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
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;

/**
 * Unit tests for Controller\Checkout\OrderStatus::execute().
 *
 * Quote resolution + session authorization are delegated to QuoteAccess (mocked
 * here; covered directly by QuoteAccessTest). These tests assert the controller
 * contract: fail closed with the generic response for unknown/unauthorized
 * quotes, report the order when authorized.
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

    /** @var array|null Last payload passed to the JSON result's setData(). */
    private $captured;

    protected function setUp(): void
    {
        $this->captured = null;

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

        $loggerMock = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();

        $this->controller = $this->getMockBuilder(OrderStatus::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $this->set('logger',            $loggerMock);
        $this->set('resultJsonFactory', $jsonResultFactoryMock);
        $this->set('quoteAccess',       $this->quoteAccessMock);
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
     * Unauthorized (or unknown) quote must produce the identical generic
     * response as "no order yet" — sequential ids leak nothing.
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

    /**
     * Masked guest ids pass straight through to QuoteAccess — the controller
     * must not require a numeric id.
     */
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

    /**
     * @param int $id
     * @return Quote|MockObject
     */
    private function buildQuoteMock(int $id)
    {
        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $quoteMock->method('getId')->willReturn($id);
        return $quoteMock;
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
