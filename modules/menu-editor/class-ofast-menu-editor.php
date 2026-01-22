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

        // Apply menu modifications (after capturing original)
        add_action('admin_menu', array($this, 'apply_menu_changes'), 9999);
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
            return;
        }

        $menu_items = isset($_POST['menu_items']) ? $_POST['menu_items'] : array();
        $settings = array();

        foreach ($menu_items as $slug => $data) {
            $settings[$slug] = array(
                'rename' => sanitize_text_field($data['rename'] ?? ''),
                'icon'   => sanitize_text_field($data['icon'] ?? ''),
                'hidden' => isset($data['hidden']) ? 1 : 0,
                'order'  => intval($data['order'] ?? 0),
            );
        }

        update_option('ofast_menu_editor_settings', $settings);
        $this->menu_settings = $settings;

        add_settings_error('ofast_menu_editor', 'saved', 'Menu settings saved!', 'success');
    }

    /**
     * Apply menu changes
     */
    public function apply_menu_changes()
    {
        global $menu;

        if (empty($this->menu_settings) || empty($menu)) {
            return;
        }

        foreach ($menu as $key => $item) {
            if (empty($item[2])) continue;

            $slug = $item[2];

            if (isset($this->menu_settings[$slug])) {
                $settings = $this->menu_settings[$slug];

                // Hide menu item
                if (!empty($settings['hidden'])) {
                    unset($menu[$key]);
                    continue;
                }

                // Rename menu item
                if (!empty($settings['rename'])) {
                    $menu[$key][0] = $settings['rename'];
                }

                // Change menu icon
                if (!empty($settings['icon'])) {
                    $menu[$key][6] = $settings['icon'];
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
            wp_die('Unauthorized');
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
                    <h1>Admin Menu Editor</h1>
                    <p>Drag rows to reorder, rename, or hide WordPress admin menu items. Save to apply changes.</p>
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
                                <th style="width: 25%;">Menu Name</th>
                                <th style="width: 25%;">Custom Name</th>
                                <th style="width: 130px;">Icon</th>
                                <th style="width: 70px; text-align: center;">Hidden</th>
                                <th>Slug</th>
                            </tr>
                        </thead>
                                <tbody id="menu-items-list">
                                    <?php
                                    $order_index = 10;
                                    $common_icons = array(
                                        '' => '— Default —',
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
                                                    placeholder="Keep original"
                                                    class="regular-text"
                                                    style="width: 100%;">
                                            </td>
                                            <td class="icon-cell">
                                                <div class="icon-picker-wrapper">
                                                    <input type="hidden" name="menu_items[<?php echo esc_attr($slug); ?>][icon]" class="icon-value" value="<?php echo esc_attr($custom_icon); ?>">
                                                    <button type="button" class="button icon-picker-btn" title="Click to change icon">
                                                        <span class="dashicons <?php echo esc_attr($custom_icon ?: $current_icon); ?>"></span>
                                                        <span class="icon-label"><?php echo $custom_icon ? 'Custom' : 'Default'; ?></span>
                                                    </button>
                                                    <div class="icon-picker-dropdown" style="display: none;">
                                                        <div class="icon-picker-search">
                                                            <input type="text" placeholder="Search icons..." class="icon-search-input">
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
                                            <td>
                                                <code style="font-size: 11px; color: #666;"><?php echo esc_html($slug); ?></code>
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
                                    <h3 style="margin: 0 0 15px 0;">Actions</h3>
                                    <button type="submit" name="ofast_save_menu_editor" class="button button-primary button-large" style="width: 100%; justify-content: center; margin-bottom: 10px;">
                                        Save Changes
                                    </button>
                                    <button type="submit" name="ofast_reset_menu" class="button button-large ofast-reset-btn" style="width: 100%; justify-content: center;" onclick="return confirm('Reset all menu customizations to default? This will unhide all menus.');">
                                        Reset to Default
                                    </button>
                                </div>
                            </div>

                            <!-- Tips -->
                            <div class="ofast-card ofast-tips-card">
                                <div class="ofast-card-body" style="padding: 20px;">
                                    <h3 style="margin-top: 0;">Tips</h3>
                                    <ul style="margin-bottom: 0; padding-left: 20px; font-size: 13px; color: #64748b;">
                                        <li style="margin-bottom: 8px;"><strong>Drag & Drop:</strong> Use the <span class="dashicons dashicons-menu"></span> handle to reorder.</li>
                                        <li style="margin-bottom: 8px;"><strong>Custom Name:</strong> Leave empty to keep original.</li>
                                        <li style="margin-bottom: 8px;"><strong>Hidden:</strong> Check to hide from menu.</li>
                                        <li><strong>Important:</strong> Save after reordering!</li>
                                    </ul>
                                </div>
                            </div>

                        </div>
                    </div>

                </div>
            </form>
        </div>

        <style>
            .ofast-header {
                display: flex;
                align-items: center;
                gap: 20px;
                background: #fff;
                padding: 25px 30px;
                border-radius: 12px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                margin-bottom: 30px;
                margin-top: 20px;
            }
            .ofast-header-icon {
                width: 56px;
                height: 56px;
                background: #fff;
                border: 1px solid #e2e8f0;
                box-shadow: 0 2px 4px rgba(0,0,0,0.02);
                border-radius: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .ofast-header-icon .dashicons {
                font-size: 28px;
                width: 28px;
                height: 28px;
                color: #6366f1;
            }
            .ofast-header-content h1 {
                margin: 0 0 5px 0 !important;
                font-size: 24px !important;
                font-weight: 700 !important;
                color: #1e293b !important;
                display: block !important;
                padding: 0 !important;
            }
            .ofast-header-content p {
                margin: 0 !important;
                color: #64748b !important;
                font-size: 14px !important;
            }

            /* LAYOUT: 2 Columns */
            .ofast-editor-layout {
                display: flex;
                gap: 30px;
                align-items: flex-start;
            }
            .ofast-editor-main {
                flex-grow: 1;
                min-width: 0; /* Prevent table overflow */
            }
            .ofast-editor-sidebar {
                width: 280px;
                flex-shrink: 0;
            }
            .ofast-card {
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            }

            #menu-items-list tr.ui-sortable-helper {
                background: #fff;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            }

            #menu-items-list tr.ui-sortable-placeholder {
                background: #e7f3ff;
                visibility: visible !important;
                height: 45px;
            }

            #menu-items-list tr.row-hidden {
                background: #fff3cd !important;
            }

            .drag-handle:hover {
                color: #6366f1 !important;
            }

            /* Modern Table Design */
            .ofast-table-card {
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                overflow: hidden;
            }

            .ofast-modern-table {
                width: 100%;
                border-collapse: collapse;
                border-spacing: 0;
            }

            .ofast-modern-table thead th {
                background: #f8fafc;
                color: #64748b;
                font-weight: 600;
                text-transform: uppercase;
                font-size: 11px;
                letter-spacing: 0.5px;
                padding: 16px 20px;
                border-bottom: 1px solid #e2e8f0;
                text-align: left;
            }

            .ofast-modern-table tbody td {
                padding: 16px 20px;
                border-bottom: 1px solid #f1f5f9;
                vertical-align: middle;
                color: #334155;
                font-size: 14px;
            }

            .ofast-modern-table tbody tr:last-child td {
                border-bottom: none;
            }

            .ofast-modern-table tbody tr:hover td {
                background-color: #f8fafc;
            }

            /* Improved Form Inputs inside table */
            .ofast-modern-table input[type="text"],
            .ofast-modern-table select {
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                padding: 10px 14px;
                box-shadow: 0 1px 2px rgba(0,0,0,0.05);
                transition: all 0.2s;
                font-size: 14px;
                color: #334155;
                background: #fff;
            }
            .ofast-modern-table input[type="text"]:focus {
                border-color: #6366f1;
                box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
                outline: none;
            }

            /* Icon Picker Styles */
            .icon-picker-wrapper {
                position: relative;
            }

            .icon-picker-btn {
                display: flex !important;
                align-items: center;
                gap: 6px;
                padding: 4px 10px !important;
                min-width: 100px;
                border: 1px solid #6366f1 !important;
                color: #6366f1 !important;
                background: #fff !important;
                border-radius: 6px !important;
                transition: all 0.2s ease;
            }

            .icon-picker-btn:hover {
                background: #eff6ff !important;
                box-shadow: 0 2px 4px rgba(99, 102, 241, 0.1);
            }

            .icon-picker-btn .dashicons {
                font-size: 18px;
                width: 18px;
                height: 18px;
                color: #6366f1 !important;
            }

            .icon-label {
                font-size: 11px;
                color: #6366f1;
                font-weight: 500;
            }

            .icon-picker-dropdown {
                position: absolute;
                top: 100%;
                left: 0;
                z-index: 1000;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 6px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                width: 280px;
                max-height: 300px;
                overflow: hidden;
            }

            .icon-picker-search {
                padding: 8px;
                border-bottom: 1px solid #eee;
            }

            .icon-search-input {
                width: 100%;
                padding: 6px 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }

            .icon-grid {
                display: flex;
                flex-wrap: wrap;
                padding: 8px;
                max-height: 220px;
                overflow-y: auto;
                gap: 4px;
            }

            .icon-option {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 32px;
                height: 32px;
                border-radius: 4px;
                cursor: pointer;
                transition: all 0.15s;
            }

            .icon-option:hover {
                background: #e7f3ff;
            }

            .icon-option .dashicons {
                font-size: 20px;
                width: 20px;
                height: 20px;
                color: #50575e;
            }

            .icon-option.selected {
                background: #6366f1;
            }

            .icon-option.selected .dashicons {
                color: #fff;
            }

            /* Button Override */
            .button.button-primary {
                background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%) !important;
                border-color: #6366f1 !important;
                text-shadow: none !important;
                box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3) !important;
                transition: all 0.3s ease !important;
                padding: 10px 24px !important;
                height: auto !important;
                border-radius: 8px !important;
                font-size: 14px !important;
            }
            .button.button-primary:hover {
                background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%) !important;
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4) !important;
            }
            .button.button-primary:active {
                transform: translateY(0);
            }
            
            /* Reset button styling */
            .ofast-reset-btn {
                background: #fff !important;
                border: 2px solid #fecaca !important;
                color: #ef4444 !important;
                transition: all 0.2s ease !important;
            }
            .ofast-reset-btn:hover {
                background: #fef2f2 !important;
                border-color: #ef4444 !important;
            }

            /* Responsive */
            @media screen and (max-width: 960px) {
                .ofast-editor-layout {
                    flex-direction: column;
                }
                .ofast-editor-sidebar {
                    width: 100%;
                }
                .ofast-sidebar-inner {
                    position: static !important;
                }
            }
        </style>

        <script>
            jQuery(document).ready(function($) {
                // Make table sortable
                $('#menu-items-list').sortable({
                    handle: '.drag-handle',
                    placeholder: 'ui-sortable-placeholder',
                    axis: 'y',
                    helper: function(e, tr) {
                        var $originals = tr.children();
                        var $helper = tr.clone();
                        $helper.children().each(function(index) {
                            $(this).width($originals.eq(index).width());
                        });
                        return $helper;
                    },
                    update: function(event, ui) {
                        // Update hidden order inputs after drag
                        var order = 10;
                        $('#menu-items-list tr').each(function() {
                            $(this).find('.order-input').val(order);
                            order += 10;
                        });
                    }
                });

                // Icon picker toggle
                $(document).on('click', '.icon-picker-btn', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var dropdown = $(this).siblings('.icon-picker-dropdown');
                    $('.icon-picker-dropdown').not(dropdown).hide();
                    dropdown.toggle();
                });

                // Close dropdown when clicking outside
                $(document).on('click', function(e) {
                    if (!$(e.target).closest('.icon-picker-wrapper').length) {
                        $('.icon-picker-dropdown').hide();
                    }
                });

                // Icon search filter
                $(document).on('input', '.icon-search-input', function() {
                    var term = $(this).val().toLowerCase();
                    $(this).closest('.icon-picker-dropdown').find('.icon-option').each(function() {
                        var title = $(this).attr('title').toLowerCase();
                        $(this).toggle(title.includes(term) || term === '');
                    });
                });

                // Icon selection
                $(document).on('click', '.icon-option', function() {
                    var wrapper = $(this).closest('.icon-picker-wrapper');
                    var icon = $(this).data('icon');
                    var iconClass = icon || 'dashicons-admin-generic';

                    wrapper.find('.icon-value').val(icon);
                    wrapper.find('.icon-picker-btn .dashicons').attr('class', 'dashicons ' + iconClass);
                    wrapper.find('.icon-label').text(icon ? 'Custom' : 'Default');
                    wrapper.find('.icon-picker-dropdown').hide();

                    // Update icon in menu name column
                    wrapper.closest('tr').find('td:eq(1) .dashicons').attr('class', 'dashicons ' + iconClass);
                });
            });
        </script>
<?php
    }
}
