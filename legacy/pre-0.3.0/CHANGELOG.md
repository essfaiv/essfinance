# EssFinance Changelog

All notable changes to EssFinance are documented in this file.

## [0.2.2] - 2026-01-23

### 🐛 Critical Bug Fixes
- **CRITICAL**: Fixed bug where Pay Date was being overwritten with today's date when editing entries
  - Previously, if due date was in the past and pay date was empty during edit, it would auto-fill with today
  - Now respects user input for pay date during edits
  - Only auto-fills pay date when marking status as "Paid"
- **Fixed blank screen issue** after adding/updating entries
  - Moved form submission handling to `admin_init` hook to execute before any output
  - Redirects now work properly without displaying blank pages

### ✨ New Features & Improvements

#### Data Format Changes
- Amount storage format (post_content and CSV):
  - Income: positive without sign (e.g., `8000` or `8000.5` - omit .00, convert .X0 to .X)
  - Expense: negative with sign (e.g., `-750` or `-750.5`)
  - Examples: `5000.00` → `5000`, `145.40` → `145.4`, `8000.50` stays `8000.50`
- Display format for listagem (UI):
  - Income: `+` prefix with BRL format (e.g., `+8.000,00`) - always 2 decimals
  - Expense: `-` prefix with BRL format (e.g., `-750,00`)
- Automatically infers income vs expense from storage sign for backward compatibility

#### CSV Export Feature
- Added **Export CSV** button in bulk actions area
- Exports ALL entries from all months in a single file
- CSV contains: Description, Due Date, Pay Date, Status, Amount
- Date formatting: Uses WordPress settings (e.g., DD/MM/YYYY)
- Amount format in CSV:
  - Income: positive without sign (e.g., `8000` or `8000.5`)
  - Expense: negative with sign (e.g., `-150` or `-750.5`)
  - Omit .00 if integer, convert .X0 to .X
- Sorted by due date (ascending)
- Timestamp included in exported filename

#### UI/UX Improvements
- **Simplified Amount Input**: Removed "Expense/Income" dropdown before amount field
- **New Income Checkbox**: Added `[ ] Income` checkbox after amount field for cleaner UI
- **Better Listing Display**:
  - Income amounts now show with `+` prefix (e.g., `+8000.00`)
  - Expense amounts display normally (e.g., `750`)
  - Removed inline "Expense/Income" type labels from list view
  - Type is now indicated by the amount sign prefix
- **Consistent Form Design**: Both add and edit forms now use the same checkbox pattern

### 🔄 Migration
- Included `migrate-0.2.2.php` script for automatic data migration
- Works with WP CLI: `wp eval-file wp-content/plugins/essfinance/migrate-0.2.2.php`
- No data loss during migration - all entries preserved with updated format
- Detailed migration output shows exactly what was updated
- See `UPGRADE-0.2.2.md` for detailed migration instructions

## [0.2.1] - 2026-01-22

### 🐛 Bug Fixes
- Fixed missing form and entries list display on admin page
- Fixed unclosed `<script>` tag in bulk actions JavaScript
- Form and table now render correctly

## [0.2.0] - 2026-01-22

### ✨ New Features

#### Smart Date Handling
- Due Date field is now optional (defaults to today if empty)
- Auto-pay feature: If due date is in the past and payment date is empty, auto-fills payment date with today
- Improved smart defaults for date handling

#### Bulk Actions System
- Added checkboxes to each entry in the list
- Added "Select All" checkbox in table header
- Created bulk actions dropdown with three actions:
  - **Mark as Paid Today**: Sets status to 'paid' with current date
  - **Mark as Pending**: Reverts status to 'pending'
  - **Delete**: Removes selected entries
- Apply button to execute bulk actions
- Confirmation dialog for delete actions

#### Entry Type Tracking
- New meta field `_entry_type` (values: 'income' or 'expense')
- Type selector in Add Entry form (Expense/Income dropdown)
- Type selector in Edit Entry form
- Type displayed below description in listing (small label)
- Defaults to 'Expense' for backward compatibility

#### Date Formatting & Validation
- `essfinance_format_date()` function formats dates using WordPress settings
- `essfinance_validate_date()` function validates date ranges
- Prevents invalid dates: Feb 30, day > 31, invalid month ranges
- Handles leap years correctly
- Dates in listing now formatted per WordPress configuration
- Date validation on form submission (both add and update)

#### UI/UX Improvements
- Action links now hidden until row hover (cleaner appearance)
- Added opacity transition for smooth reveal on hover
- Improved form layout with flex containers
- Better visual hierarchy in listings

### 🔧 Technical Changes

#### New Helper Functions
- `essfinance_format_date( $date_str )` - Format dates per WordPress settings
- `essfinance_validate_date( $date_str )` - Validate YYYY-MM-DD dates with leap year support
- `essfinance_parse_form_date( $date_input )` - Parse form date inputs

#### Database Schema
- Added meta key: `_entry_type` (string, single)
- Existing entries default to 'expense' type

#### Admin Init Hook Changes
- Added bulk action processing before page rendering
- Handles: mark_paid, mark_pending, delete operations
- Proper nonce verification and sanitization

#### Form Processing Changes
- ADD ENTRY: Validates dates, saves entry_type
- UPDATE ENTRY: Validates dates, saves entry_type
- Both operations support smart date defaults

#### Rendering Changes
- Table header now includes checkbox column
- Each row includes entry type label
- Dates formatted using WordPress settings
- Actions column hidden until hover

### 🔒 Security
- All bulk action IDs sanitized as integers
- Nonce verification maintained
- SQL-safe operations via WordPress post functions
- Input validation on all date fields
- Output properly escaped

### 📊 Data Migration
No database migration required. New fields are optional:
- Existing entries will default to 'expense' type
- Existing dates continue to work as before

## [0.1.0] - 2026-01-15

### ✨ Initial Release

#### Features
- Two-column layout (form 350px, table flexible)
- Hover action links (Edit, Paid Today, Paid Date, Delete)
- Single intelligent date column display
- Full-page edit form
- Status tracking (pending, paid, overdue)
- Monthly filtering
- Post type with custom statuses
- WordPress admin integration

#### Core Functionality
- Add/Edit/Delete entries
- Monthly filtering by payment date
- Description field for entries
- Due Date field (required)
- Pay Date field (optional)
- Amount field with 2 decimal places
- Status selector (pending/paid)
- Virtual overdue status (pending + past due_date)
- Quick edit in modal
- Instant "Paid Today" action
- "Paid Date" action for custom payment date
- Extensible module system
- Standard WordPress data storage

#### Data Structure
- `post_title`: Description
- `post_date_gmt`: Due Date
- `post_modified_gmt`: Payment Date
- `post_content`: Amount
- `post_status`: pending or paid
- `_order_date`: Filter/sort key (YYYY-MM-DD)

#### Bug Fixes & Improvements
- Fixed blank admin page on add/delete: moved form handling before render
- Fixed nonce checks: improved validation logic
- Overdue as VIRTUAL status: calculated, not stored
- Filter by pay_date: entries show under payment month
- Removed overdue from permanent statuses: now only pending/paid

---

## Version History

| Version | Date | Status |
|---------|------|--------|
| 0.2.0 | 2026-01-22 | Current |
| 0.1.0 | 2026-01-15 | Initial |
