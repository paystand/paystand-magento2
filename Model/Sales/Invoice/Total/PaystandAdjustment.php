<?php
namespace PayStand\PayStandMagento\Model\Sales\Invoice\Total;

use Magento\Sales\Model\Order\Invoice\Total\AbstractTotal;

class PaystandAdjustment extends AbstractTotal
{
    /**
     * @param \Magento\Sales\Model\Order\Invoice $invoice
     * @return $this
     */
    public function collect(\Magento\Sales\Model\Order\Invoice $invoice)
    {
        $order = $invoice->getOrder();
        
        // Get paystand_adjustment from order
        $paystandAdjustment = (float)$order->getData('paystand_adjustment');
        
        if ($paystandAdjustment === 0.0) {
            return $this;
        }
        
        // Set the adjustment on the invoice
        $invoice->setData('paystand_adjustment', $paystandAdjustment);
        
        // Add to invoice totals
        $invoice->setGrandTotal($invoice->getGrandTotal() + $paystandAdjustment);
        $invoice->setBaseGrandTotal($invoice->getBaseGrandTotal() + $paystandAdjustment);
        
        return $this;
    }
}
