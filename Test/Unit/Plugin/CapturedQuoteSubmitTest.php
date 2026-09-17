<?php

namespace PayStand\PayStandMagento\Test\Unit\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
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
        $called = false;

        $result = $plugin->aroundSubmit($this->subject, function ($q, $data) use (&$called, $quote) {
            $called = true;
            $this->assertSame($quote, $q);
            return 'order';
        }, $quote);

        $this->assertTrue($called);
        $this->assertSame('order', $result);
    }

    public function testCapturedQuoteWithNoSnapshotSubmits(): void
    {
        $plugin = $this->plugin();
        $quote = $this->quoteWith('pay1', 'posted', null);
        $called = false;

        $plugin->aroundSubmit($this->subject, function () use (&$called) {
            $called = true;
            return 'order';
        }, $quote);

        $this->assertTrue($called);
    }

    public function testMatchingSnapshotSubmits(): void
    {
        $plugin = $this->plugin();
        $quote = $this->capturedQuote('1');
        $called = false;

        $plugin->aroundSubmit($this->subject, function () use (&$called) {
            $called = true;
            return 'order';
        }, $quote);

        $this->assertTrue($called);
    }

    public function testMismatchRefusesSubmitWhenGuardIsRefuse(): void
    {
        $plugin = $this->plugin(CapturedQuoteSubmit::MODE_REFUSE);
        $quote = $this->capturedQuote('2');
        $called = false;

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The cart changed after payment was captured');

        try {
            $plugin->aroundSubmit($this->subject, function () use (&$called) {
                $called = true;
                return 'order';
            }, $quote);
        } finally {
            $this->assertFalse($called);
        }
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
        $called = false;

        $plugin->aroundSubmit($this->subject, function () use (&$called) {
            $called = true;
            return 'order';
        }, $quote);

        $this->assertTrue($called);
    }

    public function testMismatchOffSubmitsWithoutLog(): void
    {
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $logger->expects($this->never())->method('error');

        $plugin = $this->plugin(CapturedQuoteSubmit::MODE_OFF, $logger);
        $quote = $this->capturedQuote('2');
        $called = false;

        $plugin->aroundSubmit($this->subject, function () use (&$called) {
            $called = true;
            return 'order';
        }, $quote);

        $this->assertTrue($called);
    }

    public function testEmptyGuardModeIsLogOnly(): void
    {
        $plugin = $this->plugin('');
        $quote = $this->capturedQuote('2');
        $called = false;

        $plugin->aroundSubmit($this->subject, function () use (&$called) {
            $called = true;
            return 'order';
        }, $quote);

        $this->assertTrue($called);
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
        $plugin->aroundSubmit($this->subject, function () {
            return 'order';
        }, $quote);
    }

    public function testConfigDefaultsToLogOnly(): void
    {
        $xml = file_get_contents(dirname(__DIR__, 3) . '/etc/config.xml');
        $this->assertStringContainsString(
            '<captured_cart_guard>log_only</captured_cart_guard>',
            (string)$xml
        );
    }

    public function testBrokenCheckFailsOpen(): void
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'getId'])
            ->getMock();
        $quote->method('getId')->willReturn(4490737);
        $quote->method('getData')->willThrowException(new \Error('boom'));

        $plugin = $this->plugin();
        $called = false;

        $plugin->aroundSubmit($this->subject, function () use (&$called) {
            $called = true;
            return 'order';
        }, $quote);

        $this->assertTrue($called);
    }

    private function plugin(
        string $mode = CapturedQuoteSubmit::MODE_LOG_ONLY,
        ?LoggerInterface $logger = null
    ): CapturedQuoteSubmit {
        $config = $this->getMockBuilder(ScopeConfigInterface::class)->getMockForAbstractClass();
        $config->method('getValue')->willReturn($mode);

        return new CapturedQuoteSubmit(
            $logger ?: $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass(),
            new CaptureSnapshot(
                $this->getMockBuilder(QuoteShipping::class)->disableOriginalConstructor()->getMock(),
                $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass()
            ),
            $config
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
        $quote->method('getId')->willReturn(4490737);
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

    private function capturedQuote(string $currentQty): Quote
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
        $payload = json_encode(['hash' => $hash, 'grand_total' => '343.83'], JSON_UNESCAPED_SLASHES);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getData', 'getId', 'getAllVisibleItems', 'isVirtual', 'getShippingAddress',
            ])
            ->getMock();
        $quote->method('getId')->willReturn(4490737);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getAllVisibleItems')->willReturn([$item]);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getData')->willReturnCallback(function ($key) use ($payload) {
            if ($key === 'paystand_payment_id') {
                return '0an3zfttt9p7jm1v9xdqc2tq';
            }
            if ($key === 'paystand_capture_status') {
                return 'posted';
            }
            if ($key === CaptureSnapshot::QUOTE_FIELD) {
                return $payload;
            }
            return null;
        });
        return $quote;
    }
}
