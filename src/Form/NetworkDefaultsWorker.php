<?php
/**
 * New-site seeder and cron backfill worker for network defaults.
 *
 * @package LinkPurpose
 */

namespace LinkPurpose\Form;

use LinkPurpose\Installer;
use LinkPurpose\Settings\Schema;
use LinkPurpose\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Propagates `new` / `all` network defaults into per-site storage; the
 * `lock` mode needs no propagation because the read pipeline overlays it,
 * but a lock save force-writes the value so that unlocking later leaves
 * sites on the network value instead of a pre-lock customization.
 *
 * Model (ported from the Editoria11y plugin, single option blob):
 *
 *   - `new`  → seeded once into a site's option on `wp_initialize_site`.
 *   - `all`  → seeded at creation AND backfilled into existing sites whose
 *              stored value is "still tracking the network": absent, equal
 *              to ANY value the network ever published for that key, or
 *              equal to the schema default. Any other value is a per-site
 *              customization and is left alone.
 *   - `lock` → force-written to every site (and overlaid at read time).
 *
 * The "any value ever published" trail is the durable history option
 * ({@see record_history()}): a site that was seeded under "new sites
 * only" must still count as tracking when the key is later switched to
 * "all", and a small network finishes a run in one tick so the in-run
 * trail alone would be one entry deep.
 *
 * Run state lives in one network option: idle → running → completed |
 * failed. A save that lands mid-run COALESCES into the running job (the
 * just-replaced network value joins the key's `olds` trail, `new` is
 * updated, the cursor resets to 0 so visited sites are re-walked). Sites
 * already on the latest value short-circuit, so the re-walk is cheap.
 *
 * Ticks are self-rescheduling single cron events walking `$wpdb->blogs`
 * by `blog_id` cursor in batches. The `wp_cache_add` lock serializes
 * overlapping ticks when a persistent object cache is present; without
 * one WP-Cron's own `doing_cron` guard is the real serializer, and every
 * per-site write is idempotent so an overlap re-walks harmlessly.
 *
 * Writes bypass the per-site Settings API sanitize callback on purpose
 * (see {@see write_site_settings()}): the network validator already
 * sanitized the value, and the per-site validator would write every key
 * — turning a two-key seed into a full option row.
 */
final class NetworkDefaultsWorker {

	/** Network option: run state. */
	const STATE_OPTION = Installer::WORKER_STATE_OPTION;

	/** Network option: durable per-key value trail. */
	const HISTORY_OPTION = Installer::WORKER_HISTORY_OPTION;

	/** Cron hook. */
	const CRON_HOOK = Installer::WORKER_CRON_HOOK;

	/** Object-cache lock key / group serializing ticks. */
	const LOCK_KEY    = 'linkpurpose_network_backfill_lock';
	const CACHE_GROUP = 'linkpurpose';

	/** Sites processed per tick. */
	const BATCH_SIZE = 25;

	/** Per-blog errors retained in the state. */
	const MAX_ERRORS = 50;

	/** Cap on each key's `olds` trail and history trail (oldest dropped). */
	const MAX_HISTORY_PER_KEY = 20;

	/** Default minimum seconds between ticks. */
	const DEFAULT_MIN_GAP_SECONDS = 15;

