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
    
    /**
     * Sanitize CSS input safely
     * 
     * Validates and sanitizes CSS content to prevent CSS injection attacks
     * while preserving valid CSS declarations.
     * 
     * @param string $input CSS input to sanitize
     * @return string Sanitized CSS or empty string if invalid
     */
    public static function css($input) {
        if (empty($input) || !is_string($input)) {
            return '';
        }
        
        // Remove any HTML/XML markup - CSS should not contain tags
        if (preg_match('#</?[a-z]#i', $input)) {
            return '';
        }
        
        // Remove null bytes and other control characters
        $css = str_replace(chr(0), '', $input);
        $css = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $css);
        
        // Remove dangerous CSS patterns that could lead to XSS
        $dangerous_patterns = array(
            '/javascript\s*:/i',           // javascript: protocol
            '/vbscript\s*:/i',            // vbscript: protocol
            '/data\s*:/i',                // data: protocol (could contain scripts)
            '/expression\s*\(/i',         // CSS expression() - IE specific
            '/behavior\s*:/i',            // CSS behavior (IE specific)
            '/-moz-binding\s*:/i',        // Mozilla binding
            '/binding\s*:/i',             // CSS binding
            '/@import/i',                 // @import rules (could load external malicious CSS)
            '/mocha\s*:/i',               // mocha: protocol
            '/livescript\s*:/i',          // livescript: protocol
        );
        
        foreach ($dangerous_patterns as $pattern) {
            $css = preg_replace($pattern, '', $css);
        }
        
        // Remove any remaining URL protocols except safe ones
        $css = preg_replace_callback('/url\s*\(\s*["\']?([^"\'()]*)["\']?\s*\)/i', function($matches) {
            $url = trim($matches[1]);
            // Only allow http, https, and relative URLs
            if (preg_match('/^(https?:\/\/|\/|\.\/|\.\.\/)/', $url) || !preg_match('/^[a-z]+:/', $url)) {
                return 'url(' . esc_url_raw($url) . ')';
            }
            return '';
        }, $css);
        
        // Basic CSS syntax validation - ensure balanced braces
        $open_braces = substr_count($css, '{');
        $close_braces = substr_count($css, '}');
        if ($open_braces !== $close_braces) {
            return '';
        }
        
        // Trim whitespace and return
        return trim($css);
    }
}