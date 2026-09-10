<?php

declare(strict_types=1);

namespace PayStand\PayStandMagento\Model\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Math\Random;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteValidator;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PayStand\PayStandMagento\Model\Directpost;

/**
 * Performs the last authoritative Magento orderability check and consumes one
 * start permission before the browser is allowed to open Paystand.
 */
class AttemptService
{
    private const TOKEN_LENGTH = 64;
    private const PREPARED_TTL_SECONDS = 600;

    /** @var CheckoutSession */
    private $session;
    /** @var CartRepositoryInterface */
    private $quotes;
    /** @var QuoteValidator */
    private $quoteValidator;
    /** @var StoreManagerInterface */
    private $stores;
    /** @var ScopeConfigInterface */
    private $config;
    /** @var LockManagerInterface */
    private $locks;
    /** @var Random */
    private $random;
    /** @var AttemptRepository */
    private $attempts;

    public function __construct(
        CheckoutSession $session,
        CartRepositoryInterface $quotes,
        QuoteValidator $quoteValidator,
        StoreManagerInterface $stores,
        ScopeConfigInterface $config,
        LockManagerInterface $locks,
        Random $random,
        AttemptRepository $attempts
    ) {
        $this->session = $session;
        $this->quotes = $quotes;
        $this->quoteValidator = $quoteValidator;
        $this->stores = $stores;
        $this->config = $config;
        $this->locks = $locks;
        $this->random = $random;
        $this->attempts = $attempts;
    }

