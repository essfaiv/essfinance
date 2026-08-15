# EssFinance #
**Contributors:** [essfinance](https://profiles.wordpress.org/essfinance/)  
**Tags:** finance, cashflow, income, expense, budget  
**Requires at least:** 4.9  
**Tested up to:** 6.9  
**Requires PHP:** 8.0  
**Stable tag:** 0.3.4  
**License:** GPL-2.0-or-later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  

Simple personal finance management — track income and expenses with a clean cash flow view.

<!-- essf:playground-badge -->
[![Try in Playground](https://img.shields.io/badge/Try%20in-WordPress%20Playground-3858e9?logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/essfaiv/essfinance/main/blueprint.json)
<!-- /essf:playground-badge -->

## Description ##

EssFinance lets you manage your personal finances directly from WordPress. Log income and expense entries, set due dates, mark items as paid, and view a filterable cash flow summary on any page or post using a shortcode.

**Features:**

* Add, edit, and delete cash flow entries (income or expense)
* Assign a due date and optional paid date to each entry
* Status tracking: pending, paid, overdue
* Filter by status, type (income/expense), month, or free-text search
* Bulk actions: mark as paid today, mark as pending, change type, delete
* Import entries from CSV or OFX files
* Export filtered entries to CSV
* Configurable currency symbol and display options
* Frontend shortcode `[essfinance_cashflow]`
* Fully translatable

## Installation ##

1. Upload the `essfinance03` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress Plugins screen directly.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **Cash Flow** in the admin menu.
4. Add the shortcode `[essfinance_cashflow]` to any page to display the cash flow list on the front end.

## Frequently Asked Questions ##

### What file formats can I import? ###

CSV (comma-separated) and OFX (Open Financial Exchange) files are supported.

### What is the minimum PHP version required? ###

PHP 8.0 or higher. WordPress 4.9 or higher is required.

### Is the plugin translatable? ###

Yes. All strings are wrapped in standard WordPress i18n functions with the `essfinance` text domain.

## Changelog ##

### 0.3.4 ###
* New `wp essf ofx-view` WP-CLI command — dumps an OFX/QFX file to a CSV or Markdown table for offline reconciliation (read-only, no DB writes).
* New `wp essf ofx-audit` WP-CLI command — replays the import description-suggestion pipeline against everything already imported to find entries that would still need a manual edit, and doubles as a full CSV/Markdown export of every imported OFX entry (read-only, no DB writes).
* Admin OFX import now opens a review modal (editable description with suggestions from past imports) before anything is saved, instead of inserting immediately.
* Every imported OFX entry lands as "paid" with the bank's posted date, instead of always landing "pending".
* Review flags likely duplicates (same amount, nearby date, different description) and rows resembling ones you've excluded before (balances, internal transfers, etc.) — both are shown dimmed by default; click the ✕ to exclude/restore any row.
* Confirming a duplicate as excluded now also tags the existing entry it matched with the bank memo, so it starts feeding description suggestions too — not just entries created via this import flow.
* Admin dashboard only — the frontend importer remains CSV-only.

### 0.3.2 ###
* PHP 7.2 and WordPress 4.9 minimum requirements — removed typed properties, arrow functions, `str_starts_with`, and `wp_date` from production code.
* PHPCS/WPCS + PHPCompatibilityWP tooling with `phpcs.xml.dist` and `composer lint`.
* Pre-commit git hook installed automatically via `composer install`; blocks WPCS violations locally before they reach CI.
* GitHub Actions: `headers` job validates header consistency between `essfinance.php` and `readme.txt`; `phpcs`, `phpunit` (PHP 8.1–8.3), and `phpstan` jobs added.
* PHPUnit infrastructure: `composer.json`, `phpunit.xml`, `tests/bootstrap.php`, and unit test suite covering Settings, CPT, Shortcodes handlers/filters, ListTable, and AdminPage (OFX parser, CSV formatter, form data parser).
* Playwright E2E infrastructure: `playwright.config.ts`, auth fixture, and specs for CRUD, filters, bulk actions, import/export, settings, and visual regression.
* Nonce field hardened with `sanitize_text_field()` in meta-boxes save handler.
* GPL-2.0-or-later license header added to all PHP files.
* `Tested up to: 6.9`.

### 0.3.1 ###
* Security hardening: explicit nonce sanitization, GPL-2.0-or-later licensing.
* Month filter now matches the pay date (not due date) for paid entries.
* Row actions preserve current filters and pagination on redirect.
* "Paid date" row action pre-fills today's date and sets status to paid.
* Added "All statuses" dropdown to admin list table.

### 0.3.0 ###
* Rewrite with improved architecture: CPT, Settings, Shortcodes, Admin Page, List Table, Meta Boxes, Assets.
* OFX import support.
* Bulk actions.
* Overdue status.

### 0.2.0 ###
* Added CSV import/export.
* Filter by month.

### 0.1.0 ###
* Initial release.

<!-- essf:development -->
## Development ##

```bash
git clone git@github.com:essfaiv/essfinance.git
```

Load the plugin in a local WordPress site. The **Try in Playground** badge above opens the latest release in [WordPress Playground](https://playground.wordpress.net).
<!-- /essf:development -->
