<?php
/**
 * PayStand Invoice Observer
 */
namespace PayStand\PayStandMagento\Observer;

use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class InvoiceSaveAfterObserver implements ObserverInterface
{
    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * PayStand configuration path
     */
    const ENABLE_PAYSTAND_ADJUSTMENT = 'payment/paystandmagento/enable_paystand_adjustment';

    /**
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->_logger = $logger;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Transfer paystand_adjustment from order to invoice when invoice is created manually
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order\Invoice $invoice */
        $invoice = $observer->getEvent()->getInvoice();
        
        if (!$invoice || !$invoice->getOrder()) {
            return;
        }

        $order = $invoice->getOrder();
        $paystandAdjustment = (float)$order->getData('paystand_adjustment');
        
        // If there's no existing adjustment, check if feature is enabled
        if ($paystandAdjustment === 0.0) {
            $isAdjustmentEnabled = $this->scopeConfig->isSetFlag(
                self::ENABLE_PAYSTAND_ADJUSTMENT,
                ScopeInterface::SCOPE_STORE
            );
            
            if (!$isAdjustmentEnabled) {
                return;
            }
            // If enabled but amount is 0, don't process anything
            return;
        }
        
        // If there's an existing adjustment (!=0), always transfer it to invoice
        // Only process if it's not already set on invoice
        if ($invoice->getData('paystand_adjustment') === null) {
            $this->_logger->debug(">>>>> PAYSTAND-INVOICE-OBSERVER: Processing manual invoice creation with adjustment " . $paystandAdjustment);
            
            // Set the adjustment on the invoice
            $invoice->setData('paystand_adjustment', $paystandAdjustment);
            
            // Update invoice grand totals to include the adjustment
            $currentGrandTotal = (float)$invoice->getGrandTotal();
            $currentBaseGrandTotal = (float)$invoice->getBaseGrandTotal();
            $newGrandTotal = max(0.0, $currentGrandTotal + $paystandAdjustment);
            $newBaseGrandTotal = max(0.0, $currentBaseGrandTotal + $paystandAdjustment);
            
            $invoice->setGrandTotal($newGrandTotal);
            $invoice->setBaseGrandTotal($newBaseGrandTotal);
            
            $this->_logger->debug(">>>>> PAYSTAND-INVOICE-OBSERVER: Updated invoice totals - grand_total from {$currentGrandTotal} to {$newGrandTotal}, base_grand_total from {$currentBaseGrandTotal} to {$newBaseGrandTotal}");
            
            // Update order invoiced totals to include the adjustment
            $currentTotalInvoiced = (float)$order->getTotalInvoiced();
            $currentBaseTotalInvoiced = (float)$order->getBaseTotalInvoiced();
            $currentTotalPaid = (float)$order->getTotalPaid();
            $currentBaseTotalPaid = (float)$order->getBaseTotalPaid();
            
            $newTotalInvoiced = $currentTotalInvoiced + $paystandAdjustment;
            $newBaseTotalInvoiced = $currentBaseTotalInvoiced + $paystandAdjustment;
            $newTotalPaid = $currentTotalPaid + $paystandAdjustment;
            $newBaseTotalPaid = $currentBaseTotalPaid + $paystandAdjustment;
            
            $order->setTotalInvoiced($newTotalInvoiced);
            $order->setBaseTotalInvoiced($newBaseTotalInvoiced);
            $order->setTotalPaid($newTotalPaid);
            $order->setBaseTotalPaid($newBaseTotalPaid);
            
            $this->_logger->debug(">>>>> PAYSTAND-INVOICE-OBSERVER: Updated order totals - total_invoiced from {$currentTotalInvoiced} to {$newTotalInvoiced}, base_total_invoiced from {$currentBaseTotalInvoiced} to {$newBaseTotalInvoiced}");
            $this->_logger->debug(">>>>> PAYSTAND-INVOICE-OBSERVER: Updated order totals - total_paid from {$currentTotalPaid} to {$newTotalPaid}, base_total_paid from {$currentBaseTotalPaid} to {$newBaseTotalPaid}");
            
            // Update payment amounts to include the adjustment
            $payment = $order->getPayment();
            if ($payment) {
                $currentAmountPaid = (float)$payment->getAmountPaid();
                $currentBaseAmountPaid = (float)$payment->getBaseAmountPaid();
                
                $newAmountPaid = $currentAmountPaid + $paystandAdjustment;
                $newBaseAmountPaid = $currentBaseAmountPaid + $paystandAdjustment;
                
                $payment->setAmountPaid($newAmountPaid);
                $payment->setBaseAmountPaid($newBaseAmountPaid);
                $payment->save();
                
                $this->_logger->debug(">>>>> PAYSTAND-INVOICE-OBSERVER: Updated payment amounts - amount_paid from {$currentAmountPaid} to {$newAmountPaid}, base_amount_paid from {$currentBaseAmountPaid} to {$newBaseAmountPaid}");
            }
        }
    }
}
