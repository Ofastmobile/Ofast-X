<?php

/**
 * Plugin Name: Ofast-X
 * Plugin URI: https://ofastshop.com/ofast-x
 * Description: All-in-One WordPress plugin with Email System, SMTP Configuration, Dashboard Customization, Newsletter, WhatsApp, Contact Forms, Code Snippets, Redirects, and more.
 * Version: 1.0.0
 * Author: Olabode Oluwaseun (Ofastshop Digitals)
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

/**
 * Plugin Constants
 */
define('OFAST_X_VERSION', '1.0.0');
define('OFAST_X_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OFAST_X_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OFAST_X_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('OFAST_X_PLUGIN_FILE', __FILE__);

/**
 * Load Action Scheduler (for reliable email scheduling)
 * This replaces unreliable WordPress cron
 * TEMPORARILY DISABLED - Action Scheduler has compatibility issues
 */
/*
if (!class_exists('ActionScheduler_Versions')) {
    require_once OFAST_X_PLUGIN_DIR . 'includes/libraries/action-scheduler/action-scheduler.php';
}
*/

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
    require_once OFAST_X_PLUGIN_DIR . 'includes/core/class-ofast-loader.php';

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
    $settings_link = '<a href="' . admin_url('admin.php?page=ofast-settings') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'ofast_x_plugin_action_links');

/**
 * Redirect to settings page on activation
 */
function ofast_x_activation_redirect()
{
    if (get_option('ofast_x_do_activation_redirect', false)) {
        delete_option('ofast_x_do_activation_redirect');
        if (!isset($_GET['activate-multi'])) {
            wp_safe_redirect(admin_url('admin.php?page=ofast-settings'));
            exit;
        }
    }
}
add_action('admin_init', 'ofast_x_activation_redirect');
