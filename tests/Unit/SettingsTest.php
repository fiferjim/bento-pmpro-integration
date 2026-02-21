<?php
/**
 * Unit tests for Bento_Integration_Settings pure business logic.
 *
 * Covers: is_event_enabled, resolve_event_fields (all three source types plus
 * condition logic), sanitize_settings / sanitize_custom_fields, and
 * bento_sdk_available.
 */

// ── is_event_enabled ──────────────────────────────────────────────────────────

test( 'is_event_enabled returns false when option is absent', function () {
	expect( Bento_Integration_Settings::is_event_enabled( 'pmpro_checkout' ) )->toBeFalse();
} );

test( 'is_event_enabled returns true when the event flag is set', function () {
	global $__wp_test_state;
	$__wp_test_state['options'][ Bento_Integration_Settings::OPTION_KEY ] = [
		'pmpro_checkout' => [ 'enabled' => '1' ],
	];

	expect( Bento_Integration_Settings::is_event_enabled( 'pmpro_checkout' ) )->toBeTrue();
} );

test( 'is_event_enabled returns false when event is explicitly disabled', function () {
	global $__wp_test_state;
	$__wp_test_state['options'][ Bento_Integration_Settings::OPTION_KEY ] = [
		'pmpro_checkout' => [ 'enabled' => '' ],
	];

	expect( Bento_Integration_Settings::is_event_enabled( 'pmpro_checkout' ) )->toBeFalse();
} );

// ── resolve_event_fields — event name ─────────────────────────────────────────

test( 'resolve_event_fields uses the default event name when none is configured', function () {
	$result = Bento_Integration_Settings::resolve_event_fields( 'pmpro_checkout', 1, [] );

	expect( $result['event_name'] )->toBe( '$PmproMemberCheckout' );
} );

test( 'resolve_event_fields uses the configured event name', function () {
	global $__wp_test_state;
	$__wp_test_state['options'][ Bento_Integration_Settings::OPTION_KEY ] = [
		'pmpro_checkout' => [ 'event_name' => '$MyCheckout' ],
	];

	$result = Bento_Integration_Settings::resolve_event_fields( 'pmpro_checkout', 1, [] );

	expect( $result['event_name'] )->toBe( '$MyCheckout' );
} );

// ── resolve_event_fields — source types ───────────────────────────────────────

test( 'resolve_event_fields resolves a static source type', function () {
	global $__wp_test_state;
	$__wp_test_state['options'][ Bento_Integration_Settings::OPTION_KEY ] = [
		'pmpro_checkout' => [
			'custom_fields' => [ [
				'key'             => 'plan',
				'source_type'     => 'static',
				'source_value'    => 'Gold Plan',
				'condition_key'   => '',
				'condition_value' => '',
			] ],
		],
	];

	$result = Bento_Integration_Settings::resolve_event_fields( 'pmpro_checkout', 1, [] );

	expect( $result['custom_fields']['plan'] )->toBe( 'Gold Plan' );
} );

test( 'resolve_event_fields resolves a user_meta source type', function () {
	global $__wp_test_state;
	$__wp_test_state['user_meta'][42]['company'] = 'Acme Corp';
	$__wp_test_state['options'][ Bento_Integration_Settings::OPTION_KEY ] = [
		'pmpro_checkout' => [
			'custom_fields' => [ [
				'key'             => 'company',
				'source_type'     => 'user_meta',
				'source_value'    => 'company',
				'condition_key'   => '',
				'condition_value' => '',
			] ],
		],
	];

	$result = Bento_Integration_Settings::resolve_event_fields( 'pmpro_checkout', 42, [] );

	expect( $result['custom_fields']['company'] )->toBe( 'Acme Corp' );
} );

