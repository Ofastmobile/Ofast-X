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

        // Add Chat Us menu at very end (priority 9999)
        add_action('admin_menu', array($this, 'add_chat_menu'), 9999);
        
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

        // Rename first submenu to Settings
        add_submenu_page(
            'ofast-dashboard',
            'Settings',
            'Settings',
            'manage_options',
            'ofast-dashboard'
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
            'debug' => true,
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
     * Add Chat Us menu at the very end
     */
    public function add_chat_menu()
    {
        global $submenu;
        $whatsapp_number = '2348069727836';
        $message = urlencode('Hello! I need help with Ofast X plugin.');
        $whatsapp_url = 'https://wa.me/' . $whatsapp_number . '?text=' . $message;

        $submenu['ofast-dashboard'][] = array(
            'Chat Us',
            'read',
            $whatsapp_url
        );
        add_action('admin_head', array($this, 'chat_button_styles'));
    }

    /**
     * Chat Button Styles
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
     * Reorder Ofast X submenus alphabetically
     * Settings stays at top, Chat Us stays at bottom
     */
    public function reorder_ofast_submenus()
    {
        global $submenu;
        
        if (!isset($submenu['ofast-dashboard']) || !is_array($submenu['ofast-dashboard'])) {
            return;
        }
        
        $ofast_submenu = $submenu['ofast-dashboard'];
        
        // Extract special items
        $settings_item = null;
        $chat_item = null;
        $other_items = array();
        
        foreach ($ofast_submenu as $key => $item) {
            $menu_title = $item[0] ?? '';
            $menu_slug = $item[2] ?? '';
            
            // Settings is first submenu (same slug as parent)
            if ($menu_slug === 'ofast-dashboard') {
                $settings_item = $item;
            }
            // Chat Us has WhatsApp URL
            elseif (strpos($menu_slug, 'wa.me') !== false) {
                $chat_item = $item;
            }
            else {
                $other_items[] = $item;
            }
        }
        
        // Sort other items alphabetically by menu title
        usort($other_items, function($a, $b) {
            return strcasecmp($a[0], $b[0]);
        });
        
        // Rebuild submenu: Settings first, sorted items, Chat Us last
        $new_submenu = array();
        
        if ($settings_item) {
            $new_submenu[] = $settings_item;
        }
        
        foreach ($other_items as $item) {
            $new_submenu[] = $item;
        }
        
        if ($chat_item) {
            $new_submenu[] = $chat_item;
        }
        
        $submenu['ofast-dashboard'] = $new_submenu;
    }

    /**
     * Reorder admin menu
     */
    public function reorder_admin_menu($menu_order)
    {
        if (!$menu_order) return true;

        $ofast_menus = array('ofast-dashboard', 'ofast-email', 'ofast-smtp', 'ofast-forms');
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
        $saved = isset($_GET['settings_saved']);

?>
        <div class="wrap ofast-settings-wrap all-categories-view">
            <!-- Modern Header - Unified Style -->
            <div class="ofast-page-header">
                <div class="ofast-header-content">
                    <div class="ofast-header-icon">
                        <span class="dashicons dashicons-admin-generic"></span>
                    </div>
                    <div class="ofast-header-text">
                        <h1>Ofast Toolkit Settings</h1>
                        <p>Manage your plugin modules and view system status.</p>
                    </div>
                </div>
            </div>

            <!-- Stats Section -->
            <?php
            $roles = wp_roles()->roles;
            $all_users = count_users();
            $total_users = $all_users['total_users'];
            
            // Simulation Mode for Testing
            if (isset($_GET['sim_roles'])) {
                for ($i = 1; $i <= 15; $i++) {
                    $all_users['avail_roles']['test_role_' . $i] = rand(10, 500);
                    $roles['test_role_' . $i] = array('name' => 'Test Role ' . $i);
                }
            }

            // Prepare Data
            $visible_limit = 5;
            $role_counts = $all_users['avail_roles'];
            $visible_roles = array_slice($role_counts, 0, $visible_limit, true);
            $hidden_roles = array_slice($role_counts, $visible_limit, null, true);
            ?>

            <div class="ofast-stats-container">
                <div class="ofast-stats-row">
                     <div class="ofast-stat-item total">
                        <span class="label">Total Users</span>
                        <span class="value"><?php echo esc_html($total_users); ?></span>
                     </div>
                     <?php foreach ($visible_roles as $role => $role_count): 
                        $label = isset($roles[$role]['name']) ? $roles[$role]['name'] : ucfirst($role);
                     ?>
                     <div class="ofast-stat-item">
                        <span class="label"><?php echo esc_html($label); ?></span>
                        <span class="value"><?php echo esc_html($role_count); ?></span>
                     </div>
                     <?php endforeach; ?>

                     <?php if (!empty($hidden_roles)): ?>
                     <div class="ofast-stat-item expand-trigger" id="ofast-show-more-roles">
                        <span class="dashicons dashicons-plus"></span>
                        <span class="label"><?php echo count($hidden_roles); ?> More</span>
                     </div>
                     <?php endif; ?>
                </div>

                <?php if (!empty($hidden_roles)): ?>
                <div class="ofast-stats-row hidden-row" id="ofast-hidden-roles" style="display: none; margin-top: 15px;">
                     <?php foreach ($hidden_roles as $role => $role_count): 
                        $label = isset($roles[$role]['name']) ? $roles[$role]['name'] : ucfirst($role);
                     ?>
                     <div class="ofast-stat-item secondary">
                        <span class="label"><?php echo esc_html($label); ?></span>
                        <span class="value"><?php echo esc_html($role_count); ?></span>
                     </div>
                     <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <script>
            jQuery(document).ready(function($) {
                $('#ofast-show-more-roles').on('click', function() {
                    $('#ofast-hidden-roles').slideToggle();
                    // Also toggle class on parent row for mobile CSS handling
                    $(this).closest('.ofast-stats-row').toggleClass('expanded');
                    
                    $(this).toggleClass('active');
                    var icon = $(this).find('.dashicons');
                    if ($(this).hasClass('active')) {
                        icon.removeClass('dashicons-plus').addClass('dashicons-minus');
                    } else {
                        icon.removeClass('dashicons-minus').addClass('dashicons-plus');
                    }
                });
            });
            </script>

            <?php if ($saved): ?>
                <?php echo Ofast_X_Toast::render('Settings saved successfully!', 'success'); ?>
            <?php endif; ?>

            <?php if (isset($_GET['settings_reset'])): ?>
                <?php echo Ofast_X_Toast::render('All settings have been reset to defaults!', 'warning'); ?>
            <?php endif; ?>

            <!-- Toolbar: Search, Filters, Category Tabs -->
            <div class="ofast-toolbar">
                <div class="ofast-search-box">
                    <input type="text" id="ofast-search" placeholder="Search modules..." />
                    <span class="dashicons dashicons-search"></span>
                </div>
                
                <div class="ofast-filters">
                    <?php
                    $total_count = count($modules);
                    $enabled_count = 0;
                    foreach ($modules as $slug => $data) {
                        if (!empty($data['locked']) || !empty($enabled[$slug])) $enabled_count++;
                    }
                    $disabled_count = $total_count - $enabled_count;
                    ?>
                    <button type="button" class="ofast-filter active" data-filter="all">All <span class="count">(<?php echo $total_count; ?>)</span></button>
                    <button type="button" class="ofast-filter" data-filter="enabled">Enabled <span class="count">(<?php echo $enabled_count; ?>)</span></button>
                    <button type="button" class="ofast-filter" data-filter="disabled">Disabled <span class="count">(<?php echo $disabled_count; ?>)</span></button>
                </div>
                
                <div class="ofast-category-tabs">
                    <button type="button" class="ofast-tab active" data-category="all">All</button>
                    <button type="button" class="ofast-tab" data-category="communication"> Communication</button>
                    <button type="button" class="ofast-tab" data-category="security"> Security</button>
                    <button type="button" class="ofast-tab" data-category="content"> Content</button>
                    <button type="button" class="ofast-tab" data-category="customization"> Customization</button>
                    <button type="button" class="ofast-tab" data-category="utility"> Utility</button>
                </div>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('ofast_settings_save', '_wpnonce'); ?>

                <?php
                // Group modules by category
                $categories = array(
                    'customization' => array('icon' => 'dashicons-admin-appearance', 'title' => 'Customization Features'),
                    'communication' => array('icon' => 'dashicons-email', 'title' => 'Communication Features'),
                    'content' => array('icon' => 'dashicons-edit', 'title' => 'Content Management'),
                    'security' => array('icon' => 'dashicons-lock', 'title' => 'Security Features'),
                    'utility' => array('icon' => 'dashicons-admin-tools', 'title' => 'Utility Features'),
                );
                
                $grouped_modules = array();
                foreach ($modules as $slug => $data) {
                    $cat = $data['category'] ?? 'utility';
                    $grouped_modules[$cat][$slug] = $data;
                }
                ?>
                <div class="ofast-all-modules-container">
                <?php
                foreach ($categories as $cat_key => $cat_info):
                    if (empty($grouped_modules[$cat_key])) continue;
                ?>
                <div class="ofast-category-section" data-category="<?php echo esc_attr($cat_key); ?>">
                    <h2 class="ofast-category-title">
                        <span class="dashicons <?php echo esc_attr($cat_info['icon']); ?>"></span>
                        <?php echo esc_html($cat_info['title']); ?>
                    </h2>
                    
                    <div class="ofast-modules-grid">
                        <?php foreach ($grouped_modules[$cat_key] as $slug => $data):
                            $is_locked = !empty($data['locked']);
                            $is_enabled = !empty($enabled[$slug]) || $is_locked;
                            $card_class = $is_enabled ? 'enabled' : '';
                            if ($is_locked) $card_class .= ' locked';
                        ?>
                        <div class="ofast-module-card <?php echo esc_attr($card_class); ?>" data-module="<?php echo esc_attr($slug); ?>">
                            <div class="module-header">
                                <h3><?php echo esc_html($data['name']); ?></h3>
                                <?php if ($is_locked): ?>
                                    <span class="ofast-badge active">Always On</span>
                                <?php elseif ($is_enabled): ?>
                                    <span class="ofast-badge integrated">Enabled</span>
                                <?php else: ?>
                                    <span class="ofast-badge not-integrated">Disabled</span>
                                <?php endif; ?>
                            </div>
                            <p class="module-description"><?php echo esc_html($data['description']); ?></p>
                            <div class="module-footer">
                                <?php if ($is_locked): ?>
                                    <span class="always-active">Core Module</span>
                                <?php else: ?>
                                    <label class="ofast-toggle-switch">
                                        <input type="checkbox" name="modules[<?php echo esc_attr($slug); ?>]" value="1" <?php checked($is_enabled); ?>>
                                        <span class="slider"></span>
                                    </label>
                                    <span class="toggle-label"><?php echo $is_enabled ? 'Enabled' : 'Disabled'; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
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
                                <span style="color: #64748b; font-size: 13px;">Database tables and settings will be preserved.</span>
                            </div>
                        </label>

                        <label style="display: flex; align-items: flex-start; gap: 12px; padding: 15px 20px; background: <?php echo $delete_data ? '#fef2f2' : '#f8fafc'; ?>; border: 2px solid <?php echo $delete_data ? '#ef4444' : '#e5e7eb'; ?>; border-radius: 10px; cursor: pointer; flex: 1; min-width: 250px;">
                            <input type="radio" name="ofast_delete_data_on_uninstall" value="1" <?php checked($delete_data, 1); ?> style="margin-top: 3px;">
                            <div>
                                <strong style="display: block; color: #1e293b; font-size: 14px;">Remove All Data</strong>
                                <span style="color: #64748b; font-size: 13px;">Complete cleanup when uninstalled.</span>
                            </div>
                        </label>
                    </div>
                    <p style="margin: 15px 0 0 0; font-style: italic; color: #64748b; font-size: 13px;">Note: This setting only takes effect when the plugin is deleted (not just deactivated). Deactivating the plugin will never remove your data.</p>
                    
                    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between;">
                        <div>
                            <strong style="display: block; color: #1e293b; font-size: 14px; margin-bottom: 4px;">Setup Wizard</strong>
                            <span style="color: #64748b; font-size: 13px;">Relaunch the setup wizard to quickly configure core features.</span>
                        </div>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=ofast-setup-wizard')); ?>" class="ofast-btn-secondary" style="padding: 10px 20px; text-decoration: none; border: 1px solid #cbd5e1; border-radius: 8px; color: #475569; font-weight: 600; font-size: 13px; transition: all 0.2s;">
                            <span class="dashicons dashicons-admin-generic" style="vertical-align: middle; margin-right: 5px; font-size: 16px; height: 16px; width: 16px;"></span>
                            Launch Wizard
                        </a>
                    </div>
                </div>

                <p class="submit" style="margin-top: 30px; display: flex; gap: 15px; align-items: center;">
                    <button type="submit" name="ofast_save_settings" class="ofast-save-btn"> Save All Settings</button>
                    <button type="submit" name="ofast_reset_settings" class="ofast-reset-btn" onclick="return confirm('Are you sure you want to reset all settings to defaults?\n\nThis will:\n• Disable most modules\n• Reset data management setting\n\nYour DATA (snippets, redirects, forms, emails, etc.) will NOT be deleted.');">Reset to Default</button>
                </p>
            </form>

            <?php do_action('ofast_settings_after_modules'); ?>
        </div>

        <style>
            .ofast-settings-wrap { max-width: 1400px; }

            /* Page Header - Unified Style */
            .ofast-page-header {
                background: #ffffff;
                border-radius: 16px;
                padding: 30px;
                margin-bottom: 30px;
                border: 1px solid #e2e8f0;
                box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            }
            .ofast-header-content {
                display: flex;
                align-items: center;
                gap: 20px;
            }
            
            /* Stats Row */
            .ofast-stats-container { margin-bottom: 30px; }
            .ofast-stats-row {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
                gap: 15px;
            }
            @media (max-width: 600px) {
                /* On mobile, force grid to show 2 columns */
                .ofast-stats-row {
                    grid-template-columns: 1fr 1fr;
                }
                /* Hide items after the first 3 (Total + 2 Roles) on mobile initially */
                .ofast-stats-row > .ofast-stat-item:nth-child(n+4) {
                    display: none;
                }
                /* Always show the expand trigger if it exists */
                .ofast-stats-row > .ofast-stat-item.expand-trigger {
                    display: flex !important;
                    grid-column: span 2; /* Make button full width on mobile */
                }
                /* When expanded, show all items */
                .ofast-stats-row.expanded > .ofast-stat-item {
                    display: flex !important;
                }
            }
            .ofast-stat-item {
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 10px;
                padding: 15px 20px;
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                justify-content: center;
                box-shadow: 0 1px 2px rgba(0,0,0,0.03);
                transition: all 0.2s ease;
            }
            .ofast-stat-item:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
                border-color: #cbd5e1;
            }
            .ofast-stat-item.total {
                background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
                border: none;
                color: #fff;
                grid-column: span 1; /* Ensure it doesn't break grid */
            }
            .ofast-stat-item.total .label { color: rgba(255,255,255,0.9); }
            .ofast-stat-item.total .value { color: #fff; }
            
            .ofast-stat-item.secondary { background: #f8fafc; }

            .ofast-stat-item.expand-trigger {
                cursor: pointer;
                background: #f1f5f9;
                border-style: dashed;
                align-items: center;
                justify-content: center;
            }
            .ofast-stat-item.expand-trigger:hover {
                background: #e2e8f0;
                border-color: #94a3b8;
            }
            .ofast-stat-item.expand-trigger .dashicons {
                font-size: 24px;
                width: 24px;
                height: 24px;
                color: #64748b;
                margin-bottom: 5px;
            }
            
            .ofast-stat-item .label {
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: #64748b;
                font-weight: 600;
                margin-bottom: 5px;
            }
            .ofast-stat-item .value {
                font-size: 24px;
                font-weight: 700;
                color: #1e293b;
                line-height: 1;
            }
            .ofast-header-icon {
                width: 60px;
                height: 60px;
                background: #ffffff;
                border-radius: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 4px 15px rgba(99, 102, 241, 0.2); /* Matching brand color */
                border: 1px solid #e2e8f0;
                color: #6366f1; /* Matching brand color */
            }
            .ofast-header-icon .dashicons {
                font-size: 28px;
                width: 28px;
                height: 28px;
            }
            .ofast-header-text h1 {
                margin: 0;
                font-size: 28px;
                font-weight: 700;
                color: #1e293b;
            }
            .ofast-header-text p {
                margin: 5px 0 0;
                color: #64748b;
                font-size: 15px;
            }

            
            /* Toolbar */
            .ofast-toolbar { background: #fff; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .ofast-search-box { position: relative; margin-bottom: 15px; }
            #ofast-search { width: 100%; padding: 12px 40px 12px 15px; border: 2px solid #e0e0e0; border-radius: 6px; font-size: 15px; }
            #ofast-search:focus { border-color: #6366f1; outline: none; }
            .ofast-search-box .dashicons { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #999; }
            
            /* Filters */
            .ofast-filters { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; }
            .ofast-filter { padding: 8px 16px; border: 2px solid #e0e0e0; background: #fff; border-radius: 6px; cursor: pointer; transition: all 0.2s; }
            .ofast-filter:hover { border-color: #6366f1; }
            .ofast-filter.active { background: #6366f1; color: #fff; border-color: #6366f1; }
            .ofast-filter .count { opacity: 0.7; font-size: 0.9em; }
            
            /* Category Tabs */
            .ofast-category-tabs { display: flex; gap: 5px; border-bottom: 2px solid #e0e0e0; overflow-x: auto; }
            .ofast-tab { padding: 10px 16px; border: none; background: transparent; cursor: pointer; white-space: nowrap; border-bottom: 3px solid transparent; transition: all 0.2s; }
            .ofast-tab:hover { background: #f5f5f5; }
            .ofast-tab.active { border-bottom-color: #6366f1; color: #6366f1; font-weight: 600; }
            
            /* Category Sections */
            .ofast-category-section { margin: 30px 0; }
            .ofast-category-section.hidden { display: none; }
            .ofast-category-title { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #e0e0e0; font-size: 18px; }
            .ofast-category-title .dashicons { font-size: 22px; width: 22px; height: 22px; color: #6366f1; }

            /* Desktop: hide category titles when "All" tab is active */
            @media (min-width: 769px) {
                .ofast-settings-wrap.all-categories-view .ofast-category-title { display: none; }
                .ofast-settings-wrap.all-categories-view .ofast-all-modules-container {
                    display: grid;
                    grid-template-columns: repeat(3, 1fr);
                    gap: 20px;
                }
                .ofast-settings-wrap.all-categories-view .ofast-category-section {
                    display: contents;
                }
                .ofast-settings-wrap.all-categories-view .ofast-modules-grid {
                    display: contents;
                }
                .ofast-settings-wrap.all-categories-view .ofast-category-section.hidden {
                    display: none;
                }
            }
            
            /* Modules Grid */
            .ofast-modules-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }
            @media (max-width: 768px) { .ofast-modules-grid { grid-template-columns: 1fr; } }
            
            /* Module Card */
            .ofast-module-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; transition: all 0.3s ease; display: flex; flex-direction: column; }
            .ofast-module-card.hidden { display: none; }
            .ofast-module-card:hover { box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); transform: translateY(-2px); }
            .ofast-module-card.enabled { border-color: #6366f1; background: linear-gradient(to bottom, #eef2ff, #fff); }
            .ofast-module-card.locked { border-color: #6366f1; background: linear-gradient(to bottom, #eef2ff, #fff); }
            
            .module-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; gap: 10px; }
            .module-header h3 { margin: 0; font-size: 15px; font-weight: 600; color: #1e293b; }
            .module-description { color: #64748b; font-size: 13px; line-height: 1.5; margin: 0 0 15px 0; flex-grow: 1; }
            .module-footer { display: flex; align-items: center; gap: 10px; padding-top: 15px; border-top: 1px solid #f1f5f9; }
            .toggle-label, .always-active { font-size: 12px; color: #64748b; }
            .always-active { font-style: italic; }

            /* Toggle Switch */
            .ofast-toggle-switch { position: relative; display: inline-block; width: 44px; height: 24px; }
            .ofast-toggle-switch input { opacity: 0; width: 0; height: 0; }
            .ofast-toggle-switch .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .3s; border-radius: 24px; }
            .ofast-toggle-switch .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
            .ofast-toggle-switch input:checked+.slider { background-color: #6366f1; }
            .ofast-toggle-switch input:checked+.slider:before { transform: translateX(20px); }

            /* Badges */
            .ofast-badge { display: inline-block; padding: 3px 8px; border-radius: 6px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
            .ofast-badge.integrated { background: #d4edda; color: #155724; }
            .ofast-badge.not-integrated { background: #f8d7da; color: #721c24; }
            .ofast-badge.active { background: #ede9fe; color: #6d28d9; }

            /* Buttons */
            .ofast-save-btn { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); color: #fff; border: none; padding: 14px 32px; font-size: 15px; font-weight: 600; border-radius: 10px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3); }
            .ofast-save-btn:hover { background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%); box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4); transform: translateY(-2px); }
            .ofast-reset-btn { background: #fff; color: #ef4444; border: 2px solid #fecaca; padding: 12px 24px; font-size: 14px; font-weight: 600; border-radius: 10px; cursor: pointer; transition: all 0.3s ease; }
            .ofast-reset-btn:hover { background: #fef2f2; border-color: #ef4444; }
        </style>

        <script>
            jQuery(document).ready(function($) {
                // Toggle label update
                $('.ofast-toggle-switch input').on('change', function() {
                    var label = $(this).closest('.module-footer').find('.toggle-label');
                    var card = $(this).closest('.ofast-module-card');
                    var badge = card.find('.module-header .ofast-badge');
                    if (this.checked) {
                        label.text('Enabled');
                        card.addClass('enabled');
                        badge.removeClass('not-integrated').addClass('integrated').text('Enabled');
                    } else {
                        label.text('Disabled');
                        card.removeClass('enabled');
                        badge.removeClass('integrated').addClass('not-integrated').text('Disabled');
                    }
                    updateCounts();
                });

                // Search functionality
                $('#ofast-search').on('input', function() {
                    var term = $(this).val().toLowerCase();
                    $('.ofast-module-card').each(function() {
                        var title = $(this).find('h3').text().toLowerCase();
                        var desc = $(this).find('.module-description').text().toLowerCase();
                        $(this).toggleClass('hidden', !title.includes(term) && !desc.includes(term));
                    });
                    updateSectionVisibility();
                });

                // Filter buttons
                $('.ofast-filter').on('click', function() {
                    $('.ofast-filter').removeClass('active');
                    $(this).addClass('active');
                    var filter = $(this).data('filter');
                    $('.ofast-module-card').each(function() {
                        var enabled = $(this).hasClass('enabled') || $(this).hasClass('locked');
                        if (filter === 'all') $(this).removeClass('hidden');
                        else if (filter === 'enabled') $(this).toggleClass('hidden', !enabled);
                        else if (filter === 'disabled') $(this).toggleClass('hidden', enabled);
                    });
                    updateSectionVisibility();
                });

                // Category tabs
                $('.ofast-tab').on('click', function() {
                    $('.ofast-tab').removeClass('active');
                    $(this).addClass('active');
                    var category = $(this).data('category');
                    var $wrap = $('.ofast-settings-wrap');
                    if (category === 'all') {
                        $('.ofast-category-section').removeClass('hidden');
                        $wrap.addClass('all-categories-view');
                    } else {
                        $('.ofast-category-section').addClass('hidden');
                        $('.ofast-category-section[data-category="' + category + '"]').removeClass('hidden');
                        $wrap.removeClass('all-categories-view');
                    }
                });

                function updateSectionVisibility() {
                    $('.ofast-category-section').each(function() {
                        var visible = $(this).find('.ofast-module-card:not(.hidden)').length;
                        $(this).toggleClass('hidden', visible === 0);
                    });
                }

                function updateCounts() {
                    var enabled = $('.ofast-module-card.enabled, .ofast-module-card.locked').length;
                    var total = $('.ofast-module-card').length;
                    $('.ofast-filter[data-filter="enabled"] .count').text('(' + enabled + ')');
                    $('.ofast-filter[data-filter="disabled"] .count').text('(' + (total - enabled) + ')');
                }
            });
        </script>
<?php
    }

    /**
     * Get available modules with categories
     */
    private function get_available_modules()
    {
        return array(
            // === CUSTOMIZATION ===
            'dashboard' => array(
                'name' => 'Dashboard Module',
                'description' => 'View user counts by role, recent activity, and system stats at a glance',
                'category' => 'customization',
                'locked' => true,
            ),
            'admin-tweaks' => array(
                'name' => 'Admin Studio',
                'description' => 'Admin customizations including User Roles, Menu Editor, Admin URL, Admin Design, and more',
                'category' => 'customization',
            ),
            // NOTE: Admin Footer module removed - footer text settings now in White Label (whos-admin module)
            // Dark Mode and Custom Dashboard toggles are handled within Admin Footer module internally
            
            // === COMMUNICATION ===
            'email' => array(
                'name' => 'Email Module',
                'description' => 'Send personalized bulk emails to users by role, with scheduling and templates',
                'category' => 'communication',
            ),
            'smtp' => array(
                'name' => 'SMTP Configuration',
                'description' => 'Configure SendGrid, Mailgun, Zoho, or Gmail to ensure emails reach inboxes',
                'category' => 'communication',
            ),
            'forms' => array(
                'name' => 'Contact Forms',
                'description' => 'Custom contact form builder with submission storage and admin review',
                'category' => 'communication',
            ),
            'bulk-sms-channel' => array(
                'name' => 'Bulk SMS Channel',
                'description' => 'Placeholder for bulk SMS integrations (coming soon)',
                'category' => 'communication',
            ),
            
            // === SECURITY ===
            'spam-protection' => array(
                'name' => 'Spam Protection',
                'description' => 'Cloudflare Turnstile and Google reCAPTCHA v2/v3 integration to block spam',
                'category' => 'security',
            ),
            'login-redesign' => array(
                'name' => 'Login Redesign',
                'description' => 'Customize the WordPress login page with your logo, colors, and branding',
                'category' => 'security',
            ),
            'social-login' => array(
                'name' => 'Social Login',
                'description' => 'Allow users to login with Google and Facebook accounts (OAuth)',
                'category' => 'security',
            ),
            
            // === CONTENT ===
            'snippets' => array(
                'name' => 'Code Snippets Manager',
                'description' => 'Manage code snippets with visual toggle switches - easier than Code Snippets plugin',
                'category' => 'content',
            ),
            'redirects' => array(
                'name' => 'Redirects Manager',
                'description' => '301/302/307 redirects with import/export and usage tracking - SEO essential',
                'category' => 'content',
            ),
            
            // === UTILITY ===
            'debug' => array(
                'name' => 'Debug Indicator',
                'description' => 'Warns you if WP_DEBUG is active on production sites (security risk alert)',
                'category' => 'utility',
            ),
        );
    }
}
