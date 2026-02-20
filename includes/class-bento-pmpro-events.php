<?php
/**
 * PMPro event handlers for Bento integration.
 *
 * @package BentoPMProIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Bento_PMPro_Events
 *
 * Registers WordPress hooks for Paid Memberships Pro events and forwards them
 * to Bento via Bento_Events_Controller::trigger_event().
 */
class Bento_PMPro_Events {

	/**
	 * Temporary store for a user's old membership levels, keyed by user ID.
	 * Populated in pmpro_before_change_membership_level so that both the
	 * after-change and cancel hooks can report the previous level name.
	 *
	 * @var array<int, array>
	 */
	private static array $old_levels_cache = [];

	/**
	 * Register all PMPro action hooks.
	 */
	public static function init(): void {
		// Capture old levels before any change so we can reference them later.
		add_action( 'pmpro_before_change_membership_level', [ __CLASS__, 'capture_old_levels' ], 5, 4 );

		// Checkout – fires after a successful payment / free checkout.
		add_action( 'pmpro_after_checkout', [ __CLASS__, 'on_checkout' ], 10, 2 );

		// Membership level change (upgrade, downgrade, admin change).
		add_action( 'pmpro_after_change_membership_level', [ __CLASS__, 'on_level_changed' ], 10, 2 );

		// Frontend cancellation (member clicks Cancel).
		add_action( 'pmpro_cancel_processed', [ __CLASS__, 'on_cancelled' ], 10, 1 );

		// Recurring subscription payment succeeded.
		add_action( 'pmpro_subscription_payment_completed', [ __CLASS__, 'on_payment_completed' ], 10, 1 );

		// Recurring subscription payment failed.
		add_action( 'pmpro_subscription_payment_failed', [ __CLASS__, 'on_payment_failed' ], 10, 1 );

		// Membership expired (cron-driven).
		add_action( 'pmpro_membership_post_membership_expiry', [ __CLASS__, 'on_expired' ], 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the merged settings for a single event key, applying defaults.
	 *
	 * @param string $event_key One of the keys defined in Bento_Integration_Settings::get_event_definitions().
	 * @return array{enabled: bool, event_name: string, custom_fields: array}
	 */
	private static function get_event_config( string $event_key ): array {
		$all      = get_option( 'bento_pmpro_integration_settings', [] );
		$saved    = $all[ $event_key ] ?? [];
		$defaults = Bento_Integration_Settings::get_event_definitions();

		return [
			'enabled'       => ! empty( $saved['enabled'] ),
			'event_name'    => $saved['event_name'] ?? ( $defaults[ $event_key ]['default_event'] ?? $event_key ),
			'custom_fields' => $saved['custom_fields'] ?? [],
		];
	}

	/**
	 * Resolve the custom-field mappings defined in admin settings into an
	 * associative array suitable for Bento_Events_Controller::trigger_event().
	 *
	 * Three source types are supported:
	 *   - 'user_meta'  – looks up a WP user meta key for the given user.
	 *   - 'static'     – uses the literal source_value string.
	 *   - 'event_data' – reads a value from the event's own $details payload
	 *                    (e.g. 'new_level_name', 'order_total').
	 *
	 * @param array $mappings  Array of ['key', 'source_type', 'source_value'] rows.
	 * @param int   $user_id   The WordPress user ID.
	 * @param array $details   The event detail payload for 'event_data' lookups.
	 * @return array<string, mixed>
	 */
	private static function resolve_custom_fields( array $mappings, int $user_id, array $details = [] ): array {
		$resolved = [];
		foreach ( $mappings as $mapping ) {
			$key = sanitize_key( $mapping['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}

			// Evaluate optional condition before doing anything else.
			$condition_key   = $mapping['condition_key']   ?? '';
			$condition_value = $mapping['condition_value'] ?? '';
			if ( '' !== $condition_key ) {
				$actual = (string) ( $details[ $condition_key ] ?? '' );
				if ( $actual !== $condition_value ) {
					continue; // Condition not met — skip this mapping.
				}
			}

			$source_type  = $mapping['source_type'] ?? 'static';
			$source_value = $mapping['source_value'] ?? '';

			if ( 'user_meta' === $source_type ) {
				$resolved[ $key ] = get_user_meta( $user_id, sanitize_key( $source_value ), true );
			} elseif ( 'event_data' === $source_type ) {
				$resolved[ $key ] = $details[ $source_value ] ?? '';
			} else {
				$resolved[ $key ] = sanitize_text_field( $source_value );
			}
		}
		return $resolved;
	}

	/**
	 * Dispatch a Bento event if the event is enabled and Bento is available.
	 *
	 * @param string $event_key   Settings key for the event.
	 * @param int    $user_id     WordPress user ID.
	 * @param array  $details     Event-specific detail payload.
	 */
	private static function fire_event( string $event_key, int $user_id, array $details ): void {
		$config = self::get_event_config( $event_key );
		if ( ! $config['enabled'] ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}

		$custom_fields = self::resolve_custom_fields( $config['custom_fields'], $user_id, $details );

		Bento_Integration_Settings::queue_event(
			$user_id,
			$config['event_name'],
			$user->user_email,
			$details,
			$custom_fields
		);
	}

	// -------------------------------------------------------------------------
	// Hook: capture old levels before any change
	// -------------------------------------------------------------------------

	/**
	 * Store the user's current membership levels before a level change so that
	 * subsequent hooks can reference the previous state.
	 *
	 * @param int   $level_id    The incoming (new) level ID.
	 * @param int   $user_id     The user ID.
	 * @param array $old_levels  Array of current level objects (before change).
	 * @param mixed $cancel_level Unused.
	 */
	public static function capture_old_levels( int $level_id, int $user_id, array $old_levels, mixed $cancel_level ): void {
		self::$old_levels_cache[ $user_id ] = $old_levels;
	}

	// -------------------------------------------------------------------------
	// Hook handlers
	// -------------------------------------------------------------------------

	/**
	 * pmpro_after_checkout – fires after a successful checkout (new or renewal).
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param object $order   MemberOrder object.
	 */
	public static function on_checkout( int $user_id, object $order ): void {
		$level      = pmpro_getLevel( $order->membership_id ?? 0 );
		$level_name = $level->name ?? '';

		self::fire_event( 'pmpro_checkout', $user_id, [
			'level_id'     => $order->membership_id ?? 0,
			'level_name'   => $level_name,
			'order_total'  => $order->total ?? 0,
			'payment_type' => $order->payment_type ?? '',
		] );
	}

	/**
	 * pmpro_after_change_membership_level – fires after any level assignment.
	 * We only send the $PmproLevelChanged event when a real level (> 0) is set;
	 * cancellations (level_id == 0) are handled by on_cancelled().
	 *
	 * @param int $level_id The new level ID (0 means cancelled).
	 * @param int $user_id  The user ID.
	 */
	public static function on_level_changed( int $level_id, int $user_id ): void {
		if ( $level_id <= 0 ) {
			return; // Cancellation path – handled by on_cancelled().
		}

		$new_level      = pmpro_getLevel( $level_id );
		$new_level_name = $new_level->name ?? '';

		// Build a comma-separated list of the old level names (usually just one).
		$old_levels      = self::$old_levels_cache[ $user_id ] ?? [];
		$old_level_names = implode( ', ', array_map( fn( $l ) => $l->name ?? '', $old_levels ) );

		self::fire_event( 'pmpro_level_changed', $user_id, [
			'level_id'        => $level_id,
			'new_level_name'  => $new_level_name,
			'old_level_names' => $old_level_names,
		] );
	}

	/**
	 * pmpro_cancel_processed – fires when a member cancels from the frontend.
	 *
	 * @param \WP_User $user The user who cancelled.
	 */
	public static function on_cancelled( \WP_User $user ): void {
		$user_id = $user->ID;

		$old_levels      = self::$old_levels_cache[ $user_id ] ?? [];
		$old_level_names = implode( ', ', array_map( fn( $l ) => $l->name ?? '', $old_levels ) );

		self::fire_event( 'pmpro_cancelled', $user_id, [
			'last_level_names' => $old_level_names,
		] );
	}

	/**
	 * pmpro_subscription_payment_completed – fires when a recurring payment succeeds.
	 *
	 * @param object $order MemberOrder object.
	 */
	public static function on_payment_completed( object $order ): void {
		$user_id    = (int) ( $order->user_id ?? 0 );
		$level      = pmpro_getLevel( $order->membership_id ?? 0 );
		$level_name = $level->name ?? '';

		self::fire_event( 'pmpro_payment_completed', $user_id, [
			'order_total' => $order->total ?? 0,
			'level_name'  => $level_name,
		] );
	}

	/**
	 * pmpro_subscription_payment_failed – fires when a recurring payment fails.
	 *
	 * @param object $order MemberOrder object.
	 */
	public static function on_payment_failed( object $order ): void {
		$user_id    = (int) ( $order->user_id ?? 0 );
		$level      = pmpro_getLevel( $order->membership_id ?? 0 );
		$level_name = $level->name ?? '';

		self::fire_event( 'pmpro_payment_failed', $user_id, [
			'level_name' => $level_name,
		] );
	}

	/**
	 * pmpro_membership_post_membership_expiry – fires when a membership expires.
	 *
	 * @param int $user_id   The user ID.
	 * @param int $level_id  The expired level ID.
	 */
	public static function on_expired( int $user_id, int $level_id ): void {
		$level      = pmpro_getLevel( $level_id );
		$level_name = $level->name ?? '';

		self::fire_event( 'pmpro_expired', $user_id, [
			'level_id'   => $level_id,
			'level_name' => $level_name,
		] );
	}
}
