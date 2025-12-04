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

        foreach ($modules as $slug => $name) {
            $enabled_modules[$slug] = isset($_POST['modules'][$slug]);
        }

        // Save to database
        update_option('ofastx_modules_enabled', $enabled_modules);

        // Redirect with success message
        wp_redirect(add_query_arg('settings_saved', '1', wp_get_referer()));
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
        <div class="wrap">
            <h1>⚙️ Ofast X Settings</h1>
            <p class="description">Enable or disable plugin modules. Only enabled modules will load.</p>

            <?php if ($saved): ?>
                <div class="notice notice-success is-dismissible">
                    <p>✅ Settings saved successfully!</p>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('ofast_settings_save', '_wpnonce'); ?>

                <div class="card" style="max-width: 800px; margin-top: 20px;">
                    <h2 style="margin-top: 0;">📦 Module Management</h2>
                    <p>Toggle modules on/off to customize your installation.</p>

                    <table class="form-table">
                        <?php foreach ($modules as $slug => $data): ?>
                            <tr>
                                <th scope="row">
                                    <label for="module_<?php echo esc_attr($slug); ?>">
                                        <?php echo esc_html($data['name']); ?>
                                    </label>
                                </th>
                                <td>
                                    <label class="ofast-toggle-switch">
                                        <input
                                            type="checkbox"
                                            name="modules[<?php echo esc_attr($slug); ?>]"
                                            id="module_<?php echo esc_attr($slug); ?>"
                                            value="1"
                                            <?php checked(!empty($enabled[$slug])); ?>>
                                        <span class="slider"></span>
                                    </label>
                                    <p class="description"><?php echo esc_html($data['description']); ?></p>
                                    <?php if (!empty($data['badge'])): ?>
                                        <span class="ofast-badge"><?php echo esc_html($data['badge']); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>

                <p class="submit">
                    <button type="submit" name="ofast_save_settings" class="button button-primary button-large">
                        💾 Save Settings
                    </button>
                </p>
            </form>
        </div>

        <style>
            /* Toggle Switch Styling */
            .ofast-toggle-switch {
                position: relative;
                display: inline-block;
                width: 50px;
                height: 24px;
                margin-right: 10px;
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
                background-color: #ccc;
                transition: .4s;
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
                transition: .4s;
                border-radius: 50%;
            }

            .ofast-toggle-switch input:checked+.slider {
                background-color: #2271b1;
            }

            .ofast-toggle-switch input:checked+.slider:before {
                transform: translateX(26px);
            }

            .ofast-badge {
                display: inline-block;
                background: #f0f0f1;
                color: #50575e;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                margin-left: 10px;
            }

            .ofast-badge.coming-soon {
                background: #fff3cd;
                color: #856404;
            }

            .ofast-badge.active {
                background: #d1e7dd;
                color: #0f5132;
            }
        </style>
<?php
    }

    /**
     * Get available modules
     */
    private function get_available_modules()
    {
        return array(
            'email' => array(
                'name' => 'Email Module',
                'description' => 'Send bulk emails to users with scheduling and templates',
                'badge' => 'Active'
            ),
            'debug' => array(
                'name' => 'Debug Indicator',
                'description' => 'Shows debug mode indicator in admin bar',
                'badge' => 'Active'
            ),
            'smtp' => array(
                'name' => 'SMTP Configuration',
                'description' => 'Custom SMTP settings for reliable email delivery',
                'badge' => 'Coming Soon'
            ),
            'newsletter' => array(
                'name' => 'Newsletter Manager',
                'description' => 'Create and manage email newsletters',
                'badge' => 'Coming Soon'
            ),
            'contact' => array(
                'name' => 'Contact Forms',
                'description' => 'Custom contact form builder',
                'badge' => 'Coming Soon'
            ),
            'seo' => array(
                'name' => 'SEO Optimizer',
                'description' => 'On-page SEO tools and meta management',
                'badge' => 'Coming Soon'
            ),
            'analytics' => array(
                'name' => 'Analytics Dashboard',
                'description' => 'Track site performance and user behavior',
                'badge' => 'Coming Soon'
            ),
            'backup' => array(
                'name' => 'Backup Manager',
                'description' => 'Automated database and file backups',
                'badge' => 'Coming Soon'
            ),
            'security' => array(
                'name' => 'Security Scanner',
                'description' => 'Malware scanning and security hardening',
                'badge' => 'Coming Soon'
            ),
            'performance' => array(
                'name' => 'Performance Optimizer',
                'description' => 'Caching, minification, and speed optimization',
                'badge' => 'Coming Soon'
            ),
            'woocommerce' => array(
                'name' => 'WooCommerce Integration',
                'description' => 'Enhanced WooCommerce features',
                'badge' => 'Coming Soon'
            ),
            'learndash' => array(
                'name' => 'LearnDash Integration',
                'description' => 'LMS enhancements for LearnDash',
                'badge' => 'Coming Soon'
            )
        );
    }
}
