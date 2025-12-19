<?php

namespace PayStand\PayStandMagento\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class EventMonitoring extends AbstractHelper
{
    /**
     * Configuration path for event tracking
     */
    const ENABLE_EVENT_TRACKING = 'payment/paystandmagento/enable_event_tracking';

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param Context $context
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    /**
     * Check if event tracking is enabled
     *
     * @return bool
     */
    public function isEventTrackingEnabled()
    {
        return $this->scopeConfig->isSetFlag(
            self::ENABLE_EVENT_TRACKING,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Log monitoring event
     *
     * @param string $eventName
     * @return void
     */
    public function logEvent($eventName)
    {
        if ($this->isEventTrackingEnabled()) {
            $this->logger->info("MAGENTO-MONITORING event {$eventName}");
        } else {
            $this->logger->debug("MAGENTO-MONITORING is disabled skipping action");
        }
    }
}

