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

        // Hook into WordPress mail if enabled
        if ($this->is_enabled && $this->provider !== 'default') {
            add_action('phpmailer_init', array($this, 'configure_phpmailer'), 999);
        }

        // Rate limiting - load settings
        $this->rate_limit_enabled = get_option('ofast_smtp_rate_limit_enabled', true);
        $this->rate_limit_per_minute = get_option('ofast_smtp_rate_limit', 60);

        // Email logging - always log when SMTP is enabled
        if ($this->is_enabled) {
            // SECURITY: Content sanitization (runs first, priority 5)
            add_filter('wp_mail', array($this, 'sanitize_email_content'), 5, 1);

            // Logging (runs after sanitization, priority 10)
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
    }

    /**
     * Check rate limit before sending email
     */
    public function check_rate_limit($null, $atts)
    {
        $transient_key = 'ofast_smtp_rate_' . date('Y-m-d-H-i');
        $current_count = get_transient($transient_key) ?: 0;

        if ($current_count >= $this->rate_limit_per_minute) {
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
     * SECURITY: Sanitize email content to remove sensitive information
     * Applies to ALL emails for maximum security - no exceptions
     */
    public function sanitize_email_content($args)
    {
        if (!empty($args['message'])) {
            $message = $args['message'];

            // 1. Remove ALL wp-admin URLs - replace with site URL
            $admin_url = admin_url();
            $message = str_replace($admin_url, site_url(), $message);
            $message = preg_replace('#https?://[^\s<>"\']+/wp-admin[^\s<>"\']*#i', site_url(), $message);

            // 2. Remove server file paths (e.g., /var/www/, C:\xampp\, etc.)
            $message = preg_replace('#(/var/www|/home/\w+|/srv|C:\\\\[^<\s]+|/usr/share)[^\s<>"\']*#i', '[hidden]', $message);

            // 3. Remove WordPress installation paths
            $abspath = preg_quote(ABSPATH, '#');
            $message = preg_replace('#' . $abspath . '[^\s<>"\']*#i', '[hidden]', $message);

            // 4. Remove debug patterns
            $debug_patterns = array(
                '#\bWP_DEBUG\b#i',
                '#\bPHP (Fatal|Warning|Notice|Error)[^<\n]*#i',
                '#Stack trace:[^<]*#is',
                '#\bin /[^\s]+\.php on line \d+#i',
                '#\bCall Stack\b[^<]*#is',
                '#\bvar_dump\s*\([^)]*\)#is',
                '#\bprint_r\s*\([^)]*\)#is',
            );
            foreach ($debug_patterns as $pattern) {
                $message = preg_replace($pattern, '', $message);
            }

            // 5. Remove MySQL/database info
            $message = preg_replace('#\b(mysql|mysqli|pdo|wpdb)\s*:?[^<\n]*#i', '', $message);

            // 6. Remove wp-config references
            $message = preg_replace('#wp-config\.php#i', '', $message);

            // 7. Remove WordPress version info
            $message = preg_replace('#WordPress\s+\d+\.\d+(\.\d+)?#i', 'WordPress', $message);

            // 8. Remove PHP version info
            $message = preg_replace('#PHP\s+\d+\.\d+(\.\d+)?#i', 'PHP', $message);

            $args['message'] = $message;
        }

        return $args;
    }

    /**
     * Configure PHPMailer with SMTP settings
     */
    public function configure_phpmailer($phpmailer)
    {
        $phpmailer->isSMTP();
        $phpmailer->Host = get_option('ofast_smtp_host', '');
        $phpmailer->Port = get_option('ofast_smtp_port', 587);
        $phpmailer->SMTPAuth = true;
        $phpmailer->Username = get_option('ofast_smtp_username', '');
        $phpmailer->Password = $this->decrypt_password(get_option('ofast_smtp_password', ''));

        // Encryption
        $encryption = get_option('ofast_smtp_encryption', 'tls');
        if ($encryption === 'ssl') {
            $phpmailer->SMTPSecure = 'ssl';
        } elseif ($encryption === 'tls') {
            $phpmailer->SMTPSecure = 'tls';
        } else {
            $phpmailer->SMTPSecure = '';
            $phpmailer->SMTPAutoTLS = false;
        }

        // From settings
        $from_email = get_option('ofast_smtp_from_email', '');
        $from_name = get_option('ofast_smtp_from_name', get_bloginfo('name'));

        if (!empty($from_email)) {
            $phpmailer->From = $from_email;
            $phpmailer->FromName = $from_name;
        }

        // SECURITY: Sanitize headers to hide system fingerprint
        $phpmailer->XMailer = 'Ofast Mailer'; // Hide PHPMailer version

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
     * AJAX: Test SMTP connection
     */
    public function ajax_test_smtp()
    {
        check_ajax_referer('ofast_test_smtp', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Get test settings from POST (not saved yet)
        $host = sanitize_text_field($_POST['host'] ?? '');
        $port = intval($_POST['port'] ?? 587);
        $encryption = sanitize_text_field($_POST['encryption'] ?? 'tls');
        $username = sanitize_text_field($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $from_email = sanitize_email($_POST['from_email'] ?? '');
        $from_name = sanitize_text_field($_POST['from_name'] ?? get_bloginfo('name'));

        // Validate
        if (empty($host) || empty($username) || empty($password) || empty($from_email)) {
            wp_send_json_error('Please fill in all required fields');
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
            $mail->Subject = 'Ofast X SMTP Test - ' . date('Y-m-d H:i:s');
            $mail->Body = $this->get_test_email_body();
            $mail->AltBody = 'This is a test email from Ofast X SMTP module.';

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

        // Get recipient(s) as string
        $to = is_array($args['to']) ? implode(', ', $args['to']) : $args['to'];

        // Insert log entry
        $wpdb->insert($table_name, array(
            'to_email' => sanitize_text_field($to),
            'subject' => sanitize_text_field($args['subject']),
            'body' => $args['message'],
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
            $wpdb->update(
                $wpdb->prefix . 'ofast_smtp_log',
                array('status' => 'success'),
                array('id' => $this->current_log_id)
            );
            $this->current_log_id = null;
        }
    }

    /**
     * Mark email as failed
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
            $this->current_log_id = null;
        }
    }

    /**
     * Ensure log table exists
     */
    private function ensure_log_table()
    {
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
    }
}
