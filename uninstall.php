<?php
/**
 * EssFinance — Uninstall
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$essf_post_types = [ 'essf_cashflow' ];

foreach ( $essf_post_types as $essf_post_type ) {
	$entries = get_posts(
		[
			'post_type'      => $essf_post_type,
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'fields'         => 'ids',
		]
	);

	foreach ( $entries as $entry_id ) {
		wp_delete_post( $entry_id, true );
	}
}

$essf_taxonomies = [ 'essf_bill_cat', 'essf_loan_cat', 'essf_financing_cat' ];

foreach ( $essf_taxonomies as $essf_taxonomy ) {
	$term_ids = get_terms(
		[
			'taxonomy'   => $essf_taxonomy,
			'hide_empty' => false,
			'fields'     => 'ids',
		]
	);
	if ( ! is_wp_error( $term_ids ) ) {
		foreach ( $term_ids as $term_id ) {
			wp_delete_term( $term_id, $essf_taxonomy );
		}
	}
}

wp_clear_scheduled_hook( 'essf_recurrence_sweep' );
