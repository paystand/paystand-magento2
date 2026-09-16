<?php

namespace PayStand\PayStandMagento\Test\Unit\Plugin;

use PayStand\PayStandMagento\Helper\CaptureFingerprint;
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
        $this->plugin = $this->pluginWithMatch(true);
    }

    /**
     * The fingerprint check is stubbed: whether a quote still holds the cart it was
     * paid for is the helper's business, tested in CaptureFingerprintTest.
     */
    private function pluginWithMatch(bool $matches, bool $available = true): CapturedQuoteTotals
    {
        $fingerprint = $this->getMockBuilder(CaptureFingerprint::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['matchesCapture', 'isAvailable'])
            ->getMock();
        $fingerprint->method('matchesCapture')->willReturn($matches);
        $fingerprint->method('isAvailable')->willReturn($available);

        // Anonymous subclass: the release is observed, never shipped.
        return new class (
            $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass(),
            $fingerprint
        ) extends CapturedQuoteTotals {
            /** @var array<int, array<string, string>> */
            public $shipped = [];

            protected function shipReleaseEvent($quoteId, $paymentId)
            {
                $this->shipped[] = ['quote_id' => $quoteId, 'payment_id' => $paymentId];
            }
        };
    }

    /**
     * @param string|null $paymentId
     * @param string|null $captureStatus
     */
    private function quoteWith($paymentId, $captureStatus, int $quoteId = 4267713): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'getId'])
            ->addMethods(['setTotalsCollectedFlag'])
            ->getMock();
        $quote->method('getId')->willReturn($quoteId);
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

    /**
     * The regression this release exists for: a captured quote whose cart the shopper
     * then changed. Holding the freeze there keeps the paid cart's shipping rates on
     * a cart that no longer has them, and Magento can never price the new one.
     */
    public function testChangedCartCollectsAgain(): void
    {
        $plugin = $this->pluginWithMatch(false);
        $quote = $this->quoteWith('nlvsnvr0ska9i7ugvoab9917', 'posted');

        $quote->expects($this->never())->method('setTotalsCollectedFlag');

        $plugin->beforeCollectTotals($quote);
    }

    /**
     * Releasing means a capture no order will carry, so it has to be reported.
     */
    public function testReleaseIsReported(): void
    {
        $plugin = $this->pluginWithMatch(false);
        $quote = $this->quoteWith('nlvsnvr0ska9i7ugvoab9917', 'posted');

        $plugin->beforeCollectTotals($quote);

        $this->assertSame(
            [['quote_id' => '4267713', 'payment_id' => 'nlvsnvr0ska9i7ugvoab9917']],
            $plugin->shipped
        );
    }

    /**
     * A quote that was never captured is not reported: there is no capture to orphan.
     */
    public function testUncapturedQuoteIsNotReported(): void
    {
        $plugin = $this->pluginWithMatch(false);
        $quote = $this->quoteWith(null, null);

        $plugin->beforeCollectTotals($quote);

        $this->assertSame([], $plugin->shipped);
    }

    /**
     * The release event is a blocking call. collectTotals() runs several times in a
     * request and QuoteShipping clears the flag to force more, so reporting every
     * one would put seconds of blocking calls in checkout's path.
     */
    public function testReleaseIsReportedOncePerRequest(): void
    {
        $plugin = $this->pluginWithMatch(false);
        $quote = $this->quoteWith('nlvsnvr0ska9i7ugvoab9917', 'posted');

        $plugin->beforeCollectTotals($quote);
        $plugin->beforeCollectTotals($quote);
        $plugin->beforeCollectTotals($quote);

        $this->assertCount(1, $plugin->shipped, 'A released freeze must report once, not per collection');
    }

    /**
     * Two carts in one request are two orphaned captures, so each is still reported.
     */
    public function testEachQuoteIsReportedOnItsOwn(): void
    {
        $plugin = $this->pluginWithMatch(false);

        $plugin->beforeCollectTotals($this->quoteWith('pay-a', 'posted'));
        $plugin->beforeCollectTotals($this->quoteWith('pay-b', 'posted', 998877));

        $this->assertCount(2, $plugin->shipped);
    }

    /**
     * The deployment hazard: files deployed without setup:upgrade leave no column, so
     * no quote can carry a stamp and every capture would read as changed. That would
     * drop the discount protection across the whole site, so the freeze has to hold.
     */
    public function testMissingColumnHoldsTheFreeze(): void
    {
        $plugin = $this->pluginWithMatch(false, false);
        $quote = $this->quoteWith('nlvsnvr0ska9i7ugvoab9917', 'posted');

        $quote->expects($this->once())->method('setTotalsCollectedFlag')->with(true);

        $plugin->beforeCollectTotals($quote);
    }

    /**
     * Holding that freeze is not an orphaned capture, so it must not be reported.
     */
    public function testMissingColumnIsNotReportedAsARelease(): void
    {
        $plugin = $this->pluginWithMatch(false, false);

        $plugin->beforeCollectTotals($this->quoteWith('nlvsnvr0ska9i7ugvoab9917', 'posted'));

        $this->assertSame([], $plugin->shipped);
    }
}
