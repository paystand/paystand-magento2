<?php

namespace PayStand\PayStandMagento\Test\Unit\Controller\Checkout;

use PayStand\PayStandMagento\Controller\Checkout\QuotePaymentStatus;
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
 * Unit tests for Controller\Checkout\QuotePaymentStatus::execute().
 *
 * The duplicate-payment guard endpoint checked before the Paystand widget opens.
 * Quote resolution + session authorization are delegated to QuoteAccess (mocked
 * here; covered directly by QuoteAccessTest).
 */
class QuotePaymentStatusTest extends TestCase
{
    /** @var QuotePaymentStatus|MockObject */
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

    /** @var array|null */
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

        $this->controller = $this->getMockBuilder(QuotePaymentStatus::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $this->set('logger',            $this->loggerMock);
        $this->set('resultJsonFactory', $jsonResultFactoryMock);
        $this->set('quoteAccess',       $this->quoteAccessMock);
        $this->set('sessionManager',    $this->sessionManagerMock);
        $this->set('_request',          $this->requestMock);
    }

    public function testFailsOpenWhenQuoteIdMissing(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn(null);

        $this->controller->execute();

        $this->assertTrue($this->captured['success']);
        $this->assertFalse($this->captured['alreadyPaid']);
    }

    /**
     * Unauthorized (or unknown) quote gets the identical generic "not paid"
     * response: fail closed for information disclosure, fail open for the
     * legitimate first payment.
     */
    public function testFailsClosedWhenQuoteNotAuthorized(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn('4189563');
        $this->quoteAccessMock->method('getAuthorizedQuote')->willReturn(null);
        $this->quoteAccessMock->expects($this->never())->method('findOrderByQuoteId');

        $this->controller->execute();

        $this->assertTrue($this->captured['success']);
        $this->assertFalse($this->captured['alreadyPaid']);
        $this->assertNull($this->captured['paymentId']);
        $this->assertFalse($this->captured['orderExists']);
        $this->assertNull($this->captured['incrementId']);
    }

    public function testAlreadyPaidWhenQuoteHasRecordedPaymentId(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn('4189563');

        $quoteMock = $this->buildQuoteMock(4189563, 'lrawr60sjqaklyui6a84tcvh');
        $this->quoteAccessMock->method('getAuthorizedQuote')->willReturn($quoteMock);

        // No order yet for this quote — the paid-but-orderless case.
        $this->quoteAccessMock->method('findOrderByQuoteId')->willReturn(null);

        $this->controller->execute();

        $this->assertTrue($this->captured['alreadyPaid']);
        $this->assertSame('lrawr60sjqaklyui6a84tcvh', $this->captured['paymentId']);
        $this->assertFalse($this->captured['orderExists']);
    }

    public function testAlreadyPaidWhenOrderExistsEvenWithoutRecordedPaymentId(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn('4189563');

        $quoteMock = $this->buildQuoteMock(4189563, null);
        $this->quoteAccessMock->method('getAuthorizedQuote')->willReturn($quoteMock);

        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderMock->method('getId')->willReturn(7);
        $orderMock->method('getIncrementId')->willReturn('W001369548');
        $this->quoteAccessMock->method('findOrderByQuoteId')->willReturn($orderMock);

        $this->controller->execute();

        $this->assertTrue($this->captured['alreadyPaid']);
        $this->assertTrue($this->captured['orderExists']);
        $this->assertSame('W001369548', $this->captured['incrementId']);
    }

    public function testNotPaidWhenNoPaymentAndNoOrder(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn('4189563');

        $quoteMock = $this->buildQuoteMock(4189563, null);
        $this->quoteAccessMock->method('getAuthorizedQuote')->willReturn($quoteMock);
        $this->quoteAccessMock->method('findOrderByQuoteId')->willReturn(null);

        $this->controller->execute();

        $this->assertTrue($this->captured['success']);
        $this->assertFalse($this->captured['alreadyPaid']);
        $this->assertNull($this->captured['paymentId']);
        $this->assertFalse($this->captured['orderExists']);
    }

    /**
     * Masked guest ids pass straight through to QuoteAccess.
     */
    public function testMaskedGuestQuoteIdIsPassedToQuoteAccess(): void
    {
        $maskedId = 'abc123maskedguestid456';
        $this->requestMock->method('getParam')->with('quote')->willReturn($maskedId);

        $quoteMock = $this->buildQuoteMock(777, 'lrawr60sjqaklyui6a84tcvh');
        $this->quoteAccessMock->expects($this->once())
            ->method('getAuthorizedQuote')
            ->with($maskedId)
            ->willReturn($quoteMock);
        $this->quoteAccessMock->method('findOrderByQuoteId')->with(777)->willReturn(null);

        $this->controller->execute();

        $this->assertTrue($this->captured['alreadyPaid']);
        $this->assertSame('lrawr60sjqaklyui6a84tcvh', $this->captured['paymentId']);
    }

    // ── Session release ──────────────────────────────────────────────────────

    /**
     * The guard runs while a payment is being taken, so it must hand the session
     * back instead of holding it open until the response.
     */
    public function testClosesTheSessionOnTheAuthorizedPath(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn('4189563');
        $this->quoteAccessMock->method('getAuthorizedQuote')
            ->willReturn($this->buildQuoteMock(4189563, null));
        $this->quoteAccessMock->method('findOrderByQuoteId')->willReturn(null);

        $this->controller->execute();

        $this->assertSame(1, $this->countWriteCloses());
    }

    public function testClosesTheSessionWhenQuoteIdIsMissing(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn(null);

        $this->controller->execute();

        $this->assertSame(1, $this->countWriteCloses());
        $this->assertFalse($this->captured['alreadyPaid']);
    }

    /**
     * The close has to land after the session has been read for authorization
     * and before the order lookup, which is the slow part of the request.
     */
    public function testClosesTheSessionAfterAuthorizingAndBeforeTheOrderLookup(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn('4189563');

        $quoteMock = $this->buildQuoteMock(4189563, null);
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
        $this->quoteAccessMock->method('getAuthorizedQuote')
            ->willReturn($this->buildQuoteMock(4189563, null));
        $this->quoteAccessMock->method('findOrderByQuoteId')->willReturn(null);

        $this->controller->execute();

        $this->assertTrue($this->captured['success']);
        $this->assertFalse($this->captured['alreadyPaid']);
    }

    /**
     * @return int
     */
    private function countWriteCloses(): int
    {
        return count(array_filter($this->sequence, function ($event) {
            return $event === 'writeClose';
        }));
    }

    /**
     * @param int $id
     * @param string|null $paymentId
     * @return Quote|MockObject
     */
    private function buildQuoteMock(int $id, $paymentId)
    {
        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getData'])
            ->getMock();
        $quoteMock->method('getId')->willReturn($id);
        $quoteMock->method('getData')->with('paystand_payment_id')->willReturn($paymentId);
        return $quoteMock;
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
