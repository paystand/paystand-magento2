<?php

declare(strict_types=1);

namespace Magento\Checkout\Model {
    interface ConfigProviderInterface
    {
        public function getConfig();
    }
}

namespace Magento\Framework\App\Config {
    interface ScopeConfigInterface
    {
        public function getValue($path, $scopeType = null, $scopeCode = null);
    }
}

namespace Magento\Store\Model {
    class ScopeInterface
    {
        public const SCOPE_STORE = 'store';
    }
}

namespace Magento\Config\Model\Config\Backend {
    class Encrypted
    {
        protected $value = '';
        public int $parentProcessCalls = 0;
        public int $parentAfterLoadCalls = 0;

        public function setValue(string $value): void
        {
            $this->value = $value;
        }

        public function getValue(): string
        {
            return $this->value;
        }

        public function processValue($value)
        {
            $this->parentProcessCalls++;
            return 'decrypted:' . $value;
        }

        protected function _afterLoad()
        {
            $this->parentAfterLoadCalls++;
        }
    }
}

namespace Magento\Framework\Encryption {
    interface EncryptorInterface
    {
        public function encrypt($data);
    }
}

namespace Magento\Framework\Setup {
    interface ModuleDataSetupInterface
    {
    }
}

namespace Magento\Framework\Setup\Patch {
    interface DataPatchInterface
    {
        public function apply();
        public static function getDependencies(): array;
        public function getAliases(): array;
    }
}

namespace {
    $root = dirname(__DIR__, 2);

    require $root . '/Model/PayStandConfigProvider.php';
    require $root . '/Model/Config/Backend/ClientSecret.php';
    require $root . '/Setup/Patch/Data/EncryptLegacyClientSecret.php';
    require $root . '/Plugin/CsrfValidatorSkip.php';

    function expect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    final class ScopeConfigFake implements \Magento\Framework\App\Config\ScopeConfigInterface
    {
        public function getValue($path, $scopeType = null, $scopeCode = null)
        {
            $values = [
                'payment/paystandmagento/publishable_key' => 'public-key-sentinel',
                'payment/paystandmagento/checkout_preset_key' => 'preset-sentinel',
                'payment/paystandmagento/customer_id' => 'customer-sentinel',
                'payment/paystandmagento/update_order_on' => 'paid',
                'payment/paystandmagento/use_sandbox' => '1',
                'trans_email/ident_support/email' => 'support@example.invalid',
                'general/store_information/name' => 'Test Store',
                'payment/paystandmagento/client_id' => 'client-id-must-not-appear',
                'payment/paystandmagento/client_secret' => 'secret-must-not-appear'
            ];
            return $values[$path] ?? null;
        }
    }

    final class ExposedClientSecret extends \PayStand\PayStandMagento\Model\Config\Backend\ClientSecret
    {
        public function runAfterLoad(): void
        {
            $this->_afterLoad();
        }
    }

    final class RequestFake
    {
        public function __construct(
            private string $module,
            private string $controller,
            private string $action
        ) {
        }

        public function getModuleName(): string { return $this->module; }
        public function getControllerName(): string { return $this->controller; }
        public function getActionName(): string { return $this->action; }
    }

    final class SelectFake
    {
        public array $fromArgs = [];
        public array $whereArgs = [];

        public function from($table, $columns): self
        {
            $this->fromArgs = [$table, $columns];
            return $this;
        }

        public function where($condition, $value): self
        {
            $this->whereArgs = [$condition, $value];
            return $this;
        }
    }

    final class ConnectionFake
    {
        public array $updates = [];
        public SelectFake $lastSelect;

        public function __construct(private array $rows)
        {
        }

        public function select(): SelectFake
        {
            $this->lastSelect = new SelectFake();
            return $this->lastSelect;
        }
        public function fetchAll($select): array { return $this->rows; }
        public function update($table, array $data, array $where): void
        {
            $this->updates[] = [$table, $data, $where];
        }
    }

