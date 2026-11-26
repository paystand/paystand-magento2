<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Block\Adminhtml\Order;

use Magento\Backend\Block\Template;
use Magento\Framework\Registry;
use PayStand\PayStandMagento\Helper\PreauthStore;
use PayStand\PayStandMagento\Model\PreauthCaptureGuard;

class Preauth extends Template
{
    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var PreauthStore
     */
    private $preauthStore;

    /**
     * @var PreauthCaptureGuard
     */
    private $guard;

    public function __construct(
        Template\Context $context,
        Registry $registry,
        PreauthStore $preauthStore,
        PreauthCaptureGuard $guard,
        array $data = []
    ) {
        $this->registry = $registry;
        $this->preauthStore = $preauthStore;
        $this->guard = $guard;
        parent::__construct($context, $data);
    }

    public function getOrder()
    {
        return $this->registry->registry('current_order');
    }

    public function canCapture($order): bool
    {
        return $this->guard->canCapture($this->preauthStore->captureHold($order));
    }
}
