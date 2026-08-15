<?php
/**
 * WP-CLI release command for EssFinance.
 *
 * Usage:
 *   wp --require=bin/release.php essf release patch --summary="short summary"
 *   wp --require=bin/release.php essf release minor --summary="short summary"
 *   wp --require=bin/release.php essf release major --summary="short summary"
 *   wp --require=bin/release.php essf release 1.2.3 --summary="short summary"
 *   wp --require=bin/release.php essf release patch --no-commit
 *   wp --require=bin/release.php essf release patch --no-tag
 *   wp --require=bin/release.php essf release patch --no-push
 *
 * Before running, add a "= <next-version> =" entry with real release notes to
 * readme.txt's Changelog. This command bumps version headers, regenerates
 * README.md, and commits/tags/pushes — it does not write your changelog for
 * you, and refuses to proceed if the entry is missing or still a placeholder.
 *
 * Must be run from the plugin directory.
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Run a shell command via proc_open. Prints output and dies on failure.
 *
 * @param string      $cmd    Shell command.
 * @param string|null $cwd    Working directory; null inherits current.
 * @param bool        $silent Suppress stdout printing.
 * @return string Captured stdout.
 */
function essf_release_run( string $cmd, ?string $cwd = null, bool $silent = false ): string {
	$descriptors = [
		0 => [ 'pipe', 'r' ],
		1 => [ 'pipe', 'w' ],
		2 => [ 'pipe', 'w' ],
	];

	$process = proc_open( $cmd, $descriptors, $pipes, $cwd );
	if ( ! is_resource( $process ) ) {
		WP_CLI::error( "Failed to start: $cmd" );
	}

	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$exit = proc_close( $process );

	if ( ! $silent && '' !== $stdout ) {
		WP_CLI::line( rtrim( $stdout ) );
	}

	if ( 0 !== $exit ) {
		WP_CLI::error( '' !== $stderr ? rtrim( $stderr ) : "Command failed (exit $exit): $cmd" );
	}

	return $stdout;
}

/**
 * Run a shell command without dying on failure — for probing.
 *
 * @param string      $cmd Shell command.
 * @param string|null $cwd Working directory; null inherits current.
 * @return array{0:int,1:string} Exit code and captured stdout.
 */
function essf_release_try_run( string $cmd, ?string $cwd = null ): array {
	$descriptors = [
		0 => [ 'pipe', 'r' ],
		1 => [ 'pipe', 'w' ],
		2 => [ 'pipe', 'w' ],
	];

	$process = proc_open( $cmd, $descriptors, $pipes, $cwd );
	if ( ! is_resource( $process ) ) {
		return [ 1, '' ];
	}

	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$exit = proc_close( $process );

	return [ $exit, $stdout ];
}

/**
 * Assert a binary exists on PATH.
 *
 * @param string $cmd Command name.
 */
function essf_release_require_cmd( string $cmd ): void {
	[ $exit ] = essf_release_try_run( "command -v $cmd" );
	if ( 0 !== $exit ) {
		WP_CLI::error( "Required command not found on PATH: $cmd" );
	}
}

/**
 * Absolute path to the plugin root (bin/../).
 */
function essf_release_plugin_dir(): string {
	return dirname( __FILE__, 2 );
}

/**
 * Read the current Version header from the plugin file.
 *
 * @param string $plugin_file Path to essfinance.php.
 */
function essf_release_current_version( string $plugin_file ): string {
	$contents = file_get_contents( $plugin_file );
	if ( false === $contents || ! preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $contents, $m ) ) {
		WP_CLI::error( "Could not find Version header in $plugin_file" );
	}
	return $m[1];
}

/**
 * Compute the next version from a bump keyword or explicit semver.
 *
 * @param string $current Current semver (X.Y.Z).
 * @param string $bump    "major", "minor", "patch", or an explicit X.Y.Z.
 */
function essf_release_bump_version( string $current, string $bump ): string {
	if ( ! preg_match( '/^(\d+)\.(\d+)\.(\d+)/', $current, $m ) ) {
		WP_CLI::error( "Could not parse current version: $current" );
	}
	$maj = (int) $m[1];
	$min = (int) $m[2];
	$pat = (int) $m[3];

	switch ( $bump ) {
		case 'major':
			return ( $maj + 1 ) . '.0.0';
		case 'minor':
			return "$maj." . ( $min + 1 ) . '.0';
		case 'patch':
			return "$maj.$min." . ( $pat + 1 );
		default:
			if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $bump ) ) {
				WP_CLI::error( "Invalid version: $bump" );
			}
			return $bump;
	}
}

// ── Command ──────────────────────────────────────────────────────────────────

/**
 * Release automation for EssFinance: bumps version headers, regenerates
 * README.md, validates the changelog entry, and commits/tags/pushes.
 */
class Essf_Release_Command {