    /**
     * Validate, reserve, and persist a short-lived start permission.
     */
    public function prepare(): array
    {
        $sessionQuote = $this->session->getQuote();
        $quoteId = (int)$sessionQuote->getId();
        if ($quoteId <= 0) {
            throw new \DomainException('active-session-quote-required');
        }

        return $this->withCartLock($quoteId, function () use ($quoteId): array {
            $quote = $this->loadOrderableQuote($quoteId, true);
            $connection = $this->attempts->connection();
            $connection->beginTransaction();
            try {
                $existing = $this->attempts->findByQuoteId($quoteId, true);
                if ($existing && $existing['state'] !== AttemptRepository::STATE_PREPARED) {
                    throw new \DomainException('payment-initiation-already-claimed');
                }
                $this->assertNoPaymentOrOrder($quote);

                $snapshot = $this->snapshot($quote);
                $token = $this->random->getRandomString(self::TOKEN_LENGTH);
                if (!preg_match('/^[A-Za-z0-9]{64}$/D', $token)) {
                    throw new \RuntimeException('secure-attempt-token-unavailable');
                }
                $now = gmdate('Y-m-d H:i:s');
                $version = $existing ? ((int)$existing['version'] + 1) : 1;
                $this->attempts->replacePrepared([
                    'quote_id' => $quoteId,
                    'store_id' => (int)$quote->getStoreId(),
                    'customer_id' => $quote->getCustomerId() ? (int)$quote->getCustomerId() : null,
                    'attempt_token_hash' => hash('sha256', $token),
                    'session_hash' => $this->sessionHash($quoteId, (int)$quote->getStoreId()),
                    'merchant_id' => $snapshot['merchantId'],
                    'checkout_id' => $snapshot['checkoutId'],
                    'reserved_order_id' => $snapshot['reservedOrderId'],
                    'amount' => $snapshot['amount'],
                    'currency' => $snapshot['currency'],
                    'snapshot_version' => $snapshot['snapshotVersion'],
                    'state' => AttemptRepository::STATE_PREPARED,
                    'payment_may_be_started' => 1,
                    'provider_payment_id' => null,
                    'provider_status' => null,
                    'order_increment_id' => null,
                    'last_error_code' => null,
                    'version' => $version,
                    'expires_at' => gmdate('Y-m-d H:i:s', time() + self::PREPARED_TTL_SECONDS),
                    'provider_started_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
                $connection->commit();
            } catch (\Throwable $error) {
                $connection->rollBack();
                throw $error;
            }

            return array_merge($snapshot, [
                'attemptToken' => $token,
                'state' => AttemptRepository::STATE_PREPARED,
                'paymentMayBeStarted' => false
            ]);
        });
    }

    /**
     * Atomically consume the permission immediately before opening Paystand.
     */
    public function start(string $token): array
    {
        if (!preg_match('/^[A-Za-z0-9]{64}$/D', $token)) {
            throw new \DomainException('valid-attempt-token-required');
        }
        $tokenHash = hash('sha256', $token);
        $candidate = $this->attempts->findByTokenHash($tokenHash);
        if (!$candidate) {
            throw new \DomainException('prepared-attempt-not-found');
        }
        $quoteId = (int)$candidate['quote_id'];

        return $this->withCartLock($quoteId, function () use ($quoteId, $tokenHash, $token): array {
            if ((int)$this->session->getQuote()->getId() !== $quoteId) {
                throw new \DomainException('active-session-quote-changed');
            }
            $quote = $this->loadOrderableQuote($quoteId, false);
            $this->assertNoPaymentOrOrder($quote);
            $snapshot = $this->snapshot($quote);
            $connection = $this->attempts->connection();
            $connection->beginTransaction();
            try {
                $attempt = $this->attempts->findByTokenHash($tokenHash, true);
                if (!$attempt || (int)$attempt['quote_id'] !== $quoteId) {
                    throw new \DomainException('prepared-attempt-not-found');
                }
                if (!hash_equals((string)$attempt['session_hash'], $this->sessionHash($quoteId, (int)$quote->getStoreId()))) {
                    throw new \DomainException('prepared-attempt-session-mismatch');
                }
                if ((string)$attempt['state'] !== AttemptRepository::STATE_PREPARED
                    || (int)$attempt['payment_may_be_started'] !== 1
                ) {
                    throw new \DomainException('payment-initiation-already-claimed');
                }
                if (strtotime((string)$attempt['expires_at']) < time()) {
                    throw new \DomainException('prepared-attempt-expired');
                }
                if (!hash_equals((string)$attempt['snapshot_version'], $snapshot['snapshotVersion'])) {
                    throw new \DomainException('quote-changed-after-preflight');
                }
                $startedAt = gmdate('Y-m-d H:i:s');
                if (!$this->attempts->transitionToProviderStarted(
                    (int)$attempt['attempt_id'],
                    (int)$attempt['version'],
                    $startedAt
                )) {
                    throw new \DomainException('payment-initiation-race-refused');
                }
                $connection->commit();
            } catch (\Throwable $error) {
                $connection->rollBack();
                throw $error;
            }

            return array_merge($snapshot, [
                'attemptToken' => $token,
                'state' => AttemptRepository::STATE_PROVIDER_STARTED,
                'paymentMayBeStarted' => true
            ]);
        });
    }

    /**
     * Persist browser-reported payment memory before touching the quote. This is
     * not settlement evidence; the webhook/provider refetch must confirm it.
     */
    public function recordBrowserPayment(
        string $token,
        int $quoteId,
        string $paymentId,
        string $providerStatus
    ): array {
        if (!preg_match('/^[A-Za-z0-9]{64}$/D', $token)
            || !preg_match('/^[A-Za-z0-9_-]{16,160}$/D', $paymentId)
        ) {
            throw new \DomainException('valid-attempt-and-payment-required');
        }
        $providerStatus = strtolower(trim($providerStatus));
        $recognizedStatus = in_array($providerStatus, ['created', 'processing', 'posted', 'paid'], true);
        if (!preg_match('/^[a-z0-9_-]{1,32}$/D', $providerStatus)) {
            $providerStatus = 'unknown';
            $recognizedStatus = false;
        }

        $tokenHash = hash('sha256', $token);
        return $this->withCartLock($quoteId, function () use (
            $tokenHash,
            $quoteId,
            $paymentId,
            $providerStatus,
            $recognizedStatus
        ): array {
            $connection = $this->attempts->connection();
            $connection->beginTransaction();
            try {
                $attempt = $this->attempts->findByTokenHash($tokenHash, true);
                if (!$attempt || (int)$attempt['quote_id'] !== $quoteId) {
                    throw new \DomainException('prepared-attempt-not-found');
                }
                if (!hash_equals(
                    (string)$attempt['session_hash'],
                    $this->sessionHash($quoteId, (int)$attempt['store_id'])
                )) {
                    throw new \DomainException('prepared-attempt-session-mismatch');
                }
                if (in_array((string)$attempt['state'], [
                    AttemptRepository::STATE_BROWSER_REPORTED,
                    AttemptRepository::STATE_HELD,
                    AttemptRepository::STATE_ORDER_PLACED
                ], true)) {
                    if (!hash_equals((string)$attempt['provider_payment_id'], $paymentId)) {
                        throw new \DomainException('immutable-payment-reference-conflict');
                    }
                    $connection->commit();
                    return [
                        'state' => (string)$attempt['state'],
                        'paymentMayBeStarted' => false
                    ];
                }
                if ((string)$attempt['state'] !== AttemptRepository::STATE_PROVIDER_STARTED) {
                    throw new \DomainException('payment-initiation-not-claimed');
                }

                $nextState = $recognizedStatus
                    ? AttemptRepository::STATE_BROWSER_REPORTED
                    : AttemptRepository::STATE_HELD;
                $errorCode = $recognizedStatus ? null : 'unrecognized-provider-status';
                try {
                    // Re-run the complete Magento orderability contract after the
                    // modal closes. Provider memory is persisted even when this
                    // check fails, but order placement is held.
                    $quote = $this->loadOrderableQuote($quoteId, false);
                    $snapshot = $this->snapshot($quote);
                    if ($nextState !== AttemptRepository::STATE_HELD
                        && !hash_equals((string)$attempt['snapshot_version'], $snapshot['snapshotVersion'])
                    ) {
                        $nextState = AttemptRepository::STATE_HELD;
                        $errorCode = 'quote-changed-after-provider-start';
                    }
                } catch (\Throwable $validationError) {
                    $nextState = AttemptRepository::STATE_HELD;
                    $errorCode = 'quote-unorderable-after-provider-start';
                }
                if (!$this->attempts->transitionToBrowserReported(
                    (int)$attempt['attempt_id'],
                    (int)$attempt['version'],
                    $paymentId,
                    $providerStatus,
                    gmdate('Y-m-d H:i:s'),
                    $nextState,
                    $errorCode
                )) {
                    throw new \DomainException('payment-memory-race-refused');
                }
                $connection->commit();
            } catch (\Throwable $error) {
                $connection->rollBack();
                throw $error;
            }

            return [
                'state' => $nextState,
                'paymentMayBeStarted' => false
            ];
        });
    }

    /**
     * Return the session-bound durable lifecycle without exposing token hashes.
     */
    public function status(string $token): array
    {
        if (!preg_match('/^[A-Za-z0-9]{64}$/D', $token)) {
            throw new \DomainException('valid-attempt-token-required');
        }
        $attempt = $this->attempts->findByTokenHash(hash('sha256', $token));
        if (!$attempt || !hash_equals(
            (string)$attempt['session_hash'],
            $this->sessionHash((int)$attempt['quote_id'], (int)$attempt['store_id'])
        )) {
            throw new \DomainException('prepared-attempt-not-found');
        }

        $incrementId = $this->attempts->findOrderIncrementForQuote((int)$attempt['quote_id']);
        if ($incrementId !== null && in_array((string)$attempt['state'], [
            AttemptRepository::STATE_BROWSER_REPORTED,
            AttemptRepository::STATE_ORDER_PLACED
        ], true)) {
            $this->attempts->markOrderPlacedByQuote(
                (int)$attempt['quote_id'],
                $incrementId,
                gmdate('Y-m-d H:i:s')
            );
            $attempt = $this->attempts->findByTokenHash(hash('sha256', $token)) ?? $attempt;
        }

        return [
            'state' => (string)$attempt['state'],
            'paymentMayBeStarted' => false,
            'quoteId' => (string)$attempt['quote_id'],
            'checkoutId' => (string)$attempt['checkout_id'],
            'providerPaymentId' => $attempt['provider_payment_id'] !== null
                ? (string)$attempt['provider_payment_id']
                : null,
            'orderIncrementId' => $attempt['order_increment_id'] !== null
                ? (string)$attempt['order_increment_id']
                : null,
            'requiresOperatorReview' => (string)$attempt['state'] === AttemptRepository::STATE_HELD
        ];
    }

    private function loadOrderableQuote(int $quoteId, bool $persistReservation): Quote
    {
        /** @var Quote $quote */
        $quote = $this->quotes->getActive($quoteId);
        if (!$quote->getIsActive() || !$quote->getAllVisibleItems()) {
            throw new \DomainException('active-nonempty-quote-required');
        }
        if ((int)$quote->getStoreId() !== (int)$this->stores->getStore()->getId()) {
            throw new \DomainException('quote-store-mismatch');
        }
        if (!$quote->getCustomerId() && trim((string)$quote->getCustomerEmail()) === '') {
            $billing = $quote->getBillingAddress();
            $quote->setCustomerEmail($billing ? trim((string)$billing->getEmail()) : '');
        }
        if (!$quote->getCustomerId() && trim((string)$quote->getCustomerEmail()) === '') {
            throw new \DomainException('guest-email-required');
        }

        $payment = $quote->getPayment();
        $payment->setQuote($quote);
        $payment->setMethod(Directpost::METHOD_CODE);
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $method = $payment->getMethodInstance();
        if (!$method || !$method->isAvailable($quote)) {
            throw new \DomainException('paystand-payment-method-unavailable');
        }
        $this->quoteValidator->validateBeforeSubmit($quote);

        if ($persistReservation) {
            $quote->reserveOrderId();
            $this->quotes->save($quote);
            $quote = $this->quotes->getActive($quoteId);
            $this->quoteValidator->validateBeforeSubmit($quote);
        }
        if (trim((string)$quote->getReservedOrderId()) === '') {
            throw new \DomainException('reserved-order-id-required');
        }
        return $quote;
    }

    private function assertNoPaymentOrOrder(Quote $quote): void
    {
        if (trim((string)$quote->getData('paystand_payment_id')) !== ''
            || $this->attempts->orderExistsForQuote((int)$quote->getId())
        ) {
            throw new \DomainException('payment-or-order-already-recorded');
        }
    }

    private function snapshot(Quote $quote): array
    {
        $amount = $this->currencyAmount((string)$quote->getGrandTotal());
        if ($amount === '0.00') {
            throw new \DomainException('positive-order-total-required');
        }
        $currency = strtoupper(trim((string)$quote->getQuoteCurrencyCode()));
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new \DomainException('valid-quote-currency-required');
        }
        $storeId = (int)$quote->getStoreId();
        $merchantId = trim((string)$this->config->getValue(
            'payment/paystandmagento/customer_id',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
        $publishableKey = trim((string)$this->config->getValue(
            'payment/paystandmagento/publishable_key',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
        $preset = trim((string)$this->config->getValue(
            'payment/paystandmagento/checkout_preset_key',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $merchantId)
            || !preg_match('/^[A-Za-z0-9_-]{1,160}$/D', $publishableKey)
            || !preg_match('/^[A-Za-z0-9_-]{1,160}$/D', $preset)
        ) {
            throw new \DomainException('paystand-public-configuration-required');
        }

        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $items[] = [
                'id' => (string)$item->getId(),
                'productId' => (string)$item->getProductId(),
                'sku' => (string)$item->getSku(),
                'qty' => (string)$item->getQty(),
                'rowTotal' => (string)$item->getRowTotal(),
                'baseRowTotal' => (string)$item->getBaseRowTotal()
            ];
        }
        usort($items, static function (array $left, array $right): int {
            return strcmp($left['id'], $right['id']);
        });
        $canonical = [
            'quoteId' => (string)$quote->getId(),
            'storeId' => (string)$storeId,
            'customerId' => (string)$quote->getCustomerId(),
            'customerEmail' => strtolower(trim((string)$quote->getCustomerEmail())),
            'reservedOrderId' => (string)$quote->getReservedOrderId(),
            'amount' => $amount,
            'currency' => $currency,
            'merchantId' => $merchantId,
            'publishableKey' => $publishableKey,
            'presetCustom' => $preset,
            'isVirtual' => (bool)$quote->isVirtual(),
            'billing' => $this->addressSnapshot($quote->getBillingAddress()),
            'shipping' => $quote->isVirtual() ? null : $this->addressSnapshot($quote->getShippingAddress()),
            'shippingMethod' => $quote->isVirtual() ? null : (string)$quote->getShippingAddress()->getShippingMethod(),
            'items' => $items
        ];
        $version = hash('sha256', json_encode(
            $canonical,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));
        return [
            'merchantId' => $merchantId,
            'publishableKey' => $publishableKey,
            'presetCustom' => $preset,
            'storefrontId' => (string)$storeId,
            'checkoutId' => 'quote:' . (string)$quote->getId(),
            'quoteId' => (string)$quote->getId(),
            'reservedOrderId' => (string)$quote->getReservedOrderId(),
            'amount' => $amount,
            'currency' => $currency,
            'snapshotVersion' => $version
        ];
    }

    private function addressSnapshot($address): array
    {
        if (!$address) {
            return [];
        }
        return [
            'firstName' => trim((string)$address->getFirstname()),
            'lastName' => trim((string)$address->getLastname()),
            'email' => strtolower(trim((string)$address->getEmail())),
            'street' => array_values(array_map('strval', (array)$address->getStreet())),
            'city' => trim((string)$address->getCity()),
            'regionId' => (string)$address->getRegionId(),
            'regionCode' => trim((string)$address->getRegionCode()),
            'postcode' => trim((string)$address->getPostcode()),
            'countryId' => strtoupper(trim((string)$address->getCountryId())),
            'telephone' => trim((string)$address->getTelephone())
        ];
    }

    private function sessionHash(int $quoteId, int $storeId): string
    {
        $sessionId = (string)$this->session->getSessionId();
        if ($sessionId === '') {
            throw new \DomainException('checkout-session-required');
        }
        return hash('sha256', $sessionId . '|' . $storeId . '|' . $quoteId);
    }

    private function withCartLock(int $quoteId, callable $operation): array
    {
        $lockName = 'cart_lock_' . $quoteId;
        if (!$this->locks->lock($lockName, 0)) {
            throw new \DomainException('quote-is-being-processed');
        }
        try {
            return $operation();
        } finally {
            $this->locks->unlock($lockName);
        }
    }

    private function currencyAmount(string $raw): string
    {
        if (!preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $raw, $match)) {
            throw new \DomainException('exact-decimal-total-required');
        }
        $fraction = str_pad($match[2] ?? '', 2, '0');
        if (strlen($fraction) > 2 && trim(substr($fraction, 2), '0') !== '') {
            throw new \DomainException('sub-cent-total-refused');
        }
        return $match[1] . '.' . substr($fraction, 0, 2);
    }
}
