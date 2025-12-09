<?php

/**
 * Ofast X - Content Ordering Module
 * Drag-and-drop reorder for posts, pages, and custom post types
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Content_Order
{
    /**
     * Initialize module
     */
    public function init()
    {
        $enabled = get_option('ofastx_modules_enabled', array());
        if (empty($enabled['content-order'])) {
            return;
        }

        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // AJAX handlers
        add_action('wp_ajax_ofast_save_order', array($this, 'ajax_save_order'));
        add_action('wp_ajax_ofast_reset_order', array($this, 'ajax_reset_order'));

        // Modify queries to respect custom order
        add_action('pre_get_posts', array($this, 'apply_custom_order'));

        // Enqueue scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'Content Order',
            'Content Order',
            'edit_posts',
            'ofast-content-order',
            array($this, 'render_order_page')
        );
    }

    /**
     * Enqueue assets
     */
    public function enqueue_assets($hook)
    {
        if ($hook !== 'ofast-x_page_ofast-content-order') {
            return;
        }

        // jQuery UI Sortable
        wp_enqueue_script('jquery-ui-sortable');

        // Custom JS
        wp_enqueue_script(
            'ofast-content-order',
            plugin_dir_url(__FILE__) . 'assets/content-order.js',
            array('jquery', 'jquery-ui-sortable'),
            '1.0.0',
            true
        );

        wp_localize_script('ofast-content-order', 'ofastContentOrder', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ofast_content_order'),
        ));

        // Custom CSS
        wp_enqueue_style(
            'ofast-content-order',
            plugin_dir_url(__FILE__) . 'assets/content-order.css',
            array(),
            '1.0.0'
        );
    }

    /**
     * Render order page
     */
    public function render_order_page()
    {
        if (!current_user_can('edit_posts')) {
            wp_die('Insufficient permissions');
        }

        $post_type = isset($_GET['post_type']) ? sanitize_key($_GET['post_type']) : 'post';

        // Only allow posts and pages
        $allowed_types = array('post', 'page');
        if (!in_array($post_type, $allowed_types)) {
            $post_type = 'post';
        }

        // Get posts
        $posts = get_posts(array(
            'post_type' => $post_type,
            'posts_per_page' => -1,
            'orderby' => 'menu_order',
            'order' => 'ASC',
            'post_status' => array('publish', 'draft', 'pending', 'private'),
        ));

?>
        <div class="wrap">
            <h1>Content Order</h1>
            <p>Drag and drop to reorder your content. Changes are saved automatically.</p>

            <!-- Tabs Navigation -->
            <nav class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <a href="?page=ofast-content-order&post_type=post"
                    class="nav-tab <?php echo $post_type === 'post' ? 'nav-tab-active' : ''; ?>">
                    Posts
                </a>
                <a href="?page=ofast-content-order&post_type=page"
                    class="nav-tab <?php echo $post_type === 'page' ? 'nav-tab-active' : ''; ?>">
                    Pages
                </a>
                <button type="button" class="button" id="reset-order-btn" style="margin-left: auto; float: right;">
                    Reset to Default Order
                </button>
                <div class="save-status" style="display: inline-block; margin-left: 15px; float: right; color: #46b450; font-weight: 500; line-height: 30px;"></div>
            </nav>

            <?php if (empty($posts)): ?>
                <div style="background: #f9f9f9; padding: 40px; text-align: center; border-radius: 8px;">
                    <p style="color: #999; font-size: 16px;">No <?php echo $post_type === 'post' ? 'posts' : 'pages'; ?> found.</p>
                </div>
            <?php else: ?>
                <div id="sortable-posts" class="ofast-sortable-list" data-post-type="<?php echo esc_attr($post_type); ?>">
                    <?php foreach ($posts as $post): ?>
                        <?php $this->render_post_item($post); ?>
                    <?php endforeach; ?>
                </div>

                <p style="margin-top: 20px; color: #666;">
                    <strong>Tip:</strong> Grab the handle and drag to reorder. Changes save automatically!
                </p>
            <?php endif; ?>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Handle reset
                $('#reset-order-btn').on('click', function() {
                    if (!confirm('Reset all items to default order (by date)? This cannot be undone.')) {
                        return;
                    }

                    var $btn = $(this);
                    $btn.prop('disabled', true).text('Resetting...');

                    $.post(ofastContentOrder.ajaxurl, {
                        action: 'ofast_reset_order',
                        nonce: ofastContentOrder.nonce,
                        post_type: '<?php echo esc_js($post_type); ?>'
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                            $btn.prop('disabled', false).text('Reset to Default Order');
                        }
                    });
                });
            });
        </script>
    <?php
    }

    /**
     * Render single post item
     */
    private function render_post_item($post)
    {
        $thumbnail = get_the_post_thumbnail($post->ID, array(40, 40));
        $status_class = $post->post_status === 'publish' ? 'status-published' : 'status-draft';
        $status_label = ucfirst($post->post_status);
        $edit_url = get_edit_post_link($post->ID);

    ?>
        <div class="ofast-post-item <?php echo $status_class; ?>" data-id="<?php echo $post->ID; ?>">
            <span class="drag-handle">&#9776;</span>

            <code class="post-id" style="background: #f0f0f1; padding: 3px 8px; border-radius: 4px; font-size: 12px; margin-right: 10px; min-width: 50px; text-align: center;"><?php echo $post->ID; ?></code>

            <?php if ($thumbnail): ?>
                <div class="post-thumbnail"><?php echo $thumbnail; ?></div>
            <?php endif; ?>

            <div class="post-info">
                <strong class="post-title"><?php echo esc_html($post->post_title ?: '(No title)'); ?></strong>
                <span class="post-meta">
                    <span class="post-status"><?php echo esc_html($status_label); ?></span>
                    - <?php echo human_time_diff(strtotime($post->post_date), current_time('timestamp')); ?> ago
                </span>
            </div>

            <div class="post-actions">
                <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">Edit</a>
            </div>
        </div>
<?php
    }

    /**
     * AJAX: Save order
     */
    public function ajax_save_order()
    {
        check_ajax_referer('ofast_content_order', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }

        $order = isset($_POST['order']) ? array_map('intval', $_POST['order']) : array();

        if (empty($order)) {
            wp_send_json_error('No order data received');
        }

        $menu_order = 0;
        foreach ($order as $post_id) {
            wp_update_post(array(
                'ID' => $post_id,
                'menu_order' => $menu_order
            ));
            $menu_order++;
        }

        wp_send_json_success(array('message' => 'Order saved!'));
    }

    /**
     * AJAX: Reset order
     */
    public function ajax_reset_order()
    {
        check_ajax_referer('ofast_content_order', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }

        $post_type = sanitize_key($_POST['post_type']);

        $posts = get_posts(array(
            'post_type' => $post_type,
            'posts_per_page' => -1,
            'post_status' => 'any',
        ));

        foreach ($posts as $post) {
            wp_update_post(array(
                'ID' => $post->ID,
                'menu_order' => 0
            ));
        }

        wp_send_json_success(array('message' => 'Order reset!'));
    }

    /**
     * Apply custom order to queries
     */
    public function apply_custom_order($query)
    {
        // Only on frontend, main query
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        // Check if post type uses custom ordering
        $post_type = $query->get('post_type');
        if (empty($post_type)) {
            $post_type = 'post';
        }

        // Apply menu_order if not already set
        if (!$query->get('orderby')) {
            $query->set('orderby', 'menu_order date');
            $query->set('order', 'ASC');
        }
    }
}
