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
        $this->menu_settings = get_option('ofast_menu_editor_settings', array());

        // Capture menu BEFORE modifications
        add_action('admin_menu', array($this, 'capture_original_menu'), 998);
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
        // Load on the White Label page (embedded in Updates tab)
        if (strpos($hook, 'ofast-white-label') === false) {
            return;
        }

        wp_enqueue_script('jquery-ui-sortable');

        wp_enqueue_style(
            'ofast-menu-editor',
            plugins_url('assets/css/menu-editor.css', __FILE__),
            array(),
            OFAST_X_VERSION
        );

        wp_enqueue_script(
            'ofast-menu-editor',
            plugins_url('assets/js/menu-editor.js', __FILE__),
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
     * Save menu editor settings (called from White Label save handler)
     */
    public function save_settings($post_data)
    {
        $menu_items = isset($post_data['menu_items']) ? wp_unslash($post_data['menu_items']) : array();
        $settings = array();
        $protected_slugs = $this->get_protected_slugs();

        foreach ($menu_items as $slug => $data) {
            $clean_slug = sanitize_key($slug);

            // Prevent hiding protected menus
            $is_hidden = isset($data['hidden']) ? 1 : 0;
            if ($is_hidden && in_array($slug, $protected_slugs, true)) {
                $is_hidden = 0;
            }

            // Validate icon against dashicons whitelist
            $icon = sanitize_text_field($data['icon'] ?? '');
            if (!empty($icon) && strpos($icon, 'dashicons-') !== 0) {
                $icon = '';
            }

            // Clamp order to reasonable bounds
            $order = intval($data['order'] ?? 0);
            $order = max(0, min(9999, $order));

            $settings[$slug] = array(
                'rename' => sanitize_text_field($data['rename'] ?? ''),
                'icon' => $icon,
                'hidden' => $is_hidden,
                'order' => $order,
                'hidden_roles' => array(),
                'hidden_users' => array(),
            );

            // Save hidden roles
            if (!empty($data['hidden_roles']) && is_array($data['hidden_roles'])) {
                $settings[$slug]['hidden_roles'] = array_map('sanitize_key', $data['hidden_roles']);
            }

            // Save hidden users (comma-separated usernames/IDs)
            if (!empty($data['hidden_users'])) {
                $raw_users = sanitize_text_field($data['hidden_users']);
                $users_array = array_map('trim', explode(',', $raw_users));
                $users_array = array_filter($users_array);
                $settings[$slug]['hidden_users'] = array_values($users_array);
            }
        }

        update_option('ofast_menu_editor_settings', $settings, false);
        $this->menu_settings = $settings;
    }

    /**
     * Reset menu editor settings to defaults
     */
    public function reset_settings()
    {
        delete_option('ofast_menu_editor_settings');
        $this->menu_settings = array();
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

        $is_super_admin = current_user_can('manage_options');
        $current_user = wp_get_current_user();
        $user_roles = (array) $current_user->roles;
        $user_login = $current_user->user_login;
        $user_id = (string) $current_user->ID;
        $protected_slugs = $this->get_protected_slugs();

        foreach ($menu as $key => $item) {
            if (empty($item[2]))
                continue;

            $slug = $item[2];

            if (isset($this->menu_settings[$slug])) {
                $settings = $this->menu_settings[$slug];

                // User-based hiding (by username or ID)
                if (!empty($settings['hidden_users']) && is_array($settings['hidden_users'])) {
                    if (in_array($user_login, $settings['hidden_users'], true) || in_array($user_id, $settings['hidden_users'], true)) {
                        if (!in_array($slug, $protected_slugs, true)) {
                            unset($menu[$key]);
                            continue;
                        }
                    }
                }

                // Role-based hiding (applies to non-super-admins)
                if (!$is_super_admin && !empty($settings['hidden_roles']) && is_array($settings['hidden_roles'])) {
                    $matching_roles = array_intersect($user_roles, $settings['hidden_roles']);
                    if (!empty($matching_roles)) {
                        unset($menu[$key]);
                        continue;
                    }
                }

                // Global hide (only for super admin view, as configured)
                if ($is_super_admin && !empty($settings['hidden']) && !in_array($slug, $protected_slugs, true)) {
                    unset($menu[$key]);
                    continue;
                }

                // Rename and icon changes (super admin only)
                if ($is_super_admin) {
                    if (!empty($settings['rename'])) {
                        $menu[$key][0] = esc_html($settings['rename']);
                    }

                    if (!empty($settings['icon']) && strpos($settings['icon'], 'dashicons-') === 0) {
                        $menu[$key][6] = esc_attr($settings['icon']);
                    }
                }
            }
        }

        // Reorder menu items (super admin only)
        if ($is_super_admin) {
            $this->reorder_menu();
        }
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
            if (empty($item[2]))
                continue;

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

        $items_with_order = array();
        $index = 1;

        foreach ($menu_to_display as $item) {
            if (empty($item[0]) || empty($item[2]))
                continue;

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

        usort($items_with_order, function ($a, $b) {
            return $a['order'] - $b['order'];
        });

        return $items_with_order;
    }

    /**
     * Render embedded content for the White Label Updates tab.
     * Outputs menu editor table WITHOUT form wrapper (part of global White Label form).
     */
    public function render_embedded()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $sorted_menu = $this->get_sorted_menu_for_display();

        ?>
        <div class="ofast-card ofast-main-card" style="margin-top: 0;">
            <div class="ofast-card-header" id="ofast-menu-editor-header" style="cursor: pointer; display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-menu-alt3"></span>
                    <h2 style="margin: 0;"><?php esc_html_e('Menu Editor', 'ofast-x'); ?></h2>
                </div>
                <span class="dashicons dashicons-arrow-down-alt2" id="ofast-menu-editor-arrow" style="font-size: 20px; color: #64748b; transition: transform 0.2s;"></span>
            </div>
            <div class="ofast-card-body" id="ofast-menu-editor-body" style="padding: 0; display: none;">
                <div style="overflow-x: auto;">
                    <table class="ofast-modern-table" style="width: 100%;" id="menu-editor-table">
                        <thead>
                            <tr>
                                <th style="width: 40px; text-align: center;"></th>
                                <th><?php esc_html_e('Menu Name', 'ofast-x'); ?></th>
                                <th style="width: 180px;"><?php esc_html_e('Custom Name', 'ofast-x'); ?></th>
                                <th style="width: 130px;"><?php esc_html_e('Icon', 'ofast-x'); ?></th>
                                <th style="width: 70px; text-align: center;">
                                    <?php esc_html_e('Hidden', 'ofast-x'); ?></th>
                                <th style="width: 120px;"><?php esc_html_e('Hide from Roles', 'ofast-x'); ?></th>
                                <th style="width: 160px;"><?php esc_html_e('Hide from Users', 'ofast-x'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="menu-items-list">
                            <?php
                            $order_index = 10;
                            $common_icons = array(
                                '' => '— Default —',
                                'dashicons-admin-home' => 'Home',
                                'dashicons-admin-post' => 'Post',
                                'dashicons-admin-media' => 'Media',
                                'dashicons-admin-links' => 'Links',
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
                                'dashicons-admin-multisite' => 'Multisite',
                                'dashicons-admin-site' => 'Site',
                                'dashicons-admin-site-alt3' => 'Globe',
                                'dashicons-admin-customizer' => 'Customizer',
                                'dashicons-admin-collapse' => 'Collapse',
                                'dashicons-products' => 'Products',
                                'dashicons-cart' => 'Cart',
                                'dashicons-store' => 'Store',
                                'dashicons-money-alt' => 'Money',
                                'dashicons-analytics' => 'Analytics',
                                'dashicons-chart-bar' => 'Chart Bar',
                                'dashicons-chart-line' => 'Chart Line',
                                'dashicons-chart-pie' => 'Chart Pie',
                                'dashicons-chart-area' => 'Chart Area',
                                'dashicons-groups' => 'Groups',
                                'dashicons-email' => 'Email',
                                'dashicons-email-alt' => 'Email Alt',
                                'dashicons-calendar-alt' => 'Calendar',
                                'dashicons-location' => 'Location',
                                'dashicons-location-alt' => 'Location Alt',
                                'dashicons-heart' => 'Heart',
                                'dashicons-star-filled' => 'Star',
                                'dashicons-star-half' => 'Star Half',
                                'dashicons-star-empty' => 'Star Empty',
                                'dashicons-portfolio' => 'Portfolio',
                                'dashicons-shield' => 'Shield',
                                'dashicons-shield-alt' => 'Shield Alt',
                                'dashicons-tag' => 'Tag',
                                'dashicons-category' => 'Category',
                                'dashicons-archive' => 'Archive',
                                'dashicons-format-aside' => 'Aside',
                                'dashicons-format-chat' => 'Chat',
                                'dashicons-format-status' => 'Status',
                                'dashicons-format-quote' => 'Quote',
                                'dashicons-forms' => 'Forms',
                                'dashicons-editor-code' => 'Code',
                                'dashicons-editor-table' => 'Table',
                                'dashicons-editor-bold' => 'Bold',
                                'dashicons-editor-ul' => 'List',
                                'dashicons-media-document' => 'Document',
                                'dashicons-media-spreadsheet' => 'Spreadsheet',
                                'dashicons-media-text' => 'Text File',
                                'dashicons-clipboard' => 'Clipboard',
                                'dashicons-lightbulb' => 'Lightbulb',
                                'dashicons-share' => 'Share',
                                'dashicons-share-alt' => 'Share Alt',
                                'dashicons-networking' => 'Networking',
                                'dashicons-translation' => 'Translation',
                                'dashicons-performance' => 'Performance',
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
                                $hidden_users = isset($settings['hidden_users']) ? implode(', ', $settings['hidden_users']) : '';
                                ?>
                                <tr class="menu-item-row <?php echo $is_hidden ? 'row-hidden' : ''; ?>"
                                    data-slug="<?php echo esc_attr($slug); ?>">
                                    <td class="drag-handle"
                                        style="cursor: move; text-align: center; color: #999; font-size: 16px;">
                                        <span class="dashicons dashicons-menu"></span>
                                    </td>
                                    <td>
                                        <span class="dashicons <?php echo esc_attr($custom_icon ?: $current_icon); ?>"
                                            style="margin-right: 5px; color: #666;"></span>
                                        <strong><?php echo esc_html($name); ?></strong>
                                        <?php if ($is_hidden): ?>
                                            <span style="color: #999; font-size: 11px;"> (hidden)</span>
                                        <?php endif; ?>
                                        <input type="hidden" name="menu_items[<?php echo esc_attr($slug); ?>][order]"
                                            value="<?php echo esc_attr($current_order); ?>" class="order-input">
                                    </td>
                                    <td>
                                        <input type="text" name="menu_items[<?php echo esc_attr($slug); ?>][rename]"
                                            value="<?php echo esc_attr($custom_name); ?>"
                                            placeholder="<?php esc_attr_e('Keep original', 'ofast-x'); ?>"
                                            class="regular-text" style="width: 100%; height: 32px; padding: 4px 8px; box-sizing: border-box;">
                                    </td>
                                    <td class="icon-cell">
                                        <div class="icon-picker-wrapper">
                                            <input type="hidden" name="menu_items[<?php echo esc_attr($slug); ?>][icon]"
                                                class="icon-value" value="<?php echo esc_attr($custom_icon); ?>">
                                            <button type="button" class="button icon-picker-btn"
                                                title="<?php esc_attr_e('Click to change icon', 'ofast-x'); ?>">
                                                <span
                                                    class="dashicons <?php echo esc_attr($custom_icon ?: $current_icon); ?>"></span>
                                                <span
                                                    class="icon-label"><?php echo $custom_icon ? esc_html__('Custom', 'ofast-x') : esc_html__('Default', 'ofast-x'); ?></span>
                                            </button>
                                            <div class="icon-picker-dropdown" style="display: none;">
                                                <div class="icon-picker-search">
                                                    <input type="text"
                                                        placeholder="<?php esc_attr_e('Search icons...', 'ofast-x'); ?>"
                                                        class="icon-search-input">
                                                </div>
                                                <div class="icon-grid">
                                                    <span class="icon-option" data-icon="" title="Default"><span
                                                            class="dashicons dashicons-admin-generic"></span></span>
                                                    <?php foreach ($common_icons as $icon_class => $icon_label):
                                                        if ($icon_class): ?>
                                                            <span class="icon-option"
                                                                data-icon="<?php echo esc_attr($icon_class); ?>"
                                                                title="<?php echo esc_attr($icon_label); ?>">
                                                                <span
                                                                    class="dashicons <?php echo esc_attr($icon_class); ?>"></span>
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
                                                name="menu_items[<?php echo esc_attr($slug); ?>][hidden]" value="1"
                                                <?php checked($is_hidden); ?>>
                                            <span class="ofast-slider"></span>
                                        </label>
                                    </td>
                                    <td>
                                        <?php
                                        $hidden_roles = $settings['hidden_roles'] ?? array();
                                        $all_roles = wp_roles()->get_names();
                                        ?>
                                        <div class="ofast-role-picker" data-slug="<?php echo esc_attr($slug); ?>">
                                            <div class="ofast-role-tags">
                                                <?php foreach ($hidden_roles as $role_key): ?>
                                                    <?php if (isset($all_roles[$role_key])): ?>
                                                        <span
                                                            class="ofast-role-tag"><?php echo esc_html(translate_user_role($all_roles[$role_key])); ?></span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                                <button type="button" class="ofast-role-picker-btn"
                                                    title="<?php esc_attr_e('Select roles', 'ofast-x'); ?>">
                                                    <span class="dashicons dashicons-plus-alt2"></span>
                                                </button>
                                            </div>
                                            <div class="ofast-role-dropdown" style="display: none;">
                                                <?php foreach ($all_roles as $role_key => $role_name): ?>
                                                    <?php if ($role_key === 'administrator')
                                                        continue; ?>
                                                    <label class="ofast-role-option">
                                                        <input type="checkbox"
                                                            name="menu_items[<?php echo esc_attr($slug); ?>][hidden_roles][]"
                                                            value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, $hidden_roles)); ?>>
                                                        <span><?php echo esc_html(translate_user_role($role_name)); ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <input type="text"
                                            name="menu_items[<?php echo esc_attr($slug); ?>][hidden_users]"
                                            value="<?php echo esc_attr($hidden_users); ?>"
                                            placeholder="<?php esc_attr_e('user1, user2', 'ofast-x'); ?>"
                                            class="regular-text" style="width: 100%; font-size: 12px;"
                                            title="<?php esc_attr_e('Comma-separated usernames or user IDs', 'ofast-x'); ?>">
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
        <?php
    }
}
