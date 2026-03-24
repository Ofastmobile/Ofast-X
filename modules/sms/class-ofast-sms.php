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
        'twilio'          => array('label' => 'Twilio', 'class' => 'Ofast_X_SMS_Twilio', 'file' => 'class-ofast-sms-twilio.php', 'region' => 'International', 'color' => '#F22F46', 'icon' => 'T'),
        'africastalking'  => array('label' => "Africa's Talking", 'class' => 'Ofast_X_SMS_AfricasTalking', 'file' => 'class-ofast-sms-africastalking.php', 'region' => 'Africa', 'color' => '#F5A623', 'icon' => 'AT'),
        'termii'          => array('label' => 'Termii', 'class' => 'Ofast_X_SMS_Termii', 'file' => 'class-ofast-sms-termii.php', 'region' => 'Nigeria', 'color' => '#0078FF', 'icon' => 'Te'),
        'smartsms'        => array('label' => 'SmartSMSSolutions', 'class' => 'Ofast_X_SMS_SmartSMS', 'file' => 'class-ofast-sms-smartsms.php', 'region' => 'Nigeria', 'color' => '#2ECC71', 'icon' => 'SS'),
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
        ?>
        <style>
            :root { --ofast-primary: #6366f1; }

            /* Header */
            .ofast-header { display: flex; align-items: center; gap: 20px; background: #fff; padding: 25px 30px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 25px; margin-top: 20px; }
            .ofast-header-icon { width: 56px; height: 56px; background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.02); border-radius: 16px; display: flex; align-items: center; justify-content: center; }
            .ofast-header-icon .dashicons { font-size: 28px; width: 28px; height: 28px; color: #6366f1; }
            .ofast-header-content h1 { margin: 0 0 5px 0; font-size: 24px; font-weight: 700; color: #1e293b; display: block; padding: 0; }
            .ofast-header-content p { margin: 0; color: #64748b; font-size: 14px; }

            /* Tabs */
            .ofast-tabs-nav { display: flex; flex-wrap: nowrap; gap: 8px; margin-bottom: 25px; padding: 10px 12px; background: #fff; border-radius: 12px; border: 1px solid rgba(226,232,240,0.6); position: sticky; top: 40px; z-index: 99; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow-x: auto; scrollbar-width: none; }
            .ofast-tabs-nav::-webkit-scrollbar { display: none; }
            .ofast-tab { display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px; background: transparent; border: none; border-radius: 8px; color: #64748b; font-size: 14px; font-weight: 500; text-decoration: none; cursor: pointer; transition: all 0.2s ease; white-space: nowrap; }
            .ofast-tab:hover { background: #f1f5f9; color: #1e293b; }
            .ofast-tab.active { background: var(--ofast-primary); color: #fff; box-shadow: 0 4px 12px rgba(99,102,241,0.3); }
            .ofast-tab .dashicons { font-size: 16px; width: 16px; height: 16px; line-height: 16px; }

            /* Tab Content */
            .ofast-tab-content { display: none; }
            .ofast-tab-content.active { display: block; animation: ofastFadeIn 0.3s ease; }
            @keyframes ofastFadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

            /* Cards */
            .ofast-card { background: #fff; border-radius: 16px; padding: 30px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05); border: 1px solid rgba(226,232,240,0.6); margin-bottom: 20px; }
            .ofast-card h2 { margin: 0 0 20px; font-size: 18px; color: #1e293b; font-weight: 600; }
            .ofast-card h3 { margin: 0 0 12px; font-size: 14px; color: #1e293b; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; }

            /* Send Page Layout */
            .ofast-sms-send-layout { display: grid; grid-template-columns: 1fr 320px; gap: 30px; }
            @media screen and (max-width: 1024px) { .ofast-sms-send-layout { grid-template-columns: 1fr; } }

            /* Form Fields */
            .ofast-field { margin-bottom: 16px; }
            .ofast-field label { display: block; font-size: 13px; font-weight: 600; color: #1e293b; margin-bottom: 6px; }
            .ofast-field input[type="text"],
            .ofast-field input[type="password"],
            .ofast-field textarea { width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 13px; transition: border-color 0.2s; }
            .ofast-field input:focus,
            .ofast-field textarea:focus { outline: none; border-color: var(--ofast-primary); box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
            .ofast-field .field-hint { font-size: 11px; color: #94a3b8; margin-top: 4px; }

            /* Provider Cards */
            .ofast-provider-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .ofast-provider-card { border: 2px solid #e2e8f0; border-radius: 10px; padding: 16px; cursor: pointer; transition: all 0.2s; text-align: center; position: relative; }
            .ofast-provider-card:hover { border-color: var(--ofast-primary); box-shadow: 0 2px 8px rgba(99,102,241,0.15); }
            .ofast-provider-card.active { border-color: var(--ofast-primary); background: #eef2ff; }
            .ofast-provider-card input[type="radio"] { display: none; }
            .ofast-provider-logo { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; font-weight: 700; font-size: 13px; color: #fff; letter-spacing: -0.5px; }
            .ofast-provider-card .provider-name { font-weight: 600; font-size: 14px; color: #1e293b; margin-bottom: 4px; }
            .ofast-provider-card .provider-region { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
            .ofast-provider-card .check-icon { position: absolute; top: 8px; right: 8px; width: 20px; height: 20px; background: var(--ofast-primary); color: #fff; border-radius: 50%; display: none; align-items: center; justify-content: center; font-size: 12px; }
            .ofast-provider-card.active .check-icon { display: flex; }

            /* Settings two-column layout */
            .ofast-settings-layout { display: grid; grid-template-columns: 340px 1fr; gap: 24px; }
            @media screen and (max-width: 1024px) { .ofast-settings-layout { grid-template-columns: 1fr; } }

            /* Provider Fields */
            .ofast-provider-fields { display: none; }
            .ofast-provider-fields.active { display: block; }

            /* Buttons */
            .ofast-btn { padding: 10px 24px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; }
            .ofast-btn-primary { background: var(--ofast-primary); color: #fff; }
            .ofast-btn-primary:hover { background: #4f46e5; }
            .ofast-btn-secondary { background: transparent; border: 1px solid #e2e8f0; color: #1e293b; }
            .ofast-btn-secondary:hover { background: #f8fafc; }
            .ofast-btn-danger { background: transparent; border: 1px solid #fca5a5; color: #dc2626; }
            .ofast-btn-danger:hover { background: #fef2f2; }

            /* Test section */
            .ofast-test-row { display: flex; gap: 10px; align-items: flex-end; }
            .ofast-test-row input { flex: 1; max-width: 250px; }

            /* Status badge */
            .ofast-status { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
            .ofast-status.sent { background: #dcfce7; color: #166534; }
            .ofast-status.failed { background: #fee2e2; color: #991b1b; }
            .ofast-status.pending { background: #fef3c7; color: #92400e; }

            /* Logs table */
            .ofast-logs-table { width: 100%; border-collapse: collapse; }
            .ofast-logs-table th { text-align: left; padding: 12px 16px; background: #f8fafc; color: #64748b; font-size: 12px; font-weight: 600; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; }
            .ofast-logs-table td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #334155; }
            .ofast-logs-table tr:hover td { background: #f8fafc; }
            .ofast-logs-empty { text-align: center; padding: 40px; color: #94a3b8; }

            /* Toast override — no background per user request */
            .ofast-sms-result { padding: 10px 0; font-size: 13px; display: none; }
            .ofast-sms-result.success { color: #166534; }
            .ofast-sms-result.error { color: #991b1b; }
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
                $('.ofast-provider-fields').removeClass('active');
                $('#fields-' + provider).addClass('active');
                // Re-initialize Ofast dropdowns in newly visible fields
                if (typeof window.ofastDropdownInit === 'function') {
                    window.ofastDropdownInit();
                } else {
                    $('#fields-' + provider + ' select.ofast-dropdown-native').each(function() {
                        if (!$(this).data('ofast-dropdown')) {
                            $(this).trigger('change');
                        }
                    });
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
                var result = $('#ofast-send-result');

                if (!recipients || !message) {
                    result.removeClass('success').addClass('error visible').text('Please enter recipients and a message.');
                    return;
                }

                btn.prop('disabled', true).text('Sending...');
                result.removeClass('visible');

                $.post(ajaxurl, {
                    action: 'ofast_sms_send',
                    nonce: '<?php echo $nonce; ?>',
                    recipients: recipients,
                    message: message
                }, function(response) {
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-email-alt"></span> Send SMS');
                    if (response.success) {
                        result.removeClass('error').addClass('success visible').text('✓ ' + response.message);
                    } else {
                        var msg = response.message || response.data || 'Failed to send.';
                        if (response.errors && response.errors.length) {
                            msg += '\n' + response.errors.join('\n');
                        }
                        result.removeClass('success').addClass('error visible').text('✕ ' + msg);
                    }
                }).fail(function() {
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-email-alt"></span> Send SMS');
                    result.removeClass('success').addClass('error visible').text('✕ Request failed.');
                });
            });

            // Test connection
            $('#ofast-sms-test-conn').on('click', function() {
                var btn = $(this);
                var result = $('#ofast-conn-result');
                btn.prop('disabled', true).text('Testing...');
                result.removeClass('visible');

                $.post(ajaxurl, {
                    action: 'ofast_sms_test_connection',
                    nonce: '<?php echo $nonce; ?>',
                    provider: $('input[name="ofast_sms_provider"]:checked').val() || ''
                }, function(response) {
                    btn.prop('disabled', false).text('Test Connection');
                    if (response.success) {
                        result.removeClass('error').addClass('success visible').text('✓ ' + (response.message || response.data));
                    } else {
                        result.removeClass('success').addClass('error visible').text('✕ ' + (response.message || response.data || 'Connection failed.'));
                    }
                }).fail(function() {
                    btn.prop('disabled', false).text('Test Connection');
                    result.removeClass('success').addClass('error visible').text('✕ Request failed.');
                });
            });

            // Test SMS
            $('#ofast-sms-test-send').on('click', function() {
                var btn = $(this);
                var phone = $('#ofast-test-phone').val();
                var result = $('#ofast-test-result');

                if (!phone) {
                    result.removeClass('success').addClass('error visible').text('Enter a phone number.');
                    return;
                }

                btn.prop('disabled', true).text('Sending...');
                result.removeClass('visible');

                $.post(ajaxurl, {
                    action: 'ofast_sms_test',
                    nonce: '<?php echo $nonce; ?>',
                    phone: phone
                }, function(response) {
                    btn.prop('disabled', false).text('Send Test');
                    if (response.success) {
                        result.removeClass('error').addClass('success visible').text('✓ ' + (response.message || response.data));
                    } else {
                        result.removeClass('success').addClass('error visible').text('✕ ' + (response.message || response.data || 'Failed.'));
                    }
                }).fail(function() {
                    btn.prop('disabled', false).text('Send Test');
                    result.removeClass('success').addClass('error visible').text('✕ Request failed.');
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
                <div class="ofast-card">
                    <h2>Compose SMS</h2>
                    <div class="ofast-field">
                        <label for="ofast-sms-recipients">Recipients</label>
                        <textarea id="ofast-sms-recipients" rows="3" placeholder="Enter phone numbers, separated by commas or new lines&#10;e.g. +2348012345678, +2349087654321"></textarea>
                        <div class="field-hint">Use international format with country code (e.g. +234 for Nigeria)</div>
                    </div>
                    <div class="ofast-field">
                        <label>Message</label>
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
                        <div class="field-hint">SMS is sent as plain text. Formatting is for preview only.</div>
                    </div>
                    <div class="ofast-actions-row">
                        <button type="button" class="ofast-btn ofast-btn-primary" id="ofast-sms-send-btn" <?php echo !$is_configured ? 'disabled title="Configure a provider in Settings first"' : ''; ?>>
                            <span class="dashicons dashicons-email-alt"></span> Send SMS
                        </button>
                        <?php if (!$is_configured): ?>
                            <span style="font-size:12px; color:#94a3b8;">Configure a provider in Settings to enable sending</span>
                        <?php endif; ?>
                    </div>
                    <div class="ofast-sms-result" id="ofast-send-result"></div>
                </div>
            </div>

            <!-- Sidebar -->
            <div>
                <div class="ofast-card">
                    <h2>Provider</h2>
                    <?php if ($is_configured): ?>
                        <p style="font-size:13px; color:#64748b; margin:0 0 8px;">Active: <strong style="color:#1e293b;"><?php echo esc_html($this->providers[$active_provider]['label'] ?? 'None'); ?></strong></p>
                        <p style="font-size:12px; color:#94a3b8; margin:0;">Region: <?php echo esc_html($this->providers[$active_provider]['region'] ?? ''); ?></p>
                    <?php else: ?>
                        <p style="font-size:13px; color:#94a3b8; margin:0 0 12px;">No provider selected yet.</p>
                        <a href="#settings" class="ofast-btn ofast-btn-secondary" data-tab="settings" style="width:100%; justify-content:center;">Configure Provider</a>
                    <?php endif; ?>
                </div>

                <?php if ($is_configured): ?>
                <div class="ofast-card">
                    <h2>Quick Test</h2>
                    <div class="ofast-field">
                        <label for="ofast-test-phone">Test Phone Number</label>
                        <input type="text" id="ofast-test-phone" placeholder="+2348012345678">
                    </div>
                    <button type="button" class="ofast-btn ofast-btn-secondary" id="ofast-sms-test-send" style="width:100%;">Send Test</button>
                    <div class="ofast-sms-result" id="ofast-test-result"></div>
                </div>
                <?php endif; ?>
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
            <div style="color: #166534; font-size: 13px; margin-bottom: 16px;">✓ Settings saved successfully.</div>
        <?php endif; ?>

        <form method="POST">
            <?php wp_nonce_field('ofast_sms_save_settings', 'ofast_sms_save_nonce'); ?>

            <div class="ofast-settings-layout">
                <!-- Left: Provider Selection -->
                <div class="ofast-card">
                    <h2>SMS Provider</h2>
                    <div class="ofast-provider-grid">
                        <?php foreach ($this->providers as $key => $info): ?>
                            <label class="ofast-provider-card <?php echo $active_provider === $key ? 'active' : ''; ?>" data-provider="<?php echo esc_attr($key); ?>">
                                <input type="radio" name="ofast_sms_provider" value="<?php echo esc_attr($key); ?>" <?php checked($active_provider, $key); ?>>
                                <div class="check-icon">✓</div>
                                <div class="ofast-provider-logo" style="background:<?php echo esc_attr($info['color']); ?>"><?php echo esc_html($info['icon']); ?></div>
                                <div class="provider-name"><?php echo esc_html($info['label']); ?></div>
                                <div class="provider-region"><?php echo esc_html($info['region']); ?></div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Right: Provider Credentials -->
                <div class="ofast-card">
                    <h2>Credentials</h2>
                    <?php if (empty($active_provider)): ?>
                        <p style="color:#94a3b8; font-size:13px;">Select a provider to configure credentials.</p>
                    <?php endif; ?>

                    <?php foreach ($this->providers as $key => $info):
                        require_once OFAST_X_PLUGIN_DIR . 'modules/sms/' . $info['file'];
                        $fields = call_user_func(array($info['class'], 'get_fields'));
                    ?>
                        <div class="ofast-provider-fields <?php echo $active_provider === $key ? 'active' : ''; ?>" id="fields-<?php echo esc_attr($key); ?>">
                            <h3><?php echo esc_html($info['label']); ?></h3>
                            <?php foreach ($fields as $field_key => $field_info):
                                $option_key = 'ofast_sms_' . $key . '_' . $field_key;
                                $stored_value = get_option($option_key, '');
                                $display_value = ($field_info['type'] === 'password' && !empty($stored_value)) ? '••••••••' : $stored_value;
                            ?>
                                <div class="ofast-field">
                                    <label for="<?php echo esc_attr($option_key); ?>"><?php echo esc_html($field_info['label']); ?></label>
                                    <?php if ($field_info['type'] === 'select'): ?>
                                        <select name="<?php echo esc_attr($option_key); ?>" id="<?php echo esc_attr($option_key); ?>" class="ofast-dropdown-native">
                                            <?php foreach ($field_info['options'] as $opt_val => $opt_label): ?>
                                                <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($stored_value, $opt_val); ?>><?php echo esc_html($opt_label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($field_info['type'] === 'checkbox'): ?>
                                        <label style="font-weight:normal; font-size:13px;">
                                            <input type="checkbox" name="<?php echo esc_attr($option_key); ?>" value="1" <?php checked($stored_value, '1'); ?>>
                                            <?php echo esc_html($field_info['description'] ?? ''); ?>
                                        </label>
                                    <?php else: ?>
                                        <input type="<?php echo esc_attr($field_info['type']); ?>"
                                               name="<?php echo esc_attr($option_key); ?>"
                                               id="<?php echo esc_attr($option_key); ?>"
                                               value="<?php echo esc_attr($display_value); ?>"
                                               placeholder="<?php echo esc_attr($field_info['placeholder'] ?? ''); ?>"
                                               autocomplete="off">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- General Settings -->
            <div class="ofast-card">
                <h2>General</h2>
                <div class="ofast-field">
                    <label for="ofast_sms_country_code">Default Country Code</label>
                    <input type="text" name="ofast_sms_country_code" id="ofast_sms_country_code"
                           value="<?php echo esc_attr($country_code); ?>"
                           placeholder="+234" style="max-width: 120px;">
                    <div class="field-hint">Auto-prepended to numbers without a + prefix (e.g. +234 for Nigeria, +254 for Kenya)</div>
                </div>
                <div class="ofast-field">
                    <label for="ofast_sms_log_retention">Log Retention (days)</label>
                    <input type="text" name="ofast_sms_log_retention" id="ofast_sms_log_retention"
                           value="<?php echo esc_attr(get_option('ofast_sms_log_retention', '90')); ?>"
                           placeholder="90" style="max-width: 120px;">
                    <div class="field-hint">Logs older than this are auto-deleted daily. Set to 0 to keep forever.</div>
                </div>
            </div>

            <!-- Actions -->
            <div class="ofast-actions-row">
                <button type="submit" class="ofast-btn ofast-btn-primary">Save Settings</button>
                <button type="button" class="ofast-btn ofast-btn-secondary" id="ofast-sms-test-conn">Test Connection</button>
            </div>
            <div class="ofast-sms-result" id="ofast-conn-result"></div>
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
            <div style="color: #166534; font-size: 13px; margin-bottom: 16px;">✓ Logs cleared successfully.</div>
        <?php endif; ?>

        <div class="ofast-card">
            <div class="ofast-actions-row-between">
                <h2 style="margin:0;">SMS History <span style="font-size:12px; color:#94a3b8; font-weight:400;">(<?php echo $total; ?> total)</span></h2>
                <?php if ($total > 0): ?>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ofast-sms&tab=logs&action=clear_logs'), 'ofast_sms_clear_logs'); ?>"
                       class="ofast-btn ofast-btn-danger"
                       onclick="return confirm('Are you sure you want to clear all SMS logs?');">
                        Clear All Logs
                    </a>
                <?php endif; ?>
            </div>

            <?php if (empty($logs)): ?>
                <div class="ofast-logs-empty">
                    <span class="dashicons dashicons-smartphone" style="font-size:48px; width:48px; height:48px; color:#e2e8f0; display:block; margin: 0 auto 12px;"></span>
                    <p>No SMS messages sent yet.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="ofast-logs-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Recipient</th>
                                <th>Message</th>
                                <th>Provider</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $row_num = $offset;
                            foreach ($logs as $log):
                                $row_num++;
                                $provider_label = $this->providers[$log->provider]['label'] ?? $log->provider;
                            ?>
                            <tr>
                                <td><?php echo $row_num; ?></td>
                                <td><code><?php echo esc_html($log->recipient); ?></code></td>
                                <td title="<?php echo esc_attr($log->message); ?>"><?php echo esc_html(wp_trim_words($log->message, 10, '...')); ?></td>
                                <td><?php echo esc_html($provider_label); ?></td>
                                <td><span class="ofast-status <?php echo esc_attr($log->status); ?>"><?php echo esc_html(ucfirst($log->status)); ?></span></td>
                                <td><?php echo esc_html($log->sent_at); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div style="margin-top: 16px; display: flex; gap: 6px; justify-content: center;">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="<?php echo admin_url('admin.php?page=ofast-sms&tab=logs&log_page=' . $i); ?>"
                               class="ofast-btn <?php echo $i === $page ? 'ofast-btn-primary' : 'ofast-btn-secondary'; ?>"
                               style="padding: 6px 12px; font-size: 12px;">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
