<?php

/**
 * Ofast X - Form Submissions Handler
 * Handles form submissions, validation, storage, and notifications
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Forms_Submissions
{
    /**
     * Handle AJAX form submission
     */
    public function handle_submission()
    {
        $form_id = absint($_POST['form_id'] ?? 0);

        if (!$form_id) {
            wp_send_json_error(array('message' => 'Invalid form.'));
        }

        // Verify nonce
        if (!wp_verify_nonce($_POST['ofast_form_nonce'] ?? '', 'ofast_form_submit_' . $form_id)) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh and try again.'));
        }

        // SECURITY: Honeypot check - hidden field should be empty
        if (!empty($_POST['ofast_hp_field'])) {
            // Log spam attempt silently
            error_log('Ofast Forms: Honeypot triggered from IP: ' . $this->get_client_ip());
            wp_send_json_error(array('message' => 'Submission failed. Please try again.'));
        }

        // SECURITY: Rate limiting - max 5 submissions per IP per hour
        $ip = $this->get_client_ip();
        $rate_limit_key = 'ofast_form_rate_' . md5($ip);
        $submissions = get_transient($rate_limit_key);

        if ($submissions === false) {
            $submissions = 0;
        }

        if ($submissions >= 5) {
            wp_send_json_error(array('message' => 'Too many submissions. Please try again later.'));
        }

        set_transient($rate_limit_key, $submissions + 1, HOUR_IN_SECONDS);

        // Get form
        $forms = Ofast_X_Forms::get_instance();
        $form = $forms->get_form($form_id);

        if (!$form || !$form->active) {
            wp_send_json_error(array('message' => 'Form not found.'));
        }

        // Verify Turnstile if configured
        if (class_exists('Ofast_X_Turnstile')) {
            $turnstile = Ofast_X_Turnstile::get_instance();
            if ($turnstile->is_configured()) {
                $token = $_POST['cf-turnstile-response'] ?? '';
                if (!$turnstile->verify($token)) {
                    wp_send_json_error(array('message' => 'Spam protection verification failed. Please try again.'));
                }
            }
        }

        // Validate and collect field data
        require_once OFAST_X_PLUGIN_DIR . 'modules/forms/class-ofast-forms-validator.php';
        $validator = new Ofast_X_Forms_Validator();
        $validation = $validator->validate($_POST['fields'] ?? array(), $form->fields);

        if (!$validation['valid']) {
            wp_send_json_error(array(
                'message' => 'Please correct the errors below.',
                'field_errors' => $validation['errors']
            ));
        }

        $submission_data = $validation['data'];

        // Store submission
        $submission_id = $this->store_submission($form_id, $submission_data);

        if (!$submission_id) {
            wp_send_json_error(array('message' => 'Failed to save submission. Please try again.'));
        }

        // Send notifications via Notification Hub
        $this->dispatch_notifications($form, $submission_data, $submission_id);

        // Get response
        $settings = $form->settings;
        $redirect_url = !empty($settings['redirect_url']) ? $settings['redirect_url'] : '';
        $success_message = $settings['success_message'] ?? 'Thank you! Your message has been sent.';

        if ($redirect_url) {
            wp_send_json_success(array('redirect' => $redirect_url));
        } else {
            wp_send_json_success(array('message' => $success_message));
        }
    }

    /**
     * Store submission in database
     */
    private function store_submission($form_id, $data)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_form_submissions';

        $result = $wpdb->insert($table, array(
            'form_id' => $form_id,
            'data' => wp_json_encode($data),
            'ip_address' => $this->get_client_ip(),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'referer' => sanitize_text_field($_SERVER['HTTP_REFERER'] ?? ''),
            'status' => 'unread',
            'submitted_at' => current_time('mysql')
        ));

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Dispatch notifications through Notification Hub
     */
    private function dispatch_notifications($form, $data, $submission_id)
    {
        $notifications = isset($form->notifications) ? $form->notifications : array();

        // Build message content
        $message_lines = array();
        foreach ($data as $label => $value) {
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $message_lines[] = $label . ': ' . $value;
        }
        $message_text = implode("\n", $message_lines);

        // Dispatch via Notification Hub
        if (class_exists('Ofast_X_Notification_Hub')) {
            $hub = Ofast_X_Notification_Hub::get_instance();

            // Build context
            $context = array(
                'form_id' => $form->id,
                'form_title' => $form->title,
                'submission_id' => $submission_id,
                'fields' => $data,
                'admin_email' => $notifications['admin_email'] ?? get_option('admin_email'),
                'email_subject' => $notifications['email_subject'] ?? 'New Contact Form Submission',
                'whatsapp_enabled' => !empty($notifications['whatsapp_enabled']),
                'gsheets_enabled' => !empty($notifications['gsheets_enabled'])
            );

            // Build email body
            $email_body = "<h2>New Submission: {$form->title}</h2>";
            $email_body .= "<table style='border-collapse:collapse;width:100%;'>";
            foreach ($data as $label => $value) {
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $email_body .= "<tr><td style='padding:10px;border:1px solid #ddd;font-weight:bold;'>" . esc_html($label) . "</td>";
                $email_body .= "<td style='padding:10px;border:1px solid #ddd;'>" . esc_html($value) . "</td></tr>";
            }
            $email_body .= "</table>";
            $email_body .= "<p style='margin-top:20px;color:#666;'>Submitted from: " . home_url() . "</p>";

            $context['email_body'] = $email_body;

            // Build WhatsApp message
            $context['whatsapp_message'] = "New {$form->title} submission:\n\n{$message_text}";

            // Build Google Sheets row
            $context['gsheets_row'] = array_merge(
                array(current_time('Y-m-d H:i:s')),
                array_values($data)
            );

            $hub->dispatch('contact_form', $context);
        }
    }

    /**
     * Get client IP address
     */
    private function get_client_ip()
    {
        $headers = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        );

        foreach ($headers as $header) {
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
     * Render admin submissions page
     */
    public function render_admin_page()
    {
        // SECURITY: Verify user has admin capability
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_form_submissions';
        $forms_table = $wpdb->prefix . 'ofast_forms';

        // Handle actions with security checks
        if (isset($_GET['action']) && isset($_GET['id'])) {
            $id = absint($_GET['id']);
            $action = sanitize_text_field($_GET['action']);

            // SECURITY: Verify nonce for ALL actions
            $nonce_action = 'submission_action_' . $id;
            if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', $nonce_action)) {
                wp_die(__('Security check failed. Please try again.'));
            }

            switch ($action) {
                case 'mark_read':
                    $wpdb->update($table, array('status' => 'read', 'read_at' => current_time('mysql')), array('id' => $id));
                    break;
                case 'mark_spam':
                    $wpdb->update($table, array('status' => 'spam'), array('id' => $id));
                    break;
                case 'delete':
                    $wpdb->delete($table, array('id' => $id));
                    break;
            }

            // Redirect to remove action from URL
            wp_safe_redirect(remove_query_arg(array('action', 'id', '_wpnonce')));
            exit;
        }

        // Get submissions
        $form_id = isset($_GET['form_id']) ? absint($_GET['form_id']) : 0;
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

        $where = "WHERE 1=1";
        if ($form_id) {
            $where .= $wpdb->prepare(" AND s.form_id = %d", $form_id);
        }
        if ($status) {
            $where .= $wpdb->prepare(" AND s.status = %s", $status);
        }

        $submissions = $wpdb->get_results("
            SELECT s.*, f.title as form_title 
            FROM {$table} s 
            LEFT JOIN {$forms_table} f ON s.form_id = f.id 
            {$where}
            ORDER BY s.submitted_at DESC 
            LIMIT 100
        ");

        // Get forms for filter
        $forms = $wpdb->get_results("SELECT id, title FROM {$forms_table} ORDER BY title");

        // Get counts
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $unread = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'unread'");
        $spam = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'spam'");
?>
        <div class="wrap">
            <h1>Form Submissions</h1>

            <ul class="subsubsub">
                <li><a href="<?php echo admin_url('admin.php?page=ofast-forms-submissions'); ?>" <?php echo empty($status) ? 'class="current"' : ''; ?>>All (<?php echo $total; ?>)</a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=ofast-forms-submissions&status=unread'); ?>" <?php echo $status === 'unread' ? 'class="current"' : ''; ?>>Unread (<?php echo $unread; ?>)</a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=ofast-forms-submissions&status=spam'); ?>" <?php echo $status === 'spam' ? 'class="current"' : ''; ?>>Spam (<?php echo $spam; ?>)</a></li>
            </ul>

            <div class="tablenav top">
                <div class="alignleft actions">
                    <select id="filter-form" onchange="window.location='<?php echo admin_url('admin.php?page=ofast-forms-submissions&form_id='); ?>' + this.value">
                        <option value="">All Forms</option>
                        <?php foreach ($forms as $f): ?>
                            <option value="<?php echo $f->id; ?>" <?php selected($form_id, $f->id); ?>><?php echo esc_html($f->title); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if (empty($submissions)): ?>
                <div class="notice notice-info">
                    <p>No submissions found.</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:30%;">Details</th>
                            <th>Form</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $sub): ?>
                            <?php
                            $data = json_decode($sub->data, true);
                            $preview = '';
                            if (is_array($data)) {
                                $first_two = array_slice($data, 0, 2);
                                foreach ($first_two as $k => $v) {
                                    if (is_array($v)) $v = implode(', ', $v);
                                    $preview .= '<strong>' . esc_html($k) . ':</strong> ' . esc_html(substr($v, 0, 50)) . '<br>';
                                }
                            }
                            $bg = $sub->status === 'unread' ? '#fff8e5' : '';
                            ?>
                            <tr style="<?php echo $bg ? "background:{$bg};" : ''; ?>">
                                <td><?php echo $preview; ?></td>
                                <td><?php echo esc_html($sub->form_title ?: 'Unknown'); ?></td>
                                <td>
                                    <?php
                                    $status_colors = array('unread' => 'orange', 'read' => 'green', 'spam' => 'red', 'trash' => 'gray');
                                    echo '<span style="color:' . ($status_colors[$sub->status] ?? 'gray') . ';">' . ucfirst($sub->status) . '</span>';
                                    ?>
                                </td>
                                <td><?php echo date('M j, Y g:i a', strtotime($sub->submitted_at)); ?></td>
                                <td>
                                    <?php
                                    $nonce = wp_create_nonce('submission_action_' . $sub->id);
                                    $base_url = add_query_arg(array('id' => $sub->id, '_wpnonce' => $nonce));
                                    ?>
                                    <a href="#" class="view-submission" data-id="<?php echo $sub->id; ?>" data-data="<?php echo esc_attr(wp_json_encode($data)); ?>">View</a> |
                                    <?php if ($sub->status === 'unread'): ?>
                                        <a href="<?php echo esc_url(add_query_arg('action', 'mark_read', $base_url)); ?>">Mark Read</a> |
                                    <?php endif; ?>
                                    <a href="<?php echo esc_url(add_query_arg('action', 'mark_spam', $base_url)); ?>" style="color:orange;">Spam</a> |
                                    <a href="<?php echo esc_url(add_query_arg('action', 'delete', $base_url)); ?>" style="color:red;" onclick="return confirm('Delete this submission?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- View Modal -->
            <div id="submission-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:100000;">
                <div style="background:#fff;max-width:600px;margin:50px auto;padding:20px;border-radius:5px;max-height:80vh;overflow:auto;">
                    <h2 style="margin-top:0;">Submission Details</h2>
                    <div id="submission-content"></div>
                    <p><button type="button" class="button" onclick="document.getElementById('submission-modal').style.display='none';">Close</button></p>
                </div>
            </div>

            <script>
                jQuery(function($) {
                    $('.view-submission').on('click', function(e) {
                        e.preventDefault();
                        var data = $(this).data('data');
                        var html = '<table class="widefat">';
                        $.each(data, function(k, v) {
                            if (Array.isArray(v)) v = v.join(', ');
                            html += '<tr><th>' + k + '</th><td>' + v + '</td></tr>';
                        });
                        html += '</table>';
                        $('#submission-content').html(html);
                        $('#submission-modal').show();
                    });
                });
            </script>
        </div>
