<?php
/**
 * Ofast X Validator
 * Centralized validation methods
 * 
 * @package Ofast_X
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Validator {
    
    /**
     * Validate email address
     * 
     * @param string $email Email to validate
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    public static function email($email) {
        if (empty($email)) {
            return new WP_Error('empty_email', __('Email address is required.', 'ofast-x'));
        }
        
        if (!is_email($email)) {
            return new WP_Error('invalid_email', __('Email address is invalid.', 'ofast-x'));
        }
        
        return true;
    }
    
    /**
     * Validate URL
     * 
     * @param string $url URL to validate
     * @param bool $required Is URL required
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    public static function url($url, $required = true) {
        if (empty($url)) {
            if ($required) {
                return new WP_Error('empty_url', __('URL is required.', 'ofast-x'));
            }
            return true;
        }
        
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', __('URL is invalid.', 'ofast-x'));
        }
        
        return true;
    }
    
    /**
     * Validate required field
     * 
     * @param mixed $value Value to validate
     * @param string $field_name Field name for error message
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    public static function required($value, $field_name = 'Field') {
        if (empty($value) && $value !== '0') {
            return new WP_Error(
                'required_field', 
                sprintf(__('%s is required.', 'ofast-x'), $field_name)
            );
        }
        
        return true;
    }
    
    /**
     * Validate minimum length
     * 
     * @param string $value Value to validate
     * @param int $min Minimum length
     * @param string $field_name Field name for error message
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    public static function min_length($value, $min, $field_name = 'Field') {
        if (strlen($value) < $min) {
            return new WP_Error(
                'min_length', 
                sprintf(__('%s must be at least %d characters.', 'ofast-x'), $field_name, $min)
            );
        }
        
        return true;
    }
    
    /**
     * Validate maximum length
     * 
     * @param string $value Value to validate
     * @param int $max Maximum length
     * @param string $field_name Field name for error message
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    public static function max_length($value, $max, $field_name = 'Field') {
        if (strlen($value) > $max) {
            return new WP_Error(
                'max_length', 
                sprintf(__('%s must not exceed %d characters.', 'ofast-x'), $field_name, $max)
            );
        }
        
        return true;
    }
    
    /**
     * Validate numeric value
     * 
     * @param mixed $value Value to validate
     * @param string $field_name Field name for error message
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    public static function numeric($value, $field_name = 'Field') {
        if (!is_numeric($value)) {
            return new WP_Error(
                'not_numeric', 
                sprintf(__('%s must be a number.', 'ofast-x'), $field_name)
            );
        }
        
        return true;
    }
    
    /**
     * Validate integer value
     * 
     * @param mixed $value Value to validate
     * @param string $field_name Field name for error message
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    public static function integer($value, $field_name = 'Field') {
        if (!is_numeric($value) || intval($value) != $value) {
            return new WP_Error(
                'not_integer', 
                sprintf(__('%s must be an integer.', 'ofast-x'), $field_name)
            );
        }
        
        return true;
    }
    
    /**
     * Validate value is in allowed list
     * 
     * @param mixed $value Value to validate
     * @param array $allowed Allowed values
     * @param string $field_name Field name for error message
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    public static function in_list($value, $allowed, $field_name = 'Field') {
        if (!in_array($value, $allowed, true)) {
            return new WP_Error(
                'invalid_value', 
                sprintf(__('%s has an invalid value.', 'ofast-x'), $field_name)
            );
        }
        
        return true;
    }
    
    /**
     * Validate date format (Y-m-d)
     * 
     * @param string $date Date to validate
     * @param string $field_name Field name for error message
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    public static function date($date, $field_name = 'Date') {
        if (empty($date)) {
            return new WP_Error('empty_date', sprintf(__('%s is required.', 'ofast-x'), $field_name));
        }
        
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            return new WP_Error('invalid_date', sprintf(__('%s is invalid. Use format: YYYY-MM-DD', 'ofast-x'), $field_name));
        }
        
        return true;
    }
    
    /**
     * Validate datetime format (Y-m-d H:i:s)
     * 
     * @param string $datetime Datetime to validate
     * @param string $field_name Field name for error message
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    public static function datetime($datetime, $field_name = 'Datetime') {
        if (empty($datetime)) {
            return new WP_Error('empty_datetime', sprintf(__('%s is required.', 'ofast-x'), $field_name));
        }
        
        $timestamp = strtotime($datetime);
        if (!$timestamp) {
            return new WP_Error('invalid_datetime', sprintf(__('%s is invalid.', 'ofast-x'), $field_name));
        }
        
        return true;
    }
    
    /**
     * Validate phone number (basic)
     * 
     * @param string $phone Phone to validate
     * @param bool $required Is phone required
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    public static function phone($phone, $required = true) {
        if (empty($phone)) {
            if ($required) {
                return new WP_Error('empty_phone', __('Phone number is required.', 'ofast-x'));
            }
            return true;
        }
        
        // Basic validation: must contain at least 7 digits
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($digits) < 7) {
            return new WP_Error('invalid_phone', __('Phone number is invalid.', 'ofast-x'));
        }
        
        return true;
    }
    
    /**
     * Validate user capability
     * 
     * @param string $capability Capability to check
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    public static function capability($capability) {
        if (!current_user_can($capability)) {
            return new WP_Error('insufficient_permissions', __('You do not have permission to perform this action.', 'ofast-x'));
        }
        
        return true;
    }
    
    /**
     * Validate user ID exists
     * 
     * @param int $user_id User ID to validate
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    public static function user_exists($user_id) {
        $user = get_userdata($user_id);
        
        if (!$user) {
            return new WP_Error('user_not_found', __('User does not exist.', 'ofast-x'));
        }
        
        return true;
    }
    
    /**
     * Validate file upload
     * 
     * @param array $file $_FILES array element
     * @param array $allowed_types Allowed MIME types
     * @param int $max_size Maximum file size in bytes
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    public static function file_upload($file, $allowed_types = array(), $max_size = 5242880) {
        if (empty($file) || !isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', __('File upload failed.', 'ofast-x'));
        }
        
        // Check file size
        if ($file['size'] > $max_size) {
            return new WP_Error('file_too_large', sprintf(__('File size must not exceed %s.', 'ofast-x'), size_format($max_size)));
        }
        
        // Check MIME type
        if (!empty($allowed_types)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mime, $allowed_types, true)) {
                return new WP_Error('invalid_file_type', __('File type not allowed.', 'ofast-x'));
            }
        }
        
        return true;
    }
    
    /**
     * Check if validation result has errors
     * 
     * @param mixed $result Validation result
     * @return bool True if has errors, false otherwise
     */
    public static function has_errors($result) {
        return is_wp_error($result);
    }
    
    /**
     * Get error message from validation result
     * 
     * @param WP_Error $error WP_Error object
     * @return string Error message
     */
    public static function get_error($error) {
        if (is_wp_error($error)) {
            return $error->get_error_message();
        }
        
        return '';
    }
}