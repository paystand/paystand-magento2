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
        // The session's own id is gone by now if the quote went inactive during
        // this request, so fall back to what was captured before that happened.
        $quoteId = (int)$this->checkoutSession->getQuoteId() ?: $this->quoteMemo->get();
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

        CloudLogger::ship(CloudLogger::EVENT_SUCCESS_PAGE_REPAIRED, [
            'quote_id'      => (string)$quoteId,
            'error_message' => 'restored last-order session values from order '
                . $order->getIncrementId(),
        ]);

        return true;
    }

    /**
     * @param Order $order
     * @return bool
     */
    private function isRecent($order): bool
    {
        $createdAt = $order->getCreatedAt();
        if (!$createdAt) {
            return false;
        }

        // Order timestamps are stored in UTC; read them as such rather than
        // inheriting whatever timezone the process happens to run in.
        $placed = new \DateTime($createdAt, new \DateTimeZone('UTC'));
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        return ($now->getTimestamp() - $placed->getTimestamp()) <= self::MAX_ORDER_AGE_SECONDS;
    }

    /**
     * Names the values the validator found missing, so a bounce with no
     * recoverable order is still distinguishable from an unplaced order.
     *
     * @param int $quoteId
     * @param Order|null $order
     * @return void
     */
    private function reportBounce(int $quoteId, $order): void
    {
        try {
            $lastSuccessQuoteId = $this->checkoutSession->getLastSuccessQuoteId();
            $lastQuoteId = $this->checkoutSession->getLastQuoteId();
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

            $reason = 'no order for session quote';
            if ($order && $order->getId()) {
                $reason = 'order ' . $order->getIncrementId() . ($order->getCreatedAt()
                    ? ' too old to restore'
                    : ' has no created_at');
            }

            CloudLogger::ship(CloudLogger::EVENT_SUCCESS_PAGE_BOUNCED, [
                'quote_id'      => (string)($quoteId ?: ($lastSuccessQuoteId ?: $lastQuoteId ?: '')),
                'error_message' => 'success page redirected to cart; ' . $reason
                    . '; missing[' . implode(',', $missing) . ']'
                    . ' last_real_order_id=' . ($this->checkoutSession->getLastRealOrderId() ?: '(none)'),
            ]);
        } catch (\Throwable $e) {
            // Telemetry must never disturb the checkout flow.
        }
    }
}
