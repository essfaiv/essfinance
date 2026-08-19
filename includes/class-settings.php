<?php
/**
 * EssFinance — Settings
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class ESSF_Settings {

	const OPTION_STATUS_BADGE         = 'essf_show_status_badge';
	const OPTION_STATUS_ICONS         = 'essf_show_status_icons';
	const OPTION_AMOUNT_COLORS        = 'essf_show_amount_colors';
	const OPTION_POSITIVE_PREFIX      = 'essf_show_positive_prefix';
	const OPTION_NEGATIVE_PREFIX      = 'essf_show_negative_prefix';
	const OPTION_SHOW_TYPE_FILTER     = 'essf_show_type_filter';
	const OPTION_SHOW_TOTALS          = 'essf_show_totals';
	const OPTION_CURRENCY             = 'essf_currency';
	const OPTION_CURRENCY_POS         = 'essf_currency_pos';
	const OPTION_THOUSANDS_SEP        = 'essf_thousands_sep';
	const OPTION_DECIMAL_SEP          = 'essf_decimal_sep';
	const OPTION_NUM_DECIMALS         = 'essf_num_decimals';
	const OPTION_INITIAL_BALANCE      = 'essf_initial_balance';
	const OPTION_BALANCE_BASIS        = 'essf_balance_basis';
	const OPTION_SHOW_BALANCE_COLUMN  = 'essf_show_balance_column';
	const OPTION_PENDING_BALANCE_MODE = 'essf_pending_balance_mode';

	/* @var array<string,string> */
	private static $icons = [
		'paid'    => 'dashicons-yes-alt',
		'pending' => 'dashicons-clock',
		'overdue' => 'dashicons-warning',
	];

	private static function currencies(): array {
		return [
			'AED' => __( 'United Arab Emirates dirham (د.إ) — AED', 'essfinance' ),
			'AFN' => __( 'Afghan afghani (؋) — AFN', 'essfinance' ),
			'ALL' => __( 'Albanian lek (L) — ALL', 'essfinance' ),
			'AMD' => __( 'Armenian dram (AMD) — AMD', 'essfinance' ),
			'ANG' => __( 'Netherlands Antillean guilder (ƒ) — ANG', 'essfinance' ),
			'AOA' => __( 'Angolan kwanza (Kz) — AOA', 'essfinance' ),
			'ARS' => __( 'Argentine peso ($) — ARS', 'essfinance' ),
			'AUD' => __( 'Australian dollar ($) — AUD', 'essfinance' ),
			'AWG' => __( 'Aruban florin (ƒ) — AWG', 'essfinance' ),
			'AZN' => __( 'Azerbaijani manat (₼) — AZN', 'essfinance' ),
			'BAM' => __( 'Bosnia and Herzegovina convertible mark (KM) — BAM', 'essfinance' ),
			'BBD' => __( 'Barbadian dollar ($) — BBD', 'essfinance' ),
			'BDT' => __( 'Bangladeshi taka (৳) — BDT', 'essfinance' ),
			'BGN' => __( 'Bulgarian lev (лв.) — BGN', 'essfinance' ),
			'BHD' => __( 'Bahraini dinar (.د.ب) — BHD', 'essfinance' ),
			'BIF' => __( 'Burundian franc (Fr) — BIF', 'essfinance' ),
			'BMD' => __( 'Bermudian dollar ($) — BMD', 'essfinance' ),
			'BND' => __( 'Brunei dollar ($) — BND', 'essfinance' ),
			'BOB' => __( 'Bolivian boliviano (Bs.) — BOB', 'essfinance' ),
			'BRL' => __( 'Brazilian real (R$) — BRL', 'essfinance' ),
			'BSD' => __( 'Bahamian dollar ($) — BSD', 'essfinance' ),
			'BTC' => __( 'Bitcoin (₿) — BTC', 'essfinance' ),
			'BTN' => __( 'Bhutanese ngultrum (Nu) — BTN', 'essfinance' ),
			'BWP' => __( 'Botswana pula (P) — BWP', 'essfinance' ),
			'BYN' => __( 'Belarusian ruble (Br) — BYN', 'essfinance' ),
			'BZD' => __( 'Belize dollar (BZ$) — BZD', 'essfinance' ),
			'CAD' => __( 'Canadian dollar ($) — CAD', 'essfinance' ),
			'CDF' => __( 'Congolese franc (Fr) — CDF', 'essfinance' ),
			'CHF' => __( 'Swiss franc (CHF) — CHF', 'essfinance' ),
			'CLP' => __( 'Chilean peso ($) — CLP', 'essfinance' ),
			'CNY' => __( 'Chinese yuan (¥) — CNY', 'essfinance' ),
			'COP' => __( 'Colombian peso ($) — COP', 'essfinance' ),
			'CRC' => __( 'Costa Rican colón (₡) — CRC', 'essfinance' ),
			'CUC' => __( 'Cuban convertible peso ($) — CUC', 'essfinance' ),
			'CVE' => __( 'Cape Verdean escudo ($) — CVE', 'essfinance' ),
			'CZK' => __( 'Czech koruna (Kč) — CZK', 'essfinance' ),
			'DJF' => __( 'Djiboutian franc (Fr) — DJF', 'essfinance' ),
			'DKK' => __( 'Danish krone (DKK) — DKK', 'essfinance' ),
			'DOP' => __( 'Dominican peso (RD$) — DOP', 'essfinance' ),
			'DZD' => __( 'Algerian dinar (د.ج) — DZD', 'essfinance' ),
			'EGP' => __( 'Egyptian pound (EGP) — EGP', 'essfinance' ),
			'ERN' => __( 'Eritrean nakfa (Nfk) — ERN', 'essfinance' ),
			'ETB' => __( 'Ethiopian birr (Br) — ETB', 'essfinance' ),
			'EUR' => __( 'Euro (€) — EUR', 'essfinance' ),
			'FJD' => __( 'Fijian dollar ($) — FJD', 'essfinance' ),
			'FKP' => __( 'Falkland Islands pound (£) — FKP', 'essfinance' ),
			'GBP' => __( 'Pound sterling (£) — GBP', 'essfinance' ),
			'GEL' => __( 'Georgian lari (₾) — GEL', 'essfinance' ),
			'GHS' => __( 'Ghana cedi (₵) — GHS', 'essfinance' ),
			'GIP' => __( 'Gibraltar pound (£) — GIP', 'essfinance' ),
			'GMD' => __( 'Gambian dalasi (D) — GMD', 'essfinance' ),
			'GNF' => __( 'Guinean franc (Fr) — GNF', 'essfinance' ),
			'GTQ' => __( 'Guatemalan quetzal (Q) — GTQ', 'essfinance' ),
			'GYD' => __( 'Guyanese dollar ($) — GYD', 'essfinance' ),
			'HKD' => __( 'Hong Kong dollar ($) — HKD', 'essfinance' ),
			'HNL' => __( 'Honduran lempira (L) — HNL', 'essfinance' ),
			'HRK' => __( 'Croatian kuna (kn) — HRK', 'essfinance' ),
			'HTG' => __( 'Haitian gourde (G) — HTG', 'essfinance' ),
			'HUF' => __( 'Hungarian forint (Ft) — HUF', 'essfinance' ),
			'IDR' => __( 'Indonesian rupiah (Rp) — IDR', 'essfinance' ),
			'ILS' => __( 'Israeli new shekel (₪) — ILS', 'essfinance' ),
			'IMP' => __( 'Manx pound (£) — IMP', 'essfinance' ),
			'INR' => __( 'Indian rupee (₹) — INR', 'essfinance' ),
			'IQD' => __( 'Iraqi dinar (ع.د) — IQD', 'essfinance' ),
			'IRR' => __( 'Iranian rial (﷼) — IRR', 'essfinance' ),
			'IRT' => __( 'Iranian toman (تومان) — IRT', 'essfinance' ),
			'ISK' => __( 'Icelandic króna (kr.) — ISK', 'essfinance' ),
			'JEP' => __( 'Jersey pound (£) — JEP', 'essfinance' ),
			'JMD' => __( 'Jamaican dollar ($) — JMD', 'essfinance' ),
			'JOD' => __( 'Jordanian dinar (JD) — JOD', 'essfinance' ),
			'JPY' => __( 'Japanese yen (¥) — JPY', 'essfinance' ),
			'KES' => __( 'Kenyan shilling (KSh) — KES', 'essfinance' ),
			'KGS' => __( 'Kyrgyzstani som (лв) — KGS', 'essfinance' ),
			'KHR' => __( 'Cambodian riel (៛) — KHR', 'essfinance' ),
			'KMF' => __( 'Comorian franc (Fr) — KMF', 'essfinance' ),
			'KPW' => __( 'North Korean won (₩) — KPW', 'essfinance' ),
			'KRW' => __( 'South Korean won (₩) — KRW', 'essfinance' ),
			'KWD' => __( 'Kuwaiti dinar (د.ك) — KWD', 'essfinance' ),
			'KYD' => __( 'Cayman Islands dollar ($) — KYD', 'essfinance' ),
			'KZT' => __( 'Kazakhstani tenge (₸) — KZT', 'essfinance' ),
			'LAK' => __( 'Lao kip (₭) — LAK', 'essfinance' ),
			'LBP' => __( 'Lebanese pound (ل.ل) — LBP', 'essfinance' ),
			'LKR' => __( 'Sri Lankan rupee (රු) — LKR', 'essfinance' ),
			'LRD' => __( 'Liberian dollar ($) — LRD', 'essfinance' ),
			'LSL' => __( 'Lesotho loti (L) — LSL', 'essfinance' ),
			'LYD' => __( 'Libyan dinar (ل.د) — LYD', 'essfinance' ),
			'MAD' => __( 'Moroccan dirham (م.د.) — MAD', 'essfinance' ),
			'MDL' => __( 'Moldovan leu (MDL) — MDL', 'essfinance' ),
			'MGA' => __( 'Malagasy ariary (Ar) — MGA', 'essfinance' ),
			'MKD' => __( 'Macedonian denar (ден) — MKD', 'essfinance' ),
			'MMK' => __( 'Burmese kyat (K) — MMK', 'essfinance' ),
			'MNT' => __( 'Mongolian tögrög (₮) — MNT', 'essfinance' ),
			'MOP' => __( 'Macanese pataca (P) — MOP', 'essfinance' ),
			'MRU' => __( 'Mauritanian ouguiya (UM) — MRU', 'essfinance' ),
			'MUR' => __( 'Mauritian rupee (₨) — MUR', 'essfinance' ),
			'MVR' => __( 'Maldivian rufiyaa (Rf) — MVR', 'essfinance' ),
			'MWK' => __( 'Malawian kwacha (MK) — MWK', 'essfinance' ),
			'MXN' => __( 'Mexican peso ($) — MXN', 'essfinance' ),
			'MYR' => __( 'Malaysian ringgit (RM) — MYR', 'essfinance' ),
			'MZN' => __( 'Mozambican metical (MT) — MZN', 'essfinance' ),
			'NAD' => __( 'Namibian dollar (N$) — NAD', 'essfinance' ),
			'NGN' => __( 'Nigerian naira (₦) — NGN', 'essfinance' ),
			'NIO' => __( 'Nicaraguan córdoba (C$) — NIO', 'essfinance' ),
			'NOK' => __( 'Norwegian krone (kr) — NOK', 'essfinance' ),
			'NPR' => __( 'Nepalese rupee (₨) — NPR', 'essfinance' ),
			'NZD' => __( 'New Zealand dollar ($) — NZD', 'essfinance' ),
			'OMR' => __( 'Omani rial (ر.ع.) — OMR', 'essfinance' ),
			'PAB' => __( 'Panamanian balboa (B/.) — PAB', 'essfinance' ),
			'PEN' => __( 'Peruvian sol (S/.) — PEN', 'essfinance' ),
			'PGK' => __( 'Papua New Guinean kina (K) — PGK', 'essfinance' ),
			'PHP' => __( 'Philippine peso (₱) — PHP', 'essfinance' ),
			'PKR' => __( 'Pakistani rupee (₨) — PKR', 'essfinance' ),
			'PLN' => __( 'Polish złoty (zł) — PLN', 'essfinance' ),
			'PRB' => __( 'Transnistrian ruble (р.) — PRB', 'essfinance' ),
			'PYG' => __( 'Paraguayan guaraní (₲) — PYG', 'essfinance' ),
			'QAR' => __( 'Qatari riyal (ر.ق) — QAR', 'essfinance' ),
			'RON' => __( 'Romanian leu (lei) — RON', 'essfinance' ),
			'RSD' => __( 'Serbian dinar (дин.) — RSD', 'essfinance' ),
			'RUB' => __( 'Russian ruble (₽) — RUB', 'essfinance' ),
			'RWF' => __( 'Rwandan franc (Fr) — RWF', 'essfinance' ),
			'SAR' => __( 'Saudi riyal (ر.س) — SAR', 'essfinance' ),
			'SBD' => __( 'Solomon Islands dollar ($) — SBD', 'essfinance' ),
			'SCR' => __( 'Seychellois rupee (₨) — SCR', 'essfinance' ),
			'SDG' => __( 'Sudanese pound (ج.س.) — SDG', 'essfinance' ),
			'SEK' => __( 'Swedish krona (kr) — SEK', 'essfinance' ),
			'SGD' => __( 'Singapore dollar ($) — SGD', 'essfinance' ),
			'SHP' => __( 'Saint Helena pound (£) — SHP', 'essfinance' ),
			'SLL' => __( 'Sierra Leonean leone (Le) — SLL', 'essfinance' ),
			'SOS' => __( 'Somali shilling (Sh) — SOS', 'essfinance' ),
			'SRD' => __( 'Surinamese dollar ($) — SRD', 'essfinance' ),
			'SSP' => __( 'South Sudanese pound (£) — SSP', 'essfinance' ),
			'STN' => __( 'São Tomé and Príncipe dobra (Db) — STN', 'essfinance' ),
			'SYP' => __( 'Syrian pound (ل.س) — SYP', 'essfinance' ),
			'SZL' => __( 'Swazi lilangeni (L) — SZL', 'essfinance' ),
			'THB' => __( 'Thai baht (฿) — THB', 'essfinance' ),
			'TJS' => __( 'Tajikistani somoni (SM) — TJS', 'essfinance' ),
			'TMT' => __( 'Turkmenistan manat (T) — TMT', 'essfinance' ),
			'TND' => __( 'Tunisian dinar (د.ت) — TND', 'essfinance' ),
			'TOP' => __( 'Tongan paʻanga (T$) — TOP', 'essfinance' ),
			'TRY' => __( 'Turkish lira (₺) — TRY', 'essfinance' ),
			'TTD' => __( 'Trinidad and Tobago dollar ($) — TTD', 'essfinance' ),
			'TWD' => __( 'New Taiwan dollar (NT$) — TWD', 'essfinance' ),
			'TZS' => __( 'Tanzanian shilling (Sh) — TZS', 'essfinance' ),
			'UAH' => __( 'Ukrainian hryvnia (₴) — UAH', 'essfinance' ),
			'UGX' => __( 'Ugandan shilling (UGX) — UGX', 'essfinance' ),
			'USD' => __( 'United States (US) dollar ($) — USD', 'essfinance' ),
			'UYU' => __( 'Uruguayan peso ($) — UYU', 'essfinance' ),
			'UZS' => __( 'Uzbekistani som (UZS) — UZS', 'essfinance' ),
			'VEF' => __( 'Venezuelan bolívar 2008–2018 (Bs.F) — VEF', 'essfinance' ),
			'VES' => __( 'Venezuelan bolívar soberano (Bs.S) — VES', 'essfinance' ),
			'VND' => __( 'Vietnamese đồng (₫) — VND', 'essfinance' ),
			'VUV' => __( 'Vanuatu vatu (Vt) — VUV', 'essfinance' ),
			'WST' => __( 'Samoan tālā (T) — WST', 'essfinance' ),
			'XAF' => __( 'Central African CFA franc (CFA) — XAF', 'essfinance' ),
			'XCD' => __( 'East Caribbean dollar ($) — XCD', 'essfinance' ),
			'XOF' => __( 'West African CFA franc (CFA) — XOF', 'essfinance' ),
			'XPF' => __( 'CFP franc (Fr) — XPF', 'essfinance' ),
			'YER' => __( 'Yemeni rial (﷼) — YER', 'essfinance' ),
			'ZAR' => __( 'South African rand (R) — ZAR', 'essfinance' ),
			'ZMW' => __( 'Zambian kwacha (ZK) — ZMW', 'essfinance' ),
		];
	}

	private static function symbols(): array {
		return [
			'AED' => 'د.إ',
			'AFN' => '؋',
			'ALL' => 'L',
			'AMD' => 'AMD',
			'ANG' => 'ƒ',
			'AOA' => 'Kz',
			'ARS' => '$',
			'AUD' => '$',
			'AWG' => 'ƒ',
			'AZN' => '₼',
			'BAM' => 'KM',
			'BBD' => '$',
			'BDT' => '৳',
			'BGN' => 'лв.',
			'BHD' => '.د.ب',
			'BIF' => 'Fr',
			'BMD' => '$',
			'BND' => '$',
			'BOB' => 'Bs.',
			'BRL' => 'R$',
			'BSD' => '$',
			'BTC' => '₿',
			'BTN' => 'Nu',
			'BWP' => 'P',
			'BYN' => 'Br',
			'BZD' => 'BZ$',
			'CAD' => '$',
			'CDF' => 'Fr',
			'CHF' => 'CHF',
			'CLP' => '$',
			'CNY' => '¥',
			'COP' => '$',
			'CRC' => '₡',
			'CUC' => '$',
			'CVE' => '$',
			'CZK' => 'Kč',
			'DJF' => 'Fr',
			'DKK' => 'DKK',
			'DOP' => 'RD$',
			'DZD' => 'د.ج',
			'EGP' => 'EGP',
			'ERN' => 'Nfk',
			'ETB' => 'Br',
			'EUR' => '€',
			'FJD' => '$',
			'FKP' => '£',
			'GBP' => '£',
			'GEL' => '₾',
			'GHS' => '₵',
			'GIP' => '£',
			'GMD' => 'D',
			'GNF' => 'Fr',
			'GTQ' => 'Q',
			'GYD' => '$',
			'HKD' => '$',
			'HNL' => 'L',
			'HRK' => 'kn',
			'HTG' => 'G',
			'HUF' => 'Ft',
			'IDR' => 'Rp',
			'ILS' => '₪',
			'IMP' => '£',
			'INR' => '₹',
			'IQD' => 'ع.د',
			'IRR' => '﷼',
			'IRT' => 'تومان',
			'ISK' => 'kr.',
			'JEP' => '£',
			'JMD' => '$',
			'JOD' => 'JD',
			'JPY' => '¥',
			'KES' => 'KSh',
			'KGS' => 'лв',
			'KHR' => '៛',
			'KMF' => 'Fr',
			'KPW' => '₩',
			'KRW' => '₩',
			'KWD' => 'د.ك',
			'KYD' => '$',
			'KZT' => '₸',
			'LAK' => '₭',
			'LBP' => 'ل.ل',
			'LKR' => 'රු',
			'LRD' => '$',
			'LSL' => 'L',
			'LYD' => 'ل.د',
			'MAD' => 'م.د.',
			'MDL' => 'MDL',
			'MGA' => 'Ar',
			'MKD' => 'ден',
			'MMK' => 'K',
			'MNT' => '₮',
			'MOP' => 'P',
			'MRU' => 'UM',
			'MUR' => '₨',
			'MVR' => 'Rf',
			'MWK' => 'MK',
			'MXN' => '$',
			'MYR' => 'RM',
			'MZN' => 'MT',
			'NAD' => 'N$',
			'NGN' => '₦',
			'NIO' => 'C$',
			'NOK' => 'kr',
			'NPR' => '₨',
			'NZD' => '$',
			'OMR' => 'ر.ع.',
			'PAB' => 'B/.',
			'PEN' => 'S/.',
			'PGK' => 'K',
			'PHP' => '₱',
			'PKR' => '₨',
			'PLN' => 'zł',
			'PRB' => 'р.',
			'PYG' => '₲',
			'QAR' => 'ر.ق',
			'RON' => 'lei',
			'RSD' => 'дин.',
			'RUB' => '₽',
			'RWF' => 'Fr',
			'SAR' => 'ر.س',
			'SBD' => '$',
			'SCR' => '₨',
			'SDG' => 'ج.س.',
			'SEK' => 'kr',
			'SGD' => '$',
			'SHP' => '£',
			'SLL' => 'Le',
			'SOS' => 'Sh',
			'SRD' => '$',
			'SSP' => '£',
			'STN' => 'Db',
			'SYP' => 'ل.س',
			'SZL' => 'L',
			'THB' => '฿',
			'TJS' => 'SM',
			'TMT' => 'T',
			'TND' => 'د.ت',
			'TOP' => 'T$',
			'TRY' => '₺',
			'TTD' => '$',
			'TWD' => 'NT$',
			'TZS' => 'Sh',
			'UAH' => '₴',
			'UGX' => 'UGX',
			'USD' => '$',
			'UYU' => '$',
			'UZS' => 'UZS',
			'VEF' => 'Bs.F',
			'VES' => 'Bs.S',
			'VND' => '₫',
			'VUV' => 'Vt',
			'WST' => 'T',
			'XAF' => 'CFA',
			'XCD' => '$',
			'XOF' => 'CFA',
			'XPF' => 'Fr',
			'YER' => '﷼',
			'ZAR' => 'R',
			'ZMW' => 'ZK',
		];
	}

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_post_essf_create_pages', [ $this, 'handle_create_pages' ] );
		add_filter( 'admin_title', [ $this, 'filter_admin_title' ], 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( ESSF_PATH . 'essfinance.php' ), [ $this, 'action_links' ] );
	}

	public function handle_create_pages(): void {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'essf_create_pages' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'essfinance' ) );
		}
		ESSF_Shortcodes::create_pages();
		wp_safe_redirect( add_query_arg( 'essf_pages_created', '1', admin_url( 'admin.php?page=essfinance-settings' ) ) );
		exit;
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
		/* ── Display ── */
		foreach ( [
			self::OPTION_STATUS_BADGE        => false,
			self::OPTION_STATUS_ICONS        => true,
			self::OPTION_AMOUNT_COLORS       => false,
			self::OPTION_POSITIVE_PREFIX     => true,
			self::OPTION_NEGATIVE_PREFIX     => false,
			self::OPTION_SHOW_TYPE_FILTER    => false,
			self::OPTION_SHOW_TOTALS         => true,
			self::OPTION_SHOW_BALANCE_COLUMN => true,
		] as $option => $default ) {
			register_setting(
				'essf_settings',
				$option,
				[
					'type'              => 'boolean',
					'default'           => $default,
					'sanitize_callback' => 'rest_sanitize_boolean',
				]
			);
		}

		add_settings_section( 'essf_display', __( 'Display', 'essfinance' ), '__return_false', 'essf_settings' );
		add_settings_field( self::OPTION_STATUS_BADGE, __( 'Show status as badge', 'essfinance' ), [ $this, 'field_status_badge' ], 'essf_settings', 'essf_display' );
		add_settings_field( self::OPTION_STATUS_ICONS, __( 'Show icons in Status', 'essfinance' ), [ $this, 'field_status_icons' ], 'essf_settings', 'essf_display' );
		add_settings_field( self::OPTION_AMOUNT_COLORS, __( 'Show amount with colors', 'essfinance' ), [ $this, 'field_amount_colors' ], 'essf_settings', 'essf_display' );
		add_settings_field( self::OPTION_POSITIVE_PREFIX, __( 'Show positive prefix', 'essfinance' ), [ $this, 'field_positive_prefix' ], 'essf_settings', 'essf_display' );
		add_settings_field( self::OPTION_NEGATIVE_PREFIX, __( 'Show negative prefix', 'essfinance' ), [ $this, 'field_negative_prefix' ], 'essf_settings', 'essf_display' );
		add_settings_field( self::OPTION_SHOW_TYPE_FILTER, __( 'Show type filter', 'essfinance' ), [ $this, 'field_show_type_filter' ], 'essf_settings', 'essf_display' );
		add_settings_field( self::OPTION_SHOW_TOTALS, __( 'Show balances', 'essfinance' ), [ $this, 'field_show_totals' ], 'essf_settings', 'essf_display' );
		add_settings_field( self::OPTION_SHOW_BALANCE_COLUMN, __( 'Show running balance column', 'essfinance' ), [ $this, 'field_show_balance_column' ], 'essf_settings', 'essf_display' );

		/* ── Currency options ── */
		register_setting(
			'essf_settings',
			self::OPTION_CURRENCY,
			[
				'type'              => 'string',
				'default'           => 'USD',
				'sanitize_callback' => static function ( $v ) {
					return array_key_exists( $v, self::currencies() ) ? $v : 'USD';
				},
			]
		);
		register_setting(
			'essf_settings',
			self::OPTION_CURRENCY_POS,
			[
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			]
		);
		register_setting(
			'essf_settings',
			self::OPTION_THOUSANDS_SEP,
			[
				'type'              => 'string',
				'default'           => ',',
				'sanitize_callback' => static function ( $v ) {
					return in_array( $v, [ ',', '.', ' ', "'", '' ], true ) ? $v : ',';
				},
			]
		);
		register_setting(
			'essf_settings',
			self::OPTION_DECIMAL_SEP,
			[
				'type'              => 'string',
				'default'           => '.',
				'sanitize_callback' => static function ( $v ) {
					return in_array( $v, [ '.', ',' ], true ) ? $v : '.';
				},
			]
		);
		register_setting(
			'essf_settings',
			self::OPTION_NUM_DECIMALS,
			[
				'type'              => 'integer',
				'default'           => 2,
				'sanitize_callback' => 'absint',
			]
		);

		add_settings_section(
			'essf_currency',
			__( 'Currency options', 'essfinance' ),
			function () {
				echo '<p>' . esc_html__( 'The following options affect how amounts are displayed.', 'essfinance' ) . '</p>';
			},
			'essf_settings'
		);

		add_settings_field(
			self::OPTION_CURRENCY,
			__( 'Currency', 'essfinance' ),
			[ $this, 'field_currency_symbol' ],
			'essf_settings',
			'essf_currency'
		);
		add_settings_field(
			self::OPTION_CURRENCY_POS,
			__( 'Currency position', 'essfinance' ),
			[ $this, 'field_currency_pos' ],
			'essf_settings',
			'essf_currency'
		);
		add_settings_field(
			self::OPTION_THOUSANDS_SEP,
			__( 'Thousand separator', 'essfinance' ),
			[ $this, 'field_thousands_sep' ],
			'essf_settings',
			'essf_currency'
		);
		add_settings_field(
			self::OPTION_DECIMAL_SEP,
			__( 'Decimal separator', 'essfinance' ),
			[ $this, 'field_decimal_sep' ],
			'essf_settings',
			'essf_currency'
		);
		add_settings_field(
			self::OPTION_NUM_DECIMALS,
			__( 'Number of decimals', 'essfinance' ),
			[ $this, 'field_num_decimals' ],
			'essf_settings',
			'essf_currency'
		);

		/* ── Running balance ── */
		register_setting(
			'essf_settings',
			self::OPTION_INITIAL_BALANCE,
			[
				'type'              => 'number',
				'default'           => 0.0,
				'sanitize_callback' => static function ( $v ) {
					return round( (float) str_replace( ',', '.', (string) $v ), 2 );
				},
			]
		);
		register_setting(
			'essf_settings',
			self::OPTION_BALANCE_BASIS,
			[
				'type'              => 'string',
				'default'           => 'paid',
				'sanitize_callback' => static function ( $v ) {
					return in_array( $v, [ 'paid', 'all' ], true ) ? $v : 'paid';
				},
			]
		);
		register_setting(
			'essf_settings',
			self::OPTION_PENDING_BALANCE_MODE,
			[
				'type'              => 'string',
				'default'           => 'dash',
				'sanitize_callback' => static function ( $v ) {
					return in_array( $v, [ 'dash', 'forward_fill' ], true ) ? $v : 'dash';
				},
			]
		);

		add_settings_section(
			'essf_balance',
			__( 'Running Balance', 'essfinance' ),
			function () {
				echo '<p>' . esc_html__( 'These options control the cumulative "Balance" column shown alongside cash flow entries.', 'essfinance' ) . '</p>';
			},
			'essf_settings'
		);

		add_settings_field(
			self::OPTION_INITIAL_BALANCE,
			__( 'Initial balance', 'essfinance' ),
			[ $this, 'field_initial_balance' ],
			'essf_settings',
			'essf_balance'
		);
		add_settings_field(
			self::OPTION_BALANCE_BASIS,
			__( 'Balance basis', 'essfinance' ),
			[ $this, 'field_balance_basis' ],
			'essf_settings',
			'essf_balance'
		);
		add_settings_field(
			self::OPTION_PENDING_BALANCE_MODE,
			__( 'Pending entries', 'essfinance' ),
			[ $this, 'field_pending_balance_mode' ],
			'essf_settings',
			'essf_balance'
		);
	}

	/* ── Display fields ─────────────────────────────────── */

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
			function toggle() {
				var iconRow = document.getElementById( 'essf_icons_row' );
				if ( iconRow ) iconRow.style.display = badge.checked ? 'none' : '';
			}
			badge.addEventListener( 'change', toggle );
			document.addEventListener( 'DOMContentLoaded', toggle );
		} )();
		</script>
		<?php
	}

	public function field_show_type_filter(): void {
		$value = get_option( self::OPTION_SHOW_TYPE_FILTER, false );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_SHOW_TYPE_FILTER ); ?>" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Show the Income/Expense filter dropdown above the entries list', 'essfinance' ); ?>
		</label>
		<?php
	}

	public function field_show_totals(): void {
		$value = get_option( self::OPTION_SHOW_TOTALS, true );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_SHOW_TOTALS ); ?>" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Show balance/total cards above the entries list (admin and frontend)', 'essfinance' ); ?>
		</label>
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

	public function field_positive_prefix(): void {
		$value = get_option( self::OPTION_POSITIVE_PREFIX, true );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_POSITIVE_PREFIX ); ?>" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Show + sign before positive amounts (income)', 'essfinance' ); ?>
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

	/* ── Currency fields ────────────────────────────────── */

	public function field_currency_symbol(): void {
		$value = get_option( self::OPTION_CURRENCY, 'USD' );
		echo self::help_tip( __( 'This controls which currency symbol is shown next to amounts.', 'essfinance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- help_tip() returns pre-escaped HTML
		echo '<select name="' . esc_attr( self::OPTION_CURRENCY ) . '" id="' . esc_attr( self::OPTION_CURRENCY ) . '">';
		foreach ( self::currencies() as $code => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $code ), selected( $value, $code, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	public function field_currency_pos(): void {
		$value   = get_option( self::OPTION_CURRENCY_POS, '' );
		$options = [
			''            => __( 'Do not display', 'essfinance' ),
			'left'        => __( 'Left', 'essfinance' ),
			'right'       => __( 'Right', 'essfinance' ),
			'left_space'  => __( 'Left with space', 'essfinance' ),
			'right_space' => __( 'Right with space', 'essfinance' ),
		];
		echo self::help_tip( __( 'This controls the position of the currency symbol, or choose "Do not display" to hide it.', 'essfinance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- help_tip() returns pre-escaped HTML
		echo '<select name="' . esc_attr( self::OPTION_CURRENCY_POS ) . '" id="' . esc_attr( self::OPTION_CURRENCY_POS ) . '">';
		foreach ( $options as $opt => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $opt ), selected( $value, $opt, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	public function field_thousands_sep(): void {
		$value   = get_option( self::OPTION_THOUSANDS_SEP, ',' );
		$options = [
			',' => __( 'Comma (1,000)', 'essfinance' ),
			'.' => __( 'Period (1.000)', 'essfinance' ),
			' ' => __( 'Space (1 000)', 'essfinance' ),
			"'" => __( "Apostrophe (1'000)", 'essfinance' ),
			''  => __( 'None (1000)', 'essfinance' ),
		];
		echo self::help_tip( __( 'This sets the thousand separator of displayed amounts.', 'essfinance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- help_tip() returns pre-escaped HTML
		echo '<select name="' . esc_attr( self::OPTION_THOUSANDS_SEP ) . '" id="' . esc_attr( self::OPTION_THOUSANDS_SEP ) . '">';
		foreach ( $options as $opt => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $opt ), selected( $value, $opt, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	public function field_decimal_sep(): void {
		$value   = get_option( self::OPTION_DECIMAL_SEP, '.' );
		$options = [
			'.' => __( 'Period (0.00)', 'essfinance' ),
			',' => __( 'Comma (0,00)', 'essfinance' ),
		];
		echo self::help_tip( __( 'This sets the decimal separator of displayed amounts.', 'essfinance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- help_tip() returns pre-escaped HTML
		echo '<select name="' . esc_attr( self::OPTION_DECIMAL_SEP ) . '" id="' . esc_attr( self::OPTION_DECIMAL_SEP ) . '">';
		foreach ( $options as $opt => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $opt ), selected( $value, $opt, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	public function field_num_decimals(): void {
		$value = (int) get_option( self::OPTION_NUM_DECIMALS, 2 );
		echo self::help_tip( __( 'This sets the number of decimal points shown in displayed amounts.', 'essfinance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- help_tip() returns pre-escaped HTML
		?>
		<input name="<?php echo esc_attr( self::OPTION_NUM_DECIMALS ); ?>"
			id="<?php echo esc_attr( self::OPTION_NUM_DECIMALS ); ?>"
			type="number" style="width:50px;" value="<?php echo esc_attr( (string) $value ); ?>"
			min="0" step="1" placeholder="">
		<?php
	}

	/* ── Running balance fields ─────────────────────────── */

	public function field_show_balance_column(): void {
		$value = get_option( self::OPTION_SHOW_BALANCE_COLUMN, true );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_SHOW_BALANCE_COLUMN ); ?>" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Show a cumulative "Balance" column on the entries list (admin and frontend)', 'essfinance' ); ?>
		</label>
		<?php
	}

	public function field_initial_balance(): void {
		$value = (float) get_option( self::OPTION_INITIAL_BALANCE, 0.0 );
		echo self::help_tip( __( 'The starting balance the running balance column is calculated from.', 'essfinance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- help_tip() returns pre-escaped HTML
		?>
		<input name="<?php echo esc_attr( self::OPTION_INITIAL_BALANCE ); ?>"
			id="<?php echo esc_attr( self::OPTION_INITIAL_BALANCE ); ?>"
			type="number" style="width:120px;" value="<?php echo esc_attr( (string) $value ); ?>"
			step="0.01" placeholder="">
		<?php
	}

	public function field_balance_basis(): void {
		$value   = get_option( self::OPTION_BALANCE_BASIS, 'paid' );
		$options = [
			'paid' => __( 'Paid entries only (bank-ledger balance)', 'essfinance' ),
			'all'  => __( 'Paid + pending entries (forecast balance)', 'essfinance' ),
		];
		echo self::help_tip( __( 'Which entries count toward the running balance.', 'essfinance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- help_tip() returns pre-escaped HTML
		echo '<select name="' . esc_attr( self::OPTION_BALANCE_BASIS ) . '" id="' . esc_attr( self::OPTION_BALANCE_BASIS ) . '">';
		foreach ( $options as $opt => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $opt ), selected( $value, $opt, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	public function field_pending_balance_mode(): void {
		$value   = get_option( self::OPTION_PENDING_BALANCE_MODE, 'dash' );
		$options = [
			'dash'         => __( 'Show a dash for pending rows', 'essfinance' ),
			'forward_fill' => __( 'Show the last paid balance (forward-fill)', 'essfinance' ),
		];
		echo self::help_tip( __( 'How pending rows are displayed in the Balance column when the balance basis is "Paid entries only".', 'essfinance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- help_tip() returns pre-escaped HTML
		echo '<select name="' . esc_attr( self::OPTION_PENDING_BALANCE_MODE ) . '" id="' . esc_attr( self::OPTION_PENDING_BALANCE_MODE ) . '">';
		foreach ( $options as $opt => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $opt ), selected( $value, $opt, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/* ── Pages helpers ──────────────────────────────────── */

	private function render_page_status( string $option ): void {
		$id = (int) get_option( $option );
		if ( $id ) {
			$post = get_post( $id );
			if ( $post ) {
				printf(
					'<a href="%s" target="_blank">%s</a> <span class="description">(<a href="%s">%s</a>)</span>',
					esc_url( (string) get_permalink( $id ) ),
					esc_html( $post->post_title ),
					esc_url( (string) get_edit_post_link( $id ) ),
					esc_html__( 'Edit', 'essfinance' )
				);
				return;
			}
		}
		echo '<span class="description">' . esc_html__( 'Page not created', 'essfinance' ) . '</span>';
	}

	/* ── Render ─────────────────────────────────────────── */

	public function render(): void {
		?>
		<style>
		.essf-help-tip {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			width: 16px;
			height: 16px;
			border-radius: 50%;
			background: #888;
			color: #fff;
			font-size: 11px;
			font-weight: 700;
			font-style: normal;
			line-height: 1;
			cursor: help;
			margin-left: 5px;
			vertical-align: middle;
			text-decoration: none;
		}
		#essf-tip {
			position: fixed;
			background: #333;
			color: #fff;
			padding: 6px 10px;
			border-radius: 3px;
			font-size: 12px;
			font-weight: 400;
			line-height: 1.4;
			max-width: 200px;
			white-space: normal;
			text-align: center;
			pointer-events: none;
			z-index: 99999;
			display: none;
		}
		#essf-tip-arrow {
			position: fixed;
			width: 0;
			height: 0;
			border: 5px solid transparent;
			pointer-events: none;
			z-index: 99999;
			display: none;
		}
		</style>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			var tip = document.createElement( 'div' );
			tip.id = 'essf-tip';
			document.body.appendChild( tip );

			var arrow = document.createElement( 'div' );
			arrow.id = 'essf-tip-arrow';
			document.body.appendChild( arrow );

			document.querySelectorAll( '.essf-help-tip' ).forEach( function ( el ) {
				el.addEventListener( 'mouseenter', show );
				el.addEventListener( 'focusin',    show );
				el.addEventListener( 'mouseleave', hide );
				el.addEventListener( 'focusout',   hide );
			} );

			function show() {
				var el   = this;
				var rect = el.getBoundingClientRect();
				tip.textContent = el.dataset.tip;
				tip.style.display = 'block';

				var tipW = tip.offsetWidth;
				var tipH = tip.offsetHeight;
				var cx   = rect.left + rect.width / 2;

				var left = cx - tipW / 2;
				left = Math.max( 8, Math.min( left, window.innerWidth - tipW - 8 ) );
				var top  = rect.bottom + 10;

				tip.style.left = left + 'px';
				tip.style.top  = top  + 'px';

				arrow.style.borderBottomColor = '#333';
				arrow.style.borderTopColor    = '';
				arrow.style.display = 'block';
				arrow.style.left    = ( cx - 5 ) + 'px';
				arrow.style.top     = ( rect.bottom + 1 ) + 'px';
			}

			function hide() {
				tip.style.display   = 'none';
				arrow.style.display = 'none';
			}
		} );
		</script>
		<div class="wrap">
			<h1><?php esc_html_e( 'EssFinance Settings', 'essfinance' ); ?></h1>

			<?php if ( isset( $_GET['essf_pages_created'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Pages created successfully.', 'essfinance' ); ?></p></div>
			<?php endif; ?>

			<div class="postbox" style="margin-bottom:20px;padding:0 16px 16px;">
				<h2 class="hndle" style="padding:12px 0 8px;"><?php esc_html_e( 'Pages', 'essfinance' ); ?></h2>
				<p><?php esc_html_e( 'The following pages are used by EssFinance on the frontend:', 'essfinance' ); ?></p>
				<table class="form-table" role="presentation" style="margin-top:0;">
					<tr>
						<th scope="row"><?php esc_html_e( 'My Account', 'essfinance' ); ?></th>
						<td><?php $this->render_page_status( ESSF_Shortcodes::OPTION_MYACCOUNT_PAGE ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cash Flow', 'essfinance' ); ?></th>
						<td><?php $this->render_page_status( ESSF_Shortcodes::OPTION_CASHFLOW_PAGE ); ?></td>
					</tr>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="essf_create_pages">
					<?php wp_nonce_field( 'essf_create_pages' ); ?>
					<?php submit_button( __( 'Create pages', 'essfinance' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<div class="postbox" style="margin-bottom:20px;padding:0 16px 16px;">
				<h2 class="hndle" style="padding:12px 0 8px;"><?php esc_html_e( 'Glossaries', 'essfinance' ); ?></h2>
				<p><?php esc_html_e( 'Review or forget what EssFinance has learned to suggest automatically.', 'essfinance' ); ?></p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=essfinance-ofx-glossary' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'OFX Glossary', 'essfinance' ); ?></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=essfinance-category-glossary' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Category Glossary', 'essfinance' ); ?></a>
				</p>
			</div>

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

	public function filter_admin_title( string $admin_title, string $title ): string {
		$screen = get_current_screen();
		if ( $screen && 'admin_page_essfinance-settings' === $screen->id ) {
			return __( 'EssFinance Settings', 'essfinance' ) . ' &lsaquo; ' . get_bloginfo( 'name' ) . ' &#8212; WordPress';
		}
		return $admin_title;
	}

	public function action_links( array $links ): array {
		$url = esc_url( admin_url( 'admin.php?page=essfinance-settings' ) );
		array_unshift( $links, '<a href="' . $url . '">' . __( 'Settings', 'essfinance' ) . '</a>' );
		return $links;
	}

	/* ── Static helpers ─────────────────────────────────── */

	private static function help_tip( string $text ): string {
		return '<span class="essf-help-tip" tabindex="0" data-tip="' . esc_attr( $text ) . '">?</span>';
	}

	/* ── Static getters ─────────────────────────────────── */

	public static function show_status_badge(): bool {
		return (bool) get_option( self::OPTION_STATUS_BADGE, false );
	}

	public static function show_type_filter(): bool {
		return (bool) get_option( self::OPTION_SHOW_TYPE_FILTER, false );
	}

	public static function show_totals(): bool {
		return (bool) get_option( self::OPTION_SHOW_TOTALS, true );
	}

	public static function show_status_icons(): bool {
		return (bool) get_option( self::OPTION_STATUS_ICONS, true );
	}

	public static function show_amount_colors(): bool {
		return (bool) get_option( self::OPTION_AMOUNT_COLORS, false );
	}

	public static function show_positive_prefix(): bool {
		return (bool) get_option( self::OPTION_POSITIVE_PREFIX, true );
	}

	public static function show_negative_prefix(): bool {
		return (bool) get_option( self::OPTION_NEGATIVE_PREFIX, false );
	}

	public static function currency_symbol(): string {
		$code    = (string) get_option( self::OPTION_CURRENCY, 'USD' );
		$symbols = self::symbols();
		return $symbols[ $code ] ?? '$';
	}

	public static function currency_pos(): string {
		return (string) get_option( self::OPTION_CURRENCY_POS, '' );
	}

	public static function thousands_sep(): string {
		return (string) get_option( self::OPTION_THOUSANDS_SEP, ',' );
	}

	public static function decimal_sep(): string {
		return (string) get_option( self::OPTION_DECIMAL_SEP, '.' );
	}

	public static function num_decimals(): int {
		return (int) get_option( self::OPTION_NUM_DECIMALS, 2 );
	}

	public static function show_balance_column(): bool {
		return (bool) get_option( self::OPTION_SHOW_BALANCE_COLUMN, true );
	}

	public static function initial_balance(): float {
		return (float) get_option( self::OPTION_INITIAL_BALANCE, 0.0 );
	}

	public static function balance_basis(): string {
		return 'all' === (string) get_option( self::OPTION_BALANCE_BASIS, 'paid' ) ? 'all' : 'paid';
	}

	public static function pending_balance_mode(): string {
		return 'forward_fill' === (string) get_option( self::OPTION_PENDING_BALANCE_MODE, 'dash' ) ? 'forward_fill' : 'dash';
	}

	public static function format_amount( float $amount ): string {
		$formatted = number_format( abs( $amount ), self::num_decimals(), self::decimal_sep(), self::thousands_sep() );
		$symbol    = self::currency_symbol();
		$pos       = self::currency_pos();

		if ( '' === $pos || '' === $symbol ) {
			return $formatted;
		}

		switch ( $pos ) {
			case 'right':
				return $formatted . $symbol;
			case 'left_space':
				return $symbol . "\u{00A0}" . $formatted;
			case 'right_space':
				return $formatted . "\u{00A0}" . $symbol;
			default:
				return $symbol . $formatted;
		}
	}

	public static function status_icon( string $status ): string {
		$icon = self::$icons[ $status ] ?? '';
		if ( ! $icon ) {
			return '';
		}
		return '<span class="dashicons ' . esc_attr( $icon ) . '" title="' . esc_attr( ESSF_CPT::status_label( $status ) ) . '"></span> ';
	}
}
