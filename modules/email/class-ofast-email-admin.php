<?php

/**
 * Ofast X Email Admin Interface
 * Integrated into proper OOP structure
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Email_Admin
{

    private $page_hook;

    /**
     * Initialize admin interface
     */
    public function init()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_ofast_preview_email', array($this, 'ajax_preview_email'));

        // Auto-repair table on admin init (runs once)
        add_action('admin_init', array($this, 'maybe_repair_table'));
    }

    /**
     * Auto-repair email logs table if columns are missing
     */
    public function maybe_repair_table()
    {
        // Only run once per day to avoid performance issues
        $last_check = get_option('ofast_email_table_checked', 0);
        if (time() - $last_check < DAY_IN_SECONDS) {
            return;
        }
        update_option('ofast_email_table_checked', time());

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_email_logs';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            // Create the table
            $charset = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $table (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                subject varchar(255) NOT NULL,
                body longtext,
                sent_at datetime DEFAULT CURRENT_TIMESTAMP,
                recipient_count int(11) DEFAULT 0,
                status varchar(50) DEFAULT 'sent',
                notes text,
                PRIMARY KEY (id)
            ) $charset;";
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            return;
        }

        // Check for missing columns and add them
        $columns = $wpdb->get_col("SHOW COLUMNS FROM $table");

        if (!in_array('body', $columns)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN body longtext AFTER subject");
        }

        if (!in_array('status', $columns)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN status varchar(50) DEFAULT 'sent' AFTER recipient_count");
        }
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_menu()
    {
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
            'Drafts',
            'Drafts',
            'manage_options',
            'ofast-email-drafts',
            array($this, 'render_drafts_page')
        );

        // Note: "Scheduled" page removed - replaced by Queue page (class-ofast-email-queue-admin.php)

        add_submenu_page(
            'ofast-emailer',
            'Email History',
            'History',
            'manage_options',
            'ofast-email-history',
            array($this, 'render_history_page')
        );

        add_submenu_page(
            'ofast-emailer',
            'Email Templates',
            'Templates',
            'manage_options',
            'ofast-email-templates',
            array($this, 'render_templates_page')
        );
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts($hook)
    {
        if (strpos($hook, 'ofast-emailer') === false && strpos($hook, 'ofast-email') === false) {
            return;
        }

        wp_enqueue_script('jquery');
    }

    /**
     * Render send email page (ALL 13 FIXES INTEGRATED)
     */
    public function render_send_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'ofast-x'));
        }

        global $wp_roles;
        $roles = [];
        foreach ($wp_roles->roles as $key => $role) {
            $roles[$key] = translate_user_role($role['name']);
        }

        $result_message = '';

        // Handle Save as Draft
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_draft'])) {
            if (!isset($_POST['ofast_email_nonce']) || !wp_verify_nonce($_POST['ofast_email_nonce'], 'ofast_send_email_action')) {
                wp_die(__('Security check failed. Please refresh and try again.', 'ofast-x'), 'Security Error', array('response' => 403));
            }

            global $wpdb;
            $table = $wpdb->prefix . 'ofast_email_drafts';
            
            $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
            $body = wp_kses_post(wp_unslash($_POST['message'] ?? ''));
            $selected_roles = isset($_POST['roles']) && is_array($_POST['roles']) ? array_map('sanitize_text_field', $_POST['roles']) : array();
            $selected_user_ids = isset($_POST['checked_users']) && is_array($_POST['checked_users']) ? array_map('intval', $_POST['checked_users']) : array();
            $draft_id = isset($_POST['draft_id']) ? intval($_POST['draft_id']) : 0;

            $data = array(
                'admin_id' => get_current_user_id(),
                'subject' => $subject,
                'body' => $body,
                'roles' => json_encode($selected_roles),
                'user_ids' => json_encode($selected_user_ids),
                'updated_at' => current_time('mysql')
            );

            if ($draft_id > 0) {
                // Update existing draft
                $wpdb->update($table, $data, array('id' => $draft_id, 'admin_id' => get_current_user_id()));
                $result_message = Ofast_X_Toast::render('Draft updated successfully!', 'success', true);
            } else {
                // Insert new draft
                $data['created_at'] = current_time('mysql');
                $wpdb->insert($table, $data);
                $result_message = Ofast_X_Toast::render('Email saved as draft!', 'success', true);
            }
        }

        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email'])) {

            // SECURITY: Verify CSRF nonce
            if (!isset($_POST['ofast_email_nonce']) || !wp_verify_nonce($_POST['ofast_email_nonce'], 'ofast_send_email_action')) {
                wp_die(__('Security check failed. Please refresh and try again.', 'ofast-x'), 'Security Error', array('response' => 403));
            }

            // SECURITY: Double-submit protection (prevent duplicate form submissions)
            $submit_token = isset($_POST['ofast_submit_token']) ? sanitize_text_field($_POST['ofast_submit_token']) : '';
            if (!empty($submit_token) && get_transient('ofast_submit_' . $submit_token)) {
                // Already processed this submission
                $result_message = Ofast_X_Toast::render('This form was already submitted. Please refresh to send again.', 'warning', true);
            } else {
                // Mark this submission as processed (expires in 60 seconds)
                if (!empty($submit_token)) {
                    set_transient('ofast_submit_' . $submit_token, true, 60);
                }

            // SECURITY: Rate limiting - max 10 bulk sends per hour per admin
            $rate_limit_key = 'ofast_email_rate_' . get_current_user_id();
            $send_count = get_transient($rate_limit_key) ?: 0;
            if ($send_count >= 10) {
                $result_message = Ofast_X_Toast::render('Rate limit exceeded. Maximum 10 bulk sends per hour.', 'error', true);
            } else {
                // Increment rate limiter
                set_transient($rate_limit_key, $send_count + 1, HOUR_IN_SECONDS);

                $subject = sanitize_text_field(wp_unslash($_POST['subject']));
                $body = wp_kses_post(wp_unslash($_POST['message']));

                // SECURITY: Sanitize roles array
                $selected_roles = array();
                if (!empty($_POST['roles']) && is_array($_POST['roles'])) {
                    foreach ($_POST['roles'] as $role) {
                        $clean_role = sanitize_key($role);
                        if (wp_roles()->is_role($clean_role)) {
                            $selected_roles[] = $clean_role;
                        }
                    }
                }

                $send_test = isset($_POST['test_email']);
                $schedule_time = sanitize_text_field($_POST['schedule_time'] ?? '');
                $timestamp = $schedule_time ? strtotime($schedule_time) : time();

                // FIX #7: Get checked user IDs from checkboxes with validation
                $checked_user_ids = array();
                if (!empty($_POST['checked_users']) && is_array($_POST['checked_users'])) {
                    foreach ($_POST['checked_users'] as $id) {
                        if (is_numeric($id) && $id > 0) {
                            $checked_user_ids[] = intval($id);
                        }
                    }
                }

                // Parse user ID ranges (with security limits)
                $input_ids = preg_split('/\s*,\s*/', sanitize_text_field($_POST['user_ids'] ?? ''));
                $range_user_ids = [];
                foreach ($input_ids as $entry) {
                    if (strpos($entry, '-') !== false) {
                        [$start, $end] = array_map('intval', explode('-', $entry));
                        // SECURITY: Limit range to 1000 to prevent memory exhaustion
                        if ($end - $start > 1000) {
                            $end = $start + 1000;
                        }
                        if ($start > 0 && $end > 0) {
                            $range_user_ids = array_merge($range_user_ids, range($start, $end));
                        }
                    } elseif (is_numeric($entry) && intval($entry) > 0) {
                        $range_user_ids[] = intval($entry);
                    }
                }

                // Merge all user IDs
                $selected_user_ids = array_unique(array_merge($range_user_ids, $checked_user_ids));

                if ($send_test) {
                    $user = wp_get_current_user();
                    $message = $this->replace_placeholders($body, $user);
                    $headers = $this->get_email_headers();
                    wp_mail($user->user_email, $subject, $this->get_email_template($message), $headers);
                    $result_message = Ofast_X_Toast::render('Test email sent to ' . esc_html($user->user_email), 'success', true);
                } else {
                    // Merge user IDs + roles
                    $total_ids = $selected_user_ids;
                    if (!empty($selected_roles)) {
                        $role_ids = get_users(['role__in' => $selected_roles, 'fields' => 'ID']);
                        $total_ids = array_unique(array_merge($total_ids, $role_ids));
                    }

                    // FALLBACK: If no recipients selected, send only to current admin
                    if (empty($total_ids)) {
                        $current_user = wp_get_current_user();
                        $total_ids = array($current_user->ID);
                        error_log('Ofast-X Email: No recipients selected, defaulting to admin: ' . $current_user->user_email);
                    }

                    // SECURITY: Max recipient limit to prevent server overload
                    $max_recipients = apply_filters('ofast_email_max_recipients', 5000);
                    if (count($total_ids) > $max_recipients) {
                        $total_ids = array_slice($total_ids, 0, $max_recipients);
                        $result_message = Ofast_X_Toast::render('Recipient list limited to ' . $max_recipients . ' users.', 'warning', true);
                    }

                    // FIX #4 & #5: Use configurable batch size for immediate sends
                    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 40;
                    $batch_size = max(10, min(50, $batch_size)); // Clamp between 10-50

                    if (count($total_ids) <= $batch_size) {
                        // Small batch: Send immediately
                        $sent = 0;
                        $headers = $this->get_email_headers();
                        $sample_body = '';
                        foreach (get_users(['include' => $total_ids]) as $user) {
                            $message = $this->replace_placeholders($body, $user);
                            $full_body = $this->get_email_template($message);
                            if (empty($sample_body)) {
                                $sample_body = $full_body;
                            }
                            if (wp_mail($user->user_email, $subject, $full_body, $headers)) {
                                $sent++;
                            }
                        }

                        $this->log_email($subject, $sent, 'Immediate send', $sample_body);
                        $result_message = Ofast_X_Toast::render('Sent immediately to ' . $sent . ' user(s)', 'success', true);
                    } else {
                        // Large batch: Add to queue system for background processing
                        require_once OFAST_X_PLUGIN_DIR . 'includes/core/class-ofast-email-queue.php';
                        $queue = Ofast_X_Email_Queue::get_instance();
                        
                        $batch_id = $queue->add_batch($subject, $body, $total_ids, time());
                        
                        if ($batch_id) {
                            $total_count = count($total_ids);
                            $emails_per_hour = get_option('ofast_email_emails_per_cron', 30);
                            $estimated_hours = ceil($total_count / $emails_per_hour);
                            
                            $result_message = Ofast_X_Toast::render(
                                "Batch queued! {$total_count} emails will be sent at {$emails_per_hour}/hour (~{$estimated_hours}h completion time). <a href='" . admin_url('admin.php?page=ofast-email-queue') . "'>View Queue</a>",
                                'success',
                                true
                            );
                        } else {
                            $result_message = Ofast_X_Toast::render('Failed to queue emails. Please try again.', 'error', true);
                        }
                    }
                }
            } // End rate limit else block
            } // End double-submit else block
        }

        // Render UI
        $this->render_send_form($result_message, $roles);
    }

    /**
     * Render send form
     */
    private function render_send_form($result_message, $roles)
    {
        // Load draft if editing
        $draft = null;
        $draft_id = isset($_GET['draft_id']) ? intval($_GET['draft_id']) : 0;
        if ($draft_id > 0) {
            global $wpdb;
            $table = $wpdb->prefix . 'ofast_email_drafts';
            $draft = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE id = %d AND admin_id = %d",
                $draft_id,
                get_current_user_id()
            ));
        }
        
        $draft_subject = $draft ? $draft->subject : '';
        $draft_body = $draft ? $draft->body : '';
        $draft_roles = $draft ? (json_decode($draft->roles, true) ?: array()) : array();
        $draft_user_ids = $draft ? (json_decode($draft->user_ids, true) ?: array()) : array();

        // Toast notification - $result_message already contains complete toast from Ofast_X_Toast::render()
        // Just output it directly since it includes styles, script, and JS call
        $toast_html = !empty($result_message) ? $result_message : '';

        echo '<div class="wrap"><h2>Send Email</h2>' . $toast_html . '
        <form method="post" enctype="multipart/form-data" id="email-form">';
        wp_nonce_field('ofast_send_email_action', 'ofast_email_nonce');
        // Hidden draft_id for updating
        if ($draft_id > 0) {
            echo '<input type="hidden" name="draft_id" value="' . esc_attr($draft_id) . '">';
            echo '<div class="notice notice-info"><p>📝 Editing draft: <strong>' . esc_html($draft_subject ?: '(No subject)') . '</strong> — <a href="' . admin_url('admin.php?page=ofast-emailer') . '">Start fresh instead</a></p></div>';
        }
        // Double-submit protection token
        echo '<input type="hidden" name="ofast_submit_token" value="' . esc_attr(wp_generate_password(16, false)) . '">';
        echo '<p><label><strong>Email Subject:</strong><br>
            <input type="text" name="subject" style="width: 100%;" required value="' . esc_attr($draft_subject) . '"></label></p>

            <p><label><strong>Message Body:</strong><br>';
        wp_editor($draft_body, 'message', [
            'textarea_name' => 'message',
            'media_buttons' => true,
            'textarea_rows' => 10,
        ]);

        // FIX #8: Add placeholder tags display
        echo '</label></p>
        <p style="background:#f0f0f1;padding:10px;border-left:4px solid #2271b1;">
            <strong>Available Placeholders:</strong><br>
            <code>{{user_id}}</code>, <code>{{username}}</code>, <code>{{user_display_name}}</code>, 
            <code>{{user_first_name}}</code>, <code>{{user_last_name}}</code>, <code>{{user_email}}</code>
        </p>

            <p><strong>Select Roles:</strong><br>';
        foreach ($roles as $key => $label) {
            echo '<label><input type="checkbox" name="roles[]" value="' . esc_attr($key) . '"> ' . esc_html($label) . '</label><br>';
        }
        echo '</p>

            <p><label><strong>User ID(s) or Ranges (e.g. 5,12,30-35):</strong><br>
            <input type="text" name="user_ids" style="width: 100%;"></label></p>

            <p>
                <label><strong>Schedule Time (optional):</strong><br>
                <input type="datetime-local" name="schedule_time" style="width: 250px;">
                <small>Leave blank to send immediately. Large batches will auto-schedule.</small></label>
            </p>
            
            <p>
                <label><strong>Emails Per Hour:</strong>
                <select name="batch_size" style="margin-left: 10px;">
                    <option value="20">20 per hour (safest)</option>
                    <option value="30">30 per hour</option>
                    <option value="40" selected>40 per hour (recommended)</option>
                    <option value="50">50 per hour (max)</option>
                </select>
                </label>
                <br><small>Higher values may trigger spam limits on shared hosting or Gmail SMTP.</small>
            </p>


            <p><label><input type="checkbox" name="test_email"> Send to me as test only</label></p>

            <p>
                <button type="submit" name="send_email" class="button button-primary"> Send / Schedule</button>
                <button type="submit" name="save_draft" class="button button-secondary" style="margin-left:10px;"> Save as Draft</button>
                <button type="button" id="preview-email-btn" class="button button-secondary" style="margin-left:10px;">Preview Email</button>
            </p>
            
        <hr><h3> Select Users Manually (Optional)</h3>

        <label>Search: <input type="text" id="user-search" style="margin-left:5px;"></label>
        <label style="margin-left:20px;">Show 
            <select id="rows-per-page">
                <option value="10">10</option>
                <option value="20">20</option>
                <option value="50">50</option>
                <option value="all">All</option>
            </select> users per page
        </label>

        <div style="overflow-x:auto; margin-top:15px; margin-bottom:10px;">
            <table class="wp-list-table widefat striped" id="user-table">
                <thead><tr>
                    <th><input type="checkbox" id="check-all"></th>
                    <th>S/N</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>User ID</th>
                    <th>Role(s)</th>
                </tr></thead>
                <tbody>';

        $users = get_users();
        $i = 1;
        foreach ($users as $user) {
            $userdata = get_userdata($user->ID);
            $roles_list = ($userdata && isset($userdata->roles)) ? implode(', ', $userdata->roles) : '—';
            echo '<tr class="user-row">
                        <td><input type="checkbox" class="user-checkbox" name="checked_users[]" value="' . esc_attr($user->ID) . '"></td>
                        <td>' . $i++ . '</td>
                        <td class="search-text">' . esc_html($user->first_name) . '</td>
                        <td class="search-text">' . esc_html($user->last_name) . '</td>
                        <td class="search-text">' . esc_html($user->user_login) . '</td>
                        <td class="search-text">' . esc_html($user->user_email) . '</td>
                        <td class="search-text">' . esc_html($user->ID) . '</td>
                        <td class="search-text">' . esc_html($roles_list) . '</td>
                    </tr>';
        }
        echo '</tbody></table>';
        echo '<div id="user-pagination" style="margin-top:10px;"></div>';

        // FIX #6: Fixed search functionality
        echo '<script>
                jQuery(document).ready(function($) {
                    var allRows = $("#user-table tbody tr");
                    var visibleRows = allRows;
                    var itemsPerPage = 10;
                    var currentPage = 1;

                    function updateVisibleRows() {
                        var searchTerm = $("#user-search").val().toLowerCase();
                        visibleRows = allRows.filter(function() {
                            if (searchTerm === "") return true;
                            return $(this).text().toLowerCase().includes(searchTerm);
                        });
                        currentPage = 1;
                        updatePagination();
                    }

                    function showPage(page) {
                        allRows.hide();
                        var start = (page - 1) * itemsPerPage;
                        var end = start + itemsPerPage;
                        visibleRows.slice(start, end).show();
                    }

                    function updatePagination() {
                        var numPages = Math.ceil(visibleRows.length / itemsPerPage);
                        var pagination = "";
                        for (var i = 1; i <= numPages; i++) {
                            var disabled = i === currentPage ? " disabled" : "";
                            pagination += "<button type=\'button\' class=\'button page-btn\' data-page=\'" + i + "\'" + disabled + ">" + i + "</button> ";
                        }
                        $("#user-pagination").html(pagination);
                        
                        $(".page-btn").click(function() {
                            currentPage = parseInt($(this).data("page"));
                            showPage(currentPage);
                            $(".page-btn").removeAttr("disabled");
                            $(this).attr("disabled", true);
                        });
                        
                        showPage(currentPage);
                    }

                    $("#user-search").on("input", function() {
                        updateVisibleRows();
                    });

                    $("#rows-per-page").change(function() {
                        itemsPerPage = $(this).val() === "all" ? visibleRows.length : parseInt($(this).val());
                        updatePagination();
                    });

                    $("#check-all").change(function() {
                        visibleRows.find(".user-checkbox").prop("checked", $(this).prop("checked"));
                    });
        
                    updatePagination();
                });
                </script>';

        // Preview Modal HTML with Device Toggle
        echo '<div id="email-preview-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:999999;overflow-y:auto;">
            <div style="position:relative;width:90%;max-width:900px;margin:20px auto;background:#1e293b;border-radius:12px;overflow:hidden;">
                <div style="padding:12px 20px;background:#0f172a;border-bottom:1px solid #334155;display:flex;justify-content:space-between;align-items:center;">
                    <div style="display:flex;gap:10px;align-items:center;">
                        <h3 style="margin:0;color:#fff;font-size:14px;">Email Preview</h3>
                        <div style="display:flex;gap:5px;margin-left:15px;">
                            <button type="button" class="device-btn active" data-width="600" style="padding:6px 12px;border:1px solid #475569;background:#334155;color:#fff;border-radius:4px;cursor:pointer;font-size:12px;">Desktop</button>
                            <button type="button" class="device-btn" data-width="375" style="padding:6px 12px;border:1px solid #475569;background:transparent;color:#94a3b8;border-radius:4px;cursor:pointer;font-size:12px;">Mobile</button>
                        </div>
                    </div>
                    <button id="close-preview-modal" style="background:none;border:none;font-size:20px;cursor:pointer;color:#94a3b8;">&times;</button>
                </div>
                <div style="padding:20px;background:#1e293b;display:flex;justify-content:center;">
                    <iframe id="preview-iframe" style="width:600px;height:calc(100vh - 150px);border:none;border-radius:8px;background:#fff;transition:width 0.3s ease;"></iframe>
                </div>
            </div>
        </div>';

        // Preview Modal JavaScript
        echo '<script>
        jQuery(document).ready(function($) {
            // Device toggle buttons
            $(".device-btn").click(function() {
                var width = $(this).data("width");
                $("#preview-iframe").css("width", width + "px");
                $(".device-btn").removeClass("active").css({"background":"transparent","color":"#94a3b8"});
                $(this).addClass("active").css({"background":"#334155","color":"#fff"});
            });
            
            // Preview Email Button
            $("#preview-email-btn").click(function(e) {
                e.preventDefault();
                
                var subject = $("input[name=\'subject\']").val();
                var message = "";
                
                // Get content from TinyMCE
                if (typeof tinyMCE !== "undefined" && tinyMCE.get("message")) {
                    message = tinyMCE.get("message").getContent();
                } else {
                    message = $("#message").val();
                }
                
                if (!message) {
                    alert("Please enter email content first!");
                    return;
                }
                
                // Show modal with loading
                $("#email-preview-modal").fadeIn();
                var iframe = document.getElementById("preview-iframe");
                iframe.srcdoc = "<div style=\'display:flex;align-items:center;justify-content:center;height:100%;font-family:sans-serif;color:#64748b;\'>Loading preview...</div>";
                
                // AJAX to get preview
                $.post(ajaxurl, {
                    action: "ofast_preview_email",
                    nonce: "' . wp_create_nonce('ofast_preview_email') . '",
                    subject: subject,
                    message: message
                }, function(response) {
                    if (response.success) {
                        iframe.srcdoc = response.data.html;
                    } else {
                        iframe.srcdoc = "<div style=\'color:red;padding:20px;\'>Error loading preview</div>";
                    }
                });
            });
            
            // Close Modal - prevent event bubbling that could reset form
            $("#close-preview-modal").click(function(e) {
                e.preventDefault();
                e.stopPropagation();
                $("#email-preview-modal").fadeOut();
                return false;
            });
            
            // Close modal when clicking background
            $("#email-preview-modal").click(function(e) {
                if (e.target === this) {
                    e.preventDefault();
                    $(this).fadeOut();
                }
            });
            
            $(document).keyup(function(e) {
                if (e.key === "Escape") {
                    $("#email-preview-modal").fadeOut();
                }
            });
        });
        </script>';

        echo '</div></form></div>';
    }

    /**
     * Render email history page
     */
    public function render_history_page()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_email_logs';
        $logs = $wpdb->get_results("SELECT * FROM $table ORDER BY sent_at DESC LIMIT 100");

