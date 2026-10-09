<?php

if (!defined('ABSPATH')) {
    exit;
}

final class PSDU_Utils
{
    const NETWORK_TRC20 = 'trc20';
    const NETWORK_ERC20 = 'erc20';
    const USDT_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
    const USDT_CONTRACT_HEX = 'a614f803b6fd780986a42c78ec9c7f77e6ded13c';
    const ETH_USDT_CONTRACT = '0xdAC17F958D2ee523a2206206994597C13D831ec7';
    const ETH_USDT_CONTRACT_HEX = 'dac17f958d2ee523a2206206994597c13d831ec7';
    const USDT_DECIMALS = 6;
    const MAINNET_CHAIN_ID = '0x2b6653dc';
    const ETH_MAINNET_CHAIN_ID = '0x1';
    const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    private const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function site_brand()
    {
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            $host = (string) get_bloginfo('name');
        }

        $host = preg_replace('/^www\./i', '', trim($host));
        return (string) apply_filters('psdu_site_brand', $host !== '' ? $host : 'USDT');
    }

    public static function plugin_title($network = null)
    {
        if ($network === null) {
            return 'HAOJ1E + ' . self::site_brand() . ' + USDT-TRC20/ERC20';
        }
        return 'HAOJ1E + ' . self::site_brand() . ' + USDT-' . strtoupper(self::normalize_network($network));
    }

    public static function normalize_network($network)
    {
        return strtolower(trim((string) $network)) === self::NETWORK_ERC20
            ? self::NETWORK_ERC20
            : self::NETWORK_TRC20;
    }

    public static function network_config($network)
    {
        $network = self::normalize_network($network);
        if ($network === self::NETWORK_ERC20) {
            return array(
                'id'           => self::NETWORK_ERC20,
                'gateway_id'   => defined('PSDU_ERC20_GATEWAY_ID') ? PSDU_ERC20_GATEWAY_ID : 'haoj1e_direct_usdt_erc20',
                'label'        => 'Ethereum (ERC20)',
                'short_label'  => 'ERC20',
                'chain_name'   => 'Ethereum Mainnet',
                'chain_id'     => self::ETH_MAINNET_CHAIN_ID,
                'contract'     => self::ETH_USDT_CONTRACT,
                'contract_hex' => self::ETH_USDT_CONTRACT_HEX,
                'explorer'     => 'https://etherscan.io/tx/0x',
                'default_rpc'  => 'https://ethereum-rpc.publicnode.com',
            );
        }

        return array(
            'id'           => self::NETWORK_TRC20,
            'gateway_id'   => defined('PSDU_GATEWAY_ID') ? PSDU_GATEWAY_ID : 'partsyhub_direct_usdt',
            'label'        => 'TRON (TRC20)',
            'short_label'  => 'TRC20',
            'chain_name'   => 'TRON Mainnet',
            'chain_id'     => self::MAINNET_CHAIN_ID,
            'contract'     => self::USDT_CONTRACT,
            'contract_hex' => self::USDT_CONTRACT_HEX,
            'explorer'     => 'https://tronscan.org/#/transaction/',
            'default_rpc'  => 'https://api.trongrid.io/jsonrpc',
        );
    }

    public static function gateway_id($network)
    {
        $config = self::network_config($network);
        return $config['gateway_id'];
    }

    public static function payment_network_label($network = self::NETWORK_TRC20)
    {
        $config = self::network_config($network);
        return $config['label'];
    }

    public static function network_contract($network)
    {
        $config = self::network_config($network);
        return $config['contract'];
    }

    public static function network_contract_hex($network)
    {
        $config = self::network_config($network);
        return $config['contract_hex'];
    }

    public static function validate_ethereum_address($address)
    {
        return preg_match('/^0x[a-fA-F0-9]{40}$/', trim((string) $address)) === 1;
    }

    public static function validate_receive_address($network, $address)
    {
        $network = self::normalize_network($network);
        if ($network === self::NETWORK_ERC20) {
            return self::validate_ethereum_address($address)
                && !hash_equals(strtolower(self::ETH_USDT_CONTRACT), strtolower(trim((string) $address)));
        }
        return self::validate_tron_address($address)
            && !hash_equals(self::USDT_CONTRACT, trim((string) $address));
    }

    public static function normalize_receive_address($network, $address)
    {
        $address = trim((string) $address);
        return self::normalize_network($network) === self::NETWORK_ERC20 ? strtolower($address) : $address;
    }

    public static function topic_for_network_address($network, $address)
    {
        if (self::normalize_network($network) === self::NETWORK_ERC20) {
            if (!self::validate_ethereum_address($address)) {
                throw new RuntimeException('Invalid Ethereum address.');
            }
            $hex = strtolower(preg_replace('/^0x/i', '', trim((string) $address)));
            return '0x' . str_pad($hex, 64, '0', STR_PAD_LEFT);
        }
        return self::topic_for_address($address);
    }

    public static function address_from_network_topic($network, $topic)
    {
        if (self::normalize_network($network) === self::NETWORK_ERC20) {
            $topic = strtolower(preg_replace('/^0x/i', '', trim((string) $topic)));
            return preg_match('/^[a-f0-9]{64}$/', $topic) ? '0x' . substr($topic, -40) : '';
        }
        return self::address_from_topic($topic);
    }

    public static function addresses_equal($network, $left, $right)
    {
        $left = self::normalize_receive_address($network, $left);
        $right = self::normalize_receive_address($network, $right);
        return $left !== '' && hash_equals($left, $right);
    }

    public static function transaction_url($network, $txid)
    {
        $txid = self::normalize_txid($txid);
        if ($txid === '') {
            return '';
        }
        $config = self::network_config($network);
        return $config['explorer'] . $txid;
    }

    public static function validate_tron_address($address)
    {
        $decoded = self::base58check_decode($address);
        return is_string($decoded) && strlen($decoded) === 21 && ord($decoded[0]) === 0x41;
    }

    public static function tron_to_evm_hex($address)
    {
        $decoded = self::base58check_decode($address);
        if (!is_string($decoded) || strlen($decoded) !== 21 || ord($decoded[0]) !== 0x41) {
            throw new RuntimeException('Invalid TRON address checksum.');
        }

        return '0x' . strtolower(bin2hex(substr($decoded, 1)));
    }

    public static function evm_hex_to_tron($hex)
    {
        $hex = strtolower(preg_replace('/^0x/i', '', trim((string) $hex)));
        if (!preg_match('/^[a-f0-9]{40}$/', $hex)) {
            return '';
        }

        $payload = chr(0x41) . hex2bin($hex);
        $checksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        return self::base58_encode($payload . $checksum);
    }

    public static function topic_for_address($address)
    {
        $hex = preg_replace('/^0x/', '', self::tron_to_evm_hex($address));
        return '0x' . str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    public static function address_from_topic($topic)
    {
        $topic = strtolower(preg_replace('/^0x/i', '', trim((string) $topic)));
        if (!preg_match('/^[a-f0-9]{64}$/', $topic)) {
            return '';
        }

        return self::evm_hex_to_tron(substr($topic, -40));
    }

    public static function calculate_usdt_units($source_amount, $currency_per_usdt, $markup_percent = '0')
    {
        list($amount_digits, $amount_scale) = self::parse_decimal($source_amount, false);
        list($rate_digits, $rate_scale) = self::parse_decimal($currency_per_usdt, false);
        list($markup_digits, $markup_scale) = self::parse_decimal($markup_percent, true);

        if ($amount_digits === '0' || $rate_digits === '0') {
            throw new RuntimeException('Order amount and exchange rate must be greater than zero.');
        }

        if ($markup_scale > 4) {
            $discarded = substr($markup_digits, 0, strlen($markup_digits) - $markup_scale + 4);
            $markup_digits = $discarded === '' ? '0' : $discarded;
            $markup_scale = 4;
        }

        $markup_units = self::scale_integer($markup_digits, $markup_scale, 4);
        if (self::decimal_compare($markup_units, '1000000') > 0) {
            throw new RuntimeException('Markup percentage is outside the supported range.');
        }

        $markup_multiplier = self::decimal_add('1000000', $markup_units);
        $numerator = $amount_digits . str_repeat('0', $rate_scale + self::USDT_DECIMALS);
        $numerator = self::decimal_mul($numerator, $markup_multiplier);
        $denominator = $rate_digits . str_repeat('0', $amount_scale + self::USDT_DECIMALS);

        list($quotient, $remainder) = self::decimal_divmod($numerator, $denominator);
        if ($remainder !== '0') {
            $quotient = self::decimal_add($quotient, '1');
        }

        if (strlen(self::normalize_integer($quotient)) > 36) {
            throw new RuntimeException('The calculated USDT amount is too large.');
        }

        return self::normalize_integer($quotient === '0' ? '1' : $quotient);
    }

    public static function units_to_amount($units, $fixed = true)
    {
        $units = self::normalize_integer($units);
        $padded = str_pad($units, self::USDT_DECIMALS + 1, '0', STR_PAD_LEFT);
        $whole = substr($padded, 0, -self::USDT_DECIMALS);
        $fraction = substr($padded, -self::USDT_DECIMALS);

        if (!$fixed) {
            $fraction = rtrim($fraction, '0');
            return $fraction === '' ? $whole . '.00' : $whole . '.' . $fraction;
        }

        return $whole . '.' . $fraction;
    }

    public static function amount_to_units($amount)
    {
        list($digits, $scale) = self::parse_decimal($amount, false);
        if ($scale <= self::USDT_DECIMALS) {
            return self::normalize_integer($digits . str_repeat('0', self::USDT_DECIMALS - $scale));
        }

        $kept_length = strlen($digits) - ($scale - self::USDT_DECIMALS);
        $kept = $kept_length > 0 ? substr($digits, 0, $kept_length) : '0';
        $discarded = $kept_length > 0 ? substr($digits, $kept_length) : $digits;
        if (preg_match('/[1-9]/', $discarded)) {
            $kept = self::decimal_add($kept, '1');
        }

        return self::normalize_integer($kept);
    }

    public static function add_units($left, $right)
    {
        return self::decimal_add(self::normalize_integer($left), self::normalize_integer($right));
    }

    public static function compare_units($left, $right)
    {
        return self::decimal_compare(self::normalize_integer($left), self::normalize_integer($right));
    }

    public static function decimal_values_equal($left, $right)
    {
        list($left_digits, $left_scale) = self::parse_decimal($left, true);
        list($right_digits, $right_scale) = self::parse_decimal($right, true);
        $scale = max($left_scale, $right_scale);
        return self::scale_integer($left_digits, $left_scale, $scale)
            === self::scale_integer($right_digits, $right_scale, $scale);
    }

    public static function random_tag_units($digits)
    {
        $digits = max(2, min(5, absint($digits)));
        return (string) random_int(1, (10 ** $digits) - 1);
    }

    public static function hex_word_to_decimal($hex)
    {
        $hex = strtolower(preg_replace('/^0x/i', '', trim((string) $hex)));
        if ($hex === '' || !preg_match('/^[a-f0-9]+$/', $hex) || strlen($hex) > 64) {
            throw new RuntimeException('Invalid hexadecimal token amount.');
        }

        $decimal = '0';
        foreach (str_split($hex) as $character) {
            $decimal = self::decimal_mul_small($decimal, 16);
            $decimal = self::decimal_add($decimal, (string) hexdec($character));
        }

        return self::normalize_integer($decimal);
    }

    public static function hex_quantity_to_int($hex)
    {
        $hex = strtolower(trim((string) $hex));
        if (!preg_match('/^0x(?:0|[1-9a-f][a-f0-9]*)$/', $hex)) {
            throw new RuntimeException('Invalid JSON-RPC quantity.');
        }

        $value = hexdec(substr($hex, 2));
        if (!is_int($value) && (!is_float($value) || $value > PHP_INT_MAX)) {
            throw new RuntimeException('JSON-RPC quantity is too large.');
        }

        return (int) $value;
    }

    public static function int_to_hex_quantity($value)
    {
        $value = max(0, (int) $value);
        return '0x' . dechex($value);
    }

    public static function normalize_txid($txid)
    {
        $txid = strtolower(preg_replace('/^0x/i', '', trim((string) $txid)));
        return preg_match('/^[a-f0-9]{64}$/', $txid) ? $txid : '';
    }

    public static function normalize_status($status)
    {
        $status = strtolower(trim((string) $status));
        $map = array(
            'waiting'   => 'pending',
            'detected'  => 'processing',
            'paid'      => 'paid',
            'expired'   => 'expired',
            'cancelled' => 'cancelled',
            'late'      => 'unknown',
            'ambiguous' => 'unknown',
            'review'    => 'review',
        );

        return isset($map[$status]) ? $map[$status] : 'unknown';
    }

    public static function status_label($status, $locale = null)
    {
        $normalized = self::normalize_status($status);
        if (in_array($status, array('pending', 'processing', 'paid', 'expired', 'cancelled', 'review', 'unknown'), true)) {
            $normalized = $status;
        }

        return PSDU_I18n::t('status_' . $normalized, $locale);
    }

    private static function base58check_decode($address)
    {
        $address = trim((string) $address);
        if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address)) {
            return false;
        }

        $decoded = self::base58_decode($address);
        if (!is_string($decoded) || strlen($decoded) !== 25) {
            return false;
        }

        $payload = substr($decoded, 0, 21);
        $checksum = substr($decoded, 21, 4);
        $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        return hash_equals($expected, $checksum) ? $payload : false;
    }

    private static function base58_decode($input)
    {
        $bytes = array(0);
        $length = strlen($input);
        for ($position = 0; $position < $length; $position++) {
            $value = strpos(self::BASE58_ALPHABET, $input[$position]);
            if ($value === false) {
                return false;
            }

            $carry = $value;
            $count = count($bytes);
            for ($index = 0; $index < $count; $index++) {
                $carry += $bytes[$index] * 58;
                $bytes[$index] = $carry & 0xff;
                $carry = intdiv($carry, 256);
            }
            while ($carry > 0) {
                $bytes[] = $carry & 0xff;
                $carry = intdiv($carry, 256);
            }
        }

        $binary = '';
        for ($index = count($bytes) - 1; $index >= 0; $index--) {
            $binary .= chr($bytes[$index]);
        }

        $leading = strspn($input, '1');
        if ($binary === "\x00") {
            $binary = '';
        }
        return str_repeat("\x00", $leading) . $binary;
    }

    private static function base58_encode($binary)
    {
        if (!is_string($binary) || $binary === '') {
            return '';
        }

        $digits = array(0);
        $length = strlen($binary);
        for ($position = 0; $position < $length; $position++) {
            $carry = ord($binary[$position]);
            $count = count($digits);
            for ($index = 0; $index < $count; $index++) {
                $carry += $digits[$index] * 256;
                $digits[$index] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
            while ($carry > 0) {
                $digits[] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
        }

        $encoded = '';
        for ($index = count($digits) - 1; $index >= 0; $index--) {
            $encoded .= self::BASE58_ALPHABET[$digits[$index]];
        }

        $leading = strspn($binary, "\x00");
        if ($encoded === '1') {
            $encoded = '';
        }
        return str_repeat('1', $leading) . $encoded;
    }

    private static function parse_decimal($value, $allow_zero)
    {
        $value = trim((string) $value);
        if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.([0-9]+))?$/', $value, $matches)) {
            throw new RuntimeException('Invalid decimal value.');
        }

        $fraction = isset($matches[1]) ? $matches[1] : '';
        $whole = substr($value, 0, strlen($value) - ($fraction === '' ? 0 : strlen($fraction) + 1));
        $digits = self::normalize_integer($whole . $fraction);
        if (!$allow_zero && $digits === '0') {
            throw new RuntimeException('Decimal value must be greater than zero.');
        }

        return array($digits, strlen($fraction));
    }

    private static function scale_integer($digits, $current_scale, $target_scale)
    {
        $digits = self::normalize_integer($digits);
        if ($current_scale <= $target_scale) {
            return self::normalize_integer($digits . str_repeat('0', $target_scale - $current_scale));
        }

        $remove = $current_scale - $target_scale;
        if ($remove >= strlen($digits)) {
            return '0';
        }
        return self::normalize_integer(substr($digits, 0, -$remove));
    }

    private static function normalize_integer($value)
    {
        $value = trim((string) $value);
        if (!preg_match('/^[0-9]+$/', $value)) {
            throw new RuntimeException('Invalid unsigned integer.');
        }

        $value = ltrim($value, '0');
        return $value === '' ? '0' : $value;
    }

    private static function decimal_compare($left, $right)
    {
        $left = self::normalize_integer($left);
        $right = self::normalize_integer($right);
        if (strlen($left) !== strlen($right)) {
            return strlen($left) > strlen($right) ? 1 : -1;
        }
        return strcmp($left, $right);
    }

    private static function decimal_add($left, $right)
    {
        $left = strrev(self::normalize_integer($left));
        $right = strrev(self::normalize_integer($right));
        $length = max(strlen($left), strlen($right));
        $carry = 0;
        $result = '';
        for ($index = 0; $index < $length; $index++) {
            $sum = ($index < strlen($left) ? (int) $left[$index] : 0)
                + ($index < strlen($right) ? (int) $right[$index] : 0)
                + $carry;
            $result .= (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }
        if ($carry > 0) {
            $result .= (string) $carry;
        }
        return self::normalize_integer(strrev($result));
    }

    private static function decimal_subtract($left, $right)
    {
        if (self::decimal_compare($left, $right) < 0) {
            throw new RuntimeException('Unsigned subtraction would be negative.');
        }

        $left = strrev(self::normalize_integer($left));
        $right = strrev(self::normalize_integer($right));
        $borrow = 0;
        $result = '';
        for ($index = 0; $index < strlen($left); $index++) {
            $digit = (int) $left[$index] - $borrow - ($index < strlen($right) ? (int) $right[$index] : 0);
            if ($digit < 0) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result .= (string) $digit;
        }
        return self::normalize_integer(strrev($result));
    }

    private static function decimal_mul_small($value, $multiplier)
    {
        $value = strrev(self::normalize_integer($value));
        $multiplier = max(0, (int) $multiplier);
        $carry = 0;
        $result = '';
        for ($index = 0; $index < strlen($value); $index++) {
            $product = ((int) $value[$index] * $multiplier) + $carry;
            $result .= (string) ($product % 10);
            $carry = intdiv($product, 10);
        }
        while ($carry > 0) {
            $result .= (string) ($carry % 10);
            $carry = intdiv($carry, 10);
        }
        return self::normalize_integer(strrev($result));
    }

    private static function decimal_mul($left, $right)
    {
        $left = self::normalize_integer($left);
        $right = self::normalize_integer($right);
        if ($left === '0' || $right === '0') {
            return '0';
        }

        $result = '0';
        $right_reversed = strrev($right);
        for ($index = 0; $index < strlen($right_reversed); $index++) {
            $partial = self::decimal_mul_small($left, (int) $right_reversed[$index]);
            if ($partial !== '0') {
                $partial .= str_repeat('0', $index);
                $result = self::decimal_add($result, $partial);
            }
        }
        return self::normalize_integer($result);
    }

    private static function decimal_divmod($numerator, $denominator)
    {
        $numerator = self::normalize_integer($numerator);
        $denominator = self::normalize_integer($denominator);
        if ($denominator === '0') {
            throw new RuntimeException('Division by zero.');
        }
        if (self::decimal_compare($numerator, $denominator) < 0) {
            return array('0', $numerator);
        }

        $quotient = '';
        $remainder = '0';
        for ($index = 0; $index < strlen($numerator); $index++) {
            $remainder = self::normalize_integer(($remainder === '0' ? '' : $remainder) . $numerator[$index]);
            $digit = 0;
            while (self::decimal_compare($remainder, $denominator) >= 0) {
                $remainder = self::decimal_subtract($remainder, $denominator);
                $digit++;
            }
            $quotient .= (string) $digit;
        }

        return array(self::normalize_integer($quotient), self::normalize_integer($remainder));
    }
}
