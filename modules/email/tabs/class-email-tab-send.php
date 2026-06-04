<?php
/**
 * Email Tab: Send
 * Handles email composition, draft saving, sending with throttling, and form rendering.
 * Extracted from Ofast_X_Email_Admin for modularity.
 */

if (!defined('ABSPATH')) exit;

class Ofast_Email_Tab_Send
{
    private $admin;

    /**
     * @param Ofast_X_Email_Admin $admin  Parent admin instance for helper access
     */
    public function __construct($admin)
    {
        $this->admin = $admin;
    }

    /**
     * Auto-detect which queue strategy to use based on the active mailer.
     *
     * - If Ofast SMTP is configured and enabled → 'rapid' (loopback batches, fast)
     * - If default PHP Mail → 'slow' (WP-Cron, hourly batches, respects hosting limits)
     *
     * @return string 'rapid' | 'slow'
     */
    private function detect_strategy(): string
    {
        $smtp_enabled = get_option('ofast_smtp_enabled', false);
        $smtp_host    = get_option('ofast_smtp_host', '');

        if ($smtp_enabled && !empty($smtp_host)) {
            return Ofast_Email_Campaign::STRATEGY_RAPID;
        }

        return Ofast_Email_Campaign::STRATEGY_SLOW;
    }

    /**
     * Render send email page (ALL 13 FIXES INTEGRATED)
     */
    public function render_send_page($content_only = false)
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
            
            // Parse manual emails
            $manual_emails_input = sanitize_textarea_field(wp_unslash($_POST['manual_emails'] ?? ''));
            $manual_emails_arr = array();
            if (!empty($manual_emails_input)) {
                $emails_raw = preg_split('/[,\n]+/', $manual_emails_input);
                foreach ($emails_raw as $em) {
                    $em = trim($em);
                    if (is_email($em)) {
                        $manual_emails_arr[] = $em;
                    }
                }
            }
            
            $draft_id = isset($_POST['draft_id']) ? intval($_POST['draft_id']) : 0;

            $data = array(
                'admin_id' => get_current_user_id(),
                'subject' => $subject,
                'body' => $body,
                'roles' => json_encode($selected_roles),
                'user_ids' => json_encode($selected_user_ids),
                'manual_emails' => json_encode($manual_emails_arr),
                'updated_at' => current_time('mysql')
            );

