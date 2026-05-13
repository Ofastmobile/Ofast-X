<?php

/**
 * Ofast X - SMTP Port Connectivity Test
 * Probes SMTP servers to check port availability, auth methods,
 * STARTTLS support, and MITM detection.
 *
 * Inspired by Post SMTP's Postman-PortTest but cleaner and standalone.
 *
 * @since 1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_SMTP_Port_Test
{
    /** @var int Connection timeout in seconds */
    private $connection_timeout = 10;

    /** @var int Read timeout in seconds */
    private $read_timeout = 10;

    /**
     * Test a single port on a hostname.
     * Opens a socket connection, reads SMTP banner, sends EHLO,
     * detects auth methods, STARTTLS, and checks for MITM.
     *
     * @param string $hostname SMTP server hostname
     * @param int    $port     Port number to test
     * @return array Test results
     */
    public function test_port($hostname, $port)
    {
        $result = array(
            'port'          => $port,
            'open'          => false,
            'protocol'      => null,
            'secure'        => false,
            'starttls'      => false,
            'auth_login'    => false,
            'auth_plain'    => false,
            'auth_crammd5'  => false,
            'auth_xoauth'   => false,
            'auth_none'     => false,
            'banner'        => null,
            'banner_host'   => null,
            'mitm'          => false,
            'mitm_detail'   => null,
            'error'         => null,
        );

        // Port 465 expects SSL/TLS from the start
        $is_implicit_ssl = ($port === 465);
        $connection_string = $is_implicit_ssl
            ? sprintf('ssl://%s:%d', $hostname, $port)
            : sprintf('%s:%d', $hostname, $port);

        // Attempt connection
        $stream = @stream_socket_client(
            $connection_string,
            $errno,
            $errstr,
            $this->connection_timeout
        );

        if (!$stream) {
            $result['error'] = sprintf('Could not connect: %s [%d]', $errstr, $errno);
            return $result;
        }

        $result['open'] = true;
        $result['protocol'] = $is_implicit_ssl ? 'SMTPS' : 'SMTP';

        if ($is_implicit_ssl) {
            $result['secure'] = true;
        }

        @stream_set_timeout($stream, $this->read_timeout);

        // Read SMTP banner (220 response)
        $banner_response = $this->read_smtp_response($stream);
        if (!$banner_response) {
            $result['error'] = 'No SMTP banner received';
            fclose($stream);
            return $result;
        }

        $result['banner'] = $banner_response['full'];

        // Extract hostname from banner (220 mail.example.com ...)
        if (preg_match('/^220[\s\-]([^\s]+)/', $banner_response['full'], $matches)) {
            $result['banner_host'] = $matches[1];

            // MITM detection: compare banner hostname domain with configured hostname domain
            $banner_domain = $this->get_registered_domain($matches[1]);
            $config_domain = $this->get_registered_domain($hostname);

            if ($banner_domain !== $config_domain) {
                // Known exceptions (Gmail uses google.com in banners, etc.)
                $domain_aliases = array(
                    'gmail.com'   => 'google.com',
                    'live.com'    => 'hotmail.com',
                    'outlook.com' => 'hotmail.com',
                );

                $is_known_alias = false;
                if (isset($domain_aliases[$config_domain]) && $domain_aliases[$config_domain] === $banner_domain) {
                    $is_known_alias = true;
                }
                if (isset($domain_aliases[$banner_domain]) && $domain_aliases[$banner_domain] === $config_domain) {
                    $is_known_alias = true;
                }

                if (!$is_known_alias) {
                    $result['mitm'] = true;
                    $result['mitm_detail'] = sprintf(
                        'Expected domain "%s" but server reported "%s"',
                        $config_domain,
                        $banner_domain
                    );
                }
            }
        }

        // Send EHLO
        $server_name = isset($_SERVER['SERVER_NAME']) ? sanitize_text_field($_SERVER['SERVER_NAME']) : 'localhost';
        $this->send_smtp_command($stream, sprintf('EHLO %s', $server_name));
        $ehlo_response = $this->read_smtp_response($stream);

        if ($ehlo_response) {
            // Parse auth methods from 250-AUTH line
            foreach ($ehlo_response['lines'] as $line) {
                if (preg_match('/^250[\s\-]AUTH\s/i', $line)) {
                    if (preg_match('/\bLOGIN\b/i', $line)) {
                        $result['auth_login'] = true;
                    }
                    if (preg_match('/\bPLAIN\b/i', $line)) {
                        $result['auth_plain'] = true;
                    }
                    if (preg_match('/\bCRAM-MD5\b/i', $line)) {
                        $result['auth_crammd5'] = true;
                    }
                    if (preg_match('/\bXOAUTH2?\b/i', $line)) {
                        $result['auth_xoauth'] = true;
                    }
                    if (preg_match('/\bANONYMOUS\b/i', $line)) {
                        $result['auth_none'] = true;
                    }
                }

                // Check for STARTTLS capability
                if (preg_match('/^250[\s\-]STARTTLS/i', $line)) {
                    $result['starttls'] = true;
                }
            }

            // If no specific auth methods found, it likely allows unauthenticated
            if (!$result['auth_login'] && !$result['auth_plain'] && !$result['auth_crammd5'] && !$result['auth_xoauth']) {
                $result['auth_none'] = true;
            }

            // If STARTTLS is available and this isn't already an SSL port, try it
            if ($result['starttls'] && !$is_implicit_ssl) {
                $this->send_smtp_command($stream, 'STARTTLS');
                $starttls_resp = $this->read_smtp_response($stream);

                if ($starttls_resp && strpos($starttls_resp['full'], '220') === 0) {
                    $tls_success = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                    if ($tls_success) {
                        $result['secure'] = true;

                        // Re-send EHLO after TLS to get updated capabilities
                        $this->send_smtp_command($stream, sprintf('EHLO %s', $server_name));
                        $tls_ehlo = $this->read_smtp_response($stream);

                        // Re-parse auth methods after TLS
                        if ($tls_ehlo) {
                            foreach ($tls_ehlo['lines'] as $line) {
                                if (preg_match('/^250[\s\-]AUTH\s/i', $line)) {
                                    if (preg_match('/\bLOGIN\b/i', $line)) {
                                        $result['auth_login'] = true;
                                    }
                                    if (preg_match('/\bPLAIN\b/i', $line)) {
                                        $result['auth_plain'] = true;
                                    }
                                    if (preg_match('/\bCRAM-MD5\b/i', $line)) {
                                        $result['auth_crammd5'] = true;
                                    }
                                    if (preg_match('/\bXOAUTH2?\b/i', $line)) {
                                        $result['auth_xoauth'] = true;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // Send QUIT
        $this->send_smtp_command($stream, 'QUIT');
        fclose($stream);

        return $result;
    }

    /**
     * Send an SMTP command to the stream
     */
    private function send_smtp_command($stream, $command)
    {
        fputs($stream, $command . "\r\n");
    }

    /**
     * Read SMTP response lines from stream.
     * Returns array with 'full' (all lines concatenated) and 'lines' (individual lines).
     */
    private function read_smtp_response($stream)
    {
        $lines = array();
        $full = '';

        while (($line = fgets($stream)) !== false) {
            $lines[] = trim($line);
            $full .= $line;

            // End of response: line starts with "NNN " (3 digits + space)
            if (preg_match('/^\d{3}\s/', $line)) {
                break;
            }
        }

        if (empty($lines)) {
            return false;
        }

        return array(
            'full'  => trim($full),
            'lines' => $lines,
        );
    }

    /**
     * Extract the registered domain from a hostname.
     * e.g. "mx1.smtp.google.com" → "google.com"
     *
     * @param string $hostname
     * @return string
     */
    private function get_registered_domain($hostname)
    {
        $hostname = strtolower(trim($hostname));

        // Remove trailing dot
        $hostname = rtrim($hostname, '.');

        $parts = explode('.', $hostname);

        if (count($parts) <= 2) {
            return $hostname;
        }

        // Handle common TLDs with second-level domains (co.uk, com.au, etc.)
        $special_tlds = array('co.uk', 'com.au', 'co.nz', 'co.za', 'com.br', 'co.jp', 'co.kr', 'co.in');
        $last_two = implode('.', array_slice($parts, -2));

        if (in_array($last_two, $special_tlds)) {
            return implode('.', array_slice($parts, -3));
        }

        return implode('.', array_slice($parts, -2));
    }
}
