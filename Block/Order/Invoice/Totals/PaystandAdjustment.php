<?php
namespace PayStand\PayStandMagento\Block\Order\Invoice\Totals;

use Magento\Framework\View\Element\Template;
use Magento\Sales\Block\Order\Invoice\Totals as ParentTotals;

class PaystandAdjustment extends Template
{
    /** @var ParentTotals */
    protected $parentBlock;

    public function getParentBlock()
    {
        return parent::getParentBlock();
    }

    public function initTotals()
    {
        /** @var ParentTotals $totals */
        $totals = $this->getParentBlock();
        if (!$totals) {
            return $this;
        }

        $invoice = $totals->getInvoice();
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

        $label = $amount < 0
            ? __('Paystand Discount')
            : __('Paystand Adjustment');

        $total = new \Magento\Framework\DataObject([
            'code'  => 'paystand_adjustment',
            'value' => $amount,
            'label' => $label,
        ]);

        $totals->addTotal($total, 'shipping');
        return $this;
    }
}
