<?php

namespace PayStand\PayStandMagento\Test\Unit\Controller\Checkout;

use PayStand\PayStandMagento\Controller\Checkout\QuotePaymentStatus;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

/**
 * Unit tests for Controller\Checkout\QuotePaymentStatus::execute().
 *
 * The re-charge guard endpoint checked before the Paystand widget opens.
 * Constructor is bypassed and collaborators are injected via reflection.
 */
class QuotePaymentStatusTest extends TestCase
{
    /** @var QuotePaymentStatus|MockObject */
    private $controller;

    /** @var HttpRequest|MockObject */
    private $requestMock;

    /** @var JsonResult|MockObject */
    private $jsonResultMock;

    /** @var CartRepositoryInterface|MockObject */
    private $cartRepositoryMock;

    /** @var OrderCollectionFactory|MockObject */
    private $orderCollectionFactoryMock;

    /** @var array|null */
    private $captured;

    protected function setUp(): void
    {
        $this->captured = null;

        $this->requestMock = $this->getMockBuilder(HttpRequest::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->jsonResultMock = $this->getMockBuilder(JsonResult::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->jsonResultMock->method('setHttpResponseCode')->willReturnSelf();
        $this->jsonResultMock->method('setData')->willReturnCallback(function ($data) {
            $this->captured = $data;
            return $this->jsonResultMock;
        });

        $jsonResultFactoryMock = $this->getMockBuilder(JsonFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $jsonResultFactoryMock->method('create')->willReturn($this->jsonResultMock);

        $this->cartRepositoryMock = $this->getMockBuilder(CartRepositoryInterface::class)
            ->getMockForAbstractClass();

        $this->orderCollectionFactoryMock = $this->getMockBuilder(OrderCollectionFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $loggerMock = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $quoteIdMaskFactoryMock = $this->getMockBuilder(QuoteIdMaskFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->controller = $this->getMockBuilder(QuotePaymentStatus::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $this->set('logger',                 $loggerMock);
        $this->set('resultJsonFactory',      $jsonResultFactoryMock);
        $this->set('cartRepository',         $this->cartRepositoryMock);
        $this->set('quoteIdMaskFactory',     $quoteIdMaskFactoryMock);
        $this->set('orderCollectionFactory', $this->orderCollectionFactoryMock);
        $this->set('_request',               $this->requestMock);
    }

    public function testFailsOpenWhenQuoteIdMissing(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn(null);

        $this->controller->execute();

        $this->assertTrue($this->captured['success']);
        $this->assertFalse($this->captured['alreadyPaid']);
    }

    public function testAlreadyPaidWhenQuoteHasRecordedPaymentId(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn('4189563');

        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $quoteMock->method('getData')->with('paystand_payment_id')->willReturn('lrawr60sjqaklyui6a84tcvh');
        $this->cartRepositoryMock->method('get')->willReturn($quoteMock);

        // No order yet for this quote — the paid-but-no-order case.
        $this->stubCollection(0, null);

        $this->controller->execute();

        $this->assertTrue($this->captured['alreadyPaid']);
        $this->assertSame('lrawr60sjqaklyui6a84tcvh', $this->captured['paymentId']);
        $this->assertFalse($this->captured['orderExists']);
    }

    public function testAlreadyPaidWhenOrderExistsEvenWithoutRecordedPaymentId(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn('4189563');

        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $quoteMock->method('getData')->with('paystand_payment_id')->willReturn(null);
        $this->cartRepositoryMock->method('get')->willReturn($quoteMock);

        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderMock->method('getId')->willReturn(7);
        $orderMock->method('getIncrementId')->willReturn('W001369548');
        $this->stubCollection(1, $orderMock);

        $this->controller->execute();

        $this->assertTrue($this->captured['alreadyPaid']);
        $this->assertTrue($this->captured['orderExists']);
        $this->assertSame('W001369548', $this->captured['incrementId']);
    }

    public function testNotPaidWhenNoPaymentAndNoOrder(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn('4189563');

        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $quoteMock->method('getData')->with('paystand_payment_id')->willReturn(null);
        $this->cartRepositoryMock->method('get')->willReturn($quoteMock);

        $this->stubCollection(0, null);

        $this->controller->execute();

        $this->assertTrue($this->captured['success']);
        $this->assertFalse($this->captured['alreadyPaid']);
        $this->assertNull($this->captured['paymentId']);
        $this->assertFalse($this->captured['orderExists']);
    }

    /**
     * @param int $size
     * @param Order|MockObject|null $firstItem
     */
    private function stubCollection(int $size, $firstItem): void
    {
        $collectionMock = $this->getMockBuilder(OrderCollection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $collectionMock->method('addFieldToFilter')->willReturnSelf();
        $collectionMock->method('setOrder')->willReturnSelf();
        $collectionMock->method('setPageSize')->willReturnSelf();
        $collectionMock->method('getSize')->willReturn($size);
        if ($firstItem !== null) {
            $collectionMock->method('getFirstItem')->willReturn($firstItem);
        }
        $this->orderCollectionFactoryMock->method('create')->willReturn($collectionMock);
    }

    /**
     * @param string $name
     * @param mixed $value
     */
    private function set(string $name, $value): void
    {
        $class = new \ReflectionClass($this->controller);
        while ($class) {
            try {
                $prop = $class->getProperty($name);
                $prop->setAccessible(true);
                $prop->setValue($this->controller, $value);
                return;
            } catch (\ReflectionException $e) {
                $class = $class->getParentClass() ?: null;
            }
        }
        throw new \RuntimeException("Property '{$name}' not found on " . get_class($this->controller));
    }
}
