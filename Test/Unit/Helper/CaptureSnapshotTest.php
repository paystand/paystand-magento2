<?php

namespace PayStand\PayStandMagento\Test\Unit\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PayStand\PayStandMagento\Helper\CaptureSnapshot;
use PayStand\PayStandMagento\Helper\QuoteShipping;
use PayStand\PayStandMagento\Plugin\CapturedQuoteSubmit;
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
    private const UNPAID_ROW = [
        'entity_id = ?' => 770001,
        "paystand_payment_id IS NULL OR paystand_payment_id = ''",
    ];

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

    /**
     * Fixed vector so a 3.7.4 key-order or normalisation change cannot mismatch
     * in-flight 3.7.3 snapshots with a green suite.
     */
    public function testHashPartsGoldenVector(): void
    {
        $this->assertSame(
            '2cc3e7b7153c0ca1b00713d867936e0b46900823dfa338cb9f013c80e64445d6',
            CaptureSnapshot::hashParts(
                [['sku' => 'A', 'qty' => '1']],
                [
                    'city' => 'Santa Cruz',
                    'postcode' => '95060',
                    'country' => 'us',
                ]
            )
        );
    }

    /**
     * row_total is what separates two carts that sku + qty cannot tell apart:
     * a priced custom option and a different child of one configurable.
     * Without it those hash equal and pin the wrong total.
     */
    public function testHashChangesWhenRowTotalChanges(): void
    {
        $address = [
            'city' => 'Santa Cruz',
            'postcode' => '95060',
            'country' => 'us',
        ];
        $this->assertNotSame(
            CaptureSnapshot::hashParts([['sku' => 'A', 'qty' => '1', 'row_total' => '10.00']], $address),
            CaptureSnapshot::hashParts([['sku' => 'A', 'qty' => '1', 'row_total' => '12.00']], $address)
        );
    }

    /**
     * row_total is money. A re-render or a decimal(20,4) reload must not move
     * it, or every in-flight paid cart mismatches for no shopper action.
     */
    public function testHashRoundsRowTotalToCents(): void
    {
        $address = [
            'city' => 'Santa Cruz',
            'postcode' => '95060',
            'country' => 'us',
        ];
        $this->assertSame(
            CaptureSnapshot::hashParts([['sku' => 'A', 'qty' => '1', 'row_total' => '10.00']], $address),
            CaptureSnapshot::hashParts([['sku' => 'A', 'qty' => '1', 'row_total' => '10.0000']], $address)
        );
        $this->assertSame(
            CaptureSnapshot::hashParts([['sku' => 'A', 'qty' => '1', 'row_total' => '10.004']], $address),
            CaptureSnapshot::hashParts([['sku' => 'A', 'qty' => '1', 'row_total' => 10]], $address)
        );
    }

    /**
     * hash() must read the base figure. row_total is the display-currency
     * amount, so a nightly rate import would otherwise trip every paid cart.
     */
    public function testHashReadsBaseRowTotal(): void
    {
        $snapshot = $this->makeSnapshot();
        $quote = $this->quoteForHash(19.99, 24.5);

        $expected = CaptureSnapshot::hashParts(
            [['sku' => 'SKU', 'qty' => '1', 'row_total' => '19.99']],
            ['city' => 'Santa Cruz', 'postcode' => '95060', 'country' => 'US']
        );

        $this->assertSame($expected, $snapshot->hash($quote));
    }

    /**
     * A false refusal blocks an order the shopper already paid for, so the
     * normalisation has to absorb spellings that are not a destination change.
     */
    public function testHashFoldsMultibyteCityCase(): void
    {
        $items = [['sku' => 'A', 'qty' => '1', 'row_total' => '10.00']];
        $this->assertSame(
            CaptureSnapshot::hashParts($items, [
                'city' => 'MÜNCHEN',
                'postcode' => '80331',
                'country' => 'DE',
            ]),
            CaptureSnapshot::hashParts($items, [
                'city' => 'München',
                'postcode' => '80331',
                'country' => 'de',
            ])
        );
    }

    public function testHashIgnoresPostcodePunctuation(): void
    {
        $items = [['sku' => 'A', 'qty' => '1', 'row_total' => '10.00']];
        $this->assertSame(
            CaptureSnapshot::hashParts($items, [
                'city' => 'Santa Cruz',
                'postcode' => '95060-1234',
                'country' => 'us',
            ]),
            CaptureSnapshot::hashParts($items, [
                'city' => 'Santa Cruz',
                'postcode' => '950601234',
                'country' => 'us',
            ])
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
        $this->assertTrue($snapshot->isCaptured($this->quoteWith('pay1', 'paid')));
        $this->assertFalse($snapshot->isCaptured($this->quoteWith('pay1', 'processing')));
    }

    /**
     * The two gates must stay separate. Freeze and refuse run on a confirmed
     * capture; the snapshot and the pin also run on the processing rescue,
     * which places an order.
     */
    public function testIsPinnableCoversProcessingAndIsCapturedDoesNot(): void
    {
        $snapshot = $this->makeSnapshot();

        $this->assertFalse($snapshot->isPinnable(null));
        $this->assertFalse($snapshot->isPinnable($this->quoteWith(null, 'processing')));
        $this->assertFalse($snapshot->isPinnable($this->quoteWith('pay1', null)));
        $this->assertTrue($snapshot->isPinnable($this->quoteWith('pay1', 'processing')));
        $this->assertTrue($snapshot->isPinnable($this->quoteWith('pay1', 'posted')));
        $this->assertTrue($snapshot->isPinnable($this->quoteWith('pay1', 'paid')));
        $this->assertFalse($snapshot->isPinnable($this->quoteWith('pay1', 'failed')));
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

    public function testReadLogsWhenSnapshotJsonIsCorrupt(): void
    {
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $logger->expects($this->once())->method('warning')->with($this->callback(function ($message) {
            return is_string($message)
                && str_contains($message, 'corrupt')
                && str_contains($message, '1001');
        }));

        // Dedicated mock: quoteWith() already stubs getData, so a second
        // getData stub would replace capture-field returns.
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getData', 'setData', 'getAllVisibleItems', 'isVirtual',
                'getShippingAddress', 'getBillingAddress', 'getId', 'getResource',
            ])
            ->addMethods(['getGrandTotal', 'getBaseGrandTotal'])
            ->getMock();
        $quote->method('getId')->willReturn(1001);
        $quote->method('getData')->willReturnCallback(function ($key) {
            if ($key === CaptureSnapshot::QUOTE_FIELD) {
                return '{not-json';
            }
            if ($key === 'paystand_payment_id') {
                return 'pay1';
            }
            if ($key === 'paystand_capture_status') {
                return 'posted';
            }
            return null;
        });

        $snapshot = new CaptureSnapshot(
            $this->getMockBuilder(QuoteShipping::class)->disableOriginalConstructor()->getMock(),
            $logger
        );
        $this->assertNull($snapshot->read($quote));
    }

    public function testReadDoesNotLogWhenSnapshotIsEmpty(): void
    {
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $logger->expects($this->never())->method('warning');

        $quote = $this->quoteWith('pay1', 'posted');
        $snapshot = new CaptureSnapshot(
            $this->getMockBuilder(QuoteShipping::class)->disableOriginalConstructor()->getMock(),
            $logger
        );
        $this->assertNull($snapshot->read($quote));
    }

    public function testEnsureStampedLogsWhenJsonEncodeThrows(): void
    {
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $logger->expects($this->once())->method('error');

        $quoteShipping = $this->getMockBuilder(QuoteShipping::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['snapshot'])
            ->getMock();
        $quoteShipping->method('snapshot')->willReturn(null);

        $quote = $this->quoteWith('pay1', 'posted');
        $quote->method('getResource')->willReturn($this->emptyPersistedSnapshotResource());
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('isVirtual')->willReturn(true);
        $quote->method('getGrandTotal')->willReturn(NAN);
        $quote->method('getBaseGrandTotal')->willReturn(10);
        $quote->method('getBillingAddress')->willReturn(null);

        (new CaptureSnapshot($quoteShipping, $logger))->ensureStamped($quote);
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

    /**
     * The window stamps 164.96. Another browser then doubles the quote.
     * The payment save must keep 164.96 so the guard can refuse 329.92.
     */
    public function testPaymentSaveKeepsTheSnapshotTakenWhenTheWindowOpened(): void
    {
        $quoteShipping = $this->getMockBuilder(QuoteShipping::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['snapshot'])
            ->getMock();
        $quoteShipping->method('snapshot')->willReturn(null);

        $paymentId = null;
        $captureStatus = null;
        $grandTotal = 164.96;
        $stored = null;
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getData', 'setData', 'getAllVisibleItems', 'isVirtual',
                'getShippingAddress', 'getBillingAddress', 'getId', 'getResource',
            ])
            ->addMethods(['getGrandTotal', 'getBaseGrandTotal'])
            ->getMock();
        $quote->method('getId')->willReturn(1201);
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('isVirtual')->willReturn(true);
        $quote->method('getBillingAddress')->willReturn(null);
        $quote->method('getGrandTotal')->willReturnCallback(function () use (&$grandTotal) {
            return $grandTotal;
        });
        $quote->method('getBaseGrandTotal')->willReturnCallback(function () use (&$grandTotal) {
            return $grandTotal;
        });
        $quote->method('getData')->willReturnCallback(function ($key) use (&$paymentId, &$captureStatus, &$stored) {
            if ($key === 'paystand_payment_id') {
                return $paymentId;
            }
            if ($key === 'paystand_capture_status') {
                return $captureStatus;
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

        $quote->method('getResource')->willReturn($this->unpaidRowResource(1));

        $snapshot = new CaptureSnapshot(
            $quoteShipping,
            $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass()
        );
        $this->assertTrue($snapshot->stampCheckoutIntent($quote));

        $paymentId = 'pay-window';
        $captureStatus = 'paid';
        $grandTotal = 329.92;
        $snapshot->ensureStamped($quote);

        $payload = json_decode((string)$stored, true);
        $this->assertSame('164.96', $payload['grand_total']);
        $this->assertSame(CaptureSnapshot::SOURCE_CHECKOUT, $payload['source']);
    }

    public function testEnsureStampedUsesPersistedColumnWhenMemoryIsEmpty(): void
    {
        $persisted = '{"hash":"' . str_repeat('ab', 32) . '","grand_total":"1"}';
        $connection = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['select', 'fetchOne'])
            ->getMock();
        $select = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['from', 'where'])
            ->getMock();
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn($persisted);

        $resource = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getConnection', 'getMainTable'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('quote');

        $written = null;
        $quote = $this->quoteWith('pay1', 'posted');
        $quote->method('getResource')->willReturn($resource);
        $quote->method('getData')->willReturnCallback(function ($key) use (&$written) {
            if ($key === CaptureSnapshot::QUOTE_FIELD) {
                return $written;
            }
            if ($key === 'paystand_payment_id') {
                return 'pay1';
            }
            if ($key === 'paystand_capture_status') {
                return 'posted';
            }
            return null;
        });
        $quote->method('setData')->willReturnCallback(function ($key, $value) use (&$written, $quote) {
            if ($key === CaptureSnapshot::QUOTE_FIELD) {
                $written = $value;
            }
            return $quote;
        });

        $quoteShipping = $this->getMockBuilder(QuoteShipping::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['snapshot'])
            ->getMock();
        $quoteShipping->expects($this->never())->method('snapshot');

        $snapshot = new CaptureSnapshot(
            $quoteShipping,
            $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass()
        );
        $snapshot->ensureStamped($quote, ['grand_total' => '999']);

        $this->assertSame($persisted, $written);
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

        $address = $this->address();
        $address->method('getData')->willReturnCallback(function ($key) {
            if ($key === 'subtotal') {
                return 999;
            }
            return null;
        });

        $quote = $this->quoteWith('pay1', 'posted');
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getGrandTotal')->willReturn(288.83);
        $quote->method('getBaseGrandTotal')->willReturn(288.83);
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
        $snapshot->stamp($quote, [
            'grand_total' => '343.83',
            'base_grand_total' => '343.83',
            'discount_amount' => '-14.31',
            'address' => [
                'subtotal' => '303.14',
            ],
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
        $this->assertSame('303.14', $payload['address']['subtotal']);
    }

    public function testPaidBagCopiesAddressBaseDiscount(): void
    {
        $address = $this->address();
        $address->method('getData')->willReturnCallback(function ($key) {
            $map = [
                'discount_amount' => -14.31,
                'base_discount_amount' => -14.31,
                'subtotal' => 303.14,
                'base_subtotal' => 303.14,
                'subtotal_with_discount' => 288.83,
                'base_subtotal_with_discount' => 288.83,
                'tax_amount' => 0,
                'base_tax_amount' => 0,
                'shipping_incl_tax' => 55.0,
                'base_shipping_incl_tax' => 55.0,
                'shipping_tax_amount' => 0,
                'base_shipping_tax_amount' => 0,
            ];
            return $map[$key] ?? null;
        });

        $quoteShipping = $this->getMockBuilder(QuoteShipping::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['snapshot'])
            ->getMock();
        $quoteShipping->method('snapshot')->willReturn(null);

        $quote = $this->quoteWith('pay1', 'posted');
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getGrandTotal')->willReturn(343.83);
        $quote->method('getBaseGrandTotal')->willReturn(343.83);
        $quote->method('getShippingAddress')->willReturn($address);

        $bag = (new CaptureSnapshot(
            $quoteShipping,
            $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass()
        ))->paidBag($quote);

        $this->assertSame('-14.31', $bag['address']['base_discount_amount']);
        $this->assertSame('303.14', $bag['address']['subtotal']);
        $this->assertSame('0', $bag['address']['tax_amount']);
        $this->assertSame('0', $bag['address']['base_tax_amount']);
        $this->assertSame('0', $bag['address']['shipping_tax_amount']);
        $this->assertSame('0', $bag['address']['base_shipping_tax_amount']);
    }

    public function testEnsureStampedSkipsUncapturedQuotes(): void
    {
        $quote = $this->quoteWith('pay1', null);
        $quote->expects($this->never())->method('setData');

        $this->makeSnapshot()->ensureStamped($quote);
    }

    public function testStampCheckoutIntentRecordsTheCartBeforePayment(): void
    {
        $data = [];
        $quote = $this->statefulQuote($data, 164.96);
        $updates = [];
        $quote->method('getResource')->willReturn($this->unpaidRowResource(1, false, $updates));

        $this->assertTrue($this->makeSnapshot()->stampCheckoutIntent($quote));

        $written = (string)$data[CaptureSnapshot::QUOTE_FIELD];
        $payload = json_decode($written, true);
        $this->assertSame('164.96', $payload['grand_total']);
        $this->assertSame(CaptureSnapshot::SOURCE_CHECKOUT, $payload['source']);
        // Only the snapshot column, and only on a row with no payment id yet.
        $this->assertSame([[
            'quote',
            [CaptureSnapshot::QUOTE_FIELD => $written],
            self::UNPAID_ROW,
        ]], $updates);
    }

    public function testStampCheckoutIntentDoesNotOverwriteAReportedPayment(): void
    {
        $quote = $this->quoteWith('pay1', 'paid');
        $quote->expects($this->never())->method('setData');
        $updates = [];
        $quote->method('getResource')->willReturn($this->unpaidRowResource(1, false, $updates));

        $this->assertFalse($this->makeSnapshot()->stampCheckoutIntent($quote));
        $this->assertSame([], $updates);
    }

    /**
     * Another request recorded a payment after this quote was loaded, so the
     * row refuses the write. Memory takes the row's snapshot rather than keep
     * a cart that was never charged.
     */
    public function testStampCheckoutIntentYieldsToAPaymentRecordedSinceLoad(): void
    {
        $paidSnapshot = '{"hash":"' . str_repeat('cd', 32) . '","source":"checkout","grand_total":"164.96"}';
        $data = [];
        $quote = $this->statefulQuote($data, 329.92);
        $quote->method('getResource')->willReturn($this->unpaidRowResource(0, $paidSnapshot));

        $this->assertFalse($this->makeSnapshot()->stampCheckoutIntent($quote));

        $this->assertSame($paidSnapshot, $data[CaptureSnapshot::QUOTE_FIELD]);
    }

    /**
     * MySQL counts a row whose value did not change as not updated. The same
     * cart opened twice without a clear in between is still written.
     */
    public function testStampCheckoutIntentTreatsAnUnchangedRowAsWritten(): void
    {
        $data = [];
        $quote = $this->statefulQuote($data, 164.96);
        $quote->method('getResource')->willReturn($this->unpaidRowResource(0, function () use (&$data) {
            return $data[CaptureSnapshot::QUOTE_FIELD] ?? false;
        }));

        $this->assertTrue($this->makeSnapshot()->stampCheckoutIntent($quote));

        $payload = json_decode((string)$data[CaptureSnapshot::QUOTE_FIELD], true);
        $this->assertSame('164.96', $payload['grand_total']);
    }

    public function testClearCheckoutIntentDropsTheSnapshotOfAnUnpaidRow(): void
    {
        $quote = $this->quoteWith(null, null);
        $updates = [];
        $quote->method('getResource')->willReturn($this->unpaidRowResource(1, false, $updates));
        $quote->expects($this->once())->method('setData')->with(CaptureSnapshot::QUOTE_FIELD, null);

        $this->makeSnapshot()->clearCheckoutIntent($quote);

        $this->assertSame([[
            'quote',
            [CaptureSnapshot::QUOTE_FIELD => null],
            self::UNPAID_ROW,
        ]], $updates);
    }

    /**
     * A quote with a payment id keeps its snapshot: it records what was charged.
     */
    public function testClearCheckoutIntentLeavesAQuoteWithAPaymentAlone(): void
    {
        $quote = $this->quoteWith('pay1', 'processing');
        $updates = [];
        $quote->method('getResource')->willReturn($this->unpaidRowResource(1, false, $updates));
        $quote->expects($this->never())->method('setData');

        $this->makeSnapshot()->clearCheckoutIntent($quote);

        $this->assertSame([], $updates);
    }

    /**
     * Paystand's webhook can record the payment before the browser reports it. The
     * payment save names that payment, and a quote holding it is still cleared, on
     * the row as well as in memory (PROD-16503: staging refused a correctly paid cart).
     */
    public function testClearCheckoutIntentClearsAQuoteHoldingTheReportedPayment(): void
    {
        $quote = $this->quoteWith('pay1', 'processing');
        $updates = [];
        $quote->method('getResource')->willReturn($this->unpaidRowResource(1, false, $updates));
        $quote->expects($this->once())->method('setData')->with(CaptureSnapshot::QUOTE_FIELD, null);

        $this->makeSnapshot()->clearCheckoutIntent($quote, 'pay1');

        $this->assertSame([[
            'quote',
            [CaptureSnapshot::QUOTE_FIELD => null],
            [
                'entity_id = ?' => 770001,
                "paystand_payment_id IS NULL OR paystand_payment_id = '' OR paystand_payment_id = ?" => 'pay1',
            ],
        ]], $updates);
    }

    public function testClearCheckoutIntentLeavesAQuoteHoldingAnotherPaymentAlone(): void
    {
        $quote = $this->quoteWith('pay1', 'processing');
        $updates = [];
        $quote->method('getResource')->willReturn($this->unpaidRowResource(1, false, $updates));
        $quote->expects($this->never())->method('setData');

        $this->makeSnapshot()->clearCheckoutIntent($quote, 'pay2');

        $this->assertSame([], $updates);
    }

    /**
     * The webhook rescue's copy was loaded before its wait. Checkout may have
     * restamped the cart since; the row's snapshot is the one to judge by and keep.
     */
    public function testAdoptPersistedSnapshotTakesTheRowsSnapshot(): void
    {
        $quote = $this->quoteWith('pay1', 'posted');
        $quote->method('getResource')->willReturn($this->unpaidRowResource(0, '{"hash":"row"}'));
        $quote->expects($this->once())->method('setData')->with(CaptureSnapshot::QUOTE_FIELD, '{"hash":"row"}');

        $this->makeSnapshot()->adoptPersistedSnapshot($quote);
    }

    /** Checkout dropped the snapshot and had none to stamp (a bank payment still processing). */
    public function testAdoptPersistedSnapshotClearsWhenTheRowHasNone(): void
    {
        $quote = $this->quoteWith('pay1', 'processing');
        $quote->method('getResource')->willReturn($this->unpaidRowResource(0, false));
        $quote->expects($this->once())->method('setData')->with(CaptureSnapshot::QUOTE_FIELD, null);

        $this->makeSnapshot()->adoptPersistedSnapshot($quote);
    }

    /** An unreadable row is not evidence the snapshot changed, so the loaded one stays. */
    public function testAdoptPersistedSnapshotKeepsTheLoadedOneWhenTheRowCannotBeRead(): void
    {
        $quote = $this->quoteWith('pay1', 'posted');
        $quote->method('getResource')->willThrowException(new \RuntimeException('db down'));
        $quote->expects($this->never())->method('setData');
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $logger->expects($this->once())->method('error')
            ->with($this->stringContains('keeping the loaded one'));

        (new CaptureSnapshot(
            $this->getMockBuilder(QuoteShipping::class)->disableOriginalConstructor()->getMock(),
            $logger
        ))->adoptPersistedSnapshot($quote);
    }

    /**
     * A failed clear must not stop the window opening. It is logged, and the
     * stamp that follows it still overwrites the old snapshot.
     */
    public function testClearCheckoutIntentLogsAWriteFailureAndReturns(): void
    {
        $quote = $this->quoteWith(null, null);
        $connection = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['update'])
            ->getMock();
        $connection->method('update')->willThrowException(new \RuntimeException('db down'));
        $resource = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getConnection', 'getMainTable'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('quote');
        $quote->method('getResource')->willReturn($resource);
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $logger->expects($this->once())->method('error')
            ->with($this->stringContains('could not clear checkout intent for quote 770001'));

        $snapshot = new CaptureSnapshot(
            $this->getMockBuilder(QuoteShipping::class)->disableOriginalConstructor()->getMock(),
            $logger
        );
        $snapshot->clearCheckoutIntent($quote);
    }

    /**
     * ACH reaches the webhook rescue as processing and the rescue places the
     * order anyway. Stamping there is what lets the pin book that order at the
     * amount charged.
     */
    public function testEnsureStampedStampsAProcessingRescue(): void
    {
        $quote = $this->quoteWith('pay1', 'processing');
        $quote->method('getResource')->willReturn($this->emptyPersistedSnapshotResource());
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('isVirtual')->willReturn(true);
        $quote->method('getBillingAddress')->willReturn(null);
        $quote->method('getGrandTotal')->willReturn(343.83);
        $quote->method('getBaseGrandTotal')->willReturn(343.83);

        $written = null;
        $quote->expects($this->once())->method('setData')
            ->willReturnCallback(function ($key, $value = null) use (&$written, $quote) {
                $written = [$key, $value];
                return $quote;
            });

        $this->makeSnapshot()->ensureStamped(
            $quote,
            null,
            CaptureSnapshot::SOURCE_RESCUE
        );

        $this->assertSame(CaptureSnapshot::QUOTE_FIELD, $written[0]);
        $payload = json_decode($written[1], true);
        $this->assertSame('343.83', $payload['grand_total']);
        $this->assertSame(CaptureSnapshot::SOURCE_RESCUE, $payload['source']);
    }

    /**
     * failed never becomes an order, so it must not leave a snapshot behind.
     */
    public function testEnsureStampedSkipsAStatusThatNeverPlacesAnOrder(): void
    {
        $quote = $this->quoteWith('pay1', 'failed');
        $quote->expects($this->never())->method('setData');

        $this->makeSnapshot()->ensureStamped($quote);
    }

    public function testResolveGuardModeReadsStoreScopeAndLogsUnknown(): void
    {
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $logger->expects($this->once())->method('warning')->with($this->callback(function ($message) {
            return is_string($message) && str_contains($message, 'unknown captured_cart_guard');
        }));
        $config = $this->getMockBuilder(ScopeConfigInterface::class)->getMockForAbstractClass();
        $config->expects($this->once())->method('getValue')->with(
            CapturedQuoteSubmit::CONFIG_PATH,
            ScopeInterface::SCOPE_STORE
        )->willReturn('maybe');

        $mode = (new CaptureSnapshot(
            $this->getMockBuilder(QuoteShipping::class)->disableOriginalConstructor()->getMock(),
            $logger
        ))->resolveGuardMode($config);

        $this->assertSame(CapturedQuoteSubmit::MODE_LOG_ONLY, $mode);
    }

    public function testCopyPaidRecollectAndStampSetsStampingDuringRecollect(): void
    {
        $quoteShipping = $this->getMockBuilder(QuoteShipping::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['snapshot', 'recollectPreservingShipping'])
            ->getMock();
        $quoteShipping->method('snapshot')->willReturn(null);
        $snapshot = new CaptureSnapshot(
            $quoteShipping,
            $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass()
        );
        $quoteShipping->expects($this->once())->method('recollectPreservingShipping')
            ->willReturnCallback(function () use ($snapshot) {
                $this->assertTrue($snapshot->isStamping());
                return ['before' => '', 'restored' => false, 'retryFailed' => false];
            });

        $quote = $this->quoteWith('pay1', 'posted');
        $quote->method('getResource')->willReturn($this->emptyPersistedSnapshotResource());
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('isVirtual')->willReturn(true);
        $quote->method('getGrandTotal')->willReturn(10);
        $quote->method('getBaseGrandTotal')->willReturn(10);
        $quote->method('getBillingAddress')->willReturn(null);

        $this->assertFalse($snapshot->isStamping());
        $snapshot->copyPaidRecollectAndStamp($quote, 'savepaymentdata');
        $this->assertFalse($snapshot->isStamping());
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
        $quote->method('getResource')->willReturn($this->emptyPersistedSnapshotResource());
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('isVirtual')->willReturn(true);
        $quote->method('getGrandTotal')->willReturn(10);
        $quote->method('getBaseGrandTotal')->willReturn(10);
        $quote->method('getBillingAddress')->willReturn(null);

        (new CaptureSnapshot($quoteShipping, $logger))->ensureStamped($quote);
    }

    /**
     * SavePaymentData and webhook must copy paid money before recollect.
     * Recollect first would stamp a rewritten grand_total.
     */
    public function testCopyPaidRecollectAndStampCopiesBeforeRecollect(): void
    {
        $sequence = [];
        $afterRecollect = false;
        $written = null;
        $quoteShipping = $this->getMockBuilder(QuoteShipping::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['snapshot', 'recollectPreservingShipping'])
            ->getMock();
        $quoteShipping->method('snapshot')->willReturnCallback(function () use (&$sequence) {
            $sequence[] = 'paidBag';
            return [
                'method' => 'fedex_FEDEX_GROUND',
                'amount' => 55.0,
                'baseAmount' => 55.0,
                'description' => 'FedEx Ground',
                'rate' => ['code' => 'fedex_FEDEX_GROUND', 'price' => 55.0],
            ];
        });
        $quoteShipping->method('recollectPreservingShipping')
            ->willReturnCallback(function ($quote, $context) use (&$sequence, &$afterRecollect) {
                $sequence[] = 'recollect';
                $this->assertSame('savepaymentdata', $context);
                $afterRecollect = true;
                return ['before' => '', 'restored' => false, 'retryFailed' => false];
            });

        // Dedicated mock: quoteWith() already stubs getData, so a second
        // getData stub would replace capture-field returns and skip stamping.
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getData', 'setData', 'getAllVisibleItems', 'isVirtual',
                'getShippingAddress', 'getBillingAddress', 'getId', 'getResource',
            ])
            ->addMethods(['getGrandTotal', 'getBaseGrandTotal'])
            ->getMock();
        $quote->method('getId')->willReturn(770001);
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('isVirtual')->willReturn(true);
        $quote->method('getGrandTotal')->willReturnCallback(function () use (&$afterRecollect) {
            return $afterRecollect ? 288.83 : 343.83;
        });
        $quote->method('getBaseGrandTotal')->willReturnCallback(function () use (&$afterRecollect) {
            return $afterRecollect ? 288.83 : 343.83;
        });
        $quote->method('getBillingAddress')->willReturn(null);
        $quote->method('getData')->willReturnCallback(function ($key) {
            if ($key === 'paystand_payment_id') {
                return 'pay1';
            }
            if ($key === 'paystand_capture_status') {
                return 'posted';
            }
            return null;
        });
        $quote->method('setData')->willReturnCallback(function ($key, $value) use (&$sequence, &$written, $quote) {
            if ($key === CaptureSnapshot::QUOTE_FIELD) {
                $sequence[] = 'stamp';
                $written = $value;
            }
            return $quote;
        });

        $snapshot = new CaptureSnapshot(
            $quoteShipping,
            $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass()
        );
        $snapshot->copyPaidRecollectAndStamp($quote, 'savepaymentdata');

        $this->assertSame(['paidBag', 'recollect', 'stamp'], $sequence);
        $payload = json_decode((string)$written, true);
        $this->assertSame('343.83', $payload['grand_total']);
    }

    /**
     * One item whose base and display row totals differ, as on any store whose
     * presentation currency is not its base currency.
     */
    /**
     * Any reported payment id counts, whatever its status: a bank payment reaches
     * checkout still processing, with no capture status recorded.
     */
    public function testHasReportedPaymentNeedsOnlyAPaymentId(): void
    {
        $snapshot = $this->makeSnapshot();

        $this->assertTrue($snapshot->hasReportedPayment($this->quoteWith('pay1', null)));
        $this->assertTrue($snapshot->hasReportedPayment($this->quoteWith('pay1', 'processing')));
        $this->assertFalse($snapshot->hasReportedPayment($this->quoteWith(null, 'posted')));
        $this->assertFalse($snapshot->hasReportedPayment($this->quoteWith('', null)));
        $this->assertFalse($snapshot->hasReportedPayment(null));
    }

    private function quoteForHash(float $baseRowTotal, float $rowTotal): Quote
    {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSku', 'getQty'])
            ->addMethods(['getRowTotal', 'getBaseRowTotal'])
            ->getMock();
        $item->method('getSku')->willReturn('SKU');
        $item->method('getQty')->willReturn('1');
        $item->method('getRowTotal')->willReturn($rowTotal);
        $item->method('getBaseRowTotal')->willReturn($baseRowTotal);

        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCity', 'getPostcode', 'getCountryId'])
            ->getMock();
        $address->method('getCity')->willReturn('Santa Cruz');
        $address->method('getPostcode')->willReturn('95060');
        $address->method('getCountryId')->willReturn('US');

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems', 'isVirtual', 'getShippingAddress'])
            ->getMock();
        $quote->method('getAllVisibleItems')->willReturn([$item]);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getShippingAddress')->willReturn($address);
        return $quote;
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
                'getShippingAddress', 'getBillingAddress', 'getId', 'getResource',
            ])
            ->addMethods(['getGrandTotal', 'getBaseGrandTotal'])
            ->getMock();
        $quote->method('getId')->willReturn(770001);
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

    /**
     * DB has no snapshot. getResource unstubbed logs a re-read error;
     * these stamp-failure tests need one error from stamp only.
     *
     * @return object
     */
    private function emptyPersistedSnapshotResource()
    {
        $connection = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['select', 'fetchOne'])
            ->getMock();
        $select = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['from', 'where'])
            ->getMock();
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn(false);
        $resource = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getConnection', 'getMainTable'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('quote');
        return $resource;
    }

    /**
     * A virtual, unpaid quote whose getData/setData share $data, so a value
     * the helper writes is the value it reads back.
     *
     * @param array<string, mixed> $data
     */
    private function statefulQuote(array &$data, float $grandTotal): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getData', 'setData', 'getAllVisibleItems', 'isVirtual',
                'getShippingAddress', 'getBillingAddress', 'getId', 'getResource',
            ])
            ->addMethods(['getGrandTotal', 'getBaseGrandTotal'])
            ->getMock();
        $quote->method('getId')->willReturn(770001);
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('isVirtual')->willReturn(true);
        $quote->method('getBillingAddress')->willReturn(null);
        $quote->method('getGrandTotal')->willReturn($grandTotal);
        $quote->method('getBaseGrandTotal')->willReturn($grandTotal);
        $quote->method('getData')->willReturnCallback(function ($key) use (&$data) {
            return $data[$key] ?? null;
        });
        $quote->method('setData')->willReturnCallback(function ($key, $value = null) use (&$data, $quote) {
            $data[$key] = $value;
            return $quote;
        });
        return $quote;
    }

    /**
     * A quote row the snapshot helper writes to. Records every update() and
     * reports $changed rows. Re-reads return $persisted (a value or a callable).
     *
     * @param mixed $persisted
     * @param array<int, array{0:string,1:array,2:array}>|null $updates
     * @return object
     */
    private function unpaidRowResource(int $changed, $persisted = false, ?array &$updates = null)
    {
        $connection = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['update', 'select', 'fetchOne'])
            ->getMock();
        $connection->method('update')->willReturnCallback(
            function ($table, $bind, $where) use ($changed, &$updates) {
                $updates[] = [$table, $bind, $where];
                return $changed;
            }
        );
        $select = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['from', 'where'])
            ->getMock();
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturnCallback(function () use ($persisted) {
            return is_callable($persisted) ? $persisted() : $persisted;
        });
        $resource = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getConnection', 'getMainTable'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('quote');
        return $resource;
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
            ->onlyMethods(['getStreet', 'getCity', 'getPostcode', 'getCountryId', 'getData'])
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
