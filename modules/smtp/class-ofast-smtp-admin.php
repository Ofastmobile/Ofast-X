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
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_head', array($this, 'admin_head_styles'));
        add_action('wp_ajax_ofast_smtp_fetch_logs', array($this, 'ajax_fetch_logs'));
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
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.05);
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
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            }

            .ofast-tab.active {
                background: #6366f1;
                color: #fff;
                box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
            }

            .ofast-tab .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
                line-height: 16px;
            }

            .ofast-tab-content {
                display: none;
            }

            .ofast-tab-content.active {
                display: block;
            }

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

            .ofast-layout-sidebar {
                display: grid;
                grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
                gap: 20px;
                align-items: start;
            }

            @media (max-width: 1200px) {
                .ofast-grid-4 {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }

                .ofast-flex-layout {
                    grid-template-columns: 1fr;
                }

                .ofast-layout-sidebar {
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

            /* Pro-locked section overlay */
            .ofast-pro-locked-section {
                position: relative;
                overflow: hidden;
                border-radius: 8px;
            }
            .ofast-pro-locked-section > .ofast-pro-overlay {
                position: absolute;
                inset: 0;
                z-index: 10;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 10px;
                background: rgba(255, 255, 255, 0.35);
                backdrop-filter: blur(2.5px);
                -webkit-backdrop-filter: blur(2.5px);
                border-radius: 8px;
            }
            .ofast-pro-overlay .ofast-pro-lock-icon {
                width: 44px; height: 44px;
                background: rgba(99,102,241,0.12);
                border-radius: 50%;
                display: flex; align-items: center; justify-content: center;
            }
            .ofast-pro-overlay .ofast-pro-lock-icon .dashicons {
                color: #6366f1; font-size: 22px; width: 22px; height: 22px;
            }
            .ofast-pro-overlay .ofast-pro-overlay-text {
                font-size: 15px; font-weight: 600; color: #1e293b; text-align: center;
            }
            .ofast-pro-overlay .ofast-pro-overlay-desc {
                font-size: 13px; font-weight: 400; color: #64748b; text-align: center;
                max-width: 320px; line-height: 1.5;
            }
            .ofast-pro-overlay .ofast-pro-upgrade-btn {
                display: inline-flex; align-items: center; gap: 6px;
                padding: 10px 24px;
                background: linear-gradient(135deg, #6366f1, #4f46e5);
                color: #fff; font-size: 13px; font-weight: 600;
                border-radius: 8px; text-decoration: none;
                box-shadow: 0 4px 12px rgba(99,102,241,0.3);
                transition: transform 0.2s, box-shadow 0.2s;
            }
            .ofast-pro-overlay .ofast-pro-upgrade-btn:hover {
                transform: translateY(-1px);
                box-shadow: 0 6px 16px rgba(99,102,241,0.4);
                color: #fff;
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
            <!-- Header -->
            <div class="ofast-header"
                style="display:flex; align-items:center; gap:20px; background:#fff; padding:25px 30px; border-radius:12px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom:25px; margin-top:20px;">
                <div
                    style="width:56px; height:56px; background:#fff; border:1px solid #e2e8f0; box-shadow:0 2px 4px rgba(0,0,0,0.02); border-radius:16px; display:flex; align-items:center; justify-content:center;">
                    <span class="dashicons dashicons-email-alt2"
                        style="font-size:28px; width:28px; height:28px; color:#6366f1;"></span>
                </div>
                <div>
                    <h1 style="margin:0 0 5px 0; font-size:24px; font-weight:700; color:#1e293b; display:block; padding:0;">SMTP
                    </h1>
                    <p style="margin:0; color:#64748b; font-size:14px;">Configure email delivery, monitor performance, and view
                        logs.</p>
                </div>
            </div>

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
            <div id="smtp-tab-dashboard" class="ofast-tab-content<?php echo $default_tab === 'dashboard' ? ' active' : ''; ?>"
                style="<?php echo $default_tab !== 'dashboard' ? 'display:none;' : ''; ?>">
                <?php $this->render_dashboard_page_content(); ?>
            </div>

            <div id="smtp-tab-log" class="ofast-tab-content<?php echo $default_tab === 'log' ? ' active' : ''; ?>"
                style="<?php echo $default_tab !== 'log' ? 'display:none;' : ''; ?>">
                <?php $this->render_log_page_content(); ?>
            </div>

            <div id="smtp-tab-settings" class="ofast-tab-content<?php echo $default_tab === 'settings' ? ' active' : ''; ?>"
                style="<?php echo $default_tab !== 'settings' ? 'display:none;' : ''; ?>">
                <?php $this->render_settings_page_content(); ?>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                // Tab switching without page reload
                $('#smtp-tabs-nav .ofast-tab').on('click', function (e) {
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
                        history.pushState({ tab: tabId }, '', url);
                    }
                });

                // Handle browser back/forward
                window.addEventListener('popstate', function (e) {
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


        <!-- Connection Status -->
        <div class="ofast-grid-3" style="margin: 25px 0;">
            <div
                style="background: <?php echo $is_active ? 'linear-gradient(135deg, #10b981, #059669)' : 'linear-gradient(135deg, #6b7280, #4b5563)'; ?>; padding: 25px; border-radius: 12px; color: #fff; text-align: center;">
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
            <div
                style="background: linear-gradient(135deg, #6366f1, #4f46e5); padding: 25px; border-radius: 12px; color: #fff; text-align: center;">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">Provider</div>
                <div style="font-size: 18px; font-weight: 600;">
                    <?php echo $mailer_type === 'default' ? 'Server Mail' : ($host ? esc_html($host) : 'Not Set'); ?>
                </div>
                <div style="font-size: 13px; opacity: 0.9; margin-top: 5px;">
                    <?php echo $mailer_type === 'default' ? 'PHP mail() function' : 'Port ' . esc_html(get_option('ofast_smtp_port', 587)) . ' / ' . strtoupper(esc_html($encryption)); ?>
                </div>
            </div>
            <div
                style="background: linear-gradient(135deg, #3b82f6, #2563eb); padding: 25px; border-radius: 12px; color: #fff; text-align: center;">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">From Address</div>
                <div style="font-size: 16px; font-weight: 600; word-break: break-all;">
                    <?php echo $from_email ? esc_html($from_email) : 'Not Set'; ?>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="ofast-grid-4" style="margin: 25px 0;">
            <div
                style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 36px; font-weight: 700; color: #6366f1;"><?php echo number_format($stats['total']); ?>
                </div>
                <div style="color: #6b7280; font-size: 14px; margin-top: 5px;">Total Emails</div>
            </div>
            <div
                style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 36px; font-weight: 700; color: #10b981;"><?php echo number_format($stats['success']); ?>
                </div>
                <div style="color: #6b7280; font-size: 14px; margin-top: 5px;">Successful</div>
            </div>
            <div
                style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 36px; font-weight: 700; color: #ef4444;"><?php echo number_format($stats['failed']); ?>
                </div>
                <div style="color: #6b7280; font-size: 14px; margin-top: 5px;">Failed</div>
            </div>
            <div
                style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div
                    style="font-size: 36px; font-weight: 700; color: <?php echo $stats['rate'] >= 90 ? '#10b981' : ($stats['rate'] >= 70 ? '#f59e0b' : '#ef4444'); ?>;">
                    <?php echo $stats['rate']; ?>%
                </div>
                <div style="color: #6b7280; font-size: 14px; margin-top: 5px;">Success Rate</div>
            </div>
        </div>

        <!-- Lifetime Counters (persist beyond log cleanup) -->
        <?php $lifetime = Ofast_X_SMTP::get_delivery_stats(); ?>
        <?php if ($lifetime['success'] > 0 || $lifetime['failed'] > 0): ?>
            <div style="display: flex; gap: 15px; margin: 0 0 25px 0; flex-wrap: wrap;">
                <div
                    style="flex: 1; background: linear-gradient(135deg, #f0fdf4, #dcfce7); padding: 16px 20px; border-radius: 10px; border: 1px solid #bbf7d0; display: flex; align-items: center; gap: 12px; min-width: 200px;">
                    <span class="dashicons dashicons-saved"
                        style="color: #16a34a; font-size: 22px; width: 22px; height: 22px;"></span>
                    <div>
                        <div style="font-size: 20px; font-weight: 700; color: #15803d;">
                            <?php echo number_format($lifetime['success']); ?>
                        </div>
                        <div style="font-size: 12px; color: #4ade80;">Lifetime Delivered</div>
                    </div>
                </div>
                <div
                    style="flex: 1; background: linear-gradient(135deg, #fef2f2, #fee2e2); padding: 16px 20px; border-radius: 10px; border: 1px solid #fecaca; display: flex; align-items: center; gap: 12px; min-width: 200px;">
                    <span class="dashicons dashicons-dismiss"
                        style="color: #dc2626; font-size: 22px; width: 22px; height: 22px;"></span>
                    <div>
                        <div style="font-size: 20px; font-weight: 700; color: #b91c1c;">
                            <?php echo number_format($lifetime['failed']); ?>
                        </div>
                        <div style="font-size: 12px; color: #f87171;">Lifetime Failed</div>
                    </div>
                </div>
                <?php if ($lifetime['fallback_used'] > 0): ?>
                    <div
                        style="flex: 1; background: linear-gradient(135deg, #fffbeb, #fef3c7); padding: 16px 20px; border-radius: 10px; border: 1px solid #fde68a; display: flex; align-items: center; gap: 12px; min-width: 200px;">
                        <span class="dashicons dashicons-update-alt"
                            style="color: #d97706; font-size: 22px; width: 22px; height: 22px;"></span>
                        <div>
                            <div style="font-size: 20px; font-weight: 700; color: #b45309;">
                                <?php echo number_format($lifetime['fallback_used']); ?>
                            </div>
                            <div style="font-size: 12px; color: #fbbf24;">Fallback Recoveries</div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Sidebar Layout -->
        <div class="ofast-layout-sidebar" style="margin: 25px 0;">

            <!-- Left Column -->
            <div style="min-width: 0;">
                <!-- Emails Last 7 Days -->
                <div class="ofast-main"
                    style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: flex; flex-direction: column; margin-bottom: 25px;">
                    <h3 style="margin: 0; font-size: 16px; color: #374151;">Emails Last 7 Days</h3>
                    <div style="flex: 1;"></div>
                    <div
                        style="display: flex; align-items: flex-end; justify-content: space-between; gap: 8px; margin-top: 20px;">
                        <?php foreach ($weekly_data as $day): ?>
                            <?php
                            // Max height 80px for bars
                            $bar_height = $max_weekly > 0 ? round(($day['count'] / $max_weekly) * 80) : 0;
                            $bar_height = max(5, $bar_height);
                            ?>
                            <div style="flex: 1; text-align: center;">
                                <div style="background: linear-gradient(to top, #6366f1, #818cf8); height: <?php echo $bar_height; ?>px; border-radius: 4px 4px 0 0; min-height: 5px;"
                                    title="<?php echo $day['count']; ?> emails"></div>
                                <div style="font-size: 11px; color: #6b7280; margin-top: 8px;"><?php echo esc_html($day['day']); ?>
                                </div>
                                <div style="font-size: 12px; font-weight: 600; color: #374151;"><?php echo $day['count']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Recent Emails -->
                <div class="ofast-main"
                    style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 25px;">
                    <h3 style="margin: 0 0 20px 0; font-size: 16px; color: #374151;">Recent Emails</h3>
                    <?php if (empty($recent_emails)): ?>
                        <p style="color: #6b7280; text-align: center; padding: 30px 0;">No emails sent yet.</p>
                    <?php else: ?>
                        <div style="max-height: 300px; overflow-y: auto;">
                            <?php foreach ($recent_emails as $email): ?>
                                <div style="display: flex; align-items: center; padding: 10px 0; border-bottom: 1px solid #f3f4f6;">
                                    <div style="flex: 1; min-width: 0;">
                                        <div
                                            style="font-size: 13px; font-weight: 500; color: #374151; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                            <?php echo esc_html($email->subject); ?>
                                        </div>
                                        <div style="font-size: 12px; color: #6b7280;">
                                            <?php echo esc_html($email->to_email); ?>
                                        </div>
                                    </div>
                                    <div style="margin-left: 10px;">
                                        <?php if ($email->status === 'success'): ?>
                                            <span
                                                style="background: #d1fae5; color: #065f46; padding: 2px 8px; border-radius: 3px; font-size: 10px;">✓</span>
                                        <?php else: ?>
                                            <span
                                                style="background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 3px; font-size: 10px;">✗</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pro Features -->
                <div
                    style="background: linear-gradient(135deg, #1e40af, #3b82f6); padding: 35px; border-radius: 12px; color: #fff; text-align: center; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.3); margin-bottom: 25px;">
                    <!-- Pattern Background -->
                    <div
                        style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; opacity: 0.1; background-image: radial-gradient(circle at 2px 2px, white 1px, transparent 0); background-size: 24px 24px;">
                    </div>

                    <div style="position: relative; z-index: 1;">
                        <div
                            style="display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 44px; margin-bottom: 15px;">
                            <span class="dashicons dashicons-star-filled"
                                style="color: #fbbf24; font-size: 32px; width: 32px; height: 32px;"></span>
                        </div>
                        <h3
                            style="margin: 0 0 8px 0; font-size: 26px; font-weight: 700; color: #fff; text-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            Pro Features</h3>
                        <p style="margin: 0 0 30px 0; font-size: 16px; font-weight: 500; opacity: 0.9;">Supercharge your Email
                        </p>

                        <div
                            style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; text-align: left; margin-bottom: 30px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div
                                    style="background: rgba(255,255,255,0.15); border-radius: 6px; padding: 6px; display: flex;">
                                    <span class="dashicons dashicons-clock"
                                        style="color: #fbbf24; font-size: 16px; width: 16px; height: 16px;"></span>
                                </div>
                                <span style="font-size: 12px; font-weight: 500; line-height: 1.3;">Email Scheduling<br>Quota
                                    Management</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div
                                    style="background: rgba(255,255,255,0.15); border-radius: 6px; padding: 6px; display: flex;">
                                    <span class="dashicons dashicons-chart-pie"
                                        style="color: #fbbf24; font-size: 16px; width: 16px; height: 16px;"></span>
                                </div>
                                <span style="font-size: 12px; font-weight: 500; line-height: 1.3;">Email Report<br>and
                                    Tracking</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div
                                    style="background: rgba(255,255,255,0.15); border-radius: 6px; padding: 6px; display: flex;">
                                    <span class="dashicons dashicons-paperclip"
                                        style="color: #fbbf24; font-size: 16px; width: 16px; height: 16px;"></span>
                                </div>
                                <span style="font-size: 12px; font-weight: 500; line-height: 1.3;">Email
                                    Log<br>Attachment</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div
                                    style="background: rgba(255,255,255,0.15); border-radius: 6px; padding: 6px; display: flex;">
                                    <span class="dashicons dashicons-smartphone"
                                        style="color: #fbbf24; font-size: 16px; width: 16px; height: 16px;"></span>
                                </div>
                                <span style="font-size: 12px; font-weight: 500; line-height: 1.3;">SMS<br>Notification</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div
                                    style="background: rgba(255,255,255,0.15); border-radius: 6px; padding: 6px; display: flex;">
                                    <span class="dashicons dashicons-update-alt"
                                        style="color: #fbbf24; font-size: 16px; width: 16px; height: 16px;"></span>
                                </div>
                                <span style="font-size: 12px; font-weight: 500; line-height: 1.3;">Auto Resend<br>Failed
                                    Emails</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div
                                    style="background: rgba(255,255,255,0.15); border-radius: 6px; padding: 6px; display: flex;">
                                    <span class="dashicons dashicons-email"
                                        style="color: #fbbf24; font-size: 16px; width: 16px; height: 16px;"></span>
                                </div>
                                <span style="font-size: 12px; font-weight: 500; line-height: 1.3;">Microsoft 365 /<br>Office
                                    365</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div
                                    style="background: rgba(255,255,255,0.15); border-radius: 6px; padding: 6px; display: flex;">
                                    <span class="dashicons dashicons-cloud"
                                        style="color: #fbbf24; font-size: 16px; width: 16px; height: 16px;"></span>
                                </div>
                                <span style="font-size: 12px; font-weight: 500; line-height: 1.3;">Amazon SES<br>Support</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div
                                    style="background: rgba(255,255,255,0.15); border-radius: 6px; padding: 6px; display: flex;">
                                    <span class="dashicons dashicons-email-alt"
                                        style="color: #fbbf24; font-size: 16px; width: 16px; height: 16px;"></span>
                                </div>
                                <span style="font-size: 12px; font-weight: 500; line-height: 1.3;">Zoho Mail<br>Support</span>
                            </div>
                        </div>

                        <a href="<?php echo esc_url(ofast_toolkit_get_upgrade_url()); ?>" target="_blank"
                            style="display: inline-block; background: #f59e0b; color: #fff; padding: 12px 30px; border-radius: 30px; font-weight: 600; text-decoration: none; transition: transform 0.2s, box-shadow 0.2s; box-shadow: 0 4px 6px rgba(245, 158, 11, 0.3);">Get
                            Ofast Toolkit Pro &rarr;</a>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div style="min-width: 0;">

                <!-- Video Section -->
                <div
                    style="background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); cursor: pointer; position: relative; margin-bottom: 25px;">
                    <!-- Inline Video Container -->
                    <div id="ofast-inline-video-wrapper"
                        style="height: 200px; position: relative; display: flex; align-items: center; justify-content: center; background-color: #0f172a; overflow: hidden; margin: 0; padding: 0;"
                        class="ofast-video-container" data-video-id="0dcd5bLtYs8">
                        <!-- Featured Image: YouTube Thumbnail (falls back to hqdefault if maxres doesn't exist) -->
                        <img src="https://img.youtube.com/vi/0dcd5bLtYs8/maxresdefault.jpg"
                            onerror="this.src='https://img.youtube.com/vi/0dcd5bLtYs8/hqdefault.jpg';" alt="SMTP Setup Video"
                            style="position: absolute; width: 100%; height: 100%; object-fit: cover; opacity: 0.7; transition: opacity 0.3s ease;">

                        <!-- Overlay gradient for better contrast -->
                        <div
                            style="position: absolute; width: 100%; height: 100%; background: linear-gradient(135deg, rgba(30,27,75,0.4) 0%, rgba(76,29,149,0.4) 100%); pointer-events: none;">
                        </div>

                        <!-- Play Button -->
                        <div style="width: 64px; height: 64px; background: #8b5cf6; border-radius: 50%; display: flex; align-items: center; justify-content: center; z-index: 2; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4); transition: transform 0.2s ease, background 0.2s ease;"
                            class="ofast-play-btn">
                            <div
                                style="width: 0; height: 0; border-top: 10px solid transparent; border-bottom: 10px solid transparent; border-left: 16px solid #fff; margin-left: 6px;">
                            </div>
                        </div>
                    </div>
                </div>

                <style>
                    .ofast-video-container:hover img {
                        opacity: 0.9 !important;
                    }

                    .ofast-video-container:hover .ofast-play-btn {
                        transform: scale(1.1);
                        background: #7c3aed !important;
                    }
                </style>

                <script>
                    jQuery(document).ready(function ($) {
                        $('#ofast-inline-video-wrapper').on('click', function () {
                            var videoId = $(this).data('video-id');
                            var iframe = $('<iframe/>', {
                                'src': 'https://www.youtube.com/embed/' + videoId + '?autoplay=1&rel=0',
                                'frameborder': '0',
                                'allow': 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture',
                                'allowfullscreen': 'true',
                                'css': {
                                    'width': '100%',
                                    'height': '100%',
                                    'position': 'absolute',
                                    'top': '0',
                                    'left': '0',
                                    'z-index': '10'
                                }
                            });
                            $(this).empty().append(iframe);
                        });
                    });
                </script>

                <!-- Troubleshooting -->
                <div
                    style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 25px;">
                    <h3 style="margin: 0 0 20px 0; font-size: 18px; font-weight: 600; color: #1e293b;">Troubleshooting</h3>
                    <ul style="margin: 0; padding: 0; list-style: none;">
                        <li style="margin-bottom: 16px;"><a href="#"
                                style="text-decoration: none; color: #4b5563; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 14px;"><span
                                    class="dashicons dashicons-email" style="color: #3b82f6;"></span> Send test email</a></li>
                        <li style="margin-bottom: 16px;"><a href="#"
                                style="text-decoration: none; color: #4b5563; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 14px;"><span
                                    class="dashicons dashicons-external" style="color: #3b82f6;"></span> Spam Score Checker</a>
                        </li>
                        <li style="margin-bottom: 16px;"><a href="#"
                                style="text-decoration: none; color: #4b5563; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 14px;"><span
                                    class="dashicons dashicons-update" style="color: #3b82f6;"></span> Import/Export</a></li>
                        <li style="margin-bottom: 16px;"><a href="#"
                                style="text-decoration: none; color: #4b5563; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 14px;"><span
                                    class="dashicons dashicons-admin-links" style="color: #3b82f6;"></span> Connectivity
                                test</a></li>
                        <li style="margin-bottom: 16px;"><a href="#"
                                style="text-decoration: none; color: #4b5563; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 14px;"><span
                                    class="dashicons dashicons-search" style="color: #3b82f6;"></span> Diagnostic test</a></li>
                        <li style="margin-bottom: 0;"><a href="#"
                                style="text-decoration: none; color: #4b5563; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 14px;"><span
                                    class="dashicons dashicons-image-rotate" style="color: #3b82f6;"></span> Reset plugin</a>
                        </li>
                    </ul>
                </div>

                <!-- Let Our Experts -->
                <div
                    style="background: linear-gradient(to bottom, #312e81, #1e1b4b); padding: 35px 25px; border-radius: 12px; color: #fff; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin-bottom: 25px;">
                    <div
                        style="width: 70px; height: 70px; background: rgba(255,255,255,0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px auto;">
                        <span class="dashicons dashicons-businessman"
                            style="font-size: 38px; width: 38px; height: 38px; color: #60a5fa;"></span>
                    </div>
                    <p style="margin: 0 0 25px 0; font-size: 16px; font-weight: 500; line-height: 1.5;">Let Our Experts Handle
                        Your Ofast SMTP Setup</p>
                    <a href="#"
                        style="display: inline-block; background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); color: #fff; padding: 10px 24px; border-radius: 30px; text-decoration: none; font-weight: 500; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">Book
                        Now</a>
                </div>

            </div>
        </div>

        <!-- Quick Actions -->
        <div style="margin: 15px 0 25px 0;">
            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <a href="<?php echo admin_url('admin.php?page=ofast-smtp&tab=settings'); ?>"
                    class="button ofast-btn-primary button-large">Configure SMTP</a>
                <a href="<?php echo admin_url('admin.php?page=ofast-emailer&tab=history'); ?>"
                    class="button ofast-btn-secondary button-large">Emailer</a>
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

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Resend is handled in handle_resend() on admin_init (before output)

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

        <style>
            /* Pagination styles loaded from shared ofast-pagination.css */
            .ofast-smtp-loading {
                position: relative;
                pointer-events: none;
            }

            .ofast-smtp-loading::after {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(255, 255, 255, 0.7);
                border-radius: inherit;
            }
        </style>

        <div id="ofast-smtp-pagination-wrap">
            <?php echo $this->render_pagination_bar($current_page, $total_pages, $total, $per_page, $show_all, $offset); ?>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                var smtpState = {
                    page: <?php echo intval($current_page); ?>,
                    perPage: '<?php echo esc_js($show_all ? 'all' : $per_page); ?>',
                    nonce: '<?php echo esc_js($ajax_nonce); ?>'
                };

                function smtpFetchPage(page, perPage) {
                    var $tbody = $('#ofast-smtp-log-tbody');
                    var $paginationWrap = $('#ofast-smtp-pagination-wrap');
                    $tbody.closest('table').addClass('ofast-smtp-loading');
                    $paginationWrap.addClass('ofast-smtp-loading');

                    $.post(ajaxurl, {
                        action: 'ofast_smtp_fetch_logs',
                        nonce: smtpState.nonce,
                        paged: page,
                        per_page: perPage
                    }, function (response) {
                        if (response.success) {
                            $tbody.html(response.data.rows_html);
                            $paginationWrap.html(response.data.pagination_html);
                            smtpState.page = response.data.current_page;
                            smtpState.perPage = perPage;

                            // Update per-page dropdown to stay in sync
                            $('#ofast-smtp-per-page').val(perPage);

                            // Update URL without reload (bookmarkable)
                            var url = new URL(window.location);
                            url.searchParams.set('paged', response.data.current_page);
                            url.searchParams.set('per_page', perPage);
                            url.searchParams.set('tab', 'log');
                            history.replaceState(null, '', url.toString());

                            // Re-bind preview buttons for new rows
                            smtpBindPreview();
                            // Re-bind pagination clicks
                            smtpBindPagination();
                        }
                        $tbody.closest('table').removeClass('ofast-smtp-loading');
                        $paginationWrap.removeClass('ofast-smtp-loading');
                    }).fail(function () {
                        $tbody.closest('table').removeClass('ofast-smtp-loading');
                        $paginationWrap.removeClass('ofast-smtp-loading');
                    });
                }

                function smtpBindPagination() {
                    $('#ofast-smtp-pagination-wrap').off('click', '.ofast-page-btn').on('click', '.ofast-page-btn', function (e) {
                        e.preventDefault();
                        if ($(this).hasClass('disabled') || $(this).hasClass('active')) return;
                        var page = $(this).data('page');
                        if (page) smtpFetchPage(page, smtpState.perPage);
                    });
                }

                function smtpBindPreview() {
                    $('#ofast-smtp-log-tbody').off('click', '.preview-email').on('click', '.preview-email', function () {
                        var content = atob($(this).data('content'));
                        $('#email-preview-frame').remove();
                        var iframe = $('<iframe id="email-preview-frame" style="width: 100%; height: 60vh; border: none;"></iframe>');
                        iframe.attr('srcdoc', content);
                        $('#email-preview-modal .ofast-smtp-modal-body').append(iframe);
                        $('#email-preview-modal').fadeIn(200);
                    });
                }

                // Per-page change → AJAX
                $('#ofast-smtp-per-page').on('change', function () {
                    smtpFetchPage(1, $(this).val());
                });

                // Initial bindings
                smtpBindPagination();
                smtpBindPreview();

                // Close preview modal
                $('#close-preview, #email-preview-modal').on('click', function (e) {
                    if (e.target === this || $(this).attr('id') === 'close-preview') {
                        $('#email-preview-modal').fadeOut(200);
                        $('#email-preview-frame').remove();
                    }
                });
            });
        </script>

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
        <style>
            /* Ofast Toggle Switch */
            .ofast-toggle {
                position: relative;
                display: inline-block;
                width: 44px;
                height: 24px;
                vertical-align: middle;
                margin-right: 10px;
            }

            .ofast-toggle input {
                opacity: 0;
                width: 0;
                height: 0;
            }

            .ofast-slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #cbd5e1;
                transition: .4s;
                border-radius: 34px;
            }

            .ofast-slider:before {
                position: absolute;
                content: "";
                height: 18px;
                width: 18px;
                left: 3px;
                bottom: 3px;
                background-color: white;
                transition: .4s;
                border-radius: 50%;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }

            input:checked+.ofast-slider {
                background-color: #6366f1;
            }

            input:focus+.ofast-slider {
                box-shadow: 0 0 1px #6366f1;
            }

            input:checked+.ofast-slider:before {
                transform: translateX(20px);
            }

            /* Ofast Form Styling */
            #ofast-smtp-form .form-table input[type="text"],
            #ofast-smtp-form .form-table input[type="email"],
            #ofast-smtp-form .form-table input[type="password"],
            #ofast-smtp-form .form-table input[type="number"] {
                border-radius: 8px;
                border: 1px solid #d7deea;
                padding: 8px 12px;
                transition: border-color 0.2s, box-shadow 0.2s;
            }

            #ofast-smtp-form .form-table input[type="text"]:focus,
            #ofast-smtp-form .form-table input[type="email"]:focus,
            #ofast-smtp-form .form-table input[type="password"]:focus,
            #ofast-smtp-form .form-table input[type="number"]:focus {
                border-color: #6366f1;
                box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
                outline: none;
            }

            /* Encryption Segmented Control */
            .ofast-encryption-group {
                display: inline-flex;
                gap: 0;
                border: 1px solid #d7deea;
                border-radius: 8px;
                overflow: hidden;
            }

            .ofast-encryption-group label {
                padding: 8px 18px;
                cursor: pointer;
                font-size: 13px;
                font-weight: 500;
                color: #64748b;
                background: #f8fafc;
                border-right: 1px solid #d7deea;
                transition: all 0.2s;
                display: inline-flex;
                align-items: center;
                gap: 6px;
            }

            .ofast-encryption-group label:last-child {
                border-right: none;
            }

            .ofast-encryption-group input[type="radio"] {
                display: none;
            }

            .ofast-encryption-group input[type="radio"]:checked+span {
                /* handled via JS below */
            }

            .ofast-encryption-group label.active {
                background: #6366f1;
                color: #fff;
            }

            /* Ofast Button Styling */
            #ofast-smtp-form .button.button-primary,
            #ofast-smtp-form .button.ofast-btn-primary {
                background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%) !important;
                border-color: #6366f1 !important;
                text-shadow: none !important;
                box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3) !important;
                transition: all 0.3s ease !important;
                padding: 10px 24px !important;
                height: auto !important;
                border-radius: 8px !important;
                font-weight: 600 !important;
                font-size: 14px !important;
            }

            #ofast-smtp-form .button.button-primary:hover,
            #ofast-smtp-form .button.ofast-btn-primary:hover {
                background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%) !important;
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4) !important;
            }

            #ofast-smtp-form .button.button-secondary,
            #ofast-smtp-form .button.ofast-btn-secondary {
                border-radius: 8px !important;
                padding: 8px 18px !important;
                font-weight: 500 !important;
                transition: all 0.2s !important;
                border: 1px solid #d7deea !important;
            }

            #ofast-smtp-form .button.button-secondary:hover {
                border-color: #6366f1 !important;
                color: #6366f1 !important;
            }

            #ofast-smtp-form .button.button-small {
                border-radius: 6px !important;
                padding: 4px 12px !important;
            }

            /* Tooltip */
            .ofast-tooltip-wrap {
                position: relative;
                display: inline-flex;
                align-items: center;
                margin-left: 8px;
                vertical-align: middle;
            }

            .ofast-tooltip-icon {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 24px;
                height: 24px;
                border-radius: 50%;
                background: rgba(99, 102, 241, 0.1);
                border: 1px solid rgba(99, 102, 241, 0.3);
                color: #6366f1;
                font-size: 13px;
                font-weight: 700;
                cursor: help;
                transition: all 0.25s ease;
            }

            .ofast-tooltip-icon:hover {
                background: #6366f1;
                border-color: #6366f1;
                color: #fff;
                transform: scale(1.1);
                box-shadow: 0 0 12px rgba(99, 102, 241, 0.4);
            }

            .ofast-tooltip-text {
                visibility: hidden;
                opacity: 0;
                position: absolute;
                bottom: calc(100% + 10px);
                left: 50%;
                transform: translateX(-50%) translateY(4px);
                background: rgba(15, 23, 42, 0.85);
                backdrop-filter: blur(16px);
                -webkit-backdrop-filter: blur(16px);
                color: #f1f5f9;
                font-size: 13px;
                font-weight: 400;
                line-height: 1.6;
                padding: 12px 16px;
                border-radius: 12px;
                border: 1px solid rgba(255, 255, 255, 0.12);
                min-width: 280px;
                max-width: 420px;
                white-space: normal;
                box-shadow: 0 12px 40px rgba(0, 0, 0, 0.3), 0 0 1px rgba(255, 255, 255, 0.1) inset;
                z-index: 1000;
                transition: opacity 0.25s ease, visibility 0.25s ease, transform 0.25s ease;
                pointer-events: none;
            }

            .ofast-tooltip-text::after {
                content: '';
                position: absolute;
                top: 100%;
                left: 50%;
                transform: translateX(-50%);
                border: 6px solid transparent;
                border-top-color: rgba(15, 23, 42, 0.85);
            }

            .ofast-tooltip-wrap:hover .ofast-tooltip-text {
                visibility: visible;
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
        </style>


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
                            <span style="vertical-align: middle; font-weight: 500;">Use SMTP for all WordPress emails</span>
                            <p class="description">When enabled, all emails will be sent through your configured mailer.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Mailer Type</th>
                        <td>
                            <select name="smtp_mailer_type" id="smtp_mailer_type" class="ofast-dropdown-native"
                                style="width: 360px;">
                                <option value="default" <?php selected($mailer_type, 'default'); ?>>PHP Mail (Default) - No
                                    credentials needed</option>
                                <option value="smtp" <?php selected($mailer_type, 'smtp'); ?>>Other SMTP - Custom server
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
                                    style="width: 360px;">
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
                            <span style="vertical-align: middle;">Store email body content in logs</span>
                            <p class="description">
                                <strong>Enabled by default</strong> — Email body content is stored for Preview &amp; Resend
                                functionality.<br>
                                When enabled, sensitive patterns (passwords, tokens, API keys) are automatically filtered before
                                storage. Disable to log only metadata (to, subject, status, timestamp).
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Log Retention (Days)</th>
                        <td>
                            <input type="number" name="log_retention_days" value="<?php echo esc_attr($log_retention_days); ?>"
                                min="0" max="3650" style="width: 90px;">
                            <p class="description">
                                Set to <strong>0</strong> to keep logs forever. Default is 90 days.
                            </p>
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
                            <p class="description">Reports are sent to <?php echo esc_html(get_option('admin_email')); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- ============================================ -->
            <!-- Bulk Email Sending Throttle -->
            <!-- ============================================ -->
            <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin: 30px 0;"<?php echo ! ofast_toolkit_is_pro() ? ' class="ofast-pro-locked-section"' : ''; ?>>
                <?php if ( ! ofast_toolkit_is_pro() ): ?>
                <div class="ofast-pro-overlay">
                    <div class="ofast-pro-lock-icon"><span class="dashicons dashicons-lock"></span></div>
                    <div class="ofast-pro-overlay-text">Pro Feature</div>
                    <div class="ofast-pro-overlay-desc">Fine-tune send delays, batch sizes, and pause intervals to prevent SMTP rate-limit errors.</div>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ofast-license')); ?>" class="ofast-pro-upgrade-btn">
                        <span class="dashicons dashicons-star-filled" style="font-size:14px;width:14px;height:14px;"></span> Upgrade to Pro
                    </a>
                </div>
                <?php endif; ?>
                <h3 style="margin-top: 0;">Bulk Email Throttle</h3>
                <p style="color: #64748b;">Control how fast bulk emails (from the Emailer) are sent. Prevents your SMTP provider from throttling or rejecting sends. Adjust based on your provider's rate limits.</p>

                <?php
                $email_delay  = intval(get_option('ofast_email_send_delay',  2));
                $email_batch  = intval(get_option('ofast_email_batch_size',  50));
                $email_pause  = intval(get_option('ofast_email_batch_pause', 10));
                ?>

                <table class="form-table">
                    <tr>
                        <th><label for="email_send_delay">Delay Between Emails (seconds)</label></th>
                        <td>
                            <input type="number" name="email_send_delay" id="email_send_delay"
                                value="<?php echo esc_attr($email_delay); ?>" min="0" max="30" step="1" style="width: 80px;">
                            <span class="description">0 = no delay. Most providers: use 2&ndash;3 s.</span>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="email_batch_size">Emails Per Batch</label></th>
                        <td>
                            <input type="number" name="email_batch_size" id="email_batch_size"
                                value="<?php echo esc_attr($email_batch); ?>" min="1" max="500" step="1" style="width: 80px;">
                            <span class="description">How many emails to send before a longer pause. Default: 50.</span>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="email_batch_pause">Pause Between Batches (seconds)</label></th>
                        <td>
                            <input type="number" name="email_batch_pause" id="email_batch_pause"
                                value="<?php echo esc_attr($email_pause); ?>" min="0" max="120" step="1" style="width: 80px;">
                            <span class="description">Extra rest between batches. Default: 10 s.</span>
                        </td>
                    </tr>
                </table>

                <div style="margin-top:12px; padding:10px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; font-size:13px; color:#475569;">
                    <strong>Tip:</strong> Most free-tier SMTP providers (Brevo, SendGrid, Mailgun, etc.) have per-minute and daily limits. A 2&ndash;3 second delay between emails prevents rate-limit errors.
                </div>
            </div>

            <p class="submit">
                <button type="submit" name="ofast_smtp_save" class="button button-primary button-large">Save SMTP
                    Settings</button>
            </p>
        </form>


        <script>
            jQuery(document).ready(function ($) {
                // Encryption segmented control
                $('.ofast-encryption-group label').on('click', function () {
                    $(this).closest('.ofast-encryption-group').find('label').removeClass('active');
                    $(this).addClass('active');
                });
            });
        </script>


        <script>
            jQuery(document).ready(function ($) {
                $('#smtp_mailer_type').on('change', function () {
                    var isSmtp = $(this).val() === 'smtp';
                    $('#smtp-credentials-section').toggle(isSmtp);
                    $('#rate-limit-section').toggle(isSmtp);
                    $('#mailer_note').text(isSmtp
                        ? 'Requires SMTP server credentials. Better deliverability with providers like SendGrid, Mailgun.'
                        : 'Uses your server\'s built-in mail function. Only From Email/Name needed. Best for most hosts.');
                });

                // Toggle fallback section
                $('#fallback_enabled').on('change', function () {
                    $('#fallback-smtp-fields').toggle(this.checked);
                });

                // Toggle health report fields visibility
                $('input[name="health_report_enabled"]').on('change', function () {
                    $('select[name="health_report_interval"]').closest('tr').toggle(this.checked);
                });
            });
        </script>



        <!-- ============================================ -->
        <!-- Port Connectivity Test -->
        <!-- ============================================ -->
        <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 24px; margin-top: 30px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                <div>
                    <h2 style="margin: 0;">Port Connectivity Test</h2>
                    <p style="color: #64748b; margin: 4px 0 0;">Probe your SMTP server to check which ports are open, auth
                        methods, and detect security issues.</p>
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-shrink: 0;">
                    <input type="text" id="port-test-hostname" placeholder="smtp.yourdomain.com"
                        value="<?php echo esc_attr(get_option('ofast_smtp_host', '')); ?>" class="regular-text"
                        style="border-radius: 8px; border: 1px solid #d7deea; padding: 8px 12px;">
                    <button type="button" id="ofast-run-port-test" class="button button-primary"
                        style="background: linear-gradient(135deg, #6366f1, #4f46e5) !important; border-color: #6366f1 !important; text-shadow: none !important; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3) !important; padding: 8px 20px !important; height: auto !important; border-radius: 8px !important; font-weight: 600 !important;">Test
                        Ports</button>
                    <span id="port-test-spinner" class="spinner" style="float: none;"></span>
                </div>
            </div>

            <div id="port-test-results" style="display: none; margin-top: 20px;">
                <!-- Tab navigation -->
                <div id="port-tabs" style="display: flex; border-bottom: 2px solid #e5e7eb;"></div>
                <!-- Tab content -->
                <div id="port-tab-content" style="padding: 20px 0;"></div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                $('#ofast-run-port-test').on('click', function () {
                    var hostname = $('#port-test-hostname').val().trim();
                    if (!hostname) { alert('Enter a hostname first'); return; }

                    var $btn = $(this);
                    var $spinner = $('#port-test-spinner');
                    var $results = $('#port-test-results');

                    $btn.prop('disabled', true);
                    $spinner.addClass('is-active');
                    $results.hide();

                    $.post(ajaxurl, {
                        action: 'ofast_smtp_port_test',
                        nonce: ofastSMTP.port_test_nonce,
                        hostname: hostname,
                        ports: [25, 465, 587]
                    }, function (response) {
                        $btn.prop('disabled', false);
                        $spinner.removeClass('is-active');

                        if (!response.success) {
                            $results.html('<div style="padding:12px;background:#fee2e2;border-radius:8px;color:#991b1b;">' + response.data + '</div>').show();
                            return;
                        }

                        var portLabels = { 25: 'Port 25 (Plain)', 465: 'Port 465 (SSL)', 587: 'Port 587 (TLS)' };
                        var portOrder = [587, 465, 25];
                        var tabs = '';
                        var panels = {};
                        var firstPort = null;

                        $.each(portOrder, function (i, port) {
                            var r = response.data.results[port];
                            if (!r) return;
                            if (!firstPort) firstPort = port;

                            var statusDot = r.open ? '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#10b981;margin-right:6px;"></span>' : '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#ef4444;margin-right:6px;"></span>';
                            var isActive = (port == firstPort);

                            tabs += '<button type="button" class="port-tab" data-port="' + port + '" style="padding: 10px 20px; border: none; background: ' + (isActive ? '#f8fafc' : 'transparent') + '; cursor: pointer; font-weight: ' + (isActive ? '600' : '400') + '; font-size: 13px; color: ' + (isActive ? '#6366f1' : '#64748b') + '; border-bottom: 2px solid ' + (isActive ? '#6366f1' : 'transparent') + '; margin-bottom: -2px; transition: all 0.2s;">' + statusDot + (portLabels[port] || 'Port ' + port) + '</button>';

                            var panel = '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">';
                            panel += '<div style="background: ' + (r.open ? '#f0fdf4' : '#fef2f2') + '; border: 1px solid ' + (r.open ? '#bbf7d0' : '#fecaca') + '; border-radius: 10px; padding: 16px;">';
                            panel += '<div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; margin-bottom: 8px;">Status</div>';
                            panel += '<div style="font-size: 18px; font-weight: 700; color: ' + (r.open ? '#059669' : '#dc2626') + ';">' + (r.open ? 'Open' : 'Closed') + '</div>';
                            panel += '</div>';

                            if (r.open) {
                                panel += '<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px;">';
                                panel += '<div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; margin-bottom: 8px;">Security</div>';
                                var secItems = [];
                                if (r.secure) secItems.push('🔒 Encrypted');
                                if (r.starttls) secItems.push('↗ STARTTLS');
                                if (!r.secure && !r.starttls) secItems.push('⚠ No encryption');
                                panel += '<div style="font-weight: 600;">' + secItems.join(' &nbsp;·&nbsp; ') + '</div>';
                                panel += '</div>';

                                var auths = [];
                                if (r.auth_login) auths.push('LOGIN');
                                if (r.auth_plain) auths.push('PLAIN');
                                if (r.auth_crammd5) auths.push('CRAM-MD5');
                                if (r.auth_xoauth) auths.push('XOAUTH2');
                                if (auths.length) {
                                    panel += '<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px;">';
                                    panel += '<div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; margin-bottom: 8px;">Auth Methods</div>';
                                    panel += '<div style="display: flex; gap: 6px; flex-wrap: wrap;">';
                                    $.each(auths, function (j, a) {
                                        panel += '<span style="background: #eef2ff; color: #4338ca; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 500;">' + a + '</span>';
                                    });
                                    panel += '</div></div>';
                                }

                                if (r.mitm) {
                                    panel += '<div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 16px;">';
                                    panel += '<div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #92400e; margin-bottom: 8px;">⚠ MITM Warning</div>';
                                    panel += '<div style="color: #92400e; font-weight: 500;">' + (r.mitm_detail || 'Certificate hostname mismatch detected') + '</div>';
                                    panel += '</div>';
                                }
                            } else if (r.error) {
                                panel += '<div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 16px;">';
                                panel += '<div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; margin-bottom: 8px;">Error</div>';
                                panel += '<div style="color: #991b1b; font-size: 13px;">' + r.error.substring(0, 120) + '</div>';
                                panel += '</div>';
                            }
                            panel += '</div>';
                            panels[port] = panel;
                        });

                        $('#port-tabs').html(tabs);
                        $('#port-tab-content').html(panels[firstPort] || '');
                        $results.show();

                        // Tab switching
                        $('#port-tabs').off('click', '.port-tab').on('click', '.port-tab', function () {
                            var port = $(this).data('port');
                            $('#port-tabs .port-tab').css({ background: 'transparent', fontWeight: '400', color: '#64748b', borderBottom: '2px solid transparent' });
                            $(this).css({ background: '#f8fafc', fontWeight: '600', color: '#6366f1', borderBottom: '2px solid #6366f1' });
                            $('#port-tab-content').html(panels[port] || '');
                        });
                    });
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
                    <p style="color: #f59e0b;"><strong>Note:</strong> New accounts start in sandbox mode (verify recipients
                        first).</p>
                </div>
            </details>
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

        // Shared pagination CSS (reusable across modules)
        wp_enqueue_style(
            'ofast-pagination',
            OFAST_X_PLUGIN_URL . 'assets/css/ofast-pagination.css',
            array(),
            OFAST_X_VERSION
        );

        wp_enqueue_script(
            'ofast-smtp-admin',
            plugins_url('assets/smtp-admin.js', __FILE__),
            array('jquery'),
            OFAST_X_VERSION,
            true
        );

        wp_localize_script('ofast-smtp-admin', 'ofastSMTP', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ofast_test_smtp'),
            'port_test_nonce' => wp_create_nonce('ofast_port_test'),
            'presets' => Ofast_X_SMTP::get_provider_presets()
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

        // Bulk email throttle settings (Pro feature)
        if (ofast_toolkit_is_pro()) {
            update_option('ofast_email_send_delay',  max(0, intval($_POST['email_send_delay']  ?? 2)));
            update_option('ofast_email_batch_size',  max(1, intval($_POST['email_batch_size']  ?? 50)));
            update_option('ofast_email_batch_pause', max(0, intval($_POST['email_batch_pause'] ?? 10)));
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
