<?php
namespace SilentTrust;

if (!defined('ABSPATH'))
    exit;

class Risk_Engine
{

    private $db;
    private $validator;
    private $vpn_detector;

    // Dynamic weights (loaded from ML or defaults)
    private $weight_fingerprint = 0.25;  // 25%
    private $weight_behavior = 0.30;     // 30%
    private $weight_ip = 0.15;           // 15%
    private $weight_frequency = 0.30;    // 30%

    public function __construct()
    {
        $this->db = new Database();
        $this->validator = new Payload_Validator();
        $this->vpn_detector = new VPN_Detector();

        // Load ML-learned weights if available
        $this->load_weights();
    }

    /**
     * Load weights from ML training or use defaults
     */
    private function load_weights()
    {
        $ml = new ML_Weight_Adjuster();
        $weights = $ml->get_current_weights();

        // Convert percentage to decimal
        $this->weight_fingerprint = $weights['fingerprint'] / 100;
        $this->weight_behavior = $weights['behavior'] / 100;
        $this->weight_ip = $weights['ip'] / 100;
        $this->weight_frequency = $weights['frequency'] / 100;
    }

    /**
     * Calculate risk score for a submission
     */
    public function calculate_risk($payload, $ip_address, $device_cookie = null)
    {
        $breakdown = [];
        $total_score = 0;

        // Pre-checks
        $precheck = $this->precheck($device_cookie, $ip_address, $payload['fingerprint_hash'] ?? '');
        if ($precheck['instant_allow']) {
            return [
                'score' => 0,
                'breakdown' => ['whitelisted' => 0],
                'device_type' => $payload['device_type'] ?? 'unknown',
                'threshold_mode' => 'normal'
            ];
        }

        if ($precheck['instant_block']) {
            return [
                'score' => 100,
                'breakdown' => ['penalized' => 100],
                'device_type' => $payload['device_type'] ?? 'unknown',
                'threshold_mode' => 'normal'
            ];
        }

        // Server-side validation
        $validation = $this->validator->validate($payload, $ip_address);
        if ($validation['score'] > 0) {
            $breakdown['server_validation'] = $validation['score'];
            $total_score += $validation['score'];
        }

        // Determine traffic mode for adaptive thresholds
        $threshold_mode = $this->get_threshold_mode();

        // Fingerprint correlation (25%)
        $fp_score = $this->analyze_fingerprint($payload);
        if ($fp_score > 0) {
            $breakdown['fingerprint_reuse'] = $fp_score;
            $total_score += $fp_score;
        }

        // Behavior anomaly (30%)
        $behavior_score = $this->analyze_behavior($payload, $threshold_mode);
        if ($behavior_score > 0) {
            $breakdown = array_merge($breakdown, $behavior_score);
            $total_score += array_sum($behavior_score);
        }

        // IP/Subnet reputation (15%)
        $ip_score = $this->analyze_ip_reputation($ip_address);
        if ($ip_score > 0) {
            $breakdown = array_merge($breakdown, $ip_score);
            $total_score += array_sum($ip_score);
        }

        // Frequency analysis (30%)
        $freq_score = $this->analyze_frequency($payload['fingerprint_hash'] ?? '', $ip_address);
        if ($freq_score > 0) {
            $breakdown = array_merge($breakdown, $freq_score);
            $total_score += array_sum($freq_score);
        }

        // Escalate timezone mismatch if combined with other signals
        if (in_array('timezone_mismatch', $validation['flags'] ?? [])) {
            $has_other_signals = isset($breakdown['fast_fill']) ||
                isset($breakdown['fingerprint_reuse']) ||
                isset($breakdown['frequency_fp']);

            if ($has_other_signals) {
                $breakdown['timezone_escalated'] = 15; // Escalate from 10 to 25
                $total_score += 15;
            }
        }

        return [
            'score' => min($total_score, 100),
            'breakdown' => $breakdown,
            'device_type' => $payload['device_type'] ?? 'unknown',
            'threshold_mode' => $threshold_mode
        ];
    }

    /**
     * Pre-checks: whitelist and penalty list
     * Pre-checks for instant allow/block
     */
    private function precheck($device_cookie, $ip_address, $fingerprint_hash)
    {
        // CRITICAL: Check daily submission limit FIRST (even before whitelist)
        // This is a hard limit that applies to EVERYONE
        $daily_check = $this->db->check_daily_limit($device_cookie);
        if ($daily_check['exceeded']) {
            error_log(sprintf(
                '[Silent Trust] Daily limit exceeded - Device: %s, Count: %d, Limit: %d',
                substr($device_cookie, 0, 8),
                $daily_check['count'],
                $daily_check['limit']
            ));
            return ['instant_allow' => false, 'instant_block' => true, 'reason' => 'daily_limit_exceeded'];
        }

        // Check whitelist (only matters if under daily limit)
        if (!empty($device_cookie) && $this->db->is_whitelisted($device_cookie)) {
            return ['instant_allow' => true, 'instant_block' => false];
        }

        // Check penalties
        if (
            $this->db->is_penalized($fingerprint_hash, 'fingerprint') ||
            $this->db->is_penalized($ip_address, 'ip')
        ) {
            return ['instant_allow' => false, 'instant_block' => true];
        }

        return ['instant_allow' => false, 'instant_block' => false];
    }

