<?php
namespace PayStand\PayStandMagento\Controller\Api;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Request\Http;
use Psr\Log\LoggerInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Service\CreditmemoService;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Framework\ObjectManagerInterface;

/**
 * Quotes API Controller for PayStand Magento
 *
 * Handles POST /api/quotes/:id endpoint
 */
class Quotes extends Action
{
    // PayStand API Configuration Constants
    const PUBLISHABLE_KEY = 'payment/paystandmagento/publishable_key';
    const CHECKOUT_PRESET_KEY = 'payment/paystandmagento/checkout_preset_key';
    const CUSTOMER_ID = 'payment/paystandmagento/customer_id';
    const CLIENT_ID = 'payment/paystandmagento/client_id';
    const CLIENT_SECRET = 'payment/paystandmagento/client_secret';
    const USE_SANDBOX = 'payment/paystandmagento/use_sandbox';
    const SANDBOX_BASE_URL = 'https://api.paystand.co/v3';
    const BASE_URL = 'https://api.paystand.com/v3';
    const STORE_SCOPE = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;

    /**
     * @var JsonFactory
     */
    protected $jsonResultFactory;
    
    /**
     * @var Http
     */
    protected $request;
    
    /**
     * @var LoggerInterface
     */
    protected $logger;
    
    /**
     * @var QuoteFactory
     */
    protected $quoteFactory;
    
    /**
     * @var CartRepositoryInterface
     */
    protected $cartRepository;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var CreditmemoService
     */
    protected $creditmemoService;

    /**
     * @var CreditmemoFactory
     */
    protected $creditmemoFactory;

    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * Constructor
     *
     * @param Context $context
     * @param JsonFactory $jsonResultFactory
     * @param Http $request
     * @param LoggerInterface $logger
     * @param QuoteFactory $quoteFactory
     * @param CartRepositoryInterface $cartRepository
     * @param ScopeConfigInterface $scopeConfig
     * @param OrderRepositoryInterface $orderRepository
     * @param CreditmemoService $creditmemoService
     * @param CreditmemoFactory $creditmemoFactory
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(
        Context $context,
        JsonFactory $jsonResultFactory,
        Http $request,
        LoggerInterface $logger,
        QuoteFactory $quoteFactory,
        CartRepositoryInterface $cartRepository,
        ScopeConfigInterface $scopeConfig,
        OrderRepositoryInterface $orderRepository,
        CreditmemoService $creditmemoService,
        CreditmemoFactory $creditmemoFactory,
        ObjectManagerInterface $objectManager
    ) {
        $this->jsonResultFactory = $jsonResultFactory;
        $this->request = $request;
        $this->logger = $logger;
        $this->quoteFactory = $quoteFactory;
        $this->cartRepository = $cartRepository;
        $this->scopeConfig = $scopeConfig;
        $this->orderRepository = $orderRepository;
        $this->creditmemoService = $creditmemoService;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->objectManager = $objectManager;
        parent::__construct($context);
    }

