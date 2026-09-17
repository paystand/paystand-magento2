<?php

declare(strict_types=1);

namespace PayStand\PayStandMagento\Helper;

use Psr\Log\LoggerInterface;

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

    /** @var LoggerInterface */
    private $logger;

    public function __construct(QuoteShipping $quoteShipping, LoggerInterface $logger)
    {
        $this->quoteShipping = $quoteShipping;
        $this->logger = $logger;
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
     * Hash of visible items and destination. Street, item id, and region are
     * omitted: Magento can rewrite those on save/load without the shopper
     * changing the cart.
     *
     * @param array<int, array{sku:string,qty:string}> $items
     * @param array{city:string,postcode:string,country:string} $address
     */
    public static function hashParts(array $items, array $address): string
    {
        $normalized = [];
        foreach ($items as $item) {
            $normalized[] = [
                'sku' => strtolower(trim((string)($item['sku'] ?? ''))),
                'qty' => (string)(float)($item['qty'] ?? 0),
            ];
        }
        usort($normalized, static function (array $left, array $right): int {
            $sku = strcmp($left['sku'], $right['sku']);
            return $sku !== 0 ? $sku : strcmp($left['qty'], $right['qty']);
        });

        $dest = [
            'city' => strtolower(trim((string)($address['city'] ?? ''))),
            'postcode' => strtolower(trim((string)($address['postcode'] ?? ''))),
            'country' => strtoupper(trim((string)($address['country'] ?? ''))),
        ];

        return hash('sha256', json_encode(
            ['items' => $normalized, 'address' => $dest],
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
                'sku' => (string)$item->getSku(),
                'qty' => (string)(float)$item->getQty(),
            ];
        }

        $shipping = $quote->isVirtual() ? null : $quote->getShippingAddress();
        $address = [
            'city' => $shipping ? (string)$shipping->getCity() : '',
            'postcode' => $shipping ? (string)$shipping->getPostcode() : '',
            'country' => $shipping ? (string)$shipping->getCountryId() : '',
        ];

        return self::hashParts($items, $address);
    }

    /**
     * @param mixed $quote Magento quote
     * @param array<string, mixed>|null $paid Paid totals copied before recollect
     */
    public function stamp($quote, array $paid = null): void
    {
        if (!$quote) {
            return;
        }

        $paid = $paid ?? [];
        $shipping = array_key_exists('shipping', $paid)
            ? $paid['shipping']
            : $this->quoteShipping->snapshot($quote);

        if ($shipping
            && !empty($shipping['method'])
            && empty($shipping['rate'])
        ) {
            $live = $this->quoteShipping->snapshot($quote);
            if (is_array($live) && !empty($live['rate'])) {
                $shipping['rate'] = $live['rate'];
            }
        }

        if ($shipping
            && !empty($shipping['method'])
            && empty($shipping['rate'])
        ) {
            $shipping['rate'] = $this->synthesizeRate($shipping);
            $this->logger->warning(
                'PAYSTAND-CAPTURE-SNAPSHOT: shipping method has no rate row on quote '
                . $quote->getId()
                . ' method=' . $shipping['method']
                . '; synthesized rate from method and amount'
            );
        }

        $payload = [
            'hash' => $this->hash($quote),
            'grand_total' => array_key_exists('grand_total', $paid)
                ? (string)$paid['grand_total']
                : (string)$quote->getGrandTotal(),
            'base_grand_total' => array_key_exists('base_grand_total', $paid)
                ? (string)$paid['base_grand_total']
                : (string)$quote->getBaseGrandTotal(),
            'discount_amount' => array_key_exists('discount_amount', $paid)
                ? (string)$paid['discount_amount']
                : $this->discountAmount($quote),
            'shipping' => $shipping,
        ];

        $quote->setData(self::QUOTE_FIELD, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Copy paid money and shipping before Magento recollects.
     *
     * @param mixed $quote Magento quote
     * @return array{grand_total:string,base_grand_total:string,discount_amount:string,shipping:array|null}
     */
    public function paidBag($quote): array
    {
        return [
            'grand_total' => (string)$quote->getGrandTotal(),
            'base_grand_total' => (string)$quote->getBaseGrandTotal(),
            'discount_amount' => $this->discountAmount($quote),
            'shipping' => $this->quoteShipping->snapshot($quote),
        ];
    }

    /**
     * Record the snapshot once, the first time this quote shows a confirmed capture.
     * Later saves must not overwrite it: that hash is the cart the shopper paid for.
     *
     * @param mixed $quote Magento quote
     * @param array<string, mixed>|null $paid Paid totals copied before recollect
     */
    public function ensureStamped($quote, array $paid = null): void
    {
        try {
            if (!$this->isCaptured($quote) || $this->read($quote)) {
                return;
            }

            $this->stamp($quote, $paid);
        } catch (\Throwable $e) {
            $quoteId = 'unknown';
            try {
                $quoteId = $quote ? (string)$quote->getId() : 'unknown';
            } catch (\Throwable $ignored) {
                $quoteId = 'unknown';
            }
            $this->logger->error(
                'PAYSTAND-CAPTURE-SNAPSHOT: stamp failed for quote ' . $quoteId
                . ': ' . $e->getMessage()
            );
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

    /**
     * Magento validateBeforeSubmit needs getShippingRateByCode(), not only a method.
     * Build a rate row from the paid method code and amount when Magento has none.
     *
     * @param array<string, mixed> $shipping
     * @return array{code:string,carrier:string,carrierTitle:string,method:string,methodTitle:string,price:mixed}
     */
    private function synthesizeRate(array $shipping): array
    {
        $code = (string)$shipping['method'];
        $pos = strpos($code, '_');
        $carrier = $pos === false ? $code : substr($code, 0, $pos);
        $method = $pos === false ? $code : substr($code, $pos + 1);
        $title = trim((string)($shipping['description'] ?? ''));
        $price = array_key_exists('amount', $shipping)
            ? $shipping['amount']
            : ($shipping['baseAmount'] ?? 0);

        return [
            'code' => $code,
            'carrier' => $carrier,
            'carrierTitle' => $carrier,
            'method' => $method,
            'methodTitle' => $title !== '' ? $title : $method,
            'price' => $price,
        ];
    }
}
