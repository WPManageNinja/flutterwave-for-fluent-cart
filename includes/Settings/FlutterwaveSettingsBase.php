<?php
/**
 * Flutterwave Settings Base Class
 *
 * @package FlutterwaveFluentCart
 * @since 1.0.0
 */

namespace FlutterwaveFluentCart\Settings;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

if (!defined('ABSPATH')) {
    exit;
}

class FlutterwaveSettingsBase extends BaseGatewaySettings
{
    public $settings;
    public $methodHandler = 'fluent_cart_payment_settings_flutterwave';

    public function __construct()
    {
        parent::__construct();
        $settings = $this->getCachedSettings();
        $defaults = static::getDefaults();

        if (!$settings || !is_array($settings) || empty($settings)) {
            $settings = $defaults;
        } else {
            $settings = wp_parse_args($settings, $defaults);
        }

        $this->settings = apply_filters('flutterwave_fc/settings', $settings);
    }

    public static function getDefaults()
    {
        return [
            'is_active'        => 'no',
            'test_public_key'  => '',
            'test_secret_key'  => '',
            'live_public_key'  => '',
            'live_secret_key'  => '',
            'test_webhook_secret_hash'   => '',
            'live_webhook_secret_hash'   => '',
            'payment_mode'     => 'live',
        ];
    }

    public function isActive(): bool
    {
        return $this->settings['is_active'] == 'yes';
    }

    public function get($key = '')
    {
        $settings = $this->settings;

        if ($key && isset($this->settings[$key])) {
            return $this->settings[$key];
        }
        return $settings;
    }

    public function getMode()
    {
        return (new StoreSettings)->get('order_mode');
    }

    public function getSecretKey($mode = 'current')
    {
        if ($mode == 'current' || !$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            $secretKey = $this->get('test_secret_key');
        } else {
            $secretKey = $this->get('live_secret_key');
        }

        return Helper::decryptKey($secretKey);
    }

    public function getPublicKey($mode = 'current')
    {
        if ($mode == 'current' || !$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            return $this->get('test_public_key');
        } else {
            return $this->get('live_public_key');
        }
    }

    public function getWebhookSecretHash($mode = 'current')
    {
        if ($mode == 'current' || !$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            return $this->get('test_webhook_secret_hash');
        } else {
            return $this->get('live_webhook_secret_hash');
        }
    }
}
