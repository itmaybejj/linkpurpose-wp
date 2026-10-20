<?php
/**
 * Settings → Link Purpose admin page.
 *
 * @package LinkPurpose
 */

namespace LinkPurpose\Form;

use LinkPurpose\Plugin;
use LinkPurpose\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the options page, the WP Settings API option, and renders the
 * page shell around {@see FormRenderer}.
 */
final class SettingsPage {

	/** Settings API option group (the `settings_fields()` argument). */
	const OPTION_GROUP = 'linkpurpose';

	/** Capability required to view / save. */
	const CAPABILITY = 'manage_options';

	/** Wire admin hooks. */
	public function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_filter( 'plugin_action_links_' . Plugin::basename(), array( __CLASS__, 'add_settings_link' ) );
	}

	/** Settings → Link Purpose. */
	public static function register_menu(): void {
		$hook = add_options_page(
			__( 'Link Purpose Icons', 'linkpurpose' ),
			__( 'Link Purpose', 'linkpurpose' ),
			self::CAPABILITY,
			Plugin::SLUG,
			array( __CLASS__, 'render_page' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'enqueue_assets' ) );
		}
	}

	/** Register the option with its sanitize callback. */
	public static function register_setting(): void {
		register_setting(
			self::OPTION_GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( SettingsValidator::class, 'validate' ),
				'default'           => array(),
			)
		);
	}

	/** Admin CSS + the conditional-fields toggle script, on our page only. */
	public static function enqueue_assets(): void {
		wp_enqueue_style(
			'linkpurpose-admin',
			Plugin::url( 'assets/css/linkpurpose-admin.css' ),
			array(),
			Plugin::VERSION
		);
		ConditionalFields::enqueue_script();
	}

	/**
	 * "Settings" link on the Plugins list row.
	 *
	 * @param array<int,string> $links Existing action links.
	 * @return array<int,string>
	 */
	public static function add_settings_link( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::url() ),
				esc_html__( 'Settings', 'linkpurpose' )
			)
		);
		return $links;
	}

	/** Admin URL of the settings page. */
	public static function url(): string {
		return admin_url( 'options-general.php?page=' . Plugin::SLUG );
	}

	/** Render the page. */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'linkpurpose' ) );
		}
		?>
		<div class="wrap linkpurpose-settings">
			<h1><?php esc_html_e( 'Link Purpose Icons', 'linkpurpose' ); ?></h1>
			<form method="post" action="options.php" autocomplete="off" class="linkpurpose-form">
				<?php
				settings_fields( self::OPTION_GROUP );
				FormRenderer::render();
				submit_button( __( 'Save settings', 'linkpurpose' ), 'primary large' );
				?>
			</form>
		</div>
		<?php
	}
}
