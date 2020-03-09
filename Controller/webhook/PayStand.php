<?php

namespace PayStand\PayStandMagento\Controller\Webhook;

use \Magento\Framework\App\Config\ScopeConfigInterface as ScopeConfig;

/**
 * Webhook Receiver Controller for Paystand
 */
class Paystand extends \Magento\Framework\App\Action\Action
{

  /**
   * publishable key config path
   */
    const PUBLISHABLE_KEY = 'payment/paystandmagento/publishable_key';

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
   * use sandbox config path
   */
    const USE_SANDBOX = 'payment/paystandmagento/use_sandbox';

  /** @var \Psr\Log\LoggerInterface  */
    protected $_logger;

  /** @var \Magento\Sales\Model\Order  */
    protected $_order;

  /** @var \Magento\Framework\App\Request\Http */
    protected $_request;

  /**
   * @var \Magento\Framework\App\Config\ScopeConfigInterface
   */
    protected $scopeConfig;

    protected $error;
    protected $errno;
    protected $raw_response;
    protected $http_response_code;

  /**
   * @param \Magento\Framework\App\Action\Context $context,
   * @param \Psr\Log\LoggerInterface $logger
   */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Sales\Model\Order $order,
        \Magento\Framework\App\Request\Http $request,
        ScopeConfig $scopeConfig
    ) {
        $this->_logger = $logger;
        $this->_order = $order;
        $this->_request = $request;
        $this->scopeConfig = $scopeConfig;
        parent::__construct($context);
    }

  /**
   * Receives webhook events from Roadrunner
   */
    public function execute()
    {

        $this->_logger->addDebug('paystandmagento/webhook/paystand endpoint was hit');

        $body = @file_get_contents('php://input');
        $json = json_decode($body);
        $this->_logger->addDebug(">>>>> body=".print_r($body, true));

        if (isset($json->resource->meta->source) && ($json->resource->meta->source == "magento 2")) {
            $quoteId = $json->resource->meta->quote;

            $this->_logger->addDebug('magento 2 webhook identified with quote id = '.$quoteId);
            $this->_order->loadByAttribute('quote_id', $quoteId);

            if (!empty($this->_order->getIncrementId())) {
                $this->_logger->addDebug('current order increment id = '.$this->_order->getIncrementId());

                $state = $this->_order->getState();
                $this->_logger->addDebug('current order state = '.$state);

                $status = $this->_order->getStatus();
                $this->_logger->addDebug('current order status = '.$status);

                $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;

                if ($this->scopeConfig->getValue(self::USE_SANDBOX, $storeScope)) {
                    $base_url = 'https://api.paystand.co/v3';
                } else {
                    $base_url = 'https://api.paystand.com/v3';
                }

                $url = $base_url . "/events/" . $json->id . "/verify";
                $auth_header = ["x-publishable-key: ".$this->scopeConfig->getValue(self::PUBLISHABLE_KEY, $storeScope)];

                $json->client_id = $this->scopeConfig->getValue(self::CLIENT_ID, $storeScope);
                $json->client_secret = $this->scopeConfig->getValue(self::CLIENT_SECRET, $storeScope);

                $curl = $this->buildCurl("POST", $url, json_encode($json), $auth_header);
                $response = $this->runCurl($curl);

                $this->_logger->addDebug("http_response_code is ".$this->http_response_code);

                if (false !== $response && $this->http_response_code == 200) {
                    if ($json->resource->object = "payment") {
                        switch ($json->resource->status) {
                            case 'posted':
                                $state = 'pending';
                                $status = 'pending';
                                break;
                            case 'paid':
                                $state = 'processing';
                                $status = 'processing';
                                break;
                            case 'failed':
                                $state = 'closed';
                                $status = 'closed';
                                break;
                            case 'canceled':
                                $state = 'canceled';
                                $status = 'canceled';
                                break;
                        }
                    }

                    $this->_order->setState($state);
                    $this->_order->setStatus($status);
                    $this->_order->save();
                    $this->_logger->addDebug('new order state = '.$state);
                    $this->_logger->addDebug('new order status = '.$status);
                } else {
                    $this->_logger->addDebug('event verify failed');
                }
            }
        }
    }

    private function buildCurl($verb = "POST", $url, $body = "", $extheaders = null)
    {
        $headers = [
        "Content-Type: application/json",
        "Accept: application/json"
        ];

        if (null != $extheaders) {
            $headers = array_merge($headers, $extheaders);
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_USERAGENT, "Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:32.0) Gecko/20100101 Firefox/32.0");
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $verb);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);

        return $curl;
    }

    private function runCurl($curl)
    {
        $raw_response = curl_exec($curl);
        $response = json_decode($raw_response);
        $this->error = curl_error($curl);
        $this->errno = curl_errno($curl);
        $this->raw_response = $raw_response;
        $this->http_response_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        return $response;
    }
}
