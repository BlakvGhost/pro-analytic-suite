<?php
/**
 * FluentBooking analytics.
 *
 * @package Analytic_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reads FluentBooking tables defensively.
 */
class Analytic_Suite_Booking_Repository {

    /**
     * Gets booking metrics.
     *
     * @param array $filters Filters.
     * @return array
     */
    public function get_metrics( $filters ) {
        global $wpdb;

        $table = $this->find_booking_table();

        if ( ! $table ) {
            return $this->empty_metrics();
        }

        $columns = $this->get_columns( $table );
        $rows    = $this->get_booking_rows( $table, $columns, $filters );

        $total             = count( $rows );
        $cancelled         = 0;
        $customers         = array();
        $status_breakdown  = array();
        $type_breakdown    = array();
        $duration_breakdown = array();
        $country_breakdown = array();

        foreach ( $rows as $row ) {
            $status   = $this->value_from_columns( $row, array( 'status', 'booking_status', 'event_status' ) );
            $type     = $this->value_from_columns( $row, array( 'event_type', 'booking_type', 'calendar_event_id', 'event_id' ) );
            $duration = $this->value_from_columns( $row, array( 'duration', 'duration_minutes', 'meeting_duration' ) );
            $email    = strtolower( (string) $this->value_from_columns( $row, array( 'email', 'invitee_email', 'customer_email' ) ) );
            $country  = $this->value_from_columns( $row, array( 'country', 'invitee_country', 'customer_country' ) );

            if ( false !== strpos( strtolower( (string) $status ), 'cancel' ) ) {
                $cancelled++;
            }

            if ( '' !== $email ) {
                $customers[ $email ] = true;
            }

            $this->increment_breakdown( $status_breakdown, $status ? $status : __( 'Inconnu', 'analytic-suite' ) );
            $this->increment_breakdown( $type_breakdown, $type ? $type : __( 'Non classé', 'analytic-suite' ) );

            if ( $duration ) {
                $this->increment_breakdown( $duration_breakdown, $duration . ' min' );
            }

            if ( $country ) {
                $this->increment_breakdown( $country_breakdown, $country );
            }
        }

        arsort( $status_breakdown );
        arsort( $type_breakdown );
        arsort( $duration_breakdown );
        arsort( $country_breakdown );

        return array(
            'available'          => true,
            'table'              => $table,
            'total_bookings'     => $total,
            'cancelled_bookings' => $cancelled,
            'confirmed_bookings' => max( 0, $total - $cancelled ),
            'cancellation_rate'  => $total > 0 ? round( ( $cancelled / $total ) * 100, 2 ) : 0,
            'unique_customers'   => count( $customers ),
            'status_breakdown'   => $status_breakdown,
            'type_breakdown'     => array_slice( $type_breakdown, 0, 10, true ),
            'duration_breakdown' => array_slice( $duration_breakdown, 0, 10, true ),
            'country_breakdown'  => array_slice( $country_breakdown, 0, 10, true ),
        );
    }

    /**
     * Finds the most likely FluentBooking appointments table.
     *
     * @return string
     */
    private function find_booking_table() {
        global $wpdb;

        $like   = $wpdb->esc_like( $wpdb->prefix . 'fluent' ) . '%booking%';
        $tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

        if ( empty( $tables ) ) {
            return '';
        }

        $preferred_fragments = array( 'bookings', 'booking_events', 'scheduled', 'appointments' );

        foreach ( $preferred_fragments as $fragment ) {
            foreach ( $tables as $table ) {
                if ( false !== strpos( strtolower( $table ), $fragment ) ) {
                    return $table;
                }
            }
        }

        return $tables[0];
    }

    /**
     * Gets table columns.
     *
     * @param string $table Table name.
     * @return array
     */
    private function get_columns( $table ) {
        global $wpdb;

        $columns = $wpdb->get_col( 'DESCRIBE `' . esc_sql( $table ) . '`', 0 );

        return is_array( $columns ) ? $columns : array();
    }

