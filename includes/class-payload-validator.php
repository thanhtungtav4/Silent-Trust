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
     * Note: Honeypot checks are handled by CF7_Integration, not here.
     */
    public function validate($payload, $ip_address)
    {
        $score = 0;
        $flags = [];

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

        // Format validation (canvas hash, screen dims, timezone offset)
        $format_score = $this->validate_format($payload);
        $score += $format_score;
        if ($format_score > 0) {
            $flags[] = 'invalid_format';
        }

        // GeoIP consistency (soft signal) — uses $this->geoip, no duplicate instance
        $geo_score = $this->check_geo_consistency($payload, $ip_address);
        $score += $geo_score;
        if ($geo_score > 0) {
            $flags[] = 'timezone_mismatch';
        }

        // HTTP header analysis
        $http_score = $this->check_http_headers($payload);
        $score += $http_score;
        if ($http_score > 0) {
            $flags[] = 'http_header_anomaly';
        }

        // User-Agent mismatch check
        if (isset($payload['user_agent']) && !empty($_SERVER['HTTP_USER_AGENT'])) {
            $reported_ua = $payload['user_agent'];
            $actual_ua = $_SERVER['HTTP_USER_AGENT'];

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
