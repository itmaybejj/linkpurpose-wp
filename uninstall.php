<?php
/**
 * Uninstall handler: removes every option the plugin writes, on every site.
 *
 * WordPress loads this file on its own (the plugin's main file is NOT
 * loaded), so the autoloader has to be re-registered here.
 *
 * @package LinkPurpose
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Installer.php';

\LinkPurpose\Installer::uninstall();
