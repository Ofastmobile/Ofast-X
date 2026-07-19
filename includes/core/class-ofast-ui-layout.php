<?php
/**
 * Reusable UI Layout Component
 *
 * Provides the shared top bar and sidebar navigation used across
 * select module pages. Does NOT include the Upgrade card or Setup
 * Wizard button — those remain exclusive to the main Settings page.
 *
 * @package Ofast_X
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_UI_Layout {
    /**
     * Render the top bar (no Setup Wizard, no Upgrade)
     *
     * @param string $title  Text shown next to the logo.
     */
    public static function render_header($title = 'Ofast Toolkit') {
        ?>
        <div class="wrap ofast-app-wrap">
            <header class="ofast-topbar">
                <div class="ofast-logo">
                    <img src="<?php echo esc_url(OFAST_X_PLUGIN_URL . 'assets/images/toolkit-logo.png'); ?>" alt="Ofast Toolkit Logo" style="height: 40px; width: auto; object-fit: contain;" />
                    <span><?php echo esc_html($title); ?></span>
                </div>
                <div class="header-actions">
                    <a href="https://toolkit.ofastshop.com/docs/index.html" target="_blank" class="action-btn"><span class="dashicons dashicons-book"></span> Documentation</a>
                    <a href="#" class="action-btn">Quick Actions</a>
                </div>
            </header>
            <div class="ofast-app-layout">
        <?php
    }

    /**
     * Render the sidebar with dynamic nav tabs and an optional Save button.
     *
     * @param array  $tabs      Associative array of tab definitions.
     * @param string $form_id   HTML id of the <form> the Save button should submit.
     * @param string $save_name Name attribute for the submit button.
     */
    public static function render_sidebar_start($tabs = array(), $form_id = '', $save_name = 'ofast_module_save') {
        ?>
                <!-- Sidebar -->
                <aside class="ofast-sidebar">
                    <nav class="ofast-nav">
                        <?php foreach ($tabs as $key => $tab): ?>
                            <?php if (!empty($tab['is_section'])): ?>
                                <div class="nav-section"><?php echo esc_html($tab['label']); ?></div>
                            <?php else: ?>
                                <a href="#" class="nav-item ofast-subtab <?php echo !empty($tab['active']) ? 'active' : ''; ?>" data-subtab="<?php echo esc_attr($key); ?>">
                                    <?php if (!empty($tab['icon'])): ?>
                                        <span class="dashicons <?php echo esc_attr($tab['icon']); ?>"></span>
                                    <?php endif; ?>
                                    <?php echo esc_html($tab['label']); ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </nav>
                    
                    <?php if (!empty($form_id)): ?>
                    <div class="ofast-sidebar-actions">
                        <button type="submit" form="<?php echo esc_attr($form_id); ?>" name="<?php echo esc_attr($save_name); ?>" class="ofast-btn-primary" style="width: 100%; display: flex; justify-content: center; align-items: center; gap: 8px;">
                            <span class="dashicons dashicons-saved" style="margin-top: 2px;"></span> Save Settings
                        </button>
                    </div>
                    <?php endif; ?>
                </aside>
                
                <!-- Main Content -->
                <main class="ofast-main">
        <?php
    }

    /**
     * Close the main content area, layout, and wrap.
     */
    public static function render_end() {
        ?>
                </main>
            </div><!-- .ofast-app-layout -->
        </div><!-- .wrap.ofast-app-wrap -->
        <?php
    }
}
