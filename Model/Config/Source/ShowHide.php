<?php

namespace PayStand\PayStandMagento\Model\Config\Source;

class ShowHide implements \Magento\Framework\Option\ArrayInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'show', 'label' => __('Show')],
            ['value' => 'hide', 'label' => __('Hide')]
        ];
    }
} 