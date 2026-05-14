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
     * SECURITY: Check if connection is secure (HTTPS)
     * Handles reverse proxies, load balancers, and CDNs
     * 
     * @return bool True if connection is secure
     */
    private function is_secure_connection()
    {
        // Standard WordPress SSL check
        if (is_ssl()) {
            return true;
        }
        
        // Check for proxy headers (common with Cloudflare, AWS ELB, nginx reverse proxy)
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }
        
        if (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
            return true;
        }
        
        // CloudFlare specific
        if (isset($_SERVER['HTTP_CF_VISITOR'])) {
            $cf_visitor = json_decode($_SERVER['HTTP_CF_VISITOR']);
            if (isset($cf_visitor->scheme) && $cf_visitor->scheme === 'https') {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Send a hardened cookie with SameSite support.
     *
     * @param string $name    Cookie name.
     * @param string $value   Cookie value.
     * @param int    $expires Expiry timestamp.
     * @return void
     */
    private function send_cookie($name, $value, $expires)
    {
        $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        $secure = $this->is_secure_connection();
        $http_only = true;

        if (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70300) {
            setcookie($name, $value, array(
                'expires' => $expires,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => $http_only,
                'samesite' => 'Strict',
            ));
            return;
        }

        setcookie($name, $value, $expires, $path . '; samesite=Strict', $domain, $secure, $http_only);
    }

    /**
     * Clear a cookie immediately.
     *
     * @param string $name Cookie name.
     * @return void
     */
    private function clear_cookie($name)
    {
        unset($_COOKIE[$name]);
        $this->send_cookie($name, 'expired', time() - HOUR_IN_SECONDS);
    }

    /**
     * Generate a temporary token for admin-access cookies.
     *
     * @return string
     */
    private function generate_session_token()
    {
        return function_exists('random_bytes')
            ? bin2hex(random_bytes(32))
            : wp_generate_password(64, false);
    }

    /**
     * Check if a session-backed cookie is still valid.
     *
     * @param string $cookie_name      Cookie name.
     * @param string $transient_prefix Transient prefix.
     * @return bool
     */
    private function has_valid_session_cookie($cookie_name, $transient_prefix)
    {
        if (empty($_COOKIE[$cookie_name])) {
            return false;
        }

        $session_token = sanitize_text_field(wp_unslash($_COOKIE[$cookie_name]));
        if ($session_token === '') {
            $this->clear_cookie($cookie_name);
            return false;
        }

        $transient_key = $transient_prefix . md5($session_token);
        if (get_transient($transient_key)) {
            return true;
        }

        $this->clear_cookie($cookie_name);
        return false;
    }

    /**
     * Clear a session-backed cookie and its transient.
     *
     * @param string $cookie_name      Cookie name.
     * @param string $transient_prefix Transient prefix.
     * @return void
     */
    private function clear_session_cookie($cookie_name, $transient_prefix)
    {
        if (!empty($_COOKIE[$cookie_name])) {
            $session_token = sanitize_text_field(wp_unslash($_COOKIE[$cookie_name]));
            if ($session_token !== '') {
                delete_transient($transient_prefix . md5($session_token));
            }
        }

        $this->clear_cookie($cookie_name);
    }

    /**
     * Create the temporary session that proves a user entered via the custom URL.
     *
     * @return void
     */
    private function set_custom_login_cookie()
    {
        $session_token = $this->generate_session_token();
        set_transient('ofast_custom_login_session_' . md5($session_token), true, HOUR_IN_SECONDS);
        $this->send_cookie('ofast_custom_login', $session_token, time() + HOUR_IN_SECONDS);
        $_COOKIE['ofast_custom_login'] = $session_token;
    }

    /**
     * Check whether the custom-login cookie is valid.
     *
     * @return bool
     */
    private function has_valid_custom_login_cookie()
    {
        return $this->has_valid_session_cookie('ofast_custom_login', 'ofast_custom_login_session_');
    }

    /**
     * Check whether the emergency bypass cookie is valid.
     *
     * @return bool
     */
    private function has_valid_bypass_cookie()
    {
        return $this->has_valid_session_cookie('ofast_admin_bypass', 'ofast_bypass_session_');
    }

    /**
     * Get a safe return URL for admin notices after saving settings.
     *
     * @param array $args Query args to append.
     * @return string
     */
    private function get_return_url($args = array())
    {
        $fallback = admin_url('admin.php?page=ofast-admin-tweaks');
        $referer = wp_get_referer();
        $base_url = $referer ? $referer : $fallback;

        return add_query_arg($args, $base_url);
    }

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
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_url_assets'));

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

        // Clear login cookies on logout
        add_action('wp_logout', array($this, 'clear_login_cookies'));

        // Register security hooks (login attempt tracking, lockout)
        $this->register_security_hooks();

        // Check for emergency key in URL (timing-safe comparison)
        if (isset($_GET['ofast_emergency']) && !empty($this->emergency_key)) {
            // SECURITY: Rate limit emergency key attempts to prevent brute force
            $client_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
            $rate_key = 'ofast_emergency_attempts_' . md5($client_ip);
            $attempts = (int) get_transient($rate_key);
            
            if ($attempts >= 5) {
                // Log the rate limit event
                $this->log_security_event('emergency_rate_limited', array(
                    'ip' => $client_ip,
                    'attempts' => $attempts
                ));
                wp_die(
                    __('Too many emergency access attempts. Please try again in 5 minutes.', 'ofast-x'),
                    __('Rate Limited', 'ofast-x'),
                    array('response' => 429)
                );
            }
            
            // Increment attempt counter (5 minute window)
            set_transient($rate_key, $attempts + 1, 5 * MINUTE_IN_SECONDS);
            
            $provided_key = sanitize_text_field(wp_unslash($_GET['ofast_emergency']));
            if (hash_equals($this->emergency_key, $provided_key)) {
                // Clear rate limit on successful use
                delete_transient($rate_key);
                // ONE-TIME USE: Rotate the key immediately after use
                $new_key = $this->rotate_emergency_key();

                // Set bypass cookie with cryptographically secure session token
                $session_token = $this->generate_session_token();
                $this->send_cookie('ofast_admin_bypass', $session_token, time() + HOUR_IN_SECONDS);
                $_COOKIE['ofast_admin_bypass'] = $session_token;

                // Store session token temporarily
                set_transient('ofast_bypass_session_' . md5($session_token), true, HOUR_IN_SECONDS);

                // Send email with new key
                $this->send_new_emergency_key_email($new_key);

                return;
            }
        }

        // Check for bypass cookie (session-based, not key-based)
        if ($this->has_valid_bypass_cookie()) {
            return;
        }

        // Register custom URL handler
        add_action('init', array($this, 'handle_custom_url'), 1);

        // Block default login page - uses login_init which fires only on wp-login.php
        add_action('login_init', array($this, 'block_login_page'), 0);
        
        // Block wp-admin pages - uses template_redirect which fires after all plugins load
        // This ensures WooCommerce, Tutor LMS, etc. are ready before loading 404 template
        add_action('template_redirect', array($this, 'block_admin_pages'), 1);
    }

    /**
     * Add admin menu
     * NOTE: Submenu removed - Admin URL settings are now inline in Admin Tweaks page
     */
    public function add_admin_menu()
    {
        // Submenu removed - settings are inline in Admin Tweaks
        // Keep this method for backward compatibility (hook still registered in init)
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
                wp_safe_redirect($this->get_return_url(array('ofast_status' => 'deleted')));
                exit;
            }
        }

        // Handle resend email
        if (isset($_POST['resend_email'])) {
            check_admin_referer('ofast_admin_url_save', '_wpnonce');
            if (current_user_can('manage_options')) {
                $custom_slug = get_option('ofast_admin_custom_slug', '');
                $emergency_key = get_option('ofast_admin_emergency_key', '');
                if (!empty($custom_slug) && !empty($emergency_key)) {
                    $this->send_admin_notification($custom_slug, $emergency_key);
                    wp_safe_redirect($this->get_return_url(array('ofast_status' => 'resent')));
                    exit;
                } else {
                    wp_safe_redirect($this->get_return_url(array('ofast_status' => 'no_url')));
                    exit;
                }
            }
        }

        if (!isset($_POST['ofast_save_admin_url'])) {
            return;
        }

        check_admin_referer('ofast_admin_url_save', '_wpnonce');

        if (!current_user_can('manage_options')) {
            return;
        }

        $old_slug = get_option('ofast_admin_custom_slug', '');
        $new_slug = isset($_POST['custom_slug']) ? sanitize_title(wp_unslash($_POST['custom_slug'])) : '';

        // Validate slug
        $reserved = array('wp-admin', 'wp-login', 'wp-login.php', 'admin', 'login', 'dashboard', 'wp-content', 'wp-includes');
        if (in_array($new_slug, $reserved, true)) {
            add_settings_error('ofast_admin_url', 'reserved', __('That URL slug is reserved. Please choose another.', 'ofast-x'), 'error');
            return;
        }

        // Check for existing content with same slug (pages, posts, etc.)
        if (!empty($new_slug)) {
            $existing_page = get_page_by_path($new_slug);
            $existing_post = get_page_by_path($new_slug, OBJECT, 'post');
            
            if ($existing_page || $existing_post) {
                add_settings_error('ofast_admin_url', 'collision', __('That URL already exists as a page or post. Please choose another.', 'ofast-x'), 'error');
                return;
            }
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
        $max_attempts = isset($_POST['max_attempts']) ? max(1, min(20, intval(wp_unslash($_POST['max_attempts'])))) : 5;
        $lockout_duration = isset($_POST['lockout_duration']) ? max(1, min(1440, intval(wp_unslash($_POST['lockout_duration'])))) : 15;
        $ip_whitelist = isset($_POST['ip_whitelist']) ? sanitize_textarea_field(wp_unslash($_POST['ip_whitelist'])) : '';

        update_option('ofast_security_max_attempts', $max_attempts);
        update_option('ofast_security_lockout_duration', $lockout_duration);
        update_option('ofast_security_ip_whitelist', $ip_whitelist);

        wp_safe_redirect($this->get_return_url(array('ofast_status' => 'saved')));
        exit;
    }

    /**
     * Send notification email to admin
     */
    private function send_admin_notification($custom_slug, $emergency_key)
    {
        $admin_email = get_option('admin_email');
        
        // SECURITY: Validate email before sending
        if (!is_email($admin_email)) {
            error_log('Ofast X: Invalid admin email, cannot send notification');
            return false;
        }
        
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
        
        // SECURITY: Validate email before sending
        if (!is_email($admin_email)) {
            error_log('Ofast X: Invalid admin email, cannot send emergency key');
            return false;
        }
        
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
        $request_uri = parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        $request_uri = rtrim($request_uri, '/');
        $custom_slug = '/' . trim($this->custom_slug, '/');

        // Check if accessing custom login URL
        if ($request_uri === $custom_slug || str_ends_with($request_uri, $custom_slug)) {
            // Set a cookie to allow admin access
            $this->set_custom_login_cookie();

            // Redirect to wp-login.php
            wp_safe_redirect(wp_login_url());
            exit;
        }
    }

    /**
     * Block wp-login.php access for unauthorized users
     * Uses wp_die() instead of full 404 template to avoid plugin conflicts
     */
    public function block_login_page()
    {
        // Allow if custom login cookie is set
        if ($this->has_valid_custom_login_cookie()) {
            return;
        }

        // Allow logout action (must be able to logout!)
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        if ($action === 'logout') {
            return;
        }
        
        // Allow logged-out redirect
        $logged_out = isset($_GET['loggedout']) ? sanitize_text_field(wp_unslash($_GET['loggedout'])) : '';
        if ($logged_out === 'true') {
            return;
        }
        
        // Allow password reset actions
        if (in_array($action, array('lostpassword', 'rp', 'resetpass'), true)) {
            return;
        }
        
        // If user is already logged in, allow access
        if (is_user_logged_in()) {
            return;
        }

        // Block access with a simple 404 response (no full template to avoid WooCommerce/Tutor LMS errors)
        status_header(404);
        nocache_headers();
        wp_die(
            'Page not found',
            '404 Not Found',
            array(
                'response' => 404,
                'back_link' => false,
            )
        );
    }

    /**
     * Block /wp-admin access for unauthorized users
     * Uses full 404 template since this fires on template_redirect (after plugins are ready)
     */
    public function block_admin_pages()
    {
        $request_uri = wp_unslash($_SERVER['REQUEST_URI']);

        // Only block wp-admin requests
        if (strpos($request_uri, '/wp-admin') === false) {
            return;
        }

        // Allow if custom login cookie is set
        if ($this->has_valid_custom_login_cookie()) {
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

        // If user is already logged in, allow all admin access
        if (is_user_logged_in()) {
            return;
        }

        // Don't block admin assets (images, css, js)
        if (preg_match('/\.(css|js|png|jpg|gif|ico|svg|woff|woff2|ttf|eot)$/i', $request_uri)) {
            return;
        }

        $this->show_404();
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
        $logged_out = isset($_GET['loggedout']) ? sanitize_text_field(wp_unslash($_GET['loggedout'])) : '';
        if ($logged_out === 'true') {
            wp_safe_redirect(home_url('/'));
            exit;
        }
    }

    /**
     * Clear login cookies on logout
     * Prevents unauthorized access after logout
     */
    public function clear_login_cookies()
    {
        // Clear the custom login cookie
        $this->clear_session_cookie('ofast_custom_login', 'ofast_custom_login_session_');
        
        // Clear the admin bypass cookie
        $this->clear_session_cookie('ofast_admin_bypass', 'ofast_bypass_session_');
    }

    /**
     * Enqueue admin URL CSS on the admin tweaks page.
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueue_admin_url_assets($hook)
    {
        if (strpos($hook, 'ofast-admin-tweaks') === false) {
            return;
        }

        wp_enqueue_style(
            'ofast-admin-url',
            plugins_url('assets/css/admin-url.css', __FILE__),
            array(),
            OFAST_X_VERSION
        );
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

        if (isset($_GET['ofast_status'])) {
            switch ($_GET['ofast_status']) {
                case 'saved':
                    echo Ofast_X_Toast::render('Settings saved successfully!', 'success');
                    break;
                case 'deleted':
                    echo Ofast_X_Toast::render('Custom URL protection disabled.', 'warning');
                    break;
                case 'resent':
                    echo Ofast_X_Toast::render('Login details resent to admin email!', 'success');
                    break;
                case 'no_url':
                    echo Ofast_X_Toast::render('No custom URL configured.', 'error');
                    break;
            }
        }
        ?>
        <!-- Styles loaded via wp_enqueue_style: assets/css/admin-url.css -->

        <div class="wrap">
            <!-- Header -->
            <div class="ofast-header">
                <div class="ofast-header-icon">
                    <span class="dashicons dashicons-lock"></span>
                </div>
                <div class="ofast-header-content">
                    <h1>Admin URL Security</h1>
                    <p>Hide your WordPress login page behind a secret custom URL to prevent brute force attacks.</p>
                </div>
            </div>

            <div class="ofast-card">
                <!-- Warning Box -->
                <div class="ofast-warning-box">
                    <h3><span class="dashicons dashicons-warning" style="color: #000;"></span> Important Warning</h3>
                    <ul>
                        <li>When enabled, <code>/wp-admin</code> and <code>/wp-login.php</code> will return 404 errors. You <strong>MUST</strong> remember your custom URL to log in.</li>
                        <li>An email with your new URL and emergency backup will be sent to the admin email. If locked out, add <code>define('OFAST_DISABLE_ADMIN_PROTECTION', true);</code> to <code>wp-config.php</code>.</li>
                    </ul>
                </div>

                <form method="post" action="">
                    <?php wp_nonce_field('ofast_admin_url_save', '_wpnonce'); ?>

                    <table class="form-table">
                        <tr>
                            <th style="padding-top: 15px;"><label for="custom_slug" style="font-weight: 600; color: #1e293b;">Custom Login URL</label></th>
                            <td>
                                <div class="ofast-input-group">
                                    <span style="color: #64748b; font-family: monospace; background: #f1f5f9; padding: 8px 10px; border-radius: 6px 0 0 6px; border: 1px solid #e2e8f0; border-right: none;"><?php echo esc_html($site_url); ?>/</span>
                                    <input type="text" name="custom_slug" id="custom_slug"
                                        value="<?php echo esc_attr($custom_slug); ?>"
                                        class="regular-text"
                                        placeholder="my-secret-login"
                                        pattern="[a-z0-9\-]+"
                                        style="border-radius: 0 6px 6px 0; width: 250px;">
                                </div>
                                <p class="description" style="margin-top: 8px;">
                                    Only lowercase letters, numbers, and hyphens allowed.<br>
                                    Leave empty to disable protection and restore default login URLs.
                                </p>
                            </td>
                        </tr>
                        <?php if (!empty($custom_slug) && !empty($emergency_key)): ?>
                            <tr>
                                <th style="padding-top: 20px;">current_URLs</th>
                                <td>
                                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; display: inline-block; width: 100%; max-width: 600px;">
                                        <div style="margin-bottom: 15px;">
                                            <strong style="display: block; margin-bottom: 5px; color: #1e293b;">Custom Login URL:</strong>
                                            <code style="background: #fff; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; color: #059669; display: block; margin-bottom: 8px;">
                                                <?php echo esc_html(trailingslashit($site_url) . $custom_slug); ?>
                                            </code>
                                            <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_attr(trailingslashit($site_url) . $custom_slug); ?>'); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000);">
                                                Copy
                                            </button>
                                            <button type="submit" name="ofast_delete_custom_url" class="button button-small" style="color: #ef4444; border-color: #ef4444; margin-left: 5px;" onclick="return confirm('Are you sure you want to disable custom URL protection?');">
                                                Delete & Disable
                                            </button>
                                        </div>

                                        <div>
                                            <strong style="display: block; margin-bottom: 5px; color: #1e293b;">Emergency Bypass URL (One-Time Use):</strong>
                                            <code style="background: #fff; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; color: #ef4444; display: block; margin-bottom: 8px; font-size: 11px;">
                                                <?php echo esc_html(wp_login_url() . '?ofast_emergency=' . $emergency_key); ?>
                                            </code>
                                            <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_attr(wp_login_url() . '?ofast_emergency=' . $emergency_key); ?>'); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000);">
                                                Copy
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div style="margin-top: 15px;">
                                         <button type="submit" name="resend_email" class="button">
                                            Resend Login Details
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </table>

                    <hr style="margin: 30px 0; border: 0; border-top: 1px solid #e2e8f0;">

                    <!-- Security Settings Section -->
                    <h2 style="font-size: 18px; color: #1e293b; margin-bottom: 5px;">Login Limit Settings</h2>
                    <p style="color: #64748b; margin-top: 0; margin-bottom: 20px;">Configure brute force protection parameters.</p>

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
                                <span class="description"> attempts before lockout</span>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="lockout_duration">Lockout Duration</label></th>
                            <td>
                                <input type="number" name="lockout_duration" id="lockout_duration"
                                    value="<?php echo esc_attr($lockout_duration); ?>"
                                    min="1" max="1440" style="width: 80px;">
                                <span class="description"> minutes</span>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ip_whitelist">IP Whitelist</label></th>
                            <td>
                                <textarea name="ip_whitelist" id="ip_whitelist" rows="4" class="large-text code"
                                    placeholder="192.168.1.1&#10;10.0.0.1"><?php echo esc_textarea($ip_whitelist); ?></textarea>
                                <p class="description" style="margin-top: 8px;">
                                    One IP address per line. These IPs will never be locked out.<br>
                                    Your current IP: <code style="background: #f1f5f9;"><?php echo esc_html($this->get_client_ip()); ?></code>
                                    <button type="button" class="button button-small" style="margin-left: 5px;" onclick="document.getElementById('ip_whitelist').value += '\n<?php echo esc_attr($this->get_client_ip()); ?>'; this.disabled=true; this.textContent='Added!';">Add My IP</button>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
                        <button type="submit" name="ofast_save_admin_url" class="button button-primary button-large" style="min-width: 150px;">
                            Save Settings
                        </button>
                    </div>
                </form>

                <!-- Recovery Options -->
                <div class="ofast-recovery-box">
                    <h3>Recovery Options</h3>
                    <p><strong>Option 1: Emergency URL</strong> — Use the emergency bypass URL sent to your email (Expires upon entry).</p>
                    <p><strong>Option 2: wp-config.php</strong> — Add this line to your <code>wp-config.php</code> file to disable protection:</p>
                    <pre>define('OFAST_DISABLE_ADMIN_PROTECTION', true);</pre>
                    <p><strong>Option 3: Database</strong> — Delete the <code>ofast_admin_custom_slug</code> entry from the <code>wp_options</code> table.</p>
                </div>
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
        return in_array($ip, $whitelisted_ips, true);
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
     * Only trusts forwarding headers from known proxies to prevent IP spoofing
     */
    private function get_client_ip()
    {
        // Start with REMOTE_ADDR (cannot be spoofed)
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        
        // Define trusted proxies (localhost by default, filterable for custom setups)
        $trusted_proxies = apply_filters('ofast_trusted_proxies', array('127.0.0.1', '::1'));
        
        // Only trust forwarding headers if request comes from a trusted proxy
        if (in_array($ip, $trusted_proxies, true)) {
            // Check X-Forwarded-For first (most common)
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $forwarded_ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $ip = trim($forwarded_ips[0]);
            } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
                $ip = $_SERVER['HTTP_CLIENT_IP'];
            }
        }
        
        // Validate IP address format
        $validated_ip = filter_var($ip, FILTER_VALIDATE_IP);
        
        return $validated_ip ? sanitize_text_field($validated_ip) : '0.0.0.0';
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
        
        // SECURITY: Validate email before sending
        if (!is_email($admin_email)) {
            error_log('Ofast X: Invalid admin email, cannot send security alert');
            return false;
        }
        
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
