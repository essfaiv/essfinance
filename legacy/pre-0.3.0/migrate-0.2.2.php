<?php
/**
 * Migration Script for EssFinance v0.2.2
 *
 * This script migrates data to the v0.2.2 format:
 * - Income: stored as positive (e.g., 8000 or 8000.50)
 * - Expense: stored as negative (e.g., -750 or -750.50)
 * - Decimals omitted if integer value
 *
 * Usage with WP CLI:
 *   wp eval-file wp-content/plugins/essfinance/migrate-0.2.2.php
 *
 * Or via wp-cli command:
 *   wp plugin exec essfinance wp-content/plugins/essfinance/migrate-0.2.2.php
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

	// Add sign if negative
	if ( $amount_float < 0 ) {
		return '-' . $formatted;
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

echo "Starting migration of EssFinance entries to v0.2.2...\n";
echo "Total entries to process: " . count( $entries ) . "\n\n";

foreach ( $entries as $post ) {
	$amount = (float) $post->post_content;

	// Infer type from amount sign only
	$entry_type = $amount < 0 ? 'expense' : 'income';

	// Correct amount based on entry_type
	$should_be_negative = ( 'expense' === $entry_type );
	$is_negative = ( $amount < 0 );

	// If sign is wrong, correct it
	if ( $should_be_negative !== $is_negative ) {
		$new_amount_value = ( 'expense' === $entry_type ) ? -abs( $amount ) : abs( $amount );
		$new_amount = migrate_format_amount( $new_amount_value );

		// Update post_content with corrected amount
		$result = wp_update_post( array(
			'ID'           => $post->ID,
			'post_content' => $new_amount,
		) );

		if ( is_wp_error( $result ) ) {
			$errors[] = sprintf(
				'Entry #%d (%s): %s',
				$post->ID,
				$post->post_title,
				$result->get_error_message()
			);
			continue;
		}

		$amount = $new_amount_value;
	}

	// Delete _entry_type metadata (no longer needed)
	delete_post_meta( $post->ID, '_entry_type' );

	$migrated++;
	$amount_display = abs( $amount );
	$amount_formatted = migrate_format_amount( $amount );

	echo sprintf(
		"✓ Entry #%d: %s - %s (Type: %s, Amount: %s%s)\n",
		$post->ID,
		$post->post_title,
		date( 'Y-m-d', strtotime( $post->post_date_gmt ) ),
		strtoupper( $entry_type ),
		( 'income' === $entry_type ) ? '+' : '-',
		$amount_display
	);
}

echo "\n" . str_repeat( '=', 60 ) . "\n";
echo "Migration Complete!\n";
echo "Entries migrated: $migrated\n";

if ( ! empty( $errors ) ) {
	echo "\nErrors encountered:\n";
	foreach ( $errors as $error ) {
		echo "✗ $error\n";
	}
}

echo str_repeat( '=', 60 ) . "\n";
?>
