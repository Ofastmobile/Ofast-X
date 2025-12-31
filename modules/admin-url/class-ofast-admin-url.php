<?php

/**
 * Ofast X - Admin URL Customizer Module
 * Hide /wp-admin and /wp-login.php behind a custom secret URL
 * Includes emergency bypass and email notifications
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Admin_Url
{
    private $custom_slug = '';
    private $emergency_key = '';

    /**
     * Initialize module
     */
    public function init()
    {
        // NOTE: Module enabled check removed - core loader already verified this
        // before calling init(). See class-ofast-core.php is_module_enabled()

        // Get settings
        $this->custom_slug = get_option('ofast_admin_custom_slug', '');
        $this->emergency_key = get_option('ofast_admin_emergency_key', '');

        // Admin settings page
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_save'));

        // Only proceed with protection if custom slug is set
        if (empty($this->custom_slug)) {
            return;
        }

        // Check for bypass constant in wp-config.php
        if (defined('OFAST_DISABLE_ADMIN_PROTECTION') && OFAST_DISABLE_ADMIN_PROTECTION === true) {
            return;
        }

        // Customize logout redirect - go to home page instead of login page (which would 404)
        add_filter('logout_redirect', array($this, 'custom_logout_redirect'), 999, 3);

        // Fallback: catch wp-login.php?loggedout=true and redirect to home
        add_action('login_init', array($this, 'handle_logout_redirect'), 1);

        // Register security hooks (login attempt tracking, lockout)
        $this->register_security_hooks();

        // Check for emergency key in URL (timing-safe comparison)
        if (isset($_GET['ofast_emergency']) && !empty($this->emergency_key)) {
            $provided_key = sanitize_text_field($_GET['ofast_emergency']);
            if (hash_equals($this->emergency_key, $provided_key)) {
                // ONE-TIME USE: Rotate the key immediately after use
                $new_key = $this->rotate_emergency_key();

                // Set bypass cookie with OLD key hash (for this session only)
                $session_token = wp_generate_password(32, false);
                setcookie('ofast_admin_bypass', $session_token, time() + 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);

                // Store session token temporarily
                set_transient('ofast_bypass_session_' . md5($session_token), true, HOUR_IN_SECONDS);

                // Send email with new key
                $this->send_new_emergency_key_email($new_key);

                return;
            }
        }

        // Check for bypass cookie (session-based, not key-based)
        if (isset($_COOKIE['ofast_admin_bypass'])) {
            $session_token = $_COOKIE['ofast_admin_bypass'];
            if (get_transient('ofast_bypass_session_' . md5($session_token))) {
                return;
            }
        }

        // Register custom URL handler
        add_action('init', array($this, 'handle_custom_url'), 1);

        // Block default login/admin pages
        add_action('init', array($this, 'block_default_access'), 1);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'Admin URL Security',
            'Admin URL',
            'manage_options',
            'ofast-admin-url',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Handle settings save
     */
    public function handle_save()
    {
        // Handle delete custom URL
        if (isset($_POST['ofast_delete_custom_url'])) {
            check_admin_referer('ofast_admin_url_save', '_wpnonce');
            if (current_user_can('manage_options')) {
                delete_option('ofast_admin_custom_slug');
                delete_option('ofast_admin_emergency_key');
                add_settings_error('ofast_admin_url', 'deleted', 'Custom URL protection has been disabled. Default login URLs are now active.', 'success');
            }
            return;
        }

        // Handle resend email
        if (isset($_POST['resend_email'])) {
            check_admin_referer('ofast_admin_url_save', '_wpnonce');
            if (current_user_can('manage_options')) {
                $custom_slug = get_option('ofast_admin_custom_slug', '');
                $emergency_key = get_option('ofast_admin_emergency_key', '');
                if (!empty($custom_slug) && !empty($emergency_key)) {
                    $this->send_admin_notification($custom_slug, $emergency_key);
                    add_settings_error('ofast_admin_url', 'resent', 'Login details have been sent to ' . get_option('admin_email') . '!', 'success');
                } else {
                    add_settings_error('ofast_admin_url', 'no_url', 'No custom URL is configured. Set one first.', 'error');
                }
            }
            return;
        }

        if (!isset($_POST['ofast_save_admin_url'])) {
            return;
        }

        check_admin_referer('ofast_admin_url_save', '_wpnonce');

        if (!current_user_can('manage_options')) {
            return;
        }

        $old_slug = get_option('ofast_admin_custom_slug', '');
        $new_slug = sanitize_title($_POST['custom_slug']);

        // Validate slug
        $reserved = array('wp-admin', 'wp-login', 'wp-login.php', 'admin', 'login', 'dashboard', 'wp-content', 'wp-includes');
        if (in_array($new_slug, $reserved)) {
            add_settings_error('ofast_admin_url', 'reserved', 'That URL slug is reserved. Please choose another.', 'error');
            return;
        }

        // Generate or keep emergency key
        $emergency_key = get_option('ofast_admin_emergency_key');
        if (empty($emergency_key)) {
            $emergency_key = wp_generate_password(32, false);
            update_option('ofast_admin_emergency_key', $emergency_key);
        }

        // Check if slug changed and send email
        if (!empty($new_slug) && $new_slug !== $old_slug) {
            // Save new slug
            update_option('ofast_admin_custom_slug', $new_slug);

            // Send notification email to admin
            $this->send_admin_notification($new_slug, $emergency_key);

            add_settings_error('ofast_admin_url', 'saved', 'Admin URL updated! Check your email for login details.', 'success');
        } elseif (empty($new_slug)) {
            // Disable protection
            delete_option('ofast_admin_custom_slug');
            add_settings_error('ofast_admin_url', 'disabled', 'Admin URL protection disabled.', 'info');
        } else {
            add_settings_error('ofast_admin_url', 'saved', 'Settings saved.', 'success');
        }

        // Save enabled state
        update_option('ofast_admin_url_enabled', isset($_POST['protection_enabled']) ? 1 : 0);

        // Save security settings
        $max_attempts = isset($_POST['max_attempts']) ? max(1, min(20, intval($_POST['max_attempts']))) : 5;
        $lockout_duration = isset($_POST['lockout_duration']) ? max(1, min(1440, intval($_POST['lockout_duration']))) : 15;
        $ip_whitelist = isset($_POST['ip_whitelist']) ? sanitize_textarea_field($_POST['ip_whitelist']) : '';

        update_option('ofast_security_max_attempts', $max_attempts);
        update_option('ofast_security_lockout_duration', $lockout_duration);
        update_option('ofast_security_ip_whitelist', $ip_whitelist);
    }

    /**
     * Send notification email to admin
     */
    private function send_admin_notification($custom_slug, $emergency_key)
    {
        $admin_email = get_option('admin_email');
        $site_url = home_url();
        $site_name = get_bloginfo('name');

        $custom_url = trailingslashit($site_url) . $custom_slug;
        $emergency_url = wp_login_url() . '?ofast_emergency=' . $emergency_key;

        $subject = "[{$site_name}] Admin URL Changed - Save This Email!";

        $message = "
=================================================
 ADMIN URL SECURITY UPDATE
=================================================

Your WordPress admin URL has been changed for security purposes.

 IMPORTANT: Save this email! You need these URLs to log in.

--------------------------------------------------
 YOUR NEW LOGIN URL:
--------------------------------------------------
{$custom_url}

Use this URL to access your WordPress dashboard.
The default /wp-admin and /wp-login.php are now hidden.

--------------------------------------------------
 EMERGENCY BACKUP LOGIN:
--------------------------------------------------
{$emergency_url}

 Use this ONLY if you forget your custom URL or get locked out.
This emergency link bypasses protection for 1 hour.

--------------------------------------------------
 PERMANENT BYPASS (Developer Option):
--------------------------------------------------
If you ever get locked out completely, add this line to your wp-config.php file:

define('OFAST_DISABLE_ADMIN_PROTECTION', true);

This will disable the protection until you remove it.

--------------------------------------------------
 SUMMARY:
--------------------------------------------------
• Custom Login URL: {$custom_url}
• Emergency URL: {$emergency_url}
• Site: {$site_url}
• Date Changed: " . current_time('F j, Y \a\t g:i a') . "

Keep this email safe!

-- 
Ofast X Security Module
{$site_url}
";

        $headers = array('Content-Type: text/plain; charset=UTF-8');

        wp_mail($admin_email, $subject, $message, $headers);
    }

    /**
     * Rotate emergency key (one-time use security)
     */
    private function rotate_emergency_key()
    {
        $new_key = wp_generate_password(32, false);
        update_option('ofast_admin_emergency_key', $new_key);
        $this->emergency_key = $new_key;

        // Log the rotation
        $this->log_security_event('emergency_key_rotated', array(
            'time' => current_time('mysql'),
            'ip' => $this->get_client_ip()
        ));

        return $new_key;
    }

    /**
     * Send email with new emergency key after rotation
     */
    private function send_new_emergency_key_email($new_key)
    {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        $custom_url = trailingslashit($site_url) . $this->custom_slug;
        $new_emergency_url = wp_login_url() . '?ofast_emergency=' . $new_key;

        $subject = "[{$site_name}] Your Emergency Key Has Been Rotated (One-Time Use)";

        $message = "
=================================================
 EMERGENCY KEY ROTATED - NEW KEY GENERATED
=================================================

Your emergency bypass URL was just used. For security, a NEW 
key has been generated. The old key no longer works.

--------------------------------------------------
 YOUR NEW EMERGENCY BYPASS URL:
--------------------------------------------------
{$new_emergency_url}

 IMPORTANT: This is a ONE-TIME USE key. After you use it,
a new key will be generated and emailed to you.

--------------------------------------------------
 YOUR CUSTOM LOGIN URL (unchanged):
--------------------------------------------------
{$custom_url}

--------------------------------------------------
 SECURITY INFO:
--------------------------------------------------
• Time: " . current_time('mysql') . "
• This email confirms the old key was used successfully
• Save this email - the new key is only sent once

--------------------------------------------------
";

        $headers = array('Content-Type: text/plain; charset=UTF-8');
        wp_mail($admin_email, $subject, $message, $headers);
    }

    /**
     * Handle custom URL access
     */
    public function handle_custom_url()
    {
        // Get the request path without query string
        $request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $request_uri = rtrim($request_uri, '/');
        $custom_slug = '/' . trim($this->custom_slug, '/');

        // Check if accessing custom login URL
        if ($request_uri === $custom_slug || str_ends_with($request_uri, $custom_slug)) {
            // Set a cookie to allow admin access
            setcookie('ofast_custom_login', '1', time() + 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);

            // Redirect to wp-login.php
            wp_redirect(wp_login_url());
            exit;
        }
    }

    /**
     * Block default wp-admin and wp-login.php access
     */
    public function block_default_access()
    {
        $request_uri = $_SERVER['REQUEST_URI'];

        // Allow if custom login cookie is set
        if (isset($_COOKIE['ofast_custom_login'])) {
            return;
        }

        // Allow AJAX requests
        if (strpos($request_uri, 'admin-ajax.php') !== false) {
            return;
        }

        // Allow admin-post.php for form handlers
        if (strpos($request_uri, 'admin-post.php') !== false) {
            return;
        }

        // Allow Cron
        if (strpos($request_uri, 'wp-cron.php') !== false) {
            return;
        }

        // Allow logout action (must be able to logout!)
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            return;
        }

        // If user is already logged in, allow all admin/login access
        // This allows admins to preview login page from Login Redesign settings
        if (is_user_logged_in()) {
            return;
        }

        // Block direct access to wp-login.php (for non-logged-in users)
        if (strpos($request_uri, 'wp-login.php') !== false) {
            $this->show_404();
        }

        // Block direct /wp-admin access for non-logged in users  
        if (strpos($request_uri, '/wp-admin') !== false) {
            // Don't block admin assets (images, css, js)
            if (preg_match('/\.(css|js|png|jpg|gif|ico|svg|woff|woff2|ttf|eot)$/i', $request_uri)) {
                return;
            }
            $this->show_404();
        }
    }

    /**
     * Show 404 page
     */
    private function show_404()
    {
        status_header(404);
        nocache_headers();

        // Try to load theme's 404 template
        $template = get_404_template();
        if ($template) {
            include($template);
        } else {
            wp_die('Page not found', '404 Not Found', array('response' => 404));
        }
        exit;
    }

    /**
     * Custom logout redirect - send to home page instead of login (which 404s)
     */
    public function custom_logout_redirect($redirect_to, $requested_redirect_to, $user)
    {
        // Always redirect to home page after logout when admin URL protection is active
        return home_url('/');
    }

    /**
     * Fallback logout handler - ensures redirect to homepage
     */
    public function handle_logout_redirect()
    {
        // If we're on the login page with loggedout parameter, redirect to home
        if (isset($_GET['loggedout']) && $_GET['loggedout'] === 'true') {
            wp_safe_redirect(home_url('/'));
            exit;
        }
    }

    /**
     * Render settings page
     */
    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $custom_slug = get_option('ofast_admin_custom_slug', '');
        $emergency_key = get_option('ofast_admin_emergency_key', '');
        $site_url = home_url();

        settings_errors('ofast_admin_url');
