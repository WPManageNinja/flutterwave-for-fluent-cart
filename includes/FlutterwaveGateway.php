<?php
/**
 * Flutterwave Gateway Class
 *
 * @package FlutterwaveFluentCart
 * @since 1.0.0
 */

namespace FlutterwaveFluentCart;

if (!defined('ABSPATH')) {
    exit;
}

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\PluginInstaller\PaymentAddonManager;
use FlutterwaveFluentCart\Settings\FlutterwaveSettingsBase;
use FlutterwaveFluentCart\Subscriptions\FlutterwaveSubscriptions;
use FlutterwaveFluentCart\Refund\FlutterwaveRefund;

class FlutterwaveGateway extends AbstractPaymentGateway
{
    private $methodSlug = 'flutterwave';
    private $addonSlug = 'flutterwave-for-fluent-cart';
    private $addonFile = 'flutterwave-for-fluent-cart/flutterwave-for-fluent-cart.php';

    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook',
        'subscriptions'
    ];

    public function __construct()
    {
        parent::__construct(
            new FlutterwaveSettingsBase(),
            new FlutterwaveSubscriptions()
        );

        add_filter('fluent_cart/payment_methods_with_custom_checkout_buttons', function ($methods) {
            $methods[] = 'flutterwave';
            return $methods;
        });
    }

    public function meta(): array
    {
        $logo = FLUTTERWAVE_FCT_PLUGIN_URL . 'assets/images/flutterwave-logo.svg';

        $addonStatus = PaymentAddonManager::getAddonStatus($this->addonSlug, $this->addonFile);

        return [
            'title'              => __('Flutterwave', 'flutterwave-for-fluent-cart'),
            'route'              => $this->methodSlug,
            'slug'               => $this->methodSlug,
            'label'              => 'Flutterwave',
            'admin_title'        => 'Flutterwave',
            'description'        => __('Pay securely with Flutterwave - Card, Bank Transfer, Mobile Money, and more', 'flutterwave-for-fluent-cart'),
            'logo'               => $logo,
            'icon'               => $logo,
            'brand_color'        => '#F5A623',
            'tag'                => 'beta',
            'status'             => $this->settings->get('is_active') === 'yes',
            'upcoming'           => false,
            'is_addon'           => true,
            'addon_source'       => [
                'type' => 'github',
                'link' => 'https://github.com/WPManageNinja/flutterwave-for-fluent-cart/releases/latest',
                'slug' => $this->addonSlug,
                'file' => $this->addonFile,
                'is_installed' => true
            ],
            'addon_status' => $addonStatus,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function boot()
    {
        (new Webhook\FlutterwaveWebhook())->init();
        
        add_filter('fluent_cart/payment_methods/flutterwave_settings', [$this, 'getSettings'], 10, 2);

        (new Confirmations\FlutterwaveConfirmations())->init();
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $paymentArgs = [
            'success_url' => $this->getSuccessUrl($paymentInstance->transaction),
            'cancel_url'  => $this->getCancelUrl(),
        ];

        if ($paymentInstance->subscription) {
            return (new Subscriptions\FlutterwaveSubscriptions())->handleSubscription($paymentInstance, $paymentArgs);
        }

        return (new Onetime\FlutterwaveProcessor())->handleSinglePayment($paymentInstance, $paymentArgs);
    }

    public function getOrderInfo($data)
    {
        FlutterwaveHelper::checkCurrencySupport();

        $publicKey = (new Settings\FlutterwaveSettingsBase())->getPublicKey();

        wp_send_json([
            'status'       => 'success',
            'message'      => __('Order info retrieved!', 'flutterwave-for-fluent-cart'),
            'payment_args' => [
                'public_key' => $publicKey
            ],
        ], 200);
    }

    public function handleIPN(): void
    {
        (new Webhook\FlutterwaveWebhook())->verifyAndProcess();
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle' => 'flutterwave-fluent-cart-checkout-handler',
                'src'    => FLUTTERWAVE_FCT_PLUGIN_URL . 'assets/flutterwave-checkout.js',
                'version' => FLUTTERWAVE_FCT_VERSION
            ]
        ];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [
            [
                'handle' => 'flutterwave-fluent-cart-checkout-styles',
                'src'    => FLUTTERWAVE_FCT_PLUGIN_URL . 'assets/flutterwave-checkout.css',
                'version' => FLUTTERWAVE_FCT_VERSION
            ]
        ];
    }

    public function getLocalizeData(): array
    {
        return [
            'fct_flutterwave_data' => [
                'public_key' => $this->settings->getPublicKey(),
                'translations' => [
                    'Processing payment...' => __('Processing payment...', 'flutterwave-for-fluent-cart'),
                    'Pay Now' => __('Pay Now', 'flutterwave-for-fluent-cart'),
                    'Place Order' => __('Place Order', 'flutterwave-for-fluent-cart'),
                ],
                'nonce' => wp_create_nonce('flutterwave_fct_nonce')
            ]
        ];
    }

    public function webHookPaymentMethodName()
    {
        return $this->getMeta('route');
    }

    public function getTransactionUrl($url, $data): string
    {
        $transaction = Arr::get($data, 'transaction', null);
        // Flutterwave uses same dashboard for test/live; use sandbox base if they add one later.
        $baseUrl = 'https://app.flutterwave.com/dashboard/transactions/list/';

        if (!$transaction) {
            return $baseUrl;
        }

        $paymentId = $transaction->vendor_charge_id;

        if ($transaction->status === Status::TRANSACTION_REFUNDED) {
            $parentTransaction = OrderTransaction::query()
                ->where('id', Arr::get($transaction->meta, 'parent_id'))
                ->first();
            if ($parentTransaction) {
                $paymentId = $parentTransaction->vendor_charge_id;
            } else {
                return $baseUrl;
            }
        }

        return $baseUrl . $paymentId;
    }

    public function getSubscriptionUrl($url, $data): string
    {
        $subscription = Arr::get($data, 'subscription', null);
        $baseUrl = 'https://app.flutterwave.com/dashboard/subscriptions/list/';

        if (!$subscription || !$subscription->vendor_subscription_id) {
            return $baseUrl;
        }

        return $baseUrl . $subscription->vendor_subscription_id;
    }

    public function processRefund($transaction, $amount, $args)
    {
        if (!$amount) {
            return new \WP_Error(
                'flutterwave_refund_error',
                __('Refund amount is required.', 'flutterwave-for-fluent-cart')
            );
        }

        return (new FlutterwaveRefund())->processRemoteRefund($transaction, $amount, $args);
    }

    public function getWebhookInstructions(): string
    {
        $webhook_url = site_url('?fluent-cart=fct_payment_listener_ipn&method=flutterwave');
        $configureLink = 'https://dashboard.flutterwave.com/settings/webhooks';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped below with esc_html and esc_url
        return sprintf(
            '<div>
                <p><b>%s</b><code class="copyable-content">%s</code></p>
                <p>%s</p>
            </div>',
            esc_html__('Webhook URL: ', 'flutterwave-for-fluent-cart'),
            esc_html($webhook_url),
            wp_kses(
                sprintf(
                    /* translators: %1$s: URL to Flutterwave webhook settings, %2$s: Link text */
                    __('Configure this webhook URL in your Flutterwave Dashboard under Settings > Webhooks. You can access the <a href="%1$s" target="_blank">%2$s</a> here.', 'flutterwave-for-fluent-cart'),
                    esc_url($configureLink),
                    esc_html__('Flutterwave Webhook Settings Page', 'flutterwave-for-fluent-cart')
                ),
                [
                    'a' => [
                        'href'   => [],
                        'target' => [],
                    ],
                ]
            )
        );
    }

    public function fields(): array
    {
        return [
            'notice' => [
                'value' => $this->renderStoreModeNotice(),
                'label' => __('Store Mode notice', 'flutterwave-for-fluent-cart'),
                'type'  => 'notice'
            ],
            'payment_mode' => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Live credentials', 'flutterwave-for-fluent-cart'),
                        'value'  => 'live',
                        'schema' => [
                            'live_public_key' => [
                                'value'       => '',
                                'label'       => __('Live Public Key', 'flutterwave-for-fluent-cart'),
                                'type'        => 'text',
                                'placeholder' => __('FLWPUBK-xxxxxxxxxxxxxxxx-X', 'flutterwave-for-fluent-cart'),
                            ],
                            'live_secret_key' => [
                                'value'       => '',
                                'label'       => __('Live Secret Key', 'flutterwave-for-fluent-cart'),
                                'type'        => 'password',
                                'placeholder' => __('FLWSECK-xxxxxxxxxxxxxxxx-X', 'flutterwave-for-fluent-cart'),
                            ],
                            'live_webhook_secret_hash' => [
                                'value'       => '',
                                'label'       => __('Live Webhook Secret Hash', 'flutterwave-for-fluent-cart'),
                                'type'        => 'password',
                                'placeholder' => __('Your live webhook secret hash', 'flutterwave-for-fluent-cart'),
                            ],
                        ]
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Test credentials', 'flutterwave-for-fluent-cart'),
                        'value'  => 'test',
                        'schema' => [
                            'test_public_key' => [
                                'value'       => '',
                                'label'       => __('Test Public Key', 'flutterwave-for-fluent-cart'),
                                'type'        => 'text',
                                'placeholder' => __('FLWPUBK_TEST-xxxxxxxxxxxxxxxx-X', 'flutterwave-for-fluent-cart'),
                            ],
                            'test_secret_key' => [
                                'value'       => '',
                                'label'       => __('Test Secret Key', 'flutterwave-for-fluent-cart'),
                                'type'        => 'password',
                                'placeholder' => __('FLWSECK_TEST-xxxxxxxxxxxxxxxx-X', 'flutterwave-for-fluent-cart'),
                            ],
                            'test_webhook_secret_hash' => [
                                'value'       => '',
                                'label'       => __('Test Webhook Secret Hash', 'flutterwave-for-fluent-cart'),
                                'type'        => 'password',
                                'placeholder' => __('Your test webhook secret hash', 'flutterwave-for-fluent-cart'),
                            ],
                        ],
                    ],
                ]
            ],
            'webhook_info' => [
                'value' => $this->getWebhookInstructions(),
                'label' => __('Webhook Configuration', 'flutterwave-for-fluent-cart'),
                'type'  => 'html_attr'
            ],
        ];
    }

    public static function validateSettings($data): array
    {
        return $data;
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        $mode = Arr::get($data, 'payment_mode', 'test');

        if ($mode == 'test') {
            $data['test_secret_key'] = Helper::encryptKey($data['test_secret_key']);
        } else {
            $data['live_secret_key'] = Helper::encryptKey($data['live_secret_key']);
        }

        return $data;
    }

    public static function register(): void
    {
        fluent_cart_api()->registerCustomPaymentMethod('flutterwave', new self());
    }
}
