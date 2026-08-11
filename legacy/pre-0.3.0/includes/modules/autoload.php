<?php
/**
 * Modules - Optional extensions for EssFinance
 *
 * See README.md in this folder to learn how to create new modules
 */

defined( 'ABSPATH' ) || exit;

// Load all .php files in this folder (except autoload.php and README.md)
$modules_dir = dirname( __FILE__ );
$files = glob( $modules_dir . '/*.php' );

foreach ( $files as $file ) {
	if ( basename( $file ) !== 'autoload.php' ) {
		require_once $file;
	}
}
