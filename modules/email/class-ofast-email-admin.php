<?php

/**
 * Ofast X Email Admin Interface
 * Slim router that delegates to tab-specific classes.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Email_Admin
{

    private $page_hook;

    /**
     * Initialize admin interface
     */
    public function init()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_ofast_preview_email', array($this, 'ajax_preview_email'));

        // Auto-repair table on admin init (runs once)
        add_action('admin_init', array($this, 'maybe_repair_table'));

        // SECURITY: Send HTTP security headers on our admin pages
        add_action('admin_init', array($this, 'send_security_headers'));
    }

    /**
     * Send HTTP security headers on plugin admin pages
     */
    public function send_security_headers()
    {
        if (!isset($_GET['page'])) return;
        $page = sanitize_key($_GET['page']);
        if ($page !== 'ofast-emailer') return;

        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
    }

    /**
     * Auto-repair email logs table if columns are missing
     */
    public function maybe_repair_table()
    {
        // Only run once per day to avoid performance issues
        $last_check = get_option('ofast_email_table_checked', 0);
        if (time() - $last_check < DAY_IN_SECONDS) {
            return;
        }
        update_option('ofast_email_table_checked', time());

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_email_logs';

        // Check if table exists
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            $charset = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $table (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                subject varchar(255) NOT NULL,
                body longtext,
                sent_at datetime DEFAULT CURRENT_TIMESTAMP,
                recipient_count int(11) DEFAULT 0,
                status varchar(50) DEFAULT 'sent',
                target_roles text,
                notes text,
                PRIMARY KEY (id)
            ) $charset;";
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            return;
        }

        // Check for missing columns and add them
        $columns = $wpdb->get_col("SHOW COLUMNS FROM $table");

        if (!in_array('body', $columns)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN body longtext AFTER subject");
        }

        if (!in_array('status', $columns)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN status varchar(50) DEFAULT 'sent' AFTER recipient_count");
        }

        if (!in_array('target_roles', $columns)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN target_roles text AFTER status");
        }
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'Ofast Emailer',
            'Emailer',
            'manage_options',
            'ofast-emailer',
            array($this, 'render_main_page')
        );
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts($hook)
    {
        if (strpos($hook, 'ofast-emailer') === false && strpos($hook, 'ofast-email') === false) {
            return;
        }

        wp_enqueue_script('jquery');
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        // Module CSS
        wp_enqueue_style(
            'ofast-pagination',
            OFAST_X_PLUGIN_URL . 'assets/css/ofast-pagination.css',
            array(),
            OFAST_X_VERSION
        );

        wp_enqueue_style(
            'ofast-email-admin',
            OFAST_X_PLUGIN_URL . 'modules/email/assets/css/email-admin.css',
            array(),
            OFAST_X_VERSION
        );

        // Module JS
        wp_enqueue_script(
            'ofast-email-tabs',
            OFAST_X_PLUGIN_URL . 'modules/email/assets/js/email-tabs.js',
            array('jquery'),
            OFAST_X_VERSION,
            true
        );

        wp_enqueue_script(
            'ofast-email-send',
            OFAST_X_PLUGIN_URL . 'modules/email/assets/js/email-send.js',
            array('jquery'),
            OFAST_X_VERSION,
            true
        );

        wp_localize_script('ofast-email-send', 'ofastEmailSend', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'previewNonce' => wp_create_nonce('ofast_preview_email'),
        ));

        wp_enqueue_script(
            'ofast-email-history',
            OFAST_X_PLUGIN_URL . 'modules/email/assets/js/email-history.js',
            array('jquery'),
            OFAST_X_VERSION,
            true
        );

        wp_enqueue_script(
            'ofast-email-templates',
            OFAST_X_PLUGIN_URL . 'modules/email/assets/js/email-templates.js',
            array('jquery', 'wp-color-picker'),
            OFAST_X_VERSION,
            true
        );
    }

    // ───────────────────────────────────────────────
    //  ROUTING: Main page renders tab shell + delegates
    // ───────────────────────────────────────────────

    /**
     * Render main page — tab shell + delegates to tab classes
     */
    public function render_main_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions');
        }

        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'send';
        ?>
        <!-- Styles loaded via ofast-email-admin.css -->

        <div class="wrap">
            <!-- Header -->
            <div class="ofast-header">
                <div class="ofast-header-icon">
                    <span class="dashicons dashicons-email-alt"></span>
                </div>
                <div class="ofast-header-content">
                    <h1>Ofast Emailer</h1>
                    <p>Send emails, manage drafts, view history, and customize templates.</p>
                </div>
            </div>

            <!-- Tabs Navigation -->
            <nav class="ofast-tabs-nav" id="emailer-tabs-nav">
                <a href="#" class="ofast-tab <?php echo $active_tab === 'send' ? 'active' : ''; ?>" data-tab="send">
                    <span class="dashicons dashicons-email"></span> Send Email
                </a>
                <a href="#" class="ofast-tab <?php echo $active_tab === 'drafts' ? 'active' : ''; ?>" data-tab="drafts">
                    <span class="dashicons dashicons-edit"></span> Drafts
                </a>
                <a href="#" class="ofast-tab <?php echo $active_tab === 'contacts' ? 'active' : ''; ?>" data-tab="contacts">
                    <span class="dashicons dashicons-groups"></span> Contacts
                </a>
                <a href="#" class="ofast-tab <?php echo $active_tab === 'history' ? 'active' : ''; ?>" data-tab="history">
                    <span class="dashicons dashicons-clock"></span> History
                </a>
                <a href="#" class="ofast-tab <?php echo $active_tab === 'templates' ? 'active' : ''; ?>" data-tab="templates">
                    <span class="dashicons dashicons-layout"></span> Templates
                </a>
                <a href="<?php echo admin_url('admin.php?page=ofast-smtp'); ?>" class="ofast-tab">
                    <span class="dashicons dashicons-email-alt2"></span> SMTP
                </a>
            </nav>

            <!-- Send Email Tab -->
            <div id="tab-send" class="ofast-tab-content<?php echo $active_tab === 'send' ? ' active' : ''; ?>">
                <?php $this->render_tab_send(); ?>
            </div>

            <!-- Drafts Tab -->
            <div id="tab-drafts" class="ofast-tab-content<?php echo $active_tab === 'drafts' ? ' active' : ''; ?>">
                <?php $this->render_tab_drafts(); ?>
            </div>

            <!-- Contacts Tab -->
            <div id="tab-contacts" class="ofast-tab-content<?php echo $active_tab === 'contacts' ? ' active' : ''; ?>">
                <?php $this->render_tab_contacts(); ?>
            </div>

            <!-- History Tab -->
            <div id="tab-history" class="ofast-tab-content<?php echo $active_tab === 'history' ? ' active' : ''; ?>">
                <?php $this->render_tab_history(); ?>
            </div>

            <!-- Templates Tab -->
            <div id="tab-templates" class="ofast-tab-content<?php echo $active_tab === 'templates' ? ' active' : ''; ?>">
                <?php $this->render_tab_templates(); ?>
            </div>

        </div>

        <!-- Tab switching loaded via email-tabs.js -->
        <?php
    }

    // ───────────────────────────────────────────────
    //  TAB DELEGATES: Load tab classes and render
    // ───────────────────────────────────────────────

    private function render_tab_send()
    {
        require_once __DIR__ . '/tabs/class-email-tab-send.php';
        $tab = new Ofast_Email_Tab_Send($this);
        $tab->render_send_page(true);
    }

    private function render_tab_contacts()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-contacts.php';
        $contacts_mod = new Ofast_X_Email_Contacts();
        $contacts_mod->render_page();
    }

    private function render_tab_drafts()
    {
        require_once __DIR__ . '/tabs/class-email-tab-drafts.php';
        $tab = new Ofast_Email_Tab_Drafts();
        $tab->render();
    }

    private function render_tab_history()
    {
        require_once __DIR__ . '/tabs/class-email-tab-history.php';
        $tab = new Ofast_Email_Tab_History();
        $tab->render();
    }

    private function render_tab_templates()
    {
        require_once __DIR__ . '/tabs/class-email-tab-templates.php';
        $tab = new Ofast_Email_Tab_Templates();
        $tab->render();
    }

    // ───────────────────────────────────────────────
    //  SHARED HELPERS (public so tab classes can use them)
    // ───────────────────────────────────────────────

    /**
     * Helper: Get email headers — delegates to centralized secure method
     */
    public function get_email_headers()
    {
        return Ofast_X_Email::get_safe_email_headers();
    }

    /**
     * Helper: Replace placeholders
     */
    public function replace_placeholders($body, $user)
    {
        return str_replace(
            ['{{user_id}}', '{{username}}', '{{user_display_name}}', '{{user_first_name}}', '{{user_last_name}}', '{{user_email}}'],
            [$user->ID, $user->user_login, $user->display_name, $user->first_name, $user->last_name, $user->user_email],
            $body
        );
    }

    /**
     * Helper: Log email
     *
     * @param string $subject       Email subject
     * @param int    $recipient_count Number of recipients sent to
     * @param string $notes         Free-text notes for the log entry
     * @param string $body          Email body HTML (for preview)
     * @param string $status        Status: sent, partial, failed, scheduled
     * @param array  $target_roles  Array of role slugs targeted
     */
    public function log_email($subject, $recipient_count, $notes, $body = '', $status = 'sent', $target_roles = array())
    {
        global $wpdb;
        $data = [
            'subject' => $subject,
            'body' => $body,
            'sent_at' => current_time('mysql'),
            'recipient_count' => $recipient_count,
            'status' => $status,
            'notes' => $notes
        ];

        if (!empty($target_roles)) {
            $data['target_roles'] = wp_json_encode(array_values(array_unique($target_roles)));
        }

        $result = $wpdb->insert($wpdb->prefix . 'ofast_email_logs', $data);

        if ($result === false) {
            error_log('Ofast Emailer: Failed to log email - ' . $wpdb->last_error);
        }
    }

    /**
     * AJAX: Preview email with modern template
     */
    public function ajax_preview_email()
    {
        check_ajax_referer('ofast_preview_email', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? 'Email Preview'));
        $message = wp_kses_post(wp_unslash($_POST['message'] ?? ''));

        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-template.php';
        $html = Ofast_X_Email_Template::get_template($message);

        wp_send_json_success(array('html' => $html));
    }

    /**
     * Helper: Get email template using modern design
     */
    public function get_email_template($content)
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-template.php';
        return Ofast_X_Email_Template::get_template($content);
    }

    /**
     * Render scheduled emails page (WordPress Cron queue view)
     */
    public function render_scheduled_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions');
        }

        // Handle cancel action
        if (isset($_POST['cancel_scheduled']) && wp_verify_nonce($_POST['cancel_nonce'], 'ofast_cancel_scheduled')) {
            $timestamp = intval($_POST['cancel_timestamp']);
            $args = json_decode(stripslashes($_POST['cancel_args']), true);
            wp_unschedule_event($timestamp, 'ofast_send_email_batch', array($args));
            echo Ofast_X_Toast::render('Scheduled batch cancelled.', 'success');
        }

        echo '<div class="wrap">';
        echo '<h1>Scheduled Email Batches</h1>';
        echo '<p>Email batches scheduled via WordPress Cron (lightweight, no dependencies)</p>';

        $events = _get_cron_array();
        $scheduled_batches = array();

        foreach ($events as $timestamp => $hooks) {
            foreach ($hooks as $hook => $jobs) {
                if ($hook === 'ofast_send_email_batch') {
                    foreach ($jobs as $key => $details) {
                        $args = isset($details['args'][0]) ? $details['args'][0] : array();
                        $scheduled_batches[] = array(
                            'timestamp' => $timestamp,
                            'subject' => $args['subject'] ?? '[no subject]',
                            'user_count' => count($args['user_ids'] ?? array()),
                            'args' => $args,
                            'key' => $key
                        );
                    }
                }
            }
        }

        if (!empty($scheduled_batches)) {
            echo '<div style="overflow-x: auto; max-width: 100%;">';
            echo '<table class="wp-list-table widefat fixed striped" style="min-width: 800px;">';
            echo '<thead><tr>';
            echo '<th>Scheduled Time</th><th>Subject</th><th>Recipients</th><th>Status</th><th>Action</th>';
            echo '</tr></thead><tbody>';

            foreach ($scheduled_batches as $batch) {
                $time_diff = $batch['timestamp'] - time();
                $time_display = date('Y-m-d H:i:s', $batch['timestamp']);
                $status = $time_diff > 0
                    ? '<span style="color:#6366f1;">Pending (' . human_time_diff(time(), $batch['timestamp']) . ')</span>'
                    : '<span style="color:#f0ad4e;">Waiting for cron...</span>';

                echo '<tr>';
                echo '<td>' . esc_html($time_display) . '</td>';
                echo '<td>' . esc_html(wp_trim_words($batch['subject'], 8, '...')) . '</td>';
                echo '<td>' . esc_html($batch['user_count']) . ' users</td>';
                echo '<td>' . $status . '</td>';
                echo '<td>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="cancel_timestamp" value="' . esc_attr($batch['timestamp']) . '">
                        <input type="hidden" name="cancel_args" value="' . esc_attr(json_encode($batch['args'])) . '">
                        ' . wp_nonce_field('ofast_cancel_scheduled', 'cancel_nonce', true, false) . '
                        <button type="submit" name="cancel_scheduled" class="button button-small" onclick="return confirm(\'Cancel this batch?\')">Cancel</button>
                    </form>
                </td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</div>';
        } else {
            echo '
            <div style="margin-top: 30px; padding: 40px; background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); border-radius: 16px; text-align: center; color: white; box-shadow: 0 10px 40px rgba(0, 115, 170, 0.3);">
                <div style="width: 80px; height: 80px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 36px;">📅</div>
                <h2 style="margin: 0 0 10px; font-size: 24px; font-weight: 600;">No Scheduled Batches</h2>
                <p style="margin: 0 0 25px; opacity: 0.9; font-size: 15px;">When you send emails to more than your batch limit, they\'ll appear here.</p>
                <div style="background: rgba(255,255,255,0.15); border-radius: 12px; padding: 20px; text-align: left; max-width: 500px; margin: 0 auto;">
                    <h4 style="margin: 0 0 12px; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8;">How It Works</h4>
                    <p style="margin: 0 0 10px; font-size: 14px; line-height: 1.6;"><strong>WordPress Cron</strong> runs when someone visits your site. On busy sites, this is very reliable.</p>
                    <p style="margin: 0; font-size: 14px; line-height: 1.6;"><strong>Low-traffic sites?</strong> Set up a real server cron job to hit <code style="background: rgba(0,0,0,0.2); padding: 2px 6px; border-radius: 4px;">wp-cron.php</code> every 5 minutes.</p>
                </div>
            </div>';
        }

        echo '</div>';
    }

    // ───────────────────────────────────────────────
    //  LEGACY STANDALONE PAGES (kept for backwards compat)
    // ───────────────────────────────────────────────

    /**
     * @deprecated Use render_tab_send() via render_main_page() instead
     */
    public function render_send_page($content_only = false)
    {
        require_once __DIR__ . '/tabs/class-email-tab-send.php';
        $tab = new Ofast_Email_Tab_Send($this);
        $tab->render_send_page($content_only);
    }

    /**
     * @deprecated Use render_tab_history() via render_main_page() instead
     */
    public function render_history_page()
    {
        require_once __DIR__ . '/tabs/class-email-tab-history.php';
        $tab = new Ofast_Email_Tab_History();
        $tab->render_standalone();
    }

    /**
     * @deprecated Use render_tab_templates() via render_main_page() instead
     */
    public function render_templates_page()
    {
        require_once __DIR__ . '/tabs/class-email-tab-templates.php';
        $tab = new Ofast_Email_Tab_Templates();
        $tab->render();
    }

    /**
     * @deprecated Use render_tab_drafts() via render_main_page() instead
     */
    public function render_drafts_page()
    {
        require_once __DIR__ . '/tabs/class-email-tab-drafts.php';
        $tab = new Ofast_Email_Tab_Drafts();
        $tab->render();
    }
}
