<?php
/**
 * Ofast X Nonce Manager
 * Centralized nonce generation and verification
 * 
 * @package Ofast_X
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Nonce {
    
    /**
     * Generate nonce field for forms
     * 
     * @param string $action Action name
     * @param string $name Nonce field name (default: '_wpnonce')
     * @param bool $referer Add referer field (default: true)
     * @param bool $echo Echo or return (default: true)
     * @return string Nonce field HTML
     */
    public static function field($action, $name = '_wpnonce', $referer = true, $echo = true) {
        return wp_nonce_field($action, $name, $referer, $echo);
    }
    
    /**
     * Verify nonce from form submission
     * 
     * @param string $action Action name
     * @param string $name Nonce field name (default: '_wpnonce')
     * @return bool True if valid, dies if invalid
     */
    public static function verify($action, $name = '_wpnonce') {
        if (!isset($_POST[$name])) {
            wp_die(__('Security verification failed. No nonce provided.', 'ofast-x'), 403);
        }
        
        if (!wp_verify_nonce($_POST[$name], $action)) {
            wp_die(__('Security verification failed. Invalid nonce.', 'ofast-x'), 403);
        }
        
        return true;
    }
    
    /**
     * Verify nonce with admin referer check
     * 
     * @param string $action Action name
     * @param string $name Nonce field name (default: '_wpnonce')
     * @return bool True if valid, dies if invalid
     */
    public static function verify_admin($action, $name = '_wpnonce') {
        check_admin_referer($action, $name);
        return true;
    }
    
    /**
     * Create nonce for AJAX requests
     * 
     * @param string $action Action name
     * @return string Nonce value
     */
    public static function create($action) {
        return wp_create_nonce($action);
    }
    
    /**
     * Verify nonce for AJAX requests (dies on failure)
     * 
     * @param string $action Action name
     * @param string $query_arg Query argument name (default: '_wpnonce')
     * @return bool True if valid, dies if invalid
     */
    public static function verify_ajax($action, $query_arg = '_wpnonce') {
        check_ajax_referer($action, $query_arg);
        return true;
    }
    
    /**
     * Verify nonce for AJAX (returns bool instead of dying)
     * 
     * @param string $nonce Nonce value
     * @param string $action Action name
     * @return bool True if valid, false if invalid
     */
    public static function check_ajax($nonce, $action) {
        $result = wp_verify_nonce($nonce, $action);
        return ($result === 1 || $result === 2);
    }
    
    /**
     * Create nonce URL
     * 
     * @param string $url URL to add nonce to
     * @param string $action Action name
     * @param string $name Nonce parameter name (default: '_wpnonce')
     * @return string URL with nonce
     */
    public static function url($url, $action, $name = '_wpnonce') {
        return wp_nonce_url($url, $action, $name);
    }
    
    /**
     * Check if nonce is valid (without dying)
     * 
     * @param string $nonce Nonce value
     * @param string $action Action name
     * @return bool True if valid, false if invalid
     */
    public static function is_valid($nonce, $action) {
        $result = wp_verify_nonce($nonce, $action);
        return ($result === 1 || $result === 2);
    }
}