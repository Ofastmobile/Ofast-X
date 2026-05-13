<?php
/**
 * Ofast X - SMS Provider: Africa's Talking
 * Pan-African SMS via Africa's Talking API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_SMS_AfricasTalking
{
    private $live_url = 'https://api.africastalking.com/version1/messaging';
    private $sandbox_url = 'https://api.sandbox.africastalking.com/version1/messaging';

    /**
     * Send SMS via Africa's Talking
     */
    public function send($to, $message, $sender_id = '')
    {
        $api_key = $this->get_credential('api_key');
        $username = $this->get_credential('username');
        $from = !empty($sender_id) ? $sender_id : $this->get_credential('sender_id');
        $sandbox = get_option('ofast_sms_africastalking_sandbox', false);

        if (empty($api_key) || empty($username)) {
            return array('success' => false, 'message' => 'Africa\'s Talking credentials not configured.');
        }

        $endpoint = $sandbox ? $this->sandbox_url : $this->live_url;

        $body = array(
            'username' => $username,
            'to'       => $to,
            'message'  => $message,
        );

        if (!empty($from)) {
            $body['from'] = $from;
        }

        $response = wp_remote_post($endpoint, array(
            'headers' => array(
                'apiKey'       => $api_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept'       => 'application/json',
            ),
            'body'    => $body,
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($data['SMSMessageData']['Recipients']) && !empty($data['SMSMessageData']['Recipients'])) {
            $recipient = $data['SMSMessageData']['Recipients'][0];
            $status = $recipient['status'] ?? '';

            if ($status === 'Success') {
                return array('success' => true, 'message' => 'SMS sent successfully.', 'sid' => $recipient['messageId'] ?? '');
            }

            return array('success' => false, 'message' => 'Delivery failed: ' . $status);
        }

        $error = $data['SMSMessageData']['Message'] ?? 'Unknown Africa\'s Talking error';
        return array('success' => false, 'message' => $error);
    }

    /**
     * Check if configured
     */
    public function is_configured()
    {
        return !empty($this->get_credential('api_key'))
            && !empty($this->get_credential('username'));
    }

    /**
     * Test connection
     */
    public function test_connection()
    {
        $api_key = $this->get_credential('api_key');
        $username = $this->get_credential('username');

        if (empty($api_key) || empty($username)) {
            return array('success' => false, 'message' => 'Credentials missing.');
        }

        // AT doesn't have a simple "ping" endpoint, so we verify by checking the user data endpoint
        $response = wp_remote_get('https://api.africastalking.com/version1/user?username=' . urlencode($username), array(
            'headers' => array('apiKey' => $api_key, 'Accept' => 'application/json'),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['UserData'])) {
            $balance = $data['UserData']['balance'] ?? 'N/A';
            return array('success' => true, 'message' => 'Connected. Balance: ' . $balance);
        }

        return array('success' => false, 'message' => 'Invalid credentials.');
    }

    /**
     * Get required credential fields
     */
    public static function get_fields()
    {
        return array(
            'username'  => array('label' => 'Username', 'type' => 'text', 'placeholder' => 'Your AT username'),
            'api_key'   => array('label' => 'API Key', 'type' => 'password', 'placeholder' => 'Your API key'),
            'sender_id' => array('label' => 'Sender ID (optional)', 'type' => 'text', 'placeholder' => 'e.g. MyBrand'),
            'sandbox'   => array('label' => 'Sandbox Mode', 'type' => 'checkbox', 'description' => 'Use sandbox for testing'),
        );
    }

    /**
     * Get a stored credential (decrypted)
     */
    private function get_credential($key)
    {
        $value = get_option('ofast_sms_africastalking_' . $key, '');
        if (!empty($value) && class_exists('Ofast_X_Security_Hardening')) {
            $decrypted = Ofast_X_Security_Hardening::decrypt_option($value);
            if ($decrypted !== false) {
                return $decrypted;
            }
        }
        return $value;
    }
}
