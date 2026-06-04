<?php

/**
 * Ofast Email Quota
 *
 * Tracks daily email sends from the Ofast Emailer module only.
 * Does NOT count WordPress core, WooCommerce, OTP, or any other plugin emails.
 *
 * Storage: Single WordPress transient per calendar day (auto-expires at midnight).
 * At any given moment, exactly 1 row exists in wp_options for this counter.
 *
 * Free tier: 50 emails/day (configurable via filter).
 * Pro tier:  Unlimited (always returns true).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ofast_Email_Quota {

    /** Free tier daily email limit. */
    const FREE_DAILY_LIMIT = 50;

    /**
     * Check if the user can send `$count` more emails right now.
     *
     * Pro users always return true — no limits, no checks.
     *
     * @param int $count Number of emails about to be sent.
     * @return bool True if sending is allowed.
     */
    public static function can_send( int $count = 1 ): bool {
        if ( function_exists( 'ofast_toolkit_is_pro' ) && ofast_toolkit_is_pro() ) {
            return true;
        }

        $used  = self::get_today_count();
        $limit = self::get_daily_limit();

        return ( $used + $count ) <= $limit;
    }

    /**
     * How many emails have been sent today via the Ofast Emailer.
     *
     * @return int
     */
    public static function get_today_count(): int {
        return (int) get_transient( self::transient_key() );
    }

    /**
     * Increment the counter by $count after successful sends.
     *
     * @param int $count Number of emails successfully sent.
     */
    public static function increment( int $count = 1 ): void {
        if ( $count <= 0 ) {
            return;
        }

        $current = self::get_today_count();
        $seconds_until_midnight = self::seconds_until_midnight();

        set_transient(
            self::transient_key(),
            $current + $count,
            $seconds_until_midnight
        );
    }

    /**
     * How many emails remain in today's budget.
     *
     * @return int Remaining quota (PHP_INT_MAX for Pro users).
     */
    public static function remaining(): int {
        if ( function_exists( 'ofast_toolkit_is_pro' ) && ofast_toolkit_is_pro() ) {
            return PHP_INT_MAX;
        }

        return max( 0, self::get_daily_limit() - self::get_today_count() );
    }

    /**
     * Get the daily limit for the current license tier.
     *
     * @return int Daily limit (PHP_INT_MAX for Pro).
     */
    public static function get_daily_limit(): int {
        if ( function_exists( 'ofast_toolkit_is_pro' ) && ofast_toolkit_is_pro() ) {
            return PHP_INT_MAX;
        }

        return (int) apply_filters( 'ofast_email_free_daily_limit', self::FREE_DAILY_LIMIT );
    }

    /**
     * Check if the current user is on the free tier (for UI display).
     *
     * @return bool True if free (not Pro).
     */
    public static function is_free_tier(): bool {
        return ! ( function_exists( 'ofast_toolkit_is_pro' ) && ofast_toolkit_is_pro() );
    }

    // ─────────────────────────────────────────────
    //  PRIVATE HELPERS
    // ─────────────────────────────────────────────

    /**
     * Transient key for today's counter.
     *
     * Format: ofast_email_sent_2026-06-04
     * One key per calendar day in the site's timezone.
     *
     * @return string
     */
    private static function transient_key(): string {
        return 'ofast_email_sent_' . wp_date( 'Y-m-d', null, wp_timezone() );
    }

    /**
     * Seconds remaining until midnight in the site's timezone.
     *
     * Used as the transient TTL so it auto-expires at the start of a new day.
     * Minimum 60 seconds to avoid edge cases near midnight.
     *
     * @return int
     */
    private static function seconds_until_midnight(): int {
        $now      = new DateTime( 'now', wp_timezone() );
        $midnight = ( clone $now )->modify( 'tomorrow midnight' );

        return max( 60, $midnight->getTimestamp() - $now->getTimestamp() );
    }
}
