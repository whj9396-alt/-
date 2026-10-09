<?php

if (!defined('ABSPATH')) {
    exit;
}

final class PSDU_RPC_Client
{
    private $endpoint;
    private $api_key;
    private $timeout;
    private $network;
    private $config;
    private static $request_id = 0;

    public function __construct(array $settings, $network = PSDU_Utils::NETWORK_TRC20)
    {
        $this->network = PSDU_Utils::normalize_network($network);
        $this->config = PSDU_Utils::network_config($this->network);
        $this->endpoint = isset($settings['rpc_url'])
            ? trim((string) $settings['rpc_url'])
            : $this->config['default_rpc'];
        $this->api_key = isset($settings['rpc_api_key']) ? trim((string) $settings['rpc_api_key']) : '';
        $this->timeout = isset($settings['rpc_timeout']) ? max(5, min(25, absint($settings['rpc_timeout']))) : 10;

        if (!$this->valid_endpoint($this->endpoint)) {
            throw new RuntimeException('The ' . $this->config['chain_name'] . ' JSON-RPC endpoint is invalid.');
        }
        $parts = wp_parse_url($this->endpoint);
        if ($this->api_key !== '' && is_array($parts) && strtolower((string) $parts['scheme']) !== 'https') {
            throw new RuntimeException('A node API key may only be sent over HTTPS.');
        }
    }

    public function health()
    {
        $started = microtime(true);
        $chain_id = strtolower((string) $this->request('eth_chainId', array()));
        if (!hash_equals($this->config['chain_id'], $chain_id)) {
            throw new RuntimeException('The configured node is not connected to ' . $this->config['chain_name'] . '.');
        }

        $block = $this->get_finalized_block();
        if ((int) $block['timestamp'] < 1577836800 || (int) $block['timestamp'] > time() + 600) {
            throw new RuntimeException('The finalized block timestamp is invalid.');
        }
        $code = strtolower((string) $this->request(
            'eth_getCode',
            array('0x' . $this->config['contract_hex'], 'latest')
        ));
        if ($code === '' || $code === '0x' || !preg_match('/^0x[a-f0-9]+$/', $code)) {
            throw new RuntimeException('The official USDT contract is not available through this node.');
        }

        return array(
            'ok'          => true,
            'chain_id'    => $chain_id,
            'block'       => $block['number'],
            'block_time'  => $block['timestamp'],
            'latency_ms'  => (int) round((microtime(true) - $started) * 1000),
            'checked_at'  => time(),
            'endpoint'    => $this->safe_endpoint_label(),
        );
    }

    public function get_finalized_block()
    {
        return $this->get_block('finalized');
    }

    public function get_block($block)
    {
        $selector = is_int($block) || ctype_digit((string) $block)
            ? PSDU_Utils::int_to_hex_quantity((int) $block)
            : strtolower(trim((string) $block));

        if (!in_array($selector, array('latest', 'finalized'), true)
            && !preg_match('/^0x(?:0|[1-9a-f][a-f0-9]*)$/', $selector)) {
            throw new RuntimeException('Invalid block selector.');
        }

        $result = $this->request('eth_getBlockByNumber', array($selector, false));
        if (!is_array($result) || empty($result['number']) || empty($result['timestamp'])) {
            throw new RuntimeException('The node returned incomplete block data.');
        }

        return array(
            'number'    => PSDU_Utils::hex_quantity_to_int($result['number']),
            'timestamp' => PSDU_Utils::hex_quantity_to_int($result['timestamp']),
            'hash'      => isset($result['hash']) ? strtolower((string) $result['hash']) : '',
        );
    }

    public function get_transfer_logs($receive_address, $from_block, $to_block)
    {
        $from_block = max(0, (int) $from_block);
        $to_block = max($from_block, (int) $to_block);

        $filter = array(
            'fromBlock' => PSDU_Utils::int_to_hex_quantity($from_block),
            'toBlock'   => PSDU_Utils::int_to_hex_quantity($to_block),
            'address'   => '0x' . $this->config['contract_hex'],
            'topics'    => array(
                PSDU_Utils::TRANSFER_TOPIC,
                null,
                PSDU_Utils::topic_for_network_address($this->network, $receive_address),
            ),
        );

        $logs = $this->request('eth_getLogs', array($filter));
        if (!is_array($logs)) {
            throw new RuntimeException('The node returned invalid event log data.');
        }

        return $logs;
    }

    public function get_receipt($txid)
    {
        $txid = PSDU_Utils::normalize_txid($txid);
        if ($txid === '') {
            throw new RuntimeException('Invalid transaction hash.');
        }

        $receipt = $this->request('eth_getTransactionReceipt', array('0x' . $txid));
        if (!is_array($receipt)) {
            throw new RuntimeException('The confirmed transaction receipt is unavailable.');
        }

        return $receipt;
    }

    public function request($method, array $params)
    {
        $method = trim((string) $method);
        if (!preg_match('/^[a-z][A-Za-z0-9_]*$/', $method)) {
            throw new RuntimeException('Invalid JSON-RPC method.');
        }

        self::$request_id++;
        $headers = array('Content-Type' => 'application/json');
        if ($this->network === PSDU_Utils::NETWORK_TRC20 && $this->api_key !== '') {
            $headers['TRON-PRO-API-KEY'] = $this->api_key;
        }

        $body = wp_json_encode(
            array(
                'jsonrpc' => '2.0',
                'method'  => $method,
                'params'  => $params,
                'id'      => self::$request_id,
            ),
            JSON_UNESCAPED_SLASHES
        );
        if (!is_string($body)) {
            throw new RuntimeException('Unable to encode the JSON-RPC request.');
        }

        $response = wp_remote_post(
            $this->endpoint,
            array(
                'headers'     => $headers,
                'body'        => $body,
                'timeout'     => $this->timeout,
                'redirection' => 0,
                'sslverify'   => true,
                'user-agent'  => 'HAOJ1E-Direct-USDT/' . PSDU_VERSION,
                'data_format' => 'body',
            )
        );

        if (is_wp_error($response)) {
            throw new RuntimeException('Node connection failed: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        if ($status !== 200 || $raw === '' || strlen($raw) > 5242880) {
            throw new RuntimeException('Node returned an unexpected HTTP response.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_key_exists('id', $decoded)
            || (string) $decoded['id'] !== (string) self::$request_id) {
            throw new RuntimeException('Node returned invalid JSON-RPC data.');
        }
        if (!empty($decoded['error'])) {
            $message = is_array($decoded['error']) && isset($decoded['error']['message'])
                ? sanitize_text_field((string) $decoded['error']['message'])
                : 'unknown JSON-RPC error';
            throw new RuntimeException('Node error: ' . $message);
        }
        if (!array_key_exists('result', $decoded) || $decoded['result'] === null) {
            throw new RuntimeException('Node returned an empty result.');
        }

        return $decoded['result'];
    }

    private function valid_endpoint($url)
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        return true;
    }

    private function safe_endpoint_label()
    {
        $parts = wp_parse_url($this->endpoint);
        if (!is_array($parts)) {
            return '';
        }

        $label = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
        if (!empty($parts['port'])) {
            $label .= ':' . absint($parts['port']);
        }
        if (!empty($parts['path'])) {
            $label .= (string) $parts['path'];
        }
        return $label;
    }
}
