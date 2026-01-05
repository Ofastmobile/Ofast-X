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
                    'communication' => array('icon' => 'dashicons-email', 'title' => 'Communication Features'),
                    'security' => array('icon' => 'dashicons-lock', 'title' => 'Security Features'),
                    'content' => array('icon' => 'dashicons-edit', 'title' => 'Content Management'),
                    'customization' => array('icon' => 'dashicons-admin-appearance', 'title' => 'Customization Features'),
                    'utility' => array('icon' => 'dashicons-admin-tools', 'title' => 'Utility Features'),
                );
                
                $grouped_modules = array();
                foreach ($modules as $slug => $data) {
                    $cat = $data['category'] ?? 'utility';
                    $grouped_modules[$cat][$slug] = $data;
                }
                
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
                    if (category === 'all') {
                        $('.ofast-category-section').removeClass('hidden');
                    } else {
                        $('.ofast-category-section').addClass('hidden');
                        $('.ofast-category-section[data-category="' + category + '"]').removeClass('hidden');
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
            'admin-design' => array(
                'name' => 'WP Admin Design',
                'description' => 'Modern glassmorphism styling for WordPress admin with gradient animations',
                'category' => 'customization',
            ),
            'admin-tweaks' => array(
                'name' => 'Admin Tweaks',
                'description' => 'Quick admin customizations: hide admin bar, remove WP logo, rename howdy, infinite scroll',
                'category' => 'customization',
            ),
            'menu-editor' => array(
                'name' => 'Admin Menu Editor',
                'description' => 'Reorder and rename WordPress admin menu items - perfect for white-label sites',
                'category' => 'customization',
            ),
            'admin-footer' => array(
                'name' => 'Custom Admin Footer',
                'description' => 'Add custom branding text to admin footer - replace "Thank you for creating"',
                'category' => 'customization',
            ),
            'whos-admin' => array(
                'name' => "Who's Admin Widget",
                'description' => 'Dashboard widget showing admin users and designer details - white-label friendly',
                'category' => 'customization',
            ),
            
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
            'newsletter' => array(
                'name' => 'Newsletter Subscriptions',
                'description' => 'Frontend signup forms, double opt-in, subscriber export, and one-click unsubscribe',
                'category' => 'communication',
            ),
            'forms' => array(
                'name' => 'Contact Forms',
                'description' => 'Custom contact form builder with multi-channel notifications (email, SMS, WhatsApp)',
                'category' => 'communication',
            ),
            'notification-channels' => array(
                'name' => 'Notification Channels',
                'description' => 'Get instant WhatsApp/SMS alerts when users submit forms or subscribe',
                'category' => 'communication',
            ),
            
            // === SECURITY ===
            'admin-url' => array(
                'name' => 'Admin URL Customizer',
                'description' => 'Hide /wp-admin behind a secret custom URL for security (e.g., /mylogin)',
                'category' => 'security',
            ),
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
            'duplicate-content' => array(
                'name' => 'Content Duplicator',
                'description' => 'Duplicate posts and pages with one click - saves hours of copy-paste work',
                'category' => 'content',
            ),
            'content-ordering' => array(
                'name' => 'Content Ordering',
                'description' => 'Drag-and-drop reordering for posts, pages, and custom post types',
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
            'user-roles' => array(
                'name' => 'User Role Manager',
                'description' => 'Assign multiple roles to WordPress users - essential for ecommerce sites',
                'category' => 'utility',
            ),
        );
    }
}
