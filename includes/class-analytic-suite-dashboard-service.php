<?php
/**
 * Dashboard analytics orchestration.
 *
 * @package Analytic_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Combines WooCommerce and FluentBooking metrics.
 */
class Analytic_Suite_Dashboard_Service {

    /**
     * Order repository.
     *
     * @var Analytic_Suite_Order_Repository
     */
    private $orders;

    /**
     * Booking repository.
     *
     * @var Analytic_Suite_Booking_Repository
     */
    private $bookings;

    /**
     * Content repository.
     *
     * @var Analytic_Suite_Content_Repository
     */
    private $contents;

    /**
     * Google Analytics service.
     *
     * @var Analytic_Suite_Google_Analytics
     */
    private $ga;

    /**
     * Constructor.
     *
     * @param Analytic_Suite_Order_Repository   $orders   Order repository.
     * @param Analytic_Suite_Booking_Repository $bookings Booking repository.
     * @param Analytic_Suite_Content_Repository $contents Content repository.
     */
    public function __construct( Analytic_Suite_Order_Repository $orders, Analytic_Suite_Booking_Repository $bookings, Analytic_Suite_Content_Repository $contents ) {
        $this->orders   = $orders;
        $this->bookings = $bookings;
        $this->contents = $contents;
        $this->ga       = new Analytic_Suite_Google_Analytics();
    }

    /**
     * Gets normalized filters from request input.
     *
     * @param array $source Input source.
     * @return array
     */
    public function get_filters_from_request( $source ) {
        $period = isset( $source['period'] ) ? sanitize_key( wp_unslash( $source['period'] ) ) : 'all';

        $filters = array(
            'period'       => $period,
            'date_from'    => isset( $source['date_from'] ) ? sanitize_text_field( wp_unslash( $source['date_from'] ) ) : '',
            'date_to'      => isset( $source['date_to'] ) ? sanitize_text_field( wp_unslash( $source['date_to'] ) ) : '',
            'country'      => isset( $source['country'] ) ? sanitize_text_field( wp_unslash( $source['country'] ) ) : '',
            'status'       => isset( $source['status'] ) ? sanitize_text_field( wp_unslash( $source['status'] ) ) : '',
            'booking_type' => isset( $source['booking_type'] ) ? sanitize_text_field( wp_unslash( $source['booking_type'] ) ) : '',
            'exclude_booking_type' => isset( $source['exclude_booking_type'] ) ? sanitize_text_field( wp_unslash( $source['exclude_booking_type'] ) ) : '',
            'duration'     => isset( $source['duration'] ) ? absint( wp_unslash( $source['duration'] ) ) : 0,
            'product'      => isset( $source['product'] ) ? absint( wp_unslash( $source['product'] ) ) : 0,
            'gender'       => isset( $source['gender'] ) ? sanitize_text_field( wp_unslash( $source['gender'] ) ) : '',
            'customer'     => isset( $source['customer'] ) ? sanitize_text_field( wp_unslash( $source['customer'] ) ) : '',
        );

        return $this->resolve_period_dates( $filters );
    }

    /**
     * Gets all dashboard data.
     *
     * @param array $filters Filters.
     * @return array
     */
    public function get_dashboard_data( $filters ) {
        $order_metrics   = $this->orders->get_metrics( $filters );
        $booking_metrics = $this->bookings->get_metrics( $filters );
        $content_metrics = $this->contents->get_metrics( $filters );
        $ga_summary      = $this->ga->is_configured() ? $this->ga->get_summary( $filters ) : array();

        return array(
            'summary'      => array(
                'orders'              => $order_metrics['total_orders'],
                'bookings'            => $booking_metrics['total_bookings'],
                'revenue'             => $order_metrics['revenue'],
                'average_order_value' => $order_metrics['average_order_value'],
                'unique_customers'    => max( $order_metrics['unique_customers'], $booking_metrics['unique_customers'] ),
                'recurring_customers' => $order_metrics['recurring_customers'],
                'repeat_product_customers' => $order_metrics['repeat_product_customers'],
                'cancelled_carts'     => $order_metrics['cancelled_orders'],
                'cancellation_rate'   => $booking_metrics['cancellation_rate'],
                'cancelled_bookings'  => $booking_metrics['cancelled_bookings'],
                'masterclass_users'   => $content_metrics['masterclass_users'],
                'book_users'          => $content_metrics['book_users'],
                'ga_active_users'     => $ga_summary['active_users'] ?? 0,
                'ga_sessions'         => $ga_summary['sessions'] ?? 0,
                'ga_page_views'       => $ga_summary['page_views'] ?? 0,
            ),
            'orders'       => $order_metrics,
            'bookings'     => $booking_metrics,
            'contents'     => $content_metrics,
            'ga'           => $this->get_ga_data( $filters ),
            'ga_status'    => $this->ga->get_status(),
            'generated_at' => current_time( 'mysql' ),
        );
    }

