<?php

/**
 * Plugin Name: Ofast Toolkit
 * Plugin URI: https://ofastshop.com/ofast-x
 * Description: All-in-One WordPress plugin with Email System, SMTP Configuration, Dashboard Customization, Newsletter, Contact Forms, Code Snippets, Redirects, and more.
 * Version: 1.0.0
 * Author: Ofastshop Digitals
 * Author URI: https://ofastshop.com
 * Text Domain: ofast-x
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// ========================================================================================
// LICENSING: Self-hosted licensing via ofastshop.com API
// (loaded in ofast_x_init_plugin → class-ofast-licensing.php)
// ========================================================================================

/**
 * Plugin Constants
 */
define('OFAST_X_VERSION', '1.0.0');
define('OFAST_X_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OFAST_X_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OFAST_X_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('OFAST_X_PLUGIN_FILE', __FILE__);

// License API endpoint (can be overridden in wp-config.php)
if (!defined('OFAST_LICENSE_API_URL')) {
    define('OFAST_LICENSE_API_URL', 'https://ofastshop.com/wp-json/ofast-license/v1');
}


/**
 * Activation Hook
 */
register_activation_hook(__FILE__, 'ofast_x_activate_plugin');
function ofast_x_activate_plugin()
{
    require_once OFAST_X_PLUGIN_DIR . 'includes/core/class-ofast-activator.php';
    Ofast_X_Activator::activate();
}

/**
 *  Deactivation Hook
 */
register_deactivation_hook(__FILE__, 'ofast_x_deactivate_plugin');
function ofast_x_deactivate_plugin()
{
    require_once OFAST_X_PLUGIN_DIR . 'includes/core/class-ofast-activator.php';
    Ofast_X_Activator::deactivate();
}

/**
 * Initialize Plugin
 */
function ofast_x_init_plugin()
{
    // Load core classes
    require_once OFAST_X_PLUGIN_DIR . 'includes/core/class-ofast-core.php';

    // Load Licensing/Pro Gatekeeper
    require_once OFAST_X_PLUGIN_DIR . 'includes/core/class-ofast-licensing.php';

    // Load Logger utility (used by spam-protection, social-login)
    require_once OFAST_X_PLUGIN_DIR . 'includes/utilities/class-ofast-logger.php';

    // Load security hardening (early load for headers)
    require_once OFAST_X_PLUGIN_DIR . 'includes/security/class-ofast-security-hardening.php';

    // Load Toast Notification system
    require_once OFAST_X_PLUGIN_DIR . 'includes/core/class-ofast-toast.php';

    // Load Unified Button component
    require_once OFAST_X_PLUGIN_DIR . 'includes/core/class-ofast-button.php';

    // Load Unified Dropdown component
    require_once OFAST_X_PLUGIN_DIR . 'includes/core/class-ofast-dropdown.php';

    // Load Turnstile spam protection
    require_once OFAST_X_PLUGIN_DIR . 'includes/security/class-ofast-turnstile.php';

    // Load Honeypot spam protection (fallback)
    require_once OFAST_X_PLUGIN_DIR . 'includes/security/class-ofast-honeypot.php';

    // Load Universal Spam protection (force injection)
    require_once OFAST_X_PLUGIN_DIR . 'includes/security/class-ofast-universal-spam.php';

    // Load Math CAPTCHA (arithmetic challenge)
    require_once OFAST_X_PLUGIN_DIR . 'includes/security/class-ofast-math-captcha.php';

    // Load Contact Forms module
    require_once OFAST_X_PLUGIN_DIR . 'modules/forms/class-ofast-forms.php';

    // Load Social Login module
    require_once OFAST_X_PLUGIN_DIR . 'modules/social-login/class-ofast-social-login.php';

    // Load Login Redesign module
    require_once OFAST_X_PLUGIN_DIR . 'modules/login-redesign/class-ofast-login-redesign.php';

    // Load Content Ordering module
    require_once OFAST_X_PLUGIN_DIR . 'modules/admin-studio/class-ofast-content-ordering.php';

    // Load Setup Wizard
    require_once OFAST_X_PLUGIN_DIR . 'includes/core/class-ofast-setup-wizard.php';
    $wizard = new Ofast_X_Setup_Wizard();
    $wizard->init();

    // Initialize plugin
    $ofast_x = new Ofast_X_Core();
    $ofast_x->run();
}
add_action('plugins_loaded', 'ofast_x_init_plugin');

/**
 * Load Text Domain
 */
function ofast_x_load_textdomain()
{
    load_plugin_textdomain(
        'ofast-x',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}
add_action('plugins_loaded', 'ofast_x_load_textdomain');

/**
 * Add Settings link to plugins page
 */
function ofast_x_plugin_action_links($links)
{
    $settings_link = '<a href="' . admin_url('admin.php?page=ofast-dashboard') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'ofast_x_plugin_action_links');

/**
 * Redirect to wizard on first activation
 */
function ofast_x_activation_redirect()
{
    if (get_option('ofast_x_do_activation_redirect', false)) {
        delete_option('ofast_x_do_activation_redirect');
        if (!isset($_GET['activate-multi']) && !get_option('ofast_wizard_complete', false)) {
            wp_safe_redirect(admin_url('admin.php?page=ofast-setup-wizard'));
            exit;
        }
    }
}
add_action('admin_init', 'ofast_x_activation_redirect');
