<?php

namespace Tests\Integration;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Services\Payments\PaymentInstance;
use FlutterwaveFluentCart\API\FlutterwaveAPI;
use FlutterwaveFluentCart\Confirmations\FlutterwaveConfirmations;
use FlutterwaveFluentCart\FlutterwaveGateway;
use FlutterwaveFluentCart\FlutterwaveHelper;
use FlutterwaveFluentCart\Subscriptions\FlutterwaveSubscriptions;
use FlutterwaveFluentCart\Webhook\FlutterwaveWebhook;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;

class FlutterwavePaymentVerificationTest extends WPTestCase
{
    private $storeSettingsSnapshot;

    protected function tearDown(): void
    {
        remove_all_filters('pre_http_request');
        remove_all_filters('flutterwave_fc/settings');
        $this->resetApiSettings();
        CartCurrency::reset();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        if ($this->storeSettingsSnapshot !== null) {
            update_option('fluent_cart_store_settings', $this->storeSettingsSnapshot);
            StoreSettings::clearCache();
        }
        parent::tearDown();
    }

    /**
     * @dataProvider matchingCharges
     */
    public function test_a_charge_covering_the_order_is_confirmed(string $currency, int $total, $paid): void
    {
        $transaction = $this->pendingTransaction($currency, $total);

        $result = $this->confirm($transaction, $paid, $currency);

        $this->assertNotInstanceOf(\WP_Error::class, $result);
        $transaction = OrderTransaction::query()->find($transaction->id);
        $this->assertSame('succeeded', $transaction->status);
        $this->assertSame($total, (int) $transaction->total);
        $this->assertSame($currency, $transaction->currency);
    }

    public static function matchingCharges(): array
    {
        return [
            'NGN exact'                => ['NGN', 5000000, 50000],
            'USD with float noise'     => ['USD', 1999, 19.99],
            'UGX whole units'          => ['UGX', 3700000, 37000],
            'USD overpaid keeps total' => ['USD', 1999, 20.50],
        ];
    }

    /**
     * @dataProvider mismatchedCharges
     */
    public function test_a_charge_not_covering_the_order_is_refused(string $currency, int $total, $paid, string $paidCurrency): void
    {
        $transaction = $this->pendingTransaction($currency, $total);

        $result = $this->confirm($transaction, $paid, $paidCurrency);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('flutterwave_payment_mismatch', $result->get_error_code());
        $transaction = OrderTransaction::query()->find($transaction->id);
        $this->assertSame('pending', $transaction->status);
        $this->assertSame($total, (int) $transaction->total);
        $this->assertSame($currency, $transaction->currency);
        $this->assertEmpty($transaction->vendor_charge_id);
    }

    public static function mismatchedCharges(): array
    {
        return [
            'NGN underpaid'      => ['NGN', 5000000, 50, 'NGN'],
            'USD one cent short' => ['USD', 1999, 19.98, 'USD'],
            'paid in GHS'        => ['USD', 1999, 19.99, 'GHS'],
        ];
    }

    public function test_lowest_unit_conversion_rounds_float_noise(): void
    {
        $this->assertSame(1999, FlutterwaveHelper::convertToLowestUnit(19.99, 'USD'));
        $this->assertSame(115, FlutterwaveHelper::convertToLowestUnit(1.15, 'USD'));
    }

