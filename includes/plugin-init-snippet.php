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

// Initialize analytics session tracking on frontend
if (!is_admin() && !wp_doing_ajax()) {
add_action('init', ['\SilentTrust\Analytics_Helper', 'init_session_tracking'], 1);
}