<?php

declare(strict_types=1);

namespace Magento\Framework\App {
    class ResourceConnection
    {
        public function __construct(private object $connection)
        {
        }

        public function getConnection(): object
        {
            return $this->connection;
        }

        public function getTableName(string $table): string
        {
            return 'prefix_' . $table;
        }
    }
}

namespace {
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    final class SelectFake
    {
        public array $calls = [];

        public function __call(string $name, array $arguments): self
        {
            $this->calls[] = [$name, $arguments];
            return $this;
        }
    }

    final class AttemptConnectionFake
    {
        public array $updates = [];
        public array $inserts = [];
        public int $begins = 0;
        public int $commits = 0;
        public int $rollbacks = 0;
        public bool $failInsert = false;

        public function __construct(public ?array $fetchRow = null, public int $updateResult = 1)
        {
        }

        public function select(): SelectFake { return new SelectFake(); }
        public function fetchRow($select): ?array { return $this->fetchRow; }
        public function update($table, array $data, array $where): int
        {
            $this->updates[] = [$table, $data, $where];
            return $this->updateResult;
        }
        public function insertOnDuplicate($table, array $data, array $fields): void
        {
            if ($this->failInsert) {
                throw new RuntimeException('synthetic-insert-failure');
            }
            $this->inserts[] = [$table, $data, $fields];
        }
        public function beginTransaction(): void { $this->begins++; }
        public function commit(): void { $this->commits++; }
        public function rollBack(): void { $this->rollbacks++; }
    }

    function expectRepository(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $root = dirname(__DIR__, 2);
    require $root . '/Model/Checkout/AttemptRepository.php';

    $connection = new AttemptConnectionFake();
    $repository = new \PayStand\PayStandMagento\Model\Checkout\AttemptRepository(
        new \Magento\Framework\App\ResourceConnection($connection)
    );
    expectRepository($repository->transitionToProviderStarted(7, 3, '2026-09-10 12:00:00'),
        'Prepared attempt did not transition');
    expectRepository($connection->updates[0][1]['state'] === 'provider_started',
        'Provider-start state missing');
    expectRepository($connection->updates[0][1]['payment_may_be_started'] === 0,
        'Provider permission was not consumed');
    expectRepository($connection->updates[0][2] === [
        'attempt_id = ?' => 7,
        'version = ?' => 3,
        'state = ?' => 'prepared',
        'payment_may_be_started = ?' => 1
    ], 'Provider-start CAS guard changed');

    expectRepository($repository->transitionToBrowserReported(
        7,
        4,
        'payment_12345678',
        'posted',
        '2026-09-10 12:01:00',
        'held',
        'quote-changed-after-provider-start'
    ), 'Held provider callback did not persist');
    expectRepository($connection->updates[1][1]['state'] === 'held'
        && $connection->updates[1][1]['provider_payment_id'] === 'payment_12345678'
        && $connection->updates[1][1]['last_error_code'] === 'quote-changed-after-provider-start',
        'Held state lost payment memory or bounded reason');

    try {
        $repository->transitionToBrowserReported(7, 4, 'payment_12345678', 'posted', 'now', 'invalid');
        throw new RuntimeException('Invalid callback state was accepted');
    } catch (InvalidArgumentException $expected) {
        expectRepository($expected->getMessage() === 'invalid-browser-report-state',
            'Invalid callback state returned an unstable error');
    }

    $attempt = [
        'attempt_id' => 7,
        'quote_id' => 41,
        'store_id' => 2,
        'merchant_id' => 'merchant',
        'checkout_id' => 'quote:41',
        'reserved_order_id' => '000000041',
        'provider_payment_id' => 'payment_12345678',
        'provider_status' => 'posted',
        'snapshot_version' => str_repeat('a', 64),
        'state' => 'browser_reported'
    ];
    $outboxConnection = new AttemptConnectionFake($attempt);
    $outboxRepository = new \PayStand\PayStandMagento\Model\Checkout\AttemptRepository(
        new \Magento\Framework\App\ResourceConnection($outboxConnection)
    );
    $outboxRepository->markOrderPlacedByQuote(41, '000000041', '2026-09-10 12:02:00');
    expectRepository($outboxConnection->begins === 1 && $outboxConnection->commits === 1
        && $outboxConnection->rollbacks === 0, 'Lifecycle transaction was not committed exactly once');
    expectRepository(count($outboxConnection->updates) === 1 && count($outboxConnection->inserts) === 1,
        'Order marker and outbox were not both written');
    $outbox = $outboxConnection->inserts[0][1];
    expectRepository($outbox['event_key'] === 'attempt:7:order_placed:v1',
        'Outbox idempotency key changed');
    $payload = json_decode($outbox['payload_json'], true, 32, JSON_THROW_ON_ERROR);
    expectRepository($payload['eventType'] === 'magento.order_placed'
        && $payload['providerPaymentId'] === 'payment_12345678'
        && $payload['evidenceClass'] === 'browser_report_only'
        && $payload['settlementConfirmed'] === false
        && !isset($payload['attemptToken']), 'Outbox payload is incomplete or exposes attempt token');

    $failingConnection = new AttemptConnectionFake($attempt);
    $failingConnection->failInsert = true;
    $failingRepository = new \PayStand\PayStandMagento\Model\Checkout\AttemptRepository(
        new \Magento\Framework\App\ResourceConnection($failingConnection)
    );
    try {
        $failingRepository->markOrderPlacedByQuote(41, '000000041', '2026-09-10 12:02:00');
        throw new RuntimeException('Synthetic outbox failure did not escape');
    } catch (RuntimeException $expected) {
        expectRepository($expected->getMessage() === 'synthetic-insert-failure',
            'Unexpected synthetic failure');
    }
    expectRepository($failingConnection->begins === 1 && $failingConnection->commits === 0
        && $failingConnection->rollbacks === 1, 'Outbox failure did not roll back lifecycle transaction');

    echo json_encode([
        'ok' => true,
        'providerStartCas' => true,
        'heldPaymentMemory' => true,
        'orderOutboxAtomicity' => true,
        'outboxFailureRollback' => true
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
