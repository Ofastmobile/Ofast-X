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
    public function enqueue_admin_styles($hook)
    {
        if ($this->is_protected_admin_screen($hook)) {
            return;
        }

        // Get custom CSS from database
        $custom_css = get_option('ofast_admin_design_css', '');

        if (empty($custom_css)) {
            return;
        }

        $custom_css = Ofast_X_Sanitizer::css($custom_css);

        if (!empty($custom_css)) {
            wp_add_inline_style('common', $custom_css);
        }
    }



    /**
     * Keep broad admin-design CSS away from fragile core/admin takeover screens.
     */
    private function is_protected_admin_screen($hook)
    {
        if ($hook === 'upload.php' || $hook === 'media-new.php') {
            return true;
        }

        if ($hook !== 'index.php') {
            return false;
        }

        $footer_settings = get_option('ofast_admin_footer_settings', array());
        if (empty($footer_settings['enable_custom_dashboard'])) {
            return false;
        }

        $mode = get_user_meta(get_current_user_id(), 'ofast_dashboard_mode', true) ?: 'modern';

        return $mode === 'modern';
    }
}
