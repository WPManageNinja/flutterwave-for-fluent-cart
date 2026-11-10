<?php

namespace Tests\Integration;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Hooks\Handlers\GlobalPaymentHandler;
use FlutterwaveFluentCart\FlutterwaveGateway;
use lucatume\WPBrowser\TestCase\WPTestCase;

class FlutterwaveTestConnectionTest extends WPTestCase
{
    private array $requests = [];

    protected function tearDown(): void
    {
        remove_all_filters('pre_http_request');
        remove_all_filters('flutterwave_fc/settings');
        parent::tearDown();
    }

    public function test_gateway_offers_the_connection_test(): void
    {
        $this->assertTrue(GlobalPaymentHandler::canTestConnection(new FlutterwaveGateway()));
    }

    /**
     * @dataProvider modes
     */
    public function test_an_accepted_key_reports_success(string $mode, string $key, string $message): void
    {
        $this->saveKeys([$mode . '_secret_key' => Helper::encryptKey($key)]);
        $this->respondWith(200, ['status' => 'success', 'message' => 'Wallet balances fetched', 'data' => []]);

        $result = (new FlutterwaveGateway())->testConnection($mode);

        $this->assertSame(['message' => $message], $result);
        $this->assertCount(1, $this->requests);
        $this->assertSame('https://api.flutterwave.com/v3/balances', $this->requests[0]['url']);
        $this->assertSame('GET', $this->requests[0]['args']['method']);
        $this->assertSame('Bearer ' . $key, $this->requests[0]['args']['headers']['Authorization']);
    }

    public static function modes(): array
    {
        return [
            'live' => ['live', 'FLWSECK-abc123-X', 'Flutterwave accepted the live secret key.'],
            'test' => ['test', 'FLWSECK_TEST-abc123-X', 'Flutterwave accepted the test secret key.'],
        ];
    }

    public function test_the_tested_mode_key_is_used_not_the_store_mode(): void
    {
        $this->saveKeys([
            'live_secret_key' => Helper::encryptKey('FLWSECK-live-X'),
            'test_secret_key' => Helper::encryptKey('FLWSECK_TEST-test-X'),
        ]);
        $this->respondWith(200, ['status' => 'success', 'data' => []]);

        (new FlutterwaveGateway())->testConnection('test');

        $this->assertSame('Bearer FLWSECK_TEST-test-X', $this->requests[0]['args']['headers']['Authorization']);
    }

    /**
     * @dataProvider missingKeys
     */
    public function test_a_missing_key_fails_without_calling_flutterwave(string $mode, string $message): void
    {
        $this->saveKeys(['live_secret_key' => '', 'test_secret_key' => '']);
        $this->respondWith(200, ['status' => 'success']);

        $result = (new FlutterwaveGateway())->testConnection($mode);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('missing_credentials', $result->get_error_code());
        $this->assertSame($message, $result->get_error_message());
        $this->assertCount(0, $this->requests);
    }

    public static function missingKeys(): array
    {
        return [
            'live' => ['live', 'No live secret key is saved.'],
            'test' => ['test', 'No test secret key is saved.'],
        ];
    }

    public function test_a_rejected_key_returns_flutterwave_message(): void
    {
        $this->saveKeys(['live_secret_key' => Helper::encryptKey('FLWSECK-wrong-X')]);
        $this->respondWith(401, ['status' => 'error', 'message' => 'Invalid authorization key', 'data' => null]);

        $result = (new FlutterwaveGateway())->testConnection('live');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('flutterwave_api_error', $result->get_error_code());
        $this->assertSame('Invalid authorization key', $result->get_error_message());
    }

    public function test_a_non_success_body_returns_flutterwave_message(): void
    {
        $this->saveKeys(['live_secret_key' => Helper::encryptKey('FLWSECK-abc-X')]);
        $this->respondWith(200, ['status' => 'error', 'message' => 'Merchant account is not active']);

        $result = (new FlutterwaveGateway())->testConnection('live');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('Merchant account is not active', $result->get_error_message());
    }

    /**
     * @dataProvider mismatchedKeys
     */
    public function test_a_key_from_the_other_mode_fails_without_calling_flutterwave(string $mode, string $key, string $message): void
    {
        $this->saveKeys([$mode . '_secret_key' => Helper::encryptKey($key)]);
        $this->respondWith(200, ['status' => 'success']);

        $result = (new FlutterwaveGateway())->testConnection($mode);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('mode_mismatch', $result->get_error_code());
        $this->assertSame($message, $result->get_error_message());
        $this->assertCount(0, $this->requests);
    }

    public static function mismatchedKeys(): array
    {
        return [
            'test key in live field' => ['live', 'FLWSECK_TEST-abc-X', 'The live secret key field holds a test key. Paste a key that starts with FLWSECK-.'],
            'live key in test field' => ['test', 'FLWSECK-abc-X', 'The test secret key field holds a live key. Paste a key that starts with FLWSECK_TEST-.'],
        ];
    }

    private function saveKeys(array $keys): void
    {
        add_filter('flutterwave_fc/settings', function ($settings) use ($keys) {
            return array_merge($settings, $keys);
        });
    }

    private function respondWith(int $code, array $body): void
    {
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($code, $body) {
            $this->requests[] = ['url' => $url, 'args' => $args];

            return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => ''], 'cookies' => []];
        }, 10, 3);
    }
}
