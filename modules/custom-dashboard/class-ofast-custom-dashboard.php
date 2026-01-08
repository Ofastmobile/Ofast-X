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
        // Enqueue Assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Inject Dashboard
        add_action('in_admin_header', array($this, 'render_dashboard'));
        
        // Add body class for styling
        add_filter('admin_body_class', array($this, 'add_body_class'));
    }

    /**
     * Initialize the module
     */
    public function init() {
        // Hooks are in constructor
    }

    /**
     * Helper: Check if table exists
     */
    private function table_exists($table) {
        global $wpdb;
        $full_table = $wpdb->prefix . $table;
        return $wpdb->get_var("SHOW TABLES LIKE '$full_table'") === $full_table;
    }

    /**
     * Enqueue Scripts and Styles
     */
    public function enqueue_assets($hook)
    {
        if ($hook !== 'index.php') {
            return;
        }

        wp_enqueue_style(
            'ofast-custom-dashboard', 
            plugins_url('assets/dashboard.css', __FILE__), 
            array(), 
            OFAST_X_VERSION
        );

        // Chart.js for Analytics
        wp_enqueue_script(
            'chart-js', 
            plugins_url('assets/vendor/chart.min.js', __FILE__), 
            array(), 
            '4.4.1', 
            true
        );

        wp_enqueue_script(
            'ofast-custom-dashboard', 
            plugins_url('assets/dashboard.js', __FILE__), 
            array('jquery', 'jquery-ui-sortable', 'chart-js'), 
            OFAST_X_VERSION, 
            true
        );
        
        // Pass data to JS
        wp_localize_script('ofast-custom-dashboard', 'ofast_dashboard', array(
            'nonce' => wp_create_nonce('ofast_dashboard_nonce'),
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
        if ($pagenow === 'index.php') {
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
        
        $user = wp_get_current_user();
        $greeting = $this->get_greeting();
        $recent_activity = $this->get_recent_activity();
        $update_count = count(get_plugin_updates());
        $form_count = $this->get_form_count();
        $admin_count = count(get_users(array('role' => 'administrator')));
        
        // Smart Data
        $growth = $this->get_submission_growth();
        $smtp_pulse = $this->get_smtp_pulse();
        $leaders = $this->get_conversion_leaders();
        ?>
        <div class="ofast-dashboard-takeover">
            <!-- Header -->
            <div class="ofast-dashboard-header">
                <div class="ofast-welcome">
                    <h1><?php echo esc_html($greeting); ?>, <?php echo esc_html($user->display_name); ?> <span class="wave">👋</span></h1>
                    <p><?php esc_html_e('Dashboard Analytics & Overview', 'ofast-x'); ?></p>
                </div>
                
                <div class="ofast-header-actions">
                    <div class="ofast-search-bar">
                        <span class="dashicons dashicons-search"></span>
                        <input type="text" placeholder="<?php esc_attr_e('Search...', 'ofast-x'); ?>" disabled>
                    </div>
                    <div class="ofast-profile-pill">
                        <?php echo get_avatar($user->ID, 32); ?>
                        <div class="ofast-profile-info">
                            <span class="name"><?php echo esc_html($user->display_name); ?></span>
                            <span class="status">● <?php esc_html_e('Available', 'ofast-x'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Top Stats Row -->
            <div class="ofast-stats-grid">
                <!-- Total Submissions -->
                <div class="ofast-stat-card dark-card">
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
                </div>

                <!-- Admin Users -->
                <div class="ofast-stat-card dark-card">
                    <div class="circle-progress" data-percent="65" style="--c: #3b82f6;">
                         <svg viewBox="0 0 36 36"><path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" stroke-dasharray="65, 100" /></svg>
                        <span><?php esc_html_e('Active', 'ofast-x'); ?></span>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo absint($admin_count); ?></h3>
                        <p><?php esc_html_e('Administrators', 'ofast-x'); ?></p>
                    </div>
                </div>

                <!-- SMTP Pulse -->
                <div class="ofast-stat-card dark-card">
                    <div class="circle-progress" data-percent="<?php echo absint($smtp_pulse['percent']); ?>" style="--c: #10b981;">
                         <svg viewBox="0 0 36 36"><path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" stroke-dasharray="<?php echo absint($smtp_pulse['percent']); ?>, 100" /></svg>
                        <span><?php echo absint($smtp_pulse['percent']); ?>%</span>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo esc_html($smtp_pulse['status']); ?></h3>
                        <p><?php esc_html_e('SMTP Reliability', 'ofast-x'); ?></p>
                        <span class="growth-label <?php echo $smtp_pulse['percent'] > 95 ? 'up' : 'down'; ?>"><?php echo absint($smtp_pulse['total']); ?> emails processed</span>
                    </div>
                </div>

                <!-- Updates -->
                <div class="ofast-stat-card dark-card">
                    <div class="circle-progress" data-percent="<?php echo $update_count > 0 ? '100' : '0'; ?>" style="--c: #f59e0b;">
                         <svg viewBox="0 0 36 36"><path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" stroke-dasharray="<?php echo $update_count > 0 ? '100, 100' : '0, 100'; ?>" /></svg>
                        <span><?php echo absint($update_count); ?></span>
                    </div>
                    <div class="stat-content">
                        <h3><?php esc_html_e('Updates', 'ofast-x'); ?></h3>
                        <p><?php esc_html_e('Pending Updates', 'ofast-x'); ?></p>
                    </div>
                </div>
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
                             <span class="legend-item"><span class="dot" style="background:#a855f7"></span> Subscribers</span>
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
            
            <!-- Ofast Widgets Area (Populated by JS) -->
            <div id="ofast-modern-widgets-placeholder" class="ofast-dashboard-grid sortable-grid">
                <!-- Selected legacy widgets will be moved here -->
            </div>
            
            <div class="ofast-legacy-toggle">
                <button id="toggle-legacy-widgets"><?php esc_html_e('Switch to Classic Dashboard', 'ofast-x'); ?></button>
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
        $newsletter = array_fill(0, 7, 0);

        for ($i = 6; $i >= 0; $i--) {
            $labels[] = date('M j', strtotime("-$i days"));
        }

        // 1. Submissions
        if ($this->table_exists('ofast_form_submissions')) {
            $sub_results = $wpdb->get_results("
                SELECT DATE(submitted_at) as date, COUNT(*) as count 
                FROM {$wpdb->prefix}ofast_form_submissions 
                WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
                GROUP BY DATE(submitted_at)
            ");
            foreach ($sub_results as $row) {
                $idx = array_search(date('M j', strtotime($row->date)), $labels);
                if ($idx !== false) $submissions[$idx] = (int) $row->count;
            }
        }

        // 2. SMTP Volume
        if ($this->table_exists('ofast_smtp_log')) {
            $smtp_results = $wpdb->get_results("
                SELECT DATE(sent_at) as date, COUNT(*) as count 
                FROM {$wpdb->prefix}ofast_smtp_log 
                WHERE sent_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
                GROUP BY DATE(sent_at)
            ");
            foreach ($smtp_results as $row) {
                $idx = array_search(date('M j', strtotime($row->date)), $labels);
                if ($idx !== false) $smtp[$idx] = (int) $row->count;
            }
        }

        // 3. Newsletter
        if ($this->table_exists('ofast_newsletter_subscribers')) {
            $news_results = $wpdb->get_results("
                SELECT DATE(subscribed_at) as date, COUNT(*) as count 
                FROM {$wpdb->prefix}ofast_newsletter_subscribers 
                WHERE subscribed_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
                GROUP BY DATE(subscribed_at)
            ");
            foreach ($news_results as $row) {
                $idx = array_search(date('M j', strtotime($row->date)), $labels);
                if ($idx !== false) $newsletter[$idx] = (int) $row->count;
            }
        }

        return array(
            'labels' => $labels,
            'submissions' => $submissions,
            'smtp' => $smtp,
            'newsletter' => $newsletter
        );
    }

    /**
     * Data Helper: Top Forms
     */
    private function get_top_forms() {
        // ... simplified for now
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
        
        return $wpdb->get_results("SELECT * FROM $table ORDER BY submitted_at DESC LIMIT 5");
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
        return $wpdb->get_var("SELECT COUNT(*) FROM $table");
    }

    /**
     * Data Helper: Get Conversion Leaders
     */
    private function get_conversion_leaders() {
        global $wpdb;
        if (!$this->table_exists('ofast_form_submissions') || !$this->table_exists('ofast_forms')) return false;

        $table_sub = $wpdb->prefix . 'ofast_form_submissions';
        $table_forms = $wpdb->prefix . 'ofast_forms';

        return $wpdb->get_results("
            SELECT f.title, COUNT(s.id) as count 
            FROM $table_forms f
            JOIN $table_sub s ON f.id = s.form_id
            GROUP BY f.id
            ORDER BY count DESC
            LIMIT 3
        ");
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
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        if ($total == 0) return array('status' => 'Ready', 'percent' => 100, 'total' => 0);

        $success = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'success'");
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
        
        $today = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE DATE(submitted_at) = CURDATE()");
        $yesterday = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE DATE(submitted_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)");

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
}
