<?php

if (!defined('ABSPATH')) {
    exit;
}

final class PSDU_Store
{
    public static function install()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $quotes = self::quotes_table();
        $events = self::events_table();

        dbDelta(
            "CREATE TABLE {$quotes} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                quote_key char(32) NOT NULL,
                order_id bigint(20) unsigned NOT NULL,
                network varchar(16) NOT NULL DEFAULT 'trc20',
                receive_address varchar(64) NOT NULL,
                expected_units varchar(40) NOT NULL,
                expected_amount decimal(36,6) NOT NULL,
                base_units varchar(40) NOT NULL,
                base_amount decimal(36,6) NOT NULL,
                source_amount decimal(36,8) NOT NULL,
                source_currency varchar(12) NOT NULL,
                currency_per_usdt decimal(36,12) NOT NULL,
                markup_percent decimal(12,4) NOT NULL DEFAULT 0,
                tag_units int(10) unsigned NOT NULL DEFAULT 0,
                reservation_key char(64) NOT NULL,
                start_block bigint(20) unsigned NOT NULL,
                expires_at datetime NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'waiting',
                txid char(64) DEFAULT NULL,
                tx_log_index int(10) unsigned DEFAULT NULL,
                from_address varchar(64) DEFAULT NULL,
                received_units varchar(40) DEFAULT NULL,
                received_amount decimal(36,6) DEFAULT NULL,
                block_number bigint(20) unsigned DEFAULT NULL,
                block_time datetime DEFAULT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                paid_at datetime DEFAULT NULL,
                order_synced_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY quote_key (quote_key),
                UNIQUE KEY reservation_key (reservation_key),
                UNIQUE KEY tx_event (network,txid,tx_log_index),
                KEY order_id (order_id),
                KEY match_quote (network,receive_address,expected_units,status),
                KEY quote_status (status,expires_at)
            ) ENGINE=InnoDB {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$events} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_key varchar(96) NOT NULL,
                network varchar(16) NOT NULL DEFAULT 'trc20',
                txid char(64) NOT NULL,
                log_index int(10) unsigned NOT NULL,
                contract_address varchar(64) NOT NULL,
                to_address varchar(64) NOT NULL,
                from_address varchar(64) NOT NULL,
                amount_units varchar(40) NOT NULL,
                amount decimal(36,6) NOT NULL,
                block_number bigint(20) unsigned NOT NULL,
                block_time datetime NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'unmatched',
                order_id bigint(20) unsigned DEFAULT NULL,
                quote_id bigint(20) unsigned DEFAULT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY event_key (event_key),
                KEY event_match (network,to_address,amount_units,status),
                KEY event_status (status,block_time),
                KEY order_id (order_id)
            ) ENGINE=InnoDB {$charset};"
        );

        update_option('psdu_db_version', PSDU_VERSION, false);
    }

    public static function create_quote(WC_Order $order, array $settings, $start_block, $network = PSDU_Utils::NETWORK_TRC20)
    {
        global $wpdb;

        $network = PSDU_Utils::normalize_network($network);
        $source_amount = wc_format_decimal($order->get_total(), wc_get_price_decimals());
        $source_currency = strtoupper((string) $order->get_currency());
        $current = self::get_order_quote($order);
        if (is_array($current) && in_array($current['status'], array('waiting', 'paid'), true)) {
            $same_order_value = PSDU_Utils::normalize_network(isset($current['network']) ? $current['network'] : '') === $network
                && strtoupper((string) $current['source_currency']) === $source_currency
                && PSDU_Utils::decimal_values_equal($current['source_amount'], $source_amount);
            if (!$same_order_value && $current['status'] === 'waiting') {
                self::supersede_quote((int) $current['id']);
            }
            $expires = strtotime($current['expires_at'] . ' UTC');
            if ($current['status'] === 'paid' || ($same_order_value && $expires && $expires > time())) {
                return $current;
            }
        }

        $address = isset($settings['receive_address']) ? trim((string) $settings['receive_address']) : '';
        if (!PSDU_Utils::validate_receive_address($network, $address)) {
            throw new RuntimeException('The configured receiving address is invalid for the selected network.');
        }
        $address = PSDU_Utils::normalize_receive_address($network, $address);

        $rate = isset($settings['currency_per_usdt']) ? trim((string) $settings['currency_per_usdt']) : '';
        $markup = isset($settings['markup_percent']) ? trim((string) $settings['markup_percent']) : '0';
        $base_units = PSDU_Utils::calculate_usdt_units($source_amount, $rate, $markup);
        $tag_digits = isset($settings['tag_digits']) ? absint($settings['tag_digits']) : 4;
        $window = isset($settings['payment_window']) ? absint($settings['payment_window']) : 60;
        $window = max(10, min(1440, $window));
        $now = time();
        $created = gmdate('Y-m-d H:i:s', $now);
        $expires = gmdate('Y-m-d H:i:s', $now + ($window * MINUTE_IN_SECONDS));

        for ($attempt = 0; $attempt < 80; $attempt++) {
            $tag_units = PSDU_Utils::random_tag_units($tag_digits);
            $expected_units = PSDU_Utils::add_units($base_units, $tag_units);
            if (strlen($expected_units) > 36) {
                throw new RuntimeException('The final USDT amount is too large to store safely.');
            }
            $reservation = hash('sha256', $network . '|' . strtolower($address) . '|' . $expected_units);
            try {
                $quote_key = bin2hex(random_bytes(16));
            } catch (Throwable $exception) {
                $quote_key = str_replace('-', '', wp_generate_uuid4());
            }

            $inserted = $wpdb->insert(
                self::quotes_table(),
                array(
                    'quote_key'          => $quote_key,
                    'order_id'           => $order->get_id(),
                    'network'            => $network,
                    'receive_address'    => $address,
                    'expected_units'     => $expected_units,
                    'expected_amount'    => PSDU_Utils::units_to_amount($expected_units),
                    'base_units'         => $base_units,
                    'base_amount'        => PSDU_Utils::units_to_amount($base_units),
                    'source_amount'      => $source_amount,
                    'source_currency'    => $source_currency,
                    'currency_per_usdt'  => $rate,
                    'markup_percent'     => $markup,
                    'tag_units'          => (int) $tag_units,
                    'reservation_key'    => $reservation,
                    'start_block'        => max(0, (int) $start_block),
                    'expires_at'         => $expires,
                    'status'             => 'waiting',
                    'created_at'         => $created,
                    'updated_at'         => $created,
                ),
                array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s')
            );

            if ($inserted) {
                $quote = self::get_quote((int) $wpdb->insert_id);
                self::store_order_quote($order, $quote);
                return $quote;
            }
        }

        throw new RuntimeException('Unable to reserve a unique USDT amount. Increase the unique tag digits.');
    }

    public static function get_quote($quote_id)
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::quotes_table() . ' WHERE id = %d', absint($quote_id)),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    public static function get_order_quote(WC_Order $order)
    {
        $quote_id = absint($order->get_meta('_psdu_quote_id', true));
        return $quote_id ? self::get_quote($quote_id) : null;
    }

    public static function record_event(array $event)
    {
        global $wpdb;

        $txid = PSDU_Utils::normalize_txid(isset($event['txid']) ? $event['txid'] : '');
        $log_index = isset($event['log_index']) ? absint($event['log_index']) : 0;
        if ($txid === '') {
            throw new RuntimeException('Cannot store an event without a valid transaction hash.');
        }
        $network = PSDU_Utils::normalize_network(isset($event['network']) ? $event['network'] : '');
        // Keep legacy TRC20 event keys stable during the dual-network upgrade.
        $event_key = ($network === PSDU_Utils::NETWORK_ERC20 ? 'erc20:' : '') . $txid . ':' . $log_index;
        $now = gmdate('Y-m-d H:i:s');

        $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO ' . self::events_table() .
                ' (event_key,network,txid,log_index,contract_address,to_address,from_address,amount_units,amount,block_number,block_time,status,created_at,updated_at)'
                . ' VALUES (%s,%s,%s,%d,%s,%s,%s,%s,%s,%d,%s,%s,%s,%s)',
                $event_key,
                $network,
                $txid,
                $log_index,
                PSDU_Utils::network_contract($network),
                (string) $event['to_address'],
                (string) $event['from_address'],
                (string) $event['amount_units'],
                PSDU_Utils::units_to_amount($event['amount_units']),
                (int) $event['block_number'],
                gmdate('Y-m-d H:i:s', (int) $event['block_time']),
                'unmatched',
                $now,
                $now
            )
        );

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::events_table() . ' WHERE event_key = %s', $event_key),
            ARRAY_A
        );
        if (!is_array($row)) {
            throw new RuntimeException('Unable to persist the confirmed on-chain event.');
        }

        return $row;
    }

    public static function find_candidates(array $event)
    {
        global $wpdb;

        $block_time = isset($event['block_time']) ? (string) $event['block_time'] : '';
        $network = PSDU_Utils::normalize_network(isset($event['network']) ? $event['network'] : '');
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::quotes_table()
                . " WHERE network = %s AND receive_address = %s AND expected_units = %s AND status IN ('waiting','expired')"
                . ' AND start_block <= %d AND expires_at >= %s ORDER BY id ASC LIMIT 3',
                $network,
                (string) $event['to_address'],
                (string) $event['amount_units'],
                (int) $event['block_number'],
                $block_time
            ),
            ARRAY_A
        );
    }

    public static function has_late_candidate(array $event)
    {
        global $wpdb;
        $network = PSDU_Utils::normalize_network(isset($event['network']) ? $event['network'] : '');
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::quotes_table()
                . " WHERE network=%s AND receive_address=%s AND expected_units=%s AND status IN ('waiting','expired')"
                . ' AND start_block <= %d AND expires_at < %s',
                $network,
                (string) $event['to_address'],
                (string) $event['amount_units'],
                (int) $event['block_number'],
                (string) $event['block_time']
            )
        ) > 0;
    }

    public static function claim_event(array $event, array $quote)
    {
        global $wpdb;

        $events_table = self::events_table();
        $quotes_table = self::quotes_table();
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->query('START TRANSACTION');

        try {
            $event_updated = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$events_table} SET status='matched',order_id=%d,quote_id=%d,updated_at=%s"
                    . " WHERE id=%d AND status='unmatched'",
                    (int) $quote['order_id'],
                    (int) $quote['id'],
                    $now,
                    (int) $event['id']
                )
            );
            if ($event_updated !== 1) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            $quote_updated = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$quotes_table} SET status='paid',txid=%s,tx_log_index=%d,from_address=%s,received_units=%s,"
                    . 'received_amount=%s,block_number=%d,block_time=%s,paid_at=%s,updated_at=%s'
                    . " WHERE id=%d AND status IN ('waiting','expired') AND txid IS NULL",
                    (string) $event['txid'],
                    (int) $event['log_index'],
                    (string) $event['from_address'],
                    (string) $event['amount_units'],
                    PSDU_Utils::units_to_amount($event['amount_units']),
                    (int) $event['block_number'],
                    (string) $event['block_time'],
                    $now,
                    $now,
                    (int) $quote['id']
                )
            );
            if ($quote_updated !== 1) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            $wpdb->query('COMMIT');
            return true;
        } catch (Throwable $exception) {
            $wpdb->query('ROLLBACK');
            throw $exception;
        }
    }

    public static function set_event_status($event_id, $status)
    {
        global $wpdb;
        $allowed = array('unmatched', 'ambiguous', 'late', 'ignored');
        $status = in_array($status, $allowed, true) ? $status : 'unmatched';
        $wpdb->update(
            self::events_table(),
            array('status' => $status, 'updated_at' => gmdate('Y-m-d H:i:s')),
            array('id' => absint($event_id)),
            array('%s', '%s'),
            array('%d')
        );
    }

    public static function unmatched_events($limit = 100, $network = null)
    {
        global $wpdb;
        $limit = max(1, min(500, absint($limit)));
        if ($network !== null) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM " . self::events_table() . " WHERE network=%s AND status IN ('unmatched','ambiguous','late') ORDER BY block_number ASC,id ASC LIMIT %d",
                    PSDU_Utils::normalize_network($network),
                    $limit
                ),
                ARRAY_A
            );
        }
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::events_table() . " WHERE status IN ('unmatched','ambiguous','late') ORDER BY block_number ASC,id ASC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }

    public static function paid_quotes_needing_completion($limit = 50)
    {
        global $wpdb;
        $limit = max(1, min(200, absint($limit)));
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::quotes_table() . " WHERE status='paid' AND order_synced_at IS NULL ORDER BY paid_at ASC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }

    public static function expired_quotes_needing_cancellation($limit = 100)
    {
        global $wpdb;
        $limit = max(1, min(300, absint($limit)));
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::quotes_table() . " WHERE status='expired' AND order_synced_at IS NULL ORDER BY updated_at ASC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }

    public static function mark_quote_order_synced($quote_id)
    {
        global $wpdb;
        $wpdb->update(
            self::quotes_table(),
            array('order_synced_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')),
            array('id' => absint($quote_id)),
            array('%s', '%s'),
            array('%d')
        );
    }

    public static function mark_quote_review($quote_id)
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->update(
            self::quotes_table(),
            array('status' => 'review', 'order_synced_at' => $now, 'updated_at' => $now),
            array('id' => absint($quote_id)),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }

    private static function supersede_quote($quote_id)
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->update(
            self::quotes_table(),
            array('status' => 'expired', 'order_synced_at' => $now, 'updated_at' => $now),
            array('id' => absint($quote_id), 'status' => 'waiting'),
            array('%s', '%s', '%s'),
            array('%d', '%s')
        );
    }

    public static function expire_due_quotes()
    {
        global $wpdb;
        return $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . self::quotes_table() . " SET status='expired',updated_at=%s WHERE status='waiting' AND expires_at < %s",
                gmdate('Y-m-d H:i:s'),
                gmdate('Y-m-d H:i:s')
            )
        );
    }

    public static function count_waiting($network = null)
    {
        global $wpdb;
        if ($network !== null) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM " . self::quotes_table() . " WHERE network=%s AND status='waiting'",
                    PSDU_Utils::normalize_network($network)
                )
            );
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::quotes_table() . " WHERE status='waiting'");
    }

    public static function earliest_relevant_block($network = PSDU_Utils::NETWORK_TRC20)
    {
        global $wpdb;
        $value = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT MIN(start_block) FROM ' . self::quotes_table()
                . " WHERE network=%s AND txid IS NULL AND (status='waiting' OR (status='expired' AND expires_at >= %s))",
                PSDU_Utils::normalize_network($network),
                gmdate('Y-m-d H:i:s', time() - (2 * DAY_IN_SECONDS))
            )
        );

        return $value === null ? null : max(0, (int) $value);
    }

    public static function stats()
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT status,COUNT(*) AS total FROM ' . self::quotes_table() . ' GROUP BY status',
            ARRAY_A
        );
        $stats = array('waiting' => 0, 'paid' => 0, 'expired' => 0, 'review' => 0, 'events_unmatched' => 0);
        foreach ($rows as $row) {
            $stats[(string) $row['status']] = (int) $row['total'];
        }
        $stats['events_unmatched'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::events_table() . " WHERE status IN ('unmatched','ambiguous','late')"
        );
        return $stats;
    }

    public static function recent_quotes($limit = 50)
    {
        global $wpdb;
        $limit = max(1, min(200, absint($limit)));
        return $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . self::quotes_table() . ' ORDER BY id DESC LIMIT %d', $limit),
            ARRAY_A
        );
    }

    public static function recent_events($limit = 50)
    {
        global $wpdb;
        $limit = max(1, min(200, absint($limit)));
        return $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . self::events_table() . ' ORDER BY id DESC LIMIT %d', $limit),
            ARRAY_A
        );
    }

    public static function reset_scanner_cursor($network = PSDU_Utils::NETWORK_TRC20)
    {
        $suffix = PSDU_Utils::normalize_network($network) === PSDU_Utils::NETWORK_ERC20 ? '_erc20' : '';
        delete_option('psdu_scanner_cursor' . $suffix);
        delete_option('psdu_scanner_fingerprint' . $suffix);
        delete_option('psdu_scanner_health' . $suffix);
    }

    private static function store_order_quote(WC_Order $order, array $quote)
    {
        $order->update_meta_data('_psdu_quote_id', (int) $quote['id']);
        $order->update_meta_data('_psdu_quote_key', sanitize_text_field($quote['quote_key']));
        $order->update_meta_data('_psdu_network', PSDU_Utils::normalize_network(isset($quote['network']) ? $quote['network'] : ''));
        $order->update_meta_data('_psdu_receive_address', sanitize_text_field($quote['receive_address']));
        $order->update_meta_data('_psdu_expected_units', sanitize_text_field($quote['expected_units']));
        $order->update_meta_data('_psdu_expected_amount', sanitize_text_field($quote['expected_amount']));
        $order->update_meta_data('_psdu_payment_status', sanitize_key($quote['status']));
        $order->update_meta_data('_psdu_expires_at', strtotime($quote['expires_at'] . ' UTC'));
        $order->update_meta_data('_psdu_start_block', (int) $quote['start_block']);
        $order->update_meta_data('_psdu_source_amount', sanitize_text_field($quote['source_amount']));
        $order->update_meta_data('_psdu_source_currency', sanitize_text_field($quote['source_currency']));
        $order->update_meta_data('_psdu_locale', PSDU_I18n::locale());
        $order->update_meta_data('_psdu_site_domain', PSDU_Utils::site_brand());
        $order->save();
    }

    private static function quotes_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'psdu_quotes';
    }

    private static function events_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'psdu_events';
    }
}
