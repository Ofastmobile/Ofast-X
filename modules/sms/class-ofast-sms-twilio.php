<?php
/**
 * Ofast X - SMS Provider: Twilio
 * International SMS via Twilio REST API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_SMS_Twilio
{
    /**
     * Send SMS via Twilio
     *
     * @param string $to    Recipient phone number (E.164 format)
     * @param string $message SMS body
     * @param string $sender_id Twilio phone number
     * @return array ['success' => bool, 'message' => string, 'sid' => string|null]
     */
    public function send($to, $message, $sender_id = '')
    {
        $account_sid = $this->get_credential('sid');
        $auth_token = $this->get_credential('token');
        $from = !empty($sender_id) ? $sender_id : $this->get_credential('phone');

        if (empty($account_sid) || empty($auth_token) || empty($from)) {
            return array('success' => false, 'message' => 'Twilio credentials not configured.');
        }

        $endpoint = 'https://api.twilio.com/2010-04-01/Accounts/' . $account_sid . '/Messages.json';

        $response = wp_remote_post($endpoint, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($account_sid . ':' . $auth_token),
            ),
            'body' => array(
                'To'   => $to,
                'From' => $from,
                'Body' => $message,
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code >= 200 && $code < 300 && !empty($body['sid'])) {
            return array('success' => true, 'message' => 'SMS sent successfully.', 'sid' => $body['sid']);
        }

        $error = isset($body['message']) ? $body['message'] : 'Unknown Twilio error (HTTP ' . $code . ')';
        return array('success' => false, 'message' => $error);
    }

    /**
     * Check if Twilio is configured
     */
    public function is_configured()
    {
        return !empty($this->get_credential('sid'))
            && !empty($this->get_credential('token'))
            && !empty($this->get_credential('phone'));
    }

    /**
     * Test connection by checking account info
     */
    public function test_connection()
    {
        $account_sid = $this->get_credential('sid');
        $auth_token = $this->get_credential('token');

        if (empty($account_sid) || empty($auth_token)) {
            return array('success' => false, 'message' => 'Credentials missing.');
        }

        $response = wp_remote_get('https://api.twilio.com/2010-04-01/Accounts/' . $account_sid . '.json', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($account_sid . ':' . $auth_token),
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['sid'])) {
            return array('success' => true, 'message' => 'Connected to Twilio account: ' . ($body['friendly_name'] ?? $body['sid']));
        }

        return array('success' => false, 'message' => 'Invalid Twilio credentials.');
    }

    /**
     * Get required credential fields for admin UI
     */
    public static function get_fields()
    {
        return array(
            'sid'   => array('label' => 'Account SID', 'type' => 'text', 'placeholder' => 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'),
            'token' => array('label' => 'Auth Token', 'type' => 'password', 'placeholder' => 'Your Twilio auth token'),
            'phone' => array('label' => 'Twilio Phone Number', 'type' => 'text', 'placeholder' => '+1234567890'),
        );
    }

    /**
     * Get a stored credential (decrypted)
     */
    private function get_credential($key)
    {
        $value = get_option('ofast_sms_twilio_' . $key, '');
        if (!empty($value) && class_exists('Ofast_X_Security_Hardening')) {
            $decrypted = Ofast_X_Security_Hardening::decrypt_option($value);
            if ($decrypted !== false) {
                return $decrypted;
            }
        }
        return $value;
    }
}
