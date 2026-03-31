<?php

/**
 * Ofast X - Spam Protection Module
 * Unified settings for Cloudflare Turnstile and Google reCAPTCHA
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Spam_Protection
{
    /**
     * Initialize module
     */
    public function init()
    {
        // NOTE: Module enabled check removed - core loader already verified this
        // before calling init(). See class-ofast-core.php is_module_enabled()

        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Initialize honeypot if enabled
        if (class_exists('Ofast_X_Honeypot') && get_option('ofast_spam_honeypot_enabled', true)) {
            Ofast_X_Honeypot::get_instance()->init();
        }

        // Initialize universal spam protection if force-all is enabled
        if (class_exists('Ofast_X_Universal_Spam') && get_option('ofast_spam_force_all_forms', false)) {
            Ofast_X_Universal_Spam::get_instance()->init();
        }

        // Initialize Math CAPTCHA if selected as provider
        if (class_exists('Ofast_X_Math_Captcha') && $this->get_active_provider() === 'math_captcha') {
            Ofast_X_Math_Captcha::get_instance()->init();
        }

        // Get protection settings
        $protect_comments = get_option('ofast_spam_protect_comments', false);
        $protect_cf7 = get_option('ofast_spam_protect_cf7', false);
        $protect_login = get_option('ofast_spam_protect_login', false);

        // Comment form protection
        if ($protect_comments && $this->is_configured()) {
            // Render widget in comment form
            add_action('comment_form_after_fields', array($this, 'render_comment_widget'));
            add_action('comment_form_logged_in_after', array($this, 'render_comment_widget'));

            // Verify on comment submission
            add_filter('preprocess_comment', array($this, 'verify_comment'), 10, 1);

            // Enqueue script on pages with comments
            add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_script'));
        }

        // Contact Form 7 integration
        if ($protect_cf7 && $this->is_configured()) {
            // Add field to CF7 forms
            add_filter('wpcf7_form_elements', array($this, 'add_cf7_widget'));

            // Validate CF7 submission
            add_filter('wpcf7_validate', array($this, 'validate_cf7'), 20, 2);

            // Enqueue script on CF7 pages
            add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_script'));
        }

        // WordPress Login form protection
        if ($protect_login && $this->is_configured()) {
            // Render widget on login form
            add_action('login_form', array($this, 'render_login_widget'));

            // Enqueue scripts on login page
            add_action('login_enqueue_scripts', array($this, 'enqueue_login_script'));

            // Verify on authentication
            add_filter('authenticate', array($this, 'verify_login'), 30, 3);
        }
    }

    /**
     * Enqueue frontend script for Turnstile
     */
    public function enqueue_frontend_script()
    {
        $provider = $this->get_active_provider();
        if ($provider === 'turnstile' && class_exists('Ofast_X_Turnstile')) {
            echo Ofast_X_Turnstile::render_script();
        }
    }

    /**
     * Render spam protection widget in comment form
     */
    public function render_comment_widget()
    {
        $provider = $this->get_active_provider();
        
        if ($provider === 'turnstile' && class_exists('Ofast_X_Turnstile')) {
            echo '<p class="comment-form-turnstile" style="margin: 10px 0;">';
            echo Ofast_X_Turnstile::get_instance()->render_widget('comment');
            echo '</p>';
        } elseif ($provider === 'math_captcha' && class_exists('Ofast_X_Math_Captcha')) {
            echo '<p class="comment-form-math-captcha" style="margin: 10px 0;">';
            echo Ofast_X_Math_Captcha::get_instance()->render_widget('comment');
            echo '</p>';
        }
    }

    /**
     * Verify comment submission
     */
    public function verify_comment($commentdata)
    {
        // Skip for logged-in admins
        if (current_user_can('manage_options')) {
            return $commentdata;
        }

        // Get token based on provider (Math CAPTCHA reads its own POST fields)
        $provider = $this->get_active_provider();
        $token = '';
        if ($provider !== 'math_captcha') {
            $token = isset($_POST['cf-turnstile-response']) ? sanitize_text_field($_POST['cf-turnstile-response']) : '';
            if (empty($token)) {
                $token = isset($_POST['g-recaptcha-response']) ? sanitize_text_field($_POST['g-recaptcha-response']) : '';
            }
        }
        
        $result = $this->verify($token);

        if (!$result['success']) {
            wp_die(
                '<strong>Spam protection failed:</strong> ' . esc_html($result['error'] ?? 'Verification required'),
                'Comment Blocked',
                array('response' => 403, 'back_link' => true)
            );
        }

        return $commentdata;
    }

    /**
     * Add spam protection widget to Contact Form 7
     */
    public function add_cf7_widget($elements)
    {
        $provider = $this->get_active_provider();
        $widget = '';
        
        if ($provider === 'turnstile' && class_exists('Ofast_X_Turnstile')) {
            $widget = '<div class="wpcf7-turnstile" style="margin: 15px 0;">';
            $widget .= Ofast_X_Turnstile::get_instance()->render_widget('cf7');
            $widget .= '</div>';
        } elseif ($provider === 'math_captcha' && class_exists('Ofast_X_Math_Captcha')) {
            $widget = '<div class="wpcf7-math-captcha" style="margin: 15px 0;">';
            $widget .= Ofast_X_Math_Captcha::get_instance()->render_widget('cf7');
            $widget .= '</div>';
        }

        if (!empty($widget)) {
            // Add before submit button if possible
            $elements = preg_replace('/(<input[^>]*type=["\']submit["\'][^>]*>)/i', $widget . '$1', $elements, 1);
        }
        
        return $elements;
    }

    /**
     * Validate Contact Form 7 submission
     */
    public function validate_cf7($result, $tags)
    {
        // Get token based on provider (Math CAPTCHA reads its own POST fields)
        $provider = $this->get_active_provider();
        $token = '';
        if ($provider !== 'math_captcha') {
            $token = isset($_POST['cf-turnstile-response']) ? sanitize_text_field($_POST['cf-turnstile-response']) : '';
            if (empty($token)) {
                $token = isset($_POST['g-recaptcha-response']) ? sanitize_text_field($_POST['g-recaptcha-response']) : '';
            }
        }
        
        $verify = $this->verify($token);

        if (!$verify['success']) {
            $result->invalidate('', $verify['error'] ?? 'Spam verification failed');
        }

        return $result;
    }

    /**
     * Render spam protection widget on login form
     */
    public function render_login_widget()
    {
        $provider = $this->get_active_provider();
        
        if ($provider === 'turnstile' && class_exists('Ofast_X_Turnstile')) {
            echo '<div class="login-form-turnstile" style="margin: 15px 0;">';
            echo Ofast_X_Turnstile::get_instance()->render_widget('login');
            echo '</div>';
        } elseif ($provider === 'math_captcha' && class_exists('Ofast_X_Math_Captcha')) {
            echo '<div class="login-form-math-captcha" style="margin: 15px 0;">';
            echo Ofast_X_Math_Captcha::get_instance()->render_widget('login');
            echo '</div>';
        }
    }

    /**
     * Enqueue scripts on login page
     */
    public function enqueue_login_script()
    {
        $provider = $this->get_active_provider();
        if ($provider === 'turnstile' && class_exists('Ofast_X_Turnstile')) {
            echo Ofast_X_Turnstile::render_script();
        }
    }

    /**
     * Verify login form spam protection
     */
    public function verify_login($user, $username, $password)
    {
        // Only verify on actual login attempts (not empty form load)
        if (empty($username) && empty($password)) {
            return $user;
        }

        // Skip if already a credential error (wrong password, etc.)
        // But still block if it's already a spam error
        if (is_wp_error($user) && !in_array('spam_protection_failed', $user->get_error_codes())) {
            return $user;
        }

        $provider = $this->get_active_provider();

        // STRICT: Token/answer is REQUIRED for login when protection is enabled
        // Check provider-specific field names
        if ($provider === 'math_captcha') {
            // Math CAPTCHA uses ofast_math_answer field
            if (!isset($_POST['ofast_math_answer']) || $_POST['ofast_math_answer'] === '') {
                return new WP_Error(
                    'spam_protection_failed',
                    '<strong>Security verification required.</strong> Please solve the math problem.'
                );
            }
        } else {
            // Turnstile/reCAPTCHA use cf-turnstile-response or g-recaptcha-response
            $token = isset($_POST['cf-turnstile-response']) ? sanitize_text_field($_POST['cf-turnstile-response']) : '';
            if (empty($token)) {
                $token = isset($_POST['g-recaptcha-response']) ? sanitize_text_field($_POST['g-recaptcha-response']) : '';
            }
            
            // If no token at all, block immediately (prevents bypass by removing field)
            if (empty($token)) {
                return new WP_Error(
                    'spam_protection_failed',
                    '<strong>Security verification required.</strong> Please complete the spam protection challenge.'
                );
            }
        }

        // Call the unified verify method (handles all providers)
        $result = $this->verify(isset($token) ? $token : '');

        if (!$result['success']) {
            // Log failed verification attempts
            if (function_exists('error_log')) {
                error_log('Ofast Spam Protection: Login verification failed from IP ' . $this->get_client_ip() . ' - ' . ($result['error'] ?? 'Unknown error'));
            }
            
            return new WP_Error(
                'spam_protection_failed',
                '<strong>Spam protection failed:</strong> ' . esc_html($result['error'] ?? 'Please complete the verification.')
            );
        }

        return $user;
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'Spam Protection',
            'Spam Protection',
            'manage_options',
            'ofast-spam-protection',
            array($this, 'render_page')
        );
    }

    /**
     * Render the settings page
     */
    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }

        // Handle POST Save
        if (isset($_POST['ofast_save_recaptcha']) && wp_verify_nonce($_POST['recaptcha_nonce'], 'ofast_recaptcha_save')) {
            update_option('ofast_spam_provider', sanitize_text_field($_POST['spam_provider']));

            // Save protection settings
            update_option('ofast_spam_protect_comments', isset($_POST['protect_comments']) ? 1 : 0);
            update_option('ofast_spam_protect_cf7', isset($_POST['protect_cf7']) ? 1 : 0);
            update_option('ofast_spam_protect_login', isset($_POST['protect_login']) ? 1 : 0);
            
            // New extended options
            update_option('ofast_spam_force_all_forms', isset($_POST['force_all_forms']) ? 1 : 0);
            update_option('ofast_spam_honeypot_enabled', isset($_POST['honeypot_enabled']) ? 1 : 0);
            update_option('ofast_spam_protect_woocommerce', isset($_POST['protect_woocommerce']) ? 1 : 0);
            update_option('ofast_spam_protect_tutor', isset($_POST['protect_tutor']) ? 1 : 0);

            // Save Math CAPTCHA settings
            if (class_exists('Ofast_X_Math_Captcha')) {
                Ofast_X_Math_Captcha::save_settings($_POST);
            }

            // Save Turnstile keys
            if (!empty($_POST['turnstile_site_key'])) {
                update_option('ofast_turnstile_site_key', sanitize_text_field($_POST['turnstile_site_key']));
            }
            if (!empty($_POST['turnstile_secret_key']) && strpos($_POST['turnstile_secret_key'], '••') === false) {
                $secret = $_POST['turnstile_secret_key'];
                if (class_exists('Ofast_X_Security_Hardening')) {
                    $secret = Ofast_X_Security_Hardening::encrypt_option($secret);
                } else {
                    $secret = sanitize_text_field($secret);
                }
                update_option('ofast_turnstile_secret_key', $secret);
            }

            // Save reCAPTCHA keys
            if (!empty($_POST['recaptcha_site_key'])) {
                update_option('ofast_recaptcha_site_key', sanitize_text_field($_POST['recaptcha_site_key']));
            }
            if (!empty($_POST['recaptcha_secret_key'])) {
                $secret = sanitize_text_field($_POST['recaptcha_secret_key']);
                if (class_exists('Ofast_X_Security_Hardening')) {
                    $secret = Ofast_X_Security_Hardening::encrypt_option($secret);
                }
                update_option('ofast_recaptcha_secret_key', $secret);
            }
            if (isset($_POST['recaptcha_threshold'])) {
                update_option('ofast_recaptcha_threshold', floatval($_POST['recaptcha_threshold']));
            }

            // Redirect with success flag
            wp_redirect(add_query_arg('settings_saved', '1', wp_get_referer()));
            exit;
        }

        // Get Options
        $active_provider = get_option('ofast_spam_provider', 'turnstile');
        $recaptcha_site_key = get_option('ofast_recaptcha_site_key', '');
        $recaptcha_threshold = get_option('ofast_recaptcha_threshold', 0.5);
        $protect_comments = get_option('ofast_spam_protect_comments', false);
        $protect_cf7 = get_option('ofast_spam_protect_cf7', false);
        $protect_login = get_option('ofast_spam_protect_login', false);
        
        // New extended options
        $force_all_forms = get_option('ofast_spam_force_all_forms', false);
        $honeypot_enabled = get_option('ofast_spam_honeypot_enabled', true);
        $protect_woocommerce = get_option('ofast_spam_protect_woocommerce', false);
        $protect_tutor = get_option('ofast_spam_protect_tutor', false);

        // Current Tab
        $default_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';

        // Show toast
        if (isset($_GET['settings_saved'])) {
            echo Ofast_X_Toast::render('Settings saved successfully!', 'success');
        }
