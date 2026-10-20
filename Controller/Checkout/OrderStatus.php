<?php

namespace PayStand\PayStandMagento\Controller\Checkout;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Psr\Log\LoggerInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Session\SessionManagerInterface;
use PayStand\PayStandMagento\Helper\QuoteAccess;

/**
 * Read-only endpoint the frontend polls after "Place Order" to check that placeOrder made
 * an order for the paid quote. Unknown or unauthorized quotes get the same "no order"
 * response, so ids cannot be probed.
 *
 * Response: { "success": true, "orderExists": bool, "incrementId": string|null }
 */
class OrderStatus extends Action
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var JsonFactory */
    protected $resultJsonFactory;

    /** @var QuoteAccess */
    protected $quoteAccess;

    /** @var SessionManagerInterface */
    protected $sessionManager;

    /**
     * @param Context $context
     * @param LoggerInterface $logger
     * @param JsonFactory $resultJsonFactory
     * @param QuoteAccess $quoteAccess
     * @param SessionManagerInterface $sessionManager
     */
    public function __construct(
        Context $context,
        LoggerInterface $logger,
        JsonFactory $resultJsonFactory,
        QuoteAccess $quoteAccess,
        SessionManagerInterface $sessionManager
    ) {
        $this->logger = $logger;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->quoteAccess = $quoteAccess;
        $this->sessionManager = $sessionManager;
        parent::__construct($context);
    }

    /**
     * Saves and closes the session once read. Polls run during placeOrder, and an open
     * session would write its old copy over what placeOrder saves.
     */
    private function releaseSession(): void
    {
        try {
            $this->sessionManager->writeClose();
        } catch (\Throwable $e) {
            $this->logger->error('ORDERSTATUS >>>>>> Could not close session: ' . $e->getMessage());
        }
    }

    /**
     * Controller entrypoint.
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        // The quote id comes from the query string or a JSON body.
        $quoteIdIncoming = $this->getRequest()->getParam('quote');
        if (!$quoteIdIncoming) {
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);
            $quoteIdIncoming = is_array($data) ? ($data['quote'] ?? null) : null;
        }

        // Unknown and unauthorized quotes get the same generic response.
        $quote = $quoteIdIncoming ? $this->quoteAccess->getAuthorizedQuote($quoteIdIncoming) : null;

        // Nothing below this point reads or writes the session. Released before
        // the early returns so a malformed poll cannot hold it either.
        $this->releaseSession();

        if (!$quoteIdIncoming) {
            return $result->setData([
                'success' => false,
                'error'   => 'Missing quote id'
            ]);
        }

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
