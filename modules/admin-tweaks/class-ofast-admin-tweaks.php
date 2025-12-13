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
        $hash = substr(md5('ofast_author_' . $author_id . wp_salt()), 0, 12);
        return home_url('/author/' . $hash . '/');
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
                // Find the matching author
                $users = get_users(array('fields' => array('ID')));
                foreach ($users as $user) {
                    $hash = substr(md5('ofast_author_' . $user->ID . wp_salt()), 0, 12);
                    if ($hash === $author_name) {
                        $query_vars['author'] = $user->ID;
                        unset($query_vars['author_name']);
                        break;
                    }
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

        add_action('admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p>Admin tweaks settings saved!</p></div>';
        });
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
?>
        <div class="wrap">
            <h1>Admin Tweaks</h1>
            <p>Quick admin customizations to make WordPress work your way.</p>

            <form method="post">
                <?php wp_nonce_field('ofast_admin_tweaks_save', 'admin_tweaks_nonce'); ?>

                <h2 class="title">Admin Interface</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Post/Page ID Column</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ofast_show_post_id" value="1" <?php checked(!empty($settings['show_post_id'])); ?>>
                                Show ID column in Posts and Pages list tables
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Infinity Media Scroll</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ofast_infinity_media" value="1" <?php checked(!empty($settings['infinity_media'])); ?>>
                                Enable infinite scroll in Media Library
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Hide Admin Bar</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ofast_hide_admin_bar" value="1" <?php checked(!empty($settings['hide_admin_bar'])); ?>>
                                Hide admin bar on frontend for all users
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Hide Admin Bar by Role</th>
                        <td>
                            <?php foreach ($roles as $role_slug => $role_data): ?>
                                <label style="display: inline-block; margin-right: 15px; margin-bottom: 5px;">
                                    <input type="checkbox" name="ofast_hide_bar_roles[]" value="<?php echo esc_attr($role_slug); ?>"
                                        <?php checked(in_array($role_slug, $settings['hide_admin_bar_roles'] ?? array())); ?>>
                                    <?php echo esc_html(translate_user_role($role_data['name'])); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">Select roles that should not see the admin bar on frontend.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Remove WordPress Logo</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ofast_remove_wp_logo" value="1" <?php checked(!empty($settings['remove_wp_logo'])); ?>>
                                Remove WordPress logo from admin bar
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Remove +New Menu</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ofast_remove_new_content" value="1" <?php checked(!empty($settings['remove_new_content'])); ?>>
                                Remove the "+New" content menu from admin bar
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Rename "Howdy" to "Hello"</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ofast_rename_howdy" value="1" <?php checked(!empty($settings['rename_howdy'])); ?>>
                                Change "Howdy, Username" to "Hello, Username" in admin bar
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Hide Howdy Greeting</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ofast_hide_howdy" value="1" <?php checked(!empty($settings['hide_howdy'])); ?>>
                                Completely remove the "Howdy" greeting (shows only username)
                            </label>
                        </td>
                    </tr>
                </table>

                <h2 class="title" style="margin-top:30px;">Security Hardening</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Disable XML-RPC</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ofast_disable_xmlrpc" value="1" <?php checked(!empty($settings['disable_xmlrpc'])); ?>>
                                Completely disable XML-RPC functionality
                            </label>
                            <p class="description">Prevents brute force attacks and DDoS amplification via xmlrpc.php. Safe to enable unless you use mobile apps or Jetpack.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Obfuscate Author Slugs</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ofast_obfuscate_author_slugs" value="1" <?php checked(!empty($settings['obfuscate_author_slugs'])); ?>>
                                Hide real usernames from author URLs
                            </label>
                            <p class="description">Replaces usernames in author archive URLs with random hashes. Blocks ?author=N enumeration attacks.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Email Address Obfuscator</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ofast_obfuscate_emails" value="1" <?php checked(!empty($settings['obfuscate_emails'])); ?>>
                                Encode email addresses in page content
                            </label>
                            <p class="description">Converts email addresses to HTML entities (e.g., &#101;&#109;&#97;&#105;&#108;) to prevent spam harvesting bots from collecting them.</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="ofast_save_admin_tweaks" class="button button-primary">Save Tweaks</button>
                </p>
            </form>
        </div>
<?php
    }
}
