<?php

declare(strict_types=1);

namespace Magento\Checkout\Model {
    class Session
    {
        public function __construct(public object $quote, public string $sessionId = 'session-1') {}
        public function getQuote(): object { return $this->quote; }
        public function getSessionId(): string { return $this->sessionId; }
    }
}

namespace Magento\Framework\App\Config {
    interface ScopeConfigInterface { public function getValue($path, $scopeType = null, $scopeCode = null); }
}

namespace Magento\Framework\Lock {
    interface LockManagerInterface
    {
        public function lock(string $name, int $timeout = -1): bool;
        public function unlock(string $name): bool;
    }
}

namespace Magento\Framework\Math {
    class Random { public function getRandomString($length, $chars = null) { return str_repeat('A', $length); } }
}

namespace Magento\Quote\Api {
    interface CartRepositoryInterface
    {
        public function get($cartId, array $sharedStoreIds = []);
        public function getActive($cartId, array $sharedStoreIds = []);
        public function save(\Magento\Quote\Api\Data\CartInterface $quote);
    }
}

namespace Magento\Quote\Api\Data { interface CartInterface {} }

namespace Magento\Quote\Model {
    class Quote implements \Magento\Quote\Api\Data\CartInterface {}
    class QuoteValidator
    {
        public bool $reject = false;
        public int $calls = 0;
        public function validateBeforeSubmit(Quote $quote): void
        {
            $this->calls++;
            if ($this->reject) {
                throw new \DomainException('synthetic-magento-refusal');
            }
        }
    }
}

namespace Magento\Store\Model {
    class ScopeInterface { public const SCOPE_STORE = 'store'; }
    interface StoreManagerInterface { public function getStore($storeId = null); }
}

namespace PayStand\PayStandMagento\Model {
    class Directpost { public const METHOD_CODE = 'paystandmagento'; }
}

namespace PayStand\PayStandMagento\Model\Checkout {
    class AttemptRepository
    {
        public const STATE_PREPARED = 'prepared';
        public const STATE_PROVIDER_STARTED = 'provider_started';
        public const STATE_BROWSER_REPORTED = 'browser_reported';
        public const STATE_ORDER_PLACED = 'order_placed';
        public const STATE_HELD = 'held';
        public ?array $row = null;
        public object $transaction;
        public function __construct() { $this->transaction = new \ServiceTransactionFake(); }
        public function connection(): object { return $this->transaction; }
        public function findByQuoteId(int $quoteId, bool $forUpdate = false): ?array
        { return $this->row && (int)$this->row['quote_id'] === $quoteId ? $this->row : null; }
        public function findByTokenHash(string $hash, bool $forUpdate = false): ?array
        { return $this->row && $this->row['attempt_token_hash'] === $hash ? $this->row : null; }
        public function replacePrepared(array $row): void { $this->row = ['attempt_id' => 1] + $row; }
        public function transitionToProviderStarted(int $attemptId, int $version, string $startedAt): bool
        {
            if (!$this->row || $this->row['state'] !== self::STATE_PREPARED
                || (int)$this->row['version'] !== $version || (int)$this->row['payment_may_be_started'] !== 1) {
                return false;
            }
            $this->row['state'] = self::STATE_PROVIDER_STARTED;
            $this->row['payment_may_be_started'] = 0;
            $this->row['version'] = $version + 1;
            $this->row['provider_started_at'] = $startedAt;
            return true;
        }
        public function transitionToBrowserReported(
            int $attemptId,
            int $version,
            string $paymentId,
            string $providerStatus,
            string $updatedAt,
            string $nextState = self::STATE_BROWSER_REPORTED,
            ?string $errorCode = null
        ): bool {
            if (!$this->row || $this->row['state'] !== self::STATE_PROVIDER_STARTED
                || (int)$this->row['version'] !== $version) {
                return false;
            }
            $this->row['state'] = $nextState;
            $this->row['provider_payment_id'] = $paymentId;
            $this->row['provider_status'] = $providerStatus;
            $this->row['last_error_code'] = $errorCode;
            $this->row['version'] = $version + 1;
            return true;
        }
        public function orderExistsForQuote(int $quoteId): bool { return false; }
        public function findOrderIncrementForQuote(int $quoteId): ?string { return null; }
        public function markOrderPlacedByQuote(int $quoteId, string $incrementId, string $updatedAt): void {}
    }
}

namespace {
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    final class ServiceTransactionFake
    {
        public int $begins = 0;
        public int $commits = 0;
        public int $rollbacks = 0;
        public function beginTransaction(): void { $this->begins++; }
        public function commit(): void { $this->commits++; }
        public function rollBack(): void { $this->rollbacks++; }
    }

    final class LockFake implements \Magento\Framework\Lock\LockManagerInterface
    {
        public array $locks = [];
        public function lock(string $name, int $timeout = -1): bool { $this->locks[] = $name; return true; }
        public function unlock(string $name): bool { return true; }
    }

