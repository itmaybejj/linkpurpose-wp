<?php
/**
 * Client-side conditional visibility for admin form rows — the WordPress
 * stand-in for Drupal's `#states`.
 *
 * @package LinkPurpose
 */

namespace LinkPurpose\Form;

defined( 'ABSPATH' ) || exit;

/**
 * Emits a `data-linkpurpose-when` attribute describing the inputs a row
 * depends on; assets/js/linkpurpose-admin.js reads it and toggles the
 * `hidden` attribute as those inputs change.
 *
 * Multiple conditions are AND-ed, which is what the icon-classes field
 * needs (category enabled AND icon type = classes). Degrades safely with
 * JS off: everything stays visible and the server-side validator remains
 * the trust boundary.
 */
final class ConditionalFields {

	/** Data attribute carrying the JSON rule list. Stable JS contract. */
	const ATTRIBUTE = 'data-linkpurpose-when';

	/** Script handle for the toggle script. */
	const SCRIPT_HANDLE = 'linkpurpose-admin';

	/**
	 * Build the attribute string (with a leading space) for a rule list.
	 *
	 * @param array<int,array{0:string,1:string}> $conditions List of `[ input name, required value ]`.
	 *                                                        Checkbox "checked" is value '1'; unchecked is ''.
	 * @return string `' data-linkpurpose-when="…"'`, or '' for no conditions.
	 */
	public static function attr( array $conditions ): string {
		if ( empty( $conditions ) ) {
			return '';
		}
		$rules = array();
		foreach ( $conditions as $condition ) {
			$rules[] = array(
				'input' => (string) $condition[0],
				'value' => (string) $condition[1],
			);
		}
		return ' ' . self::ATTRIBUTE . '="' . esc_attr( (string) wp_json_encode( $rules ) ) . '"';
	}

	/**
	 * Enqueue the toggle script. Call from the page's `load-…` hook.
	 */
	public static function enqueue_script(): void {
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			\LinkPurpose\Plugin::url( 'assets/js/linkpurpose-admin.js' ),
			array(),
			\LinkPurpose\Plugin::VERSION,
			true
		);
	}
}
