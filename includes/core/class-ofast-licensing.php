<?php

/**
 * Ofast Toolkit Licensing & Pro-Feature Gatekeeper
 *
 * Self-hosted licensing system that validates against ofastshop.com API.
 * One-time purchase, one key per site, lifetime license with periodic validation.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if the user has a valid Pro license.
 *
 * This is the "Master Switch" for all Pro features.
 * Freemium model: Free features always available, Pro requires a license key.
 *
 * @return boolean True if Pro (licensed), False if free.
 */
function ofast_toolkit_is_pro()
{
    if (defined('OFAST_FORCE_PRO') && OFAST_FORCE_PRO) {
        return true;
    }

    if ( ofast_toolkit_has_valid_license() ) {
        return true;
    }

    return false;
}

/**
 * Check if the user has a valid, active license.
 *
 * @return boolean
 */
function ofast_toolkit_has_valid_license()
{
    $status = get_option('ofast_license_status', 'inactive');
    if ($status !== 'active') {
        return false;
    }

    $key       = get_option('ofast_license_key', '');
    $signature = get_option('ofast_license_signature', '');
    if (empty($key) || empty($signature)) {
        return false;
    }

    // 30-day freshness check — if validation hasn't run in 30 days, lock
    $last_check = (int) get_option('ofast_license_last_check', 0);
    if ($last_check && (time() - $last_check) > (30 * DAY_IN_SECONDS)) {
        update_option('ofast_license_status', 'inactive');
        return false;
    }

    return true;
}


/**
 * (Optional) Check if a specific module/plan is active, if you add different tiers later.
 * Currently defaults to the master switch.
 *
 * @param string $feature The feature to check.
 * @return boolean
 */
function ofast_toolkit_can_use_feature($feature = '')
{
    return ofast_toolkit_is_pro();
}

/**
 * Output the Pro Lock Badge HTML if the user is not Pro.
 * Call this next to the label of a premium feature.
 */
function ofast_toolkit_pro_badge()
{
    if ( ! ofast_toolkit_is_pro() ) {
        echo '<span class="dashicons dashicons-lock ofast-pro-badge" title="This is a Pro feature" style="color: #f59e0b; margin-left: 6px; font-size: 16px; width: 16px; height: 16px; vertical-align: middle;"></span>';
    }
}

/**
 * Output the 'disabled' attribute if the user is not Pro.
 * Add this inside input/select tags for premium features.
 */
function ofast_toolkit_pro_disabled()
{
    if ( ! ofast_toolkit_is_pro() ) {
        echo ' disabled="disabled" ';
    }
}

/**
 * Get the upgrade/purchase URL.
 *
 * @return string URL to the Ofast Toolkit product page on ofastshop.com
 */
function ofast_toolkit_get_upgrade_url()
{
    return 'https://ofastshop.com/user/digital/wordpress-plugin/ofast-tooltik-pro/';
}

/**
 * Get the license management page URL (within the plugin admin).
 *
 * @return string Admin URL to the license settings page.
 */
function ofast_toolkit_get_license_page_url()
{
    return admin_url('admin.php?page=ofast-license');
}



// =========================================================================
// LICENSE API URL HELPER
// =========================================================================

/**
 * Get the license API base URL.
 *
 * Three ways to override (in priority order):
 *  1. wp-config.php:  define('OFAST_LICENSE_API_URL', 'https://...');
 *  2. Filter hook:    add_filter('ofast_license_api_url', fn() => 'https://...');
 *  3. Plugin update:  change the default in ofast-x.php
 *
 * @return string API base URL (no trailing slash).
 */
function ofast_toolkit_get_api_url()
{
    return untrailingslashit( apply_filters('ofast_license_api_url', OFAST_LICENSE_API_URL) );
}

// =========================================================================
// LICENSE ACTIVATION / VALIDATION (API Communication)
// =========================================================================

/**
 * Activate a license key against the remote server.
 *
 * @param string $license_key The license key entered by the user.
 * @return array ['success' => bool, 'message' => string]
 */
