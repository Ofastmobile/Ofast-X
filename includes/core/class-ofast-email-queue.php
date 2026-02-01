<?php
/**
 * Ofast X Email Queue System
 * Handles background email processing with throttling
 * 
 * @package Ofast_X
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Email_Queue {
    
    private static $instance = null;
    
    /**
     * Singleton
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - register cron interval
     */
    public function __construct() {
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
    }
    
    /**
     * Add custom cron interval (5 minutes)
     */
    public function add_cron_interval($schedules) {
        $schedules['ofast_five_minutes'] = array(
            'interval' => 300,
            'display' => __('Every 5 Minutes (Ofast Queue)')
        );
        return $schedules;
    }
    
    /**
     * Initialize queue processor
     */
    public function init() {
        // Create table on activation
        $this->create_queue_table();
        
        // Method 1: WordPress Heartbeat (fires when admin is active)
        add_filter('heartbeat_received', array($this, 'process_queue_heartbeat'), 10, 2);
        
        // Method 2: REST API endpoint (for external cron)
        add_action('rest_api_init', array($this, 'register_rest_endpoint'));
        
        // Method 3: WP-Cron fallback (runs every 5 minutes IF site gets traffic)
        if (!wp_next_scheduled('ofast_process_queue_cron')) {
            wp_schedule_event(time(), 'ofast_five_minutes', 'ofast_process_queue_cron');
        }
        add_action('ofast_process_queue_cron', array($this, 'process_next_emails'));
        
        // Method 4: Frontend processing (10% of page loads)
        add_action('wp_footer', array($this, 'process_queue_frontend'), 999);
        
        // AJAX endpoint for manual trigger
        add_action('wp_ajax_ofast_process_queue', array($this, 'ajax_process_queue'));
        
        // Daily cleanup
        if (!wp_next_scheduled('ofast_daily_queue_cleanup')) {
            wp_schedule_event(time(), 'daily', 'ofast_daily_queue_cleanup');
        }
        add_action('ofast_daily_queue_cleanup', array($this, 'cleanup_old_batches'));
    }
    
    /**
     * Create queue table
     */
    private function create_queue_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_email_queue';
        
        // Check if table already exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            return;
        }
        
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            batch_id VARCHAR(32) UNIQUE NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body LONGTEXT NOT NULL,
            user_ids LONGTEXT NOT NULL COMMENT 'JSON array',
            total_users INT UNSIGNED NOT NULL,
            sent_count INT UNSIGNED DEFAULT 0,
            scheduled_time DATETIME NOT NULL,
            last_processed DATETIME NULL,
            next_allowed_send DATETIME NULL COMMENT 'Throttle control',
            status ENUM('pending','processing','completed','failed','paused') DEFAULT 'pending',
            error_log TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY status_scheduled (status, scheduled_time),
            KEY next_send (next_allowed_send)
        ) {$charset};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
    
    /**
     * Register REST API endpoint for external cron
     */
    public function register_rest_endpoint() {
        register_rest_route('ofast/v1', '/process-queue', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_process_queue'),
            'permission_callback' => array($this, 'verify_cron_secret'),
        ));
    }
    
    /**
     * Verify secret key for external cron access
     */
    public function verify_cron_secret($request) {
        $secret = $request->get_param('secret');
        $stored_secret = get_option('ofast_queue_cron_secret');
        
        // Generate secret on first access
        if (empty($stored_secret)) {
            $stored_secret = wp_generate_password(32, false);
            update_option('ofast_queue_cron_secret', $stored_secret);
        }
        
        return hash_equals($stored_secret, $secret);
    }
    
    /**
     * REST API handler
     */
    public function rest_process_queue($request) {
        $processed = $this->process_next_emails();
        
        return array(
            'success' => true,
            'processed' => $processed,
            'pending' => $this->get_pending_count(),
            'timestamp' => current_time('mysql')
        );
    }
    
    /**
     * Add batch to queue
     * 
     * @param string $subject
     * @param string $body
     * @param array $user_ids
     * @param int $scheduled_time Unix timestamp
     * @return string|false Batch ID or false on failure
     */
    public function add_batch($subject, $body, $user_ids, $scheduled_time = null) {
        global $wpdb;
        
        if (empty($user_ids)) {
            return false;
        }
        
        $scheduled_time = $scheduled_time ?: time();
        $batch_id = md5($subject . serialize($user_ids) . $scheduled_time . wp_rand());
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'ofast_email_queue',
            array(
                'batch_id' => $batch_id,
                'subject' => $subject,
                'body' => $body,
                'user_ids' => wp_json_encode($user_ids),
                'total_users' => count($user_ids),
                'scheduled_time' => date('Y-m-d H:i:s', $scheduled_time),
                'status' => 'pending'
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        
        if ($result) {
            // Clear cache to trigger processing
            delete_transient('ofast_queue_empty');
            return $batch_id;
        }
        
        return false;
    }
    
    /**
     * Process queue (triggered by heartbeat)
     */
    public function process_queue_heartbeat($response, $data) {
        // Only process if not already processing
        if (get_transient('ofast_queue_processing')) {
            return $response;
        }
        
        $processed = $this->process_next_emails();
        
        if ($processed > 0) {
            $response['ofast_queue_processed'] = $processed;
        }
        
        return $response;
    }
    
    /**
     * Process queue (frontend fallback)
     */
    public function process_queue_frontend() {
        // Check if queue has pending items (cached)
        if (get_transient('ofast_queue_empty')) {
            return;
        }
        
        // Don't process on every frontend request (too aggressive)
        if (wp_rand(1, 10) !== 1) {
            return; // Only 10% of page loads process queue
        }
        
        $this->process_next_emails();
    }
    
    /**
     * AJAX manual trigger
     */
    public function ajax_process_queue() {
        check_ajax_referer('ofast_queue_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $processed = $this->process_next_emails();
        
        wp_send_json_success(array(
            'processed' => $processed,
            'pending' => $this->get_pending_count()
        ));
    }
    
    /**
     * Core processing logic
     * 
     * @return int Number of emails sent
     */
    public function process_next_emails() {
        global $wpdb;
        
        // Lock processing (5 minute timeout)
        if (!$this->acquire_lock()) {
            return 0;
        }
        
        // Get throttle settings
        $emails_per_hour = get_option('ofast_email_emails_per_cron', 30);
        $delay_seconds = 3600 / $emails_per_hour;
        
        // Get next batch that's ready to send
        $table = $wpdb->prefix . 'ofast_email_queue';
        $batch = $wpdb->get_row("
            SELECT * FROM {$table}
            WHERE status = 'pending'
            AND scheduled_time <= NOW()
            AND (next_allowed_send IS NULL OR next_allowed_send <= NOW())
            ORDER BY scheduled_time ASC, id ASC
            LIMIT 1
        ");
        
        if (!$batch) {
            set_transient('ofast_queue_empty', true, 60);
            $this->release_lock();
            return 0;
        }
        
        // Get unsent users
        $all_user_ids = json_decode($batch->user_ids, true);
        $remaining_ids = array_slice($all_user_ids, $batch->sent_count);
        
        if (empty($remaining_ids)) {
            // Batch complete
            $wpdb->update(
                $wpdb->prefix . 'ofast_email_queue',
                array('status' => 'completed', 'last_processed' => current_time('mysql')),
                array('id' => $batch->id)
            );
            $this->release_lock();
            return 0;
        }
        
        // Send 1 email (throttled)
        $user_id = $remaining_ids[0];
        $user = get_userdata($user_id);
        
        if (!$user) {
            // User deleted - skip
            $this->increment_sent_count($batch->id, false);
            $this->release_lock();
            return 0;
        }
        
        // Replace placeholders
        $final_body = $this->replace_placeholders($batch->body, $user);
        
        // Get email template
        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-template.php';
        $html = Ofast_X_Email_Template::get_template($final_body);
        
        // Send email
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('ofast_email_from_name', get_bloginfo('name')) . ' <' . get_option('ofast_email_reply_to', get_option('admin_email')) . '>'
        );
        
        $sent = wp_mail($user->user_email, $batch->subject, $html, $headers);
        
        // Update batch progress
        $this->increment_sent_count($batch->id, $sent);
        
        // Set next allowed send time (throttle)
        $next_send = date('Y-m-d H:i:s', time() + $delay_seconds);
        $wpdb->update(
            $wpdb->prefix . 'ofast_email_queue',
            array('next_allowed_send' => $next_send),
            array('id' => $batch->id),
            array('%s'),
            array('%d')
        );
        
        // Check if batch complete
        if (($batch->sent_count + 1) >= $batch->total_users) {
            $wpdb->update(
                $wpdb->prefix . 'ofast_email_queue',
                array('status' => 'completed', 'last_processed' => current_time('mysql')),
                array('id' => $batch->id)
            );
            
            // Log completion
            $this->log_batch_completion($batch);
        }
        
        $this->release_lock();
        
        return $sent ? 1 : 0;
    }
    
    /**
     * Increment sent count
     */
    private function increment_sent_count($batch_id, $success = true) {
        global $wpdb;
        
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ofast_email_queue 
             SET sent_count = sent_count + 1,
                 last_processed = NOW()
             WHERE id = %d",
            $batch_id
        ));
        
        if (!$success) {
            error_log("Ofast Queue: Failed to send email for batch {$batch_id}");
        }
    }
    
    /**
     * Replace placeholders
     */
    private function replace_placeholders($body, $user) {
        return str_replace(
            array('{{user_id}}', '{{username}}', '{{user_display_name}}', '{{user_first_name}}', '{{user_last_name}}', '{{user_email}}'),
            array($user->ID, $user->user_login, $user->display_name, $user->first_name, $user->last_name, $user->user_email),
            $body
        );
    }
    
    /**
     * Log batch completion
     */
    private function log_batch_completion($batch) {
        global $wpdb;
        
        // Check if email logs table exists
        $table = $wpdb->prefix . 'ofast_email_logs';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return;
        }
        
        $wpdb->insert(
            $table,
            array(
                'subject' => $batch->subject,
                'body' => $batch->body,
                'sent_at' => current_time('mysql'),
                'recipient_count' => $batch->sent_count,
                'status' => 'sent',
                'notes' => 'Queue batch completed - ' . $batch->batch_id
            )
        );
    }
    
    /**
     * Acquire processing lock
     */
    private function acquire_lock() {
        if (get_transient('ofast_queue_processing')) {
            return false;
        }
        set_transient('ofast_queue_processing', true, 300);
        return true;
    }
    
    /**
     * Release processing lock
     */
    private function release_lock() {
        delete_transient('ofast_queue_processing');
    }
    
    /**
     * Get pending count
     */
    public function get_pending_count() {
        global $wpdb;
        return (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->prefix}ofast_email_queue 
            WHERE status = 'pending'
        ");
    }
    
    /**
     * Get queue stats
     */
    public function get_queue_stats() {
        global $wpdb;
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'pending' THEN total_users - sent_count ELSE 0 END) as emails_remaining
            FROM {$wpdb->prefix}ofast_email_queue
        ", ARRAY_A);
        
        return $stats;
    }
    
    /**
     * Cleanup old batches (keep 30 days)
     */
    public function cleanup_old_batches() {
        global $wpdb;
        
        $wpdb->query("
            DELETE FROM {$wpdb->prefix}ofast_email_queue 
            WHERE status IN ('completed', 'failed')
            AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
    }
    
    /**
     * Pause batch
     */
    public function pause_batch($batch_id) {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'ofast_email_queue',
            array('status' => 'paused'),
            array('batch_id' => $batch_id),
            array('%s'),
            array('%s')
        );
    }
    
    /**
     * Resume batch
     */
    public function resume_batch($batch_id) {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'ofast_email_queue',
            array('status' => 'pending'),
            array('batch_id' => $batch_id),
            array('%s'),
            array('%s')
        );
    }
    
    /**
     * Delete batch
     */
    public function delete_batch($batch_id) {
        global $wpdb;
        return $wpdb->delete(
            $wpdb->prefix . 'ofast_email_queue',
            array('batch_id' => $batch_id),
            array('%s')
        );
    }
}
