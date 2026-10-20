<?php
/**
 * Activation, deactivation and uninstall lifecycle.
 *
 * @package LinkPurpose
 */

namespace LinkPurpose;

defined( 'ABSPATH' ) || exit;

/**
 * Lifecycle hooks. Deliberately free of other class dependencies so
 * uninstall.php can require this one file without the autoloader.
 */
final class Installer {

	/** Per-site settings option (autoloaded array). */
	const OPTION = 'linkpurpose_settings';

	/**
	 * Network-level defaults option (wp_sitemeta on multisite; falls back to
	 * the regular options table on single-site via `get_site_option()`).
	 * Shape: `array( 'values' => array<string,mixed>, 'modes' => array<string,string> )`.
	 */
	const NETWORK_OPTION = 'linkpurpose_network_settings';

	/**
	 * Network option holding the backfill worker's run state
	 * ({@see Form\NetworkDefaultsWorker}). Transient per run.
	 */
	const WORKER_STATE_OPTION = 'linkpurpose_network_backfill_state';

	/**
	 * Network option holding every value the network has ever published for
	 * each key — the worker's "is this site still tracking the network"
	 * trail. Outlives individual runs; only uninstall removes it.
	 */
	const WORKER_HISTORY_OPTION = 'linkpurpose_network_history';

	/** WP-Cron hook the backfill worker ticks on. */
	const WORKER_CRON_HOOK = 'linkpurpose_network_backfill';

	/**
	 * Activation. Nothing to seed: the read pipeline falls back to
	 * hardcoded defaults for every key the option lacks, so a fresh install
	 * is fully configured with an empty option row.
	 */
	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			deactivate_plugins( plugin_basename( LINKPURPOSE_FILE ) );
			wp_die(
				esc_html__( 'Link Purpose Icons requires PHP 7.4 or newer.', 'linkpurpose' ),
				'',
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Deactivation. Settings are preserved; a queued backfill tick is
	 * dropped so WP-Cron is not left with an event whose callback is gone.
	 * The run state stays: the next network save starts a fresh run anyway.
	 */
	public static function deactivate(): void {
		wp_unschedule_hook( self::WORKER_CRON_HOOK );
	}

	/**
	 * Uninstall: delete the per-site option on every site, then the network
	 * options. WordPress fires uninstall exactly once (in the network-admin
	 * context on multisite), so the site iteration is ours to do.
	 */
	public static function uninstall(): void {
		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				delete_option( self::OPTION );
				restore_current_blog();
			}
		} else {
			delete_option( self::OPTION );
		}
		wp_unschedule_hook( self::WORKER_CRON_HOOK );
		delete_site_option( self::NETWORK_OPTION );
		delete_site_option( self::WORKER_STATE_OPTION );
		delete_site_option( self::WORKER_HISTORY_OPTION );
	}
}