    final class SetupFake implements \Magento\Framework\Setup\ModuleDataSetupInterface
    {
        public int $starts = 0;
        public int $ends = 0;

        public function __construct(private ConnectionFake $connection)
        {
        }

        public function getConnection(): ConnectionFake { return $this->connection; }
        public function getTable(string $name): string { return 'prefix_' . $name; }
        public function startSetup(): void { $this->starts++; }
        public function endSetup(): void { $this->ends++; }
    }

    final class EncryptorFake implements \Magento\Framework\Encryption\EncryptorInterface
    {
        public array $inputs = [];

        public function encrypt($data)
        {
            $this->inputs[] = $data;
            return '0:3:' . base64_encode((string)$data);
        }
    }

    $provider = new \PayStand\PayStandMagento\Model\PayStandConfigProvider(new ScopeConfigFake());
    $browserConfig = $provider->getConfig();
    $encodedConfig = json_encode($browserConfig, JSON_THROW_ON_ERROR);
    expect(strpos($encodedConfig, 'public-key-sentinel') !== false, 'publishable configuration disappeared');
    expect(strpos($encodedConfig, 'client-id-must-not-appear') === false, 'client id leaked into checkout config');
    expect(strpos($encodedConfig, 'secret-must-not-appear') === false, 'client secret leaked into checkout config');
    expect(strpos($encodedConfig, 'access_token') === false, 'OAuth token key leaked into checkout config');

    $secret = new ExposedClientSecret();
    expect(!$secret::isEncryptedValue('plaintext-secret'), 'plaintext misclassified as ciphertext');
    expect($secret::isEncryptedValue('0:3:' . base64_encode('ciphertext')), 'current ciphertext not recognized');
    expect($secret::isEncryptedValue('0:2:legacy-iv:' . base64_encode('ciphertext')), 'legacy ciphertext not recognized');
    expect($secret->processValue('plaintext-secret') === 'plaintext-secret', 'upgrade plaintext was not preserved');
    expect($secret->parentProcessCalls === 0, 'upgrade plaintext reached Magento decryptor');
    expect($secret->processValue('0:3:' . base64_encode('ciphertext')) === 'decrypted:0:3:' . base64_encode('ciphertext'), 'ciphertext did not reach Magento decryptor');
    $secret->setValue('plaintext-secret');
    $secret->runAfterLoad();
    expect($secret->parentAfterLoadCalls === 0, 'admin load tried to decrypt upgrade plaintext');
    $secret->setValue('0:3:' . base64_encode('ciphertext'));
    $secret->runAfterLoad();
    expect($secret->parentAfterLoadCalls === 1, 'admin load did not decrypt ciphertext');

    $connection = new ConnectionFake([
        ['config_id' => 1, 'value' => 'plaintext-secret'],
        ['config_id' => 2, 'value' => '0:3:' . base64_encode('already-encrypted')],
        ['config_id' => 3, 'value' => ''],
        ['config_id' => 4, 'value' => '0:2:legacy-iv:' . base64_encode('legacy-encrypted')]
    ]);
    $setup = new SetupFake($connection);
    $encryptor = new EncryptorFake();
    $patch = new \PayStand\PayStandMagento\Setup\Patch\Data\EncryptLegacyClientSecret($setup, $encryptor);
    expect($patch->apply() === $patch, 'data patch did not return itself');
    expect($setup->starts === 1 && $setup->ends === 1, 'setup transaction was not balanced');
    expect($encryptor->inputs === ['plaintext-secret'], 'patch encrypted the wrong rows');
    expect(count($connection->updates) === 1, 'patch did not perform exactly one plaintext migration');
    expect($connection->updates[0][2] === ['config_id = ?' => 1], 'patch did not target the exact config row');
    expect($connection->lastSelect->fromArgs === ['prefix_core_config_data', ['config_id', 'value']], 'patch selected the wrong table or columns');
    expect($connection->lastSelect->whereArgs === ['path = ?', 'payment/paystandmagento/client_secret'], 'patch selected the wrong config path');
    expect(strpos($connection->updates[0][1]['value'], 'plaintext-secret') === false, 'patch stored plaintext');

