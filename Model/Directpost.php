<?php

namespace PayStand\PayStandMagento\Model;

class Directpost extends \Magento\Payment\Model\Method\AbstractMethod
{
    public const METHOD_CODE = 'paystandmagento';

    /**
     * Payment code
     *
     * @var string
     */
    protected string $_code = 'paystandmagento';

    /**
     * Availability option
     *
     * @var bool
     */
    protected bool $_isOffline = false;

    /**
     * Check whether there are CC types set in configuration
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null): bool
    {
        return parent::isAvailable($quote)
            && $this->getConfigData('publishable_key', $quote?->getStoreId());
    }
}
