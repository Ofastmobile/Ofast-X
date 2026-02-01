<?php

/**
 * Ofast X Email Scheduler
 * Uses Action Scheduler for reliable email batch scheduling
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Email_Scheduler
{

    /**
     * Initialize scheduler
     */
    public function init()
    {
        // Register Action Scheduler hook
        add_action('ofast_send_email_batch', array($this, 'process_scheduled_email'), 10, 1);

        // Schedule daily cleanup using Action Scheduler
        if (!as_next_scheduled_action('ofast_daily_cleanup')) {
            as_schedule_recurring_action(strtotime('tomorrow 2am'), DAY_IN_SECONDS, 'ofast_daily_cleanup');
        }
        add_action('ofast_daily_cleanup', array($this, 'cleanup_old_logs'));
    }

    /**
     * Process scheduled email batch
     * 
     * @param array $args Arguments containing subject, body, user_ids
     */
    public function process_scheduled_email($args)
    {
        // Extract and validate arguments
        $subject = sanitize_text_field($args['subject'] ?? '');
        $body = wp_kses_post($args['body'] ?? '');
        $user_ids = array_map('absint', (array)($args['user_ids'] ?? []));

        if (empty($subject) || empty($user_ids)) {
            error_log('Ofast-X Scheduler: Invalid arguments provided');
            return 0;
        }

        // Prepare headers
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('ofast_email_from_name', get_bloginfo('name')) . ' <' . get_option('ofast_email_reply_to', get_option('admin_email')) . '>',
            'Reply-To: ' . get_option('ofast_email_reply_to', get_option('admin_email'))
        );

        // Get recipients
        $recipients = get_users(array(
            'include' => $user_ids,
            'fields' => 'all'
        ));

        $sent_count = 0;

        // Send to each recipient
        foreach ($recipients as $user) {
            $final_body = $this->replace_placeholders($body, $user);

            if (wp_mail($user->user_email, $subject, $this->email_template($final_body), $headers)) {
                $sent_count++;
            } else {
                error_log('Ofast-X: Failed to send email to: ' . $user->user_email);
            }

            // Small delay to prevent server overload (0.5 seconds)
            if (count($recipients) > 10) {
                usleep(500000);
            }
        }

        // Log the batch
        $this->log_email_batch($subject, $sent_count, 'scheduled');

        return $sent_count;
    }

    /**
     * Replace placeholders in email body
     */
    private function replace_placeholders($body, $user)
    {
        $replacements = array(
            '{{user_id}}' => $user->ID,
            '{{username}}' => $user->user_login,
            '{{user_display_name}}' => $user->display_name,
            '{{user_first_name}}' => $user->first_name,
            '{{user_last_name}}' => $user->last_name,
            '{{user_email}}' => $user->user_email,
        );

        return str_replace(array_keys($replacements), array_values($replacements), $body);
    }

    /**
     * Email template wrapper
     */
    private function email_template($content)
    {
        // Use template class if available
        if (class_exists('Ofast_X_Email_Template')) {
            $template = new Ofast_X_Email_Template();
            return $template->render($content);
        }

        // Fallback template
        return '
        <div style="background:#f9f9f9;padding:30px;font-family:Segoe UI,Tahoma,Geneva,Verdana,sans-serif;">
            <div style="max-width:600px;margin:auto;background:#fff;border-radius:8px;padding:20px 30px;">
                ' . wpautop($content) . '
            </div>
        </div>';
    }

    /**
     * Log email batch
     */
    private function log_email_batch($subject, $sent_count, $type = 'scheduled')
    {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'ofast_email_logs', array(
            'subject' => $subject,
            'sent_at' => current_time('mysql'),
            'recipient_count' => $sent_count,
            'notes' => ucfirst($type) . ' batch'
        ));
    }

    /**
     * Cleanup old logs (GDPR compliance)
     */
    public function cleanup_old_logs()
    {
        global $wpdb;

        $retention_days = get_option('ofast_email_retention_days', 90);
        $delete_before = date('Y-m-d H:i:s', strtotime('-' . $retention_days . ' days'));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ofast_email_logs WHERE sent_at < %s",
            $delete_before
        ));
    }
}
