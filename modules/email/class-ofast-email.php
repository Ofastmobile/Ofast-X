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
     * Setup system hooks
     */
    private function setup_hooks()
    {
        // Hook for batch email processing (used by queue system)
        add_action('ofast_send_email_batch', array($this, 'process_email_batch'), 10, 1);

        // Apply template to WordPress emails based on settings
        $apply_to = get_option('ofast_email_apply_to', array('emailer'));
        if (in_array('wordpress', $apply_to) || in_array('all', $apply_to)) {
            add_filter('wp_mail', array($this, 'apply_template_to_wp_mail'), 999, 1);
        }

        // Daily cleanup
        if (!wp_next_scheduled('ofast_email_cleanup')) {
            wp_schedule_event(time(), 'daily', 'ofast_email_cleanup');
        }
        add_action('ofast_email_cleanup', array($this, 'cleanup_old_logs'));
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

    /**
     * Process scheduled email batch (called by WordPress cron)
     * 
     * @param array $args Contains subject, body, user_ids
     */
    public function process_email_batch($args)
    {
        // Extract arguments
        $subject = isset($args['subject']) ? sanitize_text_field($args['subject']) : '';
        $body = isset($args['body']) ? wp_kses_post($args['body']) : '';
        $user_ids = isset($args['user_ids']) ? array_map('absint', (array) $args['user_ids']) : array();

        if (empty($subject) || empty($user_ids)) {
            error_log('Ofast-X Email Batch: Invalid arguments - missing subject or user_ids');
            return 0;
        }

        // Get headers
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('ofast_email_from_name', get_bloginfo('name')) . ' <' . get_option('ofast_email_reply_to', get_option('admin_email')) . '>',
            'Reply-To: ' . get_option('ofast_email_reply_to', get_option('admin_email'))
        );

        // Get users
        $users = get_users(array(
            'include' => $user_ids,
            'fields' => 'all'
        ));

        $sent_count = 0;
        $sample_body = '';

        foreach ($users as $user) {
            $final_body = $this->replace_placeholders($body, $user);
            $email_html = Ofast_X_Email_Template::get_template($final_body);

            if (empty($sample_body)) {
                $sample_body = $email_html;
            }

            if (wp_mail($user->user_email, $subject, $email_html, $headers)) {
                $sent_count++;
            } else {
                error_log('Ofast-X Email Batch: Failed to send to ' . $user->user_email);
            }

            // Small delay between emails to prevent server overload
            if (count($users) > 10) {
                usleep(100000); // 0.1 second
            }
        }

        // Log the batch
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'ofast_email_logs', array(
            'subject' => $subject,
            'body' => $sample_body,
            'sent_at' => current_time('mysql'),
            'recipient_count' => $sent_count,
            'status' => 'sent',
            'notes' => 'Scheduled batch - ' . $sent_count . ' of ' . count($user_ids) . ' sent'
        ));

        error_log('Ofast-X Email Batch: Successfully sent ' . $sent_count . ' of ' . count($user_ids) . ' emails');

        return $sent_count;
    }

    /**
     * Apply email template to WordPress emails (wp_mail filter)
     * 
     * @param array $args The wp_mail arguments
     * @return array Modified arguments with template applied
     */
    public function apply_template_to_wp_mail($args)
    {
        // Get the email message
        $message = $args['message'];

        // Skip if message is empty or already has HTML template markers
        if (empty($message)) {
            return $args;
        }

        // Check if already has full HTML structure (avoid double-wrapping)
        if (stripos($message, '<!DOCTYPE') !== false || stripos($message, '<html') !== false) {
            return $args;
        }

        // Wrap in template
        $args['message'] = Ofast_X_Email_Template::get_template($message);

        // Ensure content type is HTML
        if (!isset($args['headers'])) {
            $args['headers'] = array();
        }
        if (is_string($args['headers'])) {
            $args['headers'] = explode("\n", $args['headers']);
        }

        // Check if Content-Type header already exists
        $has_content_type = false;
        foreach ($args['headers'] as $header) {
            if (stripos($header, 'Content-Type') !== false) {
                $has_content_type = true;
                break;
            }
        }

        // Add HTML content type if not present
        if (!$has_content_type) {
            $args['headers'][] = 'Content-Type: text/html; charset=UTF-8';
        }

        return $args;
    }
}
