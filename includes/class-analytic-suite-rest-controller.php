<?php
/**
 * REST API controller.
 *
 * @package Analytic_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Exposes dashboard analytics through WordPress REST API routes.
 */
class Analytic_Suite_REST_Controller {

    /**
     * REST namespace.
     *
     * @var string
     */
    private $namespace = 'analytic-suite/v1';

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
     * Registers REST routes.
     */
    public function register_routes() {
        $read_routes = array(
            '/dashboard'        => 'get_dashboard',
            '/summary'          => 'get_summary',
            '/clients'          => 'get_clients',
            '/orders'           => 'get_orders',
            '/bookings'         => 'get_bookings',
            '/contents'         => 'get_contents',
            '/google-analytics' => 'get_google_analytics',
            '/ga'               => 'get_google_analytics',
            '/reports'          => 'get_reports',
            '/export-rows'      => 'get_export_rows',
            '/status'           => 'get_status',
            '/filters'          => 'get_filter_options_response',
            '/public'           => 'get_public',
        );

        foreach ( $read_routes as $route => $callback ) {
            register_rest_route(
                $this->namespace,
                $route,
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, $callback ),
                    'permission_callback' => array( $this, 'can_read_analytics' ),
                    'args'                => $this->get_filter_args(),
                )
            );
        }

        register_rest_route(
            $this->namespace,
            '/google-analytics/cache',
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'clear_google_analytics_cache' ),
                'permission_callback' => array( $this, 'can_manage_analytics' ),
            )
        );
    }

    /**
     * Checks read permission.
     *
     * @return bool
     */
    public function can_read_analytics() {
        return current_user_can( 'analytic_suite_view_analytics' );
    }

    /**
     * Checks management permission.
     *
     * @return bool
     */
    public function can_manage_analytics() {
        return current_user_can( 'analytic_suite_manage_analytics' );
    }

    /**
     * Gets all dashboard data.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function get_dashboard( $request ) {
        $filters = $this->normalize_filters( $request );
        $data    = $this->dashboard_service->get_dashboard_data( $filters );

        return $this->respond(
            array(
                'filters' => $filters,
                'data'    => $data,
            )
        );
    }

    /**
     * Gets summary metrics.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function get_summary( $request ) {
        $payload = $this->get_dashboard_payload( $request );

        return $this->respond(
            array(
                'filters' => $payload['filters'],
                'summary' => $payload['data']['summary'],
            )
        );
    }

    /**
     * Gets client-focused metrics.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function get_clients( $request ) {
        $payload = $this->get_dashboard_payload( $request );
        $data    = $payload['data'];

        return $this->respond(
            array(
                'filters' => $payload['filters'],
                'clients' => array(
                    'unique_customers'          => $data['summary']['unique_customers'],
                    'recurring_customers'       => $data['summary']['recurring_customers'],
                    'repeat_product_customers'  => $data['summary']['repeat_product_customers'],
                    'retention_rate'            => $data['orders']['retention_rate'],
                    'order_gender_breakdown'    => $data['orders']['gender_breakdown'],
                    'booking_gender_breakdown'  => $data['bookings']['gender_breakdown'],
                    'booking_country_breakdown' => $data['bookings']['country_breakdown'],
                    'order_customer_emails'     => $data['orders']['customer_emails'],
                    'booking_customer_emails'   => $data['bookings']['customer_emails'],
                ),
            )
        );
    }

    /**
     * Gets order metrics.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function get_orders( $request ) {
        return $this->respond_section( $request, 'orders' );
    }

    /**
     * Gets booking metrics.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function get_bookings( $request ) {
        return $this->respond_section( $request, 'bookings' );
    }

    /**
     * Gets content metrics.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function get_contents( $request ) {
        return $this->respond_section( $request, 'contents' );
    }

    /**
     * Gets GA4 metrics.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function get_google_analytics( $request ) {
        return $this->respond_section( $request, 'ga' );
    }

    /**
     * Gets report-ready dashboard data.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function get_reports( $request ) {
        $payload = $this->get_dashboard_payload( $request );

        return $this->respond(
            array(
                'filters'     => $payload['filters'],
                'data'        => $payload['data'],
                'export_rows' => $this->dashboard_service->get_export_rows( $payload['filters'] ),
            )
        );
    }

    /**
     * Gets export rows as JSON.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function get_export_rows( $request ) {
        $filters = $this->normalize_filters( $request );

        return $this->respond(
            array(
                'filters' => $filters,
                'rows'    => $this->dashboard_service->get_export_rows( $filters ),
            )
        );
    }

    /**
     * Gets integration and sync status.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function get_status( $request ) {
        $payload = $this->get_dashboard_payload( $request );
        $data    = $payload['data'];

        return $this->respond(
            array(
                'filters' => $payload['filters'],
                'status'  => array(
                    'woocommerce'       => array( 'available' => (bool) $data['orders']['available'] ),
                    'fluentbooking'     => array(
                        'available' => (bool) $data['bookings']['available'],
                        'table'     => $data['bookings']['table'],
                    ),
                    'contents'          => array(
                        'available'         => (bool) $data['contents']['available'],
                        'masterclass_table' => (bool) $data['contents']['masterclass_table'],
                        'books_table'       => (bool) $data['contents']['books_table'],
                    ),
                    'google_analytics'  => $data['ga_status'],
                    'last_sync'         => get_option( 'analytic_suite_last_sync', __( 'Jamais', 'analytic-suite' ) ),
                    'plugin_version'    => ANALYTIC_SUITE_VERSION,
                ),
            )
        );
    }

    /**
     * Gets available filter options.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function get_filter_options_response( $request ) {
        return $this->respond(
            array(
                'filters' => $this->normalize_filters( $request ),
                'options' => $this->dashboard_service->get_filter_options(),
            )
        );
    }

    /**
     * Gets public analytics data used by the shortcode.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function get_public( $request ) {
        $contents = new Analytic_Suite_Content_Repository();
        $ga       = new Analytic_Suite_Google_Analytics();
        $page     = $this->get_public_ga_page();
        $filters  = $this->normalize_filters( $request );

        if ( ! empty( $page['path'] ) ) {
            $filters['page_path'] = $page['path'];
        }

        return $this->respond(
            array(
                'filters'      => $filters,
                'public_page'  => $page,
                'demographics' => $contents->get_public_demographics(),
                'ga'           => array(
                    'configured'   => $ga->is_configured(),
                    'summary'      => $ga->is_configured() && ! empty( $page['path'] ) ? $ga->get_summary( $filters ) : array(),
                    'pages'        => $ga->is_configured() && ! empty( $page['path'] ) ? $ga->get_page_views( $filters ) : array(),
                    'demographics' => $ga->is_configured() && ! empty( $page['path'] ) ? $ga->get_demographics( $filters ) : array(),
                    'status'       => $ga->get_status(),
                ),
            )
        );
    }

    /**
     * Clears GA4 cache.
     *
     * @return WP_REST_Response
     */
    public function clear_google_analytics_cache() {
        $this->dashboard_service->clear_ga_cache();

        return $this->respond(
            array(
                'success' => true,
                'message' => __( 'Cache Google Analytics vidé.', 'analytic-suite' ),
            )
        );
    }

    /**
     * Responds with a dashboard section.
     *
     * @param WP_REST_Request $request REST request.
     * @param string          $section Section key.
     * @return WP_REST_Response
     */
    private function respond_section( $request, $section ) {
        $payload = $this->get_dashboard_payload( $request );

        return $this->respond(
            array(
                'filters' => $payload['filters'],
                $section  => $payload['data'][ $section ],
            )
        );
    }

    /**
     * Gets dashboard data with normalized filters.
     *
     * @param WP_REST_Request $request REST request.
     * @return array
     */
    private function get_dashboard_payload( $request ) {
        $filters = $this->normalize_filters( $request );

        return array(
            'filters' => $filters,
            'data'    => $this->dashboard_service->get_dashboard_data( $filters ),
        );
    }

    /**
     * Gets selected public GA page details.
     *
     * @return array
     */
    private function get_public_ga_page() {
        $page_id = absint( get_option( 'analytic_suite_public_ga_page_id', 0 ) );

        if ( ! $page_id ) {
            return array(
                'id'    => 0,
                'title' => '',
                'path'  => '',
            );
        }

        $permalink = get_permalink( $page_id );
        $path      = $permalink ? wp_parse_url( $permalink, PHP_URL_PATH ) : '';

        return array(
            'id'    => $page_id,
            'title' => get_the_title( $page_id ),
            'path'  => $path ? $path : '/',
        );
    }

    /**
     * Normalizes request filters through the dashboard service.
     *
     * @param WP_REST_Request $request REST request.
     * @return array
     */
    private function normalize_filters( $request ) {
        return $this->dashboard_service->get_filters_from_request( $request->get_params() );
    }

    /**
     * Builds route filter arguments.
     *
     * @return array
     */
    private function get_filter_args() {
        return array(
            'period' => array(
                'description'       => __( 'Période: all, 7-days, 30-days, year ou custom.', 'analytic-suite' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_key',
            ),
            'date_from' => array(
                'description'       => __( 'Date de début au format YYYY-MM-DD.', 'analytic-suite' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'date_to' => array(
                'description'       => __( 'Date de fin au format YYYY-MM-DD.', 'analytic-suite' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'country' => array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'status' => array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'booking_type' => array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'exclude_booking_type' => array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'duration' => array(
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ),
            'product' => array(
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ),
            'gender' => array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'customer' => array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'page_path' => array(
                'description'       => __( 'Chemin de page pour filtrer Google Analytics.', 'analytic-suite' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        );
    }

    /**
     * Wraps data in a REST response.
     *
     * @param array $payload Response payload.
     * @return WP_REST_Response
     */
    private function respond( $payload ) {
        return rest_ensure_response( $payload );
    }
}
