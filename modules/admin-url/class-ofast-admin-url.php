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
        // Only load if module is enabled
        $enabled = get_option('ofastx_modules_enabled', array());
        if (empty($enabled['admin-url'])) {
            return;
        }

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

        // Check for emergency key in URL
        if (isset($_GET['ofast_emergency']) && $_GET['ofast_emergency'] === $this->emergency_key) {
            // Valid emergency access - set a cookie for 1 hour
            setcookie('ofast_admin_bypass', $this->emergency_key, time() + 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
            return;
        }

        // Check for bypass cookie
        if (isset($_COOKIE['ofast_admin_bypass']) && $_COOKIE['ofast_admin_bypass'] === $this->emergency_key) {
            return;
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

        $subject = "[{$site_name}] ⚠️ Admin URL Changed - Save This Email!";

        $message = "
=================================================
🔐 ADMIN URL SECURITY UPDATE
=================================================

Your WordPress admin URL has been changed for security purposes.

⚠️ IMPORTANT: Save this email! You need these URLs to log in.

--------------------------------------------------
🔗 YOUR NEW LOGIN URL:
--------------------------------------------------
{$custom_url}

Use this URL to access your WordPress dashboard.
The default /wp-admin and /wp-login.php are now hidden.

--------------------------------------------------
🆘 EMERGENCY BACKUP LOGIN:
--------------------------------------------------
{$emergency_url}

⚡ Use this ONLY if you forget your custom URL or get locked out.
This emergency link bypasses protection for 1 hour.

--------------------------------------------------
🔧 PERMANENT BYPASS (Developer Option):
--------------------------------------------------
If you ever get locked out completely, add this line to your wp-config.php file:

define('OFAST_DISABLE_ADMIN_PROTECTION', true);

This will disable the protection until you remove it.

--------------------------------------------------
📌 SUMMARY:
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
     * Handle custom URL access
     */
    public function handle_custom_url()
    {
        $request_uri = $_SERVER['REQUEST_URI'];
        $custom_slug = '/' . $this->custom_slug;

        // Check if accessing custom login URL
        if (strpos($request_uri, $custom_slug) === 0 || $request_uri === $custom_slug) {
            // Allow access to wp-login.php
            require_once ABSPATH . 'wp-login.php';
            exit;
        }
    }

    /**
     * Block default wp-admin and wp-login.php access
     */
    public function block_default_access()
    {
        $request_uri = $_SERVER['REQUEST_URI'];

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

        // If user is already logged in, allow admin access
        if (is_user_logged_in() && is_admin()) {
            return;
        }

        // Block direct access to wp-login.php
        if (strpos($request_uri, 'wp-login.php') !== false) {
            $this->show_404();
        }

        // Block direct /wp-admin access for non-logged in users  
        if (strpos($request_uri, '/wp-admin') !== false && !is_user_logged_in()) {
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
            <h1>🔐 Admin URL Security</h1>
            <p class="description">Hide your WordPress login page behind a secret custom URL.</p>

            <!-- Warning Box -->
            <div style="background: #fff; border: 1px solid #dc3545; border-radius: 8px; padding: 15px; margin: 20px 0;">
                <h3 style="margin-top: 0; color: #dc3545;">⚠️ Important Warning</h3>
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
                                    ⚡ Use only if locked out. Grants 1-hour bypass access.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th>Resend Email</th>
                            <td>
                                <button type="submit" name="resend_email" class="button">
                                    📧 Resend Login Details to Admin
                                </button>
                                <p class="description">Sends the custom URL and emergency link to <?php echo esc_html(get_option('admin_email')); ?></p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </table>

                <p class="submit">
                    <button type="submit" name="ofast_save_admin_url" class="button button-primary button-large">
                        💾 Save Changes
                    </button>
                </p>
            </form>

            <!-- Bypass Instructions -->
            <div style="background: #f0f6fc; border: 1px solid #c3d9ed; border-radius: 8px; padding: 15px; margin-top: 20px;">
                <h3 style="margin-top: 0; color: #1d4ed8;">🔧 Recovery Options</h3>
                <p><strong>Option 1: Emergency URL</strong> - Use the emergency bypass URL (valid for 1 hour per use)</p>
                <p><strong>Option 2: wp-config.php</strong> - Add this line to your wp-config.php file:</p>
                <pre style="background: #fff; padding: 10px; border-radius: 4px; overflow-x: auto;">define('OFAST_DISABLE_ADMIN_PROTECTION', true);</pre>
                <p><strong>Option 3: Database</strong> - Delete the <code>ofast_admin_custom_slug</code> option from wp_options table</p>
            </div>
        </div>
<?php
    }
}
