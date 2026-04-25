# EssFinance — Roadmap & Context

## What it is

A simple personal finance management WordPress plugin. It uses a Custom Post Type (`essf_cashflow`) to record cash flow entries with the following fields: description, due date, payment date, amount, type (income/expense), and status.

The interface lives entirely in wp-admin — no public frontend — and follows the native WordPress visual pattern (taxonomy screen two-column layout, `.wp-list-table`, `.form-wrap`).

---

## Current state — v0.3.0

### Architecture

```
essfinance/
├── essfinance.php               # bootstrap via plugins_loaded + activation hook
├── uninstall.php                # cleans posts on uninstall
├── includes/
│   ├── class-cpt.php            # registers CPT essf_cashflow + flush on activate
│   ├── class-meta-boxes.php     # meta box in WP editor (fallback/power user)
│   ├── class-admin-page.php     # main dashboard + form handlers
│   └── class-assets.php         # enqueue CSS/JS only on plugin screens
└── assets/
    ├── css/admin.css            # badges, toggle, month rows — minimal over WP default
    └── js/admin.js              # dd/mm/yyyy date mask + expense/income toggle
```

### Fields per entry (`_essf_*` post meta)

| Meta key            | Type    | Description                         |
| ------------------- | ------- | ----------------------------------- |
| `_essf_description` | string  | Entry description                   |
| `_essf_due_date`    | Y-m-d   | Due date (stored in ISO)            |
| `_essf_pay_date`    | Y-m-d   | Payment date (stored in ISO)        |
| `_essf_amount`      | float   | Absolute value (always positive)    |
| `_essf_is_income`   | '0'/'1' | '1' = income, '0' = expense         |
| `_essf_status`      | string  | pending / paid / overdue / canceled |

### Dashboard UI

* Two-column layout (WP taxonomy standard): form on the left, listing on the right
* Listing grouped by month (based on `_essf_due_date`, fallback `post_date`)
* Each month group shows subtotals for income and expenses for the period
* Inline actions: Edit (fills the form on the left) and Delete (confirm + `admin-post.php`)
* Left-side form toggles between "Add Entry" and "Edit Entry" via `?entry=ID`
* Native WP notices after each action

### Relevant technical decisions

* **No Gutenberg block** — entire UI is pure PHP/HTML in wp-admin; block may come later
* **Non-public CPT** — `show_in_menu => false`; navigation stays inside the EssFinance menu
* **`admin_post_{action}`** for all forms — WP standard, secure, no output buffering
* **`wp_date()`** for display (timezone-aware); dates always stored as `Y-m-d`
* **`wp_unslash()` before sanitizing** on all `$_POST` — required by WP security guidelines
* **Status validated with `array_key_exists()`** against a fixed list before saving
* **`uninstall.php`** with `WP_UNINSTALL_PLUGIN` guard — removes all posts on uninstall

---

## Planned next versions

### v0.4.0 — Filters and search

* Filter listing by month, type (income/expense), and status
* Search field by description
* Clickable sorting on table columns (amount, due date)

### v0.5.0 — Categories

* Taxonomy `essf_category` (e.g., Housing, Food, Transport, Salary)
* Filter by category in the listing
* "Category" column in the table

### v0.6.0 — Recurrence

* "Recurrence" field (single / monthly / yearly)
* Automatic generation of future entries via WP-Cron
* Visual indicator for recurring entries in the listing

### v0.7.0 — Financial summary

* Dashboard page with charts (Chart.js or native WP)
* Balance per month (income − expenses)
* Balance evolution over time
* Top 5 expense categories

### v1.0.0 — Polish and distribution

* Full internationalization (`.pot` / `.po`)
* Configurable currency (Settings API)
* Configurable date format (dd/mm/yyyy or mm/dd/yyyy)
* CSV export
* Tested multisite compatibility
* README for the WordPress.org plugin directory
* PHPUnit tests for form handlers

---

## Reimplementation — quick guide

If rewriting from scratch or porting to another context:

1. **CPT** — register `essf_cashflow` with `public => false`, `show_ui => true`, `show_in_menu => false`
2. **Meta** — all fields are post meta prefixed with `_essf_`; no custom tables
3. **Dashboard** — menu page with `add_menu_page`; two-panel layout (form + list)
4. **Forms** — use `admin_post_{action}` + `check_admin_referer()` + `current_user_can('manage_options')`
5. **Security** — always `wp_unslash()` → `sanitize_*()` on input; `esc_*()` on output
6. **Dates** — store `Y-m-d`, display with `wp_date('d/m/Y', strtotime($val))`
7. **Grouping** — `group_by_month()` uses `_essf_due_date` with fallback to `post_date`
