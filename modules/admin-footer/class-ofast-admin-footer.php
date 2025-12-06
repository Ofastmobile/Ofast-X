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
            return;
        }

        // Add settings submenu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_save'));

        // Override admin footer text
        add_filter('admin_footer_text', array($this, 'custom_footer_left'), 999);
        add_filter('update_footer', array($this, 'custom_footer_right'), 999);
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
        );

        update_option('ofast_admin_footer_settings', $settings);

        add_settings_error('ofast_admin_footer', 'saved', 'Footer settings saved!', 'success');
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

        settings_errors('ofast_admin_footer');
?>
        <div class="wrap">
            <h1>📝 Custom Admin Footer</h1>
            <p class="description">Customize the footer text shown at the bottom of WordPress admin pages.</p>

            <form method="post" action="">
                <?php wp_nonce_field('ofast_admin_footer_save', '_wpnonce'); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="footer_left_text">Left Footer Text</label></th>
                        <td>
                            <textarea name="footer_left_text" id="footer_left_text" rows="3" class="large-text"
                                placeholder="e.g., Designed by Your Company | Contact: info@example.com"><?php echo esc_textarea($settings['left_text'] ?? ''); ?></textarea>
                            <p class="description">
                                Replaces "Thank you for creating with WordPress." HTML allowed.<br>
                                Available shortcuts: <code>{site_name}</code> <code>{year}</code> <code>{admin_email}</code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="footer_right_text">Right Footer Text</label></th>
                        <td>
                            <input type="text" name="footer_right_text" id="footer_right_text" class="regular-text"
                                value="<?php echo esc_attr($settings['right_text'] ?? ''); ?>"
                                placeholder="e.g., v1.0.0">
                            <p class="description">Replaces the WordPress version number.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Hide WP Version</th>
                        <td>
                            <label>
                                <input type="checkbox" name="hide_wp_version" value="1"
                                    <?php checked(!empty($settings['hide_wp_version'])); ?>>
                                Hide WordPress version number (security recommended)
                            </label>
                        </td>
                    </tr>
                </table>

                <!-- Preview Box -->
                <div style="background: #f0f0f1; border: 1px solid #c3c4c7; border-radius: 4px; padding: 15px; margin: 20px 0; max-width: 800px;">
                    <h3 style="margin-top: 0;">👁 Preview</h3>
                    <div style="display: flex; justify-content: space-between; background: #fff; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                        <span id="preview-left"><?php echo !empty($settings['left_text']) ? wp_kses_post($settings['left_text']) : '<em style="color:#999">Thank you for creating with WordPress.</em>'; ?></span>
                        <span id="preview-right"><?php echo !empty($settings['right_text']) ? esc_html($settings['right_text']) : (!empty($settings['hide_wp_version']) ? '' : '<em style="color:#999">Version X.X</em>'); ?></span>
                    </div>
                </div>

                <p class="submit">
                    <button type="submit" name="ofast_save_admin_footer" class="button button-primary button-large">
                        💾 Save Footer Settings
                    </button>
                </p>
            </form>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Live preview
                $('#footer_left_text').on('input', function() {
                    var text = $(this).val() || '<em style="color:#999">Thank you for creating with WordPress.</em>';
                    $('#preview-left').html(text);
                });
                $('#footer_right_text').on('input', function() {
                    var text = $(this).val() || '<?php echo empty($settings['hide_wp_version']) ? '<em style="color:#999">Version X.X</em>' : ''; ?>';
                    $('#preview-right').text(text);
                });
            });
        </script>
<?php
    }
}
