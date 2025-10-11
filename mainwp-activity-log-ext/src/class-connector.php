<?php
namespace RUP_MAINWP_MWALX\MWALX;

if (!defined('ABSPATH')) exit;

/**
 * Handles authenticated communication with the child site via MainWP.
 * NOTE: SIG must match the child bridge.
 */
class RUP_MAINWP_MWALX_Connector {
    const SIG = 'mwalx_v1'; // keep this if your child uses v1

    /** Robust parser: supports JSON strings, PHP-serialized strings, arrays/objects. */
    private static function parse_response($resp) {
        if (is_wp_error($resp)) return $resp;

        if (is_string($resp)) {
            // Try JSON
            $json = json_decode($resp, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                return $json;
            }
            // Try PHP serialized
            $maybe = @unserialize($resp);
            if ($maybe !== false && is_array($maybe)) {
                return $maybe;
            }
            // Unknown format -> empty array
            return [];
        }

        if (is_object($resp)) {
            $resp = (array) $resp;
        }

        return is_array($resp) ? $resp : [];
    }

    private static function send_to_child(int $site_id, array $payload) {
        $act = $GLOBALS['mainWPExtensionActivator'] ?? null;
        if (!$act || !method_exists($act, 'get_child_key') || !$act->get_child_key()) {
            return new \WP_Error('no-ext', 'MainWP extension is not activated.');
        }

        $raw = apply_filters(
            'mainwp_fetchurlauthed',
            $act->get_child_file(),
            $act->get_child_key(),
            $site_id,
            'extra_execution',
            $payload
        );

        return self::parse_response($raw);
    }

    public static function get_state(int $site_id) {
        $resp = self::send_to_child($site_id, ['sig'=>self::SIG, 'action'=>'get_state']);
        if (is_wp_error($resp)) return [];
        return $resp['mwalx'] ?? [];
    }

    public static function fetch_logs(int $site_id, array $filters) {
        // IMPORTANT: command action=get_logs, UI's "action" filter is sent as action_filter
        $resp = self::send_to_child($site_id, [
            'sig'           => self::SIG,
            'action'        => 'get_logs',
            'per_page'      => (int)($filters['per_page'] ?? 20),
            'paged'         => (int)($filters['paged'] ?? 1),
            'ip'            => (string)($filters['ip'] ?? ''),
            'topic'         => (string)($filters['topic'] ?? ''),
            'action_filter' => (string)($filters['action'] ?? ''),
            's'             => (string)($filters['s'] ?? ''),
            'date_from'     => (string)($filters['date_from'] ?? ''),
            'date_to'       => (string)($filters['date_to'] ?? ''),
        ]);

        if (is_wp_error($resp)) return ['items'=>[], 'total'=>0];

        $data = $resp['mwalx'] ?? ['items'=>[], 'total'=>0];
        $data['items'] = is_array($data['items'] ?? null) ? $data['items'] : [];
        $data['total'] = (int)($data['total'] ?? 0);
        return $data;
    }

    public static function push_settings(int $site_id, array $settings) {
        $resp = self::send_to_child($site_id, [
            'sig'               => self::SIG,
            'action'            => 'set_settings',
            'logs_lifespan'     => (string)($settings['logs_lifespan'] ?? ''),
            'logs_failed_login' => (!empty($settings['logs_failed_login']) && $settings['logs_failed_login']==='no') ? 'no' : 'yes',
            'logs_email'        => (!empty($settings['logs_email']) && $settings['logs_email']==='no') ? 'no' : 'yes',
            'ip_source'         => (string)($settings['ip_source'] ?? 'REMOTE_ADDR'),
        ]);
        if (is_wp_error($resp)) return [];
        return $resp['mwalx'] ?? [];
    }

    public static function reset_logs(int $site_id) {
        $resp = self::send_to_child($site_id, ['sig'=>self::SIG, 'action'=>'reset_logs']);
        if (is_wp_error($resp)) return [];
        return $resp['mwalx'] ?? [];
    }
}
