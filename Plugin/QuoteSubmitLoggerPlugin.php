<?php

namespace PayStand\PayStandMagento\Plugin;

use Magento\Quote\Model\QuoteManagement;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;
use PayStand\PayStandMagento\Helper\CloudLogger;
use PayStand\PayStandMagento\Model\Directpost;

/**
 * Wraps QuoteManagement::submit() for Paystand quotes and logs the attempt and its outcome,
 * to the local log and to CloudLogger. A logging failure never stops $proceed() or changes
 * its return value.
 */
class QuoteSubmitLoggerPlugin
{
    /** @var LoggerInterface */
    protected $logger;

    /**
     * Callable(string $eventType, array $context): void, by default [CloudLogger::class, 'ship'].
     * Tests inject a throwing shipper to exercise shipCloudLog()'s try/catch.
     *
     * @var callable
     */
    protected $cloudShipper;

    public function __construct(LoggerInterface $logger, ?callable $cloudShipper = null)
    {
        $this->logger = $logger;
        $this->cloudShipper = $cloudShipper ?? [CloudLogger::class, 'ship'];
    }

    /**
     * @param array $orderData
     * @return \Magento\Sales\Api\Data\OrderInterface|\Magento\Sales\Model\Order|null
     * @throws \Throwable
     */
    public function aroundSubmit(QuoteManagement $subject, callable $proceed, Quote $quote, $orderData = [])
    {
        if (!$this->isPaystandQuote($quote)) {
            return $proceed($quote, $orderData);
        }

        // A per-attempt id tells apart concurrent or retried submits of one quote.
        $requestId = uniqid('psq_', true);

        // A failure reading metadata only degrades the log line.
        $quoteId = 'unknown';
        $customerId = 'unknown';
        $grandTotal = 'unknown';
        try {
            $quoteId = $quote->getId();
            $customerId = $quote->getCustomerId();
            $grandTotal = $quote->getGrandTotal();
        } catch (\Throwable $e) {
            // Swallow — diagnostic metadata is best-effort only.
        }

        $this->safeInfo(
            ">>>>> PAYSTAND-PLACEORDER-ENTERED: request_id={$requestId} quote_id={$quoteId}"
            . " customer_id={$customerId}"
            . " grand_total={$grandTotal}"
        );
        $this->shipCloudLog(
            CloudLogger::EVENT_PLACEORDER_ENTERED,
            (string)$quoteId,
            "[QuoteSubmitLoggerPlugin] request_id={$requestId} entered, grand_total={$grandTotal}"
        );

        try {
            $order = $proceed($quote, $orderData);
        } catch (\Throwable $throwable) {
            $this->safeError(
                ">>>>> PAYSTAND-PLACEORDER-SUBMIT-EXCEPTION: request_id={$requestId} quote_id={$quoteId}"
                . " customer_id={$customerId}"
                . " error=\"{$throwable->getMessage()}\""
            );
            $this->safeError(
                ">>>>> PAYSTAND-PLACEORDER-SUBMIT-EXCEPTION trace:\n{$throwable->getTraceAsString()}"
            );
            $this->shipCloudLog(
                CloudLogger::EVENT_PLACEORDER_EXCEPTION,
                (string)$quoteId,
                "[QuoteSubmitLoggerPlugin] request_id={$requestId} threw: " . $throwable->getMessage()
            );

            throw $throwable;
        }

        // submit() returns null for a quote with no visible items. The caller treats that as a
        // failure, so it is not logged as COMPLETED.
        if (!$order) {
            $this->safeInfo(
                ">>>>> PAYSTAND-PLACEORDER-NULL-RESULT: request_id={$requestId} quote_id={$quoteId}"
                . " (submit() returned null/falsy — not an order)"
            );
            $this->shipCloudLog(
                CloudLogger::EVENT_PLACEORDER_NULL_RESULT,
                (string)$quoteId,
                "[QuoteSubmitLoggerPlugin] request_id={$requestId} submit() returned null/falsy"
            );

            return $order;
        }

        $orderId = method_exists($order, 'getIncrementId') ? $order->getIncrementId() : 'unknown';

        $this->safeInfo(
            ">>>>> PAYSTAND-PLACEORDER-COMPLETED: request_id={$requestId} quote_id={$quoteId}"
            . " order_id={$orderId}"
        );
        $this->shipCloudLog(
            CloudLogger::EVENT_PLACEORDER_COMPLETED,
            (string)$quoteId,
            "[QuoteSubmitLoggerPlugin] request_id={$requestId} completed, order_id={$orderId}"
        );

        return $order;
    }

    /**
     * Logs at info level. A logger failure is swallowed so it cannot affect placeOrder.
     */
    private function safeInfo(string $message): void
    {
        try {
            $this->logger->info($message);
        } catch (\Throwable $e) {
            // Swallow — logging must never affect the payment flow.
        }
    }

    /**
     * Logs at error level and swallows any failure, like safeInfo().
     */
    private function safeError(string $message): void
    {
        try {
            $this->logger->error($message);
        } catch (\Throwable $e) {
            // Swallow — logging must never affect the payment flow.
        }
    }

    /**
     * Ships a CloudLogger event and swallows any failure, \Error included. Protected so
     * unit tests can stub it.
     */
    protected function shipCloudLog(string $eventType, string $quoteId, string $message): void
    {
        try {
            ($this->cloudShipper)($eventType, ['quote_id' => $quoteId, 'error_message' => $message]);
        } catch (\Throwable $e) {
            // CloudLogger failure — never block the payment flow.
        }
    }

    /**
     * True for quotes paid with Paystand. It catches \Throwable, since the plugin runs for
     * every payment method and an \Error here would break all checkouts.
     */
    private function isPaystandQuote(Quote $quote): bool
    {
        try {
            $payment = $quote->getPayment();
            return $payment && $payment->getMethod() === Directpost::METHOD_CODE;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
