<?php
/**
 * Ofast X Email Main Controller
 * Loads all email module components
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Email {
    
    /**
     * Initialize email module
     */
    public function init() {
        // Load and initialize admin interface
        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-admin.php';
        $email_admin = new Ofast_X_Email_Admin();
        $email_admin->init();
        
        error_log('Ofast-X: Email module initialized successfully');
    }
}