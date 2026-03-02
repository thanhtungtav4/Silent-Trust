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

        $submission_id = $submission_data['id'] ?? null;
        $action = $log ? 'allow_log' : 'allow';

        if ($submission_id) {
            $this->update_submission_decision($submission_id, $risk_score, $risk_breakdown, $action);

            // Fire email syncronously here since risk has passed 
            // Note: This execution itself is already inside the background worker
            $this->send_direct_email($submission_data, $submission_id);

            // Fire Webhook Event
            do_action('st_submission_passed', $submission_data);
        }

        return [
            'proceed' => true,
            'action' => $action,
            'track_smtp' => true
        ];
    }

    /**
     * Handle silent delay via Action Scheduler
     */
    private function handle_delay($submission_data, $risk_score, $risk_breakdown)
    {
        $delay_seconds = wp_rand(120, 300); // Delay between 2 to 5 minutes

        // Ensure submission_id exists (it was passed as id if loaded from DB in worker)
        $submission_id = $submission_data['id'] ?? null;

        if ($submission_id) {
            // Schedule the job via Action Scheduler
            as_schedule_single_action(
                time() + $delay_seconds,
                'st_send_delayed_mail',
                ['submission_id' => $submission_id]
            );

            // Log the action to the DB row
            $this->update_submission_decision($submission_id, $risk_score, $risk_breakdown, 'delay');
        } else {
            st_log('Failed to schedule delayed mail: missing submission ID in worker context.', 'error');
        }

        return [
            'proceed' => false,
            'action' => 'delay',
            'silent' => true
        ];
    }

    /**
     * Handle silent drop (with optional penalty)
     */
    private function handle_drop($submission_data, $risk_score, $risk_breakdown, $add_penalty = false, $penalty_type = null)
    {
        $action = $add_penalty ? ($penalty_type === 'soft' ? 'soft_penalty' : 'hard_penalty') : 'drop';

        $submission_id = $submission_data['id'] ?? null;

        if ($submission_id) {
            $this->update_submission_decision($submission_id, $risk_score, $risk_breakdown, $action);
        }

        // Add penalties if requested
        if ($add_penalty) {
            $fingerprint_hash = $submission_data['fingerprint_hash'] ?? '';
            $ip_address = $submission_data['ip_address'] ?? '';

            // Always add fingerprint penalty
            if ($fingerprint_hash) {
                $this->db->add_device_penalty($fingerprint_hash, "Risk score: $risk_score", 30, $penalty_type);
            }

            // Add IP penalty only for hard penalty (>85)
            if ($penalty_type === 'hard' && $ip_address) {
                $this->db->add_ip_penalty($ip_address, "Risk score: $risk_score", 7, 'hard');
            }
        }

        return [
            'proceed' => false,
            'action' => $action,
            'silent' => true
        ];
    }

    /**
     * Send email directly
     */
    private function send_direct_email($submission_data, $submission_id)
    {
        $mail_data = $submission_data['mail_data'] ?? null;
        if (!$mail_data || !is_array($mail_data)) {
            return false;
        }

        $sent = wp_mail(
            $mail_data['to'],
            $mail_data['subject'],
            $mail_data['body'],
            $mail_data['headers']
        );

        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';

        $wpdb->update(
            $table,
            [
                'email_sent' => $sent ? 1 : 0,
                'email_failure_reason' => $sent ? null : 'WP_Mail failed during allowable processing',
                'sent_via' => 'direct'
            ],
            ['id' => $submission_id]
        );

        return $sent;
    }

    /**
     * Helper to log decision over an existing DB row natively
     */
    private function update_submission_decision($submission_id, $risk_score, $risk_breakdown, $action)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';

        $wpdb->update(
            $table,
            [
                'risk_score' => $risk_score,
                'risk_breakdown' => wp_json_encode($risk_breakdown),
                'action' => $action,
            ],
            ['id' => $submission_id]
        );
    }
}
