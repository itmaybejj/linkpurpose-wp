<?php
/**
 * Sanitizer for the network defaults form POST.
 *
 * @package LinkPurpose
 */

namespace LinkPurpose\Form;

use LinkPurpose\Settings\FieldSanitizer;
use LinkPurpose\Settings\Schema;
use LinkPurpose\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * `network_values[KEY]` + `network_modes[KEY]` → the `{values, modes}`
 * blob stored in {@see Settings::NETWORK_OPTION}.
 *
 * Values run through the same {@see FieldSanitizer} rules as the per-site
 * form, so a key never has two notions of "valid". Every schema key is
 * written on every save (an unchecked checkbox arrives absent and is
 * stored as ''), exactly like the per-site validator — the network blob is
 * authored from scratch each time, never merged.
 *
 * Modes (`new` | `all` | `lock`) are kept for every field type, including
 * a text field whose value is ''. An empty text value is a real
 * instruction — "use the plugin default" — so a network admin can push
 * sites back to the default under `all` or pin them to it under `lock`,
 * exactly as an unchecked checkbox can be propagated as "off". (The
 * Editoria11y plugin drops such modes as inert; this plugin deliberately
 * does not.)
 */
final class NetworkSettingsValidator {

	/**
	 * Sanitize one POST.
	 *
	 * @param array<string,mixed> $post Unslashed POST array.
	 * @return array{values: array<string,string>, modes: array<string,string>}
	 */
	public static function validate( array $post ): array {
		$raw_values = self::sub_array( $post, SettingsContext::NETWORK_VALUES_PREFIX );
		$raw_modes  = self::sub_array( $post, SettingsContext::NETWORK_MODES_PREFIX );

		$values = array();
		foreach ( Schema::keys() as $key ) {
			$values[ $key ] = FieldSanitizer::sanitize( $key, $raw_values[ $key ] ?? null );
		}

		$modes = array();
		foreach ( $raw_modes as $key => $mode ) {
			if ( ! is_string( $key ) || ! Schema::has( $key ) || ! is_string( $mode ) || ! in_array( $mode, Settings::MODES, true ) ) {
				continue;
			}
			$modes[ $key ] = $mode;
		}

		return array(
			'values' => $values,
			'modes'  => $modes,
		);
	}

	/**
	 * An array-valued POST bucket, or an empty array.
	 *
	 * @param array<string,mixed> $post POST array.
	 * @param string              $name Bucket name.
	 * @return array<mixed,mixed>
	 */
	private static function sub_array( array $post, string $name ): array {
		return isset( $post[ $name ] ) && is_array( $post[ $name ] ) ? $post[ $name ] : array();
	}
}
