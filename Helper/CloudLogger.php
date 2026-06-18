<?php
namespace PayStand\PayStandMagento\Helper;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Ships structured log events to the Cloudflare Analytics Engine ingest Worker.
 * Fire-and-forget — uses a non-blocking cURL call with a very short timeout.
 * If the Worker is unreachable, the call fails silently and Magento continues normally.
 *
 * The Worker validates each request by calling GET /v3/plugins/paystand/checkout/resources/public
 * on the Paystand API using the publishable key included in the payload.
 */
class CloudLogger
{
    const INGEST_URL     = 'https://magento-plugin-logs.paystand-core-services.workers.dev/ingest';
    const PLUGIN_VERSION = '3.6.6';

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
        $payload = json_encode([
            'customer_id'     => $context['customer_id'] ?? self::getMerchantId(),
            'publishable_key' => $context['publishable_key'] ?? self::getPublishableKey(),
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
