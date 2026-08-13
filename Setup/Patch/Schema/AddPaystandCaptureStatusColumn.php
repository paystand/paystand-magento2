<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Setup\Patch\Schema;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Psr\Log\LoggerInterface;

/**
 * Schema Patch to add the `paystand_capture_status` column to the `quote` table.
 *
 * Records the Paystand status of a confirmed capture. Totals are frozen only for a
 * quote carrying one, so a payment that never completed leaves the cart free to
 * recollect. Deliberately separate from `paystand_payment_id`, which is broader —
 * it locks the widget against any reported payment, confirmed or not.
 */
class AddPaystandCaptureStatusColumn implements SchemaPatchInterface
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
        $prefix = 'PAYSTANDCAPTURESTATUSCOLUMN ';
        $setup = $this->schemaSetup;

        $this->logger->info($prefix . 'Starting AddPaystandCaptureStatusColumn schema patch');

        $setup->startSetup();
        $connection = $setup->getConnection();

        $definition = [
            'type'     => Table::TYPE_TEXT,
            'length'   => 32,
            'nullable' => true,
            'default'  => null,
            'comment'  => 'PayStand capture status (set only for a confirmed capture)',
        ];

        $table = $setup->getTable('quote');
        $this->logger->info($prefix . "Processing table alias='quote', resolved='{$table}'");

        try {
            $exists = $connection->tableColumnExists($table, 'paystand_capture_status');
            $this->logger->info($prefix . 'Column exists? ' . ($exists ? 'yes' : 'no'));

            if (!$exists) {
                $connection->addColumn($table, 'paystand_capture_status', $definition);
                $this->logger->info($prefix . 'Column paystand_capture_status added successfully');
            } else {
                $this->logger->info($prefix . 'Skipping addColumn: paystand_capture_status already present');
            }
        } catch (\Throwable $e) {
            $this->logger->error($prefix . 'Error while processing table ' . $table . ': ' . $e->getMessage());
            throw $e; // fail loudly in setup:upgrade
        }

        $setup->endSetup();
        $this->logger->info($prefix . 'Finished AddPaystandCaptureStatusColumn schema patch');
    }

    /** @return array<string> */
    public static function getDependencies(): array
    {
        return [AddPaystandPaymentIdColumn::class];
    }

    /** @return array<string> */
    public function getAliases(): array
    {
        return [];
    }
}
