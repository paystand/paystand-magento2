<?php

namespace PayStand\PayStandMagento\Test\Unit\Controller\Checkout;

use PayStand\PayStandMagento\Controller\Checkout\GetQuoteData;
use PayStand\PayStandMagento\Helper\CaptureSnapshot;
use PayStand\PayStandMagento\Helper\QuoteShipping;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\RegionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Quote\Model\Quote\Address\RateFactory;

/**
 * Tests for GetQuoteData::execute(). QuoteShipping is real, not mocked, because
 * the order of snapshot, recollect and restore is what these tests check.
 */
class GetQuoteDataTest extends TestCase
{
    /** @var GetQuoteData|MockObject */
    private $controller;

    /** @var CheckoutSession|MockObject */
    private $checkoutSessionMock;

    /** @var CaptureSnapshot|MockObject */
    private $captureSnapshot;

    /** @var array|null */
    private $captured;

    /** @var AddressRepositoryInterface|MockObject */
    private $addressRepository;

    protected function setUp(): void
    {
        $this->captured = null;

        $jsonResultMock = $this->getMockBuilder(JsonResult::class)
            ->disableOriginalConstructor()
            ->getMock();
        $jsonResultMock->method('setHttpResponseCode')->willReturnSelf();
        $jsonResultMock->method('setData')->willReturnCallback(function ($data) use (&$jsonResultMock) {
            $this->captured = $data;
            return $jsonResultMock;
        });

        $jsonResultFactoryMock = $this->getMockBuilder(JsonFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $jsonResultFactoryMock->method('create')->willReturn($jsonResultMock);

        $this->checkoutSessionMock = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuote'])
            ->getMock();

        $customerSessionMock = $this->getMockBuilder(CustomerSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isLoggedIn', 'getCustomerId'])
            ->getMock();
        $customerSessionMock->method('isLoggedIn')->willReturn(false);

        $this->controller = $this->getMockBuilder(GetQuoteData::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $this->set('resultJsonFactory',  $jsonResultFactoryMock);
        $this->set('checkoutSession',    $this->checkoutSessionMock);
        $this->set('customerSession',    $customerSessionMock);
        $this->set('customerRepository', $this->getMockBuilder(CustomerRepositoryInterface::class)
            ->getMockForAbstractClass());
        $this->set('logger', $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass());
        $this->set('quoteShipping', new QuoteShipping(
            $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass(),
            $this->buildRateFactory()
        ));
        $this->captureSnapshot = $this->getMockBuilder(CaptureSnapshot::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['stampCheckoutIntent', 'clearCheckoutIntent'])
            ->getMock();
        $this->set('captureSnapshot', $this->captureSnapshot);
        $this->addressRepository = $this->getMockBuilder(AddressRepositoryInterface::class)
            ->getMockForAbstractClass();
        $this->set('addressRepository', $this->addressRepository);
    }

    /** @return RateFactory|MockObject */
    private function buildRateFactory()
    {
        $factory = $this->getMockBuilder(RateFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturnCallback(function () {
            $rate = $this->getMockBuilder(Rate::class)
                ->disableOriginalConstructor()
                ->addMethods([
                    'setCode', 'setCarrier', 'setCarrierTitle', 'setMethod', 'setMethodTitle', 'setPrice', 'getCode',
                ])
                ->getMock();
            $code = null;
            $rate->method('setCode')->willReturnCallback(function ($value) use ($rate, &$code) {
                $code = $value;
                return $rate;
            });
            foreach (['setCarrier', 'setCarrierTitle', 'setMethod', 'setMethodTitle', 'setPrice'] as $setter) {
                $rate->method($setter)->willReturnSelf();
            }
            $rate->method('getCode')->willReturnCallback(function () use (&$code) {
                return $code;
            });
            return $rate;
        });
        return $factory;
    }

    public function testRejectsRequestWithoutAnActiveQuote(): void
    {
        $this->checkoutSessionMock->method('getQuote')->willReturn(null);

        $this->controller->execute();

        $this->assertFalse($this->captured['success']);
        $this->assertSame('NO_ACTIVE_QUOTE', $this->captured['error']['code']);
    }

    public function testCollectsOnceWhenTheSelectionSurvives(): void
    {
        $address = $this->buildAddress('flatrate_flatrate', 5.00);
        $quote = $this->buildQuote($address, 38.49);
        $quote->expects($this->once())->method('collectTotals');
        $address->expects($this->never())->method('setShippingMethod');

        $this->checkoutSessionMock->method('getQuote')->willReturn($quote);

        $this->controller->execute();

        $this->assertTrue($this->captured['success']);
        $this->assertSame(38.49, $this->captured['quote']['grand_total']);
    }

    /**
     * Without collect_shipping_rates the rates are not requested again, and shipping collects at 0.
     */
    public function testRearmsRateCollectionWhenRestoringAfterAWipe(): void
    {
        // getShippingMethod() per call: chosen, wiped by the first collect, then restored.
        $address = $this->buildAddress('flatrate_flatrate', 5.00, [
            'flatrate_flatrate', // snapshot
            'flatrate_flatrate', // describe (before)
            '',                  // restore — wiped, so it puts the method back
            'flatrate_flatrate', // restore after the retry — nothing to do
            'flatrate_flatrate', // describe (after)
        ]);
        $quote = $this->buildQuote($address, 38.49);

        $address->expects($this->once())->method('setCollectShippingRates')->with(true);
        $address->expects($this->once())->method('setShippingMethod')->with('flatrate_flatrate');
        $quote->expects($this->exactly(2))->method('collectTotals');

        $this->checkoutSessionMock->method('getQuote')->willReturn($quote);

        $this->controller->execute();

        $this->assertTrue($this->captured['success']);
    }

    /**
     * This is the state the RESTORED-NO-RATE log line reports.
     */
    public function testRestoresAgainWhenTheRetryAlsoComesBackEmpty(): void
    {
        $address = $this->buildAddress('flatrate_flatrate', 5.00, [
            'flatrate_flatrate', // snapshot
            'flatrate_flatrate', // describe (before)
            '',                  // restore — wiped
            '',                  // restore after the retry — wiped again
            'flatrate_flatrate', // describe (after)
        ]);
        $quote = $this->buildQuote($address, 38.49);

        $address->expects($this->exactly(2))->method('setShippingMethod')->with('flatrate_flatrate');
        $quote->expects($this->exactly(2))->method('collectTotals');

        $this->checkoutSessionMock->method('getQuote')->willReturn($quote);

        $this->controller->execute();

        $this->assertTrue($this->captured['success']);
    }

    /**
     * Stamping first and clearing second would leave the window with no snapshot.
     */
    public function testClearsTheEarlierSnapshotBeforePricingAndStampsTheChargedCart(): void
    {
        $calls = [];
        $address = $this->buildAddress('flatrate_flatrate', 5.00);
        $quote = $this->buildQuote($address, 164.96);
        $quote->method('collectTotals')->willReturnCallback(function () use (&$calls, $quote) {
            $calls[] = 'collect';
            return $quote;
        });
        $this->checkoutSessionMock->method('getQuote')->willReturn($quote);
        $this->captureSnapshot->expects($this->once())
            ->method('clearCheckoutIntent')
            ->with($quote)
            ->willReturnCallback(function () use (&$calls) {
                $calls[] = 'clear';
            });
        $this->captureSnapshot->expects($this->once())
            ->method('stampCheckoutIntent')
            ->with($quote)
            ->willReturnCallback(function () use (&$calls) {
                $calls[] = 'stamp';
                return true;
            });

        $this->controller->execute();

        $this->assertTrue($this->captured['success']);
        $this->assertSame(164.96, $this->captured['quote']['grand_total']);
        $this->assertSame('clear', $calls[0]);
        $this->assertContains('collect', $calls);
        $this->assertSame('stamp', end($calls));
    }

    /**
     * With no snapshot on the row, the payment save stamps from the paid cart.
     */
    public function testStillReturnsQuoteDataWhenTheSnapshotIsNotWritten(): void
    {
        $address = $this->buildAddress('flatrate_flatrate', 5.00);
        $quote = $this->buildQuote($address, 164.96);
        $this->checkoutSessionMock->method('getQuote')->willReturn($quote);
        $this->captureSnapshot->method('stampCheckoutIntent')->willReturn(false);

        $this->controller->execute();

        $this->assertTrue($this->captured['success']);
        $this->assertSame(164.96, $this->captured['quote']['grand_total']);
    }

    public function testVirtualQuoteIsCollectedOnceAndNotRestored(): void
    {
        $quote = $this->buildQuote(null, 19.99, true);
        $quote->expects($this->once())->method('collectTotals');

        $this->checkoutSessionMock->method('getQuote')->willReturn($quote);

        $this->controller->execute();

        $this->assertTrue($this->captured['success']);
        $this->assertSame(19.99, $this->captured['quote']['grand_total']);
    }

    /**
     * Magento DI gives an optional argument its default, so it would always be null.
     */
    public function testAddressRepositoryIsARequiredConstructorArgument(): void
    {
        $parameter = null;
        foreach ((new \ReflectionMethod(GetQuoteData::class, '__construct'))->getParameters() as $candidate) {
            if ($candidate->getName() === 'addressRepository') {
                $parameter = $candidate;
            }
        }

        $this->assertNotNull($parameter);
        $this->assertFalse($parameter->isOptional());
        $this->assertFalse($parameter->allowsNull());
    }

    public function testRestoresTheSavedAddressOfTheQuoteCustomer(): void
    {
        $address = $this->buildChangedAddress(77);
        $address->expects($this->once())->method('setCity')->with('Brooklyn');
        $address->expects($this->once())->method('setRegionId')->with(43);
        $address->expects($this->once())->method('setRegion')->with('New York');
        $address->expects($this->once())->method('setPostcode')->with('11201');
        $address->expects($this->once())->method('setCountryId')->with('US');
        $address->expects($this->once())->method('setStreet')->with(['200 Court St']);
        $this->addressRepository->expects($this->once())->method('getById')->with(77)
            ->willReturn($this->buildSavedAddress(501));

        $this->restore($this->buildCustomerQuote(501, $address));
    }

    public function testDoesNotApplyAnotherCustomersAddress(): void
    {
        $address = $this->buildChangedAddress(77);
        $this->expectNoAddressWrites($address);
        $this->addressRepository->method('getById')->willReturn($this->buildSavedAddress(999));

        $this->restore($this->buildCustomerQuote(501, $address));
    }

    public function testDoesNotRestoreAGuestQuote(): void
    {
        $address = $this->buildChangedAddress(77);
        $this->expectNoAddressWrites($address);
        $this->addressRepository->expects($this->never())->method('getById');

        $this->restore($this->buildCustomerQuote(null, $address));
    }

    /** @param Quote|MockObject $quote */
    private function restore($quote): void
    {
        $method = new \ReflectionMethod(GetQuoteData::class, 'restoreOwnedCustomerAddress');
        $method->setAccessible(true);
        $method->invoke($this->controller, $quote);
    }

    /**
     * @param int|null $customerId
     * @param Address|MockObject $shippingAddress
     * @return Quote|MockObject
     */
    private function buildCustomerQuote($customerId, $shippingAddress)
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBillingAddress', 'getShippingAddress'])
            ->addMethods(['getCustomerId'])
            ->getMock();
        $quote->method('getCustomerId')->willReturn($customerId);
        $quote->method('getBillingAddress')->willReturn(null);
        $quote->method('getShippingAddress')->willReturn($shippingAddress);
        return $quote;
    }

    /** @return Address|MockObject */
    private function buildChangedAddress(int $customerAddressId)
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getCustomerAddressId', 'getCity', 'getRegionId', 'getPostcode', 'getStreet',
                'setCity', 'setRegionId', 'setRegion', 'setPostcode', 'setCountryId', 'setStreet',
            ])
            ->getMock();
        $address->method('getCustomerAddressId')->willReturn($customerAddressId);
        $address->method('getCity')->willReturn('Boston');
        $address->method('getRegionId')->willReturn(32);
        $address->method('getPostcode')->willReturn('02110');
        $address->method('getStreet')->willReturn(['1 Main St']);
        return $address;
    }

