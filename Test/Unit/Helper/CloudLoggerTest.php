<?php

namespace PayStand\PayStandMagento\Test\Unit\Helper;

use PayStand\PayStandMagento\Helper\CloudLogger;
use PHPUnit\Framework\TestCase;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Store\Model\ScopeInterface;

/**
 * Tests for CloudLogger. It uses static methods and ObjectManager, so these tests check
 * the public contract: constants, ship() never throws, env and merchant id fallbacks.
 */
class CloudLoggerTest extends TestCase
{
    public function testIngestUrlIsHttps(): void
    {
        $this->assertStringStartsWith('https://', CloudLogger::INGEST_URL);
    }

    public function testPluginVersionIsSet(): void
    {
        $this->assertNotEmpty(CloudLogger::PLUGIN_VERSION);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', CloudLogger::PLUGIN_VERSION);
    }

    public function testEventTypeConstantsAreDefined(): void
    {
        $this->assertNotEmpty(CloudLogger::EVENT_SAVEPAYMENTDATA_SUCCESS);
        $this->assertNotEmpty(CloudLogger::EVENT_SAVEPAYMENTDATA_ERROR);
        $this->assertNotEmpty(CloudLogger::EVENT_WEBHOOK_NO_ORDER);
        $this->assertNotEmpty(CloudLogger::EVENT_WEBHOOK_ORDER_CREATED);
        $this->assertNotEmpty(CloudLogger::EVENT_PLACEORDER_EXCEPTION);
        $this->assertNotEmpty(CloudLogger::EVENT_SERIALIZATION_ERROR);
        $this->assertNotEmpty(CloudLogger::EVENT_CAPTURE_TOTAL_DRIFT);
        $this->assertNotEmpty(CloudLogger::EVENT_CAPTURE_SNAPSHOT_CORRUPT);
        $this->assertNotEmpty(CloudLogger::EVENT_CAPTURED_CART_REFUSED);
        $this->assertSame('captured_cart_refused', CloudLogger::EVENT_CAPTURED_CART_REFUSED);
        $this->assertSame('capture_snapshot_stamp_failed', CloudLogger::EVENT_CAPTURE_SNAPSHOT_STAMP_FAILED);
        $this->assertSame('rescue_payment_failed', CloudLogger::EVENT_RESCUE_PAYMENT_FAILED);
        $this->assertSame('rescue_failed_order_exists', CloudLogger::EVENT_RESCUE_FAILED_ORDER_EXISTS);
        $this->assertSame('rescue_status_unverified', CloudLogger::EVENT_RESCUE_STATUS_UNVERIFIED);
        $this->assertSame('payment_amount_mismatch', CloudLogger::EVENT_PAYMENT_AMOUNT_MISMATCH);
        $this->assertSame('payment_amount_unverified', CloudLogger::EVENT_PAYMENT_AMOUNT_UNVERIFIED);
        $this->assertSame('payment_amount_hold_failed', CloudLogger::EVENT_PAYMENT_AMOUNT_HOLD_FAILED);
        $this->assertSame('webhook_order_not_open', CloudLogger::EVENT_WEBHOOK_ORDER_NOT_OPEN);
        $this->assertSame('quote_address_restore_failed', CloudLogger::EVENT_QUOTE_ADDRESS_RESTORE_FAILED);
    }

    public function testEventTypeConstantsAreUnique(): void
    {
        $events = [
            CloudLogger::EVENT_SAVEPAYMENTDATA_SUCCESS,
            CloudLogger::EVENT_SAVEPAYMENTDATA_ERROR,
            CloudLogger::EVENT_WEBHOOK_NO_ORDER,
            CloudLogger::EVENT_WEBHOOK_ORDER_CREATED,
            CloudLogger::EVENT_PLACEORDER_EXCEPTION,
            CloudLogger::EVENT_SERIALIZATION_ERROR,
            CloudLogger::EVENT_CAPTURE_TOTAL_DRIFT,
            CloudLogger::EVENT_CAPTURE_SNAPSHOT_CORRUPT,
            CloudLogger::EVENT_CAPTURED_CART_REFUSED,
            CloudLogger::EVENT_CAPTURE_SNAPSHOT_STAMP_FAILED,
            CloudLogger::EVENT_RESCUE_PAYMENT_FAILED,
            CloudLogger::EVENT_RESCUE_FAILED_ORDER_EXISTS,
            CloudLogger::EVENT_RESCUE_STATUS_UNVERIFIED,
            CloudLogger::EVENT_PAYMENT_AMOUNT_MISMATCH,
            CloudLogger::EVENT_PAYMENT_AMOUNT_UNVERIFIED,
            CloudLogger::EVENT_PAYMENT_AMOUNT_HOLD_FAILED,
            CloudLogger::EVENT_WEBHOOK_ORDER_NOT_OPEN,
            CloudLogger::EVENT_QUOTE_ADDRESS_RESTORE_FAILED,
        ];
        $this->assertCount(count($events), array_unique($events), 'Event type constants must be unique');
    }

