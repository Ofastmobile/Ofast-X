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

        ob_start();
?>
        <div class="ofast-form-wrapper" id="ofast-form-<?php echo $form_id; ?>">
            <form class="ofast-form" data-form-id="<?php echo $form_id; ?>" method="post">
                <?php wp_nonce_field('ofast_form_submit_' . $form_id, 'ofast_form_nonce'); ?>
                <input type="hidden" name="form_id" value="<?php echo $form_id; ?>">

                <?php foreach ($fields as $field): ?>
                    <?php $this->render_field($field, $form_id); ?>
                <?php endforeach; ?>

                <?php
                // Add Turnstile if configured
                if (class_exists('Ofast_X_Turnstile')) {
                    $turnstile = Ofast_X_Turnstile::get_instance();
                    if ($turnstile->is_configured()) {
                        echo '<div class="ofast-form-field">';
                        $turnstile->render_widget();
                        echo '</div>';
                    }
                }
                ?>

                <div class="ofast-form-field ofast-form-submit">
                    <button type="submit" class="ofast-form-button"><?php echo esc_html($submit_text); ?></button>
                </div>

                <div class="ofast-form-message" style="display:none;"></div>
            </form>
        </div>

        <style>
            .ofast-form-wrapper {
                max-width: 600px;
                margin: 0 auto;
            }

            .ofast-form {
                background: #fff;
                padding: 30px;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            }

            .ofast-form-field {
                margin-bottom: 20px;
            }

            .ofast-form-field label {
                display: block;
                font-weight: 600;
                margin-bottom: 5px;
            }

            .ofast-form-field .required {
                color: #dc3545;
            }

            .ofast-form-field input[type="text"],
            .ofast-form-field input[type="email"],
            .ofast-form-field input[type="tel"],
            .ofast-form-field input[type="url"],
            .ofast-form-field input[type="number"],
            .ofast-form-field input[type="date"],
            .ofast-form-field textarea,
            .ofast-form-field select {
                width: 100%;
                padding: 12px 15px;
                border: 1px solid #ddd;
                border-radius: 5px;
                font-size: 16px;
                transition: border-color 0.2s;
            }

            .ofast-form-field input:focus,
            .ofast-form-field textarea:focus,
            .ofast-form-field select:focus {
                outline: none;
                border-color: #2271b1;
            }

            .ofast-form-field textarea {
                min-height: 120px;
                resize: vertical;
            }

            .ofast-form-field .checkbox-group,
            .ofast-form-field .radio-group {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }

            .ofast-form-field .checkbox-group label,
            .ofast-form-field .radio-group label {
                font-weight: normal;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .ofast-form-button {
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

            .ofast-form-button:hover {
                background: #135e96;
            }

            .ofast-form-button:disabled {
                background: #ccc;
                cursor: not-allowed;
            }

            .ofast-form-message {
                padding: 15px;
                border-radius: 5px;
                margin-top: 20px;
            }

            .ofast-form-message.success {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }

            .ofast-form-message.error {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }

            .ofast-form-field.has-error input,
            .ofast-form-field.has-error textarea,
            .ofast-form-field.has-error select {
                border-color: #dc3545;
            }

            .ofast-form-field .field-error {
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
    private function render_field($field, $form_id)
    {
        $type = $field['type'] ?? 'text';
        $label = $field['label'] ?? '';
        $placeholder = $field['placeholder'] ?? '';
        $required = !empty($field['required']);
        $options = $field['options'] ?? '';
        $field_name = 'fields[' . sanitize_title($label) . ']';

        if ($type === 'hidden') {
            echo '<input type="hidden" name="' . esc_attr($field_name) . '" value="' . esc_attr($placeholder) . '">';
            return;
        }
    ?>
        <div class="ofast-form-field ofast-field-<?php echo $type; ?>">
            <?php if ($label): ?>
                <label>
                    <?php echo esc_html($label); ?>
                    <?php if ($required): ?><span class="required">*</span><?php endif; ?>
                </label>
            <?php endif; ?>

            <?php
            switch ($type) {
                case 'textarea':
                    echo '<textarea name="' . esc_attr($field_name) . '" placeholder="' . esc_attr($placeholder) . '"' . ($required ? ' required' : '') . '></textarea>';
                    break;

                case 'select':
                    echo '<select name="' . esc_attr($field_name) . '"' . ($required ? ' required' : '') . '>';
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
                    echo '<div class="radio-group">';
                    if ($options) {
                        $opts = explode("\n", $options);
                        foreach ($opts as $i => $opt) {
                            $opt = trim($opt);
                            if ($opt) {
                                echo '<label><input type="radio" name="' . esc_attr($field_name) . '" value="' . esc_attr($opt) . '"' . ($required && $i === 0 ? ' required' : '') . '> ' . esc_html($opt) . '</label>';
                            }
                        }
                    }
                    echo '</div>';
                    break;

                case 'checkbox':
                    echo '<div class="checkbox-group">';
                    if ($options) {
                        $opts = explode("\n", $options);
                        foreach ($opts as $opt) {
                            $opt = trim($opt);
                            if ($opt) {
                                echo '<label><input type="checkbox" name="' . esc_attr($field_name) . '[]" value="' . esc_attr($opt) . '"> ' . esc_html($opt) . '</label>';
                            }
                        }
                    }
                    echo '</div>';
                    break;

                case 'email':
                    echo '<input type="email" name="' . esc_attr($field_name) . '" placeholder="' . esc_attr($placeholder) . '"' . ($required ? ' required' : '') . '>';
                    break;

                case 'phone':
                    echo '<input type="tel" name="' . esc_attr($field_name) . '" placeholder="' . esc_attr($placeholder) . '"' . ($required ? ' required' : '') . '>';
                    break;

                case 'url':
                    echo '<input type="url" name="' . esc_attr($field_name) . '" placeholder="' . esc_attr($placeholder) . '"' . ($required ? ' required' : '') . '>';
                    break;

                case 'number':
                    echo '<input type="number" name="' . esc_attr($field_name) . '" placeholder="' . esc_attr($placeholder) . '"' . ($required ? ' required' : '') . '>';
                    break;

                case 'date':
                    echo '<input type="date" name="' . esc_attr($field_name) . '"' . ($required ? ' required' : '') . '>';
                    break;

                default: // text
                    echo '<input type="text" name="' . esc_attr($field_name) . '" placeholder="' . esc_attr($placeholder) . '"' . ($required ? ' required' : '') . '>';
                    break;
            }
            ?>
        </div>
<?php
    }
}
