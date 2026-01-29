<?php

namespace FlutterwaveFluentCart\Subscriptions;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Product;
use FlutterwaveFluentCart\API\FlutterwaveAPI;
use FlutterwaveFluentCart\FlutterwaveHelper;
use FlutterwaveFluentCart\Settings\FlutterwaveSettingsBase;

class FlutterwaveSubscriptions extends AbstractSubscriptionModule
{
    public function handleSubscription($paymentInstance, $paymentArgs)
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $paymentInstance->order->customer;
        $subscription = $paymentInstance->subscription;

        // Get public key for inline checkout
        $settings = new FlutterwaveSettingsBase();
        $publicKey = $settings->getPublicKey();

        if (!$publicKey) {
            return new \WP_Error(
                'flutterwave_no_public_key',
                __('Flutterwave public key is not configured.', 'flutterwave-for-fluent-cart')
            );
        }

        if (!empty($subscription->trial_days) && (int) $subscription->trial_days > 0) {
            return new \WP_Error(
                'flutterwave_trial_not_supported',
                __('Flutterwave does not support subscriptions with a trial period. Please choose another payment method or remove the trial from this product.', 'flutterwave-for-fluent-cart')
            );
        }

        $plan = self::getOrCreateFlutterwavePlan($paymentInstance);

        if (is_wp_error($plan)) {
            return $plan;
        }

        $planId = Arr::get($plan, 'data.id');

        $subscription->update([
            'vendor_plan_id' => $planId,
        ]);

        $txRef = $transaction->uuid . '_' . time();


        $firstChargeAmount = $transaction->total;

        $inlineData = [
            'public_key'   => $publicKey,
            'tx_ref'       => $txRef,
            'amount'       => FlutterwaveHelper::formatAmountForFlutterwave($firstChargeAmount, $transaction->currency),
            'currency'     => strtoupper($transaction->currency),
            'payment_plan' => $planId,
            'customer'     => [
                'email' => $fcCustomer->email,
                'name'  => trim($fcCustomer->first_name . ' ' . $fcCustomer->last_name),
            ],
            'meta'         => [
                'order_id'          => $order->id,
                'order_hash'        => $order->uuid,
                'transaction_hash'  => $transaction->uuid,
                'subscription_hash' => $subscription->uuid,
            ],
            'customizations' => [
                'title'       => get_bloginfo('name'),
                'description' => sprintf(__('Subscription for %s', 'flutterwave-for-fluent-cart'), $subscription->item_name),
                'logo'        => get_site_icon_url(),
            ]
        ];

        if (!empty($fcCustomer->phone)) {
            $inlineData['customer']['phone_number'] = $fcCustomer->phone;
        }

        $inlineData = apply_filters('flutterwave-for-fluent-cart/subscription_payment_args', $inlineData, [
            'order'        => $order,
            'transaction'  => $transaction,
            'subscription' => $subscription
        ]);


