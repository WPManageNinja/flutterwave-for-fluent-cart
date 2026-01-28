<?php

namespace FlutterwaveFluentCart\Confirmations;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\Framework\Support\Arr;
use FlutterwaveFluentCart\API\FlutterwaveAPI;
use FlutterwaveFluentCart\FlutterwaveHelper;
use FlutterwaveFluentCart\Subscriptions\FlutterwaveSubscriptions;

class FlutterwaveConfirmations
{
    public function init()
    {
        add_action('wp_ajax_nopriv_fluent_cart_confirm_flutterwave_payment', [$this, 'confirmFlutterwavePayment']);
        add_action('wp_ajax_fluent_cart_confirm_flutterwave_payment', [$this, 'confirmFlutterwavePayment']);
    }

    public function confirmFlutterwavePayment()
    {
        if (isset($_REQUEST['flutterwave_fct_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_REQUEST['flutterwave_fct_nonce']));
            if (!wp_verify_nonce($nonce, 'flutterwave_fct_nonce')) {
                wp_send_json([
                    'message' => 'Invalid nonce. Please refresh the page and try again.',
                    'status' => 'failed'
                ], 400);
            }
        } else {
            wp_send_json([
                'message' => 'Nonce is required for security verification.',
                'status' => 'failed'
            ], 400);
        }

        $transactionId = sanitize_text_field(wp_unslash($_REQUEST['transaction_id'] ?? ''));
        $txRef = sanitize_text_field(wp_unslash($_REQUEST['tx_ref'] ?? ''));
        
        if (!$transactionId && !$txRef) {
            wp_send_json([
                'message' => 'Transaction ID or reference is required to confirm the payment.',
                'status' => 'failed'
            ], 400);
        }

        // Verify the transaction with Flutterwave
        if ($transactionId) {
            $flutterwaveTransaction = FlutterwaveAPI::getFlutterwaveObject('transactions/' . $transactionId . '/verify');
        } else {
            $flutterwaveTransaction = FlutterwaveAPI::getFlutterwaveObject('transactions/verify_by_reference', ['tx_ref' => $txRef]);
        }

        if (is_wp_error($flutterwaveTransaction)) {
            wp_send_json([
                'message' => $flutterwaveTransaction->get_error_message(),
                'status' => 'failed'
            ], 500);
        }

        if (Arr::get($flutterwaveTransaction, 'status') !== 'success') {
            wp_send_json([
                'message' => Arr::get($flutterwaveTransaction, 'message', 'Transaction verification failed'),
                'status' => 'failed'
            ], 400);
        }

        $data = Arr::get($flutterwaveTransaction, 'data', []);
        $paymentStatus = Arr::get($data, 'status');

        if ($paymentStatus !== 'successful') {
            wp_send_json([
                'message' => sprintf(__('Payment status: %s', 'flutterwave-for-fluent-cart'), $paymentStatus),
                'status' => 'failed'
            ], 400);
        }

        $transactionMeta = Arr::get($data, 'meta', []);
        $transactionHash = Arr::get($transactionMeta, 'transaction_hash', '');

        // Try to get from tx_ref if not in meta
        if (!$transactionHash) {
            $txRefFromResponse = Arr::get($data, 'tx_ref', '');
            $parts = explode('_', $txRefFromResponse);
            $transactionHash = $parts[0] ?? '';
        }

        $transactionModel = null;

        if ($transactionHash) {
            $transactionModel = OrderTransaction::query()
                ->where('uuid', $transactionHash)
                ->where('payment_method', 'flutterwave')
                ->first();
        }

        if (!$transactionModel) {
            wp_send_json([
                'message' => 'Transaction not found for the provided reference.',
                'status' => 'failed'
            ], 404);
        }

        // Check if already processed
        if ($transactionModel->status === Status::TRANSACTION_SUCCEEDED) {
            wp_send_json([
                'redirect_url' => $transactionModel->getReceiptPageUrl(),
                'order' => [
                    'uuid' => $transactionModel->order->uuid,
                ],
                'message' => __('Payment already confirmed. Redirecting...!', 'flutterwave-for-fluent-cart'),
                'status' => 'success'
            ], 200);
        }

        $flutterwaveTransactionId = Arr::get($data, 'id');

        $billingInfo = [
            'type' => Arr::get($data, 'payment_type', 'card'),
            'last4' => Arr::get($data, 'card.last_4digits'),
            'brand' => Arr::get($data, 'card.type'),
            'payment_method_id' => $flutterwaveTransactionId,
            'payment_method_type' => Arr::get($data, 'payment_type'),
        ];

        $subscriptionHash = Arr::get($transactionMeta, 'subscription_hash', '');
        $updatedSubData = [];

        if ($subscriptionHash) {
            $subscriptionModel = Subscription::query()
                ->where('uuid', $subscriptionHash)
                ->first();

            if ($subscriptionModel && !in_array($subscriptionModel->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])) {
                $updatedSubData = (new FlutterwaveSubscriptions())->activateSubscription($subscriptionModel, [
                    'flutterwave_transaction' => $data,
                    'billing_info' => $billingInfo,
                ]);
            }
        }

        $this->confirmPaymentSuccessByCharge($transactionModel, [
            'vendor_charge_id' => $flutterwaveTransactionId,
            'charge' => $data,
            'subscription_data' => $updatedSubData,
            'billing_info' => $billingInfo
        ]);

        wp_send_json([
            'redirect_url' => $transactionModel->getReceiptPageUrl(),
            'order' => [
                'uuid' => $transactionModel->order->uuid,
            ],
            'message' => __('Payment confirmed successfully. Redirecting...!', 'flutterwave-for-fluent-cart'),
            'status' => 'success'
        ], 200);
    }

    /**
     * Confirm payment success and update transaction
     */
    public function confirmPaymentSuccessByCharge(OrderTransaction $transactionModel, $args = [])
    {
        $vendorChargeId = Arr::get($args, 'vendor_charge_id');
        $transactionData = Arr::get($args, 'charge');
        $subscriptionData = Arr::get($args, 'subscription_data', []);
        $billingInfo = Arr::get($args, 'billing_info', []);

        if ($transactionModel->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        $order = Order::query()->where('id', $transactionModel->order_id)->first();

        if (!$order) {
            return;
        }

        // Flutterwave returns amount in main currency unit, convert to lowest unit
        $amount = FlutterwaveHelper::convertToLowestUnit(
            Arr::get($transactionData, 'amount', 0),
            Arr::get($transactionData, 'currency')
        );
        $currency = Arr::get($transactionData, 'currency');

        // Store flw_ref for future refund matching via webhook
        $flwRef = Arr::get($transactionData, 'flw_ref', '');
        $txRef = Arr::get($transactionData, 'tx_ref', '');

        // Merge billing info with flw_ref and tx_ref for refund matching
        $metaData = array_merge($transactionModel->meta ?? [], $billingInfo, [
            'flw_ref' => $flwRef,
            'tx_ref'  => $txRef,
        ]);

        // Update transaction
        $transactionUpdateData = array_filter([
            'order_id' => $order->id,
            'total' => $amount,
            'currency' => $currency,
            'status' => Status::TRANSACTION_SUCCEEDED,
            'payment_method' => 'flutterwave',
            'card_last_4' => Arr::get($billingInfo, 'last4', ''),
            'card_brand' => Arr::get($billingInfo, 'brand', ''),
            'payment_method_type' => Arr::get($billingInfo, 'payment_method_type', ''),
            'vendor_charge_id' => $vendorChargeId,
            'meta' => $metaData
        ]);

        $transactionModel->fill($transactionUpdateData);
        $transactionModel->save();

        fluent_cart_add_log(
            __('Flutterwave Payment Confirmation', 'flutterwave-for-fluent-cart'),
            __('Payment confirmation received from Flutterwave. Transaction ID:', 'flutterwave-for-fluent-cart') . ' ' . $vendorChargeId,
            'info',
            [
                'module_name' => 'order',
                'module_id' => $order->id,
            ]
        );

        if ($order->type == Status::ORDER_TYPE_RENEWAL) {
            $subscriptionModel = Subscription::query()->where('id', $transactionModel->subscription_id)->first();

            if (!$subscriptionModel || !$subscriptionData) {
                return $order;
            }
            return SubscriptionService::recordManualRenewal($subscriptionModel, $transactionModel, [
                'billing_info'      => $billingInfo,
                'subscription_args' => $subscriptionData
            ]);
        }

        return (new StatusHelper($order))->syncOrderStatuses($transactionModel);
    }
}
