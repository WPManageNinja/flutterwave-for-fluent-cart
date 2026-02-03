<?php

namespace FlutterwaveFluentCart\Webhook;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Events\Order\OrderRefund;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\DateTime\DateTime;
use FlutterwaveFluentCart\Settings\FlutterwaveSettingsBase;
use FlutterwaveFluentCart\Confirmations\FlutterwaveConfirmations;
use FlutterwaveFluentCart\FlutterwaveHelper;
use FlutterwaveFluentCart\API\FlutterwaveAPI;
use FlutterwaveFluentCart\Subscriptions\FlutterwaveSubscriptions;
use FlutterwaveFluentCart\Refund\FlutterwaveRefund;

class FlutterwaveWebhook
{
    public function init()
    {
        // Payment events
        add_action('fluent_cart/payments/flutterwave/webhook_charge_completed', [$this, 'handleChargeCompleted'], 10, 1);
        
        // Refund events - Flutterwave sends these when refund is processed from dashboard
        add_action('fluent_cart/payments/flutterwave/webhook_refund_completed', [$this, 'handleRefundCompleted'], 10, 1);
        // add_action('fluent_cart/payments/flutterwave/webhook_transfer_completed', [$this, 'handleTransferCompleted'], 10, 1);
        
        // Subscription events
        add_action('fluent_cart/payments/flutterwave/webhook_subscription_cancelled', [$this, 'handleSubscriptionCancelled'], 10, 1);
    }

