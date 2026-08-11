# EssFinance v0.2.2 - Upgrade Guide

## What's New in v0.2.2

### Bug Fixes
- **Fixed critical bug** where Pay Date was being overwritten with today's date when editing entries
  - Previously, if you edited an entry and the due date was in the past, the pay date would be automatically set to today, even if you specified a different date
  - Now the pay date respects your input and is only auto-filled when marking as "Paid"

### UI/UX Improvements
- **New format for Amount display**: Income now shows with a `+` prefix (e.g., `+8000.00`), expenses show normally (e.g., `750`)
- **Simplified Amount field**: Removed the "Expense/Income" dropdown before the amount input
- **New Income checkbox**: Checkbox now appears after the amount field with label `[ ] Income` for better UX
- **Cleaner list view**: Removed "Expense/Income" label from the entry list, type is now indicated by the amount sign

### Data Format Changes
The internal storage format for amounts has changed to include a sign prefix:
- **Income entries**: Stored with positive sign (e.g., `+8000.00`)
- **Expense entries**: Stored with negative sign (e.g., `-750`)

This ensures data consistency and allows for better financial calculations.

## Migration from v0.2.1

A migration script is provided to automatically update your existing data without any loss.

### Option 1: Using WP CLI (Recommended)

```bash
# Navigate to your WordPress root directory
cd /path/to/wordpress

# Run the migration script
wp eval-file wp-content/plugins/essfinance/migrate-0.2.2.php
```

### Option 2: Using wp-cli eval

```bash
wp eval "require_once 'wp-content/plugins/essfinance/migrate-0.2.2.php';"
```

### Option 3: Manual PHP Execution

If you don't have WP CLI, you can manually execute the script:

1. Place the migration file in your WordPress root or accessible directory
2. Create a temporary file (e.g., `run-migration.php`):

```php
<?php
// Load WordPress
require_once 'wp-load.php';

// Run migration
require_once 'wp-content/plugins/essfinance/migrate-0.2.2.php';
?>
```

3. Access it via browser or command line:
```bash
php run-migration.php
```

## What the Migration Does

1. **Scans all EssFinance entries** for data that needs updating
2. **Preserves all existing data** - No entries are deleted or merged
3. **Sets proper entry types** - Ensures all entries have the correct type metadata
4. **Converts amounts to signed format**:
   - All expenses are prefixed with negative sign
   - All income is prefixed with positive sign
5. **Provides detailed output** - Shows exactly what was migrated

## Example Migration Output

```
Starting migration of EssFinance entries to v0.2.2...
Total entries to process: 15

✓ Entry #123: Electricity Bill - 2025-12-15 (Type: EXPENSE, Amount: -150.00)
✓ Entry #124: Freelance Project - 2025-12-20 (Type: INCOME, Amount: +2500.00)
✓ Entry #125: Rent - 2025-12-01 (Type: EXPENSE, Amount: -1200.00)
...

============================================================
Migration Complete!
Entries migrated: 15
============================================================
```

## After Migration

- All your existing entries will display correctly with the new format
- New entries will automatically use the new format
- The "Income" checkbox will work as expected
- Pay dates will no longer be overwritten when editing

## Rollback

If you need to rollback to v0.2.1:

1. Download and activate v0.2.1 of EssFinance
2. All data remains intact and will display in the old format
3. To migrate back to v0.2.2, simply run the migration script again

## Questions or Issues?

If you encounter any issues during migration:

1. Check the error output for specific problems
2. Verify that all EssFinance entries have the `_entry_type` metadata set (should all show "EXPENSE" or "INCOME")
3. Verify that amounts are properly signed (negative for expenses, positive for income)

---

**Version:** 0.2.2
**Date:** 2026-01-23
