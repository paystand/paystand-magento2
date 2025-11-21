<?php
namespace PayStand\PayStandMagento\Model\Sales\Invoice\Total;

use Magento\Sales\Model\Order\Invoice\Total\AbstractTotal;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class PaystandAdjustment extends AbstractTotal
{
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * PayStand configuration path
     */
    const ENABLE_PAYSTAND_ADJUSTMENT = 'payment/paystandmagento/enable_paystand_adjustment';

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param \Magento\Sales\Model\Order\Invoice $invoice
     * @return $this
     */
    public function collect(\Magento\Sales\Model\Order\Invoice $invoice)
    {
        $order = $invoice->getOrder();
        
        // Get paystand_adjustment from order
        $paystandAdjustment = (float)$order->getData('paystand_adjustment');
        
        // If there's no existing adjustment, check if feature is enabled
        if ($paystandAdjustment === 0.0) {
            $isAdjustmentEnabled = $this->scopeConfig->isSetFlag(
                self::ENABLE_PAYSTAND_ADJUSTMENT,
                ScopeInterface::SCOPE_STORE
            );
            
            if (!$isAdjustmentEnabled) {
                return $this;
            }
            // If enabled but amount is 0, don't add anything
            return $this;
        }
        
        // If there's an existing adjustment (!=0), always transfer it to invoice
        // Set the adjustment on the invoice
        $invoice->setData('paystand_adjustment', $paystandAdjustment);
        
        // Add to invoice totals
        $invoice->setGrandTotal($invoice->getGrandTotal() + $paystandAdjustment);
        $invoice->setBaseGrandTotal($invoice->getBaseGrandTotal() + $paystandAdjustment);
        
        return $this;
    }
}
