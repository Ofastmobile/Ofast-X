<?php

/**
 * Ofast X - Form Builder
 * Admin UI for creating and editing forms
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Forms_Builder
{
    private $form = null;
    private $form_id = 0;

    /**
     * Field types available
     */
    private $field_types = array(
        'text' => 'Text Input',
        'email' => 'Email',
        'phone' => 'Phone Number',
        'textarea' => 'Text Area',
        'select' => 'Dropdown Select',
        'radio' => 'Radio Buttons',
        'checkbox' => 'Checkboxes',
        'number' => 'Number',
        'date' => 'Date',
        'url' => 'URL',
        'hidden' => 'Hidden Field'
    );

    /**
     * Render the builder page
     */
    public function render()
    {
        // Check if editing existing form
        if (isset($_GET['id'])) {
            $this->form_id = absint($_GET['id']);
            $forms = Ofast_X_Forms::get_instance();
            $this->form = $forms->get_form($this->form_id);
        }

        // Handle save
        if (isset($_POST['ofast_save_form']) && wp_verify_nonce($_POST['form_nonce'], 'ofast_form_save')) {
            $this->save_form();
        }

        $title = $this->form ? $this->form->title : '';
        $description = $this->form ? $this->form->description : '';
        $fields = $this->form ? $this->form->fields : array();
        $settings = $this->form ? $this->form->settings : array();
        $notifications = $this->form ? (isset($this->form->notifications) ? $this->form->notifications : array()) : array();
        $active = $this->form ? $this->form->active : 1;
?>
        <div class="wrap ofast-form-builder">
            <h1><?php echo $this->form_id ? 'Edit Form' : 'Create New Form'; ?></h1>

            <form method="post" id="ofast-form-builder-form">
                <?php wp_nonce_field('ofast_form_save', 'form_nonce'); ?>
                <input type="hidden" name="form_id" value="<?php echo $this->form_id; ?>">

                <div class="builder-layout">
                    <!-- Left: Form Settings -->
                    <div class="builder-main">
                        <div class="builder-section">
                            <h2>Form Details</h2>
                            <table class="form-table">
                                <tr>
                                    <th>Form Title</th>
                                    <td><input type="text" name="title" value="<?php echo esc_attr($title); ?>" class="regular-text" required></td>
                                </tr>
                                <tr>
                                    <th>Description</th>
                                    <td><textarea name="description" rows="2" class="large-text"><?php echo esc_textarea($description); ?></textarea></td>
                                </tr>
                                <tr>
                                    <th>Status</th>
                                    <td><label><input type="checkbox" name="active" value="1" <?php checked($active); ?>> Active</label></td>
                                </tr>
                            </table>
                        </div>

                        <div class="builder-section">
                            <h2>Form Fields</h2>
                            <p class="description">Add and arrange your form fields. Drag to reorder.</p>

                            <div id="form-fields-container">
                                <?php
                                if (!empty($fields)) {
                                    foreach ($fields as $index => $field) {
                                        $this->render_field_row($field, $index);
                                    }
                                }
                                ?>
                            </div>

                            <div class="add-field-section">
                                <select id="new-field-type">
                                    <?php foreach ($this->field_types as $type => $label): ?>
                                        <option value="<?php echo $type; ?>"><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" id="add-field-btn" class="button">Add Field</button>
                            </div>
                        </div>

                        <div class="builder-section">
                            <h2>Form Settings</h2>
                            <table class="form-table">
                                <tr>
                                    <th>Success Message</th>
                                    <td><input type="text" name="settings[success_message]" value="<?php echo esc_attr($settings['success_message'] ?? 'Thank you! Your message has been sent.'); ?>" class="large-text"></td>
                                </tr>
                                <tr>
                                    <th>Redirect URL</th>
                                    <td>
                                        <input type="url" name="settings[redirect_url]" value="<?php echo esc_attr($settings['redirect_url'] ?? ''); ?>" class="regular-text" placeholder="Optional">
                                        <p class="description">Leave empty to show success message on same page</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Submit Button Text</th>
                                    <td><input type="text" name="settings[submit_text]" value="<?php echo esc_attr($settings['submit_text'] ?? 'Send Message'); ?>" class="regular-text"></td>
                                </tr>
                            </table>
                        </div>

                        <div class="builder-section">
                            <h2>Notifications</h2>
                            <table class="form-table">
                                <tr>
                                    <th>Admin Email</th>
                                    <td>
                                        <input type="email" name="notifications[admin_email]" value="<?php echo esc_attr($notifications['admin_email'] ?? get_option('admin_email')); ?>" class="regular-text">
                                        <p class="description">Email address to receive form submissions</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Email Subject</th>
                                    <td><input type="text" name="notifications[email_subject]" value="<?php echo esc_attr($notifications['email_subject'] ?? 'New Contact Form Submission'); ?>" class="regular-text"></td>
                                </tr>
                                <tr>
                                    <th>Send WhatsApp</th>
                                    <td><label><input type="checkbox" name="notifications[whatsapp_enabled]" value="1" <?php checked(!empty($notifications['whatsapp_enabled'])); ?>> Send WhatsApp notification to admin</label></td>
                                </tr>
                                <tr>
                                    <th>Log to Google Sheets</th>
                                    <td><label><input type="checkbox" name="notifications[gsheets_enabled]" value="1" <?php checked(!empty($notifications['gsheets_enabled'])); ?>> Add submission to Google Sheets</label></td>
                                </tr>
                            </table>
                        </div>

                        <div class="builder-section">
                            <h2>Design & Styling</h2>
                            <?php
                            $design = $settings['design'] ?? array();
                            ?>
                            <table class="form-table">
                                <tr>
                                    <th>Form Width</th>
                                    <td>
                                        <input type="number" name="settings[design][form_width]" value="<?php echo esc_attr($design['form_width'] ?? 600); ?>" style="width:80px;"> px
                                        <p class="description">Maximum width of the form</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Label Font Size</th>
                                    <td>
                                        <input type="number" name="settings[design][label_size]" value="<?php echo esc_attr($design['label_size'] ?? 14); ?>" style="width:60px;" min="10" max="24"> px
                                    </td>
                                </tr>
                                <tr>
                                    <th>Button Background</th>
                                    <td>
                                        <input type="color" name="settings[design][btn_bg]" value="<?php echo esc_attr($design['btn_bg'] ?? '#2271b1'); ?>">
                                        <input type="text" name="settings[design][btn_bg_text]" value="<?php echo esc_attr($design['btn_bg'] ?? '#2271b1'); ?>" style="width:80px;" class="color-text" data-target="btn_bg">
                                    </td>
                                </tr>
                                <tr>
                                    <th>Button Text Color</th>
                                    <td>
                                        <input type="color" name="settings[design][btn_text]" value="<?php echo esc_attr($design['btn_text'] ?? '#ffffff'); ?>">
                                        <input type="text" name="settings[design][btn_text_text]" value="<?php echo esc_attr($design['btn_text'] ?? '#ffffff'); ?>" style="width:80px;" class="color-text" data-target="btn_text">
                                    </td>
                                </tr>
                                <tr>
                                    <th>Button Hover Background</th>
                                    <td>
                                        <input type="color" name="settings[design][btn_hover]" value="<?php echo esc_attr($design['btn_hover'] ?? '#135e96'); ?>">
                                        <input type="text" name="settings[design][btn_hover_text]" value="<?php echo esc_attr($design['btn_hover'] ?? '#135e96'); ?>" style="width:80px;" class="color-text" data-target="btn_hover">
                                    </td>
                                </tr>
                                <tr>
                                    <th>Button Border Radius</th>
                                    <td>
                                        <input type="number" name="settings[design][btn_radius]" value="<?php echo esc_attr($design['btn_radius'] ?? 5); ?>" style="width:60px;" min="0" max="50"> px
                                    </td>
                                </tr>
                                <tr>
                                    <th>Form Background</th>
                                    <td>
                                        <input type="color" name="settings[design][form_bg]" value="<?php echo esc_attr($design['form_bg'] ?? '#ffffff'); ?>">
                                        <input type="text" name="settings[design][form_bg_text]" value="<?php echo esc_attr($design['form_bg'] ?? '#ffffff'); ?>" style="width:80px;" class="color-text" data-target="form_bg">
                                    </td>
                                </tr>
                                <tr>
                                    <th>Form Border Radius</th>
                                    <td>
                                        <input type="number" name="settings[design][form_radius]" value="<?php echo esc_attr($design['form_radius'] ?? 8); ?>" style="width:60px;" min="0" max="30"> px
                                    </td>
                                </tr>
                                <tr>
                                    <th>Input Border Color</th>
                                    <td>
                                        <input type="color" name="settings[design][input_border]" value="<?php echo esc_attr($design['input_border'] ?? '#dddddd'); ?>">
                                        <input type="text" name="settings[design][input_border_text]" value="<?php echo esc_attr($design['input_border'] ?? '#dddddd'); ?>" style="width:80px;" class="color-text" data-target="input_border">
                                    </td>
                                </tr>
                                <tr>
                                    <th>Input Focus Color</th>
                                    <td>
                                        <input type="color" name="settings[design][input_focus]" value="<?php echo esc_attr($design['input_focus'] ?? '#2271b1'); ?>">
                                        <input type="text" name="settings[design][input_focus_text]" value="<?php echo esc_attr($design['input_focus'] ?? '#2271b1'); ?>" style="width:80px;" class="color-text" data-target="input_focus">
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Right: Sidebar -->
                    <div class="builder-sidebar">
                        <div class="sidebar-box">
                            <h3>Publish</h3>
                            <button type="submit" name="ofast_save_form" class="button button-primary button-large" style="width:100%;">Save Form</button>
                            <?php if ($this->form_id): ?>
                                <p style="margin-top:10px;"><a href="<?php echo admin_url('admin.php?page=ofast-forms'); ?>">Back to All Forms</a></p>
                            <?php endif; ?>
                        </div>

                        <?php if ($this->form_id): ?>
                            <div class="sidebar-box">
                                <h3>Shortcode</h3>
                                <code style="display:block;padding:10px;background:#f5f5f5;">[ofast_form id="<?php echo $this->form_id; ?>"]</code>
                                <p class="description">Copy and paste this shortcode to display your form.</p>
                            </div>
                        <?php endif; ?>

                        <div class="sidebar-box">
                            <h3>Spam Protection</h3>
                            <?php if (class_exists('Ofast_X_Turnstile') && Ofast_X_Turnstile::get_instance()->is_configured()): ?>
                                <p style="color:green;">Turnstile is enabled</p>
                            <?php else: ?>
                                <p style="color:orange;">Turnstile not configured. <a href="<?php echo admin_url('admin.php?page=ofast-settings'); ?>">Configure now</a></p>
                            <?php endif; ?>
                        </div>

                        <div class="sidebar-box">
                            <h3>Preview</h3>
                            <button type="button" id="preview-form-btn" class="button button-secondary" style="width:100%;">Preview Form</button>
                            <p class="description" style="margin-top:8px;">See how your form looks before publishing.</p>
                        </div>
                    </div>
                </div>
            </form>

            <!-- Field Template (hidden) -->
            <script type="text/html" id="field-row-template">
                <?php $this->render_field_row(array(), '{{INDEX}}'); ?>
            </script>
        </div>

        <style>
            .ofast-form-builder .builder-layout {
                display: flex;
                gap: 20px;
            }

            .ofast-form-builder .builder-main {
                flex: 1;
            }

            .ofast-form-builder .builder-sidebar {
                width: 280px;
            }

            .ofast-form-builder .builder-section {
                background: #fff;
                padding: 20px;
                border: 1px solid #ddd;
                margin-bottom: 20px;
            }

            .ofast-form-builder .sidebar-box {
                background: #fff;
                padding: 15px;
                border: 1px solid #ddd;
                margin-bottom: 15px;
            }

            .ofast-form-builder .sidebar-box h3 {
                margin: 0 0 10px;
            }

            .ofast-form-builder .field-row {
                background: #f9f9f9;
                border: 1px solid #ddd;
                padding: 15px;
                margin-bottom: 10px;
                cursor: move;
            }

            .ofast-form-builder .field-row:hover {
                border-color: #2271b1;
            }

            .ofast-form-builder .field-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 10px;
            }

            .ofast-form-builder .field-type-badge {
                background: #2271b1;
                color: #fff;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
            }

            .ofast-form-builder .field-content {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .ofast-form-builder .field-options {
                grid-column: 1 / -1;
            }

            .ofast-form-builder .add-field-section {
                padding: 15px;
                background: #f0f0f0;
                display: flex;
                gap: 10px;
            }

            .ofast-form-builder .remove-field {
                color: red;
                cursor: pointer;
            }
        </style>

        <script>
            jQuery(function($) {
                var fieldIndex = <?php echo count($fields); ?>;

                // Add field
                $('#add-field-btn').on('click', function() {
                    var type = $('#new-field-type').val();
                    var template = $('#field-row-template').html();
                    template = template.replace(/\{\{INDEX\}\}/g, fieldIndex);
                    var $row = $(template);
                    $row.find('.field-type-input').val(type);
                    $row.find('.field-type-badge').text(type);
                    $('#form-fields-container').append($row);
                    fieldIndex++;
                });

                // Remove field
                $(document).on('click', '.remove-field', function() {
                    $(this).closest('.field-row').remove();
                });

                // Sortable
                $('#form-fields-container').sortable({
                    handle: '.field-header',
                    placeholder: 'field-row-placeholder'
                });

                // Toggle options for select/radio/checkbox
                $(document).on('change', '.field-type-input', function() {
                    var type = $(this).val();
                    var $row = $(this).closest('.field-row');
                    var hasOptions = ['select', 'radio', 'checkbox'].includes(type);
                    $row.find('.field-options').toggle(hasOptions);
                    $row.find('.field-type-badge').text(type);
                });

                // Preview functionality
                $('#preview-form-btn').on('click', function() {
                    var previewHtml = generatePreview();
                    $('#preview-content').html(previewHtml);
                    $('#preview-modal').show();
                });

                $('#close-preview-btn, #preview-modal-overlay').on('click', function() {
                    $('#preview-modal').hide();
                });

                function generatePreview() {
                    // Get design settings
                    var formWidth = $('input[name="settings[design][form_width]"]').val() || 600;
                    var labelSize = $('input[name="settings[design][label_size]"]').val() || 14;
                    var btnBg = $('input[name="settings[design][btn_bg]"]').val() || '#2271b1';
                    var btnText = $('input[name="settings[design][btn_text]"]').val() || '#ffffff';
                    var btnRadius = $('input[name="settings[design][btn_radius]"]').val() || 5;
                    var formBg = $('input[name="settings[design][form_bg]"]').val() || '#ffffff';
                    var formRadius = $('input[name="settings[design][form_radius]"]').val() || 8;
                    var inputBorder = $('input[name="settings[design][input_border]"]').val() || '#dddddd';
                    var inputFocus = $('input[name="settings[design][input_focus]"]').val() || '#2271b1';
                    var submitText = $('input[name="settings[submit_text]"]').val() || 'Send Message';

                    var html = '<div style="max-width:' + formWidth + 'px;margin:0 auto;">';
                    html += '<div style="background:' + formBg + ';padding:30px;border-radius:' + formRadius + 'px;box-shadow:0 2px 10px rgba(0,0,0,0.1);">';

                    // Generate fields
                    $('#form-fields-container .field-row').each(function() {
                        var label = $(this).find('input[name*="[label]"]').val() || '';
                        var placeholder = $(this).find('input[name*="[placeholder]"]').val() || '';
                        var type = $(this).find('.field-type-input').val() || 'text';
                        var required = $(this).find('input[name*="[required]"]').is(':checked');
                        var options = $(this).find('textarea[name*="[options]"]').val() || '';

                        if (type === 'hidden') return;

                        html += '<div style="margin-bottom:20px;">';
                        if (label) {
                            html += '<label style="display:block;font-weight:600;font-size:' + labelSize + 'px;margin-bottom:8px;">';
                            html += label;
                            if (required) html += ' <span style="color:#dc3545;">*</span>';
                            html += '</label>';
                        }

                        var inputStyle = 'width:100%;padding:12px 15px;border:1px solid ' + inputBorder + ';border-radius:5px;font-size:16px;box-sizing:border-box;';

                        switch (type) {
                            case 'textarea':
                                html += '<textarea placeholder="' + placeholder + '" style="' + inputStyle + 'min-height:120px;resize:vertical;"></textarea>';
                                break;
                            case 'select':
                                html += '<select style="' + inputStyle + '"><option>' + (placeholder || 'Select an option') + '</option>';
                                if (options) {
                                    options.split('\n').forEach(function(opt) {
                                        opt = opt.trim();
                                        if (opt) html += '<option>' + opt + '</option>';
                                    });
                                }
                                html += '</select>';
                                break;
                            case 'radio':
                            case 'checkbox':
                                html += '<div style="display:flex;flex-direction:column;gap:8px;">';
                                if (options) {
                                    options.split('\n').forEach(function(opt) {
                                        opt = opt.trim();
                                        if (opt) {
                                            html += '<label style="font-weight:normal;display:flex;align-items:center;gap:8px;">';
                                            html += '<input type="' + type + '"> ' + opt + '</label>';
                                        }
                                    });
                                }
                                html += '</div>';
                                break;
                            default:
                                var inputType = type === 'phone' ? 'tel' : type;
                                html += '<input type="' + inputType + '" placeholder="' + placeholder + '" style="' + inputStyle + '">';
                        }
                        html += '</div>';
                    });

                    // Submit button
                    var successMsg = $('input[name="settings[success_message]"]').val() || 'Thank you! Your message has been sent.';
                    html += '<div style="margin-top:20px;">';
                    html += '<button type="button" id="preview-submit-btn" style="background:' + btnBg + ';color:' + btnText + ';border:none;padding:14px 30px;font-size:16px;font-weight:600;border-radius:' + btnRadius + 'px;cursor:pointer;transition:background 0.2s;">' + submitText + '</button>';
                    html += '</div>';

                    // Success message (hidden)
                    html += '<div id="preview-success-msg" style="display:none;padding:12px 15px;border-radius:5px;margin-top:20px;font-size:14px;background:#d4edda;color:#155724;border:1px solid #c3e6cb;">' + successMsg + '</div>';

                    html += '</div></div>';
                    return html;
                }

                // Fake submit in preview
                $(document).on('click', '#preview-submit-btn', function() {
                    var $btn = $(this);
                    var originalText = $btn.text();
                    $btn.text('Sending...').prop('disabled', true);

                    setTimeout(function() {
                        $btn.text(originalText).prop('disabled', false);
                        $('#preview-success-msg').fadeIn();
                        setTimeout(function() {
                            $('#preview-success-msg').fadeOut();
                        }, 3000);
                    }, 1000);
                });
            });
        </script>

        <!-- Preview Modal -->
        <div id="preview-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:100000;">
            <div id="preview-modal-overlay" style="position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);"></div>
            <div style="position:relative;max-width:800px;margin:40px auto;background:#f5f5f5;border-radius:8px;max-height:90vh;overflow:auto;">
                <div style="background:#1d2327;color:#fff;padding:15px 20px;display:flex;justify-content:space-between;align-items:center;">
                    <h3 style="margin:0;font-size:16px;">Form Preview</h3>
                    <button type="button" id="close-preview-btn" style="background:none;border:none;color:#fff;font-size:24px;cursor:pointer;line-height:1;">&times;</button>
                </div>
                <div id="preview-content" style="padding:30px;"></div>
            </div>
        </div>
    <?php
    }

    /**
     * Render a single field row
     */
    private function render_field_row($field, $index)
    {
        $type = $field['type'] ?? 'text';
        $label = $field['label'] ?? '';
        $placeholder = $field['placeholder'] ?? '';
        $required = !empty($field['required']);
        $options = $field['options'] ?? '';
        $show_options = in_array($type, array('select', 'radio', 'checkbox'));
    ?>
        <div class="field-row">
            <div class="field-header">
                <span class="field-type-badge"><?php echo esc_html($type); ?></span>
                <span class="remove-field">Remove</span>
            </div>
            <div class="field-content">
                <div>
                    <label>Label</label>
                    <input type="text" name="fields[<?php echo $index; ?>][label]" value="<?php echo esc_attr($label); ?>" class="widefat" placeholder="Field Label">
                </div>
                <div>
                    <label>Placeholder</label>
                    <input type="text" name="fields[<?php echo $index; ?>][placeholder]" value="<?php echo esc_attr($placeholder); ?>" class="widefat" placeholder="Placeholder text">
                </div>
                <div>
                    <label>Type</label>
                    <select name="fields[<?php echo $index; ?>][type]" class="field-type-input widefat">
                        <?php foreach ($this->field_types as $t => $l): ?>
                            <option value="<?php echo $t; ?>" <?php selected($type, $t); ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><input type="checkbox" name="fields[<?php echo $index; ?>][required]" value="1" <?php checked($required); ?>> Required</label>
                </div>
                <div class="field-options" style="<?php echo $show_options ? '' : 'display:none;'; ?>">
                    <label>Options (one per line)</label>
                    <textarea name="fields[<?php echo $index; ?>][options]" rows="3" class="widefat" placeholder="Option 1&#10;Option 2&#10;Option 3"><?php echo esc_textarea($options); ?></textarea>
                </div>
            </div>
        </div>
<?php
    }

    /**
     * Save form from POST data
     */
    private function save_form()
    {
        $forms = Ofast_X_Forms::get_instance();

        $data = array(
            'id' => absint($_POST['form_id']),
            'title' => $_POST['title'],
            'description' => $_POST['description'] ?? '',
            'fields' => $_POST['fields'] ?? array(),
            'settings' => $_POST['settings'] ?? array(),
            'notifications' => $_POST['notifications'] ?? array(),
            'active' => isset($_POST['active'])
        );

        $form_id = $forms->save_form($data);

        if ($form_id && !$this->form_id) {
            wp_redirect(admin_url('admin.php?page=ofast-forms-new&id=' . $form_id . '&saved=1'));
            exit;
        }

        echo '<div class="notice notice-success"><p>Form saved successfully!</p></div>';
    }
}
