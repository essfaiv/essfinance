# UI Layout Guide

## Dashboard Layout

```
┌─────────────────────────────────────────────────────────────┐
│                    💰 Cash Flow                              │
├─────────────────────────────────────────────────────────────┤
│ Month: [January 2026 ▼]                                     │
├──────────────────────┬──────────────────────────────────────┤
│                      │ Entries for January 2026             │
│  Add Entry           ├──────────────────────────────────────┤
│ ┌──────────────────┐ │ Description    Date    Status Amount │
│ │ Description      │ ├──────────────────────────────────────┤
│ │ [           ] ✎  │ │ Rent Payment  2026-01-01 Paid  5000 │
│ │                  │ │                           ✏ ❌ 🗑     │
│ │ Due Date         │ │                                      │
│ │ [2026-01-15] ✎  │ │ Electric Bill 2026-01-10 Pend  150  │
│ │                  │ │                    ⏱ ❌ 📅 🗑        │
│ │ Pay Date         │ │                                      │
│ │ [2026-01-20] ✎  │ │ Internet      2026-01-05 Over  80   │
│ │                  │ │                    ⚠ ❌ 📅 🗑        │
│ │ Amount           │ │                                      │
│ │ [5150.00] ✎      │ │ Total: R$ 5,230.00                 │
│ │                  │ │                                      │
│ │ Status           │ │                                      │
│ │ [Pending ▼]      │ │                                      │
│ │                  │ │                                      │
│ │ [+ Add Entry]    │ │                                      │
│ └──────────────────┘ │                                      │
│                      │                                      │
└──────────────────────┴──────────────────────────────────────┘
```

## Action Links (Hover)

When you hover over an entry row, these action links appear:

- **✏ Edit** - Opens modal to edit entry details
- **⏱ Paid Today** - Marks as paid with today's date instantly
- **📅 Paid Date** - Opens modal with Pay Date field focused
- **🗑 Delete** - Removes entry (with confirmation)

## Edit Modal

```
┌─────────────────────────────────────────┐
│                                         │
│  Edit Entry                    ╳        │
│  ─────────────────────────────────     │
│  Description: [Electric Bill       ]  │
│  Due Date:    [2026-01-10            ]  │
│  Pay Date:    [                      ]  │
│  Amount:      [150.00               ]  │
│  Status:      [Pending ▼            ]  │
│                                         │
│              [Cancel] [Update]         │
│                                         │
└─────────────────────────────────────────┘
```

## Data Display

### Date Column
- Shows **Pay Date** when entry is paid
- Shows **Due Date** when entry is pending/overdue
- Format: YYYY-MM-DD

### Status Icons
- 🕐 **Pending** - Not yet paid
- ✓ **Paid** - Payment received
- ⚠ **Overdue** - Pending and past due date

### Form Fields
- **Description** - Text (required)
- **Due Date** - Date picker (required)
- **Pay Date** - Date picker (optional)
- **Amount** - Number input (required, 2 decimals)
- **Status** - Dropdown: Pending or Paid

## Storage
All data stored in standard WordPress post fields:
- `post_title` ← Description
- `post_date_gmt` ← Due Date
- `post_modified_gmt` ← Pay Date
- `post_content` ← Amount
- `post_status` ← Pending or Paid
