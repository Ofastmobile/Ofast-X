<?php

/**
 * Ofast X - Form Validator
 * Validates form field submissions
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Forms_Validator
{
    /**
     * Default maximum input lengths for different field types
     */
    private $default_max_lengths = array(
        'email' => 254,     // RFC 5321 maximum email length
        'phone' => 50,      // Generous limit for international formats
        'url' => 2048,      // Common browser URL limit
        'number' => 50,     // Sufficient for most numeric inputs
        'date' => 100,      // Sufficient for various date formats
        'text' => 500,      // Current default for text fields
        'textarea' => 10000, // Current limit for textarea
        'select' => 200,    // Reasonable limit for option values
        'radio' => 200,     // Reasonable limit for option values
        'checkbox' => 200   // Per-option limit for checkboxes
    );

    /**
     * Validate submitted data against form fields
     * 
     * @param array $submitted The submitted field values
     * @param array $form_fields The form field definitions
     * @return array ['valid' => bool, 'errors' => array, 'data' => array]
     */
    public function validate($submitted, $form_fields)
    {
        $errors = array();
        $clean_data = array();

        foreach ($form_fields as $field) {
            $label = $field['label'] ?? '';
            $type = $field['type'] ?? 'text';
            $required = !empty($field['required']);
            $field_key = sanitize_title($label);
            $value = $submitted[$field_key] ?? '';

            // Handle array values (checkboxes)
            if (is_array($value)) {
                $value = array_map('sanitize_text_field', $value);
            } else {
                $value = trim($value);
            }

            // Required check
            if ($required) {
                $is_empty = is_array($value) ? empty($value) : ($value === '');
                if ($is_empty) {
                    $errors[$field_key] = $label . ' is required.';
                    continue;
                }
            }

            // Skip validation if empty and not required
            if (empty($value)) {
                continue;
            }

            // Validate input length before type-specific validation
            if (!$this->validate_length($value, $type, $field_key, $errors)) {
                continue;
            }

            // Type-specific validation
            switch ($type) {
                case 'email':
                    if (!is_email($value)) {
                        $errors[$field_key] = 'Please enter a valid email address.';
                    } else {
                        $value = sanitize_email($value);
                    }
                    break;

                case 'phone':
                    // Remove common formatting characters
                    $phone = preg_replace('/[\s\-\(\)\+]/', '', $value);
                    if (!preg_match('/^[\d]{7,15}$/', $phone)) {
                        $errors[$field_key] = 'Please enter a valid phone number.';
                    } else {
                        $value = sanitize_text_field($value);
                    }
                    break;

                case 'url':
                    if (!filter_var($value, FILTER_VALIDATE_URL)) {
                        $errors[$field_key] = 'Please enter a valid URL.';
                    } else {
                        $value = esc_url_raw($value);
                    }
                    break;

                case 'number':
                    if (!is_numeric($value)) {
                        $errors[$field_key] = 'Please enter a valid number.';
                    } else {
                        $value = floatval($value);
                    }
                    break;

                case 'date':
                    if (!strtotime($value)) {
                        $errors[$field_key] = 'Please enter a valid date.';
                    } else {
                        $value = sanitize_text_field($value);
                    }
                    break;

                case 'textarea':
                    $value = sanitize_textarea_field($value);
                    break;

                case 'select':
                case 'radio':
                    // Validate against options
                    if (!empty($field['options'])) {
                        $valid_options = array_map('trim', explode("\n", $field['options']));
                        if (!in_array($value, $valid_options)) {
                            $errors[$field_key] = 'Please select a valid option.';
                        }
                    }
                    $value = sanitize_text_field($value);
                    break;

                case 'checkbox':
                    if (is_array($value) && !empty($field['options'])) {
                        $valid_options = array_map('trim', explode("\n", $field['options']));
                        foreach ($value as $v) {
                            if (!in_array($v, $valid_options)) {
                                $errors[$field_key] = 'Please select valid options.';
                                break;
                            }
                        }
                    }
                    break;

                default:
                    $value = sanitize_text_field($value);
                    break;
            }

            // Store clean value with label as key
            if (!isset($errors[$field_key])) {
                $clean_data[$label] = $value;
            }
        }

        return array(
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $clean_data
        );
    }

    /**
     * Validate input length for a field
     * 
     * @param mixed $value The input value to validate
     * @param string $type The field type
     * @param string $field_key The field key for error reporting
     * @param array &$errors The errors array to update
     * @return bool True if validation passed, false otherwise
     */
    private function validate_length($value, $type, $field_key, &$errors)
    {
        $max_length = $this->default_max_lengths[$type] ?? $this->default_max_lengths['text'];
        
        if (is_array($value)) {
            // Handle checkbox arrays
            $per_option_limit = $this->default_max_lengths['checkbox'];
            foreach ($value as $option_value) {
                if (mb_strlen($option_value, 'UTF-8') > $per_option_limit) {
                    $errors[$field_key] = 'Option value is too long (max ' . $per_option_limit . ' characters).';
                    return false;
                }
            }
            return true;
        }
        
        $length = mb_strlen($value, 'UTF-8');
        if ($length > $max_length) {
            $errors[$field_key] = 'Input is too long (max ' . number_format($max_length) . ' characters).';
            return false;
        }
        
        return true;
    }

    /**
     * Sanitize a single value based on type
     */
    public function sanitize($value, $type = 'text')
    {
        switch ($type) {
            case 'email':
                return sanitize_email($value);
            case 'url':
                return esc_url_raw($value);
            case 'textarea':
                return sanitize_textarea_field($value);
            case 'number':
                return floatval($value);
            default:
                return sanitize_text_field($value);
        }
    }
}
