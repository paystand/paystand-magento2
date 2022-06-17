<?php

namespace PayStand\PayStandMagento\Model\Config\Source;

class PaymentStatus implements \Magento\Framework\Option\ArrayInterface
{
  /**
     * @return array
     */
    public function toOptionArray()
    {

        return [
            ['value' => 'paid', 'label' => __('Payment Paid')],
            ['value' => 'posted', 'label' => __('Payment Posted')]
        ];
    }
}
