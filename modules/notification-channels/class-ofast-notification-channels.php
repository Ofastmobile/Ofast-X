<?php

/**
 * Ofast X - Notification Channels Module
 * Unified settings for WhatsApp notifications
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Notification_Channels
{
    /**
     * Initialize module
     */
    public function init()
    {
        // Only load if module is enabled
        $enabled = get_option('ofastx_modules_enabled', array());
        if (empty($enabled['notification-channels'])) {
            return;
        }

        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'Notification Channels',
            'Notification Channels',
            'manage_options',
            'ofast-notification-channels',
            array($this, 'render_page')
        );
    }

    /**
     * Render the settings page
     */
    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }
?>
        <div class="wrap">
            <h1>Notification Channels</h1>
            <p>Configure external notification channels for form submissions and other events.</p>

            <?php
            // WhatsApp Settings
            if (class_exists('Ofast_X_WhatsApp')) {
                echo '<div class="ofast-channel-section" style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;margin-top:20px;">';
                Ofast_X_WhatsApp::get_instance()->render_settings_form();
                echo '</div>';
            } else {
                echo '<div class="notice notice-warning"><p>WhatsApp integration not loaded.</p></div>';
            }

            // Google Sheets Settings
            if (class_exists('Ofast_X_Google_Sheets')) {
                echo '<div class="ofast-channel-section" style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;margin-top:20px;">';
                Ofast_X_Google_Sheets::get_instance()->render_settings_form();
                echo '</div>';
            } else {
                echo '<div class="notice notice-warning"><p>Google Sheets integration not loaded.</p></div>';
            }
            ?>
        </div>
<?php
    }
}
