<?php

/**
 * Ofast X - User Role Manager Module
 * Allows assigning multiple roles to WordPress users + Advanced Capability Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_User_Roles
{
    /**
     * Initialize module
     */
    public function init()
    {
        // Add role checkboxes to user profile
        add_action('show_user_profile', array($this, 'render_role_checkboxes'));
        add_action('edit_user_profile', array($this, 'render_role_checkboxes'));

        // Add quick capabilities link on user profile
        add_action('show_user_profile', array($this, 'render_quick_capabilities_link'));
        add_action('edit_user_profile', array($this, 'render_quick_capabilities_link'));

        // Save roles on profile update
        add_action('personal_options_update', array($this, 'save_user_roles'));
        add_action('edit_user_profile_update', array($this, 'save_user_roles'));

        // Add roles column to users list
        add_filter('manage_users_columns', array($this, 'add_roles_column'));
        add_filter('manage_users_custom_column', array($this, 'render_roles_column'), 10, 3);
        add_action('admin_head', array($this, 'users_table_css'));

        // Add admin menu for capabilities manager
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_capabilities_save'));
        add_action('admin_init', array($this, 'handle_role_create'));
        add_action('admin_init', array($this, 'handle_role_delete'));
        
        // AJAX handler for live capability toggle
        add_action('wp_ajax_ofast_toggle_capability', array($this, 'ajax_toggle_capability'));
    }

    /**
     * Add admin menu under Users
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'users.php',
            'Role Capabilities',
            'Capabilities',
            'manage_options',
            'ofast-role-capabilities',
            array($this, 'render_capabilities_page')
        );
    }

    /**
     * Render role checkboxes on user profile
     */
    public function render_role_checkboxes($user)
    {
        if (!current_user_can('promote_users') && !current_user_can('edit_users')) {
            return;
        }

        // Don't allow users to change their own role (unless admin)
        if ($user->ID === get_current_user_id() && !current_user_can('administrator')) {
            return;
        }

        $all_roles = wp_roles()->roles;
        $user_roles = $user->roles;

?>
        <h3>User Roles (Multiple Selection)</h3>
        <p class="description">Assign one or more roles to this user.</p>

        <table class="form-table" role="presentation">
            <tr>
                <th><label>Assigned Roles</label></th>
                <td>
                    <div style="display: flex; flex-wrap: wrap; gap: 15px;">
                        <?php foreach ($all_roles as $role_slug => $role_data): ?>
                            <label style="display: flex; align-items: center; gap: 5px; padding: 8px 12px; background: #f0f0f1; border-radius: 5px; cursor: pointer;">
                                <input type="checkbox"
                                    name="ofast_user_roles[]"
                                    value="<?php echo esc_attr($role_slug); ?>"
                                    <?php checked(in_array($role_slug, $user_roles)); ?>>
                                <?php echo esc_html(translate_user_role($role_data['name'])); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="description" style="margin-top: 10px;">
                        At least one role is required. If no roles are selected, the user will be assigned "Subscriber".
                    </p>
                </td>
            </tr>
        </table>

        <?php wp_nonce_field('ofast_save_user_roles', 'ofast_roles_nonce'); ?>
<?php
    }

    /**
     * Render quick link to capabilities manager
     */
    public function render_quick_capabilities_link($user)
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $user_roles = $user->roles;
        if (empty($user_roles)) {
            return;
        }

        $first_role = $user_roles[0];
?>
        <h3>Role Capabilities</h3>
        <table class="form-table" role="presentation">
            <tr>
                <th><label>Manage Capabilities</label></th>
                <td>
                    <a href="<?php echo admin_url('users.php?page=ofast-role-capabilities&role=' . urlencode($first_role)); ?>" 
                       class="button button-secondary">
                        Edit <?php echo esc_html(translate_user_role(ucfirst($first_role))); ?> Capabilities
                    </a>
                    <p class="description" style="margin-top: 10px;">
                        Fine-tune what this role can and cannot do in WordPress.
                    </p>
                </td>
            </tr>
        </table>
