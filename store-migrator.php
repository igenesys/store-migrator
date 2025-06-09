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

    global $wpdb;
    $count = 0;
    $page = 1;
    $limit = 50; // Adjust based on API limits
    
    do {
        $url = ASPOS_API_BASE . "/stores?includeNonActiveStores=false&page={$page}&limit={$limit}";
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            store_migrator_log("Store sync failed on page {$page}: " . $response->get_error_message(), 'error');
            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body);
        
        // Handle different response formats
        $stores = is_array($data) ? $data : (isset($data->data) ? $data->data : array());
        $has_more = false;
        
        if (isset($data->pagination)) {
            $has_more = $data->pagination->hasMore ?? false;
        } elseif (isset($data->hasMore)) {
            $has_more = $data->hasMore;
        } else {
            // If no pagination info, check if we got a full page
            $has_more = count($stores) >= $limit;
        }

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
        
        store_migrator_log("Processed page {$page} with " . count($stores) . " stores");
        $page++;
        
    } while ($has_more && count($stores) > 0);

    store_migrator_log("Successfully synced $count stores across " . ($page - 1) . " pages");
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

    add_submenu_page(
        'store-migrator-aspos-settings',
        'Store Editor',
        'Store Editor',
        'manage_options',
        'store-migrator-editor',
        'store_migrator_editor_page'
    );
}

// Store Editor page
function store_migrator_editor_page() {
    if (!is_aspos_configured()) {
        echo '<div class="wrap"><div class="notice notice-error"><p>Please configure ASPOS settings first. <a href="' . admin_url('admin.php?page=store-migrator-aspos-settings') . '">Configure Now</a></p></div></div>';
        return;
    }

    global $wpdb;
    
    // Handle store updates
    if (isset($_POST['update_store'])) {
        $wpdb->update(
            $wpdb->prefix . 'aspos_stores',
            array(
                'name' => sanitize_text_field($_POST['name']),
                'city' => sanitize_text_field($_POST['city']),
                'code' => sanitize_text_field($_POST['code']),
                'email' => sanitize_text_field($_POST['email']),
                'phone_number' => sanitize_text_field($_POST['phone_number']),
                'postal_code' => sanitize_text_field($_POST['postal_code']),
                'street' => sanitize_text_field($_POST['street']),
                'status' => sanitize_text_field($_POST['status'])
            ),
            array('id' => $_POST['store_id']),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'),
            array('%s')
        );
        echo '<div class="notice notice-success"><p>Store updated successfully!</p></div>';
    }

    // Get store data
    $store_id = isset($_GET['store_id']) ? $_GET['store_id'] : null;
    $stores = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}aspos_stores ORDER BY code ASC");
    ?>
    <div class="wrap">
        <h2>Store Editor</h2>
        <?php if ($store_id): ?>
            <?php $store = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}aspos_stores WHERE id = %s", $store_id)); ?>
            <?php if ($store): ?>
                <form method="post" action="">
                    <input type="hidden" name="store_id" value="<?php echo esc_attr($store->id); ?>">
                    <table class="form-table">
                        <tr>
                            <th><label>Store ID</label></th>
                            <td><?php echo esc_html($store->id); ?></td>
                        </tr>
                        <tr>
                            <th><label for="name">Name</label></th>
                            <td><input type="text" name="name" id="name" value="<?php echo esc_attr($store->name); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="code">Code</label></th>
                            <td><input type="text" name="code" id="code" value="<?php echo esc_attr($store->code); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="email">Email</label></th>
                            <td><input type="email" name="email" id="email" value="<?php echo esc_attr($store->email); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="phone_number">Phone Number</label></th>
                            <td><input type="text" name="phone_number" id="phone_number" value="<?php echo esc_attr($store->phone_number); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="street">Street</label></th>
                            <td><input type="text" name="street" id="street" value="<?php echo esc_attr($store->street); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="city">City</label></th>
                            <td><input type="text" name="city" id="city" value="<?php echo esc_attr($store->city); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="postal_code">Postal Code</label></th>
                            <td><input type="text" name="postal_code" id="postal_code" value="<?php echo esc_attr($store->postal_code); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="status">Status</label></th>
                            <td>
                                <select name="status" id="status">
                                    <option value="active" <?php selected($store->status, 'active'); ?>>Active</option>
                                    <option value="inactive" <?php selected($store->status, 'inactive'); ?>>Inactive</option>
                                    <option value="test" <?php selected($store->status, 'test'); ?>>Test</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="update_store" class="button button-primary" value="Update Store">
                        <a href="<?php echo admin_url('admin.php?page=store-migrator-editor'); ?>" class="button">Back to Store List</a>
                    </p>
                </form>
            <?php else: ?>
                <div class="notice notice-error"><p>Store not found.</p></div>
            <?php endif; ?>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>ASPOS Store ID</th>
                        <th>Name</th>
                        <th>Code</th>
                        <th>City</th>
                        <th>Status</th>
                        <th>Products</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i=0; foreach ($stores as $store): 
                        $product_count = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(DISTINCT product_id) FROM {$wpdb->prefix}aspos_inventory WHERE storeId = %s",
                            $store->id
                        ));
                        $i++;
                    ?>
                        <tr>
                            <td><?php echo esc_html($i); ?></td>    
                            <td><?php echo esc_html($store->id); ?></td>
                            <td><?php echo esc_html($store->name); ?></td>
                            <td><?php echo esc_html($store->code); ?></td>
                            <td><?php echo esc_html($store->city); ?></td>
                            <td><?php echo esc_html($store->status); ?></td>
                            <td><?php echo esc_html($product_count); ?></td>
                            <td>
                                <a href="<?php echo add_query_arg('store_id', $store->id); ?>" class="button">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
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

    $count = 0;
    $page = 1;
    $limit = 50; // Adjust based on API limits
    
    do {
        $url = ASPOS_API_BASE . "/sync/web-products?storeId={$store_id}&page={$page}&limit={$limit}";
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            store_migrator_log("Product sync failed for store {$store_id} on page {$page}: " . $response->get_error_message(), 'error');
            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body);
        
        // Handle different response formats
        $products = is_array($data) ? $data : (isset($data->data) ? $data->data : array());
        $has_more = false;
        
        if (isset($data->pagination)) {
            $has_more = $data->pagination->hasMore ?? false;
        } elseif (isset($data->hasMore)) {
            $has_more = $data->hasMore;
        } else {
            // If no pagination info, check if we got a full page
            $has_more = count($products) >= $limit;
        }

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
        
        store_migrator_log("Processed page {$page} with " . count($products) . " products for store {$store_id}");
        $page++;
        
    } while ($has_more && count($products) > 0);

    store_migrator_log("Successfully synced $count products for store $store_id across " . ($page - 1) . " pages");
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

