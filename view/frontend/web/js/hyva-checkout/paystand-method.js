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
            console.error('[Paystand] Error parsing config:', e);
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
     * Get quote data from checkout
     */
    function getQuoteData() {
        if (window.checkoutConfig && window.checkoutConfig.totalsData) {
            const totalsData = window.checkoutConfig.totalsData;
            
            return {
                totals: totalsData,
                quoteId: window.checkoutConfig.quoteData?.entity_id || null,
                grandTotal: totalsData.grand_total || 0,
                currency: totalsData.quote_currency_code || 'USD'
            };
        }
        
        return { totals: {}, quoteId: null, grandTotal: 0, currency: 'USD' };
    }
    
    /**
     * Get billing address from checkout
     */
    function getBillingAddress() {
        if (window.checkoutConfig && window.checkoutConfig.shippingAddressFromData) {
            return window.checkoutConfig.shippingAddressFromData;
        }
        
        return {};
    }
    
    /**
     * Get customer data
     */
    function getCustomerData() {
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
    function buildPaystandConfig() {
        const quote = getQuoteData();
        const billing = getBillingAddress();
        const customer = getCustomerData();
        
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
                    reject(new Error('Paystand SDK not loaded'));
                }
                attempts++;
            }, 500);
        });
    }
    
    /**
     * Open Paystand modal
     */
    async function openPaystandModal() {
        try {
            await waitForPaystandSDK();
            
            const config = buildPaystandConfig();
            
            // Log configuration for debugging
            console.log('========================================');
            console.log('PAYSTAND MODAL CONFIGURATION (HYVÄ)');
            console.log('========================================');
            console.log(JSON.stringify(config, null, 2));
            console.log('========================================');
            
            // Create container
            let container = document.getElementById('ps_checkout');
            if (!container) {
                container = document.createElement('div');
                container.id = 'ps_checkout';
                document.body.appendChild(container);
            }
            
            // Initialize Paystand checkout
            if (!window.psCheckout.container) {
                window.psCheckout = window.psCheckout.initScript(config);
                window.psCheckout.config = config;
                window.psCheckout.savedConfig = config;
                window.psCheckout.reboot(config);
            }
            
            // Wait for container and run checkout
            const intervalId = setInterval(function() {
                const psReady = (typeof window.psCheckout !== 'undefined' && window.psCheckout.script);
                
                if (window.psCheckout && !window.psCheckout.script && container) {
                    window.psCheckout.script = container;
                    window.psCheckout.config = config;
                }
                
                if (container && psReady) {
                    clearInterval(intervalId);
                    window.psCheckout.savedConfig = Object.assign({}, config, window.psCheckout.savedConfig);
                    window.psCheckout = window.psCheckout.runCheckout(config);
                }
            }, 500);
            
            // Handle payment completion
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
                    
                    // Trigger order placement
                    if (typeof hyvaCheckout !== 'undefined' && hyvaCheckout.checkout) {
                        hyvaCheckout.checkout.placeOrder();
                    }
                    
                } catch (error) {
                    console.error('[Paystand] Error saving payment data:', error);
                }
            });
            
        } catch (error) {
            console.error('[Paystand] Error initializing checkout:', error);
            alert('Error opening Paystand checkout. Please try again.');
        }
    }
    
    /**
     * Create "Pay with Paystand" button
     */
    function createPaystandButton() {
        if (paystandButton) return paystandButton;
        
        const buttonContainer = document.createElement('div');
        buttonContainer.className = 'paystand-button-container';
        buttonContainer.style.cssText = 'margin-top: 1rem; padding: 0 1rem 1rem 1rem;';
        
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = 'Pay with Paystand';
        button.className = 'paystand-pay-button';
        button.style.cssText = `
            background-color: rgb(0, 172, 238);
            color: white;
            padding: 12px 24px;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            width: 100%;
            max-width: 300px;
            transition: background-color 0.2s ease;
        `;
        
        button.addEventListener('mouseenter', function() {
            this.style.backgroundColor = 'rgb(0, 150, 210)';
        });
        button.addEventListener('mouseleave', function() {
            this.style.backgroundColor = 'rgb(0, 172, 238)';
        });
        
        button.addEventListener('click', openPaystandModal);
        
        buttonContainer.appendChild(button);
        paystandButton = buttonContainer;
        
        return paystandButton;
    }
    
    /**
     * Initialize payment method (called when method is selected)
     */
    function initialize() {
        // Wait for Alpine.js to render the DOM
        setTimeout(() => {
            // Try multiple selectors to find the container
            let paymentMethodContainer = document.querySelector('[data-payment-method="paystandmagento"]');
            
            if (!paymentMethodContainer) {
                paymentMethodContainer = document.querySelector('[data-method="paystandmagento"]');
            }
            
            if (!paymentMethodContainer) {
                paymentMethodContainer = document.querySelector('#payment-method-option-paystandmagento');
            }
            
            if (!paymentMethodContainer) {
                // Find by the radio input and get its parent
                const radioInput = document.querySelector('input[value="paystandmagento"]');
                if (radioInput) {
                    paymentMethodContainer = radioInput.closest('div[wire\\:key], div[id*="paystand"], label').parentElement;
                }
            }
            
            if (paymentMethodContainer) {
                if (!paymentMethodContainer.querySelector('.paystand-button-container')) {
                    const button = createPaystandButton();
                    paymentMethodContainer.appendChild(button);
                }
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
    } else {
        console.error('[Paystand] Hyvä Checkout API not available');
    }
})();
