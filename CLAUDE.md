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
composer lint             # PHPCS (WPCS + PHPCompatibilityWP, PHP 8.0+)
composer lint:fix         # PHPCBF autofix
npm run test:e2e          # Playwright e2e against a real WP install (see tests/e2e/)
```

To exercise changes manually, install/symlink this directory into `wp-content/plugins/` of a
WordPress install (or use the `wp-playground` skill / `blueprint.json`) and activate "EssFinance".
CI (`.github/workflows/tests.yml`) runs PHPCS, PHPUnit (PHP 8.1–8.3), and PHPStan on every push;
`.github/workflows/release.yml` and `pr-preview-*.yml` handle releases and PR preview builds.

## Architecture

`essfinance.php` is a thin bootstrap: defines `ESSF_VERSION` / `ESSF_PATH` / `ESSF_URL`, always
requires and instantiates `ESSF_CPT` and `ESSF_Shortcodes`, and always requires (but doesn't
instantiate — it's a static-method utility class) `ESSF_OFX_Parser`; on `is_admin()` it additionally
requires and instantiates `ESSF_Meta_Boxes`, `ESSF_Admin_Page`, `ESSF_Assets`, `ESSF_Settings` (plus
requiring `ESSF_OFX_Suggestions`); when `defined('WP_CLI') && WP_CLI` it requires and instantiates
`ESSF_CLI`. Each class lives in its own `includes/class-*.php` file and self-registers its own hooks
from its constructor — there is no central controller or router.

Before any of that, it also conditionally requires `lib/selfdirectory/class-selfdirectory.php` — a
git submodule (`https://github.com/fervidum/selfdirectory`, pinned commit, see `.gitmodules`) that
gives self-hosted wp-admin update notifications from GitHub Releases (the plugin's own distribution
channel — see `.github/workflows/release.yml`), keyed off the `Directory:` header pointing at
`github.com/essfaiv/essfinance`. Guarded by `file_exists()` only — the plugin works fully without
the submodule initialized (e.g. `git clone` without `--recurse-submodules`); only
`.github/workflows/release.yml` checks it out (`submodules: recursive`), since that's the only
build whose zip needs to ship `lib/selfdirectory/class-selfdirectory.php`.

