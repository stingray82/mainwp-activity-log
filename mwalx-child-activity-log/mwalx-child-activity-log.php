<?php
/**
 * Plugin Name:       MainWP Activity Log (activitylog.io) Child
 * Description:       Site Based Activity Log Viewing and Settings Manager for MainWP
 * Tested up to:      6.8.3
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Version:           1.0.0-alpha.5
 * Author:            ReallyUsefulPlugins.com
 * Author URI:        https://reallyusefulplugins.com
 * License:           GPL2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mwalx-child-activity-log
 * Website:           https://reallyusefulplugins.com
 */

if (!defined('ABSPATH')) exit;

define('RUP_MAINWP_MWALX_MWALX_CHILD_SIG', 'mwalx_v1');
define('RUP_MAINWP_MWALX_MWALX_CHILD_VERSION', '1.0.0-alpha.5');

/** ===== Settings helpers (serialized array: activity-log-settings) ===== */

function RUP_MAINWP_MWALX_mwalx_child_get_settings_array() : array {
    $raw = get_option('activity-log-settings', []);
    if (is_string($raw)) {
        $maybe = @unserialize($raw);
        if (is_array($maybe)) $raw = $maybe;
    }
    return is_array($raw) ? $raw : [];
}

function RUP_MAINWP_MWALX_mwalx_child_update_settings_array(array $new) : void {
    update_option('activity-log-settings', $new, false);
}

/** Get current IP header from the serialized settings. */
function RUP_MAINWP_MWALX_mwalx_child_get_ip_source() : string {
    $s = RUP_MAINWP_MWALX_mwalx_child_get_settings_array();
    if (!empty($s['log_visitor_ip_source']) && is_string($s['log_visitor_ip_source'])) {
        return (string) $s['log_visitor_ip_source'];
    }
    return 'REMOTE_ADDR';
}

/** Set the IP header (only in log_visitor_ip_source). */
function RUP_MAINWP_MWALX_mwalx_child_set_ip_source(string $value) : void {
    $value = sanitize_text_field($value ?: 'REMOTE_ADDR');
    $s = RUP_MAINWP_MWALX_mwalx_child_get_settings_array();
    $s['log_visitor_ip_source'] = $value;

    // Just in case old mirrored keys were left behind, clean them up
    foreach (['ip_source','ip_header','visitor_ip_detected','visitor_ip','ip_detected'] as $legacy) {
        if (isset($s[$legacy])) unset($s[$legacy]);
    }

    RUP_MAINWP_MWALX_mwalx_child_update_settings_array($s);
}

/** ===== Snapshot builder ===== */

