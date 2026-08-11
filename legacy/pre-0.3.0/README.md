# Pre-0.3.0 architecture (archived)

This directory preserves the plugin's original monolithic architecture, superseded
by the v0.3.0 rewrite ("Rewrite with improved architecture: CPT, Settings,
Shortcodes, Admin Page, List Table, Meta Boxes, Assets" — see `readme.txt`).

None of this code was ever part of this git repository's history — the repo's
root commit is already `Release 0.3.0`. These files were carried over as loose
working-tree files from before the rewrite and are archived here purely as a
reference in case a similar structure is ever needed again. **Nothing here is
loaded by the active plugin** (`essfinance.php` only requires `includes/class-*.php`).

## What's here

- `includes/core.php` — the original single-file monolith (routing, CPT
  registration, admin UI, and all form/action handling in one `admin_init`
  closure). Replaced by `includes/class-cpt.php`, `class-admin-page.php`,
  `class-list-table.php`, `class-meta-boxes.php`, `class-settings.php`,
  `class-shortcodes.php`, and `class-assets.php`.
- `includes/modules/` — an unused extension-point scaffold (`autoload.php`
  auto-required `includes/modules/*.php`; no modules ever shipped).
- `migrate-0.2.2.php`, `migrate-0.2.7.php`, `migrate-0.2.9.php`,
  `migrate-0.2.10.php` — one-off WP-CLI data migration scripts for the pre-0.3.0
  amount-sign storage format. Not applicable to the current data model.
- `CHANGELOG.md` — changelog entries for 0.1.0–0.2.2, predating this repo.
  Current releases are documented in `readme.txt`'s `== Changelog ==` section.
- `UPGRADE-0.2.2.md` — upgrade notes for v0.2.2.
- `.ai/` — architecture/API docs describing the pre-rewrite code (mentions
  `core.php`, an `_entry_type` meta field, etc.). Superseded by the current
  code; do not use as a reference for the active plugin.

## Data model differences (for reference)

The pre-0.3.0 code and current code share some conventions (post type
`essf_cashflow`, amount sign in `post_content` encoding income/expense,
`pending`/`paid` post statuses) but differ in others — e.g. the current code
uses meta key `_order_date` computed from due/pay date, and status/date
handling moved from a single `admin_init` closure into
`ESSF_Admin_Page`/`ESSF_Meta_Boxes`/`ESSF_Shortcodes`. Treat `includes/class-*.php`
in the plugin root as the only source of truth for the current behavior.
