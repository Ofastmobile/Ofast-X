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
    public function add_admin_menu()
    {
        add_menu_page(
            'Contact Forms',
            'Contact Forms',
            'manage_options',
            'ofast-forms',
            array($this, 'render_forms_page'),
            'dashicons-feedback',
            30
        );

        add_submenu_page(
            'ofast-forms',
            'All Forms',
            'All Forms',
            'manage_options',
            'ofast-forms',
            array($this, 'render_forms_page')
        );

        add_submenu_page(
            'ofast-forms',
            'Add New Form',
            'Add New',
            'manage_options',
            'ofast-forms-new',
            array($this, 'render_builder_page')
        );

        add_submenu_page(
            'ofast-forms',
            'Submissions',
            'Submissions',
            'manage_options',
            'ofast-forms-submissions',
            array($this, 'render_submissions_page')
        );
    }

    /**
     * Get form by ID
     */
    public function get_form($form_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_forms';

        $form = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            absint($form_id)
        ));

        if ($form) {
            $form->fields = json_decode($form->fields, true) ?: array();
            $form->settings = json_decode($form->settings, true) ?: array();
            $form->notifications = json_decode($form->notifications, true) ?: array();
        }

        return $form;
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

        $form = $this->get_form($atts['id']);
        if (!$form || !$form->active) {
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
    public function render_forms_page()
    {
        $forms = $this->get_all_forms();
?>
        <div class="wrap">
            <h1>Contact Forms <a href="<?php echo admin_url('admin.php?page=ofast-forms-new'); ?>" class="page-title-action">Add New</a></h1>

            <?php if (empty($forms)): ?>
                <?php echo Ofast_X_Toast::render('No forms yet. <a href="' . admin_url('admin.php?page=ofast-forms-new') . '" style="color:#fff;text-decoration:underline;">Create your first form</a>', 'info'); ?>
            <?php else: ?>
                <!-- Scrollable Table Container -->
                <div style="overflow-x: auto; max-width: 100%;">
                    <table class="wp-list-table widefat fixed striped" style="min-width: 800px;">
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
                                    <td><strong><?php echo esc_html($form->title); ?></strong></td>
                                    <td><code>[ofast_form id="<?php echo $form->id; ?>"]</code></td>
                                    <td>
                                        <?php
                                        $count = $this->get_submission_count($form->id);
                                        $unread = $this->get_unread_count($form->id);
                                        echo $count;
                                        if ($unread > 0) {
                                            echo ' <span class="count-bubble">' . $unread . ' new</span>';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo $form->active ? '<span style="color:green;">Active</span>' : '<span style="color:gray;">Inactive</span>'; ?></td>
                                    <td><?php echo date('M j, Y', strtotime($form->created_at)); ?></td>
                                    <td>
                                        <a href="<?php echo admin_url('admin.php?page=ofast-forms-new&id=' . $form->id); ?>">Edit</a> |
                                        <a href="#" class="delete-form" data-id="<?php echo $form->id; ?>" style="color:red;">Delete</a>
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
