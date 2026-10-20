<?php
/**
 * Read pipeline: defaults, stored values, network lock overlay.
 *
 * @package LinkPurpose
 */

use LinkPurpose\Settings\Schema;
use LinkPurpose\Settings\Settings;

class Test_LinkPurpose_SettingsRead extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_site_option( Settings::NETWORK_OPTION );
	}

	public function tear_down() {
		remove_all_filters( 'linkpurpose_settings' );
		delete_option( Settings::OPTION );
		delete_site_option( Settings::NETWORK_OPTION );
		parent::tear_down();
	}

	public function test_empty_storage_yields_schema_defaults() {
		$this->assertSame( Schema::defaults(), Settings::all() );
		$this->assertSame( '1', Settings::get( 'purposeExternal' ) );
		$this->assertSame( 'html', Settings::get( 'purposeExternalIconType' ) );
	}

	public function test_stored_value_wins_including_empty_string() {
		update_option(
			Settings::OPTION,
			array(
				'purposeExternal' => '',
				'roots'           => 'main',
			)
		);
		$all = Settings::all();
		$this->assertSame( '', $all['purposeExternal'], 'unchecked checkbox must not fall back to default 1' );
		$this->assertSame( 'main', $all['roots'] );
		$this->assertSame( '1', $all['purposeMail'], 'absent key falls back to default' );
	}

	public function test_unknown_stored_keys_are_not_exposed() {
		update_option( Settings::OPTION, array( 'legacy' => 'x' ) );
		$this->assertArrayNotHasKey( 'legacy', Settings::all() );
	}

	public function test_corrupt_option_is_treated_as_empty() {
		update_option( Settings::OPTION, 'not-an-array' );
		$this->assertSame( Schema::defaults(), Settings::all() );
		$this->assertSame( '', Settings::raw( 'roots' ) );
	}

	public function test_raw_returns_empty_for_unset_key() {
		$this->assertSame( '', Settings::raw( 'roots' ) );
		update_option( Settings::OPTION, array( 'roots' => 'main', 'noIconOnImages' => true ) );
		$this->assertSame( 'main', Settings::raw( 'roots' ) );
		$this->assertSame( '1', Settings::raw( 'noIconOnImages' ), 'booleans normalize to the string shape' );
	}

	public function test_lock_overlays_site_value() {
		update_option( Settings::OPTION, array( 'roots' => 'site' ) );
		update_site_option(
			Settings::NETWORK_OPTION,
			array(
				'values' => array( 'roots' => 'network' ),
				'modes'  => array( 'roots' => 'lock' ),
			)
		);
		$this->assertSame( 'network', Settings::get( 'roots' ) );
		$this->assertTrue( Settings::is_locked( 'roots' ) );
		$this->assertSame( 'lock', Settings::network_mode( 'roots' ) );
	}

	public function test_non_lock_modes_do_not_overlay_at_read_time() {
		update_option( Settings::OPTION, array( 'roots' => 'site' ) );
		foreach ( array( 'new', 'all' ) as $mode ) {
			update_site_option(
				Settings::NETWORK_OPTION,
				array(
					'values' => array( 'roots' => 'network' ),
					'modes'  => array( 'roots' => $mode ),
				)
			);
			$this->assertSame( 'site', Settings::get( 'roots' ), $mode );
			$this->assertFalse( Settings::is_locked( 'roots' ), $mode );
		}
	}

	public function test_lock_without_value_is_inert() {
		update_site_option(
			Settings::NETWORK_OPTION,
			array(
				'values' => array(),
				'modes'  => array( 'roots' => 'lock' ),
			)
		);
		$this->assertFalse( Settings::is_locked( 'roots' ) );
	}

	public function test_network_storage_normalization_drops_junk() {
		$normalized = Settings::normalize_network_storage(
			array(
				'values' => array(
					'roots'   => 'main',
					'unknown' => 'x',
					'hideIcon' => array( 'not scalar' ),
					'noIconOnImages' => true,
				),
				'modes'  => array(
					'roots'   => 'lock',
					'hideIcon' => 'bogus',
					'unknown' => 'lock',
				),
			)
		);
		$this->assertSame(
			array(
				'roots'          => 'main',
				'noIconOnImages' => '1',
			),
			$normalized['values']
		);
		$this->assertSame( array( 'roots' => 'lock' ), $normalized['modes'] );
	}

	public function test_settings_filter_runs_last() {
		add_filter(
			'linkpurpose_settings',
			static function ( array $settings ): array {
				$settings['roots'] = 'filtered';
				return $settings;
			}
		);
		$this->assertSame( 'filtered', Settings::get( 'roots' ) );
	}
}
