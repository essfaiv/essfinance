# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

EssFinance is a WordPress plugin (GPL-2.0-or-later) for personal cash-flow tracking, with both a
`wp-admin` UI and a frontend UI (shortcodes + user accounts). It has no custom database tables —
every entry is a native WordPress post of type `essf_cashflow`.

The repo has real tooling: Composer (PHPUnit, PHPStan, PHPCS/WPCS), npm/Playwright for e2e, a
pre-commit hook, and GitHub Actions CI. This git repository's history starts at `Release 0.3.0` —
there is no earlier commit history in this repo (the pre-0.3.0 single-file architecture is archived
under `legacy/pre-0.3.0/`, see below; it is not loaded by the plugin).

## Running / testing changes

```
composer install        # installs dev deps + a pre-commit hook (bin/install-hooks.php)
composer test            # PHPUnit (unit tests, Brain Monkey-mocked WP functions)
composer analyse         # PHPStan
composer lint             # PHPCS (WPCS + PHPCompatibilityWP, PHP 7.2+)
composer lint:fix         # PHPCBF autofix
npm run test:e2e          # Playwright e2e against a real WP install (see tests/e2e/)
```

To exercise changes manually, install/symlink this directory into `wp-content/plugins/` of a
WordPress install (or use the `wp-playground` skill / `blueprint.json`) and activate "EssFinance".
CI (`.github/workflows/tests.yml`) runs PHPCS, PHPUnit (PHP 8.1–8.3), and PHPStan on every push;
`.github/workflows/release.yml` and `pr-preview-*.yml` handle releases and PR preview builds.

## Architecture

`essfinance.php` is a thin bootstrap: defines `ESSF_VERSION` / `ESSF_PATH` / `ESSF_URL`, always
requires and instantiates `ESSF_CPT`, `ESSF_Shortcodes`; on `is_admin()` it additionally requires
and instantiates `ESSF_Meta_Boxes`, `ESSF_Admin_Page`, `ESSF_Assets`, `ESSF_Settings`. Each class
lives in its own `includes/class-*.php` file and self-registers its own hooks from its constructor
— there is no central controller or router.

| Class | File | Responsibility |
|---|---|---|
| `ESSF_CPT` | `class-cpt.php` | Registers the `essf_cashflow` post type, `pending`/`paid` statuses, `_order_date` meta. `activate()` (via `register_activation_hook`) also flushes rewrite rules and calls `ESSF_Shortcodes::create_pages()`. |
| `ESSF_Admin_Page` | `class-admin-page.php` | wp-admin UI: top-level "EssFinance" menu, dashboard (add/edit form + `ESSF_List_Table`), CRUD via `admin-post.php` actions, bulk actions, CSV/OFX import, CSV export. |
| `ESSF_List_Table` | `class-list-table.php` | `WP_List_Table` subclass for the admin dashboard: columns, status "views" tabs, status/type/month filters, search, sorting. |
| `ESSF_Meta_Boxes` | `class-meta-boxes.php` | Native `essf_cashflow` post-edit-screen meta box ("Entry Details") as a fallback editing path alongside the custom admin dashboard form. |
| `ESSF_Settings` | `class-settings.php` | Settings screen (Settings API): display options (status badge/icons, amount colors, +/− prefixes) and currency formatting (symbol, position, separators, decimals); "Create pages" button; static getters (`format_amount()`, `show_status_badge()`, etc.) used by both admin and frontend renderers. |
| `ESSF_Shortcodes` | `class-shortcodes.php` | Frontend: `[essfinance_myaccount]` (login/register) and `[essfinance_cashflow]` (full CRUD dashboard — list, filters, bulk actions, CSV/OFX import/export) shortcodes, scoped to `post_author = current user`. Also owns `create_pages()`, which auto-creates the "My Account"/"Cash Flow" pages on activation if they don't already exist. |
| `ESSF_Assets` | `class-assets.php` | Conditionally enqueues `assets/css/admin.css` / `assets/js/admin.js` only on EssFinance admin screens. |

### Data model — no custom tables

Cash flow entries are WordPress posts of type `essf_cashflow`, overloading standard post fields:

| Field | WP column | Notes |
|---|---|---|
| Description | `post_title` | |
| Due Date | `post_date_gmt` | `YYYY-MM-DD HH:MM:SS`; `0000-00-00 00:00:00` means unset |
| Pay Date | `post_modified_gmt` | `0000-00-00 00:00:00` sentinel means "unpaid" |
| Amount | `post_content` | numeric string; **positive/zero = income, negative = expense** (sign encodes type; no separate type field) |
| Status | `post_status` | custom statuses `pending` \| `paid` (`ESSF_CPT::$statuses`) |
| Filter/sort key | `_order_date` postmeta | `YYYY-MM-DD`; pay_date if set, else due_date — recompute and re-save any time due_date/pay_date/status changes |
| OFX dedup key | `_essf_fitid` postmeta | set only on OFX-imported entries, used to skip re-importing the same bank transaction |
| Ownership (frontend only) | `post_author` | frontend shortcode CRUD is scoped per-user via `post_author`; the admin dashboard is not — it manages all entries regardless of author |

`overdue` is a **virtual** status, never stored — computed at render time as `pending` + `due_date <
today`. Don't add a stored "overdue" status.

Every write path that touches `post_date_gmt`/`post_modified_gmt` (`ESSF_Admin_Page::handle_add/
handle_update`, `ESSF_Meta_Boxes::save`, `ESSF_Shortcodes::handle_save_entry`, and the various bulk
handlers) writes directly via `$wpdb->update()` on `$wpdb->posts` instead of `wp_update_post()`,
because `wp_insert_post()`/`wp_update_post()` don't reliably persist those two columns as given.
Preserve that pattern for any new field-updating code.

### Request handling

There are two independent, parallel request-handling surfaces — **don't conflate them**:

- **Admin** (`ESSF_Admin_Page`): mutations go through `admin-post.php` actions
  (`admin_post_essf_add|update|delete|paid_today|toggle_type|export|import`), each with its own
  nonce constant (`ESSF_Admin_Page::ADD_NONCE` etc.) checked via `check_admin_referer()`. Bulk
  actions are handled separately in `process_bulk_actions()` (hooked to `admin_init`, gated on
  `$_REQUEST['page'] === 'essfinance'`), nonce-checked against `bulk-entries`.
- **Frontend** (`ESSF_Shortcodes`): a single `handle_forms()` dispatcher hooked to
  `template_redirect` reads `essf_action` from `$_POST`/`$_GET` and routes to
  `login|register|add_entry|edit_entry|import|bulk_action` (POST) or
  `delete_entry|paid_today|toggle_type|export` (GET), each verifying its own nonce inline with
  `wp_verify_nonce()`. Every handler here also checks `is_user_logged_in()` and, for entry
  mutations, that `post_author` matches the current user.

Both surfaces redirect with `wp_safe_redirect()` + `exit` after mutating, and both support CSV
export/import and OFX/QFX import (dedup by description+date+amount, or by `_essf_fitid` for OFX).
When adding a new action, add it to the surface(s) it belongs to, following the existing
nonce-per-action pattern — don't introduce a shared unauthenticated action namespace between admin
and frontend.

### Legacy (archived, not loaded)

`legacy/pre-0.3.0/` preserves the plugin's original single-file (`includes/core.php`) architecture
from before the v0.3.0 rewrite, kept for reference only — see `legacy/pre-0.3.0/README.md`. It is
never `require`d by `essfinance.php` and should not be treated as current. There are currently no
active `migrate-*.php` scripts in the plugin root (the archived ones target the pre-0.3.0 data
format and don't apply to the current schema); if a future release needs a data-shape migration,
add a new `migrate-0.X.Y.php` at the plugin root following the archived ones as a style reference.

## Release process

Bump the `Version:` header in `essfinance.php` and add a `readme.txt` `== Changelog ==` entry
together per release (readme.txt is the single changelog — there is no separate `CHANGELOG.md` in
the active tree). Release commits follow `release: vX.Y.Z — <summary>`; feature/fix commits use a
short type prefix (`fix:`, `admin:`, `settings:`, `css:`, `tests:`, `ci:`, …). Releases are tagged
`vX.Y.Z` (see `git tag`).
