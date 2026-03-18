<?php

/**
 * Ofast X - Form Submissions Handler
 * Handles form submissions, validation, and storage
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

        // Fix pagination/filter URLs to maintain tab
        $base_url = admin_url('admin.php?page=ofast-forms&tab=submissions');
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
                                        <?php
                                        $nonce = wp_create_nonce('submission_action_' . $sub->id);
                                        $action_url = add_query_arg(array('id' => $sub->id, '_wpnonce' => $nonce));
                                        ?>
                                        <div style="display: flex; gap: 8px; align-items: center;">
                                            <a href="#" class="view-submission" data-id="<?php echo $sub->id; ?>" data-data="<?php echo esc_attr(wp_json_encode($data)); ?>" style="color:#6366f1; font-weight:500;">View</a>
                                            
                                            <?php if ($sub->status === 'unread'): ?>
                                                <a href="<?php echo esc_url(add_query_arg('action', 'mark_read', $action_url)); ?>" style="color:#64748b; font-size: 13px;">Read</a>
                                            <?php endif; ?>
                                            
                                            <a href="<?php echo esc_url(add_query_arg('action', 'mark_spam', $action_url)); ?>" style="color:#d97706; font-size: 13px;">Spam</a>
                                            <a href="<?php echo esc_url(add_query_arg('action', 'delete', $action_url)); ?>" style="color:#ef4444; font-size: 13px;" onclick="return confirm('Delete this submission?');">Delete</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- View Modal -->
            <div id="submission-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:100000; padding: 20px; display: flex; align-items: center; justify-content: center;">
                <div style="background:#fff; width:100%; max-width:600px; border-radius:12px; max-height:85vh; display: flex; flex-direction: column; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);">
                    <div style="padding: 20px 25px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                        <h2 style="margin:0; font-size: 18px; color: #1e293b;">Submission Details</h2>
                        <button type="button" onclick="document.getElementById('submission-modal').style.display='none';" style="background:none; border:none; font-size: 24px; color: #64748b; cursor: pointer; padding: 0;">&times;</button>
                    </div>
                    
                    <div id="submission-content" style="padding: 25px; overflow-y: auto;">
                        <!-- Content injected via JS -->
                    </div>
                    
                    <div style="padding: 15px 25px; border-top: 1px solid #e2e8f0; text-align: right; background: #f8fafc; border-radius: 0 0 12px 12px;">
                        <button type="button" class="button" onclick="document.getElementById('submission-modal').style.display='none';">Close</button>
                    </div>
                </div>
            </div>

            <script>
                jQuery(function($) {
                    $('.view-submission').on('click', function(e) {
                        e.preventDefault();
                        var data = $(this).data('data');
                        var html = '<table class="widefat striped" style="border:none; box-shadow: none;">';
                        $.each(data, function(k, v) {
                            if (Array.isArray(v)) v = v.join(', ');
                            html += '<tr><th style="width: 30%; color: #1e293b;">' + k + '</th><td style="color: #475569;">' + v + '</td></tr>';
                        });
                        html += '</table>';
                        $('#submission-content').html(html);
                        $('#submission-modal').css('display', 'flex'); // Flex to center
                    });
                    
                    // Close on overlay click
                    $('#submission-modal').on('click', function(e) {
                        if(e.target === this) {
                            $(this).hide();
                        }
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
