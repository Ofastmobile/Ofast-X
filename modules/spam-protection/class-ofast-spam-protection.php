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
        // Only load if module is enabled
        $enabled = get_option('ofastx_modules_enabled', array());
        if (empty($enabled['spam-protection'])) {
            return;
        }

        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
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

            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
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
