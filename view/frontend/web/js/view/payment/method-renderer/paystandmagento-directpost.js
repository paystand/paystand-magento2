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
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/checkout-data',
        checkoutjs_module,
    ],

    function ($, Component, quote, agreementValidator, customer) {
        'use strict';
        const termsSel = '.ps-payment-method div.checkout-agreements input[type="checkbox"]';
        const psButtonSel = '.ps-payment-method .ps-button';
        const submitTrigger = '.submit-trigger';
        let countryISO3 = null;

        function getConfig() {
            const billing = quote.billingAddress()

            // Determinate payer email and payer id
            let payerEmail = customer.isLoggedIn() ? customer.customerData.email : quote.guestEmail;
            let payerId = null;
            if (customer.isLoggedIn() && customer.customerData && customer.customerData.custom_attributes) {
                var payerIdAttr = customer.customerData.custom_attributes.paystand_payer_id;
                if (payerIdAttr && payerIdAttr.value) {
                    payerId = payerIdAttr.value;
                }
            }

            const config = {
                "publishableKey": window.checkoutConfig.payment.paystandmagento.publishable_key,
                "presetCustom": window.checkoutConfig.payment.paystandmagento.presetCustom,
                "paymentAmount": quote.totals().base_grand_total.toString(),
                "fixedAmount": true,
                "viewReceipt": "close",
                "viewCheckout": "mobile",
                "paymentCurrency": quote.totals().quote_currency_code,
                "mode": "modal",
                "env": env,
                "payerName": billing.firstname + ' ' + billing.lastname,
                "payerEmail": payerEmail,
                "payerAddressCounty": countryISO3,
                "payerId": payerId,
                "paymentMeta": {
                    "source": "magento 2",
                    "quote": quote.getQuoteId(),
                    "quoteDetails": quote.totals()
                }
            };

            // Add access token if available (when user is logged in)
            if (window.checkoutConfig.payment.paystandmagento.access_token) {
                config.accessToken = window.checkoutConfig.payment.paystandmagento.access_token;
            }

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

            // Apply preset flow in config if customer is logged in
            if (customer.isLoggedIn() && config.accessToken){
                delete config.presetCustom;
                delete config.publishableKey;
                config.checkoutType = 'checkout_magento2';
                config.customerId = window.checkoutConfig.payment.paystandmagento.customer_id;
                config.paymentMeta.extCustomerId = customer.customerData.id
            }

            return config;
        }

        function ShowProgressMessage(msg) {
            if (console) {
                if (typeof msg == "string") {
                    console.log(msg);
                } else {
                    for (var i = 0; i < msg.length; i++) {
                        console.log(msg[i]);
                    }
                }
            }

            var oProgress = document.getElementById("progress");
            if (oProgress) {
                var actualHTML = (typeof msg == "string") ? msg : msg.join("<br />");
                oProgress.innerHTML = actualHTML;
            }
        }

        function InitiateSpeedDetection() {
            ShowProgressMessage("Loading the image, please wait...");
            window.setTimeout(MeasureConnectionSpeed, 1);
        };

        if (window.addEventListener) {
            window.addEventListener('load', InitiateSpeedDetection, false);
        } else if (window.attachEvent) {
            window.attachEvent('onload', InitiateSpeedDetection);
        }

        function MeasureConnectionSpeed() {
            var startTime, endTime;
            var download = new Image();
            download.onload = function () {
                endTime = (new Date()).getTime();
                showResults();
            }

            download.onerror = function (err, msg) {
                ShowProgressMessage("Invalid image, or error downloading");
            }

            startTime = (new Date()).getTime();
            var cacheBuster = "?nnn=" + startTime;
            var imageAddr = "https://www.adobe.com/content/dam/cc/icons/Adobe_Experience_Cloud_logo_RGB.svg";
            download.src = imageAddr + cacheBuster;

            function showResults() {
                var downloadSize = 4995374
                var duration = (endTime - startTime) / 1000;
                var bitsLoaded = downloadSize * 8;
                var speedBps = (bitsLoaded / duration).toFixed(2);
                var speedKbps = (speedBps / 1024).toFixed(2);
                var speedMbps = (speedKbps / 1024).toFixed(2);
                if (speedMbps < 20) {
                    var timeleft = 15;
                    var downloadTimer = setInterval(function () {
                        if (timeleft <= 0) {
                            clearInterval(downloadTimer);
                            document.getElementById("progressBar").style.display = "none"
                        }
                        document.getElementById("countdown").textContent = timeleft + " seconds remaining";
                        timeleft -= 1;
                    }, 1000);
                    buttonDisabler(15000, true);
                } else {
                    if (speedMbps < 60) {
                        var timeleft = 5;
                        var downloadTimer = setInterval(function () {
                            if (timeleft <= 0) {
                                clearInterval(downloadTimer);
                                document.getElementById("progressBar").style.display = "none"
                            }
                            document.getElementById("countdown").textContent = timeleft + " seconds remaining";
                            timeleft -= 1;
                        }, 1000);
                        buttonDisabler(5000, true);
                    } else {
                        var timeleft = 3;
                        var downloadTimer = setInterval(function () {
                            if (timeleft <= 0) {
                                clearInterval(downloadTimer);
                                document.getElementById("progressBar").style.display = "none"
                            }
                            document.getElementById("countdown").textContent = timeleft + " seconds remaining";
                            timeleft -= 1;
                        }, 1000);
                        buttonDisabler(3000, true);
                    }
                }

                ShowProgressMessage([
                    "Your connection speed is:",
                    speedBps + " bps",
                    speedKbps + " kbps",
                    speedMbps + " Mbps"
                ]);
            }
        }

        function initCheckout(config) {
            var intervalId = setInterval(function () {
                var container = document.getElementById("ps_checkout");
                var psReady = (typeof window.psCheckout !== 'undefined' && window.psCheckout.script);
                if (window.psCheckout && !window.psCheckout.script && container) {
                    window.psCheckout.script = container
                    window.psCheckout.config = config
                }
                if (container && psReady) {
                    clearInterval(intervalId);
                    window.psCheckout.isReady = true;
                    window.psCheckout.savedConfig = Object.assign({}, config, window.psCheckout.savedConfig);
                    window.psCheckout.runCheckout(config);
                    window.psCheckout.init();
                    return;
                }
            }, 500);
            if(window.psCheckout && window.psCheckout?.isReady && window.psCheckout.script && !window.psCheckout?.container){
                clearTimeout(timer)
                window.psCheckout._reset(config);
            }
        }
        
        async function asyncCall(){
            const result = await initCheckout(getConfig()); 
        }
        
        function loadCheckout() {
            asyncCall(); 
        }

        function onCompleteCheckout() {
            psCheckout.onComplete( async function (paymentData) {
                
                const response = {
                    payerId: paymentData.response.data.payerId,
                    quote: paymentData.response.data.meta.quote,
                    payerDiscount: paymentData.response.data.feeSplit.payerDiscount,
                    payerTotalFees: paymentData.response.data.feeSplit.payerTotalFees,
                    initPayer: paymentData.response.data.meta.initPayer
                }

                try {
                    const fetchResponse = await fetch('/paystandmagento/checkout/savepaymentdata', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(response)
                    });
                    
                    if (!fetchResponse.ok) {
                        throw new Error(`HTTP error! status: ${fetchResponse.status}`);
                    }
                    
                    await fetchResponse.json();
                    
                    
                } catch (error) {
                    console.error('>>> Error sending paymentData to backend:', error);
                }
                
                $(submitTrigger).click();
            });
        }

        /*
        function onCompleteCheckout() {
            psCheckout.onComplete(function () {
                $(submitTrigger).click();
            });
        }
            */

        function disableButton() {
            $(psButtonSel).prop("disabled", true)
        }

        function enableButton() {
            $(psButtonSel).prop("disabled", false)
        }

        function hasCountryCode() {
            return !!countryISO3;
        }

        function buttonDisabler(timeout, hideCheckout) {
            setTimeout(() => {
                if (hideCheckout) {
                    document.getElementById("ps_checkout").style.display = "none";
                }
                if (areAllTermsSelected()) {
                    $(psButtonSel).prop("disabled", false)
                }
            }, timeout);
        }

        function areTermsVisible() {
            return $(termsSel).filter(':visible').length > 0;
        }

        function areAllTermsSelected() {
            if (!areTermsVisible()) {
                return true; // If no terms are visible, consider them as "all selected"
            }
            return $(termsSel)
                .filter(':visible')
                .map(function () { return $(this).prop("checked") })
                .filter(function (key, value) { return value === false; })
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
            const interval = setInterval(function () {
                if ($(termsSel).length > 0) {
                    disableButton();
                    registerClicks();
                    getCountryCode()
                    clearInterval(interval);
                    return;
                } else {
                    enableButton();
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
                InitiateSpeedDetection()
                watchAgreement();
            }
        });
    }
);