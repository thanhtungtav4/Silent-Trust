<?php
namespace SilentTrust;

if (!defined('ABSPATH'))
    exit;

class Admin_AJAX
{

    private $db;

    public function __construct()
    {
        $this->db = new Database();

        add_action('wp_ajax_st_get_dashboard_stats', [$this, 'get_dashboard_stats']);
        add_action('wp_ajax_st_get_trend_data', [$this, 'get_trend_data']);
        add_action('wp_ajax_st_get_action_stats', [$this, 'get_action_stats']);
        add_action('wp_ajax_st_get_risk_distribution', [$this, 'get_risk_distribution']);
        add_action('wp_ajax_st_get_explainability', [$this, 'get_explainability']);
        add_action('wp_ajax_st_download_geoip_db', [$this, 'download_geoip_db']);
        add_action('wp_ajax_st_optimize_weights', [$this, 'optimize_weights']);
        add_action('wp_ajax_st_reset_weights', [$this, 'reset_weights']);
    }

    /**
     * Get dashboard statistics
     */
    public function get_dashboard_stats()
    {
        check_ajax_referer('st_admin_nonce', 'nonce');

        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';

        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN action IN ('drop', 'soft_penalty', 'hard_penalty') THEN 1 ELSE 0 END) as dropped,
                SUM(CASE WHEN email_sent=1 THEN 1 ELSE 0 END) as emails_sent,
                SUM(CASE WHEN email_sent=0 AND email_failure_reason IS NOT NULL THEN 1 ELSE 0 END) as smtp_failures,
                SUM(CASE WHEN sent_via='fallback' THEN 1 ELSE 0 END) as fallback_sends
            FROM {$table}
            WHERE DATE(submitted_at) = CURDATE()"
        );

        $drop_rate = $stats->total > 0 ? ($stats->dropped / $stats->total) * 100 : 0;
        $email_delivery_rate = $stats->total > 0 ? ($stats->emails_sent / $stats->total) * 100 : 0;
        $fallback_rate = ($stats->total > 0 && $stats->fallback_sends > 0) ? ($stats->fallback_sends / $stats->total) * 100 : 0;

        wp_send_json_success([
            'total' => $stats->total,
            'drop_rate' => round($drop_rate, 1),
            'email_rate' => round($email_delivery_rate, 1),
            'smtp_failures' => $stats->smtp_failures,
            'fallback_rate' => round($fallback_rate, 1)
        ]);
    }

    /**
     * Get trend data for chart
     */
    public function get_trend_data()
    {
        check_ajax_referer('st_admin_nonce', 'nonce');

        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';

        $data = $wpdb->get_results(
            "SELECT 
                DATE(submitted_at) as date,
                COUNT(*) as total,
                SUM(CASE WHEN action='allow' OR action='allow_log' THEN 1 ELSE 0 END) as allowed,
                SUM(CASE WHEN action IN ('drop', 'soft_penalty', 'hard_penalty') THEN 1 ELSE 0 END) as dropped
            FROM {$table}
            WHERE submitted_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(submitted_at)
            ORDER BY date ASC"
        );

        wp_send_json_success($data);
    }

    /**
     * Get action distribution
     */
    public function get_action_stats()
    {
        check_ajax_referer('st_admin_nonce', 'nonce');

        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';

        $data = $wpdb->get_results(
            "SELECT action, COUNT(*) as count
            FROM {$table}
            WHERE submitted_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY action"
        );

        wp_send_json_success($data);
    }

    /**
     * Get risk score distribution
     */
    public function get_risk_distribution()
    {
        check_ajax_referer('st_admin_nonce', 'nonce');

        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';

        $data = $wpdb->get_results(
            "SELECT 
                FLOOR(risk_score / 10) * 10 as score_range,
                COUNT(*) as count
            FROM {$table}
            WHERE submitted_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY score_range
            ORDER BY score_range ASC"
        );

        wp_send_json_success($data);
    }

    /**
     * Get explainability for a submission
     */
    public function get_explainability()
    {
        check_ajax_referer('st_admin_nonce', 'nonce');

        $id = (int) $_POST['id'];

        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';

        $submission = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ));

        if (!$submission) {
            wp_send_json_error('Submission not found');
        }

        $breakdown = json_decode($submission->risk_breakdown, true);

        wp_send_json_success([
            'submission' => $submission,
            'breakdown' => $breakdown
        ]);
    }

    /**
     * Download GeoIP database manually
     */
    public function download_geoip_db()
    {
        check_ajax_referer('st_download_geoip', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        // Increase timeout for large download
        set_time_limit(300); // 5 minutes

        try {
            $geoip = new \SilentTrust\GeoIP_Bundled();
            $result = $geoip->download_database();

            if (is_wp_error($result)) {
                wp_send_json_error([
                    'message' => $result->get_error_message()
                ]);
            }

            wp_send_json_success([
                'message' => 'Database downloaded successfully! (~70MB)'
            ]);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Optimize ML weights based on historical data
     */
    public function optimize_weights()
    {
        check_ajax_referer('st_optimize_weights', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        try {
            $ml = new \SilentTrust\ML_Weight_Adjuster();
            $weights = $ml->calculate_optimal_weights();

            if (is_wp_error($weights)) {
                wp_send_json_error([
                    'message' => $weights->get_error_message()
                ]);
            }

            $ml->save_weights($weights);

            wp_send_json_success([
                'message' => sprintf(
                    'Weights optimized! FP:%d%% | BH:%d%% | IP:%d%% | FR:%d%%',
                    $weights['fingerprint'],
                    $weights['behavior'],
                    $weights['ip'],
                    $weights['frequency']
                ),
                'weights' => $weights
            ]);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Reset ML weights to defaults
     */
    public function reset_weights()
    {
        check_ajax_referer('st_reset_weights', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        try {
            $ml = new \SilentTrust\ML_Weight_Adjuster();
            $ml->reset_to_defaults();

            wp_send_json_success([
                'message' => 'Weights reset to defaults (FP:25% | BH:30% | IP:15% | FR:30%)'
            ]);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }
}
