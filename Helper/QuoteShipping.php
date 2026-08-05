<?php

namespace PayStand\PayStandMagento\Helper;

use Psr\Log\LoggerInterface;

/**
 * Guards a quote's shipping selection across a totals recollection, and
 * describes it for telemetry.
 *
 * Why this exists — Magento destroys the shopper's shipping selection when a
 * rate re-request comes back empty. In Quote\Address::collectShippingRates():
 *
 *     $found = $this->requestShippingRates();
 *     if (!$found) {
 *         $this->setShippingAmount(0)->setBaseShippingAmount(0)
 *              ->setShippingMethod('')->setShippingDescription('');
 *     }
 *
 * and Quote\Address\Total\Shipping::collect() zeroes the shipping total up
 * front, restoring it only if a rate matching the method is still present.
 *
 * ValidationRules\ShippingMethodValidationRule then requires BOTH the method
 * and a matching rate:
 *
 *     $validationResult = $shippingMethod && $shippingRate;
 *
 * so a lost rate surfaces as the misleading "The shipping method is missing.
 * Select the shipping method and try again." — which is what blocks order
 * placement after the shopper has already been charged.
 *
 * A transient carrier/rate-request failure must never cost a shopper their
 * cart, so any recollection we initiate is bracketed by snapshot()/restore().
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
     *
     * Returns null for virtual quotes and any quote without a shipping address,
     * which signals the caller that there is nothing to protect.
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
     *
     * Only ever puts back what the shopper had already chosen — it never
     * invents a method — so a genuine shipping change is left alone.
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

        // Only act when the recollection actually dropped the method. A rate
        // that is merely absent is left for Magento to resolve; blindly forcing
        // an amount back would risk charging a stale shipping price.
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
     *
     * Reports the rate separately from the method because the validation rule
     * needs both — "method set, rate missing" is the exact broken state we are
     * hunting, and it is invisible if only the method is logged.
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
            $hasRate = $method ? (bool)$address->getShippingRateByCode($method) : false;

            // Address completeness travels with the shipping state because an
            // incomplete address is the other way a paid cart becomes unplaceable
            // ("street"/"city"/"telephone" is required). Report only presence, never
            // the values — these events leave the merchant's server.
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
