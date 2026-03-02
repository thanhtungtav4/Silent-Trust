<?php
namespace SilentTrust;

if (!defined('ABSPATH'))
    exit;

class Background_Worker
{
    private $db;

    public function __construct()
    {
        add_action('st_process_submission', [$this, 'process_submission']);
        add_action('st_send_delayed_mail', [$this, 'send_delayed_mail']);
    }

    /**
     * Process a pending submission asynchronously via Action Scheduler
     */
    public function process_submission($submission_id)
    {
        try {
            // Re-instantiate DB here so it's loaded in the worker context
            $this->db = new Database();

            global $wpdb;
            $table = $wpdb->prefix . 'st_submissions';

            // Get the pending submission
            $submission = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND status = 'pending'",
                $submission_id
            ), ARRAY_A);

            if (!$submission) {
                // Not found or already processed
                return;
            }

            // Unserialize behavior data
            $payload = json_decode($submission['behavior_data'], true);
            if (!is_array($payload)) {
                $payload = [];
            }

            // Lazy load the heavy engines ONLY when processing
            $risk_engine = new Risk_Engine();
            $decision_engine = new Decision_Engine();

            // Calculate Risk
            $risk_result = $risk_engine->calculate_risk(
                $payload,
                $submission['ip_address'],
                $submission['device_cookie']
            );

            // Rehydrate submission data array for decision engine
            $submission_data = $submission;
            $submission_data['mail_data'] = maybe_unserialize($submission['submission_data']);

            // Fetch and append GeoIP Data asynchronously
            $geoip = new GeoIP_Bundled();
            $location = $geoip->get_location($submission['ip_address']);

            $geoip_data = [];
            if ($location) {
                $geoip_data = [
                    'ip_country_name' => $location['country'] ?? null,
                    'country_code' => $location['country_code'] ?? null,
                    'city' => $location['city'] ?? null,
                    'asn' => $location['asn'] ?? null,
                    'ip_region' => $location['region'] ?? null,
                    'ip_latitude' => $location['latitude'] ?? null,
                    'ip_longitude' => $location['longitude'] ?? null,
                    'ip_timezone' => $location['timezone'] ?? null
                ];
                $submission_data = array_merge($submission_data, $geoip_data);
            }

            // Execute Decision
            $decision = $decision_engine->execute(
                $risk_result['score'],
                $risk_result['breakdown'],
                $submission_data
            );

            // Note: $decision_engine->execute() already handles logging the update (or scheduling mail)
            // But we still need to mark the DB row as 'processed' and append GeoIP data

            $update_data = ['status' => 'processed'];
            if (!empty($geoip_data)) {
                $update_data = array_merge($update_data, $geoip_data);
            }

            $wpdb->update(
                $table,
                $update_data,
                ['id' => $submission_id]
            );

        } catch (\Exception $e) {
            st_log('Background worker failed to process submission ID ' . $submission_id . ': ' . $e->getMessage(), 'error');

            global $wpdb;
            $table = $wpdb->prefix . 'st_submissions';
            $wpdb->update(
                $table,
                ['status' => 'failed', 'email_failure_reason' => substr($e->getMessage(), 0, 500)],
                ['id' => $submission_id]
            );
        }
    }

    /**
     * Send a delayed mail asynchronously via Action Scheduler
     */
    public function send_delayed_mail($submission_id)
    {
        try {
            $this->db = new Database();
            global $wpdb;
            $table = $wpdb->prefix . 'st_submissions';

            // Get the submission
            $submission = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $submission_id
            ), ARRAY_A);

            if (!$submission) {
                return;
            }

            // Get mail data
            $mail_data = maybe_unserialize($submission['submission_data']);
            if (!$mail_data || !is_array($mail_data)) {
                return;
            }

            // Send the email
            $sent = wp_mail(
                $mail_data['to'],
                $mail_data['subject'],
                $mail_data['body'],
                $mail_data['headers']
            );

            // Update log with sent status
            $wpdb->update(
                $table,
                [
                    'email_sent' => $sent ? 1 : 0,
                    'email_failure_reason' => $sent ? null : 'Mail send failed asynchronously',
                    'sent_via' => 'cron' // Keep cron label for analytics
                ],
                ['id' => $submission_id],
                ['%d', '%s', '%s'],
                ['%d']
            );

            // Fire Webhook Event since it finally passed
            $submission['mail_data'] = $mail_data;
            do_action('st_submission_passed', $submission);

        } catch (\Exception $e) {
            st_log('Failed to send delayed mail for submission ID ' . $submission_id . ': ' . $e->getMessage(), 'error');
        }
    }
}