    /**
     * Gets Google Analytics data.
     *
     * @param array $filters Filters.
     * @return array
     */
    public function get_ga_data( $filters ) {
        if ( ! $this->ga->is_configured() ) {
            return array(
                'available'       => false,
                'configured'      => false,
                'status'          => $this->ga->get_status(),
                'summary'         => array(),
                'top_pages'       => array(),
                'demographics'    => array(),
                'traffic_sources' => array(),
            );
        }

        $data = array(
            'available'        => true,
            'configured'       => true,
            'summary'         => $this->ga->get_summary( $filters ),
            'top_pages'       => $this->ga->get_page_views( $filters ),
            'demographics'   => $this->ga->get_demographics( $filters ),
            'traffic_sources' => $this->ga->get_traffic_sources( $filters ),
            'realtime'        => $this->ga->get_realtime_users(),
            'status'          => $this->ga->get_status(),
        );

        $data['available'] = empty( $data['status']['last_error'] );

        return $data;
    }

    /**
     * Gets GA configuration status.
     *
     * @return array
     */
    public function get_ga_status() {
        return array(
            'configured' => $this->ga->is_configured(),
            'status'     => $this->ga->get_status(),
            'test'       => $this->ga->test_connection(),
        );
    }

    /**
     * Clears GA cache.
     */
    public function clear_ga_cache() {
        $this->ga->clear_cache();
    }

    /**
     * Builds CSV rows for export.
     *
     * @param array $filters Filters.
     * @return array
     */
    public function get_export_rows( $filters ) {
        $data = $this->get_dashboard_data( $filters );

        return array(
            array( 'Indicateur', 'Valeur' ),
            array( 'Commandes', $data['summary']['orders'] ),
            array( 'Réservations', $data['summary']['bookings'] ),
            array( 'Chiffre d’affaires', $data['summary']['revenue'] ),
            array( 'Panier moyen', $data['summary']['average_order_value'] ),
            array( 'Clients uniques', $data['summary']['unique_customers'] ),
            array( 'Clients récurrents', $data['summary']['recurring_customers'] ),
            array( 'Clients ayant repris un produit', $data['summary']['repeat_product_customers'] ),
            array( 'Paniers annulés', $data['summary']['cancelled_carts'] ),
            array( 'Réservations annulées', $data['summary']['cancelled_bookings'] ),
            array( 'Taux d’annulation', $data['summary']['cancellation_rate'] . '%' ),
            array( 'Dîners', isset( $data['bookings']['category_breakdown']['Dîner'] ) ? $data['bookings']['category_breakdown']['Dîner'] : 0 ),
            array( 'Sessions', isset( $data['bookings']['category_breakdown']['Session'] ) ? $data['bookings']['category_breakdown']['Session'] : 0 ),
            array( 'Diagnostics stratégiques', isset( $data['bookings']['category_breakdown']['Diagnostic stratégique'] ) ? $data['bookings']['category_breakdown']['Diagnostic stratégique'] : 0 ),
            array( 'Sessions 30 min', $data['bookings']['duration_summary']['30 min'] ),
            array( 'Sessions 1h', $data['bookings']['duration_summary']['1h'] ),
            array( 'Durée dominante', $data['bookings']['duration_summary']['leader'] ),
            array( 'Masterclass suivies', $data['contents']['masterclass_follows'] ),
            array( 'Utilisateurs masterclass', $data['contents']['masterclass_users'] ),
            array( 'Livres consultés', $data['contents']['book_downloads'] ),
            array( 'Utilisateurs livres', $data['contents']['book_users'] ),
            array( 'Masterclass avec replay', $data['contents']['masterclass_replays'] ),
            array( 'Masterclass à venir', $data['contents']['upcoming_masterclasses'] ),
        );
    }

