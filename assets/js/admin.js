/**
 * Bento PMPro Integration – Admin Settings JS
 *
 * On load:
 *   1. Fetches Bento subscriber fields → populates shared <datalist> for
 *      "Bento Field" autocomplete.
 *   2. Fetches DB values (PMPro levels, Sensei courses/lessons) → populates
 *      per-key <datalist> elements so the "…equals" condition input offers
 *      real suggestions (e.g. actual plan names, course titles).
 *   3. Wires existing condition-value inputs to the correct datalist based
 *      on their current "Only if…" selection.
 *   4. Resumes polling for any sync that was still running when the page loaded.
 *
 * Interactions:
 *   - Source-type change  → update source-value datalist + placeholder.
 *   - Condition-key change → update condition-value datalist.
 *   - Add Field button    → append a new blank row with all columns.
 *   - Remove button       → remove the row.
 *   - Sync buttons        → start a background sync via Action Scheduler,
 *                           then poll for status updates every 3 seconds.
 */
/* global jQuery, bentoPmpro */
jQuery( function ( $ ) {

	var BENTO_FIELDS_LIST = 'bento-pmpro-fields-datalist';

	// -------------------------------------------------------------------------
	// 1. Fetch Bento subscriber fields → "Bento Field" autocomplete datalist
	// -------------------------------------------------------------------------
	if ( typeof bentoPmpro !== 'undefined' ) {
		$.post(
			bentoPmpro.ajaxUrl,
			{ action: 'bento_pmpro_fetch_fields', _ajax_nonce: bentoPmpro.nonce },
			function ( response ) {
				if ( ! response.success ) {
					console.warn( 'Bento PMPro: could not load Bento fields – ' + ( response.data || 'unknown' ) );
					return;
				}
				var $dl = $( '#' + BENTO_FIELDS_LIST );
				$.each( response.data, function ( i, key ) {
					$dl.append( $( '<option>' ).val( key ).text( key ) );
				} );
			}
		);

		// -----------------------------------------------------------------------
		// 2. Fetch condition values (levels, courses, lessons …) and wire inputs
		// -----------------------------------------------------------------------
		$.post(
			bentoPmpro.ajaxUrl,
			{ action: 'bento_pmpro_condition_values', _ajax_nonce: bentoPmpro.condNonce },
			function ( response ) {
				if ( ! response.success ) {
					console.warn( 'Bento PMPro: could not load condition values – ' + ( response.data || 'unknown' ) );
					return;
				}

				// Populate every <datalist id="bento-condvals-{key}"> that exists on page.
				$.each( response.data, function ( condKey, values ) {
					var $dl = $( '#bento-condvals-' + condKey );
					if ( ! $dl.length ) return;
					$dl.empty();
					$.each( values, function ( i, v ) {
						$dl.append( $( '<option>' ).val( v ).text( v ) );
					} );
				} );

				// Wire any existing rows that already have a condition key selected.
				$( '.bento-condition-key' ).each( function () {
					wireConditionValue( $( this ) );
				} );
			}
		);
	}

	// -------------------------------------------------------------------------
	// Helper: point a row's condition-value input at the correct datalist
	// -------------------------------------------------------------------------
	function wireConditionValue( $condKeySelect ) {
		var condKey    = $condKeySelect.val();
		var $row       = $condKeySelect.closest( '.bento-field-row' );
		var $condInput = $row.find( '.bento-condition-value' );
		var datalistId = 'bento-condvals-' + condKey;

		if ( condKey && $( '#' + datalistId ).length ) {
			$condInput.attr( 'list', datalistId );
		} else {
			$condInput.removeAttr( 'list' );
		}
	}

	// -------------------------------------------------------------------------
	// Source-type change: update source-value datalist + placeholder
	// -------------------------------------------------------------------------
	$( document ).on( 'change', '.bento-source-type', function () {
		var $select    = $( this );
		var $row       = $select.closest( '.bento-field-row' );
		var $srcInput  = $row.find( '.bento-source-value' );
		var sourceType = $select.val();
		var eventKey   = $select.closest( '.bento-event-section' ).data( 'event-key' );

		if ( 'event_data' === sourceType ) {
			$srcInput.attr( 'list', 'bento-event-keys-' + eventKey );
			$srcInput.attr( 'placeholder', 'event key' );
		} else if ( 'user_meta' === sourceType ) {
			$srcInput.removeAttr( 'list' );
			$srcInput.attr( 'placeholder', 'meta key' );
		} else {
			$srcInput.removeAttr( 'list' );
			$srcInput.attr( 'placeholder', 'value' );
		}
	} );

	// -------------------------------------------------------------------------
	// Condition-key change: re-wire the condition-value datalist
	// -------------------------------------------------------------------------
	$( document ).on( 'change', '.bento-condition-key', function () {
		wireConditionValue( $( this ) );
		// Clear the old value since the options have changed.
		$( this ).closest( '.bento-field-row' ).find( '.bento-condition-value' ).val( '' );
	} );

	// -------------------------------------------------------------------------
	// Build the condition-key <select> HTML for a new row
	// -------------------------------------------------------------------------
	function buildConditionSelect( name, eventKeys ) {
		var html = '<select class="bento-condition-key" name="' + name + '">';
		html += '<option value="">— always —</option>';
		$.each( eventKeys, function ( i, k ) {
			html += '<option value="' + k + '">' + k + '</option>';
		} );
		html += '</select>';
		return html;
	}

	// -------------------------------------------------------------------------
	// Add a new blank custom-field mapping row
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.bento-add-field', function () {
		var $btn      = $( this );
		var fieldBase = $btn.data( 'field-base' );
		var tableId   = $btn.data( 'table' );
		var $tbody    = $( '#' + tableId );
		var index     = $tbody.find( '.bento-field-row' ).length;
		var eventKeys = $btn.data( 'event-keys' ) || [];

		var row =
			'<tr class="bento-field-row">' +
				'<td>' +
					'<input type="text" class="regular-text bento-field-key"' +
					' list="' + BENTO_FIELDS_LIST + '"' +
					' name="' + fieldBase + '[custom_fields][' + index + '][key]"' +
					' value="" placeholder="field_name">' +
				'</td>' +
				'<td>' +
					'<select class="bento-source-type"' +
					' name="' + fieldBase + '[custom_fields][' + index + '][source_type]">' +
						'<option value="static">Static</option>' +
						'<option value="user_meta">User Meta</option>' +
						'<option value="event_data">Event Data</option>' +
					'</select>' +
				'</td>' +
				'<td>' +
					'<input type="text" class="regular-text bento-source-value"' +
					' name="' + fieldBase + '[custom_fields][' + index + '][source_value]"' +
					' value="" placeholder="value">' +
				'</td>' +
				'<td>' +
					buildConditionSelect(
						fieldBase + '[custom_fields][' + index + '][condition_key]',
						eventKeys
					) +
				'</td>' +
				'<td>' +
					'<input type="text" class="regular-text bento-condition-value"' +
					' name="' + fieldBase + '[custom_fields][' + index + '][condition_value]"' +
					' value="" placeholder="any">' +
				'</td>' +
				'<td>' +
					'<button type="button" class="button bento-remove-field">Remove</button>' +
				'</td>' +
			'</tr>';

		$tbody.append( row );
	} );

	// -------------------------------------------------------------------------
	// Remove a row
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.bento-remove-field', function () {
		$( this ).closest( '.bento-field-row' ).remove();
	} );

	// -------------------------------------------------------------------------
	// Bulk sync: fire-and-poll via Action Scheduler
	//
	// 1. Click → POST bento_pmpro_start_sync → schedules an AS job, returns
	//    immediately (no waiting for the actual sync to finish).
	// 2. Poll bento_pmpro_sync_status every 3 s until status !== 'running'.
	// 3. On page load, resume polling for any type that is still 'running'
	//    (handles page refreshes mid-sync).
	// -------------------------------------------------------------------------
	var syncPollers = {}; // active setInterval handles, keyed by type

	function startPolling( type, $btn, $status ) {
		if ( syncPollers[ type ] ) {
			return; // Already polling for this type.
		}
		$btn.prop( 'disabled', true );
		syncPollers[ type ] = setInterval( function () {
			$.post(
				bentoPmpro.ajaxUrl,
				{
					action:      'bento_pmpro_sync_status',
					_ajax_nonce: bentoPmpro.syncNonce,
					type:        type,
				},
				function ( response ) {
					if ( ! response.success ) {
						clearInterval( syncPollers[ type ] );
						delete syncPollers[ type ];
						$status.text( 'Error: ' + ( response.data || 'unknown error' ) );
						$btn.prop( 'disabled', false );
						return;
					}
					var d = response.data;
					$status.text( d.message || '' );
					if ( 'running' !== d.status ) {
						clearInterval( syncPollers[ type ] );
						delete syncPollers[ type ];
						$btn.prop( 'disabled', false );
					}
				}
			).fail( function () {
				// Network hiccup — keep polling, don't abort.
			} );
		}, 3000 );
	}

	function runSync( type, $btn, $status, filterId ) {
		$btn.prop( 'disabled', true );
		$status.text( 'Queuing sync…' );

		$.post(
			bentoPmpro.ajaxUrl,
			{
				action:      'bento_pmpro_start_sync',
				_ajax_nonce: bentoPmpro.syncNonce,
				type:        type,
				filter_id:   filterId || 0,
			},
			function ( response ) {
				if ( ! response.success ) {
					$status.text( 'Error: ' + ( response.data || 'unknown error' ) );
					$btn.prop( 'disabled', false );
					return;
				}
				$status.text( 'Queued — processing in background…' );
				startPolling( type, $btn, $status );
			}
		).fail( function () {
			$status.text( 'Request failed — please try again.' );
			$btn.prop( 'disabled', false );
		} );
	}

	$( '#bento-sync-pmpro' ).on( 'click', function () {
		runSync( 'pmpro', $( this ), $( '#bento-sync-pmpro-status' ), $( '#bento-sync-pmpro-filter' ).val() );
	} );

	$( '#bento-sync-sensei' ).on( 'click', function () {
		runSync( 'sensei', $( this ), $( '#bento-sync-sensei-status' ), $( '#bento-sync-sensei-filter' ).val() );
	} );

	// Resume polling for any sync that was still running when the page loaded.
	if ( typeof bentoPmpro !== 'undefined' && bentoPmpro.syncStatus ) {
		$.each( bentoPmpro.syncStatus, function ( type, statusData ) {
			if ( 'running' === statusData.status ) {
				var $btn    = $( '#bento-sync-' + type );
				var $status = $( '#bento-sync-' + type + '-status' );
				if ( $btn.length ) {
					$status.text( statusData.message || 'Resuming…' );
					startPolling( type, $btn, $status );
				}
			}
		} );
	}

} );
