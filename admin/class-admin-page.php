<?php
namespace SilentTrust;

if (!defined('ABSPATH'))
    exit;

// Load tab classes
require_once __DIR__ . '/tabs/class-tab-dashboard.php';
require_once __DIR__ . '/tabs/class-tab-settings.php';
require_once __DIR__ . '/tabs/class-tab-logs.php';
require_once __DIR__ . '/tabs/class-tab-formdata.php';
require_once __DIR__ . '/tabs/class-tab-ip-inspector.php';

use SilentTrust\Admin\Tabs\Tab_Dashboard;
use SilentTrust\Admin\Tabs\Tab_Settings;
use SilentTrust\Admin\Tabs\Tab_Logs;
use SilentTrust\Admin\Tabs\Tab_FormData;
use SilentTrust\Admin\Tabs\Tab_IP_Inspector;

/**
 * Admin Page - Thin dispatcher for tab-based admin UI
 * 
 * Each tab is implemented in its own class under admin/tabs/.
 * This file handles menu registration, asset enqueue, and tab routing.
 */
class Admin_Page
{

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_menu_page(
            'Silent Trust',
            'Silent Trust',
            'manage_options',
            'silent-trust',
            [$this, 'render_dashboard'],
            'dashicons-shield-alt',
            30
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook)
    {
        if ($hook !== 'toplevel_page_silent-trust') {
            return;
        }

        wp_enqueue_style(
            'silent-trust-admin',
            SILENT_TRUST_PLUGIN_URL . 'admin/assets/css/admin.css',
            [],
            SILENT_TRUST_VERSION
        );

        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );

        wp_enqueue_script(
            'silent-trust-admin-js',
            SILENT_TRUST_PLUGIN_URL . 'admin/assets/js/admin.js',
            ['jquery', 'chart-js'],
            SILENT_TRUST_VERSION,
            true
        );

        wp_localize_script('silent-trust-admin-js', 'stAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('st_admin_nonce')
        ]);
    }

    /**
     * Render dashboard page (tab router)
     */
    public function render_dashboard()
    {
        $active_tab = $_GET['tab'] ?? 'dashboard';

        ?>
        <div class="wrap">
            <h1>Silent Trust - Anti-Spam Protection</h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=silent-trust&tab=dashboard"
                    class="nav-tab <?php echo esc_attr($active_tab === 'dashboard' ? 'nav-tab-active' : ''); ?>">Dashboard</a>
                <a href="?page=silent-trust&tab=ip-inspector"
                    class="nav-tab <?php echo esc_attr($active_tab === 'ip-inspector' ? 'nav-tab-active' : ''); ?>">IP
                    Inspector</a>
                <a href="?page=silent-trust&tab=settings"
                    class="nav-tab <?php echo esc_attr($active_tab === 'settings' ? 'nav-tab-active' : ''); ?>">Settings</a>
                <a href="?page=silent-trust&tab=logs"
                    class="nav-tab <?php echo esc_attr($active_tab === 'logs' ? 'nav-tab-active' : ''); ?>">Logs</a>
                <a href="?page=silent-trust&tab=formdata"
                    class="nav-tab <?php echo esc_attr($active_tab === 'formdata' ? 'nav-tab-active' : ''); ?>">Form Data</a>
            </nav>

            <div class="st-tab-content">
                <?php
                switch ($active_tab) {
                    case 'ip-inspector':
                        Tab_IP_Inspector::render();
                        break;
                    case 'settings':
                        Tab_Settings::render();
                        break;
                    case 'logs':
                        Tab_Logs::render();
                        break;
                    case 'formdata':
                        Tab_FormData::render();
                        break;
                    default:
                        Tab_Dashboard::render();
                }
                ?>
            </div>
        </div>
        <?php
    }
}
