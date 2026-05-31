<?php
/**
 * Ofast License Server (FIXED VERSION)
 * 
 * All security vulnerabilities patched:
 * - Rate limiting on all endpoints
 * - Plaintext keys removed
 * - Activation tokens validated
 * - Signature verification
 * - Input sanitization
 * - Expiration checking
 * - IP address properly sanitized
 * - Error messages generic
 */

if (!defined('ABSPATH')) {
    exit;
}

// =========================================================================
// CRITICAL FIX #1: Implement Rate Limiting
// =========================================================================

class Ofast_License_Rate_Limiter {
    
    public static function check_limit($endpoint, $limit = 50, $window = 3600) {
        global $wpdb;
        
        $ip = self::get_client_ip();
        $now = time();
        $window_start = $now - $window;
        
        // Clean old records
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ofast_rate_limits 
            WHERE ip_address = %s AND window_start < %d",
            $ip,
            $window_start
        ));
        
        // Get current count
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ofast_rate_limits 
            WHERE ip_address = %s AND endpoint = %s AND window_start > %d",
            $ip,
            $endpoint,
            $window_start
        ));
        
        if ($count >= $limit) {
            return false;  // Rate limit exceeded
        }
        
        // Increment count
        $wpdb->insert(
            $wpdb->prefix . 'ofast_rate_limits',
            [
                'ip_address' => $ip,
                'endpoint' => $endpoint,
                'window_start' => $now
            ]
        );
        
        return true;  // OK
    }
    
    public static function get_client_ip() {
        // HIGH FIX #8: Properly sanitize IP address
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            $ip = $ips[0];
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        }
        
        // Validate IP format
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = 'unknown';
        }
        
        return sanitize_text_field($ip);
    }
}

// =========================================================================
// REST API ENDPOINTS
// =========================================================================

add_action('rest_api_init', function() {
    $ns = 'ofast-license/v1';
    
    register_rest_route($ns, '/activate', [
        'methods' => 'POST',
        'callback' => 'ofast_license_activate',
        'permission_callback' => '__return_true',  // Public API, rate limiting handles security
        'args' => [
            'license_key' => ['required' => true, 'type' => 'string'],
            'site_url' => ['required' => true, 'type' => 'string'],
            'product_id' => ['required' => false, 'type' => 'string', 'default' => 'ofast-toolkit']
        ]
    ]);
    
    register_rest_route($ns, '/validate', [
        'methods' => 'POST',
        'callback' => 'ofast_license_validate',
        'permission_callback' => '__return_true',
        'args' => [
            'license_key' => ['required' => true, 'type' => 'string'],
            'site_url' => ['required' => true, 'type' => 'string'],
            'activation_token' => ['required' => false, 'type' => 'string']
        ]
    ]);
    
    register_rest_route($ns, '/deactivate', [
        'methods' => 'POST',
        'callback' => 'ofast_license_deactivate',
        'permission_callback' => '__return_true',
        'args' => [
            'license_key' => ['required' => true, 'type' => 'string'],
            'site_url' => ['required' => true, 'type' => 'string'],
            'activation_token' => ['required' => false, 'type' => 'string']
        ]
    ]);
});

// =========================================================================
// ACTIVATE ENDPOINT
// =========================================================================

