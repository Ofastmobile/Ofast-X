<?php

/**
 * Ofast X Email Main Controller
 * Complete email system with scheduler - ALL 13 FIXES INTEGRATED
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
    }

    /**
     * Load all required files
     */
    private function load_dependencies()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-admin.php';
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
     * Setup system hooks (INTEGRATED ALL 13 FIXES)
     */
    private function setup_hooks()
    {
        // Email sending hook with batch tracking
        add_action('ofast_scheduled_email_event', array($this, 'handle_scheduled_email'), 10, 6);

        // Daily cleanup
        if (!wp_next_scheduled('ofast_email_cleanup')) {
            wp_schedule_event(time(), 'daily', 'ofast_email_cleanup');
        }
        add_action('ofast_email_cleanup', array($this, 'cleanup_old_logs'));
    }

    /**
     * Handle scheduled emails (with batch tracking)
     */
    public function handle_scheduled_email($subject, $body, $selected_roles, $selected_user_ids, $batch_number = 1, $total_batches = 1)
    {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('ofast_email_from_name', 'Ofastshop Digitals') . ' <' . get_option('ofast_email_reply_to', 'support@ofastshop.com') . '>',
            'Reply-To: ' . get_option('ofast_email_reply_to', 'support@ofastshop.com')
        ];

        $recipients = [];

        if (!empty($selected_roles)) {
            $recipients = get_users(['role__in' => $selected_roles]);
        }

        if (!empty($selected_user_ids)) {
            $recipients = array_merge($recipients, get_users(['include' => $selected_user_ids]));
        }

        $sent_count = 0;
        foreach ($recipients as $user) {
            $final_body = $this->replace_placeholders($body, $user);
            if (wp_mail($user->user_email, $subject, $this->get_email_template($final_body), $headers)) {
                $sent_count++;
            }
        }

        // Log with batch info
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'ofast_email_logs', [
            'subject' => $subject,
            'sent_at' => current_time('mysql'),
            'recipient_count' => $sent_count,
            'notes' => "Batch $batch_number of $total_batches (scheduled)"
        ]);
    }

    /**
     * Replace placeholders in email body
     */
    private function replace_placeholders($body, $user)
    {
        return str_replace(
            ['{{user_id}}', '{{username}}', '{{user_display_name}}', '{{user_first_name}}', '{{user_last_name}}', '{{user_email}}'],
            [$user->ID, $user->user_login, $user->display_name, $user->first_name, $user->last_name, $user->user_email],
            $body
        );
    }

    /**
     * Get email template (FIX #9, #10, #11, #12, #13)
     */
    private function get_email_template($content)
    {
        $site_name = get_option('ofast_email_site_name', 'Ofastshop Digitals');
        $company_name = get_option('ofast_email_company_name', 'Ofastshop Digitals');
        $owner_name = get_option('ofast_email_owner_name', 'Bofast World');
        $logo_url = get_option('ofast_email_logo', 'https://pub-f02915809d3846b8ab0aaedeab54dbf7.r2.dev/ofastshop/2025/01/18150519/OFASTSHOP-DIGITALS-e1728849768928.png');
        $tagline = get_option('ofast_email_tagline', 'Learn. Create. Earn');
        $subtitle = get_option('ofast_email_subtitle', 'Africa\'s No 1 Digital Learning Hub');
        $social = get_option('ofast_email_social', []);

        ob_start(); ?>
        <style>
            @media screen and (max-width: 600px) {
                .ofast-email-body {
                    padding: 15px 15px !important;
                }

                .ofast-email-outer {
                    padding: 20px 10px !important;
                }
            }
        </style>
        <div class="ofast-email-outer" style="background:#f9f9f9;padding:30px;font-family:Segoe UI,Tahoma,Geneva,Verdana,sans-serif;color:#333;">
            <div class="ofast-email-body" style="max-width:600px;margin:auto;background:#fff;border-radius:8px;padding:20px 30px;line-height:1.6em;">

                <!-- Header -->
                <div style="text-align:center;">
                    <img src="<?php echo esc_url($logo_url); ?>" alt="Logo" style="max-width:400px;margin-bottom:10px;">
                    <h2 style="margin:5px 0 3px;color:#222;"><?php echo esc_html($tagline); ?></h2>
                    <p style="font-size:13px;color:#777;margin-top:3px;"><?php echo esc_html($subtitle); ?></p>
                </div>

                <hr style="border:none;border-top:1px solid #eee;margin:15px 0;">

                <!-- Email Content -->
                <div style="padding:20px 10px;font-size:14px;">
                    <?php echo wpautop($content); ?>
                </div>

                <hr style="border:none;border-top:1px solid #eee;">

                <!-- Footer -->
                <div style="text-align:center;font-size:13px;color:#888;">
                    <p>Follow us:</p>
                    <p><a href="https://ofastshop.com" style="color:#ffcc00;text-decoration:none;"><?php echo esc_html($site_name); ?></a></p>
                    <p style="margin:10px 0;">
                        <?php if (!empty($social['facebook'])): ?>
                            <a href="<?php echo esc_url($social['facebook']); ?>" style="display:inline-block;width:32px;height:32px;background:#000;border-radius:50%;padding:6px;margin:0 4px;"><img src="https://cdn-icons-png.flaticon.com/512/733/733547.png" alt="Facebook" width="20" style="filter:brightness(0) invert(1);"></a>
                        <?php endif; ?>
                        <?php if (!empty($social['x'])): ?>
                            <a href="<?php echo esc_url($social['x']); ?>" style="display:inline-block;width:32px;height:32px;background:#000;border-radius:50%;padding:6px;margin:0 4px;"><img src="https://cdn-icons-png.flaticon.com/512/5968/5968830.png" alt="X" width="20" style="filter:brightness(0) invert(1);"></a>
                        <?php endif; ?>
                        <?php if (!empty($social['youtube'])): ?>
                            <a href="<?php echo esc_url($social['youtube']); ?>" style="display:inline-block;width:32px;height:32px;background:#000;border-radius:50%;padding:6px;margin:0 4px;"><img src="https://cdn-icons-png.flaticon.com/512/1384/1384060.png" alt="YouTube" width="20" style="filter:brightness(0) invert(1);"></a>
                        <?php endif; ?>
                        <?php if (!empty($social['whatsapp'])): ?>
                            <a href="<?php echo esc_url($social['whatsapp']); ?>" style="display:inline-block;width:32px;height:32px;background:#000;border-radius:50%;padding:6px;margin:0 4px;"><img src="https://cdn-icons-png.flaticon.com/512/733/733585.png" alt="WhatsApp" width="20" style="filter:brightness(0) invert(1);"></a>
                        <?php endif; ?>
                    </p>
                    <p style="font-size:12px;color:#aaa;">© <?php echo date('Y'); ?> <?php echo esc_html($company_name); ?> Own by <a href="https://bofastworld.net"><?php echo esc_html($owner_name); ?></a>, Nigeria</p>
                </div>
            </div>
        </div>
<?php return ob_get_clean();
    }

    /**
     * Cleanup old email logs
     */
    public function cleanup_old_logs()
    {
        global $wpdb;
        $retention_days = get_option('ofastx_email_retention_days', 90);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ofast_email_logs WHERE sent_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retention_days
        ));
    }
}
