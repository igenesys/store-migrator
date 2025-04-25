<?php
/**
 * Plugin Name: Store Migrator
 * Description: A WordPress plugin for store migration with ASPOS API
 * Version: 1.0.0
 */

// Enable error logging
define('STORE_MIGRATOR_DEBUG', true);
define('STORE_MIGRATOR_LOG_FILE', WP_CONTENT_DIR . '/store-migrator-debug.log');

// Define constants from options
define('ASPOS_CLIENT_ID', get_option('aspos_client_id'));
define('ASPOS_CLIENT_SECRET', get_option('aspos_client_secret'));
define('ASPOS_TOKEN_URL', get_option('aspos_token_url'));
define('ASPOS_API_BASE', get_option('aspos_api_base'));

// Function to check if ASPOS settings are configured
function is_aspos_configured() {
    return ASPOS_CLIENT_ID && ASPOS_CLIENT_SECRET && ASPOS_TOKEN_URL && ASPOS_API_BASE;
}

// Function to validate ASPOS settings
function validate_aspos_settings() {
    if (!ASPOS_CLIENT_ID || !ASPOS_CLIENT_SECRET || !ASPOS_TOKEN_URL || !ASPOS_API_BASE) {
        return false;
    }

    $token = get_aspos_token();
    if (!$token) {
        store_migrator_log('Failed to get token during validation', 'error');
        return false;
    }
    return true;
}

function store_migrator_log($message, $type = 'info') {
    if (!STORE_MIGRATOR_DEBUG) return;
    $date = date('Y-m-d H:i:s');
    $log_message = "[$date][$type] $message" . PHP_EOL;
    error_log($log_message, 3, STORE_MIGRATOR_LOG_FILE);
}

// Create stores table on plugin activation
register_activation_hook(__FILE__, 'store_migrator_create_table');

function store_migrator_create_table() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $stores_sql = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}aspos_stores` (
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

    $inventory_sql = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}aspos_inventory` (
        ID bigint(20) NOT NULL AUTO_INCREMENT,
        storeId VARCHAR(255) NOT NULL,
        product_id bigint(20) NOT NULL,
        aspos_product_id VARCHAR(255) NOT NULL,
        availableQuantity decimal(10,2) DEFAULT NULL,
        physicalStockQuantity decimal(10,2) DEFAULT NULL,
        regularPrice VARCHAR(255) NOT NULL,
        salePrice VARCHAR(255) NOT NULL,
        priceInclTax VARCHAR(255) NOT NULL,
        priceExclTax VARCHAR(255) NOT NULL,
        
        PRIMARY KEY (ID)
    ) $charset_collate;";

    dbDelta($stores_sql);
    dbDelta($inventory_sql);
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
        store_migrator_log('Token request failed: ' . $response->get_error_message(), 'error');
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
        ),
        'timeout' => 300,
        'sslverify' => false
    );

    $response = wp_remote_get(ASPOS_API_BASE . '/stores', $args);
    if (is_wp_error($response)) {
        store_migrator_log('Store sync failed: ' . $response->get_error_message(), 'error');
        return false;
    }

    $stores = json_decode(wp_remote_retrieve_body($response));
    global $wpdb;
    $count = 0;

    foreach ($stores as $store) {
        if ($store->status !== 'test') {
            $result = $wpdb->replace(
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
            if ($result) $count++;
        }
    }

    store_migrator_log("Successfully synced $count stores");
    return true;
}

// Add admin menu
function store_migrator_menu() {
    add_menu_page(
        'ASPOS Settings',
        'Store Migrator',
        'manage_options',
        'store-migrator-aspos-settings',
        'store_migrator_aspos_settings_page'
    );
    
    add_submenu_page(
        'store-migrator-aspos-settings',
        'Store Settings',
        'Store Settings',
        'manage_options',
        'store-migrator-settings',
        'store_migrator_settings_page'
    );
}
add_action('admin_menu', 'store_migrator_menu');