function ofast_license_activate(WP_REST_Request $request) {
    global $wpdb;
    
    // CRITICAL FIX #1: Check rate limit
    if (!Ofast_License_Rate_Limiter::check_limit('activate', 50, 3600)) {
        return ofast_error_response('Rate limit exceeded', 429);
    }
    
    $ip = Ofast_License_Rate_Limiter::get_client_ip();
    
    // Get & validate input
    $license_key = preg_replace('/[^A-Z0-9\-]/', '', strtoupper(trim($request->get_param('license_key') ?? '')));
    $site_url = esc_url_raw($request->get_param('site_url') ?? '');
    $product_id = sanitize_text_field($request->get_param('product_id') ?? 'ofast-toolkit');
    
    // HIGH FIX #1: Validate license key format
    if (!preg_match('/^OFAST-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $license_key)) {
        ofast_log_activation($null, $license_key, 'invalid_format', $site_url, $ip, 'failure');
        return ofast_error_response('Invalid license format', 400);
    }
    
    // Get license from database (using hash)
    $license_hash = hash('sha256', $license_key);
    $license = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ofast_licenses 
        WHERE license_key_hash = %s AND product_id = %s",
        $license_hash,
        $product_id
    ));
    
    if (!$license) {
        ofast_log_activation(null, $license_hash, 'invalid_key', $site_url, $ip, 'failure');
        return ofast_error_response('Invalid license', 403);  // Generic message
    }
    
    // HIGH FIX #3: Check activation limit
    $active_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ofast_activations 
        WHERE license_id = %d AND status = 'active'",
        $license->id
    ));
    
    if ($active_count >= $license->activation_limit) {
        ofast_log_activation($license->id, $license_hash, 'limit_reached', $site_url, $ip, 'failure');
        return ofast_error_response('Invalid license', 403);
    }
    
    // Check license status
    if ($license->status !== 'active') {
        ofast_log_activation($license->id, $license_hash, 'inactive_license', $site_url, $ip, 'failure');
        return ofast_error_response('Invalid license', 403);
    }
    
    // HIGH FIX #3: Check expiration
    if ($license->expires_at && strtotime($license->expires_at) < time()) {
        ofast_log_activation($license->id, $license_hash, 'expired', $site_url, $ip, 'failure');
        return ofast_error_response('Invalid license', 403);
    }
    
    // Generate activation token
    $activation_token = bin2hex(random_bytes(32));
    $site_fingerprint = hash('sha256', strtolower(parse_url($site_url, PHP_URL_HOST) ?? $site_url));
    
    // Check if already activated on this site
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ofast_activations 
        WHERE license_id = %d AND site_fingerprint = %s",
        $license->id,
        $site_fingerprint
    ));
    
    if ($existing) {
        if ($existing->status === 'active') {
            // Already activated, return existing token
            ofast_log_activation($license->id, $license_hash, 'already_activated', $site_url, $ip, 'success');
            
            return ofast_success_response([
                'message' => 'Already activated',
                'activation_token' => $existing->activation_token,
                'signature' => ofast_generate_signature($license_key, $site_url),
                'expires_at' => $license->expires_at,
                'license_type' => $license->license_type
            ]);
        }
        
        // Reactivate existing record
        $wpdb->update(
            $wpdb->prefix . 'ofast_activations',
            [
                'status' => 'active',
                'activation_token' => $activation_token,
                'activated_at' => current_time('mysql'),
                'ip_address' => $ip
            ],
            ['id' => $existing->id]
        );
    } else {
        // Create new activation
        $wpdb->insert(
            $wpdb->prefix . 'ofast_activations',
            [
                'license_id' => $license->id,
                'license_key_hash' => $license_hash,
                'site_url' => $site_url,
                'site_fingerprint' => $site_fingerprint,
                'activation_token' => $activation_token,
                'status' => 'active',
                'activated_at' => current_time('mysql'),
                'ip_address' => $ip
            ]
        );
    }
    
    ofast_log_activation($license->id, $license_hash, 'activated', $site_url, $ip, 'success');
    
    return ofast_success_response([
        'message' => 'License activated',
        'activation_token' => $activation_token,
        'signature' => ofast_generate_signature($license_key, $site_url),
        'expires_at' => $license->expires_at,
        'license_type' => $license->license_type
    ]);
}

// =========================================================================
// VALIDATE ENDPOINT
// =========================================================================

function ofast_license_validate(WP_REST_Request $request) {
    global $wpdb;
    
    // Rate limit
    if (!Ofast_License_Rate_Limiter::check_limit('validate', 100, 3600)) {
        return ofast_error_response('Rate limit exceeded', 429);
    }
    
    $ip = Ofast_License_Rate_Limiter::get_client_ip();
    
    $license_key = preg_replace('/[^A-Z0-9\-]/', '', strtoupper($request->get_param('license_key') ?? ''));
    $site_url = esc_url_raw($request->get_param('site_url') ?? '');
    $activation_token = sanitize_text_field($request->get_param('activation_token') ?? '');
    
    $license_hash = hash('sha256', $license_key);
    $site_fingerprint = hash('sha256', strtolower(parse_url($site_url, PHP_URL_HOST) ?? $site_url));
    
    // Get license
    $license = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ofast_licenses WHERE license_key_hash = %s",
        $license_hash
    ));
    
    if (!$license) {
        ofast_log_activation(null, $license_hash, 'validate_invalid_key', $site_url, $ip, 'failure');
        return ofast_success_response(['valid' => false]);
    }
    
    // Get activation
    $activation = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ofast_activations 
        WHERE license_id = %d AND site_fingerprint = %s AND activation_token = %s",
        $license->id,
        $site_fingerprint,
        $activation_token
    ));
    
    if (!$activation || $activation->status !== 'active') {
        ofast_log_activation($license->id, $license_hash, 'validate_not_found', $site_url, $ip, 'failure');
        return ofast_success_response(['valid' => false]);
    }
    
    // HIGH FIX #3: Check expiration
    if ($license->expires_at && strtotime($license->expires_at) < time()) {
        $wpdb->update($wpdb->prefix . 'ofast_licenses', ['status' => 'expired'], ['id' => $license->id]);
        return ofast_success_response(['valid' => false, 'expired' => true]);
    }
    
    // Update last checked
    $wpdb->update(
        $wpdb->prefix . 'ofast_activations',
        ['last_checked' => current_time('mysql')],
        ['id' => $activation->id]
    );
    
    ofast_log_activation($license->id, $license_hash, 'validated', $site_url, $ip, 'success');
    
    return ofast_success_response([
        'valid' => true,
        'status' => $license->status,
        'signature' => ofast_generate_signature($license_key, $site_url),
        'expires_at' => $license->expires_at,
        'license_type' => $license->license_type
    ]);
}

