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
        // Reorder admin menu
        add_filter('custom_menu_order', '__return_true');
        add_filter('menu_order', array($this, 'reorder_admin_menu'), 999);
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
     * Reorder admin menu to place Ofast menus after WordPress Dashboard
     * Order: Dashboard, Ofast X, Ofast Emailer, Ofast SMTP, Contact Form, then rest
     */
    public function reorder_admin_menu($menu_order)
    {
        if (!$menu_order) {
            return true;
        }

        // Define our preferred order
        $ofast_menus = array(
            'ofast-dashboard',     // Ofast X
            'ofast-email',         // Ofast Emailer (if exists)
            'ofast-smtp',          // Ofast SMTP (if exists)
            'ofast-forms',         // Contact Form
        );

        // Build new order
        $new_order = array();

        // First, add WordPress Dashboard (index.php)
        if (in_array('index.php', $menu_order)) {
            $new_order[] = 'index.php';
        }

        // Add separator after dashboard
        $new_order[] = 'separator1';

        // Add Ofast menus in our preferred order
        foreach ($ofast_menus as $menu_slug) {
            if (in_array($menu_slug, $menu_order)) {
                $new_order[] = $menu_slug;
            }
        }

        // Add separator after Ofast menus
        $new_order[] = 'separator2';

        // Add remaining menus
        foreach ($menu_order as $menu) {
            if (!in_array($menu, $new_order) && $menu !== 'separator1' && $menu !== 'separator2') {
                $new_order[] = $menu;
            }
        }

        return $new_order;
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
        <style>
            .ofast-dashboard {
                max-width: 1200px;
            }

            .ofast-header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                padding: 30px;
                border-radius: 16px;
                margin-bottom: 30px;
                color: #fff;
            }

            .ofast-header h1 {
                margin: 0;
                font-size: 28px;
                font-weight: 700;
            }

            .ofast-header p {
                margin: 8px 0 0;
                opacity: 0.9;
                font-size: 15px;
            }

            .ofast-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
                gap: 16px;
                margin-bottom: 30px;
            }

            .ofast-stat-card {
                background: #fff;
                padding: 20px;
                border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
                border: 1px solid #e5e7eb;
                transition: transform 0.2s, box-shadow 0.2s;
            }

            .ofast-stat-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
            }

            .ofast-stat-card.primary {
                background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
                color: #fff;
            }

            .ofast-stat-label {
                font-size: 13px;
                opacity: 0.8;
                margin: 0;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .ofast-stat-value {
                font-size: 32px;
                font-weight: 700;
                margin: 8px 0 0;
                line-height: 1;
            }

            .ofast-stat-card.primary .ofast-stat-label {
                color: rgba(255, 255, 255, 0.85);
            }

            .ofast-section-title {
                font-size: 18px;
                font-weight: 600;
                color: #1e293b;
                margin: 0 0 16px;
                padding-bottom: 12px;
                border-bottom: 2px solid #e5e7eb;
            }

            .ofast-modules-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 12px;
            }

            .ofast-module-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                background: #fff;
                padding: 14px 18px;
                border-radius: 10px;
                border: 1px solid #e5e7eb;
            }

            .ofast-module-name {
                font-size: 14px;
                font-weight: 500;
                color: #1e293b;
            }

            .ofast-module-status {
                padding: 4px 10px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }

            .ofast-module-status.active {
                background: #dcfce7;
                color: #166534;
            }

            .ofast-quick-links {
                display: flex;
                gap: 12px;
                flex-wrap: wrap;
                margin-top: 30px;
            }

            .ofast-quick-link {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 12px 20px;
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                color: #1e293b;
                text-decoration: none;
                font-size: 14px;
                font-weight: 500;
                transition: all 0.2s;
            }

            .ofast-quick-link:hover {
                background: #f8fafc;
                border-color: #667eea;
                color: #667eea;
            }
        </style>

        <div class="wrap ofast-dashboard">
            <!-- Header -->
            <div class="ofast-header">
                <h1>Ofast X Dashboard</h1>
                <p>Welcome back! Here's an overview of your site's users and active modules.</p>
            </div>

            <!-- User Statistics -->
            <h2 class="ofast-section-title">User Statistics</h2>
            <div class="ofast-stats-grid">
                <!-- Total Users Card -->
                <div class="ofast-stat-card primary">
                    <p class="ofast-stat-label">Total Users</p>
                    <p class="ofast-stat-value"><?php echo esc_html($all_users['total_users']); ?></p>
                </div>

                <?php foreach ($all_users['avail_roles'] as $role => $count): ?>
                    <?php $label = isset($roles[$role]['name']) ? $roles[$role]['name'] : ucfirst($role); ?>
                    <div class="ofast-stat-card">
                        <p class="ofast-stat-label"><?php echo esc_html($label); ?></p>
                        <p class="ofast-stat-value" style="color: #3b82f6;"><?php echo esc_html($count); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Active Modules -->
            <h2 class="ofast-section-title">Active Modules (<?php echo count($loaded_modules); ?>)</h2>
            <div class="ofast-modules-grid">
                <?php foreach ($loaded_modules as $module): ?>
                    <div class="ofast-module-item">
                        <span class="ofast-module-name"><?php echo esc_html($module); ?></span>
                        <span class="ofast-module-status active">Active</span>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Quick Links -->
            <div class="ofast-quick-links">
                <a href="<?php echo admin_url('admin.php?page=ofast-emailer'); ?>" class="ofast-quick-link">
                    <span>Send Email</span>
                </a>
                <a href="<?php echo admin_url('admin.php?page=ofast-smtp'); ?>" class="ofast-quick-link">
                    <span>SMTP Settings</span>
                </a>
                <a href="<?php echo admin_url('admin.php?page=ofast-forms'); ?>" class="ofast-quick-link">
                    <span>Contact Forms</span>
                </a>
                <a href="<?php echo admin_url('admin.php?page=ofast-notification-channels'); ?>" class="ofast-quick-link">
                    <span>Notifications</span>
                </a>
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
            'learndash' => 'LearnDash Integration',
            'user-roles' => 'User Roles Manager',
            'admin-url' => 'Admin URL Customizer',
            'admin-footer' => 'Custom Admin Footer',
            'duplicate-content' => 'Content Duplicator',
            'menu-editor' => 'Admin Menu Editor',
            'spam-protection' => 'Spam Protection',
            'notification-channels' => 'Notification Channels',
            'social-login' => 'Social Login',
            'login-redesign' => 'Login Redesign'
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
