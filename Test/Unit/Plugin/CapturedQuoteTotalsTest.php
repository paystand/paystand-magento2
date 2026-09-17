<?php

namespace PayStand\PayStandMagento\Test\Unit\Plugin;

use PayStand\PayStandMagento\Helper\CaptureSnapshot;
use PayStand\PayStandMagento\Helper\QuoteShipping;
use PayStand\PayStandMagento\Plugin\CapturedQuoteTotals;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;

/**
 * After capture, Magento must still collectTotals so shipping rates exist after a
 * quote reload. The plugin pins the paid shipping + grand total when the cart
 * still matches the capture snapshot.
 */
class CapturedQuoteTotalsTest extends TestCase
{
    /** @var QuoteShipping&\PHPUnit\Framework\MockObject\MockObject */
    private $quoteShipping;

    /** @var CaptureSnapshot */
    private $snapshot;

    /** @var CapturedQuoteTotals */
    private $plugin;

    protected function setUp(): void
    {
        $this->quoteShipping = $this->getMockBuilder(QuoteShipping::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['snapshot', 'restore'])
            ->getMock();
        $this->snapshot = new CaptureSnapshot(
            $this->quoteShipping,
            $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass()
        );
        $this->plugin = new CapturedQuoteTotals(
            $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass(),
            $this->quoteShipping,
            $this->snapshot
        );
    }

    public function testConfirmedCaptureWithSnapshotStillCollects(): void
    {
        $quote = $this->quoteWith('nlvsnvr0ska9i7ugvoab9917', 'posted', '{"ok":1}');
        $quote->expects($this->never())->method('setTotalsCollectedFlag');

        $this->plugin->beforeCollectTotals($quote);
    }

    public function testPaidStatusWithSnapshotStillCollects(): void
    {
        $quote = $this->quoteWith('nlvsnvr0ska9i7ugvoab9917', 'paid', '{"ok":1}');
        $quote->expects($this->never())->method('setTotalsCollectedFlag');

        $this->plugin->beforeCollectTotals($quote);
    }

    /**
     * Quotes captured before paystand_capture_snapshot existed have no JSON.
     * Fall back to the 3.7.2 freeze so cart price rules cannot raise the total.
     *
     * @dataProvider capturedStatusProvider
     */
    public function testCapturedQuoteWithoutSnapshotFreezesCollect(string $status): void
    {
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $logger->expects($this->once())->method('warning')->with($this->callback(function ($message) {
            return is_string($message) && str_contains($message, 'no snapshot');
        }));
        $plugin = new CapturedQuoteTotals($logger, $this->quoteShipping, $this->snapshot);

        $quote = $this->quoteWith('nlvsnvr0ska9i7ugvoab9917', $status);
        $quote->expects($this->once())->method('setTotalsCollectedFlag')->with(true);

        $plugin->beforeCollectTotals($quote);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function capturedStatusProvider(): array
    {
        return [
            'posted' => ['posted'],
            'paid' => ['paid'],
        ];
    }

    public function testPaymentIdWithoutConfirmedCaptureStillCollects(): void
    {
        $quote = $this->quoteWith('nlvsnvr0ska9i7ugvoab9917', null);
        $quote->expects($this->never())->method('setTotalsCollectedFlag');

        $this->plugin->beforeCollectTotals($quote);
    }

    public function testUncapturedQuoteCollectsNormally(): void
    {
        $quote = $this->quoteWith(null, null);
        $quote->expects($this->never())->method('setTotalsCollectedFlag');

        $this->plugin->beforeCollectTotals($quote);
        $this->assertSame($quote, $this->plugin->afterCollectTotals($quote, $quote));
    }

    public function testEmptyStringsDoNotCountAsCaptured(): void
    {
        $quote = $this->quoteWith('', '');
        $quote->expects($this->never())->method('setTotalsCollectedFlag');

        $this->plugin->beforeCollectTotals($quote);
    }

    public function testAFailingCheckIsContained(): void
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $quote->method('getData')->willThrowException(new \Error('boom'));

        $this->plugin->beforeCollectTotals($quote);
        $this->assertSame($quote, $this->plugin->afterCollectTotals($quote, $quote));
    }

