<?php
namespace PayStand\PayStandMagento\Block\Adminhtml\Invoice\Totals;

use Magento\Framework\View\Element\Template;

class PaystandAdjustment extends Template
{
    public function initTotals()
    {
        $totalsBlock = $this->getParentBlock();
        if (!$totalsBlock) {
            return $this;
        }

        $invoice = $totalsBlock->getInvoice();
        if (!$invoice) {
            return $this;
        }

        // Get paystand_adjustment from invoice or order
        $amount = (float)$invoice->getData('paystand_adjustment');
        if ($amount === 0.0) {
            $order = $invoice->getOrder();
            if ($order) {
                $amount = (float)$order->getData('paystand_adjustment');
            }
        }

        if ($amount === 0.0) {
            return $this;
        }

        $label = $amount < 0 ? __('Paystand Discount') : __('Paystand Adjustment');

        $total = new \Magento\Framework\DataObject([
            'code'  => 'paystand_adjustment',
            'value' => $amount,
            'label' => $label,
        ]);

        $totalsBlock->addTotal($total, 'shipping');
        return $this;
    }
}
