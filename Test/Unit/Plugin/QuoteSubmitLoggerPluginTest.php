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
     * Build the plugin with shipCloudLog() stubbed out so tests never make real
     * HTTPS calls to the CloudLogger ingest Worker. Records every shipped call
     * in $shipped for assertions.
     *
     * Use this for tests asserting WHAT gets shipped (event/quote_id/message).
     * For tests asserting shipCloudLog()'s own safety guarantee (that a
     * throwing shipper is swallowed), use makePluginWithShipper() instead —
     * mocking shipCloudLog() itself would bypass its try/catch entirely and
     * leave that guarantee unexercised.
     *
     * @param array $shipped Reference — populated with ['event' => ..., 'quote_id' => ..., 'message' => ...]
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
     * Build a real (non-mocked) plugin with an injected cloudShipper callable —
     * no real HTTPS call is made (the callable itself is test-controlled), but
     * shipCloudLog()'s own try/catch runs for real. Use this to prove that
     * guarantee actually holds, rather than assuming it because the code reads
     * that way.
     *
     * @return QuoteSubmitLoggerPlugin
     */
    private function makePluginWithShipper(callable $cloudShipper): QuoteSubmitLoggerPlugin
    {
        return new QuoteSubmitLoggerPlugin($this->loggerMock, $cloudShipper);
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

        // submit() legitimately returns null for a quote with no visible items —
        // a documented non-exceptional path that must NOT be logged as COMPLETED.
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
     * getPayment() throwing an \Error (not \Exception) must still be caught —
     * isPaystandQuote() catches \Throwable specifically so a fatal here can never
     * break checkout site-wide for every payment method (this plugin runs
     * unconditionally via etc/di.xml, gated only by isPaystandQuote()).
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
     * getMethod() throwing an \Error must also be caught — this is the call the
     * isPaystandQuote() try/catch actually guards (getPayment() succeeding but
     * getMethod() failing on a malformed payment object).
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
     * Metadata extraction (getId/getCustomerId/getGrandTotal) must never block
     * proceed() — even if it throws, $proceed() still runs and its result is
     * still returned untouched.
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
     * A logger failure (e.g. broken log backend) must never prevent proceed()
     * from running, and must never cause an already-created order to be lost.
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
     * shipCloudLog()'s own try/catch is the actual mechanism protecting checkout
     * from a CloudLogger failure — every other test in this file mocks
     * shipCloudLog() out entirely (correctly, to avoid real HTTP calls), which
     * means that try/catch itself was never exercised. This test injects a
     * cloudShipper callable that genuinely throws, going through the real
     * shipCloudLog() body, to prove the guarantee actually holds rather than
     * just reading that way in the source.
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

        // No exception should escape aroundSubmit() despite the shipper throwing
        // on every call (ENTERED and COMPLETED both attempt to ship).
        $result = $plugin->aroundSubmit($this->subjectMock, $proceed, $quote, []);

        $this->assertTrue($proceedCalled);
        $this->assertSame($orderMock, $result);

        // The throwing shipper really was invoked (not skipped) — proves the
        // try/catch is what's swallowing the failure, not test setup avoiding it.
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
