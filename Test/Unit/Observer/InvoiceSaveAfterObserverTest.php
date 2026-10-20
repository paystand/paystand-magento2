<?php

namespace PayStand\PayStandMagento\Test\Unit\Observer;

use PayStand\PayStandMagento\Observer\InvoiceSaveAfterObserver;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;

/**
 * Tests for Observer\InvoiceSaveAfterObserver, which adds paystand_adjustment to an invoice
 * made outside the automatic flow. An invoice that already has it is skipped, since
 * register() has already added its total to total_invoiced and total_paid.
 */
class InvoiceSaveAfterObserverTest extends TestCase
{
    /** @var InvoiceSaveAfterObserver */
    private $observer;

    protected function setUp(): void
    {
        $this->observer = new InvoiceSaveAfterObserver(
            $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass(),
            $this->getMockBuilder(ScopeConfigInterface::class)->getMockForAbstractClass()
        );
    }

    /**
     * The automatic path sets paystand_adjustment before register(), so the fee is already
     * in total_invoiced and total_paid.
     */
    public function testAlreadyStampedInvoiceIsLeftAlone(): void
    {
        $order   = $this->buildOrder(8.73);
        $invoice = $this->buildInvoice($order, 8.73);

        $invoice->expects($this->never())->method('setGrandTotal');
        $invoice->expects($this->never())->method('setBaseGrandTotal');
        $order->expects($this->never())->method('setTotalInvoiced');
        $order->expects($this->never())->method('setTotalPaid');

        $this->observer->execute($this->buildObserver($invoice));
    }

    /**
     * A manual admin invoice gets the adjustment, on the invoice and the order's paid totals.
     */
    public function testUnstampedInvoiceStillReceivesTheAdjustment(): void
    {
        $order   = $this->buildOrder(8.73);
        $invoice = $this->buildInvoice($order, null);

        $invoice->expects($this->once())->method('setGrandTotal')->with(161.69 + 8.73);
        $order->expects($this->once())->method('setTotalInvoiced')->with(161.69 + 8.73);
        $order->expects($this->once())->method('setTotalPaid')->with(161.69 + 8.73);

        $this->observer->execute($this->buildObserver($invoice));
    }

    /**
     * No fee on the order means nothing to carry, whatever the invoice looks like.
     */
    public function testZeroAdjustmentTouchesNothing(): void
    {
        $order   = $this->buildOrder(0.0);
        $invoice = $this->buildInvoice($order, null);

        $invoice->expects($this->never())->method('setGrandTotal');
        $order->expects($this->never())->method('setTotalInvoiced');

        $this->observer->execute($this->buildObserver($invoice));
    }

    public function testMissingInvoiceIsIgnored(): void
    {
        $this->observer->execute($this->buildObserver(null));

        $this->assertTrue(true, 'A missing invoice must not throw');
    }

    /** @return Order|MockObject */
    private function buildOrder(float $adjustment)
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getData', 'getTotalInvoiced', 'getBaseTotalInvoiced', 'getTotalPaid',
                'getBaseTotalPaid', 'setTotalInvoiced', 'setBaseTotalInvoiced',
                'setTotalPaid', 'setBaseTotalPaid', 'getPayment',
            ])
            ->getMock();

        $order->method('getData')->willReturnCallback(
            function ($key = '', $index = null) use ($adjustment) {
                return $key === 'paystand_adjustment' ? $adjustment : null;
            }
        );
        $order->method('getTotalInvoiced')->willReturn(161.69);
        $order->method('getBaseTotalInvoiced')->willReturn(161.69);
        $order->method('getTotalPaid')->willReturn(161.69);
        $order->method('getBaseTotalPaid')->willReturn(161.69);

        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAmountPaid', 'getBaseAmountPaid', 'setAmountPaid', 'setBaseAmountPaid', 'save'])
            ->getMock();
        $payment->method('getAmountPaid')->willReturn(161.69);
        $payment->method('getBaseAmountPaid')->willReturn(161.69);
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }

    /**
     * @param Order|MockObject $order
     * @param float|null $stampedAdjustment what the invoice already carries
     * @return Invoice|MockObject
     */
    private function buildInvoice($order, $stampedAdjustment)
    {
        $invoice = $this->getMockBuilder(Invoice::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getOrder', 'getData', 'setData', 'getGrandTotal', 'setGrandTotal',
                'getBaseGrandTotal', 'setBaseGrandTotal',
            ])
            ->getMock();

        $invoice->method('getOrder')->willReturn($order);
        $invoice->method('getData')->willReturnCallback(
            function ($key = '', $index = null) use ($stampedAdjustment) {
                return $key === 'paystand_adjustment' ? $stampedAdjustment : null;
            }
        );
        $invoice->method('getGrandTotal')->willReturn(161.69);
        $invoice->method('getBaseGrandTotal')->willReturn(161.69);

        return $invoice;
    }

    /**
     * @param Invoice|MockObject|null $invoice
     * @return Observer|MockObject
     */
    private function buildObserver($invoice)
    {
        $event = $this->getMockBuilder(Event::class)
            ->disableOriginalConstructor()
            ->addMethods(['getInvoice'])
            ->getMock();
        $event->method('getInvoice')->willReturn($invoice);

        $observer = $this->getMockBuilder(Observer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEvent'])
            ->getMock();
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }
}
