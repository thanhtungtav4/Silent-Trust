<?php
namespace SilentTrust;

if (!defined('ABSPATH'))
    exit;

class VPN_Detector
{

    private $geoip;
    private $vpn_asn_list;

    public function __construct()
    {
        $this->geoip = new GeoIP();

        // Known VPN/Hosting/Datacenter ASNs
        $this->vpn_asn_list = [
            // Cloud providers
            16509, // Amazon AWS
            15169, // Google Cloud
            8075,  // Microsoft Azure
            14061, // DigitalOcean
            20473, // Choopa (Vultr)
            24940, // Hetzner
            16276, // OVH

            // VPN providers
            9009,  // M247 - VPN provider
            51167, // Contabo (often used for VPN)
            60068, // CDN77 / VPN

            // Popular VPN services
            // Add more as needed
        ];

        // Allow admin to customize this list
        $custom_asn = get_option('silent_trust_vpn_asn_list', []);
        if (!empty($custom_asn)) {
            $this->vpn_asn_list = array_merge($this->vpn_asn_list, $custom_asn);
        }
    }

    /**
     * Check if IP is from VPN/Proxy/Datacenter
     */
    public function is_vpn_or_datacenter($ip)
    {
        $asn_data = $this->geoip->get_asn($ip);

        if (!$asn_data || !isset($asn_data['asn'])) {
            return [
                'is_vpn' => false,
                'confidence' => 'low',
                'reason' => 'ASN data not available'
            ];
        }

        $asn = $asn_data['asn'];

        // Check against known VPN/hosting ASN list
        if (in_array($asn, $this->vpn_asn_list)) {
            return [
                'is_vpn' => true,
                'confidence' => 'high',
                'asn' => $asn,
                'organization' => $asn_data['organization'] ?? 'Unknown',
                'reason' => 'Known VPN/Hosting ASN'
            ];
        }

        // Heuristic: Check if organization name contains VPN keywords
        $org = strtolower($asn_data['organization'] ?? '');
        $vpn_keywords = ['vpn', 'proxy', 'datacenter', 'hosting', 'cloud', 'server'];

        foreach ($vpn_keywords as $keyword) {
            if (strpos($org, $keyword) !== false) {
                return [
                    'is_vpn' => true,
                    'confidence' => 'medium',
                    'asn' => $asn,
                    'organization' => $asn_data['organization'],
                    'reason' => 'Organization name contains: ' . $keyword
                ];
            }
        }

        return [
            'is_vpn' => false,
            'confidence' => 'medium',
            'asn' => $asn,
            'organization' => $asn_data['organization'] ?? 'Unknown'
        ];
    }

    /**
     * Check if IP is whitelisted (for corporate VPNs)
     */
    public function is_whitelisted($ip)
    {
        $whitelist = get_option('silent_trust_vpn_whitelist_ips', []);

        if (empty($whitelist)) {
            return false;
        }

        // Check exact match or CIDR range
        foreach ($whitelist as $whitelisted) {
            if ($ip === $whitelisted) {
                return true;
            }

            // Check CIDR range
            if (strpos($whitelisted, '/') !== false) {
                if ($this->ip_in_range($ip, $whitelisted)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if IP is in CIDR range
     */
    private function ip_in_range($ip, $cidr)
    {
        list($subnet, $mask) = explode('/', $cidr);

        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask_long = ~((1 << (32 - $mask)) - 1);

        return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
    }
}
