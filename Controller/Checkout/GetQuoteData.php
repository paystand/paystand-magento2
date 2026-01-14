<?php
/**
 * Controller to fetch current quote data for Paystand checkout
 * Returns quote totals, billing address, and customer information as JSON
 */
declare(strict_types=1);

namespace PayStand\PayStandMagento\Controller\Checkout;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

class GetQuoteData implements HttpGetActionInterface, HttpPostActionInterface
{
    private JsonFactory $resultJsonFactory;
    private CheckoutSession $checkoutSession;
    private CustomerSession $customerSession;
    private CustomerRepositoryInterface $customerRepository;
    private LoggerInterface $logger;

    public function __construct(
        JsonFactory $resultJsonFactory,
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        CustomerRepositoryInterface $customerRepository,
        LoggerInterface $logger
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
    }

    /**
     * Execute action to get quote data
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            $quote = $this->checkoutSession->getQuote();

            if (!$quote || !$quote->getId()) {
                return $result->setData([
                    'success' => false,
                    'message' => 'No active quote found'
                ]);
            }

            // Get quote totals
            $totals = $quote->getTotals();
            $totalsData = [];
            foreach ($totals as $total) {
                $totalsData[$total->getCode()] = [
                    'title' => $total->getTitle(),
                    'value' => $total->getValue()
                ];
            }

            // Get billing address
            $billingAddress = $quote->getBillingAddress();
            $billingData = [];
            if ($billingAddress) {
                $billingData = [
                    'firstname' => $billingAddress->getFirstname(),
                    'lastname' => $billingAddress->getLastname(),
                    'email' => $billingAddress->getEmail() ?: $quote->getCustomerEmail(),
                    'street' => $billingAddress->getStreet(),
                    'city' => $billingAddress->getCity(),
                    'region' => $billingAddress->getRegion(),
                    'region_code' => $billingAddress->getRegionCode(),
                    'postcode' => $billingAddress->getPostcode(),
                    'country_id' => $billingAddress->getCountryId(),
                    'telephone' => $billingAddress->getTelephone()
                ];
            }

            // Get customer data
            $isLoggedIn = $this->customerSession->isLoggedIn();
            
            $customerData = [
                'isLoggedIn' => $isLoggedIn,
                'email' => null,
                'id' => null,
                'payerId' => null
            ];

            // Get Paystand payer ID if customer is logged in
            if ($isLoggedIn) {
                $customerId = $this->customerSession->getCustomerId();
                if ($customerId) {
                    try {
                        // Use CustomerRepository to get fresh customer data with all attributes
                        $customer = $this->customerRepository->getById($customerId);
                        
                        $customerData['email'] = $customer->getEmail();
                        $customerData['id'] = $customer->getId();
                        
                        // Get paystand_payer_id attribute
                        $payerIdAttribute = $customer->getCustomAttribute('paystand_payer_id');
                        if ($payerIdAttribute && $payerIdAttribute->getValue()) {
                            $customerData['payerId'] = $payerIdAttribute->getValue();
                            $this->logger->info('[Paystand GetQuoteData] Found payer ID for customer', [
                                'customer_id' => $customerId,
                                'payer_id' => $payerIdAttribute->getValue()
                            ]);
                        } else {
                            $this->logger->info('[Paystand GetQuoteData] No payer ID found for customer', [
                                'customer_id' => $customerId
                            ]);
                        }
                    } catch (\Exception $e) {
                        $this->logger->error('[Paystand GetQuoteData] Error loading customer', [
                            'customer_id' => $customerId,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            } else {
                // Guest user - get email from billing address
                $customerData['email'] = $billingAddress ? $billingAddress->getEmail() : null;
            }

            // Build response
            $response = [
                'success' => true,
                'quote' => [
                    'id' => $quote->getId(),
                    'grand_total' => $quote->getGrandTotal(),
                    'base_grand_total' => $quote->getBaseGrandTotal(),
                    'subtotal' => $quote->getSubtotal(),
                    'currency_code' => $quote->getQuoteCurrencyCode(),
                    'items_count' => $quote->getItemsCount(),
                    'items_qty' => $quote->getItemsQty(),
                    'totals' => $totalsData
                ],
                'billing' => $billingData,
                'customer' => $customerData
            ];

            return $result->setData($response);

        } catch (NoSuchEntityException $e) {
            $this->logger->error('[Paystand] Quote not found: ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'message' => 'Quote not found'
            ]);
        } catch (LocalizedException $e) {
            $this->logger->error('[Paystand] Error getting quote data: ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[Paystand] Unexpected error: ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'message' => 'An error occurred while fetching quote data'
            ]);
        }
    }
}

