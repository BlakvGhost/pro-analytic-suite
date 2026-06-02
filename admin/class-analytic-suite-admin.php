<?php
/**
 * Admin settings page.
 *
 * @package Analytic_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Settings admin page — the only WordPress admin UI.
 * All analytics data is exposed exclusively via the REST API.
 */
class Analytic_Suite_Admin {

	/**
	 * Registers menu — Settings only.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Pro Analytics', 'analytic-suite' ),
			__( 'Pro Analytics', 'analytic-suite' ),
			'analytic_suite_manage_analytics',
			'analytic-suite',
			array( $this, 'render_settings_page' ),
			'dashicons-rest-api',
			56
		);
	}

	/**
	 * Renders the settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'analytic_suite_manage_analytics' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'analytic-suite' ) );
		}

		echo '<div class="wrap analytic-suite">';
		$this->render_page_header();
		$this->render_status_panel();
		$this->render_ga_settings();
		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Status panel
	// -------------------------------------------------------------------------

	/**
	 * Renders integration status and API endpoint listing.
	 */
	private function render_status_panel() {
		global $wpdb;

		$wc_ok    = function_exists( 'wc_get_orders' );
		$fb_table = $this->find_first_fb_table();
		$mc_table = $wpdb->prefix . 'user_masterclass';
		$mc_ok    = $mc_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $mc_table ) );
		$lb_table = $wpdb->prefix . 'user_livres';
		$lb_ok    = $lb_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lb_table ) );

		echo '<div class="analytic-suite-panel">';
		echo '<h2>' . esc_html__( 'État des intégrations', 'analytic-suite' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		$this->render_status_row( __( 'WooCommerce', 'analytic-suite' ), $wc_ok );
		$this->render_status_row( __( 'FluentBooking', 'analytic-suite' ), '' !== $fb_table, $fb_table );
		$this->render_status_row( __( 'Table wp_user_masterclass', 'analytic-suite' ), $mc_ok );
		$this->render_status_row( __( 'Table wp_user_livres', 'analytic-suite' ), $lb_ok );
		echo '<tr><th>' . esc_html__( 'Dernière synchronisation', 'analytic-suite' ) . '</th>';
		echo '<td>' . esc_html( get_option( 'analytic_suite_last_sync', __( 'Jamais', 'analytic-suite' ) ) ) . '</td></tr>';
		echo '</tbody></table>';
		echo '</div>';

		echo '<div class="analytic-suite-panel">';
		echo '<h2>' . esc_html__( 'Endpoints REST disponibles', 'analytic-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Authentification : WordPress Application Passwords (Basic Auth).', 'analytic-suite' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Endpoint', 'analytic-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Usage', 'analytic-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		$base      = rest_url( 'analytic-suite/v1' );
		$endpoints = array(
			'/dashboard'                      => __( 'Toutes les métriques agrégées (filtrables)', 'analytic-suite' ),
			'/summary'                        => __( 'Indicateurs clés de synthèse', 'analytic-suite' ),
			'/bookings'                       => __( 'Réservations : catégories, durées, pays, civilité…', 'analytic-suite' ),
			'/orders'                         => __( 'Commandes WooCommerce : CA, produits, statuts…', 'analytic-suite' ),
			'/contents'                       => __( 'Contenus : masterclass, livres, progression', 'analytic-suite' ),
			'/filters'                        => __( 'Options de filtres disponibles', 'analytic-suite' ),
			'/status'                         => __( 'Health check — état des intégrations', 'analytic-suite' ),
			'/sync/masterclass-registrations' => __( 'Lignes brutes → BigQuery masterclass_registrations', 'analytic-suite' ),
			'/sync/expert-sessions'           => __( 'Lignes brutes → BigQuery expert_sessions', 'analytic-suite' ),
			'/sync/orders'                    => __( 'Lignes brutes → BigQuery wc_orders', 'analytic-suite' ),
			'/sync/users'                     => __( 'Lignes brutes → BigQuery wp_users', 'analytic-suite' ),
		);

		foreach ( $endpoints as $path => $label ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( $base . $path ) . '</code></td>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Renders a status table row.
	 *
	 * @param string $label  Row label.
	 * @param bool   $ok     Status flag.
	 * @param string $detail Optional detail text shown when ok.
	 */
	private function render_status_row( $label, $ok, $detail = '' ) {
		$status = $ok
			? '<span style="color:#0f766e;font-weight:700;">&#10003; ' . esc_html__( 'Détecté', 'analytic-suite' ) . '</span>'
			: '<span style="color:#b42318;">&#10007; ' . esc_html__( 'Non détecté', 'analytic-suite' ) . '</span>';

		echo '<tr><th>' . esc_html( $label ) . '</th><td>' . wp_kses_post( $status );
		if ( $ok && '' !== $detail ) {
			echo ' <code>' . esc_html( $detail ) . '</code>';
		}
		echo '</td></tr>';
	}

	/**
	 * Returns the first detected FluentBooking table name.
	 *
	 * @return string Full table name or empty string.
	 */
	private function find_first_fb_table() {
		global $wpdb;

		$known = array( 'fcal_bookings', 'fluent_booking_appointments', 'fluentcalendar_bookings', 'fluent_bookings', 'fcal_appointments' );

		foreach ( $known as $slug ) {
			$t = $wpdb->prefix . $slug;
			if ( $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
				return $t;
			}
		}

		return '';
	}

	// -------------------------------------------------------------------------
	// GA settings
	// -------------------------------------------------------------------------

	/**
	 * Renders and processes the Google Analytics settings form.
	 */
	private function render_ga_settings() {
		echo '<div class="analytic-suite-panel">';
		echo '<h2>' . esc_html__( 'Google Analytics 4', 'analytic-suite' ) . '</h2>';

		if ( isset( $_POST['analytic_suite_save_ga'] ) && check_admin_referer( 'analytic_suite_ga_settings' ) ) {
			update_option( 'analytic_suite_ga_property_id',   sanitize_text_field( wp_unslash( $_POST['ga_property_id']   ?? '' ) ) );
			update_option( 'analytic_suite_ga_client_id',     sanitize_text_field( wp_unslash( $_POST['ga_client_id']     ?? '' ) ) );
			update_option( 'analytic_suite_ga_client_secret', sanitize_text_field( wp_unslash( $_POST['ga_client_secret'] ?? '' ) ) );

			$refresh_token = sanitize_text_field( wp_unslash( $_POST['ga_refresh_token'] ?? '' ) );
			if ( ! empty( $refresh_token ) ) {
				update_option( 'analytic_suite_ga_refresh_token', $refresh_token );
			}

			$this->save_appearance_settings( $_POST );

			$ga = new Analytic_Suite_Google_Analytics();
			$ga->clear_cache();

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Paramètres enregistrés.', 'analytic-suite' ) . '</p></div>';
		}

		$ga   = new Analytic_Suite_Google_Analytics();
		$test = $ga->test_connection();

		echo '<form method="post">';
		wp_nonce_field( 'analytic_suite_ga_settings' );

		echo '<table class="widefat"><tbody>';

		echo '<tr><th>' . esc_html__( 'Property ID GA4', 'analytic-suite' ) . '</th>';
		echo '<td><input type="text" name="ga_property_id" value="' . esc_attr( get_option( 'analytic_suite_ga_property_id', '' ) ) . '" class="regular-text" placeholder="XXXXXXXXX">';
		echo '<p class="description">' . esc_html__( 'Ex : 1234567890 (GA4 > Administration > Propriété)', 'analytic-suite' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Client ID OAuth2', 'analytic-suite' ) . '</th>';
		echo '<td><input type="text" name="ga_client_id" value="' . esc_attr( get_option( 'analytic_suite_ga_client_id', '' ) ) . '" class="large-text" placeholder="XXXXXXX.apps.googleusercontent.com">';
		echo '<p class="description">' . esc_html__( 'Client ID de votre application OAuth2 (Google Cloud Console > Identifiants).', 'analytic-suite' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Client Secret OAuth2', 'analytic-suite' ) . '</th>';
		echo '<td><input type="password" name="ga_client_secret" value="' . esc_attr( get_option( 'analytic_suite_ga_client_secret', '' ) ) . '" class="large-text">';
		echo '<p class="description">' . esc_html__( 'Client Secret de votre application OAuth2.', 'analytic-suite' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Refresh Token', 'analytic-suite' ) . '</th>';
		$has_token = ! empty( get_option( 'analytic_suite_ga_refresh_token', '' ) );
		echo '<td><input type="password" name="ga_refresh_token" value="" class="large-text"';
		if ( $has_token ) {
			echo ' placeholder="' . esc_attr__( '(token enregistré — laisser vide pour conserver)', 'analytic-suite' ) . '"';
		}
		echo '>';
		echo '<p class="description">' . esc_html__( 'Laisser vide pour conserver le token actuel.', 'analytic-suite' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Statut connexion', 'analytic-suite' ) . '</th><td>';
		if ( $test['success'] ) {
			echo '<span style="color:#0f766e;font-weight:700;">&#10003; ' . esc_html( $test['message'] ) . '</span>';
		} else {
			echo '<span style="color:#b42318;">&#10007; ' . esc_html( $test['message'] ) . '</span>';
		}
		echo '</td></tr>';

		echo '<tr><th></th><td><label><input type="checkbox" name="ga_clear_cache" value="1"> ';
		echo esc_html__( 'Vider le cache GA au save', 'analytic-suite' ) . '</label></td></tr>';

		echo '</tbody></table>';

		$this->render_appearance_settings();

		submit_button( __( 'Enregistrer', 'analytic-suite' ), 'primary', 'analytic_suite_save_ga', false );
		echo '</form></div>';
	}

	/**
	 * Renders appearance color pickers.
	 */
	private function render_appearance_settings() {
		echo '<div class="analytic-suite-settings-section">';
		echo '<h2>' . esc_html__( 'Apparence', 'analytic-suite' ) . '</h2>';
		echo '<table class="widefat"><tbody>';
		$this->render_color_setting( 'analytic_suite_color_primary', __( 'Couleur principale', 'analytic-suite' ), '#0f766e' );
		$this->render_color_setting( 'analytic_suite_color_accent',  __( 'Couleur accent', 'analytic-suite' ),     '#d69a3a' );
		$this->render_color_setting( 'analytic_suite_color_header',  __( 'Fond du header admin', 'analytic-suite' ), '#10231f' );
		$this->render_color_setting( 'analytic_suite_color_surface', __( 'Surface des cartes', 'analytic-suite' ), '#ffffff' );
		echo '<tr><th>' . esc_html__( 'Badge du header', 'analytic-suite' ) . '</th>';
		echo '<td><input type="text" name="analytic_suite_header_badge" value="' . esc_attr( get_option( 'analytic_suite_header_badge', 'Pro Analytics' ) ) . '" class="regular-text"></td></tr>';
		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Saves appearance options from posted data.
	 *
	 * @param array $source $_POST values.
	 */
	private function save_appearance_settings( $source ) {
		$defaults = array(
			'analytic_suite_color_primary' => '#0f766e',
			'analytic_suite_color_accent'  => '#d69a3a',
			'analytic_suite_color_header'  => '#10231f',
			'analytic_suite_color_surface' => '#ffffff',
		);

		foreach ( $defaults as $option => $default ) {
			$value = sanitize_hex_color( wp_unslash( $source[ $option ] ?? $default ) );
			update_option( $option, $value ? $value : $default );
		}

		update_option(
			'analytic_suite_header_badge',
			sanitize_text_field( wp_unslash( $source['analytic_suite_header_badge'] ?? 'Pro Analytics' ) )
		);
	}

	/**
	 * Renders a color picker row.
	 *
	 * @param string $option  Option name.
	 * @param string $label   Row label.
	 * @param string $default Default hex color.
	 */
	private function render_color_setting( $option, $label, $default ) {
		$value = sanitize_hex_color( get_option( $option, $default ) );
		echo '<tr><th>' . esc_html( $label ) . '</th>';
		echo '<td><input type="color" name="' . esc_attr( $option ) . '" value="' . esc_attr( $value ? $value : $default ) . '"></td></tr>';
	}

	/**
	 * Renders the page header.
	 */
	private function render_page_header() {
		$badge = get_option( 'analytic_suite_header_badge', __( 'Pro Analytics', 'analytic-suite' ) );

		echo '<header class="analytic-suite-header">';
		echo '<div>';
		echo '<h1>' . esc_html__( 'Paramètres Analytics', 'analytic-suite' ) . '</h1>';
		echo '<p>' . esc_html__( 'Configuration des intégrations, GA4 et endpoints REST de synchronisation BigQuery.', 'analytic-suite' ) . '</p>';
		echo '</div>';
		echo '<div class="analytic-suite-header-meta">';
		echo '<span>' . esc_html( $badge ) . '</span>';
		echo '<strong>' . esc_html( sprintf( __( 'Version %s', 'analytic-suite' ), ANALYTIC_SUITE_VERSION ) ) . '</strong>';
		echo '</div>';
		echo '</header>';
	}
}
