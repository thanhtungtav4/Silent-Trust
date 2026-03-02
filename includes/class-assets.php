<?php
namespace SilentTrust;

if (!defined('ABSPATH'))
    exit;

class Assets
{

    public function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        // Use form_elements filter which gets $fields and $form
        add_filter('wpcf7_form_elements', [$this, 'add_hidden_field'], 10, 1);
        add_filter('script_loader_tag', [$this, 'add_defer_attribute'], 10, 2);
    }

    /**
     * Enqueue fingerprint.js on all frontend pages
     * Note: Can't reliably detect CF7 forms in widgets/popups, so enqueue everywhere
     */
    public function enqueue_frontend_assets()
    {
        // Only enqueue on frontend (not admin)
        if (is_admin()) {
            return;
        }

        // Only enqueue if CF7 is active
        if (!class_exists('WPCF7')) {
            return;
        }

        // Enqueue fingerprint script
        wp_enqueue_script(
            'silent-trust-fingerprint',
            SILENT_TRUST_PLUGIN_URL . 'assets/js/fingerprint.js',
            [],
            SILENT_TRUST_VERSION,
            true // Load in footer
        );
    }

    /**
     * Add defer attribute to fingerprint script
     */
    public function add_defer_attribute($tag, $handle)
    {
        if ('silent-trust-fingerprint' === $handle) {
            return str_replace(' src', ' defer src', $tag);
        }
        return $tag;
    }

    /**
     * Add hidden payload field to CF7 forms
     * Note: Honeypot injection is handled by CF7_Integration::inject_honeypot()
     * Called by wpcf7_form_elements filter
     */
    public function add_hidden_field($form_markup)
    {
        // Add st_payload field for fingerprint data
        $hidden_field = '<input type="hidden" name="st_payload" value="" autocomplete="off">';

        return $hidden_field . $form_markup;
    }
}
