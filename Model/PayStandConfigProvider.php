<?php

namespace PayStand\PayStandMagento\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use \Magento\Framework\App\Config\ScopeConfigInterface as ScopeConfig;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\HTTP\Client\Curl;

class PayStandConfigProvider implements ConfigProviderInterface
{
  /**
   * @var \Magento\Framework\App\Config\ScopeConfigInterface
   */
    protected $scopeConfig;

  /**
   * @var \Magento\Customer\Model\Session
   */
    protected $customerSession;

  /**
   * @var \Magento\Framework\HTTP\Client\Curl
   */
    protected $curl;

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
   * Base URLs for API
   */
    const BASE_URL = 'https://api.paystand.biz/v3';
    const SANDBOX_BASE_URL = 'https://api.paystand.biz/v3';

  /**
   * @param ScopeConfig $scopeConfig
   * @param CustomerSession $customerSession
   * @param Curl $curl
   */
    public function __construct(
        ScopeConfig $scopeConfig,
        CustomerSession $customerSession,
        Curl $curl
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->customerSession = $customerSession;
        $this->curl = $curl;
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

        // Add access token if customer is logged in
        if ($this->customerSession->isLoggedIn()) {
            $accessToken = $this->getPaystandAccessToken();
            if ($accessToken) {
                $config['payment']['paystandmagento']['access_token'] = $accessToken;
            }
        }

        return $config;
    }

    /**
     * Get PayStand access token
     *
     * @return string|null
     */
    private function getPaystandAccessToken()
    {
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        $oauthUrl = $this->getBaseUrl() . '/oauth/token';

        $oauth_credentials = [
            'grant_type' => "client_credentials",
            'scope' => "auth",
            'client_id' => $this->scopeConfig->getValue(self::CLIENT_ID, $storeScope),
            'client_secret' => $this->scopeConfig->getValue(self::CLIENT_SECRET, $storeScope)
        ];

        try {
            $this->curl->setHeaders([
                "Content-Type" => "application/json",
                "Accept" => "application/json"
            ]);

            $this->curl->setOption(CURLOPT_USERAGENT, "Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:32.0) Gecko/20100101 Firefox/32.0");
            $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curl->setOption(CURLOPT_TIMEOUT, 60);
            $this->curl->post($oauthUrl, json_encode($oauth_credentials));
            $response = json_decode($this->curl->getBody());

            if (isset($response->access_token)) {
                return $response->access_token;
            }
        } catch (\Exception $e) {
            // Log error but don't break checkout process
            return null;
        }

        return null;
    }

    /**
     * Get base URL for PayStand API
     *
     * @return string
     */
    private function getBaseUrl()
    {
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        if ($this->scopeConfig->getValue(self::USE_SANDBOX, $storeScope)) {
            return self::SANDBOX_BASE_URL;
        } else {
            return self::BASE_URL;
        }
    }
}
