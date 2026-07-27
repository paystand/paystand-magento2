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
     * Inactive quote, no order anywhere, AND a recorded Paystand payment: the
     * exact paid-but-orderless case (cart merge / expiry after capture). Must
     * be reactivated and placed, not silently skipped.
     */
    public function testInactiveUnconvertedPaidQuoteIsReactivatedAndPlaced(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->method('lock')->willReturn(true);

        $reloaded = $this->buildReloadedQuote([
            'active'        => false,
            'items'         => 2,
            'customerEmail' => 'shopper@example.com',
            'paystandPaymentId' => 'pay-abc123',
        ]);
        $reloaded->expects($this->once())->method('setIsActive')->with(true);
        $this->cartRepositoryMock->method('get')->with(42)->willReturn($reloaded);

        $this->controller->method('findOrder')->willReturn(null);

        $order = $this->buildOrder(77, 'W000000077');
        $this->cartManagementMock->expects($this->once())->method('placeOrder')->with(42)->willReturn(77);
        $this->orderRepositoryMock->method('get')->with(77)->willReturn($order);

        $this->assertSame($order, $this->invoke($quote));
    }

    /**
     * An inactive quote with no order and NO payment evidence at all (neither a
     * recorded marker nor a payment id on the webhook) must not be resurrected —
     * that is a benign deactivation (cart merge / cron expiry / admin hold).
     */
    public function testInactiveQuoteWithNoPaymentEvidenceIsNotReactivated(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->method('lock')->willReturn(true);

        $reloaded = $this->buildReloadedQuote([
            'active'            => false,
            'paystandPaymentId' => null,
        ]);
        $reloaded->expects($this->never())->method('setIsActive');
        $this->cartRepositoryMock->method('get')->with(42)->willReturn($reloaded);

        $this->controller->method('findOrder')->willReturn(null);
        $this->cartManagementMock->expects($this->never())->method('placeOrder');

        // No marker on the quote AND no payment id on the webhook payload.
        $this->assertNull($this->invoke($quote, 'posted', ''));
    }

    /**
     * The verified webhook's own payment id rescues a paid-but-orderless quote
     * even when the quote marker is missing — i.e. when the client-side
     * savepaymentdata call never landed. Relying on the marker alone would miss
     * exactly the failure this fallback exists for.
     */
    public function testInactiveQuoteIsRescuedOnWebhookPaymentAloneWithoutMarker(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->method('lock')->willReturn(true);

        $reloaded = $this->buildReloadedQuote([
            'active'            => false,
            'paystandPaymentId' => null,
        ]);
        $reloaded->expects($this->once())->method('setIsActive')->with(true);
        $this->cartRepositoryMock->method('get')->with(42)->willReturn($reloaded);

        $this->controller->method('findOrder')->willReturn(null);

        $order = $this->buildOrder(77, 'W000000077');
        $this->cartManagementMock->expects($this->once())->method('placeOrder')->with(42)->willReturn(77);
        $this->orderRepositoryMock->method('get')->willReturn($order);

        $this->assertSame($order, $this->invoke($quote));
    }

    // ── payment-status gate ──────────────────────────────────────────────────

    /**
     * A declined payment must NEVER produce an order. execute()'s processable
     * list admits 'failed' (it drives order-state updates), so this method has
     * to re-check — otherwise the merchant ships against a declined payment.
     */
    public function testFailedPaymentNeverCreatesOrder(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->expects($this->never())->method('lock');
        $this->cartManagementMock->expects($this->never())->method('placeOrder');

        $this->assertNull($this->invoke($quote, 'failed'));
    }

    /**
     * Regression for the PHD-46847 incident itself: EVERY no-order webhook
     * delivery for the stranded payment carried "Payment status: processing"
     * (07-20 19:26:57Z / 19:32:07Z / 20:32:17Z), while the capture had already
     * happened client-side. A gate that excluded 'processing' would return null
     * here and disable this rescue in the exact case it exists for.
     *
     * Creating on 'processing' is safe because invoicing is decoupled: execute()
     * only creates the transaction/invoice once the status reaches updateOrderOn.
     */
    public function testProcessingPaymentCreatesOrder(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->method('lock')->willReturn(true);
        $this->cartRepositoryMock->method('get')->willReturn($this->buildReloadedQuote([]));
        $this->controller->method('findOrder')->willReturn(null);

        $order = $this->buildOrder(77, 'W000000077');
        $this->cartManagementMock->expects($this->once())->method('placeOrder')->willReturn(77);
        $this->orderRepositoryMock->method('get')->willReturn($order);

        $this->assertSame($order, $this->invoke($quote, 'processing'));
    }

    /**
     * 'canceled' maps to STATE_CANCELED, not STATE_PROCESSING — it must never
     * bring a new order into being.
     */
    public function testCanceledPaymentNeverCreatesOrder(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->expects($this->never())->method('lock');
        $this->cartManagementMock->expects($this->never())->method('placeOrder');

        $this->assertNull($this->invoke($quote, 'canceled'));
    }

    /**
     * Guards the loose comparison inside newOrderStatus(): it opens with
     * `$status == $this->updateOrderOn`, and '' == null is true in PHP, so an
     * absent status would map to STATE_PROCESSING without the explicit empty
     * check in front of the delegation.
     */
    public function testMissingPaymentStatusDoesNotCreateOrder(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->expects($this->never())->method('lock');
        $this->cartManagementMock->expects($this->never())->method('placeOrder');

        $this->assertNull($this->invoke($quote, ''));
    }

    public function testPaidStatusCreatesOrder(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->method('lock')->willReturn(true);
        $this->cartRepositoryMock->method('get')->willReturn($this->buildReloadedQuote([]));
        $this->controller->method('findOrder')->willReturn(null);

        $order = $this->buildOrder(77, 'W000000077');
        $this->cartManagementMock->expects($this->once())->method('placeOrder')->willReturn(77);
        $this->orderRepositoryMock->method('get')->willReturn($order);

        $this->assertSame($order, $this->invoke($quote, 'paid'));
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

    public function testGuestEmailRecoveredFromShippingAddressWhenBillingMissing(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->method('lock')->willReturn(true);

        $reloaded = $this->buildReloadedQuote([
            'customerEmail' => null,
            'billingEmail'  => null,
            'shippingEmail' => 'shipping-guest@example.com',
        ]);
        $reloaded->expects($this->once())->method('setCustomerEmail')->with('shipping-guest@example.com');
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

    /**
     * If a rescue reactivated the quote and placeOrder then failed, the quote
     * must be put back to inactive — otherwise a cart Magento deliberately
     * deactivated (e.g. merged on login) is resurrected in the shopper's
     * session, and every webhook retry repeats the attempt.
     */
    public function testReactivationIsRolledBackWhenPlaceOrderFails(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->method('lock')->willReturn(true);

        $reloaded = $this->buildReloadedQuote([
            'active'            => false,
            'paystandPaymentId' => 'pay-abc123',
        ]);
        $this->cartRepositoryMock->method('get')->willReturn($reloaded);
        $this->controller->method('findOrder')->willReturn(null);
        $this->cartManagementMock->method('placeOrder')
            ->willThrowException(new \RuntimeException('gateway exploded'));

        // Reactivated on the way in, then restored on the failure path.
        $reloaded->expects($this->exactly(2))
            ->method('setIsActive')
            ->willReturnCallback(function ($active) use ($reloaded) {
                static $calls = 0;
                $this->assertSame($calls === 0, $active, 'setIsActive(true) then setIsActive(false)');
                $calls++;
                return $reloaded;
            });

        $this->assertNull($this->invoke($quote));
    }

    /**
     * Once placeOrder() has committed, our in-memory quote is stale (placeOrder
     * rewrites the quote row). A later throw — e.g. reloading the order failing
     * — must NOT trigger the rollback save, which would clobber Magento's own
     * writes on a quote that now belongs to a real order.
     */
    public function testReactivationIsNotRolledBackAfterPlaceOrderCommitted(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->method('lock')->willReturn(true);

        $reloaded = $this->buildReloadedQuote([
            'active'            => false,
            'paystandPaymentId' => 'pay-abc123',
        ]);
        $this->cartRepositoryMock->method('get')->willReturn($reloaded);
        $this->controller->method('findOrder')->willReturn(null);

        // placeOrder commits, then the order reload blows up.
        $this->cartManagementMock->method('placeOrder')->willReturn(77);
        $this->orderRepositoryMock->method('get')
            ->willThrowException(new \RuntimeException('order reload failed'));

        // Reactivated on the way in, and NOT flipped back afterwards.
        $reloaded->expects($this->once())->method('setIsActive')->with(true);

        $this->assertNull($this->invoke($quote));
    }

    /**
     * A quote that was already active must not be touched by the rollback path
     * when placeOrder fails — we only undo what we ourselves changed.
     */
    public function testActiveQuoteIsNotDeactivatedWhenPlaceOrderFails(): void
    {
        $quote = $this->buildInitialQuote(42);
        $this->lockManagerMock->method('lock')->willReturn(true);

        $reloaded = $this->buildReloadedQuote(['active' => true]);
        $reloaded->expects($this->never())->method('setIsActive');
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
     * Defaults to a successfully captured payment ('posted'), the only class of
     * status for which an order may be created server-side.
     *
     * @param Quote|MockObject $quote
     * @param string $status Paystand payment status on the webhook payload
     * @param string $paymentId Payment id on the webhook payload ('' = absent)
     * @return Order|null
     */
    private function invoke($quote, string $status = 'posted', string $paymentId = 'pay-test-123')
    {
        $resource = ['status' => $status];
        if ($paymentId !== '') {
            $resource['id'] = $paymentId;
        }
        $json = json_decode(json_encode(['resource' => $resource]));
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
     * method assigned, customer email present, and a recorded Paystand
     * payment (the signal that gates reactivation of an inactive quote).
     * Override per scenario.
     *
     * @param array $overrides active|items|customerEmail|billingEmail|shippingEmail|paymentMethod|paystandPaymentId
     * @return Quote|MockObject
     */
    private function buildReloadedQuote(array $overrides)
    {
        $config = array_merge([
            'active'            => true,
            'items'             => 1,
            'customerEmail'     => 'shopper@example.com',
            'billingEmail'      => null,
            'shippingEmail'     => null,
            'paymentMethod'     => Directpost::METHOD_CODE,
            'paystandPaymentId' => 'pay-default123',
        ], $overrides);

        // getCustomerEmail/setCustomerEmail are magic data accessors on Quote.
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getId', 'getIsActive', 'setIsActive', 'getItemsCount',
                'getPayment', 'getBillingAddress', 'getShippingAddress', 'collectTotals',
                'getData',
            ])
            ->addMethods(['getCustomerEmail', 'setCustomerEmail'])
            ->getMock();

        $quote->method('getId')->willReturn(42);
        $quote->method('getIsActive')->willReturn($config['active']);
        $quote->method('getItemsCount')->willReturn($config['items']);
        $quote->method('getCustomerEmail')->willReturn($config['customerEmail']);
        $quote->method('collectTotals')->willReturnSelf();
        $quote->method('getData')->with('paystand_payment_id')->willReturn($config['paystandPaymentId']);

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
