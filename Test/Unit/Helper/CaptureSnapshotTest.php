<?php

namespace PayStand\PayStandMagento\Test\Unit\Helper;

use PayStand\PayStandMagento\Helper\CaptureSnapshot;
use PayStand\PayStandMagento\Helper\QuoteShipping;
use PHPUnit\Framework\TestCase;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for Helper\CaptureSnapshot — the paid-cart fingerprint persisted on
 * the quote so collectTotals can run after capture without losing the shipping
 * Magento dropped on reload.
 */
class CaptureSnapshotTest extends TestCase
{
    public function testHashPartsIsStableForTheSameCart(): void
    {
        $items = [
            ['sku' => 'B', 'qty' => '1'],
            ['sku' => 'A', 'qty' => '2'],
        ];
        $address = [
            'city' => 'Santa Cruz',
            'postcode' => '95060',
            'country' => 'US',
        ];

        $this->assertSame(
            CaptureSnapshot::hashParts($items, $address),
            CaptureSnapshot::hashParts(array_reverse($items), $address)
        );
    }

    public function testHashIgnoresItemIdAndStreet(): void
    {
        $left = CaptureSnapshot::hashParts(
            [['sku' => 'A', 'qty' => '1', 'id' => '9']],
            [
                'city' => 'Santa Cruz',
                'postcode' => '95060',
                'country' => 'US',
                'street' => ['123 Main'],
            ]
        );
        $right = CaptureSnapshot::hashParts(
            [['sku' => 'A', 'qty' => '1', 'id' => '99']],
            [
                'city' => 'Santa Cruz',
                'postcode' => '95060',
                'country' => 'US',
                'street' => ['123 Main', ''],
            ]
        );
        $this->assertSame($left, $right);
    }

    public function testHashChangesWhenQtyChanges(): void
    {
        $address = [
            'city' => 'Santa Cruz',
            'postcode' => '95060',
            'country' => 'US',
        ];
        $this->assertNotSame(
            CaptureSnapshot::hashParts([['sku' => 'A', 'qty' => '1']], $address),
            CaptureSnapshot::hashParts([['sku' => 'A', 'qty' => '2']], $address)
        );
    }

    public function testHashChangesWhenCityChanges(): void
    {
        $items = [['sku' => 'A', 'qty' => '1']];
        $this->assertNotSame(
            CaptureSnapshot::hashParts($items, [
                'city' => 'Santa Cruz',
                'postcode' => '95060',
                'country' => 'US',
            ]),
            CaptureSnapshot::hashParts($items, [
                'city' => 'San Jose',
                'postcode' => '95060',
                'country' => 'US',
            ])
        );
    }

    public function testHashNormalizesSkuCaseAndQtyFloat(): void
    {
        $address = [
            'city' => 'Santa Cruz',
            'postcode' => '95060',
            'country' => 'us',
        ];
        $this->assertSame(
            CaptureSnapshot::hashParts([['sku' => 'Abc', 'qty' => '1']], $address),
            CaptureSnapshot::hashParts([['sku' => 'abc', 'qty' => '1.0']], $address)
        );
    }

    public function testMatchesIgnoresItemIdAndStreetOnTheQuote(): void
    {
        $snapshot = $this->makeSnapshot();
        $a = $this->quoteWith('pay1', 'posted');
        $a->method('getAllVisibleItems')->willReturn([$this->item('9', 'SKU', '1')]);
        $a->method('isVirtual')->willReturn(false);
        $a->method('getShippingAddress')->willReturn($this->address(['123 Main']));

        $b = $this->quoteWith('pay1', 'posted');
        $b->method('getAllVisibleItems')->willReturn([$this->item('99', 'SKU', '1')]);
        $b->method('isVirtual')->willReturn(false);
        $b->method('getShippingAddress')->willReturn($this->address(['123 Main', '']));

        $this->assertTrue($snapshot->matches($b, ['hash' => $snapshot->hash($a)]));
    }

