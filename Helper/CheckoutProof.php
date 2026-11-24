<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Helper;

/**
 * Signs the quote id, amount, and currency that a card create will send.
 * Roadrunner checks the same message with the Paystand client secret.
 */
class CheckoutProof
{
    public function sign(string $quoteId, $amount, string $currency, string $secret): string
    {
        $cents = (int) round(((float) $amount) * 100);
        $message = $quoteId . '|' . $cents . '|' . strtoupper(trim($currency));

        return hash_hmac('sha256', $message, $secret);
    }
}