?>
        <style>
            /* Colors */
            :root {
                --ofast-primary: #6366f1;
            }

            .ofast-tabs-nav {
                display: flex;
                flex-wrap: nowrap;
                gap: 8px;
                margin-bottom: 25px;
                padding: 10px 12px;
                background: #fff;
                border-radius: 12px;
                border: 1px solid rgba(226, 232, 240, 0.6);
                position: sticky;
                top: 47px;
                z-index: 100;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            }
            .ofast-tab {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 12px 20px;
                background: transparent;
                border: none;
                border-radius: 8px;
                color: #64748b;
                font-size: 14px;
                font-weight: 500;
                text-decoration: none;
                cursor: pointer;
                transition: all 0.2s ease;
                flex-shrink: 0;
                white-space: nowrap;
            }
            .ofast-tab:hover {
                background: #fff;
                color: #1e293b;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            }
            .ofast-tab.active {
                background: var(--ofast-primary);
                color: #fff;
                box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
            }
            .ofast-tab .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
                line-height: 16px;
            }
            .ofast-tab-content { display: none; }
            .ofast-tab-content.active { display: block; animation: ofastFadeIn 0.3s ease; }
            
            /* Card Styling */
            .ofast-card {
                background: #fff;
                border-radius: 16px;
                padding: 40px;
                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
                margin-top: 20px;
                border: 1px solid rgba(226, 232, 240, 0.6);
            }
            .ofast-card h2 { margin-top: 0; }

            /* Toggle Switch */
            .ofast-toggle {
                position: relative;
                display: inline-block;
                width: 44px;
                height: 24px;
                vertical-align: middle;
                margin-right: 10px;
            }
            .ofast-toggle input { 
                opacity: 0; 
                width: 0; 
                height: 0; 
            }
            .ofast-slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #cbd5e1;
                transition: .4s;
                border-radius: 34px;
            }
            .ofast-slider:before {
                position: absolute;
                content: "";
                height: 18px;
                width: 18px;
                left: 3px;
                bottom: 3px;
                background-color: white;
                transition: .4s;
                border-radius: 50%;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            input:checked + .ofast-slider {
                background-color: var(--ofast-primary);
            }
            input:focus + .ofast-slider {
                box-shadow: 0 0 1px var(--ofast-primary);
            }
            input:checked + .ofast-slider:before {
                transform: translateX(20px);
            }
            
            /* Button Override */
            .button.button-primary {
                background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%) !important;
                border-color: #6366f1 !important;
                text-shadow: none !important;
                box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3) !important;
                transition: all 0.3s ease !important;
                padding-top: 10px !important;
                padding-bottom: 10px !important;
                height: auto !important;
            }
            .button.button-primary:hover {
                background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%) !important;
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4) !important;
            }
            .button.button-primary:active {
                transform: translateY(0);
            }
            
            @keyframes ofastFadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

            /* Header Styles */
            .ofast-header {
                display: flex;
                align-items: center;
                gap: 20px;
                background: #fff;
                padding: 25px 30px;
                border-radius: 12px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                margin-bottom: 30px;
                margin-top: 20px;
            }
            .ofast-header-icon {
                width: 56px;
                height: 56px;
                background: #fff;
                border: 1px solid #e2e8f0;
                box-shadow: 0 2px 4px rgba(0,0,0,0.02);
                border-radius: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .ofast-header-icon .dashicons {
                font-size: 28px;
                width: 28px;
                height: 28px;
                color: #6366f1;
            }
            .ofast-header-content h1 {
                margin: 0 0 5px 0;
                font-size: 24px;
                font-weight: 700;
                color: #1e293b;
                display: block;
                padding: 0;
            }
            .ofast-header-content p {
                margin: 0;
                color: #64748b;
                font-size: 14px;
            }
        </style>

        <div class="wrap">
            <!-- Header -->
            <div class="ofast-header">
                <div class="ofast-header-icon">
                    <span class="dashicons dashicons-shield"></span>
                </div>
                <div class="ofast-header-content">
                    <h1>Spam Protection</h1>
                    <p>Unified settings for Cloudflare Turnstile, Google reCAPTCHA, and Math CAPTCHA.</p>
                </div>
            </div>

            <form method="post">
                <?php wp_nonce_field('ofast_recaptcha_save', 'recaptcha_nonce'); ?>

                <nav class="ofast-tabs-nav" id="spam-tabs-nav">
                    <a href="#" class="ofast-tab <?php echo $default_tab === 'general' ? 'active' : ''; ?>" data-tab="general">
                        <span class="dashicons dashicons-shield"></span>
                        General
                    </a>
                    <a href="#" class="ofast-tab <?php echo $default_tab === 'turnstile' ? 'active' : ''; ?>" data-tab="turnstile">
                        <span class="dashicons dashicons-cloud"></span>
                        Turnstile
                    </a>
                    <a href="#" class="ofast-tab <?php echo $default_tab === 'math_captcha' ? 'active' : ''; ?>" data-tab="math_captcha">
                        <span class="dashicons dashicons-calculator"></span>
                        Math CAPTCHA
                    </a>
                    <a href="#" class="ofast-tab <?php echo $default_tab === 'recaptcha' ? 'active' : ''; ?>" data-tab="recaptcha">
                        <span class="dashicons dashicons-google"></span>
                        Google reCAPTCHA
                    </a>
                </nav>

                <!-- General Tab -->
                <div id="tab-general" class="ofast-tab-content<?php echo $default_tab === 'general' ? ' active' : ''; ?>">
                    <div class="ofast-card">
                        <h2>Active Provider</h2>
                        <p class="description">Select which spam protection service to use on your site.</p>
                        <table class="form-table">
                            <tr>
                                <td>
                                            <fieldset>
                                        <div style="margin-bottom: 20px;">
                                            <label class="ofast-toggle">
                                                <input type="radio" name="spam_provider" value="turnstile" <?php checked($active_provider, 'turnstile'); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                            <span style="vertical-align: middle; font-weight: 600;">Cloudflare Turnstile</span> <span class="description" style="vertical-align: middle;">(Recommended)</span>
                                            <p class="description" style="margin-left: 54px; margin-top: 5px;">Free, privacy-friendly, invisible challenge.</p>
                                        </div>
                                        
                                        <div style="margin-bottom: 20px;">
                                            <label class="ofast-toggle">
                                                <input type="radio" name="spam_provider" value="math_captcha" <?php checked($active_provider, 'math_captcha'); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                            <span style="vertical-align: middle; font-weight: 600;">Math CAPTCHA</span> <span class="description" style="vertical-align: middle; color: #10b981;">(No API keys needed)</span>
                                            <p class="description" style="margin-left: 54px; margin-top: 5px;">Simple arithmetic challenge (e.g. 5 + 3 = ?). Works offline.</p>
                                        </div>
                                        
                                        <div style="margin-bottom: 20px;">
                                            <label class="ofast-toggle">
                                                <input type="radio" name="spam_provider" value="recaptcha_v2" <?php checked($active_provider, 'recaptcha_v2'); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                            <span style="vertical-align: middle; font-weight: 600;">Google reCAPTCHA v2</span>
                                            <p class="description" style="margin-left: 54px; margin-top: 5px;">Traditional "I'm not a robot" checkbox.</p>
                                        </div>

                                        <div style="margin-bottom: 0;">
                                            <label class="ofast-toggle">
                                                <input type="radio" name="spam_provider" value="recaptcha_v3" <?php checked($active_provider, 'recaptcha_v3'); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                            <span style="vertical-align: middle; font-weight: 600;">Google reCAPTCHA v3</span>
                                            <p class="description" style="margin-left: 54px; margin-top: 5px;">Invisible scoring system.</p>
                                        </div>
                                    </fieldset>
                                </td>
                            </tr>
                        </table>

                        <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

                        <h2>Where to Apply</h2>
                        <table class="form-table">
                            <tr>
                                <th>WordPress Comments</th>
                                <td>
                                    <label class="ofast-toggle">
                                        <input type="checkbox" name="protect_comments" value="1" <?php checked($protect_comments); ?>>
                                        <span class="ofast-slider"></span>
                                    </label>
                                    <span class="description" style="vertical-align: middle;">Protect blog post comments</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Contact Form 7</th>
                                <td>
                                    <label class="ofast-toggle">
                                        <input type="checkbox" name="protect_cf7" value="1" <?php checked($protect_cf7); ?>>
                                        <span class="ofast-slider"></span>
                                    </label>
                                    <span class="description" style="vertical-align: middle;">Protect CF7 forms</span>
                                </td>
                            </tr>
                            <tr>
                                <th>WordPress Login</th>
                                <td>
                                    <label class="ofast-toggle">
                                        <input type="checkbox" name="protect_login" value="1" <?php checked($protect_login); ?>>
                                        <span class="ofast-slider"></span>
                                    </label>
                                    <span class="description" style="vertical-align: middle;">Protect login page</span>
                                </td>
                            </tr>
                            <tr>
                                <th>WooCommerce</th>
                                <td>
                                    <label class="ofast-toggle">
                                        <input type="checkbox" name="protect_woocommerce" value="1" <?php checked($protect_woocommerce); ?>>
                                        <span class="ofast-slider"></span>
                                    </label>
                                    <span class="description" style="vertical-align: middle;">Protect WooCommerce login & registration</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Tutor LMS</th>
                                <td>
                                    <span class="description" style="color: #94a3b8;">
                                        <span class="dashicons dashicons-info-outline" style="font-size: 16px; vertical-align: text-bottom;"></span>
                                        Requires <strong>Tutor LMS Pro</strong> - Use their built-in CAPTCHA settings
                                    </span>
                                </td>
                            </tr>
                        </table>
                        
                        <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">
                        
                        <h2>Advanced Protection</h2>
                        <table class="form-table">
                            <tr>
                                <th>
                                    <span style="color: #6366f1;"> </span> Force All Forms
                                </th>
                                <td>
                                    <label class="ofast-toggle">
                                        <input type="checkbox" name="force_all_forms" value="1" <?php checked($force_all_forms); ?>>
                                        <span class="ofast-slider"></span>
                                    </label>
                                    <span class="description" style="vertical-align: middle;"><strong>Universal protection</strong> - Injects into ALL login/registration forms (WooCommerce, Tutor LMS, BuddyPress, MemberPress, etc.)</span>
                                    <p class="description" style="margin-top: 8px; color: #666;">Uses JavaScript injection to add protection to any form, even from plugins that don't have native integration.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>
                                    <span style="color: #10b981;"> </span> Honeypot Fallback
                                </th>
                                <td>
                                    <label class="ofast-toggle">
                                        <input type="checkbox" name="honeypot_enabled" value="1" <?php checked($honeypot_enabled); ?>>
                                        <span class="ofast-slider"></span>
                                    </label>
                                    <span class="description" style="vertical-align: middle;">Enable honeypot as backup protection</span>
                                    <p class="description" style="margin-top: 8px; color: #666;">Adds invisible fields that only bots fill. Works when Turnstile/reCAPTCHA fails (network issues, blocked, etc.)</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Turnstile Tab -->
                <div id="tab-turnstile" class="ofast-tab-content<?php echo $default_tab === 'turnstile' ? ' active' : ''; ?>">
                    <div class="ofast-card">
                        <h2>Cloudflare Turnstile Settings</h2>
                        <?php
                        if (class_exists('Ofast_X_Turnstile')) {
                            // We need to capture the output of render_settings_form if it echoes, 
                            // but since we want it inside our card, we can just call it here.
                            // Assuming it outputs standard form fields.
                            Ofast_X_Turnstile::get_instance()->render_settings_form();
                        } else {
                            echo '<p>Turnstile module is not loaded.</p>';
                        }
                        ?>
                    </div>
                </div>

                <!-- Math CAPTCHA Tab -->
                <div id="tab-math_captcha" class="ofast-tab-content<?php echo $default_tab === 'math_captcha' ? ' active' : ''; ?>">
                    <div class="ofast-card">
                        <h2>Math CAPTCHA Settings</h2>
                        <?php
                        if (class_exists('Ofast_X_Math_Captcha')) {
                            Ofast_X_Math_Captcha::get_instance()->render_settings_form();
                        } else {
                            echo '<p>Math CAPTCHA module is not loaded.</p>';
                        }
                        ?>
                    </div>
                </div>

                <!-- reCAPTCHA Tab -->
                <div id="tab-recaptcha" class="ofast-tab-content<?php echo $default_tab === 'recaptcha' ? ' active' : ''; ?>">
                    <div class="ofast-card">
                        <h2>Google reCAPTCHA Settings</h2>
                        <p class="description">Get your keys from <a href="https://www.google.com/recaptcha/admin" target="_blank">Google reCAPTCHA Admin</a></p>

                        <table class="form-table">
                            <tr>
                                <th>Site Key</th>
                                <td>
                                    <input type="text" name="recaptcha_site_key" value="<?php echo esc_attr($recaptcha_site_key); ?>" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th>Secret Key</th>
                                <td>
                                    <input type="password" name="recaptcha_secret_key" value="" class="regular-text" placeholder="<?php echo $recaptcha_site_key ? '(encrypted - enter to change)' : ''; ?>">
                                </td>
                            </tr>
                            <tr>
                                <th>Score Threshold (v3)</th>
                                <td>
                                    <input type="number" name="recaptcha_threshold" value="<?php echo esc_attr($recaptcha_threshold); ?>" min="0" max="1" step="0.1" style="width:80px;">
                                    <p class="description">0.0 (bot) to 1.0 (human). Default: 0.5</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="ofast-form-actions" style="margin-top: 30px; padding-top: 20px;">
                    <button type="submit" name="ofast_save_recaptcha" class="button button-primary button-large" style="min-width: 150px;">Save Changes</button>
                </div>
            </form>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Tab Switching
                $('.ofast-tab').on('click', function(e) {
                    e.preventDefault();
                    var target = $(this).data('tab');
                    
                    // Update classes
                    $('.ofast-tab').removeClass('active');
                    $(this).addClass('active');
                    
                    $('.ofast-tab-content').removeClass('active');
                    $('#tab-' + target).addClass('active');
                    
                    // Update URL
                    var url = new URL(window.location);
                    url.searchParams.set('tab', target);
                    window.history.pushState({}, '', url);
                });

                // Handle back button
                window.onpopstate = function() {
                    var urlParams = new URLSearchParams(window.location.search);
                    var tab = urlParams.get('tab') || 'general';
                    $('.ofast-tab[data-tab="' + tab + '"]').click();
                };
            });
        </script>
