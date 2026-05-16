<?php
/**
 * Google Analytics 4 Data API integration.
 *
 * @package Analytic_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fetches analytics data from Google Analytics 4.
 */
class Analytic_Suite_Google_Analytics {

    /**
     * Property ID (GA4).
     *
     * @var string
     */
    private $property_id;

    /**
     * Service account credentials JSON.
     *
     * @var array
     */
    private $credentials;

    /**
     * Cache key prefix.
     *
     * @var string
     */
    private $cache_key = 'analytic_suite_ga_';

    /**
     * Last connection error.
     *
     * @var string
     */
    private $last_error = '';

    /**
     * Constructor.
     *
     * @param string $property_id GA4 property ID.
     * @param array  $credentials  Service account JSON credentials.
     */
    public function __construct( $property_id = '', $credentials = array() ) {
        $this->property_id = $property_id ?: get_option( 'analytic_suite_ga_property_id', '' );
        $this->credentials  = $credentials ?: json_decode( get_option( 'analytic_suite_ga_credentials', '' ), true );
    }

    /**
     * Checks if GA is configured.
     *
     * @return bool
     */
    public function is_configured() {
        return ! empty( $this->property_id ) && ! empty( $this->credentials );
    }

    /**
     * Gets current configuration and last error status.
     *
     * @return array
     */
    public function get_status() {
        return array(
            'configured'   => $this->is_configured(),
            'property_id'  => $this->property_id,
            'client_email' => $this->credentials['client_email'] ?? '',
            'last_error'   => $this->last_error,
        );
    }

    /**
     * Gets page views report.
     *
     * @param array $filters Filters.
     * @return array
     */
    public function get_page_views( $filters = array() ) {
        if ( ! $this->is_configured() ) {
            return $this->empty_response();
        }

        $cache_key = $this->cache_key . 'pages_' . md5( wp_json_encode( $filters ) );
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $dimensions = array( 'pagePath' );
        $metrics    = array( 'screenPageViews', 'sessions', 'averageSessionDuration', 'bounceRate' );

        $date_range = $this->build_date_range( $filters );
        $dimension_filter = $this->build_dimension_filter( $filters );

        $response = $this->run_report(
            array(
                'dimensions'   => $dimensions,
                'metrics'     => $metrics,
                'dateRanges'  => $date_range,
                'dimensionFilter' => $dimension_filter,
                'orderBys'    => array(
                    array( 'metric' => array( 'metricName' => 'screenPageViews' ), 'desc' => true ),
                ),
                'limit'       => 50,
            )
        );

        $result = $this->parse_page_views_response( $response );
        set_transient( $cache_key, $result, HOUR_IN_SECONDS );

        return $result;
    }

    /**
     * Gets user demographics.
     *
     * @param array $filters Filters.
     * @return array
     */
    public function get_demographics( $filters = array() ) {
        if ( ! $this->is_configured() ) {
            return $this->empty_response();
        }

        $cache_key = $this->cache_key . 'demographics_' . md5( wp_json_encode( $filters ) );
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $date_range = $this->build_date_range( $filters );

        $city_response = $this->run_report(
            array(
                'dimensions'  => array( 'city' ),
                'metrics'    => array( 'activeUsers' ),
                'dateRanges' => $date_range,
                'orderBys'   => array(
                    array( 'metric' => array( 'metricName' => 'activeUsers' ), 'desc' => true ),
                ),
                'limit'      => 20,
            )
        );

        $country_response = $this->run_report(
            array(
                'dimensions'  => array( 'country' ),
                'metrics'    => array( 'activeUsers' ),
                'dateRanges' => $date_range,
                'orderBys'   => array(
                    array( 'metric' => array( 'metricName' => 'activeUsers' ), 'desc' => true ),
                ),
                'limit'      => 10,
            )
        );

        $device_response = $this->run_report(
            array(
                'dimensions'  => array( 'deviceCategory' ),
                'metrics'    => array( 'activeUsers', 'sessions' ),
                'dateRanges' => $date_range,
            )
        );

        $result = array(
            'cities'    => $this->parse_dimension_response( $city_response, 'city', 'activeUsers' ),
            'countries' => $this->parse_dimension_response( $country_response, 'country', 'activeUsers' ),
            'devices'   => $this->parse_dimension_response( $device_response, 'deviceCategory', 'activeUsers' ),
        );

        set_transient( $cache_key, $result, HOUR_IN_SECONDS );

        return $result;
    }

