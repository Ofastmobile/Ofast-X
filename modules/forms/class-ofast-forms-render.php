<?php

/**
 * Ofast X - Form Renderer
 * Frontend rendering of forms
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Forms_Render
{
    /**
     * Render a form
     */
    public function render($form)
    {
        if (!$form) {
            return '<p>Form not found.</p>';
        }

        $form_id = $form->id;
        $fields = $form->fields;
        $settings = $form->settings;
        $submit_text = $settings['submit_text'] ?? 'Send Message';

        // Get design settings with defaults
        $design = $settings['design'] ?? array();
        $form_width = absint($design['form_width'] ?? 600);
        $label_size = absint($design['label_size'] ?? 14);
        $btn_bg = esc_attr($design['btn_bg'] ?? '#2271b1');
        $btn_text = esc_attr($design['btn_text'] ?? '#ffffff');
        $btn_radius = absint($design['btn_radius'] ?? 5);
        $form_bg = esc_attr($design['form_bg'] ?? '#ffffff');
        $form_radius = absint($design['form_radius'] ?? 8);
        $input_border = esc_attr($design['input_border'] ?? '#dddddd');
        $input_focus = esc_attr($design['input_focus'] ?? '#2271b1');

        ob_start();
?>
        <div class="ofast-form-wrapper" id="ofast-form-<?php echo $form_id; ?>" style="max-width: <?php echo $form_width; ?>px; margin: 0 auto;">
            <form class="ofast-form" data-form-id="<?php echo $form_id; ?>" method="post" style="background: <?php echo $form_bg; ?>; padding: 30px; border-radius: <?php echo $form_radius; ?>px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <?php wp_nonce_field('ofast_form_submit_' . $form_id, 'ofast_form_nonce'); ?>
                <input type="hidden" name="form_id" value="<?php echo $form_id; ?>">
                <input type="hidden" name="ofast_label_size" value="<?php echo $label_size; ?>">
                <input type="hidden" name="ofast_input_border" value="<?php echo $input_border; ?>">
                <input type="hidden" name="ofast_input_focus" value="<?php echo $input_focus; ?>">

                <!-- SECURITY: Honeypot field - hidden from users, catches bots -->
                <div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">
                    <input type="text" name="ofast_hp_field" value="" tabindex="-1" autocomplete="off">
                </div>

                <div class="ofast-fields-container" style="display: flex; flex-wrap: wrap; gap: 0 20px;">
                    <?php foreach ($fields as $field): ?>
                        <?php $this->render_field($field, $form_id, $label_size, $input_border); ?>
                    <?php endforeach; ?>
                </div>

                <?php
                // Add Turnstile if configured
                if (class_exists('Ofast_X_Turnstile')) {
                    $turnstile = Ofast_X_Turnstile::get_instance();
                    if ($turnstile->is_configured()) {
                        echo '<div class="ofast-form-field ofast-turnstile-field">';
                        echo $turnstile->render_widget('form-' . $form_id);
                        echo '</div>';
                        echo Ofast_X_Turnstile::render_script();
                    }
                }
                ?>

                <div class="ofast-form-field ofast-form-submit">
                    <button type="submit" class="ofast-form-button" style="background: <?php echo $btn_bg; ?>; color: <?php echo $btn_text; ?>; border: none; padding: 14px 30px; font-size: 16px; font-weight: 600; border-radius: <?php echo $btn_radius; ?>px; cursor: pointer;"><?php echo esc_html($submit_text); ?></button>
                </div>

                <div class="ofast-form-message" style="display:none;"></div>
            </form>
        </div>

        <style>
            .ofast-form-wrapper {
                max-width: 600px;
                margin: 0 auto;
            }

            .ofast-form-wrapper .ofast-form {
                background: #fff;
                padding: 30px;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            }

            .ofast-form-wrapper .ofast-form-field {
                margin-bottom: 20px;
            }

            /* Responsive: stack half-width fields on mobile */
            @media (max-width: 600px) {
                .ofast-form-wrapper .ofast-width-half {
                    width: 100% !important;
                    flex: 0 0 100% !important;
                }
            }

            .ofast-form-wrapper .ofast-form-field label {
                display: block;
                font-weight: 600;
                font-size: 14px;
                margin-bottom: 8px;
            }

            .ofast-form-wrapper .ofast-form-field .required {
                color: #dc3545;
            }

            .ofast-form-wrapper .ofast-form-field input[type="text"],
            .ofast-form-wrapper .ofast-form-field input[type="email"],
            .ofast-form-wrapper .ofast-form-field input[type="tel"],
            .ofast-form-wrapper .ofast-form-field input[type="url"],
            .ofast-form-wrapper .ofast-form-field input[type="number"],
            .ofast-form-wrapper .ofast-form-field input[type="date"],
            .ofast-form-wrapper .ofast-form-field textarea,
            .ofast-form-wrapper .ofast-form-field select {
                width: 100%;
                padding: 12px 15px;
                border: 1px solid #ddd;
                border-radius: 5px;
                font-size: 16px;
                transition: border-color 0.2s;
                box-sizing: border-box;
            }

            .ofast-form-wrapper .ofast-form-field input:focus,
            .ofast-form-wrapper .ofast-form-field textarea:focus,
            .ofast-form-wrapper .ofast-form-field select:focus {
                outline: none;
                border-color: #2271b1;
            }

            .ofast-form-wrapper .ofast-form-field textarea {
                min-height: 120px;
                resize: vertical;
            }

            .ofast-form-wrapper .ofast-form-field .checkbox-group,
            .ofast-form-wrapper .ofast-form-field .radio-group {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }

            .ofast-form-wrapper .ofast-form-field .checkbox-group label,
            .ofast-form-wrapper .ofast-form-field .radio-group label {
                font-weight: normal;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .ofast-form-wrapper .ofast-form-button {
                background: #2271b1;
                color: #fff;
                border: none;
                padding: 14px 30px;
                font-size: 16px;
                font-weight: 600;
                border-radius: 5px;
                cursor: pointer;
                transition: background 0.2s;
            }

            .ofast-form-wrapper .ofast-form-button:hover {
                background: #135e96;
            }

            .ofast-form-wrapper .ofast-form-button:disabled {
                background: #ccc;
                cursor: not-allowed;
            }

            .ofast-form-wrapper .ofast-form-message {
                padding: 12px 15px;
                border-radius: 5px;
                margin-top: 20px;
                font-size: 14px;
            }

            .ofast-form-wrapper .ofast-form-message.success {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }

            .ofast-form-wrapper .ofast-form-message.error {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }

            .ofast-form-wrapper .ofast-form-field.has-error input,
            .ofast-form-wrapper .ofast-form-field.has-error textarea,
            .ofast-form-wrapper .ofast-form-field.has-error select {
                border-color: #dc3545;
            }

            .ofast-form-wrapper .ofast-form-field .field-error {
                color: #dc3545;
                font-size: 13px;
                margin-top: 5px;
            }
        </style>

        <script>
            (function($) {
                $(document).ready(function() {
                    $('#ofast-form-<?php echo $form_id; ?> .ofast-form').on('submit', function(e) {
                        e.preventDefault();

                        var $form = $(this);
                        var $btn = $form.find('.ofast-form-button');
                        var $msg = $form.find('.ofast-form-message');
                        var originalText = $btn.text();

                        // Clear errors
                        $form.find('.has-error').removeClass('has-error');
                        $form.find('.field-error').remove();
                        $msg.hide().removeClass('success error');

                        // Disable button
                        $btn.prop('disabled', true).text('Sending...');

                        // Get form data
                        var formData = new FormData(this);
                        formData.append('action', 'ofast_submit_form');

                        $.ajax({
                            url: ofastForms.ajaxurl,
                            type: 'POST',
                            data: formData,
                            processData: false,
                            contentType: false,
                            success: function(response) {
                                if (response.success) {
                                    if (response.data.redirect) {
                                        window.location.href = response.data.redirect;
                                    } else {
                                        $msg.addClass('success').html(response.data.message).show();
                                        $form[0].reset();
                                        // Reset Turnstile if present
                                        if (window.turnstile) {
                                            turnstile.reset();
                                        }
                                    }
                                } else {
                                    if (response.data.field_errors) {
                                        $.each(response.data.field_errors, function(field, error) {
                                            var $field = $form.find('[name="fields[' + field + ']"]').closest('.ofast-form-field');
                                            $field.addClass('has-error');
                                            $field.append('<div class="field-error">' + error + '</div>');
                                        });
                                    }
                                    $msg.addClass('error').html(response.data.message || 'An error occurred.').show();
                                }
                                $btn.prop('disabled', false).text(originalText);
                            },
                            error: function() {
                                $msg.addClass('error').html('Connection error. Please try again.').show();
                                $btn.prop('disabled', false).text(originalText);
                            }
                        });
                    });
                });
            })(jQuery);
        </script>
    <?php

        return ob_get_clean();
    }

    /**
     * Render a single field
     */
    private function render_field($field, $form_id, $label_size = 14, $input_border = '#dddddd')
    {
        $type = $field['type'] ?? 'text';
        $label = $field['label'] ?? '';
        $placeholder = $field['placeholder'] ?? '';
        $required = !empty($field['required']);
        $options = $field['options'] ?? '';
        $width = $field['width'] ?? 'full';
        $field_name = 'fields[' . sanitize_title($label) . ']';

        $input_style = 'width:100%; padding:12px 15px; border:1px solid ' . $input_border . '; border-radius:5px; font-size:16px; box-sizing:border-box;';
        $label_style = 'display:block; font-weight:600; font-size:' . $label_size . 'px; margin-bottom:8px;';

        // Calculate field width for flexbox
        $field_width = $width === 'half' ? 'calc(50% - 10px)' : '100%';

        if ($type === 'hidden') {
            echo '<input type="hidden" name="' . esc_attr($field_name) . '" value="' . esc_attr($placeholder) . '">';
            return;
        }
    ?>
        <div class="ofast-form-field ofast-field-<?php echo $type; ?> ofast-width-<?php echo $width; ?>" style="margin-bottom: 20px; width: <?php echo $field_width; ?>; flex: 0 0 <?php echo $field_width; ?>; box-sizing: border-box;">
            <?php if ($label): ?>
                <label style="<?php echo $label_style; ?>">
                    <?php echo esc_html($label); ?>
                    <?php if ($required): ?><span class="required" style="color:#dc3545;">*</span><?php endif; ?>
                </label>
            <?php endif; ?>

            <?php
            switch ($type) {
                case 'textarea':
                    echo '<textarea name="' . esc_attr($field_name) . '" placeholder="' . esc_attr($placeholder) . '" style="' . $input_style . ' min-height:120px; resize:vertical;"' . ($required ? ' required' : '') . '></textarea>';
                    break;

                case 'select':
                    echo '<select name="' . esc_attr($field_name) . '" style="' . $input_style . '"' . ($required ? ' required' : '') . '>';
                    echo '<option value="">' . ($placeholder ?: 'Select an option') . '</option>';
                    if ($options) {
                        $opts = explode("\n", $options);
                        foreach ($opts as $opt) {
                            $opt = trim($opt);
                            if ($opt) {
                                echo '<option value="' . esc_attr($opt) . '">' . esc_html($opt) . '</option>';
                            }
                        }
                    }
                    echo '</select>';
                    break;

                case 'radio':
                    echo '<div class="radio-group" style="display:flex; flex-direction:column; gap:8px;">';
                    if ($options) {
                        $opts = explode("\n", $options);
                        foreach ($opts as $i => $opt) {
                            $opt = trim($opt);
                            if ($opt) {
                                echo '<label style="font-weight:normal; display:flex; align-items:center; gap:8px;"><input type="radio" name="' . esc_attr($field_name) . '" value="' . esc_attr($opt) . '"' . ($required && $i === 0 ? ' required' : '') . '> ' . esc_html($opt) . '</label>';
                            }
                        }
                    }
                    echo '</div>';
                    break;

                case 'checkbox':
                    echo '<div class="checkbox-group" style="display:flex; flex-direction:column; gap:8px;">';
                    if ($options) {
                        $opts = explode("\n", $options);
                        foreach ($opts as $opt) {
                            $opt = trim($opt);
                            if ($opt) {
                                echo '<label style="font-weight:normal; display:flex; align-items:center; gap:8px;"><input type="checkbox" name="' . esc_attr($field_name) . '[]" value="' . esc_attr($opt) . '"> ' . esc_html($opt) . '</label>';
                            }
                        }
                    }
                    echo '</div>';
                    break;

                case 'email':
                    echo '<input type="email" name="' . esc_attr($field_name) . '" placeholder="' . esc_attr($placeholder) . '" style="' . $input_style . '"' . ($required ? ' required' : '') . '>';
                    break;

                case 'phone':
                    echo '<input type="tel" name="' . esc_attr($field_name) . '" placeholder="' . esc_attr($placeholder) . '" style="' . $input_style . '"' . ($required ? ' required' : '') . '>';
                    break;

                case 'url':
                    echo '<input type="url" name="' . esc_attr($field_name) . '" placeholder="' . esc_attr($placeholder) . '" style="' . $input_style . '"' . ($required ? ' required' : '') . '>';
                    break;

                case 'number':
                    echo '<input type="number" name="' . esc_attr($field_name) . '" placeholder="' . esc_attr($placeholder) . '" style="' . $input_style . '"' . ($required ? ' required' : '') . '>';
                    break;

                case 'date':
                    echo '<input type="date" name="' . esc_attr($field_name) . '" style="' . $input_style . '"' . ($required ? ' required' : '') . '>';
                    break;

                default: // text
                    echo '<input type="text" name="' . esc_attr($field_name) . '" placeholder="' . esc_attr($placeholder) . '" style="' . $input_style . '"' . ($required ? ' required' : '') . '>';
                    break;
            }
            ?>
        </div>
<?php
    }
}
