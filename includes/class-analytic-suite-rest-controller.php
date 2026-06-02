<?php
/**
 * REST API controller — BigQuery sync endpoints.
 *
 * @package Analytic_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes flat-row sync endpoints consumed by the n8n → BigQuery pipeline.
 *
 * Authentication: WordPress Application Passwords (Basic Auth).
 * Required capability: analytic_suite_view_analytics (read) / analytic_suite_manage_analytics (write).
 */
class Analytic_Suite_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private $namespace = 'analytic-suite/v1';

	/**
	 * Dashboard service (used for GA cache clear).
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
		$sync_routes = array(
			'/sync/masterclass-registrations' => 'sync_masterclass_registrations',
			'/sync/expert-sessions'           => 'sync_expert_sessions',
			'/sync/orders'                    => 'sync_orders',
			'/sync/users'                     => 'sync_users',
		);

		foreach ( $sync_routes as $route => $callback ) {
			register_rest_route(
				$this->namespace,
				$route,
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'can_read_analytics' ),
					'args'                => $this->get_sync_args(),
				)
			);
		}

		register_rest_route(
			$this->namespace,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'can_read_analytics' ),
			)
		);

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

	// -------------------------------------------------------------------------
	// Sync endpoints
	// -------------------------------------------------------------------------

	/**
	 * Returns masterclass registration rows.
	 * Maps to BigQuery table: masterclass_registrations.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function sync_masterclass_registrations( $request ) {
		$filters = $this->get_sync_filters( $request );
		$repo    = new Analytic_Suite_Content_Repository();
		$result  = $repo->get_masterclass_rows( $filters );

		return $this->paginated_response( $result, $filters, 'wp_user_masterclass' );
	}

	/**
	 * Returns expert session (booking) rows.
	 * Maps to BigQuery table: expert_sessions.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function sync_expert_sessions( $request ) {
		$filters = $this->get_sync_filters( $request );
		$repo    = new Analytic_Suite_Booking_Repository();
		$result  = $repo->get_export_rows( $filters );

		return $this->paginated_response( $result, $filters, 'fcal_bookings' );
	}

	/**
	 * Returns WooCommerce order rows.
	 * Maps to BigQuery table: wc_orders.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function sync_orders( $request ) {
		$filters = $this->get_sync_filters( $request );
		$repo    = new Analytic_Suite_Order_Repository();
		$result  = $repo->get_export_rows( $filters );

		return $this->paginated_response( $result, $filters, 'wc_orders' );
	}

	/**
	 * Returns WordPress user rows.
	 * Maps to BigQuery table: wp_users.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function sync_users( $request ) {
		$filters = $this->get_sync_filters( $request );
		$repo    = new Analytic_Suite_User_Repository();
		$result  = $repo->get_export_rows( $filters );

		return $this->paginated_response( $result, $filters, 'wp_users' );
	}

	// -------------------------------------------------------------------------
	// Utility endpoints
	// -------------------------------------------------------------------------

	/**
	 * Returns integration status — used by n8n as health check.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status() {
		global $wpdb;

		$ga         = new Analytic_Suite_Google_Analytics();
		$wc_active  = function_exists( 'wc_get_orders' );

		$fb_table      = $this->find_first_existing_table( array(
			'fcal_bookings',
			'fluent_booking_appointments',
			'fluentcalendar_bookings',
			'fluent_bookings',
			'fcal_appointments',
		) );

		$mc_exists  = $this->table_exists( 'user_masterclass' );
		$lb_exists  = $this->table_exists( 'user_livres' );

		return rest_ensure_response( array(
			'plugin_version' => ANALYTIC_SUITE_VERSION,
			'generated_at'   => gmdate( 'c' ),
			'last_sync'      => get_option( 'analytic_suite_last_sync', null ),
			'integrations'   => array(
				'woocommerce'      => array( 'available' => $wc_active ),
				'fluentbooking'    => array( 'available' => '' !== $fb_table, 'table' => $fb_table ?: null ),
				'user_masterclass' => array( 'available' => $mc_exists ),
				'user_livres'      => array( 'available' => $lb_exists ),
				'google_analytics' => $ga->get_status(),
			),
			'sync_endpoints' => array(
				$this->namespace . '/sync/masterclass-registrations',
				$this->namespace . '/sync/expert-sessions',
				$this->namespace . '/sync/orders',
				$this->namespace . '/sync/users',
			),
		) );
	}

	/**
	 * Clears GA4 response cache.
	 *
	 * @return WP_REST_Response
	 */
	public function clear_google_analytics_cache() {
		$this->dashboard_service->clear_ga_cache();

		return rest_ensure_response( array(
			'success' => true,
			'message' => __( 'Cache Google Analytics vidé.', 'analytic-suite' ),
		) );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Extracts and sanitizes sync filter params from the request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array { updated_after: string, per_page: int, page: int }
	 */
	private function get_sync_filters( WP_REST_Request $request ) {
		$per_page = min( 500, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 200 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );

		$updated_after = '';
		$raw           = $request->get_param( 'updated_after' );

		if ( $raw ) {
			$ts = strtotime( sanitize_text_field( $raw ) );
			if ( $ts ) {
				$updated_after = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		return array(
			'updated_after' => $updated_after,
			'per_page'      => $per_page,
			'page'          => $page,
		);
	}

	/**
	 * Wraps a repository result in a paginated response envelope.
	 *
	 * @param array  $result  { total: int, rows: array }
	 * @param array  $filters Sync filters.
	 * @param string $source  Source table identifier.
	 * @return WP_REST_Response
	 */
	private function paginated_response( $result, $filters, $source ) {
		$total       = (int) $result['total'];
		$per_page    = (int) $filters['per_page'];
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

		return rest_ensure_response( array(
			'meta' => array(
				'total'        => $total,
				'page'         => (int) $filters['page'],
				'per_page'     => $per_page,
				'total_pages'  => $total_pages,
				'generated_at' => gmdate( 'c' ),
				'source'       => $source,
			),
			'rows' => $result['rows'],
		) );
	}

	/**
	 * Returns REST arg definitions for sync endpoints.
	 *
	 * @return array
	 */
	private function get_sync_args() {
		return array(
			'updated_after' => array(
				'description'       => __( 'ISO 8601 datetime. Retourne uniquement les enregistrements créés/modifiés après cette date (ex: 2026-06-01T00:00:00Z).', 'analytic-suite' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'per_page'      => array(
				'description'       => __( 'Nombre de lignes par page. Max 500, défaut 200.', 'analytic-suite' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 200,
			),
			'page'          => array(
				'description'       => __( 'Numéro de page (base 1).', 'analytic-suite' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 1,
			),
		);
	}

	/**
	 * Checks if a table with the given slug (without prefix) exists.
	 *
	 * @param string $slug Table slug.
	 * @return bool
	 */
	private function table_exists( $slug ) {
		global $wpdb;
		$table = $wpdb->prefix . $slug;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Returns the first existing table from a list of slugs (without prefix).
	 *
	 * @param string[] $slugs Table slugs.
	 * @return string Full table name, or empty string.
	 */
	private function find_first_existing_table( $slugs ) {
		global $wpdb;
		foreach ( $slugs as $slug ) {
			$table = $wpdb->prefix . $slug;
			if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				return $table;
			}
		}
		return '';
	}
}
