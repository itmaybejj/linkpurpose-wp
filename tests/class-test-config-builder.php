<?php
/**
 * JS payload shape.
 *
 * @package LinkPurpose
 */

use LinkPurpose\Frontend\ConfigBuilder;
use LinkPurpose\Settings\Schema;
use LinkPurpose\Settings\Settings;

class Test_LinkPurpose_ConfigBuilder extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_site_option( Settings::NETWORK_OPTION );
	}

	public function tear_down() {
		remove_all_filters( 'linkpurpose_js_settings' );
		delete_option( Settings::OPTION );
		delete_site_option( Settings::NETWORK_OPTION );
		parent::tear_down();
	}

	public function test_payload_carries_every_schema_key_plus_runtime_keys() {
		$payload = ConfigBuilder::build();
		foreach ( Schema::keys() as $key ) {
			$this->assertArrayHasKey( $key, $payload, $key );
		}
		$this->assertArrayHasKey( 'shadowCSS', $payload );
		$this->assertStringEndsWith( 'assets/lib/css/linkpurpose.css', $payload['shadowCSS'] );
		$this->assertSame( \LinkPurpose\Plugin::VERSION, $payload['version'] );
	}

	/**
	 * A value no site would store by default, which survives the field's
	 * sanitizer unchanged. Shared with the lock-overlay test below.
	 *
	 * @return array<string,string>
	 */
	public static function distinctive_values(): array {
		$values = array();
		foreach ( Schema::fields() as $key => $def ) {
			switch ( $def['type'] ) {
				case 'checkbox':
					$values[ $key ] = ( '1' === $def['default'] ) ? '' : '1';
					break;
				case 'select':
					foreach ( array_keys( $def['options'] ) as $option ) {
						if ( $option !== $def['default'] ) {
							$values[ $key ] = (string) $option;
							break;
						}
					}
					break;
				default:
					$values[ $key ] = self::distinctive_text( $key, $def['format'] ?? 'plain' );
			}
		}
		return $values;
	}

	private static function distinctive_text( string $key, string $format ): string {
		switch ( $format ) {
			case 'selector':
				return '.lp-' . $key;
			case 'domain':
				return 'lp-' . strtolower( $key ) . '.example';
			case 'css_class':
				return 'lp-' . $key;
			case 'css_classes':
				return 'lp-' . $key . '-a, lp-' . $key . '-b';
			default:
				return 'Hint ' . $key;
		}
	}

	/**
	 * What the payload must carry for a stored value.
	 *
	 * @param string $key    Storage key.
	 * @param string $stored Stored value.
	 * @return bool|string
	 */
	private static function expected_payload( string $key, string $stored ) {
		if ( 'checkbox' === Schema::field( $key )['type'] ) {
			return '1' === $stored;
		}
		if ( 'domain' === $key ) {
			return ConfigBuilder::domain_list( $stored );
		}
		return $stored;
	}

	public function test_distinctive_values_differ_from_defaults_and_survive_sanitizing() {
		$defaults = Schema::defaults();
		foreach ( self::distinctive_values() as $key => $value ) {
			$this->assertNotSame( $defaults[ $key ], $value, "$key: fixture value must differ from the default" );
			$this->assertSame( $value, \LinkPurpose\Settings\FieldSanitizer::sanitize( $key, $value ), "$key: fixture value must survive its sanitizer" );
		}
	}

	public function test_payload_keys_are_exactly_the_schema_plus_runtime_keys() {
		$payload  = ConfigBuilder::build();
		$expected = array_merge( Schema::keys(), array( 'shadowCSS', 'version' ) );
		sort( $expected );
		$actual = array_keys( $payload );
		sort( $actual );
		$this->assertSame( $expected, $actual, 'a schema field is missing from, or a stray key leaked into, the JS payload' );
	}

	/**
	 * Every field, stored per site, reaches the payload. One field at a
	 * time, so a key that is silently overwritten by another shows up.
	 */
	public function test_every_stored_site_value_reaches_the_payload() {
		foreach ( self::distinctive_values() as $key => $value ) {
			update_option( Settings::OPTION, array( $key => $value ) );
			$payload = ConfigBuilder::build();
			$this->assertSame( self::expected_payload( $key, $value ), $payload[ $key ], $key );
		}
		// And all at once: no field clobbers another.
		$all = self::distinctive_values();
		update_option( Settings::OPTION, $all );
		$payload = ConfigBuilder::build();
		foreach ( $all as $key => $value ) {
			$this->assertSame( self::expected_payload( $key, $value ), $payload[ $key ], $key );
		}
	}

	/**
	 * Every field, locked at network level, overrides the site value in
	 * the payload — including a blank text lock ("use the plugin default").
	 */
	public function test_every_network_locked_value_overrides_the_site_value_in_the_payload() {
		$network = self::distinctive_values();
		$site    = array();
		foreach ( Schema::fields() as $key => $def ) {
			// A site value that differs from both the default and the network value.
			$site[ $key ] = ( 'checkbox' === $def['type'] || 'select' === $def['type'] )
				? $def['default']
				: self::distinctive_text( $key, $def['format'] ?? 'plain' ) . '-site';
		}
		update_option( Settings::OPTION, $site );
		update_site_option(
			Settings::NETWORK_OPTION,
			array(
				'values' => $network,
				'modes'  => array_fill_keys( Schema::keys(), 'lock' ),
			)
		);
		$payload = ConfigBuilder::build();
		foreach ( $network as $key => $value ) {
			$this->assertSame( self::expected_payload( $key, $value ), $payload[ $key ], "$key: lock not applied" );
		}

		// Blank text locks win over customized site values.
		$blank = array();
		foreach ( Schema::fields() as $key => $def ) {
			if ( in_array( $def['type'], array( 'text', 'textarea' ), true ) ) {
				$blank[ $key ] = '';
			}
		}
		update_site_option(
			Settings::NETWORK_OPTION,
			array(
				'values' => $blank,
				'modes'  => array_fill_keys( array_keys( $blank ), 'lock' ),
			)
		);
		$payload = ConfigBuilder::build();
		foreach ( array_keys( $blank ) as $key ) {
			$expected = '' !== Schema::message_default( $key ) ? Schema::message_default( $key ) : ( 'domain' === $key ? home_url() : '' );
			$this->assertSame( $expected, $payload[ $key ], "$key: blank lock not applied" );
		}
	}

	public function test_checkboxes_become_booleans() {
		update_option( Settings::OPTION, array( 'purposeExternal' => '', 'noIconOnImages' => '1' ) );
		$payload = ConfigBuilder::build();
		$this->assertFalse( $payload['purposeExternal'] );
		$this->assertTrue( $payload['noIconOnImages'] );
		$this->assertTrue( $payload['purposeMail'], 'default on' );
	}

	public function test_domain_appends_home_url() {
		$this->assertSame( home_url(), ConfigBuilder::build()['domain'] );
		update_option( Settings::OPTION, array( 'domain' => 'a.net, *.b.net' ) );
		$this->assertSame( 'a.net, *.b.net, ' . home_url(), ConfigBuilder::build()['domain'] );
	}

	public function test_empty_message_resolves_to_translated_default() {
		$payload = ConfigBuilder::build();
		$this->assertSame( 'Link is external', $payload['purposeExternalMessage'] );
		$this->assertSame( 'Link opens phone app', $payload['purposeTelMessage'] );
		$this->assertSame( 'Link opens in new window', $payload['purposeNewWindowMessage'] );

		update_option( Settings::OPTION, array( 'purposeExternalMessage' => 'Externer Link' ) );
		$this->assertSame( 'Externer Link', ConfigBuilder::build()['purposeExternalMessage'] );
	}

	public function test_empty_text_settings_stay_empty_for_js_fallbacks() {
		$payload = ConfigBuilder::build();
		$this->assertSame( '', $payload['roots'] );
		$this->assertSame( '', $payload['purposeExternalClass'] );
	}

	public function test_js_settings_filter_can_add_developer_keys() {
		add_filter(
			'linkpurpose_js_settings',
			static function ( array $payload ): array {
				$payload['baseSelector'] = 'a[href], custom-link';
				return $payload;
			}
		);
		$this->assertSame( 'a[href], custom-link', ConfigBuilder::build()['baseSelector'] );
	}

	public function test_payload_is_json_encodable() {
		$json = wp_json_encode( ConfigBuilder::build() );
		$this->assertIsString( $json );
		$this->assertSame( JSON_ERROR_NONE, json_last_error() );
	}
}
