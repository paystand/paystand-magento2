<?php

namespace PayStand\PayStandMagento\Setup;

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Framework\Setup\UpgradeDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Psr\Log\LoggerInterface;

class UpgradeData implements UpgradeDataInterface
{
    /**
     * @var CustomerSetupFactory
     */
    private $customerSetupFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Constructor
     *
     * @param CustomerSetupFactory $customerSetupFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        CustomerSetupFactory $customerSetupFactory,
        LoggerInterface $logger
    ) {
        $this->customerSetupFactory = $customerSetupFactory;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        $currentVersion = $context->getVersion();
        $this->logger->info("PayStand module upgrade - Current version: " . $currentVersion);

        // Check if we're upgrading to version 3.5.4 or higher
        if (version_compare($context->getVersion(), '3.5.4', '<')) {
            $this->logger->info("Upgrading to version 3.5.4 - Adding paystand_payer_id attribute");
            $customerSetup = $this->customerSetupFactory->create(['setup' => $setup]);

            // Check if attribute already exists
            $attributeId = $customerSetup->getAttributeId(Customer::ENTITY, 'paystand_payer_id');
            
            if (!$attributeId) {
                $this->logger->info("Attribute paystand_payer_id does not exist, creating it...");
                // Add PayStand Payer ID attribute
                $customerSetup->addAttribute(Customer::ENTITY, 'paystand_payer_id', [
                    'type' => 'varchar',
                    'label' => 'PayStand Payer ID',
                    'input' => 'text',
                    'required' => false,
                    'visible' => false,
                    'user_defined' => true,
                    'system' => false,
                    'group' => 'General',
                    'global' => true,
                    'visible_on_front' => false,
                ]);

                // Make it available in admin forms if needed
                $attribute = $customerSetup->getEavConfig()->getAttribute(Customer::ENTITY, 'paystand_payer_id');
                $attribute->setData('used_in_forms', [
                    'adminhtml_customer',
                    'customer_account_edit',
                    'customer_account_create'
                ]);
                $attribute->save();
                $this->logger->info("PayStand Payer ID attribute created successfully!");
            } else {
                $this->logger->info("PayStand Payer ID attribute already exists (ID: " . $attributeId . ")");
            }
        } else {
            $this->logger->info("Module version is already 3.5.4 or higher, skipping attribute creation");
        }

        $setup->endSetup();
    }
} 