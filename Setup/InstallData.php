<?php

namespace PayStand\PayStandMagento\Setup;

use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Sales\Setup\SalesSetupFactory;

class InstallData implements InstallDataInterface
{
    /**
     * @var SalesSetupFactory
     */
    private $salesSetupFactory;

    /**
     * @param SalesSetupFactory $salesSetupFactory
     */
    public function __construct(SalesSetupFactory $salesSetupFactory)
    {
        $this->salesSetupFactory = $salesSetupFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $salesSetup = $this->salesSetupFactory->create(['setup' => $setup]);

        // Add paystand_payment_id attribute to order
        $salesSetup->addAttribute(
            'order',
            'paystand_payment_id',
            [
                'type' => 'varchar',
                'length' => 255,
                'visible' => false,
                'required' => false,
                'nullable' => true,
                'default' => null,
                'comment' => 'PayStand Payment ID'
            ]
        );
    }
} 