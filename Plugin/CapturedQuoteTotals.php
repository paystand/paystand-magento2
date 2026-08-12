<?php

namespace PayStand\PayStandMagento\Plugin;

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

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
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

            // Totals were final when the card was charged.
            $subject->setTotalsCollectedFlag(true);
            $this->logger->debug(
                'PAYSTAND-CAPTURED-TOTALS: skipped collection for captured quote ' . $subject->getId()
                . ' status=' . $subject->getData('paystand_capture_status')
            );
        } catch (\Throwable $e) {
            // Leaving the flag alone lets totals collect as they normally would.
            $this->logger->error('PAYSTAND-CAPTURED-TOTALS: ' . $e->getMessage());
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
}
