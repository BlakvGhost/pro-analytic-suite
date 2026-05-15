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
     * Cached FluentBooking event labels.
     *
     * @var array
     */
    private $event_labels = array();

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

        $total             = 0;
        $cancelled         = 0;
        $customers         = array();
        $status_breakdown  = array();
        $type_breakdown    = array();
        $category_breakdown = array();
        $duration_breakdown = array();
        $country_breakdown = array();
        $gender_breakdown  = array();

        foreach ( $rows as $row ) {
            $status   = $this->value_from_columns( $row, array( 'status', 'booking_status', 'event_status' ) );
            $type     = $this->value_from_columns( $row, array( 'event_type', 'booking_type', 'calendar_event_id', 'event_id' ) );
            $duration = $this->value_from_columns( $row, array( 'slot_minutes', 'duration', 'duration_minutes', 'meeting_duration' ) );
            $email    = strtolower( (string) $this->value_from_columns( $row, array( 'email', 'invitee_email', 'customer_email' ) ) );
            $country  = $this->value_from_columns( $row, array( 'country', 'invitee_country', 'customer_country' ) );
            $source_id = absint( $this->value_from_columns( $row, array( 'source_id', 'order_id' ) ) );
            $booking_id = absint( $this->value_from_columns( $row, array( 'id', 'booking_id' ) ) );
            $gender    = '';
            $service_label = '';

            if ( ! $duration ) {
                $duration = $this->get_duration_from_row( $row );
            }

            if ( function_exists( 'wc_get_order' ) ) {
                $order = $source_id ? wc_get_order( $source_id ) : false;

                if ( ! $order && $booking_id ) {
                    $order = $this->get_order_by_booking_id( $booking_id );
                }

                if ( $order instanceof WC_Order ) {
                    if ( '' === $email ) {
                        $email = strtolower( (string) $order->get_billing_email() );
                    }

                    if ( '' === $country ) {
                        $country = $order->get_billing_country();
                    }

                    $gender = $this->normalize_gender( $order->get_meta( 'gender_' ) );
                    $service_label = $this->get_order_service_label( $order );
                }
            }

            if ( '' === $service_label ) {
                $service_label = $this->get_event_label( $type );
            }

            if ( '' === $service_label ) {
                $service_label = (string) $type;
            }

            $booking_category = $this->classify_booking_type( $service_label );

            if ( ! empty( $filters['booking_type'] ) && ! $this->text_matches( $service_label . ' ' . $booking_category, $filters['booking_type'] ) ) {
                continue;
            }

            if ( ! empty( $filters['exclude_booking_type'] ) && $this->text_matches( $service_label . ' ' . $booking_category, $filters['exclude_booking_type'] ) ) {
                continue;
            }

            if ( ! empty( $filters['gender'] ) && ! $this->gender_matches( $gender, $filters['gender'] ) ) {
                continue;
            }

            if ( ! empty( $filters['country'] ) && strtolower( (string) $country ) !== strtolower( (string) $filters['country'] ) ) {
                continue;
            }

            if ( ! empty( $filters['customer'] ) && is_email( $filters['customer'] ) && $email !== strtolower( sanitize_email( $filters['customer'] ) ) ) {
                continue;
            }

            $total++;

            if ( false !== strpos( strtolower( (string) $status ), 'cancel' ) ) {
                $cancelled++;
            }

            if ( '' !== $email ) {
                $customers[ $email ] = true;
            }

            $this->increment_breakdown( $status_breakdown, $status ? $status : __( 'Inconnu', 'analytic-suite' ) );
            $this->increment_breakdown( $type_breakdown, $service_label ? $service_label : __( 'Non classé', 'analytic-suite' ) );
            $this->increment_breakdown( $category_breakdown, $booking_category );

            if ( $duration ) {
                $this->increment_breakdown( $duration_breakdown, $duration . ' min' );
            }

            if ( $country ) {
                $this->increment_breakdown( $country_breakdown, $country );
            }

            if ( $gender ) {
                $this->increment_breakdown( $gender_breakdown, $gender );
            }
        }

        arsort( $status_breakdown );
        arsort( $type_breakdown );
        arsort( $category_breakdown );
        arsort( $duration_breakdown );
        arsort( $country_breakdown );
        arsort( $gender_breakdown );

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
            'category_breakdown' => $category_breakdown,
            'duration_breakdown' => array_slice( $duration_breakdown, 0, 10, true ),
            'duration_summary'   => $this->get_duration_summary( $duration_breakdown ),
            'country_breakdown'  => array_slice( $country_breakdown, 0, 10, true ),
            'gender_breakdown'   => $gender_breakdown,
        );
    }

    /**
     * Finds the most likely FluentBooking appointments table.
     *
     * @return string
     */
    private function find_booking_table() {
        global $wpdb;

        $known_tables = array(
            $wpdb->prefix . 'fcal_bookings',
            $wpdb->prefix . 'fluent_booking_appointments',
            $wpdb->prefix . 'fluentcalendar_bookings',
            $wpdb->prefix . 'fluent_bookings',
            $wpdb->prefix . 'fcal_appointments',
        );

        foreach ( $known_tables as $known_table ) {
            if ( $this->table_exists( $known_table ) ) {
                return $known_table;
            }
        }

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
     * Checks if a table exists.
     *
     * @param string $table Table name.
     * @return bool
     */
    private function table_exists( $table ) {
        global $wpdb;

        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

        return $found === $table;
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

        $date_column = $this->first_existing_column( $columns, array( 'start_at', 'start_time', 'start_datetime', 'start_date', 'start', 'booking_date', 'created_at' ) );
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

        $duration_column = $this->first_existing_column( $columns, array( 'slot_minutes', 'duration', 'duration_minutes', 'meeting_duration' ) );
        if ( $duration_column && ! empty( $filters['duration'] ) ) {
            $where[]  = '`' . esc_sql( $duration_column ) . '` = %d';
            $values[] = absint( $filters['duration'] );
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

        $source_column = $this->first_existing_column( $columns, array( 'source_id', 'order_id' ) );
        if ( $source_column && ! empty( $filters['customer'] ) && ! is_email( $filters['customer'] ) ) {
            $where[]  = '`' . esc_sql( $source_column ) . '` = %d';
            $values[] = absint( $filters['customer'] );
        }

        $order_column = $this->first_existing_column( $columns, array( 'id', 'created_at', 'start_at', 'start_time', 'booking_date' ) );
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
     * Gets product/service labels from the linked WooCommerce order.
     *
     * @param WC_Order $order Order.
     * @return string
     */
    private function get_order_service_label( WC_Order $order ) {
        $labels = array();

        foreach ( $order->get_items() as $item ) {
            $labels[] = $item->get_name();
        }

        return implode( ', ', array_filter( $labels ) );
    }

    /**
     * Finds a WooCommerce order through the __fcal_booking_id item meta used by FluentBooking.
     *
     * @param int $booking_id Booking ID.
     * @return WC_Order|false
     */
    private function get_order_by_booking_id( $booking_id ) {
        global $wpdb;

        $booking_id = absint( $booking_id );

        if ( ! $booking_id || ! function_exists( 'wc_get_order' ) ) {
            return false;
        }

        if ( ! $this->table_exists( $wpdb->prefix . 'woocommerce_order_items' ) || ! $this->table_exists( $wpdb->prefix . 'woocommerce_order_itemmeta' ) ) {
            return false;
        }

        $order_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT oi.order_id
                FROM {$wpdb->prefix}woocommerce_order_items oi
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
                WHERE oim.meta_key = %s
                AND oim.meta_value = %s
                ORDER BY oi.order_id DESC
                LIMIT 1",
                '__fcal_booking_id',
                (string) $booking_id
            )
        );

        return $order_id ? wc_get_order( absint( $order_id ) ) : false;
    }

    /**
     * Computes duration in minutes when FluentBooking stores start/end but no slot_minutes.
     *
     * @param array $row Booking row.
     * @return int
     */
    private function get_duration_from_row( $row ) {
        $start = $this->value_from_columns( $row, array( 'start_at', 'start_time', 'start_datetime', 'start_date', 'start' ) );
        $end   = $this->value_from_columns( $row, array( 'end_at', 'end_time', 'end_datetime', 'end_date', 'end' ) );

        if ( ! $start || ! $end ) {
            return 0;
        }

        $start_ts = $this->date_to_timestamp( $start );
        $end_ts   = $this->date_to_timestamp( $end );

        if ( ! $start_ts || ! $end_ts || $end_ts <= $start_ts ) {
            return 0;
        }

        return (int) round( ( $end_ts - $start_ts ) / MINUTE_IN_SECONDS );
    }

    /**
     * Converts common FluentBooking date values to timestamps.
     *
     * @param mixed $value Date value.
     * @return int
     */
    private function date_to_timestamp( $value ) {
        if ( is_numeric( $value ) ) {
            $timestamp = (int) $value;

            if ( $timestamp > 2000000000 ) {
                $timestamp = (int) floor( $timestamp / 1000 );
            }

            return $timestamp;
        }

        $value = trim( (string) $value );

        if ( preg_match( '/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $value ) ) {
            return (int) strtotime( $value . ' UTC' );
        }

        return (int) strtotime( $value );
    }

    /**
     * Tries to resolve a FluentBooking event identifier into a readable label.
     *
     * @param mixed $event_id Event identifier.
     * @return string
     */
    private function get_event_label( $event_id ) {
        global $wpdb;

        $event_id = absint( $event_id );

        if ( ! $event_id ) {
            return '';
        }

        if ( isset( $this->event_labels[ $event_id ] ) ) {
            return $this->event_labels[ $event_id ];
        }

        $tables = array(
            $wpdb->prefix . 'fcal_calendar_events',
            $wpdb->prefix . 'fcal_events',
        );

        foreach ( $tables as $table ) {
            if ( ! $this->table_exists( $table ) ) {
                continue;
            }

            $columns      = $this->get_columns( $table );
            $label_column = $this->first_existing_column( $columns, array( 'title', 'name', 'event_title', 'post_title' ) );

            if ( ! $label_column ) {
                continue;
            }

            $label = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT `' . esc_sql( $label_column ) . '` FROM `' . esc_sql( $table ) . '` WHERE id = %d LIMIT 1',
                    $event_id
                )
            );

            if ( $label ) {
                $this->event_labels[ $event_id ] = (string) $label;

                return $this->event_labels[ $event_id ];
            }
        }

        $this->event_labels[ $event_id ] = '';

        return '';
    }

    /**
     * Classifies booking labels into business categories.
     *
     * @param string $label Label.
     * @return string
     */
    private function classify_booking_type( $label ) {
        $normalized = $this->normalize_search_text( $label );

        if ( false !== strpos( $normalized, 'diagnostic' ) || false !== strpos( $normalized, 'strategique' ) ) {
            return __( 'Diagnostic stratégique', 'analytic-suite' );
        }

        if ( false !== strpos( $normalized, 'diner' ) || false !== strpos( $normalized, 'dinner' ) ) {
            return __( 'Dîner', 'analytic-suite' );
        }

        if ( false !== strpos( $normalized, 'session' ) ) {
            return __( 'Session', 'analytic-suite' );
        }

        return __( 'Autres', 'analytic-suite' );
    }

    /**
     * Builds the duration comparison requested in the dashboard.
     *
     * @param array $duration_breakdown Duration buckets.
     * @return array
     */
    private function get_duration_summary( $duration_breakdown ) {
        $thirty_minutes = isset( $duration_breakdown['30 min'] ) ? (int) $duration_breakdown['30 min'] : 0;
        $one_hour       = isset( $duration_breakdown['60 min'] ) ? (int) $duration_breakdown['60 min'] : 0;

        return array(
            '30 min' => $thirty_minutes,
            '1h'     => $one_hour,
            'leader' => $thirty_minutes > $one_hour ? __( '30 min', 'analytic-suite' ) : ( $one_hour > $thirty_minutes ? __( '1h', 'analytic-suite' ) : __( 'Égalité', 'analytic-suite' ) ),
        );
    }

    /**
     * Checks if a text contains a filter, accent-insensitively.
     *
     * @param string $text   Text.
     * @param string $filter Filter.
     * @return bool
     */
    private function text_matches( $text, $filter ) {
        return false !== strpos( $this->normalize_search_text( $text ), $this->normalize_search_text( $filter ) );
    }

    /**
     * Normalizes text for loose matching.
     *
     * @param string $text Text.
     * @return string
     */
    private function normalize_search_text( $text ) {
        $text = strtolower( remove_accents( (string) $text ) );

        return trim( $text );
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
     * Checks if a normalized gender matches a request filter.
     *
     * @param string $gender Normalized gender.
     * @param string $filter Raw filter.
     * @return bool
     */
    private function gender_matches( $gender, $filter ) {
        $gender = strtolower( trim( (string) $gender ) );
        $filter = strtolower( trim( (string) $filter ) );

        if ( 'monsieur' === $filter ) {
            $filter = strtolower( __( 'Homme', 'analytic-suite' ) );
        } elseif ( 'madame' === $filter ) {
            $filter = strtolower( __( 'Femme', 'analytic-suite' ) );
        }

        return $gender === $filter;
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
            'category_breakdown' => array(),
            'duration_breakdown' => array(),
            'duration_summary'   => array(
                '30 min' => 0,
                '1h'     => 0,
                'leader' => __( 'Égalité', 'analytic-suite' ),
            ),
            'country_breakdown'  => array(),
            'gender_breakdown'   => array(),
        );
    }
}
