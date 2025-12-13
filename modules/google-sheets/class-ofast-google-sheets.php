<?php

/**
 * Ofast X - Google Sheets Integration
 * Sync form submissions and events to Google Sheets
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Google_Sheets
{
    private static $instance = null;
    private $enabled = false;
    private $spreadsheet_id = '';
    private $credentials = array();
    private $access_token = null;
    private $token_expires = 0;

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
        $settings = get_option('ofast_google_sheets', array());

        $this->enabled = !empty($settings['enabled']);
        $this->spreadsheet_id = isset($settings['spreadsheet_id']) ? $settings['spreadsheet_id'] : '';

        // Load and decrypt credentials
        if (!empty($settings['credentials'])) {
            if (class_exists('Ofast_X_Security_Hardening')) {
                $decrypted = Ofast_X_Security_Hardening::decrypt_option($settings['credentials']);
                if ($decrypted) {
                    $this->credentials = json_decode($decrypted, true);
                }
            } else {
                $this->credentials = json_decode($settings['credentials'], true);
            }
        }
    }

    /**
     * Check if configured properly
     */
    public function is_configured()
    {
        return $this->enabled &&
            !empty($this->spreadsheet_id) &&
            isset($this->credentials['client_email']) &&
            isset($this->credentials['private_key']);
    }

    /**
     * Check if enabled
     */
    public function is_enabled()
    {
        return $this->enabled;
    }

    /**
     * Save settings
     */
    public static function save_settings($data, $files = array())
    {
        $settings = array(
            'enabled' => !empty($data['enabled']),
            'spreadsheet_id' => sanitize_text_field($data['spreadsheet_id'] ?? ''),
        );

        $json = '';

        // Priority 1: Check for file upload
        if (!empty($files['credentials_file']['tmp_name']) && is_uploaded_file($files['credentials_file']['tmp_name'])) {
            $json = file_get_contents($files['credentials_file']['tmp_name']);
        }
        // Priority 2: Check for pasted JSON
        elseif (!empty($data['credentials'])) {
            // Use wp_unslash to prevent stripping of special chars
            $json = wp_unslash($data['credentials']);
        }

        // Handle credentials JSON
        if (!empty($json)) {
            // Validate JSON
            $decoded = json_decode($json, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['client_email'])) {
                // Encrypt before storing
                if (class_exists('Ofast_X_Security_Hardening')) {
                    $settings['credentials'] = Ofast_X_Security_Hardening::encrypt_option($json);
                } else {
                    $settings['credentials'] = base64_encode($json);
                }
                // Store service account email for display
                $settings['service_email'] = sanitize_email($decoded['client_email']);
            }
        } else {
            // Keep existing credentials
            $existing = get_option('ofast_google_sheets', array());
            if (!empty($existing['credentials'])) {
                $settings['credentials'] = $existing['credentials'];
            }
            if (!empty($existing['service_email'])) {
                $settings['service_email'] = $existing['service_email'];
            }
        }

        update_option('ofast_google_sheets', $settings);
    }

    /**
     * Append a row to the spreadsheet
     */
    public function append_row($sheet_name, $values)
    {
        if (!$this->is_configured()) {
            return array('success' => false, 'error' => 'Google Sheets not configured');
        }

        // Sanitize sheet name - remove special characters that could cause issues
        $sheet_name = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $sheet_name);
        if (empty($sheet_name)) {
            $sheet_name = 'Sheet1';
        }

        // Sanitize all values in the row
        $sanitized_values = array();
        foreach ($values as $value) {
            if (is_array($value)) {
                $value = implode(', ', array_map('sanitize_text_field', $value));
            } else {
                $value = sanitize_text_field($value);
            }
            // Limit each cell to prevent abuse
            $sanitized_values[] = mb_substr($value, 0, 1000);
        }

        $token = $this->get_access_token();
        if (!$token) {
            return array('success' => false, 'error' => 'Failed to get access token');
        }

        $range = $sheet_name . '!A:Z';
        $url = sprintf(
            'https://sheets.googleapis.com/v4/spreadsheets/%s/values/%s:append?valueInputOption=USER_ENTERED',
            rawurlencode($this->spreadsheet_id),
            urlencode($range)
        );

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'values' => array($sanitized_values)
            )),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300) {
            return array('success' => true, 'updates' => $body['updates'] ?? null);
        }

        $error = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown error';
        return array('success' => false, 'error' => $error);
    }

    /**
     * Get OAuth access token using service account
     */
    private function get_access_token()
    {
        // Return cached token if still valid
        if ($this->access_token && time() < $this->token_expires - 60) {
            return $this->access_token;
        }

        if (empty($this->credentials['private_key']) || empty($this->credentials['client_email'])) {
            return null;
        }

        // Create JWT
        $header = base64_encode(json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));
        $now = time();
        $claims = array(
            'iss' => $this->credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now
        );
        $payload = base64_encode(json_encode($claims));

        // Sign with private key
        $signing_input = $header . '.' . $payload;
        $private_key = openssl_pkey_get_private($this->credentials['private_key']);

        if (!$private_key) {
            return null;
        }

        openssl_sign($signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256);
        $signature_b64 = str_replace(array('+', '/', '='), array('-', '_', ''), base64_encode($signature));

        $jwt = $signing_input . '.' . $signature_b64;

        // Exchange JWT for access token
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body' => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['access_token'])) {
            $this->access_token = $body['access_token'];
            $this->token_expires = $now + ($body['expires_in'] ?? 3600);
            return $this->access_token;
        }

        return null;
    }

    /**
     * Render settings form
     */
    public function render_settings_form()
    {
        // Handle form submission with security checks
        if (isset($_POST['ofast_save_google_sheets'])) {
            // Verify capability first
            if (!current_user_can('manage_options')) {
                wp_die('Permission denied');
            }

            // Verify nonce
            if (!wp_verify_nonce($_POST['sheets_nonce'] ?? '', 'ofast_sheets_save')) {
                wp_die('Security check failed');
            }

            self::save_settings($_POST, $_FILES);
            $this->load_settings();
            echo '<div class="notice notice-success"><p>Google Sheets settings saved!</p></div>';
        }

        $settings = get_option('ofast_google_sheets', array());
        $has_credentials = !empty($settings['credentials']);
?>
        <h3>Google Sheets Integration</h3>
        <p>Sync form submissions to Google Sheets automatically.</p>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('ofast_sheets_save', 'sheets_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Enable</th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php checked($this->enabled); ?>>
                            Enable Google Sheets integration
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Spreadsheet ID</th>
                    <td>
                        <input type="text" name="spreadsheet_id" value="<?php echo esc_attr($this->spreadsheet_id); ?>" class="regular-text">
                        <p class="description">
                            Find this in your spreadsheet URL: docs.google.com/spreadsheets/d/<strong>SPREADSHEET_ID</strong>/edit
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Service Account Credentials</th>
                    <td>
                        <?php if ($has_credentials): ?>
                            <p style="color:green;margin-bottom:10px;">
                                ✓ Credentials stored (encrypted)
                                <?php if (!empty($settings['service_email'])): ?>
                                    <br><small>Service account: <?php echo esc_html($settings['service_email']); ?></small>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>

                        <div style="margin-bottom: 15px;">
                            <label><strong>Upload JSON File:</strong></label><br>
                            <input type="file" name="credentials_file" id="ofast_sheets_file" accept=".json" style="margin-top: 5px;">
                            <p class="description">Upload your service-account-key.json file - content will appear below</p>
                        </div>

                        <div>
                            <label><strong>JSON Content:</strong></label><br>
                            <textarea name="credentials" id="ofast_sheets_json" rows="6" class="large-text code" placeholder="Upload a file above or paste your service account JSON here..."></textarea>
                        </div>

                        <p class="description" style="margin-top: 10px;">
                            <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Create a service account</a>
                            and download the JSON key. Share your spreadsheet with the service account email.
                        </p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="submit" name="ofast_save_google_sheets" class="button button-primary">Save Google Sheets Settings</button>
                <?php if ($this->is_configured()): ?>
                    <button type="button" class="button" onclick="testGoogleSheets()">Test Connection</button>
                <?php endif; ?>
            </p>
        </form>

        <script>
            // File upload reader - populates textarea when file selected
            document.getElementById('ofast_sheets_file').addEventListener('change', function(e) {
                var file = e.target.files[0];
                if (file) {
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        var content = e.target.result;
                        document.getElementById('ofast_sheets_json').value = content;

                        // Try to validate and prettify
                        try {
                            var parsed = JSON.parse(content);
                            document.getElementById('ofast_sheets_json').value = JSON.stringify(parsed, null, 2);
                        } catch (err) {
                            // Keep raw content if not valid JSON
                        }
                    };
                    reader.readAsText(file);
                }
            });

            function testGoogleSheets() {
                jQuery.post(ajaxurl, {
                    action: 'ofast_test_google_sheets',
                    nonce: '<?php echo wp_create_nonce('ofast_test_sheets'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('Connection successful!');
                    } else {
                        alert('Connection failed: ' + response.data);
                    }
                });
            }
        </script>
<?php
    }

    /**
     * Test connection via AJAX
     */
    public function ajax_test_connection()
    {
        check_ajax_referer('ofast_test_sheets', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $result = $this->append_row('Test', array(
            date('Y-m-d H:i:s'),
            'Connection Test',
            'Success'
        ));

        if ($result['success']) {
            wp_send_json_success('Connected!');
        } else {
            wp_send_json_error($result['error']);
        }
    }
}
