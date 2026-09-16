<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Setup\Patch\Schema;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Psr\Log\LoggerInterface;

/**
 * Schema Patch to add the `paystand_capture_cart_hash` column to the `quote` table.
 *
 * Fingerprints the cart a capture was taken on. Totals stay frozen only while the
 * quote still matches its fingerprint, so a cart the shopper changed after paying
 * collects totals again and can price its shipping.
 */
class AddPaystandCaptureCartHashColumn implements SchemaPatchInterface
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
        $prefix = 'PAYSTANDCAPTURECARTHASHCOLUMN ';
        $setup = $this->schemaSetup;

        $this->logger->info($prefix . 'Starting AddPaystandCaptureCartHashColumn schema patch');

        $setup->startSetup();
        $connection = $setup->getConnection();

        $definition = [
            'type'     => Table::TYPE_TEXT,
            'length'   => 64,
            'nullable' => true,
            'default'  => null,
            'comment'  => 'PayStand capture cart fingerprint (sha256 of the cart that was paid)',
        ];

        $table = $setup->getTable('quote');
        $this->logger->info($prefix . "Processing table alias='quote', resolved='{$table}'");

        try {
            $exists = $connection->tableColumnExists($table, 'paystand_capture_cart_hash');
            $this->logger->info($prefix . 'Column exists? ' . ($exists ? 'yes' : 'no'));

            if (!$exists) {
                $connection->addColumn($table, 'paystand_capture_cart_hash', $definition);
                $this->logger->info($prefix . 'Column paystand_capture_cart_hash added successfully');
            } else {
                $this->logger->info($prefix . 'Skipping addColumn: paystand_capture_cart_hash already present');
            }
        } catch (\Throwable $e) {
            $this->logger->error($prefix . 'Error while processing table ' . $table . ': ' . $e->getMessage());
            throw $e; // fail loudly in setup:upgrade
        }

        $setup->endSetup();
        $this->logger->info($prefix . 'Finished AddPaystandCaptureCartHashColumn schema patch');
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
