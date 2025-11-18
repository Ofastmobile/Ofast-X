<?php
/**
 * Ofast X Debug Indicator
 * Shows warning when WP_DEBUG is enabled
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Debug_Indicator {
    
    /**
     * Initialize debug indicator
     */
    public function init() {
        add_action('admin_notices', array($this, 'show_debug_warning'));
        add_action('admin_bar_menu', array($this, 'add_debug_admin_bar'), 999);
    }
    
    /**
     * Show debug warning in admin
     */
    public function show_debug_warning() {
        if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) {
            echo '
            <div class="notice notice-warning is-dismissible">
                <p>⚠️ <strong>WordPress Debug Mode is Active</strong> - This should be disabled on production sites.</p>
                <p>Edit wp-config.php: <code>define(\'WP_DEBUG\', false);</code></p>
            </div>';
        }
    }
    
    /**
     * Add debug indicator to admin bar
     */
    public function add_debug_admin_bar($wp_admin_bar) {
        if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) {
            $wp_admin_bar->add_node(array(
                'id'    => 'ofast-debug-indicator',
                'title' => '⚠️ DEBUG MODE',
                'href'  => '#',
                'meta'  => array(
                    'class' => 'ofast-debug-warning',
                    'title' => 'WP_DEBUG is enabled'
                )
            ));
        }
    }
}