<?php

/**
 * Ofast X - Admin Tweaks Module
 * Quick admin customizations without separate menus
 * Settings are managed from the main Ofast X Settings page
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Admin_Tweaks
{
    /**
     * Initialize module
     */
    public function init()
    {
        $enabled = get_option('ofastx_modules_enabled', array());
        if (empty($enabled['admin-tweaks'])) {
            return;
        }

        $settings = get_option('ofast_admin_tweaks', array());

        // Post/Page ID Column
        if (!empty($settings['show_post_id'])) {
            add_filter('manage_posts_columns', array($this, 'add_id_column'));
            add_action('manage_posts_custom_column', array($this, 'render_id_column'), 10, 2);
            add_filter('manage_pages_columns', array($this, 'add_id_column'));
            add_action('manage_pages_custom_column', array($this, 'render_id_column'), 10, 2);
        }

        // Infinity Media Scroll
        if (!empty($settings['infinity_media'])) {
            add_filter('media_library_infinite_scrolling', '__return_true');
        }

        // Hide Admin Bar
        if (!empty($settings['hide_admin_bar'])) {
            add_filter('show_admin_bar', '__return_false');
        }

        // Hide Admin Bar by Role
        if (!empty($settings['hide_admin_bar_roles'])) {
            add_filter('show_admin_bar', array($this, 'hide_admin_bar_by_role'));
        }

        // Remove WordPress Logo from Admin Bar
        if (!empty($settings['remove_wp_logo'])) {
            add_action('wp_before_admin_bar_render', array($this, 'remove_wp_logo'));
        }

        // Remove +New Menu from Admin Bar
        if (!empty($settings['remove_new_content'])) {
            add_action('wp_before_admin_bar_render', array($this, 'remove_new_content_menu'));
        }

        // Rename Howdy to Hello
        if (!empty($settings['rename_howdy'])) {
            add_filter('admin_bar_menu', array($this, 'rename_howdy'), 25);
        }

        // Add settings section to Ofast X Settings page
        add_action('ofast_settings_after_modules', array($this, 'render_settings_section'));
        add_action('admin_init', array($this, 'save_settings'));
    }

    /**
     * Add ID column to posts/pages list
     */
    public function add_id_column($columns)
    {
        $new_columns = array();
        foreach ($columns as $key => $value) {
            if ($key === 'title') {
                $new_columns['post_id'] = 'ID';
            }
            $new_columns[$key] = $value;
        }
        return $new_columns;
    }

    /**
     * Render ID column content
     */
    public function render_id_column($column, $post_id)
    {
        if ($column === 'post_id') {
            echo '<code style="background: #f0f0f1; padding: 3px 8px; border-radius: 4px;">' . $post_id . '</code>';
        }
    }

    /**
     * Hide admin bar by specific roles
     */
    public function hide_admin_bar_by_role($show)
    {
        if (!is_user_logged_in()) {
            return $show;
        }

        $settings = get_option('ofast_admin_tweaks', array());
        $hidden_roles = isset($settings['hide_admin_bar_roles']) ? $settings['hide_admin_bar_roles'] : array();

        if (empty($hidden_roles)) {
            return $show;
        }

        $user = wp_get_current_user();
        foreach ($user->roles as $role) {
            if (in_array($role, $hidden_roles)) {
                return false;
            }
        }

        return $show;
    }

    /**
     * Remove WordPress logo from admin bar
     */
    public function remove_wp_logo()
    {
        global $wp_admin_bar;
        $wp_admin_bar->remove_menu('wp-logo');
    }

    /**
     * Remove +New content menu from admin bar
     */
    public function remove_new_content_menu()
    {
        global $wp_admin_bar;
        $wp_admin_bar->remove_menu('new-content');
    }

    /**
     * Rename Howdy to Hello
     */
    public function rename_howdy($wp_admin_bar)
    {
        $my_account = $wp_admin_bar->get_node('my-account');
        if ($my_account) {
            $newtitle = str_replace('Howdy,', 'Hello,', $my_account->title);
            $wp_admin_bar->add_node(array(
                'id' => 'my-account',
                'title' => $newtitle,
            ));
        }
    }

    /**
     * Save settings
     */
    public function save_settings()
    {
        if (!isset($_POST['ofast_save_admin_tweaks'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        if (!wp_verify_nonce($_POST['admin_tweaks_nonce'], 'ofast_admin_tweaks_save')) {
            return;
        }

        $settings = array(
            'show_post_id' => isset($_POST['ofast_show_post_id']) ? 1 : 0,
            'infinity_media' => isset($_POST['ofast_infinity_media']) ? 1 : 0,
            'hide_admin_bar' => isset($_POST['ofast_hide_admin_bar']) ? 1 : 0,
            'remove_wp_logo' => isset($_POST['ofast_remove_wp_logo']) ? 1 : 0,
            'remove_new_content' => isset($_POST['ofast_remove_new_content']) ? 1 : 0,
            'rename_howdy' => isset($_POST['ofast_rename_howdy']) ? 1 : 0,
            'hide_admin_bar_roles' => isset($_POST['ofast_hide_bar_roles']) ? array_map('sanitize_text_field', $_POST['ofast_hide_bar_roles']) : array(),
        );

        update_option('ofast_admin_tweaks', $settings);

        add_action('admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p>Admin tweaks settings saved!</p></div>';
        });
    }

    /**
     * Render settings section on Ofast X Settings page
     */
    public function render_settings_section()
    {
        $settings = get_option('ofast_admin_tweaks', array());
        $roles = wp_roles()->roles;
?>
        <div class="ofast-admin-tweaks-section" style="margin-top: 30px; padding: 20px; background: #fff; border: 1px solid #ddd; border-radius: 8px;">
            <h2 style="margin-top: 0;">Quick Admin Tweaks</h2>
            <p style="color: #666;">These settings don't require separate menus - just toggle them here.</p>

            <form method="post">
                <?php wp_nonce_field('ofast_admin_tweaks_save', 'admin_tweaks_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Post/Page ID Column</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ofast_show_post_id" value="1" <?php checked(!empty($settings['show_post_id'])); ?>>
                                Show ID column in Posts and Pages list tables
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Infinity Media Scroll</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ofast_infinity_media" value="1" <?php checked(!empty($settings['infinity_media'])); ?>>
                                Enable infinite scroll in Media Library
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Hide Admin Bar</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ofast_hide_admin_bar" value="1" <?php checked(!empty($settings['hide_admin_bar'])); ?>>
                                Hide admin bar on frontend for all users
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Hide Admin Bar by Role</th>
                        <td>
                            <?php foreach ($roles as $role_slug => $role_data): ?>
                                <label style="display: inline-block; margin-right: 15px; margin-bottom: 5px;">
                                    <input type="checkbox" name="ofast_hide_bar_roles[]" value="<?php echo esc_attr($role_slug); ?>"
                                        <?php checked(in_array($role_slug, $settings['hide_admin_bar_roles'] ?? array())); ?>>
                                    <?php echo esc_html(translate_user_role($role_data['name'])); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">Select roles that should not see the admin bar on frontend.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Remove WordPress Logo</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ofast_remove_wp_logo" value="1" <?php checked(!empty($settings['remove_wp_logo'])); ?>>
                                Remove WordPress logo from admin bar
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Remove +New Menu</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ofast_remove_new_content" value="1" <?php checked(!empty($settings['remove_new_content'])); ?>>
                                Remove the "+New" content menu from admin bar
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Rename "Howdy" to "Hello"</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ofast_rename_howdy" value="1" <?php checked(!empty($settings['rename_howdy'])); ?>>
                                Change "Howdy, Username" to "Hello, Username" in admin bar
                            </label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="ofast_save_admin_tweaks" class="button button-primary">Save Tweaks</button>
                </p>
            </form>
        </div>
<?php
    }
}
