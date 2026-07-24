<?php

namespace PayStand\PayStandMagento\Controller\Checkout;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Psr\Log\LoggerInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use PayStand\PayStandMagento\Helper\QuoteAccess;

/**
 * QuotePaymentStatus Controller
 *
 * Read-only endpoint the frontend calls BEFORE opening the Paystand widget, to
 * refuse initiating a second payment for a cart that has already been paid.
 *
 * The Paystand widget captures the payment before the Magento order exists. If
 * placeOrder then fails to convert the paid quote into an order, the shopper is
 * left with an active, already-paid quote and may pay a second time. This
 * endpoint reports whether a posted payment (or a resulting order) already
 * exists for the quote, so checkout can short-circuit instead of collecting a
 * second payment.
 *
 * Authorization: the quote id is resolved and authorized against the current
 * session via QuoteAccess. Unauthorized or unknown quotes receive the same
 * generic "not paid" response, so sequential ids cannot be probed for other
 * customers' payment ids or order numbers. (For the legitimate shopper this is
 * also the fail-open behavior: the guard never blocks a first payment.)
 *
 * Response shape:
 *   {
 *     "success": true,
 *     "alreadyPaid": bool,          // a posted payment OR an order exists
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

    /** @var QuoteAccess */
    protected $quoteAccess;

    /**
     * @param Context $context
     * @param LoggerInterface $logger
     * @param JsonFactory $resultJsonFactory
     * @param QuoteAccess $quoteAccess
     */
    public function __construct(
        Context $context,
        LoggerInterface $logger,
        JsonFactory $resultJsonFactory,
        QuoteAccess $quoteAccess
    ) {
        $this->logger = $logger;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->quoteAccess = $quoteAccess;
        parent::__construct($context);
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

        // Resolve + authorize against the current session. Missing, unknown, and
        // unauthorized quotes all get the identical generic response: fail closed
        // for information disclosure, fail open for the legitimate first payment.
        $quote = $quoteIdIncoming ? $this->quoteAccess->getAuthorizedQuote($quoteIdIncoming) : null;
        if (!$quote) {
            return $result->setData($this->notPaid());
        }

        $paymentId = $quote->getData('paystand_payment_id');

        $order = $this->quoteAccess->findOrderByQuoteId((int)$quote->getId());
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
}
