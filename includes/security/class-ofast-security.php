<?php
/**
 * Ofast X Security Main Controller
 * Loads and coordinates all security classes
 * 
 * @package Ofast_X
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Security {
    
    /**
     * Initialize security system
     */
    public static function init() {
        // Load security helper classes
        self::load_dependencies();
        
        // Set security headers
        add_action('send_headers', array(__CLASS__, 'set_security_headers'));
        
        // Disable XML-RPC if not needed
        add_filter('xmlrpc_enabled', '__return_false');
        
        // Remove WordPress version from head
        remove_action('wp_head', 'wp_generator');
        
        // Hide login errors
        add_filter('login_errors', array(__CLASS__, 'hide_login_errors'));
    }
    
    /**
     * Load security helper classes
     */
    private static function load_dependencies() {
        require_once OFAST_X_PLUGIN_DIR . 'includes/security/class-ofast-nonce.php';
        require_once OFAST_X_PLUGIN_DIR . 'includes/security/class-ofast-sanitizer.php';
        require_once OFAST_X_PLUGIN_DIR . 'includes/security/class-ofast-validator.php';
    }
    
    /**
     * Set security headers
     */
    public static function set_security_headers() {
        if (!is_admin()) {
            // Prevent clickjacking
            header('X-Frame-Options: SAMEORIGIN');
            
            // Prevent MIME sniffing
            header('X-Content-Type-Options: nosniff');
            
            // Enable XSS protection
            header('X-XSS-Protection: 1; mode=block');
            
            // Referrer policy
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
    }
    
    /**
     * Hide login error messages (security)
     */
    public static function hide_login_errors($error) {
        return __('Invalid login credentials.', 'ofast-x');
    }
    
    /**
     * Verify user capability
     * 
     * @param string $capability Required capability
     * @return bool True if valid, dies if invalid
     */
    public static function check_capability($capability = 'manage_options') {
        if (!current_user_can($capability)) {
            wp_die(
                __('You do not have sufficient permissions to access this page.', 'ofast-x'),
                __('Insufficient Permissions', 'ofast-x'),
                array('response' => 403)
            );
        }
        
        return true;
    }
    
    /**
     * Verify nonce and capability (combined check)
     * 
     * @param string $nonce_action Nonce action
     * @param string $nonce_name Nonce field name
     * @param string $capability Required capability
     * @return bool True if valid, dies if invalid
     */
    public static function verify($nonce_action, $nonce_name = '_wpnonce', $capability = 'manage_options') {
        // Check capability first
        self::check_capability($capability);
        
        // Verify nonce
        Ofast_X_Nonce::verify($nonce_action, $nonce_name);
        
        return true;
    }
    
    /**
     * Verify AJAX request
     * 
     * @param string $nonce_action Nonce action
     * @param string $capability Required capability
     * @return bool True if valid, dies if invalid
     */
    public static function verify_ajax($nonce_action, $capability = 'manage_options') {
        // Check capability
        if (!current_user_can($capability)) {
            wp_send_json_error(array(
                'message' => __('Insufficient permissions.', 'ofast-x')
            ), 403);
        }
        
        // Verify nonce
        Ofast_X_Nonce::verify_ajax($nonce_action);
        
        return true;
    }
    
    /**
     * Sanitize POST data
     * 
     * @param array $fields Array of field configs: ['field_name' => 'sanitize_method']
     * @return array Sanitized data
     */
    public static function sanitize_post($fields) {
        $sanitized = array();
        
        foreach ($fields as $field => $method) {
            if (!isset($_POST[$field])) {
                continue;
            }
            
            $value = $_POST[$field];
            
            // Call appropriate sanitizer method
            if (method_exists('Ofast_X_Sanitizer', $method)) {
                $sanitized[$field] = Ofast_X_Sanitizer::$method($value);
            } else {
                // Fallback to text sanitization
                $sanitized[$field] = Ofast_X_Sanitizer::text($value);
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Validate data
     * 
     * @param array $rules Validation rules: ['field' => ['method' => [params]]]
     * @param array $data Data to validate
     * @return bool|array True if valid, array of errors if invalid
     */
    public static function validate($rules, $data) {
        $errors = array();
        
        foreach ($rules as $field => $field_rules) {
            $value = isset($data[$field]) ? $data[$field] : '';
            
            foreach ($field_rules as $rule => $params) {
                if (!is_array($params)) {
                    $params = array($params);
                }
                
                // Prepend value to params
                array_unshift($params, $value);
                
                // Call validator method
                if (method_exists('Ofast_X_Validator', $rule)) {
                    $result = call_user_func_array(array('Ofast_X_Validator', $rule), $params);
                    
                    if (is_wp_error($result)) {
                        $errors[$field] = $result->get_error_message();
                        break; // Stop at first error for this field
                    }
                }
            }
        }
        
        return empty($errors) ? true : $errors;
    }
    
    /**
     * Rate limit check (simple implementation)
     * 
     * @param string $action Action identifier
     * @param int $limit Maximum attempts
     * @param int $window Time window in seconds
     * @return bool True if allowed, false if rate limited
     */
    public static function rate_limit($action, $limit = 5, $window = 60) {
        $transient_key = 'ofast_rate_' . md5($action . '_' . self::get_user_ip());
        $attempts = get_transient($transient_key);
        
        if ($attempts === false) {
            // First attempt
            set_transient($transient_key, 1, $window);
            return true;
        }
        
        if ($attempts >= $limit) {
            return false;
        }
        
        // Increment attempts
        set_transient($transient_key, $attempts + 1, $window);
        return true;
    }
    
    /**
     * Get user IP address
     * 
     * @return string IP address
     */
    private static function get_user_ip() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
    
    /**
     * Escape output for HTML
     * 
     * @param string $text Text to escape
     * @return string Escaped text
     */
    public static function esc($text) {
        return esc_html($text);
    }
    
    /**
     * Escape output for HTML attributes
     * 
     * @param string $text Text to escape
     * @return string Escaped text
     */
    public static function esc_attr($text) {
        return esc_attr($text);
    }
    
    /**
     * Escape URL
     * 
     * @param string $url URL to escape
     * @return string Escaped URL
     */
    public static function esc_url($url) {
        return esc_url($url);
    }
}

// Initialize security on plugin load
Ofast_X_Security::init();