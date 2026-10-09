<?php

if (!defined('ABSPATH')) {
    exit;
}

class PSDU_Gateway extends WC_Payment_Gateway
{
    protected $network = PSDU_Utils::NETWORK_TRC20;
    private $receive_address;
    private $currency_per_usdt;
    private $markup_percent;
    private $payment_window;
    private $debug;

    public function __construct()
    {
        $this->network = PSDU_Utils::normalize_network($this->network);
        $this->id = PSDU_Utils::gateway_id($this->network);
        $this->method_title = PSDU_Utils::plugin_title($this->network);
        $this->method_description = '无需支付商的 USDT-' . strtoupper($this->network) . ' 直收网关。服务器只读监测已固化链上事件，不保存钱包私钥。';
        $this->has_fields = true;
        $this->supports = array('products');
        $this->order_button_text = PSDU_I18n::network_t('order_button', $this->network);

        $this->init_form_fields();
        $this->init_settings();
        $this->load_runtime_settings();

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            array($this, 'process_admin_options')
        );
    }

    public function init_form_fields()
    {
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
        $config = PSDU_Utils::network_config($this->network);
        $is_ethereum = $this->network === PSDU_Utils::NETWORK_ERC20;
        $this->form_fields = array(
            'enabled' => array(
                'title'   => '启用渠道',
                'type'    => 'checkbox',
                'label'   => '节点检测通过后，在结账页显示“直连 USDT-' . strtoupper($this->network) . '”',
                'default' => 'no',
            ),
            'auto_localize' => array(
                'title'   => '前台语言',
                'type'    => 'checkbox',
                'label'   => '自动跟随本站 WordPress 语言（推荐）',
                'default' => 'yes',
            ),
            'title' => array(
                'title'       => '顾客看到的付款方式名称',
                'type'        => 'text',
                'default'     => PSDU_I18n::network_t('gateway_title', $this->network),
                'description' => '关闭自动语言后使用此处内容。',
            ),
            'description' => array(
                'title'       => '结账说明',
                'type'        => 'textarea',
                'default'     => PSDU_I18n::network_t('gateway_description', $this->network),
                'description' => '必须明确保留网络名称，避免顾客转错网络。',
            ),
            'brand_color' => array(
                'title'       => '支付页品牌色',
                'type'        => 'color',
                'default'     => '#0787dc',
                'description' => '平台名称自动跟随当前域名。',
            ),
            'receive_address' => array(
                'title'             => 'USDT-' . strtoupper($this->network) . ' 收款地址',
                'type'              => 'text',
                'default'           => '',
                'description'       => $is_ethereum
                    ? '填写你自己的 Ethereum 钱包公开地址（0x 开头）。不要填写 USDT 合约地址，更不要填写私钥或助记词。'
                    : '填写你自己的 TRON 钱包公开地址（T 开头）。不要填写 USDT 合约地址，更不要填写私钥或助记词。',
                'custom_attributes' => array('autocomplete' => 'off', 'spellcheck' => 'false'),
            ),
            'currency_per_usdt' => array(
                'title'             => '1 USDT 等于多少 ' . ($currency !== '' ? esc_html($currency) : '本站币种'),
                'type'              => 'text',
                'default'           => $currency === 'USD' || $currency === 'USDT' ? '1.000000000000' : '',
                'description'       => '不使用第三方报价服务，由管理员填写。例：1 USDT = 0.92 EUR 时填写 0.92。商店币种为 USD/USDT 时通常填写 1。',
                'custom_attributes' => array('inputmode' => 'decimal', 'autocomplete' => 'off'),
            ),
            'markup_percent' => array(
                'title'             => '汇率保护比例（%）',
                'type'              => 'text',
                'default'           => '0',
                'description'       => '可填写 0 至 100，例：1.5。用于覆盖手工汇率波动，不是支付商手续费。',
                'custom_attributes' => array('inputmode' => 'decimal'),
            ),
            'tag_digits' => array(
                'title'       => '订单识别尾数',
                'type'        => 'select',
                'default'     => '4',
                'options'     => array(
                    '3' => '3 位（最多增加 0.000999 USDT）',
                    '4' => '4 位（推荐，最多增加 0.009999 USDT）',
                    '5' => '5 位（高并发，最多增加 0.099999 USDT）',
                ),
                'description' => '同一收款地址没有订单备注，插件使用不会重复的微小尾数自动匹配订单。尾数会明确显示给顾客。',
            ),
            'payment_window' => array(
                'title'             => '付款倒计时（分钟）',
                'type'              => 'number',
                'default'           => 60,
                'description'       => '超时后的到账进入人工复核，不会自动发货。推荐 60 分钟。',
                'custom_attributes' => array('min' => 10, 'max' => 1440, 'step' => 1),
            ),
            'rpc_url' => array(
                'title'       => '主 ' . $config['chain_name'] . ' JSON-RPC 节点',
                'type'        => 'url',
                'default'     => $config['default_rpc'],
                'description' => $is_ethereum
                    ? '默认公共只读节点可用于测试；正式运营建议填写独立的 Ethereum 主网 RPC URL。'
                    : '默认使用 TronGrid 只读节点；以后可替换为自己的 TRON Solidity JSON-RPC 地址。',
            ),
            'rpc_api_key' => array(
                'title'             => '主节点 API Key（可选）',
                'type'              => 'password',
                'default'           => '',
                'description'       => $is_ethereum
                    ? '大多数 Ethereum 服务把访问凭据放在 RPC URL 中；本字段仅供兼容，绝不是钱包密钥。'
                    : '仅用于提高 TronGrid 只读节点额度，不是钱包密钥。留空时使用节点公开额度。',
                'custom_attributes' => array('autocomplete' => 'new-password'),
            ),
            'secondary_rpc_url' => array(
                'title'       => '第二 ' . $config['chain_name'] . ' JSON-RPC 节点（推荐）',
                'type'        => 'url',
                'default'     => '',
                'description' => '配置独立节点后，每笔到账必须由两个节点返回相同结果才会发货。留空时使用单节点模式。',
            ),
            'secondary_rpc_api_key' => array(
                'title'             => '第二节点 API Key（可选）',
                'type'              => 'password',
                'default'           => '',
                'description'       => '只读节点访问凭据。不要填写钱包私钥。',
                'custom_attributes' => array('autocomplete' => 'new-password'),
            ),
            'rpc_timeout' => array(
                'title'             => '节点超时（秒）',
                'type'              => 'number',
                'default'           => 10,
                'custom_attributes' => array('min' => 5, 'max' => 25, 'step' => 1),
            ),
            'scan_batch_blocks' => array(
                'title'             => '每批扫描区块数',
                'type'              => 'number',
                'default'           => 600,
                'description'       => '推荐 600。节点有限制时可降低。',
                'custom_attributes' => array('min' => 50, 'max' => 4000, 'step' => 50),
            ),
            'initial_lookback' => array(
                'title'             => '首次回看区块数',
                'type'              => 'number',
                'default'           => 1200,
                'description'       => '安装或更换地址后回看最近区块，推荐 1200。',
                'custom_attributes' => array('min' => 100, 'max' => 5000, 'step' => 100),
            ),
            'debug' => array(
                'title'       => '调试日志',
                'type'        => 'checkbox',
                'label'       => '记录扫描状态（不会记录节点 API Key）',
                'default'     => 'no',
                'description' => '日志位置：WooCommerce → 状态 → 日志。',
            ),
        );
    }

    public function process_admin_options()
    {
        $old_address = isset($this->settings['receive_address']) ? (string) $this->settings['receive_address'] : '';
        $old_primary = isset($this->settings['rpc_url']) ? (string) $this->settings['rpc_url'] : '';
        $old_secondary = isset($this->settings['secondary_rpc_url']) ? (string) $this->settings['secondary_rpc_url'] : '';

        $saved = parent::process_admin_options();
        $this->init_settings();
        $this->load_runtime_settings();

        if ($old_address !== $this->receive_address
            || $old_primary !== (string) $this->get_option('rpc_url', '')
            || $old_secondary !== (string) $this->get_option('secondary_rpc_url', '')) {
            PSDU_Store::reset_scanner_cursor($this->network);
        }

        if ($this->settings_are_ready(false)) {
            try {
                $health = PSDU_Scanner::health_check($this->settings, $this->network);
                $health['ok'] = true;
                update_option($this->node_health_option(), $health, false);
            } catch (Throwable $exception) {
                update_option(
                    $this->node_health_option(),
                    array('ok' => false, 'checked_at' => time(), 'error' => sanitize_text_field($exception->getMessage())),
                    false
                );
                WC_Admin_Settings::add_error(PSDU_Utils::payment_network_label($this->network) . ' 节点检测失败：' . sanitize_text_field($exception->getMessage()));
            }
        }

        return $saved;
    }

    public function validate_receive_address_field($key, $value)
    {
        $value = trim((string) $value);
        $old = isset($this->settings[$key]) ? trim((string) $this->settings[$key]) : '';
        if ($value === '') {
            return '';
        }
        if (!PSDU_Utils::validate_receive_address($this->network, $value)) {
            WC_Admin_Settings::add_error('收款地址校验失败。请填写钱包公开地址，不能填写 USDT 合约地址。');
            return $old;
        }
        if ($old !== '' && !hash_equals($old, $value) && PSDU_Store::count_waiting($this->network) > 0) {
            WC_Admin_Settings::add_error('仍有待付款订单，暂时不能更换收款地址。请等待订单完成或过期。');
            return $old;
        }
        return PSDU_Utils::normalize_receive_address($this->network, $value);
    }

    public function validate_currency_per_usdt_field($key, $value)
    {
        $value = trim((string) $value);
        if (!preg_match('/^(?:0|[1-9][0-9]{0,23})(?:\.[0-9]{1,12})?$/', $value) || (float) $value <= 0) {
            WC_Admin_Settings::add_error('请填写有效汇率：1 USDT 等于多少本站币种。');
            return isset($this->settings[$key]) ? (string) $this->settings[$key] : '';
        }
        return $value;
    }

    public function validate_markup_percent_field($key, $value)
    {
        $value = trim((string) $value);
        if (!preg_match('/^(?:0|[1-9][0-9]?|100)(?:\.[0-9]{1,4})?$/', $value) || (float) $value > 100) {
            WC_Admin_Settings::add_error('汇率保护比例必须在 0 至 100 之间。');
            return isset($this->settings[$key]) ? (string) $this->settings[$key] : '0';
        }
        return $value;
    }

    public function admin_options()
    {
        $config = PSDU_Utils::network_config($this->network);
        $health = get_option($this->node_health_option(), array());
        $scanner = PSDU_Scanner::health_status($this->network);
        $monitor_url = admin_url('admin.php?page=psdu-monitor');

        echo '<h2>' . esc_html(PSDU_Utils::plugin_title($this->network)) . '</h2>';
        echo '<p>资金由顾客直接转入你的公开钱包地址。网站只读取已固化的 ' . esc_html($config['chain_name']) . ' 事件，不接触、不保存、也不需要钱包私钥。</p>';
        echo '<div class="notice notice-warning inline"><p><strong>必须遵守：</strong>绝不能在 WordPress、宝塔或聊天中填写助记词和私钥；每个网络必须填写对应格式的钱包地址。</p></div>';
        echo '<p><strong>官方 USDT-' . esc_html(strtoupper($this->network)) . ' 合约：</strong> <code>' . esc_html($config['contract']) . '</code></p>';
        echo '<p><strong>最低额度：</strong>插件不设置最低订单金额；钱包、交易所和链上网络费用仍由顾客使用的平台决定。</p>';

        if (!is_ssl()) {
            echo '<div class="notice notice-error inline"><p><strong>渠道已保护性关闭：</strong>正式收款网站必须启用 HTTPS。</p></div>';
        }

        if (is_array($health) && !empty($health['ok'])) {
            $primary = isset($health['primary']) && is_array($health['primary']) ? $health['primary'] : array();
            echo '<div class="notice notice-success inline"><p><strong>节点检测通过：</strong>' . esc_html($config['chain_name']) . ' 已连接，已固化区块 '
                . esc_html(isset($primary['block']) ? $primary['block'] : '—') . '。';
            echo !empty($health['secondary']) ? ' 第二节点交叉验证已开启。' : ' 当前为单节点模式，正式大额运营建议配置第二节点。';
            echo '</p></div>';
        } elseif (is_array($health) && !empty($health['error'])) {
            echo '<div class="notice notice-error inline"><p><strong>节点检测失败：</strong>' . esc_html($health['error']) . '</p></div>';
        } else {
            echo '<div class="notice notice-warning inline"><p>保存设置后会检测节点、主网 Chain ID 和官方 USDT 合约。</p></div>';
        }

        if (is_array($scanner) && !empty($scanner['last_run_at'])) {
            echo '<p>最近扫描：' . esc_html(date_i18n('Y-m-d H:i:s', (int) $scanner['last_run_at']))
                . '，扫描游标：<code>' . esc_html(isset($scanner['cursor']) ? $scanner['cursor'] : '—') . '</code>';
            if (!empty($scanner['error'])) {
                echo '，错误：' . esc_html($scanner['error']);
            }
            echo '。 <a href="' . esc_url($monitor_url) . '">打开链上收款监测</a></p>';
        } else {
            echo '<p><a href="' . esc_url($monitor_url) . '">打开链上收款监测</a></p>';
        }

        parent::admin_options();
    }

    public function is_available()
    {
        if (!parent::is_available() || !is_ssl() || !$this->settings_are_ready() || !$this->node_is_ready()) {
            return false;
        }
        if (WC()->cart && (float) WC()->cart->get_total('edit') <= 0) {
            return false;
        }
        return true;
    }

    public function payment_fields()
    {
        $locale = PSDU_I18n::locale();
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }
        echo '<div class="psdu-choice">';
        echo '<span class="psdu-choice__token" aria-hidden="true">' . esc_html($this->network === PSDU_Utils::NETWORK_ERC20 ? 'E' : 'T') . '</span>';
        echo '<span><strong>USDT</strong><small>' . esc_html(PSDU_I18n::t('network_prefix', $locale)) . ' ' . esc_html(PSDU_Utils::payment_network_label($this->network)) . '</small></span>';
        echo '<span class="psdu-choice__tag">' . esc_html(PSDU_I18n::t('amount_locked', $locale)) . '</span>';
        echo '</div>';
        echo '<p class="psdu-choice__warning">' . esc_html(PSDU_I18n::network_t('wrong_network_short', $this->network, $locale)) . '</p>';
    }

    public function validate_fields()
    {
        if (!is_ssl()) {
            wc_add_notice(PSDU_I18n::t('https_error'), 'error');
            return false;
        }
        if (!$this->settings_are_ready()) {
            wc_add_notice(PSDU_I18n::t('create_error'), 'error');
            return false;
        }
        return true;
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order(absint($order_id));
        if (!$order instanceof WC_Order) {
            throw new RuntimeException(PSDU_I18n::t('invalid_order'));
        }
        if ($order->is_paid()) {
            return array('result' => 'success', 'redirect' => $this->get_return_url($order));
        }
        if (!is_ssl() || !$this->settings_are_ready()) {
            throw new RuntimeException(PSDU_I18n::t('create_error'));
        }

        try {
            $client = new PSDU_RPC_Client($this->settings, $this->network);
            $health = $client->health();
            $start_block = max(0, (int) $health['block'] - 2);
            PSDU_Scanner::prime_cursor($start_block, $this->settings, $this->network);
            $quote = PSDU_Store::create_quote($order, $this->settings, $start_block, $this->network);
            $expires_at = strtotime($quote['expires_at'] . ' UTC');
            if ($expires_at) {
                PSDU_Runtime::schedule_expiry($order->get_id(), $expires_at);
            }

            if ($order->get_status() !== 'on-hold') {
                $order->update_status(
                    'on-hold',
                    sprintf(
                        'Direct USDT-%s payment reserved: %s USDT to %s.',
                        strtoupper($this->network),
                        $quote['expected_amount'],
                        $quote['receive_address']
                    )
                );
            }
        } catch (Throwable $exception) {
            wc_get_logger()->error(
                'Unable to create a direct USDT payment quote.',
                array('source' => 'partsyhub-direct-usdt', 'order_id' => $order->get_id(), 'message' => $exception->getMessage())
            );
            throw new RuntimeException(PSDU_I18n::t('create_error'));
        }

        if (WC()->cart) {
            WC()->cart->empty_cart();
        }
        return array('result' => 'success', 'redirect' => $this->get_return_url($order));
    }

    private function load_runtime_settings()
    {
        $auto = $this->get_option('auto_localize', 'yes') === 'yes';
        $this->title = $auto ? PSDU_I18n::network_t('gateway_title', $this->network) : (string) $this->get_option('title', PSDU_I18n::network_t('gateway_title', $this->network));
        $this->description = $auto ? PSDU_I18n::network_t('gateway_description', $this->network) : (string) $this->get_option('description', PSDU_I18n::network_t('gateway_description', $this->network));
        $this->receive_address = trim((string) $this->get_option('receive_address', ''));
        $this->currency_per_usdt = trim((string) $this->get_option('currency_per_usdt', ''));
        $this->markup_percent = trim((string) $this->get_option('markup_percent', '0'));
        $this->payment_window = max(10, min(1440, absint($this->get_option('payment_window', 60))));
        $this->debug = $this->get_option('debug', 'no') === 'yes';
    }

    private function settings_are_ready($require_rate = true)
    {
        if (!PSDU_Utils::validate_receive_address($this->network, $this->receive_address)) {
            return false;
        }
        if ($require_rate && (!preg_match('/^(?:0|[1-9][0-9]{0,23})(?:\.[0-9]{1,12})?$/', $this->currency_per_usdt)
            || (float) $this->currency_per_usdt <= 0)) {
            return false;
        }
        $rpc_url = trim((string) $this->get_option('rpc_url', ''));
        return $rpc_url !== '';
    }

    private function node_is_ready()
    {
        $node = get_option($this->node_health_option(), array());
        if (!is_array($node) || empty($node['ok'])) {
            return false;
        }

        $scanner = PSDU_Scanner::health_status($this->network);
        if (!empty($scanner['last_run_at']) && empty($scanner['ok'])) {
            $last_success = isset($scanner['last_success_at']) ? (int) $scanner['last_success_at'] : 0;
            if ($last_success === 0 || time() - $last_success > 300) {
                return false;
            }
        }
        return true;
    }

    private function node_health_option()
    {
        return $this->network === PSDU_Utils::NETWORK_ERC20 ? 'psdu_node_health_erc20' : 'psdu_node_health';
    }
}

final class PSDU_Ethereum_Gateway extends PSDU_Gateway
{
    protected $network = PSDU_Utils::NETWORK_ERC20;
}
