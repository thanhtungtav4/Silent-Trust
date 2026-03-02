<?php
namespace SilentTrust;

if (!defined('ABSPATH'))
    exit;

class CF7_Integration
{

    private $risk_engine;
    private $decision_engine;
    private $geoip;
    private $db;
    private $analytics;

    public function __construct()
    {
        $this->db = new Database();
        $this->analytics = new Analytics_Helper();

        // Inject honeypot field into forms
        add_filter('wpcf7_form_elements', [$this, 'inject_honeypot']);

        // Hook into CF7 before mail send
        add_action('wpcf7_before_send_mail', [$this, 'intercept_submission'], 1);

        // Hook to track SMTP failures
        add_action('wpcf7_mail_failed', [$this, 'track_smtp_failure']);
    }

    /**
     * Intercept CF7 submission and apply silent risk analysis
     */
    public function intercept_submission($contact_form)
    {
        $submission = \WPCF7_Submission::get_instance();

        if (!$submission) {
            return true;
        }

        $posted_data = $submission->get_posted_data();

        // Validate honeypot first (instant bot detection)
        if (!$this->validate_honeypot($posted_data)) {
            // Bot detected - block mail immediately
            add_filter('wpcf7_skip_mail', '__return_true', 1);
            st_log('HONEYPOT TRIGGERED - Bot detected', 'warn');

            // Log as blocked submission
            $this->db->log_submission([
                'form_id' => $contact_form->id(),
                'fingerprint_hash' => hash('sha256', $this->get_client_ip() . date('Ymd')),
                'ip_address' => $this->get_client_ip(),
                'action' => 'drop',
                'status' => 'processed',
                'risk_score' => 100,
                'mail_data' => $posted_data
            ]);

            return true; // Maintain illusion of success
        }

        // Extract fingerprint payload from hidden field
        $payload = isset($posted_data['st_payload']) ? json_decode($posted_data['st_payload'], true) : [];

        if (empty($payload)) {
            // No fingerprint data - let it through but log as suspicious
            st_log('No fingerprint data in submission', 'warn');
            return true;
        }

        // Block CF7's native synchronous mail sending COMPLETELY
        add_filter('wpcf7_skip_mail', '__return_true', 1);
        add_filter('wpcf7_mail_sent', '__return_false', 1);

        // Get IP and device data
        $ip_address = $this->get_client_ip();
        $device_cookie = $_COOKIE['st_device_id'] ?? null;

        // Check Hard Daily Limits & Direct DB Blocks (Instantly)
        $quick_check = $this->db->check_daily_limit($device_cookie);
        if ($quick_check['exceeded'] || $this->db->is_penalized($payload['fingerprint_hash'] ?? '', 'fingerprint') || $this->db->is_penalized($ip_address, 'ip')) {
            st_log("Instant Block (Limit/Penalty) - IP: {$ip_address}", 'warn');

            $this->db->log_submission([
                'form_id' => $contact_form->id(),
                'fingerprint_hash' => $payload['fingerprint_hash'] ?? hash('sha256', $ip_address . date('Ymd')),
                'device_cookie' => $device_cookie,
                'ip_address' => $ip_address,
                'action' => 'drop',
                'status' => 'processed',
                'risk_score' => 100,
                'mail_data' => $posted_data // log minimal
            ]);

            return true;
        }

        // Extract analytics data (URLs, UTM, session, etc.)
        $analytics_data = $this->analytics->extract_analytics($payload, $ip_address);

        // Prepare submission data for Background Worker (async)
        $submission_data = [
            'form_id' => $contact_form->id(),
            'fingerprint_hash' => $payload['fingerprint_hash'] ?? hash('sha256', json_encode($payload)),
            'device_cookie' => $device_cookie,
            'device_type' => $payload['device_type'] ?? 'unknown',
            'fingerprint_data' => $payload,
            'behavior_data' => $payload,
            'ip_address' => $ip_address,
            'risk_score' => 0, // Calculated in background
            'status' => 'pending',
            'action' => 'pending', // Default pending
            'mail_data' => $this->extract_mail_data($contact_form, $posted_data)
        ];

        // Merge analytics data into submission
        $submission_data = array_merge($submission_data, $analytics_data);

        // 1. Save submission to DB as "pending"
        $inserted = $this->db->log_submission($submission_data);

        if ($inserted) {
            global $wpdb;
            $submission_id = $wpdb->insert_id;

            // 2. Enqueue Background Worker Action Scheduler Job
            as_enqueue_async_action('st_process_submission', ['submission_id' => $submission_id]);

            st_log(sprintf('Queued submission ID %d for Background Worker', $submission_id));
        } else {
            st_log('Failed to log pending submission to DB', 'error');
        }

        // Always return true to maintain illusion of success (CF7 returns success to user instantly)
        return true;
    }

