<?php

namespace PayStand\PayStandMagento\Test\Unit\Plugin;

use PayStand\PayStandMagento\Plugin\QuoteSubmitLoggerPlugin;
use Magento\Quote\Model\QuoteManagement;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class QuoteSubmitLoggerPluginTest extends TestCase
{
    /** @var QuoteSubmitLoggerPlugin */
    protected $plugin;

    /** @var LoggerInterface|MockObject */
    protected $loggerMock;

    /** @var QuoteManagement|MockObject */
    protected $subjectMock;

    protected function setUp(): void
    {
        $this->loggerMock = $this->getMockBuilder(LoggerInterface::class)
            ->getMockForAbstractClass();

        $this->subjectMock = $this->getMockBuilder(QuoteManagement::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->plugin = new QuoteSubmitLoggerPlugin($this->loggerMock);
    }

    /**
     * @param string $method
     * @return Quote|MockObject
     */
    private function makeQuote(string $method)
    {
        $paymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->getMock();
        $paymentMock->method('getMethod')->willReturn($method);

        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getPayment'])
            ->addMethods(['getCustomerId', 'getGrandTotal'])
            ->getMock();
        $quoteMock->method('getId')->willReturn('123456');
        $quoteMock->method('getCustomerId')->willReturn('789');
        $quoteMock->method('getGrandTotal')->willReturn(42.50);
        $quoteMock->method('getPayment')->willReturn($paymentMock);

        return $quoteMock;
    }

    public function testNonPaystandQuoteSkipsLoggingAndCallsProceedDirectly(): void
    {
        $quote = $this->makeQuote('checkmo');

        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();

        $proceedCalled = false;
        $proceed = function ($q, $orderData) use (&$proceedCalled, $orderMock) {
            $proceedCalled = true;
            return $orderMock;
        };

        $this->loggerMock->expects($this->never())->method('info');
        $this->loggerMock->expects($this->never())->method('error');

        $result = $this->plugin->aroundSubmit($this->subjectMock, $proceed, $quote, []);

        $this->assertTrue($proceedCalled);
        $this->assertSame($orderMock, $result);
    }

    public function testPaystandQuoteLogsEnteredAndCompletedOnSuccess(): void
    {
        $quote = $this->makeQuote('paystandmagento');

        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIncrementId'])
            ->getMock();
        $orderMock->method('getIncrementId')->willReturn('000000123');

        $proceed = function ($q, $orderData) use ($orderMock) {
            return $orderMock;
        };

        $infoMessages = [];
        $this->loggerMock->method('info')->willReturnCallback(function ($message) use (&$infoMessages) {
            $infoMessages[] = $message;
        });
        $this->loggerMock->expects($this->never())->method('error');

        $result = $this->plugin->aroundSubmit($this->subjectMock, $proceed, $quote, []);

        $this->assertSame($orderMock, $result);
        $this->assertCount(2, $infoMessages);
        $this->assertStringContainsString('PAYSTAND-PLACEORDER-ENTERED', $infoMessages[0]);
        $this->assertStringContainsString('quote_id=123456', $infoMessages[0]);
        $this->assertStringContainsString('PAYSTAND-PLACEORDER-COMPLETED', $infoMessages[1]);
        $this->assertStringContainsString('order_id=000000123', $infoMessages[1]);
    }

    public function testPaystandQuoteLogsExceptionAndRethrowsOnFailure(): void
    {
        $quote = $this->makeQuote('paystandmagento');

        $thrown = new \RuntimeException('simulated placeOrder failure');
        $proceed = function ($q, $orderData) use ($thrown) {
            throw $thrown;
        };

        $infoMessages = [];
        $errorMessages = [];
        $this->loggerMock->method('info')->willReturnCallback(function ($message) use (&$infoMessages) {
            $infoMessages[] = $message;
        });
        $this->loggerMock->method('error')->willReturnCallback(function ($message) use (&$errorMessages) {
            $errorMessages[] = $message;
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('simulated placeOrder failure');

        try {
            $this->plugin->aroundSubmit($this->subjectMock, $proceed, $quote, []);
        } finally {
            // Entered log still fires before proceed() throws
            $this->assertCount(1, $infoMessages);
            $this->assertStringContainsString('PAYSTAND-PLACEORDER-ENTERED', $infoMessages[0]);

            $this->assertCount(2, $errorMessages);
            $this->assertStringContainsString('PAYSTAND-PLACEORDER-SUBMIT-EXCEPTION', $errorMessages[0]);
            $this->assertStringContainsString('simulated placeOrder failure', $errorMessages[0]);
            $this->assertStringContainsString('trace:', $errorMessages[1]);
        }
    }

    public function testMissingPaymentDoesNotThrow(): void
    {
        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getPayment'])
            ->addMethods(['getCustomerId', 'getGrandTotal'])
            ->getMock();
        $quoteMock->method('getId')->willReturn('999');
        $quoteMock->method('getPayment')->willReturn(null);

        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();

        $proceedCalled = false;
        $proceed = function ($q, $orderData) use (&$proceedCalled, $orderMock) {
            $proceedCalled = true;
            return $orderMock;
        };

        $result = $this->plugin->aroundSubmit($this->subjectMock, $proceed, $quoteMock, []);

        $this->assertTrue($proceedCalled);
        $this->assertSame($orderMock, $result);
    }
}
