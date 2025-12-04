<?php

/**
 * Ofast X Email Main Controller
 * Complete email system with modern template - ALL 13 FIXES INTEGRATED
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Email
{

    private $admin;

    /**
     * Initialize complete email system
     */
    public function init()
    {
        // Load all email components
        $this->load_dependencies();
        $this->init_components();
        $this->setup_hooks();
    }

    /**
     * Load all required files
     */
    private function load_dependencies()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-template.php';
        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-admin.php';
    }

    /**
     * Initialize components
     */
    private function init_components()
    {
        $this->admin = new Ofast_X_Email_Admin();
        $this->admin->init();
    }

    /**
     * Setup system hooks (INTEGRATED ALL 13 FIXES)
     */
    private function setup_hooks()
    {
        // Email sending hook with batch tracking
        add_action('ofast_scheduled_email_event', array($this, 'handle_scheduled_email'), 10, 6);

        // Daily cleanup
        if (!wp_next_scheduled('ofast_email_cleanup')) {
            wp_schedule_event(time(), 'daily', 'ofast_email_cleanup');
        }
        add_action('ofast_email_cleanup', array($this, 'cleanup_old_logs'));
    }

    /**
     * Handle scheduled emails (with batch tracking)
     */
    public function handle_scheduled_email($subject, $body, $selected_roles, $selected_user_ids, $batch_number = 1, $total_batches = 1)
    {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('ofastx_email_from_name', get_bloginfo('name')) . ' <' . get_option('ofastx_email_reply_to', get_option('admin_email')) . '>',
            'Reply-To: ' . get_option('ofastx_email_reply_to', get_option('admin_email'))
        ];

        $recipients = [];

        if (!empty($selected_roles)) {
            $recipients = get_users(['role__in' => $selected_roles]);
        }

        if (!empty($selected_user_ids)) {
            $recipients = array_merge($recipients, get_users(['include' => $selected_user_ids]));
        }

        $sent_count = 0;
        foreach ($recipients as $user) {
            $final_body = $this->replace_placeholders($body, $user);

            // Use modern template
            $email_html = Ofast_X_Email_Template::get_template($final_body);

            if (wp_mail($user->user_email, $subject, $email_html, $headers)) {
                $sent_count++;
            }
        }

        // Log with batch info
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'ofast_email_logs', [
            'subject' => $subject,
            'sent_at' => current_time('mysql'),
            'recipient_count' => $sent_count,
            'notes' => "Batch $batch_number of $total_batches (scheduled)"
        ]);
    }

    /**
     * Replace placeholders in email body
     */
    private function replace_placeholders($body, $user)
    {
        return str_replace(
            ['{{user_id}}', '{{username}}', '{{user_display_name}}', '{{user_first_name}}', '{{user_last_name}}', '{{user_email}}'],
            [$user->ID, $user->user_login, $user->display_name, $user->first_name ?? '', $user->last_name ?? '', $user->user_email],
            $body
        );
    }

    /**
     * Cleanup old email logs
     */
    public function cleanup_old_logs()
    {
        global $wpdb;
        $retention_days = get_option('ofastx_email_retention_days', 90);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ofast_email_logs WHERE sent_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retention_days
        ));
    }
}
