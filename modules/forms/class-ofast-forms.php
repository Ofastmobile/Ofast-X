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
        // Only load if module is enabled in Ofast X settings
        $enabled = get_option('ofastx_modules_enabled', array());
        if (empty($enabled['forms'])) {
            return;
        }

        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // AJAX handlers
        add_action('wp_ajax_ofast_save_form', array($this, 'ajax_save_form'));
        add_action('wp_ajax_ofast_delete_form', array($this, 'ajax_delete_form'));
        add_action('wp_ajax_ofast_submit_form', array($this, 'ajax_submit_form'));
        add_action('wp_ajax_nopriv_ofast_submit_form', array($this, 'ajax_submit_form'));

        // Shortcode
        add_shortcode('ofast_form', array($this, 'render_shortcode'));

        // Enqueue scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
    }

    /**
     * Add admin menu
     */
    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_menu_page(
            'Contact Forms',
            'Contact Forms',
            'manage_options',
            'ofast-forms',
            array($this, 'render_main_page'),
            'dashicons-feedback',
            30
        );
    }

    /**
     * Get form by ID with authorization checks
     */
    public function get_form($form_id, $context = 'public')
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_forms';

        // Validate form ID
        $form_id = absint($form_id);
        if ($form_id <= 0) {
            return null;
        }

        // Retrieve form from database
        $form = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $form_id
        ));

        if (!$form) {
            return null;
        }

        // Perform authorization checks based on context
        if (!$this->authorize_form_access($form_id, $form, $context)) {
            return null;
        }

        // Decode JSON fields
        if ($form->fields) {
            $form->fields = json_decode($form->fields, true) ?: array();
        }
        if ($form->settings) {
            $form->settings = json_decode($form->settings, true) ?: array();
        }
        if ($form->notifications) {
            $form->notifications = json_decode($form->notifications, true) ?: array();
        }

        // Filter sensitive data based on context
        return $this->filter_form_data($form, $context);
    }

    /**
     * Authorize access to a form based on context
     */
    private function authorize_form_access($form_id, $form, $context)
    {
        switch ($context) {
            case 'admin':
                // Admin context requires manage_options capability
                if (!current_user_can('manage_options')) {
                    return false;
                }
                break;

            case 'public':
            case 'shortcode':
                // Public context only allows access to active forms
                if (!$form || !$form->active) {
                    return false;
                }
                break;

            case 'submission':
                // Submission context allows access to active forms
                if (!$form || !$form->active) {
                    return false;
                }
                break;

            default:
                // Unknown context defaults to most restrictive
                return current_user_can('manage_options');
        }

        return true;
    }

    /**
     * Filter form data based on access context
     */
    private function filter_form_data($form, $context)
    {
        switch ($context) {
            case 'admin':
                // Admin context gets full access to all data
                return $form;

            case 'public':
            case 'shortcode':
                // Public context gets limited data, no sensitive information
                return $this->filter_public_form_data($form);

            case 'submission':
                // Submission context gets form fields and validation rules
                return $this->filter_submission_form_data($form);

            default:
                // Default to most restrictive
                return current_user_can('manage_options') ? $form : $this->filter_public_form_data($form);
        }
    }

    /**
     * Filter form data for public display
     */
    private function filter_public_form_data($form)
    {
        if (!$form) {
            return null;
        }

        // Create a filtered copy with only safe public data
        $filtered = new stdClass();
        $filtered->id = $form->id;
        $filtered->title = $form->title;
        $filtered->description = $form->description ?? '';
        $filtered->active = $form->active;
        $filtered->fields = $this->filter_fields_for_public($form->fields ?? []);
        
        // Only include safe settings
        $safe_settings = [];
        if (!empty($form->settings)) {
            $public_safe_keys = [
                'success_message',
                'submit_text',
                'design'
            ];
            foreach ($public_safe_keys as $key) {
                if (isset($form->settings[$key])) {
                    $safe_settings[$key] = $form->settings[$key];
                }
            }
        }
        $filtered->settings = $safe_settings;

        return $filtered;
    }

    /**
     * Filter form data for submission processing
     */
    private function filter_submission_form_data($form)
    {
        if (!$form) {
            return null;
        }

        // Start with public data
        $filtered = $this->filter_public_form_data($form);
        
        // Add validation rules needed for processing submissions
        if (!empty($form->fields)) {
            foreach ($form->fields as $index => $field) {
                if (isset($filtered->fields[$index])) {
                    // Add validation properties that are needed server-side
                    $validation_props = ['required', 'min', 'max', 'pattern'];
                    foreach ($validation_props as $prop) {
                        if (isset($field[$prop])) {
                            $filtered->fields[$index][$prop] = $field[$prop];
                        }
                    }
                }
            }
        }

        return $filtered;
    }

    /**
     * Filter individual fields for public access
     */
    private function filter_fields_for_public($fields)
    {
        if (!is_array($fields)) {
            return [];
        }

        $filtered_fields = [];
        $public_field_props = [
            'type',
            'label', 
            'placeholder',
            'options',
            'width',
            'css_classes'
        ];

        foreach ($fields as $index => $field) {
            if (!is_array($field)) {
                continue;
            }

            $filtered_field = [];
            foreach ($public_field_props as $prop) {
                if (isset($field[$prop])) {
                    $filtered_field[$prop] = $field[$prop];
                }
            }
            $filtered_fields[$index] = $filtered_field;
        }

        return $filtered_fields;
    }

    /**
     * Get all forms
     */
    public function get_all_forms($active_only = false)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_forms';

        $where = $active_only ? "WHERE active = 1" : "";
        $forms = $wpdb->get_results("SELECT * FROM {$table} {$where} ORDER BY created_at DESC");

        foreach ($forms as &$form) {
            $form->fields = json_decode($form->fields, true) ?: array();
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

        $form_data = array(
            'title' => sanitize_text_field($data['title']),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'fields' => wp_json_encode($data['fields'] ?? array()),
            'settings' => wp_json_encode($data['settings'] ?? array()),
            'notifications' => wp_json_encode($data['notifications'] ?? array()),
            'active' => isset($data['active']) ? 1 : 0,
            'updated_at' => current_time('mysql')
        );

        if (!empty($data['id'])) {
            // Update existing
            $wpdb->update($table, $form_data, array('id' => absint($data['id'])));
            return absint($data['id']);
        } else {
            // Insert new
            $form_data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $form_data);
            return $wpdb->insert_id;
        }
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

    /**
     * Get submission count for a form
     */
    public function get_submission_count($form_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_form_submissions';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE form_id = %d",
            absint($form_id)
        ));
    }

    /**
     * Get unread submission count
     */
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
        $atts = shortcode_atts(array(
            'id' => 0
        ), $atts);

        if (empty($atts['id'])) {
            return '<p>Please specify a form ID.</p>';
        }

        $form = $this->get_form($atts['id'], 'shortcode');
        if (!$form) {
            return '<p>Form not found.</p>';
        }

        // Load renderer
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

        $form_id = $this->save_form($_POST);
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
        // Load submissions handler
        require_once OFAST_X_PLUGIN_DIR . 'modules/forms/class-ofast-forms-submissions.php';
        $handler = new Ofast_X_Forms_Submissions();
        $handler->handle_submission();
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
            'nonce' => wp_create_nonce('ofast_forms_nonce')
        ));
    }

    /**
     * Enqueue frontend scripts
     */
    public function enqueue_frontend_scripts()
    {
        wp_enqueue_style('ofast-forms-frontend', OFAST_X_PLUGIN_URL . 'modules/forms/css/forms-frontend.css', array(), OFAST_X_VERSION);
        wp_enqueue_script('ofast-forms-frontend', OFAST_X_PLUGIN_URL . 'modules/forms/js/forms-frontend.js', array('jquery'), OFAST_X_VERSION, true);

        wp_localize_script('ofast-forms-frontend', 'ofastForms', array(
            'ajaxurl' => admin_url('admin-ajax.php')
        ));
    }

    /**
     * Render forms list page
     */
    /**
     * Render main page with tabs
     */
    public function render_main_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'all-forms';
        ?>
        <style>
            /* Consolidated Admin Styles matching Email Module */
            :root {
                --ofast-primary: #6366f1;
            }

            /* Header Styles */
            .ofast-header {
                display: flex;
                align-items: center;
                gap: 20px;
                background: #fff;
                padding: 25px 30px;
                border-radius: 12px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                margin-bottom: 25px;
                margin-top: 20px;
            }
            .ofast-header-icon {
                width: 56px;
                height: 56px;
                background: #fff;
                border: 1px solid #e2e8f0;
                box-shadow: 0 2px 4px rgba(0,0,0,0.02);
                border-radius: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .ofast-header-icon .dashicons {
                font-size: 28px;
                width: 28px;
                height: 28px;
                color: #6366f1;
            }
            .ofast-header-content h1 {
                margin: 0 0 5px 0;
                font-size: 24px;
                font-weight: 700;
                color: #1e293b;
                display: block;
                padding: 0;
            }
            .ofast-header-content p {
                margin: 0;
                color: #64748b;
                font-size: 14px;
            }

            /* Tabs Navigation */
            .ofast-tabs-nav {
                display: flex;
                flex-wrap: nowrap;
                gap: 8px;
                margin-bottom: 25px;
                padding: 10px 12px;
                background: #fff;
                border-radius: 12px;
                border: 1px solid rgba(226, 232, 240, 0.6);
                position: sticky;
                top: 40px;
                z-index: 99;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                -ms-overflow-style: none;
                scrollbar-width: none;
            }
            .ofast-tabs-nav::-webkit-scrollbar {
                display: none;
            }
            .ofast-tab {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 12px 20px;
                background: transparent;
                border: none;
                border-radius: 8px;
                color: #64748b;
                font-size: 14px;
                font-weight: 500;
                text-decoration: none;
                cursor: pointer;
                transition: all 0.2s ease;
                white-space: nowrap;
            }
            .ofast-tab:hover {
                background: #f1f5f9;
                color: #1e293b;
            }
            .ofast-tab.active {
                background: var(--ofast-primary);
                color: #fff;
                box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
            }
            .ofast-tab .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
                line-height: 16px;
            }

            /* Tab Content Visibility */
            .ofast-tab-content { display: none; }
            .ofast-tab-content.active { display: block; animation: ofastFadeIn 0.3s ease; }
            @keyframes ofastFadeIn {
                from { opacity: 0; transform: translateY(10px); }
                to { opacity: 1; transform: translateY(0); }
            }

            /* Card Styling */
            .ofast-card {
                background: #fff;
                border-radius: 16px;
                padding: 30px;
                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
                border: 1px solid rgba(226, 232, 240, 0.6);
                margin-bottom: 20px;
            }
            .ofast-card h2 { margin-top: 0; }

            /* Table Styles Override within Card */
            .ofast-card .wp-list-table {
                border: none;
                box-shadow: none;
            }
            .ofast-card .wp-list-table th {
                padding: 15px 20px;
                background: #f8fafc;
                border-bottom: 1px solid #e2e8f0;
                font-weight: 600;
                color: #475569;
            }
            .ofast-card .wp-list-table td {
                padding: 15px 20px;
                vertical-align: middle;
            }
            .ofast-card .wp-list-table tr:hover td {
                background: #f8fafc;
            }
            
            /* Button Override */
            .button.button-primary {
                background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%) !important;
                border-color: #6366f1 !important;
                text-shadow: none !important;
                box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3) !important;
                transition: all 0.3s ease !important;
                padding: 10px 24px !important;
                height: auto !important;
                border-radius: 8px !important;
                font-size: 14px !important;
            }
            .button.button-primary:hover {
                background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%) !important;
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4) !important;
            }
            .button.button-primary:active { transform: translateY(0); }
            
            .page-title-action { display: none; } /* Hide default WP Add New */
            
            /* Checkbox Styling Overrides */
            .ofast-card input[type="checkbox"]:checked {
                background-color: #fff;
                border-color: #6366f1;
                background-image: url("data:image/svg+xml;utf8,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%20viewBox%3D%270%200%2020%2020%27%3E%3Cpath%20d%3D%27M14.83%204.89l1.34.94-5.81%208.38H9.02L5.78%209.67l1.34-1.25%202.57%202.4z%27%20fill%3D%27%236366f1%27%2F%3E%3C%2Fsvg%3E");
            }
            .ofast-card input[type="checkbox"]:focus {
                border-color: #6366f1;
                box-shadow: 0 0 0 1px #6366f1;
            }
        </style>

        <div class="wrap">
            <!-- Header -->
            <div class="ofast-header">
                <div class="ofast-header-icon">
                    <span class="dashicons dashicons-feedback"></span>
                </div>
                <div class="ofast-header-content">
                    <h1>Contact Forms</h1>
                    <p>Build, manage, and track your contact forms and submissions.</p>
                </div>
            </div>

            <nav class="ofast-tabs-nav">
                <a href="#" class="ofast-tab <?php echo $current_tab === 'all-forms' ? 'active' : ''; ?>" data-tab="all-forms">
                    <span class="dashicons dashicons-list-view"></span> All Forms
                </a>
                <a href="#" class="ofast-tab <?php echo $current_tab === 'add-new' ? 'active' : ''; ?>" data-tab="add-new">
                    <span class="dashicons dashicons-plus-alt2"></span> Add New
                </a>
                <a href="#" class="ofast-tab <?php echo $current_tab === 'submissions' ? 'active' : ''; ?>" data-tab="submissions">
                    <span class="dashicons dashicons-email-alt"></span> Submissions
                </a>
            </nav>

            <div id="tab-all-forms" class="ofast-tab-content <?php echo $current_tab === 'all-forms' ? 'active' : ''; ?>">
                <div class="ofast-card">
                    <?php $this->render_forms_page(); ?>
                </div>
            </div>

            <div id="tab-add-new" class="ofast-tab-content <?php echo $current_tab === 'add-new' ? 'active' : ''; ?>">
                <!-- Content here manages its own cards -->
                <?php $this->render_builder_page(); ?>
            </div>

            <div id="tab-submissions" class="ofast-tab-content <?php echo $current_tab === 'submissions' ? 'active' : ''; ?>">
                <div class="ofast-card">
                    <?php $this->render_submissions_page(); ?>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Tab Switching
                $('.ofast-tab').on('click', function(e) {
                    e.preventDefault();
                    var target = $(this).data('tab');
                    
                    $('.ofast-tab').removeClass('active');
                    $(this).addClass('active');
                    
                    $('.ofast-tab-content').removeClass('active');
                    $('#tab-' + target).addClass('active');
                    
                    var url = new URL(window.location);
                    url.searchParams.set('tab', target);
                    window.history.pushState({}, '', url);
                });

                // Handle external links to tabs (e.g., Edit links)
                $('body').on('click', '.ofast-switch-tab', function(e) {
                    var target = $(this).data('tab');
                    if(target) {
                        e.preventDefault();
                        $('.ofast-tab[data-tab="' + target + '"]').click();
                    }
                });

                // Handle browser back button
                window.onpopstate = function() {
                    var urlParams = new URLSearchParams(window.location.search);
                    var tab = urlParams.get('tab') || 'all-forms';
                    $('.ofast-tab[data-tab="' + tab + '"]').click();
                };
            });
        </script>        <?php
    }

    /**
     * Render forms list page
     */
    public function render_forms_page()
    {
        $forms = $this->get_all_forms();
?>
        <!-- Replaced content for tabbed view -->
        <div class="ofast-forms-list">
            <?php if (empty($forms)): ?>
                <div style="padding: 40px; text-align: center;">
                    <?php echo Ofast_X_Toast::render('No forms yet. <a href="#" class="ofast-switch-tab" data-tab="add-new">Create your first form</a>', 'info'); ?>
                </div>
            <?php else: ?>
                <!-- Scrollable Table Container -->
                <div style="overflow-x: auto; max-width: 100%;">
                    <table class="wp-list-table widefat fixed striped" style="min-width: 800px; margin: 0; box-shadow: none;">
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
                                    <td style="font-weight: 500; color: #1e293b;">
                                        <?php echo esc_html($form->title); ?>
                                        <div class="row-actions">
                                            <a href="#" class="ofast-switch-tab" data-tab="add-new" onclick="
                                                var url = new URL(window.location);
                                                url.searchParams.set('form_id', '<?php echo $form->id; ?>');
                                                window.history.pushState({}, '', url);
                                                location.reload(); 
                                            ">Edit</a> | 
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ofast-forms&action=delete&id=' . $form->id), 'delete_form_' . $form->id); ?>" 
                                               class="delete" onclick="return confirm('Are you sure?');">Delete</a>
                                        </div>
                                    </td>
                                    <td>
                                        <code style="background: #f1f5f9; padding: 4px 8px; border-radius: 4px; color: #6366f1; white-space: nowrap;">[ofast_form id="<?php echo $form->id; ?>"]</code>
                                        <span class="dashicons dashicons-admin-page" title="Copy Shortcode" style="cursor:pointer; color:#94a3b8; font-size:16px; margin-left:5px;" onclick="navigator.clipboard.writeText('[ofast_form id=&quot;<?php echo $form->id; ?>&quot;]'); alert('Copied!');"></span>
                                    </td>
                                    <td>
                                        <?php
                                        $count = $this->get_submission_count($form->id);
                                        $unread = $this->get_unread_count($form->id);
                                        echo $count;
                                        if ($unread > 0) {
                                            echo ' <span class="count-bubble" style="background:#6366f1; color:white; padding:2px 6px; border-radius:10px; font-size:10px; margin-left:5px;">' . $unread . ' new</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($form->active): ?>
                                            <span style="display:inline-flex; align-items:center; gap:4px; padding: 4px 10px; border-radius: 20px; background: #dcfce7; color: #15803d; font-size: 12px; font-weight: 500;">
                                                <span style="width:6px; height:6px; background:#15803d; border-radius:50%;"></span> Active
                                            </span>
                                        <?php else: ?>
                                            <span style="display:inline-flex; align-items:center; gap:4px; padding: 4px 10px; border-radius: 20px; background: #f1f5f9; color: #64748b; font-size: 12px; font-weight: 500;">
                                                <span style="width:6px; height:6px; background:#64748b; border-radius:50%;"></span> Inactive
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color: #64748b;"><?php echo date('M j, Y', strtotime($form->created_at)); ?></td>
                                    <td>
                                        <a href="<?php echo admin_url('admin.php?page=ofast-forms&tab=add-new&id=' . $form->id); ?>" style="color:#6366f1; font-weight:500;">Edit</a> |
                                        <a href="#" class="delete-form" data-id="<?php echo $form->id; ?>" style="color:#ef4444;">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <script>
            jQuery(function($) {
                $('.delete-form').on('click', function(e) {
                    e.preventDefault();
                    if (!confirm('Delete this form? This cannot be undone.')) return;

                    var $row = $(this).closest('tr');
                    $.post(ajaxurl, {
                        action: 'ofast_delete_form',
                        form_id: $(this).data('id'),
                        nonce: '<?php echo wp_create_nonce('ofast_forms_nonce'); ?>'
                    }, function() {
                        $row.fadeOut();
                    });
                });
            });
        </script>
<?php
    }

    /**
     * Render form builder page
     */
    public function render_builder_page()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/forms/class-ofast-forms-builder.php';
        $builder = new Ofast_X_Forms_Builder();
        $builder->render();
    }

    /**
     * Render submissions page
     */
    public function render_submissions_page()
    {
        require_once OFAST_X_PLUGIN_DIR . 'modules/forms/class-ofast-forms-submissions.php';
        $handler = new Ofast_X_Forms_Submissions();
        $handler->render_admin_page();
    }
}

// Initialize
Ofast_X_Forms::get_instance();
