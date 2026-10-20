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
    }

    /**
     * The plugin with shipCloudLog() stubbed, so no HTTPS call is made. Each call is recorded
     * in $shipped as event, quote_id and message. To test shipCloudLog()'s own try/catch, use
     * makePluginWithShipper().
     *
     * @return QuoteSubmitLoggerPlugin|MockObject
     */
    private function makePlugin(array &$shipped)
    {
        $plugin = $this->getMockBuilder(QuoteSubmitLoggerPlugin::class)
            ->setConstructorArgs([$this->loggerMock])
            ->onlyMethods(['shipCloudLog'])
            ->getMock();

        $plugin->method('shipCloudLog')
            ->willReturnCallback(function ($eventType, $quoteId, $message) use (&$shipped) {
                $shipped[] = ['event' => $eventType, 'quote_id' => $quoteId, 'message' => $message];
            });

        return $plugin;
    }

    /**
     * A real plugin with a test-controlled cloudShipper, so shipCloudLog()'s try/catch runs.
     */
    private function makePluginWithShipper(callable $cloudShipper): QuoteSubmitLoggerPlugin
    {
        return new QuoteSubmitLoggerPlugin($this->loggerMock, $cloudShipper);
    }

    /** @return Quote|MockObject */
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
        $shipped = [];
        $plugin = $this->makePlugin($shipped);
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

        $result = $plugin->aroundSubmit($this->subjectMock, $proceed, $quote, []);

        $this->assertTrue($proceedCalled);
        $this->assertSame($orderMock, $result);
        $this->assertCount(0, $shipped);
    }

    public function testPaystandQuoteLogsEnteredAndCompletedOnSuccess(): void
    {
        $shipped = [];
        $plugin = $this->makePlugin($shipped);
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

        $result = $plugin->aroundSubmit($this->subjectMock, $proceed, $quote, []);

        $this->assertSame($orderMock, $result);
        $this->assertCount(2, $infoMessages);
        $this->assertStringContainsString('PAYSTAND-PLACEORDER-ENTERED', $infoMessages[0]);
        $this->assertStringContainsString('quote_id=123456', $infoMessages[0]);
        $this->assertStringContainsString('PAYSTAND-PLACEORDER-COMPLETED', $infoMessages[1]);
        $this->assertStringContainsString('order_id=000000123', $infoMessages[1]);

        // request_id must be present and consistent across both log lines
        $this->assertMatchesRegularExpression('/request_id=psq_[a-z0-9.]+/', $infoMessages[0]);
        preg_match('/request_id=(psq_[a-z0-9.]+)/', $infoMessages[0], $enteredMatch);
        preg_match('/request_id=(psq_[a-z0-9.]+)/', $infoMessages[1], $completedMatch);
        $this->assertSame($enteredMatch[1], $completedMatch[1]);

        $this->assertCount(2, $shipped);
        $this->assertSame(\PayStand\PayStandMagento\Helper\CloudLogger::EVENT_PLACEORDER_ENTERED, $shipped[0]['event']);
        $this->assertSame(\PayStand\PayStandMagento\Helper\CloudLogger::EVENT_PLACEORDER_COMPLETED, $shipped[1]['event']);
    }

    public function testPaystandQuoteLogsExceptionAndRethrowsOnFailure(): void
    {
        $shipped = [];
        $plugin = $this->makePlugin($shipped);
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
            $plugin->aroundSubmit($this->subjectMock, $proceed, $quote, []);
        } finally {
            // Entered log still fires before proceed() throws
            $this->assertCount(1, $infoMessages);
            $this->assertStringContainsString('PAYSTAND-PLACEORDER-ENTERED', $infoMessages[0]);

            $this->assertCount(2, $errorMessages);
            $this->assertStringContainsString('PAYSTAND-PLACEORDER-SUBMIT-EXCEPTION', $errorMessages[0]);
            $this->assertStringContainsString('simulated placeOrder failure', $errorMessages[0]);
            $this->assertStringContainsString('trace:', $errorMessages[1]);

            $this->assertCount(2, $shipped);
            $this->assertSame(\PayStand\PayStandMagento\Helper\CloudLogger::EVENT_PLACEORDER_ENTERED, $shipped[0]['event']);
            $this->assertSame(\PayStand\PayStandMagento\Helper\CloudLogger::EVENT_PLACEORDER_EXCEPTION, $shipped[1]['event']);
        }
    }

    public function testProceedReturningNullShipsNullResultNotCompleted(): void
    {
        $shipped = [];
        $plugin = $this->makePlugin($shipped);
        $quote = $this->makeQuote('paystandmagento');

        // submit() returns null for a quote with no visible items; that is not COMPLETED.
        $proceed = function ($q, $orderData) {
            return null;
        };

        $infoMessages = [];
        $this->loggerMock->method('info')->willReturnCallback(function ($message) use (&$infoMessages) {
            $infoMessages[] = $message;
        });
        $this->loggerMock->expects($this->never())->method('error');

        $result = $plugin->aroundSubmit($this->subjectMock, $proceed, $quote, []);

        $this->assertNull($result);
        $this->assertCount(2, $infoMessages);
        $this->assertStringContainsString('PAYSTAND-PLACEORDER-ENTERED', $infoMessages[0]);
        $this->assertStringContainsString('PAYSTAND-PLACEORDER-NULL-RESULT', $infoMessages[1]);

        foreach ($infoMessages as $message) {
            $this->assertStringNotContainsString('PAYSTAND-PLACEORDER-COMPLETED', $message);
        }

        $this->assertCount(2, $shipped);
        $this->assertSame(\PayStand\PayStandMagento\Helper\CloudLogger::EVENT_PLACEORDER_ENTERED, $shipped[0]['event']);
        $this->assertSame(\PayStand\PayStandMagento\Helper\CloudLogger::EVENT_PLACEORDER_NULL_RESULT, $shipped[1]['event']);
        $this->assertNotSame(\PayStand\PayStandMagento\Helper\CloudLogger::EVENT_PLACEORDER_COMPLETED, $shipped[1]['event']);
    }

    public function testMissingPaymentDoesNotThrow(): void
    {
        $shipped = [];
        $plugin = $this->makePlugin($shipped);

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

        $result = $plugin->aroundSubmit($this->subjectMock, $proceed, $quoteMock, []);

        $this->assertTrue($proceedCalled);
        $this->assertSame($orderMock, $result);
        $this->assertCount(0, $shipped);
    }

    /**
     * The plugin runs for every payment method, so isPaystandQuote() catches \Throwable.
     * An \Error from getPayment() must not break checkout.
     */
    public function testGetPaymentThrowingErrorIsCaughtAndTreatedAsNonPaystand(): void
    {
        $shipped = [];
        $plugin = $this->makePlugin($shipped);

        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getPayment'])
            ->addMethods(['getCustomerId', 'getGrandTotal'])
            ->getMock();
        $quoteMock->method('getId')->willReturn('555');
        $quoteMock->method('getPayment')->willThrowException(new \Error('undefined constant / fatal'));

        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();

        $proceedCalled = false;
        $proceed = function ($q, $orderData) use (&$proceedCalled, $orderMock) {
            $proceedCalled = true;
            return $orderMock;
        };

        $result = $plugin->aroundSubmit($this->subjectMock, $proceed, $quoteMock, []);

        $this->assertTrue($proceedCalled);
        $this->assertSame($orderMock, $result);
        $this->assertCount(0, $shipped);
    }

    /**
     * An \Error from getMethod() on a malformed payment object is also caught.
     */
    public function testGetMethodThrowingErrorIsCaughtAndTreatedAsNonPaystand(): void
    {
        $shipped = [];
        $plugin = $this->makePlugin($shipped);

        $paymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->getMock();
        $paymentMock->method('getMethod')->willThrowException(new \Error('fatal in getMethod'));

        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getPayment'])
            ->addMethods(['getCustomerId', 'getGrandTotal'])
            ->getMock();
        $quoteMock->method('getId')->willReturn('556');
        $quoteMock->method('getPayment')->willReturn($paymentMock);

        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();

        $proceedCalled = false;
        $proceed = function ($q, $orderData) use (&$proceedCalled, $orderMock) {
            $proceedCalled = true;
            return $orderMock;
        };

        $result = $plugin->aroundSubmit($this->subjectMock, $proceed, $quoteMock, []);

        $this->assertTrue($proceedCalled);
        $this->assertSame($orderMock, $result);
        $this->assertCount(0, $shipped);
    }

    /**
     * If reading getId, getCustomerId or getGrandTotal throws, proceed() still runs and its
     * result is returned unchanged.
     */
    public function testMetadataExtractionFailureDoesNotBlockProceed(): void
    {
        $shipped = [];
        $plugin = $this->makePlugin($shipped);

        $paymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->getMock();
        $paymentMock->method('getMethod')->willReturn('paystandmagento');

        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getPayment'])
            ->addMethods(['getCustomerId', 'getGrandTotal'])
            ->getMock();
        $quoteMock->method('getPayment')->willReturn($paymentMock);
        $quoteMock->method('getId')->willThrowException(new \RuntimeException('quote id lookup failed'));

        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIncrementId'])
            ->getMock();
        $orderMock->method('getIncrementId')->willReturn('000000999');

        $proceedCalled = false;
        $proceed = function ($q, $orderData) use (&$proceedCalled, $orderMock) {
            $proceedCalled = true;
            return $orderMock;
        };

        $result = $plugin->aroundSubmit($this->subjectMock, $proceed, $quoteMock, []);

        $this->assertTrue($proceedCalled);
        $this->assertSame($orderMock, $result);
    }

    /**
     * A logger failure does not stop proceed() and does not lose an order already created.
     */
    public function testLoggerFailureDoesNotBlockProceedOrDiscardOrder(): void
    {
        $shipped = [];
        $plugin = $this->makePlugin($shipped);
        $quote = $this->makeQuote('paystandmagento');

        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIncrementId'])
            ->getMock();
        $orderMock->method('getIncrementId')->willReturn('000000777');

        $proceedCalled = false;
        $proceed = function ($q, $orderData) use (&$proceedCalled, $orderMock) {
            $proceedCalled = true;
            return $orderMock;
        };

        $this->loggerMock->method('info')->willThrowException(new \RuntimeException('logger backend down'));

        $result = $plugin->aroundSubmit($this->subjectMock, $proceed, $quote, []);

        $this->assertTrue($proceedCalled);
        $this->assertSame($orderMock, $result);
    }

    /**
     * The other tests stub shipCloudLog(). This one injects a shipper that throws, so the
     * real try/catch in shipCloudLog() is tested.
     */
    public function testShipCloudLogSwallowsShipperFailureAndNeverBlocksProceed(): void
    {
        $shipperCalls = [];
        $throwingShipper = function ($eventType, $context) use (&$shipperCalls) {
            $shipperCalls[] = ['event' => $eventType, 'context' => $context];
            throw new \Error('CloudLogger ingest Worker unreachable');
        };

        $plugin = $this->makePluginWithShipper($throwingShipper);
        $quote = $this->makeQuote('paystandmagento');

        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIncrementId'])
            ->getMock();
        $orderMock->method('getIncrementId')->willReturn('000000321');

        $proceedCalled = false;
        $proceed = function ($q, $orderData) use (&$proceedCalled, $orderMock) {
            $proceedCalled = true;
            return $orderMock;
        };

        // ENTERED and COMPLETED both ship, and the shipper throws on each call.
        $result = $plugin->aroundSubmit($this->subjectMock, $proceed, $quote, []);

        $this->assertTrue($proceedCalled);
        $this->assertSame($orderMock, $result);

        // The shipper ran, so the try/catch is what stopped the failure.
        $this->assertCount(2, $shipperCalls);
        $this->assertSame(
            \PayStand\PayStandMagento\Helper\CloudLogger::EVENT_PLACEORDER_ENTERED,
            $shipperCalls[0]['event']
        );
        $this->assertSame(
            \PayStand\PayStandMagento\Helper\CloudLogger::EVENT_PLACEORDER_COMPLETED,
            $shipperCalls[1]['event']
        );
    }
}
