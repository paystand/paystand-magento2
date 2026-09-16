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

    /** @var bool|null Whether the quote table can carry a fingerprint, per request. */
    private $columnAvailable = null;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Whether the schema patch has run. Without the column no quote can carry a
     * fingerprint, so every capture would look changed and callers must not read
     * a missing stamp as a changed cart.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return bool
     */
    public function isAvailable($quote): bool
    {
        if ($this->columnAvailable !== null) {
            return $this->columnAvailable;
        }

        try {
            $resource = $quote->getResource();
            $this->columnAvailable = (bool)$resource->getConnection()
                ->tableColumnExists($resource->getMainTable(), self::QUOTE_FIELD);
        } catch (\Throwable $e) {
            // Cannot tell, so report unavailable and let the caller keep the
            // behaviour it had before the fingerprint existed.
            $this->columnAvailable = false;
        }

        return $this->columnAvailable;
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
     * Brings a stamp already saved for this quote back onto it, so a quote loaded
     * without one cannot save a null over it. Read on its own so a missing column
     * costs the capture status nothing.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param int|string $quoteId
     * @return void
     */
    public function restore($quote, $quoteId)
    {
        try {
            if (!empty($quote->getData(self::QUOTE_FIELD))) {
                return;
            }

            $resource = $quote->getResource();
            $connection = $resource->getConnection();
            $persisted = $connection->fetchOne(
                $connection->select()
                    ->from($resource->getMainTable(), self::QUOTE_FIELD)
                    ->where('entity_id = ?', $quoteId)
            );

            if (!empty($persisted)) {
                $quote->setData(self::QUOTE_FIELD, $persisted);
            }
        } catch (\Throwable $e) {
            // Worst case the quote carries no fingerprint, which only lets its totals
            // collect as Magento normally would.
            $this->logger->error(
                'PAYSTAND-CAPTURE-FINGERPRINT: could not re-read the stamp for quote '
                . $quoteId . ': ' . $e->getMessage()
            );
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

        // Region is left out: Magento can resolve a region_id onto an address that
        // only had region text, which would read as a shopper change it is not.
        // Country, postcode and city already move whenever a destination does.
        $parts = [
            (string)$address->getCountryId(),
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
