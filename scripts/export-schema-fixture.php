<?php
/**
 * Dump the settings schema to tests/js/fixtures/schema.json so the Vitest
 * suite can iterate every field the PHP side defines.
 *
 * Run via `composer schema-fixture` (wp-cli `eval-file` inside the ddev
 * container, with the plugin loaded). The PHPUnit schema test fails when
 * the fixture is stale, so a new field cannot ship without the JS tests
 * seeing it.
 *
 * @package LinkPurpose
 */

use LinkPurpose\Settings\Schema;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Run through wp-cli: composer schema-fixture\n" );
	exit( 1 );
}

$linkpurpose_fixture = array();
foreach ( Schema::fields() as $key => $def ) {
	$entry = array(
		'type'    => $def['type'],
		'default' => (string) $def['default'],
	);
	if ( isset( $def['format'] ) ) {
		$entry['format'] = $def['format'];
	}
	if ( isset( $def['options'] ) ) {
		$entry['options'] = array_keys( $def['options'] );
	}
	$message_default = Schema::message_default( $key );
	if ( '' !== $message_default ) {
		$entry['messageDefault'] = $message_default;
	}
	$linkpurpose_fixture[ $key ] = $entry;
}

$linkpurpose_target = dirname( __DIR__ ) . '/tests/js/fixtures/schema.json';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- dev-only script.
file_put_contents( $linkpurpose_target, wp_json_encode( $linkpurpose_fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
WP_CLI::success( sprintf( 'Wrote %d fields to %s', count( $linkpurpose_fixture ), $linkpurpose_target ) );
