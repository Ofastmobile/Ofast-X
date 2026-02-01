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

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Only load if enabled in Admin Tweaks settings
        $admin_tweaks = get_option('ofast_admin_tweaks', array());
        if (empty($admin_tweaks['enable_content_ordering'])) {
            return;
        }

        $this->module_enabled = true;
        $this->enabled_post_types = get_option('ofast_ordering_post_types', array('post', 'page'));

        // Add ordering support to enabled post types
        add_action('admin_init', array($this, 'add_ordering_support'));

        // Add admin menus
        add_action('admin_menu', array($this, 'add_admin_menus'), 100);

        // AJAX handler for saving order
        add_action('wp_ajax_ofast_save_post_order', array($this, 'ajax_save_order'));

        // Modify admin query to use menu_order
        add_action('pre_get_posts', array($this, 'admin_order_query'));

        // Modify frontend query to use menu_order
        add_action('pre_get_posts', array($this, 'frontend_order_query'));
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
                'Reorder',
                'edit_posts',
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
        if (!current_user_can('edit_posts')) {
            wp_die('Permission denied');
        }

        // Get post type from page slug
        $screen = get_current_screen();
        $post_type = str_replace('_page_ofast-reorder-', '', $screen->id);

        // Handle different screen ID formats
        if (strpos($screen->id, 'ofast-reorder-') !== false) {
            preg_match('/ofast-reorder-([a-z_-]+)$/', $screen->id, $matches);
            $post_type = $matches[1] ?? 'post';
        }

        $pt_obj = get_post_type_object($post_type);
        if (!$pt_obj) {
            echo '<div class="wrap"><h1>Invalid post type</h1></div>';
            return;
        }

        // Get all items ordered by menu_order
        $items = get_posts(array(
            'post_type' => $post_type,
            'posts_per_page' => -1,
            'orderby' => 'menu_order',
            'order' => 'ASC',
            'post_status' => array('publish', 'pending', 'draft', 'private')
        ));

        // Enqueue scripts
        wp_enqueue_script('jquery-ui-sortable');
