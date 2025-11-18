<?php
/**
 * Ofast X Core Bootstrap Class
 * Main plugin controller that initializes all modules
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Core {
    
    protected $loader;
    protected $modules = array();
    
    /**
     * Run the plugin
     */
    public function run() {
        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->load_modules();
    }
    
    /**
     * Load required dependencies
     */
    private function load_dependencies() {
        // Core loader class
        require_once OFAST_X_PLUGIN_DIR . 'includes/core/class-ofast-loader.php';
        
        // Initialize loader
        $this->loader = new Ofast_X_Loader();
    }
    
    /**
     * Load all active modules
     */
    private function load_modules() {
        // Load Email Module
        if ($this->is_module_enabled('email')) {
            $this->load_email_module();
        }
        
        // Load Debug Indicator (always enabled for now)
        $this->load_debug_indicator();
    }
    
    /**
     * Load Email Module
     */
    private function load_email_module() {
        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-admin.php';
        
        $email_admin = new Ofast_X_Email_Admin();
        $email_admin->init();
        
        $this->modules['email'] = $email_admin;
        
        error_log('Ofast-X: Email module loaded successfully');
    }
    
    /**
     * Load Debug Indicator Module
     */
    private function load_debug_indicator() {
        require_once OFAST_X_PLUGIN_DIR . 'modules/debug-indicator/class-ofast-debug-indicator.php';
        
        $debug_indicator = new Ofast_X_Debug_Indicator();
        $debug_indicator->init();
        
        $this->modules['debug'] = $debug_indicator;
    }
    
    /**
     * Check if module is enabled
     */
    private function is_module_enabled($module_slug) {
        $enabled_modules = get_option('ofastx_modules_enabled', array(
            'email' => true,
            'debug' => true
        ));
        
        return !empty($enabled_modules[$module_slug]);
    }
    
    /**
     * Define the locale for internationalization
     */
    private function set_locale() {
        // Translation ready - handled by main file
    }
    
    /**
     * Register all admin hooks
     */
    private function define_admin_hooks() {
        // Add main admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Add admin styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        // Main Ofast X dashboard
        add_menu_page(
            'Ofast X Dashboard',
            'Ofast X',
            'manage_options',
            'ofast-x-dashboard',
            array($this, 'display_dashboard_page'),
            'dashicons-admin-generic',
            2
        );
    }
    
    /**
     * Display dashboard page
     */
    public function display_dashboard_page() {
        echo '<div class="wrap">';
        echo '<h1>Ofast X Dashboard</h1>';
        echo '<p>🎉 Plugin is working! Modules loaded:</p>';
        echo '<ul>';
        foreach ($this->modules as $slug => $module) {
            echo '<li>✅ ' . esc_html(ucfirst($slug)) . ' Module</li>';
        }
        echo '</ul>';
        echo '</div>';
    }
    
    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles() {
        wp_enqueue_style(
            'ofast-x-admin',
            OFAST_X_PLUGIN_URL . 'admin/css/ofast-admin.css',
            array(),
            OFAST_X_VERSION
        );
    }
}
