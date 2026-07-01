<?php

/**
 * Ofast X SMTP Module - Main Controller
 * Replaces default WordPress wp_mail with reliable SMTP
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_SMTP
{
    private static $instance = null;
    private $is_enabled = false;
    private $provider = 'default';
    private $current_log_id = null;

    // Rate limiting
    private $rate_limit_per_minute = 60;
    private $rate_limit_enabled = true;

    // Fallback SMTP: when true, get_smtp_option() returns fallback values
    private $fallback_active = false;

    // Store last failed email data for fallback retry
    private $last_email_args = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->is_enabled = get_option('ofast_smtp_enabled', false);
        $this->provider = get_option('ofast_smtp_provider', 'default');
    }

    /**
     * Initialize SMTP module
     */
    public function init()
    {
        // Load admin interface
        if (is_admin()) {
            require_once dirname(__FILE__) . '/class-ofast-smtp-admin.php';
            $admin = new Ofast_X_SMTP_Admin();
            $admin->init();
        }

        // Hook into WordPress mail if enabled (mailer_type determines behavior inside configure_phpmailer)
        if ($this->is_enabled) {
            add_action('phpmailer_init', array($this, 'configure_phpmailer'), 999);
        }

        // Rate limiting - load settings
        $this->rate_limit_enabled = get_option('ofast_smtp_rate_limit_enabled', true);
        $this->rate_limit_per_minute = get_option('ofast_smtp_rate_limit', 60);

        // Email logging - always log when SMTP is enabled
        if ($this->is_enabled) {
            // Logging
            add_filter('wp_mail', array($this, 'log_outgoing_email'), 10, 1);
            add_action('wp_mail_succeeded', array($this, 'mark_email_success'), 10, 1);
            add_action('wp_mail_failed', array($this, 'mark_email_failed'), 10, 1);

            // Rate limiting filter (runs before logging)
            if ($this->rate_limit_enabled) {
                add_filter('pre_wp_mail', array($this, 'check_rate_limit'), 5, 2);
            }
        }

        // AJAX handlers
        add_action('wp_ajax_ofast_test_smtp', array($this, 'ajax_test_smtp'));
        add_action('wp_ajax_ofast_smtp_port_test', array($this, 'ajax_port_test'));

        // Load health reports if enabled
        if (get_option('ofast_smtp_health_report_enabled', false)) {
            require_once dirname(__FILE__) . '/class-ofast-smtp-health-report.php';
            new Ofast_X_SMTP_Health_Report();
        }

        // Daily cleanup of SMTP logs (retention controlled by option)
        if (!wp_next_scheduled('ofast_smtp_cleanup_logs')) {
            wp_schedule_event(time(), 'daily', 'ofast_smtp_cleanup_logs');
        }
        add_action('ofast_smtp_cleanup_logs', array($this, 'cleanup_old_logs'));

        // Hourly sweep to close stale pending rows (requests that died before hooks fired)
        if (!wp_next_scheduled('ofast_smtp_sweep_pending')) {
            wp_schedule_event(time(), 'hourly', 'ofast_smtp_sweep_pending');
        }
        add_action('ofast_smtp_sweep_pending', array($this, 'sweep_stale_pending'));
    }

    /**
     * Check rate limit before sending email
     */
    public function check_rate_limit($null, $atts)
    {
        // Skip rate limiting during campaign batch sends — the campaign processor
        // manages its own pacing via batch_size + batch_delay settings.
        if ( ! empty( $GLOBALS['ofast_campaign_active'] ) ) {
            return $null;
        }

        $transient_key = 'ofast_smtp_rate_' . date('Y-m-d-H-i');
        $current_count = get_transient($transient_key) ?: 0;

        if ($current_count >= $this->rate_limit_per_minute) {
            // Log the rate-limited email so it appears in the Email Log
            $this->log_rate_limited_email($atts);

            // Rate limit exceeded - log and block
            error_log('Ofast SMTP: Rate limit exceeded (' . $this->rate_limit_per_minute . '/min). Email blocked.');

            // Return a WP_Error to prevent sending
            return new WP_Error(
                'rate_limit_exceeded',
                sprintf('SMTP rate limit exceeded. Maximum %d emails per minute allowed.', $this->rate_limit_per_minute)
            );
        }

        // Increment counter with 1 minute expiry
        set_transient($transient_key, $current_count + 1, 60);

        return $null; // Continue normal processing
    }

    /**
     * Configure PHPMailer with SMTP settings
     */
    public function configure_phpmailer($phpmailer)
    {
        // When fallback is active, skip default mailer type check — always use SMTP fallback
        if (!$this->fallback_active) {
            $mailer_type = get_option('ofast_smtp_mailer_type', 'default');

            // PHP Mail (Default) - uses server's native mail function
            if ($mailer_type === 'default') {
                $phpmailer->isMail();

                // Only set From if configured
                $from_email = get_option('ofast_smtp_from_email', '');
                $from_name = get_option('ofast_smtp_from_name', get_bloginfo('name'));
                if (!empty($from_email)) {
                    $phpmailer->From = $from_email;
                    $phpmailer->FromName = $from_name;
                }

                $phpmailer->XMailer = 'Ofast Mailer';
                return;
            }
        }

        // SMTP mode - uses get_smtp_option() which auto-switches to fallback values
        $phpmailer->isSMTP();
        $phpmailer->Host = $this->get_smtp_option('host', '');
        $phpmailer->Port = $this->get_smtp_option('port', 587);
        $phpmailer->Timeout = 10;
        $phpmailer->SMTPAuth = true;
        $phpmailer->Username = $this->get_smtp_option('username', '');
        $phpmailer->Password = $this->decrypt_password($this->get_smtp_option('password', ''));

        // Encryption
        $encryption = $this->get_smtp_option('encryption', 'tls');
        if ($encryption === 'ssl') {
            $phpmailer->SMTPSecure = 'ssl';
        } elseif ($encryption === 'tls') {
            $phpmailer->SMTPSecure = 'tls';
        } else {
            $phpmailer->SMTPSecure = '';
            $phpmailer->SMTPAutoTLS = false;
        }

        // From settings — fallback may have its own from address
        $from_email = $this->get_smtp_option('from_email', '');
        $from_name = $this->get_smtp_option('from_name', get_bloginfo('name'));

        if (!empty($from_email)) {
            $phpmailer->From = $from_email;
            $phpmailer->FromName = $from_name;
        }

        // SECURITY: Sanitize headers to hide system fingerprint
        $phpmailer->XMailer = 'Ofast Mailer';

        // Remove headers that expose server info
        $phpmailer->clearCustomHeaders();

        // Add minimal safe headers only
        $phpmailer->addCustomHeader('X-Priority', '3');

        // Debug mode for testing (only in development)
        if (defined('OFAST_SMTP_DEBUG') && OFAST_SMTP_DEBUG && defined('WP_DEBUG') && WP_DEBUG) {
            $phpmailer->SMTPDebug = 2;
        }
    }

    /**
     * Get an SMTP option, checking fallback prefix when fallback is active.
     * When $this->fallback_active is true, reads from ofast_smtp_fallback_* options.
     *
     * @param string $key     Option key suffix (e.g. 'host', 'port', 'username')
     * @param mixed  $default Default value
     * @return mixed
     */
    private function get_smtp_option($key, $default = '')
    {
        $prefix = $this->fallback_active ? 'ofast_smtp_fallback_' : 'ofast_smtp_';
        return get_option($prefix . $key, $default);
    }

    /**
     * Check if fallback SMTP is enabled and configured
     */
    private function is_fallback_enabled()
    {
        if (!get_option('ofast_smtp_fallback_enabled', false)) {
            return false;
        }
        // Must have at least a host configured
        $host = get_option('ofast_smtp_fallback_host', '');
        return !empty($host);
    }

    /**
     * AJAX: Test SMTP connection
     */
    public function ajax_test_smtp()
    {
        check_ajax_referer('ofast_test_smtp', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Check mailer type - use saved value if not provided
        $mailer_type = isset($_POST['mailer_type']) ? sanitize_text_field($_POST['mailer_type']) : get_option('ofast_smtp_mailer_type', 'default');
        $from_email = isset($_POST['from_email']) ? sanitize_email($_POST['from_email']) : get_option('ofast_smtp_from_email', '');
        $from_name = isset($_POST['from_name']) ? sanitize_text_field($_POST['from_name']) : get_option('ofast_smtp_from_name', get_bloginfo('name'));
        $admin_email = get_option('admin_email');

        // PHP Mail Default mode - use wp_mail directly (no SMTP credentials needed)
        if ($mailer_type === 'default') {
            $headers = array('Content-Type: text/html; charset=UTF-8');
            if (!empty($from_email)) {
                $headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
            }

            $subject = sprintf('[%s] PHP Mail Test - ', get_bloginfo('name')) . date('Y-m-d H:i:s');
            $body = $this->get_test_email_body();

            $result = wp_mail($admin_email, $subject, $body, $headers);

            if ($result) {
                wp_send_json_success(array(
                    'message' => 'Test email sent successfully! Check inbox at ' . $admin_email,
                    'details' => array(
                        'mailer' => 'PHP Mail (Default)',
                        'from' => $from_email ?: 'Server default'
                    )
                ));
            } else {
                global $phpmailer;
                $error = isset($phpmailer) && $phpmailer->ErrorInfo ? $phpmailer->ErrorInfo : 'Server mail() failed';
                wp_send_json_error(array(
                    'message' => 'PHP Mail failed',
                    'error' => $error,
                    'suggestion' => 'Your server may not support mail(). Switch to "Other SMTP" mode.'
                ));
            }
            return;
        }

        // SMTP mode - requires credentials
        $host = sanitize_text_field($_POST['host'] ?? '');
        $port = intval($_POST['port'] ?? 587);
        $encryption = sanitize_text_field($_POST['encryption'] ?? 'tls');
        $username = sanitize_text_field($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // If password is placeholder, use saved password
        if ($password === '••••••••' || empty($password)) {
            $saved_password = get_option('ofast_smtp_password', '');
            if (!empty($saved_password)) {
                $password = $this->decrypt_password($saved_password);
            }
        }

        // Validate SMTP fields
        if (empty($host) || empty($username) || empty($password) || empty($from_email)) {
            wp_send_json_error('Please fill in all required SMTP fields (Host, Username, Password, From Email)');
        }

        // Test using PHPMailer directly
        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $port;
            $mail->Timeout = 10;
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $password;

            if ($encryption === 'ssl') {
                $mail->SMTPSecure = 'ssl';
            } elseif ($encryption === 'tls') {
                $mail->SMTPSecure = 'tls';
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom($from_email, $from_name);
            $mail->addAddress(get_option('admin_email'));
            $mail->isHTML(true);
            $mail->Subject = sprintf('[%s] SMTP Test - ', get_bloginfo('name')) . date('Y-m-d H:i:s');
            $mail->Body = $this->get_test_email_body();
            $mail->AltBody = sprintf(__('This is a test email from %s.', 'ofast-x'), get_bloginfo('name'));

            $mail->send();

            wp_send_json_success(array(
                'message' => 'Test email sent successfully! Check your inbox at ' . get_option('admin_email'),
                'details' => array(
                    'host' => $host,
                    'port' => $port,
                    'encryption' => $encryption
                )
            ));
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'SMTP connection failed',
                'error' => $mail->ErrorInfo,
                'suggestion' => $this->get_error_suggestion($mail->ErrorInfo)
            ));
        }
    }

    /**
     * Get test email body HTML
     */
    private function get_test_email_body()
    {
        $site_name = get_bloginfo('name');
        $time = current_time('mysql');

        return "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
            <div style='background: linear-gradient(135deg, #6366f1, #10b981); padding: 30px; border-radius: 10px 10px 0 0; text-align: center;'>
                <h1 style='color: #fff; margin: 0;'>SMTP Test Successful!</h1>
            </div>
            <div style='background: #fff; padding: 30px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 10px 10px;'>
                <p style='font-size: 16px; color: #374151;'>Your SMTP configuration is working correctly.</p>
                <div style='background: #f0fdf4; border-left: 4px solid #10b981; padding: 15px; margin: 20px 0;'>
                    <p style='margin: 0; color: #166534;'><strong>Site:</strong> {$site_name}</p>
                    <p style='margin: 5px 0 0 0; color: #166534;'><strong>Time:</strong> {$time}</p>
                </div>
                <p style='font-size: 14px; color: #6b7280;'>This test email confirms that your WordPress site can send emails through your configured SMTP server.</p>
            </div>
        </div>";
    }

    /**
     * Get helpful error suggestions
     */
    private function get_error_suggestion($error)
    {
        $error = strtolower($error);

        if (strpos($error, 'authentication') !== false) {
            return 'Check your username and password. For Gmail/Zoho, use an App Password.';
        }
        if (strpos($error, 'connection') !== false || strpos($error, 'connect') !== false) {
            return 'Check your host and port. Common ports: 587 (TLS), 465 (SSL), 25 (No encryption).';
        }
        if (strpos($error, 'certificate') !== false) {
            return 'SSL/TLS certificate issue. Try changing encryption setting.';
        }
        if (strpos($error, 'timeout') !== false) {
            return 'Connection timed out. Your host may be blocking SMTP connections.';
        }

        return 'Check all settings and ensure your SMTP credentials are correct.';
    }

    /**
     * Encrypt password for storage
     */
    public static function encrypt_password($password)
    {
        if (empty($password)) {
            return '';
        }

        if (!defined('SECURE_AUTH_KEY') || empty(SECURE_AUTH_KEY)) {
            return base64_encode($password);
        }

        $key = hash('sha256', SECURE_AUTH_KEY);
        $iv = substr(hash('sha256', AUTH_KEY), 0, 16);
        $encrypted = openssl_encrypt($password, 'AES-256-CBC', $key, 0, $iv);

        return base64_encode($encrypted);
    }

    /**
     * Decrypt password from storage
     */
    private function decrypt_password($encrypted)
    {
        if (empty($encrypted)) {
            return '';
        }

        if (!defined('SECURE_AUTH_KEY') || empty(SECURE_AUTH_KEY)) {
            return base64_decode($encrypted);
        }

        $key = hash('sha256', SECURE_AUTH_KEY);
        $iv = substr(hash('sha256', AUTH_KEY), 0, 16);
        $decoded = base64_decode($encrypted);

        return openssl_decrypt($decoded, 'AES-256-CBC', $key, 0, $iv);
    }

    /**
     * Get common SMTP presets
     */
    public static function get_provider_presets()
    {
        return array(
            'zoho' => array(
                'name' => 'Zoho Mail',
                'host' => 'smtp.zoho.com',
                'port' => 587,
                'encryption' => 'tls',
                'note' => 'Use your Zoho email and App Password'
            ),
            'gmail' => array(
                'name' => 'Gmail / Google Workspace',
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'encryption' => 'tls',
                'note' => 'Enable 2FA and create App Password'
            ),
            'sendgrid' => array(
                'name' => 'SendGrid',
                'host' => 'smtp.sendgrid.net',
                'port' => 587,
                'encryption' => 'tls',
                'note' => 'Username: apikey, Password: your API key'
            ),
            'mailgun' => array(
                'name' => 'Mailgun',
                'host' => 'smtp.mailgun.org',
                'port' => 587,
                'encryption' => 'tls',
                'note' => 'Use SMTP credentials from Mailgun dashboard'
            ),
            'outlook' => array(
                'name' => 'Outlook / Office 365',
                'host' => 'smtp.office365.com',
                'port' => 587,
                'encryption' => 'tls',
                'note' => 'Use your Microsoft account email'
            ),
            'brevo' => array(
                'name' => 'Brevo (Sendinblue)',
                'host' => 'smtp-relay.brevo.com',
                'port' => 587,
                'encryption' => 'tls',
                'note' => 'Login: your email, Password: SMTP key from Brevo dashboard'
            ),
            'aws' => array(
                'name' => 'Amazon SES',
                'host' => 'email-smtp.us-east-1.amazonaws.com',
                'port' => 587,
                'encryption' => 'tls',
                'note' => 'Use SMTP credentials from AWS SES console (not IAM keys)'
            ),
            'custom' => array(
                'name' => 'Custom SMTP',
                'host' => '',
                'port' => 587,
                'encryption' => 'tls',
                'note' => 'Enter your own SMTP server details'
            )
        );
    }

    /**
     * Log outgoing email (before sending)
     */
    public function log_outgoing_email($args)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ofast_smtp_log';

        // Create table if not exists
        $this->ensure_log_table();

        // Store email args for potential fallback retry
        $this->last_email_args = $args;

        // Get recipient(s) as string
        $to = is_array($args['to']) ? implode(', ', $args['to']) : $args['to'];

        $log_body_content = ofast_toolkit_is_pro() ? (bool) get_option('ofast_smtp_log_body_content', false) : false;

        // Insert log entry
        $wpdb->insert($table_name, array(
            'to_email' => sanitize_text_field($to),
            'subject' => sanitize_text_field($args['subject']),
            // Respect the content logging setting and omit bodies when disabled.
            'body' => $log_body_content ? $args['message'] : '',
            'headers' => is_array($args['headers']) ? serialize($args['headers']) : $args['headers'],
            'status' => 'pending',
            'sent_at' => current_time('mysql')
        ));

        // Store the log ID for later status update
        $this->current_log_id = $wpdb->insert_id;

        return $args;
    }

    /**
     * Mark email as successful
     */
    public function mark_email_success($mail_data)
    {
        if (!empty($this->current_log_id)) {
            global $wpdb;
            $status_label = $this->fallback_active ? 'success (fallback)' : 'success';
            $wpdb->update(
                $wpdb->prefix . 'ofast_smtp_log',
                array('status' => $status_label),
                array('id' => $this->current_log_id)
            );
            $this->current_log_id = null;
        }

        // Increment lifetime success counter
        $this->increment_counter('ofast_smtp_success_count');
    }

    /**
     * Mark email as failed — then attempt fallback SMTP if configured
     */
    public function mark_email_failed($error)
    {
        if (!empty($this->current_log_id)) {
            global $wpdb;
            $error_message = '';

            if (is_wp_error($error)) {
                $error_message = $error->get_error_message();
            } elseif (is_object($error) && isset($error->mail_data)) {
                $error_message = $error->get_error_message() ?? 'Unknown error';
            }

            $wpdb->update(
                $wpdb->prefix . 'ofast_smtp_log',
                array(
                    'status' => 'failed',
                    'error_message' => sanitize_text_field($error_message)
                ),
                array('id' => $this->current_log_id)
            );
        }

        // Increment lifetime failure counter
        $this->increment_counter('ofast_smtp_failed_count');

        // Attempt fallback SMTP if not already in fallback mode
        if (!$this->fallback_active && $this->is_fallback_enabled() && !empty($this->last_email_args)) {
            $saved_log_id = $this->current_log_id;
            $this->current_log_id = null; // Prevent duplicate log update

            $this->fallback_active = true;

            // Remove and re-add our logging hooks to prevent duplicate log entries
            remove_filter('wp_mail', array($this, 'log_outgoing_email'), 10);
            remove_action('wp_mail_succeeded', array($this, 'mark_email_success'), 10);
            remove_action('wp_mail_failed', array($this, 'mark_email_failed'), 10);

            $args = $this->last_email_args;
            $fallback_result = wp_mail(
                $args['to'],
                $args['subject'],
                $args['message'],
                isset($args['headers']) ? $args['headers'] : '',
                isset($args['attachments']) ? $args['attachments'] : array()
            );

            // Re-add hooks
            add_filter('wp_mail', array($this, 'log_outgoing_email'), 10, 1);
            add_action('wp_mail_succeeded', array($this, 'mark_email_success'), 10, 1);
            add_action('wp_mail_failed', array($this, 'mark_email_failed'), 10, 1);

            $this->fallback_active = false;

            // Update original log entry with fallback result
            if ($fallback_result && $saved_log_id) {
                global $wpdb;
                $wpdb->update(
                    $wpdb->prefix . 'ofast_smtp_log',
                    array(
                        'status' => 'success (fallback)',
                        'error_message' => 'Primary failed: ' . sanitize_text_field($error_message) . ' — Sent via fallback SMTP'
                    ),
                    array('id' => $saved_log_id)
                );
                // Count fallback success
                $this->increment_counter('ofast_smtp_success_count');
                $this->increment_counter('ofast_smtp_fallback_used_count');
            }
        }

        $this->current_log_id = null;
    }

    /**
     * Log a rate-limited email so it's visible in the Email Log.
     */
    private function log_rate_limited_email($atts)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ofast_smtp_log';
        $this->ensure_log_table();

        $to = '';
        $subject = '';

        if (is_array($atts)) {
            $to_value = isset($atts['to']) ? $atts['to'] : '';
            $to = is_array($to_value) ? implode(', ', $to_value) : $to_value;
            $subject = isset($atts['subject']) ? $atts['subject'] : '';
        }

        $wpdb->insert($table_name, array(
            'to_email' => sanitize_text_field($to),
            'subject' => sanitize_text_field($subject),
            'body' => '',
            'headers' => '',
            'status' => 'rate_limited',
            'error_message' => sprintf('Rate limit exceeded (%d/min)', $this->rate_limit_per_minute),
            'sent_at' => current_time('mysql'),
        ));

        // Count rate-limited emails as blocked
        $this->increment_counter('ofast_smtp_failed_count');
    }

    /**
     * Ensure log table exists
     */
    private function ensure_log_table()
    {
        // Check cache first to avoid redundant DB queries
        if (get_transient('ofast_smtp_log_table_exists')) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ofast_smtp_log';

        // Check if table exists using proper escaping
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_name
        ));

        if ($table_exists !== $table_name) {
            $charset = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE {$table_name} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                to_email varchar(255) NOT NULL,
                subject varchar(255) NOT NULL,
                body longtext NOT NULL,
                headers text,
                status varchar(20) DEFAULT 'pending',
                error_message text,
                sent_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY status (status),
                KEY sent_at (sent_at)
            ) {$charset};";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }

        // Cache the result for 24 hours
        set_transient('ofast_smtp_log_table_exists', true, DAY_IN_SECONDS);
    }

    /**
     * Sweep stale pending rows — runs hourly.
     * Marks pending log rows older than the configured timeout as failed,
     * covering requests that died or timed out before the success/fail hook fired.
     */
    public function sweep_stale_pending()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_smtp_log';
        $pending_timeout_minutes = (int) apply_filters('ofast_smtp_pending_timeout_minutes', 10);

        if ($pending_timeout_minutes <= 0) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
            SET status = 'failed',
                error_message = %s
            WHERE status = 'pending'
            AND sent_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)",
            sprintf('Marked failed: remained pending for more than %d minutes (request likely timed out or was aborted).', $pending_timeout_minutes),
            $pending_timeout_minutes
        ));
    }

    /**
     * Cleanup old SMTP logs to prevent database bloat — runs daily.
     */
    public function cleanup_old_logs()
    {
        $retention_days = (int) get_option('ofast_smtp_log_retention_days', 90);
        if ($retention_days <= 0) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_smtp_log';

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE sent_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retention_days
        ));
    }

    // =========================================================================
    // Delivery Counters — persist lifetime stats beyond log cleanup
    // =========================================================================

    /**
     * Increment a named counter option.
     * Uses autoload=false to keep options table lean.
     */
    private function increment_counter($option_name)
    {
        $current = (int) get_option($option_name, 0);
        update_option($option_name, $current + 1, false);
    }

    /**
     * Get lifetime delivery statistics.
     * These persist even after log cleanup.
     *
     * @return array ['success' => int, 'failed' => int, 'fallback_used' => int]
     */
    public static function get_delivery_stats()
    {
        return array(
            'success'        => (int) get_option('ofast_smtp_success_count', 0),
            'failed'         => (int) get_option('ofast_smtp_failed_count', 0),
            'fallback_used'  => (int) get_option('ofast_smtp_fallback_used_count', 0),
        );
    }

    // =========================================================================
    // Port Connectivity Test — AJAX handler
    // =========================================================================

    /**
     * AJAX: Test SMTP port connectivity, auth methods, STARTTLS, and MITM detection
     */
    public function ajax_port_test()
    {
        check_ajax_referer('ofast_port_test', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $hostname = sanitize_text_field($_POST['hostname'] ?? '');
        if (empty($hostname)) {
            wp_send_json_error('Hostname is required');
        }

        require_once dirname(__FILE__) . '/class-ofast-smtp-port-test.php';
        $tester = new Ofast_X_SMTP_Port_Test();

        $ports = isset($_POST['ports']) ? array_map('intval', (array) $_POST['ports']) : array(25, 465, 587);
        $results = array();

        foreach ($ports as $port) {
            $results[$port] = $tester->test_port($hostname, $port);
        }

        wp_send_json_success(array(
            'hostname' => $hostname,
            'results'  => $results
        ));
    }

    /**
     * Validate that encryption keys are available for secure password storage.
     */
    public static function validate_encryption_keys()
    {
        return defined('SECURE_AUTH_KEY') && !empty(SECURE_AUTH_KEY)
            && defined('AUTH_KEY') && !empty(AUTH_KEY)
            && SECURE_AUTH_KEY !== 'put your unique phrase here'
            && AUTH_KEY !== 'put your unique phrase here';
    }

    /**
     * Get diagnostic info about encryption key validation.
     */
    public static function get_key_validation_details()
    {
        if (!defined('SECURE_AUTH_KEY') || empty(SECURE_AUTH_KEY)) {
            return array(
                'valid' => false,
                'message' => 'SECURE_AUTH_KEY is not defined in wp-config.php.',
                'suggestion' => 'Add unique security keys to wp-config.php using the WordPress salt generator.'
            );
        }
        if (SECURE_AUTH_KEY === 'put your unique phrase here') {
            return array(
                'valid' => false,
                'message' => 'SECURE_AUTH_KEY is set to the default placeholder value.',
                'suggestion' => 'Replace it with a unique key from https://api.wordpress.org/secret-key/1.1/salt/'
            );
        }
        return array('valid' => true, 'message' => 'Encryption keys are valid.', 'suggestion' => '');
    }
}