    /**
     * Gets traffic sources.
     *
     * @param array $filters Filters.
     * @return array
     */
    public function get_traffic_sources( $filters = array() ) {
        if ( ! $this->is_configured() ) {
            return $this->empty_response();
        }

        $cache_key = $this->cache_key . 'sources_' . md5( wp_json_encode( $filters ) );
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $date_range = $this->build_date_range( $filters );

        $response = $this->run_report(
            array(
                'dimensions'  => array( 'sessionDefaultChannelGroup' ),
                'metrics'    => array( 'sessions', 'activeUsers', 'averageSessionDuration', 'bounceRate' ),
                'dateRanges' => $date_range,
                'orderBys'   => array(
                    array( 'metric' => array( 'metricName' => 'sessions' ), 'desc' => true ),
                ),
                'limit'      => 15,
            )
        );

        $result = $this->parse_dimension_response( $response, 'sessionDefaultChannelGroup', 'sessions' );
        set_transient( $cache_key, $result, HOUR_IN_SECONDS );

        return $result;
    }

    /**
     * Gets real-time users count.
     *
     * @return array
     */
    public function get_realtime_users() {
        if ( ! $this->is_configured() ) {
            return array( 'active_users' => 0 );
        }

        $response = $this->run_realtime_report(
            array(
                'metrics' => array( 'activeUsers' ),
            )
        );

        if ( empty( $response['rows'] ) ) {
            return array( 'active_users' => 0 );
        }

        return array(
            'active_users' => (int) $response['rows'][0]['metricValues'][0]['value'],
        );
    }

    /**
     * Gets overall summary.
     *
     * @param array $filters Filters.
     * @return array
     */
    public function get_summary( $filters = array() ) {
        if ( ! $this->is_configured() ) {
            return $this->empty_summary();
        }

        $date_range = $this->build_date_range( $filters );

        $response = $this->run_report(
            array(
                'metrics'    => array(
                    'activeUsers',
                    'sessions',
                    'screenPageViews',
                    'averageSessionDuration',
                    'bounceRate',
                    'newUsers',
                ),
                'dateRanges' => $date_range,
            )
        );

        if ( empty( $response['rows'] ) ) {
            return $this->empty_summary();
        }

        $metrics = $response['rows'][0]['metricValues'];

        return array(
            'active_users'            => (int) $metrics[0]['value'],
            'sessions'               => (int) $metrics[1]['value'],
            'page_views'             => (int) $metrics[2]['value'],
            'avg_duration'          => $this->format_duration( $metrics[3]['value'] ),
            'bounce_rate'           => round( $metrics[4]['value'], 2 ),
            'new_users'             => (int) $metrics[5]['value'],
        );
    }

    /**
     * Runs a report request to GA4 Data API.
     *
     * @param array $body Request body.
     * @return array
     */
    private function run_report( $body ) {
        $access_token = $this->get_access_token();

        if ( ! $access_token ) {
            if ( empty( $this->last_error ) ) {
                $this->last_error = __( 'Impossible de générer le token Google Analytics.', 'analytic-suite' );
            }
            return array();
        }

        $url = "https://analyticsdata.googleapis.com/v1beta/properties/{$this->property_id}:runReport";

        $response = wp_remote_post(
            $url,
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ),
                'body'   => wp_json_encode( $body ),
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->last_error = $response->get_error_message();
            return array();
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );

        if ( 200 !== $code ) {
            $this->last_error = $this->format_api_error( $raw_body, $code );
            return array();
        }

        $body = json_decode( $raw_body, true );

