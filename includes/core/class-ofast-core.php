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

        if ($this->is_module_enabled('admin-design')) {
            $this->load_admin_design();
        }

        if ($this->is_module_enabled('whos-admin')) {
            $this->load_whos_admin();
        }

        if ($this->is_module_enabled('snippets')) {
            $this->load_snippets();
        }

        if ($this->is_module_enabled('newsletter')) {
            $this->load_newsletter();
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
        require_once OFAST_X_PLUGIN_DIR . 'includes/security/class-ofast-validator.php';
        require_once OFAST_X_PLUGIN_DIR . 'includes/security/class-ofast-rate-limiter.php';
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
        require_once OFAST_X_PLUGIN_DIR . 'modules/whos-admin/class-ofast-whos-admin.php';

        $whos_admin = new Ofast_X_Whos_Admin();
        $whos_admin->init();

        $this->modules['whos-admin'] = $whos_admin;
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
     * Load Newsletter Module
     */
    private function load_newsletter()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/newsletter/class-ofast-newsletter.php';

        $newsletter = new Ofast_X_Newsletter();
        $newsletter->init();

        $this->modules['newsletter'] = $newsletter;
    }

    /**
     * Check if module is enabled
     */
    private function is_module_enabled($module_slug)
    {
        // Get saved settings, or initialize with defaults
        $enabled_modules = get_option('ofastx_modules_enabled', false);

        // First time - save defaults to database
        if ($enabled_modules === false) {
            $enabled_modules = array(
                'email' => true,
                'debug' => true,
                'smtp' => false,      // Will be added later
                'newsletter' => false, // Will be added later
                // Add more modules here as you build them
            );
            update_option('ofastx_modules_enabled', $enabled_modules);
        }

        // Return whether this specific module is enabled
        return isset($enabled_modules[$module_slug]) && $enabled_modules[$module_slug];
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
    public function enqueue_admin_styles()
    {
        wp_enqueue_style(
            'ofast-x-admin',
            OFAST_X_PLUGIN_URL . 'admin/css/ofast-admin.css',
            array(),
            OFAST_X_VERSION
        );
    }
}
