define(
  [
    'Magento_Checkout/js/view/payment/default'
    , 'paystand-checkout'
  ],
  function (Component, paystandCheckout) {
    'use strict';

    return Component.extend({
      defaults: {
        template: 'PayStand_PayStandMagento/payment/paystandmagento-directpost'
      }
    });
  }
);
