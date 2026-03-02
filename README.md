# Silent Trust - Advanced WordPress Anti-Spam Plugin

![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/license-GPL--2.0%2B-green.svg)

**Silent Trust** is a sophisticated WordPress anti-spam plugin that uses device fingerprinting, risk scoring, and behavioral analysis to protect Contact Form 7 submissions from bots and malicious users—without disrupting legitimate visitors.

## 🚀 Features

### 🛡️ Core Protection
- **Device Fingerprinting**: Unique browser/device identification using Canvas, WebGL, Audio, and hardware metrics
- **Risk Scoring Engine**: ML-based risk assessment (0-100 score)
- **Honeypot Fields**: Auto-generated invisible fields with daily rotation to catch bots
- **GeoIP Detection**: Country-based risk analysis and VPN detection
- **Rate Limiting**: IP and device-based submission throttling
- **Behavioral Analysis**: Mouse movement, keystroke patterns, and timing analysis

### 📊 Analytics Dashboard
- **Real-time Stats**: Total submissions, block rate, average risk score, today's activity
- **Interactive Charts** (Chart.js):
  - 📈 Timeline: Daily submission trends
  - 🎯 Action Distribution: Allow, Challenge, Block breakdown
  - ⚠️ Risk Distribution: Low/Medium/High classification
  - 🌍 Top Countries: Geographic submission sources
- **Date Filtering**: View data for last 7, 30, or 90 days
- **CSV Export**: Full analytics data export

### 🔍 IP Inspector
Deep-dive analysis of individual IP addresses:
- **Device Tracking**: Multiple fingerprints from same IP
- **Activity Timeline**: Submission patterns over time
- **Attack Heatmap**: Hour-by-day activity visualization
- **Quick Actions**: Ban, temp-ban, or whitelist IPs
- **Performance Optimized**: Limited queries for fast loading

### 📋 Logs & Data Management
- **Submission Logs**: Complete audit trail with IP, risk, action, timestamps
- **Form Data Viewer**: Detailed modal view for each submission
- **Bulk Actions**: Ban/whitelist multiple IPs at once
- **Search & Filter**: Find submissions by IP, country, action type

### ⚙️ Advanced Settings
- **Risk Thresholds**: Configurable auto-ban levels
- **Challenge Mode**: CAPTCHA for suspicious submissions
- **Whitelist/Blacklist**: IP and email management
- **Ban Duration**: 24-hour, 7-day, or permanent bans
- **Email Alerts**: Real-time notifications for high-risk submissions

## 📦 Installation

### Requirements
- WordPress 6.0 or higher
- PHP 7.4 or higher
- Contact Form 7 plugin
- MySQL 5.7+ or MariaDB 10.2+

### Steps

1. **Download & Upload**
```bash
git clone https://github.com/thanhtungtav4/Silent-Trust.git
cd Silent-Trust
composer install
```

2. **Activate Plugin**
   - Go to WordPress Admin → Plugins
   - Find "Silent Trust" and click Activate

3. **Configure Settings**
   - Navigate to Admin → Silent Trust → Settings
   - Set your risk thresholds and preferences

4. **Install GeoIP Database** (Optional)
```bash
# Download GeoLite2 City database from MaxMind
# Place GeoLite2-City.mmdb in: wp-content/plugins/silent-trust/data/
```

## 🎯 How It Works

### 1. **Form Submission**
```
User fills Contact Form 7
     ↓
JavaScript collects fingerprint
     ↓
Hidden field st_payload populated
     ↓
Form submitted to server
```

### 2. **Risk Analysis**
```php
Honeypot Check (instant) → Risk Calculation → Decision Engine
                                  ↓
                    Device + IP + Behavior + GeoIP
                                  ↓
                         Risk Score (0-100)
```

### 3. **Action Taken**
| Risk Score | Action | Result |
|------------|--------|--------|
| 0-29 | `allow` | Email sent normally |
| 30-49 | `allow_log` | Email sent, logged for review |
| 50-69 | `delay` | Delayed send via WP-Cron |
| 70-89 | `soft_penalty` / `hard_penalty` | Blocked, logged |
| 90-100 | `drop` | Silent block |

