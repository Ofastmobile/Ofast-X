<?php

/**
 * Ofast X Security Hardening Module
 * Advanced protection against sophisticated attacks
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Security_Hardening
{
    private static $instance = null;
    private $plugin_dir;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        if (defined('OFAST_X_PLUGIN_DIR')) {
            $this->plugin_dir = OFAST_X_PLUGIN_DIR;
        } else {
            $this->plugin_dir = dirname(dirname(__FILE__));
        }

        // Security headers
        add_action('send_headers', array($this, 'add_security_headers'));

        // File integrity check (daily)
        add_action('admin_init', array($this, 'schedule_integrity_check'));
        add_action('ofast_integrity_check', array($this, 'run_integrity_check'));
        add_action('admin_init', array($this, 'maybe_refresh_hashes_on_upgrade'), 20);

        // Emergency key rate limiting
        add_filter('authenticate', array($this, 'check_emergency_key_rate_limit'), 5, 3);

        // Admin notices for security alerts
        add_action('admin_notices', array($this, 'display_security_alerts'));

        // Generate file hashes on activation
        register_activation_hook(dirname($this->plugin_dir) . '/ofast-x.php', array($this, 'generate_file_hashes'));
    }

    /**
     * Add security headers to admin pages
     */
    public function add_security_headers()
    {
        if (!is_admin()) {
            return;
        }

        // Prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');

        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');

        // XSS Protection (legacy browsers)
        header('X-XSS-Protection: 1; mode=block');

        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Permissions policy
        header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
    }

    /**
     * Schedule daily integrity check
     */
    public function schedule_integrity_check()
    {
        if (!wp_next_scheduled('ofast_integrity_check')) {
            wp_schedule_event(time(), 'daily', 'ofast_integrity_check');
        }
    }

    /**
     * Generate file hashes for integrity checking
     */
    public function generate_file_hashes()
    {
        $hashes = array();
        $files = $this->get_plugin_files();

        foreach ($files as $file) {
            $relative_path = str_replace($this->plugin_dir . '/', '', $file);
            $hashes[$relative_path] = hash_file('sha256', $file);
        }

        update_option('ofast_file_hashes', $hashes);
        update_option('ofast_hash_generated', current_time('mysql'));
        update_option('ofast_hashes_root', $this->plugin_dir);
        if (defined('OFAST_X_VERSION')) {
            update_option('ofast_hashes_version', OFAST_X_VERSION);
        }
    }

    /**
     * Get all PHP files in plugin
     */
    private function get_plugin_files()
    {
        $files = array();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->plugin_dir)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Regenerate integrity hashes on plugin upgrades or root path changes.
     */
    public function maybe_refresh_hashes_on_upgrade()
    {
        if (!defined('OFAST_X_VERSION')) {
            return;
        }

        $stored_version = get_option('ofast_hashes_version', '');
        $stored_root = get_option('ofast_hashes_root', '');

        if ($stored_version !== OFAST_X_VERSION || $stored_root !== $this->plugin_dir) {
            $this->generate_file_hashes();
            delete_option('ofast_integrity_alert');

            // Keep core version in sync for upgrade tracking.
            update_option('ofastx_version', OFAST_X_VERSION);
        }
    }

    /**
     * Run integrity check
     */
    public function run_integrity_check()
    {
        $stored_hashes = get_option('ofast_file_hashes', array());
        if (empty($stored_hashes)) {
            $this->generate_file_hashes();
            return;
        }

        $modified = array();
        $new_files = array();
        $deleted_files = array();
        $current_files = array();

        $files = $this->get_plugin_files();

        foreach ($files as $file) {
            $relative_path = str_replace($this->plugin_dir . '/', '', $file);
            $current_hash = hash_file('sha256', $file);
            $current_files[$relative_path] = true;

            if (!isset($stored_hashes[$relative_path])) {
                $new_files[] = $relative_path;
            } elseif ($stored_hashes[$relative_path] !== $current_hash) {
                $modified[] = $relative_path;
            }
        }

        // Check for deleted files
        foreach ($stored_hashes as $path => $hash) {
            if (!isset($current_files[$path])) {
                $deleted_files[] = $path;
            }
        }

        // Store results
        if (!empty($modified) || !empty($new_files) || !empty($deleted_files)) {
            $alert = array(
                'time' => current_time('mysql'),
                'modified' => $modified,
                'new' => $new_files,
                'deleted' => $deleted_files
            );
            update_option('ofast_integrity_alert', $alert);

            // Send email alert
            $this->send_integrity_alert($alert);
        } else {
            delete_option('ofast_integrity_alert');
        }

        update_option('ofast_last_integrity_check', current_time('mysql'));
    }

    /**
     * Send integrity alert email
     */
    private function send_integrity_alert($alert)
    {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');

        $subject = "[SECURITY ALERT] {$site_name} - Plugin files modified!";

        $message = "=== OFAST X SECURITY ALERT ===\n\n";
        $message .= "Time: {$alert['time']}\n\n";

        if (!empty($alert['modified'])) {
            $message .= "MODIFIED FILES:\n";
            foreach ($alert['modified'] as $file) {
                $message .= "  - {$file}\n";
            }
            $message .= "\n";
        }

        if (!empty($alert['new'])) {
            $message .= "NEW FILES (possibly injected):\n";
            foreach ($alert['new'] as $file) {
                $message .= "  - {$file}\n";
            }
            $message .= "\n";
        }

        if (!empty($alert['deleted'])) {
            $message .= "DELETED FILES:\n";
            foreach ($alert['deleted'] as $file) {
                $message .= "  - {$file}\n";
            }
            $message .= "\n";
        }

        $message .= "RECOMMENDED ACTION:\n";
        $message .= "1. Log in to your site immediately\n";
        $message .= "2. Check the files listed above\n";
        $message .= "3. If you didn't make these changes, your site may be compromised\n";
        $message .= "4. Re-install the plugin from a trusted source\n";

        wp_mail($admin_email, $subject, $message);
    }

    /**
     * Send emergency-key security alerts without flooding the admin inbox.
     */
    private function send_emergency_key_alert($event_type, $data)
    {
        $cooldown_key = 'ofast_emergency_alert_' . sanitize_key($event_type);
        if ($event_type === 'rate_limit_exceeded' && get_transient($cooldown_key)) {
            return;
        }

        if ($event_type === 'rate_limit_exceeded') {
            set_transient($cooldown_key, true, HOUR_IN_SECONDS);
        }

        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        $site_url = home_url();

        if ($event_type === 'success') {
            $subject = "[SECURITY NOTICE] {$site_name} - Emergency Key Used";
            $message = "=== EMERGENCY KEY USED ===\n\n";
            $message .= "Site: {$site_name} ({$site_url})\n";
            $message .= "Time: " . current_time('mysql') . "\n";
            $message .= "IP Address: {$data['ip']}\n\n";
            $message .= "If this was expected, no action is needed.\n";
            $message .= "If unexpected, rotate the emergency key and review recent admin access.\n";
        } elseif ($event_type === 'rate_limit_exceeded') {
            $subject = "[SECURITY ALERT] {$site_name} - Emergency Key Rate Limit Hit";
            $message = "=== EMERGENCY KEY RATE LIMIT HIT ===\n\n";
            $message .= "Site: {$site_name} ({$site_url})\n";
            $message .= "Time: " . current_time('mysql') . "\n";
            $message .= "IP Address: {$data['ip']}\n";
            $message .= "Attempts in the last hour: {$data['attempts']}\n\n";
            $message .= "Recommended actions:\n";
            $message .= "- Review the source IP for suspicious activity\n";
            $message .= "- Rotate the emergency key if this was not expected\n";
            $message .= "- Check recent authentication and server logs\n";
        } else {
            return;
        }

        wp_mail($admin_email, $subject, $message);
    }

    /**
     * Display security alerts in admin
     */
    public function display_security_alerts()
    {
        $alert = get_option('ofast_integrity_alert');
        if (empty($alert)) {
            return;
        }

        $total = count($alert['modified']) + count($alert['new']) + count($alert['deleted']);
?>
        <div class="notice notice-error">
            <p><strong>⚠️ OFAST X SECURITY ALERT:</strong> <?php echo $total; ?> plugin file(s) have been modified!</p>
            <p>
                <?php if (!empty($alert['modified'])): ?>
                    Modified: <?php echo esc_html(implode(', ', array_slice($alert['modified'], 0, 3))); ?>
                    <?php if (count($alert['modified']) > 3): ?>
                        and <?php echo count($alert['modified']) - 3; ?> more
                    <?php endif; ?>
                <?php endif; ?>
            </p>
            <p>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=ofast-dashboard&action=regenerate_hashes'), 'ofast_regenerate_hashes')); ?>" class="button">
                    I made these changes - Update hashes
                </a>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=ofast-dashboard&action=view_integrity'), 'ofast_view_integrity')); ?>" class="button">
                    View Details
                </a>
            </p>
        </div>
<?php
    }

    /**
     * Rate limit emergency key attempts
     */
    public function check_emergency_key_rate_limit($user, $username, $password)
    {
        if (!isset($_GET['ofast_emergency'])) {
            return $user;
        }

        $ip = $this->get_client_ip();
        $key = 'ofast_emergency_attempts_' . md5($ip);
        $attempts = get_transient($key);

        if ($attempts === false) {
            $attempts = 0;
        }

        $lockout_key = 'ofast_emergency_lockout_' . md5($ip);
        $lockout_until = get_transient($lockout_key);
        if ($lockout_until && time() < $lockout_until) {
            $wait_time = human_time_diff(time(), $lockout_until);
            return new WP_Error(
                'ofast_emergency_lockout',
                '<strong>' . esc_html__('Security:', 'ofast-x') . '</strong> ' .
                sprintf(esc_html__('Please wait %s before trying the emergency key again.', 'ofast-x'), $wait_time)
            );
        }

        // Verify the key
        $stored_key = get_option('ofast_admin_emergency_key', '');
        $provided_key = sanitize_text_field($_GET['ofast_emergency']);

        // Timing-safe comparison
        if (!is_string($stored_key) || $stored_key === '' || !hash_equals($stored_key, $provided_key)) {
            $attempts++;
            set_transient($key, $attempts, HOUR_IN_SECONDS);

            $lockout_duration = 0;
            if ($attempts >= 2) {
                $lockout_duration = min(($attempts - 1) * 5 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS);
                set_transient($lockout_key, time() + $lockout_duration, $lockout_duration);
            }

            $this->log_security_event('emergency_key_failed', array(
                'ip' => $ip,
                'attempts' => $attempts,
                'lockout_duration' => $lockout_duration
            ));

            if ($attempts >= 3) {
                $this->log_security_event('emergency_key_blocked', array(
                    'ip' => $ip,
                    'attempts' => $attempts
                ));

                $this->send_emergency_key_alert('rate_limit_exceeded', array(
                    'ip' => $ip,
                    'attempts' => $attempts
                ));

                $lockout_minutes = min(($attempts - 1) * 5, 15);

                return new WP_Error(
                    'ofast_emergency_rate_limit',
                    '<strong>' . esc_html__('Security:', 'ofast-x') . '</strong> ' . sprintf(esc_html__('Too many emergency key attempts. Please wait %d minutes.', 'ofast-x'), $lockout_minutes)
                );
            }

            return $user;
        }

        delete_transient($key);
        delete_transient($lockout_key);

        $this->log_security_event('emergency_key_success', array('ip' => $ip));
        $this->send_emergency_key_alert('success', array('ip' => $ip));

        return $user;
    }

    /**
     * Encrypt sensitive option
     */
    public static function encrypt_option($value)
    {
        if (!is_string($value) || $value === '') {
            return false;
        }

        if (!defined('SECURE_AUTH_KEY') || empty(SECURE_AUTH_KEY)) {
            return false;
        }

        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            return false;
        }

        $key = hash('sha256', SECURE_AUTH_KEY);

        try {
            $iv = random_bytes(16);
        } catch (Exception $e) {
            return false;
        }

        $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv);
        if ($encrypted === false) {
            return false;
        }

        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt sensitive option
     */
    public static function decrypt_option($encrypted)
    {
        if (!is_string($encrypted) || $encrypted === '') {
            return false;
        }

        if (!defined('SECURE_AUTH_KEY') || empty(SECURE_AUTH_KEY)) {
            return false;
        }

        if (!function_exists('openssl_decrypt')) {
            return false;
        }

        $key = hash('sha256', SECURE_AUTH_KEY);
        $decoded = base64_decode($encrypted, true);

        if ($decoded === false) {
            return false;
        }

        if (strlen($decoded) >= 17) {
            $iv = substr($decoded, 0, 16);
            $encrypted_data = substr($decoded, 16);
            $decrypted = openssl_decrypt($encrypted_data, 'AES-256-CBC', $key, 0, $iv);

            if ($decrypted !== false) {
                return $decrypted;
            }
        }

        if (defined('AUTH_KEY') && !empty(AUTH_KEY)) {
            $legacy_iv = substr(hash('sha256', AUTH_KEY), 0, 16);
            $legacy = openssl_decrypt($decoded, 'AES-256-CBC', $key, 0, $legacy_iv);

            if ($legacy !== false) {
                return $legacy;
            }
        }

        return false;
    }

    /**
     * Check whether a value looks like this plugin's encrypted option payload.
     */
    public static function looks_like_encrypted_option($value)
    {
        if (!is_string($value) || $value === '') {
            return false;
        }

        $decoded = base64_decode($value, true);
        return $decoded !== false && strlen($decoded) > 16;
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

        return '0.0.0.0';
    }

    /**
     * Log security event
     */
    private function log_security_event($event_type, $data)
    {
        $log = get_option('ofast_security_log', array());

        $log[] = array(
            'time' => current_time('mysql'),
            'type' => $event_type,
            'data' => $data
        );

        // Keep last 100 events
        if (count($log) > 100) {
            $log = array_slice($log, -100);
        }

        update_option('ofast_security_log', $log);
    }

    /**
     * Handle admin actions
     */
    public static function handle_admin_actions()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['action']) && $_GET['action'] === 'regenerate_hashes') {
            check_admin_referer('ofast_regenerate_hashes');
            self::get_instance()->generate_file_hashes();
            delete_option('ofast_integrity_alert');
            wp_redirect(admin_url('admin.php?page=ofast-dashboard&hashes_updated=1'));
            exit;
        }
    }
}

// Initialize
add_action('plugins_loaded', function () {
    Ofast_X_Security_Hardening::get_instance();
});

// Handle admin actions
add_action('admin_init', array('Ofast_X_Security_Hardening', 'handle_admin_actions'));
