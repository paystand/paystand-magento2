<?php
namespace PayStand\PayStandMagento\Block\Adminhtml\Invoice\Totals;

use Magento\Framework\View\Element\Template;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class PaystandAdjustment extends Template
{
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

        // If there's no existing adjustment, only proceed if feature is enabled
        if ($amount === 0.0) {
            if (!$this->isPaystandAdjustmentEnabled()) {
                return $this;
            }
            // If enabled but amount is 0, don't add anything
            return $this;
        }
        // If there's an existing adjustment (!=0), always show it regardless of config

        $label = $amount < 0 ? __('Paystand Discount') : __('Paystand Adjustment');

        $total = new \Magento\Framework\DataObject([
            'code'  => 'paystand_adjustment',
            'value' => $amount,
            'label' => $label,
        ]);

        $totalsBlock->addTotal($total, 'grand_total');
        return $this;
    }
}
