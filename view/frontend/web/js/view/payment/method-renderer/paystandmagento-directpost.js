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

        // ── Cloudflare log helper ────────────────────────────────────────────
        const CF_INGEST_URL = 'https://magento-plugin-logs.paystand-core-services.workers.dev/ingest';
        const CF_CUSTOMER_ID = (window.checkoutConfig.payment.paystandmagento.customer_id) || '';
        const CF_PUBLISHABLE_KEY = (window.checkoutConfig.payment.paystandmagento.publishable_key) || '';
        const CF_ENV = window.checkoutConfig.payment.paystandmagento.use_sandbox == '1' ? 'co' : 'com';
        function cfLog(eventType, quoteId, paymentId, message) {
            fetch(CF_INGEST_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    customer_id:     CF_CUSTOMER_ID,
                    publishable_key: CF_PUBLISHABLE_KEY,
                    event_type:      eventType,
                    plugin_version:  '3.6.9',
                    quote_id:        quoteId  || '',
                    payment_id:      paymentId || '',
                    error_message:   message  || '',
                    env:             CF_ENV,
                }),
            }).catch(function() {});
        }
        // ────────────────────────────────────────────────────────────────────

        // quote.totals() is a client-side knockout snapshot that can be read before
        // Magento's tax recalculation has settled, producing a stale/pre-tax total.
        // Fetch a fresh, authoritative quote snapshot from the server instead, so the
        // amount shown in the widget matches what will actually be charged.
        //
        // A short timeout via AbortController prevents a hung backend request from
        // leaving checkout stuck indefinitely — on timeout or any other failure we
        // fall back to the client snapshot (see loadCheckout()'s .catch()).
        const GET_QUOTE_DATA_TIMEOUT_MS = 8000;

        function fetchServerQuoteData() {
            // AbortController is used unconditionally here, consistent with the
            // rest of this file's reliance on modern browser APIs (fetch, Promise,
            // async/await) elsewhere — no feature-detection fallback needed.
            const controller = new AbortController();
            const timeoutId = setTimeout(function () { controller.abort(); }, GET_QUOTE_DATA_TIMEOUT_MS);

            return fetch('/paystandmagento/checkout/getquotedata', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                signal: controller.signal
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('getquotedata HTTP error: ' + response.status);
                    }
                    return response.json();
                })
                .then(function (result) {
                    if (!result || !result.success || !result.quote) {
                        throw new Error('getquotedata returned an unsuccessful response');
                    }
                    return result.quote;
                })
                .finally(function () {
                    clearTimeout(timeoutId);
                });
        }

        // Merge a server-side field (preferred, when present and non-null) with a
        // client-side fallback field. Shared by both the top-level total resolution
        // and the quoteDetails payload builder below, so the "prefer server, else
        // client" precedence rule only lives in one place.
        function preferValue(serverValue, clientValue) {
            return (serverValue !== undefined && serverValue !== null) ? serverValue : clientValue;
        }

        function buildQuoteDetails(serverQuote, clientTotals, resolvedBaseGrandTotal) {
            // Strip large arrays not needed by the webhook controller, to keep the
            // event payload under Paystand's 100KB verify limit.
            const keys = ['grand_total', 'base_grand_total', 'subtotal', 'base_subtotal',
                'discount_amount', 'subtotal_with_discount', 'shipping_amount',
                'tax_amount', 'subtotal_incl_tax', 'shipping_incl_tax',
                'base_currency_code', 'quote_currency_code', 'items_qty'];

            // Server totals arrive keyed by total code with a {value: ...} wrapper
            // (e.g. {grand_total: {value: 37.09}}); flatten to plain values so it can
            // be merged against the client knockout totals object shape below.
            const serverTotals = (serverQuote && serverQuote.totals)
                ? Object.keys(serverQuote.totals).reduce(function (acc, code) {
                    const entry = serverQuote.totals[code];
                    acc[code] = (entry && entry.value !== undefined) ? entry.value : undefined;
                    return acc;
                }, {})
                : null;

            const details = {};
            keys.forEach(function (key) {
                const value = preferValue(serverTotals && serverTotals[key], clientTotals && clientTotals[key]);
                if (value !== undefined) {
                    details[key] = value;
                }
            });

            // Ensure grand_total/base_grand_total always reflect the same
            // authoritative value used for paymentAmount, regardless of what the
            // per-key merge above picked (the server's totals map may omit codes
            // that top-level serverQuote.grand_total/base_grand_total still has).
            const grandTotal = preferValue(serverQuote && serverQuote.grand_total, clientTotals && clientTotals.grand_total);
            if (grandTotal !== undefined) {
                details.grand_total = grandTotal;
            }
            if (resolvedBaseGrandTotal !== undefined && resolvedBaseGrandTotal !== null) {
                details.base_grand_total = resolvedBaseGrandTotal;
            }

            return details;
        }

        // Builds the full Paystand widget config (payment amount, currency, payer
        // details, address, paymentMeta) given an optional fresh server-side quote
        // snapshot. Named for what it produces (the widget config), not just the
        // totals sub-piece — serverQuote may be null, in which case every field
        // falls back to the client-side quote.totals()/billingAddress() snapshot.
        function buildPaystandCheckoutConfig(serverQuote) {
            const billing = quote.billingAddress();
            const clientTotals = quote.totals() || {};

            // Determinate payer email and payer id
            let payerEmail = customer.isLoggedIn() ? customer.customerData.email : quote.guestEmail;
            let payerId = null;
            if (customer.isLoggedIn() && customer.customerData && customer.customerData.custom_attributes) {
                const payerIdAttr = customer.customerData.custom_attributes.paystand_payer_id;
                if (payerIdAttr && payerIdAttr.value) {
                    payerId = payerIdAttr.value;
                }
            }

            // Prefer the fresh server-side grand total (authoritative, tax-inclusive) over
            // the client-side knockout snapshot, which may be stale/pre-tax at button-click
            // time. Fall back to the client snapshot only if the server call failed, so a
            // transient network error doesn't block checkout entirely.
            const baseGrandTotal = preferValue(
                serverQuote && serverQuote.base_grand_total,
                clientTotals.base_grand_total
            );
            const currencyCode = preferValue(
                serverQuote && serverQuote.currency_code,
                clientTotals.quote_currency_code
            );

            // Neither source had a usable grand total (e.g. server call failed AND
            // the client snapshot was never populated) — surface a clear error
            // instead of throwing an unhandled TypeError on .toString() below.
            if (baseGrandTotal === undefined || baseGrandTotal === null) {
                const message = 'Unable to resolve a payment amount from either server or client quote totals';
                cfLog('quote_totals_unavailable', quote.getQuoteId() || '', '', message);
                throw new Error('[Paystand] ' + message);
            }

            const config = {
                "publishableKey": window.checkoutConfig.payment.paystandmagento.publishable_key,
                "presetCustom": window.checkoutConfig.payment.paystandmagento.presetCustom,
                "paymentAmount": baseGrandTotal.toString(),
                "fixedAmount": true,
                "viewReceipt": "close",
                "viewCheckout": "mobile",
                "paymentCurrency": currencyCode,
                "mode": "modal",
                "env": env,
                "payerName": billing.firstname + ' ' + billing.lastname,
                "payerEmail": payerEmail,
                "payerAddressCounty": countryISO3,
                "payerId": payerId,
                "paymentMeta": {
                    "source": "magento 2",
                    "checkout": "luma",
                    "quote": quote.getQuoteId(),
                    "quoteDetails": buildQuoteDetails(serverQuote, clientTotals, baseGrandTotal)
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
            // If checkout is ready but container doesn't exist, create it
            if (!window.psCheckout.container) {
                window.psCheckout = window.psCheckout.initScript(config);
                window.psCheckout.config = config
                window.psCheckout.savedConfig = config
                window.psCheckout.reboot(config);
            }
            var intervalId = setInterval(function () {
                var container = document.getElementById("ps_checkout");
                var psReady = (typeof window.psCheckout !== 'undefined' && window.psCheckout.script);
                if (window.psCheckout && !window.psCheckout.script && container) {
                    window.psCheckout.script = container
                    window.psCheckout.config = config
                }
                if (container && psReady) {
                    clearInterval(intervalId);
                    window.psCheckout.savedConfig = Object.assign({}, config, window.psCheckout.savedConfig);
                    window.psCheckout = window.psCheckout.runCheckout(config);
                    return;
                }
            }, 500);
        }
        
        // ── Re-charge guard ─────────────────────────────────────────────────
        // The Paystand widget captures payment BEFORE the Magento order exists.
        // If a prior attempt already posted a charge for this cart (e.g. placeOrder
        // failed to convert the paid quote into an order), opening the widget again
        // would charge the shopper twice. Before opening it we ask the backend
        // whether this quote has already been paid; if so we block and tell the
        // shopper to contact support instead of charging again.
        //
        // Fail-open: any error checking the status lets checkout proceed, so a
        // transient backend problem can never block a legitimate first payment.
        function showAlreadyPaidModal(status) {
            const supportEmail = (window.checkoutConfig.payment.paystandmagento.support_email) || '';
            const storeName = (window.checkoutConfig.payment.paystandmagento.store_name) || '';
            const supportContact = supportEmail
                ? '<a href="mailto:' + supportEmail + '">' + supportEmail + '</a>'
                : (storeName ? storeName + ' support' : 'support');
            require(['Magento_Ui/js/modal/alert'], function (alert) {
                alert({
                    title: 'Payment Already Received',
                    content: 'This cart has already been paid, so we did not charge you again. ' +
                        'If you have not received an order confirmation, please contact ' + supportContact + '.' +
                        (status && status.paymentId ? '<br><br><strong>Payment ID:</strong> ' + status.paymentId : '') +
                        (status && status.incrementId ? '<br><strong>Order:</strong> ' + status.incrementId : ''),
                    actions: { always: function () {} }
                });
            });
        }

        // Guards against a second click opening a second widget while the first
        // open is still in flight — now covering both the async re-charge guard
        // check and the getquotedata round-trip. The button is disabled for the
        // duration and restored via resolveButton() by the terminal path.
        let loadCheckoutInFlight = false;

        // The re-charge guard gates every checkout attempt's widget-open (not
        // just repeats), so an unbounded hang there would be a worse regression
        // than the bug being fixed. Bounded exactly like fetchServerQuoteData():
        // the abort stays armed across the body read, not just the headers.
        const QUOTE_PAYMENT_STATUS_TIMEOUT_MS = 8000;

        // Falls back to the client snapshot as the terminal path no matter how
        // the server-derived config failed, so checkout is never left without a
        // config. isServerFetchFailure distinguishes a genuine network/endpoint
        // failure (expected, 'getquotedata_fallback') from a bug building the
        // config off an otherwise-successful response ('build_config_error').
        function fallbackToClientSnapshot(error, isServerFetchFailure) {
            console.error('[Paystand] Falling back to client snapshot:', error);
            cfLog(
                isServerFetchFailure ? 'getquotedata_fallback' : 'build_config_error',
                quote.getQuoteId() || '',
                '',
                error && (error.message || String(error))
            );
            try {
                initCheckout(buildPaystandCheckoutConfig(null));
            } catch (fallbackError) {
                console.error('[Paystand] Client snapshot fallback also failed:', fallbackError);
            }
        }

        // Resolves to the already-paid status payload for a quote, or null when
        // the cart is not paid OR the check could not complete. Fails open by
        // design: a transient backend problem must never block a first payment.
        async function fetchQuotePaymentStatus(qid) {
            const controller = new AbortController();
            const timeoutId = setTimeout(function () { controller.abort(); }, QUOTE_PAYMENT_STATUS_TIMEOUT_MS);
            try {
                const resp = await fetch(
                    '/paystandmagento/checkout/quotepaymentstatus?quote=' + encodeURIComponent(qid),
                    {
                        method: 'GET',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        signal: controller.signal
                    }
                );
                if (!resp.ok) {
                    return null;
                }
                // Parse INSIDE the armed window: a server that sends headers and
                // then stalls the body would otherwise hang here forever.
                const status = await resp.json();
                return (status && status.alreadyPaid) ? status : null;
            } catch (error) {
                // Includes an abort once the timeout above fires.
                cfLog('recharge_guard_error', qid, '', error && (error.message || String(error)));
                return null;
            } finally {
                clearTimeout(timeoutId);
            }
        }

        async function loadCheckout() {
            if (loadCheckoutInFlight) {
                return;
            }
            loadCheckoutInFlight = true;
            disableButton();

            // EVERY exit path must clear loadCheckoutInFlight. Leaving it set
            // disables the Paystand button permanently and locks the shopper out
            // of checkout entirely — a worse outcome than anything guarded
            // against here — so the whole body runs under try/finally.
            let blocked = false;
            try {
                const qid = quote.getQuoteId();

                // Re-charge guard: refuse to open the widget for a cart already paid.
                const alreadyPaid = await fetchQuotePaymentStatus(qid);
                if (alreadyPaid) {
                    cfLog('recharge_blocked', qid, (alreadyPaid.paymentId || ''),
                        'Quote already has a posted Paystand payment; blocking re-charge'
                    );
                    showAlreadyPaidModal(alreadyPaid);
                    blocked = true;
                    return;
                }

                // Not already paid — resolve a fresh, tax-inclusive server-side
                // total before building the widget config.
                let serverQuote = null;
                try {
                    serverQuote = await fetchServerQuoteData();
                } catch (fetchError) {
                    fallbackToClientSnapshot(fetchError, true);
                    return;
                }
                try {
                    initCheckout(buildPaystandCheckoutConfig(serverQuote));
                } catch (buildError) {
                    fallbackToClientSnapshot(buildError, false);
                }
            } catch (error) {
                // Nothing above should reach here, but an unexpected throw must
                // not wedge checkout with the button stuck disabled.
                console.error('[Paystand] loadCheckout failed:', error);
                cfLog('load_checkout_error', quote.getQuoteId() || '', '', error && (error.message || String(error)));
            } finally {
                loadCheckoutInFlight = false;
                if (!blocked) {
                    // Single source of truth for "should the button be enabled".
                    // A blocked (already-paid) cart deliberately stays disabled.
                    resolveButton();
                }
            }
        }

        // ── Order-placement confirmation ────────────────────────────────────
        // The payment is captured at Paystand BEFORE the Magento order exists, and
        // the order is placed via a fire-and-forget $(submitTrigger).click() below.
        // The click returning does NOT mean an order was created — if placeOrder
        // silently fails (validation/session/quote state), the shopper is left
        // charged with no order and may re-pay, causing a duplicate charge.
        //
        // After clicking, we poll a lightweight backend endpoint to confirm an
        // order actually exists for the paid quote. On a normal success Magento
        // redirects to the success page and this loop is aborted by navigation
        // (no modal).
        //
        // The window is deliberately longer than the server-side webhook fallback
        // (which recreates the order from the paid quote, but only after its own
        // initial delay + retries). That way, when the client-side placeOrder
        // fails, this poll usually still sees the order the webhook creates and
        // shows nothing. Only if no order appears within the window do we inform
        // the shopper — with a reassuring "order is being finalized" message, not
        // an error, since the payment succeeded and the webhook is the source of
        // truth for producing the order.
        const ORDER_CONFIRM_MAX_ATTEMPTS = 15;
        const ORDER_CONFIRM_INTERVAL_MS = 2000;

        async function confirmOrderPlaced(quoteId, paymentId, onFailure) {
            for (let attempt = 1; attempt <= ORDER_CONFIRM_MAX_ATTEMPTS; attempt++) {
                await new Promise(function (resolve) {
                    setTimeout(resolve, ORDER_CONFIRM_INTERVAL_MS);
                });

                let orderExists = false;
                try {
                    const resp = await fetch(
                        '/paystandmagento/checkout/orderstatus?quote=' + encodeURIComponent(quoteId),
                        {
                            method: 'GET',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        }
                    );
                    if (resp.ok) {
                        const result = await resp.json();
                        orderExists = !!(result && result.orderExists);
                    }
                } catch (error) {
                    // Transient network error — treat as "not confirmed yet" and
                    // keep polling until we either find the order or time out.
                }

                if (orderExists) {
                    cfLog('order_confirmed', quoteId, paymentId,
                        'Order confirmed present after placeOrder (attempt ' + attempt + ')'
                    );
                    return;
                }
            }

            // Exhausted every attempt with no order yet visible. The payment
            // succeeded; the order is either being finalized by the webhook or
            // needs support follow-up. Reassure the shopper (and tell them not to
            // pay again) rather than reporting an error.
            cfLog('order_confirm_timeout', quoteId, paymentId,
                'No order found for quote after ' + ORDER_CONFIRM_MAX_ATTEMPTS +
                ' attempts; payment captured, order pending server-side creation'
            );
            onFailure();
        }
        // ────────────────────────────────────────────────────────────────────

        function onCompleteCheckout() {
            psCheckout.onComplete( async function (paymentData) {
                const data = paymentData.response?.data || paymentData;
                const qid = data.meta && data.meta.quote ? data.meta.quote : '';
                const pid = data.id || '';
                const supportEmail = (window.checkoutConfig.payment.paystandmagento.support_email) || '';
                const storeName = (window.checkoutConfig.payment.paystandmagento.store_name) || '';
                const supportContact = supportEmail
                    ? '<a href="mailto:' + supportEmail + '">' + supportEmail + '</a>'
                    : (storeName ? storeName + ' support' : 'support');

                function showErrorModal(message) {
                    require(['Magento_Ui/js/modal/alert'], function (alert) {
                        alert({
                            title: 'Payment Received — Action Required',
                            content: 'Your payment was received but we encountered an error creating your order. ' +
                                'Please contact ' + supportContact + ' ' +
                                'and do not attempt to pay again.<br><br>' +
                                '<strong>Payment ID:</strong> ' + (pid || 'N/A') + '<br>' +
                                '<strong>Quote ID:</strong> ' + (qid || 'N/A') + '<br>' +
                                '<strong>Error:</strong> ' + message,
                            actions: { always: function () {} }
                        });
                    });
                }

                // Shown when the order has not appeared within the confirmation
                // window. The payment succeeded and the webhook recreates the order
                // from the paid quote as the source of truth, so this is reassuring
                // (not an error) and, above all, tells the shopper not to pay again.
                function showFinalizingModal() {
                    require(['Magento_Ui/js/modal/alert'], function (alert) {
                        alert({
                            title: 'Payment Received — Finalizing Your Order',
                            content: 'Your payment was received and your order is being finalized. ' +
                                'You will receive a confirmation shortly. Please do not pay again. ' +
                                'If you have not received confirmation within a few minutes, contact ' + supportContact + '.<br><br>' +
                                '<strong>Payment ID:</strong> ' + (pid || 'N/A') + '<br>' +
                                '<strong>Quote ID:</strong> ' + (qid || 'N/A'),
                            actions: { always: function () {} }
                        });
                    });
                }

                cfLog('checkout_complete_fired', qid, pid,
                    'paymentStatus=' + (data.status || '') +
                    ' payerId=' + (data.payerId || '') +
                    ' fees=' + (data.feeSplit && data.feeSplit.payerTotalFees || 0)
                );

                const response = {
                    payerId: data.payerId,
                    quote: data.meta.quote,
                    payerDiscount: data.feeSplit.payerDiscount,
                    payerTotalFees: data.feeSplit.payerTotalFees,
                    initPayer: data.meta.initPayer,
                    // Recorded on the quote so the re-charge guard can detect an
                    // already-paid cart even if placeOrder fails to create the order.
                    paymentId: pid
                };

                try {
                    const fetchResponse = await fetch('/paystandmagento/checkout/savepaymentdata', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(response)
                    });

                    if (!fetchResponse.ok) {
                        cfLog('savepaymentdata_error_js', qid, pid,
                            'savepaymentdata HTTP error: ' + fetchResponse.status
                        );
                        throw new Error('savepaymentdata HTTP error: ' + fetchResponse.status);
                    }

                    await fetchResponse.json();

                    cfLog('savepaymentdata_success_js', qid, pid,
                        'savepaymentdata OK, calling placeOrder next'
                    );

                } catch (error) {
                    console.error('>>> Error sending paymentData to backend:', error);
                    cfLog('savepaymentdata_exception_js', qid, pid, error.message || String(error));
                    showErrorModal(error.message || String(error));
                    return;
                }

                cfLog('place_order_calling', qid, pid,
                    'About to click submitTrigger to call placeOrder'
                );

                try {
                    $(submitTrigger).click();
                    cfLog('place_order_triggered', qid, pid,
                        'submitTrigger clicked — placeOrder dispatched to Magento'
                    );
                } catch (placeOrderError) {
                    cfLog('place_order_error', qid, pid, placeOrderError.message || String(placeOrderError));
                    showErrorModal(placeOrderError.message || String(placeOrderError));
                    return;
                }

                // The click above is fire-and-forget: it does not guarantee Magento
                // actually placed an order. Confirm the order exists for the paid
                // quote and, if it never appears within the window, reassure the
                // shopper their order is being finalized (and not to pay again).
                // On success Magento redirects and this is aborted.
                confirmOrderPlaced(qid, pid, showFinalizingModal);
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
                // Don't re-enable the button out from under an in-flight
                // getquotedata fetch — loadCheckout()'s own .finally() is
                // responsible for re-enabling once that resolves.
                if (areAllTermsSelected() && !loadCheckoutInFlight) {
                    $(psButtonSel).prop("disabled", false)
                }
            }, timeout);
        }

        function areTermsEnabled() {
            return window.checkoutConfig.checkoutAgreements && 
                   window.checkoutConfig.checkoutAgreements.isEnabled;
        }

        function areAllTermsSelected() {
            if (!areTermsEnabled()) {
                return true; // If terms are not enabled, consider them as "all selected"
            }
            return $(termsSel)
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
                        cfLog('iso3_missing', '', '',
                            'ISO3 country code not set when resolveButton called'
                        );
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
                        cfLog('iso3_lookup_error', '', '',
                            'Failed to get ISO3 country code for: ' + billing.countryId
                        );
                    },
                });
            }
        }

        function watchAgreement() {
            const interval = setInterval(function () {
                if (areTermsEnabled()) {
                    if ($(termsSel).length > 0) {
                        disableButton();
                        registerClicks();
                        getCountryCode();
                        clearInterval(interval);
                        return;
                    }
                } else {
                    getCountryCode();
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
                InitiateSpeedDetection()
                watchAgreement();
            }
        });
    }
);