function ofast_toolkit_activate_license($license_key)
{
    $license_key = sanitize_text_field(trim($license_key));

    if (empty($license_key)) {
        return ['success' => false, 'message' => 'Please enter a license key.'];
    }

    // Security: Validate license key format before making any API call
    $license_key = strtoupper($license_key);
    if (!preg_match('/^OFAST-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $license_key)) {
        return ['success' => false, 'message' => 'Invalid license key format. Expected: OFAST-XXXX-XXXX-XXXX-XXXX'];
    }

    // Security: Client-side rate limiting (max 5 activation attempts per hour)
    $rate_key = 'ofast_lic_attempts_' . get_current_user_id();
    $attempts = (int) get_transient($rate_key);
    if ($attempts >= 5) {
        return ['success' => false, 'message' => 'Too many activation attempts. Please try again in 1 hour.'];
    }
    set_transient($rate_key, $attempts + 1, HOUR_IN_SECONDS);

    $response = wp_remote_post(ofast_toolkit_get_api_url() . '/activate', [
        'timeout'   => 15,
        'sslverify' => true,
        'headers'   => ['X-Ofast-Api-Secret' => OFAST_API_CLIENT_SECRET],
        'body'      => [
            'license_key' => $license_key,
            'domain'      => esc_url_raw(home_url()),
        ],
    ]);

    if (is_wp_error($response)) {
        return ['success' => false, 'message' => 'Could not connect to license server. Please try again.'];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $code = wp_remote_retrieve_response_code($response);

    if ($code === 200 && isset($body['status']) && $body['status'] === 'active') {
        update_option('ofast_license_key', $license_key);
        update_option('ofast_license_status', 'active');
        update_option('ofast_license_last_check', time());
        // Store server-generated signature (can't be forged — signed with server-only secret)
        if (!empty($body['signature'])) {
            update_option('ofast_license_signature', sanitize_text_field($body['signature']));
        }
        // Clear rate limit on success
        delete_transient($rate_key);
        return ['success' => true, 'message' => 'License activated successfully! Pro features are now unlocked.'];
    }

    $message = isset($body['message']) ? $body['message'] : 'Invalid license key or activation failed.';
    return ['success' => false, 'message' => $message];
}

/**
 * Deactivate the current license (frees up the site slot).
 *
 * @return array ['success' => bool, 'message' => string]
 */
function ofast_toolkit_deactivate_license()
{
    $license_key = get_option('ofast_license_key', '');

    if (empty($license_key)) {
        return ['success' => false, 'message' => 'No license key found.'];
    }

    $response = wp_remote_post(ofast_toolkit_get_api_url() . '/deactivate', [
        'timeout'   => 15,
        'sslverify' => true,
        'headers'   => ['X-Ofast-Api-Secret' => OFAST_API_CLIENT_SECRET],
        'body'      => [
            'license_key' => $license_key,
            'domain'      => esc_url_raw(home_url()),
        ],
    ]);

    // Clear ALL local license data regardless of API response
    delete_option('ofast_license_key');
    update_option('ofast_license_status', 'inactive');
    delete_option('ofast_license_last_check');
    delete_option('ofast_license_signature');

    if (is_wp_error($response)) {
        return ['success' => true, 'message' => 'License removed locally. Server may still show it as active.'];
    }

    return ['success' => true, 'message' => 'License deactivated successfully.'];
}

/**
 * Validate the license against the remote server (periodic check).
 * Called by WP-Cron monthly or on-demand.
 *
 * @return bool True if license is still valid.
 */
function ofast_toolkit_validate_license()
{
    $license_key = get_option('ofast_license_key', '');

    if (empty($license_key)) {
        update_option('ofast_license_status', 'inactive');
        return false;
    }

    $response = wp_remote_post(ofast_toolkit_get_api_url() . '/validate', [
        'timeout'   => 15,
        'sslverify' => true,
        'headers'   => ['X-Ofast-Api-Secret' => OFAST_API_CLIENT_SECRET],
        'body'      => [
            'license_key' => $license_key,
            'domain'      => esc_url_raw(home_url()),
        ],
    ]);

    if (is_wp_error($response)) {
        // On network error, check how long since last successful validation.
        // If it's been over 30 days, revoke — prevents permanent bypass via
        // hosts-file blocking. Legitimate users on flaky networks stay active.
        $last_success    = (int) get_option('ofast_license_last_check', 0);
        $max_offline_days = 30;

        if ($last_success && (time() - $last_success) > ($max_offline_days * DAY_IN_SECONDS)) {
            update_option('ofast_license_status', 'inactive');
            delete_option('ofast_license_signature');
            return false;
        }

        // Within offline tolerance — keep current status (don't lock user out)
        return (get_option('ofast_license_status', 'inactive') === 'active');
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($body['status']) && $body['status'] === 'active') {
        update_option('ofast_license_status', 'active');
        update_option('ofast_license_last_check', time());
        // Refresh server signature on each successful validation
        if (!empty($body['signature'])) {
            update_option('ofast_license_signature', sanitize_text_field($body['signature']));
        }
        return true;
    }

    // License expired, revoked, or invalid
    update_option('ofast_license_status', 'inactive');
    update_option('ofast_license_last_check', time());
    delete_option('ofast_license_signature');
    return false;
}

// =========================================================================
// CRON: Periodic license validation (monthly)
// =========================================================================

/**
 * Schedule the monthly license check cron event.
 */
function ofast_toolkit_schedule_license_check()
{
    if (!wp_next_scheduled('ofast_license_check_event')) {
        wp_schedule_event(time(), 'weekly', 'ofast_license_check_event');
    }
}
add_action('init', 'ofast_toolkit_schedule_license_check');

/**
 * Register a custom "weekly" cron interval (7 days).
 */
function ofast_toolkit_cron_schedules($schedules)
{
    $schedules['weekly'] = [
        'interval' => 7 * DAY_IN_SECONDS,
        'display'  => __('Once Weekly', 'ofast-x'),
    ];
    return $schedules;
}
add_filter('cron_schedules', 'ofast_toolkit_cron_schedules');

/**
 * Cron callback: validate the license.
 */
add_action('ofast_license_check_event', 'ofast_toolkit_validate_license');

// =========================================================================
// ADMIN: License page, AJAX handlers, admin notices
// =========================================================================

/**
 * Register the License admin page under Ofast Toolkit menu.
 */
function ofast_toolkit_register_license_page()
{
    add_submenu_page(
        'ofast-dashboard',
        'License',
        'License',
        'manage_options',
        'ofast-license',
        'ofast_toolkit_render_license_page'
    );
}
add_action('admin_menu', 'ofast_toolkit_register_license_page', 99);

/**
 * Enqueue CSS for the License page
 */
function ofast_toolkit_enqueue_license_assets($hook)
{
    if (strpos($hook, 'ofast-license') !== false || (isset($_GET['page']) && $_GET['page'] === 'ofast-license')) {
        wp_enqueue_style('ofast-admin-css', OFAST_X_PLUGIN_URL . 'assets/css/ofast-admin.css', array(), OFAST_X_VERSION);
    }
}
add_action('admin_enqueue_scripts', 'ofast_toolkit_enqueue_license_assets');

/**
 * Handle license activation/deactivation form submissions.
 */
function ofast_toolkit_handle_license_actions()
{
    if (!current_user_can('manage_options') || !isset($_POST['ofast_license_nonce'])) {
        return;
    }

    if (!wp_verify_nonce($_POST['ofast_license_nonce'], 'ofast_license_action')) {
        return;
    }

    if (isset($_POST['ofast_activate_license'])) {
        $result = ofast_toolkit_activate_license($_POST['ofast_license_key'] ?? '');
        set_transient('ofast_license_notice', $result, 30);
    }

    if (isset($_POST['ofast_deactivate_license'])) {
        $result = ofast_toolkit_deactivate_license();
        set_transient('ofast_license_notice', $result, 30);
    }

    wp_safe_redirect(admin_url('admin.php?page=ofast-license'));
    exit;
}
add_action('admin_init', 'ofast_toolkit_handle_license_actions');

/**
 * Render the License management page.
 */
function ofast_toolkit_render_license_page()
{
    $is_pro      = ofast_toolkit_is_pro();
    $license_key = get_option('ofast_license_key', '');
    $last_check  = get_option('ofast_license_last_check', 0);
    $notice      = get_transient('ofast_license_notice');

    if ($notice) {
        delete_transient('ofast_license_notice');
    }
    ?>


    <div class="wrap ofast-app-wrap">
        <header class="ofast-topbar">
            <div class="ofast-logo">
                <img src="<?php echo esc_url(OFAST_X_PLUGIN_URL . 'assets/images/toolkit-logo.png'); ?>" alt="Ofast Toolkit Logo" style="height: 40px; width: auto; object-fit: contain;" />
                <span>Ofast Toolkit</span>
            </div>
            <div class="header-actions">
                <a href="?page=ofast-setup-wizard" class="action-btn"><span class="dashicons dashicons-admin-tools"></span> Setup Wizard</a>
                <a href="https://toolkit.ofastshop.com/docs/index.html" target="_blank" class="action-btn"><span class="dashicons dashicons-book"></span> Documentation</a>
                <a href="#" class="action-btn">Quick Actions</a>
            </div>
        </header>

        <div class="ofast-app-layout">
            <aside class="ofast-sidebar">
                <nav class="ofast-nav">
                    <a href="?page=ofast-dashboard" class="nav-item">
                        <span class="dashicons dashicons-grid-view"></span> Dashboard
                    </a>
                    <div class="nav-section">SYSTEM</div>
                    <a href="#" class="nav-item"><span class="dashicons dashicons-database"></span> Data Management</a>
                    <a href="?page=ofast-license" class="nav-item active"><span class="dashicons dashicons-admin-network"></span> License</a>
                    <a href="?page=ofast-support" class="nav-item"><span class="dashicons dashicons-editor-help"></span> Help &amp; Support</a>
                </nav>
                <div class="ofast-pro-card">
                    <div class="pro-icon">🚀</div>
                    <h4>Unlock More Power</h4>
                    <p>Upgrade to Pro and get access to advanced features.</p>
                    <a href="https://toolkit.ofastshop.com/" target="_blank" class="upgrade-btn">Upgrade Now</a>
                </div>
            </aside>

            <main class="ofast-main">
                <div style="max-width: 700px; margin: 0 auto;">
                    <div style="text-align: center; margin-bottom: 30px;">
                        <h1 style="font-size: 28px; font-weight: 700; margin: 0 0 8px;">
                            <?php echo $is_pro ? '✅' : '🔑'; ?> Ofast Toolkit License
                        </h1>
                        <p style="color: #666; font-size: 15px; margin: 0;">
                            <?php echo $is_pro
                                ? 'Your Pro license is active. All premium features are unlocked.'
                                : 'Enter your license key to unlock all Pro features.'; ?>
                        </p>
                    </div>

                    <?php if ($notice): ?>
                        <div style="padding: 14px 20px; border-radius: 10px; margin-bottom: 20px; font-weight: 500;
                            background: <?php echo $notice['success'] ? '#ecfdf5' : '#fef2f2'; ?>;
                            color: <?php echo $notice['success'] ? '#065f46' : '#991b1b'; ?>;
                            border: 1px solid <?php echo $notice['success'] ? '#a7f3d0' : '#fecaca'; ?>;">
                            <?php echo esc_html($notice['message']); ?>
                        </div>
                    <?php endif; ?>

                    <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                        <?php if ($is_pro): ?>
                            <div style="text-align: center; padding: 20px 0;">
                                <div style="display: inline-block; background: linear-gradient(135deg, #10b981, #059669); color: #fff; padding: 12px 28px; border-radius: 50px; font-size: 15px; font-weight: 600; margin-bottom: 20px;">
                                    ● License Active
                                </div>
                                <div style="background: #f9fafb; border-radius: 12px; padding: 20px; margin: 20px 0; text-align: left;">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 12px;">
                                        <span style="color: #6b7280; font-size: 13px;">License Key</span>
                                        <code style="background: #e5e7eb; padding: 2px 10px; border-radius: 6px; font-size: 13px;">
                                            <?php echo esc_html(substr($license_key, 0, 10) . '••••••••••••'); ?>
                                        </code>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 12px;">
                                        <span style="color: #6b7280; font-size: 13px;">Status</span>
                                        <span style="color: #059669; font-weight: 600; font-size: 13px;">Active</span>
                                    </div>
                                    <div style="display: flex; justify-content: space-between;">
                                        <span style="color: #6b7280; font-size: 13px;">Last Verified</span>
                                        <span style="font-size: 13px;"><?php echo $last_check ? human_time_diff($last_check) . ' ago' : 'Never'; ?></span>
                                    </div>
                                </div>
                                <form method="post" action="" style="margin-top: 20px;">
                                    <?php wp_nonce_field('ofast_license_action', 'ofast_license_nonce'); ?>
                                    <button type="submit" name="ofast_deactivate_license" value="1"
                                        style="background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; padding: 10px 24px; border-radius: 10px; cursor: pointer; font-size: 14px; font-weight: 500;"
                                        onclick="return confirm('Deactivate this license? You can reactivate it on another site.');">
                                        Deactivate License
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <form method="post" action="">
                                <?php wp_nonce_field('ofast_license_action', 'ofast_license_nonce'); ?>
                                <label style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 8px; color: #374151;">
                                    License Key
                                </label>
                                <input type="text" name="ofast_license_key" placeholder="OFAST-XXXX-XXXX-XXXX-XXXX"
                                    value="<?php echo esc_attr($license_key); ?>"
                                    style="width: 100%; padding: 14px 16px; border: 2px solid #e5e7eb; border-radius: 12px; font-size: 15px; font-family: monospace; outline: none; transition: border-color 0.2s; box-sizing: border-box;"
                                    onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#e5e7eb'"
                                    required />
                                <p style="color: #9ca3af; font-size: 12px; margin: 8px 0 24px;">
                                    Enter the license key you received after purchasing on ofastshop.com/user
                                </p>
                                <button type="submit" name="ofast_activate_license" value="1"
                                    style="width: 100%; background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; padding: 14px; border: none; border-radius: 12px; font-size: 16px; font-weight: 600; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; box-shadow: 0 4px 14px rgba(99,102,241,0.35);"
                                    onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 6px 20px rgba(99,102,241,0.45)'"
                                    onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 14px rgba(99,102,241,0.35)'">
                                    Activate License
                                </button>
                            </form>
                            <div style="text-align: center; margin-top: 24px; padding-top: 20px; border-top: 1px solid #f3f4f6;">
                                <p style="color: #9ca3af; font-size: 13px; margin: 0 0 8px;">Don't have a license key?</p>
                                <a href="<?php echo esc_url(ofast_toolkit_get_upgrade_url()); ?>" target="_blank"
                                    style="color: #6366f1; font-weight: 600; text-decoration: none; font-size: 14px;">
                                    Get Ofast Toolkit Pro →
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <?php
}

/**
 * Show admin notice if license is not active.
 */
function ofast_toolkit_admin_license_notice()
{
    // Only show on Ofast Toolkit pages
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'ofast') === false) {
        return;
    }

    // Don't show on the license page itself
    if (isset($_GET['page']) && $_GET['page'] === 'ofast-license') {
        return;
    }

    if (!ofast_toolkit_is_pro()) {
        echo '<div class="notice notice-info is-dismissible" style="border-left-color: #6366f1; padding: 12px 16px;">';
        echo '<p><strong>🔑 Ofast Toolkit Free</strong> — ';
        echo '<a href="' . esc_url(ofast_toolkit_get_license_page_url()) . '">Enter your license key</a> to unlock all Pro features, or ';
        echo '<a href="' . esc_url(ofast_toolkit_get_upgrade_url()) . '" target="_blank">purchase a license</a>.</p>';
        echo '</div>';
    }
}
add_action('admin_notices', 'ofast_toolkit_admin_license_notice');
