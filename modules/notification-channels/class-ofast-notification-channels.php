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
            /* Header Styles */
            .ofast-header {
                display: flex;
                align-items: center;
                gap: 20px;
                background: #fff;
                padding: 25px 30px;
                border-radius: 12px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                margin-bottom: 30px;
                margin-top: 20px;
            }
            .ofast-header-icon {
                width: 56px;
                height: 56px;
                background: #fff;
                border: 1px solid #e2e8f0;
                box-shadow: 0 2px 4px rgba(0,0,0,0.02);
                border-radius: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .ofast-header-icon .dashicons {
                font-size: 28px;
                width: 28px;
                height: 28px;
                color: #6366f1;
            }
            .ofast-header-content h1 {
                margin: 0 0 5px 0;
                font-size: 24px;
                font-weight: 700;
                color: #1e293b;
                display: block;
                padding: 0;
            }
            .ofast-header-content p {
                margin: 0;
                color: #64748b;
                font-size: 14px;
            }

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

            /* Button Override */
            .button.button-primary {
                background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%) !important;
                border-color: #6366f1 !important;
                text-shadow: none !important;
                box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3) !important;
                transition: all 0.3s ease !important;
                padding: 10px 20px !important;
                height: auto !important;
                border-radius: 8px !important;
            }
            .button.button-primary:hover {
                background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%) !important;
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4) !important;
            }
            .button.button-primary:active {
                transform: translateY(0);
            }
        </style>

        <div class="wrap">
            <div class="ofast-header">
                <div class="ofast-header-icon">
                    <span class="dashicons dashicons-megaphone"></span>
                </div>
                <div class="ofast-header-content">
                    <h1>Notification Channels</h1>
                    <p>Configure external notification channels for form submissions and other events.</p>
                </div>
            </div>

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
                echo Ofast_X_Toast::render('WhatsApp integration not loaded.', 'warning');
            }

            // Google Sheets Settings
            if (class_exists('Ofast_X_Google_Sheets')) {
                echo '<div class="ofast-channel-section" style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;margin-top:20px;">';
                Ofast_X_Google_Sheets::get_instance()->render_settings_form();
                echo '</div>';
            } else {
                echo Ofast_X_Toast::render('Google Sheets integration not loaded.', 'warning');
            }
            ?>
        </div>
<?php
    }
}
