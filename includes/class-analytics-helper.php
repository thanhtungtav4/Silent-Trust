<?php
namespace SilentTrust;

if (!defined('ABSPATH'))
    exit;

/**
 * Analytics Helper - Extract comprehensive analytics data from submissions
 */
class Analytics_Helper
{
    private $geoip;

    public function __construct()
    {
        $this->geoip = new GeoIP_Bundled();
    }

    /**
     * Extract all analytics data from form submission
     * 
     * @param array $payload Frontend fingerprint payload
     * @param string $ip_address Client IP
     * @return array Complete analytics data
     */
    public function extract_analytics($payload, $ip_address)
    {
        return array_merge(
            $this->extract_geoip_data($ip_address),
            $this->extract_url_data($payload),
            $this->extract_utm_data($payload),
            $this->extract_session_data($payload),
            $this->extract_device_data($payload),
            $this->extract_time_data($payload)
        );
    }

    /**
     * Extract enhanced GeoIP data
     */
    private function extract_geoip_data($ip_address)
    {
        $location = $this->geoip->get_location($ip_address);

        return [
            'ip_country_name' => $location['country_name'] ?? null,
            'ip_region' => $location['region'] ?? null,
            'ip_latitude' => $location['latitude'] ?? null,
            'ip_longitude' => $location['longitude'] ?? null,
            'ip_timezone' => $location['timezone'] ?? null
        ];
    }

    /**
     * Extract URL tracking data
     */
    private function extract_url_data($payload)
    {
        return [
            'page_url' => $payload['page_url'] ?? $_SERVER['REQUEST_URI'] ?? null,
            'landing_url' => $payload['landing_url'] ?? $this->get_landing_url(),
            'first_url' => $payload['first_url'] ?? null,    // NEW: First URL ever visited
            // FIX: Use HTTP_REFERER fallback instead of REQUEST_URI for lead_url
            // REQUEST_URI on CF7 AJAX = /wp-json/contact-form-7/... (wrong!)
            // HTTP_REFERER = actual page where form exists (correct!)
            'lead_url' => $payload['lead_url'] ?? $_SERVER['HTTP_REFERER'] ?? null,
            'referrer_url' => $payload['referrer_url'] ?? $_SERVER['HTTP_REFERER'] ?? null
        ];
    }

    /**
     * Extract UTM parameters
     */
    private function extract_utm_data($payload)
    {
        return [
            'utm_source' => $payload['utm_source'] ?? $_GET['utm_source'] ?? null,
            'utm_medium' => $payload['utm_medium'] ?? $_GET['utm_medium'] ?? null,
            'utm_campaign' => $payload['utm_campaign'] ?? $_GET['utm_campaign'] ?? null,
            'utm_term' => $payload['utm_term'] ?? $_GET['utm_term'] ?? null,
            'utm_content' => $payload['utm_content'] ?? $_GET['utm_content'] ?? null
        ];
    }

    /**
     * Extract session data
     */
    private function extract_session_data($payload)
    {
        return [
            'session_id' => $payload['session_id'] ?? $this->get_session_id(),
            'session_duration' => isset($payload['session_duration'])
                ? (int) $payload['session_duration']
                : 0,
            'pages_visited' => isset($payload['pages_visited'])
                ? (int) $payload['pages_visited']
                : 1,
            'visit_count' => isset($payload['visit_count'])
                ? (int) $payload['visit_count']
                : 1
        ];
    }

    /**
     * Extract device & browser data
     */
    private function extract_device_data($payload)
    {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        return [
            'user_agent' => $user_agent,
            'browser_name' => $payload['browser_name'] ?? $this->detect_browser($user_agent),
            'browser_version' => $payload['browser_version'] ?? null,
            'os_name' => $payload['os_name'] ?? $this->detect_os($user_agent),
            'os_version' => $payload['os_version'] ?? null,
            'is_mobile' => isset($payload['is_mobile'])
                ? (bool) $payload['is_mobile']
                : wp_is_mobile(),
            'screen_resolution' => $payload['screen_resolution'] ?? null
        ];
    }

