<?php

namespace PayStand\PayStandMagento\Controller\Webhook;

use \Magento\Framework\App\Config\ScopeConfigInterface as ScopeConfig;
use \Magento\Quote\Model\QuoteFactory as QuoteFactory;
use \Magento\Quote\Model\QuoteIdMaskFactory as QuoteIdMaskFactory;
use \stdClass;

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

  /** @var \Magento\Quote\Model\QuoteFactory  */
    protected $_quoteFactory;

  /** @var \Magento\Quote\Model\QuoteIdMaskFactory  */
    protected $_quoteIdMaskFactory;

  /** @var \Magento\Framework\App\Request\Http */
    protected $_request;

  /** @var \Magento\Framework\Controller\Result\JsonFactory */
    protected $_jsonResultFactory;

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
        \Magento\Framework\App\Request\Http $request,
        \Magento\Framework\Controller\Result\JsonFactory $jsonResultFactory,
        QuoteFactory $quoteFactory,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        ScopeConfig $scopeConfig
    ) {
        $this->_logger = $logger;
        $this->_request = $request;
        $this->_jsonResultFactory = $jsonResultFactory;
        $this->_quoteFactory = $quoteFactory;
        $this->_quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->scopeConfig = $scopeConfig;
        parent::__construct($context);
    }

  /**
   * Receives webhook events from Roadrunner
   */
    public function execute()
    {
        $result = $this->_jsonResultFactory->create();
        $this->_logger->addDebug('paystandmagento/webhook/paystand endpoint was hit');

        $body = @file_get_contents('php://input');
        $json = json_decode($body);
        $this->_logger->addDebug(">>>>> body=".print_r($body, true));

        if (isset($json->resource->meta->source) && ($json->resource->meta->source == "magento 2")) {
            $quoteId = $json->resource->meta->quote;
            $this->_logger->addDebug('magento 2 webhook identified with quote id = '.$quoteId);

            $quoteIdMask = $this->_quoteIdMaskFactory->create()->load($quoteId, 'masked_id');
            $id = $quoteIdMask->getQuoteId();

            $quote = $this->_quoteFactory->create()->load($id);
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $order = $objectManager->create('Magento\Sales\Model\Order')->load($quote->getReservedOrderId());

            if (!empty($order->getIncrementId())) {
                $this->_logger->addDebug('current order increment id = '.$order->getIncrementId());

                $state = $order->getState();
                $this->_logger->addDebug('current order state = '.$state);

                $status = $order->getStatus();
                $this->_logger->addDebug('current order status = '.$status);

                $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;

                if ($this->scopeConfig->getValue(self::USE_SANDBOX, $storeScope)) {
                    $base_url = 'https://api.paystand.co/v3';
                } else {
                    $base_url = 'https://api.paystand.com/v3';
                }

                $oauthUrl = $base_url . '/oauth/token';
                $oauth_credentials = [
                                    'grant_type' => "client_credentials",
                                    'scope' => "auth",
                                    'client_id' => $this->scopeConfig->getValue(self::CLIENT_ID, $storeScope),
                                    'client_secret' => $this->scopeConfig->getValue(self::CLIENT_SECRET, $storeScope)
                                    ];
                $authResponse = $this->runCurl($this->buildCurl("POST", $oauthUrl, json_encode($oauth_credentials)));
                $auth_header = ["Authorization: Bearer ".$authResponse->access_token,
                                "x-customer-id: ".$this->scopeConfig->getValue(self::CUSTOMER_ID, $storeScope)];

                $this->http_response_code = "0"; //Restart http response
                $url = $base_url . "/events/" . $json->id . "/verify";

                // Clean up json before sending for verification
                $attributeWhitelist = ["id","object","resource","diff","urls","created"
                                                        ,"lastUpdated","status"];
                $json = $this->cleanObject($json,$attributeWhitelist);

                $curl = $this->buildCurl("POST", $url, json_encode($json), $auth_header);
                $response = $this->runCurl($curl);

                $this->_logger->addDebug("http_response_code is ".$this->http_response_code);

                if (false !== $response && $this->http_response_code == 200) {
                    if ($json->resource->object = "payment") {
                        switch ($json->resource->status) {
                            case 'posted':
                                $state = 'processing';
                                $status = 'processing';
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

                    $order->setState($state);
                    $order->setStatus($status);
                    $order->save();
                    $this->_logger->addDebug('new order state = '.$state);
                    $this->_logger->addDebug('new order status = '.$status);
                    $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
                    $result->setData(['success_message' => __('Event verified, order status changed'),
                                      'order' => ['newState' => __($state),
                                                 'newStatus' => __($status)]]);
                    return $result;
                } else {
                    $this->_logger->addDebug('event verify failed');
                    $result->setHttpResponseCode(\Magento\Framework\Webapi\Exception::HTTP_BAD_REQUEST);
                    $result->setData(['error_message' => __('Event verify failed')]);
                    return $result;
                }
            } else {
                $this->_logger->addDebug('Could not retrieve order from quoteId');
                $result->setHttpResponseCode(\Magento\Framework\Webapi\Exception::HTTP_NOT_FOUND);
                $result->setData(['error_message' => __('Could not retrieve order from quoteId')]);
                return $result;
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

    private function cleanObject($obj, $whitelist)
    {
        $ret = new stdClass;
        foreach ($whitelist as $prop) {
            $ret->$prop = $obj->$prop;
        }
        return $ret;
    }
}
