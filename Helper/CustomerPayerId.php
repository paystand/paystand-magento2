<?php

namespace PayStand\PayStandMagento\Helper;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

class CustomerPayerId extends AbstractHelper
{
    const PAYSTAND_PAYER_ID_ATTRIBUTE = 'paystand_payer_id';

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Constructor
     *
     * @param Context $context
     * @param CustomerRepositoryInterface $customerRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        CustomerRepositoryInterface $customerRepository,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
    }

    /**
     * Save PayStand Payer ID to customer
     *
     * @param int $customerId
     * @param string $paystandPayerId
     * @return bool
     */
    public function savePayerIdToCustomer($customerId, $paystandPayerId)
    {
        try {
            $customer = $this->customerRepository->getById($customerId);

            $customer->setCustomAttribute(self::PAYSTAND_PAYER_ID_ATTRIBUTE, $paystandPayerId);
            $this->customerRepository->save($customer);
            
            return true;
        } catch (NoSuchEntityException $e) {
            $this->logger->error('Customer not found when saving PayStand Payer ID', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            return false;
        } catch (LocalizedException $e) {
            $this->logger->error('Error saving PayStand Payer ID to customer', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get PayStand Payer ID from customer
     *
     * @param int $customerId
     * @return string|null
     */
    public function getPayerIdFromCustomer($customerId)
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            $payerIdAttribute = $customer->getCustomAttribute(self::PAYSTAND_PAYER_ID_ATTRIBUTE);
            
            return $payerIdAttribute ? $payerIdAttribute->getValue() : null;
        } catch (NoSuchEntityException $e) {
            $this->logger->error('Customer not found when retrieving PayStand Payer ID', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            return null;
        } catch (LocalizedException $e) {
            $this->logger->error('Error retrieving PayStand Payer ID from customer', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Check if customer has PayStand Payer ID
     *
     * @param int $customerId
     * @return bool
     */
    public function hasPayerId($customerId)
    {
        $payerId = $this->getPayerIdFromCustomer($customerId);
        return !empty($payerId);
    }

    /**
     * Remove PayStand Payer ID from customer
     *
     * @param int $customerId
     * @return bool
     */
    public function removePayerIdFromCustomer($customerId)
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            $customer->setCustomAttribute(self::PAYSTAND_PAYER_ID_ATTRIBUTE, null);
            $this->customerRepository->save($customer);
            
            $this->logger->info('PayStand Payer ID removed from customer', [
                'customer_id' => $customerId
            ]);
            
            return true;
        } catch (NoSuchEntityException $e) {
            $this->logger->error('Customer not found when removing PayStand Payer ID', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            return false;
        } catch (LocalizedException $e) {
            $this->logger->error('Error removing PayStand Payer ID from customer', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
} 