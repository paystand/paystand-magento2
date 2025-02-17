<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Test\Unit\Model\Compatibility;

use Magento\Framework\App\ProductMetadataInterface;
use PayStand\PayStandMagento\Model\Compatibility\Version;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class VersionTest extends TestCase
{
    /**
     * @var ProductMetadataInterface|MockObject
     */
    private $productMetadataMock;

    /**
     * @var Version
     */
    private $version;

    protected function setUp(): void
    {
        $this->productMetadataMock = $this->createMock(ProductMetadataInterface::class);
        $this->version = new Version($this->productMetadataMock);
    }

    /**
     * @dataProvider magentoVersionProvider
     */
    public function testIsMagentoVersion(string $currentVersion, string $testVersion, bool $expected): void
    {
        $this->productMetadataMock->expects($this->once())
            ->method('getVersion')
            ->willReturn($currentVersion);

        $result = $this->version->isMagentoVersion($testVersion);
        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider phpVersionProvider
     */
    public function testIsPhpVersion(string $testVersion, bool $expected): void
    {
        $result = $this->version->isPhpVersion($testVersion);
        $this->assertEquals($expected, $result);
    }

    public function testHasHttpPostActionInterface(): void
    {
        $result = $this->version->hasHttpPostActionInterface();
        $this->assertIsBool($result);
    }

    /**
     * @return array
     */
    public function magentoVersionProvider(): array
    {
        return [
            ['2.4.7', '2.4.6', true],
            ['2.4.7', '2.4.7', true],
            ['2.4.7', '2.4.8', false],
            ['2.4.4', '2.4.7', false]
        ];
    }

    /**
     * @return array
     */
    public function phpVersionProvider(): array
    {
        return [
            ['7.4', true],
            ['8.3', version_compare(PHP_VERSION, '8.3', '>=')],
            ['9.0', false]
        ];
    }
} 