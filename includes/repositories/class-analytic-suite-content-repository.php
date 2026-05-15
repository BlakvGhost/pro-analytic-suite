<?php
/**
 * Masterclass and gated content analytics.
 *
 * @package Analytic_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reads custom content tracking tables and post types.
 */
class Analytic_Suite_Content_Repository {

    /**
     * Gets content metrics.
     *
     * @param array $filters Filters.
     * @return array
     */
    public function get_metrics( $filters ) {
        return array(
            'available'              => $this->table_exists( 'user_masterclass' ) || $this->table_exists( 'user_livres' ) || post_type_exists( 'expert-session' ) || post_type_exists( 'livre' ),
            'masterclass_table'      => $this->table_exists( 'user_masterclass' ),
            'books_table'            => $this->table_exists( 'user_livres' ),
            'total_masterclasses'    => $this->count_posts( 'expert-session' ),
            'total_books'            => $this->count_posts( 'livre' ),
            'masterclass_users'      => $this->count_distinct_users( 'user_masterclass', $filters ),
            'book_users'             => $this->count_distinct_users( 'user_livres', $filters ),
            'masterclass_follows'    => $this->count_rows( 'user_masterclass', $filters ),
            'book_downloads'         => $this->count_rows( 'user_livres', $filters ),
            'top_masterclasses'      => $this->get_top_content( 'user_masterclass', 'expert-session', $filters ),
            'top_books'              => $this->get_top_content( 'user_livres', 'livre', $filters ),
            'masterclass_by_month'   => $this->get_monthly_breakdown( 'user_masterclass', $filters ),
            'books_by_month'         => $this->get_monthly_breakdown( 'user_livres', $filters ),
            'upcoming_masterclasses' => $this->count_upcoming_masterclasses(),
            'masterclass_replays'    => $this->count_masterclasses_with_replay(),
        );
    }

    /**
     * Checks if a custom table exists.
     *
     * @param string $table_slug Table slug without prefix.
     * @return bool
     */
    private function table_exists( $table_slug ) {
        global $wpdb;

        $table = $wpdb->prefix . $table_slug;
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

        return $found === $table;
    }

    /**
     * Counts posts for a post type.
     *
     * @param string $post_type Post type.
     * @return int
     */
    private function count_posts( $post_type ) {
        if ( ! post_type_exists( $post_type ) ) {
            return 0;
        }

        $counts = wp_count_posts( $post_type );
        $total  = 0;

        foreach ( (array) $counts as $status => $count ) {
            if ( 'auto-draft' === $status || 'trash' === $status ) {
                continue;
            }

            $total += (int) $count;
        }

        return $total;
    }