    /**
     * Get traffic mode for adaptive thresholds
     */
    private function get_threshold_mode()
    {
        $mode_setting = get_option('silent_trust_traffic_mode', 'auto');

        if ($mode_setting !== 'auto') {
            return $mode_setting;
        }

        // Auto-detect based on daily volume
        $daily_volume = $this->db->get_daily_volume();

        if ($daily_volume < 20) {
            return 'lenient';
        } elseif ($daily_volume < 100) {
            return 'normal';
        } else {
            return 'strict';
        }
    }

    /**
     * Analyze fingerprint correlation (25% weight)
     */
    private function analyze_fingerprint($payload)
    {
        $fp_hash = $payload['fingerprint_hash'] ?? '';
        if (empty($fp_hash)) {
            return 0;
        }

        // Weighted trait matching (stable + volatile)
        $stable_match = $this->check_stable_traits($payload);
        $freq = $this->db->get_fingerprint_frequency($fp_hash, 1);

        if ($freq && $freq->count > 3) {
            if ($stable_match) {
                // Stable traits match + hash reuse = high confidence
                return 25;
            } else {
                // Only hash matches, different stable traits = possible collision
                return 10;
            }
        }

        return 0;
    }

    /**
     * Check if stable traits match (UA family, platform, timezone, screen)
     */
    private function check_stable_traits($payload)
    {
        // This is simplified - in production, query DB for similar submissions
        // and compare stable traits
        return true; // Placeholder
    }

    /**
     * Analyze behavior anomalies (30% weight)
     */
    private function analyze_behavior($payload, $threshold_mode)
    {
        $scores = [];
        $device_type = $payload['device_type'] ?? 'unknown';

        // Get confidence multiplier based on traffic mode
        $confidence_multiplier = $threshold_mode === 'lenient' ? 3.0 :
            ($threshold_mode === 'normal' ? 2.0 : 1.5);

        // Time-per-field calculation
        if (isset($payload['time_per_field'])) {
            $time_per_field = (float) $payload['time_per_field'];

            // Use global baseline until enough data
            $threshold = ($device_type === 'mobile') ? 600 : 400; // ms

            if ($time_per_field < $threshold) {
                $scores['fast_fill'] = 20;
            }
        }

        // Desktop-specific checks
        if ($device_type === 'desktop') {
            $total_time = (float) ($payload['total_time'] ?? 0);
            $has_mouse = !empty($payload['mouse_events']);

            if (!$has_mouse && $total_time < 3) {
                $scores['no_mouse'] = 15;
            }

            if (isset($payload['typing_mechanical']) && $payload['typing_mechanical']) {
                $scores['mechanical_typing'] = 20;
            }
        }

        // Mobile-specific checks
        if ($device_type === 'mobile' || $device_type === 'tablet') {
            $total_time = (float) ($payload['total_time'] ?? 0);
            $has_touch = !empty($payload['touch_events']);

            if (!$has_touch && $total_time < 4) {
                $scores['no_touch'] = 15;
            }

            if (isset($payload['touch_speed']) && $payload['touch_speed'] > 10) {
                $scores['impossible_touch'] = 20;
            }
        }

        // All devices
        $total_time = (float) ($payload['total_time'] ?? 0);
        if ($total_time > 600) { // >10 minutes
            $scores['too_slow'] = 10;
        }

        if (isset($payload['typing_speed']) && $payload['typing_speed'] > 150) {
            $scores['superhuman_typing'] = 25;
        }

        return $scores;
    }

    /**
     * Analyze IP/Subnet reputation (15% weight)
     */
    private function analyze_ip_reputation($ip_address)
    {
        $scores = [];

        // Count distinct fingerprints for this IP
        $fp_count = $this->db->count_distinct_fingerprints_for_ip($ip_address, 24);
        if ($fp_count > 5) {
            $scores['ip_multi_fp'] = 15;
        }

        // VPN/Proxy/Datacenter detection
        $vpn_check = $this->vpn_detector->is_vpn_or_datacenter($ip_address);
        if ($vpn_check['is_vpn'] && !$this->vpn_detector->is_whitelisted($ip_address)) {
            $scores['vpn_detected'] = 10;
        }

        return $scores;
    }

    /**
     * Analyze frequency (30% weight) with risk decay
     */
    private function analyze_frequency($fingerprint_hash, $ip_address)
    {
        $scores = [];

        // Get daily limit from settings
        $daily_limit = (int) get_option('silent_trust_daily_limit', 3);

        // Fingerprint frequency (most reliable)
        $fp_freq_hour = $this->db->get_fingerprint_frequency($fingerprint_hash, 1);
        if ($fp_freq_hour && $fp_freq_hour->count > 3) {
            $scores['frequency_fp_hour'] = 30;
        }

        // Daily frequency check against configured limit
        $fp_freq_day = $this->db->get_fingerprint_frequency($fingerprint_hash, 24);
        if ($fp_freq_day && $fp_freq_day->count > $daily_limit) {
            // Exceeded daily limit - potential manual spam
            $scores['daily_limit_exceeded'] = 25;

            // If also hourly violation, escalate
            if (isset($scores['frequency_fp_hour'])) {
                $scores['frequency_fp_day'] = 25;
            }
        }

        // IP frequency
        $ip_freq = $this->db->get_ip_frequency($ip_address, 1);
        if ($ip_freq > 5) {
            $scores['frequency_ip'] = 20;
        }

        return $scores;
    }
}
