<?php

if (!defined('ABSPATH')) {
    exit;
}

final class PSDU_Scanner
{
    const CURSOR_OPTION = 'psdu_scanner_cursor';
    const FINGERPRINT_OPTION = 'psdu_scanner_fingerprint';
    const HEALTH_OPTION = 'psdu_scanner_health';
    const LOCK_KEY = 'psdu_scanner_lock';

    private $settings;
    private $primary;
    private $secondary;
    private $address;
    private $network;
    private $config;
    private $debug;
    private $secondary_finalized_number;

    public function __construct(array $settings, $network = PSDU_Utils::NETWORK_TRC20)
    {
        $this->settings = $settings;
        $this->network = PSDU_Utils::normalize_network($network);
        $this->config = PSDU_Utils::network_config($this->network);
        $this->address = PSDU_Utils::normalize_receive_address(
            $this->network,
            isset($settings['receive_address']) ? $settings['receive_address'] : ''
        );
        $this->debug = isset($settings['debug']) && $settings['debug'] === 'yes';
        $this->primary = new PSDU_RPC_Client($settings, $this->network);
        $this->secondary = null;

        $secondary_url = isset($settings['secondary_rpc_url'])
            ? trim((string) $settings['secondary_rpc_url'])
            : '';
        if ($secondary_url !== '') {
            $primary_url = isset($settings['rpc_url']) ? trim((string) $settings['rpc_url']) : '';
            if (self::same_endpoint($primary_url, $secondary_url)) {
                throw new RuntimeException('The secondary node must use a different endpoint from the primary node.');
            }
            $this->secondary = new PSDU_RPC_Client(
                array(
                    'rpc_url'     => $secondary_url,
                    'rpc_api_key' => isset($settings['secondary_rpc_api_key']) ? $settings['secondary_rpc_api_key'] : '',
                    'rpc_timeout' => isset($settings['rpc_timeout']) ? $settings['rpc_timeout'] : 10,
                ),
                $this->network
            );
        }
        $this->secondary_finalized_number = null;
    }

    public static function settings($network = PSDU_Utils::NETWORK_TRC20)
    {
        $settings = get_option('woocommerce_' . PSDU_Utils::gateway_id($network) . '_settings', array());
        return is_array($settings) ? $settings : array();
    }

    public static function run_now($force = false, $network = null)
    {
        $lock_time = (int) get_option(self::LOCK_KEY, 0);
        if ($lock_time > 0 && time() - $lock_time > 120) {
            delete_option(self::LOCK_KEY);
        }
        if (!add_option(self::LOCK_KEY, time(), '', false)) {
            return self::health_status($network === null ? PSDU_Utils::NETWORK_TRC20 : $network);
        }

        try {
            $networks = $network === null
                ? array(PSDU_Utils::NETWORK_TRC20, PSDU_Utils::NETWORK_ERC20)
                : array(PSDU_Utils::normalize_network($network));
            $results = array();
            $successful_scanner = null;
            $first_error = null;

            foreach ($networks as $network_id) {
                $settings = self::settings($network_id);
                $address = isset($settings['receive_address']) ? $settings['receive_address'] : '';
                $rpc_url = isset($settings['rpc_url']) ? trim((string) $settings['rpc_url']) : '';
                if (!PSDU_Utils::validate_receive_address($network_id, $address) || $rpc_url === '') {
                    continue;
                }

                try {
                    $scanner = new self($settings, $network_id);
                    $results[$network_id] = $scanner->scan((bool) $force);
                    $successful_scanner = $scanner;
                } catch (Throwable $exception) {
                    $results[$network_id] = self::health_status($network_id);
                    if ($first_error === null) {
                        $first_error = $exception;
                    }
                }
            }

            if ($successful_scanner instanceof self) {
                $successful_scanner->reconcile_paid_orders();
                PSDU_Store::expire_due_quotes();
                $successful_scanner->cancel_expired_orders();
            }

            if ($network !== null) {
                $network_id = PSDU_Utils::normalize_network($network);
                if ($first_error instanceof Throwable) {
                    throw $first_error;
                }
                return isset($results[$network_id]) ? $results[$network_id] : array();
            }

            return array(
                'ok'       => $first_error === null && $results !== array(),
                'networks' => $results,
                'error'    => $first_error instanceof Throwable ? sanitize_text_field($first_error->getMessage()) : '',
            );
        } finally {
            delete_option(self::LOCK_KEY);
        }
    }

