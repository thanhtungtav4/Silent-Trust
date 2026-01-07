<?php
namespace SilentTrust;

if (!defined('ABSPATH'))
    exit;

/**
 * ML Weight Adjuster - Auto-learn optimal risk factor weights
 * Uses simplified statistical ML (precision-based effectiveness)
 */
class ML_Weight_Adjuster
{
    private $db;
    private $default_weights = [
        'fingerprint' => 25,
        'behavior' => 30,
        'ip' => 15,
        'frequency' => 30
    ];

    public function __construct()
    {
        $this->db = new Database();
    }

    /**
     * Calculate optimal weights based on historical data
     * 
     * @param int $sample_size Number of recent submissions to analyze (default: 500)
     * @param int $min_required Minimum submissions required for training (default: 100)
     * @return array|WP_Error Optimized weights or error
     */
    public function calculate_optimal_weights($sample_size = 500, $min_required = 100)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';

        // Get total submission count
        $total_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        if ($total_count < $min_required) {
            return new \WP_Error(
                'insufficient_data',
                sprintf('Need at least %d submissions for training. Current: %d', $min_required, $total_count)
            );
        }

        // Get labeled training data
        $submissions = $this->get_labeled_data($sample_size);

        if (empty($submissions)) {
            return new \WP_Error('no_data', 'No training data available');
        }

        // Calculate effectiveness for each factor
        $effectiveness = [
            'fingerprint' => $this->calculate_factor_effectiveness($submissions, 'fingerprint'),
            'behavior' => $this->calculate_factor_effectiveness($submissions, 'behavior'),
            'ip' => $this->calculate_factor_effectiveness($submissions, 'ip'),
            'frequency' => $this->calculate_factor_effectiveness($submissions, 'frequency')
        ];

        // Normalize to 100%
        $total = array_sum($effectiveness);

        if ($total == 0) {
            // Fallback to defaults if no effectiveness calculated
            return $this->default_weights;
        }

        $weights = [];
        foreach ($effectiveness as $factor => $score) {
            $weights[$factor] = round(($score / $total) * 100);
        }

        // Ensure total is exactly 100 (rounding adjustment)
        $sum = array_sum($weights);
        if ($sum != 100) {
            $weights['fingerprint'] += (100 - $sum);
        }

        return $weights;
    }

    /**
     * Get labeled training data (submissions with outcome)
     */
    private function get_labeled_data($sample_size)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';

        // Get recent submissions with risk breakdown
        return $wpdb->get_results($wpdb->prepare(
            "SELECT 
                id,
                risk_score,
                risk_breakdown,
                action,
                email_sent,
                email_failure_reason
            FROM {$table}
            WHERE risk_breakdown IS NOT NULL
            ORDER BY submitted_at DESC
            LIMIT %d",
            $sample_size
        ));
    }

    /**
     * Calculate effectiveness of a risk factor
     * Uses Precision = TP / (TP + FP)
     * 
     * @param array $submissions Training data
     * @param string $factor Factor name (fingerprint, behavior, ip, frequency)
     * @return float Effectiveness score (0-1)
     */
    private function calculate_factor_effectiveness($submissions, $factor)
    {
        $true_positives = 0;
        $false_positives = 0;
        $factor_contributed_count = 0;

        foreach ($submissions as $sub) {
            $breakdown = json_decode($sub->risk_breakdown, true);

            if (empty($breakdown)) {
                continue;
            }

            // Check if this factor contributed to the risk score
            $factor_contributed = $this->did_factor_contribute($breakdown, $factor);

            if (!$factor_contributed) {
                continue;
            }

            $factor_contributed_count++;

            // Label ground truth: 
            // TRUE SPAM = email NOT sent (blocked) AND action suggests spam
            // FALSE SPAM = email WAS sent (allowed through)
            $is_actual_spam = $this->is_spam_label($sub);

            if ($is_actual_spam) {
                $true_positives++;
            } else {
                $false_positives++;
            }
        }

        // Avoid division by zero
        if ($factor_contributed_count == 0) {
            return 0;
        }

        // Precision = TP / (TP + FP)
        $precision = $true_positives / max(1, $true_positives + $false_positives);

        // Weight by how often this factor participated
        $participation_rate = $factor_contributed_count / count($submissions);

        // Final effectiveness = precision * participation
        return $precision * $participation_rate;
    }

    /**
     * Check if a factor contributed to risk score
     */
    private function did_factor_contribute($breakdown, $factor)
    {
        // Look for factor-specific keys in breakdown
        $factor_patterns = [
            'fingerprint' => ['fingerprint_', 'device_', 'cookie_'],
            'behavior' => ['behavior_', 'typing_', 'time_per_field'],
            'ip' => ['ip_', 'vpn_', 'country_'],
            'frequency' => ['frequency_', 'rate_', 'daily_limit']
        ];

        $patterns = $factor_patterns[$factor] ?? [];

        foreach ($breakdown as $key => $value) {
            foreach ($patterns as $pattern) {
                if (strpos($key, $pattern) !== false && $value > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine if submission is spam (ground truth label)
     */
    private function is_spam_label($submission)
    {
        // Definition: Spam = blocked AND not sent
        $blocked_actions = ['drop', 'soft_penalty', 'hard_penalty'];
        $was_blocked = in_array($submission->action, $blocked_actions);
        $email_not_sent = !$submission->email_sent;

        return $was_blocked && $email_not_sent;
    }

    /**
     * Save learned weights to database
     */
    public function save_weights($weights)
    {
        $data = [
            'weights' => $weights,
            'trained_at' => current_time('mysql'),
            'version' => SILENT_TRUST_VERSION
        ];

        update_option('st_ml_weights', wp_json_encode($data));
    }

    /**
     * Get current weights (learned or default)
     */
    public function get_current_weights()
    {
        $saved = get_option('st_ml_weights');

        if (empty($saved)) {
            return $this->default_weights;
        }

        $data = json_decode($saved, true);
        return $data['weights'] ?? $this->default_weights;
    }

    /**
     * Get training metadata
     */
    public function get_training_info()
    {
        $saved = get_option('st_ml_weights');

        if (empty($saved)) {
            return [
                'trained' => false,
                'using_defaults' => true
            ];
        }

        $data = json_decode($saved, true);

        return [
            'trained' => true,
            'using_defaults' => false,
            'trained_at' => $data['trained_at'] ?? null,
            'version' => $data['version'] ?? null,
            'weights' => $data['weights'] ?? $this->default_weights
        ];
    }

    /**
     * Reset to default weights
     */
    public function reset_to_defaults()
    {
        delete_option('st_ml_weights');
    }

    /**
     * Check if enough data for training
     */
    public function can_train($min_required = 100)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        return $count >= $min_required;
    }
}
