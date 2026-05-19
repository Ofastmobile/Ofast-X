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

        // 2. Code Snippets Table
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

        // 3. Contact Forms Table
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

        // 4. Form Submissions Table
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

        // 5. Rate Limits Table (for more robust rate limiting than transients)
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

        // 6. Redirects Table
        $table_redirects = $wpdb->prefix . 'ofast_redirects';
        $sql_redirects = "CREATE TABLE IF NOT EXISTS {$table_redirects} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_url VARCHAR(500) NOT NULL,
            target_url VARCHAR(500) NOT NULL,
            type ENUM('301', '302', '307') DEFAULT '301',
            is_regex TINYINT(1) DEFAULT 0,
            priority INT(11) DEFAULT 10,
            hits INT(11) DEFAULT 0,
            last_accessed DATETIME,
            active TINYINT(1) DEFAULT 1,
            created_at DATETIME,
            created_by BIGINT(20),
            PRIMARY KEY (id),
            KEY idx_source (source_url(255)),
            KEY idx_active (active),
            KEY idx_priority (priority),
            KEY idx_hits (hits)
        ) {$charset_collate};";
        dbDelta($sql_redirects);
        update_option('ofast_redirects_priority_schema', '1', false);

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

        // 9. Email Drafts Table
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

        // 10. Email Campaigns Queue Table
        $table_campaigns = $wpdb->prefix . 'ofast_email_campaigns';
        $sql_campaigns = "CREATE TABLE IF NOT EXISTS {$table_campaigns} (
            id            bigint(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
            subject       varchar(255) NOT NULL,
            body          longtext     NOT NULL,
            recipient_ids longtext     NOT NULL,
            status        varchar(20)  NOT NULL DEFAULT 'queued',
            strategy      varchar(20)  NOT NULL DEFAULT 'rapid',
            total         int(11)      NOT NULL DEFAULT 0,
            sent          int(11)      NOT NULL DEFAULT 0,
            failed        int(11)      NOT NULL DEFAULT 0,
            position      int(11)      NOT NULL DEFAULT 0,
            lock_expires  datetime     DEFAULT NULL,
            next_run      datetime     DEFAULT NULL,
            created_by    bigint(20)   UNSIGNED NOT NULL,
            created_at    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at    datetime     DEFAULT NULL,
            completed_at  datetime     DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_strategy (strategy),
            KEY idx_next_run (next_run)
        ) {$charset_collate};";
        dbDelta($sql_campaigns);

        // 11. SMTP Log Table
        $table_smtp_log = $wpdb->prefix . 'ofast_smtp_log';
        $sql_smtp_log = "CREATE TABLE IF NOT EXISTS {$table_smtp_log} (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            to_email    VARCHAR(255) NOT NULL DEFAULT '',
            subject     VARCHAR(255) NOT NULL DEFAULT '',
            body        LONGTEXT NULL,
            headers     LONGTEXT NULL,
            status      VARCHAR(20) NOT NULL DEFAULT 'pending',
            error_message TEXT NULL,
            sent_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sent_at    (sent_at),
            KEY idx_status     (status),
            KEY idx_campaign   (campaign_id)
        ) {$charset_collate};";
        dbDelta($sql_smtp_log);
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
                'contact' => false,           // Coming soon
                'seo' => false,               // Coming soon
                'analytics' => false,         // Coming soon
                'backup' => false,            // Coming soon
                'security' => false,          // Coming soon
                'performance' => false,       // Coming soon
                'woocommerce' => false,       // Coming soon
                'learndash' => false          // Coming soon
            ),
            'ofast_email_retention_days'     => 30,
            'ofast_smtp_log_retention_days'  => 30,
            'ofast_spam_fail_open' => 0
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
        // Clear all ofast-related cron events (including those with args).
        $cron_array = _get_cron_array();
        if (is_array($cron_array)) {
            foreach ($cron_array as $timestamp => $hooks) {
                foreach ($hooks as $hook => $jobs) {
                    if (strpos($hook, 'ofast') === 0) {
                        foreach ($jobs as $job) {
                            $args = isset($job['args']) ? $job['args'] : array();
                            wp_unschedule_event($timestamp, $hook, $args);
                        }
                    }
                }
            }
        }
    }

    /**
     * Log activation
     */
    private static function log_activation()
    {
        error_log('Ofast X Plugin Activated - Version: ' . OFAST_X_VERSION);
    }

    /**
     * Log deactivation
     */
    private static function log_deactivation()
    {
        error_log('Ofast X Plugin Deactivated');
    }

    /**
     * Public alias — lets other classes trigger table upgrades on demand.
     * Called by the campaigns tab on first visit to migrate old schemas.
     */
    public static function run_upgrade_tables()
    {
        self::upgrade_tables();
    }

    /**
     * Upgrade tables - add missing columns for existing installations
     */
    public static function upgrade_tables()
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

        // Ensure language column supports HTML for legacy installs with older ENUM sets.
        $language_column = $wpdb->get_row("SHOW COLUMNS FROM {$table_snippets} LIKE 'language'");
        if ($language_column) {
            $language_type = strtolower((string) $language_column->Type);
            if (strpos($language_type, 'enum(') === 0 && strpos($language_type, "'html'") === false) {
                $wpdb->query("ALTER TABLE {$table_snippets} MODIFY COLUMN language ENUM('php', 'javascript', 'css', 'html') DEFAULT 'php'");
                error_log("Ofast X: Updated language enum to include 'html' in {$table_snippets}");
            }
        }

        // Redirects table upgrades
        $table_redirects = $wpdb->prefix . 'ofast_redirects';
        $redirects_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_redirects}'");
        if ($redirects_exists) {
            $redirect_columns = $wpdb->get_col("DESCRIBE {$table_redirects}", 0);
            if (!in_array('priority', $redirect_columns, true)) {
                $wpdb->query("ALTER TABLE {$table_redirects} ADD COLUMN priority INT(11) DEFAULT 10 AFTER is_regex");
                error_log("Ofast X: Added missing column 'priority' to {$table_redirects}");
                $redirect_columns[] = 'priority';
            }
            update_option('ofast_redirects_priority_schema', in_array('priority', $redirect_columns, true) ? '1' : '0', false);
        }

        // SMTP log table — add campaign_id column for existing installs
        $table_smtp = $wpdb->prefix . 'ofast_smtp_log';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_smtp ) ) ) {
            $smtp_cols = $wpdb->get_col( "DESCRIBE {$table_smtp}", 0 );

            if ( ! in_array( 'campaign_id', $smtp_cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$table_smtp} ADD COLUMN campaign_id BIGINT(20) UNSIGNED NULL DEFAULT NULL AFTER id" );
                $wpdb->query( "ALTER TABLE {$table_smtp} ADD INDEX idx_campaign (campaign_id)" );
                error_log( 'Ofast X: Added campaign_id column to ofast_smtp_log' );
            }
        }

        // Campaigns queue table — create if missing OR upgrade if columns are missing
        $table_campaigns = $wpdb->prefix . 'ofast_email_campaigns';
        $campaigns_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_campaigns ) );

        if ( ! $campaigns_exists ) {
            // Table does not exist — create it fresh
            $charset_collate = $wpdb->get_charset_collate();
            $sql_campaigns = "CREATE TABLE IF NOT EXISTS {$table_campaigns} (
                id            bigint(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
                subject       varchar(255) NOT NULL,
                body          longtext     NOT NULL,
                recipient_ids longtext     NOT NULL DEFAULT '',
                status        varchar(20)  NOT NULL DEFAULT 'queued',
                strategy      varchar(20)  NOT NULL DEFAULT 'rapid',
                total         int(11)      NOT NULL DEFAULT 0,
                sent          int(11)      NOT NULL DEFAULT 0,
                failed        int(11)      NOT NULL DEFAULT 0,
                position      int(11)      NOT NULL DEFAULT 0,
                lock_expires      datetime     DEFAULT NULL,
                next_run          datetime     DEFAULT NULL,
                created_by        bigint(20)   UNSIGNED NOT NULL DEFAULT 0,
                created_at        datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                started_at        datetime     DEFAULT NULL,
                completed_at      datetime     DEFAULT NULL,
                failed_recipients longtext     DEFAULT NULL,
                pending_recipients longtext    DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_status (status),
                KEY idx_strategy (strategy),
                KEY idx_next_run (next_run)
            ) {$charset_collate};";
            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
            dbDelta( $sql_campaigns );
            error_log( 'Ofast X: Created ofast_email_campaigns table.' );
        } else {
            // Table exists — add any missing columns (handles old schema from previous build)
            $camp_cols = $wpdb->get_col( "DESCRIBE {$table_campaigns}", 0 );

            $missing_cols = array(
                'recipient_ids' => "ALTER TABLE {$table_campaigns} ADD COLUMN recipient_ids longtext NOT NULL DEFAULT '' AFTER body",
                'strategy'      => "ALTER TABLE {$table_campaigns} ADD COLUMN strategy varchar(20) NOT NULL DEFAULT 'rapid' AFTER status",
                'total'         => "ALTER TABLE {$table_campaigns} ADD COLUMN total int(11) NOT NULL DEFAULT 0 AFTER strategy",
                'sent'          => "ALTER TABLE {$table_campaigns} ADD COLUMN sent int(11) NOT NULL DEFAULT 0 AFTER total",
                'failed'        => "ALTER TABLE {$table_campaigns} ADD COLUMN failed int(11) NOT NULL DEFAULT 0 AFTER sent",
                'position'      => "ALTER TABLE {$table_campaigns} ADD COLUMN position int(11) NOT NULL DEFAULT 0 AFTER failed",
                'lock_expires'  => "ALTER TABLE {$table_campaigns} ADD COLUMN lock_expires datetime DEFAULT NULL AFTER position",
                'next_run'      => "ALTER TABLE {$table_campaigns} ADD COLUMN next_run datetime DEFAULT NULL AFTER lock_expires",
                'created_by'    => "ALTER TABLE {$table_campaigns} ADD COLUMN created_by bigint(20) UNSIGNED NOT NULL DEFAULT 0 AFTER next_run",
                'started_at'        => "ALTER TABLE {$table_campaigns} ADD COLUMN started_at datetime DEFAULT NULL AFTER created_at",
                'completed_at'      => "ALTER TABLE {$table_campaigns} ADD COLUMN completed_at datetime DEFAULT NULL AFTER started_at",
                'failed_recipients' => "ALTER TABLE {$table_campaigns} ADD COLUMN failed_recipients longtext DEFAULT NULL AFTER completed_at",
                'pending_recipients'=> "ALTER TABLE {$table_campaigns} ADD COLUMN pending_recipients longtext DEFAULT NULL AFTER failed_recipients",
            );

            foreach ( $missing_cols as $col => $sql ) {
                if ( ! in_array( $col, $camp_cols, true ) ) {
                    $wpdb->query( $sql );
                    error_log( "Ofast X: Added missing column '{$col}' to {$table_campaigns}" );
                }
            }

            // Ensure idx_strategy and idx_next_run indexes exist
            $indexes = $wpdb->get_col( "SHOW INDEX FROM {$table_campaigns} WHERE Key_name IN ('idx_strategy','idx_next_run')", 2 );
            if ( ! in_array( 'idx_strategy', $indexes, true ) ) {
                $wpdb->query( "ALTER TABLE {$table_campaigns} ADD INDEX idx_strategy (strategy)" );
            }
            if ( ! in_array( 'idx_next_run', $indexes, true ) ) {
                $wpdb->query( "ALTER TABLE {$table_campaigns} ADD INDEX idx_next_run (next_run)" );
            }
        }
    }

}
