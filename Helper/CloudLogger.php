<?php
namespace PayStand\PayStandMagento\Helper;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Sends log events to the Cloudflare ingest Worker with a short, non-blocking cURL call.
 * A failed call is ignored. The Worker checks the publishable key with the Paystand API.
 */
class CloudLogger
{
    const INGEST_URL     = 'https://magento-plugin-logs.paystand-core-services.workers.dev/ingest';
    const PLUGIN_VERSION = '3.7.6';

    // Config paths
    const CONFIG_PUBLISHABLE_KEY = 'payment/paystandmagento/publishable_key';
    const CONFIG_CUSTOMER_ID     = 'payment/paystandmagento/customer_id';
    const CONFIG_USE_SANDBOX     = 'payment/paystandmagento/use_sandbox';

    // Event type constants
    const EVENT_SAVEPAYMENTDATA_SUCCESS = 'savepaymentdata_success';
    const EVENT_SAVEPAYMENTDATA_ERROR   = 'savepaymentdata_error';
    const EVENT_WEBHOOK_START           = 'webhook_start';
    const EVENT_WEBHOOK_NO_ORDER        = 'webhook_no_order';
    const EVENT_WEBHOOK_ORDER_CREATED   = 'webhook_order_created';
    const EVENT_PLACEORDER_EXCEPTION    = 'placeorder_exception';
    const EVENT_SERIALIZATION_ERROR     = 'serialization_error';
    const EVENT_PLACEORDER_ENTERED      = 'placeorder_entered';
    const EVENT_PLACEORDER_COMPLETED    = 'placeorder_completed';
    const EVENT_PLACEORDER_NULL_RESULT  = 'placeorder_null_result';
    // A second, different payment id was reported for a quote that already has one.
    const EVENT_DUPLICATE_PAYMENT_RECORDED = 'duplicate_payment_recorded';
    // The webhook could not get the place-order lock and fell back to search only.
    const EVENT_PLACEORDER_LOCK_FALLBACK = 'placeorder_lock_fallback';
    // Shipping state on a quote, sent only when it changes, to show when a rate is lost.
    const EVENT_QUOTE_SHIPPING_STATE = 'quote_shipping_state';
    // The rescue gave up on a paid quote that Magento kept rejecting past the retry
    // window. Support works from this event.
    const EVENT_RESCUE_ABANDONED = 'rescue_abandoned';
    // The order confirmation page refused to render and redirected to the cart.
    // Names the checkout-session values it found missing.
    const EVENT_SUCCESS_PAGE_BOUNCED = 'success_page_bounced';
    // The confirmation page was about to redirect, but the order for the
    // session's own quote was found and the missing session values restored.
    const EVENT_SUCCESS_PAGE_REPAIRED = 'success_page_repaired';
    // Magento live grand_total after collect differs from the paid snapshot.
    const EVENT_CAPTURE_TOTAL_DRIFT = 'capture_total_drift';
    // paystand_capture_snapshot on the quote is not valid JSON.
    const EVENT_CAPTURE_SNAPSHOT_CORRUPT = 'capture_snapshot_corrupt';
    // Magento refused placeOrder because the cart no longer matches the capture.
    const EVENT_CAPTURED_CART_REFUSED = 'captured_cart_refused';
    // First stamp threw after capture markers were already on the quote.
    const EVENT_CAPTURE_SNAPSHOT_STAMP_FAILED = 'capture_snapshot_stamp_failed';
    // Totals were pinned from a snapshot the webhook rescue wrote, which is the
    // cart the rescue found rather than a cart proven to be the one paid for.
    const EVENT_CAPTURE_PIN_UNVERIFIED = 'capture_pin_unverified';
    // The webhook rescue placed no order: Paystand reports the payment failed or
    // canceled since the delivery was sent. Paystand can mark a payment failed
    // even when money has moved, so this event is the queue to alert on.
    // error_message starts with release=cleared, not_matched, skipped_captured,
    // or error.
    const EVENT_RESCUE_PAYMENT_FAILED = 'rescue_payment_failed';
    // The payment failed after the browser had already placed the order. The
    // quote is not cleared. Alert on this: an order exists for a failed payment.
    const EVENT_RESCUE_FAILED_ORDER_EXISTS = 'rescue_failed_order_exists';
    // The webhook rescue could not read the payment's current status from
    // Paystand and placed the order on the delivery's own status.
    const EVENT_RESCUE_STATUS_UNVERIFIED = 'rescue_status_unverified';

