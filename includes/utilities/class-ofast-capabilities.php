<?php
/**
 * Ofast X Capabilities Manager
 * Centralized permission checks and user capability management
 * 
 * @package Ofast_X
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Capabilities {
    
    /**
     * Check if current user can manage Ofast X settings
     * 
     * @return bool True if allowed, false otherwise
     */
    public static function can_manage() {
        return current_user_can('manage_options');
    }
    
    /**
     * Check if current user can send emails
     * 
     * @return bool True if allowed, false otherwise
     */
    public static function can_send_email() {
        return current_user_can('manage_options');
    }
    
    /**
     * Check if current user can view email history
     * 
     * @return bool True if allowed, false otherwise
     */
    public static function can_view_history() {
        return current_user_can('manage_options');
    }
    
    /**
     * Check if current user can manage forms
     * 
     * @return bool True if allowed, false otherwise
     */
    public static function can_manage_forms() {
        return current_user_can('manage_options');
    }
    
    /**
     * Check if current user can view form submissions
     * 
     * @return bool True if allowed, false otherwise
     */
    public static function can_view_submissions() {
        return current_user_can('manage_options');
    }
    
    /**
     * Check if current user can manage snippets
     * 
     * @return bool True if allowed, false otherwise
     */
    public static function can_manage_snippets() {
        return current_user_can('manage_options');
    }
    
    /**
     * Check if current user can manage redirects
     * 
     * @return bool True if allowed, false otherwise
     */
    public static function can_manage_redirects() {
        return current_user_can('manage_options');
    }
    
    /**
     * Check if current user can manage license
     * 
     * @return bool True if allowed, false otherwise
     */
    public static function can_manage_license() {
        return current_user_can('manage_options');
    }
    
    /**
     * Check if current user has specific capability
     * 
     * @param string $capability Capability to check
     * @return bool True if allowed, false otherwise
     */
    public static function has($capability) {
        return current_user_can($capability);
    }
    
    /**
     * Require capability (dies if not allowed)
     * 
     * @param string $capability Capability to require
     * @param string $message Custom error message
     */
    public static function require_capability($capability, $message = '') {
        if (!current_user_can($capability)) {
            $default_message = __('You do not have sufficient permissions to access this page.', 'ofast-x');
            wp_die(
                $message ? $message : $default_message,
                __('Insufficient Permissions', 'ofast-x'),
                array('response' => 403)
            );
        }
    }
    
    /**
     * Check if user is administrator
     * 
     * @param int $user_id User ID (default: current user)
     * @return bool True if admin, false otherwise
     */
    public static function is_admin($user_id = 0) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        $user = get_userdata($user_id);
        
        if (!$user) {
            return false;
        }
        
        return in_array('administrator', $user->roles);
    }
    
    /**
     * Get current user role
     * 
     * @return string User role or empty string
     */
    public static function get_user_role() {
        $user = wp_get_current_user();
        
        if (!$user || empty($user->roles)) {
            return '';
        }
        
        return $user->roles[0];
    }
    
    /**
     * Check if user has any of the specified roles
     * 
     * @param array $roles Array of role names
     * @param int $user_id User ID (default: current user)
     * @return bool True if user has any role, false otherwise
     */
    public static function has_role($roles, $user_id = 0) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        $user = get_userdata($user_id);
        
        if (!$user) {
            return false;
        }
        
        if (!is_array($roles)) {
            $roles = array($roles);
        }
        
        return !empty(array_intersect($roles, $user->roles));
    }
    
    /**
     * Add custom capability to role
     * 
     * @param string $role Role name
     * @param string $capability Capability name
     * @return bool Success status
     */
    public static function add_cap_to_role($role, $capability) {
        $role_obj = get_role($role);
        
        if (!$role_obj) {
            return false;
        }
        
        $role_obj->add_cap($capability);
        return true;
    }
    
    /**
     * Remove capability from role
     * 
     * @param string $role Role name
     * @param string $capability Capability name
     * @return bool Success status
     */
    public static function remove_cap_from_role($role, $capability) {
        $role_obj = get_role($role);
        
        if (!$role_obj) {
            return false;
        }
        
        $role_obj->remove_cap($capability);
        return true;
    }
    
    /**
     * Get all WordPress roles
     * 
     * @return array Array of roles
     */
    public static function get_all_roles() {
        global $wp_roles;
        
        if (!isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }
        
        return $wp_roles->get_names();
    }
    
    /**
     * Check if current user can edit user
     * 
     * @param int $user_id User ID to check
     * @return bool True if allowed, false otherwise
     */
    public static function can_edit_user($user_id) {
        $current_user_id = get_current_user_id();
        
        // Can always edit own profile
        if ($current_user_id === $user_id) {
            return true;
        }
        
        // Check if has edit_users capability
        if (!current_user_can('edit_users')) {
            return false;
        }
        
        // Can't edit users with higher role
        $current_user = get_userdata($current_user_id);
        $target_user = get_userdata($user_id);
        
        if (!$current_user || !$target_user) {
            return false;
        }
        
        // Admin can edit anyone
        if (in_array('administrator', $current_user->roles)) {
            return true;
        }
        
        // Can't edit administrators if not admin
        if (in_array('administrator', $target_user->roles)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Log capability check (for debugging)
     * 
     * @param string $capability Capability checked
     * @param bool $result Check result
     */
    public static function log_check($capability, $result) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $user_id = get_current_user_id();
            Ofast_X_Logger::debug(
                'Capability check: ' . $capability,
                array(
                    'user_id' => $user_id,
                    'result' => $result ? 'allowed' : 'denied'
                )
            );
        }
    }
    
    /**
     * Check capability with logging
     * 
     * @param string $capability Capability to check
     * @return bool True if allowed, false otherwise
     */
    public static function check($capability) {
        $result = current_user_can($capability);
        self::log_check($capability, $result);
        return $result;
    }
    
    /**
     * Get user capabilities
     * 
     * @param int $user_id User ID (default: current user)
     * @return array User capabilities
     */
    public static function get_user_capabilities($user_id = 0) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        $user = get_userdata($user_id);
        
        if (!$user) {
            return array();
        }
        
        return array_keys(array_filter($user->allcaps));
    }
}