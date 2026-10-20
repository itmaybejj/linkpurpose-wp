<?php
/**
 * Backfill worker + new-site seeder.
 *
 * @package LinkPurpose
 */

use LinkPurpose\Form\NetworkDefaultsWorker as Worker;
use LinkPurpose\Form\SettingsPage;
use LinkPurpose\Form\SettingsValidator;
use LinkPurpose\Settings\Schema;
use LinkPurpose\Settings\Settings;

class Test_LinkPurpose_NetworkDefaultsWorker extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$this->wipe();
	}

	public function tear_down() {
		$this->wipe();
		parent::tear_down();
	}

	private function wipe(): void {
		delete_option( Settings::OPTION );
		delete_site_option( Settings::NETWORK_OPTION );
		delete_site_option( Worker::HISTORY_OPTION );
		Worker::reset();
	}

	private function blob( array $values, array $modes = array() ): array {
		return array(
			'values' => $values,
			'modes'  => $modes,
		);
	}

	private function entry( $old, string $new, bool $force = false ): array {
		return array(
			'old'   => $old,
			'new'   => $new,
			'force' => $force,
		);
	}

	/* ===== Seeder ===== */

	public function test_seed_values_takes_new_and_all_including_empty_checkbox_and_skips_lock() {
		$seed = Worker::seed_values(
			$this->blob(
				array(
					'roots'           => 'net',
					'hideIcon'        => '.h',
					'purposeExternal' => '',
					'ignore'          => '.i',
					'domain'          => 'x.org',
				),
				array(
					'roots'           => 'new',
					'hideIcon'        => 'all',
					'purposeExternal' => 'all',
					'ignore'          => 'lock',
					'suppressNoBreak' => 'all',
				)
			)
		);
		$this->assertSame(
			array(
				'roots'           => 'net',
				'hideIcon'        => '.h',
				'purposeExternal' => '',
			),
			$seed
		);
	}

	public function test_seed_current_blog_inserts_only_absent_keys_and_bypasses_the_site_validator() {
		update_option( Settings::OPTION, array( 'roots' => 'mine' ) );
		// Registered AFTER the fixture write: the Settings API filter would
		// otherwise expand that write into a full row.
		SettingsPage::register_setting();
		$this->assertNotFalse( has_filter( 'sanitize_option_' . Settings::OPTION, array( SettingsValidator::class, 'validate' ) ) );
		update_site_option(
			Settings::NETWORK_OPTION,
			$this->blob(
				array(
					'roots'           => 'net',
					'hideIcon'        => '.h',
					'purposeExternal' => '',
				),
				array(
					'roots'           => 'all',
					'hideIcon'        => 'new',
					'purposeExternal' => 'all',
				)
			)
		);

		$this->assertTrue( Worker::seed_current_blog() );
		$this->assertSame(
			array(
				'roots'           => 'mine',
				'hideIcon'        => '.h',
				'purposeExternal' => '',
			),
			get_option( Settings::OPTION ),
			'stored value kept, absent keys inserted, no other keys filled in'
		);
		$this->assertNotFalse( has_filter( 'sanitize_option_' . Settings::OPTION, array( SettingsValidator::class, 'validate' ) ), 'validator filter restored' );
		$this->assertFalse( Worker::seed_current_blog(), 'second pass is a no-op' );
	}

	public function test_seed_current_blog_noop_without_defaults() {
		$this->assertFalse( Worker::seed_current_blog() );
		$this->assertFalse( get_option( Settings::OPTION ) );
	}

	public function test_new_site_is_seeded_on_multisite() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'wp_initialize_site only fires on multisite' );
		}
		update_site_option(
			Settings::NETWORK_OPTION,
			$this->blob(
				array(
					'roots'  => 'net',
					'ignore' => '.locked',
				),
				array(
					'roots'  => 'new',
					'ignore' => 'lock',
				)
			)
		);
		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );
		try {
			$this->assertSame( array( 'roots' => 'net' ), get_option( Settings::OPTION ) );
		} finally {
			restore_current_blog();
		}
	}

	/* ===== Orphans ===== */

	public function test_orphans_are_changed_non_default_values_without_a_mode() {
		$orphans = Worker::detect_orphan_changed_keys(
			$this->blob( array() ),
			$this->blob(
				array(
					'roots'                   => 'main',
					'purposeExternal'         => '',
					'purposeExternalIconType' => 'classes',
					'purposeMail'             => '1',
					'hideIcon'                => '',
				)
			)
		);
		$this->assertSame( array( 'roots', 'purposeExternal', 'purposeExternalIconType' ), $orphans, 'unchecking a default-on box IS an orphan; defaults are not' );
	}

	public function test_orphans_skip_unchanged_and_propagating_keys() {
		$orphans = Worker::detect_orphan_changed_keys(
			$this->blob( array( 'roots' => 'main' ) ),
			$this->blob(
				array(
					'roots'    => 'main',
					'hideIcon' => '.x',
					'ignore'   => '.i',
				),
				array(
					'hideIcon' => 'new',
					'ignore'   => 'lock',
				)
			)
		);
		$this->assertSame( array(), $orphans );
	}

	/* ===== Diff ===== */

	public function test_diff_detects_changes_under_all_and_emits_lock_as_force() {
		$dirty = Worker::diff_dirty_keys(
			$this->blob(
				array(
					'roots'    => 'a',
					'hideIcon' => 'h',
				),
				array(
					'roots'    => 'all',
					'hideIcon' => 'all',
				)
			),
			$this->blob(
				array(
					'roots'           => 'b',
					'hideIcon'        => 'h',
					'ignore'          => '.i',
					'purposeExternal' => '',
					'domain'          => 'x.org',
				),
				array(
					'roots'           => 'all',
					'hideIcon'        => 'all',
					'ignore'          => 'lock',
					'purposeExternal' => 'all',
					'domain'          => 'new',
				)
			)
		);
		$this->assertSame(
			array(
				'roots'           => $this->entry( 'a', 'b' ),
				'ignore'          => $this->entry( null, '.i', true ),
				'purposeExternal' => $this->entry( null, '' ),
			),
			$dirty,
			'unchanged all-mode key and new-mode key are not dirty; empty checkbox is a value'
		);
	}

	public function test_diff_ignores_keys_leaving_all_mode() {
		$dirty = Worker::diff_dirty_keys(
			$this->blob( array( 'roots' => 'a' ), array( 'roots' => 'all' ) ),
			$this->blob( array( 'roots' => 'a' ), array() )
		);
		$this->assertSame( array(), $dirty );
	}

	public function test_diff_treats_a_mode_change_with_same_value_as_dirty() {
		$to_lock = Worker::diff_dirty_keys(
			$this->blob( array( 'roots' => 'a' ), array( 'roots' => 'all' ) ),
			$this->blob( array( 'roots' => 'a' ), array( 'roots' => 'lock' ) )
		);
		$this->assertSame( array( 'roots' => $this->entry( 'a', 'a', true ) ), $to_lock );

		$to_all = Worker::diff_dirty_keys(
			$this->blob( array( 'roots' => 'a' ), array( 'roots' => 'lock' ) ),
			$this->blob( array( 'roots' => 'a' ), array( 'roots' => 'all' ) )
		);
		$this->assertSame( array( 'roots' => $this->entry( 'a', 'a' ) ), $to_all );
	}

	/* ===== History ===== */

	public function test_record_history_appends_old_and_new_regardless_of_mode() {
		Worker::record_history(
			$this->blob( array( 'roots' => 'a' ) ),
			$this->blob(
				array(
					'roots'    => 'b',
					'hideIcon' => 'h',
				),
				array( 'hideIcon' => 'new' )
			)
		);
		$this->assertSame(
			array(
				'roots'    => array( 'a', 'b' ),
				'hideIcon' => array( 'h' ),
			),
			Worker::get_history()
		);

		Worker::record_history(
			$this->blob( array( 'roots' => 'b' ) ),
			$this->blob( array( 'roots' => 'a' ) )
		);
		$this->assertSame( array( 'b', 'a' ), Worker::get_history()['roots'], 're-published value moves to the recent end' );
	}

	public function test_record_history_skips_unchanged_keys_and_caps_per_key() {
		Worker::record_history( $this->blob( array( 'roots' => 'a' ) ), $this->blob( array( 'roots' => 'a' ) ) );
		$this->assertSame( array(), Worker::get_history() );

		$previous = null;
		for ( $i = 0; $i < 25; $i++ ) {
			$old = null === $previous ? array() : array( 'roots' => $previous );
			Worker::record_history( $this->blob( $old ), $this->blob( array( 'roots' => "v$i" ) ) );
			$previous = "v$i";
		}
		$trail = Worker::get_history()['roots'];
		$this->assertCount( Worker::MAX_HISTORY_PER_KEY, $trail );
		$this->assertSame( 'v24', end( $trail ) );
		$this->assertSame( 'v5', $trail[0] );
	}

	public function test_get_history_tolerates_corrupt_storage() {
		update_site_option( Worker::HISTORY_OPTION, 'junk' );
		$this->assertSame( array(), Worker::get_history() );
		update_site_option(
			Worker::HISTORY_OPTION,
			array(
				'roots'    => 'not a list',
				'hideIcon' => array( 'x', array( 'nested' ), 2 ),
				5          => array( 'y' ),
			)
		);
		$this->assertSame( array( 'hideIcon' => array( 'x', '2' ) ), Worker::get_history() );
	}

	public function test_history_survives_worker_reset() {
		Worker::record_history( $this->blob( array() ), $this->blob( array( 'roots' => 'a' ) ) );
		Worker::enqueue( array( 'roots' => $this->entry( null, 'a' ) ) );
		Worker::reset();
		$this->assertSame( 'idle', Worker::get_state()['status'] );
		$this->assertSame( array( 'roots' => array( 'a' ) ), Worker::get_history() );
	}

	/* ===== Enqueue ===== */

	public function test_enqueue_with_nothing_dirty_is_a_noop() {
		Worker::enqueue( array() );
		$this->assertSame( 'idle', Worker::get_state()['status'] );
		$this->assertFalse( wp_next_scheduled( Worker::CRON_HOOK ) );
	}

	public function test_enqueue_starts_a_run_with_olds_from_history_and_the_previous_value() {
		update_site_option( Worker::HISTORY_OPTION, array( 'roots' => array( 'x', 'y', 'b' ) ) );
		Worker::enqueue( array( 'roots' => $this->entry( 'a', 'b' ) ) );

		$state = Worker::get_state();
		$this->assertSame( 'running', $state['status'] );
		$this->assertSame(
			array(
				'olds'  => array( 'x', 'y', 'a' ),
				'new'   => 'b',
				'force' => false,
			),
			$state['dirty']['roots'],
			'history first, then the previous value; the value being published is excluded'
		);
		$this->assertSame( Schema::defaults(), $state['hardcoded'] );
		$this->assertSame( 0, $state['cursor'] );
		$this->assertGreaterThan( 0, $state['started_at'] );
		$this->assertNotFalse( wp_next_scheduled( Worker::CRON_HOOK ) );
	}

	public function test_enqueue_without_a_previous_value_has_an_empty_trail() {
		Worker::enqueue( array( 'roots' => $this->entry( null, 'b' ) ) );
		$this->assertSame( array(), Worker::get_state()['dirty']['roots']['olds'] );
	}

	public function test_enqueue_mid_run_coalesces_resets_the_cursor_and_latches_force() {
		Worker::enqueue( array( 'roots' => $this->entry( 'a', 'b' ) ) );
		$state              = Worker::get_state();
		$state['cursor']    = 5;
		$state['processed'] = 3;
		update_site_option( Worker::STATE_OPTION, $state );

		Worker::enqueue(
			array(
				'roots'    => $this->entry( 'b', 'c', true ),
				'hideIcon' => $this->entry( null, 'h' ),
			)
		);
		$state = Worker::get_state();
		$this->assertSame( 'running', $state['status'] );
		$this->assertSame( 0, $state['cursor'] );
		$this->assertSame( 0, $state['processed'] );
		$this->assertSame(
			array(
				'olds'  => array( 'a', 'b' ),
				'new'   => 'c',
				'force' => true,
			),
			$state['dirty']['roots']
		);
		$this->assertSame(
			array(
				'olds'  => array(),
				'new'   => 'h',
				'force' => false,
			),
			$state['dirty']['hideIcon']
		);

		Worker::enqueue( array( 'roots' => $this->entry( 'c', 'd' ) ) );
		$this->assertTrue( Worker::get_state()['dirty']['roots']['force'], 'a latched lock is not undone by a later all-mode save' );
	}

	public function test_enqueue_mid_run_dedups_and_caps_the_trail() {
		Worker::enqueue( array( 'roots' => $this->entry( 'v0', 'v1' ) ) );
		for ( $i = 1; $i < 30; $i++ ) {
			Worker::enqueue( array( 'roots' => $this->entry( "v$i", 'v' . ( $i + 1 ) ) ) );
		}
		Worker::enqueue( array( 'roots' => $this->entry( 'v30', 'v31' ) ) );
		$olds = Worker::get_state()['dirty']['roots']['olds'];
		$this->assertCount( Worker::MAX_HISTORY_PER_KEY, $olds );
		$this->assertSame( 'v11', $olds[0], 'oldest dropped first' );
		$this->assertSame( 'v30', end( $olds ) );

		Worker::enqueue( array( 'roots' => $this->entry( 'v20', 'v32' ) ) );
		$olds = Worker::get_state()['dirty']['roots']['olds'];
		$this->assertCount( Worker::MAX_HISTORY_PER_KEY, $olds );
		$this->assertSame( count( $olds ), count( array_unique( $olds ) ), 'a re-published value is not duplicated' );
		$this->assertContains( 'v20', $olds );
	}

	/* ===== Apply ===== */

	private function dirty_roots( array $olds, string $new, bool $force = false ): array {
		return array(
			'roots' => array(
				'olds'  => $olds,
				'new'   => $new,
				'force' => $force,
			),
		);
	}

	public function test_apply_writes_absent_old_and_default_values() {
		$hardcoded = Schema::defaults();

		$this->assertTrue( Worker::apply_dirty( $this->dirty_roots( array( 'a' ), 'b' ), $hardcoded ) );
		$this->assertSame( array( 'roots' => 'b' ), get_option( Settings::OPTION ), 'absent key inserted' );

		update_option( Settings::OPTION, array( 'roots' => 'a' ) );
		$this->assertTrue( Worker::apply_dirty( $this->dirty_roots( array( 'x', 'a' ), 'b' ), $hardcoded ) );
		$this->assertSame( 'b', get_option( Settings::OPTION )['roots'], 'value in the olds trail overwritten' );

		update_option( Settings::OPTION, array( 'roots' => '' ) );
		$this->assertTrue( Worker::apply_dirty( $this->dirty_roots( array(), 'b' ), $hardcoded ) );
		$this->assertSame( 'b', get_option( Settings::OPTION )['roots'], 'schema default overwritten' );
	}

	public function test_apply_preserves_customized_values_and_short_circuits_equal_ones() {
		update_option( Settings::OPTION, array( 'roots' => 'custom' ) );
		$this->assertFalse( Worker::apply_dirty( $this->dirty_roots( array( 'a' ), 'b' ), Schema::defaults() ) );
		$this->assertSame( 'custom', get_option( Settings::OPTION )['roots'] );

		update_option( Settings::OPTION, array( 'roots' => 'b' ) );
		$this->assertFalse( Worker::apply_dirty( $this->dirty_roots( array( 'a' ), 'b' ), Schema::defaults() ) );
	}

	public function test_apply_force_overwrites_a_customized_value() {
		update_option( Settings::OPTION, array( 'roots' => 'custom' ) );
		$this->assertTrue( Worker::apply_dirty( $this->dirty_roots( array(), 'b', true ), Schema::defaults() ) );
		$this->assertSame( 'b', get_option( Settings::OPTION )['roots'] );
	}

	public function test_apply_handles_checkbox_off_values_and_legacy_booleans() {
		update_option( Settings::OPTION, array( 'purposeExternal' => true ) );
		$dirty = array(
			'purposeExternal' => array(
				'olds'  => array(),
				'new'   => '',
				'force' => false,
			),
		);
		$this->assertTrue( Worker::apply_dirty( $dirty, Schema::defaults() ), 'true normalizes to "1", the schema default, so it is tracking' );
		$this->assertSame( '', get_option( Settings::OPTION )['purposeExternal'] );
		$this->assertSame( '', Settings::get( 'purposeExternal' ), 'the read pipeline sees "off", not the default' );
	}

	public function test_apply_fires_the_validated_action_for_cache_purges() {
		$seen = array();
		add_action(
			'linkpurpose_settings_validated',
			static function ( $settings ) use ( &$seen ) {
				$seen[] = $settings;
			}
		);
		Worker::apply_dirty( $this->dirty_roots( array(), 'b' ), Schema::defaults() );
		$this->assertSame( array( array( 'roots' => 'b' ) ), $seen );
	}

	/* ===== Tick ===== */

	public function test_tick_when_idle_drops_the_queued_event() {
		wp_schedule_single_event( time() + 60, Worker::CRON_HOOK );
		Worker::tick();
		$this->assertSame( 'idle', Worker::get_state()['status'] );
		$this->assertFalse( wp_next_scheduled( Worker::CRON_HOOK ) );
	}

	public function test_tick_completes_immediately_on_single_site() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'single-site only' );
		}
		Worker::enqueue( array( 'roots' => $this->entry( null, 'b' ) ) );
		Worker::tick();
		$state = Worker::get_state();
		$this->assertSame( 'completed', $state['status'] );
		$this->assertSame( 0, $state['total'] );
		$this->assertFalse( wp_next_scheduled( Worker::CRON_HOOK ) );
	}

	public function test_tick_walks_every_site_on_multisite() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'tick() walks $wpdb->blogs' );
		}
		$tracking = self::factory()->blog->create();
		$custom   = self::factory()->blog->create();
		switch_to_blog( $custom );
		update_option( Settings::OPTION, array( 'roots' => 'custom' ) );
		restore_current_blog();
		update_option( Settings::OPTION, array( 'roots' => '' ) );

		Worker::enqueue( array( 'roots' => $this->entry( null, 'net' ) ) );
		$this->assertGreaterThanOrEqual( 3, Worker::get_state()['total'] );

		for ( $i = 0; $i < 10 && 'running' === Worker::get_state()['status']; $i++ ) {
			Worker::tick();
		}
		$state = Worker::get_state();
		$this->assertSame( 'completed', $state['status'], wp_json_encode( $state['errors'] ) );
		$this->assertSame( $state['total'], $state['processed'] );
		$this->assertGreaterThanOrEqual( 2, $state['updated'] );
		$this->assertFalse( wp_next_scheduled( Worker::CRON_HOOK ) );

		$this->assertSame( 'net', get_option( Settings::OPTION )['roots'], 'main site on the default was updated' );
		switch_to_blog( $tracking );
		$this->assertSame( 'net', get_option( Settings::OPTION )['roots'], 'site without a value was updated' );
		restore_current_blog();
		switch_to_blog( $custom );
		$this->assertSame( 'custom', get_option( Settings::OPTION )['roots'], 'customized site left alone' );
		restore_current_blog();
	}
}
