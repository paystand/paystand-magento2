<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Test\Unit\Model\Compatibility;

use PayStand\PayStandMagento\Model\Compatibility\TypeCompatibilityTrait;
use PHPUnit\Framework\TestCase;

class TypeCompatibilityTraitTest extends TestCase
{
    private $traitObject;

    protected function setUp(): void
    {
        $this->traitObject = new class {
            use TypeCompatibilityTrait;

            public function publicGetCompatibleReturnType($value, string $type)
            {
                return $this->getCompatibleReturnType($value, $type);
            }

            public function publicCastParameter($value, string $type)
            {
                return $this->castParameter($value, $type);
            }

            public function publicGetCompatibleInterface(string $interface)
            {
                return $this->getCompatibleInterface($interface);
            }

            public function publicHandleNullable($value, string $type)
            {
                return $this->handleNullable($value, $type);
            }
        };
    }

    /**
     * @dataProvider typeConversionProvider
     */
    public function testGetCompatibleReturnType($input, string $type, $expected): void
    {
        $result = $this->traitObject->publicGetCompatibleReturnType($input, $type);
        if (version_compare(PHP_VERSION, '8.3', '>=')) {
            $this->assertEquals($expected, $result);
        } else {
            $this->assertEquals($input, $result);
        }
    }

    /**
     * @dataProvider typeConversionProvider
     */
    public function testCastParameter($input, string $type, $expected): void
    {
        $result = $this->traitObject->publicCastParameter($input, $type);
        if (version_compare(PHP_VERSION, '8.3', '>=')) {
            $this->assertEquals($expected, $result);
        } else {
            $this->assertEquals($input, $result);
        }
    }

    public function testGetCompatibleInterface(): void
    {
        // Test with existing interface
        $result = $this->traitObject->publicGetCompatibleInterface(\JsonSerializable::class);
        $this->assertEquals(\JsonSerializable::class, $result);

        // Test with non-existing interface
        $result = $this->traitObject->publicGetCompatibleInterface('NonExistentInterface');
        $this->assertNull($result);
    }

    /**
     * @dataProvider nullableTypeProvider
     */
    public function testHandleNullable($input, string $type, $expected): void
    {
        $result = $this->traitObject->publicHandleNullable($input, $type);
        if ($input === null) {
            $this->assertNull($result);
        } elseif (version_compare(PHP_VERSION, '8.3', '>=')) {
            $this->assertEquals($expected, $result);
        } else {
            $this->assertEquals($input, $result);
        }
    }

    /**
     * @return array
     */
    public function typeConversionProvider(): array
    {
        return [
            ['123', 'integer', 123],
            [123, 'string', '123'],
            ['true', 'boolean', true],
            ['1.23', 'float', 1.23]
        ];
    }

    /**
     * @return array
     */
    public function nullableTypeProvider(): array
    {
        return [
            [null, 'string', null],
            ['123', 'integer', 123],
            [123, 'string', '123'],
            [null, 'integer', null]
        ];
    }
} 