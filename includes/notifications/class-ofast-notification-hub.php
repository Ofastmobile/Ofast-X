<?php

/**
 * Ofast X - Notification Hub
 * Central dispatcher for multi-channel notifications (Email, WhatsApp, Google Sheets)
 * 
 * Usage:
 * Ofast_X_Notification_Hub::dispatch('newsletter_subscription', [
 *     'email' => 'user@example.com',
 *     'name' => 'John Doe',
 *     'source' => 'homepage'
 * ]);
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Notification_Hub
{
    private static $instance = null;

    /**
     * Event types supported by the hub
     */
    const EVENT_NEWSLETTER_SUBSCRIPTION = 'newsletter_subscription';
    const EVENT_CONTACT_FORM = 'contact_form';
    const EVENT_WOOCOMMERCE_ORDER = 'woocommerce_order';
    const EVENT_USER_REGISTRATION = 'user_registration';
    const EVENT_CUSTOM = 'custom';

    /**
     * Channel types
     */
    const CHANNEL_EMAIL = 'email';
    const CHANNEL_WHATSAPP = 'whatsapp';
    const CHANNEL_GOOGLE_SHEETS = 'google_sheets';
    const CHANNEL_DASHBOARD = 'dashboard';

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct()
    {
        // Initialize on construct if needed
    }

    /**
     * Dispatch notification to all enabled channels
     * 
     * @param string $event_type One of the EVENT_* constants
     * @param array $data Event-specific data
     * @param array $options Optional override options
     * @return array Results from each channel
     */
    public static function dispatch($event_type, $data, $options = array())
    {
        $instance = self::get_instance();
        $results = array();

        // Get enabled channels for this event
        $channels = $instance->get_enabled_channels($event_type);

        // Add timestamp if not present
        if (!isset($data['timestamp'])) {
            $data['timestamp'] = current_time('mysql');
        }

        // Add IP if not present
        if (!isset($data['ip'])) {
            $data['ip'] = $instance->get_client_ip();
        }

        // Dispatch to each enabled channel
        foreach ($channels as $channel) {
            try {
                $result = $instance->send_to_channel($channel, $event_type, $data);
                $results[$channel] = $result;

                // Log the notification
                $instance->log_notification($event_type, $channel, $data, $result);
            } catch (Exception $e) {
                $results[$channel] = array(
                    'success' => false,
                    'error' => $e->getMessage()
                );
                $instance->log_notification($event_type, $channel, $data, array(
                    'success' => false,
                    'error' => $e->getMessage()
                ));
            }
        }

        // Fire action for extensibility
        do_action('ofast_notification_dispatched', $event_type, $data, $results);

        return $results;
    }

    /**
     * Get enabled channels for a specific event type
     */
    private function get_enabled_channels($event_type)
    {
        $settings = get_option('ofast_notification_channels', array());
        $channels = array();

        // Check each channel
        if (!empty($settings[$event_type][self::CHANNEL_EMAIL])) {
            $channels[] = self::CHANNEL_EMAIL;
        }
        if (!empty($settings[$event_type][self::CHANNEL_WHATSAPP])) {
            $channels[] = self::CHANNEL_WHATSAPP;
        }
        if (!empty($settings[$event_type][self::CHANNEL_GOOGLE_SHEETS])) {
            $channels[] = self::CHANNEL_GOOGLE_SHEETS;
        }

        // Dashboard logging is always enabled
        $channels[] = self::CHANNEL_DASHBOARD;

        // If no settings exist, enable email by default for backward compatibility
        if (empty($settings) && in_array($event_type, array(
            self::EVENT_NEWSLETTER_SUBSCRIPTION,
            self::EVENT_CONTACT_FORM
        ))) {
            $channels[] = self::CHANNEL_EMAIL;
        }

        return array_unique($channels);
    }

    /**
     * Send notification to specific channel
     */
    private function send_to_channel($channel, $event_type, $data)
    {
        switch ($channel) {
            case self::CHANNEL_EMAIL:
                return $this->send_email($event_type, $data);

            case self::CHANNEL_WHATSAPP:
                return $this->send_whatsapp($event_type, $data);

            case self::CHANNEL_GOOGLE_SHEETS:
                return $this->send_to_sheets($event_type, $data);

            case self::CHANNEL_DASHBOARD:
                return $this->log_to_dashboard($event_type, $data);

            default:
                return array('success' => false, 'error' => 'Unknown channel');
        }
    }

    /**
     * Send email notification
     */
    private function send_email($event_type, $data)
    {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');

        // Build subject and message based on event type
        switch ($event_type) {
            case self::EVENT_NEWSLETTER_SUBSCRIPTION:
                $subject = "[$site_name] New Newsletter Subscriber";
                $message = $this->build_newsletter_email($data);
                break;

            case self::EVENT_CONTACT_FORM:
                $subject = "[$site_name] New Contact Form Submission";
                $message = $this->build_contact_email($data);
                break;

            case self::EVENT_WOOCOMMERCE_ORDER:
                $subject = "[$site_name] New Order Notification";
                $message = $this->build_order_email($data);
                break;

            default:
                $subject = "[$site_name] Notification";
                $message = $this->build_generic_email($data);
        }

        // Use wp_mail (will use SMTP if configured)
        $sent = wp_mail($admin_email, $subject, $message, array('Content-Type: text/html; charset=UTF-8'));

        return array(
            'success' => $sent,
            'recipient' => $admin_email,
            'error' => $sent ? null : 'Email sending failed'
        );
    }

    /**
     * Send WhatsApp notification
     */
    private function send_whatsapp($event_type, $data)
    {
        // Check if WhatsApp module is configured
        if (!class_exists('Ofast_X_WhatsApp')) {
            return array(
                'success' => false,
                'error' => 'WhatsApp module not loaded',
                'skipped' => true
            );
        }

        $whatsapp = Ofast_X_WhatsApp::get_instance();
        if (!$whatsapp->is_configured()) {
            return array(
                'success' => false,
                'error' => 'WhatsApp not configured',
                'skipped' => true
            );
        }

        // Build message
        $message = $this->build_whatsapp_message($event_type, $data);

        return $whatsapp->send_admin_notification($message);
    }

    /**
     * Send to Google Sheets
     */
    private function send_to_sheets($event_type, $data)
    {
        // Check if Google Sheets module is configured
        if (!class_exists('Ofast_X_Google_Sheets')) {
            return array(
                'success' => false,
                'error' => 'Google Sheets module not loaded',
                'skipped' => true
            );
        }

        $sheets = Ofast_X_Google_Sheets::get_instance();
        if (!$sheets->is_configured()) {
            return array(
                'success' => false,
                'error' => 'Google Sheets not configured',
                'skipped' => true
            );
        }

        // Build row data
        $row = $this->build_sheets_row($event_type, $data);

        return $sheets->append_row($event_type, $row);
    }

    /**
     * Log to dashboard (internal log)
     */
    private function log_to_dashboard($event_type, $data)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ofast_notification_log';

        // Check if table exists (might not be created yet)
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return array(
                'success' => false,
                'error' => 'Log table not found',
                'skipped' => true
            );
        }

        $result = $wpdb->insert($table, array(
            'event_type' => $event_type,
            'channel' => 'dashboard',
            'recipient' => isset($data['email']) ? $data['email'] : '',
            'status' => 'logged',
            'message' => wp_json_encode($data),
            'created_at' => current_time('mysql')
        ));

        return array(
            'success' => $result !== false,
            'error' => $result === false ? $wpdb->last_error : null
        );
    }

    /**
     * Log notification to database
     */
    private function log_notification($event_type, $channel, $data, $result)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ofast_notification_log';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return;
        }

        $wpdb->insert($table, array(
            'event_type' => $event_type,
            'channel' => $channel,
            'recipient' => isset($data['email']) ? $data['email'] : (isset($data['phone']) ? $data['phone'] : ''),
            'status' => !empty($result['success']) ? 'sent' : (!empty($result['skipped']) ? 'skipped' : 'failed'),
            'message' => wp_json_encode(array_merge($data, array('_result' => $result))),
            'response' => isset($result['error']) ? $result['error'] : '',
            'created_at' => current_time('mysql')
        ));
    }

    /**
     * Build newsletter subscription email
     */
    private function build_newsletter_email($data)
    {
        $html = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">';
        $html .= '<h2 style="color: #1e88e5;">New Newsletter Subscriber</h2>';
        $html .= '<table style="width: 100%; border-collapse: collapse;">';
        $html .= '<tr><td style="padding: 10px; border-bottom: 1px solid #eee;"><strong>Name:</strong></td>';
        $html .= '<td style="padding: 10px; border-bottom: 1px solid #eee;">' . esc_html($data['name'] ?? 'N/A') . '</td></tr>';
        $html .= '<tr><td style="padding: 10px; border-bottom: 1px solid #eee;"><strong>Email:</strong></td>';
        $html .= '<td style="padding: 10px; border-bottom: 1px solid #eee;">' . esc_html($data['email'] ?? 'N/A') . '</td></tr>';
        $html .= '<tr><td style="padding: 10px; border-bottom: 1px solid #eee;"><strong>Source:</strong></td>';
        $html .= '<td style="padding: 10px; border-bottom: 1px solid #eee;">' . esc_html($data['source'] ?? 'Unknown') . '</td></tr>';
        $html .= '<tr><td style="padding: 10px; border-bottom: 1px solid #eee;"><strong>IP:</strong></td>';
        $html .= '<td style="padding: 10px; border-bottom: 1px solid #eee;">' . esc_html($data['ip'] ?? 'N/A') . '</td></tr>';
        $html .= '<tr><td style="padding: 10px;"><strong>Time:</strong></td>';
        $html .= '<td style="padding: 10px;">' . esc_html($data['timestamp'] ?? current_time('mysql')) . '</td></tr>';
        $html .= '</table>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Build contact form email
     */
    private function build_contact_email($data)
    {
        $html = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">';
        $html .= '<h2 style="color: #4caf50;">New Contact Form Submission</h2>';
        $html .= '<table style="width: 100%; border-collapse: collapse;">';

        foreach ($data as $key => $value) {
            if (strpos($key, '_') === 0) continue; // Skip internal fields
            $html .= '<tr><td style="padding: 10px; border-bottom: 1px solid #eee;"><strong>' . esc_html(ucfirst($key)) . ':</strong></td>';
            $html .= '<td style="padding: 10px; border-bottom: 1px solid #eee;">' . esc_html($value) . '</td></tr>';
        }

        $html .= '</table>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Build order notification email
     */
    private function build_order_email($data)
    {
        $html = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">';
        $html .= '<h2 style="color: #ff9800;">New Order Notification</h2>';
        $html .= '<p>Order ID: <strong>' . esc_html($data['order_id'] ?? 'N/A') . '</strong></p>';
        $html .= '<p>Customer: <strong>' . esc_html($data['name'] ?? 'N/A') . '</strong></p>';
        $html .= '<p>Email: <strong>' . esc_html($data['email'] ?? 'N/A') . '</strong></p>';
        $html .= '<p>Total: <strong>' . esc_html($data['total'] ?? 'N/A') . '</strong></p>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Build generic email
     */
    private function build_generic_email($data)
    {
        $html = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">';
        $html .= '<h2>Notification</h2>';
        $html .= '<pre>' . esc_html(print_r($data, true)) . '</pre>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Build WhatsApp message
     */
    private function build_whatsapp_message($event_type, $data)
    {
        switch ($event_type) {
            case self::EVENT_NEWSLETTER_SUBSCRIPTION:
                return "*New Subscriber*\n" .
                    "Name: " . ($data['name'] ?? 'N/A') . "\n" .
                    "Email: " . ($data['email'] ?? 'N/A') . "\n" .
                    "Time: " . ($data['timestamp'] ?? current_time('mysql'));

            case self::EVENT_CONTACT_FORM:
                return "*New Contact*\n" .
                    "Name: " . ($data['name'] ?? 'N/A') . "\n" .
                    "Email: " . ($data['email'] ?? 'N/A') . "\n" .
                    "Message: " . substr($data['message'] ?? '', 0, 100);

            case self::EVENT_WOOCOMMERCE_ORDER:
                return "*New Order*\n" .
                    "Order: #" . ($data['order_id'] ?? 'N/A') . "\n" .
                    "Customer: " . ($data['name'] ?? 'N/A') . "\n" .
                    "Total: " . ($data['total'] ?? 'N/A');

            default:
                return "*Notification*\n" . wp_json_encode($data);
        }
    }

    /**
     * Build Google Sheets row
     */
    private function build_sheets_row($event_type, $data)
    {
        switch ($event_type) {
            case self::EVENT_NEWSLETTER_SUBSCRIPTION:
                return array(
                    $data['timestamp'] ?? current_time('mysql'),
                    $data['name'] ?? '',
                    $data['email'] ?? '',
                    $data['source'] ?? '',
                    $data['ip'] ?? ''
                );

            case self::EVENT_CONTACT_FORM:
                return array(
                    $data['timestamp'] ?? current_time('mysql'),
                    $data['name'] ?? '',
                    $data['email'] ?? '',
                    $data['subject'] ?? '',
                    $data['message'] ?? '',
                    $data['ip'] ?? ''
                );

            case self::EVENT_WOOCOMMERCE_ORDER:
                return array(
                    $data['timestamp'] ?? current_time('mysql'),
                    $data['order_id'] ?? '',
                    $data['name'] ?? '',
                    $data['email'] ?? '',
                    $data['total'] ?? '',
                    $data['status'] ?? ''
                );

            default:
                return array_values($data);
        }
    }

    /**
     * Get client IP address
     */
    private function get_client_ip()
    {
        $ip_headers = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        );

        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Get all notification settings
     */
    public static function get_settings()
    {
        return get_option('ofast_notification_channels', array());
    }

    /**
     * Save notification settings
     */
    public static function save_settings($settings)
    {
        return update_option('ofast_notification_channels', $settings);
    }

    /**
     * Check if a specific channel is enabled for an event
     */
    public static function is_channel_enabled($event_type, $channel)
    {
        $settings = self::get_settings();
        return !empty($settings[$event_type][$channel]);
    }
}