### 4. **Honeypot Detection**
```html
<!-- Auto-injected into forms -->
<input type="text" 
       name="st_hp_email_check" 
       value="" 
       style="position:absolute;left:-9999px;" 
       autocomplete="off">
```
If filled → Instant DROP with risk_score=100

## 📊 Database Schema

### Tables
| Table | Purpose |
|-------|-------|
| `wp_st_submissions` | Main submission log (fingerprint, risk, GeoIP, UTM, form data) |
| `wp_st_penalties` | IP and device bans (soft/hard, with expiry) |
| `wp_st_whitelist` | Trusted devices (auto-whitelisted after repeated safe submissions) |
| `wp_st_anomalies` | Logged anomalies for audit |
| `wp_st_analysis_queue` | Async processing queue for two-tier analysis |

### Key Columns: `wp_st_submissions`
```sql
CREATE TABLE wp_st_submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,
    fingerprint_hash VARCHAR(64) NOT NULL,
    device_cookie VARCHAR(64),
    device_type ENUM('desktop','mobile','tablet','unknown'),
    ip_address VARCHAR(45),
    country_code VARCHAR(2),
    risk_score INT DEFAULT 0,
    action ENUM('allow','allow_log','delay','drop','soft_penalty','hard_penalty'),
    email_sent BOOLEAN DEFAULT 0,
    submitted_at DATETIME NOT NULL,
    -- Plus: GeoIP, URL tracking, UTM, session, device, form engagement, submission_data
    INDEX idx_ip (ip_address),
    INDEX idx_fingerprint (fingerprint_hash),
    INDEX idx_time (submitted_at)
);
```

## 🔧 Configuration

### Risk Thresholds (wp_options)
```php
update_option('st_allow_threshold', 30);    // Below = allow
update_option('st_challenge_threshold', 70); // Between = challenge
update_option('st_ban_threshold', 90);       // Above = ban
```

### Honeypot Toggle
```php
update_option('st_honeypot_enabled', true); // Enable honeypot
```

## 📱 API & Hooks

### Filters
```php
// Custom cron schedules
add_filter('cron_schedules', function($schedules) { ... });

// CF7 form markup (honeypot injection)
add_filter('wpcf7_form_elements', [$this, 'inject_honeypot']);

// Script defer attribute
add_filter('script_loader_tag', [$this, 'add_defer_attribute']);
```

### Actions
```php
// Cron hooks
add_action('silent_trust_daily_digest', ...);
add_action('silent_trust_weekly_report', ...);
add_action('silent_trust_check_stuck_mail', ...);
add_action('st_cleanup_old_logs', ...);
add_action('st_process_async_analysis', ...);
```

## 🎨 Screenshots

### Dashboard Analytics
![Dashboard](docs/dashboard-analytics.png)

### IP Inspector
![IP Inspector](docs/ip-inspector.png)

### Submission Logs
![Logs](docs/submission-logs.png)

## 🧪 Testing

### Test Normal Submission
```bash
# Visit your CF7 form
# Fill fields normally
# Submit → Should pass
```

### Test Honeypot (Bot Simulation)
```bash
# Inspect page → Find st_hp_* field
# Fill the hidden field manually
# Submit → Should be blocked (check Logs tab)
```

### Check Analytics
```bash
Admin → Silent Trust → Dashboard
# View charts, export CSV
```

## 🔐 Security Features

- ✅ **SQL Injection Protection**: All queries use $wpdb->prepare()
- ✅ **XSS Prevention**: esc_html(), esc_attr() throughout
- ✅ **CSRF Protection**: Nonce verification on AJAX calls
- ✅ **IP Validation**: filter_var(FILTER_VALIDATE_IP)
- ✅ **Rate Limiting**: Device and IP based
- ✅ **Silent Blocking**: Bots think submission succeeded

## 📈 Performance

- **Async Processing**: Heavy tasks run in background
- **Query Optimization**: Indexed columns, LIMIT clauses
- **Caching**: Transient API for frequent lookups
- **Lazy Loading**: IP Inspector limits to 50 records
- **CDN Integration**: Chart.js loaded from CDN

## 🛠️ Development

