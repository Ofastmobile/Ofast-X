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
        add_action('admin_init', array($this, 'handle_resend'));
        add_action('admin_init', array($this, 'handle_support_form'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_ofast_smtp_fetch_logs', array($this, 'ajax_fetch_logs'));

        // SECURITY: Send HTTP security headers on our admin pages
        add_action('admin_init', array($this, 'send_security_headers'));

        // SECURITY: Move CSV export to admin_init (before output)
        add_action('admin_init', array($this, 'handle_csv_export'));
    }

    /**
     * Send HTTP security headers on plugin admin pages
     */
    public function send_security_headers()
    {
        if (!isset($_GET['page'])) return;
        $page = sanitize_key($_GET['page']);
        if ($page !== 'ofast-smtp') return;

        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
    }

    /**
     * Handle CSV export on admin_init (before any output)
     */
    public function handle_csv_export()
    {
        if (!isset($_GET['page'], $_GET['export_csv'], $_GET['_wpnonce'])) return;
        if (sanitize_key($_GET['page']) !== 'ofast-smtp') return;
        if (!current_user_can('manage_options')) return;
        if (!wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']), 'export_smtp_logs')) return;

        $this->create_log_table();
        $this->export_logs_csv();
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
        <style>
            .ofast-saas-sidebar {
                width: 200px; 
                background: #0f172a; 
                border-right: 1px solid rgba(255,255,255,0.05); 
                padding: 30px 15px; 
                display: flex; 
                flex-direction: column;
                flex-shrink: 0;
                transition: width 0.3s ease;
            }
            .ofast-saas-sidebar.collapsed {
                width: 70px;
                padding: 30px 10px;
            }
            .ofast-saas-nav-link {
                padding: 12px 12px; 
                border-radius: 10px; 
                color: #94a3b8; 
                background: transparent; 
                text-decoration: none; 
                display: flex; 
                align-items: center; 
                gap: 12px; 
                font-weight: 500; 
                font-size: 15px; 
                transition: all 0.2s;
                margin-bottom: 8px;
                white-space: nowrap;
                overflow: hidden;
            }
            .ofast-saas-nav-link:hover {
                color: #fff; 
                background: rgba(255,255,255,0.05);
            }
            .ofast-saas-nav-link.active, .ofast-saas-nav-link.active:hover {
                color: #fff; 
                background: #6366f1; 
                box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
            }
            .ofast-saas-nav-link .dashicons {
                font-size: 20px; 
                width: 20px; 
                height: 20px;
                flex-shrink: 0;
            }
            .ofast-saas-nav-text {
                transition: opacity 0.2s ease;
            }
            .ofast-saas-sidebar.collapsed .ofast-saas-nav-text, 
            .ofast-saas-sidebar.collapsed .ofast-logo-text {
                opacity: 0;
                width: 0;
                display: none;
            }
            .ofast-saas-sidebar.collapsed .ofast-logo-icon {
                margin: 0 auto;
            }
            .ofast-saas-main {
                flex: 1; 
                min-width: 0; 
                background: #f8fafc; 
                overflow-y: auto;
                transition: background 0.3s ease;
            }
            .ofast-sidebar-toggle-btn {
                margin-top: auto;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 12px;
                cursor: pointer;
                color: #94a3b8;
                border-radius: 8px;
                transition: background 0.2s, color 0.2s;
            }
            .ofast-sidebar-toggle-btn:hover {
                background: rgba(255,255,255,0.05);
                color: #fff;
            }
        </style>

        <div class="wrap" style="display: flex; background: #0f172a; border-radius: 16px; min-height: 85vh; overflow: hidden; margin-top: 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; box-shadow: 0 4px 20px rgba(0,0,0,0.1);">
            
            <!-- Left Sidebar Navigation -->
            <div class="ofast-saas-sidebar" id="ofast-saas-sidebar">
                <!-- Logo / Header -->
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 40px; padding-left: 5px; height: 32px;">
                    <div class="ofast-logo-icon" style="width: 32px; height: 32px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <span class="dashicons dashicons-email-alt2" style="color: #fff; font-size: 18px; width: 18px; height: 18px;"></span>
                    </div>
                    <span class="ofast-logo-text" style="color: #f8fafc; font-size: 18px; font-weight: 700; letter-spacing: -0.5px; white-space: nowrap;">Ofast SMTP</span>
                </div>

                <!-- Navigation Links -->
                <nav id="smtp-tabs-nav" style="display: flex; flex-direction: column;">
                    <a href="#" class="ofast-tab ofast-saas-nav-link <?php echo $default_tab === 'dashboard' ? 'active' : ''; ?>" data-tab="dashboard">
                        <span class="dashicons dashicons-chart-area"></span> <span class="ofast-saas-nav-text">Dashboard</span>
                    </a>
                    <a href="#" class="ofast-tab ofast-saas-nav-link <?php echo $default_tab === 'log' ? 'active' : ''; ?>" data-tab="log">
                        <span class="dashicons dashicons-list-view"></span> <span class="ofast-saas-nav-text">Email Log</span>
                    </a>
                    <a href="#" class="ofast-tab ofast-saas-nav-link <?php echo $default_tab === 'support' ? 'active' : ''; ?>" data-tab="support">
                        <span class="dashicons dashicons-sos"></span> <span class="ofast-saas-nav-text">Help & Support</span>
                    </a>
                    <a href="#" class="ofast-tab ofast-saas-nav-link <?php echo $default_tab === 'settings' ? 'active' : ''; ?>" data-tab="settings">
                        <span class="dashicons dashicons-admin-settings"></span> <span class="ofast-saas-nav-text">Settings</span>
                    </a>
                </nav>
                
                <!-- Toggle Button -->
                <div class="ofast-sidebar-toggle-btn" id="ofast-sidebar-toggle-btn" title="Toggle Sidebar">
                    <span class="dashicons dashicons-arrow-left-alt2" id="ofast-sidebar-toggle-icon"></span>
                </div>
            </div>

            <!-- Main Content Area -->
            <div class="ofast-saas-main" id="ofast-main-container">
                
                <!-- Tab Content Panels -->
                <div id="smtp-tab-dashboard" class="ofast-tab-content<?php echo $default_tab === 'dashboard' ? ' active' : ''; ?>"
                    style="<?php echo $default_tab !== 'dashboard' ? 'display:none;' : ''; ?>">
                    <?php $this->render_dashboard_page_content(); ?>
                </div>

                <div id="smtp-tab-log" class="ofast-tab-content<?php echo $default_tab === 'log' ? ' active' : ''; ?>"
                    style="<?php echo $default_tab !== 'log' ? 'display:none;' : ''; ?> padding: 30px;">
                    <?php $this->render_log_page_content(); ?>
                </div>

                <div id="smtp-tab-support" class="ofast-tab-content<?php echo $default_tab === 'support' ? ' active' : ''; ?>"
                    style="<?php echo $default_tab !== 'support' ? 'display:none;' : ''; ?> padding: 30px;">
                    <?php $this->render_support_page_content(); ?>
                </div>

                <div id="smtp-tab-settings" class="ofast-tab-content<?php echo $default_tab === 'settings' ? ' active' : ''; ?>"
                    style="<?php echo $default_tab !== 'settings' ? 'display:none;' : ''; ?> padding: 30px;">
                    <?php $this->render_settings_page_content(); ?>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Background Sync
                var tabs = document.querySelectorAll('.ofast-tab');
                var mainContainer = document.getElementById('ofast-main-container');
                
                function updateBackground(tabName) {
                    mainContainer.style.background = '#f8fafc'; // Light mode for all tabs
                }
                
                // Set initial
                updateBackground('<?php echo esc_js($default_tab); ?>');
                
                // Listen for clicks
                tabs.forEach(function(tab) {
                    tab.addEventListener('click', function(e) {
                        e.preventDefault();
                        updateBackground(this.getAttribute('data-tab'));
                    });
                });
                
                // Sidebar Toggle
                var toggleBtn = document.getElementById('ofast-sidebar-toggle-btn');
                var sidebar = document.getElementById('ofast-saas-sidebar');
                var toggleIcon = document.getElementById('ofast-sidebar-toggle-icon');
                
                toggleBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('collapsed');
                    if (sidebar.classList.contains('collapsed')) {
                        toggleIcon.classList.remove('dashicons-arrow-left-alt2');
                        toggleIcon.classList.add('dashicons-arrow-right-alt2');
                    } else {
                        toggleIcon.classList.remove('dashicons-arrow-right-alt2');
                        toggleIcon.classList.add('dashicons-arrow-left-alt2');
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
            $stats['success'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status IN ('success', 'sent')"));
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


        <!-- Dashboard SaaS Light Theme Wrapper -->
        <div style="background-color: transparent; padding: 30px; color: #1e293b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

            <!-- Top Row: Charts -->
            <div style="display: flex; gap: 20px; margin-bottom: 25px; flex-wrap: wrap;">
                <!-- Left: Delivery Success Rate Chart -->
                <div style="flex: 2; min-width: 400px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
                        <h3 style="margin: 0; font-size: 16px; font-weight: 600; color: #1e293b;">Delivery Success Rate</h3>
                        <div style="background: #f8fafc; padding: 5px 12px; border-radius: 6px; font-size: 12px; color: #64748b; border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 6px;">
                            <span class="dashicons dashicons-calendar-alt" style="font-size: 14px; width: 14px; height: 14px;"></span> Last 7 Days
                        </div>
                    </div>
                    
                    <!-- CSS Bar Chart (Simulating line chart fill) -->
                    <div style="height: 180px; display: flex; align-items: flex-end; justify-content: space-between; gap: 15px;">
                        <?php foreach ($weekly_data as $day): ?>
                            <?php
                            $bar_height = $max_weekly > 0 ? round(($day['count'] / $max_weekly) * 140) : 0;
                            $bar_height = max(10, $bar_height);
                            ?>
                            <div style="flex: 1; display: flex; flex-direction: column; align-items: center; height: 100%; justify-content: flex-end; position: relative; group;">
                                <div style="position: absolute; top: -25px; font-size: 11px; color: #64748b; font-weight: 600; opacity: 0; transition: opacity 0.2s;" class="ofast-chart-tooltip"><?php echo $day['count']; ?></div>
                                <div style="width: 100%; max-width: 40px; height: <?php echo $bar_height; ?>px; background: linear-gradient(to top, rgba(99, 102, 241, 0.1), rgba(139, 92, 246, 0.4)); border-top: 3px solid #8b5cf6; border-radius: 4px 4px 0 0; position: relative; cursor: pointer;" onmouseover="this.previousElementSibling.style.opacity=1" onmouseout="this.previousElementSibling.style.opacity=0">
                                    <div style="width: 8px; height: 8px; background: #8b5cf6; border-radius: 50%; position: absolute; top: -5px; left: 50%; transform: translateX(-50%); box-shadow: 0 0 10px rgba(139, 92, 246, 0.4);"></div>
                                </div>
                                <div style="font-size: 12px; color: #64748b; margin-top: 15px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em;"><?php echo esc_html($day['day']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Right: Connection Health -->
                <div style="flex: 1; min-width: 250px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; display: flex; flex-direction: column; align-items: center; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <div style="width: 100%; display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="margin: 0; font-size: 16px; font-weight: 600; color: #1e293b;">Connection Health</h3>
                        <span class="dashicons dashicons-ellipsis" style="color: #94a3b8; cursor: pointer;"></span>
                    </div>
                    
                    <?php
                    $health_color = $stats['rate'] >= 90 ? '#10b981' : ($stats['rate'] >= 70 ? '#f59e0b' : '#ef4444');
                    ?>
                    <!-- Circular Progress -->
                    <div style="width: 180px; height: 180px; border-radius: 50%; background: conic-gradient(<?php echo $health_color; ?> <?php echo $stats['rate']; ?>%, #f1f5f9 0); display: flex; align-items: center; justify-content: center; position: relative; margin: 20px 0;">
                        <!-- Inner Circle -->
                        <div style="width: 150px; height: 150px; border-radius: 50%; background: #ffffff; display: flex; flex-direction: column; align-items: center; justify-content: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                            <div style="font-size: 42px; font-weight: 700; color: #1e293b; line-height: 1;"><?php echo $stats['rate']; ?>%</div>
                            <div style="font-size: 14px; color: <?php echo $health_color; ?>; font-weight: 500; margin-top: 5px;">
                                <?php echo $stats['rate'] >= 90 ? 'Excellent' : ($stats['rate'] >= 70 ? 'Good' : 'Poor'); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Stats under health -->
                    <div style="display: flex; justify-content: space-between; width: 100%; padding-top: 15px; border-top: 1px solid #f1f5f9;">
                        <div>
                            <div style="font-size: 12px; color: #64748b; display: flex; align-items: center; gap: 6px;">
                                <span style="width: 8px; height: 8px; border-radius: 50%; background: #6366f1;"></span> Total
                            </div>
                            <div style="font-size: 18px; font-weight: 600; color: #1e293b; margin-top: 5px;"><?php echo number_format($stats['total']); ?></div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 12px; color: #64748b; display: flex; align-items: center; justify-content: flex-end; gap: 6px;">
                                <span style="width: 8px; height: 8px; border-radius: 50%; background: #10b981;"></span> Sent
                            </div>
                            <div style="font-size: 18px; font-weight: 600; color: #1e293b; margin-top: 5px;"><?php echo number_format($stats['success']); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Middle Row: 3 Stat Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 25px;">
                <!-- Total Sent Card -->
                <div style="background: #ffffff; border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.05); position: relative; overflow: hidden;">
                    <div style="position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: #10b981;"></div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 36px; height: 36px; border-radius: 8px; background: rgba(16, 185, 129, 0.1); display: flex; align-items: center; justify-content: center;">
                                <span class="dashicons dashicons-email-alt" style="color: #10b981;"></span>
                            </div>
                            <span style="font-size: 15px; color: #1e293b; font-weight: 600;">Total Sent</span>
                        </div>
                        <span class="dashicons dashicons-ellipsis" style="color: #94a3b8; cursor: pointer;"></span>
                    </div>
                    <div style="font-size: 38px; font-weight: 700; color: #1e293b;"><?php echo number_format($stats['total']); ?></div>
                    <div style="font-size: 13px; color: #10b981; margin-top: 8px; font-weight: 500;">+ All time</div>
                </div>
                
                <!-- Delivered Card -->
                <div style="background: #ffffff; border: 1px solid rgba(139, 92, 246, 0.2); border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px -1px rgba(139, 92, 246, 0.05); position: relative; overflow: hidden;">
                    <div style="position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: #8b5cf6;"></div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 36px; height: 36px; border-radius: 8px; background: rgba(139, 92, 246, 0.1); display: flex; align-items: center; justify-content: center;">
                                <span class="dashicons dashicons-saved" style="color: #8b5cf6;"></span>
                            </div>
                            <span style="font-size: 15px; color: #1e293b; font-weight: 600;">Delivered</span>
                        </div>
                        <span class="dashicons dashicons-ellipsis" style="color: #94a3b8; cursor: pointer;"></span>
                    </div>
                    <div style="font-size: 38px; font-weight: 700; color: #1e293b;"><?php echo number_format($stats['success']); ?></div>
                    <div style="font-size: 13px; color: #8b5cf6; margin-top: 8px; font-weight: 500;"><?php echo $stats['rate']; ?>% rate</div>
                </div>

                <!-- Bounced Card -->
                <div style="background: #ffffff; border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px -1px rgba(239, 68, 68, 0.05); position: relative; overflow: hidden;">
                    <div style="position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: #ef4444;"></div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 36px; height: 36px; border-radius: 8px; background: rgba(239, 68, 68, 0.1); display: flex; align-items: center; justify-content: center;">
                                <span class="dashicons dashicons-dismiss" style="color: #ef4444;"></span>
                            </div>
                            <span style="font-size: 15px; color: #1e293b; font-weight: 600;">Bounced</span>
                        </div>
                        <span class="dashicons dashicons-ellipsis" style="color: #94a3b8; cursor: pointer;"></span>
                    </div>
                    <div style="font-size: 38px; font-weight: 700; color: #1e293b;"><?php echo number_format($stats['failed']); ?></div>
                    <div style="font-size: 13px; color: #ef4444; margin-top: 8px; font-weight: 500;"><?php echo $stats['total'] > 0 ? round(($stats['failed'] / $stats['total']) * 100, 1) : 0; ?>% bounce rate</div>
                </div>
            </div>

            <!-- Bottom Row: Recent Activity -->
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <h3 style="margin: 0 0 25px 0; font-size: 16px; font-weight: 600; color: #1e293b;">Recent Activity</h3>
                <?php if (empty($recent_emails)): ?>
                    <p style="color: #64748b; text-align: center; padding: 30px 0;">No emails sent yet.</p>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; text-align: left;">
                            <thead>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <th style="padding: 0 10px 15px 10px; color: #64748b; font-size: 13px; font-weight: 500;">Recipient</th>
                                    <th style="padding: 0 10px 15px 10px; color: #64748b; font-size: 13px; font-weight: 500;">Subject</th>
                                    <th style="padding: 0 10px 15px 10px; color: #64748b; font-size: 13px; font-weight: 500;">Status</th>
                                    <th style="padding: 0 10px 15px 10px; color: #64748b; font-size: 13px; font-weight: 500; text-align: right;">Date/Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_emails as $email): ?>
                                    <tr style="border-bottom: 1px solid #f8fafc; transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                        <td style="padding: 16px 10px; font-size: 14px; color: #334155; font-weight: 500;">
                                            <?php echo esc_html($email->to_email); ?>
                                        </td>
                                        <td style="padding: 16px 10px; font-size: 14px; color: #475569; max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                            <?php echo esc_html($email->subject); ?>
                                        </td>
                                        <td style="padding: 16px 10px;">
                                            <?php if ($email->status === 'success' || $email->status === 'sent'): ?>
                                                <span style="border: 1px solid rgba(16, 185, 129, 0.3); color: #10b981; background: rgba(16, 185, 129, 0.1); padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 500;">Delivered</span>
                                            <?php elseif ($email->status === 'failed'): ?>
                                                <span style="border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; background: rgba(239, 68, 68, 0.1); padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 500;">Bounced</span>
                                            <?php else: ?>
                                                <span style="border: 1px solid rgba(99, 102, 241, 0.3); color: #8b5cf6; background: rgba(99, 102, 241, 0.1); padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 500;">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 16px 10px; text-align: right; font-size: 13px; color: #64748b;">
                                            <?php echo gmdate('M j, g:i A', strtotime($email->sent_at)); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php 
            $lifetime = Ofast_X_SMTP::get_delivery_stats();
            if ($lifetime['fallback_used'] > 0): 
            ?>
            <div style="margin-top: 25px; padding: 15px 25px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; display: flex; align-items: center; gap: 15px;">
                <span class="dashicons dashicons-update-alt" style="color: #f59e0b; font-size: 24px; width: 24px; height: 24px;"></span>
                <div style="color: #b45309; font-size: 14px;">
                    <strong>Fallback Activated:</strong> The system successfully recovered <?php echo number_format($lifetime['fallback_used']); ?> emails via the backup SMTP connection.
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Quick Actions -->
            <div style="margin-top: 25px; display: flex; gap: 15px; justify-content: flex-end;">
                <a href="<?php echo admin_url('admin.php?page=ofast-emailer&tab=history'); ?>" style="background: #fff; color: #475569; border: 1px solid #cbd5e1; padding: 8px 20px; border-radius: 8px; text-decoration: none; font-weight: 500; font-size: 14px; transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">View All Logs</a>
                <a href="<?php echo admin_url('admin.php?page=ofast-smtp&tab=settings'); ?>" style="background: #6366f1; color: #fff; border: none; padding: 8px 20px; border-radius: 8px; text-decoration: none; font-weight: 500; font-size: 14px; transition: background 0.2s;" onmouseover="this.style.background='#4f46e5'" onmouseout="this.style.background='#6366f1'">Configure Settings</a>
            </div>

        </div> <!-- End Dashboard SaaS Wrapper -->
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

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Resend is handled in handle_resend() on admin_init (before output)
        // CSV export is handled in handle_csv_export() on admin_init (before output)

        // Handle clear logs
        if (isset($_POST['clear_logs']) && isset($_POST['_wpnonce'])) {
            if (wp_verify_nonce(sanitize_text_field($_POST['_wpnonce']), 'clear_smtp_logs')) {
                $days = intval($_POST['clear_days'] ?? 30);
                $deleted = $this->clear_old_logs($days);
                Ofast_X_Toast::add("Deleted {$deleted} log entries older than {$days} days.", 'success');
            }
        }

        $allowed_per_page = array(10, 20, 50, 100);
        $per_page_input = isset($_GET['per_page']) ? sanitize_text_field($_GET['per_page']) : '20';
        $show_all = ($per_page_input === 'all');
        $per_page = $show_all ? 999999 : intval($per_page_input);
        if (!$show_all && !in_array($per_page, $allowed_per_page)) {
            $per_page = 20;
        }
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
            'success' => $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status IN ('success', 'sent')"),
            'failed' => $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'failed'"),
            'today' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE DATE(sent_at) = %s", current_time('Y-m-d')))
        );
        ?>


        <!-- Stats -->
        <div class="ofast-grid-4" style="margin: 20px 0;">
            <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb; text-align: center;">
                <div style="font-size: 32px; font-weight: bold; color: #6366f1;"><?php echo esc_html($stats['total']); ?></div>
                <div style="color: #6b7280;">Total Emails</div>
            </div>
            <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb; text-align: center;">
                <div style="font-size: 32px; font-weight: bold; color: #10b981;"><?php echo esc_html($stats['success']); ?>
                </div>
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
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ofast-smtp&tab=log&export_csv=1'), 'export_smtp_logs'); ?>"
                class="button button-secondary">Export CSV</a>
            <form method="post" style="display: flex; gap: 10px; align-items: center; margin: 0;">
                <?php wp_nonce_field('clear_smtp_logs'); ?>
                <span style="color: #6b7280;">Clear logs older than</span>
                <select name="clear_days" style="width: auto;">
                    <option value="7">7 days</option>
                    <option value="30" selected>30 days</option>
                    <option value="60">60 days</option>
                    <option value="90">90 days</option>
                </select>
                <button type="submit" name="clear_logs" class="button" onclick="return confirm('Are you sure?');">Clear Old
                    Logs</button>
            </form>

            <!-- Per-page selector -->
            <div style="margin-left: auto; display: flex; align-items: center; gap: 8px;">
                <span style="color: #6b7280; font-size: 13px;">Show</span>
                <select id="ofast-smtp-per-page"
                    style="width: auto; min-width: 70px; border: 1px solid #d1d5db; border-radius: 8px; padding: 6px 10px; font-size: 13px; background: #fff; color: #374151; cursor: pointer;">
                    <?php foreach (array(10, 20, 50, 100, 'all') as $opt): ?>
                        <option value="<?php echo esc_attr($opt); ?>" <?php selected($show_all ? 'all' : $per_page, $opt === 'all' ? 'all' : $opt); ?>>
                            <?php echo $opt === 'all' ? 'All' : $opt; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span style="color: #6b7280; font-size: 13px;">per page</span>
            </div>
        </div>

        <?php
        // Nonce for AJAX pagination
        $ajax_nonce = wp_create_nonce('ofast_smtp_logs_nonce');
        ?>

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
                <tbody id="ofast-smtp-log-tbody">
                    <?php echo $this->render_log_rows($logs); ?>
                </tbody>
            </table>
        </div>

        <?php
        $total_pages = $show_all ? 1 : ceil($total / $per_page);
        $showing_start = $total > 0 ? $offset + 1 : 0;
        $showing_end = min($offset + $per_page, $total);
        ?>



        <div id="ofast-smtp-pagination-wrap">
            <?php echo $this->render_pagination_bar($current_page, $total_pages, $total, $per_page, $show_all, $offset); ?>
        </div>



        <!-- Preview Modal -->
        <div id="email-preview-modal"
            style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 100000;">
            <div class="ofast-smtp-modal-body"
                style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; border-radius: 8px; width: 90%; max-width: 700px; max-height: 80vh; overflow: hidden;">
                <div
                    style="padding: 15px 20px; background: #f8fafc; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0;">Email Preview</h3>
                    <button type="button" id="close-preview"
                        style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
                </div>

                <?php if (get_option('ofast_smtp_log_body_content', false)): ?>
                    <div
                        style="padding: 10px 20px; background: #fffbeb; border-bottom: 1px solid #f59e0b; color: #92400e; font-size: 13px;">
                        <strong>🔒 Security Notice:</strong> Sensitive patterns (passwords, tokens, API keys) have been
                        automatically filtered from this preview.
                    </div>
                <?php endif; ?>

                <iframe id="email-preview-frame" style="width: 100%; height: 60vh; border: none;"></iframe>
            </div>
        </div>

        <?php
    }

    /**
     * Render log table rows HTML (reused by both SSR and AJAX)
     */
    private function render_log_rows($logs)
    {
        if (empty($logs)) {
            return '<tr><td colspan="6" style="text-align: center; padding: 40px; color: #6b7280;">No emails logged yet.</td></tr>';
        }
        $html = '';
        foreach ($logs as $log) {
            $status = strtolower((string) $log->status);
            $html .= '<tr>';
            $html .= '<td>' . esc_html($log->id) . '</td>';
            $html .= '<td>' . esc_html($log->to_email) . '</td>';
            $html .= '<td>' . esc_html($log->subject) . '</td>';
            $html .= '<td>';
            if (in_array($status, array('success', 'sent', 'resent'), true)) {
                $label = $status === 'resent' ? 'RESENT' : 'SUCCESS';
                $html .= '<span style="background: #d1fae5; color: #065f46; padding: 2px 8px; border-radius: 3px; font-size: 11px;">' . $label . '</span>';
            } elseif ($status === 'failed') {
                $html .= '<span style="background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 3px; font-size: 11px;">FAILED</span>';
            } elseif ($status === 'rate_limited') {
                $html .= '<span style="background: #ffedd5; color: #9a3412; padding: 2px 8px; border-radius: 3px; font-size: 11px;">RATE LIMITED</span>';
            } else {
                $html .= '<span style="background: #e0f2fe; color: #0f4c81; padding: 2px 8px; border-radius: 3px; font-size: 11px;">PENDING</span>';
            }
            $html .= '</td>';
            $html .= '<td>' . esc_html($log->sent_at) . '</td>';
            $html .= '<td>';
            if (!empty($log->body)) {
                $html .= '<button type="button" class="button button-small preview-email" style="border-radius: 25px;" data-id="' . esc_attr($log->id) . '" data-content="' . esc_attr(base64_encode($log->body)) . '">Preview</button>';
            } else {
                $html .= '<span style="color: #6b7280; font-style: italic;">No content stored</span>';
            }
            // Resend: available for failed and delivered rows (when body is stored).
            // Pending is excluded — the row may still be in-flight and resending would duplicate it.
            $can_resend = in_array($status, array('failed', 'success', 'sent', 'resent'), true);
            if ($can_resend && !empty($log->body)) {
                $html .= ' <a href="' . wp_nonce_url(admin_url('admin.php?page=ofast-smtp&tab=log&resend=' . $log->id), 'resend_email') . '" class="button button-small" style="border-radius: 25px;">Resend</a>';
            } elseif ($can_resend) {
                $html .= '<span style="color: #6b7280; font-style: italic; margin-left: 8px;">Resend unavailable</span>';
            }
            $html .= '</td>';
            $html .= '</tr>';
        }
        return $html;
    }

    /**
     * Render pagination bar HTML (reused by both SSR and AJAX)
     */
    private function render_pagination_bar($current_page, $total_pages, $total, $per_page, $show_all, $offset)
    {
        $showing_start = $total > 0 ? $offset + 1 : 0;
        $showing_end = min($offset + $per_page, $total);

        $html = '<div class="ofast-pagination">';
        $html .= '<div class="ofast-pagination-info">';
        $html .= 'Showing <strong>' . esc_html($showing_start) . '–' . esc_html($showing_end) . '</strong> of <strong>' . esc_html($total) . '</strong> emails';
        $html .= '</div>';

        if ($total_pages > 1) {
            $html .= '<div class="ofast-pagination-pages">';

            // Prev button
            $prev_disabled = $current_page <= 1 ? ' disabled' : '';
            $html .= '<a href="#" class="ofast-page-btn' . $prev_disabled . '" data-page="' . max(1, $current_page - 1) . '" title="Previous page">';
            $html .= '<span class="dashicons dashicons-arrow-left-alt2" style="font-size: 16px; width: 16px; height: 16px; line-height: 16px;"></span>';
            $html .= '</a>';

            // Page numbers with smart ellipsis
            $range = 2;
            for ($i = 1; $i <= $total_pages; $i++) {
                if ($i === 1 || $i === $total_pages || ($i >= $current_page - $range && $i <= $current_page + $range)) {
                    $active = $i === $current_page ? ' active' : '';
                    $html .= '<a href="#" class="ofast-page-btn' . $active . '" data-page="' . $i . '">' . $i . '</a>';
                } elseif ($i === $current_page - $range - 1 || $i === $current_page + $range + 1) {
                    $html .= '<span class="ofast-page-ellipsis">…</span>';
                }
            }

            // Next button
            $next_disabled = $current_page >= $total_pages ? ' disabled' : '';
            $html .= '<a href="#" class="ofast-page-btn' . $next_disabled . '" data-page="' . min($total_pages, $current_page + 1) . '" title="Next page">';
            $html .= '<span class="dashicons dashicons-arrow-right-alt2" style="font-size: 16px; width: 16px; height: 16px; line-height: 16px;"></span>';
            $html .= '</a>';

            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * AJAX handler: Fetch log rows + pagination for instant navigation
     */
    public function ajax_fetch_logs()
    {
        check_ajax_referer('ofast_smtp_logs_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ofast_smtp_log';

        $allowed_per_page = array(10, 20, 50, 100);
        $per_page_input = isset($_POST['per_page']) ? sanitize_text_field($_POST['per_page']) : '20';
        $show_all = ($per_page_input === 'all');
        $per_page = $show_all ? 999999 : intval($per_page_input);
        if (!$show_all && !in_array($per_page, $allowed_per_page)) {
            $per_page = 20;
        }

        $current_page = max(1, intval($_POST['paged'] ?? 1));
        $offset = ($current_page - 1) * $per_page;

        $total = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"));
        $total_pages = $show_all ? 1 : (int) ceil($total / $per_page);

        // Clamp page
        if ($current_page > $total_pages && $total_pages > 0) {
            $current_page = $total_pages;
            $offset = ($current_page - 1) * $per_page;
        }

        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} ORDER BY sent_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));

        wp_send_json_success(array(
            'rows_html' => $this->render_log_rows($logs),
            'pagination_html' => $this->render_pagination_bar($current_page, $total_pages, $total, $per_page, $show_all, $offset),
            'current_page' => $current_page,
            'total_pages' => $total_pages,
            'total' => $total,
        ));
    }

    public function handle_support_form()
    {
        if (!isset($_POST['ofast_smtp_support_submit'])) {
            return;
        }

        check_admin_referer('ofast_smtp_support_form', '_wpnonce_support');

        if (!current_user_can('manage_options')) {
            return;
        }

        $support_type = sanitize_key($_POST['support_type'] ?? 'bug');
        $support_email = sanitize_email(wp_unslash($_POST['support_email'] ?? ''));
        $support_subject = sanitize_text_field(wp_unslash($_POST['support_subject'] ?? ''));
        $support_message = isset($_POST['support_message']) ? wp_kses_post(wp_unslash($_POST['support_message'])) : '';

        if (empty($support_message)) {
            Ofast_X_Toast::add('Please describe your issue before sending the request.', 'error');
            return;
        }

        $recipient = get_option('admin_email', 'support@ofastshop.com');
        $reply_to = $support_email ?: $recipient;
        $site_name = get_bloginfo('name');
        $message_type = $support_type === 'contact' ? 'Support request' : 'Bug report';
        $subject = $support_subject ?: sprintf('[%s] %s from %s', strtoupper($support_type === 'contact' ? 'support' : 'bug'), $message_type, $site_name);

        $diagnostics = array(
            'Site' => $site_name . ' (' . site_url() . ')',
            'PHP Version' => PHP_VERSION,
            'WordPress Version' => get_bloginfo('version'),
            'Plugin Version' => OFAST_X_VERSION,
            'SMTP Enabled' => get_option('ofast_smtp_enabled', false) ? 'Yes' : 'No',
            'Mailer Type' => get_option('ofast_smtp_mailer_type', 'default'),
            'Provider' => get_option('ofast_smtp_provider', 'custom'),
            'Host' => get_option('ofast_smtp_host', ''),
            'From Email' => get_option('ofast_smtp_from_email', ''),
            'Last SMTP Error' => get_option('ofast_smtp_last_error', 'None'),
        );

        $body = "Hello Ofast Support,\n\n";
        $body .= "A new {$message_type} was submitted from {$site_name}.\n\n";
        $body .= "Contact Email: {$reply_to}\n";
        $body .= "Message:\n{$support_message}\n\n";
        $body .= "System Diagnostics:\n";
        foreach ($diagnostics as $label => $value) {
            $body .= '- ' . $label . ': ' . $value . "\n";
        }
        $body .= "\nPlease reply to this message directly for follow-up.";

        $headers = array('Reply-To: ' . $reply_to);

        $sent = wp_mail($recipient, $subject, $body, $headers);

        if ($sent) {
            update_option('ofast_smtp_last_support_request', current_time('mysql'));
            Ofast_X_Toast::add('Your support request was sent successfully. We will follow up soon.', 'success');
        } else {
            update_option('ofast_smtp_last_support_request', current_time('mysql'));
            Ofast_X_Toast::add('Your request was saved locally, but the email could not be sent. Please copy the details and contact support manually.', 'error');
        }
    }

    private function render_support_page_content()
    {
        $default_email = get_option('admin_email', '');
        $default_subject = 'SMTP issue report';
        ?>
        <div style="max-width: 900px;">
            <div style="background: linear-gradient(135deg, #312e81, #4338ca); color: #fff; border-radius: 16px; padding: 28px 30px; margin-bottom: 24px; box-shadow: 0 10px 25px rgba(49, 46, 129, 0.2);">
                <h2 style="margin: 0 0 8px 0; font-size: 24px;">Help & Support</h2>
                <p style="margin: 0; color: rgba(255,255,255,0.9); font-size: 15px; line-height: 1.6;">Report a bug, ask for help, or contact us directly from within the SMTP dashboard. We’ll receive your message along with useful diagnostics so troubleshooting is faster.</p>
            </div>

            <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                <form method="post">
                    <?php wp_nonce_field('ofast_smtp_support_form', '_wpnonce_support'); ?>

                    <table class="form-table" style="margin-top: 0;">
                        <tr>
                            <th scope="row"><label for="support_type">What do you need?</label></th>
                            <td>
                                <select name="support_type" id="support_type" style="min-width: 220px;">
                                    <option value="bug">Report a bug</option>
                                    <option value="contact">Contact support</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="support_email">Your email</label></th>
                            <td>
                                <input type="email" name="support_email" id="support_email" value="<?php echo esc_attr($default_email); ?>" class="regular-text" placeholder="you@example.com">
                                <p class="description" style="margin-top: 6px;">We’ll use this address for follow-up.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="support_subject">Subject</label></th>
                            <td>
                                <input type="text" name="support_subject" id="support_subject" value="<?php echo esc_attr($default_subject); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="support_message">Details</label></th>
                            <td>
                                <textarea name="support_message" id="support_message" rows="8" class="large-text code" placeholder="Describe the issue you are seeing, what you expected to happen, and any error messages you saw."></textarea>
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top: 12px; padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; color: #475569; font-size: 13px; line-height: 1.6;">
                        <strong>Included automatically:</strong> site URL, PHP version, WordPress version, plugin version, SMTP status, current mailer settings, and the latest SMTP error (if any).
                    </div>

                    <p class="submit" style="margin-top: 18px;">
                        <button type="submit" name="ofast_smtp_support_submit" value="1" class="button button-primary">Send request</button>
                    </p>
                </form>
            </div>
        </div>
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
        $log_retention_days = intval(get_option('ofast_smtp_log_retention_days', 90));
        $presets = Ofast_X_SMTP::get_provider_presets();

        // Include Ofast Dropdown assets
        if (class_exists('Ofast_X_Dropdown')) {
            echo Ofast_X_Dropdown::render_assets();
        }
        ?>

        <?php
        $is_active = $enabled && ($mailer_type === 'default' || (!empty($host) && !empty($username)));
        $mailer_name = $mailer_type === 'default' ? 'PHP Mail (Default)' : ($presets[$provider]['name'] ?? 'Custom SMTP');
        ?>
        <!-- Connection Status -->
        <div class="ofast-grid-3" style="margin: 25px 0;">
            <div style="background: <?php echo $is_active ? 'linear-gradient(135deg, #10b981, #059669)' : 'linear-gradient(135deg, #6b7280, #4b5563)'; ?>; padding: 25px; border-radius: 12px; color: #fff; text-align: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
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
            <div style="background: linear-gradient(135deg, #6366f1, #4f46e5); padding: 25px; border-radius: 12px; color: #fff; text-align: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">Provider</div>
                <div style="font-size: 18px; font-weight: 600;">
                    <?php echo $mailer_type === 'default' ? 'Server Mail' : ($host ? esc_html($host) : 'Not Set'); ?>
                </div>
                <div style="font-size: 13px; opacity: 0.9; margin-top: 5px;">
                    <?php echo $mailer_type === 'default' ? 'PHP mail() function' : 'Port ' . esc_html(get_option('ofast_smtp_port', 587)) . ' / ' . strtoupper(esc_html($encryption)); ?>
                </div>
            </div>
            <div style="background: linear-gradient(135deg, #3b82f6, #2563eb); padding: 25px; border-radius: 12px; color: #fff; text-align: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">From Address</div>
                <div style="font-size: 16px; font-weight: 600; word-break: break-all;">
                    <?php echo $from_email ? esc_html($from_email) : 'Not Set'; ?>
                </div>
            </div>
        </div>

        <div class="ofast-layout-sidebar">
            <div style="min-width: 0;">

        <form method="post" id="ofast-smtp-form">
            <?php wp_nonce_field('ofast_smtp_settings', '_wpnonce'); ?>

            <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin-bottom: 0;">
                <h3 style="margin-top: 0;">SMTP Configuration</h3>
                <p style="color: #64748b;">Configure your mailer type, server credentials, sender identity, and rate limits.</p>

                <table class="form-table">
                    <tr>
                        <th>Enable SMTP</th>
                        <td>
                            <label class="ofast-toggle">
                                <input type="checkbox" name="smtp_enabled" value="1" <?php checked($enabled); ?>>
                                <span class="ofast-slider"></span>
                            </label>
                            <span style="vertical-align: middle; font-weight: 500;">Enable SMTP</span>
                            <span class="ofast-tooltip-wrap" style="margin-left: 8px;">
                                <span class="ofast-tooltip-icon">?</span>
                                <span class="ofast-tooltip-text">When enabled, all emails will be sent through your configured mailer.</span>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>Mailer Type</th>
                        <td>
                            <select name="smtp_mailer_type" id="smtp_mailer_type" class="ofast-dropdown-native"
                                style="width: 200px;">
                                <option value="default" <?php selected($mailer_type, 'default'); ?>>PHP Mail (Default) </option>
                                <option value="smtp" <?php selected($mailer_type, 'smtp'); ?>>Custom server
                                </option>
                            </select>
                            <span class="ofast-tooltip-wrap">
                                <span class="ofast-tooltip-icon">?</span>
                                <span class="ofast-tooltip-text"
                                    id="mailer_note"><?php echo $mailer_type === 'default' ? 'Uses your server\'s built-in mail function. Only From Email/Name needed. Best for most hosts.' : 'Requires SMTP server credentials. Better deliverability with providers like SendGrid, Mailgun.'; ?></span>
                            </span>
                        </td>
                    </tr>
                </table>

                <!-- SMTP Provider (only for smtp mailer type) -->
                <div id="smtp-credentials-section" style="<?php echo $mailer_type === 'default' ? 'display:none;' : ''; ?>">
                    <table class="form-table">
                        <tr>
                            <th>Email Provider</th>
                            <td>
                                <select name="smtp_provider" id="smtp_provider" class="ofast-dropdown-native"
                                    style="width: 200px;">
                                    <?php foreach ($presets as $key => $preset): ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($provider, $key); ?>>
                                            <?php echo esc_html($preset['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="ofast-tooltip-wrap">
                                    <span class="ofast-tooltip-icon">?</span>
                                    <span class="ofast-tooltip-text"
                                        id="provider_note"><?php echo esc_html($presets[$provider]['note'] ?? ''); ?></span>
                                </span>
                            </td>
                        </tr>
                    </table>

                    <h2 style="margin-top: 30px;">Connection Settings</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="smtp_host">SMTP Host *</label></th>
                            <td><input type="text" name="smtp_host" id="smtp_host" value="<?php echo esc_attr($host); ?>"
                                    class="regular-text" placeholder="smtp.example.com"></td>
                        </tr>
                        <tr>
                            <th><label for="smtp_port">SMTP Port *</label></th>
                            <td>
                                <input type="number" name="smtp_port" id="smtp_port" value="<?php echo esc_attr($port); ?>"
                                    style="width: 100px;">
                                <span class="description">Common: 587 (TLS), 465 (SSL), 25 (None)</span>
                            </td>
                        </tr>
                        <tr>
                            <th>Encryption</th>
                            <td>
                                <div class="ofast-encryption-group">
                                    <label class="<?php echo $encryption === 'tls' ? 'active' : ''; ?>">
                                        <input type="radio" name="smtp_encryption" value="tls" <?php checked($encryption, 'tls'); ?>>
                                        <span>TLS (Recommended)</span>
                                    </label>
                                    <label class="<?php echo $encryption === 'ssl' ? 'active' : ''; ?>">
                                        <input type="radio" name="smtp_encryption" value="ssl" <?php checked($encryption, 'ssl'); ?>>
                                        <span>SSL</span>
                                    </label>
                                    <label class="<?php echo $encryption === 'none' ? 'active' : ''; ?>">
                                        <input type="radio" name="smtp_encryption" value="none" <?php checked($encryption, 'none'); ?>>
                                        <span>None</span>
                                    </label>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <h2 style="margin-top: 30px;">Authentication</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="smtp_username">Username *</label></th>
                            <td><input type="text" name="smtp_username" id="smtp_username"
                                    value="<?php echo esc_attr($username); ?>" class="regular-text"
                                    placeholder="your@email.com or apikey"></td>
                        </tr>
                        <tr>
                            <th><label for="smtp_password">Password *</label></th>
                            <td>
                                <input type="password" name="smtp_password" id="smtp_password"
                                    value="<?php echo $password ? '••••••••' : ''; ?>" class="regular-text"
                                    placeholder="Enter password or API key">
                                <button type="button" class="button button-small"
                                    onclick="this.previousElementSibling.type = this.previousElementSibling.type === 'password' ? 'text' : 'password'">Show/Hide</button>
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
                            <input type="email" name="smtp_from_email" id="smtp_from_email"
                                value="<?php echo esc_attr($from_email); ?>" class="regular-text"
                                placeholder="noreply@yoursite.com">
                            <p class="description">The email address shown as sender</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="smtp_from_name">From Name</label></th>
                        <td><input type="text" name="smtp_from_name" id="smtp_from_name"
                                value="<?php echo esc_attr($from_name); ?>" class="regular-text"
                                placeholder="Your Website Name"></td>
                    </tr>
                </table>

                <div id="rate-limit-section" style="<?php echo $mailer_type === 'default' ? 'display:none;' : ''; ?>">
                    <h2 style="margin-top: 30px;">Rate Limiting</h2>
                    <table class="form-table">
                        <tr>
                            <th>Enable Rate Limiting</th>
                            <td>
                                <label class="ofast-toggle">
                                    <input type="checkbox" name="rate_limit_enabled" value="1" <?php checked(get_option('ofast_smtp_rate_limit_enabled', true)); ?>         <?php ofast_toolkit_pro_disabled(); ?>>
                                    <span class="ofast-slider"></span>
                                </label>
                                <span style="vertical-align: middle;">Limit emails per minute
                                    <?php ofast_toolkit_pro_badge(); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="rate_limit">Max Emails/Minute</label></th>
                            <td>
                                <input type="number" name="rate_limit" id="rate_limit"
                                    value="<?php echo esc_attr(get_option('ofast_smtp_rate_limit', 60)); ?>" min="1" max="500"
                                    style="width: 80px;">
                                <span class="description">Recommended: 30-60 for shared hosting</span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Email Logging Settings -->
            <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin: 30px 0;"<?php echo ! ofast_toolkit_is_pro() ? ' class="ofast-pro-locked-section"' : ''; ?>>
                <?php if ( ! ofast_toolkit_is_pro() ): ?>
                <div class="ofast-pro-overlay">
                    <div class="ofast-pro-lock-icon"><span class="dashicons dashicons-lock"></span></div>
                    <div class="ofast-pro-overlay-text">Pro Feature</div>
                    <div class="ofast-pro-overlay-desc">Control what email content is stored and set automatic log cleanup schedules.</div>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ofast-license')); ?>" class="ofast-pro-upgrade-btn">
                        <span class="dashicons dashicons-star-filled" style="font-size:14px;width:14px;height:14px;"></span> Upgrade to Pro
                    </a>
                </div>
                <?php endif; ?>
                <h3 style="margin-top: 0;">Email Logging</h3>
                <p style="color: #64748b;">
                    Control what data is stored in your email history logs.
                </p>

                <table class="form-table">
                    <tr>
                        <th>Log Email Content</th>
                        <td>
                            <?php $log_body = get_option('ofast_smtp_log_body_content', false); ?>
                            <label class="ofast-toggle">
                                <input type="checkbox" name="log_body_content" value="1" <?php checked($log_body); ?>>
                                <span class="ofast-slider"></span>
                            </label>
                            <span style="vertical-align: middle;">Log email body content</span>
                            <span class="ofast-tooltip-wrap" style="margin-left: 8px;">
                                <span class="ofast-tooltip-icon">?</span>
                                <span class="ofast-tooltip-text">Enabled by default — Email body content is stored for Preview &amp; Resend functionality. When enabled, sensitive patterns (passwords, tokens, API keys) are automatically filtered before storage. Disable to log only metadata (to, subject, status, timestamp).</span>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>Log Retention (Days)</th>
                        <td>
                            <input type="number" name="log_retention_days" value="<?php echo esc_attr($log_retention_days); ?>"
                                min="0" max="3650" style="width: 90px;">
                            <span class="ofast-tooltip-wrap" style="margin-left: 8px;">
                                <span class="ofast-tooltip-icon">?</span>
                                <span class="ofast-tooltip-text">Set to 0 to keep logs forever. Default is 90 days.</span>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- ============================================ -->
            <!-- Fallback SMTP Server -->
            <!-- ============================================ -->
            <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin: 30px 0;"<?php echo ! ofast_toolkit_is_pro() ? ' class="ofast-pro-locked-section"' : ''; ?>>
                <?php if ( ! ofast_toolkit_is_pro() ): ?>
                <div class="ofast-pro-overlay">
                    <div class="ofast-pro-lock-icon"><span class="dashicons dashicons-lock"></span></div>
                    <div class="ofast-pro-overlay-text">Pro Feature</div>
                    <div class="ofast-pro-overlay-desc">Automatically retry failed emails through a backup SMTP server for maximum deliverability.</div>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ofast-license')); ?>" class="ofast-pro-upgrade-btn">
                        <span class="dashicons dashicons-star-filled" style="font-size:14px;width:14px;height:14px;"></span> Upgrade to Pro
                    </a>
                </div>
                <?php endif; ?>
                <h3 style="margin-top: 0;">Fallback SMTP Server</h3>
                <p style="color: #64748b;">If your primary SMTP server fails, emails will automatically retry using this backup
                    server.</p>

                <table class="form-table">
                    <tr>
                        <th>Enable Fallback</th>
                        <td>
                            <label class="ofast-toggle">
                                <input type="checkbox" name="fallback_enabled" id="fallback_enabled" value="1" <?php checked(get_option('ofast_smtp_fallback_enabled', false)); ?>         <?php ofast_toolkit_pro_disabled(); ?>>
                                <span class="ofast-slider"></span>
                            </label>
                            <span style="color: #64748b;">Automatically retry failed emails via backup server
                                <?php ofast_toolkit_pro_badge(); ?></span>
                        </td>
                    </tr>
                </table>

                <div id="fallback-smtp-fields"
                    style="<?php echo get_option('ofast_smtp_fallback_enabled', false) ? '' : 'display:none;'; ?> background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; margin-top: 10px;">
                    <table class="form-table">
                        <tr>
                            <th>Fallback Host</th>
                            <td><input type="text" name="fallback_host"
                                    value="<?php echo esc_attr(get_option('ofast_smtp_fallback_host', '')); ?>"
                                    class="regular-text" placeholder="smtp.backup-server.com"></td>
                        </tr>
                        <tr>
                            <th>Fallback Port</th>
                            <td><input type="number" name="fallback_port"
                                    value="<?php echo esc_attr(get_option('ofast_smtp_fallback_port', 587)); ?>"
                                    style="width: 100px;"></td>
                        </tr>
                        <tr>
                            <th>Fallback Encryption</th>
                            <td>
                                <select name="fallback_encryption">
                                    <option value="tls" <?php selected(get_option('ofast_smtp_fallback_encryption', 'tls'), 'tls'); ?>>TLS</option>
                                    <option value="ssl" <?php selected(get_option('ofast_smtp_fallback_encryption', 'tls'), 'ssl'); ?>>SSL</option>
                                    <option value="none" <?php selected(get_option('ofast_smtp_fallback_encryption', 'tls'), 'none'); ?>>None</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Fallback Username</th>
                            <td><input type="text" name="fallback_username"
                                    value="<?php echo esc_attr(get_option('ofast_smtp_fallback_username', '')); ?>"
                                    class="regular-text"></td>
                        </tr>
                        <tr>
                            <th>Fallback Password</th>
                            <td><input type="password" name="fallback_password"
                                    value="<?php echo get_option('ofast_smtp_fallback_password', '') ? '••••••••' : ''; ?>"
                                    class="regular-text" autocomplete="new-password"></td>
                        </tr>
                        <tr>
                            <th>Fallback From Email</th>
                            <td><input type="email" name="fallback_from_email"
                                    value="<?php echo esc_attr(get_option('ofast_smtp_fallback_from_email', '')); ?>"
                                    class="regular-text" placeholder="backup@yourdomain.com"></td>
                        </tr>
                        <tr>
                            <th>Fallback From Name</th>
                            <td><input type="text" name="fallback_from_name"
                                    value="<?php echo esc_attr(get_option('ofast_smtp_fallback_from_name', '')); ?>"
                                    class="regular-text"></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- Email Health Reports -->
            <!-- ============================================ -->
            <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin: 30px 0;"<?php echo ! ofast_toolkit_is_pro() ? ' class="ofast-pro-locked-section"' : ''; ?>>
                <?php if ( ! ofast_toolkit_is_pro() ): ?>
                <div class="ofast-pro-overlay">
                    <div class="ofast-pro-lock-icon"><span class="dashicons dashicons-lock"></span></div>
                    <div class="ofast-pro-overlay-text">Pro Feature</div>
                    <div class="ofast-pro-overlay-desc">Get automated daily, weekly, or monthly email health digests sent to your inbox.</div>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ofast-license')); ?>" class="ofast-pro-upgrade-btn">
                        <span class="dashicons dashicons-star-filled" style="font-size:14px;width:14px;height:14px;"></span> Upgrade to Pro
                    </a>
                </div>
                <?php endif; ?>
                <h3 style="margin-top: 0;">Email Health Reports</h3>
                <p style="color: #64748b;">Receive automated email reports summarizing your send/fail statistics.</p>

                <table class="form-table">
                    <tr>
                        <th>Enable Health Reports</th>
                        <td>
                            <label class="ofast-toggle">
                                <input type="checkbox" name="health_report_enabled" value="1" <?php checked(get_option('ofast_smtp_health_report_enabled', false)); ?>         <?php ofast_toolkit_pro_disabled(); ?>>
                                <span class="ofast-slider"></span>
                            </label>
                            <span style="color: #64748b;">Send digest email to admin <?php ofast_toolkit_pro_badge(); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th>Report Interval</th>
                        <td>
                            <select name="health_report_interval" class="ofast-dropdown-native" style="width: 200px;">
                                <?php $hr_interval = get_option('ofast_smtp_health_report_interval', 'weekly'); ?>
                                <option value="daily" <?php selected($hr_interval, 'daily'); ?>>Daily</option>
                                <option value="weekly" <?php selected($hr_interval, 'weekly'); ?>>Weekly</option>
                                <option value="monthly" <?php selected($hr_interval, 'monthly'); ?>>Monthly</option>
                            </select>
                            <span class="ofast-tooltip-wrap" style="margin-left: 8px;">
                                <span class="ofast-tooltip-icon">?</span>
                                <span class="ofast-tooltip-text">Reports are sent to <?php echo esc_html(get_option('admin_email')); ?></span>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>

            <p class="submit">
                <button type="submit" name="ofast_smtp_save" class="button button-primary button-large">Save SMTP
                    Settings</button>
            </p>
        </form>






        <!-- ============================================ -->
        <!-- Port Connectivity Test -->
        <!-- ============================================ -->
        <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin: 30px 0;">
            <h3 style="margin-top: 0;">Port Connectivity Test</h3>
            <p style="color: #64748b; margin-bottom: 20px;">Probe your SMTP server to check which ports are open, auth methods, and detect security issues.</p>
            
            <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                <input type="text" id="port-test-hostname" placeholder="smtp.yourdomain.com"
                    value="<?php echo esc_attr(get_option('ofast_smtp_host', '')); ?>" class="regular-text"
                    style="border-radius: 8px; border: 1px solid #d7deea; padding: 8px 12px; min-width: 280px;">
                <button type="button" id="ofast-run-port-test" class="button button-primary"
                    style="background: linear-gradient(135deg, #6366f1, #4f46e5) !important; border-color: #6366f1 !important; text-shadow: none !important; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3) !important; padding: 8px 20px !important; height: auto !important; border-radius: 8px !important; font-weight: 600 !important;">Test Ports</button>
                <span id="port-test-spinner" class="spinner" style="float: none; margin-top: 0;"></span>
            </div>

            <div id="port-test-results" style="display: none; margin-top: 25px;">
                <!-- Tab navigation -->
                <div id="port-tabs" style="display: flex; border-bottom: 2px solid #e5e7eb; gap: 20px; overflow-x: auto;"></div>
                <!-- Tab content -->
                <div id="port-tab-content" style="padding: 20px 0;"></div>
            </div>
        </div>

        <!-- DNS Checker Section -->
        <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin: 30px 0;">
            <h3 style="margin-top: 0;">Email Authentication (DNS)</h3>
            <?php
            if (file_exists(OFAST_X_PLUGIN_DIR . 'modules/smtp/class-ofast-smtp-dns.php')) {
                require_once OFAST_X_PLUGIN_DIR . 'modules/smtp/class-ofast-smtp-dns.php';
                Ofast_X_SMTP_DNS::get_instance()->render_checker_ui();
            }
            ?>
        </div>

        <!-- Test Connection (uses saved settings) -->
        <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin: 30px 0;">
            <h3 style="margin-top: 0;">Test Connection</h3>
            <p>Send a test email to verify your saved settings are working.</p>
            <button type="button" id="test-smtp-btn" class="button button-secondary"
                style="border-radius: 8px !important; padding: 8px 18px !important; font-weight: 500 !important; transition: all 0.2s !important; border: 1px solid #d7deea !important;">Send
                Test Email to
                <?php echo esc_html(get_option('admin_email')); ?></button>
            <span id="test-result" style="margin-left: 15px;"></span>
            <div id="test-details" style="margin-top: 15px; display: none;">
                <pre style="background: #1e293b; color: #10b981; padding: 15px; border-radius: 8px; overflow-x: auto;"></pre>
            </div>
        </div>


        
        </div> <!-- End Left Column -->

        <!-- Right Column (Video & Troubleshooting) -->
        <div style="min-width: 0;">
            <!-- Setup Guide Video -->
            <div style="background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); cursor: pointer; position: relative; margin-bottom: 25px;">
                <div id="ofast-inline-video-wrapper" style="height: 200px; position: relative; display: flex; align-items: center; justify-content: center; background-color: #0f172a; overflow: hidden; margin: 0; padding: 0;" class="ofast-video-container" data-video-id="0dcd5bLtYs8">
                    <img src="https://img.youtube.com/vi/0dcd5bLtYs8/maxresdefault.jpg" onerror="this.src='https://img.youtube.com/vi/0dcd5bLtYs8/hqdefault.jpg';" alt="SMTP Setup Video" style="position: absolute; width: 100%; height: 100%; object-fit: cover; opacity: 0.7; transition: opacity 0.3s ease;">
                    <div style="position: absolute; width: 100%; height: 100%; background: linear-gradient(135deg, rgba(30,27,75,0.4) 0%, rgba(76,29,149,0.4) 100%); pointer-events: none;"></div>
                    <div style="width: 64px; height: 64px; background: #8b5cf6; border-radius: 50%; display: flex; align-items: center; justify-content: center; z-index: 2; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4); transition: transform 0.2s ease, background 0.2s ease;" class="ofast-play-btn">
                        <div style="width: 0; height: 0; border-top: 10px solid transparent; border-bottom: 10px solid transparent; border-left: 16px solid #fff; margin-left: 6px;"></div>
                    </div>
                </div>
            </div>

            <!-- Troubleshooting & Expert Help -->
            <div style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 25px;">
                <h3 style="margin: 0 0 20px 0; font-size: 18px; font-weight: 600; color: #1e293b;">Troubleshooting</h3>
                <ul style="margin: 0; padding: 0; list-style: none;">
                    <li style="margin-bottom: 16px;"><a href="#" style="text-decoration: none; color: #4b5563; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 14px;"><span class="dashicons dashicons-email" style="color: #3b82f6;"></span> Send test email</a></li>
                    <li style="margin-bottom: 16px;"><a href="#" style="text-decoration: none; color: #4b5563; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 14px;"><span class="dashicons dashicons-external" style="color: #3b82f6;"></span> Spam Score Checker</a></li>
                    <li style="margin-bottom: 16px;"><a href="#" style="text-decoration: none; color: #4b5563; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 14px;"><span class="dashicons dashicons-update" style="color: #3b82f6;"></span> Import/Export</a></li>
                    <li style="margin-bottom: 16px;"><a href="#" style="text-decoration: none; color: #4b5563; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 14px;"><span class="dashicons dashicons-admin-links" style="color: #3b82f6;"></span> Connectivity test</a></li>
                    <li style="margin-bottom: 16px;"><a href="#" style="text-decoration: none; color: #4b5563; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 14px;"><span class="dashicons dashicons-search" style="color: #3b82f6;"></span> Diagnostic test</a></li>
                    <li style="margin-bottom: 16px;"><a href="#" style="text-decoration: none; color: #4b5563; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 14px;"><span class="dashicons dashicons-image-rotate" style="color: #3b82f6;"></span> Reset plugin</a></li>
                </ul>
                <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 20px 0;">
                <div style="background: linear-gradient(to right, #f8fafc, #f1f5f9); padding: 15px; border-radius: 8px; text-align: center; border: 1px solid #e2e8f0;">
                    <p style="margin: 0 0 10px 0; font-size: 13px; font-weight: 500; color: #475569;">Still having issues?</p>
                    <a href="#" style="display: inline-block; background: #1e293b; color: #fff; padding: 8px 16px; border-radius: 20px; text-decoration: none; font-size: 13px; font-weight: 500;">Book an Expert</a>
                </div>
            </div>

            <!-- Provider Setup Guides -->
            <div style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 25px;">
                <h3 style="margin: 0 0 20px 0; font-size: 18px; font-weight: 600; color: #1e293b;">Quick Setup Guides</h3>

                <details style="margin-bottom: 15px;">
                    <summary style="cursor: pointer; font-weight: 500; color: #6366f1;">Zoho Mail Setup</summary>
                    <div style="padding: 15px; background: #f8fafc; margin-top: 10px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 13px;">
                        <ol style="margin-bottom: 0;">
                            <li>Log in to Zoho Mail</li>
                            <li>Go to Settings &rarr; Security &rarr; App Passwords</li>
                            <li>Generate a new App Password</li>
                            <li>Use: Host: smtp.zoho.com, Port: 587, TLS</li>
                            <li>Username: your Zoho email, Password: App Password</li>
                        </ol>
                    </div>
                </details>

                <details style="margin-bottom: 15px;">
                    <summary style="cursor: pointer; font-weight: 500; color: #6366f1;">SendGrid Setup</summary>
                    <div style="padding: 15px; background: #f8fafc; margin-top: 10px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 13px;">
                        <ol style="margin-bottom: 0;">
                            <li>Log in to SendGrid</li>
                            <li>Go to Settings &rarr; API Keys</li>
                            <li>Create API Key with Mail Send permission</li>
                            <li>Use: Host: smtp.sendgrid.net, Port: 587, TLS</li>
                            <li>Username: <code>apikey</code> (literally), Password: Your API Key</li>
                        </ol>
                    </div>
                </details>

                <details style="margin-bottom: 15px;">
                    <summary style="cursor: pointer; font-weight: 500; color: #6366f1;">Gmail Setup</summary>
                    <div style="padding: 15px; background: #f8fafc; margin-top: 10px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 13px;">
                        <ol>
                            <li>Enable 2-Factor Authentication on your Google account</li>
                            <li>Go to Google Account &rarr; Security &rarr; App Passwords</li>
                            <li>Generate App Password for "Mail"</li>
                            <li>Use: Host: smtp.gmail.com, Port: 587, TLS</li>
                            <li>Username: your Gmail, Password: App Password (16 chars)</li>
                        </ol>
                        <p style="color: #dc2626; margin-bottom: 0;"><strong>Note:</strong> Gmail has 500 emails/day limit for free accounts.</p>
                    </div>
                </details>

                <details style="margin-bottom: 15px;">
                    <summary style="cursor: pointer; font-weight: 500; color: #6366f1;">Brevo (Sendinblue)</summary>
                    <div style="padding: 15px; background: #f8fafc; margin-top: 10px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 13px;">
                        <ol>
                            <li>Log in to Brevo (formerly Sendinblue)</li>
                            <li>Go to Settings &rarr; SMTP &amp; API</li>
                            <li>Copy your SMTP Key</li>
                            <li>Use: Host: smtp-relay.brevo.com, Port: 587, TLS</li>
                            <li>Username: your Brevo email, Password: SMTP Key</li>
                        </ol>
                        <p style="color: #059669; margin-bottom: 0;"><strong>Free tier:</strong> 300 emails/day, great for small sites!</p>
                    </div>
                </details>

                <details style="margin-bottom: 0;">
                    <summary style="cursor: pointer; font-weight: 500; color: #6366f1;">Amazon SES Setup</summary>
                    <div style="padding: 15px; background: #f8fafc; margin-top: 10px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 13px;">
                        <ol>
                            <li>Log in to AWS Console &rarr; SES</li>
                            <li>Verify your domain or email address</li>
                            <li>Go to SMTP Settings &rarr; Create SMTP Credentials</li>
                            <li>Use: Host: email-smtp.[region].amazonaws.com, Port: 587, TLS</li>
                            <li>Username/Password: Generated SMTP credentials (NOT IAM keys)</li>
                        </ol>
                        <p style="color: #f59e0b; margin-bottom: 0;"><strong>Note:</strong> New accounts start in sandbox mode (verify recipients first).</p>
                    </div>
                </details>
            </div>
            
            <!-- Let Our Experts -->
            <div style="background: linear-gradient(to bottom, #312e81, #1e1b4b); padding: 35px 25px; border-radius: 12px; color: #fff; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin-bottom: 25px;">
                <div style="width: 70px; height: 70px; background: rgba(255,255,255,0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px auto;">
                    <span class="dashicons dashicons-businessman" style="font-size: 38px; width: 38px; height: 38px; color: #60a5fa;"></span>
                </div>
                <p style="margin: 0 0 25px 0; font-size: 16px; font-weight: 500; line-height: 1.5;">Let Our Experts Handle Your Ofast SMTP Setup</p>
                <a href="#" style="display: inline-block; background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); color: #fff; padding: 10px 24px; border-radius: 30px; text-decoration: none; font-weight: 500; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">Book Now</a>
            </div>
            
        </div> <!-- End Right Column -->
        </div> <!-- End Sidebar Layout -->
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

        // Shared pagination CSS (reusable across modules)
        wp_enqueue_style(
            'ofast-pagination',
            OFAST_X_PLUGIN_URL . 'assets/css/ofast-pagination.css',
            array(),
            OFAST_X_VERSION
        );

        // SMTP admin CSS (extracted from inline <style> blocks)
        wp_enqueue_style(
            'ofast-smtp-admin',
            plugins_url('assets/css/smtp-admin.css', __FILE__),
            array(),
            OFAST_X_VERSION
        );

        // SMTP admin JS (extracted from inline <script> blocks)
        wp_enqueue_script(
            'ofast-smtp-admin',
            plugins_url('assets/js/smtp-admin.js', __FILE__),
            array('jquery'),
            OFAST_X_VERSION,
            true
        );

        // Determine log pagination state for the JS
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page     = isset($_GET['per_page']) ? sanitize_text_field($_GET['per_page']) : '20';
        $show_all     = ($per_page === 'all');

        wp_localize_script('ofast-smtp-admin', 'ofastSMTP', array(
            'ajaxurl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('ofast_test_smtp'),
            'port_test_nonce' => wp_create_nonce('ofast_port_test'),
            'presets'         => Ofast_X_SMTP::get_provider_presets(),
            'logPage'         => $current_page,
            'logPerPage'      => $show_all ? 'all' : intval($per_page),
            'logNonce'        => wp_create_nonce('ofast_smtp_logs_nonce'),
        ));
    }

    /**
     * Handle resend action on admin_init (before any output, so redirect works)
     */
    public function handle_resend()
    {
        // Only run on our SMTP page
        if (!isset($_GET['page']) || sanitize_key($_GET['page']) !== 'ofast-smtp') {
            return;
        }

        if (!isset($_GET['resend']) || !isset($_GET['_wpnonce'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        if (!wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']), 'resend_email')) {
            return;
        }

        $this->resend_email(intval($_GET['resend']));

        // PRG: Redirect to clean URL to prevent re-send on page refresh
        wp_safe_redirect(admin_url('admin.php?page=ofast-smtp&tab=log'));
        exit;
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
                add_action('admin_notices', function () use ($key_diagnostics) {
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
                add_action('admin_notices', function () use ($e) {
                    printf('<div class="notice notice-error is-dismissible"><p><strong>SMTP Configuration Error:</strong> %s</p></div>', esc_html($e->getMessage()));
                });
                return; // Stop processing if credentials cannot be stored securely
            }
        }

        // ── Pro-only settings (skip saving if not licensed) ──
        if (ofast_toolkit_is_pro()) {
            // Rate limiting settings
            update_option('ofast_smtp_rate_limit_enabled', isset($_POST['rate_limit_enabled']) ? 1 : 0);
            update_option('ofast_smtp_rate_limit', max(1, intval($_POST['rate_limit'] ?? 60)));

            // Email logging security settings
            $log_body_content = isset($_POST['log_body_content']) ? 1 : 0;
            update_option('ofast_smtp_log_body_content', $log_body_content);

            // Fallback SMTP settings
            update_option('ofast_smtp_fallback_enabled', isset($_POST['fallback_enabled']) ? 1 : 0);
            update_option('ofast_smtp_fallback_host', sanitize_text_field(wp_unslash($_POST['fallback_host'] ?? '')));
            update_option('ofast_smtp_fallback_port', intval($_POST['fallback_port'] ?? 587));
            update_option('ofast_smtp_fallback_encryption', sanitize_text_field(wp_unslash($_POST['fallback_encryption'] ?? 'tls')));
            update_option('ofast_smtp_fallback_username', sanitize_text_field(wp_unslash($_POST['fallback_username'] ?? '')));
            update_option('ofast_smtp_fallback_from_email', sanitize_email(wp_unslash($_POST['fallback_from_email'] ?? '')));
            update_option('ofast_smtp_fallback_from_name', sanitize_text_field(wp_unslash($_POST['fallback_from_name'] ?? '')));

            // Fallback password (same mask-detection as primary)
            $fallback_password = wp_unslash($_POST['fallback_password'] ?? '');
            if (!empty($fallback_password) && $fallback_password !== '••••••••') {
                $encrypted_fb = Ofast_X_SMTP::encrypt_password($fallback_password);
                update_option('ofast_smtp_fallback_password', $encrypted_fb);
            }

            // Health report settings
            update_option('ofast_smtp_health_report_enabled', isset($_POST['health_report_enabled']) ? 1 : 0);
            $valid_hr_intervals = array('daily', 'weekly', 'monthly');
            $hr_interval = sanitize_text_field($_POST['health_report_interval'] ?? 'weekly');
            if (!in_array($hr_interval, $valid_hr_intervals)) {
                $hr_interval = 'weekly';
            }
            update_option('ofast_smtp_health_report_interval', $hr_interval);

            // Notify listeners when body logging is enabled (for optional auditing).
            if ($log_body_content) {
                do_action('ofast_smtp_body_logging_enabled', get_current_user_id(), current_time('mysql'));
            }
        }

        // Log retention settings (free feature)
        $retention_days = intval($_POST['log_retention_days'] ?? 90);
        $retention_days = max(0, min(3650, $retention_days));
        update_option('ofast_smtp_log_retention_days', $retention_days);

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
                $this->sanitize_csv_value($log->to_email),
                $this->sanitize_csv_value($log->subject),
                $this->sanitize_csv_value($log->status),
                $this->sanitize_csv_value($log->error_message ?? ''),
                $this->sanitize_csv_value($log->sent_at)
            ));
        }

        fclose($output);
        exit;
    }

    /**
     * Prevent CSV formula injection by prefixing risky values.
     */
    private function sanitize_csv_value($value)
    {
        $value = (string) $value;
        $value = str_replace(array("\r", "\n"), ' ', $value);
        if ($value !== '' && preg_match('/^[=+\\-@\\t]/', $value)) {
            return "'" . $value;
        }
        return $value;
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
        // Trigger table creation by calling the SMTP singleton
        // The ensure_log_table method runs via log_outgoing_email, 
        // but for admin pages we need the table to exist for queries.
        global $wpdb;
        $table_name = $wpdb->prefix . 'ofast_smtp_log';

        if (get_transient('ofast_smtp_log_table_exists')) {
            return;
        }

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
                body longtext NOT NULL,
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

    /**
     * Resend a previously logged email by ID.
     */
    private function resend_email($log_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ofast_smtp_log';

        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $log_id
        ));

        if (!$log) {
            Ofast_X_Toast::add('Email log entry not found.', 'error');
            return;
        }

        if (empty($log->to_email) || empty($log->subject)) {
            Ofast_X_Toast::add('Cannot resend: email data is incomplete.', 'error');
            return;
        }

        // Temporarily remove the SMTP logging filter to prevent duplicate log entries
        $smtp_instance = Ofast_X_SMTP::get_instance();
        remove_filter('wp_mail', array($smtp_instance, 'log_outgoing_email'), 10);
        remove_action('wp_mail_succeeded', array($smtp_instance, 'mark_email_success'), 10);
        remove_action('wp_mail_failed', array($smtp_instance, 'mark_email_failed'), 10);

        $headers = array('Content-Type: text/html; charset=UTF-8');
        $body = !empty($log->body) ? $log->body : '';

        $result = wp_mail($log->to_email, $log->subject, $body, $headers);

        // Re-add the logging filter
        add_filter('wp_mail', array($smtp_instance, 'log_outgoing_email'), 10, 1);
        add_action('wp_mail_succeeded', array($smtp_instance, 'mark_email_success'), 10, 1);
        add_action('wp_mail_failed', array($smtp_instance, 'mark_email_failed'), 10, 1);

        if ($result) {
            // Mark as 'resent' so it's visually distinguishable from the original successful send
            $wpdb->update(
                $table_name,
                array('status' => 'resent', 'error_message' => 'Manually resent by admin'),
                array('id' => $log_id)
            );
            Ofast_X_Toast::add('Email resent successfully to ' . esc_html($log->to_email), 'success');
        } else {
            Ofast_X_Toast::add('Failed to resend email. Check your SMTP configuration.', 'error');
        }
    }
}
