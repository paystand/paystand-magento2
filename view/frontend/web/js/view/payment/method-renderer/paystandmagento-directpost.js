var checkoutjs_module = 'paystand';
var core_domain = 'paystand.com';
var api_domain = 'api.paystand.com';
var checkout_domain = 'checkout.paystand.com';
var env = 'live';
var use_sandbox = window.checkoutConfig.payment.paystandmagento.use_sandbox;
if (use_sandbox == '1') {
    checkoutjs_module = 'paystand-sandbox';
    core_domain = 'paystand.co';
    api_domain = 'api.paystand.co';
    checkout_domain = 'checkout.paystand.co';
    env = 'sandbox'
}

define(
    [
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/model/quote',
        'Magento_CheckoutAgreements/js/model/agreement-validator',
        checkoutjs_module,
    ],
    function ($, Component, quote, agreementValidator) {
        'use strict';


        const loadCheckout = function () {

            // Get information from Magento checkout to load Paystand Checkout with
            const publishable_key = window.checkoutConfig.payment.paystandmagento.publishable_key;
            const price = quote.totals().base_grand_total.toString();
            const currency = quote.totals().quote_currency_code;
            const quoteId = quote.getQuoteId();
            const billing = quote.billingAddress();

            let checkoutData = {
                publishable_key: publishable_key,
                price: price,
                quoteId: quoteId,
                billing: billing,
                currency: currency
            }

            // Init checkout with iso3 country code if country is provided
            if (billing.countryId) {
                $.ajax({
                    beforeSend: function (request) {
                        request.setRequestHeader("x-publishable-key", publishable_key);
                    },
                    dataType: "text",
                    contentType: "application/json; charset=utf-8",
                    url: "https://" + api_domain + "/v3/addresses/countries/iso?code=" + billing.countryId,
                    success: function (data) {
                        checkoutData.countryISO3 = JSON.parse(data).iso3;
                        initCheckout(checkoutData);
                    },
                    error: function (error) {
                        console.log('Unable to get ISO3 code from PayStand!');
                    },
                });
            } else {
                initCheckout(checkoutData);
            }
        };

        const initCheckout = function (checkoutData) {
            let config = {
                "publishableKey": checkoutData.publishable_key,
                "paymentAmount": checkoutData.price,
                "fixedAmount": true,
                "viewReceipt": "close",
                "viewCheckout": "mobile",
                "paymentCurrency": checkoutData.currency,
                "mode": "modal",
                "env": env,
                "payerName": checkoutData.billing.firstname + ' ' + checkoutData.billing.lastname,
                "payerEmail": quote.guestEmail,
                "payerAddressCounty": checkoutData.countryISO3,
                "paymentMeta": {
                    "source": "magento 2",
                    "quote": checkoutData.quoteId,
                    "quoteDetails": quote.totals()
                }
            };

            if (checkoutData.billing.street && checkoutData.billing.street.length > 0) {
                config.payerAddressStreet = checkoutData.billing.street[0];
            }
            if (checkoutData.billing.city) {
                config.payerAddressCity = checkoutData.billing.city;
            }
            if (checkoutData.billing.postcode) {
                config.payerAddressPostal = checkoutData.billing.postcode;
            }
            if (checkoutData.billing.regionCode) {
                config.payerAddressState = checkoutData.billing.regionCode;
            }

            // This block fixes the issue where checkout opens blank
            psCheckout.onReady(function () {
                // wait for reboot to complete before showing checkout
                psCheckout.onceLoaded(function (data) {
                    psCheckout.showCheckout();
                });
                // reboot checkout with a new config
                psCheckout.reboot(config);
            });

            psCheckout.runCheckout(config);
        }

        const watchAgreement = function () {
            const isDisabled = $('.ps-button').prop("disabled");
            const isValid = agreementValidator.validate();
            if (isValid && isDisabled) {
                $(".ps-button").prop('disabled', false);
            }
            if (!isValid && !isDisabled) {
                $(".ps-button").prop('disabled', true);
            }
        }

        psCheckout.onComplete(function () {
            $(".submit-trigger").click();
        });

        return Component.extend({
            defaults: {
                template: 'PayStand_PayStandMagento/payment/paystandmagento-directpost'
            },

            // this function ins binded to actual Paystand button to trigger checkout
            loadCheckout: function () {
                loadCheckout();
            },

            // this function ins binded to actual Paystand button to trigger checkout
            watchAgreement: function () {
                setInterval(watchAgreement, 500)
            }
        });
    }
);
