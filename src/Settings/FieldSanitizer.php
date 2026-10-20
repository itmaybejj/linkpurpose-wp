<?php
/**
 * Per-field sanitize rules, driven by the schema's `type` / `format`.
 *
 * @package LinkPurpose
 */

namespace LinkPurpose\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One sanitize rule per field type/format, shared by the per-site form
 * validator and (next session) the network defaults validator so a key can
 * never have two notions of "valid".
 *
 * Every rule returns a string: option storage is string-shaped throughout
 * ('1' / '' for checkboxes) so values round-trip through form inputs and
 * `wp_json_encode()` without type surprises.
 */
final class FieldSanitizer {

	/**
	 * Sanitize a posted value for a known field. Unknown keys return ''.
	 *
	 * @param string $key   Storage key.
	 * @param mixed  $value Raw posted value (string, or absent → null).
	 */
	public static function sanitize( string $key, $value ): string {
		$def = Schema::field( $key );
		if ( null === $def ) {
			return '';
		}
		switch ( $def['type'] ) {
			case 'checkbox':
				return self::checkbox( $value );
			case 'select':
				return self::select( $value, array_keys( $def['options'] ), (string) $def['default'] );
			case 'text':
			case 'textarea':
			default:
				return self::text( $value, $def['format'] ?? 'plain' );
		}
	}

	/**
	 * Checkbox storage shape: '1' when checked, '' otherwise. Unchecked
	 * boxes are absent from the POST, so null/'' both map to ''.
	 *
	 * @param mixed $value Posted value.
	 */
	public static function checkbox( $value ): string {
		return ( ! is_array( $value ) && ! empty( $value ) ) ? '1' : '';
	}

	/**
	 * Fixed-choice select: the posted value if it is a known option,
	 * otherwise the default (a forged POST never stores an arbitrary string).
	 *
	 * @param mixed             $value    Posted value.
	 * @param array<int,string> $choices  Allowed option values.
	 * @param string            $fallback Stored when the posted value is not a choice.
	 */
	public static function select( $value, array $choices, string $fallback ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		return in_array( $value, $choices, true ) ? $value : $fallback;
	}

	/**
	 * Text-ish input, by format.
	 *
	 * @param mixed  $value  Posted value.
	 * @param string $format One of 'selector' | 'domain' | 'css_class' | 'css_classes' | 'plain'.
	 */
	public static function text( $value, string $format ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		switch ( $format ) {
			case 'selector':
				return self::selector( $value );
			case 'domain':
				return self::domain_list( $value );
			case 'css_class':
				return self::css_class( $value );
			case 'css_classes':
				return self::css_classes( $value );
			case 'plain':
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * CSS selector list. Strips markup, then collapses newlines and runs of
	 * whitespace to single spaces: the form uses one-row textareas purely so
	 * long selectors wrap, and a selector with embedded newlines is still a
	 * one-line selector to the browser.
	 *
	 * Validity is NOT checked here — only a browser's `querySelector` can
	 * say whether a selector parses, so the JS init validates each selector
	 * and falls back to the library default with a console warning.
	 *
	 * @param string $value Posted value.
	 */
	public static function selector( string $value ): string {
		$value = sanitize_textarea_field( $value );
		$value = (string) preg_replace( '/\s+/u', ' ', $value );
		return trim( $value );
	}

	/**
	 * Comma-separated domain list: trim each entry, drop empties, strip
	 * internal whitespace (a domain never contains spaces), re-join with a
	 * comma + space so the stored string reads back cleanly in the form.
	 *
	 * @param string $value Posted value.
	 */
	public static function domain_list( string $value ): string {
		$value = sanitize_textarea_field( $value );
		$parts = array();
		foreach ( explode( ',', $value ) as $part ) {
			$part = (string) preg_replace( '/\s+/u', '', $part );
			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}
		return implode( ', ', array_values( array_unique( $parts ) ) );
	}

	/**
	 * A single HTML class token. Anything after the first token is dropped:
	 * the library passes this value to `classList.add()`, which throws on
	 * whitespace.
	 *
	 * @param string $value Posted value.
	 */
	public static function css_class( string $value ): string {
		$tokens = self::class_tokens( $value );
		return $tokens[0] ?? '';
	}

	/**
	 * Comma-separated class list (icon-font classes). Accepts comma- or
	 * whitespace-separated input and normalizes to `a, b, c`.
	 *
	 * @param string $value Posted value.
	 */
	public static function css_classes( string $value ): string {
		return implode( ', ', self::class_tokens( $value ) );
	}

	/**
	 * Split on commas/whitespace and sanitize each token as an HTML class.
	 *
	 * @param string $value Posted value.
	 * @return array<int,string>
	 */
	private static function class_tokens( string $value ): array {
		$value  = sanitize_text_field( $value );
		$tokens = array();
		$parts  = preg_split( '/[\s,]+/u', $value );
		foreach ( is_array( $parts ) ? $parts : array() as $token ) {
			$token = sanitize_html_class( $token );
			if ( '' !== $token ) {
				$tokens[] = $token;
			}
		}
		return array_values( array_unique( $tokens ) );
	}
}
