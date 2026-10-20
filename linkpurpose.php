<?php
/**
 * Link Purpose Icons
 *
 * Plugin Name:       Link Purpose Icons
 * Plugin URI:        https://github.com/itmaybejj/linkpurpose-wp
 * Description:       Adds icons and screen reader hints to links that open new windows, leave the site, download files, send email, make calls or open apps.
 * Version:           1.0.0
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * Tested up to:      7.1
 * Author:            John Jameson
 * Author URI:        https://editoria11y.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       linkpurpose
 * Domain Path:       /languages
 *
 * @package LinkPurpose
 * @link    https://itmaybejj.github.io/linkpurpose/
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Single-instance guard: a second copy of the plugin (e.g. a renamed folder
// left behind during an upgrade) must not redeclare the namespace.
if ( defined( 'LINKPURPOSE_FILE' ) ) {
	return;
}

/** Absolute path to this file; everything else derives from it. */
define( 'LINKPURPOSE_FILE', __FILE__ );

/*
 * PSR-4 autoloader for the LinkPurpose\ namespace (src/).
 *
 * The plugin owns its autoloader rather than shipping Composer's so the
 * distribution archive needs no vendor/ tree at all: Composer is a dev-time
 * dependency only (coding standards, PHPUnit). composer.json declares the
 * same mapping so tests and IDEs resolve classes identically.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'LinkPurpose\\';
		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( \LinkPurpose\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \LinkPurpose\Installer::class, 'deactivate' ) );

\LinkPurpose\Plugin::boot();
