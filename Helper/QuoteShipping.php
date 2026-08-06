<?php

namespace PayStand\PayStandMagento\Helper;

use Psr\Log\LoggerInterface;

/**
 * Guards a quote's shipping selection across a totals recollection, and describes it
 * for telemetry. Magento clears the method and zeroes the amount when a rate
 * re-request returns nothing (Quote\Address::collectShippingRates).
 */
class QuoteShipping
{
    /** @var LoggerInterface */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Capture the shipping selection before a recollection.
     * Null for virtual quotes or quotes with no shipping address — nothing to protect.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return array|null
     */
    public function snapshot($quote)
    {
        if (!$quote || $quote->isVirtual()) {
            return null;
        }

        $address = $quote->getShippingAddress();
        if (!$address) {
            return null;
        }

        return [
            'method'      => $address->getShippingMethod(),
            'amount'      => $address->getShippingAmount(),
            'baseAmount'  => $address->getBaseShippingAmount(),
            'description' => $address->getShippingDescription(),
        ];
    }

    /**
     * Restore a shipping selection that a recollection cleared.
     * Only puts back what the shopper already chose; never invents a method.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param array|null $snapshot Result of snapshot() taken before recollecting
     * @param string $context Where this ran, for the log line
     * @return bool True when a selection had to be restored
     */
    public function restore($quote, $snapshot, string $context): bool
    {
        if (!$snapshot || empty($snapshot['method'])) {
            return false;
        }

        $address = $quote->getShippingAddress();
        if (!$address) {
            return false;
        }

        // Only act when the method was dropped; forcing an amount back on a merely
        // missing rate would risk charging a stale shipping price.
        if ($address->getShippingMethod()) {
            return false;
        }

        $address->setShippingMethod($snapshot['method']);
        $address->setShippingDescription($snapshot['description']);
        $address->setShippingAmount($snapshot['amount']);
        $address->setBaseShippingAmount($snapshot['baseAmount']);

        $this->logger->warning(
            'PAYSTAND-SHIPPING >>>>>> Recollection cleared the shipping selection; restored it',
            [
                'context'  => $context,
                'quote_id' => $quote->getId(),
                'method'   => $snapshot['method'],
                'amount'   => $snapshot['amount'],
            ]
        );

        return true;
    }

    /**
     * One-line description of the shipping selection, for telemetry.
     * Rate is reported separately from the method: validation requires both.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return string
     */
    public function describe($quote): string
    {
        try {
            if (!$quote) {
                return 'shipping=no-quote';
            }
            if ($quote->isVirtual()) {
                return 'shipping=virtual';
            }

            $address = $quote->getShippingAddress();
            if (!$address) {
                return 'shipping=no-address';
            }

            $method = (string)$address->getShippingMethod();
            // getShippingRateByCode() returns the first code match including rates
            // only flagged deleted, so walk the live list the totals collector uses.
            $hasRate = false;
            if ($method !== '') {
                foreach ($address->getAllShippingRates() as $rate) {
                    if ($rate->getCode() === $method) {
                        $hasRate = true;
                        break;
                    }
                }
            }

            // An incomplete address also blocks placement. Presence only — never the
            // values, since these events leave the merchant's server.
            $missing = [];
            foreach (['street' => 'street', 'city' => 'city', 'telephone' => 'tel', 'country_id' => 'country'] as $field => $label) {
                $value = $address->getData($field);
                if (is_array($value)) {
                    $value = trim(implode('', $value));
                }
                if ($value === null || $value === '') {
                    $missing[] = $label;
                }
            }

            return 'shipping method=' . ($method !== '' ? $method : '(none)')
                . ' rate=' . ($hasRate ? 'yes' : 'NO')
                . ' amount=' . (string)$address->getShippingAmount()
                . ' addr=' . (empty($missing) ? 'complete' : 'MISSING[' . implode(',', $missing) . ']');
        } catch (\Throwable $e) {
            // Telemetry must never disturb the payment flow.
            return 'shipping=describe-failed';
        }
    }
}
