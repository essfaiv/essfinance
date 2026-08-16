<?php
/**
 * EssFinance — Meta Boxes
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class ESSF_Meta_Boxes {

	const NONCE = 'essf_meta_nonce';

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'register' ] );
		add_action( 'save_post_essf_cashflow', [ $this, 'save' ] );
	}

	public function register() {
		add_meta_box(
			'essf_entry_details',
			__( 'Entry Details', 'essfinance' ),
			[ $this, 'render' ],
			'essf_cashflow',
			'normal',
			'high'
		);
	}

	public function render( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$amount   = (float) $post->post_content;
		$is_inc   = $amount > 0;
		$due_date = '0000-00-00' !== substr( $post->post_date_gmt, 0, 10 ) ? substr( $post->post_date_gmt, 0, 10 ) : '';
		$pay_date = '0000-00-00' !== substr( $post->post_modified_gmt, 0, 10 ) ? substr( $post->post_modified_gmt, 0, 10 ) : '';
		$status   = $post->post_status;

		$entry_terms = wp_get_post_terms( $post->ID, ESSF_Category::TAXONOMY, [ 'fields' => 'slugs' ] );
		// A brand-new/unsaved post has no terms yet — 'uncategorized' is a
		// canonical key, not necessarily a live slug, so normalize it to
		// match against $term->slug below.
		$category = $entry_terms[0] ?? ESSF_Category::normalize_slug( 'uncategorized' );
		?>
		<div class="essf-meta-box">
			<div class="essf-field">
				<label for="essf_description"><?php esc_html_e( 'Description', 'essfinance' ); ?></label>
				<input type="text" id="essf_description" name="essf_description"
					value="<?php echo esc_attr( $post->post_title ); ?>"
					placeholder="<?php esc_attr_e( 'Description', 'essfinance' ); ?>">
			</div>

			<div class="essf-field-row">
				<div class="essf-field">
					<label for="essf_due_date"><?php esc_html_e( 'Due Date', 'essfinance' ); ?></label>
					<input type="date" id="essf_due_date" name="essf_due_date"
						value="<?php echo esc_attr( $due_date ); ?>">
				</div>
				<div class="essf-field">
					<label for="essf_pay_date"><?php esc_html_e( 'Pay Date', 'essfinance' ); ?></label>
					<input type="date" id="essf_pay_date" name="essf_pay_date"
						value="<?php echo esc_attr( $pay_date ); ?>">
				</div>
			</div>

			<div class="essf-field-row essf-field-row--amount">
				<div class="essf-field essf-field--amount">
					<label for="essf_amount"><?php esc_html_e( 'Amount', 'essfinance' ); ?></label>
					<input type="number" id="essf_amount" name="essf_amount"
						value="<?php echo esc_attr( (string) abs( $amount ) ); ?>" step="0.01" min="0" placeholder="0.00">
				</div>
				<div class="essf-field essf-field--income-check">
					<label class="essf-income-label">
						<input type="checkbox" name="essf_is_income" value="1" <?php checked( $is_inc ); ?>>
						<?php esc_html_e( 'Income', 'essfinance' ); ?>
					</label>
				</div>
			</div>

			<div class="essf-field">
				<label for="essf_status"><?php esc_html_e( 'Status', 'essfinance' ); ?></label>
				<select id="essf_status" name="essf_status">
					<?php foreach ( ESSF_CPT::$statuses as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $status, $val ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="essf-field">
				<label for="essf_category"><?php esc_html_e( 'Category', 'essfinance' ); ?></label>
				<select id="essf_category" name="essf_category">
					<?php foreach ( ESSF_Category::get_ordered_terms() as $term ) : ?>
						<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $category, $term->slug ); ?>>
							<?php echo esc_html( $term->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>
		<?php
	}

	public function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$desc      = sanitize_text_field( wp_unslash( $_POST['essf_description'] ?? '' ) );
		$amount_in = (float) sanitize_text_field( wp_unslash( $_POST['essf_amount'] ?? '' ) );
		$is_income = isset( $_POST['essf_is_income'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['essf_is_income'] ) );
		$amount    = $is_income ? abs( $amount_in ) : -abs( $amount_in );

		$due_raw = sanitize_text_field( wp_unslash( $_POST['essf_due_date'] ?? '' ) );
		$pay_raw = sanitize_text_field( wp_unslash( $_POST['essf_pay_date'] ?? '' ) );
		$due_dt  = $due_raw ? DateTime::createFromFormat( 'Y-m-d', $due_raw ) : false;
		$pay_dt  = $pay_raw ? DateTime::createFromFormat( 'Y-m-d', $pay_raw ) : false;
		$due_gmt = $due_dt ? $due_dt->format( 'Y-m-d' ) . ' 00:00:00' : '0000-00-00 00:00:00';
		$pay_gmt = $pay_dt ? $pay_dt->format( 'Y-m-d' ) . ' 00:00:00' : '0000-00-00 00:00:00';

		$status = sanitize_key( wp_unslash( $_POST['essf_status'] ?? 'pending' ) );
		if ( ! array_key_exists( $status, ESSF_CPT::$statuses ) ) {
			$status = 'pending';
		}

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[
				'post_title'        => $desc,
				'post_content'      => (string) $amount,
				'post_status'       => $status,
				'post_date_gmt'     => $due_gmt,
				'post_modified_gmt' => $pay_gmt,
			],
			[ 'ID' => $post_id ],
			[ '%s', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		$order_date = '0000-00-00 00:00:00' !== $pay_gmt ? $pay_gmt : $due_gmt;
		update_post_meta( $post_id, '_order_date', substr( $order_date, 0, 10 ) );

		$category = sanitize_key( wp_unslash( $_POST['essf_category'] ?? 'uncategorized' ) );
		wp_set_post_terms( $post_id, [ ESSF_Category::term_id_for_slug( $category ) ], ESSF_Category::TAXONOMY );
	}
}
