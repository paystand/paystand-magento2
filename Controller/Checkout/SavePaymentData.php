<?php

namespace PayStand\PayStandMagento\Controller\Checkout;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Psr\Log\LoggerInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use PayStand\PayStandMagento\Helper\CustomerPayerId;
use PayStand\PayStandMagento\Helper\CloudLogger;
use PayStand\PayStandMagento\Helper\QuoteAccess;
use PayStand\PayStandMagento\Helper\QuoteShipping;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Webapi\Response;

/**
 * SavePaymentData Controller
 *
 * Responsibilities:
 * - Accepts JSON payload from frontend with:
 *   - payerId (string)
 *   - quote (numeric ID or masked quote ID)
 *   - payerDiscount (float)
 *   - payerTotalFees (float)
 * - Resolves masked quote IDs (guest carts) to real numeric quote IDs.
 * - Computes and persists a "paystand_adjustment" custom field to the quote with the rule:
 *   - If payerDiscount != 0 => store NEGATIVE value.
 *   - Else if payerTotalFees != 0 => store POSITIVE value.
 *   - Else store 0.
 * - For guest quotes: returns success and type=guest without attempting to store payerId on customer.
 * - For customer quotes: stores payerId on the customer if not already present.
 *
 * Notes:
 * - Uses CartRepositoryInterface::get($id) instead of getActive() to support quotes that might be inactive after order placement.
 * - DECIMAL DB types support negative values unless defined as UNSIGNED; ensure your schema is not UNSIGNED.
 */
