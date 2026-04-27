<?php
/**
 * EssFinance — Uninstall
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$entries = get_posts(
	[
		'post_type'      => 'essf_cashflow',
		'posts_per_page' => -1,
		'post_status'    => 'any',
		'fields'         => 'ids',
	]
);

foreach ( $entries as $entry_id ) {
	wp_delete_post( $entry_id, true );
}
