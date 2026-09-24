<?php

declare(strict_types=1);

namespace PayStand\PayStandMagento\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use PayStand\PayStandMagento\Exception\CapturedCartChangedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use PayStand\PayStandMagento\Helper\CaptureSnapshot;
use PayStand\PayStandMagento\Helper\CloudLogger;
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
 * log_only — log a mismatch and still submit
 * refuse   — throw and block placeOrder (default)
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

    /** @var ManagerInterface */
    private $eventManager;

    public function __construct(
        LoggerInterface $logger,
        CaptureSnapshot $snapshot,
        ScopeConfigInterface $scopeConfig,
        ManagerInterface $eventManager
    ) {
        $this->logger = $logger;
        $this->snapshot = $snapshot;
        $this->scopeConfig = $scopeConfig;
        $this->eventManager = $eventManager;
    }

    /**
     * Inspects and rethrows only, so it runs before Magento rather than wrapping it.
     *
     * @param QuoteManagement $subject
     * @param Quote $quote
     * @param array $orderData
     * @return void
     * @throws LocalizedException
     */
    public function beforeSubmit(QuoteManagement $subject, Quote $quote, $orderData = [])
    {
        $this->refuseIfCartChanged($quote);
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
        $mode = self::MODE_LOG_ONLY;
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

            // The rescue stamps from the quote it is about to place, so its hash
            // always matches itself. Comparing proves nothing; say so and submit.
            if ($this->snapshot->isSelfStamped($payload)) {
                $this->logger->warning(
                    'PAYSTAND-CAPTURED-SUBMIT: snapshot was written by the rescue on quote '
                    . $quote->getId() . ', cart cannot be verified, submit continues'
                );
                return;
            }

            // An unreadable stamp is not a changed cart. Refusing on one would tell
            // the shopper they altered a cart they never touched, so log and submit.
            $stamped = (string)$this->snapshot->stampedHash($payload);
            if ($stamped === '') {
                $this->logger->error(
                    'PAYSTAND-CAPTURED-SUBMIT: snapshot carries no usable hash on quote '
                    . $quote->getId() . ', submit continues'
                );
                return;
            }

            $current = $this->snapshot->hash($quote);
            if (hash_equals($stamped, $current)) {
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
            'PAYSTAND-CAPTURED-SUBMIT: cart changed after capture; '
            . $mode
            . ' placeOrder for quote '
            . $quoteId
            . ' stamped=' . $stamped
            . ' current=' . $current
        );

        $paymentId = '';
        try {
            $paymentId = (string)$quote->getData('paystand_payment_id');
        } catch (\Throwable $ignored) {
            $paymentId = '';
        }

        try {
            CloudLogger::ship(CloudLogger::EVENT_CAPTURED_CART_REFUSED, [
                'quote_id' => (string)$quoteId,
                'payment_id' => $paymentId,
                'error_message' => 'mode=' . $mode . ' stamped=' . $stamped . ' current=' . $current,
            ]);
        } catch (\Throwable $ignored) {
        }

        if ($mode !== self::MODE_REFUSE) {
            return;
        }

        $exception = new CapturedCartChangedException(
            new Phrase(
                'The cart changed after payment was captured. Do not place this order.'
                . ' Contact support. Payment ID: %1 (%2)',
                [$paymentId !== '' ? $paymentId : 'unknown', CapturedCartChangedException::CODE]
            )
        );

        try {
            $this->eventManager->dispatch('sales_model_service_quote_submit_failure', [
                'order' => null,
                'quote' => $quote,
                'exception' => $exception,
            ]);
        } catch (\Throwable $ignored) {
            // Magento event failure must not hide the refuse.
        }

        throw $exception;
    }

    private function guardMode(): string
    {
        return $this->snapshot->resolveGuardMode($this->scopeConfig);
    }
}
