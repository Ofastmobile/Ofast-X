<?php
/**
 * Enhanced Settings Page with Search, Filters, and Grouped Modules
 * File: admin/pages/class-ofast-settings-page.php
 */

class Ofast_X_Settings_Page {
    
    public function render() {
        ?>
        <div class="wrap ofastx-settings-wrap">
            <h1>Ofast X Settings</h1>
            <p class="description">Enable or disable plugin modules. Only enabled modules will load.</p>
            
            <!-- Search and Filter Bar -->
            <div class="ofastx-toolbar">
                <div class="ofastx-search-box">
                    <input type="text" id="ofastx-search" placeholder="Search modules..." />
                    <span class="dashicons dashicons-search"></span>
                </div>
                
                <div class="ofastx-filters">
                    <button class="ofastx-filter active" data-filter="all">
                        All <span class="count">(21)</span>
                    </button>
                    <button class="ofastx-filter" data-filter="enabled">
                        Enabled <span class="count">(15)</span>
                    </button>
                    <button class="ofastx-filter" data-filter="disabled">
                        Disabled <span class="count">(6)</span>
                    </button>
                </div>
                
                <div class="ofastx-category-tabs">
                    <button class="ofastx-tab active" data-category="all">All</button>
                    <button class="ofastx-tab" data-category="communication">📧 Communication</button>
                    <button class="ofastx-tab" data-category="security">🔐 Security</button>
                    <button class="ofastx-tab" data-category="content">📝 Content</button>
                    <button class="ofastx-tab" data-category="customization">🎨 Customization</button>
                    <button class="ofastx-tab" data-category="utility">🔧 Utility</button>
                </div>
            </div>
            
            <!-- Settings Form -->
            <form method="post" action="" id="ofastx-settings-form">
                <?php wp_nonce_field('ofastx_save_settings', 'ofastx_settings_nonce'); ?>
                
                <!-- Communication Features -->
                <div class="ofastx-category-section" data-category="communication">
                    <h2 class="ofastx-category-title">
                        <span class="dashicons dashicons-email"></span>
                        Communication Features
                    </h2>
                    
                    <div class="ofastx-modules-grid">
                        <?php $this->render_module([
                            'id' => 'email',
                            'title' => 'Email Module',
                            'description' => 'Send personalized bulk emails to users by role, with drag-drop user selection and scheduled batches',
                            'status' => 'integrated',
                            'enabled' => true,
                            'dependencies' => ['smtp'],
                            'category' => 'communication'
                        ]); ?>
                        
                        <?php $this->render_module([
                            'id' => 'smtp',
                            'title' => 'SMTP Configuration',
                            'description' => 'Configure SendGrid, Mailgun, Zoho, or Gmail to ensure emails reach inboxes (not spam)',
                            'status' => 'integrated',
                            'enabled' => true,
                            'category' => 'communication'
                        ]); ?>
                        
                        <?php $this->render_module([
                            'id' => 'newsletter',
                            'title' => 'Newsletter Subscriptions',
                            'description' => 'Frontend signup forms, double opt-in, subscriber export, and one-click unsubscribe',
                            'status' => 'integrated',
                            'enabled' => true,
                            'category' => 'communication'
                        ]); ?>
                        
                        <?php $this->render_module([
                            'id' => 'contact_forms',
                            'title' => 'Contact Forms',
                            'description' => 'Custom contact form builder with multi-channel notifications (email, SMS, WhatsApp)',
                            'status' => 'integrated',
                            'enabled' => true,
                            'category' => 'communication'
                        ]); ?>
                        
                        <?php $this->render_module([
                            'id' => 'notification_channels',
                            'title' => 'Notification Channels',
                            'description' => 'Get instant WhatsApp/SMS alerts when users submit forms or subscribe to newsletter',
                            'status' => 'integrated',
                            'enabled' => true,
                            'category' => 'communication'
                        ]); ?>
                    </div>
                </div>
                
                <!-- Security Features -->
                <div class="ofastx-category-section" data-category="security">
                    <h2 class="ofastx-category-title">
                        <span class="dashicons dashicons-lock"></span>
                        Security Features
                    </h2>
                    
                    <div class="ofastx-modules-grid">
                        <?php $this->render_module([
                            'id' => 'admin_url',
                            'title' => 'Admin URL Customizer',
                            'description' => 'Hide /wp-admin behind a secret custom URL for security (e.g., /mylogin)',
                            'status' => 'integrated',
                            'enabled' => true,
                            'category' => 'security'
                        ]); ?>
                        
                        <?php $this->render_module([
                            'id' => 'spam_protection',
                            'title' => 'Spam Protection',
                            'description' => 'Cloudflare Turnstile and Google reCAPTCHA v2/v3 integration to block spam submissions',
                            'status' => 'integrated',
                            'enabled' => true,
                            'category' => 'security'
                        ]); ?>
                        
                        <?php $this->render_module([
                            'id' => 'login_redesign',
                            'title' => 'Login Redesign',
                            'description' => 'Customize the WordPress login page with your logo, colors, and branding',
                            'status' => 'integrated',
                            'enabled' => true,
                            'category' => 'security'
                        ]); ?>
                        
                        <?php $this->render_module([
                            'id' => 'social_login',
                            'title' => 'Social Login',
                            'description' => 'Allow users to login with Google and Facebook accounts (OAuth integration)',
                            'status' => 'integrated',
                            'enabled' => true,
                            'category' => 'security'
                        ]); ?>
                    </div>
                </div>
                
                <!-- Content Management -->
                <div class="ofastx-category-section" data-category="content">
                    <h2 class="ofastx-category-title">
                        <span class="dashicons dashicons-edit"></span>
                        Content Management
                    </h2>
                    
                    <div class="ofastx-modules-grid">
                        <?php $this->render_module([
                            'id' => 'code_snippets',
                            'title' => 'Code Snippets Manager',
                            'description' => 'Manage code snippets with visual toggle switches - easier than Code Snippets plugin',
                            'status' => 'integrated',
                            'enabled' => true,
                            'category' => 'content'
                        ]); ?>
                        
                        <?php $this->render_module([
                            'id' => 'content_duplicator',
                            'title' => 'Content Duplicator',
                            'description' => 'Duplicate posts and pages with one click - saves hours of copy-paste work',
                            'status' => 'integrated',
                            'enabled' => true,
                            'category' => 'content'
                        ]); ?>
                        
                        <?php $this->render_module([
                            'id' => 'content_ordering',
                            'title' => 'Content Ordering',
                            'description' => 'Drag-and-drop reordering for posts, pages, and custom post types',
                            'status' => 'integrated',
                            'enabled' => true,
                            'category' => 'content'
                        ]); ?>
                        
                        <?php $this->render_module([
                            'id' => 'redirects',
                            'title' => 'Redirects Manager',
                            'description' => '301/302/307 redirects with import/export and usage tracking - SEO essential',
                            'status' => 'integrated',
                            'enabled' => true,
                            'category' => 'content'
                        ]); ?>
                    </div>
                </div>
                
                <!-- Customization Features -->
                <div class="ofastx-category-section" data-category="customization">
                    <h2 class="ofastx-category-title">
                        <span class="dashicons dashicons-admin-appearance"></span>
                        Customization Features
                    </h2>
                    
                    <div class="ofastx-modules-grid">
                        <?php $this->render_module([
                            'id' => 'dashboard',
                            'title' => 'Dashboard Module',
                            'description' => 'View user counts by role, recent activity, and system stats at a glance',
                            'status' => 'always_on',
                            'enabled' => true,
                            'category' => 'customization',
                            'locked' => true
                        ]); ?>
                        
                        <?php $this->render_module([
                            'id' => 'wp_admin_design',
                            'title' => 'WP Admin Design',
                            'description' => 'Modern glassmorphism styling for WordPress admin with gradient animations',
                            'status' => 'integrated',
                            'enabled' => false,
                            'category' => 'customization'
                        ]); ?>
                        
                        <?php $this->render_module([
                            'id' => 'admin_tweaks',
                            'title' => 'Admin Tweaks',
                            'description' => 'Quick admin customizations: hide admin bar, remove WP logo, rename howdy, infinite scroll',
                            'status' => 'integrated',
                            'enabled' => true,
                            'category' => 'customization'
                        ]); ?>
                        
                        <?php $this->render_module([
                            'id' => 'admin_menu_editor',
                            'title' => 'Admin Menu Editor',
                            'description' => 'Reorder and rename WordPress admin menu items - perfect for white-label sites',
                            'status' => 'integrated',
                            'enabled' => true,
                            'category' => 'customization'
                        ]); ?>
                        
                        <?php $this->render_module([
                            'id' => 'custom_admin_footer',
                            'title' => 'Custom Admin Footer',
                            'description' => 'Add custom branding text to admin footer - replace "Thank you for creating"',
                            'status' => 'integrated',
                            'enabled' => false,
                            'category' => 'customization'
                        ]); ?>
                        
                        <?php $this->render_module([
                            'id' => 'whos_admin',
                            'title' => "Who's Admin Widget",
                            'description' => 'Dashboard widget showing admin users and designer details - white-label friendly',
                            'status' => 'integrated',
                            'enabled' => true,
                            'category' => 'customization'
                        ]); ?>
                    </div>
                </div>
                
                <!-- Utility Features -->
                <div class="ofastx-category-section" data-category="utility">
                    <h2 class="ofastx-category-title">
                        <span class="dashicons dashicons-admin-tools"></span>
                        Utility Features
                    </h2>
                    
                    <div class="ofastx-modules-grid">
                        <?php $this->render_module([
                            'id' => 'debug_indicator',
                            'title' => 'Debug Indicator',
                            'description' => 'Warns you if WP_DEBUG is active on production sites (security risk alert)',
                            'status' => 'integrated',
                            'enabled' => true,
                            'category' => 'utility'
                        ]); ?>
                        
                        <?php $this->render_module([
                            'id' => 'user_roles',
                            'title' => 'User Role Manager',
                            'description' => 'Assign multiple roles to WordPress users - essential for ecommerce sites',
                            'status' => 'integrated',
                            'enabled' => true,
                            'category' => 'utility'
                        ]); ?>
                    </div>
                </div>
                
                <!-- Data Management -->
                <div class="ofastx-data-management">
                    <h2>Data Management</h2>
                    <p class="description">Control what happens to your data when the plugin is deleted.</p>
                    
                    <label>
                        <input type="radio" name="ofastx_data_retention" value="keep" checked>
                        <strong>Keep All Data</strong>
                        <span class="description">Database tables and settings will be preserved. Useful if you plan to reinstall later.</span>
                    </label>
                    
                    <label>
                        <input type="radio" name="ofastx_data_retention" value="remove">
                        <strong>Remove All Data</strong>
                        <span class="description">Completely remove all database tables, options, and settings when uninstalled.</span>
                    </label>
                    
                    <p class="note">
                        <strong>Note:</strong> This setting only takes effect when the plugin is <em>deleted</em> (not just deactivated). 
                        Deactivating the plugin will never remove your data.
                    </p>
                </div>
                
                <!-- Save Button -->
                <div class="ofastx-save-bar">
                    <button type="submit" class="button button-primary button-hero">
                        💾 Save All Settings
                    </button>
                    <button type="button" class="button button-secondary" id="ofastx-reset">
                        Reset to Default
                    </button>
                </div>
            </form>
        </div>
        
        <!-- CSS Styles -->
        <style>
            .ofastx-settings-wrap {
                max-width: 1400px;
                margin: 20px auto;
            }
            
            /* Toolbar */
            .ofastx-toolbar {
                background: #fff;
                padding: 20px;
                margin: 20px 0;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            
            .ofastx-search-box {
                position: relative;
                margin-bottom: 15px;
            }
            
            #ofastx-search {
                width: 100%;
                padding: 12px 40px 12px 15px;
                border: 2px solid #e0e0e0;
                border-radius: 6px;
                font-size: 15px;
                transition: border-color 0.2s;
            }
            
            #ofastx-search:focus {
                border-color: #2271b1;
                outline: none;
            }
            