// ASPOS Settings page
function store_migrator_aspos_settings_page() {
    if (isset($_POST['save_aspos_settings'])) {
        update_option('aspos_client_id', sanitize_text_field($_POST['client_id']));
        update_option('aspos_client_secret', sanitize_text_field($_POST['client_secret']));
        update_option('aspos_token_url', sanitize_text_field($_POST['token_url']));
        update_option('aspos_api_base', sanitize_text_field($_POST['api_base']));
        
        // Save the values first
        $saved = true;
        if (validate_aspos_settings()) {
            echo '<div class="notice notice-success"><p>Settings saved and validated successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Settings validation failed. Please check your credentials and API endpoints. Your entered values have been saved.</p></div>';
        }
    }
    
    $client_id = get_option('aspos_client_id');
    $client_secret = get_option('aspos_client_secret', ASPOS_CLIENT_SECRET);
    $token_url = get_option('aspos_token_url', ASPOS_TOKEN_URL);
    $api_base = get_option('aspos_api_base', ASPOS_API_BASE);
    ?>
    <div class="wrap">
        <h2>ASPOS API Settings</h2>
        <form method="post" action="">
            <table class="form-table">
                <tr>
                    <th><label for="client_id">Client ID</label></th>
                    <td>
                        <input type="text" name="client_id" id="client_id" value="<?php echo esc_attr($client_id); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><label for="client_secret">Client Secret</label></th>
                    <td>
                        <input type="password" name="client_secret" id="client_secret" value="<?php echo esc_attr($client_secret); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><label for="token_url">Token URL</label></th>
                    <td>
                        <input type="url" name="token_url" id="token_url" value="<?php echo esc_attr($token_url); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><label for="api_base">API Base URL</label></th>
                    <td>
                        <input type="url" name="api_base" id="api_base" value="<?php echo esc_attr($api_base); ?>" class="regular-text">
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="save_aspos_settings" class="button button-primary" value="Save Settings">
            </p>
        </form>
    </div>
    <?php
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
        ),
        'timeout' => 300,
        'sslverify' => false
    );

    $response = wp_remote_get(ASPOS_API_BASE . "/sync/web-products?storeId={$store_id}", $args);
    if (is_wp_error($response)) {
        store_migrator_log("Product sync failed for store {$store_id}: " . $response->get_error_message(), 'error');
        return false;
    }

    $products = json_decode(wp_remote_retrieve_body($response));
    $count = 0;

    foreach ($products as $product) {
        // Check if product already exists by ASPOS ID
        $existing_product_id = get_posts(array(
            'post_type' => 'product',
            'meta_key' => '_aspos_id',
            'meta_value' => $product->id,
            'posts_per_page' => 1,
            'fields' => 'ids'
        ));

        $post_data = array(
            'post_title' => $product->description ?? '',
            'post_content' => $product->description ?? '',
            'post_status' => 'publish',
            'post_type' => 'product'
        );

        if ($existing_product_id) {
            $post_data['ID'] = $existing_product_id[0];
            $post_id = wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data);
        }

        if ($post_id) {
            update_post_meta($post_id, '_aspos_id', $product->id);
            update_post_meta($post_id, '_price', $product->priceInclTax);
            update_post_meta($post_id, '_regular_price', $product->priceInclTax);
            
            // Get existing store IDs
            $store_ids = get_post_meta($post_id, '_aspos_store_ids', true);
            if (!is_array($store_ids)) {
                $store_ids = array();
            }
            
            // Add current store ID if not exists
            if (!in_array($store_id, $store_ids)) {
                $store_ids[] = $store_id;
                update_post_meta($post_id, '_aspos_store_ids', $store_ids);
            }
            
            $count++;
        }
    }

    store_migrator_log("Successfully synced $count products for store $store_id");
    return true;
}

