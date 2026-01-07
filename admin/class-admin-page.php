<?php
namespace SilentTrust;

if (!defined('ABSPATH'))
    exit;

class Admin_Page
{

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_menu_page(
            'Silent Trust',
            'Silent Trust',
            'manage_options',
            'silent-trust',
            [$this, 'render_dashboard'],
            'dashicons-shield-alt',
            30
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook)
    {
        if ($hook !== 'toplevel_page_silent-trust') {
            return;
        }

        wp_enqueue_style(
            'silent-trust-admin',
            SILENT_TRUST_PLUGIN_URL . 'admin/assets/css/admin.css',
            [],
            SILENT_TRUST_VERSION
        );

        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );

        wp_enqueue_script(
            'silent-trust-admin-js',
            SILENT_TRUST_PLUGIN_URL . 'admin/assets/js/admin.js',
            ['jquery', 'chart-js'],
            SILENT_TRUST_VERSION,
            true
        );

        wp_localize_script('silent-trust-admin-js', 'stAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('st_admin_nonce')
        ]);
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard()
    {
        $active_tab = $_GET['tab'] ?? 'dashboard';

        ?>
        <div class="wrap">
            <h1>Silent Trust - Anti-Spam Protection</h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=silent-trust&tab=dashboard"
                    class="nav-tab <?php echo $active_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">Dashboard</a>
                <a href="?page=silent-trust&tab=ip-inspector"
                    class="nav-tab <?php echo $active_tab === 'ip-inspector' ? 'nav-tab-active' : ''; ?>">IP Inspector</a>
                <a href="?page=silent-trust&tab=settings"
                    class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
                <a href="?page=silent-trust&tab=logs"
                    class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">Logs</a>
                <a href="?page=silent-trust&tab=formdata"
                    class="nav-tab <?php echo $active_tab === 'formdata' ? 'nav-tab-active' : ''; ?>">Form Data</a>
            </nav>

            <div class="st-tab-content">
                <?php
                switch ($active_tab) {
                    case 'ip-inspector':
                        $this->render_ip_inspector_tab();
                        break;
                    case 'settings':
                        $this->render_settings_tab();
                        break;
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                    case 'formdata':
                        $this->render_formdata_tab();
                        break;
                    default:
                        $this->render_dashboard_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render dashboard tab
     */
    private function render_dashboard_tab()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';

        // Get date range from request (default: last 30 days)
        $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
        $date_from = date('Y-m-d 00:00:00', strtotime("-{$days} days"));

        // Stats Queries
        $total_submissions = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE submitted_at >= %s",
            $date_from
        ));

        $blocked_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE submitted_at >= %s AND action IN ('HARD_PENALTY', 'DROP')",
            $date_from
        ));

        $avg_risk_score = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(risk_score) FROM {$table} WHERE submitted_at >= %s",
            $date_from
        ));

        $today_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE DATE(submitted_at) = CURDATE()"
        );

        // Timeline data (daily aggregation)
        $timeline_data = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(submitted_at) as date, COUNT(*) as count 
            FROM {$table} 
            WHERE submitted_at >= %s 
            GROUP BY DATE(submitted_at) 
            ORDER BY date ASC",
            $date_from
        ));

        // Action distribution
        $action_data = $wpdb->get_results($wpdb->prepare(
            "SELECT action, COUNT(*) as count 
            FROM {$table} 
            WHERE submitted_at >= %s 
            GROUP BY action",
            $date_from
        ));

        // Risk score distribution
        $risk_data = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                CASE 
                    WHEN risk_score < 30 THEN 'Low (0-29)'
                    WHEN risk_score < 70 THEN 'Medium (30-69)'
                    ELSE 'High (70-100)'
                END as risk_level,
                COUNT(*) as count
            FROM {$table}
            WHERE submitted_at >= %s
            GROUP BY risk_level
            ORDER BY MIN(risk_score)",
            $date_from
        ));

        // Top countries
        $country_data = $wpdb->get_results($wpdb->prepare(
            "SELECT country_code, COUNT(*) as count 
            FROM {$table} 
            WHERE submitted_at >= %s AND country_code IS NOT NULL
            GROUP BY country_code 
            ORDER BY count DESC 
            LIMIT 10",
            $date_from
        ));

        $blocked_rate = $total_submissions > 0 ? round(($blocked_count / $total_submissions) * 100, 1) : 0;
        $avg_risk = $avg_risk_score ? round($avg_risk_score, 1) : 0;

        ?>
        <div class="st-analytics-dashboard">
            <!-- Date Range Filter -->
            <div class="st-analytics-header">
                <h2>📊 Analytics Dashboard</h2>
                <div class="st-date-filter">
                    <label>Period:</label>
                    <select id="st-date-range"
                        onchange="window.location.href='?page=silent-trust&tab=dashboard&days='+this.value">
                        <option value="7" <?php selected($days, 7); ?>>Last 7 Days</option>
                        <option value="30" <?php selected($days, 30); ?>>Last 30 Days</option>
                        <option value="90" <?php selected($days, 90); ?>>Last 90 Days</option>
                    </select>
                    <button class="button" onclick="exportAnalyticsCSV()">💾 Export CSV</button>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="st-stats-grid">
                <div class="st-stat-card">
                    <div class="st-stat-icon">📝</div>
                    <div class="st-stat-content">
                        <div class="st-stat-label">Total Submissions</div>
                        <div class="st-stat-value">
                            <?php echo number_format($total_submissions); ?>
                        </div>
                        <div class="st-stat-meta">
                            <?php echo $days; ?> days
                        </div>
                    </div>
                </div>

                <div class="st-stat-card st-stat-blocked">
                    <div class="st-stat-icon">🚫</div>
                    <div class="st-stat-content">
                        <div class="st-stat-label">Blocked Rate</div>
                        <div class="st-stat-value">
                            <?php echo $blocked_rate; ?>%
                        </div>
                        <div class="st-stat-meta">
                            <?php echo number_format($blocked_count); ?> blocked
                        </div>
                    </div>
                </div>

                <div class="st-stat-card st-stat-risk">
                    <div class="st-stat-icon">⚠️</div>
                    <div class="st-stat-content">
                        <div class="st-stat-label">Avg Risk Score</div>
                        <div class="st-stat-value">
                            <?php echo $avg_risk; ?>
                        </div>
                        <div class="st-stat-meta">
                            <?php
                            echo $avg_risk < 30 ? 'Low risk' : ($avg_risk < 70 ? 'Medium risk' : 'High risk');
                            ?>
                        </div>
                    </div>
                </div>

                <div class="st-stat-card st-stat-today">
                    <div class="st-stat-icon">🕐</div>
                    <div class="st-stat-content">
                        <div class="st-stat-label">Today's Activity</div>
                        <div class="st-stat-value">
                            <?php echo number_format($today_count); ?>
                        </div>
                        <div class="st-stat-meta">
                            <?php echo date('M j, Y'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Grid -->
            <div class="st-charts-grid">
                <div class="st-chart-card">
                    <h3>📈 Submissions Timeline</h3>
                    <canvas id="st-timeline-chart"></canvas>
                </div>

                <div class="st-chart-card">
                    <h3>🎯 Action Distribution</h3>
                    <canvas id="st-action-chart"></canvas>
                </div>

                <div class="st-chart-card">
                    <h3>⚠️ Risk Score Distribution</h3>
                    <canvas id="st-risk-chart"></canvas>
                </div>

                <div class="st-chart-card">
                    <h3>🌍 Top Countries</h3>
                    <canvas id="st-country-chart"></canvas>
                </div>
            </div>
        </div>

        <!-- Chart.js -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <script>
            jQuery(document).ready(function ($) {
                // Timeline Chart Data
                const timelineData = <?php echo json_encode($timeline_data ?: []); ?>;
                const timelineLabels = timelineData.map(d => d.date);
                const timelineCounts = timelineData.map(d => parseInt(d.count));

                new Chart(document.getElementById('st-timeline-chart'), {
                    type: 'line',
                    data: {
                        labels: timelineLabels,
                        datasets: [{
                            label: 'Submissions',
                            data: timelineCounts,
                            borderColor: '#2271b1',
                            backgroundColor: 'rgba(34, 113, 177, 0.1)',
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: { beginAtZero: true, ticks: { precision: 0 } }
                        }
                    }
                });

                // Action Distribution
                const actionData = <?php echo json_encode($action_data ?: []); ?>;
                const actionLabels = actionData.map(d => d.action);
                const actionCounts = actionData.map(d => parseInt(d.count));
                const actionColors = actionLabels.map(action => {
                    const actionLower = action.toLowerCase();
                    // Blue for allow_log (monitored/logged)
                    if (actionLower.includes('allow_log')) return '#2196f3';
                    // Green for allow/pass
                    if (actionLower.includes('allow')) return '#4caf50';
                    // Orange for challenge/soft_penalty
                    if (actionLower.includes('challenge') || actionLower.includes('soft')) return '#ff9800';
                    // Red for hard_penalty/drop/block
                    return '#f44336';
                });

                new Chart(document.getElementById('st-action-chart'), {
                    type: 'doughnut',
                    data: {
                        labels: actionLabels,
                        datasets: [{
                            data: actionCounts,
                            backgroundColor: actionColors
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom' }
                        }
                    }
                });

                // Risk Score Distribution
                const riskData = <?php echo json_encode($risk_data ?: []); ?>;
                const riskLabels = riskData.map(d => d.risk_level);
                const riskCounts = riskData.map(d => parseInt(d.count));

                new Chart(document.getElementById('st-risk-chart'), {
                    type: 'bar',
                    data: {
                        labels: riskLabels,
                        datasets: [{
                            label: 'Count',
                            data: riskCounts,
                            backgroundColor: ['#4caf50', '#ff9800', '#f44336']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: { beginAtZero: true, ticks: { precision: 0 } }
                        }
                    }
                });

                // Top Countries
                const countryData = <?php echo json_encode($country_data ?: []); ?>;
                const countryLabels = countryData.map(d => d.country_code || 'Unknown');
                const countryCounts = countryData.map(d => parseInt(d.count));

                new Chart(document.getElementById('st-country-chart'), {
                    type: 'bar',
                    data: {
                        labels: countryLabels,
                        datasets: [{
                            label: 'Submissions',
                            data: countryCounts,
                            backgroundColor: '#3f51b5'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        indexAxis: 'y',
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            x: { beginAtZero: true, ticks: { precision: 0 } }
                        }
                    }
                });
            });

            // CSV Export Function
            function exportAnalyticsCSV() {
                window.location.href = '<?php echo admin_url('admin-ajax.php'); ?>?action=st_export_csv&days=<?php echo $days; ?>&_wpnonce=<?php echo wp_create_nonce('st_export_csv'); ?>';
            }
        </script>

        <style>
            .st-analytics-dashboard {
                padding: 20px 0;
            }

            .st-analytics-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 24px;
            }

            .st-analytics-header h2 {
                margin: 0;
            }

            .st-date-filter {
                display: flex;
                gap: 10px;
                align-items: center;
            }

            .st-date-filter select {
                padding: 6px 12px;
            }

            .st-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin-bottom: 30px;
            }

            .st-stat-card {
                background: white;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 20px;
                display: flex;
                gap: 16px;
                transition: all 0.2s;
            }

            .st-stat-card:hover {
                border-color: #2271b1;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            }

            .st-stat-icon {
                font-size: 36px;
                line-height: 1;
            }

            .st-stat-content {
                flex: 1;
            }

            .st-stat-label {
                font-size: 13px;
                color: #666;
                margin-bottom: 8px;
            }

            .st-stat-value {
                font-size: 32px;
                font-weight: 700;
                color: #1d2327;
                line-height: 1;
                margin-bottom: 4px;
            }

            .st-stat-meta {
                font-size: 12px;
                color: #999;
            }

            .st-charts-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
                gap: 20px;
            }

            .st-chart-card {
                background: white;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 20px;
            }

            .st-chart-card h3 {
                margin: 0 0 16px 0;
                font-size: 16px;
                color: #1d2327;
            }

            .st-chart-card canvas {
                max-height: 300px;
            }

            @media (max-width: 1200px) {
                .st-charts-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <?php
    }

    /**
     * Render settings tab
     */
    private function render_settings_tab()
    {
        if (isset($_POST['st_save_settings'])) {
            check_admin_referer('st_settings');

            update_option('silent_trust_traffic_mode', sanitize_text_field($_POST['traffic_mode'] ?? 'auto'));
            update_option('silent_trust_smtp_health_check', !empty($_POST['smtp_health_check']));
            update_option('silent_trust_whitelist_threshold', (int) ($_POST['whitelist_threshold'] ?? 3));
            update_option('silent_trust_daily_limit', (int) ($_POST['daily_limit'] ?? 3));
            update_option('silent_trust_alert_emails', sanitize_textarea_field($_POST['alert_emails'] ?? ''));
            update_option('st_maxmind_license_key', sanitize_text_field($_POST['maxmind_license_key'] ?? ''));
            update_option('st_force_sync_mode', !empty($_POST['force_sync_mode']));

            echo '<div class="notice notice-success"><p>Settings saved successfully.</p></div>';
        }

        $traffic_mode = get_option('silent_trust_traffic_mode', 'auto');
        $smtp_check = get_option('silent_trust_smtp_health_check', true);
        $whitelist_threshold = get_option('silent_trust_whitelist_threshold', 3);
        $daily_limit = get_option('silent_trust_daily_limit', 3);
        $alert_emails = get_option('silent_trust_alert_emails', get_option('admin_email'));
        $maxmind_key = get_option('st_maxmind_license_key', '');
        $force_sync = get_option('st_force_sync_mode', false);

        // Get GeoIP status
        $geoip = new \SilentTrust\GeoIP_Bundled();
        $geoip_status = $geoip->get_status();

        ?>
        <div class="st-settings">
            <form method="post">
                <?php wp_nonce_field('st_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th>Traffic Mode</th>
                        <td>
                            <select name="traffic_mode">
                                <option value="auto" <?php selected($traffic_mode, 'auto'); ?>>Auto-detect</option>
                                <option value="lenient" <?php selected($traffic_mode, 'lenient'); ?>>Lenient (&lt;20/day)
                                </option>
                                <option value="normal" <?php selected($traffic_mode, 'normal'); ?>>Normal (20-100/day)</option>
                                <option value="strict" <?php selected($traffic_mode, 'strict'); ?>>Strict (&gt;100/day)
                                </option>
                            </select>
                            <p class="description">Controls how aggressively the plugin blocks submissions</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Daily Submission Limit</th>
                        <td>
                            <input type="number" name="daily_limit" value="<?php echo esc_attr($daily_limit); ?>" min="1"
                                max="20">
                            <p class="description">Max submissions per day from same device/IP before flagging as potential
                                manual spam (recommended: 3)</p>
                        </td>
                    </tr>

                    <tr>
                        <th>SMTP Health Check</th>
                        <td>
                            <label>
                                <input type="checkbox" name="smtp_health_check" value="1" <?php checked($smtp_check); ?>>
                                Send daily test email at 9 AM
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th>Whitelist Threshold</th>
                        <td>
                            <input type="number" name="whitelist_threshold"
                                value="<?php echo esc_attr($whitelist_threshold); ?>" min="1" max="10">
                            <p class="description">Number of successful submissions before auto-whitelisting</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Alert Email Recipients</th>
                        <td>
                            <textarea name="alert_emails" rows="3"
                                class="large-text"><?php echo esc_textarea($alert_emails); ?></textarea>
                            <p class="description">Comma-separated email addresses for alerts</p>
                        </td>
                    </tr>

                    <tr>
                        <th>MaxMind License Key</th>
                        <td>
                            <input type="text" name="maxmind_license_key" value="<?php echo esc_attr($maxmind_key); ?>"
                                class="regular-text" placeholder="Your MaxMind license key">
                            <p class="description">
                                Required for bundled GeoIP. <a href="https://www.maxmind.com/en/geolite2/signup"
                                    target="_blank">Get free key</a>
                            </p>

                            <?php if (!empty($geoip_status)): ?>
                                <div
                                    style="margin-top:8px;padding:8px;background:#f0f0f1;border-left:3px solid <?php echo $geoip_status['database_exists'] ? '#46b450' : '#dc3232'; ?>; font-size:13px">
                                    <?php if ($geoip_status['database_exists']): ?>
                                        ✅ DB:
                                        <?php echo $geoip_status['database_size']; ?>, Age:
                                        <?php echo $geoip_status['database_age_days']; ?> days
                                    <?php else: ?>
                                        ❌ No DB.
                                        <?php if ($geoip_status['license_key_set']): ?>
                                            <button type="button" class="button button-small" id="st-download-geoip"
                                                style="margin-left:8px">Download Now (70MB)</button>
                                            <span id="st-download-status" style="margin-left:8px"></span>
                                        <?php else: ?>
                                            Save license key first, then click Download.
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <h2>ML Weight Optimization</h2>
                <table class="form-table">
                    <tr>
                        <th>Training Status</th>
                        <td>
                            <?php
                            $ml = new \SilentTrust\ML_Weight_Adjuster();
                            $ml_info = $ml->get_training_info();
                            $can_train = $ml->can_train();

                            if ($ml_info['trained']): ?>
                                ✅ Model trained on
                                <?php echo date('Y-m-d H:i', strtotime($ml_info['trained_at'])); ?>
                                <br><small>Using learned weights</small>
                            <?php else: ?>
                                ⚪ Default weights
                                <br><small>
                                    <?php echo $can_train ? 'Ready to optimize' : 'Need 100+ submissions'; ?>
                                </small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Current Weights</th>
                        <td>
                            <?php
                            $weights = $ml->get_current_weights();
                            echo "FP:{$weights['fingerprint']}% | BH:{$weights['behavior']}% | IP:{$weights['ip']}% | FR:{$weights['frequency']}%";
                            ?>
                            <p class="description">Risk factor contribution percentages</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Actions</th>
                        <td>
                            <button type="button" class="button button-secondary" id="st-optimize-weights" <?php echo !$can_train ? 'disabled' : ''; ?>>
                                Optimize Weights Now
                            </button>
                            <button type="button" class="button button-link" id="st-reset-weights" <?php echo !$ml_info['trained'] ? 'disabled' : ''; ?>>
                                Reset to Defaults
                            </button>
                            <span id="st-optimize-status" style="margin-left:12px"></span>
                            <p class="description">Analyzes 500 submissions to optimize weights</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="st_save_settings" class="button button-primary" value="Save Settings">
                </p>
            </form>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                $('#st-download-geoip').on('click', function () {
                    var $btn = $(this);
                    var $status = $('#st-download-status');

                    $btn.prop('disabled', true).text('Downloading...');
                    $status.html('<span style="color:#666">This may take 1-2 minutes for 70MB file...</span>');

                    $.post(ajaxurl, {
                        action: 'st_download_geoip_db',
                        nonce: '<?php echo wp_create_nonce('st_download_geoip'); ?>'
                    })
                        .done(function (response) {
                            if (response.success) {
                                $status.html('<span style="color:#46b450">✅ ' + response.data.message + '</span>');
                                setTimeout(function () { location.reload(); }, 1500);
                            } else {
                                $status.html('<span style="color:#dc3232">❌ ' + response.data.message + '</span>');
                                $btn.prop('disabled', false).text('Retry Download');
                            }
                        })
                        .fail(function () {
                            $status.html('<span style="color:#dc3232">❌ Network error. Please try again.</span>');
                            $btn.prop('disabled', false).text('Retry Download');
                        });
                });

                // ML Optimize
                $('#st-optimize-weights').on('click', function () {
                    var $btn = $(this), $status = $('#st-optimize-status');
                    $btn.prop('disabled', true).text('Optimizing...');
                    $status.html('<span style="color:#666">⏳ Analyzing...</span>');

                    $.post(ajaxurl, {
                        action: 'st_optimize_weights',
                        nonce: '<?php echo wp_create_nonce('st_optimize_weights'); ?>'
                    }).done(function (r) {
                        if (r.success) {
                            $status.html('<span style="color:#46b450">✅ ' + r.data.message + '</span>');
                            setTimeout(function () { location.reload(); }, 1500);
                        } else {
                            $status.html('<span style="color:#dc3232">❌ ' + r.data.message + '</span>');
                            $btn.prop('disabled', false).text('Optimize Weights Now');
                        }
                    });
                });

                // ML Reset
                $('#st-reset-weights').on('click', function () {
                    if (!confirm('Reset to defaults?')) return;
                    var $status = $('#st-optimize-status');
                    $status.html('<span style="color:#666">⏳ Resetting...</span>');

                    $.post(ajaxurl, {
                        action: 'st_reset_weights',
                        nonce: '<?php echo wp_create_nonce('st_reset_weights'); ?>'
                    }).done(function () {
                        $status.html('<span style="color:#46b450">✅ Reset</span>');
                        setTimeout(function () { location.reload(); }, 1000);
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * Render logs tab
     */
    private function render_logs_tab()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';

        //Handle bulk actions
        if (isset($_POST['bulk_action']) && isset($_POST['submission_ids'])) {
            check_admin_referer('st_bulk_action');
            $this->handle_bulk_action($_POST['bulk_action'], $_POST['submission_ids']);
        }

        $logs = $wpdb->get_results(
            "SELECT * FROM {$table} 
            ORDER BY submitted_at DESC 
            LIMIT 100"
        );

        ?>
        <div class="st-logs">
            <form method="post">
                <?php wp_nonce_field('st_bulk_action'); ?>

                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_action" id="st-bulk-action">
                            <option value="-1">Bulk Actions</option>
                            <option value="whitelist">✓ Whitelist Devices</option>
                            <option value="ban">🚫 Ban (30d Device + 7d IP)</option>
                            <option value="delete">🗑️ Delete Logs</option>
                        </select>
                        <input type="submit" class="button action" value="Apply" onclick="return confirmBulkAction()">
                    </div>
                    <div class="alignright">
                        <span class="displaying-num">
                            <?php echo count($logs); ?> items
                        </span>
                    </div>
                </div>

                <script>
                    function confirmBulkAction() {
                        const action = document.getElementById('st-bulk-action').value;
                        const checked = document.querySelectorAll('input[name="submission_ids[]"]:checked').length;

                        if (action === '-1') {
                            alert('⚠️ Please select an action from the dropdown.');
                            return false;
                        }

                        if (checked === 0) {
                            alert('⚠️ Please select at least one item.');
                            return false;
                        }

                        let message = '';
                        switch (action) {
                            case 'ban':
                                message = `🚫 Ban ${checked} device(s)?\n\n` +
                                    `• Device penalty: 30 days (hard)\n` +
                                    `• IP penalty: 7 days (hard)\n` +
                                    `• Users won't be able to submit forms\n\n` +
                                    `This action cannot be easily undone.`;
                                break;
                            case 'delete':
                                message = `🗑️ Permanently delete ${checked} log(s)?\n\nThis action cannot be undone.`;
                                break;
                            case 'whitelist':
                                message = `✓ Whitelist ${checked} device(s)?\n\n` +
                                    `These devices will bypass all spam checks.`;
                                break;
                        }

                        return confirm(message);
                    }

                    // Select all checkbox
                    document.getElementById('cb-select-all')?.addEventListener('change', function (e) {
                        document.querySelectorAll('input[name="submission_ids[]"]').forEach(cb => {
                            cb.checked = e.target.checked;
                        });
                    });
                </script>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="check-column"><input type="checkbox" id="cb-select-all"></td>
                            <th>Time</th>
                            <th>IP Address</th>
                            <th>Country</th>
                            <th>Email</th>
                            <th>Device</th>
                            <th>Risk Score</th>
                            <th>Action</th>
                            <th>Email Sent</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr class="st-submission-row" data-submission-id="<?php echo esc_attr($log->id); ?>" data-details="<?php echo esc_attr(json_encode([
                                   'time' => $log->submitted_at,
                                   'ip' => $log->ip_address,
                                   'country' => ($log->ip_country_name ?? $log->country_code) . ' (' . ($log->ip_region ?? 'Unknown') . ')',
                                   'location' => !empty($log->ip_latitude) ? $log->ip_latitude . ', ' . $log->ip_longitude : null,
                                   'timezone' => $log->ip_timezone,
                                   'asn' => $log->asn,
                                   'first_url' => $log->first_url,
                                   'lead_url' => $log->lead_url,
                                   'landing_url' => $log->landing_url,
                                   'page_url' => $log->page_url,
                                   'referrer' => $log->referrer_url,
                                   'utm_source' => $log->utm_source,
                                   'utm_medium' => $log->utm_medium,
                                   'utm_campaign' => $log->utm_campaign,
                                   'utm_term' => $log->utm_term,
                                   'utm_content' => $log->utm_content,
                                   'device' => $log->device_type,
                                   'browser' => ($log->browser_name ?? 'Unknown') . ' ' . ($log->browser_version ?? ''),
                                   'os' => ($log->os_name ?? 'Unknown') . ' ' . ($log->os_version ?? ''),
                                   'screen' => $log->screen_resolution,
                                   'user_agent' => $log->user_agent,
                                   'session_duration' => $log->session_duration ? gmdate('i:s', $log->session_duration) : null,
                                   'pages_visited' => $log->pages_visited,
                                   'visit_count' => $log->visit_count,
                                   'time_on_page' => $log->time_on_page,
                                   'form_start_time' => $log->form_start_time,
                                   'form_complete_time' => $log->form_complete_time,
                                   'fingerprint_hash' => $log->fingerprint_hash,
                                   'device_cookie' => $log->device_cookie,
                                   'risk_score' => $log->risk_score,
                                   'risk_breakdown' => $log->risk_breakdown,
                                   'action' => $log->action,
                                   'email_sent' => $log->email_sent,
                                   'sent_via' => $log->sent_via,
                                   'submission_data' => $log->submission_data
                               ])); ?>">
                                <th class="check-column">
                                    <input type="checkbox" name="submission_ids[]" value="<?php echo esc_attr($log->id); ?>">
                                </th>
                                <td>
                                    <?php echo esc_html($log->submitted_at); ?>
                                </td>
                                <td>
                                    <?php echo esc_html($log->ip_address); ?>
                                </td>
                                <td>
                                    <?php echo esc_html($log->country_code ?? '-'); ?>
                                </td>
                                <td>
                                    <?php
                                    // Extract email from submission_data if available
                                    $email = '-';
                                    if (!empty($log->submission_data)) {
                                        $data = maybe_unserialize($log->submission_data);
                                        if (is_array($data)) {
                                            foreach ($data as $key => $value) {
                                                if (stripos($key, 'email') !== false || filter_var($value, FILTER_VALIDATE_EMAIL)) {
                                                    $email = $value;
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                    if ($email !== '-' && strlen($email) > 25) {
                                        echo '<span title="' . esc_attr($email) . '">' . esc_html(substr($email, 0, 25)) . '...</span>';
                                    } else {
                                        echo esc_html($email);
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php echo esc_html($log->device_type); ?>
                                </td>
                                <td><span
                                        class="st-risk-badge st-risk-<?php echo $log->risk_score >= 70 ? 'high' : ($log->risk_score >= 30 ? 'medium' : 'low'); ?>">
                                        <?php echo esc_html($log->risk_score); ?>
                                    </span></td>
                                <td>
                                    <?php echo esc_html($log->action); ?>
                                </td>
                                <td>
                                    <?php echo $log->email_sent ? '✓' : '✗'; ?>
                                </td>
                                <td><button class="button button-small st-explain-btn"
                                        data-id="<?php echo esc_attr($log->id); ?>">Why?</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        </div>

        <!-- Explainability Modal -->
        <div id="st-explain-modal" style="display:none;">
            <div class="st-modal-content">
                <span class="st-modal-close">&times;</span>
                <h2>Risk Analysis Breakdown</h2>
                <div id="st-explain-content"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle bulk actions with detailed feedback
     */
    private function handle_bulk_action($action, $ids)
    {
        if (empty($ids)) {
            echo '<div class="notice notice-error"><p>⚠️ No items selected.</p></div>';
            return;
        }

        global $wpdb;
        $db = new Database();
        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $success_count = 0;
        $error_count = 0;
        $details = [];

        switch ($action) {
            case 'whitelist':
                // Add device cookies to whitelist
                $submissions = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, device_cookie, ip_address FROM {$wpdb->prefix}st_submissions 
                     WHERE id IN ($placeholders) AND device_cookie IS NOT NULL",
                    ...$ids
                ));

                foreach ($submissions as $sub) {
                    $result = $db->update_whitelist($sub->device_cookie);
                    if ($result) {
                        $success_count++;
                        $details[] = "✓ Device " . substr($sub->device_cookie, 0, 8) . "... whitelisted";
                    } else {
                        $error_count++;
                        $details[] = "✗ Failed to whitelist " . substr($sub->device_cookie, 0, 8) . "...";
                    }
                }

                if ($success_count > 0) {
                    echo '<div class="notice notice-success">';
                    echo '<p><strong>✅ Whitelist Success</strong></p>';
                    echo '<ul style="margin-left: 20px;">';
                    echo '<li>' . $success_count . ' devices whitelisted</li>';
                    echo '<li>These devices will bypass spam checks</li>';
                    if ($error_count > 0) {
                        echo '<li style="color: #d63638;">⚠️ ' . $error_count . ' items had no device cookie</li>';
                    }
                    echo '</ul></div>';

                    // Add JS to highlight affected rows
                    echo '<script>
                        [' . implode(',', $ids) . '].forEach(id => {
                            const row = document.querySelector(`input[value="${id}"]`)?.closest("tr");
                            if (row) {
                                row.style.backgroundColor = "#d4edda";
                                row.style.borderLeft = "4px solid #28a745";
                                setTimeout(() => {
                                    row.style.backgroundColor = "";
                                    row.style.borderLeft = "";
                                }, 3000);
                            }
                        });
                    </script>';
                } else {
                    echo '<div class="notice notice-warning"><p>⚠️ No valid devices found to whitelist (selected items may lack device cookies).</p></div>';
                }
                break;

            case 'ban':
                // Add to penalty list
                $submissions = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, fingerprint_hash, ip_address, country_code FROM {$wpdb->prefix}st_submissions 
                     WHERE id IN ($placeholders)",
                    ...$ids
                ));

                foreach ($submissions as $sub) {
                    // Add fingerprint penalty
                    $fp_result = $db->add_device_penalty(
                        $sub->fingerprint_hash,
                        'Manual ban via bulk action',
                        30, // 30 days
                        'hard'
                    );

                    // Add IP penalty
                    $ip_result = $db->add_ip_penalty(
                        $sub->ip_address,
                        'Manual ban via bulk action',
                        7, // 7 days
                        'hard'
                    );

                    if ($fp_result && $ip_result) {
                        $success_count++;
                        $details[] = "✓ Banned IP " . $sub->ip_address . " (" . ($sub->country_code ?? 'Unknown') . ")";
                    } else {
                        $error_count++;
                    }
                }

                echo '<div class="notice notice-success">';
                echo '<p><strong>🚫 Ban Applied</strong></p>';
                echo '<ul style="margin-left: 20px;">';
                echo '<li>' . $success_count . ' devices + IPs banned</li>';
                echo '<li>Device penalty: <strong>30 days (hard)</strong></li>';
                echo '<li>IP penalty: <strong>7 days (hard)</strong></li>';
                echo '<li>These users cannot submit forms during ban period</li>';
                echo '</ul>';
                if (count($details) <= 10) {
                    echo '<details><summary>View Details</summary><ul style="margin-left: 20px; font-size: 11px;">';
                    foreach ($details as $detail) {
                        echo '<li>' . esc_html($detail) . '</li>';
                    }
                    echo '</ul></details>';
                }
                echo '</div>';

                // Highlight rows in red
                echo '<script>
                    [' . implode(',', $ids) . '].forEach(id => {
                        const row = document.querySelector(`input[value="${id}"]`)?.closest("tr");
                        if (row) {
                            row.style.backgroundColor = "#f8d7da";
                            row.style.borderLeft = "4px solid #dc3545";
                            setTimeout(() => {
                                row.style.backgroundColor = "";
                                row.style.borderLeft = "";
                            }, 5000);
                        }
                    });
                </script>';
                break;

            case 'delete':
                $deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}st_submissions WHERE id IN ($placeholders)",
                    ...$ids
                ));

                echo '<div class="notice notice-success">';
                echo '<p><strong>🗑️ Logs Deleted</strong></p>';
                echo '<p>' . $deleted . ' submission log(s) permanently removed from database.</p>';
                echo '</div>';

                // Fade out deleted rows
                echo '<script>
                    [' . implode(',', $ids) . '].forEach(id => {
                        const row = document.querySelector(`input[value="${id}"]`)?.closest("tr");
                        if (row) {
                            row.style.transition = "opacity 0.5s";
                            row.style.opacity = "0.3";
                            setTimeout(() => row.remove(), 500);
                        }
                    });
                </script>';
                break;
        }
    }

    /**
     * Render Form Data tab - Display submitted form data
     */
    private function render_formdata_tab()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';

        // Get page parameter
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 50;
        $offset = ($paged - 1) * $per_page;

        // Get total count
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE submission_data IS NOT NULL AND submission_data != ''");
        $total_pages = ceil($total / $per_page);

        // Get submissions with form data
        $submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT id, submitted_at, ip_address, country_code, submission_data, action, 
                    first_url, lead_url, landing_url
            FROM {$table} 
            WHERE submission_data IS NOT NULL AND submission_data != ''
            ORDER BY submitted_at DESC 
            LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));

        ?>
        <div class="st-formdata">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div>
                    <h2 style="margin: 0;">📝 Form Submissions Data</h2>
                    <p style="margin: 5px 0 0 0; color: #666;">Displaying form data from
                        <strong>
                            <?php echo number_format($total); ?>
                        </strong> submissions
                    </p>
                </div>
            </div>

            <?php if (!empty($submissions)): ?>
                <table class="wp-list-table widefat fixed striped st-formdata-table">
                    <thead>
                        <tr>
                            <th style="width: 8%;">Time</th>
                            <th style="width: 8%;">IP / Country</th>
                            <th style="width: 6%;">Action</th>
                            <th style="width: 15%;">🔗 URL Tracking</th>
                            <th>📝 Form Fields</th>
                            <th style="width: 80px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $submission): ?>
                            <?php
                            $form_data = maybe_unserialize($submission->submission_data);
                            if (!is_array($form_data)) {
                                continue;
                            }
                            // Filter out technical fields
                            $filtered_data = array_filter($form_data, function ($key) {
                                return !in_array($key, ['st_fingerprint', 'st_behavior', '_wpcf7', '_wpcf7_version', '_wpcf7_locale', '_wpcf7_unit_tag', '_wpcf7_container_post']);
                            }, ARRAY_FILTER_USE_KEY);

                            if (empty($filtered_data)) {
                                continue;
                            }
                            ?>
                            <tr>
                                <td>
                                    <div style="font-size: 11px; color: #666;">
                                        <?php echo esc_html(date('Y-m-d', strtotime($submission->submitted_at))); ?><br>
                                        <strong>
                                            <?php echo esc_html(date('H:i:s', strtotime($submission->submitted_at))); ?>
                                        </strong>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-size: 11px;">
                                        <code><?php echo esc_html($submission->ip_address); ?></code><br>
                                        <span>
                                            <?php echo esc_html($submission->country_code ?? '-'); ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <span class="st-action-badge st-action-<?php echo esc_attr($submission->action); ?>">
                                        <?php echo esc_html($submission->action); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="st-url-tracking-compact">
                                        <?php if ($submission->first_url || $submission->lead_url || $submission->landing_url): ?>
                                            <div class="st-url-row">
                                                <?php if ($submission->first_url): ?>
                                                    <?php
                                                    $first_path = parse_url($submission->first_url, PHP_URL_PATH) ?: '/';
                                                    $first_display = strlen($first_path) > 20 ? substr($first_path, 0, 20) . '...' : $first_path;
                                                    ?>
                                                    <span class="st-url-tag st-url-first"
                                                        title="First: <?php echo esc_attr($submission->first_url); ?>">
                                                        F:
                                                        <?php echo esc_html($first_display); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($submission->lead_url): ?>
                                                    <?php
                                                    $lead_path = parse_url($submission->lead_url, PHP_URL_PATH) ?: '/';
                                                    $lead_display = strlen($lead_path) > 20 ? substr($lead_path, 0, 20) . '...' : $lead_path;
                                                    ?>
                                                    <span class="st-url-tag st-url-lead"
                                                        title="Lead: <?php echo esc_attr($submission->lead_url); ?>">
                                                        L:
                                                        <?php echo esc_html($lead_display); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($submission->landing_url): ?>
                                                    <?php
                                                    $landing_path = parse_url($submission->landing_url, PHP_URL_PATH) ?: '/';
                                                    $landing_display = strlen($landing_path) > 20 ? substr($landing_path, 0, 20) . '...' : $landing_path;
                                                    ?>
                                                    <span class="st-url-tag st-url-landing"
                                                        title="Landing: <?php echo esc_attr($submission->landing_url); ?>">
                                                        Ld:
                                                        <?php echo esc_html($landing_display); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="empty">-</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="st-form-data-display">
                                        <?php foreach ($filtered_data as $field => $value): ?>
                                            <?php if (!empty($value)): ?>
                                                <div class="st-form-field">
                                                    <strong>
                                                        <?php echo esc_html($field); ?>:
                                                    </strong>
                                                    <span>
                                                        <?php echo esc_html(is_array($value) ? implode(', ', $value) : $value); ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <button class="button button-small st-view-detail"
                                        data-submission-id="<?php echo esc_attr($submission->id); ?>" data-details="<?php echo esc_attr(json_encode([
                                               'time' => $submission->submitted_at,
                                               'ip' => $submission->ip_address,
                                               'country' => $submission->country_code,
                                               'action' => $submission->action,
                                               'first_url' => $submission->first_url,
                                               'lead_url' => $submission->lead_url,
                                               'landing_url' => $submission->landing_url,
                                               'form_data' => $filtered_data
                                           ])); ?>">
                                        👁️ View
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php echo number_format($total); ?> items
                            </span>
                            <span class="pagination-links">
                                <?php
                                $base_url = add_query_arg(['page' => 'silent-trust', 'tab' => 'formdata']);

                                if ($paged > 1):
                                    echo '<a class="first-page button" href="' . esc_url(add_query_arg('paged', 1, $base_url)) . '">«</a> ';
                                    echo '<a class="prev-page button" href="' . esc_url(add_query_arg('paged', $paged - 1, $base_url)) . '">‹</a> ';
                                endif;

                                echo '<span class="paging-input">';
                                echo '<span class="tablenav-paging-text">' . $paged . ' of ' . $total_pages . '</span>';
                                echo '</span> ';

                                if ($paged < $total_pages):
                                    echo '<a class="next-page button" href="' . esc_url(add_query_arg('paged', $paged + 1, $base_url)) . '">›</a> ';
                                    echo '<a class="last-page button" href="' . esc_url(add_query_arg('paged', $total_pages, $base_url)) . '">»</a>';
                                endif;
                                ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="notice notice-info inline">
                    <p>No form submissions found. Submissions will appear here once forms are submitted.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Detail Modal -->
        <div id="st-detail-modal" class="st-modal" style="display:none;">
            <div class="st-modal-backdrop"></div>
            <div class="st-modal-content-wrapper">
                <div class="st-modal-content">
                    <div class="st-modal-header">
                        <h2>📋 Submission Details</h2>
                        <button class="st-modal-close" aria-label="Close">&times;</button>
                    </div>
                    <div class="st-modal-body" id="st-detail-content">
                        <!-- Content loaded via JS -->
                    </div>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                // View Detail button
                $('.st-view-detail').on('click', function () {
                    const details = JSON.parse($(this).attr('data-details'));
                    let html = '<div class="st-detail-sections">';

                    // Time & Action
                    html += '<div class="st-detail-section">';
                    html += '<h3>⏱️ Submission Info</h3>';
                    html += '<div class="st-detail-grid">';
                    html += '<div class="st-detail-item"><strong>Time:</strong> ' + details.time + '</div>';
                    html += '<div class="st-detail-item"><strong>Action:</strong> <span class="st-action-badge st-action-' + details.action + '">' + details.action + '</span></div>';
                    html += '</div></div>';

                    // IP & Location
                    html += '<div class="st-detail-section">';
                    html += '<h3>🌍 Location</h3>';
                    html += '<div class="st-detail-grid">';
                    html += '<div class="st-detail-item"><strong>IP Address:</strong> <code>' + details.ip + '</code></div>';
                    html += '<div class="st-detail-item"><strong>Country:</strong> ' + (details.country || '-') + '</div>';
                    html += '</div></div>';

                    // URLs
                    if (details.first_url || details.lead_url || details.landing_url) {
                        html += '<div class="st-detail-section">';
                        html += '<h3>🔗 URL Tracking</h3>';
                        html += '<div class="st-detail-list">';
                        if (details.first_url) {
                            html += '<div class="st-detail-item"><strong>First URL:</strong> <a href="' + details.first_url + '" target="_blank">' + details.first_url + '</a></div>';
                        }
                        if (details.lead_url) {
                            html += '<div class="st-detail-item"><strong>Lead URL:</strong> <a href="' + details.lead_url + '" target="_blank">' + details.lead_url + '</a></div>';
                        }
                        if (details.landing_url) {
                            html += '<div class="st-detail-item"><strong>Landing URL:</strong> <a href="' + details.landing_url + '" target="_blank">' + details.landing_url + '</a></div>';
                        }
                        html += '</div></div>';
                    }

                    // Form Data
                    if (details.form_data && Object.keys(details.form_data).length > 0) {
                        html += '<div class="st-detail-section">';
                        html += '<h3>📝 Form Fields</h3>';
                        html += '<div class="st-detail-list">';
                        for (let [key, value] of Object.entries(details.form_data)) {
                            html += '<div class="st-detail-item"><strong>' + key + ':</strong> ' + value + '</div>';
                        }
                        html += '</div></div>';
                    }

                    html += '</div>';

                    $('#st-detail-content').html(html);
                    $('#st-detail-modal').fadeIn(200);
                });

                // Close modal
                $('.st-modal-close, .st-modal-backdrop').on('click', function () {
                    $('#st-detail-modal').fadeOut(200);
                });

                // ESC key to close
                $(document).on('keydown', function (e) {
                    if (e.key === 'Escape') {
                        $('#st-detail-modal').fadeOut(200);
                    }
                });
            });
        </script>

        <style>
            .st-formdata {
                margin-top: 20px;
            }

            .st-formdata-table th {
                background: #f8f9fa;
                font-weight: 600;
                text-transform: uppercase;
                font-size: 11px;
                letter-spacing: 0.5px;
            }

            .st-formdata-table tbody tr:hover {
                background: #f9fafb;
            }

            .st-formdata-table tbody td {
                vertical-align: middle;
                padding: 8px 6px;
            }

            /* Compact URL Tracking - Horizontal Layout */
            .st-url-tracking-compact {
                font-size: 11px;
                max-width: 180px;
            }

            .st-url-row {
                display: flex;
                gap: 4px;
                flex-wrap: wrap;
            }

            .st-url-tag {
                display: inline-block;
                padding: 2px 6px;
                border-radius: 3px;
                font-family: monospace;
                font-size: 9px;
                font-weight: 600;
                cursor: help;
                white-space: nowrap;
                max-width: 100px;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .st-url-first {
                background: #e3f2fd;
                color: #1565c0;
                border: 1px solid #bbdefb;
            }

            .st-url-lead {
                background: #f3e5f5;
                color: #6a1b9a;
                border: 1px solid #e1bee7;
            }

            .st-url-landing {
                background: #fff3e0;
                color: #e65100;
                border: 1px solid #ffe0b2;
            }

            /* Form Fields - More Compact */
            .st-form-data-display {
                display: flex;
                flex-wrap: wrap;
                gap: 4px;
                padding: 2px 0;
                max-width: 500px;
            }

            .st-form-field {
                background: linear-gradient(135deg, #f6f7f7 0%, #e9ecef 100%);
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 10px;
                line-height: 1.3;
                border: 1px solid #e0e0e0;
                transition: all 0.2s;
                max-width: 250px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .st-form-field:hover {
                background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
                border-color: #c0c0c0;
                white-space: normal;
                max-width: none;
                z-index: 10;
                position: relative;
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            }

            .st-form-field strong {
                color: #135e96;
                margin-right: 3px;
                font-weight: 600;
            }

            .st-form-field span {
                color: #1d2327;
            }

            .st-action-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 9px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }

            .st-action-allow {
                background: linear-gradient(135deg, #d1e7dd 0%, #c3e6cb 100%);
                color: #0f5132;
                border: 1px solid #b8dcc4;
            }

            .st-action-challenge {
                background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
                color: #856404;
                border: 1px solid #f9e79f;
            }

            .st-action-drop,
            .st-action-soft_penalty,
            .st-action-hard_penalty {
                background: linear-gradient(135deg, #f8d7da 0%, #f5c2c7 100%);
                color: #842029;
                border: 1px solid #f1aeb5;
            }

            .empty {
                color: #999;
                font-style: italic;
            }

            /* Modal Styles */
            .st-modal {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 100000;
            }

            .st-modal-backdrop {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.7);
            }

            .st-modal-content-wrapper {
                position: relative;
                height: 100%;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .st-modal-content {
                background: white;
                border-radius: 8px;
                max-width: 800px;
                width: 100%;
                max-height: 85vh;
                display: flex;
                flex-direction: column;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            }

            .st-modal-header {
                padding: 20px 24px;
                border-bottom: 1px solid #e0e0e0;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .st-modal-header h2 {
                margin: 0;
                font-size: 20px;
            }

            .st-modal-close {
                background: none;
                border: none;
                font-size: 28px;
                line-height: 1;
                cursor: pointer;
                color: #666;
                padding: 0;
                width: 32px;
                height: 32px;
                border-radius: 4px;
                transition: all 0.2s;
            }

            .st-modal-close:hover {
                background: #f0f0f0;
                color: #333;
            }

            .st-modal-body {
                padding: 24px;
                overflow-y: auto;
            }

            .st-detail-sections {
                display: flex;
                flex-direction: column;
                gap: 20px;
            }

            .st-detail-section {
                background: #f9fafb;
                padding: 16px;
                border-radius: 6px;
                border: 1px solid #e0e0e0;
            }

            .st-detail-section h3 {
                margin: 0 0 12px 0;
                font-size: 14px;
                font-weight: 600;
                color: #1d2327;
            }

            .st-detail-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 12px;
            }

            .st-detail-list {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            .st-detail-item {
                font-size: 13px;
                line-height: 1.5;
            }

            .st-detail-item strong {
                color: #135e96;
                margin-right: 6px;
            }

            .st-detail-item code {
                background: #fff;
                padding: 2px 6px;
                border-radius: 3px;
                font-family: monospace;
                font-size: 12px;
            }

            .st-detail-item a {
                color: #2271b1;
                text-decoration: none;
                word-break: break-all;
            }

            .st-detail-item a:hover {
                text-decoration: underline;
            }
        </style>
        <?php
    }

    /**
     * Render IP Inspector tab
     */
    private function render_ip_inspector_tab()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';

        // Get IP from query string
        $search_ip = isset($_GET['ip']) ? sanitize_text_field($_GET['ip']) : '';

        $ip_data = null;
        if ($search_ip && filter_var($search_ip, FILTER_VALIDATE_IP)) {
            // Get IP statistics
            $ip_data = [
                'ip' => $search_ip,
                'total' => $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE ip_address = %s",
                    $search_ip
                )),
                'devices' => $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT fingerprint_hash) FROM {$table} WHERE ip_address = %s",
                    $search_ip
                )),
                'blocked' => $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE ip_address = %s AND action IN ('HARD_PENALTY', 'DROP')",
                    $search_ip
                )),
                'avg_risk' => $wpdb->get_var($wpdb->prepare(
                    "SELECT AVG(risk_score) FROM {$table} WHERE ip_address = %s",
                    $search_ip
                ))
            ];

            // Device breakdown (limit to top 10)
            $ip_data['device_breakdown'] = $wpdb->get_results($wpdb->prepare(
                "SELECT device_type, COUNT(*) as count FROM {$table} 
                WHERE ip_address = %s 
                GROUP BY device_type
                ORDER BY count DESC
                LIMIT 10",
                $search_ip
            ));

            // Timeline (last 30 days only for performance)
            $ip_data['timeline'] = $wpdb->get_results($wpdb->prepare(
                "SELECT DATE(submitted_at) as date, action, COUNT(*) as count 
                FROM {$table} 
                WHERE ip_address = %s AND submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY DATE(submitted_at), action 
                ORDER BY date ASC
                LIMIT 100",
                $search_ip
            ));

            // All submissions (limit to last 50)
            $ip_data['submissions'] = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE ip_address = %s ORDER BY submitted_at DESC LIMIT 50",
                $search_ip
            ));
        }
        ?>
        <div class="st-ip-inspector">
            <div class="st-inspector-header">
                <h2>🔍 IP Inspector</h2>
                <form method="get" class="st-ip-search">
                    <input type="hidden" name="page" value="silent-trust">
                    <input type="hidden" name="tab" value="ip-inspector">
                    <input type="text" name="ip" value="<?php echo esc_attr($search_ip); ?>"
                        placeholder="Enter IP address (e.g., 192.168.1.1)" class="regular-text">
                    <button type="submit" class="button button-primary">🔍 Analyze</button>
                </form>
            </div>

            <?php if ($ip_data && $ip_data['total'] > 0): ?>
                <!-- Stats Cards -->
                <div class="st-ip-stats-grid">
                    <div class="st-ip-stat-card">
                        <div class="st-stat-icon">📝</div>
                        <div class="st-stat-content">
                            <div class="st-stat-label">Total Submissions</div>
                            <div class="st-stat-value">
                                <?php echo number_format($ip_data['total']); ?>
                            </div>
                        </div>
                    </div>

                    <div class="st-ip-stat-card">
                        <div class="st-stat-icon">📱</div>
                        <div class="st-stat-content">
                            <div class="st-stat-label">Unique Devices</div>
                            <div class="st-stat-value">
                                <?php echo $ip_data['devices']; ?>
                            </div>
                            <div class="st-stat-meta">fingerprints</div>
                        </div>
                    </div>

                    <div class="st-ip-stat-card st-stat-blocked">
                        <div class="st-stat-icon">🚫</div>
                        <div class="st-stat-content">
                            <div class="st-stat-label">Blocked</div>
                            <div class="st-stat-value">
                                <?php echo $ip_data['blocked']; ?>
                            </div>
                            <div class="st-stat-meta">
                                <?php echo round(($ip_data['blocked'] / $ip_data['total']) * 100, 1); ?>% blocked
                            </div>
                        </div>
                    </div>

                    <div class="st-ip-stat-card st-stat-risk">
                        <div class="st-stat-icon">⚠️</div>
                        <div class="st-stat-content">
                            <div class="st-stat-label">Avg Risk Score</div>
                            <div class="st-stat-value">
                                <?php echo round($ip_data['avg_risk'], 1); ?>
                            </div>
                            <div class="st-stat-meta">
                                <?php echo $ip_data['avg_risk'] < 30 ? 'Low' : ($ip_data['avg_risk'] < 70 ? 'Medium' : 'High'); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts -->
                <div class="st-ip-charts-grid">
                    <div class="st-chart-card">
                        <h3>📱 Device Breakdown</h3>
                        <canvas id="st-device-chart"></canvas>
                    </div>

                    <div class="st-chart-card">
                        <h3>📈 Activity Timeline</h3>
                        <canvas id="st-timeline-chart"></canvas>
                    </div>
                </div>

                <!-- Submissions Table -->
                <div class="st-submissions-table">
                    <h3>📋 Recent Submissions</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Device</th>
                                <th>Action</th>
                                <th>Risk</th>
                                <th>Country</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ip_data['submissions'] as $sub): ?>
                                <tr>
                                    <td>
                                        <?php echo esc_html($sub->submitted_at); ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html($sub->device_type ?? 'Unknown'); ?>
                                    </td>
                                    <td><span class="st-badge st-badge-<?php echo strtolower($sub->action); ?>">
                                            <?php echo esc_html($sub->action); ?>
                                        </span></td>
                                    <td>
                                        <?php echo esc_html($sub->risk_score); ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html($sub->country_code ?? '-'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Quick Actions -->
                <div class="st-quick-actions">
                    <h3>⚡ Quick Actions</h3>
                    <button class="button button-large" onclick="banIP('<?php echo esc_js($search_ip); ?>')">🚫 Ban IP
                        Permanently</button>
                    <button class="button button-large" onclick="tempBan('<?php echo esc_js($search_ip); ?>', 24)">⏰ Ban 24
                        Hours</button>
                    <button class="button button-large" onclick="whitelistIP('<?php echo esc_js($search_ip); ?>')">✅ Whitelist
                        IP</button>
                </div>

                <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
                <script>
                    // Device Chart
                    const deviceData = <?php echo json_encode($ip_data['device_breakdown']); ?>;
                    new Chart(document.getElementById('st-device-chart'), {
                        type: 'doughnut',
                        data: {
                            labels: deviceData.map(d => d.device_type || 'Unknown'),
                            datasets: [{
                                data: deviceData.map(d => parseInt(d.count)),
                                backgroundColor: ['#2196f3', '#4caf50', '#ff9800', '#f44336', '#9c27b0']
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { position: 'bottom' } }
                        }
                    });

                    // Timeline Chart
                    const timelineData = <?php echo json_encode($ip_data['timeline']); ?>;
                    const dates = [...new Set(timelineData.map(d => d.date))];
                    const datasets = {};
                    timelineData.forEach(item => {
                        if (!datasets[item.action]) {
                            datasets[item.action] = new Array(dates.length).fill(0);
                        }
                        const idx = dates.indexOf(item.date);
                        datasets[item.action][idx] = parseInt(item.count);
                    });

                    new Chart(document.getElementById('st-timeline-chart'), {
                        type: 'line',
                        data: {
                            labels: dates,
                            datasets: Object.keys(datasets).map(action => ({
                                label: action,
                                data: datasets[action],
                                borderColor: action.includes('PENALTY') || action === 'DROP' ? '#f44336' : '#4caf50',
                                tension: 0.4
                            }))
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                        }
                    });

                    function banIP(ip) {
                        if (confirm('Ban IP ' + ip + ' permanently?')) {
                            // TODO: Implement ban
                            alert('Ban functionality coming soon!');
                        }
                    }
                    function tempBan(ip, hours) {
                        if (confirm('Temporarily ban ' + ip + ' for ' + hours + ' hours?')) {
                            alert('Temp ban functionality coming soon!');
                        }
                    }
                    function whitelistIP(ip) {
                        if (confirm('Whitelist IP ' + ip + '?')) {
                            alert('Whitelist functionality coming soon!');
                        }
                    }
                </script>

                <style>
                    .st-ip-inspector {
                        padding: 20px 0;
                        max-width: 1400px;
                    }

                    .st-inspector-header {
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        margin-bottom: 30px;
                        background: white;
                        padding: 20px;
                        border-radius: 8px;
                        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
                    }

                    .st-inspector-header h2 {
                        margin: 0;
                        font-size: 22px;
                    }

                    .st-ip-search {
                        display: flex;
                        gap: 12px;
                        align-items: center;
                    }

                    .st-ip-search input[type="text"] {
                        min-width: 280px;
                        padding: 8px 12px;
                        border: 1px solid #ddd;
                        border-radius: 4px;
                    }

                    .st-ip-stats-grid {
                        display: grid;
                        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
                        gap: 20px;
                        margin-bottom: 30px;
                    }

                    .st-ip-stat-card {
                        background: white;
                        border: 1px solid #e5e7eb;
                        border-radius: 8px;
                        padding: 20px;
                        display: flex;
                        gap: 16px;
                        align-items: center;
                        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
                        transition: box-shadow 0.2s;
                    }

                    .st-ip-stat-card:hover {
                        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                    }

                    .st-stat-icon {
                        font-size: 32px;
                        width: 50px;
                        height: 50px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        background: #f3f4f6;
                        border-radius: 8px;
                    }

                    .st-stat-content {
                        flex: 1;
                    }

                    .st-stat-label {
                        font-size: 12px;
                        color: #6b7280;
                        font-weight: 500;
                        text-transform: uppercase;
                        margin-bottom: 4px;
                    }

                    .st-stat-value {
                        font-size: 28px;
                        font-weight: 700;
                        color: #1f2937;
                        line-height: 1.2;
                    }

                    .st-stat-meta {
                        font-size: 13px;
                        color: #9ca3af;
                        margin-top: 4px;
                    }

                    .st-ip-charts-grid {
                        display: grid;
                        grid-template-columns: 1fr 1fr;
                        gap: 24px;
                        margin-bottom: 30px;
                    }

                    .st-chart-card {
                        background: white;
                        border: 1px solid #e5e7eb;
                        border-radius: 8px;
                        padding: 24px;
                        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
                    }

                    .st-chart-card h3 {
                        margin: 0 0 20px 0;
                        font-size: 16px;
                        font-weight: 600;
                        color: #374151;
                    }

                    .st-chart-card canvas {
                        max-height: 280px !important;
                    }

                    .st-submissions-table {
                        background: white;
                        padding: 24px;
                        border-radius: 8px;
                        margin-bottom: 24px;
                        border: 1px solid #e5e7eb;
                        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
                    }

                    .st-submissions-table h3 {
                        margin: 0 0 16px 0;
                        font-size: 16px;
                        font-weight: 600;
                    }

                    .st-submissions-table table {
                        margin-top: 0;
                    }

                    .st-quick-actions {
                        background: white;
                        padding: 24px;
                        border-radius: 8px;
                        display: flex;
                        gap: 12px;
                        align-items: center;
                        flex-wrap: wrap;
                        border: 1px solid #e5e7eb;
                        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
                    }

                    .st-quick-actions h3 {
                        margin: 0;
                        width: 100%;
                        font-size: 16px;
                        font-weight: 600;
                        margin-bottom: 12px;
                    }

                    .st-quick-actions .button {
                        margin: 0;
                    }

                    .st-badge {
                        padding: 4px 10px;
                        border-radius: 12px;
                        font-size: 11px;
                        font-weight: 600;
                        text-transform: uppercase;
                        letter-spacing: 0.3px;
                    }

                    .st-badge-allow {
                        background: #d1fae5;
                        color: #065f46;
                    }

                    .st-badge-allow_log {
                        background: #dbeafe;
                        color: #1e40af;
                    }

                    .st-badge-hard_penalty,
                    .st-badge-drop {
                        background: #fee2e2;
                        color: #991b1b;
                    }

                    .st-empty-state {
                        background: white;
                        padding: 60px 20px;
                        text-align: center;
                        border-radius: 8px;
                        border: 2px dashed #e5e7eb;
                    }

                    .st-empty-state p {
                        font-size: 15px;
                        color: #6b7280;
                        margin: 0;
                    }

                    /* Responsive */
                    @media (max-width: 1024px) {
                        .st-ip-charts-grid {
                            grid-template-columns: 1fr;
                        }
                    }

                    @media (max-width: 768px) {
                        .st-inspector-header {
                            flex-direction: column;
                            gap: 16px;
                            align-items: stretch;
                        }

                        .st-ip-search {
                            flex-direction: column;
                        }

                        .st-ip-search input[type="text"] {
                            min-width: 100%;
                        }

                        .st-ip-stats-grid {}
                </style>

            <?php elseif ($search_ip): ?>
                <div class="notice notice-info">
                    <p>No submissions found for IP: <strong>
                            <?php echo esc_html($search_ip); ?>
                        </strong></p>
                </div>
            <?php else: ?>
                <div class="st-empty-state">
                    <p class="description">Enter an IP address above to analyze spammer behavior and device patterns.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
