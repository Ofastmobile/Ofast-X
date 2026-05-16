<?php

/**
 * Ofast X - Contact Forms Module
 * Main controller for form management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Forms
{
    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks()
    {
        $enabled = get_option('ofastx_modules_enabled', array());
        if (empty($enabled['forms'])) {
            return;
        }

        add_action('admin_menu', array($this, 'add_admin_menu'), 20);

        // AJAX handlers
        add_action('wp_ajax_ofast_save_form',           array($this, 'ajax_save_form'));
        add_action('wp_ajax_ofast_delete_form',         array($this, 'ajax_delete_form'));
        add_action('wp_ajax_ofast_submit_form',         array($this, 'ajax_submit_form'));
        add_action('wp_ajax_nopriv_ofast_submit_form',  array($this, 'ajax_submit_form'));
        // FIX #8: Export handler was implemented but never wired up.
        add_action('wp_ajax_ofast_export_submissions',  array($this, 'ajax_export_submissions'));

        add_shortcode('ofast_form', array($this, 'render_shortcode'));

        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_enqueue_scripts',    array($this, 'enqueue_frontend_scripts'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'ofast-dashboard',
            'Contact Forms',
            'Contact Forms',
            'manage_options',
            'ofast-forms',
            array($this, 'render_main_page')
        );
    }

    /**
     * Get form by ID with context-aware access control.
     */
    public function get_form($form_id, $context = 'public')
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_forms';

        $form_id = absint($form_id);
        if ($form_id <= 0) {
            return null;
        }

        $form = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $form_id
        ));

        if (!$form || !$this->authorize_form_access($form, $context)) {
            return null;
        }

        $form->fields        = json_decode($form->fields, true) ?: array();
        $form->settings      = json_decode($form->settings, true) ?: array();
        $form->notifications = json_decode($form->notifications, true) ?: array();

        return $this->filter_form_data($form, $context);
    }

    /**
     * Get all forms with optional pagination.
     *
     * FIX #13: Added $args for per_page / offset so callers can paginate.
     *
     * @param bool  $active_only
     * @param array $args  { per_page: int, offset: int }
     */
    public function get_all_forms($active_only = false, $args = array())
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_forms';

        $per_page = isset($args['per_page']) ? absint($args['per_page']) : 200;
        $offset   = isset($args['offset'])   ? absint($args['offset'])   : 0;

        $where = $active_only ? 'WHERE active = 1' : '';

        $forms = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));

        foreach ($forms as &$form) {
            $form->fields   = json_decode($form->fields, true) ?: array();
            $form->settings = json_decode($form->settings, true) ?: array();
        }

        return $forms;
    }

    /**
     * Save form
     */
    public function save_form($data)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_forms';

        $title = sanitize_text_field($data['title'] ?? '');
        $title = $this->truncate_string($title, 200);

        if ($title === '') {
            return false;
        }

        $description   = sanitize_textarea_field($data['description'] ?? '');
        $description   = $this->truncate_string($description, 1000);
        $fields        = $this->validate_and_sanitize_fields($data['fields'] ?? array());
        $settings      = $this->validate_and_sanitize_settings($data['settings'] ?? array());
        $notifications = $this->validate_and_sanitize_notifications(
            $data['notifications'] ?? $this->get_existing_notifications($data['id'] ?? 0)
        );

        $form_data = array(
            'title'         => $title,
            'description'   => $description,
            'fields'        => wp_json_encode($fields),
            'settings'      => wp_json_encode($settings),
            'notifications' => wp_json_encode($notifications),
            'active'        => isset($data['active']) ? 1 : 0,
            'updated_at'    => current_time('mysql'),
        );

        if (!empty($data['id'])) {
            $wpdb->update($table, $form_data, array('id' => absint($data['id'])));
            return absint($data['id']);
        }

        $form_data['created_at'] = current_time('mysql');
        $wpdb->insert($table, $form_data);
        return $wpdb->insert_id;
    }

    /**
     * Check whether the requested form is accessible in the current context.
     */
    private function authorize_form_access($form, $context)
    {
        if (!$form) {
            return false;
        }

        switch ($context) {
            case 'admin':
                return current_user_can('manage_options');
            case 'submission':
            case 'public':
            case 'shortcode':
                return !empty($form->active);
            default:
                return false;
        }
    }

    /**
     * Reduce the form payload based on where it is being used.
     */
    private function filter_form_data($form, $context)
    {
        switch ($context) {
            case 'admin':
                return $form;
            case 'submission':
                return $this->filter_submission_form_data($form);
            case 'public':
            case 'shortcode':
            default:
                return $this->filter_public_form_data($form);
        }
    }

    private function filter_public_form_data($form)
    {
        $filtered              = new stdClass();
        $filtered->id          = $form->id;
        $filtered->title       = $form->title;
        $filtered->description = $form->description ?? '';
        $filtered->active      = $form->active;
        $filtered->fields      = $this->filter_fields_for_public($form->fields ?? array());
        $filtered->settings    = array();

        foreach (array('submit_text', 'design') as $key) {
            if (isset($form->settings[$key])) {
                $filtered->settings[$key] = $form->settings[$key];
            }
        }

        return $filtered;
    }

    private function filter_submission_form_data($form)
    {
        $filtered = $this->filter_public_form_data($form);

        foreach (array('success_message', 'redirect_url') as $key) {
            if (!empty($form->settings[$key])) {
                $filtered->settings[$key] = $form->settings[$key];
            }
        }

        return $filtered;
    }

    private function filter_fields_for_public($fields)
    {
        if (!is_array($fields)) {
            return array();
        }

        $filtered_fields = array();
        $allowed_props   = array('type', 'label', 'placeholder', 'options', 'width', 'required');

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $filtered_field = array();
            foreach ($allowed_props as $prop) {
                if (array_key_exists($prop, $field)) {
                    $filtered_field[$prop] = $field[$prop];
                }
            }

            if (!empty($filtered_field)) {
                $filtered_fields[] = $filtered_field;
            }
        }

        return $filtered_fields;
    }

    private function validate_and_sanitize_fields($fields)
    {
        if (!is_array($fields)) {
            return array();
        }

        $allowed_field_types = array('text', 'email', 'phone', 'textarea', 'select', 'radio', 'checkbox', 'number', 'date', 'url', 'hidden');
        $allowed_widths      = array('full', 'half');
        $sanitized_fields    = array();

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $type = sanitize_key($field['type'] ?? 'text');
            if (!in_array($type, $allowed_field_types, true)) {
                $type = 'text';
            }

            $width = sanitize_key($field['width'] ?? 'full');
            if (!in_array($width, $allowed_widths, true)) {
                $width = 'full';
            }

            $sanitized_fields[] = array(
                'type'        => $type,
                'label'       => $this->truncate_string(sanitize_text_field($field['label'] ?? ''), 100),
                'placeholder' => $this->truncate_string(sanitize_text_field($field['placeholder'] ?? ''), 200),
                'options'     => $this->truncate_string(sanitize_textarea_field($field['options'] ?? ''), 2000),
                'width'       => $width,
                'required'    => !empty($field['required']),
            );
        }

        return array_slice($sanitized_fields, 0, 50);
    }

    private function validate_and_sanitize_settings($settings)
    {
        if (!is_array($settings)) {
            return array();
        }

        $sanitized_settings = array();

        if (isset($settings['success_message'])) {
            $sanitized_settings['success_message'] = $this->truncate_string(sanitize_text_field($settings['success_message']), 300);
        }

        if (isset($settings['redirect_url'])) {
            $sanitized_settings['redirect_url'] = esc_url_raw($settings['redirect_url']);
        }

        if (isset($settings['submit_text'])) {
            $sanitized_settings['submit_text'] = $this->truncate_string(sanitize_text_field($settings['submit_text']), 50);
        }

        if (!empty($settings['design']) && is_array($settings['design'])) {
            $sanitized_settings['design'] = $this->validate_and_sanitize_design_settings($settings['design']);
        }

        return $sanitized_settings;
    }

    private function validate_and_sanitize_design_settings($design)
    {
        $sanitized_design = array();
        $numeric_fields   = array(
            'form_width'  => array('min' => 200, 'max' => 1200, 'default' => 600),
            'label_size'  => array('min' => 10,  'max' => 24,   'default' => 14),
            'btn_radius'  => array('min' => 0,   'max' => 50,   'default' => 5),
            'form_radius' => array('min' => 0,   'max' => 30,   'default' => 8),
        );
        // FIX #9 / #14: Only authoritative color keys are stored.
        // The builder UI uses mirror text inputs for UX, but those inputs no
        // longer carry a `name` attribute, so they never appear in $_POST.
        $color_fields = array('btn_bg', 'btn_text', 'btn_hover', 'form_bg', 'input_border', 'input_focus');

        foreach ($numeric_fields as $field => $limits) {
            if (isset($design[$field])) {
                $value                    = absint($design[$field]);
                $sanitized_design[$field] = max($limits['min'], min($limits['max'], $value));
            }
        }

        foreach ($color_fields as $field) {
            if (isset($design[$field])) {
                $color = sanitize_hex_color($design[$field]);
                if ($color) {
                    $sanitized_design[$field] = $color;
                }
            }
        }

        return $sanitized_design;
    }

    private function validate_and_sanitize_notifications($notifications)
    {
        if (!is_array($notifications)) {
            return array();
        }

        $sanitized = array();

        foreach ($notifications as $key => $value) {
            $sanitized_key = sanitize_key($key);
            if ($sanitized_key === '') {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$sanitized_key] = $this->validate_and_sanitize_notifications($value);
            } elseif (is_bool($value)) {
                $sanitized[$sanitized_key] = $value;
            } elseif (is_scalar($value)) {
                $sanitized[$sanitized_key] = $this->truncate_string(sanitize_text_field((string) $value), 500);
            }
        }

        return $sanitized;
    }

    private function get_existing_notifications($form_id)
    {
        $form_id = absint($form_id);
        if ($form_id <= 0) {
            return array();
        }

        $existing_form = $this->get_form($form_id, 'admin');
        if (!$existing_form || empty($existing_form->notifications) || !is_array($existing_form->notifications)) {
            return array();
        }

        return $existing_form->notifications;
    }

    private function string_length($value)
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function truncate_string($value, $max_length)
    {
        if ($this->string_length($value) <= $max_length) {
            return $value;
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, $max_length, 'UTF-8') : substr($value, 0, $max_length);
    }

    /**
     * Delete form
     */
    public function delete_form($form_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_forms';

        return $wpdb->delete($table, array('id' => absint($form_id)));
    }

    public function get_submission_count($form_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_form_submissions';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE form_id = %d",
            absint($form_id)
        ));
    }

    public function get_unread_count($form_id = null)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_form_submissions';

        if ($form_id) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND status = 'unread'",
                absint($form_id)
            ));
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'unread'");
    }

    /**
     * Render shortcode
     */
    public function render_shortcode($atts)
    {
        $atts = shortcode_atts(array('id' => 0), $atts);

        if (empty($atts['id'])) {
            return '<p>Please specify a form ID.</p>';
        }

        $form = $this->get_form($atts['id'], 'shortcode');
        if (!$form) {
            return '<p>Form not found.</p>';
        }

        require_once OFAST_X_PLUGIN_DIR . 'modules/forms/class-ofast-forms-render.php';
        $renderer = new Ofast_X_Forms_Render();

        return $renderer->render($form);
    }

    /**
     * AJAX: Save form
     */
    public function ajax_save_form()
    {
        check_ajax_referer('ofast_forms_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $form_id = $this->save_form(wp_unslash($_POST));

        if ($form_id === false) {
            wp_send_json_error(array('message' => 'Form title is required.'));
        }

        wp_send_json_success(array('form_id' => $form_id));
    }

    /**
     * AJAX: Delete form
     */
    public function ajax_delete_form()
    {
        check_ajax_referer('ofast_forms_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $form_id = absint($_POST['form_id']);
        $this->delete_form($form_id);
        wp_send_json_success();
    }

    /**
     * AJAX: Submit form (frontend)
     */
    public function ajax_submit_form()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/forms/class-ofast-forms-submissions.php';
        $handler = new Ofast_X_Forms_Submissions();
        $handler->handle_submission();
    }

    /**
     * AJAX: Export submissions as CSV.
     * FIX #8: This handler was missing; export_csv() existed but was unreachable.
     */
    public function ajax_export_submissions()
    {
        check_ajax_referer('ofast_forms_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $form_id = absint($_GET['form_id'] ?? 0);
        if (!$form_id) {
            wp_die('Invalid form ID.');
        }

        require_once OFAST_X_PLUGIN_DIR . 'modules/forms/class-ofast-forms-submissions.php';
        $handler = new Ofast_X_Forms_Submissions();
        $csv     = $handler->export_csv($form_id);

        if (!$csv) {
            wp_die('No submissions found for this form.');
        }

        $form  = $this->get_form($form_id, 'admin');
        $title = $form ? sanitize_file_name($form->title) : 'submissions';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $title . '-submissions.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook)
    {
        if (strpos($hook, 'ofast-forms') === false) {
            return;
        }

        wp_enqueue_style('ofast-forms-admin', OFAST_X_PLUGIN_URL . 'modules/forms/css/forms-admin.css', array(), OFAST_X_VERSION);
        wp_enqueue_script('ofast-forms-admin', OFAST_X_PLUGIN_URL . 'modules/forms/js/forms-admin.js', array('jquery', 'jquery-ui-sortable'), OFAST_X_VERSION, true);

        wp_localize_script('ofast-forms-admin', 'ofastForms', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('ofast_forms_nonce'),
        ));
    }

    /**
     * Enqueue frontend scripts.
     *
     * FIX #11: Previously added the inline `ofastForms` variable on every
     * frontend page. Now restricted to pages that actually contain the shortcode.
     */
    public function enqueue_frontend_scripts()
    {
        global $post;

        if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'ofast_form')) {
            return;
        }

        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', 'var ofastForms = ' . wp_json_encode(array(
            'ajaxurl' => admin_url('admin-ajax.php'),
        )) . ';');
    }

    /**
     * Render main admin page with tabs.
     *
     * FIX #1: Delete action is now handled here (was wired to a GET link
     * but had no server-side handler).
     * FIX #2: `deleted=1` and `saved=1` query params now display admin notices.
     */
    public function render_main_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // --- FIX #1: Handle form deletion via GET link ---
        if (
            isset($_GET['action'], $_GET['id']) &&
            $_GET['action'] === 'delete'
        ) {
            $form_id = absint($_GET['id']);
            if (wp_verify_nonce($_GET['_wpnonce'] ?? '', 'delete_form_' . $form_id)) {
                $this->delete_form($form_id);
                wp_safe_redirect(admin_url('admin.php?page=ofast-forms&tab=all-forms&deleted=1'));
                exit;
            }
        }

        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'all-forms';
        ?>
        <style>
            :root { --ofast-primary: #6366f1; }

            .ofast-header {
                display: flex; align-items: center; gap: 20px; background: #fff;
                padding: 25px 30px; border-radius: 12px;
                box-shadow: 0 4px 6px -1px rgba(0,0,0,.05);
                margin-bottom: 25px; margin-top: 20px;
            }
            .ofast-header-icon {
                width: 56px; height: 56px; background: #fff;
                border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,.02);
                border-radius: 16px; display: flex; align-items: center; justify-content: center;
            }
            .ofast-header-icon .dashicons { font-size: 28px; width: 28px; height: 28px; color: #6366f1; }
            .ofast-header-content h1 { margin: 0 0 5px; font-size: 24px; font-weight: 700; color: #1e293b; display: block; padding: 0; }
            .ofast-header-content p  { margin: 0; color: #64748b; font-size: 14px; }

            .ofast-tabs-nav {
                display: flex; flex-wrap: nowrap; gap: 8px; margin-bottom: 25px;
                padding: 10px 12px; background: #fff; border-radius: 12px;
                border: 1px solid rgba(226,232,240,.6); position: sticky; top: 40px;
                z-index: 99; box-shadow: 0 4px 6px -1px rgba(0,0,0,.05);
                overflow-x: auto; -webkit-overflow-scrolling: touch;
                -ms-overflow-style: none; scrollbar-width: none;
            }
            .ofast-tabs-nav::-webkit-scrollbar { display: none; }
            .ofast-tab {
                display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px;
                background: transparent; border: none; border-radius: 8px; color: #64748b;
                font-size: 14px; font-weight: 500; text-decoration: none; cursor: pointer;
                transition: all .2s ease; white-space: nowrap;
            }
            .ofast-tab:hover  { background: #f1f5f9; color: #1e293b; }
            .ofast-tab.active { background: var(--ofast-primary); color: #fff; box-shadow: 0 4px 12px rgba(99,102,241,.3); }
            .ofast-tab .dashicons { font-size: 16px; width: 16px; height: 16px; line-height: 16px; }

            .ofast-tab-content        { display: none; }
            .ofast-tab-content.active { display: block; animation: ofastFadeIn .3s ease; }
            @keyframes ofastFadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

            .ofast-card {
                background: #fff; border-radius: 16px; padding: 30px;
                box-shadow: 0 10px 15px -3px rgba(0,0,0,.1), 0 4px 6px -2px rgba(0,0,0,.05);
                border: 1px solid rgba(226,232,240,.6); margin-bottom: 20px;
            }
            .ofast-card h2 { margin-top: 0; }
            .ofast-card .wp-list-table { border: none; box-shadow: none; }
            .ofast-card .wp-list-table th { padding: 15px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-weight: 600; color: #475569; }
            .ofast-card .wp-list-table td { padding: 15px 20px; vertical-align: middle; }
            .ofast-card .wp-list-table tr:hover td { background: #f8fafc; }

            .button.button-primary {
                background: linear-gradient(135deg,#6366f1 0%,#4f46e5 100%) !important;
                border-color: #6366f1 !important; text-shadow: none !important;
                box-shadow: 0 4px 15px rgba(99,102,241,.3) !important;
                transition: all .3s ease !important; padding: 10px 24px !important;
                height: auto !important; border-radius: 8px !important; font-size: 14px !important;
            }
            .button.button-primary:hover { background: linear-gradient(135deg,#4f46e5 0%,#4338ca 100%) !important; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(99,102,241,.4) !important; }
            .button.button-primary:active { transform: translateY(0); }
            .page-title-action { display: none; }
            .ofast-card input[type="checkbox"]:checked {
                background-color: #fff; border-color: #6366f1;
                background-image: url("data:image/svg+xml;utf8,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%20viewBox%3D%270%200%2020%2020%27%3E%3Cpath%20d%3D%27M14.83%204.89l1.34.94-5.81%208.38H9.02L5.78%209.67l1.34-1.25%202.57%202.4z%27%20fill%3D%27%236366f1%27%2F%3E%3C%2Fsvg%3E");
            }
            .ofast-card input[type="checkbox"]:focus { border-color: #6366f1; box-shadow: 0 0 0 1px #6366f1; }
        </style>

        <div class="wrap">
            <div class="ofast-header">
                <div class="ofast-header-icon">
                    <span class="dashicons dashicons-feedback"></span>
                </div>
                <div class="ofast-header-content">
                    <h1>Contact Forms</h1>
                    <p>Build, manage, and track your contact forms and submissions.</p>
                </div>
            </div>

            <?php
            // FIX #2: Admin notices for delete and save confirmation.
            if (!empty($_GET['deleted'])) {
                echo '<div class="notice notice-success is-dismissible"><p>Form deleted successfully.</p></div>';
            }
            if (!empty($_GET['saved'])) {
                echo '<div class="notice notice-success is-dismissible"><p>Form saved successfully.</p></div>';
            }
            ?>

            <nav class="ofast-tabs-nav">
                <a href="<?php echo esc_url(admin_url('admin.php?page=ofast-forms&tab=all-forms')); ?>" class="ofast-tab <?php echo $current_tab === 'all-forms' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-list-view"></span> All Forms
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ofast-forms&tab=add-new')); ?>" class="ofast-tab <?php echo $current_tab === 'add-new' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-plus-alt2"></span> Add New
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ofast-forms&tab=submissions')); ?>" class="ofast-tab <?php echo $current_tab === 'submissions' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-email-alt"></span> Submissions
                </a>
            </nav>

            <div id="tab-all-forms" class="ofast-tab-content <?php echo $current_tab === 'all-forms' ? 'active' : ''; ?>">
                <div class="ofast-card">
                    <?php $this->render_forms_page(); ?>
                </div>
            </div>

            <div id="tab-add-new" class="ofast-tab-content <?php echo $current_tab === 'add-new' ? 'active' : ''; ?>">
                <?php $this->render_builder_page(); ?>
            </div>

            <div id="tab-submissions" class="ofast-tab-content <?php echo $current_tab === 'submissions' ? 'active' : ''; ?>">
                <div class="ofast-card">
                    <?php $this->render_submissions_page(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render forms list page
     */
    public function render_forms_page()
    {
        $forms = $this->get_all_forms();
        ?>
        <div class="ofast-forms-list">
            <?php if (empty($forms)): ?>
                <div style="padding:60px 40px;text-align:center;">
                    <div style="width:64px;height:64px;margin:0 auto 20px;background:#f1f5f9;border-radius:16px;display:flex;align-items:center;justify-content:center;">
                        <span class="dashicons dashicons-feedback" style="font-size:32px;width:32px;height:32px;color:#94a3b8;"></span>
                    </div>
                    <h3 style="margin:0 0 8px;color:#1e293b;font-size:18px;font-weight:600;">No forms yet</h3>
                    <p style="margin:0 0 20px;color:#64748b;font-size:14px;">Create your first contact form to start collecting submissions.</p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ofast-forms&tab=add-new')); ?>" class="button button-primary">
                        <span class="dashicons dashicons-plus-alt2" style="margin-right:4px;font-size:14px;width:14px;height:14px;line-height:22px;"></span>
                        Create Form
                    </a>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;max-width:100%;">
                    <table class="wp-list-table widefat fixed striped" style="min-width:800px;margin:0;box-shadow:none;">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Shortcode</th>
                                <th>Submissions</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($forms as $form): ?>
                                <tr>
                                    <td style="font-weight:500;color:#1e293b;">
                                        <?php echo esc_html($form->title); ?>
                                        <div class="row-actions">
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=ofast-forms&tab=add-new&id=' . $form->id)); ?>" style="color:#6366f1;">Edit</a> |
                                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=ofast-forms&action=delete&id=' . $form->id), 'delete_form_' . $form->id)); ?>"
                                               class="delete" onclick="return confirm('Delete this form? This cannot be undone.');">Delete</a>
                                        </div>
                                    </td>
                                    <td>
                                        <code style="background:#f1f5f9;padding:4px 8px;border-radius:4px;color:#6366f1;white-space:nowrap;">[ofast_form id="<?php echo $form->id; ?>"]</code>
                                        <span class="dashicons dashicons-admin-page" title="Copy Shortcode" style="cursor:pointer;color:#94a3b8;font-size:16px;margin-left:5px;"
                                              onclick="navigator.clipboard.writeText('[ofast_form id=&quot;<?php echo $form->id; ?>&quot;]');alert('Copied!');"></span>
                                    </td>
                                    <td>
                                        <?php
                                        $count  = $this->get_submission_count($form->id);
                                        $unread = $this->get_unread_count($form->id);
                                        echo $count;
                                        if ($unread > 0) {
                                            echo ' <span style="background:#6366f1;color:white;padding:2px 6px;border-radius:10px;font-size:10px;margin-left:5px;">' . $unread . ' new</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($form->active): ?>
                                            <span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;background:#dcfce7;color:#15803d;font-size:12px;font-weight:500;">
                                                <span style="width:6px;height:6px;background:#15803d;border-radius:50%;"></span> Active
                                            </span>
                                        <?php else: ?>
                                            <span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;background:#f1f5f9;color:#64748b;font-size:12px;font-weight:500;">
                                                <span style="width:6px;height:6px;background:#64748b;border-radius:50%;"></span> Inactive
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color:#64748b;"><?php echo esc_html(date('M j, Y', strtotime($form->created_at))); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=ofast-forms&tab=add-new&id=' . $form->id)); ?>" style="color:#6366f1;font-weight:500;">Edit</a> |
                                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=ofast-forms&action=delete&id=' . $form->id), 'delete_form_' . $form->id)); ?>"
                                           class="delete" onclick="return confirm('Delete this form? This cannot be undone.');">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_builder_page()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/forms/class-ofast-forms-builder.php';
        $builder = new Ofast_X_Forms_Builder();
        $builder->render();
    }

    public function render_submissions_page()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/forms/class-ofast-forms-submissions.php';
        $handler = new Ofast_X_Forms_Submissions();
        $handler->render_admin_page();
    }
}

Ofast_X_Forms::get_instance();