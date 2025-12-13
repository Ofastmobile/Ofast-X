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

        // Get channel statuses
        $whatsapp_configured = class_exists('Ofast_X_WhatsApp') && Ofast_X_WhatsApp::get_instance()->is_configured();
        $sheets_configured = class_exists('Ofast_X_Google_Sheets') && Ofast_X_Google_Sheets::get_instance()->is_configured();
?>
        <style>
            .ofast-status-cards {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 16px;
                margin: 20px 0 30px;
            }

            .ofast-status-card {
                background: #fff;
                padding: 20px;
                border-radius: 12px;
                border: 1px solid #e5e7eb;
                display: flex;
                align-items: center;
                gap: 16px;
            }

            .ofast-status-icon {
                width: 48px;
                height: 48px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 24px;
            }

            .ofast-status-icon.whatsapp {
                background: #dcfce7;
                color: #166534;
            }

            .ofast-status-icon.sheets {
                background: #dbeafe;
                color: #1d4ed8;
            }

            .ofast-status-icon.inactive {
                background: #f3f4f6;
                color: #6b7280;
            }

            .ofast-status-info h3 {
                margin: 0;
                font-size: 15px;
                font-weight: 600;
                color: #1e293b;
            }

            .ofast-status-info .status {
                font-size: 13px;
                margin-top: 4px;
                font-weight: 500;
            }

            .ofast-status-info .status.connected {
                color: #10b981;
            }

            .ofast-status-info .status.not-configured {
                color: #6b7280;
            }
        </style>

        <div class="wrap">
            <h1>Notification Channels</h1>
            <p>Configure external notification channels for form submissions and other events.</p>

            <!-- Status Indicator Cards -->
            <div class="ofast-status-cards">
                <div class="ofast-status-card">
                    <div class="ofast-status-icon <?php echo $whatsapp_configured ? 'whatsapp' : 'inactive'; ?>">
                        <?php echo $whatsapp_configured ? '✓' : '○'; ?>
                    </div>
                    <div class="ofast-status-info">
                        <h3>WhatsApp</h3>
                        <div class="status <?php echo $whatsapp_configured ? 'connected' : 'not-configured'; ?>">
                            <?php echo $whatsapp_configured ? 'Connected' : 'Not Configured'; ?>
                        </div>
                    </div>
                </div>

                <div class="ofast-status-card">
                    <div class="ofast-status-icon <?php echo $sheets_configured ? 'sheets' : 'inactive'; ?>">
                        <?php echo $sheets_configured ? '✓' : '○'; ?>
                    </div>
                    <div class="ofast-status-info">
                        <h3>Google Sheets</h3>
                        <div class="status <?php echo $sheets_configured ? 'connected' : 'not-configured'; ?>">
                            <?php echo $sheets_configured ? 'Connected' : 'Not Configured'; ?>
                        </div>
                    </div>
                </div>
            </div>

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
