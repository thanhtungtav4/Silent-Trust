<?php
namespace SilentTrust;

if (!defined('ABSPATH'))
    exit;

class Alert_System
{

    private $db;

    public function __construct()
    {
        $this->db = new Database();

        // Register cron hooks
        add_action('silent_trust_daily_digest', [$this, 'send_daily_digest']);
        add_action('silent_trust_weekly_report', [$this, 'send_weekly_report']);

        // Check for spikes after each submission
        add_action('silent_trust_check_spike', [$this, 'check_drop_spike']);
    }

    /**
     * Send daily digest email
     */
    public function send_daily_digest()
    {
        $recipients = $this->get_alert_recipients();
        if (empty($recipients)) {
            return;
        }

        $stats = $this->get_daily_stats();

        $subject = sprintf('[Silent Trust] Daily Digest - %s', date('Y-m-d'));
        $message = $this->build_daily_digest_email($stats);

        foreach ($recipients as $recipient) {
            wp_mail($recipient, $subject, $message);
        }
    }

    /**
     * Send weekly report
     */
    public function send_weekly_report()
    {
        $recipients = $this->get_alert_recipients();
        if (empty($recipients)) {
            return;
        }

        $stats = $this->get_weekly_stats();

        $subject = sprintf('[Silent Trust] Weekly Report - Week of %s', date('Y-m-d'));
        $message = $this->build_weekly_report_email($stats);

        foreach ($recipients as $recipient) {
            wp_mail($recipient, $subject, $message);
        }
    }

    /**
     * Check for drop rate spikes
     */
    public function check_drop_spike()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';

        // Get submissions in past hour
        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} 
            WHERE submitted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );

        if ($total < 5) {
            return; // Not enough data
        }

        $dropped = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} 
            WHERE submitted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            AND action IN ('drop', 'soft_penalty', 'hard_penalty')"
        );

        $drop_rate = ($dropped / $total) * 100;

        // Send alert if drop rate >20%
        if ($drop_rate > 20) {
            $this->send_spike_alert($drop_rate, $total, $dropped);
        }
    }

    /**
     * Send spike alert
     */
    private function send_spike_alert($drop_rate, $total, $dropped)
    {
        $recipients = $this->get_alert_recipients();
        if (empty($recipients)) {
            return;
        }

        $subject = '[Silent Trust] ALERT: High Drop Rate Detected!';
        $message = sprintf(
            "Alert: Drop rate spike detected!\n\n" .
            "Drop Rate: %.1f%%\n" .
            "Total Submissions (past hour): %d\n" .
            "Dropped: %d\n\n" .
            "Please review the dashboard: %s\n",
            $drop_rate,
            $total,
            $dropped,
            admin_url('admin.php?page=silent-trust')
        );

        foreach ($recipients as $recipient) {
            wp_mail($recipient, $subject, $message);
        }
    }

    /**
     * Get alert recipients
     */
    private function get_alert_recipients()
    {
        $recipients = get_option('silent_trust_alert_emails', get_option('admin_email'));

        if (is_string($recipients)) {
            $recipients = array_map('trim', explode(',', $recipients));
        }

        return array_filter($recipients, 'is_email');
    }

    /**
     * Get daily statistics
     */
    private function get_daily_stats()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';

        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN action='allow' OR action='allow_log' THEN 1 ELSE 0 END) as allowed,
                SUM(CASE WHEN action IN ('drop', 'soft_penalty', 'hard_penalty') THEN 1 ELSE 0 END) as dropped,
                SUM(CASE WHEN email_sent=1 THEN 1 ELSE 0 END) as emails_sent,
                SUM(CASE WHEN email_sent=0 AND email_failure_reason IS NOT NULL THEN 1 ELSE 0 END) as smtp_failures
            FROM {$table}
            WHERE DATE(submitted_at) = CURDATE()"
        );

        return $stats;
    }

    /**
     * Get weekly statistics
     */
    private function get_weekly_stats()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';

        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN action IN ('drop', 'soft_penalty', 'hard_penalty') THEN 1 ELSE 0 END) as dropped
            FROM {$table}
            WHERE submitted_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        return $stats;
    }

    /**
     * Build daily digest email
     */
    private function build_daily_digest_email($stats)
    {
        $drop_rate = $stats->total > 0 ? ($stats->dropped / $stats->total) * 100 : 0;
        $delivery_rate = $stats->total > 0 ? ($stats->emails_sent / $stats->total) * 100 : 0;

        return sprintf(
            "Silent Trust - Daily Digest\n" .
            "Date: %s\n\n" .
            "=== Summary ===\n" .
            "Total Submissions: %d\n" .
            "Allowed: %d\n" .
            "Dropped: %d (%.1f%%)\n" .
            "Emails Sent: %d (%.1f%% delivery rate)\n" .
            "SMTP Failures: %d\n\n" .
            "View detailed reports: %s\n",
            date('Y-m-d'),
            $stats->total,
            $stats->allowed,
            $stats->dropped,
            $drop_rate,
            $stats->emails_sent,
            $delivery_rate,
            $stats->smtp_failures,
            admin_url('admin.php?page=silent-trust')
        );
    }

    /**
     * Build weekly report email
     */
    private function build_weekly_report_email($stats)
    {
        $drop_rate = $stats->total > 0 ? ($stats->dropped / $stats->total) * 100 : 0;

        return sprintf(
            "Silent Trust - Weekly Report\n" .
            "Week of: %s\n\n" .
            "=== Summary ===\n" .
            "Total Submissions: %d\n" .
            "Dropped: %d (%.1f%%)\n\n" .
            "View detailed reports: %s\n",
            date('Y-m-d'),
            $stats->total,
            $stats->dropped,
            $drop_rate,
            admin_url('admin.php?page=silent-trust')
        );
    }
}
