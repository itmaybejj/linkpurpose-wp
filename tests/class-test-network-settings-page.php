<?php
/**
 * Network defaults page: registration, rendering, the save pipeline driven
 * by the real rendered form, notices and the heartbeat panel.
 *
 * @package LinkPurpose
 */

use LinkPurpose\Form\FormRenderer;
use LinkPurpose\Form\NetworkDefaultsWorker as Worker;
use LinkPurpose\Form\NetworkSettingsPage as Page;
use LinkPurpose\Form\SettingsContext;
use LinkPurpose\Settings\Schema;
use LinkPurpose\Settings\Settings;

class Test_LinkPurpose_NetworkSettingsPage extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$this->wipe();
		SettingsContext::reset();
	}

	public function tear_down() {
		SettingsContext::reset();
		unset( $_GET['updated'] );
		$this->wipe();
		parent::tear_down();
	}

	private function wipe(): void {
		delete_option( Settings::OPTION );
		delete_site_option( Settings::NETWORK_OPTION );
		delete_site_option( Worker::HISTORY_OPTION );
		Worker::reset();
	}

	/* ===== fixtures ===== */

	private function as_super_admin(): WP_User {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}
		wp_set_current_user( $user_id );
		$user = wp_get_current_user();
		if ( ! is_multisite() ) {
			$user->add_cap( Page::CAPABILITY );
		}
		return $user;
	}

	private function render_page(): string {
		ob_start();
		Page::render_page();
		return (string) ob_get_clean();
	}

	/** Render the network form and serialize it the way a browser would. */
	private function untouched_post(): array {
		SettingsContext::push( 'network' );
		try {
			ob_start();
			FormRenderer::render();
			$html = (string) ob_get_clean();
		} finally {
			SettingsContext::pop();
		}
		return $this->serialize_form( $html );
	}

	private function serialize_form( string $html ): array {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $doc );
		$pairs = array();
		foreach ( $xpath->query( '//input|//select|//textarea' ) as $el ) {
			/** @var DOMElement $el */
			$name = $el->getAttribute( 'name' );
			if ( '' === $name || $el->hasAttribute( 'disabled' ) ) {
				continue;
			}
			if ( 'select' === $el->tagName ) {
				$value = null;
				$first = null;
				foreach ( $el->getElementsByTagName( 'option' ) as $option ) {
					if ( null === $first ) {
						$first = $option->getAttribute( 'value' );
					}
					if ( $option->hasAttribute( 'selected' ) ) {
						$value = $option->getAttribute( 'value' );
						break;
					}
				}
				$pairs[] = array( $name, null === $value ? $first : $value );
				continue;
			}
			if ( 'textarea' === $el->tagName ) {
				$pairs[] = array( $name, $el->textContent );
				continue;
			}
			$type = strtolower( $el->getAttribute( 'type' ) );
			if ( 'checkbox' === $type && ! $el->hasAttribute( 'checked' ) ) {
				continue;
			}
			$pairs[] = array( $name, $el->getAttribute( 'value' ) );
		}
		$query = implode(
			'&',
			array_map(
				static function ( array $pair ): string {
					return rawurlencode( $pair[0] ) . '=' . rawurlencode( (string) $pair[1] );
				},
				$pairs
			)
		);
		parse_str( $query, $post );
		return $post;
	}

	private function assert_saved( array $result ): void {
		$this->assertTrue( $result['saved'], 'orphans: ' . implode( ', ', $result['orphans'] ) );
	}

	/* ===== registration + rendering ===== */

	public function test_menu_registered_under_network_settings() {
		$this->as_super_admin();
		$GLOBALS['submenu'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		Page::register_menu();
		$this->assertArrayHasKey( 'settings.php', $GLOBALS['submenu'] );
		$this->assertContains( Page::SLUG, array_column( $GLOBALS['submenu']['settings.php'], 2 ) );
	}

	public function test_page_renders_the_network_form_and_an_idle_panel() {
		$this->as_super_admin();
		$html = $this->render_page();
		$this->assertStringContainsString( 'class="wrap linkpurpose-settings linkpurpose-network-settings"', $html );
		$this->assertStringContainsString( 'id="linkpurpose-backfill-panel" data-running="0"></div>', $html );
		$this->assertStringContainsString( 'name="' . Page::NONCE_FIELD . '"', $html );
		$this->assertStringContainsString( 'value="' . Page::SAVE_ACTION . '"', $html );
		$this->assertStringContainsString( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $html );
		foreach ( Schema::keys() as $key ) {
			$this->assertStringContainsString( 'name="network_values[' . $key . ']"', $html, $key );
			$this->assertStringContainsString( 'name="network_modes[' . $key . ']"', $html, $key );
		}
		$this->assertStringNotContainsString( 'placeholder="' . esc_attr( home_url() ) . '"', $html, 'no single site URL applies on the network form' );
		$this->assertStringNotContainsString( 'disabled', $html );
		$this->assertStringContainsString( esc_html( Page::propagation_help_text() ), $html );
		$this->assertSame( 'site', SettingsContext::current(), 'context popped after render' );
	}

	public function test_page_requires_the_network_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( WPDieException::class );
		Page::render_page();
	}

	public function test_network_plugin_row_link_points_at_the_page() {
		$links = Page::add_settings_link( array( 'deactivate' => '<a>Deactivate</a>' ) );
		$this->assertStringContainsString( 'page=' . Page::SLUG, $links[0] );
		$this->assertStringContainsString( 'settings.php', Page::url() );
	}

	/* ===== save pipeline ===== */

	public function test_untouched_form_saves_without_orphans_and_propagates_nothing() {
		$post = $this->untouched_post();
		$this->assertSame( 'html', $post['network_values']['purposeExternalIconType'], 'fixture: selects post their default' );
		$this->assertSame( '1', $post['network_values']['purposeMail'], 'fixture: default-on boxes post checked' );
		$this->assertArrayNotHasKey( 'themePreprocess', $post['network_values'], 'fixture: default-off boxes are absent' );
		$this->assertSame( '', $post['network_modes']['roots'] );

		$result = Page::save_from_post( $post );

		$this->assert_saved( $result );
		$this->assertSame( array(), $result['dirty'] );
		$stored = Settings::network_storage();
		$this->assertSame( Schema::keys(), array_keys( $stored['values'] ) );
		$this->assertSame( array(), $stored['modes'], 'defaults are stored inert' );
		$this->assertSame( 'idle', Worker::get_state()['status'] );
		$this->assertSame( Schema::defaults(), Settings::all(), 'site reads are unaffected' );
	}

	public function test_changed_value_without_a_mode_is_rejected_and_nothing_is_written() {
		$post = $this->untouched_post();
		$post['network_values']['roots'] = 'main';
		unset( $post['network_values']['purposeExternal'] );

		$result = Page::save_from_post( $post );

		$this->assertFalse( $result['saved'] );
		$this->assertSame( array( 'roots', 'purposeExternal' ), $result['orphans'] );
		$this->assertFalse( get_site_option( Settings::NETWORK_OPTION ) );
		$this->assertSame( 'idle', Worker::get_state()['status'] );
	}

	public function test_all_mode_text_change_stores_queues_backfill_and_records_history() {
		$post = $this->untouched_post();
		$post['network_values']['roots'] = 'main';
		$post['network_modes']['roots']  = 'all';

		$result = Page::save_from_post( $post );

		$this->assert_saved( $result );
		$this->assertSame( array( 'roots' ), array_keys( $result['dirty'] ) );
		$this->assertNull( $result['dirty']['roots']['old'] );
		$this->assertSame( 'main', $result['dirty']['roots']['new'] );
		$this->assertFalse( $result['dirty']['roots']['force'] );
		$this->assertSame( 'main', Settings::network_value( 'roots' ) );
		$this->assertSame( 'all', Settings::network_mode( 'roots' ) );
		$this->assertSame( 'running', Worker::get_state()['status'] );
		$this->assertSame( array( 'main' ), Worker::get_history()['roots'] );
		$this->assertSame( array( 'html' ), Worker::get_history()['purposeExternalIconType'], 'every published value is recorded, defaults included' );
		$this->assertSame( '', Settings::get( 'roots' ), 'all-mode does not overlay at read time' );
	}

	public function test_unchecked_checkbox_with_lock_is_stored_locked_off() {
		$post = $this->untouched_post();
		unset( $post['network_values']['purposeExternal'] );
		$post['network_modes']['purposeExternal'] = 'lock';

		$result = Page::save_from_post( $post );

		$this->assert_saved( $result );
		$this->assertTrue( $result['dirty']['purposeExternal']['force'] );
		$this->assertTrue( Settings::is_locked( 'purposeExternal' ) );
		$this->assertSame( '', Settings::get( 'purposeExternal' ) );
	}

	public function test_blank_text_with_all_mode_propagates_the_plugin_default() {
		$post = $this->untouched_post();
		$post['network_modes']['roots'] = 'all';

		$result = Page::save_from_post( $post );

		$this->assert_saved( $result );
		$this->assertArrayNotHasKey( 'inert', $result );
		$this->assertSame( 'all', Settings::network_mode( 'roots' ) );
		$this->assertTrue( Settings::has_network_value( 'roots' ) );
		$this->assertSame( array( 'roots' ), array_keys( $result['dirty'] ) );
		$this->assertSame( '', $result['dirty']['roots']['new'] );
		$this->assertSame( 'running', Worker::get_state()['status'] );
	}

	public function test_blank_text_with_all_mode_resets_sites_still_on_the_previous_network_value() {
		// Publish "main" to every site, then apply it to this site.
		$post = $this->untouched_post();
		$post['network_values']['roots'] = 'main';
		$post['network_modes']['roots']  = 'all';
		$this->assert_saved( Page::save_from_post( $post ) );
		$state = Worker::get_state();
		$this->assertTrue( Worker::apply_dirty( $state['dirty'], $state['hardcoded'] ) );
		$this->assertSame( 'main', Settings::get( 'roots' ) );
		Worker::reset();

		// Blank it out: sites still on "main" go back to the plugin default.
		$post['network_values']['roots'] = '';
		$result                          = Page::save_from_post( $post );
		$this->assert_saved( $result );
		$this->assertSame( 'main', $result['dirty']['roots']['old'] );
		$this->assertSame( '', $result['dirty']['roots']['new'] );
		$state = Worker::get_state();
		$this->assertSame( array( 'main' ), $state['dirty']['roots']['olds'] );
		$this->assertTrue( Worker::apply_dirty( $state['dirty'], $state['hardcoded'] ) );
		$this->assertSame( '', get_option( Settings::OPTION )['roots'] );
		$this->assertSame( '', Settings::get( 'roots' ), 'site is back on the library default' );

		// A site that customized the value keeps it.
		update_option( Settings::OPTION, array( 'roots' => 'custom' ) );
		$this->assertFalse( Worker::apply_dirty( $state['dirty'], $state['hardcoded'] ) );
		$this->assertSame( 'custom', Settings::get( 'roots' ) );
	}

	public function test_blank_text_with_lock_pins_sites_to_the_plugin_default() {
		update_option( Settings::OPTION, array( 'roots' => 'custom', 'purposeExternalMessage' => 'Custom hint' ) );
		$post = $this->untouched_post();
		$post['network_modes']['roots']                  = 'lock';
		$post['network_modes']['purposeExternalMessage'] = 'lock';

		$result = Page::save_from_post( $post );

		$this->assert_saved( $result );
		$this->assertTrue( $result['dirty']['roots']['force'] );
		$this->assertTrue( Settings::is_locked( 'roots' ) );
		$this->assertTrue( Settings::is_locked( 'purposeExternalMessage' ) );
		$this->assertSame( '', Settings::get( 'roots' ), 'lock overlays the blank at read time' );
		$this->assertSame( '', Settings::get( 'purposeExternalMessage' ) );
		$this->assertSame( 'Link is external', \LinkPurpose\Frontend\ConfigBuilder::build()['purposeExternalMessage'], 'the payload resolves the translated default' );
		$this->assertTrue( SettingsContext::is_disabled( 'roots' ) );
		$this->assertSame( '', SettingsContext::value( 'roots' ), 'the disabled site input shows the placeholder, not the stale custom value' );

		$state = Worker::get_state();
		$this->assertTrue( Worker::apply_dirty( $state['dirty'], $state['hardcoded'] ) );
		$this->assertSame( '', get_option( Settings::OPTION )['roots'], 'lock force-writes the blank' );
	}

	public function test_second_save_hands_the_previous_value_to_the_worker() {
		$post = $this->untouched_post();
		$post['network_values']['roots'] = 'a';
		$post['network_modes']['roots']  = 'all';
		$this->assert_saved( Page::save_from_post( $post ) );
		Worker::reset();

		$post['network_values']['roots'] = 'b';
		$result                          = Page::save_from_post( $post );

		$this->assert_saved( $result );
		$this->assertSame( 'a', $result['dirty']['roots']['old'] );
		$this->assertSame( array( 'a' ), Worker::get_state()['dirty']['roots']['olds'] );
		$this->assertSame( array( 'a', 'b' ), Worker::get_history()['roots'] );

		$this->assert_saved( Page::save_from_post( $post ) );
		$this->assertSame( array( 'a', 'b' ), Worker::get_history()['roots'], 'an unchanged save records nothing new' );
	}

	public function test_saved_action_fires_with_new_and_old_storage() {
		$seen = array();
		add_action(
			'linkpurpose_network_settings_saved',
			static function ( $new, $old ) use ( &$seen ) {
				$seen = array( $new, $old );
			},
			10,
			2
		);
		$post = $this->untouched_post();
		$post['network_values']['roots'] = 'main';
		$post['network_modes']['roots']  = 'lock';
		$this->assert_saved( Page::save_from_post( $post ) );
		$this->assertSame( 'main', $seen[0]['values']['roots'] );
		$this->assertSame( array(), $seen[1]['values'] );
	}

	/* ===== notices + heartbeat ===== */

	public function test_rejection_notice_lists_field_labels_and_consumes_the_transient() {
		$user = $this->as_super_admin();
		set_transient( Page::NOTICE_TRANSIENT . $user->ID, array( 'orphans' => array( 'roots', 'unknownKey' ) ), MINUTE_IN_SECONDS );
		$_GET['updated'] = '0';
		$html            = $this->render_page();
		$this->assertStringContainsString( 'Network defaults were not saved.', $html );
		$this->assertStringContainsString( '<li>Page areas to check</li>', $html );
		$this->assertStringContainsString( '<li>unknownKey</li>', $html );
		$this->assertStringNotContainsString( 'Network defaults saved.', $html );
		$this->assertFalse( get_transient( Page::NOTICE_TRANSIENT . $user->ID ) );
		$this->assertStringNotContainsString( 'were not saved', $this->render_page(), 'notice shows once' );
	}

	public function test_saved_notice() {
		$this->as_super_admin();
		$_GET['updated'] = '1';
		$html            = $this->render_page();
		$this->assertStringContainsString( 'Network defaults saved.', $html );
		$this->assertStringNotContainsString( 'notice-warning', $html, 'no inert-mode warning exists any more' );
	}

	public function test_backfill_panel_reflects_worker_state() {
		$this->assertSame( '', Page::render_backfill_panel_inner(), 'idle renders nothing' );

		$state              = Worker::default_state();
		$state['status']    = 'completed';
		$state['total']     = 3;
		$state['processed'] = 3;
		$state['updated']   = 2;
		update_site_option( Worker::STATE_OPTION, $state );
		$html = Page::render_backfill_panel_inner();
		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( ' inline', $html );
		$this->assertStringContainsString( '3 of 3 sites visited, 2 updated.', $html );

		$state['status'] = 'failed';
		$state['errors'] = array(
			array(
				'blog_id' => 4,
				'message' => 'boom',
			),
		);
		update_site_option( Worker::STATE_OPTION, $state );
		$html = Page::render_backfill_panel_inner();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( '1 error(s) recorded', $html );
		$this->assertStringNotContainsString( 'boom', $html, 'raw error text stays in the log' );

		$state['status']         = 'running';
		$state['processed']      = 1;
		$state['errors']         = array();
		$state['job_started_at'] = time() - 10;
		$state['updated_at']     = time();
		update_site_option( Worker::STATE_OPTION, $state );
		$html = Page::render_backfill_panel_inner();
		$this->assertStringContainsString( 'notice-info', $html );
		$this->assertStringContainsString( 'remaining.', $html );
		$this->assertStringContainsString( 'restarts propagation', $html );
	}

	public function test_heartbeat_only_answers_capable_clients_that_asked() {
		$state           = Worker::default_state();
		$state['status'] = 'running';
		update_site_option( Worker::STATE_OPTION, $state );

		$this->as_super_admin();
		$this->assertSame( array( 'x' => 1 ), Page::heartbeat_received( array( 'x' => 1 ), array() ), 'no request key → untouched' );

		$response = Page::heartbeat_received( array(), array( Page::HEARTBEAT_REQUEST => 1 ) );
		$this->assertTrue( $response[ Page::HEARTBEAT_RUNNING ] );
		$this->assertStringContainsString( 'Propagating network defaults', $response[ Page::HEARTBEAT_PANEL ] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			$this->assertSame( array(), Page::heartbeat_received( array(), array( Page::HEARTBEAT_REQUEST => 1 ) ), 'plain admin gets nothing' );
		}
		wp_set_current_user( 0 );
		$this->assertSame( array(), Page::heartbeat_received( array(), array( Page::HEARTBEAT_REQUEST => 1 ) ), 'anonymous gets nothing' );
	}
}
