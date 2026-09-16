<?php

namespace PayStand\PayStandMagento\Plugin;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use PayStand\PayStandMagento\Helper\CaptureSnapshot;
use Psr\Log\LoggerInterface;

/**
 * Refuses placeOrder when the cart no longer matches the cart Paystand captured.
 * Magento would otherwise create an order at a different total than the charge.
 *
 * Quotes captured before paystand_capture_snapshot existed have no snapshot;
 * those still submit so a missing column cannot strand paid carts.
 */
class CapturedQuoteSubmit
{
    /** @var LoggerInterface */
    private $logger;

    /** @var CaptureSnapshot */
    private $snapshot;

    public function __construct(LoggerInterface $logger, CaptureSnapshot $snapshot)
    {
        $this->logger = $logger;
        $this->snapshot = $snapshot;
    }

    /**
     * @param QuoteManagement $subject
     * @param callable $proceed
     * @param Quote $quote
     * @param array $orderData
     * @return \Magento\Sales\Api\Data\OrderInterface|\Magento\Sales\Model\Order|null
     * @throws LocalizedException
     */
    public function aroundSubmit(QuoteManagement $subject, callable $proceed, Quote $quote, $orderData = [])
    {
        $this->refuseIfCartChanged($quote);
        return $proceed($quote, $orderData);
    }

    /**
     * @param Quote $quote
     * @return void
     * @throws LocalizedException
     */
    private function refuseIfCartChanged($quote): void
    {
        $mismatch = false;
        try {
            if (!$this->snapshot->isCaptured($quote)) {
                return;
            }

            $payload = $this->snapshot->read($quote);
            if (!$payload) {
                return;
            }

            if ($this->snapshot->matches($quote, $payload)) {
                return;
            }

            $mismatch = true;
        } catch (\Throwable $e) {
            $quoteId = 'unknown';
            try {
                $quoteId = $quote ? $quote->getId() : 'unknown';
            } catch (\Throwable $ignored) {
                $quoteId = 'unknown';
            }
            $this->logger->error(
                'PAYSTAND-CAPTURED-SUBMIT: check failed for quote ' . $quoteId
                . ', submit continues: ' . $e->getMessage()
            );
            return;
        }

        if (!$mismatch) {
            return;
        }

        $quoteId = 'unknown';
        try {
            $quoteId = $quote->getId();
        } catch (\Throwable $ignored) {
            $quoteId = 'unknown';
        }

        $this->logger->error(
            'PAYSTAND-CAPTURED-SUBMIT: cart changed after capture; refusing placeOrder for quote ' . $quoteId
        );

        throw new LocalizedException(
            new Phrase(
                'The cart changed after payment was captured. Do not place this order. Contact support.'
            )
        );
    }
}