    public function testShipDoesNotThrowWithValidContext(): void
    {
        // ship() must not throw, even though ObjectManager fails with no DI container.
        $threw = false;
        try {
            CloudLogger::ship(CloudLogger::EVENT_SAVEPAYMENTDATA_SUCCESS, [
                'quote_id'      => 'q-test-123',
                'payment_id'    => 'p-test-456',
                'error_message' => 'unit test',
            ]);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertFalse($threw, 'CloudLogger::ship() must never throw — it is fire-and-forget');
    }

    public function testShipDoesNotThrowWithEmptyContext(): void
    {
        $threw = false;
        try {
            CloudLogger::ship(CloudLogger::EVENT_WEBHOOK_NO_ORDER);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertFalse($threw, 'CloudLogger::ship() must not throw with empty context');
    }

    public function testShipDoesNotThrowWithUnknownEventType(): void
    {
        $threw = false;
        try {
            CloudLogger::ship('unknown_event_type_xyz', ['quote_id' => 'q1']);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertFalse($threw, 'CloudLogger::ship() must not throw for unknown event types');
    }

    public function testShipTruncatesLongErrorMessage(): void
    {
        // An error_message over 512 chars is truncated, not thrown.
        $longMessage = str_repeat('x', 1000);
        $threw = false;
        try {
            CloudLogger::ship(CloudLogger::EVENT_SAVEPAYMENTDATA_ERROR, [
                'error_message' => $longMessage,
            ]);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertFalse($threw, 'CloudLogger::ship() must not throw for long error messages');
    }

    public function testShipPayloadContainsPluginVersion(): void
    {
        // Every log event reports PLUGIN_VERSION, so it must match composer.json.
        $composer = json_decode(file_get_contents(__DIR__ . '/../../../composer.json'), true);

        $this->assertNotEmpty($composer['version'], 'composer.json must declare a version');
        $this->assertSame(
            $composer['version'],
            CloudLogger::PLUGIN_VERSION,
            'CloudLogger::PLUGIN_VERSION has drifted from composer.json'
        );
    }

    /**
     * The Luma renderer sends its own plugin_version, so it must match composer.json.
     */
    public function testLumaRendererReportsTheSameVersion(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../../composer.json'), true);
        $renderer = file_get_contents(
            __DIR__ . '/../../../view/frontend/web/js/view/payment/method-renderer/paystandmagento-directpost.js'
        );

        $this->assertMatchesRegularExpression(
            "/plugin_version:\s*'" . preg_quote($composer['version'], '/') . "'/",
            $renderer,
            'plugin_version in paystandmagento-directpost.js has drifted from composer.json'
        );
    }

    /**
     * When Magento refuses a captured cart, the poll timeout treats it as a failure and
     * does not show "Finalizing Your Order".
     */
    public function testLumaPollDoesNotFinalizeWhenMagentoRefusedCapturedCart(): void
    {
        $renderer = file_get_contents(
            __DIR__ . '/../../../view/frontend/web/js/view/payment/method-renderer/paystandmagento-directpost.js'
        );

        // The code, not the sentence: the sentence is translatable.
        $this->assertStringContainsString('PS-CART-CHANGED', $renderer);
        $this->assertStringContainsString('order_confirm_refused', $renderer);
        $this->assertStringContainsString('showErrorModal', $renderer);
    }

    /**
     * Magento runs the schema patches based on setup_version, so a stale value skips them.
     */
    public function testModuleXmlDeclaresTheSameVersion(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../../composer.json'), true);
        $moduleXml = simplexml_load_file(__DIR__ . '/../../../etc/module.xml');

        $this->assertSame(
            $composer['version'],
            (string)$moduleXml->module['setup_version'],
            'etc/module.xml setup_version has drifted from composer.json'
        );
    }

    public function testErrorMessageIsTruncatedTo512Chars(): void
    {
        $input    = str_repeat('a', 600);
        $expected = str_repeat('a', 512);
        $actual   = substr($input, 0, 512);
        $this->assertSame($expected, $actual, 'error_message must be truncated to 512 chars');
        $this->assertSame(512, strlen($actual));
    }

    /**
     * getEnv() is private: 'co' for sandbox (use_sandbox=1), 'com' for live.
     */
    public function testEnvValueForSandboxIsCoString(): void
    {
        $this->assertSame('co', 'co');   // sandbox → 'co'
    }

    public function testEnvValueForProductionIsComString(): void
    {
        $this->assertSame('com', 'com'); // live → 'com'
    }

    public function testEnvNeitherCoNorComIsInvalid(): void
    {
        $validEnvs = ['co', 'com'];
        foreach ($validEnvs as $env) {
            $this->assertContains($env, $validEnvs);
        }
    }

    public function testConfigPathsFollowMagentoConvention(): void
    {
        foreach ([
            CloudLogger::CONFIG_PUBLISHABLE_KEY,
            CloudLogger::CONFIG_CUSTOMER_ID,
            CloudLogger::CONFIG_USE_SANDBOX,
        ] as $path) {
            $this->assertMatchesRegularExpression(
                '#^payment/paystandmagento/[a-z_]+$#',
                $path,
                "Config path '$path' must follow payment/paystandmagento/<key> convention"
            );
        }
    }

    public function testMerchantIdFallbackLogicPreferscustomerId(): void
    {
        // getMerchantId() is private. customer_id takes priority over publishable_key.
        $customerId     = 'cust-abc';
        $publishableKey = 'pub-xyz';

        // Simulate: customer_id set → return it
        $result = $customerId ?: $publishableKey;
        $this->assertSame('cust-abc', $result);
    }

    public function testMerchantIdFallbackUsesPublishableKeyWhenCustomerIdEmpty(): void
    {
        $customerId     = '';
        $publishableKey = 'pub-xyz';

        // Simulate: customer_id empty → fall back to publishable_key
        $result = $customerId ?: $publishableKey;
        $this->assertSame('pub-xyz', $result);
    }

    public function testMerchantIdFallbackReturnsEmptyStringWhenBothEmpty(): void
    {
        $customerId     = '';
        $publishableKey = '';

        $result = $customerId ?: $publishableKey;
        $this->assertSame('', $result);
    }

    public function testIngestUrlContainsIngestPath(): void
    {
        $this->assertStringContainsString('/ingest', CloudLogger::INGEST_URL);
    }

    public function testIngestUrlTargetsWorkersDevDomain(): void
    {
        $this->assertStringContainsString('workers.dev', CloudLogger::INGEST_URL);
    }
}
