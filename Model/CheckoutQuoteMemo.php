<?php

namespace PayStand\PayStandMagento\Model;

/**
 * Holds the checkout session's quote id for the life of one request.
 *
 * Magento nulls the stored quote id as soon as the quote stops being active,
 * which on the confirmation page happens before the success validator runs.
 */
class CheckoutQuoteMemo
{
    /** @var int */
    private $quoteId = 0;

    /**
     * Keeps the first non-empty id seen; later clears cannot erase it.
     *
     * @param mixed $quoteId
     * @return void
     */
    public function remember($quoteId): void
    {
        $quoteId = (int)$quoteId;
        if ($quoteId > 0 && $this->quoteId === 0) {
            $this->quoteId = $quoteId;
        }
    }

    /**
     * @return int 0 when no quote id was ever seen this request
     */
    public function get(): int
    {
        return $this->quoteId;
    }
}
