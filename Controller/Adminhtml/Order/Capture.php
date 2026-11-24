<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Sales\Api\OrderRepositoryInterface;
use PayStand\PayStandMagento\Helper\PaystandApi;
use PayStand\PayStandMagento\Helper\PreauthStore;
use PayStand\PayStandMagento\Model\PreauthCaptureGuard;

class Capture extends Action
{
    public const ADMIN_RESOURCE = 'Magento_Sales::capture';

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var PaystandApi
     */
    private $api;

    /**
     * @var PreauthStore
     */
    private $preauthStore;

    /**
     * @var PreauthCaptureGuard
     */
    private $guard;

    public function __construct(
        Action\Context $context,
        OrderRepositoryInterface $orderRepository,
        PaystandApi $api,
        PreauthStore $preauthStore,
        PreauthCaptureGuard $guard
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->api = $api;
        $this->preauthStore = $preauthStore;
        $this->guard = $guard;
    }

    public function execute()
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        $redirect = $this->resultRedirectFactory->create()->setPath('sales/order/view', ['order_id' => $orderId]);
        try {
            $order = $this->orderRepository->get($orderId);
            $preauthId = (string) $order->getData('paystand_preauth_id');
            $remaining = $order->getData('paystand_preauth_amount_remaining');
            $refusal = $this->guard->refusal([
                'status' => $order->getData('paystand_preauth_status'),
                'remaining' => $remaining,
                'expires_at' => $order->getData('paystand_preauth_expires_at')
            ], $remaining);
            if ($preauthId === '' || $refusal !== null) {
                $this->messageManager->addErrorMessage($refusal ?: __('This order has no Paystand authorization.'));
                return $redirect;
            }

            $result = $this->api->post('/preAuths/' . rawurlencode($preauthId) . '/capture', [
                'amount' => $remaining
            ]);
            if (!$result['ok']) {
                $this->messageManager->addErrorMessage($result['error']);
                return $redirect;
            }

            $body = $result['body'];
            $paymentId = (string) ($body['paymentId'] ?? ($body['payment']['id'] ?? ''));
            if ($paymentId === '' && isset($body['id']) && (string) $body['id'] !== $preauthId) {
                $paymentId = (string) $body['id'];
            }
            $order->setData('paystand_payment_id', (string) $paymentId);
            $order->setData('paystand_preauth_status', 'captured');
            $order->setData('paystand_preauth_amount_captured', $order->getData('paystand_preauth_amount'));
            $order->setData('paystand_preauth_amount_remaining', 0);
            $this->preauthStore->persistOrder($order);
            $this->messageManager->addSuccessMessage(
                __('Capture sent. The payment webhook creates the invoice.')
            );
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $redirect;
    }
}