    // Paystand amount does not match the order total. Hold, do not invoice.
    const EVENT_PAYMENT_AMOUNT_MISMATCH = 'payment_amount_mismatch';

    // Paystand amount or currency could not be read. Hold, do not invoice.
    const EVENT_PAYMENT_AMOUNT_UNVERIFIED = 'payment_amount_unverified';

    /**
     * Resolve the merchant's customer ID from store config.
     * Falls back to publishable key if customer ID is not set.
     */
    private static function getMerchantId(): string
    {
        try {
            $config     = ObjectManager::getInstance()->get(ScopeConfigInterface::class);
            $customerId = $config->getValue(self::CONFIG_CUSTOMER_ID, ScopeInterface::SCOPE_STORE);
            if ($customerId) {
                return (string)$customerId;
            }
            return (string)($config->getValue(self::CONFIG_PUBLISHABLE_KEY, ScopeInterface::SCOPE_STORE) ?? '');
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Resolve the merchant's publishable key from store config.
     */
    private static function getPublishableKey(): string
    {
        try {
            $config = ObjectManager::getInstance()->get(ScopeConfigInterface::class);
            return (string)($config->getValue(self::CONFIG_PUBLISHABLE_KEY, ScopeInterface::SCOPE_STORE) ?? '');
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Resolve the environment string ("co" for sandbox, "com" for live).
     */
    private static function getEnv(): string
    {
        try {
            $config = ObjectManager::getInstance()->get(ScopeConfigInterface::class);
            return $config->getValue(self::CONFIG_USE_SANDBOX, ScopeInterface::SCOPE_STORE) ? 'co' : 'com';
        } catch (\Exception $e) {
            return 'com';
        }
    }

    /**
     * Ship a log event to the Cloudflare Worker.
     * Includes the publishable key for Worker-side validation against the Paystand API.
     *
     * @param string $eventType  One of the EVENT_* constants
     * @param array  $context    Additional fields: quote_id, payment_id, error_message, etc.
     */
    public static function ship(string $eventType, array $context = []): void
    {
        // The Worker answers a keyless event with 401, so sending one buys
        // nothing and still costs the caller a blocking request.
        $publishableKey = (string)($context['publishable_key'] ?? self::getPublishableKey());
        if ($publishableKey === '') {
            return;
        }

        $payload = json_encode([
            'customer_id'     => $context['customer_id'] ?? self::getMerchantId(),
            'publishable_key' => $publishableKey,
            'event_type'      => $eventType,
            'quote_id'        => $context['quote_id'] ?? '',
            'payment_id'      => $context['payment_id'] ?? '',
            'error_message'   => isset($context['error_message'])
                ? substr($context['error_message'], 0, 512)
                : '',
            'magento_version' => $context['magento_version'] ?? '',
            'plugin_version'  => self::PLUGIN_VERSION,
            'env'             => self::getEnv(),
        ]);

        // Non-blocking fire-and-forget via cURL
        $ch = curl_init(self::INGEST_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => $payload,
            CURLOPT_HTTPHEADER        => [
                'Content-Type: application/json',
                'User-Agent: PaystandMagento/' . self::PLUGIN_VERSION,
            ],
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_TIMEOUT_MS        => 800,
            CURLOPT_CONNECTTIMEOUT_MS => 500,
            CURLOPT_SSL_VERIFYPEER    => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
