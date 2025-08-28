<?php
namespace PayStand\PayStandMagento\Block\Adminhtml\Order\Totals;

use Magento\Framework\View\Element\Template;
use Magento\Sales\Block\Adminhtml\Order\Totals as ParentTotals;

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

        $order = $totals->getOrder();
        if (!$order) {
            return $this;
        }

        // Remove Total Paid and Total Due rows from admin order totals
        if (method_exists($totals, 'removeTotal')) {
            // Cover both naming variants used across Magento blocks
            $totals->removeTotal('paid');
            $totals->removeTotal('due');
            $totals->removeTotal('total_paid');
            $totals->removeTotal('total_due');
        }

        $amount = (float)$order->getData('paystand_adjustment');
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

        // Insert before/after an existing row (e.g. before 'grand_total' or after 'shipping')
        $totals->addTotal($total, 'shipping');
        return $this;
    }
}
