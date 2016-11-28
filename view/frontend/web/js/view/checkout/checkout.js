var checkoutjs_module = 'paystand';
var core_domain = 'paystand.com';
var use_sandbox = window.checkoutConfig.payment.paystandmagento.use_sandbox;
if (use_sandbox == '1') {
  checkoutjs_module = 'paystand-sandbox';
  core_domain = 'paystand.co';
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
    console.log("publishable key = " + publishable_key);

    var price = quote.totals().grand_total.toString();
    var quoteId = quote.getQuoteId();
    var billing = quote.billingAddress();

    PayStandCheckout.checkoutComplete = function (data, iframe) {
      console.log("custom checkout complete:", data);
      $(".submit-trigger").click();
    };
    PayStandCheckout.checkoutFailed = function (data) {
      console.log("custom checkout failed:", data);
    };

    function initCheckout(countryISO3) {
      PayStandCheckout.init({
        "publishableKey": publishable_key,
        "checkout_domain": "https://checkout." + core_domain + "/v3/",
        "domain": "https://api." + core_domain,
        "payment": {
          "amount": price
        },
        "currency": "USD",
        "paymentMethods": [
          'echeck',
          'card'
        ],
        "payer": {
          "name": billing.firstname + ' ' + billing.lastname,
          "email": quote.guestEmail
        },
        "billing": {
          "street": billing.street[0],
          "city": billing.city,
          "postalCode": billing.postcode,
          "subdivisionCode": billing.regionCode,
          "countryCode": countryISO3
        },
        "meta": {
          "source": "magento 2",
          "quote": quoteId,
          "quoteDetails" : quote.totals()
        }
      }, null, 520);
      // stop observing for mutation events
      window.observer.disconnect();
    }

    if(billing.countryId) {
      $.ajax({
        beforeSend: function(request) {
          request.setRequestHeader("x-publishable-key", publishable_key);
        },
        dataType: "text",
        contentType: "application/json; charset=utf-8",
        url: "https://api." + core_domain + "/v3/addresses/countries/iso?code=" + billing.countryId,
        success: function(data) {
          initCheckout(JSON.parse(data).iso3);
        },
        error: function(error) {
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
        }
        else {
          recursivelyObserve();
        }
      }
      else {
        loadPaystandCheckout();
      }
    }, 10);
  };

  recursivelyObserve();

  return this;
});