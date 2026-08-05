<?php

namespace PayStand\PayStandMagento\Test\Unit\Helper;

use PayStand\PayStandMagento\Helper\QuoteShipping;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;

/**
 * Unit tests for Helper\QuoteShipping — the guard that keeps a totals
 * recollection from costing a shopper their shipping selection.
 *
 * Background: Quote\Address::collectShippingRates() clears the shipping method
 * and zeroes the amount whenever a rate re-request comes back empty, and
 * ShippingMethodValidationRule then rejects the order with "The shipping method
 * is missing." after the customer has already been charged.
 */
class QuoteShippingTest extends TestCase
{
    /** @var QuoteShipping */
    private $helper;

    protected function setUp(): void
    {
        $this->helper = new QuoteShipping(
            $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass()
        );
    }

    // ── snapshot ─────────────────────────────────────────────────────────────

    public function testSnapshotReturnsNullForVirtualQuote(): void
    {
        $quote = $this->buildQuote(true, null);
        $this->assertNull($this->helper->snapshot($quote));
    }

    public function testSnapshotReturnsNullWhenNoShippingAddress(): void
    {
        $quote = $this->buildQuote(false, null);
        $this->assertNull($this->helper->snapshot($quote));
    }

    public function testSnapshotCapturesTheSelection(): void
    {
        $address = $this->buildAddress('flatrate_flatrate', 77.00, 'Flat Rate - Fixed');
        $quote = $this->buildQuote(false, $address);

        $snapshot = $this->helper->snapshot($quote);

        $this->assertSame('flatrate_flatrate', $snapshot['method']);
        $this->assertSame(77.00, $snapshot['amount']);
        $this->assertSame('Flat Rate - Fixed', $snapshot['description']);
    }

    // ── restore ──────────────────────────────────────────────────────────────

    /**
     * The core case: the recollection wiped the method, so it must be put back.
     */
    public function testRestorePutsBackAClearedSelection(): void
    {
        // Method reads back as '' — exactly what Magento leaves behind.
        $address = $this->buildAddress('', 0.0, '');
        $address->expects($this->once())->method('setShippingMethod')->with('flatrate_flatrate');
        $address->expects($this->once())->method('setShippingAmount')->with(77.00);
        $address->expects($this->once())->method('setBaseShippingAmount')->with(77.00);

        $quote = $this->buildQuote(false, $address);
        $snapshot = [
            'method'      => 'flatrate_flatrate',
            'amount'      => 77.00,
            'baseAmount'  => 77.00,
            'description' => 'Flat Rate - Fixed',
        ];

        $this->assertTrue($this->helper->restore($quote, $snapshot, 'test'));
    }

    /**
     * A surviving selection must be left completely alone — restoring over a
     * live method could reinstate a stale shipping price.
     */
    public function testRestoreLeavesASurvivingSelectionUntouched(): void
    {
        $address = $this->buildAddress('flatrate_flatrate', 77.00, 'Flat Rate - Fixed');
        $address->expects($this->never())->method('setShippingMethod');

        $quote = $this->buildQuote(false, $address);
        $snapshot = [
            'method'      => 'flatrate_flatrate',
            'amount'      => 77.00,
            'baseAmount'  => 77.00,
            'description' => 'Flat Rate - Fixed',
        ];

        $this->assertFalse($this->helper->restore($quote, $snapshot, 'test'));
    }

    public function testRestoreIsANoOpWithoutASnapshot(): void
    {
        $quote = $this->buildQuote(false, $this->buildAddress('', 0.0, ''));

        $this->assertFalse($this->helper->restore($quote, null, 'test'));
        $this->assertFalse($this->helper->restore($quote, ['method' => ''], 'test'));
    }

    // ── describe ─────────────────────────────────────────────────────────────

