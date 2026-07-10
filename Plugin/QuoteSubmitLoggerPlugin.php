<?php

namespace PayStand\PayStandMagento\Plugin;

use Magento\Quote\Model\QuoteManagement;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;
use PayStand\PayStandMagento\Helper\CloudLogger;
use PayStand\PayStandMagento\Model\Directpost;

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
 *
 * SAFETY GUARANTEE: nothing in this plugin's diagnostic path (metadata extraction,
 * local logging, CloudLogger shipping) may ever prevent $proceed() from running or
 * alter/obscure its return value. Every diagnostic step is individually guarded so a
 * failure there degrades logging, never checkout.
 */
class QuoteSubmitLoggerPlugin
{
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
     * @return \Magento\Sales\Api\Data\OrderInterface|\Magento\Sales\Model\Order|null
     * @throws \Throwable
     */
    public function aroundSubmit(QuoteManagement $subject, callable $proceed, Quote $quote, $orderData = [])
    {
        if (!$this->isPaystandQuote($quote)) {
            return $proceed($quote, $orderData);
        }

        // Unique per-attempt ID so concurrent/retried submits of the same quote_id
        // (no CartMutex, direct submit() callers, etc.) can still be told apart.
        $requestId = uniqid('psq_', true);

        // Metadata extraction must never block placeOrder — any failure here just
        // degrades the log line, it never prevents $proceed() from running.
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

        $this->safeLog(
            'info',
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
            $this->safeLog(
                'error',
                ">>>>> PAYSTAND-PLACEORDER-SUBMIT-EXCEPTION: request_id={$requestId} quote_id={$quoteId}"
                . " customer_id={$customerId}"
                . " error=\"{$throwable->getMessage()}\""
            );
            $this->safeLog(
                'error',
                ">>>>> PAYSTAND-PLACEORDER-SUBMIT-EXCEPTION trace:\n{$throwable->getTraceAsString()}"
            );
            $this->shipCloudLog(
                CloudLogger::EVENT_PLACEORDER_EXCEPTION,
                (string)$quoteId,
                "[QuoteSubmitLoggerPlugin] request_id={$requestId} threw: " . $throwable->getMessage()
            );

            throw $throwable;
        }

        // submit() can legitimately return null/falsy for a quote with no visible
        // items — a documented non-exceptional path, NOT success. placeOrder()'s
        // caller treats this null as a failure; logging it as COMPLETED would be
        // exactly the false-success signal this feature exists to eliminate.
        if (!$order) {
            $this->safeLog(
                'info',
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

        $this->safeLog(
            'info',
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
     * Log to the local Magento logger, swallowing any failure so a broken logger
     * backend can never block placeOrder or discard an already-created order.
     */
    private function safeLog(string $level, string $message): void
    {
        try {
            $this->logger->{$level}($message);
        } catch (\Throwable $e) {
            // Swallow — logging must never affect the payment flow.
        }
    }

    /**
     * Ship a CloudLogger event, swallowing any failure (including \Error, e.g. an
     * undefined constant) so diagnostic logging can never break the payment flow.
     *
     * Protected (not private) so unit tests can mock it via a partial mock and
     * avoid making real HTTPS calls to the ingest Worker.
     */
    protected function shipCloudLog(string $eventType, string $quoteId, string $message): void
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
     *
     * Catches \Throwable (not just \Exception): this plugin runs unconditionally
     * for every payment method via etc/di.xml, gated only by this check. An
     * uncaught \Error here (e.g. a fatal on a malformed payment object) would
     * break checkout site-wide for every merchant, not just PayStand.
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
