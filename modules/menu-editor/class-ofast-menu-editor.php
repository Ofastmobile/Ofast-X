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
        $enabled = get_option('ofastx_modules_enabled', array());
        if (empty($enabled['menu-editor'])) {
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
        <div class="wrap">
            <h1>Admin Menu Editor</h1>
            <p class="description">Drag rows to reorder, rename, or hide WordPress admin menu items. Save to apply changes.</p>

            <form method="post" action="">
                <?php wp_nonce_field('ofast_menu_editor_save', '_wpnonce'); ?>

                <table class="wp-list-table widefat fixed striped" style="max-width: 800px; margin-top: 20px;" id="menu-editor-table">
                    <thead>
                        <tr>
                            <th style="width: 30px;"></th>
                            <th style="width: 200px;">Menu Name</th>
                            <th style="width: 200px;">Custom Name</th>
                            <th style="width: 80px;">Hidden</th>
                            <th>Slug</th>
                        </tr>
                    </thead>
                    <tbody id="menu-items-list">
                        <?php
                        $order_index = 10;
                        foreach ($sorted_menu as $menu_data):
                            $item = $menu_data['item'];
                            $slug = $item[2];
                            $name = wp_strip_all_tags($item[0]);
                            $settings = $menu_data['settings'];
                            $custom_name = $settings['rename'] ?? '';
                            $is_hidden = !empty($settings['hidden']);
                            $current_order = $menu_data['order'];
                        ?>
                            <tr class="menu-item-row <?php echo $is_hidden ? 'row-hidden' : ''; ?>" data-slug="<?php echo esc_attr($slug); ?>">
                                <td class="drag-handle" style="cursor: move; text-align: center; color: #999; font-size: 16px;">
                                    ☰
                                </td>
                                <td>
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
                                <td>
                                    <input type="checkbox"
                                        name="menu_items[<?php echo esc_attr($slug); ?>][hidden]"
                                        value="1"
                                        <?php checked($is_hidden); ?>>
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

                <!-- Reset Button -->
                <div style="margin-top: 15px;">
                    <button type="submit" name="ofast_reset_menu" class="button" onclick="return confirm('Reset all menu customizations to default? This will unhide all menus.');">
                        Reset to Default
                    </button>
                </div>

                <p class="submit">
                    <button type="submit" name="ofast_save_menu_editor" class="button button-primary button-large">
                        Save Menu Changes
                    </button>
                </p>
            </form>

            <!-- Help Box -->
            <div style="background: #f0f6fc; border: 1px solid #c3d9ed; border-radius: 8px; padding: 15px; margin-top: 20px; max-width: 800px;">
                <h3 style="margin-top: 0;">Tips</h3>
                <ul style="margin-bottom: 0;">
                    <li><strong>Drag & Drop:</strong> Use the ☰ handle to drag rows and reorder.</li>
                    <li><strong>Custom Name:</strong> Leave empty to keep the original name.</li>
                    <li><strong>Hidden:</strong> Check to hide the menu. Uncheck and save to show it again.</li>
                    <li><strong>Important:</strong> Click <strong>Save Menu Changes</strong> after reordering!</li>
                </ul>
            </div>
        </div>

        <style>
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
                color: #2271b1 !important;
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
            });
        </script>
<?php
    }
}
