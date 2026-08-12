<?php

namespace PayStand\PayStandMagento\Test\Unit\Plugin;

use PayStand\PayStandMagento\Plugin\CapturedQuoteTotals;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Magento\Quote\Model\Quote;

/**
 * Unit tests for Plugin\CapturedQuoteTotals — keeps a recollection from
 * re-adjudicating cart price rules on a quote that has already been charged,
 * without stranding a cart whose payment never completed.
 *
 * Setting the totals-collected flag is the whole mechanism: Quote::collectTotals()
 * returns before collecting when it is set, so these tests assert on that call.
 */
class CapturedQuoteTotalsTest extends TestCase
{
    /** @var CapturedQuoteTotals */
    private $plugin;

    protected function setUp(): void
    {
        $this->plugin = new CapturedQuoteTotals(
            $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass()
        );
    }

    /**
     * @param string|null $paymentId
     * @param string|null $captureStatus
     */
    private function quoteWith($paymentId, $captureStatus): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'getId'])
            ->addMethods(['setTotalsCollectedFlag'])
            ->getMock();
        $quote->method('getId')->willReturn(4267713);
        $quote->method('getData')->willReturnMap([
            ['paystand_payment_id', null, $paymentId],
            ['paystand_capture_status', null, $captureStatus],
        ]);
        return $quote;
    }

    public function testConfirmedCaptureIsNotRecollected(): void
    {
        $quote = $this->quoteWith('nlvsnvr0ska9i7ugvoab9917', 'posted');

        $quote->expects($this->once())->method('setTotalsCollectedFlag')->with(true);

        $this->plugin->beforeCollectTotals($quote);
    }

    public function testPaidStatusAlsoFreezes(): void
    {
        $quote = $this->quoteWith('nlvsnvr0ska9i7ugvoab9917', 'paid');

        $quote->expects($this->once())->method('setTotalsCollectedFlag')->with(true);

        $this->plugin->beforeCollectTotals($quote);
    }

    /**
     * The regression this gating exists for: a payment id is recorded for any
     * payment the widget reports, so freezing on it alone would strand a cart
     * whose charge never completed, with no way to recollect its totals.
     */
    public function testPaymentIdWithoutConfirmedCaptureStillCollects(): void
    {
        $quote = $this->quoteWith('nlvsnvr0ska9i7ugvoab9917', null);

        $quote->expects($this->never())->method('setTotalsCollectedFlag');

        $this->plugin->beforeCollectTotals($quote);
    }

    public function testCaptureStatusWithoutPaymentIdStillCollects(): void
    {
        $quote = $this->quoteWith(null, 'posted');

        $quote->expects($this->never())->method('setTotalsCollectedFlag');

        $this->plugin->beforeCollectTotals($quote);
    }

    public function testUncapturedQuoteCollectsNormally(): void
    {
        $quote = $this->quoteWith(null, null);

        $quote->expects($this->never())->method('setTotalsCollectedFlag');

        $this->plugin->beforeCollectTotals($quote);
    }

    public function testEmptyStringsDoNotCountAsCaptured(): void
    {
        $quote = $this->quoteWith('', '');

        $quote->expects($this->never())->method('setTotalsCollectedFlag');

        $this->plugin->beforeCollectTotals($quote);
    }

    /**
     * A before plugin that throws would break the collection it precedes, so the
     * failure has to stay contained. Leaving the flag unset collects as normal.
     */
    public function testAFailingCheckIsContained(): void
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $quote->method('getData')->willThrowException(new \Error('boom'));

        $this->plugin->beforeCollectTotals($quote);

        $this->assertTrue(true, 'A failed check must not propagate out of the plugin');
    }
}