### File Structure
```
silent-trust/
├── admin/
│   ├── class-admin-page.php        # Admin UI (dashboard, logs, settings, IP inspector)
│   └── assets/
│       ├── css/admin.css
│       └── js/admin.js
├── includes/
│   ├── class-cf7-integration.php   # Contact Form 7 hooks + honeypot
│   ├── class-risk-engine.php       # ML-based risk scoring
│   ├── class-decision-engine.php   # Action logic (allow/delay/drop)
│   ├── class-database.php          # DB operations (5 tables)
│   ├── class-payload-validator.php # Payload format + GeoIP consistency
│   ├── class-analytics-helper.php  # Session, URL, UTM tracking
│   ├── class-async-processor.php   # Two-tier async analysis
│   ├── class-ml-weight-adjuster.php# Adaptive weight learning
│   ├── class-alert-system.php      # Daily/weekly email reports
│   ├── class-admin-ajax.php        # AJAX endpoints for dashboard
│   ├── class-assets.php            # Frontend script enqueue
│   ├── class-vpn-detector.php      # VPN/datacenter IP detection
│   ├── class-geoip.php             # GeoIP lookup (MaxMind)
│   └── class-geoip-bundled.php     # GeoIP database management
├── assets/
│   └── js/fingerprint.js           # Browser fingerprinting (12KB)
├── data/                            # GeoIP database storage
├── vendor/                          # Composer dependencies (geoip2)
├── silent-trust.php                 # Plugin entry point
├── uninstall.php                    # Clean removal handler
├── composer.json
└── README.md
```

### Contributing
1. Fork the repository
2. Create feature branch (`git checkout -b feature/amazing`)
3. Commit changes (`git commit -m 'Add amazing feature'`)
4. Push to branch (`git push origin feature/amazing`)
5. Open Pull Request

## 📝 Changelog

### Version 1.0.1 (2026-02-25)
- 🔒 Fixed IP spoofing vulnerability (rightmost XFF extraction)
- 🔒 Fixed race condition in decision context storage
- 🔒 Added `current_user_can()` checks on all AJAX + settings endpoints
- 🔧 Consolidated honeypot to single dynamic implementation
- 🔧 Fixed duplicate `Admin_AJAX` instantiation
- 🔧 Increased WP-Cron interval from 10s to 60s
- 🔧 Implemented `check_stable_traits()` fingerprint drift detection
- 🔧 Created `uninstall.php` for clean plugin removal
- 🔧 Fixed ENUM case mismatch in submission logging
- 🔧 Removed duplicate GeoIP instantiation
- 🔧 Integrated dead validation methods into main flow
- 🔧 Replaced `rand()` with `wp_rand()`

### Version 1.0.0 (2026-01-07)
- ✅ Initial release
- ✅ Device fingerprinting with Canvas, WebGL, Audio
- ✅ Honeypot fields with daily rotation
- ✅ Analytics dashboard with Chart.js
- ✅ IP Inspector with device tracking & heatmaps
- ✅ ML-based risk scoring with adaptive weights
- ✅ Two-tier async processing
- ✅ GeoIP detection + VPN detector
- ✅ Alert system (daily digest, weekly report, spike detection)
- ✅ Submission logs with bulk actions
- ✅ Session & UTM tracking
- ✅ Responsive admin UI

## 🤝 Support

- **Issues**: [GitHub Issues](https://github.com/thanhtungtav4/Silent-Trust/issues)
- **Documentation**: [Wiki](https://github.com/thanhtungtav4/Silent-Trust/wiki)
- **Email**: thanhtungtav4@gmail.com

## 📜 License

This plugin is licensed under the [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

## 🙏 Credits

- **Chart.js**: Interactive charts ([chart.js.org](https://www.chartjs.org))
- **MaxMind GeoIP2**: IP geolocation ([maxmind.com](https://www.maxmind.com))
- **FingerprintJS**: Browser fingerprinting inspiration

## 🎉 Acknowledgments

Built with ❤️ for the WordPress community to fight spam intelligently and silently.

---

**Made by [Thanh Tung](https://github.com/thanhtungtav4)** | ⭐ Star this repo if you find it useful!
