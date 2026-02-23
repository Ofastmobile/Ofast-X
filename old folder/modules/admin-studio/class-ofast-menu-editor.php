<?php

/**
 * Ofast X - Admin Menu Editor Module
 * Reorder and rename WordPress admin menu items
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Menu_Editor
{
    private $menu_settings = array();
    private $original_menu = array();

    /**
     * Initialize module
     */
    public function init()
    {
        // Only load if module is enabled
        $admin_tweaks = get_option('ofast_admin_tweaks', array());
        if (empty($admin_tweaks['enable_menu_editor'])) {
            return;
        }

        $this->menu_settings = get_option('ofast_menu_editor_settings', array());

        // Add settings page - capture menu BEFORE modifications
        add_action('admin_menu', array($this, 'capture_original_menu'), 998);
        add_action('admin_menu', array($this, 'add_admin_menu'), 999);
        add_action('admin_init', array($this, 'handle_save'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Apply menu modifications (after capturing original)
        add_action('admin_menu', array($this, 'apply_menu_changes'), 9999);
    }

    /**
     * Critical menu slugs that cannot be hidden (self-DoS protection)
     */
    private function get_protected_slugs()
    {
        return array(
            'users.php',
            'plugins.php', 
            'options-general.php',
            'ofast-menu-editor',
            'ofast-dashboard',
        );
    }

    /**
     * Enqueue scripts and styles for menu editor page
     */
    public function enqueue_scripts($hook)
    {
        if ($hook !== 'ofast-x_page_ofast-menu-editor' && strpos($hook, 'ofast-menu-editor') === false) {
            return;
        }

        wp_enqueue_script('jquery-ui-sortable');

        wp_enqueue_style(
            'ofast-menu-editor',
            OFAST_X_PLUGIN_URL . 'assets/css/menu-editor.css',
            array(),
            OFAST_X_VERSION
        );

        wp_enqueue_script(
            'ofast-menu-editor',
            OFAST_X_PLUGIN_URL . 'assets/js/menu-editor.js',
            array('jquery', 'jquery-ui-sortable'),
            OFAST_X_VERSION,
            true
        );

        wp_localize_script('ofast-menu-editor', 'ofastMenuEditor', array(
            'i18n' => array(
                'custom' => __('Custom', 'ofast-x'),
                'default' => __('Default', 'ofast-x'),
            )
        ));
    }

    /**
     * Capture original menu before any modifications
     */
    public function capture_original_menu()
    {
        global $menu;
        $this->original_menu = $menu;
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'Menu Editor',
            'Menu Editor',
            'manage_options',
            'ofast-menu-editor',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Handle settings save
     */
    public function handle_save()
    {
        // Handle Reset
        if (isset($_POST['ofast_reset_menu'])) {
            check_admin_referer('ofast_menu_editor_save', '_wpnonce');
            if (current_user_can('manage_options')) {
                delete_option('ofast_menu_editor_settings');
                $this->menu_settings = array();
                add_settings_error('ofast_menu_editor', 'reset', 'Menu settings reset to default!', 'success');
            }
            return;
        }

        if (!isset($_POST['ofast_save_menu_editor'])) {
            return;
        }

        check_admin_referer('ofast_menu_editor_save', '_wpnonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to modify menu settings.', 'ofast-x'));
        }

        // Sanitize with wp_unslash
        $menu_items = isset($_POST['menu_items']) ? wp_unslash($_POST['menu_items']) : array();
        $settings = array();
        $protected_slugs = $this->get_protected_slugs();

        foreach ($menu_items as $slug => $data) {
            $clean_slug = sanitize_key($slug);
            
            // Prevent hiding protected menus
            $is_hidden = isset($data['hidden']) ? 1 : 0;
            if ($is_hidden && in_array($slug, $protected_slugs, true)) {
                $is_hidden = 0; // Force unhide critical menus
            }
            
            // Validate icon against dashicons whitelist
            $icon = sanitize_text_field($data['icon'] ?? '');
            if (!empty($icon) && strpos($icon, 'dashicons-') !== 0) {
                $icon = ''; // Invalid icon format
            }
            
            // Clamp order to reasonable bounds
            $order = intval($data['order'] ?? 0);
            $order = max(0, min(9999, $order));
            
            $settings[$slug] = array(
                'rename' => sanitize_text_field($data['rename'] ?? ''),
                'icon'   => $icon,
                'hidden' => $is_hidden,
                'order'  => $order,
            );
        }

        update_option('ofast_menu_editor_settings', $settings, false);
        $this->menu_settings = $settings;

        add_settings_error('ofast_menu_editor', 'saved', __('Menu settings saved!', 'ofast-x'), 'success');
    }

    /**
     * Apply menu changes
     */
    public function apply_menu_changes()
    {
        global $menu;

        // SECURITY: Only apply for users with manage_options capability
        if (!current_user_can('manage_options')) {
            return;
        }

        if (empty($this->menu_settings) || empty($menu)) {
            return;
        }

        $protected_slugs = $this->get_protected_slugs();

        foreach ($menu as $key => $item) {
            if (empty($item[2])) continue;

            $slug = $item[2];

            if (isset($this->menu_settings[$slug])) {
                $settings = $this->menu_settings[$slug];

                // Hide menu item (but never hide protected slugs)
                if (!empty($settings['hidden']) && !in_array($slug, $protected_slugs, true)) {
                    unset($menu[$key]);
                    continue;
                }

                // Rename menu item - ESCAPE to prevent XSS
                if (!empty($settings['rename'])) {
                    $menu[$key][0] = esc_html($settings['rename']);
                }

                // Change menu icon - validate dashicons format
                if (!empty($settings['icon']) && strpos($settings['icon'], 'dashicons-') === 0) {
                    $menu[$key][6] = esc_attr($settings['icon']);
                }
            }
        }

        // Reorder menu items
        $this->reorder_menu();
    }

    /**
     * Reorder menu items based on saved order
     */
    private function reorder_menu()
    {
        global $menu;

        $ordered_items = array();
        $unordered_items = array();

        foreach ($menu as $key => $item) {
            if (empty($item[2])) continue;

            $slug = $item[2];

            if (isset($this->menu_settings[$slug]) && !empty($this->menu_settings[$slug]['order'])) {
                $order = $this->menu_settings[$slug]['order'];
                $ordered_items[$order] = $item;
            } else {
                $unordered_items[$key] = $item;
            }
        }

        if (!empty($ordered_items)) {
            ksort($ordered_items);
            $menu = array_merge($ordered_items, $unordered_items);
        }
    }

    /**
     * Get menu items sorted by saved order for display
     */
    private function get_sorted_menu_for_display()
    {
        $menu_to_display = !empty($this->original_menu) ? $this->original_menu : $GLOBALS['menu'];

        // Build array with order info
        $items_with_order = array();
        $index = 1;

        foreach ($menu_to_display as $item) {
            if (empty($item[0]) || empty($item[2])) continue;

            $slug = $item[2];
            $settings = isset($this->menu_settings[$slug]) ? $this->menu_settings[$slug] : array();
            $order = $settings['order'] ?? ($index * 10);

            $items_with_order[] = array(
                'item' => $item,
                'order' => $order,
                'settings' => $settings,
            );
            $index++;
        }

        // Sort by order
        usort($items_with_order, function ($a, $b) {
            return $a['order'] - $b['order'];
        });

        return $items_with_order;
    }

    /**
     * Render settings page
     */
    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'ofast-x'));
        }

        // Get sorted menu items for display
        $sorted_menu = $this->get_sorted_menu_for_display();

        // Enqueue jQuery UI Sortable
        wp_enqueue_script('jquery-ui-sortable');

        settings_errors('ofast_menu_editor');
