<?php

namespace PayStand\PayStandMagento\Controller\Webhook;

use \Magento\Framework\App\Config\ScopeConfigInterface as ScopeConfig;
use \Magento\Quote\Model\QuoteFactory as QuoteFactory;
use \Magento\Quote\Model\QuoteIdMaskFactory as QuoteIdMaskFactory;
use \stdClass;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface as BuilderInterface;
use Magento\Sales\Model\Order;

/**
 * Webhook Receiver Controller for Paystand
 */
class Paystand extends \Magento\Framework\App\Action\Action
{

    // Get configuration from Paystand's payment method settings & set constants
    const PUBLISHABLE_KEY = 'payment/paystandmagento/publishable_key';
    const CHECKOUT_PRESET_KEY = 'payment/paystandmagento/checkout_preset_key';
    const CUSTOMER_ID = 'payment/paystandmagento/customer_id';
    const CLIENT_ID = 'payment/paystandmagento/client_id';
    const CLIENT_SECRET = 'payment/paystandmagento/client_secret';
    const UPDATE_ORDER_ON = 'payment/paystandmagento/update_order_on';
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

    /**
     * @var \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface
     */
    protected $_builderInterface;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory
     */
    protected $_invoiceCollectionFactory;

    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    protected $_invoiceService;

    /**
     * @var \Magento\Framework\DB\TransactionFactory
     */
    protected $_transactionFactory;

    /**
     * @var \Magento\Sales\Api\InvoiceRepositoryInterface
     */
    protected $_invoiceRepository;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $_orderRepository;

