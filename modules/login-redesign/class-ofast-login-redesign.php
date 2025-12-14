<?php

/**
 * Ofast X - Login Redesign Module
 * Simple customizer for WordPress login page with template options
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

        // For two-column template, we need to inject HTML
        add_action('login_footer', array($this, 'inject_two_column_html'));
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
            'template' => get_option('ofast_login_template', 'simple'),
            // Common settings
            'logo_url' => get_option('ofast_login_logo_url', ''),
            'logo_width' => get_option('ofast_login_logo_width', '84'),
            'logo_height' => get_option('ofast_login_logo_height', '84'),
            'bg_color' => get_option('ofast_login_bg_color', '#f0f0f1'),
            'bg_image' => get_option('ofast_login_bg_image', ''),
            'form_bg' => get_option('ofast_login_form_bg', '#ffffff'),
            'form_bg_end' => get_option('ofast_login_form_bg_end', '#ffffff'),
            'form_use_gradient' => get_option('ofast_login_form_use_gradient', false),
            'form_radius' => get_option('ofast_login_form_radius', '4'),
            'btn_color' => get_option('ofast_login_btn_color', '#2271b1'),
            'btn_hover' => get_option('ofast_login_btn_hover', '#135e96'),
            'btn_text_color' => get_option('ofast_login_btn_text_color', '#ffffff'),
            'link_color' => get_option('ofast_login_link_color', '#50575e'),
            'link_hover' => get_option('ofast_login_link_hover', '#2271b1'),
            'input_radius' => get_option('ofast_login_input_radius', '4'),
            'input_border_color' => get_option('ofast_login_input_border_color', '#8c8f94'),
            'input_border_width' => get_option('ofast_login_input_border_width', '1'),
            'btn_border_color' => get_option('ofast_login_btn_border_color', '#2271b1'),
            'btn_border_width' => get_option('ofast_login_btn_border_width', '1'),
            'hide_back_link' => get_option('ofast_login_hide_back_link', false),
            'custom_css' => get_option('ofast_login_custom_css', ''),
            // Two-column specific
            'tc_side_image' => get_option('ofast_login_tc_side_image', ''),
            'tc_use_color' => get_option('ofast_login_tc_use_color', false),
            'tc_side_color' => get_option('ofast_login_tc_side_color', '#667eea'),
            'tc_side_color_end' => get_option('ofast_login_tc_side_color_end', '#764ba2'),
            'tc_image_position' => get_option('ofast_login_tc_image_position', 'left'),
            'tc_overlay_color' => get_option('ofast_login_tc_overlay_color', '#000000'),
            'tc_overlay_opacity' => get_option('ofast_login_tc_overlay_opacity', '40'),
            'tc_heading' => get_option('ofast_login_tc_heading', 'Welcome Back'),
            'tc_subheading' => get_option('ofast_login_tc_subheading', ''),
            'tc_text_color' => get_option('ofast_login_tc_text_color', '#ffffff'),
            'tc_form_border_color' => get_option('ofast_login_tc_form_border_color', '#e0e0e0'),
            'tc_form_border_width' => get_option('ofast_login_tc_form_border_width', '0'),
            'tc_centered' => get_option('ofast_login_tc_centered', false),
            'tc_bg_color' => get_option('ofast_login_tc_bg_color', '#f0f0f1'),
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
            update_option('ofast_login_template', 'simple');
            update_option('ofast_login_logo_url', '');
            update_option('ofast_login_logo_width', '84');
            update_option('ofast_login_logo_height', '84');
            update_option('ofast_login_bg_color', '#f0f0f1');
            update_option('ofast_login_bg_image', '');
            update_option('ofast_login_form_bg', '#ffffff');
            update_option('ofast_login_form_bg_end', '#ffffff');
            update_option('ofast_login_form_use_gradient', false);
            update_option('ofast_login_form_radius', '4');
            update_option('ofast_login_btn_color', '#2271b1');
            update_option('ofast_login_btn_hover', '#135e96');
            update_option('ofast_login_btn_text_color', '#ffffff');
            update_option('ofast_login_link_color', '#50575e');
            update_option('ofast_login_link_hover', '#2271b1');
            update_option('ofast_login_input_radius', '4');
            update_option('ofast_login_input_border_color', '#8c8f94');
            update_option('ofast_login_input_border_width', '1');
            update_option('ofast_login_btn_border_color', '#2271b1');
            update_option('ofast_login_btn_border_width', '1');
            update_option('ofast_login_hide_back_link', false);
            update_option('ofast_login_custom_css', '');
            // Two-column
            update_option('ofast_login_tc_side_image', '');
            update_option('ofast_login_tc_use_color', false);
            update_option('ofast_login_tc_side_color', '#667eea');
            update_option('ofast_login_tc_side_color_end', '#764ba2');
            update_option('ofast_login_tc_image_position', 'left');
            update_option('ofast_login_tc_overlay_color', '#000000');
            update_option('ofast_login_tc_overlay_opacity', '40');
            update_option('ofast_login_tc_heading', 'Welcome Back');
            update_option('ofast_login_tc_subheading', '');
            update_option('ofast_login_tc_text_color', '#ffffff');
            update_option('ofast_login_tc_form_border_color', '#e0e0e0');
            update_option('ofast_login_tc_form_border_width', '0');
            update_option('ofast_login_tc_centered', false);
            update_option('ofast_login_tc_bg_color', '#f0f0f1');

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

        // Common settings
        update_option('ofast_login_redesign_enabled', isset($_POST['enabled']));
        update_option('ofast_login_template', sanitize_text_field($_POST['template'] ?? 'simple'));
        update_option('ofast_login_logo_url', esc_url_raw($_POST['logo_url'] ?? ''));
        update_option('ofast_login_logo_width', absint($_POST['logo_width'] ?? 84));
        update_option('ofast_login_logo_height', absint($_POST['logo_height'] ?? 84));
        update_option('ofast_login_bg_color', sanitize_hex_color($_POST['bg_color'] ?? '#f0f0f1'));
        update_option('ofast_login_bg_image', esc_url_raw($_POST['bg_image'] ?? ''));
        update_option('ofast_login_form_bg', sanitize_hex_color($_POST['form_bg'] ?? '#ffffff'));
        update_option('ofast_login_form_bg_end', sanitize_hex_color($_POST['form_bg_end'] ?? '#ffffff'));
        update_option('ofast_login_form_use_gradient', isset($_POST['form_use_gradient']));
        update_option('ofast_login_form_radius', absint($_POST['form_radius'] ?? 4));
        update_option('ofast_login_btn_color', sanitize_hex_color($_POST['btn_color'] ?? '#2271b1'));
        update_option('ofast_login_btn_hover', sanitize_hex_color($_POST['btn_hover'] ?? '#135e96'));
        update_option('ofast_login_btn_text_color', sanitize_hex_color($_POST['btn_text_color'] ?? '#ffffff'));
        update_option('ofast_login_link_color', sanitize_hex_color($_POST['link_color'] ?? '#50575e'));
        update_option('ofast_login_link_hover', sanitize_hex_color($_POST['link_hover'] ?? '#2271b1'));
        update_option('ofast_login_input_radius', absint($_POST['input_radius'] ?? 4));
        update_option('ofast_login_input_border_color', sanitize_hex_color($_POST['input_border_color'] ?? '#8c8f94'));
        update_option('ofast_login_input_border_width', absint($_POST['input_border_width'] ?? 1));
        update_option('ofast_login_btn_border_color', sanitize_hex_color($_POST['btn_border_color'] ?? '#2271b1'));
        update_option('ofast_login_btn_border_width', absint($_POST['btn_border_width'] ?? 1));
        update_option('ofast_login_hide_back_link', isset($_POST['hide_back_link']));
        update_option('ofast_login_custom_css', wp_strip_all_tags($_POST['custom_css'] ?? ''));

        // Two-column settings
        update_option('ofast_login_tc_side_image', esc_url_raw($_POST['tc_side_image'] ?? ''));
        update_option('ofast_login_tc_use_color', isset($_POST['tc_use_color']));
        update_option('ofast_login_tc_side_color', sanitize_hex_color($_POST['tc_side_color'] ?? '#667eea'));
        update_option('ofast_login_tc_side_color_end', sanitize_hex_color($_POST['tc_side_color_end'] ?? '#764ba2'));
        update_option('ofast_login_tc_image_position', sanitize_text_field($_POST['tc_image_position'] ?? 'left'));
        update_option('ofast_login_tc_overlay_color', sanitize_hex_color($_POST['tc_overlay_color'] ?? '#000000'));
        update_option('ofast_login_tc_overlay_opacity', absint($_POST['tc_overlay_opacity'] ?? 40));
        update_option('ofast_login_tc_heading', sanitize_text_field($_POST['tc_heading'] ?? ''));
        update_option('ofast_login_tc_subheading', sanitize_text_field($_POST['tc_subheading'] ?? ''));
        update_option('ofast_login_tc_text_color', sanitize_hex_color($_POST['tc_text_color'] ?? '#ffffff'));
        update_option('ofast_login_tc_form_border_color', sanitize_hex_color($_POST['tc_form_border_color'] ?? '#e0e0e0'));
        update_option('ofast_login_tc_form_border_width', absint($_POST['tc_form_border_width'] ?? 0));
        update_option('ofast_login_tc_centered', isset($_POST['tc_centered']));
        update_option('ofast_login_tc_bg_color', sanitize_hex_color($_POST['tc_bg_color'] ?? '#f0f0f1'));

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
        $template = $s['template'];

        $css = '/* Ofast X Login Redesign */';

        if ($template === 'two-column') {
            // Two-column template CSS
            $css .= $this->get_two_column_css($s);
        } else {
            // Simple template CSS
            $css .= $this->get_simple_css($s);
        }

        // Common CSS for both templates
        $css .= $this->get_common_css($s);

        echo '<style type="text/css">' . $css . '</style>';
    }

    /**
     * Get simple template CSS
     */
    private function get_simple_css($s)
    {
        $css = '';

        // Background
        $css .= 'body.login { background-color: ' . esc_attr($s['bg_color']) . ';';
        if (!empty($s['bg_image'])) {
            $css .= 'background-image: url(' . esc_url($s['bg_image']) . ');';
            $css .= 'background-size: cover; background-position: center; background-repeat: no-repeat;';
        }
        $css .= '}';

        // Logo
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

        return $css;
    }

    /**
     * Get two-column template CSS
     */
    private function get_two_column_css($s)
    {
        $css = '';
        $imgPos = $s['tc_image_position'];
        $overlayOpacity = intval($s['tc_overlay_opacity']) / 100;
        $isCentered = $s['tc_centered'];
        $borderWidth = intval($s['tc_form_border_width']);
        $borderColor = esc_attr($s['tc_form_border_color']);
        $formUseGradient = $s['form_use_gradient'];

        // Build form background style
        if ($formUseGradient) {
            $formBgStyle = 'linear-gradient(135deg, ' . esc_attr($s['form_bg']) . ' 0%, ' . esc_attr($s['form_bg_end']) . ' 100%)';
        } else {
            $formBgStyle = esc_attr($s['form_bg']);
        }

        // Body background
        if ($isCentered) {
            $css .= 'body.login { background: ' . esc_attr($s['tc_bg_color']) . ' !important; overflow: auto; }';
        } else {
            $css .= 'body.login { background: #fff !important; overflow: hidden; }';
        }

        // Centered container wrapper
        if ($isCentered) {
            // The wrapper is a flex container with stretch (default)
            $css .= '.ofast-tc-wrapper { ';
            $css .= 'display: flex !important;';
            $css .= 'flex-direction: row !important;';
            $css .= 'width: 90% !important;';
            $css .= 'max-width: 1000px !important;';
            $css .= 'margin: 50px auto !important;';
            $css .= 'min-height: calc(100vh - 100px) !important;';
            $css .= 'border-radius: ' . esc_attr($s['form_radius']) . 'px !important;';
            $css .= 'overflow: hidden !important;';
            $css .= 'box-shadow: 0 10px 40px rgba(0,0,0,0.15) !important;';
            $css .= '}';

            // Image side in centered mode
            $css .= '.ofast-tc-wrapper .ofast-login-side { ';
            $css .= 'flex: 0 0 50% !important;';
            $css .= 'min-height: 100% !important;';
            $css .= '}';

            // Login container in centered mode - must be flex child that stretches
            $css .= '.ofast-tc-wrapper #login { ';
            $css .= 'flex: 0 0 50% !important;';
            $css .= 'position: relative !important;';
            $css .= 'top: auto !important;';
            $css .= 'left: auto !important;';
            $css .= 'right: auto !important;';
            $css .= 'width: auto !important;';
            $css .= 'height: auto !important;';
            $css .= 'margin: 0 !important;';
            $css .= 'display: flex !important;';
            $css .= 'flex-direction: column !important;';
            $css .= 'justify-content: center !important;';
            $css .= 'align-items: center !important;';
            $css .= 'padding: 40px !important;';
            $css .= 'box-sizing: border-box !important;';
            $css .= 'background: ' . $formBgStyle . ' !important;';
            $css .= '}';
        } else {
            // Main container - full screen
            $css .= '#login { ';
            $css .= 'position: fixed !important;';
            $css .= 'width: 50% !important;';
            $css .= 'height: 100% !important;';
            $css .= 'display: flex !important;';
            $css .= 'flex-direction: column !important;';
            $css .= 'justify-content: center !important;';
            $css .= 'align-items: center !important;';
            $css .= 'padding: 40px !important;';
            $css .= 'box-sizing: border-box !important;';
            $css .= 'background: ' . $formBgStyle . ' !important;';
            if ($imgPos === 'left') {
                $css .= 'right: 0 !important; left: auto !important;';
            } else {
                $css .= 'left: 0 !important; right: auto !important;';
            }
            $css .= '}';
        }

        // Logo in two-column
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

        // Form styling for two-column
        $css .= '#loginform, #registerform, #lostpasswordform {';
        $css .= 'background: transparent !important;';
        $css .= 'box-shadow: none !important;';
        $css .= 'border: none !important;';
        $css .= 'padding: 0 !important;';
        $css .= 'margin: 0 !important;';
        $css .= 'width: 100% !important;';
        $css .= 'max-width: 320px !important;';
        $css .= '}';

        // Responsive
        $css .= '@media (max-width: 768px) {';
        $css .= '.ofast-login-side, .ofast-tc-wrapper .ofast-login-side { display: none !important; }';
        if ($isCentered) {
            $css .= '.ofast-tc-wrapper { flex-direction: column !important; width: 95% !important; }';
            $css .= '#login { width: 100% !important; min-height: 400px !important; }';
        } else {
            $css .= '#login { width: 100% !important; left: 0 !important; right: 0 !important; }';
        }
        $css .= '}';

        return $css;
    }


    /**
     * Get common CSS for all templates
     */
    private function get_common_css($s)
    {
        $css = '';

        // Form Box (simple template only)
        if ($s['template'] === 'simple') {
            $css .= '#loginform, #registerform, #lostpasswordform {';
            $css .= 'background: ' . esc_attr($s['form_bg']) . ';';
            $css .= 'border-radius: ' . esc_attr($s['form_radius']) . 'px;';
            $css .= 'box-shadow: 0 4px 20px rgba(0,0,0,0.1);';
            $css .= 'border: none;';
            $css .= '}';
        }

        // Inputs
        $css .= '#loginform input[type="text"], #loginform input[type="password"],';
        $css .= '#registerform input[type="text"], #registerform input[type="email"] {';
        $css .= 'border-radius: ' . esc_attr($s['input_radius']) . 'px;';
        $css .= 'border: ' . esc_attr($s['input_border_width']) . 'px solid ' . esc_attr($s['input_border_color']) . ' !important;';
        $css .= '}';

        // Button
        $css .= '.wp-core-ui .button-primary {';
        $css .= 'background: ' . esc_attr($s['btn_color']) . ' !important;';
        $css .= 'border: ' . esc_attr($s['btn_border_width']) . 'px solid ' . esc_attr($s['btn_border_color']) . ' !important;';
        $css .= 'color: ' . esc_attr($s['btn_text_color']) . ' !important;';
        $css .= 'border-radius: ' . esc_attr($s['input_radius']) . 'px;';
        $css .= '}';

        $css .= '.wp-core-ui .button-primary:hover, .wp-core-ui .button-primary:focus {';
        $css .= 'background: ' . esc_attr($s['btn_hover']) . ' !important;';
        $css .= 'border-color: ' . esc_attr($s['btn_border_color']) . ' !important;';
        $css .= '}';

        // Links
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

        return $css;
    }

    /**
     * Inject HTML for two-column template
     */
    public function inject_two_column_html()
    {
        if (!$this->is_enabled()) {
            return;
        }

        $s = $this->get_settings();

        if ($s['template'] !== 'two-column') {
            return;
        }

        $imgPos = $s['tc_image_position'];
        $sideImage = esc_url($s['tc_side_image'] ?: $s['bg_image']);
        $useColor = $s['tc_use_color'] || empty($sideImage);
        $sideColor = esc_attr($s['tc_side_color']);
        $sideColorEnd = esc_attr($s['tc_side_color_end']);
        $overlayColor = esc_attr($s['tc_overlay_color']);
        $overlayOpacity = intval($s['tc_overlay_opacity']) / 100;
        $heading = esc_html($s['tc_heading']);
        $subheading = esc_html($s['tc_subheading'] ?: get_bloginfo('name'));
        $textColor = esc_attr($s['tc_text_color']);
        $isCentered = $s['tc_centered'];

        // Build background style
        if ($useColor) {
            $bgStyle = 'background: linear-gradient(135deg, ' . $sideColor . ' 0%, ' . $sideColorEnd . ' 100%);';
        } else {
            $bgStyle = 'background-image: url(' . $sideImage . '); background-size: cover; background-position: center;';
        }

        if ($isCentered) {
            // For centered mode, we need to wrap everything in a container
            echo '<div class="ofast-tc-wrapper" id="ofast-tc-wrapper">';

            // Image side (order depends on position)
            if ($imgPos === 'left') {
                $this->render_image_side($bgStyle, $overlayColor, $overlayOpacity, $heading, $subheading, $textColor, $useColor);
            }
            echo '</div>';

            // Script to move login form into wrapper
            echo '<script>';
            echo 'document.addEventListener("DOMContentLoaded", function() {';
            echo '  var wrapper = document.getElementById("ofast-tc-wrapper");';
            echo '  var login = document.getElementById("login");';
            echo '  if (wrapper && login) {';
            if ($imgPos === 'left') {
                echo '    wrapper.appendChild(login);';
            } else {
                echo '    wrapper.insertBefore(login, wrapper.firstChild);';
                // Add image side after login
                echo '    var imageSide = document.querySelector(".ofast-login-side-placeholder");';
                echo '    if (imageSide) wrapper.appendChild(imageSide);';
            }
            echo '  }';
            echo '});';
            echo '</script>';

            // If image is on right, render it as placeholder to be moved
            if ($imgPos === 'right') {
                echo '<div class="ofast-login-side-placeholder" style="display:none;">';
                $this->render_image_side($bgStyle, $overlayColor, $overlayOpacity, $heading, $subheading, $textColor, $useColor);
                echo '</div>';
            }
        } else {
            // Original full-screen mode
            $position = ($imgPos === 'left') ? 'left: 0;' : 'right: 0;';

            echo '<div class="ofast-login-side" style="';
            echo 'position: fixed; top: 0; ' . $position . ' width: 50%; height: 100%;';
            echo $bgStyle;
            echo 'display: flex; align-items: center; justify-content: center; z-index: 1;">';
            if (!$useColor) {
                echo '<div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0;';
                echo 'background: ' . $overlayColor . '; opacity: ' . $overlayOpacity . ';"></div>';
            }
            echo '<div style="position: relative; z-index: 2; text-align: center; padding: 40px; color: ' . $textColor . ';">';
            if ($heading) {
                echo '<h2 style="font-size: 36px; font-weight: 600; margin: 0 0 15px 0; color: ' . $textColor . ';">' . $heading . '</h2>';
            }
            if ($subheading) {
                echo '<p style="font-size: 18px; margin: 0; opacity: 0.9; color: ' . $textColor . ';">' . $subheading . '</p>';
            }
            echo '</div></div>';
        }

        echo '<style>@media (max-width: 768px) { .ofast-login-side { display: none !important; } }</style>';
    }

    /**
     * Render the image/gradient side panel for centered mode
     */
    private function render_image_side($bgStyle, $overlayColor, $overlayOpacity, $heading, $subheading, $textColor, $useColor = false)
    {
        echo '<div class="ofast-login-side" style="';
        echo 'width: 50%; min-height: 100%; position: relative;';
        echo $bgStyle;
        echo 'display: flex; align-items: center; justify-content: center;">';
        if (!$useColor) {
            echo '<div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0;';
            echo 'background: ' . $overlayColor . '; opacity: ' . $overlayOpacity . ';"></div>';
        }
        echo '<div style="position: relative; z-index: 2; text-align: center; padding: 40px; color: ' . $textColor . ';">';
        if ($heading) {
            echo '<h2 style="font-size: 36px; font-weight: 600; margin: 0 0 15px 0; color: ' . $textColor . ';">' . $heading . '</h2>';
        }
        if ($subheading) {
            echo '<p style="font-size: 18px; margin: 0; opacity: 0.9; color: ' . $textColor . ';">' . $subheading . '</p>';
        }
        echo '</div></div>';
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
        $current_template = $s['template'];
?>
        <div class="wrap">
            <h1>Login Page Redesign</h1>

            <?php settings_errors('ofast_login_redesign'); ?>

            <form method="post">
                <?php wp_nonce_field('ofast_login_redesign_settings', 'ofast_login_nonce'); ?>

                <!-- Template Selector Tabs -->
                <div style="margin: 20px 0;">
                    <h2 style="margin-bottom: 15px;">Choose Template</h2>
                    <div class="ofast-flex-wrap" style="gap: 20px;">
                        <!-- Simple Template -->
                        <label style="
                            display: block;
                            cursor: pointer;
                            border: 3px solid <?php echo $current_template === 'simple' ? '#2271b1' : '#ddd'; ?>;
                            border-radius: 8px;
                            padding: 15px;
                            text-align: center;
                            background: <?php echo $current_template === 'simple' ? '#f0f7fc' : '#fff'; ?>;
                            width: 200px;
                        ">
                            <input type="radio" name="template" value="simple" <?php checked($current_template, 'simple'); ?> style="display:none;">
                            <div style="
                                width: 100%;
                                height: 120px;
                                background: #f0f0f1;
                                border-radius: 4px;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                margin-bottom: 10px;
                            ">
                                <div style="
                                    width: 60px;
                                    height: 70px;
                                    background: #fff;
                                    border-radius: 4px;
                                    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                                "></div>
                            </div>
                            <strong>Simple</strong>
                            <p style="margin: 5px 0 0; font-size: 12px; color: #666;">Centered form with background</p>
                        </label>

                        <!-- Two-Column Template -->
                        <label style="
                            display: block;
                            cursor: pointer;
                            border: 3px solid <?php echo $current_template === 'two-column' ? '#2271b1' : '#ddd'; ?>;
                            border-radius: 8px;
                            padding: 15px;
                            text-align: center;
                            background: <?php echo $current_template === 'two-column' ? '#f0f7fc' : '#fff'; ?>;
                            width: 200px;
                        ">
                            <input type="radio" name="template" value="two-column" <?php checked($current_template, 'two-column'); ?> style="display:none;">
                            <div style="
                                width: 100%;
                                height: 120px;
                                background: #f0f0f1;
                                border-radius: 4px;
                                display: flex;
                                overflow: hidden;
                                margin-bottom: 10px;
                            ">
                                <div style="
                                    width: 50%;
                                    height: 100%;
                                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                                "></div>
                                <div style="
                                    width: 50%;
                                    height: 100%;
                                    background: #fff;
                                    display: flex;
                                    align-items: center;
                                    justify-content: center;
                                ">
                                    <div style="width: 30px; height: 40px; background: #eee; border-radius: 2px;"></div>
                                </div>
                            </div>
                            <strong>Two-Column</strong>
                            <p style="margin: 5px 0 0; font-size: 12px; color: #666;">Split screen with image</p>
                        </label>
                    </div>
                </div>

                <div style="display:flex;gap:30px;margin-top:20px;align-items:flex-start;">
                    <!-- Settings Panel -->
                    <div style="flex:1;max-width:500px;">

                        <div class="postbox" style="padding:20px;">
                            <h3 style="margin-top:0;">General</h3>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th>Enable</th>
                                    <td>
                                        <label class="ofast-toggle">
                                            <input type="checkbox" name="enabled" value="1" <?php checked($s['enabled']); ?>>
                                            <span class="ofast-toggle-slider"></span>
                                        </label>
                                        <span style="margin-left:10px;vertical-align:middle;">Enable custom login design</span>
                                    </td>
                                </tr>
                            </table>
                            <style>
                                .ofast-toggle {
                                    position: relative;
                                    display: inline-block;
                                    width: 50px;
                                    height: 26px;
                                }

                                .ofast-toggle input {
                                    opacity: 0;
                                    width: 0;
                                    height: 0;
                                }

                                .ofast-toggle-slider {
                                    position: absolute;
                                    cursor: pointer;
                                    top: 0;
                                    left: 0;
                                    right: 0;
                                    bottom: 0;
                                    background-color: #ccc;
                                    transition: .3s;
                                    border-radius: 26px;
                                }

                                .ofast-toggle-slider:before {
                                    position: absolute;
                                    content: "";
                                    height: 20px;
                                    width: 20px;
                                    left: 3px;
                                    bottom: 3px;
                                    background-color: white;
                                    transition: .3s;
                                    border-radius: 50%;
                                }

                                .ofast-toggle input:checked+.ofast-toggle-slider {
                                    background-color: #2271b1;
                                }

                                .ofast-toggle input:checked+.ofast-toggle-slider:before {
                                    transform: translateX(24px);
                                }
                            </style>
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

                        <!-- Two-Column Specific Settings -->
                        <div id="two-column-settings" class="postbox" style="padding:20px;margin-top:15px;<?php echo $current_template !== 'two-column' ? 'display:none;' : ''; ?>">
                            <h3 style="margin-top:0;">Two-Column Settings</h3>

                            <h4 style="margin:0 0 10px 0;">Side Panel Background</h4>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th>Use Color Gradient</th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="tc_use_color" value="1" <?php checked($s['tc_use_color']); ?>>
                                            Use gradient colors instead of image
                                        </label>
                                        <p class="description">When enabled, the side panel shows a gradient. When disabled, it shows an image.</p>
                                    </td>
                                </tr>
                                <tr class="tc-color-option" style="<?php echo !$s['tc_use_color'] ? 'display:none;' : ''; ?>">
                                    <th>Gradient Start Color</th>
                                    <td><input type="text" name="tc_side_color" id="tc_side_color" value="<?php echo esc_attr($s['tc_side_color']); ?>" class="color-picker"></td>
                                </tr>
                                <tr class="tc-color-option" style="<?php echo !$s['tc_use_color'] ? 'display:none;' : ''; ?>">
                                    <th>Gradient End Color</th>
                                    <td><input type="text" name="tc_side_color_end" id="tc_side_color_end" value="<?php echo esc_attr($s['tc_side_color_end']); ?>" class="color-picker"></td>
                                </tr>
                                <tr class="tc-image-option" style="<?php echo $s['tc_use_color'] ? 'display:none;' : ''; ?>">
                                    <th>Side Image</th>
                                    <td>
                                        <input type="text" name="tc_side_image" id="tc_side_image" value="<?php echo esc_url($s['tc_side_image']); ?>" class="regular-text">
                                        <button type="button" class="button" id="upload_tc_image">Upload</button>
                                        <p class="description">Leave empty to use background image</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Image Position</th>
                                    <td>
                                        <label style="margin-right:20px;">
                                            <input type="radio" name="tc_image_position" value="left" <?php checked($s['tc_image_position'], 'left'); ?>>
                                            Image Left, Form Right
                                        </label>
                                        <label>
                                            <input type="radio" name="tc_image_position" value="right" <?php checked($s['tc_image_position'], 'right'); ?>>
                                            Image Right, Form Left
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Overlay Color</th>
                                    <td><input type="text" name="tc_overlay_color" id="tc_overlay_color" value="<?php echo esc_attr($s['tc_overlay_color']); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th>Overlay Opacity</th>
                                    <td>
                                        <input type="range" name="tc_overlay_opacity" id="tc_overlay_opacity" min="0" max="100" value="<?php echo esc_attr($s['tc_overlay_opacity']); ?>">
                                        <span id="tc_overlay_opacity_val"><?php echo esc_html($s['tc_overlay_opacity']); ?>%</span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Heading Text</th>
                                    <td>
                                        <input type="text" name="tc_heading" value="<?php echo esc_attr($s['tc_heading']); ?>" class="regular-text" placeholder="Welcome Back">
                                    </td>
                                </tr>
                                <tr>
                                    <th>Subheading Text</th>
                                    <td>
                                        <input type="text" name="tc_subheading" value="<?php echo esc_attr($s['tc_subheading']); ?>" class="regular-text" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                                        <p class="description">Leave empty to use site name</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Text Color</th>
                                    <td><input type="text" name="tc_text_color" id="tc_text_color" value="<?php echo esc_attr($s['tc_text_color']); ?>" class="color-picker"></td>
                                </tr>
                            </table>

                            <hr style="margin: 20px 0;">
                            <h4 style="margin-top:0;">Layout Mode</h4>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th>Centered Card</th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="tc_centered" value="1" <?php checked($s['tc_centered']); ?>>
                                            Center layout as a card (like Simple template)
                                        </label>
                                        <p class="description">When enabled, the two-column layout appears centered with background visible</p>
                                    </td>
                                </tr>
                                <tr class="tc-centered-option" style="<?php echo !$s['tc_centered'] ? 'display:none;' : ''; ?>">
                                    <th>Background Color</th>
                                    <td><input type="text" name="tc_bg_color" id="tc_bg_color" value="<?php echo esc_attr($s['tc_bg_color']); ?>" class="color-picker"></td>
                                </tr>
                            </table>
                        </div>

                        <!-- Simple Template Background (only show for simple) -->
                        <div id="simple-bg-settings" class="postbox" style="padding:20px;margin-top:15px;<?php echo $current_template !== 'simple' ? 'display:none;' : ''; ?>">
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
                                    <th>Use Gradient</th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="form_use_gradient" value="1" <?php checked($s['form_use_gradient']); ?>>
                                            Use gradient for form background
                                        </label>
                                    </td>
                                </tr>
                                <tr class="form-gradient-option" style="<?php echo !$s['form_use_gradient'] ? 'display:none;' : ''; ?>">
                                    <th>End Color</th>
                                    <td><input type="text" name="form_bg_end" id="form_bg_end" value="<?php echo esc_attr($s['form_bg_end']); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th>Form Border Radius</th>
                                    <td>
                                        <input type="range" name="form_radius" id="form_radius" min="0" max="30" value="<?php echo esc_attr($s['form_radius']); ?>">
                                        <span id="form_radius_val"><?php echo esc_html($s['form_radius']); ?>px</span>
                                    </td>
                                </tr>
                            </table>

                            <hr style="margin: 20px 0;">
                            <h4 style="margin-top:0;">Input Fields</h4>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th>Border Radius</th>
                                    <td>
                                        <input type="range" name="input_radius" id="input_radius" min="0" max="20" value="<?php echo esc_attr($s['input_radius']); ?>">
                                        <span id="input_radius_val"><?php echo esc_html($s['input_radius']); ?>px</span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Border Color</th>
                                    <td><input type="text" name="input_border_color" id="input_border_color" value="<?php echo esc_attr($s['input_border_color']); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th>Border Width</th>
                                    <td>
                                        <input type="range" name="input_border_width" id="input_border_width" min="0" max="5" value="<?php echo esc_attr($s['input_border_width']); ?>">
                                        <span id="input_border_width_val"><?php echo esc_html($s['input_border_width']); ?>px</span>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="postbox" style="padding:20px;margin-top:15px;">
                            <h3 style="margin-top:0;">Button Styling</h3>
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
                                <tr>
                                    <th>Border Color</th>
                                    <td><input type="text" name="btn_border_color" id="btn_border_color" value="<?php echo esc_attr($s['btn_border_color']); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th>Border Width</th>
                                    <td>
                                        <input type="range" name="btn_border_width" id="btn_border_width" min="0" max="5" value="<?php echo esc_attr($s['btn_border_width']); ?>">
                                        <span id="btn_border_width_val"><?php echo esc_html($s['btn_border_width']); ?>px</span>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="postbox" style="padding:20px;margin-top:15px;">
                            <h3 style="margin-top:0;">Link Colors</h3>
                            <p class="description" style="margin-top:0;">For "Register", "Lost Password" links</p>
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
            </form>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Initialize color pickers
                $('.color-picker').wpColorPicker({
                    change: function() {
                        setTimeout(updatePreview, 100);
                    }
                });

                // Template switching
                $('input[name="template"]').on('change', function() {
                    var template = $(this).val();
                    if (template === 'two-column') {
                        $('#two-column-settings').show();
                        $('#simple-bg-settings').hide();
                    } else {
                        $('#two-column-settings').hide();
                        $('#simple-bg-settings').show();
                    }
                    // Update tab styles
                    $('input[name="template"]').each(function() {
                        var isChecked = $(this).is(':checked');
                        $(this).closest('label').css({
                            'border-color': isChecked ? '#2271b1' : '#ddd',
                            'background': isChecked ? '#f0f7fc' : '#fff'
                        });
                    });
                    updatePreview();
                });

                // Sliders
                $('#form_radius, #input_radius, #tc_overlay_opacity, #tc_form_border_width, #input_border_width, #btn_border_width').on('input', function() {
                    var suffix = this.id === 'tc_overlay_opacity' ? '%' : 'px';
                    $('#' + this.id + '_val').text($(this).val() + suffix);
                    updatePreview();
                });

                // Centered checkbox toggle
                $('input[name="tc_centered"]').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('.tc-centered-option').show();
                    } else {
                        $('.tc-centered-option').hide();
                    }
                    updatePreview();
                });

                // Use color gradient checkbox toggle
                $('input[name="tc_use_color"]').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('.tc-color-option').show();
                        $('.tc-image-option').hide();
                    } else {
                        $('.tc-color-option').hide();
                        $('.tc-image-option').show();
                    }
                    updatePreview();
                });

                // Form gradient checkbox toggle
                $('input[name="form_use_gradient"]').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('.form-gradient-option').show();
                    } else {
                        $('.form-gradient-option').hide();
                    }
                    updatePreview();
                });

                // Any input change
                $('input, textarea').on('change input', function() {
                    updatePreview();
                });

                // Upload handlers
                function setupUpload(buttonId, inputId) {
                    $(buttonId).on('click', function(e) {
                        e.preventDefault();
                        var frame = wp.media({
                            title: 'Select Image',
                            multiple: false
                        });
                        frame.on('select', function() {
                            var url = frame.state().get('selection').first().toJSON().url;
                            $(inputId).val(url);
                            updatePreview();
                        });
                        frame.open();
                    });
                }

                setupUpload('#upload_logo', '#logo_url');
                setupUpload('#upload_bg', '#bg_image');
                setupUpload('#upload_tc_image', '#tc_side_image');

                // Build preview HTML
                function getPreviewHtml() {
                    var template = $('input[name="template"]:checked').val();
                    var logoUrl = $('#logo_url').val() || '<?php echo esc_js($wp_logo); ?>';
                    var formBg = $('.wp-color-picker[name="form_bg"]').val() || '#ffffff';
                    var formRadius = $('#form_radius').val() || 4;
                    var inputRadius = $('#input_radius').val() || 4;
                    var inputBorderColor = $('.wp-color-picker[name="input_border_color"]').val() || '#8c8f94';
                    var inputBorderWidth = $('#input_border_width').val() || 1;
                    var btnColor = $('.wp-color-picker[name="btn_color"]').val() || '#2271b1';
                    var btnText = $('.wp-color-picker[name="btn_text_color"]').val() || '#ffffff';
                    var btnBorderColor = $('.wp-color-picker[name="btn_border_color"]').val() || '#2271b1';
                    var btnBorderWidth = $('#btn_border_width').val() || 1;
                    var linkColor = $('.wp-color-picker[name="link_color"]').val() || '#50575e';
                    var logoW = $('input[name="logo_width"]').val() || 84;
                    var logoH = $('input[name="logo_height"]').val() || 84;

                    // Input style
                    var inputStyle = 'width:100%;padding:5px;border:' + inputBorderWidth + 'px solid ' + inputBorderColor + ';border-radius:' + inputRadius + 'px;font-size:10px;box-sizing:border-box;';
                    // Button style
                    var btnStyle = 'width:100%;padding:6px;background:' + btnColor + ';color:' + btnText + ';border:' + btnBorderWidth + 'px solid ' + btnBorderColor + ';border-radius:' + inputRadius + 'px;font-size:10px;cursor:pointer;';

                    if (template === 'two-column') {
                        // Two-column preview
                        var sideImage = $('#tc_side_image').val() || $('#bg_image').val() || '';
                        var useColor = $('input[name="tc_use_color"]').is(':checked') || !sideImage;
                        var sideColor = $('.wp-color-picker[name="tc_side_color"]').val() || '#667eea';
                        var sideColorEnd = $('.wp-color-picker[name="tc_side_color_end"]').val() || '#764ba2';
                        var imgPos = $('input[name="tc_image_position"]:checked').val() || 'left';
                        var overlayColor = $('.wp-color-picker[name="tc_overlay_color"]').val() || '#000000';
                        var overlayOpacity = ($('#tc_overlay_opacity').val() || 40) / 100;
                        var heading = $('input[name="tc_heading"]').val() || 'Welcome Back';
                        var subheading = $('input[name="tc_subheading"]').val() || '<?php echo esc_js(get_bloginfo('name')); ?>';
                        var textColor = $('.wp-color-picker[name="tc_text_color"]').val() || '#ffffff';
                        var isCentered = $('input[name="tc_centered"]').is(':checked');
                        var tcBgColor = $('.wp-color-picker[name="tc_bg_color"]').val() || '#f0f0f1';

                        var bgStyle = useColor ?
                            'background:linear-gradient(135deg, ' + sideColor + ' 0%, ' + sideColorEnd + ' 100%);' :
                            'background-image:url(' + sideImage + ');background-size:cover;background-position:center;';

                        var overlayDiv = useColor ? '' : '<div style="position:absolute;top:0;left:0;right:0;bottom:0;background:' + overlayColor + ';opacity:' + overlayOpacity + ';"></div>';

                        var imageSide = '<div style="width:50%;height:100%;' + bgStyle + 'position:relative;display:flex;align-items:center;justify-content:center;">' +
                            overlayDiv +
                            '<div style="position:relative;z-index:2;text-align:center;padding:20px;color:' + textColor + ';">' +
                            '<div style="font-size:16px;font-weight:600;margin-bottom:5px;">' + heading + '</div>' +
                            '<div style="font-size:11px;opacity:0.9;">' + subheading + '</div>' +
                            '</div>' +
                            '</div>';

                        // Form background - check for gradient
                        var formUseGradient = $('input[name="form_use_gradient"]').is(':checked');
                        var formBgEnd = $('.wp-color-picker[name="form_bg_end"]').val() || '#ffffff';
                        var formBgStyle = formUseGradient ?
                            'linear-gradient(135deg, ' + formBg + ' 0%, ' + formBgEnd + ' 100%)' :
                            formBg;

                        var formSide = '<div style="width:50%;height:100%;background:' + formBgStyle + ';display:flex;align-items:center;justify-content:center;">' +
                            '<div style="text-align:center;width:140px;">' +
                            '<img src="' + logoUrl + '" style="width:' + (logoW * 0.5) + 'px;height:' + (logoH * 0.5) + 'px;object-fit:contain;margin-bottom:10px;">' +
                            '<div style="margin-bottom:8px;"><input type="text" style="' + inputStyle + '" value="Username"></div>' +
                            '<div style="margin-bottom:8px;"><input type="password" style="' + inputStyle + '" value="pass"></div>' +
                            '<button style="' + btnStyle + '">Log In</button>' +
                            '<div style="margin-top:8px;font-size:8px;"><a href="#" style="color:' + linkColor + ';text-decoration:none;">Register</a> | <a href="#" style="color:' + linkColor + ';text-decoration:none;">Lost password?</a></div>' +
                            '</div>' +
                            '</div>';

                        var content = imgPos === 'left' ? imageSide + formSide : formSide + imageSide;

                        if (isCentered) {
                            // Centered card mode
                            return '<div style="background:' + tcBgColor + ';padding:20px;min-height:350px;display:flex;align-items:center;justify-content:center;">' +
                                '<div style="display:flex;width:90%;max-width:400px;height:300px;border-radius:' + formRadius + 'px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,0.15);">' +
                                content + '</div></div>';
                        } else {
                            // Full-screen mode
                            return '<div style="display:flex;height:350px;">' + content + '</div>';
                        }

                    } else {
                        // Simple preview
                        var bgColor = $('.wp-color-picker[name="bg_color"]').val() || '#f0f0f1';
                        var bgImage = $('#bg_image').val();
                        var scale = 0.6;
                        var bgStyle = 'background-color:' + bgColor + ';';
                        if (bgImage) {
                            bgStyle += 'background-image:url(' + bgImage + ');background-size:cover;background-position:center;';
                        }

                        // Scaled input/button styles
                        var sInputStyle = 'width:100%;padding:' + (8 * scale) + 'px;border:' + inputBorderWidth + 'px solid ' + inputBorderColor + ';border-radius:' + inputRadius + 'px;font-size:' + (14 * scale) + 'px;box-sizing:border-box;';
                        var sBtnStyle = 'width:100%;padding:' + (10 * scale) + 'px;background:' + btnColor + ';color:' + btnText + ';border:' + btnBorderWidth + 'px solid ' + btnBorderColor + ';border-radius:' + inputRadius + 'px;font-size:' + (14 * scale) + 'px;cursor:pointer;';

                        return '<div style="' + bgStyle + 'padding:30px;min-height:350px;display:flex;align-items:center;justify-content:center;">' +
                            '<div style="text-align:center;">' +
                            '<img src="' + logoUrl + '" style="width:' + (logoW * scale) + 'px;height:' + (logoH * scale) + 'px;object-fit:contain;margin-bottom:20px;">' +
                            '<div style="background:' + formBg + ';padding:' + (20 * scale) + 'px ' + (30 * scale) + 'px;border-radius:' + formRadius + 'px;box-shadow:0 4px 20px rgba(0,0,0,0.1);width:' + (280 * scale) + 'px;">' +
                            '<div style="margin-bottom:' + (15 * scale) + 'px;">' +
                            '<label style="display:block;text-align:left;margin-bottom:5px;font-size:' + (12 * scale) + 'px;">Username</label>' +
                            '<input type="text" style="' + sInputStyle + '" value="admin">' +
                            '</div>' +
                            '<div style="margin-bottom:' + (15 * scale) + 'px;">' +
                            '<label style="display:block;text-align:left;margin-bottom:5px;font-size:' + (12 * scale) + 'px;">Password</label>' +
                            '<input type="password" style="' + sInputStyle + '" value="password">' +
                            '</div>' +
                            '<button style="' + sBtnStyle + '">Log In</button>' +
                            '</div>' +
                            '<div style="margin-top:15px;font-size:11px;">' +
                            '<a href="#" style="color:' + linkColor + ';text-decoration:none;">Register</a>' +
                            '<span style="color:' + linkColor + ';"> | </span>' +
                            '<a href="#" style="color:' + linkColor + ';text-decoration:none;">Lost your password?</a>' +
                            '</div>' +
                            '</div>' +
                            '</div>';
                    }
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
