<?php
/**
 * Email Tab: Templates
 * Visual email template designer with live preview
 */

if (!defined('ABSPATH')) exit;

class Ofast_Email_Tab_Templates
{
    /**
     * Render templates tab (content only, used inside tabs)
     */
    public function render()
    {
        // Handle reset
        if (isset($_POST['ofast_reset_template']) && wp_verify_nonce($_POST['_wpnonce'], 'ofast_template_save')) {
            $this->reset_settings();
            echo Ofast_X_Toast::render('Template settings reset to defaults!', 'success');
        }

        // Handle send test email
        if (isset($_POST['ofast_send_test_template']) && wp_verify_nonce($_POST['_wpnonce'], 'ofast_template_save')) {
            $admin_email = get_option('admin_email');
            $test_content = '<p>This is a <strong>test email</strong> from ' . esc_html(get_bloginfo('name')) . '.</p>
                <p>If you can see this email with your logo, colors, and branding - your email template is working correctly!</p>
                <p>You can now send beautiful emails to your users.</p>';

            require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-template.php';
            $html = Ofast_X_Email_Template::get_template($test_content);
            $headers = Ofast_X_Email::get_safe_email_headers();
            $sent = wp_mail($admin_email, sprintf(__('[%s] Test Email - Template Preview', 'ofast-x'), get_bloginfo('name')), $html, $headers);

            if ($sent) {
                echo Ofast_X_Toast::render('Test email sent to ' . esc_html($admin_email), 'success');
            } else {
                echo Ofast_X_Toast::render('Failed to send test email. Please check your email configuration.', 'error');
            }
        }

        // Handle save
        if (isset($_POST['ofast_save_template']) && wp_verify_nonce($_POST['_wpnonce'], 'ofast_template_save')) {
            $this->save_settings();
            echo Ofast_X_Toast::render('Template settings saved!', 'success');
        }

        // Get current settings
        $style = get_option('ofast_email_template_style', 'modern');
        $primary = get_option('ofast_email_primary_color', '#6366f1');
        $accent = get_option('ofast_email_accent_color', '#10b981');
        $bg = get_option('ofast_email_bg_color', '#f8fafc');
        $text = get_option('ofast_email_text_color', '#1e293b');
        $logo = get_option('ofast_email_logo', '');
        $company = get_option('ofast_email_company_name', get_bloginfo('name'));
        $tagline = get_option('ofast_email_tagline', '');
        $show_header = get_option('ofast_email_show_header', true);
        $show_footer = get_option('ofast_email_show_footer', true);
        $from_name = get_option('ofast_email_from_name', get_bloginfo('name'));
        $reply_to = get_option('ofast_email_reply_to', get_option('admin_email'));
        $social = get_option('ofast_email_social', array());
        $apply_to = get_option('ofast_email_apply_to', array('emailer'));
        $font_family = get_option('ofast_email_font_family', 'system');
        $font_size = get_option('ofast_email_font_size', '15');
        $logo_width = get_option('ofast_email_logo_width', '120');
        $logo_height = get_option('ofast_email_logo_height', '0');

        ?>
<div class="ofast-template-layout">
            <!-- Left Column: Settings -->
            <div class="ofast-template-settings">
                <form method="post">
                    <?php wp_nonce_field('ofast_template_save'); ?>

                    <!-- Template Style -->
                    <div class="ofast-card" style="padding: 20px; margin-bottom: 20px;">
                        <h3 style="margin: 0 0 15px 0; font-size: 16px;">Template Style</h3>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;" id="ofast-style-selector">
                            <label class="ofast-style-label"
                                style="flex: 1; min-width: 100px; text-align: center; padding: 15px 10px; border: 2px solid <?php echo $style === 'modern' ? '#6366f1' : '#e2e8f0'; ?>; border-radius: 8px; cursor: <?php echo ofast_toolkit_is_pro() ? 'pointer' : 'not-allowed'; ?>; background: <?php echo $style === 'modern' ? '#eff6ff' : '#fff'; ?>; transition: all 0.2s;<?php echo !ofast_toolkit_is_pro() ? ' opacity: 0.6;' : ''; ?>">
                                <input type="radio" name="template_style" value="modern" <?php checked($style, 'modern'); ?>
                                    style="display: none;" <?php ofast_toolkit_pro_disabled(); ?>>
                                <div style="font-weight: 600;">Modern <?php ofast_toolkit_pro_badge(); ?></div>
                                <small style="color: #64748b;">Gradient header</small>
                            </label>
                            <label class="ofast-style-label"
                                style="flex: 1; min-width: 100px; text-align: center; padding: 15px 10px; border: 2px solid <?php echo $style === 'classic' ? '#6366f1' : '#e2e8f0'; ?>; border-radius: 8px; cursor: pointer; background: <?php echo $style === 'classic' ? '#eff6ff' : '#fff'; ?>; transition: all 0.2s;">
                                <input type="radio" name="template_style" value="classic" <?php checked($style, 'classic'); ?>
                                    style="display: none;">
                                <div style="font-weight: 600;">Classic</div>
                                <small style="color: #64748b;">Solid header</small>
                            </label>
                            <label class="ofast-style-label"
                                style="flex: 1; min-width: 100px; text-align: center; padding: 15px 10px; border: 2px solid <?php echo $style === 'minimal' ? '#6366f1' : '#e2e8f0'; ?>; border-radius: 8px; cursor: pointer; background: <?php echo $style === 'minimal' ? '#eff6ff' : '#fff'; ?>; transition: all 0.2s;">
                                <input type="radio" name="template_style" value="minimal" <?php checked($style, 'minimal'); ?>
                                    style="display: none;">
                                <div style="font-weight: 600;">Minimal</div>
                                <small style="color: #64748b;">Clean, no header</small>
                            </label>
                            <label class="ofast-style-label"
                                style="flex: 1; min-width: 100px; text-align: center; padding: 15px 10px; border: 2px solid <?php echo $style === 'custom' ? '#6366f1' : '#e2e8f0'; ?>; border-radius: 8px; cursor: <?php echo ofast_toolkit_is_pro() ? 'pointer' : 'not-allowed'; ?>; background: <?php echo $style === 'custom' ? '#eff6ff' : '#fff'; ?>; transition: all 0.2s;<?php echo !ofast_toolkit_is_pro() ? ' opacity: 0.6;' : ''; ?>">
                                <input type="radio" name="template_style" value="custom" <?php checked($style, 'custom'); ?>
                                    style="display: none;" <?php ofast_toolkit_pro_disabled(); ?>>
                                <div style="font-weight: 600;">Custom <?php ofast_toolkit_pro_badge(); ?></div>
                                <small style="color: #64748b;">Your own HTML</small>
                            </label>
                        </div>

                        <!-- Custom Template Editor -->
                        <div id="ofast-custom-template-wrap"
                            style="margin-top: 15px; display: <?php echo $style === 'custom' ? 'block' : 'none'; ?>;">
                            <p class="description" style="margin: 0 0 10px;">Paste your custom HTML email template below. Use
                                <code>{{content}}</code> as the placeholder where your email body will be inserted.
                            </p>
                            <textarea name="custom_template" id="ofast_custom_template" rows="14"
                                style="width: 100%; font-family: monospace; font-size: 13px; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; background: #1e293b; color: #e2e8f0; resize: vertical;"><?php echo esc_textarea(get_option('ofast_email_custom_template', '')); ?></textarea>
                            <p class="description" style="margin: 8px 0 0; color: #94a3b8;">Tip: Include full HTML structure
                                (&lt;html&gt;, &lt;body&gt;, etc.) for best results. The <code>{{content}}</code> tag will be
                                replaced with the email message.</p>
                        </div>
                    </div>

                    <!-- Colors -->
                    <div class="ofast-card" style="padding: 20px; margin-bottom: 20px;">
                        <h3 style="margin: 0 0 15px 0; font-size: 16px;">Colors</h3>
                        <table class="form-table" style="margin: 0;">
                            <tr><th style="width: 100px; padding: 10px 0;">Primary</th>
                                <td style="padding: 10px 0;"><input type="text" name="primary_color" value="<?php echo esc_attr($primary); ?>" class="ofast-color-picker"></td></tr>
                            <tr><th style="padding: 10px 0;">Accent</th>
                                <td style="padding: 10px 0;"><input type="text" name="accent_color" value="<?php echo esc_attr($accent); ?>" class="ofast-color-picker"></td></tr>
                            <tr><th style="padding: 10px 0;">Background</th>
                                <td style="padding: 10px 0;"><input type="text" name="bg_color" value="<?php echo esc_attr($bg); ?>" class="ofast-color-picker"></td></tr>
                            <tr><th style="padding: 10px 0;">Text</th>
                                <td style="padding: 10px 0;"><input type="text" name="text_color" value="<?php echo esc_attr($text); ?>" class="ofast-color-picker"></td></tr>
                        </table>
                    </div>

                    <!-- Typography -->
                    <div class="ofast-card" style="padding: 20px; margin-bottom: 20px;">
                        <h3 style="margin: 0 0 15px 0; font-size: 16px;">Typography</h3>
                        <table class="form-table" style="margin: 0;">
                            <tr><th style="width: 100px; padding: 10px 0;">Font</th>
                                <td style="padding: 10px 0;">
                                    <select name="font_family" id="font_family" style="width: 100%;">
                                        <option value="system" <?php selected($font_family, 'system'); ?>>System Default</option>
                                        <option value="inter" <?php selected($font_family, 'inter'); ?>>Inter</option>
                                        <option value="roboto" <?php selected($font_family, 'roboto'); ?>>Roboto</option>
                                        <option value="opensans" <?php selected($font_family, 'opensans'); ?>>Open Sans</option>
                                        <option value="lato" <?php selected($font_family, 'lato'); ?>>Lato</option>
                                        <option value="poppins" <?php selected($font_family, 'poppins'); ?>>Poppins</option>
                                        <option value="georgia" <?php selected($font_family, 'georgia'); ?>>Georgia (Serif)</option>
                                    </select>
                                </td></tr>
                            <tr><th style="padding: 10px 0;">Size</th>
                                <td style="padding: 10px 0;">
                                    <select name="font_size" id="font_size" style="width: 100%;">
                                        <option value="13" <?php selected($font_size, '13'); ?>>Small (13px)</option>
                                        <option value="14" <?php selected($font_size, '14'); ?>>Medium (14px)</option>
                                        <option value="15" <?php selected($font_size, '15'); ?>>Default (15px)</option>
                                        <option value="16" <?php selected($font_size, '16'); ?>>Large (16px)</option>
                                        <option value="17" <?php selected($font_size, '17'); ?>>Extra Large (17px)</option>
                                    </select>
                                </td></tr>
                        </table>
                    </div>

                    <!-- Branding -->
                    <div class="ofast-card" style="padding: 20px; margin-bottom: 20px;">
                        <h3 style="margin: 0 0 15px 0; font-size: 16px;">Branding</h3>
                        <table class="form-table" style="margin: 0;">
                            <tr><th style="width: 100px; padding: 10px 0;">Logo</th>
                                <td style="padding: 10px 0;">
                                    <input type="text" name="logo_url" id="logo_url" value="<?php echo esc_url($logo); ?>" style="width: 100%; margin-bottom: 5px;" placeholder="https://">
                                    <button type="button" class="button" id="upload_logo_btn">Upload Image</button>
                                </td></tr>
                            <tr><th style="padding: 10px 0;">Logo Size</th>
                                <td style="padding: 10px 0; display: flex; gap: 10px; align-items: center;">
                                    <label>W: <input type="number" name="logo_width" id="logo_width" value="<?php echo esc_attr($logo_width); ?>" style="width: 60px;" min="30" max="300"> px</label>
                                    <label>H: <input type="number" name="logo_height" id="logo_height" value="<?php echo esc_attr($logo_height); ?>" style="width: 60px;" min="0" max="200" placeholder="auto"> px</label>
                                    <small style="color: #64748b;">(0 = auto)</small>
                                </td></tr>
                            <tr><th style="padding: 10px 0;">Company</th>
                                <td style="padding: 10px 0;"><input type="text" name="company_name" value="<?php echo esc_attr($company); ?>" style="width: 100%;"></td></tr>
                            <tr><th style="padding: 10px 0;">Tagline</th>
                                <td style="padding: 10px 0;"><input type="text" name="tagline" value="<?php echo esc_attr($tagline); ?>" style="width: 100%;"></td></tr>
                            <tr><th style="padding: 10px 0;">From Name</th>
                                <td style="padding: 10px 0;"><input type="text" name="from_name" value="<?php echo esc_attr($from_name); ?>" style="width: 100%;" placeholder="Sender name for emails"></td></tr>
                            <tr><th style="padding: 10px 0;">Reply-to</th>
                                <td style="padding: 10px 0;"><input type="email" name="reply_to" value="<?php echo esc_attr($reply_to); ?>" style="width: 100%;" placeholder="email@example.com"></td></tr>
                        </table>
                    </div>

                    <!-- Header/Footer -->
                    <div class="ofast-card" style="padding: 20px; margin-bottom: 20px;">
                        <h3 style="margin: 0 0 15px 0; font-size: 16px;">Sections</h3>
                        <label style="display: block; margin-bottom: 10px;">
                            <input type="checkbox" name="show_header" value="1" <?php checked($show_header); ?>> Show Header
                        </label>
                        <label style="display: block;">
                            <input type="checkbox" name="show_footer" value="1" <?php checked($show_footer); ?>> Show Footer
                        </label>
                    </div>

                    <!-- Social Links -->
                    <div class="ofast-card" style="padding: 20px; margin-bottom: 20px;">
                        <h3 style="margin: 0 0 15px 0; font-size: 16px;">Social Links</h3>
                        <?php
                        $platforms = array('facebook', 'x', 'youtube', 'whatsapp', 'instagram', 'linkedin', 'telegram');
                        foreach ($platforms as $p) {
                            $val = $social[$p] ?? '';
                            echo '<div style="margin-bottom: 8px;"><label style="display: flex; align-items: center; gap: 8px;">';
                            echo '<span style="width: 70px; text-transform: capitalize;">' . esc_html($p) . '</span>';
                            echo '<input type="url" name="social[' . $p . ']" value="' . esc_url($val) . '" style="flex: 1;" placeholder="https://">';
                            echo '</label></div>';
                        }
                        ?>
                    </div>

                    <!-- Apply To -->
                    <div class="ofast-card" style="padding: 20px; margin-bottom: 20px;">
                        <h3 style="margin: 0 0 15px 0; font-size: 16px;">Apply Template To</h3>
                        <p style="margin: 0 0 10px 0; font-size: 12px; color: #64748b;">Select which email types should use this template:</p>
                        <?php
                        $email_types = array(
                            'emailer' => 'Ofast Emailer (campaigns)',
                            'notifications' => 'WordPress Notifications',
                            'woocommerce' => 'WooCommerce Emails',
                            'all_wp' => 'All WordPress Emails'
                        );
                        foreach ($email_types as $key => $label) {
                            $checked = in_array($key, (array) $apply_to) ? 'checked' : '';
                            echo '<label style="display: block; margin-bottom: 6px;">';
                            echo '<input type="checkbox" name="apply_to[]" value="' . $key . '" ' . $checked . '> ' . esc_html($label);
                            echo '</label>';
                        }
                        ?>
                    </div>

                    <!-- Buttons -->
                    <div style="margin-top: 30px; display: flex; gap: 12px; flex-wrap: wrap;">
                        <button type="submit" name="ofast_save_template" class="button button-primary button-large ofast-template-btn" style="flex: 1;"><span class="dashicons dashicons-saved"></span> Save Changes</button>
                        <button type="submit" name="ofast_send_test_template" class="button button-secondary button-large ofast-template-btn" style="flex: 1;"><span class="dashicons dashicons-email"></span> Send Test</button>
                        <button type="submit" name="ofast_reset_template" class="button button-large ofast-template-btn ofast-reset-btn" style="flex: 1;" onclick="return confirm('Reset all template settings details?');"><span class="dashicons dashicons-image-rotate"></span> Reset</button>
                    </div>

                </form>
            </div>

            <!-- Right Column: Live Preview -->
            <div class="ofast-template-preview">
                <div class="ofast-card" style="padding: 0; overflow: hidden; height: 100%;">
                    <div style="padding: 15px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="margin: 0; font-size: 14px; font-weight: 600; color: #64748b;">Live Preview</h3>
                        <span class="dashicons dashicons-desktop" style="color: #64748b;"></span>
                    </div>
                    <iframe id="template-preview" sandbox style="width: 100%; height: 800px; border: none; display: block;"></iframe>
                </div>
            </div>
        </div>
<?php
    }

