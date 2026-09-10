<?php

declare(strict_types=1);

namespace PayStand\PayStandMagento\Controller\Checkout;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\Webapi\Exception as WebapiException;
use PayStand\PayStandMagento\Model\Checkout\AttemptService;

class AttemptStatus implements HttpPostActionInterface
{
    /** @var RequestInterface */
    private $request;
    /** @var JsonFactory */
    private $results;
    /** @var AttemptService */
    private $attempts;
    /** @var SessionManagerInterface */
    private $session;

    public function __construct(
        RequestInterface $request,
        JsonFactory $results,
        AttemptService $attempts,
        SessionManagerInterface $session
    ) {
        $this->request = $request;
        $this->results = $results;
        $this->attempts = $attempts;
        $this->session = $session;
    }

    public function execute()
    {
        $result = $this->results->create();
        try {
            $body = json_decode((string)$this->request->getContent(), true, 8, JSON_THROW_ON_ERROR);
            return $result->setData([
                'success' => true,
                'attempt' => $this->attempts->status((string)($body['attemptToken'] ?? ''))
            ]);
        } catch (\Throwable $error) {
            return $result->setHttpResponseCode(WebapiException::HTTP_NOT_FOUND)->setData([
                'success' => false,
                'paymentMayBeStarted' => false,
                'error' => ['code' => 'attempt-status-unavailable']
            ]);
        } finally {
            try {
                $this->session->writeClose();
            } catch (\Throwable $ignored) {
            }
        }
    }
}
