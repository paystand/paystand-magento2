<?php

namespace PayStand\PayStandMagento\Test\Unit\Controller\Webhook;

use PayStand\PayStandMagento\Controller\Webhook\Paystand;
use PayStand\PayStandMagento\Helper\CloudLogger;
use PayStand\PayStandMagento\Model\PaymentAmountGate;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Quote\Model\QuoteIdMask;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\StateException;

/**
 * Tests for Controller\Webhook\Paystand::execute(). The access token, event check and
 * findOrder() are protected so they can be stubbed; no HTTP calls or DB queries run.
 */
class PaystandTest extends TestCase
{
    /** @var Paystand|MockObject */
    private $controller;

    /** @var LoggerInterface|MockObject */
    private $loggerMock;

    /** @var HttpRequest|MockObject */
    private $requestMock;

    /** @var JsonResult|MockObject */
    private $jsonResultMock;

    /** @var JsonFactory|MockObject */
    private $jsonResultFactoryMock;

    /** @var ScopeConfigInterface|MockObject */
    private $scopeConfigMock;

    /** @var ObjectManagerInterface|MockObject */
    private $objectManagerMock;

    /** @var QuoteIdMaskFactory|MockObject */
    private $quoteIdMaskFactoryMock;

    /** @var CartRepositoryInterface|MockObject */
    private $cartRepositoryMock;

    /** @var OrderRepositoryInterface|MockObject */
    private $orderRepositoryMock;

    /** @var InvoiceCollectionFactory|MockObject */
    private $invoiceCollectionFactoryMock;

