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
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

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
            add_filter('wpcf7_spam', array($this, 'check_cf7_spam'), 20);

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
            add_filter('authenticate', array($this, 'verify_login'), 9, 3);
        }

        // Tutor LMS registration protection
        $protect_tutor_reg = get_option('ofast_spam_protect_tutor_registration', false);
        if ($protect_tutor_reg && $this->is_configured() && !$this->is_tutor_pro_spam_active()) {
            // Set a flag so we know we're on a Tutor registration page
            add_action('tutor_before_student_reg_form', array($this, 'flag_tutor_registration_page'));
            add_action('tutor_before_instructor_reg_form', array($this, 'flag_tutor_registration_page'));

            // Render widget via register_form hook (fires INSIDE the form, above submit)
            add_action('register_form', array($this, 'render_tutor_registration_widget'));

            // Validate on registration submission via WordPress registration_errors filter
            add_filter('registration_errors', array($this, 'verify_tutor_registration'), 10, 3);

            // Enqueue scripts on frontend
            add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_script'));
        }
    }

    /**
     * Enqueue frontend script for Turnstile
     */
    public function enqueue_frontend_script()
    {
        $provider = $this->get_active_provider();
        if ($provider === 'turnstile' && class_exists('Ofast_X_Turnstile')) {
            Ofast_X_Turnstile::enqueue_script();
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
        }
        elseif ($provider === 'math_captcha' && class_exists('Ofast_X_Math_Captcha')) {
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

        $result = $this->verify_with_turnstile_honeypot_fallback($provider, $token);

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
            if (class_exists('Ofast_X_Honeypot') && get_option('ofast_spam_honeypot_enabled', true)) {
                $widget .= Ofast_X_Honeypot::get_field_html();
            }
            $widget .= '</div>';
        }
        elseif ($provider === 'math_captcha' && class_exists('Ofast_X_Math_Captcha')) {
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

        $verify = $this->verify_with_turnstile_honeypot_fallback($provider, $token);

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
        }
        elseif ($provider === 'math_captcha' && class_exists('Ofast_X_Math_Captcha')) {
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
            Ofast_X_Turnstile::enqueue_script();
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

        // FIX: No early return on credential errors — spam check must always run
        // at priority 9 (before WP credential check at 20)

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
        }
        else {
            // Turnstile/reCAPTCHA use cf-turnstile-response or g-recaptcha-response
            $token = isset($_POST['cf-turnstile-response']) ? sanitize_text_field($_POST['cf-turnstile-response']) : '';
            if (empty($token)) {
                $token = isset($_POST['g-recaptcha-response']) ? sanitize_text_field($_POST['g-recaptcha-response']) : '';
            }

            // If no token at all, block immediately (prevents bypass by removing field)
            if (empty($token)) {
                if (!$this->should_try_turnstile_honeypot_fallback($provider, $token)) {
                    return new WP_Error(
                        'spam_protection_failed',
                        '<strong>Security verification required.</strong> Please complete the spam protection challenge.'
                        );
                }
            }
        }

        // Call the unified verify method (handles all providers)
        $result = $this->verify_with_turnstile_honeypot_fallback($provider, isset($token) ? $token : '');

        if (!$result['success']) {
            // Log failed verification attempts
            if (function_exists('error_log')) {
                error_log('Ofast Spam Protection: Login verification failed from IP ' . $this->get_client_ip() . ' - ' . ($result['error'] ?? 'Unknown error'));
            }

            // FIX: Fire wp_login_failed so lockout plugins record this attempt
            do_action('wp_login_failed', $username, new WP_Error('spam_protection_failed', $result['error'] ?? ''));

            return new WP_Error(
                'spam_protection_failed',
                '<strong>Spam protection failed:</strong> ' . esc_html($result['error'] ?? 'Please complete the verification.')
                );
        }

        return $user;
    }

    /**
     * Determine whether Turnstile can safely fall back to the honeypot field.
     */
    private function should_try_turnstile_honeypot_fallback($provider, $token, $result = array())
    {
        if ($provider !== 'turnstile') {
            return false;
        }

        if (!class_exists('Ofast_X_Honeypot') || !get_option('ofast_spam_honeypot_enabled', true)) {
            return false;
        }

        if (!Ofast_X_Honeypot::has_submitted_field()) {
            return false;
        }

        if (empty($token)) {
            return true;
        }

        return isset($result['code']) && $result['code'] === 'api_error';
    }

    /**
     * Use the honeypot field as a fallback when Turnstile could not verify.
     */
    private function verify_with_turnstile_honeypot_fallback($provider, $token)
    {
        $result = $this->verify($token);

        if ($result['success']) {
            return $result;
        }

        if (!$this->should_try_turnstile_honeypot_fallback($provider, $token, $result)) {
            return $result;
        }

        $honeypot_result = Ofast_X_Honeypot::verify();
        if ($honeypot_result['success']) {
            $honeypot_result['fallback'] = true;
            return $honeypot_result;
        }

        return $result;
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
        if (isset($_POST['ofast_save_recaptcha']) && wp_verify_nonce(wp_unslash($_POST['recaptcha_nonce'] ?? ''), 'ofast_recaptcha_save')) {
            $secret_save_failed = false;
            update_option('ofast_spam_provider', sanitize_text_field($_POST['spam_provider']));

            // Save protection settings
            update_option('ofast_spam_protect_comments', isset($_POST['protect_comments']) ? 1 : 0);
            update_option('ofast_spam_protect_cf7', isset($_POST['protect_cf7']) ? 1 : 0);
            update_option('ofast_spam_protect_login', isset($_POST['protect_login']) ? 1 : 0);

            // New extended options
            // Server-side Pro guard: force honeypot always on, block Pro settings for free users
            if ( ! ofast_toolkit_is_pro() ) {
                update_option('ofast_spam_honeypot_enabled', 1);
            } else {
                update_option('ofast_spam_force_all_forms', isset($_POST['force_all_forms']) ? 1 : 0);
                update_option('ofast_spam_honeypot_enabled', isset($_POST['honeypot_enabled']) ? 1 : 0);
                update_option('ofast_spam_fail_open', isset($_POST['spam_fail_open']) ? 1 : 0);
            }
            update_option('ofast_spam_protect_woocommerce', (ofast_toolkit_is_pro() && isset($_POST['protect_woocommerce'])) ? 1 : 0);
            update_option('ofast_spam_protect_tutor', isset($_POST['protect_tutor']) ? 1 : 0);
            update_option('ofast_spam_protect_tutor_registration', isset($_POST['protect_tutor_registration']) ? 1 : 0);

            // Save Math CAPTCHA settings
            if (class_exists('Ofast_X_Math_Captcha')) {
                Ofast_X_Math_Captcha::save_settings($_POST);
            }

            // Save Turnstile keys
            if (!empty($_POST['turnstile_site_key'])) {
                update_option('ofast_turnstile_site_key', sanitize_text_field(wp_unslash($_POST['turnstile_site_key'])));
            }

            $turnstile_secret = sanitize_text_field(wp_unslash($_POST['turnstile_secret_key'] ?? ''));
            if ($turnstile_secret !== '') {
                $turnstile_site_key = sanitize_text_field(wp_unslash($_POST['turnstile_site_key'] ?? get_option('ofast_turnstile_site_key', '')));
                $saved = class_exists('Ofast_X_Turnstile') ?Ofast_X_Turnstile::save_keys($turnstile_site_key, $turnstile_secret) : false;

                if (!$saved) {
                    $secret_save_failed = true;
                }
            }

            // Save reCAPTCHA keys
            if (!empty($_POST['recaptcha_site_key'])) {
                update_option('ofast_recaptcha_site_key', sanitize_text_field(wp_unslash($_POST['recaptcha_site_key'])));
            }

            $recaptcha_secret = sanitize_text_field(wp_unslash($_POST['recaptcha_secret_key'] ?? ''));
            if ($recaptcha_secret !== '') {
                if (class_exists('Ofast_X_Security_Hardening')) {
                    $encrypted = Ofast_X_Security_Hardening::encrypt_option($recaptcha_secret);
                    if ($encrypted !== false) {
                        update_option('ofast_recaptcha_secret_key', $encrypted);
                    }
                    else {
                        $secret_save_failed = true;
                    }
                }
                else {
                    $secret_save_failed = true;
                }
            }
            if (isset($_POST['recaptcha_threshold'])) {
                update_option('ofast_recaptcha_threshold', floatval($_POST['recaptcha_threshold']));
            }

            // Redirect with success flag
            $redirect_args = $secret_save_failed ? array('settings_error' => 'secret_save_failed') : array('settings_saved' => '1');
            wp_safe_redirect(add_query_arg($redirect_args, wp_get_referer()));
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
        $protect_tutor_registration = get_option('ofast_spam_protect_tutor_registration', false);
        $fail_open = get_option('ofast_spam_fail_open', false);
        $tutor_pro_spam_active = $this->is_tutor_pro_spam_active();

        // Current Tab
        $default_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';

        // Show toast
        if (isset($_GET['settings_saved'])) {
            echo Ofast_X_Toast::render('Settings saved successfully!', 'success');
        }
        elseif (isset($_GET['settings_error']) && $_GET['settings_error'] === 'secret_save_failed') {
            echo Ofast_X_Toast::render('Other settings were saved, but one or more secret keys could not be stored securely. Check WordPress security keys/OpenSSL and re-enter the secret key.', 'error');
        }

        if (class_exists('Ofast_X_Dropdown')) {
            echo Ofast_X_Dropdown::render_assets();
        }
?>

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
                                    <?php if (ofast_toolkit_is_pro()): ?>
                                        <label class="ofast-toggle">
                                            <input type="checkbox" name="protect_woocommerce" value="1" <?php checked($protect_woocommerce); ?>>
                                            <span class="ofast-slider"></span>
                                        </label>
                                        <span class="description" style="vertical-align: middle;">Protect WooCommerce login & registration</span>
                                    <?php else: ?>
                                        <label class="ofast-toggle">
                                            <input type="checkbox" disabled>
                                            <span class="ofast-slider"></span>
                                        </label>
                                        <span class="description" style="vertical-align: middle;">Protect WooCommerce login & registration</span>
                                        <span style="display: inline-flex; align-items: center; gap: 3px; margin-left: 8px; padding: 2px 8px; background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; font-size: 11px; font-weight: 600; border-radius: 4px; vertical-align: middle;">
                                            <span class="dashicons dashicons-lock" style="font-size: 11px; width: 11px; height: 11px;"></span> PRO
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Tutor LMS Registration</th>
                                <td>
                                    <?php if ($tutor_pro_spam_active): ?>
                                        <label class="ofast-toggle">
                                            <input type="checkbox" name="protect_tutor_registration" value="1" disabled>
                                            <span class="ofast-slider"></span>
                                        </label>
                                        <span class="description" style="vertical-align: middle; color: #f59e0b;">
                                            <span class="dashicons dashicons-warning" style="font-size: 16px; vertical-align: text-bottom; color: #f59e0b;"></span>
                                            <strong>Tutor Pro's spam protection is active.</strong> Ofast X auto-skips to avoid duplicate CAPTCHAs.
                                        </span>
                                        <p class="description" style="margin-top: 8px; color: #666;">Disable Tutor Pro's Fraud Protection in <em>Tutor LMS → Settings → Authentication</em> to use Ofast X instead.</p>
                                    <?php elseif (!class_exists('\TUTOR\Tutor')): ?>
                                        <span class="description" style="color: #94a3b8;">
                                            <span class="dashicons dashicons-info-outline" style="font-size: 16px; vertical-align: text-bottom;"></span>
                                            Tutor LMS is not installed or active.
                                        </span>
                                    <?php else: ?>
                                        <label class="ofast-toggle">
                                            <input type="checkbox" name="protect_tutor_registration" value="1" <?php checked($protect_tutor_registration); ?>>
                                            <span class="ofast-slider"></span>
                                        </label>
                                        <span class="description" style="vertical-align: middle;">Protect student & instructor registration forms</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        
                        <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">
                        
                        <?php if ( ! ofast_toolkit_is_pro() ): ?>
                        <div style="position: relative; overflow: hidden; border-radius: 8px;">
                            <div style="position: absolute; inset: 0; z-index: 10; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; background: rgba(255,255,255,0.35); backdrop-filter: blur(2.5px); -webkit-backdrop-filter: blur(2.5px); border-radius: 8px;">
                                <div style="width: 44px; height: 44px; background: rgba(99,102,241,0.12); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                    <span class="dashicons dashicons-lock" style="color: #6366f1; font-size: 22px; width: 22px; height: 22px;"></span>
                                </div>
                                <div style="font-size: 15px; font-weight: 600; color: #1e293b;">Pro Feature</div>
                                <div style="font-size: 13px; color: #64748b; text-align: center; max-width: 320px; line-height: 1.5;">Universal form injection, honeypot controls, and fail-open mode for provider outages.</div>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=ofast-license')); ?>" style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 24px; background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; font-size: 13px; font-weight: 600; border-radius: 8px; text-decoration: none; box-shadow: 0 4px 12px rgba(99,102,241,0.3);">
                                    <span class="dashicons dashicons-star-filled" style="font-size:14px;width:14px;height:14px;"></span> Upgrade to Pro
                                </a>
                            </div>
                        <?php endif; ?>

                        <h2>Advanced Protection <?php ofast_toolkit_pro_badge(); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th>
                                    <span style="color: #6366f1;"> </span> Force All Forms
                                </th>
                                <td>
                                    <label class="ofast-toggle">
                                        <input type="checkbox" name="force_all_forms" value="1" <?php checked($force_all_forms); ?> <?php ofast_toolkit_pro_disabled(); ?>>
                                        <span class="ofast-slider"></span>
                                    </label>
                                    <span class="description" style="vertical-align: middle;"><strong>Universal protection</strong> - Injects into ALL login/registration forms (WooCommerce, Tutor LMS, BuddyPress, MemberPress, etc.) <?php ofast_toolkit_pro_badge(); ?></span>
                                    <p class="description" style="margin-top: 8px; color: #666;">Uses JavaScript injection to add protection to any form, even from plugins that don't have native integration.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>
                                    <span style="color: #10b981;"> </span> Honeypot Fallback
                                </th>
                                <td>
                                    <label class="ofast-toggle">
                                        <input type="checkbox" name="honeypot_enabled" value="1" <?php checked($honeypot_enabled); ?> <?php ofast_toolkit_pro_disabled(); ?>>
                                        <span class="ofast-slider"></span>
                                    </label>
                                    <span class="description" style="vertical-align: middle;">Invisible honeypot fields that catch bots <?php ofast_toolkit_pro_badge(); ?></span>
                                    <p class="description" style="margin-top: 8px; color: #666;">Adds invisible fields that only bots fill. Works when Turnstile/reCAPTCHA fails (network issues, blocked, etc.)</p>
                                </td>
                            </tr>
                            <tr>
                                <th>
                                    <span style="color: #f59e0b;"> </span> Fail Open on Provider Outage
                                </th>
                                <td>
                                    <label class="ofast-toggle">
                                        <input type="checkbox" name="spam_fail_open" value="1" <?php checked($fail_open); ?> <?php ofast_toolkit_pro_disabled(); ?>>
                                        <span class="ofast-slider"></span>
                                    </label>
                                    <span class="description" style="vertical-align: middle;">Allow submissions when provider API is unreachable <?php ofast_toolkit_pro_badge(); ?></span>
                                    <p class="description" style="margin-top: 8px; color: #666;">
                                        If disabled, forms will be blocked when Turnstile/reCAPTCHA cannot be verified due to network/API errors.
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <?php if ( ! ofast_toolkit_is_pro() ): ?></div><?php endif; ?>
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
        }
        else {
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
        }
        else {
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
                                    <input type="text" name="recaptcha_site_key" value="<?php echo esc_attr($recaptcha_site_key); ?>" class="regular-text" style="border-radius: 8px;">
                                </td>
                            </tr>
                            <tr>
                                <th>Secret Key</th>
                                <td>
                                    <input type="password" name="recaptcha_secret_key" value="" class="regular-text" placeholder="<?php echo $recaptcha_site_key ? '(encrypted - enter to change)' : ''; ?>" style="border-radius: 8px;">
                                </td>
                            </tr>
                            <tr>
                                <th>Score Threshold (v3)</th>
                                <td>
                                    <input type="number" name="recaptcha_threshold" value="<?php echo esc_attr($recaptcha_threshold); ?>" min="0" max="1" step="0.1" style="width:80px; border-radius: 8px;">
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


<?php
    }

    /**
     * Enqueue admin CSS/JS for the spam protection settings page
     */
    public function enqueue_admin_scripts($hook)
    {
        if (!isset($_GET['page']) || $_GET['page'] !== 'ofast-spam-protection') {
            return;
        }

        $module_url = plugin_dir_url(__FILE__);

        wp_enqueue_style(
            'ofast-spam-protection-admin',
            $module_url . 'assets/css/spam-protection.css',
            array(),
            OFAST_X_VERSION
        );

        wp_enqueue_script(
            'ofast-spam-protection-admin',
            $module_url . 'assets/js/spam-protection.js',
            array('jquery'),
            OFAST_X_VERSION,
            true
        );
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
                $secret_key = $this->get_decrypted_recaptcha_secret();
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
            if ($this->should_fail_open()) {
                return array('success' => true, 'skipped' => true, 'reason' => 'api_error');
            }
            return array('success' => false, 'error' => 'reCAPTCHA verification failed. Please try again.');
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
     * Should we allow submissions when provider API is unavailable.
     */
    private function should_fail_open()
    {
        $fail_open = get_option('ofast_spam_fail_open', false);
        return (bool) apply_filters('ofast_spam_fail_open', $fail_open);
    }

    /**
     * Get decrypted reCAPTCHA secret key
     */
    private function get_decrypted_recaptcha_secret()
    {
        $stored_secret = get_option('ofast_recaptcha_secret_key', '');
        if (empty($stored_secret)) {
            return '';
        }

        if (class_exists('Ofast_X_Security_Hardening')) {
            $decrypted = Ofast_X_Security_Hardening::decrypt_option($stored_secret);
            if (!empty($decrypted)) {
                return $decrypted;
            }

            if ($this->is_legacy_plaintext_recaptcha_secret($stored_secret)) {
                $encrypted = Ofast_X_Security_Hardening::encrypt_option($stored_secret);
                if ($encrypted !== false) {
                    update_option('ofast_recaptcha_secret_key', $encrypted);
                    return $stored_secret;
                }
                else {
                    Ofast_X_Logger::warning(
                        'Failed to encrypt legacy reCAPTCHA secret. Migration failed.',
                        array('redacted_secret' => substr($stored_secret, 0, 10) . '...')
                    );
                    update_option('ofast_recaptcha_migration_failed', true);
                    return '';
                }
            }

            return '';
        }

        return '';
    }

    /**
     * Detect legacy plaintext reCAPTCHA secrets so they can be migrated.
     */
    private function is_legacy_plaintext_recaptcha_secret($value)
    {
        if (!is_string($value) || $value === '') {
            return false;
        }

        if (class_exists('Ofast_X_Security_Hardening') && Ofast_X_Security_Hardening::looks_like_encrypted_option($value)) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9_-]{20,120}$/', $value) === 1;
    }

      /**
     * Get client IP address.
     *
     * FIX: X-Forwarded-For removed — leftmost IP is client-controlled and
     * trivially spoofable, bypassing rate limits. CF-Connecting-IP now rejects
     * private/reserved IPs. Priority: CF-Connecting-IP → X-Real-IP → REMOTE_ADDR.
     */
    private function get_client_ip()
    {
        // CF-Connecting-IP: reject private/reserved IPs
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        // X-Real-IP: single-value header set by nginx
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = trim($_SERVER['HTTP_X_REAL_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        // REMOTE_ADDR: the only value that truly cannot be spoofed
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '127.0.0.1';
    }

    /**
     * Check if Tutor Pro's built-in spam protection is active for registration.
     */
    private function is_tutor_pro_spam_active()
    {
        if (!function_exists('tutor_utils')) {
            return false;
        }
        if (!class_exists('TutorPro\\Auth\\SpamProtection')) {
            return false;
        }
        $enabled = tutor_utils()->get_option('enable_spam_protection', false);
        if (!$enabled) {
            return false;
        }
        $locations = tutor_utils()->get_option('spam_protection_location', array());
        if (!is_array($locations)) {
            $locations = array();
        }
        return in_array('tutor_registration', $locations, true);
    }

    /**
     * Flag that we are on a Tutor registration page.
     * Called by tutor_before_student_reg_form / tutor_before_instructor_reg_form.
     */
    public function flag_tutor_registration_page()
    {
        $GLOBALS['ofast_tutor_registration'] = true;
    }

    /**
     * Render spam protection widget on Tutor LMS registration forms.
     * Only renders when the Tutor registration flag is set.
     */
    public function render_tutor_registration_widget()
    {
        // Only render on Tutor registration pages (not wp-login.php)
        if (empty($GLOBALS['ofast_tutor_registration'])) {
            return;
        }

        $provider = $this->get_active_provider();
        echo '<div class="ofast-tutor-spam-widget" style="margin: 15px 0;">';
        if ($provider === 'turnstile' && class_exists('Ofast_X_Turnstile')) {
            echo Ofast_X_Turnstile::get_instance()->render_widget('tutor_registration');
        } elseif ($provider === 'math_captcha' && class_exists('Ofast_X_Math_Captcha')) {
            echo Ofast_X_Math_Captcha::get_instance()->render_widget('tutor_registration');
        }
        if (class_exists('Ofast_X_Honeypot') && get_option('ofast_spam_honeypot_enabled', true)) {
            echo Ofast_X_Honeypot::get_field_html();
        }
        echo '</div>';
    }

    /**
     * Verify spam protection on Tutor LMS registration submissions.
     */
    public function verify_tutor_registration($errors, $sanitized_user_login, $user_email)
    {
        $tutor_action = isset($_POST['tutor_action']) ? sanitize_text_field($_POST['tutor_action']) : '';
        if (!in_array($tutor_action, array('tutor_register_student', 'tutor_register_instructor'), true)) {
            return $errors;
        }
        if ($this->is_tutor_pro_spam_active()) {
            return $errors;
        }

        $provider = $this->get_active_provider();

        if ($provider === 'math_captcha') {
            if (class_exists('Ofast_X_Math_Captcha')) {
                $math_result = Ofast_X_Math_Captcha::get_instance()->verify();
                if (!$math_result['success']) {
                    $errors->add('ofast_spam_failed', '<strong>Security verification failed:</strong> ' . esc_html($math_result['error'] ?? 'Please solve the math problem.'));
                }
            }
            return $errors;
        }

        $token = isset($_POST['cf-turnstile-response']) ? sanitize_text_field($_POST['cf-turnstile-response']) : '';
        if (empty($token)) {
            $token = isset($_POST['g-recaptcha-response']) ? sanitize_text_field($_POST['g-recaptcha-response']) : '';
        }

        $result = $this->verify_with_turnstile_honeypot_fallback($provider, $token);
        if (!$result['success']) {
            $errors->add('ofast_spam_failed', '<strong>Security verification failed:</strong> ' . esc_html($result['error'] ?? 'Please complete the spam protection challenge.'));
        }

        return $errors;
    }

}
