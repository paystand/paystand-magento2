<?php

namespace PayStand\PayStandMagento\Plugin\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Model\Session\SuccessValidator;
use Magento\Sales\Model\Order;
use PayStand\PayStandMagento\Helper\CloudLogger;
use PayStand\PayStandMagento\Helper\QuoteAccess;
use PayStand\PayStandMagento\Model\CheckoutQuoteMemo;

/**
 * Lets the order confirmation page render when the checkout session has lost the
 * last-order values but an order for the session's own quote exists.
 *
 * Magento writes last_quote_id/last_order_id/last_real_order_id/
 * last_success_quote_id at the end of placeOrder. When session locking is off, a
 * request that read the session before that write and finishes after it restores
 * the pre-order snapshot, discarding all four. The validator then refuses and the
 * shopper is redirected to an empty cart with the order already paid and created.
 *
 * The surviving snapshot still carries the quote id, which is enough to find the
 * order and restore what was lost. The quote id comes from the requester's own
 * session, so no other shopper's order is reachable through this path.
 */
class SuccessValidatorRepair
{
    /**
     * Only an order placed within this window can revive a confirmation page, so
     * a stale session can never surface an old order as a fresh confirmation.
     */
    const MAX_ORDER_AGE_SECONDS = 900;

    /** @var CheckoutSession */
    private $checkoutSession;

    /** @var QuoteAccess */
    private $quoteAccess;

    /** @var CheckoutQuoteMemo */
    private $quoteMemo;

    public function __construct(
        CheckoutSession $checkoutSession,
        QuoteAccess $quoteAccess,
        CheckoutQuoteMemo $quoteMemo
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->quoteAccess = $quoteAccess;
        $this->quoteMemo = $quoteMemo;
    }

    /**
     * @param SuccessValidator $subject
     * @param bool $result
     * @return bool
     */
    public function afterIsValid(SuccessValidator $subject, $result)
    {
        if ($result) {
            return $result;
        }

        try {
            return $this->restoreLastOrder() ? true : $result;
        } catch (\Throwable $e) {
            // A failed repair must leave Magento's own decision untouched.
            return $result;
        }
    }

    /**
     * @return bool true when the session was repaired and the page may render
     */
    private function restoreLastOrder(): bool
    {
        // Prefer the memo: by the time the validator runs the session either has
        // no quote id, or for a logged-in shopper already holds the replacement
        // cart Magento created when the ordered quote stopped being active.
        $quoteId = $this->quoteMemo->get() ?: (int)$this->checkoutSession->getQuoteId();
        $order = $quoteId ? $this->quoteAccess->findOrderByQuoteId($quoteId) : null;

        if (!$order || !$order->getId() || !$this->isRecent($order)) {
            $this->reportBounce($quoteId, $order);
            return false;
        }

        $this->checkoutSession->setLastQuoteId($quoteId)
            ->setLastSuccessQuoteId($quoteId)
            ->setLastOrderId($order->getId())
            ->setLastRealOrderId($order->getIncrementId())
            ->setLastOrderStatus($order->getStatus());

        // Reported separately: a failed ship must not undo a completed restore.
        try {
            CloudLogger::ship(CloudLogger::EVENT_SUCCESS_PAGE_REPAIRED, [
                'quote_id'      => (string)$quoteId,
                'error_message' => 'restored last-order session values from order '
                    . $order->getIncrementId(),
            ]);
        } catch (\Throwable $e) {
            // Telemetry must never disturb the checkout flow.
        }

        return true;
    }

    /**
     * @param Order $order
     * @return bool
     */
    private function isRecent($order): bool
    {
        $age = $this->orderAgeSeconds($order);

        return $age !== null && $age <= self::MAX_ORDER_AGE_SECONDS;
    }