    public static function prime_cursor($block, array $settings, $network = PSDU_Utils::NETWORK_TRC20)
    {
        $network = PSDU_Utils::normalize_network($network);
        $fingerprint_option = self::option_key(self::FINGERPRINT_OPTION, $network);
        $cursor_option = self::option_key(self::CURSOR_OPTION, $network);
        $fingerprint = self::fingerprint($settings, $network);
        if ((string) get_option($fingerprint_option, '') !== $fingerprint) {
            update_option($fingerprint_option, $fingerprint, false);
            update_option($cursor_option, max(0, (int) $block), false);
        } elseif (get_option($cursor_option, null) === null) {
            update_option($cursor_option, max(0, (int) $block), false);
        }
    }

    public static function health_check(array $settings = null, $network = PSDU_Utils::NETWORK_TRC20)
    {
        $network = PSDU_Utils::normalize_network($network);
        $settings = is_array($settings) ? $settings : self::settings($network);
        $primary = (new PSDU_RPC_Client($settings, $network))->health();
        $result = array('primary' => $primary, 'secondary' => null, 'checked_at' => time());

        $secondary_url = isset($settings['secondary_rpc_url']) ? trim((string) $settings['secondary_rpc_url']) : '';
        if ($secondary_url !== '') {
            $primary_url = isset($settings['rpc_url']) ? trim((string) $settings['rpc_url']) : '';
            if (self::same_endpoint($primary_url, $secondary_url)) {
                throw new RuntimeException('The secondary node must use a different endpoint from the primary node.');
            }
            $secondary = new PSDU_RPC_Client(
                array(
                    'rpc_url'     => $secondary_url,
                    'rpc_api_key' => isset($settings['secondary_rpc_api_key']) ? $settings['secondary_rpc_api_key'] : '',
                    'rpc_timeout' => isset($settings['rpc_timeout']) ? $settings['rpc_timeout'] : 10,
                ),
                $network
            );
            $result['secondary'] = $secondary->health();
        }

        return $result;
    }

    public static function health_status($network = PSDU_Utils::NETWORK_TRC20)
    {
        $health = get_option(self::option_key(self::HEALTH_OPTION, $network), array());
        return is_array($health) ? $health : array();
    }

