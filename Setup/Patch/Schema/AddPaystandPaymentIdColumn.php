<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Setup\Patch\Schema;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Psr\Log\LoggerInterface;

/**
 * Schema Patch to add the `paystand_payment_id` column to the `quote` table.
 *
 * Records the Paystand payment id of the posted charge captured for a quote, so
 * the checkout can refuse to open the widget again for a cart that has already
 * been paid — preventing a duplicate charge when placeOrder failed to convert
 * the paid quote into an order.
 */
class AddPaystandPaymentIdColumn implements SchemaPatchInterface
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
        $prefix = 'PAYSTANDPAYMENTIDCOLUMN ';
        $setup = $this->schemaSetup;

        $this->logger->info($prefix . 'Starting AddPaystandPaymentIdColumn schema patch');

        $setup->startSetup();
        $connection = $setup->getConnection();

        $definition = [
            'type'     => Table::TYPE_TEXT,
            'length'   => 64,
            'nullable' => true,
            'default'  => null,
            'comment'  => 'PayStand Payment ID (posted charge captured for this quote)',
        ];

        $table = $setup->getTable('quote');
        $this->logger->info($prefix . "Processing table alias='quote', resolved='{$table}'");

        try {
            $exists = $connection->tableColumnExists($table, 'paystand_payment_id');
            $this->logger->info($prefix . 'Column exists? ' . ($exists ? 'yes' : 'no'));

            if (!$exists) {
                $connection->addColumn($table, 'paystand_payment_id', $definition);
                $this->logger->info($prefix . 'Column paystand_payment_id added successfully');
            } else {
                $this->logger->info($prefix . 'Skipping addColumn: paystand_payment_id already present');
            }
        } catch (\Throwable $e) {
            $this->logger->error($prefix . 'Error while processing table ' . $table . ': ' . $e->getMessage());
            throw $e; // fail loudly in setup:upgrade
        }

        $setup->endSetup();
        $this->logger->info($prefix . 'Finished AddPaystandPaymentIdColumn schema patch');
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
