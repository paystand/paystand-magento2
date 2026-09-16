<?php

declare(strict_types=1);

namespace PayStand\PayStandMagento\Helper;

/**
 * Records the cart Paystand captured and decides whether a later collect still
 * describes that cart.
 *
 * Magento placeOrder reloads the quote and calls collectTotals(). Rate rows often
 * live only in memory, so skipping collection after capture left validateBeforeSubmit
 * with no rate (shipping method is missing). This snapshot is persisted on the quote
 * so collect can run and the paid shipping + grand total can be put back when the
 * cart has not changed.
 */
class CaptureSnapshot
{
    public const QUOTE_FIELD = 'paystand_capture_snapshot';

    /** @var QuoteShipping */
    private $quoteShipping;

    public function __construct(QuoteShipping $quoteShipping)
    {
        $this->quoteShipping = $quoteShipping;
    }

    /**
     * @param mixed $quote
     */
    public function isCaptured($quote): bool
    {
        if (!$quote) {
            return false;
        }

        return !empty($quote->getData('paystand_payment_id'))
            && !empty($quote->getData('paystand_capture_status'));
    }

    /**
     * Hash of visible items and destination. Region is omitted: Magento can fill
     * region_id from region text without the shopper changing the cart.
     *
     * @param array<int, array{id:string,sku:string,qty:string}> $items
     * @param array{street:array,city:string,postcode:string,country:string} $address
     */
    public static function hashParts(array $items, array $address): string
    {
        usort($items, static function (array $left, array $right): int {
            return strcmp($left['id'], $right['id']);
        });

        return hash('sha256', json_encode(
            ['items' => $items, 'address' => $address],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));
    }

    /**
     * @param mixed $quote Magento quote
     */
    public function hash($quote): string
    {
        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $items[] = [
                'id' => (string)$item->getId(),
                'sku' => (string)$item->getSku(),
                'qty' => (string)(float)$item->getQty(),
            ];
        }

        $shipping = $quote->isVirtual() ? null : $quote->getShippingAddress();
        $address = [
            'street' => $shipping ? array_values(array_map('strval', (array)$shipping->getStreet())) : [],
            'city' => $shipping ? trim((string)$shipping->getCity()) : '',
            'postcode' => $shipping ? trim((string)$shipping->getPostcode()) : '',
            'country' => $shipping ? strtoupper(trim((string)$shipping->getCountryId())) : '',
        ];

        return self::hashParts($items, $address);
    }

    /**
     * @param mixed $quote Magento quote
     */
    public function stamp($quote): void
    {
        if (!$quote) {
            return;
        }

        $payload = [
            'hash' => $this->hash($quote),
            'grand_total' => (string)$quote->getGrandTotal(),
            'base_grand_total' => (string)$quote->getBaseGrandTotal(),
            'discount_amount' => $this->discountAmount($quote),
            'shipping' => $this->quoteShipping->snapshot($quote),
        ];

        $quote->setData(self::QUOTE_FIELD, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Record the snapshot once, the first time this quote shows a confirmed capture.
     * Later saves must not overwrite it: that hash is the cart the shopper paid for.
     *
     * @param mixed $quote Magento quote
     */
    public function ensureStamped($quote): void
    {
        try {
            if (!$this->isCaptured($quote) || $this->read($quote)) {
                return;
            }

            $this->stamp($quote);
        } catch (\Throwable $e) {
            // A failed stamp must not block persisting the capture markers.
        }
    }

    /**
     * @param mixed $quote Magento quote
     * @return array<string, mixed>|null
     */
    public function read($quote): ?array
    {
        if (!$quote) {
            return null;
        }

        $raw = $quote->getData(self::QUOTE_FIELD);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param mixed $quote Magento quote
     * @param array<string, mixed> $payload
     */
    public function matches($quote, array $payload): bool
    {
        $expected = isset($payload['hash']) ? (string)$payload['hash'] : '';
        if ($expected === '' || !preg_match('/^[a-f0-9]{64}$/D', $expected)) {
            return false;
        }

        return hash_equals($expected, $this->hash($quote));
    }

    /**
     * @param mixed $quote Magento quote
     */
    private function discountAmount($quote): string
    {
        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        if (!$address) {
            return '0';
        }

        return (string)$address->getDiscountAmount();
    }
}
