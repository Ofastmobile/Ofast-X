<?php

/**
 * Ofast X - Code Snippets Admin
 * Handles admin UI rendering: page, dashboard widget, CodeMirror enqueue,
 * and the snippet editor form with all CSS/JS.
 *
 * @since 1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Snippets_Admin
{
    /** @var Ofast_X_Snippets */
    private $core;

    public function __construct(Ofast_X_Snippets $core)
    {
        $this->core = $core;
    }

    /**
     * Register admin hooks
     */
    public function register_hooks()
    {
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_codemirror'));
    }

    /**
     * Enqueue CodeMirror for the snippet editor
     */
    public function enqueue_codemirror($hook)
    {
        // Only on our snippets page
        if (strpos((string) $hook, 'ofast-snippets') === false) {
            return;
        }

        // WordPress CodeMirror settings for PHP
        $settings = wp_enqueue_code_editor(array(
            'type' => 'text/x-php',
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

        // If CodeMirror is disabled in user profile settings, show fallback notice and bail.
        if (false === $settings) {
            add_action('admin_notices', array($this, 'show_codemirror_disabled_notice'));
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
     * Show notice when WordPress code editor is disabled for current user.
     */
    public function show_codemirror_disabled_notice()
    {
        if (!isset($_GET['page']) || sanitize_key($_GET['page']) !== 'ofast-snippets') {
            return;
        }
?>
        <div class="notice notice-info is-dismissible">
            <p><?php esc_html_e('Code editor is disabled for your user profile. You can enable syntax highlighting in your profile to use CodeMirror here.', 'ofast-x'); ?></p>
        </div>
<?php
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

        // Filter out trashed snippets
        if ($this->core->ensure_snippets_trash_schema()) {
            $snippets = $wpdb->get_results("SELECT * FROM $table WHERE (status IS NULL OR status != 'trash') ORDER BY id DESC LIMIT 10");
        } else {
            $snippets = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 10");
        }

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
            $validation_result = $this->core->validator->validate_php_code($snippet->code);
            $has_error = ($validation_result !== true);

            // Check for duplicate conflicts
            $dup_info = $this->core->validator->get_potential_duplicates($snippet->id, $snippet->name, $snippet->code);
            $is_duplicate = $dup_info['has_duplicate'];

            $active_class = $snippet->active ? 'button-primary' : '';

            // Add error indicator if validation fails
            $error_indicator = $has_error ? ' <span style="color: red; font-size: 16px;" title="' . esc_attr($validation_result) . '">● </span>' : '';

            // Add duplicate indicator
            $dup_indicator = '';
            $dup_description = '';
            if ($is_duplicate && !$has_error) {
                $dup_reasons = implode('; ', array_slice($dup_info['reasons'], 0, 2));
                $dup_indicator = ' <span style="color: red; font-size: 16px;" title="' . esc_attr($dup_reasons) . '">● </span>';
                $dup_description = $dup_reasons;
            }

            echo '<tr>';
            echo '<td>' . $error_indicator . $dup_indicator . '<strong>' . esc_html($snippet->name) . '</strong>';
            if ($has_error) {
                echo '<br><small style="color: #dc3545;">' . esc_html($validation_result) . '</small>';
            }
            if ($dup_description) {
                echo '<br><small style="color: #dc3545;">' . esc_html($dup_description) . '</small>';
            }
            echo '</td>';
            echo '<td style="text-align: center;">';
            echo '<button class="button button-small ofast-snippet-toggle ' . $active_class . '" data-id="' . $snippet->id . '" data-active="' . $snippet->active . '" data-has-error="' . ($has_error ? '1' : '0') . '" data-is-duplicate="' . ($is_duplicate ? '1' : '0') . '">';
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
                // ── Tier 2 modal helper (global, used by dashboard and main page) ──
                window.ofastShowTier2Modal = function(data, onConfirm, onCancel) {
                    var $modal = jQuery('#ofast-tier2-modal');
                    if ($modal.length === 0) {
                        var funcNames = Object.keys(data.functions || {}).join(', ');
                        if (confirm('\u26a0\ufe0f This snippet uses: ' + funcNames + '\n\nThese are commonly used in WordPress. Activate anyway?')) {
                            onConfirm();
                        } else if (onCancel) {
                            onCancel();
                        }
                        return;
                    }
                    var $funcs = $modal.find('.ofast-tier2-functions');
                    $funcs.empty();
                    if (data.functions) {
                        jQuery.each(data.functions, function(name, reason) {
                            $funcs.append(
                                '<div class="ofast-tier2-func-item">' +
                                '<span class="ofast-tier2-func-name">' + jQuery('<span>').text(name).html() + '()</span>' +
                                '<span class="ofast-tier2-func-reason">' + jQuery('<span>').text(reason).html() + '</span>' +
                                '</div>'
                            );
                        });
                    }
                    $modal.show();
                    $modal.find('.ofast-tier2-cancel, .ofast-tier2-overlay').off('click').on('click', function() {
                        $modal.hide();
                        if (onCancel) onCancel();
                    });
                    $modal.find('.ofast-tier2-confirm').off('click').on('click', function() {
                        $modal.hide();
                        onConfirm();
                    });
                    jQuery(document).off('keydown.tier2modal').on('keydown.tier2modal', function(e) {
                        if (e.keyCode === 27) {
                            $modal.hide();
                            jQuery(document).off('keydown.tier2modal');
                            if (onCancel) onCancel();
                        }
                    });
                };

                $(document).on('click', '.ofast-snippet-toggle', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var id = $btn.data('id');
                    var active = $btn.data('active');
                    var hasError = $btn.data('has-error');
                    var isDuplicate = $btn.data('is-duplicate');

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
                            // Check for Tier 2 confirmation
                            if (response.data.confirm_required) {
                                ofastShowTier2Modal(response.data, function() {
                                    // On confirm: re-send with force_activate
                                    $.post(ajaxurl, {
                                        action: 'ofast_toggle_snippet',
                                        nonce: '<?php echo wp_create_nonce('ofast_snippet_toggle'); ?>',
                                        id: id,
                                        active: active,
                                        force_activate: 'true'
                                    }, function(r2) {
                                        if (r2.success) {
                                            var newActive = r2.data.active;
                                            $btn.data('active', newActive);
                                            $btn.html(newActive ? 'ON' : 'OFF');
                                            $btn.toggleClass('button-primary', newActive);
                                        } else {
                                            alert('Error: ' + (r2.data || 'Unable to toggle snippet.'));
                                        }
                                        $btn.prop('disabled', false);
                                    });
                                }, function() {
                                    // On cancel: re-enable button
                                    $btn.prop('disabled', false);
                                });
                                return;
                            }
                            var newActive = response.data.active;
                            $btn.data('active', newActive);
                            $btn.html(newActive ? 'ON' : 'OFF');
                            $btn.toggleClass('button-primary', newActive);
                        } else {
                            var msg = response.data || 'Unknown error';
                            if (msg.indexOf('duplicate conflict') !== -1) {
                                alert('Cannot activate: a duplicate snippet is already active.\n\n' + msg + '\n\nDeactivate the conflicting snippet first, then try again.');
                            } else {
                                alert('Error: ' + msg);
                            }
                        }
                    }).fail(function() {
                        alert('Request failed. Please check your connection and try again.');
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
        $trash_supported = $this->core->ensure_snippets_trash_schema();
        $priority_supported = $this->core->ensure_snippets_priority_schema();
        $this->core->ensure_snippets_language_schema();
        $is_trash_view = isset($_GET['view']) && sanitize_key($_GET['view']) === 'trash';
        if (!$trash_supported) {
            $is_trash_view = false;
        }

        // Handle add/edit with validation
        if (isset($_POST['ofast_save_snippet'])) {
            check_admin_referer('ofast_snippet_save', '_wpnonce');

            $id = isset($_POST['snippet_id']) ? intval($_POST['snippet_id']) : 0;
            $name = sanitize_text_field($_POST['snippet_name']);
            $description = isset($_POST['snippet_description']) ? wp_unslash($_POST['snippet_description']) : '';
            $language = $this->core->normalize_snippet_language(isset($_POST['snippet_language']) ? sanitize_text_field($_POST['snippet_language']) : 'php');
            $scope = isset($_POST['snippet_scope']) ? sanitize_text_field($_POST['snippet_scope']) : 'global';
            $location = isset($_POST['snippet_location']) ? sanitize_text_field($_POST['snippet_location']) : 'footer';
            $run_once = isset($_POST['snippet_run_once']) ? 1 : 0;
            $snippet_priority = isset($_POST['snippet_priority']) ? intval($_POST['snippet_priority']) : 10;
            $snippet_priority = max(1, min(9999, $snippet_priority));
            $target_type = isset($_POST['snippet_target_type']) ? sanitize_text_field($_POST['snippet_target_type']) : 'all';
            $target_value = isset($_POST['snippet_target_value']) ? sanitize_text_field($_POST['snippet_target_value']) : '';
            $category = isset($_POST['snippet_category']) ? sanitize_text_field($_POST['snippet_category']) : '';

            // Process tags - convert comma-separated to JSON array
            $tags_raw = isset($_POST['snippet_tags']) ? sanitize_text_field($_POST['snippet_tags']) : '';
            $tags_array = array_filter(array_map('trim', explode(',', $tags_raw)));
            $tags_json = !empty($tags_array) ? json_encode(array_values($tags_array)) : '';

            $code = wp_unslash($_POST['snippet_code']);

            // Payload size limit: 1MB max — hard stop
            if (strlen($code) > 1048576) {
                echo Ofast_X_Toast::render(__('Snippet code exceeds the maximum size of 1MB. Save aborted.', 'ofast-x'), 'error');
                return;
            }

            if ($language === 'php') {
                // Accept pasted snippets with PHP wrappers and store normalized code.
                $code = $this->core->normalize_php_code($code);
            }
            $active = isset($_POST['snippet_active']) ? 1 : 0;

            // Language mismatch detection — warn if code looks like a different language
            $detected_lang = $this->core->validator->detect_code_language($code);
            $lang_labels = array('php' => 'PHP', 'javascript' => 'JavaScript', 'css' => 'CSS', 'html' => 'HTML');
            if ($detected_lang && $detected_lang !== $language) {
                $detected_label = isset($lang_labels[$detected_lang]) ? $lang_labels[$detected_lang] : strtoupper($detected_lang);
                $selected_label = isset($lang_labels[$language]) ? $lang_labels[$language] : strtoupper($language);
                $active = 0; // Force inactive — wrong language could crash the site
                echo Ofast_X_Toast::render("⚠️ Language mismatch: This code looks like {$detected_label} but you selected {$selected_label}. Code saved but NOT activated. Change the language and save again.", 'warning');
            }
            
            // Validate syntax for ALL languages
            $validation = $this->core->validator->validate_code($code, $language);

            // Handle validation results based on security tier
            if ($this->core->validator->is_hard_error($validation)) {
                // TIER 1 or syntax error — force inactive, hard block
                $active = 0;
                $warning_msg = 'Code Saved But NOT Activated: ' . esc_html($validation) . ' Fix the issue and try activating again.';
                echo Ofast_X_Toast::render($warning_msg, 'warning');
            } elseif ($this->core->validator->is_tier2_warning($validation)) {
                // TIER 2 — save the code, show warning, but allow save (activation needs confirm via AJAX)
                if ($active === 1) {
                    $active = 0; // Don't auto-activate on save — require AJAX toggle with confirm
                    echo Ofast_X_Toast::render('⚠️ Snippet saved as inactive. This code uses functions that need confirmation before activation: ' . esc_html(implode(', ', array_keys($validation['functions']))) . '. Use the toggle switch to activate with confirmation.', 'warning');
                }
            } elseif ($this->core->validator->is_tier3_info($validation)) {
                // TIER 3 — allow everything, show info toast
                echo Ofast_X_Toast::render('ℹ️ ' . esc_html($validation['message']) . ' (' . esc_html(implode(', ', array_keys($validation['functions']))) . ')', 'info');
            }

            // Prevent activating snippets that conflict with already active snippets.
            if ($language === 'php' && $active === 1) {
                $function_conflict = $this->core->validator->check_function_conflicts($code);
                if ($function_conflict !== true) {
                    $active = 0;
                    echo Ofast_X_Toast::render('Snippet saved as inactive: ' . esc_html($function_conflict), 'warning');
                } else {
                    $activation_conflicts = $this->core->validator->get_active_duplicate_conflicts($id, $name, $code);
                    if (!empty($activation_conflicts)) {
                        $active = 0;
                        $conflict_preview = implode(' | ', array_slice($activation_conflicts, 0, 2));
                        if (count($activation_conflicts) > 2) {
                            $conflict_preview .= ' | +' . (count($activation_conflicts) - 2) . ' more';
                        }
                        echo Ofast_X_Toast::render('Duplicate protection: snippet saved as inactive. Resolve active conflicts first. ' . esc_html($conflict_preview), 'warning');
                    }
                }
            }

            if ($id > 0) {
                // Get old code to save as revision (only if code changed)
                $old_snippet = $wpdb->get_row($wpdb->prepare("SELECT code FROM $table WHERE id = %d", $id));
                if ($old_snippet && $old_snippet->code !== $code) {
                    $this->core->save_revision($id, $old_snippet->code);
                }

                // Update
                $update_data = array(
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
                    'active' => $active
                );
                if ($priority_supported) {
                    $update_data['priority'] = $snippet_priority;
                }

                $result = $wpdb->update($table, $update_data, array('id' => $id));

                // Debug logging for live server issues
                if ($result === false) {
                    error_log('Ofast Snippet Update Error: ' . $wpdb->last_error);
                    echo Ofast_X_Toast::render('Database Error: ' . esc_html($wpdb->last_error), 'error');
                    return;
                }

                // Audit log
                $this->core->log_snippet_action('UPDATED', $id, $name, "Language: {$language}, Scope: {$scope}, Active: " . ($active ? 'Yes' : 'No'));

                if ($validation === true) {
                    echo Ofast_X_Toast::render('Snippet updated and ' . ($active ? 'activated' : 'saved') . '!', 'success');
                } else {
                    echo Ofast_X_Toast::render('Snippet saved (inactive for safety)', 'info');
                }
            } else {
                // DUPLICATE CHECK: Prevent saving snippets with same name
                if ($trash_supported) {
                    $existing_name = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, name FROM $table WHERE name = %s AND (status IS NULL OR status != 'trash')",
                        $name
                    ));
                } else {
                    $existing_name = $wpdb->get_row($wpdb->prepare("SELECT id, name FROM $table WHERE name = %s", $name));
                }
                if ($existing_name) {
                    echo Ofast_X_Toast::render('Duplicate Name: A snippet named "' . esc_html($name) . '" already exists. Please use a different name or edit the existing snippet.', 'error');
                    // Don't redirect, let form stay with data so user can fix
                } else {
                    // DUPLICATE CODE CHECK: Prevent saving snippets with same code
                    $code_hash = md5(trim($code));
                    if ($trash_supported) {
                        $existing_code = $wpdb->get_row($wpdb->prepare(
                            "SELECT id, name FROM $table WHERE MD5(TRIM(code)) = %s AND (status IS NULL OR status != 'trash')",
                            $code_hash
                        ));
                    } else {
                        $existing_code = $wpdb->get_row($wpdb->prepare(
                            "SELECT id, name FROM $table WHERE MD5(TRIM(code)) = %s",
                            $code_hash
                        ));
                    }
                    if ($existing_code) {
                        echo Ofast_X_Toast::render('Duplicate Code: This exact code already exists in snippet "' . esc_html($existing_code->name) . '" (ID: ' . $existing_code->id . '). Edit the existing snippet instead.', 'error');
                        // Don't save, let user decide
                    } else {
                        // Insert
                        $insert_data = array(
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
                            'created_at' => current_time('mysql')
                        );
                        if ($priority_supported) {
                            $insert_data['priority'] = $snippet_priority;
                        }

                        $result = $wpdb->insert($table, $insert_data);

                        // Debug logging for live server issues
                        if ($result === false) {
                            error_log('Ofast Snippet Save Error: ' . $wpdb->last_error);
                            echo Ofast_X_Toast::render('Database Error: ' . esc_html($wpdb->last_error), 'error');
                            return;
                        }

                        $new_id = $wpdb->insert_id;

                        // Audit log
                        $this->core->log_snippet_action('CREATED', $new_id, $name, "Language: {$language}, Scope: {$scope}, Active: " . ($active ? 'Yes' : 'No'));

                        if ($validation === true) {
                            echo Ofast_X_Toast::render('Snippet added and ' . ($active ? 'activated' : 'saved') . '!', 'success');
                        } else {
                            echo Ofast_X_Toast::render('Snippet saved (inactive for safety)', 'info');
                        }
                    } // End of !$existing_code else block
                } // End of !$existing_name else block
            }
        }

        // Get snippets (active list or trash list)
        $active_order_by = $priority_supported ? 'priority ASC, id DESC' : 'id DESC';
        $trash_order_by = $priority_supported ? 'priority ASC, trashed_at DESC, id DESC' : 'trashed_at DESC, id DESC';
        if ($trash_supported && $is_trash_view) {
            $snippets = $wpdb->get_results("SELECT * FROM $table WHERE status = 'trash' ORDER BY {$trash_order_by}");
        } elseif ($trash_supported) {
            $snippets = $wpdb->get_results("SELECT * FROM $table WHERE status IS NULL OR status != 'trash' ORDER BY {$active_order_by}");
        } else {
            $snippets = $wpdb->get_results("SELECT * FROM $table ORDER BY {$active_order_by}");
        }

        $trash_count = 0;
        if ($trash_supported) {
            $trash_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'trash'");
        }

        // Editing mode
        $editing = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $edit_snippet = $editing ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $editing)) : null;
        if ($edit_snippet && isset($edit_snippet->language)) {
            $edit_snippet->language = $this->core->normalize_snippet_language($edit_snippet->language);
        }

    ?>
        <div class="wrap ofast-snippets-wrap">
            <!-- Ofast Header -->
            <div style="display: flex; align-items: center; gap: 20px; background: #fff; border: 1px solid rgba(226, 232, 240, 0.6); padding: 25px 30px; border-radius: 16px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); margin-bottom: 20px; margin-top: 20px;">
                <div style="width: 56px; height: 56px; background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02); border-radius: 16px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <span class="dashicons dashicons-editor-code" style="font-size: 28px; width: 28px; height: 28px; color: #6366f1;"></span>
                </div>
                <div>
                    <h1 style="margin: 0 0 5px 0; font-size: 24px; font-weight: 700; color: #1e293b; padding: 0;">Code Snippets Manager</h1>
                    <p style="margin: 0; color: #64748b; font-size: 14px;">Add PHP, JavaScript, CSS & HTML code snippets that run on your WordPress site.</p>
                </div>
            </div>
            <!-- Action Buttons Bar -->
            <div style="background: #fff; border: 1px solid rgba(226, 232, 240, 0.6); border-radius: 16px; padding: 15px 20px; margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);">
                <a href="<?php echo admin_url('admin.php?page=ofast-snippets'); ?>" class="button button-primary" style="display: inline-flex; align-items: center; gap: 5px;">
                    New Snippet
                </a>
                <select id="ofast-export-type" class="regular-text" style="width: auto;">
                    <option value="json">Export as JSON</option>
                    <option value="json_active">Export Active Only</option>
                    <option value="code">Export as Code</option>
                </select>
                <button type="button" class="button" id="ofast-export-snippets" style="display: inline-flex; align-items: center; gap: 5px;">
                    Export
                </button>
                <button type="button" class="button" id="ofast-import-snippets-btn" style="display: inline-flex; align-items: center; gap: 5px;">
                    Import
                </button>
                <input type="file" id="ofast-import-file" accept=".json" style="display: none;">
                <?php if (!$is_trash_view): ?>
                    <button type="button" class="button ofast-desktop-toggle-btn" id="ofast-toggle-import-section" style="display: none;">
                        Import from Plugins
                    </button>
                    <button type="button" class="button ofast-desktop-toggle-btn" id="ofast-toggle-library-section" style="display: none;">
                        Library
                    </button>
                <?php endif; ?>
                <div style="margin-left: auto; display: flex; align-items: center; gap: 8px;">
                    <span style="color: #666; font-size: 12px;">
                        <?php echo $is_trash_view ? 'Trash: ' : 'Total: '; ?><?php echo count($snippets); ?> snippet(s)
                    </span>
                    <?php if ($trash_supported): ?>
                        <?php if ($is_trash_view): ?>
                            <a href="<?php echo admin_url('admin.php?page=ofast-snippets'); ?>" class="button">Back to Snippets</a>
                        <?php else: ?>
                            <a href="<?php echo admin_url('admin.php?page=ofast-snippets&view=trash'); ?>" class="button">Trash (<?php echo intval($trash_count); ?>)</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <style>
                /* Ofast button styles for snippets page */
                .ofast-snippets-wrap .button.button-primary,
                .ofast-snippets-wrap a.button.button-primary {
                    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%) !important;
                    color: #fff !important;
                    border: none !important;
                    border-radius: 8px !important;
                    font-weight: 600 !important;
                    box-shadow: 0 2px 8px rgba(99, 102, 241, 0.3) !important;
                    transition: all 0.3s ease !important;
                    text-shadow: none !important;
                }
                .ofast-snippets-wrap .button.button-primary:hover,
                .ofast-snippets-wrap a.button.button-primary:hover {
                    background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%) !important;
                    transform: translateY(-1px) !important;
                    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4) !important;
                    color: #fff !important;
                }
                .ofast-snippets-wrap .button:not(.button-primary):not(#ofast-empty-trash) {
                    background: #fff !important;
                    color: #6366f1 !important;
                    border: 1px solid #6366f1 !important;
                    border-radius: 8px !important;
                    font-weight: 500 !important;
                    transition: all 0.3s ease !important;
                }
                .ofast-snippets-wrap .button:not(.button-primary):not(#ofast-empty-trash):hover {
                    background: #f0f0ff !important;
                    color: #4f46e5 !important;
                    border-color: #4f46e5 !important;
                    transform: translateY(-1px) !important;
                }
                .ofast-snippets-wrap #ofast-empty-trash {
                    background: #fff !important;
                    color: #dc2626 !important;
                    border: 1px solid #dc2626 !important;
                    border-radius: 8px !important;
                    font-weight: 500 !important;
                    transition: all 0.3s ease !important;
                }
                .ofast-snippets-wrap #ofast-empty-trash:hover {
                    background: #fef2f2 !important;
                    color: #b91c1c !important;
                    border-color: #b91c1c !important;
                    transform: translateY(-1px) !important;
                }
                /* Ofast custom dropdown */
                .ofast-snippets-wrap select {
                    -webkit-appearance: none !important;
                    -moz-appearance: none !important;
                    appearance: none !important;
                    background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236366f1' d='M6 8.825a.7.7 0 0 1-.5-.2L2.05 5.15a.68.68 0 0 1 0-.975.68.68 0 0 1 .975 0L6 7.175l2.975-3a.68.68 0 0 1 .975 0 .68.68 0 0 1 0 .975L6.5 8.625a.7.7 0 0 1-.5.2Z'/%3E%3C/svg%3E") no-repeat right 10px center !important;
                    border: 1px solid #e2e8f0 !important;
                    border-radius: 8px !important;
                    padding: 8px 32px 8px 12px !important;
                    font-size: 13px !important;
                    color: #1e293b !important;
                    cursor: pointer !important;
                    transition: all 0.2s ease !important;
                    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05) !important;
                    line-height: 1.4 !important;
                    height: auto !important;
                    min-height: 36px !important;
                }
                .ofast-snippets-wrap select:hover {
                    border-color: #6366f1 !important;
                    box-shadow: 0 1px 3px rgba(99, 102, 241, 0.15) !important;
                }
                .ofast-snippets-wrap select:focus {
                    outline: none !important;
                    border-color: #6366f1 !important;
                    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15) !important;
                }
                .ofast-snippets-wrap input[type="text"]:hover { border-color: #6366f1 !important; }
                .ofast-snippets-wrap input[type="text"]:focus { border-color: #6366f1 !important; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15) !important; outline: none !important; }
                /* Ofast custom dropdown component */
                .ofast-dropdown { position: relative; display: inline-block; }
                .ofast-dropdown-trigger {
                    display: inline-flex; align-items: center; justify-content: space-between; gap: 8px;
                    background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
                    padding: 8px 12px; font-size: 13px; color: #1e293b; cursor: pointer;
                    transition: all 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.05);
                    min-height: 36px; line-height: 1.4; min-width: 120px; user-select: none;
                }
                .ofast-dropdown-trigger:hover { border-color: #6366f1; box-shadow: 0 1px 3px rgba(99,102,241,0.15); }
                .ofast-dropdown.open .ofast-dropdown-trigger { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.15); }
                .ofast-dropdown-arrow {
                    width: 0; height: 0; border-left: 4px solid transparent; border-right: 4px solid transparent;
                    border-top: 5px solid #6366f1; transition: transform 0.2s ease; flex-shrink: 0;
                }
                .ofast-dropdown.open .ofast-dropdown-arrow { transform: rotate(180deg); }
                .ofast-dropdown-menu {
                    position: absolute; top: calc(100% + 4px); left: 0; z-index: 9999;
                    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
                    box-shadow: 0 10px 25px rgba(0,0,0,0.12), 0 4px 10px rgba(0,0,0,0.08);
                    min-width: 100%; max-height: 220px; overflow-y: auto; padding: 4px;
                    opacity: 0; transform: translateY(-8px); pointer-events: none;
                    transition: all 0.2s ease;
                }
                .ofast-dropdown.open .ofast-dropdown-menu { opacity: 1; transform: translateY(0); pointer-events: auto; }
                .ofast-dropdown-option {
                    padding: 8px 12px; font-size: 13px; color: #334155; cursor: pointer;
                    border-radius: 6px; transition: all 0.15s ease; display: flex;
                    align-items: center; justify-content: space-between; gap: 8px;
                }
                .ofast-dropdown-option:hover { background: #f0edff; color: #6366f1; }
                .ofast-dropdown-option.selected { background: #6366f1; color: #fff; font-weight: 500; }
                .ofast-dropdown-option.selected:hover { background: #4f46e5; }
                .ofast-dropdown-check { font-size: 11px; }
                /* Ofast toggle switch */
                .ofast-toggle-wrap { position: relative; display: inline-block; width: 36px; height: 20px; }
                .ofast-toggle-wrap input { opacity: 0; width: 0; height: 0; position: absolute; }
                .ofast-toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background: #ccc; border-radius: 20px; transition: 0.4s; }
                .ofast-toggle-slider:before { content: ''; position: absolute; height: 14px; width: 14px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: 0.4s; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
                .ofast-toggle-wrap input:checked + .ofast-toggle-slider { background: #6366f1; }
                .ofast-toggle-wrap input:checked + .ofast-toggle-slider:before { transform: translateX(16px); }
                .ofast-toggle-wrap input:disabled + .ofast-toggle-slider { opacity: 0.5; cursor: not-allowed; }
                .ofast-toggle-wrap:hover .ofast-toggle-slider { opacity: 0.8; }
                /* Desktop: hide full sections, show toggle buttons */
                @media (min-width: 1025px) {
                    .ofast-desktop-toggle-btn { display: inline-flex !important; }
                    .ofast-collapsible-section { display: none; }
                    .ofast-collapsible-section.ofast-section-visible { display: block; }
                }
                /* Tablet & Mobile: show full sections, hide toggle buttons */
                @media (max-width: 1024px) {
                    .ofast-desktop-toggle-btn { display: none !important; }
                    .ofast-collapsible-section { display: block; }
                }
            </style>

            <?php if (!$is_trash_view):
            // Detect other snippet plugins
            $other_plugins = $this->core->importer->detect_other_snippet_plugins();
            if (!empty($other_plugins)):
            ?>
                <!-- Import from Other Plugins -->
                <div id="ofast-import-plugins-section" class="ofast-collapsible-section" style="background: #f0f6fc; border: 1px solid #c3d9ed; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0;">
                        <h3 style="margin: 0; color: #1d4ed8; font-size: 15px;">Import from Other Plugins</h3>
                        <button type="button" class="button" id="toggle-import-plugins">Show Plugins</button>
                    </div>
                    
                    <div id="import-plugins-content" style="display: none;">
                        <p style="color: #1e40af; margin: 15px 0; font-size: 13px;">We detected other snippet plugins on your site. You can import their snippets here.</p>
                        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                            <?php foreach ($other_plugins as $plugin): ?>
                                <div class="ofast-import-plugin-card" style="background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 15px; min-width: 250px; display: flex; align-items: center; justify-content: space-between; gap: 15px;">
                                    <div style="flex: 1;">
                                        <strong style="font-size: 14px;"><?php echo esc_html($plugin['name']); ?></strong>
                                        <p style="margin: 4px 0 0; color: #666; font-size: 12px;">
                                            <?php echo intval($plugin['count']); ?> snippet(s) available
                                        </p>
                                    </div>
                                    <div class="ofast-import-btn-group" style="display: flex; flex-direction: column; gap: 6px; min-width: 140px;">
                                        <button type="button" class="button ofast-preview-plugin-snippets"
                                            data-plugin="<?php echo esc_attr($plugin['slug']); ?>"
                                            data-plugin-name="<?php echo esc_attr($plugin['name']); ?>"
                                            style="width: 100%; text-align: center;">
                                            Preview &amp; Import
                                        </button>
                                        <button type="button" class="button ofast-import-from-plugin"
                                            data-plugin="<?php echo esc_attr($plugin['slug']); ?>"
                                            style="width: 100%; text-align: center;">
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
                <div id="ofast-library-section" class="ofast-collapsible-section" style="background: #f0f6fc; border: 1px solid #c3d9ed; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
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

            <?php endif; /* !$is_trash_view */ ?>
            <?php if (!$is_trash_view): ?>
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
                                        <?php $edit_language = $edit_snippet ? $this->core->normalize_snippet_language($edit_snippet->language) : 'php'; ?>
                                        <select name="snippet_language" id="snippet_language" class="regular-text">
                                            <option value="php" <?php selected($edit_language, 'php'); ?>>PHP</option>
                                            <option value="javascript" <?php selected($edit_language, 'javascript'); ?>>JavaScript</option>
                                            <option value="css" <?php selected($edit_language, 'css'); ?>>CSS</option>
                                            <option value="html" <?php selected($edit_language, 'html'); ?>>HTML</option>
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
                                <tr>
                                    <th><label for="snippet_priority">Priority</label></th>
                                    <td>
                                        <?php $snippet_priority_value = ($edit_snippet && isset($edit_snippet->priority)) ? intval($edit_snippet->priority) : 10; ?>
                                        <input type="number" name="snippet_priority" id="snippet_priority" class="small-text" min="1" max="9999" step="1"
                                            value="<?php echo esc_attr($snippet_priority_value); ?>">
                                        <p class="description">Lower number runs first and appears first in the list.</p>
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
                            <link rel="stylesheet" href="<?php echo plugins_url('assets/codemirror-themes.css', __FILE__); ?>">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin: 0 0 10px 0;">
                                <h4 style="margin: 0; font-size: 14px; font-weight: 500; color: #1e1e1e;">Code</h4>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <label for="snippet_editor_theme" style="font-size: 12px; color: #666;">Theme:</label>
                                    <select id="snippet_editor_theme" style="width: auto; font-size: 12px; padding: 2px 6px; min-width: 0;">
                                        <option value="default">Default</option>
                                        <option value="monokai">Monokai</option>
                                        <option value="dracula">Dracula</option>
                                        <option value="material">Material</option>
                                        <option value="eclipse">Eclipse</option>
                                        <option value="ayu-mirage">Ayu Mirage</option>
                                        <option value="cobalt">Cobalt</option>
                                    </select>
                                </div>
                            </div>
                            <textarea name="snippet_code" id="snippet_code" rows="15" class="large-text code" required
                                placeholder="Enter your code here..."><?php echo $edit_snippet ? esc_textarea($edit_snippet->code) : ''; ?></textarea>
                            <?php if ($editing && $edit_snippet): ?>
                                <textarea id="snippet_original_code" style="display:none;"><?php echo esc_textarea($edit_snippet->code); ?></textarea>
                            <?php endif; ?>
                            <p class="description" id="snippet_code_help">
                                <span class="php-help">Enter PHP code. You can paste it with or without &lt;?php ?&gt; tags.</span>
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
            <?php endif; ?>

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
                .ofast-snippet-form input[type="number"],
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
                .ofast-snippet-form input[type="number"]:focus,
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

            <!-- Tier 2 Security Confirmation Modal -->
            <div id="ofast-tier2-modal" style="display:none;">
                <div class="ofast-tier2-overlay"></div>
                <div class="ofast-tier2-dialog">
                    <div class="ofast-tier2-header">
                        <span class="ofast-tier2-icon">⚠️</span>
                        <h3>Security Review Required</h3>
                    </div>
                    <div class="ofast-tier2-body">
                        <p class="ofast-tier2-message">This snippet uses functions that may have side effects:</p>
                        <div class="ofast-tier2-functions"></div>
                        <p class="ofast-tier2-note">These functions are commonly used in legitimate WordPress code. If this snippet worked in your previous plugin, it is likely safe.</p>
                    </div>
                    <div class="ofast-tier2-footer">
                        <button type="button" class="button ofast-tier2-cancel">Cancel</button>
                        <button type="button" class="button button-primary ofast-tier2-confirm">I Understand, Activate Anyway</button>
                    </div>
                </div>
            </div>

            <style>
                #ofast-tier2-modal .ofast-tier2-overlay {
                    position: fixed;
                    inset: 0;
                    background: rgba(0, 0, 0, 0.6);
                    backdrop-filter: blur(4px);
                    z-index: 100000;
                }
                #ofast-tier2-modal .ofast-tier2-dialog {
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: #fff;
                    border-radius: 16px;
                    box-shadow: 0 25px 60px rgba(0,0,0,0.3);
                    width: 480px;
                    max-width: 90vw;
                    max-height: 80vh;
                    overflow-y: auto;
                    z-index: 100001;
                    animation: ofast-modal-in 0.25s ease-out;
                }
                @keyframes ofast-modal-in {
                    from { opacity: 0; transform: translate(-50%, -48%); }
                    to { opacity: 1; transform: translate(-50%, -50%); }
                }
                #ofast-tier2-modal .ofast-tier2-header {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    padding: 24px 28px 0;
                }
                #ofast-tier2-modal .ofast-tier2-icon {
                    font-size: 28px;
                    line-height: 1;
                }
                #ofast-tier2-modal .ofast-tier2-header h3 {
                    margin: 0;
                    font-size: 18px;
                    font-weight: 600;
                    color: #1e293b;
                }
                #ofast-tier2-modal .ofast-tier2-body {
                    padding: 16px 28px 20px;
                }
                #ofast-tier2-modal .ofast-tier2-message {
                    color: #475569;
                    margin: 0 0 14px;
                    font-size: 14px;
                }
                #ofast-tier2-modal .ofast-tier2-functions {
                    background: #fef3c7;
                    border: 1px solid #fcd34d;
                    border-radius: 10px;
                    padding: 14px 18px;
                    margin-bottom: 14px;
                }
                #ofast-tier2-modal .ofast-tier2-func-item {
                    display: flex;
                    align-items: flex-start;
                    gap: 8px;
                    padding: 6px 0;
                    border-bottom: 1px solid rgba(252,211,77,0.4);
                    font-size: 13px;
                }
                #ofast-tier2-modal .ofast-tier2-func-item:last-child {
                    border-bottom: none;
                    padding-bottom: 0;
                }
                #ofast-tier2-modal .ofast-tier2-func-name {
                    font-family: 'Fira Code', 'Consolas', monospace;
                    font-weight: 600;
                    color: #92400e;
                    white-space: nowrap;
                    min-width: 100px;
                }
                #ofast-tier2-modal .ofast-tier2-func-reason {
                    color: #78350f;
                    line-height: 1.4;
                }
                #ofast-tier2-modal .ofast-tier2-note {
                    color: #64748b;
                    font-size: 13px;
                    margin: 0;
                    line-height: 1.5;
                    font-style: italic;
                }
                #ofast-tier2-modal .ofast-tier2-footer {
                    display: flex;
                    justify-content: flex-end;
                    gap: 10px;
                    padding: 0 28px 24px;
                }
                #ofast-tier2-modal .ofast-tier2-cancel {
                    border-radius: 8px !important;
                    padding: 8px 20px !important;
                    font-size: 13px !important;
                }
                #ofast-tier2-modal .ofast-tier2-confirm {
                    border-radius: 8px !important;
                    padding: 8px 24px !important;
                    font-size: 13px !important;
                    background: #f59e0b !important;
                    border-color: #d97706 !important;
                    color: #fff !important;
                }
                #ofast-tier2-modal .ofast-tier2-confirm:hover {
                    background: #d97706 !important;
                }
            </style>

            <h2><?php echo $is_trash_view ? 'Trash' : 'Saved Snippets'; ?> (<?php echo count($snippets); ?>)</h2>

            <?php if ($is_trash_view): ?>
                <?php $retention_days = get_option('ofast_snippets_trash_retention', 30); ?>
                <div style="background: #fff; border: 1px solid rgba(226, 232, 240, 0.6); border-radius: 16px; padding: 12px 18px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="color: #475569; font-size: 13px; font-weight: 500;">Auto-delete after:
                            <select id="ofast-trash-retention" style="margin-left: 5px;">
                                <option value="30" <?php selected($retention_days, 30); ?>>30 days</option>
                                <option value="60" <?php selected($retention_days, 60); ?>>60 days</option>
                                <option value="90" <?php selected($retention_days, 90); ?>>90 days</option>
                                <option value="0" <?php selected($retention_days, 0); ?>>Never</option>
                            </select>
                        </span>
                        <button type="button" class="button button-small" id="ofast-save-retention" style="color: #475569;">Save</button>
                    </div>
                    <button type="button" class="button button-small" id="ofast-empty-trash" style="color: #dc3545; border-color: #dc3545;">Empty Trash</button>
                </div>
            <?php endif; ?>

            <?php if (empty($snippets)): ?>
                <?php if ($is_trash_view): ?>
                    <p style="color: #999;">Trash is empty.</p>
                <?php else: ?>
                    <p style="color: #999;">No snippets yet. Add your first one above!</p>
                <?php endif; ?>
            <?php else: ?>
                <!-- Search and Bulk Actions Bar -->
                <div style="background: #fff; border-radius: 16px; padding: 25px 30px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); border: 1px solid rgba(226, 232, 240, 0.6);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <select id="bulk-action-select" class="regular-text" style="width: auto;">
                            <option value="">Bulk Actions</option>
                            <?php if ($is_trash_view): ?>
                                <option value="restore">Restore</option>
                                <option value="delete_permanently">Delete Permanently</option>
                            <?php else: ?>
                                <option value="activate">Activate</option>
                                <option value="deactivate">Deactivate</option>
                                <option value="delete">Move to Trash</option>
                            <?php endif; ?>
                        </select>
                        <button type="button" class="button" id="apply-bulk-action">Apply</button>

                        <!-- Category Filter -->
                        <?php
                        $category_where = "WHERE category != ''";
                        if ($trash_supported) {
                            $category_where = $is_trash_view
                                ? "WHERE status = 'trash' AND category != ''"
                                : "WHERE (status IS NULL OR status != 'trash') AND category != ''";
                        }
                        $all_categories = $wpdb->get_col("SELECT DISTINCT category FROM {$wpdb->prefix}ofast_snippets {$category_where} ORDER BY category");
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
                        <input type="text" id="snippet-search" placeholder="Search name, description, code, tags..." style="width: 300px; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; font-size: 13px; color: #1e293b; box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: all 0.2s ease; outline: none; min-height: 36px;">
                    </div>
                </div>

                <!-- Scrollable Table Container -->
                <div style="overflow-x: auto; max-width: 100%;">
                    <table class="wp-list-table widefat fixed striped" id="snippets-table" style="min-width: 1080px;">
                        <thead>
                            <tr>
                                <th style="width: 30px;"><input type="checkbox" id="select-all-snippets"></th>
                                <th style="width: 22px;">ID</th>
                                <th style="width: 140px;">Name</th>
                                <th style="width: 100px;">Category</th>
                                <th>Description</th>
                                <th style="width: 75px;">Language</th>
                                <th style="width: 85px;">Scope</th>
                                <th style="width: 65px;">Inject</th>
                                <th style="width: 65px;">Priority</th>

                                <th style="width: 60px;">Status</th>
                                <th style="width: 80px;">Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($snippets as $snippet):
                                // Language text labels
                                $lang_labels = array('php' => 'PHP', 'javascript' => 'JavaScript', 'css' => 'CSS', 'html' => 'HTML');
                                $lang = $this->core->normalize_snippet_language($snippet->language ?: 'php');
                                $lang_display = isset($lang_labels[$lang]) ? $lang_labels[$lang] : 'PHP';

                                // Scope text labels
                                $scope_labels = array('global' => 'Everywhere', 'admin' => 'Admin Only', 'frontend' => 'Frontend');
                                $scope = $snippet->scope ?: 'global';
                                $scope_display = isset($scope_labels[$scope]) ? $scope_labels[$scope] : 'Everywhere';

                                // Location text labels
                                $loc_labels = array('header' => 'Header', 'body' => 'Body', 'footer' => 'Footer');
                                $loc = isset($snippet->location) && !empty($snippet->location) ? $snippet->location : 'footer';
                                $loc_display = isset($loc_labels[$loc]) ? $loc_labels[$loc] : 'Footer';
                                $priority_value = isset($snippet->priority) ? intval($snippet->priority) : 10;

                                // Status and run once - button shows ACTION to take
                                $status_text = $snippet->active ? 'Deactivate' : 'Activate';
                                $run_once_text = (isset($snippet->run_once) && $snippet->run_once) ? ' (Once)' : '';

                                // Category
                                $snippet_category = isset($snippet->category) ? $snippet->category : '';

                                // Check for potential duplicates (only for PHP and inactive snippets)
                                $duplicate_warning = array('has_duplicate' => false, 'reasons' => array());
                                if (!$is_trash_view && $lang === 'php') {
                                    $duplicate_warning = $this->core->validator->get_potential_duplicates($snippet->id, $snippet->name, $snippet->code);
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
                                            <?php if ($is_trash_view): ?>
                                                <a href="#" class="ofast-snippet-restore" data-id="<?php echo $snippet->id; ?>">Restore</a> |
                                                <a href="#" class="ofast-snippet-permanently-delete" data-id="<?php echo $snippet->id; ?>" data-name="<?php echo esc_attr($snippet->name); ?>" style="color: #b32d2e;">Delete Permanently</a>
                                            <?php else: ?>
                                                <a href="?page=ofast-snippets&edit=<?php echo $snippet->id; ?>">Edit</a> |
                                                <a href="#" class="ofast-snippet-run-now" data-id="<?php echo $snippet->id; ?>" data-name="<?php echo esc_attr($snippet->name); ?>" data-language="<?php echo esc_attr($lang); ?>" style="color: #0f766e;">Run Now</a> |
                                                <a href="#" class="ofast-snippet-duplicate" data-id="<?php echo $snippet->id; ?>" data-name="<?php echo esc_attr($snippet->name); ?>">Duplicate</a> |
                                                <a href="#" class="ofast-snippet-delete" data-id="<?php echo $snippet->id; ?>" data-active="<?php echo $snippet->active; ?>" data-name="<?php echo esc_attr($snippet->name); ?>" style="color: #b32d2e;">Delete</a>
                                            <?php endif; ?>
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
                                    <td><span style="font-size: 12px; font-weight: 600;"><?php echo $priority_value; ?></span></td>

                                    <td>
                                        <?php if ($is_trash_view): ?>
                                            <span style="background: #fef2f2; color: #b91c1c; padding: 1px 5px; border-radius: 3px; font-size: 10px; line-height: 1.2; white-space: nowrap; display: inline-block;">Trashed</span>
                                        <?php else: ?>
                                            <label class="ofast-toggle-wrap ofast-snippet-toggle"
                                                data-id="<?php echo $snippet->id; ?>" data-active="<?php echo $snippet->active; ?>"
                                                data-has-duplicate="<?php echo $duplicate_warning['has_duplicate'] ? '1' : '0'; ?>"
                                                data-duplicate-reasons="<?php echo esc_attr(implode(' | ', $duplicate_warning['reasons'])); ?>">
                                                <input type="checkbox" <?php echo $snippet->active ? 'checked' : ''; ?>>
                                                <span class="ofast-toggle-slider"></span>
                                            </label>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size: 11px;"><?php echo date('M j, Y', strtotime($snippet->created_at)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                </div>
            <?php endif; ?>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Initialize CodeMirror on the code textarea
                var cmEditor = null;
                var $codeTextarea = $('#snippet_code');
                var isTrashView = <?php echo $is_trash_view ? 'true' : 'false'; ?>;

                // ── Ofast Custom Dropdown Init ──
                (function() {
                    $('.ofast-snippets-wrap select').each(function() {
                        var $sel = $(this);
                        // Skip if already wrapped or hidden inside CodeMirror
                        if ($sel.closest('.CodeMirror').length || $sel.data('ofast-dropdown')) return;
                        $sel.data('ofast-dropdown', true);

                        var selId = $sel.attr('id') || '';
                        var $wrap = $('<div class="ofast-dropdown"></div>');
                        var selectedText = $sel.find('option:selected').text() || $sel.find('option').first().text();
                        var $trigger = $('<div class="ofast-dropdown-trigger"><span class="ofast-dropdown-label">' + selectedText + '</span><span class="ofast-dropdown-arrow"></span></div>');
                        var $menu = $('<div class="ofast-dropdown-menu"></div>');

                        $sel.find('option').each(function() {
                            var $opt = $(this);
                            var isSelected = $opt.is(':selected');
                            var $item = $('<div class="ofast-dropdown-option' + (isSelected ? ' selected' : '') + '" data-value="' + $opt.val() + '">' +
                                '<span>' + $opt.text() + '</span>' +
                                (isSelected ? '<span class="ofast-dropdown-check">✓</span>' : '') +
                                '</div>');
                            $menu.append($item);
                        });

                        $sel.after($wrap);
                        $wrap.append($trigger).append($menu);
                        $sel.hide();

                        // Copy width hint
                        if ($sel.css('width') !== 'auto' && $sel.attr('style') && $sel.attr('style').indexOf('width') !== -1) {
                            $wrap.css('min-width', '0');
                            $trigger.css('min-width', '0');
                        }

                        // Toggle open
                        $trigger.on('click', function(e) {
                            e.stopPropagation();
                            var wasOpen = $wrap.hasClass('open');
                            $('.ofast-dropdown').removeClass('open');
                            if (!wasOpen) $wrap.addClass('open');
                        });

                        // Select option
                        $menu.on('click', '.ofast-dropdown-option', function() {
                            var val = $(this).attr('data-value');
                            // Set via both jQuery and native DOM for reliability
                            $sel.val(val);
                            $sel[0].value = val;
                            $sel.trigger('change');
                            $trigger.find('.ofast-dropdown-label').text($(this).find('span').first().text());
                            $menu.find('.ofast-dropdown-option').removeClass('selected').find('.ofast-dropdown-check').remove();
                            $(this).addClass('selected').append('<span class="ofast-dropdown-check">✓</span>');
                            $wrap.removeClass('open');
                        });
                    });

                    // Close on outside click
                    $(document).on('click', function() { $('.ofast-dropdown').removeClass('open'); });
                    // Close on Escape
                    $(document).on('keydown', function(e) { if (e.key === 'Escape') $('.ofast-dropdown').removeClass('open'); });

                    // Safety: sync all custom dropdowns to native selects on form submit
                    $('form').on('submit', function() {
                        $(this).find('.ofast-dropdown').each(function() {
                            var $dd = $(this);
                            var $sel = $dd.prev('select');
                            var $selected = $dd.find('.ofast-dropdown-option.selected');
                            if ($sel.length && $selected.length) {
                                var val = $selected.attr('data-value');
                                $sel[0].value = val;
                            }
                        });
                    });
                })();

                if ($codeTextarea.length && typeof wp !== 'undefined' && wp.codeEditor) {
                    // Get language-specific settings
                    var language = $('#snippet_language').val() || 'php';
                    var mimeTypes = {
                        'php': 'text/x-php',
                        'javascript': 'text/javascript',
                        'css': 'text/css',
                        'html': 'text/html'
                    };

                    // Initialize CodeMirror
                    cmEditor = wp.codeEditor.initialize($codeTextarea, {
                        codemirror: {
                            mode: mimeTypes[language] || 'application/x-httpd-php',
                            theme: localStorage.getItem('ofast_cm_theme') || 'default',
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

                    // Theme switcher
                    var $themeSelect = $('#snippet_editor_theme');
                    var savedTheme = localStorage.getItem('ofast_cm_theme') || 'default';
                    $themeSelect.val(savedTheme);

                    $themeSelect.on('change', function() {
                        var theme = $(this).val();
                        if (cmEditor && cmEditor.codemirror) {
                            cmEditor.codemirror.setOption('theme', theme);
                            localStorage.setItem('ofast_cm_theme', theme);
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

                // Code diff before save (only when editing existing snippets)
                var $originalCodeEl = $('#snippet_original_code');
                if ($originalCodeEl.length) {
                    var diffConfirmed = false;

                    $('.ofast-snippet-form').on('submit', function(e) {
                        if (diffConfirmed) return true; // Already confirmed, allow submit

                        // Sync CodeMirror to textarea before comparing
                        if (cmEditor && cmEditor.codemirror) {
                            cmEditor.codemirror.save();
                        }

                        var originalCode = $originalCodeEl.val();
                        var currentCode = $('#snippet_code').val();

                        // If no change, submit normally
                        if (originalCode === currentCode) return true;

                        e.preventDefault();

                        // Simple line-by-line diff
                        var origLines = originalCode.split('\n');
                        var currLines = currentCode.split('\n');
                        var added = 0, removed = 0, unchanged = 0;
                        var maxLen = Math.max(origLines.length, currLines.length);
                        var diffPreview = [];

                        for (var i = 0; i < maxLen; i++) {
                            var orig = i < origLines.length ? origLines[i] : undefined;
                            var curr = i < currLines.length ? currLines[i] : undefined;

                            if (orig === undefined) {
                                added++;
                                if (diffPreview.length < 15) diffPreview.push('+ ' + curr.substring(0, 80));
                            } else if (curr === undefined) {
                                removed++;
                                if (diffPreview.length < 15) diffPreview.push('- ' + orig.substring(0, 80));
                            } else if (orig !== curr) {
                                removed++;
                                added++;
                                if (diffPreview.length < 15) {
                                    diffPreview.push('- ' + orig.substring(0, 80));
                                    diffPreview.push('+ ' + curr.substring(0, 80));
                                }
                            } else {
                                unchanged++;
                            }
                        }

                        var summary = 'Code Changes Summary:\n\n';
                        summary += '  Lines added:     +' + added + '\n';
                        summary += '  Lines removed:   -' + removed + '\n';
                        summary += '  Lines unchanged:  ' + unchanged + '\n\n';

                        if (diffPreview.length > 0) {
                            summary += 'Preview (first changes):\n';
                            summary += diffPreview.join('\n');
                            if (added + removed > 15) {
                                summary += '\n... and more changes';
                            }
                        }

                        summary += '\n\nSave these changes?';

                        if (confirm(summary)) {
                            diffConfirmed = true;
                            $('.ofast-snippet-form').submit();
                        }
                    });
                }
                // Toggle snippet
                $(document).on('click', '.ofast-snippet-toggle', function(e) {
                    e.preventDefault();
                    var $toggle = $(this);
                    var $checkbox = $toggle.find('input[type="checkbox"]');
                    var id = $toggle.data('id');
                    var active = $toggle.data('active');
                    var hasDuplicate = Number($toggle.data('has-duplicate')) === 1;
                    var duplicateReasons = String($toggle.data('duplicate-reasons') || '');

                    // Client-side duplicate warning (user can choose to proceed; server does the real check).
                    if (active == 0 && hasDuplicate) {
                        if (!confirm('This snippet has potential duplicate issues:\n\n' + (duplicateReasons || 'Potential duplicate conflict detected.') + '\n\nDo you still want to try activating it?')) {
                            return;
                        }
                    }

                    $checkbox.prop('disabled', true);
                    // Optimistic: toggle immediately for instant feedback
                    $checkbox.prop('checked', active == 0);

                    $.post(ajaxurl, {
                        action: 'ofast_toggle_snippet',
                        nonce: '<?php echo wp_create_nonce('ofast_snippet_toggle'); ?>',
                        id: id,
                        active: active
                    }, function(response) {
                        if (response.success) {
                            // Check for Tier 2 confirmation
                            if (response.data.confirm_required) {
                                // Revert optimistic toggle
                                $checkbox.prop('checked', active == 1);
                                ofastShowTier2Modal(response.data, function() {
                                    // On confirm: re-send with force_activate
                                    $checkbox.prop('disabled', true);
                                    $checkbox.prop('checked', true);
                                    $.post(ajaxurl, {
                                        action: 'ofast_toggle_snippet',
                                        nonce: '<?php echo wp_create_nonce('ofast_snippet_toggle'); ?>',
                                        id: id,
                                        active: active,
                                        force_activate: 'true'
                                    }, function(r2) {
                                        if (r2.success) {
                                            var newActive = r2.data.active;
                                            $toggle.data('active', newActive);
                                            $checkbox.prop('checked', newActive == 1);
                                        } else {
                                            $checkbox.prop('checked', active == 1);
                                            alert('Error: ' + (r2.data || 'Unable to toggle snippet.'));
                                        }
                                    }).always(function() {
                                        $checkbox.prop('disabled', false);
                                    });
                                }, function() {
                                    // On cancel: re-enable checkbox
                                    $checkbox.prop('disabled', false);
                                });
                                return;
                            }
                            var newActive = response.data.active;
                            $toggle.data('active', newActive);
                            $checkbox.prop('checked', newActive == 1);

                            // Show dependency warning if present
                            if (response.data.dependency_warning) {
                                alert(response.data.dependency_warning + '\n\nThe snippet was deactivated, but the dependent snippet(s) listed above may stop working.');
                            }
                        } else {
                            // Revert checkbox on error
                            $checkbox.prop('checked', active == 1);
                            alert('Error: ' + (response.data || 'Unable to toggle snippet.'));
                        }
                    }).always(function() {
                        $checkbox.prop('disabled', false);
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
                        'WARNING: "' + name + '" is ACTIVE and currently running!\n\nIt will be moved to Trash and stopped immediately.\n\nMove this active snippet to Trash?' :
                        'Move "' + name + '" to Trash?';

                    if (!confirm(message)) {
                        return;
                    }

                    $.post(ajaxurl, {
                        action: 'ofast_delete_snippet',
                        nonce: '<?php echo wp_create_nonce('ofast_snippet_delete'); ?>',
                        id: id,
                        permanent: 'false'
                    }, function(response) {
                        if (response.success) {
                            $btn.closest('tr').fadeOut(function() {
                                $(this).remove();
                            });
                            setTimeout(function() {
                                location.reload();
                            }, 200);
                        } else {
                            alert('Error: ' + (response.data || 'Failed to move snippet to trash.'));
                        }
                    }).fail(function() {
                        alert('Request failed. Please try again.');
                    });
                });

                // Restore snippet from trash
                $(document).on('click', '.ofast-snippet-restore', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var id = $btn.data('id');

                    $btn.css({
                        'pointer-events': 'none',
                        'opacity': '0.6'
                    });

                    $.post(ajaxurl, {
                        action: 'ofast_restore_snippet',
                        nonce: '<?php echo wp_create_nonce('ofast_snippet_restore'); ?>',
                        id: id
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Error: ' + (response.data || 'Failed to restore snippet.'));
                        }
                    }).fail(function() {
                        alert('Request failed. Please try again.');
                    }).always(function() {
                        $btn.css({
                            'pointer-events': '',
                            'opacity': ''
                        });
                    });
                });

                // Permanently delete snippet from trash
                $(document).on('click', '.ofast-snippet-permanently-delete', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var id = $btn.data('id');
                    var name = $btn.data('name');

                    if (!confirm('Permanently delete "' + name + '"? This cannot be undone.')) {
                        return;
                    }

                    $btn.css({
                        'pointer-events': 'none',
                        'opacity': '0.6'
                    });

                    $.post(ajaxurl, {
                        action: 'ofast_delete_snippet',
                        nonce: '<?php echo wp_create_nonce('ofast_snippet_delete'); ?>',
                        id: id,
                        permanent: 'true'
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Error: ' + (response.data || 'Failed to delete snippet.'));
                        }
                    }).fail(function() {
                        alert('Request failed. Please try again.');
                    }).always(function() {
                        $btn.css({
                            'pointer-events': '',
                            'opacity': ''
                        });
                    });
                });

                // Run snippet immediately (PHP only)
                $(document).on('click', '.ofast-snippet-run-now', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var id = $btn.data('id');
                    var name = $btn.data('name');
                    var language = ($btn.data('language') || 'php').toString().toLowerCase();

                    if (language !== 'php') {
                        alert('Run Now is only available for PHP snippets.');
                        return;
                    }

                    if (!confirm('Run snippet "' + name + '" now?\n\nThis executes the PHP code immediately.')) {
                        return;
                    }

                    $btn.css({
                        'pointer-events': 'none',
                        'opacity': '0.6'
                    });

                    $.post(ajaxurl, {
                        action: 'ofast_run_snippet_now',
                        nonce: '<?php echo wp_create_nonce('ofast_snippet_run_now'); ?>',
                        id: id
                    }, function(response) {
                        if (response.success) {
                            var msg = response.data && response.data.message ? response.data.message : 'Snippet executed successfully.';
                            if (response.data && response.data.output) {
                                msg += '\n\nOutput:\n' + response.data.output;
                            }
                            alert(msg);
                        } else {
                            alert('Error: ' + (response.data || 'Failed to run snippet.'));
                        }
                    }).fail(function() {
                        alert('Request failed. Please try again.');
                    }).always(function() {
                        $btn.css({
                            'pointer-events': '',
                            'opacity': ''
                        });
                    });
                });

                // Duplicate snippet
                $(document).on('click', '.ofast-snippet-duplicate', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var id = $btn.data('id');
                    var name = $btn.data('name');

                    if (!confirm('Duplicate snippet "' + name + '"?')) {
                        return;
                    }

                    $btn.css({
                        'pointer-events': 'none',
                        'opacity': '0.6'
                    });

                    $.post(ajaxurl, {
                        action: 'ofast_duplicate_snippet',
                        nonce: '<?php echo wp_create_nonce('ofast_snippet_duplicate'); ?>',
                        id: id
                    }, function(response) {
                        if (response.success) {
                            if (response.data && response.data.edit_url) {
                                window.location.href = response.data.edit_url;
                                return;
                            }
                            location.reload();
                        } else {
                            alert('Error: ' + (response.data || 'Failed to duplicate snippet.'));
                        }
                    }).fail(function() {
                        alert('Request failed. Please try again.');
                    }).always(function() {
                        $btn.css({
                            'pointer-events': '',
                            'opacity': ''
                        });
                    });
                });

                // Inline title editing
                $(document).on('click', '.snippet-name-display', function() {
                    if (isTrashView) {
                        return;
                    }
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

                    var postData = {
                        action: 'ofast_export_snippets',
                        nonce: '<?php echo wp_create_nonce('ofast_export_snippets'); ?>',
                        ids: selectedIds
                    };

                    // If exporting active only, tell the server
                    if (exportType === 'json_active') {
                        postData.active_only = 1;
                    }

                    $.post(ajaxurl, postData, function(response) {
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
                            } else if (exportType === 'json' || exportType === 'json_active') {
                                // Export as JSON
                                content = JSON.stringify(response.data, null, 2);
                                var prefix = exportType === 'json_active' ? 'ofast-snippets-active-' : 'ofast-snippets-';
                                filename = prefix + date + '.json';
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

                    if (action === 'delete') {
                        confirmMsg = 'Move ' + ids.length + ' snippet(s) to Trash?';
                    } else if (action === 'restore') {
                        confirmMsg = 'Restore ' + ids.length + ' snippet(s) from Trash? Restored snippets stay inactive for safety.';
                    } else if (action === 'delete_permanently') {
                        confirmMsg = 'WARNING: Permanently delete ' + ids.length + ' snippet(s)? This cannot be undone.';
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
                            if (response.data && response.data.blocked && Number(response.data.blocked) > 0) {
                                var blockedDetails = Array.isArray(response.data.blocked_details) ? response.data.blocked_details : [];
                                if (blockedDetails.length > 0) {
                                    var maxLines = 8;
                                    var lines = blockedDetails.slice(0, maxLines).map(function(item) {
                                        var name = item && item.name ? String(item.name) : ('Snippet #' + (item && item.id ? item.id : '?'));
                                        var reason = item && item.reason ? String(item.reason) : 'Blocked by validation checks';
                                        return '- ' + name + ': ' + reason;
                                    });

                                    if (blockedDetails.length > maxLines) {
                                        lines.push('... and ' + (blockedDetails.length - maxLines) + ' more.');
                                    }

                                    alert(
                                        response.data.blocked + ' snippet(s) were not activated.\n\n' +
                                        lines.join('\n')
                                    );
                                } else {
                                    alert(response.data.blocked + ' snippet(s) were not activated due to validation or duplicate conflicts.');
                                }
                            }
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
                        var reviewCount = snippets.filter(s => s.security_tier === 2).length;

                        // XSS-safe HTML escaping for user-controlled import data
                        function escHtml(str) {
                            if (!str) return '';
                            var div = document.createElement('div');
                            div.appendChild(document.createTextNode(String(str)));
                            return div.innerHTML;
                        }

                        // Build modal HTML
                        var modalHtml = `
                            <div id="ofast-preview-import-modal" style="position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.8); z-index:100000; overflow-y:auto; padding:20px;">
                                <div style="max-width:900px; margin:30px auto; background:#fff; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
                                    <div style="padding:20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; background:#f8fafc; border-radius:12px 12px 0 0;">
                                        <div>
                                            <h2 style="margin:0; color:#1e293b;">Import from ${pluginName}</h2>
                                            <p style="margin:5px 0 0; color:#64748b; font-size:13px;">${snippets.length} snippets found, <strong style="color:#10b981;">${validCount} importable</strong>${unsafeCount > 0 ? ', <span style="color:#ef4444;">' + unsafeCount + ' blocked</span>' : ''}${reviewCount > 0 ? ', <span style="color:#f59e0b;">' + reviewCount + ' need review</span>' : ''}</p>
                                        </div>
                                        <button type="button" class="close-preview-modal" style="background:none; border:none; font-size:28px; cursor:pointer; color:#64748b; line-height:1;">&times;</button>
                                    </div>
                                    
                                    <div style="padding:15px 20px; background:#f1f5f9; border-bottom:1px solid #e5e7eb; display:flex; gap:15px; flex-wrap:wrap; font-size:12px; align-items:center;">
                                        <span><span style="background:#d1fae5; color:#065f46; padding:2px 6px; border-radius:3px; font-size:10px; font-weight:600;">SAFE</span> No issues</span>
                                        <span><span style="background:#fef3c7; color:#92400e; padding:2px 6px; border-radius:3px; font-size:10px; font-weight:600;">REVIEW</span> Has side-effect functions</span>
                                        <span><span style="background:#fee2e2; color:#991b1b; padding:2px 6px; border-radius:3px; font-size:10px; font-weight:600;">BLOCKED</span> Dangerous code</span>
                                        <span><span style="color:#10b981; font-size:14px;">●</span> Active in source</span>
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
                                                        <strong style="color:#1e293b;">${escHtml(s.name)}</strong>
                                                        ${s.status === 'duplicate' ? '<span style="background:#fecaca; color:#991b1b; padding:2px 6px; border-radius:3px; font-size:10px;">DUPLICATE</span>' : ''}
                                                        ${s.status === 'active' ? '<span style="background:#d1fae5; color:#065f46; padding:2px 6px; border-radius:3px; font-size:10px;">ACTIVE IN SOURCE</span>' : ''}
                                                        ${s.language !== 'php' ? '<span style="background:#e0e7ff; color:#3730a3; padding:2px 6px; border-radius:3px; font-size:10px;">' + escHtml(s.language).toUpperCase() + '</span>' : ''}
                                                        <span style="margin-left:auto;">${s.is_safe === false ? '<span style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:3px; font-size:10px; font-weight:600;">BLOCKED</span>' : (s.security_tier === 2 ? '<span style="background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:3px; font-size:10px; font-weight:600;">REVIEW</span>' : '<span style="background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:3px; font-size:10px; font-weight:600;">SAFE</span>')}</span>
                                                    </div>
                                                    ${s.description ? '<p style="margin:0 0 8px; color:#64748b; font-size:12px;">' + escHtml(s.description) + '</p>' : ''}
                                                    ${s.status === 'duplicate' ? '<p style="margin:0; color:#ef4444; font-size:11px;">Already exists in Ofast X (ID: ' + escHtml(s.existing_id) + ')</p>' : ''}
                                                    ${s.is_safe === false ? '<p style="margin:0 0 8px; color:#ea580c; font-size:11px; background:#fee2e2; padding:4px 8px; border-radius:4px;"><strong>Blocked:</strong> ' + escHtml(s.error_message || 'Contains dangerous functions') + '</p>' : ''}
                                                    ${s.security_tier === 2 ? '<p style="margin:0 0 8px; color:#92400e; font-size:11px; background:#fef3c7; padding:4px 8px; border-radius:4px;"><strong>Review:</strong> ' + escHtml(s.error_message || 'Uses functions with side effects') + ' — will be imported as inactive.</p>' : ''}
                                                    <details style="margin-top:8px;">
                                                        <summary style="cursor:pointer; color:#3b82f6; font-size:12px;">Preview Code</summary>
                                                        <pre style="background:#1e1e1e; color:#d4d4d4; padding:10px; border-radius:4px; font-size:11px; overflow-x:auto; margin-top:8px; max-height:150px; white-space:pre-wrap;">${escHtml(s.code_preview)}</pre>
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

                // Desktop toggle buttons for Import from Plugins / Library sections
                $('#ofast-toggle-import-section').on('click', function() {
                    var $section = $('#ofast-import-plugins-section');
                    if ($section.hasClass('ofast-section-visible')) {
                        $section.slideUp(300, function() { $section.removeClass('ofast-section-visible'); });
                    } else {
                        $section.addClass('ofast-section-visible').slideDown(300);
                        $('html, body').animate({ scrollTop: $section.offset().top - 50 }, 300);
                    }
                });

                $('#ofast-toggle-library-section').on('click', function() {
                    var $section = $('#ofast-library-section');
                    if ($section.hasClass('ofast-section-visible')) {
                        $section.slideUp(300, function() { $section.removeClass('ofast-section-visible'); });
                    } else {
                        $section.addClass('ofast-section-visible').slideDown(300);
                        $('html, body').animate({ scrollTop: $section.offset().top - 50 }, 300);
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
                                    <p style="margin:0 0 20px; color:#50575e;">"<strong>${escHtml(data.existing_name)}</strong>" already exists in your snippets.</p>
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

                // Empty Trash button
                $('#ofast-empty-trash').on('click', function() {
                    if (!confirm('Permanently delete ALL trashed snippets? This cannot be undone.')) {
                        return;
                    }
                    var $btn = $(this);
                    $btn.prop('disabled', true).text('Emptying...');

                    $.post(ajaxurl, {
                        action: 'ofast_empty_snippet_trash',
                        nonce: '<?php echo wp_create_nonce('ofast_empty_trash'); ?>'
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Error: ' + (response.data || 'Failed to empty trash.'));
                            $btn.prop('disabled', false).html('<span class="dashicons dashicons-trash" style="font-size: 14px; line-height: 1.8;"></span> Empty Trash');
                        }
                    }).fail(function() {
                        alert('Request failed. Please try again.');
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-trash" style="font-size: 14px; line-height: 1.8;"></span> Empty Trash');
                    });
                });

                // Save trash retention setting
                $('#ofast-save-retention').on('click', function() {
                    var $btn = $(this);
                    var days = $('#ofast-trash-retention').val();
                    $btn.prop('disabled', true).text('Saving...');

                    $.post(ajaxurl, {
                        action: 'ofast_save_trash_retention',
                        nonce: '<?php echo wp_create_nonce('ofast_save_retention'); ?>',
                        days: days
                    }, function(response) {
                        if (response.success) {
                            $btn.text('Saved!').css('color', '#10b981');
                            setTimeout(function() {
                                $btn.text('Save').css('color', '#475569').prop('disabled', false);
                            }, 1500);
                        } else {
                            alert('Error: ' + (response.data || 'Failed to save.'));
                            $btn.prop('disabled', false).text('Save');
                        }
                    }).fail(function() {
                        alert('Request failed.');
                        $btn.prop('disabled', false).text('Save');
                    });
                });
            });
        </script>
<?php
    }

}