?>
        <div class="wrap">
            <h1>Admin URL Security</h1>
            <p class="description">Hide your WordPress login page behind a secret custom URL.</p>

            <!-- Warning Box -->
            <div style="background: #fff; border: 1px solid #dc3545; border-radius: 8px; padding: 15px; margin: 20px 0;">
                <h3 style="margin-top: 0; color: #dc3545;">Important Warning</h3>
                <ul style="color: #dc3545; margin-bottom: 0;">
                    <li>When you enable this, the default <code>/wp-admin</code> and <code>/wp-login.php</code> URLs will return 404 errors</li>
                    <li>You MUST remember your custom URL to log in</li>
                    <li>An email with your new URL and emergency backup will be sent to the admin email</li>
                    <li>If you get locked out, add <code>define('OFAST_DISABLE_ADMIN_PROTECTION', true);</code> to wp-config.php</li>
                </ul>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('ofast_admin_url_save', '_wpnonce'); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="custom_slug">Custom Login URL Slug</label></th>
                        <td>
                            <div style="display: flex; align-items: center; gap: 5px;">
                                <span style="color: #666;"><?php echo esc_html($site_url); ?>/</span>
                                <input type="text" name="custom_slug" id="custom_slug"
                                    value="<?php echo esc_attr($custom_slug); ?>"
                                    class="regular-text"
                                    placeholder="my-secret-login"
                                    pattern="[a-z0-9\-]+"
                                    style="width: 200px;">
                            </div>
                            <p class="description">
                                Only lowercase letters, numbers, and hyphens allowed.<br>
                                Leave empty to disable protection and use default URLs.
                            </p>
                        </td>
                    </tr>
                    <?php if (!empty($custom_slug) && !empty($emergency_key)): ?>
                        <tr>
                            <th>Current Custom URL</th>
                            <td>
                                <code style="background: #d4edda; padding: 5px 10px; border-radius: 3px;">
                                    <?php echo esc_html(trailingslashit($site_url) . $custom_slug); ?>
                                </code>
                                <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_attr(trailingslashit($site_url) . $custom_slug); ?>'); alert('Copied!');">
                                    Copy
                                </button>
                                <button type="submit" name="ofast_delete_custom_url" class="button button-small" style="color: #dc3545; border-color: #dc3545;" onclick="return confirm('Are you sure you want to disable custom URL protection?');">
                                    Delete
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <th>Emergency Bypass URL</th>
                            <td>
                                <code style="background: #f8d7da; padding: 5px 10px; border-radius: 3px; font-size: 11px;">
                                    <?php echo esc_html(wp_login_url() . '?ofast_emergency=' . $emergency_key); ?>
                                </code>
                                <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_attr(wp_login_url() . '?ofast_emergency=' . $emergency_key); ?>'); alert('Copied!');">
                                    Copy
                                </button>
                                <p class="description" style="color: #dc3545;">
                                    <strong>⚠️ ONE-TIME USE:</strong> After using this link, a NEW key is generated and emailed to you.<br>
                                    The old key becomes invalid immediately. Grants 1-hour bypass access.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th>Resend Email</th>
                            <td>
                                <button type="submit" name="resend_email" class="button">
                                    Resend Login Details to Admin
                                </button>
                                <p class="description">Sends the custom URL and emergency link to <?php echo esc_html(get_option('admin_email')); ?></p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </table>

                <!-- Security Settings Section -->
                <h2 style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">Login Security Settings</h2>
                <p>Configure brute force protection to prevent unauthorized login attempts.</p>

                <?php
                $max_attempts = get_option('ofast_security_max_attempts', 5);
                $lockout_duration = get_option('ofast_security_lockout_duration', 15);
                $ip_whitelist = get_option('ofast_security_ip_whitelist', '');
                ?>

                <table class="form-table">
                    <tr>
                        <th><label for="max_attempts">Max Failed Attempts</label></th>
                        <td>
                            <input type="number" name="max_attempts" id="max_attempts"
                                value="<?php echo esc_attr($max_attempts); ?>"
                                min="1" max="20" style="width: 80px;">
                            <span>attempts before lockout</span>
                            <p class="description">How many failed login attempts before an IP is locked out (default: 5)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="lockout_duration">Lockout Duration</label></th>
                        <td>
                            <input type="number" name="lockout_duration" id="lockout_duration"
                                value="<?php echo esc_attr($lockout_duration); ?>"
                                min="1" max="1440" style="width: 80px;">
                            <span>minutes</span>
                            <p class="description">How long an IP stays locked out (default: 15 minutes, max: 24 hours)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ip_whitelist">IP Whitelist</label></th>
                        <td>
                            <textarea name="ip_whitelist" id="ip_whitelist" rows="4" class="large-text code"
                                placeholder="192.168.1.1&#10;10.0.0.1"><?php echo esc_textarea($ip_whitelist); ?></textarea>
                            <p class="description">
                                One IP address per line. These IPs will never be locked out.<br>
                                Your current IP: <code><?php echo esc_html($this->get_client_ip()); ?></code>
                                <button type="button" class="button button-small" onclick="document.getElementById('ip_whitelist').value += '\n<?php echo esc_attr($this->get_client_ip()); ?>'; this.disabled=true; this.textContent='Added!';">Add My IP</button>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="ofast_save_admin_url" class="button button-primary button-large">
                        Save Changes
                    </button>
                </p>
            </form>

            <!-- Bypass Instructions -->
            <div style="background: #f0f6fc; border: 1px solid #c3d9ed; border-radius: 8px; padding: 15px; margin-top: 20px;">
                <h3 style="margin-top: 0; color: #1d4ed8;">Recovery Options</h3>
                <p><strong>Option 1: Emergency URL</strong> - Use the emergency bypass URL (Expires upon one usage)</p>
                <p><strong>Option 2: wp-config.php</strong> - Add this line to your wp-config.php file:</p>
                <pre style="background: #fff; padding: 10px; border-radius: 4px; overflow-x: auto;">define('OFAST_DISABLE_ADMIN_PROTECTION', true);</pre>
                <p><strong>Option 3: Database</strong> - Delete the <code>ofast_admin_custom_slug</code> option from wp_options table</p>
            </div>
        </div>
