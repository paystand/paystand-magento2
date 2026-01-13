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
    
    const useSandbox = window.paystandConfig.useSandbox;
    const env = window.paystandConfig.environment || (useSandbox ? 'sandbox' : 'live');
    const apiDomain = useSandbox ? 'api.paystand.biz' : 'api.paystand.com';
    
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
            
            const result = await response.json();
            
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
    
    /** Get billing address from Hyva stores */
    function getBillingAddress() {
        if (typeof Alpine !== 'undefined' && Alpine.store('checkout')) {
            const checkoutStore = Alpine.store('checkout');
            const billing = checkoutStore.billingAddress || {};
            
            if (billing && Object.keys(billing).length > 0) {
                return billing;
            }
        }
        
        if (window.checkoutConfig && window.checkoutConfig.shippingAddressFromData) {
            return window.checkoutConfig.shippingAddressFromData;
        }
        
        return {};
    }
    
    /** Get customer data from Hyva stores */
    function getCustomerData() {
        if (typeof Alpine !== 'undefined' && Alpine.store('customer')) {
            const customerStore = Alpine.store('customer');
            const isLoggedIn = customerStore.isLoggedIn || false;
            
            let payerId = null;
            if (isLoggedIn && customerStore.custom_attributes) {
                const payerIdAttr = customerStore.custom_attributes.paystand_payer_id;
                if (payerIdAttr && payerIdAttr.value) {
                    payerId = payerIdAttr.value;
                }
            }
            
            return {
                isLoggedIn: isLoggedIn,
                email: customerStore.email || null,
                id: customerStore.id || null,
                payerId: payerId
            };
        }
        
        const isLoggedIn = window.checkoutConfig?.isCustomerLoggedIn || false;
        const customerData = window.checkoutConfig?.customerData || {};
        
        let payerId = null;
        if (isLoggedIn && customerData.custom_attributes) {
            const payerIdAttr = customerData.custom_attributes.paystand_payer_id;
            if (payerIdAttr && payerIdAttr.value) {
                payerId = payerIdAttr.value;
            }
        }
        
        return {
            isLoggedIn: isLoggedIn,
            email: customerData.email || null,
            id: customerData.id || null,
            payerId: payerId
        };
    }
    
    /** Build Paystand modal configuration */
    async function buildPaystandConfig() {
        // Refresh Livewire components to sync wire:model.defer data
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
        
        const serverData = await fetch('/paystandmagento/checkout/getquotedata', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(r => r.json());
        
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
            "payerEmail": payerEmail || "guest@example.com",
            "payerAddressCounty": null,
            "payerId": customer.payerId,
            "paymentMeta": {
                "source": "magento 2",
                "quote": quote.quoteId,
                "quoteDetails": quote.totals
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
        if (billing.region_code) {
            config.payerAddressState = billing.region_code;
        }
        
        if (customer.isLoggedIn && window.paystandConfig.accessToken) {
            config.accessToken = window.paystandConfig.accessToken;
            config.checkoutType = "checkout_magento2";
            config.customerId = window.paystandConfig.customerId;
            config.paymentMeta.extCustomerId = customer.id;
            
            delete config.publishableKey;
            delete config.presetCustom;
        }
        
        return config;
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
        window.psCheckout.onComplete(async function(paymentData) {
            const response = {
                payerId: paymentData.response.data.payerId,
                quote: paymentData.response.data.meta.quote,
                payerDiscount: paymentData.response.data.feeSplit.payerDiscount,
                payerTotalFees: paymentData.response.data.feeSplit.payerTotalFees,
                initPayer: paymentData.response.data.meta.initPayer
            };
            
            try {
                const fetchResponse = await fetch('/paystandmagento/checkout/savepaymentdata', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(response)
                });
                
                if (!fetchResponse.ok) {
                    throw new Error(`HTTP error! status: ${fetchResponse.status}`);
                }
                
                await fetchResponse.json();
                
                const mainComponent = Livewire.components.componentsById['hyva-checkout-main'];
                if (mainComponent) {
                    try {
                        // Refresh Livewire components to sync wire:model.defer data
                        const componentsToRefresh = [
                            'checkout.guest-details',
                            'checkout.shipping-details',
                            'checkout.billing-details',
                            'checkout.shipping-details.address-form'
                        ];
                        
                        for (const compId of componentsToRefresh) {
                            const comp = Livewire.components.componentsById[compId];
                            if (comp) {
                                await comp.call('$refresh');
                            }
                        }
                        
                        await mainComponent.call('placeOrder');
                    } catch (orderError) {
                        console.error('[Paystand Hyva] Order placement failed:', orderError);
                        alert('Order placement failed. Please try again or contact support.');
                    }
                } else {
                    console.error('[Paystand Hyva] Could not find hyva-checkout-main Livewire component');
                }
                
            } catch (error) {
                console.error('[Paystand Hyva] Error during payment completion:', error);
                alert('An error occurred while processing your order. Please try again.');
            }
        });
        
        if (typeof window.psCheckout.onError === 'function') {
            window.psCheckout.onError(function(errorData) {
                console.error('[Paystand Hyva] Payment error:', errorData);
                alert('Payment failed. Please try again or use a different payment method.');
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
                alert('Error opening Paystand checkout. Please try again.');
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
            alert('Error opening Paystand checkout. Please try again.');
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
        button.className = 'paystand-pay-button text-white text-sm py-2 px-4 rounded-sm font-medium transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed hover:opacity-90';
        button.style.backgroundColor = '#00ACEE';
        
        const progressBar = document.createElement('p');
        progressBar.id = 'paystand-progressBar';
        progressBar.className = 'text-sm text-gray-600 mt-2';
        
        let timeLeft = 5;
        button.textContent = `Pay with Paystand`;
        button.disabled = true;
        progressBar.textContent = `Loading, please wait... ${timeLeft} seconds remaining`;
        
        const countdownInterval = setInterval(() => {
            timeLeft--;
            if (timeLeft > 0) {
                progressBar.textContent = `Loading, please wait... ${timeLeft} seconds remaining`;
            } else {
                clearInterval(countdownInterval);
                progressBar.style.display = 'none';
                button.disabled = false;
            }
        }, 1000);
        
        button.addEventListener('click', function() {
            if (!this.disabled) {
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
                        button.textContent = 'Pay with Paystand';
                    }, 1000);
                });
            }
        });
        
        buttonContainer.appendChild(button);
        buttonContainer.appendChild(progressBar);
        paystandButton = buttonContainer;
        
        return paystandButton;
    }
    
    /** Disable Place Order button */
    function disablePlaceOrderButton() {
        const placeOrderBtn = document.querySelector('button.btn-place-order');
        if (placeOrderBtn) {
            placeOrderBtn.disabled = true;
            placeOrderBtn.style.opacity = '0.5';
            placeOrderBtn.style.cursor = 'not-allowed';
        }
    }
    
    /** Enable Place Order button */
    function enablePlaceOrderButton() {
        const placeOrderBtn = document.querySelector('button.btn-place-order');
        if (placeOrderBtn) {
            placeOrderBtn.disabled = false;
            placeOrderBtn.style.opacity = '';
            placeOrderBtn.style.cursor = '';
        }
    }
    
    /** Initialize payment method */
    function initialize() {
        setTimeout(() => {
            const radioInput = document.querySelector('input[value="paystandmagento"]');
            if (!radioInput) return;
            
            disablePlaceOrderButton();
            
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
            
            const container = radioInput.closest('div[class*="border"]') || radioInput.parentElement;
            if (container && !container.querySelector('.paystand-button-container')) {
                container.appendChild(createPaystandButton());
            }
        }, 500);
    }
    
    /** Cleanup when method is deselected */
    function onMethodDeselect() {
        enablePlaceOrderButton();
        
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
    
    /** Watch for payment method changes */
    function watchPaymentMethodChanges() {
        const paymentMethodsContainer = document.querySelector('[wire\\:id="checkout.payment.methods"]') 
            || document.querySelector('.payment-methods');
        
        if (paymentMethodsContainer) {
            paymentMethodsContainer.addEventListener('click', (e) => {
                const radio = e.target.closest('input[type="radio"][name="payment_method"]') 
                    || e.target.closest('input[type="radio"]');
                
                if (radio) {
                    setTimeout(() => {
                        const isPaystandSelected = radio.value === 'paystandmagento' 
                            || document.querySelector('input[value="paystandmagento"]:checked');
                        
                        if (isPaystandSelected) {
                            disablePlaceOrderButton();
                        } else {
                            enablePlaceOrderButton();
                            onMethodDeselect();
                        }
                    }, 100);
                }
            });
        }
        
        if (typeof Livewire !== 'undefined') {
            Livewire.hook('message.processed', () => {
                const isPaystandSelected = document.querySelector('input[value="paystandmagento"]:checked');
                if (isPaystandSelected) {
                    disablePlaceOrderButton();
                } else {
                    enablePlaceOrderButton();
                }
            });
        }
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
            
            setTimeout(watchPaymentMethodChanges, 1000);
        });
    }
})();
