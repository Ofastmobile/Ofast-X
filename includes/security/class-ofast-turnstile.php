<?php

/**
 * Ofast X - Cloudflare Turnstile Integration
 * Centralized spam protection for all forms (Newsletter, Contact, etc.)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Turnstile
{
    private static $instance = null;
    private $site_key;
    private $secret_key;

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct()
    {
        $this->site_key = get_option('ofast_turnstile_site_key', '');
        $this->secret_key = $this->get_decrypted_secret();
    }

    /**
     * Check if Turnstile is configured
     */
    public function is_configured()
    {
        return !empty($this->site_key) && !empty($this->secret_key);
    }

    /**
     * Get site key (public - no decryption needed)
     */
    public function get_site_key()
    {
        return $this->site_key;
    }

    /**
     * Get decrypted secret key
     */
    private function get_decrypted_secret()
    {
        $encrypted = get_option('ofast_turnstile_secret_key', '');
        if (empty($encrypted)) {
            return '';
        }

        // Try to decrypt - if it fails, it might be stored plain (legacy)
        if (class_exists('Ofast_X_Security_Hardening')) {
            $decrypted = Ofast_X_Security_Hardening::decrypt_option($encrypted);
            // Check if decryption returned valid data
            if (!empty($decrypted) && strlen($decrypted) > 10) {
                return $decrypted;
            }
        }

        // Fallback: return as-is (legacy plain storage)
        return $encrypted;
    }

    /**
     * Save Turnstile keys with encryption
     */
    public static function save_keys($site_key, $secret_key)
    {
        // Site key is public - no encryption needed
        update_option('ofast_turnstile_site_key', sanitize_text_field($site_key));

        // Secret key should be encrypted
        if (class_exists('Ofast_X_Security_Hardening')) {
            $encrypted = Ofast_X_Security_Hardening::encrypt_option($secret_key);
            update_option('ofast_turnstile_secret_key', $encrypted);
        } else {
            // Fallback: store plain (not recommended)
            update_option('ofast_turnstile_secret_key', sanitize_text_field($secret_key));
        }

        // Reset instance to reload keys
        self::$instance = null;
    }

    /**
     * Verify Turnstile token
     * 
     * @param string $token The cf-turnstile-response from form
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function verify($token)
    {
        // If not configured, skip verification (allow)
        if (!$this->is_configured()) {
            return array(
                'success' => true,
                'error' => null,
                'skipped' => true
            );
        }

        if (empty($token)) {
            return array(
                'success' => false,
                'error' => 'Turnstile verification required',
                'code' => 'missing-input-response'
            );
        }

        // Get client IP
        $ip = $this->get_client_ip();

        // Send verification request to Cloudflare
        $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
            'timeout' => 15,
            'body' => array(
                'secret' => $this->secret_key,
                'response' => $token,
                'remoteip' => $ip
            )
        ));

        // Check for WP error
        if (is_wp_error($response)) {
            // Log error but allow submission (fail open)
            error_log('Ofast Turnstile: API error - ' . $response->get_error_message());
            return array(
                'success' => true,
                'error' => null,
                'skipped' => true,
                'reason' => 'api_error'
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['success'])) {
            $error_codes = isset($body['error-codes']) ? implode(', ', $body['error-codes']) : 'unknown';
            return array(
                'success' => false,
                'error' => 'Spam verification failed. Please try again.',
                'code' => $error_codes
            );
        }

        return array(
            'success' => true,
            'error' => null
        );
    }

    /**
     * Render Turnstile widget HTML
     * 
     * @param string $form_id Optional form identifier for multiple forms
     * @return string HTML to include in form
     */
    public function render_widget($form_id = 'default')
    {
        if (!$this->is_configured()) {
            return '<!-- Turnstile not configured -->';
        }

        $html = '<div class="cf-turnstile" 
                     data-sitekey="' . esc_attr($this->site_key) . '" 
                     data-callback="onTurnstileSuccess_' . esc_attr($form_id) . '"
                     data-theme="light"></div>';

        return $html;
    }

    /**
     * Render Turnstile script tag
     * Should be called once per page
     */
    public static function render_script()
    {
        static $rendered = false;
        if ($rendered) {
            return '';
        }
        $rendered = true;

        return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
    }

    /**
     * Get client IP address
     */
    private function get_client_ip()
    {
        $ip_headers = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
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

        return '0.0.0.0';
    }

    /**
     * Render settings form for admin pages
     * Can be called from any admin page that needs Turnstile settings
     */
    public function render_settings_form()
    {
        // SECURITY: Verify user has admin capability
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Handle form submission
        if (isset($_POST['ofast_save_turnstile']) && wp_verify_nonce($_POST['turnstile_nonce'], 'ofast_turnstile_save')) {
            self::save_keys(
                $_POST['turnstile_site_key'],
                $_POST['turnstile_secret_key']
            );
            echo '<div class="notice notice-success"><p>Turnstile keys saved and encrypted!</p></div>';
            // Reload keys
            $this->site_key = get_option('ofast_turnstile_site_key', '');
            $this->secret_key = $this->get_decrypted_secret();
        }

        $has_keys = $this->is_configured();
?>
        <div class="ofast-settings-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 20px;">
            <h3 style="margin-top: 0;">
                <span style="margin-right: 8px;">🛡️</span>
                Cloudflare Turnstile (Spam Protection)
            </h3>
            <p style="color: #666; margin-bottom: 15px;">
                Protects Newsletter and Contact forms from spam bots.
                <?php if ($has_keys): ?>
                    <span style="color: #46b450;">✓ Configured</span>
                <?php else: ?>
                    <span style="color: #dc3232;">✗ Not configured</span>
                <?php endif; ?>
            </p>
            <form method="post">
                <?php wp_nonce_field('ofast_turnstile_save', 'turnstile_nonce'); ?>
                <table class="form-table" style="margin: 0;">
                    <tr>
                        <th scope="row" style="padding: 10px 0;">Site Key</th>
                        <td style="padding: 10px 0;">
                            <input type="text"
                                name="turnstile_site_key"
                                value="<?php echo esc_attr($this->site_key); ?>"
                                class="regular-text"
                                placeholder="0x4AAAAAAA...">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding: 10px 0;">Secret Key</th>
                        <td style="padding: 10px 0;">
                            <input type="password"
                                name="turnstile_secret_key"
                                value="<?php echo $has_keys ? '••••••••••••••••' : ''; ?>"
                                class="regular-text"
                                placeholder="<?php echo $has_keys ? 'Key saved (encrypted)' : '0x4AAAAAAA...'; ?>">
                            <p class="description">Secret key is stored encrypted.</p>
                        </td>
                    </tr>
                </table>
                <p style="margin-top: 15px;">
                    <button type="submit" name="ofast_save_turnstile" class="button button-primary">Save Turnstile Keys</button>
                    <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" class="button" style="margin-left: 10px;">Get Keys from Cloudflare</a>
                </p>
            </form>
        </div>
<?php
    }
}
