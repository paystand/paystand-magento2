<?php

namespace PayStand\PayStandMagento\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\DB\Ddl\Table;
use Psr\Log\LoggerInterface;

class AddPaystandAdjustmentToQuote implements DataPatchInterface
{
    private $moduleDataSetup;
    private $logger;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        LoggerInterface $logger
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->logger = $logger;
    }

    public function apply()
    {
        $this->moduleDataSetup->startSetup();

        $this->logger->info("Adding paystand_adjustment to quote");

        $connection = $this->moduleDataSetup->getConnection();
        $quoteTable = $this->moduleDataSetup->getTable('quote');

        if (!$connection->tableColumnExists($quoteTable, 'paystand_adjustment')) {
            $connection->addColumn(
                $quoteTable,
                'paystand_adjustment',
                [
                    'type'     => Table::TYPE_DECIMAL,
                    'nullable' => true,
                    'default'  => '0.0000',
                    'length'   => '12,4',
                    'comment'  => 'PayStand Adjustment',
                ]
            );
            $this->logger->info("Column paystand_adjustment added to quote table successfully");
        } else {
            $this->logger->info("Column paystand_adjustment already exists in quote table");
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases()
    {
        return [];
    }

    public static function getDependencies()
    {
        return [
            AddPaystandAdjustmentToSalesOrder::class
        ];
    }
}