    /** @return AddressInterface|MockObject */
    private function buildSavedAddress(int $customerId)
    {
        $region = $this->getMockBuilder(RegionInterface::class)->getMockForAbstractClass();
        $region->method('getRegion')->willReturn('New York');

        $saved = $this->getMockBuilder(AddressInterface::class)->getMockForAbstractClass();
        $saved->method('getCustomerId')->willReturn($customerId);
        $saved->method('getCity')->willReturn('Brooklyn');
        $saved->method('getRegionId')->willReturn(43);
        $saved->method('getRegion')->willReturn($region);
        $saved->method('getPostcode')->willReturn('11201');
        $saved->method('getCountryId')->willReturn('US');
        $saved->method('getStreet')->willReturn(['200 Court St']);
        return $saved;
    }

    /** @param Address|MockObject $address */
    private function expectNoAddressWrites($address): void
    {
        foreach (['setCity', 'setRegionId', 'setRegion', 'setPostcode', 'setCountryId', 'setStreet'] as $setter) {
            $address->expects($this->never())->method($setter);
        }
    }

    /**
     * @param Address|MockObject|null $address
     * @return Quote|MockObject
     */
    private function buildQuote($address, float $grandTotal, bool $isVirtual = false)
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getId', 'isVirtual', 'getShippingAddress', 'getBillingAddress',
                'collectTotals', 'getTotals', 'getItemsCount', 'getItemsQty',
            ])
            ->addMethods([
                'setTotalsCollectedFlag', 'getGrandTotal', 'getBaseGrandTotal',
                'getSubtotal', 'getQuoteCurrencyCode', 'getCustomerEmail',
            ])
            ->getMock();
        $quote->method('getId')->willReturn(4277928);
        $quote->method('isVirtual')->willReturn($isVirtual);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getBillingAddress')->willReturn(null);
        $quote->method('setTotalsCollectedFlag')->willReturnSelf();
        $quote->method('getTotals')->willReturn([]);
        $quote->method('getGrandTotal')->willReturn($grandTotal);
        $quote->method('getBaseGrandTotal')->willReturn($grandTotal);
        $quote->method('getSubtotal')->willReturn(29.99);
        $quote->method('getQuoteCurrencyCode')->willReturn('USD');
        $quote->method('getItemsCount')->willReturn(1);
        $quote->method('getItemsQty')->willReturn(1);
        return $quote;
    }

    /** @return Rate|MockObject */
    private function buildSelectedRate(string $method, float $amount)
    {
        $rate = $this->getMockBuilder(Rate::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCode', 'getCarrier', 'getCarrierTitle', 'getMethod', 'getMethodTitle', 'getPrice'])
            ->getMock();
        $rate->method('getCode')->willReturn($method);
        $rate->method('getCarrier')->willReturn('flatrate');
        $rate->method('getCarrierTitle')->willReturn('Flat Rate');
        $rate->method('getMethod')->willReturn('flatrate');
        $rate->method('getMethodTitle')->willReturn('Fixed');
        $rate->method('getPrice')->willReturn($amount);
        return $rate;
    }

    /**
     * @param array|null $methodSequence getShippingMethod() per consecutive call
     * @return Address|MockObject
     */
    private function buildAddress(string $method, float $amount, array $methodSequence = null)
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getShippingMethod', 'getAllShippingRates', 'getShippingRateByCode', 'addShippingRate',
                'setShippingAmount', 'setBaseShippingAmount', 'getData',
            ])
            ->addMethods([
                'setShippingMethod', 'setCollectShippingRates',
                'getShippingAmount', 'getBaseShippingAmount',
                'getShippingDescription', 'setShippingDescription',
            ])
            ->getMock();
        if ($methodSequence === null) {
            $address->method('getShippingMethod')->willReturn($method);
        } else {
            $address->method('getShippingMethod')->willReturnOnConsecutiveCalls(...$methodSequence);
        }
        $address->method('getShippingAmount')->willReturn($amount);
        $address->method('getBaseShippingAmount')->willReturn($amount);
        $address->method('getShippingDescription')->willReturn('Flat Rate - Fixed');
        $address->method('getAllShippingRates')->willReturn([]);
        // The rate row survives here. QuoteShippingTest covers dropped rates.
        $address->method('getShippingRateByCode')->willReturn($this->buildSelectedRate($method, $amount));
        $address->method('addShippingRate')->willReturnSelf();
        $address->method('getData')->willReturnCallback(function ($key = null) {
            $data = [
                'street'     => ['123 Main St'],
                'city'       => 'Santa Cruz',
                'telephone'  => '5551234567',
                'country_id' => 'US',
            ];
            return $data[$key] ?? null;
        });
        return $address;
    }

    /** @param mixed $value */
    private function set(string $name, $value): void
    {
        $class = new \ReflectionClass($this->controller);
        while ($class) {
            try {
                $prop = $class->getProperty($name);
                $prop->setAccessible(true);
                $prop->setValue($this->controller, $value);
                return;
            } catch (\ReflectionException $e) {
                $class = $class->getParentClass() ?: null;
            }
        }
        throw new \RuntimeException("Property '{$name}' not found on " . get_class($this->controller));
    }
}
