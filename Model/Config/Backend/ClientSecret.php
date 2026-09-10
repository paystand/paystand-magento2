<?php

declare(strict_types=1);

namespace PayStand\PayStandMagento\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\Encrypted;

/**
 * Encrypts the OAuth client secret while remaining readable during the 3.7.2
 * plaintext-to-ciphertext setup transition.
 */
class ClientSecret extends Encrypted
{
    /**
     * Magento encryption formats carry numeric key/cipher versions followed by
     * base64 ciphertext. Four-part values are retained for older Magento keys.
     */
    public static function isEncryptedValue(string $value): bool
    {
        return preg_match(
            '/^(?:\d+:)?\d+:(?:[^:]*:)?[A-Za-z0-9+\/]+={0,2}$/D',
            $value
        ) === 1;
    }

    /**
     * A 3.7.2 database can still contain plaintext when the new metadata first
     * loads. Return it unchanged until the data patch encrypts every scoped row.
     *
     * @param mixed $value
     * @return string
     */
    public function processValue($value)
    {
        $value = (string)$value;

        return self::isEncryptedValue($value) ? parent::processValue($value) : $value;
    }

    /**
     * Avoid attempting to decrypt a legacy plaintext value in the admin form
     * before the migration patch has run.
     *
     * @return void
     */
    protected function _afterLoad()
    {
        if (self::isEncryptedValue((string)$this->getValue())) {
            parent::_afterLoad();
        }
    }
}
