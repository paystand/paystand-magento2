<?php

namespace PayStand\PayStandMagento\Helper;

use Psr\Log\LoggerInterface;
use Magento\Quote\Model\Quote\Address\RateFactory;

/**
 * Guards a quote's shipping selection across a totals recollection, and describes it
 * for telemetry. Magento clears the method and zeroes the amount when a rate
 * re-request returns nothing (Quote\Address::collectShippingRates).
 */
class QuoteShipping
{
    /** @var LoggerInterface */
    private $logger;

    /** @var RateFactory */
    private $rateFactory;

    public function __construct(LoggerInterface $logger, RateFactory $rateFactory)
    {
        $this->logger = $logger;
        $this->rateFactory = $rateFactory;
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

        $method = $address->getShippingMethod();

        // Capture the selected rate row too, not just the method. placeOrder's
        // validator (QuoteValidator::validateBeforeSubmit) requires the method AND a
        // matching rate — restoring the method alone leaves getShippingRateByCode()
        // null and still fails placement.
        $rate = null;
        if ($method) {
            $rateModel = $address->getShippingRateByCode($method);
            if ($rateModel) {
                $rate = [
                    'code'         => $rateModel->getCode(),
                    'carrier'      => $rateModel->getCarrier(),
                    'carrierTitle' => $rateModel->getCarrierTitle(),
                    'method'       => $rateModel->getMethod(),
                    'methodTitle'  => $rateModel->getMethodTitle(),
                    'price'        => $rateModel->getPrice(),
                ];
            }
        }

        return [
            'method'      => $method,
            'amount'      => $address->getShippingAmount(),
            'baseAmount'  => $address->getBaseShippingAmount(),
            'description' => $address->getShippingDescription(),
            'rate'        => $rate,
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

        // The validator needs both, so treat a dropped method OR a dropped rate row
        // as something to restore — the amounts the shopper already paid, and a rate
        // that getShippingRateByCode() can resolve for the method.
        $methodMissing = !$address->getShippingMethod();
        $rateMissing = !$address->getShippingRateByCode($snapshot['method']);
        if (!$methodMissing && !$rateMissing) {
            return false;
        }

        if ($methodMissing) {
            $address->setShippingMethod($snapshot['method']);
            $address->setShippingDescription($snapshot['description']);
        }

        // Amounts come from the snapshot rather than the zeroed recollection: this is
        // the shipping the shopper was charged, not a stale re-quote.
        $address->setShippingAmount($snapshot['amount']);
        $address->setBaseShippingAmount($snapshot['baseAmount']);

        // Re-attach the captured rate so validateBeforeSubmit finds a rate for the
        // method even when a live re-request returned nothing.
        $rateReattached = false;
        if ($rateMissing && !empty($snapshot['rate'])) {
            $rate = $this->rateFactory->create();
            $rate->setCode($snapshot['rate']['code'])
                ->setCarrier($snapshot['rate']['carrier'])
                ->setCarrierTitle($snapshot['rate']['carrierTitle'])
                ->setMethod($snapshot['rate']['method'])
                ->setMethodTitle($snapshot['rate']['methodTitle'])
                ->setPrice($snapshot['rate']['price']);
            $address->addShippingRate($rate);
            $rateReattached = true;
        }

        $this->logger->warning(
            'PAYSTAND-SHIPPING >>>>>> Recollection cleared the shipping selection; restored it',
            [
                'context'         => $context,
                'quote_id'        => $quote->getId(),
                'method'          => $snapshot['method'],
                'amount'          => $snapshot['amount'],
                'method_restored' => $methodMissing,
                'rate_reattached' => $rateReattached,
            ]
        );

        return true;
    }

    /**
     * Recollect a quote's totals while preserving the shopper's shipping selection.
     * A recollection can clear the method when a rate re-request returns nothing, so
     * this snapshots first, restores after, and — if it had to restore — re-arms the
     * rate request and recollects once more so the restored method gets a live rate.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param string $context Where this ran, for the log lines
     * @return array{before:string,restored:bool,retryFailed:bool} Telemetry about what happened
     */
    public function recollectPreservingShipping($quote, string $context): array
    {
        $snapshot = $this->snapshot($quote);
        $before = $this->describe($quote);

        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();

        $restored = $this->restore($quote, $snapshot, $context);
        $retryFailed = false;
        if ($restored) {
            // Re-arm the rate request before recollecting: the first pass consumed
            // the flag, so a plain recollect finds no rate to price the restored
            // method and would return shipping as 0.
            $shippingAddress = $quote->getShippingAddress();
            if ($shippingAddress) {
                $shippingAddress->setCollectShippingRates(true);
            }
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();

            // Restoring a second time means the retry cleared the method again, so
            // the selection is back but still has no rate to price it.
            $retryFailed = $this->restore($quote, $snapshot, $context . '-retry');
        }

        return ['before' => $before, 'restored' => $restored, 'retryFailed' => $retryFailed];
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
