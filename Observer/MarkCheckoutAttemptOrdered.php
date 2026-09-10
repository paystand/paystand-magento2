<?php

declare(strict_types=1);

namespace PayStand\PayStandMagento\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use PayStand\PayStandMagento\Model\Checkout\AttemptRepository;
use PayStand\PayStandMagento\Model\Directpost;
use Psr\Log\LoggerInterface;

/**
 * Records only local lifecycle state. It never performs remote work and never
 * lets an observability failure escape an already committed order.
 */
class MarkCheckoutAttemptOrdered implements ObserverInterface
{
    /** @var AttemptRepository */
    private $attempts;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(AttemptRepository $attempts, LoggerInterface $logger)
    {
        $this->attempts = $attempts;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        try {
            $order = $observer->getEvent()->getOrder();
            if (!$order || !$order->getId() || !$order->getPayment()
                || $order->getPayment()->getMethod() !== Directpost::METHOD_CODE
            ) {
                return;
            }
            $this->attempts->markOrderPlacedByQuote(
                (int)$order->getQuoteId(),
                (string)$order->getIncrementId(),
                gmdate('Y-m-d H:i:s')
            );
        } catch (\Throwable $error) {
            $this->logger->critical('PAYSTAND_CHECKOUT_ATTEMPT_ORDER_MARK_FAILED', [
                'reason' => get_class($error)
            ]);
        }
    }
}