| Class | File | Responsibility |
|---|---|---|
| `ESSF_CPT` | `class-cpt.php` | Registers the `essf_cashflow` post type, `pending`/`paid` statuses, `_order_date` meta. `activate()` (via `register_activation_hook`) also flushes rewrite rules and calls `ESSF_Shortcodes::create_pages()`. |
| `ESSF_OFX_Parser` | `class-ofx-parser.php` | Static-only: `parse()` turns raw OFX/QFX content into transaction rows (`fitid`, `amount`, `due_date`, raw `name`, raw `memo` — no collapsed description); `describe()` applies the `NAME ?: MEMO ?: 'OFX Transaction'` fallback; `parse_transfer()` recognizes a "`<Kind> enviada(o)/recebida(o) [pelo Pix]` - Nome - resto" Pix memo — both transfers ("Transferência...", Nubank omits "pelo Pix" on the receiving side) and refunds ("Reembolso...") follow this shape — and splits it into a `<Kind> <Nome>` title (direction is already the amount's sign, so "enviada(o)"/"recebida(o)" is dropped) plus a capitalized `detail` string for `_essf_ofx_detail`; `parse_purchase()` recognizes "Compra no débito/crédito [via X] - Merchant" and returns just the capitalized merchant name, dropping the payment-rail prefix. `ESSF_Admin_Page::build_ofx_stage_rows()` tries `parse_transfer()` then `parse_purchase()` then `describe()` in that order for a staged row's fallback description — `ESSF_OFX_Suggestions::suggest()` still gets first pick when it finds a learned mapping, this is only what shows when nothing in history matches yet. The single parsing implementation shared by `ESSF_Admin_Page` (import) and `ESSF_CLI` (export). |
| `ESSF_OFX_Suggestions` | `class-ofx-suggestions.php` | Static-only: `suggest()` ranks prior entry titles by similarity (`similar_text()` on a normalized memo) against a caller-supplied history, then frequency, then recency. `normalize()` first tries `ESSF_OFX_Parser::parse_transfer()`/`parse_purchase()` and compares on just the extracted counterparty/merchant name when one of those matches — the actual identity, avoiding an open-ended noise-word list of every possible intermediary/payment-rail name — falling back to stripping unmasked CPF/CNPJ, long digit runs, and common noise words otherwise. Callers query the DB; this class never does (aside from depending on `ESSF_OFX_Parser`, always loaded earlier — see bootstrap order above). |
| `ESSF_Admin_Page` | `class-admin-page.php` | wp-admin UI: top-level "EssFinance" menu, dashboard (add/edit form + `ESSF_List_Table`), CRUD via `admin-post.php` actions, bulk actions, CSV import, CSV export. OFX/QFX import stages parsed+deduped rows in a transient and redirects to a review-and-confirm page (see "OFX import review flow" below) instead of inserting directly. Also owns the "OFX Glossary" submenu page (`render_glossary_page()`) for reviewing/forgetting individual learned `_essf_ofx_memo` mappings and `essf_ofx_excluded_memos` patterns — see below. `handle_adjust_balance()` (triggered from the Settings screen, not this dashboard) inserts a reconciliation entry tagged with the seeded `balance-adjustment` category; `ESSF_Category::is_balance_adjustment()` locks such entries down to Delete only, everywhere (row actions, `admin-post.php` handlers, bulk actions, the meta box). |
| `ESSF_List_Table` | `class-list-table.php` | `WP_List_Table` subclass for the admin dashboard: columns, status "views" tabs, status/type/month filters, search, sorting. |
| `ESSF_Meta_Boxes` | `class-meta-boxes.php` | Native `essf_cashflow` post-edit-screen meta box ("Entry Details") as a fallback editing path alongside the custom admin dashboard form. |
| `ESSF_Settings` | `class-settings.php` | Settings screen (Settings API): display options (status badge/icons, amount colors, +/− prefixes) and currency formatting (symbol, position, separators, decimals); "Create pages" button; static getters (`format_amount()`, `show_status_badge()`, etc.) used by both admin and frontend renderers. Also hosts the "Adjust Balance" balance-reconciliation trigger (own postbox, gated on `show_balance_column()`) — the form posts to `ESSF_Admin_Page`'s existing `admin_post_essf_adjust_balance` handler. |
| `ESSF_Shortcodes` | `class-shortcodes.php` | Frontend: `[essfinance_myaccount]` (login/register) and `[essfinance_cashflow]` (full CRUD dashboard — list, filters, bulk actions, CSV import/export) shortcodes, scoped to `post_author = current user`. **CSV only** — there is no frontend OFX/QFX import. Also owns `create_pages()`, which auto-creates the "My Account"/"Cash Flow" pages on activation if they don't already exist. |
| `ESSF_Assets` | `class-assets.php` | Conditionally enqueues `assets/css/admin.css` / `assets/js/admin.js` only on EssFinance admin screens. |
| `ESSF_CLI` | `class-cli.php` | Registered only under WP-CLI. `wp essf ofx-view <file> [--format=csv\|md] [--output=<path>]` parses an OFX/QFX file via `ESSF_OFX_Parser` and prints/writes a CSV or Markdown table — read-only, no DB access, no dedup. `wp essf ofx-audit [--only-opportunities] [--format=csv\|md] [--output=<path>]` replays the exact description-prediction pipeline (`ESSF_OFX_Suggestions::suggest()` → `parse_transfer()` → `parse_purchase()` → `parse_boleto()` → `describe()`) chronologically against every already-imported OFX-tagged `essf_cashflow` post, comparing what today's code would propose against the title actually chosen — doubling as a full knowledge-base export and, with `--only-opportunities`, a report of every entry that would still need a manual edit. Both commands' PHP method names (`ofx_view`/`ofx_audit`) are bridged to their hyphenated subcommand names via `@subcommand` in the docblock — WP-CLI does not hyphenate method names automatically. |

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
| OFX raw memo | `_essf_ofx_memo` postmeta | set only on OFX-imported entries (via the confirm step below), holds the untouched bank `MEMO`/`NAME` text; powers `ESSF_OFX_Suggestions` on future imports |
| OFX transfer detail | `_essf_ofx_detail` postmeta | set only when `ESSF_OFX_Parser::parse_transfer()` recognized the memo as a Pix transfer/refund — the capitalized counterparty name + document + bank/account detail that the short `<Kind> <Nome>` title dropped |
| Ownership (frontend only) | `post_author` | frontend shortcode CRUD is scoped per-user via `post_author`; the admin dashboard is not — it manages all entries regardless of author |

None of `_essf_fitid`, `_essf_ofx_memo`, or `_essf_ofx_detail` is registered via
`register_post_meta()` (unlike `_order_date`) — all three are set ad hoc via `update_post_meta()`
only on OFX-imported entries.

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
  (`admin_post_essf_add|update|delete|paid_today|toggle_type|export|import|import_ofx_confirm`),
  each with its own nonce constant (`ESSF_Admin_Page::ADD_NONCE` etc., including
  `IMPORT_OFX_CONFIRM_NONCE`) checked via `check_admin_referer()` — except `essf_export`/`essf_import`
  themselves, which predate that constant convention and still check bare string literals. Bulk
  actions are handled separately in `process_bulk_actions()` (hooked to `admin_init`, gated on
  `$_REQUEST['page'] === 'essfinance'`), nonce-checked against `bulk-entries`.
- **Frontend** (`ESSF_Shortcodes`): a single `handle_forms()` dispatcher hooked to
  `template_redirect` reads `essf_action` from `$_POST`/`$_GET` and routes to
  `login|register|add_entry|edit_entry|import|bulk_action` (POST) or
  `delete_entry|paid_today|toggle_type|export` (GET), each verifying its own nonce inline with
  `wp_verify_nonce()`. Every handler here also checks `is_user_logged_in()` and, for entry
  mutations, that `post_author` matches the current user.

Both surfaces redirect with `wp_safe_redirect()` + `exit` after mutating, and both support CSV
export/import (dedup by description+date+amount). **OFX/QFX import is admin-only** — the frontend
importer has never supported it, despite older docs implying parity; don't assume it exists there.
When adding a new action, add it to the surface(s) it belongs to, following the existing
nonce-per-action pattern — don't introduce a shared unauthenticated action namespace between admin
and frontend.

### Recurring plans (Bills/Loans/Financing)

Bills, Loans, and Financing plans (`ESSF_Bill_CPT`, `ESSF_Loan_CPT`, `ESSF_Financing_CPT`) are each a
config-only taxonomy (`essf_bill_cat`/`essf_loan_cat`/`essf_financing_cat`, registered with no post
type) — the term *is* the plan, with amount/category/etc. as term meta; there's no stored link
between a term and the `essf_cashflow` entries it produces, only title matching (see
`ESSF_CPT::find_entries_by_title()`). `ESSF_Plan_Detector` also runs on every `insert_entry()` call
to auto-create a backing term from naming conventions already present in hand-typed data.

`ESSF_Bill_CPT` drives a daily WP-Cron sweep (`CRON_HOOK`, shared with `ESSF_Financing_CPT`) that
materializes each period's entry on demand — no per-occurrence post is ever pre-created. A bill can
recur monthly (due day of month) or weekly (due day of week), and its name may optionally embed
`YYYY-MM-DD`/`YYYY-MM` placeholder tokens substituted with the real due date when each occurrence's
title is generated (`ESSF_Bill_CPT::expand_title()`).

`ESSF_Loan_CPT` matches `essf_cashflow` entries by title — either exactly equal to the term's name, or
in the numbered `"{term name} i/n"` shape (`find_own_entries()`). The moment a second entry sharing an
existing loan's exact title is inserted, `ESSF_Plan_Detector` automatically renumbers every matching
entry into `i/n` installments and refreshes the loan's principal (`ESSF_Loan_CPT::renumber_and_sync()`)
— no manual action needed; the "Group as Loan" bulk action (`ESSF_Admin_Page::handle_group_confirm()`)
remains for grouping entries whose titles differ.

### OFX import review flow (admin only)

`ESSF_Admin_Page::handle_import()` never inserts OFX/QFX rows directly. It parses the file via
`ESSF_OFX_Parser::parse()`, dedupes (exact match by `_essf_fitid` or by description+date+amount —
silently skipped; amount+date-only matches with a different description are kept but flagged
`possible_duplicate`, since a bank memo rarely matches a curated title verbatim), and checks each
row's memo against `ESSF_OFX_Suggestions::matches_excluded()` (flag: `suggested_exclude`) before
storing the surviving rows in a transient (`essf_ofx_review_<token>`, 15 minutes, keyed to
`get_current_user_id()` so one admin can't confirm another's staged import) and redirecting to
`?page=essfinance&essf_review=<short>`. `$token` is `bin2hex( random_bytes( 20 ) )` — 40 hex chars,
shaped like a git SHA-1 on purpose — but only its first 7 chars go in the URL, like a short git
ref. `resolve_review_token()` resolves that prefix back to the full token with a direct `LIKE`
query against `wp_options` (transients don't support a prefix lookup), the same way `git show
<short-sha>` resolves against the full object database; the full 40-char token is what actually
secures the transient lookup (the `user_id` ownership check runs after, unaffected by the prefix
step) and is what the review page's hidden `essf_review_token` field and `<dialog data-token>`
carry from then on — prefix resolution only happens once, on the initial GET.

That page renders a native `<dialog>` modal (`render_review_page()`) — one row per staged
transaction with an editable description (pre-filled from `ESSF_OFX_Suggestions::suggest()`,
ranked by similarity to prior `_essf_ofx_memo` values then frequency then recency; a
`possible_duplicate` match's own title is prepended to the suggestion list too, since that's
already a strong candidate) and an X/undo toggle button (not a checkbox — `.essf-ofx-row-toggle`
in `assets/js/admin.js` flips a hidden `essf_rows[i][include]` input and dims the row via
`.is-excluded`) to exclude/restore the row. Rows flagged `possible_duplicate` or
`suggested_exclude` start excluded by default (dimmed, with an explanatory note) but are always
shown, never silently dropped. There is no per-row paid/pending control — every confirmed row is
inserted as `paid` with the pay date set to the OFX due date, since a bank statement transaction
has already cleared.

`admin_post_essf_import_ofx_confirm` re-reads the transient and only trusts the posted
description/include fields — amount, date, FITID, and memo always come from the transient, never
from resubmitted form fields — then inserts via the same `insert_entry()` used everywhere else,
stamps `_essf_fitid` and `_essf_ofx_memo`, and learns from whatever the operator excluded this
round in two ways: `remember_excluded_memos()` appends normalized memos to the
`essf_ofx_excluded_memos` option (FIFO-capped at `ESSF_Admin_Page::EXCLUDED_MEMOS_LIMIT`, so
similar noise — balance lines, internal transfers — defaults to excluded on future imports too),
and `backfill_duplicate_memos()` tags the *existing* post a `possible_duplicate` row matched
(`find_possible_duplicate()`'s amount→[id,date,title] index carries the post ID for this) with
`_essf_ofx_memo`, if it doesn't already have one. Without that second step, a `possible_duplicate`
the operator leaves excluded — i.e. confirms is really the same transaction — would never itself
start feeding `ESSF_OFX_Suggestions::suggest()`, even for an entry that predates this feature or
was entered by hand/CSV; only brand-new OFX-inserted rows would. Both learning steps run only on
excluded rows, and only `possible_duplicate` rows carry a matched post ID to backfill —
`suggested_exclude` rows (noise) have no such match. Finally the transient is deleted. The
dedup/staging/confirm-merge logic (`build_ofx_stage_rows()`, `find_possible_duplicate()`,
`resolve_ofx_confirm_rows()`) is factored out as pure `public static` methods specifically so it's
unit-testable without mocking WordPress — preserve that split when touching this flow.

The transient is the durable source of truth for the staged rows themselves, but include/exclude
toggles and description edits only ever live in the DOM — a page reload would otherwise throw them
away mid-review. `assets/js/admin.js` mirrors that in-progress state into
`localStorage['essf_ofx_review_<token>']` on every toggle/edit (`saveState()`), replays it back
over the server-rendered defaults on load (`restoreState()`), and clears it on successful submit
(`clearState()`) — each `<tr>` carries `data-row="<i>"` and the `<dialog>` carries `data-token` so
JS can address rows/find the storage key without threading extra state through PHP.

Only one description cell can be in edit mode at a time (`closeOtherDescEdits()` commits or cancels
whatever was already open before a new one opens, depending on whether it was actually changed).
Confirming an edit runs `offerBulkApply()`, not an automatic mass-update: every OTHER row still
showing the old (un-customized) text gets a small "Use "<new value>"" link (`.essf-ofx-desc-apply-
link`, built client-side; its translated template travels via `data-apply-label` on the `<dialog>`
since PHP can't know the edited value ahead of time) that the operator clicks per row to apply it —
`applyBulkLink()` handles that click. A row already individually edited (its
`.essf-ofx-desc-indicator` is gone — see the indicator-removal comment in `commitDescEdit()`) never
gets offered the link, since that's the signal an operator deliberately wants that one row to
differ. Editing a row directly also clears any pending link on it first (`enterDescEdit()`), since
it's now stale. No separate "glossary" write is needed for any of this to teach future imports —
edited/link-applied values are just what ends up in each row's submitted
`essf_rows[i][description]`, which `handle_import_ofx_confirm()` already pairs with each row's own
raw memo as `_essf_ofx_memo` on insert, so the very next import benefits from
`ESSF_OFX_Suggestions::suggest()` picking it up.

### OFX Glossary page (admin only)

Since the "glossary" isn't a dedicated data store — it's just `_essf_ofx_memo`/`_essf_ofx_detail`
postmeta scattered across `essf_cashflow` posts, plus the `essf_ofx_excluded_memos` option — a bad
learned mapping (e.g. a badly-formatted raw memo that coincidentally matched something unrelated)
has to be fixed at the source. `?page=essfinance-ofx-glossary` (`render_glossary_page()`) lists
every post currently feeding `ESSF_OFX_Suggestions` (`ofx_glossary_entries()` — like
`ofx_memo_history()` but also carries the post ID) alongside the `essf_ofx_excluded_memos` list,
each row with a "Forget" action. `handle_forget_memo()` (per-post nonce, same pattern as
`handle_delete()`) clears just that post's `_essf_ofx_memo`/`_essf_ofx_detail` — the entry itself,
its title, amount, everything else, is untouched. `handle_forget_excluded()` (one shared nonce,
same lighter-weight pattern as `bulk-entries`) removes one exact pattern from the option array.
Neither deletes real financial data; both only affect what future imports get suggested/pre-
excluded.

### Legacy (archived, not loaded)

`legacy/pre-0.3.0/` preserves the plugin's original single-file (`includes/core.php`) architecture
from before the v0.3.0 rewrite, kept for reference only — see `legacy/pre-0.3.0/README.md`. It is
never `require`d by `essfinance.php` and should not be treated as current. There are currently no
active `migrate-*.php` scripts in the plugin root (the archived ones target the pre-0.3.0 data
format and don't apply to the current schema); if a future release needs a data-shape migration,
add a new `migrate-0.X.Y.php` at the plugin root following the archived ones as a style reference.

## Release process

Add a `readme.txt` `== Changelog ==` entry for the target version first (readme.txt is the single
changelog — there is no separate `CHANGELOG.md` in the active tree), then run
`wp --require=bin/release.php essf release <patch|minor|major|X.Y.Z> --summary="<short summary>"`.
That command bumps `Version:`/`ESSF_VERSION` in `essfinance.php` and `Stable tag:` in `readme.txt`,
refuses to proceed if the changelog entry for the target version is missing or still its own
auto-inserted placeholder, regenerates `README.md` via `npm run readme` (see below), then commits
(`release: vX.Y.Z — <summary>`), tags `vX.Y.Z`, and pushes — each step skippable with
`--no-commit`/`--no-tag`/`--no-push`. Feature/fix commits use a short type prefix (`fix:`,
`admin:`, `settings:`, `css:`, `tests:`, `ci:`, …).

`README.md` is generated from `readme.txt` via `npm run readme` (`Gruntfile.js`,
`grunt-wp-readme-to-markdown`) — don't hand-edit it. A custom `essf_inject_readme_extras` Grunt
task runs right after and injects GitHub-only content (the Playground badge, a Development
section) that has no equivalent in `readme.txt`, wrapped in `<!-- essf:... -->` marker comments so
re-running the task is idempotent instead of duplicating that content.
