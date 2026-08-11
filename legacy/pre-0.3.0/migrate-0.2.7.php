<?php
/**
 * Migration Script for EssFinance v0.2.7
 *
 * This script migrates all amounts to expense format (negative values):
 * - All amounts will be stored as negative (expense)
 * - User can manually edit income entries to remove the minus sign
 * - Removes _entry_type metadata (type is now inferred from amount sign)
 *
 * Usage with WP CLI:
 *   wp eval-file wp-content/plugins/essfinance/migrate-0.2.7.php
 */

if ( ! function_exists( 'get_posts' ) ) {
	wp_die( 'This script must be run within WordPress.' );
}

// Helper function to format amount (omit .00, convert .X0 to .X)
function migrate_format_amount( $amount ) {
	$amount_float = (float) $amount;

	// If integer, return as integer string
	if ( $amount_float == (int) $amount_float ) {
		return (string) (int) $amount_float;
	}

	// Format to 2 decimals, then remove trailing zero if present
	$formatted = number_format( abs( $amount_float ), 2, '.', '' );

	// Convert .X0 to .X
	if ( substr( $formatted, -1 ) === '0' && substr( $formatted, -3, 1 ) !== '.' ) {
		$formatted = substr( $formatted, 0, -1 );
	}

	return $formatted;
}

// Get all EssFinance entries
$entries = get_posts( array(
	'post_type'   => 'essf_cashflow',
	'numberposts' => -1,
	'post_status' => array( 'pending', 'paid' ),
) );

$migrated = 0;
$errors = array();

echo "Starting migration of EssFinance entries to v0.2.7 (all as expense)...\n";
echo "Total entries to process: " . count( $entries ) . "\n\n";

foreach ( $entries as $post ) {
	$amount = (float) $post->post_content;

	// Convert all amounts to negative (expense format)
	$new_amount_value = -abs( $amount );
	$new_amount = migrate_format_amount( $new_amount_value );

	// Check if amount changed
	if ( $new_amount === (string) $amount ) {
		// Already in correct format, just clean up metadata
		delete_post_meta( $post->ID, '_entry_type' );
		continue;
	}

	// Update post_content with corrected amount
	global $wpdb;
	$result = $wpdb->update(
		$wpdb->posts,
		array( 'post_content' => $new_amount ),
		array( 'ID' => $post->ID ),
		array( '%s' ),
		array( '%d' )
	);

	if ( false === $result ) {
		$errors[] = sprintf(
			'Entry #%d (%s): Database error',
			$post->ID,
			$post->post_title
		);
		continue;
	}

	// Delete _entry_type metadata (no longer needed)
	delete_post_meta( $post->ID, '_entry_type' );

	$migrated++;
	$amount_display = abs( $new_amount_value );
	$amount_formatted = migrate_format_amount( $new_amount_value );

	echo sprintf(
		"✓ Entry #%d: %s - %s (Converted to EXPENSE: -%s)\n",
		$post->ID,
		$post->post_title,
		date( 'Y-m-d', strtotime( $post->post_date_gmt ) ),
		$amount_formatted
	);
}

echo "\n" . str_repeat( '=', 60 ) . "\n";
echo "Migration Complete!\n";
echo "Entries migrated: $migrated\n";
echo "\nNext step: Manually edit income entries to remove the minus sign.\n";
echo "Income entries will be stored without any sign (positive values).\n";

if ( ! empty( $errors ) ) {
	echo "\nErrors encountered:\n";
	foreach ( $errors as $error ) {
		echo "✗ $error\n";
	}
}

echo str_repeat( '=', 60 ) . "\n";
?>
