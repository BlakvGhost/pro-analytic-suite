<?php
/**
 * Export handling.
 *
 * @package Analytic_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles report exports.
 */
class Analytic_Suite_Export_Controller {

    /**
     * Dashboard service.
     *
     * @var Analytic_Suite_Dashboard_Service
     */
    private $dashboard_service;

    /**
     * Constructor.
     *
     * @param Analytic_Suite_Dashboard_Service $dashboard_service Dashboard service.
     */
    public function __construct( Analytic_Suite_Dashboard_Service $dashboard_service ) {
        $this->dashboard_service = $dashboard_service;
    }

    /**
     * Streams a CSV export.
     */
    public function export_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'analytic-suite' ) );
        }

        check_admin_referer( 'analytic_suite_export_csv' );

        $filters  = $this->dashboard_service->get_filters_from_request( $_POST );
        $rows     = $this->dashboard_service->get_export_rows( $filters );
        $filename = 'analytics-' . current_time( 'Y-m-d-His' ) . '.csv';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $output = fopen( 'php://output', 'w' );

        foreach ( $rows as $row ) {
            fputcsv( $output, $row );
        }

        fclose( $output );
        exit;
    }
}
