<?php

namespace PayStand\PayStandMagento\Model\Config\Source;

class CheckoutMode implements \Magento\Framework\Option\ArrayInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'modal', 'label' => __('Modal')],
            ['value' => 'embed', 'label' => __('Embed')]
        ];
    }
} 