<?php

/**
 * Ofast Email Smart Retries (Pro Only)
 *
 * When a campaign email fails, Pro users get automatic retries at escalating
 * intervals (exponential backoff): 5 min → 15 min → 1 hour. Max 3 attempts.
 *
 * Uses a dedicated lightweight DB table (`{prefix}ofast_email_retries`).
 * A 5-minute WP-Cron processes due retries. Daily cron cleans records >7 days.
 *
 * Free users: failed emails are counted as immediate failures (no retries).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ofast_Email_Retry {

    /** DB table name (without prefix). */
    const TABLE = 'ofast_email_retries';

    /** Maximum retry attempts per failed email. */
    const MAX_RETRIES = 3;

    /** Backoff schedule in seconds: 5 min, 15 min, 1 hour. */
    const BACKOFF = array( 300, 900, 3600 );

    // Status constants
    const STATUS_PENDING   = 'pending';
    const STATUS_SUCCESS   = 'success';
    const STATUS_EXHAUSTED = 'exhausted';

    // ─────────────────────────────────────────────
    //  QUEUE A FAILED EMAIL FOR RETRY
    // ─────────────────────────────────────────────

    /**
     * Queue a failed campaign email for smart retry.
     *
     * Only works for Pro users — free tier returns false immediately.
     * Prevents duplicate entries for the same campaign + email combo.
     *
     * @param int    $campaign_id Parent campaign ID.
     * @param string $email       Recipient email address.
     * @param string $subject     Email subject line.
     * @param string $body        Raw email body (pre-template).
     * @param string $error       Error message from the initial failure.
     * @return bool True if queued, false if skipped (free tier or duplicate).
     */
    public static function queue( int $campaign_id, string $email, string $subject, string $body, string $error ): bool {
        // Only Pro users get smart retries
        if ( ! function_exists( 'ofast_toolkit_is_pro' ) || ! ofast_toolkit_is_pro() ) {
            return false;
        }

        global $wpdb;
        self::ensure_table();
        $table = $wpdb->prefix . self::TABLE;

        // Prevent duplicate entries for the same campaign + email
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND email = %s AND status = %s",
            $campaign_id, $email, self::STATUS_PENDING
        ) );

        if ( (int) $existing > 0 ) {
            return false;
        }

        // Schedule first retry at BACKOFF[0] = 5 minutes from now
        $next_retry = wp_date( 'Y-m-d H:i:s', time() + self::BACKOFF[0], wp_timezone() );

        $inserted = $wpdb->insert( $table, array(
            'campaign_id'   => $campaign_id,
            'email'         => sanitize_email( $email ),
            'subject'       => sanitize_text_field( $subject ),
            'body'          => $body,
            'last_error'    => sanitize_text_field( substr( $error, 0, 500 ) ),
            'attempt'       => 0,
            'status'        => self::STATUS_PENDING,
            'next_retry_at' => $next_retry,
            'created_at'    => current_time( 'mysql' ),
        ), array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ) );

        return (bool) $inserted;
    }

    // ─────────────────────────────────────────────
    //  PROCESS DUE RETRIES (CRON CALLBACK)
    // ─────────────────────────────────────────────

    /**
     * Process retries that are due. Called every 5 minutes via WP-Cron.
     *
     * Fetches up to 20 pending retries whose next_retry_at <= now,
     * attempts to send each one, and updates status accordingly.
     */
    public static function process_due_retries(): void {
        // Only Pro users have retries
        if ( ! function_exists( 'ofast_toolkit_is_pro' ) || ! ofast_toolkit_is_pro() ) {
            return;
        }

        global $wpdb;
        self::ensure_table();
        $table = $wpdb->prefix . self::TABLE;
        $now   = current_time( 'mysql' );

        // Fetch up to 20 due retries per cron run (lightweight, won't overload shared hosting)
        $retries = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = %s AND next_retry_at <= %s ORDER BY next_retry_at ASC LIMIT 20",
            self::STATUS_PENDING, $now
        ) );

        if ( empty( $retries ) ) {
            return;
        }

        // Load dependencies for sending
        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-template.php';
        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email.php';

        $headers = Ofast_X_Email::get_safe_email_headers();

        foreach ( $retries as $retry ) {
            $attempt = (int) $retry->attempt + 1;
            $html    = Ofast_X_Email_Template::get_template( $retry->body );

            if ( wp_mail( $retry->email, $retry->subject, $html, $headers ) ) {
                // ✅ Success — mark as delivered
                $wpdb->update(
                    $table,
                    array( 'status' => self::STATUS_SUCCESS, 'attempt' => $attempt ),
                    array( 'id' => $retry->id ),
                    array( '%s', '%d' ),
                    array( '%d' )
                );
                error_log( 'Ofast Smart Retry: Email to ' . $retry->email . ' succeeded on attempt #' . $attempt . ' (campaign #' . $retry->campaign_id . ')' );
            } else {
                // ❌ Still failing
                if ( $attempt >= self::MAX_RETRIES ) {
                    // All attempts exhausted — mark as permanently failed
                    $wpdb->update(
                        $table,
                        array(
                            'status'     => self::STATUS_EXHAUSTED,
                            'attempt'    => $attempt,
                            'last_error' => 'All ' . self::MAX_RETRIES . ' retry attempts exhausted',
                        ),
                        array( 'id' => $retry->id ),
                        array( '%s', '%d', '%s' ),
                        array( '%d' )
                    );
                    error_log( 'Ofast Smart Retry: Email to ' . $retry->email . ' EXHAUSTED after ' . $attempt . ' attempts (campaign #' . $retry->campaign_id . ')' );
                } else {
                    // Schedule next retry with escalating delay
                    $next_delay = isset( self::BACKOFF[ $attempt ] ) ? self::BACKOFF[ $attempt ] : end( self::BACKOFF );
                    $next_at    = wp_date( 'Y-m-d H:i:s', time() + $next_delay, wp_timezone() );
                    $wpdb->update(
                        $table,
                        array( 'attempt' => $attempt, 'next_retry_at' => $next_at ),
                        array( 'id' => $retry->id ),
                        array( '%d', '%s' ),
                        array( '%d' )
                    );
                    error_log( 'Ofast Smart Retry: Email to ' . $retry->email . ' failed attempt #' . $attempt . '. Next retry at ' . $next_at );
                }
            }
        }
    }

    // ─────────────────────────────────────────────
    //  STATS & DISPLAY
    // ─────────────────────────────────────────────

    /**
     * Get retry statistics for a specific campaign.
     *
     * @param int $campaign_id
     * @return array { pending: int, success: int, exhausted: int, total: int }
     */
    public static function get_campaign_stats( int $campaign_id ): array {
        global $wpdb;
        self::ensure_table();
        $table = $wpdb->prefix . self::TABLE;

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) as cnt FROM {$table} WHERE campaign_id = %d GROUP BY status",
            $campaign_id
        ) );

        $stats = array( 'pending' => 0, 'success' => 0, 'exhausted' => 0, 'total' => 0 );
        foreach ( $results as $row ) {
            if ( isset( $stats[ $row->status ] ) ) {
                $stats[ $row->status ] = (int) $row->cnt;
            }
            $stats['total'] += (int) $row->cnt;
        }

        return $stats;
    }

    /**
     * Check if any retries are currently active (for admin notice).
     *
     * @return array|null Active retry summary or null if none.
     */
    public static function get_active_retries_notice(): ?array {
        if ( ! function_exists( 'ofast_toolkit_is_pro' ) || ! ofast_toolkit_is_pro() ) {
            return null;
        }

        global $wpdb;
        self::ensure_table();
        $table = $wpdb->prefix . self::TABLE;

        $pending_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = %s",
            self::STATUS_PENDING
        ) );

        if ( $pending_count <= 0 ) {
            return null;
        }

        // Get the next retry time
        $next_retry = $wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(next_retry_at) FROM {$table} WHERE status = %s",
            self::STATUS_PENDING
        ) );

        return array(
            'count'      => $pending_count,
            'next_retry' => $next_retry,
        );
    }

    // ─────────────────────────────────────────────
    //  MAINTENANCE
    // ─────────────────────────────────────────────

    /**
     * Cleanup retry records older than $days.
     * Called daily via WP-Cron.
     *
     * @param int $days Records older than this are deleted. Default 7.
     */
    public static function cleanup_old( int $days = 7 ): void {
        global $wpdb;
        self::ensure_table();
        $table = $wpdb->prefix . self::TABLE;

        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );

        if ( $deleted > 0 ) {
            error_log( 'Ofast Smart Retry: Cleaned up ' . $deleted . ' retry records older than ' . $days . ' days.' );
        }
    }

    // ─────────────────────────────────────────────
    //  TABLE MANAGEMENT
    // ─────────────────────────────────────────────

    /**
     * Ensure the retries table exists.
     *
     * Uses a static flag to avoid running dbDelta more than once per request.
     * Table is lightweight: avg ~20 rows at any time, cleaned up after 7 days.
     */
    private static function ensure_table(): void {
        static $checked = false;
        if ( $checked ) {
            return;
        }
        $checked = true;

        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        // Quick check — if table exists, skip dbDelta entirely
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table ) {
            return;
        }

        $sql = "CREATE TABLE {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            email varchar(255) NOT NULL DEFAULT '',
            subject varchar(255) NOT NULL DEFAULT '',
            body longtext NOT NULL,
            last_error text NOT NULL,
            attempt tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            next_retry_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY campaign_status (campaign_id, status),
            KEY status_retry (status, next_retry_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}
