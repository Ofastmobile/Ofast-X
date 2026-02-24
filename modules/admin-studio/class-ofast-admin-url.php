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
            
            $provided_key = sanitize_text_field($_GET['ofast_emergency']);
            if (hash_equals($this->emergency_key, $provided_key)) {
                // Clear rate limit on successful use
                delete_transient($rate_key);
                // ONE-TIME USE: Rotate the key immediately after use
                $new_key = $this->rotate_emergency_key();

                // Set bypass cookie with cryptographically secure session token
                $session_token = function_exists('random_bytes') 
                    ? bin2hex(random_bytes(32)) 
                    : wp_generate_password(64, false);
                setcookie('ofast_admin_bypass', $session_token, time() + 3600, COOKIEPATH, COOKIE_DOMAIN, $this->is_secure_connection(), true);

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
                wp_redirect(add_query_arg('ofast_status', 'deleted', wp_get_referer()));
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
                    wp_redirect(add_query_arg('ofast_status', 'resent', wp_get_referer()));
                    exit;
                } else {
                    wp_redirect(add_query_arg('ofast_status', 'no_url', wp_get_referer()));
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
        $new_slug = sanitize_title($_POST['custom_slug']);

        // Validate slug
        $reserved = array('wp-admin', 'wp-login', 'wp-login.php', 'admin', 'login', 'dashboard', 'wp-content', 'wp-includes');
        if (in_array($new_slug, $reserved)) {
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
        $max_attempts = isset($_POST['max_attempts']) ? max(1, min(20, intval($_POST['max_attempts']))) : 5;
        $lockout_duration = isset($_POST['lockout_duration']) ? max(1, min(1440, intval($_POST['lockout_duration']))) : 15;
        $ip_whitelist = isset($_POST['ip_whitelist']) ? sanitize_textarea_field($_POST['ip_whitelist']) : '';

        update_option('ofast_security_max_attempts', $max_attempts);
        update_option('ofast_security_lockout_duration', $lockout_duration);
        update_option('ofast_security_ip_whitelist', $ip_whitelist);

        wp_redirect(add_query_arg('ofast_status', 'saved', wp_get_referer()));
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
        $request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $request_uri = rtrim($request_uri, '/');
        $custom_slug = '/' . trim($this->custom_slug, '/');

        // Check if accessing custom login URL
        if ($request_uri === $custom_slug || str_ends_with($request_uri, $custom_slug)) {
            // Set a cookie to allow admin access
            setcookie('ofast_custom_login', '1', time() + 3600, COOKIEPATH, COOKIE_DOMAIN, $this->is_secure_connection(), true);

            // Redirect to wp-login.php
            wp_redirect(wp_login_url());
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
        if (isset($_COOKIE['ofast_custom_login'])) {
            return;
        }

        // Allow logout action (must be able to logout!)
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            return;
        }
        
        // Allow logged-out redirect
        if (isset($_GET['loggedout']) && $_GET['loggedout'] === 'true') {
            return;
        }
        
        // Allow password reset actions
        if (isset($_GET['action']) && in_array($_GET['action'], array('lostpassword', 'rp', 'resetpass'), true)) {
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
        $request_uri = $_SERVER['REQUEST_URI'];

        // Only block wp-admin requests
        if (strpos($request_uri, '/wp-admin') === false) {
            return;
        }

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
        if (isset($_GET['loggedout']) && $_GET['loggedout'] === 'true') {
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
        if (isset($_COOKIE['ofast_custom_login'])) {
            setcookie('ofast_custom_login', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, $this->is_secure_connection(), true);
        }
        
        // Clear the admin bypass cookie
        if (isset($_COOKIE['ofast_admin_bypass'])) {
            $session_token = $_COOKIE['ofast_admin_bypass'];
            // Also delete the associated transient
            delete_transient('ofast_bypass_session_' . md5($session_token));
            setcookie('ofast_admin_bypass', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, $this->is_secure_connection(), true);
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
        <!-- Critical Admin Styles -->
        <style>
            /* Colors */
            :root {
                --ofast-primary: #6366f1;
                --ofast-danger: #ef4444;
                --ofast-warning-bg: #fef2f2;
                --ofast-warning-border: #fee2e2;
                --ofast-text: #1e293b;
                --ofast-text-muted: #64748b;
            }

            /* Header Styles */
            .ofast-header {
                display: flex;
                align-items: center;
                gap: 20px;
                background: #fff;
                padding: 25px 30px;
                border-radius: 12px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                margin-bottom: 30px;
                margin-top: 20px;
            }
            .ofast-header-icon {
                width: 56px;
                height: 56px;
                background: #fff;
                border: 1px solid #e2e8f0;
                box-shadow: 0 2px 4px rgba(0,0,0,0.02);
                border-radius: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .ofast-header-icon .dashicons {
                font-size: 28px;
                width: 28px;
                height: 28px;
                color: #6366f1;
            }
            .ofast-header-content h1 {
                margin: 0 0 5px 0;
                font-size: 24px;
                font-weight: 700;
                color: #1e293b;
                display: block;
                padding: 0;
            }
            .ofast-header-content p {
                margin: 0;
                color: #64748b;
                font-size: 14px;
            }

            /* Card Styles */
            .ofast-card {
                background: #fff;
                border-radius: 16px;
                padding: 40px;
                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
                border: 1px solid rgba(226, 232, 240, 0.6);
            }
            
            /* Warning Box */
            .ofast-warning-box {
                background: #fff;
                border: 1px solid var(--ofast-warning-border);
                border-radius: 12px;
                padding: 20px;
                margin-bottom: 30px;
            }
            .ofast-warning-box h3 {
                color: #000;
                margin-top: 0;
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 16px;
            }
            .ofast-warning-box ul {
                color: #000;
                margin-bottom: 0;
                padding-left: 20px;
            }
            
            /* Inputs */
            .ofast-input-group {
                display: flex;
                align-items: center;
                gap: 5px;
            }
            .ofast-card input[type="text"],
            .ofast-card input[type="number"],
            .ofast-card textarea {
                border: 1px solid #e2e8f0;
                border-radius: 6px;
                padding: 8px 12px;
                font-size: 14px;
                box-shadow: 0 1px 2px rgba(0,0,0,0.05);
                transition: all 0.2s;
            }
            .ofast-card input:focus,
            .ofast-card textarea:focus {
                border-color: var(--ofast-primary);
                box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
                outline: none;
            }

            /* Button Override */
            .button.button-primary {
                background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%) !important;
                border-color: #6366f1 !important;
                text-shadow: none !important;
                box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3) !important;
                transition: all 0.3s ease !important;
                padding: 10px 25px !important;
                height: auto !important;
                font-size: 15px !important;
                border-radius: 8px !important;
            }
            .button.button-primary:hover {
                background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%) !important;
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4) !important;
            }
            .button.button-primary:active {
                transform: translateY(0);
            }

            /* Secondary Button Override (Outline Style) */
            .ofast-card .button.button-small:not(.delete-btn),
            .ofast-card .button:not(.button-primary):not(.delete-btn) {
                color: var(--ofast-primary) !important;
                border-color: var(--ofast-primary) !important;
                background: #fff !important;
                border-radius: 6px !important;
                border-width: 1px !important;
                transition: all 0.2s !important;
            }
            .ofast-card .button:not(.button-primary):not(.delete-btn):hover {
                background: #eff6ff !important;
                transform: translateY(-1px);
            }
            /* Explicitly exclude delete button from purple override if it doesn't have a specific class, 
               but in the HTML it has inline styles. To be safe, adding a check or ensuring inline precedence works. 
               The 'delete' button in HTML has inline style="color: #ef4444...", so !important here might break it.
               I'll rely on the fact that inline !important (if used) overrides, but the inline style there doesn't have !important.
               I should exclude it via attribute selector or add a class. 
               Looking at HTML: <button ... style="color: #dc3545; ..."> 
               I'll try to target only specific buttons or exclude by style attribute presence? No, CSS can't easily do that.
               I will modify the HTML to add a class to the delete button in a separate step or just assume the inline style is sufficient? 
               Wait, my CSS above uses !important `color: var(--ofast-primary) !important`. This WILL override inline styles.
               I must be careful.
               The delete button has `name="ofast_delete_custom_url"`.
               I can use `.ofast-card .button[name="ofast_delete_custom_url"]` to reset it or exclude it.
            */
            .ofast-card .button[name="ofast_delete_custom_url"] {
                color: var(--ofast-danger) !important;
                border-color: var(--ofast-danger) !important;
            }

            /* Recovery Box */
            .ofast-recovery-box {
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                padding: 25px;
                margin-top: 40px;
            }
            .ofast-recovery-box h3 {
                color: #1e293b;
                margin-top: 0;
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 16px;
                font-weight: 700;
            }
            .ofast-recovery-box p {
                margin: 12px 0;
                font-size: 13px;
                color: #475569;
                line-height: 1.5;
            }
            .ofast-recovery-box pre {
                background: #fff;
                padding: 12px;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                font-size: 12px;
                color: #334155;
                overflow-x: auto;
                margin: 10px 0;
            }
        </style>

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
