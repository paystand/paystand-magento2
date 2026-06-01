<?php

namespace PayStand\PayStandMagento\Test\Unit\Observer;

use PayStand\PayStandMagento\Observer\AfterOrderPlaceObserver;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class AfterOrderPlaceObserverTest extends TestCase
{
    /** @var AfterOrderPlaceObserver */
    protected $observer;

    /** @var LoggerInterface|MockObject */
    protected $loggerMock;

    /** @var ScopeConfigInterface|MockObject */
    protected $scopeConfigMock;

    /** @var CartRepositoryInterface|MockObject */
    protected $cartRepositoryMock;

    protected function setUp(): void
    {
        $this->loggerMock = $this->getMockBuilder(LoggerInterface::class)
            ->getMockForAbstractClass();

        $this->scopeConfigMock = $this->getMockBuilder(ScopeConfigInterface::class)
            ->getMockForAbstractClass();

        $this->cartRepositoryMock = $this->getMockBuilder(CartRepositoryInterface::class)
            ->getMockForAbstractClass();

        $this->observer = new AfterOrderPlaceObserver(
            $this->loggerMock,
            $this->scopeConfigMock,
            $this->getMockBuilder(BuilderInterface::class)->getMockForAbstractClass(),
            $this->getMockBuilder(InvoiceService::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(TransactionFactory::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(InvoiceRepositoryInterface::class)->getMockForAbstractClass(),
            $this->getMockBuilder(CollectionFactory::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(QuoteFactory::class)->disableOriginalConstructor()->getMock(),
            $this->cartRepositoryMock,
            $this->getMockBuilder(OrderRepositoryInterface::class)->getMockForAbstractClass()
        );
    }

    public function testExecuteRunsWithoutExceptionForNonPaystandOrder(): void
    {
        $paymentMock = $this->getMockBuilder(Order\Payment::class)
            ->disableOriginalConstructor()
            ->getMock();
        $paymentMock->method('getMethod')->willReturn('other_method');

        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderMock->method('getQuoteId')->willReturn(null);
        $orderMock->method('getPayment')->willReturn($paymentMock);

        $eventMock = $this->getMockBuilder(Event::class)
            ->disableOriginalConstructor()
            ->addMethods(['getOrder'])
            ->getMock();
        $eventMock->method('getOrder')->willReturn($orderMock);

        $observerMock = $this->getMockBuilder(Observer::class)
            ->disableOriginalConstructor()
            ->getMock();
        $observerMock->method('getEvent')->willReturn($eventMock);

        $this->observer->execute($observerMock);
        $this->addToAssertionCount(1);
    }
}
