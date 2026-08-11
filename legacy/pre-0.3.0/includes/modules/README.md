# Modules

Simple way to extend EssFinance without modifying core.

## Creating a Module

A module is just a PHP file in this `modules/` folder that hooks into WordPress.

### Basic Structure

Create a file: `my-module.php` in this folder

```php
<?php
// Your module code here

add_action( 'init', function() {
    // Register post types
    register_post_type( 'my_custom_type', array(
        'label' => 'My Custom Type',
        'public' => false,
        'show_ui' => true,
    ) );
} );

add_action( 'admin_menu', function() {
    // Add admin pages
    add_submenu_page(
        'essfinance',
        'My Module',
        'My Module',
        'manage_options',
        'my-module',
        'my_module_page'
    );
} );

function my_module_page() {
    echo '<div class="wrap"><h1>My Module</h1></div>';
}
```

### Files Automatically Loaded

Any `.php` file in this folder (except `autoload.php`) is automatically loaded.

### Examples

- `reports.php` - Reporting module
- `recurring.php` - Recurring entries

## Tips

- Use hooks to integrate with main admin page
- Check `essfinance_page()` in core.php for available hooks
- Keep modules independent
