<?php
/**
 * Ofast X - Unified Button Component
 * Centralized button styling system for consistent UI across the plugin
 * 
 * Usage:
 *   echo Ofast_X_Button::get_styles();  // Include once per page
 *   echo Ofast_X_Button::render_primary('Save Settings', ['name' => 'save', 'type' => 'submit']);
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Button {
    
    private static $styles_included = false;

    /**
     * Brand Colors - Matching Settings Page
     * These are the unified colors used throughout Ofast X
     */
    const PRIMARY_START = '#6366f1';      // Gradient start
    const PRIMARY_END = '#4f46e5';        // Gradient end
    const PRIMARY_HOVER_START = '#4f46e5'; // Hover gradient start
    const PRIMARY_HOVER_END = '#4338ca';   // Hover gradient end
    const SHADOW_RGB = '99, 102, 241';     // For rgba shadows
    
    const DANGER_COLOR = '#ef4444';
    const DANGER_HOVER = '#dc2626';
    const DANGER_BORDER = '#fecaca';
    
    const SECONDARY_BG = '#ffffff';
    const SECONDARY_BORDER = '#e5e7eb';
    const SECONDARY_TEXT = '#374151';

    /**
     * Get the unified button CSS styles
     * Include this once per admin page that uses buttons
     * 
     * @return string CSS styles wrapped in <style> tag
     */
    public static function get_styles() {
        return '
        <style id="ofast-button-styles">
            /* === Ofast X Unified Button Styles === */
            
            /* Base Button */
            .ofast-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                padding: 14px 32px;
                font-size: 15px;
                font-weight: 600;
                border-radius: 10px;
                cursor: pointer;
                transition: all 0.3s ease;
                text-decoration: none;
                border: none;
                line-height: 1.2;
            }
            
            /* Primary Button - Gradient Style (Settings Page Match) */
            .ofast-btn-primary,
            .ofast-save-btn {
                background: linear-gradient(135deg, ' . self::PRIMARY_START . ' 0%, ' . self::PRIMARY_END . ' 100%);
                color: #fff;
                border: none;
                padding: 14px 32px;
                font-size: 15px;
                font-weight: 600;
                border-radius: 10px;
                cursor: pointer;
                transition: all 0.3s ease;
                box-shadow: 0 4px 15px rgba(' . self::SHADOW_RGB . ', 0.3);
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                text-decoration: none;
            }
            
            .ofast-btn-primary:hover,
            .ofast-btn-primary:focus,
            .ofast-save-btn:hover,
            .ofast-save-btn:focus {
                background: linear-gradient(135deg, ' . self::PRIMARY_HOVER_START . ' 0%, ' . self::PRIMARY_HOVER_END . ' 100%);
                box-shadow: 0 6px 20px rgba(' . self::SHADOW_RGB . ', 0.4);
                transform: translateY(-2px);
                color: #fff;
            }
            
            .ofast-btn-primary:disabled,
            .ofast-save-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
                transform: none;
            }
            
            /* Secondary Button */
            .ofast-btn-secondary {
                background: ' . self::SECONDARY_BG . ';
                color: ' . self::SECONDARY_TEXT . ';
                border: 2px solid ' . self::SECONDARY_BORDER . ';
                padding: 12px 24px;
                font-size: 14px;
                font-weight: 600;
                border-radius: 10px;
                cursor: pointer;
                transition: all 0.3s ease;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                text-decoration: none;
            }
            
            .ofast-btn-secondary:hover,
            .ofast-btn-secondary:focus {
                border-color: ' . self::PRIMARY_START . ';
                color: ' . self::PRIMARY_START . ';
                background: #f8fafc;
                transform: translateY(-1px);
            }
            
            /* Danger Button */
            .ofast-btn-danger,
            .ofast-reset-btn {
                background: ' . self::SECONDARY_BG . ';
                color: ' . self::DANGER_COLOR . ';
                border: 2px solid ' . self::DANGER_BORDER . ';
                padding: 12px 24px;
                font-size: 14px;
                font-weight: 600;
                border-radius: 10px;
                cursor: pointer;
                transition: all 0.3s ease;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                text-decoration: none;
            }
            
            .ofast-btn-danger:hover,
            .ofast-btn-danger:focus,
            .ofast-reset-btn:hover,
            .ofast-reset-btn:focus {
                background: #fef2f2;
                border-color: ' . self::DANGER_COLOR . ';
                color: ' . self::DANGER_HOVER . ';
            }
            
            /* Size Variants */
            .ofast-btn-sm {
                padding: 8px 16px;
                font-size: 13px;
            }
            
            .ofast-btn-lg {
                padding: 16px 40px;
                font-size: 17px;
            }
            
            /* Loading State with Rolling Spinner */
            .ofast-btn-loading {
                pointer-events: none;
                position: relative;
            }
            
            .ofast-btn-loading .ofast-btn-text {
                visibility: hidden;
            }
            
            .ofast-btn-loading::after {
                content: "";
                position: absolute;
                width: 18px;
                height: 18px;
                border: 2px solid transparent;
                border-top-color: currentColor;
                border-radius: 50%;
                animation: ofast-btn-spin 0.8s linear infinite;
            }
            
            @keyframes ofast-btn-spin {
                to { transform: rotate(360deg); }
            }
            
            /* Dashicons in buttons */
            .ofast-btn .dashicons,
            .ofast-btn-primary .dashicons,
            .ofast-btn-secondary .dashicons,
            .ofast-btn-danger .dashicons {
                font-size: 18px;
                width: 18px;
                height: 18px;
                line-height: 18px;
            }
            
            /* Icon Colors - matching brand */
            .ofast-icon-primary {
                color: ' . self::PRIMARY_START . ';
            }
        </style>';
    }

    /**
     * Render a primary button (gradient style)
     * 
     * @param string $text Button text
     * @param array $attrs Additional HTML attributes
     * @return string HTML button element
     */
    public static function render_primary($text, $attrs = array()) {
        $defaults = array(
            'type' => 'submit',
            'class' => '',
        );
        $attrs = array_merge($defaults, $attrs);
        $attrs['class'] = 'ofast-btn ofast-btn-primary ' . $attrs['class'];
        
        return self::render_button($text, $attrs);
    }

    /**
     * Render a secondary button (outline style)
     * 
     * @param string $text Button text
     * @param array $attrs Additional HTML attributes
     * @return string HTML button element
     */
    public static function render_secondary($text, $attrs = array()) {
        $defaults = array(
            'type' => 'button',
            'class' => '',
        );
        $attrs = array_merge($defaults, $attrs);
        $attrs['class'] = 'ofast-btn ofast-btn-secondary ' . $attrs['class'];
        
        return self::render_button($text, $attrs);
    }

    /**
     * Render a danger button (for destructive actions)
     * 
     * @param string $text Button text
     * @param array $attrs Additional HTML attributes
     * @return string HTML button element
     */
    public static function render_danger($text, $attrs = array()) {
        $defaults = array(
            'type' => 'button',
            'class' => '',
        );
        $attrs = array_merge($defaults, $attrs);
        $attrs['class'] = 'ofast-btn ofast-btn-danger ' . $attrs['class'];
        
        return self::render_button($text, $attrs);
    }

    /**
     * Internal: Build the button HTML
     */
    private static function render_button($text, $attrs) {
        $html = '<button';
        
        foreach ($attrs as $key => $value) {
            if ($value !== '' && $value !== null) {
                $html .= ' ' . esc_attr($key) . '="' . esc_attr($value) . '"';
            }
        }
        
        $html .= '><span class="ofast-btn-text">' . esc_html($text) . '</span></button>';
        
        return $html;
    }

    /**
     * Get JavaScript for button loading states
     * Include once per page if using loading states
     * 
     * @return string JavaScript wrapped in <script> tag
     */
    public static function get_loading_script() {
        return '
        <script id="ofast-button-loading-script">
            window.ofastButton = {
                setLoading: function(button, isLoading) {
                    if (isLoading) {
                        button.classList.add("ofast-btn-loading");
                        button.disabled = true;
                    } else {
                        button.classList.remove("ofast-btn-loading");
                        button.disabled = false;
                    }
                }
            };
        </script>';
    }

    /**
     * Get CSS variable definitions for use in custom styles
     * This allows modules to reference the brand colors without hardcoding
     * 
     * @return string CSS custom properties
     */
    public static function get_css_variables() {
        return '
            --ofast-primary: ' . self::PRIMARY_START . ';
            --ofast-primary-end: ' . self::PRIMARY_END . ';
            --ofast-primary-hover: ' . self::PRIMARY_HOVER_START . ';
            --ofast-primary-hover-end: ' . self::PRIMARY_HOVER_END . ';
            --ofast-shadow-rgb: ' . self::SHADOW_RGB . ';
            --ofast-danger: ' . self::DANGER_COLOR . ';
            --ofast-danger-hover: ' . self::DANGER_HOVER . ';
        ';
    }
}
