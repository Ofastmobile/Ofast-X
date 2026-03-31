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
    private const LOG_SCHEMA_TRANSIENT = 'ofast_smtp_log_schema_v2';
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

        // Hook into WordPress mail if enabled (mailer_type determines behavior inside configure_phpmailer)
        if ($this->is_enabled) {
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

        // Daily cleanup of SMTP logs (retention controlled by option)
        if (!wp_next_scheduled('ofast_smtp_cleanup_logs')) {
            wp_schedule_event(time(), 'daily', 'ofast_smtp_cleanup_logs');
        }
        add_action('ofast_smtp_cleanup_logs', array($this, 'cleanup_old_logs'));
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
            if ((defined('OFAST_SMTP_DEBUG') && OFAST_SMTP_DEBUG) || (defined('WP_DEBUG') && WP_DEBUG)) {
                error_log('Ofast SMTP: Rate limit exceeded (' . $this->rate_limit_per_minute . '/min). Email blocked.');
            }

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
     * Skips HTML template emails (trusted), applies to plain text only
     */
    public function sanitize_email_content($args)
    {
        $apply_sanitization = apply_filters('ofast_smtp_sanitize_outgoing_email', false, $args);
        if (!$apply_sanitization) {
            return $args;
        }

        if (!empty($args['message'])) {
            $message = $args['message'];

            // Skip sanitization for HTML emails (structured templates we control)
            // These are trusted and sanitization breaks their HTML structure
            if (stripos($message, '<!DOCTYPE') !== false || 
                stripos($message, '<html') !== false ||
                stripos($message, '<table') !== false) {
                return $args; // Trust HTML template emails
            }

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
        
        // SMTP mode - requires host, port, credentials
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
            // SECURITY: Use custom debug output to sanitize credentials even in debug mode
            $phpmailer->Debugoutput = function($str, $level) {
                $sanitized = $this->sanitize_error_message($str);
                error_log('SMTP Debug: ' . $sanitized);
            };
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
                // SECURITY: Sanitize error message to remove any credential leakage
                $sanitized_error = $this->sanitize_error_message($error);
                wp_send_json_error(array(
                    'message' => 'PHP Mail failed',
                    'error' => $sanitized_error,
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
            // SECURITY: Sanitize error message to remove any credential leakage
            $sanitized_error = $this->sanitize_error_message($mail->ErrorInfo);
            
            wp_send_json_error(array(
                'message' => 'SMTP connection failed',
                'error' => $sanitized_error,
                'suggestion' => $this->get_error_suggestion($sanitized_error)
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
     * Sanitize error messages to remove sensitive information
     * Addresses CWE-532: Insertion of Sensitive Information into Log File
     */
    private function sanitize_error_message($error_message)
    {
        if (empty($error_message) || !is_string($error_message)) {
            return 'Connection failed';
        }

        // Limit input length to prevent ReDoS attacks
        if (strlen($error_message) > 10000) {
            return 'SMTP error occurred. (Message truncated for security)';
        }

        $sanitized = $error_message;

        // PHASE 1: Remove known credential values (most reliable)
        $known_secrets = array_filter(array(
            get_option('ofast_smtp_password', ''),
            get_option('ofast_smtp_username', ''),
            get_option('ofast_smtp_host', '')
        ), function($value) {
            return !empty($value) && strlen($value) >= 3;
        });

        // Sort by length (longest first to avoid partial matches)
        usort($known_secrets, function($a, $b) {
            return strlen($b) - strlen($a);
        });

        foreach ($known_secrets as $secret) {
            $sanitized = str_replace($secret, '[HIDDEN]', $sanitized);
            // Also catch URL-encoded and Base64-encoded versions
            $sanitized = str_replace(urlencode($secret), '[HIDDEN]', $sanitized);
            $sanitized = str_replace(base64_encode($secret), '[HIDDEN]', $sanitized);
        }

        // PHASE 2: Pattern-based sanitization (defense in depth)
        $patterns = array(
            // Password patterns
            '/(\b(?:pass(?:word)?|pwd|passwd)\s*[=:]\s*)[^\s&,;"\'\]}>]+/i',
            
            // Username patterns
            '/(\b(?:user(?:name)?|login|acct|account)\s*[=:]\s*)[^\s&,;"\'\]}>]+/i',
            
            // API key patterns
            '/(\b(?:api[_-]?key|apikey|secret[_-]?key|access[_-]?key|auth[_-]?key)\s*[=:]\s*)[^\s&,;"\'\]}>]+/i',
            
            // Token patterns
            '/(\b(?:token|bearer|auth(?:orization)?|credential)\s*[=:]\s*)[^\s&,;"\'\]}>]+/i',
            
            // URL-embedded credentials
            '/((?:smtp|smtps|ssl|tls|http|https):\/\/)[^:]+:[^@]+(@)/i',
            
            // AUTH PLAIN/LOGIN Base64
            '/(AUTH\s+(?:PLAIN|LOGIN)\s+)[A-Za-z0-9+\/=]{8,}/i',
            
            // SendGrid API keys
            '/\bSG\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/i',
            
            // JWT tokens
            '/\beyJ[A-Za-z0-9_-]*\.eyJ[A-Za-z0-9_-]*\.[A-Za-z0-9_-]*/i',
            
            // Long Base64-looking strings
            '/(?<=\s|^)(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)(?=\s|$)/',
            
            // Long hex strings (potential keys)
            '/\b[a-f0-9]{32,}\b/i'
        );

        foreach ($patterns as $pattern) {
            $sanitized = preg_replace($pattern, '[HIDDEN]', $sanitized);
        }

        // Final safety check - if too many redactions, use generic message
        if (substr_count($sanitized, '[HIDDEN]') > 3) {
            return 'SMTP connection failed. Check your configuration settings. (Error details redacted for security)';
        }

        return $sanitized;
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
     * SECURITY FIX: Require proper encryption keys, fail securely if unavailable
     */
    public static function encrypt_password($password)
    {
        if (empty($password)) {
            return '';
        }

        // SECURITY: Require proper WordPress security keys to be configured
        if (!self::validate_encryption_keys()) {
            $key_diagnostics = self::get_key_validation_details();
            throw new Exception('SMTP credentials cannot be stored: ' . $key_diagnostics['message']);
        }

        $key = hash('sha256', SECURE_AUTH_KEY);
        
        // Generate a random IV for each encryption operation
        $iv = openssl_random_pseudo_bytes(16);
        if ($iv === false) {
            // SECURITY: Fail securely if cryptographically secure random source is unavailable
            throw new Exception('SMTP credentials cannot be stored: Secure random number generation is unavailable on this server.');
        }
        
        $encrypted = openssl_encrypt($password, 'AES-256-CBC', $key, 0, $iv);
        if ($encrypted === false) {
            throw new Exception('SMTP credentials cannot be stored: Encryption failed.');
        }

        // Store IV with ciphertext: IV (16 bytes) + encrypted data
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt password from storage
     * SECURITY FIX: Enhanced validation and secure fallback handling
     */
    private function decrypt_password($encrypted)
    {
        if (empty($encrypted)) {
            return '';
        }

        // SECURITY: Check if encryption keys are available
        if (!self::validate_encryption_keys()) {
            error_log('OFAST SMTP Security Warning: Cannot decrypt credentials - WordPress security keys not properly configured');
            return '';
        }

        $key = hash('sha256', SECURE_AUTH_KEY);
        $decoded = base64_decode($encrypted);
        
        if ($decoded === false) {
            error_log('OFAST SMTP Security Warning: Invalid base64 encoding in stored credentials');
            return '';
        }

        // SECURITY: Check for legacy insecure storage (base64 only)
        // This identifies credentials that were stored without proper encryption
        if (self::is_legacy_insecure_storage($encrypted)) {
            error_log('OFAST SMTP Security Warning: Legacy insecure credential storage detected. Please re-enter your SMTP password to upgrade to secure storage.');
            // Return empty to force re-entry of credentials
            return '';
        }

        // Check if this is old format (without random IV)
        if (strlen($decoded) < 16) {
            // SECURITY: Only allow fallback for properly encrypted old format
            if (defined('AUTH_KEY') && !empty(AUTH_KEY)) {
                $iv = substr(hash('sha256', AUTH_KEY), 0, 16);
                $decrypted = openssl_decrypt($decoded, 'AES-256-CBC', $key, 0, $iv);
                if ($decrypted !== false) {
                    return $decrypted;
                }
            }
            error_log('OFAST SMTP Security Warning: Cannot decrypt old format credentials');
            return '';
        }

        // Extract IV (first 16 bytes) and ciphertext (remaining bytes)
        $iv = substr($decoded, 0, 16);
        $ciphertext = substr($decoded, 16);

        $decrypted = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, 0, $iv);
        if ($decrypted === false) {
            error_log('OFAST SMTP Security Warning: Failed to decrypt credentials');
            return '';
        }

        return $decrypted;
    }

    /**
     * Validate that WordPress security keys are properly configured
     * SECURITY FIX: Ensure proper encryption keys are available before storing credentials
     */
    public static function validate_encryption_keys()
    {
        // Check that required WordPress security keys are defined and not empty/default
        $required_keys = array('SECURE_AUTH_KEY', 'AUTH_KEY');
        
        foreach ($required_keys as $key_name) {
            if (!defined($key_name)) {
                return false;
            }
            
            $key_value = constant($key_name);
            
            // Check if key is empty or contains default/placeholder values
            if (empty($key_value) || 
                strlen($key_value) < 32 || 
                $key_value === 'put your unique phrase here' ||
                strpos($key_value, 'your unique phrase') !== false) {
                return false;
            }
        }
        
        // Additional check: ensure OpenSSL is available for encryption
        if (!extension_loaded('openssl')) {
            return false;
        }
        
        return true;
    }

    /**
     * Get detailed validation information for SMTP credential encryption keys.
     */
    public static function get_key_validation_details()
    {
        $required_keys = array('SECURE_AUTH_KEY', 'AUTH_KEY');
        $issues = array();
        $recommendations = array();

        if (!extension_loaded('openssl')) {
            return array(
                'valid' => false,
                'message' => 'OpenSSL is not available on this server, so SMTP credentials cannot be encrypted securely.',
                'suggestion' => 'Ask your hosting provider to enable the OpenSSL PHP extension before saving SMTP passwords.'
            );
        }

        foreach ($required_keys as $key_name) {
            if (!defined($key_name)) {
                $issues[] = $key_name . ' is not defined in wp-config.php';
                continue;
            }

            $key_value = constant($key_name);

            if (empty($key_value)) {
                $issues[] = $key_name . ' is empty';
                continue;
            }

            if ($key_value === 'put your unique phrase here' || strpos($key_value, 'your unique phrase') !== false) {
                $issues[] = $key_name . ' is still using the default placeholder value';
                continue;
            }

            if (strlen($key_value) < 32) {
                $issues[] = $key_name . ' is too short for secure SMTP credential storage';
                continue;
            }

            if (strlen($key_value) < 64) {
                $recommendations[] = $key_name . ' works, but a 64+ character random salt is recommended';
            }

            if (self::has_weak_key_patterns($key_value)) {
                $recommendations[] = $key_name . ' looks predictable; consider refreshing your WordPress salts';
            }
        }

        if (!empty($issues)) {
            return array(
                'valid' => false,
                'message' => 'WordPress security keys have issues: ' . implode(', ', $issues) . '.',
                'suggestion' => 'Generate fresh salts from https://api.wordpress.org/secret-key/1.1/salt/, update wp-config.php, then re-save your SMTP password.'
            );
        }

        $message = 'WordPress security keys are configured for SMTP credential encryption.';
        if (!empty($recommendations)) {
            $message .= ' ' . implode('. ', $recommendations) . '.';
        }

        return array(
            'valid' => true,
            'message' => $message,
            'suggestion' => empty($recommendations) ? '' : 'For stronger protection, replace your salts in wp-config.php with a fresh set from https://api.wordpress.org/secret-key/1.1/salt/.'
        );
    }

    /**
     * Detect obviously weak or predictable key patterns.
     */
    private static function has_weak_key_patterns($key)
    {
        $key_length = strlen($key);
        if ($key_length < 8) {
            return true;
        }

        $char_counts = array_count_values(str_split($key));
        foreach ($char_counts as $count) {
            if ($count > ($key_length * 0.25)) {
                return true;
            }
        }

        $weak_patterns = array(
            '/(.)\1{4,}/',
            '/password/i',
            '/123456/',
            '/qwerty/i',
            '/admin/i'
        );

        foreach ($weak_patterns as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }

        $sequences = array(
            'abcdefghijklmnopqrstuvwxyz',
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            '0123456789'
        );

        foreach ($sequences as $sequence) {
            for ($i = 0; $i <= strlen($sequence) - 6; $i++) {
                $chunk = substr($sequence, $i, 6);
                if (strpos($key, $chunk) !== false || strpos($key, strrev($chunk)) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Detect legacy insecure storage (base64 only, no proper encryption)
     * SECURITY FIX: Identify credentials stored with weak base64 encoding
     */
    private static function is_legacy_insecure_storage($encrypted)
    {
        if (empty($encrypted)) {
            return false;
        }
        
        $decoded = base64_decode($encrypted, true);
        if ($decoded === false) {
            return false;
        }
        
        // If the decoded content looks like readable text/password, it's likely insecure base64 storage
        // This is a heuristic - properly encrypted data should look random
        
        // Check if decoded data is printable ASCII (common for passwords stored as base64)
        if (ctype_print($decoded) && strlen($decoded) < 100) {
            // Additional checks to confirm this is likely a password:
            // - Contains common password characters
            // - Length is typical for passwords (6-64 chars)
            // - Does not contain binary data patterns
            $length = strlen($decoded);
            if ($length >= 6 && $length <= 64) {
                // Check for password-like patterns vs encrypted binary data
                $printable_ratio = strlen(preg_replace('/[^\x20-\x7E]/', '', $decoded)) / $length;
                if ($printable_ratio > 0.8) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Audit existing stored credentials for security issues
     * SECURITY FIX: Provide method to identify and report insecure credential storage
     */
    public static function audit_stored_credentials()
    {
        $stored_password = get_option('ofast_smtp_password', '');
        if (empty($stored_password)) {
            return array(
                'status' => 'no_credentials',
                'message' => 'No SMTP credentials stored.'
            );
        }
        
        // Check if encryption keys are properly configured
        $key_validation = self::get_key_validation_details();
        if (!$key_validation['valid']) {
            return array(
                'status' => 'keys_invalid',
                'message' => $key_validation['message'],
                'action_required' => $key_validation['suggestion']
            );
        }
        
        // Check for insecure legacy storage
        if (self::is_legacy_insecure_storage($stored_password)) {
            return array(
                'status' => 'insecure_storage',
                'message' => 'SMTP credentials are stored using weak base64 encoding instead of proper encryption.',
                'action_required' => 'Re-enter your SMTP password to upgrade to secure storage'
            );
        }
        
        return array(
            'status' => 'secure',
            'message' => 'SMTP credentials are properly encrypted and secure.'
        );
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
     * SECURITY FIX (CWE-532): Implements content filtering for sensitive data
     */
    public function log_outgoing_email($args)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ofast_smtp_log';

        $to_value = isset($args['to']) ? $args['to'] : '';
        $subject = isset($args['subject']) ? $args['subject'] : '';
        $message = isset($args['message']) ? $args['message'] : '';
        $headers = isset($args['headers']) ? $args['headers'] : array();

        // Create table if not exists
        $this->ensure_log_table();

        // Get recipient(s) as string
        $to = is_array($to_value) ? implode(', ', $to_value) : $to_value;

        // Get logging level setting (default: metadata only for security)
        $body_logging_enabled = get_option('ofast_smtp_log_body_content', false);
        
        // Filter sensitive content from message body before storage
        $filtered_body = null;
        if ($body_logging_enabled) {
            $filtered_body = $this->filter_sensitive_content($message);
        }

        // Filter headers to remove sensitive information
        $filtered_headers = null;
        if (!empty($headers)) {
            $headers_array = is_array($headers) ? $headers : array($headers);
            $filtered_headers = maybe_serialize($this->filter_sensitive_headers($headers_array));
        }

        // Insert log entry with filtered content
        $inserted = $wpdb->insert($table_name, array(
            'to_email' => sanitize_text_field($to),
            'subject' => sanitize_text_field($subject),
            'body' => $filtered_body,
            'headers' => $filtered_headers,
            'status' => 'pending',
            'sent_at' => current_time('mysql')
        ));

        if ($inserted === false) {
            if ((defined('OFAST_SMTP_DEBUG') && OFAST_SMTP_DEBUG) || (defined('WP_DEBUG') && WP_DEBUG)) {
                error_log('Ofast SMTP: Failed to insert SMTP log row - ' . $wpdb->last_error);
            }
            $this->current_log_id = null;
            return $args;
        }

        // Store the log ID for later status update
        $this->current_log_id = (int) $wpdb->insert_id;

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

            // SECURITY: Sanitize error message before storing to prevent credential leakage
            $sanitized_error = $this->sanitize_error_message($error_message);

            $wpdb->update(
                $wpdb->prefix . 'ofast_smtp_log',
                array(
                    'status' => 'failed',
                    'error_message' => sanitize_text_field($sanitized_error)
                ),
                array('id' => $this->current_log_id)
            );
            $this->current_log_id = null;
        }
    }

    /**
     * Filter sensitive content from email body
     * SECURITY: Prevents storage of passwords, tokens, and personal data
     */
    private function filter_sensitive_content($content)
    {
        if (empty($content)) {
            return $content;
        }

        // Define sensitive patterns to filter
        $sensitive_patterns = apply_filters('ofast_smtp_sensitive_content_patterns', array(
            // Passwords in various formats
            '/(password[=:\s]+)[^\s&<>"\']{3,}/i' => '$1[REDACTED]',
            '/"password"\s*:\s*"[^"]+"/i' => '"password":"[REDACTED]"',
            '/(\bpwd[=:\s]+)[^\s&<>"\']{3,}/i' => '$1[REDACTED]',
            
            // Tokens and API keys
            '/(token[=:\s]+)[A-Za-z0-9\-_\.]{16,}/i' => '$1[REDACTED]',
            '/(api[_-]?key[=:\s]+)[A-Za-z0-9\-_\.]{16,}/i' => '$1[REDACTED]',
            '/(bearer\s+)[A-Za-z0-9\-_\.]{20,}/i' => '$1[REDACTED]',
            
            // JWT tokens
            '/eyJ[A-Za-z0-9\-_]{10,}\.[A-Za-z0-9\-_]{10,}\.[A-Za-z0-9\-_]{10,}/' => '[JWT_TOKEN_REDACTED]',
            
            // Reset/verification tokens in URLs
            '/([?&]token=)[A-Za-z0-9\-_\.]{16,}/' => '$1[REDACTED]',
            '/([?&]reset[_-]?token=)[A-Za-z0-9\-_\.]{16,}/' => '$1[REDACTED]',
            '/([?&]verify[_-]?token=)[A-Za-z0-9\-_\.]{16,}/' => '$1[REDACTED]',
            
            // Credit card numbers (basic pattern)
            '/\b(?:4[0-9]{12}(?:[0-9]{3})?|5[1-5][0-9]{14}|3[47][0-9]{13}|6(?:011|5[0-9]{2})[0-9]{12})\b/' => '[CARD_NUMBER_REDACTED]',
            
            // Social Security Numbers (US format)
            '/\b[0-9]{3}-[0-9]{2}-[0-9]{4}\b/' => '[SSN_REDACTED]',
            
            // WordPress admin URLs that might contain sensitive info
            '/https?:\/\/[^\/\s]+\/wp-admin\/[^\s<>"\']*/' => '[ADMIN_URL_REDACTED]',
            
            // Private keys
            '/-----BEGIN\s+(?:RSA\s+)?PRIVATE\s+KEY-----[\s\S]*?-----END\s+(?:RSA\s+)?PRIVATE\s+KEY-----/' => '[PRIVATE_KEY_REDACTED]',
            
            // Email addresses in sensitive contexts (except standard to/from)
            '/(?:email[=:\s]+|user[_-]?email[=:\s]+)[^\s<>"\'@]+@[^\s<>"\']+/' => '[EMAIL_REDACTED]',
        ));

        $filtered_content = $content;
        
        foreach ($sensitive_patterns as $pattern => $replacement) {
            $updated_content = preg_replace($pattern, $replacement, $filtered_content);

            if (null !== $updated_content) {
                $filtered_content = $updated_content;
            }
        }

        return $filtered_content;
    }

    /**
     * Filter sensitive headers
     */
    private function filter_sensitive_headers($headers)
    {
        $sensitive_header_names = apply_filters('ofast_smtp_sensitive_header_names', array(
            'Authorization',
            'X-Api-Key',
            'X-Auth-Token',
            'Cookie',
            'Set-Cookie',
        ));

        $filtered = array();
        
        foreach ($headers as $key => $value) {
            if (is_numeric($key)) {
                // Handle indexed array headers like "Authorization: Bearer token"
                foreach ($sensitive_header_names as $sensitive_name) {
                    if (stripos($value, $sensitive_name . ':') === 0) {
                        $filtered[$key] = $sensitive_name . ': [REDACTED]';
                        continue 2;
                    }
                }
                $filtered[$key] = $value;
            } else {
                // Handle associative array headers
                if (in_array($key, $sensitive_header_names, true)) {
                    $filtered[$key] = '[REDACTED]';
                } else {
                    $filtered[$key] = $value;
                }
            }
        }

        return $filtered;
    }

    /**
     * Ensure the SMTP log table exists and upgrade older installs to the current schema.
     */
    public static function ensure_log_table_schema($force = false)
    {
        if (!$force && get_transient(self::LOG_SCHEMA_TRANSIENT)) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ofast_smtp_log';
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta(self::get_log_table_schema_sql($table_name, $charset));

        $columns = $wpdb->get_results("SHOW COLUMNS FROM `{$table_name}`", OBJECT_K);
        if (!is_array($columns)) {
            return;
        }

        if (!isset($columns['to_email'])) {
            $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `to_email` varchar(255) NOT NULL DEFAULT '' AFTER `id`");
        }

        if (!isset($columns['body'])) {
            $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `body` longtext NULL AFTER `subject`");
        }

        if (!isset($columns['headers'])) {
            $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `headers` longtext NULL AFTER `body`");
        }

        if (!isset($columns['error_message'])) {
            $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `error_message` text NULL AFTER `status`");
        }

        if (!isset($columns['sent_at'])) {
            $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `sent_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `error_message`");
        }

        if (isset($columns['status']) && stripos((string) $columns['status']->Type, 'varchar') === false) {
            $wpdb->query("ALTER TABLE `{$table_name}` MODIFY `status` varchar(20) NOT NULL DEFAULT 'pending'");
        }

        $columns = $wpdb->get_results("SHOW COLUMNS FROM `{$table_name}`", OBJECT_K);

        if (isset($columns['recipient']) && isset($columns['to_email'])) {
            $wpdb->query("UPDATE `{$table_name}` SET `to_email` = `recipient` WHERE (`to_email` = '' OR `to_email` IS NULL) AND `recipient` <> ''");
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE `{$table_name}` SET `status` = %s WHERE `status` = %s",
            'success',
            'sent'
        ));

        delete_transient('ofast_smtp_log_table_exists');
        set_transient(self::LOG_SCHEMA_TRANSIENT, true, DAY_IN_SECONDS);
    }

    private static function get_log_table_schema_sql($table_name, $charset)
    {
        return "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            to_email varchar(255) NOT NULL DEFAULT '',
            subject varchar(255) NOT NULL DEFAULT '',
            body longtext NULL,
            headers longtext NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            error_message text NULL,
            sent_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY sent_at (sent_at)
        ) {$charset};";
    }

    private function ensure_log_table()
    {
        self::ensure_log_table_schema();
    }

    /**
     * Cleanup old SMTP logs to prevent database bloat.
     */
    public function cleanup_old_logs()
    {
        $retention_days = (int) get_option('ofast_smtp_log_retention_days', 90);
        if ($retention_days <= 0) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_smtp_log';
        $this->ensure_log_table();

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE sent_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retention_days
        ));
    }
}
