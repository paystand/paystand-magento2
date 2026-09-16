<?php

namespace PayStand\PayStandMagento\Helper;

use Psr\Log\LoggerInterface;

/**
 * Fingerprints the cart a capture was taken on, and says whether a quote still holds
 * it. The totals freeze is meant for the cart the shopper paid for, so a quote that
 * no longer matches its fingerprint must collect totals like any other.
 */
class CaptureFingerprint
{
    /** Quote column, written wherever the capture markers are stamped. */
    const QUOTE_FIELD = 'paystand_capture_cart_hash';

    /** @var LoggerInterface */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Covers only what the shopper chose: items, quantities and destination. Prices
     * are left out on purpose, so a cart rule expiring on its own cannot release the
     * freeze — that is the case the freeze exists for.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return string
     */
    public function forQuote($quote): string
    {
        if (!$quote) {
            return '';
        }

        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            if (!$item) {
                continue;
            }
            $sku = strtolower(trim((string)$item->getSku()));
            $items[] = $sku . ':' . (string)(float)$item->getQty();
        }
        sort($items, SORT_STRING);

        return hash('sha256', implode('|', $items) . '#' . $this->destination($quote));
    }

    /**
     * Records the current cart on the quote. Called where the capture markers are
     * written, so the frozen quote carries the cart the money was taken for.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return string The fingerprint stamped, or '' when it could not be taken
     */
    public function stamp($quote): string
    {
        try {
            $hash = $this->forQuote($quote);
            if ($hash === '') {
                return '';
            }
            $quote->setData(self::QUOTE_FIELD, $hash);
            return $hash;
        } catch (\Throwable $e) {
            // Never block a capture from being recorded: an unstamped quote only
            // means its totals stay live, which is Magento's normal behaviour.
            $this->logger->error(
                'PAYSTAND-CAPTURE-FINGERPRINT: could not stamp quote '
                . $this->quoteId($quote) . ': ' . $e->getMessage()
            );
            return '';
        }
    }

    /**
     * True only when the quote still holds the cart its capture was taken on.
     * A quote with no stamp cannot prove that, so it does not match.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return bool
     */
    public function matchesCapture($quote): bool
    {
        if (!$quote) {
            return false;
        }

        $stamped = trim((string)$quote->getData(self::QUOTE_FIELD));
        if ($stamped === '') {
            return false;
        }

        $current = $this->forQuote($quote);
        return $current !== '' && hash_equals($stamped, $current);
    }

    /**
     * Rates are quoted per destination, so a new destination invalidates a captured
     * cart the same way a new item does.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return string
     */
    private function destination($quote): string
    {
        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        if (!$address) {
            return '';
        }

        $parts = [
            (string)$address->getCountryId(),
            (string)($address->getRegionId() ?: $address->getRegion()),
            (string)$address->getPostcode(),
            (string)$address->getCity(),
        ];

        return strtolower(trim(implode(':', $parts)));
    }

    /**
     * @param \Magento\Quote\Model\Quote|null $quote
     * @return string
     */
    private function quoteId($quote): string
    {
        try {
            return $quote ? (string)$quote->getId() : 'unknown';
        } catch (\Throwable $ignored) {
            return 'unknown';
        }
    }
}
