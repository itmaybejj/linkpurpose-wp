<?php
/**
 * Sanitize callback for the per-site `linkpurpose_settings` option.
 *
 * @package LinkPurpose
 */

namespace LinkPurpose\Form;

use LinkPurpose\Settings\FieldSanitizer;
use LinkPurpose\Settings\Schema;
use LinkPurpose\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registered via `register_setting()` in {@see SettingsPage}. options.php
 * runs every POST through here before writing.
 */
final class SettingsValidator {

	/**
	 * Sanitize the posted settings array.
	 *
	 * Output always contains every schema key (unchecked checkboxes arrive
	 * absent and are stored as ''), never contains unknown keys, and has
	 * network-locked keys coerced to the network value regardless of what
	 * the client posted — the disabled inputs are a UX hint, not a trust
	 * boundary.
	 *
	 * @param mixed $input Raw POST value for the option.
	 * @return array<string,string>
	 */
	public static function validate( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = array();
		foreach ( Schema::keys() as $key ) {
			$out[ $key ] = FieldSanitizer::sanitize( $key, $input[ $key ] ?? null );
		}
		$out = self::enforce_network_locks( $out );

		/**
		 * Fires after the settings have been sanitized and before they are
		 * written. Page-cache integrations can purge here.
		 *
		 * @param array<string,string> $out Sanitized settings about to be stored.
		 */
		do_action( 'linkpurpose_settings_validated', $out );

		return $out;
	}

	/**
	 * Overwrite locked keys with the network value.
	 *
	 * @param array<string,string> $settings Sanitized settings.
	 * @return array<string,string>
	 */
	private static function enforce_network_locks( array $settings ): array {
		$network = Settings::network_storage();
		foreach ( $network['modes'] as $key => $mode ) {
			if ( 'lock' !== $mode || ! array_key_exists( $key, $network['values'] ) || ! array_key_exists( $key, $settings ) ) {
				continue;
			}
			$settings[ $key ] = (string) $network['values'][ $key ];
		}
		return $settings;
	}
}
