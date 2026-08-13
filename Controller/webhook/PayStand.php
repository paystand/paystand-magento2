<?php

namespace PayStand\PayStandMagento\Controller\Webhook;

use \Magento\Framework\App\Config\ScopeConfigInterface as ScopeConfig;
use \Magento\Quote\Model\QuoteIdMaskFactory as QuoteIdMaskFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Framework\Lock\LockManagerInterface;
use \stdClass;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface as BuilderInterface;
use Magento\Sales\Model\Order;
use PayStand\PayStandMagento\Helper\CloudLogger;
use PayStand\PayStandMagento\Model\Config\Source\PaymentStatus;

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

    /**
     * Stop requesting redelivery for an unplaceable quote after this long. A late
     * success would create an order at stale prices, possibly after a refund.
     */
    const RESCUE_ABANDON_AFTER_HOURS = 24;

    /** @var \Psr\Log\LoggerInterface */
    protected $_logger;

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

    /** @var CartRepositoryInterface */
    private $cartRepository;

    /** @var CartManagementInterface */
    private $cartManagement;

    /** @var LockManagerInterface */
    private $lockManager;

    /** @var \PayStand\PayStandMagento\Helper\QuoteShipping */
    private $quoteShipping;

    /**
     * Why the last createOrderFromQuote() failed. 'terminal' means Magento rejected
     * the cart itself, so retrying the same cart fails the same way.
     *
     * @var array{terminal: bool, message: string}|null
     */
    private $lastRescueFailure = null;

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
        QuoteIdMaskFactory $quoteIdMaskFactory,
        ScopeConfig $scopeConfig,
        BuilderInterface $builderInterface,
        \Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory $invoiceCollectionFactory,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Framework\DB\TransactionFactory $transactionFactory,
        \Magento\Sales\Api\InvoiceRepositoryInterface $invoiceRepository,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        CartRepositoryInterface $cartRepository,
        CartManagementInterface $cartManagement,
        LockManagerInterface $lockManager,
        \PayStand\PayStandMagento\Helper\QuoteShipping $quoteShipping
    ) {
        $this->_logger = $logger;
        $this->_request = $request;
        $this->_jsonResultFactory = $jsonResultFactory;
        $this->_objectManager = $objectManager;
        $this->_quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->scopeConfig = $scopeConfig;
        $this->_builderInterface = $builderInterface;
        $this->_invoiceCollectionFactory = $invoiceCollectionFactory;
        $this->_invoiceService = $invoiceService;
        $this->_transactionFactory = $transactionFactory;
        $this->_invoiceRepository = $invoiceRepository;
        $this->_orderRepository = $orderRepository;
        $this->cartRepository = $cartRepository;
        $this->cartManagement = $cartManagement;
        $this->lockManager = $lockManager;
        $this->quoteShipping = $quoteShipping;
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

        // Log webhook start now that we have the parsed payload
        try {
            CloudLogger::ship(CloudLogger::EVENT_WEBHOOK_START, [
                'quote_id'      => $json->resource->meta->quote ?? '',
                'payment_id'    => $json->resource->id ?? '',
                'error_message' => 'Webhook started',
            ]);
        } catch (\Throwable $e) {
            // CloudLogger failure — silently ignored to protect payment flow
        }

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
        if (!isset($json->resource->object) || $json->resource->object != "payment") {
          $this->_logger->debug('>>>>> PAYSTAND-EVENT-VERIFICATION-FINISH: Not a payment event');
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

        // Define statuses that should trigger order updates
        $processableStatuses = [$updateOrderOn, 'failed', 'processing', 'posted', 'paid'];
        
        // Verify if the payment status should trigger an order update
        if (!in_array($psPaymentStatus, $processableStatuses)) {
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

        // Get Order Id from quote using repository (service contract)
        try {
            $quote = $this->cartRepository->get((int)$id);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->_logger->error('>>>>> PAYSTAND-ERROR: Quote not found for ID: ' . $id);
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Exception::HTTP_BAD_REQUEST);
            $result->setData(['error_message' => __('Quote not found')]);
            return $result;
        } catch (\Magento\Framework\Exception\StateException $e) {
            $this->_logger->error('>>>>> PAYSTAND-ERROR: Quote in invalid state for ID: ' . $id . ' - ' . $e->getMessage());
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Exception::HTTP_BAD_REQUEST);
            $result->setData(['error_message' => __('Quote cannot be loaded')]);
            return $result;
        }

        // Retry configuration - Initial delay for production stability
        $maxRetries = 3;  // Fixed number of retries
        $retryDelay = 3;  // Fixed delay in seconds between retries
        $initialDelay = 10;  // Initial delay to ensure observer completes (production-safe value)
        $webhookStartTime = microtime(true);
        $this->_logger->debug(">>>>> PAYSTAND-WEBHOOK-START: Webhook execution started at " . date('Y-m-d H:i:s.u'));
        $this->_logger->debug(">>>>> PAYSTAND-WEBHOOK-RACE-CONDITION-FIX: Using initial delay of {$initialDelay} seconds to allow observer to complete");

        // Initial wait to give Magento observer time to set order to Pending state
        $this->_logger->debug(">>>>> PAYSTAND-WEBHOOK: Initial wait for {$initialDelay} seconds before checking for order...");
        sleep($initialDelay);
        $afterInitialDelay = microtime(true);
        $this->_logger->debug(
            ">>>>> PAYSTAND-WEBHOOK: Initial delay completed. " .
            "Time elapsed: " . round(($afterInitialDelay - $webhookStartTime) * 1000, 2) . "ms"
        );

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

        // Order still does not exist after retries. The payment was already
        // captured at Paystand, so a missing order means the client-side placeOrder
        // failed to convert the paid quote — leaving the shopper charged with no
        // order. Rather than only searching, make the webhook the source of truth:
        // create the order server-side from the paid quote so a captured payment
        // always yields an order.
        if (!$order || !$order->getId()) {
            $order = $this->createOrderFromQuote($quote, $json);
        }

        // Server-side creation also failed (or was not possible).
        if (!$order || !$order->getId()) {
            // Give up only when the failure is terminal AND the event has outlived
            // the window; a transient blip must still get its retries.
            if ($this->isRescueTerminalAndExpired($json)) {
                $reason = $this->lastRescueFailure['message'] ?? 'unknown';
                $this->_logger->error(
                    '>>>>> PAYSTAND-ABANDONED: Giving up on quote ' . $quote->getId()
                    . ' after ' . self::RESCUE_ABANDON_AFTER_HOURS . 'h; cart cannot be placed: ' . $reason
                );
                try {
                    CloudLogger::ship(CloudLogger::EVENT_RESCUE_ABANDONED, [
                        'quote_id'      => (string)$quote->getId(),
                        'payment_id'    => $json->resource->id ?? '',
                        'error_message' => 'Paid but unplaceable for >' . self::RESCUE_ABANDON_AFTER_HOURS
                            . 'h; needs manual resolution. Last error: ' . $reason,
                    ]);
                } catch (\Throwable $e) {
                    // CloudLogger failure — silently ignored to protect payment flow
                }
                // 200 stops redelivery. The payment stands with no order, so the
                // rescue_abandoned event above is what support works from.
                $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
                $result->setData([
                    'success_message' => __('Order cannot be created for this quote; abandoned after retry window')
                ]);
                return $result;
            }

            // Otherwise keep the existing behaviour: 404 so Paystand retries.
            $this->_logger->error('>>>>> PAYSTAND-ERROR: Order not found after retries for quote: ' . $quote->getId());
            try {
                CloudLogger::ship(CloudLogger::EVENT_WEBHOOK_NO_ORDER, [
                'quote_id'      => (string)$quote->getId(),
                'payment_id'    => $json->resource->id ?? '',
                'error_message' => 'Order not found after retries. Returning 404 for Paystand retry. Payment status: ' . $psPaymentStatus,
                ]);
            } catch (\Throwable $e) {
                // CloudLogger failure — silently ignored to protect payment flow
            }
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Exception::HTTP_NOT_FOUND);
            $result->setData(['error_message' => __('Order not found')]);
            return $result;
        }

        if ($order) {
            // Reload order to get latest state
            $order = $this->_orderRepository->get($order->getId());
            
            // Get current order statuses
            $state = $order->getState();
            $status = $order->getStatus();
            $this->_logger->debug(
                '>>>>> PAYSTAND-ORDER: current order id: "' . $order->getIncrementId()
                    . '", current order state: "' . $state . '", current order status: "' . $status . '"'
            );

            // Skip processing if order is canceled
            if ($state == Order::STATE_CANCELED) {
                $this->_logger->debug(
                    '>>>>> PAYSTAND-FINISH: Order is canceled, no further action needed'
                );
                $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
                $result->setData([
                    'success_message' => __('Order is canceled, no further action needed')
                ]);
                return $result;
            }

            // If order is in processing and payment status is paid/posted, check if we need to create invoice
            if ($state == Order::STATE_PROCESSING && ($psPaymentStatus == $updateOrderOn || $psPaymentStatus == 'paid' || $psPaymentStatus == 'posted')) {
                $this->_logger->debug(
                    '>>>>> PAYSTAND-PROCESSING: Order already in processing state, checking if invoice needs to be created for payment status: ' . $psPaymentStatus
                );
                
                // Check if invoice already exists
                $invoices = $this->_invoiceCollectionFactory->create()
                    ->addAttributeToFilter('order_id', ['eq' => $order->getId()]);
                
                if ((int)$invoices->count() > 0) {
                    $this->_logger->debug(
                        '>>>>> PAYSTAND-FINISH: Order already has invoice, no further action needed'
                    );
                    $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
                    $result->setData([
                        'success_message' => __('Order already has invoice, no further action needed')
                    ]);
                    return $result;
                }
                
                // Invoice doesn't exist, create it
                $this->_logger->debug('>>>>> PAYSTAND-PROCESSING: Creating transaction and invoice for paid order...');
                
                // Create Transaction for the Order
                $this->createTransaction($order, json_decode($body, true)['resource']);
                
                // Automatically invoice order
                $this->createInvoice($order);
                
                $this->_logger->debug('>>>>> PAYSTAND-PROCESSING: Transaction and invoice created successfully for order ' . $order->getIncrementId());
                
                // Finish and send back success response
                $this->_logger->debug(
                    '>>>>> PAYSTAND-FINISH: Paystand payment status: "' . $psPaymentStatus
                        . '", order state: "' . $state
                        . '", order status: "' . $status . '", invoice created'
                );
                $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
                $result->setData([
                    'success_message' => __('Invoice created for order')
                ]);
                return $result;
            }
            
            // If order is already in processing state but payment is not paid/posted yet, skip
            if ($state == Order::STATE_PROCESSING) {
                $this->_logger->debug(
                    '>>>>> PAYSTAND-FINISH: Order already in processing state, payment status is ' . $psPaymentStatus . ', no further action needed'
                );
                $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
                $result->setData([
                    'success_message' => __('Order already in processing, awaiting payment confirmation')
                ]);
                return $result;
            }

            // If we're here, order is in pending (or another non-final state) and needs to be processed
            $this->_logger->debug(
                ">>>>> PAYSTAND-PROCESSING: Order is in state '{$state}', payment status is '{$psPaymentStatus}', proceeding to update..."
            );

            $newStatus = $this->newOrderStatus($psPaymentStatus);

            if ($newStatus != '') {
                $this->_logger->debug(">>>>> PAYSTAND-PROCESSING: Changing order state from '{$state}' to '{$newStatus}'");
                
                $state = $newStatus;
                $status = $newStatus;

                // Assign new status to Magento 2 Order
                $order->setState($state);
                $order->setStatus($status);
                $order->save();
                
                $this->_logger->debug(">>>>> PAYSTAND-PROCESSING: Order state set to '{$newStatus}' and saved immediately. Order ID: " . $order->getIncrementId());
            } else {
                $this->_logger->debug(">>>>> PAYSTAND-PROCESSING: newOrderStatus() returned empty for payment status '{$psPaymentStatus}'");
            }

            // Only create transaction and invoice when the payment is on paid/posted status
            if ($psPaymentStatus == $updateOrderOn) {
                $this->_logger->debug(">>>>> PAYSTAND-PROCESSING: Payment status matches configured updateOrderOn ('{$updateOrderOn}'), creating transaction and invoice...");
                
                // Create Transaction for the Order
                $this->createTransaction($order, json_decode($body, true)['resource']);
                
                // Automatically invoice order
                $this->createInvoice($order);
                
                $this->_logger->debug(">>>>> PAYSTAND-PROCESSING: Transaction and invoice created successfully for order " . $order->getIncrementId());
            } else {
                $this->_logger->debug(">>>>> PAYSTAND-PROCESSING: Payment status '{$psPaymentStatus}' does not match updateOrderOn '{$updateOrderOn}', skipping transaction/invoice creation");
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
            try {
                CloudLogger::ship(CloudLogger::EVENT_WEBHOOK_ORDER_CREATED, [
                'quote_id'      => (string)($order->getQuoteId() ?? ''),
                'payment_id'    => $json->resource->id ?? '',
                'error_message' => 'order=' . $order->getIncrementId() . ' state=' . $state . ' status=' . $status,
                ]);
            } catch (\Throwable $e) {
                // CloudLogger failure — silently ignored to protect payment flow
            }
            return $result;
        } else {
            $this->_logger->error('>>>>> PAYSTAND-ERROR: Order not found after retries for quote: ' . $id);
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Exception::HTTP_NOT_FOUND);
            $result->setData(['error_message' => __('Order not found')]);
            try {
                CloudLogger::ship(CloudLogger::EVENT_WEBHOOK_NO_ORDER, [
                'quote_id'      => (string)$id,
                'payment_id'    => $json->resource->id ?? '',
                'error_message' => 'Order not found in final else branch. Payment status: ' . $psPaymentStatus,
                ]);
            } catch (\Throwable $e) {
                // CloudLogger failure — silently ignored to protect payment flow
            }
            return $result;
        }
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
        if ($status == $this->updateOrderOn || $status == 'processing' || $status == 'posted' || $status == 'paid') {
            $newStatus = Order::STATE_PROCESSING;
        } else if ($status == 'canceled') {
            $newStatus = Order::STATE_CANCELED;
        }
        return $newStatus;
    }

    /**
     * Returns a Paystand OAuth access token.
     * Declared protected (not private) to allow PHPUnit partial mocking in unit tests.
     * In PHP, private methods cannot be stubbed by subclasses — there is no reflection
     * workaround that replaces a method implementation at runtime.
     * NOTE: do not override this in production Magento extensions — it is an internal
     * auth mechanism and is not part of the public API contract of this class.
     */
    protected function getPaystandAccessToken()
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

    /**
     * Verifies an incoming webhook event against the Paystand API.
     * Declared protected (not private) to allow PHPUnit partial mocking in unit tests.
     * See note on getPaystandAccessToken() for rationale.
     * NOTE: do not override this in production Magento extensions.
     */
    protected function verifyPaystandEvent($access_token, $event)
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
    protected function findOrder($quote)
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

    /**
     * Create an order server-side from a paid-but-unconverted quote, so a captured
     * payment always yields one. Any failure returns null and the caller 404s.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param \stdClass $json
     * @return \Magento\Sales\Model\Order|null
     */
    protected function createOrderFromQuote($quote, $json)
    {
        $quoteId = $quote ? (int)$quote->getId() : 0;
        if ($quoteId <= 0) {
            return null;
        }

        // Only create for a status that advances an order, so a declined payment can
        // never produce one. newOrderStatus() is the single source of truth: it maps
        // 'processing'/'posted'/'paid' to STATE_PROCESSING, 'failed'/'canceled' not.
        // The empty check is load-bearing — newOrderStatus() compares loosely against
        // updateOrderOn, and '' == null is true in PHP.
        $psPaymentStatus = $json->resource->status ?? null;
        if (!$psPaymentStatus || $this->newOrderStatus($psPaymentStatus) !== Order::STATE_PROCESSING) {
            $this->_logger->debug(
                '>>>>> PAYSTAND-WEBHOOK: Not creating order server-side for quote ' . $quoteId
                . ' — payment status "' . (string)$psPaymentStatus . '" does not advance an order'
            );
            return null;
        }

        // Serialize order creation for this quote so a concurrent webhook (or a
        // Paystand retry) cannot place a second order for the same cart. The
        // client-side placeOrder does not take this lock, so inside the critical
        // section we also re-read the quote's is_active flag — the authoritative
        // "already submitted" signal — before placing.
        $lockName = 'paystand_place_order_' . $quoteId;
        $lockAcquired = false;
        try {
            $lockAcquired = $this->lockManager->lock($lockName, 5);
        } catch (\Throwable $e) {
            $this->_logger->error('>>>>> PAYSTAND-WEBHOOK: Lock acquisition failed for ' . $lockName . ': ' . $e->getMessage());
        }
        if (!$lockAcquired) {
            // Fall back to search-only behaviour — observable in production
            // telemetry so lock contention/leaks are detectable, since this is
            // the failure mode closest to the original paid-but-orderless bug.
            $this->_logger->warning('>>>>> PAYSTAND-WEBHOOK: Could not acquire place-order lock for quote ' . $quoteId . '; using existing order if any');
            try {
                CloudLogger::ship(CloudLogger::EVENT_PLACEORDER_LOCK_FALLBACK, [
                    'quote_id'      => (string)$quoteId,
                    'payment_id'    => $json->resource->id ?? '',
                    'error_message' => 'Place-order lock not acquired; server-side creation skipped this delivery',
                ]);
            } catch (\Throwable $e) {
                // CloudLogger failure — silently ignored
            }
            return $this->findOrder($quote);
        }

        // Whether we reactivated the quote, so the failure path can undo it.
        $reactivated = false;
        // Once placeOrder commits, our in-memory quote is stale and must not be
        // saved back over Magento's writes — so the rollback below is skipped.
        $placed = false;

        try {
            // Idempotency: re-check right before creating, in case a concurrent
            // client-side placeOrder just succeeded.
            $existing = $this->findOrder($quote);
            if ($existing && $existing->getId()) {
                return $existing;
            }

            // Reload the quote fresh so is_active reflects any placeOrder that
            // committed while we waited on the lock.
            try {
                $quote = $this->cartRepository->get($quoteId);
            } catch (\Exception $e) {
                $this->_logger->error('>>>>> PAYSTAND-WEBHOOK: Could not reload quote ' . $quoteId . ': ' . $e->getMessage());
                return null;
            }

            // An inactive quote may be converted OR merely merged/expired/held, so
            // check for an order first (conversion commits it before deactivating)
            // and only reactivate when none exists. placeOrder loads via getActive(),
            // so reactivation is required before placing.
            if (!$quote->getIsActive()) {
                $existing = $this->findOrder($quote);
                if ($existing && $existing->getId()) {
                    $this->_logger->debug('>>>>> PAYSTAND-WEBHOOK: Quote ' . $quoteId . ' already converted; using existing order ' . $existing->getIncrementId());
                    return $existing;
                }
                // ?: not ?? — an empty marker must fall through to the webhook's id
                // rather than be kept and block the rescue.
                $paymentEvidence = $quote->getData('paystand_payment_id') ?: ($json->resource->id ?? null);
                if (!$paymentEvidence) {
                    $this->_logger->debug('>>>>> PAYSTAND-WEBHOOK: Quote ' . $quoteId . ' inactive, never converted, and no payment evidence; not reactivating');
                    return null;
                }
                $this->_logger->warning('>>>>> PAYSTAND-WEBHOOK: Quote ' . $quoteId . ' inactive but never converted despite a successful payment; reactivating to rescue the paid cart');
                $quote->setIsActive(true);
                $reactivated = true;
            }

            // Nothing to place: an empty cart.
            if (!$quote->getItemsCount()) {
                $this->_logger->error('>>>>> PAYSTAND-WEBHOOK: Cannot create order server-side — quote empty. Quote ID: ' . $quoteId);
                return null;
            }

            // A quote abandoned before the client-side placeOrder may not have a
            // payment method assigned; placeOrder requires one.
            $payment = $quote->getPayment();
            if ($payment && !$payment->getMethod()) {
                $payment->setMethod(\PayStand\PayStandMagento\Model\Directpost::METHOD_CODE);
            }

            // If no customer email is resolvable (quote, billing, or shipping),
            // bail out explicitly and observably rather than letting placeOrder
            // throw a generic exception that hides why the order failed.
            $email = $this->resolveQuoteCustomerEmail($quote);
            if (!$email) {
                $this->_logger->error('>>>>> PAYSTAND-WEBHOOK: Cannot create order server-side — no customer email on quote ' . $quoteId);
                try {
                    CloudLogger::ship(CloudLogger::EVENT_PLACEORDER_EXCEPTION, [
                        'quote_id'      => (string)$quoteId,
                        'payment_id'    => $json->resource->id ?? '',
                        'error_message' => 'Server-side order creation skipped: no customer email on quote',
                    ]);
                } catch (\Throwable $e) {
                    // CloudLogger failure — silently ignored
                }
                return null;
            }
            $quote->setCustomerEmail($email);

            // placeOrder below reloads the quote from the database and collects its
            // totals, which re-adjudicates cart price rules and can drop a discount
            // the shopper already paid on. The client-side save that normally records
            // these markers is exactly what failed in a rescue, so record them here.
            $captureId = $quote->getData('paystand_payment_id') ?: ($json->resource->id ?? null);
            $captureStatus = strtolower(trim((string)$psPaymentStatus));
            if ($captureId && in_array($captureStatus, PaymentStatus::CAPTURED_STATUSES, true)) {
                $quote->setData('paystand_payment_id', $captureId);
                $quote->setData('paystand_capture_status', $captureStatus);
                $this->_logger->debug(
                    '>>>>> PAYSTAND-WEBHOOK: Recorded capture markers on quote ' . $quoteId
                    . ' status=' . $captureStatus . ' before server-side placeOrder'
                );
            } else {
                $this->preserveCaptureStatus($quote, $quoteId);
            }

            // No collectTotals() here: placeOrder collects them itself, and ours could
            // clear the shipping method on the paid quote we are rescuing.
            try {
                CloudLogger::ship(CloudLogger::EVENT_QUOTE_SHIPPING_STATE, [
                    'quote_id'      => (string)$quoteId,
                    'payment_id'    => $json->resource->id ?? '',
                    'error_message' => 'createOrderFromQuote pre-place ' . $this->quoteShipping->describe($quote),
                ]);
            } catch (\Throwable $e) {
                // CloudLogger failure — silently ignored to protect payment flow
            }

            $this->cartRepository->save($quote);

            $orderId = $this->cartManagement->placeOrder($quoteId);
            $placed = true;
            $order = $this->_orderRepository->get($orderId);

            $this->_logger->debug('>>>>> PAYSTAND-WEBHOOK: Created order server-side from quote ' . $quoteId . ': ' . $order->getIncrementId());
            try {
                CloudLogger::ship(CloudLogger::EVENT_WEBHOOK_ORDER_CREATED, [
                    'quote_id'      => (string)$quoteId,
                    'payment_id'    => $json->resource->id ?? '',
                    'error_message' => 'order=' . $order->getIncrementId() . ' created server-side (source-of-truth fallback)',
                ]);
            } catch (\Throwable $e) {
                // CloudLogger failure — silently ignored to protect payment flow
            }

            return $order;
        } catch (\Throwable $e) {
            // LocalizedException = Magento rejecting the cart (shipping, address, stock,
            // minimum). Anything else is infrastructure and worth retrying.
            $this->lastRescueFailure = [
                'terminal' => $e instanceof \Magento\Framework\Exception\LocalizedException,
                'message'  => $e->getMessage(),
            ];
            $this->_logger->error('>>>>> PAYSTAND-ERROR: Server-side order creation failed for quote ' . $quoteId . ': ' . $e->getMessage());

            // Undo a reactivation whose placement then failed, so a deactivated cart
            // is not resurrected in the shopper's session. Skipped once placed, since
            // our copy is stale and would clobber what placeOrder wrote.
            if ($reactivated && !$placed) {
                try {
                    $quote->setIsActive(false);
                    $this->cartRepository->save($quote);
                    $this->_logger->debug('>>>>> PAYSTAND-WEBHOOK: Rolled back reactivation of quote ' . $quoteId);
                } catch (\Throwable $rollbackError) {
                    $this->_logger->error('>>>>> PAYSTAND-ERROR: Failed to roll back reactivation of quote ' . $quoteId . ': ' . $rollbackError->getMessage());
                }
            }

            try {
                CloudLogger::ship(CloudLogger::EVENT_PLACEORDER_EXCEPTION, [
                    'quote_id'      => (string)$quoteId,
                    'payment_id'    => $json->resource->id ?? '',
                    'error_message' => 'Server-side placeOrder failed: ' . $e->getMessage(),
                ]);
            } catch (\Throwable $inner) {
                // CloudLogger failure — silently ignored
            }
            return null;
        } finally {
            try {
                $this->lockManager->unlock($lockName);
            } catch (\Throwable $e) {
                // Best-effort unlock — the lock provider releases it when the
                // connection/session ends. Logged (not swallowed silently) so a
                // leaking lock is visible in production diagnostics.
                $this->_logger->error('>>>>> PAYSTAND-WEBHOOK: Failed to release place-order lock ' . $lockName . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Carries a capture status recorded since this quote was loaded back onto the
     * in-memory copy, so saving a delivery that has no capture of its own cannot
     * persist a null over it and unfreeze a cart that was already charged.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param int|string $quoteId
     * @return void
     */
    protected function preserveCaptureStatus($quote, $quoteId)
    {
        try {
            if (!empty($quote->getData('paystand_capture_status'))) {
                return;
            }

            // Uses the connection the quote itself is saved through, so this needs no
            // extra dependency and cannot drift from that table.
            $resource = $quote->getResource();
            $connection = $resource->getConnection();
            $select = $connection->select()
                ->from($resource->getMainTable(), 'paystand_capture_status')
                ->where('entity_id = ?', $quoteId);
            $persisted = $connection->fetchOne($select);

            if (!empty($persisted)) {
                $quote->setData('paystand_capture_status', $persisted);
                $this->_logger->debug(
                    '>>>>> PAYSTAND-WEBHOOK: Kept capture status ' . $persisted
                    . ' recorded for quote ' . $quoteId . ' since it was loaded'
                );
            }
        } catch (\Throwable $e) {
            // A failed read must not stop the rescue; the worst case is the status
            // this delivery already held being saved as it was loaded.
            $this->_logger->error('>>>>> PAYSTAND-WEBHOOK: Could not re-read capture status: ' . $e->getMessage());
        }
    }

    /**
     * True when the last failure was terminal AND the event is past the retry window.
     * Fails safe: an unusable timestamp never abandons.
     *
     * @param \stdClass $json
     * @return bool
     */
    protected function isRescueTerminalAndExpired($json): bool
    {
        if (empty($this->lastRescueFailure['terminal'])) {
            return false;
        }

        // Fixed across redeliveries, so it measures how long this has been failing.
        $created = $json->created ?? null;
        if (!$created) {
            return false;
        }

        try {
            $createdAt = new \DateTimeImmutable((string)$created);
            $now = new \DateTimeImmutable('now');
        } catch (\Exception $e) {
            return false;
        }

        $ageHours = ($now->getTimestamp() - $createdAt->getTimestamp()) / 3600;

        return $ageHours > self::RESCUE_ABANDON_AFTER_HOURS;
    }

    /**
     * Resolve a customer email for a quote missing one — guests enter it in the
     * widget, so recover it from the billing or shipping address.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return string|null
     */
    protected function resolveQuoteCustomerEmail($quote)
    {
        if ($quote->getCustomerEmail()) {
            return $quote->getCustomerEmail();
        }

        $billing = $quote->getBillingAddress();
        if ($billing && $billing->getEmail()) {
            return $billing->getEmail();
        }

        $shipping = $quote->getShippingAddress();
        if ($shipping && $shipping->getEmail()) {
            return $shipping->getEmail();
        }

        return null;
    }
}
