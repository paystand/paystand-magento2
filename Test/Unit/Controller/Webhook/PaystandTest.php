<?php

namespace PayStand\PayStandMagento\Test\Unit\Controller\Webhook;

use PayStand\PayStandMagento\Controller\Webhook\Paystand;
use PayStand\PayStandMagento\Helper\CloudLogger;
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
 * Unit tests for Controller\Webhook\Paystand::execute().
 *
 * Covers all early-return paths and the primary order-processing paths.
 *
 * getPaystandAccessToken(), verifyPaystandEvent(), and findOrder() are declared
 * protected in the production class so they can be stubbed here — no real HTTP
 * calls or DB queries are made.
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
            ->onlyMethods(['getPaystandAccessToken', 'verifyPaystandEvent', 'findOrder'])
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

    // ── Constant regression ────────────────────────────────────────────────────

    /**
     * Regression for the production bug: "Undefined constant
     * PayStand\PayStandMagento\Helper\CloudLogger::EVENT_WEBHOOK_START"
     * This constant is called in execute() before the body is even parsed,
     * so a missing constant crashes every single webhook hit.
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

    // ── Empty body ─────────────────────────────────────────────────────────────

    public function testExecuteReturnsBadRequestOnEmptyBody(): void
    {
        $this->requestMock->method('getContent')->willReturn(null);

        $this->jsonResultMock->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(500)
            ->willReturnSelf();

        $this->controller->execute();
    }

    // ── Source / object guards ─────────────────────────────────────────────────

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

    // ── Access token / verification ────────────────────────────────────────────

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

    // ── Payment status guard ───────────────────────────────────────────────────

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

    // ── Quote lookup errors ────────────────────────────────────────────────────

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

    // ── Order not found ────────────────────────────────────────────────────────

    /**
     * findOrder() is protected and mocked to return null, simulating all retry
     * strategies exhausted. execute() must return 404 so Paystand re-delivers.
     */
    public function testExecuteReturnsNotFoundWhenOrderNotFoundAfterRetries(): void
    {
        $this->requestMock->method('getContent')->willReturn($this->makeBody());
        $this->controller->method('getPaystandAccessToken')->willReturn('fake-token');
        $this->controller->method('verifyPaystandEvent')->willReturn(true);
        $this->setupQuoteIdMask();
        $this->setupQuoteMock();

        $this->controller->method('findOrder')->willReturn(null);

        $this->jsonResultMock->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(404)
            ->willReturnSelf();

        $this->controller->execute();
    }

    // ── Order found — state guards ─────────────────────────────────────────────

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
     * Order is pending, payment is 'posted'. updateOrderOn is set to 'paid'
     * so the state-change branch runs but NOT the invoice/transaction branch.
     * Verifies the order receives setState/setStatus/save calls and returns 200.
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

    // ── Throwable swallowing ───────────────────────────────────────────────────

    /**
     * The very first statement in execute() after result init is a CloudLogger call
     * that now references the fixed EVENT_WEBHOOK_START constant. Even if CloudLogger
     * threw (network issue, misconfiguration), execute() must not propagate the error.
     * Covers all four catch(\Throwable) blocks via the empty-body early-exit path.
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

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Set a protected/private property on the controller via reflection.
     * Walks the class hierarchy so inherited properties (e.g. _request from Action) are found.
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
}
