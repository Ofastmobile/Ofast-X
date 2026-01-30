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

        // Upgrade tables - add any missing columns for existing installations
        self::upgrade_tables();

        // Set default options (including module states)
        self::set_default_options();

        // Flush rewrite rules
        flush_rewrite_rules();

        // Log activation
        self::log_activation();

        // Set redirect flag for first-time activation
        add_option('ofast_x_do_activation_redirect', true);
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
            body LONGTEXT,
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
            language ENUM('php', 'javascript', 'css', 'html') DEFAULT 'php',
            active TINYINT(1) DEFAULT 0,
            category VARCHAR(100) DEFAULT '',
            tags TEXT,
            scope ENUM('global', 'admin', 'frontend') DEFAULT 'global',
            location ENUM('header', 'body', 'footer') DEFAULT 'footer',
            target_type ENUM('all', 'homepage', 'post_type', 'page_ids', 'url_contains') DEFAULT 'all',
            target_value TEXT,
            run_once TINYINT(1) DEFAULT 0,
            executed_at DATETIME DEFAULT NULL,
            priority INT(11) DEFAULT 10,
            status VARCHAR(20) DEFAULT 'active',
            trashed_at DATETIME DEFAULT NULL,
            created_at DATETIME,
            updated_at DATETIME,
            created_by BIGINT(20),
            PRIMARY KEY (id),
            KEY idx_active (active),
            KEY idx_language (language),
            KEY idx_scope (scope),
            KEY idx_category (category)
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

        // 6. Rate Limits Table (for more robust rate limiting than transients)
        $table_rate_limits = $wpdb->prefix . 'ofast_rate_limits';
        $sql_rate_limits = "CREATE TABLE IF NOT EXISTS {$table_rate_limits} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            action_type VARCHAR(50) NOT NULL,
            attempts INT(11) DEFAULT 1,
            window_start DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_user_action (user_id, action_type),
            KEY idx_window_start (window_start)
        ) {$charset_collate};";
        dbDelta($sql_rate_limits);

        // 7. Redirects Table
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

        // 8. Snippet Revisions Table (for revision history)
        $table_snippet_revisions = $wpdb->prefix . 'ofast_snippet_revisions';
        $sql_snippet_revisions = "CREATE TABLE IF NOT EXISTS {$table_snippet_revisions} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            snippet_id BIGINT(20) UNSIGNED NOT NULL,
            code LONGTEXT NOT NULL,
            changed_at DATETIME NOT NULL,
            changed_by BIGINT(20) UNSIGNED,
            PRIMARY KEY (id),
            KEY idx_snippet_id (snippet_id),
            KEY idx_changed_at (changed_at)
        ) {$charset_collate};";
        dbDelta($sql_snippet_revisions);

        // 9. Notification Log Table
        $table_notification_log = $wpdb->prefix . 'ofast_notification_log';
        $sql_notification_log = "CREATE TABLE IF NOT EXISTS {$table_notification_log} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(50) NOT NULL,
            channel VARCHAR(30) NOT NULL,
            recipient VARCHAR(255),
            status ENUM('sent', 'failed', 'skipped', 'logged') DEFAULT 'logged',
            message LONGTEXT,
            response TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_event_type (event_type),
            KEY idx_channel (channel),
            KEY idx_status (status),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_notification_log);

        // 10. Email Drafts Table
        $table_email_drafts = $wpdb->prefix . 'ofast_email_drafts';
        $sql_email_drafts = "CREATE TABLE IF NOT EXISTS {$table_email_drafts} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_id BIGINT(20) UNSIGNED NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body LONGTEXT,
            roles TEXT,
            user_ids TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_admin_id (admin_id),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_email_drafts);

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

    /**
     * Upgrade tables - add missing columns for existing installations
     */
    private static function upgrade_tables()
    {
        global $wpdb;

        // Snippets table upgrades
        $table_snippets = $wpdb->prefix . 'ofast_snippets';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_snippets}'");
        if (!$table_exists) {
            return;
        }

        // Get existing columns
        $columns = $wpdb->get_col("DESCRIBE {$table_snippets}", 0);

        // List of columns that should exist
        $required_columns = array(
            'location' => "ALTER TABLE {$table_snippets} ADD COLUMN location ENUM('header', 'body', 'footer') DEFAULT 'footer' AFTER scope",
            'target_type' => "ALTER TABLE {$table_snippets} ADD COLUMN target_type ENUM('all', 'homepage', 'post_type', 'page_ids', 'url_contains') DEFAULT 'all' AFTER location",
            'target_value' => "ALTER TABLE {$table_snippets} ADD COLUMN target_value TEXT AFTER target_type",
            'run_once' => "ALTER TABLE {$table_snippets} ADD COLUMN run_once TINYINT(1) DEFAULT 0 AFTER target_value",
            'executed_at' => "ALTER TABLE {$table_snippets} ADD COLUMN executed_at DATETIME DEFAULT NULL AFTER run_once",
            'priority' => "ALTER TABLE {$table_snippets} ADD COLUMN priority INT(11) DEFAULT 10 AFTER executed_at",
            'status' => "ALTER TABLE {$table_snippets} ADD COLUMN status VARCHAR(20) DEFAULT 'active' AFTER priority",
            'trashed_at' => "ALTER TABLE {$table_snippets} ADD COLUMN trashed_at DATETIME DEFAULT NULL AFTER status",
            'category' => "ALTER TABLE {$table_snippets} ADD COLUMN category VARCHAR(100) DEFAULT '' AFTER active",
            'tags' => "ALTER TABLE {$table_snippets} ADD COLUMN tags TEXT AFTER category",
            'updated_at' => "ALTER TABLE {$table_snippets} ADD COLUMN updated_at DATETIME AFTER created_at",
            'created_by' => "ALTER TABLE {$table_snippets} ADD COLUMN created_by BIGINT(20) AFTER updated_at",
        );

        // Add missing columns
        foreach ($required_columns as $column => $alter_sql) {
            if (!in_array($column, $columns)) {
                $wpdb->query($alter_sql);
                error_log("Ofast X: Added missing column '{$column}' to {$table_snippets}");
            }
        }
    }
}
