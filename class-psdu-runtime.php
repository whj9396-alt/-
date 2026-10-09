<?php

if (!defined('ABSPATH')) {
    exit;
}

final class PSDU_Runtime
{
    public static function init()
    {
        add_action('wp_ajax_psdu_status', array(__CLASS__, 'ajax_status'));
        add_action('wp_ajax_nopriv_psdu_status', array(__CLASS__, 'ajax_status'));
        add_action('psdu_scan_chain', array(__CLASS__, 'cron_scan'));
        add_action('psdu_expire_order', array(__CLASS__, 'expire_order'));
        add_action('admin_post_psdu_scan_now', array(__CLASS__, 'admin_scan_now'));
        add_action('admin_post_psdu_health_check', array(__CLASS__, 'admin_health_check'));

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('psdu scan', array(__CLASS__, 'cli_scan'));
            WP_CLI::add_command('psdu health', array(__CLASS__, 'cli_health'));
        }
    }

    public static function ajax_status()
    {
        $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $order_key = isset($_POST['order_key']) ? wc_clean(wp_unslash($_POST['order_key'])) : '';
        $order = $order_id ? wc_get_order($order_id) : false;

        if (!$order instanceof WC_Order || !self::is_direct_usdt_order($order)) {
            wp_send_json_error(array('message' => PSDU_I18n::t('invalid_order')), 404);
        }
        if ($order_key === '' || !hash_equals((string) $order->get_order_key(), (string) $order_key)) {
            wp_send_json_error(array('message' => PSDU_I18n::t('invalid_order')), 403);
        }

        $network = self::order_network($order);
        $health = PSDU_Scanner::health_status($network);
        $last_run = isset($health['last_run_at']) ? (int) $health['last_run_at'] : 0;
        if (!$order->is_paid() && time() - $last_run >= 10) {
            try {
                PSDU_Scanner::run_now(false, $network);
                $order = wc_get_order($order_id);
            } catch (Throwable $exception) {
                self::log('warning', 'Customer status refresh could not scan the chain.', array(
                    'order_id' => $order_id,
                    'message'  => $exception->getMessage(),
                ));
            }
        }

        $raw_status = (string) $order->get_meta('_psdu_payment_status', true);
        $normalized = $order->is_paid() ? 'paid' : PSDU_Utils::normalize_status($raw_status);
        $expires = (int) $order->get_meta('_psdu_expires_at', true);
        $locale = (string) $order->get_meta('_psdu_locale', true);
        if (!$order->is_paid() && $expires > 0 && time() >= $expires && $normalized === 'pending') {
            $normalized = 'expired';
        }

        $current_health = PSDU_Scanner::health_status($network);
        wp_send_json_success(
            array(
                'status'          => $normalized,
                'chain_status'    => $raw_status,
                'label'           => PSDU_Utils::status_label($normalized, $locale),
                'paid'            => $order->is_paid(),
                'expires_at'      => $expires > 0 ? gmdate('c', $expires) : null,
                'reload'          => $order->is_paid(),
                'scanner_healthy' => !empty($current_health['ok']),
            )
        );
    }

    public static function cron_scan()
    {
        try {
            PSDU_Scanner::run_now(false);
        } catch (Throwable $exception) {
            self::log('error', 'Scheduled direct USDT scan failed.', array('message' => $exception->getMessage()));
        }
    }

    public static function schedule_expiry($order_id, $timestamp)
    {
        $when = max(time() + 60, absint($timestamp) + 60);
        if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_single_action')) {
            if (!as_next_scheduled_action('psdu_expire_order', array(absint($order_id)), 'partsyhub-direct-usdt')) {
                as_schedule_single_action($when, 'psdu_expire_order', array(absint($order_id)), 'partsyhub-direct-usdt');
            }
            return;
        }

        if (!wp_next_scheduled('psdu_expire_order', array(absint($order_id)))) {
            wp_schedule_single_event($when, 'psdu_expire_order', array(absint($order_id)));
        }
    }

    public static function expire_order($order_id)
    {
        $order = wc_get_order(absint($order_id));
        if (!$order instanceof WC_Order || $order->is_paid() || !self::is_direct_usdt_order($order)) {
            return;
        }
        try {
            PSDU_Scanner::run_now(false, self::order_network($order));
        } catch (Throwable $exception) {
            self::log('error', 'Expiry scan failed.', array('order_id' => $order->get_id(), 'message' => $exception->getMessage()));
        }
    }

    public static function admin_scan_now()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to manage payments.', 'partsyhub-direct-usdt'));
        }
        check_admin_referer('psdu_scan_now');
        $result = 'success';
        try {
            $scan = PSDU_Scanner::run_now(true);
            if (isset($scan['ok']) && !$scan['ok']) {
                $result = 'error';
            }
        } catch (Throwable $exception) {
            $result = 'error';
        }
        wp_safe_redirect(add_query_arg('psdu_scan', $result, admin_url('admin.php?page=psdu-monitor')));
        exit;
    }

    public static function admin_health_check()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to manage payments.', 'partsyhub-direct-usdt'));
        }
        check_admin_referer('psdu_health_check');
        $result = 'success';
        foreach (array(PSDU_Utils::NETWORK_TRC20, PSDU_Utils::NETWORK_ERC20) as $network) {
            $settings = PSDU_Scanner::settings($network);
            $address = isset($settings['receive_address']) ? $settings['receive_address'] : '';
            if (!PSDU_Utils::validate_receive_address($network, $address)) {
                continue;
            }
            $option = $network === PSDU_Utils::NETWORK_ERC20 ? 'psdu_node_health_erc20' : 'psdu_node_health';
            try {
                $health = PSDU_Scanner::health_check($settings, $network);
                $health['ok'] = true;
                update_option($option, $health, false);
            } catch (Throwable $exception) {
                $result = 'error';
                update_option(
                    $option,
                    array('ok' => false, 'checked_at' => time(), 'error' => sanitize_text_field($exception->getMessage())),
                    false
                );
            }
        }
        wp_safe_redirect(add_query_arg('psdu_health', $result, admin_url('admin.php?page=psdu-monitor')));
        exit;
    }

    public static function cli_scan($args, $assoc_args)
    {
        try {
            $result = PSDU_Scanner::run_now(true);
            if (isset($result['ok']) && !$result['ok']) {
                WP_CLI::error(wp_json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return;
            }
            WP_CLI::success(wp_json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (Throwable $exception) {
            WP_CLI::error($exception->getMessage());
        }
    }

    public static function cli_health($args, $assoc_args)
    {
        try {
            $result = array();
            foreach (array(PSDU_Utils::NETWORK_TRC20, PSDU_Utils::NETWORK_ERC20) as $network) {
                $settings = PSDU_Scanner::settings($network);
                $address = isset($settings['receive_address']) ? $settings['receive_address'] : '';
                if (PSDU_Utils::validate_receive_address($network, $address)) {
                    $result[$network] = PSDU_Scanner::health_check($settings, $network);
                }
            }
            WP_CLI::success(wp_json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (Throwable $exception) {
            WP_CLI::error($exception->getMessage());
        }
    }

    private static function log($level, $message, array $context = array())
    {
        $context['source'] = 'partsyhub-direct-usdt';
        wc_get_logger()->log($level, $message, $context);
    }

    private static function is_direct_usdt_order(WC_Order $order)
    {
        return in_array($order->get_payment_method(), array(PSDU_GATEWAY_ID, PSDU_ERC20_GATEWAY_ID), true);
    }

    private static function order_network(WC_Order $order)
    {
        $stored = (string) $order->get_meta('_psdu_network', true);
        if ($stored !== '') {
            return PSDU_Utils::normalize_network($stored);
        }
        return $order->get_payment_method() === PSDU_ERC20_GATEWAY_ID
            ? PSDU_Utils::NETWORK_ERC20
            : PSDU_Utils::NETWORK_TRC20;
    }
}
