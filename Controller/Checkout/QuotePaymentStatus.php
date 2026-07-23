<?php

namespace PayStand\PayStandMagento\Controller\Checkout;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Psr\Log\LoggerInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * QuotePaymentStatus Controller
 *
 * Read-only endpoint the frontend calls BEFORE opening the Paystand widget, to
 * refuse re-charging a cart that has already been paid.
 *
 * The Paystand widget captures the payment before the Magento order exists. If
 * placeOrder then fails to convert the paid quote into an order, the shopper is
 * left with an active, already-charged quote and can pay a second time. This
 * endpoint reports whether a posted charge (or a resulting order) already exists
 * for the quote, so checkout can short-circuit instead of charging again.
 *
 * Response shape:
 *   {
 *     "success": true,
 *     "alreadyPaid": bool,          // a posted charge OR an order exists
 *     "paymentId": string|null,     // recorded Paystand payment id, if any
 *     "orderExists": bool,
 *     "incrementId": string|null
 *   }
 */
class QuotePaymentStatus extends Action
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var JsonFactory */
    protected $resultJsonFactory;

    /** @var CartRepositoryInterface */
    protected $cartRepository;

    /** @var QuoteIdMaskFactory */
    protected $quoteIdMaskFactory;

    /** @var OrderCollectionFactory */
    protected $orderCollectionFactory;

    /**
     * @param Context $context
     * @param LoggerInterface $logger
     * @param JsonFactory $resultJsonFactory
     * @param CartRepositoryInterface $cartRepository
     * @param QuoteIdMaskFactory $quoteIdMaskFactory
     * @param OrderCollectionFactory $orderCollectionFactory
     */
    public function __construct(
        Context $context,
        LoggerInterface $logger,
        JsonFactory $resultJsonFactory,
        CartRepositoryInterface $cartRepository,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        OrderCollectionFactory $orderCollectionFactory
    ) {
        $this->logger = $logger;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->cartRepository = $cartRepository;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->orderCollectionFactory = $orderCollectionFactory;
        parent::__construct($context);
    }

    /**
     * Resolve a real numeric quote_id from an incoming id.
     * Numeric ids are returned as-is; masked (guest) ids are translated.
     *
     * @param string|int $incomingId
     * @return int
     * @throws NoSuchEntityException when the masked id cannot be resolved
     */
    private function resolveRealQuoteId($incomingId): int
    {
        if (is_numeric($incomingId)) {
            return (int)$incomingId;
        }

        $mask = $this->quoteIdMaskFactory->create()->load($incomingId, 'masked_id');
        $realId = (int)$mask->getQuoteId();

        if ($realId <= 0) {
            throw new NoSuchEntityException(__('Could not resolve masked quote id.'));
        }

        return $realId;
    }

    /**
     * Controller entrypoint.
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        $quoteIdIncoming = $this->getRequest()->getParam('quote');
        if (!$quoteIdIncoming) {
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);
            $quoteIdIncoming = is_array($data) ? ($data['quote'] ?? null) : null;
        }

        // Fail open: without a quote id we cannot assert a prior payment, so we
        // report "not paid" and let checkout proceed rather than blocking it.
        if (!$quoteIdIncoming) {
            return $result->setData($this->notPaid());
        }

        try {
            $realQuoteId = $this->resolveRealQuoteId($quoteIdIncoming);
        } catch (NoSuchEntityException $e) {
            $this->logger->info(
                'QUOTEPAYMENTSTATUS >>>>>> Could not resolve quote id: ' . $e->getMessage(),
                ['incoming_quote' => $quoteIdIncoming]
            );
            return $result->setData($this->notPaid());
        }

        $paymentId = null;
        try {
            $quote = $this->cartRepository->get($realQuoteId);
            $paymentId = $quote->getData('paystand_payment_id');
        } catch (\Exception $e) {
            // Quote not loadable — treat as "not paid" and let checkout proceed.
            $this->logger->info(
                'QUOTEPAYMENTSTATUS >>>>>> Could not load quote: ' . $e->getMessage(),
                ['quote_id' => $realQuoteId]
            );
        }

        $order = $this->findOrderByQuoteId($realQuoteId);
        $orderExists = (bool)($order && $order->getId());
        $incrementId = $orderExists ? (string)$order->getIncrementId() : null;

        $alreadyPaid = !empty($paymentId) || $orderExists;

        return $result->setData([
            'success'     => true,
            'alreadyPaid' => $alreadyPaid,
            'paymentId'   => !empty($paymentId) ? (string)$paymentId : null,
            'orderExists' => $orderExists,
            'incrementId' => $incrementId
        ]);
    }

    /**
     * The "no prior payment" response used on every fail-open path.
     *
     * @return array
     */
    private function notPaid(): array
    {
        return [
            'success'     => true,
            'alreadyPaid' => false,
            'paymentId'   => null,
            'orderExists' => false,
            'incrementId' => null
        ];
    }

    /**
     * Find the most recent sales order for a quote id, if any.
     *
     * @param int $quoteId
     * @return \Magento\Sales\Model\Order|null
     */
    private function findOrderByQuoteId(int $quoteId)
    {
        try {
            $collection = $this->orderCollectionFactory->create()
                ->addFieldToFilter('quote_id', $quoteId)
                ->setOrder('entity_id', 'DESC')
                ->setPageSize(1);

            if ($collection->getSize() > 0) {
                return $collection->getFirstItem();
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'QUOTEPAYMENTSTATUS >>>>>> Error looking up order by quote id: ' . $e->getMessage(),
                ['quote_id' => $quoteId]
            );
        }

        return null;
    }
}