?>
        <div class="wrap">
            <h2>Email History</h2>
            <p>View sent emails and preview their content.</p>

            <?php if (empty($logs)): ?>
                <p>No emails have been logged yet.</p>
            <?php else: ?>
                <!-- Scrollable Table Container -->
                <div style="overflow-x: auto; max-width: 100%;">
                    <table class="widefat fixed striped" style="min-width: 800px;">
                        <thead>
                            <tr>
                                <th style="width:5%;">ID</th>
                                <th>Subject</th>
                                <th style="width:15%;">Sent At</th>
                                <th style="width:8%;">Recipients</th>
                                <th style="width:10%;">Status</th>
                                <th style="width:15%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo esc_html($log->id); ?></td>
                                    <td><?php echo esc_html(wp_trim_words($log->subject, 12, '...')); ?></td>
                                    <td><?php echo esc_html($log->sent_at); ?></td>
                                    <td><?php echo esc_html($log->recipient_count); ?></td>
                                    <td>
                                        <?php
                                        $status = $log->status ?? 'sent';
                                        $status_class = $status === 'failed' ? 'color: #dc2626;' : ($status === 'scheduled' ? 'color: #f59e0b;' : 'color: #10b981;');
                                        ?>
                                        <span style="<?php echo $status_class; ?> font-weight: 500;">
                                            <?php echo esc_html(ucfirst($status)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($log->body)): ?>
                                            <button type="button" class="button button-small preview-email-btn"
                                                data-content="<?php echo esc_attr(base64_encode($log->body)); ?>">
                                                Preview
                                            </button>
                                        <?php else: ?>
                                            <span style="color: #9ca3af;">No preview</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Email Preview Modal -->
        <div id="emailer-preview-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 100000;">
            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; border-radius: 8px; width: 90%; max-width: 700px; max-height: 80vh; overflow: hidden;">
                <div style="padding: 15px 20px; background: #f8fafc; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0;">Email Preview</h3>
                    <button type="button" id="close-emailer-preview" style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
                </div>
                <iframe id="emailer-preview-frame" style="width: 100%; height: 60vh; border: none;"></iframe>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Preview email
                $('.preview-email-btn').on('click', function() {
                    var content = atob($(this).data('content'));
                    var iframe = document.getElementById('emailer-preview-frame');
                    iframe.srcdoc = content;
                    $('#emailer-preview-modal').fadeIn(200);
                });

                // Close modal
                $('#close-emailer-preview, #emailer-preview-modal').on('click', function(e) {
                    if (e.target === this || $(this).attr('id') === 'close-emailer-preview') {
                        $('#emailer-preview-modal').fadeOut(200);
                    }
                });
            });
        </script>
    <?php
    }

    /**
     * @deprecated Settings moved to Templates page
     */

    /**
     * Helper: Get email headers (FIX #2)
     */
    private function get_email_headers()
    {
        return [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('ofast_email_from_name', 'Ofastshop Digitals') . ' <' . get_option('ofast_email_reply_to', 'support@ofastshop.com') . '>',
            'Reply-To: ' . get_option('ofast_email_reply_to', 'support@ofastshop.com')
        ];
    }

    /**
     * Helper: Replace placeholders
     */
    private function replace_placeholders($body, $user)
    {
        return str_replace(
            ['{{user_id}}', '{{username}}', '{{user_display_name}}', '{{user_first_name}}', '{{user_last_name}}', '{{user_email}}'],
            [$user->ID, $user->user_login, $user->display_name, $user->first_name, $user->last_name, $user->user_email],
            $body
        );
    }


    /**
     * Helper: Log email
     */
    private function log_email($subject, $recipient_count, $notes, $body = '', $status = 'sent')
    {
        global $wpdb;
        $result = $wpdb->insert($wpdb->prefix . 'ofast_email_logs', [
            'subject' => $subject,
            'body' => $body,
            'sent_at' => current_time('mysql'),
            'recipient_count' => $recipient_count,
            'status' => $status,
            'notes' => $notes
        ]);

        // Debug: Log if insert failed
        if ($result === false) {
            error_log('Ofast Emailer: Failed to log email - ' . $wpdb->last_error);
        }
    }

    /**
     * AJAX: Preview email with modern template
     */
    public function ajax_preview_email()
    {
        check_ajax_referer('ofast_preview_email', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? 'Email Preview'));
        $message = wp_kses_post(wp_unslash($_POST['message'] ?? ''));

        // Load template class
        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-template.php';

        // Get preview HTML using modern template
        $html = Ofast_X_Email_Template::get_template($message);

        wp_send_json_success(array('html' => $html));
    }

    /**
     * Helper: Get email template using modern design
     */
    private function get_email_template($content)
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-template.php';
        return Ofast_X_Email_Template::get_template($content);
    }

    /**
     * Render scheduled emails page (WordPress Cron queue view)
     */
    public function render_scheduled_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions');
        }

        // Handle cancel action
        if (isset($_POST['cancel_scheduled']) && wp_verify_nonce($_POST['cancel_nonce'], 'ofast_cancel_scheduled')) {
            $timestamp = intval($_POST['cancel_timestamp']);
            $args = json_decode(stripslashes($_POST['cancel_args']), true);
            wp_unschedule_event($timestamp, 'ofast_send_email_batch', array($args));
            echo Ofast_X_Toast::render('Scheduled batch cancelled.', 'success');
        }

        echo '<div class="wrap">';
        echo '<h1>Scheduled Email Batches</h1>';
        echo '<p>Email batches scheduled via WordPress Cron (lightweight, no dependencies)</p>';

        // Get all cron events
        $events = _get_cron_array();
        $scheduled_batches = array();

        foreach ($events as $timestamp => $hooks) {
            foreach ($hooks as $hook => $jobs) {
                if ($hook === 'ofast_send_email_batch') {
                    foreach ($jobs as $key => $details) {
                        $args = isset($details['args'][0]) ? $details['args'][0] : array();
                        $scheduled_batches[] = array(
                            'timestamp' => $timestamp,
                            'subject' => $args['subject'] ?? '[no subject]',
                            'user_count' => count($args['user_ids'] ?? array()),
                            'args' => $args,
                            'key' => $key
                        );
                    }
                }
            }
        }

        if (empty($scheduled_batches)) {
            // Empty state handled by styled card below
        } else {
            echo '<!-- Scrollable Table Container -->';
            echo '<div style="overflow-x: auto; max-width: 100%;">';
            echo '<table class="wp-list-table widefat fixed striped" style="min-width: 800px;">';
            echo '<thead><tr>';
            echo '<th>Scheduled Time</th><th>Subject</th><th>Recipients</th><th>Status</th><th>Action</th>';
            echo '</tr></thead><tbody>';

            foreach ($scheduled_batches as $batch) {
                $time_diff = $batch['timestamp'] - time();
                $time_display = date('Y-m-d H:i:s', $batch['timestamp']);

                if ($time_diff > 0) {
                    $status = '<span style="color:#0073aa;">Pending (' . human_time_diff(time(), $batch['timestamp']) . ')</span>';
                } else {
                    $status = '<span style="color:#f0ad4e;">Waiting for cron...</span>';
                }

                echo '<tr>';
                echo '<td>' . esc_html($time_display) . '</td>';
                echo '<td>' . esc_html(wp_trim_words($batch['subject'], 8, '...')) . '</td>';
                echo '<td>' . esc_html($batch['user_count']) . ' users</td>';
                echo '<td>' . $status . '</td>';
                echo '<td>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="cancel_timestamp" value="' . esc_attr($batch['timestamp']) . '">
                        <input type="hidden" name="cancel_args" value="' . esc_attr(json_encode($batch['args'])) . '">
                        ' . wp_nonce_field('ofast_cancel_scheduled', 'cancel_nonce', true, false) . '
                        <button type="submit" name="cancel_scheduled" class="button button-small" onclick="return confirm(\'Cancel this batch?\')">Cancel</button>
                    </form>
                </td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</div>';
        }

        // Info about WP Cron reliability - only show when no batches
        if (empty($scheduled_batches)) {
            echo '
            <div style="
                margin-top: 30px;
                padding: 40px;
                background: linear-gradient(135deg, #0073aa 0%, #005177 100%);
                border-radius: 16px;
                text-align: center;
                color: white;
                box-shadow: 0 10px 40px rgba(0, 115, 170, 0.3);
            ">
                <div style="
                    width: 80px;
                    height: 80px;
                    background: rgba(255,255,255,0.2);
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    margin: 0 auto 20px;
                    font-size: 36px;
                ">📅</div>
                
                <h2 style="margin: 0 0 10px; font-size: 24px; font-weight: 600;">No Scheduled Batches</h2>
                <p style="margin: 0 0 25px; opacity: 0.9; font-size: 15px;">
                    When you send emails to more than your batch limit, they\'ll appear here.
                </p>
                
                <div style="
                    background: rgba(255,255,255,0.15);
                    border-radius: 12px;
                    padding: 20px;
                    text-align: left;
                    max-width: 500px;
                    margin: 0 auto;
                ">
                    <h4 style="margin: 0 0 12px; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8;">How It Works</h4>
                    <p style="margin: 0 0 10px; font-size: 14px; line-height: 1.6;">
                        <strong>WordPress Cron</strong> runs when someone visits your site. On busy sites, this is very reliable.
                    </p>
                    <p style="margin: 0; font-size: 14px; line-height: 1.6;">
                        <strong>Low-traffic sites?</strong> Set up a real server cron job to hit <code style="background: rgba(0,0,0,0.2); padding: 2px 6px; border-radius: 4px;">wp-cron.php</code> every 5 minutes.
                    </p>
                </div>
            </div>';
        }

        echo '</div>';
    }

    /**
     * Render Templates page - visual email template designer
     */
    public function render_templates_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions');
        }

        // Handle reset
        if (isset($_POST['ofast_reset_template']) && wp_verify_nonce($_POST['_wpnonce'], 'ofast_template_save')) {
            $this->reset_template_settings();
            echo Ofast_X_Toast::render('Template settings reset to defaults!', 'success');
        }

        // Handle send test email
        if (isset($_POST['ofast_send_test_template']) && wp_verify_nonce($_POST['_wpnonce'], 'ofast_template_save')) {
            $admin_email = get_option('admin_email');
            $test_content = '<p>This is a <strong>test email</strong> from your Ofast X Email Template.</p>
                <p>If you can see this email with your logo, colors, and branding - your email template is working correctly!</p>
                <p>You can now send beautiful emails to your users.</p>';
            
            require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-template.php';
            $html = Ofast_X_Email_Template::get_template($test_content);
            
            $headers = array(
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . get_option('ofast_email_from_name', get_bloginfo('name')) . ' <' . get_option('ofast_email_reply_to', $admin_email) . '>'
            );
            
            $sent = wp_mail($admin_email, 'Test Email - Ofast X Template', $html, $headers);
            
            if ($sent) {
                echo Ofast_X_Toast::render('Test email sent to ' . esc_html($admin_email), 'success');
            } else {
                echo Ofast_X_Toast::render('Failed to send test email. Please check your email configuration.', 'error');
            }
        }

        // Handle save
        if (isset($_POST['ofast_save_template']) && wp_verify_nonce($_POST['_wpnonce'], 'ofast_template_save')) {
            $this->save_template_settings();
            echo Ofast_X_Toast::render('Template settings saved!', 'success');
        }

        // Get current settings
        $style = get_option('ofast_email_template_style', 'modern');
        $primary = get_option('ofast_email_primary_color', '#6366f1');
        $accent = get_option('ofast_email_accent_color', '#10b981');
        $bg = get_option('ofast_email_bg_color', '#f8fafc');
        $text = get_option('ofast_email_text_color', '#1e293b');
        $logo = get_option('ofast_email_logo', '');
        $company = get_option('ofast_email_company_name', get_bloginfo('name'));
        $tagline = get_option('ofast_email_tagline', '');
        $show_header = get_option('ofast_email_show_header', true);
        $show_footer = get_option('ofast_email_show_footer', true);
        $from_name = get_option('ofast_email_from_name', get_bloginfo('name'));
        $reply_to = get_option('ofast_email_reply_to', get_option('admin_email'));
        $social = get_option('ofast_email_social', array());
        $apply_to = get_option('ofast_email_apply_to', array('emailer'));
        $font_family = get_option('ofast_email_font_family', 'system');
        $font_size = get_option('ofast_email_font_size', '15');
        $logo_width = get_option('ofast_email_logo_width', '120');
        $logo_height = get_option('ofast_email_logo_height', '0');

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_media();

    ?>
        <div class="wrap">
            <h1>Email Templates</h1>
            <p>Design your email template with live preview. Changes apply to selected email types.</p>

            <style>
                .ofast-template-layout {
                    display: flex;
                    gap: 30px;
                    margin-top: 20px;
                }

                .ofast-template-settings {
                    flex: 0 0 380px;
                }

                .ofast-template-preview {
                    flex: 1;
                    min-width: 0;
                    position: sticky;
                    top: 32px;
                    align-self: flex-start;
                }

                @media screen and (max-width: 1200px) {
                    .ofast-template-layout {
                        flex-direction: column;
                    }

                    .ofast-template-settings {
                        flex: 1;
                        width: 100%;
                    }

                    .ofast-template-preview {
                        position: static;
                        width: 100%;
                        margin-top: 20px;
                    }

                    .ofast-template-preview iframe {
                        width: 100% !important;
                        max-width: 100% !important;
                        height: 400px;
                    }

                    .ofast-template-preview .postbox>div:last-child {
                        overflow-x: auto;
                    }
                }
            </style>

            <div class="ofast-template-layout">
                <!-- Left Column: Settings -->
                <div class="ofast-template-settings">
                    <form method="post">
                        <?php wp_nonce_field('ofast_template_save'); ?>

                        <!-- Template Style -->
                        <div class="postbox" style="padding: 15px; margin-bottom: 15px;">
                            <h3 style="margin: 0 0 15px 0; font-size: 14px;">Template Style</h3>
                            <div style="display: flex; gap: 10px;">
                                <label style="flex: 1; text-align: center; padding: 15px 10px; border: 2px solid <?php echo $style === 'modern' ? '#2271b1' : '#ddd'; ?>; border-radius: 8px; cursor: pointer; background: <?php echo $style === 'modern' ? '#f0f6fc' : '#fff'; ?>;">
                                    <input type="radio" name="template_style" value="modern" <?php checked($style, 'modern'); ?> style="display: none;">
                                    <div style="font-weight: 600;">Modern</div>
                                    <small style="color: #666;">Gradient header</small>
                                </label>
                                <label style="flex: 1; text-align: center; padding: 15px 10px; border: 2px solid <?php echo $style === 'classic' ? '#2271b1' : '#ddd'; ?>; border-radius: 8px; cursor: pointer; background: <?php echo $style === 'classic' ? '#f0f6fc' : '#fff'; ?>;">
                                    <input type="radio" name="template_style" value="classic" <?php checked($style, 'classic'); ?> style="display: none;">
                                    <div style="font-weight: 600;">Classic</div>
                                    <small style="color: #666;">Solid header</small>
                                </label>
                                <label style="flex: 1; text-align: center; padding: 15px 10px; border: 2px solid <?php echo $style === 'minimal' ? '#2271b1' : '#ddd'; ?>; border-radius: 8px; cursor: pointer; background: <?php echo $style === 'minimal' ? '#f0f6fc' : '#fff'; ?>;">
                                    <input type="radio" name="template_style" value="minimal" <?php checked($style, 'minimal'); ?> style="display: none;">
                                    <div style="font-weight: 600;">Minimal</div>
                                    <small style="color: #666;">Clean, no header</small>
                                </label>
                            </div>
                        </div>

                        <!-- Colors -->
                        <div class="postbox" style="padding: 15px; margin-bottom: 15px;">
                            <h3 style="margin: 0 0 15px 0; font-size: 14px;">Colors</h3>
                            <table class="form-table" style="margin: 0;">
                                <tr>
                                    <th style="width: 100px;">Primary</th>
                                    <td><input type="text" name="primary_color" value="<?php echo esc_attr($primary); ?>" class="ofast-color-picker"></td>
                                </tr>
                                <tr>
                                    <th>Accent</th>
                                    <td><input type="text" name="accent_color" value="<?php echo esc_attr($accent); ?>" class="ofast-color-picker"></td>
                                </tr>
                                <tr>
                                    <th>Background</th>
                                    <td><input type="text" name="bg_color" value="<?php echo esc_attr($bg); ?>" class="ofast-color-picker"></td>
                                </tr>
                                <tr>
                                    <th>Text</th>
                                    <td><input type="text" name="text_color" value="<?php echo esc_attr($text); ?>" class="ofast-color-picker"></td>
                                </tr>
                            </table>
                        </div>

                        <!-- Typography -->
                        <div class="postbox" style="padding: 15px; margin-bottom: 15px;">
                            <h3 style="margin: 0 0 15px 0; font-size: 14px;">Typography</h3>
                            <table class="form-table" style="margin: 0;">
                                <tr>
                                    <th style="width: 100px;">Font</th>
                                    <td>
                                        <select name="font_family" id="font_family" style="width: 100%;">
                                            <option value="system" <?php selected($font_family, 'system'); ?>>System Default</option>
                                            <option value="inter" <?php selected($font_family, 'inter'); ?>>Inter</option>
                                            <option value="roboto" <?php selected($font_family, 'roboto'); ?>>Roboto</option>
                                            <option value="opensans" <?php selected($font_family, 'opensans'); ?>>Open Sans</option>
                                            <option value="lato" <?php selected($font_family, 'lato'); ?>>Lato</option>
                                            <option value="poppins" <?php selected($font_family, 'poppins'); ?>>Poppins</option>
                                            <option value="georgia" <?php selected($font_family, 'georgia'); ?>>Georgia (Serif)</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Size</th>
                                    <td>
                                        <select name="font_size" id="font_size" style="width: 100%;">
                                            <option value="13" <?php selected($font_size, '13'); ?>>Small (13px)</option>
                                            <option value="14" <?php selected($font_size, '14'); ?>>Medium (14px)</option>
                                            <option value="15" <?php selected($font_size, '15'); ?>>Default (15px)</option>
                                            <option value="16" <?php selected($font_size, '16'); ?>>Large (16px)</option>
                                            <option value="17" <?php selected($font_size, '17'); ?>>Extra Large (17px)</option>
                                        </select>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <!-- Branding -->
                        <div class="postbox" style="padding: 15px; margin-bottom: 15px;">
                            <h3 style="margin: 0 0 15px 0; font-size: 14px;">Branding</h3>
                            <table class="form-table" style="margin: 0;">
                                <tr>
                                    <th style="width: 100px;">Logo</th>
                                    <td>
                                        <input type="text" name="logo_url" id="logo_url" value="<?php echo esc_url($logo); ?>" style="width: 200px;">
                                        <button type="button" class="button" id="upload_logo_btn">Upload</button>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Logo Size</th>
                                    <td style="display: flex; gap: 10px; align-items: center;">
                                        <label>W: <input type="number" name="logo_width" id="logo_width" value="<?php echo esc_attr($logo_width); ?>" style="width: 60px;" min="30" max="300"> px</label>
                                        <label>H: <input type="number" name="logo_height" id="logo_height" value="<?php echo esc_attr($logo_height); ?>" style="width: 60px;" min="0" max="200" placeholder="auto"> px</label>
                                        <small style="color: #666;">(0 = auto)</small>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Company</th>
                                    <td><input type="text" name="company_name" value="<?php echo esc_attr($company); ?>" style="width: 100%;"></td>
                                </tr>
                                <tr>
                                    <th>Tagline</th>
                                    <td><input type="text" name="tagline" value="<?php echo esc_attr($tagline); ?>" style="width: 100%;"></td>
                                </tr>
                                <tr>
                                    <th>From Name</th>
                                    <td><input type="text" name="from_name" value="<?php echo esc_attr($from_name); ?>" style="width: 100%;" placeholder="Sender name for emails"></td>
                                </tr>
                                <tr>
                                    <th>Reply-to</th>
                                    <td><input type="email" name="reply_to" value="<?php echo esc_attr($reply_to); ?>" style="width: 100%;" placeholder="email@example.com"></td>
                                </tr>
                            </table>
                        </div>

                        <!-- Header/Footer -->
                        <div class="postbox" style="padding: 15px; margin-bottom: 15px;">
                            <h3 style="margin: 0 0 15px 0; font-size: 14px;">Sections</h3>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="show_header" value="1" <?php checked($show_header); ?>> Show Header
                            </label>
                            <label style="display: block;">
                                <input type="checkbox" name="show_footer" value="1" <?php checked($show_footer); ?>> Show Footer
                            </label>
                        </div>

                        <!-- Social Links -->
                        <div class="postbox" style="padding: 15px; margin-bottom: 15px;">
                            <h3 style="margin: 0 0 15px 0; font-size: 14px;">Social Links</h3>
                            <?php
                            $platforms = array('facebook', 'x', 'youtube', 'whatsapp', 'instagram', 'linkedin');
                            foreach ($platforms as $p) {
                                $val = $social[$p] ?? '';
                                echo '<div style="margin-bottom: 8px;"><label style="display: flex; align-items: center; gap: 8px;">';
                                echo '<span style="width: 70px; text-transform: capitalize;">' . esc_html($p) . '</span>';
                                echo '<input type="url" name="social[' . $p . ']" value="' . esc_url($val) . '" style="flex: 1;" placeholder="https://">';
                                echo '</label></div>';
                            }
                            ?>
                        </div>

                        <!-- Apply To -->
                        <div class="postbox" style="padding: 15px; margin-bottom: 15px;">
                            <h3 style="margin: 0 0 15px 0; font-size: 14px;">Apply Template To</h3>
                            <p style="margin: 0 0 10px 0; font-size: 12px; color: #666;">Select which email types should use this template:</p>
                            <?php
                            $email_types = array(
                                'emailer' => 'Ofast Emailer (campaigns)',
                                'notifications' => 'WordPress Notifications',
                                'woocommerce' => 'WooCommerce Emails',
                                'all_wp' => 'All WordPress Emails'
                            );
                            foreach ($email_types as $key => $label) {
                                $checked = in_array($key, (array)$apply_to) ? 'checked' : '';
                                echo '<label style="display: block; margin-bottom: 6px;">';
                                echo '<input type="checkbox" name="apply_to[]" value="' . $key . '" ' . $checked . '> ' . esc_html($label);
                                echo '</label>';
                            }
                            ?>
                        </div>

                        <!-- Email Cron Settings (Like Tutor LMS) -->
                        <div style="margin-bottom: 20px; padding: 15px; background: #f0f6fc; border-radius: 8px; border: 1px solid #c3d9ed;">
                            <h3 style="margin: 0 0 15px 0; font-size: 14px; color: #1d4ed8;">Email Cron Settings</h3>

                            <?php
                            $cron_enabled = get_option('ofast_email_cron_enabled', 0);
                            $cron_frequency = get_option('ofast_email_cron_frequency', 200);
                            $emails_per_cron = get_option('ofast_email_emails_per_cron', 10);
                            ?>

                            <label style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px; cursor: pointer;">
                                <input type="checkbox" name="cron_enabled" value="1" <?php checked($cron_enabled, 1); ?> style="width: 18px; height: 18px;">
                                <span>
                                    <strong>WP Cron for Bulk Mailing</strong><br>
                                    <span style="font-size: 12px; color: #666;">Enable WordPress native scheduler for email sending</span>
                                </span>
                            </label>

                            <div style="display: grid; gap: 15px;">
                                <div>
                                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">WP Email Cron Frequency (seconds)</label>
                                    <input type="number" name="cron_frequency" value="<?php echo esc_attr($cron_frequency); ?>" min="60" max="3600" style="width: 100px;">
                                    <span style="font-size: 12px; color: #666;">Time between cron runs (default: 200)</span>
                                </div>

                                <div>
                                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Emails Per Cron Execution</label>
                                    <input type="number" name="emails_per_cron" value="<?php echo esc_attr($emails_per_cron); ?>" min="1" max="100" style="width: 100px;">
                                    <span style="font-size: 12px; color: #666;">Number of emails to send per cron run (default: 10)</span>
                                </div>
                            </div>
                        </div>

                        <!-- Buttons -->
                        <style>
                            @media screen and (max-width: 480px) {
                                .ofast-template-buttons { gap: 8px !important; }
                                .ofast-template-buttons .button { font-size: 11px !important; padding: 4px 8px !important; }
                            }
                        </style>
                        <div class="ofast-template-buttons" style="display: flex; gap: 10px;">
                            <button type="submit" name="ofast_save_template" class="button button-primary">Save Template</button>
                            <button type="submit" name="ofast_send_test_template" class="button button-primary">Send Test Email</button>
                            <button type="submit" name="ofast_reset_template" class="button" onclick="return confirm('Reset all template settings to defaults?');">Reset to Default</button>
                        </div>
                    </form>
                </div>

                <!-- Right Column: Preview -->
                <div style="flex: 1; min-width: 0; position: sticky; top: 32px; align-self: flex-start;">
                    <div class="postbox" style="padding: 15px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <h3 style="margin: 0; font-size: 14px;">Live Preview</h3>
                            <div style="display: flex; gap: 5px;">
                                <button type="button" class="button device-btn active" data-width="600">Desktop</button>
                                <button type="button" class="button device-btn" data-width="375">Mobile</button>
                            </div>
                        </div>
                        <div style="background: #f1f5f9; padding: 10px; border-radius: 8px; display: flex; justify-content: center; max-width: 100%; overflow-x: auto;">
                            <iframe id="template-preview" style="width: 600px; max-width: 100%; height: 500px; border: none; border-radius: 8px; background: #fff; transition: width 0.3s; box-shadow: 0 2px 8px rgba(0,0,0,0.1);"></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Initialize color pickers
                $('.ofast-color-picker').wpColorPicker({
                    change: function() {
                        setTimeout(updatePreview, 100);
                    }
                });

                // Template style change
                $('input[name="template_style"]').on('change', function() {
                    $('input[name="template_style"]').each(function() {
                        var $label = $(this).closest('label');
                        if ($(this).is(':checked')) {
                            $label.css({
                                'border-color': '#2271b1',
                                'background': '#f0f6fc'
                            });
                        } else {
                            $label.css({
                                'border-color': '#ddd',
                                'background': '#fff'
                            });
                        }
                    });
                    updatePreview();
                });

                // Other inputs
                $('input[name="company_name"], input[name="tagline"], input[name="logo_url"], input[name="show_header"], input[name="show_footer"], input[name="logo_width"], input[name="logo_height"]').on('change keyup', function() {
                    updatePreview();
                });

                // Device toggle
                $('.device-btn').on('click', function() {
                    var width = $(this).data('width');
                    $('#template-preview').css('width', width + 'px');
                    $('.device-btn').removeClass('button-primary active');
                    $(this).addClass('button-primary active');
                });

                // Media uploader for logo
                $('#upload_logo_btn').on('click', function(e) {
                    e.preventDefault();
                    var frame = wp.media({
                        title: 'Select Logo',
                        button: {
                            text: 'Use this image'
                        },
                        multiple: false
                    });
                    frame.on('select', function() {
                        var attachment = frame.state().get('selection').first().toJSON();
                        $('#logo_url').val(attachment.url);
                        updatePreview();
                    });
                    frame.open();
                });

                // Update preview - Table-based, inline-styled template (matches PHP class)
                function updatePreview() {
                    var primary = $('input[name="primary_color"]').val() || '#2563eb';
                    var bgColor = $('input[name="bg_color"]').val() || '#f3f4f6';
                    var textColor = $('input[name="text_color"]').val() || '#111827';
                    var headerBg = '#111827'; // Dark header
                    var logo = $('input[name="logo_url"]').val() || '';
                    var company = $('input[name="company_name"]').val() || '';
                    var tagline = $('input[name="tagline"]').val() || '';
                    var showHeader = $('input[name="show_header"]').is(':checked');
                    var showFooter = $('input[name="show_footer"]').is(':checked');
                    var logoWidth = parseInt($('input[name="logo_width"]').val()) || 140;

                    // Collect social links with brand colors
                    var socialLinks = {};
                    var socialColors = {
                        'facebook': '#1877f2',
                        'x': '#000000',
                        'instagram': '#e1306c',
                        'linkedin': '#0a66c2',
                        'youtube': '#ff0000',
                        'whatsapp': '#25d366'
                    };
                    var socialNames = {
                        'facebook': 'Facebook',
                        'x': 'X',
                        'instagram': 'Instagram',
                        'linkedin': 'LinkedIn',
                        'youtube': 'YouTube',
                        'whatsapp': 'WhatsApp'
                    };
                    $('input[name^="social["]').each(function() {
                        var platform = $(this).attr('name').match(/social\[(\w+)\]/)[1];
                        var url = $(this).val();
                        if (url) socialLinks[platform] = url;
                    });

                    // Build table-based HTML with inline styles
                    var html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Email Preview</title></head>';
                    html += '<body style="margin:0; padding:0; background-color:' + bgColor + '; font-family:Arial, Helvetica, sans-serif;">';
                    html += '<table width="100%" cellpadding="0" cellspacing="0" style="background-color:' + bgColor + '; padding:30px 0;"><tr><td align="center">';
                    
                    // Main card
                    html += '<table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:8px; overflow:hidden; max-width:100%;">';

                    // Header
                    if (showHeader && (logo || company)) {
                        html += '<tr><td style="background-color:' + headerBg + '; padding:24px; text-align:center;">';
                        if (logo) {
                            html += '<img src="' + logo + '" alt="' + company + '" style="max-width:' + logoWidth + 'px; height:auto; display:block; margin:0 auto;">';
                        } else if (company) {
                            html += '<div style="color:#ffffff; font-size:24px; font-weight:600;">' + company + '</div>';
                        }
                        html += '</td></tr>';
                    }

                    // Content
                    html += '<tr><td style="padding:32px; color:' + textColor + ';">';
                    html += '<div style="font-size:15px; line-height:1.7; color:#374151;">';
                    html += '<p style="margin:0 0 16px;"><strong>Hello John,</strong></p>';
                    html += '<p style="margin:0 0 16px;">This is a sample email to preview your template design. The content you write in your emails will appear here, with your branding and colors applied.</p>';
                    html += '<p style="margin:0;">Thank you for using Ofast Emailer!</p>';
                    html += '</div></td></tr>';

                    // Footer
                    if (showFooter) {
                        html += '<tr><td style="padding:0 32px;"><hr style="border:none; border-top:1px solid #e5e7eb;"></td></tr>';
                        html += '<tr><td style="padding:24px 32px; text-align:center; font-size:13px; color:#6b7280;">';
                        
                        // Company/tagline
                        if (company || tagline) {
                            html += '<p style="margin:0 0 12px;">';
                            if (company && tagline) {
                                html += company + ' — ' + tagline;
                            } else {
                                html += company || tagline;
                            }
                            html += '</p>';
                        }

                        // Social buttons (text-based)
                        var hasSocial = Object.keys(socialLinks).length > 0;
                        if (hasSocial) {
                            html += '<table cellpadding="0" cellspacing="0" align="center" style="margin-bottom:12px;"><tr>';
                            for (var platform in socialLinks) {
                                var color = socialColors[platform] || '#6b7280';
                                var name = socialNames[platform] || platform;
                                html += '<td style="padding:4px;">';
                                html += '<a href="' + socialLinks[platform] + '" style="display:inline-block; background-color:' + color + '; color:#ffffff; font-size:12px; font-weight:600; text-decoration:none; padding:8px 14px; border-radius:999px;">' + name + '</a>';
                                html += '</td>';
                            }
                            html += '</tr></table>';
                        }

                        var footerCompany = company || 'Your Site';
                        html += '<p style="margin:0; font-size:12px; color:#9ca3af;">&copy; ' + new Date().getFullYear() + ' ' + footerCompany + '. All rights reserved.</p>';
                        html += '</td></tr>';
                    }

                    html += '</table></td></tr></table></body></html>';

                    document.getElementById('template-preview').srcdoc = html;
                }

                // Initial preview
                updatePreview();
            });
        </script>
