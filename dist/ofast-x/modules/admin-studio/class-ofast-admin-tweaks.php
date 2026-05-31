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

        // Post/Page/Product ID Column
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

        // Replace the default admin-bar greeting with a custom label.
        if (!empty($settings['custom_greeting_enabled']) || !empty($settings['rename_howdy'])) {
            add_filter('admin_bar_menu', array($this, 'customize_admin_greeting'), PHP_INT_MAX);
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
            
            add_action('admin_action_ofast_duplicate_post', array($this, 'duplicate_post'));
            add_action('admin_notices', array($this, 'show_duplicate_notice'));
        }

        // Add admin menu page
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'save_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // AJAX handler for reset CSS
        add_action('wp_ajax_ofast_get_default_admin_css', array($this, 'ajax_get_default_css'));
    }

    /**
     * Enqueue Admin Studio assets.
     */
    public function enqueue_assets($hook)
    {
        if (strpos($hook, 'ofast-admin-tweaks') === false) {
            return;
        }

        wp_enqueue_style(
            'ofast-admin-tweaks',
            plugins_url('assets/css/admin-tweaks.css', __FILE__),
            array(),
            OFAST_X_VERSION
        );

        wp_enqueue_script(
            'ofast-admin-tweaks',
            plugins_url('assets/js/admin-tweaks.js', __FILE__),
            array('jquery'),
            OFAST_X_VERSION,
            true
        );
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
     * Replace the default "Howdy" label with a custom greeting.
     */
    public function customize_admin_greeting($wp_admin_bar)
    {
        $my_account = $wp_admin_bar->get_node('my-account');
        if ($my_account) {
            $settings = get_option('ofast_admin_tweaks', array());
            $custom_greeting = '';

            if (!empty($settings['custom_greeting_enabled'])) {
                $custom_greeting = sanitize_text_field($settings['custom_greeting_text'] ?? '');
            } elseif (!empty($settings['rename_howdy'])) {
                // Backward compatibility for existing installs that enabled the old Hello toggle.
                $custom_greeting = 'Hello';
            }

            $custom_greeting = trim((string) $custom_greeting);
            if ($custom_greeting === '') {
                return;
            }

            $custom_greeting = rtrim($custom_greeting, " ,");

            // Keep the username/avatar portion and replace only the greeting prefix.
            if (preg_match('/(<span[^>]*class=["\'][^"\']*display-name[^"\']*["\'][^>]*>.*)$/i', $my_account->title, $matches)) {
                $newtitle = esc_html($custom_greeting . ', ') . $matches[1];
            } else {
                $newtitle = preg_replace('/^\s*[^<]*?,\s*/u', esc_html($custom_greeting . ', '), $my_account->title, 1);
            }

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
            'rename_howdy' => 0,
            'hide_howdy' => 0,
            'custom_greeting_enabled' => isset($_POST['ofast_custom_greeting_enabled']) ? 1 : 0,
            'custom_greeting_text' => sanitize_text_field(wp_unslash($_POST['ofast_custom_greeting_text'] ?? '')),
            'hide_admin_bar_roles' => isset($_POST['ofast_hide_bar_roles']) ? array_map('sanitize_text_field', $_POST['ofast_hide_bar_roles']) : array(),
            'disable_xmlrpc' => isset($_POST['ofast_disable_xmlrpc']) ? 1 : 0,
            'obfuscate_author_slugs' => isset($_POST['ofast_obfuscate_author_slugs']) ? 1 : 0,
            'obfuscate_emails' => isset($_POST['ofast_obfuscate_emails']) ? 1 : 0,
            'show_registration_date' => isset($_POST['ofast_show_registration_date']) ? 1 : 0,
            // Admin Modules
            'enable_user_roles' => isset($_POST['ofast_enable_user_roles']) ? 1 : 0,
            'enable_menu_editor' => isset($_POST['ofast_enable_menu_editor']) ? 1 : 0,
            'enable_content_ordering' => isset($_POST['ofast_enable_content_ordering']) ? 1 : 0,
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

        // Save Admin Design CSS if enabled
        if (!empty($settings['enable_admin_design']) && isset($_POST['ofast_admin_design_css'])) {
            // wp_unslash removes WP's automatic slashes, preventing backslash accumulation
            $custom_css = wp_unslash($_POST['ofast_admin_design_css']);
            $custom_css = Ofast_X_Sanitizer::css($custom_css);
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
                            <span class="dashicons dashicons-admin-plugins"></span> Modules <?php ofast_toolkit_pro_badge(); ?>
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
                                            <label for="ofast_show_post_id">Post/Page/Product ID Column</label>
                                            <p class="description">Adds a handy ID column to your Posts, Pages, and Products lists.</p>
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
                                            <p class="description">Duplicate posts, pages, and WooCommerce products with one click.</p>
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

                                    <?php
                                    $custom_greeting_enabled = !empty($settings['custom_greeting_enabled']) || !empty($settings['rename_howdy']);
                                    $custom_greeting_text = isset($settings['custom_greeting_text']) && $settings['custom_greeting_text'] !== ''
                                        ? $settings['custom_greeting_text']
                                        : (!empty($settings['rename_howdy']) ? 'Hello' : '');
                                    ?>
                                    <div class="ofast-tweak-row">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_custom_greeting_enabled">Custom Greeting</label>
                                            <p class="description">Replace "Howdy" with your own greeting in the admin bar.</p>
                                        </div>
                                        <div class="ofast-tweak-action">
                                            <label class="ofast-toggle">
                                                <input type="checkbox" name="ofast_custom_greeting_enabled" id="ofast_custom_greeting_enabled" value="1" <?php checked($custom_greeting_enabled); ?>>
                                                <span class="ofast-slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="ofast-tweak-row" id="ofast-custom-greeting-row" style="<?php echo $custom_greeting_enabled ? '' : 'display:none;'; ?>">
                                        <div class="ofast-tweak-content">
                                            <label for="ofast_custom_greeting_text">Greeting Text</label>
                                            <p class="description">This will appear as "Your greeting, Name". Example: Welcome, Hi there, Good to see you.</p>
                                        </div>
                                        <div class="ofast-tweak-action">
                                            <input type="text" name="ofast_custom_greeting_text" id="ofast_custom_greeting_text" value="<?php echo esc_attr($custom_greeting_text); ?>" placeholder="Welcome" style="min-width: 220px; max-width: 280px;">
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
                                    <h2>Admin Modules</h2>
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

            </form>
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
