<?php
/**
 * Network Admin → Settings → Link Purpose defaults.
 *
 * @package LinkPurpose
 */

namespace LinkPurpose\Form;

use LinkPurpose\Plugin;
use LinkPurpose\Settings\Schema;
use LinkPurpose\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lets super-admins author a default value and a propagation mode for
 * every setting. Renders the same schema-driven form as the per-site page
 * ({@see FormRenderer} inside `SettingsContext::push( 'network' )`), so
 * the two screens cannot drift; only the input names and the per-field
 * propagation dropdown differ.
 *
 * The WP Settings API (`options.php`) is per-site, so this page uses the
 * standard network-admin pattern: a manual `admin_post_*` handler with a
 * nonce, then a redirect back with a notice. A save whose only effect
 * would be to store a value nobody receives (value changed, dropdown left
 * on "No network default") is rejected with the offending fields listed —
 * the admin almost always meant to flip the dropdown too.
 *
 * A status panel above the form shows backfill progress; while a run is
 * active it refreshes over the WP Heartbeat API without reloading the page
 * (which would discard in-progress edits).
 */
final class NetworkSettingsPage {

	/** Page slug (`settings.php?page=<slug>` in the network admin). */
	const SLUG = 'linkpurpose-network';

	/** `admin_post_*` action. */
	const SAVE_ACTION = 'linkpurpose_save_network_defaults';

	/** Nonce action + field. */
	const NONCE_ACTION = 'linkpurpose_network_defaults';
	const NONCE_FIELD  = 'linkpurpose_network_defaults_nonce';

	/** Capability required to view / save. */
	const CAPABILITY = 'manage_network_options';

	/** Heartbeat request / response keys (shared with assets/js/linkpurpose-network.js). */
	const HEARTBEAT_REQUEST = 'linkpurpose_backfill_request';
	const HEARTBEAT_PANEL   = 'linkpurpose_backfill_panel';
	const HEARTBEAT_RUNNING = 'linkpurpose_backfill_running';

	/** Transient prefix for the post-redirect notice payload (per user). */
	const NOTICE_TRANSIENT = 'linkpurpose_network_notice_';

	/** Wire hooks. Caller gates on `is_multisite()`. */
	public function register(): void {
		add_action( 'network_admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( __CLASS__, 'handle_save' ) );
		add_filter( 'heartbeat_received', array( __CLASS__, 'heartbeat_received' ), 10, 2 );
		add_filter( 'network_admin_plugin_action_links_' . Plugin::basename(), array( __CLASS__, 'add_settings_link' ) );
	}

	/** Network Admin → Settings → Link Purpose defaults. */
	public static function register_menu(): void {
		$hook = add_submenu_page(
			'settings.php',
			__( 'Link Purpose defaults', 'linkpurpose' ),
			__( 'Link Purpose', 'linkpurpose' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'enqueue_assets' ) );
		}
	}

	/** Shared admin CSS + conditional-fields script, plus the heartbeat client. */
	public static function enqueue_assets(): void {
		SettingsPage::enqueue_assets();
		wp_enqueue_script( 'heartbeat' );
		wp_enqueue_script(
			'linkpurpose-network',
			Plugin::url( 'assets/js/linkpurpose-network.js' ),
			array( 'heartbeat', 'jquery' ),
			Plugin::VERSION,
			true
		);
	}

	/**
	 * "Network defaults" link on the network Plugins list row.
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
				esc_html__( 'Network defaults', 'linkpurpose' )
			)
		);
		return $links;
	}

	/** URL of the page. */
	public static function url(): string {
		return network_admin_url( 'settings.php?page=' . self::SLUG );
	}

	/**
	 * Plain-language disclosure of the overwrite rule, shown above the
	 * form. The backfill decides "still tracking the network" by value
	 * equality, so a per-site value that happens to equal a default is
	 * replaced too.
	 */
	public static function propagation_help_text(): string {
		return __(
			'"Default for all sites" and "Override for all sites" replace a site\'s current value whenever that value is still a plugin default or a previous network default — including a per-site value that happens to match one. Leaving a text field blank with a propagation mode sends "use the plugin default" to sites, so a blank "Override for all sites" pins sites to the default.',
			'linkpurpose'
		);
	}

	/* ===== Rendering ===== */

