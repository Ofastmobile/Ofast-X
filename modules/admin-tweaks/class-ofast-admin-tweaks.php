<?php

/**
 * Ofast X - Admin Tweaks Module
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
            add_filter('admin_bar_menu', array($this, 'rename_howdy'), 200);
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

        // Add admin menu page
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'save_settings'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'Admin Tweaks',
            'Admin Tweaks',
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
        if ($screen && in_array($screen->id, array('edit-post', 'edit-page'))) {
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
        );

        update_option('ofast_admin_tweaks', $settings);

        update_option('ofast_admin_tweaks', $settings);

        // Redirect with success flag
        wp_redirect(add_query_arg('settings_saved', '1', wp_get_referer()));
        exit;
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
                        <h1>Admin Tweaks</h1>
                        <p>Quick customization and security hardening for your dashboard</p>
                    </div>
                </div>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('ofast_admin_tweaks_save', 'admin_tweaks_nonce'); ?>
                
                <div class="ofast-tweaks-container">
                        
                    <!-- Interface Tweaks Card -->
                    <div class="ofast-card">
                        <div class="ofast-card-header">
                            <span class="dashicons dashicons-desktop"></span>
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

                            <!-- Admin Bar Section -->
                            <h3 class="ofast-section-title">Admin Bar</h3>
                            
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

                    <!-- Security Hardening Card -->
                    <div class="ofast-card" style="margin-top: 30px;">
                        <div class="ofast-card-header">
                            <span class="dashicons dashicons-shield"></span>
                            <h2>Security Hardening</h2>
                        </div>
                        <div class="ofast-card-body">
                            
                            <div class="ofast-tweak-row">
                                <div class="ofast-tweak-content">
                                    <label for="ofast_disable_xmlrpc">Disable XML-RPC</label>
                                    <p class="description">Protects against brute force and DDoS attacks. Enable unless you use the WP App or Jetpack.</p>
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
                                    <p class="description">Hides real usernames in URLs (e.g. /author/xyz123/) to prevent enumeration.</p>
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
                                    <p class="description">Encodes email addresses in content to HTML entities to block spambots.</p>
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

                    <!-- Save Button Section -->
                    <div class="ofast-form-actions" style="margin-top: 30px;">
                        <button type="submit" name="ofast_save_admin_tweaks" class="ofast-btn-primary" style="font-size: 16px; padding: 14px 40px;">
                            Save Changes
                        </button>
                    </div>

                </div>
            </form>
        </div>

        <style>
            .ofast-tweaks-wrap { max-width: 1200px; margin: 20px auto; padding: 0 20px; }            
            /* Header */
            .ofast-page-header { background: #ffffff; border-radius: 16px; padding: 30px; margin-bottom: 30px; border: 1px solid #e2e8f0; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
            .ofast-header-content { display: flex; align-items: center; gap: 20px; }
            .ofast-header-icon { width: 60px; height: 60px; background: #ffffff; border-radius: 16px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(102, 126, 234, 0.2); border: 1px solid #e2e8f0; color: #667eea; }
            .ofast-header-icon .dashicons { font-size: 28px; width: 28px; height: 28px; }
            .ofast-header-text h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1e293b; }
            .ofast-header-text p { margin: 5px 0 0; color: #64748b; font-size: 15px; }

            /* Grid Layout - SINGLE COLUMN WIDE */
            .ofast-tweaks-container { display: flex; flex-direction: column; gap: 0; }
            
            /* Cards */
            .ofast-card { background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); overflow: hidden; border: 1px solid rgba(0,0,0,0.05); }
            .ofast-card-header { display: flex; align-items: center; gap: 12px; padding: 20px 25px; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-bottom: 1px solid #e2e8f0; }
            .ofast-card-header .dashicons { font-size: 20px; width: 20px; height: 20px; color: #667eea; }
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
            input:checked + .ofast-slider { background-color: #667eea; }
            input:checked + .ofast-slider:before { transform: translateX(22px); }
            input:focus + .ofast-slider { box-shadow: 0 0 1px #667eea; }

            /* Checkbox Pills */
            .ofast-checkbox-pill { display: inline-flex; align-items: center; padding: 6px 12px; background: #f1f5f9; border-radius: 20px; cursor: pointer; transition: all 0.2s; border: 1px solid transparent; }
            .ofast-checkbox-pill input { display: none; }
            .ofast-checkbox-pill span { font-size: 13px; color: #475569; font-weight: 500; }
            .ofast-checkbox-pill:hover { background: #e2e8f0; }
            .ofast-checkbox-pill input:checked + span { color: #667eea; }
            .ofast-checkbox-pill:has(input:checked) { background: rgba(102, 126, 234, 0.1); border-color: rgba(102, 126, 234, 0.2); }

            /* Buttons */
            .ofast-btn-primary { display: inline-flex; align-items: center; gap: 10px; padding: 12px 24px; background: #667eea; color: #fff; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 15px rgba(102, 126, 234, 0.35); }
            .ofast-btn-primary:hover { background: #5a6fd6; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(102, 126, 234, 0.45); color: #fff; }
        </style>
<?php
    }
}
