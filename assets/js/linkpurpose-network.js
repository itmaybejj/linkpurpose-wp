/**
 * Live-refresh the network-defaults backfill status panel through the WP
 * Heartbeat API, without reloading the settings form around it.
 *
 * Contract with NetworkSettingsPage::heartbeat_received():
 *   - heartbeat-send: while the panel's `data-running` is "1", attach
 *     `linkpurpose_backfill_request: 1`. Once a terminal state has been
 *     observed nothing more is requested; the last panel stays on screen.
 *   - heartbeat-tick: swap in `linkpurpose_backfill_panel` (server-rendered
 *     HTML), mirror `linkpurpose_backfill_running` into `data-running`, and
 *     fire `wp-notice-added` so WP rebinds dismiss buttons on the new notice.
 */
( function ( $ ) {
	var panel = document.getElementById( 'linkpurpose-backfill-panel' );
	if ( ! panel || ! $ ) {
		return;
	}

	$( document ).on( 'heartbeat-send', function ( event, data ) {
		if ( '1' === panel.getAttribute( 'data-running' ) ) {
			data.linkpurpose_backfill_request = 1;
		}
	} );

	$( document ).on( 'heartbeat-tick', function ( event, data ) {
		if ( typeof data.linkpurpose_backfill_panel !== 'string' ) {
			return;
		}
		panel.innerHTML = data.linkpurpose_backfill_panel;
		panel.setAttribute( 'data-running', data.linkpurpose_backfill_running ? '1' : '0' );
		$( document ).trigger( 'wp-notice-added' );
	} );
}( window.jQuery ) );
