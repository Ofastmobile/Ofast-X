<?php

/**
 * Ofast X Rate Limiter
 * Prevent abuse via transient-based rate limiting
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Rate_Limiter
{

    /**
     * Check if action is within rate limit
     * 
     * @param int $user_id User ID
     * @param string $action Action identifier
     * @param int $max_attempts Maximum attempts allowed
     * @param int $time_window Time window in seconds
     * @return bool True if within limit, false if exceeded
     */
    public static function check_limit($user_id, $action, $max_attempts, $time_window)
    {
        // Admins can bypass rate limiting  
        if (current_user_can('manage_options') && apply_filters('ofast_x_bypass_rate_limit', true)) {
            return true;
        }

        $transient_key = self::get_transient_key($user_id, $action);
        $attempts = get_transient($transient_key);

        if ($attempts === false) {
            // First attempt
            set_transient($transient_key, 1, $time_window);
            return true;
        }

        if ($attempts >= $max_attempts) {
            // Rate limit exceeded
            return false;
        }

        // Increment and allow
        set_transient($transient_key, $attempts + 1, $time_window);
        return true;
    }

    /**
     * Increment rate limit counter
     * 
     * @param int $user_id User ID
     * @param string $action Action identifier
     * @param int $time_window Time window in seconds
     */
    public static function increment($user_id, $action, $time_window = 3600)
    {
        $transient_key = self::get_transient_key($user_id, $action);
        $attempts = get_transient($transient_key);

        if ($attempts === false) {
            set_transient($transient_key, 1, $time_window);
        } else {
            set_transient($transient_key, $attempts + 1, $time_window);
        }
    }

    /**
     * Reset rate limit for user action
     * 
     * @param int $user_id User ID
     * @param string $action Action identifier
     */
    public static function reset($user_id, $action)
    {
        $transient_key = self::get_transient_key($user_id, $action);
        delete_transient($transient_key);
    }

    /**
     * Get remaining attempts
     * 
     * @param int $user_id User ID
     * @param string $action Action identifier
     * @param int $max_attempts Maximum attempts
     * @return int Remaining attempts
     */
    public static function get_remaining($user_id, $action, $max_attempts)
    {
        $transient_key = self::get_transient_key($user_id, $action);
        $attempts = get_transient($transient_key);

        if ($attempts === false) {
            return $max_attempts;
        }

        return max(0, $max_attempts - $attempts);
    }

    /**
     * Get transient key
     * 
     * @param int $user_id User ID
     * @param string $action Action identifier
     * @return string Transient key
     */
    private static function get_transient_key($user_id, $action)
    {
        return 'ofast_x_rate_' . $user_id . '_' . $action;
    }

    /**
     * Clear all rate limits for a user
     * 
     * @param int $user_id User ID
     */
    public static function clear_all($user_id)
    {
        global $wpdb;

        $pattern = 'ofast_x_rate_' . $user_id . '_%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('_transient_' . $pattern) . '%'
        ));
    }
}
