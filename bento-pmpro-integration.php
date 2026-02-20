<?php
/**
 * Plugin Name:       Bento + PMPro Integration
 * Description:       Sends Bento events on PMPro membership and Sensei LMS course activities.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Text Domain:       bento-pmpro
 */

defined( 'ABSPATH' ) || exit;

define( 'BENTO_PMPRO_VERSION', '1.2.0' );
define( 'BENTO_PMPRO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BENTO_PMPRO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load plugin classes after all plugins have loaded so Bento, PMPro and Sensei
 * are all available.
 */
function bento_pmpro_load() {
	load_plugin_textdomain( 'bento-pmpro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	require_once BENTO_PMPRO_PLUGIN_DIR . 'includes/class-bento-integration-settings.php';
	require_once BENTO_PMPRO_PLUGIN_DIR . 'includes/class-bento-pmpro-events.php';
	require_once BENTO_PMPRO_PLUGIN_DIR . 'includes/class-bento-sensei-events.php';

	Bento_Integration_Settings::init();
	Bento_PMPro_Events::init();
	Bento_Sensei_Events::init();
}
add_action( 'plugins_loaded', 'bento_pmpro_load', 20 );
