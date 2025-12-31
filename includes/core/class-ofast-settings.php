<?php

/**
 * Ofast X Global Settings
 * Professional module management with toggle switches
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Settings
{

    /**
     * Initialize settings
     */
    public function init()
    {
        add_action('admin_menu', array($this, 'add_settings_menu'));
        add_action('admin_init', array($this, 'handle_save'));
        add_action('admin_init', array($this, 'handle_reset'));
    }

    /**
     * Add settings submenu
     */
    public function add_settings_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'Ofast X Settings',
            'Settings',
            'manage_options',
            'ofast-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Handle settings save
     */
    public function handle_save()
    {
        if (!isset($_POST['ofast_save_settings'])) {
            return;
        }

        // Security checks
        if (!wp_verify_nonce($_POST['_wpnonce'], 'ofast_settings_save')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions');
        }

        // Get submitted module states
        $modules = $this->get_available_modules();
        $enabled_modules = array();

        foreach ($modules as $slug => $data) {
            // Skip locked modules
            if (!empty($data['locked'])) continue;
            $enabled_modules[$slug] = isset($_POST['modules'][$slug]);
        }

        // Save to database
        update_option('ofastx_modules_enabled', $enabled_modules);

        // Save data management settings
        $delete_data = isset($_POST['ofast_delete_data_on_uninstall']) ? intval($_POST['ofast_delete_data_on_uninstall']) : 0;
        update_option('ofast_delete_data_on_uninstall', $delete_data);

        // Redirect with success message
        wp_redirect(add_query_arg('settings_saved', '1', wp_get_referer()));
        exit;
    }

    /**
     * Handle settings reset
     */
    public function handle_reset()
    {
        if (!isset($_POST['ofast_reset_settings'])) {
            return;
        }

        // Security checks
        if (!wp_verify_nonce($_POST['_wpnonce'], 'ofast_settings_save')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions');
        }

        // Reset module enabled states to defaults
        $default_modules = array(
            'email' => true,
            'debug' => true,
            'smtp' => true,
            'newsletter' => false,
        );
        update_option('ofastx_modules_enabled', $default_modules);

        // Reset data management setting
        update_option('ofast_delete_data_on_uninstall', 0);

        // Clear settings cache
        if (class_exists('Ofast_X_Core') && method_exists('Ofast_X_Core', 'clear_options_cache')) {
            Ofast_X_Core::clear_options_cache();
        }

        // Redirect with reset message
        wp_redirect(add_query_arg('settings_reset', '1', wp_get_referer()));
        exit;
    }

    /**
     * Render settings page
     */
    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions');
        }

        $modules = $this->get_available_modules();
        $enabled = get_option('ofastx_modules_enabled', array());
        $saved = isset($_GET['settings_saved']);

