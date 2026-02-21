<?php
/**
 * Pest / PHPUnit bootstrap for Bento + PMPro Integration.
 *
 * Defines ABSPATH and plugin constants, stubs the WordPress functions used by
 * the plugin classes, then loads those classes so every test can work against
 * the real business logic without a live WordPress installation.
 */

// ── WordPress / plugin constants ──────────────────────────────────────────────
defined( 'ABSPATH' )               || define( 'ABSPATH',               dirname( __DIR__, 4 ) . '/' );
defined( 'BENTO_PMPRO_VERSION' )   || define( 'BENTO_PMPRO_VERSION',   '1.2.0' );
defined( 'BENTO_PMPRO_PLUGIN_DIR' )|| define( 'BENTO_PMPRO_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
defined( 'BENTO_PMPRO_PLUGIN_URL' )|| define( 'BENTO_PMPRO_PLUGIN_URL', 'http://example.com/' );

require_once __DIR__ . '/../vendor/autoload.php';

// ── Global test state ─────────────────────────────────────────────────────────
global $__wp_test_state;
$__wp_test_state = [
	'options'    => [],
	'user_meta'  => [],
	'users'      => [ 'id' => [] ],
	'as_actions' => [], // Action Scheduler scheduled single actions
	'actions'    => [], // add_action registrations
];

if ( ! function_exists( 'wp_test_reset_state' ) ) {
	function wp_test_reset_state(): void {
		global $__wp_test_state;
		$__wp_test_state['options']    = [];
		$__wp_test_state['user_meta']  = [];
		$__wp_test_state['users']      = [ 'id' => [] ];
		$__wp_test_state['as_actions'] = [];
		$__wp_test_state['actions']    = [];

		// Reset Bento_PMPro_Events private static properties so each test
		// starts with a clean slate.
		$r = new ReflectionClass( Bento_PMPro_Events::class );
		foreach ( [ 'pending_level_changes', 'checkout_user_ids', 'old_levels_cache' ] as $prop ) {
			$p = $r->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( null, [] );
		}
		$p = $r->getProperty( 'shutdown_registered' );
		$p->setAccessible( true );
		$p->setValue( null, false );
	}
}

// ── WordPress function stubs ───────────────────────────────────────────────────

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		global $__wp_test_state;
		return $__wp_test_state['options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value ) {
		global $__wp_test_state;
		$__wp_test_state['options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		global $__wp_test_state;
		unset( $__wp_test_state['options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key, $single = true ) {
		global $__wp_test_state;
		return $__wp_test_state['user_meta'][ $user_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		global $__wp_test_state;
		return $__wp_test_state['users']['id'][ $user_id ] ?? null;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return is_scalar( $str ) ? trim( strip_tags( (string) $str ) ) : '';
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) { return $text; }
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ) { return $text; }
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		global $__wp_test_state;
		$__wp_test_state['actions'][ $hook ][] = compact( 'callback', 'priority', 'accepted_args' );
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_action( $hook, $callback, $priority, $accepted_args );
	}
}

// ── Action Scheduler stubs ────────────────────────────────────────────────────

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	function as_schedule_single_action( $timestamp, $hook, $args = [], $group = '' ) {
		global $__wp_test_state;
		$__wp_test_state['as_actions'][] = compact( 'timestamp', 'hook', 'args', 'group' );
		return 1; // fake action ID
	}
}

if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	function as_unschedule_all_actions( $hook, $args = [], $group = '' ) {
		return true;
	}
}

// ── Load plugin class files ───────────────────────────────────────────────────
$_plugin_includes = dirname( __DIR__ ) . '/includes/';
require_once $_plugin_includes . 'class-bento-integration-settings.php';
require_once $_plugin_includes . 'class-bento-pmpro-events.php';
require_once $_plugin_includes . 'class-bento-sensei-events.php';
