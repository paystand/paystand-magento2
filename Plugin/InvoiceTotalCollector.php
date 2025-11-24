<?php
namespace PayStand\PayStandMagento\Plugin;

use Magento\Sales\Model\Order\Invoice\Total\Subtotal;
use Magento\Sales\Model\Order\Invoice;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class InvoiceTotalCollector
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
        
        // If there's no existing adjustment, check if feature is enabled
        if ($paystandAdjustment === 0.0) {
            $isAdjustmentEnabled = $this->scopeConfig->isSetFlag(
                self::ENABLE_PAYSTAND_ADJUSTMENT,
                ScopeInterface::SCOPE_STORE
            );
            
            if (!$isAdjustmentEnabled) {
                return $result;
            }
            // If enabled but amount is 0, don't add anything
            return $result;
        }
        
        // If there's an existing adjustment (!=0), always transfer it to invoice
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