            .ofastx-search-box .dashicons {
                position: absolute;
                right: 15px;
                top: 50%;
                transform: translateY(-50%);
                color: #999;
            }
            
            /* Filters */
            .ofastx-filters {
                display: flex;
                gap: 10px;
                margin-bottom: 15px;
            }
            
            .ofastx-filter {
                padding: 8px 16px;
                border: 2px solid #e0e0e0;
                background: #fff;
                border-radius: 6px;
                cursor: pointer;
                transition: all 0.2s;
            }
            
            .ofastx-filter:hover {
                border-color: #2271b1;
            }
            
            .ofastx-filter.active {
                background: #2271b1;
                color: #fff;
                border-color: #2271b1;
            }
            
            .ofastx-filter .count {
                opacity: 0.7;
                font-size: 0.9em;
            }
            
            /* Category Tabs */
            .ofastx-category-tabs {
                display: flex;
                gap: 5px;
                border-bottom: 2px solid #e0e0e0;
                overflow-x: auto;
            }
            
            .ofastx-tab {
                padding: 10px 20px;
                border: none;
                background: transparent;
                cursor: pointer;
                white-space: nowrap;
                border-bottom: 3px solid transparent;
                transition: all 0.2s;
            }
            
            .ofastx-tab:hover {
                background: #f5f5f5;
            }
            
