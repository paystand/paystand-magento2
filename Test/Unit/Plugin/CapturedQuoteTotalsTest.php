<?php

namespace PayStand\PayStandMagento\Test\Unit\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PayStand\PayStandMagento\Helper\CaptureSnapshot;
use PayStand\PayStandMagento\Helper\QuoteShipping;
use PayStand\PayStandMagento\Plugin\CapturedQuoteSubmit;
use PayStand\PayStandMagento\Plugin\CapturedQuoteTotals;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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
        $this->plugin = $this->makePlugin();
    }

    private function makePlugin(
        ?LoggerInterface $logger = null,
        string $mode = CapturedQuoteSubmit::MODE_LOG_ONLY,
        ?CaptureSnapshot $snapshot = null
    ): CapturedQuoteTotals {
        $config = $this->getMockBuilder(ScopeConfigInterface::class)->getMockForAbstractClass();
        $config->method('getValue')->willReturn($mode);

        return new CapturedQuoteTotals(
            $logger ?: $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass(),
            $this->quoteShipping,
            $snapshot ?? $this->snapshot,
            $config
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
        $plugin = $this->makePlugin($logger);

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

    public function testProcessingStatusDoesNotFreezeCollect(): void
    {
        $quote = $this->quoteWith('nlvsnvr0ska9i7ugvoab9917', 'processing');
        $quote->expects($this->never())->method('setTotalsCollectedFlag');

        $this->plugin->beforeCollectTotals($quote);
    }

    public function testStampingWindowDoesNotFreezeCapturedQuoteWithoutSnapshot(): void
    {
        $snapshot = $this->createMock(CaptureSnapshot::class);
        $snapshot->method('isCaptured')->willReturn(true);
        $snapshot->method('read')->willReturn(null);
        $snapshot->method('isStamping')->willReturn(true);
        $snapshot->method('resolveGuardMode')->willReturn(CapturedQuoteSubmit::MODE_LOG_ONLY);

        $plugin = $this->makePlugin(null, CapturedQuoteSubmit::MODE_LOG_ONLY, $snapshot);
        $quote = $this->quoteWith('nlvsnvr0ska9i7ugvoab9917', 'posted');
        $quote->expects($this->never())->method('setTotalsCollectedFlag');

        $plugin->beforeCollectTotals($quote);
    }

    public function testOffDoesNotFreezeCapturedQuoteWithoutSnapshot(): void
    {
        $plugin = $this->makePlugin(null, CapturedQuoteSubmit::MODE_OFF);
        $quote = $this->quoteWith('nlvsnvr0ska9i7ugvoab9917', 'posted');
        $quote->expects($this->never())->method('setTotalsCollectedFlag');

        $plugin->beforeCollectTotals($quote);
    }

    public function testOffDoesNotPinMatchingSnapshot(): void
    {
        $shippingSnap = [
            'method' => 'fedex_FEDEX_GROUND',
            'amount' => 55.0,
            'baseAmount' => 55.0,
            'description' => 'FedEx Ground',
            'rate' => ['code' => 'fedex_FEDEX_GROUND', 'price' => 55.0],
        ];
        $quote = $this->matchingCapturedQuote($shippingSnap, 343.83);
        $plugin = $this->makePlugin(null, CapturedQuoteSubmit::MODE_OFF);

        $this->quoteShipping->expects($this->never())->method('restore');
        $quote->expects($this->never())->method('setGrandTotal');

        $this->assertSame($quote, $plugin->afterCollectTotals($quote, $quote));
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
     * The PROD-16503 failure: Magento placeOrder reloaded the quote, collectTotals
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
        $address = $quote->getShippingAddress();
        $written = [];
        $address->method('setData')->willReturnCallback(function ($key, $value) use (&$written, $address) {
            $written[$key] = $value;
            return $address;
        });
        $this->quoteShipping->expects($this->once())
            ->method('restore')
            ->with($quote, $shippingSnap, 'captured-collect')
            ->willReturn(true);
        $quote->expects($this->atLeastOnce())->method('setGrandTotal')->with(343.83);
        $address->expects($this->once())->method('setShippingAmount')->with(55.0);
        $address->expects($this->once())->method('setBaseShippingAmount')->with(55.0);

        $this->assertSame($quote, $this->plugin->afterCollectTotals($quote, $quote));
        // Floats, not the snapshot's strings: the order must not carry both types.
        $this->assertSame(-14.31, $written['base_discount_amount']);
        $this->assertSame(303.14, $written['subtotal']);
        $this->assertSame(0.0, $written['tax_amount']);
        $this->assertSame(0.0, $written['base_tax_amount']);
        $this->assertSame(0.0, $written['shipping_tax_amount']);
        $this->assertSame(0.0, $written['base_shipping_tax_amount']);
    }

    public function testAfterCollectLogsWhenLiveGrandTotalDriftsFromPaid(): void
    {
        $shippingSnap = [
            'method' => 'fedex_FEDEX_GROUND',
            'amount' => 55.0,
            'baseAmount' => 55.0,
            'description' => 'FedEx Ground',
            'rate' => ['code' => 'fedex_FEDEX_GROUND', 'price' => 55.0],
        ];
        $quote = $this->matchingCapturedQuote($shippingSnap, 343.83, '1', true, 288.83);

        $logger = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $logger->expects($this->once())->method('warning')->with($this->callback(function ($message) {
            return is_string($message)
                && str_contains(strtolower($message), 'drift')
                && str_contains($message, '288.83')
                && str_contains($message, '343.83');
        }));
        $plugin = $this->makePlugin($logger);

        $quote->expects($this->atLeastOnce())->method('setGrandTotal')->with(343.83);

        $this->assertSame($quote, $plugin->afterCollectTotals($quote, $quote));
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

    public function testAfterCollectPinsWhenHashDiffersButPaidTotalIsWithinACent(): void
    {
        $shippingSnap = [
            'method' => 'fedex_FEDEX_GROUND',
            'amount' => '55',
            'baseAmount' => '55',
            'description' => 'FedEx Ground',
            'rate' => ['code' => 'fedex_FEDEX_GROUND', 'price' => 55.0],
        ];
        $quote = $this->matchingCapturedQuote($shippingSnap, 343.83, '2', true, 343.84);
        $this->quoteShipping->expects($this->once())
            ->method('restore')
            ->with($quote, $shippingSnap, 'captured-collect')
            ->willReturn(true);
        $quote->expects($this->atLeastOnce())->method('setGrandTotal')->with(343.83);

        $this->assertSame($quote, $this->plugin->afterCollectTotals($quote, $quote));
    }

    public function testAfterCollectDoesNotPinWhenHashDiffersAndTotalMoved(): void
    {
        $shippingSnap = [
            'method' => 'fedex_FEDEX_GROUND',
            'amount' => 55.0,
            'baseAmount' => 55.0,
            'description' => 'FedEx Ground',
            'rate' => ['code' => 'fedex_FEDEX_GROUND'],
        ];
        $quote = $this->matchingCapturedQuote($shippingSnap, 343.83, '2', true, 400.00);
        $this->quoteShipping->expects($this->never())->method('restore');
        $quote->expects($this->never())->method('setGrandTotal');

        $this->assertSame($quote, $this->plugin->afterCollectTotals($quote, $quote));
    }

    public function testAfterCollectPinsRootDiscountWhenOldSnapshotHasNoAddress(): void
    {
        $shippingSnap = [
            'method' => 'fedex_FEDEX_GROUND',
            'amount' => 55.0,
            'baseAmount' => 55.0,
            'description' => 'FedEx Ground',
            'rate' => ['code' => 'fedex_FEDEX_GROUND', 'price' => 55.0],
        ];
        $quote = $this->matchingCapturedQuote($shippingSnap, 343.83, '1', false);
        $address = $quote->getShippingAddress();
        $address->expects($this->once())->method('setDiscountAmount')->with(-14.31);
        $written = [];
        $address->method('setData')->willReturnCallback(function ($key, $value) use (&$written, $address) {
            $written[$key] = $value;
            return $address;
        });
        $this->quoteShipping->method('restore')->willReturn(true);

        $this->assertSame($quote, $this->plugin->afterCollectTotals($quote, $quote));
        $this->assertArrayNotHasKey('base_discount_amount', $written);
    }

    /**
     * Multishipping spreads totals over several addresses and skips submit(),
     * so pinning one quote-level total would leave the order half pinned.
     */
    public function testAfterCollectLeavesMultishippingQuoteAlone(): void
    {
        $shippingSnap = [
            'method' => 'fedex_FEDEX_GROUND',
            'amount' => 55.0,
            'baseAmount' => 55.0,
            'description' => 'FedEx Ground',
            'rate' => ['code' => 'fedex_FEDEX_GROUND', 'price' => 55.0],
        ];
        $quote = $this->matchingCapturedQuote($shippingSnap, 343.83, '1', true, null, true);

        $logger = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $logger->expects($this->once())->method('warning')->with($this->callback(function ($message) {
            return is_string($message) && str_contains($message, 'multishipping quote is not pinned');
        }));
        $plugin = $this->makePlugin($logger);

        $this->quoteShipping->expects($this->never())->method('restore');
        $quote->expects($this->never())->method('setGrandTotal');

        $this->assertSame($quote, $plugin->afterCollectTotals($quote, $quote));
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
            ->addMethods([
                'setTotalsCollectedFlag', 'setGrandTotal', 'setBaseGrandTotal',
                'getGrandTotal', 'getBaseGrandTotal', 'getIsMultiShipping',
            ])
            ->getMock();
        $quote->method('getId')->willReturn(770001);
        $quote->method('getIsMultiShipping')->willReturn(false);
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
    private function matchingCapturedQuote(
        array $shippingSnap,
        float $grandTotal,
        string $currentQty = '1',
        bool $includeAddressMoney = true,
        ?float $liveGrandTotal = null,
        bool $multiShipping = false
    ): Quote
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
                'getData', 'setData',
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

        $stampedHash = CaptureSnapshot::hashParts(
            [['sku' => 'SKU', 'qty' => '1']],
            [
                'city' => 'Santa Cruz',
                'postcode' => '95060',
                'country' => 'US',
            ]
        );
        $payloadData = [
            'hash' => $stampedHash,
            'grand_total' => (string)$grandTotal,
            'base_grand_total' => (string)$grandTotal,
            'discount_amount' => '-14.31',
            'shipping' => $shippingSnap,
        ];
        if ($includeAddressMoney) {
            $payloadData['address'] = [
                'subtotal' => '303.14',
                'base_subtotal' => '303.14',
                'subtotal_with_discount' => '288.83',
                'base_subtotal_with_discount' => '288.83',
                'tax_amount' => '0',
                'base_tax_amount' => '0',
                'discount_amount' => '-14.31',
                'base_discount_amount' => '-14.31',
                'shipping_incl_tax' => '55',
                'base_shipping_incl_tax' => '55',
                'shipping_tax_amount' => '0',
                'base_shipping_tax_amount' => '0',
            ];
        }
        $payload = json_encode($payloadData, JSON_UNESCAPED_SLASHES);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getData', 'getId', 'getAllVisibleItems', 'isVirtual',
                'getShippingAddress', 'getBillingAddress',
            ])
            ->addMethods([
                'setTotalsCollectedFlag', 'setGrandTotal', 'setBaseGrandTotal',
                'getGrandTotal', 'getBaseGrandTotal', 'getIsMultiShipping',
            ])
            ->getMock();
        $quote->method('getId')->willReturn(770001);
        $quote->method('getIsMultiShipping')->willReturn($multiShipping);
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
        if ($liveGrandTotal !== null) {
            $quote->method('getGrandTotal')->willReturn($liveGrandTotal);
        }
        return $quote;
    }
}
