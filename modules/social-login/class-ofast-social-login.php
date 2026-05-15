<?php

/**
 * Ofast X - Social Login Module (Pro)
 * Allows users to log in via Google and Facebook OAuth.
 *
 * @since 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Facebook API version — filterable so deprecation doesn't require a code push
// ---------------------------------------------------------------------------
if ( ! defined( 'OFAST_FACEBOOK_API_VERSION' ) ) {
    define( 'OFAST_FACEBOOK_API_VERSION', apply_filters( 'ofast_facebook_api_version', 'v20.0' ) );
}

class Ofast_X_Social_Login {

    private static $instance = null;

    // FIX: API URLs now use the filterable constant instead of hardcoded version strings.
    // Old code: const FB_AUTH_URL = 'https://www.facebook.com/v18.0/dialog/oauth';
    // v18.0 has a known sunset. Now resolved at runtime from the constant above.
    private function get_fb_auth_url()  { return 'https://www.facebook.com/' . OFAST_FACEBOOK_API_VERSION . '/dialog/oauth'; }
    private function get_fb_token_url() { return 'https://graph.facebook.com/' . OFAST_FACEBOOK_API_VERSION . '/oauth/access_token'; }
    private function get_fb_user_url()  { return 'https://graph.facebook.com/' . OFAST_FACEBOOK_API_VERSION . '/me'; }

    const GOOGLE_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const GOOGLE_USER_URL  = 'https://www.googleapis.com/oauth2/v3/userinfo';
    const STATE_EXPIRY     = 600; // 10 minutes

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
        // Pro gate
        if ( ! function_exists( 'ofast_toolkit_is_pro' ) || ! ofast_toolkit_is_pro() ) {
            return;
        }

        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

        if ( ! $this->is_enabled() ) {
            return;
        }

        // FIX: Callback now checks is_enabled() as its first action.
        // Previously the callback was always registered and processed even
        // when the module was disabled mid-session.
        add_action( 'init', array( $this, 'handle_oauth_callback' ) );

        // Login page buttons
        add_action( 'login_form',           array( $this, 'render_login_buttons' ) );
        add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login_styles' ) );

        // FIX: Lazy state endpoint — state transient is now created only when
        // the user clicks the social login button (via AJAX), not on every
        // login page render. Old code wrote a transient on every page load
        // (login page, checkout, registration) — 2 transient writes per provider
        // per page view on every concurrent visitor.
        add_action( 'wp_ajax_nopriv_ofast_social_get_auth_url', array( $this, 'ajax_get_auth_url' ) );
        add_action( 'wp_ajax_ofast_social_get_auth_url',        array( $this, 'ajax_get_auth_url' ) );

        // FIX: Wire up stored avatar to actually serve it via get_avatar_url filter.
        // Previously the avatar was stored on every login but never read anywhere.
        add_filter( 'get_avatar_url', array( $this, 'filter_avatar_url' ), 10, 3 );
    }

    // -------------------------------------------------------------------------
    // Enabled check
    // -------------------------------------------------------------------------

    public function is_enabled() {
        return (bool) get_option( 'ofast_social_login_enabled', false );
    }

    // -------------------------------------------------------------------------
    // Admin menu
    // -------------------------------------------------------------------------

    public function add_admin_menu() {
        add_submenu_page(
            'ofast-dashboard',
            __( 'Social Login', 'ofast-x' ),
            __( 'Social Login', 'ofast-x' ),
            'manage_options',
            'ofast-social-login',
            array( $this, 'render_settings_page' )
        );
    }

    // -------------------------------------------------------------------------
    // Settings page
    // -------------------------------------------------------------------------

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied', 'ofast-x' ) );
        }

        if ( isset( $_POST['ofast_save_social_login'] ) ) {
            check_admin_referer( 'ofast_social_login_save', 'ofast_social_nonce' );
            $this->handle_settings_save( wp_unslash( $_POST ) );
            wp_safe_redirect( add_query_arg( 'settings_saved', '1', wp_get_referer() ) );
            exit;
        }

        if ( isset( $_GET['settings_saved'] ) ) {
            echo Ofast_X_Toast::render( __( 'Settings saved successfully!', 'ofast-x' ), 'success' );
        }

        $google   = $this->get_provider_settings( 'google' );
        $facebook = $this->get_provider_settings( 'facebook' );
        $enabled  = $this->is_enabled();

        // Callback URL shown in settings — read-only reference for OAuth app config
        $callback_url = admin_url( 'admin-ajax.php' ) . '?ofast_social_callback=1';
        ?>
        <div class="wrap">
            <div class="ofast-header" style="display:flex;align-items:center;gap:20px;background:#fff;padding:25px 30px;border-radius:12px;box-shadow:0 4px 6px -1px rgba(0,0,0,.05);margin:20px 0 30px;">
                <div style="width:56px;height:56px;background:#fff;border:1px solid #e2e8f0;border-radius:16px;display:flex;align-items:center;justify-content:center;">
                    <span class="dashicons dashicons-share" style="font-size:28px;width:28px;height:28px;color:#6366f1;"></span>
                </div>
                <div>
                    <h1 style="margin:0 0 5px;font-size:24px;font-weight:700;color:#1e293b;display:block;padding:0;">
                        <?php esc_html_e( 'Social Login', 'ofast-x' ); ?>
                        <?php ofast_toolkit_pro_badge(); ?>
                    </h1>
                    <p style="margin:0;color:#64748b;font-size:14px;">
                        <?php esc_html_e( 'Allow users to log in via Google and Facebook.', 'ofast-x' ); ?>
                    </p>
                </div>
            </div>

            <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:14px 18px;margin-bottom:24px;font-size:13px;color:#92400e;">
                <strong><?php esc_html_e( 'V2 Module:', 'ofast-x' ); ?></strong>
                <?php esc_html_e( 'Social Login architecture is established. Full OAuth flow is scheduled for v2 after reference implementation study. Do not enable on live production sites before v2 review.', 'ofast-x' ); ?>
            </div>

            <form method="post">
                <?php wp_nonce_field( 'ofast_social_login_save', 'ofast_social_nonce' ); ?>
                <style>
                    .ofast-social-card { background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 6px -1px rgba(0,0,0,.05); border:1px solid #e2e8f0; margin-bottom:24px; }
                    .ofast-social-card h2 { margin-top:0; font-size:18px; color:#1e293b; border-bottom:1px solid #f1f5f9; padding-bottom:15px; }
                    .ofast-toggle { position:relative; display:inline-block; width:50px; height:26px; vertical-align:middle; }
                    .ofast-toggle input { opacity:0; width:0; height:0; }
                    .ofast-toggle-slider { position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background:#cbd5e1; transition:.3s; border-radius:26px; }
                    .ofast-toggle-slider:before { position:absolute; content:""; height:20px; width:20px; left:3px; bottom:3px; background:#fff; transition:.3s; border-radius:50%; }
                    input:checked + .ofast-toggle-slider { background:#6366f1; }
                    input:checked + .ofast-toggle-slider:before { transform:translateX(24px); }
                    .ofast-callback-url { background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:8px 12px; font-family:monospace; font-size:12px; color:#475569; display:block; margin-top:8px; word-break:break-all; }
                    .form-table th { width:180px; }
                    .form-table input[type=text], .form-table input[type=password], .form-table input[type=url], .form-table select { border-radius:6px; }
                </style>

                <!-- General -->
                <div class="ofast-social-card">
                    <h2><?php esc_html_e( 'General', 'ofast-x' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Enable Social Login', 'ofast-x' ); ?></th>
                            <td>
                                <label class="ofast-toggle">
                                    <input type="checkbox" name="social_login_enabled" value="1" <?php checked( $enabled ); ?>>
                                    <span class="ofast-toggle-slider"></span>
                                </label>
                                <span style="margin-left:12px;vertical-align:middle;color:#64748b;font-size:13px;">
                                    <?php esc_html_e( 'Show social login buttons on the login page', 'ofast-x' ); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Default Role', 'ofast-x' ); ?></th>
                            <td>
                                <?php
                                // FIX: wp_dropdown_roles() outputs a complete <select name="role"> element.
                                // Old code wrapped it in <select name="default_role"> creating an invalid
                                // nested select — the browser ignores the inner options. $_POST['default_role']
                                // was always empty so the role never saved correctly.
                                // Fix: build our own <select> looping get_editable_roles() directly.
                                $saved_role     = get_option( 'ofast_social_default_role', 'subscriber' );
                                $editable_roles = get_editable_roles();
                                ?>
                                <select name="default_role" style="min-width:200px;">
                                    <?php foreach ( $editable_roles as $role_key => $role_data ) : ?>
                                        <option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( $saved_role, $role_key ); ?>>
                                            <?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Role assigned to new users who register via social login.', 'ofast-x' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Button Style', 'ofast-x' ); ?></th>
                            <td>
                                <select name="btn_style" style="min-width:200px;">
                                    <?php
                                    $saved_style = get_option( 'ofast_social_btn_style', 'icon_text' );
                                    $styles = array(
                                        'icon_text' => __( 'Icon + Text', 'ofast-x' ),
                                        'icon_only' => __( 'Icon Only', 'ofast-x' ),
                                        'text_only' => __( 'Text Only', 'ofast-x' ),
                                    );
                                    foreach ( $styles as $val => $label ) : ?>
                                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $saved_style, $val ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'OAuth Callback URL', 'ofast-x' ); ?></th>
                            <td>
                                <p class="description"><?php esc_html_e( 'Add this URL to your OAuth app\'s authorised redirect URIs:', 'ofast-x' ); ?></p>
                                <code class="ofast-callback-url"><?php echo esc_url( $callback_url ); ?></code>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Google -->
                <div class="ofast-social-card">
                    <h2>
                        <span style="vertical-align:middle;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:8px;"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                        </span>
                        <?php esc_html_e( 'Google OAuth', 'ofast-x' ); ?>
                    </h2>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Enable Google', 'ofast-x' ); ?></th>
                            <td>
                                <label class="ofast-toggle">
                                    <input type="checkbox" name="google_enabled" value="1" <?php checked( ! empty( $google['enabled'] ) ); ?>>
                                    <span class="ofast-toggle-slider"></span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Client ID', 'ofast-x' ); ?></th>
                            <td>
                                <input type="text" name="google_client_id"
                                       value="<?php echo esc_attr( $google['client_id'] ); ?>"
                                       class="regular-text"
                                       placeholder="<?php esc_attr_e( 'Paste your Google Client ID', 'ofast-x' ); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Client Secret', 'ofast-x' ); ?></th>
                            <td>
                                <?php
                                // FIX: Old code decrypted the secret and set it as the input value.
                                // This put the live secret into the DOM — any admin-context XSS
                                // would capture it. Now we render an empty password input with a
                                // placeholder indicating the secret is saved. The value is only
                                // updated when the admin submits a non-empty value.
                                $has_google_secret = ! empty( $google['client_secret'] );
                                ?>
                                <input type="password" name="google_client_secret" value=""
                                       class="regular-text"
                                       placeholder="<?php echo $has_google_secret ? esc_attr__( 'Secret saved — enter new value to change', 'ofast-x' ) : esc_attr__( 'Paste your Google Client Secret', 'ofast-x' ); ?>">
                                <?php if ( $has_google_secret ) : ?>
                                    <p class="description" style="color:#10b981;">
                                        <span class="dashicons dashicons-yes-alt" style="vertical-align:middle;font-size:14px;width:14px;height:14px;"></span>
                                        <?php esc_html_e( 'Secret is saved and encrypted. Leave blank to keep the current value.', 'ofast-x' ); ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Facebook -->
                <div class="ofast-social-card">
                    <h2>
                        <span style="vertical-align:middle;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:8px;"><path fill="#1877F2" d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.235 2.686.235v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.254h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
                        </span>
                        <?php esc_html_e( 'Facebook OAuth', 'ofast-x' ); ?>
                    </h2>
                    <p class="description" style="color:#92400e;background:#fffbeb;border:1px solid #fcd34d;padding:10px 14px;border-radius:6px;margin-bottom:20px;">
                        <?php
                        printf(
                            /* translators: %s: Facebook API version */
                            esc_html__( 'Using Facebook API %s. To change version add: add_filter( "ofast_facebook_api_version", function() { return "v21.0"; });', 'ofast-x' ),
                            esc_html( OFAST_FACEBOOK_API_VERSION )
                        );
                        ?>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Enable Facebook', 'ofast-x' ); ?></th>
                            <td>
                                <label class="ofast-toggle">
                                    <input type="checkbox" name="facebook_enabled" value="1" <?php checked( ! empty( $facebook['enabled'] ) ); ?>>
                                    <span class="ofast-toggle-slider"></span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'App ID', 'ofast-x' ); ?></th>
                            <td>
                                <input type="text" name="facebook_client_id"
                                       value="<?php echo esc_attr( $facebook['client_id'] ); ?>"
                                       class="regular-text"
                                       placeholder="<?php esc_attr_e( 'Paste your Facebook App ID', 'ofast-x' ); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'App Secret', 'ofast-x' ); ?></th>
                            <td>
                                <?php
                                // FIX: Same DOM exposure fix as Google — placeholder instead of decrypted value.
                                $has_fb_secret = ! empty( $facebook['client_secret'] );
                                ?>
                                <input type="password" name="facebook_client_secret" value=""
                                       class="regular-text"
                                       placeholder="<?php echo $has_fb_secret ? esc_attr__( 'Secret saved — enter new value to change', 'ofast-x' ) : esc_attr__( 'Paste your Facebook App Secret', 'ofast-x' ); ?>">
                                <?php if ( $has_fb_secret ) : ?>
                                    <p class="description" style="color:#10b981;">
                                        <span class="dashicons dashicons-yes-alt" style="vertical-align:middle;font-size:14px;width:14px;height:14px;"></span>
                                        <?php esc_html_e( 'Secret is saved and encrypted. Leave blank to keep the current value.', 'ofast-x' ); ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <p>
                    <button type="submit" name="ofast_save_social_login" class="button button-primary button-large" style="padding:10px 30px;">
                        <?php esc_html_e( 'Save Settings', 'ofast-x' ); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Settings save
    // -------------------------------------------------------------------------

    private function handle_settings_save( $post ) {
        update_option( 'ofast_social_login_enabled', isset( $post['social_login_enabled'] ) );
        update_option( 'ofast_social_btn_style',     sanitize_key( $post['btn_style'] ?? 'icon_text' ) );

        // FIX: Validate role against editable roles — don't trust raw POST value
        $editable_roles = get_editable_roles();
        $posted_role    = sanitize_key( $post['default_role'] ?? 'subscriber' );
        $default_role   = array_key_exists( $posted_role, $editable_roles ) ? $posted_role : 'subscriber';
        update_option( 'ofast_social_default_role', $default_role );

        // Google
        update_option( 'ofast_social_google_enabled', isset( $post['google_enabled'] ) );
        if ( ! empty( $post['google_client_id'] ) ) {
            update_option( 'ofast_social_google_client_id', sanitize_text_field( $post['google_client_id'] ) );
        }
        $this->save_provider_secret( 'google', $post['google_client_secret'] ?? '' );

        // Facebook
        update_option( 'ofast_social_facebook_enabled', isset( $post['facebook_enabled'] ) );
        if ( ! empty( $post['facebook_client_id'] ) ) {
            update_option( 'ofast_social_facebook_client_id', sanitize_text_field( $post['facebook_client_id'] ) );
        }
        $this->save_provider_secret( 'facebook', $post['facebook_client_secret'] ?? '' );
    }

    private function save_provider_secret( $provider, $raw_secret ) {
        $raw_secret = sanitize_text_field( $raw_secret );

        // FIX: Only update when a non-empty value is submitted.
        // Old code always called update_option even with '', wiping the stored secret.
        if ( $raw_secret === '' ) {
            return;
        }

        if ( ! class_exists( 'Ofast_X_Security_Hardening' ) ) {
            if ( class_exists( 'Ofast_X_Logger' ) ) {
                Ofast_X_Logger::error( sprintf( 'Cannot encrypt %s secret: Ofast_X_Security_Hardening not available.', $provider ) );
            }
            return;
        }

        $encrypted = Ofast_X_Security_Hardening::encrypt_option( $raw_secret );
        if ( $encrypted !== false ) {
            update_option( 'ofast_social_' . $provider . '_client_secret', $encrypted );
        } else {
            if ( class_exists( 'Ofast_X_Logger' ) ) {
                Ofast_X_Logger::error( sprintf( 'Failed to encrypt %s client secret.', $provider ) );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Provider settings reader
    // -------------------------------------------------------------------------

    private function get_provider_settings( $provider ) {
        return array(
            'enabled'       => get_option( 'ofast_social_' . $provider . '_enabled', false ),
            'client_id'     => get_option( 'ofast_social_' . $provider . '_client_id', '' ),
            // FIX: client_secret is NOT decrypted here for rendering.
            // We only return a boolean indicating whether a secret is stored.
            // Decryption happens only in get_provider_secret() which is called
            // exclusively during the OAuth token exchange, never for display.
            'client_secret' => $this->provider_has_secret( $provider ),
        );
    }

    /**
     * Returns true if an encrypted secret is stored for this provider.
     * Never returns the actual secret value — use get_provider_secret() for that.
     */
    private function provider_has_secret( $provider ) {
        return ! empty( get_option( 'ofast_social_' . $provider . '_client_secret', '' ) );
    }

    /**
     * Decrypt and return the provider secret.
     * Only called during OAuth token exchange — never for UI rendering.
     */
    private function get_provider_secret( $provider ) {
        $stored = get_option( 'ofast_social_' . $provider . '_client_secret', '' );
        if ( empty( $stored ) || ! class_exists( 'Ofast_X_Security_Hardening' ) ) {
            return '';
        }
        return Ofast_X_Security_Hardening::decrypt_option( $stored ) ?: '';
    }

    // -------------------------------------------------------------------------
    // Login page buttons
    // -------------------------------------------------------------------------

    public function enqueue_login_styles() {
        ?>
        <style>
            .ofast-social-login-wrap { margin: 20px 0; text-align: center; }
            .ofast-social-divider { position: relative; text-align: center; margin: 20px 0; }
            .ofast-social-divider::before { content: ''; position: absolute; top: 50%; left: 0; right: 0; height: 1px; background: #e2e8f0; }
            .ofast-social-divider span { position: relative; background: #fff; padding: 0 12px; color: #94a3b8; font-size: 12px; }
            .ofast-social-btn { display: inline-flex; align-items: center; justify-content: center; gap: 10px; padding: 10px 20px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; color: #1e293b; font-size: 14px; font-weight: 500; text-decoration: none; cursor: pointer; transition: all .2s; width: 100%; box-sizing: border-box; margin-bottom: 10px; }
            .ofast-social-btn:hover { background: #f8fafc; border-color: #6366f1; color: #1e293b; }
            .ofast-social-btn:focus { outline: 2px solid #6366f1; outline-offset: 2px; }
            .ofast-social-btn svg { flex-shrink: 0; }
            .ofast-social-btn-loading { opacity: 0.7; pointer-events: none; }
        </style>
        <?php
    }

    public function render_login_buttons() {
        $google_enabled   = get_option( 'ofast_social_google_enabled', false );
        $facebook_enabled = get_option( 'ofast_social_facebook_enabled', false );

        if ( ! $google_enabled && ! $facebook_enabled ) {
            return;
        }

        $btn_style = get_option( 'ofast_social_btn_style', 'icon_text' );
        ?>
        <div class="ofast-social-login-wrap">
            <div class="ofast-social-divider">
                <span><?php esc_html_e( 'or continue with', 'ofast-x' ); ?></span>
            </div>

            <?php if ( $google_enabled ) : ?>
                <?php
                // FIX: Auth URL is now generated via AJAX on click — not rendered into the page HTML.
                // Old code called get_google_auth_url() here which wrote a transient on every page load.
                // This button triggers the AJAX endpoint that generates the URL and redirects.
                ?>
                <button type="button" class="ofast-social-btn ofast-social-btn-google"
                        data-provider="google"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'ofast_social_auth' ) ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"/>
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                    </svg>
                    <?php if ( $btn_style !== 'icon_only' ) : ?>
                        <?php esc_html_e( 'Continue with Google', 'ofast-x' ); ?>
                    <?php endif; ?>
                </button>
            <?php endif; ?>

            <?php if ( $facebook_enabled ) : ?>
                <button type="button" class="ofast-social-btn ofast-social-btn-facebook"
                        data-provider="facebook"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'ofast_social_auth' ) ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
                        <path fill="#1877F2" d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.235 2.686.235v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.254h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                    </svg>
                    <?php if ( $btn_style !== 'icon_only' ) : ?>
                        <?php esc_html_e( 'Continue with Facebook', 'ofast-x' ); ?>
                    <?php endif; ?>
                </button>
            <?php endif; ?>
        </div>

        <script>
        (function() {
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

            document.querySelectorAll('.ofast-social-btn[data-provider]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var provider = btn.getAttribute('data-provider');
                    var nonce    = btn.getAttribute('data-nonce');

                    btn.classList.add('ofast-social-btn-loading');
                    btn.setAttribute('aria-busy', 'true');

                    var fd = new FormData();
                    fd.append('action',   'ofast_social_get_auth_url');
                    fd.append('provider', provider);
                    fd.append('nonce',    nonce);
                    fd.append('redirect', window.location.href);

                    fetch(ajaxUrl, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.success && data.data.url) {
                                window.location.href = data.data.url;
                            } else {
                                alert(data.data.message || <?php echo wp_json_encode( __( 'Social login is not configured. Please contact the site admin.', 'ofast-x' ) ); ?>);
                                btn.classList.remove('ofast-social-btn-loading');
                                btn.removeAttribute('aria-busy');
                            }
                        })
                        .catch(function() {
                            btn.classList.remove('ofast-social-btn-loading');
                            btn.removeAttribute('aria-busy');
                        });
                });
            });
        })();
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX: Generate auth URL on demand (lazy state creation)
    // -------------------------------------------------------------------------

    /**
     * FIX: State transient now created here, only when user clicks the button.
     * Old code called get_google_auth_url() / get_facebook_auth_url() during
     * render_login_buttons() which fired on every login page render.
     * On a high-traffic site (500 concurrent users on /checkout) this generated
     * 1,000 transient rows per minute that lived for 10 minutes each.
     */
    public function ajax_get_auth_url() {
        if ( ! check_ajax_referer( 'ofast_social_auth', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'ofast-x' ) ) );
            return;
        }

        $provider = sanitize_key( $_POST['provider'] ?? '' );

        if ( ! in_array( $provider, array( 'google', 'facebook' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid provider.', 'ofast-x' ) ) );
            return;
        }

        if ( ! get_option( 'ofast_social_' . $provider . '_enabled', false ) ) {
            wp_send_json_error( array( 'message' => __( 'This social login provider is not enabled.', 'ofast-x' ) ) );
            return;
        }

        // FIX: Validate redirect URL is on the same host before storing in state.
        // Old code stored $_SERVER['HTTP_REFERER'] directly — an attacker who
        // could control the referer header (or craft a link that sets it) could
        // redirect users to an external site after login.
        $raw_redirect = wp_unslash( $_POST['redirect'] ?? '' );
        $redirect     = $this->sanitize_redirect( $raw_redirect );

        $url = ( $provider === 'google' )
            ? $this->build_google_auth_url( $redirect )
            : $this->build_facebook_auth_url( $redirect );

        if ( is_wp_error( $url ) ) {
            wp_send_json_error( array( 'message' => $url->get_error_message() ) );
            return;
        }

        wp_send_json_success( array( 'url' => $url ) );
    }

    private function sanitize_redirect( $url ) {
        $url = esc_url_raw( $url );
        if ( empty( $url ) ) {
            return admin_url();
        }
        // FIX: Reject any redirect URL that points to a different host.
        $site_host = parse_url( home_url(), PHP_URL_HOST );
        $url_host  = parse_url( $url, PHP_URL_HOST );
        if ( $url_host && $url_host !== $site_host ) {
            return admin_url();
        }
        return $url;
    }

    // -------------------------------------------------------------------------
    // Auth URL builders
    // -------------------------------------------------------------------------

    private function build_google_auth_url( $redirect = '' ) {
        $settings = get_option( 'ofast_social_google_client_id', '' );

        if ( empty( $settings ) ) {
            return new WP_Error( 'not_configured', __( 'Google OAuth is not configured. Please add your Client ID in Settings → Social Login.', 'ofast-x' ) );
        }

        $state    = $this->generate_state( 'google', $redirect );
        $callback = admin_url( 'admin-ajax.php' ) . '?ofast_social_callback=1';

        return add_query_arg( array(
            'client_id'     => $settings,
            'redirect_uri'  => $callback,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
        ), self::GOOGLE_AUTH_URL );
    }

    private function build_facebook_auth_url( $redirect = '' ) {
        $client_id = get_option( 'ofast_social_facebook_client_id', '' );

        if ( empty( $client_id ) ) {
            return new WP_Error( 'not_configured', __( 'Facebook OAuth is not configured. Please add your App ID in Settings → Social Login.', 'ofast-x' ) );
        }

        $state    = $this->generate_state( 'facebook', $redirect );
        $callback = admin_url( 'admin-ajax.php' ) . '?ofast_social_callback=1';

        return add_query_arg( array(
            'client_id'     => $client_id,
            'redirect_uri'  => $callback,
            'response_type' => 'code',
            'scope'         => 'email,public_profile',
            'state'         => $state,
        ), $this->get_fb_auth_url() );
    }

    private function generate_state( $provider, $redirect = '' ) {
        $state = wp_generate_password( 32, false );
        $data  = array(
            'provider' => $provider,
            'redirect' => $redirect ?: admin_url(),
            'created'  => time(),
        );
        set_transient( 'ofast_social_state_' . $state, $data, self::STATE_EXPIRY );
        return $state;
    }

    // -------------------------------------------------------------------------
    // OAuth callback handler
    // -------------------------------------------------------------------------

    public function handle_oauth_callback() {
        // FIX: Module-enabled check is the first thing — old code processed
        // the callback even when the module was disabled.
        if ( ! $this->is_enabled() ) {
            return;
        }

        if ( empty( $_GET['ofast_social_callback'] ) ) {
            return;
        }

        $code  = sanitize_text_field( wp_unslash( $_GET['code']  ?? '' ) );
        $state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
        $error = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) );

        if ( ! empty( $error ) ) {
            // FIX: Fire wp_login_failed so lockout plugins see provider-rejected logins.
            do_action( 'wp_login_failed', '', new WP_Error( 'social_provider_error', $error ) );
            $this->redirect_with_error( $error );
            return;
        }

        if ( empty( $code ) || empty( $state ) ) {
            $this->redirect_with_error( __( 'Invalid callback — missing code or state.', 'ofast-x' ) );
            return;
        }

        // Validate state
        $state_data = get_transient( 'ofast_social_state_' . $state );
        if ( ! $state_data || ! is_array( $state_data ) ) {
            // FIX: Fire wp_login_failed on state mismatch — potential CSRF attempt.
            do_action( 'wp_login_failed', '', new WP_Error( 'state_mismatch', 'State mismatch' ) );
            $this->redirect_with_error( __( 'Session expired or state mismatch. Please try again.', 'ofast-x' ) );
            return;
        }

        // Delete immediately — state tokens are single-use
        delete_transient( 'ofast_social_state_' . $state );

        $provider = sanitize_key( $state_data['provider'] ?? '' );
        $redirect = $this->sanitize_redirect( $state_data['redirect'] ?? '' );

        if ( $provider === 'google' ) {
            $this->process_google_callback( $code, $redirect );
        } elseif ( $provider === 'facebook' ) {
            $this->process_facebook_callback( $code, $redirect );
        } else {
            $this->redirect_with_error( __( 'Unknown OAuth provider.', 'ofast-x' ) );
        }
    }

    // -------------------------------------------------------------------------
    // Google callback
    // -------------------------------------------------------------------------

    private function process_google_callback( $code, $redirect ) {
        $client_id     = get_option( 'ofast_social_google_client_id', '' );
        $client_secret = $this->get_provider_secret( 'google' );
        $callback_url  = admin_url( 'admin-ajax.php' ) . '?ofast_social_callback=1';

        if ( empty( $client_id ) || empty( $client_secret ) ) {
            $this->redirect_with_error( __( 'Google OAuth is not configured.', 'ofast-x' ) );
            return;
        }

        $token_response = wp_remote_post( self::GOOGLE_TOKEN_URL, array(
            'body' => array(
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $callback_url,
                'grant_type'    => 'authorization_code',
            ),
        ) );

        if ( is_wp_error( $token_response ) ) {
            if ( class_exists( 'Ofast_X_Logger' ) ) {
                Ofast_X_Logger::error( 'Google token exchange failed: ' . $token_response->get_error_message() );
            }
            $this->redirect_with_error( __( 'Could not connect to Google. Please try again.', 'ofast-x' ) );
            return;
        }

        $token_body = json_decode( wp_remote_retrieve_body( $token_response ), true );

        if ( empty( $token_body['access_token'] ) ) {
            $this->redirect_with_error( __( 'Google did not return an access token.', 'ofast-x' ) );
            return;
        }

        $user_response = wp_remote_get( self::GOOGLE_USER_URL, array(
            'headers' => array( 'Authorization' => 'Bearer ' . $token_body['access_token'] ),
        ) );

        if ( is_wp_error( $user_response ) ) {
            $this->redirect_with_error( __( 'Could not retrieve your Google profile.', 'ofast-x' ) );
            return;
        }

        $user_data = json_decode( wp_remote_retrieve_body( $user_response ), true );

        if ( empty( $user_data['email'] ) ) {
            $this->redirect_with_error( __( 'Google did not provide an email address. Please grant email access.', 'ofast-x' ) );
            return;
        }

        // FIX: Check email_verified before trusting the email.
        // Google can return unverified emails for some account types.
        // Linking an unverified email to an existing WP account is a security risk.
        if ( empty( $user_data['email_verified'] ) || $user_data['email_verified'] === false ) {
            $this->redirect_with_error( __( 'Your Google email address is not verified. Please verify your email with Google first.', 'ofast-x' ) );
            return;
        }

        $this->authenticate_user( array(
            'provider' => 'google',
            'id'       => sanitize_text_field( $user_data['sub'] ?? $user_data['id'] ?? '' ),
            'email'    => sanitize_email( $user_data['email'] ),
            'name'     => sanitize_text_field( $user_data['name'] ?? '' ),
            'avatar'   => esc_url_raw( $user_data['picture'] ?? '' ),
            'first'    => sanitize_text_field( $user_data['given_name'] ?? '' ),
            'last'     => sanitize_text_field( $user_data['family_name'] ?? '' ),
        ), $redirect );
    }

    // -------------------------------------------------------------------------
    // Facebook callback
    // -------------------------------------------------------------------------

    private function process_facebook_callback( $code, $redirect ) {
        $client_id     = get_option( 'ofast_social_facebook_client_id', '' );
        $client_secret = $this->get_provider_secret( 'facebook' );
        $callback_url  = admin_url( 'admin-ajax.php' ) . '?ofast_social_callback=1';

        if ( empty( $client_id ) || empty( $client_secret ) ) {
            $this->redirect_with_error( __( 'Facebook OAuth is not configured.', 'ofast-x' ) );
            return;
        }

        // FIX: Old code used wp_remote_GET with the secret in the query string.
        // Secrets in GET URLs appear in web server access logs and browser history.
        // Facebook's token endpoint accepts POST — credentials now sent in request body.
        $token_response = wp_remote_post( $this->get_fb_token_url(), array(
            'body' => array(
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $callback_url,
                'code'          => $code,
            ),
        ) );

        if ( is_wp_error( $token_response ) ) {
            $this->redirect_with_error( __( 'Could not connect to Facebook. Please try again.', 'ofast-x' ) );
            return;
        }

        $token_body = json_decode( wp_remote_retrieve_body( $token_response ), true );

        if ( empty( $token_body['access_token'] ) ) {
            $error = $token_body['error']['message'] ?? __( 'Facebook did not return an access token.', 'ofast-x' );
            $this->redirect_with_error( $error );
            return;
        }

        // Fetch user data — never put the token in the URL, always in header or specific FB param
        $user_url = add_query_arg( array(
            'fields'       => 'id,name,email,first_name,last_name,picture.type(large)',
            'access_token' => $token_body['access_token'],
        ), $this->get_fb_user_url() );

        $user_response = wp_remote_get( $user_url );

        if ( is_wp_error( $user_response ) ) {
            $this->redirect_with_error( __( 'Could not retrieve your Facebook profile.', 'ofast-x' ) );
            return;
        }

        $user_data = json_decode( wp_remote_retrieve_body( $user_response ), true );

        if ( empty( $user_data['email'] ) ) {
            $this->redirect_with_error( __( 'Facebook did not provide an email address. Please ensure your Facebook account has a verified email and grant email permission.', 'ofast-x' ) );
            return;
        }

        $this->authenticate_user( array(
            'provider' => 'facebook',
            'id'       => sanitize_text_field( $user_data['id'] ?? '' ),
            'email'    => sanitize_email( $user_data['email'] ),
            'name'     => sanitize_text_field( $user_data['name'] ?? '' ),
            'avatar'   => esc_url_raw( $user_data['picture']['data']['url'] ?? '' ),
            'first'    => sanitize_text_field( $user_data['first_name'] ?? '' ),
            'last'     => sanitize_text_field( $user_data['last_name'] ?? '' ),
        ), $redirect );
    }

    // -------------------------------------------------------------------------
    // User authentication / creation
    // -------------------------------------------------------------------------

    private function authenticate_user( $social_data, $redirect ) {
        if ( empty( $social_data['email'] ) || ! is_email( $social_data['email'] ) ) {
            $this->redirect_with_error( __( 'Invalid email address returned by provider.', 'ofast-x' ) );
            return;
        }

        $meta_key = 'ofast_social_' . $social_data['provider'] . '_id';

        // Look up by provider ID first
        // FIX: count_total => false avoids a COUNT() query on every login
        $users = get_users( array(
            'meta_key'    => $meta_key,
            'meta_value'  => $social_data['id'],
            'number'      => 1,
            'count_total' => false,
        ) );

        $user = ! empty( $users ) ? $users[0] : null;

        // Fall back to email match
        if ( ! $user ) {
            $user = get_user_by( 'email', $social_data['email'] );
        }

        // Create new user if none found
        if ( ! $user ) {
            // FIX: Respect the site's user registration setting.
            // Old code created accounts regardless — bypassing the admin's intent
            // when "Anyone can register" is disabled in Settings → General.
            if ( ! get_option( 'users_can_register' ) ) {
                $this->redirect_with_error( __( 'Account registration is currently disabled on this site.', 'ofast-x' ) );
                return;
            }

            $username = $this->generate_username( $social_data );
            $user_id  = wp_create_user( $username, wp_generate_password( 24 ), $social_data['email'] );

            if ( is_wp_error( $user_id ) ) {
                if ( class_exists( 'Ofast_X_Logger' ) ) {
                    Ofast_X_Logger::error( 'Social login user creation failed: ' . $user_id->get_error_message() );
                }
                $this->redirect_with_error( __( 'Could not create your account. Please try again or contact support.', 'ofast-x' ) );
                return;
            }

            $user = get_user_by( 'id', $user_id );

            // Assign default role
            $default_role = get_option( 'ofast_social_default_role', 'subscriber' );
            $user->set_role( $default_role );

            // Store display name
            if ( ! empty( $social_data['name'] ) ) {
                wp_update_user( array(
                    'ID'           => $user_id,
                    'display_name' => $social_data['name'],
                    'first_name'   => $social_data['first'] ?? '',
                    'last_name'    => $social_data['last'] ?? '',
                ) );
            }
        }

        // Link provider ID and store avatar
        update_user_meta( $user->ID, $meta_key, $social_data['id'] );
        if ( ! empty( $social_data['avatar'] ) ) {
            update_user_meta( $user->ID, 'ofast_social_avatar', esc_url_raw( $social_data['avatar'] ) );
        }

        // Log in
        wp_set_current_user( $user->ID, $user->user_login );
        wp_set_auth_cookie( $user->ID, false );

        // FIX: Fire wp_login action so security plugins, 2FA plugins, and audit
        // logs are aware of the login. Old code skipped this entirely — any plugin
        // hooking into wp_login (Wordfence, WP Activity Log, Two Factor Auth) was
        // silently bypassed on every social login.
        do_action( 'wp_login', $user->user_login, $user );

        // FIX: Use wp_safe_redirect — prevents open redirect if $redirect were
        // somehow tampered with despite our sanitize_redirect() guard above.
        wp_safe_redirect( $redirect ?: admin_url() );
        exit;
    }

    // -------------------------------------------------------------------------
    // Username generation
    // -------------------------------------------------------------------------

    private function generate_username( $social_data ) {
        // Build a base from the name or email prefix
        $base = '';

        if ( ! empty( $social_data['first'] ) ) {
            $base = sanitize_user( strtolower( $social_data['first'] ), true );
        }
        if ( ! empty( $social_data['last'] ) ) {
            $base = $base ? $base . '_' . sanitize_user( strtolower( $social_data['last'] ), true ) : sanitize_user( strtolower( $social_data['last'] ), true );
        }
        if ( empty( $base ) && ! empty( $social_data['email'] ) ) {
            $base = sanitize_user( strtolower( strstr( $social_data['email'], '@', true ) ), true );
        }
        if ( empty( $base ) ) {
            $base = 'user';
        }

        $username = $base;

        if ( ! username_exists( $username ) ) {
            return $username;
        }

        // FIX: Old code had no iteration cap — potential infinite loop if
        // username_exists() never returned false (hook interference, DB issue).
        // Cap at 100 attempts then fall back to a UUID-based username.
        $counter = 1;
        while ( username_exists( $username ) && $counter <= 100 ) {
            $username = $base . $counter;
            $counter++;
        }

        if ( $counter > 100 ) {
            // UUID fallback — guaranteed unique
            $username = $base . '_' . substr( wp_generate_uuid4(), 0, 8 );
        }

        return $username;
    }

    // -------------------------------------------------------------------------
    // Avatar filter
    // -------------------------------------------------------------------------

    /**
     * FIX: Avatar was stored in user meta on every login but the get_avatar
     * filter was never registered — the stored URL was never actually used.
     * Now wired up to serve the social avatar in place of Gravatar when available.
     */
    public function filter_avatar_url( $url, $id_or_email, $args ) {
        $user = false;

        if ( is_numeric( $id_or_email ) ) {
            $user = get_user_by( 'id', (int) $id_or_email );
        } elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
            $user = get_user_by( 'email', $id_or_email );
        } elseif ( $id_or_email instanceof WP_User ) {
            $user = $id_or_email;
        } elseif ( $id_or_email instanceof WP_Post ) {
            $user = get_user_by( 'id', (int) $id_or_email->post_author );
        } elseif ( $id_or_email instanceof WP_Comment && ! empty( $id_or_email->user_id ) ) {
            $user = get_user_by( 'id', (int) $id_or_email->user_id );
        }

        if ( ! $user ) {
            return $url;
        }

        $social_avatar = get_user_meta( $user->ID, 'ofast_social_avatar', true );
        if ( ! empty( $social_avatar ) ) {
            return esc_url( $social_avatar );
        }

        return $url;
    }

    // -------------------------------------------------------------------------
    // Error redirect
    // -------------------------------------------------------------------------

    private function redirect_with_error( $message ) {
        // FIX: wp_redirect() allows external URLs — replaced with wp_safe_redirect().
        // FIX: Also fire wp_login_failed so lockout plugins track failed attempts.
        do_action( 'wp_login_failed', '', new WP_Error( 'social_login_failed', $message ) );

        $login_url = add_query_arg(
            array( 'ofast_social_error' => urlencode( $message ) ),
            wp_login_url()
        );

        wp_safe_redirect( $login_url );
        exit;
    }
}