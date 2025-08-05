<?php

namespace PayStand\PayStandMagento\Controller\Checkout;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Psr\Log\LoggerInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class SavePaymentData extends Action
{
    protected $logger;
    protected $resultJsonFactory;

    public function __construct(
        Context $context,
        LoggerInterface $logger,
        JsonFactory $resultJsonFactory
    ) {
        $this->logger = $logger;
        $this->resultJsonFactory = $resultJsonFactory;
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        // Leer input crudo
        $rawInput = file_get_contents('php://input');
        // Loguearlo en var/log/debug.log
        $this->logger->debug('>>>>>> PAYSTAND-PAYMENT-DATA payerId: ' . $rawInput);
        // Retornar respuesta simple
        return $result->setData(['success' => true]);
    }
}





