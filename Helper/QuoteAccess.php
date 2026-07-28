<?php

namespace PayStand\PayStandMagento\Helper;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Shared quote-id resolution, request-ownership authorization, and order lookup
 * for the checkout AJAX endpoints (orderstatus, quotepaymentstatus,
 * savepaymentdata).
 *
 * Authorization model:
 * - A masked (non-numeric) quote id is an unguessable capability token that
 *   Magento only ever hands to the guest session that owns the cart, so
 *   presenting it proves ownership.
 * - A numeric quote id is guessable/sequential, so the caller must prove
 *   ownership through their session: either the logged-in customer owns the
 *   quote, or the current checkout session is on that exact quote.
 *
 * Endpoints fail closed on authorization: an unauthorized caller receives the
 * same generic "nothing here" response as a nonexistent quote, so probing
 * sequential ids leaks no payment, order, or cart information.
 */
class QuoteAccess
{
    /** @var QuoteIdMaskFactory */
    private $quoteIdMaskFactory;

    /** @var CartRepositoryInterface */
    private $cartRepository;

    /** @var CustomerSession */
    private $customerSession;

    /** @var CheckoutSession */
    private $checkoutSession;

    /** @var OrderCollectionFactory */
    private $orderCollectionFactory;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        QuoteIdMaskFactory $quoteIdMaskFactory,
        CartRepositoryInterface $cartRepository,
        CustomerSession $customerSession,
        CheckoutSession $checkoutSession,
        OrderCollectionFactory $orderCollectionFactory,
        LoggerInterface $logger
    ) {
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->cartRepository = $cartRepository;
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->logger = $logger;
    }

    /**
     * Resolve a real numeric quote_id from an incoming id.
     * Numeric ids are returned as-is; masked (guest) ids are translated.
     *
     * @param string|int $incomingId
     * @return int
     * @throws NoSuchEntityException when the masked id cannot be resolved
     */
    public function resolveRealQuoteId($incomingId): int
    {
        if (is_numeric($incomingId)) {
            return (int)$incomingId;
        }

        $mask = $this->quoteIdMaskFactory->create()->load($incomingId, 'masked_id');
        $realId = (int)$mask->getQuoteId();

        if ($realId <= 0) {
            throw new NoSuchEntityException(__('Could not resolve masked quote id.'));
        }

        return $realId;
    }

    /**
     * Resolve, load, and authorize a quote for the current request.
     *
     * @param string|int $incomingId Raw id from the request (numeric or masked)
     * @return \Magento\Quote\Api\Data\CartInterface|null The quote, or null when
     *         it does not exist or the current session is not allowed to see it.
     */
    public function getAuthorizedQuote($incomingId)
    {
        if (!$incomingId) {
            return null;
        }

        $cameFromMaskedId = !is_numeric($incomingId);

        try {
            $realQuoteId = $this->resolveRealQuoteId($incomingId);
            $quote = $this->cartRepository->get($realQuoteId);
        } catch (\Exception $e) {
            $this->logger->info(
                'QUOTEACCESS >>>>>> Could not resolve/load quote: ' . $e->getMessage(),
                ['incoming_quote' => $incomingId]
            );
            return null;
        }

        if (!$this->canAccess($quote, $cameFromMaskedId)) {
            $this->logger->warning(
                'QUOTEACCESS >>>>>> Session does not own quote; denying access',
                ['quote_id' => $quote->getId()]
            );
            return null;
        }

        return $quote;
    }

    /**
     * Does the current request context own this quote?
     *
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @param bool $cameFromMaskedId Whether the caller presented a masked id
     * @return bool
     */
    public function canAccess($quote, bool $cameFromMaskedId): bool
    {
        // A masked id is an unguessable capability token — presenting it proves
        // ownership of the guest cart it maps to.
        if ($cameFromMaskedId) {
            return true;
        }

        // Numeric id: the logged-in customer must own the quote...
        $quoteCustomerId = (int)$quote->getCustomerId();
        if ($quoteCustomerId
            && $this->customerSession->isLoggedIn()
            && (int)$this->customerSession->getCustomerId() === $quoteCustomerId
        ) {
            return true;
        }

        // ...or the current checkout session must be on this exact quote.
        try {
            if ((int)$this->checkoutSession->getQuoteId() === (int)$quote->getId()) {
                return true;
            }
        } catch (\Exception $e) {
            // Session unavailable — fall through to deny.
        }

        return false;
    }

    /**
     * Find the most recent sales order for a quote id, if any.
     *
     * Mirrors the "by quote id" lookup used by the webhook controller's
     * findOrder(), which is the authoritative check for whether placeOrder
     * produced an order for this cart.
     *
     * @param int $quoteId
     * @return \Magento\Sales\Model\Order|null
     */
    public function findOrderByQuoteId(int $quoteId)
    {
        try {
            $collection = $this->orderCollectionFactory->create()
                ->addFieldToFilter('quote_id', $quoteId)
                ->setOrder('entity_id', 'DESC')
                ->setPageSize(1);

            if ($collection->getSize() > 0) {
                return $collection->getFirstItem();
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'QUOTEACCESS >>>>>> Error looking up order by quote id: ' . $e->getMessage(),
                ['quote_id' => $quoteId]
            );
        }

        return null;
    }
}
