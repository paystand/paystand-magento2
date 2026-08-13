<?php

namespace PayStand\PayStandMagento\Test\Unit\Controller\Checkout;

use PayStand\PayStandMagento\Controller\Checkout\SavePaymentData;
use PayStand\PayStandMagento\Model\Config\Source\PaymentStatus;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the capture-status gate in Controller\Checkout\SavePaymentData —
 * the client-side writer of paystand_capture_status, which decides whether a quote's
 * totals are frozen against cart price rules being re-adjudicated after capture.
 *
 * The gate is deliberately narrower than the re-charge lock: that keys on the payment
 * id alone, while freezing totals needs a status confirming the money was taken.
 * Freezing on anything weaker would strand a cart whose payment never completed.
 */
class SavePaymentDataTest extends TestCase
{
    /** @var SavePaymentData */
    private $controller;

    protected function setUp(): void
    {
        $this->controller = $this->getMockBuilder(SavePaymentData::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    /**
     * @param string|null $paymentId
     * @param string|null $paymentStatus
     * @return string|null
     */
    private function gate($paymentId, $paymentStatus)
    {
        $method = new \ReflectionMethod(SavePaymentData::class, 'captureStatusFor');
        $method->setAccessible(true);
        return $method->invoke($this->controller, $paymentId, $paymentStatus);
    }

    public function testPostedFreezes(): void
    {
        $this->assertSame('posted', $this->gate('nlvsnvr0ska9i7ugvoab9917', 'posted'));
    }

    public function testPaidFreezes(): void
    {
        $this->assertSame('paid', $this->gate('nlvsnvr0ska9i7ugvoab9917', 'paid'));
    }

    /**
     * ACH sits in processing before the money lands, so it must not freeze.
     */
    public function testProcessingDoesNotFreeze(): void
    {
        $this->assertNull($this->gate('nlvsnvr0ska9i7ugvoab9917', 'processing'));
    }

    public function testFailedDoesNotFreeze(): void
    {
        $this->assertNull($this->gate('nlvsnvr0ska9i7ugvoab9917', 'failed'));
    }

    /**
     * The regression the gate exists for: a payment id is recorded for any payment
     * the widget reports, so a status-less request must leave the cart collecting.
     */
    public function testPaymentIdWithoutStatusDoesNotFreeze(): void
    {
        $this->assertNull($this->gate('nlvsnvr0ska9i7ugvoab9917', null));
    }

    public function testStatusWithoutPaymentIdDoesNotFreeze(): void
    {
        $this->assertNull($this->gate(null, 'posted'));
    }

    public function testEmptyStringsDoNotFreeze(): void
    {
        $this->assertNull($this->gate('', ''));
    }

    /**
     * The widget's status casing and padding are not guaranteed.
     */
    public function testStatusIsNormalisedBeforeMatching(): void
    {
        $this->assertSame('posted', $this->gate('nlvsnvr0ska9i7ugvoab9917', '  POSTED '));
    }

    /**
     * Both writers of the column read the same list, so the gate must stay in step
     * with the values the admin dropdown offers for updateOrderOn.
     */
    public function testGateAcceptsExactlyTheSharedCapturedStatuses(): void
    {
        $this->assertSame(PaymentStatus::CAPTURED_STATUSES, SavePaymentData::CAPTURED_STATUSES);

        foreach (PaymentStatus::CAPTURED_STATUSES as $status) {
            $this->assertSame($status, $this->gate('nlvsnvr0ska9i7ugvoab9917', $status));
        }
    }
}
