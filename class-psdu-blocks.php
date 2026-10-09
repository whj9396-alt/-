<?php

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class PSDU_Blocks extends AbstractPaymentMethodType
{
    protected $name = PSDU_GATEWAY_ID;
    protected $network = PSDU_Utils::NETWORK_TRC20;
    private $settings = array();

    public function __construct()
    {
        $this->network = PSDU_Utils::normalize_network($this->network);
        $this->name = PSDU_Utils::gateway_id($this->network);
    }

    public function initialize()
    {
        $settings = get_option('woocommerce_' . $this->name . '_settings', array());
        $this->settings = is_array($settings) ? $settings : array();
    }

    public function is_active()
    {
        if (empty($this->settings['enabled']) || $this->settings['enabled'] !== 'yes' || !is_ssl()) {
            return false;
        }

        $address = isset($this->settings['receive_address'])
            ? trim((string) $this->settings['receive_address'])
            : '';
        $rate = isset($this->settings['currency_per_usdt'])
            ? trim((string) $this->settings['currency_per_usdt'])
            : '';
        $rpc_url = isset($this->settings['rpc_url']) ? trim((string) $this->settings['rpc_url']) : '';

        if (!PSDU_Utils::validate_receive_address($this->network, $address)
            || $rpc_url === ''
            || !preg_match('/^(?:0|[1-9][0-9]{0,23})(?:\.[0-9]{1,12})?$/', $rate)
            || (float) $rate <= 0) {
            return false;
        }

        $node_health = get_option(
            $this->network === PSDU_Utils::NETWORK_ERC20 ? 'psdu_node_health_erc20' : 'psdu_node_health',
            array()
        );
        return is_array($node_health) && !empty($node_health['ok']);
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'psdu-blocks',
            PSDU_URL . 'assets/js/blocks.js',
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'),
            PSDU_VERSION,
            true
        );

        return array('psdu-blocks');
    }

    public function get_payment_method_data()
    {
        $locale = PSDU_I18n::locale();
        $auto_localize = !isset($this->settings['auto_localize'])
            || $this->settings['auto_localize'] === 'yes';
        $title = isset($this->settings['title']) ? trim((string) $this->settings['title']) : '';
        $description = isset($this->settings['description'])
            ? trim((string) $this->settings['description'])
            : '';

        return array(
            'active'      => true,
            'title'       => !$auto_localize && $title !== '' ? $title : PSDU_I18n::network_t('gateway_title', $this->network, $locale),
            'description' => !$auto_localize && $description !== '' ? $description : PSDU_I18n::network_t('gateway_description', $this->network, $locale),
            'supports'    => array('products'),
            'brand'       => PSDU_Utils::site_brand(),
            'gatewayId'   => $this->name,
            'tokenMark'   => $this->network === PSDU_Utils::NETWORK_ERC20 ? 'E' : 'T',
            'shortNetwork'=> strtoupper($this->network),
            'network'     => PSDU_I18n::network_t('blocks_network', $this->network, $locale),
            'details'     => PSDU_I18n::t('blocks_details', $locale),
        );
    }
}

final class PSDU_Ethereum_Blocks extends PSDU_Blocks
{
    protected $network = PSDU_Utils::NETWORK_ERC20;
}
