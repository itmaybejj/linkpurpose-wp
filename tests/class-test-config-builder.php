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
