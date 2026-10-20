<?php
/**
 * Front-end enqueue behavior.
 *
 * @package LinkPurpose
 */

use LinkPurpose\Frontend\Assets;
use LinkPurpose\Settings\Settings;

class Test_LinkPurpose_FrontendAssets extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		$this->reset_wp_scripts();
	}

	public function tear_down() {
		remove_all_filters( 'linkpurpose_enabled' );
		delete_option( Settings::OPTION );
		$this->reset_wp_scripts();
		parent::tear_down();
	}

	private function reset_wp_scripts(): void {
		$GLOBALS['wp_scripts'] = new WP_Scripts(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$GLOBALS['wp_styles']  = new WP_Styles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	}

	public function test_hook_is_registered_on_front_end_enqueue() {
		$this->assertNotFalse( has_action( 'wp_enqueue_scripts', array( Assets::class, 'enqueue' ) ) );
	}

	public function test_enqueues_library_style_payload_and_init_shim() {
		Assets::enqueue();
		$this->assertTrue( wp_script_is( Assets::LIBRARY_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( Assets::INIT_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Assets::STYLE_HANDLE, 'enqueued' ) );

		$inline = wp_scripts()->get_data( Assets::LIBRARY_HANDLE, 'before' );
		$this->assertIsArray( $inline );
		$this->assertStringContainsString( 'window.linkpurposeSettings = {', implode( '', array_filter( $inline ) ) );

		$deps = wp_scripts()->registered[ Assets::INIT_HANDLE ]->deps;
		$this->assertSame( array( Assets::LIBRARY_HANDLE ), $deps );
	}

	public function test_theme_preprocess_skips_init_shim_but_keeps_payload() {
		update_option( Settings::OPTION, array( 'themePreprocess' => '1' ) );
		Assets::enqueue();
		$this->assertTrue( wp_script_is( Assets::LIBRARY_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( Assets::INIT_HANDLE, 'enqueued' ) );
		$this->assertNotEmpty( wp_scripts()->get_data( Assets::LIBRARY_HANDLE, 'before' ) );
	}

	public function test_enabled_filter_short_circuits() {
		add_filter( 'linkpurpose_enabled', '__return_false' );
		Assets::enqueue();
		$this->assertFalse( wp_script_is( Assets::LIBRARY_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( Assets::STYLE_HANDLE, 'enqueued' ) );
	}

	public function test_assets_are_versioned_with_plugin_version() {
		Assets::enqueue();
		$this->assertSame( \LinkPurpose\Plugin::VERSION, wp_scripts()->registered[ Assets::LIBRARY_HANDLE ]->ver );
		$this->assertSame( \LinkPurpose\Plugin::VERSION, wp_styles()->registered[ Assets::STYLE_HANDLE ]->ver );
	}

	public function test_vendored_library_files_exist() {
		$this->assertFileExists( \LinkPurpose\Plugin::path( 'assets/lib/js/linkpurpose.min.js' ) );
		$this->assertFileExists( \LinkPurpose\Plugin::path( 'assets/lib/css/linkpurpose.css' ) );
		$this->assertFileExists( \LinkPurpose\Plugin::path( 'assets/js/linkpurpose-wp.js' ) );
	}
}
