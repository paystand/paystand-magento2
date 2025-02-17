<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Model\Compatibility;

/**
 * Trait for type compatibility across PHP versions
 */
trait TypeCompatibilityTrait
{
    /**
     * Get return type based on PHP version
     *
     * @param mixed $value The value to be returned
     * @param string $type The intended return type for PHP 8.3
     * @return mixed
     */
    protected function getCompatibleReturnType($value, string $type)
    {
        if (version_compare(PHP_VERSION, '8.3', '>=')) {
            settype($value, $type);
        }
        return $value;
    }

    /**
     * Cast parameter based on PHP version
     *
     * @param mixed $value The value to be cast
     * @param string $type The intended parameter type
     * @return mixed
     */
    protected function castParameter($value, string $type)
    {
        if (version_compare(PHP_VERSION, '8.3', '>=')) {
            settype($value, $type);
        }
        return $value;
    }

    /**
     * Get compatible interface name based on Magento version
     *
     * @param string $interface The interface class name
     * @return string|null
     */
    protected function getCompatibleInterface(string $interface): ?string
    {
        if (interface_exists($interface)) {
            return $interface;
        }
        return null;
    }

    /**
     * Handle nullable types compatibility
     *
     * @param mixed $value The value to check
     * @param string $type The intended type
     * @return mixed|null
     */
    protected function handleNullable($value, string $type)
    {
        if ($value === null) {
            return null;
        }
        return $this->castParameter($value, $type);
    }
} 