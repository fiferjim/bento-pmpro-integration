<?php
/**
 * Admin settings page for Bento + PMPro Integration.
 *
 * @package BentoPMProIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Bento_Integration_Settings
 *
 * Registers the Settings → Bento Membership Integration admin page, renders
 * the settings form, and handles saving via the WordPress Settings API.
 */
class Bento_Integration_Settings {

	/** WordPress option key used to store all settings. */
	const OPTION_KEY = 'bento_pmpro_integration_settings';

	/** Settings-API group name (ties register_setting to the form). */
	const OPTION_GROUP = 'bento_pmpro_integration';

	/** Transient key for caching the Bento fields list. */
	const FIELDS_TRANSIENT = 'bento_pmpro_fields_cache';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_settings_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'wp_ajax_bento_pmpro_fetch_fields',     [ __CLASS__, 'ajax_fetch_fields' ] );
		add_action( 'wp_ajax_bento_pmpro_condition_values', [ __CLASS__, 'ajax_condition_values' ] );
		add_action( 'wp_ajax_bento_pmpro_start_sync',       [ __CLASS__, 'ajax_start_sync' ] );
		add_action( 'wp_ajax_bento_pmpro_sync_status',      [ __CLASS__, 'ajax_sync_status' ] );
		// Action Scheduler hooks — run in background, not via AJAX.
		add_action( 'bento_pmpro_as_sync',  [ __CLASS__, 'run_scheduled_batch' ], 10, 1 );
		add_action( 'bento_pmpro_as_event', [ __CLASS__, 'run_queued_event' ],    10, 1 );
	}

	// -------------------------------------------------------------------------
	// Event definitions (shared with event handler classes)
	// -------------------------------------------------------------------------

	/**
	 * Canonical list of all events managed by this plugin.
	 *
	 * @return array<string, array{label: string, default_event: string, description: string}>
	 */
	public static function get_event_definitions(): array {
		return [
			// PMPro events
			'pmpro_checkout' => [
				'label'           => 'PMPro: Member Checkout',
				'default_event'   => '$PmproMemberCheckout',
				'description'     => 'Fires when a member completes checkout (new signup or renewal).',
				'event_data_keys' => [ 'level_id', 'level_name', 'order_total', 'payment_type' ],
			],
			'pmpro_level_changed' => [
				'label'           => 'PMPro: Level Changed',
				'default_event'   => '$PmproLevelChanged',
				'description'     => 'Fires when a member\'s active membership level changes.',
				'event_data_keys' => [ 'level_id', 'new_level_name', 'old_level_names' ],
			],
			'pmpro_cancelled' => [
				'label'           => 'PMPro: Membership Cancelled',
				'default_event'   => '$PmproCancelled',
				'description'     => 'Fires when a member cancels their membership from the frontend.',
				'event_data_keys' => [ 'last_level_names' ],
			],
			'pmpro_payment_completed' => [
				'label'           => 'PMPro: Recurring Payment Completed',
				'default_event'   => '$PmproPaymentCompleted',
				'description'     => 'Fires when a recurring subscription payment succeeds.',
				'event_data_keys' => [ 'order_total', 'level_name' ],
			],
			'pmpro_payment_failed' => [
				'label'           => 'PMPro: Recurring Payment Failed',
				'default_event'   => '$PmproPaymentFailed',
				'description'     => 'Fires when a recurring subscription payment fails.',
				'event_data_keys' => [ 'level_name' ],
			],
			'pmpro_expired' => [
				'label'           => 'PMPro: Membership Expired',
				'default_event'   => '$PmproMembershipExpired',
				'description'     => 'Fires when a membership expires.',
				'event_data_keys' => [ 'level_id', 'level_name' ],
			],
			// Sensei LMS events
			'sensei_course_enrolled' => [
				'label'           => 'Sensei: Course Enrolled',
				'default_event'   => '$SenseiCourseEnrolled',
				'description'     => 'Fires when a student is enrolled in a course.',
				'event_data_keys' => [ 'course_id', 'course_title' ],
			],
			'sensei_course_unenrolled' => [
				'label'           => 'Sensei: Course Unenrolled',
				'default_event'   => '$SenseiCourseUnenrolled',
				'description'     => 'Fires when a student is unenrolled from a course.',
				'event_data_keys' => [ 'course_id', 'course_title' ],
			],
			'sensei_course_started' => [
				'label'           => 'Sensei: Course Started',
				'default_event'   => '$SenseiCourseStarted',
				'description'     => 'Fires when a student starts working through a course.',
				'event_data_keys' => [ 'course_id', 'course_title' ],
			],
			'sensei_course_completed' => [
				'label'           => 'Sensei: Course Completed',
				'default_event'   => '$SenseiCourseCompleted',
				'description'     => 'Fires when a student completes all lessons in a course.',
				'event_data_keys' => [ 'course_id', 'course_title' ],
			],
			'sensei_lesson_completed' => [
				'label'           => 'Sensei: Lesson Completed',
				'default_event'   => '$SenseiLessonCompleted',
				'description'     => 'Fires when a student completes a lesson.',
				'event_data_keys' => [ 'lesson_id', 'lesson_title', 'course_id' ],
			],
			'sensei_quiz_submitted' => [
				'label'           => 'Sensei: Quiz Submitted',
				'default_event'   => '$SenseiQuizSubmitted',
				'description'     => 'Fires when a student submits a quiz.',
				'event_data_keys' => [ 'quiz_id', 'grade', 'pass', 'quiz_pass_percentage', 'quiz_grade_type' ],
			],
		];
	}

	// -------------------------------------------------------------------------
	// Admin page registration
	// -------------------------------------------------------------------------

	/**
	 * Add the settings page under the WordPress Settings menu.
	 */
	public static function add_settings_page(): void {
		add_options_page(
			'Bento Membership Integration',
			'Bento Membership',
			'manage_options',
			'bento-pmpro-integration',
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	/**
	 * Register the single option and its sanitize callback with the Settings API.
	 */
	public static function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_KEY,
			[
				'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
				'default'           => [],
			]
		);
	}

	/**
	 * Enqueue the small admin JS file only on our settings page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_bento-pmpro-integration' !== $hook_suffix ) {
			return;
		}
		wp_enqueue_script(
			'bento-pmpro-admin',
			BENTO_PMPRO_PLUGIN_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			BENTO_PMPRO_VERSION,
			true
		);
		wp_localize_script( 'bento-pmpro-admin', 'bentoPmpro', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'bento_pmpro_fetch_fields' ),
			'condNonce'  => wp_create_nonce( 'bento_pmpro_condition_values' ),
			'syncNonce'  => wp_create_nonce( 'bento_pmpro_sync_batch' ),
			'syncStatus' => get_option( 'bento_pmpro_sync_status', [] ),
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX: fetch Bento fields
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler: fetch custom fields from the Bento API and return them as
	 * a JSON array of key strings. Results are cached for one hour.
	 */
	public static function ajax_fetch_fields(): void {
		check_ajax_referer( 'bento_pmpro_fetch_fields' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$cached = get_transient( self::FIELDS_TRANSIENT );
		if ( false !== $cached ) {
			wp_send_json_success( $cached );
			return;
		}

		$bento   = get_option( 'bento_settings', [] );
		$site    = $bento['bento_site_key']        ?? '';
		$pub     = $bento['bento_publishable_key'] ?? '';
		$secret  = $bento['bento_secret_key']      ?? '';

		if ( empty( $site ) || empty( $pub ) || empty( $secret ) ) {
			wp_send_json_error( 'Bento API credentials are not configured. Visit the Bento settings page first.' );
			return;
		}

		$response = wp_remote_get(
			'https://app.bentonow.com/api/v1/fetch/fields?site_uuid=' . urlencode( $site ),
			[
				'headers' => [
					'Accept'        => 'application/json',
					'Authorization' => 'Basic ' . base64_encode( $pub . ':' . $secret ),
					'User-Agent'    => 'bento-wordpress-' . $site,
				],
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'Bento API request failed: ' . $response->get_error_message() );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			wp_send_json_error( 'Bento API returned HTTP ' . $code );
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$keys = [];

		if ( ! empty( $body['data'] ) && is_array( $body['data'] ) ) {
			foreach ( $body['data'] as $field ) {
				$key = $field['attributes']['key'] ?? '';
				if ( '' !== $key ) {
					$keys[] = $key;
				}
			}
		}

		sort( $keys );
		set_transient( self::FIELDS_TRANSIENT, $keys, HOUR_IN_SECONDS );
		wp_send_json_success( $keys );
	}

	// -------------------------------------------------------------------------
	// AJAX: fetch condition value suggestions from WordPress
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler: return possible values for each event-data condition key.
	 *
	 * Queries PMPro levels, Sensei courses/lessons, and quiz post types from
	 * the local database so the admin can pick from a real list rather than
	 * typing exact names by hand. Returns a map of condition_key → string[].
	 */
	public static function ajax_condition_values(): void {
		check_ajax_referer( 'bento_pmpro_condition_values' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
			return;
		}

		$data = [];

		// ---- PMPro membership levels ----------------------------------------
		$level_names = [];
		$level_ids   = [];

		if ( function_exists( 'pmpro_getAllLevels' ) ) {
			foreach ( pmpro_getAllLevels( false, true ) as $level ) {
				$level_names[] = $level->name;
				$level_ids[]   = (string) $level->id;
			}
		} else {
			// Fallback: query the table directly.
			global $wpdb;
			$rows = $wpdb->get_results(
				"SELECT id, name FROM {$wpdb->prefix}pmpro_membership_levels WHERE status = 'active' ORDER BY name"
			);
			foreach ( $rows as $row ) {
				$level_names[] = $row->name;
				$level_ids[]   = (string) $row->id;
			}
		}

		// All PMPro condition keys that represent a level name share the same list.
		foreach ( [ 'level_name', 'new_level_name', 'old_level_names', 'last_level_names' ] as $k ) {
			$data[ $k ] = $level_names;
		}
		$data['level_id'] = $level_ids;

		// ---- Sensei LMS courses ---------------------------------------------
		$courses = get_posts( [
			'post_type'      => 'course',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );
		$data['course_title'] = wp_list_pluck( $courses, 'post_title' );
		$data['course_id']    = array_map( 'strval', wp_list_pluck( $courses, 'ID' ) );

		// ---- Sensei LMS lessons ---------------------------------------------
		$lessons = get_posts( [
			'post_type'      => 'lesson',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );
		$data['lesson_title'] = wp_list_pluck( $lessons, 'post_title' );
		$data['lesson_id']    = array_map( 'strval', wp_list_pluck( $lessons, 'ID' ) );

		// ---- Sensei quizzes -------------------------------------------------
		$quizzes = get_posts( [
			'post_type'      => 'quiz',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );
		$data['quiz_id'] = array_map( 'strval', wp_list_pluck( $quizzes, 'ID' ) );

		// ---- Static / boolean options ---------------------------------------
		$data['pass']            = [ '1', '0' ];   // true / false cast to string
		$data['quiz_grade_type'] = [ 'auto', 'manual', 'pass_fail' ];

		wp_send_json_success( $data );
	}

	// -------------------------------------------------------------------------
	// Shared helper: resolve field mappings for any event key
	// -------------------------------------------------------------------------

	/**
	 * Resolve configured custom-field mappings for a given event key, applying
	 * any conditions against the supplied $details array.
	 *
	 * Used both by the event handler classes and the bulk-sync handler so the
	 * same logic runs whether the event fires in real time or via manual sync.
	 *
	 * @param string $event_key The settings key (e.g. 'pmpro_checkout').
	 * @param int    $user_id   WordPress user ID.
	 * @param array  $details   Event detail payload used for condition checks and
	 *                          'event_data' source lookups.
	 * @return array{event_name: string, custom_fields: array<string, mixed>}
	 */
	public static function resolve_event_fields( string $event_key, int $user_id, array $details ): array {
		$all   = get_option( self::OPTION_KEY, [] );
		$saved = $all[ $event_key ] ?? [];
		$defs  = self::get_event_definitions();

		$event_name    = $saved['event_name'] ?? ( $defs[ $event_key ]['default_event'] ?? $event_key );
		$mappings      = $saved['custom_fields'] ?? [];
		$custom_fields = [];

		foreach ( $mappings as $mapping ) {
			$key = sanitize_key( $mapping['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}

			// Condition check.
			$ck = $mapping['condition_key']   ?? '';
			$cv = $mapping['condition_value'] ?? '';
			if ( '' !== $ck && (string) ( $details[ $ck ] ?? '' ) !== $cv ) {
				continue;
			}

			$type  = $mapping['source_type']  ?? 'static';
			$value = $mapping['source_value'] ?? '';

			if ( 'user_meta' === $type ) {
				$custom_fields[ $key ] = get_user_meta( $user_id, sanitize_key( $value ), true );
			} elseif ( 'event_data' === $type ) {
				$custom_fields[ $key ] = $details[ $value ] ?? '';
			} else {
				$custom_fields[ $key ] = sanitize_text_field( $value );
			}
		}

		return compact( 'event_name', 'custom_fields' );
	}

	// -------------------------------------------------------------------------
	// AJAX: bulk sync existing users
	// -------------------------------------------------------------------------

	/** Option key used to track background sync progress. */
	const SYNC_STATUS_OPTION = 'bento_pmpro_sync_status';

	/** Action Scheduler group name. */
	const AS_GROUP = 'bento_pmpro_integration';

	/**
	 * AJAX handler: schedule the first Action Scheduler batch and initialise
	 * the status record. Returns immediately — actual work runs in background.
	 */
	public static function ajax_start_sync(): void {
		check_ajax_referer( 'bento_pmpro_sync_batch' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
			return;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			wp_send_json_error( 'Action Scheduler is not available. Make sure PMPro or Sensei LMS is active.' );
			return;
		}

		$type      = sanitize_key( $_POST['type']      ?? 'pmpro' );
		$filter_id = max( 0, (int) ( $_POST['filter_id'] ?? 0 ) );

		// Cancel any existing pending actions for this type to avoid duplicates.
		as_unschedule_all_actions( 'bento_pmpro_as_sync', [ 'type' => $type ], self::AS_GROUP );

		// Initialise status.
		$all                  = get_option( self::SYNC_STATUS_OPTION, [] );
		$all[ $type ]         = [
			'status'    => 'running',
			'total'     => 0,
			'offset'    => 0,
			'filter_id' => $filter_id,
			'message'   => 'Queued — waiting for background processing to start…',
		];
		update_option( self::SYNC_STATUS_OPTION, $all );

		// Schedule first batch.
		as_schedule_single_action(
			time(),
			'bento_pmpro_as_sync',
			[ 'type' => $type, 'offset' => 0, 'filter_id' => $filter_id ],
			self::AS_GROUP
		);

		wp_send_json_success( [ 'message' => 'Sync queued.' ] );
	}

	/**
	 * AJAX handler: return the current sync status for a given type.
	 * Called by the JS poller every few seconds.
	 */
	public static function ajax_sync_status(): void {
		check_ajax_referer( 'bento_pmpro_sync_batch' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
			return;
		}

		$type   = sanitize_key( $_POST['type'] ?? 'pmpro' );
		$all    = get_option( self::SYNC_STATUS_OPTION, [] );
		$status = $all[ $type ] ?? [ 'status' => 'idle', 'message' => '' ];

		wp_send_json_success( $status );
	}

	/**
	 * Action Scheduler callback: process one batch, update status, and
	 * schedule the next batch if there are more records remaining.
	 *
	 * @param array $args { type, offset, filter_id }
	 */
	public static function run_scheduled_batch( array $args ): void {
		if ( ! class_exists( 'Bento_Events_Controller' ) ) {
			return;
		}

		$type      = sanitize_key( $args['type']      ?? 'pmpro' );
		$offset    = max( 0, (int) ( $args['offset']    ?? 0 ) );
		$filter_id = max( 0, (int) ( $args['filter_id'] ?? 0 ) );

		$result = 'sensei' === $type
			? self::sync_sensei_batch( $offset, 25, $filter_id )
			: self::sync_pmpro_batch( $offset, 25, $filter_id );

		// Persist progress.
		$all          = get_option( self::SYNC_STATUS_OPTION, [] );
		$all[ $type ] = [
			'status'    => $result['done'] ? 'done' : 'running',
			'total'     => $result['total'],
			'offset'    => $result['offset'],
			'filter_id' => $filter_id,
			'message'   => $result['done']
				? '✓ Done — synced ' . $result['total'] . ' records.'
				: 'Synced ' . $result['offset'] . ' of ' . $result['total'] . '…',
		];
		update_option( self::SYNC_STATUS_OPTION, $all );

		// Schedule next batch if not finished.
		if ( ! $result['done'] && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time(),
				'bento_pmpro_as_sync',
				[ 'type' => $type, 'offset' => $result['offset'], 'filter_id' => $filter_id ],
				self::AS_GROUP
			);
		}
	}

	// -------------------------------------------------------------------------
	// Real-time event queuing via Action Scheduler
	// -------------------------------------------------------------------------

	/**
	 * Schedule a single Bento event to run in the background via Action Scheduler.
	 *
	 * All event data is resolved synchronously (while the original request context
	 * is still accurate) and passed as arguments so AS can fire the HTTP call later.
	 * Falls back to a direct call if Action Scheduler is unavailable.
	 *
	 * @param int    $user_id       WordPress user ID.
	 * @param string $event_name    Bento event name (e.g. '$PmproMemberCheckout').
	 * @param string $email         Subscriber email address.
	 * @param array  $details       Event detail payload.
	 * @param array  $custom_fields Resolved custom-field key/value pairs.
	 */
	public static function queue_event(
		int $user_id, string $event_name, string $email,
		array $details, array $custom_fields
	): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time(),
				'bento_pmpro_as_event',
				[ compact( 'user_id', 'event_name', 'email', 'details', 'custom_fields' ) ],
				self::AS_GROUP
			);
		} elseif ( class_exists( 'Bento_Events_Controller' ) ) {
			// Fallback: call directly if Action Scheduler is not available.
			Bento_Events_Controller::trigger_event( $user_id, $event_name, $email, $details, $custom_fields );
		}
	}

	/**
	 * Action Scheduler callback: fire a previously queued Bento event.
	 *
	 * @param array $args { user_id, event_name, email, details, custom_fields }
	 */
	public static function run_queued_event( array $args ): void {
		if ( ! class_exists( 'Bento_Events_Controller' ) ) {
			return;
		}
		Bento_Events_Controller::trigger_event(
			$args['user_id'],
			$args['event_name'],
			$args['email'],
			$args['details'],
			$args['custom_fields']
		);
	}

	/**
	 * Process one batch of active PMPro members.
	 * Uses the 'pmpro_checkout' event configuration for field mappings.
	 */
	private static function sync_pmpro_batch( int $offset, int $batch = 25, int $filter_level_id = 0 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'pmpro_memberships_users';

		// Check the table exists (i.e. PMPro is installed).
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return [ 'total' => 0, 'offset' => 0, 'done' => true, 'error' => 'PMPro is not installed.' ];
		}

		$filter_sql = $filter_level_id > 0
			? $wpdb->prepare( ' AND membership_id = %d', $filter_level_id )
			: '';

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE status = 'active'{$filter_sql}"
		);

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT user_id, membership_id
			 FROM {$table}
			 WHERE status = 'active'{$filter_sql}
			 ORDER BY user_id
			 LIMIT %d OFFSET %d",
			$batch, $offset
		) );

		foreach ( $rows as $row ) {
			$user_id = (int) $row->user_id;
			$user    = get_userdata( $user_id );
			if ( ! $user || empty( $user->user_email ) ) {
				continue;
			}

			$level = pmpro_getLevel( (int) $row->membership_id );
			if ( ! $level ) {
				continue;
			}

			$details  = [
				'level_id'     => (int) $level->id,
				'level_name'   => $level->name,
				'order_total'  => 0,
				'payment_type' => '',
			];
			$resolved = self::resolve_event_fields( 'pmpro_checkout', $user_id, $details );

			if ( ! empty( $resolved['event_name'] ) ) {
				Bento_Events_Controller::trigger_event(
					$user_id,
					$resolved['event_name'],
					$user->user_email,
					$details,
					$resolved['custom_fields']
				);
				// Brief pause between API calls to avoid hitting Bento rate limits.
				usleep( 250000 ); // 250ms
			}
		}

		$new_offset = $offset + count( $rows );

		return [
			'total'  => $total,
			'offset' => $new_offset,
			'done'   => $new_offset >= $total,
		];
	}

	/**
	 * Process one batch of active Sensei LMS course enrollments.
	 * Uses the 'sensei_course_enrolled' event configuration for field mappings.
	 */
	private static function sync_sensei_batch( int $offset, int $batch = 25, int $filter_course_id = 0 ): array {
		global $wpdb;

		$filter_sql = $filter_course_id > 0
			? $wpdb->prepare( ' AND comment_post_ID = %d', $filter_course_id )
			: '';

		// Sensei stores course activity in wp_comments.
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM (
			     SELECT DISTINCT user_ID, comment_post_ID
			     FROM {$wpdb->comments}
			     WHERE comment_type = 'sensei_course_status'
			     AND comment_approved IN ('in-progress', 'complete'){$filter_sql}
			 ) t"
		);

		if ( 0 === $total ) {
			return [ 'total' => 0, 'offset' => 0, 'done' => true ];
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT user_ID AS user_id, comment_post_ID AS course_id
			 FROM {$wpdb->comments}
			 WHERE comment_type = 'sensei_course_status'
			 AND comment_approved IN ('in-progress', 'complete'){$filter_sql}
			 ORDER BY user_ID, comment_post_ID
			 LIMIT %d OFFSET %d",
			$batch, $offset
		) );

		foreach ( $rows as $row ) {
			$user_id   = (int) $row->user_id;
			$course_id = (int) $row->course_id;
			$user      = get_userdata( $user_id );
			if ( ! $user || empty( $user->user_email ) ) {
				continue;
			}

			$details  = [
				'course_id'    => $course_id,
				'course_title' => get_the_title( $course_id ),
			];
			$resolved = self::resolve_event_fields( 'sensei_course_enrolled', $user_id, $details );

			if ( ! empty( $resolved['event_name'] ) ) {
				Bento_Events_Controller::trigger_event(
					$user_id,
					$resolved['event_name'],
					$user->user_email,
					$details,
					$resolved['custom_fields']
				);
				// Brief pause between API calls to avoid hitting Bento rate limits.
				usleep( 250000 ); // 250ms
			}
		}

		$new_offset = $offset + count( $rows );

		return [
			'total'  => $total,
			'offset' => $new_offset,
			'done'   => $new_offset >= $total,
		];
	}

	// -------------------------------------------------------------------------
	// Settings sanitization
	// -------------------------------------------------------------------------

	/**
	 * Sanitize the entire settings array before it is saved to the database.
	 *
	 * @param mixed $raw Raw POST data from the settings form.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$clean      = [];
		$event_defs = self::get_event_definitions();

		foreach ( $event_defs as $event_key => $def ) {
			$event_raw = $raw[ $event_key ] ?? [];

			$clean[ $event_key ] = [
				'enabled'       => ! empty( $event_raw['enabled'] ),
				'event_name'    => sanitize_text_field( $event_raw['event_name'] ?? $def['default_event'] ),
				'custom_fields' => self::sanitize_custom_fields( $event_raw['custom_fields'] ?? [] ),
			];
		}

		return $clean;
	}

	/**
	 * Sanitize an array of custom-field mapping rows.
	 *
	 * @param mixed $rows Raw rows from POST.
	 * @return array Cleaned rows (empty-key rows are dropped).
	 */
	private static function sanitize_custom_fields( mixed $rows ): array {
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$clean = [];
		foreach ( $rows as $row ) {
			$key = sanitize_key( $row['key'] ?? '' );
			if ( '' === $key ) {
				continue; // Skip empty rows.
			}
			$source_type = in_array( $row['source_type'] ?? '', [ 'user_meta', 'static', 'event_data' ], true )
				? $row['source_type']
				: 'static';

			$clean[] = [
				'key'             => $key,
				'source_type'     => $source_type,
				'source_value'    => sanitize_text_field( $row['source_value'] ?? '' ),
				'condition_key'   => sanitize_key( $row['condition_key'] ?? '' ),
				'condition_value' => sanitize_text_field( $row['condition_value'] ?? '' ),
			];
		}
		return $clean;
	}

	// -------------------------------------------------------------------------
	// Settings page rendering
	// -------------------------------------------------------------------------

	/**
	 * Render the full settings page HTML.
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$saved      = get_option( self::OPTION_KEY, [] );
		$event_defs = self::get_event_definitions();
		?>
		<?php // Shared datalist for all "Bento Field Name" inputs – populated via JS. ?>
		<datalist id="bento-pmpro-fields-datalist"></datalist>

		<?php
		// One datalist per unique condition key, populated by JS from the DB.
		$all_condition_keys = [];
		foreach ( $event_defs as $def ) {
			foreach ( $def['event_data_keys'] as $k ) {
				$all_condition_keys[ $k ] = true;
			}
		}
		foreach ( array_keys( $all_condition_keys ) as $ck ) :
		?>
		<datalist id="bento-condvals-<?php echo esc_attr( $ck ); ?>"></datalist>
		<?php endforeach; ?>

		<div class="wrap">
			<h1>Bento Membership Integration</h1>
			<p>Configure which membership and course events are sent to Bento, and map custom fields to each event.</p>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<?php foreach ( $event_defs as $event_key => $def ) :
					$event_saved      = $saved[ $event_key ] ?? [];
					$enabled          = ! empty( $event_saved['enabled'] );
					$event_name       = $event_saved['event_name'] ?? $def['default_event'];
					$custom_fields    = $event_saved['custom_fields'] ?? [];
					$field_base       = self::OPTION_KEY . '[' . $event_key . ']';
					$table_id         = 'bento-fields-' . esc_attr( $event_key );
					$event_keys_id    = 'bento-event-keys-' . esc_attr( $event_key );
					$event_data_keys  = $def['event_data_keys'] ?? [];
				?>
				<?php // Per-event datalist for "Event Data" source-type suggestions. ?>
				<datalist id="<?php echo esc_attr( $event_keys_id ); ?>">
					<?php foreach ( $event_data_keys as $dk ) : ?>
					<option value="<?php echo esc_attr( $dk ); ?>">
					<?php endforeach; ?>
				</datalist>

				<div class="bento-event-section" data-event-key="<?php echo esc_attr( $event_key ); ?>" style="background:#fff;border:1px solid #c3c4c7;margin-bottom:16px;padding:16px 20px;border-radius:4px;">
					<h2 style="margin-top:0;margin-bottom:6px;"><?php echo esc_html( $def['label'] ); ?></h2>
					<p class="description" style="margin-bottom:12px;"><?php echo esc_html( $def['description'] ); ?></p>

					<table class="form-table" style="margin-top:0;">
						<tr>
							<th scope="row">Enable</th>
							<td>
								<label>
									<input type="checkbox"
										name="<?php echo esc_attr( $field_base ); ?>[enabled]"
										value="1"
										<?php checked( $enabled ); ?>>
									Send this event to Bento
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">Event Name</th>
							<td>
								<input type="text"
									class="regular-text"
									name="<?php echo esc_attr( $field_base ); ?>[event_name]"
									value="<?php echo esc_attr( $event_name ); ?>"
									placeholder="<?php echo esc_attr( $def['default_event'] ); ?>">
								<p class="description">The Bento event type identifier (e.g. <code><?php echo esc_html( $def['default_event'] ); ?></code>).</p>
							</td>
						</tr>
					</table>

					<h4 style="margin-bottom:4px;">Custom Field Mappings</h4>
					<p class="description">Set subscriber fields when this event fires. Use <em>Only if</em> to apply a row only when a specific event value matches (e.g. only for the Free Plan).</p>

					<table class="wp-list-table widefat striped" style="margin-bottom:8px;table-layout:auto;">
						<thead>
							<tr>
								<th>Bento Field</th>
								<th>Set To</th>
								<th>Value</th>
								<th>Only if&hellip;</th>
								<th>&hellip;equals</th>
								<th></th>
							</tr>
						</thead>
						<tbody id="<?php echo esc_attr( $table_id ); ?>">
							<?php foreach ( $custom_fields as $idx => $field ) :
								$saved_source_type  = $field['source_type']     ?? 'static';
								$saved_condition_key = $field['condition_key']  ?? '';
								$source_list = 'event_data' === $saved_source_type ? $event_keys_id : '';
							?>
							<tr class="bento-field-row">
								<td>
									<input type="text"
										class="regular-text bento-field-key"
										list="bento-pmpro-fields-datalist"
										name="<?php echo esc_attr( $field_base ); ?>[custom_fields][<?php echo (int) $idx; ?>][key]"
										value="<?php echo esc_attr( $field['key'] ?? '' ); ?>"
										placeholder="field_name">
								</td>
								<td>
									<select
										class="bento-source-type"
										name="<?php echo esc_attr( $field_base ); ?>[custom_fields][<?php echo (int) $idx; ?>][source_type]">
										<option value="static"      <?php selected( $saved_source_type, 'static' ); ?>>Static</option>
										<option value="user_meta"   <?php selected( $saved_source_type, 'user_meta' ); ?>>User Meta</option>
										<option value="event_data"  <?php selected( $saved_source_type, 'event_data' ); ?>>Event Data</option>
									</select>
								</td>
								<td>
									<input type="text"
										class="regular-text bento-source-value"
										<?php if ( $source_list ) : ?>list="<?php echo esc_attr( $source_list ); ?>"<?php endif; ?>
										name="<?php echo esc_attr( $field_base ); ?>[custom_fields][<?php echo (int) $idx; ?>][source_value]"
										value="<?php echo esc_attr( $field['source_value'] ?? '' ); ?>"
										placeholder="<?php echo 'event_data' === $saved_source_type ? 'event key' : ( 'user_meta' === $saved_source_type ? 'meta key' : 'value' ); ?>">
								</td>
								<td>
									<select
										class="bento-condition-key"
										name="<?php echo esc_attr( $field_base ); ?>[custom_fields][<?php echo (int) $idx; ?>][condition_key]">
										<option value="">— always —</option>
										<?php foreach ( $event_data_keys as $dk ) : ?>
										<option value="<?php echo esc_attr( $dk ); ?>" <?php selected( $saved_condition_key, $dk ); ?>>
											<?php echo esc_html( $dk ); ?>
										</option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<input type="text"
										class="regular-text bento-condition-value"
										name="<?php echo esc_attr( $field_base ); ?>[custom_fields][<?php echo (int) $idx; ?>][condition_value]"
										value="<?php echo esc_attr( $field['condition_value'] ?? '' ); ?>"
										placeholder="any">
								</td>
								<td>
									<button type="button" class="button bento-remove-field">Remove</button>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<button type="button"
						class="button bento-add-field"
						data-event="<?php echo esc_attr( $event_key ); ?>"
						data-field-base="<?php echo esc_attr( $field_base ); ?>"
						data-table="<?php echo esc_attr( $table_id ); ?>"
						data-event-keys="<?php echo esc_attr( wp_json_encode( $event_data_keys ) ); ?>">
						+ Add Field
					</button>
				</div>
				<?php endforeach; ?>

				<?php submit_button( 'Save Settings' ); ?>
			</form>

			<hr style="margin:32px 0;">

			<h2>Sync Existing Users</h2>
			<p>
				Apply your configured field mappings to users who already existed before this plugin was installed.
				Each sync fires the same event (with the same name and field mappings) as if the action had just happened —
				so the subscriber record in Bento gets the correct fields set.
				<br><strong>Note:</strong> save your settings above before running a sync.
			</p>

			<?php
			// PMPro levels for the filter dropdown.
			$pmpro_levels = [];
			if ( function_exists( 'pmpro_getAllLevels' ) ) {
				$pmpro_levels = pmpro_getAllLevels( false, true );
			} else {
				global $wpdb;
				$pmpro_levels = $wpdb->get_results(
					"SELECT id, name FROM {$wpdb->prefix}pmpro_membership_levels WHERE status = 'active' ORDER BY name"
				);
			}

			// Sensei courses for the filter dropdown.
			$sensei_courses = get_posts( [
				'post_type'      => 'course',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			] );
			?>

			<table class="form-table">
				<tr>
					<th scope="row">PMPro Members</th>
					<td>
						<p class="description" style="margin-bottom:8px;">
							Sends the configured <em>PMPro: Member Checkout</em> event for active members,
							applying your field mappings and conditions. Pick a specific level or sync all at once.
						</p>
						<select id="bento-sync-pmpro-filter" style="margin-right:8px;">
							<option value="0">— All active members —</option>
							<?php foreach ( $pmpro_levels as $level ) : ?>
							<option value="<?php echo (int) $level->id; ?>">
								<?php echo esc_html( $level->name ); ?>
							</option>
							<?php endforeach; ?>
						</select>
						<button id="bento-sync-pmpro" class="button button-primary">Sync</button>
						<span id="bento-sync-pmpro-status" style="margin-left:12px;"></span>
					</td>
				</tr>
				<tr>
					<th scope="row">Sensei Enrollments</th>
					<td>
						<p class="description" style="margin-bottom:8px;">
							Sends the configured <em>Sensei: Course Enrolled</em> event for active and completed
							enrollments. Pick a specific course or sync all at once.
						</p>
						<select id="bento-sync-sensei-filter" style="margin-right:8px;">
							<option value="0">— All enrollments —</option>
							<?php foreach ( $sensei_courses as $course ) : ?>
							<option value="<?php echo (int) $course->ID; ?>">
								<?php echo esc_html( $course->post_title ); ?>
							</option>
							<?php endforeach; ?>
						</select>
						<button id="bento-sync-sensei" class="button button-primary">Sync</button>
						<span id="bento-sync-sensei-status" style="margin-left:12px;"></span>
					</td>
				</tr>
			</table>

		</div>
		<?php
	}
}
