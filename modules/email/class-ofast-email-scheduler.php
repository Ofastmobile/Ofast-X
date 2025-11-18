<?php
/**
 * Ofast X Email Scheduler
 * Fixed version with proper security and working scheduling
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Email_Scheduler {
    
    /**
     * Initialize scheduler
     */
    public function init() {
        // Register scheduled action
        add_action('ofast_scheduled_email_event', array($this, 'process_scheduled_email'), 10, 4);
        
        // Schedule daily cleanup
        add_action('ofast_daily_cleanup', array($this, 'cleanup_old_logs'));
        
        // Schedule the cleanup event if not exists
        if (!wp_next_scheduled('ofast_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'ofast_daily_cleanup');
        }
    }
    
    /**
     * Process scheduled email batch
     */
    public function process_scheduled_email($subject, $body, $selected_roles, $selected_user_ids) {
        // SECURITY: Validate inputs
        $subject = sanitize_text_field($subject);
        $body = wp_kses_post($body);
        $selected_roles = array_map('sanitize_text_field', (array)$selected_roles);
        $selected_user_ids = array_map('absint', (array)$selected_user_ids);
        
        // Prepare headers
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('ofast_email_from_name', 'Ofastshop Digitals') . ' <' . get_option('ofast_email_reply_to', 'support@ofastshop.com') . '>',
            'Reply-To: ' . get_option('ofast_email_reply_to', 'support@ofastshop.com')
        );
        
        // Get recipients
        $recipients = $this->get_recipients($selected_roles, $selected_user_ids);
        $sent_count = 0;
        
        // Send to each recipient
        foreach ($recipients as $user) {
            $final_body = $this->replace_placeholders($body, $user);
            
            if (wp_mail($user->user_email, $subject, $this->email_template($final_body), $headers)) {
                $sent_count++;
            } else {
                error_log('Ofast-X: Failed to send email to: ' . $user->user_email);
            }
            
            // Small delay to prevent server overload
            if (count($recipients) > 10) {
                usleep(500000); // 0.5 second delay
            }
        }
        
        // Log the batch
        $this->log_email_batch($subject, $sent_count, 'scheduled');
        
        return $sent_count;
    }
    
    /**
     * Get recipients based on roles and user IDs
     */
    private function get_recipients($selected_roles, $selected_user_ids) {
        $recipients = array();
        
        // Get users by roles
        if (!empty($selected_roles)) {
            $role_users = get_users(array(
                'role__in' => $selected_roles,
                'fields' => 'all'
            ));
            $recipients = array_merge($recipients, $role_users);
        }
        
        // Get users by IDs
        if (!empty($selected_user_ids)) {
            $id_users = get_users(array(
                'include' => $selected_user_ids,
                'fields' => 'all'
            ));
            $recipients = array_merge($recipients, $id_users);
        }
        
        // Remove duplicates
        $unique_recipients = array();
        foreach ($recipients as $user) {
            $unique_recipients[$user->ID] = $user;
        }
        
        return array_values($unique_recipients);
    }
    
    /**
     * Replace placeholders in email body
     */
    private function replace_placeholders($body, $user) {
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
    private function email_template($content) {
        // Use your existing template function
        if (function_exists('ofast_email_template')) {
            return ofast_email_template($content);
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
    private function log_email_batch($subject, $sent_count, $type = 'scheduled') {
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
    public function cleanup_old_logs() {
        global $wpdb;
        
        $retention_days = get_option('ofast_email_retention_days', 90);
        $delete_before = date('Y-m-d H:i:s', strtotime('-' . $retention_days . ' days'));
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ofast_email_logs WHERE sent_at < %s",
            $delete_before
        ));
    }
}