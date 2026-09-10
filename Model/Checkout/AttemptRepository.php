<?php

declare(strict_types=1);

namespace PayStand\PayStandMagento\Model\Checkout;

use Magento\Framework\App\ResourceConnection;

/**
 * The checkout-attempt row is the local money-safety authority. It deliberately
 * has no quote foreign key: Magento quote cleanup must not erase payment memory.
 */
class AttemptRepository
{
    public const STATE_PREPARED = 'prepared';
    public const STATE_PROVIDER_STARTED = 'provider_started';
    public const STATE_BROWSER_REPORTED = 'browser_reported';
    public const STATE_ORDER_PLACED = 'order_placed';
    public const STATE_HELD = 'held';

    private const TABLE = 'paystand_checkout_attempt';
    private const OUTBOX_TABLE = 'paystand_checkout_outbox';

    /** @var ResourceConnection */
    private $resources;

    public function __construct(ResourceConnection $resources)
    {
        $this->resources = $resources;
    }

    /** @return \Magento\Framework\DB\Adapter\AdapterInterface */
    public function connection()
    {
        return $this->resources->getConnection();
    }

    public function table(): string
    {
        return $this->resources->getTableName(self::TABLE);
    }

    public function findByQuoteId(int $quoteId, bool $forUpdate = false): ?array
    {
        $select = $this->connection()->select()
            ->from($this->table())
            ->where('quote_id = ?', $quoteId)
            ->limit(1);
        if ($forUpdate) {
            $select->forUpdate(true);
        }
        $row = $this->connection()->fetchRow($select);
        return is_array($row) && $row !== [] ? $row : null;
    }

    public function findByTokenHash(string $tokenHash, bool $forUpdate = false): ?array
    {
        $select = $this->connection()->select()
            ->from($this->table())
            ->where('attempt_token_hash = ?', $tokenHash)
            ->limit(1);
        if ($forUpdate) {
            $select->forUpdate(true);
        }
        $row = $this->connection()->fetchRow($select);
        return is_array($row) && $row !== [] ? $row : null;
    }

    public function replacePrepared(array $row): void
    {
        $this->connection()->insertOnDuplicate(
            $this->table(),
            $row,
            [
                'store_id',
                'customer_id',
                'attempt_token_hash',
                'session_hash',
                'merchant_id',
                'checkout_id',
                'reserved_order_id',
                'amount',
                'currency',
                'snapshot_version',
                'state',
                'payment_may_be_started',
                'provider_payment_id',
                'provider_status',
                'order_increment_id',
                'last_error_code',
                'version',
                'expires_at',
                'provider_started_at',
                'updated_at'
            ]
        );
    }

    public function transitionToProviderStarted(int $attemptId, int $version, string $startedAt): bool
    {
        $affected = $this->connection()->update(
            $this->table(),
            [
                'state' => self::STATE_PROVIDER_STARTED,
                'payment_may_be_started' => 0,
                'provider_started_at' => $startedAt,
                'version' => $version + 1,
                'updated_at' => $startedAt
            ],
            [
                'attempt_id = ?' => $attemptId,
                'version = ?' => $version,
                'state = ?' => self::STATE_PREPARED,
                'payment_may_be_started = ?' => 1
            ]
        );
        return $affected === 1;
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
        if (!in_array($nextState, [self::STATE_BROWSER_REPORTED, self::STATE_HELD], true)) {
            throw new \InvalidArgumentException('invalid-browser-report-state');
        }
        $affected = $this->connection()->update(
            $this->table(),
            [
                'state' => $nextState,
                'payment_may_be_started' => 0,
                'provider_payment_id' => $paymentId,
                'provider_status' => $providerStatus,
                'last_error_code' => $errorCode,
                'version' => $version + 1,
                'updated_at' => $updatedAt
            ],
            [
                'attempt_id = ?' => $attemptId,
                'version = ?' => $version,
                'state = ?' => self::STATE_PROVIDER_STARTED
            ]
        );
        return $affected === 1;
    }

    public function orderExistsForQuote(int $quoteId): bool
    {
        return $this->findOrderIncrementForQuote($quoteId) !== null;
    }

