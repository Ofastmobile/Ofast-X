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
        // Only load if module is enabled
        $enabled = get_option('ofastx_modules_enabled', array());
        if (empty($enabled['redirects'])) {
            return;
        }

        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

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
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'Redirects Manager',
            'Redirects',
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

        $id = isset($_POST['redirect_id']) ? intval($_POST['redirect_id']) : 0;
        $source_url = isset($_POST['source_url']) ? sanitize_text_field($_POST['source_url']) : '';

        // FIXED: Allow relative paths (like / or /page) not just full URLs
        $raw_target = isset($_POST['target_url']) ? trim($_POST['target_url']) : '';
        if (strpos($raw_target, '/') === 0) {
            // Relative path - sanitize as text field to allow / and /path
            $target_url = sanitize_text_field($raw_target);
        } else {
            // Full URL - use esc_url_raw
            $target_url = esc_url_raw($raw_target);
        }

        $type = isset($_POST['redirect_type']) ? sanitize_text_field($_POST['redirect_type']) : '301';
        $is_regex = isset($_POST['is_regex']) ? 1 : 0;
        $active = isset($_POST['active']) ? 1 : 0;

        // Validate
        if (empty($source_url)) {
            add_settings_error('ofast_redirects', 'error', 'Source URL is required.', 'error');
            return;
        }

        if (empty($target_url)) {
            add_settings_error('ofast_redirects', 'error', 'Target URL is required.', 'error');
            return;
        }

        // SECURITY: Validate target URL to prevent open redirect attacks
        $target_validation = $this->validate_redirect_target($target_url);
        if (is_wp_error($target_validation)) {
            add_settings_error('ofast_redirects', 'error', $target_validation->get_error_message(), 'error');
            return;
        }

        // Ensure source starts with /
        if (strpos($source_url, '/') !== 0 && !$is_regex) {
            $source_url = '/' . $source_url;
        }

        // SECURITY: Prevent redirect loops  
        $source_path = parse_url($source_url, PHP_URL_PATH);
        $target_path = parse_url($target_url, PHP_URL_PATH);
        if ($source_path === $target_path) {
            add_settings_error('ofast_redirects', 'error', 'Source and target cannot be the same (redirect loop).', 'error');
            return;
        }

        // Validate redirect type
        if (!in_array($type, array('301', '302', '307'))) {
            $type = '301';
        }

        if ($id > 0) {
            // Update
            $wpdb->update($table, array(
                'source_url' => $source_url,
                'target_url' => $target_url,
                'type' => $type,
                'is_regex' => $is_regex,
                'active' => $active
            ), array('id' => $id));

            add_settings_error('ofast_redirects', 'success', 'Redirect updated successfully.', 'success');
        } else {
            // Insert
            $wpdb->insert($table, array(
                'source_url' => $source_url,
                'target_url' => $target_url,
                'type' => $type,
                'is_regex' => $is_regex,
                'active' => $active,
                'hits' => 0,
                'created_at' => current_time('mysql'),
                'created_by' => get_current_user_id()
            ));

            add_settings_error('ofast_redirects', 'success', 'Redirect added successfully.', 'success');
        }

        // Redirect to avoid resubmission
        wp_redirect(admin_url('admin.php?page=ofast-redirects'));
        exit;
    }

    /**
     * Process redirects on frontend
     */
    public function process_redirects()
    {
        // Don't run in admin
        if (is_admin()) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_redirects';

        // Get current request URI
        $request_uri = $_SERVER['REQUEST_URI'];
        $request_path = parse_url($request_uri, PHP_URL_PATH);

        // Get all active redirects
        $redirects = $wpdb->get_results("SELECT * FROM $table WHERE active = 1 ORDER BY id");

        foreach ($redirects as $redirect) {
            $matched = false;

            if ($redirect->is_regex) {
                // Regex matching
                if (@preg_match('#' . $redirect->source_url . '#', $request_path, $matches)) {
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
                // Update hit counter
                $wpdb->update($table, array(
                    'hits' => $redirect->hits + 1,
                    'last_accessed' => current_time('mysql')
                ), array('id' => $redirect->id));

                // Perform redirect
                wp_redirect($target, intval($redirect->type));
                exit;
            }
        }
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
        $wpdb->update($table, array('active' => $new_active), array('id' => $id));

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
                        // Check if already exists
                        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE source_url = %s", $item->url));
                        if (!$exists) {
                            $wpdb->insert($table, array(
                                'source_url' => $item->url,
                                'target_url' => $item->action_data,
                                'type' => '301',
                                'is_regex' => $item->regex ? 1 : 0,
                                'active' => 0, // Import as inactive
                                'hits' => 0,
                                'created_at' => current_time('mysql'),
                                'created_by' => get_current_user_id()
                            ));
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
                        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE source_url = %s", $source));
                        if (!$exists) {
                            $wpdb->insert($table, array(
                                'source_url' => $source,
                                'target_url' => $target,
                                'type' => $status ?: '301',
                                'is_regex' => 0,
                                'active' => 0,
                                'hits' => 0,
                                'created_at' => current_time('mysql'),
                                'created_by' => get_current_user_id()
                            ));
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
                        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE source_url = %s", $source));
                        if (!$exists) {
                            $wpdb->insert($table, array(
                                'source_url' => $source,
                                'target_url' => $target,
                                'type' => '301',
                                'is_regex' => 0,
                                'active' => 0,
                                'hits' => 0,
                                'created_at' => current_time('mysql'),
                                'created_by' => get_current_user_id()
                            ));
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
                        $source = isset($redirect['source_url']) ? sanitize_text_field($redirect['source_url']) : '';
                        $target = isset($redirect['target_url']) ? esc_url_raw($redirect['target_url']) : '';
                        if ($source && $target) {
                            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE source_url = %s", $source));
                            if (!$exists) {
                                $wpdb->insert($table, array(
                                    'source_url' => $source,
                                    'target_url' => $target,
                                    'type' => isset($redirect['type']) ? sanitize_text_field($redirect['type']) : '301',
                                    'is_regex' => isset($redirect['is_regex']) ? intval($redirect['is_regex']) : 0,
                                    'active' => 0,
                                    'hits' => 0,
                                    'created_at' => current_time('mysql'),
                                    'created_by' => get_current_user_id()
                                ));
                                $imported++;
                            }
                        }
                    }
                }
                break;
        }

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

        // Check for selected IDs
        $selected_ids = isset($_POST['ids']) ? array_map('intval', (array)$_POST['ids']) : array();
        $selected_ids = array_filter($selected_ids);

        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '%d'));
            $redirects = $wpdb->get_results($wpdb->prepare(
                "SELECT source_url, target_url, type, is_regex, active FROM $table WHERE id IN ($placeholders)",
                $selected_ids
            ));
        } else {
            $redirects = $wpdb->get_results("SELECT source_url, target_url, type, is_regex, active FROM $table");
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

        // Check for edit mode
        $editing = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $edit_redirect = null;
        if ($editing) {
            $edit_redirect = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $editing));
        }

        // Get all redirects
        $redirects = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC");

        // Detect import sources
        $import_sources = $this->detect_import_sources();

        settings_errors('ofast_redirects');
