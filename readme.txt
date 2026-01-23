=== Flutterwave for FluentCart ===
Contributors: techjewel
Tags: flutterwave, payment gateway, ecommerce, subscriptions, africa payments
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments via Flutterwave in FluentCart - supports one-time payments, subscriptions, and automatic refunds via webhooks.

== Description ==

Flutterwave for FluentCart is a payment gateway addon that integrates Flutterwave payment processing with FluentCart. It allows your customers to pay using various payment methods supported by Flutterwave including Cards, Bank Transfer, Mobile Money, USSD, M-Pesa, and more.

= Features =

* **One-time Payments**: Accept single payments for products
* **Subscriptions**: Create and manage recurring payments with payment plans
* **Refunds**: Process refunds directly from your dashboard
* **Webhooks**: Automatic payment status updates via webhooks
* **Multi-currency**: Support for multiple African and international currencies

= Supported Payment Methods =

* Credit/Debit Cards (Visa, Mastercard, Verve)
* Bank Transfer
* Mobile Money (All major providers)
* USSD
* M-Pesa
* Barter
* PayAttitude

= Supported Currencies =

NGN, USD, EUR, GBP, GHS, KES, ZAR, TZS, UGX, RWF, XAF, XOF, ZMW, MWK, SLL, MZN, AED, EGP, MAD, INR, ETB

= Requirements =

* WordPress 5.6 or higher
* PHP 7.4 or higher
* FluentCart 1.2.5 or higher
* Flutterwave merchant account

== Installation ==

1. Upload the `flutterwave-for-fluent-cart` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to FluentCart > Settings > Payment Methods > Flutterwave
4. Enter your Flutterwave API keys (Test and/or Live)
5. Configure your webhook URL in your Flutterwave dashboard

== Frequently Asked Questions ==

= Do I need a Flutterwave account? =

Yes, you need a Flutterwave merchant account to use this plugin. You can sign up at [flutterwave.com](https://flutterwave.com).

= Which countries are supported? =

Flutterwave supports payments from and to multiple African countries including Nigeria, Ghana, Kenya, South Africa, Tanzania, Uganda, Rwanda, and more. They also support international payments.

= Can I test the integration before going live? =

Yes, Flutterwave provides test API keys that you can use to test the integration without processing real payments.

= Are subscriptions supported? =

Yes, the plugin supports recurring payments through Flutterwave's payment plans feature.

== Changelog ==

= 1.0.0 =
* Initial release
* One-time payments support
* Subscription payments support
* Refund processing
* Webhook handling

== Upgrade Notice ==

= 1.0.0 =
Initial release of Flutterwave for FluentCart.
