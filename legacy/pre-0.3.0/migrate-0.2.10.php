<?php
/**
 * Migration Script for EssFinance v0.2.10
 *
 * This script ensures data consistency:
 * - Removes all non-numeric characters (except dots for decimals)
 * - Adds minus sign (-) to all amounts (marks as expense by default)
 * - Income entries will have no sign (positive) and must be manually edited
 *
 * Logic:
 * - amount > 0 = income
 * - amount <= 0 = expense
 *
 * Usage with WP CLI:
 *   wp eval-file wp-content/plugins/essfinance/migrate-0.2.10.php
 */

if ( ! function_exists( 'get_posts' ) ) {
	wp_die( 'This script must be run within WordPress.' );
}

// Helper function to clean and format amount
function migrate_clean_amount( $amount_str ) {
	// Remove all non-numeric characters except dots
	$cleaned = preg_replace( '/[^0-9.]/', '', $amount_str );

	// Convert to float
	$amount_float = (float) $cleaned;

	// Format: omit decimals if integer, convert .X0 to .X
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

echo "Starting migration of EssFinance entries to v0.2.10 (clean + - prefix)...\n";
echo "Total entries to process: " . count( $entries ) . "\n\n";

foreach ( $entries as $post ) {
	$original_amount = $post->post_content;
	$amount = (float) $original_amount;

	// Check if already in correct format (negative number, no extra chars)
	$cleaned = migrate_clean_amount( $original_amount );
	$new_amount_value = -abs( (float) $cleaned );

	// Format final amount
	if ( $new_amount_value == (int) $new_amount_value ) {
		$new_amount = (string) (int) $new_amount_value;
	} else {
		$new_amount = (string) $new_amount_value;
	}

	// Check if changes are needed
	if ( $new_amount === $original_amount ) {
		$skipped++;
		continue;
	}

	// Update post_content
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

	echo sprintf(
		"✓ Entry #%d: %s - %s (Original: %s → New: %s)\n",
		$post->ID,
		$post->post_title,
		date( 'Y-m-d', strtotime( $post->post_date_gmt ) ),
		$original_amount,
		$new_amount
	);
}

echo "\n" . str_repeat( '=', 60 ) . "\n";
echo "Migration Complete!\n";
echo "Entries migrated: $migrated\n";
echo "Entries already correct: $skipped\n";
echo "\nLogic:\n";
echo "  - amount > 0  → INCOME (no sign)\n";
echo "  - amount <= 0 → EXPENSE (with minus)\n";
echo "\nNext step: Manually edit income entries to remove the minus sign.\n";

if ( ! empty( $errors ) ) {
	echo "\nErrors encountered:\n";
	foreach ( $errors as $error ) {
		echo "✗ $error\n";
	}
}

echo str_repeat( '=', 60 ) . "\n";
?>
