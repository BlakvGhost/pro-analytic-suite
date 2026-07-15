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
     * Reads all demographics from Elementor Pro form submissions.
     * Source forms : "Form Register Masterclass" and "Form Livre Blanc".
     * Uniqueness   : one record per email — MAX(submission id) wins.
     *
     * @return array {
     *   total_users: int,
     *   civility_breakdown: array,
     *   experience_breakdown: array,
     *   industry_breakdown: array,
     *   support_breakdown: array,
     *   location_breakdown: array,
     * }
     */
    public function get_registered_user_demographics( $date_from = '', $date_to = '' ) {
        if ( ! $this->elementor_tables_exist() ) {
            return array(
                'total_users'          => 0,
                'civility_breakdown'   => array(),
                'experience_breakdown' => array(),
                'industry_breakdown'   => array(),
                'support_breakdown'    => array(),
                'location_breakdown'   => array(),
            );
        }

        $forms = array( 'Form Register Masterclass', 'Form Livre Blanc' );

        return array(
            'total_users'          => $this->get_elementor_unique_users_count( $forms, $date_from, $date_to ),
            'civility_breakdown'   => $this->get_elementor_field_breakdown( 'civility', $forms, $date_from, $date_to ),
            'experience_breakdown' => $this->get_elementor_field_breakdown( 'field_experience', $forms, $date_from, $date_to ),
            'industry_breakdown'   => $this->get_elementor_field_breakdown( 'sector', $forms, $date_from, $date_to ),
            'support_breakdown'    => $this->get_elementor_field_breakdown( 'advice_type', $forms, $date_from, $date_to ),
            'location_breakdown'   => $this->get_elementor_field_breakdown( 'localisation', $forms, $date_from, $date_to ),
        );
    }

    /**
     * Returns the top N masterclasses by registration count from Elementor submissions.
     * Each form submission counts (not deduplicated — one user can attend multiple sessions).
     *
     * @param int $limit Number of results.
     * @return array label => count
     */
    public function get_top_masterclasses_from_elementor( $limit = 5, $date_from = '', $date_to = '' ) {
        if ( ! $this->elementor_tables_exist() ) {
            return array();
        }

        global $wpdb;
        $sub = $wpdb->prefix . 'e_submissions';
        $val = $wpdb->prefix . 'e_submissions_values';

        $date_col  = $this->get_submissions_date_column();
        $date_sql  = '';
        $date_args = array();
        if ( $date_col && ! empty( $date_from ) ) {
            $date_sql  .= " AND s.{$date_col} >= %s";
            $date_args[] = $date_from . ' 00:00:00';
        }
        if ( $date_col && ! empty( $date_to ) ) {
            $date_sql  .= " AND s.{$date_col} <= %s";
            $date_args[] = $date_to . ' 23:59:59';
        }

        $args = array_merge( array( 'Form Register Masterclass' ), $date_args, array( $limit ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT sv.value AS origin_slug, COUNT(*) AS total
                 FROM {$sub} s
                 INNER JOIN {$val} sv ON sv.submission_id = s.id AND sv.key = 'origin'
                 WHERE s.form_name = %s
                   AND sv.value != ''
                   {$date_sql}
                 GROUP BY sv.value
                 ORDER BY total DESC
                 LIMIT %d",
                ...$args
            ),
            ARRAY_A
        );

        $result = array();
        foreach ( $rows as $row ) {
            $post  = get_page_by_path( sanitize_text_field( $row['origin_slug'] ), OBJECT, 'expert-session' );
            $label = $post
                ? get_the_title( $post )
                : ucwords( str_replace( '-', ' ', $row['origin_slug'] ) );
            $result[ $label ] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * Returns the name of the date column in e_submissions (varies by Elementor version).
     * Falls back to null when the table is absent or has no usable date column.
     *
     * @return string|null  Column name, e.g. 'created_at', or null.
     */
    private function get_submissions_date_column() {
        static $cache = false;

        if ( $cache !== false ) {
            return $cache;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'e_submissions';

        $columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );

        foreach ( array( 'created_at', 'date_time', 'submitted_at', 'submission_date' ) as $candidate ) {
            if ( in_array( $candidate, $columns, true ) ) {
                $cache = $candidate;
                return $cache;
            }
        }

        $cache = null;
        return null;
    }

    /**
     * Checks that both Elementor Pro submission tables exist.
     *
     * @return bool
     */
    private function elementor_tables_exist() {
        global $wpdb;
        $sub = $wpdb->prefix . 'e_submissions';
        $val = $wpdb->prefix . 'e_submissions_values';

        return $wpdb->get_var( "SHOW TABLES LIKE '{$sub}'" ) === $sub
            && $wpdb->get_var( "SHOW TABLES LIKE '{$val}'" ) === $val;
    }

    /**
     * Counts distinct emails across the given Elementor form names.
     *
     * @param array $form_names Form names to include.
     * @return int
     */
    private function get_elementor_unique_users_count( array $form_names, $date_from = '', $date_to = '' ) {
        return count( $this->get_elementor_unique_emails( $form_names, $date_from, $date_to ) );
    }

    /**
     * Returns distinct emails across the given Elementor form names.
     * Used both to count free-content registrations and to build the
     * "active apprenants" union with paying customers.
     *
     * @param array $form_names Form names to include.
     * @return array Lowercase emails.
     */
    private function get_elementor_unique_emails( array $form_names, $date_from = '', $date_to = '' ) {
        if ( ! $this->elementor_tables_exist() ) {
            return array();
        }

        global $wpdb;
        $sub      = $wpdb->prefix . 'e_submissions';
        $val      = $wpdb->prefix . 'e_submissions_values';
        $ph       = implode( ',', array_fill( 0, count( $form_names ), '%s' ) );
        $date_col = $this->get_submissions_date_column();

        $date_sql  = '';
        $date_args = array();
        if ( $date_col && ! empty( $date_from ) ) {
            $date_sql  .= " AND s.{$date_col} >= %s";
            $date_args[] = $date_from . ' 00:00:00';
        }
        if ( $date_col && ! empty( $date_to ) ) {
            $date_sql  .= " AND s.{$date_col} <= %s";
            $date_args[] = $date_to . ' 23:59:59';
        }

        $args = array_merge( $form_names, $date_args );

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT LOWER(sv.value)
                 FROM {$sub} s
                 INNER JOIN {$val} sv ON sv.submission_id = s.id AND sv.key = 'your_email'
                 WHERE s.form_name IN ({$ph})
                   AND sv.value != ''
                   {$date_sql}",
                ...$args
            )
        );

        return array_values( array_filter( (array) $rows ) );
    }

    /**
     * Returns a value→count map for a specific Elementor field.
     * Deduplication: for each unique email, only the latest submission (MAX id) is counted.
     *
     * @param string $field_key Elementor field key (e.g. 'sector', 'civility').
     * @param array  $form_names Form names to include.
     * @return array label => count
     */
    private function get_elementor_field_breakdown( $field_key, array $form_names, $date_from = '', $date_to = '' ) {
        global $wpdb;
        $sub      = $wpdb->prefix . 'e_submissions';
        $val      = $wpdb->prefix . 'e_submissions_values';
        $ph       = implode( ',', array_fill( 0, count( $form_names ), '%s' ) );
        $date_col = $this->get_submissions_date_column();

        $date_sql  = '';
        $date_args = array();
        if ( $date_col && ! empty( $date_from ) ) {
            $date_sql  .= " AND s.{$date_col} >= %s";
            $date_args[] = $date_from . ' 00:00:00';
        }
        if ( $date_col && ! empty( $date_to ) ) {
            $date_sql  .= " AND s.{$date_col} <= %s";
            $date_args[] = $date_to . ' 23:59:59';
        }

        $args = array_merge( $form_names, $date_args, array( $field_key ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT sv.value, COUNT(*) AS total
                 FROM {$val} sv
                 WHERE sv.submission_id IN (
                     SELECT MAX(s.id)
                     FROM {$sub} s
                     INNER JOIN {$val} sv_e ON sv_e.submission_id = s.id AND sv_e.key = 'your_email'
                     WHERE s.form_name IN ({$ph})
                       AND sv_e.value != ''
                       {$date_sql}
                     GROUP BY sv_e.value
                 )
                   AND sv.key = %s
                   AND sv.value != ''
                 GROUP BY sv.value
                 ORDER BY total DESC",
                ...$args
            ),
            ARRAY_A
        );

        $breakdown = array();
        foreach ( $rows as $row ) {
            $breakdown[ ucfirst( (string) $row['value'] ) ] = (int) $row['total'];
        }

        return $breakdown;
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
     * Gets public user demographics.
     *
     * @return array
     */
    public function get_public_demographics( $date_from = '', $date_to = '' ) {
        $users = get_users( array( 'fields' => 'ids' ) );

        $total_users         = count( $users );
        $age_breakdown       = $this->get_age_breakdown();
        $sex_breakdown       = $this->get_sex_breakdown();
        $location_breakdown  = $this->get_location_breakdown();
        $disability_count    = $this->get_disability_count();
        $logged_in_users     = $this->get_logged_in_users_count();
        $completed_content   = $this->get_completed_content_users( $date_from, $date_to );

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
     * Counts unique customers with at least one completed WooCommerce order
     * (any product), regardless of whether they also registered for free content.
     * Supports both HPOS and legacy storage.
     *
     * @param string $date_from Order start date (Y-m-d).
     * @param string $date_to   Order end date (Y-m-d).
     * @return int
     */
    public function get_paying_apprenants_count( $date_from = '', $date_to = '' ) {
        return count( $this->get_paying_customer_emails( $date_from, $date_to ) );
    }

    /**
     * Counts unique apprenants active in the period: either paid (completed
     * WooCommerce order) or followed a content (masterclass/book tracking tables).
     *
     * @param string $date_from Start date (Y-m-d).
     * @param string $date_to   End date (Y-m-d).
     * @return int
     */
    public function get_active_apprenants_count( $date_from = '', $date_to = '' ) {
        $paying_emails = $this->get_paying_customer_emails( $date_from, $date_to );

        $content_emails = array();
        foreach ( $this->get_active_content_user_ids( $date_from, $date_to ) as $user_id ) {
            $user = get_userdata( $user_id );
            if ( $user && ! empty( $user->user_email ) ) {
                $content_emails[] = strtolower( $user->user_email );
            }
        }

        return count( array_unique( array_merge( $paying_emails, $content_emails ) ) );
    }

    /**
     * Returns distinct WP user IDs who followed a content (masterclass or book)
     * within the given date range, across both tracking tables.
     *
     * @param string $date_from Start date (Y-m-d).
     * @param string $date_to   End date (Y-m-d).
     * @return int[]
     */
    private function get_active_content_user_ids( $date_from = '', $date_to = '' ) {
        global $wpdb;

        $filters = array(
            'date_from' => $date_from,
            'date_to'   => $date_to,
        );

        $ids = array();

        foreach ( array( 'user_masterclass', 'user_livres' ) as $table_slug ) {
            if ( ! $this->table_exists( $table_slug ) ) {
                continue;
            }

            $table = $wpdb->prefix . $table_slug;
            $query = $this->build_date_where( $table, $filters );
            $rows  = $wpdb->get_col( 'SELECT DISTINCT user_id FROM `' . esc_sql( $table ) . '` WHERE ' . $query['where'] );
            $ids   = array_merge( $ids, array_map( 'intval', $rows ) );
        }

        return array_values( array_unique( array_filter( $ids ) ) );
    }

    /**
     * Counts unique apprenants across every known source, deduplicated by email:
     * free-content Elementor forms and paying WooCommerce customers. A WordPress
     * account with neither a form submission nor a completed order is excluded —
     * creating an account alone does not make someone an "apprenant".
     *
     * @param string $date_from Start date (Y-m-d), applies to form/order sources only.
     * @param string $date_to   End date (Y-m-d), applies to form/order sources only.
     * @return int
     */
    public function get_total_unique_apprenants_count( $date_from = '', $date_to = '' ) {
        $free_emails   = $this->get_elementor_unique_emails( array( 'Form Register Masterclass', 'Form Livre Blanc' ), $date_from, $date_to );
        $paying_emails = $this->get_paying_customer_emails( $date_from, $date_to );

        return count( array_unique( array_merge( $free_emails, $paying_emails ) ) );
    }

    /**
     * Returns distinct billing emails from completed WooCommerce orders
     * (HPOS + legacy storage), optionally filtered by order date.
     *
     * @param string $date_from Order start date (Y-m-d).
     * @param string $date_to   Order end date (Y-m-d).
     * @return array Lowercase emails.
     */
    private function get_paying_customer_emails( $date_from = '', $date_to = '' ) {
        global $wpdb;

        $paying_emails = array();

        $hpos_table = $wpdb->prefix . 'wc_orders';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
            $sql  = "SELECT DISTINCT LOWER(billing_email) FROM `{$hpos_table}` WHERE status = 'wc-completed' AND billing_email != ''";
            $args = array();
            if ( ! empty( $date_from ) ) {
                $sql   .= ' AND date_created_gmt >= %s';
                $args[] = $date_from . ' 00:00:00';
            }
            if ( ! empty( $date_to ) ) {
                $sql   .= ' AND date_created_gmt <= %s';
                $args[] = $date_to . ' 23:59:59';
            }
            $rows = $args ? $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) ) : $wpdb->get_col( $sql );
            if ( $rows ) {
                $paying_emails = $rows;
            }
        }

        $legacy_sql  = "SELECT DISTINCT LOWER(pm.meta_value)
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_billing_email'
               AND pm.meta_value != ''
               AND p.post_type = 'shop_order'
               AND p.post_status = 'wc-completed'";
        $legacy_args = array();
        if ( ! empty( $date_from ) ) {
            $legacy_sql   .= ' AND p.post_date >= %s';
            $legacy_args[] = $date_from . ' 00:00:00';
        }
        if ( ! empty( $date_to ) ) {
            $legacy_sql   .= ' AND p.post_date <= %s';
            $legacy_args[] = $date_to . ' 23:59:59';
        }
        $legacy_rows = $legacy_args ? $wpdb->get_col( $wpdb->prepare( $legacy_sql, ...$legacy_args ) ) : $wpdb->get_col( $legacy_sql );
        if ( $legacy_rows ) {
            $paying_emails = array_unique( array_merge( $paying_emails, $legacy_rows ) );
        }

        return array_values( array_filter( $paying_emails ) );
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
     * Counts unique users who followed a content (masterclass or book) in the
     * period. Shares its user set with get_active_content_user_ids() so this
     * number lines up exactly with the content-consumption half of "Apprenants actifs".
     *
     * @return int
     */
    private function get_completed_content_users( $date_from = '', $date_to = '' ) {
        return count( $this->get_active_content_user_ids( $date_from, $date_to ) );
    }
}
