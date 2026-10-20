<?php
/**
 * PHPUnit bootstrap for the Link Purpose Icons plugin.
 *
 * Uses wp-phpunit/wp-phpunit (vendored via Composer) for the WordPress core
 * test library, pointed at tests/wp-tests-config.php for the database and
 * ABSPATH (the dev WordPress install at /var/www/html in the ddev
 * container).
 *
 * @package LinkPurpose
 */

$linkpurpose_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $linkpurpose_tests_dir ) {
	$linkpurpose_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! file_exists( $linkpurpose_tests_dir . '/includes/functions.php' ) ) {
	echo "wp-phpunit not found at $linkpurpose_tests_dir.\nRun `composer install` first." . PHP_EOL;
	exit( 1 );
}

if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
}

require_once $linkpurpose_tests_dir . '/includes/functions.php';

/**
 * Load the plugin before the WP environment finishes bootstrapping.
 */
function linkpurpose_tests_load_plugin() {
	require_once dirname( __DIR__ ) . '/linkpurpose.php';
}
tests_add_filter( 'muplugins_loaded', 'linkpurpose_tests_load_plugin' );

require $linkpurpose_tests_dir . '/includes/bootstrap.php';

if ( ! is_multisite() ) {
	echo PHP_EOL;
	echo '⚠  Single-site run: network-override tests that need is_multisite() are SKIPPED.' . PHP_EOL;
	echo '   Run them with:  env WP_MULTISITE=1 vendor/bin/phpunit   (composer phpunit-multisite)' . PHP_EOL . PHP_EOL;
}
