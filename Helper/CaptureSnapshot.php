<?php

declare(strict_types=1);

namespace PayStand\PayStandMagento\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PayStand\PayStandMagento\Model\Config\Source\PaymentStatus;
use PayStand\PayStandMagento\Plugin\CapturedQuoteSubmit;
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

    // Stamped by the shopper's own browser, from the cart that was on screen
    // when Paystand captured. The hash is evidence of what was paid for.
    public const SOURCE_CHECKOUT = 'checkout';
    // Stamped by the webhook rescue, up to 24h later, from whatever the cart
    // holds now. The hash describes that cart, not the paid one, so comparing
    // the cart against it proves nothing.
    public const SOURCE_RESCUE = 'rescue';

    public const ADDRESS_MONEY_KEYS = [
        'subtotal',
        'base_subtotal',
        'subtotal_with_discount',
        'base_subtotal_with_discount',
        'tax_amount',
        'base_tax_amount',
        'discount_amount',
        'base_discount_amount',
        'shipping_incl_tax',
        'base_shipping_incl_tax',
        'shipping_tax_amount',
        'base_shipping_tax_amount',
    ];

    /** @var QuoteShipping */
    private $quoteShipping;

    /** @var LoggerInterface */
    private $logger;

    /** @var int */
    private $stampDepth = 0;

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

        $status = strtolower(trim((string)$quote->getData('paystand_capture_status')));

        return !empty($quote->getData('paystand_payment_id'))
            && in_array($status, PaymentStatus::CAPTURED_STATUSES, true);
    }

    /**
     * True while copyPaidRecollectAndStamp is running. The freeze must not
     * short-circuit that recollect: capture markers are already set and the
     * snapshot is not written yet.
     */
    public function isStamping(): bool
    {
        return $this->stampDepth > 0;
    }

    /**
     * payment/paystandmagento/captured_cart_guard at store scope (website
     * values fall through). Unknown, empty, and read failures are log_only.
     *
     * @param ScopeConfigInterface $config
     */
    public function resolveGuardMode(ScopeConfigInterface $config): string
    {
        try {
            $mode = strtolower(trim((string)$config->getValue(
                CapturedQuoteSubmit::CONFIG_PATH,
                ScopeInterface::SCOPE_STORE
            )));
        } catch (\Throwable $e) {
            $this->logger->error(
                'PAYSTAND-CAPTURED-GUARD: could not read captured_cart_guard, using log_only: '
                . $e->getMessage()
            );
            return CapturedQuoteSubmit::MODE_LOG_ONLY;
        }

        if ($mode === CapturedQuoteSubmit::MODE_OFF
            || $mode === CapturedQuoteSubmit::MODE_LOG_ONLY
            || $mode === CapturedQuoteSubmit::MODE_REFUSE
        ) {
            return $mode;
        }

        $this->logger->warning(
            'PAYSTAND-CAPTURED-GUARD: unknown captured_cart_guard value, using log_only'
        );

        return CapturedQuoteSubmit::MODE_LOG_ONLY;
    }

    /**
     * Hash of visible items and destination. Street, item id, and region are
     * omitted: Magento can rewrite those on save/load without the shopper
     * changing the cart.
     *
     * row_total carries what sku + qty cannot: a priced custom option and a
     * different child of the same configurable. It is the base-currency figure
     * rounded to cents, so a currency-rate import or a re-render cannot move it
     * — only the goods can. Normalisation is deliberately lossy in the direction
     * of not refusing: case and postcode punctuation are never a shopper's
     * intent to change the destination.
     *
     * Changing anything here invalidates every stamp already written, so a
     * change must ship with a plugin version bump. testGoldenHashVector pins it.
     *
     * @param array<int, array{sku:string,qty:string,row_total:string}> $items
     * @param array{city:string,postcode:string,country:string} $address
     */
    public static function hashParts(array $items, array $address): string
    {
        $normalized = [];
        foreach ($items as $item) {
            $normalized[] = [
                'sku' => self::fold((string)($item['sku'] ?? '')),
                'qty' => (string)(float)($item['qty'] ?? 0),
                'row_total' => self::money($item['row_total'] ?? 0),
            ];
        }
        usort($normalized, static function (array $left, array $right): int {
            $sku = strcmp($left['sku'], $right['sku']);
            if ($sku !== 0) {
                return $sku;
            }
            $qty = strcmp($left['qty'], $right['qty']);
            return $qty !== 0 ? $qty : strcmp($left['row_total'], $right['row_total']);
        });

        $dest = [
            'city' => self::fold((string)($address['city'] ?? '')),
            // Punctuation and ZIP+4 are the same destination. Refusing a paid
            // order over "SW1A 1AA" vs "SW1A1AA" is the costlier mistake.
            'postcode' => preg_replace(
                '/[^a-z0-9]/',
                '',
                self::fold((string)($address['postcode'] ?? ''))
            ),
            'country' => self::fold((string)($address['country'] ?? '')),
        ];

        return hash('sha256', json_encode(
            ['items' => $normalized, 'address' => $dest],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));
    }

    /**
     * One case rule for every hashed field. strtolower is byte-wise, so it
     * leaves "MÜNCHEN" and "München" hashing differently.
     */
    /**
     * Money as cents, so a display re-render or a decimal(20,4) reload cannot
     * move the hash. Catalog price changes still move it; the epsilon drift
     * check is what covers a price that moved without the cart changing.
     *
     * @param mixed $value
     */
    private static function money($value): string
    {
        return number_format(round((float)$value, 2), 2, '.', '');
    }

    private static function fold(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower(trim($value), 'UTF-8')
            : strtolower(trim($value));
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
                'row_total' => self::money($item->getBaseRowTotal()),
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
    public function stamp($quote, ?array $paid = null, string $source = self::SOURCE_CHECKOUT): void
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

        $grandTotal = array_key_exists('grand_total', $paid)
            ? $paid['grand_total']
            : $quote->getGrandTotal();
        $baseGrandTotal = array_key_exists('base_grand_total', $paid)
            ? $paid['base_grand_total']
            : $quote->getBaseGrandTotal();
        // json_encode NAN/INF throws; (string)NAN would otherwise persist "NAN".
        json_encode([$grandTotal, $baseGrandTotal], JSON_THROW_ON_ERROR);

        $payload = [
            'hash' => $this->hash($quote),
            'source' => $source === self::SOURCE_RESCUE ? self::SOURCE_RESCUE : self::SOURCE_CHECKOUT,
            'grand_total' => (string)$grandTotal,
            'base_grand_total' => (string)$baseGrandTotal,
            'discount_amount' => array_key_exists('discount_amount', $paid)
                ? (string)$paid['discount_amount']
                : $this->discountAmount($quote),
            'address' => array_key_exists('address', $paid)
                ? $paid['address']
                : $this->addressMoney($quote),
            'shipping' => $shipping,
        ];

        $quote->setData(self::QUOTE_FIELD, json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));
    }

    /**
     * Copy paid money and shipping before Magento recollects.
     *
     * @param mixed $quote Magento quote
     * @return array{grand_total:string,base_grand_total:string,discount_amount:string,address:array<string,string>,shipping:array|null}
     */
    public function paidBag($quote): array
    {
        return [
            'grand_total' => (string)$quote->getGrandTotal(),
            'base_grand_total' => (string)$quote->getBaseGrandTotal(),
            'discount_amount' => $this->discountAmount($quote),
            'address' => $this->addressMoney($quote),
            'shipping' => $this->quoteShipping->snapshot($quote),
        ];
    }

    /**
     * Copy Magento quote-address money with getData. Do not invent tax math.
     *
     * @param mixed $quote
     * @return array<string, string>
     */
    private function addressMoney($quote): array
    {
        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        $out = [];
        if (!$address) {
            return $out;
        }
        foreach (self::ADDRESS_MONEY_KEYS as $key) {
            $out[$key] = (string)$address->getData($key);
        }
        return $out;
    }

    /**
     * Copy paid Magento money, then recollect, then stamp the copy.
     * Recollect first would stamp a rewritten grand_total (3.7.1 discount bug).
     *
     * @param mixed $quote Magento quote
     */
    public function copyPaidRecollectAndStamp(
        $quote,
        string $context,
        string $source = self::SOURCE_CHECKOUT
    ): void {
        $this->stampDepth++;
        try {
            $paid = $this->paidBag($quote);
            $this->quoteShipping->recollectPreservingShipping($quote, $context);
            $this->ensureStamped($quote, $paid, $source);
        } finally {
            $this->stampDepth--;
        }
    }

    /**
     * True when the snapshot was written by the rescue rather than by checkout.
     * Its hash comes from the same quote the rescue is about to place, so a
     * comparison against it always matches and proves nothing.
     *
     * @param array<string, mixed> $payload
     */
    public function isSelfStamped(array $payload): bool
    {
        return isset($payload['source']) && (string)$payload['source'] === self::SOURCE_RESCUE;
    }

    /**
     * Record the snapshot once, the first time this quote shows a confirmed capture.
     * Later saves must not overwrite it: that hash is the cart the shopper paid for.
     *
     * @param mixed $quote Magento quote
     * @param array<string, mixed>|null $paid Paid totals copied before recollect
     */
    public function ensureStamped($quote, ?array $paid = null, string $source = self::SOURCE_CHECKOUT): void
    {
        try {
            if (!$this->isCaptured($quote)) {
                return;
            }

            if ($this->read($quote)) {
                return;
            }

            $persisted = $this->persistedSnapshot($quote);
            if ($persisted !== null) {
                $quote->setData(self::QUOTE_FIELD, $persisted);
                return;
            }

            $this->stamp($quote, $paid, $source);
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
            $paymentId = '';
            try {
                $paymentId = $quote ? (string)$quote->getData('paystand_payment_id') : '';
            } catch (\Throwable $ignored) {
                $paymentId = '';
            }
            try {
                CloudLogger::ship(CloudLogger::EVENT_CAPTURE_SNAPSHOT_STAMP_FAILED, [
                    'quote_id' => $quoteId,
                    'payment_id' => $paymentId,
                    'error_message' => $e->getMessage(),
                ]);
            } catch (\Throwable $ignored) {
            }
        }
    }

    /**
     * Re-read paystand_capture_snapshot from the quote row.
     * Same pattern as preserveCaptureStatus: another request may have stamped
     * since this in-memory quote was loaded.
     *
     * @param mixed $quote Magento quote
     */
    private function persistedSnapshot($quote): ?string
    {
        try {
            $id = $quote ? $quote->getId() : null;
            if (!$id) {
                return null;
            }
            $resource = $quote->getResource();
            $connection = $resource->getConnection();
            $select = $connection->select()
                ->from($resource->getMainTable(), self::QUOTE_FIELD)
                ->where('entity_id = ?', $id);
            $raw = $connection->fetchOne($select);
            return is_string($raw) && $raw !== '' ? $raw : null;
        } catch (\Throwable $e) {
            $this->logger->error(
                'PAYSTAND-CAPTURE-SNAPSHOT: could not re-read snapshot: ' . $e->getMessage()
            );
            return null;
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
            $this->logger->warning(
                'PAYSTAND-CAPTURE-SNAPSHOT: corrupt snapshot JSON on quote '
                . $quote->getId()
                . ': ' . $e->getMessage()
            );
            try {
                CloudLogger::ship(CloudLogger::EVENT_CAPTURE_SNAPSHOT_CORRUPT, [
                    'quote_id' => (string)$quote->getId(),
                    'payment_id' => (string)$quote->getData('paystand_payment_id'),
                    'error_message' => $e->getMessage(),
                ]);
            } catch (\Throwable $ignored) {
            }
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * The stamped hash, or '' when the payload carries none it can use.
     * Callers that act on a mismatch need those two cases apart: a malformed
     * stamp is not evidence the shopper changed the cart.
     *
     * @param array<string, mixed> $payload
     */
    public function stampedHash(array $payload): string
    {
        $expected = isset($payload['hash']) ? (string)$payload['hash'] : '';
        if ($expected === '' || !preg_match('/^[a-f0-9]{64}$/D', $expected)) {
            return '';
        }

        return $expected;
    }

    /**
     * @param mixed $quote Magento quote
     * @param array<string, mixed> $payload
     */
    public function matches($quote, array $payload): bool
    {
        $expected = $this->stampedHash($payload);
        if ($expected === '') {
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
