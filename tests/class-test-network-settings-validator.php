<?php
/**
 * Network defaults validator: every key written, modes kept for every type.
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
		$this->assertArrayNotHasKey( 'inert', $out, 'modes on empty text are no longer reported as inert' );
	}

	/**
	 * A blank text value is the instruction "use the plugin default", so its
	 * mode survives: `all` pushes sites back to the default, `lock` pins
	 * them there. Same rule as an unchecked checkbox propagating "off".
	 */
	public function test_modes_are_kept_for_empty_text_and_unchecked_checkbox() {
		$out = NetworkSettingsValidator::validate(
			$this->post(
				array( 'domain' => '' ),
				array(
					'roots'                       => 'lock',
					'domain'                      => 'all',
					'purposeExternalMessage'      => 'new',
					'purposeExternal'             => 'lock',
					'purposeExternalIconPosition' => 'all',
				)
			)
		);
		$this->assertSame( '', $out['values']['roots'] );
		$this->assertSame( '', $out['values']['domain'] );
		$this->assertSame( '', $out['values']['purposeExternalMessage'] );
		$this->assertSame( '', $out['values']['purposeExternal'] );
		$this->assertSame(
			array(
				'roots'                       => 'lock',
				'domain'                      => 'all',
				'purposeExternalMessage'      => 'new',
				'purposeExternal'             => 'lock',
				'purposeExternalIconPosition' => 'all',
			),
			$out['modes']
		);
	}

	public function test_every_mode_is_kept_on_a_blank_value_for_every_field() {
		$modes = array();
		foreach ( Schema::keys() as $i => $key ) {
			$modes[ $key ] = Settings::MODES[ $i % count( Settings::MODES ) ];
		}
		$out = NetworkSettingsValidator::validate( $this->post( array(), $modes ) );
		$this->assertSame( $modes, $out['modes'], 'no field type silently loses its mode' );
		foreach ( Schema::fields() as $key => $def ) {
			$expected = in_array( $def['type'], array( 'text', 'textarea', 'checkbox' ), true ) ? '' : (string) $def['default'];
			$this->assertSame( $expected, $out['values'][ $key ], $key );
		}
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
		$this->assertSame(
			array(
				'roots'    => 'all',
				'hideIcon' => 'lock',
			),
			$normalized['modes'],
			'a lock on a blank text value survives normalization (locked to the default)'
		);
	}
}
