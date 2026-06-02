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
	 * Admin settings renderer.
	 *
	 * @var Analytic_Suite_Admin
	 */
	private $admin;

	/**
	 * Registers plugin hooks.
	 */
	public function run() {
		$this->load_dependencies();

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this, 'ensure_capabilities' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_head', array( $this, 'suppress_admin_notices' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'analytic_suite_daily_sync', array( $this, 'run_daily_sync' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ANALYTIC_SUITE_FILE ), array( $this, 'add_plugin_action_links' ) );
	}

	/**
	 * Loads required class files.
	 */
	private function load_dependencies() {
		require_once ANALYTIC_SUITE_PATH . 'includes/repositories/class-analytic-suite-order-repository.php';
		require_once ANALYTIC_SUITE_PATH . 'includes/repositories/class-analytic-suite-booking-repository.php';
		require_once ANALYTIC_SUITE_PATH . 'includes/repositories/class-analytic-suite-content-repository.php';
		require_once ANALYTIC_SUITE_PATH . 'includes/repositories/class-analytic-suite-user-repository.php';
		require_once ANALYTIC_SUITE_PATH . 'includes/services/class-analytic-suite-google-analytics.php';
		require_once ANALYTIC_SUITE_PATH . 'includes/class-analytic-suite-dashboard-service.php';
		require_once ANALYTIC_SUITE_PATH . 'includes/class-analytic-suite-rest-controller.php';
		require_once ANALYTIC_SUITE_PATH . 'admin/class-analytic-suite-admin.php';
	}

	/**
	 * Loads translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'analytic-suite', false, dirname( plugin_basename( ANALYTIC_SUITE_FILE ) ) . '/languages' );
	}

	/**
	 * Registers the Settings admin page.
	 */
	public function register_admin_menu() {
		$this->admin = new Analytic_Suite_Admin();
		$this->admin->register_menu();
	}

	/**
	 * Ensures access capabilities exist for already-activated installs.
	 */
	public function ensure_capabilities() {
		Analytic_Suite_Activator::add_capabilities();

		$user = wp_get_current_user();
		if ( $user instanceof WP_User ) {
			$user->get_role_caps();
		}
	}

	/**
	 * Adds Settings shortcut on the plugins page.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_plugin_action_links( $links ) {
		if ( current_user_can( 'analytic_suite_manage_analytics' ) ) {
			$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=analytic-suite' ) ) . '">' . esc_html__( 'Paramètres', 'analytic-suite' ) . '</a>';
			array_unshift( $links, $settings_link );
		}

		return $links;
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

		wp_add_inline_style( 'analytic-suite-admin', $this->get_appearance_css() );
	}

	/**
	 * Removes all admin notices on plugin pages to keep the settings page clean.
	 */
	public function suppress_admin_notices() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( false === strpos( $page, 'analytic-suite' ) ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
	}

	/**
	 * Registers REST API routes.
	 */
	public function register_rest_routes() {
		$controller = new Analytic_Suite_REST_Controller( $this->get_dashboard_service() );
		$controller->register_routes();
	}

	/**
	 * Updates the last sync timestamp (placeholder for future precomputed sync).
	 */
	public function run_daily_sync() {
		update_option( 'analytic_suite_last_sync', current_time( 'mysql' ) );
	}

	/**
	 * Builds CSS custom properties from appearance settings.
	 *
	 * @return string
	 */
	private function get_appearance_css() {
		$primary = $this->sanitize_hex_option( 'analytic_suite_color_primary', '#0f766e' );
		$accent  = $this->sanitize_hex_option( 'analytic_suite_color_accent', '#d69a3a' );
		$header  = $this->sanitize_hex_option( 'analytic_suite_color_header', '#10231f' );
		$surface = $this->sanitize_hex_option( 'analytic_suite_color_surface', '#ffffff' );

		return sprintf(
			'.analytic-suite{--as-primary:%1$s;--as-primary-dark:%2$s;--as-accent:%3$s;--as-surface:%4$s;--as-header-bg:%5$s;}',
			esc_html( $primary ),
			esc_html( $this->darken_hex_color( $primary, 18 ) ),
			esc_html( $accent ),
			esc_html( $surface ),
			esc_html( $header )
		);
	}

	/**
	 * Gets a sanitized hex color option.
	 *
	 * @param string $option  Option name.
	 * @param string $default Default value.
	 * @return string
	 */
	private function sanitize_hex_option( $option, $default ) {
		$value = sanitize_hex_color( get_option( $option, $default ) );
		return $value ? $value : $default;
	}

	/**
	 * Darkens a hex color by a given percentage.
	 *
	 * @param string $hex     Hex color.
	 * @param int    $percent Darken percent (0-100).
	 * @return string
	 */
	private function darken_hex_color( $hex, $percent ) {
		$hex     = ltrim( $hex, '#' );
		$percent = max( 0, min( 100, (int) $percent ) );
		$factor  = ( 100 - $percent ) / 100;

		$red   = (int) floor( hexdec( substr( $hex, 0, 2 ) ) * $factor );
		$green = (int) floor( hexdec( substr( $hex, 2, 2 ) ) * $factor );
		$blue  = (int) floor( hexdec( substr( $hex, 4, 2 ) ) * $factor );

		return sprintf( '#%02x%02x%02x', $red, $green, $blue );
	}

	/**
	 * Builds the dashboard service.
	 *
	 * @return Analytic_Suite_Dashboard_Service
	 */
	private function get_dashboard_service() {
		return new Analytic_Suite_Dashboard_Service(
			new Analytic_Suite_Order_Repository(),
			new Analytic_Suite_Booking_Repository(),
			new Analytic_Suite_Content_Repository()
		);
	}
}