// Add store inventory meta box to product edit page
function add_store_inventory_meta_box() {
    add_meta_box(
        'store_inventory_meta_box',
        'Store Inventory',
        'display_store_inventory_meta_box',
        'product',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'add_store_inventory_meta_box');

function display_store_inventory_meta_box($post) {
    global $wpdb;
    
    // Get product's ASPOS ID
    $aspos_id = get_post_meta($post->ID, '_aspos_id', true);
    
    // Get all stores with inventory for this product
    $inventory_data = $wpdb->get_results($wpdb->prepare(
        "SELECT i.*, s.name as store_name, s.city, s.code 
         FROM {$wpdb->prefix}aspos_inventory i 
         JOIN {$wpdb->prefix}aspos_stores s ON i.storeId = s.id 
         WHERE i.product_id = %d",
        $post->ID
    ));
    
    if (empty($inventory_data)) {
        echo '<p>No inventory data available for this product.</p>';
        return;
    }
    
    echo '<table class="widefat fixed" style="margin-top: 10px;">
        <thead>
            <tr>
                <th>ASPOS Product ID</th>
                <th>Store ID</th>
                <th>Store name</th>
                <th>Code</th>
                <th>City</th>
                <th>Available Qty</th>
                <th>Physical Stock</th>
                <th>priceInclTax</th>
                <th>priceExclTax</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($inventory_data as $data) {
        // Get additional store details
        $store_details = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}aspos_stores WHERE id = %s",
            $data->storeId
        ));
        
        echo '<tr>
            <td>' . esc_html($data->aspos_product_id) . '</td>
            <td>' . esc_html($data->storeId) . '</td>
            <td>' . esc_html($data->store_name) . '</td>
            <td>' . esc_html($data->code) . '</td>
            <td>' . esc_html($data->city) . '</td>
            <td>' . esc_html($data->availableQuantity) . '</td>
            <td>' . esc_html($data->physicalStockQuantity) . '</td>
            <td>' . esc_html($data->priceInclTax) . '</td>
            <td>' . esc_html($data->priceExclTax) . '</td>
        </tr>';
    }
    
    echo '</tbody></table>';
    
    echo '<p><strong>ASPOS Product ID:</strong> ' . esc_html($aspos_id) . '</p>';
}

