<?php
namespace SilentTrust\Admin\Tabs;

if (!defined('ABSPATH'))
    exit;

/**
 * Form Data Tab - Display submitted form data with pagination
 * Extracted from Admin_Page::render_formdata_tab()
 */
class Tab_FormData
{
    public static function render()
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
                            if (!is_array($form_data))
                                continue;

                            $filtered_data = array_filter($form_data, function ($key) {
                                return !in_array($key, ['st_fingerprint', 'st_behavior', '_wpcf7', '_wpcf7_version', '_wpcf7_locale', '_wpcf7_unit_tag', '_wpcf7_container_post']);
                            }, ARRAY_FILTER_USE_KEY);

                            if (empty($filtered_data))
                                continue;
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
                                                    <?php $first_path = parse_url($submission->first_url, PHP_URL_PATH) ?: '/';
                                                    $first_display = strlen($first_path) > 20 ? substr($first_path, 0, 20) . '...' : $first_path; ?>
                                                    <span class="st-url-tag st-url-first"
                                                        title="First: <?php echo esc_attr($submission->first_url); ?>">
                                                        F:
                                                        <?php echo esc_html($first_display); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($submission->lead_url): ?>
                                                    <?php $lead_path = parse_url($submission->lead_url, PHP_URL_PATH) ?: '/';
                                                    $lead_display = strlen($lead_path) > 20 ? substr($lead_path, 0, 20) . '...' : $lead_path; ?>
                                                    <span class="st-url-tag st-url-lead"
                                                        title="Lead: <?php echo esc_attr($submission->lead_url); ?>">
                                                        L:
                                                        <?php echo esc_html($lead_display); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($submission->landing_url): ?>
                                                    <?php $landing_path = parse_url($submission->landing_url, PHP_URL_PATH) ?: '/';
                                                    $landing_display = strlen($landing_path) > 20 ? substr($landing_path, 0, 20) . '...' : $landing_path; ?>
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
                                echo '<span class="paging-input"><span class="tablenav-paging-text">' . $paged . ' of ' . $total_pages . '</span></span> ';
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
                    <div class="st-modal-body" id="st-detail-content"></div>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                $('.st-view-detail').on('click', function () {
                    const details = JSON.parse($(this).attr('data-details'));
                    let html = '<div class="st-detail-sections">';

                    html += '<div class="st-detail-section"><h3>⏱️ Submission Info</h3><div class="st-detail-grid">';
                    html += '<div class="st-detail-item"><strong>Time:</strong> ' + details.time + '</div>';
                    html += '<div class="st-detail-item"><strong>Action:</strong> <span class="st-action-badge st-action-' + details.action + '">' + details.action + '</span></div>';
                    html += '</div></div>';

                    html += '<div class="st-detail-section"><h3>🌍 Location</h3><div class="st-detail-grid">';
                    html += '<div class="st-detail-item"><strong>IP Address:</strong> <code>' + details.ip + '</code></div>';
                    html += '<div class="st-detail-item"><strong>Country:</strong> ' + (details.country || '-') + '</div>';
                    html += '</div></div>';

                    if (details.first_url || details.lead_url || details.landing_url) {
                        html += '<div class="st-detail-section"><h3>🔗 URL Tracking</h3><div class="st-detail-list">';
                        if (details.first_url) html += '<div class="st-detail-item"><strong>First URL:</strong> <a href="' + details.first_url + '" target="_blank">' + details.first_url + '</a></div>';
                        if (details.lead_url) html += '<div class="st-detail-item"><strong>Lead URL:</strong> <a href="' + details.lead_url + '" target="_blank">' + details.lead_url + '</a></div>';
                        if (details.landing_url) html += '<div class="st-detail-item"><strong>Landing URL:</strong> <a href="' + details.landing_url + '" target="_blank">' + details.landing_url + '</a></div>';
                        html += '</div></div>';
                    }

                    if (details.form_data && Object.keys(details.form_data).length > 0) {
                        html += '<div class="st-detail-section"><h3>📝 Form Fields</h3><div class="st-detail-list">';
                        for (let [key, value] of Object.entries(details.form_data)) {
                            html += '<div class="st-detail-item"><strong>' + key + ':</strong> ' + value + '</div>';
                        }
                        html += '</div></div>';
                    }

                    html += '</div>';
                    $('#st-detail-content').html(html);
                    $('#st-detail-modal').fadeIn(200);
                });

                $('.st-modal-close, .st-modal-backdrop').on('click', function () {
                    $('#st-detail-modal').fadeOut(200);
                });

                $(document).on('keydown', function (e) {
                    if (e.key === 'Escape') $('#st-detail-modal').fadeOut(200);
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

            .st-action-allow_log {
                background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
                color: #1e40af;
                border: 1px solid #93c5fd;
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
}