    /**
     * @param Order $order
     * @return int|null seconds since the order was placed, null without created_at
     */
    private function orderAgeSeconds($order): ?int
    {
        $createdAt = $order->getCreatedAt();
        if (!$createdAt) {
            return null;
        }

        // Order timestamps are stored in UTC; read them as such rather than
        // inheriting whatever timezone the process happens to run in.
        $placed = new \DateTime($createdAt, new \DateTimeZone('UTC'));
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        return $now->getTimestamp() - $placed->getTimestamp();
    }

    /**
     * @param int $quoteId
     * @param Order|null $order
     * @return void
     */
    private function reportBounce(int $quoteId, $order): void
    {
        try {
            $lastSuccessQuoteId = $this->checkoutSession->getLastSuccessQuoteId();
            $lastQuoteId = $this->checkoutSession->getLastQuoteId();

            CloudLogger::ship(CloudLogger::EVENT_SUCCESS_PAGE_BOUNCED, [
                'quote_id'      => (string)($quoteId ?: ($lastSuccessQuoteId ?: $lastQuoteId ?: '')),
                'error_message' => $this->bounceMessage($quoteId, $order),
            ]);
        } catch (\Throwable $e) {
            // Telemetry must never disturb the checkout flow.
        }
    }

    /**
     * Names the values the validator found missing, so a bounce with no
     * recoverable order is still distinguishable from an unplaced order.
     *
     * kind sorts the bounces:
     * - revisit: the session still names its last order. The confirmation page
     *   clears last_success_quote_id and keeps last_order_id, so a reload or a
     *   second request after the confirmation lands here. last_order_age is the
     *   age of that order.
     * - stale_order: the session's quote has an order outside the repair window.
     * - no_order: the session has a quote with no order.
     * - no_session: the session has no quote and no last order.
     *
     * @param int $quoteId
     * @param Order|null $order
     * @return string
     */
    private function bounceMessage(int $quoteId, $order): string
    {
        $lastSuccessQuoteId = $this->checkoutSession->getLastSuccessQuoteId();
        $lastQuoteId = (int)$this->checkoutSession->getLastQuoteId();
        $lastOrderId = $this->checkoutSession->getLastOrderId();

        $missing = [];
        if (!$lastSuccessQuoteId) {
            $missing[] = 'last_success_quote_id';
        }
        if (!$lastQuoteId) {
            $missing[] = 'last_quote_id';
        }
        if (!$lastOrderId) {
            $missing[] = 'last_order_id';
        }

        $hasOrder = $order && $order->getId();
        $reason = 'no order for session quote';
        if ($hasOrder) {
            $reason = 'order ' . $order->getIncrementId() . ($order->getCreatedAt()
                ? ' too old to restore'
                : ' has no created_at');
        }

        $lastOrderAge = '';
        if ($lastOrderId) {
            $kind = 'revisit';
            $lastOrderAge = ' last_order_age=' . $this->lastOrderAge($lastQuoteId, $quoteId, $order);
        } elseif ($hasOrder) {
            $kind = 'stale_order';
        } elseif ($quoteId) {
            $kind = 'no_order';
        } else {
            $kind = 'no_session';
        }

        return 'success page redirected to cart; kind=' . $kind . '; ' . $reason
            . '; missing[' . implode(',', $missing) . ']'
            . ' last_real_order_id=' . ($this->checkoutSession->getLastRealOrderId() ?: '(none)')
            . $lastOrderAge;
    }

    /**
     * @param int $lastQuoteId
     * @param int $quoteId
     * @param Order|null $order the order already looked up for $quoteId
     * @return string
     */
    private function lastOrderAge(int $lastQuoteId, int $quoteId, $order): string
    {
        try {
            $lastOrder = $lastQuoteId === $quoteId
                ? $order
                : ($lastQuoteId ? $this->quoteAccess->findOrderByQuoteId($lastQuoteId) : null);
            $age = $lastOrder && $lastOrder->getId() ? $this->orderAgeSeconds($lastOrder) : null;
        } catch (\Throwable $e) {
            $age = null;
        }

        return $age === null ? '(unknown)' : $age . 's';
    }
}
