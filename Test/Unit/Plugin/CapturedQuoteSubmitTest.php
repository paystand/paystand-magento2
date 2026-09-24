<?php

namespace PayStand\PayStandMagento\Test\Unit\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use Magento\Store\Model\ScopeInterface;
use PayStand\PayStandMagento\Exception\CapturedCartChangedException;
use PayStand\PayStandMagento\Helper\CaptureSnapshot;
use PayStand\PayStandMagento\Helper\QuoteShipping;
use PayStand\PayStandMagento\Plugin\CapturedQuoteSubmit;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * placeOrder must not convert a quote whose cart no longer matches the capture.
 */
class CapturedQuoteSubmitTest extends TestCase
{
    /** @var QuoteManagement */
    private $subject;

    protected function setUp(): void
    {
        $this->subject = $this->getMockBuilder(QuoteManagement::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function testUncapturedQuoteSubmits(): void
    {
        $plugin = $this->plugin();
        $quote = $this->quoteWith(null, null, null);

        $this->assertNull($plugin->beforeSubmit($this->subject, $quote));
    }

    public function testCapturedQuoteWithNoSnapshotSubmits(): void
    {
        $plugin = $this->plugin();
        $quote = $this->quoteWith('pay1', 'posted', null);

        $this->assertNull($plugin->beforeSubmit($this->subject, $quote));
    }

    public function testProcessingStatusDoesNotRefuseMismatch(): void
    {
        $plugin = $this->plugin(CapturedQuoteSubmit::MODE_REFUSE);
        $quote = $this->capturedQuote('2', null, 'processing');

        $this->assertNull($plugin->beforeSubmit($this->subject, $quote));
    }

    public function testMatchingSnapshotSubmits(): void
    {
        $plugin = $this->plugin();
        $quote = $this->capturedQuote('1');

        $this->assertNull($plugin->beforeSubmit($this->subject, $quote));
    }

    public function testMismatchRefusesSubmitWhenGuardIsRefuse(): void
    {
        $plugin = $this->plugin(CapturedQuoteSubmit::MODE_REFUSE);
        $quote = $this->capturedQuote('2');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The cart changed after payment was captured');

        $plugin->beforeSubmit($this->subject, $quote);
    }

    public function testDefaultGuardRefusesMaterialCartMismatch(): void
    {
        $plugin = $this->plugin();
        $quote = $this->capturedQuote('2');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The cart changed after payment was captured');

        $plugin->beforeSubmit($this->subject, $quote);
    }

    public function testMismatchLogOnlySubmits(): void
    {
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $logger->expects($this->once())->method('error')->with($this->callback(function ($message) {
            return is_string($message)
                && str_contains($message, 'stamped=')
                && str_contains($message, 'current=')
                && str_contains($message, 'log_only');
        }));

        $plugin = $this->plugin(CapturedQuoteSubmit::MODE_LOG_ONLY, $logger);
        $quote = $this->capturedQuote('2');

        $this->assertNull($plugin->beforeSubmit($this->subject, $quote));
    }

    public function testMismatchOffSubmitsWithoutLog(): void
    {
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $logger->expects($this->never())->method('error');

        $plugin = $this->plugin(CapturedQuoteSubmit::MODE_OFF, $logger);
        $quote = $this->capturedQuote('2');

        $this->assertNull($plugin->beforeSubmit($this->subject, $quote));
    }

    public function testEmptyGuardModeIsLogOnly(): void
    {
        $plugin = $this->plugin('');
        $quote = $this->capturedQuote('2');

        $this->assertNull($plugin->beforeSubmit($this->subject, $quote));
    }

    /**
     * A malformed hash is not a changed cart, so the order still goes through.
     */
    public function testUnreadableStampSubmitsAndLogs(): void
    {
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $logger->expects($this->once())->method('error')->with($this->callback(function ($message) {
            return is_string($message) && str_contains($message, 'no usable hash');
        }));

        $plugin = $this->plugin(CapturedQuoteSubmit::MODE_REFUSE, $logger);
        $quote = $this->capturedQuote('2', 'not-a-sha256');

        $this->assertNull($plugin->beforeSubmit($this->subject, $quote));
    }

    public function testRefuseDispatchesMagentoSubmitFailure(): void
    {
        $events = $this->getMockBuilder(ManagerInterface::class)
            ->getMockForAbstractClass();
        $events->expects($this->once())->method('dispatch')->with(
            'sales_model_service_quote_submit_failure',
            $this->callback(function ($data) {
                return isset($data['quote'], $data['exception'])
                    && $data['exception'] instanceof LocalizedException;
            })
        );

        $plugin = $this->plugin(CapturedQuoteSubmit::MODE_REFUSE, null, $events);
        $this->expectException(LocalizedException::class);
        $plugin->beforeSubmit($this->subject, $this->capturedQuote('2'));
    }

    public function testRefuseMessageIncludesPaymentId(): void
    {
        $plugin = $this->plugin(CapturedQuoteSubmit::MODE_REFUSE);
        $this->expectExceptionMessage('0an3zfttt9p7jm1v9xdqc2tq');
        $plugin->beforeSubmit($this->subject, $this->capturedQuote('2'));
    }

    public function testMismatchLogOnlyDoesNotDispatchSubmitFailure(): void
    {
        $events = $this->getMockBuilder(ManagerInterface::class)
            ->getMockForAbstractClass();
        $events->expects($this->never())->method('dispatch');

        $plugin = $this->plugin(CapturedQuoteSubmit::MODE_LOG_ONLY, null, $events);

        $this->assertNull($plugin->beforeSubmit($this->subject, $this->capturedQuote('2')));
    }

    public function testMismatchLogsStampedAndCurrentHash(): void
    {
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $logger->expects($this->once())->method('error')->with($this->callback(function ($message) {
            return is_string($message)
                && str_contains($message, 'stamped=')
                && str_contains($message, 'current=');
        }));

        $plugin = $this->plugin(CapturedQuoteSubmit::MODE_REFUSE, $logger);
        $quote = $this->capturedQuote('2');

        $this->expectException(LocalizedException::class);
        $plugin->beforeSubmit($this->subject, $quote);
    }

    /**
     * The rescue stamps from the quote it is about to place, so a match there
     * is a tautology. Refusing on a mismatch would be equally meaningless.
     */
    public function testRescueStampedSnapshotIsNeverRefused(): void
    {
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $logger->expects($this->once())->method('warning')->with($this->callback(function ($message) {
            return is_string($message) && str_contains($message, 'written by the rescue');
        }));
        $logger->expects($this->never())->method('error');

        $plugin = $this->plugin(CapturedQuoteSubmit::MODE_REFUSE, $logger);
        $quote = $this->capturedQuote('2', null, 'posted', CaptureSnapshot::SOURCE_RESCUE);

        $this->assertNull($plugin->beforeSubmit($this->subject, $quote));
    }

    /**
     * Three consumers identify the refusal. None may match on the wording,
     * which a store can translate or edit.
     */
    public function testRefusalIsTypedAndCarriesAnUntranslatedCode(): void
    {
        $plugin = $this->plugin(CapturedQuoteSubmit::MODE_REFUSE);

        try {
            $plugin->beforeSubmit($this->subject, $this->capturedQuote('2'));
            $this->fail('Expected the guard to refuse');
        } catch (CapturedCartChangedException $e) {
            $this->assertStringContainsString(CapturedCartChangedException::CODE, $e->getMessage());
        }
    }

    /**
     * The browser matches this string in rendered checkout text. Changing it
     * silently stops the shopper being told the order will not exist.
     */
    public function testRefusalCodeMatchesTheBrowserConstant(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/view/frontend/web/js/view/payment/method-renderer/paystandmagento-directpost.js'
        );
        $this->assertStringContainsString(
            "CAPTURED_CART_REFUSED_CODE = '" . CapturedCartChangedException::CODE . "'",
            $js
        );
    }

    public function testConfigDefaultsToRefuse(): void
    {
        $xml = file_get_contents(dirname(__DIR__, 3) . '/etc/config.xml');
        $this->assertStringContainsString(
            '<captured_cart_guard>refuse</captured_cart_guard>',
            (string)$xml
        );
    }

    public function testOffHelpTextNamesCartPriceRuleFreeze(): void
    {
        $xml = file_get_contents(dirname(__DIR__, 3) . '/etc/adminhtml/system.xml');
        $this->assertStringContainsString('cart-price-rule freeze from 3.7.1/3.7.2', (string)$xml);
    }

    /**
     * The guard throws before Magento runs, so the submit logger must sort ahead
     * of it. A lower sortOrder wraps a higher one, so refusals stay logged.
     */
    public function testSubmitLoggerWrapsTheGuard(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/di.xml');
        $orders = [];
        foreach ($xml->xpath('//type[@name="Magento\Quote\Model\QuoteManagement"]/plugin') as $plugin) {
            $orders[(string)$plugin['name']] = (int)$plugin['sortOrder'];
        }

        $this->assertArrayHasKey('paystand_quote_submit_logger', $orders);
        $this->assertArrayHasKey('paystand_captured_quote_submit', $orders);
        $this->assertLessThan(
            $orders['paystand_captured_quote_submit'],
            $orders['paystand_quote_submit_logger']
        );
    }

    public function testBrokenCheckFailsOpen(): void
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'getId'])
            ->getMock();
        $quote->method('getId')->willReturn(770001);
        $quote->method('getData')->willThrowException(new \Error('boom'));

        $plugin = $this->plugin();

        $this->assertNull($plugin->beforeSubmit($this->subject, $quote));
    }

    private function plugin(
        string $mode = CapturedQuoteSubmit::MODE_REFUSE,
        ?LoggerInterface $logger = null,
        $eventManager = null
    ): CapturedQuoteSubmit {
        $config = $this->getMockBuilder(ScopeConfigInterface::class)->getMockForAbstractClass();
        $config->method('getValue')->with(
            CapturedQuoteSubmit::CONFIG_PATH,
            ScopeInterface::SCOPE_STORE
        )->willReturn($mode);
        if ($eventManager === null) {
            $eventManager = $this->getMockBuilder(ManagerInterface::class)
                ->getMockForAbstractClass();
        }
        return new CapturedQuoteSubmit(
            $logger ?: $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass(),
            new CaptureSnapshot(
                $this->getMockBuilder(QuoteShipping::class)->disableOriginalConstructor()->getMock(),
                $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass()
            ),
            $config,
            $eventManager
        );
    }

    /**
     * @param string|null $paymentId
     * @param string|null $captureStatus
     * @param string|null $snapshotJson
     */
    private function quoteWith($paymentId, $captureStatus, $snapshotJson): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'getId', 'getAllVisibleItems', 'isVirtual', 'getShippingAddress'])
            ->getMock();
        $quote->method('getId')->willReturn(770001);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('getShippingAddress')->willReturn(null);
        $quote->method('getData')->willReturnCallback(function ($key) use ($paymentId, $captureStatus, $snapshotJson) {
            if ($key === 'paystand_payment_id') {
                return $paymentId;
            }
            if ($key === 'paystand_capture_status') {
                return $captureStatus;
            }
            if ($key === CaptureSnapshot::QUOTE_FIELD) {
                return $snapshotJson;
            }
            return null;
        });
        return $quote;
    }

    private function capturedQuote(
        string $currentQty,
        ?string $hashOverride = null,
        string $captureStatus = 'posted',
        string $source = CaptureSnapshot::SOURCE_CHECKOUT
    ): Quote
    {
        $item = $this->getMockBuilder(\Magento\Quote\Model\Quote\Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getSku', 'getQty'])
            ->getMock();
        $item->method('getId')->willReturn('9');
        $item->method('getSku')->willReturn('SKU');
        $item->method('getQty')->willReturn($currentQty);

        $address = $this->getMockBuilder(\Magento\Quote\Model\Quote\Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStreet', 'getCity', 'getPostcode', 'getCountryId'])
            ->getMock();
        $address->method('getStreet')->willReturn(['123 Main']);
        $address->method('getCity')->willReturn('Santa Cruz');
        $address->method('getPostcode')->willReturn('95060');
        $address->method('getCountryId')->willReturn('US');

        $hash = CaptureSnapshot::hashParts(
            [['sku' => 'SKU', 'qty' => '1']],
            [
                'city' => 'Santa Cruz',
                'postcode' => '95060',
                'country' => 'US',
            ]
        );
        $payload = json_encode(
            ['hash' => $hashOverride ?? $hash, 'source' => $source, 'grand_total' => '343.83'],
            JSON_UNESCAPED_SLASHES
        );

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getData', 'getId', 'getAllVisibleItems', 'isVirtual', 'getShippingAddress',
            ])
            ->getMock();
        $quote->method('getId')->willReturn(770001);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getAllVisibleItems')->willReturn([$item]);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getData')->willReturnCallback(function ($key) use ($payload, $captureStatus) {
            if ($key === 'paystand_payment_id') {
                return '0an3zfttt9p7jm1v9xdqc2tq';
            }
            if ($key === 'paystand_capture_status') {
                return $captureStatus;
            }
            if ($key === CaptureSnapshot::QUOTE_FIELD) {
                return $payload;
            }
            return null;
        });
        return $quote;
    }
}
