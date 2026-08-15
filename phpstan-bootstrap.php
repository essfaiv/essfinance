<?php
/**
 * PHPStan bootstrap — defines plugin constants for static analysis.
 * Not loaded at runtime; only used by PHPStan.
 */
define( 'ESSF_VERSION', '0.0.0' );
define( 'ESSF_PATH', __DIR__ . '/' );
define( 'ESSF_URL', 'http://example.com/' );

// selfd() is defined by the optional lib/selfdirectory submodule, which isn't
// checked out in every context (see essfinance.php's file_exists() guard).
// Stub it so analysis doesn't depend on the submodule being present.
if ( ! function_exists( 'selfd' ) ) {
	function selfd( string $file ): void {}
}
