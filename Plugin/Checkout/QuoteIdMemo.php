<?php

namespace PayStand\PayStandMagento\Plugin\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use PayStand\PayStandMagento\Model\CheckoutQuoteMemo;

/**
 * Records the session's quote id on the way into getQuote(), which is the call
 * that nulls it once the quote is no longer active.
 */
class QuoteIdMemo
{
    /** @var CheckoutQuoteMemo */
    private $memo;

    public function __construct(CheckoutQuoteMemo $memo)
    {
        $this->memo = $memo;
    }

    /**
     * @param CheckoutSession $subject
     * @return void
     */
    public function beforeGetQuote(CheckoutSession $subject)
    {
        try {
            $this->memo->remember($subject->getQuoteId());
        } catch (\Throwable $e) {
            // Never let bookkeeping break the checkout session.
        }
    }
}