function RUP_MAINWP_MWALX_mwalx_child_build_payload() : array {
    global $wpdb;
    $table = $wpdb->prefix . 'aryo_activity_log';

    // Snapshot (last 20)
    $rows = $wpdb->get_results("
        SELECT histid, user_id, hist_ip, hist_time, action, object_type, object_subtype, object_name
        FROM {$table}
        ORDER BY hist_time DESC
        LIMIT 20
    ", ARRAY_A);

    $items = [];
    foreach ($rows ?: [] as $r) {
        $user_display = '(system)';
        if (!empty($r['user_id'])) {
            $u = get_user_by('id', (int)$r['user_id']);
            if ($u) $user_display = sprintf('%s (%s)', $u->user_login, implode(', ', $u->roles));
        }
        $items[] = [
            'date'         => date_i18n(get_option('date_format').' '.get_option('time_format'), (int)$r['hist_time']),
            'user_display' => $user_display,
            'ip'           => (string)$r['hist_ip'],
            'topic'        => (string)$r['object_type'],
            'context'      => (string)$r['object_subtype'],
            'meta'         => (string)$r['object_name'],
            'action'       => (string)$r['action'],
        ];
    }

    // Settings from serialized option
    $s = RUP_MAINWP_MWALX_mwalx_child_get_settings_array();
    $settings = [
        'logs_lifespan'     => (string)($s['logs_lifespan'] ?? ''),
        'logs_failed_login' => (string)($s['logs_failed_login'] ?? 'yes'),
        'logs_email'        => (string)($s['logs_email'] ?? 'yes'),
        'ip_source'         => RUP_MAINWP_MWALX_mwalx_child_get_ip_source(),
    ];

    return [
        'snapshot' => $items,
        'settings' => $settings,
        'meta'     => [
            'table'        => $table,
            'generated_at' => time(),
        ],
    ];
}

/** ===== MainWP transport ===== */

add_filter('mainwp_child_extra_execution', function($information, $post){
    $post = is_array($post) ? wp_unslash($post) : [];
    if (($post['sig'] ?? '') !== RUP_MAINWP_MWALX_MWALX_CHILD_SIG) return $information;

    if (!current_user_can('manage_options')) {
        $information['mwalx_error'] = 'forbidden';
        return $information;
    }

    $cmd = (string)($post['action'] ?? 'get_state');

    if ($cmd === 'get_state') {
        $information['mwalx'] = RUP_MAINWP_MWALX_mwalx_child_build_payload();
        return $information;
    }

    if ($cmd === 'get_logs') {
        global $wpdb;
        $table   = $wpdb->prefix . 'aryo_activity_log';
        $per     = max(1, min(200, (int)($post['per_page'] ?? 20)));
        $paged   = max(1, (int)($post['paged'] ?? 1));
        $offset  = ($paged - 1) * $per;

        $where = 'WHERE 1=1';
        $args  = [];

        $like_val = function($v) use ($wpdb){ return '%' . $wpdb->esc_like(sanitize_text_field($v)) . '%'; };

        if (!empty($post['ip'])) {
            $where .= " AND hist_ip LIKE %s"; $args[] = $like_val($post['ip']);
        }
        if (!empty($post['topic'])) {
            $where .= " AND object_type LIKE %s"; $args[] = $like_val($post['topic']);
        }
        $action_filter = (string)($post['action_filter'] ?? '');
        if ($action_filter !== '') {
            $where .= " AND action LIKE %s"; $args[] = $like_val($action_filter);
        }
        if (!empty($post['s'])) {
            $s = $like_val($post['s']);
            $where .= " AND (hist_ip LIKE %s OR action LIKE %s OR object_type LIKE %s OR object_subtype LIKE %s OR object_name LIKE %s)";
            array_push($args, $s,$s,$s,$s,$s);
        }
        if (!empty($post['date_from'])) {
            $from = strtotime(sanitize_text_field($post['date_from']) . ' 00:00:00');
            if ($from) { $where .= " AND hist_time >= %d"; $args[] = $from; }
        }
        if (!empty($post['date_to'])) {
            $to = strtotime(sanitize_text_field($post['date_to']) . ' 23:59:59');
            if ($to) { $where .= " AND hist_time <= %d"; $args[] = $to; }
        }

        $total = (int)$wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$table} {$where}", $args) );
        $rows  = $wpdb->get_results( $wpdb->prepare("
            SELECT histid, user_id, hist_ip, hist_time, action, object_type, object_subtype, object_name
            FROM {$table}
            {$where}
            ORDER BY hist_time DESC
            LIMIT %d OFFSET %d
        ", array_merge($args, [$per, $offset]) ), ARRAY_A );

        $items = [];
        foreach ($rows ?: [] as $r) {
            $user_display = '(system)';
            if (!empty($r['user_id'])) {
                $u = get_user_by('id', (int)$r['user_id']);
                if ($u) $user_display = sprintf('%s (%s)', $u->user_login, implode(', ', $u->roles));
            }
            $items[] = [
                'date'         => date_i18n(get_option('date_format').' '.get_option('time_format'), (int)$r['hist_time']),
                'user_display' => $user_display,
                'ip'           => (string)$r['hist_ip'],
                'topic'        => (string)$r['object_type'],
                'context'      => (string)$r['object_subtype'],
                'meta'         => (string)$r['object_name'],
                'action'       => (string)$r['action'],
            ];
        }

        $information['mwalx'] = ['items' => $items, 'total' => $total];
        return $information;
    }

    if ($cmd === 'set_settings') {
        $s = RUP_MAINWP_MWALX_mwalx_child_get_settings_array();

        if (array_key_exists('logs_lifespan', $post)) {
            $s['logs_lifespan'] = (string) sanitize_text_field($post['logs_lifespan']);
        }
        if (array_key_exists('logs_failed_login', $post)) {
            $s['logs_failed_login'] = ((string)$post['logs_failed_login'] === 'no') ? 'no' : 'yes';
        }
        if (array_key_exists('logs_email', $post)) {
            $s['logs_email'] = ((string)$post['logs_email'] === 'no') ? 'no' : 'yes';
        }
        if (array_key_exists('ip_source', $post)) {
            $s['log_visitor_ip_source'] = sanitize_text_field($post['ip_source']);
        }

        // clean any old mirrored keys
        foreach (['ip_source','ip_header','visitor_ip_detected','visitor_ip','ip_detected'] as $legacy) {
            if (isset($s[$legacy])) unset($s[$legacy]);
        }

        RUP_MAINWP_MWALX_mwalx_child_update_settings_array($s);

        $information['mwalx'] = RUP_MAINWP_MWALX_mwalx_child_build_payload();
        return $information;
    }

    if ($cmd === 'reset_logs') {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE " . $wpdb->prefix . 'aryo_activity_log');
        $information['mwalx'] = RUP_MAINWP_MWALX_mwalx_child_build_payload();
        return $information;
    }

    $information['mwalx'] = RUP_MAINWP_MWALX_mwalx_child_build_payload();
    return $information;
}, 10, 2);

/** Updater bootstrap */
add_action( 'plugins_loaded', function() {
    require_once __DIR__ . '/inc/updater.php';
    $updater_config = [
        'plugin_file' => plugin_basename( __FILE__ ),
        'slug'        => 'mwalx-child-activity-log',
        'name'        => 'MAINWP Activity Log Child',
        'version'     => RUP_MAINWP_MWALX_MWALX_CHILD_VERSION,
        'key'         => '',
        'allow_prerelease' => true,
        'server'      => 'https://raw.githubusercontent.com/stingray82/mainwp-activity-log/main/UUPD/mwalx-child-activity-log/index.json',
    ];
    \RUP\Updater\Updater_V1::register( $updater_config );
}, 20);