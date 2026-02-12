<?php

namespace PayStand\PayStandMagento\Controller\Checkout;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Psr\Log\LoggerInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use PayStand\PayStandMagento\Helper\CustomerPayerId;
use PayStand\PayStandMagento\Helper\TelemetryClient;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Framework\Exception\NoSuchEntityException;
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

    /** @var QuoteIdMaskFactory */
    protected $quoteIdMaskFactory;

    /** @var ScopeConfigInterface */
    protected $scopeConfig;

    /** @var TelemetryClient */
    protected $telemetryClient;

    /**
     * PayStand configuration path
     */
    const ENABLE_PAYSTAND_ADJUSTMENT = 'payment/paystandmagento/enable_paystand_adjustment';

    /**
     * @param Context $context
     * @param LoggerInterface $logger
     * @param JsonFactory $resultJsonFactory
     * @param CustomerPayerId $customerPayerIdHelper
     * @param CartRepositoryInterface $cartRepository
     * @param QuoteIdMaskFactory $quoteIdMaskFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param TelemetryClient $telemetryClient
     */
    public function __construct(
        Context $context,
        LoggerInterface $logger,
        JsonFactory $resultJsonFactory,
        CustomerPayerId $customerPayerIdHelper,
        CartRepositoryInterface $cartRepository,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        ScopeConfigInterface $scopeConfig,
        TelemetryClient $telemetryClient
    ) {
        $this->logger = $logger;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->customerPayerIdHelper = $customerPayerIdHelper;
        $this->cartRepository = $cartRepository;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->scopeConfig = $scopeConfig;
        $this->telemetryClient = $telemetryClient;
        parent::__construct($context);
    }

    /**
     * Resolve a real numeric quote_id from an incoming ID.
     * If the incoming value is numeric, it is returned as-is.
     * If it is a masked id (string), it is translated to the underlying quote_id.
     *
     * @param string|int $incomingId
     * @return int
     * @throws NoSuchEntityException when the masked ID cannot be resolved
     */
    private function resolveRealQuoteId($incomingId): int
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
            $this->logger->error('[MAGENTO-MONITORING]: Event checkout.payment_data.failed - Error: Invalid JSON');
            
            // Send telemetry metric
            $this->telemetryClient->sendPluginError(
                'checkout',
                'Payment data save failed - Invalid JSON',
                ['event' => 'checkout.payment_data.failed', 'error' => 'Invalid JSON']
            );
            
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

        if (!$payerId || !$quoteIdIncoming) {
            $this->logger->error('SAVEPAYMENTDATA >>>>>> Missing payerId or quote');
            $this->logger->error('[MAGENTO-MONITORING]: Event checkout.payment_data.failed - Error: Missing required data');
            
            // Send telemetry metric
            $this->telemetryClient->sendPluginError(
                'checkout',
                'Payment data save failed - Missing required data',
                ['event' => 'checkout.payment_data.failed', 'error' => 'Missing required data']
            );
            
            return $result->setHttpResponseCode(Response::HTTP_BAD_REQUEST)->setData([
                'success' => false,
                'error' => [
                    'code' => 'MISSING_REQUIRED_DATA',
                    'message' => 'Missing required data'
                ]
            ]);
        }

        try {
            // 1) Resolve real quote id (supports guest masked IDs and numeric IDs)
            $realQuoteId = $this->resolveRealQuoteId($quoteIdIncoming);

            // 2) Load quote (use get() to support possibly inactive quotes after order placement)
            $quote = $this->cartRepository->get($realQuoteId);

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

            // 5) Persist only the adjustment on the quote; totals will be updated in the PayStand observer
            $quote->setData('paystand_adjustment', $paystandAdjustment);
            $this->cartRepository->save($quote);

            if ($isAdjustmentEnabled) {
            $this->logger->info('SAVEPAYMENTDATA >>>>>> Saved paystand_adjustment to quote', [
                'quote_id'            => $realQuoteId,
                'incoming_quote'      => $quoteIdIncoming,
                'paystand_adjustment' => $paystandAdjustment
            ]);
        }
        $this->logger->info('[MAGENTO-MONITORING]: Event checkout.payment_data.saved - Quote ID: ' . $realQuoteId . ', Payer ID: ' . $payerId);
        
        // Send telemetry log
        $this->telemetryClient->sendLog(
            'Payment data saved successfully',
            [
                'event' => 'checkout.payment_data.saved',
                'quote_id' => $realQuoteId,
                'payer_id' => $payerId
            ],
            'info'
        );

            // 6) Branch by guest vs. customer
            $isGuest = (int)$quote->getCustomerIsGuest() === 1;

            if ($isGuest) {
                // Guest flow: do not attempt to store payerId on a customer
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

        } catch (NoSuchEntityException $e) {
            // Masked id could not be resolved or quote not found
            $this->logger->error(
                'SAVEPAYMENTDATA >>>>>> Quote not found / masked id invalid: ' . $e->getMessage(),
                ['incoming_quote' => $quoteIdIncoming]
            );
            $this->logger->error('[MAGENTO-MONITORING]: Event checkout.payment_data.failed - Error: Quote not found, Quote ID: ' . $quoteIdIncoming);
            
            // Send telemetry metric
            $this->telemetryClient->sendPluginError(
                'checkout',
                'Payment data save failed - Quote not found',
                [
                    'event' => 'checkout.payment_data.failed',
                    'quote_id' => $quoteIdIncoming,
                    'error' => 'Quote not found'
                ]
            );
            
            return $result->setHttpResponseCode(Response::HTTP_NOT_FOUND)->setData([
                'success' => false,
                'error' => [
                    'code' => 'QUOTE_NOT_FOUND',
                    'message' => 'Could not load quote'
                ]
            ]);
        } catch (\Exception $e) {
            $this->logger->error(
                'SAVEPAYMENTDATA >>>>>> Error loading quote: ' . $e->getMessage(),
                ['incoming_quote' => $quoteIdIncoming]
            );
            $this->logger->error('[MAGENTO-MONITORING]: Event checkout.payment_data.failed - Error: ' . $e->getMessage() . ', Quote ID: ' . $quoteIdIncoming);
            
            // Send telemetry metric
            $this->telemetryClient->sendPluginError(
                'checkout',
                'Payment data save failed - Error loading quote',
                [
                    'event' => 'checkout.payment_data.failed',
                    'quote_id' => $quoteIdIncoming,
                    'error' => $e->getMessage()
                ]
            );
            
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