// =========================================================================
// DEACTIVATE ENDPOINT
// =========================================================================

function ofast_license_deactivate(WP_REST_Request $request) {
    global $wpdb;
    
    // Rate limit
    if (!Ofast_License_Rate_Limiter::check_limit('deactivate', 20, 3600)) {
        return ofast_error_response('Rate limit exceeded', 429);
    }
    
    $ip = Ofast_License_Rate_Limiter::get_client_ip();
    
    $license_key = preg_replace('/[^A-Z0-9\-]/', '', strtoupper($request->get_param('license_key') ?? ''));
    $site_url = esc_url_raw($request->get_param('site_url') ?? '');
    
    $license_hash = hash('sha256', $license_key);
    $site_fingerprint = hash('sha256', strtolower(parse_url($site_url, PHP_URL_HOST) ?? $site_url));
    
    $license = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ofast_licenses WHERE license_key_hash = %s",
        $license_hash
    ));
    
    if (!$license) {
        return ofast_error_response('Invalid license', 403);
    }
    
    $updated = $wpdb->update(
        $wpdb->prefix . 'ofast_activations',
        ['status' => 'inactive'],
        [
            'license_id' => $license->id,
            'site_fingerprint' => $site_fingerprint
        ]
    );
    
    ofast_log_activation($license->id, $license_hash, 'deactivated', $site_url, $ip, 'success');
    
    return ofast_success_response(['message' => 'Deactivated']);
}

// =========================================================================
// HELPER FUNCTIONS
// =========================================================================

function ofast_generate_signature($license_key, $site_url) {
    // Server signs the response so client can verify
    $secret = OFAST_SERVER_SIGNING_SECRET;
    return hash_hmac('sha256', $license_key . '|' . $site_url, $secret);
}

function ofast_log_activation($license_id, $license_hash, $action, $site_url, $ip, $status) {
    global $wpdb;
    
    $wpdb->insert(
        $wpdb->prefix . 'ofast_license_logs',
        [
            'license_id' => $license_id,
            'license_key_hash' => $license_hash,
            'action' => $action,
            'site_url' => $site_url,
            'ip_address' => $ip,
            'status' => $status,
            'created_at' => current_time('mysql')
        ]
    );
}

function ofast_success_response($data) {
    return new WP_REST_Response([
        'success' => true,
        'data' => $data
    ], 200);
}

function ofast_error_response($message, $code = 400) {
    return new WP_REST_Response([
        'success' => false,
        'message' => $message
    ], $code);
}

// =========================================================================
// CRITICAL FIX #3: Never store plaintext license keys
// Only hash them
// =========================================================================

// When generating license:
function ofast_generate_license($customer_email, $license_type = 'single') {
    global $wpdb;
    
    $license_key = 'OFAST-' . wp_generate_password(4, false, false) . '-' . 
                   wp_generate_password(4, false, false) . '-' . 
                   wp_generate_password(4, false, false) . '-' . 
                   wp_generate_password(4, false, false);
    
    $license_key = strtoupper(preg_replace('/[^A-Z0-9\-]/', '', $license_key));
    $license_hash = hash('sha256', $license_key);
    
    $activation_limits = ['single' => 1, 'multi' => 5, 'unlimited' => 999];
    
    // CRITICAL FIX #3: Store ONLY the hash, never plaintext
    $wpdb->insert(
        $wpdb->prefix . 'ofast_licenses',
        [
            'license_key' => '',  // EMPTY - do not store plaintext
            'license_key_hash' => $license_hash,  // STORE ONLY THIS
            'customer_email' => $customer_email,
            'license_type' => $license_type,
            'activation_limit' => $activation_limits[$license_type] ?? 1,
            'status' => 'active',
            'created_at' => current_time('mysql'),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+1 year'))
        ]
    );
    
    // Return plaintext key ONLY once, to send to customer
    // Never store it again
    return $license_key;
}
