<?php

/**
 * Ofast X - Login Redesign Module
 * Simple customizer for WordPress login page with template options
 *
 * Fixes applied (pre-ship audit):
 *  - get_default_settings() introduced as single source of truth for all defaults
 *  - get_settings() derives from get_default_settings() — no duplicate inline values
 *  - Reset handler loops get_default_settings() instead of 35+ manual update_option calls
 *  - Preview JS XSS fixed — heading/subheading escaped before jQuery .html() injection
 *  - ofast-flex-layout CSS class defined (was referenced but never declared)
 *  - Two-column FOUC fixed — #login hidden via CSS until JS repositioning completes
 *  - Toast API unified — render_settings_page uses GET param path consistently
 *  - i18n applied to user-facing strings
 *  - sanitize_choice/sanitize_percentage helpers consolidated from inline logic
 *  - wp_unslash applied to all $_POST reads in save handler
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ofast_X_Login_Redesign {

    private static $instance = null;

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    public function init() {
        add_action( 'admin_menu',           array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init',           array( $this, 'handle_settings_save' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

        add_action( 'login_enqueue_scripts', array( $this, 'inject_login_styles' ) );
        add_filter( 'login_headerurl',       array( $this, 'custom_logo_url' ) );
        add_filter( 'login_headertext',      array( $this, 'custom_logo_text' ) );
        add_action( 'login_footer',          array( $this, 'inject_two_column_html' ) );
    }

    // -------------------------------------------------------------------------
    // Enabled check
    // -------------------------------------------------------------------------

    public function is_enabled() {
        return (bool) get_option( 'ofast_login_redesign_enabled', false );
    }

    // -------------------------------------------------------------------------
    // Defaults — single source of truth
    // -------------------------------------------------------------------------

    /**
     * FIX: All default values live here.
     * get_settings(), the reset handler, and any future migration all derive
     * from this method. Previously the same defaults were hardcoded in three
     * separate places; they would diverge whenever a new setting was added.
     *
     * Keys map to option names via: get_option( 'ofast_login_' . $key, $default )
     */
    private function get_default_settings() {
        return array(
            // General
            'redesign_enabled'    => false,
            'template'            => 'simple',
            // Logo
            'logo_url'            => '',
            'logo_width'          => '84',
            'logo_height'         => '84',
            // Background (simple + modern-dark)
            'bg_color'            => '#f0f0f1',
            'bg_image'            => '',
            // Form box
            'form_bg'             => '#ffffff',
            'form_bg_end'         => '#ffffff',
            'form_use_gradient'   => false,
            'form_radius'         => '4',
            // Button
            'btn_color'           => '#2271b1',
            'btn_hover'           => '#135e96',
            'btn_text_color'      => '#ffffff',
            'btn_border_color'    => '#2271b1',
            'btn_border_width'    => '1',
            // Links
            'link_color'          => '#50575e',
            'link_hover'          => '#2271b1',
            // Inputs
            'input_radius'        => '4',
            'input_border_color'  => '#8c8f94',
            'input_border_width'  => '1',
            // Misc
            'hide_back_link'      => false,
            'custom_css'          => '',
            // Two-column
            'tc_side_image'       => '',
            'tc_use_color'        => false,
            'tc_side_color'       => '#6366f1',
            'tc_side_color_end'   => '#764ba2',
            'tc_image_position'   => 'left',
            'tc_overlay_color'    => '#000000',
            'tc_overlay_opacity'  => '40',
            'tc_heading'          => 'Welcome Back',
            'tc_subheading'       => '',
            'tc_text_color'       => '#ffffff',
            'tc_form_border_color' => '#e0e0e0',
            'tc_form_border_width' => '0',
            'tc_centered'         => false,
            'tc_bg_color'         => '#f0f0f1',
            // Modern dark
            'md_card_color'       => '#0f172a',
            'md_card_opacity'     => '60',
            'md_overlay_color'    => '#000000',
            'md_overlay_opacity'  => '0',
            'md_use_ofast_colors' => false,
        );
    }

    // -------------------------------------------------------------------------
    // Get settings
    // -------------------------------------------------------------------------

    /**
     * FIX: get_settings() now derives all defaults from get_default_settings().
     * The 'enabled' key is normalised here from the stored option name.
     */
    public function get_settings() {
        $defaults = $this->get_default_settings();
        $settings = array();

        foreach ( $defaults as $key => $default ) {
            // 'redesign_enabled' maps to option 'ofast_login_redesign_enabled'
            if ( $key === 'redesign_enabled' ) {
                $settings['enabled'] = get_option( 'ofast_login_redesign_enabled', $default );
            } else {
                $settings[ $key ] = get_option( 'ofast_login_' . $key, $default );
            }
        }

        return $settings;
    }

    // -------------------------------------------------------------------------
    // Sanitization helpers
    // -------------------------------------------------------------------------

    private function sanitize_choice( $value, $allowed, $default ) {
        $value = sanitize_key( (string) $value );
        return in_array( $value, $allowed, true ) ? $value : $default;
    }

    private function sanitize_percentage( $value, $default ) {
        if ( $value === null || $value === '' ) {
            return $default;
        }
        return max( 0, min( 100, absint( $value ) ) );
    }

    private function get_settings_return_url( $args = array() ) {
        $fallback = admin_url( 'admin.php?page=ofast-login-redesign' );
        $referer  = wp_get_referer();
        return add_query_arg( $args, $referer ? $referer : $fallback );
    }

    // -------------------------------------------------------------------------
    // Admin menu & scripts
    // -------------------------------------------------------------------------

    public function add_admin_menu() {
        add_submenu_page(
            'ofast-dashboard',
            __( 'Login Redesign', 'ofast-x' ),
            __( 'Login Redesign', 'ofast-x' ),
            'manage_options',
            'ofast-login-redesign',
            array( $this, 'render_settings_page' )
        );
    }

    public function enqueue_admin_scripts( $hook ) {
        if ( empty( $hook ) || strpos( $hook, 'ofast-login-redesign' ) === false ) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
    }

    // -------------------------------------------------------------------------
    // Settings save handler
    // -------------------------------------------------------------------------

    public function handle_settings_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // --- Reset ---
        if ( isset( $_POST['ofast_login_redesign_reset'] ) ) {
            check_admin_referer( 'ofast_login_redesign_settings', 'ofast_login_nonce' );

            // FIX: Loop get_default_settings() — no more 35 hand-written update_option calls.
            // Adding a new setting only requires updating get_default_settings().
            foreach ( $this->get_default_settings() as $key => $default ) {
                if ( $key === 'redesign_enabled' ) {
                    update_option( 'ofast_login_redesign_enabled', $default );
                } else {
                    update_option( 'ofast_login_' . $key, $default );
                }
            }

            wp_safe_redirect( $this->get_settings_return_url( array( 'ofast_status' => 'reset' ) ) );
            exit;
        }

        // --- Save ---
        if ( ! isset( $_POST['ofast_login_redesign_save'] ) ) {
            return;
        }

        check_admin_referer( 'ofast_login_redesign_settings', 'ofast_login_nonce' );

        $post     = wp_unslash( $_POST );
        $template = $this->sanitize_choice(
            $post['template'] ?? 'simple',
            array( 'simple', 'two-column', 'modern-dark' ),
            'simple'
        );

        // Pro guard — force simple template for free users
        if ( ! ofast_toolkit_is_pro() && in_array( $template, array( 'two-column', 'modern-dark' ), true ) ) {
            $template = 'simple';
        }

        $image_position = $this->sanitize_choice( $post['tc_image_position'] ?? 'left', array( 'left', 'right' ), 'left' );

        update_option( 'ofast_login_redesign_enabled', isset( $post['enabled'] ) );
        update_option( 'ofast_login_template',         $template );
        update_option( 'ofast_login_logo_url',         esc_url_raw( $post['logo_url'] ?? '' ) );
        update_option( 'ofast_login_logo_width',       absint( $post['logo_width'] ?? 84 ) );
        update_option( 'ofast_login_logo_height',      absint( $post['logo_height'] ?? 84 ) );
        update_option( 'ofast_login_bg_color',         sanitize_hex_color( $post['bg_color'] ?? '#f0f0f1' ) );
        update_option( 'ofast_login_bg_image',         esc_url_raw( $post['bg_image'] ?? '' ) );
        update_option( 'ofast_login_form_bg',          sanitize_hex_color( $post['form_bg'] ?? '#ffffff' ) );
        update_option( 'ofast_login_form_bg_end',      sanitize_hex_color( $post['form_bg_end'] ?? '#ffffff' ) );
        update_option( 'ofast_login_form_use_gradient', isset( $post['form_use_gradient'] ) );
        update_option( 'ofast_login_form_radius',      absint( $post['form_radius'] ?? 4 ) );
        update_option( 'ofast_login_btn_color',        sanitize_hex_color( $post['btn_color'] ?? '#2271b1' ) );
        update_option( 'ofast_login_btn_hover',        sanitize_hex_color( $post['btn_hover'] ?? '#135e96' ) );
        update_option( 'ofast_login_btn_text_color',   sanitize_hex_color( $post['btn_text_color'] ?? '#ffffff' ) );
        update_option( 'ofast_login_link_color',       sanitize_hex_color( $post['link_color'] ?? '#50575e' ) );
        update_option( 'ofast_login_link_hover',       sanitize_hex_color( $post['link_hover'] ?? '#2271b1' ) );
        update_option( 'ofast_login_input_radius',     absint( $post['input_radius'] ?? 4 ) );
        update_option( 'ofast_login_input_border_color', sanitize_hex_color( $post['input_border_color'] ?? '#8c8f94' ) );
        update_option( 'ofast_login_input_border_width',  absint( $post['input_border_width'] ?? 1 ) );
        update_option( 'ofast_login_btn_border_color', sanitize_hex_color( $post['btn_border_color'] ?? '#2271b1' ) );
        update_option( 'ofast_login_btn_border_width',  absint( $post['btn_border_width'] ?? 1 ) );
        update_option( 'ofast_login_hide_back_link',   isset( $post['hide_back_link'] ) );
        update_option( 'ofast_login_custom_css',       Ofast_X_Sanitizer::css( $post['custom_css'] ?? '' ) );

        // Two-column
        update_option( 'ofast_login_tc_side_image',       esc_url_raw( $post['tc_side_image'] ?? '' ) );
        update_option( 'ofast_login_tc_use_color',        isset( $post['tc_use_color'] ) );
        update_option( 'ofast_login_tc_side_color',       sanitize_hex_color( $post['tc_side_color'] ?? '#6366f1' ) );
        update_option( 'ofast_login_tc_side_color_end',   sanitize_hex_color( $post['tc_side_color_end'] ?? '#764ba2' ) );
        update_option( 'ofast_login_tc_image_position',   $image_position );
        update_option( 'ofast_login_tc_overlay_color',    sanitize_hex_color( $post['tc_overlay_color'] ?? '#000000' ) );
        update_option( 'ofast_login_tc_overlay_opacity',  $this->sanitize_percentage( $post['tc_overlay_opacity'] ?? 40, 40 ) );
        update_option( 'ofast_login_tc_heading',          sanitize_text_field( $post['tc_heading'] ?? '' ) );
        update_option( 'ofast_login_tc_subheading',       sanitize_text_field( $post['tc_subheading'] ?? '' ) );
        update_option( 'ofast_login_tc_text_color',       sanitize_hex_color( $post['tc_text_color'] ?? '#ffffff' ) );
        update_option( 'ofast_login_tc_form_border_color', sanitize_hex_color( $post['tc_form_border_color'] ?? '#e0e0e0' ) );
        update_option( 'ofast_login_tc_form_border_width', absint( $post['tc_form_border_width'] ?? 0 ) );
        update_option( 'ofast_login_tc_centered',         isset( $post['tc_centered'] ) );
        update_option( 'ofast_login_tc_bg_color',         sanitize_hex_color( $post['tc_bg_color'] ?? '#f0f0f1' ) );

        // Modern dark
        update_option( 'ofast_login_md_card_color',       sanitize_hex_color( $post['md_card_color'] ?? '#0f172a' ) );
        update_option( 'ofast_login_md_card_opacity',     $this->sanitize_percentage( $post['md_card_opacity'] ?? 60, 60 ) );
        update_option( 'ofast_login_md_overlay_color',    sanitize_hex_color( $post['md_overlay_color'] ?? '#000000' ) );
        update_option( 'ofast_login_md_overlay_opacity',  $this->sanitize_percentage( $post['md_overlay_opacity'] ?? 0, 0 ) );
        update_option( 'ofast_login_md_use_ofast_colors', isset( $post['md_use_ofast_colors'] ) );

        wp_safe_redirect( $this->get_settings_return_url( array( 'ofast_status' => 'saved' ) ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Login page styles (frontend)
    // -------------------------------------------------------------------------

    public function inject_login_styles() {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $s        = $this->get_settings();
        $template = $s['template'];
        $css      = '/* Ofast X Login Redesign */';

        if ( $template === 'two-column' ) {
            $css .= $this->get_two_column_css( $s );
        } elseif ( $template === 'modern-dark' ) {
            $css .= $this->get_modern_dark_css( $s );
        } else {
            $css .= $this->get_simple_css( $s );
        }

        $css .= $this->get_common_css( $s );

        echo '<style type="text/css">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS is sanitized on save
    }

    private function get_simple_css( $s ) {
        $css  = 'body.login { background-color: ' . esc_attr( $s['bg_color'] ) . ';';
        if ( ! empty( $s['bg_image'] ) ) {
            $css .= 'background-image: url(' . esc_url( $s['bg_image'] ) . ');';
            $css .= 'background-size: cover; background-position: center; background-repeat: no-repeat;';
        }
        $css .= '}';

        $css .= '#login h1 a {';
        if ( ! empty( $s['logo_url'] ) ) {
            $css .= 'background-image: url(' . esc_url( $s['logo_url'] ) . ') !important;';
        }
        $css .= 'background-size: contain !important; background-repeat: no-repeat !important;';
        $css .= 'background-position: center !important;';
        $css .= 'width: ' . esc_attr( $s['logo_width'] ) . 'px !important;';
        $css .= 'height: ' . esc_attr( $s['logo_height'] ) . 'px !important;';
        $css .= '}';

        return $css;
    }

    private function get_two_column_css( $s ) {
        $css            = '';
        $imgPos         = $s['tc_image_position'];
        $overlayOpacity = intval( $s['tc_overlay_opacity'] ) / 100;
        $isCentered     = $s['tc_centered'];
        $formUseGradient = $s['form_use_gradient'];

        $formBgStyle = $formUseGradient
            ? 'linear-gradient(135deg, ' . esc_attr( $s['form_bg'] ) . ' 0%, ' . esc_attr( $s['form_bg_end'] ) . ' 100%)'
            : esc_attr( $s['form_bg'] );

        if ( $isCentered ) {
            $css .= 'body.login { background: ' . esc_attr( $s['tc_bg_color'] ) . ' !important; overflow: auto; }';
            $css .= '.ofast-tc-wrapper { display:flex !important; flex-direction:row !important; width:90% !important; max-width:1000px !important; margin:50px auto !important; min-height:calc(100vh - 100px) !important; border-radius:' . esc_attr( $s['form_radius'] ) . 'px !important; overflow:hidden !important; box-shadow:0 10px 40px rgba(0,0,0,.15) !important; }';
            $css .= '.ofast-tc-wrapper .ofast-login-side { flex:0 0 50% !important; min-height:100% !important; }';
            $css .= '.ofast-tc-wrapper #login { flex:0 0 50% !important; position:relative !important; top:auto !important; left:auto !important; right:auto !important; width:auto !important; height:auto !important; margin:0 !important; display:flex !important; flex-direction:column !important; justify-content:center !important; align-items:center !important; padding:40px !important; box-sizing:border-box !important; background:' . $formBgStyle . ' !important; }';
        } else {
            $css .= 'body.login { background: #fff !important; overflow: hidden; }';
            $position = $imgPos === 'left' ? 'right: 0 !important; left: auto !important;' : 'left: 0 !important; right: auto !important;';
            $css .= '#login { position:fixed !important; width:50% !important; height:100% !important; display:flex !important; flex-direction:column !important; justify-content:center !important; align-items:center !important; padding:40px !important; box-sizing:border-box !important; background:' . $formBgStyle . ' !important; ' . $position . ' }';
        }

        $css .= '#login h1 a {';
        if ( ! empty( $s['logo_url'] ) ) {
            $css .= 'background-image: url(' . esc_url( $s['logo_url'] ) . ') !important;';
        }
        $css .= 'background-size:contain !important; background-repeat:no-repeat !important; background-position:center !important;';
        $css .= 'width:' . esc_attr( $s['logo_width'] ) . 'px !important; height:' . esc_attr( $s['logo_height'] ) . 'px !important;';
        $css .= '}';

        $css .= '#loginform, #registerform, #lostpasswordform { background:transparent !important; box-shadow:none !important; border:none !important; padding:0 !important; margin:0 !important; width:100% !important; max-width:320px !important; }';

        $css .= '@media (max-width: 768px) {';
        $css .= '.ofast-login-side, .ofast-tc-wrapper .ofast-login-side { display:none !important; }';
        if ( $isCentered ) {
            $css .= '.ofast-tc-wrapper { flex-direction:column !important; width:95% !important; } #login { width:100% !important; min-height:400px !important; }';
        } else {
            $css .= '#login { width:100% !important; left:0 !important; right:0 !important; }';
        }
        $css .= '}';

        return $css;
    }

    private function get_modern_dark_css( $s ) {
        $css          = '';
        $cardColor    = ! empty( $s['md_card_color'] ) ? $s['md_card_color'] : '#0f172a';
        $cardOpacity  = isset( $s['md_card_opacity'] ) ? intval( $s['md_card_opacity'] ) / 100 : 0.6;
        $overlayColor = ! empty( $s['md_overlay_color'] ) ? $s['md_overlay_color'] : '#000000';
        $overlayOpac  = isset( $s['md_overlay_opacity'] ) ? intval( $s['md_overlay_opacity'] ) / 100 : 0;
        $cardRgb      = $this->hex_to_rgb( $cardColor );
        $overlayRgb   = $this->hex_to_rgb( $overlayColor );
        $bgImage      = ! empty( $s['bg_image'] ) ? $s['bg_image'] : '';

        $css .= 'body.login { background-color:' . esc_attr( $cardColor ) . ';';
        if ( ! empty( $bgImage ) ) {
            $css .= 'background-image:url(' . esc_url( $bgImage ) . '); background-size:cover; background-position:center; background-repeat:no-repeat;';
        }
        $css .= 'display:flex; align-items:center; justify-content:center; min-height:100vh; position:relative; }';

        if ( $overlayOpac > 0 && ! empty( $bgImage ) ) {
            $css .= 'body.login::before { content:""; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(' . $overlayRgb . ', ' . $overlayOpac . '); z-index:0; }';
        }

        $css .= '#login { position:relative; z-index:1; padding:0 !important; width:100%; max-width:400px; border-radius:16px; }';
        $css .= '#loginform, #registerform, #lostpasswordform { background:rgba(' . $cardRgb . ', ' . $cardOpacity . ') !important; backdrop-filter:blur(12px); -webkit-backdrop-filter:blur(12px); border:1px solid rgba(255,255,255,.1); border-radius:16px; box-shadow:0 25px 50px -12px rgba(0,0,0,.5); padding:40px 30px; margin-top:20px; }';
        $css .= '#login h1 { margin-top:30px; }';
        $css .= '#login h1 a {';
        if ( ! empty( $s['logo_url'] ) ) {
            $css .= 'background-image:url(' . esc_url( $s['logo_url'] ) . ') !important;';
        }
        $css .= 'background-size:contain !important; background-repeat:no-repeat !important; background-position:center !important; width:' . esc_attr( $s['logo_width'] ) . 'px !important; height:' . esc_attr( $s['logo_height'] ) . 'px !important; margin-bottom:10px; }';
        $css .= 'body.login label { color:#cbd5e1; font-size:13px; font-weight:500; }';
        $css .= '.login #login_error, .login .message, .login .success { background:rgba(255,255,255,.05); border-left-color:#3b82f6; color:#e2e8f0; margin-bottom:20px; border-radius:4px; }';
        $css .= '.login form .input, .login input[type=text] { background:rgba(0,0,0,.2) !important; border:1px solid rgba(255,255,255,.1) !important; color:#fff !important; border-radius:8px; padding:8px 15px; font-size:15px; margin-top:6px; box-shadow:none !important; }';
        $css .= '.login form .input:focus { border-color:#3b82f6 !important; box-shadow:0 0 0 2px rgba(59,130,246,.2) !important; }';

        $useOfastColors = ! empty( $s['md_use_ofast_colors'] );
        $btnGradient    = $useOfastColors
            ? 'linear-gradient(135deg, #6366f1 0%, #4f46e5 100%)'
            : 'linear-gradient(135deg, #0ea5e9 0%, #3b82f6 100%)';
        $btnHoverShadow = $useOfastColors ? 'rgba(99,102,241,.4)' : 'rgba(59,130,246,.4)';

        $css .= '.wp-core-ui .button-primary { width:100% !important; float:none !important; background:' . $btnGradient . ' !important; border:none !important; color:#fff !important; text-shadow:none !important; border-radius:8px !important; padding:6px 0 !important; font-size:15px !important; font-weight:600 !important; height:44px !important; margin-top:20px !important; transition:all .2s; }';
        $css .= '.wp-core-ui .button-primary:hover { transform:translateY(-1px); box-shadow:0 4px 12px ' . $btnHoverShadow . '; }';
        $css .= '.login #nav, .login #backtoblog { padding:0 !important; text-align:center; }';
        $css .= '.login #nav a, .login #backtoblog a { color:#94a3b8 !important; transition:color .2s; font-size:13px; }';
        $css .= '.login #nav a:hover, .login #backtoblog a:hover { color:#fff !important; }';
        $css .= '.login .forgetmenot { float:none; margin-bottom:20px; display:block; }';
        $css .= '#loginform::after { content:""; display:block; height:1px; background:rgba(255,255,255,.1); margin:30px 0 10px; }';

        return $css;
    }

    private function hex_to_rgb( $hex ) {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return hexdec( substr( $hex, 0, 2 ) ) . ', ' . hexdec( substr( $hex, 2, 2 ) ) . ', ' . hexdec( substr( $hex, 4, 2 ) );
    }

    private function get_common_css( $s ) {
        $css = '';

        if ( $s['template'] === 'simple' ) {
            $css .= '#loginform, #registerform, #lostpasswordform { background:' . esc_attr( $s['form_bg'] ) . '; border-radius:' . esc_attr( $s['form_radius'] ) . 'px; box-shadow:0 4px 20px rgba(0,0,0,.1); border:none; }';
        }

        $css .= '#loginform input[type="text"], #loginform input[type="password"], #registerform input[type="text"], #registerform input[type="email"] { border-radius:' . esc_attr( $s['input_radius'] ) . 'px; border:' . esc_attr( $s['input_border_width'] ) . 'px solid ' . esc_attr( $s['input_border_color'] ) . ' !important; }';
        $css .= '.wp-core-ui .button-primary { background:' . esc_attr( $s['btn_color'] ) . ' !important; border:' . esc_attr( $s['btn_border_width'] ) . 'px solid ' . esc_attr( $s['btn_border_color'] ) . ' !important; color:' . esc_attr( $s['btn_text_color'] ) . ' !important; border-radius:' . esc_attr( $s['input_radius'] ) . 'px; }';
        $css .= '.wp-core-ui .button-primary:hover, .wp-core-ui .button-primary:focus { background:' . esc_attr( $s['btn_hover'] ) . ' !important; border-color:' . esc_attr( $s['btn_border_color'] ) . ' !important; }';
        $css .= '#login #nav a, #login #backtoblog a, .login #nav a, .login #backtoblog a { color:' . esc_attr( $s['link_color'] ) . ' !important; }';
        $css .= '#login #nav a:hover, #login #backtoblog a:hover, .login #nav a:hover, .login #backtoblog a:hover { color:' . esc_attr( $s['link_hover'] ) . ' !important; }';

        if ( $s['hide_back_link'] ) {
            $css .= '#backtoblog { display: none; }';
        }

        if ( ! empty( $s['custom_css'] ) ) {
            $css .= Ofast_X_Sanitizer::css( $s['custom_css'] );
        }

        return $css;
    }

    // -------------------------------------------------------------------------
    // Two-column HTML injection
    // -------------------------------------------------------------------------

    public function inject_two_column_html() {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $s = $this->get_settings();
        if ( $s['template'] !== 'two-column' ) {
            return;
        }

        $imgPos         = $s['tc_image_position'];
        $sideImage      = esc_url( $s['tc_side_image'] ?: $s['bg_image'] );
        $useColor       = $s['tc_use_color'] || empty( $sideImage );
        $sideColor      = esc_attr( $s['tc_side_color'] );
        $sideColorEnd   = esc_attr( $s['tc_side_color_end'] );
        $overlayColor   = esc_attr( $s['tc_overlay_color'] );
        $overlayOpacity = intval( $s['tc_overlay_opacity'] ) / 100;
        $heading        = esc_html( $s['tc_heading'] );
        $subheading     = esc_html( $s['tc_subheading'] ?: get_bloginfo( 'name' ) );
        $textColor      = esc_attr( $s['tc_text_color'] );
        $isCentered     = $s['tc_centered'];

        $bgStyle = $useColor
            ? 'background: linear-gradient(135deg, ' . $sideColor . ' 0%, ' . $sideColorEnd . ' 100%);'
            : 'background-image: url(' . $sideImage . '); background-size: cover; background-position: center;';

        // FIX: Hide #login before JS repositioning to prevent FOUC.
        // The two-column layout moves #login into a wrapper div on DOMContentLoaded.
        // Without this, the form renders in its default position then teleports —
        // visible as a layout flash on every page load.
        echo '<style id="ofast-fouc-guard">body.login.ofast-tc-init #login { visibility: hidden; opacity: 0; }</style>';
        // Synchronous script adds the class immediately (no DOMContentLoaded delay)
        echo '<script>document.body.classList.add("ofast-tc-init");</script>';

        if ( $isCentered ) {
            echo '<div class="ofast-tc-wrapper" id="ofast-tc-wrapper">';
            if ( $imgPos === 'left' ) {
                $this->render_image_side( $bgStyle, $overlayColor, $overlayOpacity, $heading, $subheading, $textColor, $useColor );
            }
            echo '</div>';

            echo '<script>';
            echo 'document.addEventListener("DOMContentLoaded", function() {';
            echo '  var wrapper = document.getElementById("ofast-tc-wrapper");';
            echo '  var login   = document.getElementById("login");';
            echo '  if (wrapper && login) {';
            if ( $imgPos === 'left' ) {
                echo '    wrapper.appendChild(login);';
            } else {
                echo '    wrapper.insertBefore(login, wrapper.firstChild);';
                echo '    var placeholder = document.querySelector(".ofast-login-side-placeholder");';
                echo '    if (placeholder) wrapper.appendChild(placeholder);';
            }
            // FIX: Remove guard class AFTER repositioning — form appears in correct position
            echo '  }';
            echo '  document.body.classList.remove("ofast-tc-init");';
            echo '  var guard = document.getElementById("ofast-fouc-guard");';
            echo '  if (guard) guard.remove();';
            echo '});';
            echo '</script>';

            if ( $imgPos === 'right' ) {
                echo '<div class="ofast-login-side-placeholder" style="display:none;">';
                $this->render_image_side( $bgStyle, $overlayColor, $overlayOpacity, $heading, $subheading, $textColor, $useColor );
                echo '</div>';
            }
        } else {
            $position = $imgPos === 'left' ? 'left: 0;' : 'right: 0;';
            echo '<div class="ofast-login-side" style="position:fixed; top:0; ' . $position . ' width:50%; height:100%; ' . $bgStyle . ' display:flex; align-items:center; justify-content:center; z-index:1;">';
            if ( ! $useColor ) {
                echo '<div style="position:absolute; top:0; left:0; right:0; bottom:0; background:' . $overlayColor . '; opacity:' . $overlayOpacity . ';"></div>';
            }
            echo '<div style="position:relative; z-index:2; text-align:center; padding:40px; color:' . $textColor . ';">';
            if ( $heading ) {
                echo '<h2 style="font-size:36px; font-weight:600; margin:0 0 15px; color:' . $textColor . ';">' . $heading . '</h2>';
            }
            if ( $subheading ) {
                echo '<p style="font-size:18px; margin:0; opacity:.9; color:' . $textColor . ';">' . $subheading . '</p>';
            }
            echo '</div></div>';
            // Remove FOUC guard for full-screen mode (no repositioning needed)
            echo '<script>document.body.classList.remove("ofast-tc-init"); var g=document.getElementById("ofast-fouc-guard"); if(g)g.remove();</script>';
        }

        echo '<style>@media (max-width: 768px) { .ofast-login-side { display:none !important; } }</style>';
    }

    private function render_image_side( $bgStyle, $overlayColor, $overlayOpacity, $heading, $subheading, $textColor, $useColor = false ) {
        echo '<div class="ofast-login-side" style="width:50%; min-height:100%; position:relative; ' . $bgStyle . ' display:flex; align-items:center; justify-content:center;">';
        if ( ! $useColor ) {
            echo '<div style="position:absolute; top:0; left:0; right:0; bottom:0; background:' . $overlayColor . '; opacity:' . $overlayOpacity . ';"></div>';
        }
        echo '<div style="position:relative; z-index:2; text-align:center; padding:40px; color:' . $textColor . ';">';
        if ( $heading ) {
            echo '<h2 style="font-size:36px; font-weight:600; margin:0 0 15px; color:' . $textColor . ';">' . $heading . '</h2>';
        }
        if ( $subheading ) {
            echo '<p style="font-size:18px; margin:0; opacity:.9; color:' . $textColor . ';">' . $subheading . '</p>';
        }
        echo '</div></div>';
    }

    // -------------------------------------------------------------------------
    // Logo URL / text filters
    // -------------------------------------------------------------------------

    public function custom_logo_url( $url ) {
        return $this->is_enabled() ? home_url( '/' ) : $url;
    }

    public function custom_logo_text( $text ) {
        return $this->is_enabled() ? get_bloginfo( 'name' ) : $text;
    }

    // -------------------------------------------------------------------------
    // Settings page
    // -------------------------------------------------------------------------

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied', 'ofast-x' ) );
        }

        $s               = $this->get_settings();
        $wp_logo         = admin_url( 'images/wordpress-logo.svg' );
        $current_template = $s['template'];

        // FIX: Toast unified — both save and reset now redirect with ofast_status GET param,
        // and render_settings_page reads that param in a single switch. The old code mixed
        // Ofast_X_Toast::add() (queue-based) with Ofast_X_Toast::render() (direct), making
        // it impossible to know which path controlled the display.
        if ( isset( $_GET['ofast_status'] ) ) {
            switch ( sanitize_key( $_GET['ofast_status'] ) ) {
                case 'saved':
                    echo Ofast_X_Toast::render( __( 'Settings saved successfully!', 'ofast-x' ), 'success' );
                    break;
                case 'reset':
                    echo Ofast_X_Toast::render( __( 'Settings reset to defaults!', 'ofast-x' ), 'info' );
                    break;
            }
        }
        ?>
        <div class="wrap" style="max-width:1200px;">
            <div class="ofast-header" style="margin-top:20px;">
                <div class="ofast-header-icon">
                    <span class="dashicons dashicons-admin-appearance"></span>
                </div>
                <div class="ofast-header-content">
                    <h1><?php esc_html_e( 'Login Page Redesign', 'ofast-x' ); ?></h1>
                    <p><?php esc_html_e( 'Customize your WordPress login page with modern templates and deep styling options.', 'ofast-x' ); ?></p>
                </div>
            </div>

            <style>
                .ofast-header { display:flex; align-items:center; gap:20px; background:#fff; padding:25px 30px; border-radius:12px; box-shadow:0 4px 6px -1px rgba(0,0,0,.05); margin-bottom:30px; }
                .ofast-header-icon { width:56px; height:56px; background:#fff; border:1px solid #e2e8f0; box-shadow:0 2px 4px rgba(0,0,0,.02); border-radius:16px; display:flex; align-items:center; justify-content:center; }
                .ofast-header-icon .dashicons { font-size:28px; width:28px; height:28px; color:#6366f1; }
                .ofast-header-content h1 { margin:0 0 5px; font-size:24px; font-weight:700; color:#1e293b; display:block; padding:0; }
                .ofast-header-content p { margin:0; color:#64748b; font-size:14px; }

                .ofast-template-card { display:block; cursor:pointer; border:1px solid #e2e8f0; border-radius:12px; padding:20px; text-align:center; background:#fff; width:220px; transition:all .3s cubic-bezier(.4,0,.2,1); position:relative; }
                .ofast-template-card:hover { box-shadow:0 10px 15px -3px rgba(0,0,0,.1); border-color:#6366f1; transform:translateY(-2px); }
                .ofast-template-card.active { border:2px solid #6366f1; background:#f8fafc; box-shadow:0 4px 6px -1px rgba(99,102,241,.1); }
                .ofast-template-card .preview-box { width:100%; height:120px; background:#f1f5f9; border-radius:8px; display:flex; align-items:center; justify-content:center; overflow:hidden; margin-bottom:15px; border:1px solid #e2e8f0; }
                .ofast-template-card strong { display:block; font-size:16px; color:#1e293b; margin-bottom:5px; }
                .ofast-template-card p { font-size:13px; color:#64748b; margin:0; }

                /* FIX: ofast-flex-layout was referenced in the HTML but never defined — layout
                   worked only because of the inline style attribute, making the class misleading. */
                .ofast-flex-layout { display:flex; align-items:flex-start; }

                .ofast-toggle { position:relative; display:inline-block; width:50px; height:26px; }
                .ofast-toggle input { opacity:0; width:0; height:0; }
                .ofast-toggle-slider { position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background-color:#ccc; transition:.3s; border-radius:26px; }
                .ofast-toggle-slider:before { position:absolute; content:""; height:20px; width:20px; left:3px; bottom:3px; background-color:#fff; transition:.3s; border-radius:50%; }
                .ofast-toggle input:checked + .ofast-toggle-slider { background-color:#6366f1; }
                .ofast-toggle input:checked + .ofast-toggle-slider:before { transform:translateX(24px); }
            </style>

            <form method="post">
                <?php wp_nonce_field( 'ofast_login_redesign_settings', 'ofast_login_nonce' ); ?>

                <!-- Template selector -->
                <div style="margin:20px 0;">
                    <h2 style="font-size:18px; font-weight:600; color:#1e293b; margin-bottom:20px;"><?php esc_html_e( 'Choose Template', 'ofast-x' ); ?></h2>
                    <div style="display:flex; flex-wrap:wrap; gap:20px;">

                        <label class="ofast-template-card <?php echo $current_template === 'simple' ? 'active' : ''; ?>">
                            <input type="radio" name="template" value="simple" <?php checked( $current_template, 'simple' ); ?> style="display:none;">
                            <div class="preview-box">
                                <div style="width:60px; height:70px; background:#fff; border-radius:6px; box-shadow:0 4px 12px rgba(0,0,0,.08); border:1px solid #e2e8f0;"></div>
                            </div>
                            <strong><?php esc_html_e( 'Simple', 'ofast-x' ); ?></strong>
                            <p><?php esc_html_e( 'Centered form with custom background', 'ofast-x' ); ?></p>
                        </label>

                        <label class="ofast-template-card <?php echo $current_template === 'two-column' ? 'active' : ''; ?><?php echo ! ofast_toolkit_is_pro() ? ' ofast-pro-locked' : ''; ?>" <?php echo ! ofast_toolkit_is_pro() ? 'style="opacity:.6;cursor:not-allowed;"' : ''; ?>>
                            <input type="radio" name="template" value="two-column" <?php checked( $current_template, 'two-column' ); ?> style="display:none;" <?php ofast_toolkit_pro_disabled(); ?>>
                            <div class="preview-box" style="display:flex; padding:0;">
                                <div style="width:50%; height:100%; background:linear-gradient(135deg,#6366f1 0%,#764ba2 100%);"></div>
                                <div style="width:50%; height:100%; background:#fff; display:flex; align-items:center; justify-content:center;">
                                    <div style="width:30px; height:40px; background:#f1f5f9; border-radius:3px; border:1px solid #e2e8f0;"></div>
                                </div>
                            </div>
                            <strong><?php esc_html_e( 'Two-Column', 'ofast-x' ); ?> <?php ofast_toolkit_pro_badge(); ?></strong>
                            <p><?php esc_html_e( 'Modern split-screen with side panel', 'ofast-x' ); ?></p>
                        </label>

                        <label class="ofast-template-card <?php echo $current_template === 'modern-dark' ? 'active' : ''; ?><?php echo ! ofast_toolkit_is_pro() ? ' ofast-pro-locked' : ''; ?>" <?php echo ! ofast_toolkit_is_pro() ? 'style="opacity:.6;cursor:not-allowed;"' : ''; ?>>
                            <input type="radio" name="template" value="modern-dark" <?php checked( $current_template, 'modern-dark' ); ?> style="display:none;" <?php ofast_toolkit_pro_disabled(); ?>>
                            <div class="preview-box" style="background:#0f172a; border:1px solid #334155; position:relative; display:flex; align-items:center; justify-content:center;">
                                <div style="width:60px; height:80px; background:rgba(30,41,59,.8); border-radius:8px; border:1px solid rgba(255,255,255,.1); display:flex; flex-direction:column; align-items:center; justify-content:center;">
                                    <div style="width:20px; height:2px; background:#3b82f6; margin-bottom:4px; border-radius:2px;"></div>
                                    <div style="width:40px; height:4px; background:rgba(255,255,255,.1); margin-bottom:2px; border-radius:2px;"></div>
                                    <div style="width:40px; height:4px; background:rgba(255,255,255,.1); margin-bottom:6px; border-radius:2px;"></div>
                                    <div style="width:30px; height:6px; background:linear-gradient(135deg,#0ea5e9 0%,#3b82f6 100%); border-radius:2px;"></div>
                                </div>
                            </div>
                            <strong><?php esc_html_e( 'Modern Dark', 'ofast-x' ); ?> <?php ofast_toolkit_pro_badge(); ?></strong>
                            <p><?php esc_html_e( 'Glassmorphism on dark background', 'ofast-x' ); ?></p>
                        </label>

                    </div>
                </div>

                <div class="ofast-flex-layout" style="gap:30px; margin-top:20px; align-items:flex-start;">

                    <!-- Settings Panel -->
                    <div class="ofast-main" style="max-width:500px;">

                        <div class="postbox" style="padding:20px;">
                            <h3 style="margin-top:0;"><?php esc_html_e( 'General', 'ofast-x' ); ?></h3>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th><?php esc_html_e( 'Enable', 'ofast-x' ); ?></th>
                                    <td>
                                        <label class="ofast-toggle">
                                            <input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>>
                                            <span class="ofast-toggle-slider"></span>
                                        </label>
                                        <span style="margin-left:10px; vertical-align:middle;"><?php esc_html_e( 'Enable custom login design', 'ofast-x' ); ?></span>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="postbox" style="padding:20px; margin-top:15px;">
                            <h3 style="margin-top:0;"><?php esc_html_e( 'Logo', 'ofast-x' ); ?></h3>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th><?php esc_html_e( 'Logo URL', 'ofast-x' ); ?></th>
                                    <td>
                                        <input type="text" name="logo_url" id="logo_url" value="<?php echo esc_url( $s['logo_url'] ); ?>" class="regular-text">
                                        <button type="button" class="button" id="upload_logo"><?php esc_html_e( 'Upload', 'ofast-x' ); ?></button>
                                        <?php if ( $s['logo_url'] ) : ?>
                                            <br><img src="<?php echo esc_url( $s['logo_url'] ); ?>" style="max-width:100px; margin-top:10px;">
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Logo Size', 'ofast-x' ); ?></th>
                                    <td>
                                        <input type="number" name="logo_width" value="<?php echo esc_attr( $s['logo_width'] ); ?>" style="width:80px;"> x
                                        <input type="number" name="logo_height" value="<?php echo esc_attr( $s['logo_height'] ); ?>" style="width:80px;"> px
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <!-- Two-Column settings -->
                        <div id="two-column-settings" class="postbox" style="padding:20px; margin-top:15px;<?php echo $current_template !== 'two-column' ? 'display:none;' : ''; ?>">
                            <h3 style="margin-top:0;"><?php esc_html_e( 'Two-Column Settings', 'ofast-x' ); ?></h3>
                            <h4 style="margin:0 0 10px;"><?php esc_html_e( 'Side Panel Background', 'ofast-x' ); ?></h4>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th><?php esc_html_e( 'Use Color Gradient', 'ofast-x' ); ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="tc_use_color" value="1" <?php checked( $s['tc_use_color'] ); ?>>
                                            <?php esc_html_e( 'Use gradient instead of image', 'ofast-x' ); ?>
                                        </label>
                                    </td>
                                </tr>
                                <tr class="tc-color-option" style="<?php echo ! $s['tc_use_color'] ? 'display:none;' : ''; ?>">
                                    <th><?php esc_html_e( 'Gradient Start', 'ofast-x' ); ?></th>
                                    <td><input type="text" name="tc_side_color" id="tc_side_color" value="<?php echo esc_attr( $s['tc_side_color'] ); ?>" class="color-picker"></td>
                                </tr>
                                <tr class="tc-color-option" style="<?php echo ! $s['tc_use_color'] ? 'display:none;' : ''; ?>">
                                    <th><?php esc_html_e( 'Gradient End', 'ofast-x' ); ?></th>
                                    <td><input type="text" name="tc_side_color_end" id="tc_side_color_end" value="<?php echo esc_attr( $s['tc_side_color_end'] ); ?>" class="color-picker"></td>
                                </tr>
                                <tr class="tc-image-option" style="<?php echo $s['tc_use_color'] ? 'display:none;' : ''; ?>">
                                    <th><?php esc_html_e( 'Side Image', 'ofast-x' ); ?></th>
                                    <td>
                                        <input type="text" name="tc_side_image" id="tc_side_image" value="<?php echo esc_url( $s['tc_side_image'] ); ?>" class="regular-text">
                                        <button type="button" class="button" id="upload_tc_image"><?php esc_html_e( 'Upload', 'ofast-x' ); ?></button>
                                        <p class="description"><?php esc_html_e( 'Leave empty to use the background image', 'ofast-x' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Image Position', 'ofast-x' ); ?></th>
                                    <td>
                                        <label style="margin-right:20px;"><input type="radio" name="tc_image_position" value="left" <?php checked( $s['tc_image_position'], 'left' ); ?>> <?php esc_html_e( 'Image Left, Form Right', 'ofast-x' ); ?></label>
                                        <label><input type="radio" name="tc_image_position" value="right" <?php checked( $s['tc_image_position'], 'right' ); ?>> <?php esc_html_e( 'Image Right, Form Left', 'ofast-x' ); ?></label>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Overlay Color', 'ofast-x' ); ?></th>
                                    <td><input type="text" name="tc_overlay_color" id="tc_overlay_color" value="<?php echo esc_attr( $s['tc_overlay_color'] ); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Overlay Opacity', 'ofast-x' ); ?></th>
                                    <td>
                                        <input type="range" name="tc_overlay_opacity" id="tc_overlay_opacity" min="0" max="100" value="<?php echo esc_attr( $s['tc_overlay_opacity'] ); ?>">
                                        <span id="tc_overlay_opacity_val"><?php echo esc_html( $s['tc_overlay_opacity'] ); ?>%</span>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Heading Text', 'ofast-x' ); ?></th>
                                    <td><input type="text" name="tc_heading" value="<?php echo esc_attr( $s['tc_heading'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Welcome Back', 'ofast-x' ); ?>"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Subheading Text', 'ofast-x' ); ?></th>
                                    <td>
                                        <input type="text" name="tc_subheading" value="<?php echo esc_attr( $s['tc_subheading'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
                                        <p class="description"><?php esc_html_e( 'Leave empty to use site name', 'ofast-x' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Text Color', 'ofast-x' ); ?></th>
                                    <td><input type="text" name="tc_text_color" id="tc_text_color" value="<?php echo esc_attr( $s['tc_text_color'] ); ?>" class="color-picker"></td>
                                </tr>
                            </table>

                            <hr style="margin:20px 0;">
                            <h4 style="margin-top:0;"><?php esc_html_e( 'Layout Mode', 'ofast-x' ); ?></h4>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th><?php esc_html_e( 'Centered Card', 'ofast-x' ); ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="tc_centered" value="1" <?php checked( $s['tc_centered'] ); ?>>
                                            <?php esc_html_e( 'Center layout as a card', 'ofast-x' ); ?>
                                        </label>
                                        <p class="description"><?php esc_html_e( 'Shows two-column as a centered card with background visible around it', 'ofast-x' ); ?></p>
                                    </td>
                                </tr>
                                <tr class="tc-centered-option" style="<?php echo ! $s['tc_centered'] ? 'display:none;' : ''; ?>">
                                    <th><?php esc_html_e( 'Background Color', 'ofast-x' ); ?></th>
                                    <td><input type="text" name="tc_bg_color" id="tc_bg_color" value="<?php echo esc_attr( $s['tc_bg_color'] ); ?>" class="color-picker"></td>
                                </tr>
                            </table>
                        </div>

                        <!-- Modern Dark settings -->
                        <div id="modern-dark-settings" class="postbox" style="padding:20px; margin-top:15px;<?php echo $current_template !== 'modern-dark' ? 'display:none;' : ''; ?>">
                            <h3 style="margin-top:0;"><?php esc_html_e( 'Modern Dark Design', 'ofast-x' ); ?></h3>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th><?php esc_html_e( 'Card Color', 'ofast-x' ); ?></th>
                                    <td><input type="text" name="md_card_color" id="md_card_color" value="<?php echo esc_attr( $s['md_card_color'] ); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Card Opacity', 'ofast-x' ); ?></th>
                                    <td>
                                        <input type="range" name="md_card_opacity" id="md_card_opacity" min="0" max="100" value="<?php echo esc_attr( $s['md_card_opacity'] ); ?>">
                                        <span id="md_card_opacity_val"><?php echo esc_html( $s['md_card_opacity'] ); ?>%</span>
                                        <p class="description"><?php esc_html_e( '0% = fully transparent, 100% = solid', 'ofast-x' ); ?></p>
                                    </td>
                                </tr>
                            </table>
                            <hr style="margin:20px 0;">
                            <h4 style="margin-top:0;"><?php esc_html_e( 'Background Image Overlay', 'ofast-x' ); ?></h4>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th><?php esc_html_e( 'Overlay Color', 'ofast-x' ); ?></th>
                                    <td><input type="text" name="md_overlay_color" id="md_overlay_color" value="<?php echo esc_attr( $s['md_overlay_color'] ); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Overlay Opacity', 'ofast-x' ); ?></th>
                                    <td>
                                        <input type="range" name="md_overlay_opacity" id="md_overlay_opacity" min="0" max="100" value="<?php echo esc_attr( $s['md_overlay_opacity'] ); ?>">
                                        <span id="md_overlay_opacity_val"><?php echo esc_html( $s['md_overlay_opacity'] ); ?>%</span>
                                    </td>
                                </tr>
                            </table>
                            <hr style="margin:20px 0;">
                            <h4 style="margin-top:0;"><?php esc_html_e( 'Button Style', 'ofast-x' ); ?></h4>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th><?php esc_html_e( 'Use Ofast Colors', 'ofast-x' ); ?></th>
                                    <td>
                                        <label class="ofast-toggle">
                                            <input type="checkbox" name="md_use_ofast_colors" id="md_use_ofast_colors" value="1" <?php checked( $s['md_use_ofast_colors'] ); ?>>
                                            <span class="ofast-toggle-slider"></span>
                                        </label>
                                        <p class="description"><?php esc_html_e( 'Applies the Ofast purple gradient to the login button', 'ofast-x' ); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <!-- Background (simple + modern-dark) -->
                        <div id="simple-bg-settings" class="postbox" style="padding:20px; margin-top:15px;<?php echo ( $current_template !== 'simple' && $current_template !== 'modern-dark' ) ? 'display:none;' : ''; ?>">
                            <h3 style="margin-top:0;"><?php esc_html_e( 'Background', 'ofast-x' ); ?></h3>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th><?php esc_html_e( 'Background Color', 'ofast-x' ); ?></th>
                                    <td><input type="text" name="bg_color" id="bg_color" value="<?php echo esc_attr( $s['bg_color'] ); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Background Image', 'ofast-x' ); ?></th>
                                    <td>
                                        <input type="text" name="bg_image" id="bg_image" value="<?php echo esc_url( $s['bg_image'] ); ?>" class="regular-text">
                                        <button type="button" class="button" id="upload_bg"><?php esc_html_e( 'Upload', 'ofast-x' ); ?></button>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="postbox" style="padding:20px; margin-top:15px;">
                            <h3 style="margin-top:0;"><?php esc_html_e( 'Form Styling', 'ofast-x' ); ?></h3>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th><?php esc_html_e( 'Form Background', 'ofast-x' ); ?></th>
                                    <td><input type="text" name="form_bg" id="form_bg" value="<?php echo esc_attr( $s['form_bg'] ); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Use Gradient', 'ofast-x' ); ?></th>
                                    <td><label><input type="checkbox" name="form_use_gradient" value="1" <?php checked( $s['form_use_gradient'] ); ?>> <?php esc_html_e( 'Use gradient for form background', 'ofast-x' ); ?></label></td>
                                </tr>
                                <tr class="form-gradient-option" style="<?php echo ! $s['form_use_gradient'] ? 'display:none;' : ''; ?>">
                                    <th><?php esc_html_e( 'End Color', 'ofast-x' ); ?></th>
                                    <td><input type="text" name="form_bg_end" id="form_bg_end" value="<?php echo esc_attr( $s['form_bg_end'] ); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Border Radius', 'ofast-x' ); ?></th>
                                    <td>
                                        <input type="range" name="form_radius" id="form_radius" min="0" max="30" value="<?php echo esc_attr( $s['form_radius'] ); ?>">
                                        <span id="form_radius_val"><?php echo esc_html( $s['form_radius'] ); ?>px</span>
                                    </td>
                                </tr>
                            </table>
                            <hr style="margin:20px 0;">
                            <h4 style="margin-top:0;"><?php esc_html_e( 'Input Fields', 'ofast-x' ); ?></h4>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th><?php esc_html_e( 'Border Radius', 'ofast-x' ); ?></th>
                                    <td>
                                        <input type="range" name="input_radius" id="input_radius" min="0" max="20" value="<?php echo esc_attr( $s['input_radius'] ); ?>">
                                        <span id="input_radius_val"><?php echo esc_html( $s['input_radius'] ); ?>px</span>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Border Color', 'ofast-x' ); ?></th>
                                    <td><input type="text" name="input_border_color" id="input_border_color" value="<?php echo esc_attr( $s['input_border_color'] ); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Border Width', 'ofast-x' ); ?></th>
                                    <td>
                                        <input type="range" name="input_border_width" id="input_border_width" min="0" max="5" value="<?php echo esc_attr( $s['input_border_width'] ); ?>">
                                        <span id="input_border_width_val"><?php echo esc_html( $s['input_border_width'] ); ?>px</span>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="postbox" style="padding:20px; margin-top:15px;">
                            <h3 style="margin-top:0;"><?php esc_html_e( 'Button Styling', 'ofast-x' ); ?></h3>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th><?php esc_html_e( 'Button Color', 'ofast-x' ); ?></th>
                                    <td><input type="text" name="btn_color" id="btn_color" value="<?php echo esc_attr( $s['btn_color'] ); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Hover Color', 'ofast-x' ); ?></th>
                                    <td><input type="text" name="btn_hover" id="btn_hover" value="<?php echo esc_attr( $s['btn_hover'] ); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Text Color', 'ofast-x' ); ?></th>
                                    <td><input type="text" name="btn_text_color" id="btn_text_color" value="<?php echo esc_attr( $s['btn_text_color'] ); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Border Color', 'ofast-x' ); ?></th>
                                    <td><input type="text" name="btn_border_color" id="btn_border_color" value="<?php echo esc_attr( $s['btn_border_color'] ); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Border Width', 'ofast-x' ); ?></th>
                                    <td>
                                        <input type="range" name="btn_border_width" id="btn_border_width" min="0" max="5" value="<?php echo esc_attr( $s['btn_border_width'] ); ?>">
                                        <span id="btn_border_width_val"><?php echo esc_html( $s['btn_border_width'] ); ?>px</span>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="postbox" style="padding:20px; margin-top:15px;">
                            <h3 style="margin-top:0;"><?php esc_html_e( 'Link Colors', 'ofast-x' ); ?></h3>
                            <p class="description" style="margin-top:0;"><?php esc_html_e( 'For "Register", "Lost Password" links', 'ofast-x' ); ?></p>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th><?php esc_html_e( 'Link Color', 'ofast-x' ); ?></th>
                                    <td><input type="text" name="link_color" id="link_color" value="<?php echo esc_attr( $s['link_color'] ); ?>" class="color-picker"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Link Hover', 'ofast-x' ); ?></th>
                                    <td><input type="text" name="link_hover" id="link_hover" value="<?php echo esc_attr( $s['link_hover'] ); ?>" class="color-picker"></td>
                                </tr>
                            </table>
                        </div>

                        <div class="postbox" style="padding:20px; margin-top:15px;">
                            <h3 style="margin-top:0;"><?php esc_html_e( 'Extra Options', 'ofast-x' ); ?></h3>
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th><?php esc_html_e( 'Hide "Back to Blog"', 'ofast-x' ); ?></th>
                                    <td>
                                        <label><input type="checkbox" name="hide_back_link" value="1" <?php checked( $s['hide_back_link'] ); ?>> <?php esc_html_e( 'Hide the "Back to [site]" link', 'ofast-x' ); ?></label>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Custom CSS', 'ofast-x' ); ?></th>
                                    <td><textarea name="custom_css" rows="5" class="large-text code"><?php echo esc_textarea( $s['custom_css'] ); ?></textarea></td>
                                </tr>
                            </table>
                        </div>

                        <?php echo Ofast_X_Button::get_styles(); ?>
                        <style>.ofast-btn-sm { padding: 15px 25px !important; }</style>
                        <p style="margin-top:20px; display:flex; gap:10px; align-items:center;">
                            <?php echo Ofast_X_Button::render_primary( __( 'Save Settings', 'ofast-x' ), array( 'name' => 'ofast_login_redesign_save', 'class' => 'ofast-btn-sm' ) ); ?>
                            <?php echo Ofast_X_Button::render_danger( __( 'Reset to Defaults', 'ofast-x' ), array(
                                'name'    => 'ofast_login_redesign_reset',
                                'type'    => 'submit',
                                'class'   => 'ofast-btn-sm',
                                'onclick' => "return confirm('" . esc_js( __( 'Are you sure you want to reset all settings to defaults?', 'ofast-x' ) ) . "');",
                            ) ); ?>
                            <a href="<?php echo esc_url( wp_login_url() ); ?>" target="_blank" class="ofast-btn-secondary ofast-btn ofast-btn-sm" style="text-decoration:none;"><?php esc_html_e( 'View Login Page', 'ofast-x' ); ?></a>
                        </p>

                    </div><!-- /.ofast-main -->

                    <!-- Preview Panel -->
                    <div style="width:550px; min-width:400px; flex-shrink:0; position:sticky; top:32px; align-self:flex-start;">
                        <div class="postbox" style="padding:20px;">
                            <h3 style="margin-top:0;"><?php esc_html_e( 'Live Preview', 'ofast-x' ); ?></h3>
                            <div id="login-preview" style="border:1px solid #ddd; border-radius:8px; overflow:hidden; min-height:400px; background:#f0f0f1; pointer-events:none; user-select:none;"></div>
                            <p style="margin-top:10px; color:#666; font-size:12px; font-style:italic;"><?php esc_html_e( 'Preview is for visual reference only. Interactions are disabled.', 'ofast-x' ); ?></p>
                        </div>
                    </div>

                </div><!-- /.ofast-flex-layout -->
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var siteName = <?php echo wp_json_encode( get_bloginfo( 'name' ) ); ?>;
            var wpLogo   = <?php echo wp_json_encode( $wp_logo ); ?>;

            // Initialize color pickers
            $('.color-picker').wpColorPicker({ change: function() { setTimeout(updatePreview, 100); } });

            // Template switching
            $('input[name="template"]').on('change', function() {
                var tpl = $(this).val();
                $('#two-column-settings').toggle(tpl === 'two-column');
                $('#modern-dark-settings').toggle(tpl === 'modern-dark');
                $('#simple-bg-settings').toggle(tpl === 'simple' || tpl === 'modern-dark');
                $('.ofast-template-card').removeClass('active');
                $(this).closest('.ofast-template-card').addClass('active');
                updatePreview();
            });

            // Slider labels
            $('#form_radius, #input_radius, #input_border_width, #btn_border_width, #tc_overlay_opacity, #md_card_opacity, #md_overlay_opacity').on('input', function() {
                var suffix = ['tc_overlay_opacity','md_card_opacity','md_overlay_opacity'].indexOf(this.id) !== -1 ? '%' : 'px';
                $('#' + this.id + '_val').text($(this).val() + suffix);
                updatePreview();
            });

            // Conditional toggles
            $('input[name="tc_centered"]').on('change', function() { $('.tc-centered-option').toggle(this.checked); updatePreview(); });
            $('input[name="tc_use_color"]').on('change', function() { $('.tc-color-option').toggle(this.checked); $('.tc-image-option').toggle(!this.checked); updatePreview(); });
            $('input[name="form_use_gradient"]').on('change', function() { $('.form-gradient-option').toggle(this.checked); updatePreview(); });
            $('input, textarea').on('change input', updatePreview);

            // Upload handlers
            function setupUpload(btn, input) {
                $(btn).on('click', function(e) {
                    e.preventDefault();
                    var frame = wp.media({ title: 'Select Image', multiple: false });
                    frame.on('select', function() { $(input).val(frame.state().get('selection').first().toJSON().url); updatePreview(); });
                    frame.open();
                });
            }
            setupUpload('#upload_logo', '#logo_url');
            setupUpload('#upload_bg',   '#bg_image');
            setupUpload('#upload_tc_image', '#tc_side_image');

            // FIX: Escape user-supplied strings before inserting via .html().
            // The old code used unescaped values in innerHTML-equivalent injection,
            // allowing an admin to store <script> or event-handler payloads in the
            // heading/subheading fields that executed in the admin context.
            function escHtml(str) {
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function getPreviewHtml() {
                var tpl            = $('input[name="template"]:checked').val();
                var logoUrl        = $('#logo_url').val() || wpLogo;
                var formBg         = $('.wp-color-picker[name="form_bg"]').val() || '#ffffff';
                var formRadius     = $('#form_radius').val() || 4;
                var inputRadius    = $('#input_radius').val() || 4;
                var inputBorderClr = $('.wp-color-picker[name="input_border_color"]').val() || '#8c8f94';
                var inputBorderW   = $('#input_border_width').val() || 1;
                var btnColor       = $('.wp-color-picker[name="btn_color"]').val() || '#2271b1';
                var btnText        = $('.wp-color-picker[name="btn_text_color"]').val() || '#ffffff';
                var btnBorderClr   = $('.wp-color-picker[name="btn_border_color"]').val() || '#2271b1';
                var btnBorderW     = $('#btn_border_width').val() || 1;
                var linkColor      = $('.wp-color-picker[name="link_color"]').val() || '#50575e';
                var logoW          = $('input[name="logo_width"]').val() || 84;
                var logoH          = $('input[name="logo_height"]').val() || 84;
                var hideBack       = $('input[name="hide_back_link"]').is(':checked');

                var inputStyle = 'width:100%;padding:5px;border:' + inputBorderW + 'px solid ' + inputBorderClr + ';border-radius:' + inputRadius + 'px;font-size:10px;box-sizing:border-box;';
                var btnStyle   = 'width:100%;padding:6px;background:' + btnColor + ';color:' + btnText + ';border:' + btnBorderW + 'px solid ' + btnBorderClr + ';border-radius:' + inputRadius + 'px;font-size:10px;cursor:pointer;';
                var backHtml   = hideBack ? '' : '<div style="margin-top:10px;font-size:10px;"><a href="#" style="color:' + linkColor + ';text-decoration:none;">&larr; Back to ' + escHtml(siteName) + '</a></div>';

                if (tpl === 'two-column') {
                    var sideImage    = $('#tc_side_image').val() || $('#bg_image').val() || '';
                    var useColor     = $('input[name="tc_use_color"]').is(':checked') || !sideImage;
                    var sideColor    = $('.wp-color-picker[name="tc_side_color"]').val() || '#6366f1';
                    var sideColorEnd = $('.wp-color-picker[name="tc_side_color_end"]').val() || '#764ba2';
                    var imgPos       = $('input[name="tc_image_position"]:checked').val() || 'left';
                    var overlayClr   = $('.wp-color-picker[name="tc_overlay_color"]').val() || '#000000';
                    var overlayOpac  = ($('#tc_overlay_opacity').val() || 40) / 100;
                    // FIX: escHtml applied to user-typed heading/subheading
                    var heading      = escHtml($('input[name="tc_heading"]').val() || 'Welcome Back');
                    var subheading   = escHtml($('input[name="tc_subheading"]').val() || siteName);
                    var textColor    = $('.wp-color-picker[name="tc_text_color"]').val() || '#ffffff';
                    var isCentered   = $('input[name="tc_centered"]').is(':checked');
                    var tcBgColor    = $('.wp-color-picker[name="tc_bg_color"]').val() || '#f0f0f1';
                    var formUseGrad  = $('input[name="form_use_gradient"]').is(':checked');
                    var formBgEnd    = $('.wp-color-picker[name="form_bg_end"]').val() || '#ffffff';
                    var formBgStyle  = formUseGrad ? 'linear-gradient(135deg,' + formBg + ' 0%,' + formBgEnd + ' 100%)' : formBg;

                    var bgStyle    = useColor ? 'background:linear-gradient(135deg,' + sideColor + ' 0%,' + sideColorEnd + ' 100%);' : 'background-image:url(' + sideImage + ');background-size:cover;background-position:center;';
                    var overlayDiv = useColor ? '' : '<div style="position:absolute;top:0;left:0;right:0;bottom:0;background:' + overlayClr + ';opacity:' + overlayOpac + ';"></div>';

                    var imageSide = '<div style="width:50%;height:100%;' + bgStyle + 'position:relative;display:flex;align-items:center;justify-content:center;">' + overlayDiv + '<div style="position:relative;z-index:2;text-align:center;padding:20px;color:' + textColor + ';"><div style="font-size:16px;font-weight:600;margin-bottom:5px;">' + heading + '</div><div style="font-size:11px;opacity:.9;">' + subheading + '</div></div></div>';
                    var formSide  = '<div style="width:50%;height:100%;background:' + formBgStyle + ';display:flex;align-items:center;justify-content:center;"><div style="text-align:center;width:140px;"><img src="' + escHtml(logoUrl) + '" style="width:' + (logoW * .5) + 'px;height:' + (logoH * .5) + 'px;object-fit:contain;margin-bottom:10px;"><div style="margin-bottom:8px;"><input type="text" style="' + inputStyle + '" value="Username"></div><div style="margin-bottom:8px;"><input type="password" style="' + inputStyle + '" value="pass"></div><button style="' + btnStyle + '"><?php esc_attr_e( 'Log In', 'ofast-x' ); ?></button><div style="margin-top:8px;font-size:8px;"><a href="#" style="color:' + linkColor + ';text-decoration:none;"><?php esc_attr_e( 'Register', 'ofast-x' ); ?></a> | <a href="#" style="color:' + linkColor + ';text-decoration:none;"><?php esc_attr_e( 'Lost password?', 'ofast-x' ); ?></a></div>' + (hideBack ? '' : '<div style="margin-top:6px;font-size:7px;"><a href="#" style="color:' + linkColor + ';text-decoration:none;">&larr; Back to ' + escHtml(siteName) + '</a></div>') + '</div></div>';
                    var content   = imgPos === 'left' ? imageSide + formSide : formSide + imageSide;

                    return isCentered
                        ? '<div style="background:' + tcBgColor + ';padding:20px;min-height:350px;display:flex;align-items:center;justify-content:center;"><div style="display:flex;width:90%;max-width:400px;height:300px;border-radius:' + formRadius + 'px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,.15);">' + content + '</div></div>'
                        : '<div style="display:flex;height:350px;">' + content + '</div>';

                } else if (tpl === 'modern-dark') {
                    var bgImage      = $('#bg_image').val();
                    var scale        = 0.6;
                    var cardColor    = $('.wp-color-picker[name="md_card_color"]').val() || '#0f172a';
                    var cardOpacity  = ($('#md_card_opacity').val() || 60) / 100;
                    var overlayClr2  = $('.wp-color-picker[name="md_overlay_color"]').val() || '#000000';
                    var overlayOpac2 = ($('#md_overlay_opacity').val() || 0) / 100;

                    function hexToRgb(hex) {
                        hex = hex.replace('#','');
                        if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
                        return parseInt(hex.substring(0,2),16)+', '+parseInt(hex.substring(2,4),16)+', '+parseInt(hex.substring(4,6),16);
                    }

                    var cardRgb    = hexToRgb(cardColor);
                    var overlayRgb = hexToRgb(overlayClr2);
                    var bgStyle2   = 'background-color:' + cardColor + ';' + (bgImage ? 'background-image:url(' + escHtml(bgImage) + ');background-size:cover;background-position:center;' : '');
                    var overlayDiv2 = (overlayOpac2 > 0 && bgImage) ? '<div style="position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(' + overlayRgb + ',' + overlayOpac2 + ');"></div>' : '';
                    var dInput     = 'width:100%;padding:' + (8*scale) + 'px;background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:' + (12*scale) + 'px;box-sizing:border-box;color:#fff;';
                    var dBtn       = 'width:100%;padding:' + (10*scale) + 'px;background:linear-gradient(135deg,#0ea5e9 0%,#3b82f6 100%);color:#fff;border:none;border-radius:8px;font-size:' + (12*scale) + 'px;cursor:pointer;font-weight:600;';

                    return '<div style="' + bgStyle2 + 'padding:30px;min-height:350px;display:flex;align-items:center;justify-content:center;position:relative;">' + overlayDiv2 + '<div style="text-align:center;position:relative;z-index:1;"><img src="' + escHtml(logoUrl) + '" style="width:' + (logoW*scale) + 'px;height:' + (logoH*scale) + 'px;object-fit:contain;margin-bottom:15px;"><div style="background:rgba(' + cardRgb + ',' + cardOpacity + ');backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);padding:' + (25*scale) + 'px ' + (30*scale) + 'px;border-radius:16px;border:1px solid rgba(255,255,255,.1);box-shadow:0 25px 50px rgba(0,0,0,.5);width:' + (280*scale) + 'px;"><div style="margin-bottom:' + (12*scale) + 'px;"><label style="display:block;text-align:left;margin-bottom:5px;font-size:' + (11*scale) + 'px;color:#cbd5e1;"><?php esc_attr_e( 'Username', 'ofast-x' ); ?></label><input type="text" style="' + dInput + '" value="admin"></div><div style="margin-bottom:' + (15*scale) + 'px;"><label style="display:block;text-align:left;margin-bottom:5px;font-size:' + (11*scale) + 'px;color:#cbd5e1;"><?php esc_attr_e( 'Password', 'ofast-x' ); ?></label><input type="password" style="' + dInput + '" value="password"></div><button style="' + dBtn + '"><?php esc_attr_e( 'Log In', 'ofast-x' ); ?></button><div style="margin-top:15px;height:1px;background:rgba(255,255,255,.1);"></div></div><div style="margin-top:12px;font-size:10px;"><a href="#" style="color:#94a3b8;text-decoration:none;"><?php esc_attr_e( 'Forgot Password?', 'ofast-x' ); ?></a><span style="color:#475569;margin:0 8px;">|</span><a href="#" style="color:#94a3b8;text-decoration:none;"><?php esc_attr_e( 'Register', 'ofast-x' ); ?></a></div></div></div>';

                } else {
                    // Simple
                    var bgClr    = $('.wp-color-picker[name="bg_color"]').val() || '#f0f0f1';
                    var bgImg    = $('#bg_image').val();
                    var scale    = 0.6;
                    var bgStyle3 = 'background-color:' + bgClr + ';' + (bgImg ? 'background-image:url(' + escHtml(bgImg) + ');background-size:cover;background-position:center;' : '');
                    var sInput   = 'width:100%;padding:' + (8*scale) + 'px;border:' + inputBorderW + 'px solid ' + inputBorderClr + ';border-radius:' + inputRadius + 'px;font-size:' + (14*scale) + 'px;box-sizing:border-box;';
                    var sBtn     = 'width:100%;padding:' + (10*scale) + 'px;background:' + btnColor + ';color:' + btnText + ';border:' + btnBorderW + 'px solid ' + btnBorderClr + ';border-radius:' + inputRadius + 'px;font-size:' + (14*scale) + 'px;cursor:pointer;';

                    return '<div style="' + bgStyle3 + 'padding:30px;min-height:350px;display:flex;align-items:center;justify-content:center;"><div style="text-align:center;"><img src="' + escHtml(logoUrl) + '" style="width:' + (logoW*scale) + 'px;height:' + (logoH*scale) + 'px;object-fit:contain;margin-bottom:20px;"><div style="background:' + formBg + ';padding:' + (20*scale) + 'px ' + (30*scale) + 'px;border-radius:' + formRadius + 'px;box-shadow:0 4px 20px rgba(0,0,0,.1);width:' + (280*scale) + 'px;"><div style="margin-bottom:' + (15*scale) + 'px;"><label style="display:block;text-align:left;margin-bottom:5px;font-size:' + (12*scale) + 'px;"><?php esc_attr_e( 'Username', 'ofast-x' ); ?></label><input type="text" style="' + sInput + '" value="admin"></div><div style="margin-bottom:' + (15*scale) + 'px;"><label style="display:block;text-align:left;margin-bottom:5px;font-size:' + (12*scale) + 'px;"><?php esc_attr_e( 'Password', 'ofast-x' ); ?></label><input type="password" style="' + sInput + '" value="password"></div><button style="' + sBtn + '"><?php esc_attr_e( 'Log In', 'ofast-x' ); ?></button></div><div style="margin-top:15px;font-size:11px;"><a href="#" style="color:' + linkColor + ';text-decoration:none;"><?php esc_attr_e( 'Register', 'ofast-x' ); ?></a><span style="color:' + linkColor + ';"> | </span><a href="#" style="color:' + linkColor + ';text-decoration:none;"><?php esc_attr_e( 'Lost your password?', 'ofast-x' ); ?></a></div>' + backHtml + '</div></div>';
                }
            }

            function updatePreview() {
                $('#login-preview').html(getPreviewHtml());
            }

            updatePreview();
        });
        </script>
        <?php
    }
}
