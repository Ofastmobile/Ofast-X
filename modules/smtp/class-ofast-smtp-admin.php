<?php

/**
 * Ofast X SMTP Admin Interface
 * Settings page for SMTP configuration
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_SMTP_Admin
{
    /**
     * Initialize admin interface
     */
    public function init()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_save'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_head', array($this, 'admin_head_styles'));
    }

    /**
     * Output critical CSS in head (WooCommerce-style) - loads before body
     */
    public function admin_head_styles()
    {
        // Match by page slug instead of exact screen ID for compatibility
        // across different admin parent menu slugs.
        if (!isset($_GET['page']) || sanitize_key($_GET['page']) !== 'ofast-smtp') {
            return;
        }
        ?>
        <style id="ofast-smtp-critical-css">
            /* Tab Navigation - WooCommerce style with sticky + glassmorphism */
            .ofast-tabs-nav {
                display: flex;
                flex-wrap: nowrap;
                gap: 8px;
                margin-bottom: 25px;
                padding: 10px 12px;
                background: rgba(241, 245, 249, 0.85);
                backdrop-filter: blur(12px);
                -webkit-backdrop-filter: blur(12px);
                border-radius: 12px;
                border: 1px solid rgba(255, 255, 255, 0.5);
                position: sticky;
                top: 47px;
                z-index: 100;
                box-shadow: 0 4px 20px rgba(0,0,0,0.08), 0 1px 3px rgba(0,0,0,0.05);
            }
            @media (max-width: 782px) {
                .ofast-tabs-nav {
                    position: sticky;
                    top: 61px;
                    overflow-x: auto;
                    -webkit-overflow-scrolling: touch;
                }
            }
            .ofast-tab {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 12px 20px;
                background: transparent;
                border: none;
                border-radius: 8px;
                color: #64748b;
                font-size: 14px;
                font-weight: 500;
                text-decoration: none;
                cursor: pointer;
                transition: all 0.2s ease;
                flex-shrink: 0;
                white-space: nowrap;
            }
            .ofast-tab:hover {
                background: #fff;
                color: #1e293b;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            }
            .ofast-tab.active {
                background: linear-gradient(135deg, #6366f1 0%, #764ba2 100%);
                color: #fff;
                box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
            }
            .ofast-tab .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
                line-height: 16px;
            }
            .ofast-tab-content { display: none; }
            .ofast-tab-content.active { display: block; }

            /* Layout helpers used by dashboard cards/chart */
            .ofast-grid-3 {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 15px;
            }
            .ofast-grid-4 {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 15px;
            }
            .ofast-flex-layout {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 15px;
                align-items: stretch;
            }
            .ofast-main {
                min-width: 0;
            }
            @media (max-width: 1200px) {
                .ofast-grid-4 {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
                .ofast-flex-layout {
                    grid-template-columns: 1fr;
                }
            }
            @media (max-width: 782px) {
                .ofast-grid-3,
                .ofast-grid-4,
                .ofast-flex-layout {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <?php
    }

    /**
     * Add admin menu - Submenu under Ofast X with tabs
     */
    public function add_admin_menu()
    {
        // Add as submenu under Ofast X
        add_submenu_page(
            'ofast-dashboard',
            'SMTP',
            'SMTP',
            'manage_options',
            'ofast-smtp',
            array($this, 'render_tabbed_page')
        );
    }

    /**
     * Render tabbed page with modern JS tabs (no reload)
     */
    public function render_tabbed_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // CSS already loaded in admin_head for instant rendering

        $default_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        ?>
        <div class="wrap">
            <h1>SMTP</h1>
            
            <!-- Modern Tabs Navigation (sticky on scroll) -->
            <nav class="ofast-tabs-nav" id="smtp-tabs-nav">
                <a href="#" class="ofast-tab <?php echo $default_tab === 'dashboard' ? 'active' : ''; ?>" data-tab="dashboard">
                    <span class="dashicons dashicons-chart-area"></span>
                    Dashboard
                </a>
                <a href="#" class="ofast-tab <?php echo $default_tab === 'log' ? 'active' : ''; ?>" data-tab="log">
                    <span class="dashicons dashicons-list-view"></span>
                    Email Log
                </a>
                <a href="#" class="ofast-tab <?php echo $default_tab === 'settings' ? 'active' : ''; ?>" data-tab="settings">
                    <span class="dashicons dashicons-admin-settings"></span>
                    Settings
                </a>
            </nav>

            <!-- Tab Content Panels -->
            <div id="smtp-tab-dashboard" class="ofast-tab-content<?php echo $default_tab === 'dashboard' ? ' active' : ''; ?>" style="<?php echo $default_tab !== 'dashboard' ? 'display:none;' : ''; ?>">
                <?php $this->render_dashboard_page_content(); ?>
            </div>

            <div id="smtp-tab-log" class="ofast-tab-content<?php echo $default_tab === 'log' ? ' active' : ''; ?>" style="<?php echo $default_tab !== 'log' ? 'display:none;' : ''; ?>">
                <?php $this->render_log_page_content(); ?>
            </div>

            <div id="smtp-tab-settings" class="ofast-tab-content<?php echo $default_tab === 'settings' ? ' active' : ''; ?>" style="<?php echo $default_tab !== 'settings' ? 'display:none;' : ''; ?>">
                <?php $this->render_settings_page_content(); ?>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Tab switching without page reload
            $('#smtp-tabs-nav .ofast-tab').on('click', function(e) {
                e.preventDefault();
                
                var tabId = $(this).data('tab');
                
                // Update active tab
                $('#smtp-tabs-nav .ofast-tab').removeClass('active');
                $(this).addClass('active');
                
                // Show/hide content with active class
                $('.ofast-tab-content').removeClass('active').hide();
                $('#smtp-tab-' + tabId).addClass('active').show();
                
                // Update URL without reload (for bookmarking)
                if (history.pushState) {
                    var url = new URL(window.location);
                    url.searchParams.set('tab', tabId);
                    history.pushState({tab: tabId}, '', url);
                }
            });
            
            // Handle browser back/forward
            window.addEventListener('popstate', function(e) {
                if (e.state && e.state.tab) {
                    var tabId = e.state.tab;
                    $('#smtp-tabs-nav .ofast-tab').removeClass('active');
                    $('#smtp-tabs-nav .ofast-tab[data-tab="' + tabId + '"]').addClass('active');
                    $('.ofast-tab-content').removeClass('active').hide();
                    $('#smtp-tab-' + tabId).addClass('active').show();
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Dashboard content (without wrap) - used by tabs
     */
    private function render_dashboard_page_content()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ofast_smtp_log';
        $this->create_log_table();

        $enabled = get_option('ofast_smtp_enabled', false);
        $mailer_type = get_option('ofast_smtp_mailer_type', 'default');
        $provider = get_option('ofast_smtp_provider', 'custom');
        $host = get_option('ofast_smtp_host', '');
        $username = get_option('ofast_smtp_username', '');
        $encryption = get_option('ofast_smtp_encryption', 'tls');
        $from_email = get_option('ofast_smtp_from_email', '');

        $presets = Ofast_X_SMTP::get_provider_presets();
        
        // Determine if mailer is active
        $is_active = $enabled && ($mailer_type === 'default' || (!empty($host) && !empty($username)));
        $mailer_name = $mailer_type === 'default' ? 'PHP Mail (Default)' : ($presets[$provider]['name'] ?? 'Custom SMTP');

        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;

        $stats = array('total' => 0, 'success' => 0, 'failed' => 0, 'rate' => 0);
        $weekly_data = array();
        $recent_emails = array();

        if ($table_exists) {
            $stats['total'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"));
            $stats['success'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'success'"));
            $stats['failed'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'failed'"));
            $stats['rate'] = $stats['total'] > 0 ? round(($stats['success'] / $stats['total']) * 100) : 0;

            for ($i = 6; $i >= 0; $i--) {
                $date = gmdate('Y-m-d', strtotime("-{$i} days"));
                $day_name = gmdate('D', strtotime("-{$i} days"));
                $count = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE DATE(sent_at) = %s",
                    $date
                )));
                $weekly_data[] = array('day' => $day_name, 'count' => $count);
            }

            $recent_emails = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY sent_at DESC LIMIT 5");
        }

        if (empty($weekly_data)) {
            for ($i = 6; $i >= 0; $i--) {
                $weekly_data[] = array(
                    'day' => gmdate('D', strtotime("-{$i} days")),
                    'count' => 0
                );
            }
        }

        $weekly_counts = array_column($weekly_data, 'count');
        $max_weekly = !empty($weekly_counts) ? max($weekly_counts) : 1;
        ?>
        <p>Monitor your email delivery performance and SMTP status.</p>

        <!-- Connection Status -->
        <div class="ofast-grid-3" style="margin: 25px 0;">
            <div style="background: <?php echo $is_active ? 'linear-gradient(135deg, #10b981, #059669)' : 'linear-gradient(135deg, #6b7280, #4b5563)'; ?>; padding: 25px; border-radius: 12px; color: #fff; text-align: center;">
                <div style="font-size: 28px; margin-bottom: 5px;">
                    <?php echo $is_active ? '✓' : '✗'; ?>
                </div>
                <div style="font-size: 18px; font-weight: 600;">
                    <?php echo $is_active ? 'Mailer Active' : 'Mailer Inactive'; ?>
                </div>
                <div style="font-size: 13px; opacity: 0.9; margin-top: 5px;">
                    <?php echo $is_active ? esc_html($mailer_name) : 'Not Configured'; ?>
                </div>
            </div>
            <div style="background: linear-gradient(135deg, #6366f1, #4f46e5); padding: 25px; border-radius: 12px; color: #fff; text-align: center;">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">Provider</div>
                <div style="font-size: 18px; font-weight: 600;">
                    <?php echo $mailer_type === 'default' ? 'Server Mail' : ($host ? esc_html($host) : 'Not Set'); ?>
                </div>
                <div style="font-size: 13px; opacity: 0.9; margin-top: 5px;">
                    <?php echo $mailer_type === 'default' ? 'PHP mail() function' : 'Port ' . esc_html(get_option('ofast_smtp_port', 587)) . ' / ' . strtoupper(esc_html($encryption)); ?>
                </div>
            </div>
            <div style="background: linear-gradient(135deg, #3b82f6, #2563eb); padding: 25px; border-radius: 12px; color: #fff; text-align: center;">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">From Address</div>
                <div style="font-size: 16px; font-weight: 600; word-break: break-all;">
                    <?php echo $from_email ? esc_html($from_email) : 'Not Set'; ?>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="ofast-grid-4" style="margin: 25px 0;">
            <div style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 36px; font-weight: 700; color: #6366f1;"><?php echo number_format($stats['total']); ?></div>
                <div style="color: #6b7280; font-size: 14px; margin-top: 5px;">Total Emails</div>
            </div>
            <div style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 36px; font-weight: 700; color: #10b981;"><?php echo number_format($stats['success']); ?></div>
                <div style="color: #6b7280; font-size: 14px; margin-top: 5px;">Successful</div>
            </div>
            <div style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 36px; font-weight: 700; color: #ef4444;"><?php echo number_format($stats['failed']); ?></div>
                <div style="color: #6b7280; font-size: 14px; margin-top: 5px;">Failed</div>
            </div>
            <div style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 36px; font-weight: 700; color: <?php echo $stats['rate'] >= 90 ? '#10b981' : ($stats['rate'] >= 70 ? '#f59e0b' : '#ef4444'); ?>;">
                    <?php echo $stats['rate']; ?>%
                </div>
                <div style="color: #6b7280; font-size: 14px; margin-top: 5px;">Success Rate</div>
            </div>
        </div>

        <!-- Weekly Chart & Recent -->
        <div class="ofast-flex-layout" style="margin: 25px 0;">
            <div class="ofast-main" style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: flex; flex-direction: column;">
                <h3 style="margin: 0; font-size: 16px; color: #374151;">Emails Last 7 Days</h3>
                <div style="flex: 1;"></div>
                <div style="display: flex; align-items: flex-end; justify-content: space-between; gap: 8px;">
                    <?php foreach ($weekly_data as $day): ?>
                        <?php
                        // Max height 80px for bars
                        $bar_height = $max_weekly > 0 ? round(($day['count'] / $max_weekly) * 80) : 0;
                        $bar_height = max(5, $bar_height);
                        ?>
                        <div style="flex: 1; text-align: center;">
                            <div style="background: linear-gradient(to top, #6366f1, #818cf8); height: <?php echo $bar_height; ?>px; border-radius: 4px 4px 0 0; min-height: 5px;" title="<?php echo $day['count']; ?> emails"></div>
                            <div style="font-size: 11px; color: #6b7280; margin-top: 8px;"><?php echo esc_html($day['day']); ?></div>
                            <div style="font-size: 12px; font-weight: 600; color: #374151;"><?php echo $day['count']; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="ofast-main" style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 20px 0; font-size: 16px; color: #374151;">Recent Emails</h3>
                <?php if (empty($recent_emails)): ?>
                    <p style="color: #6b7280; text-align: center; padding: 30px 0;">No emails sent yet.</p>
                <?php else: ?>
                    <div style="max-height: 200px; overflow-y: auto;">
                        <?php foreach ($recent_emails as $email): ?>
                            <div style="display: flex; align-items: center; padding: 10px 0; border-bottom: 1px solid #f3f4f6;">
                                <div style="flex: 1; min-width: 0;">
                                    <div style="font-size: 13px; font-weight: 500; color: #374151; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?php echo esc_html($email->subject); ?>
                                    </div>
                                    <div style="font-size: 12px; color: #6b7280;">
                                        <?php echo esc_html($email->to_email); ?>
                                    </div>
                                </div>
                                <div style="margin-left: 10px;">
                                    <?php if ($email->status === 'success'): ?>
                                        <span style="background: #d1fae5; color: #065f46; padding: 2px 8px; border-radius: 3px; font-size: 10px;">✓</span>
                                    <?php else: ?>
                                        <span style="background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 3px; font-size: 10px;">✗</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div style="background: #f8fafc; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb;">
            <h3 style="margin: 0 0 15px 0; font-size: 16px; color: #374151;">Quick Actions</h3>
            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <a href="<?php echo admin_url('admin.php?page=ofast-smtp&tab=settings'); ?>" class="button button-primary button-large">Configure SMTP</a>
                <a href="<?php echo admin_url('admin.php?page=ofast-smtp&tab=log'); ?>" class="button button-secondary button-large">View All Logs</a>
            </div>
        </div>
        <?php
    }

    /**
     * Log page content (without wrap) - used by tabs
     */
    private function render_log_page_content()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ofast_smtp_log';
        $this->create_log_table();

        // Handle resend action
        if (isset($_GET['resend']) && isset($_GET['_wpnonce'])) {
            if (wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']), 'resend_email')) {
                $this->resend_email(intval($_GET['resend']));
            }
        }

        // Handle export CSV
        if (isset($_GET['export_csv']) && isset($_GET['_wpnonce'])) {
            if (wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']), 'export_smtp_logs')) {
                $this->export_logs_csv();
                return;
            }
        }

        // Handle clear logs
        if (isset($_POST['clear_logs']) && isset($_POST['_wpnonce'])) {
            if (wp_verify_nonce(sanitize_text_field($_POST['_wpnonce']), 'clear_smtp_logs')) {
                $days = intval($_POST['clear_days'] ?? 30);
                $deleted = $this->clear_old_logs($days);
                Ofast_X_Toast::add("Deleted {$deleted} log entries older than {$days} days.", 'success');
            }
        }

        $per_page = 20;
        $current_page = max(1, intval($_GET['paged'] ?? 1));
        $offset = ($current_page - 1) * $per_page;

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} ORDER BY sent_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));

        $stats = array(
            'total' => $total,
            'success' => $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'success'"),
            'failed' => $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'failed'"),
            'today' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE DATE(sent_at) = %s", current_time('Y-m-d')))
        );
        ?>
        <p>View all emails sent through SMTP with status and preview.</p>

        <!-- Stats -->
        <div class="ofast-grid-4" style="margin: 20px 0;">
            <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb; text-align: center;">
                <div style="font-size: 32px; font-weight: bold; color: #6366f1;"><?php echo esc_html($stats['total']); ?></div>
                <div style="color: #6b7280;">Total Emails</div>
            </div>
            <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb; text-align: center;">
                <div style="font-size: 32px; font-weight: bold; color: #10b981;"><?php echo esc_html($stats['success']); ?></div>
                <div style="color: #6b7280;">Successful</div>
            </div>
            <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb; text-align: center;">
                <div style="font-size: 32px; font-weight: bold; color: #ef4444;"><?php echo esc_html($stats['failed']); ?></div>
                <div style="color: #6b7280;">Failed</div>
            </div>
            <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb; text-align: center;">
                <div style="font-size: 32px; font-weight: bold; color: #3b82f6;"><?php echo esc_html($stats['today']); ?></div>
                <div style="color: #6b7280;">Sent Today</div>
            </div>
        </div>

        <!-- Actions -->
        <div style="display: flex; gap: 15px; align-items: center; margin: 20px 0; flex-wrap: wrap;">
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ofast-smtp&tab=log&export_csv=1'), 'export_smtp_logs'); ?>" class="button button-secondary">Export CSV</a>
            <form method="post" style="display: flex; gap: 10px; align-items: center; margin: 0;">
                <?php wp_nonce_field('clear_smtp_logs'); ?>
                <span style="color: #6b7280;">Clear logs older than</span>
                <select name="clear_days" style="width: auto;">
                    <option value="7">7 days</option>
                    <option value="30" selected>30 days</option>
                    <option value="60">60 days</option>
                    <option value="90">90 days</option>
                </select>
                <button type="submit" name="clear_logs" class="button" onclick="return confirm('Are you sure?');">Clear Old Logs</button>
            </form>
        </div>

        <!-- Log Table -->
        <div style="overflow-x: auto; max-width: 100%;">
            <table class="wp-list-table widefat fixed striped" style="min-width: 800px;">
                <thead>
                    <tr>
                        <th style="width: 50px;">ID</th>
                        <th>To</th>
                        <th>Subject</th>
                        <th style="width: 80px;">Status</th>
                        <th style="width: 160px;">Sent At</th>
                        <th style="width: 150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="6" style="text-align: center; padding: 40px; color: #6b7280;">No emails logged yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html($log->id); ?></td>
                                <td><?php echo esc_html($log->to_email); ?></td>
                                <td><?php echo esc_html($log->subject); ?></td>
                                <td>
                                    <?php if ($log->status === 'success'): ?>
                                        <span style="background: #d1fae5; color: #065f46; padding: 2px 8px; border-radius: 3px; font-size: 11px;">SUCCESS</span>
                                    <?php else: ?>
                                        <span style="background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 3px; font-size: 11px;">FAILED</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($log->sent_at); ?></td>
                                <td>
                                    <?php if (!empty($log->body)): ?>
                                        <button type="button" class="button button-small preview-email" data-id="<?php echo esc_attr($log->id); ?>" data-content="<?php echo esc_attr(base64_encode($log->body)); ?>">Preview</button>
                                    <?php else: ?>
                                        <span style="color: #6b7280; font-style: italic;">No content stored</span>
                                    <?php endif; ?>
                                    <?php if ($log->status === 'failed' && !empty($log->body)): ?>
                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ofast-smtp&tab=log&resend=' . $log->id), 'resend_email'); ?>" class="button button-small">Resend</a>
                                    <?php elseif ($log->status === 'failed'): ?>
                                        <span style="color: #6b7280; font-style: italic; margin-left: 8px;">Resend unavailable</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php
        $total_pages = ceil($total / $per_page);
        if ($total_pages > 1):
        ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php echo paginate_links(array('base' => add_query_arg('paged', '%#%'), 'format' => '', 'prev_text' => '&laquo;', 'next_text' => '&raquo;', 'total' => $total_pages, 'current' => $current_page)); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Preview Modal -->
        <div id="email-preview-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 100000;">
            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; border-radius: 8px; width: 90%; max-width: 700px; max-height: 80vh; overflow: hidden;">
                <div style="padding: 15px 20px; background: #f8fafc; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0;">Email Preview</h3>
                    <button type="button" id="close-preview" style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
                </div>
                
                <?php if (get_option('ofast_smtp_log_body_content', false)): ?>
                <div style="padding: 10px 20px; background: #fffbeb; border-bottom: 1px solid #f59e0b; color: #92400e; font-size: 13px;">
                    <strong>🔒 Security Notice:</strong> Sensitive patterns (passwords, tokens, API keys) have been automatically filtered from this preview.
                </div>
                <?php endif; ?>
                
                <iframe id="email-preview-frame" style="width: 100%; height: 60vh; border: none;"></iframe>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                $('.preview-email').on('click', function() {
                    var content = atob($(this).data('content'));
                    document.getElementById('email-preview-frame').srcdoc = content;
                    $('#email-preview-modal').fadeIn(200);
                });
                $('#close-preview, #email-preview-modal').on('click', function(e) {
                    if (e.target === this || $(this).attr('id') === 'close-preview') {
                        $('#email-preview-modal').fadeOut(200);
                    }
                });
            });
        </script>
        <?php
    }

    private function render_settings_page_content()
    {
        // Toast notifications are rendered automatically via the footer hook

        $enabled = get_option('ofast_smtp_enabled', false);
        $mailer_type = get_option('ofast_smtp_mailer_type', 'default');
        $provider = get_option('ofast_smtp_provider', 'custom');
        $host = get_option('ofast_smtp_host', '');
        $port = get_option('ofast_smtp_port', 587);
        $encryption = get_option('ofast_smtp_encryption', 'tls');
        $username = get_option('ofast_smtp_username', '');
        $password = get_option('ofast_smtp_password', '');
        $from_email = get_option('ofast_smtp_from_email', '');
        $from_name = get_option('ofast_smtp_from_name', get_bloginfo('name'));
        $presets = Ofast_X_SMTP::get_provider_presets();
        ?>
        <p>Configure SMTP to ensure reliable email delivery from your WordPress site.</p>

        <form method="post" id="ofast-smtp-form">
            <?php wp_nonce_field('ofast_smtp_settings', '_wpnonce'); ?>

            <table class="form-table">
                <tr>
                    <th>Enable SMTP</th>
                    <td>
                        <label><input type="checkbox" name="smtp_enabled" value="1" <?php checked($enabled); ?>> Use SMTP for all WordPress emails</label>
                        <p class="description">When enabled, all emails will be sent through your configured mailer.</p>
                    </td>
                </tr>
                <tr>
                    <th>Mailer Type</th>
                    <td>
                        <select name="smtp_mailer_type" id="smtp_mailer_type">
                            <option value="default" <?php selected($mailer_type, 'default'); ?>>PHP Mail (Default) - No credentials needed</option>
                            <option value="smtp" <?php selected($mailer_type, 'smtp'); ?>>Other SMTP - Custom server</option>
                        </select>
                        <p class="description" id="mailer_note" style="margin-top: 10px; padding: 10px; background: #f0f6fc; border-radius: 5px;">
                            <?php if ($mailer_type === 'default'): ?>
                                Uses your server's built-in mail function. Only From Email/Name needed. Best for most hosts.
                            <?php else: ?>
                                Requires SMTP server credentials. Better deliverability with providers like SendGrid, Mailgun.
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>

            <!-- SMTP Provider (only for smtp mailer type) -->
            <div id="smtp-credentials-section" style="<?php echo $mailer_type === 'default' ? 'display:none;' : ''; ?>">
                <table class="form-table">
                    <tr>
                        <th>Email Provider</th>
                        <td>
                            <select name="smtp_provider" id="smtp_provider">
                                <?php foreach ($presets as $key => $preset): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($provider, $key); ?>><?php echo esc_html($preset['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description" id="provider_note" style="margin-top: 10px; padding: 10px; background: #f0f6fc; border-radius: 5px;">
                                <?php echo esc_html($presets[$provider]['note'] ?? ''); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top: 30px;">Connection Settings</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="smtp_host">SMTP Host *</label></th>
                        <td><input type="text" name="smtp_host" id="smtp_host" value="<?php echo esc_attr($host); ?>" class="regular-text" placeholder="smtp.example.com"></td>
                    </tr>
                    <tr>
                        <th><label for="smtp_port">SMTP Port *</label></th>
                        <td>
                            <input type="number" name="smtp_port" id="smtp_port" value="<?php echo esc_attr($port); ?>" style="width: 100px;">
                            <span class="description">Common: 587 (TLS), 465 (SSL), 25 (None)</span>
                        </td>
                    </tr>
                    <tr>
                        <th>Encryption</th>
                        <td>
                            <label><input type="radio" name="smtp_encryption" value="tls" <?php checked($encryption, 'tls'); ?>> TLS (Recommended)</label><br>
                            <label><input type="radio" name="smtp_encryption" value="ssl" <?php checked($encryption, 'ssl'); ?>> SSL</label><br>
                            <label><input type="radio" name="smtp_encryption" value="none" <?php checked($encryption, 'none'); ?>> None</label>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top: 30px;">Authentication</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="smtp_username">Username *</label></th>
                        <td><input type="text" name="smtp_username" id="smtp_username" value="<?php echo esc_attr($username); ?>" class="regular-text" placeholder="your@email.com or apikey"></td>
                    </tr>
                    <tr>
                        <th><label for="smtp_password">Password *</label></th>
                        <td>
                            <input type="password" name="smtp_password" id="smtp_password" value="<?php echo $password ? '••••••••' : ''; ?>" class="regular-text" placeholder="Enter password or API key">
                            <button type="button" class="button button-small" onclick="this.previousElementSibling.type = this.previousElementSibling.type === 'password' ? 'text' : 'password'">Show/Hide</button>
                            <p class="description">For Gmail/Zoho: Use an App Password, not your login password</p>
                        </td>
                    </tr>
                </table>
            </div>

            <h2 style="margin-top: 30px;">From Settings</h2>
            <table class="form-table">
                <tr>
                    <th><label for="smtp_from_email">From Email *</label></th>
                    <td>
                        <input type="email" name="smtp_from_email" id="smtp_from_email" value="<?php echo esc_attr($from_email); ?>" class="regular-text" placeholder="noreply@yoursite.com">
                        <p class="description">The email address shown as sender</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="smtp_from_name">From Name</label></th>
                    <td><input type="text" name="smtp_from_name" id="smtp_from_name" value="<?php echo esc_attr($from_name); ?>" class="regular-text" placeholder="Your Website Name"></td>
                </tr>
            </table>

            <div id="rate-limit-section" style="<?php echo $mailer_type === 'default' ? 'display:none;' : ''; ?>">
                <h2 style="margin-top: 30px;">Rate Limiting</h2>
                <table class="form-table">
                    <tr>
                        <th>Enable Rate Limiting</th>
                        <td><label><input type="checkbox" name="rate_limit_enabled" value="1" <?php checked(get_option('ofast_smtp_rate_limit_enabled', true)); ?>> Limit emails per minute</label></td>
                    </tr>
                    <tr>
                        <th><label for="rate_limit">Max Emails/Minute</label></th>
                        <td>
                            <input type="number" name="rate_limit" id="rate_limit" value="<?php echo esc_attr(get_option('ofast_smtp_rate_limit', 60)); ?>" min="1" max="500" style="width: 80px;">
                            <span class="description">Recommended: 30-60 for shared hosting</span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Test Connection -->
            <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin: 30px 0;">
                <h3 style="margin-top: 0;">Test Connection</h3>
                <p>Send a test email to verify your settings are correct.</p>
                <button type="button" id="test-smtp-btn" class="button button-secondary">Send Test Email to <?php echo esc_html(get_option('admin_email')); ?></button>
                <span id="test-result" style="margin-left: 15px;"></span>
                <div id="test-details" style="margin-top: 15px; display: none;">
                    <pre style="background: #1e293b; color: #10b981; padding: 15px; border-radius: 5px; overflow-x: auto;"></pre>
                </div>
            </div>

            <!-- Email Logging Security Settings -->
            <div style="background: #fffbeb; border: 1px solid #f59e0b; border-radius: 8px; padding: 20px; margin: 30px 0;">
                <h3 style="margin-top: 0; color: #92400e;">🔒 Email Logging Security</h3>
                <p style="color: #92400e;">
                    <strong>Security Notice:</strong> Email logs may contain sensitive information like passwords, tokens, and personal data.
                    Configure logging level based on your security requirements.
                </p>
                
                <table class="form-table">
                    <tr>
                        <th>Log Email Content</th>
                        <td>
                            <?php $log_body = get_option('ofast_smtp_log_body_content', false); ?>
                            <label>
                                <input type="checkbox" name="log_body_content" value="1" <?php checked($log_body); ?>>
                                Store filtered email content in logs
                            </label>
                            <p class="description" style="color: #92400e;">
                                <strong>Recommended: Leave unchecked</strong> - Only metadata (to, subject, status, timestamp) will be logged for security.<br>
                                When enabled, sensitive patterns (passwords, tokens, API keys) are automatically filtered before storage.
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <p class="submit">
                <button type="submit" name="ofast_smtp_save" class="button button-primary button-large">Save SMTP Settings</button>
            </p>
        </form>

        <!-- Provider Setup Guides -->
        <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin-top: 30px;">
            <h3 style="margin-top: 0;">Quick Setup Guides</h3>

            <details style="margin-bottom: 15px;">
                <summary style="cursor: pointer; font-weight: bold; color: #6366f1;">Zoho Mail Setup</summary>
                <div style="padding: 15px; background: #f9fafb; margin-top: 10px; border-radius: 5px;">
                    <ol>
                        <li>Log in to Zoho Mail</li>
                        <li>Go to Settings &rarr; Security &rarr; App Passwords</li>
                        <li>Generate a new App Password</li>
                        <li>Use: Host: smtp.zoho.com, Port: 587, TLS</li>
                        <li>Username: your Zoho email, Password: App Password</li>
                    </ol>
                </div>
            </details>

            <details style="margin-bottom: 15px;">
                <summary style="cursor: pointer; font-weight: bold; color: #6366f1;">SendGrid Setup</summary>
                <div style="padding: 15px; background: #f9fafb; margin-top: 10px; border-radius: 5px;">
                    <ol>
                        <li>Log in to SendGrid</li>
                        <li>Go to Settings &rarr; API Keys</li>
                        <li>Create API Key with Mail Send permission</li>
                        <li>Use: Host: smtp.sendgrid.net, Port: 587, TLS</li>
                        <li>Username: <code>apikey</code> (literally), Password: Your API Key</li>
                    </ol>
                </div>
            </details>

            <details style="margin-bottom: 15px;">
                <summary style="cursor: pointer; font-weight: bold; color: #6366f1;">Gmail Setup</summary>
                <div style="padding: 15px; background: #f9fafb; margin-top: 10px; border-radius: 5px;">
                    <ol>
                        <li>Enable 2-Factor Authentication on your Google account</li>
                        <li>Go to Google Account &rarr; Security &rarr; App Passwords</li>
                        <li>Generate App Password for "Mail"</li>
                        <li>Use: Host: smtp.gmail.com, Port: 587, TLS</li>
                        <li>Username: your Gmail, Password: App Password (16 chars)</li>
                    </ol>
                    <p style="color: #dc2626;"><strong>Note:</strong> Gmail has 500 emails/day limit for free accounts.</p>
                </div>
            </details>

            <details style="margin-bottom: 15px;">
                <summary style="cursor: pointer; font-weight: bold; color: #6366f1;">Brevo (Sendinblue) Setup</summary>
                <div style="padding: 15px; background: #f9fafb; margin-top: 10px; border-radius: 5px;">
                    <ol>
                        <li>Log in to Brevo (formerly Sendinblue)</li>
                        <li>Go to Settings &rarr; SMTP &amp; API</li>
                        <li>Copy your SMTP Key</li>
                        <li>Use: Host: smtp-relay.brevo.com, Port: 587, TLS</li>
                        <li>Username: your Brevo email, Password: SMTP Key</li>
                    </ol>
                    <p style="color: #059669;"><strong>Free tier:</strong> 300 emails/day, great for small sites!</p>
                </div>
            </details>

            <details style="margin-bottom: 15px;">
                <summary style="cursor: pointer; font-weight: bold; color: #6366f1;">Amazon SES Setup</summary>
                <div style="padding: 15px; background: #f9fafb; margin-top: 10px; border-radius: 5px;">
                    <ol>
                        <li>Log in to AWS Console &rarr; SES</li>
                        <li>Verify your domain or email address</li>
                        <li>Go to SMTP Settings &rarr; Create SMTP Credentials</li>
                        <li>Use: Host: email-smtp.[region].amazonaws.com, Port: 587, TLS</li>
                        <li>Username/Password: Generated SMTP credentials (NOT IAM keys)</li>
                    </ol>
                    <p style="color: #f59e0b;"><strong>Note:</strong> New accounts start in sandbox mode (verify recipients first).</p>
                </div>
            </details>
        </div>

        <script>
            jQuery(document).ready(function($) {
                $('#smtp_mailer_type').on('change', function() {
                    var isSmtp = $(this).val() === 'smtp';
                    $('#smtp-credentials-section').toggle(isSmtp);
                    $('#rate-limit-section').toggle(isSmtp);
                    $('#mailer_note').text(isSmtp 
                        ? 'Requires SMTP server credentials. Better deliverability with providers like SendGrid, Mailgun.' 
                        : 'Uses your server\'s built-in mail function. Only From Email/Name needed. Best for most hosts.');
                });
            });
        </script>

        <!-- DNS Checker Section -->
        <div style="margin-top: 30px;">
            <h2>Email Authentication (DNS)</h2>
            <?php
            if (file_exists(OFAST_X_PLUGIN_DIR . 'modules/smtp/class-ofast-smtp-dns.php')) {
                require_once OFAST_X_PLUGIN_DIR . 'modules/smtp/class-ofast-smtp-dns.php';
                Ofast_X_SMTP_DNS::get_instance()->render_checker_ui();
            }
            ?>
        </div>
        <?php
    }

    /**
     * Enqueue scripts
     */
    public function enqueue_scripts($hook)
    {
        if (strpos($hook, 'ofast-smtp') === false) {
            return;
        }

        wp_enqueue_script(
            'ofast-smtp-admin',
            OFAST_X_PLUGIN_URL . 'modules/smtp/smtp-admin.js',
            array('jquery'),
            OFAST_X_VERSION,
            true
        );

        wp_localize_script('ofast-smtp-admin', 'ofastSMTP', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ofast_test_smtp'),
            'presets' => Ofast_X_SMTP::get_provider_presets()
        ));
    }

    /**
     * Handle settings save
     */
    public function handle_save()
    {
        if (!isset($_POST['ofast_smtp_save'])) {
            return;
        }

        check_admin_referer('ofast_smtp_settings', '_wpnonce');

        if (!current_user_can('manage_options')) {
            return;
        }

        // SECURITY FIX: Validate encryption keys before processing SMTP credentials
        if (!empty($_POST['smtp_password']) && $_POST['smtp_password'] !== '••••••••') {
            if (!Ofast_X_SMTP::validate_encryption_keys()) {
                $key_diagnostics = Ofast_X_SMTP::get_key_validation_details();
                error_log('OFAST SMTP Security Warning: Attempted to save credentials with invalid encryption keys: ' . $key_diagnostics['message']);
                add_action('admin_notices', function() use ($key_diagnostics) {
                    echo '<div class="notice notice-error is-dismissible">';
                    echo '<p><strong>SMTP Configuration Error:</strong> ' . esc_html($key_diagnostics['message']) . '</p>';
                    if (!empty($key_diagnostics['suggestion'])) {
                        echo '<p><strong>Solution:</strong> ' . esc_html($key_diagnostics['suggestion']) . '</p>';
                    }
                    echo '</div>';
                });
                return;
            }
        }

        // Save all settings
        update_option('ofast_smtp_enabled', isset($_POST['smtp_enabled']) ? 1 : 0);
        update_option('ofast_smtp_mailer_type', sanitize_text_field(wp_unslash($_POST['smtp_mailer_type'] ?? 'default')));
        update_option('ofast_smtp_provider', sanitize_text_field(wp_unslash($_POST['smtp_provider'] ?? 'custom')));
        update_option('ofast_smtp_host', sanitize_text_field(wp_unslash($_POST['smtp_host'] ?? '')));
        update_option('ofast_smtp_port', intval($_POST['smtp_port'] ?? 587));
        update_option('ofast_smtp_encryption', sanitize_text_field(wp_unslash($_POST['smtp_encryption'] ?? 'tls')));
        update_option('ofast_smtp_username', sanitize_text_field(wp_unslash($_POST['smtp_username'] ?? '')));
        update_option('ofast_smtp_from_email', sanitize_email(wp_unslash($_POST['smtp_from_email'] ?? '')));
        update_option('ofast_smtp_from_name', sanitize_text_field(wp_unslash($_POST['smtp_from_name'] ?? '')));

        // Only update password if provided (not empty placeholder)
        $smtp_password = wp_unslash($_POST['smtp_password'] ?? '');
        if (!empty($smtp_password) && $smtp_password !== '••••••••') {
            try {
                $encrypted = Ofast_X_SMTP::encrypt_password($smtp_password);
                update_option('ofast_smtp_password', $encrypted);
            } catch (Exception $e) {
                // SECURITY FIX: Handle encryption failures securely
                error_log('OFAST SMTP Security Error: ' . $e->getMessage());
                add_action('admin_notices', function() use ($e) {
                    printf('<div class="notice notice-error is-dismissible"><p><strong>SMTP Configuration Error:</strong> %s</p></div>', esc_html($e->getMessage()));
                });
                return; // Stop processing if credentials cannot be stored securely
            }
        }

        // Rate limiting settings
        update_option('ofast_smtp_rate_limit_enabled', isset($_POST['rate_limit_enabled']) ? 1 : 0);
        update_option('ofast_smtp_rate_limit', max(1, intval($_POST['rate_limit'] ?? 60)));

        // Email logging security settings
        $log_body_content = isset($_POST['log_body_content']) ? 1 : 0;
        update_option('ofast_smtp_log_body_content', $log_body_content);
        
        // Notify listeners when body logging is enabled (for optional auditing).
        if ($log_body_content) {
            do_action('ofast_smtp_body_logging_enabled', get_current_user_id(), current_time('mysql'));
        }
        Ofast_X_Toast::add('SMTP settings saved successfully!', 'success');
    }

    /**
     * Render Dashboard page
     */
    public function render_dashboard_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        ?>
        <div class="wrap">
            <h1>SMTP Dashboard</h1>
            <?php $this->render_dashboard_page_content(); ?>
        </div>
        <?php
    }

    /**
     * Render settings page
     */
    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        settings_errors('ofast_smtp');
        ?>
        <div class="wrap">
            <h1>SMTP Settings</h1>
            <?php $this->render_settings_page_content(); ?>
        </div>
        <?php
    }

    /**
     * Render Email Log page
     */
    public function render_log_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        ?>
        <div class="wrap">
            <h1>Email Log</h1>
            <?php $this->render_log_page_content(); ?>
        </div>
        <?php
    }

    /**
     * Export logs as CSV
     */
    private function export_logs_csv()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ofast_smtp_log';

        $logs = $wpdb->get_results("SELECT id, to_email, subject, status, error_message, sent_at FROM {$table_name} ORDER BY sent_at DESC");

        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=smtp_logs_' . date('Y-m-d') . '.csv');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // Add UTF-8 BOM for Excel compatibility
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Column headers
        fputcsv($output, array('ID', 'To', 'Subject', 'Status', 'Error Message', 'Sent At'));

        // Data rows
        foreach ($logs as $log) {
            fputcsv($output, array(
                $log->id,
                $log->to_email,
                $log->subject,
                $log->status,
                $log->error_message ?? '',
                $log->sent_at
            ));
        }

        fclose($output);
        exit;
    }

    /**
     * Clear old logs
     */
    private function clear_old_logs($days)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ofast_smtp_log';

        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE sent_at < %s",
            $cutoff_date
        ));

        return $deleted;
    }

    /**
     * Ensure log table exists (admin pages depend on it)
     */
    private function create_log_table()
    {
        // Avoid repeated checks within a day
        if (get_transient('ofast_smtp_log_table_exists')) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ofast_smtp_log';

        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_name
        ));

        if ($table_exists !== $table_name) {
            $charset = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE {$table_name} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                to_email varchar(255) NOT NULL,
                subject varchar(255) NOT NULL,
                body longtext,
                headers text,
                status varchar(20) DEFAULT 'pending',
                error_message text,
                sent_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY status (status),
                KEY sent_at (sent_at)
            ) {$charset};";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }

        set_transient('ofast_smtp_log_table_exists', true, DAY_IN_SECONDS);
    }
}



