<?php

namespace PayStand\PayStandMagento\Test\Unit\Model;

use Magento\Framework\Simplexml\Element;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use PayStand\PayStandMagento\Model\Directpost;
use PayStand\PayStandMagento\Model\TransactionService;
use PayStand\PayStandMagento\Model\Request;
use PayStand\PayStandMagento\Model\Directpost\Request\Factory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\Transaction\Repository as TransactionRepository;

/**
 * Class DirectpostTest
 * Unit test for Direct post (Order creation)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DirectpostTest extends \PHPUnit_Framework_TestCase
{
    const TOTAL_AMOUNT = 100.02;
    const INVOICE_NUM = '00000001';
    const TRANSACTION_ID = '41a23x34fd124';

    /**
     * @var \PayStand\PayStandMagento\Model\Directpost
     */
    protected $directpost;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $scopeConfigMock;

    /**
     * @var \Magento\Payment\Model\InfoInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $paymentMock;

    /**
     * @var \PayStand\PayStandMagento\Helper\Data|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $dataHelperMock;

    /**
     * @var TransactionRepository|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $transactionRepositoryMock;

    /**
     * @var TransactionService|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $transactionServiceMock;

    /**
     * @var \Magento\Framework\HTTP\ZendClient|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $httpClientMock;

    protected function setUp()
    {
        $this->scopeConfigMock = $this->getMockBuilder('Magento\Framework\App\Config\ScopeConfigInterface')
            ->getMock();
        $this->paymentMock = $this->getMockBuilder('Magento\Sales\Model\Order\Payment')
            ->disableOriginalConstructor()
            ->setMethods([
                'getOrder', 'getId', 'setAdditionalInformation', 'getAdditionalInformation',
                'setIsTransactionDenied', 'setIsTransactionClosed', 'decrypt', 'getCcLast4',
                'getParentTransactionId', 'getPoNumber'
            ])
            ->getMock();
        $this->dataHelperMock = $this->getMockBuilder('PayStand\PayStandMagento\Helper\Data')
            ->disableOriginalConstructor()
            ->getMock();

        $this->initResponseFactoryMock();

        $this->transactionRepositoryMock = $this->getMockBuilder(
            'Magento\Sales\Model\Order\Payment\Transaction\Repository'
        )
            ->disableOriginalConstructor()
            ->setMethods(['getByTransactionId'])
            ->getMock();

        $httpClientFactoryMock = $this->getHttpClientFactoryMock();

        $helper = new ObjectManagerHelper($this);
        $this->directpost = $helper->getObject(
            'PayStand\PayStandMagento\Model\Directpost',
            [
                'scopeConfig' => $this->scopeConfigMock,
                'dataHelper' => $this->dataHelperMock,
                'transactionRepository' => $this->transactionRepositoryMock,
                'httpClientFactory' => $httpClientFactoryMock
            ]
        );
    }

    public function testGetConfigInterface()
    {
        $this->assertInstanceOf(
            'Magento\Payment\Model\Method\ConfigInterface',
            $this->directpost->getConfigInterface()
        );
    }

    public function testGetConfigValue()
    {
        $field = 'some_field';
        $returnValue = 'expected';
        $this->scopeConfigMock->expects($this->once())
            ->method('getValue')
            ->with('payment/paymentmethod_directpost/' . $field)
            ->willReturn($returnValue);
        $this->assertEquals($returnValue, $this->directpost->getValue($field));
    }

    public function testSetDataHelper()
    {
        $storeId = 'store-id';
        $expectedResult = 'relay-url';

        $helperDataMock = $this->getMockBuilder('PayStand\PayStandMagento\Helper\Backend\Data')
            ->disableOriginalConstructor()
            ->getMock();

        $helperDataMock->expects($this->once())
            ->method('getRelayUrl')
            ->with($storeId)
            ->willReturn($expectedResult);

        $this->directpost->setDataHelper($helperDataMock);
        $this->assertEquals($expectedResult, $this->directpost->getRelayUrl($storeId));
    }

    public function testAuthorize()
    {
        $paymentAction = 'some_action';

        $this->scopeConfigMock->expects($this->any())
            ->method('getValue')
            ->with('payment/paymentmethod_directpost/payment_action', 'store', null)
            ->willReturn($paymentAction);
        $this->paymentMock->expects($this->once())
            ->method('setAdditionalInformation')
            ->with('payment_type', $paymentAction);

        $this->directpost->authorize($this->paymentMock, 10);
    }

    public function testCheckTransIdSuccess()
    {
        $this->responseMock->expects($this->once())
            ->method('getXTransId')
            ->willReturn('111');

        $this->assertEquals(true, $this->directpost->checkTransId());
    }

    /**
     * @expectedException \Magento\Framework\Exception\LocalizedException
     */
    public function testCheckTransIdFailure()
    {
        $this->responseMock->expects($this->once())
            ->method('getXTransId')
            ->willReturn(null);

        $this->directpost->checkTransId();
    }

    /**
     * @param bool $isInitializeNeeded
     *
     * @dataProvider setIsInitializeNeededDataProvider
     */
    public function testSetIsInitializeNeeded($isInitializeNeeded)
    {
        $this->directpost->setIsInitializeNeeded($isInitializeNeeded);
        $this->assertEquals($isInitializeNeeded, $this->directpost->isInitializeNeeded());
    }

    /**
     * @return array
     */
    public function setIsInitializeNeededDataProvider()
    {
        return [
            ['isInitializationNeeded' => true],
            ['isInitializationNeeded' => false]
        ];
    }

    /**
     * @param bool $isGatewayActionsLocked
     * @param bool $canCapture
     *
     * @dataProvider canCaptureDataProvider
     */
    public function testCanCapture($isGatewayActionsLocked, $canCapture)
    {
        $this->directpost->setData('info_instance', $this->paymentMock);

        $this->paymentMock->expects($this->any())
            ->method('getAdditionalInformation')
            ->with(Directpost::GATEWAY_ACTIONS_LOCKED_STATE_KEY)
            ->willReturn($isGatewayActionsLocked);

        $this->assertEquals($canCapture, $this->directpost->canCapture());
    }

    /**
     * @return array
     */
    public function canCaptureDataProvider()
    {
        return [
            ['isGatewayActionsLocked' => false, 'canCapture' => true],
            ['isGatewayActionsLocked' => true, 'canCapture' => false]
        ];
    }

    /**
     * @covers       \PayStand\PayStandMagento\Model\Directpost::fetchTransactionInfo
     *
     * @param $transactionId
     * @param $resultStatus
     * @param $responseStatus
     * @param $responseCode
     * @return void
     *
     * @dataProvider dataProviderTransaction
     */
    public function testFetchVoidedTransactionInfo($transactionId, $resultStatus, $responseStatus, $responseCode)
    {
        $paymentId = 36;
        $orderId = 36;

        $this->paymentMock->expects(static::once())
            ->method('getId')
            ->willReturn($paymentId);

        $orderMock = $this->getMockBuilder('Magento\Sales\Model\Order')
            ->disableOriginalConstructor()
            ->setMethods(['getId', '__wakeup'])
            ->getMock();
        $orderMock->expects(static::once())
            ->method('getId')
            ->willReturn($orderId);

        $this->paymentMock->expects(static::once())
            ->method('getOrder')
            ->willReturn($orderMock);

        $transactionMock = $this->getMockBuilder('Magento\Sales\Model\Order\Payment\Transaction')
            ->disableOriginalConstructor()
            ->getMock();
        $this->transactionRepositoryMock->expects(static::once())
            ->method('getByTransactionId')
            ->with($transactionId, $paymentId, $orderId)
            ->willReturn($transactionMock);

        $document = $this->getTransactionXmlDocument(
            $transactionId,
            TransactionService::PAYMENT_UPDATE_STATUS_CODE_SUCCESS,
            $resultStatus,
            $responseStatus,
            $responseCode
        );
        $this->transactionServiceMock->expects(static::once())
            ->method('getTransactionDetails')
            ->with($this->directpost, $transactionId)
            ->willReturn($document);

        // transaction should be closed
        $this->paymentMock->expects(static::once())
            ->method('setIsTransactionDenied')
            ->with(true);
        $this->paymentMock->expects(static::once())
            ->method('setIsTransactionClosed')
            ->with(true);
        $transactionMock->expects(static::once())
            ->method('close');

        $this->directpost->fetchTransactionInfo($this->paymentMock, $transactionId);
    }

    /**
     * Get data for tests
     * @return array
     */
    public function dataProviderTransaction()
    {
        return [
            [
                'transactionId' => '9941997799',
                'resultStatus' => 'Successful.',
                'responseStatus' => 'voided',
                'responseCode' => 1
            ]
        ];
    }

    /**
     * Get transaction data
     * @param $transactionId
     * @param $resultCode
     * @param $resultStatus
     * @param $responseStatus
     * @param $responseCode
     * @return Element
     */
    private function getTransactionXmlDocument(
        $transactionId,
        $resultCode,
        $resultStatus,
        $responseStatus,
        $responseCode
    ) {
        $body = sprintf(
            '<?xml version="1.0" encoding="utf-8"?>
            <getTransactionDetailsResponse
                    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                    xmlns="AnetApi/xml/v1/schema/AnetApiSchema.xsd">
                <messages>
                    <resultCode>%s</resultCode>
                    <message>
                        <code>I00001</code>
                        <text>%s</text>
                    </message>
                </messages>
                <transaction>
                    <transId>%s</transId>
                    <transactionType>authOnlyTransaction</transactionType>
                    <transactionStatus>%s</transactionStatus>
                    <responseCode>%s</responseCode>
                    <responseReasonCode>%s</responseReasonCode>
                </transaction>
            </getTransactionDetailsResponse>',
            $resultCode,
            $resultStatus,
            $transactionId,
            $responseStatus,
            $responseCode,
            $responseCode
        );
        libxml_use_internal_errors(true);
        $document = new Element($body);
        libxml_use_internal_errors(false);
        return $document;
    }

    /**
     * Get mock for order
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    private function getOrderMock()
    {
        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getId', 'getIncrementId', 'getStoreId', 'getBillingAddress', 'getShippingAddress',
                'getBaseCurrencyCode', 'getBaseTaxAmount', '__wakeup'
            ])
            ->getMock();

        $orderMock->expects(static::once())
            ->method('getId')
            ->willReturn(1);

        $orderMock->expects(static::exactly(2))
            ->method('getIncrementId')
            ->willReturn(self::INVOICE_NUM);

        $orderMock->expects(static::once())
            ->method('getStoreId')
            ->willReturn(1);

        $orderMock->expects(static::once())
            ->method('getBaseCurrencyCode')
            ->willReturn('USD');
        return $orderMock;
    }

    /**
     * Create and return mock for http client factory
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    private function getHttpClientFactoryMock()
    {
        $this->httpClientMock = $this->getMockBuilder(\Magento\Framework\HTTP\ZendClient::class)
            ->disableOriginalConstructor()
            ->setMethods(['request', 'getBody', '__wakeup'])
            ->getMock();

        $this->httpClientMock->expects(static::any())
            ->method('request')
            ->willReturnSelf();

        $httpClientFactoryMock = $this->getMockBuilder(\Magento\Framework\HTTP\ZendClientFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        $httpClientFactoryMock->expects(static::any())
            ->method('create')
            ->willReturn($this->httpClientMock);
        return $httpClientFactoryMock;
    }
}