<?php
    }

    /**
     * Get submissions for a form
     */
    public function get_submissions($form_id, $limit = 50)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_form_submissions';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE form_id = %d ORDER BY submitted_at DESC LIMIT %d",
            $form_id,
            $limit
        ));
    }

    /**
     * Export submissions as CSV
     */
    public function export_csv($form_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_form_submissions';

        $submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE form_id = %d ORDER BY submitted_at DESC",
            $form_id
        ));

        if (empty($submissions)) {
            return false;
        }

        // Get form for field names
        $forms = Ofast_X_Forms::get_instance();
        $form = $forms->get_form($form_id);

        // Build CSV
        $csv = fopen('php://temp', 'w');

        // Headers
        $headers = array('ID', 'Submitted At', 'Status', 'IP Address');
        if ($form && !empty($form->fields)) {
            foreach ($form->fields as $field) {
                $headers[] = $field['label'] ?? 'Field';
            }
        }
        fputcsv($csv, $headers);

        // Rows
        foreach ($submissions as $sub) {
            $data = json_decode($sub->data, true) ?: array();
            $row = array($sub->id, $sub->submitted_at, $sub->status, $sub->ip_address);
            foreach ($data as $value) {
                if (is_array($value)) $value = implode(', ', $value);
                $row[] = $value;
            }
            fputcsv($csv, $row);
        }

        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        return $content;
    }
}
