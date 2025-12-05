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
        // Only load if module is enabled
        $enabled = get_option('ofastx_modules_enabled', array());
        if (empty($enabled['snippets'])) {
            return;
        }

        // Add dashboard widget
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));

        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // AJAX handlers
        add_action('wp_ajax_ofast_toggle_snippet', array($this, 'ajax_toggle_snippet'));
        add_action('wp_ajax_ofast_delete_snippet', array($this, 'ajax_delete_snippet'));
        add_action('wp_ajax_ofast_rename_snippet', array($this, 'ajax_rename_snippet'));

        // Execute active snippets
        add_action('init', array($this, 'execute_snippets'), 999);
    }

    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget()
    {
        wp_add_dashboard_widget(
            'ofast_snippets_widget',
            '📝 Code Snippets',
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

        $snippets = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 10");

        if (empty($snippets)) {
            echo '<p style="text-align: center; color: #999; padding: 20px;">No snippets yet. <a href="' . admin_url('admin.php?page=ofast-snippets') . '">Add your first snippet</a></p>';
            return;
        }

        echo '<div style="max-height: 300px; overflow-y: auto;">';
        echo '<table class="widefat" style="margin: 0;">';
        echo '<thead><tr><th>Snippet Name</th><th style="width: 80px; text-align: center;">Active</th></tr></thead>';
        echo '<tbody>';

        foreach ($snippets as $snippet) {
            $active_class = $snippet->active ? 'button-primary' : '';
            echo '<tr>';
            echo '<td><strong>' . esc_html($snippet->name) . '</strong></td>';
            echo '<td style="text-align: center;">';
            echo '<button class="button button-small ofast-snippet-toggle ' . $active_class . '" data-id="' . $snippet->id . '" data-active="' . $snippet->active . '">';
            echo $snippet->active ? '✓ ON' : '✗ OFF';
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
                            $btn.html(newActive ? '✓ ON' : '✗ OFF');
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
                echo '<div class="notice notice-warning is-dismissible"><p>';
                echo '<strong>⚠️ Code Saved But NOT Activated:</strong> ' . esc_html($validation);
                echo '<br><em>Fix the syntax error and try activating again.</em>';
                echo '</p></div>';
            }

            if ($id > 0) {
                // Update
                $wpdb->update($table, array(
                    'name' => $name,
                    'description' => $description,
                    'language' => $language,
                    'scope' => $scope,
                    'location' => $location,
                    'run_once' => $run_once,
                    'code' => $code,
                    'active' => $active
                ), array('id' => $id));

                if ($validation === true) {
                    echo '<div class="notice notice-success"><p>✅ Snippet updated and ' . ($active ? 'activated' : 'saved') . '!</p></div>';
                } else {
                    echo '<div class="notice notice-info"><p>💾 Snippet saved (inactive for safety)</p></div>';
                }
            } else {
                // Insert
                $wpdb->insert($table, array(
                    'name' => $name,
                    'description' => $description,
                    'language' => $language,
                    'scope' => $scope,
                    'location' => $location,
                    'run_once' => $run_once,
                    'code' => $code,
                    'active' => $active,
                    'created_at' => current_time('mysql')
                ));

                if ($validation === true) {
                    echo '<div class="notice notice-success"><p>✅ Snippet added and ' . ($active ? 'activated' : 'saved') . '!</p></div>';
                } else {
                    echo '<div class="notice notice-info"><p>💾 Snippet saved (inactive for safety)</p></div>';
                }
            }
        }

        // Get all snippets
        $snippets = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC");

        // Editing mode
        $editing = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $edit_snippet = $editing ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $editing)) : null;

    ?>
        <div class="wrap">
            <h1>📝 Code Snippets Manager</h1>
            <p>Add PHP code snippets that run on your WordPress site. Use with caution!</p>

            <div style="background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                <h2><?php echo $editing ? 'Edit Snippet' : 'Add New Snippet'; ?></h2>
                <form method="post">
                    <?php wp_nonce_field('ofast_snippet_save', '_wpnonce'); ?>

                    <?php if ($editing): ?>
                        <input type="hidden" name="snippet_id" value="<?php echo $editing; ?>">
                    <?php endif; ?>

                    <table class="form-table">
                        <tr>
                            <th><label for="snippet_name">Snippet Name</label></th>
                            <td>
                                <input type="text" name="snippet_name" id="snippet_name" class="regular-text" required
                                    value="<?php echo $edit_snippet ? esc_attr($edit_snippet->name) : ''; ?>"
                                    placeholder="e.g., Custom Header Code">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="snippet_description">Description</label></th>
                            <td>
                                <textarea name="snippet_description" id="snippet_description" rows="3" class="large-text"
                                    placeholder="Brief description of what this snippet does (optional)"><?php echo $edit_snippet ? esc_textarea($edit_snippet->description) : ''; ?></textarea>
                                <p class="description">Optional: Add a description to help you remember what this snippet does.</p>
                        </tr>
                        <tr>
                            <th><label for="snippet_language">Language</label></th>
                            <td>
                                <select name="snippet_language" id="snippet_language" class="regular-text">
                                    <option value="php" <?php selected($edit_snippet ? $edit_snippet->language : 'php', 'php'); ?>>🐘 PHP</option>
                                    <option value="javascript" <?php selected($edit_snippet ? $edit_snippet->language : '', 'javascript'); ?>>📜 JavaScript</option>
                                    <option value="css" <?php selected($edit_snippet ? $edit_snippet->language : '', 'css'); ?>>🎨 CSS</option>
                                    <option value="html" <?php selected($edit_snippet ? $edit_snippet->language : '', 'html'); ?>>📄 HTML</option>
                                </select>
                                <p class="description">Select the code language for this snippet.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="snippet_scope">Run Location</label></th>
                            <td>
                                <select name="snippet_scope" id="snippet_scope" class="regular-text">
                                    <option value="global" <?php selected($edit_snippet ? $edit_snippet->scope : 'global', 'global'); ?>>🌍 Run Everywhere</option>
                                    <option value="admin" <?php selected($edit_snippet ? $edit_snippet->scope : '', 'admin'); ?>>🔧 Admin Only</option>
                                    <option value="frontend" <?php selected($edit_snippet ? $edit_snippet->scope : '', 'frontend'); ?>>🖥️ Frontend Only</option>
                                </select>
                                <p class="description">Choose where this snippet should execute.</p>
                            </td>
                        </tr>
                        <tr class="snippet-location-row">
                            <th><label for="snippet_location">Injection Location</label></th>
                            <td>
                                <select name="snippet_location" id="snippet_location" class="regular-text">
                                    <option value="header" <?php selected($edit_snippet ? $edit_snippet->location : '', 'header'); ?>>📌 Header (before &lt;/head&gt;)</option>
                                    <option value="body" <?php selected($edit_snippet ? $edit_snippet->location : '', 'body'); ?>>📍 Body (after &lt;body&gt;)</option>
                                    <option value="footer" <?php selected($edit_snippet ? $edit_snippet->location : 'footer', 'footer'); ?>>📎 Footer (before &lt;/body&gt;)</option>
                                </select>
                                <p class="description">Where to inject JS/CSS/HTML code. (PHP always runs on init)</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="snippet_run_once">Run Once</label></th>
                            <td>
                                <label class="ofast-toggle-switch">
                                    <input type="checkbox" name="snippet_run_once" id="snippet_run_once" value="1" <?php checked($edit_snippet ? $edit_snippet->run_once : false); ?>>
                                    <span class="ofast-toggle-slider"></span>
                                    <span class="ofast-toggle-label">Execute only once, then auto-deactivate</span>
                                </label>
                                <p class="description">Snippet will run one time and then automatically deactivate itself.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="snippet_code">Code</label></th>
                            <td>
                                <textarea name="snippet_code" id="snippet_code" rows="10" class="large-text code" required
                                    placeholder="Enter your code here..."><?php echo $edit_snippet ? esc_textarea($edit_snippet->code) : ''; ?></textarea>
                                <p class="description" id="snippet_code_help">
                                    <span class="php-help">Enter PHP code without &lt;?php ?&gt; tags. Be careful - bad code can break your site!</span>
                                    <span class="js-help" style="display:none;">Enter JavaScript code. Will be wrapped in &lt;script&gt; tags automatically.</span>
                                    <span class="css-help" style="display:none;">Enter CSS code. Will be wrapped in &lt;style&gt; tags automatically.</span>
                                    <span class="html-help" style="display:none;">Enter HTML code. Will be output directly on the page.</span>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th>Activation</th>
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

                    <p class="submit">
                        <button type="submit" name="ofast_save_snippet" class="button button-primary">
                            💾 <?php echo $editing ? 'Update Snippet' : 'Add Snippet'; ?>
                        </button>
                        <?php if ($editing): ?>
                            <a href="<?php echo admin_url('admin.php?page=ofast-snippets'); ?>" class="button">Cancel</a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>

            <style>
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
                    background-color: #2271b1;
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
                    border: 1px solid #2271b1;
                    border-radius: 3px;
                }
            </style>

            <h2>Saved Snippets (<?php echo count($snippets); ?>)</h2>

            <?php if (empty($snippets)): ?>
                <p style="color: #999;">No snippets yet. Add your first one above!</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 50px;">ID</th>
                            <th style="width: 180px;">Name</th>
                            <th>Description</th>
                            <th style="width: 70px;">Lang</th>
                            <th style="width: 80px;">Scope</th>
                            <th style="width: 70px;">Inject</th>
                            <th style="width: 80px;">Status</th>
                            <th style="width: 100px;">Created</th>
                            <th style="width: 130px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($snippets as $snippet):
                            // Language badges
                            $lang_badges = array(
                                'php' => '🐘',
                                'javascript' => '📜',
                                'css' => '🎨',
                                'html' => '📄'
                            );
                            $lang_display = isset($lang_badges[$snippet->language]) ? $lang_badges[$snippet->language] : '🐘';

                            // Scope badges
                            $scope_badges = array(
                                'global' => '🌍',
                                'admin' => '🔧',
                                'frontend' => '🖥️'
                            );
                            $scope_display = isset($scope_badges[$snippet->scope]) ? $scope_badges[$snippet->scope] : '🌍';

                            // Location badges
                            $loc_badges = array(
                                'header' => '📌',
                                'body' => '📍',
                                'footer' => '📎'
                            );
                            $loc = !empty($snippet->location) ? $snippet->location : 'footer';
                            $loc_display = isset($loc_badges[$loc]) ? $loc_badges[$loc] : '📎';

                            // Run once indicator
                            $run_once_badge = !empty($snippet->run_once) ? ' 🔂' : '';
                        ?>
                            <tr>
                                <td><?php echo $snippet->id; ?></td>
                                <td>
                                    <span class="snippet-name-display" data-id="<?php echo $snippet->id; ?>">
                                        <strong><?php echo esc_html($snippet->name); ?></strong>
                                        <span class="edit-icon" title="Click to edit name">✏️</span>
                                    </span>
                                    <input type="text" class="snippet-name-edit" data-id="<?php echo $snippet->id; ?>" value="<?php echo esc_attr($snippet->name); ?>" style="display:none;">
                                </td>
                                <td>
                                    <?php
                                    if (!empty($snippet->description)) {
                                        echo '<span style="color: #666;">' . esc_html(wp_trim_words($snippet->description, 10, '...')) . '</span>';
                                    } else {
                                        echo '<span style="color: #999; font-style: italic;">No description</span>';
                                    }
                                    ?>
                                </td>
                                <td><span style="font-size: 14px;" title="<?php echo esc_attr($snippet->language ?: 'php'); ?>"><?php echo $lang_display; ?></span></td>
                                <td><span style="font-size: 14px;" title="<?php echo esc_attr($snippet->scope ?: 'global'); ?>"><?php echo $scope_display; ?></span></td>
                                <td><span style="font-size: 14px;" title="<?php echo esc_attr($loc); ?>"><?php echo $loc_display . $run_once_badge; ?></span></td>
                                <td>
                                    <button class="button button-small ofast-snippet-toggle <?php echo $snippet->active ? 'button-primary' : ''; ?>"
                                        data-id="<?php echo $snippet->id; ?>" data-active="<?php echo $snippet->active; ?>">
                                        <?php echo $snippet->active ? '✓' : '✗'; ?>
                                    </button>
                                </td>
                                <td style="font-size: 11px;"><?php echo date('M j', strtotime($snippet->created_at)); ?></td>
                                <td>
                                    <a href="?page=ofast-snippets&edit=<?php echo $snippet->id; ?>" class="button button-small">✏️ Edit</a>
                                    <button class="button button-small button-link-delete ofast-snippet-delete"
                                        data-id="<?php echo $snippet->id; ?>"
                                        data-active="<?php echo $snippet->active; ?>"
                                        data-name="<?php echo esc_attr($snippet->name); ?>">
                                        🗑️ Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <script>
            jQuery(document).ready(function($) {
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
                            $btn.html(newActive ? 'Activated' : 'Deactivated');
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
                        '⚠️ WARNING: "' + name + '" is ACTIVE and currently running!\n\nDeleting it will stop it from running.\n\nAre you sure you want to delete this active snippet?' :
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
                        }
                    });
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

                // Language selector - toggle help text
                $('#snippet_language').on('change', function() {
                    var lang = $(this).val();
                    $('.php-help, .js-help, .css-help, .html-help').hide();
                    $('.' + lang.replace('javascript', 'js') + '-help').show();
                }).trigger('change');
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

        $id = intval($_POST['id']);
        $current_active = intval($_POST['active']);
        $new_active = $current_active ? 0 : 1;

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';

        // If turning ON, validate first (only for PHP snippets)
        if ($new_active == 1) {
            $snippet = $wpdb->get_row($wpdb->prepare("SELECT code, language FROM $table WHERE id = %d", $id));
            if ($snippet && ($snippet->language === 'php' || empty($snippet->language))) {
                $validation = $this->validate_php_code($snippet->code);
                if ($validation !== true) {
                    wp_send_json_error('Cannot activate: ' . $validation);
                    return;
                }
            }
        }

        $wpdb->update(
            $table,
            array('active' => $new_active),
            array('id' => $id)
        );

        wp_send_json_success(array('active' => $new_active));
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

        $id = intval($_POST['id']);

        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'ofast_snippets',
            array('id' => $id)
        );

        wp_send_json_success();
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

        $id = intval($_POST['id']);
        $name = sanitize_text_field($_POST['name']);

        if (empty($name)) {
            wp_send_json_error('Name cannot be empty');
            return;
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ofast_snippets',
            array('name' => $name),
            array('id' => $id)
        );

        wp_send_json_success(array('name' => $name));
    }

    /**
     * Execute active snippets
     */
    public function execute_snippets()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';

        // Get all active snippets with all relevant fields
        $snippets = $wpdb->get_results("SELECT id, code, language, scope, location, run_once, executed_at FROM $table WHERE active = 1");

        foreach ($snippets as $snippet) {
            // Check scope
            $should_run = $this->should_snippet_run($snippet->scope);
            if (!$should_run) {
                continue;
            }

            // Check run_once - if already executed, skip and deactivate
            if ($snippet->run_once && !empty($snippet->executed_at)) {
                $wpdb->update($table, array('active' => 0), array('id' => $snippet->id));
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
     * Execute PHP snippet
     */
    private function execute_php_snippet($code, $snippet_id = 0, $run_once = false)
    {
        try {
            eval($code);
            $this->mark_snippet_executed($snippet_id, $run_once);
        } catch (Exception $e) {
            error_log('Ofast PHP Snippet Error: ' . $e->getMessage());
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

        if (empty($code)) {
            return 'Code cannot be empty';
        }

        // Check for opening PHP tags (not allowed)
        if (strpos($code, '<?php') !== false || strpos($code, '<?') !== false) {
            return 'Do not include <?php tags in your code';
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
}