    /**
     * Extract time tracking data
     */
    private function extract_time_data($payload)
    {
        return [
            'time_on_page' => isset($payload['time_on_page'])
                ? (int) $payload['time_on_page']
                : null,
            'form_start_time' => isset($payload['form_start_time'])
                ? date('Y-m-d H:i:s', $payload['form_start_time'] / 1000)
                : null,
            'form_complete_time' => isset($payload['form_complete_time'])
                ? date('Y-m-d H:i:s', $payload['form_complete_time'] / 1000)
                : null
        ];
    }

    /**
     * Get or create session ID using transient (cookies)
     */
    private function get_session_id()
    {
        if (isset($_COOKIE['st_session_id'])) {
            return sanitize_text_field($_COOKIE['st_session_id']);
        }

        // Generate new session ID
        $session_id = wp_generate_password(32, false);
        setcookie('st_session_id', $session_id, time() + 1800, '/'); // 30 min

        return $session_id;
    }

    /**
     * Get landing URL from cookie/transient
     */
    private function get_landing_url()
    {
        if (isset($_COOKIE['st_landing_url'])) {
            return sanitize_text_field($_COOKIE['st_landing_url']);
        }

        // Fallback to current URL
        return $_SERVER['REQUEST_URI'] ?? null;
    }

    /**
     * Simple browser detection from user agent
     */
    private function detect_browser($user_agent)
    {
        if (preg_match('/Edge/i', $user_agent)) {
            return 'Edge';
        } elseif (preg_match('/Chrome/i', $user_agent)) {
            return 'Chrome';
        } elseif (preg_match('/Firefox/i', $user_agent)) {
            return 'Firefox';
        } elseif (preg_match('/Safari/i', $user_agent)) {
            return 'Safari';
        } elseif (preg_match('/Opera|OPR/i', $user_agent)) {
            return 'Opera';
        } elseif (preg_match('/MSIE|Trident/i', $user_agent)) {
            return 'Internet Explorer';
        }

        return 'Unknown';
    }

    /**
     * Simple OS detection from user agent
     */
    private function detect_os($user_agent)
    {
        if (preg_match('/Windows NT 10/i', $user_agent)) {
            return 'Windows 10';
        } elseif (preg_match('/Windows NT 6.3/i', $user_agent)) {
            return 'Windows 8.1';
        } elseif (preg_match('/Windows NT 6.2/i', $user_agent)) {
            return 'Windows 8';
        } elseif (preg_match('/Windows NT 6.1/i', $user_agent)) {
            return 'Windows 7';
        } elseif (preg_match('/Windows/i', $user_agent)) {
            return 'Windows';
        } elseif (preg_match('/Mac OS X/i', $user_agent)) {
            return 'macOS';
        } elseif (preg_match('/Android/i', $user_agent)) {
            return 'Android';
        } elseif (preg_match('/iOS|iPhone|iPad/i', $user_agent)) {
            return 'iOS';
        } elseif (preg_match('/Linux/i', $user_agent)) {
            return 'Linux';
        }

        return 'Unknown';
    }

    /**
     * Set session tracking cookies on page load
     */
    public static function init_session_tracking()
    {
        // Skip admin pages and AJAX
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        // Set session cookie if not exists
        if (!isset($_COOKIE['st_session_id'])) {
            $session_id = wp_generate_password(32, false);
            setcookie('st_session_id', $session_id, time() + 1800, '/'); // 30 min

            // Set landing URL
            $current_url = $_SERVER['REQUEST_URI'] ?? '';
            setcookie('st_landing_url', $current_url, time() + 1800, '/');

            // Initialize counters
            setcookie('st_pages_visited', '1', time() + 1800, '/');
            setcookie('st_session_start', (string) time(), time() + 1800, '/');
        } else {
            // Increment page count
            $pages = isset($_COOKIE['st_pages_visited']) ? (int) $_COOKIE['st_pages_visited'] : 0;
            setcookie('st_pages_visited', (string) ($pages + 1), time() + 1800, '/');
        }

        // Persistent visit count (1 year)
        $visit_count = isset($_COOKIE['st_visit_count']) ? (int) $_COOKIE['st_visit_count'] : 0;
        if (!isset($_COOKIE['st_session_id']) || !isset($_COOKIE['st_visit_count'])) {
            setcookie('st_visit_count', (string) ($visit_count + 1), time() + 31536000, '/');
        }
    }
}
