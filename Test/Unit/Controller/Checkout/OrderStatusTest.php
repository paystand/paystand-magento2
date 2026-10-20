<?php

namespace PayStand\PayStandMagento\Test\Unit\Controller\Checkout;

use PayStand\PayStandMagento\Controller\Checkout\OrderStatus;
use PayStand\PayStandMagento\Helper\QuoteAccess;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;

/**
 * Tests for OrderStatus::execute(). QuoteAccess is mocked; QuoteAccessTest covers it.
 * Unknown or unauthorized quotes get the generic response.
 */
class OrderStatusTest extends TestCase
{
    /** @var OrderStatus|MockObject */
    private $controller;

    /** @var HttpRequest|MockObject */
    private $requestMock;

    /** @var JsonResult|MockObject */
    private $jsonResultMock;

    /** @var QuoteAccess|MockObject */
    private $quoteAccessMock;

    /** @var SessionManagerInterface|MockObject */
    private $sessionManagerMock;

    /** @var LoggerInterface|MockObject */
    private $loggerMock;

    /** @var array|null Last payload passed to the JSON result's setData(). */
    private $captured;

    /** @var string[] Order in which the session read, close and order lookup ran. */
    private $sequence;

    protected function setUp(): void
    {
        $this->captured = null;
        $this->sequence = [];

        $this->requestMock = $this->getMockBuilder(HttpRequest::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->jsonResultMock = $this->getMockBuilder(JsonResult::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->jsonResultMock->method('setHttpResponseCode')->willReturnSelf();
        $this->jsonResultMock->method('setData')->willReturnCallback(function ($data) {
            $this->captured = $data;
            return $this->jsonResultMock;
        });

        $jsonResultFactoryMock = $this->getMockBuilder(JsonFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $jsonResultFactoryMock->method('create')->willReturn($this->jsonResultMock);

        $this->quoteAccessMock = $this->getMockBuilder(QuoteAccess::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->loggerMock = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();

        $this->sessionManagerMock = $this->getMockBuilder(SessionManagerInterface::class)
            ->getMockForAbstractClass();
        $this->sessionManagerMock->method('writeClose')->willReturnCallback(function () {
            $this->sequence[] = 'writeClose';
        });

        $this->controller = $this->getMockBuilder(OrderStatus::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $this->set('logger',            $this->loggerMock);
        $this->set('resultJsonFactory', $jsonResultFactoryMock);
        $this->set('quoteAccess',       $this->quoteAccessMock);
        $this->set('sessionManager',    $this->sessionManagerMock);
        $this->set('_request',          $this->requestMock);
    }

    public function testReturnsErrorWhenQuoteIdMissing(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn(null);

        $this->controller->execute();

        $this->assertIsArray($this->captured);
        $this->assertFalse($this->captured['success']);
        $this->assertSame('Missing quote id', $this->captured['error']);
    }

    /**
     * Same response as "no order yet", so sequential ids leak nothing.
     */
    public function testFailsClosedWhenQuoteNotAuthorized(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn('4189563');
        $this->quoteAccessMock->method('getAuthorizedQuote')->willReturn(null);
        $this->quoteAccessMock->expects($this->never())->method('findOrderByQuoteId');

        $this->controller->execute();

        $this->assertTrue($this->captured['success']);
        $this->assertFalse($this->captured['orderExists']);
        $this->assertNull($this->captured['incrementId']);
    }

    public function testReturnsOrderExistsTrueWhenOrderFound(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn('4189563');

        $quoteMock = $this->buildQuoteMock(4189563);
        $this->quoteAccessMock->method('getAuthorizedQuote')->with('4189563')->willReturn($quoteMock);

        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderMock->method('getId')->willReturn(42);
        $orderMock->method('getIncrementId')->willReturn('W001369548');
        $this->quoteAccessMock->method('findOrderByQuoteId')->with(4189563)->willReturn($orderMock);

        $this->controller->execute();

        $this->assertTrue($this->captured['success']);
        $this->assertTrue($this->captured['orderExists']);
        $this->assertSame('W001369548', $this->captured['incrementId']);
    }

    public function testReturnsOrderExistsFalseWhenNoOrderForQuote(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn('4189563');

        $quoteMock = $this->buildQuoteMock(4189563);
        $this->quoteAccessMock->method('getAuthorizedQuote')->willReturn($quoteMock);
        $this->quoteAccessMock->method('findOrderByQuoteId')->willReturn(null);

        $this->controller->execute();

        $this->assertTrue($this->captured['success']);
        $this->assertFalse($this->captured['orderExists']);
        $this->assertNull($this->captured['incrementId']);
    }

    public function testMaskedGuestQuoteIdIsPassedToQuoteAccess(): void
    {
        $maskedId = 'abc123maskedguestid456';
        $this->requestMock->method('getParam')->with('quote')->willReturn($maskedId);

        $quoteMock = $this->buildQuoteMock(777);
        $this->quoteAccessMock->expects($this->once())
            ->method('getAuthorizedQuote')
            ->with($maskedId)
            ->willReturn($quoteMock);
        $this->quoteAccessMock->method('findOrderByQuoteId')->with(777)->willReturn(null);

        $this->controller->execute();

        $this->assertTrue($this->captured['success']);
        $this->assertFalse($this->captured['orderExists']);
    }

    // ── Session release ──────────────────────────────────────────────────────

    /**
     * The endpoint is polled while placeOrder runs, so it must not hold the session open.
     */
    public function testClosesTheSessionOnTheAuthorizedPath(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn('4189563');
        $this->quoteAccessMock->method('getAuthorizedQuote')->willReturn($this->buildQuoteMock(4189563));
        $this->quoteAccessMock->method('findOrderByQuoteId')->willReturn(null);

        $this->controller->execute();

        $this->assertSame(1, $this->countWriteCloses());
    }

    public function testClosesTheSessionWhenQuoteIdIsMissing(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn(null);

        $this->controller->execute();

        $this->assertSame(1, $this->countWriteCloses());
        $this->assertSame('Missing quote id', $this->captured['error']);
    }

    /**
     * The order lookup is the slow part of the request.
     */
    public function testClosesTheSessionAfterAuthorizingAndBeforeTheOrderLookup(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn('4189563');

        $quoteMock = $this->buildQuoteMock(4189563);
        $this->quoteAccessMock->method('getAuthorizedQuote')->willReturnCallback(function () use ($quoteMock) {
            $this->sequence[] = 'authorize';
            return $quoteMock;
        });
        $this->quoteAccessMock->method('findOrderByQuoteId')->willReturnCallback(function () {
            $this->sequence[] = 'findOrder';
            return null;
        });

        $this->controller->execute();

        $this->assertSame(['authorize', 'writeClose', 'findOrder'], $this->sequence);
    }

    public function testAFailedSessionCloseIsLoggedAndStillAnswers(): void
    {
        $this->sessionManagerMock = $this->getMockBuilder(SessionManagerInterface::class)
            ->getMockForAbstractClass();
        $this->sessionManagerMock->method('writeClose')
            ->willThrowException(new \RuntimeException('redis gone'));
        $this->set('sessionManager', $this->sessionManagerMock);

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Could not close session'));

        $this->requestMock->method('getParam')->with('quote')->willReturn('4189563');
        $this->quoteAccessMock->method('getAuthorizedQuote')->willReturn($this->buildQuoteMock(4189563));
        $this->quoteAccessMock->method('findOrderByQuoteId')->willReturn(null);

        $this->controller->execute();

        $this->assertTrue($this->captured['success']);
        $this->assertFalse($this->captured['orderExists']);
    }

    private function countWriteCloses(): int
    {
        return count(array_filter($this->sequence, function ($event) {
            return $event === 'writeClose';
        }));
    }

    /** @return Quote|MockObject */
    private function buildQuoteMock(int $id)
    {
        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $quoteMock->method('getId')->willReturn($id);
        return $quoteMock;
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
