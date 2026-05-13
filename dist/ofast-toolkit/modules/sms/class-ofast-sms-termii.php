<?php
/**
 * Ofast X - SMS Provider: Termii
 * Nigerian SMS via Termii API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_SMS_Termii
{
    private $base_url = 'https://api.ng.termii.com/api';

    /**
     * Send SMS via Termii
     */
    public function send($to, $message, $sender_id = '')
    {
        $api_key = $this->get_credential('api_key');
        $from = !empty($sender_id) ? $sender_id : $this->get_credential('sender_id');
        $channel = get_option('ofast_sms_termii_channel', 'generic');

        if (empty($api_key) || empty($from)) {
            return array('success' => false, 'message' => 'Termii credentials not configured.');
        }

        $response = wp_remote_post($this->base_url . '/sms/send', array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'api_key' => $api_key,
                'to'      => $to,
                'from'    => $from,
                'sms'     => $message,
                'type'    => 'plain',
                'channel' => $channel,
            )),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code >= 200 && $code < 300 && !empty($data['message_id'])) {
            return array('success' => true, 'message' => 'SMS sent successfully.', 'sid' => $data['message_id']);
        }

        $error = $data['message'] ?? 'Unknown Termii error (HTTP ' . $code . ')';
        return array('success' => false, 'message' => $error);
    }

    /**
     * Check if configured
     */
    public function is_configured()
    {
        return !empty($this->get_credential('api_key'))
            && !empty($this->get_credential('sender_id'));
    }

    /**
     * Test connection by checking balance
     */
    public function test_connection()
    {
        $api_key = $this->get_credential('api_key');

        if (empty($api_key)) {
            return array('success' => false, 'message' => 'API key missing.');
        }

        $response = wp_remote_get($this->base_url . '/get-balance?api_key=' . urlencode($api_key), array(
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['balance'])) {
            return array('success' => true, 'message' => 'Connected. Balance: ₦' . number_format($data['balance'], 2));
        }

        return array('success' => false, 'message' => 'Invalid API key.');
    }

    /**
     * Get required credential fields
     */
    public static function get_fields()
    {
        return array(
            'api_key'   => array('label' => 'API Key', 'type' => 'password', 'placeholder' => 'Your Termii API key'),
            'sender_id' => array('label' => 'Sender ID', 'type' => 'text', 'placeholder' => 'e.g. MyBrand'),
            'channel'   => array(
                'label' => 'Channel',
                'type'  => 'select',
                'options' => array(
                    'generic' => 'Generic (Promotional)',
                    'dnd'     => 'DND (Transactional — bypasses Do Not Disturb)',
                ),
            ),
        );
    }

    /**
     * Get a stored credential (decrypted)
     */
    private function get_credential($key)
    {
        $value = get_option('ofast_sms_termii_' . $key, '');
        if (!empty($value) && class_exists('Ofast_X_Security_Hardening')) {
            $decrypted = Ofast_X_Security_Hardening::decrypt_option($value);
            if ($decrypted !== false) {
                return $decrypted;
            }
        }
        return $value;
    }
}
