<?php

namespace PayStand\PayStandMagento\Setup;

use Magento\Framework\Setup\UpgradeDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Sales\Setup\SalesSetupFactory;

class UpgradeData implements UpgradeDataInterface
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
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $salesSetup = $this->salesSetupFactory->create(['setup' => $setup]);

        // Add paystand_payment_id attribute to order for version 3.5.0 and above
        if (version_compare($context->getVersion(), '3.5.0', '<')) {
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
} 