test( 'resolve_event_fields resolves an event_data source type', function () {
	global $__wp_test_state;
	$__wp_test_state['options'][ Bento_Integration_Settings::OPTION_KEY ] = [
		'pmpro_checkout' => [
			'custom_fields' => [ [
				'key'             => 'membership',
				'source_type'     => 'event_data',
				'source_value'    => 'level_name',
				'condition_key'   => '',
				'condition_value' => '',
			] ],
		],
	];

	$result = Bento_Integration_Settings::resolve_event_fields(
		'pmpro_checkout', 1, [ 'level_name' => 'Gold' ]
	);

	expect( $result['custom_fields']['membership'] )->toBe( 'Gold' );
} );

// ── resolve_event_fields — condition logic ────────────────────────────────────

test( 'resolve_event_fields skips a field when the condition does not match', function () {
	global $__wp_test_state;
	$__wp_test_state['options'][ Bento_Integration_Settings::OPTION_KEY ] = [
		'pmpro_checkout' => [
			'custom_fields' => [ [
				'key'             => 'tier',
				'source_type'     => 'static',
				'source_value'    => 'gold',
				'condition_key'   => 'level_name',
				'condition_value' => 'Gold',
			] ],
		],
	];

	// level_name is 'Silver', not 'Gold' → field must be excluded.
	$result = Bento_Integration_Settings::resolve_event_fields(
		'pmpro_checkout', 1, [ 'level_name' => 'Silver' ]
	);

	expect( $result['custom_fields'] )->not->toHaveKey( 'tier' );
} );

test( 'resolve_event_fields includes a field when the condition matches', function () {
	global $__wp_test_state;
	$__wp_test_state['options'][ Bento_Integration_Settings::OPTION_KEY ] = [
		'pmpro_checkout' => [
			'custom_fields' => [ [
				'key'             => 'tier',
				'source_type'     => 'static',
				'source_value'    => 'gold',
				'condition_key'   => 'level_name',
				'condition_value' => 'Gold',
			] ],
		],
	];

	$result = Bento_Integration_Settings::resolve_event_fields(
		'pmpro_checkout', 1, [ 'level_name' => 'Gold' ]
	);

	expect( $result['custom_fields']['tier'] )->toBe( 'gold' );
} );

// ── sanitize_settings ─────────────────────────────────────────────────────────

test( 'sanitize_settings returns an empty array for non-array input', function () {
	expect( Bento_Integration_Settings::sanitize_settings( 'not-an-array' ) )->toBe( [] );
	expect( Bento_Integration_Settings::sanitize_settings( null ) )->toBe( [] );
} );

test( 'sanitize_settings drops custom field rows whose key is empty', function () {
	$input = [
		'pmpro_checkout' => [
			'enabled'       => '1',
			'event_name'    => '$PmproMemberCheckout',
			'custom_fields' => [
				// Empty key — must be dropped.
				[ 'key' => '', 'source_type' => 'static', 'source_value' => 'x',    'condition_key' => '', 'condition_value' => '' ],
				// Valid key — must be kept.
				[ 'key' => 'plan', 'source_type' => 'static', 'source_value' => 'Gold', 'condition_key' => '', 'condition_value' => '' ],
			],
		],
	];

	$result = Bento_Integration_Settings::sanitize_settings( $input );

	expect( $result['pmpro_checkout']['custom_fields'] )->toHaveCount( 1 );
	expect( $result['pmpro_checkout']['custom_fields'][0]['key'] )->toBe( 'plan' );
} );

test( 'sanitize_settings normalises an unknown source_type to static', function () {
	$input = [
		'pmpro_checkout' => [
			'custom_fields' => [ [
				'key'         => 'foo',
				'source_type' => 'invalid_type',
				'source_value' => 'bar',
				'condition_key' => '',
				'condition_value' => '',
			] ],
		],
	];

	$result = Bento_Integration_Settings::sanitize_settings( $input );

	expect( $result['pmpro_checkout']['custom_fields'][0]['source_type'] )->toBe( 'static' );
} );

// ── bento_sdk_available ───────────────────────────────────────────────────────

test( 'bento_sdk_available returns false when the Bento SDK class is not loaded', function () {
	// In the test environment Bento_Events_Controller is intentionally absent.
	expect( Bento_Integration_Settings::bento_sdk_available() )->toBeFalse();
} );
