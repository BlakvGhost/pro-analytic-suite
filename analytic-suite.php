<?php
/**
 * Plugin Name: Pro Analytics Suite
 * Plugin URI: https://github.com/BlakvGhost/pro-analytic-suite
 * Description: Suite analytics premium pour suivre les ventes WooCommerce, réservations FluentBooking, contenus, audiences GA4 et rapports publics.
 * Version: 0.1.2
 * Author: Kabirou ALASSANE
 * Author URI: https://github.com/BlakvGhost
 * Text Domain: analytic-suite
 * Domain Path: /languages
 *
 * @package Analytic_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ANALYTIC_SUITE_VERSION', '0.1.2' );
define( 'ANALYTIC_SUITE_FILE', __FILE__ );
define( 'ANALYTIC_SUITE_PATH', plugin_dir_path( __FILE__ ) );
define( 'ANALYTIC_SUITE_URL', plugin_dir_url( __FILE__ ) );

require_once ANALYTIC_SUITE_PATH . 'includes/class-analytic-suite-activator.php';
require_once ANALYTIC_SUITE_PATH . 'includes/class-analytic-suite-deactivator.php';
require_once ANALYTIC_SUITE_PATH . 'includes/class-analytic-suite.php';

register_activation_hook( __FILE__, array( 'Analytic_Suite_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Analytic_Suite_Deactivator', 'deactivate' ) );

/**
 * Starts the plugin.
 */
function analytic_suite_run() {
    $plugin = new Analytic_Suite();
    $plugin->run();
}

analytic_suite_run();
