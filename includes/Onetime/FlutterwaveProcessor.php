<?php

namespace FlutterwaveFluentCart\Onetime;

use FluentCart\App\Services\Payments\PaymentInstance;
use FlutterwaveFluentCart\API\FlutterwaveAPI;
use FlutterwaveFluentCart\FlutterwaveHelper;
use FlutterwaveFluentCart\Settings\FlutterwaveSettingsBase;
use FluentCart\Framework\Support\Arr;

class FlutterwaveProcessor
{
    /**
     * Handle single payment using Flutterwave Inline (popup modal)
     * This provides better conversion than redirect flow
     */
    public function handleSinglePayment(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $paymentInstance->order->customer;

        $txRef = $transaction->uuid . '_' . time();

        // Get public key for inline checkout
        $settings = new FlutterwaveSettingsBase();
        $publicKey = $settings->getPublicKey();

        if (!$publicKey) {
            return new \WP_Error(
                'flutterwave_no_public_key',
                __('Flutterwave public key is not configured.', 'flutterwave-for-fluent-cart')
            );
        }

        // Prepare inline payment data for Flutterwave popup
        $inlineData = [
            'public_key'  => $publicKey,
            'tx_ref'      => $txRef,
            'amount'      => FlutterwaveHelper::formatAmountForFlutterwave($transaction->total, $transaction->currency),
            'currency'    => strtoupper($transaction->currency),
            'customer'    => [
                'email' => $fcCustomer->email,
                'name'  => trim($fcCustomer->first_name . ' ' . $fcCustomer->last_name),
            ],
            'meta'        => [
                'order_id'         => $order->id,
                'order_hash'       => $order->uuid,
                'transaction_hash' => $transaction->uuid,
            ],
            'customizations' => [
                'title'       => get_bloginfo('name'),
                'description' => sprintf(__('Payment for Order #%s', 'flutterwave-for-fluent-cart'), $order->id),
                'logo'        => get_site_icon_url(),
            ]
        ];

        // Add phone if available
        if (!empty($fcCustomer->phone)) {
            $inlineData['customer']['phone_number'] = $fcCustomer->phone;
        }

        // Apply filters for customization
        $inlineData = apply_filters('fluent_cart/flutterwave/onetime_payment_args', $inlineData, [
            'order'       => $order,
            'transaction' => $transaction
        ]);

        // Store tx_ref in transaction meta for later verification
        $transaction->updateMeta('flutterwave_tx_ref', $txRef);

        return [
            'status'       => 'success',
            'nextAction'   => 'flutterwave',
            'actionName'   => 'custom',
            'message'      => __('Opening Flutterwave payment popup...', 'flutterwave-for-fluent-cart'),
            'data'         => [
                'flutterwave_data' => $inlineData,
                'tx_ref'           => $txRef,
                'intent'           => 'onetime',
                'transaction_hash' => $transaction->uuid,
            ]
        ];
    }

    public function getWebhookUrl()
    {
        return site_url('?fluent-cart=fct_payment_listener_ipn&method=flutterwave');
    }
}
