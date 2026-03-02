<?php
namespace SilentTrust\Admin\Tabs;

if (!defined('ABSPATH'))
    exit;

/**
 * Settings Tab - Plugin configuration
 * Extracted from Admin_Page::render_settings_tab()
 */
class Tab_Settings
{
    public static function render()
    {
        if (isset($_POST['st_save_settings'])) {
            check_admin_referer('st_settings');

            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have permission to change these settings.', 'silent-trust'));
            }

            update_option('silent_trust_traffic_mode', sanitize_text_field($_POST['traffic_mode'] ?? 'auto'));
            update_option('silent_trust_smtp_health_check', !empty($_POST['smtp_health_check']));
            update_option('silent_trust_whitelist_threshold', (int) ($_POST['whitelist_threshold'] ?? 3));
            update_option('silent_trust_daily_limit', (int) ($_POST['daily_limit'] ?? 3));
            update_option('silent_trust_alert_emails', sanitize_textarea_field($_POST['alert_emails'] ?? ''));
            update_option('st_maxmind_license_key', sanitize_text_field($_POST['maxmind_license_key'] ?? ''));
            update_option('st_force_sync_mode', !empty($_POST['force_sync_mode']));
            update_option('st_debug_mode', !empty($_POST['st_debug_mode']));

            // Webhook Settings
            update_option('st_webhook_enabled', !empty($_POST['st_webhook_enabled']));
            update_option('st_webhook_url', esc_url_raw($_POST['st_webhook_url'] ?? ''));
            update_option('st_webhook_token', sanitize_text_field($_POST['st_webhook_token'] ?? ''));

            echo '<div class="notice notice-success"><p>Settings saved successfully.</p></div>';
        }

        $traffic_mode = get_option('silent_trust_traffic_mode', 'auto');
        $smtp_check = get_option('silent_trust_smtp_health_check', true);
        $whitelist_threshold = get_option('silent_trust_whitelist_threshold', 3);
        $daily_limit = get_option('silent_trust_daily_limit', 3);
        $alert_emails = get_option('silent_trust_alert_emails', get_option('admin_email'));
        $maxmind_key = get_option('st_maxmind_license_key', '');
        $force_sync = get_option('st_force_sync_mode', false);
        $debug_mode = get_option('st_debug_mode', false);

        // Webhook Settings
        $webhook_enabled = get_option('st_webhook_enabled', false);
        $webhook_url = get_option('st_webhook_url', '');
        $webhook_token = get_option('st_webhook_token', '');

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

                    <tr>
                        <th>Debug Mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="st_debug_mode" value="1" <?php checked($debug_mode); ?>>
                                Enable debug logging
                            </label>
                            <p class="description">When enabled, detailed info/warning messages are written to
                                <code>wp-content/debug.log</code>. Errors are always logged regardless of this setting.
                            </p>
                        </td>
                    </tr>
                </table>

                <h2>Webhook Integration</h2>
                <p class="description">Send successful form submissions to an external CRM or API.</p>
                <table class="form-table">
                    <tr>
                        <th>Enable Webhook</th>
                        <td>
                            <label>
                                <input type="checkbox" name="st_webhook_enabled" value="1" <?php checked($webhook_enabled); ?>>
                                Send data to webhook
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Webhook URL</th>
                        <td>
                            <input type="url" name="st_webhook_url" value="<?php echo esc_attr($webhook_url); ?>"
                                class="regular-text" placeholder="https://crm.example.com/api/v1/web-leads">
                            <p class="description">The destination URL for the POST request.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Bearer Token</th>
                        <td>
                            <input type="text" name="st_webhook_token" value="<?php echo esc_attr($webhook_token); ?>"
                                class="regular-text" placeholder="Your API Token">
                            <p class="description">Optional. Sent as <code>Authorization: Bearer {token}</code> header.</p>
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
                    }).done(function (response) {
                        if (response.success) {
                            $status.html('<span style="color:#46b450">✅ ' + response.data.message + '</span>');
                            setTimeout(function () { location.reload(); }, 1500);
                        } else {
                            $status.html('<span style="color:#dc3232">❌ ' + response.data.message + '</span>');
                            $btn.prop('disabled', false).text('Retry Download');
                        }
                    }).fail(function () {
                        $status.html('<span style="color:#dc3232">❌ Network error. Please try again.</span>');
                        $btn.prop('disabled', false).text('Retry Download');
                    });
                });

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
}
