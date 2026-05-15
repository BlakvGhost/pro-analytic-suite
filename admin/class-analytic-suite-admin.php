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

        $filters = $this->dashboard_service->get_filters_from_request( $_GET );
        $data    = $this->dashboard_service->get_dashboard_data( $filters );
        $titles  = $this->get_page_titles();
        $title   = isset( $titles[ $view ] ) ? $titles[ $view ] : $titles['dashboard'];

        echo '<div class="wrap analytic-suite">';
        echo '<h1>' . esc_html( $title ) . '</h1>';

        $this->render_notices( $data );
        $this->render_filters( $filters, $view );

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
     * @param array  $filters Filters.
     * @param string $view    Current view.
     */
    private function render_filters( $filters, $view ) {
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
        $this->render_input( 'country', __( 'Pays', 'analytic-suite' ), $filters['country'], 'text' );
        $this->render_input( 'status', __( 'Statut', 'analytic-suite' ), $filters['status'], 'text' );
        $this->render_input( 'booking_type', __( 'Type réservation', 'analytic-suite' ), $filters['booking_type'], 'text' );
        $this->render_input( 'exclude_booking_type', __( 'Exclure type', 'analytic-suite' ), $filters['exclude_booking_type'], 'text' );
        $this->render_input( 'duration', __( 'Durée', 'analytic-suite' ), $filters['duration'] ? $filters['duration'] : '', 'number' );
        $this->render_input( 'product', __( 'Produit ID', 'analytic-suite' ), $filters['product'] ? $filters['product'] : '', 'number' );
        $this->render_input( 'gender', __( 'Civilité', 'analytic-suite' ), $filters['gender'], 'text' );
        $this->render_input( 'customer', __( 'Client', 'analytic-suite' ), $filters['customer'], 'text' );

        submit_button( __( 'Filtrer', 'analytic-suite' ), 'primary', '', false );
        echo '</form>';
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
        echo '</div>';
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
        echo '<p>' . esc_html__( 'CSV est disponible dans cette première version. PDF et XLSX pourront être ajoutés avec une librairie dédiée.', 'analytic-suite' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="analytic_suite_export_csv">';
        wp_nonce_field( 'analytic_suite_export_csv' );

        foreach ( $filters as $key => $value ) {
            echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
        }

        submit_button( __( 'Télécharger le CSV', 'analytic-suite' ), 'primary', 'submit', false );
        echo '</form></div>';
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
