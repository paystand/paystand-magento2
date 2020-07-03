<?php

namespace PayStand\PayStandMagento\Controller\Webhook;

use \Magento\Framework\App\Config\ScopeConfigInterface as ScopeConfig;
use \Magento\Quote\Model\QuoteFactory as QuoteFactory;
use \Magento\Quote\Model\QuoteIdMaskFactory as QuoteIdMaskFactory;
use \stdClass;
use Magento\Framework\App\Action\HttpPostActionInterface as HttpPostActionInterface;

/**
 * Webhook Receiver Controller for Paystand
 */
class Paystand extends \Magento\Framework\App\Action\Action implements HttpPostActionInterface
{

    // Get configuration from Paystand's payment method settings & set constants
    const PUBLISHABLE_KEY = 'payment/paystandmagento/publishable_key';
    const CUSTOMER_ID = 'payment/paystandmagento/customer_id';
    const CLIENT_ID = 'payment/paystandmagento/client_id';
    const CLIENT_SECRET = 'payment/paystandmagento/client_secret';
    const USE_SANDBOX = 'payment/paystandmagento/use_sandbox';
    const SANDBOX_BASE_URL = 'https://api.paystand.co/v3';
    const BASE_URL = 'https://api.paystand.com/v3';
    const STORE_SCOPE = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;

    /** @var \Psr\Log\LoggerInterface */
    protected $_logger;

    /** @var \Magento\Quote\Model\QuoteFactory */
    protected $_quoteFactory;

    /** @var \Magento\Quote\Model\QuoteIdMaskFactory */
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