<?php
    }

    /**
     * Save user roles
     */
    public function save_user_roles($user_id)
    {
        // Security checks
        if (!current_user_can('promote_users') && !current_user_can('edit_users')) {
            return;
        }

        if (!isset($_POST['ofast_roles_nonce']) || !wp_verify_nonce($_POST['ofast_roles_nonce'], 'ofast_save_user_roles')) {
            return;
        }

        // Don't allow users to change their own role (unless admin)
        if ($user_id === get_current_user_id() && !current_user_can('administrator')) {
            return;
        }

        // Get selected roles
        $new_roles = isset($_POST['ofast_user_roles']) ? array_map('sanitize_text_field', $_POST['ofast_user_roles']) : array();

        // Ensure at least one role
        if (empty($new_roles)) {
            $new_roles = array('subscriber');
        }

        // Validate roles exist
        $all_roles = array_keys(wp_roles()->roles);
        $new_roles = array_intersect($new_roles, $all_roles);

        // Get user object
        $user = new WP_User($user_id);

        // Remove all current roles
        foreach ($user->roles as $role) {
            $user->remove_role($role);
        }

        // Add new roles
        foreach ($new_roles as $role) {
            $user->add_role($role);
        }
    }

    /**
     * Add ID and roles columns to users list
     */
    public function add_roles_column($columns)
    {
        // Build new columns with ID first
        $new_columns = array();
        
        // Add cb (checkbox) first if it exists
        if (isset($columns['cb'])) {
            $new_columns['cb'] = $columns['cb'];
            unset($columns['cb']);
        }
        
        // Add ID as first visible column
        $new_columns['user_id'] = 'ID';
        
        // Add remaining columns
        foreach ($columns as $key => $value) {
            if ($key === 'role') {
                // Replace default role with our enhanced roles column
                $new_columns['ofast_roles'] = 'Roles';
            } else {
                $new_columns[$key] = $value;
            }
        }

        return $new_columns;
    }

    /**
     * Add CSS to shrink ID column width on users table
     */
    public function users_table_css()
    {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'users') {
            echo '<style>
                .users .column-user_id { width: 30px; text-align: center; }
                .users th.column-user_id { text-align: center; }
            </style>';
        }
    }

    /**
     * Render ID and roles column content
     */
    public function render_roles_column($output, $column_name, $user_id)
    {
        // Handle User ID column
        if ($column_name === 'user_id') {
            return '<code style="background: #f0f0f1; color: #1e293b; padding: 2px 6px; border-radius: 3px; font-size: 12px; font-weight: 600;">' . esc_html($user_id) . '</code>';
        }

        // Handle Roles column
        if ($column_name !== 'ofast_roles') {
            return $output;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return $output;
        }

        $roles = $user->roles;
        $role_names = array();

        foreach ($roles as $role) {
            $role_obj = get_role($role);
            if ($role_obj) {
                $role_names[] = '<span style="background: #e7f3ff; color: #0073aa; padding: 2px 8px; border-radius: 3px; font-size: 11px; display: inline-block; margin: 2px;">' . esc_html(translate_user_role(ucfirst($role))) . '</span>';
            }
        }

        return implode(' ', $role_names);
    }

    /**
     * Handle capabilities save
     */
    public function handle_capabilities_save()
    {
        if (!isset($_POST['ofast_save_capabilities'])) {
            return;
        }

        check_admin_referer('ofast_capabilities_save', '_wpnonce');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $role_slug = sanitize_text_field($_POST['role']);
        $role = get_role($role_slug);

        if (!$role) {
            wp_die('Invalid role');
        }

        // Backup original capabilities on first edit
        $backup_key = 'ofast_role_backup_' . $role_slug;
        if (!get_option($backup_key)) {
            update_option($backup_key, $role->capabilities);
        }

        // Get all possible capabilities
        $all_caps = $this->get_all_capabilities();
        $selected_caps = isset($_POST['capabilities']) ? array_map('sanitize_text_field', $_POST['capabilities']) : array();

        // Update role capabilities
        foreach ($all_caps as $cap => $label) {
            if (in_array($cap, $selected_caps)) {
                $role->add_cap($cap);
            } else {
                $role->remove_cap($cap);
            }
        }

        wp_redirect(add_query_arg(array('page' => 'ofast-role-capabilities', 'role' => $role_slug, 'updated' => '1'), admin_url('users.php')));
        exit;
    }

    /**
     * Handle AJAX capability toggle
     */
    public function ajax_toggle_capability()
    {
        check_ajax_referer('ofast_toggle_cap_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $role_slug = sanitize_text_field($_POST['role']);
        $capability = sanitize_text_field($_POST['capability']);
        $enabled = filter_var($_POST['enabled'], FILTER_VALIDATE_BOOLEAN);

        $role = get_role($role_slug);
        if (!$role) {
            wp_send_json_error('Invalid role');
        }

        // Backup on first edit
        $backup_key = 'ofast_role_backup_' . $role_slug;
        if (!get_option($backup_key)) {
            update_option($backup_key, $role->capabilities);
        }

        if ($enabled) {
            $role->add_cap($capability);
        } else {
            $role->remove_cap($capability);
        }

        wp_send_json_success(array(
            'message' => 'Capability updated',
            'capability' => $capability,
            'enabled' => $enabled
        ));
    }

    /**
     * Render capabilities management page
     */
    public function render_capabilities_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $all_roles = wp_roles()->roles;
        $selected_role = isset($_GET['role']) ? sanitize_text_field($_GET['role']) : 'editor';
        
        if (!isset($all_roles[$selected_role])) {
            $selected_role = 'editor';
        }

        $role = get_role($selected_role);
        $role_caps = $role->capabilities;
        $all_caps = $this->get_all_capabilities();
        $backup_exists = get_option('ofast_role_backup_' . $selected_role) !== false;

        // Group capabilities
        $grouped_caps = $this->group_capabilities($all_caps);

?>
        <div class="wrap ofast-capabilities-wrap">
            <!-- Modern Header -->
            <div class="ofast-page-header">
                <div class="ofast-header-content">
                    <div class="ofast-header-icon">
                        <span class="dashicons dashicons-admin-network"></span>
                    </div>
                    <div class="ofast-header-text">
                        <h1>Role Capabilities Manager</h1>
                        <p>Fine-tune permissions for each WordPress role - unlock full admin control</p>
                    </div>
                </div>
            </div>

            <?php if (isset($_GET['updated'])): ?>
                <?php echo Ofast_X_Toast::render('Capabilities updated successfully!', 'success'); ?>
            <?php endif; ?>

            <?php if (isset($_GET['restored'])): ?>
                <?php echo Ofast_X_Toast::render('Role capabilities restored to WordPress defaults!', 'info'); ?>
            <?php endif; ?>

            <?php if (isset($_GET['created'])): ?>
                <?php echo Ofast_X_Toast::render('New role created successfully!', 'success'); ?>
            <?php endif; ?>

            <?php if (isset($_GET['deleted'])): ?>
                <?php echo Ofast_X_Toast::render('Role deleted successfully!', 'success'); ?>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
                <?php
                $error_messages = array(
                    'empty_name' => 'Please enter a role name.',
                    'role_exists' => 'A role with this name already exists.',
                    'create_failed' => 'Failed to create role. Please try again.',
                    'core_role' => 'Core WordPress roles cannot be deleted.',
                    'role_in_use' => 'Cannot delete role - users are still assigned to it.',
                    'no_role' => 'No role specified.'
                );
                $error_key = sanitize_text_field($_GET['error']);
                $error_msg = isset($error_messages[$error_key]) ? $error_messages[$error_key] : 'An error occurred.';
                echo Ofast_X_Toast::render($error_msg, 'error');
                ?>
            <?php endif; ?>

            <!-- Create New Role Section -->
            <div class="ofast-card" style="margin-bottom: 20px;">
                <div class="ofast-card-header" style="cursor: pointer;" onclick="document.getElementById('create-role-form').classList.toggle('hidden');">
                    <span class="dashicons dashicons-plus-alt2" style="color: #667eea; font-size: 20px;"></span>
                    <h2 style="flex: 1;">Create New Role</h2>
                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                </div>
                <div id="create-role-form" class="ofast-card-body hidden">
                    <form method="post" action="">
                        <?php wp_nonce_field('ofast_create_role', '_wpnonce_create'); ?>
                        <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-end;">
                            <div style="flex: 1; min-width: 200px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #374151;">Role Name</label>
                                <input type="text" name="new_role_name" placeholder="e.g. Content Manager" required
                                       style="width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                            </div>
                            <div style="flex: 1; min-width: 200px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #374151;">Clone From (Optional)</label>
                                <select name="clone_from" style="width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                                    <option value="">-- Start Empty --</option>
                                    <?php foreach ($all_roles as $slug => $data): ?>
                                        <option value="<?php echo esc_attr($slug); ?>">
                                            <?php echo esc_html(translate_user_role($data['name'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <button type="submit" name="ofast_create_role" class="ofast-btn-secondary">
                                    Create Role
                                </button>
                            </div>
                        </div>
                        <p class="description" style="margin-top: 12px;">
                            The role slug will be auto-generated from the name. Clone from an existing role to copy its capabilities.
                        </p>
                    </form>
                </div>
            </div>

            <style>.hidden { display: none !important; }</style>

            <!-- Role Selector + Actions Bar -->
            <div class="ofast-toolbar">
                <div class="ofast-role-selector">
                    <label for="role-select">
                        <span class="dashicons dashicons-businessman"></span>
                        Select Role:
                    </label>
                    <select id="role-select" onchange="window.location.href='<?php echo admin_url('users.php?page=ofast-role-capabilities&role='); ?>' + this.value">
                        <?php foreach ($all_roles as $slug => $data): ?>
                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($selected_role, $slug); ?>>
                                <?php echo esc_html(translate_user_role($data['name'])); ?>
                                <?php if ($this->is_deletable_role($slug)): ?>(Custom)<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ofast-toolbar-actions">
                    <button type="button" id="select-all-caps" class="ofast-btn-secondary">
                        Select All
                    </button>
                    <button type="button" id="deselect-all-caps" class="ofast-btn-secondary">
                        Deselect All
                    </button>
                    <?php if ($backup_exists): ?>
                        <a href="<?php echo wp_nonce_url(admin_url('users.php?page=ofast-role-capabilities&role=' . $selected_role . '&action=restore'), 'ofast_restore_role'); ?>" 
                           class="ofast-btn-secondary" 
                           onclick="return confirm('Restore <?php echo esc_js(translate_user_role($all_roles[$selected_role]['name'])); ?> to WordPress defaults?');">
                            Reset to Defaults
                        </a>
                    <?php endif; ?>
                    <?php if ($this->is_deletable_role($selected_role)): ?>
                        <a href="<?php echo wp_nonce_url(admin_url('users.php?page=ofast-role-capabilities&role=' . $selected_role . '&action=delete_role'), 'ofast_delete_role'); ?>" 
                           class="ofast-btn-danger" 
                           onclick="return confirm('Delete the role \'<?php echo esc_js(translate_user_role($all_roles[$selected_role]['name'])); ?>\'? This cannot be undone.');">
                            Delete Role
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stats Bar -->
            <div class="ofast-stats-bar">
                <div class="ofast-stat-item">
                    <span class="ofast-stat-label">Total Capabilities:</span>
                    <span class="ofast-stat-value" id="total-caps"><?php echo count($all_caps); ?></span>
                </div>
                <div class="ofast-stat-item">
                    <span class="ofast-stat-label">Enabled:</span>
                    <span class="ofast-stat-value ofast-stat-enabled" id="enabled-caps"><?php echo count(array_filter($role_caps)); ?></span>
                </div>
                <div class="ofast-stat-item">
                    <span class="ofast-stat-label">Disabled:</span>
                    <span class="ofast-stat-value ofast-stat-disabled" id="disabled-caps"><?php echo count($all_caps) - count(array_filter($role_caps)); ?></span>
                </div>
            </div>

            <form method="post" action="" id="capabilities-form">
                <?php wp_nonce_field('ofast_capabilities_save', '_wpnonce'); ?>
                <input type="hidden" name="role" value="<?php echo esc_attr($selected_role); ?>">

                <?php foreach ($grouped_caps as $group => $caps): ?>
                    <div class="ofast-card ofast-cap-group">
                        <div class="ofast-card-header">
                            <span class="ofast-group-icon"><?php echo $this->get_group_icon($group); ?></span>
                            <h2><?php echo esc_html($group); ?></h2>
                            <span class="ofast-cap-count"><?php echo count($caps); ?> capabilities</span>
                        </div>
                        <div class="ofast-card-body">
                            <div class="ofast-caps-grid">
                                <?php foreach ($caps as $cap => $label): ?>
                                    <?php $is_enabled = !empty($role_caps[$cap]); ?>
                                    <div class="ofast-cap-item" data-capability="<?php echo esc_attr($cap); ?>">
                                        <label class="ofast-cap-label">
                                            <div class="ofast-cap-toggle">
                                                <input type="checkbox" 
                                                       name="capabilities[]" 
                                                       value="<?php echo esc_attr($cap); ?>"
                                                       <?php checked($is_enabled); ?>
                                                       data-role="<?php echo esc_attr($selected_role); ?>"
                                                       class="ofast-cap-checkbox">
                                                <span class="ofast-toggle-slider"></span>
                                            </div>
                                            <div class="ofast-cap-info">
                                                <span class="ofast-cap-name"><?php echo esc_html($label); ?></span>
                                                <code class="ofast-cap-code"><?php echo esc_html($cap); ?></code>
                                            </div>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="ofast-form-actions" style="position: sticky; bottom: 0; background: #fff; padding: 20px; border-top: 2px solid #e2e8f0; box-shadow: 0 -4px 20px rgba(0,0,0,0.08); margin: 0 -20px -20px;">
                    <button type="submit" name="ofast_save_capabilities" class="ofast-btn-primary ofast-btn-large">
                        <span class="dashicons dashicons-saved"></span>
                        Save All Changes
                    </button>
                    <span class="ofast-save-note">Changes take effect immediately after saving</span>
                </div>
            </form>
        </div>

        <style>
            .ofast-capabilities-wrap { max-width: 1400px; margin: 20px auto; padding: 0 20px; }

            /* Header */
            .ofast-page-header { background: #ffffff; border-radius: 16px; padding: 30px; margin-bottom: 30px; border: 1px solid #e2e8f0; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
            .ofast-header-content { display: flex; align-items: center; gap: 20px; }
            .ofast-header-icon { width: 60px; height: 60px; background: #ffffff; border-radius: 16px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(102, 126, 234, 0.2); border: 1px solid #e2e8f0; color: #667eea; }
            .ofast-header-icon .dashicons { font-size: 28px; width: 28px; height: 28px; }
            .ofast-header-text h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1e293b; }
            .ofast-header-text p { margin: 5px 0 0; color: #64748b; font-size: 15px; }

            /* Toolbar */
            .ofast-toolbar { background: #fff; padding: 20px 25px; margin-bottom: 20px; border-radius: 12px; border: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
            .ofast-role-selector { display: flex; align-items: center; gap: 10px; }
            .ofast-role-selector label { display: flex; align-items: center; gap: 8px; font-weight: 600; color: #374151; }
            .ofast-role-selector .dashicons { color: #667eea; }
            .ofast-role-selector select { padding: 8px 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; font-weight: 500; min-width: 180px; }
            .ofast-toolbar-actions { display: flex; gap: 10px; flex-wrap: wrap; }
            .ofast-toolbar-actions .button { display: inline-flex; align-items: center; gap: 6px; }

            /* Stats Bar */
            .ofast-stats-bar { display: flex; gap: 20px; padding: 20px 25px; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-radius: 12px; margin-bottom: 25px; border: 1px solid #e2e8f0; }
            .ofast-stat-item { display: flex; align-items: center; gap: 8px; }
            .ofast-stat-label { font-size: 13px; color: #64748b; font-weight: 500; }
            .ofast-stat-value { font-size: 18px; font-weight: 700; color: #1e293b; }
            .ofast-stat-enabled { color: #10b981; }
            .ofast-stat-disabled { color: #ef4444; }

            /* Cards */
            .ofast-card { background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); overflow: hidden; border: 1px solid rgba(0,0,0,0.05); margin-bottom: 20px; }
            .ofast-card-header { display: flex; align-items: center; gap: 12px; padding: 20px 25px; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-bottom: 1px solid #e2e8f0; }
            .ofast-group-icon { font-size: 20px; }
            .ofast-card-header h2 { margin: 0; font-size: 16px; font-weight: 600; color: #1e293b; flex: 1; }
            .ofast-cap-count { font-size: 12px; color: #64748b; background: #e2e8f0; padding: 4px 10px; border-radius: 20px; font-weight: 600; }
            .ofast-card-body { padding: 25px; }

            /* Capabilities Grid */
            .ofast-caps-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px; }
            @media (max-width: 768px) { .ofast-caps-grid { grid-template-columns: 1fr; } }

            /* Capability Item */
            .ofast-cap-item { background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 10px; transition: all 0.2s ease; }
            .ofast-cap-item:hover { border-color: #667eea; background: #fff; }
            .ofast-cap-label { display: flex; align-items: center; gap: 12px; padding: 12px 15px; cursor: pointer; }
            .ofast-cap-info { flex: 1; min-width: 0; }
            .ofast-cap-name { display: block; font-weight: 600; color: #1e293b; font-size: 14px; margin-bottom: 2px; }
            .ofast-cap-code { display: block; font-size: 11px; color: #64748b; background: #e2e8f0; padding: 2px 6px; border-radius: 4px; display: inline-block; }

            /* Toggle Switch */
            .ofast-cap-toggle { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
            .ofast-cap-checkbox { opacity: 0; width: 0; height: 0; position: absolute; }
            .ofast-toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .3s; border-radius: 24px; }
            .ofast-toggle-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
            .ofast-cap-checkbox:checked + .ofast-toggle-slider { background-color: #667eea; }
            .ofast-cap-checkbox:checked + .ofast-toggle-slider:before { transform: translateX(20px); }
            .ofast-cap-item:has(.ofast-cap-checkbox:checked) { background: #eef2ff; border-color: #667eea; }

            /* Buttons */
            .ofast-btn-primary { display: inline-flex; align-items: center; gap: 10px; padding: 14px 32px; background: #667eea; color: #fff; border: none; border-radius: 10px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(102, 126, 234, 0.35); text-decoration: none; }
            .ofast-btn-primary:hover { background: #5a6fd6; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(102, 126, 234, 0.45); color: #fff; }
            .ofast-btn-secondary { display: inline-flex; align-items: center; justify-content: center; padding: 10px 20px; background: #667eea; color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; text-decoration: none; }
            .ofast-btn-secondary:hover { background: #5a6fd6; transform: translateY(-1px); color: #fff; }
            .ofast-btn-danger { display: inline-flex; align-items: center; justify-content: center; padding: 10px 20px; background: #dc2626; color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; text-decoration: none; }
            .ofast-btn-danger:hover { background: #b91c1c; transform: translateY(-1px); color: #fff; }
            .ofast-btn-large { padding: 16px 40px; font-size: 17px; }
            .ofast-save-note { margin-left: 15px; font-size: 13px; color: #64748b; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Live capability toggle (optional - for instant save without form submit)
            $('.ofast-cap-checkbox').on('change', function() {
                updateStats();
            });

            // Select/Deselect All
            $('#select-all-caps').on('click', function() {
                $('.ofast-cap-checkbox').prop('checked', true).trigger('change');
            });

            $('#deselect-all-caps').on('click', function() {
                $('.ofast-cap-checkbox').prop('checked', false).trigger('change');
            });

            function updateStats() {
                var total = $('.ofast-cap-checkbox').length;
                var enabled = $('.ofast-cap-checkbox:checked').length;
                var disabled = total - enabled;
                
                $('#total-caps').text(total);
                $('#enabled-caps').text(enabled);
                $('#disabled-caps').text(disabled);
            }

            // Initial stats
            updateStats();
        });
        </script>
<?php
    }

    /**
     * Get all available capabilities
     */
    private function get_all_capabilities()
    {
        // Base WordPress capabilities
        $caps = array(
            // Posts
            'edit_posts' => 'Edit Posts',
            'edit_others_posts' => 'Edit Others Posts',
            'edit_published_posts' => 'Edit Published Posts',
            'publish_posts' => 'Publish Posts',
            'delete_posts' => 'Delete Posts',
            'delete_others_posts' => 'Delete Others Posts',
            'delete_published_posts' => 'Delete Published Posts',
            'delete_private_posts' => 'Delete Private Posts',
            'edit_private_posts' => 'Edit Private Posts',
            'read_private_posts' => 'Read Private Posts',
            
            // Pages
            'edit_pages' => 'Edit Pages',
            'edit_others_pages' => 'Edit Others Pages',
            'edit_published_pages' => 'Edit Published Pages',
            'publish_pages' => 'Publish Pages',
            'delete_pages' => 'Delete Pages',
            'delete_others_pages' => 'Delete Others Pages',
            'delete_published_pages' => 'Delete Published Pages',
            'delete_private_pages' => 'Delete Private Pages',
            'edit_private_pages' => 'Edit Private Pages',
            'read_private_pages' => 'Read Private Pages',
            
            // Media
            'upload_files' => 'Upload Files',
            'unfiltered_upload' => 'Upload Any File Type',
            
            // Categories & Tags
            'manage_categories' => 'Manage Categories',
            'manage_post_tags' => 'Manage Post Tags',
            'edit_categories' => 'Edit Categories',
            'delete_categories' => 'Delete Categories',
            'assign_categories' => 'Assign Categories',
            'assign_post_tags' => 'Assign Post Tags',
            
            // Comments
            'moderate_comments' => 'Moderate Comments',
            'edit_comment' => 'Edit Comments',
            
            // Appearance
            'switch_themes' => 'Switch Themes',
            'edit_themes' => 'Edit Themes',
            'edit_theme_options' => 'Edit Theme Options',
            'install_themes' => 'Install Themes',
            'update_themes' => 'Update Themes',
            'delete_themes' => 'Delete Themes',
            'edit_css' => 'Edit CSS',
            'customize' => 'Use Customizer',
            
            // Plugins
            'activate_plugins' => 'Activate Plugins',
            'edit_plugins' => 'Edit Plugins',
            'install_plugins' => 'Install Plugins',
            'update_plugins' => 'Update Plugins',
            'delete_plugins' => 'Delete Plugins',
            
            // Users
            'list_users' => 'List Users',
            'create_users' => 'Create Users',
            'edit_users' => 'Edit Users',
            'delete_users' => 'Delete Users',
            'promote_users' => 'Promote Users',
            'remove_users' => 'Remove Users',
            
            // Tools & Settings
            'manage_options' => 'Manage Options',
            'export' => 'Export Data',
            'import' => 'Import Data',
            'manage_links' => 'Manage Links',
            'edit_dashboard' => 'Edit Dashboard',
            
            // Core
            'read' => 'Read (Access Dashboard)',
            'unfiltered_html' => 'Use Unfiltered HTML',
            'edit_files' => 'Edit Files',
            'update_core' => 'Update WordPress Core',
        );

        // Add custom post type capabilities
        $post_types = get_post_types(array('_builtin' => false), 'objects');
        foreach ($post_types as $post_type) {
            if (isset($post_type->cap)) {
                $cap_obj = $post_type->cap;
                $type_name = $post_type->labels->singular_name;
                
                if (isset($cap_obj->edit_posts)) {
                    $caps[$cap_obj->edit_posts] = "Edit {$type_name}s";
                }
                if (isset($cap_obj->edit_others_posts)) {
                    $caps[$cap_obj->edit_others_posts] = "Edit Others' {$type_name}s";
                }
                if (isset($cap_obj->publish_posts)) {
                    $caps[$cap_obj->publish_posts] = "Publish {$type_name}s";
                }
                if (isset($cap_obj->delete_posts)) {
                    $caps[$cap_obj->delete_posts] = "Delete {$type_name}s";
                }
            }
        }

        // WooCommerce capabilities (if active)
        if (class_exists('WooCommerce')) {
            $woo_caps = array(
                'manage_woocommerce' => 'Manage WooCommerce',
                'view_woocommerce_reports' => 'View WooCommerce Reports',
                'edit_product' => 'Edit Products',
                'read_product' => 'Read Products',
                'delete_product' => 'Delete Products',
                'edit_products' => 'Edit Products (Bulk)',
                'edit_others_products' => 'Edit Others Products',
                'publish_products' => 'Publish Products',
                'read_private_products' => 'Read Private Products',
                'delete_products' => 'Delete Products (Bulk)',
                'delete_private_products' => 'Delete Private Products',
                'delete_published_products' => 'Delete Published Products',
                'delete_others_products' => 'Delete Others Products',
                'edit_private_products' => 'Edit Private Products',
                'edit_published_products' => 'Edit Published Products',
                'manage_product_terms' => 'Manage Product Categories/Tags',
                'edit_product_terms' => 'Edit Product Terms',
                'delete_product_terms' => 'Delete Product Terms',
                'assign_product_terms' => 'Assign Product Terms',
                'edit_shop_order' => 'Edit Orders',
                'read_shop_order' => 'Read Orders',
                'delete_shop_order' => 'Delete Orders',
                'edit_shop_orders' => 'Edit Orders (Bulk)',
                'edit_others_shop_orders' => 'Edit Others Orders',
                'publish_shop_orders' => 'Publish Orders',
                'read_private_shop_orders' => 'Read Private Orders',
                'delete_shop_orders' => 'Delete Orders (Bulk)',
                'manage_woocommerce_payment_gateways' => 'Manage Payment Gateways',
                'manage_woocommerce_shipping' => 'Manage Shipping',
                'manage_woocommerce_tax' => 'Manage Tax Settings',
            );
            $caps = array_merge($caps, $woo_caps);
        }

        return apply_filters('ofast_all_capabilities', $caps);
    }

    /**
     * Group capabilities by category
     */
    private function group_capabilities($capabilities)
    {
        $grouped = array(
            'Posts & Content' => array(),
            'Pages' => array(),
            'Media' => array(),
            'Categories & Tags' => array(),
            'Comments' => array(),
            'Appearance' => array(),
            'Plugins' => array(),
            'Users' => array(),
            'Settings & Tools' => array(),
            'WooCommerce' => array(),
            'Custom Post Types' => array(),
            'Advanced' => array(),
        );

        foreach ($capabilities as $cap => $label) {
            // Posts
            if (strpos($cap, 'post') !== false && strpos($cap, 'tag') === false) {
                $grouped['Posts & Content'][$cap] = $label;
            }
            // Pages
            elseif (strpos($cap, 'page') !== false) {
                $grouped['Pages'][$cap] = $label;
            }
            // Media
            elseif (strpos($cap, 'upload') !== false || strpos($cap, 'file') !== false) {
                $grouped['Media'][$cap] = $label;
            }
            // Categories & Tags
            elseif (strpos($cap, 'categor') !== false || strpos($cap, 'tag') !== false || strpos($cap, 'term') !== false) {
                $grouped['Categories & Tags'][$cap] = $label;
            }
            // Comments
            elseif (strpos($cap, 'comment') !== false) {
                $grouped['Comments'][$cap] = $label;
            }
            // Appearance
            elseif (strpos($cap, 'theme') !== false || strpos($cap, 'customize') !== false || strpos($cap, 'css') !== false) {
                $grouped['Appearance'][$cap] = $label;
            }
            // Plugins
            elseif (strpos($cap, 'plugin') !== false) {
                $grouped['Plugins'][$cap] = $label;
            }
            // Users
            elseif (strpos($cap, 'user') !== false) {
                $grouped['Users'][$cap] = $label;
            }
            // WooCommerce
            elseif (strpos($cap, 'woocommerce') !== false || strpos($cap, 'product') !== false || strpos($cap, 'shop_order') !== false) {
                $grouped['WooCommerce'][$cap] = $label;
            }
            // Settings & Tools
            elseif (in_array($cap, array('manage_options', 'export', 'import', 'manage_links', 'edit_dashboard', 'update_core'))) {
                $grouped['Settings & Tools'][$cap] = $label;
            }
            // Custom capabilities
            elseif (!in_array($cap, array('read', 'unfiltered_html', 'edit_files'))) {
                $grouped['Custom Post Types'][$cap] = $label;
            }
            // Advanced
            else {
                $grouped['Advanced'][$cap] = $label;
            }
        }

        // Remove empty groups
        return array_filter($grouped);
    }

    /**
     * Get icon for capability group
     */
    private function get_group_icon($group)
    {
        $icons = array(
            'Posts & Content' => '',
            'Pages' => '',
            'Media' => '',
            'Categories & Tags' => '',
            'Comments' => '',
            'Appearance' => '',
            'Plugins' => '',
            'Users' => '',
            'Settings & Tools' => '',
            'WooCommerce' => '',
            'Custom Post Types' => '',
            'Advanced' => '',
        );

        return isset($icons[$group]) ? $icons[$group] : '';
    }

    /**
     * Get list of core WordPress roles that cannot be deleted
     */
    private function get_core_roles()
    {
        return array('administrator', 'editor', 'author', 'contributor', 'subscriber');
    }

    /**
     * Check if a role can be deleted
     */
    public function is_deletable_role($role_slug)
    {
        return !in_array($role_slug, $this->get_core_roles());
    }

    /**
     * Handle role creation
     */
    public function handle_role_create()
    {
        if (!isset($_POST['ofast_create_role'])) {
            return;
        }

        check_admin_referer('ofast_create_role', '_wpnonce_create');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $role_name = isset($_POST['new_role_name']) ? sanitize_text_field($_POST['new_role_name']) : '';
        $clone_from = isset($_POST['clone_from']) ? sanitize_text_field($_POST['clone_from']) : '';

        if (empty($role_name)) {
            wp_redirect(add_query_arg(array(
                'page' => 'ofast-role-capabilities',
                'error' => 'empty_name'
            ), admin_url('users.php')));
            exit;
        }

        // Generate slug from name
        $role_slug = sanitize_title($role_name);
        $role_slug = str_replace('-', '_', $role_slug);

        // Check if role already exists
        if (get_role($role_slug)) {
            wp_redirect(add_query_arg(array(
                'page' => 'ofast-role-capabilities',
                'error' => 'role_exists'
            ), admin_url('users.php')));
            exit;
        }

        // Get capabilities to clone
        $capabilities = array('read' => true); // Default: at least read capability
        
        if (!empty($clone_from) && get_role($clone_from)) {
            $source_role = get_role($clone_from);
            $capabilities = $source_role->capabilities;
        }

        // Create the new role
        $result = add_role($role_slug, $role_name, $capabilities);

        if ($result) {
            // Track as custom role
            $custom_roles = get_option('ofast_custom_roles', array());
            $custom_roles[] = $role_slug;
            update_option('ofast_custom_roles', array_unique($custom_roles));

            wp_redirect(add_query_arg(array(
                'page' => 'ofast-role-capabilities',
                'role' => $role_slug,
                'created' => '1'
            ), admin_url('users.php')));
            exit;
        }

        wp_redirect(add_query_arg(array(
            'page' => 'ofast-role-capabilities',
            'error' => 'create_failed'
        ), admin_url('users.php')));
        exit;
    }

    /**
     * Handle role deletion
     */
    public function handle_role_delete()
    {
        if (!isset($_GET['action']) || $_GET['action'] !== 'delete_role') {
            return;
        }

        if (!isset($_GET['page']) || $_GET['page'] !== 'ofast-role-capabilities') {
            return;
        }

        check_admin_referer('ofast_delete_role');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $role_slug = isset($_GET['role']) ? sanitize_text_field($_GET['role']) : '';

        if (empty($role_slug)) {
            wp_redirect(add_query_arg(array(
                'page' => 'ofast-role-capabilities',
                'error' => 'no_role'
            ), admin_url('users.php')));
            exit;
        }

        // Check if deletable
        if (!$this->is_deletable_role($role_slug)) {
            wp_redirect(add_query_arg(array(
                'page' => 'ofast-role-capabilities',
                'error' => 'core_role'
            ), admin_url('users.php')));
            exit;
        }

        // Check if any users have this role
        $users_with_role = get_users(array('role' => $role_slug));
        if (!empty($users_with_role)) {
            wp_redirect(add_query_arg(array(
                'page' => 'ofast-role-capabilities',
                'error' => 'role_in_use',
                'role' => $role_slug
            ), admin_url('users.php')));
            exit;
        }

        // Remove the role
        remove_role($role_slug);

        // Remove from custom roles tracking
        $custom_roles = get_option('ofast_custom_roles', array());
        $custom_roles = array_diff($custom_roles, array($role_slug));
        update_option('ofast_custom_roles', $custom_roles);

        wp_redirect(add_query_arg(array(
            'page' => 'ofast-role-capabilities',
            'deleted' => '1'
        ), admin_url('users.php')));
        exit;
    }
}
