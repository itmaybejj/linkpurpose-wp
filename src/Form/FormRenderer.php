<?php
/**
 * Renders the settings form body from {@see Schema::layout()}.
 *
 * @package LinkPurpose
 */

namespace LinkPurpose\Form;

use LinkPurpose\Settings\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Schema → HTML. Context-aware via {@see SettingsContext}: the same call
 * renders the per-site form (inputs named for the WP Settings API option)
 * and, wrapped in `SettingsContext::push('network')`, the network defaults
 * form (inputs named `network_values[…]`, plus a propagation dropdown).
 *
 * Markup shape, mirroring the Drupal form:
 *
 *   <fieldset class="linkpurpose-section">        ← one per section
 *     <legend>…</legend> intro
 *     <table class="form-table">…</table>           ← section-level fields
 *     <details class="linkpurpose-group">           ← one per group
 *       <summary>…</summary>
 *       <table class="form-table">…</table>
 *     </details>
 *   </fieldset>
 *
 * Each field is a `<tr>` carrying the conditional-visibility attribute when
 * the schema declares `depends`.
 */
final class FormRenderer {

	/**
	 * Render the whole form body (no `<form>`, nonce or submit button —
	 * the page shell owns those).
	 */
	public static function render(): void {
		foreach ( Schema::layout() as $section ) {
			self::render_section( $section );
		}
	}

	/**
	 * The wp_kses allowlist for schema help text.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed_html(): array {
		return array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'em'     => array(),
			'strong' => array(),
			'code'   => array(),
			'br'     => array(),
			'p'      => array(),
			'ol'     => array(),
			'ul'     => array(),
			'li'     => array(),
		);
	}

	/**
	 * One top-level fieldset.
	 *
	 * @param array<string,mixed> $section Layout entry.
	 */
	private static function render_section( array $section ): void {
		?>
		<fieldset class="linkpurpose-section" id="linkpurpose-section-<?php echo esc_attr( $section['id'] ); ?>">
			<legend><?php echo esc_html( $section['title'] ); ?></legend>
			<?php
			if ( ! empty( $section['intro'] ) ) {
				echo wp_kses( $section['intro'], self::allowed_html() );
			}
			if ( ! empty( $section['fields'] ) ) {
				self::render_table( $section['fields'] );
			}
			foreach ( $section['groups'] ?? array() as $group ) {
				self::render_group( $group );
			}
			?>
		</fieldset>
		<?php
	}

	/**
	 * One collapsible group.
	 *
	 * @param array<string,mixed> $group Layout entry.
	 */
	private static function render_group( array $group ): void {
		?>
		<details class="linkpurpose-group" id="linkpurpose-group-<?php echo esc_attr( $group['id'] ); ?>">
			<summary><?php echo esc_html( $group['title'] ); ?></summary>
			<?php
			if ( ! empty( $group['intro'] ) ) {
				echo wp_kses( $group['intro'], self::allowed_html() );
			}
			if ( ! empty( $group['markup'] ) ) {
				echo wp_kses( $group['markup'], self::allowed_html() );
			}
			if ( ! empty( $group['fields'] ) ) {
				self::render_table( $group['fields'] );
			}
			?>
		</details>
		<?php
	}

	/**
	 * A form-table of field rows.
	 *
	 * @param array<int,string> $keys Storage keys, in order.
	 */
	private static function render_table( array $keys ): void {
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $keys as $key ) {
			self::render_field_row( $key );
		}
		echo '</tbody></table>';
	}

	/**
	 * One `<tr>` for a field. Public so tests can render a single row.
	 *
	 * @param string $key Storage key.
	 */
	public static function render_field_row( string $key ): void {
		$def = Schema::field( $key );
		if ( null === $def ) {
			return;
		}
		$id   = SettingsContext::input_id( $key );
		$when = self::conditions_attr( $def['depends'] ?? array() );
		?>
		<tr class="linkpurpose-field linkpurpose-field-<?php echo esc_attr( $def['type'] ); ?>"<?php echo $when; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by ConditionalFields::attr(). ?>>
			<th scope="row">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo wp_kses( $def['label'], array() ); ?></label>
			</th>
			<td>
				<?php
				SettingsContext::print_lock_note( $key );
				self::render_input( $key, $def, $id );
				if ( ! empty( $def['description'] ) ) {
					echo '<p class="description" id="' . esc_attr( $id ) . '-description">'
						. wp_kses( $def['description'], self::allowed_html() )
						. '</p>';
				}
				SettingsContext::print_mode_dropdown( $key );
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * The input element for a field.
	 *
	 * @param string              $key Storage key.
	 * @param array<string,mixed> $def Field definition.
	 * @param string              $id  Input id.
	 */
	private static function render_input( string $key, array $def, string $id ): void {
		$name        = SettingsContext::input_name( $key );
		$value       = SettingsContext::value( $key );
		$disabled    = SettingsContext::is_disabled( $key );
		$placeholder = self::placeholder( $key, $def );
		$described   = ! empty( $def['description'] ) ? ' aria-describedby="' . esc_attr( $id ) . '-description"' : '';

		switch ( $def['type'] ) {
			case 'checkbox':
				printf(
					'<input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s%4$s%5$s />',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( '1', $value, false ),
					disabled( $disabled, true, false ),
					$described // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr() above.
				);
				break;

			case 'select':
				printf(
					'<select id="%1$s" name="%2$s"%3$s%4$s>',
					esc_attr( $id ),
					esc_attr( $name ),
					disabled( $disabled, true, false ),
					$described // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr() above.
				);
				foreach ( $def['options'] as $option_value => $option_label ) {
					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( (string) $option_value ),
						selected( $value, (string) $option_value, false ),
						esc_html( $option_label )
					);
				}
				echo '</select>';
				break;

			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="1" cols="45" class="code large-text" autocomplete="off" placeholder="%3$s"%4$s%5$s>%6$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $placeholder ),
					disabled( $disabled, true, false ),
					$described, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr() above.
					esc_textarea( $value )
				);
				break;

			case 'text':
			default:
				printf(
					'<input type="text" id="%1$s" name="%2$s" class="regular-text%3$s" autocomplete="off" placeholder="%4$s" value="%5$s"%6$s%7$s />',
					esc_attr( $id ),
					esc_attr( $name ),
					'plain' === ( $def['format'] ?? 'plain' ) ? '' : ' code',
					esc_attr( $placeholder ),
					esc_attr( $value ),
					disabled( $disabled, true, false ),
					$described // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr() above.
				);
				break;
		}
	}

	/**
	 * Placeholder text. The `domain` field shows the site's own URL, which
	 * is only known at render time.
	 *
	 * @param string              $key Storage key.
	 * @param array<string,mixed> $def Field definition.
	 */
	private static function placeholder( string $key, array $def ): string {
		if ( 'domain' === $key ) {
			return home_url();
		}
		return (string) ( $def['placeholder'] ?? '' );
	}

	/**
	 * Translate schema `depends` (storage keys) into input names for the
	 * current context and build the attribute.
	 *
	 * @param array<int,array{0:string,1:string}> $depends Schema conditions.
	 */
	private static function conditions_attr( array $depends ): string {
		$conditions = array();
		foreach ( $depends as $condition ) {
			$conditions[] = array( SettingsContext::input_name( (string) $condition[0] ), (string) $condition[1] );
		}
		return ConditionalFields::attr( $conditions );
	}
}