    /**
     * Gets all available filter options from data sources.
     *
     * @return array
     */
    public function get_filter_options() {
        $empty_filters = array(
            'period'       => 'all',
            'date_from'    => '',
            'date_to'      => '',
            'country'      => '',
            'status'       => '',
            'booking_type' => '',
            'exclude_booking_type' => '',
            'duration'     => 0,
            'product'      => 0,
            'gender'       => '',
            'customer'     => '',
        );

        $order_metrics   = $this->orders->get_metrics( $empty_filters );
        $booking_metrics = $this->bookings->get_metrics( $empty_filters );

        $products = array();
        foreach ( $order_metrics['product_sales'] ?? array() as $key => $product ) {
            if ( is_numeric( $key ) ) {
                $products[ $key ] = $product['name'];
            } else {
                $products[ $product['name'] ] = $product['name'];
            }
        }

        return array(
            'countries' => $this->merge_filter_options(
                array_keys( $order_metrics['country_sales'] ?? array() ),
                array_keys( $booking_metrics['country_breakdown'] ?? array() )
            ),
            'statuses' => $this->merge_filter_options(
                array_keys( $order_metrics['status_breakdown'] ?? array() ),
                array_keys( $booking_metrics['status_breakdown'] ?? array() )
            ),
            'booking_types' => array_keys( $booking_metrics['type_breakdown'] ?? array() ),
            'exclude_booking_types' => array_keys( $booking_metrics['type_breakdown'] ?? array() ),
            'durations' => $this->normalize_duration_options( array_keys( $booking_metrics['duration_breakdown'] ?? array() ) ),
            'products' => $products,
            'genders' => $this->merge_filter_options(
                array_keys( $order_metrics['gender_breakdown'] ?? array() ),
                array_keys( $booking_metrics['gender_breakdown'] ?? array() )
            ),
            'customers' => $this->get_customer_options( $order_metrics, $booking_metrics ),
        );
    }

    /**
     * Normalizes duration options to extract numeric values.
     *
     * @param array $durations Duration strings.
     * @return array
     */
    private function normalize_duration_options( $durations ) {
        $normalized = array();
        foreach ( $durations as $duration ) {
            preg_match( '/(\d+)/', $duration, $matches );
            if ( ! empty( $matches[1] ) ) {
                $normalized[ $matches[1] ] = $duration;
            }
        }
        return $normalized;
    }

    /**
     * Gets customer email options from metrics.
     *
     * @param array $order_metrics   Order metrics.
     * @param array $booking_metrics Booking metrics.
     * @return array
     */
    private function get_customer_options( $order_metrics, $booking_metrics ) {
        $customers = array();

        if ( ! empty( $order_metrics['customer_emails'] ) ) {
            foreach ( $order_metrics['customer_emails'] as $email ) {
                $customers[ $email ] = $email;
            }
        }

        if ( ! empty( $booking_metrics['customer_emails'] ) ) {
            foreach ( $booking_metrics['customer_emails'] as $email ) {
                $customers[ $email ] = $email;
            }
        }

        ksort( $customers );
        return $customers;
    }

    /**
     * Merges two arrays of filter options, removing duplicates.
     *
     * @param array $arr1 First array.
     * @param array $arr2 Second array.
     * @return array
     */
    private function merge_filter_options( $arr1, $arr2 ) {
        return array_unique( array_merge( $arr1, $arr2 ) );
    }

    /**
     * Resolves shortcut periods into dates.
     *
     * @param array $filters Filters.
     * @return array
     */
    private function resolve_period_dates( $filters ) {
        if ( 'all' === $filters['period'] ) {
            $filters['date_from'] = '';
            $filters['date_to']   = '';

            return $filters;
        }

        if ( 'custom' === $filters['period'] ) {
            return $filters;
        }

        $today = current_time( 'Y-m-d' );

        if ( '7-days' === $filters['period'] ) {
            $filters['date_from'] = gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) );
            $filters['date_to']   = $today;
        } elseif ( 'year' === $filters['period'] ) {
            $filters['date_from'] = gmdate( 'Y-01-01', strtotime( $today ) );
            $filters['date_to']   = $today;
        } else {
            $filters['period']    = '30-days';
            $filters['date_from'] = gmdate( 'Y-m-d', strtotime( $today . ' -29 days' ) );
            $filters['date_to']   = $today;
        }

        return $filters;
    }
}
