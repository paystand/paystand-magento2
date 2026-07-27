<?php

namespace PayStand\PayStandMagento\Controller\Checkout;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Psr\Log\LoggerInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use PayStand\PayStandMagento\Helper\QuoteAccess;

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
 * order (validation/session/quote state), the shopper is left having paid with
 * no order and no reliable signal, and may pay again. The frontend uses this
 * endpoint to detect "paid but no order" and inform the shopper.
 *
 * Authorization: the quote id is resolved and authorized against the current
 * session via QuoteAccess. Unauthorized or unknown quotes receive the same
 * generic "no order" response, so sequential ids cannot be probed for other
 * customers' order numbers.
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

        // Resolve + authorize against the current session. Unknown and
        // unauthorized quotes get the identical generic response (fail closed,
        // no information leak) — which also preserves the poller's own timeout
        // semantics on the legitimate path.
        $quote = $this->quoteAccess->getAuthorizedQuote($quoteIdIncoming);
        if (!$quote) {
            return $result->setData([
                'success'     => true,
                'orderExists' => false,
                'incrementId' => null
            ]);
        }

        $order = $this->quoteAccess->findOrderByQuoteId((int)$quote->getId());

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
}
