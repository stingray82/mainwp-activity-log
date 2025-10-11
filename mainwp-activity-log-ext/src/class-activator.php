<?php
namespace RUP_MAINWP_MWALX\MWALX;

if (!defined('ABSPATH')) exit;

/**
 * Registers the extension and renders the Site tab (logs + filters + per-site settings).
 *
 */
class RUP_MAINWP_MWALX_Activator {
    protected $mainwpActivated = false;
    protected $childEnabled    = false;
    protected $childKey        = false;
    protected $childFile;

    public function __construct() {
        // MAINWP identity must be the absolute path to the MAIN plugin file.
        $this->childFile = RUP_MAINWP_MWALX_MWALX_MAIN_FILE;

        add_filter('mainwp_getextensions',     [$this, 'get_extension_definition']);
        $this->mainwpActivated = apply_filters('mainwp_activated_check', false);
        if (false !== $this->mainwpActivated) {
            $this->activate_plugin();
        } else {
            add_action('mainwp_activated', [$this, 'activate_plugin']);
        }

        add_filter('mainwp_getsubpages_sites', [$this, 'register_site_tab_legacy']);
    }

    public function get_extension_definition($exts) {
        $exts[] = [
            // MUST match what you pass to extension_enabled_check and pageheader/footer:
            'plugin'   => RUP_MAINWP_MWALX_MWALX_MAIN_FILE,
            'api'      => 'mainwp-activity-log-ext',

            // Shows a card on MainWP → Extensions (optional metadata)
            'name'        => 'Activity Log (activitylog.io)',                        
            'mainwp'   => true,
            'callback' => [$this, 'render_extensions_page'],
            'sitetab'  => true, // we also register an actual per-site tab below

            'page_title' => 'Activity Log',
            'slug'       => 'Activity-Log',
        ];
        return $exts;
    }

    public function activate_plugin() {
        $this->mainwpActivated = apply_filters('mainwp_activated_check', $this->mainwpActivated);
        // MUST be the same identity as in get_extension_definition('plugin')
        $this->childEnabled    = apply_filters('mainwp_extension_enabled_check', RUP_MAINWP_MWALX_MWALX_MAIN_FILE);
        $this->childKey        = (is_array($this->childEnabled) && !empty($this->childEnabled['key'])) ? $this->childEnabled['key'] : false;
    }

    public function render_extensions_page() {
        // MUST pass the same identity here too
        do_action('mainwp_pageheader_extensions', RUP_MAINWP_MWALX_MWALX_MAIN_FILE);
        echo '<div class="wrap"><h1>Activity Log</h1><p>Open any Site and select the “Activity Log” tab.</p></div>';
        do_action('mainwp_pagefooter_extensions', RUP_MAINWP_MWALX_MWALX_MAIN_FILE);
    }

    public function register_site_tab_legacy($subpages) {
        $subpages[] = [
            'title'    => 'Activity Log',
            'slug'     => 'Activity-Log',
            'callback' => [$this, 'render_site_tab_legacy'],
            // again: identity must match
            'plugin'   => RUP_MAINWP_MWALX_MWALX_MAIN_FILE,
        ];
        return $subpages;
    }

