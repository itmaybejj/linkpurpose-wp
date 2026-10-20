<?php
/**
 * Network defaults validator: every key written, modes filtered by type.
 *
 * @package LinkPurpose
 */

use LinkPurpose\Form\NetworkSettingsValidator;
use LinkPurpose\Form\SettingsContext;
use LinkPurpose\Settings\Schema;
use LinkPurpose\Settings\Settings;

class Test_LinkPurpose_NetworkSettingsValidator extends WP_UnitTestCase {

	private function post( array $values, array $modes ): array {
		return array(
			SettingsContext::NETWORK_VALUES_PREFIX => $values,
			SettingsContext::NETWORK_MODES_PREFIX  => $modes,
		);
	}

	public function test_writes_every_schema_key_and_sanitizes_per_field() {
		$out = NetworkSettingsValidator::validate(
			$this->post(
				array(
					'roots'                   => " main,\n #footer ",
					'purposeExternalIconType' => 'forged',
					'purposeExternalClass'    => 'one two',
					'bogus'                   => 'x',
				),
				array(
					'roots'                   => 'all',
					'purposeExternalIconType' => 'lock',
					'bogus'                   => 'lock',
					'hideIcon'                => 'nonsense',
					'purposeMail'             => '',
				)
			)
		);
		$this->assertSame( Schema::keys(), array_keys( $out['values'] ), 'every key, in schema order, nothing else' );
		$this->assertSame( 'main, #footer', $out['values']['roots'] );
		$this->assertSame( 'html', $out['values']['purposeExternalIconType'], 'forged select falls back to the default' );
		$this->assertSame( 'one', $out['values']['purposeExternalClass'] );
		$this->assertSame( '', $out['values']['purposeMail'], 'absent checkbox stores as empty string' );
		$this->assertSame(
			array(
				'roots'                   => 'all',
				'purposeExternalIconType' => 'lock',
			),
			$out['modes'],
			'unknown keys, unknown modes and "none" are dropped'
		);
		$this->assertSame( array(), $out['inert'] );
	}

	public function test_mode_dropped_for_empty_text_but_kept_for_unchecked_checkbox() {
		$out = NetworkSettingsValidator::validate(
			$this->post(
				array( 'domain' => '' ),
				array(
					'roots'                       => 'lock',
					'domain'                      => 'all',
					'purposeExternal'             => 'lock',
					'purposeExternalIconPosition' => 'all',
				)
			)
		);
		$this->assertSame( '', $out['values']['purposeExternal'] );
		$this->assertSame(
			array(
				'purposeExternal'             => 'lock',
				'purposeExternalIconPosition' => 'all',
			),
			$out['modes']
		);
		$this->assertSame( array( 'roots', 'domain' ), $out['inert'] );
	}

	public function test_non_array_buckets_are_ignored() {
		$out = NetworkSettingsValidator::validate(
			array(
				SettingsContext::NETWORK_VALUES_PREFIX => 'string',
				SettingsContext::NETWORK_MODES_PREFIX  => 42,
			)
		);
		$this->assertSame( array(), $out['modes'] );
		$this->assertSame( '', $out['values']['roots'] );
		$this->assertSame( '', $out['values']['purposeExternal'] );
		$this->assertSame( 'beforeend', $out['values']['purposeExternalIconPosition'] );
	}

	public function test_is_text_field() {
		$this->assertTrue( NetworkSettingsValidator::is_text_field( 'roots' ) );
		$this->assertTrue( NetworkSettingsValidator::is_text_field( 'purposeExternalMessage' ) );
		$this->assertFalse( NetworkSettingsValidator::is_text_field( 'purposeExternal' ) );
		$this->assertFalse( NetworkSettingsValidator::is_text_field( 'purposeExternalIconType' ) );
		$this->assertFalse( NetworkSettingsValidator::is_text_field( 'nope' ) );
	}

	public function test_output_round_trips_through_the_storage_normalizer() {
		$out        = NetworkSettingsValidator::validate(
			$this->post(
				array( 'roots' => 'main' ),
				array(
					'roots'    => 'all',
					'hideIcon' => 'lock',
				)
			)
		);
		$normalized = Settings::normalize_network_storage( $out );
		$this->assertSame( $out['values'], $normalized['values'] );
		$this->assertSame( array( 'roots' => 'all' ), $normalized['modes'] );
		$this->assertArrayNotHasKey( 'inert', $normalized );
	}
}
