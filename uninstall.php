<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$entries = get_posts( [
	'post_type'      => 'essf_cashflow',
	'posts_per_page' => -1,
	'post_status'    => 'any',
	'fields'         => 'ids',
] );

foreach ( $entries as $id ) {
	wp_delete_post( $id, true );
}
