<?php
namespace SilentTrust;

if (!defined('ABSPATH'))
    exit;

class Database
{

    private $wpdb;
    private $submissions_table;
    private $penalties_table;
    private $whitelist_table;
    private $anomalies_table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->submissions_table = $wpdb->prefix . 'st_submissions';
        $this->penalties_table = $wpdb->prefix . 'st_penalties';
        $this->whitelist_table = $wpdb->prefix . 'st_whitelist';
        $this->anomalies_table = $wpdb->prefix . 'st_anomalies';
    }

    /**
     * Create database tables on plugin activation
     */
    public function create_tables()
    {
        $charset_collate = $this->wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Main submissions table
        $sql_submissions = "CREATE TABLE {$this->submissions_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            form_id BIGINT UNSIGNED NOT NULL,
            fingerprint_hash VARCHAR(64) NOT NULL,
            device_cookie VARCHAR(64),
            device_type ENUM('desktop', 'mobile', 'tablet', 'unknown') DEFAULT 'unknown',
            fingerprint_data TEXT,
            behavior_data TEXT,
            risk_breakdown JSON COMMENT 'Detailed risk factors',
            ip_address VARCHAR(45),
            country_code VARCHAR(2),
            city VARCHAR(100),
            asn VARCHAR(20),
            risk_score INT DEFAULT 0,
            action ENUM('allow', 'allow_log', 'delay', 'drop', 'soft_penalty', 'hard_penalty') DEFAULT 'allow',
            email_sent BOOLEAN DEFAULT 0,
            email_failure_reason TEXT COMMENT 'NULL=intentional drop, NOT NULL=SMTP error',
            sent_via ENUM('direct', 'cron', 'fallback') COMMENT 'For delay action',
            submitted_at DATETIME NOT NULL,
            
            -- Enhanced GeoIP Analytics
            ip_country_name VARCHAR(100),
            ip_region VARCHAR(100),
            ip_latitude DECIMAL(10,7),
            ip_longitude DECIMAL(10,7),
            ip_timezone VARCHAR(50),
            
            -- URL Tracking
            page_url VARCHAR(500) COMMENT 'Current page where form submitted',
            landing_url VARCHAR(500) COMMENT 'First page visited (session)',
            first_url VARCHAR(500) COMMENT 'First URL ever visited (persistent)',
            lead_url VARCHAR(500) COMMENT 'URL of page with form (submission page)',
            referrer_url VARCHAR(500) COMMENT 'HTTP referrer',
            
            -- UTM Campaign Parameters
            utm_source VARCHAR(255),
            utm_medium VARCHAR(255),
            utm_campaign VARCHAR(255),
            utm_term VARCHAR(255),
            utm_content VARCHAR(255),
            
            -- Session & Engagement
            session_id VARCHAR(64),
            session_duration INT COMMENT 'Time on site (seconds)',
            pages_visited INT DEFAULT 1,
            visit_count INT DEFAULT 1,
            
            -- Device & Browser Details
            user_agent TEXT,
            browser_name VARCHAR(50),
            browser_version VARCHAR(20),
            os_name VARCHAR(50),
            os_version VARCHAR(20),
            is_mobile BOOLEAN,
            screen_resolution VARCHAR(20),
            
            -- Form Engagement Time
            time_on_page INT COMMENT 'Seconds on form page',
            form_start_time DATETIME COMMENT 'When form first focused',
            form_complete_time DATETIME COMMENT 'When submit clicked',
            
            -- Actual form submission data (email, name, message, etc.)
            submission_data LONGTEXT COMMENT 'Serialized form field values',
            
            INDEX idx_fingerprint (fingerprint_hash),
            INDEX idx_device_cookie (device_cookie),
            INDEX idx_ip (ip_address),
            INDEX idx_action (action),
            INDEX idx_risk (risk_score),
            INDEX idx_time (submitted_at),
            INDEX idx_fp_time (fingerprint_hash, submitted_at),
            INDEX idx_ip_time (ip_address, submitted_at),
            INDEX idx_country (country_code),
            INDEX idx_city (city),
            INDEX idx_utm_source (utm_source),
            INDEX idx_session (session_id)
        ) $charset_collate;";

        // Penalties table
        $sql_penalties = "CREATE TABLE {$this->penalties_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            penalty_type ENUM('soft', 'hard') COMMENT 'soft=fingerprint only, hard=fingerprint+IP',
            target_type ENUM('ip', 'fingerprint'),
            target_value VARCHAR(255) NOT NULL,
            reason TEXT,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            INDEX idx_value (target_value),
            INDEX idx_expires (expires_at)
        ) $charset_collate;";

        // Whitelist table
        $sql_whitelist = "CREATE TABLE {$this->whitelist_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_cookie VARCHAR(64) UNIQUE NOT NULL,
            success_count INT DEFAULT 0,
            last_success_at DATETIME,
            created_at DATETIME NOT NULL,
            INDEX idx_cookie (device_cookie)
        ) $charset_collate;";

        // Anomalies table
        $sql_anomalies = "CREATE TABLE {$this->anomalies_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            submission_id BIGINT UNSIGNED,
            anomaly_type VARCHAR(50),
            anomaly_details TEXT,
            severity ENUM('low', 'medium', 'high') DEFAULT 'low',
            detected_at DATETIME NOT NULL,
            INDEX idx_type (anomaly_type),
            INDEX idx_severity (severity),
            INDEX idx_time (detected_at)
        ) $charset_collate;";

        $sql_queue = "CREATE TABLE {$wpdb->prefix}st_analysis_queue (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            payload_hash VARCHAR(64) NOT NULL,
            payload_data LONGTEXT NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            form_id INT NOT NULL,
            submission_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            processed_at DATETIME DEFAULT NULL,
            status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
            error_message TEXT DEFAULT NULL,
            INDEX idx_status_created (status,created_at),
            INDEX idx_payload_hash (payload_hash)
        ) $charset_collate;";

        dbDelta($sql_submissions);
        dbDelta($sql_penalties);
        dbDelta($sql_whitelist);
        dbDelta($sql_anomalies);
        dbDelta($sql_queue);
    }

    /**
     * Migration: Add first_url and lead_url columns (v1.1.0)
     * Safe to run multiple times - checks if columns exist
     */
    public function migrate_add_url_tracking_columns()
    {
        // Check if columns already exist
        $row = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = %s 
                AND COLUMN_NAME IN ('first_url', 'lead_url')",
                $this->submissions_table
            )
        );

        // If both columns exist, skip migration
        if (count($row) >= 2) {
            return true;
        }

        // Add columns if missing
        $sql = "ALTER TABLE {$this->submissions_table}";
        $alterations = [];

        // Check and add first_url
        $has_first_url = false;
        $has_lead_url = false;
        foreach ($row as $column) {
            if ($column->COLUMN_NAME === 'first_url')
                $has_first_url = true;
            if ($column->COLUMN_NAME === 'lead_url')
                $has_lead_url = true;
        }

        if (!$has_first_url) {
            $alterations[] = "ADD COLUMN first_url VARCHAR(500) COMMENT 'First URL ever visited (persistent)' AFTER landing_url";
        }

        if (!$has_lead_url) {
            $alterations[] = "ADD COLUMN lead_url VARCHAR(500) COMMENT 'URL of page with form (submission page)' AFTER first_url";
        }

        if (!empty($alterations)) {
            $sql .= ' ' . implode(', ', $alterations);
            return $this->wpdb->query($sql);
        }

        return true;
    }

    /**
     * Log a submission
     */
    public function log_submission($data)
    {
        // Prepare data array with all analytics fields
        $insert_data = [
            'form_id' => $data['form_id'],
            'fingerprint_hash' => $data['fingerprint_hash'],
            'device_cookie' => $data['device_cookie'] ?? null,
            'device_type' => $data['device_type'] ?? 'unknown',
            'fingerprint_data' => $data['fingerprint_data'] ?? null,
            'behavior_data' => $data['behavior_data'] ?? null,
            'risk_breakdown' => isset($data['risk_breakdown']) ? wp_json_encode($data['risk_breakdown']) : null,
            'ip_address' => $data['ip_address'],
            'country_code' => $data['country_code'] ?? null,
            'city' => $data['city'] ?? null,
            'asn' => $data['asn'] ?? null,
            'risk_score' => $data['risk_score'],
            'action' => $data['action'],
            'email_sent' => $data['email_sent'] ?? 0,
            'email_failure_reason' => $data['email_failure_reason'] ?? null,
            'sent_via' => $data['sent_via'] ?? 'direct',
            'submitted_at' => current_time('mysql'),

            // Enhanced GeoIP Analytics
            'ip_country_name' => $data['ip_country_name'] ?? null,
            'ip_region' => $data['ip_region'] ?? null,
            'ip_latitude' => $data['ip_latitude'] ?? null,
            'ip_longitude' => $data['ip_longitude'] ?? null,
            'ip_timezone' => $data['ip_timezone'] ?? null,

            // URL Tracking (NEW: first_url and lead_url)
            'page_url' => $data['page_url'] ?? null,
            'landing_url' => $data['landing_url'] ?? null,
            'first_url' => $data['first_url'] ?? null,  // NEW
            'lead_url' => $data['lead_url'] ?? null,    // NEW
            'referrer_url' => $data['referrer_url'] ?? null,

            // UTM Parameters
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'utm_term' => $data['utm_term'] ?? null,
            'utm_content' => $data['utm_content'] ?? null,

            // Session & Engagement
            'session_id' => $data['session_id'] ?? null,
            'session_duration' => $data['session_duration'] ?? null,
            'pages_visited' => $data['pages_visited'] ?? null,
            'visit_count' => $data['visit_count'] ?? null,

            // Device & Browser
            'user_agent' => $data['user_agent'] ?? null,
            'browser_name' => $data['browser_name'] ?? null,
            'browser_version' => $data['browser_version'] ?? null,
            'os_name' => $data['os_name'] ?? null,
            'os_version' => $data['os_version'] ?? null,
            'is_mobile' => $data['is_mobile'] ?? null,
            'screen_resolution' => $data['screen_resolution'] ?? null,

            // Form Engagement Time
            'time_on_page' => $data['time_on_page'] ?? null,
            'form_start_time' => $data['form_start_time'] ?? null,
            'form_complete_time' => $data['form_complete_time'] ?? null,

            // Actual form submission data (email, name, message, etc.)
            'submission_data' => isset($data['mail_data']) ? maybe_serialize($data['mail_data']) : null
        ];

        return $this->wpdb->insert(
            $this->submissions_table,
            $insert_data,
            [
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%f',
                '%f',
                '%s',  // GeoIP
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',  // URLs (including new first_url, lead_url)
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',  // UTM
                '%s',
                '%d',
                '%d',
                '%d',        // Session
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',  // Device
                '%d',
                '%s',
                '%s',               // Time tracking
                '%s' // submission_data
            ]
        );
    }

    /**
     * Add penalty with custom duration (legacy method - defaults to 24h)
     */
    public function add_penalty($penalty_type, $target_type, $target_value, $reason = '')
    {
        $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));

        return $this->wpdb->insert(
            $this->penalties_table,
            [
                'penalty_type' => $penalty_type,
                'target_type' => $target_type,
                'target_value' => $target_value,
                'reason' => $reason,
                'created_at' => current_time('mysql'),
                'expires_at' => $expires_at
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Add device (fingerprint) penalty with custom duration
     * 
     * @param string $fingerprint_hash Device fingerprint
     * @param string $reason Ban reason
     * @param int $days Duration in days (default 30)
     * @param string $penalty_type 'soft' or 'hard'
     */
    public function add_device_penalty($fingerprint_hash, $reason = '', $days = 30, $penalty_type = 'hard')
    {
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$days} days"));

        return $this->wpdb->insert(
            $this->penalties_table,
            [
                'penalty_type' => $penalty_type,
                'target_type' => 'fingerprint',
                'target_value' => $fingerprint_hash,
                'reason' => $reason,
                'created_at' => current_time('mysql'),
                'expires_at' => $expires_at
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Add IP penalty with custom duration
     * 
     * @param string $ip_address IP address
     * @param string $reason Ban reason
     * @param int $days Duration in days (default 7)
     * @param string $penalty_type 'soft' or 'hard'
     */
    public function add_ip_penalty($ip_address, $reason = '', $days = 7, $penalty_type = 'hard')
    {
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$days} days"));

        return $this->wpdb->insert(
            $this->penalties_table,
            [
                'penalty_type' => $penalty_type,
                'target_type' => 'ip',
                'target_value' => $ip_address,
                'reason' => $reason,
                'created_at' => current_time('mysql'),
                'expires_at' => $expires_at
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Check if fingerprint or IP is penalized
     */
    public function is_penalized($value, $type = 'fingerprint')
    {
        $sql = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->penalties_table} 
            WHERE target_value = %s 
            AND target_type = %s 
            AND expires_at > NOW()",
            $value,
            $type
        );

        return (int) $this->wpdb->get_var($sql) > 0;
    }

    /**
     * Get whitelist status
     */
    public function is_whitelisted($device_cookie)
    {
        if (empty($device_cookie)) {
            return false;
        }

        $sql = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->whitelist_table} WHERE device_cookie = %s",
            $device_cookie
        );

        return (int) $this->wpdb->get_var($sql) > 0;
    }

    /**
     * Check daily submission limit (HARD LIMIT - even for legitimate users)
     * 
     * @param string $device_cookie Device identifier
     * @return array ['exceeded' => bool, 'count' => int, 'limit' => int]
     */
    public function check_daily_limit($device_cookie)
    {
        $limit = (int) get_option('silent_trust_daily_limit', 3); // Default: 3 per day

        if ($limit <= 0) {
            // Unlimited if set to 0 or disabled
            return ['exceeded' => false, 'count' => 0, 'limit' => 0];
        }

        if (empty($device_cookie)) {
            // No device cookie = can't track, apply strict limit
            return ['exceeded' => true, 'count' => 999, 'limit' => $limit];
        }

        // Count today's submissions from this device
        $today_start = date('Y-m-d 00:00:00');
        $today_end = date('Y-m-d 23:59:59');

        $count = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->submissions_table} 
            WHERE device_cookie = %s 
            AND submitted_at BETWEEN %s AND %s",
            $device_cookie,
            $today_start,
            $today_end
        ));

        return [
            'exceeded' => $count >= $limit,
            'count' => $count,
            'limit' => $limit
        ];
    }
    /**
     * Update whitelist
     */
    public function update_whitelist($device_cookie)
    {
        $existing = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->whitelist_table} WHERE device_cookie = %s",
            $device_cookie
        ));

        if ($existing) {
            return $this->wpdb->update(
                $this->whitelist_table,
                [
                    'success_count' => $existing->success_count + 1,
                    'last_success_at' => current_time('mysql')
                ],
                ['device_cookie' => $device_cookie],
                ['%d', '%s'],
                ['%s']
            );
        } else {
            return $this->wpdb->insert(
                $this->whitelist_table,
                [
                    'device_cookie' => $device_cookie,
                    'success_count' => 1,
                    'last_success_at' => current_time('mysql'),
                    'created_at' => current_time('mysql')
                ],
                ['%s', '%d', '%s', '%s']
            );
        }
    }

    /**
     * Check whitelist status
     */
    public function get_whitelist_status($device_cookie)
    {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->whitelist_table} WHERE device_cookie = %s",
            $device_cookie
        ));
    }

    /**
     * Get submission count in timeframe with decay
     */
    public function get_fingerprint_frequency($fingerprint_hash, $hours = 1)
    {
        $sql = $this->wpdb->prepare(
            "SELECT COUNT(*) as count, 
             SUM(POW(2.718281828, -TIMESTAMPDIFF(DAY, submitted_at, NOW()) / 2)) as decayed_count
             FROM {$this->submissions_table} 
             WHERE fingerprint_hash = %s 
             AND submitted_at > DATE_SUB(NOW(), INTERVAL %d HOUR)",
            $fingerprint_hash,
            $hours
        );

        return $this->wpdb->get_row($sql);
    }

    /**
     * Get IP submission count
     */
    public function get_ip_frequency($ip_address, $hours = 1)
    {
        $sql = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->submissions_table} 
            WHERE ip_address = %s 
            AND submitted_at > DATE_SUB(NOW(), INTERVAL %d HOUR)",
            $ip_address,
            $hours
        );

        return (int) $this->wpdb->get_var($sql);
    }

    /**
     * Count distinct fingerprints for an IP
     */
    public function count_distinct_fingerprints_for_ip($ip_address, $hours = 24)
    {
        $sql = $this->wpdb->prepare(
            "SELECT COUNT(DISTINCT fingerprint_hash) FROM {$this->submissions_table} 
            WHERE ip_address = %s 
            AND submitted_at > DATE_SUB(NOW(), INTERVAL %d HOUR)",
            $ip_address,
            $hours
        );

        return (int) $this->wpdb->get_var($sql);
    }

    /**
     * Get daily submission volume (for adaptive threshold detection)
     */
    public function get_daily_volume()
    {
        $sql = "SELECT COUNT(*) FROM {$this->submissions_table} 
                WHERE submitted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)";

        return (int) $this->wpdb->get_var($sql);
    }

    /**
     * Log anomaly
     */
    public function log_anomaly($submission_id, $type, $details, $severity = 'medium')
    {
        return $this->wpdb->insert(
            $this->anomalies_table,
            [
                'submission_id' => $submission_id,
                'anomaly_type' => $type,
                'anomaly_details' => $details,
                'severity' => $severity,
                'detected_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Clean expired penalties (cron job)
     */
    public function clean_expired_penalties()
    {
        return $this->wpdb->query(
            "DELETE FROM {$this->penalties_table} WHERE expires_at < NOW()"
        );
    }
}
