<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Helper;

use Magento\Framework\App\ResourceConnection;

/**
 * Writes the Paystand hold onto the quote and copies it onto the order.
 * The hold is not a payment id.
 */
class PreauthStore
{
    private const COLUMNS = [
        'paystand_preauth_id',
        'paystand_preauth_status',
        'paystand_preauth_amount',
        'paystand_preauth_amount_captured',
        'paystand_preauth_amount_remaining',
        'paystand_preauth_currency',
        'paystand_preauth_expires_at'
    ];

    /**
     * @var ResourceConnection
     */
    private $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    public function isHoldOnly($entity): bool
    {
        $preauthId = trim((string) $entity->getData('paystand_preauth_id'));
        $paymentId = trim((string) $entity->getData('paystand_payment_id'));

        return $preauthId !== '' && $paymentId === '';
    }

    public function applyHold($quote, array $data): void
    {
        $captured = $data['preauthAmountCaptured'] ?? 0;
        $amount = $data['preauthAmount'] ?? null;
        $remaining = $data['preauthAmountRemaining'] ?? $amount;

        $quote->setData('paystand_preauth_id', (string) ($data['preauthId'] ?? ''));
        $quote->setData('paystand_preauth_status', (string) ($data['preauthStatus'] ?? 'authorized'));
        $quote->setData('paystand_preauth_amount', $amount);
        $quote->setData('paystand_preauth_amount_captured', $captured);
        $quote->setData('paystand_preauth_amount_remaining', $remaining);
        $quote->setData('paystand_preauth_currency', (string) ($data['preauthCurrency'] ?? ''));
        $quote->setData('paystand_preauth_expires_at', $data['preauthExpiresAt'] ?? null);
    }

    public function applyRecord($entity, array $record, $paymentId = null): void
    {
        $amount = $record['amount'] ?? null;
        $captured = $record['amountCaptured'] ?? 0;
        $remaining = $record['amountRemaining'] ?? null;
        if ($remaining === null && $amount !== null) {
            $remaining = (float) $amount - (float) $captured;
        }

        $entity->setData('paystand_preauth_id', (string) ($record['id'] ?? $entity->getData('paystand_preauth_id')));
        $entity->setData('paystand_preauth_status', (string) ($record['status'] ?? ''));
        $entity->setData('paystand_preauth_amount', $amount);
        $entity->setData('paystand_preauth_amount_captured', $captured);
        $entity->setData('paystand_preauth_amount_remaining', $remaining);
        $entity->setData('paystand_preauth_currency', (string) ($record['currency'] ?? ''));
        $entity->setData('paystand_preauth_expires_at', $record['expiresAt'] ?? null);
        if ($paymentId) {
            $entity->setData('paystand_payment_id', (string) $paymentId);
        }
    }

    public function copyQuoteToOrder($quote, $order): void
    {
        foreach (self::COLUMNS as $column) {
            $order->setData($column, $quote->getData($column));
        }
    }

    public function persistQuote($quote): void
    {
        $this->persist('quote', (int) $quote->getId(), $quote);
    }

    public function persistOrder($order): void
    {
        $this->persist('sales_order', (int) $order->getId(), $order);
    }

    private function persist(string $table, int $entityId, $entity): void
    {
        if ($entityId <= 0) {
            return;
        }
        $bind = [];
        foreach (self::COLUMNS as $column) {
            $bind[$column] = $entity->getData($column);
        }
        if ($entity->getData('paystand_payment_id')) {
            $bind['paystand_payment_id'] = $entity->getData('paystand_payment_id');
        }
        $this->resource->getConnection()->update(
            $this->resource->getTableName($table),
            $bind,
            ['entity_id = ?' => $entityId]
        );
    }
}
