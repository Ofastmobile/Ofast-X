<?php
/**
 * Ofast X - Modern Toast Notifications
 * Centralized toast notification system for the entire plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Toast {
    private static $instance = null;
    private static $toast_queue = array();
    private static $styles_enqueued = false;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Enqueue styles and scripts on admin pages
        add_action('admin_footer', array($this, 'render_toast_container'));
        add_action('wp_footer', array($this, 'render_toast_container'));
    }

    /**
     * Queue a toast notification
     * 
     * @param string $message The message to display
     * @param string $type 'success', 'error', 'warning', or 'info'
     */
    public static function add($message, $type = 'success') {
        self::$toast_queue[] = array(
            'message' => $message,
            'type' => $type
        );
    }

    /**
     * Get inline CSS for toast notifications
     */
    public static function get_styles() {
        return '
        <style id="ofast-toast-styles">
            .ofast-toast-container {
                position: fixed;
                top: 50px;
                right: 20px;
                z-index: 999999;
                display: flex;
                flex-direction: column;
                gap: 10px;
                pointer-events: none;
            }
            .ofast-toast {
                padding: 16px 24px;
                border-radius: 10px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                display: flex;
                align-items: center;
                gap: 12px;
                font-size: 14px;
                font-weight: 500;
                color: #fff;
                animation: ofastToastSlideIn 0.3s ease;
                max-width: 400px;
                pointer-events: auto;
            }
            .ofast-toast.success {
                background: #10b981;
            }
            .ofast-toast.error {
                background: #ef4444;
            }
            .ofast-toast.warning {
                background: #f59e0b;
            }
            .ofast-toast.info {
                background: #3b82f6;
            }
            .ofast-toast-icon {
                font-size: 20px;
                flex-shrink: 0;
            }
            .ofast-toast-message {
                flex: 1;
                word-break: break-word;
            }
            .ofast-toast-close {
                background: none;
                border: none;
                color: #fff;
                font-size: 18px;
                cursor: pointer;
                margin-left: 10px;
                opacity: 0.8;
                padding: 0;
                line-height: 1;
                flex-shrink: 0;
            }
            .ofast-toast-close:hover {
                opacity: 1;
            }
            .ofast-toast.hiding {
                animation: ofastToastSlideOut 0.3s ease forwards;
            }
            @keyframes ofastToastSlideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes ofastToastSlideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        </style>';
    }

    /**
     * Get JavaScript for toast functionality
     */
    public static function get_script() {
        return '
        <script id="ofast-toast-script">
            window.ofastToast = {
                show: function(message, type) {
                    type = type || "success";
                    var container = document.getElementById("ofast-toast-container");
                    if (!container) {
                        container = document.createElement("div");
                        container.id = "ofast-toast-container";
                        container.className = "ofast-toast-container";
                        document.body.appendChild(container);
                    }
                    
                    var icons = {
                        success: "✓",
                        error: "✗",
                        warning: "⚠",
                        info: "ℹ"
                    };
                    
                    var toast = document.createElement("div");
                    toast.className = "ofast-toast " + type;
                    toast.innerHTML = \'<span class="ofast-toast-icon">\' + (icons[type] || "✓") + \'</span>\' +
                        \'<span class="ofast-toast-message">\' + message + \'</span>\' +
                        \'<button type="button" class="ofast-toast-close">&times;</button>\';
                    
                    container.appendChild(toast);
                    
                    var closeToast = function() {
                        toast.classList.add("hiding");
                        setTimeout(function() { toast.remove(); }, 300);
                    };
                    
                    toast.querySelector(".ofast-toast-close").addEventListener("click", closeToast);
                    
                    setTimeout(closeToast, 5000);
                },
                success: function(message) { this.show(message, "success"); },
                error: function(message) { this.show(message, "error"); },
                warning: function(message) { this.show(message, "warning"); },
                info: function(message) { this.show(message, "info"); }
            };
        </script>';
    }

    /**
     * Render toast container and any queued toasts
     */
    public function render_toast_container() {
        if (!self::$styles_enqueued) {
            echo self::get_styles();
            echo self::get_script();
            self::$styles_enqueued = true;
        }

        if (!empty(self::$toast_queue)) {
            echo '<div id="ofast-toast-container" class="ofast-toast-container">';
            foreach (self::$toast_queue as $toast) {
                $icons = array(
                    'success' => '✓',
                    'error' => '✗',
                    'warning' => '⚠',
                    'info' => 'ℹ'
                );
                $icon = isset($icons[$toast['type']]) ? $icons[$toast['type']] : '✓';
                ?>
                <div class="ofast-toast <?php echo esc_attr($toast['type']); ?>">
                    <span class="ofast-toast-icon"><?php echo $icon; ?></span>
                    <span class="ofast-toast-message"><?php echo esc_html($toast['message']); ?></span>
                    <button type="button" class="ofast-toast-close">&times;</button>
                </div>
                <?php
            }
            echo '</div>';
            
            // Auto-dismiss script
            echo '<script>
                document.addEventListener("DOMContentLoaded", function() {
                    var toasts = document.querySelectorAll(".ofast-toast");
                    toasts.forEach(function(toast, index) {
                        var closeBtn = toast.querySelector(".ofast-toast-close");
                        var closeToast = function() {
                            toast.classList.add("hiding");
                            setTimeout(function() { toast.remove(); }, 300);
                        };
                        closeBtn.addEventListener("click", closeToast);
                        setTimeout(closeToast, 5000 + (index * 200));
                    });
                });
            </script>';
            
            self::$toast_queue = array();
        }
    }

    /**
     * Helper to render inline toast (for immediate echo)
     * Returns HTML that can be echoed directly
     */
    public static function render($message, $type = 'success') {
        $icons = array(
            'success' => '✓',
            'error' => '✗',
            'warning' => '⚠',
            'info' => 'ℹ'
        );
        $icon = isset($icons[$type]) ? $icons[$type] : '✓';
        
        $html = self::get_styles();
        $html .= self::get_script();
        $html .= '<script>
            document.addEventListener("DOMContentLoaded", function() {
                if (window.ofastToast) {
                    ofastToast.show("' . esc_js($message) . '", "' . esc_js($type) . '");
                }
            });
        </script>';
        
        return $html;
    }
}

// Initialize the toast system
Ofast_X_Toast::get_instance();
