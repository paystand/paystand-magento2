<?php

namespace PayStand\PayStandMagento\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Psr\Log\LoggerInterface;
use PayStand\PayStandMagento\Helper\CloudLogger;

class PlaceOrderFailureObserver implements ObserverInterface
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        $exception = $observer->getEvent()->getException();
        $quote     = $observer->getEvent()->getQuote();
        $order     = $observer->getEvent()->getOrder();

        $quoteId     = $quote ? $quote->getId() : 'unknown';
        $customerId  = $quote ? $quote->getCustomerId() : 'unknown';
        $grandTotal  = $quote ? $quote->getGrandTotal() : 'unknown';
        $incrementId = $order ? $order->getIncrementId() : 'unknown';
        $message     = $exception instanceof \Throwable ? $exception->getMessage() : 'unknown error';
        $trace       = $exception instanceof \Throwable ? $exception->getTraceAsString() : '';

        $this->logger->error(
            ">>>>> PAYSTAND-PLACE-ORDER-FAILED: order_id={$incrementId}"
            . " quote_id={$quoteId}"
            . " customer_id={$customerId}"
            . " grand_total={$grandTotal}"
            . " error=\"{$message}\""
        );

        if ($trace) {
            $this->logger->error(">>>>> PAYSTAND-PLACE-ORDER-FAILED trace:\n{$trace}");
        }

        try {
            CloudLogger::ship(CloudLogger::EVENT_PLACEORDER_EXCEPTION, [
                'quote_id'      => (string)$quoteId,
                'error_message' => 'PlaceOrderFailureObserver: ' . $message
                    . ' | order=' . $incrementId
                    . ' | customer=' . $customerId
                    . ' | total=' . $grandTotal,
            ]);
        } catch (\Exception $e) {
            // CloudLogger failure — silently ignored to protect payment flow
        }
    }
}
