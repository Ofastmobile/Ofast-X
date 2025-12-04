<?php

/**
 * Ofast X Activator Class
 * Handles activation and deactivation logic
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Activator
{

    /**
     * Plugin activation
     */
    public static function activate()
    {
        // Set activation timestamp
        update_option('ofastx_activated_time', time());
        update_option('ofastx_version', OFAST_X_VERSION);

        // Create database tables
        self::create_tables();

        // Set default options (including module states)
        self::set_default_options();

        // Flush rewrite rules
        flush_rewrite_rules();

        // Log activation
        self::log_activation();
    }

    /**
     * Plugin deactivation
     */
    public static function deactivate()
    {
        // Clear scheduled events
        self::clear_scheduled_events();

        // Flush rewrite rules
        flush_rewrite_rules();

        // Log deactivation
        self::log_deactivation();
    }
    
    // private static function create_tables() {
    //     global $wpdb;
    //     $charset_collate = $wpdb->get_charset_collate();
    //     // We'll add table creation later...
    // }
    /**
     * Create database tables
     */
    private static function create_tables()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // 1. Email Logs Table
        $table_email_logs = $wpdb->prefix . 'ofast_email_logs';
        $sql_email_logs = "CREATE TABLE IF NOT EXISTS {$table_email_logs} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            subject VARCHAR(255) NOT NULL,
            sent_at DATETIME NOT NULL,
            recipient_count INT(11) NOT NULL DEFAULT 0,
            status ENUM('sent', 'scheduled', 'failed') DEFAULT 'sent',
            notes TEXT,
            PRIMARY KEY (id),
            KEY idx_sent_at (sent_at),
            KEY idx_status (status)
        ) {$charset_collate};";
        dbDelta($sql_email_logs);

        // 2. Newsletter Subscribers Table
        $table_subscribers = $wpdb->prefix . 'ofast_newsletter_subscribers';
        $sql_subscribers = "CREATE TABLE IF NOT EXISTS {$table_subscribers} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            name VARCHAR(255),
            status ENUM('pending', 'confirmed', 'unsubscribed') DEFAULT 'pending',
            whatsapp_opted_in TINYINT(1) DEFAULT 0,
            whatsapp_number VARCHAR(20),
            subscribed_at DATETIME,
            confirmed_at DATETIME,
            unsubscribed_at DATETIME,
            ip_address VARCHAR(45),
            user_agent TEXT,
            PRIMARY KEY (id),
            UNIQUE KEY email (email),
            KEY idx_status (status)
        ) {$charset_collate};";
        dbDelta($sql_subscribers);

        // 3. Code Snippets Table
        $table_snippets = $wpdb->prefix . 'ofast_snippets';
        $sql_snippets = "CREATE TABLE IF NOT EXISTS {$table_snippets} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            code LONGTEXT NOT NULL,
            language ENUM('php', 'javascript', 'css') DEFAULT 'php',
            active TINYINT(1) DEFAULT 0,
            tags TEXT,
            scope ENUM('global', 'admin', 'frontend') DEFAULT 'global',
            priority INT(11) DEFAULT 10,
            created_at DATETIME,
            updated_at DATETIME,
            created_by BIGINT(20),
            PRIMARY KEY (id),
            KEY idx_active (active),
            KEY idx_language (language),
            KEY idx_scope (scope)
        ) {$charset_collate};";
        dbDelta($sql_snippets);

        // 4. Contact Forms Table
        $table_forms = $wpdb->prefix . 'ofast_forms';
        $sql_forms = "CREATE TABLE IF NOT EXISTS {$table_forms} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            fields LONGTEXT NOT NULL,
            settings LONGTEXT,
            notifications LONGTEXT,
            active TINYINT(1) DEFAULT 1,
            created_at DATETIME,
            updated_at DATETIME,
            PRIMARY KEY (id),
            KEY idx_active (active)
        ) {$charset_collate};";
        dbDelta($sql_forms);

        // 5. Form Submissions Table
        $table_submissions = $wpdb->prefix . 'ofast_form_submissions';
        $sql_submissions = "CREATE TABLE IF NOT EXISTS {$table_submissions} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT(20) UNSIGNED NOT NULL,
            data LONGTEXT NOT NULL,
            ip_address VARCHAR(45),
            user_agent TEXT,
            referer TEXT,
            status ENUM('unread', 'read', 'spam', 'trash') DEFAULT 'unread',
            submitted_at DATETIME,
            read_at DATETIME,
            PRIMARY KEY (id),
            KEY idx_form_id (form_id),
            KEY idx_status (status),
            KEY idx_submitted_at (submitted_at)
        ) {$charset_collate};";
        dbDelta($sql_submissions);

        // 6. Redirects Table
        $table_redirects = $wpdb->prefix . 'ofast_redirects';
        $sql_redirects = "CREATE TABLE IF NOT EXISTS {$table_redirects} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_url VARCHAR(500) NOT NULL,
            target_url VARCHAR(500) NOT NULL,
            type ENUM('301', '302', '307') DEFAULT '301',
            is_regex TINYINT(1) DEFAULT 0,
            hits INT(11) DEFAULT 0,
            last_accessed DATETIME,
            active TINYINT(1) DEFAULT 1,
            created_at DATETIME,
            created_by BIGINT(20),
            PRIMARY KEY (id),
            KEY idx_source (source_url(255)),
            KEY idx_active (active),
            KEY idx_hits (hits)
        ) {$charset_collate};";
        dbDelta($sql_redirects);

        // 7. Redirect Logs Table
        $table_redirect_logs = $wpdb->prefix . 'ofast_redirect_logs';
        $sql_redirect_logs = "CREATE TABLE IF NOT EXISTS {$table_redirect_logs} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            redirect_id BIGINT(20) UNSIGNED NOT NULL,
            accessed_at DATETIME,
            ip_address VARCHAR(45),
            user_agent TEXT,
            referer TEXT,
            PRIMARY KEY (id),
            KEY idx_redirect_id (redirect_id),
            KEY idx_accessed_at (accessed_at)
        ) {$charset_collate};";
        dbDelta($sql_redirect_logs);

        // Log database creation
        // Ofast_X_Logger::info('Database tables created successfully');
    }

    /**
     * Set default plugin options
     */
    private static function set_default_options()
    {
        $default_options = array(
            'ofastx_license_status' => 'inactive',
            'ofastx_license_key' => '',
            'ofastx_modules_enabled' => array(
                'email' => true,              // Active
                'debug' => true,              // Active
                'smtp' => false,              // Coming soon
                'newsletter' => false,        // Coming soon
                'contact' => false,           // Coming soon
                'seo' => false,               // Coming soon
                'analytics' => false,         // Coming soon
                'backup' => false,            // Coming soon
                'security' => false,          // Coming soon
                'performance' => false,       // Coming soon
                'woocommerce' => false,       // Coming soon
                'learndash' => false          // Coming soon
            ),
            'ofastx_email_retention_days' => 90
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
    private static function clear_scheduled_events()
    {
        // Clear email scheduler events
        $timestamp = wp_next_scheduled('ofast_scheduled_email_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ofast_scheduled_email_event');
        }

        // Clear daily cleanup
        $timestamp = wp_next_scheduled('ofast_daily_cleanup');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ofast_daily_cleanup');
        }

        // Clear license check
        $timestamp = wp_next_scheduled('ofastx_daily_license_check');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ofastx_daily_license_check');
        }

        // Ofast_X_Logger::info('Scheduled events cleared');
    }

    /**
     * Log activation
     */
    private static function log_activation()
    {
        // Simple activation log
        error_log('Ofast X Plugin Activated - Version: ' . OFAST_X_VERSION);
    }

    /**
     * Log deactivation
     */
    private static function log_deactivation()
    {
        // Simple deactivation log
        error_log('Ofast X Plugin Deactivated');
    }
}
