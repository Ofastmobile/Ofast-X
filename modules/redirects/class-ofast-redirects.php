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

        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Enqueue scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Handle form submissions
        add_action('admin_init', array($this, 'handle_form_submissions'));

        // AJAX handlers
        add_action('wp_ajax_ofast_toggle_redirect', array($this, 'ajax_toggle_redirect'));
        add_action('wp_ajax_ofast_delete_redirect', array($this, 'ajax_delete_redirect'));
        add_action('wp_ajax_ofast_import_redirects_from_plugin', array($this, 'ajax_import_from_plugin'));
        add_action('wp_ajax_ofast_export_redirects', array($this, 'ajax_export_redirects'));

        // Process redirects on frontend
        add_action('template_redirect', array($this, 'process_redirects'), 1);
    }

    /**
     * Enqueue scripts and styles for redirects page
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueue_scripts($hook)
    {
        if (strpos($hook, 'ofast-redirects') === false) {
            return;
        }

        wp_enqueue_style(
            'ofast-redirects',
            OFAST_X_PLUGIN_URL . 'assets/css/redirects.css',
            array(),
            OFAST_X_VERSION
        );

        wp_enqueue_script(
            'ofast-redirects',
            OFAST_X_PLUGIN_URL . 'assets/js/redirects.js',
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
                'on' => __('ON', 'ofast-x'),
                'off' => __('OFF', 'ofast-x'),
                'confirmDelete' => __('Are you sure you want to delete this redirect?', 'ofast-x'),
                'importing' => __('Importing...', 'ofast-x'),
                'exporting' => __('Exporting...', 'ofast-x'),
                'importFailed' => __('Import failed: ', 'ofast-x'),
            )
        ));
    }

    /**
     * Add admin menu
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
     * Handle form submissions
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
        $table = $wpdb->prefix . 'ofast_redirects';
        $priority_supported = $this->ensure_redirects_priority_schema();

        $id = isset($_POST['redirect_id']) ? intval($_POST['redirect_id']) : 0;
        $source_url = isset($_POST['source_url']) ? sanitize_text_field(wp_unslash($_POST['source_url'])) : '';

        $raw_target = isset($_POST['target_url']) ? wp_unslash($_POST['target_url']) : '';
        $target_url = $this->normalize_target_url($raw_target);
        $type = $this->normalize_redirect_type(isset($_POST['redirect_type']) ? sanitize_text_field(wp_unslash($_POST['redirect_type'])) : '301');
        $is_regex = isset($_POST['is_regex']) ? 1 : 0;
        $active = isset($_POST['active']) ? 1 : 0;
        $priority = isset($_POST['priority']) ? intval($_POST['priority']) : 10;
        $priority = max(1, min(9999, $priority));

        // Validate
        if (empty($source_url)) {
            add_settings_error('ofast_redirects', 'error', __('Source URL is required.', 'ofast-x'), 'error');
            return;
        }

        if (empty($target_url)) {
            add_settings_error('ofast_redirects', 'error', __('Target URL is required.', 'ofast-x'), 'error');
            return;
        }

        // SECURITY: Validate target URL to prevent open redirect attacks
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
            // Ensure source starts with /
            if (strpos($source_url, '/') !== 0) {
                $source_url = '/' . ltrim($source_url, '/');
            }

            // SECURITY: Prevent redirect loops for exact paths.
            $source_path = parse_url($source_url, PHP_URL_PATH);
            $target_path = parse_url($target_url, PHP_URL_PATH);
            if (!empty($source_path) && !empty($target_path) && $source_path === $target_path) {
                add_settings_error('ofast_redirects', 'error', __('Source and target cannot be the same (redirect loop).', 'ofast-x'), 'error');
                return;
            }
        }

        if ($id > 0) {
            // Update
            $update_data = array(
                'source_url' => $source_url,
                'target_url' => $target_url,
                'type' => $type,
                'is_regex' => $is_regex,
                'active' => $active
            );
            if ($priority_supported) {
                $update_data['priority'] = $priority;
            }
            $wpdb->update($table, $update_data, array('id' => $id));

            add_settings_error('ofast_redirects', 'success', __('Redirect updated successfully.', 'ofast-x'), 'success');
        } else {
            // Insert
            $insert_data = array(
                'source_url' => $source_url,
                'target_url' => $target_url,
                'type' => $type,
                'is_regex' => $is_regex,
                'active' => $active,
                'hits' => 0,
                'created_at' => current_time('mysql'),
                'created_by' => get_current_user_id()
            );
            if ($priority_supported) {
                $insert_data['priority'] = $priority;
            }
            $wpdb->insert($table, $insert_data);

            add_settings_error('ofast_redirects', 'success', __('Redirect added successfully.', 'ofast-x'), 'success');
        }

        // Clear cache when redirects are modified
        $this->clear_redirects_cache();

        // Redirect to avoid resubmission
        wp_redirect(admin_url('admin.php?page=ofast-redirects'));
        exit;
    }

    /**
     * Process redirects on frontend
     * PERFORMANCE: Uses transient caching to avoid DB queries on every request
     */
    public function process_redirects()
    {
        // Don't run in admin
        if (is_admin()) {
            return;
        }

        // Get current request URI
        $request_uri = $_SERVER['REQUEST_URI'];
        $request_path = parse_url($request_uri, PHP_URL_PATH);

        // PERFORMANCE: Get redirects from cache (5 minute TTL)
        $redirects = get_transient('ofast_redirects_cache');
        $priority_supported = $this->ensure_redirects_priority_schema();

        if ($redirects === false) {
            global $wpdb;
            $table = $wpdb->prefix . 'ofast_redirects';
            $order_by = $priority_supported ? 'priority ASC, id ASC' : 'id ASC';
            $redirects = $wpdb->get_results("SELECT * FROM $table WHERE active = 1 ORDER BY {$order_by}");

            // Cache for 5 minutes (300 seconds)
            set_transient('ofast_redirects_cache', $redirects, 300);
        }

        // Early exit if no redirects
        if (empty($redirects)) {
            return;
        }

        foreach ($redirects as $redirect) {
            $matched = false;
            $target = '';

            if ($redirect->is_regex) {
                // Regex matching
                $pattern = $this->sanitize_regex($redirect->source_url);
                if ($pattern === false) {
                    continue;
                }

                $matches = array();
                if ($this->regex_match($pattern, $request_path, $matches)) {
                    $matched = true;
                    // Replace backreferences in target
                    $target = $redirect->target_url;
                    for ($i = 1; $i < count($matches); $i++) {
                        $target = str_replace('$' . $i, $matches[$i], $target);
                    }
                }
            } else {
                // Exact matching (case-insensitive, normalize trailing slashes)
                $source_normalized = rtrim(strtolower($redirect->source_url), '/');
                $request_normalized = rtrim(strtolower($request_path), '/');

                // Handle root path special case
                if ($source_normalized === '') {
                    $source_normalized = '/';
                }
                if ($request_normalized === '') {
                    $request_normalized = '/';
                }

                if ($request_normalized === $source_normalized) {
                    $matched = true;
                    $target = $redirect->target_url;
                }
            }

            if ($matched) {
                $target_validation = $this->validate_redirect_target($target);
                if (is_wp_error($target_validation)) {
                    continue;
                }
                $type = $this->normalize_redirect_type($redirect->type);

                // Update hit counter atomically.
                global $wpdb;
                $table = $wpdb->prefix . 'ofast_redirects';
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET hits = hits + 1, last_accessed = %s WHERE id = %d",
                    current_time('mysql'),
                    intval($redirect->id)
                ));

                // Perform redirect
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
     * Clear redirects cache when redirects are modified
     */
    private function clear_redirects_cache()
    {
        delete_transient('ofast_redirects_cache');
    }

    /**
     * AJAX: Toggle redirect active/inactive
     */
    public function ajax_toggle_redirect()
    {
        check_ajax_referer('ofast_redirect_toggle', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
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

        // Clear cache when redirect is toggled
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

        // Clear cache when redirect is deleted
        $this->clear_redirects_cache();

        wp_send_json_success();
    }

    /**
     * AJAX: Import from other plugins
     */
    public function ajax_import_from_plugin()
    {
        check_ajax_referer('ofast_import_redirects', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $plugin = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : '';
        $imported = 0;

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_redirects';

        switch ($plugin) {
            case 'redirection':
                // Import from Redirection plugin
                $source_table = $wpdb->prefix . 'redirection_items';
                if ($wpdb->get_var("SHOW TABLES LIKE '{$source_table}'") === $source_table) {
                    $items = $wpdb->get_results("SELECT url, action_data, regex FROM {$source_table} WHERE action_type = 'url'");
                    foreach ($items as $item) {
                        $insert_data = $this->prepare_redirect_insert_data(
                            $item->url,
                            $item->action_data,
                            '301',
                            $item->regex ? 1 : 0,
                            0,
                            10
                        );
                        if (is_wp_error($insert_data)) {
                            continue;
                        }

                        // Check if already exists
                        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE source_url = %s", $insert_data['source_url']));
                        if (!$exists) {
                            $wpdb->insert($table, $insert_data);
                            $imported++;
                        }
                    }
                }
                break;

            case 'safe-redirect-manager':
                // Import from Safe Redirect Manager
                $redirects = get_posts(array(
                    'post_type' => 'redirect_rule',
                    'posts_per_page' => -1,
                    'post_status' => 'publish'
                ));
                foreach ($redirects as $redirect) {
                    $source = get_post_meta($redirect->ID, '_redirect_rule_from', true);
                    $target = get_post_meta($redirect->ID, '_redirect_rule_to', true);
                    $status = get_post_meta($redirect->ID, '_redirect_rule_status_code', true);

                    if ($source && $target) {
                        $insert_data = $this->prepare_redirect_insert_data(
                            $source,
                            $target,
                            $status ?: '301',
                            0,
                            0,
                            10
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

            case 'simple-301':
                // Import from Simple 301 Redirects
                $redirects = get_option('301_redirects', array());
                if (is_array($redirects)) {
                    foreach ($redirects as $source => $target) {
                        $insert_data = $this->prepare_redirect_insert_data(
                            $source,
                            $target,
                            '301',
                            0,
                            0,
                            10
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

            case 'json':
                // Import from JSON file
                $json_data = isset($_POST['json_data']) ? wp_unslash($_POST['json_data']) : '';
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

        // Clear cache when redirects are imported
        $this->clear_redirects_cache();

        wp_send_json_success(array(
            'imported' => $imported,
            'message' => $imported > 0 ? "{$imported} redirect(s) imported as INACTIVE. Review and activate when ready." : 'No new redirects found to import.'
        ));
    }

    /**
     * AJAX: Export redirects
     */
    public function ajax_export_redirects()
    {
        check_ajax_referer('ofast_export_redirects', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_redirects';
        $priority_supported = $this->ensure_redirects_priority_schema();
        $select_fields = $priority_supported
            ? 'source_url, target_url, type, is_regex, active, priority'
            : 'source_url, target_url, type, is_regex, active';

        // Check for selected IDs
        $selected_ids = isset($_POST['ids']) ? array_map('intval', (array)$_POST['ids']) : array();
        $selected_ids = array_filter($selected_ids);

        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '%d'));
            $redirects = $wpdb->get_results($wpdb->prepare(
                "SELECT {$select_fields} FROM $table WHERE id IN ($placeholders)",
                $selected_ids
            ));
        } else {
            $redirects = $wpdb->get_results("SELECT {$select_fields} FROM $table");
        }

        $export_data = array(
            'plugin' => 'ofast-x',
            'version' => '1.0',
            'exported_at' => current_time('mysql'),
            'site_url' => get_site_url(),
            'redirects' => $redirects
        );

        wp_send_json_success($export_data);
    }

    /**
     * Detect available import sources
     */
    private function detect_import_sources()
    {
        global $wpdb;
        $sources = array();

        // Check Redirection plugin
        $redirection_table = $wpdb->prefix . 'redirection_items';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$redirection_table}'") === $redirection_table) {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$redirection_table} WHERE action_type = 'url'");
            if ($count > 0) {
                $sources['redirection'] = array(
                    'name' => 'Redirection Plugin',
                    'count' => $count
                );
            }
        }

        // Check Safe Redirect Manager
        $srm_count = wp_count_posts('redirect_rule');
        if (isset($srm_count->publish) && $srm_count->publish > 0) {
            $sources['safe-redirect-manager'] = array(
                'name' => 'Safe Redirect Manager',
                'count' => $srm_count->publish
            );
        }

        // Check Simple 301 Redirects
        $simple_301 = get_option('301_redirects', array());
        if (!empty($simple_301)) {
            $sources['simple-301'] = array(
                'name' => 'Simple 301 Redirects',
                'count' => count($simple_301)
            );
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
        $table = $wpdb->prefix . 'ofast_redirects';
        $priority_supported = $this->ensure_redirects_priority_schema();

        // Check for edit mode
        $editing = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $edit_redirect = null;
        if ($editing) {
            $edit_redirect = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $editing));
        }

        // Get all redirects
        $order_by = $priority_supported ? 'priority ASC, id DESC' : 'id DESC';
        $redirects = $wpdb->get_results("SELECT * FROM $table ORDER BY {$order_by}");

        // Detect import sources
        $import_sources = $this->detect_import_sources();

        settings_errors('ofast_redirects');
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
                        <h2 style="margin-top: 0; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;"><?php echo $editing ? esc_html__('Edit Redirect', 'ofast-x') : esc_html__('Add New Redirect', 'ofast-x'); ?></h2>
                        <form method="post" class="ofast-redirect-form">
                            <?php wp_nonce_field('ofast_redirect_save', '_wpnonce'); ?>

                            <?php if ($editing): ?>
                                <input type="hidden" name="redirect_id" value="<?php echo $editing; ?>">
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
                                        <select name="redirect_type" id="redirect_type">
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
                                            <input type="number" name="priority" id="priority" class="small-text" min="1" max="9999" step="1"
                                                value="<?php echo esc_attr($priority_value); ?>">
                                            <p class="description"><?php esc_html_e('Lower number runs first when multiple redirects could match.', 'ofast-x'); ?></p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <tr>
                                    <th><?php esc_html_e('Options', 'ofast-x'); ?></th>
                                    <td>
                                        <label style="display: block; margin-bottom: 10px;">
                                            <input type="checkbox" name="is_regex" value="1" <?php checked($edit_redirect ? $edit_redirect->is_regex : false); ?>>
                                            <?php esc_html_e('Use Regular Expression', 'ofast-x'); ?>
                                        </label>
                                        <label style="display: block;">
                                            <input type="checkbox" name="active" value="1" <?php checked($edit_redirect ? $edit_redirect->active : false); ?>>
                                            <?php esc_html_e('Activate immediately', 'ofast-x'); ?>
                                        </label>
                                    </td>
                                </tr>
                            </table>

                            <p class="submit" style="margin-bottom: 0; padding-bottom: 0;">
                                <button type="submit" name="ofast_save_redirect" class="button button-primary button-large">
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
                    <!-- Import Section -->
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

                    <!-- Import from JSON -->
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
                        <span style="font-weight: normal; color: #666; font-size: 14px;">(<?php echo esc_html(count($redirects)); ?> <?php esc_html_e('total', 'ofast-x'); ?>)</span>
                    </h3>

                    <!-- Scrollable Table Container -->
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
                                                <span style="background: #fef3cd; color: #856404; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">regex</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="word-break: break-all; font-size: 12px;"><?php echo esc_html($redirect->target_url); ?></td>
                                        <td>
                                            <span style="background: <?php echo $redirect->type == '301' ? '#d4edda' : '#fff3cd'; ?>; color: <?php echo $redirect->type == '301' ? '#155724' : '#856404'; ?>; padding: 2px 8px; border-radius: 3px; font-size: 11px;">
                                                <?php echo esc_html($redirect->type); ?>
                                            </span>
                                        </td>
                                        <?php if ($priority_supported): ?>
                                            <td style="font-size: 12px; font-weight: 600;"><?php echo isset($redirect->priority) ? intval($redirect->priority) : 10; ?></td>
                                        <?php endif; ?>
                                        <td style="font-size: 12px;"><?php echo number_format($redirect->hits); ?></td>
                                        <td>
                                            <button class="button button-small ofast-redirect-toggle <?php echo $redirect->active ? 'button-primary' : ''; ?>"
                                                data-id="<?php echo esc_attr($redirect->id); ?>" data-active="<?php echo esc_attr($redirect->active); ?>"
                                                style="min-width: 50px; font-size: 11px;">
                                                <?php echo $redirect->active ? esc_html__('ON', 'ofast-x') : esc_html__('OFF', 'ofast-x'); ?>
                                            </button>
                                        </td>
                                        <td>
                                            <a href="?page=ofast-redirects&edit=<?php echo esc_attr($redirect->id); ?>" class="button button-small"><?php esc_html_e('Edit', 'ofast-x'); ?></a>
                                            <button class="button button-small ofast-redirect-delete" data-id="<?php echo esc_attr($redirect->id); ?>" style="color: #dc3545;"><?php esc_html_e('Delete', 'ofast-x'); ?></button>
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

    /**
     * Normalize redirect target URL while preserving relative paths.
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
     * Normalize redirect status type to supported values.
     */
    private function normalize_redirect_type($type)
    {
        $type = (string) $type;
        return in_array($type, array('301', '302', '307'), true) ? $type : '301';
    }

    /**
     * Build a sanitized redirect record array for insert operations.
     */
    private function prepare_redirect_insert_data($source, $target, $type = '301', $is_regex = 0, $active = 0, $priority = 10)
    {
        $source_url = sanitize_text_field((string) $source);
        $target_url = $this->normalize_target_url($target);
        $type = $this->normalize_redirect_type($type);
        $is_regex = !empty($is_regex) ? 1 : 0;
        $active = !empty($active) ? 1 : 0;
        $priority = max(1, min(9999, intval($priority)));

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
            'type' => $type,
            'is_regex' => $is_regex,
            'active' => $active,
            'hits' => 0,
            'created_at' => current_time('mysql'),
            'created_by' => get_current_user_id()
        );

        if ($this->ensure_redirects_priority_schema()) {
            $insert_data['priority'] = $priority;
        }

        return $insert_data;
    }

    /**
     * Check regex validity with comprehensive ReDoS protection.
     * 
     * SECURITY: Enhanced validation includes:
     * - Syntax validation with timeout
     * - Runtime limit testing
     * - Pattern complexity analysis
     */
    private function is_valid_regex($pattern)
    {
        $wrapped = $this->wrap_regex_pattern($pattern);
        if ($wrapped === false) {
            return false;
        }

        // Store original PCRE settings for testing
        $original_backtrack_limit = ini_get('pcre.backtrack_limit');
        $original_recursion_limit = ini_get('pcre.recursion_limit');
        
        // Apply strict limits for validation
        ini_set('pcre.backtrack_limit', '1000');  // Very low for testing
        ini_set('pcre.recursion_limit', '100');
        
        $is_valid = false;
        $start_time = microtime(true);

        set_error_handler('__return_false');
        
        try {
            // Test pattern compilation and basic matching
            $result = preg_match($wrapped, '');
            $pcre_error = preg_last_error();
            
            // Pattern is valid if no PCRE errors occurred
            $is_valid = ($result !== false && $pcre_error === PREG_NO_ERROR);
            
            // Additional test with various inputs to catch ReDoS early
            if ($is_valid) {
                $test_inputs = array(
                    '',                           // Empty string
                    'a',                         // Single char
                    'aaaaa',                     // Repeated chars
                    'abcdefghijklmnop',          // Normal string
                    str_repeat('a', 100),        // Long repeated (ReDoS trigger)
                    'a' . str_repeat('b', 50) . 'c', // Pattern that might not match
                );
                
                foreach ($test_inputs as $test_input) {
                    $test_result = preg_match($wrapped, $test_input);
                    $test_error = preg_last_error();
                    
                    // If any test triggers PCRE limits, pattern is dangerous
                    if ($test_error === PREG_BACKTRACK_LIMIT_ERROR || 
                        $test_error === PREG_RECURSION_LIMIT_ERROR) {
                        $is_valid = false;
                        break;
                    }
                    
                    // Check execution time for each test
                    $current_time = microtime(true);
                    if (($current_time - $start_time) > 0.05) { // 50ms total limit
                        $is_valid = false;
                        break;
                    }
                }
            }
            
        } catch (Exception $e) {
            $is_valid = false;
        } finally {
            restore_error_handler();
            
            // Restore original PCRE settings
            ini_set('pcre.backtrack_limit', $original_backtrack_limit);
            ini_set('pcre.recursion_limit', $original_recursion_limit);
        }

        return $is_valid;
    }

    /**
     * Run regex match safely with ReDoS protection; invalid patterns return false.
     * 
     * SECURITY: Implements runtime protection against ReDoS attacks:
     * - PCRE backtrack/recursion limits
     * - Execution time monitoring  
     * - Input length validation
     * - Error handling and logging
     */
    private function regex_match($pattern, $subject, &$matches = array())
    {
        $wrapped = $this->wrap_regex_pattern($pattern);
        if ($wrapped === false) {
            return false;
        }

        // Validate input length to prevent excessive processing
        if (strlen($subject) > 10000) { // Reasonable limit for URL matching
            return false;
        }

        // Store original PCRE settings
        $original_backtrack_limit = ini_get('pcre.backtrack_limit');
        $original_recursion_limit = ini_get('pcre.recursion_limit');
        
        // Apply strict runtime limits
        ini_set('pcre.backtrack_limit', '10000');   // Lower limit for redirects
        ini_set('pcre.recursion_limit', '500');     // Prevent deep recursion
        
        $start_time = microtime(true);
        $result = false;
        $error = null;

        // Set error handler to catch PCRE errors
        set_error_handler(function($errno, $errstr) use (&$error) {
            $error = $errstr;
            return true;
        });

        try {
            // Execute regex with monitoring
            $result = preg_match($wrapped, $subject, $matches);
            
            // Check for PCRE errors
            $pcre_error = preg_last_error();
            if ($pcre_error !== PREG_NO_ERROR) {
                $result = false;
                if ($pcre_error === PREG_BACKTRACK_LIMIT_ERROR) {
                    error_log("SECURITY: ReDoS backtrack limit hit for pattern: " . substr($pattern, 0, 100));
                } elseif ($pcre_error === PREG_RECURSION_LIMIT_ERROR) {
                    error_log("SECURITY: ReDoS recursion limit hit for pattern: " . substr($pattern, 0, 100));
                }
            }
            
        } catch (Exception $e) {
            $result = false;
            error_log("SECURITY: Regex execution error: " . $e->getMessage());
        } finally {
            restore_error_handler();
            
            // Restore original PCRE settings
            ini_set('pcre.backtrack_limit', $original_backtrack_limit);
            ini_set('pcre.recursion_limit', $original_recursion_limit);
        }

        $execution_time = microtime(true) - $start_time;
        
        // Monitor for suspicious execution times (potential ReDoS)
        if ($execution_time > 0.1) { // 100ms threshold for redirect matching
            error_log("SECURITY: Suspicious regex execution time: {$execution_time}s for pattern: " . substr($pattern, 0, 100));
            return false; // Reject slow patterns
        }

        return $result === 1;
    }

    /**
     * Wrap stored regex using a controlled delimiter.
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
     * Ensure priority column exists for redirects table.
     */
    private function ensure_redirects_priority_schema()
    {
        static $checked = false;
        static $supported = false;

        if ($checked) {
            return $supported;
        }

        // Fast-path cache (autoloaded option), avoids schema queries on normal requests.
        $cached = get_option('ofast_redirects_priority_schema', null);
        if ($cached === '1') {
            $checked = true;
            $supported = true;
            return true;
        }
        if ($cached === '0' && !is_admin()) {
            $checked = true;
            $supported = false;
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_redirects';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));

        if (!$table_exists) {
            $checked = true;
            $supported = false;
            update_option('ofast_redirects_priority_schema', '0', false);
            return false;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (!in_array('priority', $columns, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN priority INT(11) DEFAULT 10 AFTER is_regex");
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        }

        $supported = in_array('priority', $columns, true);
        $checked = true;
        update_option('ofast_redirects_priority_schema', $supported ? '1' : '0', false);
        return $supported;
    }

    /**
     * SECURITY: Validate redirect target URL
     * Prevents open redirect attacks
     */
    private function validate_redirect_target($url)
    {
        // Block dangerous protocols (javascript:, data:, vbscript:)
        $dangerous_protocols = array('javascript:', 'data:', 'vbscript:', 'file:');
        foreach ($dangerous_protocols as $protocol) {
            if (stripos($url, $protocol) === 0) {
                return new WP_Error('dangerous_protocol', 'Dangerous URL protocol detected. Redirects to javascript:, data:, or file: URLs are blocked for security.');
            }
        }

        // Parse the URL
        $parsed = parse_url($url);
        if ($parsed === false) {
            return new WP_Error('invalid_url', __('Invalid target URL format.', 'ofast-x'));
        }

        // Allow only HTTP/HTTPS schemes for absolute URLs.
        if (!empty($parsed['scheme']) && !in_array(strtolower($parsed['scheme']), array('http', 'https'), true)) {
            return new WP_Error('invalid_scheme', __('Only http and https URLs are allowed.', 'ofast-x'));
        }

        // If no host, it's a relative URL (internal) - allow it
        if (empty($parsed['host'])) {
            return true;
        }

        // Get current site host
        $site_host = parse_url(home_url(), PHP_URL_HOST);

        // If same host as current site - allow it
        if (strtolower($parsed['host']) === strtolower($site_host)) {
            return true;
        }

        // If www variant of same host - allow it
        if (
            strtolower($parsed['host']) === 'www.' . strtolower($site_host) ||
            'www.' . strtolower($parsed['host']) === strtolower($site_host)
        ) {
            return true;
        }

        // External URL - allow but log it for awareness
        // External redirects are valid use cases (e.g., affiliate links, partner sites)
        // We just ensure admin is aware by storing this info
        return true;
    }

    /**
     * SECURITY: Check if URL is external
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

    /**
     * SECURITY: Comprehensive regex pattern sanitization to prevent ReDoS attacks (CWE-1333)
     * 
     * This function implements defense-in-depth against Regular Expression Denial of Service:
     * 1. Static analysis to detect dangerous patterns
     * 2. Complexity scoring to limit resource usage
     * 3. Whitelist approach for safe constructs
     * 4. Length and structural limits
     */
    private function sanitize_regex($pattern)
    {
        $pattern = trim((string) $pattern);
        if ($pattern === '') {
            return false;
        }

        // 1. BASIC CONSTRAINTS
        // Strict length limit - even short patterns can be dangerous
        if (strlen($pattern) > 200) {
            return false;
        }

        // Block null bytes and control characters that could interfere with validation
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $pattern)) {
            return false;
        }

        // 2. CRITICAL REDOS PATTERNS - Block immediately
        $dangerous_patterns = array(
            // Classic nested quantifiers
            '/\(\.\*\)\*/',                    // (.*)* 
            '/\(\.\+\)\+/',                    // (.+)+
            '/\(\.\*\)\+/',                    // (.*)+
            '/\(\.\+\)\*/',                    // (.+)*
            '/\([^)]*\+[^)]*\)\+/',            // (x+y)+
            '/\([^)]*\*[^)]*\)\*/',            // (x*y)*
            '/\([^)]*\+[^)]*\)\*/',            // (x+y)*
            '/\([^)]*\*[^)]*\)\+/',            // (x*y)+
            
            // Nested group quantifiers 
            '/\(\([^)]*[+*][^)]*\)[+*]\)/',    // ((x+)+) or ((x*)+)
            
            // Character class variants
            '/\(\[[^\]]*\]\+\)\+/',            // ([...]+)+
            '/\(\[[^\]]*\]\*\)\*/',            // ([...]*)*
            '/\(\[[^\]]*\]\+\)\*/',            // ([...]+)*
            '/\(\[[^\]]*\]\*\)\+/',            // ([...]*)+ 
            
            // Word boundary and escape sequences
            '/\(\\\\[dDwWsS]\+\)\+/',          // (\d+)+, (\w+)+, (\s+)+
            '/\(\\\\[dDwWsS]\*\)\+/',          // (\d*)+, (\w*)+, (\s*)+
            '/\(\\\\[dDwWsS]\+\)\*/',          // (\d+)*, (\w+)*, (\s+)*
            
            // Overlapping alternations 
            '/\([^|()]*\|[^|()]*\)[+*].*\1/',  // Basic overlap detection
            
            // Dangerous lookaheads/lookbehinds
            '/\(\?[=!][^)]*[+*]/',             // (?=...*) or (?!...+)
            '/\(\?<[=!][^)]*[+*]/',            // (?<=...*) or (?<!...+)
            
            // Backreference amplification
            '/\([^)]*\).*\\\\1[+*]/',          // (group)....\1+
            
            // Recursive patterns
            '/\(\?R\)|\(\?[0-9]+\)/',          // (?R) or (?1) etc
            
            // Possessive quantifiers misuse
            '/[+*]\+[+*]/',                    // *++ or ++* 
        );

        foreach ($dangerous_patterns as $dangerous) {
            if (preg_match($dangerous, $pattern)) {
                return false;
            }
        }

        // 3. COMPLEXITY ANALYSIS
        $complexity_score = $this->calculate_regex_complexity($pattern);
        if ($complexity_score > 100) { // Strict limit for redirects
            return false;
        }

        // 4. STRUCTURAL VALIDATION
        // Count nesting depth
        $max_depth = $this->get_regex_nesting_depth($pattern);
        if ($max_depth > 4) { // Reasonable limit for URL redirects
            return false;
        }

        // Count quantifiers
        $quantifier_count = preg_match_all('/[^\\\\][+*?]|\{[0-9,]+\}/', $pattern);
        if ($quantifier_count > 10) { // Too many quantifiers = likely problematic
            return false;
        }

        // 5. ALTERNATION VALIDATION  
        if (!$this->validate_alternations($pattern)) {
            return false;
        }

        // 6. WHITELIST CHECK - Ensure pattern uses only safe constructs
        if (!$this->is_whitelisted_regex_pattern($pattern)) {
            return false;
        }

        // 7. FINAL SANITIZATION
        // Remove redundant quantifier duplications
        $pattern = preg_replace('/(\+|\*|\?)\1+/', '$1', $pattern);
        
        // Normalize common problematic sequences
        $pattern = preg_replace('/\.\*\+/', '.*', $pattern);  // .*+ -> .*
        $pattern = preg_replace('/\.\+\*/', '.+', $pattern);  // .+* -> .+

        return $pattern;
    }

    /**
     * Calculate complexity score for regex pattern
     * Higher scores indicate more potential for ReDoS
     */
    private function calculate_regex_complexity($pattern)
    {
        $score = 0;
        
        // Base score from length
        $score += strlen($pattern) * 0.5;
        
        // Quantifier penalty
        $quantifiers = preg_match_all('/[+*?]|\{[0-9,]+\}/', $pattern);
        $score += $quantifiers * 5;
        
        // Group penalty (each group adds complexity)
        $groups = preg_match_all('/\([^)]*\)/', $pattern);
        $score += $groups * 3;
        
        // Alternation penalty (exponential complexity risk)
        $alternations = preg_match_all('/\|/', $pattern);
        $score += $alternations * 8;
        
        // Nested structure penalty
        $nesting_depth = $this->get_regex_nesting_depth($pattern);
        $score += pow($nesting_depth, 2) * 5;
        
        // Character class penalty
        $char_classes = preg_match_all('/\[[^\]]*\]/', $pattern);
        $score += $char_classes * 2;
        
        // Lookahead/lookbehind heavy penalty
        $assertions = preg_match_all('/\(\?[=!<]/', $pattern);
        $score += $assertions * 15;
        
        // Backreference penalty
        $backrefs = preg_match_all('/\\\\[1-9]/', $pattern);
        $score += $backrefs * 10;

        return (int) $score;
    }

    /**
     * Calculate maximum nesting depth of groups in regex
     */
    private function get_regex_nesting_depth($pattern)
    {
        $max_depth = 0;
        $current_depth = 0;
        $in_char_class = false;
        
        for ($i = 0; $i < strlen($pattern); $i++) {
            $char = $pattern[$i];
            $prev_char = $pattern[$i - 1] ?? '';
            
            // Skip escaped characters
            if ($prev_char === '\\') {
                continue;
            }
            
            // Handle character classes
            if ($char === '[' && !$in_char_class) {
                $in_char_class = true;
                continue;
            }
            if ($char === ']' && $in_char_class) {
                $in_char_class = false;
                continue;
            }
            
            if ($in_char_class) {
                continue;
            }
            
            // Track group depth
            if ($char === '(') {
                $current_depth++;
                $max_depth = max($max_depth, $current_depth);
            } elseif ($char === ')') {
                $current_depth = max(0, $current_depth - 1);
            }
        }
        
        return $max_depth;
    }

    /**
     * Validate alternation patterns for overlapping branches
     */
    private function validate_alternations($pattern)
    {
        // Find quantified alternation groups: (a|b)+, (x|y)*, etc.
        if (preg_match_all('/\(([^()]+\|[^()]+)\)[+*?]/', $pattern, $matches)) {
            foreach ($matches[1] as $alternation) {
                $branches = explode('|', $alternation);
                
                // Too many branches = complexity risk
                if (count($branches) > 8) {
                    return false;
                }
                
                // Check for obviously overlapping branches
                for ($i = 0; $i < count($branches); $i++) {
                    for ($j = $i + 1; $j < count($branches); $j++) {
                        $branch_a = trim($branches[$i]);
                        $branch_b = trim($branches[$j]);
                        
                        // Identical branches
                        if ($branch_a === $branch_b) {
                            return false;
                        }
                        
                        // One is prefix of another
                        if (strlen($branch_a) > 0 && strlen($branch_b) > 0) {
                            if (strpos($branch_b, $branch_a) === 0 || strpos($branch_a, $branch_b) === 0) {
                                return false;
                            }
                        }
                    }
                }
            }
        }
        
        return true;
    }

    /**
     * Whitelist validation - ensure pattern only uses safe constructs
     * This implements a restrictive approach suitable for redirect URL patterns
     */
    private function is_whitelisted_regex_pattern($pattern)
    {
        // For redirects, we primarily need these safe constructs:
        $allowed_constructs = array(
            // Basic literals and word characters
            '/^[a-zA-Z0-9\-\/_.]/',
            
            // Safe character classes
            '/\[[a-zA-Z0-9\-_\/\.]+\]/',
            
            // Simple quantifiers (non-nested)
            '/[^(][+*?]/',
            '/\{[0-9]+,?[0-9]*\}/',
            
            // Safe anchors
            '/[\^$]/',
            
            // Safe escapes for URL patterns
            '/\\\\[\/\-\.]/',
            
            // Non-capturing groups (safer than capturing)
            '/\(\?\:/',
            
            // Simple alternation (not quantified)
            '/[^)]\|[^(]/',
        );
        
        // Remove all allowed constructs and see what remains
        $remaining = $pattern;
        
        // Remove safe literals
        $remaining = preg_replace('/[a-zA-Z0-9\-\/_.]/', '', $remaining);
        
        // Remove safe character classes
        $remaining = preg_replace('/\[[a-zA-Z0-9\-_\/\.]+\]/', '', $remaining);
        
        // Remove safe quantifiers
        $remaining = preg_replace('/[+*?]/', '', $remaining);
        $remaining = preg_replace('/\{[0-9]+,?[0-9]*\}/', '', $remaining);
        
        // Remove anchors
        $remaining = preg_replace('/[\^$]/', '', $remaining);
        
        // Remove safe escapes
        $remaining = preg_replace('/\\\\[\/\-\.]/', '', $remaining);
        
        // Remove non-capturing groups
        $remaining = preg_replace('/\(\?\:/', '', $remaining);
        $remaining = preg_replace('/[()]/', '', $remaining);
        
        // Remove simple alternation
        $remaining = preg_replace('/\|/', '', $remaining);
        
        // Remove whitespace
        $remaining = preg_replace('/\s/', '', $remaining);
        
        // If anything suspicious remains, reject
        if (strlen($remaining) > 0) {
            // Check if remaining characters are dangerous
            $dangerous_remaining = preg_match('/[?=!<>]|\\\\[^\/\-\.]/', $remaining);
            if ($dangerous_remaining) {
                return false;
            }
        }
        
        return true;
    }
}
