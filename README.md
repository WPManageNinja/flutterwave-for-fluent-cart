# Flutterwave for FluentCart

Accept payments via Flutterwave in FluentCart - supports one-time payments, subscriptions, and automatic refunds via webhooks.

## Description

Flutterwave for FluentCart is a payment gateway addon that integrates Flutterwave payment processing with FluentCart. It allows your customers to pay using various payment methods supported by Flutterwave including:

- Credit/Debit Cards
- Bank Transfer
- Mobile Money
- USSD
- M-Pesa
- And more...

## Features

- **One-time Payments**: Accept single payments for products
- **Subscriptions**: Create and manage recurring payments with payment plans
- **Refunds**: Process refunds directly from your dashboard
- **Webhooks**: Automatic payment status updates via webhooks
- **Multi-currency**: Support for multiple African and international currencies

## Requirements

- WordPress 5.6 or higher
- PHP 7.4 or higher
- FluentCart 1.2.5 or higher
- Flutterwave merchant account

## Installation

1. Upload the `flutterwave-for-fluent-cart` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to FluentCart > Settings > Payment Methods > Flutterwave
4. Enter your Flutterwave API keys (Test and/or Live)
5. Configure your webhook URL in your Flutterwave dashboard

## Configuration

### API Keys

1. Log in to your [Flutterwave Dashboard](https://dashboard.flutterwave.com)
2. Navigate to Settings > API Keys
3. Copy your Public Key and Secret Key
4. Paste them in the FluentCart Flutterwave settings

### Webhook Setup

1. In your Flutterwave Dashboard, go to Settings > Webhooks
2. Add a new webhook with the URL provided in the FluentCart settings
3. Set a secret hash and enter the same hash in FluentCart settings
4. Enable the webhook

## Supported Currencies

NGN, USD, EUR, GBP, GHS, KES, ZAR, TZS, UGX, RWF, XAF, XOF, ZMW, MWK, SLL, MZN, AED, EGP, MAD, INR, ETB

## Changelog

### 1.0.0
- Initial release
- One-time payments support
- Subscription payments support
- Refund processing
- Webhook handling

## Support

For support, please visit [FluentCart Support](https://fluentcart.com/support) or open an issue on GitHub.

## License

GPLv2 or later
