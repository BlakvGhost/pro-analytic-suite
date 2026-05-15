<?php
/**
 * Admin pages.
 *
 * @package Analytic_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders WordPress admin analytics screens.
 */
class Analytic_Suite_Admin {

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
     * Registers menu and submenus.
     */
    public function register_menu() {
        add_menu_page(
            __( 'Pro Analytics', 'analytic-suite' ),
            __( 'Pro Analytics', 'analytic-suite' ),
            'analytic_suite_view_analytics',
            'analytic-suite',
            array( $this, 'render_dashboard_page' ),
            'dashicons-chart-area',
            56
        );

        $pages = array(
            'analytic-suite-clients'  => __( 'Clients', 'analytic-suite' ),
            'analytic-suite-bookings' => __( 'Réservations', 'analytic-suite' ),
            'analytic-suite-orders'   => __( 'Commandes', 'analytic-suite' ),
            'analytic-suite-contents' => __( 'Contenus', 'analytic-suite' ),
            'analytic-suite-reports'  => __( 'Rapports', 'analytic-suite' ),
            'analytic-suite-exports'  => __( 'Exports', 'analytic-suite' ),
            'analytic-suite-settings' => __( 'Paramètres', 'analytic-suite' ),
        );

        foreach ( $pages as $slug => $title ) {
            add_submenu_page(
                'analytic-suite',
                $title,
                $title,
                'analytic_suite_view_analytics',
                $slug,
                array( $this, 'render_submenu_page' )
            );
        }
    }

    /**
     * Renders the dashboard page.
     */
    public function render_dashboard_page() {
        $this->render_page( 'dashboard' );
    }

    /**
     * Renders any submenu page.
     */
    public function render_submenu_page() {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'analytic-suite';
        $view = str_replace( 'analytic-suite-', '', $page );

        if ( 'analytic-suite' === $page ) {
            $view = 'dashboard';
        }

        $this->render_page( $view );
    }

    /**
     * Renders a plugin page.
     *
     * @param string $view View identifier.
     */
    private function render_page( $view ) {
        if ( ! current_user_can( 'analytic_suite_view_analytics' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'analytic-suite' ) );
        }

        $filters       = $this->dashboard_service->get_filters_from_request( $_GET );
        $data          = $this->dashboard_service->get_dashboard_data( $filters );
        $filter_options = $this->dashboard_service->get_filter_options();
        $titles        = $this->get_page_titles();
        $title         = isset( $titles[ $view ] ) ? $titles[ $view ] : $titles['dashboard'];

        echo '<div class="wrap analytic-suite">';
        echo '<h1>' . esc_html( $title ) . '</h1>';
        $this->render_tabs( $view );

        $this->render_notices( $data );
        $this->render_filters( $filters, $filter_options, $view );

        if ( 'dashboard' === $view ) {
            $this->render_dashboard( $data );
        } elseif ( 'clients' === $view ) {
            $this->render_clients( $data );
        } elseif ( 'bookings' === $view ) {
            $this->render_bookings( $data );
        } elseif ( 'orders' === $view ) {
            $this->render_orders( $data );
        } elseif ( 'contents' === $view ) {
            $this->render_contents( $data );
        } elseif ( 'exports' === $view ) {
            $this->render_exports( $filters );
        } elseif ( 'settings' === $view ) {
            $this->render_settings( $data );
        } else {
            $this->render_reports( $data );
        }

