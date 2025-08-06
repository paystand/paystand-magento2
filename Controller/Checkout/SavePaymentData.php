<?php

namespace PayStand\PayStandMagento\Controller\Checkout;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Psr\Log\LoggerInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use PayStand\PayStandMagento\Helper\CustomerPayerId;
use Magento\Quote\Api\CartRepositoryInterface;

class SavePaymentData extends Action
{
    protected $logger;
    protected $resultJsonFactory;
    protected $customerPayerIdHelper;
    protected $cartRepository;
    
    public function __construct(
        Context $context,
        LoggerInterface $logger,
        JsonFactory $resultJsonFactory,
        CustomerPayerId $customerPayerIdHelper,
        CartRepositoryInterface $cartRepository
    ) {
        $this->logger = $logger;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->customerPayerIdHelper = $customerPayerIdHelper;
        $this->cartRepository = $cartRepository;
        parent::__construct($context);
    }
    
    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $rawInput = file_get_contents('php://input');
        
        // Decode JSON and get each property separately
        $data = json_decode($rawInput, true);
        
        if (!$data) {
            $this->logger->error('SAVEPAYMENTDATA >>>>>> Invalid JSON received');
            return $result->setData(['success' => true, 'error' => 'Invalid JSON']);
        }
        
        $payerId = $data['payerId'] ?? null;
        $quoteId = $data['quote'] ?? null;
        
        if (!$payerId || !$quoteId) {
            $this->logger->error('SAVEPAYMENTDATA >>>>>> Missing payerId or quote');
            return $result->setData(['success' => true, 'error' => 'Missing required data']);
        }
        
        try {
            // Load the quote using the ID
            $quote = $this->cartRepository->get($quoteId);
            
            // Check if user is guest or customer
            $isGuest = $quote->getCustomerIsGuest();
            
            if ($isGuest == 1) {
                // Is guest, return success but don't execute further logic
                return $result->setData(['success' => true, 'type' => 'guest']);
            } else {
                $this->logger->debug('SAVEPAYMENTDATA >>>>>> IS_CUSTOMER for quote: ' . $quoteId);
                
                // Get customer ID from quote
                $customerId = $quote->getCustomerId();
                
                return $result->setData(['success' => true, 'type' => 'customer', 'customer_id' => $customerId]);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('SAVEPAYMENTDATA >>>>>> Error loading quote: ' . $e->getMessage());
            // Even if it fails, return success as requested
            return $result->setData(['success' => true, 'error' => 'Could not load quote']);
        }
    }
}