            if ($draft_id > 0) {
                // Explicit ownership verification before update
                $existing_draft = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, admin_id FROM $table WHERE id = %d",
                    $draft_id
                ));

                if (!$existing_draft) {
                    $result_message = Ofast_X_Toast::render('Draft not found.', 'error', true);
                } elseif ((int) $existing_draft->admin_id !== get_current_user_id()) {
                    // Log unauthorized access attempt
                    error_log(sprintf(
                        'SECURITY: User %d attempted unauthorized update of draft %d (owned by user %d)',
                        get_current_user_id(),
                        $draft_id,
                        $existing_draft->admin_id
                    ));
                    $result_message = Ofast_X_Toast::render('Draft not found.', 'error', true);
                } else {
                    // User owns the draft, proceed with update
                    $wpdb->update($table, $data, array('id' => $draft_id));
                    $result_message = Ofast_X_Toast::render('Draft updated successfully!', 'success', true);
                }
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
                            if ($clean_role === '_imported_contacts' || wp_roles()->is_role($clean_role)) {
                                $selected_roles[] = $clean_role;
                            }
                        }
                    }

                    $send_test = isset($_POST['test_email']);
                    $schedule_time = sanitize_text_field($_POST['schedule_time'] ?? '');
                    $schedule_timestamp = 0;
                    if ($schedule_time !== '') {
                        $scheduled_date = DateTime::createFromFormat('Y-m-d\TH:i', $schedule_time, wp_timezone());
                        if (!$scheduled_date) {
                            $scheduled_date = date_create($schedule_time, wp_timezone());
                        }
                        if ($scheduled_date instanceof DateTimeInterface) {
                            $schedule_timestamp = $scheduled_date->getTimestamp();
                        }
                    }
                    $is_scheduled = $schedule_timestamp > time();

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

                    // Check for imported contacts
                    $include_contacts = false;
                    if (($key = array_search('_imported_contacts', $selected_roles)) !== false) {
                        $include_contacts = true;
                        unset($selected_roles[$key]); // Remove before querying WP roles
                    }

                    // Parse manual emails
                    $manual_emails_input = sanitize_textarea_field(wp_unslash($_POST['manual_emails'] ?? ''));
                    $manual_emails = array();
                    if (!empty($manual_emails_input)) {
                        $emails_raw = preg_split('/[,\n]+/', $manual_emails_input);
                        foreach ($emails_raw as $em) {
                            $em = trim($em);
                            if (is_email($em)) {
                                $manual_emails[] = $em;
                            }
                        }
                    }

                    // Add imported CRM contacts to manual emails
                    if ($include_contacts) {
                        global $wpdb;
                        $contacts_table = $wpdb->prefix . 'ofast_email_contacts';
                        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $contacts_table)) === $contacts_table) {
                            $crm_emails = $wpdb->get_col("SELECT email FROM {$contacts_table} WHERE status = 'subscribed'");
                            if ($crm_emails) {
                                $manual_emails = array_merge($manual_emails, $crm_emails);
                            }
                        }
                    }
                    $manual_emails = array_unique($manual_emails);

                    if ($send_test) {
                        $user = wp_get_current_user();
                        $message = $this->admin->replace_placeholders($body, $user);
                        $headers = $this->admin->get_email_headers();
                        wp_mail($user->user_email, $subject, $this->admin->get_email_template($message), $headers);
                        $result_message = Ofast_X_Toast::render('Test email sent to ' . esc_html($user->user_email), 'success');
                    } else {
                        // ── Free tier daily quota check ──────────────────────────────
                        // Only counts Ofast Emailer sends. WP core, WooCommerce, OTP
                        // emails are completely exempt (counter is never called for those).
                        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-quota.php';
                        // Merge user IDs + roles
                        $total_ids = $selected_user_ids;
                        if (!empty($selected_roles)) {
                            $role_ids = get_users(['role__in' => $selected_roles, 'fields' => 'ID']);
                            $total_ids = array_unique(array_merge($total_ids, $role_ids));
                        }

                        // FALLBACK: If no recipients selected, send only to current admin
                        if (empty($total_ids) && empty($manual_emails)) {
                            $current_user = wp_get_current_user();
                            $total_ids = array($current_user->ID);
                            error_log('Ofast-X Email: No recipients selected, defaulting to admin: ' . $current_user->user_email);
                        }

                        // SECURITY: Max recipient limit to prevent server overload
                        $max_recipients = apply_filters('ofast_email_max_recipients', 5000);
                        if (count($total_ids) + count($manual_emails) > $max_recipients) {
                            $total_ids     = array_slice($total_ids, 0, $max_recipients);
                            $manual_emails = [];
                        }

                        // Build the unified recipient list:
                        //   - WP user IDs stay as integers (processor fetches user data + personalizes)
                        //   - Manual/CRM emails stay as strings
                        $all_recipients = array_merge(
                            array_map('intval', $total_ids),
                            $manual_emails
                        );
                        $total_count = count($all_recipients);

                        // ── Free tier quota gate ──────────────────────────────────────────
                        if ( ! Ofast_Email_Quota::can_send( $total_count ) ) {
                            $q_used  = Ofast_Email_Quota::get_today_count();
                            $q_limit = Ofast_Email_Quota::get_daily_limit();
                            $q_left  = Ofast_Email_Quota::remaining();
                            $result_message = Ofast_X_Toast::render(
                                sprintf(
                                    'Daily email limit reached (%d/%d used today). %d remaining. <a href="%s" style="color:#6366f1;font-weight:600;">Upgrade to Pro</a> for unlimited emails.',
                                    $q_used, $q_limit, $q_left,
                                    esc_url( admin_url('admin.php?page=ofast-license') )
                                ),
                                'warning'
                            );
                        } else {

                        // ── V2 Queue threshold ────────────────────────────────────────────
                        // Default: 50 — configurable via filter.
                        $queue_enabled = (bool) get_option('ofast_email_queue_enabled', true);
                        $queue_threshold = max(1, (int) apply_filters('ofast_queue_threshold', get_option('ofast_email_queue_threshold', 50)));

                        if ($is_scheduled || ($queue_enabled && $total_count > $queue_threshold)) {
                            // ── QUEUE PATH: Insert campaign, fire worker, return campaign_id ──

                            // Load queue classes (require_once is idempotent)
                            require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-campaign.php';
                            require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-processor.php';

                            // Auto-detect strategy based on whether SMTP is active
                            $strategy = $this->detect_strategy();
                            $next_run = $is_scheduled
                                ? wp_date('Y-m-d H:i:s', $schedule_timestamp, wp_timezone())
                                : current_time('mysql');

                            $campaign_id = Ofast_Email_Campaign::create([
                                'subject'       => $subject,
                                'body'          => $body,
                                'recipient_ids' => $all_recipients,
                                'strategy'      => $strategy,
                                'next_run'      => $next_run,
                                'created_by'    => get_current_user_id(),
                            ]);

                            if ($campaign_id) {
                                if ($strategy === Ofast_Email_Campaign::STRATEGY_RAPID) {
                                    // Fire the first loopback worker immediately (non-blocking)
                                    Ofast_Email_Processor::fire_loopback($campaign_id);
                                    $result_message = Ofast_X_Toast::render(
                                        'Campaign #' . $campaign_id . ' queued! Sending ' . $total_count . ' emails in background batches. Track progress in the Campaigns tab.',
                                        'success'
                                    );
                                } else {
                                    // Schedule WP-Cron for slow strategy
                                    Ofast_Email_Processor::schedule_slow_campaign($campaign_id);
                                    $result_message = Ofast_X_Toast::render(
                                        'Campaign #' . $campaign_id . ' queued for slow delivery (' . $total_count . ' emails). Batches will send over the next few hours. Track progress in the Campaigns tab.',
                                        'info'
                                    );
                                }

                                // Log the campaign start
                                $this->admin->log_email(
                                    $subject,
                                    $total_count,
                                    'Campaign #' . $campaign_id . ' queued (' . $strategy . ' strategy) — ' . $total_count . ' recipients',
                                    $body,
                                    'scheduled',
                                    $selected_roles
                                );
                            } else {
                                $result_message = Ofast_X_Toast::render('Failed to create campaign queue entry. Please try again.', 'error');
                            }

                        } else {
                            // ── DIRECT PATH: ≤50 recipients, send synchronously ──────────────
                            $sent        = 0;
                            $failed      = 0;
                            $headers     = $this->admin->get_email_headers();
                            $sample_body = '';

                            // Build combined user list
                            $all_users = empty($total_ids) ? [] : get_users(['include' => $total_ids]);

                            // Add manual emails as dummy user objects
                            foreach ($manual_emails as $em) {
                                $dummy_user               = new stdClass();
                                $dummy_user->user_email   = $em;
                                $dummy_user->ID           = 0;
                                $dummy_user->first_name   = '';
                                $dummy_user->last_name    = '';
                                $dummy_user->display_name = '';
                                $dummy_user->user_login   = '';
                                $all_users[] = $dummy_user;
                            }

                            foreach ($all_users as $user) {
                                $message   = $this->admin->replace_placeholders($body, $user);
                                $full_body = $this->admin->get_email_template($message);
                                if (empty($sample_body)) {
                                    $sample_body = $full_body;
                                }
                                if (wp_mail($user->user_email, $subject, $full_body, $headers)) {
                                    $sent++;
                                } else {
                                    $failed++;
                                    error_log('Ofast-X Email: Failed to send to ' . $user->user_email);
                                }
                            }

                            // ── Increment daily quota counter (direct sends) ──
                            if ( $sent > 0 ) {
                                Ofast_Email_Quota::increment( $sent );
                            }

                            $log_target_roles = $selected_roles;
                            if ($include_contacts)   $log_target_roles[] = '_imported_contacts';
                            if (!empty($manual_emails)) $log_target_roles[] = '_manual_emails';

                            $this->admin->log_email($subject, $sent, 'Direct send — ' . $sent . ' sent, ' . $failed . ' failed', $sample_body, 'sent', $log_target_roles);

                            if ($failed > 0) {
                                $result_message = Ofast_X_Toast::render(
                                    'Sent to ' . $sent . ' of ' . $total_count . ' users. ' . $failed . ' failed — check SMTP logs.',
                                    'warning'
                                );
                            } else {
                                $result_message = Ofast_X_Toast::render('Sent successfully to ' . $sent . ' user(s)', 'success');
                            }
                        }

                        } // End quota gate
                    }
                } // End rate limit else block
            } // End double-submit else block
        }

        // Render UI
        $this->render_send_form($result_message, $roles, $content_only);
    }

    /**
     * Render send form
     */
    private function render_send_form($result_message, $roles, $content_only = false)
    {
        // Load draft if editing
        $draft = null;
        $draft_id = isset($_GET['draft_id']) ? intval($_GET['draft_id']) : 0;
        if ($draft_id > 0) {
            global $wpdb;
            $table = $wpdb->prefix . 'ofast_email_drafts';

            // Explicit ownership verification before loading draft
            $draft_check = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE id = %d",
                $draft_id
            ));

            if (!$draft_check) {
                // Draft doesn't exist - could show error but for UX just show empty form
                $draft = null;
            } elseif ((int) $draft_check->admin_id !== get_current_user_id()) {
                // Log unauthorized access attempt
                error_log(sprintf(
                    'SECURITY: User %d attempted unauthorized access to draft %d (owned by user %d)',
                    get_current_user_id(),
                    $draft_id,
                    $draft_check->admin_id
                ));
                $draft = null; // Show empty form, don't reveal draft exists
            } else {
                // User owns the draft, proceed with loading
                $draft = $draft_check;
            }
        }

        $draft_subject = $draft ? $draft->subject : '';
        $draft_body = $draft ? $draft->body : '';
        $draft_roles = $draft ? (json_decode($draft->roles, true) ?: array()) : array();
        $draft_user_ids = $draft ? (json_decode($draft->user_ids, true) ?: array()) : array();
        $draft_manual_emails = $draft && isset($draft->manual_emails) ? (json_decode($draft->manual_emails, true) ?: array()) : array();
        $draft_manual_emails_str = implode(', ', $draft_manual_emails);

        // Toast notification
        $toast_html = !empty($result_message) ? $result_message : '';
        ?>
