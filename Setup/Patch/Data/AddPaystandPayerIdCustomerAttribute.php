<?php

namespace PayStand\PayStandMagento\Setup\Patch\Data;

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Psr\Log\LoggerInterface;

class AddPaystandPayerIdCustomerAttribute implements DataPatchInterface
{
    private $moduleDataSetup;
    private $customerSetupFactory;
    private $logger;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        CustomerSetupFactory $customerSetupFactory,
        LoggerInterface $logger
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->customerSetupFactory = $customerSetupFactory;
        $this->logger = $logger;
    }

    public function apply()
    {
        $this->moduleDataSetup->startSetup();

        $this->logger->info("Adding paystand_payer_id customer attribute");

        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);
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
