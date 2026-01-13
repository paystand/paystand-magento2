/**
 * Hyvä Checkout - Paystand Payment Method Integration
 * 
 * Registers Paystand as a payment method in Hyvä Checkout and handles:
 * - Payment method selection/deselection
 * - Dynamic "Pay with Paystand" button injection
 * - Paystand modal initialization with quote and billing data
 * - Payment completion and order placement
 */
(function() {
    'use strict';
    
    // Load Paystand configuration from data attribute (injected by PHP template)
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
    
    // Determine Paystand environment
    const useSandbox = window.paystandConfig.useSandbox;
    const env = window.paystandConfig.environment || (useSandbox ? 'sandbox' : 'live');
    const apiDomain = useSandbox ? 'api.paystand.biz' : 'api.paystand.com';
    
    /**
     * Get quote data from server endpoint (like KnockoutJS observables in Luma)
     */
    async function getQuoteData() {
        try {
            // Fetch quote data from server endpoint
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
    
    /**
     * Get billing address from Hyvä reactive stores
     */
    function getBillingAddress() {
        // Try to get from Alpine store first (reactive data)
        if (typeof Alpine !== 'undefined' && Alpine.store('checkout')) {
            const checkoutStore = Alpine.store('checkout');
            const billing = checkoutStore.billingAddress || {};
            
            if (billing && Object.keys(billing).length > 0) {
                return billing;
            }
        }
        
        // Fallback to window.checkoutConfig
        if (window.checkoutConfig && window.checkoutConfig.shippingAddressFromData) {
            return window.checkoutConfig.shippingAddressFromData;
        }
        
        return {};
    }
    
    /**
     * Get customer data from Hyvä reactive stores
     */
    function getCustomerData() {
        // Try to get from Alpine store first (reactive data)
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
        
        // Fallback to window.checkoutConfig
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
    
    /**
     * Build Paystand modal configuration
     */
    async function buildPaystandConfig() {
        // Fetch all data from server
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
        
        // Extract data from server response
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
        
        // Add billing address details
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
        
        // For logged-in users with access token
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
    
    /**
     * Wait for Paystand SDK to be available
     */
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
    
    /**
     * Initialize Paystand checkout (following Luma's exact pattern)
     */
    function initCheckout(config) {
        // If checkout is ready but container doesn't exist, create it
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
    
    /**
     * Register PayStand checkout event handlers
     */
    function registerPaystadCallbacks() {
        // Handle successful payment
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
                
                // Trigger order placement via Livewire component
                const mainComponent = Livewire.components.componentsById['hyva-checkout-main'];
                if (mainComponent) {
                    try {
                        await mainComponent.call('placeOrder');
                        // Livewire handles the redirect automatically after successful order
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
        
        // Handle payment errors
        if (typeof window.psCheckout.onError === 'function') {
            window.psCheckout.onError(function(errorData) {
                console.error('[Paystand Hyva] Payment error:', errorData);
                alert('Payment failed. Please try again or use a different payment method.');
            });
        }
        
        // Handle user cancellation
        if (typeof window.psCheckout.onCancel === 'function') {
            window.psCheckout.onCancel(function() {
                console.log('[Paystand Hyva] Payment cancelled by user');
                // No action needed - user can retry or choose different payment method
            });
        }
    }
    
    /**
     * Open Paystand modal
     */
    async function openPaystandModal() {
        try {
            await waitForPaystandSDK();
            
            const config = await buildPaystandConfig();
            
            if (!config) {
                console.error('[Paystand Hyva] Failed to build checkout configuration');
                alert('Error opening Paystand checkout. Please try again.');
                return;
            }
            
            // Use existing container (already created in button)
            let container = document.getElementById('ps_checkout');
            if (!container) {
                container = document.createElement('div');
                container.id = 'ps_checkout';
                document.body.appendChild(container);
            }
            
            // Initialize checkout first
            initCheckout(config);
            
            // Register PayStand callbacks AFTER checkout is initialized
            // Use a delay to ensure psCheckout is fully ready
            setTimeout(() => {
                registerPaystadCallbacks();
            }, 1000);
            
        } catch (error) {
            console.error('[Paystand Hyva] Error opening checkout modal:', error);
            alert('Error opening Paystand checkout. Please try again.');
            throw error; // Re-throw to trigger finally block in button click handler
        }
    }
    
    /**
     * Create "Pay with Paystand" button with Tailwind classes - compact and elegant with loading state
     */
    function createPaystandButton() {
        if (paystandButton) return paystandButton;
        
        const buttonContainer = document.createElement('div');
        // Tailwind classes for centering - compact spacing
        buttonContainer.className = 'paystand-button-container flex flex-col items-center w-full mt-3 mb-2';
        
        // Create ps_checkout container (like Luma)
        const psCheckoutDiv = document.createElement('div');
        psCheckoutDiv.id = 'ps_checkout';
        buttonContainer.appendChild(psCheckoutDiv);
        
        const button = document.createElement('button');
        button.type = 'button';
        // Compact button styling with PayStand brand blue (#00ACEE)
        button.className = 'paystand-pay-button text-white text-sm py-2 px-4 rounded-sm font-medium transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed hover:opacity-90';
        button.style.backgroundColor = '#00ACEE';
        
        // Progress bar (like Luma) - with Tailwind
        const progressBar = document.createElement('p');
        progressBar.id = 'paystand-progressBar';
        progressBar.className = 'text-sm text-gray-600 mt-2';
        
        let timeLeft = 5;
        button.textContent = `Pay with Paystand`;
        button.disabled = true;
        progressBar.textContent = `Loading, please wait... ${timeLeft} seconds remaining`;
        
        // Countdown interval (like Luma's buttonDisabler)
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
                // Show loading state
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
                
                // Add CSS animation for spinner
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
                
                // Open modal
                openPaystandModal().finally(() => {
                    // Reset button state after modal opens or fails
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
    
    /**
     * Initialize payment method (called when method is selected)
     */
    function initialize() {
        setTimeout(() => {
            const radioInput = document.querySelector('input[value="paystandmagento"]');
            if (!radioInput) return;
            
            // Add logo to label and remove text
            const label = radioInput.nextElementSibling;
            if (label && !label.querySelector('.paystand-logo') && window.paystandConfig.logoUrl) {
                // Clear existing text content (theme adds "Paystand" text)
                label.textContent = '';
                
                const logo = document.createElement('img');
                logo.src = window.paystandConfig.logoUrl;
                logo.alt = 'PayStand';
                logo.className = 'paystand-logo';
                logo.style.cssText = 'height: 20px;';
                label.appendChild(logo);
            }
            
            // Add button to container
            const container = radioInput.closest('div[class*="border"]') || radioInput.parentElement;
            if (container && !container.querySelector('.paystand-button-container')) {
                container.appendChild(createPaystandButton());
            }
        }, 500);
    }
    
    /**
     * Cleanup when method is deselected
     */
    function onMethodDeselect() {
        // Remove button from anywhere in the page
        const existingButton = document.querySelector('.paystand-button-container');
        if (existingButton) {
            existingButton.remove();
        }
        
        paystandButton = null;
    }
    
    /**
     * Validate payment method
     */
    function validate() {
        return true;
    }
    
    /**
     * Place order
     */
    function placeOrder() {
        return true;
    }
    
    // Register payment method with Hyvä Checkout
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