    public function findOrderIncrementForQuote(int $quoteId): ?string
    {
        $select = $this->connection()->select()
            ->from($this->resources->getTableName('sales_order'), 'increment_id')
            ->where('quote_id = ?', $quoteId)
            ->limit(1);
        $incrementId = $this->connection()->fetchOne($select);
        return $incrementId === false ? null : (string)$incrementId;
    }

    public function markOrderPlacedByQuote(int $quoteId, string $incrementId, string $updatedAt): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $attempt = $this->findByQuoteId($quoteId, true);
            if (!$attempt || !in_array((string)$attempt['state'], [
                self::STATE_BROWSER_REPORTED,
                self::STATE_ORDER_PLACED
            ], true)) {
                $connection->commit();
                return;
            }

            $connection->update(
                $this->table(),
                [
                    'state' => self::STATE_ORDER_PLACED,
                    'payment_may_be_started' => 0,
                    'order_increment_id' => $incrementId,
                    'last_error_code' => null,
                    'updated_at' => $updatedAt
                ],
                ['attempt_id = ?' => (int)$attempt['attempt_id']]
            );

            $payload = [
                'schemaVersion' => 1,
                'eventType' => 'magento.order_placed',
                'attemptId' => (string)$attempt['attempt_id'],
                'quoteId' => (string)$quoteId,
                'storeId' => (string)$attempt['store_id'],
                'merchantId' => (string)$attempt['merchant_id'],
                'checkoutId' => (string)$attempt['checkout_id'],
                'reservedOrderId' => (string)$attempt['reserved_order_id'],
                'orderIncrementId' => $incrementId,
                'providerPaymentId' => (string)$attempt['provider_payment_id'],
                'providerStatus' => (string)$attempt['provider_status'],
                'evidenceClass' => 'browser_report_only',
                'settlementConfirmed' => false,
                'snapshotVersion' => (string)$attempt['snapshot_version'],
                'occurredAt' => $updatedAt
            ];
            $connection->insertOnDuplicate(
                $this->resources->getTableName(self::OUTBOX_TABLE),
                [
                    'attempt_id' => (int)$attempt['attempt_id'],
                    'event_key' => 'attempt:' . (int)$attempt['attempt_id'] . ':order_placed:v1',
                    'event_type' => 'magento.order_placed',
                    'payload_json' => json_encode(
                        $payload,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
                    ),
                    'state' => 'pending',
                    'delivery_attempts' => 0,
                    'lease_token_hash' => null,
                    'lease_expires_at' => null,
                    'available_at' => $updatedAt,
                    'last_error_code' => null,
                    'created_at' => $updatedAt,
                    'updated_at' => $updatedAt
                ],
                ['updated_at']
            );
            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
    }

    /**
     * Repair an order-commit/outbox gap without performing any remote work.
     */
    public function repairCommittedOrders(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        $attemptTable = $this->table();
        $orderTable = $this->resources->getTableName('sales_order');
        $outboxTable = $this->resources->getTableName(self::OUTBOX_TABLE);
        $select = $this->connection()->select()
            ->from(['attempt' => $attemptTable], ['quote_id', 'state'])
            ->joinInner(
                ['sales_order' => $orderTable],
                'sales_order.quote_id = attempt.quote_id',
                ['increment_id']
            )
            ->joinLeft(
                ['checkout_outbox' => $outboxTable],
                "checkout_outbox.attempt_id = attempt.attempt_id AND checkout_outbox.event_type = 'magento.order_placed'",
                ['outbox_id']
            )
            ->where('attempt.state IN (?)', [self::STATE_BROWSER_REPORTED, self::STATE_ORDER_PLACED])
            ->where('(attempt.state != ? OR checkout_outbox.outbox_id IS NULL)', self::STATE_ORDER_PLACED)
            ->order('attempt.attempt_id ASC')
            ->limit($limit);
        $rows = $this->connection()->fetchAll($select);
        $repaired = 0;
        foreach ($rows as $row) {
            $this->markOrderPlacedByQuote(
                (int)$row['quote_id'],
                (string)$row['increment_id'],
                gmdate('Y-m-d H:i:s')
            );
            $repaired++;
        }
        return $repaired;
    }
}