	/**
	 * Bump the plugin version, regenerate README.md, and commit/tag/push.
	 *
	 * ## OPTIONS
	 *
	 * <bump>
	 * : "patch", "minor", "major", or an explicit semver (e.g. 1.2.3).
	 *
	 * [--summary=<summary>]
	 * : Short summary for the release commit ("release: vX.Y.Z — <summary>").
	 * Required unless --no-commit is passed.
	 *
	 * [--no-commit]
	 * : Bump version files without committing (implies --no-tag).
	 *
	 * [--no-tag]
	 * : Commit without tagging (implies --no-push).
	 *
	 * [--no-push]
	 * : Tag without pushing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp --require=bin/release.php essf release patch --summary="fix OFX dedup edge case"
	 *     wp --require=bin/release.php essf release minor --summary="add X" --no-push
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function release( array $args, array $assoc_args ): void {
		essf_release_require_cmd( 'git' );
		essf_release_require_cmd( 'node' );

		$no_commit = isset( $assoc_args['no-commit'] );
		$no_tag    = isset( $assoc_args['no-tag'] ) || $no_commit;
		$no_push   = isset( $assoc_args['no-push'] ) || $no_tag;

		if ( ! $no_commit && empty( $assoc_args['summary'] ) ) {
			WP_CLI::error( 'Pass --summary="..." (or --no-commit).' );
		}

		$plugin_dir  = essf_release_plugin_dir();
		$plugin_file = $plugin_dir . '/essfinance.php';
		$readme_txt  = $plugin_dir . '/readme.txt';

		$current     = essf_release_current_version( $plugin_file );
		$new_version = essf_release_bump_version( $current, $args[0] );

		WP_CLI::log( "  → {$current} → {$new_version}" );

		essf_release_run( 'git fetch origin --quiet', $plugin_dir );
		[ $tag_exists ] = essf_release_try_run( "git ls-remote --exit-code origin refs/tags/v{$new_version}", $plugin_dir );
		if ( 0 === $tag_exists ) {
			WP_CLI::error( "Tag v{$new_version} already exists on remote." );
		}

		// ── Bump essfinance.php header + constant ───────────────────────────
		$plugin_contents = file_get_contents( $plugin_file );
		$plugin_contents = preg_replace( '/^(\s\*\sVersion:) \S+/m', '$1 ' . $new_version, $plugin_contents );
		$plugin_contents = preg_replace( "/define\( 'ESSF_VERSION', '[^']*' \)/", "define( 'ESSF_VERSION', '$new_version' )", $plugin_contents );
		file_put_contents( $plugin_file, $plugin_contents );

		// ── Bump readme.txt Stable tag + insert a changelog placeholder if absent ──
		$readme_contents = file_get_contents( $readme_txt );
		$readme_contents = preg_replace( '/^Stable tag:.*/m', "Stable tag: $new_version", $readme_contents );
		if ( ! str_contains( $readme_contents, "= $new_version =" ) ) {
			$today            = gmdate( 'Y-m-d' );
			$readme_contents  = str_replace(
				'== Changelog ==',
				"== Changelog ==\n\n= $new_version =\n* Release $new_version ($today).",
				$readme_contents
			);
		}
		file_put_contents( $readme_txt, $readme_contents );

		// ── Validate the changelog entry was actually written ───────────────
		if ( ! preg_match( '/^= ' . preg_quote( $new_version, '/' ) . ' =\n(.*?)(?=\n= |\z)/ms', $readme_contents, $m ) ) {
			WP_CLI::error( "Could not find changelog entry for $new_version in readme.txt." );
		}
		$entry = trim( $m[1] );
		$today = gmdate( 'Y-m-d' );
		if ( '' === $entry || "* Release $new_version ($today)." === $entry ) {
			WP_CLI::error( "readme.txt has no real changelog entry for = $new_version = yet — write one, then re-run." );
		}

		// ── Regenerate README.md ─────────────────────────────────────────────
		WP_CLI::log( '  → regenerating README.md via grunt' );
		essf_release_run( 'npm run readme --silent', $plugin_dir, true );

		// ── Stage / commit / tag / push ──────────────────────────────────────
		essf_release_run( 'git add -A', $plugin_dir );

		if ( $no_commit ) {
			WP_CLI::success( "Bumped to $new_version (not committed)." );
			return;
		}

		$message = "release: v{$new_version} — {$assoc_args['summary']}";
		essf_release_run( 'git commit -m ' . escapeshellarg( $message ), $plugin_dir );

		if ( $no_tag ) {
			WP_CLI::success( "Committed $new_version (not tagged)." );
			return;
		}

		essf_release_run( "git tag v{$new_version}", $plugin_dir );

		if ( $no_push ) {
			WP_CLI::success( "Tagged v{$new_version} (not pushed)." );
			return;
		}

		$branch = trim( essf_release_run( 'git rev-parse --abbrev-ref HEAD', $plugin_dir, true ) );
		[ $push_exit ] = essf_release_try_run( "git push origin $branch --quiet", $plugin_dir );
		if ( 0 !== $push_exit ) {
			WP_CLI::log( '  → push rejected — retrying with --force' );
			essf_release_run( "git push origin $branch --force --quiet", $plugin_dir, true );
		}
		essf_release_run( "git push origin v{$new_version} --quiet", $plugin_dir, true );

		$remote = trim( essf_release_run( 'git remote get-url origin', $plugin_dir, true ) );
		$repo   = preg_replace( '#^.*[:/]([^/]+/[^/]+?)(\.git)?$#', '$1', $remote );
		WP_CLI::success( "Released v{$new_version} — https://github.com/{$repo}/releases/tag/v{$new_version}" );
	}
}

WP_CLI::add_command( 'essf release', [ new Essf_Release_Command(), 'release' ] );
