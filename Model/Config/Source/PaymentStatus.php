<?php

namespace PayStand\PayStandMagento\Model\Config\Source;

class PaymentStatus implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Statuses that mean Paystand actually took the money. Shared by every caller
     * that has to tell a completed capture from a payment still in flight.
     */
    const CAPTURED_STATUSES = ['paid', 'posted'];

    /**
     * Statuses that Magento createOrderFromQuote will convert to an order.
     * ACH often arrives as processing before paid. SavePaymentData must keep
     * using CAPTURED_STATUSES so an in-flight ACH does not freeze collect.
     */
    const PLACE_ORDER_STATUSES = ['paid', 'posted', 'processing'];

    /**
     * paid and posted both mean the money was taken. processing is weaker.
     * A later processing delivery must not replace a status that already arms
     * the captured-cart guard.
     *
     * @param string $stored
     * @param string $incoming
     * @return string
     */
    public static function keepStrongerStatus($stored, $incoming)
    {
        $stored = strtolower(trim((string)$stored));
        $incoming = strtolower(trim((string)$incoming));
        $rank = [
            'processing' => 1,
            'paid' => 2,
            'posted' => 2,
        ];
        $storedRank = isset($rank[$stored]) ? $rank[$stored] : 0;
        $incomingRank = isset($rank[$incoming]) ? $rank[$incoming] : 0;
        if ($stored !== '' && $storedRank > $incomingRank) {
            return $stored;
        }

        return $incoming !== '' ? $incoming : $stored;
    }

  /**
     * @return array
     */
    public function toOptionArray()
    {
        // Literal __() calls so i18n:collect-phrases still finds these strings.
        $labels = [
            'paid'   => __('Payment Paid'),
            'posted' => __('Payment Posted'),
        ];

        $options = [];
        foreach (self::CAPTURED_STATUSES as $status) {
            $options[] = ['value' => $status, 'label' => $labels[$status]];
        }

        return $options;
    }
}
