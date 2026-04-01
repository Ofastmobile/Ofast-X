<?php

/**
 * Ofast X - Code Snippets Import/Export
 * Handles importing from JSON files, other plugins (Code Snippets, WPCode),
 * selective import with preview, export, and plugin detection.
 *
 * @since 1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Snippets_Import
{
    /** @var Ofast_X_Snippets */
    private $core;

    public function __construct(Ofast_X_Snippets $core)
    {
        $this->core = $core;
    }

    /**
     * Register AJAX hooks for import/export operations
     */
    public function register_hooks()
    {
        add_action('wp_ajax_ofast_export_snippets', array($this, 'ajax_export_snippets'));
        add_action('wp_ajax_ofast_import_snippets', array($this, 'ajax_import_snippets'));
        add_action('wp_ajax_ofast_import_from_plugin', array($this, 'ajax_import_from_plugin'));
        add_action('wp_ajax_ofast_preview_plugin_snippets', array($this, 'ajax_preview_plugin_snippets'));
        add_action('wp_ajax_ofast_selective_import_snippets', array($this, 'ajax_selective_import_snippets'));
    }

    /**
     * Detect other snippet plugins installed on the site
     */
    public function detect_other_snippet_plugins()
    {
        global $wpdb;
        $plugins = array();

        // Check for Code Snippets plugin (uses wp_snippets table)
        $code_snippets_table = $wpdb->prefix . 'snippets';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$code_snippets_table'");
        if ($table_exists) {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $code_snippets_table");
            if ($count > 0) {
                $plugins[] = array(
                    'name' => 'Code Snippets',
                    'slug' => 'code-snippets',
                    'count' => intval($count)
                );
            }
        }

        // Check for WPCode plugin (uses custom post type 'wpcode')
        $wpcode_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'wpcode' AND post_status IN ('publish', 'draft')"
        );
        if ($wpcode_count > 0) {
            $plugins[] = array(
                'name' => 'WPCode',
                'slug' => 'wpcode',
                'count' => intval($wpcode_count)
            );
        }

        return $plugins;
    }

    /**
     * AJAX: Export all snippets
     */
    public function ajax_export_snippets()
    {
        check_ajax_referer('ofast_export_snippets', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';
        $priority_supported = $this->core->ensure_snippets_priority_schema();
        $export_fields = $priority_supported
            ? 'name, description, code, language, scope, location, target_type, target_value, run_once, priority, category, tags, active'
            : 'name, description, code, language, scope, location, target_type, target_value, run_once, category, tags, active';
        $export_order = $priority_supported ? 'priority ASC, id ASC' : 'id ASC';

        // Check if specific IDs were passed (selected snippets)
        $selected_ids = isset($_POST['ids']) ? array_map('intval', (array)$_POST['ids']) : array();
        $selected_ids = array_filter($selected_ids); // Remove zeros
        $active_only = !empty($_POST['active_only']);

        // Build WHERE clause
        $where_parts = array();
        if ($this->core->ensure_snippets_trash_schema()) {
            $where_parts[] = "(status IS NULL OR status != 'trash')";
        }
        if ($active_only) {
            $where_parts[] = "active = 1";
        }

        if (!empty($selected_ids)) {
            // Export only selected snippets
            $placeholders = implode(',', array_fill(0, count($selected_ids), '%d'));
            $where_parts[] = "id IN ($placeholders)";
            $where_clause = !empty($where_parts) ? 'WHERE ' . implode(' AND ', $where_parts) : '';
            $snippets = $wpdb->get_results($wpdb->prepare(
                "SELECT {$export_fields} FROM $table {$where_clause} ORDER BY {$export_order}",
                $selected_ids
            ));
            $export_label = count($selected_ids) . ' Selected Snippets';
        } else {
            $where_clause = !empty($where_parts) ? 'WHERE ' . implode(' AND ', $where_parts) : '';
            $snippets = $wpdb->get_results("SELECT {$export_fields} FROM $table {$where_clause} ORDER BY {$export_order}");
            $export_label = $active_only ? 'Active Snippets Only' : 'All Snippets';
        }

        $export_data = array(
            'plugin' => 'ofast-x',
            'version' => '1.0',
            'exported_at' => current_time('mysql'),
            'site_url' => get_site_url(),
            'snippets' => $snippets
        );

        // Audit log
        $this->core->log_snippet_action('EXPORTED', 0, $export_label, 'Count: ' . count($snippets));

        wp_send_json_success($export_data);
    }

    /**
     * AJAX: Import snippets from JSON
     */
    public function ajax_import_snippets()
    {
        check_ajax_referer('ofast_import_snippets', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Rate limiting
        if (!$this->core->check_rate_limit('import')) {
            wp_send_json_error('Too many requests. Please wait a moment.');
        }

        $import_data = isset($_POST['import_data']) ? wp_unslash($_POST['import_data']) : '';

        // Raw payload size limit: 5MB max to prevent memory exhaustion
        if (strlen($import_data) > 5242880) {
            wp_send_json_error('Import data too large. Maximum 5MB allowed.');
        }

        $data = json_decode($import_data, true);

        if (!$data || !isset($data['snippets']) || !is_array($data['snippets'])) {
            wp_send_json_error('Invalid import file format');
        }

        // Import batch limit: max 100 snippets per import
        if (count($data['snippets']) > 100) {
            wp_send_json_error('Import limited to 100 snippets at a time. Split your file into smaller batches.');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';
        $priority_supported = $this->core->ensure_snippets_priority_schema();
        $imported = 0;
        $skipped = 0;
        $errors = array();

        foreach ($data['snippets'] as $snippet) {
            // Validate required fields
            if (empty($snippet['name']) || !isset($snippet['code'])) {
                $skipped++;
                continue;
            }

            // Validate PHP code if language is PHP
            $language = $this->core->normalize_snippet_language(isset($snippet['language']) ? $snippet['language'] : 'php');
            $snippet_code = isset($snippet['code']) ? (string) $snippet['code'] : '';
            if ($language === 'php') {
                $snippet_code = $this->core->normalize_php_code($snippet_code);
            }
            $priority = isset($snippet['priority']) ? intval($snippet['priority']) : 10;
            $priority = max(1, min(9999, $priority));
            if ($language === 'php' && $snippet_code !== '') {
                $validation = $this->core->validator->validate_php_code($snippet_code);
                if ($this->core->validator->is_hard_error($validation)) {
                    $errors[] = $snippet['name'] . ': ' . $validation;
                    $skipped++;
                    continue;
                }
                // Tier 2/3: import normally as inactive — admin can review and activate later
            }

            // DUPLICATE CODE CHECK: Skip if exact code already exists
            $code_hash = md5(trim($snippet_code));
            $existing_code = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE MD5(TRIM(code)) = %s",
                $code_hash
            ));
            if ($existing_code) {
                $skipped++;
                continue;
            }

            // Insert snippet (always as INACTIVE for safety)
            $insert_data = array(
                'name' => sanitize_text_field($snippet['name']) . ' (imported)',
                'description' => isset($snippet['description']) ? sanitize_textarea_field($snippet['description']) : '',
                'code' => $snippet_code,
                'language' => $language,
                'scope' => isset($snippet['scope']) ? sanitize_text_field($snippet['scope']) : 'global',
                'location' => isset($snippet['location']) ? sanitize_text_field($snippet['location']) : 'footer',
                'target_type' => isset($snippet['target_type']) ? sanitize_text_field($snippet['target_type']) : 'all',
                'target_value' => isset($snippet['target_value']) ? sanitize_text_field($snippet['target_value']) : '',
                'run_once' => isset($snippet['run_once']) ? intval($snippet['run_once']) : 0,
                'category' => isset($snippet['category']) ? sanitize_text_field($snippet['category']) : '',
                'tags' => isset($snippet['tags']) ? sanitize_text_field($snippet['tags']) : '',
                'active' => 0, // ALWAYS inactive on import
                'created_at' => current_time('mysql')
            );
            if ($priority_supported) {
                $insert_data['priority'] = $priority;
            }

            $wpdb->insert($table, $insert_data);

            $imported++;
        }

        // Audit log
        $this->core->log_snippet_action('IMPORTED', 0, 'Bulk Import', "Imported: {$imported}, Skipped: {$skipped}");

        $message = "Imported {$imported} snippet(s)";
        if ($skipped > 0) {
            $message .= ", skipped {$skipped}";
        }
        if (!empty($errors)) {
            $message .= "\n\nErrors:\n" . implode("\n", array_slice($errors, 0, 5));
        }

        // Clear cache when snippets are imported
        $this->core->clear_snippets_cache();

        wp_send_json_success(array('message' => $message, 'imported' => $imported, 'skipped' => $skipped));
    }

    /**
     * AJAX: Import snippets from another plugin
     */
    public function ajax_import_from_plugin()
    {
        check_ajax_referer('ofast_import_plugin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Rate limiting
        if (!$this->core->check_rate_limit('import_plugin')) {
            wp_send_json_error('Too many requests. Please wait a moment.');
        }

        $plugin = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : '';
        if (empty($plugin)) {
            wp_send_json_error('Invalid plugin');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';
        $imported = 0;
        $skipped = 0;
        $errors = array();

        if ($plugin === 'code-snippets') {
            // Import from Code Snippets plugin
            $source_table = $wpdb->prefix . 'snippets';
            $snippets = $wpdb->get_results("SELECT * FROM $source_table");

            foreach ($snippets as $snippet) {
                $snippet_code = isset($snippet->code) ? $this->core->normalize_php_code((string) $snippet->code) : '';

                // Validate PHP code — only hard-block Tier 1 (exec/eval/etc)
                if ($snippet_code !== '') {
                    $validation = $this->core->validator->validate_php_code($snippet_code);
                    if ($this->core->validator->is_hard_error($validation)) {
                        $errors[] = $snippet->name . ': ' . $validation;
                        $skipped++;
                        continue;
                    }
                    // Tier 2/3: import normally as inactive
                }

                // Map scope
                $scope = 'global';
                if (isset($snippet->scope)) {
                    if ($snippet->scope === 'admin' || $snippet->scope === 2) {
                        $scope = 'admin';
                    } elseif ($snippet->scope === 'front-end' || $snippet->scope === 1) {
                        $scope = 'frontend';
                    }
                }

                // DUPLICATE CODE CHECK: Skip if exact code already exists
                $code_hash = md5(trim($snippet_code));
                $existing_code = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table WHERE MD5(TRIM(code)) = %s",
                    $code_hash
                ));
                if ($existing_code) {
                    $skipped++;
                    continue;
                }

                $wpdb->insert($table, array(
                    'name' => sanitize_text_field($snippet->name) . ' (from Code Snippets)',
                    'description' => isset($snippet->desc) ? sanitize_textarea_field($snippet->desc) : '',
                    'code' => $snippet_code,
                    'language' => 'php',
                    'scope' => $scope,
                    'location' => 'footer',
                    'target_type' => 'all',
                    'target_value' => '',
                    'run_once' => 0,
                    'active' => 0, // Always inactive
                    'created_at' => current_time('mysql')
                ));
                $imported++;
            }
        } elseif ($plugin === 'wpcode') {
            // Import from WPCode plugin
            $posts = $wpdb->get_results(
                "SELECT p.*, pm.meta_value as code_type 
                 FROM {$wpdb->posts} p 
                 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wpcode_code_type'
                 WHERE p.post_type = 'wpcode' AND p.post_status IN ('publish', 'draft')"
            );

            foreach ($posts as $post) {
                // Get the code content from post_content or meta
                $code = $post->post_content;
                $code_meta = get_post_meta($post->ID, '_wpcode_snippet_code', true);
                if (!empty($code_meta)) {
                    $code = $code_meta;
                }

                // Determine language
                $language = 'php';
                $code_type = isset($post->code_type) ? $post->code_type : get_post_meta($post->ID, '_wpcode_code_type', true);
                $code_type = strtolower(trim((string) $code_type));
                if ($code_type === 'js' || $code_type === 'javascript') {
                    $language = 'javascript';
                } elseif ($code_type === 'css') {
                    $language = 'css';
                } elseif ($code_type === 'html' || $code_type === 'text') {
                    $language = 'html';
                }

                if ($language === 'php') {
                    $code = $this->core->normalize_php_code($code);
                }

                // Validate PHP code — only hard-block Tier 1
                if ($language === 'php' && !empty($code)) {
                    $validation = $this->core->validator->validate_php_code($code);
                    if ($this->core->validator->is_hard_error($validation)) {
                        $errors[] = $post->post_title . ': ' . $validation;
                        $skipped++;
                        continue;
                    }
                    // Tier 2/3: import normally as inactive
                }

                // DUPLICATE CODE CHECK: Skip if exact code already exists
                $code_hash = md5(trim($code));
                $existing_code = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table WHERE MD5(TRIM(code)) = %s",
                    $code_hash
                ));
                if ($existing_code) {
                    $skipped++;
                    continue;
                }

                $wpdb->insert($table, array(
                    'name' => sanitize_text_field($post->post_title) . ' (from WPCode)',
                    'description' => sanitize_textarea_field($post->post_excerpt),
                    'code' => $code,
                    'language' => $language,
                    'scope' => 'global',
                    'location' => 'footer',
                    'target_type' => 'all',
                    'target_value' => '',
                    'run_once' => 0,
                    'active' => 0, // Always inactive
                    'created_at' => current_time('mysql')
                ));
                $imported++;
            }
        } else {
            wp_send_json_error('Unknown plugin: ' . $plugin);
        }

        // Audit log
        $this->core->log_snippet_action('IMPORTED_FROM_PLUGIN', 0, $plugin, "Imported: {$imported}, Skipped: {$skipped}");

        // Clear cache when snippets are imported from plugin
        $this->core->clear_snippets_cache();

        $message = "Imported {$imported} snippet(s) from {$plugin}";
        if ($skipped > 0) {
            $message .= ", skipped {$skipped} (security/syntax issues)";
        }

        wp_send_json_success(array('message' => $message, 'imported' => $imported, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 5)));
    }

    /**
     * AJAX: Preview snippets from another plugin (for selective import)
     */
    public function ajax_preview_plugin_snippets()
    {
        check_ajax_referer('ofast_preview_snippets', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $plugin = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : '';
        if (empty($plugin)) {
            wp_send_json_error('Invalid plugin');
        }

        global $wpdb;
        $our_table = $wpdb->prefix . 'ofast_snippets';
        $snippets = array();

        if ($plugin === 'code-snippets') {
            $source_table = $wpdb->prefix . 'snippets';
            $source_snippets = $wpdb->get_results("SELECT * FROM $source_table");

            foreach ($source_snippets as $s) {
                $normalized_preview_code = isset($s->code) ? $this->core->normalize_php_code((string) $s->code) : '';
                $code_hash = md5(trim($normalized_preview_code));
                $existing_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $our_table WHERE MD5(TRIM(code)) = %s",
                    $code_hash
                ));

                $status = 'inactive';
                if ($existing_id) {
                    $status = 'duplicate';
                } elseif (!empty($s->active) && $s->active == 1) {
                    $status = 'active';
                }

                // Validate PHP syntax with tiered security
                $is_safe = true;
                $security_tier = 0;
                $error_message = null;
                if ($normalized_preview_code !== '') {
                    $validation = $this->core->validator->validate_php_code($normalized_preview_code);
                    if ($this->core->validator->is_hard_error($validation)) {
                        $is_safe = false;
                        $security_tier = 1;
                        $error_message = $validation;
                    } elseif ($this->core->validator->is_tier2_warning($validation)) {
                        $security_tier = 2;
                        $error_message = $this->core->validator->get_validation_message($validation);
                    } elseif ($this->core->validator->is_tier3_info($validation)) {
                        $security_tier = 3;
                        $error_message = $this->core->validator->get_validation_message($validation);
                    }
                }

                $snippets[] = array(
                    'id' => $s->id,
                    'name' => $s->name,
                    'description' => isset($s->desc) ? $s->desc : '',
                    'language' => 'php',
                    'status' => $status,
                    'existing_id' => $existing_id ? intval($existing_id) : null,
                    'is_safe' => $is_safe,
                    'security_tier' => $security_tier,
                    'error_message' => $error_message,
                    'code_preview' => htmlspecialchars(mb_substr($normalized_preview_code, 0, 500) . (strlen($normalized_preview_code) > 500 ? '...' : ''))
                );
            }
        } elseif ($plugin === 'wpcode') {
            $posts = $wpdb->get_results(
                "SELECT p.*, pm.meta_value as code_type, pm2.meta_value as is_active
                 FROM {$wpdb->posts} p 
                 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wpcode_code_type'
                 LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wpcode_active'
                 WHERE p.post_type = 'wpcode' AND p.post_status IN ('publish', 'draft')"
            );

            foreach ($posts as $post) {
                $code = $post->post_content;
                $code_meta = get_post_meta($post->ID, '_wpcode_snippet_code', true);
                if (!empty($code_meta)) {
                    $code = $code_meta;
                }

                $language = 'php';
                $code_type = isset($post->code_type) ? $post->code_type : get_post_meta($post->ID, '_wpcode_code_type', true);
                $code_type = strtolower(trim((string) $code_type));
                if ($code_type === 'js' || $code_type === 'javascript') {
                    $language = 'javascript';
                } elseif ($code_type === 'css') {
                    $language = 'css';
                } elseif ($code_type === 'html' || $code_type === 'text') {
                    $language = 'html';
                }

                if ($language === 'php') {
                    $code = $this->core->normalize_php_code($code);
                }

                $code_hash = md5(trim($code));
                $existing_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $our_table WHERE MD5(TRIM(code)) = %s",
                    $code_hash
                ));

                $is_active = isset($post->is_active) ? $post->is_active : get_post_meta($post->ID, '_wpcode_active', true);
                $status = 'inactive';
                if ($existing_id) {
                    $status = 'duplicate';
                } elseif ($is_active == 1 || $post->post_status === 'publish') {
                    $status = 'active';
                }

                // Validate with tiered security (only for PHP snippets)
                $is_safe = true;
                $security_tier = 0;
                $error_message = null;
                if ($language === 'php' && !empty($code)) {
                    $validation = $this->core->validator->validate_php_code($code);
                    if ($this->core->validator->is_hard_error($validation)) {
                        $is_safe = false;
                        $security_tier = 1;
                        $error_message = $validation;
                    } elseif ($this->core->validator->is_tier2_warning($validation)) {
                        $security_tier = 2;
                        $error_message = $this->core->validator->get_validation_message($validation);
                    } elseif ($this->core->validator->is_tier3_info($validation)) {
                        $security_tier = 3;
                        $error_message = $this->core->validator->get_validation_message($validation);
                    }
                }

                $snippets[] = array(
                    'id' => $post->ID,
                    'name' => $post->post_title,
                    'description' => $post->post_excerpt,
                    'language' => $language,
                    'status' => $status,
                    'existing_id' => $existing_id ? intval($existing_id) : null,
                    'is_safe' => $is_safe,
                    'security_tier' => $security_tier,
                    'error_message' => $error_message,
                    'code_preview' => htmlspecialchars(mb_substr($code, 0, 500) . (strlen($code) > 500 ? '...' : ''))
                );
            }
        } else {
            wp_send_json_error('Unknown plugin: ' . $plugin);
        }

        wp_send_json_success(array('snippets' => $snippets));
    }

    /**
     * AJAX: Selectively import specific snippets from another plugin
     */
    public function ajax_selective_import_snippets()
    {
        check_ajax_referer('ofast_selective_import', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Rate limiting
        if (!$this->core->check_rate_limit('import_plugin')) {
            wp_send_json_error('Too many requests. Please wait a moment.');
        }

        $plugin = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : '';
        $ids = isset($_POST['ids']) ? array_map('intval', $_POST['ids']) : array();

        if (empty($plugin) || empty($ids)) {
            wp_send_json_error('Invalid request');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';
        $imported = 0;
        $skipped = 0;
        $errors = array();

        if ($plugin === 'code-snippets') {
            $source_table = $wpdb->prefix . 'snippets';
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $snippets = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $source_table WHERE id IN ($placeholders)",
                $ids
            ));

            foreach ($snippets as $snippet) {
                $snippet_code = isset($snippet->code) ? $this->core->normalize_php_code((string) $snippet->code) : '';

                // Validate PHP code — only hard-block Tier 1
                if ($snippet_code !== '') {
                    $validation = $this->core->validator->validate_php_code($snippet_code);
                    if ($this->core->validator->is_hard_error($validation)) {
                        $errors[] = $snippet->name . ': ' . $validation;
                        $skipped++;
                        continue;
                    }
                    // Tier 2/3: import normally as inactive
                }

                // Map scope
                $scope = 'global';
                if (isset($snippet->scope)) {
                    if ($snippet->scope === 'admin' || $snippet->scope === 2) {
                        $scope = 'admin';
                    } elseif ($snippet->scope === 'front-end' || $snippet->scope === 1) {
                        $scope = 'frontend';
                    }
                }

                // Duplicate check
                $code_hash = md5(trim($snippet_code));
                $existing_code = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table WHERE MD5(TRIM(code)) = %s",
                    $code_hash
                ));
                if ($existing_code) {
                    $skipped++;
                    continue;
                }

                $wpdb->insert($table, array(
                    'name' => sanitize_text_field($snippet->name) . ' (from Code Snippets)',
                    'description' => isset($snippet->desc) ? sanitize_textarea_field($snippet->desc) : '',
                    'code' => $snippet_code,
                    'language' => 'php',
                    'scope' => $scope,
                    'location' => 'footer',
                    'target_type' => 'all',
                    'target_value' => '',
                    'run_once' => 0,
                    'active' => 0,
                    'created_at' => current_time('mysql')
                ));
                $imported++;
            }
        } elseif ($plugin === 'wpcode') {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $posts = $wpdb->get_results($wpdb->prepare(
                "SELECT p.*, pm.meta_value as code_type 
                 FROM {$wpdb->posts} p 
                 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wpcode_code_type'
                 WHERE p.ID IN ($placeholders) AND p.post_type = 'wpcode'",
                $ids
            ));

            foreach ($posts as $post) {
                $code = $post->post_content;
                $code_meta = get_post_meta($post->ID, '_wpcode_snippet_code', true);
                if (!empty($code_meta)) {
                    $code = $code_meta;
                }

                $language = 'php';
                $code_type = isset($post->code_type) ? $post->code_type : get_post_meta($post->ID, '_wpcode_code_type', true);
                $code_type = strtolower(trim((string) $code_type));
                if ($code_type === 'js' || $code_type === 'javascript') {
                    $language = 'javascript';
                } elseif ($code_type === 'css') {
                    $language = 'css';
                } elseif ($code_type === 'html' || $code_type === 'text') {
                    $language = 'html';
                }

                if ($language === 'php') {
                    $code = $this->core->normalize_php_code($code);
                }

                // Validate PHP code — only hard-block Tier 1
                if ($language === 'php' && !empty($code)) {
                    $validation = $this->core->validator->validate_php_code($code);
                    if ($this->core->validator->is_hard_error($validation)) {
                        $errors[] = $post->post_title . ': ' . $validation;
                        $skipped++;
                        continue;
                    }
                    // Tier 2/3: import normally as inactive
                }

                // Duplicate check
                $code_hash = md5(trim($code));
                $existing_code = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table WHERE MD5(TRIM(code)) = %s",
                    $code_hash
                ));
                if ($existing_code) {
                    $skipped++;
                    continue;
                }

                $wpdb->insert($table, array(
                    'name' => sanitize_text_field($post->post_title) . ' (from WPCode)',
                    'description' => sanitize_textarea_field($post->post_excerpt),
                    'code' => $code,
                    'language' => $language,
                    'scope' => 'global',
                    'location' => 'footer',
                    'target_type' => 'all',
                    'target_value' => '',
                    'run_once' => 0,
                    'active' => 0,
                    'created_at' => current_time('mysql')
                ));
                $imported++;
            }
        } else {
            wp_send_json_error('Unknown plugin: ' . $plugin);
        }

        // Audit log
        $this->core->log_snippet_action('SELECTIVE_IMPORT', 0, $plugin, "Selected: " . count($ids) . ", Imported: {$imported}, Skipped: {$skipped}");

        // Clear cache
        $this->core->clear_snippets_cache();

        $message = "Imported {$imported} snippet(s) from {$plugin}";
        if ($skipped > 0) {
            $message .= ", skipped {$skipped} (duplicates/errors)";
        }

        wp_send_json_success(array('message' => $message, 'imported' => $imported, 'skipped' => $skipped));
    }
}
