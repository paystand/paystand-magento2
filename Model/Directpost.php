<?php

namespace PayStand\PayStandMagento\Model;

class Directpost extends \Magento\Payment\Model\Method\AbstractMethod
{
    const METHOD_CODE = 'paystandmagento';

    /**
     * Payment code
     *
     * @var string
     */
    protected $_code = 'paystandmagento'; // Not worth it creating a constructor to just assign $_code to METHOD_CODE

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_isOffline = false;

    /**
     * Check whether there are CC types set in configuration
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        return parent::isAvailable($quote)
        && $this->getConfigData('publishable_key', $quote ? $quote->getStoreId() : null);
    }

}
