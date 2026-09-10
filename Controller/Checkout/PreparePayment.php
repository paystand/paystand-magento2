<?php

declare(strict_types=1);

namespace PayStand\PayStandMagento\Controller\Checkout;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Session\SessionManagerInterface;
use PayStand\PayStandMagento\Model\Checkout\AttemptService;

class PreparePayment implements HttpPostActionInterface
{
    private const HTTP_CONFLICT = 409;

    /** @var JsonFactory */
    private $results;
    /** @var AttemptService */
    private $attempts;
    /** @var SessionManagerInterface */
    private $session;

    public function __construct(
        JsonFactory $results,
        AttemptService $attempts,
        SessionManagerInterface $session
    ) {
        $this->results = $results;
        $this->attempts = $attempts;
        $this->session = $session;
    }

    public function execute()
    {
        $result = $this->results->create();
        try {
            return $result->setData([
                'success' => true,
                'attempt' => $this->attempts->prepare()
            ]);
        } catch (\Throwable $error) {
            return $result->setHttpResponseCode(self::HTTP_CONFLICT)->setData([
                'success' => false,
                'paymentMayBeStarted' => false,
                'error' => ['code' => $this->safeCode($error)]
            ]);
        } finally {
            $this->closeSession();
        }
    }

    private function safeCode(\Throwable $error): string
    {
        $code = strtolower(trim($error->getMessage()));
        return preg_match('/^[a-z0-9-]{1,64}$/D', $code) ? $code : 'preflight-refused';
    }

    private function closeSession(): void
    {
        try {
            $this->session->writeClose();
        } catch (\Throwable $ignored) {
        }
    }
}