    public function testIsCapturedRequiresBothFields(): void
    {
        $snapshot = $this->makeSnapshot();

        $this->assertFalse($snapshot->isCaptured(null));
        $this->assertFalse($snapshot->isCaptured($this->quoteWith(null, 'posted')));
        $this->assertFalse($snapshot->isCaptured($this->quoteWith('pay1', null)));
        $this->assertTrue($snapshot->isCaptured($this->quoteWith('pay1', 'posted')));
    }

    public function testStampPersistsHashTotalsAndShipping(): void
    {
        $shipping = [
            'method' => 'fedex_FEDEX_GROUND',
            'amount' => 55.0,
            'baseAmount' => 55.0,
            'description' => 'FedEx Ground',
            'rate' => ['code' => 'fedex_FEDEX_GROUND', 'price' => 55.0],
        ];
        $quoteShipping = $this->getMockBuilder(QuoteShipping::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['snapshot'])
            ->getMock();
        $quoteShipping->method('snapshot')->willReturn($shipping);

        $quote = $this->quoteWith('pay1', 'posted');
        $quote->method('getAllVisibleItems')->willReturn([$this->item('9', 'SKU', '1')]);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getGrandTotal')->willReturn(343.83);
        $quote->method('getBaseGrandTotal')->willReturn(343.83);
        $address = $this->address();
        $quote->method('getShippingAddress')->willReturn($address);

        $written = null;
        $quote->method('setData')->willReturnCallback(function ($key, $value) use (&$written, $quote) {
            if ($key === CaptureSnapshot::QUOTE_FIELD) {
                $written = $value;
            }
            return $quote;
        });

        $snapshot = new CaptureSnapshot(
            $quoteShipping,
            $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass()
        );
        $snapshot->stamp($quote);

        $this->assertNotNull($written);
        $payload = json_decode($written, true);
        $this->assertSame('343.83', $payload['grand_total']);
        $this->assertSame('fedex_FEDEX_GROUND', $payload['shipping']['method']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['hash']);
    }

    public function testMatchesIsTrueOnlyForTheSameCart(): void
    {
        $snapshot = $this->makeSnapshot();
        $quote = $this->quoteWith('pay1', 'posted');
        $quote->method('getAllVisibleItems')->willReturn([$this->item('9', 'SKU', '1')]);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getShippingAddress')->willReturn($this->address());

        $hash = $snapshot->hash($quote);
        $this->assertTrue($snapshot->matches($quote, ['hash' => $hash]));
        $this->assertFalse($snapshot->matches($quote, ['hash' => str_repeat('ab', 32)]));
    }

    public function testReadReturnsNullForGarbage(): void
    {
        $snapshot = $this->makeSnapshot();
        $quote = $this->quoteWith('pay1', 'posted');
        $quote->method('getData')->willReturnCallback(function ($key) {
            return $key === CaptureSnapshot::QUOTE_FIELD ? '{not-json' : 'pay1';
        });

        $this->assertNull($snapshot->read($quote));
    }

    public function testEnsureStampedWritesOnce(): void
    {
        $quoteShipping = $this->getMockBuilder(QuoteShipping::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['snapshot'])
            ->getMock();
        $quoteShipping->method('snapshot')->willReturn(null);

        $stored = null;
        $quote = $this->quoteWith('pay1', 'posted');
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('isVirtual')->willReturn(true);
        $quote->method('getGrandTotal')->willReturn(10);
        $quote->method('getBaseGrandTotal')->willReturn(10);
        $quote->method('getBillingAddress')->willReturn(null);
        $quote->method('getData')->willReturnCallback(function ($key) use (&$stored) {
            if ($key === 'paystand_payment_id') {
                return 'pay1';
            }
            if ($key === 'paystand_capture_status') {
                return 'posted';
            }
            if ($key === CaptureSnapshot::QUOTE_FIELD) {
                return $stored;
            }
            return null;
        });
        $quote->method('setData')->willReturnCallback(function ($key, $value) use (&$stored, $quote) {
            if ($key === CaptureSnapshot::QUOTE_FIELD) {
                $stored = $value;
            }
            return $quote;
        });

        $snapshot = new CaptureSnapshot(
            $quoteShipping,
            $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass()
        );
        $snapshot->ensureStamped($quote);
        $first = $stored;
        $snapshot->ensureStamped($quote);

        $this->assertNotNull($first);
        $this->assertSame($first, $stored);
    }

