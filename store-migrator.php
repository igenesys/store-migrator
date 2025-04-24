
<?php
/**
 * Plugin Name: Store Migrator
 * Description: A WordPress plugin for store migration with ASPOS API
 * Version: 1.0.0
 * Author: Your Name
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('ASPOS_CLIENT_ID', 'DOSMAX');
define('ASPOS_CLIENT_SECRET', 'UDCBNROARYIYHOKPLOBPYPPMIESKOLEGLUPDHSXMIDHGMRGYEQ');
define('ASPOS_TOKEN_URL', 'https://acceptatiewebserviceshdv.aspos.nl/connect/token');
define('ASPOS_API_BASE', 'https://acceptatiewebserviceshdv.aspos.nl/api');

// Create required tables on plugin activation
register_activation_hook(__FILE__, 'store_migrator_create_tables');

function store_migrator_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Stores table
    $sql = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}aspos_stores` (
        id VARCHAR(255) PRIMARY KEY,
        city VARCHAR(255),
        code VARCHAR(255),
        email VARCHAR(255),
        name VARCHAR(255),
        phone_number VARCHAR(255),
        postal_code VARCHAR(255),
        status VARCHAR(255),
        street VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) $charset_collate;";
    
    // Inventory table
    $sql .= "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}aspos_inventory` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        store_id VARCHAR(255),
        product_id VARCHAR(255),
        warehouse_id VARCHAR(255),
        stock_quantity INT,
        days_in_stock INT,
        allow_system_override BOOLEAN,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY store_product (store_id, product_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Get ASPOS bearer token
function get_aspos_token() {
    $args = array(
        'body' => array(
            'grant_type' => 'client_credentials',
            'client_id' => ASPOS_CLIENT_ID,
            'client_secret' => ASPOS_CLIENT_SECRET
        )
    );

    $response = wp_remote_post(ASPOS_TOKEN_URL, $args);
    if (is_wp_error($response)) {
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response));
    return isset($body->access_token) ? $body->access_token : false;
}

// Sync stores
function sync_stores() {
    $token = get_aspos_token();
    if (!$token) {
        return false;
    }

    $args = array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $token
        )
    );

    $response = wp_remote_get(ASPOS_API_BASE . '/stores', $args);
    if (is_wp_error($response)) {
        return false;
    }

    $stores = json_decode(wp_remote_retrieve_body($response));
    global $wpdb;

    foreach ($stores as $store) {
        if ($store->status !== 'test') {
            $wpdb->replace(
                $wpdb->prefix . 'aspos_stores',
                array(
                    'id' => $store->id,
                    'city' => $store->city,
                    'code' => $store->code,
                    'email' => $store->email,
                    'name' => $store->name,
                    'phone_number' => $store->phoneNumber,
                    'postal_code' => $store->postalCode,
                    'status' => $store->status,
                    'street' => $store->street
                )
            );
        }
    }
}

// Sync products for a store
function sync_store_products($store_id) {
    $token = get_aspos_token();
    if (!$token) {
        return false;
    }

    $args = array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $token
        )
    );

    $response = wp_remote_get(ASPOS_API_BASE . "/sync/web-products?storeId={$store_id}", $args);
    if (is_wp_error($response)) {
        return false;
    }

    $products = json_decode(wp_remote_retrieve_body($response));
    
    foreach ($products as $product) {
        $post_meta = array(
            '_aspos_id' => $product->id,
            '_aspos_number' => $product->number,
            '_aspos_collection_code' => $product->collectionCode,
            '_aspos_collection_desc' => $product->collectionDescription,
            '_aspos_bonus_points' => $product->bonusPoints,
            '_aspos_brand_id' => $product->brandId,
            '_aspos_group_id' => $product->groupId,
            '_aspos_store_id' => $product->storeId
        );

        $post_data = array(
            'post_title' => $product->description,
            'post_content' => $product->secondDescription,
            'post_status' => $product->state === 'Active' ? 'publish' : 'draft',
            'post_type' => 'product'
        );

        // Check if product exists
        $existing_product = get_posts(array(
            'post_type' => 'product',
            'meta_key' => '_aspos_id',
            'meta_value' => $product->id,
            'posts_per_page' => 1
        ));

        if ($existing_product) {
            $post_data['ID'] = $existing_product[0]->ID;
            wp_update_post($post_data);
        } else {
            if ($product->state !== 'Inactive') {
                $post_id = wp_insert_post($post_data);
                foreach ($post_meta as $key => $value) {
                    update_post_meta($post_id, $key, $value);
                }
                update_post_meta($post_id, '_price', $product->priceInclTax);
                update_post_meta($post_id, '_regular_price', $product->priceInclTax);
            }
        }
    }
}

// Sync inventory
function sync_product_inventory($product_id, $store_id) {
    $token = get_aspos_token();
    if (!$token) {
        return false;
    }

    $args = array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $token
        )
    );

    $response = wp_remote_get(ASPOS_API_BASE . "/products/{$product_id}/stock-info?storeId={$store_id}", $args);
    if (is_wp_error($response)) {
        return false;
    }

    $stock_info = json_decode(wp_remote_retrieve_body($response));
    global $wpdb;

    $wpdb->replace(
        $wpdb->prefix . 'aspos_inventory',
        array(
            'store_id' => $store_id,
            'product_id' => $product_id,
            'warehouse_id' => $stock_info->warehouseId,
            'days_in_stock' => $stock_info->daysInStock,
            'allow_system_override' => $stock_info->allowSystemOverride,
            'updated_at' => current_time('mysql')
        )
    );
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
    if (isset($_POST['sync_stores'])) {
        sync_stores();
        echo '<div class="notice notice-success"><p>Stores synced successfully!</p></div>';
    }
    ?>
    <div class="wrap">
        <h2>Store Migrator Settings</h2>
        <form method="post" action="">
            <p><input type="submit" name="sync_stores" class="button button-primary" value="Sync Stores"></p>
        </form>
    </div>
    <?php
}
</new_str>
