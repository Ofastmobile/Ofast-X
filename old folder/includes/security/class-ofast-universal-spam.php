<?php

/**
 * Ofast X - Universal Spam Protection
 * 
 * Injects Turnstile/reCAPTCHA into all login/registration forms
 * via JavaScript footer injection. Works with any plugin's forms.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Universal_Spam
{
    private static $instance = null;
    
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
     * Initialize universal spam protection
     */
    public function init()
    {
        // Check if force-all is enabled
        if (!$this->should_force_forms()) {
            return;
        }
        
        // Frontend pages
        add_action('wp_footer', array($this, 'inject_to_footer'), 999);
        
        // Login page
        add_action('login_footer', array($this, 'inject_to_footer'), 999);
        
        // Enqueue Turnstile script
        add_action('wp_enqueue_scripts', array($this, 'enqueue_turnstile_script'));
        add_action('login_enqueue_scripts', array($this, 'enqueue_turnstile_script'));
        
        // Hook into authenticate for server-side verification
        add_filter('authenticate', array($this, 'verify_universal_login'), 25, 3);
        
        // Hook into user registration
        add_filter('registration_errors', array($this, 'verify_universal_registration'), 10, 3);
        
        // WooCommerce hooks
        add_action('woocommerce_login_form', array($this, 'wc_render_turnstile'));
        add_action('woocommerce_register_form', array($this, 'wc_render_turnstile'));
        add_filter('woocommerce_process_login_errors', array($this, 'verify_wc_form'), 10, 3);
        add_filter('woocommerce_registration_errors', array($this, 'verify_wc_form'), 10, 3);
    }
    
    /**
     * Check if force-all forms is enabled
     */
    private function should_force_forms()
    {
        return get_option('ofast_spam_force_all_forms', false);
    }
    
    /**
     * Get the active spam provider
     */
    private function get_provider()
    {
        return get_option('ofast_spam_provider', 'turnstile');
    }
    
    /**
     * Check if spam protection is configured
     */
    private function is_configured()
    {
        if (class_exists('Ofast_X_Spam_Protection')) {
            $spam = new Ofast_X_Spam_Protection();
            return $spam->is_configured();
        }
        return false;
    }
    
    /**
     * Enqueue Turnstile script
     */
    public function enqueue_turnstile_script()
    {
        if ($this->get_provider() === 'turnstile' && class_exists('Ofast_X_Turnstile')) {
            echo Ofast_X_Turnstile::render_script();
        }
    }
    
    /**
     * Render Turnstile widget for WooCommerce forms
     */
    public function wc_render_turnstile()
    {
        if ($this->get_provider() === 'turnstile' && class_exists('Ofast_X_Turnstile')) {
            echo '<div class="form-row form-row-wide ofast-turnstile-wc" style="margin: 15px 0;">';
            echo Ofast_X_Turnstile::get_instance()->render_widget('wc');
            echo '</div>';
        }
    }
    
    /**
     * Verify WooCommerce form submission
     */
    public function verify_wc_form($errors, $username = '', $email = '')
    {
        $token = isset($_POST['cf-turnstile-response']) ? sanitize_text_field($_POST['cf-turnstile-response']) : '';
        
        $result = $this->verify_with_fallback($token);
        
        if (!$result['success']) {
            if (is_wp_error($errors)) {
                $errors->add('spam_failed', '<strong>Security check failed:</strong> ' . esc_html($result['error']));
            }
        }
        
        return $errors;
    }
    
    /**
     * Verify login with fallback to honeypot
     */
    public function verify_universal_login($user, $username, $password)
    {
        // Skip if not a real login attempt
        if (empty($username) && empty($password)) {
            return $user;
        }
        
        // Skip if already an error (but not spam error)
        if (is_wp_error($user) && !in_array('spam_protection_failed', $user->get_error_codes())) {
            return $user;
        }
        
        $token = isset($_POST['cf-turnstile-response']) ? sanitize_text_field($_POST['cf-turnstile-response']) : '';
        
        $result = $this->verify_with_fallback($token);
        
        if (!$result['success']) {
            return new WP_Error(
                'spam_protection_failed',
                '<strong>Security check failed:</strong> ' . esc_html($result['error'])
            );
        }
        
        return $user;
    }
    
    /**
     * Verify registration with fallback to honeypot
     */
    public function verify_universal_registration($errors, $sanitized_user_login, $user_email)
    {
        $token = isset($_POST['cf-turnstile-response']) ? sanitize_text_field($_POST['cf-turnstile-response']) : '';
        
        $result = $this->verify_with_fallback($token);
        
        if (!$result['success']) {
            $errors->add('spam_failed', '<strong>Security check failed:</strong> ' . esc_html($result['error']));
        }
        
        return $errors;
    }
    
    /**
     * Verify token with fallback to honeypot
     */
    private function verify_with_fallback($token)
    {
        // Try primary verification (Turnstile/reCAPTCHA)
        if (!empty($token) && $this->is_configured()) {
            if (class_exists('Ofast_X_Spam_Protection')) {
                $spam = new Ofast_X_Spam_Protection();
                $result = $spam->verify($token);
                
                if ($result['success']) {
                    return $result;
                }
            }
        }
        
        // Fallback to honeypot if enabled
        if (class_exists('Ofast_X_Honeypot') && get_option('ofast_spam_honeypot_enabled', true)) {
            $honeypot_result = Ofast_X_Honeypot::verify();
            
            // If honeypot passes, we allow through (secondary protection)
            if ($honeypot_result['success']) {
                // Log that we're using fallback
                error_log('Ofast Spam: Using honeypot fallback - primary protection unavailable');
                return $honeypot_result;
            }
            
            return $honeypot_result;
        }
        
        // No token and no honeypot - STRICT: Block
        if (empty($token) && $this->is_configured()) {
            return array(
                'success' => false,
                'error' => 'Security verification required. Please complete the challenge.'
            );
        }
        
        // Not configured - allow through but log warning
        error_log('Ofast Spam: Protection not configured - allowing request through');
        return array('success' => true, 'skipped' => true);
    }
    
    /**
     * Inject Turnstile to footer via JavaScript
     */
    public function inject_to_footer()
    {
        if (!$this->is_configured()) {
            return;
        }
        
        $provider = $this->get_provider();
        
        if ($provider === 'turnstile' && class_exists('Ofast_X_Turnstile')) {
            $site_key = Ofast_X_Turnstile::get_instance()->get_site_key();
            $this->render_injection_script($site_key, 'turnstile');
        }
    }
    
    /**
     * Render the JavaScript injection script
     */
    private function render_injection_script($site_key, $type)
    {
        ?>
        <script>
        (function() {
            'use strict';
            
            var siteKey = '<?php echo esc_js($site_key); ?>';
            var protectionType = '<?php echo esc_js($type); ?>';
            
            // Form selectors to inject protection into
            // NOTE: Tutor LMS requires their Pro plugin for CAPTCHA - excluded to avoid conflicts
            var formSelectors = [
                // WordPress Core
                '#loginform',
                '#registerform', 
                '#lostpasswordform',
                
                // WooCommerce
                '.woocommerce-form-login',
                '.woocommerce-form-register',
                'form.woocommerce-checkout',
                
                // BuddyPress / BuddyBoss
                '#buddypress form#signup_form',
                '#buddypress .standard-form',
                
                // MemberPress
                '.mepr-login-form',
                '.mepr-signup-form',
                'form#mepr_loginform',
                
                // Ultimate Member
                '.um-form',
                '.um-login form',
                '.um-register form',
                
                // WP Members
                '#wpmem_login',
                '#wpmem_reg',
                
                // Profile Builder
                '.wppb-user-forms',
                
                // Generic fallbacks (catch-all for standard WP forms)
                'form[action*="/wp-login.php"]'
            ];
            
            // Elements to ignore (avoid duplicates)
            var ignoreSelectors = [
                '.ofast-protected',
                '[data-ofast-protected]'
            ];
            
            function createTurnstileWidget() {
                var wrapper = document.createElement('div');
                wrapper.className = 'ofast-turnstile-injected';
                wrapper.style.cssText = 'margin: 15px 0; clear: both;';
                
                var widget = document.createElement('div');
                widget.className = 'cf-turnstile';
                widget.setAttribute('data-sitekey', siteKey);
                widget.setAttribute('data-theme', 'light');
                widget.setAttribute('data-size', 'normal');
                
                wrapper.appendChild(widget);
                return wrapper;
            }
            
            function injectProtection(form) {
                // Skip if already protected
                if (form.classList.contains('ofast-protected') || form.dataset.ofastProtected) {
                    return;
                }
                
                // Skip if form already has a Turnstile widget
                if (form.querySelector('.cf-turnstile')) {
                    return;
                }
                
                // Mark as protected
                form.classList.add('ofast-protected');
                form.dataset.ofastProtected = 'true';
                
                // Create and insert widget
                var widget = createTurnstileWidget();
                
                // Find best insertion point (before submit button)
                var submitBtn = form.querySelector(
                    'input[type="submit"], ' +
                    'button[type="submit"], ' +
                    '.submit, ' +
                    '.woocommerce-form-login__submit, ' +
                    '.woocommerce-form-register__submit'
                );
                
                if (submitBtn) {
                    submitBtn.parentNode.insertBefore(widget, submitBtn);
                } else {
                    form.appendChild(widget);
                }
                
                // Re-render Turnstile widget if API is loaded
                if (typeof turnstile !== 'undefined') {
                    turnstile.render(widget.querySelector('.cf-turnstile'));
                }
                
                console.log('Ofast Spam: Protected form -', form.id || form.className);
            }
            
            function scanAndProtect() {
                formSelectors.forEach(function(selector) {
                    try {
                        var forms = document.querySelectorAll(selector);
                        forms.forEach(function(form) {
                            // Make sure it's actually a form element
                            if (form.tagName === 'FORM') {
                                injectProtection(form);
                            } else {
                                // It might be a wrapper, find the form inside
                                var innerForm = form.querySelector('form');
                                if (innerForm) {
                                    injectProtection(innerForm);
                                }
                            }
                        });
                    } catch (e) {
                        // Ignore selector errors
                    }
                });
            }
            
            // Initial scan when DOM is ready
            function init() {
                scanAndProtect();
                
                // Watch for dynamically added forms (AJAX, modals, etc.)
                var observer = new MutationObserver(function(mutations) {
                    var shouldScan = false;
                    mutations.forEach(function(m) {
                        if (m.addedNodes.length > 0) {
                            shouldScan = true;
                        }
                    });
                    if (shouldScan) {
                        // Debounce
                        clearTimeout(window.ofastScanTimeout);
                        window.ofastScanTimeout = setTimeout(scanAndProtect, 100);
                    }
                });
                
                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
            }
            
            // Start when ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
            
            // Also try after a delay for slow-loading content
            setTimeout(scanAndProtect, 1000);
            setTimeout(scanAndProtect, 3000);
        })();
        </script>
        <?php
    }
    
    /**
     * Get list of protected form types for admin display
     */
    public static function get_supported_forms()
    {
        return array(
            'woocommerce' => array(
                'name' => 'WooCommerce',
                'forms' => array('Login', 'Register', 'Checkout'),
                'option' => 'ofast_spam_protect_woocommerce'
            ),
            'tutor_lms' => array(
                'name' => 'Tutor LMS',
                'forms' => array('Login', 'Registration'),
                'option' => 'ofast_spam_protect_tutor'
            ),
            'buddypress' => array(
                'name' => 'BuddyPress / BuddyBoss',
                'forms' => array('Login', 'Registration'),
                'option' => 'ofast_spam_protect_buddypress'
            ),
            'memberpress' => array(
                'name' => 'MemberPress',
                'forms' => array('Login', 'Signup'),
                'option' => 'ofast_spam_protect_memberpress'
            ),
            'ultimate_member' => array(
                'name' => 'Ultimate Member',
                'forms' => array('Login', 'Register'),
                'option' => 'ofast_spam_protect_um'
            )
        );
    }
}
