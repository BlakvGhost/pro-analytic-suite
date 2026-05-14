<?php
/**
 * Main plugin container.
 *
 * @package Analytic_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Wires services and WordPress hooks.
 */
class Analytic_Suite {

    /**
     * Registers plugin hooks.
     */
    public function run() {
        $this->load_dependencies();

        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_post_analytic_suite_export_csv', array( $this, 'export_csv' ) );
        add_action( 'analytic_suite_daily_sync', array( $this, 'run_daily_sync' ) );
    }

    /**
     * Loads required class files.
     */
    private function load_dependencies() {
        require_once ANALYTIC_SUITE_PATH . 'includes/repositories/class-analytic-suite-order-repository.php';
        require_once ANALYTIC_SUITE_PATH . 'includes/repositories/class-analytic-suite-booking-repository.php';
        require_once ANALYTIC_SUITE_PATH . 'includes/class-analytic-suite-dashboard-service.php';
        require_once ANALYTIC_SUITE_PATH . 'admin/class-analytic-suite-admin.php';
        require_once ANALYTIC_SUITE_PATH . 'admin/class-analytic-suite-export-controller.php';
    }

    /**
     * Loads translations.
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'analytic-suite', false, dirname( plugin_basename( ANALYTIC_SUITE_FILE ) ) . '/languages' );
    }

    /**
     * Registers admin pages.
     */
    public function register_admin_menu() {
        $admin = new Analytic_Suite_Admin( $this->get_dashboard_service() );
        $admin->register_menu();
    }

    /**
     * Enqueues assets on plugin admin screens.
     *
     * @param string $hook_suffix Current admin hook.
     */
    public function enqueue_admin_assets( $hook_suffix ) {
        if ( false === strpos( $hook_suffix, 'analytic-suite' ) ) {
            return;
        }

        wp_enqueue_style(
            'analytic-suite-admin',
            ANALYTIC_SUITE_URL . 'assets/css/admin.css',
            array(),
            ANALYTIC_SUITE_VERSION
        );

        wp_enqueue_script(
            'analytic-suite-admin',
            ANALYTIC_SUITE_URL . 'assets/js/admin.js',
            array(),
            ANALYTIC_SUITE_VERSION,
            true
        );
    }

    /**
     * Handles CSV exports.
     */
    public function export_csv() {
        $controller = new Analytic_Suite_Export_Controller( $this->get_dashboard_service() );
        $controller->export_csv();
    }

    /**
     * Placeholder for future precomputed analytics sync.
     */
    public function run_daily_sync() {
        update_option( 'analytic_suite_last_sync', current_time( 'mysql' ) );
    }

    /**
     * Builds the dashboard service.
     *
     * @return Analytic_Suite_Dashboard_Service
     */
    private function get_dashboard_service() {
        return new Analytic_Suite_Dashboard_Service(
            new Analytic_Suite_Order_Repository(),
            new Analytic_Suite_Booking_Repository()
        );
    }
}
