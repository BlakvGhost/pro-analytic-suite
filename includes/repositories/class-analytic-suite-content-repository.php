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

    /**
     * Gets age and gender breakdown for users registered to free content.
     * Sources: `ages` and `genders` Profile Builder meta keys.
     *
     * @return array { age_breakdown: array, gender_breakdown: array }
     */
    public function get_registered_user_demographics() {
        global $wpdb;

        $user_ids = $this->get_content_user_ids();

        if ( empty( $user_ids ) ) {
            return array(
                'age_breakdown'    => array(),
                'gender_breakdown' => array(),
            );
        }

        $ids_in = implode( ',', $user_ids );

        // Age breakdown — Profile Builder stores birth date in 'ages' meta.
        $age_values = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->usermeta}
             WHERE meta_key = 'ages'
             AND user_id IN ({$ids_in})
             AND meta_value != ''"
        );

        $ranges = array(
            '18-24' => 0,
            '25-34' => 0,
            '35-44' => 0,
            '45-54' => 0,
            '55-64' => 0,
            '65+'   => 0,
        );

        $today = new DateTime( 'today' );

        foreach ( $age_values as $raw ) {
            $birth = $this->parse_birthdate( $raw );

            if ( ! $birth ) {
                continue;
            }

            $age = (int) $today->diff( $birth )->y;

            if ( $age >= 18 && $age <= 24 )      $ranges['18-24']++;
            elseif ( $age >= 25 && $age <= 34 )  $ranges['25-34']++;
            elseif ( $age >= 35 && $age <= 44 )  $ranges['35-44']++;
            elseif ( $age >= 45 && $age <= 54 )  $ranges['45-54']++;
            elseif ( $age >= 55 && $age <= 64 )  $ranges['55-64']++;
            elseif ( $age >= 65 )                $ranges['65+']++;
        }

        // Gender breakdown — Profile Builder stores gender in 'genders' meta.
        $gender_rows = $wpdb->get_results(
            "SELECT meta_value, COUNT(*) AS total
             FROM {$wpdb->usermeta}
             WHERE meta_key = 'genders'
             AND user_id IN ({$ids_in})
             AND meta_value != ''
             GROUP BY meta_value
             ORDER BY total DESC",
            ARRAY_A
        );

        $gender_breakdown = array();

        foreach ( $gender_rows as $row ) {
            $gender_breakdown[ ucfirst( (string) $row['meta_value'] ) ] = (int) $row['total'];
        }

        return array(
            'age_breakdown'    => array_filter( $ranges ),
            'gender_breakdown' => $gender_breakdown,
        );
    }

    /**
     * Returns distinct user IDs from both content tracking tables.
     *
     * @return int[]
     */
    private function get_content_user_ids() {
        global $wpdb;

        $parts = array();

        if ( $this->table_exists( 'user_masterclass' ) ) {
            $parts[] = "SELECT DISTINCT user_id FROM {$wpdb->prefix}user_masterclass";
        }

        if ( $this->table_exists( 'user_livres' ) ) {
            $parts[] = "SELECT DISTINCT user_id FROM {$wpdb->prefix}user_livres";
        }

        if ( empty( $parts ) ) {
            return array();
        }

        $rows = $wpdb->get_col( implode( ' UNION ', $parts ) );

        return array_map( 'intval', $rows );
    }

    /**
     * Parses a birth date string into a DateTime, trying common Profile Builder formats.
     *
     * @param string $value Raw meta value.
     * @return DateTime|null
     */
    private function parse_birthdate( $value ) {
        $value = trim( (string) $value );

        if ( '' === $value ) {
            return null;
        }

        foreach ( array( 'd/m/Y', 'Y-m-d', 'm/d/Y', 'd-m-Y', 'Y/m/d' ) as $format ) {
            $date   = DateTime::createFromFormat( $format, $value );
            $errors = DateTime::getLastErrors();

            if ( false !== $date && empty( $errors['warning_count'] ) && empty( $errors['error_count'] ) ) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Gets public user demographics.
     *
     * @return array
     */
    public function get_public_demographics() {
        $users = get_users( array( 'fields' => 'ids' ) );

        $total_users         = count( $users );
        $age_breakdown       = $this->get_age_breakdown();
        $sex_breakdown       = $this->get_sex_breakdown();
        $location_breakdown  = $this->get_location_breakdown();
        $disability_count    = $this->get_disability_count();
        $logged_in_users     = $this->get_logged_in_users_count();
        $completed_content   = $this->get_completed_content_users();

        return array(
            'total_users'        => $total_users,
            'age_breakdown'      => $age_breakdown,
            'sex_breakdown'      => $sex_breakdown,
            'location_breakdown' => $location_breakdown,
            'disability_count'   => $disability_count,
            'logged_in_users'    => $logged_in_users,
            'completed_content'  => $completed_content,
        );
    }

    /**
     * Gets age breakdown from user meta.
     *
     * @return array
     */
    private function get_age_breakdown() {
        global $wpdb;

        $ranges = array(
            '18-24' => array( 18, 24 ),
            '25-34' => array( 25, 34 ),
            '35-44' => array( 35, 44 ),
            '45-54' => array( 45, 54 ),
            '55-64' => array( 55, 64 ),
            '65+'   => array( 65, 200 ),
        );

        $breakdown = array();

        foreach ( $ranges as $label => $range ) {
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'age' AND meta_value >= %d AND meta_value <= %d",
                    $range[0],
                    $range[1]
                )
            );
            $breakdown[ $label ] = (int) $count;
        }

        return $breakdown;
    }

    /**
     * Gets sex breakdown from user meta.
     *
     * @return array
     */
    private function get_sex_breakdown() {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT meta_value, COUNT(*) as count 
            FROM {$wpdb->usermeta} 
            WHERE meta_key IN ('sexe', 'sex') 
            AND meta_value != '' 
            GROUP BY meta_value",
            ARRAY_A
        );

        $breakdown = array();
        foreach ( $results as $row ) {
            $label = ! empty( $row['meta_value'] ) ? ucfirst( $row['meta_value'] ) : __( 'Non défini', 'analytic-suite' );
            $breakdown[ $label ] = (int) $row['count'];
        }

        return $breakdown;
    }

    /**
     * Gets location breakdown (rural/urban) from user meta.
     *
     * @return array
     */
    private function get_location_breakdown() {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT meta_value, COUNT(*) as count 
            FROM {$wpdb->usermeta} 
            WHERE meta_key IN ('zone', 'localisation', 'location_type') 
            AND meta_value != '' 
            GROUP BY meta_value",
            ARRAY_A
        );

        $breakdown = array();
        $rural_labels    = array( 'rurale', 'rural', 'campagne' );
        $urban_labels    = array( 'urbaine', 'urban', 'ville' );

        foreach ( $results as $row ) {
            $value = strtolower( trim( $row['meta_value'] ) );

            if ( in_array( $value, $rural_labels, true ) ) {
                $breakdown[ __( 'Zone rurale', 'analytic-suite' ) ] = (int) $row['count'];
            } elseif ( in_array( $value, $urban_labels, true ) ) {
                $breakdown[ __( 'Zone urbaine', 'analytic-suite' ) ] = (int) $row['count'];
            } else {
                $breakdown[ ucfirst( $row['meta_value'] ) ] = (int) $row['count'];
            }
        }

        return $breakdown;
    }

    /**
     * Gets count of users with disability.
     *
     * @return int
     */
    private function get_disability_count() {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} 
            WHERE meta_key IN ('handicap', 'disability', 'situation_handicap') 
            AND meta_value IN ('oui', 'yes', '1', 'true')"
        );
    }

    /**
     * Gets count of users who logged in at least once.
     *
     * @return int
     */
    private function get_logged_in_users_count() {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'last_login' AND meta_value != ''"
        );
    }

    /**
     * Gets count of users who completed masterclass or free content.
     *
     * @return int
     */
    private function get_completed_content_users() {
        $masterclass_count = 0;
        $books_count       = 0;

        if ( $this->table_exists( 'user_masterclass' ) ) {
            global $wpdb;
            $masterclass_count = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}user_masterclass" );
        }

        // if ( $this->table_exists( 'user_livres' ) ) {
        //     global $wpdb;
        //     $books_count = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}user_livres" );
        // }

        return $masterclass_count + $books_count;
    }
}
