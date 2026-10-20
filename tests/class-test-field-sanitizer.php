<?php
/**
 * Per-format sanitize rules.
 *
 * @package LinkPurpose
 */

use LinkPurpose\Settings\FieldSanitizer;

class Test_LinkPurpose_FieldSanitizer extends WP_UnitTestCase {

	public function test_checkbox_shape() {
		$this->assertSame( '1', FieldSanitizer::checkbox( '1' ) );
		$this->assertSame( '1', FieldSanitizer::checkbox( 'on' ) );
		$this->assertSame( '', FieldSanitizer::checkbox( '' ) );
		$this->assertSame( '', FieldSanitizer::checkbox( null ) );
		$this->assertSame( '', FieldSanitizer::checkbox( '0' ) );
		$this->assertSame( '', FieldSanitizer::checkbox( array( '1' ) ) );
	}

	public function test_select_rejects_unknown_values() {
		$this->assertSame( 'classes', FieldSanitizer::sanitize( 'purposeExternalIconType', 'classes' ) );
		$this->assertSame( 'html', FieldSanitizer::sanitize( 'purposeExternalIconType', '<script>' ) );
		$this->assertSame( 'afterend', FieldSanitizer::sanitize( 'purposeTelIconPosition', 'afterend' ) );
		$this->assertSame( 'beforeend', FieldSanitizer::sanitize( 'purposeTelIconPosition', 'nope' ) );
	}

	public function test_selector_collapses_whitespace_and_keeps_css_syntax() {
		$this->assertSame(
			'main > a[href^="https://x"], #footer a',
			FieldSanitizer::selector( "main > a[href^=\"https://x\"],\n   #footer   a  " )
		);
		$this->assertSame( "[href$='.pdf'], [href*='.pdf?']", FieldSanitizer::selector( "[href$='.pdf'], [href*='.pdf?']" ) );
	}

	public function test_selector_strips_markup() {
		$this->assertStringNotContainsString( '<script', FieldSanitizer::selector( 'a<script>alert(1)</script>' ) );
	}

	public function test_domain_list_normalizes_separators() {
		$this->assertSame(
			'www.only-www.net, *.all-subdomains.net, a-shared-site.net/just-my-blog',
			FieldSanitizer::domain_list( " www.only-www.net ,*.all-subdomains.net,,\n a-shared-site.net/just-my-blog, " )
		);
		$this->assertSame( '', FieldSanitizer::domain_list( ' , ' ) );
		$this->assertSame( 'a.net', FieldSanitizer::domain_list( 'a.net, a.net' ) );
	}

	public function test_css_class_keeps_first_token_only() {
		$this->assertSame( 'ext', FieldSanitizer::css_class( ' ext mailto ' ) );
		$this->assertSame( 'my-class_1', FieldSanitizer::css_class( 'my-class_1' ) );
		$this->assertSame( '', FieldSanitizer::css_class( '<b>' ) );
	}

	public function test_css_classes_accepts_commas_or_spaces() {
		$this->assertSame( 'fa-solid, fa-download', FieldSanitizer::css_classes( 'fa-solid fa-download' ) );
		$this->assertSame( 'fa-solid, fa-download', FieldSanitizer::css_classes( 'fa-solid,fa-download' ) );
		$this->assertSame( 'fa-solid, fa-download', FieldSanitizer::css_classes( ' fa-solid , , fa-download ' ) );
		$this->assertSame( 'fa-solid', FieldSanitizer::css_classes( 'fa-solid, fa-solid' ) );
	}

	public function test_plain_text_strips_tags() {
		$this->assertSame( 'Link is external', FieldSanitizer::sanitize( 'purposeExternalMessage', '<em>Link is external</em>' ) );
	}

	public function test_unknown_key_sanitizes_to_empty() {
		$this->assertSame( '', FieldSanitizer::sanitize( 'notAKey', 'anything' ) );
	}

	public function test_non_scalar_text_input_sanitizes_to_empty() {
		$this->assertSame( '', FieldSanitizer::sanitize( 'roots', array( 'main' ) ) );
	}
}