class SavePaymentData extends Action
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var JsonFactory */
    protected $resultJsonFactory;

    /** @var CustomerPayerId */
    protected $customerPayerIdHelper;

    /** @var CartRepositoryInterface */
    protected $cartRepository;

    /** @var QuoteAccess */
    protected $quoteAccess;

    /** @var QuoteShipping */
    protected $quoteShipping;

    /** @var ScopeConfigInterface */
    protected $scopeConfig;

    /**
     * PayStand configuration path
     */
    const ENABLE_PAYSTAND_ADJUSTMENT = 'payment/paystandmagento/enable_paystand_adjustment';

    /**
     * Paystand payment ids are opaque lowercase-alphanumeric tokens (observed
     * length 24). Accept a sane range so a format change upstream doesn't break
     * the flow, but reject anything that clearly isn't a payment id before it
     * is persisted onto the quote.
     */
    const PAYMENT_ID_PATTERN = '/^[a-z0-9]{16,64}$/i';

    /**
     * @param Context $context
     * @param LoggerInterface $logger
     * @param JsonFactory $resultJsonFactory
     * @param CustomerPayerId $customerPayerIdHelper
     * @param CartRepositoryInterface $cartRepository
     * @param QuoteAccess $quoteAccess
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        Context $context,
        LoggerInterface $logger,
        JsonFactory $resultJsonFactory,
        CustomerPayerId $customerPayerIdHelper,
        CartRepositoryInterface $cartRepository,
        QuoteAccess $quoteAccess,
        QuoteShipping $quoteShipping,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->logger = $logger;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->customerPayerIdHelper = $customerPayerIdHelper;
        $this->cartRepository = $cartRepository;
        $this->quoteAccess = $quoteAccess;
        $this->quoteShipping = $quoteShipping;
        $this->scopeConfig = $scopeConfig;
        parent::__construct($context);
    }

    /**
     * Controller entrypoint.
     * Reads JSON, resolves quote, persists paystand_adjustment with sign rules,
     * and optionally updates customer payerId for logged-in customers.
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        // Parse JSON request body
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        if (!$data) {
            $this->logger->error('SAVEPAYMENTDATA >>>>>> Invalid JSON received');
            return $result->setHttpResponseCode(Response::HTTP_BAD_REQUEST)->setData([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_JSON',
                    'message' => 'Invalid JSON'
                ]
            ]);
        }

        $payerId         = $data['payerId'] ?? null;
        $quoteIdIncoming = $data['quote'] ?? null;
        $payerDiscount   = isset($data['payerDiscount']) ? (float)$data['payerDiscount'] : 0.0;
        $payerTotalFees  = isset($data['payerTotalFees']) ? (float)$data['payerTotalFees'] : 0.0;
        $initPayer       = $data['initPayer'] ?? false;
        // Recorded on the quote so checkout can refuse to re-open the widget for a
        // cart that has already been paid.
        $paymentId       = $data['paymentId'] ?? null;

        if (!$payerId || !$quoteIdIncoming) {
            $this->logger->error('SAVEPAYMENTDATA >>>>>> Missing payerId or quote');
            return $result->setHttpResponseCode(Response::HTTP_BAD_REQUEST)->setData([
                'success' => false,
                'error' => [
                    'code' => 'MISSING_REQUIRED_DATA',
                    'message' => 'Missing required data'
                ]
            ]);
        }

        try {
            // 1+2) Resolve, load, and AUTHORIZE the quote against the current
            // session (masked ids act as guest capability tokens; numeric ids
            // require the session to own the quote). Fail closed: a caller who
            // does not own the quote cannot write payment data onto it.
            $quote = $this->quoteAccess->getAuthorizedQuote($quoteIdIncoming);
            if (!$quote) {
                $this->logger->warning('SAVEPAYMENTDATA >>>>>> Quote not found or not owned by session', [
                    'incoming_quote' => $quoteIdIncoming
                ]);
                try {
                    CloudLogger::ship(CloudLogger::EVENT_SAVEPAYMENTDATA_ERROR, [
                        'quote_id'      => (string)$quoteIdIncoming,
                        'error_message' => 'Quote not found or not owned by requesting session',
                    ]);
                } catch (\Exception $e) {
                    // CloudLogger failure — silently ignored to protect payment flow
                }
                return $result->setHttpResponseCode(Response::HTTP_FORBIDDEN)->setData([
                    'success' => false,
                    'error' => [
                        'code' => 'QUOTE_ACCESS_DENIED',
                        'message' => 'Could not load quote'
                    ]
                ]);
            }
            $realQuoteId = (int)$quote->getId();

            // 3) Check if paystand adjustment is enabled
            $isAdjustmentEnabled = $this->scopeConfig->isSetFlag(
                self::ENABLE_PAYSTAND_ADJUSTMENT,
                ScopeInterface::SCOPE_STORE
            );

            // 4) Compute paystand_adjustment with enforced signs (only if enabled):
            //    - If payerDiscount != 0 -> NEGATIVE
            //    - Else if payerTotalFees != 0 -> POSITIVE
            $paystandAdjustment = 0.0;

            if ($isAdjustmentEnabled) {
                if ($payerDiscount != 0.0) {
                    $paystandAdjustment = -abs($payerDiscount);
                    $this->logger->info('SAVEPAYMENTDATA >>>>>> Using payerDiscount as adjustment (negative)', [
                        'payerDiscount'  => $payerDiscount,
                        'payerTotalFees' => $payerTotalFees,
                        'stored_value'   => $paystandAdjustment
                    ]);
                } elseif ($payerTotalFees != 0.0) {
                    $paystandAdjustment = abs($payerTotalFees);
                    $this->logger->info('SAVEPAYMENTDATA >>>>>> Using payerTotalFees as adjustment (positive)', [
                        'payerDiscount'  => $payerDiscount,
                        'payerTotalFees' => $payerTotalFees,
                        'stored_value'   => $paystandAdjustment
                    ]);
                }
            } else {
                $this->logger->info('SAVEPAYMENTDATA >>>>>> Paystand adjustment is disabled, not storing adjustment');
            }

            // 5) Persist the adjustment on the quote; totals will be updated in the PayStand observer.
            //    The payment id is format-checked and never overwritten — the first one
            //    is the reconciliation anchor, and a second distinct id is the
            //    duplicate-payment signal, so it is logged rather than stored.
            $quote->setData('paystand_adjustment', $paystandAdjustment);
            if (!empty($paymentId) && !preg_match(self::PAYMENT_ID_PATTERN, (string)$paymentId)) {
                $this->logger->warning('SAVEPAYMENTDATA >>>>>> Ignoring malformed paymentId', [
                    'quote_id' => $realQuoteId
                ]);
                $paymentId = null;
            }
            if (!empty($paymentId)) {
                $existingPaymentId = (string)$quote->getData('paystand_payment_id');
                if ($existingPaymentId === '') {
                    $quote->setData('paystand_payment_id', $paymentId);
                } elseif ($existingPaymentId !== (string)$paymentId) {
                    $this->logger->error('SAVEPAYMENTDATA >>>>>> Duplicate payment id for quote', [
                        'quote_id'            => $realQuoteId,
                        'recorded_payment_id' => $existingPaymentId,
                        'new_payment_id'      => $paymentId
                    ]);
                    try {
                        CloudLogger::ship(CloudLogger::EVENT_DUPLICATE_PAYMENT_RECORDED, [
                            'quote_id'      => (string)$realQuoteId,
                            'payment_id'    => (string)$paymentId,
                            'error_message' => 'Duplicate payment id observed for quote; first=' . $existingPaymentId,
                        ]);
                    } catch (\Exception $e) {
                        // CloudLogger failure — silently ignored to protect payment flow
                    }
                }
            }
            // Runs between capture and placeOrder, bracketing the window where a
            // quote's shipping rate has been seen to disappear.
            try {
                CloudLogger::ship(CloudLogger::EVENT_QUOTE_SHIPPING_STATE, [
                    'quote_id'      => (string)$realQuoteId,
                    'payment_id'    => (string)($paymentId ?? ''),
                    'error_message' => 'savepaymentdata ' . $this->quoteShipping->describe($quote),
                ]);
            } catch (\Exception $e) {
                // CloudLogger failure — silently ignored to protect payment flow
            }

            $this->cartRepository->save($quote);

            if ($isAdjustmentEnabled) {
                $this->logger->info('SAVEPAYMENTDATA >>>>>> Saved paystand_adjustment to quote', [
                    'quote_id'            => $realQuoteId,
                    'incoming_quote'      => $quoteIdIncoming,
                    'paystand_adjustment' => $paystandAdjustment
                ]);
            }

            // 6) Branch by guest vs. customer
            $isGuest = (int)$quote->getCustomerIsGuest() === 1;

            if ($isGuest) {
                // Guest flow: do not attempt to store payerId on a customer
                try {
                    CloudLogger::ship(CloudLogger::EVENT_SAVEPAYMENTDATA_SUCCESS, [
                        'quote_id'      => (string)$realQuoteId,
                        'error_message' => 'guest checkout, adjustment=' . $paystandAdjustment,
                    ]);
                } catch (\Exception $e) {
                    // CloudLogger failure — silently ignored to protect payment flow
                }
                return $result->setData([
                    'success' => true,
                    'type'    => 'guest',
                    'quote'   => $realQuoteId
                ]);
            }

            // Customer flow: ensure payerId is stored on the customer entity
            $customerId = (int)$quote->getCustomerId();
            $existingPayerId = $this->customerPayerIdHelper->getPayerIdFromCustomer($customerId);

            if ($existingPayerId) {
                $this->logger->info('SAVEPAYMENTDATA >>>>>> Customer already has payer ID', [
                    'customer_id'       => $customerId,
                    'existing_payer_id' => $existingPayerId,
                    'new_payer_id'      => $payerId
                ]);

                return $result->setData([
                    'success'            => true,
                    'type'               => 'customer',
                    'customer_id'        => $customerId,
                    'existing_payer_id'  => $existingPayerId,
                    'message'            => 'Customer already has payer ID'
                ]);
            }

            if ($payerId && $initPayer) {
                // Store new payer ID on customer
                $this->logger->info('SAVEPAYMENTDATA >>>>>> Saving new payer ID', [
                    'customer_id'  => $customerId,
                    'new_payer_id' => $payerId
                ]);
                $this->customerPayerIdHelper->savePayerIdToCustomer($customerId, $payerId);

                return $result->setData([
                    'success'      => true,
                    'type'         => 'customer',
                    'customer_id'  => $customerId,
                    'new_payer_id' => $payerId,
                    'message'      => 'New payer ID saved'
                ]);
            } else {
                $this->logger->info('SAVEPAYMENTDATA >>>>>> Not saving new payer ID');
            }

            return $result->setData([
                'success' => true,
                'type' => 'customer',
                'customer_id' => $customerId,
                'message' => 'Payer ID not updated'
            ]);

        } catch (\Exception $e) {
            $this->logger->error(
                'SAVEPAYMENTDATA >>>>>> Error loading quote: ' . $e->getMessage(),
                ['incoming_quote' => $quoteIdIncoming]
            );
            try {
                CloudLogger::ship(CloudLogger::EVENT_SAVEPAYMENTDATA_ERROR, [
                    'quote_id'      => $quoteIdIncoming ?? '',
                    'error_message' => $e->getMessage(),
                ]);
            } catch (\Exception $e) {
                // CloudLogger failure — silently ignored to protect payment flow
            }
            return $result->setHttpResponseCode(Response::HTTP_INTERNAL_ERROR)->setData([
                'success' => false,
                'error' => [
                    'code' => 'QUOTE_SAVE_ERROR',
                    'message' => 'Could not load quote'
                ]
            ]);
        }
    }
}
