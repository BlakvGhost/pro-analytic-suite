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
     * Constructor.
     *
     * @param Analytic_Suite_Order_Repository   $orders   Order repository.
     * @param Analytic_Suite_Booking_Repository $bookings Booking repository.
     */
    public function __construct( Analytic_Suite_Order_Repository $orders, Analytic_Suite_Booking_Repository $bookings ) {
        $this->orders   = $orders;
        $this->bookings = $bookings;
    }

    /**
     * Gets normalized filters from request input.
     *
     * @param array $source Input source.
     * @return array
     */
    public function get_filters_from_request( $source ) {
        $period = isset( $source['period'] ) ? sanitize_key( wp_unslash( $source['period'] ) ) : '30-days';

        $filters = array(
            'period'       => $period,
            'date_from'    => isset( $source['date_from'] ) ? sanitize_text_field( wp_unslash( $source['date_from'] ) ) : '',
            'date_to'      => isset( $source['date_to'] ) ? sanitize_text_field( wp_unslash( $source['date_to'] ) ) : '',
            'country'      => isset( $source['country'] ) ? sanitize_text_field( wp_unslash( $source['country'] ) ) : '',
            'status'       => isset( $source['status'] ) ? sanitize_text_field( wp_unslash( $source['status'] ) ) : '',
            'booking_type' => isset( $source['booking_type'] ) ? sanitize_text_field( wp_unslash( $source['booking_type'] ) ) : '',
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

        return array(
            'summary'      => array(
                'orders'              => $order_metrics['total_orders'],
                'bookings'            => $booking_metrics['total_bookings'],
                'revenue'             => $order_metrics['revenue'],
                'average_order_value' => $order_metrics['average_order_value'],
                'unique_customers'    => max( $order_metrics['unique_customers'], $booking_metrics['unique_customers'] ),
                'recurring_customers' => $order_metrics['recurring_customers'],
                'cancellation_rate'   => $booking_metrics['cancellation_rate'],
            ),
            'orders'       => $order_metrics,
            'bookings'     => $booking_metrics,
            'generated_at' => current_time( 'mysql' ),
        );
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
            array( 'Taux d’annulation', $data['summary']['cancellation_rate'] . '%' ),
        );
    }

    /**
     * Resolves shortcut periods into dates.
     *
     * @param array $filters Filters.
     * @return array
     */
    private function resolve_period_dates( $filters ) {
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
