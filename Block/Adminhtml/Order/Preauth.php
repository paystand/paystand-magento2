<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Block\Adminhtml\Order;

use Magento\Backend\Block\Template;
use Magento\Framework\Registry;

class Preauth extends Template
{
    /**
     * @var Registry
     */
    private $registry;

    public function __construct(Template\Context $context, Registry $registry, array $data = [])
    {
        $this->registry = $registry;
        parent::__construct($context, $data);
    }

    public function getOrder()
    {
        return $this->registry->registry('current_order');
    }
}