    /**
     * Execute action
     *
     * Handles POST /api/quotes/:id
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->jsonResultFactory->create();
        
        try {
            // Only allow POST requests
            if ($this->request->getMethod() !== 'POST') {
                throw new \Exception('Only POST method is allowed for this endpoint');
            }

            // Get quote ID from URL path
            $quoteId = $this->getRequest()->getParam('id');
            if (!$quoteId) {
                throw new \Exception('Quote ID is required in URL path');
            }

            // Log the endpoint access
            $this->logger->info('PayStand Magento: Quotes API endpoint accessed', [
                'quote_id' => $quoteId,
                'method' => 'POST'
            ]);

            // Get request body
            $body = $this->request->getContent();
            if (empty($body)) {
                throw new \Exception('Request body is required');
            }

            $requestData = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON in request body: ' . json_last_error_msg());
            }

            // Validate required fields
            $this->validateRequestData($requestData);

            // Log request details
            $this->logger->info('PayStand Magento: Quotes API request details', [
                'quote_id' => $quoteId,
                'source_type' => $requestData['sourceType'],
                'source_id' => $requestData['sourceId'],
                'quote' => $requestData['quote'],
                'status' => $requestData['status']
            ]);

            // Verify quote exists and matches the one in request body
            $quote = $this->loadAndValidateQuote($quoteId, $requestData['quote']);

            // Get PayStand access token
            $accessToken = $this->getPaystandAccessToken();
            if (!$accessToken) {
                throw new \Exception('Failed to get PayStand access token. Check configuration.');
            }

            // Fetch resource from PayStand API
            $paystandResource = $this->fetchPaystandResource($accessToken, $requestData['sourceType'], $requestData['sourceId']);

            // Find the associated order
            $order = $this->findOrderByQuote($quote);
            if (!$order || !$order->getId()) {
                throw new \Exception("No order found for quote ID: {$quoteId}");
            }

            // Process the quote/order update based on the resource data
            $response = $this->processOrderUpdate($order, $requestData, $paystandResource);

            // Set success response
            $result->setHttpResponseCode(200);
            $result->setData($response);

        } catch (\Exception $e) {
            // Log error
            $this->logger->error('PayStand Magento: Error in quotes API endpoint', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'quote_id' => $quoteId ?? null
            ]);

            // Set error response for temporal retry
            $result->setHttpResponseCode(500); // Use 500 to trigger temporal retry
            $result->setData([
                'error' => true,
                'message' => $e->getMessage(),
                'retry' => true
            ]);
        }

        return $result;
    }

    /**
     * Validate request data structure
     *
     * @param array $requestData
     * @throws \Exception
     */
    protected function validateRequestData($requestData)
    {
        $requiredFields = ['sourceType', 'sourceId', 'quote', 'status'];
        
        foreach ($requiredFields as $field) {
            if (!isset($requestData[$field])) {
                throw new \Exception("Missing required field: {$field}");
            }
        }

        // Validate sourceType
        $allowedSourceTypes = ['Payment', 'Refund', 'Dispute'];
        if (!in_array($requestData['sourceType'], $allowedSourceTypes)) {
            throw new \Exception('Invalid sourceType. Must be one of: ' . implode(', ', $allowedSourceTypes));
        }

        // Validate sourceId is not empty
        if (empty($requestData['sourceId'])) {
            throw new \Exception('sourceId cannot be empty');
        }

        // Validate quote is not empty
        if (empty($requestData['quote'])) {
            throw new \Exception('quote cannot be empty');
        }

        // Validate status is not empty
        if (empty($requestData['status'])) {
            throw new \Exception('status cannot be empty');
        }
    }

    /**
     * Load and validate quote
     *
     * @param string $urlQuoteId Quote ID from URL
     * @param string $bodyQuoteId Quote ID from request body
     * @return \Magento\Quote\Model\Quote
     * @throws \Exception
     */
    protected function loadAndValidateQuote($urlQuoteId, $bodyQuoteId)
    {
        // Verify quote IDs match
        if ($urlQuoteId != $bodyQuoteId) {
            throw new \Exception('Quote ID in URL does not match quote ID in request body');
        }

        try {
            // Try to load quote
            $quote = $this->cartRepository->get($urlQuoteId);
            
            if (!$quote->getId()) {
                throw new \Exception("Quote with ID {$urlQuoteId} not found");
            }

            return $quote;
            
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            throw new \Exception("Quote with ID {$urlQuoteId} not found");
        }
    }

