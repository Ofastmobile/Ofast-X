<?php

/**
 * Ofast X - Google Sheets Integration
 * Logs form submissions to Google Sheets via Sheets API v4
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Google_Sheets
{
    private static $instance = null;

    // Settings
    private $enabled;
    private $credentials; // Service Account JSON
    private $spreadsheet_id;
    private $access_token;
    private $token_expires;

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
        $this->enabled = get_option('ofast_gsheets_enabled', false);
        $this->spreadsheet_id = get_option('ofast_gsheets_spreadsheet_id', '');

        // Load credentials (encrypted JSON)
        $encrypted_creds = get_option('ofast_gsheets_credentials', '');
        if (!empty($encrypted_creds)) {
            if (class_exists('Ofast_X_Security_Hardening')) {
                $decrypted = Ofast_X_Security_Hardening::decrypt_option($encrypted_creds);
                if (!empty($decrypted)) {
                    $this->credentials = json_decode($decrypted, true);
                }
            } else {
                $this->credentials = json_decode($encrypted_creds, true);
            }
        }

        // Load cached access token
        $token_data = get_transient('ofast_gsheets_access_token');
        if ($token_data) {
            $this->access_token = $token_data['token'];
            $this->token_expires = $token_data['expires'];
        }
    }

    /**
     * Check if Google Sheets is configured
     */
    public function is_configured()
    {
        return $this->enabled &&
            !empty($this->credentials) &&
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
    public static function save_settings($data)
    {
        update_option('ofast_gsheets_enabled', !empty($data['enabled']));

        if (isset($data['spreadsheet_id'])) {
            update_option('ofast_gsheets_spreadsheet_id', sanitize_text_field($data['spreadsheet_id']));
        }

        // Handle credential file upload or text input
        if (!empty($data['credentials_json'])) {
            $creds = json_decode($data['credentials_json'], true);
            if ($creds && isset($creds['client_email'])) {
                if (class_exists('Ofast_X_Security_Hardening')) {
                    update_option(
                        'ofast_gsheets_credentials',
                        Ofast_X_Security_Hardening::encrypt_option($data['credentials_json'])
                    );
                } else {
                    update_option('ofast_gsheets_credentials', $data['credentials_json']);
                }
            }
        }

        // Clear token cache when settings change
        delete_transient('ofast_gsheets_access_token');

        // Reset instance to reload settings
        self::$instance = null;
    }

    /**
     * Get OAuth2 access token using service account JWT
     */
    private function get_access_token()
    {
        // Return cached token if still valid
        if ($this->access_token && $this->token_expires > time()) {
            return $this->access_token;
        }

        if (!$this->credentials) {
            return false;
        }

        // Build JWT
        $header = json_encode(array('alg' => 'RS256', 'typ' => 'JWT'));
        $now = time();
        $claim = json_encode(array(
            'iss' => $this->credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now
        ));

        // Encode JWT parts
        $base64_header = $this->base64url_encode($header);
        $base64_claim = $this->base64url_encode($claim);
        $signature_input = $base64_header . '.' . $base64_claim;

        // Sign with private key
        $private_key = $this->credentials['private_key'];
        $signature = '';
        if (!openssl_sign($signature_input, $signature, $private_key, OPENSSL_ALGO_SHA256)) {
            return false;
        }

        $jwt = $signature_input . '.' . $this->base64url_encode($signature);

        // Exchange JWT for access token
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'timeout' => 15,
            'body' => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            )
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['access_token'])) {
            $this->access_token = $body['access_token'];
            $this->token_expires = time() + ($body['expires_in'] ?? 3600) - 60;

            // Cache the token
            set_transient('ofast_gsheets_access_token', array(
                'token' => $this->access_token,
                'expires' => $this->token_expires
            ), 3500);

            return $this->access_token;
        }

        return false;
    }

    /**
     * Append row to a sheet
     * 
     * @param string $event_type The event type (determines which sheet to use)
     * @param array $row Array of values for the row
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function append_row($event_type, $row)
    {
        if (!$this->is_configured()) {
            return array(
                'success' => false,
                'error' => 'Google Sheets not configured',
                'skipped' => true
            );
        }

        $token = $this->get_access_token();
        if (!$token) {
            return array(
                'success' => false,
                'error' => 'Failed to get access token'
            );
        }

        // Map event type to sheet name
        $sheet_name = $this->get_sheet_name($event_type);

        // Build API URL
        $url = sprintf(
            'https://sheets.googleapis.com/v4/spreadsheets/%s/values/%s:append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS',
            $this->spreadsheet_id,
            urlencode($sheet_name . '!A1')
        );

        $response = wp_remote_post($url, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'values' => array($row)
            ))
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code === 200) {
            return array(
                'success' => true,
                'updated_range' => isset($body['updates']['updatedRange']) ? $body['updates']['updatedRange'] : null
            );
        }

        $error = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown error';
        return array(
            'success' => false,
            'error' => $error
        );
    }

    /**
     * Get sheet name for event type
     */
    private function get_sheet_name($event_type)
    {
        $sheet_names = get_option('ofast_gsheets_sheet_names', array());

        $defaults = array(
            'newsletter_subscription' => 'Subscribers',
            'contact_form' => 'Contacts',
            'woocommerce_order' => 'Orders',
            'custom' => 'Data'
        );

        return isset($sheet_names[$event_type]) ? $sheet_names[$event_type] : (isset($defaults[$event_type]) ? $defaults[$event_type] : 'Sheet1');
    }

    /**
     * Test connection to Google Sheets
     */
    public function test_connection()
    {
        if (!$this->is_configured()) {
            return array(
                'success' => false,
                'error' => 'Google Sheets not configured'
            );
        }

        $token = $this->get_access_token();
        if (!$token) {
            return array(
                'success' => false,
                'error' => 'Failed to get access token. Check credentials.'
            );
        }

        // Try to get spreadsheet info
        $url = sprintf(
            'https://sheets.googleapis.com/v4/spreadsheets/%s?fields=properties.title,sheets.properties.title',
            $this->spreadsheet_id
        );

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token
            )
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code === 200) {
            $sheets = array();
            if (isset($body['sheets'])) {
                foreach ($body['sheets'] as $sheet) {
                    $sheets[] = $sheet['properties']['title'];
                }
            }
            return array(
                'success' => true,
                'spreadsheet_title' => isset($body['properties']['title']) ? $body['properties']['title'] : 'Unknown',
                'sheets' => $sheets
            );
        }

        $error = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown error';
        return array(
            'success' => false,
            'error' => $error
        );
    }

    /**
     * Base64 URL encode
     */
    private function base64url_encode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Get spreadsheet ID
     */
    public function get_spreadsheet_id()
    {
        return $this->spreadsheet_id;
    }

    /**
     * Render settings form
     */
    public function render_settings_form()
    {
        // Handle form submission
        if (isset($_POST['ofast_save_gsheets']) && wp_verify_nonce($_POST['gsheets_nonce'], 'ofast_gsheets_save')) {
            self::save_settings(array(
                'enabled' => isset($_POST['gsheets_enabled']),
                'spreadsheet_id' => $_POST['spreadsheet_id'],
                'credentials_json' => isset($_POST['credentials_json']) ? $_POST['credentials_json'] : ''
            ));

            // Reload settings
            $this->load_settings();

            echo '<div class="notice notice-success"><p>Google Sheets settings saved!</p></div>';
        }

        // Handle test connection
        if (isset($_POST['ofast_test_gsheets']) && wp_verify_nonce($_POST['gsheets_nonce'], 'ofast_gsheets_save')) {
            $result = $this->test_connection();
            if ($result['success']) {
                $sheets_list = implode(', ', $result['sheets']);
                echo '<div class="notice notice-success"><p>✓ Connected to "' . esc_html($result['spreadsheet_title']) . '"<br>Sheets: ' . esc_html($sheets_list) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>✗ Connection failed: ' . esc_html($result['error']) . '</p></div>';
            }
        }

        $is_configured = $this->is_configured();
?>
        <div class="ofast-settings-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 20px;">
            <h3 style="margin-top: 0;">
                Google Sheets Logging
            </h3>
            <p style="color: #666; margin-bottom: 15px;">
                Automatically log form submissions to a Google Spreadsheet.
                <?php if ($is_configured): ?>
                    <span style="color: #46b450;">✓ Configured</span>
                <?php else: ?>
                    <span style="color: #dc3232;">✗ Not configured</span>
                <?php endif; ?>
            </p>
            <form method="post">
                <?php wp_nonce_field('ofast_gsheets_save', 'gsheets_nonce'); ?>
                <table class="form-table" style="margin: 0;">
                    <tr>
                        <th scope="row" style="padding: 10px 0;">Enable Google Sheets</th>
                        <td style="padding: 10px 0;">
                            <label>
                                <input type="checkbox"
                                    name="gsheets_enabled"
                                    value="1"
                                    <?php checked($this->enabled); ?>>
                                Enable Google Sheets logging
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding: 10px 0;">Spreadsheet ID</th>
                        <td style="padding: 10px 0;">
                            <input type="text"
                                name="spreadsheet_id"
                                value="<?php echo esc_attr($this->spreadsheet_id); ?>"
                                class="regular-text"
                                placeholder="1BxiMVs0XRA5n...">
                            <p class="description">The ID from your Google Sheets URL</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding: 10px 0;">Service Account JSON</th>
                        <td style="padding: 10px 0;">
                            <textarea name="credentials_json"
                                rows="4"
                                class="large-text code"
                                placeholder='<?php echo $is_configured ? "Credentials saved (paste new to replace)" : "Paste your service account JSON here..."; ?>'></textarea>
                            <p class="description">
                                Get from
                                <a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank">Google Cloud Console</a>
                                → Create Service Account → Keys → Add Key → JSON
                            </p>
                        </td>
                    </tr>
                </table>
                <?php if ($is_configured): ?>
                    <div style="background: #f0f8ff; padding: 15px; border-radius: 5px; margin-top: 15px;">
                        <strong>Sheet Names (auto-created if needed):</strong>
                        <ul style="margin: 10px 0 0 20px;">
                            <li>Newsletter → "Subscribers" sheet</li>
                            <li>Contact Form → "Contacts" sheet</li>
                            <li>WooCommerce → "Orders" sheet</li>
                        </ul>
                    </div>
                <?php endif; ?>
                <p style="margin-top: 15px;">
                    <button type="submit" name="ofast_save_gsheets" class="button button-primary">Save Settings</button>
                    <button type="submit" name="ofast_test_gsheets" class="button" style="margin-left: 10px;">Test Connection</button>
                </p>
            </form>
        </div>
<?php
    }
}
