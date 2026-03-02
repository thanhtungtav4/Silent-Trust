<?php
namespace SilentTrust\Admin\Tabs;

if (!defined('ABSPATH'))
    exit;

/**
 * IP Inspector Tab - IP analysis, device breakdown, quick actions
 * Extracted from Admin_Page::render_ip_inspector_tab()
 */
class Tab_IP_Inspector
{
    public static function render()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';

        // Get IP from query string
        $search_ip = isset($_GET['ip']) ? sanitize_text_field($_GET['ip']) : '';

        $ip_data = null;
        if ($search_ip && filter_var($search_ip, FILTER_VALIDATE_IP)) {
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
                    "SELECT COUNT(*) FROM {$table} WHERE ip_address = %s AND action IN ('hard_penalty', 'drop')",
                    $search_ip
                )),
                'avg_risk' => $wpdb->get_var($wpdb->prepare(
                    "SELECT AVG(risk_score) FROM {$table} WHERE ip_address = %s",
                    $search_ip
                ))
            ];

            $ip_data['device_breakdown'] = $wpdb->get_results($wpdb->prepare(
                "SELECT device_type, COUNT(*) as count FROM {$table} 
                WHERE ip_address = %s GROUP BY device_type ORDER BY count DESC LIMIT 10",
                $search_ip
            ));

            $ip_data['timeline'] = $wpdb->get_results($wpdb->prepare(
                "SELECT DATE(submitted_at) as date, action, COUNT(*) as count 
                FROM {$table} 
                WHERE ip_address = %s AND submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY DATE(submitted_at), action ORDER BY date ASC LIMIT 100",
                $search_ip
            ));

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
                    const deviceData = <?php echo json_encode($ip_data['device_breakdown']); ?>;
                    new Chart(document.getElementById('st-device-chart'), {
                        type: 'doughnut',
                        data: {
                            labels: deviceData.map(d => d.device_type || 'Unknown'),
                            datasets: [{ data: deviceData.map(d => parseInt(d.count)), backgroundColor: ['#2196f3', '#4caf50', '#ff9800', '#f44336', '#9c27b0'] }]
                        },
                        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
                    });

                    const timelineData = <?php echo json_encode($ip_data['timeline']); ?>;
                    const dates = [...new Set(timelineData.map(d => d.date))];
                    const datasets = {};
                    timelineData.forEach(item => {
                        if (!datasets[item.action]) datasets[item.action] = new Array(dates.length).fill(0);
                        datasets[item.action][dates.indexOf(item.date)] = parseInt(item.count);
                    });

                    new Chart(document.getElementById('st-timeline-chart'), {
                        type: 'line',
                        data: {
                            labels: dates,
                            datasets: Object.keys(datasets).map(action => ({
                                label: action,
                                data: datasets[action],
                                borderColor: action.includes('penalty') || action === 'drop' ? '#f44336' : '#4caf50',
                                tension: 0.4
                            }))
                        },
                        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
                    });

                    function banIP(ip) { if (confirm('Ban IP ' + ip + ' permanently?')) alert('Ban functionality coming soon!'); }
                    function tempBan(ip, hours) { if (confirm('Temporarily ban ' + ip + ' for ' + hours + ' hours?')) alert('Temp ban functionality coming soon!'); }
                    function whitelistIP(ip) { if (confirm('Whitelist IP ' + ip + '?')) alert('Whitelist functionality coming soon!'); }
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
                    }
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
