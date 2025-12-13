<?php

/**
 * Ofast X - Social Login Module
 * OAuth authentication with Google and Facebook
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Social_Login
{
    private static $instance = null;

    // OAuth endpoints
    const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const GOOGLE_USER_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    const FB_AUTH_URL = 'https://www.facebook.com/v18.0/dialog/oauth';
    const FB_TOKEN_URL = 'https://graph.facebook.com/v18.0/oauth/access_token';
    const FB_USER_URL = 'https://graph.facebook.com/v18.0/me';

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
        // Admin settings
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_settings_save'));

        // OAuth callback handler
        add_action('init', array($this, 'handle_oauth_callback'));

        // Login form buttons - use login_footer to appear after submit button
        add_action('login_footer', array($this, 'render_login_buttons_positioned'));

        // WooCommerce integration - use _end hooks for bottom placement
        add_action('woocommerce_login_form_end', array($this, 'render_login_buttons'));
        add_action('woocommerce_register_form_end', array($this, 'render_login_buttons'));

        // Enqueue styles
        add_action('login_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'Social Login',
            'Social Login',
            'manage_options',
            'ofast-social-login',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Check if module is enabled
     */
    public function is_enabled()
    {
        return get_option('ofast_social_login_enabled', false);
    }

    /**
     * Get provider settings
     */
    public function get_provider_settings($provider)
    {
        $client_id = get_option("ofast_social_{$provider}_client_id", '');
        $client_secret = get_option("ofast_social_{$provider}_client_secret", '');

        // Decrypt secret if encryption available
        if (!empty($client_secret) && class_exists('Ofast_X_Security_Hardening')) {
            $client_secret = Ofast_X_Security_Hardening::decrypt_option($client_secret);
        }

        return array(
            'enabled' => get_option("ofast_social_{$provider}_enabled", false),
            'client_id' => $client_id,
            'client_secret' => $client_secret
        );
    }

    /**
     * Check if provider is configured
     */
    public function is_provider_configured($provider)
    {
        $settings = $this->get_provider_settings($provider);
        return $settings['enabled'] && !empty($settings['client_id']) && !empty($settings['client_secret']);
    }

    /**
     * Get callback URL
     */
    public function get_callback_url($provider)
    {
        return home_url('?ofast_social_callback=' . $provider);
    }

    /**
     * Generate OAuth state for CSRF protection
     */
    private function generate_state($provider)
    {
        $state = wp_generate_password(32, false);
        set_transient('ofast_social_state_' . $state, array(
            'provider' => $provider,
            'redirect' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : home_url()
        ), 600); // 10 minutes
        return $state;
    }

    /**
     * Verify OAuth state
     */
    private function verify_state($state)
    {
        $data = get_transient('ofast_social_state_' . $state);
        if ($data) {
            delete_transient('ofast_social_state_' . $state);
        }
        return $data;
    }

    /**
     * Get Google authorization URL
     */
    public function get_google_auth_url()
    {
        $settings = $this->get_provider_settings('google');
        $state = $this->generate_state('google');

        $params = array(
            'client_id' => $settings['client_id'],
            'redirect_uri' => $this->get_callback_url('google'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account'
        );

        return self::GOOGLE_AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Get Facebook authorization URL
     */
    public function get_facebook_auth_url()
    {
        $settings = $this->get_provider_settings('facebook');
        $state = $this->generate_state('facebook');

        $params = array(
            'client_id' => $settings['client_id'],
            'redirect_uri' => $this->get_callback_url('facebook'),
            'response_type' => 'code',
            'scope' => 'email,public_profile',
            'state' => $state
        );

        return self::FB_AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Handle OAuth callback
     */
    public function handle_oauth_callback()
    {
        if (!isset($_GET['ofast_social_callback'])) {
            return;
        }

        $provider = sanitize_text_field($_GET['ofast_social_callback']);

        // Verify state
        if (!isset($_GET['state'])) {
            $this->redirect_with_error('Invalid request');
            return;
        }

        $state_data = $this->verify_state(sanitize_text_field($_GET['state']));
        if (!$state_data || $state_data['provider'] !== $provider) {
            $this->redirect_with_error('Security check failed');
            return;
        }

        // Check for error from provider
        if (isset($_GET['error'])) {
            $this->redirect_with_error($_GET['error_description'] ?? 'Authentication cancelled');
            return;
        }

        // Get authorization code
        if (!isset($_GET['code'])) {
            $this->redirect_with_error('No authorization code');
            return;
        }

        $code = sanitize_text_field($_GET['code']);

        // Process based on provider
        if ($provider === 'google') {
            $this->process_google_callback($code, $state_data['redirect']);
        } elseif ($provider === 'facebook') {
            $this->process_facebook_callback($code, $state_data['redirect']);
        }
    }

    /**
     * Process Google callback
     */
    private function process_google_callback($code, $redirect_url)
    {
        $settings = $this->get_provider_settings('google');

        // Exchange code for token
        $response = wp_remote_post(self::GOOGLE_TOKEN_URL, array(
            'body' => array(
                'code' => $code,
                'client_id' => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'redirect_uri' => $this->get_callback_url('google'),
                'grant_type' => 'authorization_code'
            )
        ));

        if (is_wp_error($response)) {
            $this->redirect_with_error('Failed to get access token');
            return;
        }

        $token_data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($token_data['access_token'])) {
            $this->redirect_with_error('Invalid token response');
            return;
        }

        // Get user profile
        $response = wp_remote_get(self::GOOGLE_USER_URL, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token_data['access_token']
            )
        ));

        if (is_wp_error($response)) {
            $this->redirect_with_error('Failed to get user info');
            return;
        }

        $user_data = json_decode(wp_remote_retrieve_body($response), true);

        $this->authenticate_user(array(
            'provider' => 'google',
            'id' => $user_data['id'],
            'email' => $user_data['email'],
            'name' => $user_data['name'],
            'first_name' => $user_data['given_name'] ?? '',
            'last_name' => $user_data['family_name'] ?? '',
            'avatar' => $user_data['picture'] ?? ''
        ), $redirect_url);
    }

    /**
     * Process Facebook callback
     */
    private function process_facebook_callback($code, $redirect_url)
    {
        $settings = $this->get_provider_settings('facebook');

        // Exchange code for token
        $response = wp_remote_get(self::FB_TOKEN_URL . '?' . http_build_query(array(
            'code' => $code,
            'client_id' => $settings['client_id'],
            'client_secret' => $settings['client_secret'],
            'redirect_uri' => $this->get_callback_url('facebook')
        )));

        if (is_wp_error($response)) {
            $this->redirect_with_error('Failed to get access token');
            return;
        }

        $token_data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($token_data['access_token'])) {
            $this->redirect_with_error('Invalid token response');
            return;
        }

        // Get user profile
        $response = wp_remote_get(self::FB_USER_URL . '?' . http_build_query(array(
            'access_token' => $token_data['access_token'],
            'fields' => 'id,email,name,first_name,last_name,picture.type(large)'
        )));

        if (is_wp_error($response)) {
            $this->redirect_with_error('Failed to get user info');
            return;
        }

        $user_data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($user_data['email'])) {
            $this->redirect_with_error('Email not provided by Facebook');
            return;
        }

        $this->authenticate_user(array(
            'provider' => 'facebook',
            'id' => $user_data['id'],
            'email' => $user_data['email'],
            'name' => $user_data['name'],
            'first_name' => $user_data['first_name'] ?? '',
            'last_name' => $user_data['last_name'] ?? '',
            'avatar' => $user_data['picture']['data']['url'] ?? ''
        ), $redirect_url);
    }

    /**
     * Authenticate or create user
     */
    private function authenticate_user($social_data, $redirect_url)
    {
        // First, check if user already linked with this social account
        $users = get_users(array(
            'meta_key' => 'ofast_social_' . $social_data['provider'] . '_id',
            'meta_value' => $social_data['id'],
            'number' => 1
        ));

        if (!empty($users)) {
            $user = $users[0];
        } else {
            // Check if user exists with this email
            $user = get_user_by('email', $social_data['email']);

            if (!$user) {
                // Create new user
                $username = $this->generate_username($social_data['email'], $social_data['name']);
                $password = wp_generate_password(24);

                $user_id = wp_create_user($username, $password, $social_data['email']);

                if (is_wp_error($user_id)) {
                    $this->redirect_with_error('Failed to create account');
                    return;
                }

                // Set user data
                wp_update_user(array(
                    'ID' => $user_id,
                    'first_name' => $social_data['first_name'],
                    'last_name' => $social_data['last_name'],
                    'display_name' => $social_data['name'],
                    'role' => get_option('ofast_social_default_role', 'subscriber')
                ));

                $user = get_user_by('ID', $user_id);
            }

            // Link social account
            update_user_meta($user->ID, 'ofast_social_' . $social_data['provider'] . '_id', $social_data['id']);
        }

        // Update avatar
        if (!empty($social_data['avatar'])) {
            update_user_meta($user->ID, 'ofast_social_avatar', $social_data['avatar']);
        }

        // Log the user in
        wp_set_auth_cookie($user->ID, true);
        wp_set_current_user($user->ID);

        // Redirect
        $final_redirect = get_option('ofast_social_redirect_url', '');
        if (empty($final_redirect)) {
            $final_redirect = $redirect_url ?: home_url();
        }

        // Don't redirect back to login page
        if (strpos($final_redirect, 'wp-login.php') !== false) {
            $final_redirect = home_url();
        }

        wp_safe_redirect($final_redirect);
        exit;
    }

    /**
     * Generate unique username from email/name
     */
    private function generate_username($email, $name)
    {
        // Try name first
        $username = sanitize_user(strtolower(str_replace(' ', '', $name)), true);

        if (empty($username) || username_exists($username)) {
            // Use email prefix
            $username = sanitize_user(strstr($email, '@', true), true);
        }

        // Add number if exists
        $base_username = $username;
        $counter = 1;
        while (username_exists($username)) {
            $username = $base_username . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Redirect with error
     */
    private function redirect_with_error($message)
    {
        wp_redirect(add_query_arg('social_login_error', urlencode($message), wp_login_url()));
        exit;
    }

    /**
     * Render login buttons
     */
    public function render_login_buttons()
    {
        if (!$this->is_enabled()) {
            return;
        }

        $google_enabled = $this->is_provider_configured('google');
        $facebook_enabled = $this->is_provider_configured('facebook');

        if (!$google_enabled && !$facebook_enabled) {
            return;
        }

        $button_style = get_option('ofast_social_button_style', 'icon_text');
?>
        <div class="ofast-social-login">
            <div class="ofast-social-divider">
                <span><?php esc_html_e('Or continue with', 'ofast-x'); ?></span>
            </div>
            <div class="ofast-social-buttons">
                <?php if ($google_enabled): ?>
                    <a href="<?php echo esc_url($this->get_google_auth_url()); ?>" class="ofast-social-btn ofast-social-google">
                        <svg viewBox="0 0 24 24" width="20" height="20">
                            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
                            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" />
                            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
                        </svg>
                        <?php if ($button_style !== 'icon'): ?>
                            <span>Google</span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>

                <?php if ($facebook_enabled): ?>
                    <a href="<?php echo esc_url($this->get_facebook_auth_url()); ?>" class="ofast-social-btn ofast-social-facebook">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="#1877F2">
                            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
                        </svg>
                        <?php if ($button_style !== 'icon'): ?>
                            <span>Facebook</span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php
        // Show error if any
        if (isset($_GET['social_login_error'])): ?>
            <div class="ofast-social-error">
                <?php echo esc_html(urldecode($_GET['social_login_error'])); ?>
            </div>
        <?php endif;
    }

    /**
     * Render login buttons positioned at bottom of WP login form
     */
    public function render_login_buttons_positioned()
    {
        if (!$this->is_enabled()) {
            return;
        }

        $google_enabled = $this->is_provider_configured('google');
        $facebook_enabled = $this->is_provider_configured('facebook');

        if (!$google_enabled && !$facebook_enabled) {
            return;
        }

        $button_style = get_option('ofast_social_button_style', 'icon_text');
        ?>
        <div id="ofast-social-login-container" style="display: none;">
            <div class="ofast-social-login" style="margin: 0 auto; max-width: 320px; padding: 0 24px;">
                <div class="ofast-social-divider">
                    <span><?php esc_html_e('Or continue with', 'ofast-x'); ?></span>
                </div>
                <div class="ofast-social-buttons">
                    <?php if ($google_enabled): ?>
                        <a href="<?php echo esc_url($this->get_google_auth_url()); ?>" class="ofast-social-btn ofast-social-google">
                            <svg viewBox="0 0 24 24" width="20" height="20">
                                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
                                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" />
                                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
                            </svg>
                            <?php if ($button_style !== 'icon'): ?>
                                <span>Google</span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($facebook_enabled): ?>
                        <a href="<?php echo esc_url($this->get_facebook_auth_url()); ?>" class="ofast-social-btn ofast-social-facebook">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="#1877F2">
                                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
                            </svg>
                            <?php if ($button_style !== 'icon'): ?>
                                <span>Facebook</span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (isset($_GET['social_login_error'])): ?>
                <div class="ofast-social-error" style="margin: 10px 24px;">
                    <?php echo esc_html(urldecode($_GET['social_login_error'])); ?>
                </div>
            <?php endif; ?>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var socialContainer = document.getElementById('ofast-social-login-container');
                var loginForm = document.getElementById('loginform') || document.getElementById('registerform');

                if (socialContainer && loginForm) {
                    // Move social buttons after the login form
                    loginForm.parentNode.insertBefore(socialContainer, loginForm.nextSibling);
                    socialContainer.style.display = 'block';
                }
            });
        </script>
    <?php
    }

    /**
     * Enqueue styles
     */
    public function enqueue_styles()
    {
        if (!$this->is_enabled()) {
            return;
        }
    ?>
        <style>
            .ofast-social-login {
                margin: 20px 0;
                text-align: center;
            }

            .ofast-social-divider {
                display: flex;
                align-items: center;
                margin: 20px 0;
            }

            .ofast-social-divider::before,
            .ofast-social-divider::after {
                content: '';
                flex: 1;
                border-bottom: 1px solid #ddd;
            }

            .ofast-social-divider span {
                padding: 0 15px;
                color: #666;
                font-size: 13px;
            }

            .ofast-social-buttons {
                display: flex;
                gap: 10px;
                justify-content: center;
                flex-wrap: wrap;
            }

            .ofast-social-btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 10px 20px;
                border: 1px solid #ddd;
                border-radius: 5px;
                background: #fff;
                color: #333;
                text-decoration: none;
                font-size: 14px;
                font-weight: 500;
                transition: all 0.2s;
            }

            .ofast-social-btn:hover {
                background: #f5f5f5;
                border-color: #ccc;
            }

            .ofast-social-google:hover {
                border-color: #4285F4;
            }

            .ofast-social-facebook:hover {
                border-color: #1877F2;
            }

            .ofast-social-error {
                background: #f8d7da;
                color: #721c24;
                padding: 10px 15px;
                border-radius: 5px;
                margin: 10px 0;
                font-size: 13px;
            }
        </style>
    <?php
    }

    /**
     * Handle settings save
     */
    public function handle_settings_save()
    {
        if (!isset($_POST['ofast_social_login_save']) || !current_user_can('manage_options')) {
            return;
        }

        if (!wp_verify_nonce($_POST['ofast_social_nonce'] ?? '', 'ofast_social_login_settings')) {
            return;
        }

        // Enable/disable
        update_option('ofast_social_login_enabled', isset($_POST['social_login_enabled']));

        // Google settings
        update_option('ofast_social_google_enabled', isset($_POST['google_enabled']));
        if (!empty($_POST['google_client_id'])) {
            update_option('ofast_social_google_client_id', sanitize_text_field($_POST['google_client_id']));
        }
        if (!empty($_POST['google_client_secret'])) {
            $secret = sanitize_text_field($_POST['google_client_secret']);
            if (class_exists('Ofast_X_Security_Hardening')) {
                $secret = Ofast_X_Security_Hardening::encrypt_option($secret);
            }
            update_option('ofast_social_google_client_secret', $secret);
        }

        // Facebook settings
        update_option('ofast_social_facebook_enabled', isset($_POST['facebook_enabled']));
        if (!empty($_POST['facebook_app_id'])) {
            update_option('ofast_social_facebook_client_id', sanitize_text_field($_POST['facebook_app_id']));
        }
        if (!empty($_POST['facebook_app_secret'])) {
            $secret = sanitize_text_field($_POST['facebook_app_secret']);
            if (class_exists('Ofast_X_Security_Hardening')) {
                $secret = Ofast_X_Security_Hardening::encrypt_option($secret);
            }
            update_option('ofast_social_facebook_client_secret', $secret);
        }

        // Other settings
        update_option('ofast_social_button_style', sanitize_text_field($_POST['button_style'] ?? 'icon_text'));
        update_option('ofast_social_default_role', sanitize_text_field($_POST['default_role'] ?? 'subscriber'));
        update_option('ofast_social_redirect_url', esc_url_raw($_POST['redirect_url'] ?? ''));

        add_settings_error('ofast_social_login', 'saved', 'Settings saved!', 'success');
    }

    /**
     * Render settings page
     */
    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }

        $google = $this->get_provider_settings('google');
        $facebook = $this->get_provider_settings('facebook');
    ?>
        <div class="wrap">
            <h1>Social Login Settings</h1>

            <?php settings_errors('ofast_social_login'); ?>

            <form method="post">
                <?php wp_nonce_field('ofast_social_login_settings', 'ofast_social_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th>Enable Social Login</th>
                        <td>
                            <label>
                                <input type="checkbox" name="social_login_enabled" value="1" <?php checked(get_option('ofast_social_login_enabled')); ?>>
                                Enable social login on login and registration forms
                            </label>
                        </td>
                    </tr>
                </table>

                <h2>Google</h2>
                <p>Create credentials at <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a></p>
                <table class="form-table">
                    <tr>
                        <th>Enable Google</th>
                        <td>
                            <input type="checkbox" name="google_enabled" value="1" <?php checked($google['enabled']); ?>>
                        </td>
                    </tr>
                    <tr>
                        <th>Client ID</th>
                        <td>
                            <input type="text" name="google_client_id" value="<?php echo esc_attr($google['client_id']); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Client Secret</th>
                        <td>
                            <input type="password" name="google_client_secret" placeholder="<?php echo $google['client_secret'] ? '********' : ''; ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Callback URL</th>
                        <td>
                            <code><?php echo esc_html($this->get_callback_url('google')); ?></code>
                            <p class="description">Add this URL to your Google OAuth Authorized redirect URIs</p>
                        </td>
                    </tr>
                </table>

                <h2>Facebook</h2>
                <p>Create an app at <a href="https://developers.facebook.com/apps/" target="_blank">Meta Developer Portal</a></p>
                <table class="form-table">
                    <tr>
                        <th>Enable Facebook</th>
                        <td>
                            <input type="checkbox" name="facebook_enabled" value="1" <?php checked($facebook['enabled']); ?>>
                        </td>
                    </tr>
                    <tr>
                        <th>App ID</th>
                        <td>
                            <input type="text" name="facebook_app_id" value="<?php echo esc_attr($facebook['client_id']); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>App Secret</th>
                        <td>
                            <input type="password" name="facebook_app_secret" placeholder="<?php echo $facebook['client_secret'] ? '********' : ''; ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Callback URL</th>
                        <td>
                            <code><?php echo esc_html($this->get_callback_url('facebook')); ?></code>
                            <p class="description">Add this URL to your Facebook App Valid OAuth Redirect URIs</p>
                        </td>
                    </tr>
                </table>

                <h2>Options</h2>
                <table class="form-table">
                    <tr>
                        <th>Button Style</th>
                        <td>
                            <select name="button_style">
                                <option value="icon_text" <?php selected(get_option('ofast_social_button_style'), 'icon_text'); ?>>Icon + Text</option>
                                <option value="icon" <?php selected(get_option('ofast_social_button_style'), 'icon'); ?>>Icon Only</option>
                                <option value="text" <?php selected(get_option('ofast_social_button_style'), 'text'); ?>>Text Only</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Default Role</th>
                        <td>
                            <select name="default_role">
                                <?php wp_dropdown_roles(get_option('ofast_social_default_role', 'subscriber')); ?>
                            </select>
                            <p class="description">Role assigned to new users created via social login</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Redirect After Login</th>
                        <td>
                            <input type="url" name="redirect_url" value="<?php echo esc_url(get_option('ofast_social_redirect_url')); ?>" class="regular-text" placeholder="<?php echo esc_url(home_url()); ?>">
                            <p class="description">Leave empty to redirect to previous page</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="ofast_social_login_save" class="button button-primary">Save Settings</button>
                </p>
            </form>
        </div>
<?php
    }
}
