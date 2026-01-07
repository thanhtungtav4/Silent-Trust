<?php
namespace SilentTrust;

if (!defined('ABSPATH'))
    exit;

/**
 * Async Processor - Two-tier spam analysis for fast form responses
 * Tier 1: Quick pre-check (<10ms) for instant blocks
 * Tier 2: Full analysis queued via WP-Cron (non-blocking)
 */
class Async_Processor
{
    private $db;
    private $risk;

    public function __construct()
    {
        $this->db = new Database();
        $this->risk = new Risk_Engine();
    }

    /**
     * Quick pre-check for instant spam detection (<10ms)
     * Only checks fast, indexed database queries
     * 
     * @param array $payload Fingerprint payload
     * @param string $ip_address Client IP
     * @return array ['instant_block' => bool, 'reason' => string]
     */
    public function quick_precheck($payload, $ip_address)
    {
        // Check 1: Device cookie penalty (indexed query)
        $device_penalty = $this->db->get_device_penalty($payload['fingerprint_hash'] ?? '');
        if ($device_penalty && $device_penalty['severity'] === 'hard') {
            return [
                'instant_block' => true,
                'reason' => 'device_hard_penalty',
                'action' => 'drop'
            ];
        }

        // Check 2: IP blacklist (cached)
        $ip_penalty = $this->db->get_ip_penalty($ip_address);
        if ($ip_penalty && $ip_penalty['severity'] === 'hard') {
            return [
                'instant_block' => true,
                'reason' => 'ip_blacklisted',
                'action' => 'drop'
            ];
        }

        // Check 3: Extreme frequency (>10 submissions/minute from same IP)
        $recent_count = $this->db->count_recent_submissions($ip_address, 60); // Last 60 seconds
        if ($recent_count > 10) {
            return [
                'instant_block' => true,
                'reason' => 'extreme_frequency',
                'action' => 'drop'
            ];
        }

        // Check 4: Ultra-fast typing (obvious bot)
        if (isset($payload['typing_speed']) && $payload['typing_speed'] < 50) {
            // Less than 50ms per field = impossible for human
            return [
                'instant_block' => true,
                'reason' => 'bot_typing_speed',
                'action' => 'drop'
            ];
        }

        // Passed quick checks - queue for full analysis
        return [
            'instant_block' => false,
            'reason' => 'quick_check_passed'
        ];
    }

    /**
     * Queue submission for async full analysis
     * 
     * @param array $payload Fingerprint payload
     * @param string $ip_address Client IP
     * @param int $form_id CF7 form ID
     * @param int $submission_id Submission record ID
     * @return bool Success
     */
    public function queue_analysis($payload, $ip_address, $form_id, $submission_id = null)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'st_analysis_queue';

        // Insert to queue
        $inserted = $wpdb->insert($table, [
            'payload_hash' => $payload['fingerprint_hash'] ?? md5(json_encode($payload)),
            'payload_data' => wp_json_encode($payload),
            'ip_address' => $ip_address,
            'form_id' => $form_id,
            'submission_id' => $submission_id,
            'created_at' => current_time('mysql'),
            'status' => 'pending'
        ]);

        if (!$inserted) {
            error_log('[Silent Trust] Failed to queue analysis: ' . $wpdb->last_error);
            return false;
        }

        $queue_id = $wpdb->insert_id;

        // Schedule immediate WP-Cron processing
        if (!wp_next_scheduled('st_process_async_analysis', [$queue_id])) {
            wp_schedule_single_event(time(), 'st_process_async_analysis', [$queue_id]);
        }

