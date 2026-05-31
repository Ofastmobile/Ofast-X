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

        if ($this->is_bundled_default_css($custom_css)) {
            return;
        }

        $custom_css = Ofast_X_Sanitizer::css($custom_css);

        if (!empty($custom_css)) {
            wp_add_inline_style('common', $custom_css);
        }
    }

    /**
     * Check whether the saved CSS is the bundled starter template.
     */
    private function is_bundled_default_css($custom_css)
    {
        $default_css_file = OFAST_X_PLUGIN_DIR . 'modules/admin-design/assets/admin-design.css';

        if (!file_exists($default_css_file)) {
            return false;
        }

        $default_css = file_get_contents($default_css_file);

        if ($default_css === false) {
            return false;
        }

        $normalized_css = $this->normalize_css($custom_css);

        if ($normalized_css === $this->normalize_css($default_css)) {
            return true;
        }

        // Older installs may have saved the previous bundled template before
        // its media-library selectors were tightened.
        return strpos($normalized_css, 'Overall WP admin dashboard body') !== false
            && strpos($normalized_css, 'Main Menu Hover') !== false
            && strpos($normalized_css, '#adminmenuwrap') !== false
            && strpos($normalized_css, '#welcome-panel') !== false;
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

    /**
     * Normalize CSS for template comparison only.
     */
    private function normalize_css($css)
    {
        $css = str_replace("\r\n", "\n", (string) $css);
        $css = preg_replace('/\s+/', ' ', $css);

        return trim($css);
    }
}