    public function testStampWarnsWhenMethodHasNoRateRow(): void
    {
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $logger->expects($this->once())->method('warning');

        $quoteShipping = $this->getMockBuilder(QuoteShipping::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['snapshot'])
            ->getMock();
        $quoteShipping->method('snapshot')->willReturn([
            'method' => 'fedex_FEDEX_GROUND',
            'amount' => 55.0,
            'baseAmount' => 55.0,
            'description' => 'FedEx Ground',
            'rate' => null,
        ]);

        $quote = $this->quoteWith('pay1', 'posted');
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getGrandTotal')->willReturn(343.83);
        $quote->method('getBaseGrandTotal')->willReturn(343.83);
        $quote->method('getShippingAddress')->willReturn($this->address());
        $written = null;
        $quote->method('setData')->willReturnCallback(function ($key, $value) use (&$written, $quote) {
            if ($key === CaptureSnapshot::QUOTE_FIELD) {
                $written = $value;
            }
            return $quote;
        });

        (new CaptureSnapshot($quoteShipping, $logger))->stamp($quote);

        $payload = json_decode((string)$written, true);
        $this->assertSame('fedex_FEDEX_GROUND', $payload['shipping']['rate']['code']);
        $this->assertSame('fedex', $payload['shipping']['rate']['carrier']);
        $this->assertSame('FEDEX_GROUND', $payload['shipping']['rate']['method']);
        $this->assertEquals(55.0, $payload['shipping']['rate']['price']);
    }

    public function testStampSynthesizesRateFromPaidBagWhenLiveRateMissing(): void
    {
        $quoteShipping = $this->getMockBuilder(QuoteShipping::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['snapshot'])
            ->getMock();
        $quoteShipping->method('snapshot')->willReturn([
            'method' => 'fedex_FEDEX_GROUND',
            'amount' => 55.0,
            'baseAmount' => 55.0,
            'description' => 'FedEx Ground',
            'rate' => null,
        ]);

        $quote = $this->quoteWith('pay1', 'posted');
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getGrandTotal')->willReturn(343.83);
        $quote->method('getBaseGrandTotal')->willReturn(343.83);
        $quote->method('getShippingAddress')->willReturn($this->address());
        $written = null;
        $quote->method('setData')->willReturnCallback(function ($key, $value) use (&$written, $quote) {
            if ($key === CaptureSnapshot::QUOTE_FIELD) {
                $written = $value;
            }
            return $quote;
        });

        $snapshot = new CaptureSnapshot(
            $quoteShipping,
            $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass()
        );
        $snapshot->stamp($quote, [
            'grand_total' => '343.83',
            'base_grand_total' => '343.83',
            'discount_amount' => '-14.31',
            'shipping' => [
                'method' => 'fedex_FEDEX_GROUND',
                'amount' => 55.0,
                'baseAmount' => 55.0,
                'description' => 'FedEx Ground',
                'rate' => null,
            ],
        ]);

        $payload = json_decode((string)$written, true);
        $this->assertSame('fedex_FEDEX_GROUND', $payload['shipping']['rate']['code']);
        $this->assertEquals(55.0, $payload['shipping']['rate']['price']);
        $this->assertSame('FedEx Ground', $payload['shipping']['rate']['methodTitle']);
    }

