<?php
/**
 * Ofast X - Unified Dropdown Component
 * Reusable custom select dropdown for consistent UI across admin modules.
 *
 * Usage:
 *   echo Ofast_X_Dropdown::render_assets(); // Include once per page
 *   <select class="ofast-dropdown-native">...</select>
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Dropdown
{
    private static $assets_included = false;

    /**
     * Render dropdown assets once per page.
     *
     * @return string
     */
    public static function render_assets()
    {
        if (self::$assets_included) {
            return '';
        }

        self::$assets_included = true;
        
        wp_enqueue_style('ofast-dropdown-css', OFAST_X_PLUGIN_URL . 'assets/css/ofast-dropdown.css', array(), OFAST_X_VERSION);
        wp_enqueue_script('ofast-dropdown-js', OFAST_X_PLUGIN_URL . 'assets/js/ofast-dropdown.js', array('jquery'), OFAST_X_VERSION, true);

        return '';
    }
}
