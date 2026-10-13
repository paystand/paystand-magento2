/**
 * Checkout can ask for the success page twice: Magento after its placeOrder
 * call succeeds, and the Paystand order-confirm poll when the webhook placed
 * the order first. The first success page load clears the session's last
 * order, so a second load sends the shopper to the cart. Only the first
 * request navigates.
 */
define([
    'mage/utils/wrapper',
    'Magento_Checkout/js/model/quote'
], function (wrapper, quote) {
    'use strict';

    return function (redirectOnSuccess) {
        redirectOnSuccess.paystandRedirectStarted = false;
        redirectOnSuccess.execute = wrapper.wrap(redirectOnSuccess.execute, function (originalExecute) {
            var method = quote.paymentMethod();
            if (!method || method.method !== 'paystandmagento') {
                return originalExecute();
            }
            if (redirectOnSuccess.paystandRedirectStarted) {
                return;
            }
            redirectOnSuccess.paystandRedirectStarted = true;

            return originalExecute();
        });

        return redirectOnSuccess;
    };
});