    /**
     * Reads filtered booking rows.
     *
     * @param string $table   Table name.
     * @param array  $columns Columns.
     * @param array  $filters Filters.
     * @return array
     */
    private function get_booking_rows( $table, $columns, $filters ) {
        global $wpdb;

        $where  = array( '1=1' );
        $values = array();

        $date_column = $this->first_existing_column( $columns, array( 'start_time', 'start_date', 'booking_date', 'created_at' ) );
        if ( $date_column && ! empty( $filters['date_from'] ) ) {
            $where[]  = '`' . esc_sql( $date_column ) . '` >= %s';
            $values[] = $filters['date_from'] . ' 00:00:00';
        }
        if ( $date_column && ! empty( $filters['date_to'] ) ) {
            $where[]  = '`' . esc_sql( $date_column ) . '` <= %s';
            $values[] = $filters['date_to'] . ' 23:59:59';
        }

        $status_column = $this->first_existing_column( $columns, array( 'status', 'booking_status', 'event_status' ) );
        if ( $status_column && ! empty( $filters['status'] ) ) {
            $where[]  = '`' . esc_sql( $status_column ) . '` = %s';
            $values[] = $filters['status'];
        }

        $duration_column = $this->first_existing_column( $columns, array( 'duration', 'duration_minutes', 'meeting_duration' ) );
        if ( $duration_column && ! empty( $filters['duration'] ) ) {
            $where[]  = '`' . esc_sql( $duration_column ) . '` = %d';
            $values[] = absint( $filters['duration'] );
        }

        $type_column = $this->first_existing_column( $columns, array( 'event_type', 'booking_type', 'calendar_event_id', 'event_id' ) );
        if ( $type_column && ! empty( $filters['booking_type'] ) ) {
            $where[]  = '`' . esc_sql( $type_column ) . '` = %s';
            $values[] = $filters['booking_type'];
        }

        $country_column = $this->first_existing_column( $columns, array( 'country', 'invitee_country', 'customer_country' ) );
        if ( $country_column && ! empty( $filters['country'] ) ) {
            $where[]  = '`' . esc_sql( $country_column ) . '` = %s';
            $values[] = $filters['country'];
        }

        $email_column = $this->first_existing_column( $columns, array( 'email', 'invitee_email', 'customer_email' ) );
        if ( $email_column && ! empty( $filters['customer'] ) && is_email( $filters['customer'] ) ) {
            $where[]  = '`' . esc_sql( $email_column ) . '` = %s';
            $values[] = sanitize_email( $filters['customer'] );
        }

        $order_column = $this->first_existing_column( $columns, array( 'id', 'created_at', 'start_time', 'booking_date' ) );
        $order_sql    = $order_column ? ' ORDER BY `' . esc_sql( $order_column ) . '` DESC' : '';
        $sql          = 'SELECT * FROM `' . esc_sql( $table ) . '` WHERE ' . implode( ' AND ', $where ) . $order_sql . ' LIMIT 2000';

        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare( $sql, $values );
        }

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Gets first available column from a list.
     *
     * @param array $columns    Existing columns.
     * @param array $candidates Candidate names.
     * @return string
     */
    private function first_existing_column( $columns, $candidates ) {
        foreach ( $candidates as $candidate ) {
            if ( in_array( $candidate, $columns, true ) ) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Reads first non-empty row value from possible columns.
     *
     * @param array $row        Row.
     * @param array $candidates Candidate names.
     * @return mixed
     */
    private function value_from_columns( $row, $candidates ) {
        foreach ( $candidates as $candidate ) {
            if ( isset( $row[ $candidate ] ) && '' !== $row[ $candidate ] ) {
                return $row[ $candidate ];
            }
        }

        return '';
    }

    /**
     * Increments a breakdown bucket.
     *
     * @param array  $breakdown Breakdown.
     * @param string $key       Bucket key.
     */
    private function increment_breakdown( &$breakdown, $key ) {
        $breakdown[ $key ] = isset( $breakdown[ $key ] ) ? $breakdown[ $key ] + 1 : 1;
    }

    /**
     * Empty response when FluentBooking is not available.
     *
     * @return array
     */
    private function empty_metrics() {
        return array(
            'available'          => false,
            'table'              => '',
            'total_bookings'     => 0,
            'cancelled_bookings' => 0,
            'confirmed_bookings' => 0,
            'cancellation_rate'  => 0,
            'unique_customers'   => 0,
            'status_breakdown'   => array(),
            'type_breakdown'     => array(),
            'duration_breakdown' => array(),
            'country_breakdown'  => array(),
        );
    }
}
