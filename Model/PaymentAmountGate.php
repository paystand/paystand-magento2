<?php

declare(strict_types=1);

namespace PayStand\PayStandMagento\Model;

use Magento\Sales\Model\Order;
use PayStand\PayStandMagento\Helper\CloudLogger;

/**
 * Checks the Paystand charge against the order total before an invoice.
 * A mismatch holds the order. Payer fees come from Paystand's fee split.
 */
class PaymentAmountGate
{
    public const ACTION_INVOICE = 'invoice';
    public const ACTION_HOLD = 'hold';

    public const REASON_MATCH = 'match';
    public const REASON_MISMATCH = 'mismatch';
    public const REASON_UNVERIFIED = 'unverified';

    public const TOLERANCE_CENTS = 1;

    /**
     * @param mixed $paystandAdjustment Browser fee or discount. Not used when the payment has a fee split.
     * @param array<string, mixed> $paymentData Paystand payment. Uses amount, not settlementAmount.
     * @return array{action:string,reason:string,charged:?float,expected:?float,currency:?string,orderTotal:?float}
     */
    public function decide(
        $orderGrandTotal,
        $orderBaseGrandTotal,
        $orderCurrency,
        $baseCurrency,
        $paystandAdjustment,
        bool $adjustmentAlreadyInTotal,
        array $paymentData
    ): array {
        $charged = $this->money($paymentData['amount'] ?? null);
        $paymentCurrency = $this->currency($paymentData['currency'] ?? null);
        $orderCurrency = $this->currency($orderCurrency);
        $baseCurrency = $this->currency($baseCurrency);

        if ($charged === null || $paymentCurrency === null) {
            return $this->result(self::ACTION_HOLD, self::REASON_UNVERIFIED, null, null, $paymentCurrency);
        }

        if ($orderCurrency !== null && $paymentCurrency === $orderCurrency) {
            $orderTotal = $this->money($orderGrandTotal);
        } elseif ($baseCurrency !== null && $paymentCurrency === $baseCurrency) {
            $orderTotal = $this->money($orderBaseGrandTotal);
        } else {
            return $this->result(self::ACTION_HOLD, self::REASON_UNVERIFIED, $charged, null, $paymentCurrency);
        }

        if ($orderTotal === null) {
            return $this->result(self::ACTION_HOLD, self::REASON_UNVERIFIED, $charged, null, $paymentCurrency);
        }

        $adjustment = $this->money($paystandAdjustment) ?? 0.0;
        $fees = $this->paystandFees($paymentData, $paymentCurrency);
        if ($fees !== null) {
            // Paystand's fee split replaces the adjustment the browser sent.
            $goods = $adjustmentAlreadyInTotal ? $orderTotal - $adjustment : $orderTotal;
            $expected = round($goods + $fees, 2);
        } else {
            $expected = $adjustmentAlreadyInTotal ? $orderTotal : round($orderTotal + $adjustment, 2);
        }

        if (self::centsApart($charged, $expected) <= self::TOLERANCE_CENTS) {
            return $this->result(self::ACTION_INVOICE, self::REASON_MATCH, $charged, $expected, $paymentCurrency, $orderTotal);
        }

        return $this->result(self::ACTION_HOLD, self::REASON_MISMATCH, $charged, $expected, $paymentCurrency, $orderTotal);
    }

    /**
     * Payer fees less discount, from Paystand's fee split. Null without a usable split.
     *
     * @param array<string, mixed> $paymentData
     */
    private function paystandFees(array $paymentData, string $paymentCurrency): ?float
    {
        $feeSplit = $paymentData['feeSplit'] ?? null;
        if (!is_array($feeSplit)) {
            return null;
        }
        $feeCurrency = $this->currency($feeSplit['currency'] ?? null);
        if ($feeCurrency !== null && $feeCurrency !== $paymentCurrency) {
            return null;
        }
        $payerTotal = $this->money($feeSplit['payerTotal'] ?? null);
        $subtotal = $this->money($feeSplit['subtotal'] ?? null);
        if ($payerTotal === null || $subtotal === null || $subtotal <= 0.0) {
            return null;
        }
        return round($payerTotal - $subtotal, 2);
    }

