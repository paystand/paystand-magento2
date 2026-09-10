# PayStand Payment Gateway Magento 2.x Extension

Accept Credit Card, eCheck, and ACH payments using PayStand Payment Gateway's robust and modern checkout.

Use of the extension requires a PayStand account offering fully-featured plans.  To learn more and create your own account, visit us at [PayStand.com](http://www.paystand.com), or contact us at support@paystand.com.

##  Create a PayStand Account

1.  Contact PayStand at support@paystand.com to set up a Merchant account and be issued a publishable_key.
2.  If you have a test server and would like to enable Sandbox Mode, request to also be issued a Sandbox publishable_key.
3.  Provide PayStand with your magento website address so you can be registered to receive webhooks, providing you with timely order status updates when payments clear.

## Module installation

1.  Go to your Magento 2 root folder
2.  `composer config repositories.paystand-magento2 git https://github.com/paystand/paystand-magento2.git`
3.  `composer require paystand/paystandmagento:3.7.3`
4.  `composer update`
5.  `php bin/magento setup:upgrade`  
**Note**: The above command updates database schema, so in order to preserve previously generated static files run the above command with the flag `--keep-generated`

For Magento Installation best practices please refer to the [Magento Installation Guide](https://devdocs.magento.com/guides/v2.4/install-gde/install-flow-diagram.html)

### Upgrading from 3.7.2 or earlier

Run `php bin/magento setup:upgrade` before reopening the storefront. Version 3.7.3 encrypts every
stored Paystand client-secret scope and removes OAuth client credentials and bearer tokens from
checkout responses. After the upgrade, clear Magento configuration and full-page caches and rotate
the Paystand client secret because earlier versions could expose it in Hyva checkout HTML.

The extension no longer changes the merchant's global CSP mode. Test the checkout with the merchant's
normal CSP restrict-mode policy before enabling it in production.

### Checkout safety in 3.7.3

Luma and the bundled Hyva integration now use the same Magento-owned payment-start contract:

1. Magento reloads the active cart, selects the Paystand method, collects totals, applies Magento's
   `validateBeforeSubmit` rules, reserves the order number, and stores a short-lived snapshot.
2. A separate request atomically consumes that snapshot immediately before the Paystand checkout is
   opened. A stale, changed, expired, reused, foreign-session, already-paid, or already-ordered cart is
   refused.
3. The browser-reported Paystand reference is written to durable attempt memory before the quote is
   changed. Magento validates the cart again; if it changed or became unorderable, the attempt is held
   and the shopper is told not to pay again.
4. A Paystand order records `magento.order_placed` in a local transactional outbox. Magento cron repairs
   a rare order-commit/outbox gap every five minutes. Version 3.7.3 does not dispatch that outbox to a
   remote service and does not automatically issue refunds.

Attempt states are `prepared`, `provider_started`, `browser_reported`, `order_placed`, and `held`.
`held` and an old `provider_started` row require reconciliation before the cart can initiate another
payment. Keep Magento cron enabled and monitor `PAYSTAND_CHECKOUT_LIFECYCLE_*` log events. Do not delete
`paystand_checkout_attempt` or `paystand_checkout_outbox` rows as part of routine quote cleanup.

The checkout endpoints that prepare, start, report, and read an attempt are same-origin POST requests
protected by Magento's form key. Only the exact Paystand webhook route is exempt. The browser receives
the publishable key but never the OAuth client id, client secret, or webhook bearer token.

##  Configuring the PayStand Payment Gateway
1.  Go to Stores/Configuration/Sales/Payment Methods/PayStand in your Magento admin interface.
2.  Enter your publishable_key, or Sandbox publishable_key that you were issued when creating your PayStand account.

If you have any further questions, please email [support@paystand.com](support@paystand.com) or contact us at (800) 708-6413.

### About PayStand

PayStand is a next-generation payment & eCommerce checkout system that enables any organization to receive money in their Website, Social Network, or Web Application in a flat-rate SaaS model with no transaction markups. We are the first multi-payment gateway to accept credit cards (Visa/MasterCard/Amex/Discover), eCheck, and ACH in a single interface. Thousands of merchants are using PayStand for their online payments, shopping cart, donation management, subscriptions, eCommerce integrations, recurring payments, checkout experience and more.

You can choose which payment rails to activate in your PayStand account dashboard, or let your customers decide which method to use when checking out. Additionally, we pass our wholesale rates on credit cards direct to you, and automatically lower them as we're able to negotiate lower rates on your behalf. 
