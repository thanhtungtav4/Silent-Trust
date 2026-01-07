<?php
namespace SilentTrust;

if (!defined('ABSPATH'))
    exit;

/**
 * GeoIP Handler - Now uses bundled MaxMind database
 * Falls back to geoip-location plugin if bundled DB not available
 */
class GeoIP
{
    private $bundled;

    public function __construct()
    {
        // Use bundled GeoIP as primary source
        $this->bundled = new GeoIP_Bundled();
    }

    /**
     * Get location data for IP address
     * 
     * @param string $ip_address IP to lookup
     * @return array|null Location data or null if not found
     */
    public function get_location($ip_address = null)
    {
        // Get client IP if not provided
        if (!$ip_address) {
            $ip_address = $this->get_client_ip();
        }

        // Validate IP
        if (empty($ip_address) || !filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        // Use bundled database (with automatic fallback)
        return $this->bundled->get_location($ip_address);
    }

    /**
     * Get country code only (faster lookup)
     * 
     * @param string $ip_address IP to lookup
     * @return string|null Country code or null
     */
    public function get_country_code($ip_address)
    {
        $location = $this->get_location($ip_address);
        return $location['country_code'] ?? null;
    }

    /**
     * Get ASN for IP address (requires ASN database - not implemented yet)
     * 
     * @param string $ip_address IP to lookup
     * @return string|null ASN or null
     */
    public function get_asn($ip_address = null)
    {
        $location = $this->get_location($ip_address);
        return $location['asn'] ?? null;
    }

    /**
     * Get expected timezone for a country code
     * 
     * @param string $country_code Two-letter country code
     * @return string|null Primary timezone or null
     */
    public function get_country_timezone($country_code)
    {
        $timezone_map = [
            'VN' => 'Asia/Ho_Chi_Minh',
            'US' => 'America/New_York',
            'GB' => 'Europe/London',
            'FR' => 'Europe/Paris',
            'DE' => 'Europe/Berlin',
            'JP' => 'Asia/Tokyo',
            'CN' => 'Asia/Shanghai',
            'AU' => 'Australia/Sydney',
            'IN' => 'Asia/Kolkata',
            'BR' => 'America/Sao_Paulo',
        ];

        return $timezone_map[$country_code] ?? null;
    }

    /**
     * Check if GeoIP service is available
     * 
     * @return bool True if database available or fallback plugin active
     */
    public function is_available()
    {
        $status = $this->bundled->get_status();

        // Check if bundled DB exists OR fallback plugin available
        return $status['database_exists'] || $status['using_fallback'];
    }

    /**
     * Get service status for debugging
     * 
     * @return array Status information
     */
    public function get_status()
    {
        return $this->bundled->get_status();
    }

    /**
     * Get client IP address (handles proxies)
     * 
     * @return string Client IP address
     */
    private function get_client_ip()
    {
        $ip_keys = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];

        foreach ($ip_keys as $key) {
            if (isset($_SERVER[$key]) && filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
                return $_SERVER[$key];
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
