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
    protected \Psr\Log\LoggerInterface $_logger;

    /** @var \Magento\Quote\Model\QuoteFactory */
    protected \Magento\Quote\Model\QuoteFactory $_quoteFactory;

    /** @var \Magento\Quote\Model\QuoteIdMaskFactory */
    protected \Magento\Quote\Model\QuoteIdMaskFactory $_quoteIdMaskFactory;

    /** @var \Magento\Framework\App\Request\Http */
    protected \Magento\Framework\App\Request\Http $_request;

    /** @var \Magento\Framework\Controller\Result\JsonFactory */
    protected \Magento\Framework\Controller\Result\JsonFactory $_jsonResultFactory;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig;

    protected ?string $error = null;
    protected ?int $errno = null;

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
        $json = file_get_contents('php://input');
        $data = json_decode($json);
        $resultJson = $this->_jsonResultFactory->create();

        if (!$data) {
            $resultJson->setHttpResponseCode(400);
            return $resultJson->setData(['error' => 'No data received']);
        }

        try {
            $access_token = $this->getPaystandAccessToken();
            if (!$access_token) {
                throw new \Exception('Could not get access token');
            }

            $event = $this->verifyPaystandEvent($access_token, $data);
            if (!$event) {
                throw new \Exception('Could not verify event');
            }

            $resource = $event->resource;
            if (!$resource || !isset($resource->payment)) {
                throw new \Exception('No payment in resource');
            }

            $payment = $resource->payment;
            $quote_id = null;

            if (isset($payment->meta) && isset($payment->meta->quote)) {
                $quote_id = $payment->meta->quote;
            }

            if (!$quote_id) {
                throw new \Exception('No quote ID in payment meta');
            }

            $quote = $this->_quoteFactory->create()->load($quote_id);
            if (!$quote->getId()) {
                throw new \Exception('No quote found for ID ' . $quote_id);
            }

            $order = $this->_objectManager->create('Magento\Sales\Model\Order')
                ->loadByIncrementId($quote->getReservedOrderId());

            if (!$order->getId()) {
                throw new \Exception('No order found for quote ' . $quote_id);
            }

            $this->_logger->debug('Processing webhook for order ' . $order->getIncrementId());

            $payment_data = $this->retrievePaystandPaymentInfo($payment);
            if (!$payment_data) {
                throw new \Exception('Could not process payment info');
            }

            $this->createTransaction($order, $payment_data);

            $status = $this->newOrderStatus($payment->status);
            if ($status === Order::STATE_PROCESSING) {
                $this->createInvoice($order);
            }

            $order->setState($status)->setStatus($status);
            $order->save();

            return $resultJson->setData(['success' => true]);
        } catch (\Exception $e) {
            $this->_logger->critical($e);
            $resultJson->setHttpResponseCode(500);
            return $resultJson->setData(['error' => $e->getMessage()]);
        }
    }

    private function buildCurl($curl, string $verb, string $body = "", ?array $extheaders = null): void
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
    }

    private function runCurl($curl, string $url): ?stdClass
    {
        $curl->post($url, null);
        $raw_response = $curl->getBody();
        $response = json_decode($raw_response);
        return $response;
    }

    private function cleanObject(stdClass $obj, array $whitelist): stdClass
    {
        $ret = new stdClass;
        foreach ($whitelist as $prop) {
            $ret->$prop = $obj->$prop;
        }
        return $ret;
    }

    private function newOrderStatus(string $status): string
    {
        $newStatus = '';
        if ($status == $this->updateOrderOn) {
            $newStatus = Order::STATE_PROCESSING;
        } else if ($status == 'canceled') {
            $newStatus = Order::STATE_CANCELED;
        }
        return $newStatus;
    }

    private function getPaystandAccessToken(): ?string
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

    private function verifyPaystandEvent(string $access_token, stdClass $event): ?stdClass
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

    private function getBaseUrl(): string
    {
        if ($this->scopeConfig->getValue(self::USE_SANDBOX, self::STORE_SCOPE)) {
            $base_url = self::SANDBOX_BASE_URL;
        } else {
            $base_url = self::BASE_URL;
        }
        return $base_url;
    }

    private function createTransaction(?Order $order = null, array $paymentData = []): void
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
        } catch (\Magento\Framework\Exception\AlreadyExistsException $e) {
            $this->_logger->debug('>>>>> PAYSTAND-EXCEPTION: ' . $e);
        }
    }

    private function retrievePaystandPaymentInfo(stdClass $json): ?array
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

    private function createInvoice(Order $order): void
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
                    return;
                }

                if (!$order->canInvoice()) {
                    return;
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
            }
        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __($e->getMessage())
            );
        }
    }
}
