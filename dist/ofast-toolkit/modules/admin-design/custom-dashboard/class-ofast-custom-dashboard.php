<?php
/**
 * Ofast X - Custom Dashboard
 * Improves the default WordPress Dashboard
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Custom_Dashboard
{
    /**
     * Instance of this class.
     */
    private static $instance = null;

    /**
     * Mode: 'modern' or 'classic'
     */
    private $mode = 'modern';

    /**
     * Return an instance of this class.
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        // Empty - all initialization happens in init() after enabled check
    }

    /**
     * Initialize the module
     */
    public function init() {
        // Check global toggle from Admin Footer settings FIRST
        $footer_settings = get_option('ofast_admin_footer_settings', array());
        if (empty($footer_settings['enable_custom_dashboard'])) {
            return;
        }

        // Get user preference (modern vs classic)
        $this->mode = get_user_meta(get_current_user_id(), 'ofast_dashboard_mode', true) ?: 'modern';

        // Enqueue Assets (Only in Modern Mode)
        if ($this->mode === 'modern') {
            add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        }
        
        // Inject Dashboard (Checks mode inside)
        add_action('in_admin_header', array($this, 'render_dashboard'));

        // Add Switch Button to Classic Dashboard if in Classic Mode
        add_action('admin_notices', array($this, 'render_switch_to_modern_button'));
        
        // Add body class for styling
        add_filter('admin_body_class', array($this, 'add_body_class'));

        // Handle Dashboard Switch Action
        add_action('admin_action_ofast_switch_dashboard', array($this, 'handle_switch_dashboard'));

        // Register AJAX Search Handler
        add_action('wp_ajax_ofast_global_search', array($this, 'ajax_global_search'));
    }

    /**
     * Whitelist of valid table suffixes (without prefix)
     */
    private $valid_table_suffixes = array(
        'ofast_form_submissions',
        'ofast_smtp_log',
        'ofast_forms',
        'wc_order_stats'
    );

    /**
     * Helper: Check if table exists
     * Uses prepared statement and validates table name against whitelist
     */
    private function table_exists($table) {
        global $wpdb;
        
        // Validate table name against whitelist
        if (!in_array($table, $this->valid_table_suffixes, true)) {
            return false;
        }
        
        $full_table = $wpdb->prefix . $table;
        // Use prepared statement to prevent SQL injection
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_table)) === $full_table;
    }

    /**
     * Enqueue Scripts and Styles
     */
    public function enqueue_assets($hook)
    {
        if ($hook !== 'index.php') {
            return;
        }

        // Check global toggle
        $footer_settings = get_option('ofast_admin_footer_settings', array());
        if (empty($footer_settings['enable_custom_dashboard'])) {
            return;
        }

        $css_file = OFAST_X_PLUGIN_DIR . 'modules/admin-design/custom-dashboard/assets/dashboard.css';
        $js_file = OFAST_X_PLUGIN_DIR . 'modules/admin-design/custom-dashboard/assets/dashboard.js';
        $css_version = file_exists($css_file) ? (string) filemtime($css_file) : OFAST_X_VERSION;
        $js_version = file_exists($js_file) ? (string) filemtime($js_file) : OFAST_X_VERSION;

        wp_enqueue_style(
            'ofast-custom-dashboard', 
            plugins_url('assets/dashboard.css', __FILE__), 
            array(), 
            $css_version
        );

        // Chart.js for Analytics (conditional — avoid duplicate if WooCommerce already registered it)
        if (!wp_script_is('chart-js', 'registered')) {
            wp_register_script(
                'chart-js', 
                plugins_url('assets/vendor/chart.min.js', __FILE__), 
                array(), 
                '4.4.1', 
                true
            );
        }
        wp_enqueue_script('chart-js');

        wp_enqueue_script(
            'ofast-custom-dashboard', 
            plugins_url('assets/dashboard.js', __FILE__), 
            array('jquery', 'jquery-ui-sortable', 'chart-js'), 
            $js_version, 
            true
        );
        
        // Pass data to JS
        wp_localize_script('ofast-custom-dashboard', 'ofast_dashboard', array(
            'mode'      => $this->mode,
            'nonce' => wp_create_nonce('ofast_dashboard_nonce'),
            'admin_url' => admin_url(), // Base admin URL for search redirect
            'analytics' => $this->get_analytics_trends(),
            'forms' => $this->get_top_forms()
        ));
    }
    
    /**
     * Add body class
     */
    public function add_body_class($classes)
    {
        global $pagenow;
        
        // Check global toggle
        $footer_settings = get_option('ofast_admin_footer_settings', array());
        if (empty($footer_settings['enable_custom_dashboard'])) {
            return $classes;
        }

        if ($pagenow === 'index.php' && $this->mode === 'modern') {
            $classes .= ' ofast-clean-dashboard ofast-dark-theme ';
        }
        return $classes;
    }

    /**
     * Render the Custom Dashboard
     */
    public function render_dashboard()
    {
        global $pagenow;
        if ($pagenow !== 'index.php') {
            return;
        }

        // Check global toggle
        $footer_settings = get_option('ofast_admin_footer_settings', array());
        if (empty($footer_settings['enable_custom_dashboard'])) {
            return;
        }

        // Check if we are in Modern Mode
        if ($this->mode !== 'modern') {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            return;
        }

        $user = wp_get_current_user();
        $greeting = $this->get_greeting();
        $recent_activity = $this->get_recent_activity();
        $form_count = $this->get_form_count();
        $user_counts = count_users();
        $total_users = isset($user_counts['total_users']) ? (int) $user_counts['total_users'] : 0;
        $admin_count = isset($user_counts['avail_roles']['administrator']) ? (int) $user_counts['avail_roles']['administrator'] : 0;
        $submissions_url = admin_url('admin.php?page=ofast-forms&tab=submissions');
        $users_url = admin_url('users.php');
        $smtp_url = admin_url('admin.php?page=ofast-smtp&tab=log');
        $updates_url = admin_url('update-core.php');
        
        // Smart Data
        $growth = $this->get_submission_growth();
        $smtp_pulse = $this->get_smtp_pulse();
        $leaders = $this->get_conversion_leaders();
        $commerce_snapshot = $this->get_revenue_snapshot();
        $revenue_report = $this->get_revenue_report();
        $commerce_url = $this->get_revenue_analytics_url();
        ?>
        <div class="ofast-dashboard-takeover">
            <!-- Header -->
            <div class="ofast-dashboard-header">
                <div class="ofast-welcome">
                    <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                        <h1><?php echo esc_html($greeting); ?>, <?php echo esc_html($user->display_name); ?> <span class="ofast-wave">👋</span></h1>
                        <a href="<?php echo esc_url(admin_url('admin.php?action=ofast_switch_dashboard&mode=classic&_wpnonce=' . wp_create_nonce('ofast_switch_dashboard'))); ?>" class="ofast-switch-btn" title="<?php esc_attr_e('Switch to Classic Dashboard', 'ofast-x'); ?>">
                            <span class="dashicons dashicons-dashboard"></span>
                            <?php esc_html_e('Classic', 'ofast-x'); ?>
                        </a>
                    </div>
                    <p><?php esc_html_e('Dashboard Analytics & Overview', 'ofast-x'); ?></p>
                    <p id="ofast-live-clock" style="margin-top: 5px; font-size: 13px; opacity: 0.8; color: #64748b;">
                        <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format')); ?>
                    </p>
                </div>
                
                <div class="ofast-header-actions">
                    <div class="ofast-search-bar">
                        <span class="dashicons dashicons-search"></span>
                        <input type="text" placeholder="<?php esc_attr_e('Search...', 'ofast-x'); ?>">
                    </div>
                    <div class="ofast-profile-pill">
                        <?php echo get_avatar($user->ID, 36); ?>
                        <div class="ofast-profile-info">
                            <span class="name"><?php echo esc_html($user->display_name); ?></span>
                            <?php 
                            $user_roles = $user->roles;
                            $user_role = !empty($user_roles) ? ucfirst($user_roles[0]) : 'User';
                            ?>
                            <span class="role"><?php echo esc_html($user_role); ?></span>
                        </div>
                        <span class="dashicons dashicons-arrow-down-alt2 ofast-pill-arrow"></span>

                        <!-- Profile Dropdown -->
                        <div class="ofast-profile-dropdown">
                            <div class="ofast-dropdown-header">
                                <div class="ofast-big-avatar">
                                    <?php echo get_avatar($user->ID, 80); ?>
                                    <a href="<?php echo esc_url(get_edit_profile_url()); ?>" class="ofast-edit-badge">
                                        <span class="dashicons dashicons-edit"></span>
                                    </a>
                                </div>
                                <h3><?php echo esc_html($user->display_name); ?></h3>
                                <span><?php echo esc_html($user_role); ?></span>
                            </div>
                            
                            <div class="ofast-dropdown-menu">
                                <a href="<?php echo esc_url(get_edit_profile_url()); ?>#password" class="ofast-menu-item">
                                    <div class="ofast-item-left">
                                        <span class="dashicons dashicons-lock"></span>
                                        <span><?php esc_html_e('Change Password', 'ofast-x'); ?></span>
                                    </div>
                                    <span class="ofast-item-right"><span class="dashicons dashicons-arrow-right-alt2"></span></span>
                                </a>

                                <a href="<?php echo esc_url(admin_url('users.php?page=ofast-role-capabilities&role=' . esc_attr($user_roles[0]))); ?>" class="ofast-menu-item">
                                    <div class="ofast-item-left">
                                        <span class="dashicons dashicons-info-outline"></span>
                                        <span><?php esc_html_e('Role Info', 'ofast-x'); ?></span>
                                    </div>
                                    <span class="ofast-item-right"><span class="dashicons dashicons-arrow-right-alt2"></span></span>
                                </a>
                                
                                <a href="<?php echo esc_url(wp_logout_url()); ?>" class="ofast-menu-item logout">
                                    <div class="ofast-item-left">
                                        <span class="dashicons dashicons-exit"></span>
                                        <span><?php esc_html_e('Sign Out', 'ofast-x'); ?></span>
                                    </div>
                                    <span class="ofast-item-right"><span class="dashicons dashicons-arrow-right-alt2"></span></span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Top Stats Row -->
            <div class="ofast-stats-grid">
                <!-- Total Submissions -->
                <a href="<?php echo esc_url($submissions_url); ?>" class="ofast-stat-card dark-card ofast-stat-card-link">
                    <div class="circle-progress" data-percent="<?php echo absint($growth['percent']); ?>" style="--c: #6366f1;">
                        <svg viewBox="0 0 36 36"><path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" stroke-dasharray="<?php echo absint($growth['percent']); ?>, 100" /></svg>
                        <span><?php echo absint($growth['percent']); ?>%</span>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo absint($form_count); ?></h3>
                        <p><?php esc_html_e('Total Submissions', 'ofast-x'); ?></p>
                        <?php if ($growth['trend'] === 'up'): ?>
                            <span class="growth-label up"><span class="dashicons dashicons-arrow-up-alt2"></span> <?php echo absint($growth['diff']); ?> today</span>
                        <?php else: ?>
                            <span class="growth-label neutral">Stable</span>
                        <?php endif; ?>
                    </div>
                </a>

                <!-- Total Users -->
                <a href="<?php echo esc_url($users_url); ?>" class="ofast-stat-card dark-card ofast-stat-card-link">
                    <div class="circle-progress" data-percent="<?php echo $total_users > 0 ? '100' : '0'; ?>" style="--c: #3b82f6;">
                         <svg viewBox="0 0 36 36"><path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" stroke-dasharray="<?php echo $total_users > 0 ? '100, 100' : '0, 100'; ?>" /></svg>
                        <span><?php esc_html_e('Users', 'ofast-x'); ?></span>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo absint($total_users); ?></h3>
                        <p><?php esc_html_e('Total Users', 'ofast-x'); ?></p>
                        <span class="growth-label neutral"><?php printf(esc_html__('%d administrators', 'ofast-x'), absint($admin_count)); ?></span>
                    </div>
                </a>

                <!-- SMTP Pulse -->
                <a href="<?php echo esc_url($smtp_url); ?>" class="ofast-stat-card dark-card ofast-stat-card-link">
                    <div class="circle-progress" data-percent="<?php echo absint($smtp_pulse['percent']); ?>" style="--c: #10b981;">
                         <svg viewBox="0 0 36 36"><path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" stroke-dasharray="<?php echo absint($smtp_pulse['percent']); ?>, 100" /></svg>
                        <span><?php echo absint($smtp_pulse['percent']); ?>%</span>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo esc_html($smtp_pulse['status']); ?></h3>
                        <p><?php esc_html_e('SMTP Reliability', 'ofast-x'); ?></p>
                        <span class="growth-label <?php echo $smtp_pulse['percent'] > 95 ? 'up' : 'down'; ?>"><?php echo absint($smtp_pulse['total']); ?> emails processed</span>
                    </div>
                </a>

                <?php if ($commerce_snapshot) : ?>
                    <a href="<?php echo esc_url($commerce_url); ?>" class="ofast-stat-card dark-card ofast-stat-card-link">
                        <div class="circle-progress" data-percent="<?php echo $commerce_snapshot['orders'] > 0 ? '100' : '0'; ?>" style="--c: #f59e0b;">
                             <svg viewBox="0 0 36 36"><path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" stroke-dasharray="<?php echo $commerce_snapshot['orders'] > 0 ? '100, 100' : '0, 100'; ?>" /></svg>
                            <span><?php echo absint($commerce_snapshot['orders']); ?></span>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo wp_kses_post($commerce_snapshot['revenue_display']); ?></h3>
                            <p><?php esc_html_e('Revenue (7 days)', 'ofast-x'); ?></p>
                            <span class="growth-label neutral"><?php printf(esc_html__('%d paid orders', 'ofast-x'), absint($commerce_snapshot['orders'])); ?></span>
                        </div>
                    </a>
                <?php else : ?>
                    <!-- Updates -->
                    <?php $update_count = count(get_plugin_updates()); ?>
                    <a href="<?php echo esc_url($updates_url); ?>" class="ofast-stat-card dark-card ofast-stat-card-link">
                        <div class="circle-progress" data-percent="<?php echo $update_count > 0 ? '100' : '0'; ?>" style="--c: #f59e0b;">
                             <svg viewBox="0 0 36 36"><path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" stroke-dasharray="<?php echo $update_count > 0 ? '100, 100' : '0, 100'; ?>" /></svg>
                            <span><?php echo absint($update_count); ?></span>
                        </div>
                        <div class="stat-content">
                            <h3><?php esc_html_e('Updates', 'ofast-x'); ?></h3>
                            <p><?php esc_html_e('Pending Updates', 'ofast-x'); ?></p>
                        </div>
                    </a>
                <?php endif; ?>
            </div>
            
            <!-- Main Content Grid -->
            <div class="ofast-main-grid">
                <!-- Analytics Chart -->
                <div class="ofast-chart-card dark-card">
                    <div class="card-header">
                        <h2>Analytics Report</h2>
                        <div class="chart-legend">
                             <span class="legend-item"><span class="dot" style="background:#6366f1"></span> Submissions</span>
                             <span class="legend-item"><span class="dot" style="background:#10b981"></span> Emails Sent</span>
                        </div>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="ofastSubmissionChart"></canvas>
                    </div>
                </div>

                <!-- Recent Activity & Conversion Leaders Column -->
                <div class="ofast-side-column">
                    <!-- Conversion Leaders -->
                    <div class="ofast-activity-card dark-card">
                        <h2><?php esc_html_e('Conversion Leaders', 'ofast-x'); ?></h2>
                        <div class="activity-list">
                            <?php if ($leaders): ?>
                                <?php foreach ($leaders as $leader): ?>
                                    <div class="activity-item">
                                        <div class="leader-badge"><?php echo absint($leader->count); ?></div>
                                        <div class="activity-details">
                                            <strong><?php echo esc_html($leader->title); ?></strong>
                                            <span class="activity-meta"><?php esc_html_e('High Performance Form', 'ofast-x'); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="no-activity"><?php esc_html_e('No form trends yet.', 'ofast-x'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="ofast-activity-card dark-card">
                        <h2><?php esc_html_e('Recent Activity', 'ofast-x'); ?></h2>
                        <div class="activity-list">
                            <?php if ($recent_activity): ?>
                                <?php foreach ($recent_activity as $act): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <span class="dashicons dashicons-email-alt"></span>
                                        </div>
                                        <div class="activity-details">
                                            <strong><?php esc_html_e('New Submission', 'ofast-x'); ?></strong>
                                            <span class="activity-meta"><?php printf(esc_html__('Form #%d', 'ofast-x'), absint($act->form_id)); ?> • <?php echo esc_html(human_time_diff(strtotime($act->submitted_at), current_time('timestamp'))); ?> <?php esc_html_e('ago', 'ofast-x'); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="no-activity"><?php esc_html_e('No recent activity.', 'ofast-x'); ?></p>
                            <?php endif; ?>
                        </div>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=ofast-forms&tab=submissions')); ?>" class="see-all-link"><?php esc_html_e('See All Activity', 'ofast-x'); ?></a>
                    </div>
                </div>
            </div>

            <?php if (false !== $revenue_report) : ?>
                <div class="ofast-report-card dark-card">
                    <div class="card-header">
                        <h2><?php esc_html_e('Revenue Report', 'ofast-x'); ?></h2>
                        <a href="<?php echo esc_url($commerce_url); ?>" class="see-all-link"><?php esc_html_e('Open Analytics', 'ofast-x'); ?></a>
                    </div>

                    <?php if (!empty($revenue_report)) : ?>
                        <div class="ofast-report-table-wrap">
                            <table class="ofast-report-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Date', 'ofast-x'); ?></th>
                                        <th><?php esc_html_e('Paid Orders', 'ofast-x'); ?></th>
                                        <th><?php esc_html_e('Revenue', 'ofast-x'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($revenue_report as $row) : ?>
                                        <tr>
                                            <td><?php echo esc_html($row['date_label']); ?></td>
                                            <td><?php echo absint($row['orders']); ?></td>
                                            <td><?php echo wp_kses_post($row['revenue_display']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else : ?>
                        <p class="no-activity"><?php esc_html_e('No paid WooCommerce orders in the last 7 days.', 'ofast-x'); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <!-- Ofast Widgets Area (Populated by JS) -->
            <div id="ofast-modern-widgets-placeholder" class="ofast-dashboard-grid sortable-grid">
                <!-- Selected legacy widgets will be moved here -->
            </div>
        </div>
        <?php
    }
    
    /**
     * Data Helper: Get Multi-Series Analytics Trends
     */
    private function get_analytics_trends() {
        global $wpdb;
        
        $labels = array();
        $submissions = array_fill(0, 7, 0);
        $smtp = array_fill(0, 7, 0);

        for ($i = 6; $i >= 0; $i--) {
            $labels[] = date('M j', strtotime("-$i days"));
        }

        // 1. Submissions
        if ($this->table_exists('ofast_form_submissions')) {
            $table = $wpdb->prefix . 'ofast_form_submissions';
            $sub_results = $wpdb->get_results($wpdb->prepare("
                SELECT DATE(submitted_at) as date, COUNT(*) as count 
                FROM `{$table}` 
                WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY) 
                GROUP BY DATE(submitted_at)
            ", 6));
            foreach ($sub_results as $row) {
                $idx = array_search(date('M j', strtotime($row->date)), $labels);
                if ($idx !== false) $submissions[$idx] = (int) $row->count;
            }
        }

        // 2. SMTP Volume
        if ($this->table_exists('ofast_smtp_log')) {
            $table = $wpdb->prefix . 'ofast_smtp_log';
            $smtp_results = $wpdb->get_results($wpdb->prepare("
                SELECT DATE(sent_at) as date, COUNT(*) as count 
                FROM `{$table}` 
                WHERE sent_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY) 
                GROUP BY DATE(sent_at)
            ", 6));
            foreach ($smtp_results as $row) {
                $idx = array_search(date('M j', strtotime($row->date)), $labels);
                if ($idx !== false) $smtp[$idx] = (int) $row->count;
            }
        }

        return array(
            'labels' => $labels,
            'submissions' => $submissions,
            'smtp' => $smtp,
        );
    }

    /**
     * Data Helper: Top Forms
     */
    private function get_top_forms() {
        return array();
    }

    /**
     * Data Helper: Recent Activity
     */
    private function get_recent_activity() {
        global $wpdb;
        if (!$this->table_exists('ofast_form_submissions')) {
            return false;
        }
        
        $table = $wpdb->prefix . 'ofast_form_submissions';
        
        // Table name is validated by table_exists(), safe to use directly
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table}` ORDER BY submitted_at DESC LIMIT %d", 5));
    }

    /**
     * Get Submission Count Helper
     */
    private function get_form_count() {
        global $wpdb;
        if (!$this->table_exists('ofast_form_submissions')) {
            return 0;
        }
        $table = $wpdb->prefix . 'ofast_form_submissions';
        // Table name is validated by table_exists(), safe to use directly
        return $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    }

    /**
     * Data Helper: Get Conversion Leaders
     */
    private function get_conversion_leaders() {
        global $wpdb;
        if (!$this->table_exists('ofast_form_submissions') || !$this->table_exists('ofast_forms')) return false;

        $table_sub = $wpdb->prefix . 'ofast_form_submissions';
        $table_forms = $wpdb->prefix . 'ofast_forms';

        // Table names are validated by table_exists(), safe to use directly
        return $wpdb->get_results($wpdb->prepare("
            SELECT f.title, COUNT(s.id) as count 
            FROM `{$table_forms}` f
            JOIN `{$table_sub}` s ON f.id = s.form_id
            GROUP BY f.id
            ORDER BY count DESC
            LIMIT %d
        ", 3));
    }

    /**
     * Data Helper: SMTP Pulse
     */
    private function get_smtp_pulse() {
        global $wpdb;
        if (!$this->table_exists('ofast_smtp_log')) {
            return array('status' => 'No Data', 'percent' => 100, 'total' => 0);
        }

        $table = $wpdb->prefix . 'ofast_smtp_log';
        // Table name is validated by table_exists(), safe to use directly
        $total = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        if ($total == 0) return array('status' => 'Ready', 'percent' => 100, 'total' => 0);

        $success = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE status IN ('success', 'sent')");
        $percent = ($success / $total) * 100;

        return array(
            'status' => $percent > 95 ? 'Healthy' : 'Check Log',
            'percent' => $percent,
            'total' => $total
        );
    }

    /**
     * Data Helper: Growth Momentum (Today vs Yesterday)
     */
    private function get_submission_growth() {
        global $wpdb;
        if (!$this->table_exists('ofast_form_submissions')) return array('diff' => 0, 'trend' => 'neutral', 'percent' => 0);

        $table = $wpdb->prefix . 'ofast_form_submissions';
        
        // Table name is validated by table_exists(), safe to use directly
        $today = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE DATE(submitted_at) = CURDATE()");
        $yesterday = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE DATE(submitted_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)");

        if ($yesterday == 0) {
            $percent = $today > 0 ? 100 : 0;
            return array('diff' => $today, 'trend' => $today > 0 ? 'up' : 'neutral', 'percent' => $percent);
        }

        $diff = $today - $yesterday;
        $percent = ($today / ($today + $yesterday)) * 100; // Relative to recent volume

        return array(
            'diff' => $diff,
            'trend' => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'neutral'),
            'percent' => $percent
        );
    }

    /**
     * Determine if current user can safely view WooCommerce analytics.
     */
    private function can_view_revenue_analytics() {
        return class_exists('WooCommerce') && (current_user_can('manage_woocommerce') || current_user_can('view_woocommerce_reports'));
    }

    /**
     * Determine if WooCommerce analytics data is available.
     */
    private function has_revenue_analytics_available() {
        return $this->can_view_revenue_analytics() && $this->table_exists('wc_order_stats');
    }

    /**
     * Get the WooCommerce revenue analytics page URL.
     */
    private function get_revenue_analytics_url() {
        return admin_url('admin.php?page=wc-admin&path=/analytics/revenue');
    }

    /**
     * Data Helper: 7-day WooCommerce revenue snapshot.
     */
    private function get_revenue_snapshot() {
        global $wpdb;

        if (!$this->has_revenue_analytics_available()) {
            return false;
        }

        $table = $wpdb->prefix . 'wc_order_stats';
        $paid_statuses = array('wc-processing', 'wc-completed', 'processing', 'completed');
        $status_placeholders = implode(', ', array_fill(0, count($paid_statuses), '%s'));
        $query_args = array_merge($paid_statuses, array(6));

        // Table name is validated by table_exists(), safe to use directly.
        $snapshot = $wpdb->get_row(
            $wpdb->prepare(
                "
                SELECT
                    COALESCE(SUM(total_sales), 0) AS revenue,
                    COUNT(*) AS orders
                FROM `{$table}`
                WHERE parent_id = 0
                    AND status IN ({$status_placeholders})
                    AND COALESCE(date_paid, date_created) >= DATE_SUB(NOW(), INTERVAL %d DAY)
                ",
                ...$query_args
            ),
            ARRAY_A
        );

        if (empty($snapshot)) {
            return false;
        }

        $revenue = isset($snapshot['revenue']) ? (float) $snapshot['revenue'] : 0.0;
        $orders = isset($snapshot['orders']) ? (int) $snapshot['orders'] : 0;

        return array(
            'revenue' => $revenue,
            'orders' => $orders,
            'revenue_display' => function_exists('wc_price')
                ? wc_price($revenue)
                : number_format_i18n($revenue, 2),
        );
    }

    /**
     * Data Helper: Daily WooCommerce revenue rows for the last 7 days.
     */
    private function get_revenue_report() {
        global $wpdb;

        if (!$this->has_revenue_analytics_available()) {
            return false;
        }

        $table = $wpdb->prefix . 'wc_order_stats';
        $paid_statuses = array('wc-processing', 'wc-completed', 'processing', 'completed');
        $status_placeholders = implode(', ', array_fill(0, count($paid_statuses), '%s'));
        $query_args = array_merge($paid_statuses, array(6, 7));

        // Table name is validated by table_exists(), safe to use directly.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    DATE(COALESCE(date_paid, date_created)) AS report_date,
                    COUNT(*) AS orders,
                    COALESCE(SUM(total_sales), 0) AS revenue
                FROM `{$table}`
                WHERE parent_id = 0
                    AND status IN ({$status_placeholders})
                    AND COALESCE(date_paid, date_created) >= DATE_SUB(NOW(), INTERVAL %d DAY)
                GROUP BY DATE(COALESCE(date_paid, date_created))
                ORDER BY report_date DESC
                LIMIT %d
                ",
                ...$query_args
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return array();
        }

        foreach ($rows as &$row) {
            $row['date_label'] = date_i18n(get_option('date_format'), strtotime($row['report_date']));
            $row['orders'] = (int) $row['orders'];
            $row['revenue_display'] = function_exists('wc_price')
                ? wc_price((float) $row['revenue'])
                : number_format_i18n((float) $row['revenue'], 2);
        }
        unset($row);

        return $rows;
    }

    /**
     * Get Time-based Greeting
     */
    private function get_greeting()
    {
        $hour = (int) current_time('H');
        if ($hour < 12) {
            return 'Good Morning';
        } elseif ($hour < 18) {
            return 'Good Afternoon';
        } else {
            return 'Good Evening';
        }
    }

    /**
     * Handle Dashboard Switch Action
     */
    public function handle_switch_dashboard()
    {
        check_admin_referer('ofast_switch_dashboard');

        $mode = sanitize_key(isset($_GET['mode']) ? $_GET['mode'] : '');
        $mode = in_array($mode, array('classic', 'modern'), true) ? $mode : 'modern';
        update_user_meta(get_current_user_id(), 'ofast_dashboard_mode', $mode);

        wp_safe_redirect(admin_url('index.php'));
        exit;
    }

    /**
     * Render "Switch to Modern Dashboard" button on classic dashboard
     */
    public function render_switch_to_modern_button()
    {
        global $pagenow;
        if ($pagenow !== 'index.php' || $this->mode === 'modern') {
            return;
        }

        // Check global toggle
        $footer_settings = get_option('ofast_admin_footer_settings', array());
        if (empty($footer_settings['enable_custom_dashboard'])) {
            return;
        }
        ?>
        <style>
            .ofast-classic-switch-btn {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                padding: 6px 12px;
                background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
                color: #fff !important;
                text-decoration: none;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 500;
                margin-left: 10px;
                vertical-align: middle;
                transition: all 0.2s;
            }
            .ofast-classic-switch-btn:hover {
                background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
                color: #fff !important;
                transform: translateY(-1px);
            }
            .ofast-classic-switch-btn .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
            }
        </style>
        <script>
        jQuery(document).ready(function($) {
            var switchBtn = '<a href="<?php echo esc_url(admin_url('admin.php?action=ofast_switch_dashboard&mode=modern&_wpnonce=' . wp_create_nonce('ofast_switch_dashboard'))); ?>" class="ofast-classic-switch-btn"><span class="dashicons dashicons-chart-area"></span> Modern</a>';
            // Insert after "Dashboard" heading
            $('.wrap > h1').first().append(switchBtn);
        });
        </script>
        <?php
    }

    /**
     * AJAX Global Search Handler
     */
    public function ajax_global_search()
    {
        check_ajax_referer('ofast_dashboard_nonce', 'nonce');
    
        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        $results = array();
    
        if (empty($query)) {
            wp_send_json_success(array());
        }
    
        // 1. Search Posts, Pages, Products (if any)
        $post_args = array(
            's' => $query,
            'post_type' => 'any',
            'post_status' => 'publish',
            'posts_per_page' => 5,
            'ignore_sticky_posts' => true
        );
        $posts_query = new WP_Query($post_args);
    
        if ($posts_query->have_posts()) {
            foreach ($posts_query->posts as $post) {
                $post_type_obj = get_post_type_object($post->post_type);
                $label = $post_type_obj ? $post_type_obj->labels->singular_name : ucfirst($post->post_type);
                
                $results[] = array(
                    'type' => 'post', // Generic type for icon mapping
                    'subtype' => $post->post_type,
                    'label' => $label,
                    'title' => get_the_title($post),
                    'url' => get_edit_post_link($post->ID),
                    'date' => get_the_date('', $post)
                );
            }
        }
    
        // 2. Search Users
        if (current_user_can('list_users')) {
            $user_args = array(
                'search' => '*' . $query . '*',
                'search_columns' => array('user_login', 'user_nicename', 'user_email', 'display_name'),
                'number' => 3
            );
            $user_query = new WP_User_Query($user_args);
            $users = $user_query->get_results();
    
            if (!empty($users)) {
                foreach ($users as $user) {
                    $results[] = array(
                        'type' => 'user',
                        'label' => 'User',
                        'title' => $user->display_name . ' (' . $user->user_email . ')',
                        'url' => get_edit_user_link($user->ID),
                        'avatar' => get_avatar_url($user->ID, array('size' => 24))
                    );
                }
            }
        }

        // 3. Search Plugins (Installed)
        if (current_user_can('activate_plugins')) {
             if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $all_plugins = get_plugins();
            $plugin_matches = 0;
            foreach ($all_plugins as $plugin_path => $plugin_data) {
                if ($plugin_matches >= 3) break;
                
                if (stripos($plugin_data['Name'], $query) !== false || stripos($plugin_data['Description'], $query) !== false) {
                     $results[] = array(
                        'type' => 'plugin',
                        'label' => 'Plugin',
                        'title' => $plugin_data['Name'],
                        'url' => admin_url('plugins.php?s=' . urlencode($plugin_data['Name']) . '&plugin_status=all'),
                     );
                     $plugin_matches++;
                }
            }
        }
    
        wp_send_json_success($results);
    }
}
