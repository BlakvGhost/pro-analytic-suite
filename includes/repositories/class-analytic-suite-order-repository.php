<?php
/**
 * WooCommerce order analytics.
 *
 * @package Analytic_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reads WooCommerce orders and computes metrics.
 */
class Analytic_Suite_Order_Repository {

    /**
     * Gets order metrics.
     *
     * @param array $filters Filters.
     * @return array
     */
    public function get_metrics( $filters ) {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return $this->empty_metrics();
        }

        $orders = wc_get_orders( $this->build_query_args( $filters ) );

        $revenue          = 0.0;
        $customers        = array();
        $customer_orders  = array();
        $country_sales    = array();
        $product_sales    = array();
        $status_breakdown = array();

        foreach ( $orders as $order ) {
            if ( ! $order instanceof WC_Order ) {
                continue;
            }

            $total   = (float) $order->get_total();
            $status  = $order->get_status();
            $country = $order->get_billing_country();
            $email   = strtolower( (string) $order->get_billing_email() );

            $revenue += $total;

            if ( '' !== $email ) {
                $customers[ $email ] = true;
                if ( ! isset( $customer_orders[ $email ] ) ) {
                    $customer_orders[ $email ] = 0;
                }
                $customer_orders[ $email ]++;
            }

            if ( '' !== $country ) {
                $country_sales[ $country ] = isset( $country_sales[ $country ] ) ? $country_sales[ $country ] + $total : $total;
            }

            $status_breakdown[ $status ] = isset( $status_breakdown[ $status ] ) ? $status_breakdown[ $status ] + 1 : 1;

            foreach ( $order->get_items() as $item ) {
                $product_id = $item->get_product_id();
                $name       = $item->get_name();
                $key        = $product_id ? (string) $product_id : $name;

                if ( ! isset( $product_sales[ $key ] ) ) {
                    $product_sales[ $key ] = array(
                        'name'     => $name,
                        'quantity' => 0,
                        'revenue'  => 0.0,
                    );
                }

                $product_sales[ $key ]['quantity'] += (int) $item->get_quantity();
                $product_sales[ $key ]['revenue']  += (float) $item->get_total();
            }
        }

        $total_orders        = count( $orders );
        $recurring_customers = count(
            array_filter(
                $customer_orders,
                function ( $count ) {
                    return $count > 1;
                }
            )
        );

        arsort( $country_sales );
        uasort(
            $product_sales,
            function ( $left, $right ) {
                return $right['revenue'] <=> $left['revenue'];
            }
        );
        arsort( $status_breakdown );

        return array(
            'available'           => true,
            'total_orders'        => $total_orders,
            'revenue'             => round( $revenue, 2 ),
            'average_order_value' => $total_orders > 0 ? round( $revenue / $total_orders, 2 ) : 0,
            'unique_customers'    => count( $customers ),
            'recurring_customers' => $recurring_customers,
            'retention_rate'      => count( $customers ) > 0 ? round( ( $recurring_customers / count( $customers ) ) * 100, 2 ) : 0,
            'country_sales'       => array_slice( $country_sales, 0, 10, true ),
            'product_sales'       => array_slice( array_values( $product_sales ), 0, 10 ),
            'status_breakdown'    => $status_breakdown,
        );
    }

    /**
     * Builds WooCommerce order query args.
     *
     * @param array $filters Filters.
     * @return array
     */
    private function build_query_args( $filters ) {
        $args = array(
            'limit'   => -1,
            'return'  => 'objects',
            'orderby' => 'date',
            'order'   => 'DESC',
        );

        if ( ! empty( $filters['status'] ) ) {
            $args['status'] = array( sanitize_key( $filters['status'] ) );
        } else {
            $args['status'] = array( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-cancelled', 'wc-refunded', 'wc-failed' );
        }

        if ( ! empty( $filters['date_from'] ) || ! empty( $filters['date_to'] ) ) {
            $from                = ! empty( $filters['date_from'] ) ? $filters['date_from'] : '1970-01-01';
            $to                  = ! empty( $filters['date_to'] ) ? $filters['date_to'] : current_time( 'Y-m-d' );
            $args['date_created'] = $from . '...' . $to;
        }

        if ( ! empty( $filters['product'] ) ) {
            $args['product_id'] = absint( $filters['product'] );
        }

        if ( ! empty( $filters['customer'] ) && is_email( $filters['customer'] ) ) {
            $args['billing_email'] = sanitize_email( $filters['customer'] );
        }

        return $args;
    }

    /**
     * Empty response when WooCommerce is not available.
     *
     * @return array
     */
    private function empty_metrics() {
        return array(
            'available'           => false,
            'total_orders'        => 0,
            'revenue'             => 0,
            'average_order_value' => 0,
            'unique_customers'    => 0,
            'recurring_customers' => 0,
            'retention_rate'      => 0,
            'country_sales'       => array(),
            'product_sales'       => array(),
            'status_breakdown'    => array(),
        );
    }
}
