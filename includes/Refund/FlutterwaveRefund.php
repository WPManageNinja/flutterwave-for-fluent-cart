<?php

namespace FlutterwaveFluentCart\Refund;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FluentCart\Framework\Support\Arr;
use FlutterwaveFluentCart\API\FlutterwaveAPI;
use FlutterwaveFluentCart\FlutterwaveHelper;

class FlutterwaveRefund
{
    public static function processRemoteRefund($transaction, $amount, $args)
    {
        $flutterwaveTransactionId = $transaction->vendor_charge_id;

        if (!$flutterwaveTransactionId) {
            return new \WP_Error(
                'flutterwave_refund_error',
                __('Payment ID not found for refund', 'flutterwave-for-fluent-cart')
            );
        }

        // Flutterwave expects amount in main currency unit
        $refundAmount = FlutterwaveHelper::formatAmountForFlutterwave($amount, $transaction->currency);

        $refund = FlutterwaveAPI::createFlutterwaveObject('transactions/' . $flutterwaveTransactionId . '/refund', ['amount' => $refundAmount]);

        if (is_wp_error($refund)) {
            return $refund;
        }

        if (Arr::get($refund, 'status') !== 'success') {
            return new \WP_Error(
                'refund_failed',
                Arr::get($refund, 'message', __('Refund could not be processed in Flutterwave. Please check your Flutterwave account', 'flutterwave-for-fluent-cart'))
            );
        }

        $refundData = Arr::get($refund, 'data', []);
        $refundId = Arr::get($refundData, 'id');
        $refundStatus = Arr::get($refundData, 'status');

        $acceptedStatuses = ['pending', 'pending-void', 'completed'];

        if (!in_array($refundStatus, $acceptedStatuses)) {
            return new \WP_Error(
                'refund_failed',
                /* translators: %s: Refund status from Flutterwave */
                sprintf(__('Refund status: %s. Please check your Flutterwave account', 'flutterwave-for-fluent-cart'), $refundStatus)
            );
        }

        return $refundId;
    }
}
