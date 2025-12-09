<?php

/**
 * Ofast X - Form Notifications
 * Email and WhatsApp notification templates for form submissions
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Forms_Notifications
{
    /**
     * Format email notification for form submission
     */
    public static function format_email($form, $data)
    {
        $site_name = get_bloginfo('name');
        $form_title = $form->title ?? 'Contact Form';

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;padding:20px;background:#f5f5f5;">';
        $html .= '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;">';

        // Header
        $html .= '<div style="background:#2271b1;padding:20px;text-align:center;">';
        $html .= '<h1 style="color:#fff;margin:0;">New Form Submission</h1>';
        $html .= '</div>';

        // Content
        $html .= '<div style="padding:30px;">';
        $html .= '<p style="font-size:16px;color:#333;">You have received a new submission from <strong>' . esc_html($form_title) . '</strong>:</p>';

        // Data table
        $html .= '<table style="width:100%;border-collapse:collapse;margin:20px 0;">';
        foreach ($data as $label => $value) {
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $html .= '<tr>';
            $html .= '<td style="padding:12px;border:1px solid #eee;background:#f9f9f9;font-weight:bold;width:35%;">' . esc_html($label) . '</td>';
            $html .= '<td style="padding:12px;border:1px solid #eee;">' . nl2br(esc_html($value)) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';

        // Meta info
        $html .= '<div style="margin-top:30px;padding-top:20px;border-top:1px solid #eee;color:#666;font-size:13px;">';
        $html .= '<p>Submitted at: ' . current_time('F j, Y g:i a') . '</p>';
        $html .= '<p>From: <a href="' . home_url() . '">' . $site_name . '</a></p>';
        $html .= '</div>';

        $html .= '</div>';
        $html .= '</div></body></html>';

        return $html;
    }

    /**
     * Format WhatsApp message for form submission
     */
    public static function format_whatsapp($form, $data)
    {
        $form_title = $form->title ?? 'Contact Form';
        $site_name = get_bloginfo('name');

        $message = "New {$form_title} submission on {$site_name}:\n\n";

        foreach ($data as $label => $value) {
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $message .= "*{$label}:* {$value}\n";
        }

        $message .= "\n" . current_time('F j, Y g:i a');

        return $message;
    }

    /**
     * Format Google Sheets row
     */
    public static function format_sheets_row($form, $data)
    {
        // First column is timestamp
        $row = array(current_time('Y-m-d H:i:s'));

        // Add each field value
        foreach ($data as $label => $value) {
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $row[] = $value;
        }

        return $row;
    }

    /**
     * Get sheet headers based on form fields
     */
    public static function get_sheets_headers($form)
    {
        $headers = array('Timestamp');

        if (!empty($form->fields)) {
            foreach ($form->fields as $field) {
                $headers[] = $field['label'] ?? 'Field';
            }
        }

        return $headers;
    }
}
