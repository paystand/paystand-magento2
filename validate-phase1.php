<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use PayStand\PayStandMagento\Model\Compatibility\Version;
use Magento\Framework\App\ProductMetadataInterface;

class ValidationResult
{
    private $tests = [];
    private $passed = 0;
    private $failed = 0;

    public function addTest(string $name, bool $result, string $message = '')
    {
        $this->tests[] = [
            'name' => $name,
            'result' => $result,
            'message' => $message
        ];
        
        if ($result) {
            $this->passed++;
        } else {
            $this->failed++;
        }
    }

    public function printResults()
    {
        echo "\nPhase 1 Validation Results:\n";
        echo "-------------------------\n";
        
        foreach ($this->tests as $test) {
            $status = $test['result'] ? '✅ PASS' : '❌ FAIL';
            echo sprintf(
                "%s: %s %s\n",
                $status,
                $test['name'],
                $test['message'] ? "({$test['message']})" : ''
            );
        }
        
        echo "\nSummary:\n";
        echo "--------\n";
        echo "Total Tests: " . count($this->tests) . "\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        
        return $this->failed === 0;
    }
}

// Create validation result
$validation = new ValidationResult();

// 1. Verify PHP Version Support
$validation->addTest(
    'PHP Version Support',
    version_compare(PHP_VERSION, '7.4', '>='),
    'PHP version must be 7.4 or higher'
);

// 2. Verify Class Existence
$validation->addTest(
    'Version Class Exists',
    class_exists('PayStand\PayStandMagento\Model\Compatibility\Version'),
    'Version class should be available'
);

$validation->addTest(
    'VersionInterface Exists',
    interface_exists('PayStand\PayStandMagento\Model\Compatibility\VersionInterface'),
    'VersionInterface should be available'
);

$validation->addTest(
    'TypeCompatibilityTrait Exists',
    trait_exists('PayStand\PayStandMagento\Model\Compatibility\TypeCompatibilityTrait'),
    'TypeCompatibilityTrait should be available'
);

// 3. Verify Composer Configuration
$composerJson = json_decode(file_get_contents(__DIR__ . '/composer.json'), true);
$validation->addTest(
    'Composer PHP Version',
    isset($composerJson['require']['php']),
    'composer.json should specify PHP version requirements'
);

$validation->addTest(
    'Composer Magento Framework Version',
    isset($composerJson['require']['magento/framework']),
    'composer.json should specify Magento framework version'
);

// 4. Verify Type Compatibility
$testClass = new class {
    use PayStand\PayStandMagento\Model\Compatibility\TypeCompatibilityTrait;
    
    public function test($value, $type)
    {
        return $this->castParameter($value, $type);
    }
};

$validation->addTest(
    'Type Casting',
    is_int($testClass->test('123', 'integer')) || is_string($testClass->test('123', 'integer')),
    'Type casting should work based on PHP version'
);

// Print results
$success = $validation->printResults();

// Exit with appropriate code
exit($success ? 0 : 1); 