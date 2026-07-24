<?php

namespace PayStand\PayStandMagento\Test\Unit\Controller\Webhook;

use PayStand\PayStandMagento\Controller\Webhook\Paystand;
use PayStand\PayStandMagento\Model\Directpost;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Payment;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * Direct unit tests for Paystand::createOrderFromQuote() — the server-side
 * order-creation fallback that rescues a paid-but-orderless quote.
 *
 * Unlike PaystandTest (which stubs this method to test execute()'s routing),
 * these tests run the REAL method: only findOrder (DB) is stubbed, and the
 * lock manager, cart repository/management, and order repository are mocks.
 * Covered behaviours: locking, idempotent short-circuits, the
 * inactive-converted vs inactive-unconverted disambiguation, reactivation,
 * payment-method and guest-email backfill, and failure paths.
 */
class CreateOrderFromQuoteTest extends TestCase
{
    /** @var Paystand|MockObject */
    private $controller;

    /** @var LockManagerInterface|MockObject */
    private $lockManagerMock;

    /** @var CartRepositoryInterface|MockObject */
    private $cartRepositoryMock;

    /** @var CartManagementInterface|MockObject */
    private $cartManagementMock;

    /** @var OrderRepositoryInterface|MockObject */
    private $orderRepositoryMock;

    protected function setUp(): void
    {
        $this->lockManagerMock = $this->getMockBuilder(LockManagerInterface::class)
            ->getMockForAbstractClass();

        $this->cartRepositoryMock = $this->getMockBuilder(CartRepositoryInterface::class)
            ->getMockForAbstractClass();

        $this->cartManagementMock = $this->getMockBuilder(CartManagementInterface::class)
            ->getMockForAbstractClass();

        $this->orderRepositoryMock = $this->getMockBuilder(OrderRepositoryInterface::class)
            ->getMockForAbstractClass();

        // Keep createOrderFromQuote REAL; stub only the DB-backed findOrder.
        $this->controller = $this->getMockBuilder(Paystand::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOrder'])
            ->getMock();

        $this->set('_logger',          $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass());
        $this->set('lockManager',      $this->lockManagerMock);
        $this->set('cartRepository',   $this->cartRepositoryMock);
        $this->set('cartManagement',   $this->cartManagementMock);
        $this->set('_orderRepository', $this->orderRepositoryMock);
    }

    public function testReturnsNullWhenQuoteHasNoId(): void
    {
        $quote = $this->buildInitialQuote(0);
        $this->lockManagerMock->expects($this->never())->method('lock');

        $this->assertNull($this->invoke($quote));
    }

    public function testLockNotAcquiredFallsBackToSearchOnly(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->method('lock')->willReturn(false);

        $order = $this->buildOrder(7, 'W000000007');
        $this->controller->method('findOrder')->willReturn($order);
        $this->cartManagementMock->expects($this->never())->method('placeOrder');

        $this->assertSame($order, $this->invoke($quote));
    }

    public function testExistingOrderShortCircuitsWithoutPlacing(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->method('lock')->willReturn(true);
        $this->lockManagerMock->expects($this->once())->method('unlock');

        $order = $this->buildOrder(7, 'W000000007');
        $this->controller->method('findOrder')->willReturn($order);
        $this->cartManagementMock->expects($this->never())->method('placeOrder');

        $this->assertSame($order, $this->invoke($quote));
    }

    /**
     * Inactive quote whose order shows up on the re-check: the client-side
     * placeOrder won the race — return its order, never place a second one.
     */
    public function testInactiveConvertedQuoteReturnsRacingOrder(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->method('lock')->willReturn(true);

        $reloaded = $this->buildReloadedQuote(['active' => false]);
        $reloaded->expects($this->never())->method('setIsActive');
        $this->cartRepositoryMock->method('get')->with(42)->willReturn($reloaded);

        $order = $this->buildOrder(7, 'W000000007');
        $this->controller->method('findOrder')->willReturnOnConsecutiveCalls(null, $order);
        $this->cartManagementMock->expects($this->never())->method('placeOrder');

        $this->assertSame($order, $this->invoke($quote));
    }

    /**
     * Inactive quote with NO order anywhere: deactivated without conversion
     * (cart merge / expiry) — the exact paid-but-orderless case. Must be
     * reactivated and placed, not silently skipped.
     */
    public function testInactiveUnconvertedQuoteIsReactivatedAndPlaced(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->method('lock')->willReturn(true);

        $reloaded = $this->buildReloadedQuote([
            'active'        => false,
            'items'         => 2,
            'customerEmail' => 'shopper@example.com',
        ]);
        $reloaded->expects($this->once())->method('setIsActive')->with(true);
        $this->cartRepositoryMock->method('get')->with(42)->willReturn($reloaded);

        $this->controller->method('findOrder')->willReturn(null);

        $order = $this->buildOrder(77, 'W000000077');
        $this->cartManagementMock->expects($this->once())->method('placeOrder')->with(42)->willReturn(77);
        $this->orderRepositoryMock->method('get')->with(77)->willReturn($order);

        $this->assertSame($order, $this->invoke($quote));
    }

    public function testEmptyQuoteReturnsNull(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->method('lock')->willReturn(true);

        $reloaded = $this->buildReloadedQuote(['items' => 0]);
        $this->cartRepositoryMock->method('get')->willReturn($reloaded);
        $this->controller->method('findOrder')->willReturn(null);
        $this->cartManagementMock->expects($this->never())->method('placeOrder');

        $this->assertNull($this->invoke($quote));
    }

    public function testNoResolvableEmailReturnsNullWithoutPlacing(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->method('lock')->willReturn(true);

        $reloaded = $this->buildReloadedQuote([
            'customerEmail' => null,
            'billingEmail'  => null,
            'shippingEmail' => null,
        ]);
        $this->cartRepositoryMock->method('get')->willReturn($reloaded);
        $this->controller->method('findOrder')->willReturn(null);
        $this->cartManagementMock->expects($this->never())->method('placeOrder');

        $this->assertNull($this->invoke($quote));
    }

    public function testGuestEmailRecoveredFromBillingAddress(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->method('lock')->willReturn(true);

        $reloaded = $this->buildReloadedQuote([
            'customerEmail' => null,
            'billingEmail'  => 'guest@example.com',
        ]);
        $reloaded->expects($this->once())->method('setCustomerEmail')->with('guest@example.com');
        $this->cartRepositoryMock->method('get')->willReturn($reloaded);
        $this->controller->method('findOrder')->willReturn(null);

        $order = $this->buildOrder(77, 'W000000077');
        $this->cartManagementMock->expects($this->once())->method('placeOrder')->with(42)->willReturn(77);
        $this->orderRepositoryMock->method('get')->willReturn($order);

        $this->assertSame($order, $this->invoke($quote));
    }

    public function testMissingPaymentMethodIsBackfilled(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->method('lock')->willReturn(true);

        $reloaded = $this->buildReloadedQuote(['paymentMethod' => null]);
        $this->cartRepositoryMock->method('get')->willReturn($reloaded);
        $this->controller->method('findOrder')->willReturn(null);

        $payment = $reloaded->getPayment();
        $payment->expects($this->once())->method('setMethod')->with(Directpost::METHOD_CODE);

        $order = $this->buildOrder(77, 'W000000077');
        $this->cartManagementMock->method('placeOrder')->willReturn(77);
        $this->orderRepositoryMock->method('get')->willReturn($order);

        $this->assertSame($order, $this->invoke($quote));
    }

    public function testPlaceOrderThrowableReturnsNullAndUnlocks(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->method('lock')->willReturn(true);
        $this->lockManagerMock->expects($this->once())->method('unlock');

        $reloaded = $this->buildReloadedQuote([]);
        $this->cartRepositoryMock->method('get')->willReturn($reloaded);
        $this->controller->method('findOrder')->willReturn(null);
        $this->cartManagementMock->method('placeOrder')
            ->willThrowException(new \RuntimeException('gateway exploded'));

        $this->assertNull($this->invoke($quote));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Invoke the protected createOrderFromQuote() on the real controller.
     *
     * @param Quote|MockObject $quote
     * @return Order|null
     */
    private function invoke($quote)
    {
        $json = json_decode(json_encode(['resource' => ['id' => 'pay-test-123']]));
        $method = new \ReflectionMethod(Paystand::class, 'createOrderFromQuote');
        $method->setAccessible(true);
        return $method->invoke($this->controller, $quote, $json);
    }

    /**
     * The quote handed INTO createOrderFromQuote — only its id is consulted
     * before the method reloads a fresh copy from the repository.
     *
     * @param int $id
     * @return Quote|MockObject
     */
    private function buildInitialQuote(int $id)
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $quote->method('getId')->willReturn($id);
        return $quote;
    }

    /**
     * The fresh quote returned by cartRepository->get() inside the lock.
     *
     * Defaults model a healthy, rescuable quote: active, 1 item, payment
     * method assigned, customer email present. Override per scenario.
     *
     * @param array $overrides active|items|customerEmail|billingEmail|shippingEmail|paymentMethod
     * @return Quote|MockObject
     */
    private function buildReloadedQuote(array $overrides)
    {
        $config = array_merge([
            'active'        => true,
            'items'         => 1,
            'customerEmail' => 'shopper@example.com',
            'billingEmail'  => null,
            'shippingEmail' => null,
            'paymentMethod' => Directpost::METHOD_CODE,
        ], $overrides);

        // getCustomerEmail/setCustomerEmail are magic data accessors on Quote.
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getId', 'getIsActive', 'setIsActive', 'getItemsCount',
                'getPayment', 'getBillingAddress', 'getShippingAddress', 'collectTotals',
            ])
            ->addMethods(['getCustomerEmail', 'setCustomerEmail'])
            ->getMock();

        $quote->method('getId')->willReturn(42);
        $quote->method('getIsActive')->willReturn($config['active']);
        $quote->method('getItemsCount')->willReturn($config['items']);
        $quote->method('getCustomerEmail')->willReturn($config['customerEmail']);
        $quote->method('collectTotals')->willReturnSelf();

        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMethod', 'setMethod'])
            ->getMock();
        $payment->method('getMethod')->willReturn($config['paymentMethod']);
        $quote->method('getPayment')->willReturn($payment);

        $quote->method('getBillingAddress')->willReturn($this->buildAddress($config['billingEmail']));
        $quote->method('getShippingAddress')->willReturn($this->buildAddress($config['shippingEmail']));

        return $quote;
    }

    /**
     * @param string|null $email
     * @return Address|MockObject
     */
    private function buildAddress($email)
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEmail'])
            ->getMock();
        $address->method('getEmail')->willReturn($email);
        return $address;
    }

    /**
     * @param int $id
     * @param string $incrementId
     * @return Order|MockObject
     */
    private function buildOrder(int $id, string $incrementId)
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        $order->method('getId')->willReturn($id);
        $order->method('getIncrementId')->willReturn($incrementId);
        return $order;
    }

    /**
     * Set a (possibly inherited, possibly private) property on the controller.
     *
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
