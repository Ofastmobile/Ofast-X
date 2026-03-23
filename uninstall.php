<?php

/**
 * Ofast X Uninstall Handler
 * 
 * This file runs when the plugin is deleted from WordPress.
 * It respects the user's data management preference:
 * - If "Keep All Data" is selected, nothing is deleted
 * - If "Remove All Data" is selected, all tables and options are removed
 *
 * @package Ofast_X
 */

// Prevent direct access
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Check if user wants to delete data
$delete_data = get_option('ofast_delete_data_on_uninstall', 0);

if (!$delete_data) {
    // User chose to keep data - exit without deleting anything
    return;
}

// User wants to remove all data - proceed with cleanup
global $wpdb;

/**
 * 1. Drop all database tables created by the plugin
 */
$tables = array(
    $wpdb->prefix . 'ofast_email_logs',
    $wpdb->prefix . 'ofast_email_drafts',  // Email drafts
    $wpdb->prefix . 'ofast_smtp_log',  // SMTP module log table (was missing)
    $wpdb->prefix . 'ofast_newsletter_subscribers',
    $wpdb->prefix . 'ofast_snippets',
    $wpdb->prefix . 'ofast_snippet_revisions',
    $wpdb->prefix . 'ofast_forms',
    $wpdb->prefix . 'ofast_form_submissions',
    $wpdb->prefix . 'ofast_redirects',
    $wpdb->prefix . 'ofast_redirect_logs',
);

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

/**
 * 2. Delete all plugin options from wp_options
 */
$options = array(
    // Core plugin options
    'ofastx_activated_time',
    'ofastx_version',
    'ofastx_license_status',
    'ofastx_license_key',
    'ofastx_modules_enabled',
    'ofast_email_retention_days',
    'ofast_x_do_activation_redirect',
    'ofast_delete_data_on_uninstall',

    // Email Template options
    'ofast_email_template_style',
    'ofast_email_primary_color',
    'ofast_email_accent_color',
    'ofast_email_bg_color',
    'ofast_email_text_color',
    'ofast_email_logo',
    'ofast_email_company_name',
    'ofast_email_tagline',
    'ofast_email_show_header',
    'ofast_email_show_footer',
    'ofast_email_from_name',
    'ofast_email_reply_to',
    'ofast_email_social',
    'ofast_email_apply_to',
    'ofast_email_font_family',
    'ofast_email_font_size',
    'ofast_email_logo_width',
    'ofast_email_logo_height',

    // SMTP options
    'ofast_smtp_enabled',
    'ofast_smtp_host',
    'ofast_smtp_port',
    'ofast_smtp_encryption',
    'ofast_smtp_auth',
    'ofast_smtp_username',
    'ofast_smtp_password',
    'ofast_smtp_from_email',
    'ofast_smtp_from_name',

    // Admin URL Protection options
    'ofast_admin_custom_slug',
    'ofast_admin_redirect_url',
    'ofast_admin_protection_enabled',
    'ofast_admin_emergency_code',
    'ofast_admin_emergency_expires',

    // Login Redesign options
    'ofast_login_template',
    'ofast_login_logo',
    'ofast_login_background',
    'ofast_login_settings',

    // Debug options
    'ofast_debug_mode',
);

foreach ($options as $option) {
    delete_option($option);
}

// Also delete any options that match ofast_ pattern (catch-all for any we might have missed)
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ofast%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ofastx%'");

/**
 * 3. Clear any scheduled cron events
 */
$cron_hooks = array(
    'ofast_scheduled_email_event',
    'ofast_daily_cleanup',
    'ofastx_daily_license_check',
    'ofast_send_email_batch',
);

foreach ($cron_hooks as $hook) {
    $timestamp = wp_next_scheduled($hook);
    while ($timestamp) {
        wp_unschedule_event($timestamp, $hook);
        $timestamp = wp_next_scheduled($hook);
    }
}

// Clear all cron events that match ofast pattern
$cron_array = _get_cron_array();
if (is_array($cron_array)) {
    foreach ($cron_array as $timestamp => $hooks) {
        foreach ($hooks as $hook => $jobs) {
            if (strpos($hook, 'ofast') === 0) {
                foreach ($jobs as $key => $job) {
                    wp_unschedule_event($timestamp, $hook, $job['args']);
                }
            }
        }
    }
}

/**
 * 4. Delete any transients created by the plugin
 */
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ofast%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ofast%'");

/**
 * 5. Clear any user meta created by the plugin
 */
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'ofast%'");

// Log the uninstall (this will only appear if WP_DEBUG_LOG is enabled)
if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
    error_log('Ofast X Plugin: All data has been removed during uninstall.');
}
