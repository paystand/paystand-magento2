<?php

namespace PayStand\PayStandMagento\Model\Config\Source;

use PayStand\PayStandMagento\Plugin\CapturedQuoteSubmit;

class CapturedCartGuard implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * @return array
     */
    public function toOptionArray()
    {
        // Literal __() calls so i18n:collect-phrases still finds these strings.
        return [
            [
                'value' => CapturedQuoteSubmit::MODE_OFF,
                'label' => __('Off — always place the order'),
            ],
            [
                'value' => CapturedQuoteSubmit::MODE_LOG_ONLY,
                'label' => __('Log only — place the order and log a cart mismatch'),
            ],
            [
                'value' => CapturedQuoteSubmit::MODE_REFUSE,
                'label' => __('Refuse — do not place the order when the cart changed after payment'),
            ],
        ];
    }
}
