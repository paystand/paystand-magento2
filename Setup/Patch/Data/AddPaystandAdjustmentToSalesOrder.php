<?php

namespace PayStand\PayStandMagento\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Sales\Setup\SalesSetupFactory;
use Psr\Log\LoggerInterface;

class AddPaystandAdjustmentToSalesOrder implements DataPatchInterface
{
    private $moduleDataSetup;
    private $salesSetupFactory;
    private $logger;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        SalesSetupFactory $salesSetupFactory,
        LoggerInterface $logger
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->salesSetupFactory = $salesSetupFactory;
        $this->logger = $logger;
    }

    public function apply()
    {
        $this->moduleDataSetup->startSetup();

        $this->logger->info("Adding paystand_adjustment to sales_order");

        $connection = $this->moduleDataSetup->getConnection();
        $orderTable = $this->moduleDataSetup->getTable('sales_order');

        if (!$connection->tableColumnExists($orderTable, 'paystand_adjustment')) {
            $salesSetup = $this->salesSetupFactory->create(['setup' => $this->moduleDataSetup]);
            $salesSetup->addAttribute('order', 'paystand_adjustment', [
                'type'     => 'decimal',
                'visible'  => false,
                'nullable' => true,
                'comment'  => 'PayStand Adjustment'
            ]);

            $this->logger->info("Column paystand_adjustment added to sales_order");
        } else {
            $this->logger->info("Column paystand_adjustment already exists in sales_order");
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases()
    {
        return [];
    }

    public static function getDependencies()
    {
        return [];
    }
}