    final class ConfigFake implements \Magento\Framework\App\Config\ScopeConfigInterface
    {
        public function getValue($path, $scopeType = null, $scopeCode = null)
        {
            return [
                'payment/paystandmagento/customer_id' => 'merchant_1',
                'payment/paystandmagento/publishable_key' => 'publishable_1',
                'payment/paystandmagento/checkout_preset_key' => 'preset_1'
            ][$path] ?? null;
        }
    }

    final class StoreManagerFake implements \Magento\Store\Model\StoreManagerInterface
    {
        public function getStore($storeId = null) { return new class { public function getId(): int { return 2; } }; }
    }

    final class MethodFake { public function isAvailable($quote): bool { return true; } }
    final class PaymentFake
    {
        public function setQuote($quote): self { return $this; }
        public function setMethod($method): self { return $this; }
        public function getMethodInstance(): object { return new MethodFake(); }
    }
    final class AddressFake
    {
        public function getFirstname(): string { return 'A'; }
        public function getLastname(): string { return 'Buyer'; }
        public function getEmail(): string { return 'buyer@example.invalid'; }
        public function getStreet(): array { return ['1 Test Way']; }
        public function getCity(): string { return 'Test'; }
        public function getRegionId(): int { return 12; }
        public function getRegionCode(): string { return 'CA'; }
        public function getPostcode(): string { return '90001'; }
        public function getCountryId(): string { return 'US'; }
        public function getTelephone(): string { return '5555555555'; }
        public function getShippingMethod(): string { return 'flatrate_flatrate'; }
    }
    final class ItemFake
    {
        public function getId(): int { return 9; }
        public function getProductId(): int { return 3; }
        public function getSku(): string { return 'sku-1'; }
        public function getQty(): int { return 1; }
        public function getRowTotal(): string { return '10.00'; }
        public function getBaseRowTotal(): string { return '10.00'; }
    }
    final class QuoteFake extends \Magento\Quote\Model\Quote
    {
        public int $id;
        public string $grandTotal = '10.00';
        public string $reservedOrderId = '';
        private PaymentFake $payment;
        private AddressFake $address;
        public function __construct(int $id = 41) { $this->id = $id; $this->payment = new PaymentFake(); $this->address = new AddressFake(); }
        public function getId(): int { return $this->id; }
        public function getIsActive(): bool { return true; }
        public function getAllVisibleItems(): array { return [new ItemFake()]; }
        public function getStoreId(): int { return 2; }
        public function getCustomerId(): int { return 5; }
        public function getCustomerEmail(): string { return 'buyer@example.invalid'; }
        public function setCustomerEmail($email): self { return $this; }
        public function getPayment(): PaymentFake { return $this->payment; }
        public function setTotalsCollectedFlag($flag): self { return $this; }
        public function collectTotals(): self { return $this; }
        public function reserveOrderId(): self { $this->reservedOrderId = '000000041'; return $this; }
        public function getReservedOrderId(): string { return $this->reservedOrderId; }
        public function getData($key) { return null; }
        public function getGrandTotal(): string { return $this->grandTotal; }
        public function getQuoteCurrencyCode(): string { return 'USD'; }
        public function isVirtual(): bool { return false; }
        public function getBillingAddress(): AddressFake { return $this->address; }
        public function getShippingAddress(): AddressFake { return $this->address; }
    }

    final class QuotesFake implements \Magento\Quote\Api\CartRepositoryInterface
    {
        public int $saves = 0;
        public function __construct(public QuoteFake $quote) {}
        public function get($cartId, array $sharedStoreIds = []) { return $this->quote; }
        public function getActive($cartId, array $sharedStoreIds = []) { return $this->quote; }
        public function save(\Magento\Quote\Api\Data\CartInterface $quote) { $this->saves++; return $quote; }
    }

    function expectService(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }

    require dirname(__DIR__, 2) . '/Model/Checkout/AttemptService.php';

    $quote = new QuoteFake();
    $session = new \Magento\Checkout\Model\Session($quote);
    $quotes = new QuotesFake($quote);
    $validator = new \Magento\Quote\Model\QuoteValidator();
    $locks = new LockFake();
    $attempts = new \PayStand\PayStandMagento\Model\Checkout\AttemptRepository();
    $service = new \PayStand\PayStandMagento\Model\Checkout\AttemptService(
        $session,
        $quotes,
        $validator,
        new StoreManagerFake(),
        new ConfigFake(),
        $locks,
        new \Magento\Framework\Math\Random(),
        $attempts
    );

    $prepared = $service->prepare();
    expectService($prepared['state'] === 'prepared' && $prepared['paymentMayBeStarted'] === false,
        'Prepare exposed provider permission');
    expectService($prepared['amount'] === '10.00' && $prepared['currency'] === 'USD'
        && $prepared['publishableKey'] === 'publishable_1' && $prepared['presetCustom'] === 'preset_1',
        'Prepare did not bind authoritative payment configuration');
    expectService($validator->calls === 2 && $quotes->saves === 1,
        'Prepare did not validate before and after reservation');