	/** Register the cron tick and the new-site seeder. */
	public static function register(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'wp_initialize_site', array( __CLASS__, 'seed_new_site' ), 10, 1 );
	}

	/* ===== New-site seeder ===== */

	/**
	 * `wp_initialize_site` callback: seed `new` / `all` defaults into the
	 * new site's option.
	 *
	 * @param \WP_Site|object $new_site The site just created.
	 */
	public static function seed_new_site( $new_site ): void {
		$blog_id = ( is_object( $new_site ) && ! empty( $new_site->blog_id ) ) ? (int) $new_site->blog_id : 0;
		if ( $blog_id <= 0 || ! function_exists( 'switch_to_blog' ) ) {
			return;
		}
		switch_to_blog( $blog_id );
		try {
			self::seed_current_blog();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Seed the current blog: insert each `new` / `all` default where the
	 * key is absent. Never overwrites a stored value, '' included.
	 *
	 * @return bool Whether anything was written.
	 */
	public static function seed_current_blog(): bool {
		$seed = self::seed_values( Settings::network_storage() );
		if ( empty( $seed ) ) {
			return false;
		}
		$stored  = Settings::stored();
		$changed = false;
		foreach ( $seed as $key => $value ) {
			if ( array_key_exists( $key, $stored ) ) {
				continue;
			}
			$stored[ $key ] = $value;
			$changed        = true;
		}
		if ( $changed ) {
			self::write_site_settings( $stored );
		}
		return $changed;
	}

	/**
	 * `key => value` for every entry whose mode is `new` or `all`. '' is a
	 * value here (an unchecked checkbox default), which is why presence is
	 * tested with `array_key_exists`.
	 *
	 * @param array{values: array<string,mixed>, modes: array<string,string>} $storage Normalized network storage.
	 * @return array<string,string>
	 */
	public static function seed_values( array $storage ): array {
		$seed = array();
		foreach ( $storage['modes'] as $key => $mode ) {
			if ( ( 'new' === $mode || 'all' === $mode ) && array_key_exists( $key, $storage['values'] ) ) {
				$seed[ $key ] = (string) $storage['values'][ $key ];
			}
		}
		return $seed;
	}

	/* ===== Save-time analysis ===== */

	/**
	 * Keys whose value changed while their propagation dropdown says "no
	 * network default" — a change that would reach no site. The save
	 * handler rejects the save and lists them.
	 *
	 * A value equal to the schema default is never an orphan: choice fields
	 * have no blank option, so an untouched form posts the default for
	 * every select and default-on checkbox, and "default with no mode" is
	 * exactly the inert state. (Text fields default to '', so clearing one
	 * is covered by the same rule.)
	 *
	 * @param array{values: array<string,mixed>, modes: array<string,string>} $previous Previous storage.
	 * @param array{values: array<string,mixed>, modes: array<string,string>} $current  New storage.
	 * @return array<int,string>
	 */
	public static function detect_orphan_changed_keys( array $previous, array $current ): array {
		$defaults = Schema::defaults();
		$orphans  = array();
		foreach ( $current['values'] as $key => $new_value ) {
			$new_value = (string) $new_value;
			if ( self::previous_value( $previous, (string) $key ) === $new_value ) {
				continue;
			}
			if ( in_array( $current['modes'][ $key ] ?? '', Settings::MODES, true ) ) {
				continue;
			}
			if ( ( $defaults[ $key ] ?? null ) === $new_value ) {
				continue;
			}
			$orphans[] = (string) $key;
		}
		return $orphans;
	}

	/**
	 * Keys the backfill must propagate after a save: mode `all` or `lock`
	 * with a value, unless both mode and value are unchanged (nothing new
	 * to write). `lock` entries carry `force`, which bypasses the tracking
	 * rule. A key switched away from `all` is NOT rolled back — the intent
	 * is "stop propagating", not "undo".
	 *
	 * @param array{values: array<string,mixed>, modes: array<string,string>} $previous Previous storage.
	 * @param array{values: array<string,mixed>, modes: array<string,string>} $current  New storage.
	 * @return array<string, array{old: string|null, new: string, force: bool}>
	 */
	public static function diff_dirty_keys( array $previous, array $current ): array {
		$dirty = array();
		foreach ( $current['modes'] as $key => $mode ) {
			if ( ( 'all' !== $mode && 'lock' !== $mode ) || ! array_key_exists( $key, $current['values'] ) ) {
				continue;
			}
			$new_value = (string) $current['values'][ $key ];
			$old_value = self::previous_value( $previous, (string) $key );
			if ( ( $previous['modes'][ $key ] ?? '' ) === $mode && $old_value === $new_value ) {
				continue;
			}
			$dirty[ $key ] = array(
				'old'   => $old_value,
				'new'   => $new_value,
				'force' => ( 'lock' === $mode ),
			);
		}
		return $dirty;
	}

	/**
	 * Previous network value as a string, or null when the key was unset.
	 *
	 * @param array{values: array<string,mixed>} $storage Storage blob.
	 * @param string                             $key     Storage key.
	 * @return string|null
	 */
	private static function previous_value( array $storage, string $key ) {
		return array_key_exists( $key, $storage['values'] ) ? (string) $storage['values'][ $key ] : null;
	}

	/* ===== Durable history ===== */

	/**
	 * Record every value that changed in this save — old and new, whatever
	 * the mode — so the backfill recognizes sites seeded under any earlier
	 * value. Re-publishing a value moves it to the recent end of the trail
	 * so the cap evicts the least recent first.
	 *
	 * @param array{values: array<string,mixed>, modes: array<string,string>} $previous Previous storage.
	 * @param array{values: array<string,mixed>, modes: array<string,string>} $current  New storage.
	 */
	public static function record_history( array $previous, array $current ): void {
		$history = self::get_history();
		$updated = $history;
		$keys    = array_unique( array_merge( array_keys( $previous['values'] ), array_keys( $current['values'] ) ) );
		foreach ( $keys as $key ) {
			$before = self::previous_value( $previous, (string) $key );
			$after  = self::previous_value( $current, (string) $key );
			if ( $before === $after ) {
				continue;
			}
			$trail = $updated[ $key ] ?? array();
			foreach ( array( $before, $after ) as $value ) {
				if ( null === $value ) {
					continue;
				}
				$position = array_search( $value, $trail, true );
				if ( false !== $position ) {
					array_splice( $trail, (int) $position, 1 );
				}
				$trail[] = $value;
			}
			$updated[ $key ] = array_slice( $trail, -self::MAX_HISTORY_PER_KEY );
		}
		if ( $updated !== $history ) {
			update_site_option( self::HISTORY_OPTION, $updated );
		}
	}

	/**
	 * The history, validated to `key => list<string>`; corrupt storage
	 * degrades to empty rather than breaking the save path.
	 *
	 * @return array<string, array<int,string>>
	 */
	public static function get_history(): array {
		$raw = get_site_option( self::HISTORY_OPTION, array() );
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( $raw as $key => $values ) {
			if ( ! is_string( $key ) || ! is_array( $values ) ) {
				continue;
			}
			$out[ $key ] = array_values( array_map( 'strval', array_filter( $values, 'is_scalar' ) ) );
		}
		return $out;
	}

	/* ===== Enqueue + state ===== */

	/**
	 * Start a run, or coalesce into the running one. No-op for an empty
	 * dirty set.
	 *
	 * @param array<string, array{old: string|null, new: string, force?: bool}> $dirty Output of {@see diff_dirty_keys()}.
	 */
	public static function enqueue( array $dirty ): void {
		if ( empty( $dirty ) ) {
			return;
		}
		$state   = self::get_state();
		$history = self::get_history();
		if ( 'running' !== $state['status'] ) {
			$state               = self::default_state();
			$state['status']     = 'running';
			$state['started_at'] = time();
		}
		$state['dirty']     = self::coalesce_dirty( (array) $state['dirty'], $dirty, $history );
		$state['hardcoded'] = Schema::defaults();
		$state              = self::reset_walk_progress( $state );
		self::save_state( $state );
		self::schedule_next_tick( 0 );
	}

	/**
	 * Merge save-handler `{old, new, force}` entries into the job's
	 * `{olds[], new, force}` shape. Each key's `olds` is the union of its
	 * in-run trail, the incoming `old` and the durable history, minus the
	 * value now being published. A lock latched mid-run stays latched.
	 *
	 * @param array<string, array<string,mixed>>                                $existing Running job's dirty map (empty for a fresh run).
	 * @param array<string, array{old: string|null, new: string, force?: bool}> $incoming This save's dirty map.
	 * @param array<string, array<int,string>>                                  $history  Durable history.
	 * @return array<string, array{olds: array<int,string>, new: string, force: bool}>
	 */
	private static function coalesce_dirty( array $existing, array $incoming, array $history ): array {
		foreach ( $incoming as $key => $entry ) {
			$current = is_array( $existing[ $key ] ?? null ) ? $existing[ $key ] : array();
			$olds    = is_array( $current['olds'] ?? null ) ? $current['olds'] : array();
			if ( isset( $entry['old'] ) ) {
				$olds[] = (string) $entry['old'];
			}
			$new_value        = (string) ( $entry['new'] ?? '' );
			$existing[ $key ] = array(
				'olds'  => self::fold_olds( $olds, $history[ $key ] ?? array(), $new_value ),
				'new'   => $new_value,
				'force' => ! empty( $current['force'] ) || ! empty( $entry['force'] ),
			);
		}
		return $existing;
	}

	/**
	 * Union history (oldest first) with the in-run trail, de-duplicated,
	 * minus the value being published, capped at MAX_HISTORY_PER_KEY.
	 *
	 * @param array<int,mixed>  $olds      In-run trail.
	 * @param array<int,string> $history   Durable trail for the key.
	 * @param string            $new_value Value being published.
	 * @return array<int,string>
	 */
	private static function fold_olds( array $olds, array $history, string $new_value ): array {
		$merged = array();
		foreach ( array_merge( $history, $olds ) as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = (string) $value;
			if ( $value === $new_value || in_array( $value, $merged, true ) ) {
				continue;
			}
			$merged[] = $value;
		}
		return array_slice( $merged, -self::MAX_HISTORY_PER_KEY );
	}

	/**
	 * Reset cursor and counters for a (re-)walk from the first site.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	private static function reset_walk_progress( array $state ): array {
		$now                     = time();
		$state['cursor']         = 0;
		$state['total']          = self::count_pending_sites( 0 );
		$state['processed']      = 0;
		$state['updated']        = 0;
		$state['job_started_at'] = $now;
		$state['updated_at']     = $now;
		return $state;
	}

	/**
	 * Current state merged over the default shape.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_state(): array {
		$state = get_site_option( self::STATE_OPTION, null );
		if ( ! is_array( $state ) ) {
			return self::default_state();
		}
		return wp_parse_args( $state, self::default_state() );
	}

	/**
	 * Idle state shape.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_state(): array {
		return array(
			'status'         => 'idle',
			'cursor'         => 0,
			'total'          => 0,
			'processed'      => 0,
			'updated'        => 0,
			'dirty'          => array(),
			'hardcoded'      => array(),
			'errors'         => array(),
			'started_at'     => 0,
			'job_started_at' => 0,
			'updated_at'     => 0,
		);
	}

	/** Drop the run state and any queued tick. History is kept. */
	public static function reset(): void {
		delete_site_option( self::STATE_OPTION );
		self::unschedule();
	}

	/* ===== Tick ===== */

	/**
	 * Cron callback: one batch. A throwing tick marks the run failed and
	 * stops rescheduling rather than breaking WP-Cron for everything else.
	 */
	public static function tick(): void {
		if ( ! wp_cache_add( self::LOCK_KEY, 1, self::CACHE_GROUP, 90 ) ) {
			return;
		}
		try {
			self::tick_locked();
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- one-shot diagnostic for a failed cron run.
			error_log( '[Link Purpose] network defaults backfill failed: ' . $e->getMessage() );
			self::fail_run( $e->getMessage() );
		} finally {
			wp_cache_delete( self::LOCK_KEY, self::CACHE_GROUP );
		}
	}

	/**
	 * Mark the run failed without letting a second failure escape.
	 *
	 * @param string $message Error message to record.
	 */
	private static function fail_run( string $message ): void {
		try {
			$state           = self::get_state();
			$state['status'] = 'failed';
			self::push_error( $state, 0, $message );
			self::save_state( $state );
			self::unschedule();
		} catch ( \Throwable $inner ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- nothing further to do.
			unset( $inner );
		}
	}

	/** Walk one batch, advance the cursor, reschedule or finish. */
	private static function tick_locked(): void {
		$state = self::get_state();
		if ( 'running' !== $state['status'] ) {
			self::unschedule();
			return;
		}
		$blog_ids = self::next_batch( (int) $state['cursor'], self::BATCH_SIZE );
		foreach ( $blog_ids as $blog_id ) {
			self::process_blog( (int) $blog_id, $state );
			++$state['processed'];
		}
		if ( ! empty( $blog_ids ) ) {
			$state['cursor'] = (int) end( $blog_ids );
		}
		if ( ! empty( $blog_ids ) && self::count_pending_sites( (int) $state['cursor'] ) > 0 ) {
			self::save_state( $state );
			self::schedule_next_tick( self::min_gap_seconds() );
			return;
		}
		$state['status'] = empty( $state['errors'] ) ? 'completed' : 'failed';
		self::save_state( $state );
		self::unschedule();
	}

	/**
	 * Apply the job to one site.
	 *
	 * @param int                 $blog_id Target site.
	 * @param array<string,mixed> $state   State, by reference.
	 */
	private static function process_blog( int $blog_id, array &$state ): void {
		switch_to_blog( $blog_id );
		try {
			if ( self::apply_dirty( (array) $state['dirty'], (array) $state['hardcoded'] ) ) {
				++$state['updated'];
			}
		} catch ( \Throwable $e ) {
			self::push_error( $state, $blog_id, $e->getMessage() );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Apply the job's dirty keys to the CURRENT blog's option.
	 *
	 * A key is written when the entry is forced (lock) or the site's value
	 * is still tracking the network: absent, in the `olds` trail, or equal
	 * to the schema default. A site already on the new value is skipped,
	 * which is what makes a corrective mid-run save cheap.
	 *
	 * Public for tests; production callers are {@see process_blog()} only.
	 *
	 * @param array<string, array<string,mixed>> $dirty     Job dirty map.
	 * @param array<string,string>               $hardcoded Schema defaults snapshot.
	 * @return bool Whether the option was written.
	 */
	public static function apply_dirty( array $dirty, array $hardcoded ): bool {
		if ( empty( $dirty ) ) {
			return false;
		}
		$stored  = Settings::stored();
		$changed = false;
		foreach ( $dirty as $key => $entry ) {
			$new_value  = (string) ( $entry['new'] ?? '' );
			$site_value = array_key_exists( $key, $stored ) ? self::to_string( $stored[ $key ] ) : null;
			if ( $site_value === $new_value || ! self::is_tracking( $entry, $site_value, $hardcoded[ $key ] ?? null ) ) {
				continue;
			}
			$stored[ $key ] = $new_value;
			$changed        = true;
		}
		if ( $changed ) {
			self::write_site_settings( $stored );
		}
		return $changed;
	}

	/**
	 * Whether a site value may be overwritten by the entry: the entry is
	 * forced (lock), or the site is still tracking the network — no value,
	 * a value the network once published, or the schema default.
	 *
	 * @param array<string,mixed> $entry      Job entry (`olds`, `new`, `force`).
	 * @param string|null         $site_value Site value, null when absent.
	 * @param string|null         $hardcoded  Schema default for the key.
	 */
	private static function is_tracking( array $entry, $site_value, $hardcoded ): bool {
		if ( null === $site_value || ! empty( $entry['force'] ) ) {
			return true;
		}
		return $hardcoded === $site_value
			|| in_array( $site_value, (array) ( $entry['olds'] ?? array() ), true );
	}

	/**
	 * Write the current blog's option without running the per-site
	 * Settings API sanitize callback (which would fill in every key and
	 * re-apply locks). Fires the same action the per-site save does so
	 * page-cache integrations purge the site.
	 *
	 * @param array<string,mixed> $stored Full option row to write.
	 */
	private static function write_site_settings( array $stored ): void {
		$hook     = 'sanitize_option_' . Settings::OPTION;
		$callback = array( SettingsValidator::class, 'validate' );
		$removed  = remove_filter( $hook, $callback );
		update_option( Settings::OPTION, $stored );
		if ( $removed ) {
			add_filter( $hook, $callback );
		}
		/** This action is documented in src/Form/SettingsValidator.php. */
		do_action( 'linkpurpose_settings_validated', $stored );
	}

	/* ===== Helpers ===== */

	/**
	 * Next batch of live blog ids strictly after the cursor. Direct SQL:
	 * WP_Site_Query has no "id greater than" predicate. Empty on
	 * single-site, where `$wpdb->blogs` is blank.
	 *
	 * @param int $cursor High-water blog_id already processed.
	 * @param int $limit  Batch size.
	 * @return array<int,int>
	 */
	private static function next_batch( int $cursor, int $limit ): array {
		if ( ! is_multisite() ) {
			return array();
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->blogs is a core table literal; a cursor walk must not be cached.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT blog_id FROM {$wpdb->blogs}
				WHERE blog_id > %d AND archived = '0' AND spam = '0' AND deleted = '0'
				ORDER BY blog_id ASC LIMIT %d",
				$cursor,
				$limit
			)
		);
		// phpcs:enable
		return array_map( 'intval', is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Live sites strictly after the cursor; 0 on single-site.
	 *
	 * @param int $cursor High-water blog_id already processed.
	 */
	private static function count_pending_sites( int $cursor ): int {
		if ( ! is_multisite() ) {
			return 0;
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see next_batch().
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->blogs}
				WHERE blog_id > %d AND archived = '0' AND spam = '0' AND deleted = '0'",
				$cursor
			)
		);
		// phpcs:enable
	}

	/**
	 * Append an error, keeping the most recent MAX_ERRORS.
	 *
	 * @param array<string,mixed> $state   State, by reference.
	 * @param int                 $blog_id Failing site (0 = the run itself).
	 * @param string              $message Error message.
	 */
	private static function push_error( array &$state, int $blog_id, string $message ): void {
		$state['errors'][] = array(
			'blog_id' => $blog_id,
			'message' => $message,
		);
		if ( count( $state['errors'] ) > self::MAX_ERRORS ) {
			array_shift( $state['errors'] );
		}
	}

	/** Minimum seconds between ticks; `LINKPURPOSE_WORKER_MIN_GAP_SECONDS` overrides. */
	private static function min_gap_seconds(): int {
		if ( defined( 'LINKPURPOSE_WORKER_MIN_GAP_SECONDS' ) ) {
			return max( 1, (int) constant( 'LINKPURPOSE_WORKER_MIN_GAP_SECONDS' ) );
		}
		return self::DEFAULT_MIN_GAP_SECONDS;
	}

	/**
	 * Queue the next tick. WP ignores a duplicate event within 10 minutes,
	 * so this is idempotent.
	 *
	 * @param int $delay_seconds Seconds from now.
	 */
	private static function schedule_next_tick( int $delay_seconds ): void {
		wp_schedule_single_event( time() + max( 0, $delay_seconds ), self::CRON_HOOK );
	}

	/** Drop every queued tick. */
	public static function unschedule(): void {
		wp_unschedule_hook( self::CRON_HOOK );
	}

	/**
	 * Persist state with a fresh `updated_at`.
	 *
	 * @param array<string,mixed> $state Full state.
	 */
	private static function save_state( array $state ): void {
		$state['updated_at'] = time();
		update_site_option( self::STATE_OPTION, $state );
	}

	/**
	 * Stored scalar → the string shape (`'1'`/`''` for booleans).
	 *
	 * @param mixed $value Stored value.
	 */
	private static function to_string( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		return is_scalar( $value ) ? (string) $value : '';
	}
}
