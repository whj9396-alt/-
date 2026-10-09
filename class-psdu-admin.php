<?php

if (!defined('ABSPATH')) {
    exit;
}

final class PSDU_Admin
{
    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'register_menu'), 60);
    }

    public static function register_menu()
    {
        add_submenu_page(
            'woocommerce',
            '链上收款监测',
            '链上收款监测',
            'manage_woocommerce',
            'psdu-monitor',
            array(__CLASS__, 'render_page')
        );
    }

    public static function render_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $stats = PSDU_Store::stats();
        $networks = array();
        foreach (array(PSDU_Utils::NETWORK_TRC20, PSDU_Utils::NETWORK_ERC20) as $network) {
            $settings = PSDU_Scanner::settings($network);
            $networks[$network] = array(
                'config'       => PSDU_Utils::network_config($network),
                'settings'     => $settings,
                'address'      => isset($settings['receive_address']) ? trim((string) $settings['receive_address']) : '',
                'node'         => get_option($network === PSDU_Utils::NETWORK_ERC20 ? 'psdu_node_health_erc20' : 'psdu_node_health', array()),
                'scanner'      => PSDU_Scanner::health_status($network),
                'settings_url' => admin_url('admin.php?page=wc-settings&tab=checkout&section=' . PSDU_Utils::gateway_id($network)),
            );
        }
        $next_scan = wp_next_scheduled('psdu_scan_chain');
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $last_success = max(
            isset($networks[PSDU_Utils::NETWORK_TRC20]['scanner']['last_success_at']) ? (int) $networks[PSDU_Utils::NETWORK_TRC20]['scanner']['last_success_at'] : 0,
            isset($networks[PSDU_Utils::NETWORK_ERC20]['scanner']['last_success_at']) ? (int) $networks[PSDU_Utils::NETWORK_ERC20]['scanner']['last_success_at'] : 0
        );
        ?>
        <div class="wrap psdu-monitor">
            <h1>链上收款监测</h1>
            <p>这里展示系统从 TRON 与 Ethereum 主网读取到的结果。后台没有“手动标记已付款”按钮，防止误发货。</p>

            <?php self::render_action_notice(); ?>

            <div class="psdu-monitor__security">
                <strong>资金安全边界</strong>
                <span>插件只保存公开收款地址，绝不需要助记词、私钥或钱包密码。每个独立网站请使用不同收款地址。</span>
            </div>

            <?php if ($cron_disabled) : ?>
                <div class="notice notice-warning inline"><p><strong>检测到 DISABLE_WP_CRON：</strong>请在服务器设置每分钟调用一次 WordPress Cron，否则顾客关闭付款页后可能无法及时自动发货。</p></div>
            <?php elseif (!$next_scan) : ?>
                <div class="notice notice-error inline"><p><strong>定时扫描未注册。</strong>请停用后重新启用本插件，或联系管理员检查 WordPress Cron。</p></div>
            <?php elseif ($last_success > 0 && time() - $last_success > 300) : ?>
                <div class="notice notice-error inline"><p><strong>链上扫描超过 5 分钟未成功。</strong>请立即运行健康检测并检查节点。</p></div>
            <?php endif; ?>

            <div class="psdu-monitor__actions">
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=psdu_scan_now'), 'psdu_scan_now')); ?>">立即扫描</a>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=psdu_health_check'), 'psdu_health_check')); ?>">检测节点</a>
                <a class="button" href="<?php echo esc_url($networks[PSDU_Utils::NETWORK_TRC20]['settings_url']); ?>">TRC20 设置</a>
                <a class="button" href="<?php echo esc_url($networks[PSDU_Utils::NETWORK_ERC20]['settings_url']); ?>">ERC20 设置</a>
            </div>

            <div class="psdu-monitor__stats">
                <?php self::stat_box('等待付款', isset($stats['waiting']) ? $stats['waiting'] : 0, 'waiting'); ?>
                <?php self::stat_box('已核实到账', isset($stats['paid']) ? $stats['paid'] : 0, 'paid'); ?>
                <?php self::stat_box('已过期', isset($stats['expired']) ? $stats['expired'] : 0, 'expired'); ?>
                <?php self::stat_box('需要复核', (isset($stats['events_unmatched']) ? $stats['events_unmatched'] : 0) + (isset($stats['review']) ? $stats['review'] : 0), 'review'); ?>
            </div>

            <div class="psdu-monitor__grid">
                <?php foreach ($networks as $network => $data) : ?>
                <section>
                    <h2><?php echo esc_html(strtoupper($network)); ?> 节点状态</h2>
                    <?php self::render_node_status($data['node']); ?>
                </section>
                <section>
                    <h2><?php echo esc_html(strtoupper($network)); ?> 扫描状态</h2>
                    <?php self::render_scanner_status($data['scanner'], $next_scan); ?>
                </section>
                <?php endforeach; ?>
            </div>

            <section class="psdu-monitor__section">
                <h2>当前站点收款配置</h2>
                <table class="widefat striped"><tbody>
                    <tr><th>站点</th><td><code><?php echo esc_html(PSDU_Utils::site_brand()); ?></code></td></tr>
                    <?php foreach ($networks as $network => $data) : ?>
                    <tr><th><?php echo esc_html(strtoupper($network)); ?> 收款地址</th><td><code><?php echo esc_html($data['address'] !== '' ? $data['address'] : '尚未设置'); ?></code></td></tr>
                    <tr><th><?php echo esc_html(strtoupper($network)); ?> 官方合约</th><td><code><?php echo esc_html($data['config']['contract']); ?></code></td></tr>
                    <?php endforeach; ?>
                    <tr><th>匹配规则</th><td>官方合约 + 收款地址 + 精确金额 + 成功回执 + 已固化区块</td></tr>
                    <tr><th>自动发货触发</th><td>验证通过后调用 WooCommerce <code>payment_complete()</code></td></tr>
                </tbody></table>
            </section>

            <?php self::render_quotes_table(PSDU_Store::recent_quotes(50)); ?>
            <?php self::render_events_table(PSDU_Store::recent_events(50)); ?>
        </div>
        <?php self::render_styles(); ?>
        <?php
    }

    private static function render_action_notice()
    {
        if (isset($_GET['psdu_scan'])) {
            $ok = sanitize_key(wp_unslash($_GET['psdu_scan'])) === 'success';
            echo '<div class="notice ' . ($ok ? 'notice-success' : 'notice-error') . ' is-dismissible"><p>';
            echo esc_html($ok ? '链上扫描已完成。' : '链上扫描失败，请查看下方错误和 WooCommerce 日志。');
            echo '</p></div>';
        }
        if (isset($_GET['psdu_health'])) {
            $ok = sanitize_key(wp_unslash($_GET['psdu_health'])) === 'success';
            echo '<div class="notice ' . ($ok ? 'notice-success' : 'notice-error') . ' is-dismissible"><p>';
            echo esc_html($ok ? '已配置网络的节点、主网和官方 USDT 合约检测通过。' : '至少一个已配置网络检测失败，请检查对应 RPC 地址或 API Key。');
            echo '</p></div>';
        }
    }

    private static function stat_box($label, $value, $state)
    {
        echo '<div class="psdu-monitor__stat psdu-monitor__stat--' . esc_attr($state) . '">';
        echo '<span>' . esc_html($label) . '</span><strong>' . esc_html((int) $value) . '</strong></div>';
    }

    private static function render_node_status($node)
    {
        if (!is_array($node) || empty($node['checked_at'])) {
            echo '<p class="psdu-state psdu-state--warning">尚未检测。保存渠道设置后会自动检测。</p>';
            return;
        }
        if (empty($node['ok'])) {
            echo '<p class="psdu-state psdu-state--error">检测失败：' . esc_html(isset($node['error']) ? $node['error'] : '未知错误') . '</p>';
            echo '<p>检测时间：' . esc_html(self::date((int) $node['checked_at'])) . '</p>';
            return;
        }

        $primary = isset($node['primary']) && is_array($node['primary']) ? $node['primary'] : array();
        echo '<p class="psdu-state psdu-state--ok">主节点检测通过</p>';
        echo '<dl><dt>节点</dt><dd><code>' . esc_html(isset($primary['endpoint']) ? $primary['endpoint'] : '—') . '</code></dd>';
        echo '<dt>Chain ID</dt><dd><code>' . esc_html(isset($primary['chain_id']) ? $primary['chain_id'] : '—') . '</code></dd>';
        echo '<dt>已固化区块</dt><dd>' . esc_html(isset($primary['block']) ? $primary['block'] : '—') . '</dd>';
        echo '<dt>检测延迟</dt><dd>' . esc_html(isset($primary['latency_ms']) ? $primary['latency_ms'] . ' ms' : '—') . '</dd>';
        echo '<dt>第二节点</dt><dd>' . (!empty($node['secondary']) ? '已开启交叉验证' : '未配置') . '</dd></dl>';
    }

    private static function render_scanner_status($scanner, $next_scan)
    {
        if (!is_array($scanner) || empty($scanner['last_run_at'])) {
            echo '<p class="psdu-state psdu-state--warning">尚未运行</p>';
        } elseif (!empty($scanner['ok'])) {
            echo '<p class="psdu-state psdu-state--ok">最近一次扫描成功</p>';
        } else {
            echo '<p class="psdu-state psdu-state--error">扫描失败：' . esc_html(isset($scanner['error']) ? $scanner['error'] : '未知错误') . '</p>';
        }

        echo '<dl><dt>最近运行</dt><dd>' . esc_html(!empty($scanner['last_run_at']) ? self::date((int) $scanner['last_run_at']) : '—') . '</dd>';
        echo '<dt>最近成功</dt><dd>' . esc_html(!empty($scanner['last_success_at']) ? self::date((int) $scanner['last_success_at']) : '—') . '</dd>';
        echo '<dt>扫描游标</dt><dd><code>' . esc_html(isset($scanner['cursor']) ? $scanner['cursor'] : '—') . '</code></dd>';
        echo '<dt>已固化区块</dt><dd><code>' . esc_html(isset($scanner['finalized_block']) ? $scanner['finalized_block'] : '—') . '</code></dd>';
        echo '<dt>下次计划</dt><dd>' . esc_html($next_scan ? self::date((int) $next_scan) : '未注册') . '</dd></dl>';
    }

    private static function render_quotes_table($quotes)
    {
        echo '<section class="psdu-monitor__section"><h2>最近 50 个付款报价</h2>';
        echo '<div class="psdu-monitor__table"><table class="widefat striped"><thead><tr>';
        echo '<th>订单</th><th>网络</th><th>状态</th><th>应付 USDT</th><th>本站金额</th><th>到期时间</th><th>交易</th>';
        echo '</tr></thead><tbody>';
        if (!$quotes) {
            echo '<tr><td colspan="7">暂无记录</td></tr>';
        }
        foreach ($quotes as $quote) {
            $order = wc_get_order((int) $quote['order_id']);
            $order_label = '#' . (int) $quote['order_id'];
            if ($order instanceof WC_Order) {
                $order_label = '#' . $order->get_order_number();
                $order_url = method_exists($order, 'get_edit_order_url') ? $order->get_edit_order_url() : '';
                if ($order_url) {
                    $order_label = '<a href="' . esc_url($order_url) . '">' . esc_html($order_label) . '</a>';
                } else {
                    $order_label = esc_html($order_label);
                }
            } else {
                $order_label = esc_html($order_label);
            }

            echo '<tr><td>' . $order_label . '</td>';
            $network = PSDU_Utils::normalize_network(isset($quote['network']) ? $quote['network'] : '');
            echo '<td>' . esc_html(strtoupper($network)) . '</td>';
            echo '<td>' . esc_html(self::quote_status($quote['status'])) . '</td>';
            echo '<td><strong>' . esc_html($quote['expected_amount']) . '</strong></td>';
            echo '<td>' . esc_html($quote['source_amount'] . ' ' . $quote['source_currency']) . '</td>';
            echo '<td>' . esc_html(self::date_string($quote['expires_at'])) . '</td>';
            echo '<td>' . self::tx_link($quote['txid'], $network) . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private static function render_events_table($events)
    {
        echo '<section class="psdu-monitor__section"><h2>最近 50 个官方 USDT 入账事件</h2>';
        echo '<div class="psdu-monitor__table"><table class="widefat striped"><thead><tr>';
        echo '<th>区块时间</th><th>网络</th><th>金额</th><th>处理结果</th><th>关联订单</th><th>交易</th>';
        echo '</tr></thead><tbody>';
        if (!$events) {
            echo '<tr><td colspan="6">暂无记录</td></tr>';
        }
        foreach ($events as $event) {
            echo '<tr><td>' . esc_html(self::date_string($event['block_time'])) . '</td>';
            $network = PSDU_Utils::normalize_network(isset($event['network']) ? $event['network'] : '');
            echo '<td>' . esc_html(strtoupper($network)) . '</td>';
            echo '<td><strong>' . esc_html($event['amount']) . ' USDT</strong></td>';
            echo '<td>' . esc_html(self::event_status($event['status'])) . '</td>';
            echo '<td>' . esc_html(!empty($event['order_id']) ? '#' . (int) $event['order_id'] : '—') . '</td>';
            echo '<td>' . self::tx_link($event['txid'], $network) . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private static function tx_link($txid, $network)
    {
        $txid = PSDU_Utils::normalize_txid($txid);
        if ($txid === '') {
            return '—';
        }
        $short = substr($txid, 0, 8) . '…' . substr($txid, -6);
        return '<a href="' . esc_url(PSDU_Utils::transaction_url($network, $txid)) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html($short) . '</code></a>';
    }

    private static function quote_status($status)
    {
        $labels = array('waiting' => '等待付款', 'paid' => '已核实到账', 'expired' => '已过期', 'review' => '已到账，需人工复核');
        return isset($labels[$status]) ? $labels[$status] : (string) $status;
    }

    private static function event_status($status)
    {
        $labels = array(
            'matched'   => '已匹配并发货',
            'unmatched' => '未找到精确订单',
            'late'      => '超时后到账，人工复核',
            'ambiguous' => '匹配冲突，人工复核',
            'ignored'   => '已忽略',
        );
        return isset($labels[$status]) ? $labels[$status] : (string) $status;
    }

    private static function date($timestamp)
    {
        return date_i18n('Y-m-d H:i:s', $timestamp);
    }

    private static function date_string($gmt)
    {
        $timestamp = strtotime((string) $gmt . ' UTC');
        return $timestamp ? self::date($timestamp) : '—';
    }

    private static function render_styles()
    {
        ?>
        <style>
            .psdu-monitor{max-width:1280px}.psdu-monitor__security{display:flex;gap:12px;align-items:flex-start;margin:16px 0;padding:14px 16px;border-left:4px solid #2271b1;background:#fff}.psdu-monitor__security span{color:#50575e}.psdu-monitor__actions{display:flex;flex-wrap:wrap;gap:8px;margin:16px 0}.psdu-monitor__stats{display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:12px;margin:18px 0}.psdu-monitor__stat{padding:16px;border:1px solid #dcdcde;border-top:3px solid #8c8f94;background:#fff}.psdu-monitor__stat span{display:block;color:#646970}.psdu-monitor__stat strong{display:block;margin-top:4px;font-size:26px}.psdu-monitor__stat--paid{border-top-color:#008a20}.psdu-monitor__stat--review{border-top-color:#d63638}.psdu-monitor__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.psdu-monitor__grid section,.psdu-monitor__section{margin-top:18px;padding:18px;border:1px solid #dcdcde;background:#fff}.psdu-monitor h2{margin-top:0}.psdu-monitor dl{display:grid;grid-template-columns:120px minmax(0,1fr);gap:8px 12px}.psdu-monitor dt{font-weight:600}.psdu-monitor dd{margin:0;overflow-wrap:anywhere}.psdu-state{padding:10px 12px;border-left:3px solid #dba617;background:#fcf9e8}.psdu-state--ok{border-left-color:#008a20;background:#edfaef}.psdu-state--error{border-left-color:#d63638;background:#fcf0f1}.psdu-monitor__table{overflow-x:auto}.psdu-monitor__table table{min-width:760px}.psdu-monitor code{overflow-wrap:anywhere}@media(max-width:782px){.psdu-monitor__stats,.psdu-monitor__grid{grid-template-columns:1fr 1fr}}@media(max-width:520px){.psdu-monitor__stats,.psdu-monitor__grid{grid-template-columns:1fr}}
        </style>
        <?php
    }
}