    /**
     * @param \Magento\Framework\App\Action\Context $context ,
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Framework\Controller\Result\JsonFactory $jsonResultFactory,
        \Magento\Framework\ObjectManagerInterface $objectManager,
        QuoteFactory $quoteFactory,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        ScopeConfig $scopeConfig
    ) {
        $this->_logger = $logger;
        $this->_request = $request;
        $this->_jsonResultFactory = $jsonResultFactory;
        $this->_objectManager = $objectManager;
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
        // Start and Initialize http response
        $result = $this->_jsonResultFactory->create();
        $this->_logger->debug('>>>>> PAYSTAND-START: paystandmagento/webhook/paystand endpoint was hit');

        // Get body content from request
        $body = (!empty($this->_request->getContent()))
            ? $this->_request->getContent() : $this->getRequest()->getContent();
        if ($body == null) {
            $this->_logger->error('>>>>> PAYSTAND-ERROR: error retrieving the body from webhook');
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Exception::HTTP_INTERNAL_ERROR);
            $result->setData(['error_message' => __('error retrieving the body from webhook')]);
            return $result;
        }
        $json = json_decode($body);
        $this->_logger->debug(">>>>> PAYSTAND-REQUEST-RECEIVED: " .json_encode($json));

        // Verify the received event is a Paystand-Magento request
        if (!isset($json->resource->meta->source) || ($json->resource->meta->source != "magento 2")) {
            $this->_logger->debug('>>>>> PAYSTAND-FINISH: not a Paystand-Magento request');
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
            $result->setData(
                ['success_message' => __('Event finished: Not a Paystand-Magento request')]
            );
            return $result;
        }

        // Get quote id from request
        $quoteId = $json->resource->meta->quote;
        $this->_logger->debug('>>>>> PAYSTAND-QUOTE: magento 2 webhook identified with quote id = ' . $quoteId);
        $quoteIdMask = $this->_quoteIdMaskFactory->create()->load($quoteId, 'masked_id');
        // If the quoteId is not masked, it comes from a logged in user and should be used as is.
        $id = (empty($quoteIdMask->getQuoteId())) ? $json->resource->meta->quote : $quoteIdMask->getQuoteId();

        // Get Order Id from quote
        $quote = $this->_quoteFactory->create()->load($id);
        $order = $this->_objectManager->create(
            \Magento\Sales\Model\Order::class
        )->loadByIncrementId($quote->getReservedOrderId());

        // Verify we got an existing Magento order from received quote id
        if (empty($order->getIncrementId())) {
            $this->_logger->debug('>>>>> PAYSTAND-ERROR: Could not retrieve order from quoteId');
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Exception::HTTP_NOT_FOUND);
            $result->setData(['error_message' => __('Could not retrieve order from quoteId')]);
            return $result;
        }

        // Get current order statuses
        $state = $order->getState();
        $status = $order->getStatus();
        $this->_logger->debug(
            '>>>>> PAYSTAND-ORDER: current order id: "' . $order->getIncrementId()
            . '", current order state: "' . $state . '", current order status: "' . $status . '"'
        );

        // Get an access_token from Paystand using CLIENT_ID & CLIENT_SECRET
        $access_token = $this->getPaystandAccessToken();
        if ($access_token == null) {
            $this->_logger->error(
                '>>>>> PAYSTAND-ERROR: access_token could not be retrieved, check your Paystand configuration'
            );
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Exception::HTTP_BAD_REQUEST);
            $result->setData(
                [
                    'error_message' => __('access_token could not be retrieved from Paystand')
                ]
            );
            return $result;
        }

        // Verify received Event is valid with Paystand
        if (!$this->verifyPaystandEvent($access_token, $json)) {
            $this->_logger->error('>>>>> PAYSTAND-ERROR: Event verification failed');
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Exception::HTTP_BAD_REQUEST);
            $result->setData(['error_message' => __('Event verification failed')]);
            return $result;
        }

        // Verify the event is payment related
        if (!$json->resource->object = "payment") {
            $this->_logger->debug('>>>>> PAYSTAND-EVENT-VERIFICATION-FINISH');
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
            $result->setData(
                ['success_message' => __('Event verified, not a payment, no further action')]
            );
            return $result;
        }

        // Get new order state & status depending on Paystand's payment status
        if ($json->resource->status == 'created' || $json->resource->status == 'processing') {
            $this->_logger->debug('>>>>> PAYSTAND-FINISH: payment created or processing, no need to update order');
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
            $result->setData(
                ['success_message' => __('Event verified, payment created or processing, no further action')]
            );
            return $result;
        }
        $newStatus = $this->newOrderStatus($json->resource->status);
        $state = $newStatus;
        $status = $newStatus;

        // Assign new status to Magento 2 Order
        $order->setState($state);
        $order->setStatus($status);
        $order->save();

        // Finish and send back success response
        $this->_logger->debug(
            '>>>>> PAYSTAND-FINISH: Paystand payment status: "' . $json->resource->status
            . '", new order state: "' . $state
            . '", new order status: "' . $status . '"'
        );
        $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
        $result->setData(
            [
                'success_message' => __('Event verified, order status changed'),
                'order' => [
                    'newState' => __($state),
                    'newStatus' => __($status)
                ]
            ]
        );
        return $result;
    }

    private function buildCurl($curl, $verb, $body = "", $extheaders = null)
    {
        // Initialize default headers
        $headers = [
            "Content-Type" => "application/json",
            "Accept" => "application/json"
        ];
        // Add external headers for this particular request if any
        if (null != $extheaders) {
            $headers = array_merge($headers, $extheaders);
        }
        $curl->setHeaders($headers);

        // Initialize default options and set the body for this request
        $curlOptions = [
            CURLOPT_USERAGENT => "Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:32.0) Gecko/20100101 Firefox/32.0",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $verb,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POSTFIELDS => $body
        ];
        $curl->setOptions($curlOptions);
        return $curl;
    }

    private function runCurl($curl, $url)
    {
        $curl->post($url, null);
        $raw_response = $curl->getBody();
        $response = json_decode($raw_response);
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

    private function newOrderStatus($status)
    {
        $newStatus = '';
        switch ($status) {
            case 'posted':
                $newStatus = 'processing';
                break;
            case 'paid':
                $newStatus = 'processing';
                break;
            case 'failed':
                $newStatus = 'closed';
                break;
            case 'canceled':
                $newStatus = 'canceled';
                break;
        }
        return $newStatus;
    }

    private function getPaystandAccessToken()
    {
        $oauthUrl = $this->getBaseUrl() . '/oauth/token';
        $oauth_credentials = [
            'grant_type' => "client_credentials",
            'scope' => "auth",
            'client_id' => $this->scopeConfig->getValue(self::CLIENT_ID, self::STORE_SCOPE),
            'client_secret' => $this->scopeConfig->getValue(self::CLIENT_SECRET, self::STORE_SCOPE)
        ];
        $authCurl = $this->_objectManager->create(\Magento\Framework\HTTP\Client\Curl::class);
        $authCurl = $this->buildCurl($authCurl, "POST", json_encode($oauth_credentials));
        $this->_logger->debug(
            '>>>>> PAYSTAND-FETCH-ACCESS-TOKEN-START'
        );
        $authResponse = $this->runCurl($authCurl, $oauthUrl);
        if ($authResponse == null) {
            return null;
        }
        $this->_logger->debug('>>>>> PAYSTAND-FETCH-ACCESS-TOKEN-SUCCESS');
        return $authResponse->access_token;
    }

    private function verifyPaystandEvent($access_token, $event)
    {
        $auth_header =
            [
                "Authorization" => "Bearer " . $access_token,
                "x-customer-id" => $this->scopeConfig->getValue(self::CUSTOMER_ID, self::STORE_SCOPE)
            ];
        $url = $this->getBaseUrl() . "/events/" . $event->id . "/verify";

        // Clean up json before sending for verification
        $attributeWhitelist = [
            "id", "object", "resource", "diff", "urls", "created", "lastUpdated", "status"
        ];
        $event = $this->cleanObject($event, $attributeWhitelist);

        $this->_logger->debug('>>>>> PAYSTAND-EVENT-VERIFICATION-START');
        $verificationCurl = $this->_objectManager->create(\Magento\Framework\HTTP\Client\Curl::class);
        $verificationCurl = $this->buildCurl($verificationCurl, "POST", json_encode($event), $auth_header);
        $response = $this->runCurl($verificationCurl, $url);
        if (null == $response || property_exists($response, "error")) {
            return false;
        } else {
            $this->_logger->debug('>>>>> PAYSTAND-EVENT-VERIFICATION-SUCCESS');
            return true;
        }
    }

    private function getBaseUrl()
    {
        if ($this->scopeConfig->getValue(self::USE_SANDBOX, self::STORE_SCOPE)) {
            $base_url = self::SANDBOX_BASE_URL;
        } else {
            $base_url = self::BASE_URL;
        }
        return $base_url;
    }
}
