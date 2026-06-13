<?php

/**
 * Ofast X - Redirects Manager Module
 * Manage 301/302/307 redirects with activate/deactivate toggle
 * Import from other redirect plugins
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Redirects
{
    /**
     * Initialize module
     */
    public function init()
    {
        // NOTE: Module enabled check removed - core loader already verified this
        // before calling init(). See class-ofast-core.php is_module_enabled()

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_init', array($this, 'handle_form_submissions'));

        add_action('wp_ajax_ofast_toggle_redirect', array($this, 'ajax_toggle_redirect'));
        add_action('wp_ajax_ofast_delete_redirect', array($this, 'ajax_delete_redirect'));
        add_action('wp_ajax_ofast_import_redirects_from_plugin', array($this, 'ajax_import_from_plugin'));
        add_action('wp_ajax_ofast_export_redirects', array($this, 'ajax_export_redirects'));

        add_action('template_redirect', array($this, 'process_redirects'), 1);
    }

    /**
     * Enqueue scripts and styles for redirects admin page
     */
    public function enqueue_scripts($hook)
    {
        if (strpos($hook, 'ofast-redirects') === false) {
            return;
        }

        wp_enqueue_style(
            'ofast-redirects',
            plugins_url('assets/redirects.css', __FILE__),
            array(),
            OFAST_X_VERSION
        );

        wp_enqueue_script(
            'ofast-redirects',
            plugins_url('assets/redirects.js', __FILE__),
            array('jquery'),
            OFAST_X_VERSION,
            true
        );

        wp_localize_script('ofast-redirects', 'ofastRedirects', array(
            'toggleNonce' => wp_create_nonce('ofast_redirect_toggle'),
            'deleteNonce' => wp_create_nonce('ofast_redirect_delete'),
            'importNonce' => wp_create_nonce('ofast_import_redirects'),
            'exportNonce' => wp_create_nonce('ofast_export_redirects'),
            'i18n' => array(
                'on'            => __('ON', 'ofast-x'),
                'off'           => __('OFF', 'ofast-x'),
                'confirmDelete' => __('Are you sure you want to delete this redirect?', 'ofast-x'),
                'importing'     => __('Importing...', 'ofast-x'),
                'exporting'     => __('Exporting...', 'ofast-x'),
                'importFailed'  => __('Import failed: ', 'ofast-x'),
            ),
        ));
    }

    /**
     * Add admin submenu page
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            __('Redirects Manager', 'ofast-x'),
            __('Redirects', 'ofast-x'),
            'manage_options',
            'ofast-redirects',
            array($this, 'render_page')
        );
    }

    /**
     * Handle add/edit form submissions
     */
    public function handle_form_submissions()
    {
        if (!isset($_POST['ofast_save_redirect'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('ofast_redirect_save', '_wpnonce');

        global $wpdb;
        $table             = $wpdb->prefix . 'ofast_redirects';
        $priority_supported = $this->ensure_redirects_priority_schema();

        $id         = isset($_POST['redirect_id']) ? intval($_POST['redirect_id']) : 0;
        $source_url = isset($_POST['source_url']) ? sanitize_text_field(wp_unslash($_POST['source_url'])) : '';
        $raw_target = isset($_POST['target_url']) ? wp_unslash($_POST['target_url']) : '';
        $target_url = $this->normalize_target_url($raw_target);
        $type       = $this->normalize_redirect_type(isset($_POST['redirect_type']) ? sanitize_text_field(wp_unslash($_POST['redirect_type'])) : '301');
        $is_regex   = isset($_POST['is_regex']) ? 1 : 0;
        $active     = isset($_POST['active']) ? 1 : 0;
        $priority   = isset($_POST['priority']) ? max(1, min(9999, intval($_POST['priority']))) : 10;

        if (empty($source_url)) {
            add_settings_error('ofast_redirects', 'error', __('Source URL is required.', 'ofast-x'), 'error');
            return;
        }

        if (empty($target_url)) {
            add_settings_error('ofast_redirects', 'error', __('Target URL is required.', 'ofast-x'), 'error');
            return;
        }

        $target_validation = $this->validate_redirect_target($target_url);
        if (is_wp_error($target_validation)) {
            add_settings_error('ofast_redirects', 'error', $target_validation->get_error_message(), 'error');
            return;
        }

        if ($is_regex) {
            $source_url = $this->sanitize_regex($source_url);
            if ($source_url === false || !$this->is_valid_regex($source_url)) {
                add_settings_error('ofast_redirects', 'error', __('Invalid regex pattern. Please review your expression.', 'ofast-x'), 'error');
                return;
            }
        } else {
            if (strpos($source_url, '/') !== 0) {
                $source_url = '/' . ltrim($source_url, '/');
            }
            $source_path = parse_url($source_url, PHP_URL_PATH);
            $target_path = parse_url($target_url, PHP_URL_PATH);
            if (!empty($source_path) && !empty($target_path) && $source_path === $target_path) {
                add_settings_error('ofast_redirects', 'error', __('Source and target cannot be the same (redirect loop).', 'ofast-x'), 'error');
                return;
            }
        }

        if ($id > 0) {
            $update_data = array(
                'source_url' => $source_url,
                'target_url' => $target_url,
                'type'       => $type,
                'is_regex'   => $is_regex,
                'active'     => $active,
            );
            if ($priority_supported) {
                $update_data['priority'] = $priority;
            }
            $wpdb->update($table, $update_data, array('id' => $id));
            add_settings_error('ofast_redirects', 'success', __('Redirect updated successfully.', 'ofast-x'), 'success');
        } else {
            $insert_data = array(
                'source_url' => $source_url,
                'target_url' => $target_url,
                'type'       => $type,
                'is_regex'   => $is_regex,
                'active'     => $active,
                'hits'       => 0,
                'created_at' => current_time('mysql'),
                'created_by' => get_current_user_id(),
            );
            if ($priority_supported) {
                $insert_data['priority'] = $priority;
            }
            $wpdb->insert($table, $insert_data);
            add_settings_error('ofast_redirects', 'success', __('Redirect added successfully.', 'ofast-x'), 'success');
        }

        $this->clear_redirects_cache();

        wp_safe_redirect(admin_url('admin.php?page=ofast-redirects'));
        exit;
    }

    /**
     * Process redirects on frontend — runs on every page load via template_redirect.
     * DB query only happens on transient miss (every 5 minutes at most).
     */
    public function process_redirects()
    {
        if (is_admin()) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_redirects';

        $request_uri  = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $request_path = parse_url($request_uri, PHP_URL_PATH);

        // Ensure last_accessed and priority columns exist.
        // Uses a static variable internally — DB work only happens once ever per request.
        $priority_supported = $this->ensure_redirects_schema();

        $redirects = get_transient('ofast_redirects_cache');

        // !is_array() catches false (cache miss), null (DB error on first load),
        // and any other garbage that should not be treated as a valid empty cache.
        if (!is_array($redirects)) {
            $order_by  = $priority_supported ? 'priority ASC, id ASC' : 'id ASC';
            $redirects = $wpdb->get_results("SELECT * FROM $table WHERE active = 1 ORDER BY {$order_by}");
            if (!is_array($redirects)) {
                $redirects = array(); // DB error — do not cache broken result
            } else {
                set_transient('ofast_redirects_cache', $redirects, 300);
            }
        }

        if (empty($redirects)) {
            return;
        }

        foreach ($redirects as $redirect) {
            $matched = false;
            $target  = '';

            if ($redirect->is_regex) {
                $pattern = $this->sanitize_regex($redirect->source_url);
                if ($pattern === false) {
                    continue;
                }
                $matches = array();
                if ($this->regex_match($pattern, $request_path, $matches)) {
                    $matched = true;
                    $target  = $redirect->target_url;
                    for ($i = 1; $i < count($matches); $i++) {
                        $target = str_replace('$' . $i, $matches[$i], $target);
                    }
                }
            } else {
                $source_normalized  = rtrim(strtolower($redirect->source_url), '/');
                $request_normalized = rtrim(strtolower($request_path), '/');

                if ($source_normalized === '') {
                    $source_normalized = '/';
                }
                if ($request_normalized === '') {
                    $request_normalized = '/';
                }

                if ($request_normalized === $source_normalized) {
                    $matched = true;
                    $target  = $redirect->target_url;
                }
            }

            if ($matched) {
                $target_validation = $this->validate_redirect_target($target);
                if (is_wp_error($target_validation)) {
                    continue;
                }

                $type = $this->normalize_redirect_type($redirect->type);

                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET hits = hits + 1, last_accessed = %s WHERE id = %d",
                    current_time('mysql'),
                    intval($redirect->id)
                ));

                if ($this->is_external_url($target)) {
                    wp_redirect($target, intval($type));
                } else {
                    wp_safe_redirect($target, intval($type));
                }
                exit;
            }
        }
    }

    /**
     * Clear the redirects transient cache
     */
    private function clear_redirects_cache()
    {
        delete_transient('ofast_redirects_cache');
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX: Toggle redirect active/inactive
     */
    public function ajax_toggle_redirect()
    {
        check_ajax_referer('ofast_redirect_toggle', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $id      = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $current = isset($_POST['active']) ? intval($_POST['active']) : 0;

        if ($id <= 0) {
            wp_send_json_error('Invalid ID');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_redirects';

        $new_active = $current ? 0 : 1;

        if ($new_active === 1) {
            $redirect = $wpdb->get_row($wpdb->prepare(
                "SELECT id, source_url, target_url, type, is_regex FROM {$table} WHERE id = %d",
                $id
            ));

            if (!$redirect) {
                wp_send_json_error('Redirect not found');
            }

            if ((int) $redirect->is_regex === 1) {
                $pattern = $this->sanitize_regex($redirect->source_url);
                if ($pattern === false || !$this->is_valid_regex($pattern)) {
                    wp_send_json_error('Cannot activate: invalid regex pattern.');
                }
            } else {
                $source_path = parse_url($redirect->source_url, PHP_URL_PATH);
                $target_path = parse_url($redirect->target_url, PHP_URL_PATH);
                if (!empty($source_path) && !empty($target_path) && $source_path === $target_path) {
                    wp_send_json_error('Cannot activate: source and target are the same path.');
                }
            }

            $target_validation = $this->validate_redirect_target($redirect->target_url);
            if (is_wp_error($target_validation)) {
                wp_send_json_error('Cannot activate: ' . $target_validation->get_error_message());
            }
        }

        $wpdb->update($table, array('active' => $new_active), array('id' => $id));
        $this->clear_redirects_cache();

        wp_send_json_success(array('active' => $new_active));
    }

    /**
     * AJAX: Delete redirect
     */
    public function ajax_delete_redirect()
    {
        check_ajax_referer('ofast_redirect_delete', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($id <= 0) {
            wp_send_json_error('Invalid ID');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_redirects';

        $wpdb->delete($table, array('id' => $id));
        $this->clear_redirects_cache();

        wp_send_json_success();
    }

    /**
     * AJAX: Import from other redirect plugins
     */
    public function ajax_import_from_plugin()
    {
        check_ajax_referer('ofast_import_redirects', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $plugin   = isset($_POST['plugin']) ? sanitize_text_field(wp_unslash($_POST['plugin'])) : '';
        $imported = 0;

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_redirects';

        switch ($plugin) {
            case 'redirection':
                $source_table = $wpdb->prefix . 'redirection_items';
                if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $source_table)) === $source_table) {
                    $items = $wpdb->get_results("SELECT url, action_data, regex FROM {$source_table} WHERE action_type = 'url'");
                    foreach ($items as $item) {
                        $insert_data = $this->prepare_redirect_insert_data($item->url, $item->action_data, '301', $item->regex ? 1 : 0, 0, 10);
                        if (is_wp_error($insert_data)) {
                            continue;
                        }
                        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE source_url = %s", $insert_data['source_url']));
                        if (!$exists) {
                            $wpdb->insert($table, $insert_data);
                            $imported++;
                        }
                    }
                }
                break;

            case 'safe-redirect-manager':
                $redirects = get_posts(array('post_type' => 'redirect_rule', 'posts_per_page' => -1, 'post_status' => 'publish'));
                foreach ($redirects as $redirect) {
                    $source = get_post_meta($redirect->ID, '_redirect_rule_from', true);
                    $target = get_post_meta($redirect->ID, '_redirect_rule_to', true);
                    $status = get_post_meta($redirect->ID, '_redirect_rule_status_code', true);
                    if ($source && $target) {
                        $insert_data = $this->prepare_redirect_insert_data($source, $target, $status ?: '301', 0, 0, 10);
                        if (is_wp_error($insert_data)) {
                            continue;
                        }
                        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE source_url = %s", $insert_data['source_url']));
                        if (!$exists) {
                            $wpdb->insert($table, $insert_data);
                            $imported++;
                        }
                    }
                }
                break;

            case 'simple-301':
                $redirects = get_option('301_redirects', array());
                if (is_array($redirects)) {
                    foreach ($redirects as $source => $target) {
                        $insert_data = $this->prepare_redirect_insert_data($source, $target, '301', 0, 0, 10);
                        if (is_wp_error($insert_data)) {
                            continue;
                        }
                        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE source_url = %s", $insert_data['source_url']));
                        if (!$exists) {
                            $wpdb->insert($table, $insert_data);
                            $imported++;
                        }
                    }
                }
                break;

            case 'json':
                $json_data = isset($_POST['json_data']) ? wp_unslash($_POST['json_data']) : '';
                if (strlen($json_data) > 500000) {
                    wp_send_json_error('Import file too large.');
                }
                $data = json_decode($json_data, true);
                if ($data && isset($data['redirects']) && is_array($data['redirects'])) {
                    foreach ($data['redirects'] as $redirect) {
                        $insert_data = $this->prepare_redirect_insert_data(
                            isset($redirect['source_url']) ? $redirect['source_url'] : '',
                            isset($redirect['target_url']) ? $redirect['target_url'] : '',
                            isset($redirect['type']) ? $redirect['type'] : '301',
                            isset($redirect['is_regex']) ? intval($redirect['is_regex']) : 0,
                            0,
                            isset($redirect['priority']) ? intval($redirect['priority']) : 10
                        );
                        if (is_wp_error($insert_data)) {
                            continue;
                        }
                        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE source_url = %s", $insert_data['source_url']));
                        if (!$exists) {
                            $wpdb->insert($table, $insert_data);
                            $imported++;
                        }
                    }
                }
                break;
        }

        $this->clear_redirects_cache();

        wp_send_json_success(array(
            'imported' => $imported,
            'message'  => $imported > 0
                ? "{$imported} redirect(s) imported as INACTIVE. Review and activate when ready."
                : 'No new redirects found to import.',
        ));
    }

    /**
     * AJAX: Export redirects as JSON
     */
    public function ajax_export_redirects()
    {
        check_ajax_referer('ofast_export_redirects', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table             = $wpdb->prefix . 'ofast_redirects';
        $priority_supported = $this->ensure_redirects_priority_schema();
        $select_fields     = $priority_supported
            ? 'source_url, target_url, type, is_regex, active, priority'
            : 'source_url, target_url, type, is_regex, active';

        $selected_ids = isset($_POST['ids']) ? array_filter(array_map('intval', (array) $_POST['ids'])) : array();

        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '%d'));
            $redirects    = $wpdb->get_results($wpdb->prepare(
                "SELECT {$select_fields} FROM $table WHERE id IN ($placeholders)",
                $selected_ids
            ));
        } else {
            $redirects = $wpdb->get_results("SELECT {$select_fields} FROM $table");
        }

        wp_send_json_success(array(
            'plugin'      => 'ofast-x',
            'version'     => '1.0',
            'exported_at' => current_time('mysql'),
            'site_url'    => get_site_url(),
            'redirects'   => $redirects,
        ));
    }

    // =========================================================================
    // ADMIN PAGE RENDER
    // =========================================================================

    /**
     * Detect which redirect plugins have importable data
     */
    private function detect_import_sources()
    {
        global $wpdb;
        $sources = array();

        $redirection_table = $wpdb->prefix . 'redirection_items';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $redirection_table)) === $redirection_table) {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$redirection_table} WHERE action_type = 'url'");
            if ($count > 0) {
                $sources['redirection'] = array('name' => 'Redirection Plugin', 'count' => $count);
            }
        }

        $srm_count = wp_count_posts('redirect_rule');
        if (isset($srm_count->publish) && $srm_count->publish > 0) {
            $sources['safe-redirect-manager'] = array('name' => 'Safe Redirect Manager', 'count' => $srm_count->publish);
        }

        $simple_301 = get_option('301_redirects', array());
        if (!empty($simple_301)) {
            $sources['simple-301'] = array('name' => 'Simple 301 Redirects', 'count' => count($simple_301));
        }

        return $sources;
    }

    /**
     * Render admin page
     */
    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $table             = $wpdb->prefix . 'ofast_redirects';
        $priority_supported = $this->ensure_redirects_priority_schema();

        $editing      = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $edit_redirect = null;
        if ($editing) {
            $edit_redirect = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $editing));
        }

        $order_by      = $priority_supported ? 'priority ASC, id DESC' : 'id DESC';
        $redirects     = $wpdb->get_results("SELECT * FROM $table ORDER BY {$order_by}");
        $import_sources = $this->detect_import_sources();
        $regex_help    = __('Use "Use Regular Expression" to save this redirect as a regex rule. The regex badge in the table only appears for redirects saved with that option.', 'ofast-x');

        settings_errors('ofast_redirects');

        if (class_exists('Ofast_X_Dropdown')) {
            echo Ofast_X_Dropdown::render_assets();
        }
