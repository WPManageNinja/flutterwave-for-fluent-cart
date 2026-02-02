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

        // Detect if this is a reactivation (renewal order)
        $isRenewal = $order->type === Status::ORDER_TYPE_RENEWAL;

        // Validate trial days compatibility with Flutterwave (skip for renewals)
        if (!$isRenewal) {
            $trialValidation = $this->validateTrialDays($subscription);
            if (is_wp_error($trialValidation)) {
                return $trialValidation;
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

        if ($isRenewal) {
            $firstChargeAmount = (int) $transaction->total;
        } else {
            $firstChargeAmount = $this->calculateFirstChargeAmount($subscription, $transaction);
            if (is_wp_error($firstChargeAmount)) {
                return $firstChargeAmount;
            }
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

        // For reactivation, use remaining bill times; otherwise use original bill_times
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
            $planData['duration'] = max(0, (int) $billTimes - 1);
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
            $vendorTransactionId = Arr::get($args, 'vendor_transaction_id', '');
        }  

        if ($subscriptionModel->vendor_plan_id) {
            // get subscriptions by 'transaction_id' is the same as the flutterwave transaction id
            $flutterwaveSubscriptions = FlutterwaveAPI::getFlutterwaveObject('subscriptions', ['transaction_id' => $vendorTransactionId]);
            $flutterwaveSubscription = Arr::get($flutterwaveSubscriptions, 'data.0', []);
            if ($flutterwaveSubscription) {
                $vendorSubscriptionId = Arr::get($flutterwaveSubscription, 'id');
                $customerId = Arr::get($flutterwaveSubscription, 'customer.id');
            }
        }

        $status = FlutterwaveHelper::getFctSubscriptionStatus(Arr::get($flutterwaveSubscription, 'status'));

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
            return true; // No trial, no validation needed
        }

        $isSimulatedTrial = Arr::get($subscription->config, 'is_trial_days_simulated', 'no') === 'yes';

        if ($isSimulatedTrial) {
            return true; // Simulated trials (coupons) always work - just different first charge amount
        }

        // Real trial: check if trial_days covers at least one full billing period
        $minTrialDays = $this->getMinTrialDaysForInterval($subscription->billing_interval);

        if ($trialDays < $minTrialDays) {
            return new \WP_Error(
                'flutterwave_trial_not_supported',
                sprintf(
                    /* translators: %1$d: configured trial days, %2$d: minimum required days, %3$s: billing interval */
                    __('Flutterwave does not support %1$d-day trials for %3$s subscriptions. Minimum trial period is %2$d days (one full billing cycle). Consider using a different payment method or adjusting the trial period.', 'flutterwave-for-fluent-cart'),
                    $trialDays,
                    $minTrialDays,
                    $subscription->billing_interval
                )
            );
        }

        return true;
    }

    /**
     * Get minimum trial days required for a billing interval.
     * Flutterwave can only offer "first period free", so trial must be >= one full period.
     */
    private function getMinTrialDaysForInterval($interval)
    {
        $intervalDays = [
            'daily'       => 1,
            'weekly'      => 7,
            'monthly'     => 30,
            'quarterly'   => 90,
            'half_yearly' => 180,
            'yearly'      => 365,
        ];

        return $intervalDays[$interval] ?? 30;
    }

    /**
     * Calculate the first charge amount based on trial/coupon status.
     * Returns WP_Error if amount is invalid (e.g., $0 for real trials).
     *
     * @return int|\WP_Error
     */
    private function calculateFirstChargeAmount($subscription, $transaction)
    {
        $trialDays = (int) ($subscription->trial_days ?? 0);
        $isSimulatedTrial = Arr::get($subscription->config, 'is_trial_days_simulated', 'no') === 'yes';

        if ($isSimulatedTrial) {
            $amount = (int) $transaction->total;

            if ($amount <= 0) {
                $minimumAmount = $this->getMinimumChargeAmount($transaction->currency);
                if ($minimumAmount > 0) {
                    return $minimumAmount;
                }

                return new \WP_Error(
                    'flutterwave_zero_amount_not_supported',
                    __('Flutterwave does not support $0 first payment. A 100% discount coupon cannot be used with Flutterwave subscriptions.', 'flutterwave-for-fluent-cart')
                );
            }

            return $amount;
        }

        if ($trialDays > 0) {
            $minimumAmount = $this->getMinimumChargeAmount($transaction->currency);

            if ($minimumAmount <= 0) {
                return new \WP_Error(
                    'flutterwave_trial_zero_not_supported',
                    __('Flutterwave does not support $0 first payment for trials. Please configure a minimum trial amount using the "flutterwave-for-fluent-cart/minimum_trial_amounts" filter, or use a different payment method.', 'flutterwave-for-fluent-cart')
                );
            }

            return $minimumAmount;
        }

        return (int) $transaction->total;
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

        // get the vendor charge of the first transaction
        $transactionId = FlutterwaveHelper::getFirstTransactionByVendorChargeId($subscriptionModel->id);


        $updateData = $this->getSubscriptionData($subscriptionModel, [
            'vendor_transaction_id' => $transactionId,
        ]);

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
