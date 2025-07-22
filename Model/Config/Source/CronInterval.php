<?php

namespace PayStand\PayStandMagento\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class CronInterval implements ArrayInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => '5', 'label' => __('5 minutes')],
            ['value' => '15', 'label' => __('15 minutes')],
            ['value' => '30', 'label' => __('30 minutes')],
            ['value' => '60', 'label' => __('1 hour')]
        ];
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        return [
            '5' => __('5 minutes'),
            '15' => __('15 minutes'),
            '30' => __('30 minutes'),
            '60' => __('1 hour')
        ];
    }
} 