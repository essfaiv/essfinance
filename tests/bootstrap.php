<?php
/**
 * PHPUnit bootstrap — loads Brain\Monkey and minimal WP stubs, then requires plugin files.
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// ── Activate Patchwork stream wrapper BEFORE defining any stub functions ──────
// Brain\Monkey loads Patchwork lazily (inside setUp()), but our stubs must be
// defined in a file that Patchwork's stream wrapper has already preprocessed —
// otherwise it throws DefinedTooEarly when a test tries to mock them.
require_once dirname( __DIR__ ) . '/vendor/antecedent/patchwork/Patchwork.php';

// ── WordPress function stubs ──────────────────────────────────────────────────
// Must be required (not eval'd inline) so Patchwork can intercept them and
// Brain\Monkey can mock them per test.
require_once __DIR__ . '/stubs/functions.php';

use Brain\Monkey;

// ── WordPress constants ───────────────────────────────────────────────────────

defined( 'ABSPATH' ) || define( 'ABSPATH', sys_get_temp_dir() . '/wordpress/' );
define( 'ESSF_VERSION', '0.3.2' );
define( 'ESSF_PATH', dirname( __DIR__ ) . '/' );
define( 'ESSF_URL', 'http://example.com/wp-content/plugins/essfinance03/' );

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

// ── Minimal $wpdb stub ────────────────────────────────────────────────────────
// Spies on ->update() calls instead of touching a real database — used to
// assert that a write only ever sets the columns it claims to (e.g. renaming
// a post_title must never implicitly also touch post_modified_gmt, unlike
// wp_update_post() — see CLAUDE.md's post_date_gmt/post_modified_gmt caveat).

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $posts = 'wp_posts';

		/** @var array<int, array{table: string, data: array, where: array}> */
		public array $update_calls = [];

		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			$this->update_calls[] = [
				'table' => $table,
				'data'  => $data,
				'where' => $where,
			];
			return 1;
		}
	}
}

// ── Minimal WP_Term stub ─────────────────────────────────────────────────────

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public int    $term_id  = 0;
		public string $name     = '';
		public string $slug     = '';
		public string $taxonomy = '';

		public function __construct( array $data = [] ) {
			foreach ( $data as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

// ── Load plugin includes (order matters — dependencies first) ─────────────────

require_once ESSF_PATH . 'includes/class-cpt.php';
require_once ESSF_PATH . 'includes/class-category.php';
require_once ESSF_PATH . 'includes/class-ofx-parser.php';
require_once ESSF_PATH . 'includes/class-ofx-suggestions.php';
require_once ESSF_PATH . 'includes/class-totals.php';
require_once ESSF_PATH . 'includes/class-settings.php';
require_once ESSF_PATH . 'includes/class-shortcodes.php';
require_once ESSF_PATH . 'includes/class-list-table.php';
require_once ESSF_PATH . 'includes/class-meta-boxes.php';
require_once ESSF_PATH . 'includes/class-admin-page.php';
require_once ESSF_PATH . 'includes/class-assets.php';
require_once ESSF_PATH . 'includes/class-cli.php';
require_once ESSF_PATH . 'includes/class-bill.php';
require_once ESSF_PATH . 'includes/class-loan.php';
require_once ESSF_PATH . 'includes/class-financing.php';
