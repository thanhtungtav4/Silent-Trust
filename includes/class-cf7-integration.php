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
        $this->risk_engine = new Risk_Engine();
        $this->decision_engine = new Decision_Engine();
        $this->geoip = new GeoIP();
        $this->db = new Database();
        $this->analytics = new Analytics_Helper();

        // Inject honeypot field into forms
        add_filter('wpcf7_form_elements', [$this, 'inject_honeypot']);

        // Hook into CF7 before mail send
        add_action('wpcf7_before_send_mail', [$this, 'intercept_submission'], 1);

        // Hook to track SMTP failures
        add_action('wpcf7_mail_failed', [$this, 'track_smtp_failure']);

        // Register WP-Cron handlers
        add_action('silent_trust_delayed_mail_send', [$this, 'send_delayed_mail'], 10, 2);
        add_action('silent_trust_check_stuck_mail', [$this, 'check_stuck_mail']);
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
            error_log('[Silent Trust] HONEYPOT TRIGGERED - Bot detected');

            // Log as blocked submission
            $this->db->log_submission([
                'form_id' => $contact_form->id(),
                'ip_address' => $this->get_client_ip(),
                'action' => 'DROP',
                'risk_score' => 100,
                'reason_code' => 'HONEYPOT_FILLED',
                'submission_data' => $posted_data
            ]);

            return true; // Maintain illusion of success
        }

        // Extract fingerprint payload from hidden field
        $payload = isset($posted_data['st_payload']) ? json_decode($posted_data['st_payload'], true) : [];

        if (empty($payload)) {
            // No fingerprint data - let it through but log as suspicious
            error_log('Silent Trust: No fingerprint data in submission');
            return true;
        }

        // Get IP and GeoIP data
        $ip_address = $this->get_client_ip();
        $location = $this->geoip->get_location($ip_address);

        // Extract device cookie if present
        $device_cookie = $_COOKIE['st_device_id'] ?? null;

        // Calculate risk score
        $risk_result = $this->risk_engine->calculate_risk($payload, $ip_address, $device_cookie);

        // Extract analytics data (URLs, UTM, session, etc.)
        $analytics_data = $this->analytics->extract_analytics($payload, $ip_address);

        // Prepare submission data for decision engine
        $submission_data = [
            'form_id' => $contact_form->id(),
            'fingerprint_hash' => $payload['fingerprint_hash'] ?? hash('sha256', json_encode($payload)),
            'device_cookie' => $device_cookie,
            'device_type' => $payload['device_type'] ?? 'unknown',
            'fingerprint_data' => $payload,
            'behavior_data' => $payload,
            'ip_address' => $ip_address,
            'country_code' => $location['country_code'] ?? null,
            'city' => $location['city'] ?? null,
            'asn' => null, // Can add ASN lookup here if needed
            'mail_data' => $this->extract_mail_data($contact_form, $posted_data)
        ];

        // Merge analytics data into submission
        $submission_data = array_merge($submission_data, $analytics_data);

        // Execute decision
        $decision = $this->decision_engine->execute(
            $risk_result['score'],
            $risk_result['breakdown'],
            $submission_data
        );

        // Store decision context for later use
        $this->store_decision_context($decision, $submission_data);

        // CRITICAL: Block email BEFORE CF7 processes mail
        if (!$decision['proceed']) {
            // Block mail with high priority
            add_filter('wpcf7_skip_mail', '__return_true', 1);

            // Also set a flag for CF7's mail property
            add_filter('wpcf7_mail_sent', '__return_false', 1);

            // Debug log
            error_log(sprintf(
                '[Silent Trust] BLOCKED submission - Risk: %d, Action: %s, Reason: %s',
                $risk_result['score'],
                $decision['action'],
                $decision['reason'] ?? 'high_risk'
            ));
        } else {
            error_log(sprintf(
                '[Silent Trust] ALLOWED submission - Risk: %d, Action: %s',
                $risk_result['score'],
                $decision['action']
            ));
        }

        // Always return true to maintain illusion of success
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
            'form_id' => $contact_form->id()
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
     * Send delayed mail via WP-Cron
     */
    public function send_delayed_mail($mail_data, $submission_data)
    {
        // Remove from fallback transient
        if (isset($mail_data['fallback_id'])) {
            delete_transient('st_mail_fallback_' . $mail_data['fallback_id']);
        }

        // Send the email
        $sent = wp_mail(
            $mail_data['to'],
            $mail_data['subject'],
            $mail_data['body'],
            $mail_data['headers']
        );

        // Update log with sent status
        $this->update_submission_log($submission_data, $sent, 'cron');
    }

    /**
     * Check for stuck mail (fallback mechanism)
     */
    public function check_stuck_mail()
    {
        global $wpdb;

        // Get all st_mail_fallback_* transients
        $transients = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_st_mail_fallback_%'"
        );

        foreach ($transients as $transient) {
            $value = maybe_unserialize($transient->option_value);

            if (!isset($value['mail_data']) || !isset($value['submission_data'])) {
                continue;
            }

            $scheduled_at = $value['mail_data']['scheduled_at'] ?? 0;

            // If older than 10 seconds, send immediately
            if (time() - $scheduled_at > 10) {
                $this->send_delayed_mail($value['mail_data'], $value['submission_data']);

                // Log warning about WP-Cron delay
                error_log('Silent Trust: WP-Cron delayed, sent via fallback');
            }
        }
    }

    /**
     * Track SMTP failures
     */
    public function track_smtp_failure($contact_form)
    {
        // Get the last submission context
        $context = get_transient('st_last_decision_context');

        if ($context && isset($context['submission_data'])) {
            global $wpdb;
            $table = $wpdb->prefix . 'st_submissions';

            // Update the last submission with SMTP error
            $wpdb->update(
                $table,
                ['email_failure_reason' => 'SMTP send failed'],
                ['fingerprint_hash' => $context['submission_data']['fingerprint_hash']],
                ['%s'],
                ['%s']
            );
        }
    }

    /**
     * Store decision context temporarily
     */
    private function store_decision_context($decision, $submission_data)
    {
        set_transient('st_last_decision_context', [
            'decision' => $decision,
            'submission_data' => $submission_data
        ], 60);
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
     * Get client IP
     */
    private function get_client_ip()
    {
        $ip_keys = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];

        foreach ($ip_keys as $key) {
            if (isset($_SERVER[$key]) && filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
                return $_SERVER[$key];
            }
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
            error_log('Silent Trust: Honeypot triggered - Field: ' . $field_name . ' Value: ' . $posted_data[$field_name]);
            return false; // Failed validation (bot detected)
        }

        return true; // Passed validation
    }
}
