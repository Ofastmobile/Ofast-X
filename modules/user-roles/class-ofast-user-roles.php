<?php

/**
 * Ofast X - User Role Manager Module
 * Allows assigning multiple roles to WordPress users
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
        // Only load if module is enabled
        $enabled = get_option('ofastx_modules_enabled', array());
        if (empty($enabled['user-roles'])) {
            return;
        }

        // Add role checkboxes to user profile
        add_action('show_user_profile', array($this, 'render_role_checkboxes'));
        add_action('edit_user_profile', array($this, 'render_role_checkboxes'));

        // Save roles on profile update
        add_action('personal_options_update', array($this, 'save_user_roles'));
        add_action('edit_user_profile_update', array($this, 'save_user_roles'));

        // Add roles column to users list
        add_filter('manage_users_columns', array($this, 'add_roles_column'));
        add_filter('manage_users_custom_column', array($this, 'render_roles_column'), 10, 3);
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
        <h3>🎭 User Roles (Multiple Selection)</h3>
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
                        ⚠️ At least one role is required. If no roles are selected, the user will be assigned "Subscriber".
                    </p>
                </td>
            </tr>
        </table>

        <?php wp_nonce_field('ofast_save_user_roles', 'ofast_roles_nonce'); ?>
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
     * Add roles column to users list
     */
    public function add_roles_column($columns)
    {
        // Insert after username
        $new_columns = array();
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'username') {
                $new_columns['ofast_roles'] = 'Roles';
            }
        }

        // Remove default role column
        unset($new_columns['role']);

        return $new_columns;
    }

    /**
     * Render roles column content
     */
    public function render_roles_column($output, $column_name, $user_id)
    {
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
}
