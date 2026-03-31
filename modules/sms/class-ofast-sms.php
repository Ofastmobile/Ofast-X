<?php
/**
 * Ofast X - SMS Channel Module
 * Multi-provider SMS sending with tabbed admin UI
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_SMS
{
    private static $instance = null;

    /**
     * Available SMS providers
     */
    private $providers = array(
        'twilio'          => array('label' => 'Twilio', 'class' => 'Ofast_X_SMS_Twilio', 'file' => 'class-ofast-sms-twilio.php', 'region' => 'International', 'color' => '#F22F46', 'icon' => 'T', 'logo' => 'twillo.png'),
        'africastalking'  => array('label' => "Africa's Talking", 'class' => 'Ofast_X_SMS_AfricasTalking', 'file' => 'class-ofast-sms-africastalking.php', 'region' => 'Africa', 'color' => '#F5A623', 'icon' => 'AT', 'logo' => 'africa-talking.png'),
        'termii'          => array('label' => 'Termii', 'class' => 'Ofast_X_SMS_Termii', 'file' => 'class-ofast-sms-termii.php', 'region' => 'Nigeria', 'color' => '#0078FF', 'icon' => 'Te', 'logo' => 'termii.png'),
        'smartsms'        => array('label' => 'SmartSMSSolutions', 'class' => 'Ofast_X_SMS_SmartSMS', 'file' => 'class-ofast-sms-smartsms.php', 'region' => 'Nigeria', 'color' => '#2ECC71', 'icon' => 'SS', 'logo' => 'smartsms_logo_square_512.jpg'),
    );

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize the SMS module
     */
    public function init()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'), 30);
        add_action('admin_init', array($this, 'handle_actions'));
        add_action('wp_ajax_ofast_sms_test', array($this, 'ajax_test_sms'));
        add_action('wp_ajax_ofast_sms_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_ofast_sms_send', array($this, 'ajax_send_sms'));

        // Auto-create log table
        add_action('admin_init', array($this, 'maybe_create_table'));
    }

    /**
     * Add admin menu under Ofast Toolkit
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'SMS Channel',
            'SMS Channel',
            'manage_options',
            'ofast-sms',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Create SMS log table if not exists
     */
    public function maybe_create_table()
    {
        $last_check = get_option('ofast_sms_table_checked', 0);
        if (time() - $last_check < DAY_IN_SECONDS) {
            return;
        }
        update_option('ofast_sms_table_checked', time());

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_sms_logs';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            recipient varchar(50) NOT NULL,
            message text NOT NULL,
            provider varchar(50) NOT NULL,
            status varchar(20) DEFAULT 'pending',
            response_message text,
            remote_id varchar(100) DEFAULT '',
            sent_by bigint(20) unsigned DEFAULT 0,
            sent_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_sent_at (sent_at)
        ) $charset;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Auto-prune logs older than 90 days to prevent DB bloat
        $retention_days = intval(get_option('ofast_sms_log_retention', 90));
        if ($retention_days > 0) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table WHERE sent_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $retention_days
            ));
        }
    }

    // =============================================
    // PROVIDER MANAGEMENT
    // =============================================

    public function get_active_provider()
    {
        return get_option('ofast_sms_active_provider', '');
    }

    public function get_provider_instance($provider_key = '')
    {
        if (empty($provider_key)) {
            $provider_key = $this->get_active_provider();
        }
        if (empty($provider_key) || !isset($this->providers[$provider_key])) {
            return null;
        }

        $provider = $this->providers[$provider_key];
        $file = OFAST_X_PLUGIN_DIR . 'modules/sms/' . $provider['file'];
        if (!file_exists($file)) return null;

        require_once $file;
        if (!class_exists($provider['class'])) return null;

        return new $provider['class']();
    }

    // =============================================
    // SEND SMS
    // =============================================

    public function send($to, $message)
    {
        $provider_key = $this->get_active_provider();
        $provider = $this->get_provider_instance();

        if (!$provider) {
            return array('success' => false, 'message' => 'No SMS provider configured.');
        }
        if (!$provider->is_configured()) {
            return array('success' => false, 'message' => 'SMS provider credentials incomplete.');
        }

        // Sanitize phone
        $to = preg_replace('/[^0-9+]/', '', $to);
        if (empty($to)) {
            return array('success' => false, 'message' => 'Invalid phone number.');
        }

        // Add country code if needed
        $country_code = get_option('ofast_sms_country_code', '');
        if (!empty($country_code) && strpos($to, '+') !== 0) {
            $to = $country_code . ltrim($to, '0');
        }

        $message = sanitize_textarea_field($message);
        if (empty($message)) {
            return array('success' => false, 'message' => 'Message body is empty.');
        }

        $result = $provider->send($to, $message);

        // Log the SMS
        $this->log_sms($to, $message, $provider_key, $result);

        return $result;
    }

    /**
     * Log SMS to database
     */
    private function log_sms($to, $message, $provider_key, $result)
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'ofast_sms_logs',
            array(
                'recipient'        => $to,
                'message'          => $message,
                'provider'         => $provider_key,
                'status'           => $result['success'] ? 'sent' : 'failed',
                'response_message' => $result['message'] ?? '',
                'remote_id'        => $result['sid'] ?? '',
                'sent_by'          => get_current_user_id(),
                'sent_at'          => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s')
        );
    }

    // =============================================
    // AJAX HANDLERS
    // =============================================

    public function ajax_send_sms()
    {
        check_ajax_referer('ofast_sms_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized.');
        }

        $recipients = sanitize_textarea_field($_POST['recipients'] ?? '');
        $message = sanitize_textarea_field($_POST['message'] ?? '');

        if (empty($recipients) || empty($message)) {
            wp_send_json_error('Recipients and message are required.');
        }

        // Strip HTML tags — SMS is plain text
        $message = wp_strip_all_tags($message);

        // Parse recipients (comma or newline separated)
        $numbers = preg_split('/[,\n\r]+/', $recipients);
        $numbers = array_filter(array_map('trim', $numbers));

        if (empty($numbers)) {
            wp_send_json_error('No valid recipients.');
        }

        $sent = 0;
        $failed = 0;
        $errors = array();

        foreach ($numbers as $number) {
            $result = $this->send($number, $message);
            if ($result['success']) {
                $sent++;
            } else {
                $failed++;
                $errors[] = $number . ': ' . $result['message'];
            }
        }

        $summary = $sent . ' sent';
        if ($failed > 0) {
            $summary .= ', ' . $failed . ' failed';
        }

        wp_send_json(array(
            'success' => $sent > 0,
            'message' => $summary,
            'errors'  => $errors,
        ));
    }

    public function ajax_test_sms()
    {
        check_ajax_referer('ofast_sms_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized.');
        }

        $phone = sanitize_text_field($_POST['phone'] ?? '');
        if (empty($phone)) {
            wp_send_json_error('Phone number required.');
        }

        $result = $this->send($phone, 'Test SMS from ' . get_bloginfo('name') . ' via Ofast-X. If you received this, your SMS channel is working!');
        wp_send_json($result);
    }

    public function ajax_test_connection()
    {
        check_ajax_referer('ofast_sms_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized.');
        }

        $provider_key = sanitize_key($_POST['provider'] ?? $this->get_active_provider());
        $instance = $this->get_provider_instance($provider_key);

        if (!$instance) {
            wp_send_json_error('Invalid provider.');
        }

        $result = $instance->test_connection();
        wp_send_json($result);
    }

    // =============================================
    // SETTINGS SAVE & ACTIONS
    // =============================================

    public function handle_actions()
    {
        // Save settings
        if (isset($_POST['ofast_sms_save_nonce']) && wp_verify_nonce($_POST['ofast_sms_save_nonce'], 'ofast_sms_save_settings')) {
            if (!current_user_can('manage_options')) return;

            $provider = sanitize_key($_POST['ofast_sms_provider'] ?? '');
            if (isset($this->providers[$provider])) {
                update_option('ofast_sms_active_provider', $provider);
            }

            $country_code = preg_replace('/[^0-9+]/', '', sanitize_text_field($_POST['ofast_sms_country_code'] ?? ''));
            update_option('ofast_sms_country_code', $country_code);

            $log_retention = absint($_POST['ofast_sms_log_retention'] ?? 90);
            update_option('ofast_sms_log_retention', $log_retention);

            // Save provider credentials (encrypted)
            foreach ($this->providers as $key => $info) {
                require_once OFAST_X_PLUGIN_DIR . 'modules/sms/' . $info['file'];
                $fields = call_user_func(array($info['class'], 'get_fields'));

                foreach ($fields as $field_key => $field_info) {
                    $post_key = 'ofast_sms_' . $key . '_' . $field_key;
                    if (isset($_POST[$post_key])) {
                        $value = sanitize_text_field($_POST[$post_key]);

                        if ($field_info['type'] === 'password' && $value === '••••••••') continue;

                        if (in_array($field_info['type'], array('password')) && !empty($value)) {
                            if (class_exists('Ofast_X_Security_Hardening')) {
                                $encrypted = Ofast_X_Security_Hardening::encrypt_option($value);
                                if ($encrypted !== false) $value = $encrypted;
                            }
                        }

                        update_option($post_key, $value);
                    } elseif ($field_info['type'] === 'checkbox') {
                        update_option($post_key, '');
                    }
                }
            }

            wp_redirect(admin_url('admin.php?page=ofast-sms&tab=settings&saved=1'));
            exit;
        }

        // Clear logs
        if (isset($_GET['action']) && $_GET['action'] === 'clear_logs' && isset($_GET['_wpnonce'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'ofast_sms_clear_logs') && current_user_can('manage_options')) {
                global $wpdb;
                $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}ofast_sms_logs");
                wp_redirect(admin_url('admin.php?page=ofast-sms&tab=logs&cleared=1'));
                exit;
            }
        }
    }

    // =============================================
    // ADMIN PAGE RENDER
    // =============================================

    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'send';
        $active_provider = $this->get_active_provider();
        $nonce = wp_create_nonce('ofast_sms_nonce');

        if (class_exists('Ofast_X_Dropdown')) {
            echo Ofast_X_Dropdown::render_assets();
        }
        ?>
        <style>
            :root {
                --ofast-primary: #4F46E5;
                --ofast-primary-hover: #4338CA;
                --ofast-surface: #ffffff;
                --ofast-bg: #f8fafc;
                --ofast-border: #e2e8f0;
                --ofast-text-main: #0f172a;
                --ofast-text-muted: #64748b;
                --ofast-radius-lg: 16px;
                --ofast-radius-md: 12px;
                --ofast-radius-sm: 8px;
                --ofast-shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
                --ofast-shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.05), 0 2px 4px -2px rgb(0 0 0 / 0.05);
                --ofast-shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.05), 0 4px 6px -4px rgb(0 0 0 / 0.05);
                --ofast-shadow-glow: 0 0 15px rgba(79, 70, 229, 0.15);
                --ofast-transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            }

            /* Global & Typography */
            .wrap.ofast-sms-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: var(--ofast-text-main); margin-top: 20px; max-width: 1400px; }
            
            /* Header with subtle gradient & glassmorphism hint */
            .ofast-header { display: flex; align-items: center; gap: 24px; background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%); padding: 32px 36px; border-radius: var(--ofast-radius-lg); box-shadow: var(--ofast-shadow-md); margin-bottom: 32px; border: 1px solid rgba(255,255,255,0.8); position: relative; overflow: hidden; }
            .ofast-header::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, var(--ofast-primary), #0ea5e9); }
            .ofast-header-icon { width: 64px; height: 64px; background: #ffffff; border: 1px solid #e2e8f0; box-shadow: 0 4px 10px rgba(79,70,229,0.05); border-radius: 20px; display: flex; align-items: center; justify-content: center; transform: rotate(-3deg); transition: var(--ofast-transition); }
            .ofast-header:hover .ofast-header-icon { transform: rotate(0deg) scale(1.05); }
            .ofast-header-icon .dashicons { font-size: 32px; width: 32px; height: 32px; color: var(--ofast-primary); }
            .ofast-header-content h1 { margin: 0 0 6px 0; font-size: 28px; font-weight: 800; color: #0f172a; letter-spacing: -0.5px; }
            .ofast-header-content p { margin: 0; color: var(--ofast-text-muted); font-size: 15px; font-weight: 500; }

            /* Modern Pill Tabs */
            .ofast-tabs-nav { display: inline-flex; flex-wrap: nowrap; gap: 4px; margin-bottom: 32px; padding: 6px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; position: sticky; top: 32px; z-index: 99; box-shadow: 0 4px 20px rgba(0,0,0,0.02); }
            .ofast-tab { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; background: transparent; border: none; border-radius: 10px; color: #475569; font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer; transition: var(--ofast-transition); position: relative; }
            .ofast-tab:hover { color: var(--ofast-primary); }
            .ofast-tab.active { background: #ffffff; color: var(--ofast-primary); box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
            .ofast-tab .dashicons { font-size: 18px; width: 18px; height: 18px; line-height: 18px; }

            /* Tab Content Animations */
            .ofast-tab-content { display: none; opacity: 0; transform: translateY(10px); transition: opacity 0.4s ease, transform 0.4s ease; }
            .ofast-tab-content.active { display: block; opacity: 1; transform: translateY(0); animation: slideUpFade 0.4s ease forwards; }
            @keyframes slideUpFade { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }

            /* Premium Cards */
            .ofast-card { background: var(--ofast-surface); border-radius: var(--ofast-radius-lg); padding: 32px; box-shadow: var(--ofast-shadow-lg); border: 1px solid rgba(226,232,240,0.8); margin-bottom: 24px; transition: var(--ofast-transition); }
            .ofast-card:hover { box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.05), 0 8px 10px -6px rgb(0 0 0 / 0.01); border-color: #cbd5e1; }
            .ofast-card h2 { margin: 0 0 24px; font-size: 19px; color: var(--ofast-text-main); font-weight: 700; letter-spacing: -0.3px; display: flex; align-items: center; gap: 8px; }
            .ofast-card h3 { margin: 0 0 16px; font-size: 15px; color: var(--ofast-text-main); font-weight: 600; padding-bottom: 12px; border-bottom: 2px solid #f1f5f9; }

            /* Layouts */
            .ofast-sms-send-layout { display: grid; grid-template-columns: 1fr 340px; gap: 32px; }
            .ofast-settings-layout { display: grid; grid-template-columns: 360px 1fr; gap: 32px; }
            @media screen and (max-width: 1024px) { .ofast-sms-send-layout, .ofast-settings-layout { grid-template-columns: 1fr; } }

            /* Precision Form Fields */
            .ofast-field { margin-bottom: 20px; position: relative; }
            .ofast-field label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 8px; }
            .ofast-field input[type="text"],
            .ofast-field input[type="password"],
            .ofast-field textarea { width: 100%; padding: 12px 16px; background: #f8fafc; border: 2px solid transparent; border-radius: var(--ofast-radius-md); font-size: 14px; color: #0f172a; transition: var(--ofast-transition); }
            .ofast-field input:hover, .ofast-field textarea:hover { background: #f1f5f9; }
            .ofast-field input:focus,
            .ofast-field textarea:focus { outline: none; background: #ffffff; border-color: var(--ofast-primary); box-shadow: 0 0 0 4px rgba(79,70,229,0.1); }
            .ofast-field .field-hint { font-size: 12px; color: #64748b; margin-top: 6px; display: flex; align-items: center; gap: 4px; }

            /* Interactive Provider Cards */
            .ofast-provider-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
            .ofast-provider-card { background: #ffffff; border: 2px solid #e2e8f0; border-radius: var(--ofast-radius-md); padding: 20px 16px; cursor: pointer; transition: var(--ofast-transition); text-align: center; position: relative; overflow: hidden; }
            .ofast-provider-card::before { content: ""; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(135deg, rgba(79,70,229,0.03) 0%, transparent 100%); opacity: 0; transition: opacity 0.3s; }
            .ofast-provider-card:hover { border-color: #cbd5e1; transform: translateY(-2px); box-shadow: var(--ofast-shadow-md); }
            .ofast-provider-card:hover::before { opacity: 1; }
            .ofast-provider-card.active { border-color: var(--ofast-primary); background: #f5f8ff; box-shadow: var(--ofast-shadow-glow); transform: translateY(-2px); }
            .ofast-provider-card input[type="radio"] { display: none; }
            .ofast-provider-logo { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; font-weight: 800; font-size: 16px; color: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.1); position: relative; z-index: 1; transition: var(--ofast-transition); }
            .ofast-provider-card:hover .ofast-provider-logo { transform: scale(1.1); }
            .ofast-provider-card .provider-name { font-weight: 700; font-size: 15px; color: #1e293b; margin-bottom: 4px; position: relative; z-index: 1; }
            .ofast-provider-card .provider-region { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 600; position: relative; z-index: 1; }
            .ofast-provider-card .check-icon { position: absolute; top: 12px; right: 12px; width: 22px; height: 22px; background: var(--ofast-primary); color: #fff; border-radius: 50%; display: none; align-items: center; justify-content: center; font-size: 12px; font-weight: bold; z-index: 2; box-shadow: 0 2px 6px rgba(79,70,229,0.4); animation: scaleIn 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
            .ofast-provider-card.active .check-icon { display: flex; }
            @keyframes scaleIn { from { transform: scale(0); } to { transform: scale(1); } }

            /* Credential Fields Anim */
            .ofast-provider-fields { display: none; opacity: 0; transform: translateX(10px); transition: all 0.3s ease; border: 1px solid #cbd5e1; border-radius: 12px; padding: 24px; background: #ffffff; margin-top: 24px; }
            .ofast-provider-fields.active { display: block; opacity: 1; transform: translateX(0); }

            /* Enhanced Buttons */
            .ofast-btn { padding: 12px 24px; border-radius: var(--ofast-radius-sm); font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: var(--ofast-transition); display: inline-flex; align-items: center; justify-content: center; gap: 8px; background-size: 200% auto; text-decoration: none; }
            .ofast-btn-primary { background-image: linear-gradient(to right, var(--ofast-primary) 0%, #6366f1 51%, var(--ofast-primary) 100%); color: #fff; box-shadow: 0 4px 10px rgba(79,70,229,0.3); }
            .ofast-btn-primary:hover:not(:disabled) { background-position: right center; box-shadow: 0 6px 15px rgba(79,70,229,0.4); transform: translateY(-1px); color: #fff; }
            .ofast-btn-primary:active:not(:disabled) { transform: translateY(1px); box-shadow: 0 2px 5px rgba(79,70,229,0.3); }
            .ofast-btn-primary:disabled { background: #cbd5e1; box-shadow: none; cursor: not-allowed; opacity: 0.7; }
            .ofast-btn-secondary { background: #ffffff; border: 2px solid #e2e8f0; color: #334155; }
            .ofast-btn-secondary:hover { border-color: #cbd5e1; background: #f8fafc; color: #0f172a; }
            .ofast-btn-danger { background: #ffffff; border: 2px solid #fecaca; color: #dc2626; }
            .ofast-btn-danger:hover { background: #fef2f2; border-color: #f87171; }

            /* Send SMS UI Specific */
            .ofast-composer-meta { background: #f8fafc; border-radius: var(--ofast-radius-md); padding: 20px; margin-bottom: 24px; display: flex; gap: 24px; border: 1px solid #f1f5f9; }
            .ofast-composer-meta .meta-block { flex: 1; }
            .ofast-composer-meta .meta-label { font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; font-weight: 700; margin-bottom: 4px; }
            .ofast-composer-meta .meta-value { font-size: 15px; font-weight: 600; color: #0f172a; }

            /* Status badges */
            .ofast-status { display: inline-flex; align-items: center; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; letter-spacing: 0.3px; }
            .ofast-status.sent { background: #dcfce7; color: #166534; box-shadow: inset 0 0 0 1px rgba(22,101,52,0.1); }
            .ofast-status.failed { background: #fee2e2; color: #991b1b; box-shadow: inset 0 0 0 1px rgba(153,27,27,0.1); }
            .ofast-status.pending { background: #fef3c7; color: #92400e; box-shadow: inset 0 0 0 1px rgba(146,64,14,0.1); }

            /* Logs Data Table */
            .ofast-logs-wrapper { border-radius: var(--ofast-radius-md); overflow: hidden; border: 1px solid #e2e8f0; }
            .ofast-logs-table { width: 100%; border-collapse: separate; border-spacing: 0; background: #fff; }
            .ofast-logs-table th { text-align: left; padding: 16px 20px; background: #f8fafc; color: #475569; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
            .ofast-logs-table td { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; font-size: 14px; color: #334155; vertical-align: middle; }
            .ofast-logs-table tr:last-child td { border-bottom: none; }
            .ofast-logs-table tr:hover td { background: #f8fafc; }
            .ofast-logs-empty { text-align: center; padding: 60px 40px; color: #64748b; font-size: 15px; background: #fafafa; }

            /* Clean Toasts */
            .ofast-sms-result { padding: 12px 16px; font-size: 13px; font-weight: 500; border-radius: var(--ofast-radius-sm); margin-top: 16px; display: none; animation: slideUpFade 0.3s ease; }
            .ofast-sms-result.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
            .ofast-sms-result.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
            .ofast-sms-result.visible { display: block; }

            /* Actions row */
            .ofast-actions-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 20px; }
            .ofast-actions-row-between { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        </style>

        <div class="wrap">
            <!-- Header -->
            <div class="ofast-header">
                <div class="ofast-header-icon"><span class="dashicons dashicons-smartphone"></span></div>
                <div class="ofast-header-content">
                    <h1>SMS Channel</h1>
                    <p>Send SMS messages to your users via <?php echo !empty($active_provider) ? esc_html($this->providers[$active_provider]['label'] ?? 'your provider') : 'a configured provider'; ?></p>
                </div>
            </div>

            <!-- Tabs -->
            <div class="ofast-tabs-nav">
                <a href="#send" class="ofast-tab <?php echo $active_tab === 'send' ? 'active' : ''; ?>" data-tab="send">
                    <span class="dashicons dashicons-email-alt"></span> Send Message
                </a>
                <a href="#logs" class="ofast-tab <?php echo $active_tab === 'logs' ? 'active' : ''; ?>" data-tab="logs">
                    <span class="dashicons dashicons-list-view"></span> Logs
                </a>
                <a href="#settings" class="ofast-tab <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" data-tab="settings">
                    <span class="dashicons dashicons-admin-generic"></span> Settings
                </a>
            </div>

            <!-- Send Message Tab -->
            <div class="ofast-tab-content <?php echo $active_tab === 'send' ? 'active' : ''; ?>" id="tab-send">
                <?php $this->render_send_tab($active_provider, $nonce); ?>
            </div>

            <!-- Logs Tab -->
            <div class="ofast-tab-content <?php echo $active_tab === 'logs' ? 'active' : ''; ?>" id="tab-logs">
                <?php $this->render_logs_tab(); ?>
            </div>

            <!-- Settings Tab -->
            <div class="ofast-tab-content <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" id="tab-settings">
                <?php $this->render_settings_tab($active_provider, $nonce); ?>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Tab switching (no reload)
            function switchTab(name) {
                $('.ofast-tab').removeClass('active');
                $('.ofast-tab[data-tab="' + name + '"]').addClass('active');
                $('.ofast-tab-content').removeClass('active');
                $('#tab-' + name).addClass('active');
                history.replaceState(null, null, '#' + name);
            }
            $('.ofast-tab[data-tab]').on('click', function(e) {
                e.preventDefault();
                switchTab($(this).data('tab'));
            });
            // Handle hash on load
            var hash = window.location.hash.replace('#', '');
            if (hash && $('#tab-' + hash).length) { switchTab(hash); }

            // Provider card selection
            $('.ofast-provider-card').on('click', function() {
                var provider = $(this).data('provider');
                $('.ofast-provider-card').removeClass('active');
                $(this).addClass('active');
                $(this).find('input[type="radio"]').prop('checked', true);
                
                $('#ofast-provider-placeholder').hide();
                $('.ofast-provider-fields').removeClass('active');
                $('#fields-' + provider).addClass('active');
                // Re-initialize Ofast dropdowns in newly visible fields
                if (typeof window.OfastInitDropdowns === 'function') {
                    window.OfastInitDropdowns('#fields-' + provider);
                }
            });

            // Send SMS
            $('#ofast-sms-send-btn').on('click', function() {
                var btn = $(this);
                var recipients = $('#ofast-sms-recipients').val();
                // Get content from WP editor
                var message = '';
                if (typeof tinyMCE !== 'undefined' && tinyMCE.get('ofast_sms_message')) {
                    message = tinyMCE.get('ofast_sms_message').getContent({format: 'text'});
                } else {
                    message = $('#ofast_sms_message').val();
                }

                if (!recipients || !message) {
                    ofastToast.error('Please enter recipients and a message.');
                    return;
                }

                btn.prop('disabled', true).text('Sending...');

                $.post(ajaxurl, {
                    action: 'ofast_sms_send',
                    nonce: '<?php echo $nonce; ?>',
                    recipients: recipients,
                    message: message
                }, function(response) {
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-email-alt"></span> Send SMS');
                    if (response.success) {
                        ofastToast.success(response.message || 'SMS sent successfully!');
                    } else {
                        var msg = response.message || response.data || 'Failed to send.';
                        if (response.errors && response.errors.length) {
                            msg += ' ' + response.errors.join(', ');
                        }
                        ofastToast.error(msg);
                    }
                }).fail(function() {
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-email-alt"></span> Send SMS');
                    ofastToast.error('Request failed. Please try again.');
                });
            });

            // Test connection
            $('#ofast-sms-test-conn').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Testing...');

                $.post(ajaxurl, {
                    action: 'ofast_sms_test_connection',
                    nonce: '<?php echo $nonce; ?>',
                    provider: $('input[name="ofast_sms_provider"]:checked').val() || ''
                }, function(response) {
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top:2px;"></span> Test Connection');
                    if (response.success) {
                        ofastToast.success(response.message || response.data || 'Connection successful!');
                    } else {
                        ofastToast.error(response.message || response.data || 'Connection failed.');
                    }
                }).fail(function() {
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top:2px;"></span> Test Connection');
                    ofastToast.error('Request failed. Please try again.');
                });
            });

            // Test SMS
            $('#ofast-sms-test-send').on('click', function() {
                var btn = $(this);
                var phone = $('#ofast-test-phone').val();

                if (!phone) {
                    ofastToast.warning('Please enter a phone number.');
                    return;
                }

                btn.prop('disabled', true).text('Sending...');

                $.post(ajaxurl, {
                    action: 'ofast_sms_test',
                    nonce: '<?php echo $nonce; ?>',
                    phone: phone
                }, function(response) {
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-email-alt" style="margin-top:2px;"></span> Send Test');
                    if (response.success) {
                        ofastToast.success(response.message || response.data || 'Test SMS sent!');
                    } else {
                        ofastToast.error(response.message || response.data || 'Failed to send test SMS.');
                    }
                }).fail(function() {
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-email-alt" style="margin-top:2px;"></span> Send Test');
                    ofastToast.error('Request failed. Please try again.');
                });
            });
        });
        </script>
        <?php
    }

    // =============================================
    // TAB: SEND MESSAGE
    // =============================================

    private function render_send_tab($active_provider, $nonce)
    {
        $is_configured = !empty($active_provider);
        ?>
        <div class="ofast-sms-send-layout">
            <!-- Main Column -->
            <div>
                <div class="ofast-card" style="padding: 32px 40px;">
                    <h2>
                        <span class="dashicons dashicons-edit" style="color:var(--ofast-primary); font-size:24px; width:24px; height:24px; margin-right:4px;"></span> 
                        Compose SMS
                    </h2>
                    
                    <?php if ($is_configured): ?>
                    <div class="ofast-composer-meta">
                        <div class="meta-block">
                            <div class="meta-label">Sending Via</div>
                            <div class="meta-value" style="display:flex; align-items:center; gap:8px;">
                                <span style="display:inline-block; width:8px; height:8px; background:#10b981; border-radius:50%; box-shadow: 0 0 8px rgba(16, 185, 129, 0.4);"></span>
                                <?php echo esc_html($this->providers[$active_provider]['label'] ?? 'Unknown'); ?>
                            </div>
                        </div>
                        <div class="meta-block" style="border-left: 1px solid #e2e8f0; padding-left: 24px;">
                            <div class="meta-label">Sender ID</div>
                            <div class="meta-value"><?php echo esc_html(get_option('ofast_sms_sender_id', 'OFAST')); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="ofast-field">
                        <label for="ofast-sms-recipients">Recipients <span style="color:#ef4444;">*</span></label>
                        <textarea id="ofast-sms-recipients" rows="2" placeholder="Enter phone numbers separated by commas (+2348012345678, +447911123456)"></textarea>
                        <div class="field-hint">
                            <span class="dashicons dashicons-info-outline" style="font-size:14px; width:14px; height:14px; margin-top:1px;"></span>
                            Use international format. Default country code (+<?php echo esc_html(ltrim(get_option('ofast_sms_country_code', '+234'), '+')); ?>) is auto-applied to local numbers.
                        </div>
                    </div>
                    
                    <div class="ofast-field" style="margin-top:28px;">
                        <label>Message Content <span style="color:#ef4444;">*</span></label>
                        <div style="border-radius:var(--ofast-radius-md); overflow:hidden; border:2px solid transparent; background:#f8fafc; transition:var(--ofast-transition);" onfocusin="this.style.borderColor='var(--ofast-primary)'; this.style.background='#fff'; this.style.boxShadow='0 0 0 4px rgba(79,70,229,0.1)';" onfocusout="this.style.borderColor='transparent'; this.style.background='#f8fafc'; this.style.boxShadow='none';">
                            <?php
                            wp_editor('', 'ofast_sms_message', array(
                                'textarea_rows' => 6,
                                'media_buttons' => false,
                                'quicktags'     => false,
                                'teeny'         => true,
                                'tinymce'       => array(
                                    'toolbar1' => 'bold,italic,underline,|,undo,redo',
                                    'toolbar2' => '',
                                    'statusbar' => false,
                                ),
                            ));
                            ?>
                        </div>
                        <div class="field-hint" style="margin-top:10px;">
                            <span class="dashicons dashicons-format-aside" style="font-size:14px; width:14px; height:14px; margin-top:1px;"></span>
                            SMS goes out as plain text. Rich formatting is stripped from the final delivery.
                        </div>
                    </div>
                    
                    <div class="ofast-actions-row" style="margin-top:36px; padding-top:24px; border-top:1px solid #f1f5f9;">
                        <button type="button" class="ofast-btn ofast-btn-primary" id="ofast-sms-send-btn" <?php echo !$is_configured ? 'disabled title="Configure a provider in Settings first"' : ''; ?> style="padding:14px 32px; font-size:15px; box-shadow: 0 10px 20px -5px rgba(79,70,229,0.4);">
                            <span class="dashicons dashicons-paperplane" style="margin-top:2px;"></span> Send Campaign
                        </button>
                        <?php if (!$is_configured): ?>
                            <span style="font-size:13px; color:#ef4444; font-weight:600; display:flex; align-items:center; gap:6px;">
                                <span class="dashicons dashicons-warning" style="font-size:16px; width:16px; height:16px;"></span> Provider configuration required
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="ofast-sms-result" id="ofast-send-result" style="margin-top:20px;"></div>
                </div>
            </div>

            <!-- Sidebar -->
            <div>
                <div class="ofast-card" style="position: sticky; top: 100px;">
                    <h2>
                        <span class="dashicons dashicons-admin-network" style="color:var(--ofast-text-muted); font-size:20px; width:20px; height:20px;"></span> 
                        Connection Node
                    </h2>
                    
                    <?php if ($is_configured): ?>
                        <div style="background:#f8fafc; border-radius:12px; padding:20px; margin-bottom:24px; border:1px solid #e2e8f0; position:relative; overflow:hidden;">
                            <div style="position:absolute; top:-20px; right:-20px; width:80px; height:80px; background:var(--ofast-primary); opacity:0.03; border-radius:50%;"></div>
                            <div style="display:flex; align-items:center; gap:16px; margin-bottom:16px; position:relative; z-index:1;">
                                <div class="ofast-provider-logo" style="margin:0; width:44px; height:44px; font-size:16px; background:var(--ofast-primary);">
                                    <?php echo esc_html(substr($this->providers[$active_provider]['label'] ?? 'P', 0, 1)); ?>
                                </div>
                                <div>
                                    <div style="font-weight:700; color:#0f172a; font-size:15px; margin-bottom:2px;"><?php echo esc_html($this->providers[$active_provider]['label'] ?? ''); ?></div>
                                    <div style="font-size:12px; color:#64748b; font-weight:600; letter-spacing:0.5px; text-transform:uppercase;"><?php echo esc_html($this->providers[$active_provider]['region'] ?? ''); ?></div>
                                </div>
                            </div>
                            <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px dashed #cbd5e1; padding-top:16px;">
                                <span style="font-size:12px; color:#64748b; font-weight:700; letter-spacing:0.5px;">STATUS</span>
                                <span class="ofast-status sent" style="padding:6px 14px;">Operational</span>
                            </div>
                        </div>

                        <h3 style="font-size:13px; color:#475569; text-transform:uppercase; letter-spacing:0.8px; border:none; margin-bottom:12px; font-weight:700;">Diagnostic Test</h3>
                        <div class="ofast-field" style="margin-bottom:16px;">
                            <input type="text" id="ofast-test-phone" placeholder="Test number (e.g. +234...)" style="background:#fff;">
                        </div>
                        <button type="button" class="ofast-btn ofast-btn-secondary" id="ofast-sms-test-send" style="width:100%; justify-content:center; padding:12px;">
                            <span class="dashicons dashicons-testimonial" style="font-size:16px; width:16px; height:16px;"></span> Send Ping
                        </button>
                        <div class="ofast-sms-result" id="ofast-test-result"></div>
                    <?php else: ?>
                        <div style="text-align:center; padding:20px 0;">
                            <div style="width:56px; height:56px; background:#f1f5f9; border-radius:16px; display:inline-flex; align-items:center; justify-content:center; margin:0 auto 20px;">
                                <span class="dashicons dashicons-warning" style="color:#94a3b8; font-size:28px; width:28px; height:28px;"></span>
                            </div>
                            <h3 style="border:none; margin:0 0 8px; font-size:16px; font-weight:700; color:#0f172a;">No Gateway</h3>
                            <p style="font-size:14px; color:#64748b; margin:0 0 24px; line-height:1.6;">Configure an SMS provider in settings before sending campaigns.</p>
                            <a href="#settings" class="ofast-btn ofast-btn-primary" data-tab="settings" style="width:100%; justify-content:center; box-shadow:none; padding:12px;">
                                Go to Settings <span class="dashicons dashicons-arrow-right-alt" style="font-size:16px; width:16px; height:16px;"></span>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    // =============================================
    // TAB: SETTINGS
    // =============================================

    private function render_settings_tab($active_provider, $nonce)
    {
        $saved = isset($_GET['saved']) && $_GET['saved'] === '1';
        $country_code = get_option('ofast_sms_country_code', '+234');
        ?>
        <?php if ($saved): ?>
            <?php echo Ofast_X_Toast::render('Settings saved successfully.', 'success'); ?>
        <?php endif; ?>

        <form method="POST">
            <?php wp_nonce_field('ofast_sms_save_settings', 'ofast_sms_save_nonce'); ?>

            <div class="ofast-settings-layout">
                <!-- Left: Provider Selection -->
                <div>
                    <div class="ofast-card" style="padding: 32px; position: sticky; top: 100px;">
                        <h2>
                            <span class="dashicons dashicons-networking" style="color:var(--ofast-primary); font-size:24px; width:24px; height:24px; margin-right:4px;"></span> 
                            SMS Gateway
                        </h2>
                        <p style="font-size:13px; color:#64748b; margin:-12px 0 24px;">Select an active provider to route all your outbound SMS traffic.</p>
                        
                        <div class="ofast-provider-grid">
                            <?php foreach ($this->providers as $key => $info): ?>
                                <label class="ofast-provider-card <?php echo $active_provider === $key ? 'active' : ''; ?>" data-provider="<?php echo esc_attr($key); ?>">
                                    <input type="radio" name="ofast_sms_provider" value="<?php echo esc_attr($key); ?>" <?php checked($active_provider, $key); ?>>
                                    <div class="check-icon"><span class="dashicons dashicons-yes" style="font-size:14px; width:14px; height:14px; margin-top:3px; margin-left:-2px;"></span></div>
                                    <div class="ofast-provider-logo" style="background:#ffffff; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
                                        <img src="<?php echo esc_url(OFAST_X_PLUGIN_URL . 'assets/images/' . $info['logo']); ?>" alt="<?php echo esc_attr($info['label']); ?>" style="max-width: 24px; max-height: 24px; object-fit: contain;">
                                    </div>
                                    <div class="provider-name"><?php echo esc_html($info['label']); ?></div>
                                    <div class="provider-region"><?php echo esc_html($info['region']); ?></div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Right: Provider Credentials & General Settings -->
                <div>
                    <div class="ofast-card" style="padding: 32px 40px; margin-bottom: 32px;">
                        <h2>
                            <span class="dashicons dashicons-shield" style="color:var(--ofast-primary); font-size:24px; width:24px; height:24px; margin-right:4px;"></span> 
                            Authentication
                        </h2>
                        <div id="ofast-provider-placeholder" style="padding:24px; text-align:center; background:#f8fafc; border-radius:12px; border:1px dashed #cbd5e1; margin-top: 24px; <?php echo !empty($active_provider) ? 'display:none;' : ''; ?>">
                            <span class="dashicons dashicons-lock" style="font-size:32px; width:32px; height:32px; color:#94a3b8; margin-bottom:12px;"></span>
                            <p style="color:#64748b; font-size:14px; margin:0;">Select a provider from the left panel to configure its API credentials.</p>
                        </div>

                        <?php foreach ($this->providers as $key => $info):
                            if (!class_exists($info['class'])) {
                                require_once OFAST_X_PLUGIN_DIR . 'modules/sms/' . $info['file'];
                            }
                            $fields = call_user_func(array($info['class'], 'get_fields'));
                        ?>
                            <div class="ofast-provider-fields <?php echo $active_provider === $key ? 'active' : ''; ?>" id="fields-<?php echo esc_attr($key); ?>">
                                <div style="display:flex; align-items:center; gap:12px; margin-bottom:24px; padding-bottom:16px; border-bottom:1px solid #f1f5f9;">
                                    <div style="width:32px; height:32px; border-radius:8px; background:#ffffff; border: 1px solid #e2e8f0; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
                                        <img src="<?php echo esc_url(OFAST_X_PLUGIN_URL . 'assets/images/' . $info['logo']); ?>" alt="<?php echo esc_attr($info['label']); ?>" style="max-width: 18px; max-height: 18px; object-fit: contain;">
                                    </div>
                                    <div>
                                        <h3 style="margin:0; padding:0; border:none; color:#0f172a; font-size:16px;"><?php echo esc_html($info['label']); ?> API Keys</h3>
                                        <div style="font-size:12px; color:#64748b;">Keys are securely encrypted in the database.</div>
                                    </div>
                                </div>
                                
                                <?php foreach ($fields as $field_key => $field_info):
                                    $option_key = 'ofast_sms_' . $key . '_' . $field_key;
                                    $stored_value = get_option($option_key, '');
                                    $display_value = ($field_info['type'] === 'password' && !empty($stored_value)) ? '••••••••' : $stored_value;
                                ?>
                                    <div class="ofast-field">
                                        <label for="<?php echo esc_attr($option_key); ?>"><?php echo esc_html($field_info['label']); ?></label>
                                        <?php if ($field_info['type'] === 'select'): ?>
                                            <select name="<?php echo esc_attr($option_key); ?>" id="<?php echo esc_attr($option_key); ?>" class="ofast-dropdown-native" style="width:100%; padding:12px 16px; background:#f8fafc; border:2px solid transparent; border-radius:12px; font-size:14px;">
                                                <?php foreach ($field_info['options'] as $opt_val => $opt_label): ?>
                                                    <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($stored_value, $opt_val); ?>><?php echo esc_html($opt_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php elseif ($field_info['type'] === 'checkbox'): ?>
                                            <label style="font-weight:normal; font-size:14px; display:flex; align-items:center; gap:8px; cursor:pointer;">
                                                <input type="checkbox" name="<?php echo esc_attr($option_key); ?>" value="1" <?php checked($stored_value, '1'); ?> style="width:18px; height:18px;">
                                                <?php echo esc_html($field_info['description'] ?? ''); ?>
                                            </label>
                                        <?php else: ?>
                                            <div style="position:relative;">
                                                <input type="<?php echo esc_attr($field_info['type']); ?>"
                                                       name="<?php echo esc_attr($option_key); ?>"
                                                       id="<?php echo esc_attr($option_key); ?>"
                                                       value="<?php echo esc_attr($display_value); ?>"
                                                       placeholder="<?php echo esc_attr($field_info['placeholder'] ?? ''); ?>"
                                                       autocomplete="off">
                                                <?php if ($field_info['type'] === 'password' && !empty($stored_value)): ?>
                                                    <span class="dashicons dashicons-lock" style="position:absolute; right:16px; top:14px; color:#10b981; font-size:16px; width:16px; height:16px;" title="Encrypted & Saved"></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="field-hint" style="margin-top:6px;">
                                            <?php echo esc_html($field_info['description'] ?? ''); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- General Settings -->
                    <div class="ofast-card" style="padding: 32px 40px;">
                        <h2>
                            <span class="dashicons dashicons-admin-generic" style="color:var(--ofast-text-muted); font-size:24px; width:24px; height:24px; margin-right:4px;"></span> 
                            Global Configuration
                        </h2>
                        
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-top:24px;">
                            <div class="ofast-field">
                                <label for="ofast_sms_country_code">Default Country Prefix</label>
                                <div style="position:relative; width:100%;">
                                    <span style="position:absolute; left:16px; top:13px; color:#64748b; font-weight:600;">+</span>
                                    <input type="text" name="ofast_sms_country_code" id="ofast_sms_country_code"
                                           value="<?php echo esc_attr(ltrim($country_code, '+')); ?>"
                                           placeholder="234" style="padding-left:32px; width:100%; box-sizing:border-box;">
                                </div>
                                <div class="field-hint" style="margin-top:8px;">Auto-prepended to local numbers.</div>
                            </div>
                            
                            <div class="ofast-field">
                                <label for="ofast_sms_log_retention">Log Retention (Days)</label>
                                <div style="position:relative; width:100%;">
                                    <input type="number" name="ofast_sms_log_retention" id="ofast_sms_log_retention"
                                           value="<?php echo esc_attr(get_option('ofast_sms_log_retention', '90')); ?>"
                                           min="0" placeholder="90" style="width:100%; box-sizing:border-box;">
                                </div>
                                <div class="field-hint" style="margin-top:8px;">Set to 0 to keep history forever.</div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="ofast-actions-row" style="margin-top:32px; padding-top:24px; border-top:1px solid #f1f5f9;">
                            <button type="submit" class="ofast-btn ofast-btn-primary" style="padding:14px 32px; font-size:15px; box-shadow: 0 10px 20px -5px rgba(79,70,229,0.4);">
                                <span class="dashicons dashicons-saved" style="margin-top:2px;"></span> Save Configurations
                            </button>
                        </div>
                        <div class="ofast-sms-result" id="ofast-conn-result" style="margin-top:16px;"></div>

                        <!-- Test SMS Section -->
                        <div style="margin-top:32px; padding-top:24px; border-top:1px solid #f1f5f9;">
                            <h3 style="margin:0 0 6px 0; font-size:15px; color:#0f172a; font-weight:700; display:flex; align-items:center; gap:8px; border:none; padding:0;">
                                <span class="dashicons dashicons-phone" style="color:var(--ofast-primary); font-size:18px; width:18px; height:18px;"></span>
                                Send Test SMS
                            </h3>
                            <p style="margin:0 0 16px 0; font-size:13px; color:#64748b;">Verify your configuration by sending a real test message to any phone number.</p>
                            <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
                                <div class="ofast-field" style="margin-bottom:0; flex:1; min-width:200px;">
                                    <label for="ofast-test-phone">Recipient Phone Number</label>
                                    <input type="text" id="ofast-test-phone" placeholder="+2348012345678" style="width:100%;">
                                </div>
                                <button type="button" class="ofast-btn ofast-btn-primary" id="ofast-sms-test-send" style="padding:12px 24px; font-size:14px; white-space:nowrap; height:fit-content;">
                                    <span class="dashicons dashicons-email-alt" style="margin-top:2px;"></span> Send Test
                                </button>
                            </div>
                            <div class="ofast-sms-result" id="ofast-test-result" style="margin-top:12px;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        <?php
    }

    // =============================================
    // TAB: LOGS
    // =============================================

    private function render_logs_tab()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_sms_logs';
        $cleared = isset($_GET['cleared']) && $_GET['cleared'] === '1';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;

        $page = max(1, intval($_GET['log_page'] ?? 1));
        $per_page = 20;
        $offset = ($page - 1) * $per_page;

        $total = 0;
        $logs = array();

        if ($table_exists) {
            $total = intval($wpdb->get_var("SELECT COUNT(*) FROM $table"));
            $logs = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table ORDER BY sent_at DESC LIMIT %d OFFSET %d",
                $per_page, $offset
            ));
        }

        $total_pages = ceil($total / $per_page);
        ?>
        <?php if ($cleared): ?>
            <?php echo Ofast_X_Toast::render('Message history cleared successfully.', 'success'); ?>
        <?php endif; ?>

        <div class="ofast-card" style="padding: 32px 40px;">
            <div class="ofast-actions-row-between" style="margin-bottom:24px; border-bottom:1px solid #f1f5f9; padding-bottom:20px;">
                <div>
                    <h2 style="margin:0 0 4px 0; display:flex; align-items:center; gap:8px;">
                        <span class="dashicons dashicons-book" style="color:var(--ofast-primary); font-size:24px; width:24px; height:24px;"></span>
                        Transmission Logs
                    </h2>
                    <p style="margin:0; font-size:13px; color:#64748b;">
                        Tracking <strong><?php echo number_format($total); ?></strong> outbound messages across all providers.
                    </p>
                </div>
                <div style="display:flex; gap:12px; align-items:center;">
                    <button type="button" onclick="window.location.reload();" class="ofast-btn ofast-btn-secondary" style="padding:10px 20px; text-decoration:none;">
                        <span class="dashicons dashicons-update-alt" style="margin-right:4px;"></span> Refresh Logs
                    </button>
                    <?php if ($total > 0): ?>
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ofast-sms&tab=logs&action=clear_logs'), 'ofast_sms_clear_logs'); ?>"
                           class="ofast-btn" style="background:#fef2f2; color:#ef4444; border:1px solid #fecaca; padding:10px 20px; text-decoration:none;"
                           onclick="return confirm('WARNING: This will permanently delete all SMS logs. Are you completely sure?');">
                            <span class="dashicons dashicons-trash" style="margin-right:4px;"></span> Clear History
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($logs)): ?>
                <div class="ofast-logs-empty" style="padding:60px 20px;">
                    <div style="width:80px; height:80px; background:#f8fafc; border-radius:24px; display:inline-flex; align-items:center; justify-content:center; margin:0 auto 24px; box-shadow:inset 0 2px 4px rgba(0,0,0,0.02), 0 10px 15px -3px rgba(0,0,0,0.05);">
                        <span class="dashicons dashicons-smartphone" style="font-size:40px; width:40px; height:40px; color:#cbd5e1;"></span>
                    </div>
                    <h3 style="margin:0 0 8px 0; font-size:18px; color:#1e293b;">No Messages Sent</h3>
                    <p style="margin:0; color:#64748b; font-size:14px; max-width:400px; margin:0 auto;">Your transmission log is currently empty. Sent messages will automatically appear here for tracking and auditing purposes.</p>
                </div>
            <?php else: ?>
                <div style="border-radius:12px; border:1px solid #e2e8f0; overflow:hidden;">
                    <div style="overflow-x:auto;">
                        <table class="ofast-logs-table" style="margin:0; width:100%; border-collapse:collapse;">
                            <thead>
                                <tr style="background:#f8fafc; text-align:left;">
                                    <th style="padding:16px 20px; font-weight:600; color:#475569; font-size:12px; text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid #e2e8f0;">#</th>
                                    <th style="padding:16px 20px; font-weight:600; color:#475569; font-size:12px; text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid #e2e8f0;">Recipient</th>
                                    <th style="padding:16px 20px; font-weight:600; color:#475569; font-size:12px; text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid #e2e8f0; width:40%;">Message Segment</th>
                                    <th style="padding:16px 20px; font-weight:600; color:#475569; font-size:12px; text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid #e2e8f0;">Gateway</th>
                                    <th style="padding:16px 20px; font-weight:600; color:#475569; font-size:12px; text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid #e2e8f0;">Status</th>
                                    <th style="padding:16px 20px; font-weight:600; color:#475569; font-size:12px; text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid #e2e8f0;">Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $row_num = $offset;
                                foreach ($logs as $log):
                                    $row_num++;
                                    $provider_label = $this->providers[$log->provider]['label'] ?? $log->provider;
                                    $status_class = strtolower($log->status);
                                    if ($status_class === 'sent') $status_class = 'success';
                                    if ($status_class === 'failed') $status_class = 'error';
                                ?>
                                <tr style="border-bottom:1px solid #f1f5f9; transition:var(--ofast-transition);" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                    <td style="padding:16px 20px; color:#94a3b8; font-size:13px;"><?php echo $row_num; ?></td>
                                    <td style="padding:16px 20px; color:#0f172a; font-weight:600; font-size:13px; letter-spacing:0.5px;"><?php echo esc_html($log->recipient); ?></td>
                                    <td style="padding:16px 20px; color:#475569; font-size:13px;" title="<?php echo esc_attr($log->message); ?>">
                                        <div style="background:#f1f5f9; padding:8px 12px; border-radius:6px; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                            <?php echo esc_html(wp_trim_words($log->message, 12, '...')); ?>
                                        </div>
                                    </td>
                                    <td style="padding:16px 20px;">
                                        <div style="display:inline-flex; align-items:center; gap:6px; background:#f1f5f9; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:600; color:#334155;">
                                            <?php echo esc_html($provider_label); ?>
                                        </div>
                                    </td>
                                    <td style="padding:16px 20px;">
                                        <span class="ofast-badge ofast-badge-<?php echo esc_attr($status_class); ?>">
                                            <?php echo esc_html(ucfirst($log->status)); ?>
                                        </span>
                                    </td>
                                    <td style="padding:16px 20px; color:#64748b; font-size:13px;">
                                        <?php echo esc_html(date('M j, Y \a\t g:i a', strtotime($log->sent_at))); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div style="margin-top:24px; display:flex; gap:8px; justify-content:center; align-items:center;">
                        <?php 
                        $prev_page = max(1, $page - 1);
                        $next_page = min($total_pages, $page + 1);
                        ?>
                        
                        <?php if ($page > 1): ?>
                            <a href="<?php echo admin_url('admin.php?page=ofast-sms&tab=logs&log_page=' . $prev_page); ?>" class="ofast-btn ofast-btn-secondary" style="padding:8px 12px;">
                                <span class="dashicons dashicons-arrow-left-alt2" style="margin:0;"></span>
                            </a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <a href="<?php echo admin_url('admin.php?page=ofast-sms&tab=logs&log_page=' . $i); ?>"
                               class="ofast-btn <?php echo $i === $page ? 'ofast-btn-primary' : 'ofast-btn-secondary'; ?>"
                               style="padding:8px 16px; font-size:13px; <?php echo $i === $page ? 'box-shadow: 0 4px 10px rgba(79,70,229,0.3); transform:scale(1.05);' : 'background:#fff;'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="<?php echo admin_url('admin.php?page=ofast-sms&tab=logs&log_page=' . $next_page); ?>" class="ofast-btn ofast-btn-secondary" style="padding:8px 12px;">
                                <span class="dashicons dashicons-arrow-right-alt2" style="margin:0;"></span>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
