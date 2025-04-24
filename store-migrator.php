
<?php
/**
 * Plugin Name: Store Migrator
 * Description: A WordPress plugin for store migration
 * Version: 1.0.0
 * Author: Your Name
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Add menu item
function store_migrator_menu() {
    add_menu_page(
        'Store Migrator',
        'Store Migrator',
        'manage_options',
        'store-migrator-settings',
        'store_migrator_settings_page'
    );
}
add_action('admin_menu', 'store_migrator_menu');

// Create settings page
function store_migrator_settings_page() {
    ?>
    <div class="wrap">
        <h2>Store Migrator Settings</h2>
        <form method="post" action="options.php">
            <?php
            settings_fields('store_migrator_settings');
            do_settings_sections('store-migrator-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}
