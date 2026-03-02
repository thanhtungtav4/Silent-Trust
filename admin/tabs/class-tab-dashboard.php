<?php
namespace SilentTrust\Admin\Tabs;

if (!defined('ABSPATH'))
    exit;

/**
 * Dashboard Tab - Analytics overview with charts
 * Extracted from Admin_Page::render_dashboard_tab()
 */
class Tab_Dashboard
{
    public static function render()
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
            "SELECT COUNT(*) FROM {$table} WHERE submitted_at >= %s AND action IN ('hard_penalty', 'drop')",
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
                            <?php echo esc_html(number_format($total_submissions)); ?>
                        </div>
                        <div class="st-stat-meta">
                            <?php echo esc_html($days); ?> days
                        </div>
                    </div>
                </div>

                <div class="st-stat-card st-stat-blocked">
                    <div class="st-stat-icon">🚫</div>
                    <div class="st-stat-content">
                        <div class="st-stat-label">Blocked Rate</div>
                        <div class="st-stat-value">
                            <?php echo esc_html($blocked_rate); ?>%
                        </div>
                        <div class="st-stat-meta">
                            <?php echo esc_html(number_format($blocked_count)); ?> blocked
                        </div>
                    </div>
                </div>

                <div class="st-stat-card st-stat-risk">
                    <div class="st-stat-icon">⚠️</div>
                    <div class="st-stat-content">
                        <div class="st-stat-label">Avg Risk Score</div>
                        <div class="st-stat-value">
                            <?php echo esc_html($avg_risk); ?>
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
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                    }
                });

                const actionData = <?php echo json_encode($action_data ?: []); ?>;
                const actionLabels = actionData.map(d => d.action);
                const actionCounts = actionData.map(d => parseInt(d.count));
                const actionColors = actionLabels.map(action => {
                    const a = action.toLowerCase();
                    if (a.includes('allow_log')) return '#2196f3';
                    if (a.includes('allow')) return '#4caf50';
                    if (a.includes('challenge') || a.includes('soft')) return '#ff9800';
                    return '#f44336';
                });

                new Chart(document.getElementById('st-action-chart'), {
                    type: 'doughnut',
                    data: { labels: actionLabels, datasets: [{ data: actionCounts, backgroundColor: actionColors }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
                });

                const riskData = <?php echo json_encode($risk_data ?: []); ?>;
                new Chart(document.getElementById('st-risk-chart'), {
                    type: 'bar',
                    data: {
                        labels: riskData.map(d => d.risk_level),
                        datasets: [{ label: 'Count', data: riskData.map(d => parseInt(d.count)), backgroundColor: ['#4caf50', '#ff9800', '#f44336'] }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
                });

                const countryData = <?php echo json_encode($country_data ?: []); ?>;
                new Chart(document.getElementById('st-country-chart'), {
                    type: 'bar',
                    data: {
                        labels: countryData.map(d => d.country_code || 'Unknown'),
                        datasets: [{ label: 'Submissions', data: countryData.map(d => parseInt(d.count)), backgroundColor: '#3f51b5' }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } }
                });
            });

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
}