?>
        <div class="wrap ofast-redirects-page">
            <!-- Header -->
            <div class="ofast-header">
                <div class="ofast-header-icon">
                    <span class="dashicons dashicons-randomize"></span>
                </div>
                <div class="ofast-header-content">
                    <h1><?php esc_html_e('Redirects Manager', 'ofast-x'); ?></h1>
                    <p><?php esc_html_e('Manage URL redirects with 301, 302, or 307 status codes. Activate/deactivate as needed.', 'ofast-x'); ?></p>
                </div>
            </div>

            <!-- Columns Container -->
            <div class="ofast-redirects-columns">

                <!-- Left Column: Add/Edit -->
                <div class="ofast-column-left">
                    <div class="ofast-card">
                        <h2 style="margin-top: 0; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">
                            <?php echo $editing ? esc_html__('Edit Redirect', 'ofast-x') : esc_html__('Add New Redirect', 'ofast-x'); ?>
                        </h2>
                        <form method="post" class="ofast-redirect-form">
                            <?php wp_nonce_field('ofast_redirect_save', '_wpnonce'); ?>
                            <?php if ($editing): ?>
                                <input type="hidden" name="redirect_id" value="<?php echo esc_attr($editing); ?>">
                            <?php endif; ?>

                            <table class="form-table" style="margin-top: 0;">
                                <tr>
                                    <th><label for="source_url"><?php esc_html_e('Source URL', 'ofast-x'); ?></label></th>
                                    <td>
                                        <input type="text" name="source_url" id="source_url" class="regular-text"
                                            value="<?php echo $edit_redirect ? esc_attr($edit_redirect->source_url) : ''; ?>"
                                            placeholder="/old-page" required>
                                        <p class="description"><?php esc_html_e('The URL path to redirect from (e.g., /old-page)', 'ofast-x'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="target_url"><?php esc_html_e('Target URL', 'ofast-x'); ?></label></th>
                                    <td>
                                        <input type="text" name="target_url" id="target_url" class="regular-text"
                                            value="<?php echo $edit_redirect ? esc_attr($edit_redirect->target_url) : ''; ?>"
                                            placeholder="<?php echo esc_attr(home_url('/new-page')); ?>" required>
                                        <p class="description"><?php esc_html_e('The URL to redirect to (full URL or relative path)', 'ofast-x'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="redirect_type"><?php esc_html_e('Redirect Type', 'ofast-x'); ?></label></th>
                                    <td>
                                        <select name="redirect_type" id="redirect_type" class="ofast-dropdown-native">
                                            <option value="301" <?php selected($edit_redirect ? $edit_redirect->type : '', '301'); ?>><?php esc_html_e('301 - Permanent', 'ofast-x'); ?></option>
                                            <option value="302" <?php selected($edit_redirect ? $edit_redirect->type : '', '302'); ?>><?php esc_html_e('302 - Temporary', 'ofast-x'); ?></option>
                                            <option value="307" <?php selected($edit_redirect ? $edit_redirect->type : '', '307'); ?>><?php esc_html_e('307 - Temporary (Preserve Method)', 'ofast-x'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <?php if ($priority_supported): ?>
                                    <tr>
                                        <th><label for="priority"><?php esc_html_e('Priority', 'ofast-x'); ?></label></th>
                                        <td>
                                            <?php $priority_value = ($edit_redirect && isset($edit_redirect->priority)) ? intval($edit_redirect->priority) : 10; ?>
                                            <input type="number" name="priority" id="priority" class="small-text"
                                                min="1" max="9999" step="1" value="<?php echo esc_attr($priority_value); ?>">
                                            <p class="description"><?php esc_html_e('Lower number runs first when multiple redirects could match.', 'ofast-x'); ?></p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <tr>
                                    <th><?php esc_html_e('Options', 'ofast-x'); ?></th>
                                    <td>
                                        <label class="ofast-help-label" style="display: block; margin-bottom: 10px;">
                                            <input type="checkbox" name="is_regex" value="1" <?php checked($edit_redirect ? $edit_redirect->is_regex : false); ?>>
                                            <?php esc_html_e('Use Regular Expression', 'ofast-x'); ?>
                                            <span class="ofast-help-tooltip" tabindex="0"
                                                data-tooltip="<?php echo esc_attr($regex_help); ?>"
                                                aria-label="<?php echo esc_attr($regex_help); ?>">?</span>
                                        </label>
                                        <label style="display: block;">
                                            <input type="checkbox" name="active" value="1" <?php checked($edit_redirect ? $edit_redirect->active : false); ?>>
                                            <?php esc_html_e('Activate immediately', 'ofast-x'); ?>
                                        </label>
                                    </td>
                                </tr>
                            </table>

                            <p class="submit" style="margin-bottom: 0; padding-bottom: 0;">
                                <button type="submit" name="ofast_save_redirect" class="button button-primary button-large ofast-redirect-primary-action">
                                    <?php echo $editing ? esc_html__('Update Redirect', 'ofast-x') : esc_html__('Add Redirect', 'ofast-x'); ?>
                                </button>
                                <?php if ($editing): ?>
                                    <a href="?page=ofast-redirects" class="button button-large"><?php esc_html_e('Cancel', 'ofast-x'); ?></a>
                                <?php endif; ?>
                            </p>
                        </form>
                    </div>
                </div>

                <!-- Right Column: Import/Export -->
                <div class="ofast-column-right">
                    <?php if (!empty($import_sources)): ?>
                        <div class="ofast-card" style="margin-bottom: 20px;">
                            <h3 style="margin-top: 0;"><?php esc_html_e('Import from Plugins', 'ofast-x'); ?></h3>
                            <p class="description"><?php esc_html_e('Imported redirects will be set to INACTIVE.', 'ofast-x'); ?></p>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px;">
                                <?php foreach ($import_sources as $key => $source): ?>
                                    <button type="button" class="button import-from-plugin" data-plugin="<?php echo esc_attr($key); ?>">
                                        <?php
                                        /* translators: %1$s: plugin name, %2$d: number of redirects */
                                        printf(esc_html__('Import from %1$s (%2$d)', 'ofast-x'), esc_html($source['name']), intval($source['count']));
                                        ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="ofast-card">
                        <h3 style="margin-top: 0; margin-bottom: 15px;"><?php esc_html_e('Import/Export', 'ofast-x'); ?></h3>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: 600;"><?php esc_html_e('Import from JSON', 'ofast-x'); ?></label>
                            <div style="display: flex; gap: 10px;">
                                <input type="file" id="import-json-file" accept=".json" style="flex: 1;">
                            </div>
                        </div>

                        <div style="border-top: 1px solid #eee; padding-top: 20px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: 600;"><?php esc_html_e('Export Redirects', 'ofast-x'); ?></label>
                            <button type="button" id="export-redirects" class="button"><?php esc_html_e('Export All to JSON', 'ofast-x'); ?></button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Redirects List -->
            <?php if (!empty($redirects)): ?>
                <div class="ofast-card" style="margin-top: 20px;">
                    <h3 style="margin-top: 0;">
                        <?php esc_html_e('All Redirects', 'ofast-x'); ?>
                        <span style="font-weight: normal; color: #666; font-size: 14px;">
                            (<?php echo esc_html(count($redirects)); ?> <?php esc_html_e('total', 'ofast-x'); ?>)
                        </span>
                    </h3>

                    <div style="overflow-x: auto; max-width: 100%;">
                        <table class="wp-list-table widefat fixed striped" style="min-width: 900px;">
                            <thead>
                                <tr>
                                    <th style="width: 30px;"><input type="checkbox" id="select-all-redirects"></th>
                                    <th><?php esc_html_e('Source URL', 'ofast-x'); ?></th>
                                    <th><?php esc_html_e('Target URL', 'ofast-x'); ?></th>
                                    <th style="width: 80px;"><?php esc_html_e('Type', 'ofast-x'); ?></th>
                                    <?php if ($priority_supported): ?>
                                        <th style="width: 75px;"><?php esc_html_e('Priority', 'ofast-x'); ?></th>
                                    <?php endif; ?>
                                    <th style="width: 60px;"><?php esc_html_e('Hits', 'ofast-x'); ?></th>
                                    <th style="width: 80px;"><?php esc_html_e('Status', 'ofast-x'); ?></th>
                                    <th style="width: 120px;"><?php esc_html_e('Actions', 'ofast-x'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($redirects as $redirect): ?>
                                    <tr>
                                        <td><input type="checkbox" class="redirect-checkbox" value="<?php echo esc_attr($redirect->id); ?>"></td>
                                        <td>
                                            <code style="font-size: 12px;"><?php echo esc_html($redirect->source_url); ?></code>
                                            <?php if ($redirect->is_regex): ?>
                                                <span class="ofast-redirect-regex-badge">regex</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="word-break: break-all; font-size: 12px;"><?php echo esc_html($redirect->target_url); ?></td>
                                        <td>
                                            <span style="background: <?php echo esc_attr($redirect->type == '301' ? '#d4edda' : '#fff3cd'); ?>; color: <?php echo esc_attr($redirect->type == '301' ? '#155724' : '#856404'); ?>; padding: 2px 8px; border-radius: 3px; font-size: 11px;">
                                                <?php echo esc_html($redirect->type); ?>
                                            </span>
                                        </td>
                                        <?php if ($priority_supported): ?>
                                            <td style="font-size: 12px; font-weight: 600;"><?php echo isset($redirect->priority) ? intval($redirect->priority) : 10; ?></td>
                                        <?php endif; ?>
                                        <td style="font-size: 12px;"><?php echo number_format($redirect->hits); ?></td>
                                        <td>
                                            <button class="button button-small ofast-redirect-toggle <?php echo $redirect->active ? 'button-primary' : ''; ?>"
                                                data-id="<?php echo esc_attr($redirect->id); ?>"
                                                data-active="<?php echo esc_attr($redirect->active); ?>"
                                                style="min-width: 50px;">
                                                <?php echo $redirect->active ? esc_html__('ON', 'ofast-x') : esc_html__('OFF', 'ofast-x'); ?>
                                            </button>
                                        </td>
                                        <td>
                                            <a href="?page=ofast-redirects&edit=<?php echo esc_attr($redirect->id); ?>" class="button button-small">
                                                <?php esc_html_e('Edit', 'ofast-x'); ?>
                                            </a>
                                            <button class="button button-small ofast-redirect-delete"
                                                data-id="<?php echo esc_attr($redirect->id); ?>"
                                                style="color: #dc3545;">
                                                <?php esc_html_e('Delete', 'ofast-x'); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <div class="ofast-redirects-empty">
                    <p><?php esc_html_e('No redirects yet. Add your first redirect above!', 'ofast-x'); ?></p>
                </div>
            <?php endif; ?>
        </div>
<?php
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Normalize redirect target URL, preserving relative paths.
     */
    private function normalize_target_url($raw_target)
    {
        $raw_target = trim((string) $raw_target);
        if ($raw_target === '') {
            return '';
        }
        if (strpos($raw_target, '/') === 0) {
            return sanitize_text_field($raw_target);
        }
        return esc_url_raw($raw_target);
    }

    /**
     * Normalize redirect type to one of the three supported values.
     */
    private function normalize_redirect_type($type)
    {
        $type = (string) $type;
        return in_array($type, array('301', '302', '307'), true) ? $type : '301';
    }

    /**
     * Build a sanitized redirect record for insert operations.
     */
    private function prepare_redirect_insert_data($source, $target, $type = '301', $is_regex = 0, $active = 0, $priority = 10)
    {
        $source_url = sanitize_text_field((string) $source);
        $target_url = $this->normalize_target_url($target);
        $type       = $this->normalize_redirect_type($type);
        $is_regex   = !empty($is_regex) ? 1 : 0;
        $active     = !empty($active) ? 1 : 0;
        $priority   = max(1, min(9999, intval($priority)));

        if ($source_url === '') {
            return new WP_Error('invalid_source', __('Source URL is required.', 'ofast-x'));
        }
        if ($target_url === '') {
            return new WP_Error('invalid_target', __('Target URL is required.', 'ofast-x'));
        }

        if ($is_regex) {
            $source_url = $this->sanitize_regex($source_url);
            if ($source_url === false || !$this->is_valid_regex($source_url)) {
                return new WP_Error('invalid_regex', __('Invalid regex pattern.', 'ofast-x'));
            }
        } else {
            if (strpos($source_url, '/') !== 0) {
                $source_url = '/' . ltrim($source_url, '/');
            }
            $source_path = parse_url($source_url, PHP_URL_PATH);
            $target_path = parse_url($target_url, PHP_URL_PATH);
            if (!empty($source_path) && !empty($target_path) && $source_path === $target_path) {
                return new WP_Error('redirect_loop', __('Source and target cannot be the same (redirect loop).', 'ofast-x'));
            }
        }

        $target_validation = $this->validate_redirect_target($target_url);
        if (is_wp_error($target_validation)) {
            return $target_validation;
        }

        $insert_data = array(
            'source_url' => $source_url,
            'target_url' => $target_url,
            'type'       => $type,
            'is_regex'   => $is_regex,
            'active'     => $active,
            'hits'       => 0,
            'created_at' => current_time('mysql'),
            'created_by' => get_current_user_id(),
        );

        if ($this->ensure_redirects_priority_schema()) {
            $insert_data['priority'] = $priority;
        }

        return $insert_data;
    }

    /**
     * Sanitize regex: length cap, null bytes, classic nested-quantifier ReDoS.
     */
    private function sanitize_regex($pattern)
    {
        $pattern = trim((string) $pattern);

        if ($pattern === '' || strlen($pattern) > 300) {
            return false;
        }

        // Block null bytes and control characters
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $pattern)) {
            return false;
        }

        // Block the four classic nested-quantifier ReDoS patterns and PHP recursive refs
        $dangerous = array(
            '/\(\.\*\)\*/',             // (.*)*
            '/\(\.\+\)\+/',             // (.+)+
            '/\(\.\*\)\+/',             // (.*)+
            '/\(\.\+\)\*/',             // (.+)*
            '/\(\?R\)|\(\?[0-9]+\)/',  // (?R) or (?1)
        );
        foreach ($dangerous as $d) {
            if (preg_match($d, $pattern)) {
                return false;
            }
        }

        // Collapse redundant quantifier runs (e.g. ** → *)
        $pattern = preg_replace('/(\+|\*|\?)\1+/', '$1', $pattern);

        return $pattern;
    }

    /**
     * Validate regex syntax by attempting a compile + empty-string match.
     */
    private function is_valid_regex($pattern)
    {
        $wrapped = $this->wrap_regex_pattern($pattern);
        if ($wrapped === false) {
            return false;
        }

        set_error_handler('__return_false');
        $result = @preg_match($wrapped, '');
        restore_error_handler();

        return $result !== false && preg_last_error() === PREG_NO_ERROR;
    }

    /**
     * Execute a regex match with capped PCRE limits.
     */
    private function regex_match($pattern, $subject, &$matches = array())
    {
        $wrapped = $this->wrap_regex_pattern($pattern);
        if ($wrapped === false || strlen($subject) > 2000) {
            return false;
        }

        $prev_backtrack = ini_get('pcre.backtrack_limit');
        $prev_recursion = ini_get('pcre.recursion_limit');
        @ini_set('pcre.backtrack_limit', '10000');
        @ini_set('pcre.recursion_limit', '500');

        set_error_handler('__return_false');
        $result = @preg_match($wrapped, $subject, $matches);
        restore_error_handler();

        @ini_set('pcre.backtrack_limit', $prev_backtrack);
        @ini_set('pcre.recursion_limit', $prev_recursion);

        if (preg_last_error() !== PREG_NO_ERROR) {
            return false;
        }

        return $result === 1;
    }

    /**
     * Wrap a stored regex with the ~ delimiter, escaping any literal ~ in the pattern.
     */
    private function wrap_regex_pattern($pattern)
    {
        $pattern = (string) $pattern;
        if ($pattern === '') {
            return false;
        }
        return '~' . str_replace('~', '\~', $pattern) . '~';
    }

    /**
     * Ensure the priority column exists, adding it via ALTER TABLE if not.
     * Result is cached in an autoloaded WP option and a static variable.
     */
    /**
     * Ensure priority and last_accessed columns exist, adding them via ALTER TABLE if missing.
     * Returns true if the priority column exists (used for ORDER BY in process_redirects).
     * Replaces ensure_redirects_priority_schema() — also covers last_accessed so the
     * hit-counter UPDATE in process_redirects() never fails with a "unknown column" DB error.
     * Result is cached in a static variable — DB work only happens once per process.
     */
    private function ensure_redirects_schema()
    {
        static $checked   = false;
        static $supported = false;

        if ($checked) {
            return $supported;
        }

        $cached = get_option('ofast_redirects_priority_schema', null);

        // '2' = both priority AND last_accessed confirmed present.
        if ($cached === '2') {
            $checked   = true;
            $supported = true;
            return true;
        }

        // '1' = priority only (old format before this fix). Re-run to add last_accessed.
        // '0' on frontend = priority missing, but we still need to check last_accessed.
        // null = never checked before.

        global $wpdb;
        $table        = $wpdb->prefix . 'ofast_redirects';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));

        if (!$table_exists) {
            $checked   = true;
            $supported = false;
            update_option('ofast_redirects_priority_schema', '0', false);
            return false;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);

        if (!in_array('priority', $columns, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN priority INT(11) NOT NULL DEFAULT 10 AFTER is_regex");
        }

        // The critical one: used in the hit-counter UPDATE on every matched redirect.
        // If it doesn't exist, $wpdb outputs a DB error which sends output before
        // wp_redirect() can set headers — redirect silently fails.
        if (!in_array('last_accessed', $columns, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN last_accessed DATETIME NULL DEFAULT NULL AFTER hits");
        }

        // Re-read columns once after any ALTER TABLE operations.
        $columns  = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        $supported = in_array('priority', $columns, true);
        $checked   = true;

        // Store '2' to signal both columns are present so we skip all of this next time.
        update_option('ofast_redirects_priority_schema', $supported ? '2' : '0', false);
        return $supported;
    }

    /**
     * Backwards-compat alias — other methods in this file call ensure_redirects_priority_schema().
     * Routes to the new consolidated method.
     */
    private function ensure_redirects_priority_schema()
    {
        return $this->ensure_redirects_schema();
    }

    /**
     * SECURITY: Block dangerous protocols; allow http/https and relative paths.
     */
    private function validate_redirect_target($url)
    {
        $dangerous_protocols = array('javascript:', 'data:', 'vbscript:', 'file:');
        foreach ($dangerous_protocols as $protocol) {
            if (stripos($url, $protocol) === 0) {
                return new WP_Error('dangerous_protocol', 'Dangerous URL protocol detected. Redirects to javascript:, data:, or file: URLs are blocked for security.');
            }
        }

        $parsed = parse_url($url);
        if ($parsed === false) {
            return new WP_Error('invalid_url', __('Invalid target URL format.', 'ofast-x'));
        }

        if (!empty($parsed['scheme']) && !in_array(strtolower($parsed['scheme']), array('http', 'https'), true)) {
            return new WP_Error('invalid_scheme', __('Only http and https URLs are allowed.', 'ofast-x'));
        }

        return true;
    }

    /**
     * Check whether a URL points to an external host.
     */
    private function is_external_url($url)
    {
        $parsed = parse_url($url);
        if (empty($parsed['host'])) {
            return false;
        }
        $site_host = parse_url(home_url(), PHP_URL_HOST);
        return strtolower($parsed['host']) !== strtolower($site_host);
    }
}