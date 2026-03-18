<?php

/**
 * Ofast X - WP Admin Design Module
 * Modern glassmorphism styling for WordPress admin dashboard
 * CSS is customizable via Admin Tweaks
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Admin_Design
{

    /**
     * Initialize module
     */
    public function init()
    {
        // Note: Module enabled check is done in core loader
        // using is_admin_tweak_enabled('enable_admin_design')

        // Enqueue admin styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
    }

    /**
     * Enqueue custom admin styles
     */
    public function enqueue_admin_styles()
    {
        // Get custom CSS from database
        $custom_css = get_option('ofast_admin_design_css', '');
        
        // If custom CSS exists in database, sanitize and output it inline
        if (!empty($custom_css)) {
            // SECURITY: Sanitize CSS to prevent XSS attacks via CSS injection
            $sanitized_css = Ofast_X_Sanitizer::css($custom_css);
            
            if (!empty($sanitized_css)) {
                wp_add_inline_style('common', $sanitized_css);
            }
        } else {
            // Fall back to default CSS file
            wp_enqueue_style(
                'ofast-admin-design',
                OFAST_X_PLUGIN_URL . 'modules/admin-design/assets/admin-design.css',
                array(),
                OFAST_X_VERSION
            );
        }
    }
}
