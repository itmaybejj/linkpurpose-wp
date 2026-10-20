<?php
/**
 * Rendering context for the shared settings form: per-site page vs. the
 * (planned) network defaults page.
 *
 * @package LinkPurpose
 */

namespace LinkPurpose\Form;

use LinkPurpose\Settings\Schema;
use LinkPurpose\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Small static context stack so one renderer ({@see FormRenderer}) can
 * serve both admin screens.
 *
 * Per field, the renderer asks the context for three things: the value to
 * prefill, the input `name=` (the site form posts into the WP Settings API
 * option; the network form posts into its own `network_values[]` bucket),
 * and the locked state (site context only). The network page pushes
 * `network` around its render call and pops after; the site page pushes
 * nothing (`site` is the baseline).
 *
 * Pattern ported from the Editoria11y plugin's SettingsContext, minus the
 * CSA/premium split. Because this plugin's renderer is schema-driven, the
 * input-name indirection replaces Editoria11y's post-render regex rewrite
 * of `name=` attributes.
 */
final class SettingsContext {

	/** Site-form input name prefix (the registered WP option). */
	const SITE_INPUT_PREFIX = Settings::OPTION;

	/** Network-form input name prefix for values. */
	const NETWORK_VALUES_PREFIX = 'network_values';

	/** Network-form input name prefix for propagation modes. */
	const NETWORK_MODES_PREFIX = 'network_modes';

	/**
	 * Context stack; `site` when empty.
	 *
	 * @var array<int,string>
	 */
	private static $stack = array();

	/**
	 * Push a context.
	 *
	 * @param string $context 'site' or 'network'.
	 */
	public static function push( string $context ): void {
		self::$stack[] = ( 'network' === $context ) ? 'network' : 'site';
	}

	/** Pop the top context; no-op at baseline. */
	public static function pop(): void {
		array_pop( self::$stack );
	}

	/** Clear the stack (test fixtures). */
	public static function reset(): void {
		self::$stack = array();
	}

	/** Current context. */
	public static function current(): string {
		return empty( self::$stack ) ? 'site' : (string) end( self::$stack );
	}

	/** Whether the network defaults page is rendering. */
	public static function is_network(): bool {
		return 'network' === self::current();
	}

	/**
	 * Value to prefill for a key.
	 *
	 * Site context: the raw stored value, or the network value when the key
	 * is locked (so the disabled input shows what is in effect). Network
	 * context: the raw network default.
	 *
	 * Text inputs that were never saved prefill empty and let the
	 * placeholder show the default. Choice inputs (checkbox, select) cannot
	 * show a placeholder, so a never-saved key prefills with the schema
	 * default — otherwise every default-on category would render unchecked
	 * on a fresh install and the first save would turn them all off.
	 *
	 * @param string $key Storage key.
	 */
	public static function value( string $key ): string {
		if ( self::is_network() ) {
			return Settings::has_network_value( $key )
				? Settings::network_value( $key )
				: self::unsaved_prefill( $key );
		}
		if ( Settings::is_locked( $key ) ) {
			return Settings::network_value( $key );
		}
		return Settings::has_stored( $key )
			? Settings::raw( $key )
			: self::unsaved_prefill( $key );
	}

	/**
	 * Prefill for a key the current context has never stored: the schema
	 * default for choice fields, '' (placeholder shows through) otherwise.
	 *
	 * @param string $key Storage key.
	 */
	private static function unsaved_prefill( string $key ): string {
		$def = Schema::field( $key );
		if ( null !== $def && in_array( $def['type'], array( 'checkbox', 'select' ), true ) ) {
			return (string) $def['default'];
		}
		return '';
	}

	/**
	 * `name=` attribute for a key's input.
	 *
	 * @param string $key Storage key.
	 */
	public static function input_name( string $key ): string {
		$prefix = self::is_network() ? self::NETWORK_VALUES_PREFIX : self::SITE_INPUT_PREFIX;
		return $prefix . '[' . $key . ']';
	}

	/**
	 * `id=` attribute for a key's input (stable; tests and labels rely on it).
	 *
	 * @param string $key Storage key.
	 */
	public static function input_id( string $key ): string {
		return 'linkpurpose-' . sanitize_html_class( $key );
	}

	/**
	 * Whether the input should render disabled (locked at network level,
	 * site context only).
	 *
	 * @param string $key Storage key.
	 */
	public static function is_disabled( string $key ): bool {
		return ! self::is_network() && Settings::is_locked( $key );
	}

	/**
	 * Echo the "Managed at the network level" note for a locked key (site
	 * context only).
	 *
	 * @param string $key Storage key.
	 */
	public static function print_lock_note( string $key ): void {
		if ( ! self::is_disabled( $key ) ) {
			return;
		}
		echo '<p class="linkpurpose-locked-by-network"><strong>🔒 '
			. esc_html__( 'Managed at the network level', 'linkpurpose' )
			. '</strong></p>';
	}

	/**
	 * Echo the propagation-mode dropdown for a key (network context only).
	 *
	 * Vocabulary matches the Editoria11y network defaults page so admins
	 * running both plugins see one model:
	 *   ''      no network default (value stored but never propagates)
	 *   'new'   seed into sites created from now on
	 *   'all'   seed new sites AND backfill sites still on a default
	 *   'lock'  enforce at read time; sites cannot override
	 *
	 * @param string $key Storage key.
	 */
	public static function print_mode_dropdown( string $key ): void {
		if ( ! self::is_network() ) {
			return;
		}
		$current = Settings::network_mode( $key );
		$options = array(
			''     => __( '— No network default —', 'linkpurpose' ),
			'new'  => __( 'Default for new sites', 'linkpurpose' ),
			'all'  => __( 'Default for all sites (skip custom values)', 'linkpurpose' ),
			'lock' => __( 'Override for all sites (delete custom values)', 'linkpurpose' ),
		);
		$name    = self::NETWORK_MODES_PREFIX . '[' . $key . ']';
		$id      = self::input_id( $key ) . '-mode';
		?>
		<p class="linkpurpose-network-mode">
			<label for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Propagation', 'linkpurpose' ); ?></label>
			<select name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $id ); ?>">
				<?php foreach ( $options as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>"<?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}
}
