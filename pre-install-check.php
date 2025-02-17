<?php
declare(strict_types=1);

/**
 * PayStand Magento 2 Module Pre-Installation Check
 */

class PreInstallCheck
{
    private $results = [];
    private $errors = [];
    private $warnings = [];
    private $magentoRoot;

    public function __construct()
    {
        $this->magentoRoot = dirname(__DIR__);
    }

    public function runChecks(): void
    {
        $this->checkPHPVersion();
        $this->checkMagentoVersion();
        $this->checkRequiredExtensions();
        $this->checkFilePermissions();
        $this->checkComposerJson();
        $this->checkConflictingModules();
        $this->checkDatabasePrivileges();
        $this->checkMemoryLimit();
    }

    private function checkPHPVersion(): void
    {
        $requiredVersion = '7.4.0';
        $currentVersion = PHP_VERSION;
        $result = version_compare($currentVersion, $requiredVersion, '>=');
        
        $this->addResult(
            'PHP Version',
            $result,
            "Required: >= {$requiredVersion}, Current: {$currentVersion}"
        );
    }

    private function checkMagentoVersion(): void
    {
        $composerJson = $this->magentoRoot . '/composer.json';
        if (!file_exists($composerJson)) {
            $this->addError('Cannot find Magento composer.json');
            return;
        }

        $json = json_decode(file_get_contents($composerJson), true);
        if (isset($json['require']['magento/product-community-edition'])) {
            $version = $json['require']['magento/product-community-edition'];
            $this->addResult(
                'Magento Version',
                true,
                "Found version: {$version}"
            );
        } else {
            $this->addWarning('Cannot determine Magento version');
        }
    }

    private function checkRequiredExtensions(): void
    {
        $required = [
            'curl',
            'json',
            'openssl',
            'PDO',
            'pdo_mysql',
            'SimpleXML'
        ];

        foreach ($required as $ext) {
            $loaded = extension_loaded($ext);
            $this->addResult(
                "PHP Extension: {$ext}",
                $loaded,
                $loaded ? 'Installed' : 'Missing'
            );
        }
    }

    private function checkFilePermissions(): void
    {
        $paths = [
            'app/etc',
            'var',
            'generated',
            'pub/static'
        ];

        foreach ($paths as $path) {
            $fullPath = $this->magentoRoot . '/' . $path;
            if (!file_exists($fullPath)) {
                $this->addWarning("Path does not exist: {$path}");
                continue;
            }

            $writable = is_writable($fullPath);
            $this->addResult(
                "File Permissions: {$path}",
                $writable,
                $writable ? 'Writable' : 'Not writable'
            );
        }
    }

    private function checkComposerJson(): void
    {
        $composerJson = __DIR__ . '/composer.json';
        if (!file_exists($composerJson)) {
            $this->addError('Module composer.json not found');
            return;
        }

        $json = json_decode(file_get_contents($composerJson), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->addError('Invalid composer.json format');
            return;
        }

        $required = ['name', 'type', 'version', 'require'];
        foreach ($required as $field) {
            if (!isset($json[$field])) {
                $this->addError("Missing {$field} in composer.json");
            }
        }
    }

    private function checkConflictingModules(): void
    {
        $modulesDir = $this->magentoRoot . '/app/code';
        if (!is_dir($modulesDir)) {
            $this->addWarning('Cannot check for conflicting modules');
            return;
        }

        // Check for old PayStand module
        if (is_dir($modulesDir . '/PayStand')) {
            $this->addWarning('Old PayStand module directory found. Will be replaced during installation.');
        }
    }

    private function checkDatabasePrivileges(): void
    {
        // Try to read env.php for database configuration
        $envFile = $this->magentoRoot . '/app/etc/env.php';
        if (!file_exists($envFile)) {
            $this->addWarning('Cannot verify database privileges');
            return;
        }

        try {
            $env = require $envFile;
            if (isset($env['db']['connection']['default'])) {
                $db = $env['db']['connection']['default'];
                $dsn = "mysql:host={$db['host']};dbname={$db['dbname']}";
                $pdo = new PDO($dsn, $db['username'], $db['password']);
                
                // Check privileges
                $stmt = $pdo->query('SHOW GRANTS');
                $hasPrivileges = false;
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    if (strpos($row[0], 'ALL PRIVILEGES') !== false) {
                        $hasPrivileges = true;
                        break;
                    }
                }
                
                $this->addResult(
                    'Database Privileges',
                    $hasPrivileges,
                    $hasPrivileges ? 'Sufficient' : 'May need additional privileges'
                );
            }
        } catch (Exception $e) {
            $this->addWarning('Cannot verify database privileges: ' . $e->getMessage());
        }
    }

    private function checkMemoryLimit(): void
    {
        $limit = ini_get('memory_limit');
        $limitBytes = $this->returnBytes($limit);
        $recommended = 756 * 1024 * 1024; // 756M
        
        $this->addResult(
            'PHP Memory Limit',
            $limitBytes >= $recommended,
            "Current: {$limit}, Recommended: 756M"
        );
    }

    private function returnBytes($val): int
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val)-1]);
        $val = (int)$val;
        switch($last) {
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }
        return $val;
    }

    private function addResult(string $check, bool $result, string $message = ''): void
    {
        $this->results[] = [
            'check' => $check,
            'result' => $result,
            'message' => $message
        ];
    }

    private function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    private function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function displayResults(): bool
    {
        echo "\nPayStand Magento 2 Module Pre-Installation Check\n";
        echo str_repeat('=', 50) . "\n\n";

        foreach ($this->results as $result) {
            $status = $result['result'] ? '✅ PASS' : '❌ FAIL';
            echo sprintf(
                "%s: %s %s\n",
                $status,
                $result['check'],
                $result['message'] ? "({$result['message']})" : ''
            );
        }

        if ($this->errors) {
            echo "\nErrors:\n";
            foreach ($this->errors as $error) {
                echo "❌ {$error}\n";
            }
        }

        if ($this->warnings) {
            echo "\nWarnings:\n";
            foreach ($this->warnings as $warning) {
                echo "⚠️  {$warning}\n";
            }
        }

        $hasFailures = false;
        foreach ($this->results as $result) {
            if (!$result['result']) {
                $hasFailures = true;
                break;
            }
        }

        echo "\nSummary:\n";
        echo "Total Checks: " . count($this->results) . "\n";
        echo "Errors: " . count($this->errors) . "\n";
        echo "Warnings: " . count($this->warnings) . "\n";
        echo "Status: " . ($hasFailures ? "❌ Not Ready for Installation" : "✅ Ready for Installation") . "\n";

        return !$hasFailures && empty($this->errors);
    }
}

// Run checks
$checker = new PreInstallCheck();
$checker->runChecks();
$success = $checker->displayResults();

// Exit with appropriate code
exit($success ? 0 : 1); 