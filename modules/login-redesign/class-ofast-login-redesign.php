<?php

/**
 * Ofast X - Login Redesign Module
 * Simple customizer for WordPress login page
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Login_Redesign
{
    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Constructor
    }

    /**
     * Initialize hooks
     */
    public function init()
    {
        // Admin
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_settings_save'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Frontend (login page)
        add_action('login_enqueue_scripts', array($this, 'inject_login_styles'));
        add_filter('login_headerurl', array($this, 'custom_logo_url'));
        add_filter('login_headertext', array($this, 'custom_logo_text'));
    }

    /**
     * Check if module is enabled
     */
    public function is_enabled()
    {
        return get_option('ofast_login_redesign_enabled', false);
    }

    /**
     * Get all settings
     */
    public function get_settings()
    {
        return array(
            'enabled' => get_option('ofast_login_redesign_enabled', false),
            'logo_url' => get_option('ofast_login_logo_url', ''),
            'logo_width' => get_option('ofast_login_logo_width', '84'),
            'logo_height' => get_option('ofast_login_logo_height', '84'),
            'bg_color' => get_option('ofast_login_bg_color', '#f0f0f1'),
            'bg_image' => get_option('ofast_login_bg_image', ''),
            'form_bg' => get_option('ofast_login_form_bg', '#ffffff'),
            'form_radius' => get_option('ofast_login_form_radius', '4'),
            'btn_color' => get_option('ofast_login_btn_color', '#2271b1'),
            'btn_hover' => get_option('ofast_login_btn_hover', '#135e96'),
            'btn_text_color' => get_option('ofast_login_btn_text_color', '#ffffff'),
            'link_color' => get_option('ofast_login_link_color', '#50575e'),
            'link_hover' => get_option('ofast_login_link_hover', '#2271b1'),
            'input_radius' => get_option('ofast_login_input_radius', '4'),
            'hide_back_link' => get_option('ofast_login_hide_back_link', false),
            'custom_css' => get_option('ofast_login_custom_css', ''),
        );
    }

    /**
     * Get default settings
     */
    public function get_defaults()
    {
        return array(
            'enabled' => false,
            'logo_url' => '',
            'logo_width' => '84',
            'logo_height' => '84',
            'bg_color' => '#f0f0f1',
            'bg_image' => '',
            'form_bg' => '#ffffff',
            'form_radius' => '4',
            'btn_color' => '#2271b1',
            'btn_hover' => '#135e96',
            'btn_text_color' => '#ffffff',
            'link_color' => '#50575e',
            'link_hover' => '#2271b1',
            'input_radius' => '4',
            'hide_back_link' => false,
            'custom_css' => '',
        );
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'Login Redesign',
            'Login Redesign',
            'manage_options',
            'ofast-login-redesign',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook)
    {
        if (empty($hook) || strpos($hook, 'ofast-login-redesign') === false) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
    }

    /**
     * Handle settings save
     */
    public function handle_settings_save()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle Reset
        if (isset($_POST['ofast_login_redesign_reset'])) {
            if (!wp_verify_nonce($_POST['ofast_login_nonce'] ?? '', 'ofast_login_redesign_settings')) {
                return;
            }

            // Reset all options to defaults
            update_option('ofast_login_redesign_enabled', false);
            update_option('ofast_login_logo_url', '');
            update_option('ofast_login_logo_width', '84');
            update_option('ofast_login_logo_height', '84');
            update_option('ofast_login_bg_color', '#f0f0f1');
            update_option('ofast_login_bg_image', '');
            update_option('ofast_login_form_bg', '#ffffff');
            update_option('ofast_login_form_radius', '4');
            update_option('ofast_login_btn_color', '#2271b1');
            update_option('ofast_login_btn_hover', '#135e96');
            update_option('ofast_login_btn_text_color', '#ffffff');
            update_option('ofast_login_link_color', '#50575e');
            update_option('ofast_login_link_hover', '#2271b1');
            update_option('ofast_login_input_radius', '4');
            update_option('ofast_login_hide_back_link', false);
            update_option('ofast_login_custom_css', '');

            add_settings_error('ofast_login_redesign', 'reset', 'Settings reset to defaults!', 'success');
            return;
        }


        // Handle Save
        if (!isset($_POST['ofast_login_redesign_save'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['ofast_login_nonce'] ?? '', 'ofast_login_redesign_settings')) {
            return;
        }

        update_option('ofast_login_redesign_enabled', isset($_POST['enabled']));
        update_option('ofast_login_logo_url', esc_url_raw($_POST['logo_url'] ?? ''));
        update_option('ofast_login_logo_width', absint($_POST['logo_width'] ?? 84));
        update_option('ofast_login_logo_height', absint($_POST['logo_height'] ?? 84));
        update_option('ofast_login_bg_color', sanitize_hex_color($_POST['bg_color'] ?? '#f0f0f1'));
        update_option('ofast_login_bg_image', esc_url_raw($_POST['bg_image'] ?? ''));
        update_option('ofast_login_form_bg', sanitize_hex_color($_POST['form_bg'] ?? '#ffffff'));
        update_option('ofast_login_form_radius', absint($_POST['form_radius'] ?? 4));
        update_option('ofast_login_btn_color', sanitize_hex_color($_POST['btn_color'] ?? '#2271b1'));
        update_option('ofast_login_btn_hover', sanitize_hex_color($_POST['btn_hover'] ?? '#135e96'));
        update_option('ofast_login_btn_text_color', sanitize_hex_color($_POST['btn_text_color'] ?? '#ffffff'));
        update_option('ofast_login_link_color', sanitize_hex_color($_POST['link_color'] ?? '#50575e'));
        update_option('ofast_login_link_hover', sanitize_hex_color($_POST['link_hover'] ?? '#2271b1'));
        update_option('ofast_login_input_radius', absint($_POST['input_radius'] ?? 4));
        update_option('ofast_login_hide_back_link', isset($_POST['hide_back_link']));
        update_option('ofast_login_custom_css', wp_strip_all_tags($_POST['custom_css'] ?? ''));

        add_settings_error('ofast_login_redesign', 'saved', 'Settings saved!', 'success');
    }

    /**
     * Inject styles on login page
     */
    public function inject_login_styles()
    {
        if (!$this->is_enabled()) {
            return;
        }

        $s = $this->get_settings();

        $css = '/* Ofast X Login Redesign */';

        // Background - applied to body only
        $css .= 'body.login { background-color: ' . esc_attr($s['bg_color']) . ';';
        if (!empty($s['bg_image'])) {
            $css .= 'background-image: url(' . esc_url($s['bg_image']) . ');';
            $css .= 'background-size: cover; background-position: center; background-repeat: no-repeat;';
        }
        $css .= '}';

        // Logo - always style the logo container, use custom or keep default visible
        $css .= '#login h1 a {';
        if (!empty($s['logo_url'])) {
            $css .= 'background-image: url(' . esc_url($s['logo_url']) . ') !important;';
        }
        $css .= 'background-size: contain !important;';
        $css .= 'background-repeat: no-repeat !important;';
        $css .= 'background-position: center !important;';
        $css .= 'width: ' . esc_attr($s['logo_width']) . 'px !important;';
        $css .= 'height: ' . esc_attr($s['logo_height']) . 'px !important;';
        $css .= '}';

        // Form Box
        $css .= '#loginform, #registerform, #lostpasswordform {';
        $css .= 'background: ' . esc_attr($s['form_bg']) . ';';
        $css .= 'border-radius: ' . esc_attr($s['form_radius']) . 'px;';
        $css .= 'box-shadow: 0 4px 20px rgba(0,0,0,0.1);';
        $css .= 'border: none;';
        $css .= '}';

        // Inputs
        $css .= '#loginform input[type="text"], #loginform input[type="password"],';
        $css .= '#registerform input[type="text"], #registerform input[type="email"] {';
        $css .= 'border-radius: ' . esc_attr($s['input_radius']) . 'px;';
        $css .= '}';

        // Button
        $css .= '.wp-core-ui .button-primary {';
        $css .= 'background: ' . esc_attr($s['btn_color']) . ' !important;';
        $css .= 'border-color: ' . esc_attr($s['btn_color']) . ' !important;';
        $css .= 'color: ' . esc_attr($s['btn_text_color']) . ' !important;';
        $css .= 'border-radius: ' . esc_attr($s['input_radius']) . 'px;';
        $css .= '}';

        $css .= '.wp-core-ui .button-primary:hover, .wp-core-ui .button-primary:focus {';
        $css .= 'background: ' . esc_attr($s['btn_hover']) . ' !important;';
        $css .= 'border-color: ' . esc_attr($s['btn_hover']) . ' !important;';
        $css .= '}';

        // Links (Register, Lost Password, Back to blog)
        $css .= '#login #nav a, #login #backtoblog a, .login #nav a, .login #backtoblog a {';
        $css .= 'color: ' . esc_attr($s['link_color']) . ' !important;';
        $css .= '}';

        $css .= '#login #nav a:hover, #login #backtoblog a:hover, .login #nav a:hover, .login #backtoblog a:hover {';
        $css .= 'color: ' . esc_attr($s['link_hover']) . ' !important;';
        $css .= '}';

        // Hide back to blog link
        if ($s['hide_back_link']) {
            $css .= '#backtoblog { display: none; }';
        }

        // Custom CSS
        if (!empty($s['custom_css'])) {
            $css .= wp_strip_all_tags($s['custom_css']);
        }

        echo '<style type="text/css">' . $css . '</style>';
    }

    /**
     * Custom logo URL
     */
    public function custom_logo_url($url)
    {
        if ($this->is_enabled()) {
            return home_url('/');
        }
        return $url;
    }

    /**
     * Custom logo text
     */
    public function custom_logo_text($text)
    {
        if ($this->is_enabled()) {
            return get_bloginfo('name');
        }
        return $text;
    }

    /**
     * Render settings page
     */
    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }

        $s = $this->get_settings();
        $wp_logo = admin_url('images/wordpress-logo.svg');