<?php
    }

    /**
     * Save template settings
     */
    private function save_template_settings()
    {
        update_option('ofast_email_template_style', sanitize_text_field($_POST['template_style'] ?? 'modern'));
        update_option('ofast_email_primary_color', sanitize_hex_color($_POST['primary_color'] ?? '#6366f1'));
        update_option('ofast_email_accent_color', sanitize_hex_color($_POST['accent_color'] ?? '#10b981'));
        update_option('ofast_email_bg_color', sanitize_hex_color($_POST['bg_color'] ?? '#f8fafc'));
        update_option('ofast_email_text_color', sanitize_hex_color($_POST['text_color'] ?? '#1e293b'));
        update_option('ofast_email_logo', esc_url_raw($_POST['logo_url'] ?? ''));
        update_option('ofast_email_company_name', sanitize_text_field($_POST['company_name'] ?? ''));
        update_option('ofast_email_tagline', sanitize_text_field($_POST['tagline'] ?? ''));
        update_option('ofast_email_show_header', isset($_POST['show_header']));
        update_option('ofast_email_show_footer', isset($_POST['show_footer']));
        update_option('ofast_email_from_name', sanitize_text_field($_POST['from_name'] ?? get_bloginfo('name')));
        update_option('ofast_email_reply_to', sanitize_email($_POST['reply_to'] ?? get_option('admin_email')));
        update_option('ofast_email_social', array_map('esc_url_raw', $_POST['social'] ?? array()));
        update_option('ofast_email_apply_to', array_map('sanitize_text_field', $_POST['apply_to'] ?? array('emailer')));
        update_option('ofast_email_font_family', sanitize_text_field($_POST['font_family'] ?? 'system'));
        update_option('ofast_email_font_size', absint($_POST['font_size'] ?? 15));
        update_option('ofast_email_logo_width', absint($_POST['logo_width'] ?? 120));
        update_option('ofast_email_logo_height', absint($_POST['logo_height'] ?? 0));

        // Email Cron Settings
        update_option('ofast_email_cron_enabled', isset($_POST['cron_enabled']) ? 1 : 0);
        update_option('ofast_email_cron_frequency', max(60, min(3600, absint($_POST['cron_frequency'] ?? 200))));
        update_option('ofast_email_emails_per_cron', max(1, min(100, absint($_POST['emails_per_cron'] ?? 10))));
    }

    /**
     * Reset template settings to defaults
     */
    private function reset_template_settings()
    {
        update_option('ofast_email_template_style', 'modern');
        update_option('ofast_email_primary_color', '#6366f1');
        update_option('ofast_email_accent_color', '#10b981');
        update_option('ofast_email_bg_color', '#f8fafc');
        update_option('ofast_email_text_color', '#1e293b');
        update_option('ofast_email_logo', '');
        update_option('ofast_email_company_name', get_bloginfo('name'));
        update_option('ofast_email_tagline', '');
        update_option('ofast_email_show_header', true);
        update_option('ofast_email_show_footer', true);
        update_option('ofast_email_from_name', get_bloginfo('name'));
        update_option('ofast_email_reply_to', get_option('admin_email'));
        update_option('ofast_email_social', array());
        update_option('ofast_email_apply_to', array('emailer'));
        update_option('ofast_email_font_family', 'system');
        update_option('ofast_email_font_size', '15');
        update_option('ofast_email_logo_width', '120');
        update_option('ofast_email_logo_height', '0');
    }

    /**
     * Render drafts page
     */
    public function render_drafts_page()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_email_drafts';
        $current_user_id = get_current_user_id();

        // Create table if it doesn't exist
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            $charset = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $table (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                admin_id BIGINT(20) UNSIGNED NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body LONGTEXT,
                roles TEXT,
                user_ids TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_admin_id (admin_id)
            ) $charset;";
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }

        // Handle delete action
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['draft_id'])) {
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_draft_' . $_GET['draft_id'])) {
                $wpdb->delete($table, array('id' => intval($_GET['draft_id']), 'admin_id' => $current_user_id));
                echo Ofast_X_Toast::render('Draft deleted successfully!', 'success', true);
            }
        }

        // Handle send now action
        if (isset($_GET['action']) && $_GET['action'] === 'send' && isset($_GET['draft_id'])) {
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'send_draft_' . $_GET['draft_id'])) {
                $draft = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table WHERE id = %d AND admin_id = %d",
                    intval($_GET['draft_id']),
                    $current_user_id
                ));
                
                if ($draft) {
                    $roles = json_decode($draft->roles, true) ?: array();
                    $user_ids = json_decode($draft->user_ids, true) ?: array();
                    
                    // Get recipients
                    $total_ids = $user_ids;
                    if (!empty($roles)) {
                        $role_ids = get_users(array('role__in' => $roles, 'fields' => 'ID'));
                        $total_ids = array_unique(array_merge($total_ids, $role_ids));
                    }
                    if (empty($total_ids)) {
                        $total_ids = array($current_user_id); // Fallback to admin
                    }
                    
                    $sent = 0;
                    $headers = $this->get_email_headers();
                    foreach (get_users(array('include' => $total_ids)) as $user) {
                        $message = $this->replace_placeholders($draft->body, $user);
                        $full_body = $this->get_email_template($message);
                        if (wp_mail($user->user_email, $draft->subject, $full_body, $headers)) {
                            $sent++;
                        }
                    }
                    
                    $this->log_email($draft->subject, $sent, 'Sent from draft', $draft->body);
                    $wpdb->delete($table, array('id' => $draft->id));
                    echo Ofast_X_Toast::render("Sent {$sent} emails from draft!", 'success', true);
                }
            }
        }

        // Get drafts for current admin
        $drafts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE admin_id = %d ORDER BY updated_at DESC",
            $current_user_id
        ));

        echo '<div class="wrap"><h1>Email Drafts</h1>';
        
        if (empty($drafts)) {
            echo '<div class="notice notice-info"><p>No drafts yet. <a href="' . admin_url('admin.php?page=ofast-emailer') . '">Create an email</a> and save it as draft.</p></div>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:30%">Subject</th>
                        <th style="width:20%">Recipients</th>
                        <th style="width:20%">Last Modified</th>
                        <th style="width:30%">Actions</th>
                    </tr>
                </thead>
                <tbody>';
            
            foreach ($drafts as $draft) {
                $roles = json_decode($draft->roles, true) ?: array();
                $user_ids = json_decode($draft->user_ids, true) ?: array();
                $recipients = array();
                if (!empty($roles)) $recipients[] = count($roles) . ' role(s)';
                if (!empty($user_ids)) $recipients[] = count($user_ids) . ' user(s)';
                $recipients_text = !empty($recipients) ? implode(', ', $recipients) : 'Admin only';
                
                $edit_url = admin_url('admin.php?page=ofast-emailer&draft_id=' . $draft->id);
                $send_url = wp_nonce_url(admin_url('admin.php?page=ofast-email-drafts&action=send&draft_id=' . $draft->id), 'send_draft_' . $draft->id);
                $delete_url = wp_nonce_url(admin_url('admin.php?page=ofast-email-drafts&action=delete&draft_id=' . $draft->id), 'delete_draft_' . $draft->id);
                
                echo '<tr>
                    <td><strong>' . esc_html($draft->subject ?: '(No subject)') . '</strong></td>
                    <td>' . esc_html($recipients_text) . '</td>
                    <td>' . esc_html(date('M j, Y g:i a', strtotime($draft->updated_at))) . '</td>
                    <td>
                        <a href="' . esc_url($edit_url) . '" class="button button-small">✏️ Edit</a>
                        <a href="' . esc_url($send_url) . '" class="button button-small button-primary" onclick="return confirm(\'Send this draft now?\')">📧 Send Now</a>
                        <a href="' . esc_url($delete_url) . '" class="button button-small" onclick="return confirm(\'Delete this draft?\')">🗑️ Delete</a>
                    </td>
                </tr>';
            }
            
            echo '</tbody></table>';
        }
        
        echo '</div>';
    }
}
