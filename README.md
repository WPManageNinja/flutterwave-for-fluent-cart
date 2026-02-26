# Flutterwave for FluentCart

[![Download Latest](https://img.shields.io/badge/Download-Latest-blue?style=for-the-badge&logo=github)](https://github.com/WPManageNinja/flutterwave-for-fluent-cart/releases/latest/download/flutterwave-for-fluent-cart.zip)

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

### Prerequisites

- WordPress 5.6 or higher
- PHP 7.4 or higher
- [FluentCart](https://wordpress.org/plugins/fluent-cart/) plugin installed and activated
- A [Flutterwave](https://flutterwave.com) merchant account

### Install & Activate

1. **Download the Plugin**
   - Visit the [latest release](../../releases/latest)
   - Download the `Source code (zip)` file

2. **Upload to WordPress**
   - Go to your WordPress admin dashboard
   - Navigate to **Plugins > Add New**
   - Click **Upload Plugin**
   - Select the downloaded zip file and click **Install Now**

3. **Activate the Plugin**
   - After installation, click **Activate Plugin**
   - Alternatively, go to **Plugins** and click "Activate" below the plugin name

4. **Configure Flutterwave**
   - Go to **FluentCart > Settings > Payment Methods**
   - Find and enable **Flutterwave**
   - Enter your Test and Live API keys from the [Flutterwave Dashboard](https://dashboard.flutterwave.com)
   - Configure your webhook URL (see [Configuration](#configuration) below)

## Updates

To update the Flutterwave for FluentCart addon:

1. **Check for Updates**
   - Go to **FluentCart > Settings > Payment Methods**
   - Click on the **Flutterwave** payment method
   - Click the **Check for Updates** button

2. **Download the New Version**
   - If a new version is available, an **Update Now** button will appear
   - Clicking this button will take you to the latest release page
   - Download the `Source code (zip)` file

3. **Install the Update**
   - Go to **Plugins > Add New > Upload Plugin**
   - Upload the new zip file
   - WordPress will automatically replace the old version with the new one
   - Reactivate the plugin if prompted

> **Note:** Since this addon is distributed via GitHub releases (not the WordPress Plugin Directory), updates must be installed manually using the steps above.

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
