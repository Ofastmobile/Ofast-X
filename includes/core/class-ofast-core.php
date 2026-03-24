<?php

/**
 * Ofast X Core Bootstrap Class
 * Main plugin controller that initializes all modules
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Core
{
    /**
     * Static cache for enabled modules - loaded once, used everywhere
     * This eliminates 20+ redundant get_option() calls per request
     */
    private static $enabled_modules_cache = null;
    
    /**
     * Static cache for frequently accessed options
     */
    private static $options_cache = array();

    protected $loader;
    protected $modules = array();

    /**
     * Run the plugin
     */
    public function run()
    {
        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->load_modules();
    }

    /**
     * Load required dependencies
     */
    private function load_dependencies()
    {
        // Core loader class
        require_once OFAST_X_PLUGIN_DIR . 'includes/core/class-ofast-loader.php';

        // Initialize loader
        $this->loader = new Ofast_X_Loader();
    }

    /**
     * Load all active modules
     */
    private function load_modules()
    {
        // Load Dashboard first (creates main "Ofast X" menu)
        $this->load_dashboard();

        // Load Settings second (adds submenu)
        $this->load_settings();

        // Load Security classes
        $this->load_security();

        // Load modules if enabled
        if ($this->is_module_enabled('email')) {
            $this->load_email_module();
        }

        if ($this->is_module_enabled('debug')) {
            $this->load_debug_indicator();
        }

        if ($this->is_admin_tweak_enabled('enable_admin_design')) {
            $this->load_admin_design();
        }

        if ($this->is_admin_tweak_enabled('enable_whos_admin')) {
            $this->load_whos_admin();
        }

        if ($this->is_module_enabled('snippets')) {
            $this->load_snippets();
        }

        if ($this->is_admin_tweak_enabled('enable_user_roles')) {
            $this->load_user_roles();
        }

        if ($this->is_admin_tweak_enabled('enable_admin_url')) {
            $this->load_admin_url();
        }

        if ($this->is_module_enabled('redirects')) {
            $this->load_redirects();
        }

        // Load SMTP module (always load - it controls wp_mail)
        if ($this->is_module_enabled('smtp')) {
            $this->load_smtp();
        }

        // Load Admin Tweaks module
        if ($this->is_module_enabled('admin-tweaks')) {
            $this->load_admin_tweaks();
        }

        // Load Spam Protection module
        if ($this->is_module_enabled('spam-protection')) {
            $this->load_spam_protection();
        }

        // Load Social Login module
        if ($this->is_module_enabled('social-login')) {
            $this->load_social_login();
        }

        // Load Login Redesign module
        if ($this->is_module_enabled('login-redesign')) {
            $this->load_login_redesign();
        }
    }

    /**
     * Load Dashboard Module
     */
    private function load_dashboard()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/dashboard/class-ofast-dashboard.php';
        $dashboard = new Ofast_X_Dashboard();
        $dashboard->init();
        $this->modules['dashboard'] = $dashboard;
    }

    /**
     * Load Settings Manager
     */
    private function load_settings()
    {
        require_once OFAST_X_PLUGIN_DIR . 'includes/core/class-ofast-settings.php';
        $settings = new Ofast_X_Settings();
        $settings->init();
        $this->modules['settings'] = $settings;
    }

    /**
     * Load Security Classes
     */
    private function load_security()
    {
        require_once OFAST_X_PLUGIN_DIR . 'includes/security/class-ofast-sanitizer.php';
        require_once OFAST_X_PLUGIN_DIR . 'includes/security/class-ofast-validator.php';
        require_once OFAST_X_PLUGIN_DIR . 'includes/security/class-ofast-rate-limiter.php';
    }

    /**
     * Check if module is enabled
     * PERFORMANCE: Uses static cache to avoid repeated DB queries
     */
    private function is_module_enabled($module_slug)
    {
        // Use cached value if available (eliminates 20+ DB reads per request)
        if (self::$enabled_modules_cache === null) {
            self::$enabled_modules_cache = get_option('ofastx_modules_enabled', false);
            
            // First time - save defaults to database
            if (self::$enabled_modules_cache === false) {
                self::$enabled_modules_cache = array(
                    'email' => true,
                    'debug' => true,
                    'smtp' => true,
                    'admin-tweaks' => true,
                );
                update_option('ofastx_modules_enabled', self::$enabled_modules_cache);
            }
        }

        // Return whether this specific module is enabled
        return isset(self::$enabled_modules_cache[$module_slug]) && self::$enabled_modules_cache[$module_slug];
    }

    /**
     * Check if an Admin Tweaks sub-module is enabled
     * PERFORMANCE: Uses static cache for ofast_admin_tweaks option
     */
    private function is_admin_tweak_enabled($tweak_key)
    {
        static $admin_tweaks = null;
        
        if ($admin_tweaks === null) {
            $admin_tweaks = get_option('ofast_admin_tweaks', array());
        }
        
        return !empty($admin_tweaks[$tweak_key]);
    }
    
    /**
     * Get a cached option value
     * PERFORMANCE: Caches option values to avoid repeated DB queries
     * 
     * @param string $key Option key
     * @param mixed $default Default value if option doesn't exist
     * @return mixed Option value
     */
    public static function get_cached_option($key, $default = '')
    {
        if (!isset(self::$options_cache[$key])) {
            self::$options_cache[$key] = get_option($key, $default);
        }
        return self::$options_cache[$key];
    }
    
    /**
     * Clear options cache (call when options are updated)
     * 
     * @param string|null $key Specific key to clear, or null to clear all
     */
    public static function clear_options_cache($key = null)
    {
        if ($key === null) {
            self::$options_cache = array();
            self::$enabled_modules_cache = null;
        } else {
            unset(self::$options_cache[$key]);
            if ($key === 'ofastx_modules_enabled') {
                self::$enabled_modules_cache = null;
            }
        }
    }

    /**
     * Define the locale for internationalization
     */
    private function set_locale()
    {
        // Translation ready - handled by main file
    }

    /**
     * Register all admin hooks
     */
    private function define_admin_hooks()
    {
        // Add admin styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles($hook)
    {
        // Only load on plugin pages for performance
        if (strpos($hook, 'ofast') === false && strpos($hook, 'toplevel_page_ofast') === false) {
            return;
        }

        // Responsive CSS for mobile-friendly admin pages
        $responsive_file = OFAST_X_PLUGIN_DIR . 'assets/css/ofast-admin-responsive.css';
        $responsive_version = (defined('WP_DEBUG') && WP_DEBUG) ? filemtime($responsive_file) : OFAST_X_VERSION;
        
        wp_enqueue_style(
            'ofast-x-admin-responsive',
            OFAST_X_PLUGIN_URL . 'assets/css/ofast-admin-responsive.css',
            array(),
            $responsive_version
        );
    }

    /**
     * Load Email Module
     */
    private function load_email_module()
    {
        $email_file = OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email.php';

        // Check if main email controller exists
        if (file_exists($email_file)) {
            require_once $email_file;

            // Initialize the main email controller (it loads admin and everything else)
            $email_controller = new Ofast_X_Email();
            $email_controller->init();

            $this->modules['email'] = $email_controller;
        }
    }

    /**
     * Load Debug Indicator Module
     */
    private function load_debug_indicator()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/debug-indicator/class-ofast-debug-indicator.php';

        $debug_indicator = new Ofast_X_Debug_Indicator();
        $debug_indicator->init();

        $this->modules['debug'] = $debug_indicator;
    }

    /**
     * Load WP Admin Design Module
     */
    private function load_admin_design()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/admin-design/class-ofast-admin-design.php';

        $admin_design = new Ofast_X_Admin_Design();
        $admin_design->init();

        $this->modules['admin-design'] = $admin_design;
    }

    /**
     * Load Who's Admin Module
     */
    private function load_whos_admin()
    {
        // 1. Load White Label (Who's Admin)
        require_once OFAST_X_PLUGIN_DIR . 'modules/admin-studio/class-ofast-whos-admin.php';
        $whos_admin = new Ofast_X_Whos_Admin();
        $whos_admin->init();
        $this->modules['whos-admin'] = $whos_admin;

        // 2. Load Menu Editor (embedded in White Label Updates tab)
        require_once OFAST_X_PLUGIN_DIR . 'modules/admin-studio/class-ofast-menu-editor.php';
        $menu_editor = new Ofast_X_Menu_Editor();
        $menu_editor->init();
        $this->modules['menu-editor'] = $menu_editor;
        $whos_admin->set_menu_editor($menu_editor);

        // 3. Load Admin Footer (handles Dark Mode toggle)
        require_once OFAST_X_PLUGIN_DIR . 'modules/admin-footer/class-ofast-admin-footer.php';
        $admin_footer = new Ofast_X_Admin_Footer();
        $admin_footer->init();
        $this->modules['admin-footer'] = $admin_footer;

        // 4. Load Custom Dashboard
        require_once OFAST_X_PLUGIN_DIR . 'modules/admin-design/custom-dashboard/class-ofast-custom-dashboard.php';
        $custom_dashboard = new Ofast_X_Custom_Dashboard();
        $custom_dashboard->init();
        $this->modules['custom-dashboard'] = $custom_dashboard;
    }

    /**
     * Load Code Snippets Module
     */
    private function load_snippets()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/snippets/class-ofast-snippets.php';

        $snippets = new Ofast_X_Snippets();
        $snippets->init();
        $this->modules['snippets'] = $snippets;
    }

    /**
     * Load User Roles Module
     */
    private function load_user_roles()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/admin-studio/class-ofast-user-roles.php';

        $user_roles = new Ofast_X_User_Roles();
        $user_roles->init();

        $this->modules['user-roles'] = $user_roles;
    }

    /**
     * Load Admin URL Customizer Module
     */
    private function load_admin_url()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/admin-studio/class-ofast-admin-url.php';

        $admin_url = new Ofast_X_Admin_Url();
        $admin_url->init();

        $this->modules['admin-url'] = $admin_url;
    }

    /**
     * Load Redirects Module
     */
    private function load_redirects()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/redirects/class-ofast-redirects.php';
        $redirects = new Ofast_X_Redirects();
        $redirects->init();
        $this->modules['redirects'] = $redirects;
    }

    /**
     * Load SMTP Module
     */
    private function load_smtp()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/smtp/class-ofast-smtp.php';
        $smtp = Ofast_X_SMTP::get_instance();
        $smtp->init();
        $this->modules['smtp'] = $smtp;
    }

    /**
     * Load Admin Tweaks Module
     */
    private function load_admin_tweaks()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/admin-studio/class-ofast-admin-tweaks.php';
        $admin_tweaks = new Ofast_X_Admin_Tweaks();
        $admin_tweaks->init();
        $this->modules['admin-tweaks'] = $admin_tweaks;
    }

    /**
     * Load Spam Protection Module
     */
    private function load_spam_protection()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/spam-protection/class-ofast-spam-protection.php';
        $spam_protection = new Ofast_X_Spam_Protection();
        $spam_protection->init();
        $this->modules['spam-protection'] = $spam_protection;
    }

    /**
     * Load Social Login Module
     */
    private function load_social_login()
    {
        if (class_exists('Ofast_X_Social_Login')) {
            $social_login = Ofast_X_Social_Login::get_instance();
            $social_login->init();
            $this->modules['social-login'] = $social_login;
        }
    }

    /**
     * Load Login Redesign Module
     */
    private function load_login_redesign()
    {
        if (class_exists('Ofast_X_Login_Redesign')) {
            $login_redesign = Ofast_X_Login_Redesign::get_instance();
            $login_redesign->init();
            $this->modules['login-redesign'] = $login_redesign;
        }
    }
}