    $started = $service->start($prepared['attemptToken']);
    expectService($started['state'] === 'provider_started' && $started['paymentMayBeStarted'] === true
        && $started['attemptToken'] === $prepared['attemptToken'],
        'Start claim was not returned after CAS');
    expectService($locks->locks === ['cart_lock_41', 'cart_lock_41'],
        'Service does not share Magento cart lock');
    try {
        $service->start($prepared['attemptToken']);
        throw new RuntimeException('Reused start token was accepted');
    } catch (DomainException $expected) {
        expectService($expected->getMessage() === 'payment-initiation-already-claimed',
            'Reused start token produced wrong refusal');
    }

    $quote->grandTotal = '11.00';
    $reported = $service->recordBrowserPayment(
        $prepared['attemptToken'],
        41,
        'payment_12345678',
        'posted'
    );
    expectService($reported['state'] === 'held' && $attempts->row['provider_payment_id'] === 'payment_12345678'
        && $attempts->row['last_error_code'] === 'quote-changed-after-provider-start',
        'Changed quote did not preserve provider memory in held state');

    $unknownQuote = new QuoteFake();
    $unknownAttempts = new \PayStand\PayStandMagento\Model\Checkout\AttemptRepository();
    $unknownService = new \PayStand\PayStandMagento\Model\Checkout\AttemptService(
        new \Magento\Checkout\Model\Session($unknownQuote, 'session-unknown'),
        new QuotesFake($unknownQuote),
        new \Magento\Quote\Model\QuoteValidator(),
        new StoreManagerFake(),
        new ConfigFake(),
        new LockFake(),
        new \Magento\Framework\Math\Random(),
        $unknownAttempts
    );
    $unknownPrepared = $unknownService->prepare();
    $unknownService->start($unknownPrepared['attemptToken']);
    $unknownReported = $unknownService->recordBrowserPayment(
        $unknownPrepared['attemptToken'],
        41,
        'payment_abcdefgh',
        'future_status'
    );
    expectService($unknownReported['state'] === 'held'
        && $unknownAttempts->row['provider_payment_id'] === 'payment_abcdefgh'
        && $unknownAttempts->row['last_error_code'] === 'unrecognized-provider-status',
        'Unknown provider status erased payment memory or remained orderable');

    $switchedQuote = new QuoteFake();
    $switchedSession = new \Magento\Checkout\Model\Session($switchedQuote, 'session-switch');
    $switchedAttempts = new \PayStand\PayStandMagento\Model\Checkout\AttemptRepository();
    $switchedService = new \PayStand\PayStandMagento\Model\Checkout\AttemptService(
        $switchedSession,
        new QuotesFake($switchedQuote),
        new \Magento\Quote\Model\QuoteValidator(),
        new StoreManagerFake(),
        new ConfigFake(),
        new LockFake(),
        new \Magento\Framework\Math\Random(),
        $switchedAttempts
    );
    $switchedPrepared = $switchedService->prepare();
    $switchedSession->quote = new QuoteFake(42);
    try {
        $switchedService->start($switchedPrepared['attemptToken']);
        throw new RuntimeException('Old cart attempt started after session cart switch');
    } catch (DomainException $expected) {
        expectService($expected->getMessage() === 'active-session-quote-changed',
            'Cart-switch start refusal changed');
    }
    expectService($switchedAttempts->row['state'] === 'prepared',
        'Cart-switch refusal consumed provider permission');

    $refusalQuote = new QuoteFake();
    $refusalValidator = new \Magento\Quote\Model\QuoteValidator();
    $refusalValidator->reject = true;
    $refusalAttempts = new \PayStand\PayStandMagento\Model\Checkout\AttemptRepository();
    $refusalService = new \PayStand\PayStandMagento\Model\Checkout\AttemptService(
        new \Magento\Checkout\Model\Session($refusalQuote, 'session-2'),
        new QuotesFake($refusalQuote),
        $refusalValidator,
        new StoreManagerFake(),
        new ConfigFake(),
        new LockFake(),
        new \Magento\Framework\Math\Random(),
        $refusalAttempts
    );
    try {
        $refusalService->prepare();
        throw new RuntimeException('Magento validation refusal still prepared payment');
    } catch (DomainException $expected) {
        expectService($expected->getMessage() === 'synthetic-magento-refusal',
            'Magento validation refusal was not preserved');
    }
    expectService($refusalAttempts->row === null,
        'Refused Magento quote created a provider-start authority');

    echo json_encode([
        'ok' => true,
        'prepareValidations' => 2,
        'providerStartClaims' => 1,
        'reusedStartRefused' => true,
        'changedQuoteHeld' => true,
        'unknownStatusHeld' => true,
        'sessionCartSwitchRefused' => true,
        'magentoRefusalCreatesAttempts' => 0
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
