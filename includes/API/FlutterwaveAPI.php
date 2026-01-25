<?php
/**
 * Flutterwave API Handler
 *
 * @package FlutterwaveFluentCart
 * @since 1.0.0
 */

namespace FlutterwaveFluentCart\API;

use FlutterwaveFluentCart\Settings\FlutterwaveSettingsBase;

if (!defined('ABSPATH')) {
    exit;
}

class FlutterwaveAPI
{
    private static $baseUrl = 'https://api.flutterwave.com/v3/';
    private static $settings = null;

    /**
     * Get settings instance
     */
    public static function getSettings()
    {
        if (!self::$settings) {
            self::$settings = new FlutterwaveSettingsBase();
        }
        return self::$settings;
    }

    private static function request($endpoint, $method = 'GET', $data = [])
    {
        if (empty($endpoint) || !is_string($endpoint)) {
            return new \WP_Error('invalid_endpoint', 'Invalid API endpoint provided');
        }

        $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE'];
        if (!in_array(strtoupper($method), $allowedMethods, true)) {
            return new \WP_Error('invalid_method', 'Invalid HTTP method');
        }

        $url = self::$baseUrl . $endpoint;
        $secretKey = self::getSettings()->getSecretKey();

        if (!$secretKey) {
            return new \WP_Error('missing_api_key', 'Flutterwave API key is not configured');
        }

        $args = [
            'method'    => strtoupper($method),
            'headers'   => [
                'Authorization' => 'Bearer ' . sanitize_text_field($secretKey),
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'FlutterwaveFluentCart/1.0.0 WordPress/' . get_bloginfo('version'),
            ],
            'timeout'   => 30,
            'sslverify' => true,
        ];

        if (in_array($method, ['POST', 'PUT']) && !empty($data)) {
            $args['body'] = wp_json_encode($data);
        }

        if ($method === 'GET' && !empty($data)) {
            $url = add_query_arg($data, $url);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        $statusCode = wp_remote_retrieve_response_code($response);
        
        if ($statusCode >= 400) {
            return new \WP_Error(
                'flutterwave_api_error',
                $decoded['message'] ?? 'Unknown Flutterwave API error',
                ['status' => $statusCode, 'response' => $decoded]
            );
        }

        return $decoded;
    }

    public static function getFlutterwaveObject($endpoint, $params = [])
    {
        return self::request($endpoint, 'GET', $params);
    }

    public static function createFlutterwaveObject($endpoint, $data = [])
    {
        return self::request($endpoint, 'POST', $data);
    }

    public static function updateFlutterwaveObject($endpoint, $data = [])
    {
        return self::request($endpoint, 'PUT', $data);
    }

    public static function deleteFlutterwaveObject($endpoint, $data = [])
    {
        return self::request($endpoint, 'DELETE', $data);
    }

    /**
     * Initialize a payment
     */
    public static function initializePayment($data)
    {
        return self::createFlutterwaveObject('payments', $data);
    }

    /**
     * Verify a transaction by ID
     */
    public static function verifyTransaction($transactionId)
    {
        return self::getFlutterwaveObject('transactions/' . $transactionId . '/verify');
    }

    /**
     * Verify a transaction by reference
     */
    public static function verifyTransactionByReference($txRef)
    {
        return self::getFlutterwaveObject('transactions/verify_by_reference', ['tx_ref' => $txRef]);
    }

    /**
     * Create a refund
     */
    public static function createRefund($transactionId, $amount = null)
    {
        $data = [];
        if ($amount) {
            $data['amount'] = $amount;
        }
        return self::createFlutterwaveObject('transactions/' . $transactionId . '/refund', $data);
    }

    /**
     * Create a payment plan (for subscriptions)
     */
    public static function createPaymentPlan($data)
    {
        return self::createFlutterwaveObject('payment-plans', $data);
    }

    /**
     * Get a payment plan
     */
    public static function getPaymentPlan($planId)
    {
        return self::getFlutterwaveObject('payment-plans/' . $planId);
    }

    /**
     * Get subscription details
     */
    public static function getSubscription($subscriptionId)
    {
        return self::getFlutterwaveObject('subscriptions/' . $subscriptionId);
    }

    /**
     * Get all subscriptions for a transaction/email
     */
    public static function getSubscriptions($params = [])
    {
        return self::getFlutterwaveObject('subscriptions', $params);
    }
}
