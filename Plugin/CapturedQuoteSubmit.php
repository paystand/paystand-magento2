<?php

namespace PayStand\PayStandMagento\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
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
 *
 * payment/paystandmagento/captured_cart_guard:
 * off      — do not check
 * log_only — log a mismatch and still submit (default)
 * refuse   — throw and block placeOrder
 */
class CapturedQuoteSubmit
{
    public const CONFIG_PATH = 'payment/paystandmagento/captured_cart_guard';
    public const MODE_OFF = 'off';
    public const MODE_LOG_ONLY = 'log_only';
    public const MODE_REFUSE = 'refuse';

    /** @var LoggerInterface */
    private $logger;

    /** @var CaptureSnapshot */
    private $snapshot;

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    public function __construct(
        LoggerInterface $logger,
        CaptureSnapshot $snapshot,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->logger = $logger;
        $this->snapshot = $snapshot;
        $this->scopeConfig = $scopeConfig;
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
        $stamped = '';
        $current = '';
        try {
            $mode = $this->guardMode();
            if ($mode === self::MODE_OFF) {
                return;
            }

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
            $stamped = (string)$payload['hash'];
            $current = $this->snapshot->hash($quote);
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

        $mode = $this->guardMode();
        $this->logger->error(
            'PAYSTAND-CAPTURED-SUBMIT: cart changed after capture; '
            . $mode
            . ' placeOrder for quote '
            . $quoteId
            . ' stamped=' . $stamped
            . ' current=' . $current
        );

        if ($mode !== self::MODE_REFUSE) {
            return;
        }

        throw new LocalizedException(
            new Phrase(
                'The cart changed after payment was captured. Do not place this order. Contact support.'
            )
        );
    }

    private function guardMode(): string
    {
        try {
            $mode = strtolower(trim((string)$this->scopeConfig->getValue(self::CONFIG_PATH)));
        } catch (\Throwable $e) {
            return self::MODE_LOG_ONLY;
        }

        if ($mode === self::MODE_OFF || $mode === self::MODE_LOG_ONLY || $mode === self::MODE_REFUSE) {
            return $mode;
        }

        return self::MODE_LOG_ONLY;
    }
}
