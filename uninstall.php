<?php
/**
 * Silent Trust Uninstall
 *
 * Fired when the plugin is deleted (not just deactivated).
 * Cleans up all database tables, options, cron jobs, and transients.
 */

// Abort if not being uninstalled by WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// 1. Drop custom database tables
$tables = [
    $wpdb->prefix . 'st_submissions',
    $wpdb->prefix . 'st_penalties',
    $wpdb->prefix . 'st_whitelist',
    $wpdb->prefix . 'st_anomalies',
    $wpdb->prefix . 'st_analysis_queue',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// 2. Delete all plugin options
$options_to_delete = [
    'silent_trust_traffic_mode',
    'silent_trust_smtp_health_check',
    'silent_trust_whitelist_threshold',
    'silent_trust_daily_limit',
    'silent_trust_alert_emails',
    'silent_trust_vpn_asn_list',
    'silent_trust_vpn_whitelist_ips',
    'st_maxmind_license_key',
    'st_ml_weights',
    'st_force_sync_mode',
    'st_honeypot_enabled',
    'st_trusted_proxy_header',
    'st_debug_mode',
    'st_db_version',
];

foreach ($options_to_delete as $option) {
    delete_option($option);
}

// 3. Clear all scheduled cron jobs
$cron_hooks = [
    'silent_trust_daily_digest',
    'silent_trust_check_stuck_mail',
    'silent_trust_weekly_report',
    'st_cleanup_old_logs',
    'st_process_queued_analysis',
];

foreach ($cron_hooks as $hook) {
    wp_clear_scheduled_hook($hook);
}

// 4. Clean up transients (pattern: st_decision_*)
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_st_decision_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_st_decision_%'");

// 5. Remove GeoIP database file if exists
$geoip_path = WP_CONTENT_DIR . '/uploads/silent-trust/';
if (is_dir($geoip_path)) {
    $files = glob($geoip_path . '*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($geoip_path);
}
