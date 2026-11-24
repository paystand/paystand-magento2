<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Setup\Patch\Schema;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class AddPaystandPreauthColumns implements SchemaPatchInterface
{
    /**
     * @var SchemaSetupInterface
     */
    private $schemaSetup;

    public function __construct(SchemaSetupInterface $schemaSetup)
    {
        $this->schemaSetup = $schemaSetup;
    }

    public function apply()
    {
        $this->schemaSetup->startSetup();
        $connection = $this->schemaSetup->getConnection();
        foreach (['quote', 'sales_order'] as $tableName) {
            $table = $this->schemaSetup->getTable($tableName);
            $this->addText($connection, $table, 'paystand_preauth_id', 'Paystand pre-authorization id');
            $this->addText($connection, $table, 'paystand_preauth_status', 'Paystand pre-authorization status');
            $this->addDecimal($connection, $table, 'paystand_preauth_amount', 'Paystand pre-authorization amount');
            $this->addDecimal($connection, $table, 'paystand_preauth_amount_captured', 'Paystand amount captured');
            $this->addDecimal($connection, $table, 'paystand_preauth_amount_remaining', 'Paystand amount remaining');
            $this->addText($connection, $table, 'paystand_preauth_currency', 'Paystand pre-authorization currency');
            $this->addDatetime($connection, $table, 'paystand_preauth_expires_at', 'Paystand pre-authorization expiry');
        }
        $this->schemaSetup->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddPaystandCaptureSnapshotColumn::class];
    }

    public function getAliases(): array
    {
        return [];
    }

    private function addText($connection, string $table, string $column, string $comment): void
    {
        if ($connection->tableColumnExists($table, $column)) {
            return;
        }
        $connection->addColumn($table, $column, [
            'type' => Table::TYPE_TEXT,
            'length' => 255,
            'nullable' => true,
            'comment' => $comment
        ]);
    }

    private function addDecimal($connection, string $table, string $column, string $comment): void
    {
        if ($connection->tableColumnExists($table, $column)) {
            return;
        }
        $connection->addColumn($table, $column, [
            'type' => Table::TYPE_DECIMAL,
            'length' => '12,4',
            'nullable' => true,
            'comment' => $comment
        ]);
    }

    private function addDatetime($connection, string $table, string $column, string $comment): void
    {
        if ($connection->tableColumnExists($table, $column)) {
            return;
        }
        $connection->addColumn($table, $column, [
            'type' => Table::TYPE_DATETIME,
            'nullable' => true,
            'comment' => $comment
        ]);
    }
}
