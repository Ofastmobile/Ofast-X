<?php

/**
 * Ofast Email Processor
 *
 * Handles one batch of emails per invocation. Called by:
 *   - Rapid strategy: via non-blocking loopback HTTP request (AJAX endpoint).
 *   - Slow strategy:  via WP-Cron event.
 *
 * Workflow per call:
 *   1. Find a claimable campaign for the given strategy.
 *   2. Atomically claim it (prevents race conditions).
 *   3. Send the next `batch_size` emails.
 *   4. Update DB progress.
 *   5. If more remain AND strategy is rapid → sleep(delay) → fire next loopback.
 *   6. If more remain AND strategy is slow  → next_run set to NOW + 60 min.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-ofast-email-campaign.php';
require_once __DIR__ . '/class-ofast-email-template.php';

class Ofast_Email_Processor {

    /** Default emails per batch for SMTP/rapid mode. */
    const DEFAULT_RAPID_BATCH_SIZE = 50;

    /** Default emails per batch for PHP Mail/slow mode. */
    const DEFAULT_SLOW_BATCH_SIZE = 30;

    /** Default pause between rapid batches (seconds). */
    const DEFAULT_RAPID_DELAY = 3;

    /** Default pause between slow batches (minutes). */
    const DEFAULT_SLOW_DELAY_MINUTES = 60;

    // ─────────────────────────────────────────────
    //  STRATEGY ROUTER
    // ─────────────────────────────────────────────

    /**
     * Run one batch for the RAPID strategy (called from loopback endpoint).
     *
     * @param int|null $campaign_id  If provided, process that specific campaign.
     *                               If null, auto-pick the next claimable one.
     */
    public static function run_rapid( ?int $campaign_id = null ) {
        $campaign = self::claim_campaign( Ofast_Email_Campaign::STRATEGY_RAPID, $campaign_id );
        if ( ! $campaign ) {
            return; // Nothing to process right now
        }

        $batch_size = min( 500, max( 1, (int) get_option( 'ofast_email_batch_size', self::DEFAULT_RAPID_BATCH_SIZE ) ) );
        $delay      = min( 120, max( 0, (int) get_option( 'ofast_email_batch_delay', self::DEFAULT_RAPID_DELAY ) ) );

        $result = self::process_batch( $campaign, $batch_size );

        // Determine next_run — immediate for rapid mode
        $next_run = current_time( 'mysql' );

        Ofast_Email_Campaign::update_progress(
            (int) $campaign->id,
            $result['sent'],
            $result['failed'],
            $result['new_position'],
            $next_run,
            $result['failed_emails'] ?? array()
        );

        // If more emails remain, fire next loopback after the burst-protection delay
        if ( ! $result['is_done'] ) {
            sleep( $delay ); // Pause to avoid SMTP burst detection
            self::fire_loopback( (int) $campaign->id );
        }
    }

    /**
     * Run one batch for the SLOW strategy (called from WP-Cron hook).
     *
     * @param int $campaign_id
     */
    public static function run_slow( int $campaign_id ) {
        $campaign = self::claim_campaign( Ofast_Email_Campaign::STRATEGY_SLOW, $campaign_id );
        if ( ! $campaign ) {
            return;
        }

        $batch_size    = min( 500, max( 1, (int) get_option( 'ofast_email_batch_size', self::DEFAULT_SLOW_BATCH_SIZE ) ) );
        $delay_minutes = min( 1440, max( 1, (int) get_option( 'ofast_email_slow_delay_minutes', self::DEFAULT_SLOW_DELAY_MINUTES ) ) );

        $result = self::process_batch( $campaign, $batch_size );

        // For slow mode, next run is now + delay (respects hosting hourly limits)
        $next_run = current_time( 'mysql' );
        if ( ! $result['is_done'] ) {
            $next_run = wp_date( 'Y-m-d H:i:s', time() + ( $delay_minutes * MINUTE_IN_SECONDS ), wp_timezone() );
        }

        Ofast_Email_Campaign::update_progress(
            (int) $campaign->id,
            $result['sent'],
            $result['failed'],
            $result['new_position'],
            $next_run,
            $result['failed_emails'] ?? array()
        );

        // WP-Cron will pick it up again at next_run automatically via the scheduled hook
    }

    // ─────────────────────────────────────────────
    //  BATCH ENGINE
    // ─────────────────────────────────────────────

    /**
     * Process a single batch of emails from a campaign.
     *
     * @param object $campaign Campaign DB row.
     * @param int    $batch_size Number of emails to send this run.
     * @return array {
     *   @type int  $sent         Emails successfully sent this batch.
     *   @type int  $failed       Emails that failed this batch.
     *   @type int  $new_position New position pointer for the recipient_ids array.
     *   @type bool $is_done      Whether all recipients have been processed.
     * }
     */
    private static function process_batch( object $campaign, int $batch_size ): array {
        $all_recipients = Ofast_Email_Campaign::decode_recipients( $campaign );
        $position       = (int) $campaign->position;
        $total          = count( $all_recipients );

        // Slice the recipients for this batch
        $batch = array_slice( $all_recipients, $position, $batch_size );

        $sent          = 0;
        $failed        = 0;
        $failed_emails = array();

        // Get email-sending headers
        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email.php';
        $headers = Ofast_X_Email::get_safe_email_headers();

        foreach ( $batch as $recipient ) {
            $email   = '';
            $user    = null;

            // Recipient can be a WP user ID (int) or a raw email string
            if ( is_numeric( $recipient ) ) {
                $user  = get_userdata( (int) $recipient );
                $email = $user ? $user->user_email : '';
            } else {
                $email = is_email( $recipient ) ? $recipient : '';
                // Build a minimal stdClass so replace_placeholders doesn't error
                $user            = new stdClass();
                $user->ID        = 0;
                $user->user_email   = $email;
                $user->user_login   = '';
                $user->display_name = '';
                $user->first_name   = '';
                $user->last_name    = '';
            }

            if ( empty( $email ) ) {
                $failed++;
                continue;
            }

            $body    = self::replace_placeholders( $campaign->body, $user );
            $html    = Ofast_X_Email_Template::get_template( $body );
            $subject = $campaign->subject;

            // Tag this send with the campaign ID so the SMTP logger can group it
            $GLOBALS['ofast_current_campaign_id'] = (int) $campaign->id;

            if ( wp_mail( $email, $subject, $html, $headers ) ) {
                $sent++;
            } else {
                $failed++;
                $failed_emails[] = $email;
                error_log( 'Ofast Email Processor: Failed to send campaign #' . $campaign->id . ' to ' . $email );
            }

            $GLOBALS['ofast_current_campaign_id'] = null; // clear after each send
        }

        $new_position = $position + count( $batch );
        $is_done      = $new_position >= $total;

        return compact( 'sent', 'failed', 'failed_emails', 'new_position', 'is_done' );
    }

    // ─────────────────────────────────────────────
    //  CLAIM HELPER
    // ─────────────────────────────────────────────

    /**
     * Find and atomically claim a campaign.
     *
     * @param string   $strategy
     * @param int|null $campaign_id If set, target a specific campaign; else auto-pick.
     * @return object|null Claimed campaign row, or null if claim failed / nothing available.
     */
    private static function claim_campaign( string $strategy, ?int $campaign_id ): ?object {
        if ( $campaign_id ) {
            $campaign = Ofast_Email_Campaign::get( $campaign_id );
        } else {
            $campaign = Ofast_Email_Campaign::get_next_claimable( $strategy );
        }

        if ( ! $campaign ) {
            return null;
        }

        // Strategy guard — don't let the wrong worker process it
        if ( $campaign->strategy !== $strategy ) {
            error_log( 'Ofast Email Processor: Strategy mismatch for campaign #' . $campaign->id );
            return null;
        }

        // Check it is still paused/cancelled before claiming
        if ( in_array( $campaign->status, array(
            Ofast_Email_Campaign::STATUS_PAUSED,
            Ofast_Email_Campaign::STATUS_CANCELLED,
            Ofast_Email_Campaign::STATUS_COMPLETED,
        ), true ) ) {
            return null;
        }

        $claimed = Ofast_Email_Campaign::atomic_claim( (int) $campaign->id );
        if ( ! $claimed ) {
            // Another worker got there first — this is expected, not an error
            return null;
        }

        // Re-fetch to get the freshest data after the claim UPDATE
        return Ofast_Email_Campaign::get( (int) $campaign->id );
    }

    // ─────────────────────────────────────────────
    //  LOOPBACK TRIGGER
    // ─────────────────────────────────────────────

    /**
     * Fire a non-blocking loopback HTTP request to continue the rapid queue.
     *
     * Non-blocking means this call returns immediately — the loopback request
     * runs independently in the background, invisible to the browser.
     *
     * @param int $campaign_id
     */
    public static function fire_loopback( int $campaign_id ) {
        $url  = admin_url( 'admin-ajax.php' );
        $args = array(
            'body'      => array(
                'action'      => 'ofast_queue_worker',
                'campaign_id' => $campaign_id,
                'worker_key'  => self::get_worker_key(),
            ),
            'timeout'   => 0.01,  // Return immediately (non-blocking)
            'blocking'  => false, // Do NOT wait for a response
            'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
            'cookies'   => array(), // No cookies needed for internal worker
        );

        $result = wp_remote_post( $url, $args );

        if ( is_wp_error( $result ) ) {
            // Loopback failed — schedule a cron fallback
            error_log( 'Ofast Email Processor: Loopback failed for campaign #' . $campaign_id . ' — ' . $result->get_error_message() );
            wp_schedule_single_event( time() + 30, 'ofast_campaign_rapid_batch', array( $campaign_id ) );
        }
    }

    // ─────────────────────────────────────────────
    //  WP-CRON SCHEDULING (slow strategy)
    // ─────────────────────────────────────────────

    /**
     * Schedule the first WP-Cron batch for a slow-strategy campaign.
     * Subsequent batches are rescheduled inside run_slow() via next_run.
     *
     * @param int $campaign_id
     */
    public static function schedule_slow_campaign( int $campaign_id ) {
        if ( ! wp_next_scheduled( 'ofast_campaign_slow_batch', array( $campaign_id ) ) ) {
            $campaign  = Ofast_Email_Campaign::get( $campaign_id );
            $timestamp = $campaign && ! empty( $campaign->next_run ) ? strtotime( $campaign->next_run ) : time();
            $timestamp = $timestamp && $timestamp > time() ? $timestamp : time();

            wp_schedule_single_event( $timestamp, 'ofast_campaign_slow_batch', array( $campaign_id ) );
        }
    }

    /**
     * Re-schedule the next slow batch based on the campaign's next_run value.
     *
     * Called after a slow batch completes to set the next cron event.
     *
     * @param int    $campaign_id
     * @param string $next_run_mysql MySQL datetime string.
     */
    public static function reschedule_slow_campaign( int $campaign_id, string $next_run_mysql ) {
        // Clear any existing scheduled event first
        $existing = wp_next_scheduled( 'ofast_campaign_slow_batch', array( $campaign_id ) );
        if ( $existing ) {
            wp_unschedule_event( $existing, 'ofast_campaign_slow_batch', array( $campaign_id ) );
        }

        $timestamp = strtotime( $next_run_mysql );
        if ( $timestamp && $timestamp > time() ) {
            wp_schedule_single_event( $timestamp, 'ofast_campaign_slow_batch', array( $campaign_id ) );
        }
    }

    // ─────────────────────────────────────────────
    //  HELPERS
    // ─────────────────────────────────────────────

    /**
     * A shared secret key so the loopback endpoint can verify it's called
     * by our own code, not an outside attacker.
     *
     * @return string
     */
    public static function get_worker_key(): string {
        $key = get_option( 'ofast_queue_worker_key' );
        if ( ! $key ) {
            $key = wp_generate_password( 40, false );
            update_option( 'ofast_queue_worker_key', $key, false );
        }
        return $key;
    }

    /**
     * Replace personalization placeholders in the email body.
     *
     * @param string       $body Email body HTML.
     * @param object|WP_User $user User object (real or dummy stdClass).
     * @return string
     */
    private static function replace_placeholders( string $body, $user ): string {
        return str_replace(
            array( '{{user_id}}', '{{username}}', '{{user_display_name}}', '{{user_first_name}}', '{{user_last_name}}', '{{user_email}}' ),
            array(
                $user->ID ?? 0,
                $user->user_login ?? '',
                $user->display_name ?? '',
                $user->first_name ?? '',
                $user->last_name ?? '',
                $user->user_email ?? '',
            ),
            $body
        );
    }
}
