<?php
/**
 * Ofast X Activator Class
 * Handles activation and deactivation logic
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Activator {
    
    /**
     * Plugin activation
     */
    public static function activate() {
        // Set activation timestamp
        update_option('ofastx_activated_time', time());
        update_option('ofastx_version', OFAST_X_VERSION);
        
        // Create database tables
        self::create_tables();
        
        // Set default options
        self::set_default_options();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log activation
        self::log_activation();
    }
    
    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Clear scheduled events
        self::clear_scheduled_events();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log deactivation
        self::log_deactivation();
    }
    
    /**
     * Create database tables
     */
    private static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // We'll add table creation later when we build modules
        // For now, just ensure no errors
    }
    
    /**
     * Set default plugin options
     */
    private static function set_default_options() {
        $default_options = array(
            'ofastx_license_status' => 'inactive',
            'ofastx_license_key' => '',
            'ofastx_modules_enabled' => array()
        );
        
        foreach ($default_options as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }
    
    /**
     * Clear scheduled events
     */
    private static function clear_scheduled_events() {
        // We'll implement this when we add scheduling
    }
    
    /**
     * Log activation
     */
    private static function log_activation() {
        // Simple activation log
        error_log('Ofast X Plugin Activated - Version: ' . OFAST_X_VERSION);
    }
    
    /**
     * Log deactivation
     */
    private static function log_deactivation() {
        // Simple deactivation log
        error_log('Ofast X Plugin Deactivated');
    }
}

