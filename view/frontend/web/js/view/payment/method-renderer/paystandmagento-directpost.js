var checkoutjs_module = 'paystand';
var core_domain = 'paystand.com';
var api_domain = 'api.paystand.com';
var checkout_domain = 'checkout.paystand.com';
var env = 'live';
var use_sandbox = window.checkoutConfig.payment.paystandmagento.use_sandbox;
if (use_sandbox == '1') {
    checkoutjs_module = 'paystand-sandbox';
    core_domain = 'paystand.biz';
    api_domain = 'api.paystand.biz';
    checkout_domain = 'checkout.paystand.biz';
    env = 'development'
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

      const termsSel = '.ps-payment-method div.checkout-agreements input[type="checkbox"]';
      const psButtonSel = '.ps-payment-method .ps-button';
      const submitTrigger = '.submit-trigger';
      let countryISO3 = null;

      function getConfig() {
          const billing = quote.billingAddress()
          const config = {
              "publishableKey": window.checkoutConfig.payment.paystandmagento.publishable_key,
              "paymentAmount": quote.totals().base_grand_total.toString(),
              "fixedAmount": true,
              "viewReceipt": "close",
              "viewCheckout": "mobile",
              "paymentCurrency": quote.totals().quote_currency_code,
              "mode": "modal",
              "env": env,
              "payerName": billing.firstname + ' ' + billing.lastname,
              "payerEmail": quote.guestEmail,
              "payerAddressCounty": countryISO3,
              "paymentMeta": {
                  "source": "magento 2",
                  "quote": quote.getQuoteId(),
                  "quoteDetails": quote.totals()
              }
          };

          if (billing.street && billing.street.length > 0) {
              config.payerAddressStreet = billing.street[0];
          }
          if (billing.city) {
              config.payerAddressCity = billing.city;
          }
          if (billing.postcode) {
              config.payerAddressPostal = billing.postcode;
          }
          if (billing.regionCode) {
              config.payerAddressState = billing.regionCode;
          }
          return config;
      }

      function initCheckout(config) {
          setTimeout(() => { psCheckout.runCheckout(config); }, 0);
      }

      function loadCheckout() {
          initCheckout(getConfig());
      }

      function onCompleteCheckout() {
        psCheckout.onComplete(function () {
            $(submitTrigger).click();
        });
      }

      function disableButton() {
          $(psButtonSel).prop("disabled", true)
      }

      function enableButton() {
          $(psButtonSel).prop("disabled", false)
      }

      function hasCountryCode() {
          return !!countryISO3;
      }

      function areAllTermsSelected() {
          return $(termsSel)
            .map(function () { return $(this).prop("checked") })
            .filter(function (key,value) { return value === false; })
            .toArray()
            .length === 0;
      }

      function registerClicks() {
          $(termsSel).each(function () {
              $(this).click(function () { resolveButton(); })
          });
      }

      function resolveButton() {
          if (areAllTermsSelected()) {
              if (agreementValidator.validate()) {
                  if (hasCountryCode()) {
                      enableButton();
                  }
                  else {
                      // show "Unable to find country code error"
                      console.log('Unable to get ISO3 code from PayStand!');
                  }
              }
              else {
                  disableButton();
              }
          }
          else {
              disableButton();
          }
      }

      function getCountryCode() {
          const billing = quote.billingAddress();
          const publishable_key = window.checkoutConfig.payment.paystandmagento.publishable_key;
          if (billing.countryId) {
              $.ajax({
                  beforeSend: function (request) {
                      request.setRequestHeader("x-publishable-key", publishable_key);
                  },
                  dataType: "text",
                  contentType: "application/json; charset=utf-8",
                  url: "https://" + api_domain + "/v3/addresses/countries/iso?code=" + billing.countryId,
                  success: function (data) {
                      countryISO3 = JSON.parse(data).iso3;
                      resolveButton();
                  },
                  error: function (error) {
                      console.log('Unable to get ISO3 code from PayStand!');
                  },
              });
          }
      }

      function watchAgreement() {
          const interval = setInterval(function(){
              if ($(termsSel).length > 0) {
                  disableButton();
                  registerClicks();
                  getCountryCode()
                  clearInterval(interval);
                  return;
              }
          }, 500)
      }

      return Component.extend({
          defaults: {
              template: 'PayStand_PayStandMagento/payment/paystandmagento-directpost'
          },

          // this function ins binded to actual Paystand button to trigger checkout
          loadCheckout: function () {
              loadCheckout();
          },

          onCompleteCheckout: function () {
              onCompleteCheckout();
          },

          // this function ins binded to actual Paystand button to trigger checkout
          watchAgreement: function () {
              watchAgreement();
          }
      });
  }
);
