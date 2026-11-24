<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Client-credentials calls for the admin hold panel.
 */
class PaystandApi
{
    private const CONFIG_CLIENT_ID = 'payment/paystandmagento/client_id';
    private const CONFIG_CLIENT_SECRET = 'payment/paystandmagento/client_secret';
    private const CONFIG_SANDBOX = 'payment/paystandmagento/sandbox';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    private function request(string $method, string $path, ?array $body): array
    {
        $token = $this->token();
        if ($token === null) {
            return ['ok' => false, 'body' => [], 'error' => 'Paystand token is not available.'];
        }

        $ch = curl_init($this->baseUrl() . $path);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json'
        ];
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $error = '';
        if ($status < 200 || $status >= 300) {
            $error = (string) ($decoded['explanation'] ?? $decoded['message'] ?? ('Paystand returned HTTP ' . $status));
        }

        return ['ok' => $error === '', 'body' => $decoded, 'error' => $error];
    }

    private function token(): ?string
    {
        $clientId = (string) $this->scopeConfig->getValue(self::CONFIG_CLIENT_ID, ScopeInterface::SCOPE_STORE);
        $clientSecret = (string) $this->scopeConfig->getValue(self::CONFIG_CLIENT_SECRET, ScopeInterface::SCOPE_STORE);
        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $ch = curl_init($this->baseUrl() . '/oauth/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'auth'
        ]));
        $raw = curl_exec($ch);
        curl_close($ch);
        $decoded = json_decode((string) $raw, true);

        $token = is_array($decoded) ? (string) ($decoded['access_token'] ?? '') : '';

        return $token === '' ? null : $token;
    }

    private function baseUrl(): string
    {
        $sandbox = $this->scopeConfig->isSetFlag(self::CONFIG_SANDBOX, ScopeInterface::SCOPE_STORE);

        return $sandbox ? 'https://api.paystand.co/v3' : 'https://api.paystand.com/v3';
    }
}
