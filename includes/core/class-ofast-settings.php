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
        add_action('admin_init', array($this, 'handle_support_form'));
        add_action('wp_ajax_ofast_save_module_toggle', array($this, 'ajax_save_module_toggle'));
        add_action('wp_ajax_ofast_save_data_management', array($this, 'ajax_save_data_management'));


        
        // Enqueue settings assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Reorder Ofast X submenus alphabetically (after all menus added)
        add_action('admin_menu', array($this, 'reorder_ofast_submenus'), 99999);
        
        // Reorder admin menu
        add_filter('custom_menu_order', '__return_true');
        add_filter('menu_order', array($this, 'reorder_admin_menu'), 999);
    }

    /**
     * Enqueue CSS and JS assets for the settings page
     */
    public function enqueue_admin_assets($hook)
    {
        if (strpos($hook, 'ofast') === false) {
            return;
        }

        wp_enqueue_style('ofast-admin-css', OFAST_X_PLUGIN_URL . 'assets/css/ofast-admin.css', array(), OFAST_X_VERSION);
        wp_enqueue_script('ofast-admin-js', OFAST_X_PLUGIN_URL . 'assets/js/ofast-admin.js', array('jquery'), OFAST_X_VERSION, true);

        wp_localize_script('ofast-admin-js', 'ofastSettingsAjax', array(
            'url'   => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ofast_settings_ajax')
        ));
    }

    /**
     * Add settings submenu
     */
    public function add_settings_menu()
    {
        add_menu_page(
            'Ofast Toolkit',
            'Ofast Toolkit',
            'manage_options',
            'ofast-dashboard',
            array($this, 'render_settings_page'),
            'dashicons-chart-bar', /* Keeping the chart icon or using 'dashicons-admin-generic' */
            2
        );

        // Rename first submenu to Dashboard
        add_submenu_page(
            'ofast-dashboard',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'ofast-dashboard'
        );
    }

    /**
     * Handle support form submission
     */
    public function handle_support_form()
    {
        if (!isset($_POST['ofast_support_submit'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions', 'ofast-x'));
        }

        check_admin_referer('ofast_support_form', '_wpnonce_support');

        $support_type = sanitize_key($_POST['support_type'] ?? 'bug');
        $support_email = sanitize_email(wp_unslash($_POST['support_email'] ?? ''));
        $support_subject = sanitize_text_field(wp_unslash($_POST['support_subject'] ?? ''));
        $support_message = isset($_POST['support_message']) ? sanitize_textarea_field(wp_unslash($_POST['support_message'])) : '';

        if (empty($support_message)) {
            if (class_exists('Ofast_X_Toast')) {
                Ofast_X_Toast::add('Please describe the issue before sending your message.', 'error');
            }
            return;
        }

        $recipient = apply_filters('ofast_support_recipient_email', 'support@ofastshop.com');
        $reply_to = $support_email ?: $recipient;
        $site_name = get_bloginfo('name');
        $message_type = $support_type === 'contact' ? 'Support request' : 'Bug report';
        $subject = $support_subject ?: sprintf('[Ofast Toolkit] %s from %s', $message_type, $site_name);

        $diagnostics = array(
            'Site' => $site_name . ' (' . site_url() . ')',
            'PHP Version' => PHP_VERSION,
            'WordPress Version' => get_bloginfo('version'),
            'Plugin Version' => OFAST_X_VERSION,
            'SMTP Enabled' => get_option('ofast_smtp_enabled', false) ? 'Yes' : 'No',
            'Mailer Type' => get_option('ofast_smtp_mailer_type', 'default'),
            'Provider' => get_option('ofast_smtp_provider', 'custom'),
            'From Email' => get_option('ofast_smtp_from_email', ''),
            'Last SMTP Error' => get_option('ofast_smtp_last_error', 'None'),
        );

        $body = "Hello Ofast Support,\n\n";
        $body .= "A new {$message_type} was submitted from {$site_name}.\n\n";
        $body .= "Contact Email: {$reply_to}\n";
        $body .= "Message:\n{$support_message}\n\n";
        $body .= "System Diagnostics:\n";
        foreach ($diagnostics as $label => $value) {
            $body .= '- ' . $label . ': ' . $value . "\n";
        }
        $body .= "\nPlease reply to this email for follow-up.";

        $headers = array('Reply-To: ' . $reply_to);
        $sent = wp_mail($recipient, $subject, $body, $headers);

        if ($sent) {
            update_option('ofast_last_support_request', current_time('mysql'));
            if (class_exists('Ofast_X_Toast')) {
                Ofast_X_Toast::add('Your support request was sent successfully. We will follow up soon.', 'success');
            }
        } else {
            update_option('ofast_last_support_request', current_time('mysql'));
            if (class_exists('Ofast_X_Toast')) {
                Ofast_X_Toast::add('Your request could not be sent right now. Please contact support directly via email.', 'error');
            }
        }
    }

    /**
     * Render support page
     */
    public function render_support_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions', 'ofast-x'));
        }

        $default_email = get_option('admin_email', 'support@ofastshop.com');
        ?>
        <div class="wrap">
            <h1>Help & Support</h1>
            <p style="max-width: 800px; color: #475569;">Report a bug, request help, or contact us from anywhere inside the plugin. Your message will include useful diagnostics to make troubleshooting faster.</p>

            <div style="max-width: 900px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; margin-top: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                <form method="post">
                    <?php wp_nonce_field('ofast_support_form', '_wpnonce_support'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="support_type">What do you need?</label></th>
                            <td>
                                <select name="support_type" id="support_type" style="min-width: 220px;">
                                    <option value="bug">Report a bug</option>
                                    <option value="contact">Contact support</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="support_email">Your email</label></th>
                            <td>
                                <input type="email" name="support_email" id="support_email" value="<?php echo esc_attr($default_email); ?>" class="regular-text">
                                <p class="description">We will use this address for follow-up.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="support_subject">Subject</label></th>
                            <td>
                                <input type="text" name="support_subject" id="support_subject" value="Plugin issue report" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="support_message">Details</label></th>
                            <td>
                                <textarea name="support_message" id="support_message" rows="8" class="large-text code" placeholder="Describe the problem, what you expected, and any error messages you saw."></textarea>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" name="ofast_support_submit" value="1" class="button button-primary">Send request</button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Handle settings save
     */
    public function handle_save()
    {
        if (!isset($_POST['ofast_save_settings'])) {
            return;
        }

        // Security checks - capability first (fail fast)
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions', 'ofast-x'));
        }

        if (!wp_verify_nonce($_POST['_wpnonce'], 'ofast_settings_save')) {
            wp_die(esc_html__('Security check failed', 'ofast-x'));
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

        // Security checks - capability first (fail fast)
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions', 'ofast-x'));
        }

        if (!wp_verify_nonce($_POST['_wpnonce'], 'ofast_settings_save')) {
            wp_die(esc_html__('Security check failed', 'ofast-x'));
        }

        // Reset module enabled states to defaults
        $default_modules = array(
            'email' => true,
            'smtp' => true,
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
     * Reorder Ofast X submenus alphabetically
     * Settings stays at top
     */
    public function reorder_ofast_submenus()
    {
        global $submenu;
        
        if (!isset($submenu['ofast-dashboard']) || !is_array($submenu['ofast-dashboard'])) {
            return;
        }

        // Hide License from WP admin submenu (it's now a tab inside settings)
        remove_submenu_page('ofast-dashboard', 'ofast-license');
        
        $ofast_submenu = $submenu['ofast-dashboard'];
        
        // Extract special items
        $settings_item = null;
        $license_item = null;
        $support_item = null;
        $other_items = array();
        
        foreach ($ofast_submenu as $key => $item) {
            $menu_slug = $item[2] ?? '';
            
            // Settings is first submenu (same slug as parent)
            if ($menu_slug === 'ofast-dashboard') {
                $settings_item = $item;
            }
            elseif ($menu_slug === 'ofast-license') {
                $license_item = $item;
            }
            elseif ($menu_slug === 'ofast-support') {
                $support_item = $item;
            }
            else {
                $other_items[] = $item;
            }
        }
        
        // Sort remaining items alphabetically by menu title
        usort($other_items, function($a, $b) {
            return strcasecmp($a[0], $b[0]);
        });
        
        // Rebuild submenu: Settings first, then License, then Help & Support, then the rest
        $new_submenu = array();
        
        if ($settings_item) {
            $new_submenu[] = $settings_item;
        }
        if ($license_item) {
            $new_submenu[] = $license_item;
        }
        if ($support_item) {
            $new_submenu[] = $support_item;
        }
        
        foreach ($other_items as $item) {
            $new_submenu[] = $item;
        }
        
        $submenu['ofast-dashboard'] = $new_submenu;
    }

    /**
     * Reorder admin menu
     */
    public function reorder_admin_menu($menu_order)
    {
        if (!$menu_order) return true;

        $ofast_menus = array('ofast-dashboard');
        $new_order = array();

        if (in_array('index.php', $menu_order)) $new_order[] = 'index.php';
        $new_order[] = 'separator1';

        foreach ($ofast_menus as $menu_slug) {
            if (in_array($menu_slug, $menu_order)) $new_order[] = $menu_slug;
        }

        $new_order[] = 'separator2';

        foreach ($menu_order as $menu) {
            if (!in_array($menu, $new_order) && $menu !== 'separator1' && $menu !== 'separator2') {
                $new_order[] = $menu;
            }
        }
        return $new_order;
    }
    /**
     * Render settings page
     */
    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions', 'ofast-x'));
        }

        $modules = $this->get_available_modules();
        $enabled = get_option('ofastx_modules_enabled', array());
        $delete_data = get_option('ofast_delete_data_on_uninstall', 0);
        
        // Count active modules
        $active_count = 0;
        foreach ($modules as $slug => $data) {
            if (!empty($data['locked']) || !empty($enabled[$slug])) $active_count++;
        }
        $total_modules = count($modules);
        
        ?>
        <div class="wrap ofast-app-wrap">
            <header class="ofast-topbar">
                <div class="ofast-logo">
                    <img src="<?php echo esc_url(OFAST_X_PLUGIN_URL . 'assets/images/toolkit-logo.png'); ?>" alt="Ofast Toolkit Logo" style="height: 40px; width: auto; object-fit: contain;" />
                    <span>Ofast Toolkit</span>
                </div>
                <div class="header-actions">
                    <a href="?page=ofast-setup-wizard" class="action-btn"><span class="dashicons dashicons-admin-tools"></span> Setup Wizard</a>
                    <a href="https://toolkit.ofastshop.com/docs/index.html" target="_blank" class="action-btn"><span class="dashicons dashicons-book"></span> Documentation</a>
                    <a href="#" class="action-btn">Quick Actions</a>
                </div>
            </header>

            <div class="ofast-app-layout">
                <!-- Sidebar -->
                <aside class="ofast-sidebar">
                    <nav class="ofast-nav">
                        <a href="#" class="nav-item active" data-tab="dashboard">
                            <span class="dashicons dashicons-grid-view"></span> Dashboard
                        </a>
                        
                        <div class="nav-section">SYSTEM</div>
                        <a href="#" class="nav-item ofast-open-data-modal"><span class="dashicons dashicons-database"></span> Data Management</a>
                        <a href="#" class="nav-item" data-tab="license"><span class="dashicons dashicons-admin-network"></span> License</a>
                        <a href="#" class="nav-item" data-tab="support"><span class="dashicons dashicons-editor-help"></span> Help &amp; Support</a>
                    </nav>
                    
                    <div class="ofast-pro-card">
                        <div class="pro-icon">🚀</div>
                        <h4>Unlock More Power</h4>
                        <p>Upgrade to Pro and get access to advanced features.</p>
                        <a href="#" class="upgrade-btn">Upgrade Now</a>
                    </div>
                </aside>
                
                <!-- Main Content -->
                <main class="ofast-main">
                    <div id="ofast-tab-dashboard" class="ofast-tab-panel">
                    <div class="ofast-stats-row">
                        <div class="stat-card">
                            <div class="stat-icon bg-green"><span class="dashicons dashicons-yes"></span></div>
                            <div class="stat-info">
                                <span class="label">Plugin Health</span>
                                <span class="value">98%</span>
                                <span class="desc">Everything is running smoothly.</span>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon bg-purple"><span class="dashicons dashicons-grid-view"></span></div>
                            <div class="stat-info">
                                <span class="label">Modules Active</span>
                                <span class="value"><?php echo $active_count; ?> / <?php echo $total_modules; ?></span>
                                <span class="desc"><?php echo $active_count; ?> modules are active and working.</span>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon bg-pink"><span class="dashicons dashicons-awards"></span></div>
                            <div class="stat-info">
                                <span class="label">License Status</span>
                                <span class="value">Active</span>
                                <span class="desc">Your license is valid and active.</span>
                            </div>
                            <div class="badge-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                        </div>
                    </div>
                    
                    <div class="ofast-filter-bar">
                        <div class="search-wrap">
                            <span class="dashicons dashicons-search"></span>
                            <input type="text" id="module-search" placeholder="Search modules..." />
                        </div>
                        <div class="filter-pills">
                            <button class="pill active" data-filter="all">All Modules</button>
                            <button class="pill" data-filter="status-enabled">Active</button>
                            <button class="pill" data-filter="status-disabled">Disabled</button>
                        </div>
                    </div>
                    
                    <div class="ofast-module-grid">
                        <?php foreach ($modules as $slug => $data):
                            $is_locked = !empty($data['locked']);
                            $is_enabled = !empty($enabled[$slug]) || $is_locked;
                            $module_url = $this->get_module_admin_url($data);
                        ?>
                        <div class="module-card <?php echo $is_enabled ? 'enabled' : ''; ?>" data-category="<?php echo esc_attr($data['category'] ?? 'core'); ?>">
                            <div class="card-top">
                                <div class="card-icon <?php echo esc_attr($data['color_class'] ?? 'bg-purple'); ?>">
                                    <span class="dashicons <?php echo esc_attr($data['icon'] ?? 'dashicons-admin-generic'); ?>"></span>
                                </div>
                                <div class="card-title">
                                    <h3><?php echo esc_html($data['name']); ?></h3>
                                    <?php if ($is_enabled): ?>
                                        <span class="status-badge enabled">Enabled</span>
                                    <?php else: ?>
                                        <span class="status-badge disabled">Disabled</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <p class="card-desc"><?php echo esc_html($data['description']); ?></p>
                            
                            <div class="card-features">
                                <?php if (!empty($data['features'])): ?>
                                    <?php foreach ($data['features'] as $feature): ?>
                                        <span class="feature-tag"><?php echo esc_html($feature); ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            
                            <div class="card-bottom">
                                <?php if (!empty($module_url)): ?>
                                    <a href="<?php echo esc_url($module_url); ?>" class="configure-link">Configure</a>
                                <?php else: ?>
                                    <span></span>
                                <?php endif; ?>
                                
                                <?php if (!$is_locked): ?>
                                    <label class="ofast-toggle-switch">
                                        <input type="checkbox" class="module-toggle" data-module="<?php echo esc_attr($slug); ?>" <?php checked($is_enabled); ?>>
                                        <span class="slider"></span>
                                    </label>
                                <?php else: ?>
                                    <span class="core-label">Core</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        

                    </div>
                    
                    <div class="ofast-bottom-actions">
                        <div class="action-card">
                            <div class="action-card-header">
                                <h4>Setup Wizard</h4>
                                <div class="ac-icon"><span class="dashicons dashicons-admin-tools"></span></div>
                            </div>
                            <div class="action-card-content">
                                <p>New to Ofast Toolkit? Let's get you started.<br>Launch the setup wizard and configure the essential tools in a few simple steps.</p>
                            </div>
                            <a href="?page=ofast-setup-wizard" class="ac-btn dark">Launch Wizard</a>
                        </div>

                        <div class="action-card">
                            <div class="action-card-header">
                                <h4>Danger Zone</h4>
                                <div class="ac-icon red"><span class="dashicons dashicons-warning"></span></div>
                            </div>
                            <div class="action-card-content">
                                <p>Reset all settings.<br>This will permanently reset all settings back to default. Your data will not be removed.</p>
                            </div>
                            <form method="post" style="margin:0;">
                                <?php wp_nonce_field('ofast_settings_save', '_wpnonce'); ?>
                                <button type="submit" name="ofast_reset_settings" class="ac-btn outline-red" onclick="return confirm('Are you sure you want to reset all settings to defaults?');">Reset Settings</button>
                            </form>
                        </div>
                    </div>
                    </div><!-- /#ofast-tab-dashboard -->

                    <!-- License Tab -->
                    <div id="ofast-tab-license" class="ofast-tab-panel" style="display:none;">
                        <div style="max-width: 700px; margin: 0 auto;">
                            <?php
                            $is_pro = function_exists('ofast_toolkit_is_pro') ? ofast_toolkit_is_pro() : false;
                            $license_key = get_option('ofast_license_key', '');
                            $last_check = get_option('ofast_license_last_check', 0);
                            $license_notice = get_transient('ofast_license_notice');
                            if ($license_notice) { delete_transient('ofast_license_notice'); }
                            ?>
                            <div style="text-align: center; margin-bottom: 30px;">
                                <h1 style="font-size: 28px; font-weight: 700; margin: 0 0 8px; padding: 0;">
                                    <?php echo $is_pro ? '✅' : '🔑'; ?> Ofast Toolkit License
                                </h1>
                                <p style="color: #666; font-size: 15px; margin: 0;">
                                    <?php echo $is_pro
                                        ? 'Your Pro license is active. All premium features are unlocked.'
                                        : 'Enter your license key to unlock all Pro features.'; ?>
                                </p>
                            </div>
                            <?php if ($license_notice): ?>
                                <div style="padding: 14px 20px; border-radius: 10px; margin-bottom: 20px; font-weight: 500;
                                    background: <?php echo $license_notice['success'] ? '#ecfdf5' : '#fef2f2'; ?>;
                                    color: <?php echo $license_notice['success'] ? '#065f46' : '#991b1b'; ?>;
                                    border: 1px solid <?php echo $license_notice['success'] ? '#a7f3d0' : '#fecaca'; ?>;">
                                    <?php echo esc_html($license_notice['message']); ?>
                                </div>
                            <?php endif; ?>
                            <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                                <?php if ($is_pro): ?>
                                    <div style="text-align: center; padding: 20px 0;">
                                        <div style="display: inline-block; background: linear-gradient(135deg, #10b981, #059669); color: #fff; padding: 12px 28px; border-radius: 50px; font-size: 15px; font-weight: 600; margin-bottom: 20px;">● License Active</div>
                                        <div style="background: #f9fafb; border-radius: 12px; padding: 20px; margin: 20px 0; text-align: left;">
                                            <div style="display: flex; justify-content: space-between; margin-bottom: 12px;">
                                                <span style="color: #6b7280; font-size: 13px;">License Key</span>
                                                <code style="background: #e5e7eb; padding: 2px 10px; border-radius: 6px; font-size: 13px;"><?php echo esc_html(substr($license_key, 0, 10) . '••••••••••••'); ?></code>
                                            </div>
                                            <div style="display: flex; justify-content: space-between; margin-bottom: 12px;">
                                                <span style="color: #6b7280; font-size: 13px;">Status</span>
                                                <span style="color: #059669; font-weight: 600; font-size: 13px;">Active</span>
                                            </div>
                                            <div style="display: flex; justify-content: space-between;">
                                                <span style="color: #6b7280; font-size: 13px;">Last Verified</span>
                                                <span style="font-size: 13px;"><?php echo $last_check ? human_time_diff($last_check) . ' ago' : 'Never'; ?></span>
                                            </div>
                                        </div>
                                        <form method="post" action="" style="margin-top: 20px;">
                                            <?php wp_nonce_field('ofast_license_action', 'ofast_license_nonce'); ?>
                                            <button type="submit" name="ofast_deactivate_license" value="1" style="background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; padding: 10px 24px; border-radius: 10px; cursor: pointer; font-size: 14px; font-weight: 500;" onclick="return confirm('Deactivate this license? You can reactivate it on another site.');">Deactivate License</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <form method="post" action="">
                                        <?php wp_nonce_field('ofast_license_action', 'ofast_license_nonce'); ?>
                                        <label style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 8px; color: #374151;">License Key</label>
                                        <input type="text" name="ofast_license_key" placeholder="OFAST-XXXX-XXXX-XXXX-XXXX" value="<?php echo esc_attr($license_key); ?>" style="width: 100%; padding: 14px 16px; border: 2px solid #e5e7eb; border-radius: 12px; font-size: 15px; font-family: monospace; outline: none; transition: border-color 0.2s; box-sizing: border-box;" onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#e5e7eb'" required />
                                        <p style="color: #9ca3af; font-size: 12px; margin: 8px 0 24px;">Enter the license key you received after purchasing on ofastshop.com/user</p>
                                        <button type="submit" name="ofast_activate_license" value="1" style="width: 100%; background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; padding: 14px; border: none; border-radius: 12px; font-size: 16px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 14px rgba(99,102,241,0.35);">Activate License</button>
                                    </form>
                                    <div style="text-align: center; margin-top: 24px; padding-top: 20px; border-top: 1px solid #f3f4f6;">
                                        <p style="color: #9ca3af; font-size: 13px; margin: 0 0 8px;">Don't have a license key?</p>
                                        <a href="<?php echo esc_url(function_exists('ofast_toolkit_get_upgrade_url') ? ofast_toolkit_get_upgrade_url() : '#'); ?>" target="_blank" style="color: #6366f1; font-weight: 600; text-decoration: none; font-size: 14px;">Get Ofast Toolkit Pro →</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div><!-- /#ofast-tab-license -->

                    <!-- Support Tab -->
                    <div id="ofast-tab-support" class="ofast-tab-panel" style="display:none;">
                        <div style="max-width: 900px;">
                            <h1 style="font-size: 28px; font-weight: 700; margin: 0 0 8px; padding: 0;">Help &amp; Support</h1>
                            <p style="color: #475569; margin: 0 0 24px;">Report a bug, request help, or contact us. Your message will include useful diagnostics to make troubleshooting faster.</p>
                            <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                                <form method="post">
                                    <?php wp_nonce_field('ofast_support_form', '_wpnonce_support'); ?>
                                    <div style="margin-bottom: 20px;">
                                        <label for="support_type_tab" style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 8px; color: #374151;">What do you need?</label>
                                        <select name="support_type" id="support_type_tab" style="width: 100%; max-width: 300px; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                                            <option value="bug">Report a bug</option>
                                            <option value="contact">Contact support</option>
                                        </select>
                                    </div>
                                    <div style="margin-bottom: 20px;">
                                        <label for="support_email_tab" style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 8px; color: #374151;">Your email</label>
                                        <input type="email" name="support_email" id="support_email_tab" value="<?php echo esc_attr(get_option('admin_email', '')); ?>" style="width: 100%; max-width: 400px; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                                        <p style="color: #9ca3af; font-size: 12px; margin: 6px 0 0;">We will use this address for follow-up.</p>
                                    </div>
                                    <div style="margin-bottom: 20px;">
                                        <label for="support_subject_tab" style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 8px; color: #374151;">Subject</label>
                                        <input type="text" name="support_subject" id="support_subject_tab" value="Plugin issue report" style="width: 100%; max-width: 400px; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                                    </div>
                                    <div style="margin-bottom: 20px;">
                                        <label for="support_message_tab" style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 8px; color: #374151;">Details</label>
                                        <textarea name="support_message" id="support_message_tab" rows="8" style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; resize: vertical;" placeholder="Describe the problem, what you expected, and any error messages you saw."></textarea>
                                    </div>
                                    <button type="submit" name="ofast_support_submit" value="1" style="background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; padding: 12px 24px; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 14px rgba(99,102,241,0.35);">Send Request</button>
                                </form>
                            </div>
                        </div>
                    </div><!-- /#ofast-tab-support -->

                </main>
            </div>
            
            <!-- Data Management Modal -->
            <div id="data-management-modal" class="ofast-modal">
                <div class="ofast-modal-content">
                    <div class="modal-header">
                        <h2>Data Management</h2>
                        <span class="dashicons dashicons-no-alt close-modal"></span>
                    </div>
                    <div class="modal-body">
                        <p>Control what happens to your data when the plugin is deleted.</p>
                        <div class="data-options">
                            <label class="data-option <?php echo !$delete_data ? 'selected' : ''; ?>">
                                <input type="radio" name="delete_data_choice" value="0" <?php checked($delete_data, 0); ?>>
                                <div class="opt-text">
                                    <strong>Keep All Data</strong>
                                    <span>Database tables and settings will be preserved.</span>
                                </div>
                            </label>
                            <label class="data-option danger <?php echo $delete_data ? 'selected' : ''; ?>">
                                <input type="radio" name="delete_data_choice" value="1" <?php checked($delete_data, 1); ?>>
                                <div class="opt-text">
                                    <strong>Remove All Data</strong>
                                    <span>Complete cleanup when uninstalled.</span>
                                </div>
                            </label>
                        </div>
                        <p class="note">Note: This setting only takes effect when the plugin is deleted (not just deactivated).</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-cancel close-modal">Cancel</button>
                        <button type="button" class="btn-save" id="save-data-management">Save Preferences</button>
                    </div>
                </div>
            </div>
        </div>

        
        
        
        
        
        <?php
    }
private function get_module_admin_url($module)
    {
        if (empty($module['admin_url'])) {
            return '';
        }

        return admin_url(ltrim($module['admin_url'], '/'));
    }

    /**
     * Get available modules with categories
     */
    private function get_available_modules()
    {
        return array(
            'dashboard' => array(
                'name' => 'Dashboard Module',
                'description' => 'View user counts by role, recent activity, and system stats at a glance',
                'category' => 'core',
                'locked' => true,
                'admin_url' => 'admin.php?page=ofast-dashboard',
                'icon' => 'dashicons-grid-view',
                'color_class' => 'bg-purple',
                'features' => array('User Stats', 'Activity Log')
            ),
            'admin-tweaks' => array(
                'name' => 'Admin Studio',
                'description' => 'Customize WordPress admin area, menus, roles, admin URL, and more.',
                'category' => 'customization',
                'admin_url' => 'admin.php?page=ofast-admin-tweaks',
                'icon' => 'dashicons-admin-users',
                'color_class' => 'bg-blue',
                'features' => array('User Roles', 'Admin URL', 'Menu Editor')
            ),
            'email' => array(
                'name' => 'Email Module',
                'description' => 'Send beautiful emails, manage templates and email settings.',
                'category' => 'communication',
                'admin_url' => 'admin.php?page=ofast-emailer',
                'icon' => 'dashicons-email-alt',
                'color_class' => 'bg-blue',
                'features' => array('Templates', 'Bulk Email', 'Scheduling')
            ),
            'smtp' => array(
                'name' => 'SMTP Configuration',
                'description' => 'Configure SMTP providers like SendGrid, Mailgun, Zoho and more.',
                'category' => 'communication',
                'admin_url' => 'admin.php?page=ofast-smtp',
                'icon' => 'dashicons-database-export',
                'color_class' => 'bg-yellow',
                'features' => array('Reliable', 'Secure', 'Fast Delivery')
            ),
            'sms-channel' => array(
                'name' => 'SMS Channel',
                'description' => 'Send SMS via Twilio, Africa\'s Talking, Termii or SmartSMSSolutions.',
                'category' => 'communication',
                'admin_url' => 'admin.php?page=ofast-sms',
                'icon' => 'dashicons-smartphone',
                'color_class' => 'bg-green',
                'features' => array('Twilio', 'Termii', 'Bulk SMS')
            ),
            'spam-protection' => array(
                'name' => 'Spam Protection',
                'description' => 'Protect your site with Turnstile, reCAPTCHA and math challenge.',
                'category' => 'security',
                'admin_url' => 'admin.php?page=ofast-spam-protection',
                'icon' => 'dashicons-shield',
                'color_class' => 'bg-green',
                'features' => array('Cloudflare', 'reCAPTCHA', 'Math')
            ),
            'login-redesign' => array(
                'name' => 'Login Redesign',
                'description' => 'Customize your WordPress login page with your brand identity.',
                'category' => 'security',
                'admin_url' => 'admin.php?page=ofast-login-redesign',
                'icon' => 'dashicons-lock',
                'color_class' => 'bg-pink',
                'features' => array('Custom Login', 'Branding', 'Styles')
            ),
            'snippets' => array(
                'name' => 'Code Snippets',
                'description' => 'Add and manage code snippets with visual editor and conditions.',
                'category' => 'developer',
                'admin_url' => 'admin.php?page=ofast-snippets',
                'icon' => 'dashicons-editor-code',
                'color_class' => 'bg-purple',
                'features' => array('PHP', 'CSS', 'JS')
            ),
            'redirects' => array(
                'name' => 'Redirects Manager',
                'description' => 'Manage 301/302/307 redirects and track redirect analytics.',
                'category' => 'marketing',
                'admin_url' => 'admin.php?page=ofast-redirects',
                'icon' => 'dashicons-external',
                'color_class' => 'bg-blue',
                'features' => array('301 Redirect', '404 Monitor', 'Import/Export')
            ),
            'forms' => array(
                'name' => 'Contact Forms',
                'description' => 'Build beautiful contact forms with submissions management.',
                'category' => 'marketing',
                'admin_url' => 'admin.php?page=ofast-forms',
                'icon' => 'dashicons-feedback',
                'color_class' => 'bg-yellow',
                'features' => array('Builder', 'Storage', 'Notifications')
            ),
            'white-label' => array(
                'name' => 'White Label',
                'description' => 'White label your plugin with custom branding and footer text.',
                'category' => 'customization',
                'admin_url' => 'admin.php?page=ofast-white-label',
                'icon' => 'dashicons-art',
                'color_class' => 'bg-emerald',
                'features' => array('Branding', 'Footer Text', 'Admin Security')
            ),
            'utilities' => array(
                'name' => 'Utilities',
                'description' => 'Import/Export settings, system info and other useful tools.',
                'category' => 'developer',
                'admin_url' => '#',
                'icon' => 'dashicons-admin-tools',
                'color_class' => 'bg-blue',
                'features' => array('Export', 'Import', 'System Info')
            )
        );    }
}
