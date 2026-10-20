<?php
/**
 * Schema integrity: the registry every other component is driven by.
 *
 * @package LinkPurpose
 */

use LinkPurpose\Settings\Schema;

class Test_LinkPurpose_Schema extends WP_UnitTestCase {

	public function test_every_field_has_type_default_and_label() {
		foreach ( Schema::fields() as $key => $def ) {
			$this->assertArrayHasKey( 'type', $def, $key );
			$this->assertContains( $def['type'], array( 'textarea', 'text', 'checkbox', 'select' ), $key );
			$this->assertArrayHasKey( 'default', $def, $key );
			$this->assertIsString( $def['default'], $key );
			$this->assertArrayHasKey( 'label', $def, $key );
			$this->assertNotSame( '', $def['label'], $key );
		}
	}

	public function test_selects_have_options_containing_their_default() {
		foreach ( Schema::fields() as $key => $def ) {
			if ( 'select' !== $def['type'] ) {
				continue;
			}
			$this->assertNotEmpty( $def['options'], $key );
			$this->assertArrayHasKey( $def['default'], $def['options'], $key );
		}
	}

	public function test_text_fields_declare_a_known_format() {
		foreach ( Schema::fields() as $key => $def ) {
			if ( ! in_array( $def['type'], array( 'text', 'textarea' ), true ) ) {
				continue;
			}
			$this->assertContains( $def['format'] ?? 'plain', array( 'selector', 'domain', 'css_class', 'css_classes', 'plain' ), $key );
		}
	}

	public function test_depends_reference_existing_keys() {
		foreach ( Schema::fields() as $key => $def ) {
			foreach ( $def['depends'] ?? array() as $condition ) {
				$this->assertTrue( Schema::has( $condition[0] ), "$key depends on unknown key {$condition[0]}" );
			}
		}
	}

	public function test_layout_covers_every_field_exactly_once() {
		$seen = array();
		foreach ( Schema::layout() as $section ) {
			foreach ( $section['fields'] ?? array() as $key ) {
				$seen[] = $key;
			}
			foreach ( $section['groups'] ?? array() as $group ) {
				foreach ( $group['fields'] ?? array() as $key ) {
					$seen[] = $key;
				}
			}
		}
		sort( $seen );
		$expected = Schema::keys();
		sort( $expected );
		$this->assertSame( $expected, $seen );
		$this->assertSame( count( array_unique( $seen ) ), count( $seen ), 'a field is placed twice' );
	}

	public function test_drupal_parity_keys_are_present() {
		// The Drupal module's drupalSettings key list, minus the per-purpose
		// selector keys the Drupal form never exposed for mail/tel/newWindow.
		$drupal = array(
			'roots',
			'shadowComponents',
			'ignore',
			'suppressNoBreak',
			'insertIconOutsideHiddenSpan',
			'hideIcon',
			'noRunIfPresent',
			'noRunIfAbsent',
			'noIconOnImages',
			'domain',
			'themePreprocess',
		);
		foreach ( array( 'Document', 'Download', 'App', 'External', 'Mail', 'Tel', 'NewWindow' ) as $purpose ) {
			foreach ( array( '', 'Message', 'Class', 'IconWrapperClass', 'IconType', 'IconPosition', 'IconClasses' ) as $suffix ) {
				$drupal[] = 'purpose' . $purpose . $suffix;
			}
		}
		foreach ( array( 'Document', 'Download', 'App', 'External' ) as $purpose ) {
			$drupal[] = 'purpose' . $purpose . 'Selector';
		}
		$drupal[] = 'purposeExternalNoReferrer';
		$drupal[] = 'purposeExternalNewWindow';

		foreach ( $drupal as $key ) {
			$this->assertTrue( Schema::has( $key ), "missing Drupal key $key" );
		}
		// noAggregate is Drupal-only (no WP aggregation) and intentionally absent.
		$this->assertFalse( Schema::has( 'noAggregate' ) );
	}

	public function test_purpose_toggles_default_on_and_messages_have_translated_defaults() {
		foreach ( Schema::purposes() as $purpose ) {
			$this->assertSame( '1', Schema::field( $purpose['prefix'] )['default'] );
			$this->assertNotSame( '', Schema::message_default( $purpose['message_key'] ) );
		}
		$this->assertSame( '', Schema::message_default( 'roots' ) );
	}
}