<?php if (!$content_only): ?>
            <div class="wrap"><?php endif; ?>
            <?php echo $toast_html; ?>

            <?php if (!$content_only): ?>
                <!-- Header -->
                <div class="ofast-header">
                    <div class="ofast-header-icon">
                        <span class="dashicons dashicons-email-alt"></span>
                    </div>
                    <div class="ofast-header-content">
                        <h1>Send Email</h1>
                        <p>Compose and send emails to your users with personalized placeholders and scheduling options.</p>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" id="email-form">
                <?php wp_nonce_field('ofast_send_email_action', 'ofast_email_nonce'); ?>

                <?php if ($draft_id > 0): ?>
                    <input type="hidden" name="draft_id" value="<?php echo esc_attr($draft_id); ?>">
                    <div class="ofast-draft-notice">
                        <span class="dashicons dashicons-edit"></span>
                        <span>Editing draft: <strong><?php echo esc_html($draft_subject ?: '(No subject)'); ?></strong></span>
                        <a href="<?php echo admin_url('admin.php?page=ofast-emailer'); ?>" style="margin-left: auto;">Start
                            fresh</a>
                    </div>
                <?php endif; ?>

                <input type="hidden" name="ofast_submit_token" value="<?php echo esc_attr(wp_generate_password(16, false)); ?>">

                <div class="ofast-email-form-layout">
                    <!-- Left Column - Main Content -->
                    <div class="ofast-form-main">
                        <div class="ofast-card">
                            <div class="ofast-form-group">
                                <label>
                                    <strong>Email Subject</strong>
                                    <input type="text" name="subject" required value="<?php echo esc_attr($draft_subject); ?>"
                                        placeholder="Enter email subject...">
                                </label>
                            </div>

                            <div class="ofast-form-group">
                                <label><strong>Message Body</strong></label>
                                <?php
                                wp_editor($draft_body, 'message', [
                                    'textarea_name' => 'message',
                                    'media_buttons' => true,
                                    'textarea_rows' => 12,
                                ]);
                                ?>
                            </div>

                            <div class="ofast-placeholders-box">
                                <strong>Available Placeholders:</strong><br>
                                <code>{{user_id}}</code>, <code>{{username}}</code>, <code>{{user_display_name}}</code>,
                                <code>{{user_first_name}}</code>, <code>{{user_last_name}}</code>, <code>{{user_email}}</code>
                            </div>

                            <div class="ofast-form-group" style="margin-bottom: 45px;">
                                <label><strong>Select Roles</strong></label>
                                <div id="ofast-roles-picker" style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 8px;">
                                    <label class="ofast-checkbox-pill">
                                        <input type="checkbox" name="roles[]" value="_imported_contacts" <?php checked(in_array('_imported_contacts', $draft_roles)); ?>>
                                        <span>CRM Contacts</span>
                                    </label>
                                    <?php foreach ($roles as $key => $label): ?>
                                        <label class="ofast-checkbox-pill">
                                            <input type="checkbox" name="roles[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $draft_roles)); ?>>
                                            <span><?php echo esc_html($label); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Selected Audience Summary Bar -->
                                <div id="ofast-audience-summary" style="display: none; margin-top: 12px; padding: 10px 16px; background: linear-gradient(135deg, #ede9fe 0%, #e0e7ff 100%); border: 1px solid #c7d2fe; border-radius: 10px; animation: ofastFadeIn 0.25s ease;">
                                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                        <span class="dashicons dashicons-groups" style="font-size: 16px; width: 16px; height: 16px; color: #6366f1;"></span>
                                        <span style="font-size: 12px; font-weight: 600; color: #4338ca; text-transform: uppercase; letter-spacing: 0.5px;">Sending to:</span>
                                        <div id="ofast-audience-tags" style="display: flex; flex-wrap: wrap; gap: 6px;"></div>
                                    </div>
                                </div>
