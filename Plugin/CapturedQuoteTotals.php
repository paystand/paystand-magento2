<?php

namespace PayStand\PayStandMagento\Plugin;

use PayStand\PayStandMagento\Helper\CaptureSnapshot;
use PayStand\PayStandMagento\Helper\QuoteShipping;
use Psr\Log\LoggerInterface;

/**
 * After Paystand captures a quote, Magento must still collectTotals(). Skipping
 * collection (the 3.7.2 freeze) left placeOrder with no shipping rate after it
 * reloaded the quote — "The shipping method is missing" — even when the cart
 * had not changed (PROD-16503 / Rowley quote 4490737).
 *
 * This plugin lets Magento collect, then puts back the paid shipping row and
 * grand total when the cart still matches the capture snapshot. Quotes that
 * are captured but have no snapshot freeze collect, same as 3.7.2.
 */
class CapturedQuoteTotals
{
    /** @var LoggerInterface */
    private $logger;

    /** @var QuoteShipping */
    private $quoteShipping;

    /** @var CaptureSnapshot */
    private $snapshot;

    public function __construct(
        LoggerInterface $logger,
        QuoteShipping $quoteShipping,
        CaptureSnapshot $snapshot
    ) {
        $this->logger = $logger;
        $this->quoteShipping = $quoteShipping;
        $this->snapshot = $snapshot;
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
            $this->pinPaidTotals($subject ?: $result);
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

        $payload = $this->snapshot->read($quote);
        if (!$payload) {
            $this->logger->warning(
                'PAYSTAND-CAPTURED-TOTALS: captured quote has no snapshot, leaving Magento totals on quote '
                . $quote->getId()
            );
            return;
        }

        if (!$this->snapshot->matches($quote, $payload)) {
            $this->logger->warning(
                'PAYSTAND-CAPTURED-TOTALS: cart changed after capture, leaving Magento totals on quote '
                . $quote->getId()
            );
            return;
        }

        $shipping = isset($payload['shipping']) && is_array($payload['shipping'])
            ? $payload['shipping']
            : null;
        if ($shipping) {
            $this->quoteShipping->restore($quote, $shipping, 'captured-collect');
            $this->pinShippingAmounts($quote, $shipping);
        }

        if (!isset($payload['grand_total']) || $payload['grand_total'] === '') {
            return;
        }

        $grandTotal = (float)$payload['grand_total'];
        $baseGrandTotal = isset($payload['base_grand_total']) && $payload['base_grand_total'] !== ''
            ? (float)$payload['base_grand_total']
            : $grandTotal;

        $quote->setGrandTotal($grandTotal);
        $quote->setBaseGrandTotal($baseGrandTotal);

        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        if ($address) {
            $address->setGrandTotal($grandTotal);
            $address->setBaseGrandTotal($baseGrandTotal);
            if (array_key_exists('discount_amount', $payload)) {
                $address->setDiscountAmount($payload['discount_amount']);
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

        if (array_key_exists('amount', $shipping)) {
            $address->setShippingAmount($shipping['amount']);
        }
        if (array_key_exists('baseAmount', $shipping)) {
            $address->setBaseShippingAmount($shipping['baseAmount']);
        }
        if (!empty($shipping['description'])) {
            $address->setShippingDescription($shipping['description']);
        }
    }
}
