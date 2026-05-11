<?php

/**
 * Ofast X - White Label Module
 * Dashboard widgets showing administrator users and designer details
 * Also handles custom admin footer text customization
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Whos_Admin
{
    private const PAGE_PROTECTION_COOKIE_PREFIX = 'ofast_page_protection_';
    private const PAGE_PROTECTION_TIMEOUT_DEFAULT = 30;
    private const PAGE_PROTECTION_TIMEOUT_MIN = 5;
    private const PAGE_PROTECTION_TIMEOUT_MAX = 480;

    /**
     * @var Ofast_X_Menu_Editor|null Menu editor instance for embedding.
     */
    private $menu_editor = null;

    /**
     * Initialize module
     */
    public function init()
    {
        // NOTE: Module enabled check removed - core loader already verified this
        // before calling init(). See class-ofast-core.php is_module_enabled()

        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        // Add dashboard widgets
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widgets'));

        // Add settings page
        add_action('admin_menu', array($this, 'add_settings_menu'));
        add_action('admin_init', array($this, 'handle_settings_save'));

        // Override admin footer text (from Admin Footer module)
        add_filter('admin_footer_text', array($this, 'custom_footer_left'), 999);
        add_filter('update_footer', array($this, 'custom_footer_right'), 999);

        // Disable plugin updates if enabled
        if (get_option('ofast_disable_plugin_updates', 0)) {
            add_filter('site_transient_update_plugins', array($this, 'disable_plugin_updates'));
            add_filter('pre_set_site_transient_update_plugins', array($this, 'disable_plugin_updates'));
        }

        // Admin page protection
        if (get_option('ofast_page_protection_enabled', 0)) {
            add_action('admin_init', array($this, 'protect_admin_pages'), 1);
        }

        add_action('wp_logout', array($this, 'clear_protection_cookie'));
    }

    /**
     * Set the menu editor instance for embedding in the Updates tab.
     *
     * @param Ofast_X_Menu_Editor $menu_editor
     */
    public function set_menu_editor($menu_editor)
    {
        $this->menu_editor = $menu_editor;
    }

    /**
     * Enqueue reusable UI assets for the White Label page.
     *
     * @param string $hook Admin page hook.
     */
    public function enqueue_assets($hook)
    {
        if (strpos($hook, 'ofast-white-label') === false) {
            return;
        }

        wp_enqueue_style(
            'ofast-tabs',
            plugins_url('assets/ofast-tabs.css', __FILE__),
            array(),
            OFAST_X_VERSION
        );

        wp_enqueue_script(
            'ofast-tabs',
            plugins_url('assets/ofast-tabs.js', __FILE__),
            array('jquery'),
            OFAST_X_VERSION,
            true
        );
    }

    /**
     * Add dashboard widgets
     */
    public function add_dashboard_widgets()
    {
        // Administrator widget
        wp_add_dashboard_widget(
            'ofast_admin_users_widget',
            'Administrator',
            array($this, 'render_admin_users_widget')
        );

        // Designer Details widget
        wp_add_dashboard_widget(
            'ofast_designer_details_widget',
            'Designer Details',
            array($this, 'render_designer_widget')
        );
    }

    /**
     * Render Administrator Users Widget
     */
    public function render_admin_users_widget()
    {
        $args = array(
            'role' => 'administrator',
            'orderby' => 'registered',
            'order' => 'DESC',
        );

        $admin_users = get_users($args);

        if ($admin_users) {
            foreach ($admin_users as $admin_user) {
                $first_name = $admin_user->first_name;
                $last_name = $admin_user->last_name;
                $email = $admin_user->user_email;
                $full_name = trim($first_name . ' ' . $last_name) ?: $admin_user->user_login;
                $site_logo_url = get_site_icon_url(32);

                echo '<table style="width: 100%; margin-bottom: 20px; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;">';

                echo '<tr style="background: #f9f9f9;"><th style="text-align: left; width: 120px; padding: 10px; font-weight: 600;">Name</th>
                          <td style="padding: 10px;">' . esc_html($full_name) . '</td></tr>';

                echo '<tr><th style="text-align: left; padding: 10px; font-weight: 600;">Email</th>
                          <td style="padding: 10px;"><a href="mailto:' . esc_attr($email) . '" style="color: #1e88e5; text-decoration: none;">' . esc_html($email) . '</a></td></tr>';

                echo '<tr style="background: #f9f9f9;"><th style="text-align: left; padding: 10px; font-weight: 600;">Site Logo</th>
                          <td style="padding: 10px;">';
                if ($site_logo_url) {
                    echo '<img src="' . esc_url($site_logo_url) . '" alt="Site Logo" width="32" height="32" style="border-radius: 4px;">';
                } else {
                    echo '<span style="color: #999;">No Logo Set</span>';
                }
                echo '</td></tr>';

                echo '</table>';
            }
        } else {
            echo '<p style="color: #999;">No admin users found.</p>';
        }
    }

    /**
     * Render Designer Details Widget
     */
    public function render_designer_widget()
    {
        $name = get_option('ofast_designer_name', 'Your Name');
        $email = get_option('ofast_designer_email', 'hello@example.com');
        $website = get_option('ofast_designer_website', 'https://example.com');

        echo '<div style="padding: 10px;">';
        echo '<p style="margin: 8px 0;"><strong>Designer:</strong> ' . esc_html($name) . '</p>';
        echo '<p style="margin: 8px 0;"><strong>Email:</strong> <a href="mailto:' . esc_attr($email) . '" style="color: #1e88e5; text-decoration: none;">' . esc_html($email) . '</a></p>';
        echo '<p style="margin: 8px 0;"><strong>Website:</strong> <a href="' . esc_url($website) . '" target="_blank" style="color: #1e88e5; text-decoration: none;">' . esc_html($website) . '</a></p>';
        echo '<hr style="margin: 15px 0; border: none; border-top: 1px solid #eee;">';
        echo '</div>';
    }

    /**
     * Add settings submenu
     */
    public function add_settings_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'White Label Settings',
            'White Label',
            'manage_options',
            'ofast-white-label',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Handle settings save
     */
    public function handle_settings_save()
    {
        // Handle Reset All
        if (isset($_POST['ofast_white_label_reset'])) {
            if (!wp_verify_nonce($_POST['_wpnonce'], 'ofast_white_label_settings')) {
                wp_die('Security check failed');
            }
            if (!current_user_can('manage_options')) {
                wp_die('Insufficient permissions');
            }

            // Pro guard: White Label is a Pro-only module
            if ( ! ofast_toolkit_is_pro() ) {
                return;
            }

            // Reset all White Label settings
            delete_option('ofast_designer_name');
            delete_option('ofast_designer_email');
            delete_option('ofast_designer_website');
            delete_option('ofast_admin_footer_settings');
            delete_option('ofast_disable_plugin_updates');
            delete_option('ofast_disabled_plugins_list');
            delete_option('ofast_page_protection_enabled');
            delete_option('ofast_super_admin_username');
            delete_option('ofast_protection_password');
            delete_option('ofast_page_protection_timeout');
            delete_option('ofast_protected_pages_list');
            delete_site_transient('update_plugins');
            $this->clear_protection_cookie();

            // Reset menu editor settings
            if ($this->menu_editor) {
                $this->menu_editor->reset_settings();
            }

            $active_tab = isset($_POST['white_label_active_tab']) ? sanitize_key(wp_unslash($_POST['white_label_active_tab'])) : 'designer_details';
            if (!in_array($active_tab, array('designer_details', 'footer', 'updates'), true)) {
                $active_tab = 'designer_details';
            }

            wp_redirect(add_query_arg(array(
                'settings_reset' => '1',
                'tab' => $active_tab,
            ), admin_url('admin.php?page=ofast-white-label')));
            exit;
        }

        if (!isset($_POST['ofast_white_label_save'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['_wpnonce'], 'ofast_white_label_settings')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        // Pro guard: White Label is a Pro-only module
        if ( ! ofast_toolkit_is_pro() ) {
            return;
        }

        // Save Designer Details
        update_option('ofast_designer_name', sanitize_text_field($_POST['designer_name']));
        update_option('ofast_designer_email', sanitize_email($_POST['designer_email']));
        update_option('ofast_designer_website', esc_url_raw($_POST['designer_website']));

        // Save Footer Settings (migrated from Admin Footer module)
        $footer_settings = array(
            'left_text' => wp_kses_post($_POST['footer_left_text'] ?? ''),
            'right_text' => sanitize_text_field($_POST['footer_right_text'] ?? ''),
            'hide_wp_version' => isset($_POST['hide_wp_version']) ? 1 : 0,
            'enable_dark_mode' => isset($_POST['enable_dark_mode']) ? 1 : 0,
            'enable_custom_dashboard' => isset($_POST['enable_custom_dashboard']) ? 1 : 0,
        );
        update_option('ofast_admin_footer_settings', $footer_settings);

        // Save Updates Settings
        update_option('ofast_disable_plugin_updates', isset($_POST['ofast_disable_plugin_updates']) ? 1 : 0);

        // Save the list of plugins to disable updates for
        $disabled_plugins = array();
        if (!empty($_POST['ofast_disabled_plugins']) && is_array($_POST['ofast_disabled_plugins'])) {
            $disabled_plugins = array_map('sanitize_text_field', $_POST['ofast_disabled_plugins']);
        }
        update_option('ofast_disabled_plugins_list', $disabled_plugins);

        // Clear the update plugins transient so changes take effect immediately
        delete_site_transient('update_plugins');

        // Save Page Protection Settings
        update_option('ofast_page_protection_enabled', isset($_POST['ofast_page_protection_enabled']) ? 1 : 0);

        if (!empty($_POST['ofast_super_admin_username'])) {
            update_option('ofast_super_admin_username', sanitize_user(wp_unslash($_POST['ofast_super_admin_username'])));
        }

        // Only update password if a new one was entered
        if (!empty($_POST['ofast_protection_password'])) {
            update_option('ofast_protection_password', wp_hash_password((string) wp_unslash($_POST['ofast_protection_password'])));
        }

        $timeout_minutes = isset($_POST['ofast_page_protection_timeout']) ? absint(wp_unslash($_POST['ofast_page_protection_timeout'])) : self::PAGE_PROTECTION_TIMEOUT_DEFAULT;
        $timeout_minutes = min(max($timeout_minutes, self::PAGE_PROTECTION_TIMEOUT_MIN), self::PAGE_PROTECTION_TIMEOUT_MAX);
        update_option('ofast_page_protection_timeout', $timeout_minutes);

        $protected_pages = array();
        if (!empty($_POST['ofast_protected_pages']) && is_array($_POST['ofast_protected_pages'])) {
            $protected_pages = array_map('sanitize_text_field', $_POST['ofast_protected_pages']);
        }
        update_option('ofast_protected_pages_list', $protected_pages);
        $this->clear_protection_cookie();

        // Save Menu Editor settings (menu_items are part of the global form now)
        if ($this->menu_editor && isset($_POST['menu_items'])) {
            $this->menu_editor->save_settings($_POST);
        }

        $active_tab = isset($_POST['white_label_active_tab']) ? sanitize_key(wp_unslash($_POST['white_label_active_tab'])) : 'designer_details';
        if (!in_array($active_tab, array('designer_details', 'footer', 'updates'), true)) {
            $active_tab = 'designer_details';
        }

        wp_redirect(add_query_arg(array(
            'settings_saved' => '1',
            'tab' => $active_tab,
        ), admin_url('admin.php?page=ofast-white-label')));
        exit;
    }

    /**
     * Custom left footer text
     */
    public function custom_footer_left($text)
    {
        $settings = get_option('ofast_admin_footer_settings', array());

        if (!empty($settings['left_text'])) {
            $footer_text = $settings['left_text'];

            // Replace shortcuts
            $footer_text = $this->replace_shortcuts($footer_text);

            return wp_kses_post($footer_text);
        }

        return $text;
    }

    /**
     * Replace shortcuts with actual values
     */
    private function replace_shortcuts($text)
    {
        $replacements = array(
            '{site_name}' => get_bloginfo('name'),
            '{year}' => date('Y'),
            '{admin_email}' => get_option('admin_email'),
        );

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    /**
     * Custom right footer text (WP version area)
     */
    public function custom_footer_right($text)
    {
        $settings = get_option('ofast_admin_footer_settings', array());

        // Hide WP version if selected
        if (!empty($settings['hide_wp_version'])) {
            $text = '';
        }

        if (!empty($settings['right_text'])) {
            return esc_html($settings['right_text']);
        }

        return $text;
    }

    /**
     * Disable plugin updates for user-selected plugins
     */
    public function disable_plugin_updates($value)
    {
        if (!is_object($value)) {
            return $value;
        }

        $pluginsToDisable = get_option('ofast_disabled_plugins_list', array());

        if (!is_array($pluginsToDisable) || empty($pluginsToDisable)) {
            return $value;
        }

        foreach ($pluginsToDisable as $plugin) {
            if (isset($value->response[$plugin])) {
                unset($value->response[$plugin]);
            }
            if (isset($value->no_update[$plugin])) {
                unset($value->no_update[$plugin]);
            }
            if (isset($value->checked[$plugin])) {
                unset($value->checked[$plugin]);
            }
        }

        return $value;
    }

    /**
     * Protect admin pages from non-super-admin users
     * Shows a password form when accessing protected pages
     */
    public function protect_admin_pages()
    {
        $current_user = wp_get_current_user();
        $super_admin_username = get_option('ofast_super_admin_username', '');

        // Super admin bypasses all protection
        if ($current_user->user_login === $super_admin_username) {
            return;
        }

        // Get protected pages
        $protected_pages = get_option('ofast_protected_pages_list', array());
        if (empty($protected_pages) || !is_array($protected_pages)) {
            return;
        }

        // Get stored password hash
        $stored_password_hash = get_option('ofast_protection_password', '');
        if (empty($stored_password_hash)) {
            return; // No password set, skip protection
        }

        // Build the current page identifier
        global $pagenow;
        $current_page = $pagenow;

        if (isset($_GET['post_type'])) {
            $current_page = $pagenow . '?post_type=' . sanitize_text_field($_GET['post_type']);
        }

        if (isset($_GET['page'])) {
            $current_page = $pagenow . '?page=' . sanitize_text_field($_GET['page']);
        }

        // Check if current page matches any protected page
        $is_protected = false;
        foreach ($protected_pages as $protected) {
            // Exact match
            if ($current_page === $protected || $pagenow === $protected) {
                $is_protected = true;
                break;
            }
            // Also check if protected page has query params and current page starts with it
            if (strpos($protected, '?') !== false) {
                $protected_base = explode('?', $protected)[0];
                parse_str(explode('?', $protected)[1], $protected_params);
                if ($pagenow === $protected_base) {
                    $match = true;
                    foreach ($protected_params as $key => $val) {
                        if (!isset($_GET[$key]) || $_GET[$key] !== $val) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        $is_protected = true;
                        break;
                    }
                }
            }
        }

        if (!$is_protected) {
            return;
        }

        if ($this->has_valid_protection_cookie($current_user, $stored_password_hash, $protected_pages)) {
            return;
        }

        // Handle password submission
        if (isset($_POST['ofast_protected_page_password']) && isset($_POST['ofast_protected_page_access'])) {
            if (!isset($_POST['_ofast_protection_nonce']) || !wp_verify_nonce($_POST['_ofast_protection_nonce'], 'ofast_page_protection')) {
                $error_message = __('Security check failed. Please try again.', 'ofast-x');
            } else {
                $entered_password = (string) wp_unslash($_POST['ofast_protected_page_password']);

                if (wp_check_password($entered_password, $stored_password_hash)) {
                    $current_url = $this->get_current_admin_url();
                    $this->set_protection_cookie($current_user, $stored_password_hash, $protected_pages);
                    wp_safe_redirect($current_url);
                    exit;
                }

                $error_message = __('Incorrect password! Try again.', 'ofast-x');
            }
        }

        // Show password form
        $dashboard_url = admin_url();
        $current_url = $this->get_current_admin_url();
        $nonce = wp_create_nonce('ofast_page_protection');

        $error_html = '';
        if (isset($error_message)) {
            $error_html = '<div style="background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-weight: 500; font-size: 14px;">' . esc_html($error_message) . '</div>';
        }

        wp_die('
            <div style="max-width: 420px; margin: 80px auto; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">
                <div style="background: #fff; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,0.12); overflow: hidden;">
                    <div style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); padding: 28px 30px; text-align: center;">
                        <div style="width: 56px; height: 56px; background: rgba(255,255,255,0.2); border-radius: 14px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px;">
                            <span style="font-size: 28px;">&#128274;</span>
                        </div>
                        <h2 style="margin: 0; color: #fff; font-size: 22px; font-weight: 700;">Protected Area</h2>
                        <p style="margin: 6px 0 0; color: rgba(255,255,255,0.8); font-size: 14px;">This section requires authentication</p>
                    </div>
                    <div style="padding: 28px 30px;">
                        ' . $error_html . '
                        <form method="post" action="' . esc_url($current_url) . '">
                            <input type="hidden" name="ofast_protected_page_access" value="1">
                            <input type="hidden" name="_ofast_protection_nonce" value="' . esc_attr($nonce) . '">
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; font-weight: 600; color: #374151; margin-bottom: 6px; font-size: 14px;">Password</label>
                                <input type="password" name="ofast_protected_page_password"
                                    placeholder="Enter protection password"
                                    autocomplete="current-password"
                                    style="width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 15px; background: #f8fafc; transition: all 0.2s; box-sizing: border-box; outline: none;"
                                    onfocus="this.style.borderColor=\'#6366f1\'; this.style.background=\'#fff\'; this.style.boxShadow=\'0 0 0 4px rgba(99,102,241,0.1)\';"
                                    onblur="this.style.borderColor=\'#e2e8f0\'; this.style.background=\'#f8fafc\'; this.style.boxShadow=\'none\';"
                                    required autofocus>
                            </div>
                            <button type="submit"
                                style="width: 100%; padding: 12px; background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); color: #fff; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 12px rgba(99,102,241,0.3); transition: all 0.2s;"
                                onmouseover="this.style.transform=\'translateY(-1px)\'; this.style.boxShadow=\'0 6px 16px rgba(99,102,241,0.4)\';"
                                onmouseout="this.style.transform=\'none\'; this.style.boxShadow=\'0 4px 12px rgba(99,102,241,0.3)\';">
                                Unlock Access
                            </button>
                        </form>
                        <a href="' . esc_url($dashboard_url) . '"
                            style="display: block; text-align: center; margin-top: 14px; padding: 10px; background: #f1f5f9; color: #475569; text-decoration: none; border-radius: 10px; font-weight: 500; font-size: 14px; transition: all 0.2s;"
                            onmouseover="this.style.background=\'#e2e8f0\';"
                            onmouseout="this.style.background=\'#f1f5f9\';">
                            &larr; Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>
        ', esc_html__('Protected Area - Authentication Required', 'ofast-x'), array('response' => 403));
    }

    private function get_page_protection_timeout_minutes()
    {
        $minutes = absint(get_option('ofast_page_protection_timeout', self::PAGE_PROTECTION_TIMEOUT_DEFAULT));
        return min(max($minutes, self::PAGE_PROTECTION_TIMEOUT_MIN), self::PAGE_PROTECTION_TIMEOUT_MAX);
    }

    private function get_current_admin_url()
    {
        global $pagenow;

        $query_args = array();
        foreach ($_GET as $key => $value) {
            if (is_scalar($value)) {
                $query_args[$key] = sanitize_text_field(wp_unslash($value));
            }
        }

        return add_query_arg($query_args, admin_url($pagenow));
    }

    private function get_protection_cookie_name($user_id = 0)
    {
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        $hash = defined('COOKIEHASH') && COOKIEHASH ? COOKIEHASH : md5(site_url());
        return self::PAGE_PROTECTION_COOKIE_PREFIX . $hash . '_' . $user_id;
    }

    private function get_protection_cookie_signature($user_id, $expires, $stored_password_hash, $protected_pages)
    {
        $session_token = function_exists('wp_get_session_token') ? (string) wp_get_session_token() : '';
        $pages_hash = md5(wp_json_encode(array_values((array) $protected_pages)));
        $payload = implode('|', array((int) $user_id, (int) $expires, $session_token, $stored_password_hash, $pages_hash));

        return hash_hmac('sha256', $payload, wp_salt('auth'));
    }

    private function has_valid_protection_cookie($current_user, $stored_password_hash, $protected_pages)
    {
        $cookie_name = $this->get_protection_cookie_name($current_user->ID);
        if (empty($_COOKIE[$cookie_name])) {
            return false;
        }

        $parts = explode('|', (string) wp_unslash($_COOKIE[$cookie_name]));
        if (count($parts) !== 3) {
            $this->clear_protection_cookie($current_user->ID);
            return false;
        }

        $cookie_user_id = absint($parts[0]);
        $expires = absint($parts[1]);
        $signature = (string) $parts[2];

        if ($cookie_user_id !== (int) $current_user->ID || $expires <= time()) {
            $this->clear_protection_cookie($current_user->ID);
            return false;
        }

        $expected_signature = $this->get_protection_cookie_signature($cookie_user_id, $expires, $stored_password_hash, $protected_pages);
        if (!hash_equals($expected_signature, $signature)) {
            $this->clear_protection_cookie($current_user->ID);
            return false;
        }

        return true;
    }

    private function set_protection_cookie($current_user, $stored_password_hash, $protected_pages)
    {
        $expires = time() + ($this->get_page_protection_timeout_minutes() * MINUTE_IN_SECONDS);
        $signature = $this->get_protection_cookie_signature($current_user->ID, $expires, $stored_password_hash, $protected_pages);
        $value = implode('|', array((int) $current_user->ID, (int) $expires, $signature));

        $this->send_protection_cookie($this->get_protection_cookie_name($current_user->ID), $value, $expires);
    }

    public function clear_protection_cookie($user_id = 0)
    {
        $cookie_name = $this->get_protection_cookie_name($user_id);

        if (isset($_COOKIE[$cookie_name])) {
            unset($_COOKIE[$cookie_name]);
        }

        $this->send_protection_cookie($cookie_name, 'expired', time() - HOUR_IN_SECONDS);
    }

    private function send_protection_cookie($name, $value, $expires)
    {
        $path = defined('ADMIN_COOKIE_PATH') && ADMIN_COOKIE_PATH ? ADMIN_COOKIE_PATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        $secure = is_ssl();
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
     * Render settings page
     */
    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        // Pro gate — show upgrade notice for free users
        if (!ofast_toolkit_is_pro()) {
            ?>
            <div class="wrap">
                <div style="max-width:700px;margin:40px auto;background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,0.10);overflow:hidden;">
                    <div style="background:linear-gradient(135deg,#6366f1 0%,#4f46e5 100%);padding:36px 40px;text-align:center;">
                        <span class="dashicons dashicons-lock" style="font-size:48px;width:48px;height:48px;color:#fff;opacity:.9;"></span>
                        <h2 style="margin:16px 0 8px;color:#fff;font-size:26px;font-weight:700;">White Label — Premium Feature</h2>
                        <p style="margin:0;color:rgba(255,255,255,.85);font-size:15px;">Customize your brand identity, admin footer, and more.</p>
                    </div>
                    <div style="padding:36px 40px;">
                        <ul style="list-style:none;margin:0 0 28px;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <?php foreach ([
                                ['dashicons-businessperson', 'Designer Details branding'],
                                ['dashicons-editor-kitchensink', 'Custom admin footer text'],
                                ['dashicons-update', 'Plugin update control'],
                                ['dashicons-lock', 'Admin page protection'],
                                ['dashicons-menu-alt3', 'Menu editor'],
                                ['dashicons-admin-generic', 'Dark/Light mode toggle'],
                            ] as $item): ?>
                            <li style="display:flex;align-items:center;gap:10px;background:#f8fafc;border-radius:10px;padding:12px 14px;">
                                <span class="dashicons <?php echo esc_attr($item[0]); ?>" style="color:#6366f1;font-size:18px;width:18px;height:18px;"></span>
                                <span style="font-size:13px;font-weight:500;color:#374151;"><?php echo esc_html($item[1]); ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <div style="text-align:center;">
                            <a href="<?php echo esc_url(ofast_toolkit_get_upgrade_url()); ?>" target="_blank"
                               style="display:inline-block;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;padding:14px 36px;border-radius:30px;font-weight:600;font-size:15px;text-decoration:none;box-shadow:0 4px 14px rgba(99,102,241,.35);transition:all .2s;">
                                Upgrade to Pro &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            return;
        }

        $name = get_option('ofast_designer_name', '');
        $email = get_option('ofast_designer_email', '');
        $website = get_option('ofast_designer_website', '');

        // Footer settings
        $footer_settings = get_option('ofast_admin_footer_settings', array(
            'left_text' => '',
            'right_text' => '',
            'hide_wp_version' => 0,
        ));

        $saved = isset($_GET['settings_saved']);
        $was_reset = isset($_GET['settings_reset']);
        $default_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'designer_details';
        if (!in_array($default_tab, array('designer_details', 'footer', 'updates'), true)) {
            $default_tab = 'designer_details';
        }

        ?>
        <div class="wrap ofast-white-label-wrap">
            <!-- Modern Header with Gradient -->
            <div class="ofast-page-header">
                <div class="ofast-header-content">
                    <div class="ofast-header-icon">
                        <span class="dashicons dashicons-id-alt"></span>
                    </div>
                    <div class="ofast-header-text">
                        <h1>White Label</h1>
                        <p>Customize designer details, admin footer branding, and future white label settings</p>
                    </div>
                </div>
            </div>

            <?php if ($saved): ?>
                <?php Ofast_X_Toast::add('White Label settings saved successfully!', 'success'); ?>
            <?php endif; ?>
            <?php if ($was_reset): ?>
                <?php Ofast_X_Toast::add('All settings have been reset to defaults!', 'success'); ?>
            <?php endif; ?>

            <!-- Strip toast query params so they don't persist on refresh -->
            <script>
                (function () {
                    var url = new URL(window.location);
                    if (url.searchParams.has('settings_saved') || url.searchParams.has('settings_reset')) {
                        url.searchParams.delete('settings_saved');
                        url.searchParams.delete('settings_reset');
                        window.history.replaceState({}, '', url.toString());
                    }
                })();
            </script>

            <form method="post" action="" class="ofast-modern-form">
                <?php wp_nonce_field('ofast_white_label_settings', '_wpnonce'); ?>
                <input type="hidden" name="white_label_active_tab" value="<?php echo esc_attr($default_tab); ?>"
                    class="ofast-active-tab">

                <div class="ofast-tabs-shell">
                    <nav class="ofast-tabs-nav" aria-label="<?php esc_attr_e('White Label sections', 'ofast-x'); ?>">
                        <button type="button"
                            class="ofast-tab <?php echo $default_tab === 'designer_details' ? 'active' : ''; ?>"
                            data-tab="designer_details">
                            <span class="dashicons dashicons-businessperson"></span>
                            <?php esc_html_e('Designer Details', 'ofast-x'); ?>
                        </button>
                        <button type="button" class="ofast-tab <?php echo $default_tab === 'footer' ? 'active' : ''; ?>"
                            data-tab="footer">
                            <span class="dashicons dashicons-editor-kitchensink"></span>
                            <?php esc_html_e('Footer', 'ofast-x'); ?>
                        </button>
                        <button type="button" class="ofast-tab <?php echo $default_tab === 'updates' ? 'active' : ''; ?>"
                            data-tab="updates">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e('Updates', 'ofast-x'); ?>
                        </button>
                    </nav>

                    <div class="ofast-tab-content<?php echo $default_tab === 'designer_details' ? ' active' : ''; ?>"
                        data-tab-panel="designer_details">

                        <div class="ofast-content-grid">
                            <div class="ofast-card ofast-main-card">
                                <div class="ofast-card-header">
                                    <span class="dashicons dashicons-admin-users"></span>
                                    <h2>Designer Details</h2>
                                </div>
                                <div class="ofast-card-body">
                                    <div class="ofast-form-group">
                                        <label for="designer_name">
                                            <span class="dashicons dashicons-businessperson"></span>
                                            Designer Name
                                        </label>
                                        <input type="text" name="designer_name" id="designer_name"
                                            value="<?php echo esc_attr($name); ?>" placeholder="John Doe or Acme Studios">
                                        <span class="ofast-field-hint">Your full name or company name</span>
                                    </div>

                                    <div class="ofast-form-group">
                                        <label for="designer_email">
                                            <span class="dashicons dashicons-email"></span>
                                            Email Address
                                        </label>
                                        <input type="email" name="designer_email" id="designer_email"
                                            value="<?php echo esc_attr($email); ?>" placeholder="hello@example.com">
                                        <span class="ofast-field-hint">Contact email for support inquiries</span>
                                    </div>

                                    <div class="ofast-form-group">
                                        <label for="designer_website">
                                            <span class="dashicons dashicons-admin-site-alt3"></span>
                                            Website URL
                                        </label>
                                        <input type="url" name="designer_website" id="designer_website"
                                            value="<?php echo esc_attr($website); ?>" placeholder="https://example.com">
                                        <span class="ofast-field-hint">Your portfolio or business website</span>
                                    </div>
                                </div>
                            </div>

                            <div class="ofast-card ofast-preview-card">
                                <div class="ofast-card-header">
                                    <span class="dashicons dashicons-visibility"></span>
                                    <h2>Designer Preview</h2>
                                </div>
                                <div class="ofast-card-body">
                                    <div class="ofast-preview-widget">
                                        <div class="ofast-preview-item">
                                            <span class="ofast-preview-label">Designer</span>
                                            <span class="ofast-preview-value"
                                                id="preview-name"><?php echo esc_html($name ?: 'Your Name'); ?></span>
                                        </div>
                                        <div class="ofast-preview-item">
                                            <span class="ofast-preview-label">Email</span>
                                            <a href="mailto:<?php echo esc_attr($email); ?>"
                                                class="ofast-preview-value ofast-link" id="preview-email">
                                                <?php echo esc_html($email ?: 'hello@example.com'); ?>
                                            </a>
                                        </div>
                                        <div class="ofast-preview-item">
                                            <span class="ofast-preview-label">Website</span>
                                            <a href="<?php echo esc_url($website); ?>" target="_blank"
                                                class="ofast-preview-value ofast-link" id="preview-website">
                                                <?php echo esc_html($website ?: 'https://example.com'); ?>
                                            </a>
                                        </div>
                                    </div>
                                    <p class="ofast-preview-note">
                                        <span class="dashicons dashicons-info-outline"></span>
                                        This is how your details appear in the dashboard widget
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="ofast-form-actions"
                            style="margin-top: 30px; display: flex; gap: 12px; align-items: center;">
                            <button type="submit" name="ofast_white_label_save" class="ofast-btn-primary">
                                Save Settings
                            </button>
                            <button type="submit" name="ofast_white_label_reset" class="ofast-btn-reset"
                                onclick="return confirm('Reset ALL White Label settings to defaults?');">
                                Reset to Default
                            </button>
                        </div>
                    </div>

                    <div class="ofast-tab-content<?php echo $default_tab === 'footer' ? ' active' : ''; ?>"
                        data-tab-panel="footer">

                        <div class="ofast-content-grid">
                            <div class="ofast-card ofast-main-card">
                                <div class="ofast-card-header">
                                    <span class="dashicons dashicons-editor-kitchensink"></span>
                                    <h2>Admin Footer</h2>
                                </div>
                                <div class="ofast-card-body">
                                    <div class="ofast-form-group">
                                        <label for="footer_left_text">
                                            Left Footer Text
                                            <span class="ofast-tooltip"
                                                title="Replaces 'Thank you for creating with WordPress.' HTML is allowed.">
                                                <span class="dashicons dashicons-info-outline"></span>
                                            </span>
                                        </label>
                                        <textarea name="footer_left_text" id="footer_left_text" rows="3"
                                            placeholder="e.g., Designed by Your Company | Contact: info@example.com"><?php echo esc_textarea($footer_settings['left_text'] ?? ''); ?></textarea>
                                        <span class="ofast-field-hint">
                                            Available shortcuts: <code>{site_name}</code> <code>{year}</code>
                                            <code>{admin_email}</code>
                                        </span>
                                    </div>

                                    <div class="ofast-form-group">
                                        <label for="footer_right_text">
                                            Right Footer Text
                                            <span class="ofast-tooltip"
                                                title="Replaces the WordPress version number on the right side.">
                                                <span class="dashicons dashicons-info-outline"></span>
                                            </span>
                                        </label>
                                        <input type="text" name="footer_right_text" id="footer_right_text"
                                            value="<?php echo esc_attr($footer_settings['right_text'] ?? ''); ?>"
                                            placeholder="e.g., v1.0.0">
                                        <span class="ofast-field-hint">Custom text for the right footer area</span>
                                    </div>

                                    <div class="ofast-form-group">
                                        <label class="ofast-checkbox-label">
                                            <input type="checkbox" name="hide_wp_version" value="1" <?php checked(!empty($footer_settings['hide_wp_version'])); ?>>
                                            <span class="ofast-checkbox-custom"></span>
                                            <span class="ofast-checkbox-text">
                                                Hide WordPress version number
                                                <span class="ofast-security-badge">Security Recommended</span>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="ofast-card ofast-preview-card">
                                <div class="ofast-card-header">
                                    <span class="dashicons dashicons-visibility"></span>
                                    <h2>Footer Preview</h2>
                                </div>
                                <div class="ofast-card-body">
                                    <div class="ofast-preview-widget">
                                        <div class="ofast-preview-footer">
                                            <span class="ofast-preview-left"
                                                id="preview-left"><?php echo !empty($footer_settings['left_text']) ? wp_kses_post($this->replace_shortcuts($footer_settings['left_text'])) : '<em>Thank you for creating with WordPress.</em>'; ?></span>
                                            <span class="ofast-preview-right"
                                                id="preview-right"><?php echo !empty($footer_settings['right_text']) ? esc_html($footer_settings['right_text']) : (!empty($footer_settings['hide_wp_version']) ? '' : '<em>Version X.X</em>'); ?></span>
                                        </div>
                                    </div>
                                    <p class="ofast-preview-note">
                                        <span class="dashicons dashicons-info-outline"></span>
                                        This is how your footer appears in the admin area
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="ofast-form-actions"
                            style="margin-top: 30px; display: flex; gap: 12px; align-items: center;">
                            <button type="submit" name="ofast_white_label_save" class="ofast-btn-primary">
                                Save Settings
                            </button>
                            <button type="submit" name="ofast_white_label_reset" class="ofast-btn-reset"
                                onclick="return confirm('Reset ALL White Label settings to defaults?');">
                                Reset to Default
                            </button>
                        </div>
                    </div>

                    <div class="ofast-tab-content<?php echo $default_tab === 'updates' ? ' active' : ''; ?>"
                        data-tab-panel="updates">

                        <div class="ofast-subtab-layout">
                            <!-- Left: vertical sub-tab nav -->
                            <nav class="ofast-subtab-nav">
                                <button type="button" class="ofast-subtab active" data-subtab="features">
                                    <span class="dashicons dashicons-admin-generic"></span>
                                    <?php esc_html_e('Features', 'ofast-x'); ?>
                                </button>
                                <button type="button" class="ofast-subtab" data-subtab="updates">
                                    <span class="dashicons dashicons-update"></span>
                                    <?php esc_html_e('Plugin Update', 'ofast-x'); ?>
                                </button>
                                <button type="button" class="ofast-subtab" data-subtab="page-protection">
                                    <span class="dashicons dashicons-lock"></span>
                                    <?php esc_html_e('Page Protection', 'ofast-x'); ?>
                                </button>
                                <button type="button" class="ofast-subtab" data-subtab="menu-editor">
                                    <span class="dashicons dashicons-menu-alt3"></span>
                                    <?php esc_html_e('Menu Editor', 'ofast-x'); ?>
                                </button>

                                <!-- Save/Reset buttons moved below subtab layout for full visibility on all screens -->
                            </nav>

                            <!-- Right: sub-tab content panels -->
                            <div class="ofast-subtab-panels">

                                <!-- Features Panel -->
                                <div class="ofast-subtab-panel active" data-subtab-panel="features">
                                    <div class="ofast-card ofast-main-card">
                                        <div class="ofast-card-header">
                                            <span class="dashicons dashicons-admin-generic"></span>
                                            <h2><?php esc_html_e('Features', 'ofast-x'); ?></h2>
                                        </div>
                                        <div class="ofast-card-body">
                                            <div class="ofast-form-group">
                                                <label class="ofast-checkbox-label">
                                                    <input type="checkbox" name="enable_dark_mode" value="1" <?php checked(!empty($footer_settings['enable_dark_mode'])); ?>>
                                                    <span class="ofast-checkbox-custom"></span>
                                                    <span class="ofast-checkbox-text">
                                                        Enable Dark/Light Mode Toggle
                                                        <span class="ofast-security-badge"
                                                            style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);">UI
                                                            Feature</span>
                                                    </span>
                                                </label>
                                            </div>

                                            <div class="ofast-form-group">
                                                <label class="ofast-checkbox-label">
                                                    <input type="checkbox" name="enable_custom_dashboard" value="1" <?php checked(!empty($footer_settings['enable_custom_dashboard'])); ?>>
                                                    <span class="ofast-checkbox-custom"></span>
                                                    <span class="ofast-checkbox-text">
                                                        Enable Custom Dashboard
                                                        <span class="ofast-security-badge"
                                                            style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);">New
                                                            Feature</span>
                                                    </span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Plugin Update Panel -->
                                <div class="ofast-subtab-panel" data-subtab-panel="updates">
                                    <div class="ofast-card ofast-main-card">
                                        <div class="ofast-card-header">
                                            <span class="dashicons dashicons-update"></span>
                                            <h2><?php esc_html_e('Plugin Update', 'ofast-x'); ?></h2>
                                        </div>
                                        <div class="ofast-card-body">
                                            <div class="ofast-field">
                                                <label for="ofast_disable_plugin_updates" class="ofast-toggle-label">
                                                    <span
                                                        class="ofast-toggle-text"><?php esc_html_e('Disable Plugin Updates for Specific Plugins', 'ofast-x'); ?></span>
                                                    <div class="ofast-toggle-switch">
                                                        <input type="checkbox" id="ofast_disable_plugin_updates"
                                                            name="ofast_disable_plugin_updates" value="1" <?php checked(get_option('ofast_disable_plugin_updates', 0), 1); ?> />
                                                        <span class="ofast-toggle-slider"></span>
                                                    </div>
                                                </label>
                                                <p class="ofast-field-hint">
                                                    <?php esc_html_e('When enabled, WordPress update checks will be suppressed for the plugins you select below.', 'ofast-x'); ?>
                                                </p>
                                            </div>

                                            <?php
                                            $is_updates_enabled = get_option('ofast_disable_plugin_updates', 0);
                                            $disabled_plugins_list = get_option('ofast_disabled_plugins_list', array());
                                            if (!is_array($disabled_plugins_list)) {
                                                $disabled_plugins_list = array();
                                            }

                                            // Get all installed plugins
                                            if (!function_exists('get_plugins')) {
                                                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                                            }
                                            $all_plugins = get_plugins();
                                            ?>

                                            <div id="ofast-plugin-selector" class="ofast-plugin-selector"
                                                style="<?php echo $is_updates_enabled ? '' : 'display:none;'; ?>">
                                                <div class="ofast-plugin-search-wrap">
                                                    <span class="dashicons dashicons-search"></span>
                                                    <input type="text" id="ofast-plugin-search" class="ofast-plugin-search"
                                                        placeholder="<?php esc_attr_e('Search plugins...', 'ofast-x'); ?>" />
                                                </div>

                                                <div class="ofast-plugin-select-actions">
                                                    <button type="button" class="ofast-select-all-btn"
                                                        id="ofast-select-all"><?php esc_html_e('Select All', 'ofast-x'); ?></button>
                                                    <button type="button" class="ofast-select-all-btn"
                                                        id="ofast-deselect-all"><?php esc_html_e('Deselect All', 'ofast-x'); ?></button>
                                                    <span class="ofast-selected-count"><span
                                                            id="ofast-selected-num"><?php echo count($disabled_plugins_list); ?></span>
                                                        <?php esc_html_e('selected', 'ofast-x'); ?></span>
                                                </div>

                                                <div class="ofast-plugin-list">
                                                    <?php foreach ($all_plugins as $plugin_file => $plugin_data): ?>
                                                        <label class="ofast-plugin-item"
                                                            data-plugin-name="<?php echo esc_attr(strtolower($plugin_data['Name'])); ?>">
                                                            <input type="checkbox" name="ofast_disabled_plugins[]"
                                                                value="<?php echo esc_attr($plugin_file); ?>" <?php checked(in_array($plugin_file, $disabled_plugins_list)); ?> />
                                                            <span class="ofast-plugin-checkbox-custom"></span>
                                                            <div class="ofast-plugin-info">
                                                                <span
                                                                    class="ofast-plugin-name"><?php echo esc_html($plugin_data['Name']); ?></span>
                                                                <span class="ofast-plugin-meta">
                                                                    <?php if (!empty($plugin_data['Version'])): ?>
                                                                        <span
                                                                            class="ofast-plugin-version">v<?php echo esc_html($plugin_data['Version']); ?></span>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($plugin_data['AuthorName'])): ?>
                                                                        <span
                                                                            class="ofast-plugin-author"><?php echo esc_html($plugin_data['AuthorName']); ?></span>
                                                                    <?php endif; ?>
                                                                </span>
                                                            </div>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Page Protection Panel -->
                                <div class="ofast-subtab-panel" data-subtab-panel="page-protection">

                                    <?php
                                    // Page Protection Settings
                                    $page_protection_enabled = get_option('ofast_page_protection_enabled', 0);
                                    $super_admin_username = get_option('ofast_super_admin_username', wp_get_current_user()->user_login);
                                    $protected_pages_list = get_option('ofast_protected_pages_list', array());
                                    if (!is_array($protected_pages_list)) {
                                        $protected_pages_list = array();
                                    }
                                    $has_password_set = (bool) get_option('ofast_protection_password', '');
                                    $page_protection_timeout = absint(get_option('ofast_page_protection_timeout', self::PAGE_PROTECTION_TIMEOUT_DEFAULT));

                                    // Common admin pages
                                    $available_pages = array(
                                        'themes.php' => __('Appearance', 'ofast-x'),
                                        'plugins.php' => __('Plugins', 'ofast-x'),
                                        'users.php' => __('Users', 'ofast-x'),
                                        'options-general.php' => __('Settings', 'ofast-x'),
                                        'tools.php' => __('Tools', 'ofast-x'),
                                        'edit.php' => __('Posts', 'ofast-x'),
                                        'upload.php' => __('Media', 'ofast-x'),
                                        'edit.php?post_type=page' => __('Pages', 'ofast-x'),
                                        'edit-comments.php' => __('Comments', 'ofast-x'),
                                        'profile.php' => __('Profile', 'ofast-x'),
                                    );

                                    // Add registered admin pages dynamically
                                    global $menu;
                                    if (!empty($menu)) {
                                        foreach ($menu as $m) {
                                            if (!empty($m[2]) && !isset($available_pages[$m[2]]) && !empty($m[0])) {
                                                $label = wp_strip_all_tags($m[0]);
                                                if (!empty($label)) {
                                                    $available_pages[$m[2]] = $label;
                                                }
                                            }
                                        }
                                    }
                                    ?>

                                    <div class="ofast-card ofast-main-card" style="margin-top: 20px; margin-bottom: 20px;">
                                        <div class="ofast-card-header" id="ofast-page-protection-header"
                                            style="cursor: pointer; display: flex; align-items: center; justify-content: space-between;">
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <span class="dashicons dashicons-lock"></span>
                                                <h2 style="margin: 0;"><?php esc_html_e('Page Protection', 'ofast-x'); ?></h2>
                                            </div>
                                            <span
                                                class="dashicons <?php echo $page_protection_enabled ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2'; ?>"
                                                id="ofast-page-protection-arrow"
                                                style="font-size: 20px; color: #64748b; transition: transform 0.2s;"></span>
                                        </div>
                                        <div class="ofast-card-body" id="ofast-page-protection-body"
                                            style="<?php echo $page_protection_enabled ? '' : 'display: none;'; ?>">
                                            <div class="ofast-field">
                                                <label for="ofast_page_protection_enabled" class="ofast-toggle-label">
                                                    <span
                                                        class="ofast-toggle-text"><?php esc_html_e('Password-Protect Admin Pages', 'ofast-x'); ?></span>
                                                    <div class="ofast-toggle-switch">
                                                        <input type="checkbox" id="ofast_page_protection_enabled"
                                                            name="ofast_page_protection_enabled" value="1" <?php checked($page_protection_enabled, 1); ?> />
                                                        <span class="ofast-toggle-slider"></span>
                                                    </div>
                                                </label>
                                                <p class="ofast-field-hint">
                                                    <?php esc_html_e('When enabled, non-super-admin users must enter a password to access the selected pages.', 'ofast-x'); ?>
                                                </p>
                                            </div>

                                            <div id="ofast-page-protection-settings" class="ofast-page-protection-settings"
                                                style="<?php echo $page_protection_enabled ? '' : 'display:none;'; ?>">

                                                <div class="ofast-protection-fields">
                                                    <div class="ofast-form-group">
                                                        <label for="ofast_super_admin_username">
                                                            <span class="dashicons dashicons-admin-users"></span>
                                                            <?php esc_html_e('Super Admin Username', 'ofast-x'); ?>
                                                        </label>
                                                        <input type="text" id="ofast_super_admin_username"
                                                            name="ofast_super_admin_username"
                                                            value="<?php echo esc_attr($super_admin_username); ?>"
                                                            placeholder="<?php esc_attr_e('e.g. admin', 'ofast-x'); ?>">
                                                        <span class="ofast-field-hint">
                                                            <?php esc_html_e('This user will bypass protection and always have full access.', 'ofast-x'); ?>
                                                        </span>
                                                    </div>

                                                    <div class="ofast-form-group">
                                                        <label for="ofast_protection_password">
                                                            <span class="dashicons dashicons-lock"></span>
                                                            <?php esc_html_e('Protection Password', 'ofast-x'); ?>
                                                        </label>
                                                        <input type="password" id="ofast_protection_password"
                                                            name="ofast_protection_password" value=""
                                                            placeholder="<?php echo $has_password_set ? esc_attr__('••••••• (leave blank to keep current)', 'ofast-x') : esc_attr__('Set a password', 'ofast-x'); ?>">
                                                        <span class="ofast-field-hint">
                                                            <?php if ($has_password_set): ?>
                                                                <span style="color: #10b981;">✓
                                                                    <?php esc_html_e('Password is set.', 'ofast-x'); ?></span>
                                                                <?php esc_html_e('Leave blank to keep current password.', 'ofast-x'); ?>
                                                            <?php else: ?>
                                                                <span style="color: #ef4444;">✗
                                                                    <?php esc_html_e('No password set. Feature will not work until a password is configured.', 'ofast-x'); ?></span>
                                                            <?php endif; ?>
                                                        </span>
                                                    </div>

                                                    <div class="ofast-form-group">
                                                        <label for="ofast_page_protection_timeout">
                                                            <span class="dashicons dashicons-clock"></span>
                                                            <?php esc_html_e('Remember Access For (Minutes)', 'ofast-x'); ?>
                                                        </label>
                                                        <input type="number" id="ofast_page_protection_timeout"
                                                            name="ofast_page_protection_timeout"
                                                            value="<?php echo esc_attr($page_protection_timeout); ?>"
                                                            min="<?php echo esc_attr(self::PAGE_PROTECTION_TIMEOUT_MIN); ?>"
                                                            max="<?php echo esc_attr(self::PAGE_PROTECTION_TIMEOUT_MAX); ?>"
                                                            step="5">
                                                        <span class="ofast-field-hint">
                                                            <?php esc_html_e('Recommended: 30 minutes. Access is remembered in a signed, expiring admin cookie — not as plain text and never in the URL.', 'ofast-x'); ?>
                                                        </span>
                                                    </div>
                                                </div>

                                                <div class="ofast-protected-pages-section">
                                                    <h4 style="margin: 20px 0 10px; font-weight: 600; color: #1e293b;">
                                                        <span class="dashicons dashicons-shield"
                                                            style="color: #6366f1; margin-right: 4px;"></span>
                                                        <?php esc_html_e('Protected Pages', 'ofast-x'); ?>
                                                    </h4>

                                                    <div class="ofast-plugin-search-wrap">
                                                        <span class="dashicons dashicons-search"></span>
                                                        <input type="text" id="ofast-page-search" class="ofast-plugin-search"
                                                            placeholder="<?php esc_attr_e('Search pages...', 'ofast-x'); ?>" />
                                                    </div>

                                                    <div class="ofast-plugin-select-actions">
                                                        <button type="button" class="ofast-select-all-btn"
                                                            id="ofast-page-select-all"><?php esc_html_e('Select All', 'ofast-x'); ?></button>
                                                        <button type="button" class="ofast-select-all-btn"
                                                            id="ofast-page-deselect-all"><?php esc_html_e('Deselect All', 'ofast-x'); ?></button>
                                                        <span class="ofast-selected-count"><span
                                                                id="ofast-page-selected-num"><?php echo count($protected_pages_list); ?></span>
                                                            <?php esc_html_e('selected', 'ofast-x'); ?></span>
                                                    </div>

                                                    <div class="ofast-plugin-list">
                                                        <?php foreach ($available_pages as $page_slug => $page_label): ?>
                                                            <label class="ofast-plugin-item ofast-page-item"
                                                                data-plugin-name="<?php echo esc_attr(strtolower($page_label)); ?>">
                                                                <input type="checkbox" name="ofast_protected_pages[]"
                                                                    value="<?php echo esc_attr($page_slug); ?>" <?php checked(in_array($page_slug, $protected_pages_list)); ?> />
                                                                <span class="ofast-plugin-checkbox-custom"></span>
                                                                <div class="ofast-plugin-info">
                                                                    <span
                                                                        class="ofast-plugin-name"><?php echo esc_html($page_label); ?></span>
                                                                    <span class="ofast-plugin-meta">
                                                                        <span
                                                                            class="ofast-plugin-version"><?php echo esc_html($page_slug); ?></span>
                                                                    </span>
                                                                </div>
                                                            </label>
                                                        <?php endforeach; ?>
                                                    </div><!-- .ofast-plugin-list -->
                                                </div><!-- .ofast-protected-pages-section -->
                                            </div><!-- #ofast-page-protection-settings -->
                                        </div><!-- .ofast-card-body -->
                                    </div><!-- .ofast-card -->
                                </div><!-- .ofast-subtab-panel page-protection -->

                                <!-- Menu Editor Panel -->
                                <div class="ofast-subtab-panel" data-subtab-panel="menu-editor">
                                    <?php
                                    // Embedded Menu Editor
                                    if ($this->menu_editor) {
                                        $this->menu_editor->render_embedded();
                                    }
                                    ?>
                                </div>

                            </div><!-- .ofast-subtab-panels -->
                        </div><!-- .ofast-subtab-layout -->

                        <div class="ofast-form-actions"
                            style="margin-top: 24px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                            <button type="submit" name="ofast_white_label_save" class="ofast-btn-primary">
                                Save Settings
                            </button>
                            <button type="submit" name="ofast_white_label_reset" class="ofast-btn-reset"
                                onclick="return confirm('Reset ALL White Label settings to defaults?');">
                                Reset to Default
                            </button>
                        </div>

                    </div>


                </div>
            </form>
        </div>

        <style>
            .ofast-white-label-wrap {
                max-width: 1200px;
                margin: 20px auto;
                padding: 0 20px;
            }

            /* Page Header - White with glassmorphism icon */
            .ofast-page-header {
                background: #ffffff;
                border-radius: 16px;
                padding: 30px;
                margin-bottom: 30px;
                border: 1px solid #e2e8f0;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            }

            .ofast-header-content {
                display: flex;
                align-items: center;
                gap: 20px;
            }

            .ofast-header-icon {
                width: 60px;
                height: 60px;
                background: #ffffff;
                border-radius: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 4px 15px rgba(99, 102, 241, 0.2);
                border: 1px solid #e2e8f0;
                color: #6366f1;
            }

            .ofast-header-icon .dashicons {
                font-size: 28px;
                width: 28px;
                height: 28px;
            }

            .ofast-header-text h1 {
                margin: 0;
                font-size: 28px;
                font-weight: 700;
                color: #1e293b;
            }

            .ofast-header-text p {
                margin: 5px 0 0;
                color: #64748b;
                font-size: 15px;
            }

            /* Content Grid */
            .ofast-content-grid {
                display: grid;
                grid-template-columns: 1.5fr 1fr;
                gap: 25px;
                align-items: start;
            }

            @media (max-width: 900px) {
                .ofast-content-grid {
                    grid-template-columns: 1fr;
                }
            }

            /* Cards */
            .ofast-card {
                background: #fff;
                border-radius: 16px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
                overflow: hidden;
                border: 1px solid rgba(0, 0, 0, 0.05);
            }

            .ofast-card-header {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 20px 25px;
                background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
                border-bottom: 1px solid #e2e8f0;
            }

            .ofast-card-header .dashicons {
                font-size: 20px;
                width: 20px;
                height: 20px;
                color: #6366f1;
            }

            .ofast-card-header h2 {
                margin: 0;
                font-size: 16px;
                font-weight: 600;
                color: #1e293b;
            }

            .ofast-card-body {
                padding: 25px;
            }

            /* Modern Form */
            .ofast-modern-form .ofast-form-group {
                margin-bottom: 24px;
            }

            .ofast-modern-form label {
                display: flex;
                align-items: center;
                gap: 8px;
                font-weight: 600;
                color: #374151;
                margin-bottom: 10px;
                font-size: 14px;
            }

            .ofast-modern-form label .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
                color: #6366f1;
            }

            .ofast-modern-form input[type="text"],
            .ofast-modern-form input[type="email"],
            .ofast-modern-form input[type="url"],
            .ofast-modern-form textarea {
                width: 100%;
                padding: 14px 18px;
                border: 2px solid #e2e8f0;
                border-radius: 10px;
                font-size: 15px;
                transition: all 0.2s ease;
                background: #f8fafc;
                font-family: inherit;
            }

            .ofast-modern-form textarea {
                min-height: 100px;
                resize: vertical;
            }

            .ofast-modern-form input:focus,
            .ofast-modern-form textarea:focus {
                outline: none;
                border-color: #6366f1;
                background: #fff;
                box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
            }

            .ofast-modern-form input::placeholder,
            .ofast-modern-form textarea::placeholder {
                color: #94a3b8;
            }

            .ofast-field-hint {
                display: block;
                margin-top: 8px;
                font-size: 13px;
                color: #64748b;
            }

            .ofast-field-hint code {
                background: #f1f5f9;
                padding: 2px 6px;
                border-radius: 4px;
                font-size: 12px;
                color: #6366f1;
            }

            /* Checkbox styling */
            .ofast-checkbox-label {
                display: flex !important;
                align-items: center;
                gap: 12px;
                cursor: pointer;
                padding: 16px 20px;
                background: #f8fafc;
                border: 2px solid #e2e8f0;
                border-radius: 10px;
                transition: all 0.2s ease;
            }

            .ofast-checkbox-label:hover {
                border-color: #6366f1;
                background: #fff;
            }

            .ofast-checkbox-label input[type="checkbox"] {
                width: 20px;
                height: 20px;
                accent-color: #6366f1;
            }

            .ofast-checkbox-text {
                display: flex;
                align-items: center;
                gap: 8px;
                flex: 1;
            }

            .ofast-security-badge {
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                color: #fff;
                padding: 3px 10px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            /* Form Actions */
            .ofast-form-actions {
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #e2e8f0;
            }

            .ofast-btn-primary {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                padding: 14px 28px;
                background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
                color: #fff;
                border: none;
                border-radius: 10px;
                font-size: 15px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
            }

            .ofast-btn-primary:hover {
                background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
            }

            .ofast-btn-primary .dashicons {
                font-size: 18px;
                width: 18px;
                height: 18px;
            }

            .ofast-btn-reset {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                padding: 12px 24px;
                background: #fff;
                color: #ef4444;
                border: 1px solid #fecaca;
                border-radius: 10px;
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
            }

            .ofast-btn-reset:hover {
                background: #fef2f2;
                border-color: #ef4444;
            }

            /* Preview Card */
            .ofast-preview-widget {
                background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
                border-radius: 12px;
                padding: 20px;
                border: 1px solid #e2e8f0;
            }

            .ofast-preview-item {
                padding: 12px 0;
                border-bottom: 1px solid #e2e8f0;
            }

            .ofast-preview-item:last-child {
                border-bottom: none;
            }

            .ofast-preview-label {
                display: block;
                font-size: 12px;
                font-weight: 600;
                color: #64748b;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-bottom: 4px;
            }

            .ofast-preview-value {
                font-size: 15px;
                color: #1e293b;
                font-weight: 500;
            }

            .ofast-link {
                color: #6366f1;
                text-decoration: none;
            }

            .ofast-link:hover {
                text-decoration: underline;
            }

            .ofast-preview-note {
                display: flex;
                align-items: center;
                gap: 8px;
                margin: 20px 0 0;
                padding: 12px 15px;
                background: #e0f2fe;
                border-radius: 8px;
                font-size: 13px;
                color: #0369a1;
            }

            .ofast-preview-note .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
            }

            /* Footer Preview */
            .ofast-preview-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                background: #fff;
                padding: 12px 16px;
                border-radius: 8px;
                border: 1px solid #e2e8f0;
                font-size: 13px;
                color: #64748b;
            }

            .ofast-preview-footer em {
                color: #94a3b8;
            }

            /* Tooltip */
            .ofast-tooltip {
                position: relative;
                cursor: help;
                margin-left: 4px;
            }

            .ofast-tooltip .dashicons {
                font-size: 14px !important;
                width: 14px !important;
                height: 14px !important;
                color: #94a3b8;
                transition: color 0.2s ease;
            }

            .ofast-tooltip:hover .dashicons {
                color: #6366f1;
            }

            /* Toggle Switch */
            .ofast-toggle-label {
                display: flex;
                align-items: center;
                justify-content: space-between;
                cursor: pointer;
                user-select: none;
            }

            .ofast-toggle-text {
                flex: 1;
                font-weight: 500;
                color: #1e293b;
            }

            .ofast-toggle-switch {
                position: relative;
                width: 50px;
                height: 24px;
            }

            .ofast-toggle-switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }

            .ofast-toggle-slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #ccc;
                transition: 0.4s;
                border-radius: 24px;
            }

            .ofast-toggle-slider:before {
                position: absolute;
                content: "";
                height: 18px;
                width: 18px;
                left: 3px;
                bottom: 3px;
                background-color: white;
                transition: 0.4s;
                border-radius: 50%;
            }

            input:checked+.ofast-toggle-slider {
                background-color: #6366f1;
            }

            input:checked+.ofast-toggle-slider:before {
                transform: translateX(26px);
            }

            /* Plugin Selector */
            .ofast-plugin-selector {
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #e2e8f0;
            }

            .ofast-plugin-search-wrap {
                position: relative;
                margin-bottom: 12px;
            }

            .ofast-plugin-search-wrap .dashicons {
                position: absolute;
                left: 14px;
                top: 50%;
                transform: translateY(-50%);
                color: #94a3b8;
                font-size: 18px;
                width: 18px;
                height: 18px;
            }

            .ofast-plugin-search {
                width: 100%;
                padding: 10px 14px 10px 40px !important;
                border: 2px solid #e2e8f0 !important;
                border-radius: 10px !important;
                font-size: 14px !important;
                background: #f8fafc !important;
                transition: all 0.2s ease;
            }

            .ofast-plugin-search:focus {
                border-color: #6366f1 !important;
                background: #fff !important;
                box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1) !important;
                outline: none !important;
            }

            .ofast-plugin-select-actions {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 12px;
            }

            .ofast-select-all-btn {
                padding: 4px 12px;
                border: 1px solid #e2e8f0;
                border-radius: 6px;
                background: #f8fafc;
                color: #475569;
                font-size: 12px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s ease;
            }

            .ofast-select-all-btn:hover {
                border-color: #6366f1;
                color: #6366f1;
                background: #f0f0ff;
            }

            .ofast-selected-count {
                margin-left: auto;
                font-size: 13px;
                color: #64748b;
                font-weight: 500;
            }

            .ofast-plugin-list {
                max-height: 400px;
                overflow-y: auto;
                border: 1px solid #e2e8f0;
                border-radius: 10px;
                background: #f8fafc;
            }

            .ofast-plugin-item {
                display: flex !important;
                align-items: center;
                gap: 12px;
                padding: 12px 16px !important;
                margin: 0 !important;
                border-bottom: 1px solid #e2e8f0;
                cursor: pointer;
                transition: background 0.15s ease;
            }

            .ofast-plugin-item:last-child {
                border-bottom: none;
            }

            .ofast-plugin-item:hover {
                background: #eef2ff;
            }

            .ofast-plugin-item input[type="checkbox"] {
                display: none;
            }

            .ofast-plugin-checkbox-custom {
                width: 20px;
                height: 20px;
                min-width: 20px;
                border: 2px solid #cbd5e1;
                border-radius: 6px;
                background: #fff;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all 0.2s ease;
            }

            .ofast-plugin-item input:checked+.ofast-plugin-checkbox-custom {
                background: #6366f1;
                border-color: #6366f1;
            }

            .ofast-plugin-item input:checked+.ofast-plugin-checkbox-custom::after {
                content: '';
                width: 6px;
                height: 10px;
                border: solid #fff;
                border-width: 0 2px 2px 0;
                transform: rotate(45deg);
                margin-top: -2px;
            }

            .ofast-plugin-info {
                display: flex;
                flex-direction: column;
                gap: 2px;
                min-width: 0;
            }

            .ofast-plugin-name {
                font-weight: 500;
                font-size: 14px;
                color: #1e293b;
            }

            .ofast-plugin-meta {
                display: flex;
                gap: 10px;
                font-size: 12px;
                color: #94a3b8;
            }

            .ofast-plugin-version {
                background: #f1f5f9;
                padding: 1px 6px;
                border-radius: 4px;
                font-size: 11px;
            }

            .ofast-plugin-item.ofast-hidden {
                display: none !important;
            }

            /* Page Protection */
            .ofast-page-protection-settings {
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #e2e8f0;
            }

            .ofast-protection-fields {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin-bottom: 10px;
            }

            @media (max-width: 768px) {
                .ofast-protection-fields {
                    grid-template-columns: 1fr;
                }
            }

            .ofast-form-group label {
                display: flex;
                align-items: center;
                gap: 6px;
                font-weight: 600;
                font-size: 13px;
                color: #374151;
                margin-bottom: 8px;
            }

            .ofast-form-group label .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
                color: #6366f1;
            }

            .ofast-form-group input[type="text"],
            .ofast-form-group input[type="password"] {
                width: 100%;
                padding: 10px 14px !important;
                border: 2px solid #e2e8f0 !important;
                border-radius: 10px !important;
                font-size: 14px !important;
                background: #f8fafc !important;
                transition: all 0.2s ease;
                box-sizing: border-box;
            }

            .ofast-form-group input:focus {
                border-color: #6366f1 !important;
                background: #fff !important;
                box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1) !important;
                outline: none !important;
            }

            .ofast-form-group .ofast-field-hint {
                display: block;
                font-size: 12px;
                color: #94a3b8;
                margin-top: 6px;
            }

            /* ===== Vertical Sub-Tab Layout (Updates tab) ===== */
            .ofast-subtab-layout {
                display: block;
            }

            .ofast-subtab-nav {
                display: none;
            }

            /* Mobile/tablet: show all panels stacked */
            .ofast-subtab-panel {
                display: block !important;
                margin-bottom: 20px;
            }

            /* Desktop: side-by-side layout */
            @media (min-width: 1024px) {
                .ofast-subtab-layout {
                    display: flex;
                    gap: 24px;
                    align-items: flex-start;
                }

                .ofast-subtab-nav {
                    display: flex;
                    flex-direction: column;
                    gap: 4px;
                    width: 240px;
                    flex-shrink: 0;
                    position: sticky;
                    top: 52px;
                    background: #fff;
                    border-radius: 12px;
                    padding: 8px;
                    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08), 0 1px 2px rgba(0, 0, 0, 0.06);
                    border: 1px solid #e2e8f0;
                }

                .ofast-subtab {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    padding: 10px 14px;
                    border: none;
                    background: transparent;
                    border-radius: 8px;
                    cursor: pointer;
                    font-size: 13px;
                    font-weight: 500;
                    color: #64748b;
                    text-align: left;
                    transition: all 0.2s ease;
                    white-space: nowrap;
                }

                .ofast-subtab:hover {
                    background: #f1f5f9;
                    color: #334155;
                }

                .ofast-subtab.active {
                    background: #eef2ff;
                    color: #6366f1;
                    border: 1px solid #c7d2fe;
                    font-weight: 600;
                }

                .ofast-subtab .dashicons {
                    font-size: 18px;
                    width: 18px;
                    height: 18px;
                    line-height: 18px;
                }

                .ofast-subtab-panels {
                    flex: 1;
                    min-width: 0;
                }

                /* Desktop: only show active panel */
                .ofast-subtab-panel {
                    display: none !important;
                }

                .ofast-subtab-panel.active {
                    display: block !important;
                }
            }

            /* Make tables inside sub-tab panels horizontally scrollable */
            .ofast-subtab-panel .ofast-table-wrap,
            .ofast-subtab-panel table {
                width: 100%;
            }

            .ofast-subtab-panel .ofast-table-scroll {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
        </style>

        <script>
            jQuery(document).ready(function ($) {
                // Live preview updates for Designer Details
                $('#designer_name').on('input', function () {
                    $('#preview-name').text($(this).val() || 'Your Name');
                });
                $('#designer_email').on('input', function () {
                    var val = $(this).val() || 'hello@example.com';
                    $('#preview-email').text(val).attr('href', 'mailto:' + val);
                });
                $('#designer_website').on('input', function () {
                    var val = $(this).val() || 'https://example.com';
                    $('#preview-website').text(val).attr('href', val);
                });

                // Live preview for Footer
                $('#footer_left_text').on('input', function () {
                    var text = $(this).val() || '<em>Thank you for creating with WordPress.</em>';
                    $('#preview-left').html(text);
                });
                $('#footer_right_text').on('input', function () {
                    var defaultRight = <?php echo json_encode(empty($footer_settings['hide_wp_version']) ? '<em>Version X.X</em>' : ''); ?>;
                    var text = $(this).val() || defaultRight;
                    $('#preview-right').html(text || '<em>Version X.X</em>');
                });
                $('input[name="hide_wp_version"]').on('change', function () {
                    if ($(this).is(':checked') && !$('#footer_right_text').val()) {
                        $('#preview-right').html('');
                    } else if (!$('#footer_right_text').val()) {
                        $('#preview-right').html('<em>Version X.X</em>');
                    }
                });
                // Toggle plugin selector visibility
                $('#ofast_disable_plugin_updates').on('change', function () {
                    if ($(this).is(':checked')) {
                        $('#ofast-plugin-selector').slideDown(200);
                    } else {
                        $('#ofast-plugin-selector').slideUp(200);
                    }
                });

                // Sub-tab switching for Updates tab
                $('.ofast-subtab-nav .ofast-subtab').on('click', function () {
                    var target = $(this).data('subtab');
                    $('.ofast-subtab-nav .ofast-subtab').removeClass('active');
                    $(this).addClass('active');
                    $('.ofast-subtab-panel').removeClass('active');
                    $('.ofast-subtab-panel[data-subtab-panel="' + target + '"]').addClass('active');
                });

                // Header arrow toggle for Menu Editor card body
                $('#ofast-menu-editor-header').on('click', function (e) {
                    if ($(e.target).closest('.ofast-toggle, .ofast-toggle-switch, input').length) return;
                    var $body = $('#ofast-menu-editor-body');
                    var $arrow = $('#ofast-menu-editor-arrow');
                    $body.slideToggle(200, function () {
                        if ($body.is(':visible')) {
                            $arrow.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
                        } else {
                            $arrow.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
                        }
                    });
                });

                // Header arrow toggle for Page Protection card body
                $('#ofast-page-protection-header').on('click', function (e) {
                    if ($(e.target).closest('.ofast-toggle, .ofast-toggle-switch, input, label, button, a').length) return;
                    var $body = $('#ofast-page-protection-body');
                    var $arrow = $('#ofast-page-protection-arrow');
                    $body.stop(true, true).slideToggle(200, function () {
                        if ($body.is(':visible')) {
                            $arrow.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
                        } else {
                            $arrow.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
                        }
                    });
                });

                // Plugin search filter
                $('#ofast-plugin-search').on('input', function () {
                    var query = $(this).val().toLowerCase();
                    $('.ofast-plugin-item').each(function () {
                        var name = $(this).data('plugin-name');
                        $(this).toggleClass('ofast-hidden', name.indexOf(query) === -1);
                    });
                });

                // Update selected count
                function updateSelectedCount() {
                    var count = $('.ofast-plugin-item input:checked').length;
                    $('#ofast-selected-num').text(count);
                }
                $('.ofast-plugin-item input').on('change', updateSelectedCount);

                // Select all / deselect all
                $('#ofast-select-all').on('click', function () {
                    $('.ofast-plugin-item:not(.ofast-hidden) input').prop('checked', true);
                    updateSelectedCount();
                });
                $('#ofast-deselect-all').on('click', function () {
                    $('.ofast-plugin-item:not(.ofast-hidden) input').prop('checked', false);
                    updateSelectedCount();
                });

                // Toggle page protection settings visibility
                $('#ofast_page_protection_enabled').on('change', function () {
                    if ($(this).is(':checked')) {
                        $('#ofast-page-protection-body').stop(true, true).slideDown(200);
                        $('#ofast-page-protection-arrow').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
                        $('#ofast-page-protection-settings').slideDown(200);
                    } else {
                        $('#ofast-page-protection-settings').slideUp(200);
                    }
                });

                // Page search filter
                $('#ofast-page-search').on('input', function () {
                    var query = $(this).val().toLowerCase();
                    $('.ofast-page-item').each(function () {
                        var name = $(this).data('plugin-name');
                        $(this).toggleClass('ofast-hidden', name.indexOf(query) === -1);
                    });
                });

                // Page selected count
                function updatePageSelectedCount() {
                    var count = $('.ofast-page-item input:checked').length;
                    $('#ofast-page-selected-num').text(count);
                }
                $('.ofast-page-item input').on('change', updatePageSelectedCount);

                // Page select all / deselect all
                $('#ofast-page-select-all').on('click', function () {
                    $('.ofast-page-item:not(.ofast-hidden) input').prop('checked', true);
                    updatePageSelectedCount();
                });
                $('#ofast-page-deselect-all').on('click', function () {
                    $('.ofast-page-item:not(.ofast-hidden) input').prop('checked', false);
                    updatePageSelectedCount();
                });
            });
        </script>
        <?php
    }
}
