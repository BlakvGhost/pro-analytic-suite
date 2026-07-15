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
     * Admin page renderer.
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
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'admin_post_analytic_suite_export_csv', array( $this, 'export_csv' ) );
        add_action( 'admin_post_analytic_suite_export_excel', array( $this, 'export_excel' ) );
        add_action( 'admin_post_analytic_suite_export_pdf', array( $this, 'export_pdf' ) );
        add_action( 'analytic_suite_daily_sync', array( $this, 'run_daily_sync' ) );
        add_action( 'template_redirect', array( $this, 'handle_public_auth_form' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( ANALYTIC_SUITE_FILE ), array( $this, 'add_plugin_action_links' ) );
        add_shortcode( 'analytics_public', array( $this, 'render_public_analytics' ) );
    }

    /**
     * Loads required class files.
     */
    private function load_dependencies() {
        require_once ANALYTIC_SUITE_PATH . 'includes/repositories/class-analytic-suite-order-repository.php';
        require_once ANALYTIC_SUITE_PATH . 'includes/repositories/class-analytic-suite-booking-repository.php';
        require_once ANALYTIC_SUITE_PATH . 'includes/repositories/class-analytic-suite-content-repository.php';
        require_once ANALYTIC_SUITE_PATH . 'includes/services/class-analytic-suite-google-analytics.php';
        require_once ANALYTIC_SUITE_PATH . 'includes/class-analytic-suite-dashboard-service.php';
        require_once ANALYTIC_SUITE_PATH . 'includes/class-analytic-suite-rest-controller.php';
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
        $this->admin = new Analytic_Suite_Admin( $this->get_dashboard_service() );
        $this->admin->register_menu();
    }

    /**
     * Ensures access capabilities exist for already-activated installs.
     */
    public function ensure_capabilities() {
        if ( get_option( 'analytic_suite_caps_version' ) === ANALYTIC_SUITE_VERSION ) {
            return;
        }

        Analytic_Suite_Activator::add_capabilities();
        update_option( 'analytic_suite_caps_version', ANALYTIC_SUITE_VERSION, false );

        $user = wp_get_current_user();
        if ( $user instanceof WP_User ) {
            $user->get_role_caps();
        }
    }

    /**
     * Adds dashboard shortcut on the plugins page.
     *
     * @param array $links Existing action links.
     * @return array
     */
    public function add_plugin_action_links( $links ) {
        if ( current_user_can( 'analytic_suite_view_analytics' ) ) {
            $dashboard_link = '<a href="' . esc_url( admin_url( 'admin.php?page=analytic-suite' ) ) . '">' . esc_html__( 'Dashboard', 'analytic-suite' ) . '</a>';
            array_unshift( $links, $dashboard_link );
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

        wp_enqueue_script(
            'analytic-suite-admin',
            ANALYTIC_SUITE_URL . 'assets/js/admin.js',
            array(),
            ANALYTIC_SUITE_VERSION,
            true
        );
    }

    /**
     * Removes all admin notices on plugin pages to keep the dashboard clean.
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
     * Enqueues assets for the public analytics shortcode.
     */
    public function enqueue_public_assets() {
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'analytics_public' ) ) {
            return;
        }

        wp_enqueue_style(
            'analytic-suite-public',
            ANALYTIC_SUITE_URL . 'assets/css/admin.css',
            array(),
            ANALYTIC_SUITE_VERSION
        );

        wp_add_inline_style( 'analytic-suite-public', $this->get_appearance_css() );

        wp_enqueue_script(
            'analytic-suite-public',
            ANALYTIC_SUITE_URL . 'assets/js/admin.js',
            array(),
            ANALYTIC_SUITE_VERSION,
            true
        );
    }

    /**
     * Registers REST API routes.
     */
    public function register_rest_routes() {
        $controller = new Analytic_Suite_REST_Controller( $this->get_dashboard_service() );
        $controller->register_routes();
    }

    /**
     * Handles CSV exports.
     */
    public function export_csv() {
        $controller = new Analytic_Suite_Export_Controller( $this->get_dashboard_service() );
        $controller->export_csv();
    }

    /**
     * Builds CSS variables from appearance settings.
     *
     * @return string
     */
    private function get_appearance_css() {
        $primary = $this->sanitize_hex_option( 'analytic_suite_color_primary', '#0f766e' );
        $accent  = $this->sanitize_hex_option( 'analytic_suite_color_accent', '#d69a3a' );
        $header  = $this->sanitize_hex_option( 'analytic_suite_color_header', '#10231f' );
        $surface = $this->sanitize_hex_option( 'analytic_suite_color_surface', '#ffffff' );

        return sprintf(
            '.analytic-suite,.analytic-suite-public{--as-primary:%1$s;--as-primary-dark:%2$s;--as-accent:%3$s;--as-surface:%4$s;--as-header-bg:%5$s;--as-public-primary:%1$s;--as-public-accent:%3$s;--as-public-surface:%6$s;}',
            esc_html( $primary ),
            esc_html( $this->darken_hex_color( $primary, 18 ) ),
            esc_html( $accent ),
            esc_html( $surface ),
            esc_html( $header ),
            esc_html( $this->hex_to_rgba( $surface, 0.92 ) )
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
     * Darkens a hex color.
     *
     * @param string $hex     Hex color.
     * @param int    $percent Darken percent.
     * @return string
     */
    private function darken_hex_color( $hex, $percent ) {
        $hex = ltrim( $hex, '#' );
        $percent = max( 0, min( 100, (int) $percent ) );
        $factor = ( 100 - $percent ) / 100;

        $red   = (int) floor( hexdec( substr( $hex, 0, 2 ) ) * $factor );
        $green = (int) floor( hexdec( substr( $hex, 2, 2 ) ) * $factor );
        $blue  = (int) floor( hexdec( substr( $hex, 4, 2 ) ) * $factor );

        return sprintf( '#%02x%02x%02x', $red, $green, $blue );
    }

    /**
     * Converts a hex color to rgba.
     *
     * @param string $hex   Hex color.
     * @param float  $alpha Alpha.
     * @return string
     */
    private function hex_to_rgba( $hex, $alpha ) {
        $hex = ltrim( $hex, '#' );
        $alpha = max( 0, min( 1, (float) $alpha ) );

        return sprintf(
            'rgba(%d,%d,%d,%s)',
            hexdec( substr( $hex, 0, 2 ) ),
            hexdec( substr( $hex, 2, 2 ) ),
            hexdec( substr( $hex, 4, 2 ) ),
            rtrim( rtrim( number_format( $alpha, 2, '.', '' ), '0' ), '.' )
        );
    }

    /**
     * Handles Excel exports.
     */
    public function export_excel() {
        $controller = new Analytic_Suite_Export_Controller( $this->get_dashboard_service() );
        $controller->export_excel();
    }

    /**
     * Handles PDF exports.
     */
    public function export_pdf() {
        $controller = new Analytic_Suite_Export_Controller( $this->get_dashboard_service() );
        $controller->export_pdf();
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
            new Analytic_Suite_Booking_Repository(),
            new Analytic_Suite_Content_Repository()
        );
    }

    /**
     * Processes the public analytics password form (fires on template_redirect).
     */
    public function handle_public_auth_form() {
        if ( empty( $_POST['analytic_suite_pub_auth_action'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'analytic_suite_pub_auth' ) ) {
            wp_die( esc_html__( 'Erreur de sécurité.', 'analytic-suite' ) );
        }

        $password_hash = get_option( 'analytic_suite_public_password', '' );
        if ( empty( $password_hash ) ) {
            return;
        }

        $redirect  = esc_url_raw( wp_unslash( $_POST['analytic_suite_pub_redirect'] ?? '' ) );
        $redirect  = wp_validate_redirect( $redirect, home_url() );
        $submitted = wp_unslash( $_POST['analytic_suite_pub_password'] ?? '' );

        if ( wp_check_password( $submitted, $password_hash ) ) {
            setcookie(
                'analytic_suite_pub_auth',
                $this->get_public_auth_token(),
                time() + 30 * DAY_IN_SECONDS,
                COOKIEPATH,
                COOKIE_DOMAIN,
                is_ssl(),
                true
            );
            wp_redirect( remove_query_arg( 'as_auth_failed', $redirect ) );
            exit;
        }

        wp_redirect( add_query_arg( 'as_auth_failed', '1', $redirect ) );
        exit;
    }

    /**
     * Returns a deterministic signed token for the public auth cookie.
     *
     * @return string
     */
    private function get_public_auth_token() {
        return hash_hmac( 'sha256', 'analytic_suite_pub_auth_v1', wp_salt( 'auth' ) );
    }

    /**
     * Checks whether the current visitor holds a valid public auth cookie.
     *
     * @return bool
     */
    private function is_public_authenticated() {
        $cookie = sanitize_text_field( wp_unslash( $_COOKIE['analytic_suite_pub_auth'] ?? '' ) );
        return ! empty( $cookie ) && hash_equals( $this->get_public_auth_token(), $cookie );
    }

    /**
     * Returns the HTML password gate shown when the page is protected.
     *
     * @return string
     */
    private function render_public_auth_form() {
        $failed    = isset( $_GET['as_auth_failed'] );
        $permalink = get_permalink() ?: home_url();

        ob_start();
        ?>
        <div class="analytic-suite-public">
            <div class="as-auth-gate">
                <form class="as-auth-form" method="post">
                    <?php wp_nonce_field( 'analytic_suite_pub_auth' ); ?>
                    <input type="hidden" name="analytic_suite_pub_auth_action" value="1">
                    <input type="hidden" name="analytic_suite_pub_redirect" value="<?php echo esc_url( $permalink ); ?>">

                    <div class="as-auth-badge" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                    </div>

                    <span class="as-auth-kicker"><?php esc_html_e( 'Zone réservée', 'analytic-suite' ); ?></span>
                    <h2><?php esc_html_e( 'Accès protégé', 'analytic-suite' ); ?></h2>
                    <p><?php esc_html_e( 'Ce tableau de bord est accessible sur invitation. Saisissez le mot de passe pour continuer.', 'analytic-suite' ); ?></p>

                    <?php if ( $failed ) : ?>
                        <p class="as-auth-error">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                            <?php esc_html_e( 'Mot de passe incorrect. Veuillez réessayer.', 'analytic-suite' ); ?>
                        </p>
                    <?php endif; ?>

                    <div class="as-auth-field">
                        <input
                            type="password"
                            name="analytic_suite_pub_password"
                            placeholder="<?php esc_attr_e( 'Entrez le mot de passe…', 'analytic-suite' ); ?>"
                            required
                            autocomplete="current-password"
                        >
                        <button type="submit"><?php esc_html_e( 'Accéder au tableau de bord', 'analytic-suite' ); ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders public analytics shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function render_public_analytics( $atts ) {
        $password_hash = get_option( 'analytic_suite_public_password', '' );
        if ( ! empty( $password_hash ) && ! $this->is_public_authenticated() ) {
            return $this->render_public_auth_form();
        }

        $filters = $this->get_public_filters();

        $content_repo = new Analytic_Suite_Content_Repository();

        // Elementor-based free-content registrations (date-filtered) = "Nouveaux apprenants gratuits".
        $registered_demos = $content_repo->get_registered_user_demographics( $filters['date_from'], $filters['date_to'] );
        $free_count       = $registered_demos['total_users'];

        // Legacy WP demographics (disability/login use wp_usermeta — no date column, not filterable).
        $data            = $content_repo->get_public_demographics( $filters['date_from'], $filters['date_to'] );

        // Paying = unique customers with at least one completed WooCommerce order (any product).
        $paying_count = $content_repo->get_paying_apprenants_count( $filters['date_from'], $filters['date_to'] );

        // Total unique apprenants across WP accounts, free-content forms, and paying customers, deduped by
        // email — always >= free_count and >= paying_count (a form submitter or guest buyer need not have a WP account).
        $total_users = $content_repo->get_total_unique_apprenants_count( $filters['date_from'], $filters['date_to'] );

        // Use free_count as denominator when available; it shares the same date scope as the numerator.
        $rate_base       = $free_count > 0 ? $free_count : $total_users;
        $engagement_rate = $this->calculate_percentage( $data['completed_content'], $rate_base );

        // Active = unique apprenants who either paid or followed a content in the period.
        $active_count = $content_repo->get_active_apprenants_count( $filters['date_from'], $filters['date_to'] );

        $ga_data          = $this->get_public_ga_data( $filters );
        $active_users_ga  = isset( $ga_data['summary']['active_users'] ) ? (int) $ga_data['summary']['active_users'] : 0;
        $conversion_rate  = min( 100.0, $this->calculate_percentage( $free_count, $active_users_ga ) );
        $civility_breakdown   = $registered_demos['civility_breakdown'];
        $experience_breakdown = $registered_demos['experience_breakdown'];
        $industry_breakdown   = $registered_demos['industry_breakdown'];
        $support_breakdown    = $registered_demos['support_breakdown'];
        $location_breakdown   = ! empty( $registered_demos['location_breakdown'] )
            ? $registered_demos['location_breakdown']
            : ( $ga_data['demographics']['countries'] ?? array() );
        $city_breakdown       = $ga_data['demographics']['cities'] ?? array();
        $top_masterclasses    = $content_repo->get_top_masterclasses_from_elementor( 5, $filters['date_from'], $filters['date_to'] );

        // Classify GA4 cities as urban/rural via Open-Meteo Geocoding API (cached 30 days).
        $zone_counts    = $this->get_urban_rural_counts( $city_breakdown );
        $loc_urban      = $zone_counts['urban'];
        $loc_rural      = $zone_counts['rural'];
        $loc_total      = $loc_urban + $loc_rural;
        $loc_unresolved = max( 0, $active_users_ga - $loc_total );

        // Percentages are relative to active GA4 visitors — the pool of users who could potentially be located.
        $urban_pct      = $active_users_ga > 0 ? number_format_i18n( round( $loc_urban / $active_users_ga * 100, 1 ), 1 ) : '0';
        $rural_pct      = $active_users_ga > 0 ? number_format_i18n( round( $loc_rural / $active_users_ga * 100, 1 ), 1 ) : '0';
        $unresolved_pct = $active_users_ga > 0 ? number_format_i18n( round( $loc_unresolved / $active_users_ga * 100, 1 ), 1 ) : '0';

        ob_start();
        ?>
        <div class="analytic-suite-public">
            <?php echo $this->render_public_filter_bar( $filters ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <section class="as-public-hero">
                <div class="as-public-hero-copy">
                    <span class="as-public-kicker"><?php esc_html_e( 'Analytics publics', 'analytic-suite' ); ?></span>
                    <h2><?php esc_html_e( 'Tableau de bord', 'analytic-suite' ); ?></h2>
                    <p><?php esc_html_e( "Une lecture claire des profils, de l'engagement et de la progression des apprenants.", 'analytic-suite' ); ?></p>
                </div>
                <div class="as-public-hero-meter">
                    <span><?php esc_html_e( 'Engagement contenu', 'analytic-suite' ); ?></span>
                    <strong><?php echo esc_html( number_format_i18n( $engagement_rate, 1 ) ); ?>%</strong>
                    <div class="as-public-meter-track"><span style="width: <?php echo esc_attr( min( 100, $engagement_rate ) ); ?>%"></span></div>
                </div>
            </section>

            <div class="as-public-grid">
                <?php $this->render_public_stat_card( __( 'Nouveaux Apprenants', 'analytic-suite' ), $total_users, __( '', 'analytic-suite' ), __( 'Toutes les personnes uniques arrivées sur la période : comptes créés, formulaires de contenu gratuit remplis ou achats effectués. Chaque personne n\'est comptée qu\'une seule fois, même si elle a fait plusieurs de ces actions.', 'analytic-suite' ) ); ?>
                <?php $this->render_public_stat_card( __( 'Nouveaux apprenants payants', 'analytic-suite' ), $paying_count, __( '', 'analytic-suite' ), __( 'Nombre de clients uniques ayant réalisé au moins un achat validé (commande complétée) sur la période.', 'analytic-suite' ) ); ?>
                <?php $this->render_public_stat_card( __( 'Nouveaux apprenants gratuits', 'analytic-suite' ), $free_count, $active_users_ga > 0 ? number_format_i18n( $conversion_rate, 1 ) . '% ' . __( 'taux de conversion', 'analytic-suite' ) : __( 'Apprenants uniques', 'analytic-suite' ), __( 'Nombre de personnes uniques ayant rempli un formulaire pour accéder à un contenu gratuit (masterclass, livre, expert session) sur la période. Le taux de conversion indique la part de ces visiteurs par rapport aux visiteurs actifs du site.', 'analytic-suite' ) ); ?>
                <?php $this->render_public_stat_card( __( 'Apprenants actifs', 'analytic-suite' ), $active_count, __( '', 'analytic-suite' ), __( 'Personnes uniques ayant soit acheté, soit consulté un contenu (gratuit ou payant) sur la période. Un bon indicateur de l\'engagement global.', 'analytic-suite' ) ); ?>
            </div>

            <?php if ( ! empty( $ga_data['configured'] ) ) : ?>
                <section class="as-public-ga-block">
                    <div class="as-public-ga-heading">
                        <h3><?php esc_html_e( 'Performance des contenus', 'analytic-suite' ); ?></h3>
                    </div>
                    <div class="as-public-grid">
                        <?php $this->render_public_stat_card( __( 'Visiteurs actifs', 'analytic-suite' ), $ga_data['summary']['active_users'], $filters['period_label'], __( 'Nombre de personnes ayant visité le site sur la période, mesuré par Google Analytics. Une même personne n\'est comptée qu\'une fois, même si elle revient plusieurs fois.', 'analytic-suite' ) ); ?>
                        <?php $this->render_public_stat_card( __( 'Sessions', 'analytic-suite' ), $ga_data['summary']['sessions'], __( 'Trafic', 'analytic-suite' ), __( 'Nombre total de visites sur le site. Une même personne qui revient plusieurs fois génère plusieurs sessions.', 'analytic-suite' ) ); ?>
                        <?php $this->render_public_stat_card( __( 'Pages vues', 'analytic-suite' ), $ga_data['summary']['page_views'], __( 'Vues', 'analytic-suite' ), __( 'Nombre total de pages consultées sur le site, toutes visites confondues.', 'analytic-suite' ) ); ?>
                        <?php $this->render_public_stat_card( __( 'Nouveaux visiteurs', 'analytic-suite' ), $ga_data['summary']['new_users'], $ga_data['summary']['avg_duration'], __( 'Nombre de personnes venues sur le site pour la première fois sur la période (jamais vues auparavant par Google Analytics).', 'analytic-suite' ) ); ?>
                    </div>
                    <div class="as-public-charts">
                        <?php $this->render_public_chart( __( 'Indicateurs GA4', 'analytic-suite' ), 'bar', array(
                            __( 'Utilisateurs', 'analytic-suite' ) => $ga_data['summary']['active_users'],
                            __( 'Sessions', 'analytic-suite' )     => $ga_data['summary']['sessions'],
                            __( 'Pages vues', 'analytic-suite' )   => $ga_data['summary']['page_views'],
                            __( 'Nouveaux', 'analytic-suite' )     => $ga_data['summary']['new_users'],
                        ) ); ?>
                        <?php $this->render_public_chart( __( 'Pages suivies GA4', 'analytic-suite' ), 'bar', $this->format_public_ga_pages( $ga_data['pages'] ) ); ?>
                    </div>
                </section>
            <?php endif; ?>

            <div class="as-public-charts as-public-charts--featured">
                <?php $this->render_public_chart( __( 'Civilité', 'analytic-suite' ), 'doughnut', $civility_breakdown ); ?>
                <?php $this->render_public_chart( __( 'Type de conseils recherché', 'analytic-suite' ), 'doughnut', $support_breakdown ); ?>
                <?php $this->render_public_chart( __( 'Localisation', 'analytic-suite' ), 'doughnut', $location_breakdown ); ?>
            </div>

            <?php if ( $active_users_ga > 0 ) : ?>
            <div class="as-public-grid as-public-grid--location">
                <?php $this->render_public_stat_card( __( 'Zone urbaine', 'analytic-suite' ), $loc_urban, $urban_pct . __( '% des visiteurs actifs', 'analytic-suite' ), __( 'Visiteurs actifs situés dans une ville identifiée comme urbaine (capitale, grande agglomération ou commune de plus de 10 000 habitants).', 'analytic-suite' ) ); ?>
                <?php $this->render_public_stat_card( __( 'Zone rurale', 'analytic-suite' ), $loc_rural, $rural_pct . __( '% des visiteurs actifs', 'analytic-suite' ), __( 'Visiteurs actifs situés dans une commune identifiée comme rurale (moins de 10 000 habitants).', 'analytic-suite' ) ); ?>
                <?php $this->render_public_stat_card( __( 'Utilisateurs non localisés', 'analytic-suite' ), $loc_unresolved, $unresolved_pct . __( '% des visiteurs actifs', 'analytic-suite' ), __( 'Visiteurs actifs dont la ville n\'a pas pu être identifiée ou classée en zone urbaine/rurale.', 'analytic-suite' ) ); ?>
            </div>
            <?php endif; ?>

            <div class="as-public-charts as-public-charts--3col">
                <?php $this->render_public_chart( __( 'Secteurs d\'activité', 'analytic-suite' ), 'bar', $industry_breakdown ); ?>
                <?php $this->render_public_chart( __( 'Années d\'expérience', 'analytic-suite' ), 'bar', $experience_breakdown ); ?>
                <?php $this->render_public_chart( __( 'Villes', 'analytic-suite' ), 'bar', array_slice( $city_breakdown, 0, 15, true ) ); ?>
            </div>

            <?php if ( ! empty( $top_masterclasses ) ) : ?>
                <section class="as-public-top-mc">
                    <div class="as-public-top-mc-heading">
                        <h3><?php esc_html_e( 'Top 5 Masterclasses', 'analytic-suite' ); ?></h3>
                        <span><?php esc_html_e( 'par nombre d\'inscriptions', 'analytic-suite' ); ?></span>
                    </div>
                    <?php $this->render_public_chart( __( 'Inscriptions', 'analytic-suite' ), 'bar', $top_masterclasses ); ?>
                </section>
            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders a public stat card.
     *
     * @param string $label       Label.
     * @param int    $value       Value.
     * @param string $note        Note.
     * @param string $description Plain-language explanation shown on hover/focus. Optional.
     */
    private function render_public_stat_card( $label, $value, $note, $description = '' ) {
        $has_description = ! empty( $description );
        echo '<div class="as-public-card"' . ( $has_description ? ' data-tooltip="' . esc_attr( $description ) . '" tabindex="0"' : '' ) . '>';
        if ( $has_description ) {
            echo '<span class="as-card-info" aria-hidden="true">?</span>';
        }
        echo '<span class="as-card-label">' . esc_html( $label ) . '</span>';
        echo '<strong class="as-card-value">' . esc_html( number_format_i18n( (int) $value ) ) . '</strong>';
        echo '<small>' . esc_html( $note ) . '</small>';
        echo '</div>';
    }

    /**
     * Aggregates GA4 visitor counts into urban/rural buckets using city classification.
     *
     * @param array $city_breakdown city_name => session_count from GA4.
     * @return array { urban: int, rural: int, unknown: int }
     */
    private function get_urban_rural_counts( array $city_breakdown ) {
        $counts = array( 'urban' => 0, 'rural' => 0, 'unknown' => 0 );
        foreach ( $city_breakdown as $city => $sessions ) {
            $city = trim( (string) $city );
            if ( '' === $city || '(not set)' === $city ) {
                continue;
            }
            $zone             = $this->classify_city_zone( $city );
            $counts[ $zone ] += (int) $sessions;
        }
        return $counts;
    }

    /**
     * Classifies a city as 'urban', 'rural', or 'unknown' using the Open-Meteo
     * Geocoding API (free, no API key). Results are cached as WP transients for 30 days.
     *
     * Classification rules (GeoNames feature codes + population):
     *   - PPLC / PPLG / PPLA  → always urban (capital / admin seat level 1)
     *   - Others with pop > 10 000 → urban
     *   - Others with pop ≤ 10 000 → rural
     *   - No result or no population → unknown
     *
     * @param string $city City name.
     * @return string 'urban' | 'rural' | 'unknown'
     */
    private function classify_city_zone( $city ) {
        $cache_key = 'analytic_suite_city_zone_' . md5( strtolower( $city ) );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $url = add_query_arg(
            array(
                'name'     => $city,
                'count'    => 1,
                'language' => 'fr',
                'format'   => 'json',
            ),
            'https://geocoding-api.open-meteo.com/v1/search'
        );

        $response = wp_remote_get(
            $url,
            array(
                'timeout'    => 5,
                'user-agent' => 'AnalyticSuite/' . ANALYTIC_SUITE_VERSION . ' WordPress Plugin',
            )
        );

        $zone = 'unknown';

        if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
            $body   = json_decode( wp_remote_retrieve_body( $response ), true );
            $result = ! empty( $body['results'][0] ) ? $body['results'][0] : null;

            if ( $result ) {
                $feature_code  = isset( $result['feature_code'] ) ? strtoupper( $result['feature_code'] ) : '';
                $population    = isset( $result['population'] ) ? (int) $result['population'] : null;
                $always_urban  = array( 'PPLC', 'PPLG', 'PPLA' );

                if ( in_array( $feature_code, $always_urban, true ) ) {
                    $zone = 'urban';
                } elseif ( null !== $population ) {
                    $zone = $population > 10000 ? 'urban' : 'rural';
                }
            }
        }

        set_transient( $cache_key, $zone, 30 * DAY_IN_SECONDS );
        return $zone;
    }

    /**
     * Renders a public chart panel.
     *
     * @param string $title Chart title.
     * @param string $type  Chart type.
     * @param array  $items Chart items.
     */
    private function render_public_chart( $title, $type, $items ) {
        $points = $this->normalize_public_chart_points( $items );

        echo '<section class="analytic-suite-chart-panel as-public-chart-panel">';
        echo '<h3>' . esc_html( $title ) . '</h3>';

        if ( empty( $points ) ) {
            echo '<p>' . esc_html__( 'Aucune donnée disponible.', 'analytic-suite' ) . '</p></section>';
            return;
        }

        echo '<div class="analytic-suite-chart-wrap">';
        echo $this->render_public_chart_fallback( $points );
        echo '<canvas class="analytic-suite-chart" height="260" data-chart-type="' . esc_attr( $type ) . '" data-chart-points="' . esc_attr( wp_json_encode( $points ) ) . '" aria-label="' . esc_attr( $title ) . '" role="img"></canvas>';
        echo '</div>';
        echo '</section>';
    }

    /**
     * Renders a public breakdown list.
     *
     * @param string $title List title.
     * @param array  $items Items.
     */
    private function render_public_breakdown( $title, $items ) {
        echo '<section class="as-public-section">';
        echo '<h3>' . esc_html( $title ) . '</h3>';

        if ( empty( $items ) ) {
            echo '<p>' . esc_html__( 'Aucune donnée', 'analytic-suite' ) . '</p></section>';
            return;
        }

        echo '<div class="as-public-list">';
        foreach ( $items as $label => $count ) {
            echo '<div class="as-public-list-row">';
            echo '<span>' . esc_html( $label ) . '</span>';
            echo '<strong>' . esc_html( number_format_i18n( (int) $count ) ) . '</strong>';
            echo '</div>';
        }
        echo '</div></section>';
    }

    /**
     * Gets the selected public GA page.
     *
     * @return array
     */
    private function get_public_content_paths() {
        return array( '/contenus-gratuits/', '/expert-session/', '/livre/' );
    }

    /**
     * Gets GA data filtered to the public content paths.
     *
     * @return array
     */
    /**
     * Reads and validates public filter params from the query string.
     *
     * @return array { period, date_from, date_to, period_label }
     */
    private function get_public_filters() {
        $allowed = array( 'all', '7-days', '30-days', 'year', 'custom' );
        // phpcs:ignore WordPress.Security.NonceVerification
        $period = sanitize_key( $_GET['as_period'] ?? 'all' );

        if ( ! in_array( $period, $allowed, true ) ) {
            $period = 'all';
        }

        $today     = current_time( 'Y-m-d' );
        $date_from = '';
        $date_to   = '';

        switch ( $period ) {
            case '7-days':
                $date_from    = gmdate( 'Y-m-d', strtotime( '-7 days', strtotime( $today ) ) );
                $date_to      = $today;
                $period_label = __( '7 derniers jours', 'analytic-suite' );
                break;
            case '30-days':
                $date_from    = gmdate( 'Y-m-d', strtotime( '-30 days', strtotime( $today ) ) );
                $date_to      = $today;
                $period_label = __( '30 derniers jours', 'analytic-suite' );
                break;
            case 'year':
                $date_from    = gmdate( 'Y-01-01', strtotime( $today ) );
                $date_to      = $today;
                $period_label = __( 'Cette année', 'analytic-suite' );
                break;
            case 'custom':
                // phpcs:disable WordPress.Security.NonceVerification
                $df = sanitize_text_field( wp_unslash( $_GET['as_date_from'] ?? '' ) );
                $dt = sanitize_text_field( wp_unslash( $_GET['as_date_to'] ?? '' ) );
                // phpcs:enable
                $date_from    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $df ) ? $df : '';
                $date_to      = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dt ) ? $dt : $today;
                $period_label = $date_from
                    ? sprintf( '%s – %s', $date_from, $date_to )
                    : __( 'Personnalisé', 'analytic-suite' );
                break;
            default:
                $period_label = __( 'Toutes les données', 'analytic-suite' );
                break;
        }

        return array(
            'period'       => $period,
            'date_from'    => $date_from,
            'date_to'      => $date_to,
            'period_label' => $period_label,
        );
    }

    /**
     * Renders the date-filter bar for the public shortcode.
     *
     * @param array $filters Filters from get_public_filters().
     * @return string HTML.
     */
    private function render_public_filter_bar( array $filters ) {
        $base    = get_permalink() ?: home_url( '/' );
        $period  = $filters['period'];
        $periods = array(
            'all'     => __( 'Tout', 'analytic-suite' ),
            '7-days'  => __( '7 jours', 'analytic-suite' ),
            '30-days' => __( '30 jours', 'analytic-suite' ),
            'year'    => __( 'Cette année', 'analytic-suite' ),
        );
        $today = current_time( 'Y-m-d' );

        ob_start();
        ?>
        <div class="as-public-filter-bar">
            <nav class="as-public-filter-periods" aria-label="<?php esc_attr_e( 'Filtrer par période', 'analytic-suite' ); ?>">
                <?php foreach ( $periods as $key => $label ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'as_period', $key, $base ) ); ?>"
                       class="as-filter-pill<?php echo $period === $key ? ' is-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
                <button type="button"
                        class="as-filter-pill as-filter-custom-toggle<?php echo 'custom' === $period ? ' is-active' : ''; ?>"
                        aria-expanded="<?php echo 'custom' === $period ? 'true' : 'false'; ?>"
                        aria-controls="as-public-filter-custom">
                    <?php esc_html_e( 'Personnalisé', 'analytic-suite' ); ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </button>
            </nav>
            <form id="as-public-filter-custom"
                  class="as-public-filter-custom<?php echo 'custom' === $period ? ' is-open' : ''; ?>"
                  method="get"
                  action="<?php echo esc_url( $base ); ?>">
                <input type="hidden" name="as_period" value="custom">
                <label class="as-filter-label" for="as_date_from"><?php esc_html_e( 'Du', 'analytic-suite' ); ?></label>
                <input type="date" id="as_date_from" name="as_date_from"
                       value="<?php echo esc_attr( $filters['date_from'] ); ?>"
                       max="<?php echo esc_attr( $today ); ?>">
                <span class="as-filter-sep" aria-hidden="true">→</span>
                <label class="as-filter-label" for="as_date_to"><?php esc_html_e( 'Au', 'analytic-suite' ); ?></label>
                <input type="date" id="as_date_to" name="as_date_to"
                       value="<?php echo esc_attr( $filters['date_to'] ?: $today ); ?>"
                       max="<?php echo esc_attr( $today ); ?>">
                <button type="submit" class="as-filter-apply"><?php esc_html_e( 'Appliquer', 'analytic-suite' ); ?></button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_public_ga_data( array $pub_filters = array() ) {
        $ga = new Analytic_Suite_Google_Analytics();

        if ( ! $ga->is_configured() ) {
            return array(
                'configured'   => false,
                'summary'      => array(),
                'pages'        => array(),
                'demographics' => array(),
            );
        }

        $period = $pub_filters['period'] ?? '30-days';
        if ( 'all' === $period ) {
            $period = 'year';
        }

        $filters = array(
            'period'     => $period,
            'page_paths' => $this->get_public_content_paths(),
        );

        if ( 'custom' === $period ) {
            $filters['date_from'] = $pub_filters['date_from'] ?? '';
            $filters['date_to']   = $pub_filters['date_to'] ?? current_time( 'Y-m-d' );
        }

        return array(
            'configured'   => true,
            'summary'      => $ga->get_summary( $filters ),
            'pages'        => $ga->get_page_views( $filters ),
            'demographics' => $ga->get_demographics( $filters ),
        );
    }

    /**
     * Uses GA data when available, otherwise keeps local fallback data.
     *
     * @param array $ga_items       GA items.
     * @param array $fallback_items Fallback items.
     * @return array
     */
    private function prefer_ga_breakdown( $ga_items, $fallback_items ) {
        return ! empty( $ga_items ) ? $ga_items : $fallback_items;
    }

    /**
     * Formats GA gender labels for public display.
     *
     * @param array $items GA gender items.
     * @return array
     */
    private function format_public_ga_genders( $items ) {
        $labels = array(
            'male'   => __( 'Homme', 'analytic-suite' ),
            'female' => __( 'Femme', 'analytic-suite' ),
        );
        $formatted = array();

        foreach ( (array) $items as $label => $value ) {
            $key = strtolower( (string) $label );
            $formatted[ $labels[ $key ] ?? ucfirst( (string) $label ) ] = $value;
        }

        return $formatted;
    }

    /**
     * Formats GA pages for public charts.
     *
     * @param array $pages Page rows.
     * @return array
     */
    private function format_public_ga_pages( $pages ) {
        $items = array();

        foreach ( array_slice( (array) $pages, 0, 6 ) as $page ) {
            if ( empty( $page['path'] ) || ! isset( $page['views'] ) ) {
                continue;
            }

            $items[ $page['path'] ] = (int) $page['views'];
        }

        return $items;
    }

    /**
     * Normalizes public chart values.
     *
     * @param array $items Raw chart items.
     * @return array
     */
    private function normalize_public_chart_points( $items ) {
        $points = array();

        foreach ( (array) $items as $label => $value ) {
            if ( is_numeric( $value ) ) {
                $points[] = array(
                    'label' => (string) $label,
                    'value' => (float) $value,
                );
            }
        }

        return $points;
    }

    /**
     * Renders chart fallback bars for the public shortcode.
     *
     * @param array $points Chart points.
     * @return string
     */
    private function render_public_chart_fallback( $points ) {
        $max = 0;
        foreach ( $points as $point ) {
            $max = max( $max, (float) $point['value'] );
        }

        $output = '<div class="analytic-suite-chart-fallback" aria-hidden="true">';
        foreach ( $points as $point ) {
            $value   = (float) $point['value'];
            $percent = $max > 0 ? min( 100, round( ( $value / $max ) * 100, 2 ) ) : 0;

            $output .= '<div class="analytic-suite-chart-row">';
            $output .= '<span class="analytic-suite-chart-label">' . esc_html( $point['label'] ) . '</span>';
            $output .= '<span class="analytic-suite-chart-track"><span class="analytic-suite-chart-fill" style="width:' . esc_attr( $percent ) . '%"></span></span>';
            $output .= '<strong class="analytic-suite-chart-value">' . esc_html( number_format_i18n( $value, 0 ) ) . '</strong>';
            $output .= '</div>';
        }
        $output .= '</div>';

        return $output;
    }

    /**
     * Calculates a percentage safely.
     *
     * @param int $value Value.
     * @param int $total Total.
     * @return float
     */
    private function calculate_percentage( $value, $total ) {
        $total = (int) $total;

        if ( $total <= 0 ) {
            return 0.0;
        }

        return round( ( (int) $value / $total ) * 100, 1 );
    }
}
