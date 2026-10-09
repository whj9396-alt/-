<?php

if (!defined('ABSPATH')) {
    exit;
}

final class PSDU_Renderer
{
    private static $rendered_orders = array();

    public static function init()
    {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('woocommerce_thankyou_' . PSDU_GATEWAY_ID, array(__CLASS__, 'render_by_id'), 8);
        add_action('woocommerce_thankyou_' . PSDU_ERC20_GATEWAY_ID, array(__CLASS__, 'render_by_id'), 8);
        add_action('woocommerce_order_details_before_order_table', array(__CLASS__, 'render_by_order'), 8);
        add_action('woocommerce_admin_order_data_after_payment_info', array(__CLASS__, 'render_admin_payment_info'));
    }

    public static function enqueue_assets()
    {
        if (!function_exists('is_checkout')) {
            return;
        }

        $is_payment_surface = is_checkout()
            || (function_exists('is_account_page') && is_account_page())
            || (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received'))
            || (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('view-order'));
        if (!$is_payment_surface) {
            return;
        }

        wp_enqueue_style('psdu-checkout', PSDU_URL . 'assets/css/checkout.css', array(), PSDU_VERSION);
        $settings = PSDU_Scanner::settings();
        $color = isset($settings['brand_color']) ? sanitize_hex_color($settings['brand_color']) : '';
        $color = $color ? $color : '#0787dc';
        wp_add_inline_style(
            'psdu-checkout',
            ':root{--psdu-blue:' . $color . ';--psdu-blue-dark:' . $color . ';}'
        );

        wp_enqueue_script(
            'psdu-qrcode',
            PSDU_URL . 'assets/vendor/qrcodejs/qrcode.min.js',
            array(),
            '1.0.0',
            true
        );
        wp_enqueue_script(
            'psdu-payment-page',
            PSDU_URL . 'assets/js/payment-page.js',
            array('psdu-qrcode'),
            PSDU_VERSION,
            true
        );

        $locale = PSDU_I18n::locale();
        wp_localize_script(
            'psdu-payment-page',
            'psduPayment',
            array(
                'ajaxUrl'       => admin_url('admin-ajax.php'),
                'copyLabel'     => PSDU_I18n::t('copy', $locale),
                'copiedLabel'   => PSDU_I18n::t('copied', $locale),
                'networkError'  => PSDU_I18n::t('network_error', $locale),
                'expiredLabel'  => PSDU_I18n::t('status_expired', $locale),
                'paidLabel'     => PSDU_I18n::t('status_paid', $locale),
                'checkingLabel' => PSDU_I18n::t('status_unknown', $locale),
                'expiredHelp'   => PSDU_I18n::t('expired_help', $locale),
                'verifiedHelp'  => PSDU_I18n::t('verified_help', $locale),
                'reviewHelp'    => PSDU_I18n::t('review_help', $locale),
            )
        );
    }

    public static function render_by_id($order_id)
    {
        $order = wc_get_order(absint($order_id));
        if ($order instanceof WC_Order) {
            self::render($order);
        }
    }

    public static function render_by_order($order)
    {
        if ($order instanceof WC_Order) {
            self::render($order);
        }
    }

    public static function render_admin_payment_info($order)
    {
        if (!$order instanceof WC_Order || !in_array($order->get_payment_method(), array(PSDU_GATEWAY_ID, PSDU_ERC20_GATEWAY_ID), true)) {
            return;
        }

        $network = self::order_network($order);
        $raw_status = (string) $order->get_meta('_psdu_payment_status', true);
        $amount = (string) $order->get_meta('_psdu_expected_amount', true);
        $address = (string) $order->get_meta('_psdu_receive_address', true);
        $txid = PSDU_Utils::normalize_txid($order->get_meta('_psdu_txid', true));
        $block = absint($order->get_meta('_psdu_block_number', true));
        $normalized = $order->is_paid() ? 'paid' : PSDU_Utils::normalize_status($raw_status);

        echo '<div class="notice notice-info inline psdu-admin-summary"><p>';
        echo '<strong>' . esc_html(PSDU_Utils::plugin_title($network)) . '</strong><br>';
        echo '网络：' . esc_html(PSDU_Utils::payment_network_label($network));
        echo '<br>状态：' . esc_html(self::admin_status_label($normalized));
        echo '<br>应付精确金额：' . esc_html($amount !== '' ? $amount . ' USDT' : '—');
        echo '<br>收款地址：<code>' . esc_html($address !== '' ? $address : '—') . '</code>';
        if ($txid !== '') {
            echo '<br>链上哈希：<code>' . esc_html($txid) . '</code>';
        }
        if ($block > 0) {
            echo '<br>已固化区块：<code>' . esc_html($block) . '</code>';
        }
        echo '</p></div>';
    }

    private static function render(WC_Order $order)
    {
        if (!in_array($order->get_payment_method(), array(PSDU_GATEWAY_ID, PSDU_ERC20_GATEWAY_ID), true)
            || isset(self::$rendered_orders[$order->get_id()])
            || !self::can_view_order($order)) {
            return;
        }
        self::$rendered_orders[$order->get_id()] = true;

        $stored_locale = (string) $order->get_meta('_psdu_locale', true);
        $locale = PSDU_I18n::locale($stored_locale);
        $network = self::order_network($order);
        $is_paid = $order->is_paid();
        $address = (string) $order->get_meta('_psdu_receive_address', true);
        $amount = (string) $order->get_meta('_psdu_expected_amount', true);
        $expires = (int) $order->get_meta('_psdu_expires_at', true);
        $raw_status = (string) $order->get_meta('_psdu_payment_status', true);
        $status = $is_paid ? 'paid' : PSDU_Utils::normalize_status($raw_status);
        if (!$is_paid && $expires > 0 && time() >= $expires && $status === 'pending') {
            $status = 'expired';
        }

        if (!$is_paid && ($address === '' || $amount === '')) {
            echo '<section class="psdu-panel psdu-panel--error">';
            echo '<h2>' . esc_html(PSDU_I18n::t('payment_unavailable_title', $locale)) . '</h2>';
            echo '<p>' . esc_html(PSDU_I18n::t('payment_unavailable_help', $locale)) . '</p>';
            echo '</section>';
            return;
        }

        $can_pay = !$is_paid && $status === 'pending';
        $state_class = sanitize_html_class($status);
        $brand = PSDU_Utils::site_brand();
        ?>
        <section
            class="psdu-panel psdu-panel--<?php echo esc_attr($state_class); ?>"
            data-psdu-payment
            data-order-id="<?php echo esc_attr($order->get_id()); ?>"
            data-order-key="<?php echo esc_attr($order->get_order_key()); ?>"
            data-address="<?php echo esc_attr($can_pay ? $address : ''); ?>"
            data-expires="<?php echo esc_attr($expires > 0 ? gmdate('c', $expires) : ''); ?>"
            data-state="<?php echo esc_attr($status); ?>"
            data-expired-help="<?php echo esc_attr(PSDU_I18n::t('expired_help', $locale)); ?>"
            data-verified-help="<?php echo esc_attr(PSDU_I18n::t('verified_help', $locale)); ?>"
            data-review-help="<?php echo esc_attr(PSDU_I18n::t('review_help', $locale)); ?>"
        >
            <header class="psdu-panel__header">
                <div class="psdu-brandmark" aria-hidden="true"><?php echo esc_html($network === PSDU_Utils::NETWORK_ERC20 ? 'E' : 'T'); ?></div>
                <div>
                    <span class="psdu-eyebrow"><?php echo esc_html(PSDU_I18n::t('secure_checkout', $locale, array($brand))); ?></span>
                    <h2><?php echo esc_html(PSDU_I18n::t($is_paid ? 'payment_received' : 'complete_payment', $locale)); ?></h2>
                    <p><?php echo esc_html(PSDU_I18n::t('order_number', $locale, array($order->get_order_number()))); ?></p>
                </div>
                <div class="psdu-status" data-payment-status aria-live="polite">
                    <span class="psdu-status__dot" aria-hidden="true"></span>
                    <span data-payment-status-label><?php echo esc_html(PSDU_Utils::status_label($status, $locale)); ?></span>
                </div>
            </header>

            <?php if ($is_paid) : ?>
                <div class="psdu-success">
                    <strong><?php echo esc_html(PSDU_I18n::network_t('verified', $network, $locale)); ?></strong>
                    <span><?php echo esc_html(PSDU_I18n::t('email_updates', $locale)); ?></span>
                </div>
            <?php elseif (!$can_pay) : ?>
                <div class="psdu-expired-message" data-payment-help>
                    <?php echo esc_html(PSDU_I18n::t($status === 'review' ? 'review_help' : 'expired_help', $locale)); ?>
                </div>
            <?php else : ?>
                <div class="psdu-panel__body">
                    <div class="psdu-qr-column">
                        <div class="psdu-qr" data-payment-qr aria-label="<?php echo esc_attr(PSDU_I18n::t('qr_label', $locale)); ?>"></div>
                        <span class="psdu-network">USDT · <?php echo esc_html(PSDU_Utils::payment_network_label($network)); ?></span>
                    </div>

                    <div class="psdu-details">
                        <div class="psdu-timer-row">
                            <span><?php echo esc_html(PSDU_I18n::t('time_remaining', $locale)); ?></span>
                            <strong data-payment-countdown>--:--</strong>
                        </div>

                        <div class="psdu-field">
                            <span><?php echo esc_html(PSDU_I18n::t('exact_amount', $locale)); ?></span>
                            <div>
                                <strong class="psdu-amount" data-payment-amount><?php echo esc_html($amount); ?> USDT</strong>
                                <button type="button" class="psdu-copy" data-copy-value="<?php echo esc_attr($amount); ?>" aria-label="<?php echo esc_attr(PSDU_I18n::t('copy_amount', $locale)); ?>"><?php echo esc_html(PSDU_I18n::t('copy', $locale)); ?></button>
                            </div>
                            <small class="psdu-exact-help"><?php echo esc_html(PSDU_I18n::t('exact_amount_help', $locale)); ?></small>
                        </div>

                        <div class="psdu-field">
                            <span><?php echo esc_html(PSDU_I18n::network_t('payment_address', $network, $locale)); ?></span>
                            <div>
                                <code class="psdu-address" data-payment-address><?php echo esc_html($address); ?></code>
                                <button type="button" class="psdu-copy" data-copy-value="<?php echo esc_attr($address); ?>" aria-label="<?php echo esc_attr(PSDU_I18n::t('copy_address', $locale)); ?>"><?php echo esc_html(PSDU_I18n::t('copy', $locale)); ?></button>
                            </div>
                        </div>

                        <div class="psdu-warning">
                            <strong><?php echo esc_html(PSDU_I18n::network_t('wrong_network_title', $network, $locale)); ?></strong>
                            <span><?php echo esc_html(PSDU_I18n::network_t('wrong_network_detail', $network, $locale)); ?></span>
                        </div>
                    </div>
                </div>

                <footer class="psdu-panel__footer">
                    <span class="psdu-live-indicator" aria-hidden="true"></span>
                    <span data-payment-help><?php echo esc_html(PSDU_I18n::t('auto_check', $locale)); ?></span>
                </footer>
            <?php endif; ?>
        </section>
        <?php
    }

    private static function can_view_order(WC_Order $order)
    {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }
        $customer_id = (int) $order->get_customer_id();
        if ($customer_id > 0 && get_current_user_id() === $customer_id) {
            return true;
        }

        $key = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
        return $key !== '' && hash_equals((string) $order->get_order_key(), (string) $key);
    }

    private static function admin_status_label($status)
    {
        $labels = array(
            'pending'    => '等待付款',
            'processing' => '链上核对中',
            'paid'       => '付款完成',
            'expired'    => '付款已过期',
            'cancelled'  => '付款已取消',
            'review'     => '已到账，等待人工复核',
            'unknown'    => '需要复核',
        );
        return isset($labels[$status]) ? $labels[$status] : $labels['unknown'];
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
