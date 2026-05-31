<?php

/**
 * Ofast X - SMTP Email Health Reports
 * Sends scheduled digest emails to the admin with send/fail statistics.
 * Supports daily, weekly, and monthly intervals via WP-Cron.
 *
 * @since 1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_SMTP_Health_Report
{
    public function __construct()
    {
        add_action('init', array($this, 'schedule_report'));
        add_action('ofast_smtp_health_report', array($this, 'send_report'));
        add_filter('cron_schedules', array($this, 'add_custom_schedules'));
    }

    /**
     * Add weekly and monthly cron schedules (WordPress only ships with hourly/twicedaily/daily)
     */
    public function add_custom_schedules($schedules)
    {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = array(
                'interval' => 7 * DAY_IN_SECONDS,
                'display'  => 'Once Weekly',
            );
        }
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = array(
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => 'Once Monthly',
            );
        }
        return $schedules;
    }

    /**
     * Schedule the health report cron event based on settings.
     * Re-schedules if the interval has changed.
     */
    public function schedule_report()
    {
        $enabled = get_option('ofast_smtp_health_report_enabled', false);
        $interval = get_option('ofast_smtp_health_report_interval', 'weekly');

        $valid_intervals = array('daily', 'weekly', 'monthly');
        if (!in_array($interval, $valid_intervals)) {
            $interval = 'weekly';
        }

        $hook = 'ofast_smtp_health_report';

        if (!$enabled) {
            // Unschedule if disabled
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
            return;
        }

        // Check if already scheduled with the right interval
        $existing = wp_next_scheduled($hook);
        if ($existing) {
            // Check if interval changed
            $crons = _get_cron_array();
            foreach ($crons as $timestamp => $cron) {
                if (isset($cron[$hook])) {
                    foreach ($cron[$hook] as $key => $data) {
                        if (isset($data['schedule']) && $data['schedule'] !== $interval) {
                            // Interval changed — reschedule
                            wp_unschedule_event($timestamp, $hook);
                            wp_schedule_event(time() + HOUR_IN_SECONDS, $interval, $hook);
                        }
                    }
                }
            }
        } else {
            // Not scheduled yet — schedule now
            wp_schedule_event(time() + HOUR_IN_SECONDS, $interval, $hook);
        }
    }

    /**
     * Send the health report email.
     * Triggered by WP-Cron or manually via do_action('ofast_smtp_health_report').
     */
    public function send_report()
    {
        $interval = get_option('ofast_smtp_health_report_interval', 'weekly');
        $stats = $this->get_period_stats($interval);
        $lifetime = Ofast_X_SMTP::get_delivery_stats();

        $body = $this->build_report_html($interval, $stats, $lifetime);

        $duration_label = ucfirst($interval);
        $site_name = get_bloginfo('name');
        $to = get_option('admin_email');
        $subject = sprintf('Your %s SMTP Report for %s', $duration_label, $site_name);
        $headers = array('Content-Type: text/html; charset=UTF-8');

        wp_mail($to, $subject, $body, $headers);
    }

    /**
     * Get email stats for the specified period.
     *
     * @param string $interval 'daily', 'weekly', or 'monthly'
     * @return array
     */
    private function get_period_stats($interval)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_smtp_log';

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($table_exists !== $table) {
            return array('total' => 0, 'success' => 0, 'failed' => 0, 'rate_limited' => 0, 'rate' => 0, 'top_errors' => array());
        }

        // Calculate date range
        switch ($interval) {
            case 'daily':
                $since = gmdate('Y-m-d H:i:s', strtotime('-1 day'));
                break;
            case 'monthly':
                $since = gmdate('Y-m-d H:i:s', strtotime('-30 days'));
                break;
            case 'weekly':
            default:
                $since = gmdate('Y-m-d H:i:s', strtotime('-7 days'));
                break;
        }

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE sent_at >= %s",
            $since
        ));

        $success = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE sent_at >= %s AND status IN ('success', 'sent', 'success (fallback)')",
            $since
        ));

        $failed = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE sent_at >= %s AND status = 'failed'",
            $since
        ));

        $rate_limited = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE sent_at >= %s AND status = 'rate_limited'",
            $since
        ));

        $rate = $total > 0 ? round(($success / $total) * 100) : 0;

        // Top error messages
        $top_errors = $wpdb->get_results($wpdb->prepare(
            "SELECT error_message, COUNT(*) as cnt FROM {$table} 
             WHERE sent_at >= %s AND status = 'failed' AND error_message != '' 
             GROUP BY error_message ORDER BY cnt DESC LIMIT 3",
            $since
        ));

        return array(
            'total'        => $total,
            'success'      => $success,
            'failed'       => $failed,
            'rate_limited' => $rate_limited,
            'rate'         => $rate,
            'top_errors'   => $top_errors,
        );
    }

    /**
     * Build the HTML email template for the health report.
     * Ofast-branded design with gradient header, stat cards, error breakdown.
     */
    private function build_report_html($interval, $stats, $lifetime)
    {
        $site_name = esc_html(get_bloginfo('name'));
        $site_url = esc_url(home_url());
        $duration = ucfirst($interval);
        $admin_url = esc_url(admin_url('admin.php?page=ofast-smtp'));
        $date_range = $this->get_date_range_label($interval);

        // Color for success rate
        $rate_color = $stats['rate'] >= 90 ? '#10b981' : ($stats['rate'] >= 70 ? '#f59e0b' : '#ef4444');

        $html = "
        <!DOCTYPE html>
        <html>
        <head><meta charset='UTF-8'></head>
        <body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, sans-serif; background: #f1f5f9; margin: 0; padding: 20px;'>
            <div style='max-width: 600px; margin: 0 auto;'>
                <!-- Header -->
                <div style='background: linear-gradient(135deg, #6366f1, #4f46e5); padding: 35px 30px; border-radius: 12px 12px 0 0; text-align: center;'>
                    <h1 style='color: #fff; margin: 0 0 5px 0; font-size: 22px; font-weight: 700;'>SMTP Health Report</h1>
                    <p style='color: rgba(255,255,255,0.85); margin: 0; font-size: 14px;'>{$duration} report for {$site_name}</p>
                    <p style='color: rgba(255,255,255,0.65); margin: 5px 0 0 0; font-size: 12px;'>{$date_range}</p>
                </div>

                <!-- Stats Cards -->
                <div style='background: #fff; padding: 30px; border-left: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;'>
                    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse: collapse;'>
                        <tr>
                            <td width='25%' style='text-align: center; padding: 15px 5px;'>
                                <div style='font-size: 32px; font-weight: 700; color: #6366f1;'>{$stats['total']}</div>
                                <div style='font-size: 12px; color: #6b7280; margin-top: 4px;'>Total Emails</div>
                            </td>
                            <td width='25%' style='text-align: center; padding: 15px 5px;'>
                                <div style='font-size: 32px; font-weight: 700; color: #10b981;'>{$stats['success']}</div>
                                <div style='font-size: 12px; color: #6b7280; margin-top: 4px;'>Delivered</div>
                            </td>
                            <td width='25%' style='text-align: center; padding: 15px 5px;'>
                                <div style='font-size: 32px; font-weight: 700; color: #ef4444;'>{$stats['failed']}</div>
                                <div style='font-size: 12px; color: #6b7280; margin-top: 4px;'>Failed</div>
                            </td>
                            <td width='25%' style='text-align: center; padding: 15px 5px;'>
                                <div style='font-size: 32px; font-weight: 700; color: {$rate_color};'>{$stats['rate']}%</div>
                                <div style='font-size: 12px; color: #6b7280; margin-top: 4px;'>Success Rate</div>
                            </td>
                        </tr>
                    </table>";

        // Rate-limited emails
        if ($stats['rate_limited'] > 0) {
            $html .= "
                    <div style='margin-top: 15px; padding: 12px 15px; background: #fef3c7; border-radius: 8px; border-left: 4px solid #f59e0b;'>
                        <strong style='color: #92400e;'>⚠ {$stats['rate_limited']} email(s) blocked by rate limiting</strong>
                    </div>";
        }

        // Top errors
        if (!empty($stats['top_errors'])) {
            $html .= "
                    <div style='margin-top: 20px;'>
                        <h3 style='margin: 0 0 10px 0; font-size: 14px; color: #374151;'>Common Errors</h3>
                        <table width='100%' cellpadding='8' cellspacing='0' style='border-collapse: collapse; font-size: 13px;'>";

            foreach ($stats['top_errors'] as $error) {
                $error_text = esc_html(substr($error->error_message, 0, 80));
                $error_count = intval($error->cnt);
                $html .= "
                            <tr style='border-bottom: 1px solid #f3f4f6;'>
                                <td style='color: #374151;'>{$error_text}</td>
                                <td style='text-align: right; color: #ef4444; font-weight: 600; white-space: nowrap;'>×{$error_count}</td>
                            </tr>";
            }

            $html .= "
                        </table>
                    </div>";
        }

        // Lifetime stats
        $html .= "
                    <div style='margin-top: 20px; padding: 15px; background: #f8fafc; border-radius: 8px;'>
                        <h3 style='margin: 0 0 8px 0; font-size: 13px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;'>Lifetime Totals</h3>
                        <table width='100%' cellpadding='0' cellspacing='0' style='font-size: 13px; color: #374151;'>
                            <tr>
                                <td>Emails Delivered</td>
                                <td style='text-align: right; font-weight: 600;'>" . number_format($lifetime['success']) . "</td>
                            </tr>
                            <tr>
                                <td>Emails Failed</td>
                                <td style='text-align: right; font-weight: 600; color: #ef4444;'>" . number_format($lifetime['failed']) . "</td>
                            </tr>";

        if ($lifetime['fallback_used'] > 0) {
            $html .= "
                            <tr>
                                <td>Fallback Recoveries</td>
                                <td style='text-align: right; font-weight: 600; color: #f59e0b;'>" . number_format($lifetime['fallback_used']) . "</td>
                            </tr>";
        }

        $html .= "
                        </table>
                    </div>

                    <!-- CTA -->
                    <div style='text-align: center; margin-top: 25px;'>
                        <a href='{$admin_url}' style='display: inline-block; background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; padding: 12px 30px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px;'>View Full Dashboard →</a>
                    </div>
                </div>

                <!-- Footer -->
                <div style='background: #f8fafc; padding: 20px 30px; border-radius: 0 0 12px 12px; border: 1px solid #e5e7eb; border-top: none; text-align: center;'>
                    <p style='margin: 0; font-size: 12px; color: #94a3b8;'>
                        This report was sent by <strong>Ofast SMTP</strong> on <a href='{$site_url}' style='color: #6366f1; text-decoration: none;'>{$site_name}</a>.
                        <br>To disable, go to SMTP → Settings → Health Reports.
                    </p>
                </div>
            </div>
        </body>
        </html>";

        return $html;
    }

    /**
     * Get a human-readable date range label.
     */
    private function get_date_range_label($interval)
    {
        switch ($interval) {
            case 'daily':
                return gmdate('M j, Y', strtotime('-1 day')) . ' — ' . gmdate('M j, Y');
            case 'monthly':
                return gmdate('M j', strtotime('-30 days')) . ' — ' . gmdate('M j, Y');
            case 'weekly':
            default:
                return gmdate('M j', strtotime('-7 days')) . ' — ' . gmdate('M j, Y');
        }
    }
}
