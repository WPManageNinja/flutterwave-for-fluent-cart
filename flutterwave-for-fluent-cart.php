<?php
/**
 * Plugin Name: Flutterwave for FluentCart
 * Plugin URI: https://fluentcart.com
 * Description: Accept payments via Flutterwave in FluentCart - supports one-time payments, subscriptions, and automatic refunds via webhooks.
 * Version: 1.0.0
 * Author: FluentCart
 * Author URI: https://fluentcart.com
 * Text Domain: flutterwave-for-fluent-cart
 * Domain Path: /languages
 * Requires at least: 5.6
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
defined('ABSPATH') || exit('Direct access not allowed.');

// Define plugin constants
define('FLUTTERWAVE_FCT_VERSION', '1.0.0');
define('FLUTTERWAVE_FCT_PLUGIN_FILE', __FILE__);
define('FLUTTERWAVE_FCT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FLUTTERWAVE_FCT_PLUGIN_URL', plugin_dir_url(__FILE__));


function flutterwave_fc_check_dependencies() {
    if (!defined('FLUENTCART_VERSION')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('Flutterwave for FluentCart', 'flutterwave-for-fluent-cart'); ?></strong> 
                    <?php esc_html_e('requires FluentCart to be installed and activated.', 'flutterwave-for-fluent-cart'); ?>
                </p>
            </div>
            <?php
        });
        return false;
    }
    
    if (version_compare(FLUENTCART_VERSION, '1.2.5', '<')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('Flutterwave for FluentCart', 'flutterwave-for-fluent-cart'); ?></strong> 
                    <?php esc_html_e('requires FluentCart version 1.2.5 or higher', 'flutterwave-for-fluent-cart'); ?>
                </p>
            </div>
            <?php
        });
        return false;
    }
    
    return true;
}


add_action('plugins_loaded', function() {
    if (!flutterwave_fc_check_dependencies()) {
        return;
    }

    spl_autoload_register(function ($class) {
        $prefix = 'FlutterwaveFluentCart\\';
        $base_dir = FLUTTERWAVE_FCT_PLUGIN_DIR . 'includes/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    });

    add_action('fluent_cart/register_payment_methods', function($data) {
        \FlutterwaveFluentCart\FlutterwaveGateway::register();
    }, 10);

    /**
     * Plugin Updater
     */
    $apiUrl = 'https://fluentcart.com/wp-admin/admin-ajax.php?action=fluent_cart_addon_update&time=' . time();
    new \FlutterwaveFluentCart\PluginManager\Updater($apiUrl, FLUTTERWAVE_FCT_PLUGIN_FILE, array(
        'version'   => FLUTTERWAVE_FCT_VERSION,
        'license'   => '',
        'item_name' => 'Flutterwave for FluentCart',
        'item_id'   => 'flutterwave-for-fluent-cart',
        'author'    => 'wpmanageninja'
    ),
        array(
            'license_status' => 'valid',
            'admin_page_url' => admin_url('admin.php?page=fluent-cart#/'),
            'purchase_url'   => 'https://fluentcart.com',
            'plugin_title'   => 'Flutterwave for FluentCart'
        )
    );

    add_filter('plugin_row_meta', function ($links, $pluginFile) {
        if (plugin_basename(FLUTTERWAVE_FCT_PLUGIN_FILE) !== $pluginFile) {
            return $links;
        }

        $checkUpdateUrl = esc_url(admin_url('plugins.php?flutterwave-for-fluent-cart-check-update=' . time()));

        $row_meta = array(
            'check_update' => '<a style="color: #583fad;font-weight: 600;" href="' . $checkUpdateUrl . '" aria-label="' . esc_attr__('Check Update', 'flutterwave-for-fluent-cart') . '">' . esc_html__('Check Update', 'flutterwave-for-fluent-cart') . '</a>',
        );

        return array_merge($links, $row_meta);
    }, 10, 2);

}, 20);


register_activation_hook(__FILE__, 'flutterwave_fc_on_activation');

/**
 * Plugin activation callback
 */
function flutterwave_fc_on_activation() {
    if (!flutterwave_fc_check_dependencies()) {
        wp_die(
            esc_html__('Flutterwave for FluentCart requires FluentCart to be installed and activated.', 'flutterwave-for-fluent-cart'),
            esc_html__('Plugin Activation Error', 'flutterwave-for-fluent-cart'),
            ['back_link' => true]
        );
    }
    
    // Clear any relevant caches
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
}