    /**
     * Verify and process Flutterwave webhook
     */
    public function verifyAndProcess()
    {
        $payload = $this->getWebhookPayload();

        if (is_wp_error($payload)) {
            http_response_code(400);
            exit('Not valid payload'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional webhook response
        }

        $data = json_decode($payload, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            exit('Invalid JSON payload'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional webhook response
        }

        $event = Arr::get($data, 'event', '');

        if (!in_array($event, ['subscription.cancelled']) && !$this->verify($payload)) {
            http_response_code(401);
            exit('Invalid signature / Verification failed'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional webhook response
        }

        $order = $this->getFluentCartOrder($data);

        $event = Arr::get($data, 'event', '');
        $eventAction = str_replace('.', '_', $event);

        if (has_action('fluent_cart/payments/flutterwave/webhook_' . $eventAction)) {
            do_action('fluent_cart/payments/flutterwave/webhook_' . $eventAction, [
                'payload' => Arr::get($data, 'data'),
                'order' => $order,
                'event' => $event
            ]);

            $this->sendResponse(200, 'Webhook processed successfully');
        }

        http_response_code(200);
        exit('Webhook received but not handled: ' . esc_html($event)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional webhook response
    }

    private function getWebhookPayload()
    {
        $input = file_get_contents('php://input');
        
        if (strlen($input) > 1048576) {
            return new \WP_Error('payload_too_large', 'Webhook payload too large');
        }
        
        if (empty($input)) {
            return new \WP_Error('empty_payload', 'Empty webhook payload');
        }
        
        return $input;
    }

    /**
     * Verify webhook signature. Uses cached payload so php://input is not read twice.
     *
     * @param string $payload Raw request body (from verifyAndProcess).
     */
    private function verify($payload = null)
    {
        if ($payload === null) {
            if (is_wp_error($payload)) {
                return false;
            }
        }

        $secretHash = '';
        if (isset($_SERVER['HTTP_VERIF_HASH'])) {
            $secretHash = sanitize_text_field(wp_unslash($_SERVER['HTTP_VERIF_HASH']));
        }

        if (!$secretHash) {
            if (!isset($_SERVER['HTTP_FLUTTERWAVE_SIGNATURE'])) {
                return false;
            }
            $signature = sanitize_text_field(wp_unslash($_SERVER['HTTP_FLUTTERWAVE_SIGNATURE']));
            if (!$signature) {
                return false;
            }

            return $this->verifySignature($signature, $payload);
        }

        $storedHash = (new FlutterwaveSettingsBase())->getWebhookSecretHash();
        
        if (!$storedHash) {
            // If no webhook secret is configured, allow the webhook but log a warning
            fluent_cart_add_log(
                __('Flutterwave Webhook Secret Hash Not Configured', 'flutterwave-for-fluent-cart'),
                'No webhook secret is configured for Flutterwave. Allowing the webhook but it is not recommended.',
                'warning',
                [
                    'module_name' => 'payment',
                    'module_id'   => 'flutterwave',
                ]
            );
            return true;
        }

        return hash_equals($storedHash, $secretHash);
    }

    private function verifySignature($signature, $payload)
    {
        $storedHash = (new FlutterwaveSettingsBase())->getWebhookSecretHash();
    
        if (!$storedHash) {
            // If no webhook secret is configured, allow the webhook but log a warning
            fluent_cart_add_log(
                __('Flutterwave Webhook Secret Hash Not Configured', 'flutterwave-for-fluent-cart'),
                'No webhook secret is configured for Flutterwave. Allowing the webhook but it is not recommended.',
                'warning',
                [
                    'module_name' => 'payment',
                    'module_id'   => 'flutterwave',
                ]
            );
            return true;
        }

        $expectedSignature = hash_hmac('sha512', $payload, $storedHash);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Handle charge.completed webhook event.
     * Flutterwave sends this for both one-time payments and subscription recurring payments.
     * See: https://developer.flutterwave.com/docs/webhooks
     */
    public function handleChargeCompleted($data)
    {
        $flutterwaveTransaction = Arr::get($data, 'payload');
        $flutterwaveTransactionId = Arr::get($flutterwaveTransaction, 'id');
        $txRef = Arr::get($flutterwaveTransaction, 'tx_ref', '');
        $status = Arr::get($flutterwaveTransaction, 'status', '');


        $flutterwaveTransaction = FlutterwaveAPI::getFlutterwaveObject('transactions/' . $flutterwaveTransactionId . '/verify');

        if (!is_wp_error($flutterwaveTransaction)) {
            $flutterwaveTransaction = Arr::get($flutterwaveTransaction, 'data', []);
            $status = Arr::get($flutterwaveTransaction, 'status');
            $txRef = Arr::get($flutterwaveTransaction, 'tx_ref', '');

        }

        if (!in_array($status, ['successful', 'succeeded'], true)) {
            $this->sendResponse(200, 'Transaction not successful, skipping.');
        }

    
        $transactionMeta = Arr::get($flutterwaveTransaction, 'meta', []);
        $transactionHash = Arr::get($transactionMeta, 'transaction_hash', '');

        // Try to get from tx_ref if not in meta
        $refType = '';
        $refHash = '';
        if ($txRef) {
            $parts = explode('_', $txRef);
            $refHash = $parts[1] ?? '';
            $refType = $parts[0] ?? '';
        }

        $transactionModel = null;
        $subscriptionModel = null;

        if ($refHash && $refType) {
            if ($refType == 'subscription') {
                $subscriptionModel = Subscription::query()
                    ->where('uuid', $refHash)
                    ->first();
                
                if (!$subscriptionModel) {
                    $this->sendResponse(200, 'Subscription not found or not active for the provided reference.');
                }

            
                if ($subscriptionModel->bill_count > 0) {
                    $this->processSubscriptionRecurringPayment($flutterwaveTransaction, $subscriptionModel);
                }

                // get the first transaction for the subscription
                $firstTransaction = OrderTransaction::query()
                    ->where('subscription_id', $subscriptionModel->id)
                    ->where('payment_method', 'flutterwave')
                    ->first();
                
                if (!$firstTransaction) {
                    $this->sendResponse(200, 'First transaction not found for the subscription.');
                }

                $transactionModel = $firstTransaction;
            } else if ($refType == 'onetime') {
                $transactionModel = OrderTransaction::query()
                    ->where('uuid', $refHash)
                    ->where('payment_method', 'flutterwave')
                    ->first();
                if (!$transactionModel && $transactionHash) {
                    $transactionModel = OrderTransaction::query()
                        ->where('uuid', $transactionHash)
                        ->where('payment_method', 'flutterwave')
                        ->first();
                }
                
                if (!$transactionModel || $transactionModel->status != Status::TRANSACTION_SUCCEEDED) {
                    $this->sendResponse(200, 'Transaction not found or not successful for the provided reference.');
                }
            }    
        }

        if (!$transactionModel) {
            $this->sendResponse(200, 'Transaction not found for the provided reference.');
        }

        if ($transactionModel->status == Status::TRANSACTION_SUCCEEDED) {
            $this->sendResponse(200, 'Transaction already processed.');
        }

        $billingInfo = [
            'type' => Arr::get($flutterwaveTransaction, 'payment_type', 'card'),
            'last4' => Arr::get($flutterwaveTransaction, 'card.last_4digits'),
            'brand' => Arr::get($flutterwaveTransaction, 'card.type'),
            'payment_method_id' => $flutterwaveTransactionId,
            'payment_method_type' => Arr::get($flutterwaveTransaction, 'payment_type'),
            'customer' => Arr::get($flutterwaveTransaction, 'customer'),
        ];

        $subscriptionData = [];
        if ($subscriptionModel && !in_array($subscriptionModel->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])) {
            $subscriptionData = (new FlutterwaveSubscriptions())->getSubscriptionData($subscriptionModel, [
                'flutterwave_transaction' => $flutterwaveTransaction,
                'billing_info' => $billingInfo,
            ]);
        }

        (new FlutterwaveConfirmations())->confirmPaymentSuccessByCharge($transactionModel, [
            'vendor_charge_id' => $flutterwaveTransactionId,
            'charge' => $flutterwaveTransaction,
            'subscription_data' => $subscriptionData,
            'billing_info' => $billingInfo
        ]);


        $this->sendResponse(200, 'Charge completed processed successfully');
    }

    /**
     * Process subscription recurring payment (renewal) from Flutterwave charge.completed webhook.
     * Mirrors PayPal IPN processRecurringPaymentReceived / Stripe invoice.payment_succeeded handling.
     */
    protected function processSubscriptionRecurringPayment(array $flutterwaveTransaction, Subscription $subscriptionModel)
    {
        if ($subscriptionModel->current_payment_method !== 'flutterwave') {
            return null;
        }

        $chargeId = Arr::get($flutterwaveTransaction, 'id');
        // check if the charge id is already in the database
        $charge = OrderTransaction::query()
            ->where('vendor_charge_id', $chargeId)
            ->where('payment_method', 'flutterwave')
            ->first();

        if ($charge) {
            return null;
        }

        $amount = FlutterwaveHelper::convertToLowestUnit(
            Arr::get($flutterwaveTransaction, 'amount', 0),
            Arr::get($flutterwaveTransaction, 'currency', $subscriptionModel->order->currency ?? 'USD')
        );
        if (!$amount || !$chargeId) {
            return null;
        }

        $transactionData = [
            'payment_method'      => 'flutterwave',
            'total'               => $amount,
            'vendor_charge_id'    => $chargeId,
            'payment_method_type' => Arr::get($flutterwaveTransaction, 'payment_type', 'card'),
            'payment_mode'        => $subscriptionModel->order ? $subscriptionModel->order->mode : 'live',
            'meta'                => [
                'customer' => Arr::get($flutterwaveTransaction, 'customer', []),
                'payment_type' => Arr::get($flutterwaveTransaction, 'payment_type'),
            ]
        ];

        $subscriptionUpdateData = [
            'current_payment_method' => 'flutterwave',
        ];
        $nextDue = Arr::get($flutterwaveTransaction, 'next_due');
        if ($nextDue) {
            $subscriptionUpdateData['next_billing_date'] = DateTime::anyTimeToGmt($nextDue)->format('Y-m-d H:i:s');
        }

        $result = SubscriptionService::recordRenewalPayment($transactionData, $subscriptionModel, $subscriptionUpdateData);
        if (is_wp_error($result)) {
            fluent_cart_add_log(
                __('Flutterwave subscription renewal webhook failed', 'flutterwave-for-fluent-cart'),
                $result->get_error_message(),
                'error',
                ['module_name' => 'payment', 'module_id' => 'flutterwave']
            );
            return null;
        }
        return $result;
    }

    /**
     * Handle refund.completed webhook event
     * This is triggered when a refund is processed from Flutterwave dashboard
     */
    public function handleRefundCompleted($data)
    {
        $refund = Arr::get($data, 'payload');
        
        $refundId = Arr::get($refund, 'id');
        $refundStatus = Arr::get($refund, 'status');
        
        // Only process completed refunds
        if ($refundStatus !== 'completed' && $refundStatus !== 'successful') {
            $this->sendResponse(200, 'Refund not completed, skipping. Status: ' . sanitize_text_field($refundStatus));
        }

        // Get the original transaction ID from the refund data
        $flwRef = Arr::get($refund, 'flw_ref');
        $transactionId = Arr::get($refund, 'transaction_id');
        
        // Find the parent transaction by vendor_charge_id (Flutterwave transaction ID)
        $parentTransaction = null;
        
        if ($transactionId) {
            $parentTransaction = OrderTransaction::query()
                ->where('vendor_charge_id', $transactionId)
                ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                ->where('payment_method', 'flutterwave')
                ->first();
        }

        // If not found by transaction_id, try by flw_ref in meta
        if (!$parentTransaction && $flwRef) {
            $parentTransaction = OrderTransaction::query()
                ->where('payment_method', 'flutterwave')
                ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                ->whereRaw("JSON_EXTRACT(meta, '$.flw_ref') = ?", [$flwRef])
                ->first();
        }

        if (!$parentTransaction) {
            $this->sendResponse(200, 'Parent transaction not found for refund');
        }

        $order = $parentTransaction->order;

        if (!$order) {
            $this->sendResponse(200, 'Order not found for refund');
        }

        // Convert amount - Flutterwave sends amount in main currency units
        $amount = FlutterwaveHelper::convertToLowestUnit(
            Arr::get($refund, 'amount_refunded', Arr::get($refund, 'amount')),
            Arr::get($refund, 'currency', $parentTransaction->currency)
        );
        $refundCurrency = Arr::get($refund, 'currency', $parentTransaction->currency);

        $refundData = [
            'order_id'           => $order->id,
            'transaction_type'   => Status::TRANSACTION_TYPE_REFUND,
            'status'             => Status::TRANSACTION_REFUNDED,
            'payment_method'     => 'flutterwave',
            'payment_mode'       => $parentTransaction->payment_mode,
            'vendor_charge_id'   => $refundId,
            'total'              => $amount,
            'currency'           => $refundCurrency,
            'meta'               => [
                'parent_id'          => $parentTransaction->id,
                'flw_ref'            => $flwRef,
                'refund_status'      => $refundStatus,
                'refund_description' => Arr::get($refund, 'comment', Arr::get($refund, 'meta.comment', '')),
                'refund_source'      => 'webhook'
            ]
        ];

        $syncedRefund = (new FlutterwaveRefund())->createOrUpdateIpnRefund($refundData, $parentTransaction);
        
        if ($syncedRefund && $syncedRefund->wasRecentlyCreated) {
            (new OrderRefund($order, $syncedRefund))->dispatch();

            fluent_cart_add_log(
                __('Flutterwave Refund Processed', 'flutterwave-for-fluent-cart'),
                sprintf(
                    /* translators: %1$s: Currency code, %2$s: Refund amount, %3$s: Refund ID */
                    __('Refund of %1$s %2$s processed via webhook. Refund ID: %3$s', 'flutterwave-for-fluent-cart'),
                    $refundCurrency,
                    FlutterwaveHelper::formatAmountForFlutterwave($amount, $refundCurrency),
                    $refundId
                ),
                'info',
                [
                    'module_name' => 'order',
                    'module_id'   => $order->id,
                ]
            );
        }

        $this->sendResponse(200, 'Refund processed successfully');
    }

    public function handleTransferCompleted($data)
    {
        // Handle transfer.completed - may also be used for refunds in some cases
        $transfer = Arr::get($data, 'payload');
        $order = Arr::get($data, 'order');

        // Check if this is a refund transfer
        $transferType = Arr::get($transfer, 'transfer_type', '');
        
        if (!$order) {
            // Try to find order from transfer reference
            $reference = Arr::get($transfer, 'reference', '');
            if ($reference) {
                $order = FlutterwaveHelper::getOrderFromTxRef($reference);
            }
        }

        if (!$order) {
            $this->sendResponse(200, 'Order not found for transfer');
        }

        $refundId = Arr::get($transfer, 'id');
        $amount = FlutterwaveHelper::convertToLowestUnit(
            Arr::get($transfer, 'amount'),
            Arr::get($transfer, 'currency')
        );
        $refundCurrency = Arr::get($transfer, 'currency');

        // Find the parent transaction
        $parentTransaction = OrderTransaction::query()
            ->where('order_id', $order->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->where('payment_method', 'flutterwave')
            ->first();

        if (!$parentTransaction) {
            $this->sendResponse(200, 'Parent transaction not found');
        }

        $refundData = [
            'order_id'           => $order->id,
            'transaction_type'   => Status::TRANSACTION_TYPE_REFUND,
            'status'             => Status::TRANSACTION_REFUNDED,
            'payment_method'     => 'flutterwave',
            'payment_mode'       => $parentTransaction->payment_mode,
            'vendor_charge_id'   => $refundId,
            'total'              => $amount,
            'currency'           => $refundCurrency,
            'meta'               => [
                'parent_id'          => $parentTransaction->id,
                'refund_description' => Arr::get($transfer, 'narration', ''),
                'refund_source'      => 'webhook'
            ]
        ];

        $syncedRefund = (new FlutterwaveRefund())->createOrUpdateIpnRefund($refundData, $parentTransaction);
        
        if ($syncedRefund && $syncedRefund->wasRecentlyCreated) {
            (new OrderRefund($order, $syncedRefund))->dispatch();

            fluent_cart_add_log(
                __('Flutterwave Transfer/Refund Processed', 'flutterwave-for-fluent-cart'),
                sprintf(
                    /* translators: %1$s: Currency code, %2$s: Refund amount, %3$s: Transfer/Refund ID */
                    __('Transfer/Refund of %1$s %2$s processed via webhook. ID: %3$s', 'flutterwave-for-fluent-cart'),
                    $refundCurrency,
                    FlutterwaveHelper::formatAmountForFlutterwave($amount, $refundCurrency),
                    $refundId
                ),
                'info',
                [
                    'module_name' => 'order',
                    'module_id'   => $order->id,
                ]
            );
        }

        $this->sendResponse(200, 'Transfer/Refund processed successfully');
    }

    public function handleSubscriptionCancelled($data)
    {
        $subscriptionData = Arr::get($data, 'payload');
        $flutterwaveSubscriptionId = Arr::get($subscriptionData, 'id');

        $subscriptionModel = Subscription::query()
            ->where('vendor_subscription_id', $flutterwaveSubscriptionId)
            ->first();

        if (!$subscriptionModel) {
            $this->sendResponse(200, 'Subscription not found');
        }

        // if (in_array($subscriptionModel->status, [Status::SUBSCRIPTION_CANCELED, Status::SUBSCRIPTION_COMPLETED, Status::SUBSCRIPTION_EXPIRED])) {
        //     $this->sendResponse(200, 'Subscription already cancelled/completed');
        // }

        $firstTransactionId = FlutterwaveHelper::getFirstTransactionByVendorChargeId($subscriptionModel->id);

        if (!$firstTransactionId) {
            $this->sendResponse(200, 'First transaction not found for the subscription');
        }

        $flutterwaveSubscriptions = FlutterwaveAPI::getFlutterwaveObject('subscriptions', ['transaction_id' => $firstTransactionId]);

        if (is_wp_error($flutterwaveSubscriptions) || !Arr::get($flutterwaveSubscriptions, 'data.0', [])) {
            $this->sendResponse(200, 'Flutterwave subscription not found');
        }

        $flutterwaveSubscription = Arr::get($flutterwaveSubscriptions, 'data.0', []);

        if ($flutterwaveSubscription['id'] != $flutterwaveSubscriptionId) {
            $this->sendResponse(200, 'Flutterwave subscription not found');
        }

        if ($flutterwaveSubscription['status'] != 'cancelled') {
            $this->sendResponse(200, 'Flutterwave subscription already cancelled');
        }

        $subscriptionModel->update([
            'status'     => Status::SUBSCRIPTION_CANCELED,
            'canceled_at' => DateTime::gmtNow()->format('Y-m-d H:i:s')
        ]);

        fluent_cart_add_log(
            __('Subscription Canceled', 'flutterwave-for-fluent-cart'),
            'Subscription cancellation received from Flutterwave for subscription ID: ' . $flutterwaveSubscriptionId,
            'info',
            [
                'module_name' => 'subscription',
                'module_id'   => $subscriptionModel->id
            ]
        );

        $this->sendResponse(200, 'Subscription cancellation processed successfully');
    }

    public function getFluentCartOrder($data)
    {
        $order = null;
        $event = Arr::get($data, 'event', '');

        $orderHash = Arr::get($data, 'data.meta.order_hash');
        if ($orderHash) {
            $order = Order::query()->where('uuid', $orderHash)->first();
        }

        $txRef = Arr::get($data, 'data.tx_ref');

        $refType = '';
        $refHash = '';
        if ($txRef) {
            $parts = explode('_', $txRef);
            $refHash = $parts[1] ?? '';
            $refType = $parts[0] ?? '';
        }
        

        if ($refHash && $refType) {
            if ($refType == 'subscription') {
                $subscriptionModel = Subscription::query()
                    ->where('uuid', $refHash)
                    ->first();
                if ($subscriptionModel) {
                    $order = Order::query()->where('id', $subscriptionModel->parent_order_id)->first();
                }
            }
            if ($refType == 'onetime') {
                $transactionModel = OrderTransaction::query()
                    ->where('uuid', $refHash)
                    ->where('payment_method', 'flutterwave')
                    ->first();
                if ($transactionModel) {
                    $order = Order::query()->where('id', $transactionModel->order_id)->first();
                }
            }
        }

        // subscription hash
        $subscriptionHash = Arr::get($data, 'data.meta.subscription_hash');
        if ($subscriptionHash && !$order) {
            $subscriptionModel = Subscription::query()
                ->where('uuid', $subscriptionHash)
                ->first();
            if ($subscriptionModel) {
                $order = Order::query()->where('id', $subscriptionModel->parent_order_id)->first();
            }
        }

        if (strpos($event, 'refund') !== false && !$order) {
            $transactionId = Arr::get($data, 'data.transaction_id');
            if ($transactionId) {
                $transaction = OrderTransaction::query()
                    ->where('vendor_charge_id', $transactionId)
                    ->where('payment_method', 'flutterwave')
                    ->first();
                
                if ($transaction) {
                    $order = Order::query()->where('id', $transaction->order_id)->first();
                }
            }

            // $flwRef = Arr::get($data, 'data.flw_ref');
            // if ($flwRef && !$order) {
            //     $transaction = OrderTransaction::query()
            //         ->where('payment_method', 'flutterwave')
            //         ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            //         ->whereRaw("JSON_EXTRACT(meta, '$.flw_ref') = ?", [$flwRef])
            //         ->first();
                
            //     if ($transaction) {
            //         $order = Order::query()->where('id', $transaction->order_id)->first();
            //     }
            // }
        }

        $subscriptionId = Arr::get($data, 'data.id');
        
        if (strpos($event, 'subscription') !== false && $subscriptionId && !$order) {
            $subscriptionModel = Subscription::query()
                ->where('vendor_subscription_id', $subscriptionId)
                ->first();
            
            if ($subscriptionModel) {
                $order = Order::query()->where('id', $subscriptionModel->parent_order_id)->first();
            }
        }

        return $order;
    }

    protected function sendResponse($statusCode = 200, $message = 'Success')
    {
        http_response_code($statusCode);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response for webhook
        echo wp_json_encode([
            'message' => $message,
        ]);

        exit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional webhook termination
    }
}
