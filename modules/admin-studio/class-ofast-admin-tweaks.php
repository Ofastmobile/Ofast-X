<?php

/**
 * Ofast X - Admin Studio Module
 * Quick admin customizations without separate menus
 * Settings are managed from the main Ofast X Settings page
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Admin_Tweaks
{
    /**
     * Initialize module
     */
    public function init()
    {
        $enabled = get_option('ofastx_modules_enabled', array());
        if (empty($enabled['admin-tweaks'])) {
            return;
        }

        $settings = get_option('ofast_admin_tweaks', array());

        // Post/Page ID Column
        if (!empty($settings['show_post_id'])) {
            add_filter('manage_posts_columns', array($this, 'add_id_column'));
            add_action('manage_posts_custom_column', array($this, 'render_id_column'), 10, 2);
            add_filter('manage_pages_columns', array($this, 'add_id_column'));
            add_action('manage_pages_custom_column', array($this, 'render_id_column'), 10, 2);
            // Note: Products are covered by manage_posts_columns/manage_posts_custom_column
            // No need for separate product hooks as they cause duplicate rendering
            
            add_action('admin_head', array($this, 'add_id_column_css'));
        }

        // Infinity Media Scroll
        if (!empty($settings['infinity_media'])) {
            add_filter('media_library_infinite_scrolling', '__return_true');
        }

        // Hide Admin Bar
        if (!empty($settings['hide_admin_bar'])) {
            add_filter('show_admin_bar', '__return_false');
        }

        // Hide Admin Bar by Role
        if (!empty($settings['hide_admin_bar_roles'])) {
            add_filter('show_admin_bar', array($this, 'hide_admin_bar_by_role'));
        }

        // Remove WordPress Logo from Admin Bar
        if (!empty($settings['remove_wp_logo'])) {
            add_action('wp_before_admin_bar_render', array($this, 'remove_wp_logo'));
        }

        // Remove +New Menu from Admin Bar
        if (!empty($settings['remove_new_content'])) {
            add_action('wp_before_admin_bar_render', array($this, 'remove_new_content_menu'));
        }

        // Rename Howdy to Hello
        if (!empty($settings['rename_howdy'])) {
            add_filter('admin_bar_menu', array($this, 'rename_howdy'), PHP_INT_MAX);
        }

        // Hide Howdy completely
        if (!empty($settings['hide_howdy'])) {
            add_filter('admin_bar_menu', array($this, 'hide_howdy'), 200);
        }

        // Disable XML-RPC
        if (!empty($settings['disable_xmlrpc'])) {
            add_filter('xmlrpc_enabled', '__return_false');
            add_filter('wp_headers', array($this, 'remove_xmlrpc_headers'));
            add_filter('xmlrpc_methods', array($this, 'disable_xmlrpc_methods'));
        }

        // Obfuscate Author Slugs
        if (!empty($settings['obfuscate_author_slugs'])) {
            add_filter('author_link', array($this, 'obfuscate_author_link'), 10, 3);
            add_filter('request', array($this, 'handle_obfuscated_author_request'));
            add_action('template_redirect', array($this, 'block_author_enumeration'));

            // Ensure hash is updated on profile save
            add_action('profile_update', array($this, 'update_user_author_hash'));
            add_action('user_register', array($this, 'update_user_author_hash'));
        }

        // Email Address Obfuscator
        if (!empty($settings['obfuscate_emails'])) {
            add_filter('the_content', array($this, 'obfuscate_emails_in_content'));
            add_filter('the_excerpt', array($this, 'obfuscate_emails_in_content'));
            add_filter('widget_text', array($this, 'obfuscate_emails_in_content'));
            add_filter('comment_text', array($this, 'obfuscate_emails_in_content'));
        }

        // Show User Registration Date Column
        if (!empty($settings['show_registration_date'])) {
            add_filter('manage_users_columns', array($this, 'add_registration_date_column'));
            add_filter('manage_users_custom_column', array($this, 'show_registration_date_column'), 10, 3);
            add_filter('manage_users_sortable_columns', array($this, 'make_registration_date_sortable'));
            add_action('pre_get_users', array($this, 'sort_users_by_registration_date'));
        }

        // Content Duplicator
        if (!empty($settings['enable_content_duplicator'])) {
            add_filter('post_row_actions', array($this, 'add_duplicate_link'), 10, 2);
            add_filter('page_row_actions', array($this, 'add_duplicate_link'), 10, 2);
            // WooCommerce Products Support
            add_filter('post_row_actions', array($this, 'add_product_duplicate_link'), 10, 2);
            
            add_action('admin_action_ofast_duplicate_post', array($this, 'duplicate_post'));
            add_action('admin_notices', array($this, 'show_duplicate_notice'));
        }

        // Add admin menu page
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'save_settings'));
        
        // AJAX handler for reset CSS
        add_action('wp_ajax_ofast_get_default_admin_css', array($this, 'ajax_get_default_css'));
        
        // AJAX handler for resending admin URL email
        add_action('wp_ajax_ofast_resend_admin_url_email', array($this, 'ajax_resend_admin_url_email'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'Admin Studio',
            'Admin Studio',
            'manage_options',
            'ofast-admin-tweaks',
            array($this, 'render_page')
        );
    }

    /**
     * Add ID column to posts/pages list
     */
    public function add_id_column($columns)
    {
        $columns['post_id'] = 'ID';
        return $columns;
    }

    /**
     * Render ID column content
     */
    public function render_id_column($column, $post_id)
    {
        if ($column === 'post_id') {
            echo '<code style="background:#f0f0f1;padding:1px 4px;border-radius:2px;font-size:11px;">' . $post_id . '</code>';
        }
    }

    /**
     * Add CSS for narrow ID column
     */
    public function add_id_column_css()
    {
        $screen = get_current_screen();
        if ($screen && in_array($screen->id, array('edit-post', 'edit-page', 'edit-product'))) {
            echo '<style>.column-post_id { width: 40px !important; text-align: center; }</style>';
        }
    }
    /**
     * Hide admin bar by specific roles
     */
    public function hide_admin_bar_by_role($show)
    {
        if (!is_user_logged_in()) {
            return $show;
        }

        $settings = get_option('ofast_admin_tweaks', array());
        $hidden_roles = isset($settings['hide_admin_bar_roles']) ? $settings['hide_admin_bar_roles'] : array();

        if (empty($hidden_roles)) {
            return $show;
        }

        $user = wp_get_current_user();
        foreach ($user->roles as $role) {
            if (in_array($role, $hidden_roles)) {
                return false;
            }
        }

        return $show;
    }

    /**
     * Remove WordPress logo from admin bar
     */
    public function remove_wp_logo()
    {
        global $wp_admin_bar;
        $wp_admin_bar->remove_menu('wp-logo');
    }

    /**
     * Remove +New content menu from admin bar
     */
    public function remove_new_content_menu()
    {
        global $wp_admin_bar;
        $wp_admin_bar->remove_menu('new-content');
    }

    /**
     * Rename Howdy to Hello
     */
    public function rename_howdy($wp_admin_bar)
    {
        $my_account = $wp_admin_bar->get_node('my-account');
        if ($my_account) {
            $newtitle = str_replace('Howdy,', 'Hello,', $my_account->title);
            $wp_admin_bar->add_node(array(
                'id' => 'my-account',
                'title' => $newtitle,
            ));
        }
    }

    /**
     * Hide Howdy greeting completely
     */
    public function hide_howdy($wp_admin_bar)
    {
        $my_account = $wp_admin_bar->get_node('my-account');
        if ($my_account) {
            // Remove "Howdy, " prefix completely - show just the display name
            $current_user = wp_get_current_user();
            $display_name = $current_user->display_name;
            $newtitle = preg_replace('/^Howdy,\s*/', '', $my_account->title);
            $wp_admin_bar->add_node(array(
                'id' => 'my-account',
                'title' => $newtitle,
            ));
        }
    }

    /**
     * Remove X-Pingback header
     */
    public function remove_xmlrpc_headers($headers)
    {
        unset($headers['X-Pingback']);
        return $headers;
    }

    /**
     * Disable all XML-RPC methods
     */
    public function disable_xmlrpc_methods($methods)
    {
        return array();
    }

    /**
     * Obfuscate author link to use ID instead of nicename
     */
    public function obfuscate_author_link($link, $author_id, $author_nicename)
    {
        $hash = $this->get_user_author_hash($author_id);
        return home_url('/author/' . $hash . '/');
    }

    /**
     * Get or generate the obfuscated hash for a user
     */
    private function get_user_author_hash($user_id)
    {
        $hash = get_user_meta($user_id, 'ofast_author_hash', true);

        if (empty($hash)) {
            $hash = $this->update_user_author_hash($user_id);
        }

        return $hash;
    }

    /**
     * Update/Generate the obfuscated hash for a user
     */
    public function update_user_author_hash($user_id)
    {
        $hash = substr(md5('ofast_author_' . $user_id . wp_salt()), 0, 12);
        update_user_meta($user_id, 'ofast_author_hash', $hash);
        return $hash;
    }

    /**
     * Handle obfuscated author requests
     */
    public function handle_obfuscated_author_request($query_vars)
    {
        if (isset($query_vars['author_name'])) {
            $author_name = $query_vars['author_name'];
            // Check if it's a hash (12 char hex)
            if (preg_match('/^[a-f0-9]{12}$/', $author_name)) {
                // Find the matching author using meta query (much faster than looping)
                $users = get_users(array(
                    'meta_key' => 'ofast_author_hash',
                    'meta_value' => $author_name,
                    'fields' => 'ID',
                    'number' => 1
                ));

                if (!empty($users)) {
                    $query_vars['author'] = $users[0];
                    unset($query_vars['author_name']);
                }
            }
        }
        return $query_vars;
    }

    /**
     * Block author enumeration via ?author=N
     */
    public function block_author_enumeration()
    {
        if (isset($_GET['author']) && is_numeric($_GET['author'])) {
            wp_redirect(home_url(), 301);
            exit;
        }
    }

    /**
     * Obfuscate email addresses in content
     */
    public function obfuscate_emails_in_content($content)
    {
        // Match email addresses
        $pattern = '/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/';
        return preg_replace_callback($pattern, array($this, 'encode_email'), $content);
    }

    /**
     * Encode email address to HTML entities
     */
    private function encode_email($matches)
    {
        $email = $matches[1];
        $encoded = '';
        for ($i = 0; $i < strlen($email); $i++) {
            $encoded .= '&#' . ord($email[$i]) . ';';
        }
        return $encoded;
    }

    /**
     * Add Registration Date column to users table
     */
    public function add_registration_date_column($columns)
    {
        $columns['registration_date'] = 'Registered';
        return $columns;
    }

    /**
     * Display Registration Date in the column
     */
    public function show_registration_date_column($output, $column, $user_id)
    {
        if ($column === 'registration_date') {
            $user = get_userdata($user_id);
            return date('j M, Y H:i', strtotime($user->user_registered));
        }
        return $output;
    }

    /**
     * Make Registration Date column sortable
     */
    public function make_registration_date_sortable($columns)
    {
        $columns['registration_date'] = 'registered';
        return $columns;
    }

    /**
     * Handle sorting by registration date
     */
    public function sort_users_by_registration_date($query)
    {
        if (!is_admin()) {
            return;
        }

        $orderby = $query->get('orderby');
        if ('registered' === $orderby) {
            $query->set('orderby', 'registered');
        }
    }

    /**
     * Save settings
     */
    public function save_settings()
    {
        if (!isset($_POST['ofast_save_admin_tweaks'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        if (!wp_verify_nonce($_POST['admin_tweaks_nonce'], 'ofast_admin_tweaks_save')) {
            return;
        }

        $settings = array(
            'show_post_id' => isset($_POST['ofast_show_post_id']) ? 1 : 0,
            'infinity_media' => isset($_POST['ofast_infinity_media']) ? 1 : 0,
            'hide_admin_bar' => isset($_POST['ofast_hide_admin_bar']) ? 1 : 0,
            'remove_wp_logo' => isset($_POST['ofast_remove_wp_logo']) ? 1 : 0,
            'remove_new_content' => isset($_POST['ofast_remove_new_content']) ? 1 : 0,
            'rename_howdy' => isset($_POST['ofast_rename_howdy']) ? 1 : 0,
            'hide_howdy' => isset($_POST['ofast_hide_howdy']) ? 1 : 0,
            'hide_admin_bar_roles' => isset($_POST['ofast_hide_bar_roles']) ? array_map('sanitize_text_field', $_POST['ofast_hide_bar_roles']) : array(),
            'disable_xmlrpc' => isset($_POST['ofast_disable_xmlrpc']) ? 1 : 0,
            'obfuscate_author_slugs' => isset($_POST['ofast_obfuscate_author_slugs']) ? 1 : 0,
            'obfuscate_emails' => isset($_POST['ofast_obfuscate_emails']) ? 1 : 0,
            'show_registration_date' => isset($_POST['ofast_show_registration_date']) ? 1 : 0,
            // Admin Modules
            'enable_user_roles' => isset($_POST['ofast_enable_user_roles']) ? 1 : 0,
            'enable_whos_admin' => isset($_POST['ofast_enable_whos_admin']) ? 1 : 0,
            'enable_menu_editor' => isset($_POST['ofast_enable_menu_editor']) ? 1 : 0,
            'enable_content_ordering' => isset($_POST['ofast_enable_content_ordering']) ? 1 : 0,
            'enable_admin_url' => isset($_POST['ofast_enable_admin_url']) ? 1 : 0,
            'enable_admin_design' => isset($_POST['ofast_enable_admin_design']) ? 1 : 0,
            'enable_content_duplicator' => isset($_POST['ofast_enable_content_duplicator']) ? 1 : 0,
        );
        
        // Save Interface Mode
        if (isset($_POST['ofast_admin_interface_mode'])) {
            $mode = sanitize_key($_POST['ofast_admin_interface_mode']);
            if (in_array($mode, array('classic', 'modern'))) {
                update_option('ofast_admin_interface_mode', $mode);
            }
        }

        update_option('ofast_admin_tweaks', $settings);

        // Save Admin URL settings if enabled
        if (!empty($settings['enable_admin_url']) && isset($_POST['ofast_admin_url_slug'])) {
            $old_slug = get_option('ofast_admin_custom_slug', '');
            $new_slug = sanitize_title($_POST['ofast_admin_url_slug']);
            
            // Don't allow reserved slugs
            $reserved = array('wp-admin', 'wp-login', 'wp-login.php', 'admin', 'login', 'dashboard', 'wp-content', 'wp-includes');
            if (!in_array($new_slug, $reserved)) {
                if (!empty($new_slug) && $new_slug !== $old_slug) {
                    update_option('ofast_admin_custom_slug', $new_slug);
                    // Generate emergency key if not exists
                    if (empty(get_option('ofast_admin_emergency_key'))) {
                        update_option('ofast_admin_emergency_key', wp_generate_password(32, false));
                    }
                } elseif (empty($new_slug)) {
                    delete_option('ofast_admin_custom_slug');
                }
            }
            
            // Save security settings
            if (isset($_POST['ofast_max_attempts'])) {
                $max_attempts = max(1, min(20, intval($_POST['ofast_max_attempts'])));
                update_option('ofast_security_max_attempts', $max_attempts);
            }
            if (isset($_POST['ofast_lockout_duration'])) {
                $lockout_duration = max(1, min(1440, intval($_POST['ofast_lockout_duration'])));
                update_option('ofast_security_lockout_duration', $lockout_duration);
            }
            if (isset($_POST['ofast_ip_whitelist'])) {
                update_option('ofast_security_ip_whitelist', sanitize_textarea_field($_POST['ofast_ip_whitelist']));
            }
        }

        // Save Admin Design CSS if enabled
        if (!empty($settings['enable_admin_design']) && isset($_POST['ofast_admin_design_css'])) {
            // wp_unslash removes WP's automatic slashes, preventing backslash accumulation
            $custom_css = wp_unslash($_POST['ofast_admin_design_css']);
            $custom_css = wp_strip_all_tags($custom_css);
            update_option('ofast_admin_design_css', $custom_css);
        }

        // Redirect with success flag
        wp_redirect(add_query_arg('settings_saved', '1', wp_get_referer()));
        exit;
    }

    /**
     * AJAX handler for getting default CSS
     */
    public function ajax_get_default_css()
    {
        check_ajax_referer('ofast_admin_css_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $default_css_file = OFAST_X_PLUGIN_DIR . 'modules/admin-design/assets/admin-design.css';
        $css = '';
        
        if (file_exists($default_css_file)) {
            $css = file_get_contents($default_css_file);
        }
        
        wp_send_json_success(array('css' => $css));
    }

    /**
     * AJAX handler for resending admin URL login details email
     */
    public function ajax_resend_admin_url_email()
    {
        check_ajax_referer('ofast_resend_email_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $custom_slug = get_option('ofast_admin_custom_slug', '');
        $emergency_key = get_option('ofast_admin_emergency_key', '');
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        
        if (empty($custom_slug)) {
            wp_send_json_error('Admin URL protection is not active');
        }
        
        // Prepare email
        $login_url = trailingslashit($site_url) . $custom_slug;
        $emergency_url = wp_login_url() . '?ofast_emergency=' . $emergency_key;
        
        $subject = '[' . $site_name . '] Your Admin Login Details';
        
        $message = "Hello Admin,\n\n";
        $message .= "Here are your custom admin login details for " . $site_name . ":\n\n";
        $message .= "═══════════════════════════════════════\n";
        $message .= "CUSTOM LOGIN URL:\n";
        $message .= $login_url . "\n\n";
        $message .= "EMERGENCY BYPASS URL (One-Time Use):\n";
        $message .= $emergency_url . "\n";
        $message .= "═══════════════════════════════════════\n\n";
        $message .= "IMPORTANT:\n";
        $message .= "• Keep this email safe - you'll need these URLs to access your admin area\n";
        $message .= "• The emergency URL rotates after each use\n";
        $message .= "• If locked out, add this to wp-config.php:\n";
        $message .= "  define('OFAST_DISABLE_ADMIN_PROTECTION', true);\n\n";
        $message .= "This email was sent from: " . $site_url . "\n";
        $message .= "Time: " . current_time('mysql') . "\n";
        
        $sent = wp_mail($admin_email, $subject, $message);
        
        if ($sent) {
            wp_send_json_success(array('message' => 'Email sent'));
        } else {
            wp_send_json_error('Failed to send email');
        }
    }

    /**
     * Render admin tweaks settings page
     */
    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }

        $settings = get_option('ofast_admin_tweaks', array());
        $roles = wp_roles()->roles;

        // Show toast if settings were saved
        if (isset($_GET['settings_saved']) && $_GET['settings_saved'] == '1') {
            echo Ofast_X_Toast::render('Admin tweaks settings saved!', 'success');
        }
?>
        <div class="wrap ofast-tweaks-wrap">
            <!-- Modern Header -->
            <div class="ofast-page-header">
                <div class="ofast-header-content">
                    <div class="ofast-header-icon">
                        <span class="dashicons dashicons-admin-tools"></span>
                    </div>
                    <div class="ofast-header-text">
                        <h1>Admin Studio</h1>
                        <p>Quick customization and security hardening for your dashboard</p>
                    </div>
                </div>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('ofast_admin_tweaks_save', 'admin_tweaks_nonce'); ?>
                
                <!-- Mobile Only Save Button (Top) -->
                <div class="ofast-form-actions mobile-only" style="margin-bottom: 20px; text-align: center;">
                     <button type="submit" name="ofast_save_admin_tweaks" class="ofast-btn-primary" style="width: 100%; justify-content: center;">
                        Save Changes
                    </button>
                </div>

                <div class="ofast-studio-wrapper">
                    
                    <!-- Sidebar Tabs -->
                    <div class="ofast-studio-sidebar">
                        <div class="ofast-studio-tab active" data-target="tab-interface">
                            <span class="dashicons dashicons-desktop"></span> Interface
                        </div>
                        <div class="ofast-studio-tab" data-target="tab-admin-bar">
                            <span class="dashicons dashicons-menu-alt"></span> Admin Bar
                        </div>
                        <div class="ofast-studio-tab" data-target="tab-security">
                            <span class="dashicons dashicons-shield"></span> Security
                        </div>
                        <div class="ofast-studio-tab" data-target="tab-modules">
                            <span class="dashicons dashicons-admin-plugins"></span> Modules
                        </div>
                        
                        <!-- Save Button in Sidebar -->
                        <div style="margin-top: 30px;">
                            <button type="submit" name="ofast_save_admin_tweaks" class="ofast-btn-primary" style="width: 100%; justify-content: center;">
                                Save Changes
                            </button>
                        </div>
                    </div>

                    <!-- Content Area -->
                    <div class="ofast-studio-content">
                        
                        <!-- TAB: INTERFACE -->
                        <div id="tab-interface" class="ofast-tab-content active">
                            <div class="ofast-card">
                                <div class="ofast-card-header">
                                    <h2>Interface Customizations</h2>
                                </div>
                                <div class="ofast-card-body">
                                    <div class="ofast-tweak-row">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_show_post_id">Post/Page ID Column</label>
                                            <p class="description">Adds a handy ID column to your Posts and Pages lists.</p>
                                        </div>
                                        <div class="ofast-tweak-action">
                                            <label class="ofast-toggle">
                                                <input type="checkbox" name="ofast_show_post_id" id="ofast_show_post_id" value="1" <?php checked(!empty($settings['show_post_id'])); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="ofast-tweak-row">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_enable_content_duplicator">Content Duplicator</label>
                                            <p class="description">Add a "Duplicate" link to row actions for posts and pages.</p>
                                        </div>
                                        <div class="ofast-tweak-action">
                                            <label class="ofast-toggle">
                                                <input type="checkbox" name="ofast_enable_content_duplicator" id="ofast_enable_content_duplicator" value="1" <?php checked(!empty($settings['enable_content_duplicator'])); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="ofast-tweak-row">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_show_registration_date">User Registration Date Column</label>
                                            <p class="description">Adds a sortable registration date column to the Users table.</p>
                                        </div>
                                        <div class="ofast-tweak-action">
                                            <label class="ofast-toggle">
                                                <input type="checkbox" name="ofast_show_registration_date" id="ofast_show_registration_date" value="1" <?php checked(!empty($settings['show_registration_date'])); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="ofast-tweak-row">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_infinity_media">Infinity Media Scroll</label>
                                            <p class="description">Enables infinite scrolling in the Media Library (no more "Load More").</p>
                                        </div>
                                        <div class="ofast-tweak-action">
                                            <label class="ofast-toggle">
                                                <input type="checkbox" name="ofast_infinity_media" id="ofast_infinity_media" value="1" <?php checked(!empty($settings['infinity_media'])); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB: ADMIN BAR -->
                        <div id="tab-admin-bar" class="ofast-tab-content">
                             <div class="ofast-card">
                                <div class="ofast-card-header">
                                    <h2>Admin Bar Tweaks</h2>
                                </div>
                                <div class="ofast-card-body">
                                    <div class="ofast-tweak-row">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_hide_admin_bar">Hide Admin Bar (Global)</label>
                                            <p class="description">Completely hides the admin bar on the frontend for everyone.</p>
                                        </div>
                                        <div class="ofast-tweak-action">
                                            <label class="ofast-toggle">
                                                <input type="checkbox" name="ofast_hide_admin_bar" id="ofast_hide_admin_bar" value="1" <?php checked(!empty($settings['hide_admin_bar'])); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="ofast-tweak-row">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_remove_wp_logo">Remove WP Logo</label>
                                            <p class="description">Removes the WordPress logo from the top-left of the admin bar.</p>
                                        </div>
                                        <div class="ofast-tweak-action">
                                            <label class="ofast-toggle">
                                                <input type="checkbox" name="ofast_remove_wp_logo" id="ofast_remove_wp_logo" value="1" <?php checked(!empty($settings['remove_wp_logo'])); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="ofast-tweak-row">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_remove_new_content">Remove "+New" Menu</label>
                                            <p class="description">Clean up the admin bar by removing the creation shortcut.</p>
                                        </div>
                                        <div class="ofast-tweak-action">
                                            <label class="ofast-toggle">
                                                <input type="checkbox" name="ofast_remove_new_content" id="ofast_remove_new_content" value="1" <?php checked(!empty($settings['remove_new_content'])); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="ofast-tweak-row">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_rename_howdy">Rename "Howdy"</label>
                                            <p class="description">Change "Howdy, Name" to "Hello, Name".</p>
                                            </div>
                                        <div class="ofast-tweak-action">
                                            <label class="ofast-toggle">
                                                <input type="checkbox" name="ofast_rename_howdy" id="ofast_rename_howdy" value="1" <?php checked(!empty($settings['rename_howdy'])); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="ofast-tweak-row">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_hide_howdy">Hide Greeting</label>
                                            <p class="description">Remove the greeting entirely, showing only the username.</p>
                                        </div>
                                        <div class="ofast-tweak-action">
                                            <label class="ofast-toggle">
                                                <input type="checkbox" name="ofast_hide_howdy" id="ofast_hide_howdy" value="1" <?php checked(!empty($settings['hide_howdy'])); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Role Hiding -->
                                    <div class="ofast-tweak-row" style="display: block;">
                                        <div style="margin-bottom: 10px;">
                                            <label style="font-weight: 600; color: #374151;">Hide Admin Bar by Role</label>
                                            <p class="description">Select roles that should NOT see the admin bar.</p>
                                        </div>
                                        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                                            <?php foreach ($roles as $role_slug => $role_data): ?>
                                                <label class="ofast-checkbox-pill">
                                                    <input type="checkbox" name="ofast_hide_bar_roles[]" value="<?php echo esc_attr($role_slug); ?>"
                                                        <?php checked(in_array($role_slug, $settings['hide_admin_bar_roles'] ?? array())); ?>>
                                                    <span><?php echo esc_html(translate_user_role($role_data['name'])); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB: SECURITY -->
                        <div id="tab-security" class="ofast-tab-content">
                            <div class="ofast-card">
                                <div class="ofast-card-header">
                                    <h2>Security Hardening</h2>
                                </div>
                                <div class="ofast-card-body">
                                    <div class="ofast-tweak-row">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_disable_xmlrpc">Disable XML-RPC</label>
                                            <p class="description">Protects against brute force and DDoS attacks.</p>
                                        </div>
                                        <div class="ofast-tweak-action">
                                            <label class="ofast-toggle">
                                                <input type="checkbox" name="ofast_disable_xmlrpc" id="ofast_disable_xmlrpc" value="1" <?php checked(!empty($settings['disable_xmlrpc'])); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="ofast-tweak-row">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_obfuscate_author_slugs">Obfuscate Author Slugs</label>
                                            <p class="description">Hides real usernames in URLs (e.g. /author/xyz123/).</p>
                                        </div>
                                        <div class="ofast-tweak-action">
                                            <label class="ofast-toggle">
                                                <input type="checkbox" name="ofast_obfuscate_author_slugs" id="ofast_obfuscate_author_slugs" value="1" <?php checked(!empty($settings['obfuscate_author_slugs'])); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="ofast-tweak-row">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_obfuscate_emails">Obfuscate Emails</label>
                                            <p class="description">Encodes email addresses in content to HTML entities.</p>
                                        </div>
                                        <div class="ofast-tweak-action">
                                            <label class="ofast-toggle">
                                                <input type="checkbox" name="ofast_obfuscate_emails" id="ofast_obfuscate_emails" value="1" <?php checked(!empty($settings['obfuscate_emails'])); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                         <!-- TAB: MODULES -->
                        <div id="tab-modules" class="ofast-tab-content">
                            <div class="ofast-card">
                                <div class="ofast-card-header">
                                    <h2>Admin Modules Downloads</h2>
                                </div>
                                <div class="ofast-card-body">
                                    <p class="description" style="margin-top: 0; margin-bottom: 20px;">Enable additional admin features.</p>
                                    
                                    <div class="ofast-tweak-row">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_enable_user_roles">User Roles Manager</label>
                                            <p class="description">Assign multiple roles to users.</p>
                                        </div>
                                        <div class="ofast-tweak-action">
                                            <label class="ofast-toggle">
                                                <input type="checkbox" name="ofast_enable_user_roles" id="ofast_enable_user_roles" value="1" <?php checked(!empty($settings['enable_user_roles'])); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="ofast-tweak-row">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_enable_whos_admin">Who's Admin Widget</label>
                                            <p class="description">Dashboard widget showing admin users.</p>
                                        </div>
                                        <div class="ofast-tweak-action">
                                            <label class="ofast-toggle">
                                                <input type="checkbox" name="ofast_enable_whos_admin" id="ofast_enable_whos_admin" value="1" <?php checked(!empty($settings['enable_whos_admin'])); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="ofast-tweak-row">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_enable_menu_editor">Admin Menu Editor</label>
                                            <p class="description">Reorder and rename admin menu items.</p>
                                        </div>
                                        <div class="ofast-tweak-action">
                                            <label class="ofast-toggle">
                                                <input type="checkbox" name="ofast_enable_menu_editor" id="ofast_enable_menu_editor" value="1" <?php checked(!empty($settings['enable_menu_editor'])); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="ofast-tweak-row">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_enable_content_ordering">Content Ordering</label>
                                            <p class="description">Drag-and-drop reordering for content.</p>
                                        </div>
                                        <div class="ofast-tweak-action">
                                            <label class="ofast-toggle">
                                                <input type="checkbox" name="ofast_enable_content_ordering" id="ofast_enable_content_ordering" value="1" <?php checked(!empty($settings['enable_content_ordering'])); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="ofast-tweak-row">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_enable_admin_url">Admin URL Security</label>
                                            <p class="description">Hide /wp-admin behind a custom URL.</p>
                                        </div>
                                        <div class="ofast-tweak-action">
                                            <label class="ofast-toggle">
                                                <input type="checkbox" name="ofast_enable_admin_url" id="ofast_enable_admin_url" value="1" <?php checked(!empty($settings['enable_admin_url'])); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div id="admin-url-settings" style="display: <?php echo !empty($settings['enable_admin_url']) ? 'block' : 'none'; ?>; margin-top: 15px;">
                                        <div class="ofast-collapsible-header" onclick="toggleCollapsible('admin-url-content')" style="background: #f8fafc; padding: 12px 15px; border-radius: 8px 8px 0 0; border: 1px solid #e2e8f0; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                                            <span style="font-weight: 600; color: #374151;">Admin URL Settings</span>
                                            <span class="dashicons dashicons-arrow-down-alt2" id="admin-url-content-arrow" style="transition: transform 0.3s; transform: rotate(-90deg);"></span>
                                        </div>
                                        <div id="admin-url-content" style="padding: 20px; background: #fff; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 8px 8px; display: none;">
                                            <?php $this->render_admin_url_settings(); ?>
                                        </div>
                                    </div>

                                    <div class="ofast-tweak-row" style="margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 20px;">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_enable_admin_design">Admin Design (Custom CSS)</label>
                                            <p class="description">Modern glassmorphism styling for admin.</p>
                                        </div>
                                        <div class="ofast-tweak-action">
                                            <label class="ofast-toggle">
                                                <input type="checkbox" name="ofast_enable_admin_design" id="ofast_enable_admin_design" value="1" <?php checked(!empty($settings['enable_admin_design'])); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div id="admin-design-settings" style="display: <?php echo !empty($settings['enable_admin_design']) ? 'block' : 'none'; ?>; margin-top: 15px;">
                                        <div class="ofast-collapsible-header" onclick="toggleCollapsible('admin-design-content')" style="background: #f8fafc; padding: 12px 15px; border-radius: 8px 8px 0 0; border: 1px solid #e2e8f0; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                                            <span style="font-weight: 600; color: #374151;">Admin Design CSS</span>
                                            <span class="dashicons dashicons-arrow-down-alt2" id="admin-design-content-arrow" style="transition: transform 0.3s; transform: rotate(-90deg);"></span>
                                        </div>
                                        <div id="admin-design-content" style="padding: 20px; background: #fff; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 8px 8px; display: none;">
                                            <?php $this->render_admin_design_editor(); ?>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>

                    </div>
                </div>

            </form>
        </div>

        <style>
            .ofast-tweaks-wrap { max-width: 1200px; margin: 20px auto; padding: 0 20px; }            
            /* Header */
            .ofast-page-header { background: #ffffff; border-radius: 16px; padding: 30px; margin-bottom: 30px; border: 1px solid #e2e8f0; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
            .ofast-header-content { display: flex; align-items: center; gap: 20px; }
            .ofast-header-icon { width: 60px; height: 60px; background: #ffffff; border-radius: 16px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.2); border: 1px solid #e2e8f0; color: #6366f1; }
            .ofast-header-icon .dashicons { font-size: 28px; width: 28px; height: 28px; }
            .ofast-header-text h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1e293b; }
            .ofast-header-text p { margin: 5px 0 0; color: #64748b; font-size: 15px; }

            /* STUDIO LAYOUT - 2 COLUMNS */
            .ofast-studio-wrapper { display: flex; align-items: flex-start; gap: 60px; margin-top: 30px; }
            
            /* Sidebar */
            .ofast-studio-sidebar { width: 220px; flex-shrink: 0; position: sticky; top: 50px; }
            .ofast-studio-tab { display: flex; align-items: center; gap: 12px; padding: 15px 20px; margin-bottom: 8px; background: #fff; border-radius: 12px; cursor: pointer; transition: all 0.2s; border: 1px solid transparent; color: #64748b; font-weight: 500; }
            .ofast-studio-tab:hover { background: #f8fafc; color: #374151; transform: translateX(5px); }
            /* Active Tab - Light Variant */
            .ofast-studio-tab.active { background: #e0e7ff; color: #4338ca; border: 1px solid #c7d2fe; box-shadow: 0 2px 6px rgba(99, 102, 241, 0.1); }
            .ofast-studio-tab .dashicons { font-size: 20px; width: 20px; height: 20px; }
            
            /* Content Area */
            .ofast-studio-content { flex-grow: 1; min-width: 0; }
            .ofast-tab-content { display: none; animation: fadeIn 0.3s ease; }
            .ofast-tab-content.active { display: block; }
            
            @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

            /* SEARCH MODE: Show all tabs when searching */
            .ofast-studio-wrapper.searching .ofast-tab-content { display: block !important; animation: none; margin-bottom: 30px; }
            .ofast-studio-wrapper.searching .ofast-studio-sidebar { opacity: 0.5; pointer-events: none; }

            /* Search Box in Card Header */
            .ofast-search-box { position: relative; }
            .ofast-search-box input { width: 200px; padding: 8px 35px 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 13px; background: #fff; transition: all 0.2s; }
            .ofast-search-box input:focus { border-color: #6366f1; outline: none; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1); width: 260px; }
            .ofast-search-box .dashicons { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 16px; width: 16px; height: 16px; pointer-events: none; }
            
            /* Hidden Elements */
            .ofast-tweak-row.hidden-by-search { display: none !important; }
            .ofast-section-title.hidden-by-search { display: none !important; }
            .ofast-card.hidden-by-search { display: none !important; }
            .ofast-tab-content.hidden-by-search { display: none !important; }

            /* Grid Layout */
            .ofast-tweaks-container { display: flex; flex-direction: column; gap: 0; }
            
            /* Cards */
            .ofast-card { background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); overflow: hidden; border: 1px solid rgba(0,0,0,0.05); }
            .ofast-card-header { display: flex; align-items: center; gap: 12px; padding: 20px 25px; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-bottom: 1px solid #e2e8f0; }
            .ofast-card-header .dashicons { font-size: 20px; width: 20px; height: 20px; color: #6366f1; }
            .ofast-card-header h2 { margin: 0; font-size: 16px; font-weight: 600; color: #1e293b; }
            .ofast-card-body { padding: 25px; }

            /* Tweak Rows */
            .ofast-tweak-row { display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid #f1f5f9; }
            .ofast-tweak-row:last-child { border-bottom: none; }
            .ofast-tweak-content label { font-weight: 600; color: #374151; font-size: 15px; display: block; margin-bottom: 4px; }
            .ofast-tweak-content .description { margin: 0; font-size: 13px; color: #64748b; }
            .ofast-section-title { margin: 20px 0 10px; font-size: 14px; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.5px; font-weight: 700; }

            /* Modern Toggle Switch */
            .ofast-toggle { position: relative; display: inline-block; width: 48px; height: 26px; flex-shrink: 0; }
            .ofast-toggle input { opacity: 0; width: 0; height: 0; }
            .ofast-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .3s; border-radius: 34px; }
            .ofast-slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            input:checked + .ofast-slider { background-color: #6366f1; }
            input:checked + .ofast-slider:before { transform: translateX(22px); }
            input:focus + .ofast-slider { box-shadow: 0 0 1px #6366f1; }

            /* Checkbox Pills */
            .ofast-checkbox-pill { display: inline-flex; align-items: center; padding: 6px 12px; background: #f1f5f9; border-radius: 20px; cursor: pointer; transition: all 0.2s; border: 1px solid transparent; }
            .ofast-checkbox-pill input { display: none; }
            .ofast-checkbox-pill span { font-size: 13px; color: #475569; font-weight: 500; }
            .ofast-checkbox-pill:hover { background: #e2e8f0; }
            .ofast-checkbox-pill input:checked + span { color: #6366f1; }
            .ofast-checkbox-pill:has(input:checked) { background: rgba(99, 102, 241, 0.1); border-color: rgba(99, 102, 241, 0.2); }

            /* Buttons */
            .ofast-btn-primary { display: inline-flex; align-items: center; gap: 10px; padding: 12px 24px; background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); color: #fff; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3); }
            .ofast-btn-primary:hover { background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4); color: #fff; }

            /* Admin URL Settings Inline */
            #admin-url-settings h3 { margin: 0 0 15px 0; font-size: 16px; color: #1e293b; }
            #admin-url-settings .form-field { margin-bottom: 15px; }
            #admin-url-settings label { display: block; font-weight: 600; color: #374151; margin-bottom: 5px; }
            #admin-url-settings input[type="text"], #admin-url-settings input[type="number"], #admin-url-settings textarea { width: 100%; max-width: 400px; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; }
            #admin-url-settings .description { color: #64748b; font-size: 12px; margin-top: 5px; }

            /* RESPONSIVE DESIGN */
            @media screen and (max-width: 960px) {
                .ofast-studio-wrapper { flex-direction: column; gap: 20px; }
                
                /* Sidebar becomes horizontal scroll menu */
                .ofast-studio-sidebar { 
                    width: 100%; 
                    display: flex; 
                    overflow-x: auto; 
                    padding-bottom: 5px; 
                    position: static;
                    gap: 10px;
                    -webkit-overflow-scrolling: touch;
                }
                
                /* Tabs in scroll menu */
                .ofast-studio-tab { 
                    flex-shrink: 0; 
                    margin-bottom: 0; 
                    white-space: nowrap; 
                    padding: 10px 15px;
                    font-size: 13px;
                }
                
                /* Move Save Button to be inline or floating? 
                   For now, let's keep it at the end of the scroll list or hide it in sidebar and use a global one.
                   Actually, let's just make it full width at the bottom of the content or keep it in sidebar but styled differently.
                */
                .ofast-studio-sidebar div:last-child {
                    /* Container for save button in sidebar */
                    margin-top: 0 !important;
                    display: none; /* Hide sidebar save button on mobile, assume user knows to scroll or we duplicate it */
                }

                /* We might need a floating save button or show it at bottom of content */
                .ofast-form-actions { text-align: center; } /* content save button */
            }
            
            /* Add Save Button to bottom of content area for Mobile */
            @media screen and (min-width: 961px) {
                .ofast-form-actions.mobile-only { display: none; }
            }
            @media screen and (max-width: 782px) {
                 .ofast-tweak-row { flex-direction: column; align-items: flex-start; gap: 10px; }
                 .ofast-tweak-action { align-self: flex-end; }
                 .ofast-card-header { flex-direction: column; align-items: flex-start; gap: 15px; }
                 .ofast-search-box { width: 100%; }
                 .ofast-search-box input, .ofast-search-box input:focus { width: 100% !important; }
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            
            // Tab Switching
            $('.ofast-studio-tab').on('click', function() {
                // Ignore click if searching
                if ($('.ofast-studio-wrapper').hasClass('searching')) return;
                
                var target = $(this).data('target');
                
                // Update Sidebar
                $('.ofast-studio-tab').removeClass('active');
                $(this).addClass('active');
                
                // Update Content
                $('.ofast-tab-content').removeClass('active');
                $('#' + target).addClass('active');
                
                // Save active tab to localStorage (optional)
                localStorage.setItem('ofast_admin_tweaks_tab', target);
            });
            
            // Restore active tab
            var savedTab = localStorage.getItem('ofast_admin_tweaks_tab');
            if (savedTab && $('#' + savedTab).length > 0) {
                 $('.ofast-studio-tab[data-target="' + savedTab + '"]').click();
            }

            // Toggle Admin URL settings visibility
            $('#ofast_enable_admin_url').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#admin-url-settings').slideDown(300);
                } else {
                    $('#admin-url-settings').slideUp(300);
                }
            });
            
            // Toggle Admin Design settings visibility
            $('#ofast_enable_admin_design').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#admin-design-settings').slideDown(300);
                } else {
                    $('#admin-design-settings').slideUp(300);
                }
            });

            // Search functionality
            $('#ofast-tweaks-search').on('input', function() {
                var searchTerm = $(this).val().toLowerCase().trim();
                var wrapper = $('.ofast-studio-wrapper');
                
                if (searchTerm === '') {
                    // CLEAR SEARCH
                    wrapper.removeClass('searching');
                    $('.ofast-tweak-row, .ofast-section-title, .ofast-card, .ofast-tab-content').removeClass('hidden-by-search');
                    // Restore active tab
                    $('.ofast-studio-tab.active').click(); 
                    return;
                }
                
                // ACTIVE SEARCH MODE
                wrapper.addClass('searching');
                
                // Search through all cards and rows
                $('.ofast-card').each(function() {
                    var card = $(this);
                    
                    card.find('.ofast-tweak-row').each(function() {
                        var labelText = $(this).find('label').first().text().toLowerCase();
                        var descText = $(this).find('.description').text().toLowerCase();
                        
                        if (labelText.includes(searchTerm) || descText.includes(searchTerm)) {
                            $(this).removeClass('hidden-by-search');
                        } else {
                            $(this).addClass('hidden-by-search');
                        }
                    });
                    
                    // Hide section titles if needed
                    card.find('.ofast-section-title').each(function() {
                        var $nextRows = $(this).nextUntil('.ofast-section-title, .ofast-card-header');
                        var visibleRows = $nextRows.filter('.ofast-tweak-row:not(.hidden-by-search)').length;
                        $(this).toggleClass('hidden-by-search', visibleRows === 0);
                    });
                    
                    // Hide card if no visible rows
                    var visibleRowsInCard = card.find('.ofast-tweak-row:not(.hidden-by-search)').length;
                    card.toggleClass('hidden-by-search', visibleRowsInCard === 0);
                });
                
                // Hide tabs if no visible cards
                $('.ofast-tab-content').each(function() {
                    var visibleCards = $(this).find('.ofast-card:not(.hidden-by-search)').length;
                    $(this).toggleClass('hidden-by-search', visibleCards === 0);
                });
            });
        });
        
        // Collapsible toggle function
        function toggleCollapsible(contentId) {
            var content = document.getElementById(contentId);
            var arrow = document.getElementById(contentId + '-arrow');
            if (content.style.display === 'none') {
                content.style.display = 'block';
                arrow.style.transform = 'rotate(0deg)';
            } else {
                content.style.display = 'none';
                arrow.style.transform = 'rotate(-90deg)';
            }
        }
        </script>
<?php
    }

    /**
     * Render Admin URL settings inline
     */
    private function render_admin_url_settings()
    {
        $custom_slug = get_option('ofast_admin_custom_slug', '');
        $emergency_key = get_option('ofast_admin_emergency_key', '');
        $site_url = home_url();
        $max_attempts = get_option('ofast_security_max_attempts', 5);
        $lockout_duration = get_option('ofast_security_lockout_duration', 15);
        $ip_whitelist = get_option('ofast_security_ip_whitelist', '');
?>
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 15px;">
            <p style="margin: 0; color: #64748b; font-size: 13px;">
                <strong>Note:</strong> When enabled, <code>/wp-admin</code> and <code>/wp-login.php</code> will return 404. Remember your custom URL.
            </p>
        </div>

        <div class="form-field">
            <label>Custom Login URL</label>
            <div style="display: flex; align-items: center; gap: 0;">
                <span style="background: #f1f5f9; padding: 10px; border: 1px solid #e2e8f0; border-right: none; border-radius: 8px 0 0 8px; font-family: monospace; color: #64748b; font-size: 13px;"><?php echo esc_html($site_url); ?>/</span>
                <input type="text" name="ofast_admin_url_slug" value="<?php echo esc_attr($custom_slug); ?>" placeholder="my-secret-login" pattern="[a-z0-9\-]+" style="border-radius: 0 8px 8px 0 !important; max-width: 200px;">
            </div>
            <p class="description">Lowercase letters, numbers, and hyphens only. Leave empty to disable.</p>
        </div>

        <?php if (!empty($custom_slug) && !empty($emergency_key)): ?>
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; margin: 15px 0;">
            <p style="margin: 0 0 10px 0; font-weight: 600; color: #374151;">Protection Active</p>
            <p style="margin: 0; font-size: 13px; color: #374151;">
                <strong>Login URL:</strong> <code id="login-url-display"><?php echo esc_html(trailingslashit($site_url) . $custom_slug); ?></code>
                <button type="button" class="button button-small" style="margin-left: 8px;" onclick="copyToClipboard('login-url-display', this)">Copy</button>
            </p>
        </div>

        <!-- Recovery Options Section -->
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; margin: 15px 0;">
            <h4 style="margin: 0 0 12px 0; font-size: 14px; color: #374151;">
                Recovery Options
            </h4>
            
            <div style="margin-bottom: 12px;">
                <p style="margin: 0 0 5px 0; font-size: 12px; font-weight: 600; color: #374151;">Emergency Bypass URL (One-Time Use):</p>
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <code id="emergency-url-display" style="font-size: 10px; background: #fff; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 4px; word-break: break-all; max-width: 100%;"><?php echo esc_html(wp_login_url() . '?ofast_emergency=' . $emergency_key); ?></code>
                    <button type="button" class="button button-small" onclick="copyToClipboard('emergency-url-display', this)">Copy</button>
                </div>
                <p style="margin: 5px 0 0; font-size: 11px; color: #64748b;">This key rotates after each use. A new one will be emailed to you.</p>
            </div>

            <div style="margin-bottom: 12px;">
                <p style="margin: 0 0 5px 0; font-size: 12px; font-weight: 600; color: #374151;">Permanent Bypass (wp-config.php):</p>
                <code id="wpconfig-bypass" style="font-size: 11px; background: #fff; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 4px; display: block;">define('OFAST_DISABLE_ADMIN_PROTECTION', true);</code>
                <p style="margin: 5px 0 0; font-size: 11px; color: #64748b;">Add this line to wp-config.php if you're locked out.</p>
            </div>

            <div style="margin-top: 15px; padding-top: 12px; border-top: 1px solid #e2e8f0;">
                <button type="button" class="button" onclick="ofastResendLoginDetails()" id="resend-login-btn">
                    Resend Login Details to Admin Email
                </button>
                <span id="resend-status" style="margin-left: 10px; font-size: 12px;"></span>
            </div>
        </div>

        <script>
        function copyToClipboard(elementId, button) {
            var text = document.getElementById(elementId).innerText;
            navigator.clipboard.writeText(text).then(function() {
                var originalText = button.innerText;
                button.innerText = 'Copied!';
                button.style.background = '#10b981';
                button.style.color = '#fff';
                setTimeout(function() {
                    button.innerText = originalText;
                    button.style.background = '';
                    button.style.color = '';
                }, 2000);
            });
        }

        function ofastResendLoginDetails() {
            var btn = document.getElementById('resend-login-btn');
            var status = document.getElementById('resend-status');
            btn.disabled = true;
            status.innerHTML = '<span style="color: #6366f1;">Sending...</span>';
            
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ofast_resend_admin_url_email',
                    nonce: '<?php echo wp_create_nonce('ofast_resend_email_nonce'); ?>'
                },
                success: function(response) {
                    btn.disabled = false;
                    if (response.success) {
                        status.innerHTML = '<span style="color: #10b981;">✓ Email sent to <?php echo esc_js(get_option('admin_email')); ?></span>';
                    } else {
                        status.innerHTML = '<span style="color: #ef4444;">Failed: ' + response.data + '</span>';
                    }
                },
                error: function() {
                    btn.disabled = false;
                    status.innerHTML = '<span style="color: #ef4444;">Connection error</span>';
                }
            });
        }
        </script>
        <?php endif; ?>

        <h4 style="margin: 20px 0 10px 0; font-size: 14px; color: #374151;">Login Limit Settings</h4>
        
        <div class="form-field" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; max-width: 400px;">
            <div>
                <label>Max Failed Attempts</label>
                <input type="number" name="ofast_max_attempts" value="<?php echo esc_attr($max_attempts); ?>" min="1" max="20" style="width: 100%;">
            </div>
            <div>
                <label>Lockout Duration (min)</label>
                <input type="number" name="ofast_lockout_duration" value="<?php echo esc_attr($lockout_duration); ?>" min="1" max="1440" style="width: 100%;">
            </div>
        </div>

        <div class="form-field">
            <label>IP Whitelist</label>
            <textarea name="ofast_ip_whitelist" rows="3" placeholder="One IP per line" style="max-width: 400px;"><?php echo esc_textarea($ip_whitelist); ?></textarea>
            <p class="description">IPs that bypass login limits (one per line).</p>
        </div>
<?php
    }

    /**
     * Render Admin Design CSS editor inline
     */
    private function render_admin_design_editor()
    {
        // Get saved custom CSS or load default
        $custom_css = get_option('ofast_admin_design_css', '');
        
        // If no custom CSS saved yet, load default from file
        if (empty($custom_css)) {
            $default_css_file = OFAST_X_PLUGIN_DIR . 'modules/admin-design/assets/admin-design.css';
            if (file_exists($default_css_file)) {
                $custom_css = file_get_contents($default_css_file);
            }
        }
?>
        <p class="description" style="margin-bottom: 15px;">
            Customize the WordPress admin styling. Edit the CSS below to change colors, animations, and effects.
        </p>

        <div class="form-field">
            <textarea 
                name="ofast_admin_design_css" 
                id="ofast_admin_design_css"
                rows="15" 
                style="width: 100%; font-family: 'Consolas', 'Monaco', monospace; font-size: 13px; line-height: 1.5; padding: 15px; background: #1e293b; color: #e2e8f0; border: 1px solid #475569; border-radius: 8px; resize: vertical;"
                placeholder="/* Your custom admin CSS here */"
            ><?php echo esc_textarea($custom_css); ?></textarea>
            <p class="description" style="margin-top: 10px;">
                <strong>Tip:</strong> The CSS is applied to all WordPress admin pages when this module is enabled.
            </p>
        </div>

        <div style="margin-top: 15px;">
            <button type="button" class="button" onclick="ofastResetDefaultCSS()">Reset to Default</button>
        </div>

        <script>
        function ofastResetDefaultCSS() {
            if (confirm('Reset CSS to default? Your customizations will be lost.')) {
                jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ofast_get_default_admin_css',
                        nonce: '<?php echo wp_create_nonce('ofast_admin_css_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            jQuery('#ofast_admin_design_css').val(response.data.css);
                        }
                    }
                });
            }
        }
        </script>
<?php
    } // This closing brace belongs to render_admin_design_editor()

    /**
     * CONTENT DUPLICATOR METHODS
     * =========================================
     */

    /**
     * Add duplicate link to row actions
     */
    public function add_duplicate_link($actions, $post)
    {
        if (!current_user_can('edit_posts')) return $actions;

        $allowed_types = array('post', 'page', 'product');
        if (!in_array($post->post_type, $allowed_types)) return $actions;

        $url = wp_nonce_url(
            admin_url('admin.php?action=ofast_duplicate_post&post=' . $post->ID),
            'ofast_duplicate_' . $post->ID,
            'ofast_nonce'
        );

        $actions['duplicate'] = sprintf(
            '<a href="%s" title="%s" style="color: #6366f1;">%s</a>',
            esc_url($url),
            esc_attr__('Duplicate this item'),
            'Duplicate'
        );

        return $actions;
    }

    /**
     * Specific handler for WooCommerce product row actions filter if needed
     * acts as alias for add_duplicate_link but checks post type inside
     */
    public function add_product_duplicate_link($actions, $post) {
         if ($post->post_type === 'product') {
             return $this->add_duplicate_link($actions, $post);
         }
         return $actions;
    }

    /**
     * Duplicate post handler
     */
    public function duplicate_post()
    {
        if (!isset($_GET['post']) || !isset($_GET['ofast_nonce'])) wp_die('Invalid request');
        $post_id = intval($_GET['post']);
        if (!wp_verify_nonce($_GET['ofast_nonce'], 'ofast_duplicate_' . $post_id)) wp_die('Security check failed');
        if (!current_user_can('edit_posts')) wp_die('Permission denied');

        $post = get_post($post_id);
        if (!$post) wp_die('Post not found');

        $new_post = array(
            'post_title'     => $post->post_title . ' (Copy)',
            'post_content'   => $post->post_content,
            'post_excerpt'   => $post->post_excerpt,
            'post_status'    => 'draft',
            'post_type'      => $post->post_type,
            'post_author'    => get_current_user_id(),
            'post_parent'    => $post->post_parent,
            'menu_order'     => $post->menu_order,
            'comment_status' => $post->comment_status,
            'ping_status'    => $post->ping_status,
            'post_password'  => $post->post_password,
        );

        $new_post_id = wp_insert_post($new_post);
        if (is_wp_error($new_post_id)) wp_die('Failed to duplicate: ' . $new_post_id->get_error_message());

        $this->duplicate_post_meta($post_id, $new_post_id);
        $this->duplicate_taxonomies($post_id, $new_post_id, $post->post_type);

        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) set_post_thumbnail($new_post_id, $thumbnail_id);

        $redirect_url = add_query_arg(
            array('post_type' => $post->post_type, 'ofast_duplicated' => 1, 'new_post' => $new_post_id),
            admin_url('edit.php')
        );
        wp_safe_redirect($redirect_url);
        exit;
    }

    private function duplicate_post_meta($original_id, $new_id)
    {
        global $wpdb;
        $post_meta = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $original_id));
        if (empty($post_meta)) return;

        $skip = array('_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date');
        foreach ($post_meta as $meta) {
            if (in_array($meta->meta_key, $skip)) continue;
            if (strpos($meta->meta_key, '_wp_attached') === 0) continue;
            add_post_meta($new_id, $meta->meta_key, maybe_unserialize($meta->meta_value));
        }
    }

    private function duplicate_taxonomies($original_id, $new_id, $post_type)
    {
        $taxonomies = get_object_taxonomies($post_type);
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_object_terms($original_id, $taxonomy, array('fields' => 'ids'));
            if (!empty($terms) && !is_wp_error($terms)) {
                wp_set_object_terms($new_id, $terms, $taxonomy);
            }
        }
    }

    public function show_duplicate_notice()
    {
        if (!isset($_GET['ofast_duplicated']) || $_GET['ofast_duplicated'] != 1) return;
        $new_post_id = isset($_GET['new_post']) ? intval($_GET['new_post']) : 0;
        $edit_link = $new_post_id ? get_edit_post_link($new_post_id) : '';
        
        $message = 'Content duplicated successfully!';
        if ($edit_link) $message .= ' <a href="' . esc_url($edit_link) . '" style="color:#fff;text-decoration:underline;">Edit the duplicate</a>';
        
        if (class_exists('Ofast_X_Toast')) {
            echo Ofast_X_Toast::render($message, 'success');
        } else {
            echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
        }
    }
}
