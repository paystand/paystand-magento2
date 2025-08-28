<?php

namespace PayStand\PayStandMagento\Setup;

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Framework\Setup\UpgradeDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Sales\Setup\SalesSetupFactory;
use Psr\Log\LoggerInterface;

class UpgradeData implements UpgradeDataInterface
{
    private $customerSetupFactory;
    private $salesSetupFactory;
    private $logger;

    public function __construct(
        CustomerSetupFactory $customerSetupFactory,
        LoggerInterface $logger,
        SalesSetupFactory $salesSetupFactory
    ) {
        $this->customerSetupFactory = $customerSetupFactory;
        $this->logger = $logger;
        $this->salesSetupFactory = $salesSetupFactory;
    }

    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        $currentVersion = $context->getVersion();
        $this->logger->info("PayStand module upgrade - Current version: " . $currentVersion);

        // v3.5.4 - Agregar atributo de cliente
        if (version_compare($currentVersion, '3.5.4', '<')) {
            $this->logger->info("Upgrading to version 3.5.4 - Adding paystand_payer_id customer attribute");

            $customerSetup = $this->customerSetupFactory->create(['setup' => $setup]);
            $attributeId = $customerSetup->getAttributeId(Customer::ENTITY, 'paystand_payer_id');

            if (!$attributeId) {
                $this->logger->info("Creating customer attribute: paystand_payer_id");
                $customerSetup->addAttribute(Customer::ENTITY, 'paystand_payer_id', [
                    'type'             => 'varchar',
                    'label'            => 'PayStand Payer ID',
                    'input'            => 'text',
                    'required'         => false,
                    'visible'          => false,
                    'user_defined'     => true,
                    'system'           => false,
                    'group'            => 'General',
                    'global'           => true,
                    'visible_on_front' => false,
                ]);

                $attribute = $customerSetup->getEavConfig()->getAttribute(Customer::ENTITY, 'paystand_payer_id');
                $attribute->setData('used_in_forms', [
                    'adminhtml_customer',
                    'customer_account_edit',
                    'customer_account_create'
                ]);
                $attribute->save();
                $this->logger->info("Customer attribute paystand_payer_id created successfully!");
            } else {
                $this->logger->info("Customer attribute paystand_payer_id already exists (ID: $attributeId)");
            }
        }

        // v3.5.5 - Agregar columna en sales_order
        if (version_compare($currentVersion, '3.5.5', '<')) {
            $this->logger->info("Upgrading to version 3.5.5 - Adding paystand_adjustment to sales_order");

            $connection = $setup->getConnection();
            $orderTable = $setup->getTable('sales_order');

            if (!$connection->tableColumnExists($orderTable, 'paystand_adjustment')) {
                $salesSetup = $this->salesSetupFactory->create(['setup' => $setup]);
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
        }

        // ✅ v3.5.6 - Agregar columna en quote
        if (version_compare($currentVersion, '3.5.6', '<')) {
            $this->logger->info("Upgrading to version 3.5.6 - Adding paystand_adjustment to quote");

            $connection = $setup->getConnection();
            $quoteTable = $setup->getTable('quote');

            if (!$connection->tableColumnExists($quoteTable, 'paystand_adjustment')) {
                $connection->addColumn(
                    $quoteTable,
                    'paystand_adjustment',
                    [
                        'type'     => \Magento\Framework\DB\Ddl\Table::TYPE_DECIMAL,
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
        }

        $setup->endSetup();
    }
}
