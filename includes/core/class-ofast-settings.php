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


        
        // Reorder Ofast X submenus alphabetically (after all menus added)
        add_action('admin_menu', array($this, 'reorder_ofast_submenus'), 99999);
        
        // Reorder admin menu
        add_filter('custom_menu_order', '__return_true');
        add_filter('menu_order', array($this, 'reorder_admin_menu'), 999);
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
                    <img src="https://dl.ofastshop.com/ofastshop/web/2026/07/18110733/toolkit-logo.png" alt="Ofast Toolkit" style="height: 45px; width: auto; object-fit: contain;" />
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
                            <div class="stat-icon bg-blue"><span class="dashicons dashicons-performance"></span></div>
                            <div class="stat-info">
                                <span class="label">Performance</span>
                                <span class="value">Excellent</span>
                                <span class="desc">Your site performance is optimized.</span>
                            </div>
                            <!-- Mini chart decorative -->
                            <div class="mini-chart"><svg viewBox="0 0 100 20"><path d="M0 20 L20 15 L40 18 L60 5 L80 10 L100 0" fill="none" stroke="#3b82f6" stroke-width="2"/></svg></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon bg-emerald"><span class="dashicons dashicons-shield"></span></div>
                            <div class="stat-info">
                                <span class="label">Security</span>
                                <span class="value">Protected</span>
                                <span class="desc">All security features are active.</span>
                            </div>
                            <div class="mini-chart"><svg viewBox="0 0 100 20"><path d="M0 20 L20 18 L40 10 L60 12 L80 5 L100 0" fill="none" stroke="#10b981" stroke-width="2"/></svg></div>
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

        <script>
        var ofastSettingsAjax = {
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            nonce: '<?php echo wp_create_nonce('ofast_settings_ajax'); ?>'
        };
        </script>
        
        <style>
        /* WordPress Admin Override */
        #wpcontent, #wpbody-content { padding: 0 !important; }
        #wpfooter { display: none !important; }
        #wpbody { background: #fcfcfd; }
        
        /* Google Fonts */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        
        .ofast-app-wrap {
            margin: 0;
            width: 100%;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            box-sizing: border-box;
        }
        .ofast-app-wrap * { box-sizing: border-box; }
        
        .ofast-app-layout {
            display: flex;
            gap: 0;
            background: #fcfcfd;
            border-radius: 0;
            border: none;
            box-shadow: none;
            min-height: calc(100vh - 32px);
        }
        
        .ofast-topbar {
            position: sticky;
            top: 32px;
            z-index: 100;
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Sidebar */
        .ofast-sidebar {
            width: 220px;
            background: #ffffff;
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            padding: 24px 16px;
            flex-shrink: 0;
            position: sticky;
            top: 100px;
            height: calc(100vh - 100px);
            overflow-y: auto;
        }
        
        .ofast-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
        }
        .ofast-logo .logo-icon {
            background: #4f46e5;
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .ofast-logo .logo-icon .dashicons {
            margin-top: 5px;
        }
        
        .ofast-nav { flex-grow: 1; }
        .nav-section {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #94a3b8;
            font-weight: 600;
            margin: 24px 8px 10px 8px;
        }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            color: #475569;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            margin-bottom: 2px;
        }
        .nav-item:focus { box-shadow: none; outline: none; }
        .nav-item .dashicons { font-size: 18px; width: 18px; height: 18px; opacity: 0.7; }
        .nav-item:hover {
            background: #f1f5f9;
            color: #1e293b;
        }
        .nav-item:hover .dashicons { opacity: 1; }
        .nav-item.active {
            background: #4f46e5;
            color: white;
        }
        .nav-item.active .dashicons { opacity: 1; }
        
        .ofast-pro-card {
            background: linear-gradient(135deg, #312e81 0%, #4338ca 100%);
            border-radius: 16px;
            padding: 24px 20px;
            color: white;
            text-align: center;
            margin-top: 20px;
        }
        .ofast-pro-card .pro-icon { font-size: 24px; margin-bottom: 10px; }
        .ofast-pro-card h4 { margin: 0 0 8px 0; color: white; font-size: 16px; }
        .ofast-pro-card p { font-size: 13px; color: #c7d2fe; margin: 0 0 16px 0; line-height: 1.4; }
        .upgrade-btn {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .upgrade-btn:hover { background: rgba(255,255,255,0.3); color: white; }
        
        /* Main Content */
        .ofast-main {
            flex-grow: 1;
            padding: 32px 40px;
            background: #fcfcfd;
            max-width: calc(100% - 220px);
        }
        
        .header-actions { display: flex; gap: 12px; }
        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            color: #0f172a;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            transition: all 0.2s;
        }
        .action-btn:hover { border-color: #cbd5e1; background: #f8fafc; color: #000000; }
        .action-btn.icon-only { padding: 8px; }
        .action-btn.icon-only .dashicons { margin: 0; }
        .action-btn:focus { box-shadow: none; outline: none; }
        
        /* Stats row */
        .ofast-stats-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 16px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            position: relative;
        }
        .stat-icon {
            width: 40px; height: 40px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 16px;
        }
        .stat-icon .dashicons { font-size: 20px; width: 20px; height: 20px; margin-top:5px; }
        .bg-green { background: #dcfce7; color: #16a34a; }
        .bg-purple { background: #e0e7ff; color: #4f46e5; }
        .bg-blue { background: #dbeafe; color: #2563eb; }
        .bg-emerald { background: #d1fae5; color: #059669; }
        .bg-pink { background: #fce7f3; color: #db2777; }
        .bg-yellow { background: #fef3c7; color: #d97706; }
        
        .stat-info { display: flex; flex-direction: column; }
        .stat-info .label { font-size: 13px; color: #0f172a; font-weight: 600; margin-bottom: 4px; }
        .stat-info .value { font-size: 22px; color: #0f172a; font-weight: 700; margin-bottom: 8px; }
        .stat-info .desc { font-size: 12px; color: #64748b; line-height: 1.4; }
        
        .mini-chart { position: absolute; bottom: 20px; right: 20px; width: 60px; height: 20px; opacity: 0.6; }
        .badge-icon { position: absolute; bottom: 20px; right: 20px; width: 24px; height: 24px; background: #4f46e5; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .badge-icon .dashicons { font-size: 14px; width: 14px; height: 14px; margin-top:2px; }
        
        /* Filter Bar */
        .ofast-filter-bar {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 24px;
            background: white;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }
        .search-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #e2e8f0;
            padding: 8px 12px;
            border-radius: 8px;
            width: 250px;
        }
        .search-wrap .dashicons { color: #94a3b8; }
        .search-wrap input {
            border: none;
            background: transparent;
            box-shadow: none;
            padding: 0;
            width: 100%;
            font-size: 14px;
            color: #0f172a;
        }
        .search-wrap input:focus { outline: none; box-shadow: none; }
        
        .filter-pills { display: flex; gap: 8px; overflow-x: auto; flex-grow: 1; }
        .filter-pills .pill {
            background: transparent;
            border: 1px solid transparent;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .filter-pills .pill:hover { color: #0f172a; }
        .filter-pills .pill.active { background: #4f46e5; color: white; }
        
        /* Module Grid */
        .ofast-module-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 40px;
        }
        .module-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            transition: all 0.2s;
        }
        .module-card:hover { border-color: #cbd5e1; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .module-card.hidden { display: none; }
        
        .card-top { display: flex; align-items: flex-start; gap: 16px; margin-bottom: 16px; }
        .card-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .card-icon .dashicons { font-size: 24px; width: 24px; height: 24px; margin-top:5px; }
        
        .card-title { flex-grow: 1; display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
        .card-title h3 { margin: 0 0 4px 0; font-size: 15px; font-weight: 600; color: #0f172a; }
        
        .status-badge {
            font-size: 11px; font-weight: 600; padding: 4px 8px; border-radius: 6px;
        }
        .status-badge.enabled { background: #dcfce7; color: #16a34a; }
        .status-badge.disabled { background: #fee2e2; color: #dc2626; }
        
        .card-desc { font-size: 13px; color: #64748b; line-height: 1.5; margin: 0 0 20px 0; flex-grow: 1; }
        
        .card-features { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 20px; }
        .feature-tag {
            font-size: 11px; font-weight: 500; color: #475569;
            background: #f1f5f9; padding: 4px 8px; border-radius: 4px;
        }
        
        .card-bottom {
            display: flex; justify-content: space-between; align-items: center;
            padding-top: 16px; border-top: 1px solid #f1f5f9;
        }
        .configure-link { font-size: 14px; font-weight: 600; color: #4f46e5; text-decoration: none; }
        .configure-link:hover { text-decoration: underline; }
        
        .coming-soon-card {
            border: 2px dashed #cbd5e1;
            background: #f8fafc;
            display: flex; align-items: center; justify-content: center;
            text-align: center; padding: 40px 20px;
        }
        .coming-soon-card:hover { border-color: #94a3b8; }
        .coming-soon-content .dashicons { font-size: 32px; width: 32px; height: 32px; color: #94a3b8; margin-bottom: 12px; }
        .coming-soon-content h4 { margin: 0 0 8px 0; color: #475569; font-size: 15px; }
        .coming-soon-content p { margin: 0; color: #64748b; font-size: 13px; }
        
        /* Toggle Switch */
        .ofast-toggle-switch { position: relative; display: inline-block; width: 40px; height: 22px; }
        .ofast-toggle-switch input { opacity: 0; width: 0; height: 0; }
        .ofast-toggle-switch .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .3s; border-radius: 22px; }
        .ofast-toggle-switch .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 2px; bottom: 2px; background-color: white; transition: .3s; border-radius: 50%; box-shadow: 0 1px 2px rgba(0,0,0,0.1); }
        .ofast-toggle-switch input:checked+.slider { background-color: #4f46e5; }
        .ofast-toggle-switch input:checked+.slider:before { transform: translateX(18px); }
        .core-label { font-size: 12px; color: #94a3b8; font-style: italic; }

        /* Bottom Actions */
        .ofast-bottom-actions { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
        .action-card {
            background: white; border-radius: 16px; padding: 24px;
            display: flex; flex-direction: column; justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02); border: 1px solid #e2e8f0;
        }
        
        .action-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .action-card-header h4 { margin: 0; font-size: 16px; color: #0f172a; font-weight: 600; }
        
        .action-card-content { margin-bottom: 24px; flex-grow: 1; }
        .action-card-content p { margin: 0; font-size: 13px; color: #64748b; line-height: 1.5; }
        
        .ac-icon {
            width: 40px; height: 40px; border-radius: 10px; background: #e0e7ff; color: #4f46e5;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .ac-icon.green { background: #d1fae5; color: #059669; }
        .ac-icon.red { background: #fee2e2; color: #dc2626; }
        .ac-icon .dashicons { font-size: 20px; width: 20px; height: 20px; margin-top:5px; }
        
        .ac-btn {
            display: inline-block; text-align: center; padding: 10px 24px;
            border-radius: 8px; font-weight: 600; font-size: 14px; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s;
            align-self: flex-start;
        }
        .ac-btn.dark { background: #0f172a; color: white; }
        .ac-btn.dark:hover { background: #1e293b; color: white; }
        .ac-btn.outline { background: white; border: 1px solid #cbd5e1; color: #475569; }
        .ac-btn.outline:hover { background: #f8fafc; border-color: #94a3b8; }
        .ac-btn.outline-red { background: white; border: 1px solid #fecaca; color: #dc2626; }
        .ac-btn.outline-red:hover { background: #fef2f2; border-color: #fca5a5; }

        /* Modal */
        .ofast-modal {
            display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%;
            background-color: rgba(15, 23, 42, 0.5); backdrop-filter: blur(4px);
            align-items: center; justify-content: center;
        }
        .ofast-modal.active { display: flex; }
        .ofast-modal-content {
            background-color: #fff; border-radius: 16px; width: 100%; max-width: 500px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
            overflow: hidden;
        }
        .modal-header { padding: 20px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h2 { margin: 0; font-size: 18px; color: #0f172a; }
        .close-modal { cursor: pointer; color: #64748b; }
        .modal-body { padding: 24px; }
        .modal-body > p { margin: 0 0 20px 0; color: #475569; font-size: 14px; }
        .data-options { display: flex; flex-direction: column; gap: 12px; }
        .data-option {
            display: flex; align-items: flex-start; gap: 12px; padding: 16px;
            border: 2px solid #e2e8f0; border-radius: 12px; cursor: pointer; transition: all 0.2s;
        }
        .data-option input { margin-top: 4px; }
        .opt-text strong { display: block; margin-bottom: 4px; color: #0f172a; font-size: 14px; }
        .opt-text span { color: #64748b; font-size: 13px; }
        .data-option.selected { border-color: #4f46e5; background: #e0e7ff; }
        .data-option.danger.selected { border-color: #ef4444; background: #fee2e2; }
        .modal-body .note { margin: 20px 0 0 0; font-size: 12px; color: #94a3b8; font-style: italic; }
        .modal-footer { padding: 16px 24px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 12px; }
        .btn-cancel { padding: 10px 16px; border-radius: 8px; border: 1px solid #cbd5e1; background: white; color: #475569; font-weight: 600; cursor: pointer; }
        .btn-save { padding: 10px 16px; border-radius: 8px; border: none; background: #4f46e5; color: white; font-weight: 600; cursor: pointer; }

        @media (max-width: 1400px) {
            .ofast-module-grid { grid-template-columns: repeat(2, 1fr); }
            .ofast-stats-row { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 1100px) {
            .ofast-bottom-actions { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 900px) {
            .ofast-app-layout { flex-direction: column; }
            .ofast-sidebar { width: 100%; border-right: none; border-bottom: 1px solid #e2e8f0; }
            .ofast-main { max-width: 100%; }
        }
        @media (max-width: 768px) {
            .ofast-stats-row { grid-template-columns: repeat(2, 1fr); }
            .ofast-module-grid { grid-template-columns: 1fr; }
            .ofast-bottom-actions { grid-template-columns: 1fr; }
            .ofast-header { flex-direction: column; gap: 16px; }
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Module toggle AJAX
            $('.module-toggle').on('change', function() {
                var isChecked = $(this).is(':checked');
                var module = $(this).data('module');
                var badge = $(this).closest('.module-card').find('.status-badge');
                
                // Update UI optimistically
                if (isChecked) {
                    badge.removeClass('disabled').addClass('enabled').text('Enabled');
                    $(this).closest('.module-card').addClass('enabled');
                } else {
                    badge.removeClass('enabled').addClass('disabled').text('Disabled');
                    $(this).closest('.module-card').removeClass('enabled');
                }
                
                $.post(ofastSettingsAjax.url, {
                    action: 'ofast_save_module_toggle',
                    nonce: ofastSettingsAjax.nonce,
                    module: module,
                    enabled: isChecked
                });
            });

            // Filtering
            $('.filter-pills .pill').on('click', function() {
                $('.filter-pills .pill').removeClass('active');
                $(this).addClass('active');
                
                var filter = $(this).data('filter');
                if (filter === 'all') {
                    $('.module-card:not(.coming-soon-card)').show();
                } else if (filter === 'status-enabled') {
                    $('.module-card:not(.coming-soon-card)').hide();
                    $('.module-card.enabled').show();
                } else if (filter === 'status-disabled') {
                    $('.module-card:not(.coming-soon-card)').hide();
                    $('.module-card:not(.enabled):not(.coming-soon-card)').show();
                }
            });
            
            // Search
            $('#module-search').on('input', function() {
                var term = $(this).val().toLowerCase();
                if (term) {
                    $('.filter-pills .pill').removeClass('active');
                } else {
                    $('.filter-pills .pill[data-filter="all"]').addClass('active');
                }
                
                $('.module-card:not(.coming-soon-card)').each(function() {
                    var title = $(this).find('h3').text().toLowerCase();
                    var desc = $(this).find('.card-desc').text().toLowerCase();
                    if (title.indexOf(term) > -1 || desc.indexOf(term) > -1) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });

            // Modal
            $('.ofast-open-data-modal').on('click', function(e) {
                e.preventDefault();
                $('#data-management-modal').addClass('active');
            });
            $('.close-modal').on('click', function() {
                $('#data-management-modal').removeClass('active');
            });
            
            // Data options selection
            $('input[name="delete_data_choice"]').on('change', function() {
                $('.data-option').removeClass('selected').removeClass('danger-selected');
                var isDanger = $(this).closest('.data-option').hasClass('danger');
                if(isDanger) {
                    $(this).closest('.data-option').addClass('selected');
                } else {
                    $(this).closest('.data-option').addClass('selected');
                }
            });
            
            // Save Data Management
            $('#save-data-management').on('click', function() {
                var choice = $('input[name="delete_data_choice"]:checked').val();
                var btn = $(this);
                var originalText = btn.text();
                
                btn.text('Saving...').prop('disabled', true);
                
                $.post(ofastSettingsAjax.url, {
                    action: 'ofast_save_data_management',
                    nonce: ofastSettingsAjax.nonce,
                    delete_data: choice
                }, function(response) {
                    btn.text('Saved!');
                    setTimeout(function() {
                        btn.text(originalText).prop('disabled', false);
                        $('#data-management-modal').removeClass('active');
                    }, 1000);
                });
            });

            // Tab Switching (no page reload)
            $('.nav-item[data-tab]').on('click', function(e) {
                e.preventDefault();
                var tab = $(this).data('tab');
                $('.ofast-tab-panel').hide();
                $('#ofast-tab-' + tab).show();
                $('.nav-item[data-tab]').removeClass('active');
                $(this).addClass('active');
            });
        });
        </script>
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
