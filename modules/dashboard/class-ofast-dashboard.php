<?php

/**
 * Ofast X Dashboard Module
 * Main dashboard showing user statistics and module status
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Dashboard
{

    /**
     * Initialize dashboard
     */
    public function init()
    {
        add_action('admin_menu', array($this, 'add_dashboard_menu'));
        // Add Chat Us menu at very end (priority 9999)
        add_action('admin_menu', array($this, 'add_chat_menu'), 9999);
    }

    /**
     * Add main dashboard menu
     */
    public function add_dashboard_menu()
    {
        add_menu_page(
            'Ofast X Dashboard',
            'Ofast X',
            'manage_options',
            'ofast-dashboard',
            array($this, 'render_dashboard'),
            'dashicons-chart-bar',
            2
        );

        // Change first submenu from "Ofast Dashboard" to "Dashboard"
        add_submenu_page(
            'ofast-dashboard',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'ofast-dashboard'
        );
    }

    /**
     * Add Chat Us menu at the very end - direct link, no page
     */
    public function add_chat_menu()
    {
        global $submenu;

        $whatsapp_number = '2348069727836';
        $message = urlencode('Hello! I need help with Ofast X plugin.');
        $whatsapp_url = 'https://wa.me/' . $whatsapp_number . '?text=' . $message;

        // Add external link directly to submenu array (bypasses WordPress page creation)
        $submenu['ofast-dashboard'][] = array(
            'Chat Us',
            'read',
            $whatsapp_url
        );

        // Add CSS for button styling
        add_action('admin_head', array($this, 'chat_button_styles'));
    }

    /**
     * Add Chat Us button styles
     */
    public function chat_button_styles()
    {
?>
        <style>
            #adminmenu .toplevel_page_ofast-dashboard ul.wp-submenu a[href*="wa.me"] {
                background: #25D366 !important;
                color: #fff !important;
                border-radius: 10px !important;
                padding: 8px 12px !important;
                margin: 5px 10px !important;
                display: inline-block !important;
                transition: all 0.3s ease !important;
            }

            #adminmenu .toplevel_page_ofast-dashboard ul.wp-submenu a[href*="wa.me"]:hover {
                background: #128C7E !important;
                transform: scale(1.05) !important;
            }
        </style>
    <?php
    }

    /**
     * Render dashboard page with user role statistics
     */
    public function render_dashboard()
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions');
        }

        $roles = wp_roles()->roles;
        $all_users = count_users();
        $loaded_modules = $this->get_loaded_modules();

    ?>
        <div class="wrap">
            <h1>📊 Ofast X Dashboard</h1>
            <p class="description">Overview of your site's users and active modules</p>

            <!-- User Statistics -->
            <h2>User Statistics</h2>
            <div style="display:flex;flex-wrap:wrap;gap:20px;margin-top:20px;">

                <!-- Total Users Card -->
                <div style="flex:1;min-width:180px;background:#0073aa;color:white;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                    <h3 style="margin:0;font-size:16px;">Total Users</h3>
                    <p style="font-size:32px;font-weight:bold;margin:10px 0 0;"><?php echo esc_html($all_users['total_users']); ?></p>
                </div>

                <?php foreach ($all_users['avail_roles'] as $role => $count): ?>
                    <?php $label = isset($roles[$role]['name']) ? $roles[$role]['name'] : ucfirst($role); ?>
                    <div style="flex:1;min-width:180px;background:#f1f1f1;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.05);">
                        <h4 style="margin:0;font-size:15px;color:#333;"><?php echo esc_html($label); ?></h4>
                        <p style="font-size:24px;font-weight:bold;margin:10px 0 0;color:#0073aa;"><?php echo esc_html($count); ?></p>
                    </div>
                <?php endforeach; ?>

            </div>

            <!-- Module Status -->
            <h2 style="margin-top:40px;">Active Modules</h2>
            <div class="card" style="max-width:600px">
                <h3 style="margin-top:0;">🎉 Plugin is working! Modules loaded:</h3>
                <ul style="list-style:none;padding:0;">
                    <?php foreach ($loaded_modules as $module): ?>
                        <li style="padding:8px 0;border-bottom:1px solid #eee;">
                            ✅ <?php echo esc_html($module); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

        </div>
<?php
    }

    /**
     * Get list of loaded modules
     */
    private function get_loaded_modules()
    {
        $enabled_modules = get_option('ofastx_modules_enabled', array());
        $module_names = array(
            'email' => 'Email Module',
            'debug' => 'Debug Indicator',
            'settings' => 'Settings Module',
            'smtp' => 'SMTP Configuration',
            'newsletter' => 'Newsletter Manager',
            'contact' => 'Contact Forms',
            'seo' => 'SEO Optimizer',
            'analytics' => 'Analytics Dashboard',
            'backup' => 'Backup Manager',
            'security' => 'Security Scanner',
            'performance' => 'Performance Optimizer',
            'woocommerce' => 'WooCommerce Integration',
            'learndash' => 'LearnDash Integration'
        );

        $loaded = array();

        // Settings always loads first
        $loaded[] = 'Settings Module';

        foreach ($enabled_modules as $slug => $enabled) {
            if ($enabled && isset($module_names[$slug])) {
                $loaded[] = $module_names[$slug];
            }
        }

        return $loaded;
    }
}