        return is_array( $body ) ? $body : array();
    }

    /**
     * Runs a real-time report request to GA4 Data API.
     *
     * @param array $body Request body.
     * @return array
     */
    private function run_realtime_report( $body ) {
        $access_token = $this->get_access_token();

        if ( ! $access_token ) {
            if ( empty( $this->last_error ) ) {
                $this->last_error = __( 'Impossible de générer le token Google Analytics.', 'analytic-suite' );
            }
            return array();
        }

        $url = "https://analyticsdata.googleapis.com/v1beta/properties/{$this->property_id}:runRealtimeReport";

        $response = wp_remote_post(
            $url,
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type'  => 'application/json',
                ),
                'body' => wp_json_encode( $body ),
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->last_error = $response->get_error_message();
            return array();
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );

        if ( 200 !== $code ) {
            $this->last_error = $this->format_api_error( $raw_body, $code );
            return array();
        }

        $body = json_decode( $raw_body, true );

        return is_array( $body ) ? $body : array();
    }

    /**
     * Gets access token using service account.
     *
     * @return string
     */
    private function get_access_token() {
        $cached = get_transient( $this->cache_key . 'token' );

        if ( $cached ) {
            return $cached;
        }

        if ( empty( $this->credentials['private_key'] ) || empty( $this->credentials['client_email'] ) ) {
            return '';
        }

        $jwt_header = $this->base64url_encode( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'RS256' ) ) );

        $now = time();
        $jwt_payload = $this->base64url_encode(
            wp_json_encode(
                array(
                    'iss'   => $this->credentials['client_email'],
                    'sub'   => $this->credentials['client_email'],
                    'aud'   => 'https://oauth2.googleapis.com/token',
                    'iat'   => $now,
                    'exp'   => $now + 3600,
                    'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
                )
            )
        );

        $signing_algorithm = OPENSSL_ALGO_SHA256;
        $private_key       = openssl_pkey_get_private( $this->credentials['private_key'] );

        if ( ! $private_key ) {
            $this->last_error = __( 'Clé privée Google Analytics invalide.', 'analytic-suite' );
            return '';
        }

        $signature = '';
        $signing_input = $jwt_header . '.' . $jwt_payload;

        if ( ! openssl_sign( $signing_input, $signature, $private_key, $signing_algorithm ) ) {
            $this->last_error = __( 'Signature du token Google Analytics impossible.', 'analytic-suite' );
            return '';
        }

        $jwt_signature = $this->base64url_encode( $signature );
        $jwt = $signing_input . '.' . $jwt_signature;

        $response = wp_remote_post(
            'https://oauth2.googleapis.com/token',
            array(
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ),
                'body' => array(
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'   => $jwt,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->last_error = $response->get_error_message();
            return '';
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw_body, true );

        if ( 200 !== $code || empty( $data['access_token'] ) ) {
            $this->last_error = $this->format_api_error( $raw_body, $code );
            return '';
        }

        set_transient( $this->cache_key . 'token', $data['access_token'], 3500 );

        return $data['access_token'];
    }

    /**
     * Encodes data using base64url for JWT segments.
     *
     * @param string $data Raw data.
     * @return string
     */
    private function base64url_encode( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    /**
     * Formats an API error response.
     *
     * @param string $body Response body.
     * @param int    $code Response code.
     * @return string
     */
    private function format_api_error( $body, $code ) {
        $data = json_decode( $body, true );

        if ( ! empty( $data['error']['message'] ) ) {
            return sprintf(
                /* translators: 1: HTTP code, 2: API error message. */
                __( 'Google Analytics a répondu %1$d: %2$s', 'analytic-suite' ),
                (int) $code,
                $data['error']['message']
            );
        }

        if ( ! empty( $data['error_description'] ) ) {
            return sprintf(
                /* translators: 1: HTTP code, 2: API error message. */
                __( 'Google OAuth a répondu %1$d: %2$s', 'analytic-suite' ),
                (int) $code,
                $data['error_description']
            );
        }

        return sprintf(
            /* translators: %d: HTTP code. */
            __( 'Google Analytics a répondu avec le code HTTP %d.', 'analytic-suite' ),
            (int) $code
        );
    }

    /**
     * Builds date range for API request.
     *
     * @param array $filters Filters.
     * @return array
     */
    private function build_date_range( $filters ) {
        $today = current_time( 'Y-m-d' );

        if ( ! empty( $filters['date_from'] ) && ! empty( $filters['date_to'] ) ) {
            return array(
                array( 'startDate' => $filters['date_from'], 'endDate' => $filters['date_to'] ),
            );
        }

        $days = 30;

        if ( '7-days' === $filters['period'] ) {
            $days = 7;
        } elseif ( 'year' === $filters['period'] ) {
            return array(
                array( 'startDate' => gmdate( 'Y-01-01' ), 'endDate' => $today ),
            );
        }

        return array(
            array(
                'startDate' => gmdate( 'Y-m-d', strtotime( $today . ' -' . ( $days - 1 ) . ' days' ) ),
                'endDate'   => $today,
            ),
        );
    }

    /**
     * Builds dimension filter.
     *
     * @param array $filters Filters.
     * @return array|null
     */
    private function build_dimension_filter( $filters ) {
        if ( ! empty( $filters['page_path'] ) ) {
            return array(
                'filter' => array(
                    'fieldName' => 'pagePath',
                    'stringFilter' => array(
                        'matchType' => 'CONTAINS',
                        'value'     => $filters['page_path'],
                    ),
                ),
            );
        }

        return null;
    }

    /**
     * Parses page views response.
     *
     * @param array $response API response.
     * @return array
     */
    private function parse_page_views_response( $response ) {
        if ( empty( $response['rows'] ) ) {
            return array();
        }

        $pages = array();

        foreach ( $response['rows'] as $row ) {
            $dims   = $row['dimensionValues'];
            $mets   = $row['metricValues'];

            $pages[] = array(
                'path'       => $dims[0]['value'],
                'views'      => (int) $mets[0]['value'],
                'sessions'   => (int) $mets[1]['value'],
                'avg_duration' => $this->format_duration( $mets[2]['value'] ),
                'bounce_rate' => round( $mets[3]['value'], 2 ),
            );
        }

        return $pages;
    }

    /**
     * Parses dimension response to key-value array.
     *
     * @param array  $response    API response.
     * @param string $dim_name    Dimension name.
     * @param string $metric_name Metric name.
     * @return array
     */
    private function parse_dimension_response( $response, $dim_name, $metric_name ) {
        if ( empty( $response['rows'] ) ) {
            return array();
        }

        $result = array();

        foreach ( $response['rows'] as $row ) {
            $dims = $row['dimensionValues'];
            $mets = $row['metricValues'];

            $dimension = $dims[0]['value'];
            $metric   = (int) $mets[0]['value'];

            if ( '(not set)' === $dimension || '(not provided)' === $dimension ) {
                continue;
            }

            $result[ $dimension ] = $metric;
        }

        return $result;
    }

    /**
     * Formats duration in seconds to human readable.
     *
     * @param float $seconds Seconds.
     * @return string
     */
    private function format_duration( $seconds ) {
        $minutes = floor( $seconds / 60 );
        $secs    = floor( $seconds % 60 );

        if ( $minutes > 0 ) {
            return $minutes . 'm ' . $secs . 's';
        }

        return $secs . 's';
    }

    /**
     * Returns empty response.
     *
     * @return array
     */
    private function empty_response() {
        return array();
    }

    /**
     * Returns empty summary.
     *
     * @return array
     */
    private function empty_summary() {
        return array(
            'active_users'     => 0,
            'sessions'         => 0,
            'page_views'       => 0,
            'avg_duration'     => '0s',
            'bounce_rate'      => 0,
            'new_users'        => 0,
        );
    }

    /**
     * Clears cache.
     */
    public function clear_cache() {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( '_transient_' . $this->cache_key ) . '%'
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( '_transient_timeout_' . $this->cache_key ) . '%'
            )
        );
    }

    /**
     * Tests the connection.
     *
     * @return array
     */
    public function test_connection() {
        if ( ! $this->is_configured() ) {
            return array(
                'success' => false,
                'message' => __( 'Google Analytics non configuré', 'analytic-suite' ),
            );
        }

        $summary = $this->get_summary();

        if ( ! empty( $this->last_error ) ) {
            return array(
                'success' => false,
                'message' => $this->last_error,
            );
        }

        return array(
            'success' => true,
            'message' => __( 'Connexion réussie', 'analytic-suite' ),
        );
    }
}