    /**
     * Counts custom table rows.
     *
     * @param string $table_slug Table slug without prefix.
     * @param array  $filters    Filters.
     * @return int
     */
    private function count_rows( $table_slug, $filters ) {
        global $wpdb;

        if ( ! $this->table_exists( $table_slug ) ) {
            return 0;
        }

        $table = $wpdb->prefix . $table_slug;
        $query = $this->build_date_where( $table, $filters );

        return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $table ) . '` WHERE ' . $query['where'] );
    }

    /**
     * Counts unique users in a custom tracking table.
     *
     * @param string $table_slug Table slug without prefix.
     * @param array  $filters    Filters.
     * @return int
     */
    private function count_distinct_users( $table_slug, $filters ) {
        global $wpdb;

        if ( ! $this->table_exists( $table_slug ) ) {
            return 0;
        }

        $table = $wpdb->prefix . $table_slug;
        $query = $this->build_date_where( $table, $filters );

        return (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT user_id) FROM `' . esc_sql( $table ) . '` WHERE ' . $query['where'] );
    }

    /**
     * Gets top content by tracking rows.
     *
     * @param string $table_slug Table slug without prefix.
     * @param string $post_type  Post type.
     * @param array  $filters    Filters.
     * @return array
     */
    private function get_top_content( $table_slug, $post_type, $filters ) {
        global $wpdb;

        if ( ! $this->table_exists( $table_slug ) ) {
            return array();
        }

        $table = $wpdb->prefix . $table_slug;

        if ( ! $this->table_has_column( $table, 'created_at' ) ) {
            return array();
        }

        $query = $this->build_date_where( $table, $filters );
        $rows  = $wpdb->get_results(
            'SELECT post_id, COUNT(*) AS total
            FROM `' . esc_sql( $table ) . '`
            WHERE ' . $query['where'] . '
            GROUP BY post_id
            ORDER BY total DESC
            LIMIT 10',
            ARRAY_A
        );

        $items = array();

        foreach ( $rows as $row ) {
            $post_id = absint( $row['post_id'] );
            $post    = get_post( $post_id );

            if ( ! $post || $post_type !== $post->post_type ) {
                continue;
            }

            $items[ get_the_title( $post_id ) ] = (int) $row['total'];
        }

        return $items;
    }

    /**
     * Gets monthly row counts.
     *
     * @param string $table_slug Table slug without prefix.
     * @param array  $filters    Filters.
     * @return array
     */
    private function get_monthly_breakdown( $table_slug, $filters ) {
        global $wpdb;

        if ( ! $this->table_exists( $table_slug ) ) {
            return array();
        }

        $table = $wpdb->prefix . $table_slug;
        $query = $this->build_date_where( $table, $filters );
        $rows  = $wpdb->get_results(
            'SELECT DATE_FORMAT(created_at, "%Y-%m") AS month_key, COUNT(*) AS total
            FROM `' . esc_sql( $table ) . '`
            WHERE ' . $query['where'] . '
            GROUP BY month_key
            ORDER BY month_key DESC
            LIMIT 12',
            ARRAY_A
        );

        $items = array();

        foreach ( $rows as $row ) {
            $items[ $row['month_key'] ] = (int) $row['total'];
        }

        return $items;
    }

    /**
     * Builds a date WHERE clause.
     *
     * @param string $table   Full table name.
     * @param array  $filters Filters.
     * @return array
     */
    private function build_date_where( $table, $filters ) {
        global $wpdb;

        $where  = array( '1=1' );
        $values = array();
        $cols   = $wpdb->get_col( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`' );

        if ( in_array( 'created_at', (array) $cols, true ) ) {
            if ( ! empty( $filters['date_from'] ) ) {
                $where[]  = 'created_at >= %s';
                $values[] = $filters['date_from'] . ' 00:00:00';
            }

            if ( ! empty( $filters['date_to'] ) ) {
                $where[]  = 'created_at <= %s';
                $values[] = $filters['date_to'] . ' 23:59:59';
            }
        }

        $sql = implode( ' AND ', $where );

        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare( $sql, $values );
        }

        return array(
            'where' => $sql,
        );
    }

    /**
     * Checks if a table has a column.
     *
     * @param string $table  Full table name.
     * @param string $column Column name.
     * @return bool
     */
    private function table_has_column( $table, $column ) {
        global $wpdb;

        $cols = $wpdb->get_col( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`' );

        return in_array( $column, (array) $cols, true );
    }

    /**
     * Counts upcoming masterclasses by ACF/meta date.
     *
     * @return int
     */
    private function count_upcoming_masterclasses() {
        if ( ! post_type_exists( 'expert-session' ) ) {
            return 0;
        }

        $query = new WP_Query(
            array(
                'post_type'      => 'expert-session',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => 'date',
                'meta_query'     => array(
                    array(
                        'key'     => 'date',
                        'value'   => current_time( 'Ymd' ),
                        'compare' => '>=',
                        'type'    => 'NUMERIC',
                    ),
                ),
            )
        );

        return (int) $query->found_posts;
    }

    /**
     * Counts masterclasses with replay links.
     *
     * @return int
     */
    private function count_masterclasses_with_replay() {
        if ( ! post_type_exists( 'expert-session' ) ) {
            return 0;
        }

        $query = new WP_Query(
            array(
                'post_type'      => 'expert-session',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'     => 'link_video_replay',
                        'value'   => '',
                        'compare' => '!=',
                    ),
                ),
            )
        );

        return (int) $query->found_posts;
    }
}
