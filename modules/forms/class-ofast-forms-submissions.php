<?php

/**
 * Ofast X - Form Submissions Handler
 * Handles form submissions, validation, and storage
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once OFAST_X_PLUGIN_DIR . 'includes/utilities/class-ofast-logger.php';

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
        $form = $forms->get_form($form_id, 'submission');

        if (!$form) {
            wp_send_json_error(array('message' => 'Form not found.'));
        }

        // Verify active spam protection (Turnstile, Math CAPTCHA, or reCAPTCHA)
        if (class_exists('Ofast_X_Spam_Protection')) {
            $spam = new Ofast_X_Spam_Protection();
            if ($spam->is_configured()) {
                $provider = $spam->get_active_provider();

                // Get the appropriate token based on active provider
                $token = '';
                if ($provider === 'turnstile') {
                    $token = sanitize_text_field($_POST['cf-turnstile-response'] ?? '');
                } elseif (in_array($provider, array('recaptcha_v2', 'recaptcha_v3'), true)) {
                    $token = sanitize_text_field($_POST['g-recaptcha-response'] ?? '');
                }
                // Math CAPTCHA reads its own POST field internally

                $result = $spam->verify($token);
                if (!$result['success'] && $this->can_use_turnstile_honeypot_fallback($provider, $token)) {
                    $result = array('success' => true, 'fallback' => true, 'method' => 'honeypot');
                    Ofast_X_Logger::info('Turnstile verification failed, falling back to honeypot', array(
                        'provider' => $provider,
                        'token_present' => !empty($token),
                        'ip' => $this->get_client_ip(),
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                        'honeypot_value' => $_POST['ofast_hp_field'] ?? ''
                    ));
                }

                if (!$result['success']) {
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

        // Send notification emails (non-blocking — failures don't affect user response)
        $this->send_notification_emails($form_id, $submission_data);

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
     * Send notification emails after form submission
     * Sends to: (1) Admin email, (2) Submitter confirmation if email field exists
     */
    private function send_notification_emails($form_id, $submission_data)
    {
        // Get full form with notification settings
        $forms = Ofast_X_Forms::get_instance();
        $form = $forms->get_form($form_id, 'admin');

        if (!$form) {
            return;
        }

        $form_title = sanitize_text_field($form->title ?? 'Contact Form');
        $site_name = get_bloginfo('name');
        $admin_email = get_option('admin_email');

        // Check notification settings — allow admin to configure recipient
        $notifications = $form->notifications ?? array();
        $notify_email = !empty($notifications['email']['to'])
            ? sanitize_email($notifications['email']['to'])
            : $admin_email;

        // Validate recipient email
        if (!is_email($notify_email)) {
            $notify_email = $admin_email;
        }

        // Build email body from submission data
        $body = $this->build_email_body($form_title, $submission_data, $site_name);

        // Wrap in email template if available
        if (class_exists('Ofast_X_Email_Template')) {
            $email_html = Ofast_X_Email_Template::get_template($body);
        } else {
            $email_html = $body;
        }

        // Email headers — use centralized secure headers
        $submitter_email = $this->find_submitter_email($submission_data);
        $headers = Ofast_X_Email::get_safe_email_headers($submitter_email ?: '');

        // 1. Send admin notification
        $subject = sprintf('[%s] New %s Submission', $site_name, $form_title);
        wp_mail($notify_email, $subject, $email_html, $headers);

        // 2. Send confirmation to submitter (if email field exists and auto-reply is not disabled)
        $auto_reply_disabled = !empty($notifications['email']['disable_auto_reply']);
        if ($submitter_email && !$auto_reply_disabled) {
            $confirm_body = $this->build_confirmation_body($form_title, $site_name);
            if (class_exists('Ofast_X_Email_Template')) {
                $confirm_html = Ofast_X_Email_Template::get_template($confirm_body);
            } else {
                $confirm_html = $confirm_body;
            }

            $confirm_subject = sprintf('Thank you for contacting %s', $site_name);
            $confirm_headers = Ofast_X_Email::get_safe_email_headers();
            wp_mail($submitter_email, $confirm_subject, $confirm_html, $confirm_headers);
        }
    }

    /**
     * Build HTML email body from form submission data
     */
    private function build_email_body($form_title, $data, $site_name)
    {
        $html = '<h2 style="color:#1e293b;margin:0 0 20px;">New ' . esc_html($form_title) . ' Submission</h2>';
        $html .= '<table style="width:100%;border-collapse:collapse;margin:10px 0;">';

        foreach ($data as $label => $value) {
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $html .= '<tr>';
            $html .= '<td style="padding:12px 16px;border:1px solid #e2e8f0;background:#f8fafc;font-weight:600;color:#374151;width:35%;">' . esc_html($label) . '</td>';
            $html .= '<td style="padding:12px 16px;border:1px solid #e2e8f0;color:#475569;">' . nl2br(esc_html($value)) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';
        $html .= '<p style="color:#9ca3af;font-size:13px;margin:20px 0 0;">Submitted on ' . esc_html(current_time('F j, Y \a\t g:i a')) . ' from ' . esc_html($site_name) . '</p>';

        return $html;
    }

    /**
     * Build confirmation email body for submitter
     */
    private function build_confirmation_body($form_title, $site_name)
    {
        $html = '<h2 style="color:#1e293b;margin:0 0 15px;">Thank You!</h2>';
        $html .= '<p style="color:#475569;font-size:15px;line-height:1.6;">We have received your <strong>' . esc_html($form_title) . '</strong> submission and will get back to you as soon as possible.</p>';
        $html .= '<p style="color:#9ca3af;font-size:13px;margin-top:25px;">— ' . esc_html($site_name) . '</p>';

        return $html;
    }

    /**
     * Find submitter's email from form data
     * Looks for fields labeled 'email' or containing email values
     */
    private function find_submitter_email($data)
    {
        // First pass: look for a field explicitly labeled 'email'
        foreach ($data as $label => $value) {
            if (is_string($value) && stripos($label, 'email') !== false && is_email($value)) {
                return sanitize_email($value);
            }
        }

        // Second pass: look for any value that looks like an email
        foreach ($data as $value) {
            if (is_string($value) && is_email($value)) {
                return sanitize_email($value);
            }
        }

        return false;
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
        $base_url = admin_url('admin.php?page=ofast-forms&tab=submissions');

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
                case 'mark_unread':
                    $wpdb->update($table, array('status' => 'unread', 'read_at' => null), array('id' => $id));
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
        $submission_id = isset($_GET['submission_id']) ? absint($_GET['submission_id']) : 0;
        $list_args = array();

        if ($form_id) {
            $list_args['form_id'] = $form_id;
        }

        if ($status) {
            $list_args['status'] = $status;
        }

        $list_url = !empty($list_args) ? add_query_arg($list_args, $base_url) : $base_url;

        if ($submission_id) {
            $submission = $this->get_submission_record($submission_id);
            $this->render_submission_detail_page($submission, $list_url, $list_args);
            return;
        }

        // Build parameterized query with proper WHERE conditions
        $where_conditions = array();
        $query_params = array();
        
        if ($form_id) {
            $where_conditions[] = "s.form_id = %d";
            $query_params[] = $form_id;
        }
        
        if ($status) {
            $where_conditions[] = "s.status = %s";
            $query_params[] = $status;
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        $query = "
            SELECT s.*, f.title as form_title 
            FROM {$table} s 
            LEFT JOIN {$forms_table} f ON s.form_id = f.id 
            {$where_clause}
            ORDER BY s.submitted_at DESC 
            LIMIT 100
        ";
        
        $submissions = !empty($query_params) ? 
            $wpdb->get_results($wpdb->prepare($query, $query_params)) :
            $wpdb->get_results($query);

        // Get forms for filter
        $forms = $wpdb->get_results("SELECT id, title FROM {$forms_table} ORDER BY title");

        // Get counts
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $unread = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'unread'");
        $spam = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'spam'");
        // In tabbed view, we only render if active?  Actually main page handles visibility.
        // But we might need to handle form submissions filters reloading the page.
        // They currently redirect to admin.php?page=ofast-forms-submissions... needs update.
        if (isset($_GET['tab']) && $_GET['tab'] !== 'submissions') {
            // Placeholder for potentially handling separate page loads vs tab switching
        }

?>
        <div class="ofast-submissions-list">
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <!-- Status Filters -->
                <div class="ofast-status-filters" style="display: flex; gap: 10px;">
                    <a href="<?php echo $base_url; ?>" class="ofast-filter-pill <?php echo empty($status) ? 'active' : ''; ?>">All (<?php echo $total; ?>)</a>
                    <a href="<?php echo add_query_arg('status', 'unread', $base_url); ?>" class="ofast-filter-pill <?php echo $status === 'unread' ? 'active' : ''; ?>">Unread (<?php echo $unread; ?>)</a>
                    <a href="<?php echo add_query_arg('status', 'spam', $base_url); ?>" class="ofast-filter-pill <?php echo $status === 'spam' ? 'active' : ''; ?>">Spam (<?php echo $spam; ?>)</a>
                </div>

                <!-- Form Filter -->
                <div class="ofast-form-filter">
                    <select id="filter-form" onchange="window.location='<?php echo esc_url($base_url); ?>&form_id=' + this.value" style="border-radius: 8px; border: 1px solid #e2e8f0; padding: 6px 12px;">
                        <option value="">All Forms</option>
                        <?php foreach ($forms as $f): ?>
                            <option value="<?php echo $f->id; ?>" <?php selected($form_id, $f->id); ?>><?php echo esc_html($f->title); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <style>
                .ofast-filter-pill {
                    padding: 6px 16px;
                    border-radius: 20px;
                    background: #f1f5f9;
                    color: #64748b;
                    text-decoration: none;
                    font-size: 13px;
                    font-weight: 500;
                    transition: all 0.2s;
                    border: 1px solid transparent;
                }
                .ofast-filter-pill:hover {
                    background: #e2e8f0;
                    color: #1e293b;
                }
                .ofast-filter-pill.active {
                    background: #eff6ff;
                    color: #6366f1;
                    border-color: #c7d2fe;
                }
                .ofast-card a { text-decoration: none; }
            </style>

            <?php if (empty($submissions)): ?>
                <?php echo Ofast_X_Toast::render('No submissions found.', 'info'); ?>
            <?php else: ?>
                <!-- Scrollable Table Container -->
                <div style="overflow-x: auto; max-width: 100%;">
                    <table class="wp-list-table widefat fixed striped" style="min-width: 800px;">
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
                                $view_url = add_query_arg(array_merge($list_args, array('submission_id' => $sub->id)), $base_url);
                                $mark_read_url = $this->build_submission_action_url('mark_read', $sub->id, $list_args);
                                $mark_spam_url = $this->build_submission_action_url('mark_spam', $sub->id, $list_args);
                                $delete_url = $this->build_submission_action_url('delete', $sub->id, $list_args);
                                if (is_array($data)) {
                                    $first_two = array_slice($data, 0, 2);
                                    foreach ($first_two as $k => $v) {
                                        if (is_array($v)) $v = implode(', ', $v);
                                        $preview .= '<strong style="color:#1e293b;">' . esc_html($k) . ':</strong> <span style="color:#64748b;">' . esc_html(substr($v, 0, 50)) . '</span><br>';
                                    }
                                }
                                $bg = $sub->status === 'unread' ? '#fffbeb' : '#fff'; // Softer yellow for unread
                                ?>
                                <tr style="background-color: <?php echo $bg; ?>; border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 15px 20px; vertical-align: middle;"><?php echo $preview; ?></td>
                                    <td style="padding: 15px 20px; vertical-align: middle; font-weight: 500; color: #1e293b;"><?php echo esc_html($sub->form_title ?: 'Unknown'); ?></td>
                                    <td style="padding: 15px 20px; vertical-align: middle;">
                                        <?php
                                        $status_styles = array(
                                            'unread' => 'background:#fef3c7; color:#b45309;',
                                            'read' => 'background:#dcfce7; color:#15803d;',
                                            'spam' => 'background:#fee2e2; color:#b91c1c;',
                                            'trash' => 'background:#f1f5f9; color:#64748b;'
                                        );
                                        $style = $status_styles[$sub->status] ?? $status_styles['trash'];
                                        echo '<span style="display:inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; ' . $style . '">' . ucfirst($sub->status) . '</span>';
                                        ?>
                                    </td>
                                    <td style="padding: 15px 20px; vertical-align: middle; color: #64748b;"><?php echo date('M j, Y g:i a', strtotime($sub->submitted_at)); ?></td>
                                    <td style="padding: 15px 20px; vertical-align: middle;">
                                        <div style="display: flex; gap: 8px; align-items: center;">
                                            <a href="<?php echo esc_url($view_url); ?>" style="color:#6366f1; font-weight:500;">View</a>
                                             
                                            <?php if ($sub->status === 'unread'): ?>
                                                <a href="<?php echo esc_url($mark_read_url); ?>" style="color:#64748b; font-size: 13px;">Read</a>
                                            <?php endif; ?>
                                             
                                            <a href="<?php echo esc_url($mark_spam_url); ?>" style="color:#d97706; font-size: 13px;">Spam</a>
                                            <a href="<?php echo esc_url($delete_url); ?>" style="color:#ef4444; font-size: 13px;" onclick="return confirm('Delete this submission?');">Delete</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
<?php
    }

    /**
     * Get a single submission with its form title.
     */
    private function get_submission_record($submission_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_form_submissions';
        $forms_table = $wpdb->prefix . 'ofast_forms';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, f.title as form_title
            FROM {$table} s
            LEFT JOIN {$forms_table} f ON s.form_id = f.id
            WHERE s.id = %d
            LIMIT 1",
            $submission_id
        ));
    }

    /**
     * Render a full submission details screen.
     */
    private function render_submission_detail_page($submission, $list_url, $list_args)
    {
        if (!$submission) {
            echo Ofast_X_Toast::render('Submission not found.', 'error');
            echo '<p style="margin-top:16px;"><a href="' . esc_url($list_url) . '" class="button">Back to Submissions</a></p>';
            return;
        }

        $data = json_decode($submission->data, true) ?: array();
        $status_styles = array(
            'unread' => 'background:#fef3c7; color:#b45309;',
            'read' => 'background:#dcfce7; color:#15803d;',
            'spam' => 'background:#fee2e2; color:#b91c1c;',
            'trash' => 'background:#f1f5f9; color:#64748b;'
        );
        $detail_args = array_merge($list_args, array('submission_id' => $submission->id));
        $status_style = $status_styles[$submission->status] ?? $status_styles['trash'];
        $mark_read_url = $this->build_submission_action_url('mark_read', $submission->id, $detail_args);
        $mark_unread_url = $this->build_submission_action_url('mark_unread', $submission->id, $detail_args);
        $mark_spam_url = $this->build_submission_action_url('mark_spam', $submission->id, $detail_args);
        $delete_url = $this->build_submission_action_url('delete', $submission->id, $list_args);
        $edit_form_url = admin_url('admin.php?page=ofast-forms&tab=add-new&id=' . absint($submission->form_id));
?>
        <div class="ofast-submission-detail">
            <style>
                .ofast-submission-detail a { text-decoration: none; }
                .ofast-submission-detail-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                    gap: 14px;
                    margin: 24px 0;
                }
                .ofast-submission-panel {
                    background: #f8fafc;
                    border: 1px solid #e2e8f0;
                    border-radius: 14px;
                    padding: 18px 20px;
                }
                .ofast-submission-panel h3 {
                    margin: 0 0 16px;
                    font-size: 16px;
                    color: #1e293b;
                }
                .ofast-submission-meta-label {
                    display: block;
                    margin-bottom: 6px;
                    font-size: 12px;
                    font-weight: 600;
                    letter-spacing: 0.04em;
                    text-transform: uppercase;
                    color: #64748b;
                }
                .ofast-submission-meta-value {
                    color: #1e293b;
                    font-size: 15px;
                    line-height: 1.5;
                    word-break: break-word;
                }
                .ofast-submission-data-table {
                    width: 100%;
                    border-collapse: collapse;
                }
                .ofast-submission-data-table th,
                .ofast-submission-data-table td {
                    padding: 14px 0;
                    border-bottom: 1px solid #e2e8f0;
                    vertical-align: top;
                }
                .ofast-submission-data-table th {
                    width: 220px;
                    padding-right: 24px;
                    color: #1e293b;
                    font-weight: 600;
                    text-align: left;
                }
                .ofast-submission-data-table td {
                    color: #475569;
                }
                .ofast-submission-actions {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 10px;
                }
            </style>

            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
                <div>
                    <a href="<?php echo esc_url($list_url); ?>" style="display:inline-flex; align-items:center; gap:6px; color:#6366f1; font-weight:600;">&larr; Back to submissions</a>
                    <h2 style="margin:10px 0 6px; color:#1e293b;">Submission #<?php echo absint($submission->id); ?></h2>
                    <p style="margin:0; color:#64748b;">
                        <?php echo esc_html($submission->form_title ?: 'Unknown Form'); ?> submitted on <?php echo esc_html(date('M j, Y g:i a', strtotime($submission->submitted_at))); ?>
                    </p>
                </div>

                <div class="ofast-submission-actions">
                    <a href="<?php echo esc_url($edit_form_url); ?>" class="button">Edit Form</a>
                    <?php if ($submission->status === 'unread'): ?>
                        <a href="<?php echo esc_url($mark_read_url); ?>" class="button">Mark Read</a>
                    <?php else: ?>
                        <a href="<?php echo esc_url($mark_unread_url); ?>" class="button">Mark Unread</a>
                    <?php endif; ?>
                    <?php if ($submission->status !== 'spam'): ?>
                        <a href="<?php echo esc_url($mark_spam_url); ?>" class="button" style="color:#b45309;">Mark Spam</a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url($delete_url); ?>" class="button" style="color:#b91c1c;" onclick="return confirm('Delete this submission?');">Delete</a>
                </div>
            </div>

            <div class="ofast-submission-detail-grid">
                <div class="ofast-submission-panel">
                    <span class="ofast-submission-meta-label">Form</span>
                    <div class="ofast-submission-meta-value"><?php echo esc_html($submission->form_title ?: 'Unknown Form'); ?></div>
                </div>
                <div class="ofast-submission-panel">
                    <span class="ofast-submission-meta-label">Status</span>
                    <div class="ofast-submission-meta-value">
                        <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; <?php echo esc_attr($status_style); ?>">
                            <?php echo esc_html(ucfirst($submission->status)); ?>
                        </span>
                    </div>
                </div>
                <div class="ofast-submission-panel">
                    <span class="ofast-submission-meta-label">Submitted</span>
                    <div class="ofast-submission-meta-value"><?php echo esc_html(date('M j, Y g:i a', strtotime($submission->submitted_at))); ?></div>
                </div>
                <div class="ofast-submission-panel">
                    <span class="ofast-submission-meta-label">Read At</span>
                    <div class="ofast-submission-meta-value"><?php echo !empty($submission->read_at) ? esc_html(date('M j, Y g:i a', strtotime($submission->read_at))) : 'Not yet read'; ?></div>
                </div>
            </div>

            <div class="ofast-submission-panel" style="margin-bottom:18px;">
                <h3>Submitted Data</h3>
                <?php if (empty($data)): ?>
                    <p style="margin:0; color:#64748b;">No submission fields were stored for this entry.</p>
                <?php else: ?>
                    <table class="ofast-submission-data-table">
                        <tbody>
                            <?php foreach ($data as $label => $value): ?>
                                <tr>
                                    <th><?php echo esc_html($label); ?></th>
                                    <td><?php echo wp_kses_post($this->format_submission_value($value)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="ofast-submission-panel">
                <h3>Request Details</h3>
                <table class="ofast-submission-data-table">
                    <tbody>
                        <tr>
                            <th>IP Address</th>
                            <td><?php echo esc_html($submission->ip_address ?: 'Unknown'); ?></td>
                        </tr>
                        <tr>
                            <th>Referrer</th>
                            <td>
                                <?php if (!empty($submission->referer) && filter_var($submission->referer, FILTER_VALIDATE_URL)): ?>
                                    <a href="<?php echo esc_url($submission->referer); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($submission->referer); ?></a>
                                <?php else: ?>
                                    <?php echo !empty($submission->referer) ? esc_html($submission->referer) : 'Not available'; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>User Agent</th>
                            <td><?php echo !empty($submission->user_agent) ? esc_html($submission->user_agent) : 'Not available'; ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
<?php
    }

    /**
     * Build a secure action URL for a submission record.
     */
    private function build_submission_action_url($action, $submission_id, $args = array())
    {
        return add_query_arg(array_merge(
            $args,
            array(
                'action' => $action,
                'id' => $submission_id,
                '_wpnonce' => wp_create_nonce('submission_action_' . $submission_id),
            )
        ), admin_url('admin.php?page=ofast-forms&tab=submissions'));
    }

    /**
     * Format submission values for detail output.
     */
    private function format_submission_value($value)
    {
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }

        $value = trim((string) $value);
        if ($value === '') {
            return '<span style="color:#94a3b8;">-</span>';
        }

        return nl2br(esc_html($value));
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
        $form = $forms->get_form($form_id, 'admin');

        // Build CSV
        $csv = fopen('php://temp', 'w');

        // Headers
        $headers = array('ID', 'Submitted At', 'Status', 'IP Address');
        if ($form && !empty($form->fields)) {
            foreach ($form->fields as $field) {
                $headers[] = $this->sanitize_csv_value($field['label'] ?? 'Field');
            }
        }
        fputcsv($csv, $headers);

        // Rows
        foreach ($submissions as $sub) {
            $data = json_decode($sub->data, true) ?: array();
            $row = array(
                $sub->id,
                $this->sanitize_csv_value($sub->submitted_at),
                $this->sanitize_csv_value($sub->status),
                $this->sanitize_csv_value($sub->ip_address)
            );
            foreach ($data as $value) {
                if (is_array($value)) $value = implode(', ', $value);
                $row[] = $this->sanitize_csv_value($value);
            }
            fputcsv($csv, $row);
        }

        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        return $content;
    }

    /**
     * Ofast forms already render a built-in honeypot field, so we can
     * safely fall back to it when Turnstile failed to return a token.
     */
    private function can_use_turnstile_honeypot_fallback($provider, $token)
    {
        return $provider === 'turnstile'
            && empty($token)
            && get_option('ofast_spam_honeypot_enabled', true)
            && array_key_exists('ofast_hp_field', $_POST)
            && empty($_POST['ofast_hp_field']);
    }

    /**
     * Prevent CSV formula injection by prefixing risky values.
     */
    private function sanitize_csv_value($value)
    {
        $value = (string) $value;
        $value = str_replace(array("\r", "\n"), ' ', $value);
        if ($value !== '' && preg_match('/^[=+\\-@\\t]/', $value)) {
            return "'" . $value;
        }
        return $value;
    }
}
