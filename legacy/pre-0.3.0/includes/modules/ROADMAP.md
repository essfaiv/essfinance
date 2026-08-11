# Modules Roadmap

Planned modules to extend EssFinance.

## 📋 Financing Module (v1.1)

Auto-generate cash flow entries from financing plans.

**Features:**
- Create financing plan
- Define: total entries, start date, value per entry, due day
- Auto-generates all cash flow entries
- Updates pay_date based on month offset

**Meta Fields:**
- `_total_entries`: Total number of payments
- `_start_date`: First payment date
- `_value`: Amount per payment
- `_due_day`: Day of month for due date

See `financing.php` for example implementation (currently disabled).

## 📊 Reports Module (v1.2)

Generate reports and analytics.

**Features:**
- Monthly summary
- Category totals
- Pending vs completed analysis
- Export to CSV

## 📅 Recurring Module (v1.3)

Automatic recurring entries.

**Features:**
- Define recurring pattern
- Auto-create entries for month
- Modify or skip individual occurrences

## 📈 Budget Module (v2.0)

Budget planning and tracking.

**Features:**
- Set monthly budgets by category
- Compare budget vs actual
- Alerts for over-budget categories

---

To create a new module: see `README.md` in this folder.
