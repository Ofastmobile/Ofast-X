<?php

/**
 * Ofast X - Code Snippets AJAX Handlers
 * Handles all AJAX operations: toggle, delete, rename, duplicate,
 * bulk actions, run now, revisions, trash management, and library templates.
 *
 * @since 1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Snippets_Ajax
{
    /** @var Ofast_X_Snippets */
    private $core;

    public function __construct(Ofast_X_Snippets $core)
    {
        $this->core = $core;
    }

    /**
     * Register AJAX hooks
     */
    public function register_hooks()
    {
        add_action('wp_ajax_ofast_toggle_snippet', array($this, 'ajax_toggle_snippet'));
        add_action('wp_ajax_ofast_delete_snippet', array($this, 'ajax_delete_snippet'));
        add_action('wp_ajax_ofast_rename_snippet', array($this, 'ajax_rename_snippet'));
        add_action('wp_ajax_ofast_bulk_action_snippets', array($this, 'ajax_bulk_action_snippets'));
        add_action('wp_ajax_ofast_use_library_template', array($this, 'ajax_use_library_template'));
        add_action('wp_ajax_ofast_get_revisions', array($this, 'ajax_get_revisions'));
        add_action('wp_ajax_ofast_restore_revision', array($this, 'ajax_restore_revision'));
        add_action('wp_ajax_ofast_restore_snippet', array($this, 'ajax_restore_snippet'));
        add_action('wp_ajax_ofast_run_snippet_now', array($this, 'ajax_run_snippet_now'));
        add_action('wp_ajax_ofast_duplicate_snippet', array($this, 'ajax_duplicate_snippet'));
        add_action('wp_ajax_ofast_save_trash_retention', array($this, 'ajax_save_trash_retention'));
        add_action('wp_ajax_ofast_empty_snippet_trash', array($this, 'ajax_empty_snippet_trash'));
    }

    /**
     * AJAX: Toggle snippet
     */
    public function ajax_toggle_snippet()
    {
        check_ajax_referer('ofast_snippet_toggle', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Rate limiting
        if (!$this->core->check_rate_limit('toggle')) {
            wp_send_json_error('Too many requests. Please wait a moment.');
        }

        $id = intval($_POST['id']);
        $current_active = intval($_POST['active']);
        $new_active = $current_active ? 0 : 1;

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';

        // Get snippet info for logging
        $snippet = $wpdb->get_row($wpdb->prepare("SELECT id, name, code, language FROM $table WHERE id = %d", $id));

        if (!$snippet) {
            wp_send_json_error('Snippet not found');
            return;
        }

        // If turning ON, validate first
        if ($new_active == 1 && $snippet) {
            $lang = !empty($snippet->language) ? $snippet->language : 'php';
            $candidate_code = ($lang === 'php') ? $this->core->normalize_php_code($snippet->code) : $snippet->code;
            $force_activate = isset($_POST['force_activate']) && $_POST['force_activate'] === 'true';

            // Language mismatch check
            $detected_lang = $this->core->validator->detect_code_language($candidate_code);
            if ($detected_lang && $detected_lang !== $lang) {
                $lang_labels = array('php' => 'PHP', 'javascript' => 'JavaScript', 'css' => 'CSS', 'html' => 'HTML');
                $detected_label = isset($lang_labels[$detected_lang]) ? $lang_labels[$detected_lang] : strtoupper($detected_lang);
                $selected_label = isset($lang_labels[$lang]) ? $lang_labels[$lang] : strtoupper($lang);
                wp_send_json_error("Cannot activate: This code looks like {$detected_label} but is saved as {$selected_label}. Edit the snippet and change the language.");
                return;
            }

            // ── Code Snippets plugin approach: no pre-validation gates ────────
            // Real safety net is test_snippet_code() which catches actual PHP
            // parse errors at runtime via try/catch. If PHP can parse it, let it run.

            // PHP-specific checks: function conflicts & duplicate conflicts
            if ($lang === 'php') {
                // Check for function name conflicts
                $conflict_check = $this->core->validator->check_function_conflicts($candidate_code);
                if ($conflict_check !== true) {
                    wp_send_json_error('Cannot activate: ' . $conflict_check);
                    return;
                }

                // Check duplicate conflicts against already active snippets.
                $activation_conflicts = $this->core->validator->get_active_duplicate_conflicts($snippet->id, $snippet->name, $candidate_code);
                if (!empty($activation_conflicts)) {
                    $conflict_preview = implode(' | ', array_slice($activation_conflicts, 0, 2));
                    if (count($activation_conflicts) > 2) {
                        $conflict_preview .= ' | +' . (count($activation_conflicts) - 2) . ' more';
                    }
                    wp_send_json_error('Cannot activate: duplicate conflict with active snippet(s). ' . $conflict_preview);
                    return;
                }

                // Pre-activation test: execute the code in a sandbox to catch runtime errors
                // before actually activating. Prevents admin lockouts from broken snippets.
                $test_result = $this->core->test_snippet_code($candidate_code, $snippet->id);
                if ($test_result !== true) {
                    wp_send_json_error('Cannot activate — code test failed: ' . $test_result);
                    return;
                }
            }
        }

        // If turning OFF, check for dependent snippets (warning only, not blocking)
        $dependency_warning = '';
        if ($new_active == 0 && ($snippet->language === 'php' || empty($snippet->language))) {
            $dependents = $this->core->validator->get_dependent_snippets($snippet->id, $snippet->code);
            if (!empty($dependents)) {
                $dep_names = array_map(function($d) { return $d->name; }, $dependents);
                $dependency_warning = 'Warning: ' . count($dependents) . ' active snippet(s) may depend on functions in this snippet: ' . implode(', ', array_slice($dep_names, 0, 3));
                if (count($dep_names) > 3) {
                    $dependency_warning .= ' +' . (count($dep_names) - 3) . ' more';
                }
            }
        }

        $wpdb->update(
            $table,
            array('active' => $new_active),
            array('id' => $id)
        );

        // Audit log
        $this->core->log_snippet_action(
            $new_active ? 'ACTIVATED' : 'DEACTIVATED',
            $id,
            $snippet ? $snippet->name : '',
            $dependency_warning ? $dependency_warning : ''
        );

        // Clear cache when snippet is toggled
        $this->core->clear_snippets_cache();

        $response = array('active' => $new_active);
        if ($dependency_warning) {
            $response['dependency_warning'] = $dependency_warning;
        }

        wp_send_json_success($response);
    }

    /**
     * AJAX: Delete snippet
     */
    public function ajax_delete_snippet()
    {
        check_ajax_referer('ofast_snippet_delete', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Rate limiting
        if (!$this->core->check_rate_limit('delete')) {
            wp_send_json_error('Too many requests. Please wait a moment.');
        }

        $id = intval($_POST['id']);
        $permanent = isset($_POST['permanent']) ? sanitize_text_field(wp_unslash($_POST['permanent'])) : '';
        $is_permanent = in_array(strtolower($permanent), array('1', 'true', 'yes'), true);

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';
        $trash_supported = $this->core->ensure_snippets_trash_schema();

        // Get snippet for logging
        $snippet = $wpdb->get_row($wpdb->prepare("SELECT id, name FROM $table WHERE id = %d", $id));
        if (!$snippet) {
            wp_send_json_error('Snippet not found');
        }

        // Permanent delete always removes row
        if ($is_permanent) {
            $wpdb->delete($table, array('id' => $id));
            $this->core->log_snippet_action('DELETED_PERMANENTLY', $id, $snippet->name, '');
            $this->core->clear_snippets_cache();
            wp_send_json_success(array('message' => 'Snippet permanently deleted.', 'permanent' => true));
        }

        // Soft delete to trash when supported
        if ($trash_supported) {
            $wpdb->update(
                $table,
                array(
                    'status' => 'trash',
                    'trashed_at' => current_time('mysql'),
                    'active' => 0
                ),
                array('id' => $id)
            );
            $this->core->log_snippet_action('TRASHED', $id, $snippet->name, '');
            $this->core->clear_snippets_cache();
            wp_send_json_success(array('message' => 'Snippet moved to trash.', 'trashed' => true));
        }

        // Fallback hard delete if trash columns are unavailable
        $wpdb->delete($table, array('id' => $id));
        $this->core->log_snippet_action('DELETED', $id, $snippet->name, 'Trash schema unavailable');
        $this->core->clear_snippets_cache();
        wp_send_json_success(array('message' => 'Snippet deleted.'));
    }

    /**
     * AJAX: Restore snippet from trash
     */
    public function ajax_restore_snippet()
    {
        check_ajax_referer('ofast_snippet_restore', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        if (!$this->core->check_rate_limit('restore')) {
            wp_send_json_error('Too many requests. Please wait a moment.');
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error('Invalid snippet ID');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';

        if (!$this->core->ensure_snippets_trash_schema()) {
            wp_send_json_error('Trash schema unavailable');
        }

        $snippet = $wpdb->get_row($wpdb->prepare("SELECT id, name FROM $table WHERE id = %d", $id));
        if (!$snippet) {
            wp_send_json_error('Snippet not found');
        }

        $wpdb->update(
            $table,
            array(
                'status' => 'active',
                'trashed_at' => null,
                'active' => 0 // Restored snippets are inactive for safety
            ),
            array('id' => $id)
        );

        $this->core->log_snippet_action('RESTORED', $id, $snippet->name, 'Restored from trash (inactive)');
        $this->core->clear_snippets_cache();

        wp_send_json_success(array('message' => 'Snippet restored.'));
    }

    /**
     * AJAX: Run snippet immediately (PHP snippets only)
     */
    public function ajax_run_snippet_now()
    {
        check_ajax_referer('ofast_snippet_run_now', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        if (!$this->core->check_rate_limit('run_now')) {
            wp_send_json_error('Too many requests. Please wait a moment.');
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error('Invalid snippet ID');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';
        $trash_supported = $this->core->ensure_snippets_trash_schema();

        if ($trash_supported) {
            $snippet = $wpdb->get_row($wpdb->prepare(
                "SELECT id, name, code, language, status FROM $table WHERE id = %d",
                $id
            ));
        } else {
            $snippet = $wpdb->get_row($wpdb->prepare(
                "SELECT id, name, code, language FROM $table WHERE id = %d",
                $id
            ));
        }

        if (!$snippet) {
            wp_send_json_error('Snippet not found');
        }

        if ($trash_supported && isset($snippet->status) && $snippet->status === 'trash') {
            wp_send_json_error('Cannot run a trashed snippet. Restore it first.');
        }

        if (!empty($snippet->language) && $snippet->language !== 'php') {
            wp_send_json_error('Run Now is only available for PHP snippets');
        }

        $runtime_code = $this->core->normalize_php_code($snippet->code);
        // No pre-validation — let PHP catch real errors at runtime (try/catch below)

        $output = '';
        $snippet_file = $this->core->write_snippet_file($runtime_code, $snippet->id);
        if (!$snippet_file) {
            wp_send_json_error('Could not create snippet file for execution.');
        }

        try {
            ob_start();
            include $snippet_file;
            $output = trim((string) ob_get_clean());
        } catch (Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            @unlink($snippet_file);
            $this->core->log_snippet_action('RUN_NOW_FAILED', $snippet->id, $snippet->name, $e->getMessage());
            wp_send_json_error($e->getMessage());
        }

        @unlink($snippet_file);

        $details = 'Executed manually from snippets table';
        if ($output !== '') {
            $details .= ' | Output length: ' . strlen($output);
        }
        $this->core->log_snippet_action('RUN_NOW', $snippet->id, $snippet->name, $details);

        wp_send_json_success(array(
            'message' => 'Snippet executed successfully.',
            'output' => $output
        ));
    }

    /**
     * AJAX: Duplicate snippet
     */
    public function ajax_duplicate_snippet()
    {
        check_ajax_referer('ofast_snippet_duplicate', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        if (!$this->core->check_rate_limit('duplicate')) {
            wp_send_json_error('Too many requests. Please wait a moment.');
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error('Invalid snippet ID');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';
        $priority_supported = $this->core->ensure_snippets_priority_schema();

        $snippet = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
        if (!$snippet) {
            wp_send_json_error('Snippet not found');
        }

        $base_name = trim((string) $snippet->name);
        $copy_num = 1;
        $new_name = $base_name . ' (Copy)';

        while ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE name = %s", $new_name)) > 0) {
            $copy_num++;
            $new_name = $base_name . ' (Copy ' . $copy_num . ')';
        }

        $duplicate_language = $this->core->normalize_snippet_language(isset($snippet->language) ? $snippet->language : 'php');
        $duplicated_code = isset($snippet->code) ? (string) $snippet->code : '';
        if ($duplicate_language === 'php') {
            $duplicated_code = $this->core->normalize_php_code($duplicated_code);
        }

        $insert_data = array(
            'name' => $new_name,
            'description' => isset($snippet->description) ? $snippet->description : '',
            'code' => $duplicated_code,
            'language' => $duplicate_language,
            'scope' => !empty($snippet->scope) ? $snippet->scope : 'global',
            'location' => !empty($snippet->location) ? $snippet->location : 'footer',
            'target_type' => !empty($snippet->target_type) ? $snippet->target_type : 'all',
            'target_value' => isset($snippet->target_value) ? $snippet->target_value : '',
            'run_once' => isset($snippet->run_once) ? intval($snippet->run_once) : 0,
            'active' => 0,
            'category' => isset($snippet->category) ? $snippet->category : '',
            'tags' => isset($snippet->tags) ? $snippet->tags : '',
            'executed_at' => null,
            'created_at' => current_time('mysql')
        );
        if ($priority_supported) {
            $insert_data['priority'] = isset($snippet->priority) ? max(1, intval($snippet->priority)) : 10;
        }

        $result = $wpdb->insert($table, $insert_data);

        if ($result === false) {
            wp_send_json_error('Failed to duplicate snippet: ' . $wpdb->last_error);
        }

        $new_id = (int) $wpdb->insert_id;
        $this->core->log_snippet_action('DUPLICATED', $new_id, $new_name, 'From snippet ID: ' . $id);
        $this->core->clear_snippets_cache();

        wp_send_json_success(array(
            'id' => $new_id,
            'name' => $new_name,
            'edit_url' => admin_url('admin.php?page=ofast-snippets&edit=' . $new_id)
        ));
    }

    /**
     * AJAX: Rename snippet
     */
    public function ajax_rename_snippet()
    {
        check_ajax_referer('ofast_snippet_rename', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Rate limiting
        if (!$this->core->check_rate_limit('rename')) {
            wp_send_json_error('Too many requests. Please wait a moment.');
        }

        $id = intval($_POST['id']);
        $name = sanitize_text_field($_POST['name']);

        if (empty($name)) {
            wp_send_json_error('Name cannot be empty');
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';

        // Get old name for logging
        $old_snippet = $wpdb->get_row($wpdb->prepare("SELECT name FROM $table WHERE id = %d", $id));

        $wpdb->update($table, array('name' => $name), array('id' => $id));

        // Audit log
        $this->core->log_snippet_action('RENAMED', $id, $name, $old_snippet ? "From: {$old_snippet->name}" : '');

        // Clear cache when snippet is renamed
        $this->core->clear_snippets_cache();

        wp_send_json_success(array('name' => $name));
    }

    /**
     * AJAX: Bulk action on snippets
     */
    public function ajax_bulk_action_snippets()
    {
        check_ajax_referer('ofast_bulk_action', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Rate limiting
        if (!$this->core->check_rate_limit('bulk_action')) {
            wp_send_json_error('Too many requests. Please wait a moment.');
        }

        $action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
        $ids = isset($_POST['ids']) ? array_map('intval', $_POST['ids']) : array();

        if (empty($action) || empty($ids)) {
            wp_send_json_error('Invalid request');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';
        $trash_supported = $this->core->ensure_snippets_trash_schema();
        $count = 0;
        $blocked = 0;
        $blocked_details = array();

        foreach ($ids as $id) {
            switch ($action) {
                case 'activate':
                    $snippet = $wpdb->get_row($wpdb->prepare("SELECT id, name, code, language FROM $table WHERE id = %d", $id));
                    if (!$snippet) {
                        $blocked++;
                        $blocked_details[] = array(
                            'id' => $id,
                            'name' => 'Snippet #' . $id,
                            'reason' => 'Snippet not found'
                        );
                        break;
                    }

                    if ($snippet->language === 'php' || empty($snippet->language)) {
                        $candidate_code = $this->core->normalize_php_code($snippet->code);

                        // Only check function conflicts (same as Code Snippets plugin)

                        $conflict_check = $this->core->validator->check_function_conflicts($candidate_code);
                        if ($conflict_check !== true) {
                            $blocked++;
                            $blocked_details[] = array(
                                'id' => (int) $snippet->id,
                                'name' => (string) $snippet->name,
                                'reason' => (string) $conflict_check
                            );
                            break;
                        }

                        $activation_conflicts = $this->core->validator->get_active_duplicate_conflicts($snippet->id, $snippet->name, $candidate_code);
                        if (!empty($activation_conflicts)) {
                            $blocked++;
                            $blocked_details[] = array(
                                'id' => (int) $snippet->id,
                                'name' => (string) $snippet->name,
                                'reason' => 'Duplicate conflict: ' . implode(' | ', array_slice($activation_conflicts, 0, 3))
                            );
                            break;
                        }
                    }

                    if ($trash_supported) {
                        $wpdb->update($table, array('active' => 1, 'status' => 'active', 'trashed_at' => null), array('id' => $id));
                    } else {
                        $wpdb->update($table, array('active' => 1), array('id' => $id));
                    }
                    $count++;
                    break;
                case 'deactivate':
                    if ($trash_supported) {
                        $wpdb->update($table, array('active' => 0, 'status' => 'active', 'trashed_at' => null), array('id' => $id));
                    } else {
                        $wpdb->update($table, array('active' => 0), array('id' => $id));
                    }
                    $count++;
                    break;
                case 'delete':
                    if ($trash_supported) {
                        $wpdb->update($table, array('active' => 0, 'status' => 'trash', 'trashed_at' => current_time('mysql')), array('id' => $id));
                    } else {
                        $wpdb->delete($table, array('id' => $id));
                    }
                    $count++;
                    break;
                case 'restore':
                    if ($trash_supported) {
                        $wpdb->update($table, array('active' => 0, 'status' => 'active', 'trashed_at' => null), array('id' => $id));
                        $count++;
                    }
                    break;
                case 'delete_permanently':
                    $wpdb->delete($table, array('id' => $id));
                    $count++;
                    break;
            }
        }

        // Audit log
        $this->core->log_snippet_action('BULK_' . strtoupper($action), 0, 'Bulk Action', "Count: {$count}");

        // Clear cache when bulk action is performed
        $this->core->clear_snippets_cache();

        wp_send_json_success(array(
            'count' => $count,
            'blocked' => $blocked,
            'blocked_details' => $blocked_details
        ));
    }

    /**
     * AJAX: Use library template
     */
    public function ajax_use_library_template()
    {
        check_ajax_referer('ofast_use_template', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $index = isset($_POST['index']) ? intval($_POST['index']) : -1;
        $force_copy = isset($_POST['force_copy']) ? (bool)$_POST['force_copy'] : false;

        // Load library
        $library_file = plugin_dir_path(dirname(__FILE__)) . 'snippets/library/snippets.json';
        if (!file_exists($library_file)) {
            // Try alternate path
            $library_file = plugin_dir_path(__FILE__) . 'library/snippets.json';
        }
        if (!file_exists($library_file)) {
            wp_send_json_error('Library file not found');
        }

        $library = json_decode(file_get_contents($library_file), true);
        if (!$library || !isset($library['snippets'][$index])) {
            wp_send_json_error('Template not found');
        }

        $template = $library['snippets'][$index];

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';

        // Check if snippet with same name already exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name FROM $table WHERE name = %s",
            $template['name']
        ));

        if ($existing && !$force_copy) {
            // Return info about existing snippet - let frontend ask user
            wp_send_json_success(array(
                'duplicate' => true,
                'existing_id' => $existing->id,
                'existing_name' => $existing->name,
                'message' => "'{$template['name']}' already exists. Would you like to edit the existing one or create a copy?"
            ));
            return;
        }

        // Determine the name (add "Copy" suffix if duplicate and force_copy)
        $snippet_name = $template['name'];
        if ($existing && $force_copy) {
            // Find a unique name
            $copy_num = 1;
            $new_name = $snippet_name . ' (Copy)';
            while ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE name = %s", $new_name))) {
                $copy_num++;
                $new_name = $snippet_name . ' (Copy ' . $copy_num . ')';
            }
            $snippet_name = $new_name;
        }

        // Insert as inactive
        $template_language = $this->core->normalize_snippet_language(isset($template['language']) ? $template['language'] : 'php');
        $template_code = isset($template['code']) ? (string) $template['code'] : '';
        if ($template_language === 'php') {
            $template_code = $this->core->normalize_php_code($template_code);
        }

        $result = $wpdb->insert($table, array(
            'name' => $snippet_name,
            'description' => $template['description'],
            'code' => $template_code,
            'language' => $template_language,
            'scope' => $template['scope'],
            'category' => $template['category'],
            'active' => 0,
            'location' => 'footer',
            'created_at' => current_time('mysql')
        ));

        if ($result === false) {
            wp_send_json_error('Failed to add template: ' . $wpdb->last_error);
        }

        // Log
        $this->core->log_snippet_action('TEMPLATE_USED', $wpdb->insert_id, $snippet_name, "Category: {$template['category']}");

        // Clear cache when template is used
        $this->core->clear_snippets_cache();

        wp_send_json_success(array(
            'message' => "'{$snippet_name}' added! It's set to INACTIVE - review and activate when ready.",
            'id' => $wpdb->insert_id
        ));
    }

    /**
     * AJAX: Get snippet revisions
     */
    public function ajax_get_revisions()
    {
        check_ajax_referer('ofast_get_revisions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $snippet_id = intval($_POST['snippet_id']);
        $revisions = $this->core->get_revisions($snippet_id);

        wp_send_json_success(array('revisions' => $revisions));
    }

    /**
     * AJAX: Restore snippet revision
     */
    public function ajax_restore_revision()
    {
        check_ajax_referer('ofast_restore_revision', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $revision_id = intval($_POST['revision_id']);

        global $wpdb;
        $rev_table = $wpdb->prefix . 'ofast_snippet_revisions';
        $snippet_table = $wpdb->prefix . 'ofast_snippets';

        // Get revision
        $revision = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $rev_table WHERE id = %d",
            $revision_id
        ));

        if (!$revision) {
            wp_send_json_error('Revision not found');
        }

        // Save current code as a new revision before restoring
        $current_snippet = $wpdb->get_row($wpdb->prepare(
            "SELECT code FROM $snippet_table WHERE id = %d",
            $revision->snippet_id
        ));

        if ($current_snippet) {
            $this->core->save_revision($revision->snippet_id, $current_snippet->code);
        }

        // Restore the revision code (set inactive for safety)
        $wpdb->update(
            $snippet_table,
            array('code' => $revision->code, 'active' => 0),
            array('id' => $revision->snippet_id)
        );

        // Clear cache so updated snippet code takes effect immediately
        $this->core->clear_snippets_cache();

        $this->core->log_snippet_action('RESTORED_REVISION', $revision->snippet_id, '', "From revision #{$revision_id}");

        wp_send_json_success(array(
            'message' => 'Revision restored! Snippet set to inactive for safety.',
            'code' => $revision->code
        ));
    }

    /**
     * AJAX: Save trash retention setting
     */
    public function ajax_save_trash_retention()
    {
        check_ajax_referer('ofast_save_retention', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $days = isset($_POST['days']) ? intval($_POST['days']) : 30;

        // Validate: only allow 0, 30, 60, 90
        if (!in_array($days, array(0, 30, 60, 90), true)) {
            $days = 30;
        }

        update_option('ofast_snippets_trash_retention', $days);

        wp_send_json_success(array('days' => $days));
    }

    /**
     * AJAX: Empty snippet trash (permanently delete all trashed snippets)
     */
    public function ajax_empty_snippet_trash()
    {
        check_ajax_referer('ofast_empty_trash', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        if (!$this->core->check_rate_limit('empty_trash')) {
            wp_send_json_error('Too many requests. Please wait a moment.');
        }

        if (!$this->core->ensure_snippets_trash_schema()) {
            wp_send_json_error('Trash schema unavailable.');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';

        $deleted = $wpdb->query("DELETE FROM $table WHERE status = 'trash'");

        $this->core->clear_snippets_cache();
        $this->core->log_snippet_action('EMPTIED_TRASH', 0, '', "Permanently deleted {$deleted} trashed snippet(s)");

        wp_send_json_success(array('deleted' => $deleted));
    }
}
