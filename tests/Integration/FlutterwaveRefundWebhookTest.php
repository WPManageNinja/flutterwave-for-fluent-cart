<?php

namespace Tests\Integration;

use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FlutterwaveFluentCart\Webhook\FlutterwaveWebhook;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\RefundWebhookProbe;

class FlutterwaveRefundWebhookTest extends WPTestCase
{
    private $charge;
    private $events = 0;
    private $listener;
    private $settingsFilter;
    private $server;
    private $secret = 'refund-test-secret';
    private $webhook;
    private $hookSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        App::db()->beginTransaction();
        $this->server = $_SERVER;
        $this->settingsFilter = function ($settings) {
            $settings['live_webhook_secret_hash'] = $this->secret;
            $settings['test_webhook_secret_hash'] = $this->secret;
            return $settings;
        };
        add_filter('flutterwave_fc/settings', $this->settingsFilter);
        $this->listener = function ($data) {
            $this->events++;
        };
        add_action('fluent_cart/order_refunded', $this->listener);
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id, 'payment_method' => 'flutterwave',
            'currency' => 'NGN', 'total_paid' => 10000, 'payment_status' => 'paid',
        ]);
        $this->charge = OrderTransaction::query()->create([
            'order_id' => $order->id, 'order_type' => 'payment', 'transaction_type' => 'charge',
            'status' => 'succeeded', 'total' => 10000, 'currency' => 'NGN',
            'payment_method' => 'flutterwave', 'payment_mode' => 'test',
            'vendor_charge_id' => wp_generate_uuid4(), 'meta' => ['flw_ref' => wp_generate_uuid4()],
        ]);
        foreach (['charge_completed', 'refund_completed', 'subscription_cancelled'] as $event) {
            $tag = 'fluent_cart/payments/flutterwave/webhook_' . $event;
            $this->hookSnapshot[$tag] = isset($GLOBALS['wp_filter'][$tag]) ? clone $GLOBALS['wp_filter'][$tag] : null;
            remove_all_actions($tag);
        }
        $this->webhook = new RefundWebhookProbe();
        $this->webhook->init();
    }

    protected function tearDown(): void
    {
        remove_filter('flutterwave_fc/settings', $this->settingsFilter);
        remove_action('fluent_cart/order_refunded', $this->listener);
        remove_action('fluent_cart/payments/flutterwave/webhook_charge_completed', [$this->webhook, 'handleChargeCompleted']);
        remove_action('fluent_cart/payments/flutterwave/webhook_refund_completed', [$this->webhook, 'handleRefundCompleted']);
        remove_action('fluent_cart/payments/flutterwave/webhook_subscription_cancelled', [$this->webhook, 'handleSubscriptionCancelled']);
        foreach ($this->hookSnapshot as $tag => $hook) {
            if ($hook) {
                $GLOBALS['wp_filter'][$tag] = $hook;
            } else {
                unset($GLOBALS['wp_filter'][$tag]);
            }
        }
        $_SERVER = $this->server;
        App::db()->rollBack();
        parent::tearDown();
    }

    public function test_missing_secret_rejects_an_arbitrary_signature(): void
    {
        $this->secret = '';
        unset($_SERVER['HTTP_VERIF_HASH']);
        $_SERVER['HTTP_FLUTTERWAVE_SIGNATURE'] = 'arbitrary';
        $verify = new \ReflectionMethod(FlutterwaveWebhook::class, 'verify');
        $verify->setAccessible(true);

        $this->assertFalse($verify->invoke($this->webhook, '{}'), 'Missing credentials must fail closed.');
        $this->assertSame(0, OrderTransaction::query()->where('order_id', $this->charge->order_id)->where('transaction_type', 'refund')->count(), 'Verification must not record a refund.');
    }

    /**
     * @dataProvider signatureHeaders
     */
    public function test_configured_signatures_are_verified(string $header, bool $valid): void
    {
        unset($_SERVER['HTTP_VERIF_HASH'], $_SERVER['HTTP_FLUTTERWAVE_SIGNATURE']);
        $signature = $header === 'HTTP_VERIF_HASH' ? $this->secret : hash_hmac('sha512', '{}', $this->secret);
        $_SERVER[$header] = $valid ? $signature : 'invalid';
        $verify = new \ReflectionMethod(FlutterwaveWebhook::class, 'verify');
        $verify->setAccessible(true);

        $this->assertSame($valid, $verify->invoke($this->webhook, '{}'), 'Both existing signature formats must enforce the configured secret.');
    }

    public static function signatureHeaders(): array
    {
        return [['HTTP_VERIF_HASH', true], ['HTTP_VERIF_HASH', false], ['HTTP_FLUTTERWAVE_SIGNATURE', true], ['HTTP_FLUTTERWAVE_SIGNATURE', false]];
    }

    /**
     * @dataProvider completedStatuses
     */
    public function test_final_refund_statuses_record_once(string $status): void
    {
        $data = ['payload' => ['id' => 99025, 'transaction_id' => $this->charge->vendor_charge_id, 'amount_refunded' => 19.99, 'status' => $status]];
        $this->assertSame(200, $this->webhook->deliverRefund($data), 'A completed refund must be acknowledged.');
        $this->assertSame(200, $this->webhook->deliverRefund($data), 'A replay must be acknowledged.');
        $rows = OrderTransaction::query()->where('order_id', $this->charge->order_id)->where('transaction_type', 'refund')->get();
        $this->assertCount(1, $rows, 'Replay must not create a second refund.');
        $this->assertSame(1999, (int) $rows[0]->total, 'The full amount must survive decimal conversion.');
        $this->assertSame('refunded', $rows[0]->status, 'The final refund must be settled.');
        $this->assertSame(1999, (int) Order::find($this->charge->order_id)->total_refund, 'Order totals must count the refund once.');
        $this->assertSame(1999, (int) OrderTransaction::find($this->charge->id)->meta['refunded_total'], 'Charge totals must count the refund once.');
        $this->assertSame(1, $this->events, 'Replay must not repeat the refund notification hook.');
    }

    public static function completedStatuses(): array
    {
        return [['completed-bank-transfer'], ['completed-momo'], ['completed-mpgs'], ['completed-offline'], ['completed-preauth'], ['successful']];
    }

    public function test_documented_aliases_reach_the_refund_handler(): void
    {
        $this->assertSame(200, $this->webhook->deliverRefund(['payload' => [
            'id' => 99025, 'TransactionId' => $this->charge->vendor_charge_id, 'AmountRefunded' => 20,
            'FlwRef' => '4687213286', 'comments' => 'Return', 'status' => 'completed-momo',
        ]]), 'The documented fields must be accepted.');
        $this->assertSame(2000, (int) Order::find($this->charge->order_id)->total_refund, 'Aliases must identify the charge and amount.');
        $this->assertSame(1, $this->events, 'The refund must notify once.');
    }

    /**
     * @dataProvider pendingStatuses
     */
    public function test_pending_refunds_count_only_after_completion(string $status): void
    {
        $data = ['payload' => ['id' => 99025, 'transaction_id' => $this->charge->vendor_charge_id, 'amount_refunded' => 20, 'status' => $status]];
        $this->assertSame(200, $this->webhook->deliverRefund($data), 'A pending refund must be acknowledged.');
        $row = OrderTransaction::query()->where('order_id', $this->charge->order_id)->where('transaction_type', 'refund')->first();
        $this->assertNotNull($row, 'Pending refunds must retain their ID for later updates.');
        $this->assertSame('pending', $row->status, 'Initiation must not mark the refund complete.');
        $this->assertSame(0, (int) Order::find($this->charge->order_id)->total_refund, 'Pending amounts must not affect order totals.');
        $this->assertSame(0, $this->events, 'Pending refunds must not notify as completed.');
        $data['payload']['status'] = 'completed-momo';
        $this->webhook->deliverRefund($data);
        $data['payload']['status'] = $status;
        $this->webhook->deliverRefund($data);
        $this->assertSame('refunded', OrderTransaction::find($row->id)->status, 'A late pending callback must not regress completion.');
        $this->assertSame(2000, (int) Order::find($this->charge->order_id)->total_refund, 'Completion must count the original refund.');
        $this->assertSame(1, $this->events, 'Completion must notify exactly once.');
    }

    public static function pendingStatuses(): array
    {
        return [['completed'], ['pending'], ['pending-void'], ['pending-momo'], ['pending-bank-transfer'], ['pending-mpgs'], ['processing']];
    }

    public function test_a_pending_refund_that_fails_does_not_change_totals(): void
    {
        $data = ['payload' => ['id' => 99025, 'transaction_id' => $this->charge->vendor_charge_id, 'amount_refunded' => 20, 'status' => 'completed']];
        $this->webhook->deliverRefund($data);
        $data['payload']['status'] = 'failed';
        $this->assertSame(200, $this->webhook->deliverRefund($data), 'A failed refund must be acknowledged.');
        $row = OrderTransaction::query()->where('order_id', $this->charge->order_id)->where('transaction_type', 'refund')->first();
        $this->assertSame('failed', $row->status, 'The pending row must transition to failed.');
        $this->assertSame(0, (int) Order::find($this->charge->order_id)->total_refund, 'A failed refund must not affect totals.');
        $this->assertSame(0, $this->events, 'A failed refund must not notify as completed.');
    }

    /**
     * @dataProvider unknownStatuses
     */
    public function test_unknown_refund_statuses_do_not_record_money($status): void
    {
        $this->assertSame(200, $this->webhook->deliverRefund(['payload' => [
            'id' => 99025, 'transaction_id' => $this->charge->vendor_charge_id, 'amount_refunded' => 20, 'status' => $status,
        ]]), 'Unknown statuses must be ignored without a server error.');
        $this->assertSame(0, OrderTransaction::query()->where('order_id', $this->charge->order_id)->where('transaction_type', 'refund')->count(), 'Unknown statuses must not create a refund.');
        $this->assertSame(0, $this->events, 'Unknown statuses must not notify.');
    }

    public static function unknownStatuses(): array
    {
        return [['unknown'], [null], [['completed-momo']]];
    }

    /**
     * @dataProvider payloadFormats
     */
    public function test_signed_refund_payloads_reach_the_registered_handler(bool $wrapped): void
    {
        $payload = ['id' => 99025, 'TransactionId' => $this->charge->vendor_charge_id, 'AmountRefunded' => 20, 'FlwRef' => '4687213286', 'status' => 'completed-momo'];
        $body = wp_json_encode($wrapped ? ['event' => 'refund.completed', 'data' => $payload] : $payload);
        unset($_SERVER['HTTP_VERIF_HASH']);
        $_SERVER['HTTP_FLUTTERWAVE_SIGNATURE'] = hash_hmac('sha512', $body, $this->secret);
        $this->assertSame(200, $this->webhook->deliver($body), 'Verify the raw body before normalization.');
        $this->assertSame(200, $this->webhook->deliver($body), 'Replays must reach core safely.');
        $this->assertSame(2000, (int) Order::find($this->charge->order_id)->total_refund, 'The registered handler must update the order.');
        $this->assertSame(1, $this->events, 'Ingress replay must not repeat notification.');
    }

    public static function payloadFormats(): array
    {
        return [[false], [true]];
    }
}
