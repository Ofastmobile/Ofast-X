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
            /* Dashboard Foundation */
            .ofast-dashboard {
                max-width: 1200px;
                padding-right: 20px;
            }

            /* Header Styles */
            .ofast-header {
                display: flex;
                align-items: center;
                gap: 20px;
                background: #fff;
                padding: 25px 30px;
                border-radius: 12px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                margin-bottom: 30px;
                margin-top: 20px;
            }
            .ofast-header-icon {
                width: 56px;
                height: 56px;
                background: #fff;
                border: 1px solid #e2e8f0;
                box-shadow: 0 2px 4px rgba(0,0,0,0.02);
                border-radius: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .ofast-header-icon .dashicons {
                font-size: 28px;
                width: 28px;
                height: 28px;
                color: #6366f1;
            }
            .ofast-header-content h1 {
                margin: 0 0 5px 0;
                font-size: 24px;
                font-weight: 700;
                color: #1e293b;
                display: block;
                padding: 0;
            }
            .ofast-header-content p {
                margin: 0;
                color: #64748b;
                font-size: 14px;
            }

            /* Stats Grid */
            .ofast-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin-bottom: 40px;
            }

            .ofast-stat-card {
                background: #fff;
                padding: 25px;
                border-radius: 16px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                border: 1px solid rgba(226, 232, 240, 0.6);
                transition: all 0.3s ease;
                display: flex;
                flex-direction: column;
                justify-content: center;
            }

            .ofast-stat-card:hover {
                transform: translateY(-4px);
                box-shadow: 0 12px 20px -5px rgba(0, 0, 0, 0.1);
                border-color: #6366f1;
            }

            .ofast-stat-card.primary {
                background: #f8fafc;
                border-left: 4px solid #6366f1;
            }

            .ofast-stat-label {
                font-size: 13px;
                color: #64748b;
                margin: 0 0 8px 0;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .ofast-stat-value {
                font-size: 36px;
                font-weight: 800;
                margin: 0;
                color: #1e293b;
                line-height: 1;
            }

            .ofast-stat-card.primary .ofast-stat-value {
                color: #6366f1;
            }

            /* Section Headers */
            .ofast-section-title {
                font-size: 18px;
                font-weight: 700;
                color: #1e293b;
                margin: 40px 0 20px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .ofast-section-title::after {
                content: '';
                flex: 1;
                height: 1px;
                background: #e2e8f0;
            }

            /* Modules Grid */
            .ofast-modules-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 15px;
            }

            .ofast-module-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                background: #fff;
                padding: 16px 20px;
                border-radius: 12px;
                border: 1px solid #e2e8f0;
                transition: all 0.2s;
            }
            .ofast-module-item:hover {
                border-color: #6366f1;
                background: #f9fafb;
            }

            .ofast-module-name {
                font-size: 14px;
                font-weight: 600;
                color: #334155;
            }

            .ofast-module-status.active {
                background: #ecfdf5;
                color: #059669;
                padding: 4px 12px;
                border-radius: 99px;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
            }

            /* Quick Links */
            .ofast-quick-links {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 15px;
                margin-top: 10px;
            }

            .ofast-quick-link {
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 18px 15px;
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                color: #475569;
                text-decoration: none;
                font-size: 14px;
                font-weight: 600;
                transition: all 0.2s;
                text-align: center;
                box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            }

            .ofast-quick-link:hover {
                background: #fff;
                border-color: #6366f1;
                color: #6366f1;
                box-shadow: 0 4px 12px rgba(99, 102, 241, 0.15);
                transform: translateY(-2px);
            }
        </style>

        <div class="wrap ofast-dashboard">
            <!-- Header -->
            <div class="ofast-header">
                <div class="ofast-header-icon">
                    <span class="dashicons dashicons-chart-bar"></span>
                </div>
                <div class="ofast-header-content">
                    <h1>Ofast X Dashboard</h1>
                    <p>Welcome back! Here's an overview of your site's users and active modules.</p>
                </div>
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
                        <p class="ofast-stat-value"><?php echo esc_html($count); ?></p>
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

            <!-- Quick Access -->
            <h3 class="ofast-section-title" style="margin-top:30px;">Quick Access</h3>
            <div class="ofast-quick-links">
                <a href="<?php echo admin_url('admin.php?page=ofast-emailer'); ?>" class="ofast-quick-link">Send Email</a>
                <a href="<?php echo admin_url('admin.php?page=ofast-smtp'); ?>" class="ofast-quick-link">SMTP Settings</a>
                <a href="<?php echo admin_url('admin.php?page=ofast-forms'); ?>" class="ofast-quick-link">Contact Forms</a>
                <a href="<?php echo admin_url('admin.php?page=ofast-notification-channels'); ?>" class="ofast-quick-link">Notifications</a>
                <a href="<?php echo admin_url('admin.php?page=ofast-snippets'); ?>" class="ofast-quick-link">Code Snippets</a>
                <a href="<?php echo admin_url('admin.php?page=ofast-login-redesign'); ?>" class="ofast-quick-link">Login Redesign</a>
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
            'forms' => 'Contact Forms',
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
            'login-redesign' => 'Login Redesign',
            'snippets' => 'Code Snippets',
            'redirects' => 'URL Redirects',
            'admin-tweaks' => 'Admin Tweaks',
            'admin-design' => 'Admin Design',
            'whatsapp' => 'WhatsApp Integration',
            'google-sheets' => 'Google Sheets',
            'content-ordering' => 'Content Ordering',
            'whos-admin' => 'Who\'s Admin'
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
