<?php
namespace SilentTrust;

if (!defined('ABSPATH'))
    exit;

class Decision_Engine
{

    private $db;

    public function __construct()
    {
        $this->db = new Database();
    }

    /**
     * Execute decision based on risk score
     */
    public function execute($risk_score, $risk_breakdown, $submission_data)
    {
        $action = $this->determine_action($risk_score);

        switch ($action) {
            case 'allow':
                return $this->handle_allow($submission_data, $risk_score, $risk_breakdown, false);

            case 'allow_log':
                return $this->handle_allow($submission_data, $risk_score, $risk_breakdown, true);

            case 'delay':
                return $this->handle_delay($submission_data, $risk_score, $risk_breakdown);

            case 'drop':
                return $this->handle_drop($submission_data, $risk_score, $risk_breakdown, false);

            case 'soft_penalty':
                return $this->handle_drop($submission_data, $risk_score, $risk_breakdown, true, 'soft');

            case 'hard_penalty':
                return $this->handle_drop($submission_data, $risk_score, $risk_breakdown, true, 'hard');
        }

        return ['proceed' => true, 'action' => 'allow'];
    }

    /**
     * Determine action from risk score
     */
    private function determine_action($risk_score)
    {
        if ($risk_score < 30) {
            return 'allow';
        } elseif ($risk_score < 50) {
            return 'allow_log';
        } elseif ($risk_score < 65) {
            return 'delay';
        } elseif ($risk_score < 70) {
            return 'drop';
        } elseif ($risk_score < 85) {
            return 'soft_penalty';
        } else {
            return 'hard_penalty';
        }
    }

    /**
     * Handle allow (with or without logging)
     */
    private function handle_allow($submission_data, $risk_score, $risk_breakdown, $log = false)
    {
        // Update whitelist for device cookie
        if (!empty($submission_data['device_cookie'])) {
            $this->db->update_whitelist($submission_data['device_cookie']);
        }

        // CRITICAL: Always log ALL submissions for audit trail and analytics
        // This includes whitelisted users (risk = 0) for tracking purposes
        $this->log_submission($submission_data, $risk_score, $risk_breakdown, $log ? 'allow_log' : 'allow', true);

        return [
            'proceed' => true,
            'action' => $log ? 'allow_log' : 'allow',
            'track_smtp' => true
        ];
    }

    /**
     * Handle silent delay via WP-Cron
     */
    private function handle_delay($submission_data, $risk_score, $risk_breakdown)
    {
        $mail_data = $submission_data['mail_data'];
        $delay = rand(2, 5);
        $fallback_id = uniqid('st_mail_');

        $mail_data['fallback_id'] = $fallback_id;
        $mail_data['scheduled_at'] = time();

        // Schedule send via WP-Cron
        wp_schedule_single_event(
            time() + $delay,
            'silent_trust_delayed_mail_send',
            ['mail_data' => $mail_data, 'submission_data' => $submission_data]
        );

        // Store in transient as fallback (expires in 30s)
        set_transient('st_mail_fallback_' . $fallback_id, [
            'mail_data' => $mail_data,
            'submission_data' => $submission_data
        ], 30);

        // Log the delay action
        $this->log_submission($submission_data, $risk_score, $risk_breakdown, 'delay', false, 'cron');

        return [
            'proceed' => false, // Don't send immediately
            'action' => 'delay',
            'silent' => true
        ];
    }

    /**
     * Handle silent drop (with optional penalty)
     */
    private function handle_drop($submission_data, $risk_score, $risk_breakdown, $add_penalty = false, $penalty_type = null)
    {
        // Log the drop
        $action = $add_penalty ? ($penalty_type === 'soft' ? 'soft_penalty' : 'hard_penalty') : 'drop';
        $this->log_submission($submission_data, $risk_score, $risk_breakdown, $action, false);

        // Add penalties if requested
        if ($add_penalty) {
            $fingerprint_hash = $submission_data['fingerprint_hash'];
            $ip_address = $submission_data['ip_address'];

            // Always add fingerprint penalty
            $this->db->add_penalty(
                $penalty_type,
                'fingerprint',
                $fingerprint_hash,
                "Risk score: $risk_score"
            );

            // Add IP penalty only for hard penalty (>85)
            if ($penalty_type === 'hard') {
                $this->db->add_penalty(
                    'hard',
                    'ip',
                    $ip_address,
                    "Risk score: $risk_score"
                );
            }

            // Log to anomalies table
            // Note: need submission_id, will update after log_submission
        }

        return [
            'proceed' => false,
            'action' => $action,
            'silent' => true
        ];
    }

    /**
     * Log submission to database
     */
    private function log_submission($submission_data, $risk_score, $risk_breakdown, $action, $email_sent = false, $sent_via = 'direct')
    {
        // Pass entire submission_data array (already has analytics merged in CF7_Integration)
        // Just add the fields we determine here
        $log_data = array_merge($submission_data, [
            'risk_score' => $risk_score,
            'risk_breakdown' => $risk_breakdown,
            'action' => $action,
            'email_sent' => $email_sent ? 1 : 0,
            'email_failure_reason' => null, // Will be updated if SMTP fails
            'sent_via' => $sent_via,
            // Ensure JSON encoding for object fields
            'fingerprint_data' => is_string($submission_data['fingerprint_data'] ?? null)
                ? $submission_data['fingerprint_data']
                : json_encode($submission_data['fingerprint_data'] ?? []),
            'behavior_data' => is_string($submission_data['behavior_data'] ?? null)
                ? $submission_data['behavior_data']
                : json_encode($submission_data['behavior_data'] ?? [])
        ]);

        $this->db->log_submission($log_data);
    }
}