<?php
    }

    /**
     * SECURITY: Check if IP is locked out
     */
    private function is_ip_locked_out($ip)
    {
        $lockout_data = get_transient('ofast_login_lockout_' . md5($ip));
        if ($lockout_data) {
            return true;
        }
        return false;
    }

    /**
     * SECURITY: Get failed login attempts for IP
     */
    private function get_failed_attempts($ip)
    {
        $attempts = get_transient('ofast_login_attempts_' . md5($ip));
        return $attempts ? intval($attempts) : 0;
    }

    /**
     * SECURITY: Record failed login attempt
     */
    public function record_failed_login($username)
    {
        $ip = $this->get_client_ip();

        // Check if IP is whitelisted - skip tracking
        if ($this->is_ip_whitelisted($ip)) {
            return;
        }

        // Get configurable settings
        $max_attempts = get_option('ofast_security_max_attempts', 5);
        $lockout_duration = get_option('ofast_security_lockout_duration', 15);

        $attempts = $this->get_failed_attempts($ip) + 1;

        // Store attempts (expires based on lockout duration)
        set_transient('ofast_login_attempts_' . md5($ip), $attempts, $lockout_duration * MINUTE_IN_SECONDS);

        // Log the attempt
        $this->log_security_event('failed_login', array(
            'username' => $username,
            'ip' => $ip,
            'attempts' => $attempts
        ));

        // If max attempts reached, lockout the IP
        if ($attempts >= $max_attempts) {
            set_transient('ofast_login_lockout_' . md5($ip), time(), $lockout_duration * MINUTE_IN_SECONDS);

            // Send alert email
            $this->send_security_alert($ip, $username, $attempts);
        }
    }

    /**
     * SECURITY: Check if IP is in whitelist
     */
    private function is_ip_whitelisted($ip)
    {
        $whitelist = get_option('ofast_security_ip_whitelist', '');
        if (empty($whitelist)) {
            return false;
        }

        $whitelisted_ips = array_filter(array_map('trim', explode("\n", $whitelist)));
        return in_array($ip, $whitelisted_ips);
    }

    /**
     * SECURITY: Clear failed attempts on successful login
     */
    public function record_successful_login($user_login, $user)
    {
        $ip = $this->get_client_ip();

        // Clear attempts
        delete_transient('ofast_login_attempts_' . md5($ip));
        delete_transient('ofast_login_lockout_' . md5($ip));

        // Log successful login
        $this->log_security_event('successful_login', array(
            'username' => $user_login,
            'ip' => $ip,
            'user_id' => $user->ID
        ));
    }

    /**
     * SECURITY: Get client IP address
     */
    private function get_client_ip()
    {
        $ip = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return sanitize_text_field($ip);
    }

    /**
     * SECURITY: Log security events
     * PERFORMANCE: Uses transients instead of options to avoid blocking DB writes
     */
    private function log_security_event($event_type, $data)
    {
        // Use hourly transients instead of a single option
        // This prevents blocking DB writes on every request
        $log_key = 'ofast_security_log_' . date('Y-m-d-H');
        $log = get_transient($log_key);
        
        if (!is_array($log)) {
            $log = array();
        }

        // Keep only last 50 events per hour
        if (count($log) >= 50) {
            $log = array_slice($log, -49);
        }

        $log[] = array(
            'type' => $event_type,
            'data' => $data,
            'timestamp' => current_time('mysql'),
        );

        // Store with 24-hour expiry (transient is non-blocking)
        set_transient($log_key, $log, DAY_IN_SECONDS);
    }

    /**
     * SECURITY: Send alert email for suspicious activity
     */
    private function send_security_alert($ip, $username, $attempts)
    {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        $site_url = home_url();

        $subject = "[Security Alert] {$site_name} - Multiple Failed Login Attempts";

        $message = "
SECURITY ALERT - Multiple Failed Login Attempts
================================================

Someone has attempted to login to your WordPress site multiple times without success.

DETAILS:
--------
• IP Address: {$ip}
• Username Attempted: {$username}
• Number of Attempts: {$attempts}
• Time: " . current_time('F j, Y \a\t g:i a') . "
• Site: {$site_url}

ACTION TAKEN:
-------------
This IP address has been temporarily blocked from logging in for 15 minutes.

RECOMMENDATIONS:
----------------
1. If this is you, wait 15 minutes and try again
2. If this is NOT you, consider:
   - Changing your password
   - Enabling two-factor authentication
   - Checking for other suspicious activity

--
Ofast X Security Module
{$site_url}
";

        $headers = array('Content-Type: text/plain; charset=UTF-8');
        wp_mail($admin_email, $subject, $message, $headers);
    }

    /**
     * SECURITY: Check lockout before login
     */
    public function check_lockout_before_auth($user, $username, $password)
    {
        if (empty($username)) {
            return $user;
        }

        $ip = $this->get_client_ip();

        // Whitelisted IPs bypass lockout
        if ($this->is_ip_whitelisted($ip)) {
            return $user;
        }

        if ($this->is_ip_locked_out($ip)) {
            $this->log_security_event('blocked_attempt', array(
                'ip' => $ip,
                'username' => $username
            ));

            $lockout_duration = get_option('ofast_security_lockout_duration', 15);

            return new WP_Error(
                'ofast_locked_out',
                '<strong>Security Lockout:</strong> Too many failed login attempts. Please try again in ' . $lockout_duration . ' minutes.'
            );
        }

        return $user;
    }

    /**
     * Register security hooks (call this from init)
     */
    public function register_security_hooks()
    {
        // Hook into failed logins
        add_action('wp_login_failed', array($this, 'record_failed_login'));

        // Hook into successful logins
        add_action('wp_login', array($this, 'record_successful_login'), 10, 2);

        // Check lockout before authentication
        add_filter('authenticate', array($this, 'check_lockout_before_auth'), 30, 3);
    }
}
