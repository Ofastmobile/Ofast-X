<?php
/**
 * Ofast X Email Admin Interface
 * Secure rewrite of your existing email code
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Email_Admin {
    
    private $page_hook;
    
    /**
     * Initialize admin interface
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_ofast_render_preview', array($this, 'ajax_render_preview'));
        add_action('wp_ajax_ofast_send_test_email', array($this, 'ajax_send_test_email'));
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        $this->page_hook = add_menu_page(
            'Ofast Emailer',
            'Ofast Emailer',
            'manage_options',
            'ofast-emailer',
            array($this, 'render_send_page'),
            'dashicons-email',
            25
        );
        
        add_submenu_page(
            'ofast-emailer',
            'Send Email',
            'Send Email',
            'manage_options',
            'ofast-emailer',
            array($this, 'render_send_page')
        );
        
        add_submenu_page(
            'ofast-emailer',
            'Scheduled Emails',
            'Scheduled',
            'manage_options',
            'ofast-scheduled-emails',
            array($this, 'render_scheduled_page')
        );
        
        add_submenu_page(
            'ofast-emailer',
            'Email History',
            'History',
            'manage_options',
            'ofast-email-history',
            array($this, 'render_history_page')
        );
    }
    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts($hook) {
        if ($hook != $this->page_hook) {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_style(
            'ofast-email-admin',
            OFAST_X_PLUGIN_URL . 'modules/email/css/email-admin.css',
            array(),
            OFAST_X_VERSION
        );
        
        wp_enqueue_script(
            'ofast-email-admin',
            OFAST_X_PLUGIN_URL . 'modules/email/js/email-admin.js',
            array('jquery'),
            OFAST_X_VERSION,
            true
        );
        
        // Localize script for AJAX
        wp_localize_script('ofast-email-admin', 'ofast_email', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ofast_email_nonce'),
            'sending_text' => __('Sending...', 'ofast-x'),
            'sent_text' => __('Sent!', 'ofast-x')
        ));
    }
    
    /**
     * Render send email page (your main interface)
     */
    public function render_send_page() {
        // SECURITY: Check capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'ofast-x'));
        }
        
        $result_message = '';
        
        // Handle form submission with SECURITY
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email'])) {
            $result_message = $this->handle_email_submission();
        }
        
        // Your existing UI code (secured)
        echo '<div class="wrap">';
        echo '<h1>📧 Send Email</h1>';
        
        // Show result message
        if ($result_message) {
            echo $result_message;
        }
        
        // Render the form
        $this->render_email_form();
        
        echo '</div>';
    }
    
    /**
     * Handle email form submission with SECURITY
     */
    private function handle_email_submission() {
        // SECURITY: Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'], 'ofast_email_send')) {
            return '<div class="notice notice-error"><p>Security verification failed.</p></div>';
        }
        
        // SECURITY: Check capabilities
        if (!current_user_can('manage_options')) {
            return '<div class="notice notice-error"><p>You do not have permission to send emails.</p></div>';
        }
        
        // SANITIZE inputs
        $subject = sanitize_text_field($_POST['subject'] ?? '');
        $body = wp_kses_post($_POST['message'] ?? '');
        $selected_roles = array_map('sanitize_text_field', $_POST['roles'] ?? []);
        $user_ids_input = sanitize_text_field($_POST['user_ids'] ?? '');
        $send_test = isset($_POST['test_email']);
        $schedule_time = sanitize_text_field($_POST['schedule_time'] ?? '');
        
        // VALIDATE required fields
        if (empty($subject) || empty($body)) {
            return '<div class="notice notice-error"><p>Subject and message are required.</p></div>';
        }
        
        // Process user IDs (your existing logic)
        $selected_user_ids = $this->parse_user_ids($user_ids_input);
        
        if ($send_test) {
            // Send test email to current user
            return $this->send_test_email($subject, $body);
        } else {
            // Send or schedule bulk email
            return $this->send_bulk_email($subject, $body, $selected_roles, $selected_user_ids, $schedule_time);
        }
    }
    
    /**
     * Parse user IDs from input (your existing logic)
     */
    private function parse_user_ids($input) {
        $input_ids = preg_split('/\s*,\s*/', $input);
        $selected_user_ids = [];
        
        foreach ($input_ids as $entry) {
            if (strpos($entry, '-') !== false) {
                [$start, $end] = array_map('intval', explode('-', $entry));
                $selected_user_ids = array_merge($selected_user_ids, range($start, $end));
            } elseif (is_numeric($entry)) {
                $selected_user_ids[] = intval($entry);
            }
        }
        
        return $selected_user_ids;
    }
    
    /**
     * Send test email
     */
    private function send_test_email($subject, $body) {
        $user = wp_get_current_user();
        $final_body = $this->replace_placeholders($body, $user);
        
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('ofast_email_from_name', 'Ofastshop Digitals') . ' <' . get_option('ofast_email_reply_to', 'support@ofastshop.com') . '>',
            'Reply-To: ' . get_option('ofast_email_reply_to', 'support@ofastshop.com')
        ];
        
        if (wp_mail($user->user_email, $subject, $this->email_template($final_body), $headers)) {
            return '<div class="notice notice-success"><p>✅ Test email sent to ' . esc_html($user->user_email) . '</p></div>';
        } else {
            return '<div class="notice notice-error"><p>❌ Failed to send test email.</p></div>';
        }
    }
    
    /**
     * Send bulk email (your existing logic, secured)
     */
    private function send_bulk_email($subject, $body, $selected_roles, $selected_user_ids, $schedule_time) {
        // Merge user IDs + roles
        $total_ids = $selected_user_ids;
        if (!empty($selected_roles)) {
            $role_ids = get_users(['role__in' => $selected_roles, 'fields' => 'ID']);
            $total_ids = array_unique(array_merge($total_ids, $role_ids));
        }
        
        $total_recipients = count($total_ids);
        
        if ($total_recipients <= 50) {
            // Send immediately
            $sent_count = $this->send_immediate_batch($subject, $body, $total_ids);
            return '<div class="notice notice-success"><p>✅ Sent immediately to ' . $sent_count . ' user(s)</p></div>';
        } else {
            // Schedule in batches (your existing logic)
            $chunks = array_chunk($total_ids, 40);
            $timestamp = $schedule_time ? strtotime($schedule_time) : time();
            
            foreach ($chunks as $i => $chunk) {
                wp_schedule_single_event(
                    $timestamp + ($i * 3600),
                    'ofast_scheduled_email_event',
                    [
                        'subject' => $subject,
                        'body' => $body,
                        'selected_roles' => [],
                        'selected_user_ids' => $chunk
                    ]
                );
            }
            
            return '<div class="notice notice-success"><p>🕒 ' . count($chunks) . ' batches scheduled starting ' . date('Y-m-d H:i', $timestamp) . '</p></div>';
        }
    }
    
    /**
     * Send immediate email batch
     */
    private function send_immediate_batch($subject, $body, $user_ids) {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('ofast_email_from_name', 'Ofastshop Digitals') . ' <' . get_option('ofast_email_reply_to', 'support@ofastshop.com') . '>',
            'Reply-To: ' . get_option('ofast_email_reply_to', 'support@ofastshop.com')
        ];
        
        $sent_count = 0;
        $users = get_users(['include' => $user_ids]);
        
        foreach ($users as $user) {
            $message = $this->replace_placeholders($body, $user);
            if (wp_mail($user->user_email, $subject, $this->email_template($message), $headers)) {
                $sent_count++;
            }
        }
        
        // Log the send
        $this->log_email($subject, $sent_count, 'immediate');
        
        return $sent_count;
    }
    
    /**
     * Render email form (your existing UI)
     */
    private function render_email_form() {
        // SECURITY: Add nonce field
        wp_nonce_field('ofast_email_send', '_wpnonce', true, true);
        
        echo '<form method="post" enctype="multipart/form-data">';
        
        // Your existing form HTML (with security improvements)
        echo '<table class="form-table">';
        
        // Subject
        echo '<tr><th scope="row"><label for="subject">Subject</label></th>';
        echo '<td><input type="text" name="subject" id="subject" class="regular-text" required value="' . esc_attr($_POST['subject'] ?? '') . '"></td></tr>';
        
        // Message
        echo '<tr><th scope="row"><label for="message">Message</label></th>';
        echo '<td>';
        wp_editor(
            $_POST['message'] ?? '',
            'message',
            [
                'textarea_name' => 'message',
                'media_buttons' => true,
                'textarea_rows' => 10,
                'editor_class' => 'large-text'
            ]
        );
        echo '</td></tr>';
        
        // Roles
        echo '<tr><th scope="row">Select Roles</th><td>';
        global $wp_roles;
        foreach ($wp_roles->roles as $key => $role) {
            $checked = in_array($key, $_POST['roles'] ?? []) ? 'checked' : '';
            echo '<label><input type="checkbox" name="roles[]" value="' . esc_attr($key) . '" ' . $checked . '> ' . esc_html($role['name']) . '</label><br>';
        }
        echo '</td></tr>';
        
        // User IDs
        echo '<tr><th scope="row"><label for="user_ids">User ID(s) or Ranges</label></th>';
        echo '<td><input type="text" name="user_ids" id="user_ids" class="regular-text" value="' . esc_attr($_POST['user_ids'] ?? '') . '" placeholder="e.g., 5,12,30-35"></td></tr>';
        
        // Schedule
        echo '<tr><th scope="row"><label for="schedule_time">Schedule Time (optional)</label></th>';
        echo '<td><input type="datetime-local" name="schedule_time" id="schedule_time" value="' . esc_attr($_POST['schedule_time'] ?? '') . '"></td></tr>';
        
        // Test mode
        echo '<tr><th scope="row">Test Mode</th>';
        echo '<td><label><input type="checkbox" name="test_email" value="1" ' . checked(isset($_POST['test_email']), true, false) . '> Send to me as test only</label></td></tr>';
        
        echo '</table>';
        
        // Submit button
        echo '<p><button type="submit" name="send_email" class="button button-primary">🚀 Send / Schedule</button></p>';
        
        echo '</form>';
        
        // Add your user selection table and preview modal here
        $this->render_user_selection_table();
        $this->render_preview_modal();
    }
    
    /**
     * Render user selection table (your existing code)
     */
    private function render_user_selection_table() {
        // Your existing user table code goes here
        // I'll add the JavaScript for pagination/search
    }
    
    /**
     * Render preview modal (your existing code)
     */
    private function render_preview_modal() {
        // Your existing preview modal code
    }
    
    /**
     * Replace placeholders (your existing function)
     */
    private function replace_placeholders($body, $user) {
        return str_replace(
            ['{{user_id}}', '{{username}}', '{{user_display_name}}', '{{user_first_name}}', '{{user_last_name}}', '{{user_email}}'],
            [$user->ID, $user->user_login, $user->display_name, $user->first_name, $user->last_name, $user->user_email],
            $body
        );
    }
    
    /**
     * Email template (your existing function)
     */
    private function email_template($content) {
        // Use your existing template function
        if (function_exists('ofast_email_template')) {
            return ofast_email_template($content);
        }
        
        // Fallback template
        ob_start();
        ?>
        <div style="background:#f9f9f9;padding:30px;font-family:Segoe UI,Tahoma,Geneva,Verdana,sans-serif;">
            <div style="max-width:600px;margin:auto;background:#fff;border-radius:8px;padding:20px 30px;">
                <?php echo wpautop($content); ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Log email
     */
    private function log_email($subject, $recipient_count, $type = 'immediate') {
        global $wpdb;
        
        $wpdb->insert($wpdb->prefix . 'ofast_email_logs', [
            'subject' => $subject,
            'sent_at' => current_time('mysql'),
            'recipient_count' => $recipient_count,
            'notes' => ucfirst($type) . ' send'
        ]);
    }
    
    /**
     * AJAX render preview
     */
    public function ajax_render_preview() {
        // SECURITY: Verify nonce
        check_ajax_referer('ofast_email_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $body = wp_kses_post($_POST['body'] ?? '');
        echo $this->email_template($body);
        wp_die();
    }
    
    /**
     * AJAX send test email
     */
    public function ajax_send_test_email() {
        // SECURITY: Verify nonce
        check_ajax_referer('ofast_email_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $subject = sanitize_text_field($_POST['subject'] ?? 'Test Email');
        $body = wp_kses_post($_POST['body'] ?? '');
        
        $result = $this->send_test_email($subject, $body);
        wp_send_json_success($result);
    }
    
    /**
     * Render scheduled emails page
     */
    public function render_scheduled_page() {
        // Your existing scheduled emails page
        echo '<div class="wrap"><h1>📅 Scheduled Email Batches</h1>';
        echo '<p>Scheduled emails functionality will be implemented with Action Scheduler.</p>';
        echo '</div>';
    }
    
    /**
     * Render email history page
     */
    public function render_history_page() {
        // Your existing history page
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_email_logs';
        $logs = $wpdb->get_results("SELECT * FROM $table ORDER BY sent_at DESC LIMIT 100");
        
        echo '<div class="wrap"><h1>📋 Email History</h1>';
        
        if (empty($logs)) {
            echo '<p>No emails have been logged yet.</p>';
        } else {
            echo '<table class="widefat fixed striped">';
            echo '<thead><tr><th>ID</th><th>Subject</th><th>Sent At</th><th>Recipients</th><th>Notes</th></tr></thead>';
            echo '<tbody>';
            foreach ($logs as $log) {
                echo '<tr>';
                echo '<td>' . esc_html($log->id) . '</td>';
                echo '<td>' . esc_html(wp_trim_words($log->subject, 12, '...')) . '</td>';
                echo '<td>' . esc_html($log->sent_at) . '</td>';
                echo '<td>' . esc_html($log->recipient_count) . '</td>';
                echo '<td>' . esc_html($log->notes) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        
        echo '</div>';
    }
}