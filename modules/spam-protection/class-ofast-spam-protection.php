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
     * Render Turnstile widget in comment form
     */
    public function render_comment_widget()
    {
        $provider = $this->get_active_provider();
        if ($provider === 'turnstile' && class_exists('Ofast_X_Turnstile')) {
            echo '<p class="comment-form-turnstile" style="margin: 10px 0;">';
            echo Ofast_X_Turnstile::get_instance()->render_widget('comment');
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

        $token = isset($_POST['cf-turnstile-response']) ? sanitize_text_field($_POST['cf-turnstile-response']) : '';
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
     * Add Turnstile widget to Contact Form 7
     */
    public function add_cf7_widget($elements)
    {
        $provider = $this->get_active_provider();
        if ($provider === 'turnstile' && class_exists('Ofast_X_Turnstile')) {
            $widget = '<div class="wpcf7-turnstile" style="margin: 15px 0;">';
            $widget .= Ofast_X_Turnstile::get_instance()->render_widget('cf7');
            $widget .= '</div>';

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
        $token = isset($_POST['cf-turnstile-response']) ? sanitize_text_field($_POST['cf-turnstile-response']) : '';
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

        // STRICT: Token is REQUIRED for login when protection is enabled
        $token = isset($_POST['cf-turnstile-response']) ? sanitize_text_field($_POST['cf-turnstile-response']) : '';
        
        // If no token at all, block immediately (prevents bypass by removing field)
        if (empty($token)) {
            return new WP_Error(
                'spam_protection_failed',
                '<strong>Security verification required.</strong> Please complete the spam protection challenge.'
            );
        }

        $result = $this->verify($token);

        if (!$result['success']) {
            // Log failed verification attempts
            if (function_exists('error_log')) {
                error_log('Ofast Spam Protection: Login verification failed from IP ' . $this->get_client_ip());
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

        // Handle reCAPTCHA save
        if (isset($_POST['ofast_save_recaptcha']) && wp_verify_nonce($_POST['recaptcha_nonce'], 'ofast_recaptcha_save')) {
            update_option('ofast_spam_provider', sanitize_text_field($_POST['spam_provider']));

            // Save protection settings
            update_option('ofast_spam_protect_comments', isset($_POST['protect_comments']) ? 1 : 0);
            update_option('ofast_spam_protect_cf7', isset($_POST['protect_cf7']) ? 1 : 0);
            update_option('ofast_spam_protect_login', isset($_POST['protect_login']) ? 1 : 0);

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

            echo Ofast_X_Toast::render('Settings saved!', 'success');
        }

        $active_provider = get_option('ofast_spam_provider', 'turnstile');
        $recaptcha_site_key = get_option('ofast_recaptcha_site_key', '');
        $recaptcha_threshold = get_option('ofast_recaptcha_threshold', 0.5);
?>
        <div class="wrap">
            <h1>Spam Protection</h1>
            <p>Configure spam protection for your forms. Choose between Cloudflare Turnstile or Google reCAPTCHA.</p>

            <form method="post">
                <?php wp_nonce_field('ofast_recaptcha_save', 'recaptcha_nonce'); ?>

                <h2>Select Provider</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Active Provider</th>
                        <td>
                            <fieldset>
                                <label style="display:block;margin-bottom:10px;">
                                    <input type="radio" name="spam_provider" value="turnstile" <?php checked($active_provider, 'turnstile'); ?>>
                                    <strong>Cloudflare Turnstile</strong> (Recommended)
                                    <p class="description" style="margin-left:25px;">Free, privacy-friendly, invisible challenge</p>
                                </label>
                                <label style="display:block;margin-bottom:10px;">
                                    <input type="radio" name="spam_provider" value="recaptcha_v2" <?php checked($active_provider, 'recaptcha_v2'); ?>>
                                    <strong>Google reCAPTCHA v2</strong>
                                    <p class="description" style="margin-left:25px;">Checkbox challenge - "I'm not a robot"</p>
                                </label>
                                <label style="display:block;margin-bottom:10px;">
                                    <input type="radio" name="spam_provider" value="recaptcha_v3" <?php checked($active_provider, 'recaptcha_v3'); ?>>
                                    <strong>Google reCAPTCHA v3</strong>
                                    <p class="description" style="margin-left:25px;">Invisible scoring system (0.0 to 1.0)</p>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <hr>

                <!-- Turnstile Settings -->
                <div id="turnstile-settings" class="provider-settings" style="<?php echo $active_provider !== 'turnstile' ? 'display:none;' : ''; ?>">
                    <h2>Cloudflare Turnstile Settings</h2>
                    <?php
                    if (class_exists('Ofast_X_Turnstile')) {
                        Ofast_X_Turnstile::get_instance()->render_settings_form();
                    } else {
                        echo '<p class="notice notice-warning" style="padding:10px;">Turnstile class not loaded.</p>';
                    }
                    ?>
                </div>

                <!-- reCAPTCHA Settings -->
                <div id="recaptcha-settings" class="provider-settings" style="<?php echo !in_array($active_provider, array('recaptcha_v2', 'recaptcha_v3')) ? 'display:none;' : ''; ?>">
                    <h2>Google reCAPTCHA Settings</h2>
                    <p>Get your keys from <a href="https://www.google.com/recaptcha/admin" target="_blank">Google reCAPTCHA Admin</a></p>

                    <table class="form-table">
                        <tr>
                            <th scope="row">Site Key</th>
                            <td>
                                <input type="text" name="recaptcha_site_key" value="<?php echo esc_attr($recaptcha_site_key); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Secret Key</th>
                            <td>
                                <input type="password" name="recaptcha_secret_key" value="" class="regular-text" placeholder="<?php echo $recaptcha_site_key ? '(encrypted - enter to change)' : ''; ?>">
                                <p class="description">Secret keys are encrypted before storage</p>
                            </td>
                        </tr>
                        <tr id="threshold-row" style="<?php echo $active_provider !== 'recaptcha_v3' ? 'display:none;' : ''; ?>">
                            <th scope="row">Score Threshold (v3 only)</th>
                            <td>
                                <input type="number" name="recaptcha_threshold" value="<?php echo esc_attr($recaptcha_threshold); ?>" min="0" max="1" step="0.1" style="width:80px;">
                                <p class="description">Submissions with scores below this will be rejected (0.0 = bot, 1.0 = human). Default: 0.5</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <hr>

                <!-- Where to Apply Protection -->
                <h2>Where to Apply Protection</h2>
                <p class="description">Select which forms should be protected by spam protection.</p>
                <?php
                $protect_comments = get_option('ofast_spam_protect_comments', false);
                $protect_cf7 = get_option('ofast_spam_protect_cf7', false);
                $protect_login = get_option('ofast_spam_protect_login', false);
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">WordPress Comments</th>
                        <td>
                            <label>
                                <input type="checkbox" name="protect_comments" value="1" <?php checked($protect_comments); ?>>
                                Add spam protection to comment forms
                            </label>
                            <p class="description">Protects blog post comments from spam bots.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Contact Form 7</th>
                        <td>
                            <label>
                                <input type="checkbox" name="protect_cf7" value="1" <?php checked($protect_cf7); ?>>
                                Add spam protection to Contact Form 7 forms
                            </label>
                            <p class="description">Requires Contact Form 7 plugin to be installed.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">WordPress Login</th>
                        <td>
                            <label>
                                <input type="checkbox" name="protect_login" value="1" <?php checked($protect_login); ?>>
                                Add spam protection to login form
                            </label>
                            <p class="description">Protect your login page from brute force attacks and bots.</p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" name="ofast_save_recaptcha" class="button button-primary">Save Settings</button>
                </p>
            </form>
        </div>

        <script>
            jQuery(function($) {
                $('input[name="spam_provider"]').on('change', function() {
                    var provider = $(this).val();

                    $('.provider-settings').hide();

                    if (provider === 'turnstile') {
                        $('#turnstile-settings').show();
                    } else {
                        $('#recaptcha-settings').show();
                        if (provider === 'recaptcha_v3') {
                            $('#threshold-row').show();
                        } else {
                            $('#threshold-row').hide();
                        }
                    }
                });
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