<?php
    }

    /**
     * Get active provider
     */
    public function get_active_provider()
    {
        return get_option('ofast_spam_provider', 'turnstile');
    }

    /**
     * Check if any spam protection is configured
     */
    public function is_configured()
    {
        $provider = $this->get_active_provider();

        switch ($provider) {
            case 'turnstile':
                if (class_exists('Ofast_X_Turnstile')) {
                    return Ofast_X_Turnstile::get_instance()->is_configured();
                }
                return false;

            case 'math_captcha':
                // Math CAPTCHA is always configured - no API keys needed
                return true;

            case 'recaptcha_v2':
            case 'recaptcha_v3':
                $site_key = get_option('ofast_recaptcha_site_key', '');
                $secret_key = get_option('ofast_recaptcha_secret_key', '');
                return !empty($site_key) && !empty($secret_key);

            default:
                return false;
        }
    }

    /**
     * Verify spam protection token
     */
    public function verify($token)
    {
        $provider = $this->get_active_provider();

        switch ($provider) {
            case 'turnstile':
                if (class_exists('Ofast_X_Turnstile')) {
                    return Ofast_X_Turnstile::get_instance()->verify($token);
                }
                return array('success' => false, 'error' => 'Turnstile not available');

            case 'math_captcha':
                if (class_exists('Ofast_X_Math_Captcha')) {
                    return Ofast_X_Math_Captcha::get_instance()->verify();
                }
                return array('success' => false, 'error' => 'Math CAPTCHA not available');

            case 'recaptcha_v2':
            case 'recaptcha_v3':
                return $this->verify_recaptcha($token);

            default:
                return array('success' => true);
        }
    }

    /**
     * Verify reCAPTCHA token
     */
    private function verify_recaptcha($token)
    {
        $secret_key = $this->get_decrypted_recaptcha_secret();

        if (empty($secret_key)) {
            return array('success' => false, 'error' => 'reCAPTCHA not configured');
        }

        $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', array(
            'body' => array(
                'secret' => $secret_key,
                'response' => $token,
                'remoteip' => $this->get_client_ip()
            )
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['success'])) {
            $error = isset($body['error-codes']) ? implode(', ', $body['error-codes']) : 'Verification failed';
            return array('success' => false, 'error' => $error);
        }

        // For v3, check score threshold
        if ($this->get_active_provider() === 'recaptcha_v3') {
            $threshold = floatval(get_option('ofast_recaptcha_threshold', 0.5));
            $score = isset($body['score']) ? floatval($body['score']) : 0;

            if ($score < $threshold) {
                return array('success' => false, 'error' => 'Score too low: ' . $score);
            }
        }

        return array('success' => true);
    }

    /**
     * Get decrypted reCAPTCHA secret key
     */
    private function get_decrypted_recaptcha_secret()
    {
        $encrypted = get_option('ofast_recaptcha_secret_key', '');
        if (empty($encrypted)) {
            return '';
        }

        if (class_exists('Ofast_X_Security_Hardening')) {
            return Ofast_X_Security_Hardening::decrypt_option($encrypted);
        }

        return $encrypted;
    }

    /**
     * Get client IP
     */
    private function get_client_ip()
    {
        $ip_headers = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        );

        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }
}
