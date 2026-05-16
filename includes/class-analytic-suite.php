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
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );
        add_action( 'admin_post_analytic_suite_export_csv', array( $this, 'export_csv' ) );
        add_action( 'admin_post_analytic_suite_export_excel', array( $this, 'export_excel' ) );
        add_action( 'admin_post_analytic_suite_export_pdf', array( $this, 'export_pdf' ) );
        add_action( 'analytic_suite_daily_sync', array( $this, 'run_daily_sync' ) );
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
        Analytic_Suite_Activator::add_capabilities();

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

        wp_enqueue_script(
            'analytic-suite-admin',
            ANALYTIC_SUITE_URL . 'assets/js/admin.js',
            array(),
            ANALYTIC_SUITE_VERSION,
            true
        );
    }

    /**
     * Enqueues assets for the public analytics shortcode.
     */
    public function enqueue_public_assets() {
        wp_enqueue_style(
            'analytic-suite-public',
            ANALYTIC_SUITE_URL . 'assets/css/admin.css',
            array(),
            ANALYTIC_SUITE_VERSION
        );

        wp_enqueue_script(
            'analytic-suite-public',
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
     * Renders public analytics shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function render_public_analytics( $atts ) {
        $content_repo = new Analytic_Suite_Content_Repository();
        $data         = $content_repo->get_public_demographics();
        $engagement_rate = $this->calculate_percentage( $data['completed_content'], $data['total_users'] );
        $login_rate      = $this->calculate_percentage( $data['logged_in_users'], $data['total_users'] );
        $access_rate     = $this->calculate_percentage( $data['disability_count'], $data['total_users'] );

        ob_start();
        ?>
        <div class="analytic-suite-public">
            <section class="as-public-hero">
                <div class="as-public-hero-copy">
                    <span class="as-public-kicker"><?php esc_html_e( 'Analytics publics', 'analytic-suite' ); ?></span>
                    <h2><?php esc_html_e( 'Tableau de bord de la communauté', 'analytic-suite' ); ?></h2>
                    <p><?php esc_html_e( 'Une lecture claire des profils, de l’engagement et de la progression des utilisateurs.', 'analytic-suite' ); ?></p>
                </div>
                <div class="as-public-hero-meter">
                    <span><?php esc_html_e( 'Engagement contenu', 'analytic-suite' ); ?></span>
                    <strong><?php echo esc_html( number_format_i18n( $engagement_rate, 1 ) ); ?>%</strong>
                    <div class="as-public-meter-track"><span style="width: <?php echo esc_attr( min( 100, $engagement_rate ) ); ?>%"></span></div>
                </div>
            </section>

            <div class="as-public-grid">
                <?php $this->render_public_stat_card( __( 'Utilisateurs inscrits', 'analytic-suite' ), $data['total_users'], __( 'Base totale', 'analytic-suite' ) ); ?>
                <?php $this->render_public_stat_card( __( 'Utilisateurs connectés', 'analytic-suite' ), $data['logged_in_users'], number_format_i18n( $login_rate, 1 ) . '%' ); ?>
                <?php $this->render_public_stat_card( __( 'Contenus finalisés', 'analytic-suite' ), $data['completed_content'], number_format_i18n( $engagement_rate, 1 ) . '%' ); ?>
                <?php $this->render_public_stat_card( __( 'Situation de handicap', 'analytic-suite' ), $data['disability_count'], number_format_i18n( $access_rate, 1 ) . '%' ); ?>
            </div>

            <div class="as-public-insights">
                <div class="as-public-ring-card">
                    <span><?php esc_html_e( 'Taux de connexion', 'analytic-suite' ); ?></span>
                    <div class="as-public-ring" style="--as-ring-value: <?php echo esc_attr( min( 100, $login_rate ) ); ?>;">
                        <strong><?php echo esc_html( number_format_i18n( $login_rate, 1 ) ); ?>%</strong>
                    </div>
                </div>
                <div class="as-public-ring-card">
                    <span><?php esc_html_e( 'Progression contenu', 'analytic-suite' ); ?></span>
                    <div class="as-public-ring" style="--as-ring-value: <?php echo esc_attr( min( 100, $engagement_rate ) ); ?>;">
                        <strong><?php echo esc_html( number_format_i18n( $engagement_rate, 1 ) ); ?>%</strong>
                    </div>
                </div>
                <div class="as-public-highlight">
                    <span><?php esc_html_e( 'Lecture rapide', 'analytic-suite' ); ?></span>
                    <strong><?php echo esc_html( number_format_i18n( $data['completed_content'] ) ); ?></strong>
                    <p><?php esc_html_e( 'utilisateurs ont finalisé au moins un contenu suivi.', 'analytic-suite' ); ?></p>
                </div>
            </div>

            <div class="as-public-charts">
                <?php $this->render_public_chart( __( 'Répartition par âge', 'analytic-suite' ), 'bar', $data['age_breakdown'] ); ?>
                <?php $this->render_public_chart( __( 'Répartition par sexe', 'analytic-suite' ), 'doughnut', $data['sex_breakdown'] ); ?>
                <?php $this->render_public_chart( __( 'Localisation', 'analytic-suite' ), 'doughnut', $data['location_breakdown'] ); ?>
                <?php $this->render_public_chart( __( 'Parcours utilisateur', 'analytic-suite' ), 'bar', array(
                    __( 'Inscrits', 'analytic-suite' )   => $data['total_users'],
                    __( 'Connectés', 'analytic-suite' )  => $data['logged_in_users'],
                    __( 'Finalisés', 'analytic-suite' )  => $data['completed_content'],
                    __( 'Handicap', 'analytic-suite' )   => $data['disability_count'],
                ) ); ?>
            </div>

            <div class="as-public-sections">
                <?php $this->render_public_breakdown( __( 'Âge', 'analytic-suite' ), $data['age_breakdown'] ); ?>
                <?php $this->render_public_breakdown( __( 'Sexe', 'analytic-suite' ), $data['sex_breakdown'] ); ?>
                <?php $this->render_public_breakdown( __( 'Localisation', 'analytic-suite' ), $data['location_breakdown'] ); ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders a public stat card.
     *
     * @param string $label Label.
     * @param int    $value Value.
     * @param string $note  Note.
     */
    private function render_public_stat_card( $label, $value, $note ) {
        echo '<div class="as-public-card">';
        echo '<span class="as-card-label">' . esc_html( $label ) . '</span>';
        echo '<strong class="as-card-value">' . esc_html( number_format_i18n( (int) $value ) ) . '</strong>';
        echo '<small>' . esc_html( $note ) . '</small>';
        echo '</div>';
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
