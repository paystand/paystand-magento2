#  PayStand Payment Gateway Magento 2.x Extension

Accept Credit Card, eCheck, and ACH payments using PayStand Payment Gateway's robust and modern checkout.

Use of the extension requires a PayStand account offering fully-featured plans.  To learn more and create your own account, visit us at [PayStand.com](http://www.paystand.com), or contact us at support@paystand.com.

##  Create a PayStand Account

1.  Contact PayStand at support@paysand.com to set up a Merchant account and be issued a publishable_key.
2.  If you have a test server and would like to enable Sandbox Mode, request to also be issued a Sandbox publishable_key.
3.  Provide PayStand with your magento website address so you can be registered to receive webhooks, providing you with timely order status updates when payments clear.

##  Module Installation:

1.  Go to your Magento 2 root folder
2.  `composer config repositories.paystand-magento2 git https://github.com/paystand/paystand-magento2.git`
3.  `composer require paystand/paystandmagento:3.3.7`
4.  `composer update`
5.  `php bin/magento setup:upgrade`  
**Note**: The above command updates database schema, so in order to preserve previously generated static files run the above command with the flag `--keep-generated`

For Magento Installation best practices please refer to the [Magento Installation Guide](https://devdocs.magento.com/guides/v2.4/install-gde/install-flow-diagram.html)

##  Configuring the PayStand Payment Gateway
1.  Go to Stores/Configuration/Sales/Payment Methods/PayStand in your Magento admin interface.
2.  Enter your publishable_key, or Sandbox publishable_key that you were issued when creating your PayStand account.

If you have any further questions, please email [support@paystand.com](support@paystand.com) or contact us at (800) 708-6413.

### About PayStand

PayStand is a next-generation payment & eCommerce checkout system that enables any organization to receive money in their Website, Social Network, or Web Application in a flat-rate SaaS model with no transaction markups. We are the first multi-payment gateway to accept credit cards (Visa/MasterCard/Amex/Discover), eCheck, and ACH in a single interface. Thousands of merchants are using PayStand for their online payments, shopping cart, donation management, subscriptions, eCommerce integrations, recurring payments, checkout experience and more.

You can choose which payment rails to activate in your PayStand account dashboard, or let your customers decide which method to use when checking out. Additionally, we pass our wholesale rates on credit cards direct to you, and automatically lower them as we're able to negotiate lower rates on your behalf. 
