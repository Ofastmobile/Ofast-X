<?php

/**
 * Ofast X - Who's Admin Module
 * Dashboard widgets showing administrator users and designer details
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Whos_Admin
{

    /**
     * Initialize module
     */
    public function init()
    {
        // Only load if module is enabled
        $enabled = get_option('ofastx_modules_enabled', array());
        if (empty($enabled['whos-admin'])) {
            return;
        }

        // Add dashboard widgets
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widgets'));

        // Add settings page
        add_action('admin_menu', array($this, 'add_settings_menu'));
        add_action('admin_init', array($this, 'handle_settings_save'));
    }

    /**
     * Add dashboard widgets
     */
    public function add_dashboard_widgets()
    {
        // Administrator widget
        wp_add_dashboard_widget(
            'ofast_admin_users_widget',
            '👤 Administrator',
            array($this, 'render_admin_users_widget')
        );

        // Designer Details widget
        wp_add_dashboard_widget(
            'ofast_designer_details_widget',
            '🎨 Designer Details',
            array($this, 'render_designer_widget')
        );
    }

    /**
     * Render Administrator Users Widget
     */
    public function render_admin_users_widget()
    {
        $args = array(
            'role'    => 'administrator',
            'orderby' => 'registered',
            'order'   => 'DESC',
        );

        $admin_users = get_users($args);

        if ($admin_users) {
            foreach ($admin_users as $admin_user) {
                $first_name = $admin_user->first_name;
                $last_name = $admin_user->last_name;
                $email = $admin_user->user_email;
                $full_name = trim($first_name . ' ' . $last_name) ?: $admin_user->user_login;
                $site_logo_url = get_site_icon_url(32);

                echo '<table style="width: 100%; margin-bottom: 20px; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;">';

                echo '<tr style="background: #f9f9f9;"><th style="text-align: left; width: 120px; padding: 10px; font-weight: 600;">Name</th>
                          <td style="padding: 10px;">' . esc_html($full_name) . '</td></tr>';

                echo '<tr><th style="text-align: left; padding: 10px; font-weight: 600;">Email</th>
                          <td style="padding: 10px;"><a href="mailto:' . esc_attr($email) . '" style="color: #1e88e5; text-decoration: none;">' . esc_html($email) . '</a></td></tr>';

                echo '<tr style="background: #f9f9f9;"><th style="text-align: left; padding: 10px; font-weight: 600;">Site Logo</th>
                          <td style="padding: 10px;">';
                if ($site_logo_url) {
                    echo '<img src="' . esc_url($site_logo_url) . '" alt="Site Logo" width="32" height="32" style="border-radius: 4px;">';
                } else {
                    echo '<span style="color: #999;">No Logo Set</span>';
                }
                echo '</td></tr>';

                echo '</table>';
            }
        } else {
            echo '<p style="color: #999;">No admin users found.</p>';
        }
    }

    /**
     * Render Designer Details Widget
     */
    public function render_designer_widget()
    {
        $name = get_option('ofast_designer_name', 'Your Name');
        $email = get_option('ofast_designer_email', 'hello@example.com');
        $website = get_option('ofast_designer_website', 'https://example.com');

        echo '<div style="padding: 10px;">';
        echo '<p style="margin: 8px 0;"><strong>💼 Designer:</strong> ' . esc_html($name) . '</p>';
        echo '<p style="margin: 8px 0;"><strong>📧 Email:</strong> <a href="mailto:' . esc_attr($email) . '" style="color: #1e88e5; text-decoration: none;">' . esc_html($email) . '</a></p>';
        echo '<p style="margin: 8px 0;"><strong>🌐 Website:</strong> <a href="' . esc_url($website) . '" target="_blank" style="color: #1e88e5; text-decoration: none;">' . esc_html($website) . '</a></p>';
        echo '<hr style="margin: 15px 0; border: none; border-top: 1px solid #eee;">';
        echo '<p style="text-align: center; margin: 10px 0;"><a href="' . admin_url('admin.php?page=ofast-whos-admin-settings') . '" class="button button-small">⚙️ Edit Details</a></p>';
        echo '</div>';
    }

    /**
     * Add settings submenu
     */
    public function add_settings_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'Who\'s Admin Settings',
            'Who\'s Admin',
            'manage_options',
            'ofast-whos-admin-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Handle settings save
     */
    public function handle_settings_save()
    {
        if (!isset($_POST['ofast_whos_admin_save'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['_wpnonce'], 'ofast_whos_admin_settings')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        update_option('ofast_designer_name', sanitize_text_field($_POST['designer_name']));
        update_option('ofast_designer_email', sanitize_email($_POST['designer_email']));
        update_option('ofast_designer_website', esc_url_raw($_POST['designer_website']));

        wp_redirect(add_query_arg('settings_saved', '1', wp_get_referer()));
        exit;
    }

    /**
     * Render settings page
     */
    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $name = get_option('ofast_designer_name', '');
        $email = get_option('ofast_designer_email', '');
        $website = get_option('ofast_designer_website', '');
        $saved = isset($_GET['settings_saved']);

?>
        <div class="wrap">
            <h1>🎨 Who's Admin - Designer Details</h1>
            <p>Set your designer/developer information to display in the dashboard widget.</p>

            <?php if ($saved): ?>
                <div class="notice notice-success is-dismissible">
                    <p>✅ Designer details saved successfully!</p>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('ofast_whos_admin_settings', '_wpnonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="designer_name">Designer Name</label>
                        </th>
                        <td>
                            <input type="text" name="designer_name" id="designer_name" value="<?php echo esc_attr($name); ?>" class="regular-text" placeholder="Your Name">
                            <p class="description">Your full name or company name</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="designer_email">Email Address</label>
                        </th>
                        <td>
                            <input type="email" name="designer_email" id="designer_email" value="<?php echo esc_attr($email); ?>" class="regular-text" placeholder="hello@example.com">
                            <p class="description">Contact email for support inquiries</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="designer_website">Website URL</label>
                        </th>
                        <td>
                            <input type="url" name="designer_website" id="designer_website" value="<?php echo esc_attr($website); ?>" class="regular-text" placeholder="https://example.com">
                            <p class="description">Your portfolio or business website</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="ofast_whos_admin_save" class="button button-primary">💾 Save Designer Details</button>
                </p>
            </form>
        </div>
<?php
    }
}