            .ofastx-tab.active {
                border-bottom-color: #2271b1;
                color: #2271b1;
                font-weight: 600;
            }
            
            /* Category Sections */
            .ofastx-category-section {
                margin: 30px 0;
            }
            
            .ofastx-category-section.hidden {
                display: none;
            }
            
            .ofastx-category-title {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 20px;
                padding-bottom: 10px;
                border-bottom: 2px solid #e0e0e0;
            }
            
            .ofastx-category-title .dashicons {
                font-size: 24px;
                width: 24px;
                height: 24px;
            }
            
            /* Modules Grid */
            .ofastx-modules-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
                gap: 20px;
            }
            
            @media (max-width: 1200px) {
                .ofastx-modules-grid {
                    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
                }
            }
            
            @media (max-width: 768px) {
                .ofastx-modules-grid {
                    grid-template-columns: 1fr;
                }
            }
            
            /* Module Card */
            .ofastx-module {
                background: #fff;
                border: 2px solid #e0e0e0;
                border-radius: 8px;
                padding: 20px;
                transition: all 0.2s;
            }
            
            .ofastx-module.hidden {
                display: none;
            }
            
            .ofastx-module:hover {
                border-color: #2271b1;
                box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            }
            
            .ofastx-module-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 10px;
            }
            
            .ofastx-module-title {
                font-size: 16px;
                font-weight: 600;
                color: #1d2327;
                margin: 0 0 5px 0;
            }
            
            .ofastx-module-status {
                padding: 4px 10px;
                border-radius: 4px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .ofastx-module-status.integrated {
                background: #d4edda;
                color: #155724;
            }
            
            .ofastx-module-status.always-on {
                background: #cfe2ff;
                color: #084298;
            }
            
            .ofastx-module-description {
                color: #646970;
                font-size: 13px;
                line-height: 1.5;
                margin-bottom: 15px;
            }
            
            .ofastx-module-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .ofastx-module-dependencies {
                font-size: 12px;
                color: #999;
            }
            
            .ofastx-module-dependencies .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
            }
            
            /* Toggle Switch */
            .ofastx-toggle {
                position: relative;
                display: inline-block;
                width: 50px;
                height: 26px;
            }
            
            .ofastx-toggle input {
                opacity: 0;
                width: 0;
                height: 0;
            }
            
            .ofastx-toggle-slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #ccc;
                transition: 0.3s;
                border-radius: 26px;
            }
            
            .ofastx-toggle-slider:before {
                position: absolute;
                content: "";
                height: 20px;
                width: 20px;
                left: 3px;
                bottom: 3px;
                background-color: white;
                transition: 0.3s;
                border-radius: 50%;
            }
            
            .ofastx-toggle input:checked + .ofastx-toggle-slider {
                background-color: #2271b1;
            }
            
            .ofastx-toggle input:checked + .ofastx-toggle-slider:before {
                transform: translateX(24px);
            }
            
            .ofastx-toggle input:disabled + .ofastx-toggle-slider {
                opacity: 0.5;
                cursor: not-allowed;
            }
            
            /* Data Management */
            .ofastx-data-management {
                background: #fff;
                padding: 20px;
                border-radius: 8px;
                margin: 30px 0;
            }
            
            .ofastx-data-management label {
                display: block;
                padding: 15px;
                border: 2px solid #e0e0e0;
                border-radius: 6px;
                margin-bottom: 10px;
                cursor: pointer;
                transition: all 0.2s;
            }
            
            .ofastx-data-management label:hover {
                border-color: #2271b1;
                background: #f5f9ff;
            }
            
            .ofastx-data-management input[type="radio"] {
                margin-right: 10px;
            }
            
            .ofastx-data-management .description {
                display: block;
                margin-left: 30px;
                color: #646970;
                font-size: 13px;
            }
            
            .ofastx-data-management .note {
                background: #fff8e1;
                padding: 12px;
                border-left: 4px solid #ffc107;
                margin-top: 15px;
                font-size: 13px;
            }
            
            /* Save Bar */
            .ofastx-save-bar {
                position: sticky;
                bottom: 0;
                background: #fff;
                padding: 20px;
                border-top: 2px solid #e0e0e0;
                display: flex;
                gap: 15px;
                z-index: 100;
                box-shadow: 0 -4px 12px rgba(0,0,0,0.08);
            }
        </style>
        
        <!-- JavaScript -->
        <script>
        jQuery(document).ready(function($) {
            // Search functionality
            $('#ofastx-search').on('input', function() {
                const term = $(this).val().toLowerCase();
                
                $('.ofastx-module').each(function() {
                    const title = $(this).find('.ofastx-module-title').text().toLowerCase();
                    const desc = $(this).find('.ofastx-module-description').text().toLowerCase();
                    
                    if (title.includes(term) || desc.includes(term)) {
                        $(this).removeClass('hidden');
                    } else {
                        $(this).addClass('hidden');
                    }
                });
                
                // Hide empty sections
                $('.ofastx-category-section').each(function() {
                    const visibleModules = $(this).find('.ofastx-module:not(.hidden)').length;
                    $(this).toggle(visibleModules > 0);
                });
            });
            
            // Filter functionality
            $('.ofastx-filter').on('click', function() {
                $('.ofastx-filter').removeClass('active');
                $(this).addClass('active');
                
                const filter = $(this).data('filter');
                
                $('.ofastx-module').each(function() {
                    const enabled = $(this).find('input[type="checkbox"]').is(':checked');
                    
                    if (filter === 'all') {
                        $(this).removeClass('hidden');
                    } else if (filter === 'enabled') {
                        $(this).toggle(enabled);
                    } else if (filter === 'disabled') {
                        $(this).toggle(!enabled);
                    }
                });
                
                // Update section visibility
                $('.ofastx-category-section').each(function() {
                    const visibleModules = $(this).find('.ofastx-module:not(.hidden)').length;
                    $(this).toggle(visibleModules > 0);
                });
            });
            
            // Category tabs
            $('.ofastx-tab').on('click', function() {
                $('.ofastx-tab').removeClass('active');
                $(this).addClass('active');
                
                const category = $(this).data('category');
                
                if (category === 'all') {
                    $('.ofastx-category-section').removeClass('hidden');
                } else {
                    $('.ofastx-category-section').addClass('hidden');
                    $(`.ofastx-category-section[data-category="${category}"]`).removeClass('hidden');
                }
            });
            
            // Reset button
            $('#ofastx-reset').on('click', function() {
                if (confirm('Reset all settings to default? This cannot be undone.')) {
                    location.href = location.href + '&reset=1';
                }
            });
            
            // Update counts on toggle
            $('.ofastx-toggle input').on('change', function() {
                const enabledCount = $('.ofastx-toggle input:checked').length;
                const disabledCount = $('.ofastx-toggle input:not(:checked)').length;
                const totalCount = enabledCount + disabledCount;
                
                $('.ofastx-filter[data-filter="all"] .count').text('(' + totalCount + ')');
                $('.ofastx-filter[data-filter="enabled"] .count').text('(' + enabledCount + ')');
                $('.ofastx-filter[data-filter="disabled"] .count').text('(' + disabledCount + ')');
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render individual module card
     */
    private function render_module($args) {
        $defaults = [
            'id' => '',
            'title' => '',
            'description' => '',
            'status' => 'integrated',
            'enabled' => false,
            'dependencies' => [],
            'category' => 'utility',
            'locked' => false
        ];
        
        $module = wp_parse_args($args, $defaults);
        
        ?>
        <div class="ofastx-module" data-module="<?php echo esc_attr($module['id']); ?>" data-category="<?php echo esc_attr($module['category']); ?>">
            <div class="ofastx-module-header">
                <div>
                    <h3 class="ofastx-module-title"><?php echo esc_html($module['title']); ?></h3>
                    <span class="ofastx-module-status <?php echo esc_attr($module['status']); ?>">
                        <?php echo esc_html(strtoupper(str_replace('_', ' ', $module['status']))); ?>
                    </span>
                </div>
                
                <label class="ofastx-toggle">
                    <input type="checkbox" 
                           name="ofastx_modules[<?php echo esc_attr($module['id']); ?>]" 
                           <?php checked($module['enabled']); ?>
                           <?php disabled($module['locked']); ?>>
                    <span class="ofastx-toggle-slider"></span>
                </label>
            </div>
            
            <p class="ofastx-module-description"><?php echo esc_html($module['description']); ?></p>
            
            <?php if (!empty($module['dependencies'])): ?>
            <div class="ofastx-module-footer">
                <div class="ofastx-module-dependencies">
                    <span class="dashicons dashicons-info"></span>
                    Works best with: <?php echo esc_html(implode(', ', $module['dependencies'])); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}