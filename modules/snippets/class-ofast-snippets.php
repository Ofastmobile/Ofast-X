<?php

/**
 * Ofast X - Code Snippets Module
 * Standalone snippet manager with toggle switches
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Snippets
{
    /**
     * Initialize module
     */
    public function init()
    {
        // NOTE: Module enabled check removed - core loader already verified this
        // before calling init(). See class-ofast-core.php is_module_enabled()

        // Auto-migrate: Add missing columns for trash system
        $this->maybe_add_trash_columns();

        // Add dashboard widget
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));

        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // AJAX handlers
        add_action('wp_ajax_ofast_toggle_snippet', array($this, 'ajax_toggle_snippet'));
        add_action('wp_ajax_ofast_delete_snippet', array($this, 'ajax_delete_snippet'));
        add_action('wp_ajax_ofast_rename_snippet', array($this, 'ajax_rename_snippet'));
        add_action('wp_ajax_ofast_export_snippets', array($this, 'ajax_export_snippets'));
        add_action('wp_ajax_ofast_import_snippets', array($this, 'ajax_import_snippets'));
        add_action('wp_ajax_ofast_bulk_action_snippets', array($this, 'ajax_bulk_action_snippets'));
        add_action('wp_ajax_ofast_import_from_plugin', array($this, 'ajax_import_from_plugin'));
        add_action('wp_ajax_ofast_preview_plugin_snippets', array($this, 'ajax_preview_plugin_snippets'));
        add_action('wp_ajax_ofast_selective_import_snippets', array($this, 'ajax_selective_import_snippets'));
        add_action('wp_ajax_ofast_use_library_template', array($this, 'ajax_use_library_template'));
        add_action('wp_ajax_ofast_get_revisions', array($this, 'ajax_get_revisions'));
        add_action('wp_ajax_ofast_restore_revision', array($this, 'ajax_restore_revision'));
        add_action('wp_ajax_ofast_restore_snippet', array($this, 'ajax_restore_snippet'));

        // Execute active snippets
        add_action('init', array($this, 'execute_snippets'), 999);

        // Show runtime error notices
        add_action('admin_notices', array($this, 'show_runtime_error_notice'));

        // Enqueue CodeMirror for code editor
        add_action('admin_enqueue_scripts', array($this, 'enqueue_codemirror'));

        // Schedule daily trash cleanup
        if (!wp_next_scheduled('ofast_snippets_cleanup_trash')) {
            wp_schedule_event(time(), 'daily', 'ofast_snippets_cleanup_trash');
        }
        add_action('ofast_snippets_cleanup_trash', array($this, 'cleanup_old_trash'));
    }

    /**
     * Enqueue CodeMirror for the snippet editor
     */
    public function enqueue_codemirror($hook)
    {
        // Only on our snippets page
        if ($hook !== 'ofast-x_page_ofast-snippets') {
            return;
        }

        // WordPress CodeMirror settings for PHP
        $settings = wp_enqueue_code_editor(array(
            'type' => 'application/x-httpd-php',
            'codemirror' => array(
                'lineNumbers' => true,
                'lineWrapping' => true,
                'indentUnit' => 4,
                'tabSize' => 4,
                'indentWithTabs' => false,
                'autoCloseBrackets' => true,
                'matchBrackets' => true,
                'autoCloseTags' => true,
                'foldGutter' => true,
                'gutters' => array('CodeMirror-linenumbers', 'CodeMirror-foldgutter'),
                'extraKeys' => array(
                    'Ctrl-/' => 'toggleComment',
                    'Cmd-/' => 'toggleComment',
                    'Ctrl-Space' => 'autocomplete',
                ),
            ),
        ));

        // If CodeMirror is disabled, bail
        if (false === $settings) {
            return;
        }

        // Also enqueue for JS
        wp_enqueue_code_editor(array('type' => 'text/javascript'));

        // Also enqueue for CSS
        wp_enqueue_code_editor(array('type' => 'text/css'));

        // Also enqueue for HTML
        wp_enqueue_code_editor(array('type' => 'text/html'));

        // Pass settings to our script
        wp_localize_script('code-editor', 'ofastCodeMirrorSettings', $settings);
    }

    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget()
    {
        wp_add_dashboard_widget(
            'ofast_snippets_widget',
            'Code Snippets',
            array($this, 'render_dashboard_widget')
        );
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'Code Snippets',
            'Code Snippets',
            'manage_options',
            'ofast-snippets',
            array($this, 'render_snippets_page')
        );
    }

    /**
     * Render dashboard widget
     */
    public function render_dashboard_widget()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';

        $snippets = $wpdb->get_results("SELECT * FROM $table WHERE status IS NULL OR status != 'trash' ORDER BY id DESC LIMIT 10");

        if (empty($snippets)) {
            echo '<p style="text-align: center; color: #999; padding: 20px;">No snippets yet. <a href="' . admin_url('admin.php?page=ofast-snippets') . '">Add your first snippet</a></p>';
            return;
        }

        echo '<div style="max-height: 300px; overflow-y: auto;">';
        echo '<table class="widefat" style="margin: 0;">';
        echo '<thead><tr><th>Snippet Name</th><th style="width: 80px; text-align: center;">Active</th></tr></thead>';
        echo '<tbody>';

        foreach ($snippets as $snippet) {
            // Validate snippet code
            $validation_result = $this->validate_php_code($snippet->code);
            $has_error = ($validation_result !== true);

            $active_class = $snippet->active ? 'button-primary' : '';

            // Add error indicator if validation fails
            $error_indicator = $has_error ? ' <span style="color: red; font-size: 16px;" title="' . esc_attr($validation_result) . '">● </span>' : '';

            echo '<tr>';
            echo '<td>' . $error_indicator . '<strong>' . esc_html($snippet->name) . '</strong>';
            if ($has_error) {
                echo '<br><small style="color: #dc3545;">' . esc_html($validation_result) . '</small>';
            }
            echo '</td>';
            echo '<td style="text-align: center;">';
            echo '<button class="button button-small ofast-snippet-toggle ' . esc_attr($active_class) . '" data-id="' . esc_attr($snippet->id) . '" data-active="' . esc_attr($snippet->active) . '" data-has-error="' . ($has_error ? '1' : '0') . '">';
            echo $snippet->active ? 'ON' : 'OFF';
            echo '</button>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '<p style="text-align: center; margin-top: 15px;"><a href="' . admin_url('admin.php?page=ofast-snippets') . '" class="button">Manage All Snippets</a></p>';

        // Add inline JavaScript
?>
        <script>
            jQuery(document).ready(function($) {
                $(document).on('click', '.ofast-snippet-toggle', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var id = $btn.data('id');
                    var active = $btn.data('active');
                    var hasError = $btn.data('has-error');

                    // Prevent activation if has errors
                    if (active == 0 && hasError == 1) {
                        alert('Cannot activate this snippet: it contains syntax errors.\n\nPlease fix the errors first from the Code Snippets management page.');
                        return;
                    }

                    $btn.prop('disabled', true);

                    $.post(ajaxurl, {
                        action: 'ofast_toggle_snippet',
                        nonce: '<?php echo wp_create_nonce('ofast_snippet_toggle'); ?>',
                        id: id,
                        active: active
                    }, function(response) {
                        if (response.success) {
                            var newActive = response.data.active;
                            $btn.data('active', newActive);
                            $btn.html(newActive ? 'ON' : 'OFF');
                            $btn.toggleClass('button-primary', newActive);
                        } else {
                            alert('Error: ' + response.data);
                        }
                    }).always(function() {
                        $btn.prop('disabled', false);
                    });
                });
            });
        </script>
    <?php
    }

    /**
     * Render snippets management page
     */
    public function render_snippets_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';

        // Handle add/edit with validation
        if (isset($_POST['ofast_save_snippet'])) {
            check_admin_referer('ofast_snippet_save', '_wpnonce');

            $id = isset($_POST['snippet_id']) ? intval($_POST['snippet_id']) : 0;
            $name = sanitize_text_field($_POST['snippet_name']);
            $description = isset($_POST['snippet_description']) ? wp_unslash($_POST['snippet_description']) : '';
            $language = isset($_POST['snippet_language']) ? sanitize_text_field($_POST['snippet_language']) : 'php';
            $scope = isset($_POST['snippet_scope']) ? sanitize_text_field($_POST['snippet_scope']) : 'global';
            $location = isset($_POST['snippet_location']) ? sanitize_text_field($_POST['snippet_location']) : 'footer';
            $run_once = isset($_POST['snippet_run_once']) ? 1 : 0;
            $target_type = isset($_POST['snippet_target_type']) ? sanitize_text_field($_POST['snippet_target_type']) : 'all';
            $target_value = isset($_POST['snippet_target_value']) ? sanitize_text_field($_POST['snippet_target_value']) : '';
            $category = isset($_POST['snippet_category']) ? sanitize_text_field($_POST['snippet_category']) : '';
            $priority = isset($_POST['snippet_priority']) ? intval($_POST['snippet_priority']) : 10;
            $priority = max(1, min(999, $priority)); // Clamp to 1-999

            // Process tags - convert comma-separated to JSON array
            $tags_raw = isset($_POST['snippet_tags']) ? sanitize_text_field($_POST['snippet_tags']) : '';
            $tags_array = array_filter(array_map('trim', explode(',', $tags_raw)));
            $tags_json = !empty($tags_array) ? json_encode(array_values($tags_array)) : '';

            $code = wp_unslash($_POST['snippet_code']);
            $active = isset($_POST['snippet_active']) ? 1 : 0;

            // Validate PHP syntax only for PHP snippets
            $validation = true;
            if ($language === 'php') {
                $validation = $this->validate_php_code($code);
            }

            // If validation fails, force inactive and show warning
            if ($validation !== true) {
                $active = 0; // Force inactive for safety
                $warning_msg = 'Code Saved But NOT Activated: ' . esc_html($validation) . ' Fix the syntax error and try activating again.';
                echo Ofast_X_Toast::render($warning_msg, 'warning');
            }

            if ($id > 0) {
                // Get old code to save as revision (only if code changed)
                $old_snippet = $wpdb->get_row($wpdb->prepare("SELECT code FROM $table WHERE id = %d", $id));
                if ($old_snippet && $old_snippet->code !== $code) {
                    $this->save_revision($id, $old_snippet->code);
                }

                // Update
                $result = $wpdb->update($table, array(
                    'name' => $name,
                    'description' => $description,
                    'language' => $language,
                    'scope' => $scope,
                    'location' => $location,
                    'run_once' => $run_once,
                    'target_type' => $target_type,
                    'target_value' => $target_value,
                    'category' => $category,
                    'tags' => $tags_json,
                    'code' => $code,
                    'active' => $active,
                    'priority' => $priority
                ), array('id' => $id));

                // Debug logging for live server issues
                if ($result === false) {
                    error_log('Ofast Snippet Update Error: ' . $wpdb->last_error);
                    echo Ofast_X_Toast::render('Database Error: ' . esc_html($wpdb->last_error), 'error');
                    return;
                }

                // Audit log
                $this->log_snippet_action('UPDATED', $id, $name, "Language: {$language}, Scope: {$scope}, Active: " . ($active ? 'Yes' : 'No'));

                if ($validation === true) {
                    echo Ofast_X_Toast::render('Snippet updated and ' . ($active ? 'activated' : 'saved') . '!', 'success');
                } else {
                    echo Ofast_X_Toast::render('Snippet saved (inactive for safety)', 'info');
                }
            } else {
                // DUPLICATE CHECK: Prevent saving snippets with same name
                $existing_name = $wpdb->get_row($wpdb->prepare("SELECT id, name FROM $table WHERE name = %s", $name));
                if ($existing_name) {
                    echo Ofast_X_Toast::render('Duplicate Name: A snippet named "' . esc_html($name) . '" already exists. Please use a different name or edit the existing snippet.', 'error');
                    // Don't redirect, let form stay with data so user can fix
                } else {
                    // DUPLICATE CODE CHECK: Prevent saving snippets with same code
                    $code_hash = md5(trim($code));
                    $existing_code = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, name FROM $table WHERE MD5(TRIM(code)) = %s",
                        $code_hash
                    ));
                    if ($existing_code) {
                        echo Ofast_X_Toast::render('Duplicate Code: This exact code already exists in snippet "' . esc_html($existing_code->name) . '" (ID: ' . $existing_code->id . '). Edit the existing snippet instead.', 'error');
                        // Don't save, let user decide
                    } else {
                        // Insert
                        $result = $wpdb->insert($table, array(
                            'name' => $name,
                            'description' => $description,
                            'language' => $language,
                            'scope' => $scope,
                            'location' => $location,
                            'run_once' => $run_once,
                            'target_type' => $target_type,
                            'target_value' => $target_value,
                            'category' => $category,
                            'tags' => $tags_json,
                            'code' => $code,
                            'active' => $active,
                            'priority' => $priority,
                            'created_at' => current_time('mysql')
                        ));

                        // Debug logging for live server issues
                        if ($result === false) {
                            error_log('Ofast Snippet Save Error: ' . $wpdb->last_error);
                            echo Ofast_X_Toast::render('Database Error: ' . esc_html($wpdb->last_error), 'error');
                            return;
                        }

                        $new_id = $wpdb->insert_id;

                        // Audit log
                        $this->log_snippet_action('CREATED', $new_id, $name, "Language: {$language}, Scope: {$scope}, Active: " . ($active ? 'Yes' : 'No'));

                        if ($validation === true) {
                            echo Ofast_X_Toast::render('Snippet added and ' . ($active ? 'activated' : 'saved') . '!', 'success');
                        } else {
                            echo Ofast_X_Toast::render('Snippet saved (inactive for safety)', 'info');
                        }
                    } // End of !$existing_code else block
                } // End of !$existing_name else block
            }
        }

        // Get all snippets (exclude trashed)
        $snippets = $wpdb->get_results("SELECT * FROM $table WHERE status IS NULL OR status != 'trash' ORDER BY id DESC");

        // Editing mode
        $editing = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $edit_snippet = $editing ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $editing)) : null;

    ?>
        <div class="wrap">
            <h1>Code Snippets Manager</h1>
            <p>Add PHP code snippets that run on your WordPress site. Use with caution!</p>

            <!-- Action Buttons Bar -->
            <div style="background: #f0f6fc; border: 1px solid #c3d9ed; border-radius: 8px; padding: 15px; margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <a href="<?php echo admin_url('admin.php?page=ofast-snippets'); ?>" class="button button-primary" style="display: inline-flex; align-items: center; gap: 5px;">
                    New Snippet
                </a>
                <select id="ofast-export-type" class="regular-text" style="width: auto;">
                    <option value="json">Export as JSON</option>
                    <option value="code">Export as Code</option>
                </select>
                <button type="button" class="button" id="ofast-export-snippets" style="display: inline-flex; align-items: center; gap: 5px;">
                    Export
                </button>
                <button type="button" class="button" id="ofast-import-snippets-btn" style="display: inline-flex; align-items: center; gap: 5px;">
                    Import
                </button>
                <input type="file" id="ofast-import-file" accept=".json" style="display: none;">
                <span style="color: #666; font-size: 12px; margin-left: auto;">
                    Total: <?php echo count($snippets); ?> snippet(s)
                </span>
            </div>

            <?php
            // Detect other snippet plugins
            $other_plugins = $this->detect_other_snippet_plugins();
            if (!empty($other_plugins)):
            ?>
                <!-- Import from Other Plugins -->
                <div style="background: #f0f6fc; border: 1px solid #c3d9ed; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0;">
                        <h3 style="margin: 0; color: #1d4ed8; font-size: 15px;">Import from Other Plugins</h3>
                        <button type="button" class="button" id="toggle-import-plugins">Show Plugins</button>
                    </div>
                    
                    <div id="import-plugins-content" style="display: none;">
                        <p style="color: #1e40af; margin: 15px 0; font-size: 13px;">We detected other snippet plugins on your site. You can import their snippets here.</p>
                        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                            <?php foreach ($other_plugins as $plugin): ?>
                                <div style="background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 12px; min-width: 280px;">
                                    <strong><?php echo esc_html($plugin['name']); ?></strong>
                                    <p style="margin: 5px 0; color: #666; font-size: 12px;">
                                        <?php echo intval($plugin['count']); ?> snippet(s) available
                                    </p>
                                    <div style="display: flex; gap: 8px; margin-top: 10px;">
                                        <button type="button" class="button ofast-preview-plugin-snippets"
                                            data-plugin="<?php echo esc_attr($plugin['slug']); ?>"
                                            data-plugin-name="<?php echo esc_attr($plugin['name']); ?>"
                                            style="flex: 1;">
                                            Preview & Import
                                        </button>
                                        <button type="button" class="button ofast-import-from-plugin"
                                            data-plugin="<?php echo esc_attr($plugin['slug']); ?>"
                                            style="flex: 1;">
                                            Import All
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top: 12px; display: flex; gap: 20px; flex-wrap: wrap; font-size: 11px;">
                            <span style="color: #1e40af;"><span style="color: #10b981; font-size: 14px;">●</span> Active in source plugin</span>
                            <span style="color: #1e40af;"><span style="color: #6b7280; font-size: 14px;">●</span> Inactive in source plugin</span>
                            <span style="color: #1e40af;"><span style="color: #ef4444; font-size: 14px;">●</span> Duplicate (already exists)</span>
                            <span style="color: #1e40af;"><span style="background: #d1fae5; color: #065f46; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 600;">SAFE</span> Syntax validated</span>
                        </div>
                        <p style="color: #1e40af; font-size: 11px; margin-top: 10px; margin-bottom: 0;">
                            All imported snippets will be set to <strong>INACTIVE</strong> for safety. Review and activate manually.
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Snippet Library -->
            <?php
            $library_file = plugin_dir_path(__FILE__) . 'library/snippets.json';
            $library = null;
            if (file_exists($library_file)) {
                $library = json_decode(file_get_contents($library_file), true);
            }

            if ($library && !empty($library['snippets'])):
            ?>
                <div style="background: #f0f6fc; border: 1px solid #c3d9ed; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <h3 style="margin: 0; color: #1d4ed8;">Snippet Library</h3>
                        <button type="button" class="button" id="toggle-library">Show Templates</button>
                    </div>

                    <div id="snippet-library" style="display: none;">
                        <p style="color: #1e40af; margin-bottom: 15px;">Pre-made snippets ready to use. Click "Use Template" to add to your snippets.</p>

                        <!-- Category Filter -->
                        <div style="margin-bottom: 15px;">
                            <button type="button" class="button library-cat-filter active" data-cat="all">All (<?php echo count($library['snippets']); ?>)</button>
                            <?php
                            $cat_counts = array();
                            foreach ($library['snippets'] as $s) {
                                $cat = $s['category'];
                                $cat_counts[$cat] = isset($cat_counts[$cat]) ? $cat_counts[$cat] + 1 : 1;
                            }
                            foreach ($library['categories'] as $cat):
                                $count = isset($cat_counts[$cat]) ? $cat_counts[$cat] : 0;
                                if ($count > 0):
                            ?>
                                    <button type="button" class="button library-cat-filter" data-cat="<?php echo esc_attr($cat); ?>"><?php echo esc_html($cat); ?> (<?php echo $count; ?>)</button>
                            <?php endif;
                            endforeach; ?>
                        </div>

                        <!-- Template Cards -->
                        <div id="library-templates" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px;">
                            <?php foreach ($library['snippets'] as $index => $template): ?>
                                <div class="library-template" data-category="<?php echo esc_attr($template['category']); ?>"
                                    style="background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 15px;">
                                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                                        <strong style="color: #1e40af;"><?php echo esc_html($template['name']); ?></strong>
                                        <span style="background: #e0e7ff; color: #3730a3; padding: 2px 8px; border-radius: 3px; font-size: 10px;">
                                            <?php echo esc_html($template['category']); ?>
                                        </span>
                                    </div>
                                    <p style="color: #666; font-size: 12px; margin-bottom: 10px;"><?php echo esc_html($template['description']); ?></p>
                                    <div style="display: flex; gap: 5px; align-items: center; margin-bottom: 10px;">
                                        <span style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px; font-size: 10px;">
                                            <?php echo strtoupper($template['language']); ?>
                                        </span>
                                        <span style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px; font-size: 10px;">
                                            <?php echo ucfirst($template['scope']); ?>
                                        </span>
                                    </div>
                                    <details style="margin-bottom: 10px;">
                                        <summary style="cursor: pointer; color: #6366f1; font-size: 12px;">Preview Code</summary>
                                        <pre style="background: #1e1e1e; color: #d4d4d4; padding: 10px; border-radius: 4px; font-size: 11px; overflow-x: auto; margin-top: 8px; max-height: 200px;"><?php echo esc_html($template['code']); ?></pre>
                                    </details>
                                    <button type="button" class="button button-primary use-library-template"
                                        data-index="<?php echo $index; ?>"
                                        style="width: 100%;">
                                        Use Template
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div style="background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                <h2><?php echo $editing ? 'Edit Snippet' : 'Add New Snippet'; ?></h2>
                <form method="post" class="ofast-snippet-form">
                    <?php wp_nonce_field('ofast_snippet_save', '_wpnonce'); ?>

                    <?php if ($editing): ?>
                        <input type="hidden" name="snippet_id" value="<?php echo $editing; ?>">
                    <?php endif; ?>

                    <!-- Two-Column Layout Wrapper (CSS Grid on desktop) -->
                    <div class="snippet-editor-layout">
                        <!-- Name and Description -->
                        <div class="snippet-name-section">
                            <div style="margin-bottom: 15px;">
                                <label for="snippet_name" style="display: block; font-size: 14px; font-weight: 500; color: #1e1e1e; margin-bottom: 6px;">Snippet Name</label>
                                <input type="text" name="snippet_name" id="snippet_name" class="regular-text" required
                                    value="<?php echo $edit_snippet ? esc_attr($edit_snippet->name) : ''; ?>"
                                    placeholder="e.g., Custom Header Code" style="width: 100%; max-width: 100%;">
                            </div>
                            <div style="margin-bottom: 15px;">
                                <label for="snippet_description" style="display: block; font-size: 14px; font-weight: 500; color: #1e1e1e; margin-bottom: 6px;">Description</label>
                                <textarea name="snippet_description" id="snippet_description" rows="2" class="large-text"
                                    placeholder="Brief description (optional)" style="width: 100%; max-width: 100%;"><?php echo $edit_snippet ? esc_textarea($edit_snippet->description) : ''; ?></textarea>
                            </div>
                        </div>

                        <!-- Right Column: Options -->
                        <div class="snippet-options-column">
                            <table class="form-table">
                                <tr>
                                    <th><label for="snippet_category">Category</label></th>
                                    <td>
                                        <?php
                                        // Get existing categories for autocomplete
                                        $existing_categories = $wpdb->get_col("SELECT DISTINCT category FROM {$wpdb->prefix}ofast_snippets WHERE category != '' ORDER BY category");
                                        $current_category = ($edit_snippet && isset($edit_snippet->category)) ? $edit_snippet->category : '';
                                        ?>
                                        <input type="text" name="snippet_category" id="snippet_category" class="regular-text"
                                            value="<?php echo esc_attr($current_category); ?>"
                                            placeholder="e.g., WooCommerce, Security, Performance"
                                            list="snippet_categories_list">
                                        <datalist id="snippet_categories_list">
                                            <?php foreach ($existing_categories as $cat): ?>
                                                <option value="<?php echo esc_attr($cat); ?>">
                                                <?php endforeach; ?>
                                        </datalist>
                                        <p class="description">Type to search existing categories or create a new one.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="snippet_tags">Tags</label></th>
                                    <td>
                                        <?php
                                        // Get existing tags for autocomplete
                                        $existing_tags = array();
                                        $all_tags_raw = $wpdb->get_col("SELECT DISTINCT tags FROM {$wpdb->prefix}ofast_snippets WHERE tags != '' AND tags IS NOT NULL");
                                        foreach ($all_tags_raw as $tags_json) {
                                            $tags_arr = json_decode($tags_json, true);
                                            if (is_array($tags_arr)) {
                                                $existing_tags = array_merge($existing_tags, $tags_arr);
                                            }
                                        }
                                        $existing_tags = array_unique(array_filter($existing_tags));
                                        sort($existing_tags);

                                        $current_tags = '';
                                        if ($edit_snippet && !empty($edit_snippet->tags)) {
                                            $tags_arr = json_decode($edit_snippet->tags, true);
                                            if (is_array($tags_arr)) {
                                                $current_tags = implode(', ', $tags_arr);
                                            }
                                        }
                                        ?>
                                        <input type="text" name="snippet_tags" id="snippet_tags" class="regular-text"
                                            value="<?php echo esc_attr($current_tags); ?>"
                                            placeholder="e.g., woocommerce, hooks, filter"
                                            list="snippet_tags_list">
                                        <datalist id="snippet_tags_list">
                                            <?php foreach ($existing_tags as $tag): ?>
                                                <option value="<?php echo esc_attr($tag); ?>">
                                                <?php endforeach; ?>
                                        </datalist>
                                        <p class="description">Comma-separated tags for easier filtering. Example: security, login, performance</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="snippet_language">Language</label></th>
                                    <td>
                                        <select name="snippet_language" id="snippet_language" class="regular-text">
                                            <option value="php" <?php selected($edit_snippet ? $edit_snippet->language : 'php', 'php'); ?>>PHP</option>
                                            <option value="javascript" <?php selected($edit_snippet ? $edit_snippet->language : '', 'javascript'); ?>>JavaScript</option>
                                            <option value="css" <?php selected($edit_snippet ? $edit_snippet->language : '', 'css'); ?>>CSS</option>
                                            <option value="html" <?php selected($edit_snippet ? $edit_snippet->language : '', 'html'); ?>>HTML</option>
                                        </select>
                                        <p class="description">Select the code language for this snippet.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="snippet_scope">Run Location</label></th>
                                    <td>
                                        <select name="snippet_scope" id="snippet_scope" class="regular-text">
                                            <option value="global" <?php selected($edit_snippet ? $edit_snippet->scope : 'global', 'global'); ?>>Run Everywhere</option>
                                            <option value="admin" <?php selected($edit_snippet ? $edit_snippet->scope : '', 'admin'); ?>>Admin Only</option>
                                            <option value="frontend" <?php selected($edit_snippet ? $edit_snippet->scope : '', 'frontend'); ?>>Frontend Only</option>
                                        </select>
                                        <p class="description">Choose where this snippet should execute.</p>
                                    </td>
                                </tr>
                                <tr class="snippet-location-row">
                                    <th><label for="snippet_location">Injection Location</label></th>
                                    <td>
                                        <?php $location = ($edit_snippet && isset($edit_snippet->location)) ? $edit_snippet->location : 'footer'; ?>
                                        <select name="snippet_location" id="snippet_location" class="regular-text">
                                            <option value="header" <?php selected($location, 'header'); ?>>Header (before &lt;/head&gt;)</option>
                                            <option value="body" <?php selected($location, 'body'); ?>>Body (after &lt;body&gt;)</option>
                                            <option value="footer" <?php selected($location, 'footer'); ?>>Footer (before &lt;/body&gt;)</option>
                                        </select>
                                        <p class="description">Where to inject JS/CSS/HTML code. (PHP always runs on init)</p>
                                    </td>
                                </tr>
                                <tr class="snippet-targeting-row">
                                    <th><label for="snippet_target_type">Page Targeting</label></th>
                                    <td>
                                        <select name="snippet_target_type" id="snippet_target_type" class="regular-text">
                                            <?php $target_type = ($edit_snippet && isset($edit_snippet->target_type)) ? $edit_snippet->target_type : 'all'; ?>
                                            <option value="all" <?php selected($target_type, 'all'); ?>>All Pages</option>
                                            <option value="homepage" <?php selected($target_type, 'homepage'); ?>>Homepage Only</option>
                                            <option value="post_type" <?php selected($target_type, 'post_type'); ?>>Specific Post Type</option>
                                            <option value="page_ids" <?php selected($target_type, 'page_ids'); ?>>Specific Page/Post IDs</option>
                                            <option value="url_contains" <?php selected($target_type, 'url_contains'); ?>>URL Contains</option>
                                        </select>
                                        <p class="description">Choose which pages this snippet runs on.</p>
                                    </td>
                                </tr>
                                <tr class="snippet-target-value-row" style="display: none;">
                                    <th><label for="snippet_target_value">Target Value</label></th>
                                    <td>
                                        <?php $target_value = ($edit_snippet && isset($edit_snippet->target_value)) ? $edit_snippet->target_value : ''; ?>
                                        <input type="text" name="snippet_target_value" id="snippet_target_value" class="regular-text"
                                            value="<?php echo esc_attr($target_value); ?>"
                                            placeholder="">
                                        </p>
                                    </td>
                                    <!-- Run Once & Activation (desktop only - hidden on mobile) -->
                                <tr class="snippet-actions-desktop">
                                    <th><label for="snippet_run_once_desktop">Run Once</label></th>
                                    <td>
                                        <?php $run_once = ($edit_snippet && isset($edit_snippet->run_once)) ? $edit_snippet->run_once : false; ?>
                                        <label class="ofast-toggle-switch">
                                            <input type="checkbox" id="snippet_run_once_desktop" value="1" <?php checked($run_once); ?>>
                                            <span class="ofast-toggle-slider"></span>
                                            <span class="ofast-toggle-label">Execute only once, then auto-deactivate</span>
                                        </label>
                                        <p class="description">Snippet will run one time and then automatically deactivate itself.</p>
                                    </td>
                                </tr>
                                <tr class="snippet-actions-desktop">
                                    <th><label for="snippet_priority">Execution Priority</label></th>
                                    <td>
                                        <?php $priority = ($edit_snippet && isset($edit_snippet->priority)) ? $edit_snippet->priority : 10; ?>
                                        <input type="number" name="snippet_priority" id="snippet_priority" 
                                               value="<?php echo esc_attr($priority); ?>" 
                                               min="1" max="999" step="1" class="small-text" style="width: 70px;">
                                        <p class="description">
                                            Lower numbers run first. Default: 10<br>
                                            <span style="color: #666;">1-9: Critical | 10: Normal | 11-99: Late | 100+: Last</span>
                                        </p>
                                    </td>
                                </tr>
                                <tr class="snippet-actions-desktop">
                                    <th>Activation</th>
                                    <td>
                                        <label class="ofast-toggle-switch">
                                            <input type="checkbox" id="snippet_active_desktop" value="1" <?php checked($edit_snippet ? $edit_snippet->active : false); ?>>
                                            <span class="ofast-toggle-slider"></span>
                                            <span class="ofast-toggle-label">Activate snippet after saving</span>
                                        </label>
                                        <p class="description">Toggle ON to activate immediately, or leave OFF to save as inactive.</p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <!-- Left Column: Code Editor (on desktop, appears on left due to flexbox order) -->
                        <div class="snippet-code-column">
                            <!-- Code Editor -->
                            <h4 style="margin: 0 0 10px 0; font-size: 14px; font-weight: 500; color: #1e1e1e;">Code</h4>
                            <textarea name="snippet_code" id="snippet_code" rows="15" class="large-text code" required
                                placeholder="Enter your code here..."><?php echo $edit_snippet ? esc_textarea($edit_snippet->code) : ''; ?></textarea>
                            <p class="description" id="snippet_code_help">
                                <span class="php-help">Enter PHP code without &lt;?php ?&gt; tags. Be careful - bad code can break your site!</span>
                                <span class="js-help" style="display:none;">Enter JavaScript code. Will be wrapped in &lt;script&gt; tags automatically.</span>
                                <span class="css-help" style="display:none;">Enter CSS code. Will be wrapped in &lt;style&gt; tags automatically.</span>
                                <span class="html-help" style="display:none;">Enter HTML code. Will be output directly on the page.</span>
                            </p>
                        </div>
                    </div>
                    <!-- End Two-Column Layout -->

                    <!-- Run Once & Activation (appears below code on mobile) -->
                    <div class="snippet-actions-section" style="margin-top: 20px;">
                        <table class="form-table" style="margin: 0;">
                            <tr>
                                <th style="width: 120px;"><label for="snippet_run_once">Run Once</label></th>
                                <td>
                                    <?php $run_once = ($edit_snippet && isset($edit_snippet->run_once)) ? $edit_snippet->run_once : false; ?>
                                    <label class="ofast-toggle-switch">
                                        <input type="checkbox" name="snippet_run_once" id="snippet_run_once" value="1" <?php checked($run_once); ?>>
                                        <span class="ofast-toggle-slider"></span>
                                        <span class="ofast-toggle-label">Execute only once, then auto-deactivate</span>
                                    </label>
                                    <p class="description">Snippet will run one time and then automatically deactivate itself.</p>
                                </td>
                            </tr>
                            <tr>
                                <th style="width: 120px;">Activation</th>
                                <td>
                                    <label class="ofast-toggle-switch">
                                        <input type="checkbox" name="snippet_active" value="1" <?php checked($edit_snippet ? $edit_snippet->active : false); ?>>
                                        <span class="ofast-toggle-slider"></span>
                                        <span class="ofast-toggle-label">Activate snippet after saving</span>
                                    </label>
                                    <p class="description">Toggle ON to activate immediately, or leave OFF to save as inactive.</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <p class="submit">
                        <button type="submit" name="ofast_save_snippet" class="button button-primary">
                            <?php echo $editing ? 'Update Snippet' : 'Add Snippet'; ?>
                        </button>
                        <?php if ($editing): ?>
                            <button type="button" class="button" id="view-history-btn" data-snippet-id="<?php echo $editing; ?>" style="margin-left: 10px;">
                                View History
                            </button>
                            <a href="<?php echo admin_url('admin.php?page=ofast-snippets'); ?>" class="button">Cancel</a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>

            <style>
                /* Minimal Form Layout */
                .ofast-snippet-form {
                    max-width: 900px;
                }

                .ofast-snippet-form .form-table {
                    margin-top: 15px;
                }

                .ofast-snippet-form .form-table th {
                    width: 120px;
                    padding: 12px 10px 12px 0;
                    font-weight: 500;
                    font-size: 13px;
                    color: #1e1e1e;
                }

                .ofast-snippet-form .form-table td {
                    padding: 10px 0;
                }

                .ofast-snippet-form input[type="text"],
                .ofast-snippet-form select {
                    max-width: 400px;
                    width: 100%;
                    padding: 8px 12px;
                    border: 1px solid #ddd;
                    border-radius: 6px;
                    font-size: 14px;
                    transition: border-color 0.2s, box-shadow 0.2s;
                }

                .ofast-snippet-form input[type="text"]:focus,
                .ofast-snippet-form select:focus {
                    border-color: #6366f1;
                    box-shadow: 0 0 0 2px rgba(34, 113, 177, 0.1);
                    outline: none;
                }

                .ofast-snippet-form textarea#snippet_description {
                    max-width: 500px;
                    width: 100%;
                    padding: 10px 12px;
                    border: 1px solid #ddd;
                    border-radius: 6px;
                    font-size: 13px;
                    resize: vertical;
                    min-height: 60px;
                    font-family: inherit;
                }

                .ofast-snippet-form textarea#snippet_description:focus {
                    border-color: #6366f1;
                    box-shadow: 0 0 0 2px rgba(34, 113, 177, 0.1);
                    outline: none;
                }

                .ofast-snippet-form .description {
                    color: #666;
                    font-size: 12px;
                    margin-top: 6px;
                }

                /* CodeMirror Styling */
                .ofast-snippet-form .CodeMirror {
                    max-width: 100%;
                    border: 1px solid #ddd;
                    border-radius: 8px;
                    font-size: 13px;
                    line-height: 1.5;
                    height: 300px;
                    font-family: 'SF Mono', 'Monaco', 'Consolas', monospace;
                }

                .ofast-snippet-form .CodeMirror-focused {
                    border-color: #6366f1;
                    box-shadow: 0 0 0 2px rgba(34, 113, 177, 0.1);
                }

                .ofast-snippet-form .CodeMirror-gutters {
                    background: #f8f9fa;
                    border-right: 1px solid #e5e5e5;
                    border-radius: 8px 0 0 8px;
                }

                .ofast-snippet-form .CodeMirror-linenumber {
                    color: #999;
                    font-size: 11px;
                }

                /* Submit Area */
                .ofast-snippet-form .submit {
                    border-top: 1px solid #eee;
                    padding-top: 20px;
                    margin-top: 10px;
                }

                /* Two-Column Layout - Desktop Only */
                @media screen and (min-width: 1200px) {
                    .ofast-snippet-form {
                        max-width: 100%;
                    }

                    .snippet-editor-layout {
                        display: grid;
                        grid-template-columns: 1fr 380px;
                        grid-template-rows: auto auto;
                        gap: 20px 30px;
                        align-items: start;
                    }

                    /* Name section: Column 1, Row 1 */
                    .snippet-name-section {
                        grid-column: 1;
                        grid-row: 1;
                    }

                    /* Code column: Column 1, Row 2 */
                    .snippet-code-column {
                        grid-column: 1;
                        grid-row: 2;
                    }

                    /* Options column: Column 2, spanning rows 1-2 */
                    .snippet-options-column {
                        grid-column: 2;
                        grid-row: 1 / 3;
                    }

                    .snippet-code-column .CodeMirror {
                        height: 500px;
                    }

                    .snippet-options-column .form-table th {
                        width: 100px;
                    }

                    .snippet-options-column .form-table {
                        margin-top: 0;
                    }

                    .snippet-options-column input[type="text"],
                    .snippet-options-column select,
                    .snippet-options-column textarea {
                        max-width: 100%;
                    }

                    /* Hide mobile actions section on desktop - show in options column instead */
                    .snippet-actions-section {
                        display: none;
                    }
                }

                /* Hide desktop-only actions rows on mobile/tablet (below 1200px) */
                @media screen and (max-width: 1199px) {
                    .snippet-actions-desktop {
                        display: none;
                    }
                }

                /* Responsive Styles */
                @media screen and (max-width: 782px) {

                    .ofast-snippet-form .form-table th,
                    .ofast-snippet-form .form-table td {
                        display: block;
                        width: 100%;
                        padding: 8px 0;
                    }

                    .ofast-snippet-form .form-table th {
                        padding-bottom: 4px;
                    }

                    .ofast-snippet-form input[type="text"],
                    .ofast-snippet-form select,
                    .ofast-snippet-form textarea#snippet_description {
                        max-width: 100%;
                    }

                    .ofast-snippet-form .CodeMirror {
                        height: 250px;
                    }
                }

                @media screen and (max-width: 480px) {
                    .ofast-snippet-form .CodeMirror {
                        height: 200px;
                        font-size: 12px;
                    }

                    .ofast-snippet-form .submit .button {
                        width: 100%;
                        margin-bottom: 10px;
                    }
                }

                /* Modern Toggle Switch */
                .ofast-toggle-switch {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    cursor: pointer;
                    user-select: none;
                }

                .ofast-toggle-switch input[type="checkbox"] {
                    position: absolute;
                    opacity: 0;
                    width: 0;
                    height: 0;
                }

                .ofast-toggle-slider {
                    position: relative;
                    display: inline-block;
                    width: 50px;
                    height: 26px;
                    background-color: #ccc;
                    border-radius: 26px;
                    transition: 0.3s;
                    flex-shrink: 0;
                }

                .ofast-toggle-slider:before {
                    content: "";
                    position: absolute;
                    height: 20px;
                    width: 20px;
                    left: 3px;
                    bottom: 3px;
                    background-color: white;
                    border-radius: 50%;
                    transition: 0.3s;
                }

                .ofast-toggle-switch input:checked+.ofast-toggle-slider {
                    background-color: #6366f1;
                }

                .ofast-toggle-switch input:checked+.ofast-toggle-slider:before {
                    transform: translateX(24px);
                }

                .ofast-toggle-switch:hover .ofast-toggle-slider {
                    opacity: 0.8;
                }

                .ofast-toggle-label {
                    font-weight: 500;
                }

                /* Inline editing styles */
                .snippet-name-display {
                    cursor: pointer;
                    position: relative;
                    display: inline-block;
                }

                .snippet-name-display .edit-icon {
                    opacity: 0;
                    margin-left: 8px;
                    font-size: 14px;
                    transition: opacity 0.2s;
                }

                .snippet-name-display:hover .edit-icon {
                    opacity: 0.6;
                }

                .snippet-name-edit {
                    width: 300px;
                    padding: 4px 8px;
                    border: 1px solid #6366f1;
                    border-radius: 3px;
                }
            </style>

            <h2>Saved Snippets (<?php echo count($snippets); ?>)</h2>

            <?php if (empty($snippets)): ?>
                <p style="color: #999;">No snippets yet. Add your first one above!</p>
            <?php else: ?>
                <!-- Search and Bulk Actions Bar -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <select id="bulk-action-select" class="regular-text" style="width: auto;">
                            <option value="">Bulk Actions</option>
                            <option value="activate">Activate</option>
                            <option value="deactivate">Deactivate</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="button" class="button" id="apply-bulk-action">Apply</button>

                        <!-- Category Filter -->
                        <?php
                        $all_categories = $wpdb->get_col("SELECT DISTINCT category FROM {$wpdb->prefix}ofast_snippets WHERE category != '' ORDER BY category");
                        if (!empty($all_categories)):
                        ?>
                            <select id="category-filter" style="width: auto;">
                                <option value="">All Categories</option>
                                <?php foreach ($all_categories as $cat): ?>
                                    <option value="<?php echo esc_attr($cat); ?>"><?php echo esc_html($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div>
                        <input type="text" id="snippet-search" placeholder="Search name, description, code, tags..." style="width: 300px;">
                    </div>
                </div>

                <!-- Scrollable Table Container -->
                <div style="overflow-x: auto; max-width: 100%;">
                    <table class="wp-list-table widefat fixed striped" id="snippets-table" style="min-width: 1000px;">
                        <thead>
                            <tr>
                                <th style="width: 30px;"><input type="checkbox" id="select-all-snippets"></th>
                                <th style="width: 35px;">ID</th>
                                <th style="width: 200px;">Name</th>
                                <th style="width: 100px;">Category</th>
                                <th>Description</th>
                                <th style="width: 75px;">Language</th>
                                <th style="width: 85px;">Scope</th>
                                <th style="width: 65px;">Inject</th>
                                <th style="width: 70px;">Status</th>
                                <th style="width: 80px;">Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($snippets as $snippet):
                                // Language text labels
                                $lang_labels = array('php' => 'PHP', 'javascript' => 'JavaScript', 'css' => 'CSS', 'html' => 'HTML');
                                $lang = $snippet->language ?: 'php';
                                $lang_display = isset($lang_labels[$lang]) ? $lang_labels[$lang] : 'PHP';

                                // Scope text labels
                                $scope_labels = array('global' => 'Everywhere', 'admin' => 'Admin Only', 'frontend' => 'Frontend');
                                $scope = $snippet->scope ?: 'global';
                                $scope_display = isset($scope_labels[$scope]) ? $scope_labels[$scope] : 'Everywhere';

                                // Location text labels
                                $loc_labels = array('header' => 'Header', 'body' => 'Body', 'footer' => 'Footer');
                                $loc = isset($snippet->location) && !empty($snippet->location) ? $snippet->location : 'footer';
                                $loc_display = isset($loc_labels[$loc]) ? $loc_labels[$loc] : 'Footer';

                                // Status and run once - button shows ACTION to take
                                $status_text = $snippet->active ? 'Deactivate' : 'Activate';
                                $run_once_text = (isset($snippet->run_once) && $snippet->run_once) ? ' (Once)' : '';

                                // Category
                                $snippet_category = isset($snippet->category) ? $snippet->category : '';

                                // Check for potential duplicates (only for PHP and inactive snippets)
                                $duplicate_warning = array('has_duplicate' => false, 'reasons' => array());
                                if (!$snippet->active && ($snippet->language === 'php' || empty($snippet->language))) {
                                    $duplicate_warning = $this->get_potential_duplicates($snippet->id, $snippet->name, $snippet->code);
                                }
                            ?>
                                <tr class="snippet-row"
                                    data-name="<?php echo esc_attr(strtolower($snippet->name)); ?>"
                                    data-description="<?php echo esc_attr(strtolower($snippet->description ?? '')); ?>"
                                    data-category="<?php echo esc_attr($snippet_category); ?>"
                                    data-code="<?php echo esc_attr(strtolower(substr($snippet->code, 0, 2000))); ?>"
                                    data-tags="<?php echo esc_attr(strtolower($snippet->tags ?? '')); ?>">
                                    <td><input type="checkbox" class="snippet-checkbox" value="<?php echo $snippet->id; ?>"></td>
                                    <td><?php echo $snippet->id; ?></td>
                                    <td>
                                        <?php if ($duplicate_warning['has_duplicate']): ?>
                                            <span class="duplicate-warning" title="<?php echo esc_attr(implode(' | ', $duplicate_warning['reasons'])); ?>" style="display: inline-block; width: 10px; height: 10px; background: #dc3545; border-radius: 50%; margin-right: 5px; cursor: help; vertical-align: middle;" data-tooltip="<?php echo esc_attr(implode("\n", $duplicate_warning['reasons'])); ?>"></span>
                                        <?php endif; ?>
                                        <span class="snippet-name-display" data-id="<?php echo $snippet->id; ?>" style="cursor: pointer; color: #6366f1;" title="Click to rename">
                                            <strong><?php echo esc_html($snippet->name); ?></strong>
                                        </span>
                                        <input type="text" class="snippet-name-edit" data-id="<?php echo $snippet->id; ?>" value="<?php echo esc_attr($snippet->name); ?>" style="display:none; width: 100%;">
                                        <div class="row-actions" style="margin-top: 3px; font-size: 12px;">
                                            <a href="?page=ofast-snippets&edit=<?php echo $snippet->id; ?>">Edit</a> |
                                            <a href="#" class="ofast-snippet-delete" data-id="<?php echo $snippet->id; ?>" data-active="<?php echo $snippet->active; ?>" data-name="<?php echo esc_attr($snippet->name); ?>" style="color: #b32d2e;">Delete</a>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($snippet_category)): ?>
                                            <span style="background: #e7f3ff; color: #6366f1; padding: 2px 8px; border-radius: 3px; font-size: 11px;"><?php echo esc_html($snippet_category); ?></span>
                                        <?php else: ?>
                                            <span style="color: #999;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="word-wrap: break-word; white-space: normal;">
                                        <?php
                                        if (!empty($snippet->description)) {
                                            echo '<span style="color: #666;">' . esc_html($snippet->description) . '</span>';
                                        } else {
                                            echo '<span style="color: #999;">—</span>';
                                        }

                                        // Display tags as badges
                                        if (!empty($snippet->tags)) {
                                            $tags_arr = json_decode($snippet->tags, true);
                                            if (!empty($tags_arr) && is_array($tags_arr)) {
                                                echo '<div style="margin-top: 5px;">';
                                                foreach ($tags_arr as $tag) {
                                                    echo '<span style="background: #f0e6ff; color: #5b21b6; padding: 1px 6px; border-radius: 3px; font-size: 10px; margin-right: 4px; display: inline-block; margin-bottom: 2px;">' . esc_html($tag) . '</span>';
                                                }
                                                echo '</div>';
                                            }
                                        }
                                        ?>
                                    </td>
                                    <td><span style="background: #f0f0f0; padding: 2px 8px; border-radius: 3px; font-size: 11px;"><?php echo $lang_display; ?></span></td>
                                    <td><span style="font-size: 12px;"><?php echo $scope_display; ?></span></td>
                                    <td><span style="font-size: 12px;"><?php echo $loc_display . $run_once_text; ?></span></td>
                                    <td>
                                        <button class="button button-small ofast-snippet-toggle <?php echo $snippet->active ? 'button-primary' : ''; ?>"
                                            data-id="<?php echo $snippet->id; ?>" data-active="<?php echo $snippet->active; ?>"
                                            style="min-width: 60px; font-size: 11px;">
                                            <?php echo $status_text; ?>
                                        </button>
                                    </td>
                                    <td style="font-size: 11px;"><?php echo date('M j, Y', strtotime($snippet->created_at)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php
        // Get trashed snippets for trash panel
        $trashed_snippets = $wpdb->get_results("SELECT * FROM $table WHERE status = 'trash' ORDER BY trashed_at DESC");
        $trash_count = count($trashed_snippets);
        ?>
        
        <!-- Trash Panel -->
        <div class="ofast-trash-panel" style="margin-top: 30px; background: #fff; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;">
            <div class="trash-header" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; cursor: pointer;" id="toggle-trash-panel">
                <h3 style="margin: 0; font-size: 14px; font-weight: 500;">
                    <span class="dashicons dashicons-trash" style="margin-right: 8px;"></span>
                    Trash (<?php echo $trash_count; ?>)
                </h3>
                <span class="toggle-icon" style="font-size: 18px;">▼</span>
            </div>
            
            <div class="trash-content" style="display: none; padding: 20px;">
                <?php if ($trash_count > 0): ?>
                    <p style="color: #666; margin: 0 0 15px; font-size: 13px;">
                        <span class="dashicons dashicons-info" style="color: #2271b1;"></span>
                        Items in trash are automatically deleted after 30 days.
                    </p>
                    
                    <table class="widefat striped" style="margin-bottom: 15px;">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th style="width: 120px;">Deleted</th>
                                <th style="width: 80px;">Days Left</th>
                                <th style="width: 180px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trashed_snippets as $trashed): 
                                $days_since = floor((time() - strtotime($trashed->trashed_at)) / 86400);
                                $days_left = max(0, 30 - $days_since);
                            ?>
                            <tr class="trash-row" data-id="<?php echo esc_attr($trashed->id); ?>">
                                <td>
                                    <strong><?php echo esc_html($trashed->name); ?></strong>
                                    <br><small style="color: #999;"><?php echo esc_html($trashed->language ?? 'php'); ?></small>
                                </td>
                                <td style="font-size: 12px;"><?php echo date('M j, Y', strtotime($trashed->trashed_at)); ?></td>
                                <td>
                                    <span style="color: <?php echo $days_left < 7 ? '#dc3545' : '#666'; ?>; font-weight: <?php echo $days_left < 7 ? '600' : '400'; ?>;">
                                        <?php echo $days_left; ?> days
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="button button-small restore-snippet" data-id="<?php echo esc_attr($trashed->id); ?>" style="margin-right: 5px;">
                                        <span class="dashicons dashicons-undo" style="vertical-align: middle; margin-right: 3px;"></span>Restore
                                    </button>
                                    <button type="button" class="button button-small delete-forever" data-id="<?php echo esc_attr($trashed->id); ?>" style="color: #dc3545;">
                                        <span class="dashicons dashicons-dismiss" style="vertical-align: middle; margin-right: 3px;"></span>Delete
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <button type="button" id="empty-all-trash" class="button" style="color: #dc3545; border-color: #dc3545;">
                        <span class="dashicons dashicons-trash" style="vertical-align: middle; margin-right: 5px;"></span>
                        Empty Trash (<?php echo $trash_count; ?> items)
                    </button>
                <?php else: ?>
                    <p style="text-align: center; color: #999; padding: 30px; margin: 0;">
                        <span class="dashicons dashicons-yes-alt" style="font-size: 32px; display: block; margin-bottom: 10px; color: #46b450;"></span>
                        Trash is empty
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Initialize CodeMirror on the code textarea
                var cmEditor = null;
                var $codeTextarea = $('#snippet_code');

                if ($codeTextarea.length && typeof wp !== 'undefined' && wp.codeEditor) {
                    // Get language-specific settings
                    var language = $('#snippet_language').val() || 'php';
                    var mimeTypes = {
                        'php': 'application/x-httpd-php',
                        'javascript': 'text/javascript',
                        'css': 'text/css',
                        'html': 'text/html'
                    };

                    // Initialize CodeMirror
                    cmEditor = wp.codeEditor.initialize($codeTextarea, {
                        codemirror: {
                            mode: mimeTypes[language] || 'application/x-httpd-php',
                            lineNumbers: true,
                            lineWrapping: true,
                            indentUnit: 4,
                            tabSize: 4,
                            indentWithTabs: false,
                            autoCloseBrackets: true,
                            matchBrackets: true,
                            autoCloseTags: true,
                            extraKeys: {
                                'Ctrl-/': 'toggleComment',
                                'Cmd-/': 'toggleComment',
                                'Tab': function(cm) {
                                    cm.replaceSelection('    ', 'end');
                                }
                            }
                        }
                    });

                    // Switch CodeMirror mode when language changes
                    $('#snippet_language').on('change', function() {
                        var newLang = $(this).val();
                        var newMode = mimeTypes[newLang] || 'application/x-httpd-php';
                        if (cmEditor && cmEditor.codemirror) {
                            cmEditor.codemirror.setOption('mode', newMode);
                        }
                    });

                    // Make sure CodeMirror content syncs back to textarea before form submit
                    $('.ofast-snippet-form').on('submit', function() {
                        if (cmEditor && cmEditor.codemirror) {
                            cmEditor.codemirror.save();
                            // Extra safety: also set the value directly
                            $codeTextarea.val(cmEditor.codemirror.getValue());
                        }
                    });

                    // Also sync on any CodeMirror change (backup for form submit)
                    if (cmEditor && cmEditor.codemirror) {
                        cmEditor.codemirror.on('change', function() {
                            cmEditor.codemirror.save();
                        });
                    }
                }

                // Sync desktop action checkboxes with mobile (actual form fields)
                $('#snippet_run_once_desktop').on('change', function() {
                    $('input[name="snippet_run_once"]').prop('checked', $(this).prop('checked'));
                });
                $('input[name="snippet_run_once"]').on('change', function() {
                    $('#snippet_run_once_desktop').prop('checked', $(this).prop('checked'));
                });

                $('#snippet_active_desktop').on('change', function() {
                    $('input[name="snippet_active"]').prop('checked', $(this).prop('checked'));
                });
                $('input[name="snippet_active"]').on('change', function() {
                    $('#snippet_active_desktop').prop('checked', $(this).prop('checked'));
                });

                // Toggle snippet
                $(document).on('click', '.ofast-snippet-toggle', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var id = $btn.data('id');
                    var active = $btn.data('active');

                    $btn.prop('disabled', true);

                    $.post(ajaxurl, {
                        action: 'ofast_toggle_snippet',
                        nonce: '<?php echo wp_create_nonce('ofast_snippet_toggle'); ?>',
                        id: id,
                        active: active
                    }, function(response) {
                        if (response.success) {
                            var newActive = response.data.active;
                            $btn.data('active', newActive);
                            $btn.html(newActive ? 'Deactivate' : 'Activate');
                            $btn.toggleClass('button-primary', newActive);
                        }
                    }).always(function() {
                        $btn.prop('disabled', false);
                    });
                });

                // Delete snippet
                $(document).on('click', '.ofast-snippet-delete', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var id = $btn.data('id');
                    var active = $btn.data('active');
                    var name = $btn.data('name');

                    // Stronger warning for active snippets
                    var message = active == 1 ?
                        'WARNING: "' + name + '" is ACTIVE and currently running!\n\nDeleting it will stop it from running.\n\nAre you sure you want to delete this active snippet?' :
                        'Are you sure you want to delete "' + name + '"?';

                    if (!confirm(message)) {
                        return;
                    }

                    $.post(ajaxurl, {
                        action: 'ofast_delete_snippet',
                        nonce: '<?php echo wp_create_nonce('ofast_snippet_delete'); ?>',
                        id: id
                    }, function(response) {
                        if (response.success) {
                            $btn.closest('tr').fadeOut(function() {
                                $(this).remove();
                            });
                            // Refresh page to show updated trash
                            setTimeout(function() { location.reload(); }, 500);
                        }
                    });
                });

                // Toggle trash panel
                $('#toggle-trash-panel').on('click', function() {
                    var $content = $('.trash-content');
                    var $icon = $(this).find('.toggle-icon');
                    $content.slideToggle(200);
                    $icon.text($content.is(':visible') ? '▲' : '▼');
                });

                // Restore snippet from trash
                $(document).on('click', '.restore-snippet', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var id = $btn.data('id');
                    $btn.prop('disabled', true).text('Restoring...');

                    $.post(ajaxurl, {
                        action: 'ofast_restore_snippet',
                        nonce: '<?php echo wp_create_nonce('ofast_snippet_restore'); ?>',
                        id: id
                    }, function(response) {
                        if (response.success) {
                            $btn.closest('tr').fadeOut(function() {
                                $(this).remove();
                                // Update trash count
                                var $header = $('#toggle-trash-panel h3');
                                var count = parseInt($header.text().match(/\d+/)[0]) - 1;
                                $header.html('<span class="dashicons dashicons-trash" style="margin-right: 8px;"></span>Trash (' + count + ')');
                                if (count === 0) {
                                    $('.trash-content').html('<p style="text-align: center; color: #999; padding: 30px; margin: 0;"><span class="dashicons dashicons-yes-alt" style="font-size: 32px; display: block; margin-bottom: 10px; color: #46b450;"></span>Trash is empty</p>');
                                }
                            });
                        } else {
                            alert(response.data || 'Error restoring snippet');
                            $btn.prop('disabled', false).html('<span class="dashicons dashicons-undo" style="vertical-align: middle; margin-right: 3px;"></span>Restore');
                        }
                    });
                });

                // Delete forever from trash
                $(document).on('click', '.delete-forever', function(e) {
                    e.preventDefault();
                    if (!confirm('Are you sure you want to PERMANENTLY delete this snippet? This cannot be undone.')) {
                        return;
                    }
                    var $btn = $(this);
                    var id = $btn.data('id');
                    $btn.prop('disabled', true).text('Deleting...');

                    $.post(ajaxurl, {
                        action: 'ofast_delete_snippet',
                        nonce: '<?php echo wp_create_nonce('ofast_snippet_delete'); ?>',
                        id: id,
                        permanent: 'true'
                    }, function(response) {
                        if (response.success) {
                            $btn.closest('tr').fadeOut(function() {
                                $(this).remove();
                                // Update trash count
                                var $header = $('#toggle-trash-panel h3');
                                var count = parseInt($header.text().match(/\d+/)[0]) - 1;
                                $header.html('<span class="dashicons dashicons-trash" style="margin-right: 8px;"></span>Trash (' + count + ')');
                                $('#empty-all-trash').html('<span class="dashicons dashicons-trash" style="vertical-align: middle; margin-right: 5px;"></span>Empty Trash (' + count + ' items)');
                                if (count === 0) {
                                    $('.trash-content').html('<p style="text-align: center; color: #999; padding: 30px; margin: 0;"><span class="dashicons dashicons-yes-alt" style="font-size: 32px; display: block; margin-bottom: 10px; color: #46b450;"></span>Trash is empty</p>');
                                }
                            });
                        }
                    });
                });

                // Empty all trash
                $('#empty-all-trash').on('click', function(e) {
                    e.preventDefault();
                    var count = $('.trash-row').length;
                    if (!confirm('Are you sure you want to PERMANENTLY delete all ' + count + ' items in trash? This cannot be undone.')) {
                        return;
                    }
                    var $btn = $(this);
                    $btn.prop('disabled', true).text('Deleting all...');
                    
                    // Delete each item sequentially
                    var ids = [];
                    $('.trash-row').each(function() {
                        ids.push($(this).data('id'));
                    });
                    
                    var deleteNext = function(index) {
                        if (index >= ids.length) {
                            location.reload();
                            return;
                        }
                        $.post(ajaxurl, {
                            action: 'ofast_delete_snippet',
                            nonce: '<?php echo wp_create_nonce('ofast_snippet_delete'); ?>',
                            id: ids[index],
                            permanent: 'true'
                        }, function() {
                            deleteNext(index + 1);
                        });
                    };
                    deleteNext(0);
                });

                // Inline title editing
                $(document).on('click', '.snippet-name-display', function() {
                    var $display = $(this);
                    var $input = $display.siblings('.snippet-name-edit');
                    $display.hide();
                    $input.show().focus().select();
                });

                $(document).on('blur', '.snippet-name-edit', function() {
                    var $input = $(this);
                    var $display = $input.siblings('.snippet-name-display');
                    var id = $input.data('id');
                    var newName = $input.val().trim();

                    if (newName === '') {
                        $input.hide();
                        $display.show();
                        return;
                    }

                    // Save via AJAX
                    $.post(ajaxurl, {
                        action: 'ofast_rename_snippet',
                        nonce: '<?php echo wp_create_nonce('ofast_snippet_rename'); ?>',
                        id: id,
                        name: newName
                    }, function(response) {
                        if (response.success) {
                            $display.find('strong').text(newName);
                            $input.val(newName);
                        }
                        $input.hide();
                        $display.show();
                    });
                });

                $(document).on('keypress', '.snippet-name-edit', function(e) {
                    if (e.which === 13) { // Enter key
                        $(this).blur();
                    }
                });

                // Language selector - toggle help text AND injection location visibility
                $('#snippet_language').on('change', function() {
                    var lang = $(this).val();

                    // Toggle help text
                    $('.php-help, .js-help, .css-help, .html-help').hide();
                    $('.' + lang.replace('javascript', 'js') + '-help').show();

                    // Show/hide injection location row (only relevant for JS/CSS/HTML, not PHP)
                    if (lang === 'php') {
                        $('.snippet-location-row').hide();
                    } else {
                        $('.snippet-location-row').show();

                        // Auto-select best default injection location based on language
                        // Only change if not editing an existing snippet with a set location
                        var $location = $('#snippet_location');
                        if (!$location.data('user-set')) {
                            if (lang === 'css') {
                                $location.val('header'); // CSS best in header to prevent FOUC
                            } else {
                                $location.val('footer'); // JS/HTML best in footer
                            }
                        }
                    }
                }).trigger('change');

                // Mark location as user-set when manually changed
                $('#snippet_location').on('change', function() {
                    $(this).data('user-set', true);
                });

                // Page Targeting - show/hide target value field
                $('#snippet_target_type').on('change', function() {
                    var type = $(this).val();
                    var $valueRow = $('.snippet-target-value-row');
                    var $input = $('#snippet_target_value');

                    // Hide all help texts
                    $('.post-type-help, .page-ids-help, .url-contains-help').hide();

                    if (type === 'all' || type === 'homepage') {
                        $valueRow.hide();
                        $input.val('');
                    } else {
                        $valueRow.show();

                        // Show appropriate help and placeholder
                        if (type === 'post_type') {
                            $('.post-type-help').show();
                            $input.attr('placeholder', 'e.g., product, post, page');
                        } else if (type === 'page_ids') {
                            $('.page-ids-help').show();
                            $input.attr('placeholder', 'e.g., 1, 5, 23, 100');
                        } else if (type === 'url_contains') {
                            $('.url-contains-help').show();
                            $input.attr('placeholder', 'e.g., /shop/, checkout, product');
                        }
                    }
                }).trigger('change');

                // Export snippets
                $('#ofast-export-snippets').on('click', function() {
                    var $btn = $(this);
                    var exportType = $('#ofast-export-type').val();
                    $btn.prop('disabled', true).text('Exporting...');

                    // Collect selected snippet IDs (from checkboxes if any are checked)
                    var selectedIds = [];
                    $('.snippet-checkbox:checked').each(function() {
                        selectedIds.push($(this).val());
                    });

                    $.post(ajaxurl, {
                        action: 'ofast_export_snippets',
                        nonce: '<?php echo wp_create_nonce('ofast_export_snippets'); ?>',
                        ids: selectedIds
                    }, function(response) {
                        if (response.success) {
                            var content, filename, mimeType;
                            var date = new Date().toISOString().split('T')[0];

                            if (exportType === 'code') {
                                // Export as readable code file
                                var codeOutput = [];
                                codeOutput.push('/*');
                                codeOutput.push(' * Ofast X Code Snippets Export');
                                codeOutput.push(' * Exported: ' + date);
                                codeOutput.push(' * Site: ' + response.data.site_url);
                                codeOutput.push(' * Total Snippets: ' + response.data.snippets.length);
                                codeOutput.push(' */\n');

                                response.data.snippets.forEach(function(snippet, index) {
                                    codeOutput.push('/* ========================================');
                                    codeOutput.push(' * Snippet #' + (index + 1) + ': ' + snippet.name);
                                    codeOutput.push(' * Language: ' + (snippet.language || 'php').toUpperCase());
                                    codeOutput.push(' * Scope: ' + (snippet.scope || 'global'));
                                    codeOutput.push(' * Status: ' + (snippet.active == 1 ? 'Active' : 'Inactive'));
                                    if (snippet.description) {
                                        codeOutput.push(' * Description: ' + snippet.description);
                                    }
                                    codeOutput.push(' * ======================================== */\n');
                                    codeOutput.push(snippet.code);
                                    codeOutput.push('\n\n');
                                });

                                content = codeOutput.join('\n');
                                filename = 'ofast-snippets-code-' + date + '.txt';
                                mimeType = 'text/plain';
                            } else {
                                // Export as JSON
                                content = JSON.stringify(response.data, null, 2);
                                filename = 'ofast-snippets-' + date + '.json';
                                mimeType = 'application/json';
                            }

                            var blob = new Blob([content], {
                                type: mimeType
                            });
                            var url = URL.createObjectURL(blob);
                            var a = document.createElement('a');
                            a.href = url;
                            a.download = filename;
                            a.click();
                            URL.revokeObjectURL(url);
                        } else {
                            alert('Export failed: ' + response.data);
                        }
                        $btn.prop('disabled', false).html('Export');
                    });
                });

                // Import snippets - trigger file input
                $('#ofast-import-snippets-btn').on('click', function() {
                    $('#ofast-import-file').click();
                });

                // Handle file selection for import
                $('#ofast-import-file').on('change', function() {
                    var file = this.files[0];
                    if (!file) return;

                    if (!file.name.endsWith('.json')) {
                        alert('Please select a valid JSON file');
                        return;
                    }

                    var reader = new FileReader();
                    reader.onload = function(e) {
                        try {
                            var data = JSON.parse(e.target.result);

                            if (!confirm('Import ' + (data.snippets ? data.snippets.length : 0) + ' snippet(s)?\n\nNote: All imported snippets will be set to INACTIVE for safety.')) {
                                return;
                            }

                            $.post(ajaxurl, {
                                action: 'ofast_import_snippets',
                                nonce: '<?php echo wp_create_nonce('ofast_import_snippets'); ?>',
                                import_data: JSON.stringify(data)
                            }, function(response) {
                                if (response.success) {
                                    alert(response.data.message);
                                    location.reload();
                                } else {
                                    alert('Import failed: ' + response.data);
                                }
                            });
                        } catch (err) {
                            alert('Invalid JSON file: ' + err.message);
                        }
                    };
                    reader.readAsText(file);

                    // Reset file input
                    $(this).val('');
                });

                // Search filter
                $('#snippet-search').on('keyup', function() {
                    filterSnippets();
                });

                // Category filter
                $('#category-filter').on('change', function() {
                    filterSnippets();
                });

                // Combined filter function
                function filterSnippets() {
                    var query = $('#snippet-search').val();
                    query = query ? query.toLowerCase() : '';

                    var categoryFilter = $('#category-filter');
                    var category = categoryFilter.length ? categoryFilter.val() : '';

                    $('.snippet-row').each(function() {
                        var $row = $(this);
                        var name = String($row.attr('data-name') || '').toLowerCase();
                        var desc = String($row.attr('data-description') || '').toLowerCase();
                        var cat = String($row.attr('data-category') || '');
                        var code = String($row.attr('data-code') || '').toLowerCase();
                        var tags = String($row.attr('data-tags') || '').toLowerCase();

                        var matchesText = (query === '' || name.indexOf(query) > -1 || desc.indexOf(query) > -1 || code.indexOf(query) > -1 || tags.indexOf(query) > -1);
                        var matchesCategory = (category === '' || category === undefined || cat === category);

                        if (matchesText && matchesCategory) {
                            $row.show();
                        } else {
                            $row.hide();
                        }
                    });
                }

                // Select all checkbox
                $('#select-all-snippets').on('change', function() {
                    var checked = $(this).is(':checked');
                    $('.snippet-checkbox:visible').prop('checked', checked);
                });

                // Bulk actions
                $('#apply-bulk-action').on('click', function() {
                    var action = $('#bulk-action-select').val();
                    if (!action) {
                        alert('Please select a bulk action');
                        return;
                    }

                    var ids = [];
                    $('.snippet-checkbox:checked').each(function() {
                        ids.push($(this).val());
                    });

                    if (ids.length === 0) {
                        alert('Please select at least one snippet');
                        return;
                    }

                    var confirmMsg = 'Are you sure you want to ' + action + ' ' + ids.length + ' snippet(s)?';
                    if (action === 'delete') {
                        confirmMsg = '⚠️ WARNING: This will permanently delete ' + ids.length + ' snippet(s). Continue?';
                    }

                    if (!confirm(confirmMsg)) {
                        return;
                    }

                    $.post(ajaxurl, {
                        action: 'ofast_bulk_action_snippets',
                        nonce: '<?php echo wp_create_nonce('ofast_bulk_action'); ?>',
                        bulk_action: action,
                        ids: ids
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                        }
                    });
                });

                // Import from other plugin
                $(document).on('click', '.ofast-import-from-plugin', function() {
                    var $btn = $(this);
                    var plugin = $btn.data('plugin');

                    if (!confirm('Import all snippets from ' + plugin + '?\n\nAll snippets will be imported as INACTIVE. You can review and activate them manually.')) {
                        return;
                    }

                    $btn.prop('disabled', true).text('Importing...');

                    $.post(ajaxurl, {
                        action: 'ofast_import_from_plugin',
                        nonce: '<?php echo wp_create_nonce('ofast_import_plugin'); ?>',
                        plugin: plugin
                    }, function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert('Import failed: ' + response.data);
                            $btn.prop('disabled', false).text('Import All');
                        }
                    });
                });

                // Preview & Import from other plugin
                $(document).on('click', '.ofast-preview-plugin-snippets', function() {
                    var $btn = $(this);
                    var plugin = $btn.data('plugin');
                    var pluginName = $btn.data('plugin-name');

                    $btn.prop('disabled', true).text('Loading...');

                    $.post(ajaxurl, {
                        action: 'ofast_preview_plugin_snippets',
                        nonce: '<?php echo wp_create_nonce('ofast_preview_snippets'); ?>',
                        plugin: plugin
                    }, function(response) {
                        $btn.prop('disabled', false).text('Preview & Import');

                        if (!response.success) {
                            alert('Error: ' + response.data);
                            return;
                        }

                        var snippets = response.data.snippets;
                        var validCount = snippets.filter(s => s.status !== 'duplicate' && s.is_safe !== false).length;
                        var unsafeCount = snippets.filter(s => s.is_safe === false).length;

                        // Build modal HTML
                        var modalHtml = `
                            <div id="ofast-preview-import-modal" style="position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.8); z-index:100000; overflow-y:auto; padding:20px;">
                                <div style="max-width:900px; margin:30px auto; background:#fff; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
                                    <div style="padding:20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; background:#f8fafc; border-radius:12px 12px 0 0;">
                                        <div>
                                            <h2 style="margin:0; color:#1e293b;">Import from ${pluginName}</h2>
                                            <p style="margin:5px 0 0; color:#64748b; font-size:13px;">${snippets.length} snippets found, <strong style="color:#10b981;">${validCount} safe to import</strong>${unsafeCount > 0 ? ', <span style="color:#ef4444;">' + unsafeCount + ' unsafe</span>' : ''}</p>
                                        </div>
                                        <button type="button" class="close-preview-modal" style="background:none; border:none; font-size:28px; cursor:pointer; color:#64748b; line-height:1;">&times;</button>
                                    </div>
                                    
                                    <div style="padding:15px 20px; background:#f1f5f9; border-bottom:1px solid #e5e7eb; display:flex; gap:15px; flex-wrap:wrap; font-size:12px; align-items:center;">
                                        <span><span style="background:#d1fae5; color:#065f46; padding:2px 6px; border-radius:3px; font-size:10px; font-weight:600;">SAFE</span> Syntax OK</span>
                                        <span><span style="background:#fee2e2; color:#991b1b; padding:2px 6px; border-radius:3px; font-size:10px; font-weight:600;">UNSAFE</span> Has errors</span>
                                        <span><span style="color:#10b981; font-size:14px;">●</span> Active</span>
                                        <span><span style="color:#6b7280; font-size:14px;">●</span> Inactive</span>
                                        <span><span style="color:#ef4444; font-size:14px;">●</span> Duplicate</span>
                                        <label style="margin-left:auto;"><input type="checkbox" id="preview-select-all" ${validCount === 0 ? 'disabled' : ''}> Select All Safe</label>
                                    </div>
                                    
                                    <div style="max-height:400px; overflow-y:auto; padding:10px 20px;">
                                        ${snippets.map((s, i) => `
                                            <div class="preview-snippet-item" style="display:flex; gap:12px; padding:12px; border:1px solid ${s.status === 'duplicate' ? '#fecaca' : (s.is_safe === false ? '#fed7aa' : '#e5e7eb')}; border-radius:8px; margin-bottom:10px; background:${s.status === 'duplicate' ? '#fef2f2' : (s.is_safe === false ? '#fffbeb' : '#fff')}; ${s.status === 'duplicate' ? 'opacity:0.7;' : ''}">
                                                <input type="checkbox" class="preview-snippet-checkbox" name="import_snippets[]" value="${s.id}" ${s.status === 'duplicate' || s.is_safe === false ? 'disabled' : ''} style="margin-top:4px;">
                                                <div style="flex:1; min-width:0;">
                                                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px; flex-wrap:wrap;">
                                                        <span style="color:${s.status === 'active' ? '#10b981' : (s.status === 'duplicate' ? '#ef4444' : '#6b7280')}; font-size:16px;">●</span>
                                                        <strong style="color:#1e293b;">${s.name}</strong>
                                                        ${s.status === 'duplicate' ? '<span style="background:#fecaca; color:#991b1b; padding:2px 6px; border-radius:3px; font-size:10px;">DUPLICATE</span>' : ''}
                                                        ${s.language !== 'php' ? '<span style="background:#e0e7ff; color:#3730a3; padding:2px 6px; border-radius:3px; font-size:10px;">' + s.language.toUpperCase() + '</span>' : ''}
                                                        <span style="margin-left:auto;">${s.is_safe !== false ? '<span style="background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:3px; font-size:10px; font-weight:600;">SAFE</span>' : '<span style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:3px; font-size:10px; font-weight:600;">UNSAFE</span>'}</span>
                                                    </div>
                                                    ${s.description ? '<p style="margin:0 0 8px; color:#64748b; font-size:12px;">' + s.description + '</p>' : ''}
                                                    ${s.status === 'duplicate' ? '<p style="margin:0; color:#ef4444; font-size:11px;">Already exists in Ofast X (ID: ' + s.existing_id + ')</p>' : ''}
                                                    ${s.is_safe === false ? '<p style="margin:0 0 8px; color:#ea580c; font-size:11px; background:#fffbeb; padding:4px 8px; border-radius:4px;"><strong>Syntax Error:</strong> ' + (s.error_message || 'Invalid PHP code') + '</p>' : ''}
                                                    <details style="margin-top:8px;">
                                                        <summary style="cursor:pointer; color:#3b82f6; font-size:12px;">Preview Code</summary>
                                                        <pre style="background:#1e1e1e; color:#d4d4d4; padding:10px; border-radius:4px; font-size:11px; overflow-x:auto; margin-top:8px; max-height:150px; white-space:pre-wrap;">${s.code_preview}</pre>
                                                    </details>
                                                </div>
                                            </div>
                                        `).join('')}
                                    </div>
                                    
                                    <div style="padding:20px; border-top:1px solid #e5e7eb; display:flex; gap:10px; justify-content:flex-end; background:#f8fafc; border-radius:0 0 12px 12px;">
                                        <button type="button" class="button close-preview-modal">Cancel</button>
                                        <button type="button" class="button button-primary import-selected-snippets" data-plugin="${plugin}" ${validCount === 0 ? 'disabled' : ''}>
                                            Import Selected (<span class="selected-count">0</span>)
                                        </button>
                                    </div>
                                </div>
                            </div>
                        `;

                        $('body').append(modalHtml);

                        var $modal = $('#ofast-preview-import-modal');

                        // Update count when checkboxes change
                        function updateSelectedCount() {
                            var count = $modal.find('.preview-snippet-checkbox:checked').length;
                            $modal.find('.selected-count').text(count);
                            $modal.find('.import-selected-snippets').prop('disabled', count === 0);
                        }

                        $modal.on('change', '.preview-snippet-checkbox', updateSelectedCount);

                        // Select all
                        $modal.on('change', '#preview-select-all', function() {
                            var checked = $(this).is(':checked');
                            $modal.find('.preview-snippet-checkbox:not(:disabled)').prop('checked', checked);
                            updateSelectedCount();
                        });

                        // Close modal
                        $modal.on('click', '.close-preview-modal', function() {
                            $modal.remove();
                        });

                        $modal.on('click', function(e) {
                            if (e.target === this) {
                                $modal.remove();
                            }
                        });

                        // Import selected
                        $modal.on('click', '.import-selected-snippets', function() {
                            var $importBtn = $(this);
                            var selectedIds = [];
                            $modal.find('.preview-snippet-checkbox:checked').each(function() {
                                selectedIds.push($(this).val());
                            });

                            if (selectedIds.length === 0) {
                                alert('Please select at least one snippet to import');
                                return;
                            }

                            $importBtn.prop('disabled', true).text('Importing...');

                            $.post(ajaxurl, {
                                action: 'ofast_selective_import_snippets',
                                nonce: '<?php echo wp_create_nonce('ofast_selective_import'); ?>',
                                plugin: $importBtn.data('plugin'),
                                ids: selectedIds
                            }, function(resp) {
                                if (resp.success) {
                                    alert(resp.data.message);
                                    location.reload();
                                } else {
                                    alert('Import failed: ' + resp.data);
                                    $importBtn.prop('disabled', false).html('Import Selected (<span class="selected-count">' + selectedIds.length + '</span>)');
                                }
                            });
                        });
                    });
                });

                // Toggle Library visibility
                $('#toggle-library').on('click', function() {
                    var $lib = $('#snippet-library');
                    var $btn = $(this);
                    if ($lib.is(':visible')) {
                        $lib.slideUp();
                        $btn.text('Show Templates');
                    } else {
                        $lib.slideDown();
                        $btn.text('Hide Templates');
                    }
                });

                // Toggle Import from Other Plugins visibility
                $('#toggle-import-plugins').on('click', function() {
                    var $content = $('#import-plugins-content');
                    var $btn = $(this);
                    if ($content.is(':visible')) {
                        $content.slideUp();
                        $btn.text('Show Plugins');
                    } else {
                        $content.slideDown();
                        $btn.text('Hide Plugins');
                    }
                });

                // Library category filter
                $('.library-cat-filter').on('click', function() {
                    var cat = $(this).data('cat');
                    $('.library-cat-filter').removeClass('button-primary active');
                    $(this).addClass('button-primary active');

                    if (cat === 'all') {
                        $('.library-template').show();
                    } else {
                        $('.library-template').each(function() {
                            if ($(this).data('category') === cat) {
                                $(this).show();
                            } else {
                                $(this).hide();
                            }
                        });
                    }
                });

                // Use Library Template
                $(document).on('click', '.use-library-template', function() {
                    var $btn = $(this);
                    var index = $btn.data('index');

                    useTemplate($btn, index, false);
                });

                // Function to use template (handles duplicates)
                function useTemplate($btn, index, forceCopy) {
                    $btn.prop('disabled', true).text('Adding...');

                    $.post(ajaxurl, {
                        action: 'ofast_use_library_template',
                        nonce: '<?php echo wp_create_nonce('ofast_use_template'); ?>',
                        index: index,
                        force_copy: forceCopy ? 1 : 0
                    }, function(response) {
                        if (response.success) {
                            // Check if duplicate found
                            if (response.data.duplicate) {
                                // Show custom modal with options
                                showDuplicateModal(response.data, $btn, index);
                            } else {
                                // Normal success
                                alert(response.data.message);
                                location.reload();
                            }
                        } else {
                            alert('Failed: ' + response.data);
                            $btn.prop('disabled', false).text('Use Template');
                        }
                    });
                }

                // Custom modal for duplicate template choice
                function showDuplicateModal(data, $btn, index) {
                    // Remove existing modal if any
                    $('#ofast-duplicate-modal').remove();

                    var modalHtml = `
                        <div id="ofast-duplicate-modal" style="position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.6); z-index:100000; display:flex; align-items:center; justify-content:center;">
                            <div style="background:#fff; border-radius:12px; padding:0; max-width:450px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
                                <div style="padding:20px; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center;">
                                    <h3 style="margin:0; color:#1d2327;">Template Already Exists</h3>
                                    <button type="button" class="close-duplicate-modal" style="background:none; border:none; font-size:24px; cursor:pointer; color:#999;">&times;</button>
                                </div>
                                <div style="padding:25px;">
                                    <p style="margin:0 0 20px; color:#50575e;">"<strong>${data.existing_name}</strong>" already exists in your snippets.</p>
                                    <p style="margin:0 0 25px; color:#666;">What would you like to do?</p>
                                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                        <button type="button" class="button button-primary edit-existing-btn" style="flex:1; min-width:120px;">Edit Existing</button>
                                        <button type="button" class="button create-copy-btn" style="flex:1; min-width:120px;">Create Copy</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;

                    $('body').append(modalHtml);

                    var $modal = $('#ofast-duplicate-modal');

                    // Close modal on X click or outside click
                    $modal.on('click', '.close-duplicate-modal', function() {
                        $modal.remove();
                        $btn.prop('disabled', false).text('Use Template');
                    });

                    $modal.on('click', function(e) {
                        if (e.target === this) {
                            $modal.remove();
                            $btn.prop('disabled', false).text('Use Template');
                        }
                    });

                    // Edit existing
                    $modal.on('click', '.edit-existing-btn', function() {
                        window.location.href = '?page=ofast-snippets&edit=' + data.existing_id;
                    });

                    // Create copy
                    $modal.on('click', '.create-copy-btn', function() {
                        $modal.remove();
                        useTemplate($btn, index, true);
                    });
                }

                // View History Button
                $('#view-history-btn').on('click', function() {
                    var snippetId = $(this).data('snippet-id');

                    // Show loading modal
                    if (!$('#revision-modal').length) {
                        $('body').append(`
                            <div id="revision-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.7); z-index:100000; overflow-y:auto; padding:20px;">
                                <div style="max-width:800px; margin:50px auto; background:#fff; border-radius:8px; box-shadow:0 10px 50px rgba(0,0,0,0.3);">
                                    <div style="padding:20px; border-bottom:1px solid #ddd; display:flex; justify-content:space-between; align-items:center;">
                                        <h2 style="margin:0;">Revision History</h2>
                                        <button type="button" id="close-revision-modal" class="button">&times; Close</button>
                                    </div>
                                    <div id="revision-content" style="padding:20px;">Loading...</div>
                                </div>
                            </div>
                        `);

                        $(document).on('click', '#close-revision-modal', function() {
                            $('#revision-modal').fadeOut();
                        });
                    }

                    $('#revision-modal').fadeIn();

                    $.post(ajaxurl, {
                        action: 'ofast_get_revisions',
                        nonce: '<?php echo wp_create_nonce('ofast_get_revisions'); ?>',
                        snippet_id: snippetId
                    }, function(response) {
                        if (response.success) {
                            var revisions = response.data.revisions;
                            var html = '';

                            if (revisions.length === 0) {
                                html = '<p style="text-align:center; color:#666; padding:40px;">No revisions yet. Revisions are created when you edit and save code.</p>';
                            } else {
                                html = '<p style="color:#666; margin-bottom:15px;">Click "Preview" to view code, "Restore" to revert to that version.</p>';
                                html += '<table class="widefat striped">';
                                html += '<thead><tr><th>Date</th><th>Changed By</th><th style="width:200px;">Actions</th></tr></thead>';
                                html += '<tbody>';

                                revisions.forEach(function(rev) {
                                    html += '<tr>';
                                    html += '<td>' + rev.changed_at + '</td>';
                                    html += '<td>' + (rev.user_name || 'Unknown') + '</td>';
                                    html += '<td>';
                                    html += '<button type="button" class="button button-small preview-revision" data-code="' + encodeURIComponent(rev.code) + '">Preview</button> ';
                                    html += '<button type="button" class="button button-small restore-revision" data-id="' + rev.id + '">Restore</button>';
                                    html += '</td>';
                                    html += '</tr>';
                                });

                                html += '</tbody></table>';
                            }

                            $('#revision-content').html(html);
                        } else {
                            $('#revision-content').html('<p style="color:red;">Error loading revisions</p>');
                        }
                    });
                });

                // Preview revision
                $(document).on('click', '.preview-revision', function() {
                    var code = decodeURIComponent($(this).data('code'));
                    alert('=== REVISION CODE ===\n\n' + code.substring(0, 2000) + (code.length > 2000 ? '\n\n... (truncated)' : ''));
                });

                // Restore revision
                $(document).on('click', '.restore-revision', function() {
                    if (!confirm('Restore this revision? Current code will be saved as a new revision and snippet will be set to INACTIVE for safety.')) {
                        return;
                    }

                    var $btn = $(this);
                    var revisionId = $btn.data('id');

                    $btn.prop('disabled', true).text('Restoring...');

                    $.post(ajaxurl, {
                        action: 'ofast_restore_revision',
                        nonce: '<?php echo wp_create_nonce('ofast_restore_revision'); ?>',
                        revision_id: revisionId
                    }, function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert('Failed: ' + response.data);
                            $btn.prop('disabled', false).text('Restore');
                        }
                    });
                });
            });
        </script>
<?php
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
        if (!$this->check_rate_limit('toggle')) {
            wp_send_json_error('Too many requests. Please wait a moment.');
        }

        $id = intval($_POST['id']);
        $current_active = intval($_POST['active']);
        $new_active = $current_active ? 0 : 1;

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';

        // Get snippet info for logging
        $snippet = $wpdb->get_row($wpdb->prepare("SELECT name, code, language FROM $table WHERE id = %d", $id));

        // If turning ON, validate first (only for PHP snippets)
        if ($new_active == 1 && $snippet) {
            if ($snippet->language === 'php' || empty($snippet->language)) {
                // Check syntax first
                $validation = $this->validate_php_code($snippet->code);
                if ($validation !== true) {
                    wp_send_json_error('Cannot activate: ' . $validation);
                    return;
                }

                // Check for function name conflicts
                $conflict_check = $this->check_function_conflicts($snippet->code);
                if ($conflict_check !== true) {
                    wp_send_json_error('Cannot activate: ' . $conflict_check);
                    return;
                }
            }
        }

        $wpdb->update(
            $table,
            array('active' => $new_active),
            array('id' => $id)
        );

        // Audit log
        $this->log_snippet_action(
            $new_active ? 'ACTIVATED' : 'DEACTIVATED',
            $id,
            $snippet ? $snippet->name : '',
            ''
        );

        // Clear cache when snippet is toggled
        $this->clear_snippets_cache();

        wp_send_json_success(array('active' => $new_active));
    }

    /**
     * AJAX: Delete snippet (soft delete - moves to trash)
     */
    public function ajax_delete_snippet()
    {
        check_ajax_referer('ofast_snippet_delete', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Rate limiting
        if (!$this->check_rate_limit('delete')) {
            wp_send_json_error('Too many requests. Please wait a moment.');
        }

        $id = intval($_POST['id']);
        $permanent = isset($_POST['permanent']) && $_POST['permanent'] === 'true';

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';

        // Get snippet for logging
        $snippet = $wpdb->get_row($wpdb->prepare(
            "SELECT name, status FROM $table WHERE id = %d", $id
        ));

        if (!$snippet) {
            wp_send_json_error('Snippet not found');
        }

        if ($permanent && isset($snippet->status) && $snippet->status === 'trash') {
            // Permanent delete from trash
            $wpdb->delete($table, array('id' => $id));
            $this->log_snippet_action('PERMANENTLY_DELETED', $id, $snippet->name, '');
        } else {
            // Soft delete - move to trash
            $wpdb->update($table, array(
                'status' => 'trash',
                'active' => 0,
                'trashed_at' => current_time('mysql')
            ), array('id' => $id));
            $this->log_snippet_action('TRASHED', $id, $snippet->name, '');
        }

        // Clear cache
        $this->clear_snippets_cache();

        wp_send_json_success();
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

        // Rate limiting
        if (!$this->check_rate_limit('restore')) {
            wp_send_json_error('Too many requests. Please wait a moment.');
        }

        $id = intval($_POST['id']);

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';

        $snippet = $wpdb->get_row($wpdb->prepare(
            "SELECT name FROM $table WHERE id = %d AND status = 'trash'", $id
        ));

        if (!$snippet) {
            wp_send_json_error('Snippet not found in trash');
        }

        $wpdb->update($table, array(
            'status' => 'active',
            'trashed_at' => null
        ), array('id' => $id));

        $this->log_snippet_action('RESTORED', $id, $snippet->name, '');
        $this->clear_snippets_cache();

        wp_send_json_success(array('message' => 'Snippet restored successfully'));
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
        if (!$this->check_rate_limit('rename')) {
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
        $this->log_snippet_action('RENAMED', $id, $name, $old_snippet ? "From: {$old_snippet->name}" : '');

        // Clear cache when snippet is renamed
        $this->clear_snippets_cache();

        wp_send_json_success(array('name' => $name));
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

        // Check if specific IDs were passed (selected snippets)
        $selected_ids = isset($_POST['ids']) ? array_map('intval', (array)$_POST['ids']) : array();
        $selected_ids = array_filter($selected_ids); // Remove zeros

        if (!empty($selected_ids)) {
            // Export only selected snippets
            $placeholders = implode(',', array_fill(0, count($selected_ids), '%d'));
            $snippets = $wpdb->get_results($wpdb->prepare(
                "SELECT name, description, code, language, scope, location, target_type, target_value, run_once, active FROM $table WHERE id IN ($placeholders) ORDER BY id",
                $selected_ids
            ));
            $export_label = count($selected_ids) . ' Selected Snippets';
        } else {
            // Export all snippets
            $snippets = $wpdb->get_results("SELECT name, description, code, language, scope, location, target_type, target_value, run_once, active FROM $table ORDER BY id");
            $export_label = 'All Snippets';
        }

        $export_data = array(
            'plugin' => 'ofast-x',
            'version' => '1.0',
            'exported_at' => current_time('mysql'),
            'site_url' => get_site_url(),
            'snippets' => $snippets
        );

        // Audit log
        $this->log_snippet_action('EXPORTED', 0, $export_label, 'Count: ' . count($snippets));

        wp_send_json_success($export_data);
    }

    /**
     * AJAX: Import snippets
     */
    public function ajax_import_snippets()
    {
        check_ajax_referer('ofast_import_snippets', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Rate limiting
        if (!$this->check_rate_limit('import')) {
            wp_send_json_error('Too many requests. Please wait a moment.');
        }

        $import_data = isset($_POST['import_data']) ? wp_unslash($_POST['import_data']) : '';
        
        // SECURITY: Limit import size to prevent DoS
        $max_import_size = 5 * 1024 * 1024; // 5MB
        if (strlen($import_data) > $max_import_size) {
            wp_send_json_error('Import file too large (maximum 5MB)');
        }
        
        $data = json_decode($import_data, true);

        if (!$data || !isset($data['snippets']) || !is_array($data['snippets'])) {
            wp_send_json_error('Invalid import file format');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';
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
            $language = isset($snippet['language']) ? $snippet['language'] : 'php';
            if ($language === 'php' && !empty($snippet['code'])) {
                $validation = $this->validate_php_code($snippet['code']);
                if ($validation !== true) {
                    $errors[] = $snippet['name'] . ': ' . $validation;
                    $skipped++;
                    continue;
                }
            }

            // DUPLICATE CODE CHECK: Skip if exact code already exists
            $code_hash = md5(trim($snippet['code']));
            $existing_code = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE MD5(TRIM(code)) = %s",
                $code_hash
            ));
            if ($existing_code) {
                $skipped++;
                continue;
            }

            // Insert snippet (always as INACTIVE for safety)
            $wpdb->insert($table, array(
                'name' => sanitize_text_field($snippet['name']) . ' (imported)',
                'description' => isset($snippet['description']) ? sanitize_textarea_field($snippet['description']) : '',
                'code' => $snippet['code'], // Keep code as-is (validated above)
                'language' => in_array($language, array('php', 'javascript', 'css', 'html')) ? $language : 'php',
                'scope' => isset($snippet['scope']) ? sanitize_text_field($snippet['scope']) : 'global',
                'location' => isset($snippet['location']) ? sanitize_text_field($snippet['location']) : 'footer',
                'target_type' => isset($snippet['target_type']) ? sanitize_text_field($snippet['target_type']) : 'all',
                'target_value' => isset($snippet['target_value']) ? sanitize_text_field($snippet['target_value']) : '',
                'run_once' => isset($snippet['run_once']) ? intval($snippet['run_once']) : 0,
                'active' => 0, // ALWAYS inactive on import
                'created_at' => current_time('mysql')
            ));

            $imported++;
        }

        // Audit log
        $this->log_snippet_action('IMPORTED', 0, 'Bulk Import', "Imported: {$imported}, Skipped: {$skipped}");

        $message = "Imported {$imported} snippet(s)";
        if ($skipped > 0) {
            $message .= ", skipped {$skipped}";
        }
        if (!empty($errors)) {
            $message .= "\n\nErrors:\n" . implode("\n", array_slice($errors, 0, 5));
        }

        // Clear cache when snippets are imported
        $this->clear_snippets_cache();

        wp_send_json_success(array('message' => $message, 'imported' => $imported, 'skipped' => $skipped));
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

        // Rate limiting
        if (!$this->check_rate_limit('use_template')) {
            wp_send_json_error('Too many requests. Please wait a moment.');
        }

        $index = isset($_POST['index']) ? intval($_POST['index']) : -1;
        $force_copy = isset($_POST['force_copy']) ? (bool)$_POST['force_copy'] : false;

        // Load library
        $library_file = plugin_dir_path(__FILE__) . 'library/snippets.json';
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
        $result = $wpdb->insert($table, array(
            'name' => $snippet_name,
            'description' => $template['description'],
            'code' => $template['code'],
            'language' => $template['language'],
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
        $this->log_snippet_action('TEMPLATE_USED', $wpdb->insert_id, $snippet_name, "Category: {$template['category']}");

        // Clear cache when template is used
        $this->clear_snippets_cache();

        wp_send_json_success(array(
            'message' => "'{$snippet_name}' added! It's set to INACTIVE - review and activate when ready.",
            'id' => $wpdb->insert_id
        ));
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
        if (!$this->check_rate_limit('bulk_action')) {
            wp_send_json_error('Too many requests. Please wait a moment.');
        }

        $action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
        $ids = isset($_POST['ids']) ? array_map('intval', $_POST['ids']) : array();

        if (empty($action) || empty($ids)) {
            wp_send_json_error('Invalid request');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';
        $count = 0;

        foreach ($ids as $id) {
            switch ($action) {
                case 'activate':
                    $wpdb->update($table, array('active' => 1), array('id' => $id));
                    $count++;
                    break;
                case 'deactivate':
                    $wpdb->update($table, array('active' => 0), array('id' => $id));
                    $count++;
                    break;
                case 'delete':
                    $wpdb->delete($table, array('id' => $id));
                    $count++;
                    break;
            }
        }

        // Audit log
        $this->log_snippet_action('BULK_' . strtoupper($action), 0, 'Bulk Action', "Count: {$count}");

        // Clear cache when bulk action is performed
        $this->clear_snippets_cache();

        wp_send_json_success(array('count' => $count));
    }

    /**
     * Detect other snippet plugins installed on the site
     */
    private function detect_other_snippet_plugins()
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
     * AJAX: Import snippets from another plugin
     */
    public function ajax_import_from_plugin()
    {
        check_ajax_referer('ofast_import_plugin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Rate limiting
        if (!$this->check_rate_limit('import_plugin')) {
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
                // Validate PHP code
                if (!empty($snippet->code)) {
                    $validation = $this->validate_php_code($snippet->code);
                    if ($validation !== true) {
                        $errors[] = $snippet->name . ': ' . $validation;
                        $skipped++;
                        continue;
                    }
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
                $code_hash = md5(trim($snippet->code));
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
                    'code' => $snippet->code,
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
                if ($code_type === 'js' || $code_type === 'javascript') {
                    $language = 'javascript';
                } elseif ($code_type === 'css') {
                    $language = 'css';
                } elseif ($code_type === 'html' || $code_type === 'text') {
                    $language = 'html';
                }

                // Validate PHP code only
                if ($language === 'php' && !empty($code)) {
                    $validation = $this->validate_php_code($code);
                    if ($validation !== true) {
                        $errors[] = $post->post_title . ': ' . $validation;
                        $skipped++;
                        continue;
                    }
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
        $this->log_snippet_action('IMPORTED_FROM_PLUGIN', 0, $plugin, "Imported: {$imported}, Skipped: {$skipped}");

        // Clear cache when snippets are imported from plugin
        $this->clear_snippets_cache();

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

        // Rate limiting
        if (!$this->check_rate_limit('preview_snippets')) {
            wp_send_json_error('Too many requests. Please wait a moment.');
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
                $code_hash = md5(trim($s->code));
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

                // Validate PHP syntax for safety check
                $is_safe = true;
                $error_message = null;
                if (!empty($s->code)) {
                    $validation = $this->validate_php_code($s->code);
                    if ($validation !== true) {
                        $is_safe = false;
                        $error_message = $validation;
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
                    'error_message' => $error_message,
                    'code_preview' => htmlspecialchars(mb_substr($s->code, 0, 500) . (strlen($s->code) > 500 ? '...' : ''))
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
                if ($code_type === 'js' || $code_type === 'javascript') {
                    $language = 'javascript';
                } elseif ($code_type === 'css') {
                    $language = 'css';
                } elseif ($code_type === 'html' || $code_type === 'text') {
                    $language = 'html';
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

                // Validate PHP syntax for safety check (only for PHP snippets)
                $is_safe = true;
                $error_message = null;
                if ($language === 'php' && !empty($code)) {
                    $validation = $this->validate_php_code($code);
                    if ($validation !== true) {
                        $is_safe = false;
                        $error_message = $validation;
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
        if (!$this->check_rate_limit('import_plugin')) {
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
                // Validate PHP code
                if (!empty($snippet->code)) {
                    $validation = $this->validate_php_code($snippet->code);
                    if ($validation !== true) {
                        $errors[] = $snippet->name . ': ' . $validation;
                        $skipped++;
                        continue;
                    }
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
                $code_hash = md5(trim($snippet->code));
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
                    'code' => $snippet->code,
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
                if ($code_type === 'js' || $code_type === 'javascript') {
                    $language = 'javascript';
                } elseif ($code_type === 'css') {
                    $language = 'css';
                } elseif ($code_type === 'html' || $code_type === 'text') {
                    $language = 'html';
                }

                // Validate PHP code only
                if ($language === 'php' && !empty($code)) {
                    $validation = $this->validate_php_code($code);
                    if ($validation !== true) {
                        $errors[] = $post->post_title . ': ' . $validation;
                        $skipped++;
                        continue;
                    }
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
        $this->log_snippet_action('SELECTIVE_IMPORT', 0, $plugin, "Selected: " . count($ids) . ", Imported: {$imported}, Skipped: {$skipped}");

        // Clear cache
        $this->clear_snippets_cache();

        $message = "Imported {$imported} snippet(s) from {$plugin}";
        if ($skipped > 0) {
            $message .= ", skipped {$skipped} (duplicates/errors)";
        }

        wp_send_json_success(array('message' => $message, 'imported' => $imported, 'skipped' => $skipped));
    }

    /**
     * Execute active snippets
     * PERFORMANCE: Uses transient caching to avoid DB queries on every request
     */
    public function execute_snippets()
    {
        // PERFORMANCE: Get active snippets from cache (1 hour TTL)
        $snippets = get_transient('ofast_active_snippets_cache');

        if ($snippets === false) {
            global $wpdb;
            $table = $wpdb->prefix . 'ofast_snippets';
            // Get all active snippets with all relevant fields, ordered by priority (exclude trashed)
            $snippets = $wpdb->get_results("SELECT id, code, language, scope, location, target_type, target_value, run_once, executed_at, priority FROM $table WHERE active = 1 AND (status IS NULL OR status != 'trash') ORDER BY priority ASC, id ASC");

            // Cache for 1 hour (3600 seconds)
            set_transient('ofast_active_snippets_cache', $snippets, 3600);
        }

        if (empty($snippets)) {
            return;
        }

        foreach ($snippets as $snippet) {
            // Check scope (admin/frontend/global)
            $should_run = $this->should_snippet_run($snippet->scope);
            if (!$should_run) {
                continue;
            }

            // Check page targeting (only on frontend, skip for admin)
            if (!is_admin()) {
                $target_type = !empty($snippet->target_type) ? $snippet->target_type : 'all';
                $target_value = !empty($snippet->target_value) ? $snippet->target_value : '';

                if (!$this->should_run_on_page($target_type, $target_value)) {
                    continue;
                }
            }

            // Check run_once - if already executed, skip and deactivate
            if ($snippet->run_once && !empty($snippet->executed_at)) {
                global $wpdb;
                $table = $wpdb->prefix . 'ofast_snippets';
                $wpdb->update($table, array('active' => 0), array('id' => $snippet->id));
                $this->clear_snippets_cache();
                continue;
            }

            // Execute based on language
            $language = !empty($snippet->language) ? $snippet->language : 'php';
            $location = !empty($snippet->location) ? $snippet->location : 'footer';

            switch ($language) {
                case 'php':
                    $this->execute_php_snippet($snippet->code, $snippet->id, $snippet->run_once);
                    break;
                case 'javascript':
                    $this->execute_js_snippet($snippet->code, $location, $snippet->id, $snippet->run_once);
                    break;
                case 'css':
                    $this->execute_css_snippet($snippet->code, $location, $snippet->id, $snippet->run_once);
                    break;
                case 'html':
                    $this->execute_html_snippet($snippet->code, $location, $snippet->id, $snippet->run_once);
                    break;
            }
        }
    }

    /**
     * Clear snippets cache when snippets are modified
     */
    private function clear_snippets_cache()
    {
        delete_transient('ofast_active_snippets_cache');
    }

    /**
     * Check if snippet should run based on scope
     */
    private function should_snippet_run($scope)
    {
        $scope = !empty($scope) ? $scope : 'global';

        switch ($scope) {
            case 'admin':
                return is_admin();
            case 'frontend':
                return !is_admin();
            case 'global':
            default:
                return true;
        }
    }

    /**
     * Check if snippet should run on current page based on targeting
     */
    private function should_run_on_page($target_type, $target_value)
    {
        // All pages - always run
        if ($target_type === 'all' || empty($target_type)) {
            return true;
        }

        // Homepage only
        if ($target_type === 'homepage') {
            return is_front_page() || is_home();
        }

        // Specific post type
        if ($target_type === 'post_type') {
            $post_types = array_map('trim', explode(',', $target_value));
            return is_singular($post_types);
        }

        // Specific page/post IDs
        if ($target_type === 'page_ids') {
            $ids = array_map('intval', array_map('trim', explode(',', $target_value)));
            $current_id = get_queried_object_id();
            return in_array($current_id, $ids);
        }

        // URL contains
        if ($target_type === 'url_contains') {
            $current_url = $_SERVER['REQUEST_URI'];
            $patterns = array_map('trim', explode(',', $target_value));
            foreach ($patterns as $pattern) {
                if (!empty($pattern) && strpos($current_url, $pattern) !== false) {
                    return true;
                }
            }
            return false;
        }

        return true; // Default: run
    }

    /**
     * Mark snippet as executed (for run_once)
     */
    private function mark_snippet_executed($snippet_id, $run_once)
    {
        if (!$run_once) return;

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';
        $wpdb->update($table, array(
            'executed_at' => current_time('mysql'),
            'active' => 0
        ), array('id' => $snippet_id));
    }

    /**
     * Check for function name conflicts
     * Returns true if no conflicts, error message string if conflicts found
     */
    private function check_function_conflicts($code)
    {
        // Extract function names from the code
        $function_names = array();

        // Match "function function_name(" patterns
        if (preg_match_all('/function\s+([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\s*\(/i', $code, $matches)) {
            $function_names = $matches[1];
        }

        if (empty($function_names)) {
            return true; // No named functions = no conflicts possible
        }

        $conflicts = array();
        foreach ($function_names as $func_name) {
            // Check if function already exists
            if (function_exists($func_name)) {
                $conflicts[] = $func_name;
            }
        }

        if (!empty($conflicts)) {
            $conflict_list = implode(', ', $conflicts);
            return "Function conflict detected! These functions already exist: {$conflict_list}. This may be caused by another plugin (Code Snippets, WPCode, etc.) or another active snippet using the same function names. Deactivate the conflicting snippet/plugin first, or rename the functions in this code.";
        }

        return true;
    }

    /**
     * Extract function names from PHP code
     */
    private function extract_function_names($code)
    {
        $function_names = array();
        if (preg_match_all('/function\s+([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\s*\(/i', $code, $matches)) {
            $function_names = $matches[1];
        }
        return $function_names;
    }

    /**
     * Check for potential duplicates within our snippets table
     * Returns array with 'has_duplicate' boolean and 'reasons' array
     */
    private function get_potential_duplicates($snippet_id, $snippet_name, $snippet_code)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';

        $result = array(
            'has_duplicate' => false,
            'reasons' => array()
        );

        // Get all other snippets
        $other_snippets = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, code, active FROM $table WHERE id != %d",
            $snippet_id
        ));

        if (empty($other_snippets)) {
            return $result;
        }

        // Check for same name
        foreach ($other_snippets as $other) {
            if (strtolower(trim($other->name)) === strtolower(trim($snippet_name))) {
                $result['has_duplicate'] = true;
                $status = $other->active ? 'ACTIVE' : 'inactive';
                $result['reasons'][] = "Same name as snippet #{$other->id} ({$status})";
            }
        }

        // Extract function names from this snippet
        $my_functions = $this->extract_function_names($snippet_code);

        if (!empty($my_functions)) {
            foreach ($other_snippets as $other) {
                $other_functions = $this->extract_function_names($other->code);
                $overlap = array_intersect($my_functions, $other_functions);

                if (!empty($overlap)) {
                    $result['has_duplicate'] = true;
                    $status = $other->active ? 'ACTIVE' : 'inactive';
                    $result['reasons'][] = "Shares functions (" . implode(', ', $overlap) . ") with snippet #{$other->id} ({$status})";
                }
            }
        }

        return $result;
    }

    /**
     * Save a revision of snippet code
     */
    private function save_revision($snippet_id, $code)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippet_revisions';

        // Limit to last 10 revisions per snippet
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE snippet_id = %d",
            $snippet_id
        ));

        if ($count >= 10) {
            // Delete oldest revision
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table WHERE snippet_id = %d ORDER BY changed_at ASC LIMIT 1",
                $snippet_id
            ));
        }

        // Save new revision
        $wpdb->insert($table, array(
            'snippet_id' => $snippet_id,
            'code' => $code,
            'changed_at' => current_time('mysql'),
            'changed_by' => get_current_user_id()
        ));
    }

    /**
     * Get revisions for a snippet
     */
    private function get_revisions($snippet_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippet_revisions';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, u.display_name as user_name 
             FROM $table r 
             LEFT JOIN {$wpdb->users} u ON r.changed_by = u.ID 
             WHERE r.snippet_id = %d 
             ORDER BY r.changed_at DESC",
            $snippet_id
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
        $revisions = $this->get_revisions($snippet_id);

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
            $this->save_revision($revision->snippet_id, $current_snippet->code);
        }

        // Restore the revision code (set inactive for safety)
        $wpdb->update(
            $snippet_table,
            array('code' => $revision->code, 'active' => 0),
            array('id' => $revision->snippet_id)
        );

        $this->log_snippet_action('RESTORED_REVISION', $revision->snippet_id, '', "From revision #{$revision_id}");

        wp_send_json_success(array(
            'message' => 'Revision restored! Snippet set to inactive for safety.',
            'code' => $revision->code
        ));
    }

    /**
     * Execute PHP snippet with enhanced error handling
     */
    private function execute_php_snippet($code, $snippet_id = 0, $run_once = false)
    {
        // Set custom error handler to catch runtime errors
        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        });

        try {
            // Execute the snippet
            eval($code);

            // Mark as executed if successful
            $this->mark_snippet_executed($snippet_id, $run_once);
        } catch (Throwable $e) {
            // Log the error
            error_log('Ofast Snippet Runtime Error (ID: ' . $snippet_id . '): ' . $e->getMessage());

            // Auto-deactivate the problematic snippet
            if ($snippet_id > 0) {
                $this->auto_deactivate_snippet($snippet_id, $e->getMessage());
            }
        } finally {
            // Always restore the error handler
            restore_error_handler();
        }
    }

    /**
     * Execute JavaScript snippet
     */
    private function execute_js_snippet($code, $location = 'footer', $snippet_id = 0, $run_once = false)
    {
        $hook = $this->get_injection_hook($location, 'js');
        $self = $this;

        add_action($hook, function () use ($code, $snippet_id, $run_once, $self) {
            echo "\n<script>\n" . $code . "\n</script>\n";
            $self->mark_snippet_executed($snippet_id, $run_once);
        }, 100);
    }

    /**
     * Execute CSS snippet
     */
    private function execute_css_snippet($code, $location = 'header', $snippet_id = 0, $run_once = false)
    {
        $hook = $this->get_injection_hook($location, 'css');
        $self = $this;

        add_action($hook, function () use ($code, $snippet_id, $run_once, $self) {
            echo "\n<style>\n" . $code . "\n</style>\n";
            $self->mark_snippet_executed($snippet_id, $run_once);
        }, 100);
    }

    /**
     * Execute HTML snippet
     */
    private function execute_html_snippet($code, $location = 'footer', $snippet_id = 0, $run_once = false)
    {
        $hook = $this->get_injection_hook($location, 'html');
        $self = $this;

        add_action($hook, function () use ($code, $snippet_id, $run_once, $self) {
            echo "\n" . $code . "\n";
            $self->mark_snippet_executed($snippet_id, $run_once);
        }, 100);
    }

    /**
     * Get WordPress hook based on injection location
     */
    private function get_injection_hook($location, $type = 'js')
    {
        $is_admin = is_admin();

        switch ($location) {
            case 'header':
                return $is_admin ? 'admin_head' : 'wp_head';
            case 'body':
                // wp_body_open is only available on frontend
                return $is_admin ? 'admin_head' : 'wp_body_open';
            case 'footer':
            default:
                return $is_admin ? 'admin_footer' : 'wp_footer';
        }
    }

    /**
     * Validate PHP code syntax
     * Returns true if valid, error message if invalid
     */
    private function validate_php_code($code)
    {
        // Check for common syntax errors
        $code = trim($code);

        // Allow empty code (for saving templates/drafts)
        if (empty($code)) {
            return true;
        }

        // Auto-strip PHP tags instead of rejecting (compatibility with other plugins)
        // This allows importing code from WPCode, Code Snippets, etc.
        $code = preg_replace('/<\?php\s*/i', '', $code);
        $code = preg_replace('/<\?\s*/i', '', $code);
        $code = preg_replace('/\s*\?>/i', '', $code);
        $code = trim($code);

        // SECURITY: Check for dangerous functions
        $dangerous_functions = array(
            'exec' => 'Execute system commands',
            'shell_exec' => 'Execute shell commands',
            'system' => 'Execute system commands',
            'passthru' => 'Execute commands and output',
            'popen' => 'Open process pipe',
            'proc_open' => 'Execute command via process',
            'pcntl_exec' => 'Execute program',
            'eval' => 'Execute arbitrary PHP (nested eval not allowed)',
            'assert' => 'Execute code as assertion',
            'create_function' => 'Create anonymous function (deprecated)',
            'unlink' => 'Delete files',
            'rmdir' => 'Remove directories',
            'rename' => 'Rename/move files',
            'copy' => 'Copy files',
            'file_put_contents' => 'Write to files',
            'fwrite' => 'Write to file handle',
            'fputs' => 'Write to file handle',
            'fopen' => 'Open files (with write mode)',
            'curl_exec' => 'Execute external requests',
            'base64_decode' => 'Decode obfuscated code',
            'preg_replace' => 'Can execute code with /e modifier',
            'include' => 'Include external files',
            'include_once' => 'Include external files',
            'require' => 'Include external files',
            'require_once' => 'Include external files',
            // CRASH-CAUSING FUNCTIONS
            'exit' => 'Terminates WordPress execution',
            'die' => 'Terminates WordPress execution',
            'sleep' => 'Causes server delays/timeouts',
            'usleep' => 'Causes server delays/timeouts',
            'set_time_limit' => 'Can cause infinite execution',
            'ini_set' => 'Can modify PHP configuration',
            'mail' => 'Can be used for spam (use wp_mail instead)',
            'header' => 'Can cause header errors (use wp_redirect instead)',
            'setcookie' => 'Can cause header errors',
            'session_start' => 'Can conflict with WordPress sessions',
            'ob_start' => 'Can break output buffering',
            'ob_end_clean' => 'Can break output buffering',
            'ob_end_flush' => 'Can break output buffering',
            'error_reporting' => 'Can hide critical errors',
            'restore_error_handler' => 'Can disable error handling',
            'register_shutdown_function' => 'Can interfere with WordPress shutdown',
            'define' => 'Can conflict with WordPress constants',
            'constant' => 'Can expose sensitive constants',
        );

        // INFINITE LOOP DETECTION - Check for while(true), while(1), for(;;)
        $infinite_loop_patterns = array(
            '/while\s*\(\s*true\s*\)/i' => 'while(true) infinite loop',
            '/while\s*\(\s*1\s*\)/i' => 'while(1) infinite loop',
            '/while\s*\(\s*!\s*false\s*\)/i' => 'while(!false) infinite loop',
            '/for\s*\(\s*;\s*;\s*\)/' => 'for(;;) infinite loop',
            '/while\s*\(\s*\$[a-z_]+\s*=\s*\$[a-z_]+\s*\)/i' => 'Self-referential while condition',
        );

        foreach ($infinite_loop_patterns as $pattern => $reason) {
            if (preg_match($pattern, $code)) {
                return "Crash prevention: {$reason} detected. This would freeze or crash your site.";
            }
        }

        // MEMORY EXHAUSTION DETECTION
        $memory_patterns = array(
            '/str_repeat\s*\(.{0,50}\d{6,}/i' => 'str_repeat with very large number can exhaust memory',
            '/array_fill\s*\(.{0,50}\d{6,}/i' => 'array_fill with very large number can exhaust memory',
            '/range\s*\(.{0,30}\d{7,}/i' => 'range() with large numbers can exhaust memory',
        );

        foreach ($memory_patterns as $pattern => $reason) {
            if (preg_match($pattern, $code)) {
                return "Memory protection: {$reason}";
            }
        }

        // RECURSIVE FUNCTION DETECTION
        // Extract function names and check if they call themselves without exit conditions
        if (preg_match_all('/function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/i', $code, $func_matches)) {
            foreach ($func_matches[1] as $func_name) {
                // Check if function calls itself
                $self_call_pattern = '/function\s+' . preg_quote($func_name, '/') . '\s*\([^)]*\)\s*\{[^}]*' . preg_quote($func_name, '/') . '\s*\(/is';
                if (preg_match($self_call_pattern, $code)) {
                    // Check if there's a clear exit condition (if/return before the recursive call)
                    $safe_recursion = '/function\s+' . preg_quote($func_name, '/') . '\s*\([^)]*\)\s*\{[^}]*(if\s*\([^)]+\)\s*\{?\s*return|if\s*\([^)]+\)\s*return)/is';
                    if (!preg_match($safe_recursion, $code)) {
                        return "Crash prevention: Function '{$func_name}' appears to be recursive without a clear exit condition. This could cause a stack overflow and crash your site.";
                    }
                }
            }
        }

        // DANGEROUS SQL DETECTION
        $dangerous_sql = array(
            '/\bDROP\s+(TABLE|DATABASE|INDEX)/i' => 'DROP TABLE/DATABASE - Destroys data permanently',
            '/\bTRUNCATE\s+TABLE/i' => 'TRUNCATE TABLE - Deletes all data from table',
            '/\bDELETE\s+FROM\s+\w+\s*(;|$)/i' => 'DELETE without WHERE - Deletes all rows',
            '/\bALTER\s+TABLE/i' => 'ALTER TABLE - Can break database structure',
            '/\bCREATE\s+(TABLE|DATABASE)/i' => 'CREATE TABLE/DATABASE - Should use WordPress dbDelta()',
            '/\$wpdb\s*->\s*query\s*\(\s*["\']?\s*DELETE/i' => 'Raw DELETE query - Use $wpdb->delete() instead',
        );

        foreach ($dangerous_sql as $pattern => $reason) {
            if (preg_match($pattern, $code)) {
                return "Database protection: {$reason}";
            }
        }

        // UNLIMITED SELECT - BLOCK to prevent memory exhaustion on large tables
        // Detects SELECT * FROM without LIMIT in get_results
        if (preg_match('/\$wpdb\s*->\s*get_results\s*\(\s*["\'][^"\']*SELECT\s+\*\s+FROM[^"\']*["\'](?![^)]*LIMIT)/i', $code)) {
            return "Database protection: SELECT * FROM without LIMIT can crash your site on large tables. Add LIMIT clause (e.g., LIMIT 100).";
        }

        // Also check for raw SQL variables without LIMIT
        if (preg_match('/["\']SELECT\s+\*\s+FROM\s+\w+["\']\s*(?!.*LIMIT)/i', $code)) {
            if (!preg_match('/LIMIT\s+\d+/i', $code)) {
                return "Database protection: SELECT * FROM without LIMIT can crash your site on large tables. Add LIMIT clause.";
            }
        }

        $code_lower = strtolower($code);
        foreach ($dangerous_functions as $func => $reason) {
            // Check for function calls with ( after function name
            $pattern = '/\b' . preg_quote($func, '/') . '\s*\(/i';
            if (preg_match($pattern, $code)) {
                return "🚨 Security blocked: '{$func}()' is not allowed. Reason: {$reason}";
            }
        }

        // Try to validate using token_get_all
        $test_code = '<?php ' . $code;

        // Suppress errors and check if code can be tokenized
        $old_error_reporting = error_reporting(0);
        $tokens = @token_get_all($test_code);
        error_reporting($old_error_reporting);

        if ($tokens === false) {
            return 'Invalid PHP syntax detected';
        }

        // Check for unclosed parentheses, brackets, braces
        $open_paren = 0;
        $open_bracket = 0;
        $open_brace = 0;

        foreach ($tokens as $token) {
            if (is_string($token)) {
                if ($token === '(') $open_paren++;
                if ($token === ')') $open_paren--;
                if ($token === '[') $open_bracket++;
                if ($token === ']') $open_bracket--;
                if ($token === '{') $open_brace++;
                if ($token === '}') $open_brace--;
            }
        }

        if ($open_paren != 0) {
            return 'Unclosed parenthesis ( ) detected';
        }
        if ($open_bracket != 0) {
            return 'Unclosed bracket [ ] detected';
        }
        if ($open_brace != 0) {
            return 'Unclosed brace { } detected';
        }

        // Additional validation - check last token for common errors
        $last_tokens = array_slice($tokens, -5);
        $has_semicolon = false;

        foreach ($last_tokens as $token) {
            if ($token === ';') {
                $has_semicolon = true;
            }
        }

        // If code has function calls but no semicolon, warn
        if (!$has_semicolon && (strpos($code, 'add_action') !== false || strpos($code, 'add_filter') !== false)) {
            return 'Missing semicolon ; at the end of function call';
        }

        return true; // Valid!
    }

    /**
     * SECURITY: Log snippet actions for audit trail
     */
    private function log_snippet_action($action, $snippet_id, $snippet_name = '', $details = '')
    {
        $user = wp_get_current_user();
        $log_entry = sprintf(
            '[%s] SNIPPET %s: ID=%d, Name="%s", User=%s (ID:%d), IP=%s %s',
            current_time('Y-m-d H:i:s'),
            strtoupper($action),
            $snippet_id,
            $snippet_name,
            $user->user_login,
            $user->ID,
            $this->get_client_ip(),
            $details ? "| {$details}" : ''
        );

        // Log to WordPress debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[OFAST_SNIPPETS] ' . $log_entry);
        }

        // Also store in options for admin viewing
        $logs = get_option('ofast_snippet_audit_log', array());
        array_unshift($logs, array(
            'time' => current_time('mysql'),
            'action' => $action,
            'snippet_id' => $snippet_id,
            'snippet_name' => $snippet_name,
            'user_id' => $user->ID,
            'user_login' => $user->user_login,
            'ip' => $this->get_client_ip(),
            'details' => $details
        ));

        // Keep only last 100 entries
        $logs = array_slice($logs, 0, 100);
        update_option('ofast_snippet_audit_log', $logs);
    }

    /**
     * Get client IP address
     */
    private function get_client_ip()
    {
        $ip_keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR');
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = explode(',', $_SERVER[$key]);
                return trim($ip[0]);
            }
        }
        return 'Unknown';
    }

    /**
     * Auto-deactivate snippet on runtime error
     */
    private function auto_deactivate_snippet($snippet_id, $error_message = '')
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';

        // Get snippet details before deactivating
        $snippet = $wpdb->get_row($wpdb->prepare(
            "SELECT name, code FROM $table WHERE id = %d",
            $snippet_id
        ));

        if (!$snippet) {
            return;
        }

        // Deactivate the snippet
        $wpdb->update(
            $table,
            array('active' => 0),
            array('id' => $snippet_id)
        );

        // Log the auto-deactivation
        $this->log_snippet_action(
            'AUTO_DEACTIVATED',
            $snippet_id,
            $snippet->name,
            'Runtime error: ' . substr($error_message, 0, 200)
        );

        // Store error for admin notice
        $failed_snippets = get_transient('ofast_failed_snippets') ?: array();
        $failed_snippets[] = array(
            'id' => $snippet_id,
            'name' => $snippet->name,
            'error' => $error_message,
            'time' => current_time('mysql')
        );
        set_transient('ofast_failed_snippets', $failed_snippets, DAY_IN_SECONDS);

        // Show admin notice on next page load
        add_action('admin_notices', array($this, 'show_runtime_error_notice'));
    }

    /**
     * Show admin notice for runtime errors
     */
    public function show_runtime_error_notice()
    {
        $failed_snippets = get_transient('ofast_failed_snippets');

        if (empty($failed_snippets)) {
            return;
        }

        foreach ($failed_snippets as $failed) {
            $message = '<strong>Snippet Auto-Deactivated:</strong> "' . esc_html($failed['name']) . '" encountered a runtime error and was automatically deactivated for safety. ';
            $message .= '<strong>Error:</strong> ' . esc_html($failed['error']) . ' ';
            $message .= '<a href="' . admin_url('admin.php?page=ofast-snippets&edit=' . $failed['id']) . '" style="color:#fff;text-decoration:underline;">Fix Snippet</a>';
            echo Ofast_X_Toast::render($message, 'error');
        }

        // Clear the transient after showing
        delete_transient('ofast_failed_snippets');
    }

    /**
     * SECURITY: Rate limiting check
     * Returns true if action is allowed, false if rate limited
     */
    private function check_rate_limit($action = 'snippet_action')
    {
        $user_id = get_current_user_id();
        $transient_key = "ofast_rate_{$action}_{$user_id}";
        $attempts = get_transient($transient_key);

        if ($attempts === false) {
            // First attempt
            set_transient($transient_key, 1, 60); // 60 second window
            return true;
        }

        if ($attempts >= 30) { // Max 30 actions per minute
            return false;
        }

        set_transient($transient_key, $attempts + 1, 60);
        return true;
    }

    /**
     * Cleanup snippets that have been in trash for more than 30 days
     * Runs daily via cron job
     */
    public function cleanup_old_trash()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';
        
        // Delete snippets in trash for more than 30 days
        $deleted = $wpdb->query(
            "DELETE FROM $table 
             WHERE status = 'trash' 
             AND trashed_at IS NOT NULL 
             AND trashed_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        
        if ($deleted > 0) {
            error_log("Ofast X Snippets: Auto-purged {$deleted} snippet(s) from trash (30+ days old)");
        }
        
        // Clear cache after cleanup
        $this->clear_snippets_cache();
    }

    /**
     * Add missing columns for trash system (auto-migration)
     * Only runs once, tracks via option
     */
    private function maybe_add_trash_columns()
    {
        // Only run if not already migrated
        if (get_option('ofast_snippets_trash_columns_added')) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        if (!$table_exists) {
            return;
        }

        // Get existing columns
        $columns = $wpdb->get_col("DESCRIBE {$table}", 0);

        // Add status column if missing
        if (!in_array('status', $columns)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN status VARCHAR(20) DEFAULT 'active' AFTER priority");
            error_log("Ofast X Snippets: Added 'status' column");
        }

        // Add trashed_at column if missing
        if (!in_array('trashed_at', $columns)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN trashed_at DATETIME DEFAULT NULL AFTER status");
            error_log("Ofast X Snippets: Added 'trashed_at' column");
        }

        // Add priority column if missing
        if (!in_array('priority', $columns)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN priority INT(11) DEFAULT 10 AFTER executed_at");
            error_log("Ofast X Snippets: Added 'priority' column");
        }

        // Mark as migrated
        update_option('ofast_snippets_trash_columns_added', true);
    }
}
