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
     * Sanitize CSS to prevent XSS attacks via CSS injection
     * 
     * Removes dangerous CSS patterns including:
     * - CSS expressions (IE)
     * - JavaScript/VBScript URLs  
     * - Dangerous data URIs
     * - @import statements
     * - Behavior/binding properties
     * - HTML injection attempts
     * 
     * @param string $css Raw CSS input
     * @return string Sanitized CSS
     */
    public static function css($css) {
        if (empty($css) || !is_string($css)) {
            return '';
        }
        
        // Decode HTML entities to catch obfuscation attempts
        $css = html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Remove null bytes and control characters
        $css = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $css);
        
        // Decode CSS escape sequences to reveal obfuscated content
        $css = self::decode_css_escapes($css);
        
        // Strip HTML tags to prevent breaking out of style context
        $css = wp_strip_all_tags($css);
        
        // Remove dangerous CSS patterns
        $dangerous_patterns = array(
            '/expression\s*\(/i',                    // CSS expressions (IE6-7)
            '/j\s*a\s*v\s*a\s*s\s*c\s*r\s*i\s*p\s*t\s*:/i', // JavaScript protocol
            '/v\s*b\s*s\s*c\s*r\s*i\s*p\s*t\s*:/i',          // VBScript protocol
            '/@import\b/i',                          // @import statements
            '/@charset\b/i',                         // @charset (can cause issues)
            '/b\s*e\s*h\s*a\s*v\s*i\s*o\s*r\s*\s*:/i',       // IE behavior property
            '/-moz-binding\s*:/i',                   // Mozilla XBL bindings
            '/-webkit-binding\s*:/i',                // WebKit bindings
            '/binding\s*:/i',                        // Generic binding
            '/<\s*\/?(?:script|style|link|object|embed|applet|iframe|frame|meta|base|form|input)\b/i' // HTML injection
        );
        
        foreach ($dangerous_patterns as $pattern) {
            $css = preg_replace($pattern, '/* [blocked] */', $css);
        }
        
        // Sanitize url() values
        $css = self::sanitize_css_urls($css);
        
        // Final cleanup - remove excessive backslashes
        $css = preg_replace('/\\\\{3,}/', '', $css);
        
        return trim($css);
    }
    
    /**
     * Decode CSS escape sequences to reveal obfuscated content
     * 
     * @param string $css CSS containing potential escape sequences
     * @return string CSS with escapes decoded
     */
    private static function decode_css_escapes($css) {
        // Decode hex escape sequences: \6A -> j (for javascript obfuscation)
        $css = preg_replace_callback(
            '/\\\\([0-9a-fA-F]{1,6})(?:\s)?/',
            function($matches) {
                $code_point = intval($matches[1], 16);
                if ($code_point > 0 && $code_point < 0x10FFFF) {
                    return mb_chr($code_point, 'UTF-8');
                }
                return '';
            },
            $css
        );
        
        // Decode simple escape sequences: \j -> j
        $css = preg_replace('/\\\\([^0-9a-fA-F])/', '$1', $css);
        
        return $css;
    }
    
    /**
     * Sanitize URL values within CSS url() functions
     * 
     * @param string $css CSS containing url() values
     * @return string CSS with dangerous URLs removed
     */
    private static function sanitize_css_urls($css) {
        return preg_replace_callback(
            '/url\s*\(\s*(["\']?)(.+?)\1\s*\)/is',
            function($matches) {
                $quote = $matches[1];
                $url = trim($matches[2]);
                $url_normalized = preg_replace('/\s+/', '', strtolower($url));
                
                // Block javascript/vbscript protocols
                if (preg_match('/^(javascript|vbscript):/i', $url_normalized)) {
                    return '/* [blocked url] */';
                }
                
                // Handle data URIs - only allow safe image types
                if (preg_match('/^data:/i', $url_normalized)) {
                    if (preg_match('/^data:image\/(png|jpe?g|gif|webp|svg\+xml);base64,/i', $url)) {
                        // Additional SVG safety check
                        if (stripos($url, 'image/svg') !== false) {
                            $decoded = base64_decode(substr($url, strpos($url, ',') + 1));
                            if ($decoded && preg_match('/<script|javascript:|on\w+\s*=/i', $decoded)) {
                                return '/* [blocked svg] */';
                            }
                        }
                        return $matches[0]; // Allow safe image data URI
                    }
                    return '/* [blocked data uri] */';
                }
                
                // Allow http/https URLs
                if (preg_match('/^https?:\/\//i', $url)) {
                    $url = esc_url($url);
                    return "url({$quote}{$url}{$quote})";
                }
                
                // Allow simple relative URLs
                if (preg_match('/^[a-zA-Z0-9_\-\.\/]+(?:\?[a-zA-Z0-9_\-\.\/&=]*)?(?:#[a-zA-Z0-9_\-]*)?$/', $url)) {
                    return $matches[0];
                }
                
                // Block everything else
                return '/* [blocked url] */';
            },
            $css
        );
    }
}