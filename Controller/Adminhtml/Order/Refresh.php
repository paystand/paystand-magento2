<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Sales\Api\OrderRepositoryInterface;
use PayStand\PayStandMagento\Helper\PaystandApi;
use PayStand\PayStandMagento\Helper\PreauthStore;

class Refresh extends Action
{
    public const ADMIN_RESOURCE = 'Magento_Sales::actions_view';

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

    public function __construct(
        Action\Context $context,
        OrderRepositoryInterface $orderRepository,
        PaystandApi $api,
        PreauthStore $preauthStore
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->api = $api;
        $this->preauthStore = $preauthStore;
    }

    public function execute()
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        $redirect = $this->resultRedirectFactory->create()->setPath('sales/order/view', ['order_id' => $orderId]);
        try {
            $order = $this->orderRepository->get($orderId);
            $preauthId = (string) $order->getData('paystand_preauth_id');
            if ($preauthId === '') {
                $this->messageManager->addErrorMessage(__('This order has no Paystand authorization.'));
                return $redirect;
            }
            $result = $this->api->get('/preAuths/' . rawurlencode($preauthId));
            if (!$result['ok']) {
                $this->messageManager->addErrorMessage($result['error']);
                return $redirect;
            }
            $this->preauthStore->applyRecord($order, $result['body']);
            $this->preauthStore->persistOrder($order);
            $this->messageManager->addSuccessMessage(__('Authorization refreshed.'));
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $redirect;
    }
}
