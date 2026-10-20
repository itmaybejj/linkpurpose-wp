<?php
/**
 * Plugin bootstrap and lifecycle.
 *
 * @package LinkPurpose
 */

use LinkPurpose\Installer;
use LinkPurpose\Plugin;
use LinkPurpose\Settings\Settings;

class Test_LinkPurpose_PluginLifecycle extends WP_UnitTestCase {

	public function test_version_header_matches_class_constant() {
		$header = get_file_data( LINKPURPOSE_FILE, array( 'Version' => 'Version' ) );
		$this->assertSame( Plugin::VERSION, $header['Version'] );
	}

	public function test_text_domain_header_matches_phpcs_pin() {
		$header = get_file_data( LINKPURPOSE_FILE, array( 'TextDomain' => 'Text Domain' ) );
		$this->assertSame( 'linkpurpose', $header['TextDomain'] );
		$this->assertStringContainsString( '<element value="linkpurpose"/>', (string) file_get_contents( Plugin::path( 'phpcs.xml.dist' ) ) );
	}

	public function test_paths_and_urls() {
		$this->assertStringEndsWith( '/linkpurpose-wp/assets/x.css', Plugin::path( 'assets/x.css' ) );
		$this->assertStringEndsWith( '/linkpurpose-wp/assets/x.css', Plugin::url( '/assets/x.css' ) );
		$this->assertSame( 'linkpurpose-wp/linkpurpose.php', Plugin::basename() );
	}

	public function test_uninstall_removes_options() {
		update_option( Settings::OPTION, array( 'roots' => 'x' ) );
		update_site_option( Settings::NETWORK_OPTION, array( 'values' => array() ) );
		Installer::uninstall();
		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_site_option( Settings::NETWORK_OPTION ) );
	}

	public function test_uninstall_removes_worker_state_history_and_queued_tick() {
		update_site_option( Installer::WORKER_STATE_OPTION, array( 'status' => 'running' ) );
		update_site_option( Installer::WORKER_HISTORY_OPTION, array( 'roots' => array( 'a' ) ) );
		wp_schedule_single_event( time() + 60, Installer::WORKER_CRON_HOOK );
		Installer::uninstall();
		$this->assertFalse( get_site_option( Installer::WORKER_STATE_OPTION ) );
		$this->assertFalse( get_site_option( Installer::WORKER_HISTORY_OPTION ) );
		$this->assertFalse( wp_next_scheduled( Installer::WORKER_CRON_HOOK ) );
	}

	public function test_deactivate_drops_the_queued_tick_but_keeps_settings() {
		update_option( Settings::OPTION, array( 'roots' => 'x' ) );
		wp_schedule_single_event( time() + 60, Installer::WORKER_CRON_HOOK );
		Installer::deactivate();
		$this->assertFalse( wp_next_scheduled( Installer::WORKER_CRON_HOOK ) );
		$this->assertSame( array( 'roots' => 'x' ), get_option( Settings::OPTION ) );
	}

	public function test_uninstall_walks_every_site_on_multisite() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'multisite only' );
		}
		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );
		update_option( Settings::OPTION, array( 'roots' => 'sub' ) );
		restore_current_blog();

		Installer::uninstall();

		switch_to_blog( $blog_id );
		$this->assertFalse( get_option( Settings::OPTION ) );
		restore_current_blog();
	}

	public function test_autoloader_resolves_namespace_only() {
		$this->assertTrue( class_exists( \LinkPurpose\Form\FormRenderer::class ) );
		$this->assertFalse( class_exists( 'LinkPurpose\\DoesNotExist' ) );
	}
}
