<?php

namespace FlutterwaveFluentCart;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Api\CurrencySettings;

class FlutterwaveHelper
{
    public static function getFlutterwaveKeys()
    {
        $settings = (new Settings\FlutterwaveSettingsBase());

        $mode = $settings->getMode();

        if ($mode === 'live') {
            $publicKey = $settings->get('live_public_key');
            $secretKey = $settings->get('live_secret_key');
        } else {
            $publicKey = $settings->get('test_public_key');
            $secretKey = $settings->get('test_secret_key');
        }

        return [
            'public_key' => trim($publicKey),
            'secret_key' => trim($secretKey),
        ];
    }

    public static function mapIntervalToFlutterwave($interval)
    {
        $intervalMaps = [
            'daily'       => 'daily',
            'weekly'      => 'weekly',
            'monthly'     => 'monthly',
            'quarterly'   => 'quarterly',
            'half_yearly' => 'bi-annually',
            'yearly'      => 'yearly',
        ];

        return $intervalMaps[$interval] ?? 'monthly';
    }

    public static function getFctSubscriptionStatus($status)
    {
        $statusMap = [
            'active'    => Status::SUBSCRIPTION_ACTIVE,
            'cancelled' => Status::SUBSCRIPTION_CANCELED,
            'expired'   => Status::SUBSCRIPTION_EXPIRED,
        ];

        return $statusMap[$status] ?? Status::SUBSCRIPTION_ACTIVE;
    }

    public static function getOrderFromTransactionHash($transactionHash)
    {
        $orderTransaction = OrderTransaction::query()
            ->where('uuid', $transactionHash)
            ->where('payment_method', 'flutterwave')
            ->first();

        if ($orderTransaction) {
            return Order::query()->where('id', $orderTransaction->order_id)->first();
        }

        return null;
    }

    public static function getOrderFromTxRef($txRef)
    {
        $parts = explode('_', $txRef);
        $transactionHash = $parts[0] ?? '';

        return self::getOrderFromTransactionHash($transactionHash);
    }

    public static function checkCurrencySupport()
    {
        $currency = CurrencySettings::get('currency');

        if (!in_array(strtoupper($currency), self::getFlutterwaveSupportedCurrencies())) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Flutterwave does not support the currency you are using!', 'flutterwave-for-fluent-cart')
            ], 422);
        }
    }

    public static function getFlutterwaveSupportedCurrencies(): array
    {
        return apply_filters('flutterwave-for-fluent-cart/supported_currencies', [
            'NGN', 'USD', 'EUR', 'GBP', 'CAD', 'GHS', 'KES', 'ZAR', 'TZS', 'UGX',
            'RWF', 'XAF', 'XOF', 'ZMW', 'MWK', 'SLL', 'MZN', 'AED', 'EGP',
            'MAD', 'INR', 'ETB', 'ILS', 'JPY', 'KRW', 'MYR', 'PHP', 'SGD', 'THB',
            'TRY', 'VND', 'XCD', 'XPF', 'YER', 'ZMW', 'ZAR', 'INR', 'BRL', 'ARS'
        ]);
    }

    public static function getSubscriptionUpdateData($flutterwaveSubscription, $subscriptionModel)
    {
        $status = self::getFctSubscriptionStatus(Arr::get($flutterwaveSubscription, 'data.status'));

        $subscriptionUpdateData = array_filter([
            'current_payment_method' => 'flutterwave',
            'status'                 => $status
        ]);

        if ($status === Status::SUBSCRIPTION_CANCELED) {
            $subscriptionUpdateData['canceled_at'] = DateTime::gmtNow()->format('Y-m-d H:i:s');
        }

        $nextDue = Arr::get($flutterwaveSubscription, 'data.next_due');
        if ($nextDue) {
            $subscriptionUpdateData['next_billing_date'] = DateTime::anyTimeToGmt($nextDue)->format('Y-m-d H:i:s');
        }

        return $subscriptionUpdateData;
    }

    public static function getFirstTransactionByVendorChargeId($subscriptionId)
    {
        $transaction = OrderTransaction::query()
            ->where('subscription_id', $subscriptionId)
            ->where('payment_method', 'flutterwave')
            ->where('vendor_charge_id', '!=', '')
            ->where('status', Status::TRANSACTION_SUCCEEDED)
            ->orderBy('id', 'asc')
            ->first();

        return $transaction->vendor_charge_id ?? '';
    }

    public static function formatAmountForFlutterwave($amount, $currency)
    {
       
        return round($amount / 100, 2);
    }

    public static function convertToLowestUnit($amount, $currency)
    { 
        return (int) ($amount * 100);
    }
}
