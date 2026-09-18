<?php

declare(strict_types=1);

namespace PayStand\PayStandMagento\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PayStand\PayStandMagento\Helper\CaptureSnapshot;
use PayStand\PayStandMagento\Helper\CloudLogger;
use PayStand\PayStandMagento\Helper\QuoteShipping;
use Psr\Log\LoggerInterface;

/**
 * After Paystand captures a quote, Magento must still collectTotals(). Skipping
 * collection (the 3.7.2 freeze) left placeOrder with no shipping rate after it
 * reloaded the quote — "The shipping method is missing" — even when the cart
 * had not changed (PROD-16503).
 *
 * This plugin lets Magento collect, then puts back the paid shipping row and
 * grand total when the cart still matches the capture snapshot, or when the
 * recollected grand total is within a cent of the paid one. Quotes that are
 * captured but have no snapshot freeze collect, same as 3.7.2, except during
 * the stamp recollect and when captured_cart_guard is off.
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
     * Collect when a snapshot exists so Magento can rebuild rate rows after a
     * reload. Freeze collect when the quote is captured and the snapshot is
     * missing, so cart price rules cannot raise the paid total.
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
     * @param \Magento\Quote\Model\Quote $quote
     * @return void
     */
    private function pinPaidTotals($quote)
    {
        if (!$quote || !$this->snapshot->isCaptured($quote)) {
            return;
        }

        if ($this->guardIsOff()) {
            return;
        }

        // Multishipping splits totals across several addresses and never reaches
        // QuoteManagement::submit(), so there is no guard behind this. Pinning a
        // quote-level total onto one of those addresses would half-pin the order.
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
        $moneyClose = $hasPaidTotal && abs($liveGrand - $grandTotal) <= 0.01;

        if (!$hashMatches && !$moneyClose) {
            $this->logger->warning(
                'PAYSTAND-CAPTURED-TOTALS: cart changed after capture, leaving Magento totals on quote '
                . $quote->getId()
            );
            return;
        }

        if (!$hashMatches) {
            $this->logger->warning(
                'PAYSTAND-CAPTURED-TOTALS: cart hash changed after capture, pinning paid total on quote '
                . $quote->getId()
            );
        }

        $shipping = isset($payload['shipping']) && is_array($payload['shipping'])
            ? $payload['shipping']
            : null;
        if ($shipping) {
            $this->quoteShipping->restore($quote, $shipping, 'captured-collect');
            $this->pinShippingAmounts($quote, $shipping);
        }

        if (!$hasPaidTotal) {
            return;
        }

        $baseGrandTotal = isset($payload['base_grand_total']) && $payload['base_grand_total'] !== ''
            ? (float)$payload['base_grand_total']
            : $grandTotal;

        if ($hashMatches && abs($liveGrand - $grandTotal) > 0.005) {
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
     * restore() only writes amounts when the method or rate row was missing.
     * Pin them whenever the cart still matches, so a live re-quote cannot
     * replace the shipping the shopper already paid.
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

    private function guardIsOff(): bool
    {
        return $this->snapshot->resolveGuardMode($this->scopeConfig) === CapturedQuoteSubmit::MODE_OFF;
    }
}
