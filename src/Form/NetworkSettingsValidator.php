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
 * Modes (`new` | `all` | `lock`) are kept only where they can do
 * something:
 *
 *   - checkbox / select: always kept. '' IS a value for a checkbox
 *     ("locked off", "default off for all sites").
 *   - text / textarea: dropped when the value is ''. An empty text value
 *     means "use the library default", so there is nothing to seed or
 *     backfill, and a lock on it would pin sites to a default they already
 *     have. Dropped keys are reported so the page can say so instead of
 *     silently reverting the dropdown.
 */
final class NetworkSettingsValidator {

	/**
	 * Sanitize one POST.
	 *
	 * @param array<string,mixed> $post Unslashed POST array.
	 * @return array{values: array<string,string>, modes: array<string,string>, inert: array<int,string>}
	 *         `inert` lists keys whose propagating mode was dropped because
	 *         the text value is empty.
	 */
	public static function validate( array $post ): array {
		$raw_values = self::sub_array( $post, SettingsContext::NETWORK_VALUES_PREFIX );
		$raw_modes  = self::sub_array( $post, SettingsContext::NETWORK_MODES_PREFIX );

		$values = array();
		foreach ( Schema::keys() as $key ) {
			$values[ $key ] = FieldSanitizer::sanitize( $key, $raw_values[ $key ] ?? null );
		}

		$modes = array();
		$inert = array();
		foreach ( $raw_modes as $key => $mode ) {
			if ( ! is_string( $key ) || ! Schema::has( $key ) || ! is_string( $mode ) || ! in_array( $mode, Settings::MODES, true ) ) {
				continue;
			}
			if ( '' === $values[ $key ] && self::is_text_field( $key ) ) {
				$inert[] = $key;
				continue;
			}
			$modes[ $key ] = $mode;
		}

		return array(
			'values' => $values,
			'modes'  => $modes,
			'inert'  => $inert,
		);
	}

	/**
	 * Whether a key is a free-text field (as opposed to a fixed choice).
	 *
	 * @param string $key Storage key.
	 */
	public static function is_text_field( string $key ): bool {
		$def = Schema::field( $key );
		return null !== $def && in_array( $def['type'], array( 'text', 'textarea' ), true );
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