    /**
     * "method set, rate missing" is the state that fails order placement, so
     * the breadcrumb has to distinguish it from a healthy selection.
     */
    public function testDescribeFlagsAMissingRate(): void
    {
        $address = $this->buildAddress('flatrate_flatrate', 0.0, '');
        $address->method('getShippingRateByCode')->willReturn(false);

        $out = $this->helper->describe($this->buildQuote(false, $address));

        $this->assertStringContainsString('method=flatrate_flatrate', $out);
        $this->assertStringContainsString('rate=NO', $out);
    }

    public function testDescribeReportsAHealthySelection(): void
    {
        $address = $this->buildAddress('flatrate_flatrate', 77.00, 'Flat Rate - Fixed');
        $address->method('getShippingRateByCode')->willReturn(new \stdClass());

        $out = $this->helper->describe($this->buildQuote(false, $address));

        $this->assertStringContainsString('method=flatrate_flatrate', $out);
        $this->assertStringContainsString('rate=yes', $out);
    }

    /**
     * The address variant of the same defect: a paid cart that placeOrder rejects
     * with "street"/"city"/"telephone is required". The breadcrumb has to name the
     * missing fields, and must never carry their values off the merchant's server.
     */
    public function testDescribeReportsMissingAddressFields(): void
    {
        $address = $this->buildAddress('flatrate_flatrate', 5.00, 'Flat Rate', [
            'street'     => [''],
            'city'       => '',
            'telephone'  => '5551234567',
            'country_id' => 'US',
        ]);
        $address->method('getShippingRateByCode')->willReturn(new \stdClass());

        $out = $this->helper->describe($this->buildQuote(false, $address));

        $this->assertStringContainsString('addr=MISSING[street,city]', $out);
        $this->assertStringNotContainsString('5551234567', $out, 'must not leak address values');
    }

    public function testDescribeReportsACompleteAddress(): void
    {
        $address = $this->buildAddress('flatrate_flatrate', 5.00, 'Flat Rate');
        $address->method('getShippingRateByCode')->willReturn(new \stdClass());

        $this->assertStringContainsString(
            'addr=complete',
            $this->helper->describe($this->buildQuote(false, $address))
        );
    }

    public function testDescribeHandlesVirtualQuote(): void
    {
        $this->assertSame('shipping=virtual', $this->helper->describe($this->buildQuote(true, null)));
    }

    public function testDescribeNeverThrows(): void
    {
        $this->assertSame('shipping=no-quote', $this->helper->describe(null));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param bool $isVirtual
     * @param Address|MockObject|null $address
     * @return Quote|MockObject
     */
    private function buildQuote(bool $isVirtual, $address)
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isVirtual', 'getShippingAddress', 'getId'])
            ->getMock();
        $quote->method('isVirtual')->willReturn($isVirtual);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getId')->willReturn(4277928);
        return $quote;
    }

    /**
     * getShippingAmount / getBaseShippingAmount / getShippingDescription /
     * setShippingMethod / setShippingDescription are magic data accessors on
     * Address, so they need addMethods() rather than onlyMethods().
     *
     * @param string $method
     * @param float $amount
     * @param string $description
     * @return Address|MockObject
     */
    private function buildAddress(string $method, float $amount, string $description, array $addressData = null)
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingMethod', 'getShippingRateByCode', 'setShippingAmount', 'setBaseShippingAmount', 'getData'])
            ->addMethods(['setShippingMethod', 'getShippingAmount', 'getBaseShippingAmount', 'getShippingDescription', 'setShippingDescription'])
            ->getMock();
        $address->method('getShippingMethod')->willReturn($method);
        $address->method('getShippingAmount')->willReturn($amount);
        $address->method('getBaseShippingAmount')->willReturn($amount);
        $address->method('getShippingDescription')->willReturn($description);

        // Complete address unless a scenario overrides it.
        $data = $addressData ?? [
            'street'     => ['123 Main St'],
            'city'       => 'Santa Cruz',
            'telephone'  => '5551234567',
            'country_id' => 'US',
        ];
        $address->method('getData')->willReturnCallback(function ($key = null) use ($data) {
            return $data[$key] ?? null;
        });

        return $address;
    }
}
