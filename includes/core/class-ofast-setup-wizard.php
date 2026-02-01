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
     * Handle wizard form submission
     */
    public function handle_wizard_submission() {
        if (!isset($_POST['ofast_wizard_action'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['_wpnonce'], 'ofast_wizard_nonce')) {
            wp_die('Security check failed');
        }

        $action = sanitize_text_field($_POST['ofast_wizard_action']);

        switch ($action) {
            case 'skip_wizard':
                update_option('ofast_wizard_complete', true);
                wp_redirect(admin_url('admin.php?page=ofast-dashboard'));
                exit;

            case 'complete_step_1':
                // Save selected modules
                if (isset($_POST['modules']) && is_array($_POST['modules'])) {
                    $modules = array();
                    foreach ($_POST['modules'] as $slug => $value) {
                        $modules[$slug] = true;
                    }
                    update_option('ofastx_modules_enabled', $modules);
                }
                wp_redirect(admin_url('admin.php?page=ofast-setup-wizard&step=2'));
                exit;

            case 'import_smtp':
                $this->import_smtp_settings();
                if (isset($_POST['activate_smtp'])) {
                    $modules = get_option('ofastx_modules_enabled', array());
                    $modules['smtp'] = true;
                    update_option('ofastx_modules_enabled', $modules);
                }
                wp_redirect(admin_url('admin.php?page=ofast-setup-wizard&step=3'));
                exit;

            case 'skip_smtp':
                wp_redirect(admin_url('admin.php?page=ofast-setup-wizard&step=3'));
                exit;

            case 'finish_wizard':
                update_option('ofast_wizard_complete', true);
                wp_redirect(admin_url('admin.php?page=ofast-dashboard&wizard_complete=1'));
                exit;
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

        // Save to Ofast SMTP options
        update_option('ofast_smtp_enabled', 1);
        update_option('ofast_smtp_host', $settings['host']);
        update_option('ofast_smtp_port', $settings['port']);
        update_option('ofast_smtp_username', $settings['username']);
        update_option('ofast_smtp_password', $settings['password']);
        update_option('ofast_smtp_encryption', $settings['encryption']);
        update_option('ofast_smtp_from_email', $settings['from_email']);
        update_option('ofast_smtp_from_name', $settings['from_name']);

        return true;
    }

    /**
     * Render wizard
     */
    public function render_wizard() {
        $step = isset($_GET['step']) ? intval($_GET['step']) : 1;
        ?>
        <style>
            .ofast-wizard-wrap { max-width: 800px; margin: 40px auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
            .ofast-wizard-header { text-align: center; margin-bottom: 40px; }
            .ofast-wizard-header h1 { font-size: 32px; margin: 0 0 10px; color: #1e293b; }
            .ofast-wizard-header p { color: #64748b; font-size: 16px; margin: 0; }
            .ofast-wizard-steps { display: flex; justify-content: center; gap: 10px; margin-bottom: 40px; }
            .ofast-step { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; background: #e5e7eb; color: #64748b; }
            .ofast-step.active { background: #6366f1; color: #fff; }
            .ofast-step.complete { background: #10b981; color: #fff; }
            .ofast-step-line { width: 60px; height: 3px; background: #e5e7eb; align-self: center; }
            .ofast-wizard-card { background: #fff; border-radius: 16px; padding: 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
            .ofast-wizard-card h2 { margin: 0 0 20px; font-size: 24px; color: #1e293b; }
            .ofast-wizard-card p { color: #64748b; line-height: 1.6; }
            .ofast-module-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin: 25px 0; }
            .ofast-module-item { display: flex; align-items: center; gap: 12px; padding: 15px; border: 2px solid #e5e7eb; border-radius: 10px; cursor: pointer; transition: all 0.2s; }
            .ofast-module-item:hover { border-color: #6366f1; }
            .ofast-module-item.checked { border-color: #6366f1; background: #eef2ff; }
            .ofast-module-item input { margin: 0; }
            .ofast-module-item label { cursor: pointer; font-weight: 500; color: #1e293b; }
            .ofast-smtp-detected { background: #ecfdf5; border: 2px solid #10b981; border-radius: 12px; padding: 20px; margin: 20px 0; }
            .ofast-smtp-detected h3 { margin: 0 0 10px; color: #065f46; display: flex; align-items: center; gap: 8px; }
            .ofast-smtp-not-found { background: #fef3c7; border: 2px solid #f59e0b; border-radius: 12px; padding: 20px; margin: 20px 0; }
            .ofast-wizard-actions { display: flex; justify-content: space-between; margin-top: 30px; padding-top: 20px; border-top: 2px solid #f1f5f9; }
            .ofast-btn { padding: 14px 28px; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.2s; border: none; }
            .ofast-btn-primary { background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; box-shadow: 0 4px 15px rgba(99,102,241,0.3); }
            .ofast-btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(99,102,241,0.4); }
            .ofast-btn-secondary { background: #f8fafc; color: #64748b; border: 2px solid #e5e7eb; }
            .ofast-btn-secondary:hover { border-color: #6366f1; color: #6366f1; }
            .ofast-btn-skip { background: transparent; color: #64748b; text-decoration: underline; }
            .ofast-category-label { font-size: 12px; font-weight: 600; color: #6366f1; text-transform: uppercase; letter-spacing: 0.5px; margin: 20px 0 10px; }
        </style>

        <div class="ofast-wizard-wrap">
            <div class="ofast-wizard-header">
                <h1>Welcome to Ofast X</h1>
                <p>Let's set up your plugin in just a few steps</p>
            </div>

            <div class="ofast-wizard-steps">
                <div class="ofast-step <?php echo $step >= 1 ? ($step > 1 ? 'complete' : 'active') : ''; ?>">1</div>
                <div class="ofast-step-line"></div>
                <div class="ofast-step <?php echo $step >= 2 ? ($step > 2 ? 'complete' : 'active') : ''; ?>">2</div>
                <div class="ofast-step-line"></div>
                <div class="ofast-step <?php echo $step >= 3 ? 'active' : ''; ?>">3</div>
            </div>

            <div class="ofast-wizard-card">
                <?php
                switch ($step) {
                    case 1:
                        $this->render_step_1();
                        break;
                    case 2:
                        $this->render_step_2();
                        break;
                    case 3:
                        $this->render_step_3();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Step 1: Module Selection
     */
    private function render_step_1() {
        $recommended = array('email', 'smtp', 'forms', 'snippets', 'redirects', 'admin-tweaks');
        ?>
        <h2>Choose Your Modules</h2>
        <p>Select the features you want to enable. You can always change these later in Settings.</p>

        <form method="post">
            <?php wp_nonce_field('ofast_wizard_nonce'); ?>
            <input type="hidden" name="ofast_wizard_action" value="complete_step_1">

            <div class="ofast-category-label"> Communication</div>
            <div class="ofast-module-grid">
                <?php foreach (array('email' => 'Email Module', 'smtp' => 'SMTP Configuration', 'newsletter' => 'Newsletter', 'forms' => 'Contact Forms') as $slug => $name): ?>
                <div class="ofast-module-item <?php echo in_array($slug, $recommended) ? 'checked' : ''; ?>">
                    <input type="checkbox" name="modules[<?php echo $slug; ?>]" id="mod_<?php echo $slug; ?>" <?php checked(in_array($slug, $recommended)); ?>>
                    <label for="mod_<?php echo $slug; ?>"><?php echo $name; ?></label>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="ofast-category-label"> Security & Content</div>
            <div class="ofast-module-grid">
                <?php foreach (array('admin-url' => 'Admin URL Customizer', 'spam-protection' => 'Spam Protection', 'snippets' => 'Code Snippets', 'redirects' => 'Redirects Manager') as $slug => $name): ?>
                <div class="ofast-module-item <?php echo in_array($slug, $recommended) ? 'checked' : ''; ?>">
                    <input type="checkbox" name="modules[<?php echo $slug; ?>]" id="mod_<?php echo $slug; ?>" <?php checked(in_array($slug, $recommended)); ?>>
                    <label for="mod_<?php echo $slug; ?>"><?php echo $name; ?></label>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="ofast-category-label"> Customization</div>
            <div class="ofast-module-grid">
                <?php foreach (array('admin-tweaks' => 'Admin Tweaks', 'login-redesign' => 'Login Redesign', 'menu-editor' => 'Menu Editor', 'whos-admin' => "Who's Admin") as $slug => $name): ?>
                <div class="ofast-module-item <?php echo in_array($slug, $recommended) ? 'checked' : ''; ?>">
                    <input type="checkbox" name="modules[<?php echo $slug; ?>]" id="mod_<?php echo $slug; ?>" <?php checked(in_array($slug, $recommended)); ?>>
                    <label for="mod_<?php echo $slug; ?>"><?php echo $name; ?></label>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="ofast-wizard-actions">
                <button type="submit" name="ofast_wizard_action" value="skip_wizard" class="ofast-btn ofast-btn-skip">Skip Setup</button>
                <button type="submit" class="ofast-btn ofast-btn-primary">Continue →</button>
            </div>
        </form>

        <script>
            document.querySelectorAll('.ofast-module-item').forEach(item => {
                item.addEventListener('click', function(e) {
                    if (e.target.tagName !== 'INPUT') {
                        const checkbox = this.querySelector('input');
                        checkbox.checked = !checkbox.checked;
                    }
                    this.classList.toggle('checked', this.querySelector('input').checked);
                });
            });
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
                    <h3>✅ Existing SMTP Plugin Detected!</h3>
                    <p>We found the following SMTP configuration. Would you like to import these settings?</p>
                    
                    <?php foreach ($detected as $key => $plugin): ?>
                    <div class="ofast-module-item checked" style="margin-top: 15px; background: #fff;">
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
                    <h3> No SMTP Plugin Found</h3>
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

        <div style="background: #f8fafc; border-radius: 12px; padding: 25px; margin: 30px 0;">
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
}
