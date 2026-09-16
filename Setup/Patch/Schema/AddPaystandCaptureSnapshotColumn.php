<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Setup\Patch\Schema;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Psr\Log\LoggerInterface;

/**
 * Schema Patch to add the `paystand_capture_snapshot` column to the `quote` table.
 *
 * JSON of the cart Paystand captured: fingerprint hash, paid grand total, and
 * the shipping method + rate row. Collect-then-pin uses this after Magento
 * reloads the quote on placeOrder.
 */
class AddPaystandCaptureSnapshotColumn implements SchemaPatchInterface
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
        $prefix = 'PAYSTANDCAPTURESNAPSHOTCOLUMN ';
        $setup = $this->schemaSetup;

        $this->logger->info($prefix . 'Starting AddPaystandCaptureSnapshotColumn schema patch');

        $setup->startSetup();
        $connection = $setup->getConnection();

        $definition = [
            'type'     => Table::TYPE_TEXT,
            'length'   => 65535,
            'nullable' => true,
            'default'  => null,
            'comment'  => 'PayStand capture snapshot (hash, paid totals, shipping)',
        ];

        $table = $setup->getTable('quote');
        $this->logger->info($prefix . "Processing table alias='quote', resolved='{$table}'");

        try {
            $exists = $connection->tableColumnExists($table, 'paystand_capture_snapshot');
            $this->logger->info($prefix . 'Column exists? ' . ($exists ? 'yes' : 'no'));

            if (!$exists) {
                $connection->addColumn($table, 'paystand_capture_snapshot', $definition);
                $this->logger->info($prefix . 'Column paystand_capture_snapshot added successfully');
            } else {
                $this->logger->info($prefix . 'Skipping addColumn: paystand_capture_snapshot already present');
            }
        } catch (\Throwable $e) {
            $this->logger->error($prefix . 'Error while processing table ' . $table . ': ' . $e->getMessage());
            throw $e;
        }

        $setup->endSetup();
        $this->logger->info($prefix . 'Finished AddPaystandCaptureSnapshotColumn schema patch');
    }

    /** @return array<string> */
    public static function getDependencies(): array
    {
        return [AddPaystandCaptureStatusColumn::class];
    }

    /** @return array<string> */
    public function getAliases(): array
    {
        return [];
    }
}
