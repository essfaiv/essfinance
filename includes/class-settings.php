<?php
defined( 'ABSPATH' ) || exit;

class ESSF_Settings {

	const OPTION_STATUS_BADGE    = 'essf_show_status_badge';
	const OPTION_STATUS_ICONS    = 'essf_show_status_icons';
	const OPTION_AMOUNT_COLORS   = 'essf_show_amount_colors';
	const OPTION_NEGATIVE_PREFIX = 'essf_show_negative_prefix';

	private static array $icons = [
		'paid'    => 'dashicons-yes-alt',
		'pending' => 'dashicons-clock',
		'overdue' => 'dashicons-warning',
	];

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( ESSF_PATH . 'essfinance.php' ), [ $this, 'action_links' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			null,
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
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		] );

		register_setting( 'essf_settings', self::OPTION_STATUS_ICONS, [
			'type'              => 'boolean',
			'default'           => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
		] );

		register_setting( 'essf_settings', self::OPTION_AMOUNT_COLORS, [
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		] );

		register_setting( 'essf_settings', self::OPTION_NEGATIVE_PREFIX, [
			'type'              => 'boolean',
			'default'           => false,
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

		add_settings_field(
			self::OPTION_STATUS_ICONS,
			__( 'Show icons in Status', 'essfinance' ),
			[ $this, 'field_status_icons' ],
			'essf_settings',
			'essf_display'
		);

		add_settings_field(
			self::OPTION_AMOUNT_COLORS,
			__( 'Show amount with colors', 'essfinance' ),
			[ $this, 'field_amount_colors' ],
			'essf_settings',
			'essf_display'
		);

		add_settings_field(
			self::OPTION_NEGATIVE_PREFIX,
			__( 'Show negative prefix', 'essfinance' ),
			[ $this, 'field_negative_prefix' ],
			'essf_settings',
			'essf_display'
		);
	}

	public function field_status_badge(): void {
		$value = get_option( self::OPTION_STATUS_BADGE, false );
		?>
		<label>
			<input type="checkbox" id="essf_badge_toggle" name="<?php echo esc_attr( self::OPTION_STATUS_BADGE ); ?>" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Display entry status as a colored badge in the list', 'essfinance' ); ?>
		</label>
		<script>
		( function () {
			var badge = document.getElementById( 'essf_badge_toggle' );
			var iconRow = document.getElementById( 'essf_icons_row' );
			function toggle() { iconRow.style.display = badge.checked ? 'none' : ''; }
			badge.addEventListener( 'change', toggle );
			toggle();
		} )();
		</script>
		<?php
	}

	public function field_status_icons(): void {
		$value = get_option( self::OPTION_STATUS_ICONS, true );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_STATUS_ICONS ); ?>" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Show a dashicon before the status label', 'essfinance' ); ?>
		</label>
		<script>
		document.querySelector( 'tr:has(#essf_icons_row), #essf_icons_row' );
		( function () {
			var row = document.currentScript.closest( 'tr' );
			if ( row ) row.id = 'essf_icons_row';
		} )();
		</script>
		<?php
	}

	public function field_amount_colors(): void {
		$value = get_option( self::OPTION_AMOUNT_COLORS, false );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_AMOUNT_COLORS ); ?>" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Colorize income and expense amounts', 'essfinance' ); ?>
		</label>
		<?php
	}

	public function field_negative_prefix(): void {
		$value = get_option( self::OPTION_NEGATIVE_PREFIX, false );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NEGATIVE_PREFIX ); ?>" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Show − sign before negative amounts', 'essfinance' ); ?>
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
		$url = esc_url( admin_url( 'admin.php?page=essfinance-settings' ) );
		array_unshift( $links, '<a href="' . $url . '">' . __( 'Settings', 'essfinance' ) . '</a>' );
		return $links;
	}

	public static function show_status_badge(): bool {
		return (bool) get_option( self::OPTION_STATUS_BADGE, false );
	}

	public static function show_status_icons(): bool {
		return (bool) get_option( self::OPTION_STATUS_ICONS, true );
	}

	public static function show_amount_colors(): bool {
		return (bool) get_option( self::OPTION_AMOUNT_COLORS, false );
	}

	public static function show_negative_prefix(): bool {
		return (bool) get_option( self::OPTION_NEGATIVE_PREFIX, false );
	}

	public static function status_icon( string $status ): string {
		$icon = self::$icons[ $status ] ?? '';
		if ( ! $icon ) {
			return '';
		}
		return '<span class="dashicons ' . esc_attr( $icon ) . '" title="' . esc_attr( ucfirst( $status ) ) . '"></span> ';
	}
}