?>
        <div class="wrap ofast-settings-wrap">
            <h1>Ofast X Settings</h1>
            <p class="description">Enable or disable plugin modules. Only enabled modules will load.</p>

            <?php if ($saved): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Settings saved successfully!</p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['settings_reset'])): ?>
                <div class="notice notice-warning is-dismissible">
                    <p>All settings have been reset to defaults!</p>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('ofast_settings_save', '_wpnonce'); ?>

                <div class="ofast-modules-grid">
                    <?php foreach ($modules as $slug => $data):
                        $is_locked = !empty($data['locked']);
                        $is_coming_soon = !empty($data['coming_soon']);
                        $is_enabled = !empty($enabled[$slug]);
                        $card_class = $is_enabled ? 'enabled' : '';
                        if ($is_locked) $card_class = 'locked';
                        if ($is_coming_soon) $card_class = 'coming-soon';
                    ?>
                        <div class="ofast-module-card <?php echo esc_attr($card_class); ?>">
                            <div class="module-header">
                                <h3><?php echo esc_html($data['name']); ?></h3>
                                <?php if ($is_locked): ?>
                                    <span class="ofast-badge active">Always On</span>
                                <?php elseif ($is_coming_soon): ?>
                                    <span class="ofast-badge coming-soon">Coming Soon</span>
                                <?php elseif ($is_enabled): ?>
                                    <span class="ofast-badge integrated">Integrated</span>
                                <?php else: ?>
                                    <span class="ofast-badge not-integrated">Not Integrated</span>
                                <?php endif; ?>
                            </div>
                            <p class="module-description"><?php echo esc_html($data['description']); ?></p>
                            <div class="module-footer">
                                <?php if ($is_locked): ?>
                                    <span class="always-active">Core Module</span>
                                <?php elseif ($is_coming_soon): ?>
                                    <span class="coming-soon-text">Available Soon</span>
                                <?php else: ?>
                                    <label class="ofast-toggle-switch">
                                        <input
                                            type="checkbox"
                                            name="modules[<?php echo esc_attr($slug); ?>]"
                                            id="module_<?php echo esc_attr($slug); ?>"
                                            value="1"
                                            <?php checked($is_enabled); ?>>
                                        <span class="slider"></span>
                                    </label>
                                    <span class="toggle-label"><?php echo $is_enabled ? 'Enabled' : 'Disabled'; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Data Management Section -->
                <div class="ofast-data-management" style="margin-top: 40px; padding: 25px; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;">
                    <h2 style="margin: 0 0 10px 0; font-size: 18px; color: #1e293b;">Data Management</h2>
                    <p style="color: #64748b; margin: 0 0 20px 0;">Control what happens to your data when the plugin is deleted.</p>

                    <?php $delete_data = get_option('ofast_delete_data_on_uninstall', 0); ?>

                    <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                        <label style="display: flex; align-items: flex-start; gap: 12px; padding: 15px 20px; background: <?php echo !$delete_data ? '#eef2ff' : '#f8fafc'; ?>; border: 2px solid <?php echo !$delete_data ? '#6366f1' : '#e5e7eb'; ?>; border-radius: 10px; cursor: pointer; flex: 1; min-width: 250px;">
                            <input type="radio" name="ofast_delete_data_on_uninstall" value="0" <?php checked($delete_data, 0); ?> style="margin-top: 3px;">
                            <div>
                                <strong style="display: block; color: #1e293b; font-size: 14px;">Keep All Data</strong>
                                <span style="color: #64748b; font-size: 13px;">Database tables and settings will be preserved. Useful if you plan to reinstall later.</span>
                            </div>
                        </label>

                        <label style="display: flex; align-items: flex-start; gap: 12px; padding: 15px 20px; background: <?php echo $delete_data ? '#fef2f2' : '#f8fafc'; ?>; border: 2px solid <?php echo $delete_data ? '#ef4444' : '#e5e7eb'; ?>; border-radius: 10px; cursor: pointer; flex: 1; min-width: 250px;">
                            <input type="radio" name="ofast_delete_data_on_uninstall" value="1" <?php checked($delete_data, 1); ?> style="margin-top: 3px;">
                            <div>
                                <strong style="display: block; color: #1e293b; font-size: 14px;">Remove All Data</strong>
                                <span style="color: #64748b; font-size: 13px;">Completely remove all database tables, options, and settings when uninstalled.</span>
                            </div>
                        </label>
                    </div>

                    <p style="margin: 15px 0 0 0; padding: 12px 0; color: #64748b; font-size: 13px;">
                        <strong>Note:</strong> This setting only takes effect when the plugin is <em>deleted</em> (not just deactivated). Deactivating the plugin will never remove your data.
                    </p>
                </div>

                <p class="submit" style="margin-top: 30px; display: flex; gap: 15px; align-items: center;">
                    <button type="submit" name="ofast_save_settings" class="ofast-save-btn">
                        Save All Settings
                    </button>
                    <button type="submit" name="ofast_reset_settings" class="ofast-reset-btn" onclick="return confirm('Are you sure you want to reset all settings to defaults?\n\nThis will:\n• Disable most modules\n• Reset data management settings\n\nYour data (snippets, redirects, etc.) will NOT be deleted.');">
                        Reset to Default
                    </button>
                </p>
            </form>

            <?php
            // Allow modules to add their own settings sections
            do_action('ofast_settings_after_modules');
            ?>
        </div>

        <style>
            .ofast-settings-wrap {
                max-width: 1200px;
            }

            .ofast-modules-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 20px;
                margin-top: 25px;
            }

            .ofast-module-card {
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 12px;
                padding: 20px;
                transition: all 0.3s ease;
                display: flex;
                flex-direction: column;
            }

            .ofast-module-card:hover {
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
                transform: translateY(-2px);
            }

            .ofast-module-card.enabled {
                border-color: #6366f1;
                background: linear-gradient(to bottom, #eef2ff, #fff);
            }

            .ofast-module-card.locked {
                border-color: #6366f1;
                background: linear-gradient(to bottom, #eef2ff, #fff);
            }

            .ofast-module-card.coming-soon {
                opacity: 0.7;
                border-style: dashed;
            }

            .module-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 10px;
                gap: 10px;
            }

            .module-header h3 {
                margin: 0;
                font-size: 15px;
                font-weight: 600;
                color: #1e293b;
            }

            .module-description {
                color: #64748b;
                font-size: 13px;
                line-height: 1.5;
                margin: 0 0 15px 0;
                flex-grow: 1;
            }

            .module-footer {
                display: flex;
                align-items: center;
                gap: 10px;
                padding-top: 15px;
                border-top: 1px solid #f1f5f9;
            }

            .toggle-label {
                font-size: 12px;
                color: #64748b;
            }

            .always-active,
            .coming-soon-text {
                font-size: 12px;
                color: #64748b;
                font-style: italic;
            }

            /* Toggle Switch */
            .ofast-toggle-switch {
                position: relative;
                display: inline-block;
                width: 44px;
                height: 24px;
            }

            .ofast-toggle-switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }

            .ofast-toggle-switch .slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #cbd5e1;
                transition: .3s;
                border-radius: 24px;
            }

            .ofast-toggle-switch .slider:before {
                position: absolute;
                content: "";
                height: 18px;
                width: 18px;
                left: 3px;
                bottom: 3px;
                background-color: white;
                transition: .3s;
                border-radius: 50%;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
            }

            .ofast-toggle-switch input:checked+.slider {
                background-color: #6366f1;
            }

            .ofast-toggle-switch input:checked+.slider:before {
                transform: translateX(20px);
            }

            /* Badges */
            .ofast-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 6px;
                font-size: 10px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                white-space: nowrap;
            }

            .ofast-badge.integrated {
                background: #dbeafe;
                color: #1e40af;
            }

            .ofast-badge.coming-soon {
                background: #fef3c7;
                color: #92400e;
            }

            .ofast-badge.active {
                background: #ede9fe;
                color: #6d28d9;
            }

            .ofast-save-btn {
                background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
                color: #fff;
                border: none;
                padding: 14px 32px;
                font-size: 15px;
                font-weight: 600;
                border-radius: 10px;
                cursor: pointer;
                transition: all 0.3s ease;
                box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
            }

            .ofast-save-btn:hover {
                background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
                box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
                transform: translateY(-2px);
            }

            .ofast-save-btn:active {
                transform: translateY(0);
                box-shadow: 0 2px 10px rgba(99, 102, 241, 0.3);
            }

            .ofast-reset-btn {
                background: #fff;
                color: #ef4444;
                border: 2px solid #fecaca;
                padding: 12px 24px;
                font-size: 14px;
                font-weight: 600;
                border-radius: 10px;
                cursor: pointer;
                transition: all 0.3s ease;
            }

            .ofast-reset-btn:hover {
                background: #fef2f2;
                border-color: #ef4444;
            }

            .ofast-reset-btn:active {
                background: #fee2e2;
            }
        </style>

        <script>
            jQuery(document).ready(function($) {
                // Update toggle label when checkbox changes
                $('.ofast-toggle-switch input').on('change', function() {
                    var label = $(this).closest('.module-footer').find('.toggle-label');
                    var card = $(this).closest('.ofast-module-card');
                    var badge = card.find('.module-header .ofast-badge');
                    if (this.checked) {
                        label.text('Enabled');
                        card.addClass('enabled');
                        badge.removeClass('not-integrated').addClass('integrated').text('Integrated');
                    } else {
                        label.text('Disabled');
                        card.removeClass('enabled');
                        badge.removeClass('integrated').addClass('not-integrated').text('Not Integrated');
                    }
                });
            });
        </script>
