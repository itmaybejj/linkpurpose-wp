<?php
/**
 * Settings page: registration, rendered form shape, lock rendering,
 * network-context naming.
 *
 * @package LinkPurpose
 */

use LinkPurpose\Form\ConditionalFields;
use LinkPurpose\Form\FormRenderer;
use LinkPurpose\Form\SettingsContext;
use LinkPurpose\Form\SettingsPage;
use LinkPurpose\Plugin;
use LinkPurpose\Settings\Schema;
use LinkPurpose\Settings\Settings;

class Test_LinkPurpose_SettingsPage extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_site_option( Settings::NETWORK_OPTION );
		SettingsContext::reset();
	}

	public function tear_down() {
		SettingsContext::reset();
		delete_option( Settings::OPTION );
		delete_site_option( Settings::NETWORK_OPTION );
		parent::tear_down();
	}

	private function render(): string {
		ob_start();
		FormRenderer::render();
		return (string) ob_get_clean();
	}

	private function render_row( string $key ): string {
		ob_start();
		FormRenderer::render_field_row( $key );
		return (string) ob_get_clean();
	}

	public function test_menu_registered_for_admin() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
		$GLOBALS['submenu'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		SettingsPage::register_menu();
		$this->assertArrayHasKey( 'options-general.php', $GLOBALS['submenu'] );
		$slugs = array_column( $GLOBALS['submenu']['options-general.php'], 2 );
		$this->assertContains( Plugin::SLUG, $slugs );
	}

	public function test_setting_registered_with_validator() {
		SettingsPage::register_setting();
		global $wp_registered_settings;
		$this->assertArrayHasKey( Settings::OPTION, $wp_registered_settings );
		$this->assertSame( SettingsPage::OPTION_GROUP, $wp_registered_settings[ Settings::OPTION ]['group'] );
		$this->assertNotFalse( has_filter( 'sanitize_option_' . Settings::OPTION ) );
	}

	public function test_form_renders_an_input_for_every_field() {
		$html = $this->render();
		foreach ( Schema::keys() as $key ) {
			$this->assertStringContainsString( 'name="' . Settings::OPTION . '[' . $key . ']"', $html, $key );
			$this->assertStringContainsString( 'id="' . SettingsContext::input_id( $key ) . '"', $html, $key );
		}
		$this->assertStringContainsString( 'name="' . Settings::OPTION . '[purposeMail]"', $html );
	}

	public function test_sections_and_groups_mirror_the_drupal_form() {
		$html = $this->render();
		$this->assertStringContainsString( 'id="linkpurpose-section-basic"', $html );
		$this->assertStringContainsString( 'id="linkpurpose-section-icons"', $html );
		foreach ( array( 'containers', 'compatibility', 'migrating', 'external', 'document', 'download', 'app', 'mail', 'tel', 'newWindow' ) as $group ) {
			$this->assertStringContainsString( 'id="linkpurpose-group-' . $group . '"', $html, $group );
		}
		$this->assertSame( 2, substr_count( $html, '<fieldset class="linkpurpose-section"' ) );
	}

	public function test_conditional_rows_carry_rules_in_site_naming() {
		$row = $this->render_row( 'purposeExternalIconClasses' );
		$this->assertStringContainsString( ConditionalFields::ATTRIBUTE . '="', $row );
		$decoded = $this->decode_when( $row );
		$this->assertSame(
			array(
				array( 'input' => Settings::OPTION . '[purposeExternal]', 'value' => '1' ),
				array( 'input' => Settings::OPTION . '[purposeExternalIconType]', 'value' => 'classes' ),
			),
			$decoded
		);
		$this->assertStringNotContainsString( ConditionalFields::ATTRIBUTE, $this->render_row( 'purposeExternal' ) );
	}

	public function test_prefill_and_placeholders() {
		update_option( Settings::OPTION, array( 'roots' => 'main', 'purposeExternal' => '' ) );
		$html = $this->render();
		$this->assertStringContainsString( '>main</textarea>', $html );
		$this->assertStringContainsString( 'placeholder="' . esc_attr( home_url() ) . '"', $html );
		$this->assertStringContainsString( 'placeholder="link-purpose-external"', $html );
		// Unchecked toggle renders unchecked; defaults-on toggle renders checked.
		$this->assertMatchesRegularExpression( '#<input type="checkbox" id="linkpurpose-purposeExternal"[^>]*value="1" />#', $html );
		$this->assertMatchesRegularExpression( '#<input type="checkbox" id="linkpurpose-purposeMail"[^>]*checked=\'checked\'#', $html );
	}

	public function test_select_defaults_when_unset() {
		$row = $this->render_row( 'purposeExternalIconPosition' );
		$this->assertStringContainsString( "<option value=\"beforeend\" selected='selected'>", $row );
	}

	public function test_locked_field_renders_disabled_with_note_and_network_value() {
		update_option( Settings::OPTION, array( 'roots' => 'site' ) );
		update_site_option(
			Settings::NETWORK_OPTION,
			array(
				'values' => array( 'roots' => 'network' ),
				'modes'  => array( 'roots' => 'lock' ),
			)
		);
		$row = $this->render_row( 'roots' );
		$this->assertStringContainsString( "disabled='disabled'", $row );
		$this->assertStringContainsString( '>network</textarea>', $row );
		$this->assertStringContainsString( 'linkpurpose-locked-by-network', $row );
		$this->assertStringNotContainsString( 'linkpurpose-network-mode', $row, 'no propagation dropdown on the site page' );
	}

	public function test_network_context_renames_inputs_and_adds_mode_dropdown() {
		update_site_option(
			Settings::NETWORK_OPTION,
			array(
				'values' => array( 'roots' => 'network' ),
				'modes'  => array( 'roots' => 'all' ),
			)
		);
		SettingsContext::push( 'network' );
		try {
			$row = $this->render_row( 'roots' );
			$dep = $this->render_row( 'purposeExternalIconType' );
		} finally {
			SettingsContext::pop();
		}
		$this->assertStringContainsString( 'name="network_values[roots]"', $row );
		$this->assertStringContainsString( 'name="network_modes[roots]"', $row );
		$this->assertStringContainsString( "<option value=\"all\" selected='selected'>", $row );
		$this->assertStringContainsString( '>network</textarea>', $row );
		$this->assertStringNotContainsString( 'disabled', $row );
		$this->assertSame( 'network_values[purposeExternal]', $this->decode_when( $dep )[0]['input'] );
	}

	public function test_help_text_html_is_limited_to_allowlist() {
		$html = $this->render();
		$this->assertStringContainsString( '<code><em>main, #footer</em></code>', $html );
		$this->assertStringContainsString( 'href="https://developer.mozilla.org', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_settings_link_added_to_plugin_row() {
		$links = SettingsPage::add_settings_link( array( 'deactivate' => '<a>Deactivate</a>' ) );
		$this->assertStringContainsString( 'page=' . Plugin::SLUG, $links[0] );
	}

	private function decode_when( string $row ): array {
		$this->assertSame( 1, preg_match( '/' . ConditionalFields::ATTRIBUTE . '="([^"]+)"/', $row, $m ) );
		return json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
	}
}
