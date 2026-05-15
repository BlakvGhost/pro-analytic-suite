<?php
/**
 * Plugin Name: Pro Analytics Suite
 * Plugin URI: https://github.com/BlakvGhost/Pro-Analytics-Suite
 * Description: Tableau de bord analytique pour WooCommerce et FluentBooking.
 * Version: 0.1.1
 * Author: Kabirou ALASSANE
 * Text Domain: analytic-suite
 * Domain Path: /languages
 *
 * @package Analytic_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ANALYTIC_SUITE_VERSION', '0.1.1' );
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
