var checkoutjs_module = 'paystand';
var core_domain = 'paystand.com';
var api_domain = 'api.paystand.com';
var checkout_domain = 'checkout.paystand.com';
var use_sandbox = window.checkoutConfig.payment.paystandmagento.use_sandbox;
if (use_sandbox == '1') {
  checkoutjs_module = 'paystand-sandbox';
  core_domain = 'paystand.co';
  api_domain = 'api.paystand.co';
  checkout_domain = 'checkout.paystand.co';
  // core_domain = 'localhost:3001';
  // api_domain = 'localhost:3001/api';
  // checkout_domain = 'localhost:3003';
}

/*jshint browser:true jquery:true*/
define([
  'require',
  'jquery',
  'Magento_Payment/js/view/payment/iframe',
  'Magento_Checkout/js/model/quote',
  'Magento_Checkout/js/model/error-processor',
  checkoutjs_module
], function (require, $, iframe, quote, errorProcessor, paystand) {
  'use strict';

  /**
   * Load the Paystand checkout
   */
  var loadPaystandCheckout = function () {

    var publishable_key = window.checkoutConfig.payment.paystandmagento.publishable_key;

    var price = quote.totals().grand_total.toString();
    var quoteId = quote.getQuoteId();
    var billing = quote.billingAddress();

    psCheckout.onComplete(function(data){
      console.log("custom checkout complete:", data);
      $(".submit-trigger").click();
    });
    psCheckout.onError(function(data){
      console.log("custom checkout error:", data);
    });

    function initCheckout(countryISO3)
    {

      var config = {
        "publishableKey": publishable_key,
        "paymentAmount": price,
        "fixedAmount": true,
        "viewReceipt": "close",
        "viewCheckout": "mobile",
        "paymentCurrency": "USD",
        "viewFunds": "echeck,card",
        "payerName": billing.firstname + ' ' + billing.lastname,
        "payerEmail": quote.guestEmail,
        "payerAddressCounty": countryISO3,
        "paymentMeta": {
          "source": "magento 2",
          "quote": quoteId,
          "quoteDetails" : quote.totals()
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

      console.log("rebooting checkout with config", config);

      psCheckout.onceLoaded(function (data) {
        psCheckout.onceLoaded(function (data) {
          psCheckout.showCheckout();
        });
        psCheckout.reboot(config);
      });

      psCheckout.init();

      // stop observing for mutation events
      window.observer.disconnect();
    }

    if (billing.countryId) {
      $.ajax({
        beforeSend: function (request) {
          request.setRequestHeader("x-publishable-key", publishable_key);
        },
        dataType: "text",
        contentType: "application/json; charset=utf-8",
        url: "https://" + api_domain + "/v3/addresses/countries/iso?code=" + billing.countryId,
        success: function (data) {
          initCheckout(JSON.parse(data).iso3);
        },
        error: function (error) {
          console.log('Unable to get ISO3 code from PayStand!');
        },
      });
    } else {
      initCheckout();
    }
  };

// create an observer instance
  window.observer = new MutationObserver(function (mutations) {
    mutations.forEach(function (mutation) {
      console.log(mutation.type);
      for (var i = 0; i < mutation.addedNodes.length; ++i) {
        var item = mutation.addedNodes[i];
        if (typeof item.getElementsByClassName === "function") {
          if (item.getElementsByClassName('paystand-checkout-form').length > 0) {
            loadPaystandCheckout();
          }
        }
      }
    });
  });

  // configuration of the observer:
  var config = {attributes: true, childList: true, characterData: true};

  // pass in the target node, as well as the observer options
  var total_timeout = 0;
  var recursivelyObserve = function () {
    window.setTimeout(function () {
      total_timeout += 10;

      if (total_timeout < 1000) {
        // select the target node
        var target = $('.payment-method').parent().get(0);

        if (typeof target == "Node") {
          observer.observe(target, config);
        } else {
          recursivelyObserve();
        }
      } else {
        loadPaystandCheckout();
      }
    }, 10);
  };

  recursivelyObserve();

  return this;
});
