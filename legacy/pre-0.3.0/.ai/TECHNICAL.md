# EssFinance v0.2.0 Technical Implementation

## Overview

This document details the technical implementation of v0.2.0 features including bulk actions, entry type tracking, date formatting, and UI improvements.

## New Helper Functions

### `essfinance_format_date( $date_str )`

Formats a date string according to WordPress settings.

```php
function essfinance_format_date( $date_str ) {
    if ( empty( $date_str ) || '0000-00-00' === $date_str ) {
        return '';
    }
    $timestamp = strtotime( $date_str );
    return date_i18n( get_option( 'date_format' ), $timestamp );
}
```

**Parameters:**
- `$date_str` (string): Date in YYYY-MM-DD format

**Returns:**
- (string): Formatted date per `date_format` WordPress option, or empty string

**Usage:**
```php
$formatted = essfinance_format_date( '2026-01-22' );
// Output: "01/22/2026" or "22/01/2026" based on WordPress settings
```

### `essfinance_validate_date( $date_str )`

Validates date format and range, including leap year calculation.

```php
function essfinance_validate_date( $date_str ) {
    // Accepts empty dates (optional fields)
    // Validates YYYY-MM-DD format
    // Checks month range (1-12)
    // Checks day range (1-31, considering month length)
    // Handles leap years (4-year, 100-year, 400-year rules)
}
```

**Parameters:**
- `$date_str` (string): Date to validate

**Returns:**
- (boolean): `true` if valid or empty, `false` if invalid

**Validation Rules:**
- Empty dates return `true` (field is optional)
- Non-matching YYYY-MM-DD format returns `false`
- Month must be 1-12
- Day must be valid for the month and year
- Leap year: divisible by 4 AND (not divisible by 100 OR divisible by 400)

**Examples:**
```php
essfinance_validate_date( '2026-02-28' );  // true (valid)
essfinance_validate_date( '2026-02-29' );  // false (not leap year)
essfinance_validate_date( '2024-02-29' );  // true (leap year)
essfinance_validate_date( '2026-13-01' );  // false (invalid month)
essfinance_validate_date( '' );             // true (empty allowed)
```

## Database Schema Changes

### New Meta Field

- **Key:** `_entry_type`
- **Type:** string
- **Values:** 'income' or 'expense'
- **Default:** 'expense' (for existing entries)
- **Scope:** Per post (essf_cashflow post type)

**Registration:**
```php
register_post_meta( 'essf_cashflow', '_entry_type', array(
    'type' => 'string',
    'single' => true
) );
```

## Bulk Actions Implementation

### URL Parameters

Bulk actions are triggered via GET parameters:

```
?page=essfinance&bulk_action=ACTION&entry_ids=ID1,ID2,ID3
```

**Supported Actions:**
- `mark_paid`: Set status to 'paid' and update pay date to today
- `mark_pending`: Set status to 'pending'
- `delete`: Delete selected entries

### Processing Flow

1. Admin init hook checks for `bulk_action` and `entry_ids` parameters
2. Executes before any page output (prevents header errors)
3. IDs sanitized as integers, exploded from comma-separated string
4. Action executed for each ID
5. Redirects back without query parameters

### JavaScript Implementation

```javascript
// Select all checkboxes
document.getElementById('select-all-entries').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.essfinance-entry-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
});

// Apply bulk action
document.getElementById('bulk-action-apply').addEventListener('click', function() {
    const action = document.getElementById('bulk-action-select').value;
    const checkboxes = document.querySelectorAll('.essfinance-entry-checkbox:checked');

    if (!action) {
        alert('Please select an action');
        return;
    }
    if (checkboxes.length === 0) {
        alert('Please select at least one entry');
        return;
    }
    if (action === 'delete' && !confirm('Are you sure?')) {
        return;
    }

    const ids = Array.from(checkboxes).map(cb => cb.value).join(',');
    const current_url = new URL(window.location);
    current_url.searchParams.set('bulk_action', action);
    current_url.searchParams.set('entry_ids', ids);
    window.location = current_url.toString();
});
```

## Form Implementation

### Add Entry Form

**Fields:**
- Description (required, text)
- Due Date (optional, HTML date input)
- Pay Date (optional, HTML date input)
- Entry Type (required, select: Expense/Income)
- Amount (required, number, 2 decimals)
- Status (required, select: Pending/Paid)

**Form Structure:**
```html
<select name="entry_type">
    <option value="expense">Expense</option>
    <option value="income">Income</option>
</select>
<input type="number" name="amount" />
```

### Edit Entry Form

Same as Add Entry, but values are pre-populated from the post:

```php
$entry_type = get_post_meta( $post->ID, '_entry_type', true );
if ( ! $entry_type ) {
    $entry_type = 'expense'; // Default for old entries
}

// In form:
<option value="expense" <?php selected( $entry_type, 'expense' ); ?>>Expense</option>
<option value="income" <?php selected( $entry_type, 'income' ); ?>>Income</option>
```

## CSS Changes

### Hidden Action Links Until Hover

```css
.essfinance-actions a {
    opacity: 0;
    transition: opacity 0.2s ease;
}

tr:hover .essfinance-actions a {
    opacity: 1;
}
```

**Behavior:**
- Links start invisible (opacity: 0)
- On row hover, links fade in (200ms transition)
- Improves visual hierarchy and reduces clutter

### Checkbox Column

```css
.essfinance-checkbox-column {
    width: 40px;
    text-align: center;
}
```

**Sizing:**
- Fixed 40px width for checkbox column
- Centered alignment
- Consistent across all rows

## Data Flow

### Adding Entry with Entry Type

```
1. Form submitted with entry_type="income"
2. essfinance_handle_form() processes POST
3. Validates all fields including dates
4. wp_insert_post() creates post
5. update_post_meta() saves _entry_type
6. update_post_meta() saves _order_date
7. Redirects to referer
```

### Updating Entry with New Type

```
1. Form submitted with modified entry_type
2. essfinance_handle_form() processes POST
3. wp_update_post() updates post fields
4. update_post_meta() updates _entry_type
5. Redirects to main page
```

### Bulk Marking as Paid

```
1. User selects entries and chooses "Mark as Paid Today"
2. URL: ?bulk_action=mark_paid&entry_ids=ID1,ID2,ID3
3. admin_init processes before page render
4. For each ID:
   - wp_update_post() sets status to 'paid'
   - Sets post_modified_gmt to current_time()
   - update_post_meta() sets _order_date to today
5. Redirects to clean page
```

## Formatting in Listings

### Date Display

Each item's date is now formatted:

```php
$display_date = ! empty( $item['pay_date'] ) ? $item['pay_date'] : $item['due_date'];
$formatted_date = essfinance_format_date( $display_date );

// In table:
<td><?php echo esc_html( $formatted_date ); ?></td>
```

**Logic:**
1. Use pay_date if available, otherwise use due_date
2. Format using WordPress date_format option
3. Escape for HTML output

### Entry Type Display

```php
$type_label = $item['entry_type'] === 'income' ? 'Income' : 'Expense';

// In table, below title:
<span style="font-size: 12px; color: #999;"><?php echo esc_html( $type_label ); ?></span>
```

**Styling:**
- Small text (12px)
- Gray color (#999)
- Below description text

## Backward Compatibility

### Existing Entries

All new features are backward compatible:

1. **Entry Type:** Defaults to 'expense' when not set
2. **Dates:** No changes to date storage format
3. **Form:** All existing fields work unchanged
4. **Validation:** Validates dates when saving, doesn't affect existing

### Migration Path

No migration required. Old entries continue to work:

```php
$entry_type = get_post_meta( $post->ID, '_entry_type', true );
if ( ! $entry_type ) {
    $entry_type = 'expense'; // Automatic default
}
```

## Error Handling

### Date Validation Errors

When saving invalid dates:

```php
if ( ! essfinance_validate_date( $due_date_input ) ) {
    wp_die( 'Invalid due date format.' );
}
```

**User Experience:**
- Shows error message
- Prevents invalid data from being saved
- User can use browser back button to correct

### Bulk Action Errors

Handled via JavaScript:

```javascript
if (!action) {
    alert('Please select an action');
    return;
}
```

## Performance Considerations

1. **Date Formatting:** Done at render time, not stored
2. **Bulk Operations:** Loop through IDs, WordPress handles batching
3. **Meta Queries:** Single value per post, no complex queries
4. **Checkboxes:** DOM queries on page load only
5. **No N+1 queries:** All posts loaded once, meta retrieved in bulk

## Testing Checklist

- [ ] Add entry as Expense
- [ ] Add entry as Income
- [ ] Verify entry_type displays correctly
- [ ] Edit entry and change type
- [ ] Verify date validation (Feb 29 non-leap year, etc.)
- [ ] Bulk select all entries
- [ ] Bulk select some entries
- [ ] Mark as paid via bulk action
- [ ] Mark as pending via bulk action
- [ ] Delete via bulk action
- [ ] Verify action links hidden until hover
- [ ] Check dates formatted per WordPress settings
- [ ] Old entries show as Expense (default)
- [ ] Try invalid dates in form
