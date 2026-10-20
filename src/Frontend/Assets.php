<?php
/**
 * Front-end asset registration.
 *
 * @package LinkPurpose
 */

namespace LinkPurpose\Frontend;

use LinkPurpose\Plugin;
use LinkPurpose\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the vendored library, its stylesheet, the settings payload and
 * (unless the theme opted to initialize the library itself) the init shim.
 *
 * Front end only: hooked on `wp_enqueue_scripts`, which never fires in
 * wp-admin, so the Drupal module's "exit on admin routes" check is implicit.
 *
 * Caching: everything is a static file versioned with `?ver=` + the plugin
 * version, except the settings payload, which is inlined into the page.
 * Full-page caches therefore hold the payload until they purge; see
 * developers.md → "Caching".
 */
final class Assets {

	/** Script handle for the vendored library. */
	const LIBRARY_HANDLE = 'linkpurpose';

	/** Script handle for the WordPress init shim. */
	const INIT_HANDLE = 'linkpurpose-wp';

	/** Style handle for the library stylesheet. */
	const STYLE_HANDLE = 'linkpurpose';

	/** Wire the front-end hook. */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue library + payload (+ init shim).
	 */
	public static function enqueue(): void {
		/**
		 * Short-circuit: return false to keep Link Purpose off the current
		 * request entirely (e.g. a landing-page template).
		 *
		 * @param bool $enabled Whether to enqueue. Default true.
		 */
		if ( ! apply_filters( 'linkpurpose_enabled', true ) ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE_HANDLE,
			Plugin::url( 'assets/lib/css/linkpurpose.css' ),
			array(),
			Plugin::VERSION
		);

		wp_enqueue_script(
			self::LIBRARY_HANDLE,
			Plugin::url( 'assets/lib/js/linkpurpose.min.js' ),
			array(),
			Plugin::VERSION,
			true
		);
		wp_add_inline_script( self::LIBRARY_HANDLE, self::inline_settings(), 'before' );

		if ( '1' === Settings::get( 'themePreprocess' ) ) {
			// Theme takes it from here with its own init script.
			return;
		}

		wp_enqueue_script(
			self::INIT_HANDLE,
			Plugin::url( 'assets/js/linkpurpose-wp.js' ),
			array( self::LIBRARY_HANDLE ),
			Plugin::VERSION,
			true
		);
	}

	/**
	 * The inline `window.linkpurposeSettings = {…};` statement.
	 */
	public static function inline_settings(): string {
		return 'window.linkpurposeSettings = ' . wp_json_encode( ConfigBuilder::build() ) . ';';
	}
}
