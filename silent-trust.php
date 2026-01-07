<?php
/**
 * Plugin Name: Silent Trust
 * Plugin URI: https://nttung.dev
 * Description: Silent anti-spam protection for Contact Form 7 using behavior analysis, fingerprinting, and correlation without disrupting UX
 * Version: 1.0.0
 * Author: Thanh Tùng
 * Author URI: https://nttung.dev
 * Text Domain: silent-trust
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Plugin constants
define('SILENT_TRUST_VERSION', '1.0.0');
define('SILENT_TRUST_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SILENT_TRUST_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoload classes
spl_autoload_register(function ($class) {
    $prefix = 'SilentTrust\\';
    $base_dir = SILENT_TRUST_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-' . strtolower(str_replace('_', '-', $relative_class)) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Activation hook
register_activation_hook(__FILE__, function () {
    require_once SILENT_TRUST_PLUGIN_DIR . 'includes/class-database.php';
    $database = new SilentTrust\Database();
    $database->create_tables();

    // Run migrations for new features
    $database->migrate_add_url_tracking_columns();

    // Set default options
    add_option('silent_trust_traffic_mode', 'auto');
    add_option('silent_trust_smtp_health_check', true);
    add_option('silent_trust_whitelist_threshold', 3);
    add_option('silent_trust_alert_emails', get_option('admin_email'));

    // Download GeoIP database if license key provided
    if (!empty(get_option('st_maxmind_license_key'))) {
        require_once SILENT_TRUST_PLUGIN_DIR . 'includes/class-geoip-bundled.php';
        $geoip = new SilentTrust\GeoIP_Bundled();
        $geoip->download_database();
        $geoip->schedule_updates();
    }

    // Schedule cron jobs
    if (!wp_next_scheduled('silent_trust_daily_digest')) {
        wp_schedule_event(strtotime('tomorrow 9:00'), 'daily', 'silent_trust_daily_digest');
    }

    if (!wp_next_scheduled('silent_trust_check_stuck_mail')) {
        wp_schedule_event(time(), 'st_every_10_seconds', 'silent_trust_check_stuck_mail');
    }

    if (!wp_next_scheduled('silent_trust_weekly_report')) {
        wp_schedule_event(strtotime('next Sunday'), 'weekly', 'silent_trust_weekly_report');
    }
});

// Deactivation hook
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('silent_trust_daily_digest');
    wp_clear_scheduled_hook('silent_trust_check_stuck_mail');
    wp_clear_scheduled_hook('silent_trust_weekly_report');
});

// Add custom cron schedule
add_filter('cron_schedules', function ($schedules) {
    $schedules['st_every_10_seconds'] = [
        'interval' => 10,
        'display' => __('Every 10 Seconds', 'silent-trust')
    ];
    return $schedules;
});

// Register async analysis cron action
add_action('st_process_async_analysis', function ($queue_id) {
    $async = new \SilentTrust\Async_Processor();
    $async->process_queued_item($queue_id);
});

// Register queue cleanup cron action
add_action('st_cleanup_async_queue', function () {
    $async = new \SilentTrust\Async_Processor();
    $async->cleanup_old_queue();
});

// Initialize plugin
add_action('plugins_loaded', function () {
    // Check if Contact Form 7 is active
    if (!class_exists('WPCF7')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>';
            echo __('Silent Trust requires Contact Form 7 to be installed and active.', 'silent-trust');
            echo '</p></div>';
        });
        return;
    }

    // Load plugin components
    require_once SILENT_TRUST_PLUGIN_DIR . 'includes/class-database.php';
    require_once SILENT_TRUST_PLUGIN_DIR . 'includes/class-geoip.php';
    require_once SILENT_TRUST_PLUGIN_DIR . 'includes/class-geoip-bundled.php';
    require_once SILENT_TRUST_PLUGIN_DIR . 'includes/class-analytics-helper.php';
    require_once SILENT_TRUST_PLUGIN_DIR . 'includes/class-vpn-detector.php';
    require_once SILENT_TRUST_PLUGIN_DIR . 'includes/class-payload-validator.php';
    require_once SILENT_TRUST_PLUGIN_DIR . 'includes/class-risk-engine.php';
    require_once SILENT_TRUST_PLUGIN_DIR . 'includes/class-decision-engine.php';
    require_once SILENT_TRUST_PLUGIN_DIR . 'includes/class-cf7-integration.php';
    require_once SILENT_TRUST_PLUGIN_DIR . 'includes/class-assets.php';
    require_once SILENT_TRUST_PLUGIN_DIR . 'includes/class-alert-system.php';
    require_once SILENT_TRUST_PLUGIN_DIR . 'includes/class-ml-weight-adjuster.php';
    require_once SILENT_TRUST_PLUGIN_DIR . 'includes/class-admin-ajax.php';
    require_once SILENT_TRUST_PLUGIN_DIR . 'includes/class-async-processor.php';

    // Initialize components
    new SilentTrust\Assets();
    new SilentTrust\CF7_Integration();
    new SilentTrust\Alert_System();

    // Initialize analytics session tracking on frontend (cache-safe)
    if (!is_admin() && !wp_doing_ajax()) {
        add_action('init', ['\SilentTrust\Analytics_Helper', 'init_session_tracking'], 1);
    }

    // Load admin if in admin area
    if (is_admin()) {
        require_once SILENT_TRUST_PLUGIN_DIR . 'admin/class-admin-page.php';
        require_once SILENT_TRUST_PLUGIN_DIR . 'includes/class-admin-ajax.php';
        new SilentTrust\Admin_Page();
        new SilentTrust\Admin_AJAX();
    }
});
