<?php

/**
 * Ofast X - WhatsApp Integration Module
 * Sends WhatsApp notifications via Twilio or Termii API
 * Termii is a Nigerian/African provider with better regional support
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_WhatsApp
{
    private static $instance = null;

    // Providers
    const PROVIDER_TWILIO = 'twilio';
    const PROVIDER_TERMII = 'termii';

    // Settings
    private $enabled;
    private $provider;
    private $api_key;        // Termii API Key or Twilio Account SID
    private $api_secret;     // Twilio Auth Token (not used for Termii)
    private $sender_id;      // Termii Sender ID or Twilio From Number
    private $admin_number;

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
        $this->load_settings();
    }

    /**
     * Load settings from database
     */
    private function load_settings()
    {
        $this->enabled = get_option('ofast_whatsapp_enabled', false);
        $this->provider = get_option('ofast_whatsapp_provider', self::PROVIDER_TERMII);
        $this->api_key = $this->decrypt_setting('ofast_whatsapp_api_key');
        $this->api_secret = $this->decrypt_setting('ofast_whatsapp_api_secret');
        $this->sender_id = get_option('ofast_whatsapp_sender_id', '');
        $this->admin_number = get_option('ofast_whatsapp_admin_number', '');
    }

    /**
     * Decrypt a setting
     */
    private function decrypt_setting($option_name)
    {
        $encrypted = get_option($option_name, '');
        if (empty($encrypted)) {
            return '';
        }

        if (class_exists('Ofast_X_Security_Hardening')) {
            $decrypted = Ofast_X_Security_Hardening::decrypt_option($encrypted);
            if (!empty($decrypted) && strlen($decrypted) > 5) {
                return $decrypted;
            }
        }

        return $encrypted;
    }

    /**
     * Check if WhatsApp is configured
     */
    public function is_configured()
    {
        if (!$this->enabled || empty($this->admin_number)) {
            return false;
        }

        if ($this->provider === self::PROVIDER_TERMII) {
            return !empty($this->api_key);
        } else {
            // Twilio
            return !empty($this->api_key) && !empty($this->api_secret) && !empty($this->sender_id);
        }
    }

    /**
     * Check if enabled
     */
    public function is_enabled()
    {
        return $this->enabled;
    }

    /**
     * Get current provider
     */
    public function get_provider()
    {
        return $this->provider;
    }

    /**
     * Save settings
     */
    public static function save_settings($data)
    {
        update_option('ofast_whatsapp_enabled', !empty($data['enabled']));
        update_option('ofast_whatsapp_provider', sanitize_text_field($data['provider'] ?? self::PROVIDER_TERMII));

        // Encrypt sensitive data
        if (!empty($data['api_key'])) {
            if (class_exists('Ofast_X_Security_Hardening')) {
                update_option(
                    'ofast_whatsapp_api_key',
                    Ofast_X_Security_Hardening::encrypt_option($data['api_key'])
                );
            } else {
                update_option('ofast_whatsapp_api_key', sanitize_text_field($data['api_key']));
            }
        }

        if (!empty($data['api_secret'])) {
            if (class_exists('Ofast_X_Security_Hardening')) {
                update_option(
                    'ofast_whatsapp_api_secret',
                    Ofast_X_Security_Hardening::encrypt_option($data['api_secret'])
                );
            } else {
                update_option('ofast_whatsapp_api_secret', sanitize_text_field($data['api_secret']));
            }
        }

        if (isset($data['sender_id'])) {
            update_option('ofast_whatsapp_sender_id', sanitize_text_field($data['sender_id']));
        }
        if (isset($data['admin_number'])) {
            update_option('ofast_whatsapp_admin_number', sanitize_text_field($data['admin_number']));
        }

        self::$instance = null;
    }

    /**
     * Send WhatsApp message (routes to correct provider)
     */
    public function send_message($to, $message)
    {
        if (!$this->is_configured()) {
            return array('success' => false, 'error' => 'WhatsApp not configured');
        }

        $to = $this->format_phone_number($to);
        if (!$to) {
            return array('success' => false, 'error' => 'Invalid phone number');
        }

        // Sanitize and limit message length to prevent abuse
        $message = sanitize_textarea_field($message);
        $message = mb_substr($message, 0, 1600); // WhatsApp message limit

        if (empty($message)) {
            return array('success' => false, 'error' => 'Empty message');
        }

        if ($this->provider === self::PROVIDER_TERMII) {
            return $this->send_via_termii($to, $message);
        } else {
            return $this->send_via_twilio($to, $message);
        }
    }

    /**
     * Send via Termii API
     */
    private function send_via_termii($to, $message)
    {
        $url = 'https://api.ng.termii.com/api/send';

        $response = wp_remote_post($url, array(
            'timeout' => 30,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array(
                'api_key' => $this->api_key,
                'to' => ltrim($to, '+'),  // Termii expects number without +
                'from' => $this->sender_id ?: 'N-Alert',
                'sms' => $message,
                'channel' => 'whatsapp',
                'type' => 'plain'
            ))
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 200 && isset($body['message_id'])) {
            return array(
                'success' => true,
                'message_id' => $body['message_id'],
                'provider' => 'termii'
            );
        }

        $error = isset($body['message']) ? $body['message'] : 'Termii API error';
        return array('success' => false, 'error' => $error);
    }

    /**
     * Send via Twilio API
     */
    private function send_via_twilio($to, $message)
    {
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->api_key}/Messages.json";

        $response = wp_remote_post($url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode("{$this->api_key}:{$this->api_secret}"),
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'body' => array(
                'From' => 'whatsapp:' . $this->sender_id,
                'To' => 'whatsapp:' . $to,
                'Body' => $message
            )
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 201) {
            return array(
                'success' => true,
                'sid' => $body['sid'] ?? null,
                'provider' => 'twilio'
            );
        }

        $error = isset($body['message']) ? $body['message'] : 'Twilio API error';
        return array('success' => false, 'error' => $error);
    }

    /**
     * Send admin notification
     */
    public function send_admin_notification($message)
    {
        if (!$this->is_configured()) {
            return array('success' => false, 'error' => 'WhatsApp not configured', 'skipped' => true);
        }
        return $this->send_message($this->admin_number, $message);
    }

    /**
     * Format phone number
     */
    private function format_phone_number($phone)
    {
        $phone = preg_replace('/[^\d+]/', '', $phone);

        if (strpos($phone, '+') === 0) {
            if (preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
                return $phone;
            }
        }

        if (preg_match('/^[1-9]\d{7,14}$/', $phone)) {
            return '+' . $phone;
        }

        return false;
    }

    /**
     * Test connection
     */
    public function test_connection()
    {
        if (!$this->is_configured()) {
            return array('success' => false, 'error' => 'WhatsApp not configured');
        }

        if ($this->provider === self::PROVIDER_TERMII) {
            return $this->test_termii();
        } else {
            return $this->test_twilio();
        }
    }

    /**
     * Test Termii connection
     */
    private function test_termii()
    {
        $url = 'https://api.ng.termii.com/api/check/balance?api_key=' . $this->api_key;

        $response = wp_remote_get($url, array('timeout' => 15));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 200 && isset($body['balance'])) {
            return array(
                'success' => true,
                'provider' => 'Termii',
                'balance' => $body['balance'] . ' ' . ($body['currency'] ?? 'NGN')
            );
        }

        return array('success' => false, 'error' => $body['message'] ?? 'Invalid API key');
    }

    /**
     * Test Twilio connection
     */
    private function test_twilio()
    {
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->api_key}.json";

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array('Authorization' => 'Basic ' . base64_encode("{$this->api_key}:{$this->api_secret}"))
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 200) {
            return array(
                'success' => true,
                'provider' => 'Twilio',
                'account_name' => $body['friendly_name'] ?? 'Connected'
            );
        }

        return array('success' => false, 'error' => $body['message'] ?? 'Connection failed');
    }

    /**
     * Render settings form
     */
    public function render_settings_form()
    {
        // SECURITY: Verify user has admin capability
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Handle save
        if (isset($_POST['ofast_save_whatsapp']) && wp_verify_nonce($_POST['whatsapp_nonce'], 'ofast_whatsapp_save')) {
            self::save_settings(array(
                'enabled' => isset($_POST['whatsapp_enabled']),
                'provider' => $_POST['whatsapp_provider'],
                'api_key' => $_POST['api_key'],
                'api_secret' => $_POST['api_secret'] ?? '',
                'sender_id' => $_POST['sender_id'] ?? '',
                'admin_number' => $_POST['admin_number']
            ));

            // Save notification types
            $notify_types = array(
                'user_registration' => isset($_POST['notify_user_registration']) ? 1 : 0,
                'form_submission' => isset($_POST['notify_form_submission']) ? 1 : 0,
                'woocommerce' => isset($_POST['notify_woocommerce']) ? 1 : 0,
                'admin_only' => isset($_POST['notify_admin_only']) ? 1 : 0,
            );
            update_option('ofast_whatsapp_notify_types', $notify_types);

            $this->load_settings();
            echo Ofast_X_Toast::render('WhatsApp settings saved!', 'success');
        }

        // Handle test
        if (isset($_POST['ofast_test_whatsapp']) && wp_verify_nonce($_POST['whatsapp_nonce'], 'ofast_whatsapp_save')) {
            $result = $this->test_connection();
            if ($result['success']) {
                $info = isset($result['balance']) ? "Balance: {$result['balance']}" : ($result['account_name'] ?? 'Connected');
                set_transient('ofast_whatsapp_test_result', array('type' => 'success', 'message' => 'Connected to ' . $result['provider'] . '! ' . $info), 30);
            } else {
                set_transient('ofast_whatsapp_test_result', array('type' => 'error', 'message' => 'Connection failed: ' . $result['error']), 30);
            }
            wp_redirect($_SERVER['REQUEST_URI']);
            exit;
        }

        // Handle send test message
        if (isset($_POST['ofast_send_test_whatsapp']) && wp_verify_nonce($_POST['whatsapp_nonce'], 'ofast_whatsapp_save')) {
            if ($this->is_configured() && !empty($this->admin_number)) {
                $test_message = sprintf(__("WhatsApp Test from %s\n\nThis is a test message.\n\nIf you received this, your WhatsApp notifications are working!\n\nTime: %s", 'ofast-x'), get_bloginfo('name'), current_time('F j, Y g:i a'));
                $result = $this->send_message($this->admin_number, $test_message);
                if ($result) {
                    set_transient('ofast_whatsapp_test_result', array('type' => 'success', 'message' => 'Test message sent to ' . $this->admin_number . '! Check your WhatsApp.'), 30);
                } else {
                    set_transient('ofast_whatsapp_test_result', array('type' => 'error', 'message' => 'Failed to send test message. Check your API credentials and balance.'), 30);
                }
            } else {
                set_transient('ofast_whatsapp_test_result', array('type' => 'error', 'message' => 'Please configure settings and admin number first.'), 30);
            }
            wp_redirect($_SERVER['REQUEST_URI']);
            exit;
        }

        $is_configured = $this->is_configured();
        $is_termii = $this->provider === self::PROVIDER_TERMII;

        // Get and clear test result
        $test_result = get_transient('ofast_whatsapp_test_result');
        delete_transient('ofast_whatsapp_test_result');
?>
        <div class="ofast-settings-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 20px;">
            <h3 style="margin-top: 0;">
                WhatsApp Notifications
            </h3>

            <?php if ($test_result): ?>
                <div style="padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; <?php echo $test_result['type'] === 'success' ? 'background: #d4edda; border: 1px solid #c3e6cb; color: #155724;' : 'background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24;'; ?>">
                    <strong><?php echo $test_result['type'] === 'success' ? '✓' : '✗'; ?></strong>
                    <?php echo esc_html($test_result['message']); ?>
                </div>
            <?php endif; ?>

            <p style="color: #666; margin-bottom: 15px;">
                Send instant WhatsApp notifications when forms are submitted.
                <?php if ($is_configured): ?>
                    <span style="color: #46b450;">✓ Configured (<?php echo ucfirst($this->provider); ?>)</span>
                <?php else: ?>
                    <span style="color: #dc3232;">✗ Not configured</span>
                <?php endif; ?>
            </p>
            <form method="post">
                <?php wp_nonce_field('ofast_whatsapp_save', 'whatsapp_nonce'); ?>
                <table class="form-table" style="margin: 0;">
                    <tr>
                        <th style="padding: 10px 0;">Enable WhatsApp</th>
                        <td style="padding: 10px 0;">
                            <label>
                                <input type="checkbox" name="whatsapp_enabled" value="1" <?php checked($this->enabled); ?>>
                                Enable WhatsApp notifications
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th style="padding: 10px 0;">Provider</th>
                        <td style="padding: 10px 0;">
                            <select name="whatsapp_provider" id="whatsapp_provider" onchange="toggleProviderFields()">
                                <option value="termii" <?php selected($this->provider, 'termii'); ?>>Termii (Nigeria/Africa)</option>
                                <option value="meta" <?php selected($this->provider, 'meta'); ?>>Meta Cloud API (Official)</option>
                                <option value="wati" <?php selected($this->provider, 'wati'); ?>>WATI (Business API)</option>
                                <option value="twilio" <?php selected($this->provider, 'twilio'); ?>>Twilio (Global)</option>
                            </select>
                            <p class="description">Termii recommended for Nigeria/Africa. Meta Cloud API is the official WhatsApp API.</p>
                        </td>
                    </tr>
                    <tr>
                        <th style="padding: 10px 0;">API Key</th>
                        <td style="padding: 10px 0;">
                            <input type="text" name="api_key" value="<?php echo $is_configured ? str_repeat('*', 16) : ''; ?>" class="regular-text" placeholder="<?php echo $is_termii ? 'Termii API Key' : 'Twilio Account SID'; ?>">
                            <p class="description" id="api_key_desc"><?php echo $is_termii ? 'Get from termii.com → Settings → API Key' : 'Find in Twilio Console'; ?></p>
                        </td>
                    </tr>
                    <tr id="api_secret_row" style="<?php echo $is_termii ? 'display:none;' : ''; ?>">
                        <th style="padding: 10px 0;">Auth Token</th>
                        <td style="padding: 10px 0;">
                            <input type="password" name="api_secret" value="" class="regular-text" placeholder="Twilio Auth Token">
                            <p class="description">Auth Token is stored encrypted.</p>
                        </td>
                    </tr>
                    <tr id="sender_id_row" style="<?php echo $is_termii ? 'display:none;' : ''; ?>">
                        <th style="padding: 10px 0;">Sender ID / From Number</th>
                        <td style="padding: 10px 0;">
                            <input type="text" name="sender_id" value="<?php echo esc_attr($this->sender_id); ?>" class="regular-text" placeholder="<?php echo $is_termii ? 'N-Alert' : '+14155238886'; ?>">
                            <p class="description"><?php echo $is_termii ? 'Your registered Sender ID' : 'Your Twilio WhatsApp number'; ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th style="padding: 10px 0;">Admin Phone Number</th>
                        <td style="padding: 10px 0;">
                            <input type="text" name="admin_number" value="<?php echo esc_attr($this->admin_number); ?>" class="regular-text" placeholder="+2348012345678">
                            <p class="description">Your WhatsApp number (with country code) to receive notifications</p>
                        </td>
                    </tr>
                </table>

                <!-- Notification Types Section -->
                <h4 style="margin: 20px 0 10px; padding-top: 15px; border-top: 1px solid #ddd;">Notification Types</h4>
                <p class="description" style="margin-bottom: 15px;">Select which events should trigger WhatsApp notifications.</p>
                <?php
                $notify_types = get_option('ofast_whatsapp_notify_types', array('form_submission' => 1));
                ?>
                <table class="form-table" style="margin: 0;">
                    <tr>
                        <th style="padding: 8px 0;">New User Registrations</th>
                        <td style="padding: 8px 0;">
                            <label>
                                <input type="checkbox" name="notify_user_registration" value="1" <?php checked(!empty($notify_types['user_registration'])); ?>>
                                Notify when a new user registers
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th style="padding: 8px 0;">Form Submissions</th>
                        <td style="padding: 8px 0;">
                            <label>
                                <input type="checkbox" name="notify_form_submission" value="1" <?php checked(!empty($notify_types['form_submission'])); ?>>
                                Notify when contact/newsletter forms are submitted
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th style="padding: 8px 0;">WooCommerce Orders</th>
                        <td style="padding: 8px 0;">
                            <label>
                                <input type="checkbox" name="notify_woocommerce" value="1" <?php checked(!empty($notify_types['woocommerce'])); ?>>
                                Notify when new orders are placed
                            </label>
                            <p class="description">Requires WooCommerce to be active</p>
                        </td>
                    </tr>
                    <tr>
                        <th style="padding: 8px 0;">Admin Alerts Only</th>
                        <td style="padding: 8px 0;">
                            <label>
                                <input type="checkbox" name="notify_admin_only" value="1" <?php checked(!empty($notify_types['admin_only'])); ?>>
                                Only send to admin (don't notify users)
                            </label>
                        </td>
                    </tr>
                </table>
                <p style="margin-top: 15px;">
                    <button type="submit" name="ofast_save_whatsapp" class="button button-primary">Save Settings</button>
                    <button type="submit" name="ofast_test_whatsapp" class="button" style="margin-left: 10px;">Test Connection</button>
                    <?php if ($is_configured): ?>
                        <button type="submit" name="ofast_send_test_whatsapp" class="button" style="margin-left: 10px; background: #10b981; border-color: #10b981; color: #fff;">Send Test Message</button>
                    <?php endif; ?>
                    <a href="https://termii.com" target="_blank" class="button" style="margin-left: 10px;" id="provider_link">Get Termii</a>
                </p>
            </form>
        </div>
        <script>
            function toggleProviderFields() {
                var provider = document.getElementById('whatsapp_provider').value;
                var isTermii = provider === 'termii';

                document.getElementById('api_secret_row').style.display = isTermii ? 'none' : '';
                document.getElementById('sender_id_row').style.display = isTermii ? 'none' : '';
                document.getElementById('api_key_desc').textContent = isTermii ?
                    'Get from termii.com → Settings → API Key' : 'Find in Twilio Console';
                document.getElementById('provider_link').textContent = isTermii ? 'Get Termii' : 'Get Twilio';
                document.getElementById('provider_link').href = isTermii ? 'https://termii.com' : 'https://console.twilio.com';
            }
        </script>
<?php
    }
}
