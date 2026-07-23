<?php

namespace PayStand\PayStandMagento\Test\Unit\Controller\Checkout;

use PayStand\PayStandMagento\Controller\Checkout\OrderStatus;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Quote\Model\QuoteIdMask;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

/**
 * Unit tests for Controller\Checkout\OrderStatus::execute().
 *
 * The endpoint the frontend polls to confirm placeOrder actually produced an
 * order for the paid quote. Constructor is bypassed and collaborators are
 * injected via reflection, so no real DB access occurs.
 */
class OrderStatusTest extends TestCase
{
    /** @var OrderStatus|MockObject */
    private $controller;

    /** @var HttpRequest|MockObject */
    private $requestMock;

    /** @var JsonResult|MockObject */
    private $jsonResultMock;

    /** @var OrderCollectionFactory|MockObject */
    private $orderCollectionFactoryMock;

    /** @var array|null Last payload passed to the JSON result's setData(). */
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

        $this->orderCollectionFactoryMock = $this->getMockBuilder(OrderCollectionFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $loggerMock = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();
        $cartRepositoryMock = $this->getMockBuilder(CartRepositoryInterface::class)->getMockForAbstractClass();
        $quoteIdMaskFactoryMock = $this->getMockBuilder(QuoteIdMaskFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->controller = $this->getMockBuilder(OrderStatus::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $this->set('logger',                 $loggerMock);
        $this->set('resultJsonFactory',      $jsonResultFactoryMock);
        $this->set('cartRepository',         $cartRepositoryMock);
        $this->set('quoteIdMaskFactory',     $quoteIdMaskFactoryMock);
        $this->set('orderCollectionFactory', $this->orderCollectionFactoryMock);
        $this->set('_request',               $this->requestMock);
    }

    public function testReturnsErrorWhenQuoteIdMissing(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn(null);

        $this->controller->execute();

        $this->assertIsArray($this->captured);
        $this->assertFalse($this->captured['success']);
        $this->assertSame('Missing quote id', $this->captured['error']);
    }

    public function testReturnsOrderExistsTrueWhenOrderFoundForNumericQuote(): void
    {
        // Numeric quote id resolves directly (no masked-id lookup).
        $this->requestMock->method('getParam')->with('quote')->willReturn('4189563');

        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderMock->method('getId')->willReturn(42);
        $orderMock->method('getIncrementId')->willReturn('W001369548');

        $this->stubCollection(1, $orderMock);

        $this->controller->execute();

        $this->assertIsArray($this->captured);
        $this->assertTrue($this->captured['success']);
        $this->assertTrue($this->captured['orderExists']);
        $this->assertSame('W001369548', $this->captured['incrementId']);
    }

    public function testReturnsOrderExistsFalseWhenNoOrderForQuote(): void
    {
        $this->requestMock->method('getParam')->with('quote')->willReturn('4189563');

        $this->stubCollection(0, null);

        $this->controller->execute();

        $this->assertIsArray($this->captured);
        $this->assertTrue($this->captured['success']);
        $this->assertFalse($this->captured['orderExists']);
        $this->assertNull($this->captured['incrementId']);
    }

    /**
     * Wire the order collection factory to return a collection reporting $size
     * rows, whose first item is $firstItem.
     *
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
     * Set a (possibly inherited, possibly private) property on the controller.
     *
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
