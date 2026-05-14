<?php
/**
 * Plugin deactivation tasks.
 *
 * @package Analytic_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deactivation handler.
 */
class Analytic_Suite_Deactivator {

    /**
     * Clears scheduled sync event.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( 'analytic_suite_daily_sync' );
    }
}