?>
        <div class="wrap" style="max-width: 1200px;">
            <div class="ofast-header">
                <div class="ofast-header-icon">
                    <span class="dashicons dashicons-menu-alt3"></span>
                </div>
                <div class="ofast-header-content">
                    <h1><?php esc_html_e('Admin Menu Editor', 'ofast-x'); ?></h1>
                    <p><?php esc_html_e('Drag rows to reorder, rename, or hide WordPress admin menu items. Save to apply changes.', 'ofast-x'); ?></p>
                </div>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('ofast_menu_editor_save', '_wpnonce'); ?>

                <div class="ofast-editor-layout">
                    
                    <!-- Left Column: Table -->
                    <div class="ofast-editor-main">
                <!-- Scrollable Table Container -->
                <div class="ofast-table-card">
                    <div style="overflow-x: auto;">
                        <table class="ofast-modern-table" style="width: 100%;" id="menu-editor-table">
                        <thead>
                            <tr>
                                <th style="width: 40px; text-align: center;"></th>
                                <th style="width: 30%;"><?php esc_html_e('Menu Name', 'ofast-x'); ?></th>
                                <th style="width: 30%;"><?php esc_html_e('Custom Name', 'ofast-x'); ?></th>
                                <th style="width: 130px;"><?php esc_html_e('Icon', 'ofast-x'); ?></th>
                                <th style="width: 70px; text-align: center;"><?php esc_html_e('Hidden', 'ofast-x'); ?></th>
                            </tr>
                        </thead>
                                <tbody id="menu-items-list">
                                    <?php
                                    $order_index = 10;
                                    $common_icons = array(
                                        '' => 'â€” Default â€”',
                                        'dashicons-admin-home' => 'Home',
                                        // ... (rest of icons array structure remains same, simplified for brevity in replacement if possible, but keeping logic)
                                        'dashicons-admin-post' => 'Post',
                                        'dashicons-admin-media' => 'Media',
                                        'dashicons-admin-page' => 'Page',
                                        'dashicons-admin-comments' => 'Comments',
                                        'dashicons-admin-appearance' => 'Appearance',
                                        'dashicons-admin-plugins' => 'Plugins',
                                        'dashicons-admin-users' => 'Users',
                                        'dashicons-admin-tools' => 'Tools',
                                        'dashicons-admin-settings' => 'Settings',
                                        'dashicons-admin-network' => 'Network',
                                        'dashicons-admin-generic' => 'Generic',
                                        'dashicons-dashboard' => 'Dashboard',
                                        'dashicons-chart-bar' => 'Chart Bar',
                                        'dashicons-chart-pie' => 'Chart Pie',
                                        'dashicons-chart-line' => 'Chart Line',
                                        'dashicons-chart-area' => 'Chart Area',
                                        'dashicons-analytics' => 'Analytics',
                                        'dashicons-email' => 'Email',
                                        'dashicons-email-alt' => 'Email Alt',
                                        'dashicons-email-alt2' => 'Email Alt2',
                                        'dashicons-products' => 'Products',
                                        'dashicons-cart' => 'Cart',
                                        'dashicons-store' => 'Store',
                                        'dashicons-money-alt' => 'Money',
                                        'dashicons-calendar' => 'Calendar',
                                        'dashicons-calendar-alt' => 'Calendar Alt',
                                        'dashicons-star-filled' => 'Star',
                                        'dashicons-star-half' => 'Star Half',
                                        'dashicons-heart' => 'Heart',
                                        'dashicons-shield' => 'Shield',
                                        'dashicons-shield-alt' => 'Shield Alt',
                                        'dashicons-lock' => 'Lock',
                                        'dashicons-unlock' => 'Unlock',
                                        'dashicons-visibility' => 'Eye',
                                        'dashicons-hidden' => 'Hidden',
                                        'dashicons-bell' => 'Bell',
                                        'dashicons-flag' => 'Flag',
                                        'dashicons-awards' => 'Awards',
                                        'dashicons-thumbs-up' => 'Thumbs Up',
                                        'dashicons-thumbs-down' => 'Thumbs Down',
                                        'dashicons-welcome-write-blog' => 'Write',
                                        'dashicons-welcome-add-page' => 'Add Page',
                                        'dashicons-welcome-view-site' => 'View Site',
                                        'dashicons-welcome-widgets-menus' => 'Widgets',
                                        'dashicons-welcome-learn-more' => 'Learn',
                                        'dashicons-hammer' => 'Hammer',
                                        'dashicons-arrow-right-alt' => 'Arrow Right',
                                        'dashicons-arrow-left-alt' => 'Arrow Left',
                                        'dashicons-arrow-up-alt' => 'Arrow Up',
                                        'dashicons-arrow-down-alt' => 'Arrow Down',
                                        'dashicons-search' => 'Search',
                                        'dashicons-filter' => 'Filter',
                                        'dashicons-sort' => 'Sort',
                                        'dashicons-list-view' => 'List View',
                                        'dashicons-grid-view' => 'Grid View',
                                        'dashicons-exerpt-view' => 'Excerpt View',
                                        'dashicons-info' => 'Info',
                                        'dashicons-warning' => 'Warning',
                                        'dashicons-yes' => 'Yes/Check',
                                        'dashicons-no' => 'No/X',
                                        'dashicons-plus' => 'Plus',
                                        'dashicons-minus' => 'Minus',
                                        'dashicons-paperclip' => 'Paperclip',
                                        'dashicons-camera' => 'Camera',
                                        'dashicons-video-alt' => 'Video',
                                        'dashicons-microphone' => 'Microphone',
                                        'dashicons-format-audio' => 'Audio',
                                        'dashicons-format-image' => 'Image',
                                        'dashicons-format-gallery' => 'Gallery',
                                        'dashicons-format-video' => 'Video',
                                        'dashicons-database' => 'Database',
                                        'dashicons-cloud' => 'Cloud',
                                        'dashicons-cloud-upload' => 'Upload',
                                        'dashicons-cloud-saved' => 'Saved',
                                        'dashicons-download' => 'Download',
                                        'dashicons-upload' => 'Upload',
                                        'dashicons-backup' => 'Backup',
                                        'dashicons-book' => 'Book',
                                        'dashicons-book-alt' => 'Book Alt',
                                        'dashicons-businessman' => 'Businessman',
                                        'dashicons-buddicons-buddypress-logo' => 'BuddyPress',
                                    );
                                    foreach ($sorted_menu as $menu_data):
                                        $item = $menu_data['item'];
                                        $slug = $item[2];
                                        $name = wp_strip_all_tags($item[0]);
                                        $settings = $menu_data['settings'];
                                        $custom_name = $settings['rename'] ?? '';
                                        $custom_icon = $settings['icon'] ?? '';
                                        $current_icon = $item[6] ?? 'dashicons-admin-generic';
                                        $is_hidden = !empty($settings['hidden']);
                                        $current_order = $menu_data['order'];
                                    ?>
                                        <tr class="menu-item-row <?php echo $is_hidden ? 'row-hidden' : ''; ?>" data-slug="<?php echo esc_attr($slug); ?>">
                                            <td class="drag-handle" style="cursor: move; text-align: center; color: #999; font-size: 16px;">
                                                <span class="dashicons dashicons-menu"></span>
                                            </td>
                                            <td>
                                                <span class="dashicons <?php echo esc_attr($custom_icon ?: $current_icon); ?>" style="margin-right: 5px; color: #666;"></span>
                                                <strong><?php echo esc_html($name); ?></strong>
                                                <?php if ($is_hidden): ?>
                                                    <span style="color: #999; font-size: 11px;"> (hidden)</span>
                                                <?php endif; ?>
                                                <!-- Hidden order input -->
                                                <input type="hidden"
                                                    name="menu_items[<?php echo esc_attr($slug); ?>][order]"
                                                    value="<?php echo esc_attr($current_order); ?>"
                                                    class="order-input">
                                            </td>
                                            <td>
                                                <input type="text"
                                                    name="menu_items[<?php echo esc_attr($slug); ?>][rename]"
                                                    value="<?php echo esc_attr($custom_name); ?>"
                                                    placeholder="<?php esc_attr_e('Keep original', 'ofast-x'); ?>"
                                                    class="regular-text"
                                                    style="width: 100%;">
                                            </td>
                                            <td class="icon-cell">
                                                <div class="icon-picker-wrapper">
                                                    <input type="hidden" name="menu_items[<?php echo esc_attr($slug); ?>][icon]" class="icon-value" value="<?php echo esc_attr($custom_icon); ?>">
                                                    <button type="button" class="button icon-picker-btn" title="<?php esc_attr_e('Click to change icon', 'ofast-x'); ?>">
                                                        <span class="dashicons <?php echo esc_attr($custom_icon ?: $current_icon); ?>"></span>
                                                        <span class="icon-label"><?php echo $custom_icon ? esc_html__('Custom', 'ofast-x') : esc_html__('Default', 'ofast-x'); ?></span>
                                                    </button>
                                                    <div class="icon-picker-dropdown" style="display: none;">
                                                        <div class="icon-picker-search">
                                                            <input type="text" placeholder="<?php esc_attr_e('Search icons...', 'ofast-x'); ?>" class="icon-search-input">
                                                        </div>
                                                        <div class="icon-grid">
                                                            <span class="icon-option" data-icon="" title="Default"><span class="dashicons dashicons-admin-generic"></span></span>
                                                            <?php foreach ($common_icons as $icon_class => $icon_label): if ($icon_class): ?>
                                                                    <span class="icon-option" data-icon="<?php echo esc_attr($icon_class); ?>" title="<?php echo esc_attr($icon_label); ?>">
                                                                        <span class="dashicons <?php echo esc_attr($icon_class); ?>"></span>
                                                                    </span>
                                                            <?php endif;
                                                            endforeach; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td style="text-align: center;">
                                                <label class="ofast-toggle">
                                                    <input type="checkbox"
                                                        name="menu_items[<?php echo esc_attr($slug); ?>][hidden]"
                                                        value="1"
                                                        <?php checked($is_hidden); ?>>
                                                    <span class="ofast-slider"></span>
                                                </label>
                                            </td>
                                        </tr>
                                    <?php
                                        $order_index += 10;
                                    endforeach;
                                    ?>
                                </tbody>
                            </table>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Sidebar -->
                    <div class="ofast-editor-sidebar">
                        <div class="ofast-sidebar-inner" style="position: sticky; top: 100px;">
                            
                            <!-- Save Actions -->
                            <div class="ofast-card" style="margin-bottom: 20px;">
                                <div class="ofast-card-body" style="padding: 20px;">
                                    <h3 style="margin: 0 0 15px 0;"><?php esc_html_e('Actions', 'ofast-x'); ?></h3>
                                    <button type="submit" name="ofast_save_menu_editor" class="button button-primary button-large" style="width: 100%; justify-content: center; margin-bottom: 10px;">
                                        <?php esc_html_e('Save Changes', 'ofast-x'); ?>
                                    </button>
                                    <button type="submit" name="ofast_reset_menu" class="button button-large ofast-reset-btn" style="width: 100%; justify-content: center;" onclick="return confirm('<?php echo esc_js(__('Reset all menu customizations to default? This will unhide all menus.', 'ofast-x')); ?>');">
                                        <?php esc_html_e('Reset to Default', 'ofast-x'); ?>
                                    </button>
                                </div>
                            </div>

                            <!-- Tips -->
                            <div class="ofast-card ofast-tips-card">
                                <div class="ofast-card-body" style="padding: 20px;">
                                    <h3 style="margin-top: 0;"><?php esc_html_e('Tips', 'ofast-x'); ?></h3>
                                    <ul style="margin-bottom: 0; padding-left: 20px; font-size: 13px; color: #64748b;">
                                        <li style="margin-bottom: 8px;"><strong><?php esc_html_e('Drag & Drop:', 'ofast-x'); ?></strong> <?php esc_html_e('Use the handle to reorder.', 'ofast-x'); ?></li>
                                        <li style="margin-bottom: 8px;"><strong><?php esc_html_e('Custom Name:', 'ofast-x'); ?></strong> <?php esc_html_e('Leave empty to keep original.', 'ofast-x'); ?></li>
                                        <li style="margin-bottom: 8px;"><strong><?php esc_html_e('Hidden:', 'ofast-x'); ?></strong> <?php esc_html_e('Check to hide from menu.', 'ofast-x'); ?></li>
                                        <li><strong><?php esc_html_e('Important:', 'ofast-x'); ?></strong> <?php esc_html_e('Save after reordering!', 'ofast-x'); ?></li>
                                    </ul>
                                </div>
                            </div>

                        </div>
                    </div>

                </div>
            </form>
        </div>
    <?php
    }
}
