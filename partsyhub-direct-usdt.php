<?php
/**
 * Plugin Name: HAOJ1E Multi-Site Direct USDT
 * Description: Direct USDT payments on TRON and Ethereum with finalized on-chain verification for WooCommerce.
 * Version:     1.2.0
 * Author:      HAOJ1E
 * Text Domain: partsyhub-direct-usdt
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 11.0
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PSDU_VERSION', '1.2.0');
define('PSDU_GATEWAY_ID', 'partsyhub_direct_usdt');
define('PSDU_ERC20_GATEWAY_ID', 'haoj1e_direct_usdt_erc20');
define('PSDU_FILE', __FILE__);
define('PSDU_PATH', plugin_dir_path(__FILE__));
define('PSDU_URL', plugin_dir_url(__FILE__));

add_filter('cron_schedules', 'psdu_add_cron_schedule');
function psdu_add_cron_schedule($schedules)
{
    if (!isset($schedules['psdu_every_minute'])) {
        $schedules['psdu_every_minute'] = array(
            'interval' => MINUTE_IN_SECONDS,
            'display'  => 'Every minute (HAOJ1E Direct USDT)',
        );
    }
    return $schedules;
}

add_action('before_woocommerce_init', static function () {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
        Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
    }
});

add_action('plugins_loaded', 'psdu_bootstrap', 20);
function psdu_bootstrap()
{
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', 'psdu_missing_woocommerce_notice');
        return;
    }

    require_once PSDU_PATH . 'includes/class-psdu-i18n.php';
    require_once PSDU_PATH . 'includes/class-psdu-utils.php';
    require_once PSDU_PATH . 'includes/class-psdu-rpc-client.php';
    require_once PSDU_PATH . 'includes/class-psdu-store.php';
    require_once PSDU_PATH . 'includes/class-psdu-scanner.php';
    require_once PSDU_PATH . 'includes/class-psdu-gateway.php';
    require_once PSDU_PATH . 'includes/class-psdu-runtime.php';
    require_once PSDU_PATH . 'includes/class-psdu-renderer.php';
    require_once PSDU_PATH . 'includes/class-psdu-admin.php';

    if ((string) get_option('psdu_db_version', '') !== PSDU_VERSION) {
        PSDU_Store::install();
    }

    add_filter('woocommerce_payment_gateways', static function ($gateways) {
        $gateways[] = 'PSDU_Gateway';
        $gateways[] = 'PSDU_Ethereum_Gateway';
        return $gateways;
    });

    PSDU_Runtime::init();
    PSDU_Renderer::init();
    PSDU_Admin::init();
    psdu_ensure_scan_schedule();

    add_action('woocommerce_blocks_loaded', 'psdu_register_blocks_support');
}

function psdu_register_blocks_support()
{
    if (!class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) {
        return;
    }

    require_once PSDU_PATH . 'includes/class-psdu-blocks.php';
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        static function ($registry) {
            $registry->register(new PSDU_Blocks());
            $registry->register(new PSDU_Ethereum_Blocks());
        }
    );
}

function psdu_ensure_scan_schedule()
{
    if (!wp_next_scheduled('psdu_scan_chain')) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'psdu_every_minute', 'psdu_scan_chain');
    }
}

function psdu_missing_woocommerce_notice()
{
    if (!current_user_can('activate_plugins')) {
        return;
    }

    echo '<div class="notice notice-error"><p>';
    echo esc_html__('HAOJ1E Multi-Site Direct USDT requires WooCommerce to be active.', 'partsyhub-direct-usdt');
    echo '</p></div>';
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), static function ($links) {
    $trc20_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . PSDU_GATEWAY_ID);
    $erc20_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . PSDU_ERC20_GATEWAY_ID);
    $monitor_url = admin_url('admin.php?page=psdu-monitor');
    array_unshift(
        $links,
        '<a href="' . esc_url($trc20_url) . '">' . esc_html__('TRC20 Settings', 'partsyhub-direct-usdt') . '</a>',
        '<a href="' . esc_url($erc20_url) . '">' . esc_html__('ERC20 Settings', 'partsyhub-direct-usdt') . '</a>',
        '<a href="' . esc_url($monitor_url) . '">' . esc_html__('Monitor', 'partsyhub-direct-usdt') . '</a>'
    );
    return $links;
});

add_filter('woocommerce_currencies', static function ($currencies) {
    $currencies['USDT'] = 'Tether USD (USDT)';
    return $currencies;
});

add_filter('woocommerce_currency_symbol', static function ($symbol, $currency) {
    return strtoupper((string) $currency) === 'USDT' ? 'USDT' : $symbol;
}, 10, 2);

add_filter('wc_get_price_decimals', static function ($decimals) {
    return function_exists('get_woocommerce_currency') && get_woocommerce_currency() === 'USDT'
        ? 6
        : $decimals;
});

register_activation_hook(__FILE__, 'psdu_activate');
function psdu_activate()
{
    require_once PSDU_PATH . 'includes/class-psdu-store.php';
    PSDU_Store::install();
    psdu_ensure_scan_schedule();
}

register_deactivation_hook(__FILE__, 'psdu_deactivate');
function psdu_deactivate()
{
    wp_clear_scheduled_hook('psdu_scan_chain');
}