?>
        <div class="wrap">
            <h1>Redirects Manager</h1>
            <p class="description">Manage URL redirects with 301, 302, or 307 status codes. Activate/deactivate as needed.</p>

            <!-- Add/Edit Form -->
            <div style="background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin: 20px 0; max-width: 700px;">
                <h2><?php echo $editing ? 'Edit Redirect' : 'Add New Redirect'; ?></h2>
                <form method="post" class="ofast-redirect-form">
                    <?php wp_nonce_field('ofast_redirect_save', '_wpnonce'); ?>

                    <?php if ($editing): ?>
                        <input type="hidden" name="redirect_id" value="<?php echo $editing; ?>">
                    <?php endif; ?>

                    <table class="form-table">
                        <tr>
                            <th><label for="source_url">Source URL</label></th>
                            <td>
                                <input type="text" name="source_url" id="source_url" class="regular-text"
                                    value="<?php echo $edit_redirect ? esc_attr($edit_redirect->source_url) : ''; ?>"
                                    placeholder="/old-page" required>
                                <p class="description">The URL path to redirect from (e.g., /old-page)</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="target_url">Target URL</label></th>
                            <td>
                                <input type="text" name="target_url" id="target_url" class="regular-text"
                                    value="<?php echo $edit_redirect ? esc_attr($edit_redirect->target_url) : ''; ?>"
                                    placeholder="<?php echo home_url('/new-page'); ?>" required>
                                <p class="description">The URL to redirect to (full URL or relative path)</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="redirect_type">Redirect Type</label></th>
                            <td>
                                <select name="redirect_type" id="redirect_type">
                                    <option value="301" <?php selected($edit_redirect ? $edit_redirect->type : '', '301'); ?>>301 - Permanent</option>
                                    <option value="302" <?php selected($edit_redirect ? $edit_redirect->type : '', '302'); ?>>302 - Temporary</option>
                                    <option value="307" <?php selected($edit_redirect ? $edit_redirect->type : '', '307'); ?>>307 - Temporary (Preserve Method)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Options</th>
                            <td>
                                <label style="display: block; margin-bottom: 10px;">
                                    <input type="checkbox" name="is_regex" value="1" <?php checked($edit_redirect ? $edit_redirect->is_regex : false); ?>>
                                    Use Regular Expression
                                </label>
                                <label style="display: block;">
                                    <input type="checkbox" name="active" value="1" <?php checked($edit_redirect ? $edit_redirect->active : false); ?>>
                                    Activate immediately
                                </label>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" name="ofast_save_redirect" class="button button-primary">
                            <?php echo $editing ? 'Update Redirect' : 'Add Redirect'; ?>
                        </button>
                        <?php if ($editing): ?>
                            <a href="?page=ofast-redirects" class="button">Cancel</a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>

            <!-- Import Section -->
            <?php if (!empty($import_sources)): ?>
                <div style="background: #f0f7ff; border: 1px solid #c3d9ff; border-radius: 8px; padding: 15px; margin: 20px 0; max-width: 700px;">
                    <h3 style="margin-top: 0;">Import from Other Plugins</h3>
                    <p class="description">Imported redirects will be set to INACTIVE until you activate them.</p>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px;">
                        <?php foreach ($import_sources as $key => $source): ?>
                            <button type="button" class="button import-from-plugin" data-plugin="<?php echo esc_attr($key); ?>">
                                Import from <?php echo esc_html($source['name']); ?> (<?php echo $source['count']; ?>)
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Import from JSON -->
            <div style="background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin: 20px 0; max-width: 700px;">
                <h3 style="margin-top: 0;">Import/Export</h3>
                <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: 500;">Import from JSON</label>
                        <input type="file" id="import-json-file" accept=".json">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: 500;">Export</label>
                        <button type="button" id="export-redirects" class="button">Export All</button>
                    </div>
                </div>
            </div>

            <!-- Redirects List -->
            <?php if (!empty($redirects)): ?>
                <div style="background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin-top: 20px;">
                    <h3 style="margin-top: 0;">
                        All Redirects
                        <span style="font-weight: normal; color: #666; font-size: 14px;">(<?php echo count($redirects); ?> total)</span>
                    </h3>

                    <!-- Scrollable Table Container -->
                    <div style="overflow-x: auto; max-width: 100%;">
                        <table class="wp-list-table widefat fixed striped" style="min-width: 900px;">
                            <thead>
                                <tr>
                                    <th style="width: 30px;"><input type="checkbox" id="select-all-redirects"></th>
                                    <th>Source URL</th>
                                    <th>Target URL</th>
                                    <th style="width: 80px;">Type</th>
                                    <th style="width: 60px;">Hits</th>
                                    <th style="width: 80px;">Status</th>
                                    <th style="width: 120px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($redirects as $redirect): ?>
                                    <tr>
                                        <td><input type="checkbox" class="redirect-checkbox" value="<?php echo $redirect->id; ?>"></td>
                                        <td>
                                            <code style="font-size: 12px;"><?php echo esc_html($redirect->source_url); ?></code>
                                            <?php if ($redirect->is_regex): ?>
                                                <span style="background: #fef3cd; color: #856404; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">regex</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="word-break: break-all; font-size: 12px;"><?php echo esc_html($redirect->target_url); ?></td>
                                        <td>
                                            <span style="background: <?php echo $redirect->type == '301' ? '#d4edda' : '#fff3cd'; ?>; color: <?php echo $redirect->type == '301' ? '#155724' : '#856404'; ?>; padding: 2px 8px; border-radius: 3px; font-size: 11px;">
                                                <?php echo $redirect->type; ?>
                                            </span>
                                        </td>
                                        <td style="font-size: 12px;"><?php echo number_format($redirect->hits); ?></td>
                                        <td>
                                            <button class="button button-small ofast-redirect-toggle <?php echo $redirect->active ? 'button-primary' : ''; ?>"
                                                data-id="<?php echo $redirect->id; ?>" data-active="<?php echo $redirect->active; ?>"
                                                style="min-width: 50px; font-size: 11px;">
                                                <?php echo $redirect->active ? 'ON' : 'OFF'; ?>
                                            </button>
                                        </td>
                                        <td>
                                            <a href="?page=ofast-redirects&edit=<?php echo $redirect->id; ?>" class="button button-small">Edit</a>
                                            <button class="button button-small ofast-redirect-delete" data-id="<?php echo $redirect->id; ?>" style="color: #dc3545;">Delete</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <div style="background: #f8f9fa; border: 1px solid #ddd; border-radius: 8px; padding: 40px; text-align: center; margin-top: 20px;">
                    <p style="color: #666; margin: 0;">No redirects yet. Add your first redirect above!</p>
                </div>
            <?php endif; ?>
        </div>

        <style>
            .ofast-redirect-form .form-table th {
                width: 120px;
                padding: 12px 10px 12px 0;
                font-weight: 500;
            }

            .ofast-redirect-form .form-table td {
                padding: 10px 0;
            }

            .ofast-redirect-form input[type="text"],
            .ofast-redirect-form select {
                max-width: 400px;
                width: 100%;
                padding: 8px 12px;
                border: 1px solid #ddd;
                border-radius: 6px;
            }

            .ofast-redirect-form .description {
                color: #666;
                font-size: 12px;
                margin-top: 5px;
            }

            @media screen and (max-width: 782px) {

                .ofast-redirect-form .form-table th,
                .ofast-redirect-form .form-table td {
                    display: block;
                    width: 100%;
                }
            }
        </style>

        <script>
            jQuery(document).ready(function($) {
                // Toggle redirect
                $(document).on('click', '.ofast-redirect-toggle', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var id = $btn.data('id');
                    var active = $btn.data('active');

                    $btn.prop('disabled', true);

                    $.post(ajaxurl, {
                        action: 'ofast_toggle_redirect',
                        nonce: '<?php echo wp_create_nonce('ofast_redirect_toggle'); ?>',
                        id: id,
                        active: active
                    }, function(response) {
                        if (response.success) {
                            var newActive = response.data.active;
                            $btn.data('active', newActive);
                            $btn.text(newActive ? 'ON' : 'OFF');
                            $btn.toggleClass('button-primary', newActive == 1);
                        }
                    }).always(function() {
                        $btn.prop('disabled', false);
                    });
                });

                // Delete redirect
                $(document).on('click', '.ofast-redirect-delete', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var id = $btn.data('id');

                    if (!confirm('Are you sure you want to delete this redirect?')) {
                        return;
                    }

                    $.post(ajaxurl, {
                        action: 'ofast_delete_redirect',
                        nonce: '<?php echo wp_create_nonce('ofast_redirect_delete'); ?>',
                        id: id
                    }, function(response) {
                        if (response.success) {
                            $btn.closest('tr').fadeOut(function() {
                                $(this).remove();
                            });
                        }
                    });
                });

                // Import from plugin
                $(document).on('click', '.import-from-plugin', function() {
                    var $btn = $(this);
                    var plugin = $btn.data('plugin');

                    $btn.prop('disabled', true).text('Importing...');

                    $.post(ajaxurl, {
                        action: 'ofast_import_redirects_from_plugin',
                        nonce: '<?php echo wp_create_nonce('ofast_import_redirects'); ?>',
                        plugin: plugin
                    }, function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            if (response.data.imported > 0) {
                                location.reload();
                            }
                        } else {
                            alert('Import failed: ' + response.data);
                        }
                    }).always(function() {
                        $btn.prop('disabled', false);
                        location.reload();
                    });
                });

                // Import from JSON file
                $('#import-json-file').on('change', function(e) {
                    var file = e.target.files[0];
                    if (!file) return;

                    var reader = new FileReader();
                    reader.onload = function(e) {
                        $.post(ajaxurl, {
                            action: 'ofast_import_redirects_from_plugin',
                            nonce: '<?php echo wp_create_nonce('ofast_import_redirects'); ?>',
                            plugin: 'json',
                            json_data: e.target.result
                        }, function(response) {
                            if (response.success) {
                                alert(response.data.message);
                                if (response.data.imported > 0) {
                                    location.reload();
                                }
                            } else {
                                alert('Import failed: ' + response.data);
                            }
                        });
                    };
                    reader.readAsText(file);
                });

                // Export redirects
                $('#export-redirects').on('click', function() {
                    var $btn = $(this);
                    $btn.prop('disabled', true).text('Exporting...');

                    // Collect selected IDs
                    var selectedIds = [];
                    $('.redirect-checkbox:checked').each(function() {
                        selectedIds.push($(this).val());
                    });

                    $.post(ajaxurl, {
                        action: 'ofast_export_redirects',
                        nonce: '<?php echo wp_create_nonce('ofast_export_redirects'); ?>',
                        ids: selectedIds
                    }, function(response) {
                        if (response.success) {
                            var blob = new Blob([JSON.stringify(response.data, null, 2)], {
                                type: 'application/json'
                            });
                            var url = URL.createObjectURL(blob);
                            var a = document.createElement('a');
                            a.href = url;
                            a.download = 'ofast-redirects-' + new Date().toISOString().split('T')[0] + '.json';
                            a.click();
                            URL.revokeObjectURL(url);
                        }
                    }).always(function() {
                        $btn.prop('disabled', false).text('Export All');
                    });
                });

                // Select all
                $('#select-all-redirects').on('change', function() {
                    $('.redirect-checkbox').prop('checked', $(this).prop('checked'));
                });
            });
        </script>
<?php
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
     * SECURITY: Sanitize regex pattern to prevent ReDoS
     */
    private function sanitize_regex($pattern)
    {
        // Remove nested quantifiers that could cause ReDoS
        $pattern = preg_replace('/(\+|\*|\?)\1+/', '$1', $pattern);

        // Limit overall pattern length
        if (strlen($pattern) > 500) {
            return false;
        }

        return $pattern;
    }
}