    /**
     * Get PayStand access token
     *
     * @return string|null
     */
    protected function getPaystandAccessToken()
    {
        try {
            $oauthUrl = $this->getPaystandBaseUrl() . '/oauth/token';
            $oauth_credentials = [
                'grant_type' => "client_credentials",
                'scope' => "auth",
                'client_id' => $this->scopeConfig->getValue(self::CLIENT_ID, self::STORE_SCOPE),
                'client_secret' => $this->scopeConfig->getValue(self::CLIENT_SECRET, self::STORE_SCOPE)
            ];

            $authCurl = $this->objectManager->create(\Magento\Framework\HTTP\Client\Curl::class);
            $authCurl = $this->buildCurl($authCurl, "POST", json_encode($oauth_credentials));
            
            $this->logger->debug('PayStand API: Fetching access token');
            $authResponse = $this->runCurl($authCurl, $oauthUrl);
            
            if ($authResponse == null || !isset($authResponse->access_token)) {
                return null;
            }

            $this->logger->debug('PayStand API: Access token fetched successfully');
            return $authResponse->access_token;
        } catch (\Exception $e) {
            $this->logger->error('PayStand API: Error fetching access token', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Fetch resource from PayStand API
     *
     * @param string $accessToken
     * @param string $resourceType
     * @param string $resourceId
     * @return object
     * @throws \Exception
     */
    protected function fetchPaystandResource($accessToken, $resourceType, $resourceId)
    {
        try {
            $resourceTypeMap = [
                'Payment' => 'payments',
                'Refund' => 'refunds', 
                'Dispute' => 'disputes'
            ];

            if (!isset($resourceTypeMap[$resourceType])) {
                throw new \Exception("Unknown resource type: {$resourceType}");
            }

            $endpoint = $resourceTypeMap[$resourceType];
            $url = $this->getPaystandBaseUrl() . "/{$endpoint}/{$resourceId}";

            $headers = [
                "Authorization" => "Bearer " . $accessToken,
                "x-customer-id" => $this->scopeConfig->getValue(self::CUSTOMER_ID, self::STORE_SCOPE)
            ];

            $curl = $this->objectManager->create(\Magento\Framework\HTTP\Client\Curl::class);
            $curl = $this->buildCurl($curl, "GET", "", $headers);

            $this->logger->debug("PayStand API: Fetching {$resourceType} resource", [
                'resource_id' => $resourceId,
                'endpoint' => $endpoint
            ]);

            $response = $this->runCurl($curl, $url);

            if ($response == null) {
                throw new \Exception("Failed to fetch {$resourceType} resource from PayStand API");
            }

            if (isset($response->error)) {
                throw new \Exception("PayStand API error: " . $response->error);
            }

            $this->logger->debug("PayStand API: Successfully fetched {$resourceType} resource", [
                'resource_id' => $resourceId,
                'status' => $response->status ?? 'unknown'
            ]);

            return $response;
        } catch (\Exception $e) {
            $this->logger->error('PayStand API: Error fetching resource', [
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Find order by quote
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return \Magento\Sales\Model\Order|null
     */
    protected function findOrderByQuote($quote)
    {
        try {
            // Try to find order by quote ID
            $orderCollection = $this->objectManager->create(\Magento\Sales\Model\ResourceModel\Order\Collection::class)
                ->addFieldToFilter('quote_id', $quote->getId())
                ->setOrder('entity_id', 'DESC')
                ->setPageSize(1);

            if ($orderCollection->getSize() > 0) {
                $order = $orderCollection->getFirstItem();
                $this->logger->debug("Found order by quote ID", [
                    'order_id' => $order->getIncrementId(),
                    'quote_id' => $quote->getId()
                ]);
                return $order;
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error('Error finding order by quote', [
                'quote_id' => $quote->getId(),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Process order update based on resource data
     *
     * @param \Magento\Sales\Model\Order $order
     * @param array $requestData
     * @param object $paystandResource
     * @return array
     */
    protected function processOrderUpdate($order, $requestData, $paystandResource)
    {
        $sourceType = $requestData['sourceType'];
        $status = strtolower($paystandResource->status ?? $requestData['status']);
        $sourceId = $requestData['sourceId'];

        $this->logger->info('PayStand Magento: Processing order update', [
            'order_id' => $order->getIncrementId(),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'status' => $status
        ]);

        $actions = [];

        try {
            switch ($sourceType) {
                case 'Payment':
                    $actions = $this->processPaymentStatus($order, $status, $paystandResource);
                    break;
                case 'Refund':
                    $actions = $this->processRefundStatus($order, $status, $paystandResource);
                    break;
                case 'Dispute':
                    $actions = $this->processDisputeStatus($order, $status, $paystandResource);
                    break;
                default:
                    throw new \Exception("Unknown source type: {$sourceType}");
            }

            return [
                'success' => true,
                'message' => 'Order processed successfully',
                'order_id' => $order->getIncrementId(),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'status' => $status,
                'actions_taken' => $actions,
                'timestamp' => date('c')
            ];

        } catch (\Exception $e) {
            $this->logger->error('PayStand Magento: Error processing order update', [
                'order_id' => $order->getIncrementId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Process payment status
     *
     * @param \Magento\Sales\Model\Order $order
     * @param string $status
     * @param object $paystandResource
     * @return array
     */
    protected function processPaymentStatus($order, $status, $paystandResource)
    {
        $actions = [];

        switch ($status) {
            case 'paid':
                // Move order to complete
                if ($order->getState() != Order::STATE_COMPLETE) {
                    $order->setState(Order::STATE_COMPLETE);
                    $order->setStatus(Order::STATE_COMPLETE);
                    $order->addStatusHistoryComment(__('Order marked as complete by PayStand payment status: paid'), false);
                    $order->save();
                    $actions[] = 'moved_to_complete';
                    $this->logger->info('Order moved to complete status', ['order_id' => $order->getIncrementId()]);
                }
                break;
            case 'posted':
                // Move order to processing
                if ($order->getState() != Order::STATE_PROCESSING) {
                    $order->setState(Order::STATE_PROCESSING);
                    $order->setStatus(Order::STATE_PROCESSING);
                    $order->addStatusHistoryComment(__('Order moved to processing by PayStand payment status: posted'), false);
                    $order->save();
                    $actions[] = 'moved_to_processing';
                    $this->logger->info('Order moved to processing status', ['order_id' => $order->getIncrementId()]);
                }
                break;
            default:
                $actions[] = 'no_action_for_status_' . $status;
                $this->logger->debug('No action taken for payment status', [
                    'order_id' => $order->getIncrementId(),
                    'status' => $status
                ]);
        }

        return $actions;
    }

    /**
     * Process refund status
     *
     * @param \Magento\Sales\Model\Order $order
     * @param string $status
     * @param object $paystandResource
     * @return array
     */
    protected function processRefundStatus($order, $status, $paystandResource)
    {
        $actions = [];

        switch ($status) {
            case 'paid':
                // Create credit memo for refund amount
                $refundAmount = $paystandResource->amount ?? 0;
                if ($refundAmount > 0) {
                    $creditmemo = $this->createCreditMemo($order, $refundAmount, 'PayStand refund processed');
                    if ($creditmemo) {
                        $actions[] = 'credit_memo_created';
                        $this->logger->info('Credit memo created for refund', [
                            'order_id' => $order->getIncrementId(),
                            'amount' => $refundAmount,
                            'creditmemo_id' => $creditmemo->getIncrementId()
                        ]);
                    }
                }
                break;
            case 'posted':
                // Do nothing
                $actions[] = 'no_action_for_posted_refund';
                $this->logger->debug('No action taken for posted refund', ['order_id' => $order->getIncrementId()]);
                break;
            default:
                $actions[] = 'no_action_for_status_' . $status;
        }

        return $actions;
    }

    /**
     * Process dispute status
     *
     * @param \Magento\Sales\Model\Order $order
     * @param string $status
     * @param object $paystandResource
     * @return array
     */
    protected function processDisputeStatus($order, $status, $paystandResource)
    {
        $actions = [];

        switch ($status) {
            case 'won':
                // Create credit memo for disputed amount
                $disputeAmount = $paystandResource->amount ?? 0;
                if ($disputeAmount > 0) {
                    $creditmemo = $this->createCreditMemo($order, $disputeAmount, 'PayStand dispute won');
                    if ($creditmemo) {
                        $actions[] = 'credit_memo_created_for_dispute';
                        $this->logger->info('Credit memo created for won dispute', [
                            'order_id' => $order->getIncrementId(),
                            'amount' => $disputeAmount,
                            'creditmemo_id' => $creditmemo->getIncrementId()
                        ]);
                    }
                }
                break;
            case 'lost':
                // Do nothing
                $actions[] = 'no_action_for_lost_dispute';
                $this->logger->debug('No action taken for lost dispute', ['order_id' => $order->getIncrementId()]);
                break;
            default:
                $actions[] = 'no_action_for_status_' . $status;
        }

        return $actions;
    }

    /**
     * Create credit memo
     *
     * @param \Magento\Sales\Model\Order $order
     * @param float $amount
     * @param string $comment
     * @return \Magento\Sales\Model\Order\Creditmemo|null
     */
    protected function createCreditMemo($order, $amount, $comment)
    {
        try {
            if (!$order->canCreditmemo()) {
                $this->logger->warning('Order cannot be credited', ['order_id' => $order->getIncrementId()]);
                return null;
            }

            $creditmemo = $this->creditmemoFactory->createByOrder($order);
            
            // Set adjustment refund to the specified amount
            $creditmemo->setAdjustmentPositive($amount);
            $creditmemo->setGrandTotal($amount);
            $creditmemo->setBaseGrandTotal($amount);
            
            // Add comment
            $creditmemo->addComment($comment, false, false);

            // Save the credit memo
            $this->creditmemoService->refund($creditmemo);

            $this->logger->info('Credit memo created successfully', [
                'order_id' => $order->getIncrementId(),
                'creditmemo_id' => $creditmemo->getIncrementId(),
                'amount' => $amount
            ]);

            return $creditmemo;
        } catch (\Exception $e) {
            $this->logger->error('Error creating credit memo', [
                'order_id' => $order->getIncrementId(),
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            throw new \Exception("Failed to create credit memo: " . $e->getMessage());
        }
    }

    /**
     * Get PayStand base URL
     *
     * @return string
     */
    protected function getPaystandBaseUrl()
    {
        if ($this->scopeConfig->getValue(self::USE_SANDBOX, self::STORE_SCOPE)) {
            return self::SANDBOX_BASE_URL;
        } else {
            return self::BASE_URL;
        }
    }

    /**
     * Build CURL request
     *
     * @param \Magento\Framework\HTTP\Client\Curl $curl
     * @param string $verb
     * @param string $body
     * @param array $extheaders
     * @return \Magento\Framework\HTTP\Client\Curl
     */
    protected function buildCurl($curl, $verb, $body = "", $extheaders = null)
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

    /**
     * Run CURL request
     *
     * @param \Magento\Framework\HTTP\Client\Curl $curl
     * @param string $url
     * @return object|null
     */
    protected function runCurl($curl, $url)
    {
        try {
            if (strpos($url, 'oauth/token') !== false) {
                $curl->post($url, null);
            } else {
                $curl->get($url);
            }
            
            $raw_response = $curl->getBody();
            $response = json_decode($raw_response);
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('CURL request failed', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
} 