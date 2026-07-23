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
 * OrderStatus Controller
 *
 * Lightweight, read-only endpoint the frontend polls immediately after clicking
 * the native Magento "Place Order" trigger, to confirm that placeOrder actually
 * produced a sales order for the paid quote.
 *
 * Background: the Paystand widget captures the payment BEFORE the
 * Magento order exists, and the client then fires a fire-and-forget
 * $(submitTrigger).click() to place the order. If that click fails to create an
 * order (validation/session/quote state), the shopper is left charged with no
 * order and no reliable signal, and may re-pay — producing a duplicate charge.
 * The frontend uses this endpoint to detect "charged but no order" and surface
 * the existing "Payment received — do not pay again" modal.
 *
 * Responsibilities:
 * - Accept a quote id (numeric real id, or masked guest id) via `quote` query/body param.
 * - Resolve masked guest ids to the real numeric quote id.
 * - Report whether a sales order now exists for that quote.
 *
 * Response shape:
 *   { "success": true, "orderExists": bool, "incrementId": string|null }
 */
class OrderStatus extends Action
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

        // Accept the quote id from either the query string (GET, preferred) or a
        // JSON body, so the endpoint is convenient to poll from the frontend.
        $quoteIdIncoming = $this->getRequest()->getParam('quote');
        if (!$quoteIdIncoming) {
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);
            $quoteIdIncoming = is_array($data) ? ($data['quote'] ?? null) : null;
        }

        if (!$quoteIdIncoming) {
            return $result->setData([
                'success' => false,
                'error'   => 'Missing quote id'
            ]);
        }

        try {
            $realQuoteId = $this->resolveRealQuoteId($quoteIdIncoming);
        } catch (NoSuchEntityException $e) {
            // A masked id that can't be resolved means we cannot confirm an order.
            // Report "not found" rather than erroring so the poller keeps its own
            // timeout semantics.
            $this->logger->info(
                'ORDERSTATUS >>>>>> Could not resolve quote id: ' . $e->getMessage(),
                ['incoming_quote' => $quoteIdIncoming]
            );
            return $result->setData([
                'success'     => true,
                'orderExists' => false,
                'incrementId' => null
            ]);
        }

        $order = $this->findOrderByQuoteId($realQuoteId);

        if ($order && $order->getId()) {
            return $result->setData([
                'success'     => true,
                'orderExists' => true,
                'incrementId' => (string)$order->getIncrementId()
            ]);
        }

        return $result->setData([
            'success'     => true,
            'orderExists' => false,
            'incrementId' => null
        ]);
    }

    /**
     * Find the most recent sales order for a quote id, if any.
     *
     * Mirrors the "by quote id" lookup used by the webhook controller's
     * findOrder(), which is the authoritative check for whether placeOrder
     * produced an order for this cart.
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
                'ORDERSTATUS >>>>>> Error looking up order by quote id: ' . $e->getMessage(),
                ['quote_id' => $quoteId]
            );
        }

        return null;
    }
}
