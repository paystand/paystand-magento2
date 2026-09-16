<?php

namespace PayStand\PayStandMagento\Plugin;

use PayStand\PayStandMagento\Helper\CaptureFingerprint;
use PayStand\PayStandMagento\Helper\CloudLogger;
use Psr\Log\LoggerInterface;

/**
 * Stops totals being recollected on a quote Paystand has already captured.
 * Cart price rules are re-adjudicated on every collection, so a rule that stops
 * qualifying after capture would drop a discount the shopper already paid on and
 * leave the order billing higher than the amount taken.
 */
class CapturedQuoteTotals
{
    /** @var LoggerInterface */
    private $logger;

    /** @var CaptureFingerprint */
    private $fingerprint;

    public function __construct(LoggerInterface $logger, CaptureFingerprint $fingerprint)
    {
        $this->logger = $logger;
        $this->fingerprint = $fingerprint;
    }

    /**
     * Quote::collectTotals() returns early when the totals-collected flag is set,
     * so setting it here is all that is needed to skip the collection.
     *
     * @param \Magento\Quote\Model\Quote $subject
     * @return void
     */
    public function beforeCollectTotals($subject)
    {
        try {
            if (!$this->isCaptured($subject)) {
                return;
            }

            // The freeze covers the cart that was paid for. Once the shopper changes
            // that cart, holding it would keep stale shipping rates on the quote and
            // Magento could never price the new one.
            if (!$this->fingerprint->matchesCapture($subject)) {
                $this->releaseFreeze($subject);
                return;
            }

            // Totals were final when the card was charged.
            $subject->setTotalsCollectedFlag(true);
            $this->logger->debug(
                'PAYSTAND-CAPTURED-TOTALS: skipped collection for captured quote ' . $subject->getId()
                . ' status=' . $subject->getData('paystand_capture_status')
            );
        } catch (\Throwable $e) {
            // Fail open: leaving the flag alone collects totals as normal, so a broken
            // check cannot block checkout. The quote id is logged because that drifts.
            $quoteId = null;
            try {
                $quoteId = $subject ? $subject->getId() : null;
            } catch (\Throwable $ignored) {
                $quoteId = null;
            }
            $this->logger->error(
                'PAYSTAND-CAPTURED-TOTALS: check failed for quote ' . ($quoteId ?: 'unknown')
                . ', totals will collect normally: ' . $e->getMessage()
            );
        }
    }

    /**
     * Frozen only for a confirmed capture. The payment id alone is a broader
     * signal — it locks the widget against any reported payment, completed or
     * not — so freezing on it would strand a cart whose payment never landed.
     *
     * @param \Magento\Quote\Model\Quote $subject
     * @return bool
     */
    private function isCaptured($subject)
    {
        if (!$subject) {
            return false;
        }

        return !empty($subject->getData('paystand_payment_id'))
            && !empty($subject->getData('paystand_capture_status'));
    }

    /**
     * Leaves the markers in place: the money was taken and the payment id is still
     * the reconciliation anchor. Only the totals go live again, and the release is
     * reported because it means a capture no order will ever carry.
     *
     * @param \Magento\Quote\Model\Quote $subject
     * @return void
     */
    private function releaseFreeze($subject)
    {
        $quoteId = (string)$subject->getId();
        $paymentId = (string)$subject->getData('paystand_payment_id');

        $this->logger->warning(
            'PAYSTAND-CAPTURED-TOTALS: cart changed since capture, totals stay live for quote '
            . $quoteId . ' payment=' . $paymentId
        );

        $this->shipReleaseEvent($quoteId, $paymentId);
    }

    /**
     * Its own method so the release can be observed without shipping anything.
     *
     * @param string $quoteId
     * @param string $paymentId
     * @return void
     */
    protected function shipReleaseEvent($quoteId, $paymentId)
    {
        try {
            CloudLogger::ship(CloudLogger::EVENT_CAPTURE_FREEZE_RELEASED, [
                'quote_id'      => $quoteId,
                'payment_id'    => $paymentId,
                'error_message' => 'cart no longer matches the capture fingerprint, totals released',
            ]);
        } catch (\Throwable $e) {
            // CloudLogger failure — silently ignored to protect checkout
        }
    }
}