// Schedule cron jobs on plugin activation
register_activation_hook(__FILE__, 'store_migrator_schedule_cron');
register_deactivation_hook(__FILE__, 'store_migrator_unschedule_cron');

function store_migrator_schedule_cron() {
    if (!wp_next_scheduled('store_migrator_hourly_sync')) {
        wp_schedule_event(time(), 'hourly', 'store_migrator_hourly_sync');
    }
    if (!wp_next_scheduled('store_migrator_daily_full_sync')) {
        wp_schedule_event(time(), 'daily', 'store_migrator_daily_full_sync');
    }
}

function store_migrator_unschedule_cron() {
    wp_clear_scheduled_hook('store_migrator_hourly_sync');
    wp_clear_scheduled_hook('store_migrator_daily_full_sync');
}

// Cron job handlers
add_action('store_migrator_hourly_sync', 'store_migrator_cron_inventory_sync');
add_action('store_migrator_daily_full_sync', 'store_migrator_cron_full_sync');

function store_migrator_cron_inventory_sync() {
    if (!is_aspos_configured()) {
        store_migrator_log('Cron: ASPOS not configured, skipping inventory sync', 'warning');
        return;
    }
    
    store_migrator_log('Cron: Starting hourly inventory sync');
    $result = sync_all_inventory();
    store_migrator_log('Cron: Hourly inventory sync ' . ($result ? 'completed' : 'failed'));
}

function store_migrator_cron_full_sync() {
    if (!is_aspos_configured()) {
        store_migrator_log('Cron: ASPOS not configured, skipping full sync', 'warning');
        return;
    }
    
    store_migrator_log('Cron: Starting daily full sync');
    
    // Sync stores first
    $stores_result = sync_stores();
    store_migrator_log('Cron: Store sync ' . ($stores_result ? 'completed' : 'failed'));
    
    // Sync all products
    $products_result = sync_all_store_products();
    store_migrator_log('Cron: Product sync ' . ($products_result ? 'completed' : 'failed'));
    
    // Sync inventory
    $inventory_result = sync_all_inventory();
    store_migrator_log('Cron: Inventory sync ' . ($inventory_result ? 'completed' : 'failed'));
    
    // Sync prices
    $prices_result = sync_store_prices();
    store_migrator_log('Cron: Price sync ' . ($prices_result ? 'completed' : 'failed'));
    
    store_migrator_log('Cron: Daily full sync completed');
}

// Add manual queue processing
function store_migrator_queue_sync($type, $store_id = null) {
    $queue_option = 'store_migrator_sync_queue';
    $queue = get_option($queue_option, array());
    
    $task = array(
        'type' => $type,
        'store_id' => $store_id,
        'timestamp' => time(),
        'status' => 'pending'
    );
    
    $queue[] = $task;
    update_option($queue_option, $queue);
    
    // Schedule immediate processing
    wp_schedule_single_event(time() + 30, 'store_migrator_process_queue');
    
    store_migrator_log("Queued sync task: $type" . ($store_id ? " for store $store_id" : ''));
    return true;
}

add_action('store_migrator_process_queue', 'store_migrator_process_sync_queue');

function store_migrator_process_sync_queue() {
    $queue_option = 'store_migrator_sync_queue';
    $queue = get_option($queue_option, array());
    
    if (empty($queue)) {
        return;
    }
    
    // Process first item in queue
    $task = array_shift($queue);
    
    store_migrator_log("Processing queue task: {$task['type']}");
    
    $result = false;
    switch($task['type']) {
        case 'stores':
            $result = sync_stores();
            break;
        case 'products':
            if ($task['store_id']) {
                $result = sync_store_products($task['store_id']);
            } else {
                $result = sync_all_store_products();
            }
            break;
        case 'inventory':
            $result = sync_all_inventory();
            break;
        case 'prices':
            $result = sync_store_prices();
            break;
    }
    
    store_migrator_log("Queue task {$task['type']} " . ($result ? 'completed' : 'failed'));
    
    // Update queue
    update_option($queue_option, $queue);
    
    // If more items in queue, schedule next processing
    if (!empty($queue)) {
        wp_schedule_single_event(time() + 60, 'store_migrator_process_queue');
    }
}

