<?php

namespace PayStand\PayStandMagento\Test\Unit\Helper;

use PayStand\PayStandMagento\Helper\CaptureSnapshot;
use PayStand\PayStandMagento\Helper\QuoteShipping;
use PHPUnit\Framework\TestCase;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;

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
            ['id' => '2', 'sku' => 'B', 'qty' => '1'],
            ['id' => '1', 'sku' => 'A', 'qty' => '2'],
        ];
        $address = [
            'street' => ['123 Main'],
            'city' => 'Santa Cruz',
            'postcode' => '95060',
            'country' => 'US',
        ];

        $this->assertSame(
            CaptureSnapshot::hashParts($items, $address),
            CaptureSnapshot::hashParts(array_reverse($items), $address)
        );
    }

    public function testHashPartsChangesWhenQtyChanges(): void
    {
        $address = [
            'street' => ['123 Main'],
            'city' => 'Santa Cruz',
            'postcode' => '95060',
            'country' => 'US',
        ];
        $one = CaptureSnapshot::hashParts(
            [['id' => '1', 'sku' => 'A', 'qty' => '1']],
            $address
        );
        $two = CaptureSnapshot::hashParts(
            [['id' => '1', 'sku' => 'A', 'qty' => '2']],
            $address
        );

        $this->assertNotSame($one, $two);
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

        $snapshot = new CaptureSnapshot($quoteShipping);
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

        $snapshot = new CaptureSnapshot($quoteShipping);
        $snapshot->ensureStamped($quote);
        $first = $stored;
        $snapshot->ensureStamped($quote);

        $this->assertNotNull($first);
        $this->assertSame($first, $stored);
    }

    public function testEnsureStampedSkipsUncapturedQuotes(): void
    {
        $quote = $this->quoteWith('pay1', null);
        $quote->expects($this->never())->method('setData');

        $this->makeSnapshot()->ensureStamped($quote);
    }

    private function makeSnapshot(): CaptureSnapshot
    {
        return new CaptureSnapshot(
            $this->getMockBuilder(QuoteShipping::class)->disableOriginalConstructor()->getMock()
        );
    }

    private function quoteWith(?string $paymentId, ?string $captureStatus): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getData', 'setData', 'getAllVisibleItems', 'isVirtual',
                'getShippingAddress', 'getBillingAddress',
            ])
            ->addMethods(['getGrandTotal', 'getBaseGrandTotal'])
            ->getMock();
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

    private function address(): Address
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStreet', 'getCity', 'getPostcode', 'getCountryId'])
            ->addMethods(['getDiscountAmount'])
            ->getMock();
        $address->method('getStreet')->willReturn(['123 Main']);
        $address->method('getCity')->willReturn('Santa Cruz');
        $address->method('getPostcode')->willReturn('95060');
        $address->method('getCountryId')->willReturn('US');
        $address->method('getDiscountAmount')->willReturn(-14.31);
        return $address;
    }
}
