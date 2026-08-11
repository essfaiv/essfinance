<?php
/**
 * Migration Script for EssFinance v0.2.9
 *
 * This script ensures consistency by adding minus sign to all amounts:
 * - All amounts will be stored as negative by default
 * - Income entries (few) should be manually edited to remove the minus sign
 *
 * Usage with WP CLI:
 *   wp eval-file wp-content/plugins/essfinance/migrate-0.2.9.php
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
$skipped = 0;

echo "Starting migration of EssFinance entries to v0.2.9 (ensuring all with -)...\n";
echo "Total entries to process: " . count( $entries ) . "\n\n";

foreach ( $entries as $post ) {
	$amount = (float) $post->post_content;

	// Check if already negative (correct format)
	if ( $amount < 0 ) {
		$skipped++;
		continue;
	}

	// Convert to negative (expense format)
	$new_amount_value = -abs( $amount );
	$new_amount = migrate_format_amount( $new_amount_value );

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

	$migrated++;
	$amount_display = abs( $new_amount_value );
	$amount_formatted = migrate_format_amount( $new_amount_value );

	echo sprintf(
		"✓ Entry #%d: %s - %s (Converted to: -%s)\n",
		$post->ID,
		$post->post_title,
		date( 'Y-m-d', strtotime( $post->post_date_gmt ) ),
		$amount_formatted
	);
}

echo "\n" . str_repeat( '=', 60 ) . "\n";
echo "Migration Complete!\n";
echo "Entries migrated: $migrated\n";
echo "Entries already correct: $skipped\n";
echo "\nNext step: Manually edit income entries to remove the minus sign.\n";

if ( ! empty( $errors ) ) {
	echo "\nErrors encountered:\n";
	foreach ( $errors as $error ) {
		echo "✗ $error\n";
	}
}

echo str_repeat( '=', 60 ) . "\n";
?>