// Sync inventory for a product
function sync_product_inventory($product_id) {
    $token = get_aspos_token();
    if (!$token) {
        store_migrator_log("Failed to get token for product $product_id", 'error');
        return false;
    }

    // Get ASPOS ID from product meta
    $aspos_product_id = get_post_meta($product_id, '_aspos_id', true);
    if (!$aspos_product_id) {
        store_migrator_log("No ASPOS ID found for product $product_id", 'error');
        return false;
    }

    // Get store IDs from product meta
    $store_ids = maybe_unserialize(get_post_meta($product_id, '_aspos_store_ids', true));
    if (!is_array($store_ids)) {
        store_migrator_log("No valid store IDs found for product $product_id", 'error');
        return false;
    }

    $args = array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $token
        ),
        'timeout' => 300,
        'sslverify' => false
    );

    // Get stock info from API
    $response = wp_remote_get(ASPOS_API_BASE . "/products/{$aspos_product_id}/stock-info", $args);
    if (is_wp_error($response)) {
        store_migrator_log("Stock info fetch failed for product {$aspos_product_id}: " . $response->get_error_message(), 'error');
        return false;
    }

    $stock_info = json_decode(wp_remote_retrieve_body($response));
    
    if (!$stock_info) {
        store_migrator_log("Invalid stock info response for product {$aspos_product_id}", 'error');
        return false;
    }

    global $wpdb;
    $success = true;

    // Process each store in the stock info
    foreach ($stock_info as $store_info) {
        // Only insert if store ID exists in product meta
        if (in_array($store_info->storeId, $store_ids)) {
            $result = $wpdb->replace(
                $wpdb->prefix . 'aspos_inventory',
                array(
                    'storeId' => $store_info->storeId,
                    'product_id' => $product_id,
                    'aspos_product_id' => $aspos_product_id,
                    'availableQuantity' => $store_info->availableQuantity ?? 0,
                    'physicalStockQuantity' => $store_info->physicalStockQuantity ?? 0
                ),
                array('%s', '%d', '%s', '%f', '%f')
            );
            
            if ($result === false) {
                store_migrator_log("Failed to update inventory for product $product_id in store {$store_info->storeId}", 'error');
                $success = false;
            }
        }
    }

    store_migrator_log("Finished syncing inventory for product $product_id");
    return $success;
}

// Sync inventory for all products
function sync_all_inventory() {
    $products = get_posts(array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'fields' => 'ids'
    ));

    $success = true;
    foreach ($products as $product_id) {
        if (!sync_product_inventory($product_id)) {
            $success = false;
        }
    }
    return $success;
}

// Sync products for all stores
function sync_all_store_products() {
    global $wpdb;
    $stores = $wpdb->get_results("SELECT id FROM {$wpdb->prefix}aspos_stores");
    $success = true;
    
    foreach ($stores as $store) {
        $result = sync_store_products($store->id);
        if (!$result) {
            $success = false;
        }
    }
    
    return $success;
}

// Settings page
function store_migrator_settings_page() {
    if (!is_aspos_configured()) {
        echo '<div class="wrap"><div class="notice notice-error"><p>Please configure ASPOS settings first. <a href="' . admin_url('admin.php?page=store-migrator-aspos-settings') . '">Configure Now</a></p></div></div>';
        return;
    }
    global $wpdb;
    
    if (isset($_POST['sync_stores'])) {
        $result = sync_stores();
        if ($result) {
            echo '<div class="notice notice-success"><p>Stores synced successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Store sync failed. Check debug log for details.</p></div>';
        }
    }

    if (isset($_POST['sync_products']) && isset($_POST['store_id'])) {
        $result = sync_store_products($_POST['store_id']);
        if ($result) {
            echo '<div class="notice notice-success"><p>Products synced successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Product sync failed. Check debug log for details.</p></div>';
        }
    }

    // Get stores from database
    $stores = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}aspos_stores");
    ?>
    <div class="wrap">
        <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        <script>
            jQuery(document).ready(function($) {
                $('select[name="store_id"]').select2({
                    placeholder: "Select a store",
                    allowClear: true
                });
            });
        </script>
        <h2>Store Migrator Settings</h2>
        <form method="post" action="">
            <p><input type="submit" name="sync_stores" class="button button-primary" value="Sync Stores"></p>

            <h3>Sync Products</h3>
            <select name="store_id" style="width: 300px;">
                <?php foreach ($stores as $store): ?>
                    <option value="<?php echo esc_attr($store->id); ?>"><?php echo esc_html($store->name ?: $store->code); ?></option>
                <?php endforeach; ?>
            </select>
            <p>
                <input type="submit" name="sync_products" class="button button-primary" value="Sync Products">
                <input type="submit" name="sync_all_products" class="button button-primary" value="Sync All Products" style="margin-left: 10px;">
                <input type="submit" name="sync_inventory" class="button button-primary" value="Sync Inventory" style="margin-left: 10px;">
            </p>

            <?php if (isset($_POST['sync_inventory'])): 
                $result = sync_all_inventory();
                if ($result) {
                    echo '<div class="notice notice-success"><p>Inventory synced successfully!</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Some inventory failed to sync. Check debug log for details.</p></div>';
                }
            endif; ?>

            <?php if (isset($_POST['sync_all_products'])): 
                $result = sync_all_store_products();
                if ($result) {
                    echo '<div class="notice notice-success"><p>All store products synced successfully!</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Some stores failed to sync products. Check debug log for details.</p></div>';
                }
            endif; ?>

            <?php if (STORE_MIGRATOR_DEBUG): ?>
            <h3>Debug Log</h3>
            <div style="background: #fff; padding: 10px; margin-top: 10px; max-height: 400px; overflow: auto;">
                <pre><?php echo esc_html(file_exists(STORE_MIGRATOR_LOG_FILE) ? file_get_contents(STORE_MIGRATOR_LOG_FILE) : 'No logs yet.'); ?></pre>
            </div>
            <?php endif; ?>

            <?php if (isset($_POST['sync_prices'])): 
                $result = sync_store_prices();
                if ($result) {
                    echo '<div class="notice notice-success"><p>Prices synced successfully!</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Failed to sync prices. Check debug log for details.</p></div>';
                }
            endif; ?>

            <p><input type="submit" name="sync_prices" class="button button-primary" value="Sync Prices"></p>
        </form>
    </div>
    <?php
}