    /**
     * Save template settings
     */
    public function save_settings()
    {
        $template_style = sanitize_text_field($_POST['template_style'] ?? 'modern');
        if (!ofast_toolkit_is_pro() && in_array($template_style, array('modern', 'custom'), true)) {
            $template_style = 'classic';
        }
        update_option('ofast_email_template_style', $template_style);
        update_option('ofast_email_primary_color', sanitize_hex_color($_POST['primary_color'] ?? '#6366f1'));
        update_option('ofast_email_accent_color', sanitize_hex_color($_POST['accent_color'] ?? '#10b981'));
        update_option('ofast_email_bg_color', sanitize_hex_color($_POST['bg_color'] ?? '#f8fafc'));
        update_option('ofast_email_text_color', sanitize_hex_color($_POST['text_color'] ?? '#1e293b'));
        update_option('ofast_email_logo', esc_url_raw($_POST['logo_url'] ?? ''));
        update_option('ofast_email_company_name', sanitize_text_field($_POST['company_name'] ?? ''));
        update_option('ofast_email_tagline', sanitize_text_field($_POST['tagline'] ?? ''));
        update_option('ofast_email_show_header', isset($_POST['show_header']));
        update_option('ofast_email_show_footer', isset($_POST['show_footer']));
        update_option('ofast_email_from_name', sanitize_text_field($_POST['from_name'] ?? get_bloginfo('name')));
        update_option('ofast_email_reply_to', sanitize_email($_POST['reply_to'] ?? get_option('admin_email')));
        update_option('ofast_email_social', array_map('esc_url_raw', $_POST['social'] ?? array()));
        update_option('ofast_email_apply_to', array_map('sanitize_text_field', $_POST['apply_to'] ?? array('emailer')));
        update_option('ofast_email_font_family', sanitize_text_field($_POST['font_family'] ?? 'system'));
        update_option('ofast_email_font_size', absint($_POST['font_size'] ?? 15));
        update_option('ofast_email_logo_width', absint($_POST['logo_width'] ?? 120));
        update_option('ofast_email_logo_height', absint($_POST['logo_height'] ?? 0));

        if (!empty($_POST['custom_template'])) {
            update_option('ofast_email_custom_template', wp_unslash($_POST['custom_template']));
        } else {
            update_option('ofast_email_custom_template', '');
        }

        update_option('ofast_email_cron_enabled', isset($_POST['queue_enabled']) ? 1 : 0);
        update_option('ofast_email_queue_enabled', isset($_POST['queue_enabled']) ? true : false);
        update_option('ofast_email_rate_per_hour', max(10, min(500, absint($_POST['emails_per_hour'] ?? 30))));
        update_option('ofast_email_batch_size', max(1, min(50, absint($_POST['emails_per_batch'] ?? 5))));
    }

    /**
     * Reset template settings to defaults
     */
    public function reset_settings()
    {
        update_option('ofast_email_template_style', 'modern');
        update_option('ofast_email_primary_color', '#6366f1');
        update_option('ofast_email_accent_color', '#10b981');
        update_option('ofast_email_bg_color', '#f8fafc');
        update_option('ofast_email_text_color', '#1e293b');
        update_option('ofast_email_logo', '');
        update_option('ofast_email_company_name', get_bloginfo('name'));
        update_option('ofast_email_tagline', '');
        update_option('ofast_email_show_header', true);
        update_option('ofast_email_show_footer', true);
        update_option('ofast_email_from_name', get_bloginfo('name'));
        update_option('ofast_email_reply_to', get_option('admin_email'));
        update_option('ofast_email_social', array());
        update_option('ofast_email_apply_to', array('emailer'));
        update_option('ofast_email_font_family', 'system');
        update_option('ofast_email_font_size', '15');
        update_option('ofast_email_logo_width', '120');
        update_option('ofast_email_logo_height', '0');
    }
}
