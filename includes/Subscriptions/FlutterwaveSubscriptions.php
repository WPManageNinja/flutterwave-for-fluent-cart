<?php

namespace FlutterwaveFluentCart\Subscriptions;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\OrderTransaction;
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

        $isRenewal = $order->type === Status::ORDER_TYPE_RENEWAL;

        if (!$isRenewal) {
            $trialValidation = $this->validateTrialDays($subscription);
            if (is_wp_error($trialValidation)) {
                return $trialValidation;
            }
        }

        if ($isRenewal) {
            $trialDays = $subscription->getReactivationTrialDays();
            if ($trialDays > 0) {
                return new \WP_Error(
                    'flutterwave_reactivation_trial_not_supported',
                    __('Flutterwave does not support 0 payment or trial days. wait for period end to reactivate.', 'flutterwave-for-fluent-cart')
                );
            }
        }

        $plan = self::getOrCreateFlutterwavePlan($paymentInstance, $isRenewal);

        if (is_wp_error($plan)) {
            return $plan;
        }

        $planId = Arr::get($plan, 'data.id');

        $subscription->update([
            'vendor_plan_id' => $planId,
        ]);

        $txRef = 'subscription_' . $subscription->uuid;
        $firstChargeAmount = $transaction->total;

        if ($firstChargeAmount <= 0) {
            return new \WP_Error(
                'flutterwave_zero_amount_not_supported',
                __('Flutterwave does not support $0 first payment. A 100% discount coupon cannot be used with Flutterwave subscriptions.', 'flutterwave-for-fluent-cart')
            );
        }

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
                'order_id'            => $order->id,
                'order_hash'          => $order->uuid,
                'transaction_hash'    => $transaction->uuid,
                'subscription_hash'   => $subscription->uuid,
                'trial_days'          => $subscription->trial_days ?? 0,
                'is_simulated_trial'  => Arr::get($subscription->config, 'is_trial_days_simulated', 'no'),
                'first_charge_amount' => FlutterwaveHelper::formatAmountForFlutterwave($firstChargeAmount, $transaction->currency),
                'recurring_amount'    => FlutterwaveHelper::formatAmountForFlutterwave($subscription->recurring_total, $transaction->currency),
                'is_renewal'          => $isRenewal ? 'yes' : 'no',
            ],
            'customizations' => [
                'title'       => get_bloginfo('name'),
                /* translators: %s: Subscription item name */
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

    public static function getOrCreateFlutterwavePlan($paymentInstance, $isRenewal = false)
    {
        $subscription = $paymentInstance->subscription;
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $variation = $subscription->variation;
        $product = $subscription->product;

        $billTimes = $isRenewal ? $subscription->getRequiredBillTimes() : $subscription->bill_times;

        $interval = FlutterwaveHelper::mapIntervalToFlutterwave($subscription->billing_interval);

        $billingPeriod = apply_filters('fluent_cart/subscription_billing_period', [
            'interval' => $interval
        ], [
            'subscription_interval' => $subscription->billing_interval,
            'payment_method' => 'flutterwave',
        ]);

        // Plan ID includes bill times (will differ for reactivation if remaining charges differ)
        $fctFlutterwavePlanId = 'fct_flutterwave_plan_'
            . $order->mode . '_'
            . $product->id . '_'
            . $order->variation_id . '_'
            . $subscription->recurring_total . '_'
            . $subscription->billing_interval . '_'
            . $billTimes . '_'
            . $transaction->currency;

        $planData = [
            'name'     => $subscription->item_name,
            'amount'   => FlutterwaveHelper::formatAmountForFlutterwave($subscription->recurring_total, $transaction->currency),
            'interval' => Arr::get($billingPeriod, 'interval'),
            'currency' => strtoupper($transaction->currency),
        ];

        if ($billTimes && $billTimes > 0) {
            $planData['duration'] = max(0, (int) $billTimes - 1); // -1 because first charge is done on subscription creation, we always cancel on subscription cancel, so no worries for in case of 0 even though the subscription is installment based
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

    public function getSubscriptionData($subscriptionModel, $args = [])
    {
        $flutterwaveTransaction = Arr::get($args, 'flutterwave_transaction', []);
        $nextBillingDate = $this->calculateNextBillingDate($subscriptionModel);
     
        $flutterwaveSubscription = null;

        $vendorSubscriptionId = null;
        $customerId = null;

        $vendorTransactionId = null;

        if ($flutterwaveTransaction) {
            $vendorTransactionId = Arr::get($flutterwaveTransaction, 'id');
        } else {
            $vendorTransactionId = $subscriptionModel->getMeta('flutterwave_subscription_transaction_id');
        }  

        if (!$vendorTransactionId) {
            return new \WP_Error(
                'invalid_transaction',
                __('Invalid transaction ID.', 'flutterwave-for-fluent-cart')
            );
        }

        if ($subscriptionModel->vendor_plan_id) {
            // get subscriptions by 'transaction_id', transaction id is the first transaction id created during subscription creation
            $flutterwaveSubscriptions = FlutterwaveAPI::getFlutterwaveObject('subscriptions', ['transaction_id' => $vendorTransactionId]);
            $flutterwaveSubscription = Arr::get($flutterwaveSubscriptions, 'data.0', []);
            if ($flutterwaveSubscription) {
                $vendorSubscriptionId = Arr::get($flutterwaveSubscription, 'id');
                $customerId = Arr::get($flutterwaveSubscription, 'customer.id');
            }
        }

        $status = FlutterwaveHelper::getFctSubscriptionStatus(Arr::get($flutterwaveSubscription, 'status'));

        if ($subscriptionModel->status == Status::SUBSCRIPTION_COMPLETED) {
            $status = Status::SUBSCRIPTION_COMPLETED;
        }

        $updateData = [
            'status'                 => $status,
            'vendor_customer_id'     => $customerId,
            'next_billing_date'      => $nextBillingDate,
            'current_payment_method' => 'flutterwave',
            'vendor_response'        => json_encode($flutterwaveSubscription),
        ];

        if ($vendorSubscriptionId) {
            $updateData['vendor_subscription_id'] = $vendorSubscriptionId;
        }

        if ($vendorTransactionId) {
            $subscriptionModel->updateMeta('flutterwave_subscription_transaction_id', $vendorTransactionId);
        }

        return $updateData;
    }

    private function calculateNextBillingDate($subscriptionModel)
    {
        $interval = $subscriptionModel->billing_interval;
        $trialDays = $subscriptionModel->trial_days;

        $currentNextBillingDate = $subscriptionModel->next_billing_date;

        if ($currentNextBillingDate) {
            $now = DateTime::anyTimeToGmt($currentNextBillingDate);
        } else {
            $now = DateTime::gmtNow();
            if ($trialDays > 0) {
                $now->addDays($trialDays);
            }
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

    /**
     * Validate that trial_days is compatible with Flutterwave's billing model.
     * Flutterwave can only do "first period free" - it cannot do custom trial days
     * that don't match the billing interval.
     */
    private function validateTrialDays($subscription)
    {
        $trialDays = (int) ($subscription->trial_days ?? 0);

        if ($trialDays <= 0) {
            return true;
        }

        $isSimulatedTrial = Arr::get($subscription->config, 'is_trial_days_simulated', 'no') === 'yes';

        if ($isSimulatedTrial) {
            return true; // Simulated trials (coupons) always work - just different first charge amount
        }

        return new \WP_Error(
            'flutterwave_trial_not_supported',
            __('Flutterwave does not support does not support different trial days.', 'flutterwave-for-fluent-cart')
        );

    }

    /**
     * Get minimum charge amount for a currency (in lowest unit - cents/kobo).
     * Defaults to 0 (which will trigger an error). Developers can use the filter
     * to set minimum amounts per currency based on their Flutterwave testing.
     *
     * Example filter usage:
     * add_filter('flutterwave-for-fluent-cart/minimum_trial_amounts', function($amounts) {
     *     $amounts['NGN'] = 10000; // 100 Naira in kobo
     *     $amounts['USD'] = 100;   // 1 USD in cents
     *     return $amounts;
     * });
     */
    private function getMinimumChargeAmount($currency)
    {
        $minimumAmounts = apply_filters('flutterwave-for-fluent-cart/minimum_trial_amounts', [
            'default' => 0
        ]);

        return $minimumAmounts[strtoupper($currency)] ?? $minimumAmounts['default'] ?? 0;
    }

    public function reSyncSubscriptionFromRemote(Subscription $subscriptionModel)
    {
        if ($subscriptionModel->current_payment_method != 'flutterwave') {
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

        $updateData = $this->getSubscriptionData($subscriptionModel);

        $trxRef = 'subscription_' . $subscriptionModel->uuid;

        // get transactions by trx_ref from flutterwave
        $flutterwaveTransactions = [];
        $hasMore = true;
        $currentPage = 1;
        do {
            $result = FlutterwaveAPI::getFlutterwaveObject('transactions', ['tx_ref' => $trxRef, 'page' => $currentPage]);
            if (is_wp_error($result)) {
                break;
            }
            $total = Arr::get($result, 'meta.total');
            $flutterwaveTransactions = array_merge($flutterwaveTransactions, Arr::get($result, 'data', []));

            if ($total > count($flutterwaveTransactions)) {
                $currentPage++;
            } else {
                $hasMore = false;
            }

        } while ($hasMore);

        foreach ($flutterwaveTransactions as $flutterwaveTransaction) {
            $transaction = OrderTransaction::query()
                ->where('subscription_id', $subscriptionModel->id)
                ->where('payment_method', 'flutterwave')
                ->where('vendor_charge_id', $flutterwaveTransaction['id'])
                ->first();

            if (!$transaction) {
               // transaction with no vendor charge id , then update and continue
               $transaction = OrderTransaction::query()
                ->where('subscription_id', $subscriptionModel->id)
                ->where('payment_method', 'flutterwave')
                ->where('vendor_charge_id', '')
                ->first();

                if ($transaction) {
                    $transaction->update([
                        'vendor_charge_id' => $flutterwaveTransaction['id'],
                        'status'           => Status::TRANSACTION_SUCCEEDED,
                        'total'            => FlutterwaveHelper::convertToLowestUnit($flutterwaveTransaction['amount'], $flutterwaveTransaction['currency']),
                    ]);

                    continue;
                }


                // record renewal payment
                $transactionData = [
                    'order_id'         => $subscriptionModel->order->id,
                    'subscription_id'  => $subscriptionModel->id,
                    'vendor_charge_id' => Arr::get($flutterwaveTransaction, 'id'),
                    'last4'            => Arr::get($flutterwaveTransaction, 'card.last_4digits', null),
                    'brand'            => Arr::get($flutterwaveTransaction, 'card.type', null),
                    'payment_method_type' => Arr::get($flutterwaveTransaction, 'payment_type', null),
                    'status'           => Status::TRANSACTION_SUCCEEDED,
                    'total'            => FlutterwaveHelper::convertToLowestUnit($flutterwaveTransaction['amount'], $flutterwaveTransaction['currency']),
                ];
                
                SubscriptionService::recordRenewalPayment($transactionData, $subscriptionModel, $updateData);
               
            }

        }
        
        $subscriptionModel = SubscriptionService::syncSubscriptionStates($subscriptionModel, $updateData);

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

        $status = FlutterwaveHelper::getFctSubscriptionStatus(Arr::get($response, 'status'));
        if ($subscriptionModel->status == Status::SUBSCRIPTION_COMPLETED) {
            $status = Status::SUBSCRIPTION_COMPLETED;
            // log canceled after completed
            fluent_cart_add_log(
                __('Flutterwave Subscription Cancelled After Completed', 'flutterwave-for-fluent-cart'),
                __('Subscription cancelled on Flutterwave after it was completed. ID: ', 'flutterwave-for-fluent-cart') . $vendorSubscriptionId,
                'info',
                [
                    'module_name' => 'order',
                    'module_id'   => $subscriptionModel->parent_order_id,
                ]
            );

            return [
                'status'      => $status,
                'canceled_at' => DateTime::gmtNow()->format('Y-m-d H:i:s')
            ];
        }
        
        fluent_cart_add_log(
            __('Flutterwave Subscription Cancelled', 'flutterwave-for-fluent-cart'),
            __('Subscription cancelled on Flutterwave. ID: ', 'flutterwave-for-fluent-cart') . $vendorSubscriptionId,
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $subscriptionModel->parent_order_id,
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
            'status'      => $status,
            'canceled_at' => DateTime::gmtNow()->format('Y-m-d H:i:s')
        ];
    }
}
