<?php
namespace PayStand\PayStandMagento\Block\Adminhtml\Order\Totals;

use Magento\Framework\View\Element\Template;
use Magento\Sales\Block\Adminhtml\Order\Totals as ParentTotals;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class PaystandAdjustment extends Template
{
    /** @var ParentTotals */
    protected $parentBlock;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @param Template\Context $context
     * @param ScopeConfigInterface $scopeConfig
     * @param array $data
     */
    public function __construct(
        Template\Context $context,
        ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Check if paystand adjustment is enabled
     *
     * @return bool
     */
    protected function isPaystandAdjustmentEnabled()
    {
        return $this->scopeConfig->isSetFlag(
            'payment/paystandmagento/enable_paystand_adjustment',
            ScopeInterface::SCOPE_STORE
        );
    }

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

        $amount = (float)$order->getData('paystand_adjustment');
        
        // If there's no existing adjustment, only proceed if feature is enabled
        if ($amount === 0.0) {
            if (!$this->isPaystandAdjustmentEnabled()) {
                return $this;
            }
            // If enabled but amount is 0, don't add anything
            return $this;
        }
        // If there's an existing adjustment (!=0), always show it regardless of config
        
        // Remove Total Paid and Total Due rows when showing paystand adjustment
        if (method_exists($totals, 'removeTotal')) {
            // Cover both naming variants used across Magento blocks
            $totals->removeTotal('paid');
            $totals->removeTotal('due');
            $totals->removeTotal('total_paid');
            $totals->removeTotal('total_due');
        }

        $label = $amount < 0
            ? __('Paystand Discount')
            : __('Paystand Adjustment');

        $total = new \Magento\Framework\DataObject([
            'code'  => 'paystand_adjustment',
            'value' => $amount,
            'label' => $label,
        ]);

        // Insert without positioning to avoid foreach on null totals during early layout
        $totals->addTotal($total);
        return $this;
    }
}
