<?php
/**
 * Unit tests for Bento_PMPro_Events business logic.
 *
 * Focuses on the duplicate-event prevention: flush_pending_level_changes()
 * must suppress the $PmproLevelChanged event for any user who already received
 * a $PmproMemberCheckout event in the same request.
 */

/**
 * Helper: use Reflection to set private static properties on Bento_PMPro_Events.
 */
function set_pmpro_static( string $property, mixed $value ): void {
	$p = ( new ReflectionClass( Bento_PMPro_Events::class ) )->getProperty( $property );
	$p->setAccessible( true );
	$p->setValue( null, $value );
}

/**
 * Enable the pmpro_level_changed event so fire_event() doesn't bail early.
 */
function enable_level_changed_event(): void {
	global $__wp_test_state;
	$__wp_test_state['options'][ Bento_Integration_Settings::OPTION_KEY ] = [
		'pmpro_level_changed' => [
			'enabled'    => '1',
			'event_name' => '$PmproLevelChanged',
		],
	];
}

// ── flush_pending_level_changes ───────────────────────────────────────────────

test( 'flush_pending_level_changes suppresses the event for a user who checked out in the same request', function () {
	global $__wp_test_state;
	enable_level_changed_event();

	// User 1 exists in WordPress.
	$__wp_test_state['users']['id'][1] = (object) [ 'ID' => 1, 'user_email' => 'user1@example.com' ];

	// Simulate: level changed for user 1, AND user 1 also checked out — the
	// level-changed event should be suppressed to avoid sending a duplicate.
	set_pmpro_static( 'pending_level_changes', [
		[ 'user_id' => 1, 'level_id' => 5, 'new_level_name' => 'Gold', 'old_level_names' => '' ],
	] );
	set_pmpro_static( 'checkout_user_ids', [ 1 => true ] );

	Bento_PMPro_Events::flush_pending_level_changes();

	expect( $__wp_test_state['as_actions'] )->toBeEmpty();
} );

test( 'flush_pending_level_changes queues an event for a user who did not check out', function () {
	global $__wp_test_state;
	enable_level_changed_event();

	$__wp_test_state['users']['id'][2] = (object) [ 'ID' => 2, 'user_email' => 'user2@example.com' ];

	set_pmpro_static( 'pending_level_changes', [
		[ 'user_id' => 2, 'level_id' => 5, 'new_level_name' => 'Gold', 'old_level_names' => '' ],
	] );
	set_pmpro_static( 'checkout_user_ids', [] ); // no checkouts this request

	Bento_PMPro_Events::flush_pending_level_changes();

	expect( $__wp_test_state['as_actions'] )->toHaveCount( 1 );

	$action = $__wp_test_state['as_actions'][0];
	expect( $action['hook'] )->toBe( 'bento_pmpro_as_event' );
	expect( $action['args'][0]['user_id'] )->toBe( 2 );
	expect( $action['args'][0]['event_name'] )->toBe( '$PmproLevelChanged' );
	expect( $action['args'][0]['email'] )->toBe( 'user2@example.com' );
} );

test( 'flush_pending_level_changes queues only the non-checkout user when both are pending', function () {
	global $__wp_test_state;
	enable_level_changed_event();

	$__wp_test_state['users']['id'][1] = (object) [ 'ID' => 1, 'user_email' => 'user1@example.com' ];
	$__wp_test_state['users']['id'][2] = (object) [ 'ID' => 2, 'user_email' => 'user2@example.com' ];

	set_pmpro_static( 'pending_level_changes', [
		[ 'user_id' => 1, 'level_id' => 5, 'new_level_name' => 'Gold',   'old_level_names' => '' ],
		[ 'user_id' => 2, 'level_id' => 6, 'new_level_name' => 'Silver', 'old_level_names' => '' ],
	] );
	set_pmpro_static( 'checkout_user_ids', [ 1 => true ] ); // only user 1 checked out

	Bento_PMPro_Events::flush_pending_level_changes();

	// Exactly one event, for user 2 only.
	expect( $__wp_test_state['as_actions'] )->toHaveCount( 1 );
	expect( $__wp_test_state['as_actions'][0]['args'][0]['user_id'] )->toBe( 2 );
} );
