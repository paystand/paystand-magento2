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
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\ResourceModel\Quote\Address as QuoteAddressResource;
use PayStand\PayStandMagento\Helper\CaptureSnapshot;
use PayStand\PayStandMagento\Helper\CloudLogger;
use PayStand\PayStandMagento\Helper\QuoteShipping;
use Psr\Log\LoggerInterface;

class GetQuoteData implements HttpGetActionInterface, HttpPostActionInterface
{
    private JsonFactory $resultJsonFactory;
    private CheckoutSession $checkoutSession;
    private CustomerSession $customerSession;
    private CustomerRepositoryInterface $customerRepository;
    private LoggerInterface $logger;
    private QuoteShipping $quoteShipping;
    private CaptureSnapshot $captureSnapshot;
    private AddressRepositoryInterface $addressRepository;
    private QuoteAddressResource $quoteAddressResource;

    public function __construct(
        JsonFactory $resultJsonFactory,
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        CustomerRepositoryInterface $customerRepository,
        LoggerInterface $logger,
        QuoteShipping $quoteShipping,
        CaptureSnapshot $captureSnapshot,
        AddressRepositoryInterface $addressRepository,
        QuoteAddressResource $quoteAddressResource
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
        $this->quoteShipping = $quoteShipping;
        $this->captureSnapshot = $captureSnapshot;
        $this->addressRepository = $addressRepository;
        $this->quoteAddressResource = $quoteAddressResource;
    }

    /**
     * Logs the shipping selection before and after the recollect, to show when a rate is lost.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param string $before describe() output taken before recollecting
     */
    private function shipShippingBreadcrumb($quote, string $before, bool $restored, bool $retryFailed): void
    {
        try {
            $after = $this->quoteShipping->describe($quote);
            if (!$restored && $before === $after) {
                // Nothing changed — don't spend an event on the common case.
                return;
            }
            // RESTORED-NO-RATE means the method is back but nothing can price it,
            // so the cart is still unplaceable — the queue worth alerting on.
            $marker = $restored ? ($retryFailed ? ' RESTORED-NO-RATE' : ' RESTORED') : '';
            CloudLogger::ship(CloudLogger::EVENT_QUOTE_SHIPPING_STATE, [
                'quote_id'      => (string)$quote->getId(),
                'error_message' => 'getquotedata before[' . $before . '] after[' . $after . ']' . $marker,
            ]);
        } catch (\Throwable $e) {
            // CloudLogger failure — silently ignored to protect the payment flow
        }
    }

