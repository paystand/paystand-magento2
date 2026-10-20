<?php

namespace PayStand\PayStandMagento\Controller\Checkout;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Psr\Log\LoggerInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use PayStand\PayStandMagento\Helper\CustomerPayerId;
use PayStand\PayStandMagento\Helper\CloudLogger;
use PayStand\PayStandMagento\Helper\QuoteAccess;
use PayStand\PayStandMagento\Helper\CaptureSnapshot;
use PayStand\PayStandMagento\Helper\QuoteShipping;
use PayStand\PayStandMagento\Model\Config\Source\PaymentStatus;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Webapi\Response;

/**
 * Saves the Paystand payment result on the quote: paystand_adjustment (negative for a
 * payer discount, positive for payer fees), the payment id and the capture status.
 * For a logged-in customer, it also stores the payer id on the customer.
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

    /** @var CaptureSnapshot */
    protected $captureSnapshot;

    /** @var ScopeConfigInterface */
    protected $scopeConfig;

    /**
     * PayStand configuration path
     */
    const ENABLE_PAYSTAND_ADJUSTMENT = 'payment/paystandmagento/enable_paystand_adjustment';

    /**
     * Paystand payment ids are alphanumeric, 24 chars today. The range allows a format
     * change but rejects input that is not a payment id.
     */
    const PAYMENT_ID_PATTERN = '/^[a-z0-9]{16,64}$/i';

    /**
     * Statuses that mark a confirmed capture, shared with the webhook rescue.
     */
    const CAPTURED_STATUSES = PaymentStatus::CAPTURED_STATUSES;

    /**
     * Write only the Paystand columns. A full quote save would recollect totals.
     *
     * @param \Magento\Quote\Model\Quote $quote
     */
    private function persistPaystandMarkers($quote): void
    {
        $resource = $quote->getResource();
        $connection = $resource->getConnection();
        $connection->update(
            $resource->getMainTable(),
            [
                'paystand_adjustment' => $quote->getData('paystand_adjustment'),
                'paystand_payment_id' => $quote->getData('paystand_payment_id'),
                'paystand_capture_status' => $quote->getData('paystand_capture_status'),
                'paystand_capture_snapshot' => $quote->getData(CaptureSnapshot::QUOTE_FIELD),
            ],
            ['entity_id = ?' => (int)$quote->getId()]
        );
    }

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
        ScopeConfigInterface $scopeConfig,
        CaptureSnapshot $captureSnapshot
    ) {
        $this->logger = $logger;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->customerPayerIdHelper = $customerPayerIdHelper;
        $this->cartRepository = $cartRepository;
        $this->quoteAccess = $quoteAccess;
        $this->quoteShipping = $quoteShipping;
        $this->scopeConfig = $scopeConfig;
        $this->captureSnapshot = $captureSnapshot;
        parent::__construct($context);
    }

    /**
     * Controller entrypoint.
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
        // Stops checkout from reopening the widget for a cart already paid.
        $paymentId       = $data['paymentId'] ?? null;
        // Only a confirmed capture stamps the snapshot that pins the paid totals.
        $paymentStatus   = $data['paymentStatus'] ?? null;
        // False when the window was priced from browser totals because
        // getquotedata failed. Older checkout JS does not send it.
        $windowPricedByServer = $data['windowPricedByServer'] ?? null;

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
            // Load the quote only if this session may write to it. QuoteAccess fails closed.
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

            // Before the payment id lands: clearing checks for one.
            $this->dropSnapshotFromEarlierOpen($quote, $windowPricedByServer, $paymentId);

            // Check if paystand adjustment is enabled
            $isAdjustmentEnabled = $this->scopeConfig->isSetFlag(
                self::ENABLE_PAYSTAND_ADJUSTMENT,
                ScopeInterface::SCOPE_STORE
            );

            // When enabled: a payer discount is stored negative, else payer fees positive.
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

            // The observer adds the adjustment to the totals. The first payment id is kept; a
            // second, different id signals a duplicate payment and is only logged.
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

            // Only a confirmed capture with a payment id is recorded. A payment that never
            // completed must not pin its totals onto a live cart.
            $captureStatus = $this->captureStatusFor($paymentId, $paymentStatus);
            if ($captureStatus !== null) {
                $quote->setData('paystand_capture_status', $captureStatus);
            } else {
                if (!empty($paymentId) && !empty($paymentStatus)) {
                    $this->logger->info('SAVEPAYMENTDATA >>>>>> Payment status is not a capture, totals stay live', [
                        'quote_id'       => $realQuoteId,
                        'payment_status' => strtolower(trim((string)$paymentStatus))
                    ]);
                }
                $this->preserveCaptureStatus($quote, $realQuoteId);
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

            // Save without a recollect, so the paid totals and address stay as they are.
            $this->captureSnapshot->stampPaidWithoutRecollect($quote);
            $this->persistPaystandMarkers($quote);

            if ($isAdjustmentEnabled) {
                $this->logger->info('SAVEPAYMENTDATA >>>>>> Saved paystand_adjustment to quote', [
                    'quote_id'            => $realQuoteId,
                    'incoming_quote'      => $quoteIdIncoming,
                    'paystand_adjustment' => $paystandAdjustment
                ]);
            }

            // Branch by guest vs. customer
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

    /**
     * The confirmed capture status to record, or null. It needs a payment id too.
     *
     * @param string|null $paymentId
     * @param string|null $paymentStatus
     * @return string|null
     */
    private function captureStatusFor($paymentId, $paymentStatus)
    {
        if (empty($paymentId) || empty($paymentStatus)) {
            return null;
        }

        $status = strtolower(trim((string)$paymentStatus));

        return in_array($status, self::CAPTURED_STATUSES, true) ? $status : null;
    }

    /**
     * When getquotedata did not price this window, the snapshot is from an earlier open, so
     * it is dropped and the paid cart is stamped instead. No flag (older JS) changes nothing,
     * and a quote holding another payment is left as is.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param mixed $windowPricedByServer
     * @param mixed $paymentId The payment this request reports
     * @return void
     */
    private function dropSnapshotFromEarlierOpen($quote, $windowPricedByServer, $paymentId)
    {
        if ($windowPricedByServer === false) {
            $this->captureSnapshot->clearCheckoutIntent($quote, (string)$paymentId);
        }
    }

    /**
     * Copies a capture status recorded since load onto this copy, so saving it keeps it.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param int|string $quoteId
     * @return void
     */
    private function preserveCaptureStatus($quote, $quoteId)
    {
        try {
            if (!empty($quote->getData('paystand_capture_status'))) {
                return;
            }

            // Uses the quote's own connection, so no extra dependency is needed.
            $resource = $quote->getResource();
            $connection = $resource->getConnection();
            $select = $connection->select()
                ->from($resource->getMainTable(), 'paystand_capture_status')
                ->where('entity_id = ?', $quoteId);
            $persisted = $connection->fetchOne($select);

            if (!empty($persisted)) {
                $quote->setData('paystand_capture_status', $persisted);
                $this->logger->info('SAVEPAYMENTDATA >>>>>> Kept capture status recorded since load', [
                    'quote_id'       => $quoteId,
                    'capture_status' => $persisted
                ]);
            }
        } catch (\Throwable $e) {
            // A failed read must not break the payment flow; the worst case is the
            // status this request already held being saved as it was loaded.
            $this->logger->error('SAVEPAYMENTDATA >>>>>> Could not re-read capture status: ' . $e->getMessage());
        }
    }
}
