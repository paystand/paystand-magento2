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
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Quote\Model\Quote\Address\RateFactory;

/**
 * Unit tests for Controller\Checkout\GetQuoteData::execute().
 *
 * Covers the snapshot/recollect/restore bracket around the forced totals
 * recalculation. QuoteShipping itself is real here, not mocked, because the
 * sequencing between the two is what the tests are about.
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
    }

    /**
     * Builds rates the helper can write a restored selection onto. The setters are
     * magic data accessors on Rate, so they need addMethods().
     *
     * @return RateFactory|MockObject
     */
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

    /**
     * The common case: the recollect leaves the selection alone, so nothing is
     * restored and the quote is collected exactly once.
     */
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
     * When the recollect clears the method, the retry must re-arm
     * collect_shipping_rates. Without it the rates are never re-requested and
     * the restored method collects at an amount of 0.
     */
    public function testRearmsRateCollectionWhenRestoringAfterAWipe(): void
    {
        // Reads as chosen for the snapshot, empty once the first collectTotals()
        // has wiped it, then chosen again after the restore — the production
        // failure sequence as GetQuoteData walks it.
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
     * When the re-requested rates come back empty too, the method is restored a
     * second time — the state the RESTORED-NO-RATE breadcrumb reports.
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
     * The snapshot an earlier open left is dropped before the cart is priced, and
     * the cart this response hands the window is stamped after. Stamping first and
     * clearing second would leave the window with no snapshot at all.
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
     * A snapshot that could not be written must not stop the window opening.
     * With none on the row, the payment save stamps from the paid cart.
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

    /**
     * A virtual quote has nothing to snapshot, so the bracket must stay out of
     * the way rather than erroring on a missing shipping address.
     */
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
     * @param Address|MockObject|null $address
     * @param float $grandTotal
     * @param bool $isVirtual
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

    /**
     * Shipping amount/description accessors are magic data methods on Address,
     * so they need addMethods() rather than onlyMethods().
     *
     * @param string $method
     * @param float $amount
     * @param array|null $methodSequence getShippingMethod() per consecutive call
     * @return Address|MockObject
     */
    /**
     * The rate row the snapshot captures. Its getters are magic data accessors on
     * Rate, so they need addMethods().
     *
     * @param string $method
     * @param float $amount
     * @return Rate|MockObject
     */
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
        // The selected rate row survives here; these cases cover a cleared method,
        // and the dropped-rate cases live in QuoteShippingTest.
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

    /**
     * @param string $name
     * @param mixed $value
     */
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
