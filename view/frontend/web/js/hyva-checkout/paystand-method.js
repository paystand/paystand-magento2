/**
 * Hyva Checkout - Paystand Payment Method Integration
 */
(function() {
    'use strict';
    
    const scriptTag = document.currentScript || document.querySelector('script[data-paystand-config]');
    
    if (scriptTag && scriptTag.dataset.paystandConfig) {
        try {
            window.paystandConfig = JSON.parse(scriptTag.dataset.paystandConfig);
        } catch (e) {
            console.error('[Paystand Hyva] Failed to parse configuration:', e);
            window.paystandConfig = {};
        }
    } else {
        window.paystandConfig = {};
    }
    
    let paystandButton = null;
    let paystandContainer = null;
    let psCheckoutInstance = null;
    let validationFormEl = null;
    let validationHandler = null;
    let validationWatchActive = false;
    let livewireHookRegistered = false;
    let paystandCallbacksRegistered = false;
    let placeOrderButtonObserver = null;
    let shouldDisablePlaceOrder = false;
    
    const useSandbox = window.paystandConfig.useSandbox;
    const env = window.paystandConfig.environment || (useSandbox ? 'sandbox' : 'live');
    const apiDomain = useSandbox ? 'api.paystand.io' : 'api.paystand.com';
    
    /** Fetch quote data from server */
    async function getQuoteData() {
        try {
            const response = await fetch('/paystandmagento/checkout/getquotedata', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            const result = await readJsonResponse(response);
            
            if (result.success && result.quote) {
                return {
                    totals: result.quote.totals || {},
                    quoteId: result.quote.id || null,
                    grandTotal: result.quote.grand_total || 0,
                    currency: result.quote.currency_code || 'USD'
                };
            }
            
        } catch (error) {
            console.error('[Paystand Hyva] Failed to fetch quote data:', error);
        }
        
        return { totals: {}, quoteId: null, grandTotal: 0, currency: 'USD' };
    }

    /** JSON from a response */
    async function readJsonResponse(response) {
        const clone = response.clone();
        try {
            return await response.json();
        } catch (error) {
            const text = await clone.text();
            const message = text ? `Invalid JSON response: ${text}` : 'Invalid JSON response';
            throw new Error(message);
        }
    }
    
    /** Check if all required checkout fields are complete */
    function isCheckoutComplete() {
        const checkoutContainer = document.querySelector('[wire\\:id="hyva-checkout-main"], .checkout-container, #checkout');
        if (!checkoutContainer) {
            return { complete: false, missing: 'checkout container' };
        }
        
        const requiredInputs = checkoutContainer.querySelectorAll('input[required]:not([type="hidden"]), select[required], textarea[required]');
        const requiredByWire = checkoutContainer.querySelectorAll('[wire\\:model\\.defer][required], [wire\\:model][required]');
        
        const allRequired = new Set([...requiredInputs, ...requiredByWire]);
        
        for (const input of allRequired) {
            if (input.offsetParent === null || input.type === 'hidden') continue;
            
            const value = input.value?.trim() || '';
            if (!value) {
                const fieldName = input.name || input.id || input.getAttribute('wire:model.defer') || 'unknown';
                return { complete: false, missing: fieldName };
            }
        }
        
        const isLoggedIn = window.checkoutConfig?.isCustomerLoggedIn || false;
        if (!isLoggedIn) {
            const emailInput = checkoutContainer.querySelector('input[type="email"]');
            if (emailInput && emailInput.offsetParent !== null) {
                if (!emailInput.value || !emailInput.value.includes('@')) {
                    return { complete: false, missing: 'email' };
                }
            }
        }
        
        const cityInput = checkoutContainer.querySelector('input[name*="city"], input[id*="city"], [wire\\:model\\.defer*="city"], [wire\\:model*="city"]');
        if (cityInput && cityInput.offsetParent !== null) {
            if (!cityInput.value || !cityInput.value.trim()) {
                return { complete: false, missing: 'city' };
            }
        }
        
        const isVirtual = window.checkoutConfig?.isVirtual || false;
        if (!isVirtual) {
            const shippingMethodRadio = checkoutContainer.querySelector('input[type="radio"][name*="shipping"]:checked');
            const shippingMethodDiv = checkoutContainer.querySelector('.shipping-method-selected, [data-shipping-method-selected]');
            
            let livewireHasMethod = false;
            const shippingComp = Livewire?.components?.componentsById?.['checkout.shipping-method'];
            if (shippingComp && shippingComp.data?.method) {
                livewireHasMethod = true;
            }
            
            if (!shippingMethodRadio && !shippingMethodDiv && !livewireHasMethod) {
                return { complete: false, missing: 'shipping method' };
            }
        }
        
        return { complete: true, missing: null };
    }
    
    /** Update Paystand button state based on checkout completion */
    function updatePaystandButtonState() {
        const button = document.querySelector('.paystand-pay-button');
        const statusText = document.querySelector('.paystand-status-text');
        
        if (!button) return;
        
        const validation = isCheckoutComplete();
        
        if (validation.complete) {
            button.disabled = false;
            button.style.backgroundColor = '#00ACEE';
            button.textContent = 'Pay with Paystand';
            if (statusText) {
                statusText.style.display = 'none';
            }
        } else {
            button.disabled = true;
            button.style.backgroundColor = '#9CA3AF';
            button.textContent = 'Pay with Paystand';
            if (statusText) {
                statusText.style.display = 'block';
                const fieldName = formatFieldName(validation.missing);
                const action = fieldName === 'shipping method' ? 'select' : 'enter';
                statusText.textContent = `Please ${action} ${fieldName}`;
            }
        }
    }
    
    /** Start watching for form changes */
    function startValidationWatch() {
        if (validationWatchActive) {
            return;
        }
        validationWatchActive = true;

        validationHandler = debounce(updatePaystandButtonState, 300);
        validationFormEl = document.querySelector('form[id*="checkout"], .checkout-container, [wire\\:id="hyva-checkout-main"]');
        if (validationFormEl) {
            validationFormEl.addEventListener('input', validationHandler);
            validationFormEl.addEventListener('change', validationHandler);
        }

        if (typeof Livewire !== 'undefined' && !livewireHookRegistered) {
            Livewire.hook('message.processed', () => {
                const paystandRadio = document.querySelector('input[value="paystandmagento"]');
                if (paystandRadio && paystandRadio.checked) {
                    updatePaystandButtonState();
                    // Re-inject button if it was removed by Livewire re-render
                    setTimeout(injectPaystandButton, 100);
                }
            });
            livewireHookRegistered = true;
        }
    }
    
    /** Stop validation watch */
    function stopValidationWatch() {
        if (!validationWatchActive) {
            return;
        }
        if (validationFormEl && validationHandler) {
            validationFormEl.removeEventListener('input', validationHandler);
            validationFormEl.removeEventListener('change', validationHandler);
        }
        validationFormEl = null;
        validationHandler = null;
        validationWatchActive = false;
    }
    
    /** Simple debounce utility */
    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }
    
    /** Format field name for display */
    function formatFieldName(fieldName) {
        if (!fieldName) return 'required field';
        return fieldName
            .replace(/[^a-zA-Z\s]/g, ' ')
            .replace(/([a-z])([A-Z])/g, '$1 $2')
            .toLowerCase()
            .trim()
            .replace(/\s+/g, ' ');
    }
    
    /** Build Paystand modal configuration */
    async function buildPaystandConfig() {
        // Refresh Livewire components to sync deferred data to server first
        // Required for one-page checkout where wire:model.defer is used
        const componentsToRefresh = [
            'checkout.guest-details',
            'checkout.shipping-details',
            'checkout.billing-details',
            'checkout.shipping-details.address-form'
        ];
        
        for (const compId of componentsToRefresh) {
            const comp = Livewire.components?.componentsById?.[compId];
            if (comp) {
                await comp.call('$refresh');
            }
        }
        
        const response = await fetch('/paystandmagento/checkout/getquotedata', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const serverData = await readJsonResponse(response);
        
        if (!serverData.success) {
            console.error('[Paystand Hyva] Server returned unsuccessful response for quote data');
            return null;
        }
        
        const quote = {
            totals: serverData.quote.totals || {},
            quoteId: serverData.quote.id || null,
            grandTotal: serverData.quote.grand_total || 0,
            currency: serverData.quote.currency_code || 'USD'
        };
        
        const billing = serverData.billing || {};
        const customer = serverData.customer || { isLoggedIn: false, email: null, id: null, payerId: null };
        
        const payerEmail = customer.isLoggedIn ? customer.email : (billing.email || '');
        const payerName = (billing.firstname || '') + ' ' + (billing.lastname || '');
        
        let payerId = null;
        if (customer.isLoggedIn && customer.payerId) {
            payerId = customer.payerId;
        }
        
        const config = {
            "publishableKey": window.paystandConfig.publishableKey,
            "presetCustom": window.paystandConfig.presetCustom,
            "paymentAmount": quote.grandTotal.toString(),
            "fixedAmount": true,
            "viewReceipt": "close",
            "viewCheckout": "mobile",
            "paymentCurrency": quote.currency,
            "mode": "modal",
            "env": env,
            "payerName": payerName.trim() || "Guest",
            "payerEmail": payerEmail,
            "payerAddressCounty": null,
            "payerId": payerId,
            "paymentMeta": {
                "source": "magento 2",
                "checkout": "hyva",
                "quote": quote.quoteId,
                "quoteDetails": quote.totals
            }
        };
        
        console.log('[HYVA Paystand] Config enviado a modal:', config);
        
        if (window.paystandConfig.accessToken) {
            config.accessToken = window.paystandConfig.accessToken;
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
        if (billing.region_code) {
            config.payerAddressState = billing.region_code;
        }
        
        if (customer.isLoggedIn && config.accessToken) {
            delete config.presetCustom;
            delete config.publishableKey;
            config.checkoutType = 'checkout_magento2';
            config.customerId = window.paystandConfig.customerId;
            config.paymentMeta.extCustomerId = customer.id;
        }
        
        return config;
    }

    /** Display an inline error near the Paystand button */
    function setPaystandError(message) {
        const errorEl = document.querySelector('.paystand-error-text');
        if (!errorEl) return;

        if (message) {
            errorEl.textContent = message;
            errorEl.style.display = 'block';
        } else {
            errorEl.textContent = '';
            errorEl.style.display = 'none';
        }
    }
    
    /** Wait for Paystand SDK to load */
    function waitForPaystandSDK() {
        return new Promise((resolve, reject) => {
            let attempts = 0;
            const maxAttempts = 20;
            
            const checkSDK = setInterval(() => {
                if (typeof window.psCheckout !== 'undefined') {
                    clearInterval(checkSDK);
                    resolve();
                } else if (attempts >= maxAttempts) {
                    clearInterval(checkSDK);
                    console.error('[Paystand Hyva] SDK failed to load after maximum attempts');
                    reject(new Error('Paystand SDK not loaded'));
                }
                attempts++;
            }, 500);
        });
    }
    
    /** Initialize Paystand checkout */
    function initCheckout(config) {
        if (!window.psCheckout.container) {
            window.psCheckout = window.psCheckout.initScript(config);
            window.psCheckout.config = config;
            window.psCheckout.savedConfig = config;
            window.psCheckout.reboot(config);
        }
        
        var intervalId = setInterval(function () {
            var container = document.getElementById("ps_checkout");
            var psReady = (typeof window.psCheckout !== 'undefined' && window.psCheckout.script);
            
            if (window.psCheckout && !window.psCheckout.script && container) {
                window.psCheckout.script = container;
                window.psCheckout.config = config;
            }
            
            if (container && psReady) {
                clearInterval(intervalId);
                window.psCheckout.savedConfig = Object.assign({}, config, window.psCheckout.savedConfig);
                window.psCheckout = window.psCheckout.runCheckout(config);
                return;
            }
        }, 500);
    }
    
    /** Register Paystand checkout event handlers */
    function registerPaystadCallbacks() {
        if (paystandCallbacksRegistered) {
            return;
        }
        paystandCallbacksRegistered = true;

        window.psCheckout.onComplete(async function(paymentData) {
            console.log('[Paystand Hyva] Payment completed, full data received:', paymentData);
            
            const data = paymentData.response?.data || paymentData;
            
            console.log('[Paystand Hyva] Extracted data:', data);
            
            const response = {
                payerId: data.payerId,
                quote: data.meta.quote,
                payerDiscount: data.feeSplit.payerDiscount,
                payerTotalFees: data.feeSplit.payerTotalFees,
                initPayer: data.meta.initPayer
            };
            
            console.log('[Paystand Hyva] Sending payment data to backend:', response);
            console.log('[Paystand Hyva] initPayer flag:', response.initPayer);
            
            try {
                const fetchResponse = await fetch('/paystandmagento/checkout/savepaymentdata', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(response)
                });
                
                if (!fetchResponse.ok) {
                    throw new Error(`HTTP error! status: ${fetchResponse.status}`);
                }
                
                const result = await readJsonResponse(fetchResponse);
                console.log('[Paystand Hyva] Backend response:', result);

                if (result && result.success === false) {
                    const message = result?.error?.message || 'Payment data could not be saved. Please try again.';
                    setPaystandError(message);
                    throw new Error(message);
                }
                
                const mainComponent = Livewire.components.componentsById['hyva-checkout-main'];
                if (mainComponent) {
                    try {
                        // Refresh Livewire components to sync deferred data before placing order
                        const componentsToRefresh = [
                            'checkout.guest-details',
                            'checkout.shipping-details',
                            'checkout.billing-details',
                            'checkout.shipping-details.address-form'
                        ];
                        
                        for (const compId of componentsToRefresh) {
                            const comp = Livewire.components?.componentsById?.[compId];
                            if (comp) {
                                await comp.call('$refresh');
                            }
                        }
                        
                        await mainComponent.call('placeOrder');
                    } catch (orderError) {
                        console.error('[Paystand Hyva] Order placement failed:', orderError);
                        setPaystandError('Order placement failed. Please try again or contact support.');
                    }
                } else {
                    console.error('[Paystand Hyva] Could not find hyva-checkout-main Livewire component');
                }
                
            } catch (error) {
                console.error('[Paystand Hyva] Error during payment completion:', error);
                setPaystandError('An error occurred while processing your order. Please try again.');
            }
        });
        
        if (typeof window.psCheckout.onError === 'function') {
            window.psCheckout.onError(function(errorData) {
                console.error('[Paystand Hyva] Payment error:', errorData);
                setPaystandError('Payment failed. Please try again or use a different payment method.');
            });
        }
        
        if (typeof window.psCheckout.onCancel === 'function') {
            window.psCheckout.onCancel(function() {
                console.log('[Paystand Hyva] Payment cancelled by user');
            });
        }
    }
    
    /** Open Paystand modal */
    async function openPaystandModal() {
        try {
            await waitForPaystandSDK();
            
            const config = await buildPaystandConfig();
            
            if (!config) {
                console.error('[Paystand Hyva] Failed to build checkout configuration');
                setPaystandError('Error opening Paystand checkout. Please try again.');
                return;
            }
            
            let container = document.getElementById('ps_checkout');
            if (!container) {
                container = document.createElement('div');
                container.id = 'ps_checkout';
                document.body.appendChild(container);
            }
            
            initCheckout(config);
            
            setTimeout(() => {
                registerPaystadCallbacks();
            }, 1000);
            
        } catch (error) {
            console.error('[Paystand Hyva] Error opening checkout modal:', error);
            setPaystandError('Error opening Paystand checkout. Please try again.');
            throw error;
        }
    }
    
    /** Create "Pay with Paystand" button */
    function createPaystandButton() {
        if (paystandButton) return paystandButton;
        
        const buttonContainer = document.createElement('div');
        buttonContainer.className = 'paystand-button-container flex flex-col items-center w-full mt-3 mb-2';
        
        const psCheckoutDiv = document.createElement('div');
        psCheckoutDiv.id = 'ps_checkout';
        buttonContainer.appendChild(psCheckoutDiv);
        
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'paystand-pay-button text-white text-sm py-2 px-4 rounded-sm font-medium transition-all duration-200 disabled:cursor-not-allowed hover:opacity-90';
        button.style.backgroundColor = '#9CA3AF';
        button.textContent = 'Pay with Paystand';
        button.disabled = true;
        
        const statusText = document.createElement('p');
        statusText.className = 'paystand-status-text text-sm text-gray-600 mt-2';
        statusText.textContent = 'Complete all required fields to continue';

        const errorText = document.createElement('p');
        errorText.className = 'paystand-error-text text-sm text-red-600 mt-2';
        errorText.style.display = 'none';
        
        button.addEventListener('click', function() {
            if (!this.disabled) {
                setPaystandError('');
                button.disabled = true;
                button.innerHTML = `
                    <span style="display: inline-flex; align-items: center; gap: 8px;">
                        <svg style="animation: spin 1s linear infinite; width: 16px; height: 16px;" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" opacity="0.25"/>
                            <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="4" stroke-linecap="round"/>
                        </svg>
                        Opening Paystand...
                    </span>
                `;
                
                if (!document.getElementById('paystand-spinner-style')) {
                    const style = document.createElement('style');
                    style.id = 'paystand-spinner-style';
                    style.textContent = `
                        @keyframes spin {
                            from { transform: rotate(0deg); }
                            to { transform: rotate(360deg); }
                        }
                    `;
                    document.head.appendChild(style);
                }
                
                openPaystandModal().finally(() => {
                    setTimeout(() => {
                        button.disabled = false;
                        button.style.backgroundColor = '#00ACEE';
                        button.textContent = 'Pay with Paystand';
                    }, 1000);
                });
            }
        });
        
        buttonContainer.appendChild(button);
        buttonContainer.appendChild(statusText);
        buttonContainer.appendChild(errorText);
        paystandButton = buttonContainer;
        
        startValidationWatch();
        setTimeout(updatePaystandButtonState, 100);
        
        return paystandButton;
    }
    
    /** Disable/Enable the main Place Order button */
    function togglePlaceOrderButton(disable) {
        const placeOrderButton = document.querySelector('button.btn-place-order');
        
        if (placeOrderButton) {
            shouldDisablePlaceOrder = disable;
            
            if (disable) {
                placeOrderButton.disabled = true;
                placeOrderButton.setAttribute('data-paystand-disabled', 'true');
                startObservingPlaceOrderButton(placeOrderButton);
            } else {
                stopObservingPlaceOrderButton();
                placeOrderButton.disabled = false;
                placeOrderButton.removeAttribute('data-paystand-disabled');
            }
        }
    }
    
    /** Start observing Place Order button to keep it disabled */
    function startObservingPlaceOrderButton(button) {
        stopObservingPlaceOrderButton();
        
        placeOrderButtonObserver = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.type === 'attributes' && mutation.attributeName === 'disabled') {
                    if (shouldDisablePlaceOrder && !button.disabled) {
                        button.disabled = true;
                    }
                }
            });
        });
        
        placeOrderButtonObserver.observe(button, {
            attributes: true,
            attributeFilter: ['disabled']
        });
    }
    
    /** Stop observing Place Order button */
    function stopObservingPlaceOrderButton() {
        if (placeOrderButtonObserver) {
            placeOrderButtonObserver.disconnect();
            placeOrderButtonObserver = null;
        }
    }
    
    /** Inject button into the payment method container */
    function injectPaystandButton() {
        const radioInput = document.querySelector('input[value="paystandmagento"]');
        if (!radioInput || !radioInput.checked) {
            return;
        }
        
        togglePlaceOrderButton(true);
        
        // Add logo to label if missing
        const label = radioInput.nextElementSibling;
        if (label && !label.querySelector('.paystand-logo') && window.paystandConfig.logoUrl) {
            label.textContent = '';
            
            const logo = document.createElement('img');
            logo.src = window.paystandConfig.logoUrl;
            logo.alt = 'PayStand';
            logo.className = 'paystand-logo';
            logo.style.cssText = 'height: 20px;';
            label.appendChild(logo);
        }
        
        // Add button if missing
        const existingButton = document.querySelector('.paystand-button-container');
        if (!existingButton) {
            const container = radioInput.closest('div[class*="border"]') || radioInput.parentElement;
            if (container) {
                container.appendChild(createPaystandButton());
            }
        }
    }
    
    /** Initialize payment method */
    function initialize() {
        setTimeout(injectPaystandButton, 500);
        setupPaymentMethodListener();
        
        if (typeof Livewire !== 'undefined') {
            Livewire.hook('message.processed', () => {
                const paystandRadio = document.querySelector('input[value="paystandmagento"]');
                if (paystandRadio && paystandRadio.checked) {
                    setTimeout(() => togglePlaceOrderButton(true), 100);
                } else {
                    setTimeout(() => togglePlaceOrderButton(false), 100);
                }
            });
        }
    }
    
    /** Setup listener for payment method changes */
    function setupPaymentMethodListener() {
        document.addEventListener('change', function(e) {
            const target = e.target;
            
            if (target.type === 'radio') {
                if (target.name.includes('payment') || target.value === 'paystandmagento' || target.closest('[class*="payment"]')) {
                    const paystandRadio = document.querySelector('input[value="paystandmagento"]');
                    
                    if (target.value === 'paystandmagento' && target.checked) {
                        setTimeout(injectPaystandButton, 100);
                    } else if (paystandRadio && !paystandRadio.checked) {
                        onMethodDeselect();
                    }
                }
            }
        });
        
        document.addEventListener('click', function(e) {
            const target = e.target;
            
            if (target.type === 'radio' || target.tagName === 'LABEL' || target.closest('label')) {
                setTimeout(() => {
                    const paystandRadio = document.querySelector('input[value="paystandmagento"]');
                    
                    if (paystandRadio && paystandRadio.checked) {
                        setTimeout(injectPaystandButton, 100);
                    } else if (paystandRadio && !paystandRadio.checked) {
                        onMethodDeselect();
                    }
                }, 50);
            }
        });
    }
    
    /** Cleanup when method is deselected */
    function onMethodDeselect() {
        stopValidationWatch();
        togglePlaceOrderButton(false);
        
        const existingButton = document.querySelector('.paystand-button-container');
        if (existingButton) {
            existingButton.remove();
        }
        
        paystandButton = null;
    }
    
    /** Validate payment method */
    function validate() {
        return true;
    }
    
    /** Place order */
    function placeOrder() {
        return true;
    }
    
    // Register payment method with Hyva Checkout
    if (typeof hyvaCheckout !== 'undefined' && hyvaCheckout.api) {
        hyvaCheckout.api.after(() => {
            hyvaCheckout.payment.registerMethod({
                code: 'paystandmagento',
                method: {
                    initialize: initialize,
                    validate: validate,
                    placeOrder: placeOrder
                }
            });
        });
    }
})();
