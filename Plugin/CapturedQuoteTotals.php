<?php

declare(strict_types=1);

namespace PayStand\PayStandMagento\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PayStand\PayStandMagento\Helper\CaptureSnapshot;
use PayStand\PayStandMagento\Helper\CloudLogger;
use PayStand\PayStandMagento\Helper\QuoteShipping;
use Psr\Log\LoggerInterface;

/**
 * After capture, Magento must still collectTotals() so placeOrder finds a shipping rate
 * after it reloads the quote. This plugin lets Magento collect, then puts back the paid
 * shipping row and grand total when the cart matches the snapshot, or when the new grand
 * total is within a cent of the paid one. A captured quote with no snapshot freezes
 * collect, except during the stamp recollect and when captured_cart_guard is off.
 */
class CapturedQuoteTotals
{
    /** @var LoggerInterface */
    private $logger;

    /** @var QuoteShipping */
    private $quoteShipping;

    /** @var CaptureSnapshot */
    private $snapshot;

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    public function __construct(
        LoggerInterface $logger,
        QuoteShipping $quoteShipping,
        CaptureSnapshot $snapshot,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->logger = $logger;
        $this->quoteShipping = $quoteShipping;
        $this->snapshot = $snapshot;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Collects when a snapshot exists, so Magento can rebuild rate rows. Freezes collect for
     * a captured quote with no snapshot, so cart price rules cannot raise the paid total.
     *
     * @param \Magento\Quote\Model\Quote $subject
     * @return void
     */
    public function beforeCollectTotals($subject)
    {
        try {
            if (!$subject || !$this->snapshot->isCaptured($subject)) {
                return;
            }

            if ($this->guardIsOff()) {
                return;
            }

            // Capture markers are set before the snapshot. Freeze here and the
            // stamp recollect never runs.
            if ($this->snapshot->isStamping()) {
                return;
            }

            if ($this->snapshot->read($subject)) {
                return;
            }

            $subject->setTotalsCollectedFlag(true);
            $this->logger->warning(
                'PAYSTAND-CAPTURED-TOTALS: captured quote has no snapshot, freezing collect on quote '
                . $subject->getId()
            );
        } catch (\Throwable $e) {
            $quoteId = null;
            try {
                $quoteId = $subject ? $subject->getId() : null;
            } catch (\Throwable $ignored) {
                $quoteId = null;
            }
            $this->logger->error(
                'PAYSTAND-CAPTURED-TOTALS: freeze check failed for quote ' . ($quoteId ?: 'unknown')
                . ', Magento totals will collect: ' . $e->getMessage()
            );
        }
    }

    /**
     * @param \Magento\Quote\Model\Quote $subject
     * @param \Magento\Quote\Model\Quote $result
     * @return \Magento\Quote\Model\Quote
     */
    public function afterCollectTotals($subject, $result)
    {
        try {
            $this->pinPaidTotals($subject);
        } catch (\Throwable $e) {
            $quoteId = null;
            try {
                $quoteId = $subject ? $subject->getId() : null;
            } catch (\Throwable $ignored) {
                $quoteId = null;
            }
            $this->logger->error(
                'PAYSTAND-CAPTURED-TOTALS: pin failed for quote ' . ($quoteId ?: 'unknown')
                . ', Magento totals left in place: ' . $e->getMessage()
            );
        }

        return $result;
    }

    /**
     * Pins on isPinnable, not isCaptured, since the rescue places ACH orders while they
     * read processing.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return void
     */
    private function pinPaidTotals($quote)
    {
        if (!$quote || !$this->snapshot->isPinnable($quote)) {
            return;
        }

        if ($this->guardIsOff()) {
            return;
        }

        // Multishipping spreads totals over several addresses and skips submit(), so a pin
        // would cover only part of the order.
        if ($quote->getIsMultiShipping()) {
            $this->logger->warning(
                'PAYSTAND-CAPTURED-TOTALS: multishipping quote is not pinned, leaving Magento totals on quote '
                . $quote->getId()
            );
            return;
        }

        $payload = $this->snapshot->read($quote);
        if (!$payload) {
            $this->logger->warning(
                'PAYSTAND-CAPTURED-TOTALS: captured quote has no snapshot, leaving Magento totals on quote '
                . $quote->getId()
            );
            return;
        }

        $hashMatches = $this->snapshot->matches($quote, $payload);
        $hasPaidTotal = isset($payload['grand_total']) && $payload['grand_total'] !== '';
        $grandTotal = $hasPaidTotal ? (float)$payload['grand_total'] : 0.0;
        $liveGrand = (float)$quote->getGrandTotal();
        $moneyClose = $hasPaidTotal && self::centsApart($liveGrand, $grandTotal) <= 1;

        if (!$hashMatches && !$moneyClose) {
            $this->logger->warning(
                'PAYSTAND-CAPTURED-TOTALS: cart changed after capture, leaving Magento totals on quote '
                . $quote->getId()
            );
            return;
        }

        $shipping = isset($payload['shipping']) && is_array($payload['shipping'])
            ? $payload['shipping']
            : null;

        if (!$hashMatches) {
            // The cart changed and only the total agrees. A different shipping service as well
            // ends the pin.
            if ($shipping && $this->shippingMethodChanged($quote, $shipping)) {
                $this->logger->warning(
                    'PAYSTAND-CAPTURED-TOTALS: cart hash and shipping method both changed after capture,'
                    . ' leaving Magento totals on quote ' . $quote->getId()
                );
                return;
            }

            $this->logger->warning(
                'PAYSTAND-CAPTURED-TOTALS: cart hash changed after capture, pinning paid total on quote '
                . $quote->getId()
            );
        }

        if ($shipping) {
            $this->quoteShipping->restore($quote, $shipping, 'captured-collect');
            $this->pinShippingAmounts($quote, $shipping);
        }

        // A rescue-stamped hash proves nothing, but it is the best paid figure there is. Pin
        // it and log that the amount is unverified.
        if (($hasPaidTotal || $shipping) && $this->snapshot->isSelfStamped($payload)) {
            $this->logger->warning(
                'PAYSTAND-CAPTURED-TOTALS: pinning quote ' . $quote->getId()
                . ' from a rescue-written snapshot, paid amount is unverified'
                . ' live=' . $liveGrand . ' stamped=' . $grandTotal
            );
            try {
                CloudLogger::ship(CloudLogger::EVENT_CAPTURE_PIN_UNVERIFIED, [
                    'quote_id' => (string)$quote->getId(),
                    'payment_id' => (string)$quote->getData('paystand_payment_id'),
                    'error_message' => 'live=' . $liveGrand . ' stamped=' . $grandTotal,
                ]);
            } catch (\Throwable $e) {
                // CloudLogger failure — ignored
            }
        }

        if (!$hasPaidTotal) {
            return;
        }

        $baseGrandTotal = isset($payload['base_grand_total']) && $payload['base_grand_total'] !== ''
            ? (float)$payload['base_grand_total']
            : $grandTotal;

        if ($hashMatches && self::centsApart($liveGrand, $grandTotal) > 0) {
            $this->logger->warning(
                'PAYSTAND-CAPTURED-TOTALS: Magento live grand_total drifted from paid copy on quote '
                . $quote->getId()
                . ' live=' . $liveGrand
                . ' paid=' . $grandTotal
            );
            try {
                CloudLogger::ship(CloudLogger::EVENT_CAPTURE_TOTAL_DRIFT, [
                    'quote_id' => (string)$quote->getId(),
                    'payment_id' => (string)$quote->getData('paystand_payment_id'),
                    'error_message' => 'live=' . $liveGrand . ' paid=' . $grandTotal,
                ]);
            } catch (\Throwable $e) {
                // CloudLogger failure — ignored
            }
        }

        $quote->setGrandTotal($grandTotal);
        $quote->setBaseGrandTotal($baseGrandTotal);

        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        if ($address) {
            $address->setGrandTotal($grandTotal);
            $address->setBaseGrandTotal($baseGrandTotal);
            if (isset($payload['address']) && is_array($payload['address'])) {
                // Cast like the grand total above: the snapshot stores money as
                // strings, and the order must not carry a mix of the two types.
                foreach (CaptureSnapshot::ADDRESS_MONEY_KEYS as $key) {
                    if (array_key_exists($key, $payload['address']) && $payload['address'][$key] !== '') {
                        $address->setData($key, (float)$payload['address'][$key]);
                    }
                }
            } elseif (array_key_exists('discount_amount', $payload) && $payload['discount_amount'] !== '') {
                $address->setDiscountAmount((float)$payload['discount_amount']);
            }
        }

        $this->logger->debug(
            'PAYSTAND-CAPTURED-TOTALS: pinned paid totals for quote ' . $quote->getId()
            . ' grand_total=' . $grandTotal
        );
    }

    /**
     * restore() writes amounts only when the method or rate row was missing. This pins them
     * whenever the cart matches, so a live re-quote cannot replace the paid shipping.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param array<string, mixed> $shipping
     * @return void
     */
    private function pinShippingAmounts($quote, array $shipping)
    {
        if ($quote->isVirtual() || empty($shipping['method'])) {
            return;
        }

        $address = $quote->getShippingAddress();
        if (!$address) {
            return;
        }

        if (array_key_exists('amount', $shipping) && $shipping['amount'] !== '') {
            $address->setShippingAmount((float)$shipping['amount']);
        }
        if (array_key_exists('baseAmount', $shipping) && $shipping['baseAmount'] !== '') {
            $address->setBaseShippingAmount((float)$shipping['baseAmount']);
        }
        if (!empty($shipping['description'])) {
            $address->setShippingDescription($shipping['description']);
        }
    }

    /**
     * True when the address has a different shipping method than the snapshot. An empty
     * method is not a change: the recollect clears it and restore() puts it back.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param array<string, mixed> $shipping
     */
    private function shippingMethodChanged($quote, array $shipping): bool
    {
        if ($quote->isVirtual() || empty($shipping['method'])) {
            return false;
        }

        $address = $quote->getShippingAddress();
        if (!$address) {
            return false;
        }

        $live = (string)$address->getShippingMethod();

        return $live !== '' && $live !== (string)$shipping['method'];
    }

    /**
     * Distance between two money figures in whole cents. abs(1.01 - 1.00) is above 0.01 in
     * floats, so both are rounded to cents first.
     */
    private static function centsApart(float $left, float $right): int
    {
        return (int)abs((int)round($left * 100) - (int)round($right * 100));
    }

    private function guardIsOff(): bool
    {
        return $this->snapshot->resolveGuardMode($this->scopeConfig) === CapturedQuoteSubmit::MODE_OFF;
    }
}
