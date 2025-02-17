<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Model\Compatibility;

/**
 * Interface for version compatibility checks
 */
interface VersionInterface
{
    /**
     * Check if current Magento version is compatible with a given version
     *
     * @param string $version Version to check against (e.g., '2.4.7')
     * @return bool
     */
    public function isMagentoVersion(string $version): bool;

    /**
     * Check if current PHP version is compatible with a given version
     *
     * @param string $version Version to check against (e.g., '8.3')
     * @return bool
     */
    public function isPhpVersion(string $version): bool;

    /**
     * Get current Magento version
     *
     * @return string
     */
    public function getMagentoVersion(): string;

    /**
     * Check if HttpPostActionInterface exists
     *
     * @return bool
     */
    public function hasHttpPostActionInterface(): bool;
} 