    public function scan($force = false)
    {
        $started = microtime(true);
        $cursor_option = self::option_key(self::CURSOR_OPTION, $this->network);
        $fingerprint_option = self::option_key(self::FINGERPRINT_OPTION, $this->network);
        $health_option = self::option_key(self::HEALTH_OPTION, $this->network);
        $health = array(
            'ok'              => false,
            'last_run_at'     => time(),
            'last_success_at' => 0,
            'network'         => $this->network,
            'cursor'          => (int) get_option($cursor_option, 0),
            'finalized_block' => 0,
            'logs_seen'       => 0,
            'events_matched'  => 0,
            'error'           => '',
            'secondary'       => $this->secondary instanceof PSDU_RPC_Client,
        );
        $previous = self::health_status($this->network);
        if (!empty($previous['last_success_at'])) {
            $health['last_success_at'] = (int) $previous['last_success_at'];
        }

        try {
            if (!PSDU_Utils::validate_receive_address($this->network, $this->address)) {
                throw new RuntimeException('A valid receiving address is required for ' . $this->config['label'] . '.');
            }

            $chain_id = strtolower((string) $this->primary->request('eth_chainId', array()));
            if (!hash_equals($this->config['chain_id'], $chain_id)) {
                throw new RuntimeException('Primary node is not connected to ' . $this->config['chain_name'] . '.');
            }
            if ($this->secondary instanceof PSDU_RPC_Client) {
                $secondary_chain = strtolower((string) $this->secondary->request('eth_chainId', array()));
                if (!hash_equals($this->config['chain_id'], $secondary_chain)) {
                    throw new RuntimeException('Secondary node is not connected to ' . $this->config['chain_name'] . '.');
                }
            }

            $finalized = $this->primary->get_finalized_block();
            $finalized_number = (int) $finalized['number'];
            $health['finalized_block'] = $finalized_number;
            $fingerprint = self::fingerprint($this->settings, $this->network);
            $stored_fingerprint = (string) get_option($fingerprint_option, '');
            $lookback = isset($this->settings['initial_lookback'])
                ? max(100, min(5000, absint($this->settings['initial_lookback'])))
                : 1200;

            if (!hash_equals($fingerprint, $stored_fingerprint)) {
                $cursor = $this->recovery_cursor($finalized_number, $lookback);
                update_option($fingerprint_option, $fingerprint, false);
                update_option($cursor_option, $cursor, false);
            } else {
                $stored_cursor = get_option($cursor_option, null);
                $cursor = $stored_cursor === null
                    ? $this->recovery_cursor($finalized_number, $lookback)
                    : (int) $stored_cursor;
                if ($stored_cursor === null) {
                    update_option($cursor_option, $cursor, false);
                }
                if ($cursor > $finalized_number) {
                    $cursor = $this->recovery_cursor($finalized_number, $lookback);
                    update_option($cursor_option, $cursor, false);
                }
            }

            $batch_size = isset($this->settings['scan_batch_blocks'])
                ? max(50, min(4000, absint($this->settings['scan_batch_blocks'])))
                : 600;
            $max_batches = $force ? 8 : 3;
            $block_cache = array();

            for ($batch = 0; $batch < $max_batches && $cursor < $finalized_number; $batch++) {
                $from_block = $cursor + 1;
                $to_block = min($finalized_number, $cursor + $batch_size);
                $logs = $this->primary->get_transfer_logs($this->address, $from_block, $to_block);

                foreach ($logs as $raw_log) {
                    $event = $this->parse_and_verify_log($raw_log, $finalized_number, $block_cache);
                    if ($event === null) {
                        continue;
                    }
                    $health['logs_seen']++;
                    $stored = PSDU_Store::record_event($event);
                    if ($this->match_event($stored)) {
                        $health['events_matched']++;
                    }
                }

                $cursor = $to_block;
                update_option($cursor_option, $cursor, false);
            }

            foreach (PSDU_Store::unmatched_events(100, $this->network) as $event) {
                if ($event['status'] === 'unmatched' && $this->match_event($event)) {
                    $health['events_matched']++;
                }
            }

            $health['ok'] = true;
            $health['cursor'] = $cursor;
            $health['last_success_at'] = time();
            $health['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
            update_option($health_option, $health, false);
            return $health;
        } catch (Throwable $exception) {
            $health['error'] = sanitize_text_field($exception->getMessage());
            $health['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
            update_option($health_option, $health, false);
            $this->log('error', 'Direct USDT chain scan failed.', array('message' => $exception->getMessage()));
            throw $exception;
        }
    }

    private function parse_and_verify_log($raw_log, $finalized_number, array &$block_cache)
    {
        if (!is_array($raw_log) || !empty($raw_log['removed'])) {
            return null;
        }

        $contract = strtolower((string) (isset($raw_log['address']) ? $raw_log['address'] : ''));
        if (!hash_equals('0x' . $this->config['contract_hex'], $contract)) {
            return null;
        }

        $topics = isset($raw_log['topics']) && is_array($raw_log['topics']) ? $raw_log['topics'] : array();
        if (count($topics) < 3 || !hash_equals(PSDU_Utils::TRANSFER_TOPIC, strtolower((string) $topics[0]))) {
            return null;
        }

        $to_address = PSDU_Utils::address_from_network_topic($this->network, $topics[2]);
        if ($to_address === '' || !PSDU_Utils::addresses_equal($this->network, $this->address, $to_address)) {
            return null;
        }
        $to_address = PSDU_Utils::normalize_receive_address($this->network, $to_address);
        $from_address = PSDU_Utils::address_from_network_topic($this->network, $topics[1]);
        if ($from_address === '') {
            throw new RuntimeException('Transfer sender address is invalid.');
        }

        $amount_units = PSDU_Utils::hex_word_to_decimal(isset($raw_log['data']) ? $raw_log['data'] : '');
        if (PSDU_Utils::compare_units($amount_units, '0') <= 0) {
            return null;
        }

        $txid = PSDU_Utils::normalize_txid(isset($raw_log['transactionHash']) ? $raw_log['transactionHash'] : '');
        if ($txid === '') {
            throw new RuntimeException('Transfer transaction hash is invalid.');
        }
        $block_number = PSDU_Utils::hex_quantity_to_int(isset($raw_log['blockNumber']) ? $raw_log['blockNumber'] : '');
        if ($block_number <= 0 || $block_number > $finalized_number) {
            throw new RuntimeException('Transfer is not in a finalized block.');
        }
        $log_index = PSDU_Utils::hex_quantity_to_int(isset($raw_log['logIndex']) ? $raw_log['logIndex'] : '0x0');

        $receipt = $this->primary->get_receipt($txid);
        $receipt_status = strtolower((string) (isset($receipt['status']) ? $receipt['status'] : ''));
        $receipt_txid = PSDU_Utils::normalize_txid(isset($receipt['transactionHash']) ? $receipt['transactionHash'] : '');
        $receipt_block = PSDU_Utils::hex_quantity_to_int(isset($receipt['blockNumber']) ? $receipt['blockNumber'] : '');
        if ($receipt_status !== '0x1'
            || $receipt_txid !== $txid
            || $receipt_block !== $block_number
            || !$this->receipt_contains_transfer($receipt, $txid, $log_index, $to_address, $amount_units)) {
            throw new RuntimeException('Transfer receipt did not pass finality validation.');
        }

        if (!empty($raw_log['blockTimestamp'])) {
            $block_time = PSDU_Utils::hex_quantity_to_int($raw_log['blockTimestamp']);
        } else {
            if (!isset($block_cache[$block_number])) {
                $block_cache[$block_number] = $this->primary->get_block($block_number);
            }
            $block_time = (int) $block_cache[$block_number]['timestamp'];
        }
        if ($block_time <= 0 || $block_time > time() + 600) {
            throw new RuntimeException('Transfer block timestamp is invalid.');
        }

        $event = array(
            'network'       => $this->network,
            'txid'          => $txid,
            'log_index'     => $log_index,
            'to_address'    => $to_address,
            'from_address'  => $from_address,
            'amount_units'  => $amount_units,
            'block_number'  => $block_number,
            'block_time'    => $block_time,
        );

        if ($this->secondary instanceof PSDU_RPC_Client) {
            $this->verify_on_secondary($event);
        }

        return $event;
    }

    private function verify_on_secondary(array $event)
    {
        if ($this->secondary_finalized_number === null) {
            $secondary_finalized = $this->secondary->get_finalized_block();
            $this->secondary_finalized_number = (int) $secondary_finalized['number'];
        }
        if ($this->secondary_finalized_number < (int) $event['block_number']) {
            throw new RuntimeException('Secondary node has not finalized this transfer yet.');
        }

        $receipt = $this->secondary->get_receipt($event['txid']);
        $status = strtolower((string) (isset($receipt['status']) ? $receipt['status'] : ''));
        $receipt_txid = PSDU_Utils::normalize_txid(isset($receipt['transactionHash']) ? $receipt['transactionHash'] : '');
        $receipt_block = PSDU_Utils::hex_quantity_to_int(isset($receipt['blockNumber']) ? $receipt['blockNumber'] : '');
        if ($status !== '0x1'
            || $receipt_txid !== $event['txid']
            || $receipt_block !== (int) $event['block_number']
            || !$this->receipt_contains_transfer(
                $receipt,
                $event['txid'],
                (int) $event['log_index'],
                $event['to_address'],
                $event['amount_units']
            )) {
            throw new RuntimeException('Secondary node rejected the transfer receipt.');
        }

        $logs = $this->secondary->get_transfer_logs(
            $event['to_address'],
            $event['block_number'],
            $event['block_number']
        );
        foreach ($logs as $log) {
            $txid = PSDU_Utils::normalize_txid(isset($log['transactionHash']) ? $log['transactionHash'] : '');
            $index = PSDU_Utils::hex_quantity_to_int(isset($log['logIndex']) ? $log['logIndex'] : '0x0');
            $units = PSDU_Utils::hex_word_to_decimal(isset($log['data']) ? $log['data'] : '');
            if ($txid === $event['txid'] && $index === (int) $event['log_index'] && $units === $event['amount_units']) {
                return;
            }
        }

        throw new RuntimeException('Secondary node did not return the same USDT transfer event.');
    }

    private function match_event(array $event)
    {
        if ($event['status'] === 'matched') {
            return false;
        }

        $candidates = PSDU_Store::find_candidates($event);
        if (count($candidates) === 1) {
            if (PSDU_Store::claim_event($event, $candidates[0])) {
                $this->complete_order(PSDU_Store::get_quote((int) $candidates[0]['id']));
                return true;
            }
            return false;
        }

        if (count($candidates) > 1) {
            PSDU_Store::set_event_status($event['id'], 'ambiguous');
            $this->log('error', 'A confirmed USDT transfer matched more than one order.', array(
                'network' => $this->network,
                'txid' => $event['txid'],
            ));
            return false;
        }

        if (PSDU_Store::has_late_candidate($event)) {
            PSDU_Store::set_event_status($event['id'], 'late');
        }
        return false;
    }

    private function receipt_contains_transfer(array $receipt, $txid, $log_index, $to_address, $amount_units)
    {
        $logs = isset($receipt['logs']) && is_array($receipt['logs']) ? $receipt['logs'] : array();
        foreach ($logs as $log) {
            if (!is_array($log)) {
                continue;
            }
            $contract = strtolower((string) (isset($log['address']) ? $log['address'] : ''));
            $topics = isset($log['topics']) && is_array($log['topics']) ? $log['topics'] : array();
            if (!hash_equals('0x' . $this->config['contract_hex'], $contract)
                || count($topics) < 3
                || !hash_equals(PSDU_Utils::TRANSFER_TOPIC, strtolower((string) $topics[0]))) {
                continue;
            }

            try {
                $candidate_txid = PSDU_Utils::normalize_txid(isset($log['transactionHash']) ? $log['transactionHash'] : '');
                $candidate_index = PSDU_Utils::hex_quantity_to_int(isset($log['logIndex']) ? $log['logIndex'] : '');
                $candidate_to = PSDU_Utils::address_from_network_topic($this->network, $topics[2]);
                $candidate_units = PSDU_Utils::hex_word_to_decimal(isset($log['data']) ? $log['data'] : '');
            } catch (Throwable $exception) {
                continue;
            }

            if ($candidate_txid === $txid
                && $candidate_index === (int) $log_index
                && PSDU_Utils::addresses_equal($this->network, $candidate_to, $to_address)
                && $candidate_units === $amount_units) {
                return true;
            }
        }
        return false;
    }

    private function reconcile_paid_orders()
    {
        foreach (PSDU_Store::paid_quotes_needing_completion(100) as $quote) {
            $this->complete_order($quote);
        }
    }

    private function complete_order(array $quote)
    {
        $order = wc_get_order((int) $quote['order_id']);
        $network = PSDU_Utils::normalize_network(isset($quote['network']) ? $quote['network'] : '');
        if (!$order instanceof WC_Order || $order->get_payment_method() !== PSDU_Utils::gateway_id($network)) {
            return;
        }

        $txid = PSDU_Utils::normalize_txid($quote['txid']);
        if ($txid === '' || PSDU_Utils::compare_units($quote['received_units'], $quote['expected_units']) !== 0) {
            $this->log('error', 'Paid quote failed its stored amount or transaction validation.', array(
                'order_id' => $quote['order_id'],
            ));
            return;
        }

        $current_total = wc_format_decimal($order->get_total(), wc_get_price_decimals());
        $order_value_matches = strtoupper((string) $order->get_currency()) === strtoupper((string) $quote['source_currency'])
            && PSDU_Utils::decimal_values_equal($current_total, $quote['source_amount']);
        if (!$order_value_matches) {
            $order->update_meta_data('_psdu_quote_id', (int) $quote['id']);
            $order->update_meta_data('_psdu_payment_status', 'review');
            $order->update_meta_data('_psdu_txid', $txid);
            $order->update_meta_data('_psdu_received_amount', sanitize_text_field($quote['received_amount']));
            $order->update_meta_data('_psdu_block_number', (int) $quote['block_number']);
            $order->save();
            $order->add_order_note(
                'Direct USDT payment received, but the order total or currency changed after the quote was created. Manual review is required; do not auto-ship.'
            );
            PSDU_Store::mark_quote_review((int) $quote['id']);
            $this->log('error', 'A verified payment requires review because the order value changed.', array(
                'order_id' => $quote['order_id'],
                'txid'     => $txid,
            ));
            do_action('psdu_direct_usdt_payment_review', $order, $quote);
            return;
        }

        $order->update_meta_data('_psdu_quote_id', (int) $quote['id']);
        $order->update_meta_data('_psdu_payment_status', 'paid');
        $order->update_meta_data('_psdu_network', $network);
        $order->update_meta_data('_psdu_txid', $txid);
        $order->update_meta_data('_psdu_log_index', (int) $quote['tx_log_index']);
        $order->update_meta_data('_psdu_from_address', sanitize_text_field($quote['from_address']));
        $order->update_meta_data('_psdu_received_amount', sanitize_text_field($quote['received_amount']));
        $order->update_meta_data('_psdu_block_number', (int) $quote['block_number']);
        $order->save();

        if (!$order->is_paid()) {
            $order->payment_complete($txid);
            $order->add_order_note(
                sprintf(
                    'Direct USDT-%s payment verified in finalized block %d. Tx: %s',
                    strtoupper($network),
                    (int) $quote['block_number'],
                    $txid
                )
            );
            do_action('psdu_direct_usdt_payment_confirmed', $order, $quote);
        }

        if ($order->is_paid()) {
            PSDU_Store::mark_quote_order_synced((int) $quote['id']);
        }
    }

    private function cancel_expired_orders()
    {
        foreach (PSDU_Store::expired_quotes_needing_cancellation(100) as $quote) {
            $order = wc_get_order((int) $quote['order_id']);
            $network = PSDU_Utils::normalize_network(isset($quote['network']) ? $quote['network'] : '');
            if (!$order instanceof WC_Order || $order->get_payment_method() !== PSDU_Utils::gateway_id($network)) {
                continue;
            }
            if (absint($order->get_meta('_psdu_quote_id', true)) !== (int) $quote['id']) {
                PSDU_Store::mark_quote_order_synced((int) $quote['id']);
                continue;
            }
            if ($order->is_paid()) {
                PSDU_Store::mark_quote_order_synced((int) $quote['id']);
                continue;
            }

            $order->update_meta_data('_psdu_payment_status', 'expired');
            $order->save();
            if (!in_array($order->get_status(), array('cancelled', 'failed'), true)) {
                $order->update_status('cancelled', 'Direct USDT payment window expired. Stock restored.');
            }
            PSDU_Store::mark_quote_order_synced((int) $quote['id']);
        }
    }

    private static function fingerprint(array $settings, $network = PSDU_Utils::NETWORK_TRC20)
    {
        $network = PSDU_Utils::normalize_network($network);
        return hash(
            'sha256',
            $network
            . '|'
            . strtolower(trim((string) (isset($settings['receive_address']) ? $settings['receive_address'] : '')))
            . '|'
            . strtolower(trim((string) (isset($settings['rpc_url']) ? $settings['rpc_url'] : '')))
            . '|'
            . strtolower(trim((string) (isset($settings['secondary_rpc_url']) ? $settings['secondary_rpc_url'] : '')))
            . '|'
            . PSDU_Utils::network_contract($network)
        );
    }

    private static function same_endpoint($left, $right)
    {
        $left = rtrim(strtolower(trim((string) $left)), '/');
        $right = rtrim(strtolower(trim((string) $right)), '/');
        return $left !== '' && hash_equals($left, $right);
    }

    private function recovery_cursor($finalized_number, $lookback)
    {
        $cursor = max(0, (int) $finalized_number - (int) $lookback);
        $earliest = PSDU_Store::earliest_relevant_block($this->network);
        if ($earliest !== null) {
            $cursor = min($cursor, max(0, (int) $earliest - 1));
        }
        return $cursor;
    }

    private function log($level, $message, array $context = array())
    {
        if ($level === 'debug' && !$this->debug) {
            return;
        }
        $context['source'] = 'partsyhub-direct-usdt';
        $context['network'] = $this->network;
        wc_get_logger()->log($level, $message, $context);
    }

    private static function option_key($base, $network)
    {
        return PSDU_Utils::normalize_network($network) === PSDU_Utils::NETWORK_ERC20
            ? $base . '_erc20'
            : $base;
    }
}
