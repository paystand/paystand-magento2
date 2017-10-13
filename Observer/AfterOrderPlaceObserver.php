<?php
/**
 * PayStand Observer
 */
namespace PayStand\PayStandMagento\Observer;

use Magento\Framework\Event\ObserverInterface;
use PayStand\PayStandMagento\Model\Directpost;

class AfterOrderPlaceObserver implements ObserverInterface
{
  /**
   * Sets order status to pending
   *
   * @param \Magento\Framework\Event\Observer $observer
   * @return void
   */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment();
        if ($payment->getMethod() == Directpost::METHOD_CODE) {
            $order->setState('pending');
            $order->setStatus('pending');
        }
    }
}
