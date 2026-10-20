<?php
/**
 * Settings read pipeline: stored per-site values, network overlay, defaults.
 *
 * @package LinkPurpose
 */

namespace LinkPurpose\Settings;

use LinkPurpose\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Reads effective settings. Writes happen only through the WP Settings API
 * sanitize callback ({@see \LinkPurpose\Form\SettingsValidator}) or, for
 * network defaults, the network page handler (next session).
 *
 * Resolution order for every key in {@see Schema::keys()}:
 *
 *   1. Network value, when the network admin locked the key.
 *   2. The site's stored value, when the key exists in the option row
 *      (`array_key_exists`, not `empty()` — an unchecked checkbox stores
 *      '' and must not fall back to a default of '1').
 *   3. The schema default.
 *
 * Empty strings are passed through as-is: the JS init and
 * {@see \LinkPurpose\Frontend\ConfigBuilder} treat '' as "use the library
 * default", which is what the form's placeholder text shows.
 */
final class Settings {

	/** Per-site option name. */
	const OPTION = Installer::OPTION;

	/** Network defaults option name. */
	const NETWORK_OPTION = Installer::NETWORK_OPTION;

	/** Propagation modes a network default can carry. */
	const MODES = array( 'new', 'all', 'lock' );

	/**
	 * Effective settings for the current site.
	 *
	 * @return array<string,string>
	 */
	public static function all(): array {
		$stored  = self::stored();
		$network = self::network_storage();
		$out     = array();
		foreach ( Schema::defaults() as $key => $default ) {
			if ( self::is_locked_in( $key, $network ) ) {
				$out[ $key ] = (string) $network['values'][ $key ];
				continue;
			}
			$out[ $key ] = array_key_exists( $key, $stored ) ? (string) $stored[ $key ] : $default;
		}
		/**
		 * Filter the effective Link Purpose settings before they are used.
		 *
		 * @param array<string,string> $out Effective `key => value` map.
		 */
		return (array) apply_filters( 'linkpurpose_settings', $out );
	}

	/**
	 * One effective value, or '' for an unknown key.
	 *
	 * @param string $key Storage key.
	 */
	public static function get( string $key ): string {
		$all = self::all();
		return isset( $all[ $key ] ) ? (string) $all[ $key ] : '';
	}

	/**
	 * Raw stored value for form prefill, '' when the site never saved it.
	 * Do not use at runtime — '' here does not mean "default".
	 *
	 * @param string $key Storage key.
	 */
	public static function raw( string $key ): string {
		$stored = self::stored();
		return array_key_exists( $key, $stored ) ? self::to_string( $stored[ $key ] ) : '';
	}

	/**
	 * Whether the site has ever stored this key (distinguishes a stored ''
	 * from "never saved" — the form needs that for checkbox prefill).
	 *
	 * @param string $key Storage key.
	 */
	public static function has_stored( string $key ): bool {
		return array_key_exists( $key, self::stored() );
	}

	/**
	 * The option row as stored, with non-array corruption treated as empty.
	 *
	 * @return array<string,mixed>
	 */
	public static function stored(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/* ===== Network defaults ===== */

	/**
	 * Network defaults in canonical `{values, modes}` shape.
	 *
	 * `get_site_option()` falls back to the regular options table on
	 * single-site, so callers never gate on `is_multisite()`.
	 *
	 * @return array{values: array<string,mixed>, modes: array<string,string>}
	 */
	public static function network_storage(): array {
		$raw = get_site_option( self::NETWORK_OPTION, array() );
		return self::normalize_network_storage( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Validate a raw network blob: unknown keys and invalid modes are dropped.
	 *
	 * @param array<string,mixed> $raw Raw storage.
	 * @return array{values: array<string,mixed>, modes: array<string,string>}
	 */
	public static function normalize_network_storage( array $raw ): array {
		$values = isset( $raw['values'] ) && is_array( $raw['values'] ) ? $raw['values'] : array();
		$modes  = isset( $raw['modes'] ) && is_array( $raw['modes'] ) ? $raw['modes'] : array();
		return array(
			'values' => self::normalize_network_values( $values ),
			'modes'  => self::normalize_network_modes( $modes ),
		);
	}

	/**
	 * Keep only known keys with scalar values, cast to the string shape.
	 *
	 * @param array<mixed,mixed> $values Raw `values` sub-array.
	 * @return array<string,string>
	 */
	private static function normalize_network_values( array $values ): array {
		$out = array();
		foreach ( $values as $key => $value ) {
			if ( is_string( $key ) && Schema::has( $key ) && is_scalar( $value ) ) {
				$out[ $key ] = self::to_string( $value );
			}
		}
		return $out;
	}

	/**
	 * Keep only known keys carrying a valid mode.
	 *
	 * @param array<mixed,mixed> $modes Raw `modes` sub-array.
	 * @return array<string,string>
	 */
	private static function normalize_network_modes( array $modes ): array {
		$out = array();
		foreach ( $modes as $key => $mode ) {
			if ( is_string( $key ) && Schema::has( $key ) && is_string( $mode ) && in_array( $mode, self::MODES, true ) ) {
				$out[ $key ] = $mode;
			}
		}
		return $out;
	}

	/**
	 * Raw network default for one key, '' when unset.
	 *
	 * @param string $key Storage key.
	 */
	public static function network_value( string $key ): string {
		$storage = self::network_storage();
		return array_key_exists( $key, $storage['values'] ) ? (string) $storage['values'][ $key ] : '';
	}

	/**
	 * Whether the network admin has authored a value for this key.
	 *
	 * @param string $key Storage key.
	 */
	public static function has_network_value( string $key ): bool {
		return array_key_exists( $key, self::network_storage()['values'] );
	}

	/**
	 * Propagation mode for one key: 'new' | 'all' | 'lock' | '' (none).
	 *
	 * @param string $key Storage key.
	 */
	public static function network_mode( string $key ): string {
		$storage = self::network_storage();
		return $storage['modes'][ $key ] ?? '';
	}

	/**
	 * Whether the network admin locked this key.
	 *
	 * A lock needs a stored value to be meaningful; '' IS a value for
	 * checkboxes (locked off) and for text fields it means "locked to the
	 * library default", so presence is tested with `array_key_exists`.
	 *
	 * @param string $key Storage key.
	 */
	public static function is_locked( string $key ): bool {
		return self::is_locked_in( $key, self::network_storage() );
	}

	/**
	 * Lock test against an already-loaded storage blob (avoids re-reading
	 * the option once per key inside {@see all()}).
	 *
	 * @param string                                                          $key     Storage key.
	 * @param array{values: array<string,mixed>, modes: array<string,string>} $storage Network storage.
	 */
	private static function is_locked_in( string $key, array $storage ): bool {
		return ( $storage['modes'][ $key ] ?? '' ) === 'lock'
			&& array_key_exists( $key, $storage['values'] );
	}

	/**
	 * Cast a stored scalar to the string shape the form and JS expect.
	 *
	 * @param mixed $value Stored value.
	 */
	private static function to_string( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		return (string) $value;
	}
}
