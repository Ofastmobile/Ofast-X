<?php

/**
 * Ofast X - Custom Admin Footer Module
 * Dark Mode toggle and Custom Dashboard feature
 * NOTE: Footer text settings moved to White Label module
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Admin_Footer
{
    /**
     * Initialize module
     */
    public function init()
    {


        $settings = get_option('ofast_admin_footer_settings', array());

        // NOTE: Footer text filters moved to White Label module (class-ofast-whos-admin.php)
        // This module now only handles Dark Mode toggle

        // Dark Mode Toggle
        if (!empty($settings['enable_dark_mode'])) {
            add_action('admin_bar_menu', array($this, 'add_dark_mode_toggle'), 999);
            add_action('admin_enqueue_scripts', array($this, 'enqueue_dark_mode_scripts'));
            add_action('wp_ajax_ofast_toggle_dark_mode', array($this, 'ajax_toggle_dark_mode'));
        }
    }

    /**
     * Add Dark Mode Toggle to Admin Bar
     */
    public function add_dark_mode_toggle($wp_admin_bar)
    {
        $is_dark = get_user_meta(get_current_user_id(), 'ofast_dark_mode', true);
        $icon = $is_dark ? 'dashicons-sun' : 'dashicons-moon';
        $title = $is_dark ? 'Light Mode' : 'Dark Mode';

        $wp_admin_bar->add_node(array(
            'id'    => 'ofast-dark-mode',
            'title' => '<span class="ab-icon dashicons ' . $icon . '" style="margin-top: 4px;"></span><span class="ab-label">' . $title . '</span>',
            'href'  => '#',
            'meta'  => array(
                'onclick' => 'return false;',
                'class'   => 'ofast-dark-mode-toggle',
                'title'   => 'Toggle Dark Mode'
            ),
        ));
    }

    /**
     * AJAX Handler for toggling dark mode
     */
    public function ajax_toggle_dark_mode()
    {
        check_ajax_referer('ofast_dark_mode_nonce', 'nonce');
        
        $current_mode = get_user_meta(get_current_user_id(), 'ofast_dark_mode', true);
        $new_mode = !$current_mode;
        
        update_user_meta(get_current_user_id(), 'ofast_dark_mode', $new_mode);
        
        wp_send_json_success(array('is_dark' => $new_mode));
    }

    /**
     * Enqueue Dark Mode Scripts & Styles
     */
    public function enqueue_dark_mode_scripts()
    {
        $is_dark = get_user_meta(get_current_user_id(), 'ofast_dark_mode', true);
        
        // CSS Variables for Dark Mode
        $css = "
            :root {
                --ofast-dark-bg: #111827;
                --ofast-dark-card: #1f2937;
                --ofast-dark-text: #f3f4f6;
                --ofast-dark-border: #374151;
            }
            
            body.ofast-dark-mode {
                background: var(--ofast-dark-bg) !important;
                color: var(--ofast-dark-text) !important;
            }
            body.ofast-dark-mode #wpadminbar,
            body.ofast-dark-mode #adminmenu,
            body.ofast-dark-mode #adminmenuback,
            body.ofast-dark-mode #adminmenuwrap {
                background: #000 !important;
            }
            body.ofast-dark-mode .postbox,
            body.ofast-dark-mode .wrap .ofast-card,
            body.ofast-dark-mode #wpbody-content .wrap {
                background-color: var(--ofast-dark-card) !important;
                color: var(--ofast-dark-text) !important;
                border-color: var(--ofast-dark-border) !important;
            }
            body.ofast-dark-mode input,
            body.ofast-dark-mode textarea,
            body.ofast-dark-mode select {
                background-color: #374151 !important;
                color: #fff !important;
                border-color: #4b5563 !important;
            }
            body.ofast-dark-mode a {
                color: #818cf8;
            }
            body.ofast-dark-mode h1, body.ofast-dark-mode h2, body.ofast-dark-mode h3 {
                color: #fff !important;
            }
        ";

        if ($is_dark) {
            $css .= "body { background: #111827 !important; }"; // Instant apply to prevent flash
        }

        wp_add_inline_style('common', $css);

        // JS for Toggle
        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', "
            jQuery(document).ready(function($) {
                var isDark = " . ($is_dark ? 'true' : 'false') . ";
                if(isDark) $('body').addClass('ofast-dark-mode');

                $('#wp-admin-bar-ofast-dark-mode').on('click', function(e) {
                    e.preventDefault();
                    
                    $('body').toggleClass('ofast-dark-mode');
                    isDark = !isDark;
                    
                    // Update Text & Icon
                    var label = isDark ? 'Light Mode' : 'Dark Mode';
                    var iconRemove = isDark ? 'dashicons-moon' : 'dashicons-sun';
                    var iconAdd = isDark ? 'dashicons-sun' : 'dashicons-moon';
                    
                    $(this).find('.ab-label').text(label);
                    $(this).find('.ab-icon').removeClass(iconRemove).addClass(iconAdd);
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'ofast_toggle_dark_mode',
                            nonce: '" . wp_create_nonce('ofast_dark_mode_nonce') . "'
                        }
                    });
                });
            });
        ");
    }
}
