<?php
/**
 * Ofast X Sanitizer
 * Centralized input sanitization methods
 * 
 * @package Ofast_X
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Sanitizer {
    
    /**
     * Sanitize text field
     * 
     * @param mixed $input Input value
     * @return string Sanitized text
     */
    public static function text($input) {
        return sanitize_text_field($input);
    }
    
    /**
     * Sanitize textarea
     * 
     * @param mixed $input Input value
     * @return string Sanitized textarea
     */
    public static function textarea($input) {
        return sanitize_textarea_field($input);
    }
    
    /**
     * Sanitize email
     * 
     * @param mixed $input Input value
     * @return string Sanitized email
     */
    public static function email($input) {
        return sanitize_email($input);
    }
    
    /**
     * Sanitize URL
     * 
     * @param mixed $input Input value
     * @return string Sanitized URL
     */
    public static function url($input) {
        return esc_url_raw($input);
    }
    
    /**
     * Sanitize HTML content (allows safe HTML tags)
     * 
     * @param mixed $input Input value
     * @return string Sanitized HTML
     */
    public static function html($input) {
        return wp_kses_post($input);
    }
    
    /**
     * Sanitize integer
     * 
     * @param mixed $input Input value
     * @return int Sanitized integer
     */
    public static function int($input) {
        return absint($input);
    }
    
    /**
     * Sanitize float
     * 
     * @param mixed $input Input value
     * @return float Sanitized float
     */
    public static function float($input) {
        return floatval($input);
    }
    
    /**
     * Sanitize boolean
     * 
     * @param mixed $input Input value
     * @return bool Sanitized boolean
     */
    public static function bool($input) {
        return (bool) $input;
    }
    
    /**
     * Sanitize array of text fields
     * 
     * @param array $input Input array
     * @return array Sanitized array
     */
    public static function text_array($input) {
        if (!is_array($input)) {
            return array();
        }
        
        return array_map('sanitize_text_field', $input);
    }
    
    /**
     * Sanitize array of integers
     * 
     * @param array $input Input array
     * @return array Sanitized array
     */
    public static function int_array($input) {
        if (!is_array($input)) {
            return array();
        }
        
        return array_map('absint', $input);
    }
    
    /**
     * Sanitize key (alphanumeric with dashes/underscores)
     * 
     * @param mixed $input Input value
     * @return string Sanitized key
     */
    public static function key($input) {
        return sanitize_key($input);
    }
    
    /**
     * Sanitize filename
     * 
     * @param mixed $input Input value
     * @return string Sanitized filename
     */
    public static function filename($input) {
        return sanitize_file_name($input);
    }
    
    /**
     * Sanitize user input (strips all tags)
     * 
     * @param mixed $input Input value
     * @return string Sanitized input
     */
    public static function strip_all($input) {
        return wp_strip_all_tags($input);
    }
    
    /**
     * Sanitize phone number (basic)
     * 
     * @param mixed $input Input value
     * @return string Sanitized phone
     */
    public static function phone($input) {
        // Remove everything except numbers, +, -, (, ), and spaces
        return preg_replace('/[^0-9+\-() ]/', '', $input);
    }
    
    /**
     * Sanitize comma-separated list of integers
     * 
     * @param string $input Input value (e.g., "1,2,3,5-10")
     * @return array Array of integers
     */
    public static function id_list($input) {
        $input = sanitize_text_field($input);
        $parts = preg_split('/\s*,\s*/', $input);
        $ids = array();
        
        foreach ($parts as $part) {
            if (strpos($part, '-') !== false) {
                // Range: "5-10"
                list($start, $end) = array_map('intval', explode('-', $part));
                if ($start > 0 && $end >= $start) {
                    $ids = array_merge($ids, range($start, $end));
                }
            } elseif (is_numeric($part)) {
                // Single ID
                $ids[] = absint($part);
            }
        }
        
        return array_unique($ids);
    }
    
    /**
     * Sanitize color hex code
     * 
     * @param string $input Input value
     * @return string Sanitized hex color
     */
    public static function color($input) {
        $input = sanitize_text_field($input);
        
        // Must start with # and be 3 or 6 hex digits
        if (preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $input)) {
            return $input;
        }
        
        return '';
    }
    
    /**
     * Sanitize date (Y-m-d format)
     * 
     * @param string $input Input value
     * @return string Sanitized date or empty
     */
    public static function date($input) {
        $input = sanitize_text_field($input);
        $timestamp = strtotime($input);
        
        if ($timestamp) {
            return date('Y-m-d', $timestamp);
        }
        
        return '';
    }
    
    /**
     * Sanitize datetime (Y-m-d H:i:s format)
     * 
     * @param string $input Input value
     * @return string Sanitized datetime or empty
     */
    public static function datetime($input) {
        $input = sanitize_text_field($input);
        $timestamp = strtotime($input);
        
        if ($timestamp) {
            return date('Y-m-d H:i:s', $timestamp);
        }
        
        return '';
    }
}