        echo '</div>';
    }

    /**
     * Gets page titles.
     *
     * @return array
     */
    private function get_page_titles() {
        return array(
            'dashboard' => __( 'Dashboard Analytics', 'analytic-suite' ),
            'clients'   => __( 'Analytics Clients', 'analytic-suite' ),
            'bookings'  => __( 'Analytics Réservations', 'analytic-suite' ),
            'orders'    => __( 'Analytics Commandes', 'analytic-suite' ),
            'contents'  => __( 'Analytics Contenus', 'analytic-suite' ),
            'reports'   => __( 'Rapports Analytics', 'analytic-suite' ),
            'exports'   => __( 'Exports Analytics', 'analytic-suite' ),
            'settings'  => __( 'Paramètres Analytics', 'analytic-suite' ),
        );
    }

    /**
     * Renders top navigation tabs.
     *
     * @param string $current_view Current view.
     */
    private function render_tabs( $current_view ) {
        $tabs = array(
            'dashboard' => __( 'Dashboard', 'analytic-suite' ),
            'clients'   => __( 'Clients', 'analytic-suite' ),
            'bookings'  => __( 'Réservations', 'analytic-suite' ),
            'orders'    => __( 'Commandes', 'analytic-suite' ),
            'contents'  => __( 'Contenus', 'analytic-suite' ),
            'reports'   => __( 'Rapports', 'analytic-suite' ),
            'exports'   => __( 'Exports', 'analytic-suite' ),
            'settings'  => __( 'Paramètres', 'analytic-suite' ),
        );

        echo '<nav class="analytic-suite-tabs" aria-label="' . esc_attr__( 'Navigation Analytics', 'analytic-suite' ) . '">';

        foreach ( $tabs as $view => $label ) {
            $page = 'dashboard' === $view ? 'analytic-suite' : 'analytic-suite-' . $view;
            $url  = admin_url( 'admin.php?page=' . $page );
            $class = $current_view === $view ? ' class="is-active"' : '';

            echo '<a href="' . esc_url( $url ) . '"' . $class . '>' . esc_html( $label ) . '</a>';
        }

        echo '</nav>';
    }

    /**
     * Renders plugin availability notices.
     *
     * @param array $data Dashboard data.
     */
    private function render_notices( $data ) {
        if ( empty( $data['orders']['available'] ) ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'WooCommerce n’est pas détecté. Les métriques de commandes resteront à zéro.', 'analytic-suite' ) . '</p></div>';
        }

        if ( empty( $data['bookings']['available'] ) ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'Les tables FluentBooking ne sont pas détectées. Les métriques de réservations resteront à zéro.', 'analytic-suite' ) . '</p></div>';
        }

        if ( empty( $data['contents']['available'] ) ) {
            echo '<div class="notice notice-info"><p>' . esc_html__( 'Les tables ou post types de contenus ne sont pas détectés. Les métriques Masterclass/Livres resteront à zéro.', 'analytic-suite' ) . '</p></div>';
        }
    }

    /**
     * Renders filters.
     *
     * @param array  $filters       Filters.
     * @param array  $filter_options Available filter options.
     * @param string $view          Current view.
     */
    private function render_filters( $filters, $filter_options, $view ) {
        $page = 'dashboard' === $view ? 'analytic-suite' : 'analytic-suite-' . $view;

        echo '<form method="get" class="analytic-suite-filters">';
        echo '<input type="hidden" name="page" value="' . esc_attr( $page ) . '">';

        $this->render_select(
            'period',
            __( 'Période', 'analytic-suite' ),
            $filters['period'],
            array(
                'all'     => __( 'Toutes les données', 'analytic-suite' ),
                '7-days'  => __( '7 jours', 'analytic-suite' ),
                '30-days' => __( '30 jours', 'analytic-suite' ),
                'year'    => __( 'Année en cours', 'analytic-suite' ),
                'custom'  => __( 'Personnalisée', 'analytic-suite' ),
            )
        );

        $this->render_input( 'date_from', __( 'Du', 'analytic-suite' ), $filters['date_from'], 'date' );
        $this->render_input( 'date_to', __( 'Au', 'analytic-suite' ), $filters['date_to'], 'date' );

        $this->render_select(
            'country',
            __( 'Pays', 'analytic-suite' ),
            $filters['country'],
            $this->build_options_array( $filter_options['countries'], true )
        );

        $this->render_select(
            'status',
            __( 'Statut', 'analytic-suite' ),
            $filters['status'],
            $this->build_options_array( $filter_options['statuses'], true )
        );

        $this->render_select(
            'booking_type',
            __( 'Type réservation', 'analytic-suite' ),
            $filters['booking_type'],
            $this->build_options_array( $filter_options['booking_types'], true )
        );

        $this->render_select(
            'exclude_booking_type',
            __( 'Exclure type', 'analytic-suite' ),
            $filters['exclude_booking_type'],
            $this->build_options_array( $filter_options['exclude_booking_types'], true )
        );

        $this->render_select(
            'duration',
            __( 'Durée', 'analytic-suite' ),
            $filters['duration'] ? (string) $filters['duration'] : '',
            $this->build_options_array( $filter_options['durations'], true )
        );

        $this->render_select(
            'product',
            __( 'Produit', 'analytic-suite' ),
            $filters['product'] ? (string) $filters['product'] : '',
            $this->build_options_array( $filter_options['products'], true, true )
        );

        $this->render_select(
            'gender',
            __( 'Civilité', 'analytic-suite' ),
            $filters['gender'],
            $this->build_options_array( $filter_options['genders'], true )
        );

        $this->render_select(
            'customer',
            __( 'Client', 'analytic-suite' ),
            $filters['customer'],
            $this->build_options_array( $filter_options['customers'] ?? array(), true )
        );

        submit_button( __( 'Filtrer', 'analytic-suite' ), 'primary', '', false );
        echo '</form>';
    }

    /**
     * Builds an options array for select fields.
     *
     * @param array  $items      Items.
     * @param bool   $add_empty  Add empty option.
     * @param bool   $use_keys   Use items as keys.
     * @return array
     */
    private function build_options_array( $items, $add_empty = false, $use_keys = false ) {
        $options = array();

        if ( $add_empty ) {
            $options[''] = __( 'Tous', 'analytic-suite' );
        }

        if ( empty( $items ) ) {
            return $options;
        }

        foreach ( $items as $key => $value ) {
            if ( $use_keys ) {
                $options[ $key ] = $value;
            } else {
                $options[ $value ] = $value;
            }
        }

        return $options;
    }

    /**
     * Renders a select field.
     *
     * @param string $name    Field name.
     * @param string $label   Field label.
     * @param string $current Current value.
     * @param array  $options Options.
     */
    private function render_select( $name, $label, $current, $options ) {
        echo '<label><span>' . esc_html( $label ) . '</span>';
        echo '<select name="' . esc_attr( $name ) . '">';
        foreach ( $options as $value => $text ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( $text ) . '</option>';
        }
        echo '</select></label>';
    }

    /**
     * Renders an input field.
     *
     * @param string $name  Field name.
     * @param string $label Field label.
     * @param mixed  $value Field value.
     * @param string $type  Input type.
     */
    private function render_input( $name, $label, $value, $type ) {
        echo '<label><span>' . esc_html( $label ) . '</span>';
        echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
        echo '</label>';
    }

    /**
     * Renders dashboard cards and tables.
     *
     * @param array $data Dashboard data.
     */
    private function render_dashboard( $data ) {
        $summary = $data['summary'];
        $top_booking_country = $this->get_top_breakdown_label( $data['bookings']['country_breakdown'] );
        $top_booking_gender  = $this->get_top_breakdown_label( $data['bookings']['gender_breakdown'] );

        echo '<div class="analytic-suite-cards">';
        $this->render_card( __( 'Nb clients uniques', 'analytic-suite' ), $summary['unique_customers'] );
        $this->render_card( __( 'Nb paniers annulés', 'analytic-suite' ), $summary['cancelled_carts'] );
        $this->render_card( __( 'Réservations', 'analytic-suite' ), $summary['bookings'] );
        $this->render_card( __( 'Réservations annulées', 'analytic-suite' ), $summary['cancelled_bookings'] . ' (' . $summary['cancellation_rate'] . '%)' );
        $this->render_card( __( 'Clients produits répétés', 'analytic-suite' ), $summary['repeat_product_customers'] );
        $this->render_card( __( 'Panier moyen', 'analytic-suite' ), $this->format_price( $summary['average_order_value'] ) );
        $this->render_card( __( 'Pays #1 réservations', 'analytic-suite' ), $top_booking_country );
        $this->render_card( __( 'Civilité #1 réservations', 'analytic-suite' ), $top_booking_gender );
        $this->render_card( __( 'Durée dominante', 'analytic-suite' ), $data['bookings']['duration_summary']['leader'] );
        $this->render_card( __( 'Suivis masterclass', 'analytic-suite' ), $data['contents']['masterclass_follows'] );
        $this->render_card( __( 'Livres consultés', 'analytic-suite' ), $data['contents']['book_downloads'] );
        echo '</div>';

        if ( ! empty( $data['ga']['available'] ) ) {
            $this->render_ga_cards( $data['ga'] );
        }

        echo '<div class="analytic-suite-grid">';
        $this->render_breakdown_table( __( 'Dîners / Sessions / Diagnostics', 'analytic-suite' ), $data['bookings']['category_breakdown'] );
        $this->render_breakdown_table( __( 'Types de réservation détaillés', 'analytic-suite' ), $data['bookings']['type_breakdown'] );
        $this->render_breakdown_table( __( 'Durées de réservation', 'analytic-suite' ), $data['bookings']['duration_breakdown'] );
        $this->render_breakdown_table( __( 'Comparaison 30 min / 1h', 'analytic-suite' ), $this->format_duration_summary( $data['bookings']['duration_summary'] ) );
        $this->render_breakdown_table( __( 'Pays avec le plus de réservations', 'analytic-suite' ), $data['bookings']['country_breakdown'] );
        $this->render_breakdown_table( __( 'Civilité avec le plus de réservations', 'analytic-suite' ), $data['bookings']['gender_breakdown'] );
        $this->render_breakdown_table( __( 'Top masterclass', 'analytic-suite' ), $data['contents']['top_masterclasses'] );
        $this->render_breakdown_table( __( 'Top livres blancs', 'analytic-suite' ), $data['contents']['top_books'] );
        $this->render_breakdown_table( __( 'Statuts réservations', 'analytic-suite' ), $data['bookings']['status_breakdown'] );
        $this->render_breakdown_table( __( 'Statuts commandes', 'analytic-suite' ), $data['orders']['status_breakdown'] );

        if ( ! empty( $data['ga']['available'] ) ) {
            $this->render_ga_tables( $data['ga'] );
        }

        echo '</div>';
    }

    /**
     * Renders Google Analytics cards.
     *
     * @param array $ga GA data.
     */
    private function render_ga_cards( $ga ) {
        $summary = $ga['summary'];
        $realtime = $ga['realtime'] ?? array();

        echo '<h3 style="margin: 30px 0 16px; font-size: 16px; font-weight: 800;">' . esc_html__( 'Google Analytics', 'analytic-suite' ) . '</h3>';
        echo '<div class="analytic-suite-cards">';
        $this->render_card( __( 'Utilisateurs actifs', 'analytic-suite' ), $summary['active_users'] ?? 0 );
        $this->render_card( __( 'Sessions', 'analytic-suite' ), $summary['sessions'] ?? 0 );
        $this->render_card( __( 'Pages vues', 'analytic-suite' ), $summary['page_views'] ?? 0 );
        $this->render_card( __( 'Nouveaux utilisateurs', 'analytic-suite' ), $summary['new_users'] ?? 0 );
        $this->render_card( __( 'Durée moy. session', 'analytic-suite' ), $summary['avg_duration'] ?? '0s' );
        $this->render_card( __( 'Taux de rebond', 'analytic-suite' ), ( $summary['bounce_rate'] ?? 0 ) . '%' );
        if ( ! empty( $realtime['active_users'] ) ) {
            $this->render_card( __( 'Utilisateurs temps réel', 'analytic-suite' ), $realtime['active_users'] );
        }
        echo '</div>';
    }

    /**
     * Renders Google Analytics tables.
     *
     * @param array $ga GA data.
     */
    private function render_ga_tables( $ga ) {
        if ( ! empty( $ga['top_pages'] ) ) {
            $page_list = array();
            foreach ( $ga['top_pages'] as $page ) {
                $page_list[ $page['path'] ] = $page['views'];
            }
            $this->render_breakdown_table( __( 'Pages les plus visitées', 'analytic-suite' ), $page_list );
        }

        if ( ! empty( $ga['demographics']['cities'] ) ) {
            $this->render_breakdown_table( __( 'Villes (GA4)', 'analytic-suite' ), $ga['demographics']['cities'] );
        }

        if ( ! empty( $ga['demographics']['countries'] ) ) {
            $this->render_breakdown_table( __( 'Pays (GA4)', 'analytic-suite' ), $ga['demographics']['countries'] );
        }

        if ( ! empty( $ga['demographics']['devices'] ) ) {
            $this->render_breakdown_table( __( 'Appareils', 'analytic-suite' ), $ga['demographics']['devices'] );
        }

        if ( ! empty( $ga['traffic_sources'] ) ) {
            $this->render_breakdown_table( __( 'Sources de trafic', 'analytic-suite' ), $ga['traffic_sources'] );
        }
    }

    /**
     * Renders client analytics.
     *
     * @param array $data Dashboard data.
     */
    private function render_clients( $data ) {
        echo '<div class="analytic-suite-cards">';
        $this->render_card( __( 'Clients uniques', 'analytic-suite' ), $data['summary']['unique_customers'] );
        $this->render_card( __( 'Clients récurrents', 'analytic-suite' ), $data['summary']['recurring_customers'] );
        $this->render_card( __( 'Clients ayant repris un produit', 'analytic-suite' ), $data['summary']['repeat_product_customers'] );
        $this->render_card( __( 'Taux de fidélisation', 'analytic-suite' ), $data['orders']['retention_rate'] . '%' );
        echo '</div>';

        $this->render_breakdown_table( __( 'Réservations par pays', 'analytic-suite' ), $data['bookings']['country_breakdown'] );
        $this->render_breakdown_table( __( 'Civilité commandes', 'analytic-suite' ), $data['orders']['gender_breakdown'] );
        $this->render_breakdown_table( __( 'Civilité réservations', 'analytic-suite' ), $data['bookings']['gender_breakdown'] );
    }

    /**
     * Renders booking analytics.
     *
     * @param array $data Dashboard data.
     */
    private function render_bookings( $data ) {
        echo '<div class="analytic-suite-cards">';
        $this->render_card( __( 'Total réservations', 'analytic-suite' ), $data['bookings']['total_bookings'] );
        $this->render_card( __( 'Validées', 'analytic-suite' ), $data['bookings']['confirmed_bookings'] );
        $this->render_card( __( 'Annulées', 'analytic-suite' ), $data['bookings']['cancelled_bookings'] );
        $this->render_card( __( 'Taux d’annulation', 'analytic-suite' ), $data['bookings']['cancellation_rate'] . '%' );
        $this->render_card( __( 'Durée dominante', 'analytic-suite' ), $data['bookings']['duration_summary']['leader'] );
        echo '</div>';

        echo '<div class="analytic-suite-grid">';
        $this->render_breakdown_table( __( 'Dîners / Sessions / Diagnostics', 'analytic-suite' ), $data['bookings']['category_breakdown'] );
        $this->render_breakdown_table( __( 'Types de réservation', 'analytic-suite' ), $data['bookings']['type_breakdown'] );
        $this->render_breakdown_table( __( 'Durées', 'analytic-suite' ), $data['bookings']['duration_breakdown'] );
        $this->render_breakdown_table( __( 'Comparaison 30 min / 1h', 'analytic-suite' ), $this->format_duration_summary( $data['bookings']['duration_summary'] ) );
        $this->render_breakdown_table( __( 'Statuts', 'analytic-suite' ), $data['bookings']['status_breakdown'] );
        echo '</div>';
    }

    /**
     * Renders order analytics.
     *
     * @param array $data Dashboard data.
     */
    private function render_orders( $data ) {
        echo '<div class="analytic-suite-cards">';
        $this->render_card( __( 'Total commandes', 'analytic-suite' ), $data['orders']['total_orders'] );
        $this->render_card( __( 'Paniers annulés', 'analytic-suite' ), $data['orders']['cancelled_orders'] );
        $this->render_card( __( 'Chiffre d’affaires', 'analytic-suite' ), $this->format_price( $data['orders']['revenue'] ) );
        $this->render_card( __( 'Panier moyen', 'analytic-suite' ), $this->format_price( $data['orders']['average_order_value'] ) );
        echo '</div>';

        $this->render_product_table( $data['orders']['product_sales'] );
    }

    /**
     * Renders content analytics.
     *
     * @param array $data Dashboard data.
     */
    private function render_contents( $data ) {
        echo '<div class="analytic-suite-cards">';
        $this->render_card( __( 'Masterclass publiées', 'analytic-suite' ), $data['contents']['total_masterclasses'] );
        $this->render_card( __( 'Masterclass suivies', 'analytic-suite' ), $data['contents']['masterclass_follows'] );
        $this->render_card( __( 'Utilisateurs masterclass', 'analytic-suite' ), $data['contents']['masterclass_users'] );
        $this->render_card( __( 'Masterclass avec replay', 'analytic-suite' ), $data['contents']['masterclass_replays'] );
        $this->render_card( __( 'Masterclass à venir', 'analytic-suite' ), $data['contents']['upcoming_masterclasses'] );
        $this->render_card( __( 'Livres publiés', 'analytic-suite' ), $data['contents']['total_books'] );
        $this->render_card( __( 'Livres consultés', 'analytic-suite' ), $data['contents']['book_downloads'] );
        $this->render_card( __( 'Utilisateurs livres', 'analytic-suite' ), $data['contents']['book_users'] );
        echo '</div>';

        echo '<div class="analytic-suite-grid">';
        $this->render_breakdown_table( __( 'Top masterclass suivies', 'analytic-suite' ), $data['contents']['top_masterclasses'] );
        $this->render_breakdown_table( __( 'Top livres consultés', 'analytic-suite' ), $data['contents']['top_books'] );
        $this->render_breakdown_table( __( 'Suivis masterclass par mois', 'analytic-suite' ), $data['contents']['masterclass_by_month'] );
        $this->render_breakdown_table( __( 'Consultations livres par mois', 'analytic-suite' ), $data['contents']['books_by_month'] );
        echo '</div>';
    }

    /**
     * Renders reports page.
     *
     * @param array $data Dashboard data.
     */
    private function render_reports( $data ) {
        echo '<div class="analytic-suite-panel">';
        echo '<h2>' . esc_html__( 'Rapport de synthèse', 'analytic-suite' ) . '</h2>';
        echo '<p>' . esc_html( sprintf( __( 'Généré le %s.', 'analytic-suite' ), $data['generated_at'] ) ) . '</p>';
        echo '</div>';
        $this->render_dashboard( $data );
    }

    /**
     * Renders export page.
     *
     * @param array $filters Filters.
     */
    private function render_exports( $filters ) {
        echo '<div class="analytic-suite-panel">';
        echo '<h2>' . esc_html__( 'Exporter les données', 'analytic-suite' ) . '</h2>';
        echo '<p>' . esc_html__( 'Téléchargez la synthèse avec les filtres actuellement appliqués.', 'analytic-suite' ) . '</p>';
        echo '<div class="analytic-suite-export-actions">';
        $this->render_export_form( 'analytic_suite_export_csv', 'analytic_suite_export_csv', __( 'Télécharger CSV', 'analytic-suite' ), $filters );
        $this->render_export_form( 'analytic_suite_export_excel', 'analytic_suite_export_excel', __( 'Télécharger Excel', 'analytic-suite' ), $filters );
        $this->render_export_form( 'analytic_suite_export_pdf', 'analytic_suite_export_pdf', __( 'Télécharger PDF', 'analytic-suite' ), $filters );
        echo '</div></div>';
    }

    /**
     * Renders an export form.
     *
     * @param string $action  Admin-post action.
     * @param string $nonce   Nonce action.
     * @param string $label   Button label.
     * @param array  $filters Current filters.
     */
    private function render_export_form( $action, $nonce, $label, $filters ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
        wp_nonce_field( $nonce );

        foreach ( $filters as $key => $value ) {
            echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
        }

        submit_button( $label, 'primary', 'submit', false );
        echo '</form>';
    }

    /**
     * Renders settings page.
     *
     * @param array $data Dashboard data.
     */
    private function render_settings( $data ) {
        echo '<div class="analytic-suite-panel">';
        echo '<h2>' . esc_html__( 'État des intégrations', 'analytic-suite' ) . '</h2>';
        echo '<table class="widefat striped"><tbody>';
        echo '<tr><th>' . esc_html__( 'WooCommerce', 'analytic-suite' ) . '</th><td>' . esc_html( $data['orders']['available'] ? __( 'Détecté', 'analytic-suite' ) : __( 'Non détecté', 'analytic-suite' ) ) . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'FluentBooking', 'analytic-suite' ) . '</th><td>' . esc_html( $data['bookings']['available'] ? __( 'Détecté', 'analytic-suite' ) : __( 'Non détecté', 'analytic-suite' ) ) . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Table user_masterclass', 'analytic-suite' ) . '</th><td>' . esc_html( $data['contents']['masterclass_table'] ? __( 'Détectée', 'analytic-suite' ) : __( 'Non détectée', 'analytic-suite' ) ) . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Table user_livres', 'analytic-suite' ) . '</th><td>' . esc_html( $data['contents']['books_table'] ? __( 'Détectée', 'analytic-suite' ) : __( 'Non détectée', 'analytic-suite' ) ) . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Dernière synchronisation', 'analytic-suite' ) . '</th><td>' . esc_html( get_option( 'analytic_suite_last_sync', __( 'Jamais', 'analytic-suite' ) ) ) . '</td></tr>';
        echo '</tbody></table></div>';

        $this->render_ga_settings();
    }

    /**
     * Renders Google Analytics settings.
     */
    private function render_ga_settings() {
        echo '<div class="analytic-suite-panel">';
        echo '<h2>' . esc_html__( 'Google Analytics 4', 'analytic-suite' ) . '</h2>';

        if ( isset( $_POST['analytic_suite_save_ga'] ) && check_admin_referer( 'analytic_suite_ga_settings' ) ) {
            update_option( 'analytic_suite_ga_property_id', sanitize_text_field( wp_unslash( $_POST['ga_property_id'] ?? '' ) ) );
            update_option( 'analytic_suite_ga_credentials', sanitize_text_field( wp_unslash( $_POST['ga_credentials'] ?? '' ) ) );

            if ( ! empty( $_POST['ga_clear_cache'] ) ) {
                $ga = new Analytic_Suite_Google_Analytics();
                $ga->clear_cache();
            }

            echo '<div class="notice notice-success"><p>' . esc_html__( 'Paramètres enregistrés.', 'analytic-suite' ) . '</div>';
        }

        $ga = new Analytic_Suite_Google_Analytics();
        $test = $ga->test_connection();

        echo '<form method="post">';
        wp_nonce_field( 'analytic_suite_ga_settings' );

        echo '<table class="widefat"><tbody>';
        echo '<tr><th>' . esc_html__( 'Property ID GA4', 'analytic-suite' ) . '</th>';
        echo '<td><input type="text" name="ga_property_id" value="' . esc_attr( get_option( 'analytic_suite_ga_property_id', '' ) ) . '" class="regular-text" placeholder="XXXXXXXXX">';
        echo '<p class="description">Ex: 1234567890 (dans GA4 > Administration > Propriété)</p></td></tr>';

        echo '<tr><th>' . esc_html__( 'Clé JSON Service Account', 'analytic-suite' ) . '</th>';
        echo '<td><textarea name="ga_credentials" rows="6" class="large-text code" placeholder=\'{"type":"service_account",...}\'>' . esc_attr( get_option( 'analytic_suite_ga_credentials', '' ) ) . '</textarea>';
        echo '<p class="description">' . esc_html__( 'Copier le contenu du fichier JSON du compte de service Google. Donner le rôle "Lecteur" à l\'email du service account dans GA4.', 'analytic-suite' ) . '</p></td></tr>';

        echo '<tr><th>' . esc_html__( 'Statut', 'analytic-suite' ) . '</th>';
        echo '<td>';
        if ( $test['success'] ) {
            echo '<span style="color: #0f766e; font-weight: 700;">✓ ' . esc_html( $test['message'] ) . '</span>';
        } else {
            echo '<span style="color: #b42318;">✗ ' . esc_html( $test['message'] ) . '</span>';
        }
        echo '</td></tr>';

        echo '<tr><th></th><td>';
        echo '<label><input type="checkbox" name="ga_clear_cache" value="1"> ' . esc_html__( 'Vider le cache GA', 'analytic-suite' ) . '</label>';
        echo '</td></tr>';

        echo '</tbody></table>';

        submit_button( __( 'Enregistrer', 'analytic-suite' ), 'primary', 'analytic_suite_save_ga', false );
        echo '</form></div>';
    }

    /**
     * Renders a stat card.
     *
     * @param string $label Label.
     * @param mixed  $value Value.
     */
    private function render_card( $label, $value ) {
        echo '<div class="analytic-suite-card"><span>' . esc_html( $label ) . '</span><strong>' . wp_kses_post( (string) $value ) . '</strong></div>';
    }

    /**
     * Renders a key/value breakdown.
     *
     * @param string $title Title.
     * @param array  $items Items.
     */
    private function render_breakdown_table( $title, $items ) {
        echo '<div class="analytic-suite-panel"><h2>' . esc_html( $title ) . '</h2>';

        if ( empty( $items ) ) {
            echo '<p>' . esc_html__( 'Aucune donnée disponible.', 'analytic-suite' ) . '</p></div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Libellé', 'analytic-suite' ) . '</th><th>' . esc_html__( 'Valeur', 'analytic-suite' ) . '</th></tr></thead><tbody>';
        foreach ( $items as $label => $value ) {
            echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( $value ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * Gets the first label from a sorted breakdown.
     *
     * @param array $items Items.
     * @return string
     */
    private function get_top_breakdown_label( $items ) {
        if ( empty( $items ) ) {
            return __( 'Aucune donnée', 'analytic-suite' );
        }

        $label = key( $items );
        $value = current( $items );

        return $label . ' (' . $value . ')';
    }

    /**
     * Formats duration summary for display.
     *
     * @param array $summary Summary.
     * @return array
     */
    private function format_duration_summary( $summary ) {
        return array(
            __( 'Sessions de 30 min', 'analytic-suite' ) => isset( $summary['30 min'] ) ? $summary['30 min'] : 0,
            __( 'Sessions de 1h', 'analytic-suite' )     => isset( $summary['1h'] ) ? $summary['1h'] : 0,
            __( 'Plus fréquent', 'analytic-suite' )      => isset( $summary['leader'] ) ? $summary['leader'] : __( 'Égalité', 'analytic-suite' ),
        );
    }

    /**
     * Renders product sales.
     *
     * @param array $items Product sales.
     */
    private function render_product_table( $items ) {
        echo '<div class="analytic-suite-panel"><h2>' . esc_html__( 'Répartition des ventes par produit', 'analytic-suite' ) . '</h2>';

        if ( empty( $items ) ) {
            echo '<p>' . esc_html__( 'Aucune donnée disponible.', 'analytic-suite' ) . '</p></div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Produit', 'analytic-suite' ) . '</th><th>' . esc_html__( 'Quantité', 'analytic-suite' ) . '</th><th>' . esc_html__( 'CA', 'analytic-suite' ) . '</th></tr></thead><tbody>';
        foreach ( $items as $item ) {
            echo '<tr><td>' . esc_html( $item['name'] ) . '</td><td>' . esc_html( $item['quantity'] ) . '</td><td>' . wp_kses_post( $this->format_price( $item['revenue'] ) ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * Formats a monetary value with WooCommerce when available.
     *
     * @param float $amount Amount.
     * @return string
     */
    private function format_price( $amount ) {
        if ( function_exists( 'wc_price' ) ) {
            return wc_price( $amount );
        }

        return number_format_i18n( (float) $amount, 2 );
    }
}
