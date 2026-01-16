<?php

/**
 * Ofast X - Custom Admin Footer Module
 * Add custom branding text to WordPress admin footer
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Admin_Footer
{
    /**
     * Initialize module
     */
    public function init()
    {
        // Only load if module is enabled
        $enabled = get_option('ofastx_modules_enabled', array());
        if (empty($enabled['admin-footer'])) {
        }

        $settings = get_option('ofast_admin_footer_settings', array());

        // Add settings submenu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_save'));

        // Override admin footer text
        add_filter('admin_footer_text', array($this, 'custom_footer_left'), 999);
        add_filter('update_footer', array($this, 'custom_footer_right'), 999);

        // Dark Mode Toggle
        if (!empty($settings['enable_dark_mode'])) {
            add_action('admin_bar_menu', array($this, 'add_dark_mode_toggle'), 999);
            add_action('admin_enqueue_scripts', array($this, 'enqueue_dark_mode_scripts'));
            add_action('wp_ajax_ofast_toggle_dark_mode', array($this, 'ajax_toggle_dark_mode'));
        }
    }

    /**
     * Add Dark Mode Toggle to Admin Bar
     */
    public function add_dark_mode_toggle($wp_admin_bar)
    {
        $is_dark = get_user_meta(get_current_user_id(), 'ofast_dark_mode', true);
        $icon = $is_dark ? 'dashicons-sun' : 'dashicons-moon';
        $title = $is_dark ? 'Light Mode' : 'Dark Mode';

        $wp_admin_bar->add_node(array(
            'id'    => 'ofast-dark-mode',
            'title' => '<span class="ab-icon dashicons ' . $icon . '" style="margin-top: 4px;"></span><span class="ab-label">' . $title . '</span>',
            'href'  => '#',
            'meta'  => array(
                'onclick' => 'return false;',
                'class'   => 'ofast-dark-mode-toggle',
                'title'   => 'Toggle Dark Mode'
            ),
        ));
    }

    /**
     * AJAX Handler for toggling dark mode
     */
    public function ajax_toggle_dark_mode()
    {
        check_ajax_referer('ofast_dark_mode_nonce', 'nonce');
        
        $current_mode = get_user_meta(get_current_user_id(), 'ofast_dark_mode', true);
        $new_mode = !$current_mode;
        
        update_user_meta(get_current_user_id(), 'ofast_dark_mode', $new_mode);
        
        wp_send_json_success(array('is_dark' => $new_mode));
    }

    /**
     * Enqueue Dark Mode Scripts & Styles
     */
    public function enqueue_dark_mode_scripts()
    {
        $is_dark = get_user_meta(get_current_user_id(), 'ofast_dark_mode', true);
        
        // CSS Variables for Dark Mode
        $css = "
            :root {
                --ofast-dark-bg: #111827;
                --ofast-dark-card: #1f2937;
                --ofast-dark-text: #f3f4f6;
                --ofast-dark-border: #374151;
            }
            
            body.ofast-dark-mode {
                background: var(--ofast-dark-bg) !important;
                color: var(--ofast-dark-text) !important;
            }
            body.ofast-dark-mode #wpadminbar,
            body.ofast-dark-mode #adminmenu,
            body.ofast-dark-mode #adminmenuback,
            body.ofast-dark-mode #adminmenuwrap {
                background: #000 !important;
            }
            body.ofast-dark-mode .postbox,
            body.ofast-dark-mode .wrap .ofast-card,
            body.ofast-dark-mode #wpbody-content .wrap {
                background-color: var(--ofast-dark-card) !important;
                color: var(--ofast-dark-text) !important;
                border-color: var(--ofast-dark-border) !important;
            }
            body.ofast-dark-mode input,
            body.ofast-dark-mode textarea,
            body.ofast-dark-mode select {
                background-color: #374151 !important;
                color: #fff !important;
                border-color: #4b5563 !important;
            }
            body.ofast-dark-mode a {
                color: #818cf8;
            }
            body.ofast-dark-mode h1, body.ofast-dark-mode h2, body.ofast-dark-mode h3 {
                color: #fff !important;
            }
        ";

        if ($is_dark) {
            $css .= "body { background: #111827 !important; }"; // Instant apply to prevent flash
        }

        wp_add_inline_style('common', $css);

        // JS for Toggle
        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', "
            jQuery(document).ready(function($) {
                var isDark = " . ($is_dark ? 'true' : 'false') . ";
                if(isDark) $('body').addClass('ofast-dark-mode');

                $('#wp-admin-bar-ofast-dark-mode').on('click', function(e) {
                    e.preventDefault();
                    
                    $('body').toggleClass('ofast-dark-mode');
                    isDark = !isDark;
                    
                    // Update Text & Icon
                    var label = isDark ? 'Light Mode' : 'Dark Mode';
                    var iconRemove = isDark ? 'dashicons-moon' : 'dashicons-sun';
                    var iconAdd = isDark ? 'dashicons-sun' : 'dashicons-moon';
                    
                    $(this).find('.ab-label').text(label);
                    $(this).find('.ab-icon').removeClass(iconRemove).addClass(iconAdd);
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'ofast_toggle_dark_mode',
                            nonce: '" . wp_create_nonce('ofast_dark_mode_nonce') . "'
                        }
                    });
                });
            });
        ");
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'Admin Footer',
            'Admin Footer',
            'manage_options',
            'ofast-admin-footer',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Handle settings save
     */
    public function handle_save()
    {
        if (!isset($_POST['ofast_save_admin_footer'])) {
            return;
        }

        check_admin_referer('ofast_admin_footer_save', '_wpnonce');

        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = array(
            'left_text' => wp_kses_post($_POST['footer_left_text'] ?? ''),
            'right_text' => sanitize_text_field($_POST['footer_right_text'] ?? ''),
            'hide_wp_version' => isset($_POST['hide_wp_version']) ? 1 : 0,
            'enable_custom_dashboard' => isset($_POST['enable_custom_dashboard']) ? 1 : 0,
            'enable_dark_mode' => isset($_POST['enable_dark_mode']) ? 1 : 0,
        );

        update_option('ofast_admin_footer_settings', $settings);

        wp_redirect(add_query_arg('settings_saved', '1', wp_get_referer()));
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
            wp_die('Unauthorized');
        }

        $settings = get_option('ofast_admin_footer_settings', array(
            'left_text' => '',
            'right_text' => '',
            'hide_wp_version' => 0,
        ));

        $saved = isset($_GET['settings-updated']) || !empty($_GET['settings_saved']);
        if ($saved) {
            Ofast_X_Toast::add('Footer settings saved successfully!', 'success');
        }
