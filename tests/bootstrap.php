<?php
/**
 * PHPUnit bootstrap — loads Brain\Monkey and minimal WP stubs, then requires plugin files.
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use Brain\Monkey;

// ── WordPress constants ───────────────────────────────────────────────────────

defined( 'ABSPATH' ) || define( 'ABSPATH', sys_get_temp_dir() . '/wordpress/' );
define( 'ESSF_VERSION', '0.3.1' );
define( 'ESSF_PATH', dirname( __DIR__ ) . '/' );
define( 'ESSF_URL', 'http://example.com/wp-content/plugins/essfinance03/' );

// ── Minimal WordPress function stubs used at class-load time ─────────────────
// Brain\Monkey provides proper per-test stubs; these are the bare-minimum needed
// to require plugin PHP files without fatal errors.

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) {}
}
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}

// ── Minimal WP_List_Table stub so class-list-table.php can be required ───────

if ( ! class_exists( 'WP_List_Table' ) ) {
	class WP_List_Table {
		protected $screen;
		protected $_args             = [];
		protected $_pagination_args  = [];
		public    $items             = [];

		public function __construct( array $args = [] ) {}

		protected function get_column_info(): array {
			return [ [], [], [], '' ];
		}
	}
}

// ── Minimal WP_Post stub ─────────────────────────────────────────────────────

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int    $ID                = 0;
		public string $post_title        = '';
		public string $post_content      = '';
		public string $post_status       = 'pending';
		public string $post_date_gmt     = '0000-00-00 00:00:00';
		public string $post_modified_gmt = '0000-00-00 00:00:00';
		public string $post_type         = 'essf_cashflow';

		public function __construct( array $data = [] ) {
			foreach ( $data as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

// ── Load plugin includes (order matters — dependencies first) ─────────────────

require_once ESSF_PATH . 'includes/class-cpt.php';
require_once ESSF_PATH . 'includes/class-settings.php';
require_once ESSF_PATH . 'includes/class-shortcodes.php';
require_once ESSF_PATH . 'includes/class-list-table.php';
require_once ESSF_PATH . 'includes/class-meta-boxes.php';
require_once ESSF_PATH . 'includes/class-admin-page.php';
require_once ESSF_PATH . 'includes/class-assets.php';