    /**
     * Restore quote addresses from the saved address they point at, if this customer owns it.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return \Magento\Quote\Model\Quote\Address[] the addresses that changed
     */
    private function restoreOwnedCustomerAddress($quote): array
    {
        $restored = [];
        $customerId = (int)$quote->getCustomerId();
        if ($customerId <= 0) {
            return $restored;
        }
        foreach ([$quote->getBillingAddress(), $quote->getShippingAddress()] as $address) {
            if (!$address) {
                continue;
            }
            $addressId = (int)$address->getCustomerAddressId();
            if ($addressId <= 0) {
                continue;
            }
            try {
                $saved = $this->addressRepository->getById($addressId);
            } catch (\Throwable $e) {
                $this->logger->warning('[Paystand GetQuoteData] Saved address not read, quote address kept', [
                    'quote_id' => $quote->getId(),
                    'customer_address_id' => $addressId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
            if ((int)$saved->getCustomerId() !== $customerId) {
                continue;
            }
            $savedStreet = implode("\n", (array)$saved->getStreet());
            $quoteStreet = implode("\n", (array)$address->getStreet());
            $regionId = $saved->getRegionId();
            $differs = (string)$address->getCity() !== (string)$saved->getCity()
                || (string)$address->getRegionId() !== (string)$regionId
                || (string)$address->getPostcode() !== (string)$saved->getPostcode()
                || $quoteStreet !== $savedStreet;
            if (!$differs) {
                continue;
            }
            $address->setCity($saved->getCity());
            $address->setRegionId($regionId);
            $region = $saved->getRegion();
            if ($region && method_exists($region, 'getRegion')) {
                $address->setRegion($region->getRegion());
            }
            $address->setPostcode($saved->getPostcode());
            $address->setCountryId($saved->getCountryId());
            $address->setStreet($saved->getStreet());
            $restored[] = $address;
        }
        return $restored;
    }

    /**
     * placeOrder reloads the quote, so a restored address must be in the database for
     * the order to carry the tax the window charges. A failed save is logged only.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param \Magento\Quote\Model\Quote\Address[] $addresses
     */
    private function saveRestoredAddresses($quote, array $addresses): void
    {
        foreach ($addresses as $address) {
            try {
                $this->quoteAddressResource->save($address);
            } catch (\Throwable $e) {
                $this->logger->error('[Paystand GetQuoteData] Restored address not saved', [
                    'quote_id' => $quote->getId(),
                    'address_type' => $address->getAddressType(),
                    'error' => $e->getMessage(),
                ]);
                try {
                    CloudLogger::ship(CloudLogger::EVENT_QUOTE_ADDRESS_RESTORE_FAILED, [
                        'quote_id'      => (string)$quote->getId(),
                        'error_message' => 'getquotedata ' . $address->getAddressType() . ' address not saved: '
                            . $e->getMessage(),
                    ]);
                } catch (\Throwable $ignored) {
                    // CloudLogger failure — silently ignored to protect the payment flow
                }
            }
        }
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
                return $result->setHttpResponseCode(\Magento\Framework\Webapi\Exception::HTTP_BAD_REQUEST)->setData([
                    'success' => false,
                    'error' => [
                        'code' => 'NO_ACTIVE_QUOTE',
                        'message' => 'No active quote found'
                    ]
                ]);
            }

            // Drop the snapshot of an earlier open before pricing. If this request fails, the
            // browser prices the window itself and nothing restamps.
            $this->captureSnapshot->clearCheckoutIntent($quote);
            $restoredAddresses = $this->restoreOwnedCustomerAddress($quote);

            // Clear totals_collected_flag so collectTotals() recalculates. The recollect can
            // clear the shipping selection, so QuoteShipping brackets it.
            $shipping = $this->quoteShipping->recollectPreservingShipping($quote, 'getquotedata');
            $this->saveRestoredAddresses($quote, $restoredAddresses);

            $this->shipShippingBreadcrumb(
                $quote,
                $shipping['before'],
                $shipping['restored'],
                $shipping['retryFailed']
            );

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
                    'base_currency_code' => $quote->getBaseCurrencyCode(),
                    'items_count' => $quote->getItemsCount(),
                    'items_qty' => $quote->getItemsQty(),
                    'totals' => $totalsData
                ],
                'billing' => $billingData,
                'customer' => $customerData
            ];

            // The window charges the amount this response returns, so the cart is stamped now,
            // before another browser can change it. The payment save keeps this snapshot.
            $this->captureSnapshot->stampCheckoutIntent($quote);

            return $result->setData($response);

        } catch (NoSuchEntityException $e) {
            $this->logger->error('[Paystand] Quote not found: ' . $e->getMessage());
            return $result->setHttpResponseCode(\Magento\Framework\Webapi\Exception::HTTP_NOT_FOUND)->setData([
                'success' => false,
                'error' => [
                    'code' => 'QUOTE_NOT_FOUND',
                    'message' => 'Quote not found'
                ]
            ]);
        } catch (LocalizedException $e) {
            $this->logger->error('[Paystand] Error getting quote data: ' . $e->getMessage());
            return $result->setHttpResponseCode(\Magento\Framework\Webapi\Exception::HTTP_BAD_REQUEST)->setData([
                'success' => false,
                'error' => [
                    'code' => 'QUOTE_DATA_ERROR',
                    'message' => $e->getMessage()
                ]
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[Paystand] Unexpected error: ' . $e->getMessage());
            return $result->setHttpResponseCode(\Magento\Framework\Webapi\Exception::HTTP_INTERNAL_ERROR)->setData([
                'success' => false,
                'error' => [
                    'code' => 'QUOTE_DATA_UNEXPECTED_ERROR',
                    'message' => 'An error occurred while fetching quote data'
                ]
            ]);
        } catch (\Throwable $e) {
            // \Error from any total collector, so the browser still gets a JSON error response.
            $this->logger->error('[Paystand] Unexpected throwable: ' . $e->getMessage());
            return $result->setHttpResponseCode(\Magento\Framework\Webapi\Exception::HTTP_INTERNAL_ERROR)->setData([
                'success' => false,
                'error' => [
                    'code' => 'QUOTE_DATA_UNEXPECTED_ERROR',
                    'message' => 'An error occurred while fetching quote data'
                ]
            ]);
        }
    }
}