    /**
     * Paystand amount for the order note, or the fallback when the payload has none.
     *
     * @param array<string, mixed> $paymentData
     * @param mixed $fallback
     */
    public function displayAmount(array $paymentData, $fallback): float
    {
        $charged = $this->money($paymentData['amount'] ?? null);
        if ($charged !== null) {
            return $charged;
        }
        return $this->money($fallback) ?? 0.0;
    }

    /**
     * Hold the order and record why. An order already on hold is left as it is.
     *
     * @param mixed $order Magento order
     * @param array{action:string,reason:string,charged:?float,expected:?float,currency:?string,orderTotal:?float} $decision
     */
    public function hold($order, array $decision, string $paymentId): void
    {
        if ((string)$order->getState() === Order::STATE_HOLDED) {
            return;
        }

        try {
            if ($order->canHold()) {
                $order->hold();
            } else {
                $order->setState(Order::STATE_HOLDED);
                $order->setStatus(Order::STATE_HOLDED);
            }
        } catch (\Throwable $e) {
            $order->setState(Order::STATE_HOLDED);
            $order->setStatus(Order::STATE_HOLDED);
        }

        $comment = $this->comment($decision, $paymentId);
        $order->addCommentToStatusHistory(__($comment), false);
        $order->save();

        $event = $decision['reason'] === self::REASON_MISMATCH
            ? CloudLogger::EVENT_PAYMENT_AMOUNT_MISMATCH
            : CloudLogger::EVENT_PAYMENT_AMOUNT_UNVERIFIED;

        try {
            CloudLogger::ship($event, [
                'quote_id' => (string)($order->getQuoteId() ?? ''),
                'payment_id' => $paymentId,
                'error_message' => $comment,
            ]);
        } catch (\Throwable $e) {
            // CloudLogger failure must not invoice the order or skip the hold.
        }
    }

    /** @param array{reason:string,charged:?float,expected:?float,currency:?string,orderTotal?:?float} $decision */
    public function comment(array $decision, string $paymentId): string
    {
        if ($decision['reason'] === self::REASON_MISMATCH) {
            $currency = $decision['currency'] ?? '';
            $orderTotal = $decision['orderTotal'] ?? $decision['expected'];
            $message = sprintf(
                'Paystand charged %s %s. The order total is %s %s.',
                $this->formatMoney($decision['charged']),
                $currency,
                $this->formatMoney($orderTotal),
                $currency
            );
            // Fees or a discount make the expected payment differ from the order total.
            if ($orderTotal !== null && $decision['expected'] !== null
                && self::centsApart($orderTotal, $decision['expected']) !== 0
            ) {
                $message .= sprintf(
                    ' With Paystand fees and discounts, the expected payment is %s %s.',
                    $this->formatMoney($decision['expected']),
                    $currency
                );
            }
            return $message . sprintf(' Payment %s. The order is on hold and was not invoiced.', $paymentId);
        }

        return sprintf(
            'Paystand payment %s could not be checked against the order total. The order is on hold and was not invoiced.',
            $paymentId
        );
    }

    public static function centsApart(float $left, float $right): int
    {
        return (int)abs((int)round($left * 100) - (int)round($right * 100));
    }

    /** @return array{action:string,reason:string,charged:?float,expected:?float,currency:?string,orderTotal:?float} */
    private function result(
        string $action,
        string $reason,
        ?float $charged,
        ?float $expected,
        ?string $currency,
        ?float $orderTotal = null
    ): array {
        return [
            'action' => $action,
            'reason' => $reason,
            'charged' => $charged,
            'expected' => $expected,
            'currency' => $currency,
            'orderTotal' => $orderTotal,
        ];
    }

    /** @param mixed $value */
    private function money($value): ?float
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return round((float)$value, 2);
    }

    /** @param mixed $value */
    private function currency($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtoupper(trim($value));
        return $value === '' ? null : $value;
    }

    private function formatMoney(?float $amount): string
    {
        if ($amount === null) {
            return 'unknown';
        }
        return number_format($amount, 2, '.', '');
    }
}