        return [
            'status'       => 'success',
            'nextAction'   => 'flutterwave',
            'actionName'   => 'custom',
            'message'      => __('Opening Flutterwave payment popup...', 'flutterwave-for-fluent-cart'),
            'data'         => [
                'flutterwave_data' => $inlineData,
                'tx_ref'           => $txRef,
                'intent'           => 'subscription',
                'transaction_hash' => $transaction->uuid,
            ]
        ];
    }

    public static function getOrCreateFlutterwavePlan($paymentInstance)
    {
        $subscription = $paymentInstance->subscription;
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $variation = $subscription->variation;
        $product = $subscription->product;

        $interval = FlutterwaveHelper::mapIntervalToFlutterwave($subscription->billing_interval);

        $billingPeriod = apply_filters('fluent_cart/subscription_billing_period', [
            'interval' => $interval
        ], [
            'subscription_interval' => $subscription->billing_interval,
            'payment_method' => 'flutterwave',
        ]);

        $fctFlutterwavePlanId = 'fct_flutterwave_plan_'
            . $order->mode . '_'
            . $product->id . '_'
            . $order->variation_id . '_'
            . $subscription->recurring_total . '_'
            . $subscription->billing_interval . '_'
            . $subscription->bill_times . '_'
            . $transaction->currency;

        $planData = [
            'name'     => $subscription->item_name,
            'amount'   => FlutterwaveHelper::formatAmountForFlutterwave($subscription->recurring_total, $transaction->currency),
            'interval' => Arr::get($billingPeriod, 'interval'),
            'currency' => strtoupper($transaction->currency),
        ];

        // Flutterwave duration = number of charges AFTER the first; total charges = 1 + duration.
        // So for bill_times total payments we send duration = bill_times - 1.
        if ($subscription->bill_times && $subscription->bill_times > 0) {
            $planData['duration'] = max(0, (int) $subscription->bill_times - 1);
        }

        $fctFlutterwavePlanId = apply_filters('fluent_cart/flutterwave_plan_id', $fctFlutterwavePlanId, [
            'plan_data' => $planData,
            'variation' => $variation,
            'product'   => $product
        ]);

        $existingPlanId = null;
        if ($product && $product instanceof Product) {
            $existingPlanId = $product->getProductMeta($fctFlutterwavePlanId);
        }

        if ($existingPlanId) {
            $existingPlan = FlutterwaveAPI::getFlutterwaveObject('payment-plans/' . $existingPlanId);
            if (!is_wp_error($existingPlan) && Arr::get($existingPlan, 'status') === 'success') {
                return $existingPlan;
            }
        }

        $plan = FlutterwaveAPI::createFlutterwaveObject('payment-plans', $planData);

        if (is_wp_error($plan)) {
            return $plan;
        }

        if (Arr::get($plan, 'status') !== 'success') {
            return new \WP_Error(
                'plan_creation_failed',
                Arr::get($plan, 'message', __('Failed to create payment plan in Flutterwave.', 'flutterwave-for-fluent-cart'))
            );
        }

        if ($product && $product instanceof Product) {
            $product->updateProductMeta($fctFlutterwavePlanId, Arr::get($plan, 'data.id'));
        }

        return $plan;
    }

    public function activateSubscription($subscriptionModel, $args = [])
    {
        $order = $subscriptionModel->order;
        $oldStatus = $subscriptionModel->status;
        $flutterwaveTransaction = Arr::get($args, 'flutterwave_transaction', []);
        $billingInfo = Arr::get($args, 'billing_info', []);

        $nextBillingDate = $this->calculateNextBillingDate($subscriptionModel);

        $vendorSubscriptionId = null;
        $customerId = Arr::get($flutterwaveTransaction, 'customer.id');

        if ($customerId && $subscriptionModel->vendor_plan_id) {
            $flutterwaveSubscriptions = FlutterwaveAPI::getFlutterwaveObject('subscriptions', [
                'email' => Arr::get($flutterwaveTransaction, 'customer.email')
            ]);

            if (!is_wp_error($flutterwaveSubscriptions)) {
                $subs = Arr::get($flutterwaveSubscriptions, 'data', []);
                foreach ($subs as $sub) {
                    if (Arr::get($sub, 'plan') == $subscriptionModel->vendor_plan_id) {
                        $vendorSubscriptionId = Arr::get($sub, 'id');
                        break;
                    }
                }
            }
        }

        $status = $subscriptionModel->trial_days > 0 ? Status::SUBSCRIPTION_TRIALING : Status::SUBSCRIPTION_ACTIVE;

        $updateData = [
            'status'                 => $status,
            'vendor_customer_id'     => $customerId,
            'next_billing_date'      => $nextBillingDate,
            'current_payment_method' => 'flutterwave',
        ];

        if ($vendorSubscriptionId) {
            $updateData['vendor_subscription_id'] = $vendorSubscriptionId;
        }

        $subscriptionModel->update($updateData);
        $subscriptionModel->updateMeta('active_payment_method', $billingInfo);

        fluent_cart_add_log(
            __('Flutterwave Subscription Activated', 'flutterwave-for-fluent-cart'),
            'Subscription activated via Flutterwave. Plan ID: ' . $subscriptionModel->vendor_plan_id,
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $order->id
            ]
        );

        if ($oldStatus != $subscriptionModel->status && in_array($subscriptionModel->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])) {
            (new SubscriptionActivated($subscriptionModel, $order, $order->customer))->dispatch();
        }

        return $updateData;
    }

    private function calculateNextBillingDate($subscriptionModel)
    {
        $interval = $subscriptionModel->billing_interval;
        $trialDays = $subscriptionModel->trial_days;

        $now = DateTime::gmtNow();

        if ($trialDays > 0) {
            $now->addDays($trialDays);
        }

        switch ($interval) {
            case 'daily':
                $now->addDay();
                break;
            case 'weekly':
                $now->addWeek();
                break;
            case 'monthly':
                $now->addMonth();
                break;
            case 'quarterly':
                $now->addMonths(3);
                break;
            case 'half_yearly':
                $now->addMonths(6);
                break;
            case 'yearly':
                $now->addYear();
                break;
            default:
                $now->addMonth();
        }

        return $now->format('Y-m-d H:i:s');
    }

    public function reSyncSubscriptionFromRemote(Subscription $subscriptionModel)
    {
        if ($subscriptionModel->current_payment_method !== 'flutterwave') {
            return new \WP_Error(
                'invalid_payment_method',
                __('This subscription is not using Flutterwave as payment method.', 'flutterwave-for-fluent-cart')
            );
        }

        $vendorSubscriptionId = $subscriptionModel->vendor_subscription_id;

        if (!$vendorSubscriptionId) {
            return new \WP_Error(
                'invalid_subscription',
                __('Invalid vendor subscription ID.', 'flutterwave-for-fluent-cart')
            );
        }

        $flutterwaveSubscription = FlutterwaveAPI::getFlutterwaveObject('subscriptions/' . $vendorSubscriptionId);
        
        if (is_wp_error($flutterwaveSubscription)) {
            return $flutterwaveSubscription;
        }

        $subscriptionUpdateData = FlutterwaveHelper::getSubscriptionUpdateData($flutterwaveSubscription, $subscriptionModel);
        
        $subscriptionModel = SubscriptionService::syncSubscriptionStates($subscriptionModel, $subscriptionUpdateData);

        return $subscriptionModel;
    }

    public function cancel($vendorSubscriptionId, $args = [])
    {
        $subscriptionModel = Subscription::query()
            ->where('vendor_subscription_id', $vendorSubscriptionId)
            ->first();

        if (!$subscriptionModel) {
            return new \WP_Error(
                'invalid_subscription',
                __('Invalid vendor subscription ID.', 'flutterwave-for-fluent-cart')
            );
        }

        $response = FlutterwaveAPI::updateFlutterwaveObject('subscriptions/' . $vendorSubscriptionId . '/cancel');

        if (is_wp_error($response)) {
            fluent_cart_add_log(
                'Flutterwave Subscription Cancellation Failed',
                $response->get_error_message(),
                'error',
                [
                    'module_name' => 'subscription',
                    'module_id'   => $subscriptionModel->id,
                ]
            );
            return $response;
        }

        if (Arr::get($response, 'status') !== 'success') {
            return new \WP_Error(
                'cancellation_failed',
                Arr::get($response, 'message', __('Failed to cancel subscription on Flutterwave.', 'flutterwave-for-fluent-cart'))
            );
        }

        $subscriptionModel->update([
            'status'      => Status::SUBSCRIPTION_CANCELED,
            'canceled_at' => DateTime::gmtNow()->format('Y-m-d H:i:s')
        ]);

        $order = $subscriptionModel->order;

        fluent_cart_add_log(
            __('Flutterwave Subscription Cancelled', 'flutterwave-for-fluent-cart'),
            __('Subscription cancelled on Flutterwave. ID: ', 'flutterwave-for-fluent-cart') . $vendorSubscriptionId,
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $order ? $order->id : null,
            ]
        );

        fluent_cart_add_log(
            __('Flutterwave Subscription Cancelled', 'flutterwave-for-fluent-cart'),
            __('Subscription cancelled on Flutterwave. ID: ', 'flutterwave-for-fluent-cart') . $vendorSubscriptionId,
            'info',
            [
                'module_name' => 'subscription',
                'module_id'   => $subscriptionModel->id,
            ]
        );

        return [
            'status'      => Status::SUBSCRIPTION_CANCELED,
            'canceled_at' => DateTime::gmtNow()->format('Y-m-d H:i:s')
        ];
    }
}
