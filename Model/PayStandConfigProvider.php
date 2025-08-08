<?php

namespace PayStand\PayStandMagento\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use \Magento\Framework\App\Config\ScopeConfigInterface as ScopeConfig;

class PayStandConfigProvider implements ConfigProviderInterface
{
  /**
   * @var \Magento\Framework\App\Config\ScopeConfigInterface
   */
    protected $scopeConfig;

  /**
   * publishable key config path
   */
    const PUBLISHABLE_KEY = 'payment/paystandmagento/publishable_key';

  /**
   * checkout preset key config path
   */
    const CHECKOUT_PRESET_KEY = 'payment/paystandmagento/checkout_preset_key';

  /**
   * client secret config path
   */
    const CUSTOMER_ID = 'payment/paystandmagento/customer_id';

  /**
   * client id config path
   */
    const CLIENT_ID = 'payment/paystandmagento/client_id';

  /**
   * client secret config path
   */
    const CLIENT_SECRET = 'payment/paystandmagento/client_secret';

  /**
   * update orders on
   */
  const UPDATE_ORDER_ON = 'payment/paystandmagento/update_order_on';

  /**
   * use sandbox config path
   */
    const USE_SANDBOX = 'payment/paystandmagento/use_sandbox';

  /**
   * @param ScopeConfig $scopeConfig
   */
    public function __construct(
        ScopeConfig $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
    }
  /**
   * {@inheritdoc}
   */
    public function getConfig()
    {
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;

        $config = [
          'payment' => [
            'paystandmagento' => [
              'publishable_key' => $this->scopeConfig->getValue(self::PUBLISHABLE_KEY, $storeScope),
              'presetCustom' => $this->scopeConfig->getValue(self::CHECKOUT_PRESET_KEY, $storeScope),
              'customer_id' => $this->scopeConfig->getValue(self::CUSTOMER_ID, $storeScope),
              // client_id y client_secret are not used in the new version of the module
              // 'client_id' => $this->scopeConfig->getValue(self::CLIENT_ID, $storeScope),
              // 'client_secret' => $this->scopeConfig->getValue(self::CLIENT_SECRET, $storeScope),
              'update_order_on' => $this->scopeConfig->getValue(self::UPDATE_ORDER_ON, $storeScope),
              'use_sandbox' => $this->scopeConfig->getValue(self::USE_SANDBOX, $storeScope),
            ]
          ]
        ];
        return $config;
    }
}
