<?php
/**
 * Runtime hook orchestrator.
 *
 * @package LinkPurpose
 */

namespace LinkPurpose;

use LinkPurpose\Form\SettingsPage;
use LinkPurpose\Frontend\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's WordPress integration points.
 *
 * Owns nothing data-side: option names and the settings read pipeline live
 * on {@see \LinkPurpose\Settings\Settings}; the settings schema on
 * {@see \LinkPurpose\Settings\Schema}; activation/uninstall on
 * {@see Installer}. This class only decides WHICH components register hooks
 * for the current request.
 */
final class Plugin {

	/**
	 * Plugin version. Keep in sync with the `Version:` header in
	 * linkpurpose.php (scripts/build-dist.sh asserts they match). Used as the
	 * `?ver=` cache-buster on every enqueued asset.
	 */
	const VERSION = '1.0.0';

	/** Admin page slug (`options-general.php?page=linkpurpose`). */
	const SLUG = 'linkpurpose';

	/**
	 * Whether {@see boot()} has already run in this request.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Register hooks. Idempotent.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		( new Assets() )->register();

		if ( is_admin() ) {
			( new SettingsPage() )->register();
		}
	}

	/**
	 * Absolute filesystem path inside the plugin directory.
	 *
	 * @param string $relative Path relative to the plugin root, no leading slash.
	 */
	public static function path( string $relative = '' ): string {
		return plugin_dir_path( LINKPURPOSE_FILE ) . ltrim( $relative, '/' );
	}

	/**
	 * URL inside the plugin directory.
	 *
	 * @param string $relative Path relative to the plugin root, no leading slash.
	 */
	public static function url( string $relative = '' ): string {
		return plugins_url( ltrim( $relative, '/' ), LINKPURPOSE_FILE );
	}

	/**
	 * Plugin basename (`linkpurpose-wp/linkpurpose.php`), for action-link filters.
	 */
	public static function basename(): string {
		return plugin_basename( LINKPURPOSE_FILE );
	}
}
