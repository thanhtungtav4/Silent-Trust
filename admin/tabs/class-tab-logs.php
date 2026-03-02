<?php
namespace SilentTrust\Admin\Tabs;

if (!defined('ABSPATH'))
    exit;

use SilentTrust\Database;

/**
 * Logs Tab - Submission logs with bulk actions
 * Extracted from Admin_Page::render_logs_tab() + handle_bulk_action()
 */
class Tab_Logs
{
    public static function render()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'st_submissions';

        // Handle bulk actions
        if (isset($_POST['bulk_action']) && isset($_POST['submission_ids'])) {
            check_admin_referer('st_bulk_action');
            self::handle_bulk_action($_POST['bulk_action'], $_POST['submission_ids']);
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
                        if (action === '-1') { alert('⚠️ Please select an action from the dropdown.'); return false; }
                        if (checked === 0) { alert('⚠️ Please select at least one item.'); return false; }
                        let message = '';
                        switch (action) {
                            case 'ban':
                                message = `🚫 Ban ${checked} device(s)?\n\n• Device penalty: 30 days (hard)\n• IP penalty: 7 days (hard)\n• Users won't be able to submit forms\n\nThis action cannot be easily undone.`;
                                break;
                            case 'delete':
                                message = `🗑️ Permanently delete ${checked} log(s)?\n\nThis action cannot be undone.`;
                                break;
                            case 'whitelist':
                                message = `✓ Whitelist ${checked} device(s)?\n\nThese devices will bypass all spam checks.`;
                                break;
                        }
                        return confirm(message);
                    }

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
    private static function handle_bulk_action($action, $ids)
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
                } else {
                    echo '<div class="notice notice-warning"><p>⚠️ No valid devices found to whitelist.</p></div>';
                }
                break;

            case 'ban':
                $submissions = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, fingerprint_hash, ip_address, country_code FROM {$wpdb->prefix}st_submissions 
                     WHERE id IN ($placeholders)",
                    ...$ids
                ));

                foreach ($submissions as $sub) {
                    $fp_result = $db->add_device_penalty($sub->fingerprint_hash, 'Manual ban via bulk action', 30, 'hard');
                    $ip_result = $db->add_ip_penalty($sub->ip_address, 'Manual ban via bulk action', 7, 'hard');
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
                echo '</ul></div>';
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
                break;
        }
    }
}
