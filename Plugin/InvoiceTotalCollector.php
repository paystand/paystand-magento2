<?php
namespace PayStand\PayStandMagento\Plugin;

use Magento\Sales\Model\Order\Invoice\Total\Subtotal;
use Magento\Sales\Model\Order\Invoice;

class InvoiceTotalCollector
{
    /**
     * Add Paystand adjustment to invoice totals during collection
     *
     * @param Subtotal $subject
     * @param Subtotal $result
     * @param Invoice $invoice
     * @return Subtotal
     */
    public function afterCollect(Subtotal $subject, Subtotal $result, Invoice $invoice)
    {
        $order = $invoice->getOrder();
        
        // Get paystand_adjustment from order
        $paystandAdjustment = (float)$order->getData('paystand_adjustment');
        
        if ($paystandAdjustment === 0.0) {
            return $result;
        }
        
        // Only add if not already set on invoice (to avoid double-adding)
        if ($invoice->getData('paystand_adjustment') === null) {
            // Set the adjustment on the invoice
            $invoice->setData('paystand_adjustment', $paystandAdjustment);
            
            // Add to invoice totals
            $currentGrandTotal = (float)$invoice->getGrandTotal();
            $currentBaseGrandTotal = (float)$invoice->getBaseGrandTotal();
            
            $invoice->setGrandTotal($currentGrandTotal + $paystandAdjustment);
            $invoice->setBaseGrandTotal($currentBaseGrandTotal + $paystandAdjustment);
        }
        
        return $result;
    }
}