</div>

                            <div class="ofast-form-group">
                                <label>
                                    <strong>User ID(s) or Ranges (e.g. 5,12,30-35)</strong>
                                    <input type="text" name="user_ids" placeholder="Enter specific user IDs or ranges...">
                                </label>
                            </div>

                            <div class="ofast-form-group">
                                <label>
                                    <strong>Additional Recipients (Comma separated)</strong>
                                    <textarea name="manual_emails" rows="3" placeholder="email1@example.com, email2@example.com" style="width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; font-size: 14px; transition: all 0.2s;"><?php echo esc_textarea($draft_manual_emails_str); ?></textarea>
                                    <p class="description" style="margin-top: 5px; color: #64748b; font-size: 13px;">Paste external email addresses here for marketing campaigns.</p>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column - Sidebar -->
                    <div class="ofast-form-sidebar">
                        <div class="ofast-sidebar-card"
                            style="padding: 0; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); cursor: pointer;">
                            <div id="ofast-emailer-video-wrapper"
                                style="height: 200px; position: relative; display: flex; align-items: center; justify-content: center; background-color: #0f172a; overflow: hidden; margin: 0; padding: 0;"
                                class="ofast-emailer-video-container" data-video-id="0dcd5bLtYs8" tabindex="0" role="button"
                                aria-label="Play setup video">
                                <img src="https://img.youtube.com/vi/0dcd5bLtYs8/maxresdefault.jpg"
                                    onerror="this.src='https://img.youtube.com/vi/0dcd5bLtYs8/hqdefault.jpg';"
                                    alt="Emailer Setup Video"
                                    style="position: absolute; width: 100%; height: 100%; object-fit: cover; opacity: 0.7; transition: opacity 0.3s ease;">
                                <div
                                    style="position: absolute; width: 100%; height: 100%; background: linear-gradient(135deg, rgba(30,27,75,0.4) 0%, rgba(76,29,149,0.4) 100%); pointer-events: none;">
                                </div>
                                <div style="width: 64px; height: 64px; background: #8b5cf6; border-radius: 50%; display: flex; align-items: center; justify-content: center; z-index: 2; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4); transition: transform 0.2s ease, background 0.2s ease;"
                                    class="ofast-emailer-play-btn">
                                    <div
                                        style="width: 0; height: 0; border-top: 10px solid transparent; border-bottom: 10px solid transparent; border-left: 16px solid #fff; margin-left: 6px;">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php
                        // ── Daily Quota Badge ────────────────────────────────────
                        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-quota.php';
                        $is_pro_user   = ! Ofast_Email_Quota::is_free_tier();
                        $quota_used    = Ofast_Email_Quota::get_today_count();
                        $quota_limit   = Ofast_Email_Quota::get_daily_limit();
                        $quota_percent = $is_pro_user ? 0 : min( 100, round( ( $quota_used / max( 1, $quota_limit ) ) * 100 ) );
                        ?>
                        <div class="ofast-sidebar-card" style="padding: 14px 16px;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                                <span style="font-size: 12px; font-weight: 700; color: <?php echo $is_pro_user ? '#065f46' : '#1e3a5f'; ?>; text-transform: uppercase; letter-spacing: 0.5px;">
                                    <?php echo $is_pro_user ? '✅ Pro — Unlimited' : '📧 Daily Email Quota'; ?>
                                </span>
                                <?php if ( ! $is_pro_user ): ?>
                                    <span style="font-size: 12px; font-weight: 600; color: <?php echo $quota_used >= $quota_limit ? '#dc2626' : '#374151'; ?>;">
                                        <?php echo esc_html( $quota_used ); ?>/<?php echo esc_html( $quota_limit ); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if ( ! $is_pro_user ): ?>
                                <div style="background: #e5e7eb; border-radius: 50px; height: 6px; overflow: hidden;">
                                    <div style="background: <?php echo $quota_percent >= 100 ? '#dc2626' : ( $quota_percent >= 80 ? '#f59e0b' : '#6366f1' ); ?>; height: 100%; width: <?php echo esc_attr( $quota_percent ); ?>%; border-radius: 50px; transition: width 0.3s ease;"></div>
                                </div>
                                <div style="margin-top: 6px; font-size: 11px; color: #6b7280;">
                                    <?php if ( $quota_used >= $quota_limit ): ?>
                                        Limit reached — resets at midnight.
                                    <?php else: ?>
                                        <?php echo esc_html( Ofast_Email_Quota::remaining() ); ?> emails remaining today.
                                    <?php endif; ?>
                                    <a href="<?php echo esc_url( admin_url('admin.php?page=ofast-license') ); ?>" style="color: #6366f1; text-decoration: none; font-weight: 600;">Upgrade →</a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="ofast-sidebar-card">
                            <h4>Schedule</h4>
                            <div class="ofast-form-group" style="margin-bottom: 15px;">
                                <label>
                                    <strong style="font-size: 13px;">Schedule Time (optional)</strong>
                                    <input type="datetime-local" name="schedule_time" style="margin-top: 5px;">
                                </label>
                                <p class="description">Leave blank to send immediately.</p>
                            </div>
                        </div>

                        <div class="ofast-sidebar-card">
                            <h4>Actions</h4>
                            <label class="ofast-role-item" style="margin-bottom: 10px; background: #fff;">
                                <input type="checkbox" name="test_email">
                                Send to me as test only
                            </label>

                            <div style="display: flex; flex-direction: column; gap: 8px; margin-top: 15px;">
                                <button type="button" id="preview-email-btn" class="button button-secondary"
                                    style="width: 100%;">Preview Email</button>
                                <button type="submit" name="save_draft" class="button button-secondary"
                                    style="width: 100%;">Save as Draft</button>
                                <button type="submit" name="send_email" class="button button-primary" style="width: 100%;">Send
                                    Email</button>
                            </div>
                        </div>

                        <?php if ( Ofast_Email_Quota::is_free_tier() ): ?>
                        <div class="ofast-sidebar-card" style="background: linear-gradient(135deg, #faf5ff, #ede9fe); border: 1px solid #c4b5fd;">
                            <h4 style="margin-top: 0; color: #5b21b6; font-size: 14px;">⚡ Pro Queue Features</h4>
                            <ul style="font-size: 12px; color: #6b7280; padding-left: 18px; margin: 8px 0 14px; line-height: 1.8;">
                                <li><strong>Unlimited</strong> daily emails</li>
                                <li>Smart Retries with exponential backoff</li>
                                <li>Fallback SMTP server</li>
                                <li>Email health reports</li>
                            </ul>
                            <a href="<?php echo esc_url( admin_url('admin.php?page=ofast-license') ); ?>"
                               style="display: block; text-align: center; background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; padding: 9px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 13px; box-shadow: 0 2px 8px rgba(99,102,241,0.3); transition: transform 0.2s;"
                               onmouseover="this.style.transform='translateY(-1px)'" onmouseout="this.style.transform='translateY(0)'">
                                Upgrade to Pro →
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ofast-card" style="margin-top: 30px;">
                    <h3 style="margin-top: 0; margin-bottom: 15px;">Select Users Manually (Optional)</h3>

                    <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: center; margin-bottom: 15px;">
                        <label
                            style="display: flex; align-items: center; gap: 6px; color: #374151; font-size: 13px; font-weight: 500;">
                            <span class="dashicons dashicons-search"
                                style="font-size: 16px; width: 16px; height: 16px; color: #6366f1;"></span>
                            <input type="text" id="user-search" placeholder="Search users..."
                                style="border: 1px solid #d1d5db; border-radius: 8px; padding: 7px 12px; font-size: 13px; min-width: 180px;">
                        </label>

                        <label
                            style="display: flex; align-items: center; gap: 6px; color: #374151; font-size: 13px; font-weight: 500;">
                            Show
                            <select id="rows-per-page"
                                style="border: 1px solid #d1d5db; border-radius: 8px; padding: 6px 10px; font-size: 13px; background: #fff; cursor: pointer;">
                                <option value="10">10</option>
                                <option value="20">20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="all">All</option>
                            </select>
                            per page
                        </label>

                        <!-- Row Range Selector -->
                        <div
                            style="display: flex; align-items: center; gap: 6px; margin-left: auto; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 5px 8px;">
                            <span class="dashicons dashicons-screenoptions"
                                style="font-size: 16px; width: 16px; height: 16px; color: #6366f1;"></span>
                            <input type="text" id="row-range-input" placeholder="e.g. 1-50"
                                style="border: 1px solid #d1d5db; border-radius: 6px; padding: 6px 10px; font-size: 13px; width: 110px; background: #fff;">
                            <button type="button" id="row-range-select"
                                style="background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; border: none; border-radius: 6px; padding: 6px 14px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; transition: all 0.2s;">
                                Select
                            </button>
                            <button type="button" id="row-range-clear"
                                style="background: #fff; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; padding: 6px 10px; font-size: 12px; font-weight: 500; cursor: pointer; white-space: nowrap; transition: all 0.2s;"
                                title="Clear all selections">
                                Clear
                            </button>
                        </div>
                    </div>
                    <div id="row-range-feedback"
                        style="display: none; margin-bottom: 10px; padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 500; animation: ofastFadeIn 0.3s ease;">
                    </div>

                    <div style="overflow-x:auto; margin-top:15px; margin-bottom:10px;">
                        <table class="wp-list-table widefat striped" id="user-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="check-all"></th>
                                    <th>S/N</th>
                                    <th>First Name</th>
                                    <th>Last Name</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>User ID</th>
                                    <th>Role(s)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php

                                // Server-side pagination to prevent memory exhaustion on large sites (§5.1 audit fix)
                                $users_per_page = apply_filters( 'ofast_email_user_table_limit', 500 );
                                $user_page      = isset( $_GET['u_paged'] ) ? max( 1, intval( $_GET['u_paged'] ) ) : 1;
                                $total_users    = (int) count_users()['total_users'];
                                $total_pages    = max( 1, ceil( $total_users / $users_per_page ) );
                                if ( $user_page > $total_pages ) $user_page = $total_pages;

                                $users = get_users([
                                    'orderby' => 'ID',
                                    'order'   => 'ASC',
                                    'number'  => $users_per_page,
                                    'paged'   => $user_page,
                                ]);

                                if ( $total_users > $users_per_page ) {
                                    $page_start = ( $user_page - 1 ) * $users_per_page + 1;
                                    $page_end   = min( $user_page * $users_per_page, $total_users );
                                    echo '<div style="margin-bottom:12px; padding:10px 16px; background:#fffbeb; border:1px solid #fde68a; border-radius:8px; font-size:13px; color:#92400e;">';
                                    echo '<span class="dashicons dashicons-info" style="font-size:16px; width:16px; height:16px; color:#f59e0b; vertical-align:middle; margin-right:6px;"></span>';
                                    echo 'Showing users <strong>' . $page_start . '–' . $page_end . '</strong> of <strong>' . number_format( $total_users ) . '</strong>. ';
                                    echo 'Use the page selector below or the <em>User ID(s) / Ranges</em> field above to target specific users.';
                                    echo '</div>';
                                }

                                $i = ( $user_page - 1 ) * $users_per_page + 1;
                                foreach ($users as $user) {
                                    $userdata = get_userdata($user->ID);
                                    $user_roles_arr = ($userdata && isset($userdata->roles)) ? $userdata->roles : array();
                                    $roles_list = !empty($user_roles_arr) ? implode(', ', $user_roles_arr) : '—';
                                    $roles_data = esc_attr(implode(',', $user_roles_arr)); // For JS filtering
                                    echo '<tr class="user-row" data-roles="' . $roles_data . '">
                        <td><input type="checkbox" class="user-checkbox" name="checked_users[]" value="' . esc_attr($user->ID) . '"></td>
                        <td>' . $i++ . '</td>
                        <td class="search-text">' . esc_html($user->first_name) . '</td>
                        <td class="search-text">' . esc_html($user->last_name) . '</td>
                        <td class="search-text">' . esc_html($user->user_login) . '</td>
                        <td class="search-text">' . esc_html($user->user_email) . '</td>
                        <td class="search-text">' . esc_html($user->ID) . '</td>
                        <td class="search-text ofast-roles-cell">' . esc_html($roles_list) . '</td>
                    </tr>';
                                }
                                echo '</tbody></table>';
                                echo '<div id="user-pagination" style="margin-top:10px;"></div>';

                                // Server-side page navigation (§5.1 — only shown when total_users > per-page cap)
                                if ( $total_pages > 1 ) {
                                    $base_url = admin_url( 'admin.php?page=ofast-emailer&tab=send' );
                                    echo '<div style="margin-top:12px; display:flex; justify-content:center; gap:5px; flex-wrap:wrap;">';
                                    echo '<span style="align-self:center; font-size:13px; color:#64748b; margin-right:8px;">Server page:</span>';
                                    for ( $p = 1; $p <= min( $total_pages, 20 ); $p++ ) {
                                        $active_style = $p === $user_page
                                            ? 'background:linear-gradient(135deg,#6366f1,#4f46e5); color:#fff; border-color:#6366f1;'
                                            : 'background:#fff; color:#333;';
                                        echo '<a href="' . esc_url( add_query_arg( 'u_paged', $p, $base_url ) ) . '" '
                                           . 'style="padding:5px 10px; border:1px solid #e2e8f0; border-radius:4px; text-decoration:none; font-size:13px; ' . $active_style . '">'
                                           . $p . '</a>';
                                    }
                                    if ( $total_pages > 20 ) {
                                        echo '<span style="align-self:center; color:#64748b;">… ' . $total_pages . ' pages</span>';
                                    }
                                    echo '</div>';
                                }

                                echo '</div>'; // End ofast-card


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
                    <iframe id="preview-iframe" sandbox style="width:600px;height:calc(100vh - 150px);border:none;border-radius:8px;background:#fff;transition:width 0.3s ease;"></iframe>
                </div>
            </div>
        </div>';


                                echo '</div></form>';
                                if (!$content_only) {
                                    echo '</div>';
                                }
    }

}