<?php
    }

    /**
     * Get available modules
     */
    private function get_available_modules()
    {
        $enabled = get_option('ofastx_modules_enabled', array());

        return array(
            'dashboard' => array(
                'name' => 'Dashboard Module',
                'description' => 'Custom dashboard with user statistics',
                'locked' => true,
            ),
            'email' => array(
                'name' => 'Email Module',
                'description' => 'Send bulk emails to users with scheduling and templates',
                'status' => 'Integrated',
            ),
            'debug' => array(
                'name' => 'Debug Indicator',
                'description' => 'Shows debug mode indicator in admin bar',
                'status' => 'Integrated',
            ),
            'newsletter' => array(
                'name' => 'Newsletter Subscriptions',
                'description' => 'Newsletter signup forms with admin management',
                'status' => 'Integrated',
            ),
            'snippets' => array(
                'name' => 'Code Snippets Manager',
                'description' => 'Manage code snippets with toggle switches',
                'status' => 'Integrated',
            ),
            'admin-design' => array(
                'name' => 'WP Admin Design',
                'description' => 'Modern glassmorphism styling for WordPress admin',
                'status' => 'Integrated',
            ),
            'whos-admin' => array(
                'name' => 'Who\'s Admin Widget',
                'description' => 'Dashboard widget showing admin users and designer details',
                'status' => 'Integrated',
            ),
            'smtp' => array(
                'name' => 'SMTP Configuration',
                'description' => 'Custom SMTP settings for reliable email delivery (Zoho, Gmail, SendGrid, Mailgun)',
                'status' => 'Integrated',
            ),
            'forms' => array(
                'name' => 'Contact Forms',
                'description' => 'Custom contact form builder with multi-channel notifications',
                'status' => 'Integrated',
            ),
            'redirects' => array(
                'name' => 'Redirects Manager',
                'description' => '301/302/307 redirects with import/export and activate toggle',
                'status' => 'Integrated',
            ),
            'user-roles' => array(
                'name' => 'User Role Manager',
                'description' => 'Assign multiple roles to WordPress users',
                'status' => 'Integrated',
            ),
            'admin-url' => array(
                'name' => 'Admin URL Customizer',
                'description' => 'Hide /wp-admin behind a secret custom URL for security',
                'status' => 'Integrated',
            ),
            'duplicate-content' => array(
                'name' => 'Content Duplicator',
                'description' => 'Duplicate posts and pages with one click',
                'status' => 'Integrated',
            ),
            'menu-editor' => array(
                'name' => 'Admin Menu Editor',
                'description' => 'Reorder and rename WordPress admin menu items',
                'status' => 'Integrated',
            ),
            'admin-footer' => array(
                'name' => 'Custom Admin Footer',
                'description' => 'Add custom branding text to admin footer',
            ),
            'admin-tweaks' => array(
                'name' => 'Admin Tweaks',
                'description' => 'Quick admin customizations: ID columns, infinite scroll, hide admin bar, remove WP logo, rename howdy',
            ),
            'content-ordering' => array(
                'name' => 'Content Ordering',
                'description' => 'Drag-and-drop reordering for posts, pages, and custom post types',
                'status' => 'Integrated',
            ),
            'notification-channels' => array(
                'name' => 'Notification Channels',
                'description' => 'WhatsApp and Google Sheets notifications for form submissions',
                'status' => 'Integrated',
            ),
            'spam-protection' => array(
                'name' => 'Spam Protection',
                'description' => 'Cloudflare Turnstile and Google reCAPTCHA v2/v3',
                'status' => 'Integrated',
            ),
            'social-login' => array(
                'name' => 'Social Login',
                'description' => 'Allow users to login with Google and Facebook accounts',
                'status' => 'Integrated',
            ),
            'login-redesign' => array(
                'name' => 'Login Redesign',
                'description' => 'Customize the WordPress login page with your logo and colors',
                'status' => 'Integrated',
            ),
        );
    }
}