    public function testStampUsesPaidGrandTotalNotLiveQuoteTotal(): void
    {
        $quoteShipping = $this->getMockBuilder(QuoteShipping::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['snapshot'])
            ->getMock();
        $quoteShipping->method('snapshot')->willReturn([
            'method' => 'fedex_FEDEX_GROUND',
            'amount' => 55.0,
            'baseAmount' => 55.0,
            'description' => 'FedEx Ground',
            'rate' => ['code' => 'fedex_FEDEX_GROUND', 'price' => 55.0],
        ]);

        $quote = $this->quoteWith('pay1', 'posted');
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getGrandTotal')->willReturn(288.83);
        $quote->method('getBaseGrandTotal')->willReturn(288.83);
        $quote->method('getShippingAddress')->willReturn($this->address());

        $written = null;
        $quote->method('setData')->willReturnCallback(function ($key, $value) use (&$written, $quote) {
            if ($key === CaptureSnapshot::QUOTE_FIELD) {
                $written = $value;
            }
            return $quote;
        });

        $snapshot = new CaptureSnapshot(
            $quoteShipping,
            $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass()
        );
        $snapshot->stamp($quote, [
            'grand_total' => '343.83',
            'base_grand_total' => '343.83',
            'discount_amount' => '-14.31',
            'shipping' => [
                'method' => 'fedex_FEDEX_GROUND',
                'amount' => 55.0,
                'baseAmount' => 55.0,
                'description' => 'FedEx Ground',
                'rate' => null,
            ],
        ]);

        $payload = json_decode($written, true);
        $this->assertSame('343.83', $payload['grand_total']);
        $this->assertSame('fedex_FEDEX_GROUND', $payload['shipping']['rate']['code']);
    }

    public function testEnsureStampedSkipsUncapturedQuotes(): void
    {
        $quote = $this->quoteWith('pay1', null);
        $quote->expects($this->never())->method('setData');

        $this->makeSnapshot()->ensureStamped($quote);
    }

    public function testEnsureStampedLogsWhenStampThrows(): void
    {
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $logger->expects($this->once())->method('error');

        $quoteShipping = $this->getMockBuilder(QuoteShipping::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['snapshot'])
            ->getMock();
        $quoteShipping->method('snapshot')->willThrowException(new \RuntimeException('boom'));

        $quote = $this->quoteWith('pay1', 'posted');
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('isVirtual')->willReturn(true);
        $quote->method('getGrandTotal')->willReturn(10);
        $quote->method('getBaseGrandTotal')->willReturn(10);
        $quote->method('getBillingAddress')->willReturn(null);

        (new CaptureSnapshot($quoteShipping, $logger))->ensureStamped($quote);
    }

    private function makeSnapshot(): CaptureSnapshot
    {
        return new CaptureSnapshot(
            $this->getMockBuilder(QuoteShipping::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass()
        );
    }

    private function quoteWith(?string $paymentId, ?string $captureStatus): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getData', 'setData', 'getAllVisibleItems', 'isVirtual',
                'getShippingAddress', 'getBillingAddress', 'getId',
            ])
            ->addMethods(['getGrandTotal', 'getBaseGrandTotal'])
            ->getMock();
        $quote->method('getId')->willReturn(4490737);
        $quote->method('getData')->willReturnCallback(function ($key) use ($paymentId, $captureStatus) {
            if ($key === 'paystand_payment_id') {
                return $paymentId;
            }
            if ($key === 'paystand_capture_status') {
                return $captureStatus;
            }
            return null;
        });
        return $quote;
    }

    private function item(string $id, string $sku, string $qty): Item
    {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getSku', 'getQty'])
            ->getMock();
        $item->method('getId')->willReturn($id);
        $item->method('getSku')->willReturn($sku);
        $item->method('getQty')->willReturn($qty);
        return $item;
    }

    /**
     * @param array<int, string> $street
     */
    private function address(array $street = ['123 Main']): Address
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStreet', 'getCity', 'getPostcode', 'getCountryId'])
            ->addMethods(['getDiscountAmount'])
            ->getMock();
        $address->method('getStreet')->willReturn($street);
        $address->method('getCity')->willReturn('Santa Cruz');
        $address->method('getPostcode')->willReturn('95060');
        $address->method('getCountryId')->willReturn('US');
        $address->method('getDiscountAmount')->willReturn(-14.31);
        return $address;
    }
}
