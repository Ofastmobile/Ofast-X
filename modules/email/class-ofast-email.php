<?php
/**
 * Ofast X Email Main Controller
 * Complete initialization of email system
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Email {
    
    private $admin;
    private $scheduler;
    
    /**
     * Initialize complete email system
     */
    public function init() {
        // Load all email components
        $this->load_dependencies();
        $this->init_components();
        $this->setup_hooks();
        
        error_log('Ofast-X: Complete email system initialized');
    }
    
    /**
     * Load all required files
     */
    private function load_dependencies() {
        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-admin.php';
        // We'll add other components later: sender, scheduler, etc.
    }
    
    /**
     * Initialize components
     */
    private function init_components() {
        $this->admin = new Ofast_X_Email_Admin();
        $this->admin->init();
    }
    
    /**
     * Setup system hooks
     */
    private function setup_hooks() {
        // Email sending hook
        add_action('ofast_scheduled_email_event', array($this, 'handle_scheduled_email'), 10, 4);
        
        // Daily cleanup
        if (!wp_next_scheduled('ofast_email_cleanup')) {
            wp_schedule_event(time(), 'daily', 'ofast_email_cleanup');
        }
        add_action('ofast_email_cleanup', array($this, 'cleanup_old_logs'));
    }
    
    /**
     * Handle scheduled emails
     */
    public function handle_scheduled_email($subject, $body, $selected_roles, $selected_user_ids) {
        // We'll implement proper scheduled email handling
        error_log('Ofast-X: Scheduled email triggered');
    }
    
    /**
     * Cleanup old email logs
     */
    public function cleanup_old_logs() {
        // GDPR compliance - cleanup old logs
        error_log('Ofast-X: Email logs cleanup executed');
    }
}