<?php
/**
 * Plugin activation tasks.
 *
 * @package Analytic_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Activation handler.
 */
class Analytic_Suite_Activator {

    /**
     * Creates lightweight analytics tables and schedules sync.
     */
    public static function activate() {
        self::add_capabilities();
        self::create_tables();

        if ( ! wp_next_scheduled( 'analytic_suite_daily_sync' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'analytic_suite_daily_sync' );
        }

        add_option( 'analytic_suite_version', ANALYTIC_SUITE_VERSION );
    }

    /**
     * Grants plugin access to administrators and WooCommerce shop managers.
     */
    public static function add_capabilities() {
        $roles = array( 'administrator', 'shop_manager' );

        foreach ( $roles as $role_name ) {
            $role = get_role( $role_name );

            if ( $role ) {
                $role->add_cap( 'analytic_suite_view_analytics' );
                $role->add_cap( 'analytic_suite_manage_analytics' );
            }
        }
    }

    /**
     * Creates future-facing storage for precomputed snapshots.
     */
    private static function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $table_name      = $wpdb->prefix . 'analytic_suite_snapshots';

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            snapshot_key varchar(191) NOT NULL,
            snapshot_type varchar(80) NOT NULL,
            filters longtext NULL,
            payload longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY snapshot_key (snapshot_key),
            KEY snapshot_type (snapshot_type),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta( $sql );
    }
}
