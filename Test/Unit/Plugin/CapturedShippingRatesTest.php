<?php

namespace PayStand\PayStandMagento\Test\Unit\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment;
use Magento\Store\Model\ScopeInterface;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Quote\Model\Quote\Item;
use PayStand\PayStandMagento\Helper\CaptureSnapshot;
use PayStand\PayStandMagento\Helper\QuoteShipping;
use PayStand\PayStandMagento\Plugin\CapturedQuoteSubmit;
use PayStand\PayStandMagento\Plugin\CapturedShippingRates;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * After Paystand reports a payment, a collect must not ask the carriers again
 * while the cart still matches the snapshot: the stored row for the selected
 * method is the rate that was paid.
 */
class CapturedShippingRatesTest extends TestCase
{
    private const PAYMENT_ID = 'nlvsnvr0ska9i7ugvoab9917';
    private const METHOD = 'fedex_FEDEX_GROUND';

    /** @var int */
    private $proceedCalls = 0;

    private function makePlugin(
        string $mode = CapturedQuoteSubmit::MODE_LOG_ONLY,
        ?LoggerInterface $logger = null,
        ?CaptureSnapshot $snapshot = null,
        ?ScopeConfigInterface $config = null
    ): CapturedShippingRates {
        if (!$config) {
            $config = $this->getMockBuilder(ScopeConfigInterface::class)->getMockForAbstractClass();
            $config->method('getValue')->willReturn($mode);
        }

        if (!$snapshot) {
            $snapshot = new CaptureSnapshot(
                $this->getMockBuilder(QuoteShipping::class)->disableOriginalConstructor()->getMock(),
                $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass()
            );
        }

        return new CapturedShippingRates(
            $snapshot,
            $config,
            $logger ?: $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass()
        );
    }

    private function proceedFor(Address $address): callable
    {
        return function () use ($address) {
            $this->proceedCalls++;
            return $address;
        };
    }

    /**
     * @return string JSON snapshot whose hash matches the cart built by addressOn()
     */
    private function matchingSnapshot(string $qty = '1', string $postcode = '95060'): string
    {
        return json_encode([
            'hash' => CaptureSnapshot::hashParts(
                [['sku' => 'SKU', 'qty' => $qty]],
                ['city' => 'Santa Cruz', 'postcode' => $postcode, 'country' => 'US']
            ),
            'grand_total' => '113.14',
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return Quote&MockObject
     */
    private function quoteWith(
        ?string $paymentId,
        ?string $captureStatus,
        ?string $snapshotJson = null,
        bool $multiShipping = false,
        string $methodCode = 'paystandmagento',
        $storeId = 1
    ): Quote {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSku', 'getQty'])
            ->getMock();
        $item->method('getSku')->willReturn('SKU');
        $item->method('getQty')->willReturn('1');

        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMethod'])
            ->getMock();
        $payment->method('getMethod')->willReturn($methodCode);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getData', 'getId', 'getAllVisibleItems', 'isVirtual', 'getShippingAddress',
                'getPayment', 'getStoreId',
            ])
            ->addMethods(['getIsMultiShipping'])
            ->getMock();
        $quote->method('getId')->willReturn(770001);
        $quote->method('getIsMultiShipping')->willReturn($multiShipping);
        $quote->method('getPayment')->willReturn($payment);
        $quote->method('getStoreId')->willReturn($storeId);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getAllVisibleItems')->willReturn([$item]);
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
     * @param string[] $storedRateCodes
     * @return Address&MockObject
     */
    private function addressOn(
        ?Quote $quote,
        array $storedRateCodes = [self::METHOD],
        string $method = self::METHOD,
        string $type = Address::ADDRESS_TYPE_SHIPPING,
        bool $collectFlag = true
    ): Address {
        $rates = [];
        foreach ($storedRateCodes as $code) {
            $rate = $this->getMockBuilder(Rate::class)
                ->disableOriginalConstructor()
                ->addMethods(['getCode'])
                ->getMock();
            $rate->method('getCode')->willReturn($code);
            $rates[] = $rate;
        }

        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getQuote', 'getAllShippingRates', 'getShippingMethod',
                'getCity', 'getPostcode', 'getCountryId',
            ])
            ->addMethods(['getAddressType', 'getCollectShippingRates', 'setCollectShippingRates', 'getQuoteId'])
            ->getMock();
        $address->method('getQuote')->willReturn($quote);
        $address->method('getAllShippingRates')->willReturn($rates);
        $address->method('getShippingMethod')->willReturn($method);
        $address->method('getCity')->willReturn('Santa Cruz');
        $address->method('getPostcode')->willReturn('95060');
        $address->method('getCountryId')->willReturn('US');
        $address->method('getAddressType')->willReturn($type);
        $address->method('getCollectShippingRates')->willReturn($collectFlag);
        $address->method('getQuoteId')->willReturn(770001);

        if ($quote instanceof MockObject) {
            $quote->method('getShippingAddress')->willReturn($address);
        }
        return $address;
    }