    protected function setUp(): void
    {
        $this->loggerMock = $this->getMockBuilder(LoggerInterface::class)
            ->getMockForAbstractClass();

        $this->requestMock = $this->getMockBuilder(HttpRequest::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->jsonResultMock = $this->getMockBuilder(JsonResult::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->jsonResultMock->method('setHttpResponseCode')->willReturnSelf();
        $this->jsonResultMock->method('setData')->willReturnSelf();

        $this->jsonResultFactoryMock = $this->getMockBuilder(JsonFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->jsonResultFactoryMock->method('create')->willReturn($this->jsonResultMock);

        $this->scopeConfigMock = $this->getMockBuilder(ScopeConfigInterface::class)
            ->getMockForAbstractClass();

        $this->objectManagerMock = $this->getMockBuilder(ObjectManagerInterface::class)
            ->getMockForAbstractClass();

        $this->quoteIdMaskFactoryMock = $this->getMockBuilder(QuoteIdMaskFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->cartRepositoryMock = $this->getMockBuilder(CartRepositoryInterface::class)
            ->getMockForAbstractClass();

        $this->orderRepositoryMock = $this->getMockBuilder(OrderRepositoryInterface::class)
            ->getMockForAbstractClass();

        $this->invoiceCollectionFactoryMock = $this->getMockBuilder(InvoiceCollectionFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Partial mock: stub out HTTP calls and findOrder (DB)
        $this->controller = $this->getMockBuilder(Paystand::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPaystandAccessToken', 'verifyPaystandEvent', 'findOrder', 'createOrderFromQuote'])
            ->getMock();

        // Wire all properties the constructor would have set
        $this->set('_logger',                   $this->loggerMock);
        $this->set('_request',                  $this->requestMock);
        $this->set('_jsonResultFactory',         $this->jsonResultFactoryMock);
        $this->set('_objectManager',             $this->objectManagerMock);
        $this->set('_quoteIdMaskFactory',        $this->quoteIdMaskFactoryMock);
        $this->set('scopeConfig',                $this->scopeConfigMock);
        $this->set('cartRepository',             $this->cartRepositoryMock);
        $this->set('_orderRepository',           $this->orderRepositoryMock);
        $this->set('_invoiceCollectionFactory',  $this->invoiceCollectionFactoryMock);
        $this->set('updateOrderOn',              'posted');

        // Unused-in-early-paths deps
        $this->set('_builderInterface',   $this->getMockBuilder(BuilderInterface::class)->getMockForAbstractClass());
        $this->set('_invoiceService',     $this->getMockBuilder(InvoiceService::class)->disableOriginalConstructor()->getMock());
        $this->set('_transactionFactory', $this->getMockBuilder(TransactionFactory::class)->disableOriginalConstructor()->getMock());
        $this->set('_invoiceRepository',  $this->getMockBuilder(InvoiceRepositoryInterface::class)->getMockForAbstractClass());
    }

    /**
     * execute() reads this constant first, so without it every webhook fails.
     */
    public function testEventWebhookStartConstantIsDefined(): void
    {
        $this->assertTrue(
            defined(CloudLogger::class . '::EVENT_WEBHOOK_START'),
            'CloudLogger::EVENT_WEBHOOK_START must be defined — absence crashes every webhook hit'
        );
        $this->assertNotEmpty(CloudLogger::EVENT_WEBHOOK_START);
    }

    public function testAllCloudLoggerEventConstantsAreDefined(): void
    {
        $constants = [
            'EVENT_WEBHOOK_START',
            'EVENT_WEBHOOK_NO_ORDER',
            'EVENT_WEBHOOK_ORDER_CREATED',
            'EVENT_SAVEPAYMENTDATA_SUCCESS',
            'EVENT_SAVEPAYMENTDATA_ERROR',
            'EVENT_PLACEORDER_EXCEPTION',
            'EVENT_SERIALIZATION_ERROR',
        ];
        foreach ($constants as $name) {
            $this->assertTrue(
                defined(CloudLogger::class . '::' . $name),
                "CloudLogger::{$name} must be defined"
            );
        }
    }

    public function testExecuteReturnsBadRequestOnEmptyBody(): void
    {
        $this->requestMock->method('getContent')->willReturn(null);

        $this->jsonResultMock->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(500)
            ->willReturnSelf();

        $this->controller->execute();
    }

    public function testExecuteReturnsOkWhenSourceIsNotMagento2(): void
    {
        $body = $this->makeBody([
            'resource' => ['object' => 'payment', 'status' => 'posted', 'meta' => ['source' => 'netsuite']],
        ]);
        $this->requestMock->method('getContent')->willReturn($body);

        $this->jsonResultMock->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(200)
            ->willReturnSelf();

        $this->controller->execute();
    }

    public function testExecuteReturnsOkWhenResourceIsNotPaymentObject(): void
    {
        $body = json_encode([
            'id' => 'evt-1',
            'resource' => [
                'object' => 'fee',
                'status' => 'posted',
                'meta'   => ['source' => 'magento 2', 'quote' => 'q1'],
            ],
        ]);
        $this->requestMock->method('getContent')->willReturn($body);

        $this->jsonResultMock->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(200)
            ->willReturnSelf();

        $this->controller->execute();
    }

    public function testExecuteReturnsBadRequestWhenAccessTokenIsNull(): void
    {
        $this->requestMock->method('getContent')->willReturn($this->makeBody());
        $this->controller->method('getPaystandAccessToken')->willReturn(null);

        $this->jsonResultMock->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(400)
            ->willReturnSelf();

        $this->controller->execute();
    }

    public function testExecuteReturnsBadRequestWhenEventVerificationFails(): void
    {
        $this->requestMock->method('getContent')->willReturn($this->makeBody());
        $this->controller->method('getPaystandAccessToken')->willReturn('fake-token');
        $this->controller->method('verifyPaystandEvent')->willReturn(false);

        $this->jsonResultMock->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(400)
            ->willReturnSelf();

        $this->controller->execute();
    }

    public function testExecuteReturnsOkWhenPaymentStatusIsNotProcessable(): void
    {
        $this->requestMock->method('getContent')
            ->willReturn($this->makeBody(['resource' => [
                'id'     => 'pay-1',
                'object' => 'payment',
                'status' => 'created',
                'meta'   => ['source' => 'magento 2', 'quote' => 'q1'],
            ]]));
        $this->controller->method('getPaystandAccessToken')->willReturn('fake-token');
        $this->controller->method('verifyPaystandEvent')->willReturn(true);

        $this->jsonResultMock->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(200)
            ->willReturnSelf();

        $this->controller->execute();
    }

    public function testExecuteReturnsBadRequestWhenQuoteNotFound(): void
    {
        $this->requestMock->method('getContent')->willReturn($this->makeBody());
        $this->controller->method('getPaystandAccessToken')->willReturn('fake-token');
        $this->controller->method('verifyPaystandEvent')->willReturn(true);
        $this->setupQuoteIdMask();

        $this->cartRepositoryMock->method('get')
            ->willThrowException(new NoSuchEntityException(__('Not found')));

        $this->jsonResultMock->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(400)
            ->willReturnSelf();

        $this->controller->execute();
    }

    public function testExecuteReturnsBadRequestWhenQuoteIsInInvalidState(): void
    {
        $this->requestMock->method('getContent')->willReturn($this->makeBody());
        $this->controller->method('getPaystandAccessToken')->willReturn('fake-token');
        $this->controller->method('verifyPaystandEvent')->willReturn(true);
        $this->setupQuoteIdMask();

        $this->cartRepositoryMock->method('get')
            ->willThrowException(new StateException(__('Invalid state')));

        $this->jsonResultMock->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(400)
            ->willReturnSelf();

        $this->controller->execute();
    }

    /**
     * When every order search fails, execute() returns 404 so Paystand delivers again.
     */
    public function testExecuteReturnsNotFoundWhenOrderNotFoundAfterRetries(): void
    {
        $this->requestMock->method('getContent')->willReturn($this->makeBody());
        $this->controller->method('getPaystandAccessToken')->willReturn('fake-token');
        $this->controller->method('verifyPaystandEvent')->willReturn(true);
        $this->setupQuoteIdMask();
        $this->setupQuoteMock();

        $this->controller->method('findOrder')->willReturn(null);
        // The server-side fallback also fails, so no order can be produced.
        $this->controller->method('createOrderFromQuote')->willReturn(null);

        $this->jsonResultMock->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(404)
            ->willReturnSelf();

        $this->controller->execute();
    }

    /**
     * A terminal failure past the window returns 200 to stop delivery, and logs rescue_abandoned.
     */
    public function testAbandonsRetriesWhenFailureIsTerminalAndEventIsOld(): void
    {
        // makeBody() sets created to 2026-01-01, past the window.
        $this->requestMock->method('getContent')->willReturn($this->makeBody());
        $this->controller->method('getPaystandAccessToken')->willReturn('fake-token');
        $this->controller->method('verifyPaystandEvent')->willReturn(true);
        $this->setupQuoteIdMask();
        $this->setupQuoteMock();

        $this->controller->method('findOrder')->willReturn(null);
        $this->controller->method('createOrderFromQuote')->willReturn(null);
        $this->set('lastRescueFailure', [
            'terminal' => true,
            'message'  => 'The shipping method is missing. Select the shipping method and try again.',
        ]);

        $this->jsonResultMock->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(200)
            ->willReturnSelf();

        $this->controller->execute();
    }

    /**
     * Paystand reports the payment failed, so no redelivery can place an order.
     * Not terminal, so without the flag this would be a 404.
     */
    public function testStopsRetriesWhenThePaymentFailedBeforeTheRescue(): void
    {
        $this->requestMock->method('getContent')->willReturn($this->makeBody(['resource' => ['status' => 'processing']]));
        $this->controller->method('getPaystandAccessToken')->willReturn('fake-token');
        $this->controller->method('verifyPaystandEvent')->willReturn(true);
        $this->setupQuoteIdMask();
        $this->setupQuoteMock();

        $this->controller->method('findOrder')->willReturn(null);
        $this->controller->method('createOrderFromQuote')->willReturn(null);
        $this->set('lastRescueFailure', [
            'terminal'      => false,
            'refused'       => false,
            'paymentFailed' => true,
            'message'       => 'Delivery status processing, Paystand now reports failed; order not placed',
        ]);

        $this->jsonResultMock->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(200)
            ->willReturnSelf();

        $this->controller->execute();

        $token = new \ReflectionProperty(Paystand::class, 'paystandAccessToken');
        $token->setAccessible(true);
        $this->assertSame('fake-token', $token->getValue($this->controller));
    }

    /**
     * Exercised directly rather than through execute(), which sleeps ~19s per call.
     *
     * @dataProvider rescueRetryScenarios
     */
    public function testRescueAbandonDecision(bool $expected, $failure, $created, string $case): void
    {
        $this->set('lastRescueFailure', $failure);

        $json = json_decode(json_encode(['created' => $created, 'resource' => ['id' => 'pay-1']]));

        $method = new \ReflectionMethod(Paystand::class, 'isRescueTerminalAndExpired');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($this->controller, $json), $case);
    }

    /** @return array<string, array{0: bool, 1: mixed, 2: mixed, 3: string}> */
    public static function rescueRetryScenarios(): array
    {
        $old = '2026-01-01T00:00:00.000Z';
        $fresh = gmdate('Y-m-d\TH:i:s.000\Z');
        $terminal = ['terminal' => true, 'message' => 'The shipping method is missing.'];

        return [
            'terminal + old event => abandon' => [true, $terminal, $old, 'a rejected cart past the window is futile to retry'],
            // Infrastructure faults keep their retries, however old the event.
            'transient + old event => retry' => [false, ['terminal' => false, 'message' => 'deadlock'], $old, 'infra faults must keep retrying'],
            // A rate lost to a short carrier outage can come back on the next delivery.
            'terminal + fresh event => retry' => [false, $terminal, $fresh, 'a fresh terminal failure may still resolve'],
            // Fail safe: unknown age must never drop a paid order.
            'terminal + no timestamp => retry' => [false, $terminal, null, 'unknown age must fall back to retrying'],
            'terminal + unparseable timestamp => retry' => [false, $terminal, 'not-a-date', 'a bad timestamp must not abandon'],
            'no recorded failure => retry' => [false, null, $old, 'nothing failed terminally, so nothing to abandon'],
        ];
    }

    /**
     * When the order search finds nothing, the webhook creates the order instead of returning 404.
     */
    public function testExecuteCreatesOrderServerSideWhenSearchFindsNone(): void
    {
        $this->requestMock->method('getContent')->willReturn($this->makeBody());
        $this->controller->method('getPaystandAccessToken')->willReturn('fake-token');
        $this->controller->method('verifyPaystandEvent')->willReturn(true);
        $this->setupQuoteIdMask();
        $this->setupQuoteMock();

        // Search exhausts all strategies, but server-side creation yields the order.
        $this->controller->method('findOrder')->willReturn(null);
        $createdOrder = $this->buildOrderMock(Order::STATE_CANCELED);
        $this->controller->method('createOrderFromQuote')->willReturn($createdOrder);
        $this->orderRepositoryMock->method('get')->willReturn($createdOrder);

        // Must reach the order-processing path (200), not the 404 retry path.
        $this->jsonResultMock->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(200)
            ->willReturnSelf();

        $this->controller->execute();
    }

    public function testExecuteReturnsOkWhenOrderIsCanceled(): void
    {
        $this->requestMock->method('getContent')->willReturn($this->makeBody());
        $this->controller->method('getPaystandAccessToken')->willReturn('fake-token');
        $this->controller->method('verifyPaystandEvent')->willReturn(true);
        $this->setupQuoteIdMask();
        $this->setupQuoteMock();

        $orderMock = $this->buildOrderMock(Order::STATE_CANCELED);
        $this->controller->method('findOrder')->willReturn($orderMock);
        $this->orderRepositoryMock->method('get')->willReturn($orderMock);

        $this->jsonResultMock->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(200)
            ->willReturnSelf();

        $this->controller->execute();
    }

    public function testExecuteReturnsOkWhenOrderAlreadyProcessingAndHasInvoice(): void
    {
        $this->requestMock->method('getContent')->willReturn($this->makeBody());
        $this->controller->method('getPaystandAccessToken')->willReturn('fake-token');
        $this->controller->method('verifyPaystandEvent')->willReturn(true);
        $this->setupQuoteIdMask();
        $this->setupQuoteMock();

        $orderMock = $this->buildOrderMock(Order::STATE_PROCESSING);
        $this->controller->method('findOrder')->willReturn($orderMock);
        $this->orderRepositoryMock->method('get')->willReturn($orderMock);

        $invoiceCollectionMock = $this->getMockBuilder(\Magento\Sales\Model\ResourceModel\Order\Invoice\Collection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $invoiceCollectionMock->method('addAttributeToFilter')->willReturnSelf();
        $invoiceCollectionMock->method('count')->willReturn(1);
        $this->invoiceCollectionFactoryMock->method('create')->willReturn($invoiceCollectionMock);

        $this->jsonResultMock->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(200)
            ->willReturnSelf();

        $this->controller->execute();
    }

    /**
     * Pending order, 'posted' payment, updateOrderOn = 'paid'. The state changes and the
     * response is 200, but no invoice or transaction is created.
     */
    public function testExecuteChangesOrderStateForPendingOrderOnProcessableStatus(): void
    {
        $this->set('updateOrderOn', 'paid');

        $this->requestMock->method('getContent')->willReturn($this->makeBody());
        $this->controller->method('getPaystandAccessToken')->willReturn('fake-token');
        $this->controller->method('verifyPaystandEvent')->willReturn(true);
        $this->setupQuoteIdMask();
        $this->setupQuoteMock();

        $orderMock = $this->buildOrderMock('pending');
        $orderMock->expects($this->atLeastOnce())->method('setState');
        $orderMock->expects($this->atLeastOnce())->method('setStatus');
        $orderMock->expects($this->atLeastOnce())->method('save');

        $this->controller->method('findOrder')->willReturn($orderMock);
        $this->orderRepositoryMock->method('get')->willReturn($orderMock);

        $this->jsonResultMock->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(200)
            ->willReturnSelf();

        $this->controller->execute();
    }

    public function testMismatchedOrderStaysOnHoldAndNotInvoicedThroughPostedThenPaid(): void
    {
        $this->set('amountGate', new PaymentAmountGate($this->loggerMock));
        $this->wireTransactionBuilder();
        $invoiceService = $this->getMockBuilder(InvoiceService::class)->disableOriginalConstructor()->getMock();
        $invoiceService->expects($this->never())->method('prepareInvoice');
        $this->set('_invoiceService', $invoiceService);
        $this->setupInvoiceCollection(0);

        $status = 'posted';
        $this->requestMock->method('getContent')->willReturnCallback(function () use (&$status) {
            return $this->makeBody(['resource' => $this->paymentResource($status, '106.14')]);
        });
        $this->controller->method('getPaystandAccessToken')->willReturn('fake-token');
        $this->controller->method('verifyPaystandEvent')->willReturn(true);
        $this->setupQuoteIdMask();
        $this->setupQuoteMock();

        $order = $this->buildGatedOrderMock('new', 109.63);
        $order->expects($this->once())->method('addCommentToStatusHistory');
        $this->controller->method('findOrder')->willReturn($order);
        $this->orderRepositoryMock->method('get')->willReturn($order);
        $response = $this->captureResponse();

        $this->controller->execute();
        $this->assertSame(Order::STATE_HOLDED, $order->getState());
        $this->assertSame([200], $response->codes);
        $this->assertSame(
            'Paystand amount not confirmed against the order total, order held and not invoiced',
            (string)$response->data['success_message']
        );
        $this->assertArrayNotHasKey('order', $response->data);

        $status = 'paid';
        $this->controller->execute();
        $this->assertSame(Order::STATE_HOLDED, $order->getState());
        $this->assertSame([200, 200], $response->codes);
    }

    /**
     * A late paid webhook must not reopen or hold a complete or closed order.
     *
     * @dataProvider finishedStates
     */
    public function testLeavesAFinishedOrderAsItIs(string $state): void
    {
        $this->set('updateOrderOn', 'paid');
        $gate = $this->getMockBuilder(PaymentAmountGate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['checkOrder'])
            ->getMock();
        $gate->expects($this->never())->method('checkOrder');
        $this->set('amountGate', $gate);
        $warnings = $this->captureWarnings();

        $this->requestMock->method('getContent')
            ->willReturn($this->makeBody(['resource' => $this->paymentResource('paid', '106.14')]));
        $this->controller->method('getPaystandAccessToken')->willReturn('fake-token');
        $this->controller->method('verifyPaystandEvent')->willReturn(true);
        $this->setupQuoteIdMask();
        $this->setupQuoteMock();

        $order = $this->buildOrderMock($state);
        $order->expects($this->never())->method('setState');
        $order->expects($this->never())->method('save');
        $this->controller->method('findOrder')->willReturn($order);
        $this->orderRepositoryMock->method('get')->willReturn($order);

        $this->jsonResultMock->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(200)
            ->willReturnSelf();

        $this->controller->execute();

        $this->assertContains(
            '>>>>> PAYSTAND-ORDER-NOT-OPEN: order 000000001 is ' . $state
                . ', payment mp0m4r5x8ovwo0i1btgop38l paid not invoiced, recorded=no',
            $warnings->list
        );
    }

    public static function finishedStates(): array
    {
        return ['complete' => [Order::STATE_COMPLETE], 'closed' => [Order::STATE_CLOSED]];
    }

    /**
     * A merchant or fraud hold stays. The payment and the amount check are recorded
     * on the order once, and the order is not invoiced.
     */
    public function testMerchantHeldOrderRecordsThePaymentOnceAndStaysHeld(): void
    {
        $this->set('amountGate', new PaymentAmountGate($this->loggerMock));
        $this->wireTransactionBuilder();
        $invoiceService = $this->wireInvoicing($invoiceCount);
        $invoiceService->expects($this->never())->method('prepareInvoice');

        $status = 'posted';
        $this->requestMock->method('getContent')->willReturnCallback(function () use (&$status) {
            return $this->makeBody(['resource' => $this->paymentResource($status, '109.63')]);
        });
        $this->controller->method('getPaystandAccessToken')->willReturn('fake-token');
        $this->controller->method('verifyPaystandEvent')->willReturn(true);
        $this->setupQuoteIdMask();
        $this->setupQuoteMock();
        $warnings = $this->captureWarnings();

        $order = $this->buildGatedOrderMock(Order::STATE_HOLDED, 109.63);
        $order->expects($this->never())->method('hold');
        $order->expects($this->once())->method('addCommentToStatusHistory')->with(
            $this->callback(function ($comment) {
                return (string)$comment === 'Paystand payment mp0m4r5x8ovwo0i1btgop38l matches the order total.'
                    . ' The order was on hold, so it was not invoiced.';
            }),
            false
        );
        $this->controller->method('findOrder')->willReturn($order);
        $this->orderRepositoryMock->method('get')->willReturn($order);

        $this->controller->execute();
        $status = 'paid';
        $this->controller->execute();

        $this->assertSame(Order::STATE_HOLDED, $order->getState());
        $this->assertSame('mp0m4r5x8ovwo0i1btgop38l', $order->getPayment()->getLastTransId());
        $this->assertContains(
            '>>>>> PAYSTAND-ORDER-NOT-OPEN: order 1000763084 is holded, payment mp0m4r5x8ovwo0i1btgop38l'
                . ' posted not invoiced, recorded=invoice',
            $warnings->list
        );
        $this->assertContains(
            '>>>>> PAYSTAND-ORDER-NOT-OPEN: order 1000763084 is holded, payment mp0m4r5x8ovwo0i1btgop38l'
                . ' paid not invoiced, recorded=already',
            $warnings->list
        );
    }

    /**
     * posted then paid for one payment creates one invoice, from either open state.
     *
     * @dataProvider openStates
     */
    public function testMatchingPaymentCreatesExactlyOneInvoice(string $state): void
    {
        $this->set('amountGate', new PaymentAmountGate($this->loggerMock));
        $this->wireTransactionBuilder();
        $invoiceService = $this->wireInvoicing($invoiceCount);

        $status = 'posted';
        $this->requestMock->method('getContent')->willReturnCallback(function () use (&$status) {
            return $this->makeBody(['resource' => $this->paymentResource($status, '109.63')]);
        });
        $this->controller->method('getPaystandAccessToken')->willReturn('fake-token');
        $this->controller->method('verifyPaystandEvent')->willReturn(true);
        $this->setupQuoteIdMask();
        $this->setupQuoteMock();
        $response = $this->captureResponse();

        $order = $this->buildGatedOrderMock($state, 109.63);
        $order->expects($this->never())->method('hold');
        $this->controller->method('findOrder')->willReturn($order);
        $this->orderRepositoryMock->method('get')->willReturn($order);

        $this->controller->execute();
        $this->assertSame(1, $invoiceCount);
        $this->assertSame(Order::STATE_PROCESSING, $order->getState());

        $status = 'paid';
        $this->controller->execute();
        $this->assertSame(1, $invoiceCount);
        $this->assertSame(
            'Order already has invoice, no further action needed',
            (string)$response->data['success_message']
        );
    }

    public static function openStates(): array
    {
        return ['new' => ['new'], 'processing' => [Order::STATE_PROCESSING]];
    }

    public function testProcessingOrderInvoiceIsReported(): void
    {
        $this->set('amountGate', new PaymentAmountGate($this->loggerMock));
        $this->wireTransactionBuilder();
        $this->wireInvoicing($invoiceCount);
        $response = $this->deliver(Order::STATE_PROCESSING, $this->paymentResource('posted', '109.63'));

        $this->assertSame(1, $invoiceCount);
        $this->assertSame('Invoice created for order', (string)$response->data['success_message']);
    }

    /**
     * Order in EUR with a USD base. A USD payment is checked against the base total.
     */
    public function testBaseCurrencyPaymentMatchingTheBaseTotalIsInvoiced(): void
    {
        $this->set('amountGate', new PaymentAmountGate($this->loggerMock));
        $this->wireTransactionBuilder();
        $this->wireInvoicing($invoiceCount);

        $this->deliver(
            Order::STATE_PROCESSING,
            $this->paymentResource('posted', '110.00'),
            100.00,
            'EUR',
            110.00,
            'USD'
        );

        $this->assertSame(1, $invoiceCount);
    }

    /**
     * @dataProvider heldPayments
     */
    public function testPaymentThatCannotBeConfirmedIsHeldAndNotInvoiced(array $payment, string $comment): void
    {
        $this->set('amountGate', new PaymentAmountGate($this->loggerMock));
        $this->wireTransactionBuilder();
        $this->wireInvoicing($invoiceCount);

        $order = null;
        $response = $this->deliver(Order::STATE_PROCESSING, $payment, 100.00, 'EUR', 110.00, 'USD', $order, $comment);

        $this->assertSame(0, $invoiceCount);
        $this->assertSame(Order::STATE_HOLDED, $order->getState());
        $this->assertSame(
            'Paystand amount not confirmed against the order total, order held and not invoiced',
            (string)$response->data['success_message']
        );
    }

    public static function heldPayments(): array
    {
        $noAmount = self::paymentResource('posted', '110.00');
        unset($noAmount['amount']);
        $noCurrency = self::paymentResource('posted', '110.00');
        unset($noCurrency['currency']);
        $otherCurrency = self::paymentResource('posted', '110.00');
        $otherCurrency['currency'] = 'GBP';
        $otherCurrency['feeSplit']['currency'] = 'GBP';
        // The base amount labelled with the order currency, which Luma sent before.
        $baseAsOrder = self::paymentResource('posted', '110.00');
        $baseAsOrder['currency'] = 'EUR';
        $baseAsOrder['feeSplit']['currency'] = 'EUR';
        $twoCents = self::paymentResource('posted', '110.02');
        $twoCents['currency'] = 'USD';

        $unverified = 'could not be checked against the order total';
        return [
            'no amount' => [$noAmount, $unverified],
            'no currency' => [$noCurrency, $unverified],
            'neither order nor base currency' => [$otherCurrency, $unverified],
            'base amount labelled with the order currency' => [$baseAsOrder, 'Paystand charged 110.00 EUR. The order total is 100.00 EUR.'],
            'two cents over the base total' => [$twoCents, 'Paystand charged 110.02 USD. The order total is 110.00 USD.'],
        ];
    }

    /**
     * A hold that cannot be saved still answers 200, so Paystand does not retry into
     * an invoice, and nothing is invoiced.
     */
    public function testHoldThatIsNotSavedAnswersOkWithoutAnInvoice(): void
    {
        $gate = $this->getMockBuilder(PaymentAmountGate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['checkOrder'])
            ->getMock();
        $gate->expects($this->once())->method('checkOrder')->willReturn(PaymentAmountGate::OUTCOME_HOLD_FAILED);
        $this->set('amountGate', $gate);
        $this->wireTransactionBuilder();
        $this->wireInvoicing($invoiceCount);

        $response = $this->deliver(Order::STATE_PROCESSING, $this->paymentResource('posted', '106.14'));

        $this->assertSame(0, $invoiceCount);
        $this->assertSame([200], $response->codes);
        $this->assertSame(
            'Paystand amount not confirmed against the order total, order not invoiced, hold not saved',
            (string)$response->data['success_message']
        );
    }

    public function testInvoicedOrderIsNotCheckedOrHeld(): void
    {
        $gate = $this->getMockBuilder(PaymentAmountGate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['checkOrder'])
            ->getMock();
        $gate->expects($this->never())->method('checkOrder');
        $this->set('amountGate', $gate);
        $this->setupInvoiceCollection(1, 9);

        $method = new \ReflectionMethod(Paystand::class, 'invoiceWhenAmountMatches');
        $method->setAccessible(true);

        $this->assertSame('invoiced', $method->invoke(
            $this->controller,
            $this->buildOrderMock(Order::STATE_PROCESSING),
            $this->paymentResource('paid', '106.14')
        ));
    }

    /**
     * PHP 8 throws \Error for an undefined constant. catch (\Throwable) stops it;
     * catch (\Exception) would not.
     */
    public function testExecuteDoesNotPropagatePhpError(): void
    {
        $threw = false;
        $errorMessage = '';
        try {
            $fn = function () {
                throw new \Error('Simulated undefined constant Error');
            };
            try {
                $fn();
            } catch (\Throwable $e) {
                $errorMessage = $e->getMessage();
            }
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertFalse($threw, '\Error must be caught by catch(\Throwable), not propagate');
        $this->assertSame('Simulated undefined constant Error', $errorMessage,
            'catch(\Throwable) must catch \Error — catch(\Exception) would not');
    }

    /**
     * execute() never lets a Throwable escape, whatever fails inside it.
     */
    public function testExecuteNeverPropagatesThrowable(): void
    {
        $this->requestMock->method('getContent')->willReturn(null);

        $threw = false;
        try {
            $this->controller->execute();
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertFalse($threw, 'execute() must never propagate a Throwable — payment flow must be protected');
    }

    /**
     * Sets a property on the controller, including inherited ones such as _request.
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

    /**
     * Build a standard valid webhook body. Payment status defaults to 'posted'.
     */
    private function makeBody(array $override = []): string
    {
        $base = [
            'id'       => 'evt-test-123',
            'object'   => 'event',
            'resource' => [
                'id'     => 'pay-test-456',
                'object' => 'payment',
                'status' => 'posted',
                'meta'   => ['source' => 'magento 2', 'quote' => 'test-quote-masked'],
            ],
            'diff'    => [],
            'created' => '2026-01-01T00:00:00.000Z',
        ];
        return json_encode(array_replace_recursive($base, $override));
    }

    /**
     * QuoteIdMask returns null getQuoteId() (logged-in user path: raw quote ID used as-is).
     */
    private function setupQuoteIdMask(): void
    {
        $maskMock = $this->getMockBuilder(QuoteIdMask::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load'])
            ->addMethods(['getQuoteId'])
            ->getMock();
        $maskMock->method('load')->willReturnSelf();
        $maskMock->method('getQuoteId')->willReturn(null);
        $this->quoteIdMaskFactoryMock->method('create')->willReturn($maskMock);
    }

    /**
     * Set up a Quote mock that cartRepository::get() will return.
     */
    private function setupQuoteMock(): Quote
    {
        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getReservedOrderId'])
            ->getMock();
        $quoteMock->method('getId')->willReturn(42);
        $quoteMock->method('getReservedOrderId')->willReturn('000000099');
        $this->cartRepositoryMock->method('get')->willReturn($quoteMock);
        return $quoteMock;
    }

    /**
     * Build an Order mock in the given state.
     */
    private function buildOrderMock(string $state): MockObject
    {
        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getId', 'getState', 'getStatus', 'getIncrementId', 'getQuoteId',
                'setState', 'setStatus', 'save',
            ])
            ->getMock();
        $orderMock->method('getId')->willReturn(1);
        $orderMock->method('getState')->willReturn($state);
        $orderMock->method('getStatus')->willReturn($state);
        $orderMock->method('getIncrementId')->willReturn('000000001');
        $orderMock->method('getQuoteId')->willReturn(42);
        $orderMock->method('setState')->willReturnSelf();
        $orderMock->method('setStatus')->willReturnSelf();
        $orderMock->method('save')->willReturnSelf();
        return $orderMock;
    }

    /**
     * Payment resource fields that createTransaction() and the amount gate read.
     */
    private static function paymentResource(string $status, string $amount): array
    {
        return [
            'id'                 => 'mp0m4r5x8ovwo0i1btgop38l',
            'object'             => 'payment',
            'status'             => $status,
            'amount'             => $amount,
            'currency'           => 'USD',
            'settlementAmount'   => $amount,
            'settlementCurrency' => 'USD',
            'payerId'            => 'payer-1',
            'sourceType'         => 'card',
            'sourceId'           => 'card-1',
            'feeSplit'           => ['subtotal' => $amount, 'payerTotal' => $amount, 'currency' => 'USD'],
            'meta'               => ['source' => 'magento 2', 'quote' => 'test-quote-masked'],
        ];
    }

    /**
     * Order mock whose state follows setState() and hold(). The payment keeps
     * last_trans_id, so a repeat delivery of the same payment can be seen.
     */
    private function buildGatedOrderMock(
        string $state,
        float $grandTotal,
        string $currency = 'USD',
        ?float $baseGrandTotal = null,
        string $baseCurrency = 'USD'
    ): MockObject {
        $payment = $this->getMockBuilder(Order\Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'setTransactionId', 'setAdditionalInformation',
                'addTransactionCommentsToOrder', 'setParentTransactionId', 'save',
            ])
            ->getMock();
        foreach (['setTransactionId', 'setAdditionalInformation', 'setParentTransactionId', 'save'] as $method) {
            $payment->method($method)->willReturnSelf();
        }

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getId', 'getState', 'getStatus', 'getIncrementId', 'getQuoteId',
                'setState', 'setStatus', 'save', 'getPayment', 'getData',
                'getGrandTotal', 'getBaseGrandTotal', 'getOrderCurrencyCode', 'getBaseCurrencyCode',
                'canHold', 'hold', 'addCommentToStatusHistory', 'canInvoice', 'addStatusHistoryComment',
            ])
            ->getMock();
        $current = ['state' => $state, 'status' => $state];
        $order->method('canInvoice')->willReturn(true);
        $order->method('addStatusHistoryComment')->willReturnSelf();
        $order->method('getId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('1000763084');
        $order->method('getQuoteId')->willReturn(42);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getData')->willReturn(null);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getBaseGrandTotal')->willReturn($baseGrandTotal ?? $grandTotal);
        $order->method('getOrderCurrencyCode')->willReturn($currency);
        $order->method('getBaseCurrencyCode')->willReturn($baseCurrency);
        $order->method('save')->willReturnSelf();
        $order->method('getState')->willReturnCallback(function () use (&$current) {
            return $current['state'];
        });
        $order->method('getStatus')->willReturnCallback(function () use (&$current) {
            return $current['status'];
        });
        $order->method('setState')->willReturnCallback(function ($value) use (&$current, $order) {
            $current['state'] = $value;
            return $order;
        });
        $order->method('setStatus')->willReturnCallback(function ($value) use (&$current, $order) {
            $current['status'] = $value;
            return $order;
        });
        $order->method('canHold')->willReturnCallback(function () use (&$current) {
            return $current['state'] !== Order::STATE_HOLDED;
        });
        $order->method('hold')->willReturnCallback(function () use (&$current, $order) {
            $current['state'] = Order::STATE_HOLDED;
            $current['status'] = Order::STATE_HOLDED;
            return $order;
        });
        return $order;
    }

    /**
     * Transaction builder chain used by createTransaction().
     */
    private function wireTransactionBuilder(): void
    {
        $transaction = $this->getMockBuilder(\Magento\Sales\Model\Order\Payment\Transaction::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save', 'getTransactionId'])
            ->getMock();
        $transaction->method('save')->willReturnSelf();
        $transaction->method('getTransactionId')->willReturn('mp0m4r5x8ovwo0i1btgop38l');

        $builder = $this->getMockBuilder(BuilderInterface::class)->getMockForAbstractClass();
        foreach (['setPayment', 'setOrder', 'setTransactionId', 'setAdditionalInformation', 'setFailSafe'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $builder->method('build')->willReturn($transaction);
        $this->set('_builderInterface', $builder);
    }

    /**
     * Sends one webhook for an order in $state and returns what the controller answered.
     *
     * @param MockObject|null $order set to the order mock used
     * @param string|null $comment expected in the single hold comment
     */
    private function deliver(
        string $state,
        array $payment,
        float $grandTotal = 109.63,
        string $currency = 'USD',
        ?float $baseGrandTotal = null,
        string $baseCurrency = 'USD',
        &$order = null,
        ?string $comment = null
    ): \stdClass {
        $this->requestMock->method('getContent')->willReturn($this->makeBody(['resource' => $payment]));
        $this->controller->method('getPaystandAccessToken')->willReturn('fake-token');
        $this->controller->method('verifyPaystandEvent')->willReturn(true);
        $this->setupQuoteIdMask();
        $this->setupQuoteMock();
        $response = $this->captureResponse();

        $order = $this->buildGatedOrderMock($state, $grandTotal, $currency, $baseGrandTotal, $baseCurrency);
        if ($comment !== null) {
            $order->expects($this->once())->method('addCommentToStatusHistory')->with(
                $this->callback(function ($text) use ($comment) {
                    return strpos((string)$text, $comment) !== false;
                }),
                false
            );
        }
        $this->controller->method('findOrder')->willReturn($order);
        $this->orderRepositoryMock->method('get')->willReturn($order);

        $this->controller->execute();
        return $response;
    }

    /**
     * Records each response code and the last response body.
     */
    private function captureResponse(): \stdClass
    {
        $response = new \stdClass();
        $response->codes = [];
        $response->data = null;
        $this->jsonResultMock = $this->getMockBuilder(JsonResult::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->jsonResultMock->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($response) {
            $response->codes[] = $code;
            return $this->jsonResultMock;
        });
        $this->jsonResultMock->method('setData')->willReturnCallback(function ($data) use ($response) {
            $response->data = $data;
            return $this->jsonResultMock;
        });
        $factory = $this->getMockBuilder(JsonFactory::class)->disableOriginalConstructor()->getMock();
        $factory->method('create')->willReturn($this->jsonResultMock);
        $this->set('_jsonResultFactory', $factory);
        return $response;
    }

    /**
     * Collects every warning the controller and the gate log.
     */
    private function captureWarnings(): \stdClass
    {
        $warnings = new \stdClass();
        $warnings->list = [];
        $this->loggerMock->method('warning')->willReturnCallback(function ($message) use ($warnings) {
            $warnings->list[] = (string)$message;
        });
        return $warnings;
    }

    /**
     * Invoice service and collection that count invoices, so a second delivery
     * sees the invoice the first one made.
     *
     * @param int|null $invoiceCount set to the number of invoices created
     * @return InvoiceService|MockObject
     */
    private function wireInvoicing(&$invoiceCount)
    {
        $invoiceCount = 0;
        $invoice = $this->getMockBuilder(\Magento\Sales\Model\Order\Invoice::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['register', 'getOrder'])
            ->getMock();
        $invoice->method('register')->willReturnSelf();
        $invoice->method('getOrder')->willReturn(
            $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods([])->getMock()
        );

        $invoiceService = $this->getMockBuilder(InvoiceService::class)->disableOriginalConstructor()->getMock();
        $invoiceService->method('prepareInvoice')->willReturnCallback(function () use (&$invoiceCount, $invoice) {
            $invoiceCount++;
            return $invoice;
        });
        $this->set('_invoiceService', $invoiceService);

        $saveTransaction = $this->getMockBuilder(\Magento\Framework\DB\Transaction::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addObject', 'save'])
            ->getMock();
        $saveTransaction->method('addObject')->willReturnSelf();
        $saveTransaction->method('save')->willReturnSelf();
        $transactionFactory = $this->getMockBuilder(TransactionFactory::class)->disableOriginalConstructor()->getMock();
        $transactionFactory->method('create')->willReturn($saveTransaction);
        $this->set('_transactionFactory', $transactionFactory);

        $select = $this->getMockBuilder(\Magento\Framework\DB\Select::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['limit'])
            ->getMock();
        $select->method('limit')->willReturnSelf();
        $collection = $this->getMockBuilder(\Magento\Sales\Model\ResourceModel\Order\Invoice\Collection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('count')->willReturnCallback(function () use (&$invoiceCount) {
            return $invoiceCount;
        });
        $collection->method('getSelect')->willReturn($select);
        $this->invoiceCollectionFactoryMock->method('create')->willReturn($collection);

        return $invoiceService;
    }

    /**
     * Invoice collection with the given number of invoices on the order.
     */
    private function setupInvoiceCollection(int $count, int $invoiceId = 0): void
    {
        $select = $this->getMockBuilder(\Magento\Framework\DB\Select::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['limit'])
            ->getMock();
        $select->method('limit')->willReturnSelf();

        $collection = $this->getMockBuilder(\Magento\Sales\Model\ResourceModel\Order\Invoice\Collection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('count')->willReturn($count);
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getFirstItem')->willReturn(new \Magento\Framework\DataObject(['id' => $invoiceId]));
        $this->invoiceCollectionFactoryMock->method('create')->willReturn($collection);
    }
}
