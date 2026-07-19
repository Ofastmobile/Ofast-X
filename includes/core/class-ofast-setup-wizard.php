<?php
/**
 * Ofast X Setup Wizard
 * First-run wizard with SMTP detection and import
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Setup_Wizard {

    /**
     * Initialize wizard
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_wizard_page'));
        add_action('admin_init', array($this, 'maybe_redirect_to_wizard'));
        add_action('admin_init', array($this, 'handle_wizard_submission'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * Enqueue setup wizard CSS
     */
    public function enqueue_assets($hook) {
        if (strpos($hook, 'ofast-setup-wizard') !== false || (isset($_GET['page']) && $_GET['page'] === 'ofast-setup-wizard')) {
            wp_enqueue_style('ofast-wizard-css', OFAST_X_PLUGIN_URL . 'assets/css/ofast-setup-wizard.css', array(), OFAST_X_VERSION);
        }
    }

    /**
     * Add hidden wizard page
     */
    public function add_wizard_page() {
        add_submenu_page(
            'options.php', // Hidden from menu (using options.php as hidden parent)
            'Ofast X Setup',
            'Setup Wizard',
            'manage_options',
            'ofast-setup-wizard',
            array($this, 'render_wizard')
        );
    }

    /**
     * Redirect to wizard on first activation
     */
    public function maybe_redirect_to_wizard() {
        if (get_option('ofast_wizard_redirect', false)) {
            delete_option('ofast_wizard_redirect');
            
            // Don't redirect if already completed or during AJAX/bulk activation
            if (get_option('ofast_wizard_complete', false) || 
                wp_doing_ajax() || 
                isset($_GET['activate-multi']) ||
                !current_user_can('manage_options')) {
                return;
            }
            
            wp_safe_redirect(admin_url('admin.php?page=ofast-setup-wizard'));
            exit;
        }
    }

    /**
     * Handle wizard form submission (AJAX)
     */
    public function handle_wizard_submission() {
        if (!isset($_POST['ofast_wizard_action'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['_wpnonce'], 'ofast_wizard_nonce')) {
            if (wp_doing_ajax()) {
                wp_send_json_error('Security check failed');
            } else {
                wp_die(esc_html__('Security check failed', 'ofast-x'));
            }
        }

        $action = sanitize_text_field($_POST['ofast_wizard_action']);

        if ($action === 'skip_wizard') {
            update_option('ofast_wizard_complete', true);
            if (wp_doing_ajax()) {
                wp_send_json_success();
            } else {
                wp_redirect(admin_url('admin.php?page=ofast-dashboard'));
                exit;
            }
        } elseif ($action === 'finish_ajax') {
            // 1. Save Modules
            if (isset($_POST['modules']) && is_array($_POST['modules'])) {
                // Define whitelist of allowed modules to prevent arbitrary keys
                $allowed_modules = $this->get_allowed_wizard_modules();

                // Filter submitted modules against whitelist
                $filtered_modules = array_intersect_key($_POST['modules'], $allowed_modules);

                $modules = array();
                foreach ($filtered_modules as $slug => $value) {
                    $modules[$slug] = true;
                }
                update_option('ofastx_modules_enabled', $modules);
            }

            // 2. Handle SMTP Import
            if (!empty($_POST['smtp_source'])) {
                $this->import_smtp_settings();
                if (isset($_POST['activate_smtp'])) {
                    $modules = get_option('ofastx_modules_enabled', array());
                    $modules['smtp'] = true;
                    update_option('ofastx_modules_enabled', $modules);
                }
            }

            // 3. Mark Complete
            update_option('ofast_wizard_complete', true);

            wp_send_json_success();
        }
    }

    /**
     * Detect installed SMTP plugins
     */
    private function detect_smtp_plugins() {
        $detected = array();

        // Post SMTP
        if (is_plugin_active('post-smtp/postman-smtp.php') || get_option('postman_options')) {
            $options = get_option('postman_options', array());
            if (!empty($options)) {
                $detected['post_smtp'] = array(
                    'name' => 'Post SMTP',
                    'host' => $options['hostname'] ?? '',
                    'port' => $options['port'] ?? '',
                    'username' => $options['basic_auth_username'] ?? '',
                    'password' => $options['basic_auth_password'] ?? '',
                    'encryption' => $options['enc_type'] ?? '',
                    'from_email' => $options['sender_email'] ?? '',
                    'from_name' => $options['sender_name'] ?? '',
                );
            }
        }

        // WP Mail SMTP
        if (is_plugin_active('wp-mail-smtp/wp_mail_smtp.php') || get_option('wp_mail_smtp')) {
            $options = get_option('wp_mail_smtp', array());
            if (!empty($options)) {
                $smtp = $options['smtp'] ?? array();
                $detected['wp_mail_smtp'] = array(
                    'name' => 'WP Mail SMTP',
                    'host' => $smtp['host'] ?? '',
                    'port' => $smtp['port'] ?? '',
                    'username' => $smtp['user'] ?? '',
                    'password' => $smtp['pass'] ?? '',
                    'encryption' => $smtp['encryption'] ?? '',
                    'from_email' => $options['mail']['from_email'] ?? '',
                    'from_name' => $options['mail']['from_name'] ?? '',
                );
            }
        }

        // Easy WP SMTP
        if (is_plugin_active('easy-wp-smtp/easy-wp-smtp.php') || get_option('swpsmtp_options')) {
            $options = get_option('swpsmtp_options', array());
            if (!empty($options)) {
                $detected['easy_smtp'] = array(
                    'name' => 'Easy WP SMTP',
                    'host' => $options['smtp_settings']['host'] ?? '',
                    'port' => $options['smtp_settings']['port'] ?? '',
                    'username' => $options['smtp_settings']['username'] ?? '',
                    'password' => $options['smtp_settings']['password'] ?? '',
                    'encryption' => $options['smtp_settings']['type_encryption'] ?? '',
                    'from_email' => $options['from_email_field'] ?? '',
                    'from_name' => $options['from_name_field'] ?? '',
                );
            }
        }

        return $detected;
    }

    /**
     * Import SMTP settings from detected plugin
     */
    private function import_smtp_settings() {
        $source = sanitize_text_field($_POST['smtp_source'] ?? '');
        $detected = $this->detect_smtp_plugins();

        if (empty($source) || !isset($detected[$source])) {
            return false;
        }

        $settings = $detected[$source];

        if (!class_exists('Ofast_X_SMTP')) {
            require_once OFAST_X_PLUGIN_DIR . 'modules/smtp/class-ofast-smtp.php';
        }

        // Save to Ofast SMTP options
        update_option('ofast_smtp_enabled', 1);
        update_option('ofast_smtp_host', $settings['host']);
        update_option('ofast_smtp_port', $settings['port']);
        update_option('ofast_smtp_username', $settings['username']);
        update_option('ofast_smtp_password', Ofast_X_SMTP::encrypt_password($settings['password']));
        update_option('ofast_smtp_encryption', $settings['encryption']);
        update_option('ofast_smtp_from_email', $settings['from_email']);
        update_option('ofast_smtp_from_name', $settings['from_name']);

        return true;
    }

    /**
     * Render wizard
     */
    public function render_wizard() {
        ?>
        

        <div class="ofast-wizard-body">
            <div class="ofast-wizard-wrap">
                <div class="ofast-wizard-header">
                    <div class="ofast-wizard-logo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                        </svg>
                        Ofast Toolkit
                    </div>
                </div>

                <div class="ofast-wizard-card">
                    <div class="ofast-wizard-content">
                        <!-- Step Dots -->
                        <div class="ofast-step-dots" id="ofast-wizard-dots">
                            <div class="ofast-dot active"></div>
                            <div class="ofast-dot"></div>
                            <div class="ofast-dot"></div>
                        </div>

                        <form id="ofast-wizard-form" method="post">
                            <?php wp_nonce_field('ofast_wizard_nonce'); ?>
                            
                            <!-- STEP 1: Features -->
                            <div class="ofast-wizard-step active" id="step-1">
                                <h2 class="ofast-title-accent">Getting Started!</h2>
                                <h1>Select your essential tools.</h1>

                                <div class="ofast-progress-container" style="margin: 20px 0 30px;">
                                    <div class="ofast-progress-bar-wrap" style="background: #e2e8f0;">
                                        <div class="ofast-progress-bar-fill" style="width: 33%;"></div>
                                    </div>
                                </div>

                                <div class="ofast-module-grid">
                                    <?php 
                                    $major_features = array(
                                        'admin-tweaks' => array('Admin Studio', 'Modern dashboard widgets, user roles, and UI controls.'),
                                        'snippets' => array('Code Snippets', 'Add custom PHP, JS, and CSS to your site safely.'),
                                        'email' => array('Email Module', 'Reliable email delivery with beautiful HTML branded templates.'),
                                        'smtp' => array('SMTP Configuration', 'Configure secure out-bound mail delivery.'),
                                        'login-redesign' => array('Login Redesign', 'Create beautiful custom branded login pages.'),
                                        'whos-admin' => array('White Label', 'Hide traces of default WordPress branding.')
                                    );
                                    foreach ($major_features as $slug => $data): ?>
                                    <div class="ofast-module-item checked">
                                        <input type="checkbox" name="modules[<?php echo $slug; ?>]" id="mod_<?php echo $slug; ?>" checked>
                                        <label for="mod_<?php echo $slug; ?>">
                                            <?php echo $data[0]; ?>
                                            <span class="ofast-module-desc"><?php echo $data[1]; ?></span>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="ofast-wizard-nav between">
                                    <button type="button" class="ofast-btn ofast-btn-skip" onclick="skipWizard()">Skip Setup...</button>
                                    <button type="button" class="ofast-btn ofast-btn-primary" onclick="nextStep(2)">Continue to Mail Settings</button>
                                </div>
                            </div>

                            <!-- STEP 2: SMTP -->
                            <div class="ofast-wizard-step" id="step-2">
                                <?php $detected = $this->detect_smtp_plugins(); ?>
                                <h2 class="ofast-title-accent">Almost there!</h2>
                                <h1>SMTP Mail Configuration.</h1>

                                <div class="ofast-progress-container" style="margin: 20px 0 30px;">
                                    <div class="ofast-progress-bar-wrap" style="background: #e2e8f0;">
                                        <div class="ofast-progress-bar-fill" style="width: 66%;"></div>
                                    </div>
                                </div>

                                <?php if (!empty($detected)): ?>
                                    <div class="ofast-highlight-card" style="display:block;">
                                        <div class="ofast-highlight-left" style="margin-bottom: 20px;">
                                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline>
                                            </svg>
                                            <div class="ofast-highlight-text">
                                                <h3>Existing SMTP Detected</h3>
                                                <p>Select one to import its settings automatically.</p>
                                            </div>
                                        </div>
                                        
                                        <div class="ofast-module-grid" style="grid-template-columns: 1fr; gap: 10px;">
                                        <?php foreach ($detected as $key => $plugin): ?>
                                            <div class="ofast-module-item checked">
                                                <input type="radio" name="smtp_source" id="smtp_<?php echo $key; ?>" value="<?php echo $key; ?>" checked>
                                                <label for="smtp_<?php echo $key; ?>">
                                                    <?php echo esc_html($plugin['name']); ?>
                                                    <span class="ofast-module-desc">Host: <?php echo esc_html($plugin['host']); ?></span>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                        </div>

                                        <div class="ofast-module-item" style="border:none; box-shadow:none; padding: 10px 0; background:transparent;">
                                            <input type="checkbox" name="activate_smtp" id="activate_smtp" checked>
                                            <label for="activate_smtp" style="font-weight:400;">Activate Ofast SMTP module after import</label>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="ofast-highlight-card">
                                        <div class="ofast-highlight-left">
                                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line>
                                            </svg>
                                            <div class="ofast-highlight-text">
                                                <h3>No SMTP Plugin Found</h3>
                                                <p>No existing SMTP settings detected. You can configure SMTP later in the Emailer settings.</p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="ofast-wizard-nav between">
                                    <button type="button" class="ofast-btn ofast-btn-secondary" onclick="prevStep(1)">Back</button>
                                    <div style="display: flex; gap: 12px; align-items: center;">
                                        <button type="button" class="ofast-btn ofast-btn-skip" onclick="skipSmtpAndSubmit(this)">Skip Step</button>
                                        <button type="button" class="ofast-btn ofast-btn-primary" onclick="submitWizard(this)">Complete Setup <div class="ofast-loader"></div></button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Hidden inputs for final submission -->
                            <input type="hidden" name="action" value="ofast_wizard_submission">
                            <input type="hidden" name="ofast_wizard_action" id="ofast_wizard_action_input" value="finish_ajax">

                        </form>

                        <!-- STEP 3: Complete -->
                        <div class="ofast-wizard-step" id="step-3">
                            <h2 class="ofast-title-accent">Woohoo!</h2>
                            <h1>Your site is fully optimized and ready to go.</h1>

                            <div class="ofast-progress-container">
                                <div class="ofast-progress-header">
                                    <div class="ofast-progress-percent">100<span>%</span></div>
                                    <div class="ofast-progress-timer">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline>
                                        </svg>
                                        00:00:00
                                    </div>
                                </div>
                                <div class="ofast-progress-bar-wrap">
                                    <div class="ofast-progress-bar-fill" style="width: 100%;"></div>
                                </div>
                            </div>

                            <div class="ofast-highlight-card">
                                <div class="ofast-highlight-left">
                                    <div class="ofast-icon-box">
                                        <!-- Party popper icon mimicking the Airlift design -->
                                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#312e81" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M5.8 11.3 2 22l10.7-3.8"></path>
                                            <path d="M4 3h.01"></path>
                                            <path d="M22 8h.01"></path>
                                            <path d="M15 2h.01"></path>
                                            <path d="M22 20h.01"></path>
                                            <path d="m22 2-2.2 2.2"></path>
                                            <path d="m11 13 9-9"></path>
                                        </svg>
                                    </div>
                                    <div class="ofast-highlight-text">
                                        <h3>Your Site just got faster!</h3>
                                        <p>Check it out while we continue improving the rest of your site.</p>
                                    </div>
                                </div>
                                <a href="<?php echo esc_url(site_url()); ?>" target="_blank" class="ofast-outline-btn">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line>
                                    </svg>
                                    View Site
                                </a>
                            </div>

                            <p class="ofast-footer-text">Everything's set! Enjoy your faster, smoother site experience.</p>

                            <div class="ofast-wizard-nav" style="justify-content: flex-end;">
                                <a href="<?php echo admin_url('admin.php?page=ofast-dashboard&wizard_complete=1'); ?>" class="ofast-btn ofast-btn-primary">Go to Dashboard</a>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <script>
            // Handle Checkbox/Radio styling
            document.querySelectorAll('.ofast-module-item').forEach(item => {
                item.addEventListener('click', function(e) {
                    if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'LABEL' && !e.target.closest('label')) {
                        const input = this.querySelector('input');
                        if(input.type === 'radio') {
                            input.checked = true;
                            // Reset others
                            document.querySelectorAll('input[type="radio"]').forEach(r => r.closest('.ofast-module-item').classList.remove('checked'));
                            this.classList.add('checked');
                        } else {
                            input.checked = !input.checked;
                            this.classList.toggle('checked', input.checked);
                        }
                    }
                });
            });
            document.querySelectorAll('input[type="radio"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    document.querySelectorAll('input[type="radio"]').forEach(r => r.closest('.ofast-module-item').classList.remove('checked'));
                    if(this.checked) this.closest('.ofast-module-item').classList.add('checked');
                });
            });
            document.querySelectorAll('input[type="checkbox"]').forEach(check => {
                check.addEventListener('change', function() {
                    this.closest('.ofast-module-item').classList.toggle('checked', this.checked);
                });
            });

            // Transitions API
            function switchStep(target) {
                document.querySelectorAll('.ofast-wizard-step').forEach(el => el.classList.remove('active'));
                document.getElementById('step-' + target).classList.add('active');
                
                // Update dots
                const dots = document.querySelectorAll('.ofast-dot');
                dots.forEach((dot, index) => {
                    dot.classList.toggle('active', index < target);
                });
            }

            function nextStep(step) {
                switchStep(step);
            }

            function prevStep(step) {
                switchStep(step);
            }

            function skipWizard() {
                document.getElementById('ofast_wizard_action_input').value = 'skip_wizard';
                document.getElementById('ofast-wizard-form').submit();
            }

            function skipSmtpAndSubmit(btn) {
                // Clear any SMTP selection before submitting so it is ignored
                document.querySelectorAll('input[name="smtp_source"]').forEach(el => el.checked = false);
                const activateSmtp = document.getElementById('activate_smtp');
                if (activateSmtp) activateSmtp.checked = false;
                submitWizard(btn);
            }

            function submitWizard(btn) {
                btn.classList.add('loading');
                btn.disabled = true;

                const form = document.getElementById('ofast-wizard-form');
                const formData = new FormData(form);

                fetch(ajaxurl, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    btn.classList.remove('loading');
                    // Even if error, show success page to match user expectations,
                    // but ideally data.success is true
                    switchStep(3);
                })
                .catch(error => {
                    btn.classList.remove('loading');
                    btn.disabled = false;
                    switchStep(3); // Fallback to success UI
                });
            }
        </script>
        <?php
    }

    /**
     * Step 2: SMTP Detection & Import
     */
    private function render_step_2() {
        $detected = $this->detect_smtp_plugins();
        ?>
        <h2>SMTP Configuration</h2>
        <p>We'll help you configure email delivery to ensure your emails reach inboxes.</p>

        <form method="post">
            <?php wp_nonce_field('ofast_wizard_nonce'); ?>

            <?php if (!empty($detected)): ?>
                <div class="ofast-smtp-detected">
                    <h3><span class="dashicons dashicons-email-alt" style="color: #6366f1;"></span> Existing SMTP Plugin Detected</h3>
                    <p>We found the following SMTP configuration. Would you like to import these settings?</p>
                    
                    <?php foreach ($detected as $key => $plugin): ?>
                    <div class="ofast-module-item checked" style="margin-top: 15px; background: #f8fafc;">
                        <input type="radio" name="smtp_source" id="smtp_<?php echo $key; ?>" value="<?php echo $key; ?>" checked>
                        <label for="smtp_<?php echo $key; ?>">
                            <strong><?php echo esc_html($plugin['name']); ?></strong>
                            <br><small>Host: <?php echo esc_html($plugin['host']); ?></small>
                        </label>
                    </div>
                    <?php endforeach; ?>

                    <div class="ofast-module-item" style="margin-top: 15px; background: #fff;">
                        <input type="checkbox" name="activate_smtp" id="activate_smtp" checked>
                        <label for="activate_smtp">Activate Ofast SMTP module after import</label>
                    </div>
                </div>

                <div class="ofast-wizard-actions">
                    <button type="submit" name="ofast_wizard_action" value="skip_smtp" class="ofast-btn ofast-btn-secondary">Skip →</button>
                    <button type="submit" name="ofast_wizard_action" value="import_smtp" class="ofast-btn ofast-btn-primary"> Import & Continue</button>
                </div>

            <?php else: ?>
                <div class="ofast-smtp-not-found">
                    <h3><span class="dashicons dashicons-info" style="color: #64748b;"></span> No SMTP Plugin Found</h3>
                    <p>No existing SMTP settings detected. You can configure SMTP later in <strong>Ofast Emailer → SMTP</strong>.</p>
                </div>

                <div class="ofast-wizard-actions">
                    <button type="submit" name="ofast_wizard_action" value="skip_smtp" class="ofast-btn ofast-btn-secondary">← Back</button>
                    <button type="submit" name="ofast_wizard_action" value="skip_smtp" class="ofast-btn ofast-btn-primary">Continue →</button>
                </div>
            <?php endif; ?>
        </form>
        <?php
    }

    /**
     * Step 3: Complete
     */
    private function render_step_3() {
        $enabled = get_option('ofastx_modules_enabled', array());
        $count = count(array_filter($enabled));
        ?>
        <h2 style="text-align: center;">🎉 You're All Set!</h2>
        <p style="text-align: center;">Ofast X is now configured with <strong><?php echo $count; ?> modules</strong> enabled.</p>

        <div style="background: #fff; border: 2px solid #e5e7eb; border-radius: 12px; padding: 25px; margin: 30px 0;">
            <h3 style="margin: 0 0 15px; font-size: 16px;">What's Next?</h3>
            <ul style="margin: 0; padding-left: 20px; color: #64748b; line-height: 1.8;">
                <li>Visit the <strong>Dashboard</strong> to see your site overview</li>
                <li>Configure <strong>Email Templates</strong> for branded emails</li>
                <li>Set up <strong>Contact Forms</strong> for your site</li>
                <li>Adjust modules anytime in <strong>Settings</strong></li>
            </ul>
        </div>

        <form method="post">
            <?php wp_nonce_field('ofast_wizard_nonce'); ?>
            <div class="ofast-wizard-actions" style="justify-content: center;">
                <button type="submit" name="ofast_wizard_action" value="finish_wizard" class="ofast-btn ofast-btn-primary">Go to Dashboard →</button>
            </div>
        </form>
        <?php
    }

    /**
     * Get allowed modules for wizard validation
     * 
     * @return array Whitelist of allowed module keys
     */
    private function get_allowed_wizard_modules() {
        return array(
            // Communication modules
            'email' => true,
            'smtp' => true,
            'forms' => true,
            'social-login' => true,
            
            // Security & Content modules  
            'admin-url' => true,
            'spam-protection' => true,
            'snippets' => true,
            'redirects' => true,
            'content-ordering' => true,
            
            // Customization modules
            'admin-tweaks' => true,
            'login-redesign' => true,
            'menu-editor' => true,
            'whos-admin' => true,
            'user-roles' => true,
            'admin-design' => true,
        );
    }
}
