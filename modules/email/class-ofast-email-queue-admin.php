<?php
/**
 * Ofast X Email Queue Admin Interface
 * Manage queued email batches
 * 
 * @package Ofast_X
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Email_Queue_Admin {
    
    /**
     * Initialize
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'handle_actions'));
    }
    
    /**
     * Add menu page
     * NOTE: Disabled - Queue is now a tab in the main Emailer page
     */
    public function add_menu() {
        // Submenu disabled - Queue is now integrated as a tab in the main Ofast Emailer page
        // add_submenu_page(
        //     'ofast-emailer',
        //     'Email Queue',
        //     'Queue',
        //     'manage_options',
        //     'ofast-email-queue',
        //     array($this, 'render_page')
        // );
    }
    
    /**
     * Handle pause/resume/delete actions
     */
    public function handle_actions() {
        if (!isset($_GET['page']) || !in_array($_GET['page'], array('ofast-email-queue', 'ofast-emailer'))) {
            return;
        }
        
        if (!isset($_GET['action']) || !isset($_GET['batch_id'])) {
            return;
        }
        
        if (!wp_verify_nonce($_GET['_wpnonce'], 'ofast_queue_action')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        require_once OFAST_X_PLUGIN_DIR . 'includes/core/class-ofast-email-queue.php';
        $queue = Ofast_X_Email_Queue::get_instance();
        $batch_id = sanitize_text_field($_GET['batch_id']);
        $action = sanitize_text_field($_GET['action']);
        
        switch ($action) {
            case 'pause':
                $queue->pause_batch($batch_id);
                $message = 'Batch paused';
                break;
            case 'resume':
                $queue->resume_batch($batch_id);
                $message = 'Batch resumed';
                break;
            case 'delete':
                $queue->delete_batch($batch_id);
                $message = 'Batch deleted';
                break;
            default:
                $message = '';
        }
        
        if ($message) {
            wp_redirect(add_query_arg('queue_message', urlencode($message), admin_url('admin.php?page=ofast-emailer&tab=queue')));
            exit;
        }
    }
    
    /**
     * Render page
     */
    public function render_page() {
        require_once OFAST_X_PLUGIN_DIR . 'includes/core/class-ofast-email-queue.php';
        $queue = Ofast_X_Email_Queue::get_instance();
        $stats = $queue->get_queue_stats();
        
        global $wpdb;
        $batches = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}ofast_email_queue
            ORDER BY 
                CASE status
                    WHEN 'processing' THEN 1
                    WHEN 'pending' THEN 2
                    WHEN 'paused' THEN 3
                    WHEN 'completed' THEN 4
                    WHEN 'failed' THEN 5
                END,
                scheduled_time ASC
            LIMIT 100
        ");
        
        $emails_per_hour = get_option('ofast_email_emails_per_cron', 30);
        $delay = 3600 / $emails_per_hour;
        
        if (isset($_GET['queue_message'])) {
            echo Ofast_X_Toast::render(urldecode($_GET['queue_message']), 'success');
        }
        ?>
        
        <style>
            .ofast-queue-wrap { max-width: 1400px; }
            
            /* Header Styles */
            .ofast-header {
                display: flex;
                align-items: center;
                gap: 20px;
                background: #fff;
                padding: 25px 30px;
                border-radius: 12px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                margin-bottom: 30px;
            }
            .ofast-header-icon {
                width: 56px;
                height: 56px;
                background: #fff;
                border: 1px solid #e2e8f0;
                box-shadow: 0 2px 4px rgba(0,0,0,0.02);
                border-radius: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .ofast-header-icon .dashicons {
                font-size: 28px;
                width: 28px;
                height: 28px;
                color: #6366f1;
            }
            
            .ofast-stats-grid { 
                display: grid; 
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
                gap: 16px; 
                margin-bottom: 24px; 
            }
            .ofast-stat-card { 
                background: #fff; 
                padding: 24px; 
                border-radius: 12px; 
                border: 1px solid #e5e7eb; 
                text-align: center;
                transition: all 0.2s ease;
            }
            .ofast-stat-card:hover { 
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); 
                transform: translateY(-2px); 
            }
            .ofast-stat-value { font-size: 36px; font-weight: 700; line-height: 1.2; }
            .ofast-stat-label { color: #64748b; font-size: 13px; font-weight: 500; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.5px; }
            
            .ofast-queue-table { 
                background: #fff; 
                border-radius: 12px; 
                border: 1px solid #e5e7eb; 
                overflow: hidden; 
            }
            .ofast-queue-table table { border: none; }
            .ofast-queue-table th { 
                background: #f8fafc; 
                font-weight: 600; 
                color: #475569; 
                font-size: 12px; 
                text-transform: uppercase; 
                letter-spacing: 0.5px;
                padding: 14px 16px;
            }
            .ofast-queue-table td { padding: 14px 16px; vertical-align: middle; }
            
            .ofast-progress-bar { 
                background: #e5e7eb; 
                border-radius: 6px; 
                overflow: hidden; 
                height: 8px; 
            }
            .ofast-progress-fill { 
                height: 100%; 
                transition: width 0.3s ease; 
                border-radius: 6px;
            }
            .ofast-progress-text { font-size: 12px; color: #64748b; margin-top: 4px; }
            
            .ofast-status-badge { 
                display: inline-block; 
                padding: 5px 10px; 
                border-radius: 6px; 
                font-size: 11px; 
                font-weight: 600; 
                text-transform: uppercase; 
                letter-spacing: 0.3px;
            }
            
            .ofast-action-btn { 
                display: inline-flex; 
                align-items: center; 
                gap: 4px; 
                padding: 6px 12px; 
                border-radius: 6px; 
                font-size: 12px; 
                font-weight: 500; 
                text-decoration: none; 
                border: 1px solid #e5e7eb;
                background: #fff;
                color: #475569;
                transition: all 0.15s ease;
            }
            .ofast-action-btn:hover { 
                background: #f8fafc; 
                border-color: #cbd5e1;
                color: #1e293b;
            }
            .ofast-action-btn .dashicons { font-size: 14px; width: 14px; height: 14px; }
            
            .ofast-cron-card { 
                background: #fff; 
                padding: 24px; 
                margin-bottom: 24px; 
                border-radius: 12px; 
                border: 1px solid #e5e7eb; 
            }
            .ofast-cron-card h3 { 
                margin: 0 0 16px 0; 
                font-size: 16px; 
                font-weight: 600; 
                color: #1e293b;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .ofast-cron-card h3 .dashicons { color: #6366f1; }
            
            .ofast-alert { 
                padding: 16px 20px; 
                border-radius: 12px; 
                margin-bottom: 16px; 
                display: flex; 
                align-items: flex-start; 
                gap: 14px;
                backdrop-filter: blur(8px);
                -webkit-backdrop-filter: blur(8px);
            }
            .ofast-alert-warning { 
                background: linear-gradient(135deg, rgba(251, 191, 36, 0.08) 0%, rgba(245, 158, 11, 0.12) 100%);
                border: 1px solid rgba(245, 158, 11, 0.2);
            }
            .ofast-alert-success { 
                background: linear-gradient(135deg, rgba(16, 185, 129, 0.08) 0%, rgba(5, 150, 105, 0.12) 100%);
                border: 1px solid rgba(16, 185, 129, 0.2);
            }
            .ofast-alert-info { 
                background: linear-gradient(135deg, rgba(99, 102, 241, 0.08) 0%, rgba(79, 70, 229, 0.12) 100%);
                border: 1px solid rgba(99, 102, 241, 0.2);
            }
            .ofast-alert .dashicons { 
                flex-shrink: 0; 
                margin-top: 2px;
                width: 20px;
                height: 20px;
                font-size: 20px;
            }
            .ofast-alert-warning .dashicons { color: #b45309; }
            .ofast-alert-success .dashicons { color: #047857; }
            .ofast-alert-info .dashicons { color: #4f46e5; }
            .ofast-alert-content { flex: 1; }
            .ofast-alert-content strong { display: block; margin-bottom: 4px; color: #1e293b; }
            .ofast-alert-content p { margin: 0; font-size: 14px; color: #475569; }
            
            .ofast-accordion { margin-bottom: 12px; }
            .ofast-accordion-header { 
                cursor: pointer; 
                font-weight: 600; 
                padding: 14px 16px; 
                background: #f8fafc; 
                border-radius: 8px; 
                display: flex;
                align-items: center;
                gap: 10px;
                transition: background 0.15s ease;
            }
            .ofast-accordion-header:hover { background: #f1f5f9; }
            .ofast-accordion-header .dashicons { color: #64748b; transition: transform 0.2s ease; }
            .ofast-accordion[open] .ofast-accordion-header .dashicons { transform: rotate(90deg); }
            .ofast-accordion-content { 
                padding: 16px; 
                background: #f8fafc; 
                margin-top: 8px; 
                border-radius: 8px; 
            }
            
            .ofast-code-block { 
                background: #1e293b; 
                color: #10b981; 
                padding: 14px 16px; 
                border-radius: 8px; 
                margin: 12px 0; 
                font-family: ui-monospace, monospace; 
                font-size: 13px; 
                overflow-x: auto; 
            }
            
            .ofast-empty-state { 
                text-align: center; 
                padding: 60px 20px; 
                color: #64748b; 
            }
            .ofast-empty-state .dashicons { 
                font-size: 48px; 
                width: 48px; 
                height: 48px; 
                color: #cbd5e1;
                margin-bottom: 16px;
            }
        </style>
        
        <div class="wrap ofast-queue-wrap">
            <!-- Header -->
            <div class="ofast-header" style="margin-top: 20px;">
                <div class="ofast-header-icon">
                    <span class="dashicons dashicons-list-view"></span>
                </div>
                <div class="ofast-header-content">
                    <h1 style="margin: 0 0 5px 0; font-size: 24px; font-weight: 700; color: #1e293b; padding: 0;">Email Queue</h1>
                    <p style="margin: 0; color: #64748b; font-size: 14px;">Background email processing with throttle control (<?php echo $emails_per_hour; ?> emails/hour = 1 email every <?php echo round($delay); ?> seconds)</p>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="ofast-stats-grid">
                <div class="ofast-stat-card">
                    <div class="ofast-stat-value" style="color: #f59e0b;"><?php echo number_format($stats['pending'] ?? 0); ?></div>
                    <div class="ofast-stat-label">Pending Batches</div>
                </div>
                <div class="ofast-stat-card">
                    <div class="ofast-stat-value" style="color: #ef4444;"><?php echo number_format($stats['emails_remaining'] ?? 0); ?></div>
                    <div class="ofast-stat-label">Emails Remaining</div>
                </div>
                <div class="ofast-stat-card">
                    <div class="ofast-stat-value" style="color: #10b981;"><?php echo number_format($stats['completed'] ?? 0); ?></div>
                    <div class="ofast-stat-label">Completed Batches</div>
                </div>
                <div class="ofast-stat-card">
                    <div class="ofast-stat-value" style="color: #6366f1;"><?php echo $emails_per_hour; ?>/hr</div>
                    <div class="ofast-stat-label">Send Rate</div>
                </div>
            </div>
            
            <!-- Server Cron Setup Instructions -->
            <?php $this->render_cron_setup_instructions(); ?>
            
            <!-- Queue Table -->
            <div class="ofast-queue-table">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 50px;">ID</th>
                            <th>Subject</th>
                            <th style="width: 120px;">Progress</th>
                            <th style="width: 90px;">Status</th>
                            <th style="width: 150px;">Scheduled</th>
                            <th style="width: 100px;">Next Send</th>
                            <th style="width: 160px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($batches)): ?>
                            <tr>
                                <td colspan="7">
                                    <div class="ofast-empty-state">
                                        <span class="dashicons dashicons-email-alt"></span>
                                        <p>No batches in queue. Send a bulk email to see it here.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($batches as $batch): 
                                $progress = ($batch->total_users > 0) ? round(($batch->sent_count / $batch->total_users) * 100) : 0;
                                $status_colors = array(
                                    'pending' => '#f59e0b',
                                    'processing' => '#3b82f6',
                                    'completed' => '#10b981',
                                    'failed' => '#ef4444',
                                    'paused' => '#6b7280'
                                );
                                $color = $status_colors[$batch->status] ?? '#6b7280';
                            ?>
                            <tr>
                                <td><?php echo $batch->id; ?></td>
                                <td><strong><?php echo esc_html(wp_trim_words($batch->subject, 8)); ?></strong></td>
                                <td>
                                    <div class="ofast-progress-bar">
                                        <div class="ofast-progress-fill" style="background: <?php echo $color; ?>; width: <?php echo $progress; ?>%;"></div>
                                    </div>
                                    <div class="ofast-progress-text"><?php echo $batch->sent_count; ?>/<?php echo $batch->total_users; ?></div>
                                </td>
                                <td>
                                    <span class="ofast-status-badge" style="background: <?php echo $color; ?>15; color: <?php echo $color; ?>;">
                                        <?php echo strtoupper($batch->status); ?>
                                    </span>
                                </td>
                                <td style="font-size: 13px; color: #64748b;"><?php echo esc_html($batch->scheduled_time); ?></td>
                                <td>
                                    <?php if ($batch->next_allowed_send && $batch->status === 'pending'): ?>
                                        <?php 
                                        $next = strtotime($batch->next_allowed_send);
                                        $now = time();
                                        if ($next > $now) {
                                            echo '<span style="color: #f59e0b; font-weight: 500;">' . human_time_diff($now, $next) . '</span>';
                                        } else {
                                            echo '<span style="color: #10b981; font-weight: 500;">Ready</span>';
                                        }
                                        ?>
                                    <?php else: ?>
                                        <span style="color: #cbd5e1;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($batch->status === 'pending'): ?>
                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ofast-emailer&tab=queue&action=pause&batch_id=' . $batch->batch_id), 'ofast_queue_action'); ?>" class="ofast-action-btn">
                                            <span class="dashicons dashicons-controls-pause"></span> Pause
                                        </a>
                                    <?php elseif ($batch->status === 'paused'): ?>
                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ofast-emailer&tab=queue&action=resume&batch_id=' . $batch->batch_id), 'ofast_queue_action'); ?>" class="ofast-action-btn">
                                            <span class="dashicons dashicons-controls-play"></span> Resume
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($batch->status, array('completed', 'failed', 'paused'))): ?>
                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ofast-emailer&tab=queue&action=delete&batch_id=' . $batch->batch_id), 'ofast_queue_action'); ?>" class="ofast-action-btn" onclick="return confirm('Delete this batch?')" style="color: #ef4444;">
                                            <span class="dashicons dashicons-trash"></span> Delete
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Render page content only (for tabbed view)
     */
    public function render_content_only() {
        require_once OFAST_X_PLUGIN_DIR . 'includes/core/class-ofast-email-queue.php';
        $queue = Ofast_X_Email_Queue::get_instance();
        $stats = $queue->get_queue_stats();
        
        global $wpdb;
        $batches = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}ofast_email_queue
            ORDER BY 
                CASE status
                    WHEN 'processing' THEN 1
                    WHEN 'pending' THEN 2
                    WHEN 'paused' THEN 3
                    WHEN 'completed' THEN 4
                    WHEN 'failed' THEN 5
                END,
                scheduled_time ASC
            LIMIT 100
        ");
        
        $emails_per_hour = get_option('ofast_email_emails_per_cron', 30);
        $delay = 3600 / $emails_per_hour;
        
        if (isset($_GET['queue_message'])) {
            echo Ofast_X_Toast::render(urldecode($_GET['queue_message']), 'success');
        }
        ?>
        
        <style>
            .ofast-queue-wrap { max-width: 1400px; }
            
            .ofast-stats-grid { 
                display: grid; 
                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); 
                gap: 16px; 
                margin-bottom: 24px; 
            }
            .ofast-stat-card { 
                background: #fff; 
                padding: 24px; 
                border-radius: 12px; 
                border: 1px solid #e5e7eb; 
                text-align: center;
                transition: all 0.2s ease;
            }
            .ofast-stat-card:hover { 
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); 
                transform: translateY(-2px); 
            }
            .ofast-stat-value { font-size: 36px; font-weight: 700; line-height: 1.2; }
            .ofast-stat-label { color: #64748b; font-size: 13px; font-weight: 500; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.5px; }
            
            .ofast-queue-table { 
                background: #fff; 
                border-radius: 12px; 
                border: 1px solid #e5e7eb; 
                overflow-x: auto; 
            }
            .ofast-queue-table table { border: none; min-width: 800px !important; table-layout: auto !important; }
            .ofast-queue-table th { 
                background: #f8fafc; 
                font-weight: 600; 
                color: #475569; 
                font-size: 12px; 
                text-transform: uppercase; 
                letter-spacing: 0.5px;
                padding: 14px 16px;
                white-space: nowrap;
            }
            .ofast-queue-table td { padding: 14px 16px; vertical-align: middle; }
            
            .ofast-progress-bar { 
                background: #e5e7eb; 
                border-radius: 6px; 
                overflow: hidden; 
                height: 8px; 
            }
            .ofast-progress-fill { 
                height: 100%; 
                transition: width 0.3s ease; 
                border-radius: 6px;
            }
            .ofast-progress-text { font-size: 12px; color: #64748b; margin-top: 4px; }
            
            .ofast-status-badge { 
                display: inline-block; 
                padding: 5px 10px; 
                border-radius: 6px; 
                font-size: 11px; 
                font-weight: 600; 
                text-transform: uppercase; 
                letter-spacing: 0.3px;
            }
            
            .ofast-action-btn { 
                display: inline-flex; 
                align-items: center; 
                gap: 4px; 
                padding: 6px 12px; 
                border-radius: 6px; 
                font-size: 12px; 
                font-weight: 500; 
                text-decoration: none; 
                border: 1px solid #e5e7eb;
                background: #fff;
                color: #475569;
                transition: all 0.15s ease;
            }
            .ofast-action-btn:hover { 
                background: #f8fafc; 
                border-color: #cbd5e1;
                color: #1e293b;
            }
            .ofast-action-btn .dashicons { font-size: 14px; width: 14px; height: 14px; }
            
            .ofast-cron-card { 
                background: #fff; 
                padding: 24px; 
                margin-bottom: 24px; 
                border-radius: 12px; 
                border: 1px solid #e5e7eb; 
            }
            .ofast-cron-card h3 { 
                margin: 0 0 16px 0; 
                font-size: 16px; 
                font-weight: 600; 
                color: #1e293b;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .ofast-cron-card h3 .dashicons { color: #6366f1; }
            
            .ofast-alert { 
                padding: 16px 20px; 
                border-radius: 12px; 
                margin-bottom: 16px; 
                display: flex; 
                align-items: flex-start; 
                gap: 14px;
                backdrop-filter: blur(8px);
                -webkit-backdrop-filter: blur(8px);
            }
            .ofast-alert-warning { 
                background: transparent;
                border: 1px solid rgba(245, 158, 11, 0.2);
            }
            .ofast-alert-success { 
                background: transparent;
                border: 1px solid rgba(16, 185, 129, 0.2);
            }
            .ofast-alert-info { 
                background: transparent;
                border: 1px solid rgba(99, 102, 241, 0.2);
            }
            .ofast-alert .dashicons { 
                flex-shrink: 0; 
                margin-top: 2px;
                width: 20px;
                height: 20px;
                font-size: 20px;
            }
            .ofast-alert-warning .dashicons { color: #b45309; }
            .ofast-alert-success .dashicons { color: #047857; }
            .ofast-alert-info .dashicons { color: #4f46e5; }
            .ofast-alert-content { flex: 1; }
            .ofast-alert-content strong { display: block; margin-bottom: 4px; color: #1e293b; }
            .ofast-alert-content p { margin: 0; font-size: 14px; color: #475569; }
            
            .ofast-accordion { margin-bottom: 12px; }
            .ofast-accordion-header { 
                cursor: pointer; 
                font-weight: 600; 
                padding: 14px 16px; 
                background: #f8fafc; 
                border-radius: 8px; 
                display: flex;
                align-items: center;
                gap: 10px;
                transition: background 0.15s ease;
            }
            .ofast-accordion-header:hover { background: #f1f5f9; }
            .ofast-accordion-header .dashicons { color: #64748b; transition: transform 0.2s ease; }
            .ofast-accordion[open] .ofast-accordion-header .dashicons { transform: rotate(90deg); }
            .ofast-accordion-content { 
                padding: 16px; 
                background: #f8fafc; 
                margin-top: 8px; 
                border-radius: 8px; 
            }
            
            .ofast-code-block { 
                background: #1e293b; 
                color: #10b981; 
                padding: 14px 16px; 
                border-radius: 8px; 
                margin: 12px 0; 
                font-family: ui-monospace, monospace; 
                font-size: 13px; 
                overflow-x: auto; 
            }
            
            .ofast-empty-state { 
                text-align: center; 
                padding: 60px 20px; 
                color: #64748b; 
            }
            .ofast-empty-state .dashicons { 
                font-size: 48px; 
                width: 48px; 
                height: 48px; 
                color: #cbd5e1;
                margin-bottom: 16px;
            }
        </style>
        
        <div class="ofast-queue-wrap">
            <!-- Stats Cards -->
            <div class="ofast-stats-grid">
                <div class="ofast-stat-card">
                    <div class="ofast-stat-value" style="color: #f59e0b;"><?php echo number_format($stats['pending'] ?? 0); ?></div>
                    <div class="ofast-stat-label">Pending Batches</div>
                </div>
                <div class="ofast-stat-card">
                    <div class="ofast-stat-value" style="color: #ef4444;"><?php echo number_format($stats['emails_remaining'] ?? 0); ?></div>
                    <div class="ofast-stat-label">Emails Remaining</div>
                </div>
                <div class="ofast-stat-card">
                    <div class="ofast-stat-value" style="color: #10b981;"><?php echo number_format($stats['completed'] ?? 0); ?></div>
                    <div class="ofast-stat-label">Completed Batches</div>
                </div>
                <div class="ofast-stat-card">
                    <div class="ofast-stat-value" style="color: #6366f1;"><?php echo $emails_per_hour; ?>/hr</div>
                    <div class="ofast-stat-label">Send Rate</div>
                </div>
            </div>
            
            <!-- Server Cron Setup Instructions -->
            <?php $this->render_cron_setup_instructions(); ?>
            
            <!-- Queue Table -->
            <div class="ofast-queue-table">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 50px;">ID</th>
                            <th>Subject</th>
                            <th style="width: 120px;">Progress</th>
                            <th style="width: 90px;">Status</th>
                            <th style="width: 150px;">Scheduled</th>
                            <th style="width: 100px;">Next Send</th>
                            <th style="width: 160px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($batches)): ?>
                            <tr>
                                <td colspan="7">
                                    <div class="ofast-empty-state">
                                        <span class="dashicons dashicons-email-alt"></span>
                                        <p>No batches in queue. Send a bulk email to see it here.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($batches as $batch): 
                                $progress = ($batch->total_users > 0) ? round(($batch->sent_count / $batch->total_users) * 100) : 0;
                                $status_colors = array(
                                    'pending' => '#f59e0b',
                                    'processing' => '#3b82f6',
                                    'completed' => '#10b981',
                                    'failed' => '#ef4444',
                                    'paused' => '#6b7280'
                                );
                                $color = $status_colors[$batch->status] ?? '#6b7280';
                            ?>
                            <tr>
                                <td><?php echo $batch->id; ?></td>
                                <td><strong><?php echo esc_html(wp_trim_words($batch->subject, 8)); ?></strong></td>
                                <td>
                                    <div class="ofast-progress-bar">
                                        <div class="ofast-progress-fill" style="background: <?php echo $color; ?>; width: <?php echo $progress; ?>%;"></div>
                                    </div>
                                    <div class="ofast-progress-text"><?php echo $batch->sent_count; ?>/<?php echo $batch->total_users; ?></div>
                                </td>
                                <td>
                                    <span class="ofast-status-badge" style="background: <?php echo $color; ?>15; color: <?php echo $color; ?>;">
                                        <?php echo strtoupper($batch->status); ?>
                                    </span>
                                </td>
                                <td style="font-size: 13px; color: #64748b;"><?php echo esc_html($batch->scheduled_time); ?></td>
                                <td>
                                    <?php if ($batch->next_allowed_send && $batch->status === 'pending'): ?>
                                        <?php 
                                        $next = strtotime($batch->next_allowed_send);
                                        $now = time();
                                        if ($next > $now) {
                                            echo '<span style="color: #f59e0b; font-weight: 500;">' . human_time_diff($now, $next) . '</span>';
                                        } else {
                                            echo '<span style="color: #10b981; font-weight: 500;">Ready</span>';
                                        }
                                        ?>
                                    <?php else: ?>
                                        <span style="color: #cbd5e1;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($batch->status === 'pending'): ?>
                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ofast-emailer&tab=queue&action=pause&batch_id=' . $batch->batch_id), 'ofast_queue_action'); ?>" class="ofast-action-btn">
                                            <span class="dashicons dashicons-controls-pause"></span> Pause
                                        </a>
                                    <?php elseif ($batch->status === 'paused'): ?>
                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ofast-emailer&tab=queue&action=resume&batch_id=' . $batch->batch_id), 'ofast_queue_action'); ?>" class="ofast-action-btn">
                                            <span class="dashicons dashicons-controls-play"></span> Resume
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($batch->status, array('completed', 'failed', 'paused'))): ?>
                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ofast-emailer&tab=queue&action=delete&batch_id=' . $batch->batch_id), 'ofast_queue_action'); ?>" class="ofast-action-btn" onclick="return confirm('Delete this batch?')" style="color: #ef4444;">
                                            <span class="dashicons dashicons-trash"></span> Delete
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
    private function render_cron_setup_instructions() {
        $secret = get_option('ofast_queue_cron_secret');
        if (empty($secret)) {
            $secret = wp_generate_password(32, false);
            update_option('ofast_queue_cron_secret', $secret);
        }
        
        $cron_url = rest_url('ofast/v1/process-queue') . '?secret=' . $secret;
        ?>
        
        <div class="ofast-cron-card">
            <h3><span class="dashicons dashicons-clock"></span> Server Cron Setup (Recommended)</h3>
            
            <div class="ofast-alert ofast-alert-warning">
                <span class="dashicons dashicons-warning"></span>
                <div class="ofast-alert-content">
                    <strong>Important for Large Batches (500+ emails)</strong>
                    <p>WordPress heartbeat only processes queue when admin is logged in. For reliable 24/7 processing, set up a server cron job.</p>
                </div>
            </div>
            
            <details class="ofast-accordion">
                <summary class="ofast-accordion-header">
                    <span class="dashicons dashicons-arrow-right-alt2"></span>
                    cPanel / Shared Hosting Setup
                </summary>
                <div class="ofast-accordion-content">
                    <ol style="margin: 0; padding-left: 20px; line-height: 1.8;">
                        <li>Log in to cPanel</li>
                        <li>Go to <strong>Advanced → Cron Jobs</strong></li>
                        <li>Add new cron job:
                            <div class="ofast-code-block">*/5 * * * * curl -s "<?php echo esc_url($cron_url); ?>" > /dev/null 2>&1</div>
                        </li>
                        <li>Click <strong>Add New Cron Job</strong></li>
                    </ol>
                    <p style="margin: 12px 0 0; color: #64748b; font-size: 13px;">
                        This runs every 5 minutes and processes emails at your configured throttle rate.
                    </p>
                </div>
            </details>
            
            <details class="ofast-accordion">
                <summary class="ofast-accordion-header">
                    <span class="dashicons dashicons-arrow-right-alt2"></span>
                    VPS / Dedicated Server Setup
                </summary>
                <div class="ofast-accordion-content">
                    <p style="margin: 0 0 10px;">SSH into your server and edit crontab:</p>
                    <div class="ofast-code-block">crontab -e</div>
                    <p style="margin: 10px 0;">Add this line:</p>
                    <div class="ofast-code-block">*/5 * * * * curl -s "<?php echo esc_url($cron_url); ?>" > /dev/null 2>&1</div>
                </div>
            </details>
            
            <details class="ofast-accordion">
                <summary class="ofast-accordion-header">
                    <span class="dashicons dashicons-arrow-right-alt2"></span>
                    Alternative: EasyCron / Cron-Job.org (Free Services)
                </summary>
                <div class="ofast-accordion-content">
                    <p style="margin: 0 0 10px;">If your host doesn't support cron jobs:</p>
                    <ol style="margin: 0; padding-left: 20px; line-height: 1.8;">
                        <li>Sign up at <a href="https://www.easycron.com" target="_blank">EasyCron.com</a> (free tier available)</li>
                        <li>Create new cron job</li>
                        <li>URL: <code style="background: #e5e7eb; padding: 2px 6px; border-radius: 4px; font-size: 12px; word-break: break-all;"><?php echo esc_url($cron_url); ?></code></li>
                        <li>Frequency: Every 5 minutes</li>
                        <li>Save</li>
                    </ol>
                </div>
            </details>
            
            <div class="ofast-alert ofast-alert-success" style="margin-top: 16px; margin-bottom: 0;">
                <span class="dashicons dashicons-yes-alt"></span>
                <div class="ofast-alert-content">
                    <strong>Test Your Cron Setup</strong>
                    <p>Visit this URL in your browser (it should return JSON):</p>
                    <div style="background: #fff; padding: 10px; border-radius: 6px; margin-top: 8px; word-break: break-all; font-size: 12px;">
                        <a href="<?php echo esc_url($cron_url); ?>" target="_blank"><?php echo esc_url($cron_url); ?></a>
                    </div>
                </div>
            </div>
            
            <div class="ofast-alert ofast-alert-info" style="margin-top: 12px; margin-bottom: 0;">
                <span class="dashicons dashicons-lock"></span>
                <div class="ofast-alert-content">
                    <strong>Security Note</strong>
                    <p>The <code>secret</code> parameter prevents unauthorized access. Keep this URL private.</p>
                </div>
            </div>
        </div>
        <?php
    }
}
