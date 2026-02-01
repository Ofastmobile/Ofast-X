<?php

/**
 * Ofast X - Content Ordering Module
 * Drag-and-drop reordering for posts, pages, and custom post types
 * Uses dedicated reorder pages for reliability
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Content_Ordering
{
    private static $instance = null;
    private $enabled_post_types = array();
    private $module_enabled = false;
    public $post_types = array(); // Fix deprecated property

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Check if enabled via Admin Tweaks toggle
        $admin_tweaks = get_option('ofast_admin_tweaks', array());
        if (empty($admin_tweaks['enable_content_ordering'])) {
            return;
        }

        // Post types to sort
        $this->post_types = apply_filters('ofast_content_ordering_post_types', array('post', 'page', 'product'));
        $this->enabled_post_types = $this->post_types;

        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_menu', array($this, 'add_admin_menus'), 100);
        add_action('wp_ajax_ofast_save_post_order', array($this, 'ajax_save_order'));
        
        // Modify admin query to use menu_order (only add once, not in loop)
        add_action('pre_get_posts', array($this, 'admin_order_query'));
        
        // Modify frontend query to use menu_order
        add_action('pre_get_posts', array($this, 'frontend_order_query'));
    }

    /**
     * Enqueue scripts and styles for reordering
     */
    public function enqueue_scripts($hook)
    {
        // Only enqueue on our reorder pages
        if (strpos($hook, 'ofast-reorder') === false) {
            return;
        }

        // Enqueue jQuery UI Sortable
        wp_enqueue_script('jquery-ui-sortable');
        
        // Enqueue external CSS
        wp_enqueue_style(
            'ofast-content-ordering',
            OFAST_X_PLUGIN_URL . 'assets/css/content-ordering.css',
            array(),
            OFAST_X_VERSION
        );
        
        // Enqueue external JS
        wp_enqueue_script(
            'ofast-content-ordering',
            OFAST_X_PLUGIN_URL . 'assets/js/content-ordering.js',
            array('jquery', 'jquery-ui-sortable'),
            OFAST_X_VERSION,
            true
        );
        
        // Pass data to JS using wp_localize_script (WordPress standard)
        wp_localize_script('ofast-content-ordering', 'ofastOrdering', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ofast_ordering_nonce'),
            'i18n' => array(
                'saving' => __('Saving...', 'ofast-x'),
                'saved' => __('Order saved!', 'ofast-x'),
                'error' => __('Error: ', 'ofast-x'),
                'failed' => __('Failed', 'ofast-x'),
                'connectionError' => __('Connection error', 'ofast-x'),
            )
        ));
    }

    /**
     * Modify admin posts list to use menu_order
     */
    public function admin_order_query($query)
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        // Don't interfere with orderby if explicitly set
        if (isset($_GET['orderby']) && $_GET['orderby'] !== 'menu_order') {
            return;
        }

        global $pagenow, $typenow;

        // Only on edit.php (post list)
        if ($pagenow !== 'edit.php') {
            return;
        }

        // Get current post type
        $post_type = $typenow ?: 'post';

        // Check if this post type has ordering enabled
        if (!in_array($post_type, $this->enabled_post_types)) {
            return;
        }

        // Apply menu_order ordering
        $query->set('orderby', 'menu_order');
        $query->set('order', 'ASC');
    }

    /**
     * Add ordering support to enabled post types
     */
    public function add_ordering_support()
    {
        foreach ($this->enabled_post_types as $post_type) {
            add_post_type_support($post_type, 'page-attributes');
        }
    }

    /**
     * Add admin menus
     */
    public function add_admin_menus()
    {
        // Main settings page under Ofast X
        add_submenu_page(
            'ofast-x',
            'Content Ordering',
            'Content Ordering',
            'manage_options',
            'ofast-content-ordering',
            array($this, 'render_settings_page')
        );

        // Add submenu under each enabled post type
        foreach ($this->enabled_post_types as $post_type) {
            $pt_obj = get_post_type_object($post_type);
            if (!$pt_obj) continue;

            $parent_slug = 'edit.php';
            if ($post_type !== 'post') {
                $parent_slug = 'edit.php?post_type=' . $post_type;
            }

            add_submenu_page(
                $parent_slug,
                'Reorder ' . $pt_obj->labels->name,
                __('Reorder', 'ofast-x'),
                'edit_others_posts',
                'ofast-reorder-' . $post_type,
                array($this, 'render_reorder_page')
            );
        }
    }

    /**
     * Render reorder page for a specific post type
     */
    public function render_reorder_page()
    {
        // Security: Require edit_others_posts capability
        if (!current_user_can('edit_others_posts')) {
            wp_die(__('Permission denied. You need edit_others_posts capability.', 'ofast-x'));
        }

        // Get post type from page slug
        $screen = get_current_screen();
        $post_type = str_replace('_page_ofast-reorder-', '', $screen->id);

        // Handle different screen ID formats
        if (strpos($screen->id, 'ofast-reorder-') !== false) {
            preg_match('/ofast-reorder-([a-z_-]+)$/', $screen->id, $matches);
            $post_type = isset($matches[1]) ? sanitize_key($matches[1]) : 'post';
        }

        // Security: Validate post type is in enabled list (prevent URL manipulation)
        if (!in_array($post_type, $this->enabled_post_types, true)) {
            wp_die(__('This post type is not enabled for reordering.', 'ofast-x'));
        }

        $pt_obj = get_post_type_object($post_type);
        if (!$pt_obj) {
            echo '<div class="wrap"><h1>' . esc_html__('Invalid post type', 'ofast-x') . '</h1></div>';
            return;
        }

        // Get items ordered by menu_order (limit to 500 for performance)
        $items = get_posts(array(
            'post_type' => $post_type,
            'posts_per_page' => 500,
            'orderby' => 'menu_order',
            'order' => 'ASC',
            'post_status' => array('publish', 'pending', 'draft', 'private')
        ));
        
        // Check if there are more posts than we can display
        $total_count = wp_count_posts($post_type);
        $total_posts = array_sum(array_map('intval', array_filter((array) $total_count, function($status) {
            return in_array($status, array('publish', 'pending', 'draft', 'private'));
        }, ARRAY_FILTER_USE_KEY)));
?>
        <div class="wrap">
            <h1><?php echo esc_html(sprintf(__('Reorder %s', 'ofast-x'), $pt_obj->labels->name)); ?></h1>
            <p><?php esc_html_e('Drag and drop items to reorder. Changes are saved automatically.', 'ofast-x'); ?></p>

            <?php if ($total_posts > 500): ?>
                <div class="notice notice-warning">
                    <p><?php echo esc_html(sprintf(__('Showing first 500 of %d items. Reorder in batches for better performance.', 'ofast-x'), $total_posts)); ?></p>
                </div>
            <?php endif; ?>

            <div id="ofast-order-status" style="display:none;padding:10px 15px;margin:10px 0;border-radius:4px;"></div>

            <?php if (empty($items)): ?>
                <?php 
                /* translators: %s: post type name */
                echo Ofast_X_Toast::render(sprintf(__('No %s found.', 'ofast-x'), esc_html(strtolower($pt_obj->labels->name))), 'info'); 
                ?>
            <?php else: ?>
                <div id="ofast-sortable-items" data-post-type="<?php echo esc_attr($post_type); ?>">
                    <?php foreach ($items as $index => $item): ?>
                        <div class="ofast-sortable-item" data-id="<?php echo esc_attr($item->ID); ?>">
                            <span class="ofast-drag-handle">⋮⋮</span>
                            <span class="ofast-item-order"><?php echo esc_html($index + 1); ?></span>
                            <span class="ofast-item-title">
                                <?php echo esc_html($item->post_title ?: __('(no title)', 'ofast-x')); ?>
                            </span>
                            <span class="ofast-item-status ofast-status-<?php echo esc_attr($item->post_status); ?>">
                                <?php echo esc_html(ucfirst($item->post_status)); ?>
                            </span>
                            <a href="<?php echo esc_url(get_edit_post_link($item->ID)); ?>" class="ofast-item-edit" target="_blank"><?php esc_html_e('Edit', 'ofast-x'); ?></a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <p style="margin-top:20px;">
                    <strong>Total:</strong> <?php echo count($items); ?> items
                </p>
            <?php endif; ?>
        </div>
    <?php
    }

    /**
     * AJAX handler for saving order
     */
    public function ajax_save_order()
    {
        // Security: Require edit_others_posts capability (not just edit_posts)
        if (!current_user_can('edit_others_posts')) {
            wp_send_json_error(__('Permission denied. You need edit_others_posts capability.', 'ofast-x'));
        }

        // Verify nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'ofast_ordering_nonce')) {
            wp_send_json_error(__('Security check failed.', 'ofast-x'));
        }

        // Sanitize and validate post type
        $post_type = isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : '';
        
        if (empty($post_type)) {
            wp_send_json_error(__('Post type not specified.', 'ofast-x'));
        }
        
        // Validate post type is in enabled list (prevent manipulation)
        if (!in_array($post_type, $this->enabled_post_types, true)) {
            wp_send_json_error(__('Invalid post type.', 'ofast-x'));
        }

        // Sanitize order array
        $order = isset($_POST['order']) ? array_map('absint', wp_unslash($_POST['order'])) : array();
        
        if (empty($order)) {
            wp_send_json_error(__('No items to reorder.', 'ofast-x'));
        }

        // SECURITY: Transient-based lock to prevent race conditions
        $lock_key = 'ofast_ordering_lock_' . $post_type;
        $lock_timeout = 30; // seconds
        
        // Check if another save is in progress
        if (get_transient($lock_key)) {
            wp_send_json_error(__('Another reorder operation is in progress. Please wait and try again.', 'ofast-x'));
        }
        
        // Set lock
        set_transient($lock_key, time(), $lock_timeout);

        $updated = 0;

        foreach ($order as $index => $post_id) {
            // Verify each post belongs to the declared post type
            $post = get_post($post_id);
            
            if (!$post || $post->post_type !== $post_type) {
                continue; // Skip invalid posts
            }
            
            // Use wp_update_post instead of direct $wpdb for proper hook execution
            $result = wp_update_post(array(
                'ID' => $post_id,
                'menu_order' => (int) $index
            ));
            
            if ($result && !is_wp_error($result)) {
                $updated++;
            }
        }

        // Release lock
        delete_transient($lock_key);

        wp_send_json_success(array(
            'message' => __('Order saved!', 'ofast-x'),
            'count' => $updated
        ));
    }

    /**
     * Modify frontend query to use menu_order
     */
    public function frontend_order_query($query)
    {
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        // Check if ordering should apply to frontend
        if (!get_option('ofast_ordering_frontend', false)) {
            return;
        }

        $post_type = $query->get('post_type');

        if (empty($post_type)) {
            $post_type = 'post';
        }

        if (is_array($post_type)) {
            $post_type = reset($post_type);
        }

        if (!in_array($post_type, $this->enabled_post_types)) {
            return;
        }

        // Only on archive/category pages
        if ($query->is_archive() || $query->is_home()) {
            $query->set('orderby', 'menu_order');
            $query->set('order', 'ASC');
        }
    }

    /**
     * Render settings page
     */
    public function render_settings_page()
    {
        // Security check
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'ofast-x'));
        }

        // Handle save with referer check
        if (isset($_POST['ofast_save_ordering'])) {
            check_admin_referer('ofast_ordering_settings', 'ordering_nonce');
            
            $enabled_types = isset($_POST['enabled_post_types']) 
                ? array_map('sanitize_key', wp_unslash($_POST['enabled_post_types'])) 
                : array();
            $frontend_enabled = isset($_POST['frontend_enabled']);

            update_option('ofast_ordering_post_types', $enabled_types, false);
            update_option('ofast_ordering_frontend', $frontend_enabled, false);

            $this->enabled_post_types = $enabled_types;

            echo Ofast_X_Toast::render(__('Settings saved! Please refresh the page to see the new Reorder submenus.', 'ofast-x'), 'success');
        }

        // Get all public post types
        $post_types = get_post_types(array('public' => true), 'objects');
        $enabled = $this->enabled_post_types;
        $frontend_enabled = get_option('ofast_ordering_frontend', false);
    ?>
        <div class="wrap">
            <h1><?php esc_html_e('Content Ordering', 'ofast-x'); ?></h1>
            <p><?php esc_html_e('Enable drag-and-drop reordering for your content types.', 'ofast-x'); ?></p>

            <form method="post">
                <?php wp_nonce_field('ofast_ordering_settings', 'ordering_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Ordering For', 'ofast-x'); ?></th>
                        <td>
                            <?php foreach ($post_types as $pt): ?>
                                <?php if ($pt->name === 'attachment') continue; ?>
                                <label style="display:block;margin-bottom:8px;">
                                    <input type="checkbox" name="enabled_post_types[]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $enabled, true)); ?>>
                                    <?php echo esc_html($pt->label); ?> <code>(<?php echo esc_html($pt->name); ?>)</code>
                                </label>
                            <?php endforeach; ?>
                            <p class="description"><?php esc_html_e('Select which content types should have the Reorder submenu.', 'ofast-x'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Frontend Ordering', 'ofast-x'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="frontend_enabled" value="1" <?php checked($frontend_enabled); ?>>
                                <?php esc_html_e('Apply custom order on frontend (archive pages)', 'ofast-x'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('When enabled, archive pages will display content in your custom order instead of by date.', 'ofast-x'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Reorder Links', 'ofast-x'); ?></h2>
                <p><?php esc_html_e('After enabling, find the "Reorder" submenu under each content type:', 'ofast-x'); ?></p>
                <ul style="list-style:disc;margin-left:20px;">
                    <?php foreach ($enabled as $pt_name): ?>
                        <?php $pt = get_post_type_object($pt_name);
                        if (!$pt) continue; ?>
                        <li>
                            <strong><?php echo esc_html($pt->labels->name); ?>:</strong>
                            <a href="<?php echo esc_url(admin_url('edit.php' . ($pt_name !== 'post' ? '?post_type=' . $pt_name : '') . '&page=ofast-reorder-' . $pt_name)); ?>">
                                <?php echo esc_html(sprintf(__('Reorder %s', 'ofast-x'), $pt->labels->name)); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <p>
                    <button type="submit" name="ofast_save_ordering" class="button button-primary"><?php esc_html_e('Save Settings', 'ofast-x'); ?></button>
                </p>
            </form>
        </div>
<?php
    }
}

// Initialize
Ofast_X_Content_Ordering::get_instance();
