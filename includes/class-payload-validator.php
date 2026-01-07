<?php
namespace SilentTrust;

if (!defined('ABSPATH'))
    exit;

class Payload_Validator
{

    private $geoip;

    public function __construct()
    {
        $this->geoip = new GeoIP();
    }

    /**
     * Validate payload and detect obvious bots
     */
    public function validate($payload, $ip_address)
    {
        $score = 0;
        $flags = [];

        // Honeypot detection (CRITICAL - instant block)
        $honeypot_result = $this->check_honeypot();
        if ($honeypot_result > 0) {
            return [
                'score' => 100,
                'flags' => ['honeypot_triggered']
            ];
        }

        // Missing or empty payload
        if (empty($payload) || !is_array($payload)) {
            $score += 50;
            $flags[] = 'missing_payload';
            return ['score' => $score, 'flags' => $flags];
        }

        // Check required fields
        $required_fields = ['device_type', 'fingerprint_hash', 'canvas_hash'];
        foreach ($required_fields as $field) {
            if (empty($payload[$field])) {
                $score += 15;
                $flags[] = "missing_{$field}";
            }
        }

        // GeoIP consistency (soft signal)
        if (isset($payload['timezone']) && !empty($ip_address)) {
            $geoip = new GeoIP();
            $location = $geoip->get_location($ip_address);

            if ($location && isset($location['timezone'])) {
                $reported_tz = $payload['timezone'];
                $actual_tz = $location['timezone'];

                if ($reported_tz !== $actual_tz) {
                    $score += 10;
                    $flags[] = 'timezone_mismatch';
                }
            }
        }

        // HTTP header analysis (User-Agent mismatch)
        if (isset($payload['user_agent']) && !empty($_SERVER['HTTP_USER_AGENT'])) {
            $reported_ua = $payload['user_agent'];
            $actual_ua = $_SERVER['HTTP_USER_AGENT'];

            // Simple substring check (exact match too strict)
            if (strpos($actual_ua, $reported_ua) === false && strpos($reported_ua, $actual_ua) === false) {
                $score += 20;
                $flags[] = 'ua_mismatch';
            }
        }

        return [
            'score' => $score,
            'flags' => $flags
        ];
    }

    /**
     * Check honeypot fields (invisible to users, filled by bots)
     */
    private function check_honeypot()
    {
        $honeypot_fields = ['website_url', 'confirm_email', 'company_name'];

        foreach ($honeypot_fields as $field) {
            if (isset($_POST[$field]) && !empty($_POST[$field])) {
                // Bot detected! Filled invisible field
                $this->log_anomaly('honeypot_triggered', $field . ' filled: ' . $_POST[$field]);
                return 100;
            }
        }

        return 0;
    }

    /**
     * Log anomaly for analysis
     */
    private function log_anomaly($type, $details)
    {
        error_log("[Silent Trust] Anomaly: {$type} - {$details}");
    }
    /**
     * Validate fingerprint format
     */
    private function validate_format($payload)
    {
        $score = 0;

        // Canvas hash should be 64-char hex string
        if (isset($payload['canvas_hash'])) {
            if (!preg_match('/^[a-f0-9]{64}$/i', $payload['canvas_hash'])) {
                $score += 30;
            }
        }

        // Screen dimensions validation
        if (isset($payload['screen_width'])) {
            $width = (int) $payload['screen_width'];
            if ($width < 320 || $width > 3840) {
                $score += 20;
            }
        }

        // Timezone offset validation (-12 to +14 hours)
        if (isset($payload['timezone_offset'])) {
            $offset = (int) $payload['timezone_offset'];
            if ($offset < -720 || $offset > 840) {
                $score += 20;
            }
        }

        return $score;
    }

    /**
     * Check GeoIP vs timezone consistency (soft signal)
     */
    private function check_geo_consistency($payload, $ip_address)
    {
        if (!isset($payload['timezone'])) {
            return 0;
        }

        $location = $this->geoip->get_location($ip_address);
        if (!$location || !isset($location['country_code'])) {
            return 0;
        }

        $expected_tz = $this->geoip->get_country_timezone($location['country_code']);
        $actual_tz = $payload['timezone'];

        // Basic mismatch = +10 points (soft signal)
        if ($expected_tz && $actual_tz !== $expected_tz) {
            return 10; // Will be escalated to 25 if combined with other signals
        }

        return 0;
    }

    /**
     * Check HTTP header consistency (optional, research only)
     */
    private function check_http_headers($payload)
    {
        $score = 0;

        // Skip if behind CDN (unreliable)
        if (isset($_SERVER['HTTP_CF_RAY']) || isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return 0;
        }

        // Check UA vs Accept header consistency
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        // Chrome should have specific Accept header
        if (strpos($ua, 'Chrome') !== false && strpos($accept, 'text/html') === false) {
            $score += 5;
        }

        // Check for missing Accept-Language
        if (empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $score += 5;
        }

        // Max 10 points for HTTP analysis
        return min($score, 10);
    }

    /**
     * Check if timezone mismatch should escalate based on other signals
     */
    public function should_escalate_timezone($has_fast_fill, $has_fingerprint_reuse, $has_frequency)
    {
        return ($has_fast_fill || $has_fingerprint_reuse || $has_frequency);
    }
}