// Sync prices from all stores
function sync_store_prices() {
    global $wpdb;
    $token = get_aspos_token();
    if (!$token) {
        store_migrator_log("Failed to get token for price sync", 'error');
        return false;
    }

    // Create temp directory if it doesn't exist
    $temp_dir = WP_CONTENT_DIR . '/temp';
    if (!file_exists($temp_dir)) {
        mkdir($temp_dir, 0755, true);
    }

    $stores = $wpdb->get_results("SELECT id FROM {$wpdb->prefix}aspos_stores");
    $all_products = array();

    foreach ($stores as $store) {
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token
            ),
            'timeout' => 300,
            'sslverify' => false
        );

        $response = wp_remote_get(ASPOS_API_BASE . "/sync/web-products?storeId={$store->id}", $args);
        if (is_wp_error($response)) {
            store_migrator_log("Price sync failed for store {$store->id}: " . $response->get_error_message(), 'error');
            continue;
        }

        $products = json_decode(wp_remote_retrieve_body($response));
        foreach ($products as $product) {
            $all_products[] = array(
                'id' => $product->id,
                'priceInclTax' => $product->priceInclTax,
                'priceExclTax' => $product->priceExclTax,
                'storeID' => $store->id
            );
        }
    }

    // Save to JSON file
    $json_file = $temp_dir . '/store_prices_' . date('Y-m-d_H-i-s') . '.json';
    $result = file_put_contents($json_file, json_encode($all_products, JSON_PRETTY_PRINT));
    
    if ($result === false) {
        store_migrator_log("Failed to save prices JSON file", 'error');
        return false;
    }

    // Update inventory table with prices
    $updated_count = 0;
    foreach ($all_products as $product) {
        $result = $wpdb->update(
            $wpdb->prefix . 'aspos_inventory',
            array(
                'priceInclTax' => $product['priceInclTax'],
                'priceExclTax' => $product['priceExclTax']
            ),
            array(
                'aspos_product_id' => $product['id'],
                'storeId' => $product['storeID']
            ),
            array('%s', '%s'),
            array('%s', '%s')
        );
        
        if ($result !== false) {
            $updated_count++;
        }
    }

    store_migrator_log("Successfully saved prices to: $json_file");
    store_migrator_log("Updated prices for $updated_count products in inventory table");
    
    // Delete the temporary JSON file
    if (unlink($json_file)) {
        store_migrator_log("Deleted temporary JSON file: $json_file");
    } else {
        store_migrator_log("Failed to delete temporary JSON file: $json_file", 'error');
    }
    
    return true;
}