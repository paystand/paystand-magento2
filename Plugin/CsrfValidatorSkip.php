<?php
namespace PayStand\PayStandMagento\Plugin;

class CsrfValidatorSkip
{
    private const WEBHOOK_MODULE = 'paystandmagento';
    private const WEBHOOK_CONTROLLER = 'webhook';
    private const WEBHOOK_ACTION = 'paystand';

    /**
     * @param \Magento\Framework\App\Request\CsrfValidator $subject
     * @param \Closure $proceed
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Magento\Framework\App\ActionInterface $action
     */
    public function aroundValidate(
        $subject,
        \Closure $proceed,
        $request,
        $action
    ) {
        if (
            $request->getModuleName() === self::WEBHOOK_MODULE
            && $request->getControllerName() === self::WEBHOOK_CONTROLLER
            && $request->getActionName() === self::WEBHOOK_ACTION
        ) {
            return;
        }

        return $proceed($request, $action);
    }
}
