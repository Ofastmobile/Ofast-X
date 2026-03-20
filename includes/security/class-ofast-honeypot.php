<?php

/**
 * Ofast X - Honeypot Spam Protection
 * 
 * Hidden field technique to catch bots without user interaction.
 * Used as fallback when Turnstile/reCAPTCHA is unavailable or fails.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Honeypot
{
    private static $instance = null;
    
    // Field names that attract bots (rotate to avoid detection)
    private static $field_names = array(
        'website_url',
        'your_website', 
        'homepage_url',
        'contact_email2',
        'phone_number2'
    );
    
    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialize honeypot hooks
     */
    public function init()
    {
        // Inject honeypot into forms if enabled
        if (get_option('ofast_spam_honeypot_enabled', true)) {
            // WordPress login form
            add_action('login_form', array($this, 'render_field'));
            add_action('register_form', array($this, 'render_field'));
            add_action('lostpassword_form', array($this, 'render_field'));
            
            // Comments
            add_action('comment_form_after_fields', array($this, 'render_field'));
            
            // Frontend forms via footer (for universal coverage)
            add_action('wp_footer', array($this, 'inject_honeypot_script'));
        }
    }
    
    /**
     * Get the current honeypot field name
     * Rotates daily based on site-specific hash
     */
    public static function get_field_name()
    {
        // Create a daily rotating index based on site URL + date
        $seed = md5(home_url() . date('Y-m-d'));
        $index = hexdec(substr($seed, 0, 4)) % count(self::$field_names);
        return self::$field_names[$index];
    }
    
    /**
     * Render honeypot field HTML
     * Hidden via CSS, invisible to humans, filled by bots
     */
    public function render_field()
    {
        $field_name = self::get_field_name();
        
        // Use multiple CSS hiding techniques to stay hidden
        echo '<div class="ofast-hp-wrap" style="position:absolute!important;left:-9999px!important;height:0!important;width:0!important;overflow:hidden!important;" aria-hidden="true">';
        echo '<label for="' . esc_attr($field_name) . '" style="display:none;">Leave this field empty</label>';
        echo '<input type="text" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '" value="" autocomplete="off" tabindex="-1">';
        echo '</div>';
    }
    
    /**
     * Get honeypot field HTML (for injection)
     */
    public static function get_field_html()
    {
        $field_name = self::get_field_name();
        
        $html = '<div class="ofast-hp-wrap" style="position:absolute!important;left:-9999px!important;height:0!important;width:0!important;overflow:hidden!important;" aria-hidden="true">';
        $html .= '<label for="' . esc_attr($field_name) . '" style="display:none;">Leave empty</label>';
        $html .= '<input type="text" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '" value="" autocomplete="off" tabindex="-1">';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Verify honeypot field wasn't filled
     * 
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function verify()
    {
        $field_name = self::get_field_name();
        
        // If field is empty, verification passes (human)
        if (empty($_POST[$field_name])) {
            return array(
                'success' => true,
                'error' => null,
                'method' => 'honeypot'
            );
        }
        
        // Field was filled - likely a bot
        // Log the attempt
        if (function_exists('error_log')) {
            $ip = self::get_client_ip();
            error_log("Ofast Honeypot: Bot detected from IP {$ip} - filled honeypot field '{$field_name}'");
        }
        
        return array(
            'success' => false,
            'error' => 'Bot activity detected',
            'method' => 'honeypot'
        );
    }
    
    /**
     * Inject honeypot via JavaScript for forms that load dynamically
     */
    public function inject_honeypot_script()
    {
        $field_name = self::get_field_name();
        $field_html = self::get_field_html();
        ?>
        <script>
        (function() {
            'use strict';
            
            var honeypotHtml = <?php echo wp_json_encode($field_html); ?>;
            var honeypotFieldName = <?php echo wp_json_encode($field_name); ?>;
            
            // Form selectors to protect
            var formSelectors = [
                // WordPress
                '#loginform', '#registerform', '#lostpasswordform', '#commentform',
                // WooCommerce
                '.woocommerce-form-login', '.woocommerce-form-register', '.woocommerce-checkout',
                // Tutor LMS
                '.tutor-login-form', '.tutor-registration-form', 'form.tutor-login-form-wrap',
                // BuddyPress
                '#buddypress #signup_form', '#buddypress .standard-form',
                // Generic
                'form[action*="login"]', 'form[action*="register"]', 'form[action*="signup"]'
            ];
            
            function injectHoneypot(form) {
                // Skip if already has honeypot
                if (form.querySelector('.ofast-hp-wrap') || form.dataset.ofastHp) {
                    return;
                }
                
                // Mark as processed
                form.dataset.ofastHp = 'true';
                
                // Find submit button and insert before it
                var submit = form.querySelector('input[type="submit"], button[type="submit"], .submit');
                if (submit) {
                    submit.insertAdjacentHTML('beforebegin', honeypotHtml);
                } else {
                    form.insertAdjacentHTML('beforeend', honeypotHtml);
                }
            }
            
            function scanForms() {
                formSelectors.forEach(function(selector) {
                    var forms = document.querySelectorAll(selector);
                    forms.forEach(injectHoneypot);
                });
            }
            
            // Initial scan
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', scanForms);
            } else {
                scanForms();
            }
            
            // Watch for dynamically added forms
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.addedNodes.length) {
                        scanForms();
                    }
                });
            });
            
            observer.observe(document.body, { childList: true, subtree: true });
        })();
        </script>
        <?php
    }
    
    /**
     * Get client IP address
     */
    private static function get_client_ip()
    {
        $ip_headers = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        );

        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }
}
