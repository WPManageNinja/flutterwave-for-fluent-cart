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
        $logoLight = FLUTTERWAVE_FCT_PLUGIN_URL . 'assets/images/flutterwave-logo-light.svg';

        $addonStatus = PaymentAddonManager::getAddonStatus($this->addonSlug, $this->addonFile);

        return [
            'title'              => __('Flutterwave', 'flutterwave-for-fluent-cart'),
            'route'              => $this->methodSlug,
            'slug'               => $this->methodSlug,
            'label'              => 'Flutterwave',
            'admin_title'        => 'Flutterwave',
            'description'        => __('Pay securely with Flutterwave - Card, Bank Transfer, Mobile Money, and more', 'flutterwave-for-fluent-cart'),
            'logo'               => $logo,
            'logo_light'         => $logoLight,
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
        $baseUrl = 'https://app.flutterwave.com/dashboard/payments/plans/details/';

        if (!$subscription || !$subscription->vendor_plan_id) {
            return $baseUrl;
        }

        return $baseUrl . $subscription->vendor_plan_id;
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

    public function getWebhookInstructions(): array
    {
        $webhook_url = site_url('?fluent-cart=fct_payment_listener_ipn&method=flutterwave');
        $configureLink = 'https://app.flutterwave.com/dashboard/settings/webhooks/live';

        $svg    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M10 6V8H5V19H16V14H18V20C18 20.5523 17.5523 21 17 21H4C3.44772 21 3 20.5523 3 20V7C3 6.44772 3.44772 6 4 6H10ZM21 3V11H19L18.9999 6.413L11.2071 14.2071L9.79289 12.7929L17.5849 5H13V3H21Z"></path></svg>';

        /* translators: %1$s: "Flutterwave Webhook Settings Page " link with icon */
        $step = fn($url) => \sprintf(
            '<p>%s</p>',
            \sprintf(
                __('Click %1$s', 'flutterwave-for-fluent-cart'),
                \sprintf('<a href="%s" target="_blank">%s %s</a>', $url, __('Flutterwave Webhook Settings Page ', 'flutterwave-for-fluent-cart'), $svg)
            )
        );

        return [
            'title'       => __('Webhook URL', 'flutterwave-for-fluent-cart'),
            'webhook_url' => esc_html($webhook_url),
            'description' => __('You should configure your webhook URL in your Flutterwave Dashboard.', 'flutterwave-for-fluent-cart'),
            'steps'       => [
                'title' => __('How to configure?', 'flutterwave-for-fluent-cart'),
                'list'  => [
                    __('In your Flutterwave Dashboard under Settings &rarr; Webhooks', 'flutterwave-for-fluent-cart'),
                    $step(esc_url($configureLink)),
                ],
            ],
            'webhook_notice' => [
                'title' => __('Important: Server Configuration Required', 'flutterwave-for-fluent-cart'),
                'description'  => __('To ensure webhooks are delivered successfully, you may need to whitelist Flutterwave on your server.', 'flutterwave-for-fluent-cart'),
                'list' => [
                    __('Ensure your server firewall or security plugins allow incoming requests from Flutterwave', 'flutterwave-for-fluent-cart'),
                    __("If using a WAF (Web Application Firewall) or security plugin, whitelist Flutterwave's webhook domain", 'flutterwave-for-fluent-cart'),
                    __("Check your server error logs if webhooks are failing to reach your site", 'flutterwave-for-fluent-cart'),
                ]
            ]
        ];
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

        $data[$mode . '_secret_key'] = Helper::encryptKey(Arr::get($data, $mode . '_secret_key'));

        return $data;
    }

    public static function register(): void
    {
        fluent_cart_api()->registerCustomPaymentMethod('flutterwave', new self());
    }
}
