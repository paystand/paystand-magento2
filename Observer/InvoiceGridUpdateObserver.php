<?php
/**
 * PayStand Invoice Grid Update Observer
 */
namespace PayStand\PayStandMagento\Observer;

use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\ResourceConnection;

class InvoiceGridUpdateObserver implements ObserverInterface
{
    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * @var ResourceConnection
     */
    protected $_resourceConnection;

    /**
     * @param LoggerInterface $logger
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        LoggerInterface $logger,
        ResourceConnection $resourceConnection
    ) {
        $this->_logger = $logger;
        $this->_resourceConnection = $resourceConnection;
    }

    /**
     * Update sales_invoice_grid table after invoice is saved and committed
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order\Invoice $invoice */
        $invoice = $observer->getEvent()->getInvoice();
        
        if (!$invoice || !$invoice->getId()) {
            return;
        }

        $paystandAdjustment = (float)$invoice->getData('paystand_adjustment');
        
        // Only process if there's a paystand adjustment
        if ($paystandAdjustment !== 0.0) {
            try {
                $connection = $this->_resourceConnection->getConnection();
                $invoiceGridTable = $this->_resourceConnection->getTableName('sales_invoice_grid');
                
                $this->_logger->debug(">>>>> PAYSTAND-INVOICE-GRID-OBSERVER: Attempting to update sales_invoice_grid for invoice ID " . $invoice->getId() . " with paystand_adjustment={$paystandAdjustment}");
                
                // Force update the grid table directly
                $updateResult = $connection->update(
                    $invoiceGridTable,
                    ['paystand_adjustment' => $paystandAdjustment],
                    ['entity_id = ?' => $invoice->getId()]
                );
                
                $this->_logger->debug(">>>>> PAYSTAND-INVOICE-GRID-OBSERVER: Update completed. Rows affected: {$updateResult} for invoice ID " . $invoice->getId());
                
                // Verify the update worked
                $verifySelect = $connection->select()
                    ->from($invoiceGridTable, ['paystand_adjustment'])
                    ->where('entity_id = ?', $invoice->getId());
                $verifiedValue = $connection->fetchOne($verifySelect);
                $this->_logger->debug(">>>>> PAYSTAND-INVOICE-GRID-OBSERVER: Verification - paystand_adjustment is now: {$verifiedValue}");
                
            } catch (\Exception $e) {
                $this->_logger->error(">>>>> PAYSTAND-INVOICE-GRID-OBSERVER: Error updating sales_invoice_grid: " . $e->getMessage());
                $this->_logger->error(">>>>> PAYSTAND-INVOICE-GRID-OBSERVER: Stack trace: " . $e->getTraceAsString());
            }
        } else {
            $this->_logger->debug(">>>>> PAYSTAND-INVOICE-GRID-OBSERVER: No paystand_adjustment found for invoice ID " . $invoice->getId());
        }
    }
}