<?php

/**
 * Ofast X Email Admin Interface
 * All 13 fixes integrated into proper OOP structure
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

        /* Temporarily disabled - requires Action Scheduler
        add_submenu_page(
            'ofast-emailer',
            'Scheduled Emails',
            'Scheduled',
            'manage_options',
            'ofast-scheduled-emails',
            array($this, 'render_scheduled_page')
        );
        */

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
            'Settings',
            'Settings',
            'manage_options',
            'ofast-email-settings',
            array($this, 'render_settings_page')
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

        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email'])) {
            $subject = sanitize_text_field($_POST['subject']);
            $body = wp_kses_post($_POST['message']);
            $selected_roles = $_POST['roles'] ?? [];
            $send_test = isset($_POST['test_email']);
            $schedule_time = $_POST['schedule_time'] ?? '';
            $timestamp = $schedule_time ? strtotime($schedule_time) : time();

            // FIX #7: Get checked user IDs from checkboxes
            $checked_user_ids = $_POST['checked_users'] ?? [];

            // Parse user ID ranges
            $input_ids = preg_split('/\s*,\s*/', $_POST['user_ids'] ?? '');
            $range_user_ids = [];
            foreach ($input_ids as $entry) {
                if (strpos($entry, '-') !== false) {
                    [$start, $end] = array_map('intval', explode('-', $entry));
                    $range_user_ids = array_merge($range_user_ids, range($start, $end));
                } elseif (is_numeric($entry)) {
                    $range_user_ids[] = intval($entry);
                }
            }

            // Merge all user IDs
            $selected_user_ids = array_unique(array_merge($range_user_ids, array_map('intval', $checked_user_ids)));

            if ($send_test) {
                $user = wp_get_current_user();
                $message = $this->replace_placeholders($body, $user);
                $headers = $this->get_email_headers();
                wp_mail($user->user_email, $subject, $this->get_email_template($message), $headers);
                $result_message = '<div class="notice notice-success"><p>✅ Test email sent to ' . esc_html($user->user_email) . '</p></div>';
            } else {
                // Merge user IDs + roles
                $total_ids = $selected_user_ids;
                if (!empty($selected_roles)) {
                    $role_ids = get_users(['role__in' => $selected_roles, 'fields' => 'ID']);
                    $total_ids = array_unique(array_merge($total_ids, $role_ids));
                }

                // FIX #4 & #5: Change threshold to 40 for scheduling
                if (count($total_ids) <= 40) {
                    $sent = 0;
                    $headers = $this->get_email_headers();
                    foreach (get_users(['include' => $total_ids]) as $user) {
                        $message = $this->replace_placeholders($body, $user);
                        if (wp_mail($user->user_email, $subject, $this->get_email_template($message), $headers)) {
                            $sent++;
                        }
                    }

                    $this->log_email($subject, $sent, 'Immediate send');
                    $result_message = '<div class="notice notice-success"><p>✅ Sent immediately to ' . $sent . ' user(s)</p></div>';
                } else {
                    // Schedule in batches of 40 users per hour using Action Scheduler
                    $chunks = array_chunk($total_ids, 40);
                    $scheduled_count = 0;

                    foreach ($chunks as $i => $chunk) {
                        $batch_time = $timestamp + ($i * 3600); // 1 hour apart

                        // Use WordPress cron for scheduling
                        wp_schedule_single_event(
                            $batch_time,
                            'ofast_send_email_batch',
                            array(
                                array(
                                    'subject' => $subject,
                                    'body' => $body,
                                    'user_ids' => $chunk
                                )
                            )
                        );
                        $scheduled_count++;
                    }

                    $result_message = '<div class="notice notice-success"><p>📅 ' . $scheduled_count . ' batches scheduled (40 users/hour) starting ' . date('Y-m-d H:i', $timestamp) . '</p></div>';
                }
            }
        }

        // Render UI
        $this->render_send_form($result_message, $roles);
    }

    /**
     * Render send form
     */
    private function render_send_form($result_message, $roles)
    {
        echo '<div class="wrap"><h2>📧 Send Email</h2>' . $result_message . '
        <form method="post" enctype="multipart/form-data" id="email-form">
            <p><label><strong>Email Subject:</strong><br>
            <input type="text" name="subject" style="width: 100%;" required></label></p>

            <p><label><strong>Message Body:</strong><br>';
        wp_editor('', 'message', [
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
                <small>Leave blank to send immediately. More than 40 recipients will auto-schedule.</small></label>
            </p>


            <p><label><input type="checkbox" name="test_email"> Send to me as test only</label></p>

            <p>
                <button type="submit" name="send_email" class="button button-primary"> Send / Schedule</button>
                <button type="button" id="preview-email-btn" class="button button-secondary" style="margin-left:10px;">👁️ Preview Email</button>
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
                        $("#user-pagination").html(visibleRows.length + " users | " + pagination);
                        
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

        // Preview Modal HTML
        echo '<div id="email-preview-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:999999;overflow-y:auto;">
            <div style="position:relative;width:90%;max-width:800px;margin:30px auto;background:white;border-radius:8px;overflow:hidden;">
                <div style="padding:15px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                    <h3 style="margin:0;">📧 Email Preview</h3>
                    <button id="close-preview-modal" style="background:none;border:none;font-size:24px;cursor:pointer;color:#64748b;">&times;</button>
                </div>
                <div id="preview-content" style="padding:20px;max-height:calc(100vh - 200px);overflow-y:auto;">
                    <!-- Preview will load here -->
                </div>
            </div>
        </div>';

        // Preview Modal JavaScript
        echo '<script>
        jQuery(document).ready(function($) {
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
                
                // Show loading
                $("#preview-content").html("<p style=\'text-align:center;padding:40px;\'><span class=\'spinner is-active\' style=\'float:none;\'></span><br>Generating preview...</p>");
                $("#email-preview-modal").fadeIn();
                
                // AJAX to get preview
                $.post(ajaxurl, {
                    action: "ofast_preview_email",
                    nonce: "' . wp_create_nonce('ofast_preview_email') . '",
                    subject: subject,
                    message: message
                }, function(response) {
                    if (response.success) {
                        $("#preview-content").html(response.data.html);
                    } else {
                        $("#preview-content").html("<p style=\'color:red;\'>Error loading preview</p>");
                    }
                });
            });
            
            // Close Modal
            $("#close-preview-modal, #email-preview-modal").click(function(e) {
                if (e.target === this) {
                    $("#email-preview-modal").fadeOut();
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

        echo '<div class="wrap"><h2>📚 Email History</h2>';

        if (empty($logs)) {
            echo '<p>No emails have been logged yet.</p>';
        } else {
            echo '<table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:5%;">ID</th>
                        <th>Subject</th>
                        <th>Sent At</th>
                        <th>Recipients</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>';
            foreach ($logs as $log) {
                echo '<tr>
                    <td>' . esc_html($log->id) . '</td>
                    <td>' . esc_html(wp_trim_words($log->subject, 12, '...')) . '</td>
                    <td>' . esc_html($log->sent_at) . '</td>
                    <td>' . esc_html($log->recipient_count) . '</td>
                    <td>' . esc_html($log->notes) . '</td>
                </tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }

    /**
     * Render settings page (FIX #2, #3, #12, #13)
     */
    public function render_settings_page()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ofast_settings_update'])) {
            update_option('ofast_email_logo', esc_url_raw($_POST['email_logo']));
            update_option('ofast_email_tagline', sanitize_text_field($_POST['email_tagline']));
            update_option('ofast_email_subtitle', sanitize_text_field($_POST['email_subtitle']));
            update_option('ofast_email_site_name', sanitize_text_field($_POST['site_name']));
            update_option('ofast_email_company_name', sanitize_text_field($_POST['company_name']));
            update_option('ofast_email_owner_name', sanitize_text_field($_POST['owner_name']));
            update_option('ofast_email_from_name', sanitize_text_field($_POST['email_from']));
            update_option('ofast_email_reply_to', sanitize_email($_POST['email_reply']));
            update_option('ofast_email_social', array_map('esc_url_raw', $_POST['social'] ?? []));
            echo '<div class="notice notice-success"><p>✅ Settings saved!</p></div>';
        }

        $logo = get_option('ofast_email_logo', '');
        $tagline = get_option('ofast_email_tagline', 'Learn. Create. Earn');
        $subtitle = get_option('ofast_email_subtitle', 'Africa\'s No 1 Digital Learning Hub');
        $site_name = get_option('ofast_email_site_name', 'Ofastshop Digitals');
        $company_name = get_option('ofast_email_company_name', 'Ofastshop Digitals');
        $owner_name = get_option('ofast_email_owner_name', 'Bofast World');
        $from = get_option('ofast_email_from_name', 'Ofastshop Digitals');
        $reply = get_option('ofast_email_reply_to', 'support@ofastshop.com');
        $social = get_option('ofast_email_social', []);

        echo '<div class="wrap"><h2>⚙️ Ofast Email Settings</h2>
        <form method="post">
            <h3>Email Template Branding</h3>
            <table class="form-table">
                <tr><th>Logo URL</th><td><input type="url" name="email_logo" value="' . esc_attr($logo) . '" class="regular-text"></td></tr>
                <tr><th>Header Tagline</th><td><input type="text" name="email_tagline" value="' . esc_attr($tagline) . '" class="regular-text"></td></tr>
                <tr><th>Header Subtitle</th><td><input type="text" name="email_subtitle" value="' . esc_attr($subtitle) . '" class="regular-text"></td></tr>
            </table>
            
            <h3>Sender Information</h3>
            <table class="form-table">
                <tr><th>From Name</th><td><input type="text" name="email_from" value="' . esc_attr($from) . '" class="regular-text"></td></tr>
                <tr><th>Reply-to Email</th><td><input type="email" name="email_reply" value="' . esc_attr($reply) . '" class="regular-text" placeholder="support@ofastshop.com"></td></tr>
            </table>
            
            <h3>Footer Customization</h3>
            <table class="form-table">
                <tr><th>Site Name (footer link)</th><td><input type="text" name="site_name" value="' . esc_attr($site_name) . '" class="regular-text"></td></tr>
                <tr><th>Company Name (copyright)</th><td><input type="text" name="company_name" value="' . esc_attr($company_name) . '" class="regular-text"></td></tr>
                <tr><th>Owner Name</th><td><input type="text" name="owner_name" value="' . esc_attr($owner_name) . '" class="regular-text"></td></tr>
            </table>
            
            <h3>Social Media Links</h3>
            <table class="form-table">
                <tr><th>Social Links</th><td>';
        $platforms = ['facebook', 'x', 'youtube', 'whatsapp'];
        foreach ($platforms as $platform) {
            echo ucfirst($platform) . ': <input type="url" name="social[' . $platform . ']" value="' . esc_attr($social[$platform] ?? '') . '" class="regular-text" placeholder="https://"><br>';
        }
        echo '</td></tr>
            </table>
            <p><button type="submit" name="ofast_settings_update" class="button button-primary">💾 Save Settings</button></p>
        </form></div>';
    }

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
    private function log_email($subject, $recipient_count, $notes)
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'ofast_email_logs', [
            'subject' => $subject,
            'sent_at' => current_time('mysql'),
            'recipient_count' => $recipient_count,
            'notes' => $notes
        ]);
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

        $subject = sanitize_text_field($_POST['subject'] ?? 'Email Preview');
        $message = wp_kses_post($_POST['message'] ?? '');

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
     * Render scheduled emails page (Action Scheduler queue view)
     */
    public function render_scheduled_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions');
        }

        echo '<div class="wrap">';
        echo '<h1>📅 Scheduled Email Batches</h1>';
        echo '<p>Email batches scheduled via Action Scheduler (runs reliably every minute)</p>';

        // Get all pending scheduled actions for email batches
        $actions = as_get_scheduled_actions(array(
            'hook' => 'ofast_send_email_batch',
            'status' => 'pending',
            'per_page' => 50
        ));

        if (empty($actions)) {
            echo '<div class="notice notice-info"><p>No email batches currently scheduled.</p></div>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>Batch ID</th><th>Subject</th><th>Recipients</th><th>Scheduled Time</th><th>Status</th>';
            echo '</tr></thead><tbody>';

            foreach ($actions as $action_id => $action) {
                $args = $action->get_args();
                $subject = esc_html($args['subject'] ?? 'N/A');
                $user_count = count($args['user_ids'] ?? []);
                $scheduled_time = $action->get_schedule()->get_date()->format('Y-m-d H:i:s');

                echo '<tr>';
                echo '<td>' . esc_html($action_id) . '</td>';
                echo '<td>' . $subject . '</td>';
                echo '<td>' . $user_count . ' users</td>';
                echo '<td>' . esc_html($scheduled_time) . '</td>';
                echo '<td><span class="dashicons dashions-clock"></span> Pending</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        // Show completed actions (last 10)
        echo '<h2 style="margin-top: 30px;">Recently Completed Batches</h2>';
        $completed = as_get_scheduled_actions(array(
            'hook' => 'ofast_send_email_batch',
            'status' => 'complete',
            'per_page' => 10
        ));

        if (!empty($completed)) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>Subject</th><th>Recipients</th><th>Executed At</th>';
            echo '</tr></thead><tbody>';

            foreach ($completed as $action) {
                $args = $action->get_args();
                echo '<tr>';
                echo '<td>' . esc_html($args['subject'] ?? 'N/A') . '</td>';
                echo '<td>' . count($args['user_ids'] ?? []) . ' users</td>';
                echo '<td>' . esc_html($action->get_schedule()->get_date()->format('Y-m-d H:i:s')) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';
    }
}
