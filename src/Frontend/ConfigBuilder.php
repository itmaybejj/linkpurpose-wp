<?php
/**
 * Builds the JSON payload the front-end init script consumes.
 *
 * @package LinkPurpose
 */

namespace LinkPurpose\Frontend;

use LinkPurpose\Plugin;
use LinkPurpose\Settings\Schema;
use LinkPurpose\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Effective settings → `window.linkpurposeSettings`.
 *
 * Mirrors what the Drupal module attaches to `drupalSettings.linkpurpose`:
 * every stored key, plus `domain` (stored list + the site's own URL) and
 * the stylesheet URL for shadow-DOM injection. Checkboxes become booleans;
 * everything else stays a string so the JS init's "empty → library
 * default" fallbacks keep working unchanged.
 *
 * Screen-reader messages are resolved here: an empty stored message maps
 * to the translated default from the schema, which is the WordPress
 * equivalent of Drupal passing the stored string through `t()`.
 */
final class ConfigBuilder {

	/**
	 * Build the payload.
	 *
	 * @return array<string,mixed>
	 */
	public static function build(): array {
		$settings = Settings::all();
		$payload  = array();

		foreach ( Schema::fields() as $key => $def ) {
			$value = $settings[ $key ] ?? (string) $def['default'];
			if ( 'checkbox' === $def['type'] ) {
				$payload[ $key ] = ( '1' === $value );
				continue;
			}
			if ( '' === $value && '' !== Schema::message_default( $key ) ) {
				$value = Schema::message_default( $key );
			}
			$payload[ $key ] = $value;
		}

		$payload['domain']    = self::domain_list( $settings['domain'] ?? '' );
		$payload['shadowCSS'] = Plugin::url( 'assets/lib/css/linkpurpose.css' );
		$payload['version']   = Plugin::VERSION;

		/**
		 * Filter the settings object handed to the front-end init script.
		 *
		 * Theme/plugin developers can add keys the admin form does not
		 * expose (`baseSelector`, `baseLinkClass`, `baseIconWrapperClass`,
		 * `noBreakClass`); the init script reads them when present.
		 *
		 * @param array<string,mixed> $payload Settings payload.
		 */
		return (array) apply_filters( 'linkpurpose_js_settings', $payload );
	}

	/**
	 * Stored additional domains plus the current site's home URL, which is
	 * always internal. The library splits on commas.
	 *
	 * @param string $stored Sanitized `domain` setting.
	 */
	public static function domain_list( string $stored ): string {
		$home = home_url();
		return '' === trim( $stored ) ? $home : $stored . ', ' . $home;
	}
}
