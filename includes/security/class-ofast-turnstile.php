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
    private static $script_output_done = false;
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
        $stored_secret = get_option('ofast_turnstile_secret_key', '');
        if (empty($stored_secret)) {
            return '';
        }

        if (!class_exists('Ofast_X_Security_Hardening')) {
            return '';
        }

        $decrypted = Ofast_X_Security_Hardening::decrypt_option($stored_secret);
        if (!empty($decrypted)) {
            return $decrypted;
        }

        if ($this->is_legacy_plaintext_key($stored_secret)) {
            $this->migrate_legacy_key($stored_secret);
            return $stored_secret;
        }

        return '';
    }

    /**
     * Save Turnstile keys with encryption
     */
    public static function save_keys($site_key, $secret_key)
    {
        // Site key is public - no encryption needed
        update_option('ofast_turnstile_site_key', sanitize_text_field($site_key));

        if (!class_exists('Ofast_X_Security_Hardening')) {
            return false;
        }

        $encrypted = Ofast_X_Security_Hardening::encrypt_option(sanitize_text_field($secret_key));
        if ($encrypted === false) {
            return false;
        }

        update_option('ofast_turnstile_secret_key', $encrypted);

        // Reset instance to reload keys
        self::$instance = null;
        return true;
    }

    /**
     * Detect legacy plaintext Turnstile keys so they can be migrated.
     */
    private function is_legacy_plaintext_key($value)
    {
        if (!is_string($value) || $value === '') {
            return false;
        }

        if (class_exists('Ofast_X_Security_Hardening') && Ofast_X_Security_Hardening::looks_like_encrypted_option($value)) {
            return false;
        }

        return preg_match('/^0x[a-fA-F0-9]{20,100}$/', $value) === 1;
    }

    /**
     * Re-encrypt a legacy plaintext Turnstile key when possible.
     */
    private function migrate_legacy_key($plaintext_key)
    {
        if (!class_exists('Ofast_X_Security_Hardening')) {
            return;
        }

        $encrypted = Ofast_X_Security_Hardening::encrypt_option($plaintext_key);
        if ($encrypted !== false) {
            update_option('ofast_turnstile_secret_key', $encrypted);
        }
    }

    /**
     * Verify Turnstile token
     *
     * Hardened: Prevents token replay attacks using short-lived transients.
     * Each Turnstile token can only be used ONCE. Cloudflare's server-side
     * validation is single-use, but responses can be replayed within a short
     * window before CF marks them as timeout-or-duplicate. The transient
     * ensures we reject replays immediately.
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

        // --- Token Replay Prevention ---
        // Check if this exact token has already been consumed.
        // Forked from Simple Cloudflare Turnstile's transient-based approach.
        $token_hash = 'ofast_ts_' . substr(md5('ofast_verify_' . $token), 0, 20);
        if (get_transient($token_hash)) {
            return array(
                'success' => false,
                'error' => 'Security token already used. Please complete the challenge again.',
                'code' => 'token-replay'
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

        // Check for WP error (network/DNS/timeout failure)
        if (is_wp_error($response)) {
            error_log('Ofast Turnstile: API error - ' . $response->get_error_message());
            if ($this->should_fail_open()) {
                return array(
                    'success' => true,
                    'error' => null,
                    'skipped' => true,
                    'reason' => 'api_error'
                );
            }
            return array(
                'success' => false,
                'error' => 'Turnstile verification failed. Please try again.',
                'code' => 'api_error'
            );
        }

        // Check for Cloudflare server error (5xx)
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code >= 500) {
            error_log('Ofast Turnstile: Cloudflare returned HTTP ' . $http_code);
            if ($this->should_fail_open()) {
                return array(
                    'success' => true,
                    'error' => null,
                    'skipped' => true,
                    'reason' => 'api_error'
                );
            }
            return array(
                'success' => false,
                'error' => 'Turnstile service temporarily unavailable. Please try again.',
                'code' => 'api_error'
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body)) {
            return array(
                'success' => false,
                'error' => 'Invalid response from Turnstile API.',
                'code' => 'bad-response'
            );
        }

        if (empty($body['success'])) {
            $error_codes = isset($body['error-codes']) ? implode(', ', $body['error-codes']) : 'unknown';
            return array(
                'success' => false,
                'error' => 'Spam verification failed. Please try again.',
                'code' => $error_codes
            );
        }

        // --- Mark token as consumed ---
        // 300-second TTL matches Cloudflare's token validity window.
        // After this, the token is expired at Cloudflare anyway.
        set_transient($token_hash, 1, 300);

        return array(
            'success' => true,
            'error' => null
        );
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
     * Render Turnstile widget HTML
     *
     * Hardened: Includes data-action for Cloudflare analytics, auto-retry on
     * failure, auto-refresh on token expiry, and unique IDs per widget instance.
     * Forked from Simple Cloudflare Turnstile's cfturnstile_field_show().
     * 
     * @param string $form_id Form context identifier (e.g. 'login', 'comment', 'cf7')
     * @return string HTML to include in form
     */
    public function render_widget($form_id = 'default')
    {
        if (!$this->is_configured()) {
            return '<!-- Turnstile not configured -->';
        }

        // Unique ID per widget instance for pages with multiple forms
        $unique_id = 'ofast-ts-' . sanitize_key($form_id) . '-' . wp_rand(1000, 9999);
        $action = 'ofast-' . sanitize_key($form_id);

        $html  = '<div id="' . esc_attr($unique_id) . '"';
        $html .= ' class="cf-turnstile ofast-turnstile-widget"';
        $html .= ' data-sitekey="' . esc_attr($this->site_key) . '"';
        $html .= ' data-theme="light"';
        $html .= ' data-action="' . esc_attr($action) . '"';           // Form type in CF analytics
        $html .= ' data-retry="auto"';                                   // Auto-retry on failure
        $html .= ' data-retry-interval="1000"';                          // 1-second retry interval
        $html .= ' data-refresh-expired="auto"';                         // Auto-refresh expired tokens
        $html .= ' data-refresh-timeout="auto"';                         // Auto-refresh on timeout
        $html .= ' data-callback="ofastTurnstileSuccess"';               // Global success callback
        $html .= ' data-error-callback="ofastTurnstileError"';           // Global error callback
        $html .= ' data-appearance="always"></div>';                     // Always show widget

        return $html;
    }

    /**
     * Enqueue Turnstile API script
     *
     * Hardened: Uses render=auto mode, loads in footer with defer strategy,
     * and adds data-cfasync="false" via script_loader_tag filter to prevent
     * Cloudflare Rocket Loader from breaking the Turnstile widget.
     */
    public static function enqueue_script()
    {
        if (self::$script_output_done) {
            return;
        }

        if (function_exists('wp_enqueue_script')) {
            wp_enqueue_script(
                'ofast-turnstile-api',
                'https://challenges.cloudflare.com/turnstile/v0/api.js?render=auto',
                array(),
                null,
                array('in_footer' => true, 'strategy' => 'defer')
            );
            self::$script_output_done = true;

            // Prevent Cloudflare Rocket Loader from deferring the Turnstile script
            add_filter('script_loader_tag', array(__CLASS__, 'add_cfasync_attribute'), 10, 2);
        }
    }

    /**
     * Add data-cfasync="false" to the Turnstile script tag.
     * Prevents Cloudflare Rocket Loader from breaking the widget.
     */
    public static function add_cfasync_attribute($tag, $handle)
    {
        if ('ofast-turnstile-api' === $handle) {
            $tag = str_replace("src='", "data-cfasync='false' src='", $tag);
            $tag = str_replace('src="', 'data-cfasync="false" src="', $tag);
        }
        return $tag;
    }

    /**
     * Render Turnstile script tag
     * Should be called once per page
     */
    public static function render_script()
    {
        if (self::$script_output_done) {
            return '';
        }
        self::$script_output_done = true;

        return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
    }

    /**
     * Get client IP address.
     *
     * Hardened: Removed X-Forwarded-For trust — the leftmost IP is
     * client-controlled and trivially spoofable. CF-Connecting-IP now
     * rejects private/reserved IPs.
     * Priority: CF-Connecting-IP → X-Real-IP → REMOTE_ADDR.
     */
    private function get_client_ip()
    {
        // 1. CF-Connecting-IP: set by Cloudflare proxy, reject private/reserved
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = sanitize_text_field(trim(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP'])));
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        // 2. X-Real-IP: single-value header set by nginx reverse proxy
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = sanitize_text_field(trim(wp_unslash($_SERVER['HTTP_X_REAL_IP'])));
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        // 3. REMOTE_ADDR: the only value that truly cannot be spoofed
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(trim(wp_unslash($_SERVER['REMOTE_ADDR'])));
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
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
            wp_die(__('You do not have sufficient permissions to access this page.', 'ofast-x'));
        }

        // NOTE: Save logic is handled by parent form (spam-protection page)
        // with proper nonce verification. This method only renders the form.

        $has_keys = $this->is_configured();
?>
        <p style="color: #666; margin-bottom: 15px;">
            Get your keys from <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank">Cloudflare Turnstile Dashboard</a>.
            <?php if ($has_keys): ?>
                <span style="color: #46b450; margin-left: 10px;">✓ Configured</span>
            <?php else: ?>
                <span style="color: #dc3232; margin-left: 10px;">✗ Not configured</span>
            <?php endif; ?>
        </p>
        
        <table class="form-table" style="margin: 0;">
            <tr>
                <th scope="row">Site Key</th>
                <td>
                    <input type="text"
                        name="turnstile_site_key"
                        value="<?php echo esc_attr($this->site_key); ?>"
                        class="regular-text"
                        style="border-radius: 8px;"
                        placeholder="0x4AAAAAAA...">
                </td>
            </tr>
            <tr>
                <th scope="row">Secret Key</th>
                <td>
                    <input type="password"
                        name="turnstile_secret_key"
                        value=""
                        class="regular-text"
                        style="border-radius: 8px;"
                        placeholder="<?php echo $has_keys ? 'Leave blank to keep existing encrypted key' : '0x4AAAAAAA...'; ?>">
                    <p class="description">Secret key is stored encrypted. Leave unchanged to keep existing key.</p>
                </td>
            </tr>
        </table>
<?php
    }
}
