<?php

/**
 * Ofast Email Campaign Model
 *
 * Handles all DB operations for the email campaigns queue table.
 * Provides atomic claim locking to prevent race conditions between
 * concurrent loopback workers or cron events.
 *
 * Table: {prefix}ofast_email_campaigns
 * Statuses: queued | processing | completed | failed | paused | cancelled
 * Strategies: rapid (loopback) | slow (wp-cron)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ofast_Email_Campaign {

    const TABLE = 'ofast_email_campaigns';

    const STATUS_QUEUED     = 'queued';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED  = 'completed';
    const STATUS_FAILED     = 'failed';
    const STATUS_PAUSED     = 'paused';
    const STATUS_CANCELLED  = 'cancelled';

    const STRATEGY_RAPID = 'rapid';
    const STRATEGY_SLOW  = 'slow';

    /** Lock TTL in minutes — if a worker dies, another can pick up after this. */
    const LOCK_TTL_MINUTES = 5;

    // ─────────────────────────────────────────────
    //  CREATE
    // ─────────────────────────────────────────────

    /**
     * Insert a new campaign row and return its ID.
     *
     * @param array $args {
     *   @type string $subject       Email subject.
     *   @type string $body          Email body HTML.
     *   @type array  $recipient_ids Mixed array of WP user IDs (int) and raw email addresses (string).
     *   @type string $strategy      'rapid' or 'slow'.
     *   @type int    $created_by    Admin user ID.
     *   @type string $next_run      Optional MySQL datetime for the first send.
     * }
     * @return int|false New campaign ID or false on failure.
     */
    public static function create( array $args ) {
        global $wpdb;

        $recipient_ids = isset( $args['recipient_ids'] ) ? (array) $args['recipient_ids'] : array();
        $strategy      = isset( $args['strategy'] ) && $args['strategy'] === self::STRATEGY_SLOW
            ? self::STRATEGY_SLOW
            : self::STRATEGY_RAPID;
        $next_run      = ! empty( $args['next_run'] ) && strtotime( $args['next_run'] )
            ? sanitize_text_field( $args['next_run'] )
            : current_time( 'mysql' );

        $data = array(
            'subject'       => sanitize_text_field( $args['subject'] ?? '' ),
            'body'          => $args['body'] ?? '',
            'recipient_ids' => wp_json_encode( array_values( $recipient_ids ) ),
            'status'        => self::STATUS_QUEUED,
            'strategy'      => $strategy,
            'total'         => count( $recipient_ids ),
            'sent'          => 0,
            'failed'        => 0,
            'position'      => 0,
            'lock_expires'  => null,
            'next_run'      => $next_run,
            'created_by'    => absint( $args['created_by'] ?? get_current_user_id() ),
            'created_at'    => current_time( 'mysql' ),
        );

        $result = $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            $data,
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
        );

        if ( $result === false ) {
            error_log( 'Ofast Email Campaign: Failed to create campaign — ' . $wpdb->last_error );
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    // ─────────────────────────────────────────────
    //  READ
    // ─────────────────────────────────────────────

    /**
     * Get a campaign row by ID.
     *
     * @param int $id
     * @return object|null
     */
    public static function get( int $id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
    }

    /**
     * Get the next claimable campaign for a given strategy.
     * A campaign is claimable if:
     *   - status = 'queued'
     *   - strategy matches
     *   - next_run <= NOW()
     *   - lock_expires IS NULL or lock_expires < NOW() (stale lock recovery)
     *
     * @param string $strategy 'rapid' or 'slow'
     * @return object|null
     */
    public static function get_next_claimable( string $strategy ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $now   = current_time( 'mysql' );

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE  status   = %s
               AND  strategy = %s
               AND  next_run <= %s
               AND  (lock_expires IS NULL OR lock_expires < %s)
             ORDER BY created_at ASC
             LIMIT 1",
            self::STATUS_QUEUED,
            $strategy,
            $now,
            $now
        ) );
    }

    /**
     * Get all campaigns, paginated, for the Campaigns tab.
     *
     * @param array $args { page, per_page, status, strategy }
     * @return array { items: array, total: int }
     */
    public static function get_all( array $args = array() ) {
        global $wpdb;
        $table    = $wpdb->prefix . self::TABLE;
        $page     = max( 1, intval( $args['page'] ?? 1 ) );
        $per_page = min( 100, max( 10, intval( $args['per_page'] ?? 20 ) ) );
        $offset   = ( $page - 1 ) * $per_page;

        $where  = array( '1=1' );
        $values = array();

        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $values[] = sanitize_key( $args['status'] );
        }

        $where_clause = implode( ' AND ', $where );

        $total = (int) $wpdb->get_var(
            $values
                ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}", ...$values )
                : "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}"
        );

        $items = $values
            ? $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                ...array_merge( $values, array( $per_page, $offset ) )
            ) )
            : $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page, $offset
            ) );

        return array( 'items' => $items ?: array(), 'total' => $total );
    }

    // ─────────────────────────────────────────────
    //  ATOMIC CLAIM (Race-condition-safe)
    // ─────────────────────────────────────────────

    /**
     * Atomically claim a campaign for processing.
     *
     * Uses a single UPDATE with a WHERE clause that only matches if the row
     * has NOT already been claimed by another worker. Returns true only if
     * exactly 1 row was updated (meaning this worker won the race).
     *
     * @param int $campaign_id
     * @return bool True if this worker successfully claimed the campaign.
     */
    public static function atomic_claim( int $campaign_id ): bool {
        global $wpdb;
        $table      = $wpdb->prefix . self::TABLE;
        $now        = current_time( 'mysql' );
        $ttl        = self::LOCK_TTL_MINUTES;
        $lock_until = gmdate( 'Y-m-d H:i:s', strtotime( "+{$ttl} minutes", strtotime( $now ) ) );

        $affected = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET    status       = %s,
                    lock_expires = %s,
                    started_at   = COALESCE(started_at, %s)
             WHERE  id           = %d
               AND  status       = %s
               AND  (lock_expires IS NULL OR lock_expires < %s)",
            self::STATUS_PROCESSING,
            $lock_until,
            $now,
            $campaign_id,
            self::STATUS_QUEUED,
            $now
        ) );

        return (int) $affected === 1;
    }

    // ─────────────────────────────────────────────
    //  UPDATE (after a batch)
    // ─────────────────────────────────────────────

    /**
     * After a batch is sent, update progress and re-queue (or mark complete/failed).
     *
     * @param int    $campaign_id
     * @param int    $new_sent       Incremental sends this batch.
     * @param int    $new_failed     Incremental failures this batch.
     * @param int    $new_position   New position pointer in recipient_ids array.
     * @param string $next_run_at    MySQL datetime for when the next batch should run.
     * @param array  $failed_emails  Email addresses that failed this batch (accumulated).
     */
    public static function update_progress( int $campaign_id, int $new_sent, int $new_failed, int $new_position, string $next_run_at, array $failed_emails = array() ) {
        global $wpdb;
        $table    = $wpdb->prefix . self::TABLE;
        $campaign = self::get( $campaign_id );

        if ( ! $campaign ) {
            return;
        }

        $total_sent   = (int) ( $campaign->sent   ?? 0 ) + $new_sent;
        $total_failed = (int) ( $campaign->failed  ?? 0 ) + $new_failed;
        $is_done      = $new_position >= (int) ( $campaign->total ?? $campaign->total_recipients ?? 0 );

        // Accumulate failed recipients across batches
        $existing_failed = array();
        if ( ! empty( $campaign->failed_recipients ) ) {
            $decoded = json_decode( $campaign->failed_recipients, true );
            if ( is_array( $decoded ) ) {
                $existing_failed = $decoded;
            }
        }
        $all_failed_json = wp_json_encode( array_unique( array_merge( $existing_failed, $failed_emails ) ) );

        if ( $is_done ) {
            $status    = $total_failed === (int) ( $campaign->total ?? $campaign->total_recipients ?? 0 )
                ? self::STATUS_FAILED
                : self::STATUS_COMPLETED;
            $completed = current_time( 'mysql' );
            $wpdb->update(
                $table,
                array(
                    'status'            => $status,
                    'sent'              => $total_sent,
                    'failed'            => $total_failed,
                    'position'          => $new_position,
                    'lock_expires'      => null,
                    'completed_at'      => $completed,
                    'failed_recipients' => $all_failed_json,
                ),
                array( 'id' => $campaign_id ),
                array( '%s', '%d', '%d', '%d', '%s', '%s', '%s' ),
                array( '%d' )
            );
        } else {
            $wpdb->update(
                $table,
                array(
                    'status'            => self::STATUS_QUEUED,
                    'sent'              => $total_sent,
                    'failed'            => $total_failed,
                    'position'          => $new_position,
                    'lock_expires'      => null,
                    'next_run'          => $next_run_at,
                    'failed_recipients' => $all_failed_json,
                ),
                array( 'id' => $campaign_id ),
                array( '%s', '%d', '%d', '%d', '%s', '%s', '%s' ),
                array( '%d' )
            );
        }
    }

    // ─────────────────────────────────────────────
    //  STATUS CHANGES
    // ─────────────────────────────────────────────

    /**
     * Pause a queued campaign. Cannot pause processing or completed campaigns.
     *
     * @param int $campaign_id
     * @param int $requested_by Admin user ID making the request.
     * @return bool
     */
    public static function pause( int $campaign_id, int $requested_by ): bool {
        global $wpdb;
        $table    = $wpdb->prefix . self::TABLE;
        $campaign = self::get( $campaign_id );

        if ( ! $campaign || (int) $campaign->created_by !== $requested_by ) {
            return false;
        }

        if ( ! in_array( $campaign->status, array( self::STATUS_QUEUED, self::STATUS_PROCESSING ), true ) ) {
            return false;
        }

        $affected = $wpdb->update(
            $table,
            array( 'status' => self::STATUS_PAUSED, 'lock_expires' => null ),
            array( 'id' => $campaign_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        return $affected !== false;
    }

    /**
     * Resume a paused campaign.
     *
     * @param int $campaign_id
     * @param int $requested_by
     * @return bool
     */
    public static function resume( int $campaign_id, int $requested_by ): bool {
        global $wpdb;
        $table    = $wpdb->prefix . self::TABLE;
        $campaign = self::get( $campaign_id );

        if ( ! $campaign || (int) $campaign->created_by !== $requested_by ) {
            return false;
        }

        if ( $campaign->status !== self::STATUS_PAUSED ) {
            return false;
        }

        $affected = $wpdb->update(
            $table,
            array( 'status' => self::STATUS_QUEUED, 'next_run' => current_time( 'mysql' ) ),
            array( 'id' => $campaign_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        return $affected !== false;
    }

    /**
     * Cancel a campaign. Marks it as cancelled regardless of status.
     *
     * @param int $campaign_id
     * @param int $requested_by
     * @return bool
     */
    public static function cancel( int $campaign_id, int $requested_by ): bool {
        global $wpdb;
        $table    = $wpdb->prefix . self::TABLE;
        $campaign = self::get( $campaign_id );

        if ( ! $campaign ) {
            return false;
        }

        // Admins can cancel any campaign; non-admin can only cancel their own
        if ( ! current_user_can( 'manage_options' ) && (int) $campaign->created_by !== $requested_by ) {
            return false;
        }

        if ( in_array( $campaign->status, array( self::STATUS_COMPLETED, self::STATUS_CANCELLED ), true ) ) {
            return false;
        }

        $affected = $wpdb->update(
            $table,
            array( 'status' => self::STATUS_CANCELLED, 'lock_expires' => null, 'completed_at' => current_time( 'mysql' ) ),
            array( 'id' => $campaign_id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );

        return $affected !== false;
    }

    // ─────────────────────────────────────────────
    //  HELPERS
    // ─────────────────────────────────────────────

    /**
     * Decode recipient_ids JSON to a PHP array.
     *
     * @param object $campaign
     * @return array
     */
    public static function decode_recipients( $campaign ): array {
        if ( empty( $campaign->recipient_ids ) ) {
            return array();
        }
        $decoded = json_decode( $campaign->recipient_ids, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Get a progress percentage (0–100).
     * Handles legacy rows that may use total_recipients instead of total,
     * or may be missing the position column entirely.
     *
     * @param object $campaign
     * @return int
     */
    public static function get_progress( $campaign ): int {
        // Handle legacy column name (total_recipients → total).
        // Use property_exists() to avoid PHP warnings on old-schema stdClass rows.
        $total    = (int) ( property_exists( $campaign, 'total' )    ? $campaign->total    : ( $campaign->total_recipients ?? 0 ) );
        $position = (int) ( property_exists( $campaign, 'position' ) ? $campaign->position : ( $campaign->sent ?? 0 ) );

        if ( $total === 0 ) {
            return 0;
        }

        return min( 100, (int) round( ( $position / $total ) * 100 ) );
    }

    /**
     * Delete old completed/cancelled campaigns older than N days.
     *
     * @param int $days Default 30.
     */
    public static function cleanup_old( int $days = 30 ) {
        global $wpdb;
        $table     = $wpdb->prefix . self::TABLE;
        $threshold = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table}
             WHERE  status IN ('completed','cancelled','failed')
               AND  created_at < %s",
            $threshold
        ) );
    }

    /**
     * Permanently delete a single campaign row.
     * Only permitted for terminal statuses (completed / cancelled / failed).
     *
     * @param int $campaign_id
     * @return bool True if deleted, false if not found or still active.
     */
    public static function delete( int $campaign_id ): bool {
        global $wpdb;
        $table    = $wpdb->prefix . self::TABLE;
        $campaign = self::get( $campaign_id );

        if ( ! $campaign ) {
            return false;
        }

        // Block deletion of active campaigns
        $terminal = array( self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_FAILED );
        if ( ! in_array( $campaign->status ?? '', $terminal, true ) ) {
            return false;
        }

        $deleted = $wpdb->delete(
            $table,
            array( 'id' => $campaign_id ),
            array( '%d' )
        );

        return $deleted !== false && $deleted > 0;
    }
}