?>
        <div class="wrap">
            <h1>Reorder <?php echo esc_html($pt_obj->labels->name); ?></h1>
            <p>Drag and drop items to reorder. Changes are saved automatically.</p>

            <div id="ofast-order-status" style="display:none;padding:10px 15px;margin:10px 0;border-radius:4px;"></div>

            <?php if (empty($items)): ?>
                <?php echo Ofast_X_Toast::render('No ' . esc_html(strtolower($pt_obj->labels->name)) . ' found.', 'info'); ?>
            <?php else: ?>
                <div id="ofast-sortable-items" data-post-type="<?php echo esc_attr($post_type); ?>">
                    <?php foreach ($items as $index => $item): ?>
                        <div class="ofast-sortable-item" data-id="<?php echo $item->ID; ?>">
                            <span class="ofast-drag-handle">⋮⋮</span>
                            <span class="ofast-item-order"><?php echo $index + 1; ?></span>
                            <span class="ofast-item-title">
                                <?php echo esc_html($item->post_title ?: '(no title)'); ?>
                            </span>
                            <span class="ofast-item-status ofast-status-<?php echo $item->post_status; ?>">
                                <?php echo ucfirst($item->post_status); ?>
                            </span>
                            <a href="<?php echo get_edit_post_link($item->ID); ?>" class="ofast-item-edit" target="_blank">Edit</a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <p style="margin-top:20px;">
                    <strong>Total:</strong> <?php echo count($items); ?> items
                </p>
            <?php endif; ?>
        </div>

        <style>
            #ofast-sortable-items {
                max-width: 800px;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 4px;
            }

            .ofast-sortable-item {
                display: flex;
                align-items: center;
                padding: 12px 15px;
                border-bottom: 1px solid #eee;
                background: #fff;
                cursor: grab;
                transition: background 0.2s;
            }

            .ofast-sortable-item:last-child {
                border-bottom: none;
            }

            .ofast-sortable-item:hover {
                background: #f7f7f7;
            }

            .ofast-drag-handle {
                color: #999;
                margin-right: 12px;
                font-size: 14px;
                letter-spacing: 2px;
            }

            .ofast-item-order {
                background: #6366f1;
                color: #fff;
                width: 28px;
                height: 28px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 12px;
                font-weight: 600;
                margin-right: 12px;
            }

            .ofast-item-title {
                flex: 1;
                font-weight: 500;
            }

            .ofast-item-status {
                font-size: 11px;
                padding: 3px 8px;
                border-radius: 3px;
                margin-right: 12px;
            }

            .ofast-status-publish {
                background: #d4edda;
                color: #155724;
            }

            .ofast-status-draft {
                background: #fff3cd;
                color: #856404;
            }

            .ofast-status-pending {
                background: #d1ecf1;
                color: #0c5460;
            }

            .ofast-status-private {
                background: #e2e3e5;
                color: #383d41;
            }

            .ofast-item-edit {
                color: #6366f1;
                text-decoration: none;
                font-size: 13px;
            }

            .ofast-sortable-item.ui-sortable-helper {
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                cursor: grabbing;
            }

            .ofast-sortable-placeholder {
                background: #e7f3ff !important;
                border: 2px dashed #6366f1;
                height: 50px;
            }

            #ofast-order-status.success {
                background: #d4edda;
                border: 1px solid #c3e6cb;
                color: #155724;
            }

            #ofast-order-status.error {
                background: #f8d7da;
                border: 1px solid #f5c6cb;
                color: #721c24;
            }

            #ofast-order-status.saving {
                background: #fff3cd;
                border: 1px solid #ffc107;
                color: #856404;
            }
        </style>

        <script>
            jQuery(function($) {
                var $container = $('#ofast-sortable-items');
                var isSaving = false;

                $container.sortable({
                    items: '.ofast-sortable-item',
                    placeholder: 'ofast-sortable-placeholder',
                    cursor: 'grabbing',
                    axis: 'y',
                    tolerance: 'pointer',

                    update: function(event, ui) {
                        updateOrderNumbers();
                        saveOrder();
                    }
                });

                function updateOrderNumbers() {
                    $('.ofast-sortable-item').each(function(index) {
                        $(this).find('.ofast-item-order').text(index + 1);
                    });
                }

                function saveOrder() {
                    if (isSaving) return;
                    isSaving = true;

                    showStatus('Saving...', 'saving');

                    var order = [];
                    $('.ofast-sortable-item').each(function() {
                        order.push($(this).data('id'));
                    });

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'ofast_save_post_order',
                            nonce: '<?php echo wp_create_nonce('ofast_ordering_nonce'); ?>',
                            order: order,
                            post_type: $container.data('post-type')
                        },
                        success: function(response) {
                            isSaving = false;
                            if (response.success) {
                                showStatus('Order saved!', 'success');
                                setTimeout(function() {
                                    $('#ofast-order-status').fadeOut();
                                }, 2000);
                            } else {
                                showStatus('Error: ' + (response.data || 'Failed'), 'error');
                            }
                        },
                        error: function() {
                            isSaving = false;
                            showStatus('Connection error', 'error');
                        }
                    });
                }

                function showStatus(message, type) {
                    $('#ofast-order-status')
                        .removeClass('success error saving')
                        .addClass(type)
                        .text(message)
                        .show();
                }
            });
        </script>
    <?php
    }

    /**
     * AJAX handler for saving order
     */
    public function ajax_save_order()
    {
        // Security checks
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ofast_ordering_nonce')) {
            wp_send_json_error('Security check failed');
        }

        $order = isset($_POST['order']) ? array_map('absint', $_POST['order']) : array();

        global $wpdb;

        foreach ($order as $index => $post_id) {
            $wpdb->update(
                $wpdb->posts,
                array('menu_order' => $index),
                array('ID' => $post_id),
                array('%d'),
                array('%d')
            );
        }

        wp_send_json_success(array(
            'message' => 'Order saved!',
            'count' => count($order)
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
            wp_die('Permission denied');
        }

        // Handle save
        if (isset($_POST['ofast_save_ordering']) && wp_verify_nonce($_POST['ordering_nonce'], 'ofast_ordering_settings')) {
            $enabled_types = isset($_POST['enabled_post_types']) ? array_map('sanitize_text_field', $_POST['enabled_post_types']) : array();
            $frontend_enabled = isset($_POST['frontend_enabled']);

            update_option('ofast_ordering_post_types', $enabled_types);
            update_option('ofast_ordering_frontend', $frontend_enabled);

            $this->enabled_post_types = $enabled_types;

            echo Ofast_X_Toast::render('Settings saved! Please refresh the page to see the new Reorder submenus.', 'success');
        }

        // Get all public post types
        $post_types = get_post_types(array('public' => true), 'objects');
        $enabled = $this->enabled_post_types;
        $frontend_enabled = get_option('ofast_ordering_frontend', false);
    ?>
        <div class="wrap">
            <h1>Content Ordering</h1>
            <p>Enable drag-and-drop reordering for your content types.</p>

            <form method="post">
                <?php wp_nonce_field('ofast_ordering_settings', 'ordering_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Ordering For</th>
                        <td>
                            <?php foreach ($post_types as $pt): ?>
                                <?php if ($pt->name === 'attachment') continue; ?>
                                <label style="display:block;margin-bottom:8px;">
                                    <input type="checkbox" name="enabled_post_types[]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $enabled)); ?>>
                                    <?php echo esc_html($pt->label); ?> <code>(<?php echo esc_html($pt->name); ?>)</code>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">Select which content types should have the Reorder submenu.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Frontend Ordering</th>
                        <td>
                            <label>
                                <input type="checkbox" name="frontend_enabled" value="1" <?php checked($frontend_enabled); ?>>
                                Apply custom order on frontend (archive pages)
                            </label>
                            <p class="description">When enabled, archive pages will display content in your custom order instead of by date.</p>
                        </td>
                    </tr>
                </table>

                <h2>Reorder Links</h2>
                <p>After enabling, find the "Reorder" submenu under each content type:</p>
                <ul style="list-style:disc;margin-left:20px;">
                    <?php foreach ($enabled as $pt_name): ?>
                        <?php $pt = get_post_type_object($pt_name);
                        if (!$pt) continue; ?>
                        <li>
                            <strong><?php echo esc_html($pt->labels->name); ?>:</strong>
                            <a href="<?php echo admin_url('edit.php' . ($pt_name !== 'post' ? '?post_type=' . $pt_name : '') . '&page=ofast-reorder-' . $pt_name); ?>">
                                Reorder <?php echo esc_html($pt->labels->name); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <p>
                    <button type="submit" name="ofast_save_ordering" class="button button-primary">Save Settings</button>
                </p>
            </form>
        </div>
<?php
    }
}

// Initialize
Ofast_X_Content_Ordering::get_instance();