    private function assertAsksTheCarriers(Address $address, ?CapturedShippingRates $plugin = null): void
    {
        $address->expects($this->never())->method('setCollectShippingRates');

        $result = ($plugin ?? $this->makePlugin())->aroundCollectShippingRates($address, $this->proceedFor($address));

        $this->assertSame($address, $result);
        $this->assertSame(1, $this->proceedCalls, 'Magento must request the rates');
    }

    public static function placeOrderStatuses(): array
    {
        return [
            'posted' => ['posted'],
            'paid' => ['paid'],
            'processing (ACH)' => ['processing'],
        ];
    }

    /**
     * @dataProvider placeOrderStatuses
     */
    public function testKeepsThePaidRateWhileTheCartMatchesTheSnapshot(string $status): void
    {
        $address = $this->addressOn(
            $this->quoteWith(self::PAYMENT_ID, $status, $this->matchingSnapshot()),
            ['ups_GND', self::METHOD]
        );
        $address->expects($this->once())->method('setCollectShippingRates')->with(false);

        $result = $this->makePlugin()->aroundCollectShippingRates($address, $this->proceedFor($address));

        $this->assertSame($address, $result);
        $this->assertSame(0, $this->proceedCalls, 'The carriers must not be asked again');
    }

    public static function unusableStamps(): array
    {
        return [
            'no snapshot' => [null],
            'snapshot without a hash' => ['{"grand_total":"113.14"}'],
            'corrupt snapshot' => ['{not json'],
        ];
    }

    /**
     * A missing stamp cannot prove the cart is unchanged. Ask the carriers,
     * as 3.7.4 did. The stamp recollect is the exception: the snapshot is not
     * written yet, and the stored row is the rate being stamped.
     *
     * @dataProvider unusableStamps
     */
    public function testAsksTheCarriersWhenNoStampCanProveTheCartChanged(?string $snapshotJson): void
    {
        $this->assertAsksTheCarriers($this->addressOn(
            $this->quoteWith(self::PAYMENT_ID, 'posted', $snapshotJson)
        ));
    }

