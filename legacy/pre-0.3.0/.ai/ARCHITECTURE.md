# ESSFINANCE v0.x.0 - ARCHITECTURE

## OVERVIEW
WordPress plugin for personal cashflow management. Post-based with standard WordPress fields.

## DATA MODEL
- **Post Type**: essf_cashflow
- **Statuses**: pending, paid (only 2)
- **Storage**:
  - `post_title` → Description
  - `post_date_gmt` → Due Date (YYYY-MM-DD HH:MM:SS)
  - `post_modified_gmt` → Pay Date (YYYY-MM-DD HH:MM:SS, or 0000-00-00 if empty)
  - `post_content` → Amount (numeric)
  - `post_status` → pending|paid
  - `_order_date` (meta) → Filter key YYYY-MM-DD (pay_date if set, else due_date)
- **Overdue**: Virtual status (pending + due_date < today, never stored)

## STRUCTURE
```
essfinance/
├── essfinance.php (entry point)
├── includes/
│   ├── core.php - admin page, forms, actions, edits
│   ├── modules/
│   │   ├── autoload.php - auto-loads any .php in modules folder
│   │   └── README.md - module developer guide
│   ├── actions.php
│   ├── admin-menu.php
│   └── ... (other components)
├── .ai/ (documentation)
└── README.md (user guide)
```

## UI LAYOUT

**Two-column design:**
- Left (350px): Add Entry form
- Right (flex): Month selector + Entries table

**Actions visible on hover:**
- Edit - opens modal for editing
- Paid Today - marks paid with today's date
- Paid Date - opens edit page with pay_date field focused
- Delete - removes entry with confirmation

**Single Date column:**
- Shows pay_date if exists, else due_date
- Sorted by _order_date meta field

## KEY FEATURES
- **CRUD**: Create/Read/Update/Delete
- **Smart Dates**: pay_date/due_date independent (no conflicts)
- **Overdue**: Virtual - pending + due < today
- **Filter**: By month (pay_date priority, then due_date)
- **Inline Edit**: No modal, WordPress-like inline form
- **Actions**: Edit | Delete | Paid Today | Paid (custom)
- **Date Column**: Single column (shows relevant date)

## FUNCTIONS
- `essfinance_register_post_types()` - register + meta fields
- `essfinance_admin_menu()` - admin menu
- `essfinance_page()` - render page with form + table
- `essfinance_handle_form()` - process actions (GET/POST)

## ACTION FLOWS

### Add Entry (POST)
```
Form submit → essfinance_handle_form() → wp_insert_post() → update_post_meta()
```

### Edit Entry (GET + POST)
```
GET ?action=edit&id=123 → admin_init shows inline form
Form submit → essfinance_handle_form() → wp_update_post()
```

### Mark Paid Today (GET)
```
GET ?action=mark_paid&id=123&_wpnonce=... → mark paid + set date to today
```

### Mark Paid Custom (GET + POST)
```
GET ?action=mark_paid_custom&id=123 → admin_init shows date form
Form submit → essfinance_handle_form() → update status + date
```

### Delete (GET)
```
GET ?action=delete_entry&id=123&_wpnonce=... → wp_delete_post()
```

## UI LAYOUT
```
[Month Selector]

Add Entry [Form]
Title, Due Date, Pay Date, Amount, Status

Entries for [Month] [Table]
| Description | Date | Status | Amount | Actions |
| Invoice ABC | 2024-01-22 | ✓ Paid | 1500.00 | Edit | Delete |
```

## FILTERING
```php
// Show entry if in current month
$filter_date = !empty($pay_date) ? $pay_date : $due_date;
if (substr($filter_date, 0, 7) === '2024-01') {
    // Show in January 2024
}
```

## STATUS CALCULATION
```php
$status = $post->post_status;  // 'pending' or 'paid'
if ('pending' === $status && $due_date < date('Y-m-d')) {
    $status = 'overdue';  // Virtual, only for display
}
```

## SECURITY
- Nonce checks: add_entry, edit, delete, mark_paid
- Sanitize: sanitize_text_field()
- Escape: esc_html(), esc_attr(), esc_url()
- Meta validation: floatval() for amounts

## MIGRATION (v1 → v2)
Script: `includes/migrate-v2.php`
```php
$due_date = substr($post->post_date_gmt, 0, 10);
$pay_date = ($post->post_modified_gmt !== '0000-00-00 00:00:00')
    ? substr($post->post_modified_gmt, 0, 10)
    : '';

update_post_meta($post->ID, '_due_date', $due_date);
update_post_meta($post->ID, '_pay_date', $pay_date);
```
