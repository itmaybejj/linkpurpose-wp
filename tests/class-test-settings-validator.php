<?php
/**
 * The WP Settings API sanitize callback: shape of the stored option and
 * server-side enforcement of network locks.
 *
 * @package LinkPurpose
 */

use LinkPurpose\Form\SettingsValidator;
use LinkPurpose\Settings\Schema;
use LinkPurpose\Settings\Settings;

class Test_LinkPurpose_SettingsValidator extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_site_option( Settings::NETWORK_OPTION );
	}

	public function tear_down() {
		delete_option( Settings::OPTION );
		delete_site_option( Settings::NETWORK_OPTION );
		parent::tear_down();
	}

	public function test_output_contains_every_key_and_nothing_else() {
		$out = SettingsValidator::validate( array( 'roots' => 'main', 'bogus' => 'x' ) );
		$this->assertSame( Schema::keys(), array_keys( $out ) );
		$this->assertArrayNotHasKey( 'bogus', $out );
		$this->assertSame( 'main', $out['roots'] );
	}

	public function test_absent_checkbox_stores_empty_string() {
		// A real form POST omits unchecked boxes: purposeExternal absent → ''.
		$out = SettingsValidator::validate( array( 'purposeMail' => '1' ) );
		$this->assertSame( '', $out['purposeExternal'] );
		$this->assertSame( '1', $out['purposeMail'] );
	}

	public function test_non_array_input_yields_defaults_shape() {
		$out = SettingsValidator::validate( 'garbage' );
		$this->assertSame( Schema::keys(), array_keys( $out ) );
		$this->assertSame( '', $out['purposeExternal'] );
	}

	public function test_locked_key_is_coerced_to_network_value() {
		update_site_option(
			Settings::NETWORK_OPTION,
			array(
				'values' => array(
					'roots'           => '#network-main',
					'purposeExternal' => '',
				),
				'modes'  => array(
					'roots'           => 'lock',
					'purposeExternal' => 'lock',
				),
			)
		);
		$out = SettingsValidator::validate(
			array(
				'roots'           => 'forged',
				'purposeExternal' => '1',
			)
		);
		$this->assertSame( '#network-main', $out['roots'] );
		$this->assertSame( '', $out['purposeExternal'], 'locked-off checkbox stays off' );
	}

	public function test_unlocked_network_value_does_not_override_post() {
		update_site_option(
			Settings::NETWORK_OPTION,
			array(
				'values' => array( 'roots' => '#network-main' ),
				'modes'  => array( 'roots' => 'all' ),
			)
		);
		$out = SettingsValidator::validate( array( 'roots' => 'main' ) );
		$this->assertSame( 'main', $out['roots'] );
	}

	public function test_validated_action_fires_with_sanitized_settings() {
		$seen = null;
		add_action(
			'linkpurpose_settings_validated',
			static function ( $settings ) use ( &$seen ) {
				$seen = $settings;
			}
		);
		SettingsValidator::validate( array( 'domain' => 'a.net' ) );
		$this->assertIsArray( $seen );
		$this->assertSame( 'a.net', $seen['domain'] );
	}

	public function test_round_trip_through_update_option_uses_the_callback() {
		// register_setting() attaches the callback on admin_init; emulate.
		\LinkPurpose\Form\SettingsPage::register_setting();
		update_option( Settings::OPTION, array( 'roots' => "main,\n footer", 'junk' => 1 ) );
		$stored = get_option( Settings::OPTION );
		$this->assertSame( 'main, footer', $stored['roots'] );
		$this->assertArrayNotHasKey( 'junk', $stored );
		$this->assertSame( Schema::keys(), array_keys( $stored ) );
	}
}
