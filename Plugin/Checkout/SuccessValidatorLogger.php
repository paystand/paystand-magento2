<?php

namespace PayStand\PayStandMagento\Plugin\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Model\Session\SuccessValidator;
use PayStand\PayStandMagento\Helper\CloudLogger;

/**
 * Reports why the order confirmation page sent a shopper to the cart instead.
 * The validator only answers yes/no, so this names which checkout-session values
 * were missing — the difference between a lost session and an unplaced order.
 */
class SuccessValidatorLogger
{
    /** @var CheckoutSession */
    private $checkoutSession;

    public function __construct(CheckoutSession $checkoutSession)
    {
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * @param SuccessValidator $subject
     * @param bool $result
     * @return bool
     */
    public function afterIsValid(SuccessValidator $subject, $result)
    {
        if ($result) {
            return $result;
        }

        try {
            $lastSuccessQuoteId = $this->checkoutSession->getLastSuccessQuoteId();
            $lastQuoteId = $this->checkoutSession->getLastQuoteId();
            $lastOrderId = $this->checkoutSession->getLastOrderId();

            $missing = [];
            if (!$lastSuccessQuoteId) {
                $missing[] = 'last_success_quote_id';
            }
            if (!$lastQuoteId) {
                $missing[] = 'last_quote_id';
            }
            if (!$lastOrderId) {
                $missing[] = 'last_order_id';
            }

            CloudLogger::ship(CloudLogger::EVENT_SUCCESS_PAGE_BOUNCED, [
                'quote_id'      => (string)($lastSuccessQuoteId ?: $lastQuoteId ?: ''),
                'error_message' => 'success page redirected to cart; missing['
                    . implode(',', $missing) . ']'
                    . ' last_real_order_id=' . ($this->checkoutSession->getLastRealOrderId() ?: '(none)'),
            ]);
        } catch (\Throwable $e) {
            // Telemetry must never disturb the checkout flow.
        }

        return $result;
    }
}
