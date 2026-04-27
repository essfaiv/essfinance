#!/usr/bin/env php
<?php
/**
 * Configure the repository to use the project's git hooks directory.
 *
 * Run automatically via: composer install / composer update
 * Or manually: php bin/install-hooks.php
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

// Skip silently in CI environments (GitHub Actions, etc. set CI=true).
if ( getenv( 'CI' ) ) {
    exit( 0 );
}

// The git root is the plugin directory itself.
$root = dirname( __DIR__ );

if ( ! is_dir( $root . '/.git' ) ) {
    echo "⚠  Could not find .git directory. Skipping hook installation.\n";
    exit( 0 );
}

$hooks_dir = $root . '/.githooks';

if ( ! is_dir( $hooks_dir ) ) {
    echo "⚠  .githooks/ directory not found in {$root}. Skipping.\n";
    exit( 0 );
}

// Point git at the project hooks directory.
passthru( sprintf( 'git -C %s config core.hooksPath .githooks', escapeshellarg( $root ) ), $status );

if ( 0 !== $status ) {
    echo "✗  Failed to set core.hooksPath.\n";
    exit( 1 );
}

// Ensure the hook scripts are executable.
foreach ( glob( $hooks_dir . '/*' ) as $hook ) {
    chmod( $hook, 0755 );
}

echo "✔  Git hooks installed (core.hooksPath → .githooks/).\n";
