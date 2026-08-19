<?php
/**
 * EssFinance — Dedicated listing for Bills/Loans/Financing
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/**
 * The only dedicated admin page this feature has — everything else about
 * Bills/Loans/Financing is native taxonomy screens (term list + term edit).
 * The virtual/real occurrence tables used to live inside the term edit
 * form; they're big enough (and conceptually separate enough from editing
 * the plan's config) to warrant their own screen. Hidden from the menu
 * (parent `''`, same technique as the OFX/Category Glossary pages) —
 * reached only via the "History"/"Installments"/"Payments" row action or
 * link on each taxonomy's own screens.
 */
class ESSF_Recurrence_Entries_Page {

	const SLUG = 'essfinance-recurrence-entries';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
	}

	public function register_menu(): void {
		// A '' parent means get_admin_page_title() can't find this page's
		// title (it only walks top-level $menu entries when the parent is
		// empty) and leaves $title unset, which trips a strip_tags( null )
		// deprecation notice in wp-admin/admin-header.php — same issue
		// ESSF_Admin_Page's OFX/Category Glossary pages hit, same fix:
		// pre-set it here, before admin-header.php ever loads.
		if ( isset( $_GET['page'] ) && self::SLUG === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$term_id          = absint( $_GET['term_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$taxonomy         = isset( $_GET['taxonomy'] ) ? sanitize_key( $_GET['taxonomy'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$term             = $term_id && $taxonomy ? get_term( $term_id, $taxonomy ) : null;
			$GLOBALS['title'] = ( $term && ! is_wp_error( $term ) ) ? $term->name : __( 'Entries', 'essfinance' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		add_submenu_page( '', __( 'Entries', 'essfinance' ), '', 'manage_options', self::SLUG, [ $this, 'render' ] );
	}

	public static function url( string $taxonomy, int $term_id ): string {
		return add_query_arg(
			[
				'page'     => self::SLUG,
				'taxonomy' => $taxonomy,
				'term_id'  => $term_id,
			],
			admin_url( 'admin.php' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( $_GET['taxonomy'] ) : '';
		$term_id  = absint( $_GET['term_id'] ?? 0 );

		$known = [ ESSF_Bill_CPT::TAXONOMY, ESSF_Loan_CPT::TAXONOMY, ESSF_Financing_CPT::TAXONOMY ];
		if ( ! in_array( $taxonomy, $known, true ) ) {
			wp_die( esc_html__( 'Unknown taxonomy.', 'essfinance' ) );
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			wp_die( esc_html__( 'Not found.', 'essfinance' ) );
		}

		$back_url = add_query_arg( [ 'taxonomy' => $taxonomy ], admin_url( 'edit-tags.php' ) );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $term->name ); ?></h1>
			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">&larr; <?php esc_html_e( 'Back', 'essfinance' ); ?></a>
			<hr class="wp-header-end">
			<?php
			switch ( $taxonomy ) {
				case ESSF_Bill_CPT::TAXONOMY:
					ESSF_Bill_CPT::render_history_table( $term );
					ESSF_Bill_CPT::render_launch_widget( $term );
					break;
				case ESSF_Financing_CPT::TAXONOMY:
					ESSF_Financing_CPT::render_installments_table_for_term( $term );
					break;
				case ESSF_Loan_CPT::TAXONOMY:
					$principal = (float) get_term_meta( $term->term_id, ESSF_Loan_CPT::META_PRINCIPAL, true );
					ESSF_Loan_CPT::render_payments_summary( $term, $principal );
					break;
			}
			?>
		</div>
		<?php
	}
}
