# Code Reference - Key Functions

## 1. Layout Structure (CSS + HTML)

```css
#essfinance-wrap {
    display: flex;
    gap: 30px;
}
#essfinance-form {
    flex: 0 0 350px; /* Fixed width */
}
#essfinance-list {
    flex: 1; /* Takes remaining space */
    min-width: 0;
}

/* Hover actions */
.essfinance-table tbody tr:hover .essfinance-actions {
    opacity: 1;
}
.essfinance-actions {
    opacity: 0;
    transition: opacity 0.2s ease-in-out;
    white-space: nowrap;
}
```

## 2. Single Date Column Logic

```php
// In essfinance_page()
$display_date = ! empty( $item['pay_date'] ) ? $item['pay_date'] : $item['due_date'];

// Output in table
<td><?php echo esc_html( $display_date ); ?></td>
```

**Rule**: If pay_date exists, show it. Otherwise show due_date.

## 3. Action Links (Hover)

```php
<div class="essfinance-actions">
    <a href="<?php echo esc_url( $edit_url ); ?>">Edit</a>
    <a href="<?php echo esc_url( $paid_today_url ); ?>">Paid Today</a>
    <a href="<?php echo esc_url( $paid_date_url ); ?>">Paid Date</a>
    <a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('Delete this entry?');">Delete</a>
</div>
```

## 4. Paid Date Action Handler

```php
// In essfinance_handle_form()
if ( isset( $_GET['action'] ) && 'paid_date' === $_GET['action'] && isset( $_GET['id'] ) ) {
    wp_safe_redirect( add_query_arg(
        array(
            'page' => 'essfinance',
            'action' => 'edit',
            'id' => (int) $_GET['id'],
            'focus' => 'pay_date'  // Signal to focus pay_date field
        ),
        admin_url( 'admin.php' )
    ) );
    exit;
}
```

## 5. Modal Edit (Fixed Overlay)

```php
// In admin_init hook
if ( isset( $_GET['action'] ) && 'edit' === $_GET['action'] ) {
    $focus_field = isset( $_GET['focus'] ) ? sanitize_key( $_GET['focus'] ) : '';
    $js_focus = ( 'pay_date' === $focus_field ) ? "document.getElementById('edit-pay').focus();" : '';
    ?>
    <div style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 100000;">
        <div style="background: #fff; border-radius: 4px; padding: 30px; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 25px rgba(0,0,0,0.15);">
            <!-- Form here -->
        </div>
    </div>
    <script><?php echo $js_focus; ?></script>
    <?php
}
```

**Features**:
- Fixed positioning (stays visible on scroll)
- Centered on screen
- Dark overlay background
- Auto-close on Cancel
- Focus on specific field if requested

## 6. Action URL Builders

```php
// Edit
$edit_url = add_query_arg(
    array( 'page' => 'essfinance', 'action' => 'edit', 'id' => $item['ID'] ),
    admin_url( 'admin.php' )
);

// Paid Today
$paid_today_url = wp_nonce_url(
    add_query_arg(
        array( 'page' => 'essfinance', 'action' => 'mark_paid', 'id' => $item['ID'] ),
        admin_url( 'admin.php' )
    ),
    'essfinance_mark_paid'
);

// Paid Date
$paid_date_url = add_query_arg(
    array( 'page' => 'essfinance', 'action' => 'paid_date', 'id' => $item['ID'] ),
    admin_url( 'admin.php' )
);

// Delete
$delete_url = wp_nonce_url(
    add_query_arg(
        array( 'page' => 'essfinance', 'action' => 'delete_entry', 'id' => $item['ID'] },
        admin_url( 'admin.php' )
    ),
    'essfinance_delete'
);
```

## 7. Two-Column Form Structure

```php
<div id="essfinance-wrap">
    <!-- LEFT: Form (350px fixed) -->
    <div id="essfinance-form">
        <div style="background: #f5f5f5; padding: 15px; border-radius: 4px;">
            <h2 style="margin-top: 0;">Add Entry</h2>
            <form method="post">
                <!-- Form fields here -->
            </form>
        </div>
    </div>

    <!-- RIGHT: Table (flexible) -->
    <div id="essfinance-list">
        <h2 style="margin-top: 0;">Entries for...</h2>
        <table class="wp-list-table widefat fixed striped essfinance-table">
            <!-- Table here -->
        </table>
    </div>
</div>
```

## 8. Status Display Logic

```php
$status = $post->post_status;  // 'pending' or 'paid'

// Virtual overdue calculation
if ( 'pending' === $status && $due_date < $today ) {
    $status = 'overdue';  // Never stored, only displayed
}

// Icon mapping
$icon = $status === 'paid' ? 'yes-alt' : ($status === 'overdue' ? 'warning' : 'clock');
$label = ucfirst( $status );

// Output
<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" title="<?php echo esc_attr( $label ); ?>"></span>
<?php echo esc_html( $label ); ?>
```

**Icons**:
- pending → 🕐 (dashicons-clock)
- paid → ✓ (dashicons-yes-alt)
- overdue → ⚠ (dashicons-warning)

## 9. Date Handling

```php
// Store dates with WordPress format
$due_date = sanitize_text_field( $_POST['due_date'] ) . ' 00:00:00';
$pay_date = ! empty( $_POST['pay_date'] ) ? sanitize_text_field( $_POST['pay_date'] ) . ' 00:00:00' : '0000-00-00 00:00:00';

// Extract display dates
$due_date_display = substr( $post->post_date_gmt, 0, 10 );      // YYYY-MM-DD
$pay_date_display = substr( $post->post_modified_gmt, 0, 10 );  // YYYY-MM-DD

// Check if empty
$is_empty = '0000-00-00' === $pay_date_display;
```

**Format**: YYYY-MM-DD HH:MM:SS (WordPress standard)

## 10. Nonce Protection

```php
// In forms
<?php wp_nonce_field( 'essfinance_add_entry' ); ?>
<?php wp_nonce_field( 'essfinance_edit' ); ?>

// In handlers
if ( ! check_admin_referer( 'essfinance_add_entry', '_wpnonce', false ) ) {
    return;
}

// In URLs
wp_nonce_url( $url, 'essfinance_mark_paid' )
wp_nonce_url( $url, 'essfinance_delete' )
```

**Actions**:
- add_entry
- update_entry
- mark_paid
- delete_entry
