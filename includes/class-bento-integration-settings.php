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
		add_action( 'wp_ajax_bento_pmpro_fetch_fields',       [ __CLASS__, 'ajax_fetch_fields' ] );
		add_action( 'wp_ajax_bento_pmpro_condition_values',   [ __CLASS__, 'ajax_condition_values' ] );
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
		</div>
		<?php
	}
}