    public function test_a_cart_in_a_foreign_currency_is_offered_flutterwave(): void
    {
        $this->presentCurrency('NGN');
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'USD'], ['code' => 'GHS']]]);
        ExchangeRateService::record('USD', '0.00065');
        ExchangeRateService::record('GHS', '0.0095');

        $gateway = new FlutterwaveGateway();
        foreach (['NGN', 'USD', 'GHS'] as $currency) {
            $kept = apply_filters('fluent_cart/checkout_active_payment_methods', [$gateway], ['cart' => $this->cartIn($currency)]);
            $this->assertSame([$gateway], array_values($kept), $currency);
        }

        $withoutFlag = new FlutterwaveGateway();
        $withoutFlag->supportedFeatures = array_values(array_diff($withoutFlag->supportedFeatures, ['multi_currency']));
        $kept = apply_filters('fluent_cart/checkout_active_payment_methods', [$withoutFlag], ['cart' => $this->cartIn('USD')]);
        $this->assertSame([], array_values($kept));
    }

    /**
     * @dataProvider reactivationCharges
     */
    public function test_a_renewal_charge_settles_a_pending_reactivation_only_when_it_covers_it($paid, string $paidCurrency, string $expectedStatus): void
    {
        $subscription = $this->subscriptionIn('NGN', 5000000);
        $invoiceTransaction = $this->pendingReactivation($subscription, 5000000);

        $method = new \ReflectionMethod(FlutterwaveWebhook::class, 'processSubscriptionRecurringPayment');
        $method->setAccessible(true);
        $method->invoke(new FlutterwaveWebhook(), [
            'id' => 555001, 'status' => 'successful', 'amount' => $paid, 'currency' => $paidCurrency,
            'payment_type' => 'card', 'created_at' => gmdate('c'),
        ], Subscription::query()->find($subscription->id));

        $invoiceTransaction = OrderTransaction::query()->find($invoiceTransaction->id);
        $this->assertSame($expectedStatus, $invoiceTransaction->status);
        $this->assertSame(5000000, (int) $invoiceTransaction->total);
    }

    public static function reactivationCharges(): array
    {
        return [
            'covers the invoice' => [50000, 'NGN', 'succeeded'],
            'underpaid'          => [1, 'NGN', 'pending'],
            'paid in GHS'        => [50000, 'GHS', 'pending'],
        ];
    }

    /**
     * @dataProvider resyncCharges
     */
    public function test_resync_adopts_a_pending_transaction_only_for_a_successful_covering_charge($paid, string $remoteStatus, string $expectedStatus, $expectedSubscriptionStatus): void
    {
        $subscription = $this->subscriptionIn('NGN', 5000000);
        $transaction = $this->pendingTransaction('NGN', 5000000, $subscription->parent_order_id, $subscription->id);
        $subscription->updateMeta('flutterwave_subscription_transaction_id', 555002);
        $subscription->update(['vendor_plan_id' => '4242']);
        $this->stubFlutterwave([
            'subscriptions' => ['status' => 'success', 'data' => [['id' => 778, 'status' => 'active', 'customer' => ['id' => 1]]]],
            'transactions'  => [
                'status' => 'success',
                'meta'   => ['total' => 1],
                'data'   => [[
                    'id' => 555002, 'status' => $remoteStatus, 'amount' => $paid, 'currency' => 'NGN',
                    'tx_ref' => 'subscription_' . $subscription->uuid, 'created_at' => gmdate('c'),
                ]],
            ],
        ]);

        (new FlutterwaveSubscriptions())->reSyncSubscriptionFromRemote(Subscription::query()->find($subscription->id));

        $transaction = OrderTransaction::query()->find($transaction->id);
        $this->assertSame($expectedStatus, $transaction->status);
        $this->assertSame(5000000, (int) $transaction->total);
        $this->assertSame(1, OrderTransaction::query()->where('subscription_id', $subscription->id)->count());
        if ($expectedSubscriptionStatus) {
            $this->assertSame($expectedSubscriptionStatus, Subscription::query()->find($subscription->id)->status);
        }
    }

    public static function resyncCharges(): array
    {
        return [
            'successful and covering' => [50000, 'successful', 'succeeded', 'active'],
            'successful but short'    => [1, 'successful', 'pending', 'canceled'],
            'failed attempt'          => [50000, 'failed', 'pending', null],
        ];
    }

    public function test_payment_in_a_currency_flutterwave_does_not_support_is_refused(): void
    {
        $transaction = $this->pendingTransaction('AUD', 1999);

        $result = (new FlutterwaveGateway())->makePaymentFromPaymentInstance(new PaymentInstance(Order::query()->find($transaction->order_id)));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('flutterwave_unsupported_currency', $result->get_error_code());
    }

    private function subscriptionIn(string $currency, int $recurring): Subscription
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id, 'type' => 'subscription', 'payment_method' => 'flutterwave',
            'currency' => $currency, 'total_amount' => $recurring, 'payment_status' => 'paid',
        ]);

        return (new SubscriptionFactory())->create([
            'customer_id' => $customer->id, 'parent_order_id' => $order->id, 'product_id' => $product->ID,
            'variation_id' => $product->variants->first()->id, 'currency' => $currency,
            'recurring_amount' => $recurring, 'recurring_total' => $recurring, 'bill_times' => 0, 'bill_count' => 1,
            'current_payment_method' => 'flutterwave', 'vendor_subscription_id' => '777', 'vendor_plan_id' => '',
            'status' => 'canceled',
        ]);
    }

    private function pendingReactivation(Subscription $subscription, int $total): OrderTransaction
    {
        $parent = Order::query()->find($subscription->parent_order_id);
        $invoice = (new OrderFactory())->create([
            'customer_id' => $parent->customer_id, 'parent_id' => $parent->id, 'type' => 'renewal',
            'payment_method' => 'flutterwave', 'currency' => $parent->currency, 'total_amount' => $total,
            'payment_status' => 'pending',
        ]);

        return $this->pendingTransaction($parent->currency, $total, $invoice->id, $subscription->id);
    }

    private function stubFlutterwave(array $responsesByEndpoint): void
    {
        add_filter('flutterwave_fc/settings', function ($settings) {
            $settings['test_secret_key'] = Helper::encryptKey('FLWSECK_TEST-x');
            $settings['live_secret_key'] = Helper::encryptKey('FLWSECK-x');

            return $settings;
        });
        $this->resetApiSettings();

        add_filter('pre_http_request', function ($preempt, $args, $url) use ($responsesByEndpoint) {
            $endpoint = strtok(basename(wp_parse_url($url, PHP_URL_PATH)), '?');
            $response = $responsesByEndpoint[$endpoint] ?? [];

            return ['headers' => [], 'body' => wp_json_encode($response), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
        }, 10, 3);
    }

    private function resetApiSettings(): void
    {
        $property = new \ReflectionProperty(FlutterwaveAPI::class, 'settings');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    private function confirm(OrderTransaction $transaction, $paid, string $paidCurrency)
    {
        return (new FlutterwaveConfirmations())->confirmPaymentSuccessByCharge($transaction, [
            'vendor_charge_id' => '987654',
            'charge'           => [
                'id' => 987654, 'status' => 'successful', 'amount' => $paid, 'currency' => $paidCurrency,
                'tx_ref' => 'onetime_' . $transaction->uuid, 'created_at' => gmdate('c'),
            ],
            'billing_info'     => [],
        ]);
    }

    private function pendingTransaction(string $currency, int $total, int $orderId = 0, int $subscriptionId = 0): OrderTransaction
    {
        if (!$orderId) {
            $customer = (new CustomerFactory())->create();
            $orderId = (new OrderFactory())->create([
                'customer_id' => $customer->id, 'type' => 'onetime', 'payment_method' => 'flutterwave',
                'currency' => $currency, 'total_amount' => $total,
            ])->id;
        }

        return OrderTransaction::query()->create(array_filter([
            'order_id' => $orderId, 'subscription_id' => $subscriptionId, 'uuid' => wp_generate_uuid4(),
            'payment_method' => 'flutterwave', 'payment_mode' => 'test', 'status' => 'pending',
            'transaction_type' => 'charge', 'currency' => $currency, 'total' => $total,
        ]) + ['vendor_charge_id' => '']);
    }

    private function presentCurrency(string $currency): void
    {
        if ($this->storeSettingsSnapshot === null) {
            $this->storeSettingsSnapshot = get_option('fluent_cart_store_settings', []);
        }
        update_option('fluent_cart_store_settings', array_merge((array) $this->storeSettingsSnapshot, ['currency' => $currency]));
        StoreSettings::clearCache();
    }

    private function cartIn(string $currency): Cart
    {
        $product = (new ProductFactory())->create();
        $variation = ProductVariation::query()->find($product->variants->first()->id);

        $cart = new Cart();
        $cart->cart_group = 'instant';
        $cart->cart_data = [CartHelper::generateCartItemFromVariation($variation, 1)];
        $cart->checkout_data = [];
        CartCurrency::force($cart, $currency);
        $cart->save();

        return Cart::query()->find($cart->cart_hash);
    }
}
