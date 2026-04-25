<?php
defined( 'ABSPATH' ) || exit;

class ESSF_Settings {

	const OPTION_STATUS_BADGE = 'essf_show_status_badge';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( ESSF_PATH . 'essfinance.php' ), [ $this, 'action_links' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'essfinance',
			__( 'EssFinance Settings', 'essfinance' ),
			__( 'Settings', 'essfinance' ),
			'manage_options',
			'essfinance-settings',
			[ $this, 'render' ]
		);
	}

	public function register_settings(): void {
		register_setting( 'essf_settings', self::OPTION_STATUS_BADGE, [
			'type'              => 'boolean',
			'default'           => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
		] );

		add_settings_section( 'essf_display', __( 'Display', 'essfinance' ), '__return_false', 'essf_settings' );

		add_settings_field(
			self::OPTION_STATUS_BADGE,
			__( 'Show status as badge', 'essfinance' ),
			[ $this, 'field_status_badge' ],
			'essf_settings',
			'essf_display'
		);
	}

	public function field_status_badge(): void {
		$value = get_option( self::OPTION_STATUS_BADGE, true );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_STATUS_BADGE ); ?>" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Display entry status as a colored badge in the list', 'essfinance' ); ?>
		</label>
		<?php
	}

	public function render(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'EssFinance Settings', 'essfinance' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'essf_settings' );
				do_settings_sections( 'essf_settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function action_links( array $links ): array {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=essfinance-settings' ) ) . '">' . __( 'Settings', 'essfinance' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	public static function show_status_badge(): bool {
		return (bool) get_option( self::OPTION_STATUS_BADGE, true );
	}
}