    /**
     * The Sep 15 failure: Magento placeOrder reloaded the quote, collectTotals
     * ran, shipping method and rate were gone. Restore them from the snapshot
     * when the cart still matches.
     */
    public function testAfterCollectRestoresShippingWhenCartMatches(): void
    {
        $shippingSnap = [
            'method' => 'fedex_FEDEX_GROUND',
            'amount' => 55.0,
            'baseAmount' => 55.0,
            'description' => 'FedEx Ground',
            'rate' => ['code' => 'fedex_FEDEX_GROUND', 'price' => 55.0],
        ];
        $quote = $this->matchingCapturedQuote($shippingSnap, 343.83);
        $this->quoteShipping->expects($this->once())
            ->method('restore')
            ->with($quote, $shippingSnap, 'captured-collect')
            ->willReturn(true);
        $quote->expects($this->atLeastOnce())->method('setGrandTotal')->with(343.83);

        $this->assertSame($quote, $this->plugin->afterCollectTotals($quote, $quote));
    }

    public function testAfterCollectDoesNotRestoreShippingWhenCartChanged(): void
    {
        $shippingSnap = [
            'method' => 'fedex_FEDEX_GROUND',
            'amount' => 55.0,
            'baseAmount' => 55.0,
            'description' => 'FedEx Ground',
            'rate' => ['code' => 'fedex_FEDEX_GROUND'],
        ];
        $quote = $this->matchingCapturedQuote($shippingSnap, 343.83, '2');
        $this->quoteShipping->expects($this->never())->method('restore');
        $quote->expects($this->never())->method('setGrandTotal');

        $this->assertSame($quote, $this->plugin->afterCollectTotals($quote, $quote));
    }

    /**
     * @param string|null $paymentId
     * @param string|null $captureStatus
     * @param string|null $snapshotJson
     */
    private function quoteWith($paymentId, $captureStatus, $snapshotJson = null): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'getId', 'getAllVisibleItems', 'isVirtual', 'getShippingAddress', 'getBillingAddress'])
            ->addMethods(['setTotalsCollectedFlag', 'setGrandTotal', 'setBaseGrandTotal', 'getGrandTotal', 'getBaseGrandTotal'])
            ->getMock();
        $quote->method('getId')->willReturn(4490737);
        $quote->method('getData')->willReturnCallback(
            function ($key) use ($paymentId, $captureStatus, $snapshotJson) {
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
            }
        );
        return $quote;
    }

    /**
     * @param array<string, mixed> $shippingSnap
     */
    private function matchingCapturedQuote(array $shippingSnap, float $grandTotal, string $currentQty = '1'): Quote
    {
        $item = $this->getMockBuilder(\Magento\Quote\Model\Quote\Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getSku', 'getQty'])
            ->getMock();
        $item->method('getId')->willReturn('9');
        $item->method('getSku')->willReturn('SKU');
        $item->method('getQty')->willReturn($currentQty);

        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getStreet', 'getCity', 'getPostcode', 'getCountryId',
                'setShippingAmount', 'setBaseShippingAmount',
            ])
            ->addMethods([
                'getDiscountAmount', 'setGrandTotal', 'setBaseGrandTotal', 'setDiscountAmount',
                'setShippingDescription',
            ])
            ->getMock();
        $address->method('getStreet')->willReturn(['123 Main']);
        $address->method('getCity')->willReturn('Santa Cruz');
        $address->method('getPostcode')->willReturn('95060');
        $address->method('getCountryId')->willReturn('US');
        $address->method('getDiscountAmount')->willReturn(-14.31);
        $address->method('setGrandTotal')->willReturnSelf();
        $address->method('setBaseGrandTotal')->willReturnSelf();
        $address->method('setShippingAmount')->willReturnSelf();
        $address->method('setBaseShippingAmount')->willReturnSelf();
        $address->method('setShippingDescription')->willReturnSelf();

        $stampedHash = CaptureSnapshot::hashParts(
            [['sku' => 'SKU', 'qty' => '1']],
            [
                'city' => 'Santa Cruz',
                'postcode' => '95060',
                'country' => 'US',
            ]
        );
        $payload = json_encode([
            'hash' => $stampedHash,
            'grand_total' => (string)$grandTotal,
            'base_grand_total' => (string)$grandTotal,
            'discount_amount' => '-14.31',
            'shipping' => $shippingSnap,
        ], JSON_UNESCAPED_SLASHES);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getData', 'getId', 'getAllVisibleItems', 'isVirtual',
                'getShippingAddress', 'getBillingAddress',
            ])
            ->addMethods(['setTotalsCollectedFlag', 'setGrandTotal', 'setBaseGrandTotal', 'getGrandTotal', 'getBaseGrandTotal'])
            ->getMock();
        $quote->method('getId')->willReturn(4490737);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getAllVisibleItems')->willReturn([$item]);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getBillingAddress')->willReturn($address);
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