    /**
     * Extract mail data from CF7 form
     */
    private function extract_mail_data($contact_form, $posted_data)
    {
        $mail = $contact_form->prop('mail');

        return [
            'to' => $posted_data['your-email'] ?? $mail['recipient'] ?? get_option('admin_email'),
            'subject' => $mail['subject'] ?? 'Contact Form Submission',
            'body' => $this->build_mail_body($posted_data),
            'headers' => $mail['additional_headers'] ?? '',
            'form_id' => $contact_form->id(),
            'raw_data' => $posted_data
        ];
    }

    /**
     * Build mail body from posted data
     */
    private function build_mail_body($posted_data)
    {
        $body = '';
        foreach ($posted_data as $key => $value) {
            if (strpos($key, 'st_') === 0 || strpos($key, '_') === 0) {
                continue; // Skip internal fields
            }
            $body .= ucfirst(str_replace('-', ' ', $key)) . ": " . $value . "\n";
        }
        return $body;
    }

    /**
     * Track SMTP failures
     */
    public function track_smtp_failure($contact_form)
    {
        $submission = \WPCF7_Submission::get_instance();
        if (!$submission) {
            return;
        }

        $posted_data = $submission->get_posted_data();
        $payload = isset($posted_data['st_payload']) ? json_decode($posted_data['st_payload'], true) : [];
        $fp_hash = $payload['fingerprint_hash'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        $key = 'st_decision_' . md5($fp_hash . $ip);
        $context = get_transient($key);

        if ($context && isset($context['submission_data'])) {
            global $wpdb;
            $table = $wpdb->prefix . 'st_submissions';

            $wpdb->update(
                $table,
                ['email_failure_reason' => 'SMTP send failed'],
                ['fingerprint_hash' => $context['submission_data']['fingerprint_hash']],
                ['%s'],
                ['%s']
            );

            delete_transient($key);
        }
    }

    /**
     * Update submission log after email send
     */
    private function update_submission_log($submission_data, $sent, $sent_via = 'direct')
    {
        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';

        $wpdb->update(
            $table,
            [
                'email_sent' => $sent ? 1 : 0,
                'email_failure_reason' => $sent ? null : 'Mail send failed',
                'sent_via' => $sent_via
            ],
            ['fingerprint_hash' => $submission_data['fingerprint_hash']],
            ['%d', '%s', '%s'],
            ['%s']
        );
    }

    /**
     * Get client IP (hardened against spoofing)
     *
     * Only trusts the admin-configured proxy header.
     * For X-Forwarded-For, extracts the rightmost (proxy-appended) IP,
     * not the client-provided leftmost one.
     */
    private function get_client_ip()
    {
        // Admin can configure which header to trust (default: none / REMOTE_ADDR only)
        $trusted_header = get_option('st_trusted_proxy_header', '');

        if (!empty($trusted_header) && isset($_SERVER[$trusted_header])) {
            $header_value = $_SERVER[$trusted_header];

            // For X-Forwarded-For, take the LAST IP (added by trusted proxy)
            if ($trusted_header === 'HTTP_X_FORWARDED_FOR' && strpos($header_value, ',') !== false) {
                $ips = array_map('trim', explode(',', $header_value));
                $header_value = end($ips);
            }

            if (filter_var($header_value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $header_value;
            }
        }

        // Cloudflare header is safe to trust if Cloudflare is in use
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Inject honeypot field into CF7 forms
     */
    public function inject_honeypot($form)
    {
        // Check if honeypot is enabled
        if (!get_option('st_honeypot_enabled', true)) {
            return $form;
        }

        // Generate dynamic field name (changes daily)
        $field_name = $this->get_honeypot_field_name();

        // Honeypot HTML
        $honeypot_html = sprintf(
            '<div style="position:absolute;left:-9999px;" aria-hidden="true">' .
            '<label for="%s">Leave this field blank</label>' .
            '<input type="text" name="%s" id="%s" value="" tabindex="-1" autocomplete="off" />' .
            '</div>',
            esc_attr($field_name),
            esc_attr($field_name),
            esc_attr($field_name)
        );

        // Inject before closing form tag
        $form = str_replace('</form>', $honeypot_html . '</form>', $form);

        return $form;
    }

    /**
     * Get honeypot field name (rotates daily)
     */
    private function get_honeypot_field_name()
    {
        $date_seed = date('Ymd'); // Changes daily
        $base_names = ['user_verify', 'email_check', 'website_url', 'phone_verify', 'contact_name'];
        $index = (int) substr(md5($date_seed), 0, 2) % count($base_names);

        return 'st_hp_' . $base_names[$index];
    }

    /**
     * Validate honeypot field
     */
    public function validate_honeypot($posted_data)
    {
        $field_name = $this->get_honeypot_field_name();

        // If honeypot is filled, it's a bot
        if (isset($posted_data[$field_name]) && !empty($posted_data[$field_name])) {
            st_log('Honeypot triggered - Field: ' . $field_name . ' Value: ' . $posted_data[$field_name], 'warn');
            return false; // Failed validation (bot detected)
        }

        return true; // Passed validation
    }
}
