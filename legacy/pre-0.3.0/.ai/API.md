# ESSFINANCE API - IA QUICK REF

## DATA MODEL (v2.1)
```
Post Type: essf_cashflow
├── post_title: Description
├── post_date_gmt: Due Date (YYYY-MM-DD HH:MM:SS)
├── post_modified_gmt: Pay Date (YYYY-MM-DD HH:MM:SS or 0000-00-00)
├── post_content: Amount (numeric)
├── post_status: 'pending' | 'paid'
└── _order_date: Filter date (YYYY-MM-DD, meta)
    └─ = post_modified if set, else post_date
```

## CREATE ENTRY
```php
wp_insert_post([
    'post_type'         => 'essf_cashflow',
    'post_title'        => 'Invoice ABC',
    'post_status'       => 'pending',
    'post_date_gmt'     => '2024-01-20 00:00:00',
    'post_modified_gmt' => '0000-00-00 00:00:00',  // empty if not paid
    'post_content'      => 1500.00,
]);

// Add order_date meta
update_post_meta($post_id, '_order_date', '2024-01-20');
```

## READ ENTRIES
```php
$posts = get_posts([
    'post_type'   => 'essf_cashflow',
    'numberposts' => -1,
]);

foreach ($posts as $post) {
    $due_date = $post->post_date_gmt;
    $pay_date = $post->post_modified_gmt;
    $amount = (float) $post->post_content;
    $order_date = get_post_meta($post->ID, '_order_date', true);
    $status = $post->post_status;
}
```

## UPDATE ENTRY
```php
wp_update_post([
    'ID'                => $post_id,
    'post_title'        => 'New title',
    'post_status'       => 'paid',
    'post_date_gmt'     => '2024-01-20 00:00:00',
    'post_modified_gmt' => '2024-01-22 00:00:00',
    'post_content'      => 2000.00,
]);

// Update order_date
$pay_date = '2024-01-22 00:00:00';
$due_date = '2024-01-20 00:00:00';
$order_date = ('0000-00-00 00:00:00' !== $pay_date) 
    ? substr($pay_date, 0, 10) 
    : substr($due_date, 0, 10);
update_post_meta($post_id, '_order_date', $order_date);
```

## DELETE ENTRY
```php
wp_delete_post($post_id);
```

## FILTER BY MONTH
```php
$month = '2024-01';
foreach ($posts as $post) {
    $order_date = get_post_meta($post->ID, '_order_date', true);
    if (substr($order_date, 0, 7) === $month) {
        // Show this entry
    }
}
```

## OVERDUE STATUS
```php
$status = $post->post_status;
if ('pending' === $status && substr($post->post_date_gmt, 0, 10) < date('Y-m-d')) {
    $status = 'overdue';  // Virtual, not stored
}
```

## UI ACTIONS
- Edit: `?action=edit&id=123`
- Delete: `?action=delete_entry&id=123` (GET with nonce)
- Paid Today: `?action=mark_paid&id=123` (GET with nonce)

## FORMS & SECURITY
- Add: POST with nonce 'essfinance_add_entry'
- Update: POST with nonce 'essfinance_edit'
- Delete/Mark Paid: GET with nonce
- Sanitize all inputs
- Escape all outputs

## ENTRY DATA MODEL
| Field | Type | Use | Example |
|-------|------|-----|---------|
| post_title | string | Description | "Invoice 001" |
| post_date_gmt | datetime | Due Date | "2024-01-20 00:00:00" |
| post_modified_gmt | datetime | Pay Date | "2024-01-22 00:00:00" |
| post_content | float | Amount | "1500.00" |
| post_status | string | Status | "pending" or "paid" |
| _order_date | string | Filter month | "2024-01-22" |
