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
	 * Deactivation. Settings are preserved.
	 */
	public static function deactivate(): void {}

	/**
	 * Uninstall: delete the per-site option on every site, then the network
	 * option. WordPress fires uninstall exactly once (in the network-admin
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
		delete_site_option( self::NETWORK_OPTION );
	}
}
