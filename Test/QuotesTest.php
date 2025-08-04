<?php
namespace PayStand\PayStandMagento\Test\Unit\Controller\Api;

use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Request\Http;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Service\CreditmemoService;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Framework\ObjectManagerInterface;
use PayStand\PayStandMagento\Controller\Api\Quotes;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QuotesTest extends TestCase
{
    private $context;
    private $jsonFactory;
    private $request;
    private $logger;
    private $quoteFactory;
    private $cartRepository;
    private $scopeConfig;
    private $orderRepository;
    private $creditmemoService;
    private $creditmemoFactory;
    private $objectManager;
    private $controller;

    protected function setUp(): void
    {
        $this->context = $this->createMock(Context::class);
        $this->jsonFactory = $this->createMock(JsonFactory::class);
        $this->request = $this->createMock(Http::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->quoteFactory = $this->createMock(QuoteFactory::class);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->creditmemoService = $this->createMock(CreditmemoService::class);
        $this->creditmemoFactory = $this->createMock(CreditmemoFactory::class);
        $this->objectManager = $this->createMock(ObjectManagerInterface::class);

        $this->controller = new Quotes(
            $this->context,
            $this->jsonFactory,
            $this->request,
            $this->logger,
            $this->quoteFactory,
            $this->cartRepository,
            $this->scopeConfig,
            $this->orderRepository,
            $this->creditmemoService,
            $this->creditmemoFactory,
            $this->objectManager
        );
    }

    public function testValidateRequestDataThrowsExceptionOnMissingFields()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing required field: sourceType');

        $reflection = new \ReflectionClass(Quotes::class);
        $method = $reflection->getMethod('validateRequestData');
        $method->setAccessible(true);
        $method->invokeArgs($this->controller, [[
            // Missing sourceType
            'sourceId' => '123',
            'quote' => '456',
            'status' => 'paid'
        ]]);
    }

    public function testValidateRequestDataValidatesCorrectly()
    {
        $reflection = new \ReflectionClass(Quotes::class);
        $method = $reflection->getMethod('validateRequestData');
        $method->setAccessible(true);

        $validData = [
            'sourceType' => 'Payment',
            'sourceId' => 'src_123',
            'quote' => 'q_123',
            'status' => 'posted'
        ];

        $this->assertNull($method->invokeArgs($this->controller, [$validData]));
    }

    public function testLoadAndValidateQuoteThrowsExceptionOnMismatch()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Quote ID in URL does not match quote ID in request body');

        $reflection = new \ReflectionClass(Quotes::class);
        $method = $reflection->getMethod('loadAndValidateQuote');
        $method->setAccessible(true);

        $method->invokeArgs($this->controller, ['url_id', 'body_id']);
    }

    public function testGetPaystandAccessTokenReturnsNullOnFailure()
    {
        $this->scopeConfig
            ->method('getValue')
            ->willReturn('invalid');

        $this->objectManager
            ->method('create')
            ->willThrowException(new \Exception("Curl error"));

        $reflection = new \ReflectionClass(Quotes::class);
        $method = $reflection->getMethod('getPaystandAccessToken');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller);
        $this->assertNull($result);
    }

    public function testFetchPaystandResourceThrowsExceptionOnInvalidType()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unknown resource type: InvalidType');

        $reflection = new \ReflectionClass(Quotes::class);
        $method = $reflection->getMethod('fetchPaystandResource');
        $method->setAccessible(true);

        $method->invokeArgs($this->controller, ['token', 'InvalidType', 'src_123']);
    }

    public function testProcessOrderUpdateThrowsExceptionOnUnknownSourceType()
    {
        $orderMock = $this->getMockBuilder(\Magento\Sales\Model\Order::class)
            ->disableOriginalConstructor()
            ->getMock();

        $reflection = new \ReflectionClass(Quotes::class);
        $method = $reflection->getMethod('processOrderUpdate');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unknown source type: Unknown');

        $requestData = ['sourceType' => 'Unknown', 'status' => 'posted', 'sourceId' => 'src', 'quote' => 'qid'];
        $method->invokeArgs($this->controller, [$orderMock, $requestData, (object)['status' => 'posted']]);
    }

    //NEW CONTENT



    public function testLoadAndValidateQuoteSuccessful()
    {
        $quoteMock = $this->getMockBuilder(\Magento\Quote\Model\Quote::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $quoteMock->method('getId')->willReturn(123);
        
        $this->cartRepository
            ->expects($this->once())
            ->method('get')
            ->with('quote_123')
            ->willReturn($quoteMock);

        $reflection = new \ReflectionClass(Quotes::class);
        $method = $reflection->getMethod('loadAndValidateQuote');
        $method->setAccessible(true);

        $result = $method->invokeArgs($this->controller, ['quote_123', 'quote_123']);
        $this->assertSame($quoteMock, $result);
    }

    public function testGetPaystandAccessTokenSuccessful()
    {
        $mockResponse = (object)['access_token' => 'test_token_123'];
        
        $this->scopeConfig
            ->method('getValue')
            ->willReturnMap([
                [Quotes::CLIENT_ID, Quotes::STORE_SCOPE, null, 'test_client_id'],
                [Quotes::CLIENT_SECRET, Quotes::STORE_SCOPE, null, 'test_client_secret'],
                [Quotes::USE_SANDBOX, Quotes::STORE_SCOPE, null, true]
            ]);

        $curlMock = $this->getMockBuilder(\Magento\Framework\HTTP\Client\Curl::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $curlMock->method('getBody')->willReturn(json_encode($mockResponse));

        $this->objectManager
            ->method('create')
            ->with(\Magento\Framework\HTTP\Client\Curl::class)
            ->willReturn($curlMock);

        $reflection = new \ReflectionClass(Quotes::class);
        $method = $reflection->getMethod('getPaystandAccessToken');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller);
        $this->assertEquals('test_token_123', $result);
    }

    public function testFetchPaystandResourceSuccessfulForPayment()
    {
        $mockResponse = (object)[
            'id' => 'pay_123',
            'status' => 'paid',
            'amount' => 100.00
        ];

        $this->scopeConfig
            ->method('getValue')
            ->willReturnMap([
                [Quotes::CUSTOMER_ID, Quotes::STORE_SCOPE, null, 'customer_123'],
                [Quotes::USE_SANDBOX, Quotes::STORE_SCOPE, null, true]
            ]);

        $curlMock = $this->getMockBuilder(\Magento\Framework\HTTP\Client\Curl::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $curlMock->method('getBody')->willReturn(json_encode($mockResponse));

        $this->objectManager
            ->method('create')
            ->with(\Magento\Framework\HTTP\Client\Curl::class)
            ->willReturn($curlMock);

        $reflection = new \ReflectionClass(Quotes::class);
        $method = $reflection->getMethod('fetchPaystandResource');
        $method->setAccessible(true);

        $result = $method->invokeArgs($this->controller, ['test_token', 'Payment', 'pay_123']);
        $this->assertEquals('paid', $result->status);
        $this->assertEquals('pay_123', $result->id);
    }

    public function testFindOrderByQuoteSuccessful()
    {
        $quoteMock = $this->getMockBuilder(\Magento\Quote\Model\Quote::class)
            ->disableOriginalConstructor()
            ->getMock();
        $quoteMock->method('getId')->willReturn(123);

        $orderMock = $this->getMockBuilder(\Magento\Sales\Model\Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderMock->method('getIncrementId')->willReturn('ORD_123');

        $collectionMock = $this->getMockBuilder(\Magento\Sales\Model\ResourceModel\Order\Collection::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $collectionMock->method('addFieldToFilter')->willReturnSelf();
        $collectionMock->method('setOrder')->willReturnSelf();
        $collectionMock->method('setPageSize')->willReturnSelf();
        $collectionMock->method('getSize')->willReturn(1);
        $collectionMock->method('getFirstItem')->willReturn($orderMock);

        $this->objectManager
            ->method('create')
            ->with(\Magento\Sales\Model\ResourceModel\Order\Collection::class)
            ->willReturn($collectionMock);

        $reflection = new \ReflectionClass(Quotes::class);
        $method = $reflection->getMethod('findOrderByQuote');
        $method->setAccessible(true);

        $result = $method->invokeArgs($this->controller, [$quoteMock]);
        $this->assertSame($orderMock, $result);
    }

    public function testProcessPaymentStatusPaid()
    {
        $orderMock = $this->getMockBuilder(\Magento\Sales\Model\Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $orderMock->method('getState')->willReturn('processing');
        $orderMock->method('getIncrementId')->willReturn('ORD_123');
        $orderMock->expects($this->once())->method('setState')->with(\Magento\Sales\Model\Order::STATE_COMPLETE);
        $orderMock->expects($this->once())->method('setStatus')->with(\Magento\Sales\Model\Order::STATE_COMPLETE);
        $orderMock->expects($this->once())->method('addStatusHistoryComment');
        $orderMock->expects($this->once())->method('save');

        $paystandResource = (object)['status' => 'paid'];

        $reflection = new \ReflectionClass(Quotes::class);
        $method = $reflection->getMethod('processPaymentStatus');
        $method->setAccessible(true);

        $result = $method->invokeArgs($this->controller, [$orderMock, 'paid', $paystandResource]);
        $this->assertContains('moved_to_complete', $result);
    }

    public function testProcessPaymentStatusPosted()
    {
        $orderMock = $this->getMockBuilder(\Magento\Sales\Model\Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $orderMock->method('getState')->willReturn('pending');
        $orderMock->method('getIncrementId')->willReturn('ORD_123');
        $orderMock->expects($this->once())->method('setState')->with(\Magento\Sales\Model\Order::STATE_PROCESSING);
        $orderMock->expects($this->once())->method('setStatus')->with(\Magento\Sales\Model\Order::STATE_PROCESSING);
        $orderMock->expects($this->once())->method('addStatusHistoryComment');
        $orderMock->expects($this->once())->method('save');

        $paystandResource = (object)['status' => 'posted'];

        $reflection = new \ReflectionClass(Quotes::class);
        $method = $reflection->getMethod('processPaymentStatus');
        $method->setAccessible(true);

        $result = $method->invokeArgs($this->controller, [$orderMock, 'posted', $paystandResource]);
        $this->assertContains('moved_to_processing', $result);
    }

    public function testProcessRefundStatusPaid()
    {
        $orderMock = $this->getMockBuilder(\Magento\Sales\Model\Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderMock->method('getIncrementId')->willReturn('ORD_123');

        $creditmemoMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Creditmemo::class)
            ->disableOriginalConstructor()
            ->getMock();
        $creditmemoMock->method('getIncrementId')->willReturn('CM_123');

        $paystandResource = (object)['status' => 'paid', 'amount' => 50.00];

        // Mock the createCreditMemo method to return success
        $partialMock = $this->getMockBuilder(Quotes::class)
            ->setConstructorArgs([
                $this->context,
                $this->jsonFactory,
                $this->request,
                $this->logger,
                $this->quoteFactory,
                $this->cartRepository,
                $this->scopeConfig,
                $this->orderRepository,
                $this->creditmemoService,
                $this->creditmemoFactory,
                $this->objectManager
            ])
            ->onlyMethods(['createCreditMemo'])
            ->getMock();

        $partialMock->method('createCreditMemo')->willReturn($creditmemoMock);

        $reflection = new \ReflectionClass(Quotes::class);
        $method = $reflection->getMethod('processRefundStatus');
        $method->setAccessible(true);

        $result = $method->invokeArgs($partialMock, [$orderMock, 'paid', $paystandResource]);
        $this->assertContains('credit_memo_created', $result);
    }

    public function testCreateCreditMemoSuccessful()
    {
        $orderMock = $this->getMockBuilder(\Magento\Sales\Model\Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderMock->method('canCreditmemo')->willReturn(true);
        $orderMock->method('getIncrementId')->willReturn('ORD_123');

        $creditmemoMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Creditmemo::class)
            ->disableOriginalConstructor()
            ->getMock();
        $creditmemoMock->method('getIncrementId')->willReturn('CM_123');
        $creditmemoMock->expects($this->once())->method('setAdjustmentPositive')->with(50.00);
        $creditmemoMock->expects($this->once())->method('setGrandTotal')->with(50.00);
        $creditmemoMock->expects($this->once())->method('setBaseGrandTotal')->with(50.00);
        $creditmemoMock->expects($this->once())->method('addComment');

        $this->creditmemoFactory
            ->expects($this->once())
            ->method('createByOrder')
            ->with($orderMock)
            ->willReturn($creditmemoMock);

        $this->creditmemoService
            ->expects($this->once())
            ->method('refund')
            ->with($creditmemoMock);

        $reflection = new \ReflectionClass(Quotes::class);
        $method = $reflection->getMethod('createCreditMemo');
        $method->setAccessible(true);

        $result = $method->invokeArgs($this->controller, [$orderMock, 50.00, 'Test refund']);
        $this->assertSame($creditmemoMock, $result);
    }

    public function testGetPaystandBaseUrlReturnsSandbox()
    {
        $this->scopeConfig
            ->method('getValue')
            ->with(Quotes::USE_SANDBOX, Quotes::STORE_SCOPE)
            ->willReturn(true);

        $reflection = new \ReflectionClass(Quotes::class);
        $method = $reflection->getMethod('getPaystandBaseUrl');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller);
        $this->assertEquals(Quotes::SANDBOX_BASE_URL, $result);
    }

    public function testGetPaystandBaseUrlReturnsProduction()
    {
        $this->scopeConfig
            ->method('getValue')
            ->with(Quotes::USE_SANDBOX, Quotes::STORE_SCOPE)
            ->willReturn(false);

        $reflection = new \ReflectionClass(Quotes::class);
        $method = $reflection->getMethod('getPaystandBaseUrl');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller);
        $this->assertEquals(Quotes::BASE_URL, $result);
    }
}
