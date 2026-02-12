<?php

namespace PayStand\PayStandMagento\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\HTTP\Client\Curl;

/**
 * Telemetry Client Helper
 * 
 * Sends monitoring events to the telemetry API
 */
class TelemetryClient
{
    // Telemetry API Base URL - centralized configuration
    const TELEMETRY_API_URL = 'https://f023-2806-261-4ab-ec-f5fe-ffc7-7a5e-1c9a.ngrok-free.app';
    
    // Configuration paths
    const TELEMETRY_API_KEY = 'payment/paystandmagento/telemetry_api_key';
    const CUSTOMER_ID = 'payment/paystandmagento/customer_id';
    
    // Metric names
    const METRIC_PLUGIN_ERRORS = 'paystand_magento_plugin_errors_total';
    const METRIC_OAUTH_ATTEMPTS = 'paystand_magento_oauth_attempts_total';
    
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;
    
    /**
     * @var LoggerInterface
     */
    protected $logger;
    
    /**
     * @var Curl
     */
    protected $curl;
    
    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     * @param Curl $curl
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        Curl $curl
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->curl = $curl;
    }
    
    /**
     * Send a metric event to the telemetry API
     * 
     * @param string $metricName
     * @param string $message
     * @param array $dimensions
     * @param array $context
     * @param string $level
     * @param int $value
     * @return bool
     */
    public function sendMetric(
        string $metricName,
        string $message,
        array $dimensions,
        array $context = [],
        string $level = 'error',
        int $value = 1
    ): bool {
        $apiKey = $this->getApiKey();
        
        // If no API key configured, skip telemetry (fail silently)
        if (empty($apiKey)) {
            $this->logger->debug('TELEMETRY: API key not configured, skipping metric send');
            return false;
        }
        
        $clientId = $this->getClientId();
        
        $payload = [
            'client_id' => $clientId,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'message' => $message,
            'value' => $value,
            'dimensions' => $dimensions,
            'context' => $context
        ];
        
        $url = self::TELEMETRY_API_URL . '/metrics/' . $metricName;
        
        return $this->sendRequest($url, $payload, $apiKey);
    }
    
    /**
     * Send a log-only event to the telemetry API
     * 
     * @param string $message
     * @param array $context
     * @param string $level
     * @return bool
     */
    public function sendLog(
        string $message,
        array $context = [],
        string $level = 'info'
    ): bool {
        $apiKey = $this->getApiKey();
        
        // If no API key configured, skip telemetry (fail silently)
        if (empty($apiKey)) {
            $this->logger->debug('TELEMETRY: API key not configured, skipping log send');
            return false;
        }
        
        $clientId = $this->getClientId();
        
        $payload = [
            'client_id' => $clientId,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];
        
        $url = self::TELEMETRY_API_URL . '/logs';
        
        return $this->sendRequest($url, $payload, $apiKey);
    }
    
    /**
     * Send plugin error metric
     * 
     * @param string $category (webhook, checkout, order, invoice, oauth)
     * @param string $message
     * @param array $context
     * @return bool
     */
    public function sendPluginError(string $category, string $message, array $context = []): bool
    {
        return $this->sendMetric(
            self::METRIC_PLUGIN_ERRORS,
            $message,
            ['category' => $category],
            $context,
            'error',
            1
        );
    }
    
    /**
     * Send OAuth attempt metric
     * 
     * @param string $result (success, failed)
     * @param string $message
     * @param array $context
     * @return bool
     */
    public function sendOAuthAttempt(string $result, string $message, array $context = []): bool
    {
        $level = $result === 'success' ? 'info' : 'error';
        
        return $this->sendMetric(
            self::METRIC_OAUTH_ATTEMPTS,
            $message,
            ['result' => $result],
            $context,
            $level,
            1
        );
    }
    
    /**
     * Send HTTP request to telemetry API
     * 
     * @param string $url
     * @param array $payload
     * @param string $apiKey
     * @return bool
     */
    protected function sendRequest(string $url, array $payload, string $apiKey): bool
    {
        try {
            $headers = [
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey
            ];
            
            // Log request details (mask API key for security)
            $this->logger->debug('TELEMETRY-REQUEST: Sending event', [
                'url' => $url,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-api-key' => substr($apiKey, 0, 8) . '...' // Show only first 8 chars
                ],
                'payload' => $payload
            ]);
            
            $this->curl->setHeaders($headers);
            $this->curl->post($url, json_encode($payload));
            
            $statusCode = $this->curl->getStatus();
            $response = $this->curl->getBody();
            
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->debug('TELEMETRY-RESPONSE: Event sent successfully', [
                    'url' => $url,
                    'status' => $statusCode,
                    'response' => $response
                ]);
                return true;
            } else {
                $this->logger->error('TELEMETRY-RESPONSE: Failed to send event', [
                    'url' => $url,
                    'status' => $statusCode,
                    'response' => $response,
                    'payload' => $payload
                ]);
                return false;
            }
        } catch (\Exception $e) {
            $this->logger->error('TELEMETRY-EXCEPTION: Exception sending event', [
                'url' => $url,
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            return false;
        }
    }
    
    /**
     * Get API key from configuration
     * 
     * @return string
     */
    protected function getApiKey(): string
    {
        $apiKey = (string)$this->scopeConfig->getValue(
            self::TELEMETRY_API_KEY,
            ScopeInterface::SCOPE_STORE
        );
        
        // Temporary debug log to verify API key value
        $this->logger->debug('TELEMETRY-API-KEY: Retrieved from config', [
            'key_length' => strlen($apiKey),
            'key_preview' => substr($apiKey, 0, 6) . '...' . substr($apiKey, -2),
            'is_empty' => empty($apiKey)
        ]);
        
        return $apiKey;
    }
    
    /**
     * Get client ID from configuration
     * 
     * @return string
     */
    protected function getClientId(): string
    {
        $customerId = $this->scopeConfig->getValue(
            self::CUSTOMER_ID,
            ScopeInterface::SCOPE_STORE
        );
        
        return !empty($customerId) ? $customerId : 'unknown_customer';
    }
}
