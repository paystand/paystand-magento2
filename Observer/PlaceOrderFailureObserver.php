<?php

namespace PayStand\PayStandMagento\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Psr\Log\LoggerInterface;

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

        $quoteId    = $quote ? $quote->getId() : 'unknown';
        $customerId = $quote ? $quote->getCustomerId() : 'unknown';
        $grandTotal = $quote ? $quote->getGrandTotal() : 'unknown';
        $message    = $exception instanceof \Throwable ? $exception->getMessage() : 'unknown error';
        $trace      = $exception instanceof \Throwable ? $exception->getTraceAsString() : '';

        $this->logger->error(
            ">>>>> PAYSTAND-PLACE-ORDER-FAILED: quote_id={$quoteId}"
            . " customer_id={$customerId}"
            . " grand_total={$grandTotal}"
            . " error=\"{$message}\""
        );

        $this->logger->error(">>>>> PAYSTAND-PLACE-ORDER-FAILED trace:\n{$trace}");
    }
}
