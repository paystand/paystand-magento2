/*browser:true*/
/*global define*/
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'paystandmagento',
                component: 'PayStand_PayStandMagento/js/view/payment/method-renderer/paystandmagento-directpost'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);