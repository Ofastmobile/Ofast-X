<?php

/**
 * Ofast X - SMTP DNS Checker
 * Verifies SPF, DKIM, and DMARC records for email deliverability
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_SMTP_DNS
{
    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check all DNS records for a domain
     * 
     * @param string $domain Domain to check (defaults to site domain)
     * @return array Results for SPF, DKIM, DMARC
     */
    public function check_all($domain = null)
    {
        if (!$domain) {
            $domain = $this->get_site_domain();
        }

        return array(
            'domain' => $domain,
            'spf' => $this->check_spf($domain),
            'dkim' => $this->check_dkim($domain),
            'dmarc' => $this->check_dmarc($domain),
            'mx' => $this->check_mx($domain),
            'checked_at' => current_time('mysql')
        );
    }

    /**
     * Get site domain from URL
     */
    private function get_site_domain()
    {
        $url = parse_url(home_url(), PHP_URL_HOST);
        // Remove www. prefix if present
        return preg_replace('/^www\./', '', $url);
    }

    /**
     * Check SPF record
     */
    public function check_spf($domain)
    {
        $result = array(
            'status' => 'missing',
            'record' => null,
            'issues' => array(),
            'recommendations' => array()
        );

        $records = @dns_get_record($domain, DNS_TXT);
        if (!$records) {
            $result['issues'][] = 'Could not retrieve DNS TXT records';
            $result['recommendations'][] = 'Ensure DNS is accessible and TXT records exist';
            return $result;
        }

        // Find SPF record
        foreach ($records as $record) {
            if (isset($record['txt']) && strpos($record['txt'], 'v=spf1') === 0) {
                $result['record'] = $record['txt'];
                $result['status'] = 'found';
                break;
            }
        }

        if (!$result['record']) {
            $result['issues'][] = 'No SPF record found';
            $result['recommendations'][] = 'Add a TXT record with: v=spf1 include:_spf.google.com ~all';
            return $result;
        }

        // Validate SPF record
        $spf = $result['record'];

        // Check for common issues
        if (substr_count($spf, 'include:') > 10) {
            $result['issues'][] = 'Too many include statements (max 10 DNS lookups allowed)';
            $result['status'] = 'warning';
        }

        if (strpos($spf, '-all') !== false) {
            $result['status'] = 'valid';
        } elseif (strpos($spf, '~all') !== false) {
            $result['status'] = 'valid';
            $result['recommendations'][] = 'Consider using -all (hard fail) instead of ~all (soft fail) for stricter enforcement';
        } elseif (strpos($spf, '?all') !== false) {
            $result['status'] = 'warning';
            $result['issues'][] = 'SPF policy is neutral (?all) - provides no protection';
            $result['recommendations'][] = 'Change ?all to ~all or -all';
        } elseif (strpos($spf, '+all') !== false) {
            $result['status'] = 'error';
            $result['issues'][] = 'SPF allows all senders (+all) - defeats the purpose of SPF';
            $result['recommendations'][] = 'Remove +all and use -all or ~all';
        }

        // Check for common mail provider includes
        $providers = array(
            '_spf.google.com' => 'Google Workspace',
            'spf.protection.outlook.com' => 'Microsoft 365',
            'sendgrid.net' => 'SendGrid',
            'mailgun.org' => 'Mailgun',
            'amazonses.com' => 'Amazon SES'
        );

        $detected = array();
        foreach ($providers as $include => $name) {
            if (strpos($spf, $include) !== false) {
                $detected[] = $name;
            }
        }
        if (!empty($detected)) {
            $result['providers'] = $detected;
        }

        return $result;
    }

    /**
     * Check DKIM record
     */
    public function check_dkim($domain, $selectors = array('default', 'google', 'selector1', 'selector2', 's1', 's2', 'k1', 'mail', 'dkim'))
    {
        $result = array(
            'status' => 'missing',
            'records' => array(),
            'issues' => array(),
            'recommendations' => array()
        );

        $found_selectors = array();

        foreach ($selectors as $selector) {
            $dkim_domain = $selector . '._domainkey.' . $domain;
            $records = @dns_get_record($dkim_domain, DNS_TXT);

            if ($records) {
                foreach ($records as $record) {
                    if (isset($record['txt']) && strpos($record['txt'], 'v=DKIM1') !== false) {
                        $found_selectors[$selector] = $record['txt'];
                        $result['status'] = 'found';
                    }
                }
            }
        }

        if (empty($found_selectors)) {
            $result['issues'][] = 'No DKIM records found for common selectors';
            $result['recommendations'][] = 'Configure DKIM with your email provider';
            $result['recommendations'][] = 'Common selectors: google, default, selector1, selector2';
            return $result;
        }

        $result['records'] = $found_selectors;
        $result['status'] = 'valid';

        // Validate each DKIM record
        foreach ($found_selectors as $selector => $dkim) {
            if (strpos($dkim, 'p=') === false) {
                $result['issues'][] = "DKIM record for '$selector' missing public key (p=)";
                $result['status'] = 'warning';
            }
        }

        return $result;
    }

    /**
     * Check DMARC record
     */
    public function check_dmarc($domain)
    {
        $result = array(
            'status' => 'missing',
            'record' => null,
            'policy' => null,
            'issues' => array(),
            'recommendations' => array()
        );

        $dmarc_domain = '_dmarc.' . $domain;
        $records = @dns_get_record($dmarc_domain, DNS_TXT);

        if (!$records) {
            $result['issues'][] = 'No DMARC record found';
            $result['recommendations'][] = 'Add a TXT record at _dmarc.' . $domain;
            $result['recommendations'][] = 'Example: v=DMARC1; p=quarantine; rua=mailto:dmarc@' . $domain;
            return $result;
        }

        foreach ($records as $record) {
            if (isset($record['txt']) && strpos($record['txt'], 'v=DMARC1') === 0) {
                $result['record'] = $record['txt'];
                $result['status'] = 'found';
                break;
            }
        }

        if (!$result['record']) {
            $result['issues'][] = 'No valid DMARC record found';
            return $result;
        }

        $dmarc = $result['record'];

        // Extract policy
        if (preg_match('/p=(none|quarantine|reject)/i', $dmarc, $matches)) {
            $result['policy'] = strtolower($matches[1]);

            switch ($result['policy']) {
                case 'none':
                    $result['status'] = 'warning';
                    $result['issues'][] = 'DMARC policy is "none" - emails failing checks are not quarantined or rejected';
                    $result['recommendations'][] = 'Consider upgrading to p=quarantine or p=reject after monitoring';
                    break;
                case 'quarantine':
                    $result['status'] = 'valid';
                    break;
                case 'reject':
                    $result['status'] = 'valid';
                    break;
            }
        } else {
            $result['issues'][] = 'DMARC record missing policy (p=)';
            $result['status'] = 'error';
        }

        // Check for reporting address
        if (strpos($dmarc, 'rua=') === false) {
            $result['recommendations'][] = 'Add rua= to receive aggregate reports';
        }

        // Check for subdomain policy
        if (strpos($dmarc, 'sp=') !== false) {
            preg_match('/sp=(none|quarantine|reject)/i', $dmarc, $sp_matches);
            if (isset($sp_matches[1])) {
                $result['subdomain_policy'] = strtolower($sp_matches[1]);
            }
        }

        // Check percentage
        if (preg_match('/pct=(\d+)/', $dmarc, $pct_matches)) {
            $pct = intval($pct_matches[1]);
            if ($pct < 100) {
                $result['percentage'] = $pct;
                $result['recommendations'][] = "DMARC applied to only {$pct}% of messages. Increase to 100% when ready.";
            }
        }

        return $result;
    }

    /**
     * Check MX records
     */
    public function check_mx($domain)
    {
        $result = array(
            'status' => 'missing',
            'records' => array(),
            'issues' => array()
        );

        $records = @dns_get_record($domain, DNS_MX);

        if (!$records || empty($records)) {
            $result['issues'][] = 'No MX records found - email cannot be received';
            return $result;
        }

        $result['status'] = 'valid';

        foreach ($records as $record) {
            if (isset($record['target'])) {
                $result['records'][] = array(
                    'priority' => $record['pri'] ?? 0,
                    'host' => $record['target']
                );
            }
        }

        // Sort by priority
        usort($result['records'], function ($a, $b) {
            return $a['priority'] - $b['priority'];
        });

        // Detect mail provider
        $primary_mx = strtolower($result['records'][0]['host'] ?? '');
        if (strpos($primary_mx, 'google') !== false || strpos($primary_mx, 'googlemail') !== false) {
            $result['provider'] = 'Google Workspace';
        } elseif (strpos($primary_mx, 'outlook') !== false || strpos($primary_mx, 'microsoft') !== false) {
            $result['provider'] = 'Microsoft 365';
        } elseif (strpos($primary_mx, 'zoho') !== false) {
            $result['provider'] = 'Zoho Mail';
        } elseif (strpos($primary_mx, 'mxlogin') !== false || strpos($primary_mx, 'emailsrvr') !== false) {
            $result['provider'] = 'Rackspace';
        }

        return $result;
    }

    /**
     * Get overall health score (0-100)
     */
    public function get_health_score($results)
    {
        $score = 0;
        $max_score = 100;

        // SPF (30 points)
        switch ($results['spf']['status']) {
            case 'valid':
                $score += 30;
                break;
            case 'found':
                $score += 20;
                break;
            case 'warning':
                $score += 15;
                break;
        }

        // DKIM (30 points)
        switch ($results['dkim']['status']) {
            case 'valid':
                $score += 30;
                break;
            case 'found':
                $score += 20;
                break;
            case 'warning':
                $score += 15;
                break;
        }

        // DMARC (30 points)
        switch ($results['dmarc']['status']) {
            case 'valid':
                $score += 30;
                break;
            case 'found':
                $score += 15;
                break;
            case 'warning':
                $score += 10;
                break;
        }

        // MX (10 points)
        if ($results['mx']['status'] === 'valid') {
            $score += 10;
        }

        return min(100, $score);
    }

    /**
     * Render DNS checker UI
     */
    public function render_checker_ui()
    {
        $results = null;
        $domain = $this->get_site_domain();

        if (isset($_POST['check_dns']) && wp_verify_nonce($_POST['dns_nonce'], 'ofast_dns_check')) {
            $domain = sanitize_text_field($_POST['domain'] ?? $domain);
            $results = $this->check_all($domain);
        }
?>
        <div class="ofast-dns-checker" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 20px;">
            <h3 style="margin-top: 0;">Email DNS Checker</h3>
            <p style="color: #666;">Verify SPF, DKIM, and DMARC records for email deliverability.</p>

            <form method="post" style="margin-bottom: 20px; display: flex; gap: 10px; align-items: center;">
                <?php wp_nonce_field('ofast_dns_check', 'dns_nonce'); ?>
                <input type="text" name="domain" value="<?php echo esc_attr($domain); ?>" class="regular-text" placeholder="example.com"
                    style="border-radius: 8px; border: 1px solid #d7deea; padding: 8px 12px;">
                <button type="submit" name="check_dns" class="button button-primary"
                    style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%) !important; border-color: #6366f1 !important; text-shadow: none !important; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3) !important; padding: 8px 20px !important; height: auto !important; border-radius: 8px !important; font-weight: 600 !important;">Check DNS Records</button>
            </form>

            <?php if ($results): ?>
                <?php $score = $this->get_health_score($results); ?>
                <div style="margin-bottom: 20px; padding: 15px; background: <?php echo $score >= 70 ? '#d4edda' : ($score >= 40 ? '#fff3cd' : '#f8d7da'); ?>; border-radius: 8px;">
                    <strong>Email Health Score: <?php echo $score; ?>/100</strong>
                    <?php if ($score >= 70): ?>
                        <span style="color: #155724;"> - Good! Your email authentication is well configured.</span>
                    <?php elseif ($score >= 40): ?>
                        <span style="color: #856404;"> - Needs improvement. See recommendations below.</span>
                    <?php else: ?>
                        <span style="color: #721c24;"> - Critical! Emails may be marked as spam.</span>
                    <?php endif; ?>
                </div>

                <?php $this->render_record_card('SPF Record', $results['spf']); ?>
                <?php $this->render_record_card('DKIM Records', $results['dkim']); ?>
                <?php $this->render_record_card('DMARC Record', $results['dmarc']); ?>
                <?php $this->render_record_card('MX Records', $results['mx']); ?>
            <?php endif; ?>
        </div>
    <?php
    }

    /**
     * Render individual record card
     */
    private function render_record_card($title, $data)
    {
        $status_colors = array(
            'valid' => '#28a745',
            'found' => '#17a2b8',
            'warning' => '#ffc107',
            'error' => '#dc3545',
            'missing' => '#6c757d'
        );
        $status_icons = array(
            'valid' => '✓',
            'found' => '●',
            'warning' => '⚠',
            'error' => '✗',
            'missing' => '○'
        );
        $color = $status_colors[$data['status']] ?? '#6c757d';
        $icon = $status_icons[$data['status']] ?? '?';
    ?>
        <div style="border: 1px solid #ddd; border-left: 4px solid <?php echo $color; ?>; padding: 15px; margin-bottom: 15px; border-radius: 8px;">
            <h4 style="margin: 0 0 10px 0;"><?php echo esc_html($title); ?> <span style="color: <?php echo $color; ?>;"><?php echo $icon; ?> <?php echo ucfirst($data['status']); ?></span></h4>

            <?php if (!empty($data['record'])): ?>
                <code style="display: block; padding: 10px; background: #f5f5f5; margin: 10px 0; word-break: break-all; font-size: 12px; border-radius: 6px;"><?php echo esc_html($data['record']); ?></code>
            <?php endif; ?>

            <?php if (!empty($data['records'])): ?>
                <?php foreach ($data['records'] as $key => $rec): ?>
                    <?php if (is_array($rec)): ?>
                        <code style="display: block; padding: 5px 10px; background: #f5f5f5; margin: 5px 0; font-size: 12px;">Priority <?php echo $rec['priority']; ?>: <?php echo esc_html($rec['host']); ?></code>
                    <?php else: ?>
                        <code style="display: block; padding: 5px 10px; background: #f5f5f5; margin: 5px 0; font-size: 12px;"><?php echo esc_html($key); ?>: <?php echo esc_html(substr($rec, 0, 80)); ?>...</code>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($data['provider'])): ?>
                <p style="margin: 10px 0 0; color: #666;">Detected: <strong><?php echo esc_html($data['provider']); ?></strong></p>
            <?php endif; ?>

            <?php if (!empty($data['policy'])): ?>
                <p style="margin: 10px 0 0;">Policy: <strong><?php echo esc_html($data['policy']); ?></strong></p>
            <?php endif; ?>

            <?php if (!empty($data['issues'])): ?>
                <div style="margin-top: 10px; padding: 10px; background: #fff3cd; border-radius: 8px;">
                    <strong>Issues:</strong>
                    <ul style="margin: 5px 0 0 20px;">
                        <?php foreach ($data['issues'] as $issue): ?>
                            <li><?php echo esc_html($issue); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($data['recommendations'])): ?>
                <div style="margin-top: 10px; padding: 10px; background: #e7f3ff; border-radius: 8px;">
                    <strong>Recommendations:</strong>
                    <ul style="margin: 5px 0 0 20px;">
                        <?php foreach ($data['recommendations'] as $rec): ?>
                            <li><?php echo esc_html($rec); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
<?php
    }
}
