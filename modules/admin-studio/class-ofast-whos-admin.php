<?php

/**
 * Ofast X - White Label Module
 * Dashboard widgets showing administrator users and designer details
 * Also handles custom admin footer text customization
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Whos_Admin
{

    /**
     * Initialize module
     */
    public function init()
    {
        // NOTE: Module enabled check removed - core loader already verified this
        // before calling init(). See class-ofast-core.php is_module_enabled()

        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        // Add dashboard widgets
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widgets'));

        // Add settings page
        add_action('admin_menu', array($this, 'add_settings_menu'));
        add_action('admin_init', array($this, 'handle_settings_save'));

        // Override admin footer text (from Admin Footer module)
        add_filter('admin_footer_text', array($this, 'custom_footer_left'), 999);
        add_filter('update_footer', array($this, 'custom_footer_right'), 999);
    }

    /**
     * Enqueue reusable UI assets for the White Label page.
     *
     * @param string $hook Admin page hook.
     */
    public function enqueue_assets($hook)
    {
        if (strpos($hook, 'ofast-white-label') === false) {
            return;
        }

        wp_enqueue_style(
            'ofast-tabs',
            OFAST_X_PLUGIN_URL . 'assets/css/ofast-tabs.css',
            array(),
            OFAST_X_VERSION
        );

        wp_enqueue_script(
            'ofast-tabs',
            OFAST_X_PLUGIN_URL . 'assets/js/ofast-tabs.js',
            array('jquery'),
            OFAST_X_VERSION,
            true
        );
    }

    /**
     * Add dashboard widgets
     */
    public function add_dashboard_widgets()
    {
        // Administrator widget
        wp_add_dashboard_widget(
            'ofast_admin_users_widget',
            'Administrator',
            array($this, 'render_admin_users_widget')
        );

        // Designer Details widget
        wp_add_dashboard_widget(
            'ofast_designer_details_widget',
            'Designer Details',
            array($this, 'render_designer_widget')
        );
    }

    /**
     * Render Administrator Users Widget
     */
    public function render_admin_users_widget()
    {
        $args = array(
            'role'    => 'administrator',
            'orderby' => 'registered',
            'order'   => 'DESC',
        );

        $admin_users = get_users($args);

        if ($admin_users) {
            foreach ($admin_users as $admin_user) {
                $first_name = $admin_user->first_name;
                $last_name = $admin_user->last_name;
                $email = $admin_user->user_email;
                $full_name = trim($first_name . ' ' . $last_name) ?: $admin_user->user_login;
                $site_logo_url = get_site_icon_url(32);

                echo '<table style="width: 100%; margin-bottom: 20px; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;">';

                echo '<tr style="background: #f9f9f9;"><th style="text-align: left; width: 120px; padding: 10px; font-weight: 600;">Name</th>
                          <td style="padding: 10px;">' . esc_html($full_name) . '</td></tr>';

                echo '<tr><th style="text-align: left; padding: 10px; font-weight: 600;">Email</th>
                          <td style="padding: 10px;"><a href="mailto:' . esc_attr($email) . '" style="color: #1e88e5; text-decoration: none;">' . esc_html($email) . '</a></td></tr>';

                echo '<tr style="background: #f9f9f9;"><th style="text-align: left; padding: 10px; font-weight: 600;">Site Logo</th>
                          <td style="padding: 10px;">';
                if ($site_logo_url) {
                    echo '<img src="' . esc_url($site_logo_url) . '" alt="Site Logo" width="32" height="32" style="border-radius: 4px;">';
                } else {
                    echo '<span style="color: #999;">No Logo Set</span>';
                }
                echo '</td></tr>';

                echo '</table>';
            }
        } else {
            echo '<p style="color: #999;">No admin users found.</p>';
        }
    }

    /**
     * Render Designer Details Widget
     */
    public function render_designer_widget()
    {
        $name = get_option('ofast_designer_name', 'Your Name');
        $email = get_option('ofast_designer_email', 'hello@example.com');
        $website = get_option('ofast_designer_website', 'https://example.com');

        echo '<div style="padding: 10px;">';
        echo '<p style="margin: 8px 0;"><strong>Designer:</strong> ' . esc_html($name) . '</p>';
        echo '<p style="margin: 8px 0;"><strong>Email:</strong> <a href="mailto:' . esc_attr($email) . '" style="color: #1e88e5; text-decoration: none;">' . esc_html($email) . '</a></p>';
        echo '<p style="margin: 8px 0;"><strong>Website:</strong> <a href="' . esc_url($website) . '" target="_blank" style="color: #1e88e5; text-decoration: none;">' . esc_html($website) . '</a></p>';
        echo '<hr style="margin: 15px 0; border: none; border-top: 1px solid #eee;">';
        echo '</div>';
    }

    /**
     * Add settings submenu
     */
    public function add_settings_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'White Label Settings',
            'White Label',
            'manage_options',
            'ofast-white-label',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Handle settings save
     */
    public function handle_settings_save()
    {
        if (!isset($_POST['ofast_white_label_save'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['_wpnonce'], 'ofast_white_label_settings')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        // Save Designer Details
        update_option('ofast_designer_name', sanitize_text_field($_POST['designer_name']));
        update_option('ofast_designer_email', sanitize_email($_POST['designer_email']));
        update_option('ofast_designer_website', esc_url_raw($_POST['designer_website']));

        // Save Footer Settings (migrated from Admin Footer module)
        $footer_settings = array(
            'left_text' => wp_kses_post($_POST['footer_left_text'] ?? ''),
            'right_text' => sanitize_text_field($_POST['footer_right_text'] ?? ''),
            'hide_wp_version' => isset($_POST['hide_wp_version']) ? 1 : 0,
            'enable_dark_mode' => isset($_POST['enable_dark_mode']) ? 1 : 0,
            'enable_custom_dashboard' => isset($_POST['enable_custom_dashboard']) ? 1 : 0,
        );
        update_option('ofast_admin_footer_settings', $footer_settings);

        $active_tab = isset($_POST['white_label_active_tab']) ? sanitize_key(wp_unslash($_POST['white_label_active_tab'])) : 'designer_details';
        if (!in_array($active_tab, array('designer_details', 'footer', 'updates'), true)) {
            $active_tab = 'designer_details';
        }

        wp_redirect(add_query_arg(array(
            'settings_saved' => '1',
            'tab' => $active_tab,
        ), wp_get_referer()));
        exit;
    }

    /**
     * Custom left footer text
     */
    public function custom_footer_left($text)
    {
        $settings = get_option('ofast_admin_footer_settings', array());

        if (!empty($settings['left_text'])) {
            $footer_text = $settings['left_text'];

            // Replace shortcuts
            $footer_text = $this->replace_shortcuts($footer_text);

            return wp_kses_post($footer_text);
        }

        return $text;
    }

    /**
     * Replace shortcuts with actual values
     */
    private function replace_shortcuts($text)
    {
        $replacements = array(
            '{site_name}'   => get_bloginfo('name'),
            '{year}'        => date('Y'),
            '{admin_email}' => get_option('admin_email'),
        );

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    /**
     * Custom right footer text (WP version area)
     */
    public function custom_footer_right($text)
    {
        $settings = get_option('ofast_admin_footer_settings', array());

        // Hide WP version if selected
        if (!empty($settings['hide_wp_version'])) {
            $text = '';
        }

        if (!empty($settings['right_text'])) {
            return esc_html($settings['right_text']);
        }

        return $text;
    }

    /**
     * Render settings page
     */
    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $name = get_option('ofast_designer_name', '');
        $email = get_option('ofast_designer_email', '');
        $website = get_option('ofast_designer_website', '');
        
        // Footer settings
        $footer_settings = get_option('ofast_admin_footer_settings', array(
            'left_text' => '',
            'right_text' => '',
            'hide_wp_version' => 0,
        ));
        
        $saved = isset($_GET['settings_saved']);
        $default_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'designer_details';
        if (!in_array($default_tab, array('designer_details', 'footer', 'updates'), true)) {
            $default_tab = 'designer_details';
        }

?>
        <div class="wrap ofast-white-label-wrap">
            <!-- Modern Header with Gradient -->
            <div class="ofast-page-header">
                <div class="ofast-header-content">
                    <div class="ofast-header-icon">
                        <span class="dashicons dashicons-id-alt"></span>
                    </div>
                    <div class="ofast-header-text">
                        <h1>White Label</h1>
                        <p>Customize designer details, admin footer branding, and future white label settings</p>
                    </div>
                </div>
            </div>

            <?php if ($saved): ?>
                <?php Ofast_X_Toast::add('White Label settings saved successfully!', 'success'); ?>
            <?php endif; ?>

            <form method="post" action="" class="ofast-modern-form">
                <?php wp_nonce_field('ofast_white_label_settings', '_wpnonce'); ?>
                <input type="hidden" name="white_label_active_tab" value="<?php echo esc_attr($default_tab); ?>" class="ofast-active-tab">

                <div class="ofast-tabs-shell">
                    <nav class="ofast-tabs-nav" aria-label="<?php esc_attr_e('White Label sections', 'ofast-x'); ?>">
                        <button type="button" class="ofast-tab <?php echo $default_tab === 'designer_details' ? 'active' : ''; ?>" data-tab="designer_details">
                            <span class="dashicons dashicons-businessperson"></span>
                            <?php esc_html_e('Designer Details', 'ofast-x'); ?>
                        </button>
                        <button type="button" class="ofast-tab <?php echo $default_tab === 'footer' ? 'active' : ''; ?>" data-tab="footer">
                            <span class="dashicons dashicons-editor-kitchensink"></span>
                            <?php esc_html_e('Footer', 'ofast-x'); ?>
                        </button>
                        <button type="button" class="ofast-tab <?php echo $default_tab === 'updates' ? 'active' : ''; ?>" data-tab="updates">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e('Updates', 'ofast-x'); ?>
                        </button>
                    </nav>

                    <div class="ofast-tab-content<?php echo $default_tab === 'designer_details' ? ' active' : ''; ?>" data-tab-panel="designer_details">
                        <div class="ofast-tab-header">
                            <h2><?php esc_html_e('Designer Details', 'ofast-x'); ?></h2>
                            <p><?php esc_html_e('Manage the details shown in your White Label dashboard widget.', 'ofast-x'); ?></p>
                        </div>

                        <div class="ofast-content-grid">
                            <div class="ofast-card ofast-main-card">
                                <div class="ofast-card-header">
                                    <span class="dashicons dashicons-admin-users"></span>
                                    <h2>Designer Details</h2>
                                </div>
                                <div class="ofast-card-body">
                                    <div class="ofast-form-group">
                                        <label for="designer_name">
                                            <span class="dashicons dashicons-businessperson"></span>
                                            Designer Name
                                        </label>
                                        <input type="text" name="designer_name" id="designer_name"
                                            value="<?php echo esc_attr($name); ?>"
                                            placeholder="John Doe or Acme Studios">
                                        <span class="ofast-field-hint">Your full name or company name</span>
                                    </div>

                                    <div class="ofast-form-group">
                                        <label for="designer_email">
                                            <span class="dashicons dashicons-email"></span>
                                            Email Address
                                        </label>
                                        <input type="email" name="designer_email" id="designer_email"
                                            value="<?php echo esc_attr($email); ?>"
                                            placeholder="hello@example.com">
                                        <span class="ofast-field-hint">Contact email for support inquiries</span>
                                    </div>

                                    <div class="ofast-form-group">
                                        <label for="designer_website">
                                            <span class="dashicons dashicons-admin-site-alt3"></span>
                                            Website URL
                                        </label>
                                        <input type="url" name="designer_website" id="designer_website"
                                            value="<?php echo esc_attr($website); ?>"
                                            placeholder="https://example.com">
                                        <span class="ofast-field-hint">Your portfolio or business website</span>
                                    </div>
                                </div>
                            </div>

                            <div class="ofast-card ofast-preview-card">
                                <div class="ofast-card-header">
                                    <span class="dashicons dashicons-visibility"></span>
                                    <h2>Designer Preview</h2>
                                </div>
                                <div class="ofast-card-body">
                                    <div class="ofast-preview-widget">
                                        <div class="ofast-preview-item">
                                            <span class="ofast-preview-label">Designer</span>
                                            <span class="ofast-preview-value" id="preview-name"><?php echo esc_html($name ?: 'Your Name'); ?></span>
                                        </div>
                                        <div class="ofast-preview-item">
                                            <span class="ofast-preview-label">Email</span>
                                            <a href="mailto:<?php echo esc_attr($email); ?>" class="ofast-preview-value ofast-link" id="preview-email">
                                                <?php echo esc_html($email ?: 'hello@example.com'); ?>
                                            </a>
                                        </div>
                                        <div class="ofast-preview-item">
                                            <span class="ofast-preview-label">Website</span>
                                            <a href="<?php echo esc_url($website); ?>" target="_blank" class="ofast-preview-value ofast-link" id="preview-website">
                                                <?php echo esc_html($website ?: 'https://example.com'); ?>
                                            </a>
                                        </div>
                                    </div>
                                    <p class="ofast-preview-note">
                                        <span class="dashicons dashicons-info-outline"></span>
                                        This is how your details appear in the dashboard widget
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ofast-tab-content<?php echo $default_tab === 'footer' ? ' active' : ''; ?>" data-tab-panel="footer">
                        <div class="ofast-tab-header">
                            <h2><?php esc_html_e('Footer', 'ofast-x'); ?></h2>
                            <p><?php esc_html_e('Control the admin footer text and related white label footer options.', 'ofast-x'); ?></p>
                        </div>

                        <div class="ofast-content-grid">
                            <div class="ofast-card ofast-main-card">
                                <div class="ofast-card-header">
                                    <span class="dashicons dashicons-editor-kitchensink"></span>
                                    <h2>Admin Footer</h2>
                                </div>
                                <div class="ofast-card-body">
                                    <div class="ofast-form-group">
                                        <label for="footer_left_text">
                                            Left Footer Text
                                            <span class="ofast-tooltip" title="Replaces 'Thank you for creating with WordPress.' HTML is allowed.">
                                                <span class="dashicons dashicons-info-outline"></span>
                                            </span>
                                        </label>
                                        <textarea name="footer_left_text" id="footer_left_text" rows="3"
                                            placeholder="e.g., Designed by Your Company | Contact: info@example.com"><?php echo esc_textarea($footer_settings['left_text'] ?? ''); ?></textarea>
                                        <span class="ofast-field-hint">
                                            Available shortcuts: <code>{site_name}</code> <code>{year}</code> <code>{admin_email}</code>
                                        </span>
                                    </div>

                                    <div class="ofast-form-group">
                                        <label for="footer_right_text">
                                            Right Footer Text
                                            <span class="ofast-tooltip" title="Replaces the WordPress version number on the right side.">
                                                <span class="dashicons dashicons-info-outline"></span>
                                            </span>
                                        </label>
                                        <input type="text" name="footer_right_text" id="footer_right_text"
                                            value="<?php echo esc_attr($footer_settings['right_text'] ?? ''); ?>"
                                            placeholder="e.g., v1.0.0">
                                        <span class="ofast-field-hint">Custom text for the right footer area</span>
                                    </div>

                                    <div class="ofast-form-group">
                                        <label class="ofast-checkbox-label">
                                            <input type="checkbox" name="hide_wp_version" value="1"
                                                <?php checked(!empty($footer_settings['hide_wp_version'])); ?>>
                                            <span class="ofast-checkbox-custom"></span>
                                            <span class="ofast-checkbox-text">
                                                Hide WordPress version number
                                                <span class="ofast-security-badge">Security Recommended</span>
                                            </span>
                                        </label>
                                    </div>

                                    <div class="ofast-form-group">
                                        <label class="ofast-checkbox-label">
                                            <input type="checkbox" name="enable_dark_mode" value="1"
                                                <?php checked(!empty($footer_settings['enable_dark_mode'])); ?>>
                                            <span class="ofast-checkbox-custom"></span>
                                            <span class="ofast-checkbox-text">
                                                Enable Dark/Light Mode Toggle
                                                <span class="ofast-security-badge" style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);">UI Feature</span>
                                            </span>
                                        </label>
                                    </div>

                                    <div class="ofast-form-group">
                                        <label class="ofast-checkbox-label">
                                            <input type="checkbox" name="enable_custom_dashboard" value="1"
                                                <?php checked(!empty($footer_settings['enable_custom_dashboard'])); ?>>
                                            <span class="ofast-checkbox-custom"></span>
                                            <span class="ofast-checkbox-text">
                                                Enable Custom Dashboard
                                                <span class="ofast-security-badge" style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);">New Feature</span>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="ofast-card ofast-preview-card">
                                <div class="ofast-card-header">
                                    <span class="dashicons dashicons-visibility"></span>
                                    <h2>Footer Preview</h2>
                                </div>
                                <div class="ofast-card-body">
                                    <div class="ofast-preview-widget">
                                        <div class="ofast-preview-footer">
                                            <span class="ofast-preview-left" id="preview-left"><?php echo !empty($footer_settings['left_text']) ? wp_kses_post($this->replace_shortcuts($footer_settings['left_text'])) : '<em>Thank you for creating with WordPress.</em>'; ?></span>
                                            <span class="ofast-preview-right" id="preview-right"><?php echo !empty($footer_settings['right_text']) ? esc_html($footer_settings['right_text']) : (!empty($footer_settings['hide_wp_version']) ? '' : '<em>Version X.X</em>'); ?></span>
                                        </div>
                                    </div>
                                    <p class="ofast-preview-note">
                                        <span class="dashicons dashicons-info-outline"></span>
                                        This is how your footer appears in the admin area
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ofast-tab-content<?php echo $default_tab === 'updates' ? ' active' : ''; ?>" data-tab-panel="updates">
                        <div class="ofast-tab-header">
                            <h2><?php esc_html_e('Updates', 'ofast-x'); ?></h2>
                            <p><?php esc_html_e('This tab is ready for the additional White Label update settings you plan to add later.', 'ofast-x'); ?></p>
                        </div>

                        <div class="ofast-card ofast-main-card">
                            <div class="ofast-card-header">
                                <span class="dashicons dashicons-update"></span>
                                <h2>Updates</h2>
                            </div>
                            <div class="ofast-card-body">
                                <p class="ofast-field-hint" style="margin-top: 0;">
                                    This section is intentionally empty for now so you can add your White Label update controls later without changing the page structure again.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ofast-form-actions" style="margin-top: 30px;">
                    <button type="submit" name="ofast_white_label_save" class="ofast-btn-primary">
                        Save Settings
                    </button>
                </div>
            </form>
        </div>

        <style>
            .ofast-white-label-wrap {
                max-width: 1200px;
                margin: 20px auto;
                padding: 0 20px;
            }

            /* Page Header - White with glassmorphism icon */
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
            .ofast-header-icon {
                width: 60px;
                height: 60px;
                background: #ffffff;
                border-radius: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 4px 15px rgba(99, 102, 241, 0.2);
                border: 1px solid #e2e8f0;
                color: #6366f1;
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

            /* Content Grid */
            .ofast-content-grid {
                display: grid;
                grid-template-columns: 1.5fr 1fr;
                gap: 25px;
                align-items: start;
            }
            @media (max-width: 900px) {
                .ofast-content-grid {
                    grid-template-columns: 1fr;
                }
            }

            /* Cards */
            .ofast-card {
                background: #fff;
                border-radius: 16px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.08);
                overflow: hidden;
                border: 1px solid rgba(0,0,0,0.05);
            }
            .ofast-card-header {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 20px 25px;
                background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
                border-bottom: 1px solid #e2e8f0;
            }
            .ofast-card-header .dashicons {
                font-size: 20px;
                width: 20px;
                height: 20px;
                color: #6366f1;
            }
            .ofast-card-header h2 {
                margin: 0;
                font-size: 16px;
                font-weight: 600;
                color: #1e293b;
            }
            .ofast-card-body {
                padding: 25px;
            }

            /* Modern Form */
            .ofast-modern-form .ofast-form-group {
                margin-bottom: 24px;
            }
            .ofast-modern-form label {
                display: flex;
                align-items: center;
                gap: 8px;
                font-weight: 600;
                color: #374151;
                margin-bottom: 10px;
                font-size: 14px;
            }
            .ofast-modern-form label .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
                color: #6366f1;
            }
            .ofast-modern-form input[type="text"],
            .ofast-modern-form input[type="email"],
            .ofast-modern-form input[type="url"],
            .ofast-modern-form textarea {
                width: 100%;
                padding: 14px 18px;
                border: 2px solid #e2e8f0;
                border-radius: 10px;
                font-size: 15px;
                transition: all 0.2s ease;
                background: #f8fafc;
                font-family: inherit;
            }
            .ofast-modern-form textarea {
                min-height: 100px;
                resize: vertical;
            }
            .ofast-modern-form input:focus,
            .ofast-modern-form textarea:focus {
                outline: none;
                border-color: #6366f1;
                background: #fff;
                box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
            }
            .ofast-modern-form input::placeholder,
            .ofast-modern-form textarea::placeholder {
                color: #94a3b8;
            }
            .ofast-field-hint {
                display: block;
                margin-top: 8px;
                font-size: 13px;
                color: #64748b;
            }
            .ofast-field-hint code {
                background: #f1f5f9;
                padding: 2px 6px;
                border-radius: 4px;
                font-size: 12px;
                color: #6366f1;
            }

            /* Checkbox styling */
            .ofast-checkbox-label {
                display: flex !important;
                align-items: center;
                gap: 12px;
                cursor: pointer;
                padding: 16px 20px;
                background: #f8fafc;
                border: 2px solid #e2e8f0;
                border-radius: 10px;
                transition: all 0.2s ease;
            }
            .ofast-checkbox-label:hover {
                border-color: #6366f1;
                background: #fff;
            }
            .ofast-checkbox-label input[type="checkbox"] {
                width: 20px;
                height: 20px;
                accent-color: #6366f1;
            }
            .ofast-checkbox-text {
                display: flex;
                align-items: center;
                gap: 8px;
                flex: 1;
            }
            .ofast-security-badge {
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                color: #fff;
                padding: 3px 10px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            /* Form Actions */
            .ofast-form-actions {
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #e2e8f0;
            }
            .ofast-btn-primary {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                padding: 14px 28px;
                background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
                color: #fff;
                border: none;
                border-radius: 10px;
                font-size: 15px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
            }
            .ofast-btn-primary:hover {
                background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
            }
            .ofast-btn-primary .dashicons {
                font-size: 18px;
                width: 18px;
                height: 18px;
            }

            /* Preview Card */
            .ofast-preview-widget {
                background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
                border-radius: 12px;
                padding: 20px;
                border: 1px solid #e2e8f0;
            }
            .ofast-preview-item {
                padding: 12px 0;
                border-bottom: 1px solid #e2e8f0;
            }
            .ofast-preview-item:last-child {
                border-bottom: none;
            }
            .ofast-preview-label {
                display: block;
                font-size: 12px;
                font-weight: 600;
                color: #64748b;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-bottom: 4px;
            }
            .ofast-preview-value {
                font-size: 15px;
                color: #1e293b;
                font-weight: 500;
            }
            .ofast-link {
                color: #6366f1;
                text-decoration: none;
            }
            .ofast-link:hover {
                text-decoration: underline;
            }
            .ofast-preview-note {
                display: flex;
                align-items: center;
                gap: 8px;
                margin: 20px 0 0;
                padding: 12px 15px;
                background: #e0f2fe;
                border-radius: 8px;
                font-size: 13px;
                color: #0369a1;
            }
            .ofast-preview-note .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
            }

            /* Footer Preview */
            .ofast-preview-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                background: #fff;
                padding: 12px 16px;
                border-radius: 8px;
                border: 1px solid #e2e8f0;
                font-size: 13px;
                color: #64748b;
            }
            .ofast-preview-footer em {
                color: #94a3b8;
            }

            /* Tooltip */
            .ofast-tooltip {
                position: relative;
                cursor: help;
                margin-left: 4px;
            }
            .ofast-tooltip .dashicons {
                font-size: 14px !important;
                width: 14px !important;
                height: 14px !important;
                color: #94a3b8;
                transition: color 0.2s ease;
            }
            .ofast-tooltip:hover .dashicons {
                color: #6366f1;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Live preview updates for Designer Details
            $('#designer_name').on('input', function() {
                $('#preview-name').text($(this).val() || 'Your Name');
            });
            $('#designer_email').on('input', function() {
                var val = $(this).val() || 'hello@example.com';
                $('#preview-email').text(val).attr('href', 'mailto:' + val);
            });
            $('#designer_website').on('input', function() {
                var val = $(this).val() || 'https://example.com';
                $('#preview-website').text(val).attr('href', val);
            });

            // Live preview for Footer
            $('#footer_left_text').on('input', function() {
                var text = $(this).val() || '<em>Thank you for creating with WordPress.</em>';
                $('#preview-left').html(text);
            });
            $('#footer_right_text').on('input', function() {
                var text = $(this).val() || '<?php echo empty($footer_settings['hide_wp_version']) ? '<em>Version X.X</em>' : ''; ?>';
                $('#preview-right').html(text || '<em>Version X.X</em>');
            });
            $('input[name="hide_wp_version"]').on('change', function() {
                if ($(this).is(':checked') && !$('#footer_right_text').val()) {
                    $('#preview-right').html('');
                } else if (!$('#footer_right_text').val()) {
                    $('#preview-right').html('<em>Version X.X</em>');
                }
            });
        });
        </script>
<?php
    }
}