	/** Render the page. */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'linkpurpose' ) );
		}
		?>
		<div class="wrap linkpurpose-settings linkpurpose-network-settings">
			<h1><?php esc_html_e( 'Link Purpose defaults', 'linkpurpose' ); ?></h1>
			<?php
			self::render_notices();
			self::render_backfill_panel();
			?>
			<p class="description linkpurpose-propagation-help"><?php echo esc_html( self::propagation_help_text() ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off" class="linkpurpose-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<?php
				wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
				SettingsContext::push( 'network' );
				try {
					FormRenderer::render();
				} finally {
					SettingsContext::pop();
				}
				submit_button( __( 'Save network defaults', 'linkpurpose' ), 'primary large' );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Post-redirect notices: saved, or rejected (orphans).
	 */
	private static function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
		$updated = isset( $_GET['updated'] ) && '1' === $_GET['updated'];
		$notice  = self::take_notice();
		$orphans = self::labels_for( $notice['orphans'] ?? array() );

		if ( ! empty( $orphans ) ) {
			?>
			<div class="notice notice-error">
				<p><strong><?php esc_html_e( 'Network defaults were not saved.', 'linkpurpose' ); ?></strong>
				<?php esc_html_e( 'You changed these settings but left their propagation on "No network default", so the change would not reach any site. Choose a propagation mode or revert the value, then save again:', 'linkpurpose' ); ?></p>
				<?php self::print_label_list( $orphans ); ?>
			</div>
			<?php
			return;
		}
		if ( $updated ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Network defaults saved.', 'linkpurpose' ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Echo a bulleted list of field labels.
	 *
	 * @param array<int,string> $labels Labels (schema labels are pre-escaped).
	 */
	private static function print_label_list( array $labels ): void {
		echo '<ul class="linkpurpose-notice-list">';
		foreach ( $labels as $label ) {
			echo '<li>' . wp_kses( $label, array() ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Schema labels for a list of keys (keys the schema no longer knows are
	 * shown raw).
	 *
	 * @param array<int,mixed> $keys Storage keys.
	 * @return array<int,string>
	 */
	private static function labels_for( array $keys ): array {
		$labels = array();
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			$def      = Schema::field( $key );
			$labels[] = ( null !== $def && ! empty( $def['label'] ) ) ? (string) $def['label'] : $key;
		}
		return $labels;
	}

	/**
	 * Read and delete the current user's notice payload.
	 *
	 * @return array<string,mixed>
	 */
	private static function take_notice(): array {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$stored = get_transient( $key );
		if ( false !== $stored ) {
			delete_transient( $key );
		}
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * The backfill status wrapper. Always emitted (even idle) so the
	 * heartbeat client has a stable target; `data-running` tells the client
	 * whether to keep polling.
	 */
	private static function render_backfill_panel(): void {
		$state = NetworkDefaultsWorker::get_state();
		printf(
			'<div id="linkpurpose-backfill-panel" data-running="%s">%s</div>',
			'running' === $state['status'] ? '1' : '0',
			self::render_backfill_panel_inner() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per field inside the helper.
		);
	}

	/**
	 * Panel contents: one inline notice, or '' when idle. Public so the
	 * heartbeat handler renders the same markup.
	 *
	 * `.inline` keeps the notice where it is: WP core's admin script moves
	 * any other `.notice` under the page `<h1>`, which would leave the
	 * wrapper empty for the next heartbeat refill.
	 */
	public static function render_backfill_panel_inner(): string {
		$state = NetworkDefaultsWorker::get_state();
		if ( 'idle' === $state['status'] ) {
			return '';
		}
		$total     = max( 0, (int) $state['total'] );
		$processed = max( 0, min( $total, (int) $state['processed'] ) );
		$class     = 'notice notice-info inline';
		if ( 'completed' === $state['status'] ) {
			$class = 'notice notice-success is-dismissible inline';
		} elseif ( 'failed' === $state['status'] ) {
			$class = 'notice notice-error is-dismissible inline';
		}
		ob_start();
		?>
		<div class="<?php echo esc_attr( $class ); ?>">
			<p>
				<strong><?php echo esc_html( self::status_label( (string) $state['status'] ) ); ?></strong>
				<?php
				echo ' ' . esc_html(
					sprintf(
						/* translators: 1: sites visited, 2: total sites, 3: sites updated */
						__( '%1$s of %2$s sites visited, %3$s updated.', 'linkpurpose' ),
						number_format_i18n( $processed ),
						number_format_i18n( $total ),
						number_format_i18n( (int) $state['updated'] )
					)
				);
				$eta = self::eta( $state, $processed, $total );
				if ( '' !== $eta ) {
					/* translators: %s: human-readable duration */
					echo ' ' . esc_html( sprintf( __( 'About %s remaining.', 'linkpurpose' ), $eta ) );
				}
				?>
			</p>
			<?php if ( ! empty( $state['errors'] ) ) : ?>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of errors */
							__( '%s error(s) recorded; see the server error log.', 'linkpurpose' ),
							number_format_i18n( count( (array) $state['errors'] ) )
						)
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( 'running' === $state['status'] ) : ?>
				<p class="description"><em><?php esc_html_e( 'Saving the form now restarts propagation with the new settings.', 'linkpurpose' ); ?></em></p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Human time estimate for the rest of a running walk, or ''.
	 *
	 * @param array<string,mixed> $state     Worker state.
	 * @param int                 $processed Sites visited.
	 * @param int                 $total     Sites in the walk.
	 */
	private static function eta( array $state, int $processed, int $total ): string {
		$elapsed   = (int) $state['updated_at'] - (int) $state['job_started_at'];
		$remaining = $total - $processed;
		if ( 'running' !== $state['status'] || $processed <= 0 || $remaining <= 0 || $elapsed <= 0 ) {
			return '';
		}
		$seconds = (int) round( $remaining * $elapsed / $processed );
		return $seconds > 0 ? human_time_diff( time(), time() + $seconds ) : '';
	}

	/**
	 * Status → label.
	 *
	 * @param string $status Worker status.
	 */
	private static function status_label( string $status ): string {
		switch ( $status ) {
			case 'running':
				return __( 'Propagating network defaults to existing sites…', 'linkpurpose' );
			case 'completed':
				return __( 'Network defaults propagated.', 'linkpurpose' );
			case 'failed':
				return __( 'Network defaults propagation failed.', 'linkpurpose' );
			default:
				return __( 'Network defaults propagation idle.', 'linkpurpose' );
		}
	}

	/**
	 * `heartbeat_received`: ship refreshed panel HTML to a client that asked.
	 *
	 * @param array<string,mixed> $response Heartbeat response.
	 * @param array<string,mixed> $data     Client request data.
	 * @return array<string,mixed>
	 */
	public static function heartbeat_received( $response, $data ) {
		$response = is_array( $response ) ? $response : array();
		if ( empty( $data[ self::HEARTBEAT_REQUEST ] ) || ! current_user_can( self::CAPABILITY ) ) {
			return $response;
		}
		$response[ self::HEARTBEAT_PANEL ]   = self::render_backfill_panel_inner();
		$response[ self::HEARTBEAT_RUNNING ] = ( 'running' === NetworkDefaultsWorker::get_state()['status'] );
		return $response;
	}

	/* ===== Saving ===== */

	/**
	 * `admin_post_<SAVE_ACTION>`: capability + nonce, save, redirect.
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'linkpurpose' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every element is sanitized per field by NetworkSettingsValidator.
		$result = self::save_from_post( (array) wp_unslash( $_POST ) );

		if ( ! empty( $result['orphans'] ) ) {
			set_transient( self::NOTICE_TRANSIENT . get_current_user_id(), array( 'orphans' => $result['orphans'] ), MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( add_query_arg( 'updated', $result['saved'] ? '1' : '0', self::url() ) );
		exit;
	}

	/**
	 * Validate, orphan-check, store, record history and queue the backfill.
	 * Split from {@see handle_save()} so tests can drive it without nonce,
	 * redirect and exit plumbing.
	 *
	 * The previous storage is snapshotted BEFORE the write: the worker's
	 * "still tracking" rule needs the value sites may currently hold.
	 *
	 * @param array<string,mixed> $post Unslashed POST.
	 * @return array{saved: bool, orphans: array<int,string>, dirty: array<string,array<string,mixed>>}
	 */
	public static function save_from_post( array $post ): array {
		$old       = Settings::network_storage();
		$validated = NetworkSettingsValidator::validate( $post );
		$new       = Settings::normalize_network_storage( $validated );

		$orphans = NetworkDefaultsWorker::detect_orphan_changed_keys( $old, $new );
		if ( ! empty( $orphans ) ) {
			return array(
				'saved'   => false,
				'orphans' => $orphans,
				'dirty'   => array(),
			);
		}

		update_site_option( Settings::NETWORK_OPTION, $new );

		/**
		 * Fires after the network defaults have been written. Lock changes
		 * take effect on every site at read time, so network-wide page
		 * caches can purge here.
		 *
		 * @param array{values: array<string,string>, modes: array<string,string>} $new Stored network defaults.
		 * @param array{values: array<string,string>, modes: array<string,string>} $old Previous network defaults.
		 */
		do_action( 'linkpurpose_network_settings_saved', $new, $old );

		$dirty = array();
		try {
			// History first: even if the enqueue below throws, the next save
			// still knows every value the network has held.
			NetworkDefaultsWorker::record_history( $old, $new );
			$dirty = NetworkDefaultsWorker::diff_dirty_keys( $old, $new );
			NetworkDefaultsWorker::enqueue( $dirty );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the defaults are already stored; surface the failed backfill queueing.
			error_log( '[Link Purpose] could not queue the network defaults backfill: ' . $e->getMessage() );
		}

		return array(
			'saved'   => true,
			'orphans' => array(),
			'dirty'   => $dirty,
		);
	}
}
