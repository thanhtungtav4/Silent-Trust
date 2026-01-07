<?php
namespace SilentTrust;

if (!defined('ABSPATH'))
    exit;

/**
 * Self-contained GeoIP handler using bundled MaxMind GeoLite2 database
 * Removes dependency on external geoip-location plugin
 */
class GeoIP_Bundled
{
    private $db_path;
    private $reader;

    public function __construct()
    {
        $this->db_path = SILENT_TRUST_PLUGIN_DIR . 'data/GeoLite2-City.mmdb';

        // Load bundled database only
        if (file_exists($this->db_path)) {
            $this->load_database();
        }
    }

    /**
     * Load MaxMind database reader
     */
    private function load_database()
    {
        try {
            require_once SILENT_TRUST_PLUGIN_DIR . 'vendor/autoload.php';
            $this->reader = new \GeoIp2\Database\Reader($this->db_path);
        } catch (\Exception $e) {
            error_log('[Silent Trust] Failed to load GeoIP database: ' . $e->getMessage());
            $this->reader = null;
        }
    }

    /**
     * Get location data for IP address
     */
    public function get_location($ip_address)
    {
        // Return null if no bundled database available
        if (!$this->reader) {
            return null;
        }

        return $this->get_location_bundled($ip_address);
    }

    /**
     * Get location from bundled database
     */
    private function get_location_bundled($ip_address)
    {
        try {
            $record = $this->reader->city($ip_address);

            return [
                'ip_address' => $ip_address,
                'city' => $record->city->name ?? '',
                'region' => $record->mostSpecificSubdivision->name ?? '',
                'country' => $record->country->name ?? '',
                'country_code' => $record->country->isoCode ?? '',
                'latitude' => $record->location->latitude ?? 0,
                'longitude' => $record->location->longitude ?? 0,
                'timezone' => $record->location->timeZone ?? '',
                'asn' => null // ASN requires separate database
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Download GeoLite2 database from MaxMind
     */
    public function download_database()
    {
        $license_key = get_option('st_maxmind_license_key');

        if (empty($license_key)) {
            return new \WP_Error('no_license', 'MaxMind license key required. Get free key at https://www.maxmind.com/en/geolite2/signup');
        }

        // Create data directory if not exists
        $data_dir = SILENT_TRUST_PLUGIN_DIR . 'data';
        if (!is_dir($data_dir)) {
            wp_mkdir_p($data_dir);
        }

        // Download URL
        $edition_id = 'GeoLite2-City';
        $url = "https://download.maxmind.com/app/geoip_download?edition_id={$edition_id}&license_key={$license_key}&suffix=tar.gz";

        // Download file
        $temp_file = download_url($url);

        if (is_wp_error($temp_file)) {
            return $temp_file;
        }

        // Extract .mmdb file
        $result = $this->extract_mmdb($temp_file, $data_dir);

        // Cleanup
        @unlink($temp_file);

        if (is_wp_error($result)) {
            return $result;
        }

        // Reload database
        $this->load_database();

        return true;
    }

    /**
     * Extract .mmdb file from .tar.gz archive
     */
    private function extract_mmdb($tar_gz_file, $dest_dir)
    {
        try {
            // Using PharData to extract tar.gz
            $phar = new \PharData($tar_gz_file);
            $phar->extractTo($dest_dir, null, true);

            // Find .mmdb file in extracted directory
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dest_dir),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'mmdb') {
                    // Move to data directory
                    $new_path = $dest_dir . '/GeoLite2-City.mmdb';
                    rename($file->getPathname(), $new_path);

                    // Cleanup extracted directory
                    $this->cleanup_extracted_dir($dest_dir);

                    return true;
                }
            }

            return new \WP_Error('no_mmdb', 'No .mmdb file found in archive');
        } catch (\Exception $e) {
            return new \WP_Error('extract_failed', $e->getMessage());
        }
    }

    /**
     * Cleanup extracted directories (keep only .mmdb)
     */
    private function cleanup_extracted_dir($dir)
    {
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === 'GeoLite2-City.mmdb') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursive_rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }

    /**
     * Recursively remove directory
     */
    private function recursive_rmdir($dir)
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursive_rmdir($path) : unlink($path);
        }
        return rmdir($dir);
    }

    /**
     * Schedule monthly database updates
     */
    public function schedule_updates()
    {
        if (!wp_next_scheduled('st_update_geoip_db')) {
            wp_schedule_event(time(), 'monthly', 'st_update_geoip_db');
        }
    }

    /**
     * Unschedule updates (on plugin deactivation)
     */
    public function unschedule_updates()
    {
        $timestamp = wp_next_scheduled('st_update_geoip_db');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'st_update_geoip_db');
        }
    }

    /**
     * Check if database exists and is recent
     */
    public function is_database_valid()
    {
        if (!file_exists($this->db_path)) {
            return false;
        }

        // Check if database is less than 35 days old
        $file_time = filemtime($this->db_path);
        $age_days = (time() - $file_time) / DAY_IN_SECONDS;

        return $age_days < 35;
    }

    /**
     * Get database status info
     */
    public function get_status()
    {
        $status = [
            'database_exists' => file_exists($this->db_path),
            'database_path' => $this->db_path,
            'license_key_set' => !empty(get_option('st_maxmind_license_key')),
        ];

        if ($status['database_exists']) {
            $status['database_size'] = size_format(filesize($this->db_path));
            $status['database_modified'] = date('Y-m-d H:i:s', filemtime($this->db_path));
            $status['database_age_days'] = round((time() - filemtime($this->db_path)) / DAY_IN_SECONDS, 1);
            $status['is_valid'] = $this->is_database_valid();
        }

        return $status;
    }
}