        return true;
    }

    /**
     * Process queued analysis item (WP-Cron handler)
     * 
     * @param int $queue_id Queue item ID
     */
    public function process_queued_item($queue_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'st_analysis_queue';

        // Get queue item
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND status = 'pending'",
            $queue_id
        ), ARRAY_A);

        if (!$item) {
            return; // Already processed or doesn't exist
        }

        // Mark as processing
        $wpdb->update($table, ['status' => 'processing'], ['id' => $queue_id]);

        try {
            // Decode payload
            $payload = json_decode($item['payload_data'], true);

            // Run full risk analysis
            $result = $this->risk->calculate_risk(
                $payload,
                $item['ip_address'],
                $item['payload_hash']
            );

            // Apply retroactive penalties if spam detected
            if ($result['score'] >= 70) {
                $this->apply_retroactive_penalty($item, $result);
            }

            // If low risk, consider whitelisting
            if ($result['score'] < 20) {
                $this->consider_whitelist($item, $result);
            }

            // Mark as completed
            $wpdb->update($table, [
                'status' => 'completed',
                'processed_at' => current_time('mysql')
            ], ['id' => $queue_id]);

        } catch (\Exception $e) {
            error_log('[Silent Trust] Async analysis failed: ' . $e->getMessage());

            $wpdb->update($table, [
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ], ['id' => $queue_id]);
        }
    }

    /**
     * Apply retroactive penalty to device and IP
     * Called when spam is detected after email was already sent
     */
    private function apply_retroactive_penalty($queue_item, $risk_result)
    {
        // Add hard penalty to device (30 days)
        $this->db->add_device_penalty(
            $queue_item['payload_hash'],
            'retroactive_spam_detected',
            30 // days
        );

        // Add soft penalty to IP (7 days)
        $this->db->add_ip_penalty(
            $queue_item['ip_address'],
            'retroactive_spam_ip',
            7 // days
        );

        // Log for admin review
        error_log(sprintf(
            '[Silent Trust] Retroactive spam penalty applied - Device: %s, IP: %s, Risk: %d',
            substr($queue_item['payload_hash'], 0, 8),
            $queue_item['ip_address'],
            $risk_result['score']
        ));
    }

    /**
     * Consider whitelisting device/IP if consistently low risk
     */
    private function consider_whitelist($queue_item, $risk_result)
    {
        // Check submission history for this device
        $history = $this->db->get_device_history($queue_item['payload_hash'], 10);

        // If 10+ submissions, all with score <20, whitelist
        if (count($history) >= 10) {
            $all_low_risk = true;
            foreach ($history as $submission) {
                if ($submission['risk_score'] >= 20) {
                    $all_low_risk = false;
                    break;
                }
            }

            if ($all_low_risk) {
                $this->db->whitelist_device($queue_item['payload_hash'], 90); // 90 days
            }
        }
    }

    /**
     * Check if async mode should be used
     * Falls back to sync if WP-Cron disabled
     */
    public function should_use_async()
    {
        // Check if async mode enabled in settings
        $async_enabled = get_option('st_async_mode', true);
        if (!$async_enabled) {
            return false;
        }

        // Check if WP-Cron is functional
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            return false; // Cron disabled, must use sync
        }

        return true;
    }

    /**
     * Cleanup old queue items (>1 hour)
     * Run via daily WP-Cron
     */
    public function cleanup_old_queue()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'st_analysis_queue';

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} 
            WHERE status IN ('completed', 'failed') 
            AND created_at < %s",
            date('Y-m-d H:i:s', strtotime('-1 hour'))
        ));

        if ($deleted) {
            error_log("[Silent Trust] Cleaned up {$deleted} old queue items");
        }
    }

    /**
     * Get queue statistics for admin dashboard
     */
    public function get_queue_stats()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'st_analysis_queue';

        return [
            'pending' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'"),
            'processing' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'processing'"),
            'completed_today' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} 
                WHERE status = 'completed' 
                AND created_at >= %s",
                date('Y-m-d 00:00:00')
            )),
            'failed_today' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} 
                WHERE status = 'failed' 
                AND created_at >= %s",
                date('Y-m-d 00:00:00')
            ))
        ];
    }
}
