<?php

/**
 * Ofast X - Newsletter Module
 * Newsletter subscription system with admin management and CSV export
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Newsletter
{

    /**
     * Initialize module
     */
    public function init()
    {
        // Only load if module is enabled
        $enabled = get_option('ofastx_modules_enabled', array());
        if (empty($enabled['newsletter'])) {
            return;
        }

        // Add shortcode
        add_shortcode('ofast_newsletter', array($this, 'render_newsletter_form'));

        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Handle form submission
        add_action('admin_post_nopriv_ofast_newsletter_submit', array($this, 'handle_submission'));
        add_action('admin_post_ofast_newsletter_submit', array($this, 'handle_submission'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'Newsletter Subscribers',
            'Newsletter',
            'manage_options',
            'ofast-newsletter',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Render newsletter subscription form
     */
    public function render_newsletter_form($atts)
    {
        $atts = shortcode_atts(array(
            'style' => 'default',
            'title' => 'Subscribe to Our Newsletter',
            'button_text' => 'Subscribe'
        ), $atts);

        // Check for messages
        $message = '';
        if (isset($_GET['newsletter'])) {
            switch ($_GET['newsletter']) {
                case 'success':
                    $message = '<div class="ofast-newsletter-success">Successfully subscribed!</div>';
                    break;
                case 'exists':
                    $message = '<div class="ofast-newsletter-error">Email already subscribed.</div>';
                    break;
                case 'invalid':
                    $message = '<div class="ofast-newsletter-error">Invalid email or name.</div>';
                    break;
                case 'spam':
                    $message = '<div class="ofast-newsletter-error">Spam verification failed. Please try again.</div>';
                    break;
                case 'error':
                    $message = '<div class="ofast-newsletter-error">Error. Try again.</div>';
                    break;
            }
        }

        ob_start();
?>
        <style>
            .ofast-newsletter-form {
                max-width: 500px;
                margin: 20px auto;
                padding: 30px;
                background: #f9f9f9;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            }

            .ofast-newsletter-form h3 {
                margin-top: 0;
                color: #333;
            }

            .ofast-newsletter-form input[type="text"],
            .ofast-newsletter-form input[type="email"] {
                width: 100%;
                padding: 12px;
                margin-bottom: 15px;
                border: 1px solid #ddd;
                border-radius: 5px;
                font-size: 14px;
            }

            .ofast-newsletter-form button {
                width: 100%;
                padding: 12px;
                background: #1e88e5;
                color: white;
                border: none;
                border-radius: 5px;
                font-size: 16px;
                cursor: pointer;
            }

            .ofast-newsletter-form button:hover {
                background: #1565c0;
            }

            .ofast-newsletter-success {
                padding: 15px;
                background: #e6ffed;
                border-left: 4px solid #28a745;
                margin-bottom: 15px;
                border-radius: 5px;
            }

            .ofast-newsletter-error {
                padding: 15px;
                background: #ffe6e6;
                border-left: 4px solid #dc3545;
                margin-bottom: 15px;
                border-radius: 5px;
            }
        </style>

        <div class="ofast-newsletter-form">
            <?php echo $message; ?>
            <h3><?php echo esc_html($atts['title']); ?></h3>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('ofast_newsletter_action', 'ofast_newsletter_nonce'); ?>
                <input type="hidden" name="action" value="ofast_newsletter_submit">
                <input type="text" name="subscriber_name" placeholder="Your Name" required>
                <input type="email" name="subscriber_email" placeholder="Your Email" required>
                <?php
                // Render Turnstile widget if configured
                if (class_exists('Ofast_X_Turnstile')) {
                    $turnstile = Ofast_X_Turnstile::get_instance();
                    if ($turnstile->is_configured()) {
                        echo '<div style="margin: 15px 0;">';
                        echo $turnstile->render_widget('newsletter');
                        echo '</div>';
                        echo Ofast_X_Turnstile::render_script();
                    }
                }
                ?>
                <button type="submit"><?php echo esc_html($atts['button_text']); ?></button>
            </form>
            <p style="font-size:12px;color:#999;margin-top:10px;text-align:center;">We respect your privacy.</p>
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * Handle newsletter subscription
     */
    public function handle_submission()
    {
        if (!isset($_POST['ofast_newsletter_nonce']) || !wp_verify_nonce($_POST['ofast_newsletter_nonce'], 'ofast_newsletter_action')) {
            wp_die('Security check failed');
        }

        // Verify Turnstile if configured
        if (class_exists('Ofast_X_Turnstile')) {
            $turnstile = Ofast_X_Turnstile::get_instance();
            if ($turnstile->is_configured()) {
                $token = isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '';
                $result = $turnstile->verify($token);
                if (!$result['success']) {
                    wp_redirect(add_query_arg('newsletter', 'spam', wp_get_referer()));
                    exit;
                }
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_newsletter_subscribers';

        // SECURITY: Remove newlines from name to prevent email header injection
        $name = str_replace(array("\r", "\n", "\t"), '', sanitize_text_field($_POST['subscriber_name']));
        $email = sanitize_email($_POST['subscriber_email']);

        // SECURITY: Better IP detection (handles proxies)
        $ip = $this->get_client_ip();

        if (empty($name) || empty($email) || !is_email($email)) {
            wp_redirect(add_query_arg('newsletter', 'invalid', wp_get_referer()));
            exit;
        }

        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE email = %s", $email));
        if ($exists) {
            wp_redirect(add_query_arg('newsletter', 'exists', wp_get_referer()));
            exit;
        }

        $inserted = $wpdb->insert($table, array(
            'email' => $email,
            'name' => $name,
            'status' => 'confirmed',
            'subscribed_at' => current_time('mysql'),
            'ip_address' => $ip
        ));

        if ($inserted) {
            // Dispatch notification via Notification Hub (Email, WhatsApp, Google Sheets)
            if (class_exists('Ofast_X_Notification_Hub')) {
                Ofast_X_Notification_Hub::dispatch(
                    Ofast_X_Notification_Hub::EVENT_NEWSLETTER_SUBSCRIPTION,
                    array(
                        'email' => $email,
                        'name' => $name,
                        'ip' => $ip,
                        'source' => wp_get_referer() ? wp_get_referer() : 'direct'
                    )
                );
            } else {
                // Fallback to direct email if hub not available
                $admin_email = get_option('admin_email');
                wp_mail($admin_email, 'New Newsletter Subscriber', "Name: " . esc_html($name) . "\nEmail: " . esc_html($email), array('Content-Type: text/html'));
            }
            wp_redirect(add_query_arg('newsletter', 'success', wp_get_referer()));
            exit;
        } else {
            wp_redirect(add_query_arg('newsletter', 'error', wp_get_referer()));
            exit;
        }
    }

    /**
     * Render admin page
     */
    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_newsletter_subscribers';

        if (isset($_GET['action']) && $_GET['action'] == 'export') {
            $subscribers = $wpdb->get_results("SELECT * FROM $table ORDER BY subscribed_at DESC");
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="newsletter-' . date('Y-m-d') . '.csv"');
            $output = fopen('php://output', 'w');
            fputcsv($output, array('ID', 'Name', 'Email', 'Status', 'IP', 'Date'));
            foreach ($subscribers as $sub) {
                fputcsv($output, array($sub->id, $sub->name, $sub->email, $sub->status, $sub->ip_address, $sub->subscribed_at));
            }
            fclose($output);
            exit;
        }

        if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
            check_admin_referer('delete_subscriber_' . $_GET['id']);
            $wpdb->delete($table, array('id' => intval($_GET['id'])));
            echo '<div class="notice notice-success"><p>Deleted!</p></div>';
        }

        $subscribers = $wpdb->get_results("SELECT * FROM $table ORDER BY subscribed_at DESC");
        $total = count($subscribers);

        // Check Turnstile status
        $turnstile_configured = class_exists('Ofast_X_Turnstile') && Ofast_X_Turnstile::get_instance()->is_configured();
    ?>
        <div class="wrap">
            <h1>Newsletter Subscribers (<?php echo $total; ?>)</h1>
            <p><a href="?page=ofast-newsletter&action=export" class="button button-primary">Export CSV</a></p>

            <!-- Two column layout for shortcode and turnstile status -->
            <div style="display: flex; gap: 20px; margin: 20px 0; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 300px; background:#f0f8ff;padding:15px;border-left:4px solid #1e88e5;border-radius:5px;">
                    <h3 style="margin-top:0;">Add Newsletter Form</h3>
                    <code style="background:white;padding:10px;display:block;border-radius:5px;">[ofast_newsletter title="Subscribe Now"]</code>
                </div>
                <div style="flex: 1; min-width: 300px; background:<?php echo $turnstile_configured ? '#e8f5e9' : '#fff8e1'; ?>;padding:15px;border-left:4px solid <?php echo $turnstile_configured ? '#4caf50' : '#ff9800'; ?>;border-radius:5px;">
                    <h3 style="margin-top:0;">
                        🛡️ Spam Protection
                        <?php if ($turnstile_configured): ?>
                            <span style="color:#4caf50;font-size:14px;font-weight:normal;">✓ Active</span>
                        <?php else: ?>
                            <span style="color:#ff9800;font-size:14px;font-weight:normal;">⚠ Not Configured</span>
                        <?php endif; ?>
                    </h3>
                    <p style="margin-bottom:10px;"><?php echo $turnstile_configured ? 'Cloudflare Turnstile is protecting your forms.' : 'Configure Turnstile to protect against spam bots.'; ?></p>
                    <a href="<?php echo admin_url('admin.php?page=ofast-settings'); ?>" class="button button-secondary">
                        <?php echo $turnstile_configured ? 'View Settings' : 'Configure Turnstile'; ?>
                    </a>
                </div>
            </div>

            <?php if ($subscribers): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>IP</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subscribers as $sub): ?>
                            <tr>
                                <td><?php echo $sub->id; ?></td>
                                <td><strong><?php echo esc_html($sub->name); ?></strong></td>
                                <td><?php echo esc_html($sub->email); ?></td>
                                <td><span style="padding:3px 8px;background:#e6ffed;border:1px solid #28a745;border-radius:3px;font-size:11px;"><?php echo $sub->status; ?></span></td>
                                <td><?php echo esc_html($sub->ip_address); ?></td>
                                <td><?php echo date('M j, Y', strtotime($sub->subscribed_at)); ?></td>
                                <td><a href="<?php echo wp_nonce_url('?page=ofast-newsletter&action=delete&id=' . $sub->id, 'delete_subscriber_' . $sub->id); ?>" class="button button-small" onclick="return confirm('Delete?')">Delete</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="text-align:center;padding:40px;color:#999;">No subscribers yet!</p>
            <?php endif; ?>
        </div>
<?php
    }

    /**
     * SECURITY: Get real client IP (handles proxies)
     */
    private function get_client_ip()
    {
        $ip_headers = array(
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Proxies
            'HTTP_X_REAL_IP',            // Nginx
            'REMOTE_ADDR'                // Default
        );

        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // X-Forwarded-For can contain multiple IPs, take the first
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                // Validate IP format
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}
