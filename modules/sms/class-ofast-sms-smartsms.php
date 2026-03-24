<?php
/**
 * Ofast X - SMS Provider: SmartSMSSolutions
 * Nigerian SMS via SmartSMSSolutions API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_SMS_SmartSMS
{
    private $base_url = 'https://smartsmssolutions.com/api/json.php';

    /**
     * Send SMS via SmartSMSSolutions
     */
    public function send($to, $message, $sender_id = '')
    {
        $api_token = $this->get_credential('token');
        $from = !empty($sender_id) ? $sender_id : $this->get_credential('sender_id');
        $routing = get_option('ofast_sms_smartsms_routing', '3');

        if (empty($api_token) || empty($from)) {
            return array('success' => false, 'message' => 'SmartSMSSolutions credentials not configured.');
        }

        $params = array(
            'token'   => $api_token,
            'sender'  => $from,
            'to'      => $to,
            'message' => $message,
            'routing' => $routing,
            'type'    => '0',
        );

        $url = $this->base_url . '?' . http_build_query($params);

        $response = wp_remote_get($url, array('timeout' => 30));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // SmartSMS returns different response formats
        if (is_array($data)) {
            $code = $data['code'] ?? $data['comment'] ?? '';
            if ($code === '1000' || (isset($data['successful']) && $data['successful'] !== '')) {
                return array('success' => true, 'message' => 'SMS sent successfully.', 'sid' => $data['message_id'] ?? '');
            }
            $error = $data['comment'] ?? $data['message'] ?? 'Unknown SmartSMS error';
            return array('success' => false, 'message' => $error);
        }

        // Fallback: raw response
        if (strpos($body, '1000') !== false || stripos($body, 'success') !== false) {
            return array('success' => true, 'message' => 'SMS sent successfully.');
        }

        return array('success' => false, 'message' => 'SmartSMS error: ' . substr($body, 0, 200));
    }

    /**
     * Check if configured
     */
    public function is_configured()
    {
        return !empty($this->get_credential('token'))
            && !empty($this->get_credential('sender_id'));
    }

    /**
     * Test connection by checking balance
     */
    public function test_connection()
    {
        $api_token = $this->get_credential('token');

        if (empty($api_token)) {
            return array('success' => false, 'message' => 'API token missing.');
        }

        $response = wp_remote_get('https://smartsmssolutions.com/api/balance.php?token=' . urlencode($api_token), array(
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (is_array($data) && isset($data['balance'])) {
            return array('success' => true, 'message' => 'Connected. Balance: ₦' . number_format(floatval($data['balance']), 2));
        }

        // Fallback: raw response might just be the balance
        if (is_numeric(trim($body))) {
            return array('success' => true, 'message' => 'Connected. Balance: ₦' . number_format(floatval($body), 2));
        }

        return array('success' => false, 'message' => 'Invalid API token.');
    }

    /**
     * Get required credential fields
     */
    public static function get_fields()
    {
        return array(
            'token'     => array('label' => 'API Token', 'type' => 'password', 'placeholder' => 'Your SmartSMS API token'),
            'sender_id' => array('label' => 'Sender ID', 'type' => 'text', 'placeholder' => 'e.g. MyBrand'),
            'routing'   => array(
                'label' => 'Routing',
                'type'  => 'select',
                'options' => array(
                    '3' => 'Corporate (DND bypass)',
                    '2' => 'Standard',
                    '6' => 'Refund',
                ),
            ),
        );
    }

    /**
     * Get a stored credential (decrypted)
     */
    private function get_credential($key)
    {
        $value = get_option('ofast_sms_smartsms_' . $key, '');
        if (!empty($value) && class_exists('Ofast_X_Security_Hardening')) {
            $decrypted = Ofast_X_Security_Hardening::decrypt_option($value);
            if ($decrypted !== false) {
                return $decrypted;
            }
        }
        return $value;
    }
}
