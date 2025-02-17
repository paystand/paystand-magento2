<?php
declare(strict_types=1);

/**
 * PayStand Magento 2 Module - Structural Changes Validation
 * This script validates only the changes we've made without touching payment logic
 */

class StructuralValidator
{
    private $results = [];
    private $magentoRoot;

    public function __construct()
    {
        $this->magentoRoot = dirname(__DIR__);
    }

    public function validate(): bool
    {
        $this->validateDirectoryStructure();
        $this->validateClassLoading();
        $this->validateInterfaces();
        $this->validateTypeDeclarations();
        
        return $this->displayResults();
    }

    private function validateDirectoryStructure(): void
    {
        // Check new PSR-4 compliant structure
        $paths = [
            'Controller/Webhook/PayStand.php' => 'New webhook controller location',
            'Model/Compatibility/Version.php' => 'Version compatibility class',
            'Model/Compatibility/VersionInterface.php' => 'Version interface',
            'Model/Compatibility/TypeCompatibilityTrait.php' => 'Type compatibility trait'
        ];

        foreach ($paths as $path => $description) {
            $fullPath = $this->magentoRoot . '/magento-2/' . $path;
            $exists = file_exists($fullPath);
            $this->addResult(
                "File Structure: {$description}",
                $exists,
                $exists ? "Found at correct location" : "Missing file"
            );
        }
    }

    private function validateClassLoading(): void
    {
        $classes = [
            'PayStand\PayStandMagento\Controller\Webhook\PayStand' => 'Webhook Controller',
            'PayStand\PayStandMagento\Model\Compatibility\Version' => 'Version Class',
            'PayStand\PayStandMagento\Model\Compatibility\VersionInterface' => 'Version Interface',
        ];

        foreach ($classes as $class => $description) {
            $exists = class_exists($class) || interface_exists($class);
            $this->addResult(
                "Class Loading: {$description}",
                $exists,
                $exists ? "Class/Interface loadable" : "Not loadable"
            );
        }
    }

    private function validateInterfaces(): void
    {
        // Check if webhook controller implements required interfaces
        $controllerClass = 'PayStand\PayStandMagento\Controller\Webhook\PayStand';
        if (class_exists($controllerClass)) {
            $implements = class_implements($controllerClass);
            $hasHttpPost = isset($implements['Magento\Framework\App\Action\HttpPostActionInterface']);
            
            $this->addResult(
                "Interface Implementation: HttpPostActionInterface",
                $hasHttpPost,
                $hasHttpPost ? "Properly implemented" : "Missing implementation"
            );
        }

        // Verify Version class implements VersionInterface
        $versionClass = 'PayStand\PayStandMagento\Model\Compatibility\Version';
        if (class_exists($versionClass)) {
            $implements = class_implements($versionClass);
            $hasInterface = isset($implements['PayStand\PayStandMagento\Model\Compatibility\VersionInterface']);
            
            $this->addResult(
                "Interface Implementation: VersionInterface",
                $hasInterface,
                $hasInterface ? "Properly implemented" : "Missing implementation"
            );
        }
    }

    private function validateTypeDeclarations(): void
    {
        // Use reflection to check type declarations
        $classesToCheck = [
            'PayStand\PayStandMagento\Controller\Webhook\PayStand' => [
                'execute' => 'Return type declaration'
            ],
            'PayStand\PayStandMagento\Model\Compatibility\Version' => [
                'isMagentoVersion' => 'Method type declaration',
                'isPhpVersion' => 'Method type declaration',
                'getMagentoVersion' => 'Method type declaration'
            ]
        ];

        foreach ($classesToCheck as $class => $methods) {
            if (class_exists($class)) {
                $reflection = new ReflectionClass($class);
                foreach ($methods as $method => $description) {
                    try {
                        $methodReflection = $reflection->getMethod($method);
                        $hasReturnType = $methodReflection->hasReturnType();
                        $this->addResult(
                            "Type Declaration: {$class}::{$method}",
                            $hasReturnType,
                            $hasReturnType ? "Has return type" : "Missing return type"
                        );
                    } catch (ReflectionException $e) {
                        $this->addResult(
                            "Type Declaration: {$class}::{$method}",
                            false,
                            "Method not found"
                        );
                    }
                }
            }
        }
    }

    private function addResult(string $check, bool $result, string $message = ''): void
    {
        $this->results[] = [
            'check' => $check,
            'result' => $result,
            'message' => $message
        ];
    }

    private function displayResults(): bool
    {
        echo "\nPayStand Structural Changes Validation\n";
        echo str_repeat('=', 50) . "\n\n";

        $failures = 0;
        foreach ($this->results as $result) {
            $status = $result['result'] ? '✅ PASS' : '❌ FAIL';
            if (!$result['result']) {
                $failures++;
            }
            echo sprintf(
                "%s: %s %s\n",
                $status,
                $result['check'],
                $result['message'] ? "({$result['message']})" : ''
            );
        }

        echo "\nSummary:\n";
        echo "--------\n";
        echo "Total Checks: " . count($this->results) . "\n";
        echo "Passed: " . (count($this->results) - $failures) . "\n";
        echo "Failed: " . $failures . "\n";
        echo "Status: " . ($failures === 0 ? "✅ All Structural Changes Valid" : "❌ Some Changes Need Attention") . "\n";

        return $failures === 0;
    }
}

// Run validation
$validator = new StructuralValidator();
$success = $validator->validate();

// Exit with appropriate code
exit($success ? 0 : 1); 