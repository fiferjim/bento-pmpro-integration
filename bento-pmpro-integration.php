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

/**
 * Abort activation with a clear message if the Bento WordPress SDK is not active.
 * At activation time all other active plugins are already loaded, so the class
 * check is reliable.
 */
register_activation_hook( __FILE__, function (): void {
	if ( ! class_exists( 'Bento_Events_Controller' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			'<p><strong>Bento + PMPro Integration</strong> requires the '
			. '<strong>Bento WordPress SDK</strong> plugin to be installed and active. '
			. 'Please activate the Bento SDK first, then activate this plugin.</p>',
			'Plugin Activation Error',
			[ 'back_link' => true ]
		);
	}
} );
define( 'BENTO_PMPRO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BENTO_PMPRO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Cancel all pending Action Scheduler actions on deactivation so they don't
 * run in the background after the plugin has been switched off.
 */
register_deactivation_hook( __FILE__, function (): void {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'bento_pmpro_as_sync',  [], 'bento_pmpro_integration' );
		as_unschedule_all_actions( 'bento_pmpro_as_event', [], 'bento_pmpro_integration' );
	}
} );

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

	// Only register PMPro hooks when PMPro is active; avoids fatal errors if
	// PMPro is deactivated while this plugin remains active.
	if ( function_exists( 'pmpro_getLevel' ) ) {
		Bento_PMPro_Events::init();
	}

	// Only register Sensei hooks when Sensei LMS is active.
	if ( class_exists( 'Sensei_Main' ) ) {
		Bento_Sensei_Events::init();
	}
}
add_action( 'plugins_loaded', 'bento_pmpro_load', 20 );
