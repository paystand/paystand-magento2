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
