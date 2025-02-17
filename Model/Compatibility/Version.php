<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Model\Compatibility;

use Magento\Framework\App\ProductMetadataInterface;

/**
 * Version compatibility implementation
 */
class Version implements VersionInterface
{
    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * @param ProductMetadataInterface $productMetadata
     */
    public function __construct(
        ProductMetadataInterface $productMetadata
    ) {
        $this->productMetadata = $productMetadata;
    }

    /**
     * @inheritDoc
     */
    public function isMagentoVersion(string $version): bool
    {
        return version_compare($this->getMagentoVersion(), $version, '>=');
    }

    /**
     * @inheritDoc
     */
    public function isPhpVersion(string $version): bool
    {
        return version_compare(PHP_VERSION, $version, '>=');
    }

    /**
     * @inheritDoc
     */
    public function getMagentoVersion(): string
    {
        return $this->productMetadata->getVersion();
    }

    /**
     * @inheritDoc
     */
    public function hasHttpPostActionInterface(): bool
    {
        return interface_exists('\Magento\Framework\App\Action\HttpPostActionInterface');
    }
} 