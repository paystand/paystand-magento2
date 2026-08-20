/**
 * Records where the shopper actually landed after paying. Checkout writes a marker
 * before placeOrder redirects; this reads it on the next page load and reports the
 * landing page, which distinguishes a normal confirmation from a bounce to the cart.
 * Loaded as a plain script by layout so it runs on every frontend page.
 */
(function () {
    'use strict';

    var KEY = 'ps_nav_trace';
    var CF_INGEST_URL = 'https://magento-plugin-logs.paystand-core-services.workers.dev/ingest';
    var PLUGIN_VERSION = '3.7.2';
    // Ignore a marker left by an abandoned session rather than reporting an
    // unrelated page as the landing page.
    var MAX_AGE_MS = 15 * 60 * 1000;

    function readMarker() {
        var raw;
        try {
            raw = window.sessionStorage.getItem(KEY);
            if (!raw) {
                return null;
            }
            // One-shot: clear before reporting so a reload cannot log twice.
            window.sessionStorage.removeItem(KEY);
        } catch (e) {
            return null;
        }

        var marker;
        try {
            marker = JSON.parse(raw);
        } catch (e) {
            return null;
        }
        if (!marker || marker.v !== 1 || (Date.now() - marker.t) > MAX_AGE_MS) {
            return null;
        }
        return marker;
    }

    function classify(path) {
        if (path.indexOf('/checkout/onepage/success') !== -1) {
            return 'success';
        }
        if (path.indexOf('/checkout/cart') !== -1) {
            return 'cart';
        }
        if (path.indexOf('/checkout') !== -1) {
            return 'checkout';
        }
        return 'other';
    }

    function report(marker) {
        var landed = classify(window.location.pathname);
        // Confirms the landing page rendered what it should have: the success block
        // on a confirmation, an empty-cart notice on a bounce.
        var successBlock = !!document.querySelector('.checkout-success');
        var cartEmpty = !!document.querySelector('.cart-empty');

        var message = 'landed=' + landed +
            ' path=' + window.location.pathname +
            ' success_block=' + (successBlock ? 'yes' : 'no') +
            ' cart_empty=' + (cartEmpty ? 'yes' : 'no') +
            ' elapsed=' + (Date.now() - marker.t) + 'ms';

        fetch(CF_INGEST_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            keepalive: true,
            body: JSON.stringify({
                customer_id: marker.c || '',
                publishable_key: marker.k || '',
                event_type: 'nav_landed',
                plugin_version: PLUGIN_VERSION,
                quote_id: marker.q || '',
                payment_id: marker.p || '',
                error_message: message,
                env: marker.e || 'com'
            })
        }).catch(function () {});
    }

    var marker = readMarker();
    if (!marker) {
        return;
    }
    // The rendered-state checks above need the DOM.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            report(marker);
        });
    } else {
        report(marker);
    }
})();
