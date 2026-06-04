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

        // Register custom cron intervals (must happen early)
        add_filter( 'cron_schedules', array( $this, 'register_cron_intervals' ) );
    }

    /**
     * Load all required files
     */
    private function load_dependencies()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-template.php';
        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-admin.php';
        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-campaign.php';
        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-processor.php';
        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-retry.php';
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
        // Legacy cron batch hook (kept for backwards compatibility)
        add_action('ofast_send_email_batch', array($this, 'process_email_batch'), 10, 1);

        // ── Queue System: Loopback worker (rapid SMTP strategy) ──────────────
        // Called by non-blocking HTTP POST from Ofast_Email_Processor::fire_loopback().
        // No login required — verified via shared worker_key instead.
        add_action('wp_ajax_ofast_queue_worker',        array($this, 'ajax_queue_worker'));
        add_action('wp_ajax_nopriv_ofast_queue_worker', array($this, 'ajax_queue_worker'));

        // ── Queue System: Slow cron hook (PHP Mail / shared hosting strategy) ─
        add_action('ofast_campaign_rapid_batch',   array($this, 'cron_rapid_batch'), 10, 1);
        add_action('ofast_campaign_slow_batch',    array($this, 'cron_slow_batch'), 10, 1);
        add_action('ofast_campaign_cron_fallback', array($this, 'cron_campaign_fallback'), 10, 1);

        // ── Queue System: Progress polling (admin AJAX) ──────────────────────
        add_action('wp_ajax_ofast_campaign_progress', array($this, 'ajax_campaign_progress'));

        // ── Queue System: Pause / Resume / Cancel / Delete ───────────────────────────
        add_action('wp_ajax_ofast_campaign_action', array($this, 'ajax_campaign_action'));
        add_action('wp_ajax_ofast_campaign_delete', array($this, 'ajax_campaign_delete'));

        // ── Queue System: Daily cleanup of old completed campaigns ───────────
        if (!wp_next_scheduled('ofast_campaign_cleanup')) {
            wp_schedule_event(time(), 'daily', 'ofast_campaign_cleanup');
        }
        add_action('ofast_campaign_cleanup', array($this, 'cleanup_old_campaigns'));

        // Apply template to WordPress emails based on settings
        $apply_to = get_option('ofast_email_apply_to', array('emailer'));
        if (array_intersect(array('notifications', 'woocommerce', 'all_wp'), (array) $apply_to)) {
            add_filter('wp_mail', array($this, 'apply_template_to_wp_mail'), 999, 1);
        }

        // Daily log cleanup
        if (!wp_next_scheduled('ofast_email_cleanup')) {
            wp_schedule_event(time(), 'daily', 'ofast_email_cleanup');
        }
        add_action('ofast_email_cleanup', array($this, 'cleanup_old_logs'));

        // ── Smart Retries: Process retry queue every 5 minutes (Pro only) ────
        if ( ! wp_next_scheduled( 'ofast_email_process_retries' ) ) {
            wp_schedule_event( time() + 300, 'ofast_every_five_minutes', 'ofast_email_process_retries' );
        }
        add_action( 'ofast_email_process_retries', array( $this, 'cron_process_retries' ) );

        // ── Smart Retries: Daily cleanup of old retry records ────────────────
        if ( ! wp_next_scheduled( 'ofast_email_retry_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'ofast_email_retry_cleanup' );
        }
        add_action( 'ofast_email_retry_cleanup', array( $this, 'cron_cleanup_retries' ) );
    }

    // ────────────────────────────────────────────────────────────────
    //  QUEUE AJAX & CRON HANDLERS
    // ────────────────────────────────────────────────────────────────

    /**
     * AJAX: Loopback worker endpoint for the rapid queue strategy.
     *
     * Validates the shared worker key then delegates to the processor.
     * Intentionally has no nonce (internal server-to-server call), but
     * is protected by the rotating worker_key secret.
     */
    public function ajax_queue_worker()
    {
        // Validate internal worker key
        $provided_key = isset($_POST['worker_key']) ? sanitize_text_field(wp_unslash($_POST['worker_key'])) : '';
        if (empty($provided_key) || !hash_equals(Ofast_Email_Processor::get_worker_key(), $provided_key)) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }

        $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : null;

        // §1.1 Audit fix: Apply burst-protection delay HERE (at the start of the new loopback worker)
        // instead of at the end of run_rapid(). This frees the previous PHP worker immediately
        // instead of holding it open during sleep().
        $delay = min( 120, max( 0, (int) get_option( 'ofast_email_batch_delay', 3 ) ) );
        if ( $delay > 0 ) {
            sleep( $delay );
        }

        // Run one rapid batch
        Ofast_Email_Processor::run_rapid($campaign_id ?: null);

        wp_send_json_success('batch_processed');
    }

    /**
     * WP-Cron: Process one rapid batch for a scheduled campaign.
     *
     * @param int $campaign_id
     */
    public function cron_rapid_batch(int $campaign_id)
    {
        Ofast_Email_Processor::run_rapid($campaign_id);
    }

    /**
     * WP-Cron: Process one slow batch for a specific campaign.
     *
     * @param int $campaign_id
     */
    public function cron_slow_batch(int $campaign_id)
    {
        Ofast_Email_Processor::run_slow($campaign_id);

        // Reschedule next batch if not done
        $campaign = Ofast_Email_Campaign::get($campaign_id);
        if ($campaign && $campaign->status === Ofast_Email_Campaign::STATUS_QUEUED) {
            Ofast_Email_Processor::reschedule_slow_campaign($campaign_id, $campaign->next_run);
        }
    }

    /**
     * Backwards-compatible cron fallback that routes by campaign strategy.
     *
     * @param int $campaign_id
     */
    public function cron_campaign_fallback(int $campaign_id)
    {
        $campaign = Ofast_Email_Campaign::get($campaign_id);
        if ($campaign && $campaign->strategy === Ofast_Email_Campaign::STRATEGY_RAPID) {
            $this->cron_rapid_batch($campaign_id);
            return;
        }

        $this->cron_slow_batch($campaign_id);
    }

    /**
     * Register custom cron interval for smart retries.
     *
     * @param array $schedules Existing WP-Cron schedules.
     * @return array Modified schedules.
     */
    public function register_cron_intervals( $schedules ) {
        if ( ! isset( $schedules['ofast_every_five_minutes'] ) ) {
            $schedules['ofast_every_five_minutes'] = array(
                'interval' => 300,
                'display'  => __( 'Every 5 Minutes (Ofast Retries)', 'ofast-x' ),
            );
        }
        return $schedules;
    }

    /**
     * WP-Cron: Process due smart retries (Pro only).
     * Runs every 5 minutes, processes up to 20 retries per run.
     */
    public function cron_process_retries() {
        Ofast_Email_Retry::process_due_retries();
    }

    /**
     * WP-Cron: Cleanup retry records older than 7 days.
     * Runs daily to prevent table bloat.
     */
    public function cron_cleanup_retries() {
        Ofast_Email_Retry::cleanup_old( 7 );
    }

    /**
     * AJAX: Return progress data for a campaign (polled by the UI).
     */
    public function ajax_campaign_progress()
    {
        check_ajax_referer('ofast_campaign_progress', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
        if (!$campaign_id) {
            wp_send_json_error('Invalid campaign ID');
        }

        $campaign = Ofast_Email_Campaign::get($campaign_id);
        if (!$campaign) {
            wp_send_json_error('Campaign not found');
        }

        wp_send_json_success(array(
            'id'       => (int) $campaign->id,
            'status'   => $campaign->status,
            'strategy' => $campaign->strategy,
            'total'    => (int) $campaign->total,
            'sent'     => (int) $campaign->sent,
            'failed'   => (int) $campaign->failed,
            'position' => (int) $campaign->position,
            'progress' => Ofast_Email_Campaign::get_progress($campaign),
        ));
    }

    /**
     * AJAX: Perform a pause / resume / cancel action on a campaign.
     */
    public function ajax_campaign_action()
    {
        check_ajax_referer('ofast_campaign_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
        $action      = isset($_POST['campaign_action']) ? sanitize_key($_POST['campaign_action']) : '';
        $user_id     = get_current_user_id();

        if (!$campaign_id || !$action) {
            wp_send_json_error('Missing parameters');
        }

        $success = false;
        switch ($action) {
            case 'pause':
                $success = Ofast_Email_Campaign::pause($campaign_id, $user_id);
                break;
            case 'resume':
                $success = Ofast_Email_Campaign::resume($campaign_id, $user_id);
                // For rapid campaigns that are resumed, fire loopback immediately
                if ($success) {
                    $campaign = Ofast_Email_Campaign::get($campaign_id);
                    if ($campaign && $campaign->strategy === Ofast_Email_Campaign::STRATEGY_RAPID) {
                        Ofast_Email_Processor::fire_loopback($campaign_id);
                    }
                }
                break;
            case 'cancel':
                $success = Ofast_Email_Campaign::cancel($campaign_id, $user_id);
                break;
            default:
                wp_send_json_error('Unknown action');
        }

        if ($success) {
            wp_send_json_success(array('action' => $action, 'campaign_id' => $campaign_id));
        } else {
            wp_send_json_error('Action failed — campaign may already be in a terminal state');
        }
    }

    /**
     * Cron: Purge completed/cancelled campaigns older than 30 days.
     */
    public function cleanup_old_campaigns()
    {
        Ofast_Email_Campaign::cleanup_old(30);
    }

    /**
     * AJAX: Permanently delete a terminal campaign row.
     * Only allowed for completed / cancelled / failed campaigns.
     */
    public function ajax_campaign_delete()
    {
        check_ajax_referer('ofast_campaign_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
        if (!$campaign_id) {
            wp_send_json_error('Invalid campaign ID');
        }

        $deleted = Ofast_Email_Campaign::delete($campaign_id);
        if ($deleted) {
            wp_send_json_success(array('deleted' => $campaign_id));
        } else {
            wp_send_json_error('Cannot delete — campaign may still be active');
        }
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
        $retention_days = get_option('ofast_email_retention_days', 90);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ofast_email_logs WHERE sent_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retention_days
        ));
    }

    /**
     * Get sanitized email headers (centralized, CRLF-safe)
     * All email sending should use this method for headers.
     *
     * @param string $reply_to_override Optional override for Reply-To address
     * @return array Sanitized email headers
     */
    public static function get_safe_email_headers($reply_to_override = '')
    {
        // Get and sanitize From name — strip any CRLF characters to prevent header injection
        $from_name = get_option('ofast_email_from_name', get_bloginfo('name'));
        $from_name = preg_replace('/[\r\n]/', '', sanitize_text_field($from_name));
        if (empty($from_name)) {
            $from_name = get_bloginfo('name');
        }

        // Get and validate From email
        $from_email = sanitize_email(get_option('ofast_email_reply_to', get_option('admin_email')));
        if (!is_email($from_email)) {
            $from_email = get_option('admin_email');
        }

        // Get and validate Reply-To
        if (!empty($reply_to_override)) {
            $reply_to = sanitize_email($reply_to_override);
        } else {
            $reply_to = sanitize_email(get_option('ofast_email_reply_to', get_option('admin_email')));
        }
        if (!is_email($reply_to)) {
            $reply_to = $from_email;
        }

        return array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
            'Reply-To: ' . $reply_to
        );
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

        // Get sanitized headers
        $headers = self::get_safe_email_headers();

        // Get users
        $users = get_users(array(
            'include' => $user_ids,
            'fields' => 'all'
        ));

        $sent_count  = 0;
        $failed      = 0;
        $sample_body = '';

        // SMTP rate limiting — sleep() is SAFE here because this runs via WP-Cron
        // in the background. It does NOT block the user's browser.
        // Purpose: respect SMTP provider send rate limits (e.g. Gmail ~1/sec, Brevo ~10/sec).
        //
        // Values are capped to hard maximums to prevent runaway execution time:
        //   - per-email delay: 0–5 seconds (default 1s)
        //   - between-batch pause: 0–30 seconds (default 5s)
        $send_delay  = min(5,  max(0, intval(get_option('ofast_email_send_delay', 1))));   // seconds between emails (hard cap: 5s)
        $batch_size  = min(100, max(1, intval(get_option('ofast_email_batch_size', 50)))); // emails per batch (hard cap: 100)
        $batch_pause = min(30, max(0, intval(get_option('ofast_email_batch_pause', 5))));  // pause between batches (hard cap: 30s)

        $batches   = array_chunk($users, $batch_size);
        $batch_num = 0;

        foreach ($batches as $batch) {
            $batch_num++;
            foreach ($batch as $user) {
                $final_body = $this->replace_placeholders($body, $user);
                $email_html = Ofast_X_Email_Template::get_template($final_body);

                if (empty($sample_body)) {
                    $sample_body = $email_html;
                }

                if (wp_mail($user->user_email, $subject, $email_html, $headers)) {
                    $sent_count++;
                } else {
                    $failed++;
                    error_log('Ofast-X Email Batch: Failed to send to ' . $user->user_email);
                }

                // Rate limiting delay — paces sends to respect SMTP provider limits.
                // Safe here: cron is background, user browser is NOT waiting.
                if ($send_delay > 0) {
                    sleep($send_delay);
                }
            }

            // Pause between batches (except after the last batch).
            // Gives SMTP server breathing room between large send bursts.
            if ($batch_pause > 0 && $batch_num < count($batches)) {
                sleep($batch_pause);
            }
        }

        // Log the batch
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'ofast_email_logs', array(
            'subject'         => $subject,
            'body'            => $sample_body,
            'sent_at'         => current_time('mysql'),
            'recipient_count' => $sent_count,
            'status'          => $failed > 0 ? 'partial' : 'sent',
            'notes'           => 'Scheduled batch - ' . $sent_count . ' sent, ' . $failed . ' failed (' . count($batches) . ' batches)'
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
