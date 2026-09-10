<?php

declare(strict_types=1);

namespace PayStand\PayStandMagento\Cron;

use PayStand\PayStandMagento\Model\Checkout\AttemptRepository;
use Psr\Log\LoggerInterface;

/** Repairs only local lifecycle/outbox state; it never contacts Paystand. */
class RepairCheckoutLifecycle
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

    public function execute(): void
    {
        try {
            $repaired = $this->attempts->repairCommittedOrders(100);
            if ($repaired > 0) {
                $this->logger->notice('PAYSTAND_CHECKOUT_LIFECYCLE_REPAIRED', ['count' => $repaired]);
            }
        } catch (\Throwable $error) {
            $this->logger->critical('PAYSTAND_CHECKOUT_LIFECYCLE_REPAIR_FAILED', [
                'reason' => get_class($error)
            ]);
        }
    }
}