?>
        <div class="wrap">
            <h1>Login Page Redesign</h1>

            <?php settings_errors('ofast_login_redesign'); ?>

            <div style="display:flex;gap:30px;margin-top:20px;align-items:flex-start;">
                <!-- Settings Panel -->
                <div style="flex:1;max-width:500px;">
                    <form method="post">
                        <?php wp_nonce_field('ofast_login_redesign_settings', 'ofast_login_nonce'); ?>

                        <div class="postbox" style="padding:20px;">
                            <h3 style="margin-top:0;">General</h3>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th>Enable</th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="enabled" value="1" <?php checked($s['enabled']); ?>>
                                            Enable custom login design
                                        </label>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="postbox" style="padding:20px;margin-top:15px;">
                            <h3 style="margin-top:0;">Logo</h3>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th>Logo URL</th>
                                    <td>
                                        <input type="text" name="logo_url" id="logo_url" value="<?php echo esc_url($s['logo_url']); ?>" class="regular-text">
                                        <button type="button" class="button" id="upload_logo">Upload</button>
                                        <?php if ($s['logo_url']): ?>
                                            <br><img src="<?php echo esc_url($s['logo_url']); ?>" style="max-width:100px;margin-top:10px;">
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Logo Size</th>
                                    <td>
                                        <input type="number" name="logo_width" value="<?php echo esc_attr($s['logo_width']); ?>" style="width:80px;"> x
                                        <input type="number" name="logo_height" value="<?php echo esc_attr($s['logo_height']); ?>" style="width:80px;"> px
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="postbox" style="padding:20px;margin-top:15px;">
                            <h3 style="margin-top:0;">Background</h3>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th>Background Color</th>
                                    <td><input type="text" name="bg_color" id="bg_color" value="<?php echo esc_attr($s['bg_color']); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th>Background Image</th>
                                    <td>
                                        <input type="text" name="bg_image" id="bg_image" value="<?php echo esc_url($s['bg_image']); ?>" class="regular-text">
                                        <button type="button" class="button" id="upload_bg">Upload</button>
                                        <?php if ($s['bg_image']): ?>
                                            <button type="button" class="button" id="remove_bg" style="color:#a00;">Remove</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="postbox" style="padding:20px;margin-top:15px;">
                            <h3 style="margin-top:0;">Form Styling</h3>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th>Form Background</th>
                                    <td><input type="text" name="form_bg" id="form_bg" value="<?php echo esc_attr($s['form_bg']); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th>Form Border Radius</th>
                                    <td>
                                        <input type="range" name="form_radius" id="form_radius" min="0" max="30" value="<?php echo esc_attr($s['form_radius']); ?>">
                                        <span id="form_radius_val"><?php echo esc_html($s['form_radius']); ?>px</span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Input Border Radius</th>
                                    <td>
                                        <input type="range" name="input_radius" id="input_radius" min="0" max="20" value="<?php echo esc_attr($s['input_radius']); ?>">
                                        <span id="input_radius_val"><?php echo esc_html($s['input_radius']); ?>px</span>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="postbox" style="padding:20px;margin-top:15px;">
                            <h3 style="margin-top:0;">Button Colors</h3>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th>Button Color</th>
                                    <td><input type="text" name="btn_color" id="btn_color" value="<?php echo esc_attr($s['btn_color']); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th>Button Hover</th>
                                    <td><input type="text" name="btn_hover" id="btn_hover" value="<?php echo esc_attr($s['btn_hover']); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th>Button Text</th>
                                    <td><input type="text" name="btn_text_color" id="btn_text_color" value="<?php echo esc_attr($s['btn_text_color']); ?>" class="color-picker"></td>
                                </tr>
                            </table>
                        </div>

                        <div class="postbox" style="padding:20px;margin-top:15px;">
                            <h3 style="margin-top:0;">Link Colors</h3>
                            <p class="description" style="margin-top:0;">For "Register", "Lost Password", and "Back to" links</p>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th>Link Color</th>
                                    <td><input type="text" name="link_color" id="link_color" value="<?php echo esc_attr($s['link_color']); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th>Link Hover</th>
                                    <td><input type="text" name="link_hover" id="link_hover" value="<?php echo esc_attr($s['link_hover']); ?>" class="color-picker"></td>
                                </tr>
                            </table>
                        </div>

                        <div class="postbox" style="padding:20px;margin-top:15px;">
                            <h3 style="margin-top:0;">Extra Options</h3>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th>Hide "Back to Blog"</th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="hide_back_link" value="1" <?php checked($s['hide_back_link']); ?>>
                                            Hide the "Back to [site]" link
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Custom CSS</th>
                                    <td>
                                        <textarea name="custom_css" rows="5" class="large-text code"><?php echo esc_textarea($s['custom_css']); ?></textarea>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <p style="margin-top:20px;">
                            <button type="submit" name="ofast_login_redesign_save" class="button button-primary button-large">Save Settings</button>
                            <button type="submit" name="ofast_login_redesign_reset" class="button button-secondary button-large" onclick="return confirm('Are you sure you want to reset all settings to defaults?');">Reset to Defaults</button>
                            <a href="<?php echo wp_login_url(); ?>" target="_blank" class="button">View Login Page</a>
                        </p>
                    </form>
                </div>

                <!-- Preview Panel -->
                <div style="flex:1;position:sticky;top:32px;align-self:flex-start;">
                    <div class="postbox" style="padding:20px;">
                        <h3 style="margin-top:0;">Live Preview</h3>
                        <div id="login-preview" style="border:1px solid #ddd;border-radius:8px;overflow:hidden;min-height:400px;background:#f0f0f1;">
                            <!-- Preview rendered here -->
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Initialize color pickers
                $('.color-picker').wpColorPicker({
                    change: function() {
                        setTimeout(updatePreview, 100);
                    }
                });

                // Sliders
                $('#form_radius, #input_radius').on('input', function() {
                    $('#' + this.id + '_val').text($(this).val() + 'px');
                    updatePreview();
                });

                // Any input change
                $('input, textarea').on('change input', function() {
                    updatePreview();
                });

                // Upload logo
                $('#upload_logo').on('click', function(e) {
                    e.preventDefault();
                    var frame = wp.media({
                        title: 'Select Logo',
                        multiple: false
                    });
                    frame.on('select', function() {
                        var url = frame.state().get('selection').first().toJSON().url;
                        $('#logo_url').val(url);
                        updatePreview();
                    });
                    frame.open();
                });

                // Upload background
                $('#upload_bg').on('click', function(e) {
                    e.preventDefault();
                    var frame = wp.media({
                        title: 'Select Background Image',
                        multiple: false
                    });
                    frame.on('select', function() {
                        var url = frame.state().get('selection').first().toJSON().url;
                        $('#bg_image').val(url);
                        updatePreview();
                    });
                    frame.open();
                });

                // Remove background
                $('#remove_bg').on('click', function(e) {
                    e.preventDefault();
                    $('#bg_image').val('');
                    updatePreview();
                });

                // Build preview HTML
                function getPreviewHtml() {
                    var logoUrl = $('#logo_url').val() || '<?php echo esc_js($wp_logo); ?>';
                    var bgColor = $('.wp-color-picker[name="bg_color"]').val() || '#f0f0f1';
                    var bgImage = $('#bg_image').val();
                    var formBg = $('.wp-color-picker[name="form_bg"]').val() || '#ffffff';
                    var formRadius = $('#form_radius').val() || 4;
                    var inputRadius = $('#input_radius').val() || 4;
                    var btnColor = $('.wp-color-picker[name="btn_color"]').val() || '#2271b1';
                    var btnText = $('.wp-color-picker[name="btn_text_color"]').val() || '#ffffff';
                    var linkColor = $('.wp-color-picker[name="link_color"]').val() || '#50575e';
                    var logoW = $('input[name="logo_width"]').val() || 84;
                    var logoH = $('input[name="logo_height"]').val() || 84;

                    var scale = 0.6;
                    var bgStyle = 'background-color:' + bgColor + ';';
                    if (bgImage) {
                        bgStyle += 'background-image:url(' + bgImage + ');background-size:cover;background-position:center;';
                    }

                    return '<div style="' + bgStyle + 'padding:30px;min-height:350px;display:flex;align-items:center;justify-content:center;">' +
                        '<div style="text-align:center;">' +
                        '<img src="' + logoUrl + '" style="width:' + (logoW * scale) + 'px;height:' + (logoH * scale) + 'px;object-fit:contain;margin-bottom:20px;">' +
                        '<div style="background:' + formBg + ';padding:' + (20 * scale) + 'px ' + (30 * scale) + 'px;border-radius:' + formRadius + 'px;box-shadow:0 4px 20px rgba(0,0,0,0.1);width:' + (280 * scale) + 'px;">' +
                        '<div style="margin-bottom:' + (15 * scale) + 'px;">' +
                        '<label style="display:block;text-align:left;margin-bottom:5px;font-size:' + (12 * scale) + 'px;">Username</label>' +
                        '<input type="text" style="width:100%;padding:' + (8 * scale) + 'px;border:1px solid #ddd;border-radius:' + inputRadius + 'px;font-size:' + (14 * scale) + 'px;" value="admin">' +
                        '</div>' +
                        '<div style="margin-bottom:' + (15 * scale) + 'px;">' +
                        '<label style="display:block;text-align:left;margin-bottom:5px;font-size:' + (12 * scale) + 'px;">Password</label>' +
                        '<input type="password" style="width:100%;padding:' + (8 * scale) + 'px;border:1px solid #ddd;border-radius:' + inputRadius + 'px;font-size:' + (14 * scale) + 'px;" value="password">' +
                        '</div>' +
                        '<button style="width:100%;padding:' + (10 * scale) + 'px;background:' + btnColor + ';color:' + btnText + ';border:none;border-radius:' + inputRadius + 'px;font-size:' + (14 * scale) + 'px;cursor:pointer;">Log In</button>' +
                        '</div>' +
                        '<div style="margin-top:15px;font-size:11px;">' +
                        '<a href="#" style="color:' + linkColor + ';text-decoration:none;">Register</a>' +
                        '<span style="color:' + linkColor + ';"> | </span>' +
                        '<a href="#" style="color:' + linkColor + ';text-decoration:none;">Lost your password?</a>' +
                        '</div>' +
                        '</div>' +
                        '</div>';
                }

                function updatePreview() {
                    $('#login-preview').html(getPreviewHtml());
                }

                // Initial preview
                updatePreview();
            });
        </script>
<?php
    }
}
