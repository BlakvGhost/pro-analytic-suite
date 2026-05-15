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
        $gender_breakdown = array();
        $customer_products = array();
        $cancelled_orders = 0;
        $revenue_order_count = 0;

        foreach ( $orders as $order ) {
            if ( ! $order instanceof WC_Order ) {
                continue;
            }

            $total   = (float) $order->get_total();
            $status  = $order->get_status();
            $country = $order->get_billing_country();
            $email   = strtolower( (string) $order->get_billing_email() );
            $gender  = $this->normalize_gender( $order->get_meta( 'gender_' ) );

            $is_revenue_order = ! in_array( $status, array( 'cancelled', 'failed', 'refunded' ), true );

            if ( 'cancelled' === $status ) {
                $cancelled_orders++;
            }

            if ( $is_revenue_order ) {
                $revenue += $total;
                $revenue_order_count++;
            }

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

            if ( '' !== $gender ) {
                $gender_breakdown[ $gender ] = isset( $gender_breakdown[ $gender ] ) ? $gender_breakdown[ $gender ] + 1 : 1;
            }

            foreach ( $order->get_items() as $item ) {
                $product_id = $item->get_product_id();
                $name       = $item->get_name();
                $key        = $product_id ? (string) $product_id : $name;

                if ( '' !== $email && $is_revenue_order ) {
                    if ( ! isset( $customer_products[ $email ] ) ) {
                        $customer_products[ $email ] = array();
                    }

                    if ( ! isset( $customer_products[ $email ][ $key ] ) ) {
                        $customer_products[ $email ][ $key ] = 0;
                    }

                    $customer_products[ $email ][ $key ] += (int) $item->get_quantity();
                }

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
        $repeat_product_customers = $this->count_repeat_product_customers( $customer_products );

        arsort( $country_sales );
        arsort( $gender_breakdown );
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
            'cancelled_orders'    => $cancelled_orders,
            'revenue'             => round( $revenue, 2 ),
            'average_order_value' => $revenue_order_count > 0 ? round( $revenue / $revenue_order_count, 2 ) : 0,
            'unique_customers'    => count( $customers ),
            'recurring_customers' => $recurring_customers,
            'repeat_product_customers' => $repeat_product_customers,
            'retention_rate'      => count( $customers ) > 0 ? round( ( $recurring_customers / count( $customers ) ) * 100, 2 ) : 0,
            'country_sales'       => array_slice( $country_sales, 0, 10, true ),
            'gender_breakdown'    => $gender_breakdown,
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

        if ( ! empty( $filters['gender'] ) ) {
            $args['meta_query'] = array(
                array(
                    'key'     => 'gender_',
                    'value'   => sanitize_text_field( $filters['gender'] ),
                    'compare' => '=',
                ),
            );
        }

        return $args;
    }

    /**
     * Normalizes the site's gender metadata.
     *
     * @param string $gender Raw gender.
     * @return string
     */
    private function normalize_gender( $gender ) {
        $gender = strtolower( trim( (string) $gender ) );

        if ( 'monsieur' === $gender ) {
            return __( 'Homme', 'analytic-suite' );
        }

        if ( 'madame' === $gender ) {
            return __( 'Femme', 'analytic-suite' );
        }

        return '' !== $gender ? ucfirst( $gender ) : '';
    }

    /**
     * Counts customers who bought the same product more than once.
     *
     * @param array $customer_products Products grouped by customer.
     * @return int
     */
    private function count_repeat_product_customers( $customer_products ) {
        $count = 0;

        foreach ( $customer_products as $products ) {
            foreach ( $products as $quantity ) {
                if ( $quantity > 1 ) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
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
            'cancelled_orders'    => 0,
            'revenue'             => 0,
            'average_order_value' => 0,
            'unique_customers'    => 0,
            'recurring_customers' => 0,
            'repeat_product_customers' => 0,
            'retention_rate'      => 0,
            'country_sales'       => array(),
            'gender_breakdown'    => array(),
            'product_sales'       => array(),
            'status_breakdown'    => array(),
        );
    }
}
