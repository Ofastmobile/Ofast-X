<?php
/**
 * Ofast Toolkit License Manager (FIXED VERSION)
 * 
 * All security vulnerabilities patched:
 * - CSRF protection added
 * - Capability checks added
 * - Input validation added
 * - Rate limiting added
 * - Expiration checking added
 * - SSL verification enforced
 * - Signature verification added
 * - Comprehensive logging added
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_Toolkit_License_Manager {
    
    private $api_url = 'https://yourdomain.com/wp-json/ofast-license/v1/';
    private $product_id = 'ofast-toolkit';
    private $option_prefix = 'ofast_toolkit_';
    
    /**
     * Initialize
     */
    public function init() {
        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_license_page']);
            add_action('admin_init', [$this, 'handle_form_submission']);
            add_action('admin_notices', [$this, 'show_license_notices']);
        }
        
        // Daily validation
        add_action('ofast_daily_license_check', [$this, 'validate_license']);
        if (!wp_next_scheduled('ofast_daily_license_check')) {
            wp_schedule_event(time(), 'daily', 'ofast_daily_license_check');
        }
    }
    
    /**
     * Add license page to admin menu
     */
    public function add_license_page() {
        add_submenu_page(
            'ofast-settings',
            'License',
            'License',
            'manage_options',
            'ofast-license',
            [$this, 'render_license_page']
        );
    }
    
    /**
     * Handle form submissions with CSRF protection
     */
    public function handle_form_submission() {
        // CRITICAL FIX #4: Add nonce verification
        if (!isset($_POST['ofast_license_action'])) {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized access', 'ofast-toolkit'));
        }
        
        // Verify nonce
        if (!isset($_POST['ofast_license_nonce']) || 
            !wp_verify_nonce($_POST['ofast_license_nonce'], 'ofast_license_action')) {
            wp_die(esc_html__('Security check failed', 'ofast-toolkit'));
        }
        
        $action = sanitize_text_field($_POST['ofast_license_action']);
        
        if ($action === 'activate') {
            $this->activate_license($_POST['license_key'] ?? '');
        } elseif ($action === 'deactivate') {
            $this->deactivate_license();
        }
    }
    
    /**
     * Render license settings page
     * FIX: Added capability check
     */
    public function render_license_page() {
        // CRITICAL FIX #2: Add capability check
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page', 'ofast-toolkit'));
        }
        
        $license_key = get_option($this->option_prefix . 'license_key', '');
        $license_status = get_option($this->option_prefix . 'license_status', 'inactive');
        $license_expires = get_option($this->option_prefix . 'license_expires', '');
        $license_type = get_option($this->option_prefix . 'license_type', '');
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Ofast Toolkit License', 'ofast-toolkit'); ?></h1>
            
            <div class="ofast-license-container">
                <?php if ($license_status === 'active'): ?>
                    <div class="license-status active">
                        <h2><?php esc_html_e('License Active', 'ofast-toolkit'); ?></h2>
                        <table class="license-info">
                            <tr>
                                <th><?php esc_html_e('License Key:', 'ofast-toolkit'); ?></th>
                                <td><code><?php echo esc_html($this->mask_license($license_key)); ?></code></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Status:', 'ofast-toolkit'); ?></th>
                                <td><span class="status-active"><?php esc_html_e('Active', 'ofast-toolkit'); ?></span></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Type:', 'ofast-toolkit'); ?></th>
                                <td><?php echo esc_html(ucfirst($license_type)); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Expires:', 'ofast-toolkit'); ?></th>
                                <td>
                                    <?php 
                                    if ($license_expires) {
                                        echo esc_html(date_i18n('F j, Y', strtotime($license_expires)));
                                    } else {
                                        esc_html_e('Never', 'ofast-toolkit');
                                    }
                                    ?>
                                </td>
                            </tr>
                        </table>
                        
                        <form method="post" style="margin-top: 20px;">
                            <?php wp_nonce_field('ofast_license_action', 'ofast_license_nonce'); ?>
                            <input type="hidden" name="ofast_license_action" value="deactivate">
                            <button type="submit" class="button button-secondary">
                                <?php esc_html_e('Deactivate License', 'ofast-toolkit'); ?>
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="license-status inactive">
                        <h2><?php esc_html_e('No Active License', 'ofast-toolkit'); ?></h2>
                        <p><?php esc_html_e('Enter your license key to unlock premium features', 'ofast-toolkit'); ?></p>
                        
                        <form method="post">
                            <?php wp_nonce_field('ofast_license_action', 'ofast_license_nonce'); ?>
                            <input type="hidden" name="ofast_license_action" value="activate">
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="license_key"><?php esc_html_e('License Key', 'ofast-toolkit'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" 
                                               id="license_key" 
                                               name="license_key" 
                                               class="regular-text" 
                                               placeholder="OFAST-XXXX-XXXX-XXXX-XXXX"
                                               value="<?php echo esc_attr($license_key); ?>"
                                               pattern="OFAST-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}"
                                               required>
                                        <p class="description">
                                            <?php esc_html_e("Format: OFAST-XXXX-XXXX-XXXX-XXXX", 'ofast-toolkit'); ?>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                            <p class="submit">
                                <button type="submit" class="button button-primary">
                                    <?php esc_html_e('Activate License', 'ofast-toolkit'); ?>
                                </button>
                            </p>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Activate license with all security checks
     */
    public function activate_license($license_key) {
        // HIGH FIX #1: Validate license key format
        $license_key = preg_replace('/[^A-Z0-9\-]/', '', strtoupper(trim($license_key)));
        
        if (!preg_match('/^OFAST-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $license_key)) {
            add_settings_error('ofast_license', 'invalid_format', 
                __('Invalid license key format. Use: OFAST-XXXX-XXXX-XXXX-XXXX', 'ofast-toolkit'), 'error');
            return false;
        }
        
        // HIGH FIX #2: Rate limiting on client side
        $rate_key = 'ofast_activation_attempts_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
        $attempts = get_transient($rate_key) ?: 0;
        
        if ($attempts > 5) {
            add_settings_error('ofast_license', 'rate_limited', 
                __('Too many activation attempts. Please try again in 1 hour.', 'ofast-toolkit'), 'error');
            return false;
        }
        
        set_transient($rate_key, $attempts + 1, HOUR_IN_SECONDS);
        
        // Call API with all security measures
        $response = wp_remote_post($this->api_url . 'activate', [
            'timeout' => 15,
            'sslverify' => true,  // HIGH FIX #4: Force SSL verification
            'headers' => [
                'X-Ofast-Api-Secret' => OFAST_API_CLIENT_SECRET,
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'body' => [
                'license_key' => $license_key,
                'site_url' => esc_url_raw(home_url()),
                'product_id' => $this->product_id,
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION
            ]
        ]);
        
        if (is_wp_error($response)) {
            add_settings_error('ofast_license', 'connection_error', 
                sprintf(__('Connection error: %s', 'ofast-toolkit'), esc_html($response->get_error_message())), 'error');
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!$body['success']) {
            add_settings_error('ofast_license', 'activation_failed', 
                esc_html($body['message'] ?? __('Activation failed', 'ofast-toolkit')), 'error');
            return false;
        }
        
        // HIGH FIX #7: Verify signature before storing
        if (!$this->verify_signature($license_key, $body['data']['signature'] ?? '')) {
            add_settings_error('ofast_license', 'signature_failed', 
                __('License signature verification failed. Possible tampering detected.', 'ofast-toolkit'), 'error');
            return false;
        }
        
        // Save license data
        update_option($this->option_prefix . 'license_key', $license_key);
        update_option($this->option_prefix . 'activation_token', sanitize_text_field($body['data']['activation_token'] ?? ''));
        update_option($this->option_prefix . 'license_status', 'active');
        update_option($this->option_prefix . 'license_expires', sanitize_text_field($body['data']['expires_at'] ?? ''));
        update_option($this->option_prefix . 'license_type', sanitize_text_field($body['data']['license_type'] ?? ''));
        update_option($this->option_prefix . 'last_checked', time());
        
        // Log successful activation
        $this->log_activation_attempt($license_key, 'success');
        
        add_settings_error('ofast_license', 'activation_success', 
            __('License activated successfully!', 'ofast-toolkit'), 'success');
        
        return true;
    }
    
    /**
     * Deactivate license
     */
    public function deactivate_license() {
        $license_key = get_option($this->option_prefix . 'license_key');
        
        if (empty($license_key)) {
            return false;
        }
        
        // Call API to notify server
        wp_remote_post($this->api_url . 'deactivate', [
            'timeout' => 15,
            'sslverify' => true,
            'headers' => [
                'X-Ofast-Api-Secret' => OFAST_API_CLIENT_SECRET
            ],
            'body' => [
                'license_key' => $license_key,
                'site_url' => esc_url_raw(home_url())
            ]
        ]);
        
        // Clear local data
        delete_option($this->option_prefix . 'license_key');
        delete_option($this->option_prefix . 'activation_token');
        delete_option($this->option_prefix . 'license_status');
        delete_option($this->option_prefix . 'license_expires');
        delete_option($this->option_prefix . 'license_type');
        delete_option($this->option_prefix . 'last_checked');
        
        $this->log_activation_attempt($license_key, 'deactivated');
        
        add_settings_error('ofast_license', 'deactivation_success', 
            __('License deactivated successfully!', 'ofast-toolkit'), 'success');
        
        return true;
    }
    
    /**
     * Validate license (runs daily)
     */
    public function validate_license() {
        $license_key = get_option($this->option_prefix . 'license_key');
        $activation_token = get_option($this->option_prefix . 'activation_token');
        
        if (empty($license_key) || empty($activation_token)) {
            return false;
        }
        
        $response = wp_remote_post($this->api_url . 'validate', [
            'timeout' => 15,
            'sslverify' => true,
            'body' => [
                'license_key' => $license_key,
                'site_url' => esc_url_raw(home_url()),
                'activation_token' => $activation_token
            ]
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($body['success'] && $body['data']['valid']) {
            update_option($this->option_prefix . 'license_status', $body['data']['status']);
            update_option($this->option_prefix . 'last_checked', time());
            return true;
        } else {
            update_option($this->option_prefix . 'license_status', 'inactive');
            return false;
        }
    }
    
    /**
     * Check if license is active (with expiration check)
     * HIGH FIX #3: Add expiration checking
     */
    public function is_license_active() {
        $status = get_option($this->option_prefix . 'license_status', 'inactive');
        
        if ($status !== 'active') {
            return false;
        }
        
        // Check expiration
        $expires = get_option($this->option_prefix . 'license_expires');
        if ($expires && strtotime($expires) < time()) {
            update_option($this->option_prefix . 'license_status', 'expired');
            return false;
        }
        
        return true;
    }
    
    /**
     * Verify signature from server
     * HIGH FIX #7: Client-side signature verification
     */
    private function verify_signature($license_key, $signature) {
        if (empty($signature)) {
            return false;
        }
        
        // Recreate expected signature
        $expected_sig = hash_hmac('sha256', $license_key . '|' . home_url(), OFAST_API_CLIENT_SECRET);
        
        // Constant-time comparison to prevent timing attacks
        return hash_equals($expected_sig, $signature);
    }
    
    /**
     * Log activation attempts
     * HIGH FIX #5: Add activation logging
     */
    private function log_activation_attempt($license_key, $status) {
        $log_key = 'ofast_activation_log_' . md5($license_key);
        $logs = get_option($log_key, []);
        
        // Keep only last 20 attempts
        if (count($logs) >= 20) {
            array_shift($logs);
        }
        
        $logs[] = [
            'timestamp' => current_time('mysql'),
            'status' => $status,
            'ip' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100)
        ];
        
        update_option($log_key, $logs);
    }
    
    /**
     * Show admin notices
     */
    public function show_license_notices() {
        $status = get_option($this->option_prefix . 'license_status', 'inactive');
        
        if ($status === 'expired') {
            echo '<div class="notice notice-error"><p>';
            esc_html_e('Your Ofast Toolkit license has expired. ', 'ofast-toolkit');
            echo '<a href="' . esc_url(admin_url('admin.php?page=ofast-license')) . '">';
            esc_html_e('Renew now', 'ofast-toolkit');
            echo '</a></p></div>';
        }
    }
    
    /**
     * Mask license key for display
     */
    private function mask_license($key) {
        if (strlen($key) <= 8) {
            return $key;
        }
        return substr($key, 0, 4) . str_repeat('*', strlen($key) - 8) . substr($key, -4);
    }
}