    protected $error;
    protected $errno;
    protected $updateOrderOn;

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
        ScopeConfig $scopeConfig,
        BuilderInterface $builderInterface,
        \Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory $invoiceCollectionFactory,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Framework\DB\TransactionFactory $transactionFactory,
        \Magento\Sales\Api\InvoiceRepositoryInterface $invoiceRepository,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
    ) {
        $this->_logger = $logger;
        $this->_request = $request;
        $this->_jsonResultFactory = $jsonResultFactory;
        $this->_objectManager = $objectManager;
        $this->_quoteFactory = $quoteFactory;
        $this->_quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->scopeConfig = $scopeConfig;
        $this->_builderInterface = $builderInterface;
        $this->_invoiceCollectionFactory = $invoiceCollectionFactory;
        $this->_invoiceService = $invoiceService;
        $this->_transactionFactory = $transactionFactory;
        $this->_invoiceRepository = $invoiceRepository;
        $this->_orderRepository = $orderRepository;
        $this->updateOrderOn = $this->scopeConfig->getValue(self::UPDATE_ORDER_ON, self::STORE_SCOPE);
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
        $this->_logger->debug(">>>>> PAYSTAND-REQUEST-RECEIVED: " . json_encode($json));

        // Verify the received event is a Paystand-Magento request
        if (!isset($json->resource->meta->source) || ($json->resource->meta->source != "magento 2")) {
            $this->_logger->debug('>>>>> PAYSTAND-FINISH: not a Paystand-Magento request');
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
            $result->setData(
                ['success_message' => __('Event finished: Not a Paystand-Magento request')]
            );
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

        $updateOrderOn = $this->updateOrderOn;
        $this->_logger->debug(">>>>> PAYSTAND-UPDATE-ORDER-ON: '{$updateOrderOn}'");
        $psPaymentStatus = $json->resource->status;
        $this->_logger->debug(">>>>> PAYSTAND-PAYMENT-STATUS: '{$psPaymentStatus}'");

        // Verify if the payment status is not the same as the updateOrderOn value and is not failed
        if ($psPaymentStatus != $updateOrderOn && $psPaymentStatus != 'failed') {
          $this->_logger->debug(
              ">>>>> PAYSTAND-FINISH: payment {$psPaymentStatus}, no need to update order"
          );
          $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
          $result->setData(
              ['success_message' => __("Event verified, payment {$psPaymentStatus}, no further action")]
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

        // Hardcoded retry configuration
        $maxRetries = 3;  // Fixed number of retries
        $retryDelay = 3;  // Fixed delay in seconds between retries

        // Initial wait to give Magento time to process the order
        $this->_logger->debug(">>>>> PAYSTAND-WEBHOOK: Initial wait for " . $retryDelay . " seconds before checking for order...");
        sleep($retryDelay);

        // Try to find the order using multiple methods
        $order = $this->findOrder($quote);

        $retryCount = 0;

        while ((!$order || !$order->getId()) && $retryCount < $maxRetries) {
            $this->_logger->debug(
                ">>>>> PAYSTAND-WEBHOOK: Order not found on attempt " . ($retryCount + 1) . 
                ", waiting " . $retryDelay . " seconds before retry..."
            );

            // Sleep for the specified delay
            sleep($retryDelay);

            // Try to get the order again using multiple methods
            $order = $this->findOrder($quote);

            $retryCount++;
        }

        // If we found the order after retries, log it
        if ($retryCount > 0 && $order && $order->getId()) {
            $this->_logger->debug(
                ">>>>> PAYSTAND-WEBHOOK: Order found after " . $retryCount . " retry attempts: " . $order->getIncrementId()
            );
        }

        // Order does not exist, handle it differently
        if (!$order || !$order->getId()) {
            // This is a valid webhook with a quote but no associated order yet
            // Let's handle it differently instead of returning an error

            $this->_logger->debug('>>>>> PAYSTAND-WEBHOOK: Handling payment for quote without complete order after ' . 
                $maxRetries . ' retry attempts, quoteId = ' . $quote->getId());

            // Get payment status from the webhook
            $psPaymentStatus = $json->resource->status;
            $this->_logger->debug(">>>>> PAYSTAND-PAYMENT-STATUS: '{$psPaymentStatus}' for quote ID " . $quote->getId());

            // Get configured updateOrderOn value
            $updateOrderOn = $this->updateOrderOn;
            $this->_logger->debug(">>>>> PAYSTAND-UPDATE-ORDER-ON: '{$updateOrderOn}'");

            // Update the quote with payment information
            try {
                // First, make sure the quote is valid
                if ($quote && $quote->getId()) {
                    // Make sure quote is active
                    if (!$quote->getIsActive()) {
                        $quote->setIsActive(true);
                    }

                    // Instead of setting attributes directly on the quote (which doesn't persist),
                    // store the payment information in the payment object
                    $payment = $quote->getPayment();
                    if ($payment) {
                        $payment->setAdditionalInformation('paystand_payment_status', $psPaymentStatus);
                        $payment->setAdditionalInformation('paystand_payment_id', $json->resource->id);
                        $payment->setAdditionalInformation('paystand_payment_data', json_encode($json->resource));
                        $payment->save();

                        $this->_logger->debug('>>>>> PAYSTAND-WEBHOOK: Successfully updated quote payment with payment information');
                    } else {
                        $this->_logger->debug('>>>>> PAYSTAND-WEBHOOK: Could not find payment object for quote');
                    }

                    // Save quote
                    $quote->save();

                    // Acknowledge the webhook
                    $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
                    $result->setData([
                        'success_message' => __('Payment information recorded for quote, awaiting order completion'),
                        'quote_id' => $quote->getId(),
                        'payment_status' => $psPaymentStatus
                    ]);

                    return $result;
                }
            } catch (\Exception $e) {
                $this->_logger->error('>>>>> PAYSTAND-ERROR: Exception while updating quote: ' . $e->getMessage());
                // Continue to standard error response
            }
        }

        // Order exists, get current order statuses
        $state = $order->getState();
        $status = $order->getStatus();
        $this->_logger->debug(
            '>>>>> PAYSTAND-ORDER: current order id: "' . $order->getIncrementId()
                . '", current order state: "' . $state . '", current order status: "' . $status . '"'
        );

        // Check if order is already processing or canceled
        if ($state == Order::STATE_PROCESSING || $state == Order::STATE_CANCELED) {
            $this->_logger->debug('>>>>> PAYSTAND-FINISH: Order already ' . $state . ', no further action needed');
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
            $result->setData([
                'success_message' => __('Order already %1, no further action needed', $state)
            ]);
            return $result;
        }

        // If payment status has already been processed, there is no further action
        if ($state == 'processing' || $status == 'processing') {
            $this->_logger->debug(
                '>>>>> PAYSTAND-FINISH: payment already processed, no further action needed'
            );
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
            $result->setData(
                ['success_message' => __('payment already processed, no further action needed')]
            );
            return $result;
        }

        $newStatus = $this->newOrderStatus($psPaymentStatus);

        if ($newStatus != '') {
            $state = $newStatus;
            $status = $newStatus;

            // Assign new status to Magento 2 Order
            $order->setState($state);
            $order->setStatus($status);
            $order->save();
        }

        // Only create transaction and invoice when the payment is on paid status to prevent multiple objects
        if ($psPaymentStatus == $updateOrderOn) {
            // Create Transaction for the Order
            $this->createTransaction($order, json_decode($body, true)['resource']);
            // Automatically invoice order
            $this->createInvoice($order);
        }

        // Finish and send back success response
        $this->_logger->debug(
            '>>>>> PAYSTAND-FINISH: Paystand payment status: "' . $psPaymentStatus
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
        if ($status == $this->updateOrderOn) {
            $newStatus = Order::STATE_PROCESSING;
        } else if ($status == 'canceled') {
            $newStatus = Order::STATE_CANCELED;
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

    private function createTransaction($order = null, $paymentData = [])
    {
        try {
            //get payment object from order object
            $this->_logger->debug('>>>>> PAYSTAND-CREATE-TRANSACTION-START');
            $payment = $order->getPayment();
            $payment->setLastTransId($paymentData['id']);
            $payment->setTransactionId($paymentData['id']);
            $payment->setAdditionalInformation(
                [\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => $paymentData]
            );

            // Formated price
            $formatedPrice = $order->getBaseCurrency()->formatTxt(
                $order->getGrandTotal()
            );

            $paystandPaymentInfo = $this->retrievePaystandPaymentInfo($paymentData);
            $message = sprintf(
                'Amount: %s<br/>Paystand Payment ID: %s<br/>Paystand Payer ID: %s<br/>'
                    .'Paystand %s ID: %s<br/>Magento quote ID: %s<br/>',
                $formatedPrice,
                $paystandPaymentInfo['paystandTransactionId'],
                $paystandPaymentInfo['payerId'],
                $paystandPaymentInfo['sourceType'],
                $paystandPaymentInfo['sourceId'],
                $paystandPaymentInfo['quote']
            );
            //get the object of builder class
            $trans = $this->_builderInterface;
            $transaction = $trans->setPayment($payment)
                ->setOrder($order)
                ->setTransactionId($paymentData['id'])
                ->setAdditionalInformation(
                    [\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS
                        => $paystandPaymentInfo]
                )
                ->setFailSafe(true)
                //build method creates the transaction and returns the object
                ->build(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE);

            $payment->addTransactionCommentsToOrder(
                $transaction,
                $message
            );
            $payment->setParentTransactionId(null);
            $payment->save();
            $order->save();

            $transactionId = $transaction->save()->getTransactionId();
            $this->_logger->debug('>>>>> PAYSTAND-CREATE-TRANSACTION-FINISH: transactionId: ' . $transactionId);
            return  $transactionId;
        } catch (\Magento\Framework\Exception\AlreadyExistsException $e) {
            $this->_logger->debug('>>>>> PAYSTAND-EXCEPTION: ' . $e);
        }
    }

    private function retrievePaystandPaymentInfo($json)
    {
        if ($json) {
            $paymentInfo = [
                'paystandTransactionId' => $json['id'],
                'amount' => $json['settlementAmount'],
                'currency' => $json['settlementCurrency'],
                'paymentStatus' => $json['status'],
                'payerId' => $json['payerId'],
                'sourceType' => $json['sourceType'],
                'sourceId' => $json['sourceId'],
                'quote' => $json['meta']['quote']
            ];
        } else {
            $paymentInfo = [];
        }
        return $paymentInfo;
    }

    private function createInvoice($order)
    {
        try {
            $this->_logger->debug('>>>>> PAYSTAND-CREATE-INVOICE-START');
            if ($order) {
                $invoices = $this->_invoiceCollectionFactory->create()
                    ->addAttributeToFilter('order_id', ['eq' => $order->getId()]);

                $invoices->getSelect()->limit(1);

                if ((int)$invoices->count() !== 0) {
                    $invoices = $invoices->getFirstItem();
                    $invoice = $this->_invoiceRepository->get($invoices->getId());
                    return $invoice;
                }

                if (!$order->canInvoice()) {
                    return null;
                }

                $invoice = $this->_invoiceService->prepareInvoice($order);
                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                $invoice->register();
                $invoice->getOrder()->setCustomerNoteNotify(false);
                $invoice->getOrder()->setIsInProcess(true);
                $order->addStatusHistoryComment(__('Automatically INVOICED by Paystand'), false);
                $transactionSave = $this->_transactionFactory
                    ->create()
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder());
                $transactionSave->save();
                $this->_logger->debug('>>>>> PAYSTAND-CREATE-INVOICE-FINISH: invoiceId: ' . $invoice->getEntityId());
                return $invoice;
            }
        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __($e->getMessage())
            );
        }
    }

    /**
     * Comprehensive method to find an order using multiple approaches
     * 
     * @param \Magento\Quote\Model\Quote $quote
     * @return \Magento\Sales\Model\Order|null
     */
    private function findOrder($quote)
    {
        $quoteId = $quote->getId();
        $reservedOrderId = $quote->getReservedOrderId();
        $this->_logger->debug(">>>>> PAYSTAND-WEBHOOK: Finding order for Quote ID: {$quoteId}, Reserved Order ID: {$reservedOrderId}");

        $order = null;

        // Method 1: Try to load by increment ID
        try {
            $order = $this->_objectManager->create(\Magento\Sales\Model\Order::class)
                ->loadByIncrementId($reservedOrderId);

            if ($order && $order->getId()) {
                $this->_logger->debug(">>>>> PAYSTAND-WEBHOOK: Found order by increment ID: " . $order->getIncrementId());
                return $order;
            }
        } catch (\Exception $e) {
            $this->_logger->debug(">>>>> PAYSTAND-WEBHOOK: Error loading order by increment ID: " . $e->getMessage());
        }

        // Method 2: Try to load by entity ID if the reserved ID is numeric
        if (is_numeric($reservedOrderId)) {
            try {
                $order = $this->_objectManager->create(\Magento\Sales\Model\Order::class)
                    ->load($reservedOrderId);

                if ($order && $order->getId()) {
                    $this->_logger->debug(">>>>> PAYSTAND-WEBHOOK: Found order by entity ID: " . $order->getIncrementId());
                    return $order;
                }
            } catch (\Exception $e) {
                $this->_logger->debug(">>>>> PAYSTAND-WEBHOOK: Error loading order by entity ID: " . $e->getMessage());
            }
        }

        // Method 3: Try to find by quote ID
        try {
            $orderCollection = $this->_objectManager->create(\Magento\Sales\Model\ResourceModel\Order\Collection::class)
                ->addFieldToFilter('quote_id', $quoteId)
                ->setOrder('entity_id', 'DESC')
                ->setPageSize(1);

            if ($orderCollection->getSize() > 0) {
                $order = $orderCollection->getFirstItem();
                $this->_logger->debug(">>>>> PAYSTAND-WEBHOOK: Found order by quote ID: " . $order->getIncrementId());
                return $order;
            }
        } catch (\Exception $e) {
            $this->_logger->debug(">>>>> PAYSTAND-WEBHOOK: Error finding order by quote ID: " . $e->getMessage());
        }

        // Method 4: Direct database query as last resort
        try {
            $connection = $this->_objectManager->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
            $tableName = $connection->getTableName('sales_order');

            // Query by quote_id
            $select = $connection->select()
                ->from($tableName)
                ->where('quote_id = ?', $quoteId)
                ->order('entity_id DESC')
                ->limit(1);

            $orderData = $connection->fetchRow($select);

            if ($orderData && isset($orderData['entity_id'])) {
                $order = $this->_objectManager->create(\Magento\Sales\Model\Order::class)
                    ->load($orderData['entity_id']);

                if ($order && $order->getId()) {
                    $this->_logger->debug(">>>>> PAYSTAND-WEBHOOK: Found order by direct database query: " . $order->getIncrementId());
                    return $order;
                }
            }
        } catch (\Exception $e) {
            $this->_logger->debug(">>>>> PAYSTAND-WEBHOOK: Error with direct database query: " . $e->getMessage());
        }

        $this->_logger->debug(">>>>> PAYSTAND-WEBHOOK: Could not find any order for Quote ID: {$quoteId}");
        return null;
    }
}
