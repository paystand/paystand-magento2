<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Setup\Patch\Schema;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Psr\Log\LoggerInterface;

/**
 * Schema Patch (V2) to add the `paystand_adjustment` decimal(12,4) column to:
 * `sales_order_payment`, `sales_invoice`, and `sales_invoice_grid` tables.
 *
 * Depends on the initial AddPaystandAdjustmentColumns patch that handled
 * `sales_order` and `quote`.
 */
class AddPaystandAdjustmentColumnsV2 implements SchemaPatchInterface
{
    /** @var SchemaSetupInterface */
    private $schemaSetup;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        SchemaSetupInterface $schemaSetup,
        LoggerInterface $logger
    ) {
        $this->schemaSetup = $schemaSetup;
        $this->logger = $logger;
    }

    public function apply()
    {
        $prefix = 'PAYSTANDTESTCOLUMN ';
        $setup = $this->schemaSetup;

        $this->logger->info($prefix . 'Starting AddPaystandAdjustmentColumnsV2 schema patch');

        $setup->startSetup();
        $this->logger->info($prefix . 'Setup started');

        $connection = $setup->getConnection();
        $this->logger->info($prefix . 'Obtained DB connection');

        $definition = [
            'type'     => Table::TYPE_DECIMAL,
            'length'   => '12,4',
            'nullable' => true,
            'default'  => '0.0000',
            'comment'  => 'PayStand Adjustment',
        ];
        $this->logger->info($prefix . 'Column definition prepared: TYPE=DECIMAL(12,4), NULLABLE=true, DEFAULT=0.0000');

        foreach (['sales_order_payment', 'sales_invoice', 'sales_invoice_grid'] as $baseTable) {
            $table = $setup->getTable($baseTable);
            $this->logger->info($prefix . "Processing table alias='{$baseTable}', resolved='{$table}'");

            try {
                $exists = $connection->tableColumnExists($table, 'paystand_adjustment');
                $this->logger->info($prefix . 'Column exists? ' . ($exists ? 'yes' : 'no'));

                if (!$exists) {
                    $this->logger->info($prefix . 'Adding column paystand_adjustment ...');
                    $connection->addColumn($table, 'paystand_adjustment', $definition);
                    $this->logger->info($prefix . 'Column paystand_adjustment added successfully');
                } else {
                    $this->logger->info($prefix . 'Skipping addColumn: paystand_adjustment already present');
                }
            } catch (\Throwable $e) {
                $this->logger->error($prefix . 'Error while processing table ' . $table . ': ' . $e->getMessage());
                throw $e; // fail loudly in setup:upgrade
            }
        }

        $setup->endSetup();
        $this->logger->info($prefix . 'Setup ended');
        $this->logger->info($prefix . 'Finished AddPaystandAdjustmentColumnsV2 schema patch');
    }

    /** @return array<string> */
    public static function getDependencies(): array
    {
        return [AddPaystandAdjustmentColumns::class];
    }

    /** @return array<string> */
    public function getAliases(): array
    {
        return [];
    }
}


