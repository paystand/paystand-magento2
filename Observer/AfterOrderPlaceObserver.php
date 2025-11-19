<?php
/**
 * PayStand Observer
 */
namespace PayStand\PayStandMagento\Observer;

use Magento\Framework\Event\ObserverInterface;
use PayStand\PayStandMagento\Model\Directpost;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class AfterOrderPlaceObserver implements ObserverInterface
{
    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var BuilderInterface
     */
    protected $_builderInterface;

    /**
     * @var InvoiceService
     */
    protected $_invoiceService;

    /**
     * @var TransactionFactory
     */
    protected $_transactionFactory;

    /**
     * @var InvoiceRepositoryInterface
     */
    protected $_invoiceRepository;

    /**
     * @var CollectionFactory
     */
    protected $_invoiceCollectionFactory;

    /**
     * @var QuoteFactory 
     */
    protected $_quoteFactory;

    /**
     * @var CartRepositoryInterface
     */
    protected $_cartRepository;

    /**
     * @var OrderRepositoryInterface
     */
    protected $_orderRepository;

    /**
     * PayStand configuration paths
     */
    const UPDATE_ORDER_ON = 'payment/paystandmagento/update_order_on';
    const STORE_SCOPE = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;

    /**
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param BuilderInterface $builderInterface
     * @param InvoiceService $invoiceService
     * @param TransactionFactory $transactionFactory
     * @param InvoiceRepositoryInterface $invoiceRepository
     * @param CollectionFactory $invoiceCollectionFactory
     * @param QuoteFactory $quoteFactory
     * @param CartRepositoryInterface $cartRepository
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        BuilderInterface $builderInterface,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        InvoiceRepositoryInterface $invoiceRepository,
        CollectionFactory $invoiceCollectionFactory,
        QuoteFactory $quoteFactory,
        CartRepositoryInterface $cartRepository,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->_logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->_builderInterface = $builderInterface;
        $this->_invoiceService = $invoiceService;
        $this->_transactionFactory = $transactionFactory;
        $this->_invoiceRepository = $invoiceRepository;
        $this->_invoiceCollectionFactory = $invoiceCollectionFactory;
        $this->_quoteFactory = $quoteFactory;
        $this->_cartRepository = $cartRepository;
        $this->_orderRepository = $orderRepository;
    }

    /**
     * Sets order status based on payment method and stored quote payment info
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getEvent()->getOrder();
        // Transfer paystand_adjustment from quote to order if present
        try {
            $quoteId = $order->getQuoteId();
            if ($quoteId) {
                try {
                    $quote = $this->_cartRepository->get($quoteId);
                } catch (\Exception $e) {
                    $quote = $this->_quoteFactory->create()->load($quoteId);
                }
                if ($quote && $quote->getId()) {
                    $paystandAdjustment = $quote->getData('paystand_adjustment');
                    $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: paystand_adjustment from quote is " . var_export($paystandAdjustment, true));
                    if ($paystandAdjustment !== null && $paystandAdjustment !== '') {
                        $adjustment = (float)$paystandAdjustment;

                        // 1) Update quote totals to include paystand_adjustment and save
                        try {
                            $quoteGrandTotal      = (float)$quote->getGrandTotal();
                            $quoteBaseGrandTotal  = (float)$quote->getBaseGrandTotal();
                            $quoteNewGrandTotal   = max(0.0, $quoteGrandTotal + $adjustment);
                            $quoteNewBaseGrand    = max(0.0, $quoteBaseGrandTotal + $adjustment);

                            $quote->setGrandTotal($quoteNewGrandTotal);
                            $quote->setBaseGrandTotal($quoteNewBaseGrand);

                            // Prevent re-collection from overwriting values if supported
                            if (method_exists($quote, 'setTriggerRecollect')) {
                                $quote->setTriggerRecollect(0);
                            }
                            if (method_exists($quote, 'setTotalsCollectedFlag')) {
                                $quote->setTotalsCollectedFlag(true);
                            }

                            $this->_cartRepository->save($quote);
                            $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Updated QUOTE totals with adjustment: grand_total {$quoteGrandTotal} -> {$quoteNewGrandTotal}, base_grand_total {$quoteBaseGrandTotal} -> {$quoteNewBaseGrand}");
                        } catch (\Exception $e) {
                            $this->_logger->error(">>>>> PAYSTAND-ORDER-OBSERVER: Failed updating quote totals: " . $e->getMessage());
                        }

                        // 2) Store custom field on order and update order totals
                        $order->setData('paystand_adjustment', $adjustment);

                        // Recalculate order grand totals to reconcile with displayed totals
                        $currentGrandTotal      = (float)$order->getGrandTotal();
                        $currentBaseGrandTotal  = (float)$order->getBaseGrandTotal();
                        $newGrandTotal          = max(0.0, $currentGrandTotal + $adjustment);
                        $newBaseGrandTotal      = max(0.0, $currentBaseGrandTotal + $adjustment); // assumes same currency rate

                        $order->setGrandTotal($newGrandTotal);
                        $order->setBaseGrandTotal($newBaseGrandTotal);

                        // Recompute due amounts (guard against negatives)
                        $totalPaid         = (float)$order->getTotalPaid();
                        $baseTotalPaid     = (float)$order->getBaseTotalPaid();
                        $order->setTotalDue(max(0.0, $newGrandTotal - $totalPaid));
                        $order->setBaseTotalDue(max(0.0, $newBaseGrandTotal - $baseTotalPaid));

                        $this->_orderRepository->save($order);
                        $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Set ORDER paystand_adjustment=" . $adjustment .
                            ", grand_total from {$currentGrandTotal} to {$newGrandTotal}, base_grand_total from {$currentBaseGrandTotal} to {$newBaseGrandTotal} for order " . $order->getIncrementId());

                        // 3) Update SALES_ORDER_PAYMENT amounts to reflect adjusted totals
                        try {
                            $paymentEntity = $order->getPayment();
                            if ($paymentEntity) {
                                $paymentEntity->setData('paystand_adjustment', $adjustment);
                                $paymentEntity->setAmountOrdered($newGrandTotal);
                                $paymentEntity->setBaseAmountOrdered($newBaseGrandTotal);
                                $paymentEntity->save();
                                $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Updated PAYMENT amounts: amount_ordered={$newGrandTotal}, base_amount_ordered={$newBaseGrandTotal}");
                            } else {
                                $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Payment entity not available to update ordered amounts");
                            }
                        } catch (\Exception $e) {
                            $this->_logger->error(">>>>> PAYSTAND-ORDER-OBSERVER: Failed updating payment ordered amounts: " . $e->getMessage());
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->_logger->error(">>>>> PAYSTAND-ORDER-OBSERVER: Failed to transfer paystand_adjustment from quote: " . $e->getMessage());
        }
        $observerStartTime = microtime(true);
        $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER-START: Observer triggered for order " . $order->getIncrementId() . " at " . date('Y-m-d H:i:s.u'));
        
        // Log initial order state for race condition diagnosis
        $initialState = $order->getState();
        $initialStatus = $order->getStatus();
        $this->_logger->debug(
            ">>>>> PAYSTAND-ORDER-OBSERVER-RACE-CONDITION: Initial order state: '{$initialState}', status: '{$initialStatus}'"
        );

        $payment = $order->getPayment();
        $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Payment method is: " . $payment->getMethod());

        if ($payment->getMethod() == Directpost::METHOD_CODE) {
            $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Payment method matches Paystand, continuing with processing");

            // Default state for new PayStand orders
            // CRITICAL: Set to pending and save immediately to prevent race condition with webhook
            // This must complete before webhook can process the order
            $beforeSetStateTime = microtime(true);
            $order->setState('pending');
            $order->setStatus('pending');
            $this->_logger->debug(
                ">>>>> PAYSTAND-ORDER-OBSERVER-RACE-CONDITION: Setting order to PENDING state. " .
                "Time since observer start: " . round(($beforeSetStateTime - $observerStartTime) * 1000, 2) . "ms"
            );
            
            // Save order immediately to ensure state is persisted before webhook can process it
            try {
                $this->_orderRepository->save($order);
                $saveTime = microtime(true);
                $saveDuration = round(($saveTime - $beforeSetStateTime) * 1000, 2);
                $totalDuration = round(($saveTime - $observerStartTime) * 1000, 2);
                
                // Verify the save was successful by reloading
                $savedOrder = $this->_orderRepository->get($order->getId());
                $savedState = $savedOrder->getState();
                $savedStatus = $savedOrder->getStatus();
                
                $this->_logger->debug(
                    ">>>>> PAYSTAND-ORDER-OBSERVER-RACE-CONDITION: Order state set to PENDING and saved immediately. " .
                    "Order ID: " . $order->getIncrementId() . 
                    ", Save duration: {$saveDuration}ms" .
                    ", Total observer time: {$totalDuration}ms" .
                    ", Verified state after save: '{$savedState}', status: '{$savedStatus}'"
                );
                
                if ($savedState != 'pending' && $savedState != Order::STATE_PENDING) {
                    $this->_logger->error(
                        ">>>>> PAYSTAND-ORDER-OBSERVER-RACE-CONDITION-ERROR: " .
                        "Order state verification failed! Expected 'pending', got '{$savedState}'"
                    );
                }
            } catch (\Exception $e) {
                $this->_logger->error(
                    ">>>>> PAYSTAND-ORDER-OBSERVER-RACE-CONDITION-ERROR: " .
                    "Failed to save order to pending state: " . $e->getMessage() . 
                    ", Stack trace: " . $e->getTraceAsString()
                );
            }

            // Check if the quote has already received payment information via webhook
            $quoteId = $order->getQuoteId();
            $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Order quote ID is: " . $quoteId);

            $quote = null;
            if ($quoteId) {
                try {
                    // Try to load quote using the repository first
                    try {
                        $quote = $this->_cartRepository->get($quoteId);
                        $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Successfully loaded quote from repository");
                    } catch (\Exception $e) {
                        // Fall back to the quote factory if repository fails
                        $quote = $this->_quoteFactory->create()->load($quoteId);
                        $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Loaded quote using factory method");
                    }

                    if ($quote && $quote->getId()) {
                        $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Found quote ID: " . $quote->getId());

                        // Get the payment object from the quote
                        $quotePayment = $quote->getPayment();
                        if ($quotePayment) {
                            $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Found payment for quote " . $quote->getId());

                            // Check for payment information in the additional_information field
                            $paymentStatus = $quotePayment->getAdditionalInformation('paystand_payment_status');
                            $paymentId = $quotePayment->getAdditionalInformation('paystand_payment_id');
                            $paymentData = $quotePayment->getAdditionalInformation('paystand_payment_data');

                            $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Payment status from additional info: " . ($paymentStatus ?: 'null'));
                            $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Payment ID from additional info: " . ($paymentId ?: 'null'));

                            if ($paymentStatus) {
                                $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Found payment status '{$paymentStatus}' on quote payment " . $quote->getId());

                                // Get configured updateOrderOn value
                                $updateOrderOn = $this->scopeConfig->getValue(self::UPDATE_ORDER_ON, self::STORE_SCOPE);
                                $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Configured updateOrderOn value is '{$updateOrderOn}'");

                                // Apply payment information to the order if status exactly matches configuration
                                if ($paymentStatus == $updateOrderOn) {
                                    $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Setting order " . $order->getIncrementId() . " to processing");
                                    $order->setState(Order::STATE_PROCESSING);
                                    $order->setStatus(Order::STATE_PROCESSING);

                                    // Add payment information to order history
                                    $order->addStatusHistoryComment(
                                        __('Payment already received via PayStand webhook. Payment status: %1, Payment ID: %2', 
                                        $paymentStatus, 
                                        $paymentId)
                                    );

                                    // Set payment transaction ID if available
                                    if ($paymentId) {
                                        $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Found payment ID " . $paymentId);
                                        $payment->setTransactionId($paymentId);
                                        $payment->setLastTransId($paymentId);
                                        $payment->setIsTransactionClosed(0);

                                        // Transfer payment additional info to order payment
                                        if ($paymentData) {
                                            $paymentDataArray = json_decode($paymentData, true);
                                            if ($paymentDataArray) {
                                                // Copy the additional information to the order payment
                                                $payment->setAdditionalInformation('paystand_payment_status', $paymentStatus);
                                                $payment->setAdditionalInformation('paystand_payment_id', $paymentId);

                                                // Store only essential information as individual fields instead of the entire JSON
                                                if (isset($paymentDataArray['id'])) {
                                                    $payment->setAdditionalInformation('paystand_transaction_id', $paymentDataArray['id']);
                                                }
                                                if (isset($paymentDataArray['status'])) {
                                                    $payment->setAdditionalInformation('paystand_status', $paymentDataArray['status']);
                                                }
                                                if (isset($paymentDataArray['amount'])) {
                                                    $payment->setAdditionalInformation('paystand_amount', $paymentDataArray['amount']);
                                                }
                                                if (isset($paymentDataArray['currency'])) {
                                                    $payment->setAdditionalInformation('paystand_currency', $paymentDataArray['currency']);
                                                }

                                                // DO NOT store the entire JSON string as it causes issues in OrderRepository
                                                // $payment->setAdditionalInformation('paystand_payment_data', $paymentData);
                                            }
                                        }

                                        // IMPORTANT: Save the order before creating transactions and invoices
                                        // This ensures the order exists in the database before we create related records
                                        try {
                                            $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Saving order before creating transaction and invoice");
                                            $this->_orderRepository->save($order);
                                        } catch (\Exception $e) {
                                            $this->_logger->error(">>>>> PAYSTAND-ORDER-OBSERVER: Error saving order: " . $e->getMessage());
                                            return;
                                        }

                                        // Now create transaction and invoice after the order is saved
                                        if ($paymentData) {
                                            try {
                                                $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Found payment data, attempting to create transaction and invoice");
                                                $paymentDataArray = json_decode($paymentData, true);
                                                if ($paymentDataArray) {
                                                    // Create transaction
                                                    $transactionId = $this->createTransaction($order, $paymentDataArray);
                                                    if ($transactionId) {
                                                        $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Transaction created with ID: " . $transactionId);

                                                        // Only attempt to create invoice if transaction was successful
                                                        // Create invoice
                                                        $invoice = $this->createInvoice($order);
                    if ($invoice) {
                        $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Invoice created with ID: " . $invoice->getEntityId());
                                                        } else {
                                                            $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Invoice creation failed");
                                                        }
                                                    } else {
                                                        $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Transaction creation failed");
                                                    }
                                                } else {
                                                    $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Payment data could not be decoded from JSON");
                                                }
                                            } catch (\Exception $e) {
                                                $this->_logger->error(">>>>> PAYSTAND-ORDER-OBSERVER: Error creating transaction/invoice: " . $e->getMessage());
                                            }
                                        } else {
                                            $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: No payment data found on quote payment");
                                        }
                                    } else {
                                        $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: No payment ID found on quote payment");
                                    }
                                } else {
                                    $this->_logger->debug(
                                        ">>>>> PAYSTAND-ORDER-OBSERVER: Payment status '{$paymentStatus}' doesn't match updateOrderOn '{$updateOrderOn}', not changing order status"
                                    );
                                }
                            } else {
                                $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: No payment status found in payment additional information");
                            }
                        } else {
                            $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: No payment found for quote");
                        }
                    } else {
                        $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Quote was found but is not valid");
                    }
                } catch (\Exception $e) {
                    $this->_logger->error(">>>>> PAYSTAND-ORDER-OBSERVER: Error loading quote: " . $e->getMessage());
                }
            } else {
                $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: No quote ID found in order " . $order->getIncrementId());
            }
        } else {
            $this->_logger->debug(">>>>> PAYSTAND-ORDER-OBSERVER: Not a Paystand payment method, skipping");
        }

        $observerEndTime = microtime(true);
        $totalTime = round(($observerEndTime - $observerStartTime) * 1000, 2);
        $this->_logger->debug(
            ">>>>> PAYSTAND-ORDER-OBSERVER-END: Finished processing for order " . $order->getIncrementId() . 
            " at " . date('Y-m-d H:i:s.u') . 
            ", Total time: {$totalTime}ms"
        );
    }

    /**
     * Create transaction for order
     * 
     * @param Order $order
     * @param array $paymentData
     * @return string|null
     */
    private function createTransaction($order, $paymentData)
    {
        try {
            //get payment object from order object
            $this->_logger->debug('>>>>> PAYSTAND-CREATE-TRANSACTION-START');

            // Double-check that order exists and has an ID to prevent foreign key issues
            if (!$order->getId()) {
                $this->_logger->error('>>>>> PAYSTAND-CREATE-TRANSACTION-ERROR: Order does not have an ID yet');
                return null;
            }

            $payment = $order->getPayment();
            if (!$payment) {
                $this->_logger->error('>>>>> PAYSTAND-CREATE-TRANSACTION-ERROR: Payment object not found');
                return null;
            }

            $payment->setLastTransId($paymentData['id']);
            $payment->setTransactionId($paymentData['id']);

            // Instead of setting the raw details as the entire payment data object,
            // create a filtered array with only essential information
            $essentialPaymentInfo = [];
            $essentialFields = ['id', 'status', 'amount', 'currency', 'settlementAmount', 'settlementCurrency'];
            foreach ($essentialFields as $field) {
                if (isset($paymentData[$field])) {
                    $essentialPaymentInfo[$field] = $paymentData[$field];
                }
            }

            // Store payer info separately
            if (isset($paymentData['payerId'])) {
                $essentialPaymentInfo['payerId'] = $paymentData['payerId'];
            }

            // Store meta info if available
            if (isset($paymentData['meta']) && is_array($paymentData['meta'])) {
                $essentialPaymentInfo['meta'] = $paymentData['meta'];
            }

            $payment->setAdditionalInformation(
                [\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => $essentialPaymentInfo]
            );

            // Formated price
            $formatedPrice = $order->getBaseCurrency()->formatTxt(
                $order->getGrandTotal()
            );

            // Create a message with information from essentialPaymentInfo
            $message = sprintf(
                'Amount: %s<br/>Paystand Payment ID: %s<br/>Paystand Payer ID: %s<br/>',
                $formatedPrice,
                $essentialPaymentInfo['id'] ?? '',
                $essentialPaymentInfo['payerId'] ?? ''
            );

            // Add quote info if available
            if (isset($essentialPaymentInfo['meta']) && isset($essentialPaymentInfo['meta']['quote'])) {
                $message .= sprintf('Magento quote ID: %s<br/>', $essentialPaymentInfo['meta']['quote']);
            }

            //get the object of builder class
            $trans = $this->_builderInterface;
            $transaction = $trans->setPayment($payment)
                ->setOrder($order)
                ->setTransactionId($paymentData['id'])
                ->setAdditionalInformation(
                    [\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS
                        => $essentialPaymentInfo]
                )
                ->setFailSafe(true)
                //build method creates the transaction and returns the object
                ->build(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE);

            $payment->addTransactionCommentsToOrder(
                $transaction,
                $message
            );
            $payment->setParentTransactionId(null);

            // Save the transaction first, then the payment, then the order
            $transactionId = $transaction->save()->getTransactionId();
            $payment->save();
            $order->save();

            $this->_logger->debug('>>>>> PAYSTAND-CREATE-TRANSACTION-FINISH: transactionId: ' . $transactionId);
            return $transactionId;
        } catch (\Exception $e) {
            $this->_logger->error('>>>>> PAYSTAND-EXCEPTION: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create invoice for order
     * 
     * @param Order $order
     * @return \Magento\Sales\Model\Order\Invoice|null
     */
    private function createInvoice($order)
    {
        try {
            $this->_logger->debug('>>>>> PAYSTAND-CREATE-INVOICE-START');

            // Double-check that order exists and has an ID to prevent foreign key issues
            if (!$order->getId()) {
                $this->_logger->error('>>>>> PAYSTAND-CREATE-INVOICE-ERROR: Order does not have an ID yet');
                return null;
            }

            if ($order) {
                $invoices = $this->_invoiceCollectionFactory->create()
                    ->addAttributeToFilter('order_id', ['eq' => $order->getId()]);

                $invoices->getSelect()->limit(1);

                if ((int)$invoices->count() !== 0) {
                    $invoices = $invoices->getFirstItem();
                    $invoice = $this->_invoiceRepository->get($invoices->getId());
                    return $invoice;
                }

                if (!$order->canInvoice()) {
                    $this->_logger->debug('>>>>> PAYSTAND-CREATE-INVOICE: Order cannot be invoiced');
                    return null;
                }

                $invoice = $this->_invoiceService->prepareInvoice($order);

                // Transfer paystand_adjustment from order to invoice if present
                $paystandAdjustment = (float)$order->getData('paystand_adjustment');
                if ($paystandAdjustment !== 0.0) {
                    $invoice->setData('paystand_adjustment', $paystandAdjustment);
                    
                    // Update invoice grand totals to include the adjustment
                    $currentGrandTotal = (float)$invoice->getGrandTotal();
                    $currentBaseGrandTotal = (float)$invoice->getBaseGrandTotal();
                    $newGrandTotal = max(0.0, $currentGrandTotal + $paystandAdjustment);
                    $newBaseGrandTotal = max(0.0, $currentBaseGrandTotal + $paystandAdjustment);
                    
                    $invoice->setGrandTotal($newGrandTotal);
                    $invoice->setBaseGrandTotal($newBaseGrandTotal);
                    
                    $this->_logger->debug(">>>>> PAYSTAND-CREATE-INVOICE: Added paystand_adjustment={$paystandAdjustment} to invoice, grand_total from {$currentGrandTotal} to {$newGrandTotal}");
                }

                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                $invoice->register();
                $invoice->getOrder()->setCustomerNoteNotify(false);
                $invoice->getOrder()->setIsInProcess(true);
                $order->addStatusHistoryComment(__('Automatically INVOICED by Paystand'), false);
                
                // Update order totals to reflect the paystand_adjustment in invoice totals
                if ($paystandAdjustment !== 0.0) {
                    $currentTotalInvoiced = (float)$order->getTotalInvoiced();
                    $currentBaseTotalInvoiced = (float)$order->getBaseTotalInvoiced();
                    $currentTotalPaid = (float)$order->getTotalPaid();
                    $currentBaseTotalPaid = (float)$order->getBaseTotalPaid();
                    
                    $newTotalInvoiced = $currentTotalInvoiced + $paystandAdjustment;
                    $newBaseTotalInvoiced = $currentBaseTotalInvoiced + $paystandAdjustment;
                    $newTotalPaid = $currentTotalPaid + $paystandAdjustment;
                    $newBaseTotalPaid = $currentBaseTotalPaid + $paystandAdjustment;
                    
                    $order->setTotalInvoiced($newTotalInvoiced);
                    $order->setBaseTotalInvoiced($newBaseTotalInvoiced);
                    $order->setTotalPaid($newTotalPaid);
                    $order->setBaseTotalPaid($newBaseTotalPaid);
                    
                    $this->_logger->debug(">>>>> PAYSTAND-CREATE-INVOICE: Updated order invoice totals - total_invoiced from {$currentTotalInvoiced} to {$newTotalInvoiced}, total_paid from {$currentTotalPaid} to {$newTotalPaid}");
                    
                    // Update payment amounts to include the adjustment
                    $payment = $order->getPayment();
                    if ($payment) {
                        $currentAmountPaid = (float)$payment->getAmountPaid();
                        $currentBaseAmountPaid = (float)$payment->getBaseAmountPaid();
                        
                        $newAmountPaid = $currentAmountPaid + $paystandAdjustment;
                        $newBaseAmountPaid = $currentBaseAmountPaid + $paystandAdjustment;
                        
                        $payment->setAmountPaid($newAmountPaid);
                        $payment->setBaseAmountPaid($newBaseAmountPaid);
                        
                        $this->_logger->debug(">>>>> PAYSTAND-CREATE-INVOICE: Updated payment amounts - amount_paid from {$currentAmountPaid} to {$newAmountPaid}, base_amount_paid from {$currentBaseAmountPaid} to {$newBaseAmountPaid}");
                    }
                }

                // Create transaction to save invoice and order
                $transactionSave = $this->_transactionFactory
                    ->create()
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder());
                $transactionSave->save();

                $this->_logger->debug('>>>>> PAYSTAND-CREATE-INVOICE-FINISH: invoiceId: ' . $invoice->getEntityId());
                return $invoice;
            }
        } catch (\Exception $e) {
            $this->_logger->error('>>>>> PAYSTAND-EXCEPTION: ' . $e->getMessage());
            return null;
        }
        return null;
    }
}