    public function testKeepsThePaidRateWhileTheSnapshotIsBeingStamped(): void
    {
        $snapshot = new CaptureSnapshot(
            $this->getMockBuilder(QuoteShipping::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass()
        );
        $depth = new \ReflectionProperty(CaptureSnapshot::class, 'stampDepth');
        $depth->setAccessible(true);
        $depth->setValue($snapshot, 1);

        $address = $this->addressOn($this->quoteWith(self::PAYMENT_ID, 'posted', null));
        $address->expects($this->once())->method('setCollectShippingRates')->with(false);

        $this->makePlugin(CapturedQuoteSubmit::MODE_LOG_ONLY, null, $snapshot)
            ->aroundCollectShippingRates($address, $this->proceedFor($address));

        $this->assertSame(0, $this->proceedCalls);
    }

    public function testAsksTheCarriersForADifferentPaymentMethod(): void
    {
        $this->assertAsksTheCarriers($this->addressOn(
            $this->quoteWith(self::PAYMENT_ID, 'posted', $this->matchingSnapshot(), false, 'checkmo')
        ));
    }

    public function testReadsTheGuardModeAtTheQuoteStore(): void
    {
        $config = $this->getMockBuilder(ScopeConfigInterface::class)->getMockForAbstractClass();
        $config->expects($this->once())->method('getValue')->with(
            CapturedQuoteSubmit::CONFIG_PATH,
            ScopeInterface::SCOPE_STORE,
            7
        )->willReturn(CapturedQuoteSubmit::MODE_OFF);

        $this->assertAsksTheCarriers(
            $this->addressOn($this->quoteWith(
                self::PAYMENT_ID,
                'posted',
                $this->matchingSnapshot(),
                false,
                'paystandmagento',
                7
            )),
            $this->makePlugin(CapturedQuoteSubmit::MODE_LOG_ONLY, null, null, $config)
        );
    }

    public static function changedCarts(): array
    {
        return [
            'quantity changed' => ['2', '95060'],
            'destination changed' => ['1', '10001'],
        ];
    }

    /**
     * @dataProvider changedCarts
     */
    public function testAsksTheCarriersOnceTheCartNoLongerMatchesTheSnapshot(string $qty, string $postcode): void
    {
        $this->assertAsksTheCarriers($this->addressOn(
            $this->quoteWith(self::PAYMENT_ID, 'posted', $this->matchingSnapshot($qty, $postcode))
        ));
    }

    public function testAsksTheCarriersBeforePaystandReportsAPayment(): void
    {
        $this->assertAsksTheCarriers($this->addressOn($this->quoteWith(null, null)));
    }

    public function testAsksTheCarriersForAFailedPayment(): void
    {
        $this->assertAsksTheCarriers($this->addressOn($this->quoteWith(self::PAYMENT_ID, 'failed')));
    }

    public function testAsksTheCarriersWhenNoStoredRowHasTheSelectedMethod(): void
    {
        $this->assertAsksTheCarriers($this->addressOn(
            $this->quoteWith(self::PAYMENT_ID, 'posted', $this->matchingSnapshot()),
            ['ups_GND']
        ));
    }

    public function testAsksTheCarriersWhenNoMethodIsSelected(): void
    {
        $this->assertAsksTheCarriers($this->addressOn(
            $this->quoteWith(self::PAYMENT_ID, 'posted', $this->matchingSnapshot()),
            [self::METHOD],
            ''
        ));
    }

    public function testLeavesTheBillingAddressToMagento(): void
    {
        $this->assertAsksTheCarriers($this->addressOn(
            $this->quoteWith(self::PAYMENT_ID, 'posted', $this->matchingSnapshot()),
            [self::METHOD],
            self::METHOD,
            Address::ADDRESS_TYPE_BILLING
        ));
    }

    public function testLeavesACollectWithoutTheRateFlagToMagento(): void
    {
        $this->assertAsksTheCarriers($this->addressOn(
            $this->quoteWith(self::PAYMENT_ID, 'posted', $this->matchingSnapshot()),
            [self::METHOD],
            self::METHOD,
            Address::ADDRESS_TYPE_SHIPPING,
            false
        ));
    }

    public function testGuardOffAsksTheCarriers(): void
    {
        $this->assertAsksTheCarriers(
            $this->addressOn($this->quoteWith(self::PAYMENT_ID, 'posted', $this->matchingSnapshot())),
            $this->makePlugin(CapturedQuoteSubmit::MODE_OFF)
        );
    }

    public function testMultishippingAsksTheCarriers(): void
    {
        $this->assertAsksTheCarriers($this->addressOn(
            $this->quoteWith(self::PAYMENT_ID, 'posted', $this->matchingSnapshot(), true)
        ));
    }

    public function testAddressWithoutAQuoteAsksTheCarriers(): void
    {
        $this->assertAsksTheCarriers($this->addressOn(null));
    }

    public function testAFailingCheckFallsBackToMagento(): void
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuote'])
            ->addMethods(['getAddressType', 'getCollectShippingRates', 'setCollectShippingRates'])
            ->getMock();
        $address->method('getAddressType')->willReturn(Address::ADDRESS_TYPE_SHIPPING);
        $address->method('getCollectShippingRates')->willReturn(true);
        $address->method('getQuote')->willThrowException(new \RuntimeException('quote load failed'));

        $this->assertAsksTheCarriers($address);
    }

    public function testKeptRateIsLogged(): void
    {
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $logger->expects($this->once())
            ->method('debug')
            ->with($this->logicalAnd(
                $this->stringContains('PAYSTAND-CAPTURED-RATES'),
                $this->stringContains(self::METHOD),
                $this->stringContains('770001')
            ));

        $address = $this->addressOn($this->quoteWith(self::PAYMENT_ID, 'posted', $this->matchingSnapshot()));
        $this->makePlugin(CapturedQuoteSubmit::MODE_LOG_ONLY, $logger)
            ->aroundCollectShippingRates($address, $this->proceedFor($address));
    }
}
