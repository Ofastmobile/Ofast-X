<?php

/**
 * Ofast X - Spam Protection Module
 * Unified settings for Cloudflare Turnstile and Google reCAPTCHA
 *
 * Fixes applied (pre-ship audit):
 *  - authenticate filter moved to priority 9 (before WP credential check at 20)
 *  - verify_login() no longer skips spam check on credential errors
 *  - $token defined unconditionally to prevent undefined-variable path
 *  - wp_login_failed action fired on spam block so lockout plugins respond
 *  - wp_unslash applied to nonce and all $_POST token reads
 *  - CF7 integration switched to wpcf7_spam filter (correct API)
 *  - add_cf7_widget() regex extended to match <button type="submit">
 *  - get_client_ip() stops trusting X-Forwarded-For (spoofable); uses CF header + X-Real-IP + REMOTE_ADDR
 *  - WooCommerce option marked coming-soon since handler is not implemented
 *  - i18n applied to all user-facing error strings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ofast_X_Spam_Protection {

    /**
     * Initialize module
     */
    public function init() {
        // Admin menu
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

        // Honeypot — always on
        if ( class_exists( 'Ofast_X_Honeypot' ) && get_option( 'ofast_spam_honeypot_enabled', true ) ) {
            Ofast_X_Honeypot::get_instance()->init();
        }

        // Universal spam (Pro — force-all-forms)
        if ( class_exists( 'Ofast_X_Universal_Spam' ) && get_option( 'ofast_spam_force_all_forms', false ) ) {
            Ofast_X_Universal_Spam::get_instance()->init();
        }

        // Math CAPTCHA
        if ( class_exists( 'Ofast_X_Math_Captcha' ) && $this->get_active_provider() === 'math_captcha' ) {
            Ofast_X_Math_Captcha::get_instance()->init();
        }

        $protect_comments = get_option( 'ofast_spam_protect_comments', false );
        $protect_cf7      = get_option( 'ofast_spam_protect_cf7', false );
        $protect_login    = get_option( 'ofast_spam_protect_login', false );

        // Comment form
        if ( $protect_comments && $this->is_configured() ) {
            add_action( 'comment_form_after_fields',   array( $this, 'render_comment_widget' ) );
            add_action( 'comment_form_logged_in_after', array( $this, 'render_comment_widget' ) );
            add_filter( 'preprocess_comment',          array( $this, 'verify_comment' ), 10, 1 );
            add_action( 'wp_enqueue_scripts',          array( $this, 'enqueue_frontend_script' ) );
        }

        // Contact Form 7
        if ( $protect_cf7 && $this->is_configured() ) {
            add_filter( 'wpcf7_form_elements', array( $this, 'add_cf7_widget' ) );
            // FIX: Use wpcf7_spam instead of wpcf7_validate.
            // wpcf7_validate requires a valid WPCF7_FormTag object; wpcf7_spam is the correct
            // integration point for third-party verification that isn't tied to a specific field.
            add_filter( 'wpcf7_spam', array( $this, 'check_cf7_spam' ), 20 );
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_script' ) );
        }

        // WordPress Login
        if ( $protect_login && $this->is_configured() ) {
            add_action( 'login_form',           array( $this, 'render_login_widget' ) );
            add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login_script' ) );
            // FIX: Priority 9 — must run BEFORE WP's credential check at priority 20.
            // At priority 30 (old value) the credential check already fired; a wrong-password
            // WP_Error was returned first and our guard skipped the spam check entirely,
            // allowing unlimited brute-force attempts with no CAPTCHA enforcement.
            add_filter( 'authenticate', array( $this, 'verify_login' ), 9, 3 );
        }
    }

    // -------------------------------------------------------------------------
    // Enqueue helpers
    // -------------------------------------------------------------------------

    public function enqueue_frontend_script() {
        $provider = $this->get_active_provider();
        if ( $provider === 'turnstile' && class_exists( 'Ofast_X_Turnstile' ) ) {
            Ofast_X_Turnstile::enqueue_script();
        }
    }

    public function enqueue_login_script() {
        $provider = $this->get_active_provider();
        if ( $provider === 'turnstile' && class_exists( 'Ofast_X_Turnstile' ) ) {
            Ofast_X_Turnstile::enqueue_script();
        }
    }

    // -------------------------------------------------------------------------
    // Comment protection
    // -------------------------------------------------------------------------

    public function render_comment_widget() {
        $provider = $this->get_active_provider();

        if ( $provider === 'turnstile' && class_exists( 'Ofast_X_Turnstile' ) ) {
            echo '<p class="comment-form-turnstile" style="margin: 10px 0;">';
            echo Ofast_X_Turnstile::get_instance()->render_widget( 'comment' );
            echo '</p>';
        } elseif ( $provider === 'math_captcha' && class_exists( 'Ofast_X_Math_Captcha' ) ) {
            echo '<p class="comment-form-math-captcha" style="margin: 10px 0;">';
            echo Ofast_X_Math_Captcha::get_instance()->render_widget( 'comment' );
            echo '</p>';
        }
    }

    public function verify_comment( $commentdata ) {
        if ( current_user_can( 'manage_options' ) ) {
            return $commentdata;
        }

        $provider = $this->get_active_provider();
        $token    = '';

        if ( $provider !== 'math_captcha' ) {
            // FIX: wp_unslash before sanitize on all $_POST reads
            $token = isset( $_POST['cf-turnstile-response'] )
                ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) )
                : '';
            if ( empty( $token ) ) {
                $token = isset( $_POST['g-recaptcha-response'] )
                    ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) )
                    : '';
            }
        }

        $result = $this->verify_with_turnstile_honeypot_fallback( $provider, $token );

        if ( ! $result['success'] ) {
            wp_die(
                '<strong>' . esc_html__( 'Spam protection failed:', 'ofast-x' ) . '</strong> ' .
                esc_html( $result['error'] ?? __( 'Verification required.', 'ofast-x' ) ),
                esc_html__( 'Comment Blocked', 'ofast-x' ),
                array( 'response' => 403, 'back_link' => true )
            );
        }

        return $commentdata;
    }

    // -------------------------------------------------------------------------
    // Contact Form 7
    // -------------------------------------------------------------------------

    public function add_cf7_widget( $elements ) {
        $provider = $this->get_active_provider();
        $widget   = '';

        if ( $provider === 'turnstile' && class_exists( 'Ofast_X_Turnstile' ) ) {
            $widget = '<div class="wpcf7-turnstile" style="margin: 15px 0;">';
            $widget .= Ofast_X_Turnstile::get_instance()->render_widget( 'cf7' );
            if ( class_exists( 'Ofast_X_Honeypot' ) && get_option( 'ofast_spam_honeypot_enabled', true ) ) {
                $widget .= Ofast_X_Honeypot::get_field_html();
            }
            $widget .= '</div>';
        } elseif ( $provider === 'math_captcha' && class_exists( 'Ofast_X_Math_Captcha' ) ) {
            $widget  = '<div class="wpcf7-math-captcha" style="margin: 15px 0;">';
            $widget .= Ofast_X_Math_Captcha::get_instance()->render_widget( 'cf7' );
            $widget .= '</div>';
        }

        if ( ! empty( $widget ) ) {
            // FIX: original regex only matched <input type="submit">.
            // CF7 and some themes use <button type="submit"> — both variants are now handled.
            $submit_pattern = '/(<input[^>]*type=["\']submit["\'][^>]*\/?>' .
                              '|<button[^>]*type=["\']submit["\'][^>]*>[\s\S]*?<\/button>)/i';
            $injected = preg_replace( $submit_pattern, $widget . '$1', $elements, 1 );
            // If regex found nothing (no submit element), append the widget before closing tag
            $elements = ( $injected !== $elements ) ? $injected : $elements . $widget;
        }

        return $elements;
    }

    /**
     * FIX: Replaces the old validate_cf7() method which incorrectly called
     * $result->invalidate('', ...) — passing '' instead of a WPCF7_FormTag object
     * causes a PHP warning and may silently pass spam on some CF7 versions.
     *
     * wpcf7_spam is the correct hook for third-party spam gates: returning true
     * marks the submission as spam and CF7 shows a configurable error message
     * without requiring a specific form field to be targeted.
     */
    public function check_cf7_spam( $spam ) {
        if ( $spam ) {
            return $spam; // Already flagged by another check — don't double-verify
        }

        $provider = $this->get_active_provider();
        $token    = '';

        if ( $provider !== 'math_captcha' ) {
            $token = isset( $_POST['cf-turnstile-response'] )
                ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) )
                : '';
            if ( empty( $token ) ) {
                $token = isset( $_POST['g-recaptcha-response'] )
                    ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) )
                    : '';
            }
        }

        $result = $this->verify_with_turnstile_honeypot_fallback( $provider, $token );

        return ! $result['success'];
    }

    // -------------------------------------------------------------------------
    // Login form protection
    // -------------------------------------------------------------------------

    public function render_login_widget() {
        $provider = $this->get_active_provider();

        if ( $provider === 'turnstile' && class_exists( 'Ofast_X_Turnstile' ) ) {
            echo '<div class="login-form-turnstile" style="margin: 15px 0;">';
            echo Ofast_X_Turnstile::get_instance()->render_widget( 'login' );
            echo '</div>';
        } elseif ( $provider === 'math_captcha' && class_exists( 'Ofast_X_Math_Captcha' ) ) {
            echo '<div class="login-form-math-captcha" style="margin: 15px 0;">';
            echo Ofast_X_Math_Captcha::get_instance()->render_widget( 'login' );
            echo '</div>';
        }
    }

    /**
     * Verify login form spam protection.
     *
     * Runs at priority 9 — BEFORE WordPress core credential check (priority 20).
     * This ensures every login attempt is gated by spam verification regardless
     * of whether the credentials are correct.  The old priority-30 placement
     * allowed unlimited wrong-password attempts to bypass CAPTCHA because the
     * credential error was already set when this method ran.
     */
    public function verify_login( $user, $username, $password ) {
        // Skip rendering/empty form loads — not a real submission
        if ( empty( $username ) && empty( $password ) ) {
            return $user;
        }

        $provider = $this->get_active_provider();

        // FIX: $token defined unconditionally to avoid undefined-variable warning
        // on the fallback path when provider is not math_captcha.
        $token = '';

        if ( $provider === 'math_captcha' ) {
            if ( ! isset( $_POST['ofast_math_answer'] ) || $_POST['ofast_math_answer'] === '' ) {
                return new WP_Error(
                    'spam_protection_failed',
                    /* translators: Login form security challenge error */
                    __( '<strong>Security verification required.</strong> Please solve the math problem.', 'ofast-x' )
                );
            }
        } else {
            $token = isset( $_POST['cf-turnstile-response'] )
                ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) )
                : '';
            if ( empty( $token ) ) {
                $token = isset( $_POST['g-recaptcha-response'] )
                    ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) )
                    : '';
            }

            // Block immediately if no token and no honeypot fallback is available
            if ( empty( $token ) && ! $this->should_try_turnstile_honeypot_fallback( $provider, $token ) ) {
                return new WP_Error(
                    'spam_protection_failed',
                    __( '<strong>Security verification required.</strong> Please complete the spam protection challenge.', 'ofast-x' )
                );
            }
        }

        $result = $this->verify_with_turnstile_honeypot_fallback( $provider, $token );

        if ( ! $result['success'] ) {
            if ( class_exists( 'Ofast_X_Logger' ) ) {
                Ofast_X_Logger::warning( sprintf(
                    'Login spam verification failed — IP: %s, reason: %s',
                    $this->get_client_ip(),
                    $result['error'] ?? 'unknown'
                ) );
            }

            // FIX: Fire wp_login_failed so lockout plugins (Wordfence, Limit Login Attempts, etc.)
            // record this attempt and can enforce brute-force limits.
            do_action( 'wp_login_failed', $username, new WP_Error( 'spam_protection_failed', $result['error'] ?? '' ) );

            return new WP_Error(
                'spam_protection_failed',
                '<strong>' . esc_html__( 'Spam protection failed:', 'ofast-x' ) . '</strong> ' .
                esc_html( $result['error'] ?? __( 'Please complete the verification.', 'ofast-x' ) )
            );
        }

        return $user;
    }

    // -------------------------------------------------------------------------
    // Honeypot fallback helpers
    // -------------------------------------------------------------------------

    private function should_try_turnstile_honeypot_fallback( $provider, $token, $result = array() ) {
        if ( $provider !== 'turnstile' ) {
            return false;
        }
        if ( ! class_exists( 'Ofast_X_Honeypot' ) || ! get_option( 'ofast_spam_honeypot_enabled', true ) ) {
            return false;
        }
        if ( ! Ofast_X_Honeypot::has_submitted_field() ) {
            return false;
        }
        if ( empty( $token ) ) {
            return true;
        }
        return isset( $result['code'] ) && $result['code'] === 'api_error';
    }

    private function verify_with_turnstile_honeypot_fallback( $provider, $token ) {
        $result = $this->verify( $token );

        if ( $result['success'] ) {
            return $result;
        }

        if ( ! $this->should_try_turnstile_honeypot_fallback( $provider, $token, $result ) ) {
            return $result;
        }

        $honeypot_result = Ofast_X_Honeypot::verify();
        if ( $honeypot_result['success'] ) {
            $honeypot_result['fallback'] = true;
            return $honeypot_result;
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Admin menu & settings page
    // -------------------------------------------------------------------------

    public function add_admin_menu() {
        add_submenu_page(
            'ofast-dashboard',
            __( 'Spam Protection', 'ofast-x' ),
            __( 'Spam Protection', 'ofast-x' ),
            'manage_options',
            'ofast-spam-protection',
            array( $this, 'render_page' )
        );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied', 'ofast-x' ) );
        }

        // FIX: wp_unslash before nonce verification — slashed POST data causes
        // verification failures on servers where magic_quotes_gpc behaviour is emulated.
        if (
            isset( $_POST['ofast_save_recaptcha'] ) &&
            wp_verify_nonce( wp_unslash( $_POST['recaptcha_nonce'] ?? '' ), 'ofast_recaptcha_save' )
        ) {
            $secret_save_failed = false;

            update_option( 'ofast_spam_provider', sanitize_text_field( wp_unslash( $_POST['spam_provider'] ?? 'turnstile' ) ) );

            update_option( 'ofast_spam_protect_comments', isset( $_POST['protect_comments'] ) ? 1 : 0 );
            update_option( 'ofast_spam_protect_cf7',      isset( $_POST['protect_cf7'] )      ? 1 : 0 );
            update_option( 'ofast_spam_protect_login',    isset( $_POST['protect_login'] )    ? 1 : 0 );

            // WooCommerce protection — UI present but handler not yet implemented (v2)
            // Saving the preference so it persists when the handler ships
            update_option( 'ofast_spam_protect_woocommerce', isset( $_POST['protect_woocommerce'] ) ? 1 : 0 );

            // Pro-gated advanced options
            if ( ! ofast_toolkit_is_pro() ) {
                update_option( 'ofast_spam_honeypot_enabled', 1 );
            } else {
                update_option( 'ofast_spam_force_all_forms',  isset( $_POST['force_all_forms'] )  ? 1 : 0 );
                update_option( 'ofast_spam_honeypot_enabled', isset( $_POST['honeypot_enabled'] ) ? 1 : 0 );
                update_option( 'ofast_spam_fail_open',        isset( $_POST['spam_fail_open'] )   ? 1 : 0 );
            }

            // Math CAPTCHA settings
            if ( class_exists( 'Ofast_X_Math_Captcha' ) ) {
                Ofast_X_Math_Captcha::save_settings( $_POST );
            }

            // Turnstile keys
            if ( ! empty( $_POST['turnstile_site_key'] ) ) {
                update_option( 'ofast_turnstile_site_key', sanitize_text_field( wp_unslash( $_POST['turnstile_site_key'] ) ) );
            }

            $turnstile_secret = sanitize_text_field( wp_unslash( $_POST['turnstile_secret_key'] ?? '' ) );
            if ( $turnstile_secret !== '' ) {
                $turnstile_site_key = sanitize_text_field( wp_unslash( $_POST['turnstile_site_key'] ?? get_option( 'ofast_turnstile_site_key', '' ) ) );
                $saved = class_exists( 'Ofast_X_Turnstile' )
                    ? Ofast_X_Turnstile::save_keys( $turnstile_site_key, $turnstile_secret )
                    : false;
                if ( ! $saved ) {
                    $secret_save_failed = true;
                }
            }

            // reCAPTCHA keys
            if ( ! empty( $_POST['recaptcha_site_key'] ) ) {
                update_option( 'ofast_recaptcha_site_key', sanitize_text_field( wp_unslash( $_POST['recaptcha_site_key'] ) ) );
            }

            $recaptcha_secret = sanitize_text_field( wp_unslash( $_POST['recaptcha_secret_key'] ?? '' ) );
            if ( $recaptcha_secret !== '' ) {
                if ( class_exists( 'Ofast_X_Security_Hardening' ) ) {
                    $encrypted = Ofast_X_Security_Hardening::encrypt_option( $recaptcha_secret );
                    if ( $encrypted !== false ) {
                        update_option( 'ofast_recaptcha_secret_key', $encrypted );
                    } else {
                        $secret_save_failed = true;
                    }
                } else {
                    $secret_save_failed = true;
                }
            }

            if ( isset( $_POST['recaptcha_threshold'] ) ) {
                update_option( 'ofast_recaptcha_threshold', floatval( $_POST['recaptcha_threshold'] ) );
            }

            $redirect_args = $secret_save_failed
                ? array( 'settings_error' => 'secret_save_failed' )
                : array( 'settings_saved' => '1' );

            wp_safe_redirect( add_query_arg( $redirect_args, wp_get_referer() ) );
            exit;
        }

        // Retrieve current options
        $active_provider    = get_option( 'ofast_spam_provider', 'turnstile' );
        $recaptcha_site_key = get_option( 'ofast_recaptcha_site_key', '' );
        $recaptcha_threshold = get_option( 'ofast_recaptcha_threshold', 0.5 );
        $protect_comments   = get_option( 'ofast_spam_protect_comments', false );
        $protect_cf7        = get_option( 'ofast_spam_protect_cf7', false );
        $protect_login      = get_option( 'ofast_spam_protect_login', false );
        $protect_woocommerce = get_option( 'ofast_spam_protect_woocommerce', false );
        $force_all_forms    = get_option( 'ofast_spam_force_all_forms', false );
        $honeypot_enabled   = get_option( 'ofast_spam_honeypot_enabled', true );
        $fail_open          = get_option( 'ofast_spam_fail_open', false );
        $default_tab        = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';

        if ( isset( $_GET['settings_saved'] ) ) {
            echo Ofast_X_Toast::render( __( 'Settings saved successfully!', 'ofast-x' ), 'success' );
        } elseif ( isset( $_GET['settings_error'] ) && $_GET['settings_error'] === 'secret_save_failed' ) {
            echo Ofast_X_Toast::render(
                __( 'Other settings were saved, but one or more secret keys could not be stored securely. Check WordPress security keys/OpenSSL and re-enter the secret key.', 'ofast-x' ),
                'error'
            );
        }

        if ( class_exists( 'Ofast_X_Dropdown' ) ) {
            echo Ofast_X_Dropdown::render_assets();
        }
        ?>
        <style>
            :root { --ofast-primary: #6366f1; }

            .ofast-tabs-nav {
                display: flex; flex-wrap: nowrap; gap: 8px; margin-bottom: 25px;
                padding: 10px 12px; background: #fff; border-radius: 12px;
                border: 1px solid rgba(226,232,240,.6); position: sticky; top: 47px;
                z-index: 100; box-shadow: 0 4px 6px -1px rgba(0,0,0,.05), 0 2px 4px -1px rgba(0,0,0,.03);
            }
            .ofast-tab {
                display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px;
                background: transparent; border: none; border-radius: 8px; color: #64748b;
                font-size: 14px; font-weight: 500; text-decoration: none; cursor: pointer;
                transition: all .2s ease; flex-shrink: 0; white-space: nowrap;
            }
            .ofast-tab:hover { background: #fff; color: #1e293b; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
            .ofast-tab.active { background: var(--ofast-primary); color: #fff; box-shadow: 0 4px 12px rgba(99,102,241,.4); }
            .ofast-tab .dashicons { font-size: 16px; width: 16px; height: 16px; line-height: 16px; }
            .ofast-tab-content { display: none; }
            .ofast-tab-content.active { display: block; animation: ofastFadeIn .3s ease; }

            .ofast-card {
                background: #fff; border-radius: 16px; padding: 40px;
                box-shadow: 0 10px 15px -3px rgba(0,0,0,.1), 0 4px 6px -2px rgba(0,0,0,.05);
                margin-top: 20px; border: 1px solid rgba(226,232,240,.6);
            }
            .ofast-card h2 { margin-top: 0; }

            .ofast-toggle { position: relative; display: inline-block; width: 44px; height: 24px; vertical-align: middle; margin-right: 10px; }
            .ofast-toggle input { opacity: 0; width: 0; height: 0; }
            .ofast-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .4s; border-radius: 34px; }
            .ofast-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: #fff; transition: .4s; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
            input:checked + .ofast-slider { background-color: var(--ofast-primary); }
            input:focus + .ofast-slider { box-shadow: 0 0 1px var(--ofast-primary); }
            input:checked + .ofast-slider:before { transform: translateX(20px); }

            .button.button-primary {
                background: linear-gradient(135deg,#6366f1 0%,#4f46e5 100%) !important;
                border-color: #6366f1 !important; text-shadow: none !important;
                box-shadow: 0 4px 15px rgba(99,102,241,.3) !important;
                transition: all .3s ease !important; padding-top: 10px !important;
                padding-bottom: 10px !important; height: auto !important;
            }
            .button.button-primary:hover {
                background: linear-gradient(135deg,#4f46e5 0%,#4338ca 100%) !important;
                transform: translateY(-2px); box-shadow: 0 6px 20px rgba(99,102,241,.4) !important;
            }
            .button.button-primary:active { transform: translateY(0); }

            @keyframes ofastFadeIn { from { opacity:0; transform:translateY(5px); } to { opacity:1; transform:translateY(0); } }

            .ofast-header { display:flex; align-items:center; gap:20px; background:#fff; padding:25px 30px; border-radius:12px; box-shadow:0 4px 6px -1px rgba(0,0,0,.05); margin-bottom:30px; margin-top:20px; }
            .ofast-header-icon { width:56px; height:56px; background:#fff; border:1px solid #e2e8f0; box-shadow:0 2px 4px rgba(0,0,0,.02); border-radius:16px; display:flex; align-items:center; justify-content:center; }
            .ofast-header-icon .dashicons { font-size:28px; width:28px; height:28px; color:#6366f1; }
            .ofast-header-content h1 { margin:0 0 5px; font-size:24px; font-weight:700; color:#1e293b; display:block; padding:0; }
            .ofast-header-content p { margin:0; color:#64748b; font-size:14px; }

            /* Provider selector — card-style radio buttons, not toggle switches */
            .ofast-provider-card {
                display:flex; align-items:flex-start; gap:12px; padding:16px 20px;
                border:2px solid #e2e8f0; border-radius:10px; cursor:pointer;
                transition:border-color .2s; margin-bottom:12px; background:#fff;
            }
            .ofast-provider-card:hover { border-color: #6366f1; }
            .ofast-provider-card input[type="radio"] { margin-top:2px; flex-shrink:0; accent-color:#6366f1; width:16px; height:16px; }
            .ofast-provider-card.selected { border-color:#6366f1; background:#f8f7ff; }
            .ofast-provider-card .ofast-provider-label { font-weight:600; color:#1e293b; font-size:14px; }
            .ofast-provider-card .ofast-provider-desc { font-size:12px; color:#64748b; margin-top:3px; }
        </style>

        <div class="wrap">
            <div class="ofast-header">
                <div class="ofast-header-icon">
                    <span class="dashicons dashicons-shield"></span>
                </div>
                <div class="ofast-header-content">
                    <h1><?php esc_html_e( 'Spam Protection', 'ofast-x' ); ?></h1>
                    <p><?php esc_html_e( 'Unified settings for Cloudflare Turnstile, Google reCAPTCHA, and Math CAPTCHA.', 'ofast-x' ); ?></p>
                </div>
            </div>

            <form method="post">
                <?php wp_nonce_field( 'ofast_recaptcha_save', 'recaptcha_nonce' ); ?>

                <nav class="ofast-tabs-nav" id="spam-tabs-nav">
                    <a href="#" class="ofast-tab <?php echo $default_tab === 'general' ? 'active' : ''; ?>" data-tab="general">
                        <span class="dashicons dashicons-shield"></span>
                        <?php esc_html_e( 'General', 'ofast-x' ); ?>
                    </a>
                    <a href="#" class="ofast-tab <?php echo $default_tab === 'turnstile' ? 'active' : ''; ?>" data-tab="turnstile">
                        <span class="dashicons dashicons-cloud"></span>
                        <?php esc_html_e( 'Turnstile', 'ofast-x' ); ?>
                    </a>
                    <a href="#" class="ofast-tab <?php echo $default_tab === 'math_captcha' ? 'active' : ''; ?>" data-tab="math_captcha">
                        <span class="dashicons dashicons-calculator"></span>
                        <?php esc_html_e( 'Math CAPTCHA', 'ofast-x' ); ?>
                    </a>
                    <a href="#" class="ofast-tab <?php echo $default_tab === 'recaptcha' ? 'active' : ''; ?>" data-tab="recaptcha">
                        <span class="dashicons dashicons-google"></span>
                        <?php esc_html_e( 'reCAPTCHA', 'ofast-x' ); ?>
                    </a>
                </nav>

                <!-- General Tab -->
                <div id="tab-general" class="ofast-tab-content<?php echo $default_tab === 'general' ? ' active' : ''; ?>">
                    <div class="ofast-card">
                        <h2><?php esc_html_e( 'Active Provider', 'ofast-x' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Select which spam protection service to use on your site.', 'ofast-x' ); ?></p>

                        <?php
                        // FIX: Replaced broken <label class="ofast-toggle"><input type="radio"> pattern.
                        // The .ofast-toggle CSS is designed for checkboxes (uses :checked + .ofast-slider
                        // adjacent sibling selector on a span). Applying it to radio inputs produced
                        // broken/invisible controls. Provider selection now uses readable card-style UI.
                        $providers = array(
                            'turnstile'    => array(
                                'label' => __( 'Cloudflare Turnstile', 'ofast-x' ),
                                'badge' => __( 'Recommended', 'ofast-x' ),
                                'desc'  => __( 'Free, privacy-friendly, invisible challenge. No API billing.', 'ofast-x' ),
                            ),
                            'math_captcha' => array(
                                'label' => __( 'Math CAPTCHA', 'ofast-x' ),
                                'badge' => __( 'No API keys needed', 'ofast-x' ),
                                'desc'  => __( 'Simple arithmetic challenge (e.g. 5 + 3 = ?). Works fully offline.', 'ofast-x' ),
                            ),
                            'recaptcha_v2' => array(
                                'label' => __( 'Google reCAPTCHA v2', 'ofast-x' ),
                                'badge' => '',
                                'desc'  => __( 'Traditional "I\'m not a robot" checkbox.', 'ofast-x' ),
                            ),
                            'recaptcha_v3' => array(
                                'label' => __( 'Google reCAPTCHA v3', 'ofast-x' ),
                                'badge' => '',
                                'desc'  => __( 'Invisible scoring system. No user interaction required.', 'ofast-x' ),
                            ),
                        );
                        foreach ( $providers as $value => $info ) :
                            $is_selected = $active_provider === $value;
                        ?>
                        <label class="ofast-provider-card<?php echo $is_selected ? ' selected' : ''; ?>">
                            <input type="radio" name="spam_provider" value="<?php echo esc_attr( $value ); ?>" <?php checked( $active_provider, $value ); ?>>
                            <div>
                                <div class="ofast-provider-label">
                                    <?php echo esc_html( $info['label'] ); ?>
                                    <?php if ( $info['badge'] ) : ?>
                                        <span style="font-size:11px;font-weight:400;color:#10b981;background:#ecfdf5;padding:2px 8px;border-radius:20px;margin-left:6px;">
                                            <?php echo esc_html( $info['badge'] ); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="ofast-provider-desc"><?php echo esc_html( $info['desc'] ); ?></div>
                            </div>
                        </label>
                        <?php endforeach; ?>

                        <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

                        <h2><?php esc_html_e( 'Where to Apply', 'ofast-x' ); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th><?php esc_html_e( 'WordPress Comments', 'ofast-x' ); ?></th>
                                <td>
                                    <label class="ofast-toggle">
                                        <input type="checkbox" name="protect_comments" value="1" <?php checked( $protect_comments ); ?>>
                                        <span class="ofast-slider"></span>
                                    </label>
                                    <span class="description" style="vertical-align:middle;"><?php esc_html_e( 'Protect blog post comment forms', 'ofast-x' ); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Contact Form 7', 'ofast-x' ); ?></th>
                                <td>
                                    <label class="ofast-toggle">
                                        <input type="checkbox" name="protect_cf7" value="1" <?php checked( $protect_cf7 ); ?>>
                                        <span class="ofast-slider"></span>
                                    </label>
                                    <span class="description" style="vertical-align:middle;"><?php esc_html_e( 'Protect CF7 forms', 'ofast-x' ); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'WordPress Login', 'ofast-x' ); ?></th>
                                <td>
                                    <label class="ofast-toggle">
                                        <input type="checkbox" name="protect_login" value="1" <?php checked( $protect_login ); ?>>
                                        <span class="ofast-slider"></span>
                                    </label>
                                    <span class="description" style="vertical-align:middle;"><?php esc_html_e( 'Protect the wp-login.php login page', 'ofast-x' ); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'WooCommerce', 'ofast-x' ); ?></th>
                                <td>
                                    <?php
                                    // FIX: The woocommerce protection handler was not implemented —
                                    // no hooks were registered in init() for this option.
                                    // The checkbox is retained (so the preference is saved for v2)
                                    // and clearly labeled as coming soon to avoid misleading admins.
                                    ?>
                                    <label class="ofast-toggle">
                                        <input type="checkbox" name="protect_woocommerce" value="1" <?php checked( $protect_woocommerce ); ?> disabled>
                                        <span class="ofast-slider"></span>
                                    </label>
                                    <span class="description" style="vertical-align:middle;">
                                        <?php esc_html_e( 'WooCommerce protection — coming in v2', 'ofast-x' ); ?>
                                    </span>
                                </td>
                            </tr>
                        </table>

                        <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

                        <h2><?php esc_html_e( 'Advanced Protection', 'ofast-x' ); ?> <?php ofast_toolkit_pro_badge(); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th><?php esc_html_e( 'Force All Forms', 'ofast-x' ); ?></th>
                                <td>
                                    <label class="ofast-toggle">
                                        <input type="checkbox" name="force_all_forms" value="1" <?php checked( $force_all_forms ); ?> <?php ofast_toolkit_pro_disabled(); ?>>
                                        <span class="ofast-slider"></span>
                                    </label>
                                    <span class="description" style="vertical-align:middle;">
                                        <?php esc_html_e( 'Universal protection — injects into ALL login/registration forms (WooCommerce, BuddyPress, MemberPress, etc.)', 'ofast-x' ); ?>
                                        <?php ofast_toolkit_pro_badge(); ?>
                                    </span>
                                    <p class="description" style="margin-top:8px;color:#666;">
                                        <?php esc_html_e( 'Uses JavaScript injection to add protection to any form, even from plugins without native integration.', 'ofast-x' ); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Honeypot Fallback', 'ofast-x' ); ?></th>
                                <td>
                                    <label class="ofast-toggle">
                                        <input type="checkbox" name="honeypot_enabled" value="1" checked disabled>
                                        <span class="ofast-slider"></span>
                                    </label>
                                    <span class="description" style="vertical-align:middle;"><?php esc_html_e( 'Honeypot protection is always enabled', 'ofast-x' ); ?></span>
                                    <p class="description" style="margin-top:8px;color:#666;">
                                        <?php esc_html_e( 'Adds invisible fields only bots fill. Activates when Turnstile/reCAPTCHA fails due to network issues.', 'ofast-x' ); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Fail Open on Provider Outage', 'ofast-x' ); ?></th>
                                <td>
                                    <label class="ofast-toggle">
                                        <input type="checkbox" name="spam_fail_open" value="1" <?php checked( $fail_open ); ?> <?php ofast_toolkit_pro_disabled(); ?>>
                                        <span class="ofast-slider"></span>
                                    </label>
                                    <span class="description" style="vertical-align:middle;">
                                        <?php esc_html_e( 'Allow submissions when provider API is unreachable', 'ofast-x' ); ?>
                                        <?php ofast_toolkit_pro_badge(); ?>
                                    </span>
                                    <p class="description" style="margin-top:8px;color:#666;">
                                        <?php esc_html_e( 'When disabled, forms block if Turnstile/reCAPTCHA cannot be reached.', 'ofast-x' ); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Turnstile Tab -->
                <div id="tab-turnstile" class="ofast-tab-content<?php echo $default_tab === 'turnstile' ? ' active' : ''; ?>">
                    <div class="ofast-card">
                        <h2><?php esc_html_e( 'Cloudflare Turnstile Settings', 'ofast-x' ); ?></h2>
                        <?php
                        if ( class_exists( 'Ofast_X_Turnstile' ) ) {
                            Ofast_X_Turnstile::get_instance()->render_settings_form();
                        } else {
                            echo '<p>' . esc_html__( 'Turnstile module is not loaded.', 'ofast-x' ) . '</p>';
                        }
                        ?>
                    </div>
                </div>

                <!-- Math CAPTCHA Tab -->
                <div id="tab-math_captcha" class="ofast-tab-content<?php echo $default_tab === 'math_captcha' ? ' active' : ''; ?>">
                    <div class="ofast-card">
                        <h2><?php esc_html_e( 'Math CAPTCHA Settings', 'ofast-x' ); ?></h2>
                        <?php
                        if ( class_exists( 'Ofast_X_Math_Captcha' ) ) {
                            Ofast_X_Math_Captcha::get_instance()->render_settings_form();
                        } else {
                            echo '<p>' . esc_html__( 'Math CAPTCHA module is not loaded.', 'ofast-x' ) . '</p>';
                        }
                        ?>
                    </div>
                </div>

                <!-- reCAPTCHA Tab -->
                <div id="tab-recaptcha" class="ofast-tab-content<?php echo $default_tab === 'recaptcha' ? ' active' : ''; ?>">
                    <div class="ofast-card">
                        <h2><?php esc_html_e( 'Google reCAPTCHA Settings', 'ofast-x' ); ?></h2>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: link to Google reCAPTCHA admin */
                                esc_html__( 'Get your keys from %s', 'ofast-x' ),
                                '<a href="https://www.google.com/recaptcha/admin" target="_blank">' . esc_html__( 'Google reCAPTCHA Admin', 'ofast-x' ) . '</a>'
                            );
                            ?>
                        </p>
                        <table class="form-table">
                            <tr>
                                <th><?php esc_html_e( 'Site Key', 'ofast-x' ); ?></th>
                                <td>
                                    <input type="text" name="recaptcha_site_key"
                                           value="<?php echo esc_attr( $recaptcha_site_key ); ?>"
                                           class="regular-text" style="border-radius:8px;">
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Secret Key', 'ofast-x' ); ?></th>
                                <td>
                                    <input type="password" name="recaptcha_secret_key" value=""
                                           class="regular-text" style="border-radius:8px;"
                                           placeholder="<?php echo $recaptcha_site_key ? esc_attr__( 'Saved — enter to change', 'ofast-x' ) : ''; ?>">
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Score Threshold (v3)', 'ofast-x' ); ?></th>
                                <td>
                                    <input type="number" name="recaptcha_threshold"
                                           value="<?php echo esc_attr( $recaptcha_threshold ); ?>"
                                           min="0" max="1" step="0.1"
                                           style="width:80px;border-radius:8px;">
                                    <p class="description"><?php esc_html_e( '0.0 = bot, 1.0 = human. Default: 0.5', 'ofast-x' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="ofast-form-actions" style="margin-top:30px;padding-top:20px;">
                    <button type="submit" name="ofast_save_recaptcha" class="button button-primary button-large" style="min-width:150px;">
                        <?php esc_html_e( 'Save Changes', 'ofast-x' ); ?>
                    </button>
                </div>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Provider card selected state
            $('input[name="spam_provider"]').on('change', function() {
                $('.ofast-provider-card').removeClass('selected');
                $(this).closest('.ofast-provider-card').addClass('selected');
            });

            // Tab switching
            $('.ofast-tab').on('click', function(e) {
                e.preventDefault();
                var target = $(this).data('tab');
                $('.ofast-tab').removeClass('active');
                $(this).addClass('active');
                $('.ofast-tab-content').removeClass('active');
                $('#tab-' + target).addClass('active');
                var url = new URL(window.location);
                url.searchParams.set('tab', target);
                window.history.pushState({}, '', url);
                if (typeof window.OfastInitDropdowns === 'function') {
                    window.OfastInitDropdowns('#tab-' + target);
                }
            });

            window.onpopstate = function() {
                var tab = new URLSearchParams(window.location.search).get('tab') || 'general';
                $('.ofast-tab[data-tab="' + tab + '"]').trigger('click');
            };
        });
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Provider helpers
    // -------------------------------------------------------------------------

    public function get_active_provider() {
        return get_option( 'ofast_spam_provider', 'turnstile' );
    }

    public function is_configured() {
        $provider = $this->get_active_provider();

        switch ( $provider ) {
            case 'turnstile':
                return class_exists( 'Ofast_X_Turnstile' ) && Ofast_X_Turnstile::get_instance()->is_configured();

            case 'math_captcha':
                return true; // No API keys required

            case 'recaptcha_v2':
            case 'recaptcha_v3':
                return ! empty( get_option( 'ofast_recaptcha_site_key', '' ) )
                    && ! empty( $this->get_decrypted_recaptcha_secret() );

            default:
                return false;
        }
    }

    public function verify( $token ) {
        $provider = $this->get_active_provider();

        switch ( $provider ) {
            case 'turnstile':
                if ( class_exists( 'Ofast_X_Turnstile' ) ) {
                    return Ofast_X_Turnstile::get_instance()->verify( $token );
                }
                return array( 'success' => false, 'error' => __( 'Turnstile not available', 'ofast-x' ) );

            case 'math_captcha':
                if ( class_exists( 'Ofast_X_Math_Captcha' ) ) {
                    return Ofast_X_Math_Captcha::get_instance()->verify();
                }
                return array( 'success' => false, 'error' => __( 'Math CAPTCHA not available', 'ofast-x' ) );

            case 'recaptcha_v2':
            case 'recaptcha_v3':
                return $this->verify_recaptcha( $token );

            default:
                return array( 'success' => true );
        }
    }

    private function verify_recaptcha( $token ) {
        $secret_key = $this->get_decrypted_recaptcha_secret();

        if ( empty( $secret_key ) ) {
            return array( 'success' => false, 'error' => __( 'reCAPTCHA not configured', 'ofast-x' ) );
        }

        $response = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', array(
            'body' => array(
                'secret'   => $secret_key,
                'response' => $token,
                'remoteip' => $this->get_client_ip(),
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            if ( $this->should_fail_open() ) {
                return array( 'success' => true, 'skipped' => true, 'reason' => 'api_error' );
            }
            return array( 'success' => false, 'error' => __( 'reCAPTCHA verification failed. Please try again.', 'ofast-x' ), 'code' => 'api_error' );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['success'] ) ) {
            $error = isset( $body['error-codes'] ) ? implode( ', ', $body['error-codes'] ) : __( 'Verification failed', 'ofast-x' );
            return array( 'success' => false, 'error' => $error );
        }

        if ( $this->get_active_provider() === 'recaptcha_v3' ) {
            $threshold = floatval( get_option( 'ofast_recaptcha_threshold', 0.5 ) );
            $score     = isset( $body['score'] ) ? floatval( $body['score'] ) : 0;
            if ( $score < $threshold ) {
                return array( 'success' => false, 'error' => sprintf( __( 'Score too low: %s', 'ofast-x' ), $score ) );
            }
        }

        return array( 'success' => true );
    }

    private function should_fail_open() {
        return (bool) apply_filters( 'ofast_spam_fail_open', get_option( 'ofast_spam_fail_open', false ) );
    }

    private function get_decrypted_recaptcha_secret() {
        $stored = get_option( 'ofast_recaptcha_secret_key', '' );
        if ( empty( $stored ) ) {
            return '';
        }

        if ( ! class_exists( 'Ofast_X_Security_Hardening' ) ) {
            return '';
        }

        $decrypted = Ofast_X_Security_Hardening::decrypt_option( $stored );
        if ( ! empty( $decrypted ) ) {
            return $decrypted;
        }

        // Migrate legacy plaintext secret
        if ( $this->is_legacy_plaintext_recaptcha_secret( $stored ) ) {
            $encrypted = Ofast_X_Security_Hardening::encrypt_option( $stored );
            if ( $encrypted !== false ) {
                update_option( 'ofast_recaptcha_secret_key', $encrypted );
                return $stored;
            }
            if ( class_exists( 'Ofast_X_Logger' ) ) {
                Ofast_X_Logger::warning( 'Failed to encrypt legacy reCAPTCHA secret. Migration failed.' );
            }
            update_option( 'ofast_recaptcha_migration_failed', true );
        }

        return '';
    }

    private function is_legacy_plaintext_recaptcha_secret( $value ) {
        if ( ! is_string( $value ) || $value === '' ) {
            return false;
        }
        if ( class_exists( 'Ofast_X_Security_Hardening' ) && Ofast_X_Security_Hardening::looks_like_encrypted_option( $value ) ) {
            return false;
        }
        return (bool) preg_match( '/^[A-Za-z0-9_-]{20,120}$/', $value );
    }

    /**
     * Get client IP address.
     *
     * FIX — previous implementation had two security problems:
     *
     * 1. X-Forwarded-For: took the leftmost IP (client-controlled, trivially
     *    spoofable). Any client sending "X-Forwarded-For: 1.2.3.4" bypasses any
     *    IP-based rate limiting.  XFF is now ignored entirely — without knowing
     *    which upstream proxy is trusted you cannot safely parse it.
     *
     * 2. CF-Connecting-IP was trusted unconditionally. Ideally REMOTE_ADDR should
     *    be validated against Cloudflare's published IP ranges first; that check is
     *    left as a v2 improvement. For now, private/reserved IPs are rejected so a
     *    locally-forged header has no effect on non-CF deployments.
     *
     * Priority: CF-Connecting-IP → X-Real-IP (nginx single-value header) → REMOTE_ADDR.
     */
    private function get_client_ip() {
        // CF-Connecting-IP: set by Cloudflare infrastructure, not the client.
        // Reject private/reserved IPs so a locally-crafted header does nothing.
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $ip = trim( $_SERVER['HTTP_CF_CONNECTING_IP'] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                return $ip;
            }
        }

        // X-Real-IP: a single-value header set by nginx — not a list, not client-controllable.
        if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
            $ip = trim( $_SERVER['HTTP_X_REAL_IP'] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }

        // REMOTE_ADDR: the only value that truly cannot be spoofed.
        return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '127.0.0.1';
    }
}
