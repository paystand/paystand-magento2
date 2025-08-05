<?php

namespace PayStand\PayStandMagento\Setup;

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;

class InstallData implements InstallDataInterface
{
    /**
     * @var CustomerSetupFactory
     */
    private $customerSetupFactory;

    /**
     * Constructor
     *
     * @param CustomerSetupFactory $customerSetupFactory
     */
    public function __construct(
        CustomerSetupFactory $customerSetupFactory
    ) {
        $this->customerSetupFactory = $customerSetupFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        $customerSetup = $this->customerSetupFactory->create(['setup' => $setup]);

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

        $setup->endSetup();
    }
} 