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
   * client id config path
   */
    const CLIENT_ID = 'payment/paystandmagento/client_id';

  /**
   * client secret config path
   */
    const CLIENT_SECRET = 'payment/paystandmagento/client_secret';

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
          'client_id' => $this->scopeConfig->getValue(self::CLIENT_ID, $storeScope),
          'client_secret' => $this->scopeConfig->getValue(self::CLIENT_SECRET, $storeScope),
          'use_sandbox' => $this->scopeConfig->getValue(self::USE_SANDBOX, $storeScope)
        ]
        ]
        ];
        return $config;
    }
}