// Settings page
function store_migrator_settings_page() {
    if (!is_aspos_configured()) {
        echo '<div class="wrap"><div class="notice notice-error"><p>Please configure ASPOS settings first. <a href="' . admin_url('admin.php?page=store-migrator-aspos-settings') . '">Configure Now</a></p></div></div>';
        return;
    }
    global $wpdb;
    
    if (isset($_POST['sync_all_data'])) {
        // Queue all sync operations in the correct order
        $results = array();
        $results[] = store_migrator_queue_sync('stores');
        $results[] = store_migrator_queue_sync('products');
        $results[] = store_migrator_queue_sync('inventory');
        $results[] = store_migrator_queue_sync('prices');
        
        $success = !in_array(false, $results);
        if ($success) {
            echo '<div class="notice notice-success"><p>Complete product sync queued successfully! All processes (Stores → Products → Inventory → Prices) will run in sequence. Check back in a few minutes.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to queue some sync operations.</p></div>';
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
            <h3>Complete Product Synchronization</h3>
            <p>This will sync all data in the following order:</p>
            <ol>
                <li>Sync Stores</li>
                <li>Sync All Products</li>
                <li>Sync Inventory</li>
                <li>Sync Prices</li>
            </ol>
            <p>
                <input type="submit" name="sync_all_data" class="button button-primary button-large" value="Sync Products" style="font-size: 16px; padding: 10px 20px;">
            </p>

            

            <h3>Sync Status</h3>
            <?php
            $queue = get_option('store_migrator_sync_queue', array());
            $next_hourly = wp_next_scheduled('store_migrator_hourly_sync');
            $next_daily = wp_next_scheduled('store_migrator_daily_full_sync');
            ?>
            <p><strong>Queue Items:</strong> <?php echo count($queue); ?></p>
            <p><strong>Next Hourly Sync:</strong> <?php echo $next_hourly ? date('Y-m-d H:i:s', $next_hourly) : 'Not scheduled'; ?></p>
            <p><strong>Next Daily Sync:</strong> <?php echo $next_daily ? date('Y-m-d H:i:s', $next_daily) : 'Not scheduled'; ?></p>

            <?php if (STORE_MIGRATOR_DEBUG): ?>
            <h3>Debug Log</h3>
            <div style="background: #fff; padding: 10px; margin-top: 10px; max-height: 400px; overflow: auto;">
                <pre><?php echo esc_html(file_exists(STORE_MIGRATOR_LOG_FILE) ? file_get_contents(STORE_MIGRATOR_LOG_FILE) : 'No logs yet.'); ?></pre>
            </div>
            <?php endif; ?>

            
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

        $page = 1;
        $limit = 50; // Adjust based on API limits
        
        do {
            $url = ASPOS_API_BASE . "/sync/web-products?storeId={$store->id}&page={$page}&limit={$limit}";
            $response = wp_remote_get($url, $args);
            
            if (is_wp_error($response)) {
                store_migrator_log("Price sync failed for store {$store->id} on page {$page}: " . $response->get_error_message(), 'error');
                break;
            }

            $response_body = wp_remote_retrieve_body($response);
            $data = json_decode($response_body);
            
            // Handle different response formats
            $products = is_array($data) ? $data : (isset($data->data) ? $data->data : array());
            $has_more = false;
            
            if (isset($data->pagination)) {
                $has_more = $data->pagination->hasMore ?? false;
            } elseif (isset($data->hasMore)) {
                $has_more = $data->hasMore;
            } else {
                // If no pagination info, check if we got a full page
                $has_more = count($products) >= $limit;
            }

            foreach ($products as $product) {
                $all_products[] = array(
                    'id' => $product->id,
                    'priceInclTax' => $product->priceInclTax,
                    'priceExclTax' => $product->priceExclTax,
                    'storeID' => $store->id
                );
            }
            
            store_migrator_log("Processed price page {$page} with " . count($products) . " products for store {$store->id}");
            $page++;
            
        } while ($has_more && count($products) > 0);
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