?>
        <div class="wrap ofast-admin-footer-wrap">
            <!-- Modern Header - White with Glassmorphism Icon -->
            <div class="ofast-page-header">
                <div class="ofast-header-content">
                    <div class="ofast-header-icon">
                        <span class="dashicons dashicons-editor-kitchensink"></span>
                    </div>
                    <div class="ofast-header-text">
                        <h1>Admin Footer</h1>
                        <p>Customize the footer text shown at the bottom of WordPress admin pages</p>
                    </div>
                </div>
            </div>

            <div class="ofast-content-grid">
                <!-- Main Settings Card -->
                <div class="ofast-card ofast-main-card">
                    <div class="ofast-card-header">
                        <span class="dashicons dashicons-edit"></span>
                        <h2>Footer Settings</h2>
                    </div>
                    <div class="ofast-card-body">
                        <form method="post" action="" class="ofast-modern-form">
                            <?php wp_nonce_field('ofast_admin_footer_save', '_wpnonce'); ?>

                            <div class="ofast-form-group">
                                <label for="footer_left_text">
                                    Left Footer Text
                                    <span class="ofast-tooltip" title="Replaces 'Thank you for creating with WordPress.' HTML is allowed.">
                                        <span class="dashicons dashicons-info-outline"></span>
                                    </span>
                                </label>
                                <textarea name="footer_left_text" id="footer_left_text" rows="3"
                                    placeholder="e.g., Designed by Your Company | Contact: info@example.com"><?php echo esc_textarea($settings['left_text'] ?? ''); ?></textarea>
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
                                    value="<?php echo esc_attr($settings['right_text'] ?? ''); ?>"
                                    placeholder="e.g., v1.0.0">
                                <span class="ofast-field-hint">Custom text for the right footer area</span>
                            </div>

                            <div class="ofast-form-group">
                                <label class="ofast-checkbox-label">
                                    <input type="checkbox" name="hide_wp_version" value="1"
                                        <?php checked(!empty($settings['hide_wp_version'])); ?>>
                                    <span class="ofast-checkbox-custom"></span>
                                    <span class="ofast-checkbox-text">
                                        Hide WordPress version number
                                        <span class="ofast-security-badge">Security Recommended</span>
                                    </span>
                                </label>
                                    </span>
                                </label>
                            </div>

                            <div class="ofast-form-group">
                                <label class="ofast-checkbox-label">
                                    <input type="checkbox" name="enable_custom_dashboard" value="1"
                                        <?php checked(!empty($settings['enable_custom_dashboard'])); ?>>
                                    <span class="ofast-checkbox-custom"></span>
                                    <span class="ofast-checkbox-text">
                                        Enable Custom Dashboard
                                        <span class="ofast-security-badge" style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);">New Feature</span>
                                    </span>
                                </label>
                                    </span>
                                </label>
                            </div>

                            <div class="ofast-form-group">
                                <label class="ofast-checkbox-label">
                                    <input type="checkbox" name="enable_dark_mode" value="1"
                                        <?php checked(!empty($settings['enable_dark_mode'])); ?>>
                                    <span class="ofast-checkbox-custom"></span>
                                    <span class="ofast-checkbox-text">
                                        Enable Dark/Light Mode Toggle
                                        <span class="ofast-security-badge" style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);">UI Feature</span>
                                    </span>
                                </label>
                            </div>

                            <div class="ofast-form-actions">
                                <button type="submit" name="ofast_save_admin_footer" class="ofast-btn-primary">
                                    Save Footer Settings
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Preview Card -->
                <div class="ofast-card ofast-preview-card">
                    <div class="ofast-card-header">
                        <span class="dashicons dashicons-visibility"></span>
                        <h2>Live Preview</h2>
                    </div>
                    <div class="ofast-card-body">
                        <div class="ofast-preview-widget">
                            <div class="ofast-preview-footer">
                                <span class="ofast-preview-left" id="preview-left"><?php echo !empty($settings['left_text']) ? wp_kses_post($this->replace_shortcuts($settings['left_text'])) : '<em>Thank you for creating with WordPress.</em>'; ?></span>
                                <span class="ofast-preview-right" id="preview-right"><?php echo !empty($settings['right_text']) ? esc_html($settings['right_text']) : (!empty($settings['hide_wp_version']) ? '' : '<em>Version X.X</em>'); ?></span>
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

        <style>
            .ofast-admin-footer-wrap {
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
            .ofast-checkbox-text .dashicons {
                color: #6366f1;
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
            .ofast-btn-primary:disabled {
                background: #94a3b8;
                cursor: not-allowed;
                transform: none;
                box-shadow: none;
            }
            .ofast-btn-primary .dashicons {
                font-size: 18px;
                width: 18px;
                height: 18px;
            }
            .ofast-btn-primary .dashicons-update {
                animation: ofast-spin 1s linear infinite;
            }
            @keyframes ofast-spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }

            /* Preview Card */
            .ofast-preview-widget {
                background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
                border-radius: 12px;
                padding: 20px;
                border: 1px solid #e2e8f0;
            }
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
                // Live preview
                $('#footer_left_text').on('input', function() {
                    var text = $(this).val() || '<em>Thank you for creating with WordPress.</em>';
                    $('#preview-left').html(text);
                });
                $('#footer_right_text').on('input', function() {
                    var text = $(this).val() || '<?php echo empty($settings['hide_wp_version']) ? '<em>Version X.X</em>' : ''; ?>';
                    $('#preview-right').html(text || '<em>Version X.X</em>');
                });
                $('#hide_wp_version').on('change', function() {
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
