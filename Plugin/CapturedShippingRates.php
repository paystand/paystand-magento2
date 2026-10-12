<?php

declare(strict_types=1);

namespace PayStand\PayStandMagento\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address;
use PayStand\PayStandMagento\Helper\CaptureSnapshot;
use Psr\Log\LoggerInterface;

/**
 * Every collect that has collect_shipping_rates set deletes the stored rate
 * rows and asks the carriers again. When the answer does not include the
 * selected method, Magento clears the method and sets shipping to zero. The
 * answer can differ outside the shopper's browser session, so the webhook
 * rescue can lose the shipping that was paid. CapturedQuoteTotals then has to
 * restore the method and the grand total after the collect.
 *
 * Once Paystand reports the payment, the stored rate row for the selected
 * method is the rate that was paid. While the cart and destination still
 * match the capture snapshot, this plugin keeps the stored rows and skips the
 * carrier request. Magento's Shipping total then reads the paid price from
 * the kept row. A changed cart, a quote without a stored row for the method,
 * and captured_cart_guard off all collect as usual.
 */
class CapturedShippingRates
{
    /** @var CaptureSnapshot */
    private $snapshot;

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        CaptureSnapshot $snapshot,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->snapshot = $snapshot;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    /**
     * @param Address $subject
     * @param callable $proceed
     * @return Address
     */
    public function aroundCollectShippingRates(Address $subject, callable $proceed)
    {
        try {
            $keep = $subject->getCollectShippingRates() && $this->keepsPaidRate($subject);
        } catch (\Throwable $e) {
            $keep = false;
        }

        if (!$keep) {
            return $proceed();
        }

        // Same end state as Magento's early return when the flag is not set.
        $subject->setCollectShippingRates(false);
        $this->logger->debug(
            'PAYSTAND-CAPTURED-RATES: kept the paid shipping rate ' . $subject->getShippingMethod()
            . ' on quote ' . $subject->getQuoteId() . ' instead of asking the carriers again'
        );
        return $subject;
    }

    private function keepsPaidRate(Address $address): bool
    {
        if ($address->getAddressType() !== Address::ADDRESS_TYPE_SHIPPING) {
            return false;
        }

        $quote = $address->getQuote();
        if (!$quote || !$this->snapshot->isPinnable($quote) || $quote->getIsMultiShipping()) {
            return false;
        }

        if ($this->snapshot->resolveGuardMode($this->scopeConfig) === CapturedQuoteSubmit::MODE_OFF) {
            return false;
        }

        $method = (string)$address->getShippingMethod();
        if ($method === '') {
            return false;
        }

        // A missing or unreadable stamp is not evidence that the cart changed.
        $payload = $this->snapshot->read($quote);
        if ($payload !== null
            && $this->snapshot->stampedHash($payload) !== ''
            && !$this->snapshot->matches($quote, $payload)
        ) {
            return false;
        }

        // getShippingRateByCode() also returns rows already marked deleted.
        foreach ($address->getAllShippingRates() as $rate) {
            if ((string)$rate->getCode() === $method) {
                return true;
            }
        }
        return false;
    }
}
