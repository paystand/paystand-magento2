/**
 * Hyvä Checkout - Paystand Payment Method Integration
 * 
 * Registers Paystand as a payment method in Hyvä Checkout and handles:
 * - Payment method selection/deselection
 * - Dynamic "Pay with Paystand" button injection
 * - Paystand modal initialization with quote and billing data
 * - Payment completion and order placement
 * 
 * @see view/frontend/templates/hyva-checkout/paystand-init.phtml - Config injection
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
    
    // Determine Paystand environment
    const useSandbox = window.paystandConfig.useSandbox;
    const env = window.paystandConfig.environment || (useSandbox ? 'sandbox' : 'live');
    
    /**
     * Get quote data from Hyvä Checkout
     * @returns {Object} Quote data with totals, ID, grand total and currency
     */
    function getQuoteData() {
        // Access quote from window.checkoutConfig (standard Magento object)
        if (window.checkoutConfig && window.checkoutConfig.totalsData) {
            const totalsData = window.checkoutConfig.totalsData;
            
            return {
                totals: totalsData,
                quoteId: window.checkoutConfig.quoteData?.entity_id || null,
                grandTotal: totalsData.grand_total || 0,
                currency: totalsData.quote_currency_code || 'USD'
            };
        }
        
        // Fallback to empty data
        return { totals: {}, quoteId: null, grandTotal: 0, currency: 'USD' };
    }
    
    /**
     * Get billing address from checkout
     * @returns {Object} Billing address with firstname, lastname, email, street, city, etc.
     */
    function getBillingAddress() {
        // Try to get billing address from checkoutConfig
        if (window.checkoutConfig && window.checkoutConfig.shippingAddressFromData) {
            return window.checkoutConfig.shippingAddressFromData;
        }
        
        return {};
    }
    
    /**
     * Get customer data
     * @returns {Object} Customer data with isLoggedIn, email, id, and payerId
     */
    function getCustomerData() {
        const isLoggedIn = window.checkoutConfig?.isCustomerLoggedIn || false;
        const customerData = window.checkoutConfig?.customerData || {};
        
        // Get payer ID from custom attributes if available
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
     * Build Paystand modal configuration object
     * @returns {Object} Paystand configuration for modal
     */
    function buildPaystandConfig() {
        const quote = getQuoteData();
        const billing = getBillingAddress();
        const customer = getCustomerData();
        
        // Determine payer email and name
        const payerEmail = customer.isLoggedIn ? customer.email : (billing.email || '');
        const payerName = (billing.firstname || '') + ' ' + (billing.lastname || '');
        
        // Base configuration
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
        
        // Add billing address details if available
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
        
        // For logged-in users, use access token flow if available
        if (customer.isLoggedIn && window.paystandConfig.accessToken) {
            config.accessToken = window.paystandConfig.accessToken;
            config.checkoutType = "checkout_magento2";
            config.customerId = window.paystandConfig.customerId;
            config.paymentMeta.extCustomerId = customer.id;
            
            // Remove publishableKey when using access token
            delete config.publishableKey;
            delete config.presetCustom;
        }
        
        return config;
    }
    
    /**
     * Wait for Paystand SDK to be available
     * @returns {Promise} Resolves when psCheckout is available
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
     * Open Paystand modal with configuration
     */
    async function openPaystandModal() {
        try {
            // Wait for Paystand SDK to be available
            await waitForPaystandSDK();
            
            // Build configuration for modal
            const config = buildPaystandConfig();
            
            // Log configuration for debugging
            console.log('========================================');
            console.log('PAYSTAND MODAL CONFIGURATION (HYVÄ)');
            console.log('========================================');
            console.log(JSON.stringify(config, null, 2));
            console.log('========================================');
            
            // Create or get container for Paystand modal
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
                console.log('[Paystand] Payment completed:', paymentData);
                
                // Prepare response data
                const response = {
                    payerId: paymentData.response.data.payerId,
                    quote: paymentData.response.data.meta.quote,
                    payerDiscount: paymentData.response.data.feeSplit.payerDiscount,
                    payerTotalFees: paymentData.response.data.feeSplit.payerTotalFees,
                    initPayer: paymentData.response.data.meta.initPayer
                };
                
                try {
                    // Save payment data to Magento
                    const fetchResponse = await fetch('/paystandmagento/checkout/savepaymentdata', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(response)
                    });
                    
                    if (!fetchResponse.ok) {
                        throw new Error(`HTTP error! status: ${fetchResponse.status}`);
                    }
                    
                    await fetchResponse.json();
                    
                    // Trigger order placement in Hyvä Checkout
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
     * @returns {HTMLElement} Button container element
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
        
        // Hover effect
        button.addEventListener('mouseenter', function() {
            this.style.backgroundColor = 'rgb(0, 150, 210)';
        });
        button.addEventListener('mouseleave', function() {
            this.style.backgroundColor = 'rgb(0, 172, 238)';
        });
        
        // Open modal on click
        button.addEventListener('click', openPaystandModal);
        
        buttonContainer.appendChild(button);
        paystandButton = buttonContainer;
        
        return paystandButton;
    }
    
    /**
     * Initialize payment method (called when method is selected)
     */
    function initialize() {
        // Find payment method container
        const paymentMethodContainer = document.querySelector('[data-payment-method="paystandmagento"]');
        
        if (paymentMethodContainer) {
            // Check if button already exists
            if (!paymentMethodContainer.querySelector('.paystand-button-container')) {
                const button = createPaystandButton();
                paymentMethodContainer.appendChild(button);
            }
        }
    }
    
    /**
     * Cleanup when payment method is deselected
     */
    function onMethodDeselect() {
        const paymentMethodContainer = document.querySelector('[data-payment-method="paystandmagento"]');
        
        if (paymentMethodContainer) {
            const existingButton = paymentMethodContainer.querySelector('.paystand-button-container');
            if (existingButton) {
                existingButton.remove();
            }
        }
        
        paystandButton = null;
    }
    
    /**
     * Validate payment method before placing order
     * @returns {boolean} Always returns true (validation happens in Paystand modal)
     */
    function validate() {
        return true;
    }
    
    /**
     * Place order (triggered after Paystand payment completion)
     * @returns {boolean} Always returns true
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
            
            // Listen for method selection/deselection
            hyvaCheckout.payment.onMethodSelect('paystandmagento', initialize);
            hyvaCheckout.payment.onMethodDeselect('paystandmagento', onMethodDeselect);
        });
    } else {
        console.error('[Paystand] Hyvä Checkout API not available');
    }
})();
