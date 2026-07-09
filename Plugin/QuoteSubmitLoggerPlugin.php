<?php

namespace PayStand\PayStandMagento\Plugin;

use Magento\Quote\Model\QuoteManagement;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;
use PayStand\PayStandMagento\Helper\CloudLogger;

/**
 * Wraps Magento\Quote\Model\QuoteManagement::submit() to close the diagnostic gap
 * between the browser dispatching placeOrder and Magento either creating the order
 * or throwing a caught exception (PlaceOrderFailureObserver).
 *
 * Logs synchronously to the local Magento log (survives even if the PHP process
 * dies immediately after) AND ships to CloudLogger (queryable without needing
 * merchant server log access).
 *
 * Only instruments PayStand payments — checks quote payment method before logging
 * to avoid noise on non-PayStand checkouts.
 */
class QuoteSubmitLoggerPlugin
{
    const PAYMENT_METHOD_CODE = 'paystandmagento';

    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param QuoteManagement $subject
     * @param callable $proceed
     * @param Quote $quote
     * @param array $orderData
     * @return \Magento\Sales\Api\Data\OrderInterface|\Magento\Sales\Model\Order
     * @throws \Throwable
     */
    public function aroundSubmit(QuoteManagement $subject, callable $proceed, Quote $quote, $orderData = [])
    {
        if (!$this->isPaystandQuote($quote)) {
            return $proceed($quote, $orderData);
        }

        $quoteId    = $quote->getId();
        $customerId = $quote->getCustomerId();
        $grandTotal = $quote->getGrandTotal();

        // Synchronous local log — survives even if the process dies immediately after.
        $this->logger->info(
            ">>>>> PAYSTAND-PLACEORDER-ENTERED: quote_id={$quoteId}"
            . " customer_id={$customerId}"
            . " grand_total={$grandTotal}"
        );
        $this->shipCloudLog(CloudLogger::EVENT_PLACEORDER_ENTERED, (string)$quoteId,
            "QuoteManagement::submit entered, grand_total={$grandTotal}");

        try {
            $order = $proceed($quote, $orderData);
        } catch (\Throwable $throwable) {
            $this->logger->error(
                ">>>>> PAYSTAND-PLACEORDER-SUBMIT-EXCEPTION: quote_id={$quoteId}"
                . " customer_id={$customerId}"
                . " error=\"{$throwable->getMessage()}\""
            );
            $this->logger->error(">>>>> PAYSTAND-PLACEORDER-SUBMIT-EXCEPTION trace:\n{$throwable->getTraceAsString()}");
            $this->shipCloudLog(CloudLogger::EVENT_PLACEORDER_EXCEPTION, (string)$quoteId,
                'QuoteManagement::submit threw: ' . $throwable->getMessage());

            throw $throwable;
        }

        $orderId = $order && method_exists($order, 'getIncrementId') ? $order->getIncrementId() : 'unknown';

        $this->logger->info(
            ">>>>> PAYSTAND-PLACEORDER-COMPLETED: quote_id={$quoteId}"
            . " order_id={$orderId}"
        );
        $this->shipCloudLog(CloudLogger::EVENT_PLACEORDER_COMPLETED, (string)$quoteId,
            "QuoteManagement::submit completed, order_id={$orderId}");

        return $order;
    }

    /**
     * Ship a CloudLogger event, swallowing any failure (including \Error, e.g. an
     * undefined constant) so diagnostic logging can never break the payment flow.
     */
    private function shipCloudLog(string $eventType, string $quoteId, string $message): void
    {
        try {
            CloudLogger::ship($eventType, ['quote_id' => $quoteId, 'error_message' => $message]);
        } catch (\Throwable $e) {
            // CloudLogger failure — never block the payment flow.
        }
    }

    /**
     * Only instrument quotes actually paid via the PayStand payment method,
     * to avoid noise on unrelated checkouts.
     */
    private function isPaystandQuote(Quote $quote): bool
    {
        try {
            $payment = $quote->getPayment();
            return $payment && $payment->getMethod() === self::PAYMENT_METHOD_CODE;
        } catch (\Exception $e) {
            return false;
        }
    }
}