    $csrf = new \PayStand\PayStandMagento\Plugin\CsrfValidatorSkip();
    $proceeds = 0;
    $proceed = function () use (&$proceeds) {
        $proceeds++;
        return 'validated';
    };
    $action = new \stdClass();
    expect($csrf->aroundValidate(null, $proceed, new RequestFake('paystandmagento', 'webhook', 'paystand'), $action) === null, 'webhook was not exempted');
    expect($proceeds === 0, 'webhook unexpectedly reached the form-key validator');
    expect($csrf->aroundValidate(null, $proceed, new RequestFake('paystandmagento', 'checkout', 'savepaymentdata'), $action) === 'validated', 'checkout mutation bypassed form-key validation');
    expect($csrf->aroundValidate(null, $proceed, new RequestFake('other', 'webhook', 'paystand'), $action) === 'validated', 'foreign webhook bypassed form-key validation');
    expect($proceeds === 2, 'wrong number of non-webhook validations');

    $template = file_get_contents($root . '/view/frontend/templates/hyva-checkout/paystand-init.phtml');
    $luma = file_get_contents($root . '/view/frontend/web/js/view/payment/method-renderer/paystandmagento-directpost.js');
    $hyva = file_get_contents($root . '/view/frontend/web/js/hyva-checkout/paystand-method.js');
    $frontendDi = file_get_contents($root . '/etc/frontend/di.xml');
    $defaultConfig = file_get_contents($root . '/etc/config.xml');
    $composer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $moduleXml = simplexml_load_file($root . '/etc/module.xml');
    $cloudLogger = file_get_contents($root . '/Helper/CloudLogger.php');
    $navigation = file_get_contents($root . '/view/frontend/web/js/paystand-nav-trace.js');
    foreach (['clientSecret', 'client_secret', 'accessToken', 'access_token'] as $forbidden) {
        expect(strpos($template, $forbidden) === false, "Hyva template exposes {$forbidden}");
        expect(strpos($luma, $forbidden) === false, "Luma JavaScript exposes {$forbidden}");
        expect(strpos($hyva, $forbidden) === false, "Hyva JavaScript exposes {$forbidden}");
    }
    expect(strpos($luma, "$.mage.cookies.get('form_key')") !== false, 'Luma mutation lacks form key');
    expect(strpos($hyva, 'window.hyva.getFormKey') !== false, 'Hyva mutation lacks form key');
    expect(strpos($frontendDi, 'name="csrf_validator_skip"') === false, 'collision-prone CSRF plugin name remains');
    expect(strpos($frontendDi, 'name="paystand_webhook_csrf_exemption"') !== false, 'unique CSRF plugin name missing');
    expect(strpos($defaultConfig, '<report_only>') === false, 'module weakens the merchant CSP mode');
    expect($composer['version'] === '3.7.3', 'composer version was not incremented');
    expect((string)$moduleXml->module['setup_version'] === '3.7.3', 'setup version was not incremented');
    expect(strpos($cloudLogger, "PLUGIN_VERSION = '3.7.3'") !== false, 'server telemetry version was not incremented');
    expect(strpos($navigation, "PLUGIN_VERSION = '3.7.3'") !== false, 'browser telemetry version was not incremented');
    expect(strpos($luma, "plugin_version:  '3.7.3'") !== false, 'checkout telemetry version was not incremented');

    echo json_encode([
        'ok' => true,
        'browserCredentialAbsence' => true,
        'plaintextRowsMigrated' => 1,
        'ciphertextRowsPreserved' => 2,
        'csrfExemptions' => 1,
        'csrfProtectedSamples' => 2
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
