<?php

declare(strict_types=1);

namespace PayStand\PayStandMagento\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * placeOrder was refused because the cart no longer matches the captured one.
 *
 * The refusal has to be recognisable to the webhook, to the checkout JavaScript
 * and to our telemetry. The message is translatable and editable, so none of
 * them may match on its words: PHP callers test for this class, and the browser
 * matches CODE, which is never translated.
 */
class CapturedCartChangedException extends LocalizedException
{
    /**
     * Printed in the shopper-facing message so the browser can recognise the
     * refusal from rendered text. Support can quote it too.
     */
    public const CODE = 'PS-CART-CHANGED';
}
