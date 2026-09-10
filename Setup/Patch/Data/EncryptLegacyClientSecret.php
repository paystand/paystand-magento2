<?php

declare(strict_types=1);

namespace PayStand\PayStandMagento\Setup\Patch\Data;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use PayStand\PayStandMagento\Model\Config\Backend\ClientSecret;

/**
 * Encrypt every inherited/default, website, and store client-secret row that
 * predates the encrypted backend model.
 */
class EncryptLegacyClientSecret implements DataPatchInterface
{
    private const CONFIG_PATH = 'payment/paystandmagento/client_secret';

    /** @var ModuleDataSetupInterface */
    private $moduleDataSetup;

    /** @var EncryptorInterface */
    private $encryptor;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EncryptorInterface $encryptor
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->encryptor = $encryptor;
    }

    /**
     * @return $this
     */
    public function apply()
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');

        $this->moduleDataSetup->startSetup();
        try {
            $select = $connection->select()
                ->from($table, ['config_id', 'value'])
                ->where('path = ?', self::CONFIG_PATH);

            foreach ($connection->fetchAll($select) as $row) {
                $value = (string)($row['value'] ?? '');
                if ($value === '' || ClientSecret::isEncryptedValue($value)) {
                    continue;
                }

                $connection->update(
                    $table,
                    ['value' => $this->encryptor->encrypt($value)],
                    ['config_id = ?' => (int)$row['config_id']]
                );
            }
        } finally {
            $this->moduleDataSetup->endSetup();
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