    public function render_site_tab_legacy() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'mainwp-activity-log-ext'));
        }

        // Accept either ?id or ?ids (varies by MainWP builds / views).
        $site_id = isset($_GET['id']) ? (int) $_GET['id'] : (isset($_GET['ids']) ? (int) $_GET['ids'] : 0);

        // Preserve MainWP routing args so forms/pagination stay on this site tab.
        $raw_get  = array_map('sanitize_text_field', (array) wp_unslash($_GET));
        $keepable = ['page','dashboard','id','ids','tab','tab_id','tab_slug','websiteid'];
        $keep     = [];
        foreach ($keepable as $k) { if (isset($raw_get[$k])) $keep[$k] = $raw_get[$k]; }

        // Read POST (filters submitted by POST to avoid WAF/mod_security false positives on IPs)
        $raw_post = array_map('sanitize_text_field', (array) wp_unslash($_POST));
        $use_post = !empty($raw_post['mwalx_do_filter'])
            && isset($raw_post['mwalx_filter_nonce'])
            && wp_verify_nonce($raw_post['mwalx_filter_nonce'], 'mwalx_filter');

        do_action('mainwp_pageheader_sites', 'MWALXActivityLog');
        echo '<div id="mainwp_tab_MWALX_ActivityLog_container" class="ui segment">';

        if (!$site_id) {
            echo '<div class="notice notice-warning"><p>'
               . esc_html__('No child site selected (open from the site’s details screen).', 'mainwp-activity-log-ext')
               . '</p></div>';
            echo '</div>';
            do_action('mainwp_pagefooter_sites', 'MWALXActivityLog');
            return;
        }

        /* ===================== INLINE SETTINGS SAVE (unchanged) ===================== */
        if (isset($_POST['mwalx_save_site_settings']) && check_admin_referer('mwalx_site_settings')) {
            $payload = [
                'logs_lifespan'     => sanitize_text_field($_POST['logs_lifespan'] ?? ''),
                'logs_failed_login' => (($_POST['logs_failed_login'] ?? 'yes') === 'no') ? 'no' : 'yes',
                'logs_email'        => (($_POST['logs_email'] ?? 'yes') === 'no') ? 'no' : 'yes',
                'ip_source'         => sanitize_text_field($_POST['ip_source'] ?? 'REMOTE_ADDR'),
            ];
            $updated = RUP_MAINWP_MWALX_Connector::push_settings($site_id, $payload);
            echo !empty($updated)
                ? '<div class="notice notice-success"><p>Settings saved on child site.</p></div>'
                : '<div class="notice notice-error"><p>Failed to save settings on child site.</p></div>';
        }

        $state = RUP_MAINWP_MWALX_Connector::get_state($site_id);
        $set   = is_array($state['settings'] ?? null) ? $state['settings'] : [
            'logs_lifespan'     => '',
            'logs_failed_login' => 'yes',
            'logs_email'        => 'yes',
            'ip_source'         => 'REMOTE_ADDR',
        ];

        /* ===================== FILTERS & PAGINATION ===================== */
        $pp_options = [10,25,50,100];

        // Prefer POST for "Show" (per-page); reset to page 1 on a new POST filter submit.
        if ($use_post) {
            $pp    = isset($raw_post['mwalx_pp']) ? max(1, (int) $raw_post['mwalx_pp']) : 10;
            $paged = 1; // new filter run -> start from page 1
        } else {
            $pp    = isset($raw_get['mwalx_pp']) ? max(1, (int) $raw_get['mwalx_pp']) : 10;
            $paged = isset($raw_get['mwalx_paged']) ? max(1, (int) $raw_get['mwalx_paged']) : 1;
        }
        if (!in_array($pp, $pp_options, true)) $pp = 10;

        // UI fields (prefer POST when present)
        $ui = [
            's'      => $use_post ? ($raw_post['mwalx_s']     ?? '') : ($raw_get['mwalx_s']     ?? ''),
            'user'   => $use_post ? ($raw_post['mwalx_user']  ?? '') : ($raw_get['mwalx_user']  ?? ''), // local-only on v1
            'ip'     => $use_post ? ($raw_post['mwalx_ip']    ?? '') : ($raw_get['mwalx_ip']    ?? ''),
            'topic'  => $use_post ? ($raw_post['mwalx_topic'] ?? '') : ($raw_get['mwalx_topic'] ?? ''),
            'action' => $use_post ? ($raw_post['mwalx_action']?? '') : ($raw_get['mwalx_action']?? ''),
            'from'   => $use_post ? ($raw_post['mwalx_from']  ?? '') : ($raw_get['mwalx_from']  ?? ''),
            'to'     => $use_post ? ($raw_post['mwalx_to']    ?? '') : ($raw_get['mwalx_to']    ?? ''),
        ];

        // Server filters (v1 child: do NOT map user into 's')
        $flt = [
            's'         => $ui['s'],
            'ip'        => $ui['ip'],
            'topic'     => $ui['topic'],
            'action'    => $ui['action'],
            'date_from' => $ui['from'],
            'date_to'   => $ui['to'],
            'per_page'  => $pp,
            'paged'     => $paged,
        ];

        // Fetch current page
        $list  = RUP_MAINWP_MWALX_Connector::fetch_logs($site_id, $flt);
        $rows  = is_array($list['items'] ?? null) ? $list['items'] : [];
        $tot   = (int)($list['total'] ?? 0);

        // Local refinement to cover user filter and make others forgiving
        $refine = function(array $rows, array $ui): array {
            if (empty($rows)) return $rows;
            $nu = strtolower(trim((string)$ui['user']));
            $ni = strtolower(trim((string)$ui['ip']));
            $nt = strtolower(trim((string)$ui['topic']));
            $na = strtolower(trim((string)$ui['action']));
            $to_lc = static function($v){ return is_string($v) ? strtolower($v) : ''; };

            if ($nu !== '' || $ni !== '' || $nt !== '' || $na !== '') {
                $rows = array_values(array_filter($rows, function($r) use ($nu,$ni,$nt,$na,$to_lc) {
                    $ok = true;
                    if ($nu !== '') {
                        $hay = $to_lc($r['user_display'] ?? '') . ' ' . $to_lc($r['meta'] ?? '') . ' ' . $to_lc($r['context'] ?? '');
                        $ok = $ok && ($hay !== '' && strpos($hay, $nu) !== false);
                    }
                    if ($ok && $ni !== '')  $ok = (strpos($to_lc($r['ip'] ?? ''),     $ni) !== false);
                    if ($ok && $nt !== '')  $ok = (strpos($to_lc($r['topic'] ?? ''),  $nt) !== false);
                    if ($ok && $na !== '')  $ok = (strpos($to_lc($r['action'] ?? ''), $na) !== false);
                    return $ok;
                }));
            }
            return $rows;
        };
        $rows = $refine($rows, $ui);

        // Pages (use server total so nav is consistent)
        $pages = max(1, (int)ceil($tot / max(1, (int)$pp)));

        // Facets (from refined rows)
        $facet_ips = $facet_topics = $facet_actions = $facet_users = [];
        foreach ($rows as $r) {
            if (!empty($r['ip']))           $facet_ips[$r['ip']] = true;
            if (!empty($r['topic']))        $facet_topics[$r['topic']] = true;
            if (!empty($r['action']))       $facet_actions[$r['action']] = true;
            if (!empty($r['user_display'])) $facet_users[$r['user_display']] = true;
        }
        $facet_ips     = array_keys($facet_ips);
        $facet_topics  = array_keys($facet_topics);
        $facet_actions = array_keys($facet_actions);
        $facet_users   = array_keys($facet_users);
        sort($facet_ips); sort($facet_topics); sort($facet_actions); sort($facet_users);

        // IP source choices
        $ip_sources = [
            'REMOTE_ADDR'              => 'REMOTE_ADDR',
            'HTTP_CF_CONNECTING_IP'    => 'HTTP_CF_CONNECTING_IP',
            'HTTP_TRUE_CLIENT_IP'      => 'HTTP_TRUE_CLIENT_IP',
            'HTTP_CLIENT_IP'           => 'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR'     => 'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED'         => 'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP' => 'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR'       => 'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED'           => 'HTTP_FORWARDED',
            'no-collect-ip'            => 'Do not collect IP',
        ];

        ?>
        <style>
        #mwalx-wrap .panel{ background:#fff; border:1px solid #ddd; border-radius:10px; padding:16px; margin:0 0 18px; }
        #mwalx-wrap table{ width:100%; border-collapse:collapse; }
        #mwalx-wrap th,#mwalx-wrap td{ padding:10px; border:1px solid #e5e5e5; text-align:left; vertical-align:top; }
        #mwalx-wrap th{ background:#f8f9fa; }
        #mwalx-wrap .filters{ display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; margin:0 0 12px; }
        #mwalx-wrap .filters input[type="text"],
        #mwalx-wrap .filters input[type="date"],
        #mwalx-wrap .filters select{ min-width:140px; }
        #mwalx-wrap .pagination{ margin-top:10px; display:flex; gap:8px; align-items:center; }
        #mwalx-wrap .pagination a, #mwalx-wrap .pagination span{ padding:4px 8px; border:1px solid #ddd; border-radius:6px; text-decoration:none; }
        #mwalx-wrap .pagination .current{ background:#2271b1; color:#fff; border-color:#1b5a8a; }
        #mwalx-wrap .grid{ display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
        @media (max-width:900px){ #mwalx-wrap .grid{ grid-template-columns:1fr; } }
        #mwalx-wrap .btn-pad{ padding:8px 14px; }
        </style>

        <div id="mwalx-wrap">

            <div class="panel">
                <h3 style="margin-top:0">Activity Log</h3>
                <?php
                // "Clear" -> keep routing + page size, drop all filter args.
                $clear_args = $keep + ['mwalx_pp' => $pp];
                $clear_url = esc_url( add_query_arg( $clear_args, admin_url('admin.php') ) );
                ?>
                <!-- Filters: submit via POST to avoid WAF 403 on IPs -->
                <form method="post" class="filters">
                    <?php wp_nonce_field('mwalx_filter', 'mwalx_filter_nonce'); ?>
                    <input type="hidden" name="mwalx_do_filter" value="1" />

                    <?php foreach ($keep as $k => $v): ?>
                        <input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>" />
                    <?php endforeach; ?>

                    <input type="text"  name="mwalx_s"     placeholder="Search…" value="<?php echo esc_attr($ui['s']); ?>">

                    <input type="text"  name="mwalx_user"  placeholder="User"
                           list="mwalx_users" value="<?php echo esc_attr($ui['user']); ?>">
                    <datalist id="mwalx_users">
                        <?php foreach ($facet_users as $u) echo '<option value="'.esc_attr($u).'">'; ?>
                    </datalist>

                    <input type="text"  name="mwalx_ip"    placeholder="IP"
                           list="mwalx_ips" value="<?php echo esc_attr($ui['ip']); ?>">
                    <datalist id="mwalx_ips">
                        <?php foreach ($facet_ips as $ip) echo '<option value="'.esc_attr($ip).'">'; ?>
                    </datalist>

                    <input type="text"  name="mwalx_topic" placeholder="Topic"
                           list="mwalx_topics" value="<?php echo esc_attr($ui['topic']); ?>">
                    <datalist id="mwalx_topics">
                        <?php foreach ($facet_topics as $t) echo '<option value="'.esc_attr($t).'">'; ?>
                    </datalist>

                    <input type="text"  name="mwalx_action" placeholder="Action"
                           list="mwalx_actions" value="<?php echo esc_attr($ui['action']); ?>">
                    <datalist id="mwalx_actions">
                        <?php foreach ($facet_actions as $a) echo '<option value="'.esc_attr($a).'">'; ?>
                    </datalist>

                    <label>From <input type="date" name="mwalx_from" value="<?php echo esc_attr($ui['from']); ?>"></label>
                    <label>To <input type="date" name="mwalx_to" value="<?php echo esc_attr($ui['to']); ?>"></label>

                    <label>Show
                        <select name="mwalx_pp">
                            <?php foreach ([10,25,50,100] as $opt): ?>
                                <option value="<?php echo $opt; ?>" <?php selected($pp, $opt); ?>><?php echo $opt; ?></option>
                            <?php endforeach; ?>
                        </select>
                        rows
                    </label>

                    <button class="button">Filter</button>
                    <a class="button button-secondary" href="<?php echo $clear_url; ?>" style="margin-left:6px">Clear</a>
                </form>

                <?php if (empty($rows)) : ?>
                    <p>No matching log entries.</p>
                <?php else : ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>User</th>
                                <th>IP</th>
                                <th>Topic</th>
                                <th>Context</th>
                                <th>Meta</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?php echo esc_html($r['date'] ?? ''); ?></td>
                                <td><?php echo esc_html($r['user_display'] ?? ''); ?></td>
                                <td><?php echo esc_html($r['ip'] ?? ''); ?></td>
                                <td><?php echo esc_html($r['topic'] ?? ''); ?></td>
                                <td><?php echo esc_html($r['context'] ?? ''); ?></td>
                                <td><?php echo esc_html($r['meta'] ?? ''); ?></td>
                                <td><?php echo esc_html($r['action'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php
                    // Pagination keeps filters in query args
                    $base_args = $keep + [
                        'mwalx_s'     => $ui['s'],
                        'mwalx_user'  => $ui['user'],
                        'mwalx_ip'    => $ui['ip'],
                        'mwalx_topic' => $ui['topic'],
                        'mwalx_action'=> $ui['action'],
                        'mwalx_from'  => $ui['from'],
                        'mwalx_to'    => $ui['to'],
                        'mwalx_pp'    => $pp,
                    ];
                    echo '<div class="pagination">';
                    $current = isset($raw_get['mwalx_paged']) ? (int)$raw_get['mwalx_paged'] : 1;
                    for ($i = 1; $i <= $pages; $i++) {
                        $url = esc_url(add_query_arg(array_merge($base_args, ['mwalx_paged' => $i]), admin_url('admin.php')));
                        echo ($i === $current) ? '<span class="current">'.$i.'</span>' : '<a href="'.$url.'">'.$i.'</a>';
                    }
                    echo '</div>';
                    ?>
                <?php endif; ?>
            </div>

            <div class="panel">
                <h3 style="margin-top:0">Activity Log Settings (this site)</h3>
                <form class="ui form" method="post">
                    <?php wp_nonce_field('mwalx_site_settings'); ?>
                    <input type="hidden" name="mwalx_save_site_settings" value="1" />

                    <div class="grid">
                        <div>
                            <label>Keep logs for (days)</label>
                            <input type="number" min="0" name="logs_lifespan" value="<?php echo esc_attr($set['logs_lifespan']); ?>" />
                        </div>
                        <div>
                            <label>Visitor IP detected</label>
                            <select name="ip_source">
                                <?php
                                $ip_sources = [
                                    'REMOTE_ADDR'              => 'REMOTE_ADDR',
                                    'HTTP_CF_CONNECTING_IP'    => 'HTTP_CF_CONNECTING_IP',
                                    'HTTP_TRUE_CLIENT_IP'      => 'HTTP_TRUE_CLIENT_IP',
                                    'HTTP_CLIENT_IP'           => 'HTTP_CLIENT_IP',
                                    'HTTP_X_FORWARDED_FOR'     => 'HTTP_X_FORWARDED_FOR',
                                    'HTTP_X_FORWARDED'         => 'HTTP_X_FORWARDED',
                                    'HTTP_X_CLUSTER_CLIENT_IP' => 'HTTP_X_CLUSTER_CLIENT_IP',
                                    'HTTP_FORWARDED_FOR'       => 'HTTP_FORWARDED_FOR',
                                    'HTTP_FORWARDED'           => 'HTTP_FORWARDED',
                                    'no-collect-ip'            => 'Do not collect IP',
                                ];
                                foreach ($ip_sources as $v => $label): ?>
                                    <option value="<?php echo esc_attr($v); ?>" <?php selected($set['ip_source'], $v); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Keep Failed Login Logs</label>
                            <select name="logs_failed_login">
                                <option value="yes" <?php selected($set['logs_failed_login'], 'yes'); ?>>Keep</option>
                                <option value="no"  <?php selected($set['logs_failed_login'], 'no');  ?>>Don't keep</option>
                            </select>
                        </div>
                        <div>
                            <label>Keep email logs</label>
                            <select name="logs_email">
                                <option value="yes" <?php selected($set['logs_email'], 'yes'); ?>>Keep</option>
                                <option value="no"  <?php selected($set['logs_email'], 'no');  ?>>Don't keep</option>
                            </select>
                        </div>
                    </div>

                    <p><button class="button button-primary btn-pad" type="submit">Save settings on this site</button></p>
                </form>
            </div>

        </div>
        <?php
        echo '</div>';
        do_action('mainwp_pagefooter_sites', 'MWALXActivityLog');
    }

    public function get_child_key()  { return $this->childKey; }
    public function get_child_file() { return $this->childFile; }
}
