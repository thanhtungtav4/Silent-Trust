/* Silent Trust Admin JavaScript */
(function ($) {
    'use strict';

    let charts = {};

    // Initialize dashboard
    function initDashboard() {
        loadDashboardStats();
        loadTrendChart();
        loadActionChart();
        loadRiskChart();
        checkAlerts();
    }

    // Load dashboard statistics
    function loadDashboardStats() {
        $.ajax({
            url: stAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'st_get_dashboard_stats',
                nonce: stAdmin.nonce
            },
            success: function (response) {
                if (response.success) {
                    $('#st-total-today').text(response.data.total);
                    $('#st-drop-rate').text(response.data.drop_rate + '%');
                    $('#st-email-rate').text(response.data.email_rate + '%');

                    // Check for alerts
                    if (response.data.smtp_failures > 0 && response.data.email_rate < 20) {
                        showAlert('Email delivery may be broken. Check SMTP settings.', 'error');
                    }

                    if (response.data.fallback_rate > 30) {
                        showAlert('WP-Cron may be inactive. Consider using server cron.', 'warning');
                    }
                }
            }
        });
    }

    // Load trend chart
    function loadTrendChart() {
        $.ajax({
            url: stAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'st_get_trend_data',
                nonce: stAdmin.nonce
            },
            success: function (response) {
                if (response.success && response.data && response.data.length > 0) {
                    const ctx = document.getElementById('st-trend-chart');
                    if (!ctx) return;

                    const labels = response.data.map(d => d.date);
                    const allowed = response.data.map(d => parseInt(d.allowed) || 0);
                    const dropped = response.data.map(d => parseInt(d.dropped) || 0);

                    charts.trend = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [
                                {
                                    label: 'Allowed',
                                    data: allowed,
                                    borderColor: '#46b450',
                                    backgroundColor: 'rgba(70, 180, 80, 0.1)',
                                    tension: 0.3
                                },
                                {
                                    label: 'Dropped',
                                    data: dropped,
                                    borderColor: '#dc3232',
                                    backgroundColor: 'rgba(220, 50, 50, 0.1)',
                                    tension: 0.3
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'top'
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        precision: 0
                                    }
                                }
                            }
                        }
                    });
                } else {
                    $('#st-trend-chart').parent().html('<p style="text-align:center;padding:40px;color:#666;">No data available yet</p>');
                }
            }
        });
    }

    // Load action distribution chart
    function loadActionChart() {
        $.ajax({
            url: stAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'st_get_action_stats',
                nonce: stAdmin.nonce
            },
            success: function (response) {
                if (response.success && response.data && response.data.length > 0) {
                    const ctx = document.getElementById('st-action-chart');
                    if (!ctx) return;

                    const labels = response.data.map(d => d.action);
                    const counts = response.data.map(d => parseInt(d.count) || 0);

                    charts.action = new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: counts,
                                backgroundColor: [
                                    '#46b450',
                                    '#00a0d2',
                                    '#ffb900',
                                    '#dc3232',
                                    '#9b59b6',
                                    '#e67e22'
                                ]
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false
                        }
                    });
                } else {
                    $('#st-action-chart').parent().html('<p style="text-align:center;padding:40px;color:#666;">No data available yet</p>');
                }
            }
        });
    }

    // Load risk distribution chart
    function loadRiskChart() {
        $.ajax({
            url: stAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'st_get_risk_distribution',
                nonce: stAdmin.nonce
            },
            success: function (response) {
                if (response.success && response.data && response.data.length > 0) {
                    const ctx = document.getElementById('st-risk-chart');
                    if (!ctx) return;

                    const labels = response.data.map(d => d.score_range + '-' + (parseInt(d.score_range) + 9));
                    const counts = response.data.map(d => parseInt(d.count) || 0);

                    charts.risk = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Submissions',
                                data: counts,
                                backgroundColor: '#00a0d2'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        precision: 0
                                    }
                                }
                            }
                        }
                    });
                } else {
                    $('#st-risk-chart').parent().html('<p style="text-align:center;padding:40px;color:#666;">No data available yet</p>');
                }
            }
        });
    }

    // Show alert
    function showAlert(message, type) {
        const alertClass = type === 'error' ? 'notice-error' : 'notice-warning';
        const html = `<div class="notice ${alertClass}"><p>${message}</p></div>`;
        $('#st-alerts').append(html);
    }

    // Check for high drop rate alerts
    function checkAlerts() {
        // This would check drop rate and show alerts
    }

    // Explainability modal
    // Explain button click handler
    $(document).on('click', '.st-explain-btn', function (e) {
        e.preventDefault(); // CRITICAL: Prevent default button action

        const id = $(this).data('id');

        $.ajax({
            url: stAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'st_get_explainability',
                nonce: stAdmin.nonce,
                id: id
            },
            success: function (response) {
                if (response.success) {
                    let html = '<h3>Risk Score: ' + response.data.submission.risk_score + '</h3>';
                    html += '<h4>Contributing Factors:</h4><ul>';

                    for (const [key, value] of Object.entries(response.data.breakdown)) {
                        html += '<li><strong>' + key + '</strong>: +' + value + ' points</li>';
                    }

                    html += '</ul>';

                    $('#st-explain-content').html(html);
                    $('#st-explain-modal').show();
                }
            }
        });
    });

    // Close modal
    $(document).on('click', '.st-modal-close', function (e) {
        e.preventDefault();
        $('#st-explain-modal').hide();
    });

    // Close modal when clicking outside
    $(document).on('click', '#st-explain-modal', function (e) {
        if (e.target === this) {
            $('#st-explain-modal').hide();
        }
    });

    // Select all checkboxes
    $(document).on('change', '#cb-select-all', function () {
        $('input[name="submission_ids[]"]').prop('checked', this.checked);
    });

    // Hover tooltip for submission details
    let $tooltip = null;
    let tooltipTimeout = null;

    function createTooltip() {
        if (!$tooltip) {
            $tooltip = $('<div class="st-tooltip"></div>').appendTo('body');
        }
        return $tooltip;
    }

    function formatValue(value) {
        if (!value || value === 'null' || value === 'Unknown' || value === 'Unknown Unknown') {
            return '<span class="empty">-</span>';
        }
        return value;
    }

    function renderTooltipContent(data) {
        let html = '<h4>📊 Submission Details</h4>';

        // Form Data Section (if available)
        if (data.submission_data) {
            try {
                const formData = typeof data.submission_data === 'string' ? JSON.parse(data.submission_data) : data.submission_data;
                if (formData && Object.keys(formData).length > 0) {
                    html += '<div class="st-tooltip-section">';
                    html += '<strong>📝 Form Data</strong>';
                    let count = 0;
                    for (const [key, value] of Object.entries(formData)) {
                        if (count >= 5) break; // Limit to 5 fields
                        if (value && key !== 'st_fingerprint' && key !== 'st_behavior') {
                            html += '<div class="st-tooltip-item">';
                            html += '<span class="st-tooltip-label">' + key + ':</span>';
                            html += '<span class="st-tooltip-value">' + (value.toString().length > 30 ? value.toString().substring(0, 30) + '...' : value) + '</span>';
                            html += '</div>';
                            count++;
                        }
                    }
                    html += '</div>';
                }
            } catch (e) {
                // Silent fail if JSON parse fails
            }
        }

        // URLs Section
        html += '<div class="st-tooltip-section">';
        html += '<strong>🔗 URL Tracking</strong>';
        if (data.first_url || data.lead_url || data.landing_url) {
            if (data.first_url) {
                html += '<div class="st-tooltip-item">';
                html += '<span class="st-tooltip-label">First URL:</span>';
                html += '<span class="st-tooltip-value">' + formatValue(data.first_url) + '</span>';
                html += '</div>';
            }
            if (data.lead_url) {
                html += '<div class="st-tooltip-item">';
                html += '<span class="st-tooltip-label">Lead URL:</span>';
                html += '<span class="st-tooltip-value">' + formatValue(data.lead_url) + '</span>';
                html += '</div>';
            }
            if (data.landing_url) {
                html += '<div class="st-tooltip-item">';
                html += '<span class="st-tooltip-label">Landing URL:</span>';
                html += '<span class="st-tooltip-value">' + formatValue(data.landing_url) + '</span>';
                html += '</div>';
            }
            if (data.referrer) {
                html += '<div class="st-tooltip-item">';
                html += '<span class="st-tooltip-label">Referrer:</span>';
                html += '<span class="st-tooltip-value">' + formatValue(data.referrer) + '</span>';
                html += '</div>';
            }
        } else {
            html += '<div class="st-tooltip-item"><span class="empty">No URL data</span></div>';
        }
        html += '</div>';

        // UTM Parameters
        if (data.utm_source || data.utm_medium || data.utm_campaign || data.utm_term) {
            html += '<div class="st-tooltip-section">';
            html += '<strong>🎯 Marketing Source</strong>';
            if (data.utm_source) {
                html += '<div class="st-tooltip-item">';
                html += '<span class="st-tooltip-label">Source:</span>';
                html += '<span class="st-tooltip-value">' + formatValue(data.utm_source) + '</span>';
                html += '</div>';
            }
            if (data.utm_medium) {
                html += '<div class="st-tooltip-item">';
                html += '<span class="st-tooltip-label">Medium:</span>';
                html += '<span class="st-tooltip-value">' + formatValue(data.utm_medium) + '</span>';
                html += '</div>';
            }
            if (data.utm_campaign) {
                html += '<div class="st-tooltip-item">';
                html += '<span class="st-tooltip-label">Campaign:</span>';
                html += '<span class="st-tooltip-value">' + formatValue(data.utm_campaign) + '</span>';
                html += '</div>';
            }
            if (data.utm_term) {
                html += '<div class="st-tooltip-item">';
                html += '<span class="st-tooltip-label">Term:</span>';
                html += '<span class="st-tooltip-value">' + formatValue(data.utm_term) + '</span>';
                html += '</div>';
            }
            html += '</div>';
        }

        // Risk & Action
        if (data.risk_score || data.risk_breakdown) {
            html += '<div class="st-tooltip-section">';
            html += '<strong>⚠️ Risk Analysis</strong>';
            html += '<div class="st-tooltip-item">';
            html += '<span class="st-tooltip-label">Risk Score:</span>';
            html += '<span class="st-tooltip-value"><strong>' + (data.risk_score || 0) + '</strong></span>';
            html += '</div>';
            html += '<div class="st-tooltip-item">';
            html += '<span class="st-tooltip-label">Action:</span>';
            html += '<span class="st-tooltip-value">' + formatValue(data.action) + '</span>';
            html += '</div>';
            if (data.email_sent) {
                html += '<div class="st-tooltip-item">';
                html += '<span class="st-tooltip-label">Email Sent:</span>';
                html += '<span class="st-tooltip-value">✓ ' + (data.sent_via || 'direct') + '</span>';
                html += '</div>';
            }
            html += '</div>';
        }

        // Location
        if (data.country || data.location || data.asn) {
            html += '<div class="st-tooltip-section">';
            html += '<strong>🌍 Location & ISP</strong>';
            if (data.country) {
                html += '<div class="st-tooltip-item">';
                html += '<span class="st-tooltip-label">Country/Region:</span>';
                html += '<span class="st-tooltip-value">' + formatValue(data.country) + '</span>';
                html += '</div>';
            }
            if (data.location) {
                html += '<div class="st-tooltip-item">';
                html += '<span class="st-tooltip-label">Coordinates:</span>';
                html += '<span class="st-tooltip-value">' + formatValue(data.location) + '</span>';
                html += '</div>';
            }
            if (data.timezone) {
                html += '<div class="st-tooltip-item">';
                html += '<span class="st-tooltip-label">Timezone:</span>';
                html += '<span class="st-tooltip-value">' + formatValue(data.timezone) + '</span>';
                html += '</div>';
            }
            if (data.asn) {
                html += '<div class="st-tooltip-item">';
                html += '<span class="st-tooltip-label">ISP (ASN):</span>';
                html += '<span class="st-tooltip-value">' + formatValue(data.asn) + '</span>';
                html += '</div>';
            }
            html += '</div>';
        }

        // Device Info
        html += '<div class="st-tooltip-section">';
        html += '<strong>💻 Device</strong>';
        html += '<div class="st-tooltip-item">';
        html += '<span class="st-tooltip-label">Browser:</span>';
        html += '<span class="st-tooltip-value">' + formatValue(data.browser) + '</span>';
        html += '</div>';
        html += '<div class="st-tooltip-item">';
        html += '<span class="st-tooltip-label">OS:</span>';
        html += '<span class="st-tooltip-value">' + formatValue(data.os) + '</span>';
        html += '</div>';
        if (data.screen) {
            html += '<div class="st-tooltip-item">';
            html += '<span class="st-tooltip-label">Screen:</span>';
            html += '<span class="st-tooltip-value">' + formatValue(data.screen) + '</span>';
            html += '</div>';
        }
        if (data.fingerprint_hash) {
            html += '<div class="st-tooltip-item">';
            html += '<span class="st-tooltip-label">Fingerprint:</span>';
            html += '<span class="st-tooltip-value" style="font-family: monospace; font-size: 10px;">' + data.fingerprint_hash.substring(0, 12) + '...</span>';
            html += '</div>';
        }
        html += '</div>';

        // Session & Engagement
        if (data.session_duration || data.pages_visited || data.time_on_page || data.visit_count || (data.form_complete_time && data.form_start_time)) {
            html += '<div class="st-tooltip-section">';
            html += '<strong>⏱️ Session & Engagement</strong>';
            if (data.session_duration) {
                html += '<div class="st-tooltip-item">';
                html += '<span class="st-tooltip-label">Session Duration:</span>';
                html += '<span class="st-tooltip-value">' + formatValue(data.session_duration) + '</span>';
                html += '</div>';
            }
            if (data.pages_visited) {
                html += '<div class="st-tooltip-item">';
                html += '<span class="st-tooltip-label">Pages Visited:</span>';
                html += '<span class="st-tooltip-value">' + formatValue(data.pages_visited) + '</span>';
                html += '</div>';
            }
            if (data.visit_count) {
                html += '<div class="st-tooltip-item">';
                html += '<span class="st-tooltip-label">Total Visits:</span>';
                html += '<span class="st-tooltip-value">' + formatValue(data.visit_count) + '</span>';
                html += '</div>';
            }
            if (data.time_on_page) {
                html += '<div class="st-tooltip-item">';
                html += '<span class="st-tooltip-label">Time on Page:</span>';
                html += '<span class="st-tooltip-value">' + Math.round(data.time_on_page / 1000) + 's</span>';
                html += '</div>';
            }
            if (data.form_complete_time && data.form_start_time) {
                const fillTime = Math.round((data.form_complete_time - data.form_start_time) / 1000);
                html += '<div class="st-tooltip-item">';
                html += '<span class="st-tooltip-label">Form Fill Time:</span>';
                html += '<span class="st-tooltip-value">' + fillTime + 's</span>';
                html += '</div>';
            }
            html += '</div>';
        }

        return html;
    }

    $(document).on('mouseenter', '.st-submission-row', function (e) {
        const $row = $(this);
        const data = JSON.parse($row.attr('data-details') || '{}');

        clearTimeout(tooltipTimeout);
        tooltipTimeout = setTimeout(function () {
            const $tooltip = createTooltip();
            $tooltip.html(renderTooltipContent(data));

            // Position tooltip
            const mouseX = e.pageX;
            const mouseY = e.pageY;
            const tooltipWidth = 450;
            const tooltipHeight = $tooltip.outerHeight();

            let left = mouseX + 15;
            let top = mouseY - tooltipHeight / 2;

            // Prevent overflow
            if (left + tooltipWidth > $(window).width()) {
                left = mouseX - tooltipWidth - 15;
            }
            if (top < $(window).scrollTop()) {
                top = $(window).scrollTop() + 10;
            }
            if (top + tooltipHeight > $(window).scrollTop() + $(window).height()) {
                top = $(window).scrollTop() + $(window).height() - tooltipHeight - 10;
            }

            $tooltip.css({ left: left + 'px', top: top + 'px' }).addClass('show');
        }, 300); // 300ms delay before showing
    });

    $(document).on('mouseleave', '.st-submission-row', function () {
        clearTimeout(tooltipTimeout);
        if ($tooltip) {
            $tooltip.removeClass('show');
        }
    });

    $(document).on('mousemove', '.st-submission-row', function (e) {
        if ($tooltip && $tooltip.hasClass('show')) {
            const mouseX = e.pageX;
            const mouseY = e.pageY;
            const tooltipWidth = $tooltip.outerWidth();
            const tooltipHeight = $tooltip.outerHeight();

            let left = mouseX + 15;
            let top = mouseY - tooltipHeight / 2;

            // Prevent overflow
            if (left + tooltipWidth > $(window).width()) {
                left = mouseX - tooltipWidth - 15;
            }
            if (top < $(window).scrollTop()) {
                top = $(window).scrollTop() + 10;
            }
            if (top + tooltipHeight > $(window).scrollTop() + $(window).height()) {
                top = $(window).scrollTop() + $(window).height() - tooltipHeight - 10;
            }

            $tooltip.css({ left: left + 'px', top: top + 'px' });
        }
    });

    // Initialize on document ready
    $(document).ready(function () {
        if ($('.st-dashboard').length) {
            initDashboard();
        }
    });

})(jQuery);
