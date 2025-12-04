<?php

/**
 * Ofast X - WP Admin Design Module
 * Modern glassmorphism styling for WordPress admin dashboard
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
        // Only load if module is enabled
        $enabled = get_option('ofastx_modules_enabled', array());
        if (empty($enabled['admin-design'])) {
            return;
        }

        // Enqueue admin styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
    }

    /**
     * Enqueue custom admin styles
     */
    public function enqueue_admin_styles()
    {
        wp_enqueue_style(
            'ofast-admin-design',
            OFAST_X_PLUGIN_URL . 'modules/admin-design/assets/admin-design.css',
            array(),
            OFAST_X_VERSION
        );
    }
}
