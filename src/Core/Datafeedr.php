<?php

namespace AEBG\Core;

use AEBG\Core\CurrencyManager;


class Datafeedr {
    public $access_id;
    public $access_key;
    public $enabled;
    private $api_url = 'https://api.datafeedr.com/search';

    public function __construct() {
        $options = get_option( 'aebg_settings' );
        $this->access_id = $options['datafeedr_access_id'] ?? '';
        $this->access_key = $options['datafeedr_secret_key'] ?? ''; // Using secret_key field for access_key
        $this->enabled = isset( $options['enable_datafeedr'] ) ? (bool) $options['enable_datafeedr'] : false;
        
        add_action( 'wp_ajax_aebg_search_products', [ $this, 'ajax_search_products' ] );
        add_action( 'wp_ajax_aebg_search_products_advanced', [ $this, 'ajax_search_products_advanced' ] );
        add_action( 'wp_ajax_aebg_test_datafeedr_connection', [ $this, 'ajax_test_connection' ] );
        
        // Add a simple test AJAX handler
        add_action( 'wp_ajax_aebg_test_ajax', [ $this, 'ajax_test_simple' ] );
        
        // Add fields test AJAX handler
        add_action( 'wp_ajax_aebg_test_fields', [ $this, 'ajax_test_fields' ] );
        add_action( 'wp_ajax_aebg_get_merchant_counts', [ $this, 'ajax_get_merchant_counts' ] );
        
        // Add merchant comparison modal AJAX handlers
        add_action( 'wp_ajax_aebg_get_merchant_comparison', [ $this, 'ajax_get_merchant_comparison' ] );
        add_action( 'wp_ajax_aebg_update_product_merchant', [ $this, 'ajax_update_product_merchant' ] );
        
        // Add cache management AJAX handlers
        add_action( 'wp_ajax_aebg_clear_merchant_cache', [ $this, 'ajax_clear_merchant_cache' ] );
        
        // Add product name update AJAX handler
        add_action( 'wp_ajax_aebg_update_product_name', [ $this, 'ajax_update_product_name' ] );
        
        // Add comparison data AJAX handlers
        add_action( 'wp_ajax_aebg_save_comparison', [ $this, 'ajax_save_comparison' ] );
        add_action( 'wp_ajax_aebg_load_comparison', [ $this, 'ajax_load_comparison' ] );
        add_action( 'wp_ajax_aebg_delete_comparison', [ $this, 'ajax_delete_comparison' ] );
        add_action( 'wp_ajax_aebg_get_user_comparisons', [ $this, 'ajax_get_user_comparisons' ] );
        add_action( 'wp_ajax_aebg_test_comparison_db', [ $this, 'ajax_test_comparison_db' ] );
        add_action( 'wp_ajax_aebg_clear_incorrect_comparisons', [ $this, 'ajax_clear_incorrect_comparisons' ] );
    }

    public function ajax_search_products() {
        // Verify nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'aebg_search_products' ) ) {
            wp_send_json_error( 'Invalid nonce.' );
            return;
        }
        
        // Check permissions
        if ( ! current_user_can( 'aebg_generate_content' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
            return;
        }
        
        // Validate and sanitize input
        $query = isset( $_POST['q'] ) ? sanitize_text_field( $_POST['q'] ) : '';
        
        // Validate query is not empty and has reasonable length
        if ( empty( $query ) ) {
            wp_send_json_error( 'Search query is required.' );
            return;
        }
        
        if ( strlen( $query ) > 500 ) {
            wp_send_json_error( 'Search query is too long (maximum 500 characters).' );
            return;
        }
        
        $results = $this->search( $query );
        if ( is_wp_error( $results ) ) {
            wp_send_json_error( $results->get_error_message() );
            return;
        }
        wp_send_json_success( $results );
    }

    public function ajax_search_products_advanced() {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log('[AEBG] ajax_search_products_advanced called');
            error_log('[AEBG] POST data: ' . print_r($_POST, true));
        }
        
        if ( ! wp_verify_nonce( $_POST['nonce'], 'aebg_search_products' ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log('[AEBG] Nonce verification failed');
            }
            wp_send_json_error( 'Invalid nonce.' );
            return;
        }

        $query = sanitize_text_field( $_POST['query'] ?? '' );
        $brand = sanitize_text_field( $_POST['brand'] ?? '' );
        $limit = intval( $_POST['limit'] ?? 50 );
        $sort_by = sanitize_text_field( $_POST['sort_by'] ?? 'relevance' );
        $min_price = isset( $_POST['min_price'] ) ? floatval( $_POST['min_price'] ) : 0;
        $max_price = isset( $_POST['max_price'] ) ? floatval( $_POST['max_price'] ) : 0;
        $min_rating = floatval( $_POST['min_rating'] ?? 0 );
        $in_stock_only = isset( $_POST['in_stock_only'] ) ? (bool) $_POST['in_stock_only'] : false;
        $currency = isset( $_POST['currency'] ) ? sanitize_text_field( $_POST['currency'] ) : '';
        $country = isset( $_POST['country'] ) ? sanitize_text_field( $_POST['country'] ) : '';
        $category = sanitize_text_field( $_POST['category'] ?? '' );
        $has_image = isset( $_POST['has_image'] ) ? (bool) $_POST['has_image'] : false;
        $page = intval( $_POST['page'] ?? 1 );
        // Handle both networks and network_ids parameters for backward compatibility
        $networks = [];
        if (isset($_POST['network_ids']) && !empty($_POST['network_ids'])) {
            $networks = (array) $_POST['network_ids'];
        } elseif (isset($_POST['networks']) && !empty($_POST['networks'])) {
            $networks = (array) $_POST['networks'];
        }
        
        // If no currency specified, use default from settings
        if (empty($currency)) {
            $settings = get_option('aebg_settings', []);
            $currency = $settings['default_currency'] ?? 'USD';
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log('[AEBG] Using default currency from settings: ' . $currency);
            }
        }
        
        // If no networks specified, use default from settings
        if (empty($networks)) {
            $settings = get_option('aebg_settings', []);
            $networks = $settings['default_networks'] ?? ['all'];
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log('[AEBG] Using default networks from settings: ' . implode(', ', $networks));
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log('[AEBG] Processed search parameters:');
            error_log('[AEBG] - query: ' . $query);
            error_log('[AEBG] - brand: ' . $brand);
            error_log('[AEBG] - limit: ' . $limit);
            error_log('[AEBG] - sort_by: ' . $sort_by);
            error_log('[AEBG] - min_price: ' . $min_price);
            error_log('[AEBG] - max_price: ' . $max_price);
            error_log('[AEBG] - min_rating: ' . $min_rating);
            error_log('[AEBG] - in_stock_only: ' . ($in_stock_only ? 'true' : 'false'));
            error_log('[AEBG] - currency: ' . $currency);
            error_log('[AEBG] - country: ' . $country);
            error_log('[AEBG] - category: ' . $category);
            error_log('[AEBG] - has_image: ' . ($has_image ? 'true' : 'false'));
            error_log('[AEBG] - page: ' . $page);
            error_log('[AEBG] - networks: ' . implode(', ', $networks));
        }

        if ( empty( $query ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log('[AEBG] Query is empty');
            }
            wp_send_json_error( 'Query is required.' );
        }

        // Calculate offset for pagination
        $offset = ( $page - 1 ) * $limit;
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log('[AEBG] Calculated offset: ' . $offset);
        }

        // Perform the search
        $results = $this->search_advanced( $query, $limit, $sort_by, $min_price, $max_price, $min_rating, $in_stock_only, $currency, '', $category, $has_image, $offset, $networks, $brand );

        if ( is_wp_error( $results ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log('[AEBG] Search returned error: ' . $results->get_error_message());
            }
            wp_send_json_error( $results->get_error_message() );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // Only log summary in debug mode, full results only in verbose mode
            if (defined('WP_DEBUG') && WP_DEBUG) {
                if (defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                    error_log('[AEBG] Search results: ' . print_r($results, true));
                } else {
                    $product_count = isset($results['products']) ? count($results['products']) : 0;
                    $merchant_count = isset($results['merchants']) ? count($results['merchants']) : 0;
                    $network_count = isset($results['networks']) ? count($results['networks']) : 0;
                    error_log('[AEBG] Search results: ' . $product_count . ' products, ' . $merchant_count . ' merchants, ' . $network_count . ' networks');
                }
            }
        }

        // Calculate pagination info using the new response structure
        $total_results = $results['total'] ?? $results['found_count'] ?? count($results['products'] ?? []);
        $found_count = $results['found_count'] ?? $total_results;
        $length = $results['length'] ?? count($results['products'] ?? []);
        $total_pages = ceil( $total_results / $limit );
        
        $pagination = [
            'current_page' => $page,
            'total_pages' => $total_pages,
            'total_results' => $total_results,
            'found_count' => $found_count,
            'length' => $length,
            'per_page' => $limit,
            'has_next' => $page < $total_pages,
            'has_prev' => $page > 1,
            'offset' => $offset
        ];

        $response_data = [
            'products' => $results['products'] ?? [],
            'pagination' => $pagination,
            'query' => $query,
            'filters' => [],
            'merchants' => $results['merchants'] ?? [],
            'networks' => $results['networks'] ?? [],
            'price_groups' => $results['price_groups'] ?? [],
            'duplicates_count' => $results['duplicates_count'] ?? 0,
            'result_count' => $results['result_count'] ?? count($results['products'] ?? [])
        ];
        
        // Only add filters that were actually sent
        if (isset($_POST['brand']) && $_POST['brand'] !== '') {
            $response_data['filters']['brand'] = $brand;
        }
        
        if (isset($_POST['category']) && $_POST['category'] !== '') {
            $response_data['filters']['category'] = $category;
        }
        
        if (isset($_POST['min_rating']) && $_POST['min_rating'] !== '') {
            $response_data['filters']['min_rating'] = $min_rating;
        }
        
        if (isset($_POST['has_image']) && $_POST['has_image'] !== '') {
            $response_data['filters']['has_image'] = $has_image;
        }
        
        if (isset($_POST['min_price']) && $_POST['min_price'] !== '') {
            $response_data['filters']['min_price'] = $min_price;
        }
        
        if (isset($_POST['max_price']) && $_POST['max_price'] !== '') {
            $response_data['filters']['max_price'] = $max_price;
        }
        
        if (isset($_POST['currency']) && $_POST['currency'] !== 'All Currencies') {
            $response_data['filters']['currency'] = $currency;
        }
        
        if (isset($_POST['country']) && $_POST['country'] !== '') {
            $response_data['filters']['country'] = $country;
        }
        
        if (isset($_POST['networks']) && !empty($_POST['networks'])) {
            $response_data['filters']['networks'] = $networks;
        }

        // Only log summary in debug mode, full data only in verbose mode
        if (defined('WP_DEBUG') && WP_DEBUG) {
            if (defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                error_log('[AEBG] Sending response: ' . print_r($response_data, true));
            } else {
                $product_count = isset($response_data['products']) ? count($response_data['products']) : 0;
                error_log('[AEBG] Sending response: ' . $product_count . ' products');
            }
        }
        wp_send_json_success( $response_data );
    }

    public function ajax_test_connection() {
        if ( ! wp_verify_nonce( $_POST['nonce'], 'aebg_ajax_nonce' ) ) {
            wp_send_json_error( 'Invalid nonce.' );
        }
        
        $result = $this->test_connection();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( 'Connection successful' );
    }

    public function ajax_test_simple() {
        // Check if nonce exists
        if ( ! isset( $_POST['nonce'] ) ) {
            wp_send_json_error( 'No nonce provided.' );
        }
        
        if ( ! wp_verify_nonce( $_POST['nonce'], 'aebg_ajax_nonce' ) ) {
            wp_send_json_error( 'Invalid nonce.' );
        }
        
        wp_send_json_success( 'Simple AJAX test successful' );
    }

    public function ajax_test_fields() {
        if ( ! wp_verify_nonce( $_POST['nonce'], 'aebg_ajax_nonce' ) ) {
            wp_send_json_error( 'Invalid nonce.' );
        }
        
        // Try a simple search to see what fields are available
        $request_data = [
            'aid' => $this->access_id,
            'akey' => $this->access_key,
            'query' => [ 'name LIKE "test"' ],
            'limit' => 1
        ];
        
        $response = $this->makeProtectedApiRequest('https://api.datafeedr.com/search', $request_data, 60);
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Network error: ' . $response->get_error_message() );
        }
        
        $status_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        

        
        if ( $status_code === 200 && isset( $data['products'] ) && ! empty( $data['products'] ) ) {
            $sample_product = $data['products'][0];
            $available_fields = array_keys( $sample_product );
            wp_send_json_success( [
                'message' => 'Fields test successful',
                'available_fields' => $available_fields,
                'sample_product' => $sample_product
            ] );
        } else {
            wp_send_json_error( 'Failed to get field information: ' . $body );
        }
    }

    public function ajax_get_merchant_counts() {
        if ( ! wp_verify_nonce( $_POST['nonce'], 'aebg_ajax_nonce' ) ) {
            wp_send_json_error( 'Invalid nonce.' );
        }
        
        $products = $_POST['products'] ?? [];
        if ( empty( $products ) || ! is_array( $products ) ) {
            wp_send_json_error( 'No products provided.' );
        }
        
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log('[AEBG] Merchant counting request received for ' . count($products) . ' products');
        }
        
        $merchant_info = [];
        
        foreach ( $products as $product ) {
            $product_id = $product['id'] ?? '';
            if ( ! empty( $product_id ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log('[AEBG] Processing product: ' . $product_id . ' - ' . ($product['name'] ?? 'Unknown'));
                }
                
                // First, try to get merchant info from saved comparison data in our database
                $comparison_data = $this->get_comparison_data_for_merchant_count($product_id);
                if ($comparison_data) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log('[AEBG] ✅ DATABASE HIT: Found comparison data for product ' . $product_id . ': ' . $comparison_data['merchant_count'] . ' merchants');
                    }
                    $merchant_info[$product_id] = $comparison_data;
                } else {
                    // Fallback to API if no comparison data exists
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log('[AEBG] 🔄 API FALLBACK: No comparison data found for product ' . $product_id . ', calling Datafeedr API...');
                    }
                    $actual_product_data = $this->get_product_data_from_database($product_id);
                    if ($actual_product_data) {
                        $product = array_merge($product, $actual_product_data);
                        error_log('[AEBG] 📊 Enhanced product data for ' . $product_id . ' - Name: "' . ($product['name'] ?? 'Unknown') . '", Brand: "' . ($product['brand'] ?? 'Unknown') . '", SKU: "' . ($product['sku'] ?? 'Unknown') . '", Merchant: "' . ($product['merchant'] ?? 'NOT SET') . '"');
                    } else {
                        error_log('[AEBG] ⚠️ No actual product data found for ' . $product_id . ', using basic product info');
                    }
                    
                    $info = $this->get_merchant_price_info( $product );
                    if ( is_wp_error( $info ) ) {
                        error_log('[AEBG] ❌ API ERROR: Error getting merchant info for product ' . $product_id . ': ' . $info->get_error_message());
                        $merchant_info[$product_id] = [
                            'merchant_count' => 1,
                            'price_range' => [
                                'lowest' => $product['price'] ?? 0,
                                'highest' => $product['price'] ?? 0
                            ],
                            'merchants' => [
                                'lowest_price' => [
                                    'name' => $product['merchant'] ?? 'Unknown',
                                    'price' => $product['price'] ?? 0
                                ],
                                'highest_price' => [
                                    'name' => $product['merchant'] ?? 'Unknown',
                                    'price' => $product['price'] ?? 0
                                ]
                            ]
                        ];
                    } else {
                        error_log('[AEBG] ✅ API SUCCESS: Fresh merchant info for product ' . $product_id . ': ' . $info['merchant_count'] . ' merchants, price range: ' . $info['price_range']['lowest'] . ' - ' . $info['price_range']['highest']);
                        error_log('[AEBG] 📋 API RESULTS: Lowest: ' . $info['merchants']['lowest_price']['name'] . ' (' . $info['merchants']['lowest_price']['price'] . '), Highest: ' . $info['merchants']['highest_price']['name'] . ' (' . $info['merchants']['highest_price']['price'] . ')');
                        $merchant_info[$product_id] = $info;
                    }
                }
            }
        }
        
        // Only log summary in debug mode, full data only in verbose mode
        if (defined('WP_DEBUG') && WP_DEBUG) {
            if (defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                error_log('[AEBG] Sending merchant info response: ' . json_encode($merchant_info));
            } else {
                error_log('[AEBG] Sending merchant info for ' . count($merchant_info) . ' products');
            }
        }
        wp_send_json_success( $merchant_info );
    }

    /**
     * Get comparison data for merchant count calculation
     * 
     * @param string $product_id Product ID
     * @return array|false Comparison data or false if not found
     */
    private function get_comparison_data_for_merchant_count($product_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'aebg_comparisons';
        $post_id = get_the_ID();
        $user_id = get_current_user_id();
        
        // Try to find comparison data for this product, post, and user
        $query = $wpdb->prepare(
            "SELECT comparison_data FROM $table_name WHERE user_id = %d AND post_id = %s AND product_id = %s AND status = 'active' ORDER BY updated_at DESC LIMIT 1",
            $user_id,
            $post_id,
            $product_id
        );
        
        $result = $wpdb->get_row($query, ARRAY_A);
        
        if ($result && !empty($result['comparison_data'])) {
            $data = json_decode($result['comparison_data'], true);
            
            if ($data && isset($data['merchants']) && is_array($data['merchants']) && !empty($data['merchants'])) {
                // Calculate merchant count and price range from stored comparison data
                $merchants = [];
                $prices = [];
                
                error_log('[AEBG] Processing ' . count($data['merchants']) . ' merchants from comparison data');
                
                foreach ($data['merchants'] as $merchant_name => $merchant_data) {
                    if (is_array($merchant_data) && isset($merchant_data['price'])) {
                        $price = floatval($merchant_data['price']);
                        $merchants[$merchant_name] = true;
                        $prices[] = $price;
                        
                        error_log('[AEBG] Found merchant: ' . $merchant_name . ' with price: ' . $price);
                    }
                }
                
                $merchant_count = count($merchants);
                $lowest_price = !empty($prices) ? min($prices) : 0;
                $highest_price = !empty($prices) ? max($prices) : 0;
                
                error_log('[AEBG] Calculated from comparison data: ' . $merchant_count . ' merchants, price range: ' . $lowest_price . ' - ' . $highest_price);
                error_log('[AEBG] Unique merchants found: ' . implode(', ', array_keys($merchants)));
                
                return [
                    'merchant_count' => $merchant_count,
                    'price_range' => [
                        'lowest' => floatval($lowest_price),
                        'highest' => floatval($highest_price)
                    ],
                    'merchants' => $data['merchants']
                ];
            }
        }
        
        error_log('[AEBG] No comparison data found for product: ' . $product_id);
        return false;
    }

    /**
     * Check if we're in Elementor editor context
     * 
     * CRITICAL: We must NOT add template prevention filters when in Elementor editor,
     * as they interfere with Elementor's ability to detect the_content() in templates.
     * 
     * @return bool True if in Elementor editor context, false otherwise
     */
    private function is_elementor_editor_context() {
        // Check if Elementor plugin is active
        if ( ! class_exists( '\Elementor\Plugin' ) ) {
            return false;
        }
        
        // Method 1: Check Elementor's editor API (most reliable)
        try {
            $elementor = \Elementor\Plugin::instance();
            if ( isset( $elementor->editor ) && method_exists( $elementor->editor, 'is_edit_mode' ) ) {
                if ( $elementor->editor->is_edit_mode() ) {
                    return true;
                }
            }
        } catch ( \Exception $e ) {
            // Elementor API not available, continue with other checks
        }
        
        // Method 2: Check for Elementor editor query parameters
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'elementor' ) {
            return true;
        }
        
        // Method 3: Check for Elementor preview mode
        if ( isset( $_GET['elementor-preview'] ) ) {
            return true;
        }
        
        // Method 4: Check for Elementor in POST data (AJAX requests)
        if ( isset( $_POST['action'] ) && strpos( $_POST['action'], 'elementor' ) !== false ) {
            return true;
        }
        
        // Method 5: Check if we're in admin and URL contains elementor
        if ( is_admin() && isset( $_SERVER['REQUEST_URI'] ) ) {
            $request_uri = $_SERVER['REQUEST_URI'];
            if ( strpos( $request_uri, 'elementor' ) !== false && strpos( $request_uri, 'action=elementor' ) !== false ) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Make protected API request
     * 
     * Makes a protected API request with proper timeout handling and error management.
     * 
     * @param string $endpoint API endpoint URL
     * @param array $request_data Request data to JSON encode
     * @param int $timeout Timeout in seconds
     * @return array|\WP_Error WordPress HTTP API response or WP_Error
     */
    private function makeProtectedApiRequest($endpoint, $request_data, $timeout = 10) {
        $request_start = microtime(true);
        
        // Set flag to prevent shortcode execution during API request
        $GLOBALS['AEBG_API_REQUEST_IN_PROGRESS'] = true;
        
        // Suppress error output during API requests
        $old_error_reporting = error_reporting();
        $old_display_errors = ini_get('display_errors');
        error_reporting(E_ERROR | E_PARSE); // Only show critical errors, suppress warnings/deprecations
        @ini_set('display_errors', 0); // Prevent error output
        
        // Start output buffering to catch any accidental output
        ob_start();
        
        // Set up watchdog timeout to detect hung requests
        $watchdog_timeout = $timeout + 5;
        
        // PHP-level timeout protection to prevent infinite hangs
        // If wp_remote_post hangs, PHP will kill it after this timeout
        // We add a safety buffer (timeout + 15s) to ensure the request can complete normally
        $php_timeout_buffer = $timeout + 15;
        $current_php_timeout = ini_get('max_execution_time');
        $original_php_timeout = $current_php_timeout;
        $php_timeout_modified = false;
        
        // Only modify PHP timeout if absolutely necessary to prevent hangs
        // DO NOT reduce timeout if it's already at a reasonable value (like 1800s)
        // Reducing timeout unnecessarily can cause premature job termination
        // 
        // Only set PHP timeout if:
        // 1. Current timeout is 0 (unlimited) - dangerous, must set a limit
        // 2. Current timeout is unreasonably high (> 3600s) - reduce to buffer to prevent hangs
        // 3. Current timeout is too low (< buffer) - increase to buffer to prevent premature kills
        // 
        // DO NOT reduce timeout if it's already reasonable (e.g., 1800s) - this can kill long-running jobs!
        $unreasonably_high_threshold = 3600; // 1 hour - anything above this is considered unreasonably high
        
        if ($current_php_timeout == 0) {
            // Unlimited timeout - set to buffer to prevent infinite hangs
            $safe_timeout = $php_timeout_buffer;
            if (@set_time_limit($safe_timeout)) {
                $php_timeout_modified = true;
                // Only log timeout modifications (important for debugging hangs)
                error_log('[AEBG] makeProtectedApiRequest: Set PHP timeout to ' . $safe_timeout . 's (was: unlimited) for request timeout: ' . $timeout . 's');
            }
        } elseif ($current_php_timeout > $unreasonably_high_threshold) {
            // Unreasonably high timeout - reduce to buffer to prevent hangs
            $safe_timeout = $php_timeout_buffer;
            if (@set_time_limit($safe_timeout)) {
                $php_timeout_modified = true;
                // Only log timeout modifications (important for debugging hangs)
                error_log('[AEBG] makeProtectedApiRequest: Reduced PHP timeout from ' . $current_php_timeout . 's to ' . $safe_timeout . 's (was unreasonably high) for request timeout: ' . $timeout . 's');
            }
        } elseif ($current_php_timeout < $php_timeout_buffer) {
            // Too low timeout - increase to buffer to prevent premature kills
            $safe_timeout = $php_timeout_buffer;
            if (@set_time_limit($safe_timeout)) {
                $php_timeout_modified = true;
                // Only log timeout modifications (important for debugging hangs)
                error_log('[AEBG] makeProtectedApiRequest: Increased PHP timeout from ' . $current_php_timeout . 's to ' . $safe_timeout . 's (was too low) for request timeout: ' . $timeout . 's');
            }
        } else {
            // Current timeout is reasonable (between buffer and unreasonably_high_threshold)
            // DO NOT modify it - leave it alone to prevent premature job termination
            // Only log timeout adjustments, not routine checks
        }
        
        try {
            $json_body = json_encode($request_data);
            if ($json_body === false) {
                return new \WP_Error('json_encode_failed', 'Failed to encode request body: ' . json_last_error_msg());
            }
            
            // Only log API requests in verbose debug mode
            if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                error_log('[AEBG] makeProtectedApiRequest: Making Datafeedr API request to ' . $endpoint . ' with timeout: ' . $timeout . 's');
            }
            
            // Force fresh connection to prevent reusing stale connections from previous articles
            // This is especially important for the second+ articles which may inherit bad connection state
            // Use cURL options to force fresh connections and prevent reuse
            // This bypasses WordPress HTTP API connection pool issues
            // WordPress HTTP API supports 'curl' array in args to pass cURL options directly
            $response = wp_remote_post(
                $endpoint,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'User-Agent' => 'WordPress/AEBG-Plugin/1.0.1',
                        'Connection' => 'close', // Force connection close to prevent reuse
                    ],
                    'body' => $json_body,
                    'timeout' => $timeout,
                    'blocking' => true,
                    'sslverify' => true,
                    'redirection' => 0,
                    'httpversion' => '1.1',
                    'compress' => false,
                    'decompress' => true,
                    'connect_timeout' => min(10, $timeout / 2),
                    'reject_unsafe_urls' => false,
                    'cookies' => false, // Don't send cookies (fresh request)
                    // Pass cURL options directly to force fresh connections
                    // WordPress HTTP API will pass these to cURL if using cURL transport
                    'curl' => [
                        CURLOPT_FRESH_CONNECT => true,  // Force new connection
                        CURLOPT_FORBID_REUSE => true,   // Prevent connection reuse
                        CURLOPT_TCP_NODELAY => true,    // Disable Nagle algorithm for faster connections
                        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_WHATEVER, // Let cURL decide IP version
                    ],
                ]
            );
            
            $request_elapsed = microtime(true) - $request_start;
            
            // Only log request completion if it's slow or in verbose debug mode
            if ($request_elapsed > 2.0 || (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG)) {
                error_log('[AEBG] makeProtectedApiRequest: Request completed in ' . round($request_elapsed, 2) . 's');
            }
            
            // Check if request exceeded watchdog timeout (hung request)
            if ($request_elapsed > $watchdog_timeout) {
                error_log('[AEBG] ⚠️ WARNING: Datafeedr API request exceeded watchdog timeout (' . round($request_elapsed, 2) . 's > ' . $watchdog_timeout . 's) - treating as hung request');
                if (!is_wp_error($response)) {
                    $response = new \WP_Error('http_request_timeout', 'Request exceeded watchdog timeout (' . round($request_elapsed, 2) . 's > ' . $watchdog_timeout . 's) - hung request detected');
                }
            }
        } finally {
            // Always restore PHP timeout if we modified it
            if ($php_timeout_modified && isset($original_php_timeout)) {
                if ($original_php_timeout == 0) {
                    @set_time_limit(0); // Restore unlimited if it was unlimited
                } else {
                    @set_time_limit($original_php_timeout);
                }
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log('[AEBG] makeProtectedApiRequest: Restored PHP timeout to: ' . ($original_php_timeout == 0 ? 'unlimited' : $original_php_timeout . 's'));
                }
            }
            
            // Discard any output that was buffered (should be none, but just in case)
            $buffered_output = ob_get_clean();
            if (!empty($buffered_output)) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log('[AEBG] ⚠️ WARNING: Output was generated during Datafeedr API request (length: ' . strlen($buffered_output) . ' bytes)');
                    error_log('[AEBG] ⚠️ Buffered output preview: ' . substr($buffered_output, 0, 500));
                }
            }
            
            // Restore error reporting
            error_reporting($old_error_reporting);
            if ($old_display_errors !== false) {
                @ini_set('display_errors', $old_display_errors);
            }
            
            // Clear the API request flag
            unset($GLOBALS['AEBG_API_REQUEST_IN_PROGRESS']);
        }
        
        return $response;
    }

    /**
     * Static cache for product data lookups to prevent redundant queries
     * 
     * @var array
     */
    private static $product_data_cache = [];
    
    /**
     * Static request-level cache for search results to prevent duplicate API calls
     * 
     * @var array
     */
    private static $search_request_cache = [];
    
    /**
     * Get product data from database by product ID
     * 
     * CRITICAL: Uses direct database query instead of get_posts() to avoid:
     * - Loading all posts into memory (performance issue)
     * - Triggering WordPress hooks/filters (can cause hangs during generation)
     * - Database connection issues when called multiple times
     * 
     * CACHED: Results are cached per request to prevent redundant queries
     * 
     * @param string $product_id Product ID
     * @return array|false Product data or false if not found
     */
    public function get_product_data_from_database($product_id) {
        // CRITICAL: Skip database lookup for invalid/single-digit product IDs (likely errors)
        // Single digits like "1", "2", etc. are not valid product IDs
        if (empty($product_id) || (strlen($product_id) <= 2 && is_numeric($product_id))) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[AEBG] ⚠️ SKIPPING database lookup for invalid product ID: "' . $product_id . '" (too short, likely an error)');
            }
            return false;
        }
        
        // Check cache first
        if (isset(self::$product_data_cache[$product_id])) {
            return self::$product_data_cache[$product_id];
        }
        
        global $wpdb;
        
        // CRITICAL: Use direct database query instead of get_posts()
        // This avoids triggering WordPress hooks and loading all posts into memory
        // Limit to 50 posts max to prevent performance issues
        // NOTE: No placeholders needed, so we don't use wpdb->prepare() here
        // $wpdb->postmeta is a safe WordPress property containing the table name
        // SECURITY: This query is safe - meta_key is hardcoded, no user input
        $query = "SELECT post_id, meta_value 
                  FROM {$wpdb->postmeta} 
                  WHERE meta_key = '_aebg_products' 
                  ORDER BY post_id DESC
                  LIMIT 50";
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        if (empty($results)) {
            // Cache negative result to avoid repeated queries
            self::$product_data_cache[$product_id] = false;
            return false;
        }
        
        // Search through the meta values for the product ID
        foreach ($results as $row) {
            $products = maybe_unserialize($row['meta_value']);
            if (is_array($products)) {
                foreach ($products as $product) {
                    if (isset($product['id']) && $product['id'] == $product_id) {
                        // Cache the found product
                        self::$product_data_cache[$product_id] = $product;
                        return $product;
                    }
                }
            }
        }
        
        // Cache negative result to avoid repeated queries
        self::$product_data_cache[$product_id] = false;
        return false;
    }

    public function search( $query, $limit = 10 ) {
        // CRITICAL: Skip API call for invalid product IDs (single digits like "1", "2", etc.)
        // Check if this is an ID-based search with an invalid ID
        if (preg_match('/^id:(\d+)$/i', $query, $matches)) {
            $product_identifier = trim($matches[1]);
            if (strlen($product_identifier) <= 2 && is_numeric($product_identifier)) {
                error_log('[AEBG] ⚠️ SKIPPING API call for invalid product ID in search: "' . $product_identifier . '" (too short, likely an error)');
                return []; // Return empty array to prevent expensive API call
            }
        }
        
        // Check if Datafeedr is enabled and credentials are set
        if ( ! $this->enabled ) {
            error_log('[AEBG] Datafeedr search failed: integration is disabled');
            return new \WP_Error( 'datafeedr_disabled', __( 'Datafeedr integration is disabled.', 'aebg' ) );
        }

        if ( empty( $this->access_id ) || empty( $this->access_key ) ) {
            error_log('[AEBG] Datafeedr search failed: credentials missing');
            return new \WP_Error( 'datafeedr_credentials_missing', __( 'Datafeedr Access ID and Access Key are required.', 'aebg' ) );
        }

        // Sanitize and validate query
        $query = trim($query);
        if (empty($query)) {
            error_log( '[AEBG] Datafeedr search failed: empty query' );
            return new \WP_Error( 'datafeedr_invalid_query', __( 'Search query cannot be empty.', 'aebg' ) );
        }

        // Validate limit
        $limit = max(1, min(100, (int) $limit));

        // Layer 1: Request-level cache (fastest) - check before API call
        $cache_key = md5($query . '_' . $limit);
        
        if (isset(self::$search_request_cache[$cache_key])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[AEBG] Datafeedr search: Request cache HIT for query: "' . $query . '"');
            }
            return self::$search_request_cache[$cache_key];
        }

        // Layer 2: WordPress object cache (cross-request, 10 minutes)
        $wp_cache_key = 'aebg_search_' . $cache_key;
        $cached_results = wp_cache_get($wp_cache_key, 'aebg_searches');
        
        if ($cached_results !== false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[AEBG] Datafeedr search: Object cache HIT for query: "' . $query . '"');
            }
            self::$search_request_cache[$cache_key] = $cached_results;
            return $cached_results;
        }

        error_log('[AEBG] Datafeedr search request: query="' . $query . '", limit=' . $limit);

        // Build intelligent search queries based on the input
        $search_conditions = $this->build_search_conditions_from_query($query);
        
        if (empty($search_conditions)) {
            error_log('[AEBG] Datafeedr search failed: could not build search conditions');
            $error = new \WP_Error( 'datafeedr_invalid_query', __( 'Could not build search conditions from query.', 'aebg' ) );
            self::$search_request_cache[$cache_key] = $error;
            return $error;
        }

        // Prepare the request data according to Datafeedr API documentation
        $request_data = [
            'aid' => $this->access_id,
            'akey' => $this->access_key,
            'query' => $search_conditions,
            'limit' => $limit
        ];

        error_log('[AEBG] Datafeedr search conditions: ' . json_encode($search_conditions));

        // Make POST request to Datafeedr API
        $response = $this->makeProtectedApiRequest('https://api.datafeedr.com/search', $request_data, 60);

        if ( is_wp_error( $response ) ) {
            error_log('[AEBG] Datafeedr network error: ' . $response->get_error_message());
            $error = new \WP_Error( 'datafeedr_network_error', 'Network error: ' . $response->get_error_message() );
            self::$search_request_cache[$cache_key] = $error;
            return $error;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        error_log('[AEBG] Datafeedr response: status=' . $status_code . ', body_length=' . strlen($body));

        // Check for HTTP errors
        if ( $status_code !== 200 ) {
            $error_message = 'HTTP Error ' . $status_code;
            if ( isset( $data['message'] ) ) {
                $error_message .= ': ' . $data['message'];
            }
            error_log('[AEBG] Datafeedr HTTP error: ' . $error_message);
            $error = new \WP_Error( 'datafeedr_http_error', $error_message );
            self::$search_request_cache[$cache_key] = $error;
            return $error;
        }

        // Check for API errors
        if ( isset( $data['error'] ) ) {
            $error_message = 'API Error ' . $data['error'];
            if ( isset( $data['message'] ) ) {
                $error_message .= ': ' . $data['message'];
            }
            if ( isset( $data['details'] ) ) {
                $error_message .= ' - ' . $data['details'];
            }
            error_log('[AEBG] Datafeedr API error: ' . $error_message);
            $error = new \WP_Error( 'datafeedr_api_error', $error_message );
            self::$search_request_cache[$cache_key] = $error;
            return $error;
        }

        // Return products if available
        if ( isset( $data['products'] ) && is_array($data['products']) ) {
            error_log('[AEBG] Raw products from basic search API: ' . count($data['products']) . ' items');
            
            // Filter out any non-product items (merchants, networks, etc.)
            $actual_products = array_filter($data['products'], function($item) {
                // Check if this is actually a product (has required product fields)
                return is_array($item) && 
                       isset($item['name']) && 
                       !empty($item['name']) && 
                       (isset($item['price']) || isset($item['finalprice']));
            });
            
            error_log('[AEBG] Filtered actual products from basic search: ' . count($actual_products) . ' items');
            
            $formatted_products = $this->format_products( $actual_products );
            
            // Get default currency from settings for post-filtering
            $settings = get_option('aebg_settings', []);
            $default_currency = $settings['default_currency'] ?? 'USD';
            
            // Apply currency filter if specified in settings - but be more flexible
            if ( ! empty( $default_currency ) && $default_currency !== 'All Currencies' ) {
                $before_count = count($formatted_products);
                
                // First try exact currency match
                $exact_currency_products = array_filter($formatted_products, function($product) use ($default_currency) {
                    return isset($product['currency']) && $product['currency'] === $default_currency;
                });
                
                // If we have exact currency matches, use those
                if (!empty($exact_currency_products)) {
                    $formatted_products = $exact_currency_products;
                    error_log('[AEBG] Basic search currency filter applied: ' . $before_count . ' -> ' . count($formatted_products) . ' products (exact currency match: ' . $default_currency . ')');
                } else {
                    // If no exact matches, try to find products with similar currencies or no currency specified
                    $flexible_currency_products = array_filter($formatted_products, function($product) use ($default_currency) {
                        // Accept products with no currency specified (likely default currency)
                        if (!isset($product['currency']) || empty($product['currency'])) {
                            return true;
                        }
                        // Accept products with similar currencies (e.g., USD vs DKK for testing)
                        if (in_array($product['currency'], ['USD', 'DKK', 'EUR'])) {
                            return true;
                        }
                        return false;
                    });
                    
                    if (!empty($flexible_currency_products)) {
                        $formatted_products = $flexible_currency_products;
                        error_log('[AEBG] Basic search currency filter applied: ' . $before_count . ' -> ' . count($formatted_products) . ' products (flexible currency match: ' . $default_currency . ')');
                    } else {
                        // If still no matches, keep original products but log the issue
                        error_log('[AEBG] Warning: No products match currency filter, keeping all ' . $before_count . ' products for broader compatibility');
                    }
                }
            }
            
            // If we found products, try to save them to the database for future use
            if (!empty($formatted_products) && count($formatted_products) > 0) {
                $this->save_found_products_to_database($formatted_products, $query);
            }
            
            // Cache in all layers for future requests
            self::$search_request_cache[$cache_key] = $formatted_products;
            wp_cache_set($wp_cache_key, $formatted_products, 'aebg_searches', 10 * MINUTE_IN_SECONDS);
            
            error_log('[AEBG] Datafeedr search successful: found ' . count($formatted_products) . ' products');
            return $formatted_products;
        }

        // No products found - cache empty result
        $empty_result = [];
        self::$search_request_cache[$cache_key] = $empty_result;
        wp_cache_set($wp_cache_key, $empty_result, 'aebg_searches', 5 * MINUTE_IN_SECONDS);
        
        error_log('[AEBG] Datafeedr search successful: found 0 products');
        return $empty_result;
    }

    /**
     * Build intelligent search conditions based on the query
     * 
     * @param string $query The search query
     * @return array Array of search conditions
     */
    private function build_search_conditions_from_query($query) {
        $conditions = [];
        
        // Check if this is an ID-based search
        if (preg_match('/^id:(.+)$/i', $query, $matches)) {
            $product_identifier = trim($matches[1]);
            error_log('[AEBG] ID-based search detected for: ' . $product_identifier);
            
            // Extract key components from the product identifier
            $components = $this->extract_product_components($product_identifier);
            
            if (!empty($components['brand']) && !empty($components['model'])) {
                // Brand + Model search (most specific)
                $conditions[] = 'name LIKE "%' . $this->sanitize_condition_value($components['brand']) . '%"';
                $conditions[] = 'name LIKE "%' . $this->sanitize_condition_value($components['model']) . '%"';
                error_log('[AEBG] Using brand + model search: ' . $components['brand'] . ' + ' . $components['model']);
            } elseif (!empty($components['brand'])) {
                // Brand only search
                $conditions[] = 'name LIKE "%' . $this->sanitize_condition_value($components['brand']) . '%"';
                error_log('[AEBG] Using brand-only search: ' . $components['brand']);
            } elseif (!empty($components['model'])) {
                // Model only search
                $conditions[] = 'name LIKE "%' . $this->sanitize_condition_value($components['model']) . '%"';
                error_log('[AEBG] Using model-only search: ' . $components['model']);
            } else {
                // Extract key terms and use broader search
                $key_terms = $this->extract_key_product_terms($product_identifier);
                if (!empty($key_terms)) {
                    // Use key terms with broader matching
                    $search_terms = implode(' ', array_slice($key_terms, 0, 4)); // Use up to 4 key terms
                    $conditions[] = 'name LIKE "%' . $this->sanitize_condition_value($search_terms) . '%"';
                    error_log('[AEBG] Using key terms search: ' . $search_terms);
                } else {
                    // CRITICAL: Skip API call for invalid/single-digit product IDs (likely errors)
                    // Single digits like "1", "2", etc. are not valid product IDs and will return huge result sets
                    if (strlen($product_identifier) <= 2 && is_numeric($product_identifier)) {
                        error_log('[AEBG] ⚠️ SKIPPING API call for invalid product ID: "' . $product_identifier . '" (too short, likely an error)');
                        return []; // Return empty to prevent expensive API call
                    }
                    
                    // Fallback to simple name search
                    $conditions[] = 'name LIKE "%' . $this->sanitize_condition_value($product_identifier) . '%"';
                    error_log('[AEBG] Using fallback name search: ' . $product_identifier);
                }
            }
        } else {
            // Regular search - use the query as-is
            $conditions[] = 'name LIKE "%' . $this->sanitize_condition_value($query) . '%"';
            error_log('[AEBG] Using regular name search: ' . $query);
        }
        
        return $conditions;
    }





    /**
     * Advanced search with filters and sorting
     * 
     * @param string $query Search query
     * @param int $limit Number of results
     * @param string $sort_by Sort method
     * @param float $min_price Minimum price
     * @param float $max_price Maximum price
     * @param float $min_rating Minimum rating
     * @param bool $in_stock_only Only in stock products
     * @param string $currency Currency filter
     * @param bool $has_image Only products with images
     * @return array|WP_Error Products array or WP_Error
     */
    /**
     * Search products specifically for merchant discovery during bulk generation
     * This method is designed to find the exact same product from different merchants
     * without duplicate/color filtering to build comprehensive price comparison data
     * 
     * @param array $params Search parameters for merchant discovery
     * @return array|\WP_Error Search results
     */
    public function search_products_for_merchant_discovery($params) {
        if (!$this->is_configured()) {
            return new \WP_Error('datafeedr_not_configured', 'Datafeedr is not configured');
        }

        // Extract parameters
        $product_id = $params['product_id'] ?? '';
        $product_name = $params['product_name'] ?? '';
        $brand = $params['brand'] ?? '';
        $category = $params['category'] ?? '';
        $price_min = $params['price_min'] ?? 0;
        $price_max = $params['price_max'] ?? 0;
        $limit = $params['limit'] ?? 5;
        $disable_duplicate_detection = $params['disable_duplicate_detection'] ?? true;
        $disable_color_filtering = $params['disable_color_filtering'] ?? true;
        $include_existing_merchant = $params['include_existing_merchant'] ?? true;
        $prefer_different_merchants = $params['prefer_different_merchants'] ?? true;
        $search_strategy = $params['search_strategy'] ?? 'exact_match';

        error_log('[AEBG] Merchant discovery search for product: ' . $product_name . ' (ID: ' . $product_id . ')');

        // Build search query based on strategy - use more precise conditions
        $search_conditions = $this->build_precise_merchant_discovery_conditions($product_name, $brand, $search_strategy);
        
        if (empty($search_conditions)) {
            return new \WP_Error('invalid_search_query', 'Could not build search query for merchant discovery');
        }

        // Build the API request using the correct Datafeedr API format
        $request_data = [
            'aid' => $this->access_id,
            'akey' => $this->access_key,
            'query' => $search_conditions,
            'limit' => $limit * 3, // Request more to account for filtering
            'fields' => [
                'id', 'name', 'brand', 'price', 'currency', 'merchant', 'url', 'image', 'category', 'sku', 'mpn', 'upc', 'ean'
            ]
        ];

        // Only log merchant discovery requests in verbose debug mode
        if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
            error_log('[AEBG] Merchant discovery API request: ' . json_encode($request_data));
        }

        // Make API request to the correct endpoint with optimized timeout for merchant discovery
        $response = $this->makeProtectedApiRequest('https://api.datafeedr.com/search', $request_data, 15);

        if (is_wp_error($response)) {
            // Always log errors
            error_log('[AEBG] Merchant discovery API error: ' . $response->get_error_message());
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Only log API responses if there's an error or in verbose debug mode
        if ($status_code !== 200 || (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG)) {
            error_log('[AEBG] Merchant discovery API response - Status: ' . $status_code . ', Body length: ' . strlen($body));
        }

        // Check for errors
        if ($status_code !== 200 || isset($data['error'])) {
            $error_message = 'API Error ' . ($data['error'] ?? $status_code);
            if (isset($data['message'])) {
                $error_message .= ': ' . $data['message'];
            }
            error_log('[AEBG] Merchant discovery API error: ' . $error_message);
            return new \WP_Error('api_error', $error_message);
        }

        // Process the response according to Datafeedr API structure
        if (isset($data['products']) && is_array($data['products'])) {
            $products = $data['products'];
            error_log('[AEBG] Merchant discovery found ' . count($products) . ' raw products');
        } else {
            error_log('[AEBG] Merchant discovery: No products found in API response');
            $products = [];
        }

        // Filter and process results with more strict filtering
        // Apply currency filter after getting results (since API doesn't support currency in query)
        $settings = get_option('aebg_settings', []);
        $default_currency = $settings['default_currency'] ?? 'USD';
        if (!empty($default_currency) && $default_currency !== 'All Currencies') {
            $products_before_currency = count($products);
            $products = array_filter($products, function($product) use ($default_currency) {
                return isset($product['currency']) && $product['currency'] === $default_currency;
            });
            $products_after_currency = count($products);
            error_log('[AEBG] Applied currency filter (' . $default_currency . ') - ' . $products_before_currency . ' products before, ' . $products_after_currency . ' after');
        }
        
        $filtered_products = $this->filter_merchant_discovery_results_strict($products, $params);
        
        error_log('[AEBG] Merchant discovery filtered to ' . count($filtered_products) . ' products');
        
        return [
            'products' => $filtered_products,
            'total_found' => count($filtered_products),
            'found_count' => count($filtered_products),
            'length' => count($filtered_products),
            'offset' => 0,
            'limit' => $limit,
            'merchants' => $data['merchants'] ?? [],
            'networks' => $data['networks'] ?? [],
            'price_groups' => $data['price_groups'] ?? [],
            'duplicates_count' => $data['duplicates_count'] ?? 0,
            'result_count' => count($filtered_products)
        ];
    }

    /**
     * Build precise search conditions for merchant discovery
     * 
     * @param string $product_name Product name
     * @param string $brand Product brand
     * @param string $search_strategy Search strategy
     * @return array Search conditions
     */
    private function build_precise_merchant_discovery_conditions($product_name, $brand, $search_strategy) {
        $conditions = [];
        
        if (empty($product_name)) {
            return $conditions;
        }

        // Clean and normalize the product name
        $clean_name = $this->normalize_product_name($product_name);
        
        switch ($search_strategy) {
            case 'exact_match':
                // Use exact product name matching for highest precision
                $exact_name = $this->get_exact_product_name_for_search($clean_name);
                if (!empty($exact_name)) {
                    $conditions[] = 'name = "' . $this->sanitize_condition_value($exact_name) . '"';
                    error_log('[AEBG] Added exact name condition: name = "' . $exact_name . '"');
                }
                break;
                
            case 'brand_model':
                // Use brand + model combination for precision
                if (!empty($brand)) {
                    $model = $this->extract_model_from_name($clean_name);
                    if (!empty($model)) {
                        $conditions[] = 'brand = "' . $this->sanitize_condition_value($brand) . '"';
                        $conditions[] = 'name LIKE "%' . $this->sanitize_condition_value($model) . '%"';
                        error_log('[AEBG] Added brand + model conditions: brand = "' . $brand . '", name LIKE "%' . $model . '%"');
                    } else {
                        $conditions[] = 'brand = "' . $this->sanitize_condition_value($brand) . '"';
                        error_log('[AEBG] Added brand condition: brand = "' . $brand . '"');
                    }
                }
                break;
                
            case 'key_terms':
                // Use key product terms for broader but still relevant search
                $key_terms = $this->extract_key_product_terms($clean_name);
                if (!empty($key_terms)) {
                    $condition = 'name LIKE "%' . $this->sanitize_condition_value(implode(' ', $key_terms)) . '%"';
                    $conditions[] = $condition;
                    error_log('[AEBG] Added key terms condition: ' . $condition);
                }
                break;
                
            default:
                // Default to exact match
                $exact_name = $this->get_exact_product_name_for_search($clean_name);
                if (!empty($exact_name)) {
                    $conditions[] = 'name = "' . $this->sanitize_condition_value($exact_name) . '"';
                    error_log('[AEBG] Added default exact name condition: name = "' . $exact_name . '"');
                }
                break;
        }

        // NOTE: Currency filter removed from query - Datafeedr API returns "Invalid currency" error (405)
        // Currency filtering will be done after getting results instead
        $settings = get_option('aebg_settings', []);
        $default_currency = $settings['default_currency'] ?? 'USD';
        if (!empty($default_currency) && $default_currency !== 'All Currencies') {
            error_log('[AEBG] Currency filter will be applied after API response: ' . $default_currency);
        }

        error_log('[AEBG] Built ' . count($conditions) . ' precise merchant discovery conditions');
        return $conditions;
    }

    /**
     * Filter merchant discovery results with strict criteria to ensure exact product matches
     * 
     * @param array $products Raw products from API
     * @param array $params Search parameters
     * @return array Filtered products
     */
    private function filter_merchant_discovery_results_strict($products, $params) {
        if (empty($products)) {
            return [];
        }

        $filtered_products = [];
        $original_name = $params['product_name'] ?? '';
        $original_brand = $params['brand'] ?? '';
        $price_min = $params['price_min'] ?? 0;
        $price_max = $params['price_max'] ?? 0;
        
        // Normalize original product name for comparison
        $normalized_original = $this->normalize_product_name($original_name);
        $exact_original = $this->get_exact_product_name_for_search($normalized_original);
        
        error_log('[AEBG] Filtering ' . count($products) . ' products with strict criteria');
        error_log('[AEBG] Original product: ' . $original_name . ' (normalized: ' . $normalized_original . ', exact: ' . $exact_original . ')');

        foreach ($products as $product) {
            if (!$this->is_valid_product_for_merchant_discovery($product)) {
                continue;
            }

            $product_name = $product['name'] ?? '';
            $product_brand = $product['brand'] ?? '';
            $product_currency = $product['currency'] ?? CurrencyManager::getDefaultCurrency();
            $product_price = $this->convert_datafeedr_price($product['price'] ?? 0, $product_currency);
            
            // Skip if price is outside the acceptable range
            if ($price_min > 0 && $product_price < $price_min) {
                error_log('[AEBG] Skipping product due to low price: ' . $product_name . ' (price: ' . $product_price . ', min: ' . $price_min . ')');
                continue;
            }
            
            if ($price_max > 0 && $product_price > $price_max) {
                error_log('[AEBG] Skipping product due to high price: ' . $product_name . ' (price: ' . $product_price . ', max: ' . $price_max . ')');
                continue;
            }

            // Check if this is the exact same product
            if ($this->is_exact_same_product($product_name, $product_brand, $normalized_original, $original_brand)) {
                $filtered_products[] = $product;
                error_log('[AEBG] ✅ Added exact match: ' . $product_name . ' (brand: ' . $product_brand . ', price: ' . $product_price . ')');
            } else {
                error_log('[AEBG] ❌ Skipped non-exact match: ' . $product_name . ' (brand: ' . $product_brand . ')');
            }
        }

        error_log('[AEBG] Strict filtering resulted in ' . count($filtered_products) . ' exact product matches');
        return $filtered_products;
    }

    /**
     * Check if a product is valid for merchant discovery
     * 
     * @param array $product Product data
     * @return bool True if valid
     */
    private function is_valid_product_for_merchant_discovery($product) {
        // Must have required fields
        if (empty($product['name']) || empty($product['merchant']) || !isset($product['price'])) {
            return false;
        }
        
        // Must have a valid price
        $price = $this->convert_datafeedr_price($product['price'] ?? 0);
        if ($price <= 0) {
            return false;
        }
        
        // Must have a valid merchant name
        if (empty(trim($product['merchant']))) {
            return false;
        }
        
        return true;
    }

    /**
     * Check if two products are the exact same product
     * 
     * @param string $product1_name First product name
     * @param string $product1_brand First product brand
     * @param string $product2_name Second product name
     * @param string $product2_brand Second product brand
     * @return bool True if exact same product
     */
    private function is_exact_same_product($product1_name, $product1_brand, $product2_name, $product2_brand) {
        // Normalize both product names
        $normalized1 = $this->normalize_product_name($product1_name);
        $normalized2 = $this->normalize_product_name($product2_name);
        
        // Get exact names for comparison
        $exact1 = $this->get_exact_product_name_for_search($normalized1);
        $exact2 = $this->get_exact_product_name_for_search($normalized2);
        
        // Check if names are exactly the same
        if ($exact1 === $exact2) {
            return true;
        }
        
        // Check if brands match and names are very similar
        if (!empty($product1_brand) && !empty($product2_brand) && 
            strtolower(trim($product1_brand)) === strtolower(trim($product2_brand))) {
            
            // Check if names are similar (allowing for minor variations)
            $similarity = $this->calculate_name_similarity($exact1, $exact2);
            if ($similarity >= 0.9) { // 90% similarity threshold
                return true;
            }
        }
        
        return false;
    }

    /**
     * Calculate similarity between two product names
     * 
     * @param string $name1 First name
     * @param string $name2 Second name
     * @return float Similarity score (0.0 to 1.0)
     */
    private function calculate_name_similarity($name1, $name2) {
        if (empty($name1) || empty($name2)) {
            return 0.0;
        }
        
        // Convert to lowercase for comparison
        $name1 = strtolower(trim($name1));
        $name2 = strtolower(trim($name2));
        
        // Use similar_text for similarity calculation
        similar_text($name1, $name2, $percent);
        
        return $percent / 100;
    }

    public function search_advanced( $query, $limit = 50, $sort_by = 'relevance', $min_price = 0, $max_price = 0, $min_rating = 0, $in_stock_only = false, $currency = '', $country = '', $category = '', $has_image = false, $offset = 0, $networks = [], $brand = '' ) {
        // Check if Datafeedr is enabled and credentials are set
        if ( ! $this->enabled ) {
            return new \WP_Error( 'datafeedr_disabled', __( 'Datafeedr integration is disabled.', 'aebg' ) );
        }

        if ( empty( $this->access_id ) || empty( $this->access_key ) ) {
            return new \WP_Error( 'datafeedr_credentials_missing', __( 'Datafeedr Access ID and Access Key are required.', 'aebg' ) );
        }

        error_log('[AEBG] search_advanced called with query: ' . $query);

        // Build search conditions - RESTORED advanced functionality
        $conditions = [];
        
        // Primary search condition - name contains the query
        // CRITICAL FIX: Remove quotes and wildcards based on working example
        // Format: "name LIKE robotst\u00f8vsuger" instead of "name LIKE \"%robotst\u00f8vsuger%\""
        $conditions[] = 'name LIKE ' . $this->sanitize_condition_value($query);
        
        // RESTORE advanced filters for "Add More Products" section
        // Add price filters only if they are actually set and greater than 0
        if ( $min_price > 0 ) {
            $conditions[] = 'price >= ' . intval($min_price * 100);
        }
        
        if ( $max_price > 0 ) {
            $conditions[] = 'price <= ' . intval($max_price * 100);
        }
        
        // Add rating filter
        if ( $min_rating > 0 ) {
            $conditions[] = 'rating >= ' . intval($min_rating);
        }
        
        // Add image filter - use 'image' field with !EMPTY operator
        if ( $has_image ) {
            $conditions[] = 'image !EMPTY';
        }
        
        // Add category filter
        if ( ! empty( $category ) ) {
            $conditions[] = 'category LIKE ' . $this->sanitize_condition_value( $category );
        }
        
        // Add brand filter
        if ( ! empty( $brand ) ) {
            $conditions[] = 'brand LIKE ' . $this->sanitize_condition_value( $brand );
            error_log('[AEBG] Brand filter added to API request: ' . $brand);
        }
        
        // NOTE: Currency filter removed from query - Datafeedr API returns "Invalid currency" error (405)
        // Currency filtering will be done after getting results instead
        if ( ! empty( $currency ) && $currency !== 'All Currencies' ) {
            error_log('[AEBG] Currency filter will be applied after API response: ' . $currency);
        }
        
        // Add network filter if specified
        if ( ! empty( $networks ) && is_array( $networks ) && ! in_array( 'all', $networks ) ) {
            $network_conditions = [];
            $valid_networks = [];
            
            foreach ( $networks as $network ) {
                // Map frontend network codes to valid Datafeedr source values
                $mapped_source = $this->mapNetworkToSource($network);
                if ($mapped_source) {
                    // CRITICAL FIX: Use 'source LIKE' format based on working example
                    // Format: "source LIKE Partner-Ads Denmark" instead of "network = partnerads"
                    $display_name = $this->mapSourceToDisplayName($mapped_source);
                    $network_conditions[] = 'source LIKE ' . $this->sanitize_condition_value($display_name);
                    $valid_networks[] = $network;
                } else {
                    error_log('[AEBG] Warning: Network code "' . $network . '" could not be mapped to a valid Datafeedr source');
                }
            }
            
            if ( ! empty( $network_conditions ) ) {
                // RESTORE proper network handling for multiple networks
                // If only one network, add it directly; if multiple, wrap in parentheses
                if (count($network_conditions) === 1) {
                    $conditions[] = $network_conditions[0];
                } else {
                    $conditions[] = '(' . implode( ' OR ', $network_conditions ) . ')';
                }
                error_log('[AEBG] Network filter added to API request: ' . implode( ', ', $valid_networks ));
                error_log('[AEBG] Network conditions: ' . json_encode($network_conditions));
            } else {
                error_log('[AEBG] Warning: No valid network filters could be created, proceeding without network filtering');
            }
        }

        // Prepare request data according to Datafeedr API documentation
        $request_data = [
            'aid' => $this->access_id,
            'akey' => $this->access_key,
            'query' => $conditions,
            'limit' => min($limit, 100), // Datafeedr max is 100
            'fields' => ['name', 'price', 'finalprice', 'merchant', 'source', 'brand', 'salediscount', 'url', 'currency', 'image', 'category', 'description', 'sku', 'upc', 'ean', 'isbn'],
            'string_ids' => false
        ];

        // Add offset for pagination
        if ($offset > 0) {
            $request_data['offset'] = $offset;
        }

        // Add sorting according to Datafeedr API format
        switch ( $sort_by ) {
            case 'price_asc':
                $request_data['sort'] = ['+price'];
                break;
            case 'price_desc':
                $request_data['sort'] = ['-price'];
                break;
            case 'rating_desc':
                $request_data['sort'] = ['-rating'];
                break;
            case 'name_asc':
                $request_data['sort'] = ['+name'];
                break;
            case 'relevance':
            default:
                // Default is relevance (no sort specified)
                break;
        }

        // Add duplicate exclusion
        $request_data['exclude_duplicates'] = 'merchant_id name|image';

        error_log( '[AEBG] Advanced search request: ' . json_encode( $request_data ) );
        error_log( '[AEBG] Search conditions: ' . json_encode( $conditions ) );

        // CRITICAL: Reset HTTP connections before Datafeedr API call (only for second+ jobs)
        // This is especially important for second+ jobs which may inherit stale connections
        // First job doesn't need this - it starts with clean state
        if (isset($GLOBALS['aebg_job_number']) && $GLOBALS['aebg_job_number'] > 1) {
            $this->resetHttpConnectionsBeforeRequest();
        }

        // Make the API request with retry logic and better timeout handling
        $max_retries = 2;
        $timeout = 20; // Reduced from 30 to 20 seconds
        $connect_timeout = 5; // Connection timeout
        $attempt = 0;
        $response = null;
        
        while ( $attempt <= $max_retries ) {
            $attempt++;
            
            // CRITICAL: Always use cURL directly for better timeout control and connection management
            // Never fallback to wp_remote_post() which uses WordPress HTTP API connection pool
            if ( function_exists( 'curl_init' ) && ! ini_get( 'safe_mode' ) ) {
                // CRITICAL: Add small delay before second job to ensure connections are fully closed
                // This prevents inheriting stale connections from first job
                if ( $attempt === 1 && isset( $GLOBALS['aebg_job_number'] ) && $GLOBALS['aebg_job_number'] > 1 ) {
                    usleep( 100000 ); // 0.1 second delay for second+ jobs
                    error_log( '[AEBG] Added 0.1s delay before Datafeedr API call for job #' . $GLOBALS['aebg_job_number'] );
                }
                
                $ch = curl_init( $this->api_url );
                
                if ( $ch === false ) {
                    error_log( '[AEBG] Failed to initialize cURL for Datafeedr API' );
                    // Fallback to protected API request
                    $response = $this->makeProtectedApiRequest( $this->api_url, $request_data, $timeout );
                } else {
                    $json_body = json_encode( $request_data );
                    
                    curl_setopt_array( $ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $json_body,
                        CURLOPT_HTTPHEADER => [
                            'Content-Type: application/json',
                            'User-Agent: WordPress/AEBG-Plugin/1.0.1',
                            'Connection: close', // Force connection close header
                        ],
                        CURLOPT_TIMEOUT_MS => ( $timeout * 1000 ), // Total timeout in milliseconds
                        CURLOPT_CONNECTTIMEOUT_MS => ( $connect_timeout * 1000 ), // Connection timeout
                        CURLOPT_DNS_CACHE_TIMEOUT => 0, // Disable DNS cache
                        CURLOPT_FRESH_CONNECT => true, // Force new connection
                        CURLOPT_FORBID_REUSE => true, // Don't reuse connections
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                        // CRITICAL: Additional options to prevent connection reuse
                        CURLOPT_TCP_NODELAY => true, // Disable Nagle algorithm
                        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_WHATEVER, // Allow IPv4 or IPv6
                    ] );
                    
                    $response_body = curl_exec( $ch );
                    $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
                    $curl_error = curl_error( $ch );
                    $curl_errno = curl_errno( $ch );
                    $total_time = curl_getinfo( $ch, CURLINFO_TOTAL_TIME );
                    
                    curl_close( $ch );
                    
                    // Convert cURL response to wp_remote_post format
                    if ( $response_body === false || $curl_errno !== 0 ) {
                        if ( $curl_errno === 28 ) { // CURL_TIMEOUT
                            error_log( '[AEBG] Datafeedr API timeout (attempt ' . $attempt . ' of ' . ( $max_retries + 1 ) . '): ' . $curl_error );
                            if ( $attempt <= $max_retries ) {
                                // Exponential backoff: wait 1s, 2s, 4s...
                                sleep( pow( 2, $attempt - 1 ) );
                                continue;
                            }
                            return new \WP_Error( 'datafeedr_timeout', 'Datafeedr API request timed out after ' . $timeout . ' seconds: ' . $curl_error );
                        }
                        return new \WP_Error( 'datafeedr_curl_error', 'cURL error ' . $curl_errno . ': ' . $curl_error );
                    }
                    
                    // Create response array compatible with wp_remote_post format
                    $response = [
                        'headers' => [],
                        'body' => $response_body,
                        'response' => [
                            'code' => $http_code,
                            'message' => get_status_header_desc( $http_code ),
                        ],
                        'cookies' => [],
                        'http_response' => null,
                    ];
                }
            } else {
                // Fallback to protected API request
                $response = $this->makeProtectedApiRequest( $this->api_url, $request_data, $timeout );
            }
            
            // Check if response is WP_Error (timeout or network error)
            if ( is_wp_error( $response ) ) {
                $error_code = $response->get_error_code();
                $error_message = $response->get_error_message();
                
                // Check for timeout errors
                if ( strpos( $error_message, 'timeout' ) !== false || 
                     strpos( $error_message, 'timed out' ) !== false ||
                     $error_code === 'http_request_failed' ) {
                    error_log( '[AEBG] Datafeedr API timeout (attempt ' . $attempt . ' of ' . ( $max_retries + 1 ) . '): ' . $error_message );
                    if ( $attempt <= $max_retries ) {
                        // Exponential backoff: wait 1s, 2s, 4s...
                        sleep( pow( 2, $attempt - 1 ) );
                        continue;
                    }
                    return new \WP_Error( 'datafeedr_timeout', 'Datafeedr API request timed out after ' . $timeout . ' seconds: ' . $error_message );
                }
                
                error_log( '[AEBG] Datafeedr API error: ' . $error_message );
                return $response;
            }
            
            // Success - break out of retry loop
            break;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        error_log( '[AEBG] Datafeedr API response status: ' . $status_code );
        // COMMENTED OUT: Excessive logging. Uncomment if needed for debugging.
        // error_log( '[AEBG] Datafeedr API response body: ' . $body );

        // Check for HTTP errors
        if ( $status_code !== 200 ) {
            $error_message = 'HTTP Error ' . $status_code;
            if ( isset( $data['message'] ) ) {
                $error_message .= ': ' . $data['message'];
            }
            if ( isset( $data['error'] ) ) {
                $error_message .= ' (Error: ' . $data['error'] . ')';
            }
            error_log('[AEBG] Datafeedr API error response: ' . $error_message);
            return new \WP_Error( 'datafeedr_http_error', $error_message );
        }

        // Check for API errors
        if ( isset( $data['error'] ) ) {
            $error_message = 'API Error ' . $data['error'];
            if ( isset( $data['message'] ) ) {
                $error_message .= ': ' . $data['message'];
            }
            return new \WP_Error( 'datafeedr_api_error', $error_message );
        }
        
        // Log response structure for debugging network filtering
        if (isset($data['products']) && is_array($data['products']) && count($data['products']) > 0) {
            $first_product = $data['products'][0];
            error_log('[AEBG] DEBUG: First product response structure: ' . json_encode(array_keys($first_product)));
            if (isset($first_product['source'])) {
                error_log('[AEBG] DEBUG: First product source field: ' . $first_product['source']);
            }
            if (isset($first_product['network'])) {
                error_log('[AEBG] DEBUG: First product network field: ' . $first_product['network']);
            }
            if (isset($first_product['affiliate_network'])) {
                error_log('[AEBG] DEBUG: First product affiliate_network field: ' . $first_product['affiliate_network']);
            }
        }

        // Return products if available
        if ( isset( $data['products'] ) && is_array($data['products']) ) {
            $products = $data['products'];
            error_log('[AEBG] Raw products from API: ' . count($products) . ' items');
            
            // Apply currency filter after getting results (since API doesn't support currency in query)
            if ( ! empty( $currency ) && $currency !== 'All Currencies' ) {
                $products_before_currency = count($products);
                $products = array_filter($products, function($product) use ($currency) {
                    return isset($product['currency']) && $product['currency'] === $currency;
                });
                $products_after_currency = count($products);
                error_log('[AEBG] Applied currency filter (' . $currency . ') - ' . $products_before_currency . ' products before, ' . $products_after_currency . ' after');
            }
            
            // Post-process filtering: if we requested specific networks, filter results to ensure they match
            if ( ! empty( $networks ) && is_array( $networks ) && ! in_array( 'all', $networks ) ) {
                $filtered_products = [];
                $requested_sources = [];
                
                // Get the mapped source values we requested
                foreach ($networks as $network) {
                    $mapped_source = $this->mapNetworkToSource($network);
                    if ($mapped_source) {
                        $requested_sources[] = $mapped_source;
                    }
                }
                
                error_log('[AEBG] POST-FILTER: Requested sources: ' . json_encode($requested_sources));
                
                foreach ($products as $product) {
                    $product_source = $product['source'] ?? '';
                    $product_network = $product['network'] ?? '';
                    $product_affiliate_network = $product['affiliate_network'] ?? '';
                    
                    // Check if this product matches any of our requested sources
                    $matches_source = in_array($product_source, $requested_sources) ||
                                   in_array($product_network, $requested_sources) ||
                                   in_array($product_affiliate_network, $requested_sources);
                    
                    if ($matches_source) {
                        $filtered_products[] = $product;
                    }
                }
                
                error_log('[AEBG] POST-FILTER: Original products: ' . count($products) . ', Filtered products: ' . count($filtered_products));
                if (count($filtered_products) > 0) {
                    error_log('[AEBG] POST-FILTER: Sample filtered product sources: ' . json_encode(array_slice(array_column($filtered_products, 'source'), 0, 5)));
                }
                
                $products = $filtered_products;
            }
            
            // Filter out any non-product items (merchants, networks, etc.)
            $actual_products = array_filter($data['products'], function($item) {
                // Check if this is actually a product (has required product fields)
                return is_array($item) && 
                       isset($item['name']) && 
                       !empty($item['name']) && 
                       (isset($item['price']) || isset($item['finalprice']));
            });
            
            error_log('[AEBG] Filtered actual products: ' . count($actual_products) . ' items');
            
            $formatted_products = $this->format_products( $actual_products );
            
            $total_results = $data['total_found'] ?? count($formatted_products);
            $found_count = $data['found_count'] ?? count($formatted_products);
            $length = $data['length'] ?? count($formatted_products);
            
            error_log( '[AEBG] Datafeedr search successful: found ' . $found_count . ' products (showing ' . $length . ')' );
            
            return [
                'products' => $formatted_products,
                'total' => $total_results,
                'found_count' => $found_count,
                'length' => $length,
                'offset' => $offset,
                'limit' => $limit,
                'merchants' => $data['merchants'] ?? [],
                'networks' => $data['networks'] ?? [],
                'price_groups' => $data['price_groups'] ?? [],
                'duplicates_count' => $data['duplicates_count'] ?? 0,
                'result_count' => $data['result_count'] ?? count($formatted_products)
            ];
        }

        // If no products found, return empty array
        error_log( '[AEBG] Datafeedr search completed: no products found' );
        return [
            'products' => [],
            'total' => 0,
            'found_count' => 0,
            'length' => 0,
            'offset' => $offset,
            'limit' => $limit,
            'merchants' => [],
            'networks' => [],
            'price_groups' => [],
            'duplicates_count' => 0,
            'result_count' => 0
        ];
    }

    /**
     * Convert Datafeedr price from cents to decimal
     * 
     * @deprecated Use CurrencyManager::normalizePrice() instead
     * This method is kept for backward compatibility but now delegates to CurrencyManager
     * 
     * @param mixed $price Price value from Datafeedr API or stored data
     * @param string $currency Currency code (optional, defaults to USD)
     * @return float Price in decimal format
     */
    private function convert_datafeedr_price($price, $currency = 'USD') {
        // Delegate to CurrencyManager for unified normalization
        return CurrencyManager::normalizePrice($price, $currency);
    }

    /**
     * Format products for consistent output
     * 
     * @param array $products Raw products from API
     * @return array Formatted products
     */
    private function format_products( $products ) {
        // ALWAYS log when format_products is called
        error_log('[AEBG] ===== format_products() CALLED with ' . count($products) . ' products =====');
        
        $formatted = [];
        
        // Merchants to always exclude from search results (loaded from settings)
        $settings = get_option( 'aebg_settings', [] );
        $excluded_merchant_names = [];
        
        if ( isset( $settings['excluded_merchants'] ) && ! empty( $settings['excluded_merchants'] ) ) {
            $raw_excluded = $settings['excluded_merchants'];
            
            // Allow both array and string (newline/comma-separated) formats
            if ( is_array( $raw_excluded ) ) {
                foreach ( $raw_excluded as $name ) {
                    if ( is_string( $name ) ) {
                        $name = strtolower( trim( $name ) );
                        if ( $name !== '' ) {
                            $excluded_merchant_names[] = $name;
                        }
                    }
                }
            } elseif ( is_string( $raw_excluded ) ) {
                $parts = preg_split( '/[\r\n,]+/', $raw_excluded );
                foreach ( $parts as $name ) {
                    $name = strtolower( trim( $name ) );
                    if ( $name !== '' ) {
                        $excluded_merchant_names[] = $name;
                    }
                }
            }
            
            // Ensure uniqueness
            $excluded_merchant_names = array_values( array_unique( $excluded_merchant_names ) );
        }
        
        // Fallback defaults if nothing configured
        if ( empty( $excluded_merchant_names ) ) {
            $excluded_merchant_names = [
                'ultrashop',
                'homeshop',
                'boligcenter.dk',
            ];
        }
        
        foreach ( $products as $index => $product ) {
            // Enhanced URL extraction - try multiple possible field names
            $product_url = '';
            if (!empty($product['url'])) {
                $product_url = $product['url'];
            } elseif (!empty($product['direct_url'])) {
                $product_url = $product['direct_url'];
            } elseif (!empty($product['product_url'])) {
                $product_url = $product['product_url'];
            } elseif (!empty($product['affiliate_url'])) {
                $product_url = $product['affiliate_url'];
            } elseif (!empty($product['link'])) {
                $product_url = $product['link'];
            } elseif (!empty($product['product_link'])) {
                $product_url = $product['product_link'];
            }
            
            // URLs from Datafeedr API are already correctly formatted - no fixing needed
            // Only validate to ensure they're valid URLs
            if (!empty($product_url) && !filter_var($product_url, FILTER_VALIDATE_URL)) {
                error_log('[AEBG] WARNING: Invalid URL from API: ' . $product_url);
                $product_url = ''; // Don't store invalid URLs
            }
            
            // Extract affiliate_url if it exists in API response
            $affiliate_url = '';
            if (!empty($product['affiliate_url'])) {
                $affiliate_url = $product['affiliate_url'];
                
                // Validate affiliate_url
                if (!filter_var($affiliate_url, FILTER_VALIDATE_URL)) {
                    error_log('[AEBG] WARNING: Invalid affiliate_url from API: ' . $affiliate_url);
                    $affiliate_url = $product_url; // Fallback to product_url
                }
            } else {
                $affiliate_url = $product_url; // Use product_url as fallback
            }
            
            // Extract merchant name for currency detection
            $merchant_name = $product['merchant'] ?? $product['store'] ?? '';

            // Filter out excluded merchants (case-insensitive)
            if ( ! empty( $merchant_name ) ) {
                $normalized_merchant = strtolower( trim( $merchant_name ) );
                if ( in_array( $normalized_merchant, $excluded_merchant_names, true ) ) {
                    // Skip this product entirely
                    continue;
                }
            }
            
            // Detect currency from merchant name/domain, fallback to product currency, then default to DKK for Danish sites
            $currency = $product['currency'] ?? null;
            if (empty($currency)) {
                $currency = \AEBG\Core\CurrencyManager::detectCurrency($merchant_name);
            }
            // Default to DKK for Danish sites (was USD), otherwise USD
            if (empty($currency)) {
                $currency = (preg_match('/\.dk$/i', $merchant_name)) ? 'DKK' : 'USD';
            }
            
            // Extract and map network to display name
            $raw_network = $product['source'] ?? $product['network'] ?? '';
            $network = $this->mapNetworkToDisplayName($raw_network);
            
            $formatted[] = [
                'id' => $product['_id'] ?? $product['id'] ?? '',
                'name' => $product['name'] ?? '',
                'description' => $product['description'] ?? '',
                'price' => $this->convert_datafeedr_price($product['price'] ?? 0, $currency),
                'finalprice' => $this->convert_datafeedr_price($product['finalprice'] ?? $product['price'] ?? 0, $currency),
                'currency' => $currency,
                'url' => $product_url,
                'product_url' => $product_url, // Add explicit product_url field
                'affiliate_url' => $affiliate_url, // CRITICAL: Use fixed affiliate_url, not raw from API
                'image_url' => $product['image'] ?? $product['image_url'] ?? '',
                'merchant' => $merchant_name,
                'category' => $product['category'] ?? '',
                'rating' => floatval( $product['rating'] ?? 0 ),
                'reviews_count' => intval( $product['reviews_count'] ?? 0 ),
                'availability' => $product['availability'] ?? '',
                'brand' => $product['brand'] ?? '',
                'sku' => $product['sku'] ?? '',
                'mpn' => $product['mpn'] ?? '',
                'upc' => $product['upc'] ?? '',
                'ean' => $product['ean'] ?? '',
                'isbn' => $product['isbn'] ?? '',
                'condition' => $product['condition'] ?? '',
                'shipping' => $product['shipping'] ?? '',
                'network' => $network, // Use mapped display name
                'network_id' => $product['source_id'] ?? $product['network_id'] ?? '',
                'program' => $product['program'] ?? '',
                'commission' => $product['commission'] ?? 0,
                'commission_type' => $product['commission_type'] ?? '',
                'last_updated' => $product['last_updated'] ?? '',
                'salediscount' => $product['salediscount'] ?? 0,
                'merchant_id' => $product['merchant_id'] ?? '',
                'source' => $product['source'] ?? '',
                'source_id' => $product['source_id'] ?? '',
            ];
        }
        
        return $formatted;
    }


    /**
     * Check if Datafeedr is properly configured
     * 
     * @return bool|WP_Error True if configured, WP_Error if not
     */
    public function is_configured() {
        if ( ! $this->enabled ) {
            return new \WP_Error( 'datafeedr_disabled', __( 'Datafeedr integration is disabled.', 'aebg' ) );
        }

        if ( empty( $this->access_id ) ) {
            return new \WP_Error( 'datafeedr_access_id_missing', __( 'Datafeedr Access ID is required.', 'aebg' ) );
        }

        if ( empty( $this->access_key ) ) {
            return new \WP_Error( 'datafeedr_access_key_missing', __( 'Datafeedr Access Key is required.', 'aebg' ) );
        }

        return true;
    }

    /**
     * Test Datafeedr API connection
     * 
     * @return bool|WP_Error True if successful, WP_Error if failed
     */
    public function test_connection() {
        $configured = $this->is_configured();
        if ( is_wp_error( $configured ) ) {
            return $configured;
        }

        // Test with a simple networks request as per documentation
        $result = $this->get_networks();
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return true;
    }

    /**
     * Get merchant and price information for a specific product
     * 
     * @param array $product Product data
     * @return array|WP_Error Array with merchant count, price range, and merchant info or WP_Error
     */
    /**
     * Get merchant price info for a product
     * 
     * PRODUCTION-READY: Multi-layer caching with database check first
     * 
     * @param array $product Product data
     * @return array|WP_Error Merchant price info or WP_Error
     */
    public function get_merchant_price_info( $product ) {
        $product_id = $product['id'] ?? '';
        
        if (empty($product_id)) {
            return new \WP_Error('invalid_product', __('Product ID is required.', 'aebg'));
        }
        
        // Check if Datafeedr is enabled
        if ( ! $this->enabled ) {
            return new \WP_Error( 'datafeedr_disabled', __( 'Datafeedr integration is disabled.', 'aebg' ) );
        }

        if ( empty( $this->access_id ) || empty( $this->access_key ) ) {
            return new \WP_Error( 'datafeedr_credentials_missing', __( 'Datafeedr Access ID and Access Key are required.', 'aebg' ) );
        }

        // Layer 1: Request-level cache
        static $request_cache = [];
        $cache_key = 'merchant_price_info_' . $product_id;
        
        if (isset($request_cache[$cache_key])) {
            return $request_cache[$cache_key];
        }

        // Layer 2: Check database comparison cache first
        $is_generating = (isset($GLOBALS['AEBG_GENERATION_IN_PROGRESS']) && $GLOBALS['AEBG_GENERATION_IN_PROGRESS']);
        
        if (!$is_generating) {
            $user_id = get_current_user_id();
            $post_id = get_the_ID();
            
            $comparison = \AEBG\Core\ComparisonManager::get_comparison($user_id, $product_id, $post_id);
            
            if ($comparison && !empty($comparison->comparison_data)) {
                $comparison_data = $comparison->comparison_data;
                
                // Convert comparison data to price info format
                if (is_array($comparison_data) && isset($comparison_data['merchants'])) {
                    $price_info = $this->convert_comparison_to_price_info($comparison_data);
                    
                    if ($price_info) {
                        $request_cache[$cache_key] = $price_info;
                        return $price_info;
                    }
                }
            }
        }

        // Layer 3: Continue with existing API logic (rest of method unchanged)

        // Use product identifiers to find the EXACT same product (not similar products)
        $identifiers = [];
        if ( ! empty( $product['sku'] ) ) {
            $identifiers[] = 'sku = "' . $product['sku'] . '"';
        }
        if ( ! empty( $product['mpn'] ) ) {
            $identifiers[] = 'mpn = "' . $product['mpn'] . '"';
        }
        if ( ! empty( $product['upc'] ) ) {
            $identifiers[] = 'upc = "' . $product['upc'] . '"';
        }
        if ( ! empty( $product['ean'] ) ) {
            $identifiers[] = 'ean = "' . $product['ean'] . '"';
        }
        if ( ! empty( $product['isbn'] ) ) {
            $identifiers[] = 'isbn = "' . $product['isbn'] . '"';
        }

        error_log('[AEBG] Exact identifiers found: ' . count($identifiers));

        // If no exact identifiers, try to find the exact same product by name and brand
        if ( empty( $identifiers ) && ! empty( $product['name'] ) ) {
            // Use display_name if available, otherwise use original name
            $search_name = $product['display_name'] ?? $product['name'];
            $clean_name = $this->normalize_product_name( $search_name );
            $brand = $product['brand'] ?? '';
            
            error_log('[AEBG] Building search queries - clean_name: "' . $clean_name . '", brand: "' . $brand . '"');
            
            // Start with exact name match - this is the most important condition
            $identifiers[] = 'name = "' . $this->sanitize_condition_value($clean_name) . '"';
            
            // Only add brand condition if we have a brand and it's not too long
            // (some brand names can be very long and cause issues)
            if (!empty($brand) && strlen($brand) < 50) {
                // Clean up the brand name - remove extra commas, spaces, and special characters
                $clean_brand = trim($brand, ' ,');
                $clean_brand = preg_replace('/\s*,\s*/', ' ', $clean_brand); // Replace commas with spaces
                $clean_brand = preg_replace('/\s+/', ' ', $clean_brand); // Normalize multiple spaces
                
                // Only add if the cleaned brand is still reasonable
                if (strlen($clean_brand) < 50 && !empty($clean_brand)) {
                    $identifiers[] = 'brand = "' . $this->sanitize_condition_value($clean_brand) . '"';
                    error_log('[AEBG] Added brand condition: "' . $clean_brand . '"');
                } else {
                    error_log('[AEBG] Brand too long or empty after cleaning: "' . $clean_brand . '"');
                }
            } else {
                error_log('[AEBG] Brand condition not added - brand empty or too long: "' . $brand . '"');
            }
            
            error_log('[AEBG] Final search queries: ' . json_encode($identifiers));
        }
        
        // If still no identifiers, use a very specific search with key terms
        if ( empty( $identifiers ) && ! empty( $product['name'] ) ) {
            // Use display_name if available, otherwise use original name
            $search_name = $product['display_name'] ?? $product['name'];
            $clean_name = $this->normalize_product_name( $search_name );
            $key_terms = $this->extract_key_product_terms($clean_name);
            
            if (!empty($key_terms)) {
                // Use key terms match without wildcards (API handles substring matching)
                $identifiers[] = 'name LIKE "' . $this->sanitize_condition_value(implode(' ', $key_terms)) . '"';
            } else {
                // Fallback to simple name search without wildcards
                $identifiers[] = 'name LIKE "' . $this->sanitize_condition_value($clean_name) . '"';
            }
            
            error_log('[AEBG] Using key terms search: ' . json_encode($identifiers));
        }

        // If still no conditions, try brand-based search
        if ( empty( $identifiers ) && ! empty( $product['brand'] ) ) {
            $identifiers[] = 'brand = "' . $this->sanitize_condition_value($product['brand']) . '"';
        }

        // If still no conditions, try a very broad search with just key terms
        if ( empty( $identifiers ) && ! empty( $product['name'] ) ) {
            // Use display_name if available, otherwise use original name
            $search_name = $product['display_name'] ?? $product['name'];
            $clean_name = $this->normalize_product_name( $search_name );
            $words = explode(' ', $clean_name);
            $words = array_filter($words, function($word) { return strlen($word) > 2; });
            
            if (!empty($words)) {
                $identifiers[] = 'name LIKE "' . $this->sanitize_condition_value(implode(' ', array_slice($words, 0, 3))) . '"';
            }
        }

        if ( empty( $identifiers ) ) {
            error_log('[AEBG] No search queries could be generated, returning default 1 merchant');
            $product_currency = $product['currency'] ?? CurrencyManager::getDefaultCurrency();
            $fallback_price = $this->convert_datafeedr_price($product['price'] ?? 0, $product_currency);
            return [
                'merchant_count' => 1,
                'price_range' => [
                    'lowest' => $fallback_price,
                    'highest' => $fallback_price
                ],
                'merchants' => [
                    'lowest_price' => [
                        'name' => $product['merchant'] ?? 'Unknown',
                        'price' => $fallback_price
                    ],
                    'highest_price' => [
                        'name' => $product['merchant'] ?? 'Unknown',
                        'price' => $fallback_price
                    ]
                ]
            ];
        }

        // Prepare the request data - use a smaller limit to focus on exact matches
        $request_data = [
            'aid' => $this->access_id,
            'akey' => $this->access_key,
            'query' => array_values($identifiers),
            'limit' => 50, // Reduced limit to focus on exact matches
            'fields' => [
                'id', 'name', 'brand', 'price', 'currency', 'merchant', 'url', 'image', 'category'
                // Removed: 'rating', 'reviews_count', 'availability' - these may not be valid field names
            ]
        ];

        error_log('[AEBG] Making API request with queries: ' . json_encode($identifiers));
        error_log('[AEBG] Full API request data: ' . json_encode($request_data));
        error_log('[AEBG] Request body JSON: ' . json_encode($request_data));

        // Make POST request to Datafeedr API
        $response = $this->makeProtectedApiRequest('https://api.datafeedr.com/search', $request_data, 60);

        if ( is_wp_error( $response ) ) {
            error_log('[AEBG] Datafeedr merchant price info network error: ' . $response->get_error_message());
            $product_currency = $product['currency'] ?? CurrencyManager::getDefaultCurrency();
            $fallback_price = $this->convert_datafeedr_price($product['price'] ?? 0, $product_currency);
            return [
                'merchant_count' => 1,
                'price_range' => [
                    'lowest' => $fallback_price,
                    'highest' => $fallback_price
                ],
                'merchants' => [
                    'lowest_price' => [
                        'name' => $product['merchant'] ?? 'Unknown',
                        'price' => $fallback_price
                    ],
                    'highest_price' => [
                        'name' => $product['merchant'] ?? 'Unknown',
                        'price' => $fallback_price
                    ]
                ]
            ];
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        // Only log API responses if there's an error or in verbose debug mode
        if ($status_code !== 200 || (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG)) {
            error_log('[AEBG] API response - Status: ' . $status_code . ', Body length: ' . strlen($body));
        }

        // Check for errors
        if ( $status_code !== 200 || isset( $data['error'] ) ) {
            error_log('[AEBG] Datafeedr merchant price info error: status=' . $status_code . ', error=' . ($data['error'] ?? 'none'));
            $product_currency = $product['currency'] ?? CurrencyManager::getDefaultCurrency();
            $fallback_price = $this->convert_datafeedr_price($product['price'] ?? 0, $product_currency);
            return [
                'merchant_count' => 1,
                'price_range' => [
                    'lowest' => $fallback_price,
                    'highest' => $fallback_price
                ],
                'merchants' => [
                    'lowest_price' => [
                        'name' => $product['merchant'] ?? 'Unknown',
                        'price' => $fallback_price
                    ],
                    'highest_price' => [
                        'name' => $product['merchant'] ?? 'Unknown',
                        'price' => $fallback_price
                    ]
                ]
            ];
        }

        // Collect merchant and price information for the EXACT same product
        if ( isset( $data['products'] ) && is_array($data['products']) ) {

            // Only log search details in verbose debug mode
            if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                error_log('[AEBG] Datafeedr API returned ' . count($data['products']) . ' products for merchant counting');
                error_log('[AEBG] Search queries used: ' . json_encode($identifiers));
            }
            
            $merchants = [];
            $prices = [];
            $merchant_prices = []; // To map merchant to their price for finding min/max merchants
            $merchant_price_ranges = []; // To track min/max prices for each merchant
            
            // Always include the original product in the results
            $original_merchant = $product['merchant'] ?? 'Unknown';
            $product_currency = $product['currency'] ?? CurrencyManager::getDefaultCurrency();
            $original_price = $this->convert_datafeedr_price($product['price'] ?? 0, $product_currency);
            if (!empty($original_merchant) && $original_price > 0) {
                $merchant_key = sanitize_title($original_merchant);
                $merchant_details[$merchant_key] = [
                    'name' => $original_merchant,
                    'currency' => $original_product['currency'] ?? 'USD',
                    'prices' => [$original_price],
                    'products' => [[
                        'id' => $original_product['_id'] ?? $original_product['id'] ?? '',
                        'name' => $original_product['name'] ?? '',
                        'price' => $original_price,
                        'currency' => $original_product['currency'] ?? 'USD',
                        'url' => $original_product['url'] ?? '',
                        'image_url' => $original_product['image'] ?? $original_product['image_url'] ?? '',
                        'availability' => $original_product['availability'] ?? 'in_stock',
                        'rating' => $original_product['rating'] ?? 0,
                        'reviews_count' => $original_product['reviews_count'] ?? 0,
                        'is_original' => true
                    ]],
                    'lowest_price' => $original_price,
                    'highest_price' => $original_price,
                    'average_price' => $original_price,
                    'average_rating' => $original_product['rating'] ?? 0,
                    'product_count' => 1,
                    'is_original' => true
                ];
                $prices[] = $original_price;
                // Only log in verbose debug mode
                if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                    error_log('[AEBG] Added original merchant: ' . $original_merchant);
                }
            }
            
            // Only count merchants for products that are very similar (likely the same product)
            $original_name = $this->normalize_product_name($product['display_name'] ?? $product['name'] ?? '');
            $original_brand = $product['brand'] ?? '';
            
            foreach ( $data['products'] as $index => $similar_product ) {
                $merchant = $similar_product['merchant'] ?? $similar_product['store'] ?? '';
                $similar_currency = $similar_product['currency'] ?? $product['currency'] ?? CurrencyManager::getDefaultCurrency();
                $price = $this->convert_datafeedr_price($similar_product['price'] ?? 0, $similar_currency);
                $product_name = $similar_product['name'] ?? 'Unknown';
                $product_brand = $similar_product['brand'] ?? '';
                
                // Only include if it's likely the same product (exact name match or very similar)
                $similar_name = $this->normalize_product_name($product_name);
                $is_same_product = false;
                
                // Check if it's the exact same product
                if ($similar_name === $original_name && $product_brand === $original_brand) {
                    $is_same_product = true;
                } elseif (similar_text($similar_name, $original_name) > 90) {
                    // Very similar name (90%+ similarity)
                    $is_same_product = true;
                } elseif ($product_brand === $original_brand && !empty($product_brand)) {
                    // Same brand - check for model number or key identifiers
                    $original_sku = $product['sku'] ?? '';
                    $similar_sku = $similar_product['sku'] ?? '';
                    
                    // If SKUs match, it's definitely the same product
                    if (!empty($original_sku) && !empty($similar_sku) && $original_sku === $similar_sku) {
                        $is_same_product = true;
                    } else {
                        // Check for model numbers in names (e.g., "H2B" in both names)
                        $original_words = explode(' ', strtolower($original_name));
                        $similar_words = explode(' ', strtolower($similar_name));
                        $common_words = array_intersect($original_words, $similar_words);
                        
                        // If they share key model identifiers (like "H2B"), consider them the same
                        $key_identifiers = ['h2b', 'h2', 'h3', 'h4', 'h5', 'pro', 'max', 'ultra', 'plus', 'mini'];
                        foreach ($key_identifiers as $identifier) {
                            if (in_array($identifier, $common_words)) {
                                $is_same_product = true;
                                break;
                            }
                        }
                        
                        // Also check if they're both coffee machines/related products
                        $coffee_terms = ['coffee', 'kaffe', 'espresso', 'cappuccino', 'latte', 'machine', 'maskine', 'maker'];
                        $original_has_coffee = false;
                        $similar_has_coffee = false;
                        
                        foreach ($coffee_terms as $term) {
                            if (strpos(strtolower($original_name), $term) !== false) {
                                $original_has_coffee = true;
                            }
                            if (strpos(strtolower($similar_name), $term) !== false) {
                                $similar_has_coffee = true;
                            }
                        }
                        
                        if ($original_has_coffee && $similar_has_coffee) {
                            $is_same_product = true;
                        }
                    }
                }
                
                if ( $is_same_product && ! empty( $merchant ) && $price > 0 ) {
                    $merchants[$merchant] = true;
                    $prices[] = $price;
                    
                    // Track price range for each merchant
                    if (!isset($merchant_price_ranges[$merchant])) {
                        $merchant_price_ranges[$merchant] = ['min' => $price, 'max' => $price];
                    } else {
                        $merchant_price_ranges[$merchant]['min'] = min($merchant_price_ranges[$merchant]['min'], $price);
                        $merchant_price_ranges[$merchant]['max'] = max($merchant_price_ranges[$merchant]['max'], $price);
                    }
                    
                    // Keep the lowest price for this merchant (for lowest price calculation)
                    if (!isset($merchant_prices[$merchant]) || $price < $merchant_prices[$merchant]) {
                        $merchant_prices[$merchant] = $price;
                    }
                    
                    // Merchant addition is routine - only log in detailed debug mode
                    if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                        error_log('[AEBG] Added merchant for same product: ' . $merchant . ' with price: ' . $price . ' for product: ' . $product_name);
                    }
                } else {
                    // Skipped products are routine - only log in detailed debug mode
                    if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                        $similarity_score = similar_text($similar_name, $original_name);
                        $brand_match = ($product_brand === $original_brand && !empty($product_brand)) ? 'YES' : 'NO';
                        $sku_match = (!empty($product['sku']) && !empty($similar_product['sku']) && $product['sku'] === $similar_product['sku']) ? 'YES' : 'NO';
                        error_log('[AEBG] Skipped different product: ' . $product_name . ' (merchant=' . $merchant . ', price=' . $price . ', similarity=' . $similarity_score . ', brand_match=' . $brand_match . ', sku_match=' . $sku_match . ')');
                    }
                }
            }
            
            $merchant_count = count( $merchants );
            $lowest_price = !empty($prices) ? min($prices) : ($product['price'] ?? 0);
            $highest_price = !empty($prices) ? max($prices) : ($product['price'] ?? 0);
            
            // Only log final results, not intermediate steps
            if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                error_log('[AEBG] Final merchant count: ' . $merchant_count . ', price range: ' . $lowest_price . ' - ' . $highest_price);
            }
            
            // Find merchants with lowest and highest prices
            $lowest_merchant_name = 'Unknown';
            $highest_merchant_name = 'Unknown';
            
            if (!empty($merchant_prices)) {
                // Find the merchant(s) associated with the lowest price
                $lowest_merchants_found = array_keys($merchant_prices, $lowest_price);
                $lowest_merchant_name = !empty($lowest_merchants_found) ? $lowest_merchants_found[0] : 'Unknown';

                // Find the merchant(s) associated with the highest price using price ranges
                foreach ($merchant_price_ranges as $merchant_name => $range) {
                    if ($range['max'] == $highest_price) {
                        $highest_merchant_name = $merchant_name;
                        break;
                    }
                }
            }
            
            $price_info = [
                'merchant_count' => $merchant_count,
                'price_range' => [
                    'lowest' => floatval($lowest_price),
                    'highest' => floatval($highest_price)
                ],
                'merchants' => [
                    'lowest_price' => [
                        'name' => $lowest_merchant_name,
                        'price' => floatval($lowest_price)
                    ],
                    'highest_price' => [
                        'name' => $highest_merchant_name,
                        'price' => floatval($highest_price)
                    ]
                ]
            ];
            
            // Cache the result
            $request_cache[$cache_key] = $price_info;
            
            return $price_info;
        }

        error_log('[AEBG] No products found in API response, returning default 1 merchant');
        $product_currency = $product['currency'] ?? CurrencyManager::getDefaultCurrency();
        $fallback_price = $this->convert_datafeedr_price($product['price'] ?? 0, $product_currency);
        $fallback_info = [
            'merchant_count' => 1,
            'price_range' => [
                'lowest' => $fallback_price,
                'highest' => $fallback_price
            ],
            'merchants' => [
                'lowest_price' => [
                    'name' => $product['merchant'] ?? 'Unknown',
                    'price' => $fallback_price
                ],
                'highest_price' => [
                    'name' => $product['merchant'] ?? 'Unknown',
                    'price' => $fallback_price
                ]
            ]
        ];
        
        // Cache fallback result
        $request_cache[$cache_key] = $fallback_info;
        
        return $fallback_info;
    }
    
    /**
     * Convert comparison data to price info format
     * 
     * @param array $comparison_data Comparison data with merchants
     * @return array|false Price info array or false if conversion fails
     */
    private function convert_comparison_to_price_info($comparison_data) {
        if (!is_array($comparison_data) || !isset($comparison_data['merchants']) || !is_array($comparison_data['merchants'])) {
            return false;
        }
        
        $merchants = $comparison_data['merchants'];
        if (empty($merchants)) {
            return false;
        }
        
        $prices = [];
        $merchant_names = [];
        
        foreach ($merchants as $merchant_key => $merchant) {
            if (isset($merchant['price'])) {
                $price = floatval($merchant['price']);
                $prices[] = $price;
                $merchant_names[$price] = $merchant['name'] ?? 'Unknown';
            } elseif (isset($merchant['lowest_price'])) {
                $price = floatval($merchant['lowest_price']);
                $prices[] = $price;
                $merchant_names[$price] = $merchant['name'] ?? 'Unknown';
            }
        }
        
        if (empty($prices)) {
            return false;
        }
        
        $lowest_price = min($prices);
        $highest_price = max($prices);
        $lowest_merchant_name = $merchant_names[$lowest_price] ?? 'Unknown';
        $highest_merchant_name = $merchant_names[$highest_price] ?? 'Unknown';
        
        return [
            'merchant_count' => count($merchants),
            'price_range' => [
                'lowest' => $lowest_price,
                'highest' => $highest_price
            ],
            'merchants' => [
                'lowest_price' => [
                    'name' => $lowest_merchant_name,
                    'price' => $lowest_price
                ],
                'highest_price' => [
                    'name' => $highest_merchant_name,
                    'price' => $highest_price
                ]
            ]
        ];
    }

    /**
     * Build intelligent search queries for product name variations
     * 
     * @param array $product Product data
     * @return array Array of search queries
     */
    private function build_intelligent_search_queries( $product ) {
        $queries = [];
        $product_name = trim($product['name'] ?? '');
        $brand = trim($product['brand'] ?? '');
        
        if ( empty( $product_name ) ) {
            return $queries;
        }

        // Clean and normalize the product name
        $clean_name = $this->normalize_product_name( $product_name );
        
        // Extract model number and brand (now with AI support)
        $model_info = $this->extract_model_and_brand( $clean_name, $brand );
        
        error_log('[AEBG] Model info extracted: ' . json_encode($model_info));
        
        // Use AI-generated search terms if available
        if ( ! empty( $model_info['search_terms'] ) ) {
            foreach ( $model_info['search_terms'] as $search_term ) {
                $queries[] = 'name LIKE "%' . $search_term . '%"';
            }
        }
        
        // SMART SEARCH: Generate intelligent search variations based on product structure
        $smart_queries = $this->generate_smart_search_variations($clean_name, $model_info);
        $queries = array_merge($queries, $smart_queries);
        
        // Fallback: Key product terms (simplified) - these will find similar product types
        $key_terms = $this->extract_key_product_terms($clean_name);
        foreach ($key_terms as $term) {
            if (strlen($term) > 3) { // Only use terms longer than 3 characters
                $queries[] = 'name LIKE "%' . $term . '%"';
            }
        }
        
        // Brand only (if exists) - this will find other products from the same brand
        if ( ! empty( $model_info['brand'] ) ) {
            $queries[] = 'name LIKE "%' . $model_info['brand'] . '%"';
        }
        
        // Remove duplicates and limit to maximum 10 queries to avoid API errors
        $queries = array_unique( $queries );
        $queries = array_slice($queries, 0, 10); // Limit to 10 queries
        
        error_log('[AEBG] Generated ' . count($queries) . ' smart search queries: ' . json_encode($queries));
        return $queries;
    }
    
    /**
     * Generate intelligent search variations based on product structure
     */
    private function generate_smart_search_variations($product_name, $model_info) {
        $queries = [];
        $name_lower = strtolower($product_name);
        
        // 1. Extract product components
        $components = $this->extract_product_components($product_name);
        
        // 2. Generate variations based on product type patterns
        if ($this->is_accessory_product($name_lower)) {
            $queries = array_merge($queries, $this->generate_accessory_variations($components, $model_info));
        } elseif ($this->is_electronic_device($name_lower)) {
            $queries = array_merge($queries, $this->generate_device_variations($components, $model_info));
        } else {
            $queries = array_merge($queries, $this->generate_generic_variations($components, $model_info));
        }
        
        return $queries;
    }
    
    /**
     * Extract product components (brand, model, type, etc.)
     */
    private function extract_product_components($product_name) {
        $components = [
            'full_name' => $product_name,
            'words' => explode(' ', strtolower($product_name)),
            'brand' => '',
            'model' => '',
            'type' => '',
            'variant' => '',
            'color' => ''
        ];
        
        // Common brand patterns for massage chairs
        $brands = ['IWAO', 'OGAWA', 'physa', 'DenForm', 'DOPIO'];
        
        // Extract brand
        foreach ($brands as $brand) {
            if (stripos($product_name, $brand) !== false) {
                $components['brand'] = $brand;
                break;
            }
        }
        
        // Extract model/type information for massage chairs
        if (preg_match('/(\d+D?)\s+Massagestol/i', $product_name, $matches)) {
            $components['model'] = $matches[1];
        }
        
        // Extract color
        if (preg_match('/(Sort|Grå|Brun|Beige|Espresso)/i', $product_name, $matches)) {
            $components['color'] = $matches[1];
        }
        
        // Extract type
        if (stripos($product_name, 'Massagestol') !== false) {
            $components['type'] = 'Massagestol';
        }
        
        // Extract common patterns (existing logic)
        $patterns = [
            'brand_model' => '/^([A-Z][a-z]+)\s+([A-Z0-9\/]+)/',
            'type_for_brand' => '/([A-Za-zæøå]+)\s+til\s+([A-Za-z0-9\s]+)/i',
            'brand_type_model' => '/([A-Za-zæøå]+)\s+([A-Za-zæøå]+)\s+([A-Z0-9\/]+)/i'
        ];
        
        foreach ($patterns as $pattern_name => $pattern) {
            if (preg_match($pattern, $product_name, $matches)) {
                switch ($pattern_name) {
                    case 'brand_model':
                        if (empty($components['brand'])) $components['brand'] = $matches[1];
                        if (empty($components['model'])) $components['model'] = $matches[2];
                        break;
                    case 'type_for_brand':
                        if (empty($components['type'])) $components['type'] = $matches[1];
                        if (empty($components['brand'])) $components['brand'] = $matches[2];
                        break;
                    case 'brand_type_model':
                        if (empty($components['brand'])) $components['brand'] = $matches[1];
                        if (empty($components['type'])) $components['type'] = $matches[2];
                        if (empty($components['model'])) $components['model'] = $matches[3];
                        break;
                }
            }
        }
        
        // Component extraction is routine - only log in detailed debug mode
        if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
            error_log('[AEBG] Extracted components: ' . json_encode($components));
        }
        
        return $components;
    }
    
    /**
     * Check if product is an accessory (cases, covers, protectors, etc.)
     */
    private function is_accessory_product($name_lower) {
        $accessory_keywords = [
            'beskyttelse', 'protection', 'cover', 'case', 'hylster', 'protector',
            'bagskærm', 'rear screen', 'screen protector', 'mat', 'pad',
            'adapter', 'kabel', 'cable', 'charger', 'oplader'
        ];
        
        foreach ($accessory_keywords as $keyword) {
            if (strpos($name_lower, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check if product is an electronic device
     */
    private function is_electronic_device($name_lower) {
        $device_keywords = [
            'phone', 'smartphone', 'mobile', 'mobil',
            'laptop', 'computer', 'pc', 'desktop',
            'tablet', 'ipad', 'android',
            'scooter', 'løbehjul', 'el-løbehjul',
            'robot', 'robotstøvsuger', 'vacuum'
        ];
        
        foreach ($device_keywords as $keyword) {
            if (strpos($name_lower, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Generate search variations for accessory products
     */
    private function generate_accessory_variations($components, $model_info) {
        $queries = [];
        
        // For accessories, we want to find the same accessory for different models
        if (!empty($components['type']) && !empty($components['brand'])) {
            // Pattern: "Bagskærmbeskyttelse til Xiaomi 1S/M365 el løbehjul"
            $queries[] = 'name LIKE "%' . $components['type'] . ' til ' . $components['brand'] . '%"';
            
            // Pattern: "Bagskærmbeskyttelse til Xiaomi M365 el løbehjul"
            if (!empty($model_info['model'])) {
                $queries[] = 'name LIKE "%' . $components['type'] . ' til ' . $components['brand'] . ' ' . $model_info['model'] . '%"';
            }
            
            // Pattern: "Bagskærmbeskyttelse til Xiaomi 1S el-løbehjul"
            $queries[] = 'name LIKE "%' . $components['type'] . ' til ' . $components['brand'] . ' 1S%"';
            
            // Pattern: "Bagskærmbeskyttelse til Xiaomi el løbehjul"
            $queries[] = 'name LIKE "%' . $components['type'] . ' til ' . $components['brand'] . ' el%løbehjul%"';
        }
        
        // Generic accessory search
        if (!empty($components['type'])) {
            $queries[] = 'name LIKE "%' . $components['type'] . '%"';
        }
        
        return $queries;
    }
    
    /**
     * Generate search variations for electronic devices
     */
    private function generate_device_variations($components, $model_info) {
        $queries = [];
        
        // For devices, we want to find the same model from different merchants
        if (!empty($model_info['brand']) && !empty($model_info['model'])) {
            $queries[] = 'name LIKE "%' . $model_info['brand'] . ' ' . $model_info['model'] . '%"';
            $queries[] = 'name LIKE "%' . $model_info['model'] . '%"';
        }
        
        return $queries;
    }
    
    /**
     * Generate generic search variations
     */
    private function generate_generic_variations($components, $model_info) {
        $queries = [];
        
        // Use the most important parts of the product name
        if (!empty($model_info['brand'])) {
            $queries[] = 'name LIKE "%' . $model_info['brand'] . '%"';
        }
        
        if (!empty($model_info['model'])) {
            $queries[] = 'name LIKE "%' . $model_info['model'] . '%"';
        }
        
        return $queries;
    }
    
    /**
     * Extract key product terms for broader matching
     */
    private function extract_key_product_terms($name) {
        $terms = [];
        
        // Common product keywords that indicate the type of product
        $product_keywords = [
            'mouse', 'musematte', 'musemåtte', 'pad', 'mat', 'matte',
            'robot', 'robotstøvsuger', 'vacuum', 'cleaner',
            'phone', 'smartphone', 'mobile', 'mobil',
            'laptop', 'computer', 'pc', 'desktop',
            'headphone', 'headset', 'earphone', 'øretelefon',
            'camera', 'kamera', 'photo', 'bilde',
            'watch', 'klokke', 'smartwatch', 'smartklokke',
            'tablet', 'ipad', 'android',
            'speaker', 'høyttaler', 'sound', 'lyd',
            'keyboard', 'tastatur', 'keyboard',
            'monitor', 'skjerm', 'display', 'screen',
            'scooter', 'løbehjul', 'el-løbehjul', 'electric scooter',
            'beskyttelse', 'protection', 'cover', 'case', 'hylster',
            'bagskærm', 'rear screen', 'screen protector'
        ];
        
        $name_lower = strtolower($name);
        foreach ($product_keywords as $keyword) {
            if (strpos($name_lower, $keyword) !== false) {
                $terms[] = $keyword;
            }
        }
        
        // Also extract common product categories from the name
        $words = explode(' ', $name_lower);
        foreach ($words as $word) {
            $word = trim($word, '.,!?()[]{}');
            if (strlen($word) > 4 && !in_array($word, ['til', 'for', 'med', 'den', 'det', 'der', 'som', 'har', 'kan', 'vil', 'skal'])) {
                $terms[] = $word;
            }
        }
        
        return array_unique($terms);
    }
    
    /**
     * Extract category terms for broader matching
     */
    private function extract_category_terms($name) {
        $terms = [];
        
        // Category mappings
        $categories = [
            'musematte' => ['mouse pad', 'musematte', 'musemåtte', 'mouse mat', 'desk mat'],
            'robotstøvsuger' => ['robot vacuum', 'robotstøvsuger', 'robot cleaner', 'automatic vacuum'],
            'smartphone' => ['phone', 'smartphone', 'mobile', 'mobil', 'cell phone'],
            'laptop' => ['laptop', 'computer', 'pc', 'notebook', 'portable computer'],
            'headphone' => ['headphone', 'headset', 'earphone', 'øretelefon', 'audio'],
            'camera' => ['camera', 'kamera', 'photo', 'bilde', 'photography'],
            'watch' => ['watch', 'klokke', 'smartwatch', 'smartklokke', 'timepiece'],
            'tablet' => ['tablet', 'ipad', 'android tablet', 'touchscreen'],
            'speaker' => ['speaker', 'høyttaler', 'sound', 'lyd', 'audio'],
            'keyboard' => ['keyboard', 'tastatur', 'input device'],
            'monitor' => ['monitor', 'skjerm', 'display', 'screen', 'computer screen']
        ];
        
        $name_lower = strtolower($name);
        foreach ($categories as $category => $terms_list) {
            if (strpos($name_lower, $category) !== false) {
                $terms = array_merge($terms, $terms_list);
            }
        }
        
        return array_unique($terms);
    }



    /**
     * Normalize product name for better matching
     * 
     * @param string $name Product name
     * @return string Normalized name
     */
    private function normalize_product_name( $name ) {
        // Remove common prefixes/suffixes that don't affect matching
        $name = preg_replace('/\s*\([^)]*\)\s*/', ' ', $name); // Remove parentheses content
        $name = preg_replace('/\s*\[[^\]]*\]\s*/', ' ', $name); // Remove bracket content
        $name = preg_replace('/\s*-\s*$/', '', $name); // Remove trailing dash
        $name = preg_replace('/^\s*-\s*/', '', $name); // Remove leading dash
        $name = preg_replace('/\s+/', ' ', $name); // Normalize whitespace
        return trim($name);
    }

    /**
     * Extract model number and brand from product name
     * 
     * @param string $name Product name
     * @param string $brand Brand name
     * @return array Array with 'model' and 'brand' keys
     */
    private function extract_model_and_brand( $name, $brand = '' ) {
        $result = ['model' => '', 'brand' => ''];
        
        // Try AI-powered extraction first
        $ai_result = $this->extract_model_and_brand_with_ai( $name, $brand );
        if ( ! empty( $ai_result['model'] ) || ! empty( $ai_result['brand'] ) ) {
            return $ai_result;
        }
        
        // Fallback to regex patterns if AI fails
        $result = $this->extract_model_and_brand_with_regex( $name, $brand );
        
        return $result;
    }
    
    /**
     * Use AI to intelligently extract model and brand from product name
     */
    private function extract_model_and_brand_with_ai( $name, $brand = '' ) {
        $result = ['model' => '', 'brand' => ''];
        
        try {
            // Get OpenAI API key
            $openai_api_key = get_option( 'aebg_openai_api_key' );
            if ( empty( $openai_api_key ) ) {
                return $result; // Fallback to regex
            }
            
            // Check cache first to avoid repeated API calls
            $cache_key = 'aebg_ai_extraction_' . md5($name . $brand);
            $cached_result = get_transient($cache_key);
            if ($cached_result !== false) {
                error_log('[AEBG] Using cached AI extraction for "' . $name . '"');
                return $cached_result;
            }
            
            // Prepare the prompt for AI analysis
            $prompt = "Analyze this product name and extract the most searchable model identifier and brand for finding similar products across different merchants. Focus on the core model number/name that would be consistent across different sellers.

Product Name: \"{$name}\"
Brand (if known): \"{$brand}\"

Please respond with ONLY a JSON object in this exact format:
{
    \"model\": \"the most searchable model identifier\",
    \"brand\": \"the brand name\",
    \"search_terms\": [\"array of search terms to find this product\"]
}

Examples:
- For \"Ezviz RE4 Robotstøvsuger Hvit\" → {\"model\": \"RE4\", \"brand\": \"EZVIZ\", \"search_terms\": [\"RE4\", \"EZVIZ RE4\", \"RE4 Robotstøvsuger\"]}
- For \"Neatsvor X650 Pro robotstøvsuger\" → {\"model\": \"X650 Pro\", \"brand\": \"Neatsvor\", \"search_terms\": [\"X650 Pro\", \"Neatsvor X650\", \"X650\"]}
- For \"Samsung Galaxy S23 Ultra 5G\" → {\"model\": \"S23 Ultra\", \"brand\": \"Samsung\", \"search_terms\": [\"S23 Ultra\", \"Galaxy S23\", \"S23\"]}
- For \"Apple iPhone 14 Pro Max\" → {\"model\": \"iPhone 14 Pro Max\", \"brand\": \"Apple\", \"search_terms\": [\"iPhone 14 Pro Max\", \"iPhone 14 Pro\", \"14 Pro Max\"]}

Focus on extracting the most specific yet searchable model identifier that would help find the same product across different merchants.";

            // ULTRA-ROBUST: Use APIClient::makeRequest() for ultra-robust timeout handling
            $request_body = [
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a product analysis expert. Extract the most searchable model identifier and brand from product names for e-commerce search purposes.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => 200,
                'temperature' => 0.1, // Low temperature for consistent results
            ];
            
            $data = \AEBG\Core\APIClient::makeRequest(
                'https://api.openai.com/v1/chat/completions',
                $openai_api_key,
                $request_body,
                15,
                3
            );

            if ( is_wp_error( $data ) ) {
                error_log( '[AEBG] AI extraction failed: ' . $data->get_error_message() );
                return $result;
            }

            if ( empty( $data ) || !is_array( $data ) ) {
                error_log( '[AEBG] AI extraction returned empty or invalid response' );
                return $result;
            }

            if ( ! empty( $data['choices'][0]['message']['content'] ) ) {
                $ai_response = trim( $data['choices'][0]['message']['content'] );
                
                // Try to extract JSON from the response
                if ( preg_match( '/\{.*\}/s', $ai_response, $matches ) ) {
                    $json_data = json_decode( $matches[0], true );
                    
                                            if ( $json_data && isset( $json_data['model'] ) ) {
                            $result['model'] = trim( $json_data['model'] );
                            $result['brand'] = trim( $json_data['brand'] ?? '' );
                            
                            // Store search terms for enhanced searching
                            if ( ! empty( $json_data['search_terms'] ) ) {
                                $result['search_terms'] = $json_data['search_terms'];
                            }
                            
                            // Cache the result for 24 hours to avoid repeated API calls
                            set_transient($cache_key, $result, 24 * HOUR_IN_SECONDS);
                            
                            error_log( '[AEBG] AI extraction successful for "' . $name . '": ' . json_encode( $result ) );
                            return $result;
                        }
                }
            }
            
        } catch ( Exception $e ) {
            error_log( '[AEBG] AI extraction error: ' . $e->getMessage() );
        }
        
        return $result; // Fallback to regex
    }
    
    /**
     * Fallback regex-based extraction
     */
    private function extract_model_and_brand_with_regex( $name, $brand = '' ) {
        $result = ['model' => '', 'brand' => ''];
        
        // Generic model patterns that work for ANY product type
        $model_patterns = [
            '/\b([A-Z]{2,}\s*\d+[A-Z]*)\b/', // EZVIZ RE4, Neatsvor X650, Samsung QLED, etc.
            '/\b([A-Z]+\s*\d+[A-Z]*\s*Pro)\b/', // X650 Pro, iPhone 14 Pro, etc.
            '/\b([A-Z]+\s*\d+[A-Z]*\s*Plus)\b/', // X650 Plus, iPhone 14 Plus, etc.
            '/\b([A-Z]+\s*\d+[A-Z]*\s*Max)\b/', // X650 Max, iPhone 14 Max, etc.
            '/\b([A-Z]+\s*\d+[A-Z]*\s*Ultra)\b/', // Galaxy S23 Ultra, etc.
            '/\b([A-Z]+\s*\d+[A-Z]*\s*Mini)\b/', // iPhone 13 Mini, etc.
            '/\b([A-Z]+\s*\d+[A-Z]*\s*SE)\b/', // iPhone SE, etc.
            '/\b([A-Z]+\s*\d+[A-Z]*\s*Air)\b/', // MacBook Air, iPad Air, etc.
            '/\b([A-Z]+\s*\d+[A-Z]*\s*Pro)\b/', // MacBook Pro, iPad Pro, etc.
            '/\b([A-Z]+\s*\d+[A-Z]*)\b/', // Generic pattern for any model
        ];
        
        foreach ( $model_patterns as $pattern ) {
            if ( preg_match( $pattern, $name, $matches ) ) {
                $result['model'] = trim($matches[1]);
                break;
            }
        }
        
        // If no model found, try to extract any product identifier
        if ( empty( $result['model'] ) ) {
            // Look for any combination of letters and numbers that might be a model
            if ( preg_match( '/\b([A-Z]{2,}[0-9]+[A-Z]*)\b/', $name, $matches ) ) {
                $result['model'] = trim($matches[1]);
            }
        }
        
        // Extract brand
        if ( ! empty( $brand ) ) {
            $result['brand'] = trim($brand);
        } else {
            // Try to extract brand from name
            $brand_patterns = [
                '/^([A-Z]{2,})\s/', // EZVIZ, Neatsvor, Samsung, Apple, etc.
                '/\b([A-Z]{2,})\s*\d/', // Brand before model
                '/\b([A-Z]{2,})\s*[A-Z]/', // Brand before any letter sequence
            ];
            
            foreach ( $brand_patterns as $pattern ) {
                if ( preg_match( $pattern, $name, $matches ) ) {
                    $result['brand'] = trim($matches[1]);
                    break;
                }
            }
        }
        
        return $result;
    }

    /**
     * Get networks (for testing connection)
     * 
     * @return array|WP_Error Networks array or WP_Error
     */
    public function get_networks() {
        if ( ! $this->enabled || empty( $this->access_id ) || empty( $this->access_key ) ) {
            return new \WP_Error( 'datafeedr_not_configured', __( 'Datafeedr is not properly configured.', 'aebg' ) );
        }

        $request_data = [
            'aid' => $this->access_id,
            'akey' => $this->access_key,
        ];

        $response = $this->makeProtectedApiRequest('https://api.datafeedr.com/networks', $request_data, 60);

        if ( is_wp_error( $response ) ) {
            return new \WP_Error( 'datafeedr_network_error', 'Network error: ' . $response->get_error_message() );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $status_code !== 200 ) {
            $error_message = 'HTTP Error ' . $status_code;
            if ( isset( $data['message'] ) ) {
                $error_message .= ': ' . $data['message'];
            }
            return new \WP_Error( 'datafeedr_http_error', $error_message );
        }

        if ( isset( $data['error'] ) ) {
            $error_message = 'API Error ' . $data['error'];
            if ( isset( $data['message'] ) ) {
                $error_message .= ': ' . $data['message'];
            }
            return new \WP_Error( 'datafeedr_api_error', $error_message );
        }

        // Log the response structure for debugging
        error_log('[AEBG Datafeedr] Networks API response keys: ' . json_encode(array_keys($data ?? [])));
        
        // Try different possible response formats
        if (isset($data['networks']) && is_array($data['networks'])) {
            error_log('[AEBG Datafeedr] Found ' . count($data['networks']) . ' networks in response');
            return $data['networks'];
        }
        
        // Some APIs return 'sources' instead of 'networks'
        if (isset($data['sources']) && is_array($data['sources'])) {
            error_log('[AEBG Datafeedr] Found ' . count($data['sources']) . ' sources in response');
            return $data['sources'];
        }
        
        // If the response itself is an array, return it
        if (is_array($data) && !isset($data['error']) && !isset($data['message'])) {
            error_log('[AEBG Datafeedr] Response is array with ' . count($data) . ' items');
            return $data;
        }
        
        error_log('[AEBG Datafeedr] No networks found in API response. Response: ' . json_encode($data));
        return [];
    }

    /**
     * Get comprehensive merchant comparison data for a product
     * 
     * PRODUCTION-READY: Multi-layer caching with intelligent cache invalidation
     * 
     * @param array $product Product data
     * @param int $limit Maximum number of merchants to return
     * @param bool $force_refresh Force fresh API call (default: false)
     * @return array|WP_Error Merchant comparison data or WP_Error
     */
    public function get_merchant_comparison($product, $limit = 20, $force_refresh = false) {
        // Validate inputs
        if (!$this->enabled) {
            return new \WP_Error('datafeedr_disabled', __('Datafeedr integration is disabled.', 'aebg'));
        }

        if (empty($this->access_id) || empty($this->access_key)) {
            return new \WP_Error('datafeedr_credentials_missing', __('Datafeedr Access ID and Access Key are required.', 'aebg'));
        }

        $product_id = $product['id'] ?? '';
        if (empty($product_id)) {
            return new \WP_Error('invalid_product', __('Product ID is required.', 'aebg'));
        }

        // Check if we're in generation mode (skip database lookup to avoid hangs)
        $is_generating = (isset($GLOBALS['AEBG_GENERATION_IN_PROGRESS']) && $GLOBALS['AEBG_GENERATION_IN_PROGRESS']);
        
        // Layer 1: Request-level static cache (fastest, same request)
        static $request_cache = [];
        $request_cache_key = 'merchant_comparison_' . md5($product_id . '_' . $limit);
        
        // Define WordPress cache key early so it's available throughout the function
        $wp_cache_key = 'aebg_merchant_comparison_' . md5($product_id . '_' . $limit);
        
        if (!$force_refresh && isset($request_cache[$request_cache_key])) {
            Logger::debug('get_merchant_comparison: Request cache HIT for product: ' . $product_id);
            return $request_cache[$request_cache_key];
        }

        // Layer 2: WordPress object cache (cross-request, 15 minutes)
        if (!$force_refresh && !$is_generating) {
            $cached_data = wp_cache_get($wp_cache_key, 'aebg_merchants');
            
            if ($cached_data !== false && is_array($cached_data)) {
                Logger::debug('get_merchant_comparison: Object cache HIT for product: ' . $product_id);
                $request_cache[$request_cache_key] = $cached_data;
                return $cached_data;
            }
        }

        // Layer 3: Database comparison cache (persistent, user-specific)
        if (!$force_refresh && !$is_generating) {
            $user_id = get_current_user_id();
            $post_id = get_the_ID();
            
            $comparison = \AEBG\Core\ComparisonManager::get_comparison($user_id, $product_id, $post_id);
            
            if ($comparison && !empty($comparison->comparison_data)) {
                $comparison_data = $comparison->comparison_data;
                
                // Validate comparison data structure
                if (is_array($comparison_data) && 
                    isset($comparison_data['merchants']) && 
                    is_array($comparison_data['merchants']) &&
                    !empty($comparison_data['merchants'])) {
                    
                    Logger::debug('get_merchant_comparison: Database cache HIT for product: ' . $product_id . ' (' . count($comparison_data['merchants']) . ' merchants)');
                    
                    // Cache in upper layers for faster future access
                    $request_cache[$request_cache_key] = $comparison_data;
                    wp_cache_set($wp_cache_key, $comparison_data, 'aebg_merchants', 15 * MINUTE_IN_SECONDS);
                    
                    return $comparison_data;
                }
            }
        }

        // Layer 4: Transient cache (30-day merchant cache)
        if (!$force_refresh) {
            $merchant_cache_data = \AEBG\Core\MerchantCache::get($product_id);
            
            if ($merchant_cache_data !== null && is_array($merchant_cache_data)) {
                Logger::debug('get_merchant_comparison: Transient cache HIT for product: ' . $product_id);
                
                // Cache in upper layers
                $request_cache[$request_cache_key] = $merchant_cache_data;
                wp_cache_set($wp_cache_key, $merchant_cache_data, 'aebg_merchants', 15 * MINUTE_IN_SECONDS);
                
                // Optionally save to database for user-specific access
                if (!$is_generating) {
                    $user_id = get_current_user_id();
                    $post_id = get_the_ID();
                    \AEBG\Core\ComparisonManager::save_comparison(
                        $user_id,
                        $post_id,
                        $product_id,
                        'Merchant Comparison',
                        $merchant_cache_data
                    );
                }
                
                return $merchant_cache_data;
            }
        }

        // Layer 5: API call (last resort or force refresh)
        Logger::debug('get_merchant_comparison: All caches MISS, calling API for product: ' . $product_id);

        // Enhance product data with database data if available (for better search conditions)
        $needs_enhancement = empty($product['sku']) && empty($product['upc']) && empty($product['ean']) && empty($product['brand']);
        
        if (!empty($product_id) && ($needs_enhancement || !$is_generating)) {
            $db_product_data = $this->get_product_data_from_database($product_id);
            if ($db_product_data && is_array($db_product_data)) {
                $product = array_merge($product, $db_product_data);
            }
        }

        // Build search conditions
        $conditions = $this->build_merchant_search_conditions($product);
        
        if (empty($conditions)) {
            Logger::warning('get_merchant_comparison: No search conditions for product: ' . $product_id);
            $fallback_data = $this->get_fallback_merchant_data($product);
            
            // Cache fallback data to prevent repeated failed lookups
            $request_cache[$request_cache_key] = $fallback_data;
            wp_cache_set($wp_cache_key, $fallback_data, 'aebg_merchants', 5 * MINUTE_IN_SECONDS);
            
            return $fallback_data;
        }

        // Prepare request data according to Datafeedr API documentation
        $request_data = [
            'aid' => $this->access_id,
            'akey' => $this->access_key,
            'query' => $conditions,
            'limit' => min($limit * 3, 200), // Get more results to ensure merchant diversity
            'fields' => ['id', 'name', 'price', 'currency', 'direct_url', 'image', 'merchant', 'brand', 'sku', 'upc', 'ean', 'isbn', 'description', 'category', 'thumbnail', 'network']
        ];

        // error_log('[AEBG] Making merchant comparison API request with conditions: ' . json_encode($conditions));
        // error_log('[AEBG] Request data: ' . json_encode($request_data));

        // CRITICAL: Ensure database connection is healthy before making API request
        // This prevents hangs from stale database connections, especially on second+ articles
        global $wpdb;
        if ($wpdb && isset($wpdb->dbh)) {
            // Quick health check - ping the database connection
            if ($wpdb->dbh instanceof \mysqli) {
                if (!$wpdb->dbh->ping()) {
                    Logger::warning('get_merchant_comparison: Database connection is dead, reconnecting...');
                    if (method_exists($wpdb, 'db_connect')) {
                        $wpdb->db_connect();
                    }
                }
            }
            // Flush any pending queries to prevent "Commands out of sync" errors
            $wpdb->flush();
        }
        
        // Make POST request to Datafeedr API with aggressive timeout to prevent hanging
        // CRITICAL: Use shorter timeout to prevent blocking the entire generation process
        // CRITICAL: Use protected API request to prevent WordPress hooks from triggering
        Logger::debug('get_merchant_comparison: About to call makeProtectedApiRequest for product: ' . $product_id);
        $response = $this->makeProtectedApiRequest('https://api.datafeedr.com/search', $request_data, 10);
        Logger::debug('get_merchant_comparison: makeProtectedApiRequest returned for product: ' . $product_id);
        
        // Handle API response
        if (is_wp_error($response)) {
            Logger::error('get_merchant_comparison: API error for product: ' . $product_id . ' - ' . $response->get_error_message());
            
            // Try to return cached data even if expired (better than nothing)
            $stale_cache = \AEBG\Core\MerchantCache::get($product_id);
            if ($stale_cache !== null) {
                Logger::info('get_merchant_comparison: Returning stale cache due to API error for product: ' . $product_id);
                return $stale_cache;
            }
            
            $fallback_data = $this->get_fallback_merchant_data($product);
            $request_cache[$request_cache_key] = $fallback_data;
            return $fallback_data;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Only log API responses if there's an error or in verbose debug mode
        if ($status_code !== 200 || (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG)) {
            error_log('[AEBG] API response - Status: ' . $status_code . ', Body length: ' . strlen($body));
        }

        // Handle API errors
        if ($status_code !== 200 || isset($data['error'])) {
            Logger::error('get_merchant_comparison: API returned error for product: ' . $product_id);
            
            // Try stale cache
            $stale_cache = \AEBG\Core\MerchantCache::get($product_id);
            if ($stale_cache !== null) {
                return $stale_cache;
            }
            
            $fallback_data = $this->get_fallback_merchant_data($product);
            
            // Save fallback to database for future use
            if (!empty($product_id) && !$is_generating) {
                $user_id = get_current_user_id();
                $post_id = get_the_ID();
                try {
                    \AEBG\Core\ComparisonManager::save_comparison(
                        $user_id,
                        $post_id,
                        $product_id,
                        'Merchant Comparison',
                        $fallback_data
                    );
                } catch (\Exception $e) {
                    Logger::warning('get_merchant_comparison: Failed to save fallback data: ' . $e->getMessage());
                }
            }
            
            $request_cache[$request_cache_key] = $fallback_data;
            return $fallback_data;
        }

        // Process the response according to Datafeedr API structure
        if (isset($data['products']) && is_array($data['products'])) {
            // error_log('[AEBG] Found ' . count($data['products']) . ' raw items in merchant comparison API response');
            // error_log('[AEBG] Raw products sample: ' . json_encode(array_slice($data['products'], 0, 3)));
            
            // Filter out any non-product items (merchants, networks, etc.)
            $actual_products = array_filter($data['products'], function($item) {
                // Check if this is actually a product (has required product fields)
                return is_array($item) && 
                       isset($item['name']) && 
                       !empty($item['name']) && 
                       (isset($item['price']) || isset($item['finalprice']));
            });
            
            Logger::debug('Filtered actual products for merchant comparison: ' . count($actual_products) . ' items');
            
            // CRITICAL: Add timeout protection and error handling around format_merchant_comparison_data
            // This method can hang if processing very large arrays
            $format_start = microtime(true);
            try {
                Logger::debug('Calling format_merchant_comparison_data with ' . count($actual_products) . ' products, limit: ' . $limit);
                $merchant_data = $this->format_merchant_comparison_data($actual_products, $product, $limit);
                $format_elapsed = microtime(true) - $format_start;
                
                if ($format_elapsed > 3) {
                    Logger::warning('format_merchant_comparison_data took ' . round($format_elapsed, 2) . ' seconds (slow processing)');
                } else {
                    Logger::debug('format_merchant_comparison_data completed in ' . round($format_elapsed, 2) . ' seconds');
                }
                
                // Cache in ALL layers for future requests
                $request_cache[$request_cache_key] = $merchant_data;
                wp_cache_set($wp_cache_key, $merchant_data, 'aebg_merchants', 15 * MINUTE_IN_SECONDS);
                \AEBG\Core\MerchantCache::set($product_id, $merchant_data);
                
                // Save to database for user-specific access
                if (!empty($product_id) && !$is_generating) {
                    $user_id = get_current_user_id();
                    $post_id = get_the_ID();
                    try {
                        \AEBG\Core\ComparisonManager::save_comparison(
                            $user_id,
                            $post_id,
                            $product_id,
                            'Merchant Comparison',
                            $merchant_data
                        );
                    } catch (\Exception $e) {
                        Logger::warning('get_merchant_comparison: Failed to save to database: ' . $e->getMessage());
                    }
                }
                
                Logger::debug('get_merchant_comparison: Successfully fetched and cached merchant data for product: ' . $product_id);
                return $merchant_data;
            } catch (\Exception $e) {
                Logger::error('get_merchant_comparison: Exception formatting data: ' . $e->getMessage());
                $fallback_data = $this->get_fallback_merchant_data($product);
                $request_cache[$request_cache_key] = $fallback_data;
                return $fallback_data;
            }
        }

        // No products found
        Logger::warning('get_merchant_comparison: No products in API response for product: ' . $product_id);
        $fallback_data = $this->get_fallback_merchant_data($product);
        $request_cache[$request_cache_key] = $fallback_data;
        wp_cache_set($wp_cache_key, $fallback_data, 'aebg_merchants', 5 * MINUTE_IN_SECONDS);
        
        return $fallback_data;
    }

    /**
     * Build search conditions for merchant comparison
     * 
     * @param array $product Product data
     * @return array Search conditions
     */
    private function build_merchant_search_conditions($product) {
        $conditions = [];
        
        // error_log('[AEBG] Building merchant search conditions for product: ' . json_encode($product));
        
        // Try exact identifiers first (most accurate)
        if (!empty($product['sku'])) {
            $conditions[] = 'sku = "' . $this->sanitize_condition_value($product['sku']) . '"';
            // Only log in verbose debug mode
            if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                error_log('[AEBG] Added SKU condition: sku = "' . $product['sku'] . '"');
            }
        }
        if (!empty($product['mpn'])) {
            $conditions[] = 'mpn = "' . $this->sanitize_condition_value($product['mpn']) . '"';
            // Only log in verbose debug mode
            if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                error_log('[AEBG] Added MPN condition: mpn = "' . $product['mpn'] . '"');
            }
        }
        if (!empty($product['upc'])) {
            $conditions[] = 'upc = "' . $this->sanitize_condition_value($product['upc']) . '"';
            // Only log in verbose debug mode
            if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                error_log('[AEBG] Added UPC condition: upc = "' . $product['upc'] . '"');
            }
        }
        if (!empty($product['ean'])) {
            $conditions[] = 'ean = "' . $this->sanitize_condition_value($product['ean']) . '"';
        }
        if (!empty($product['isbn'])) {
            $conditions[] = 'isbn = "' . $this->sanitize_condition_value($product['isbn']) . '"';
            // Only log in verbose debug mode
            if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                error_log('[AEBG] Added ISBN condition: isbn = "' . $product['isbn'] . '"');
            }
        }

        // If no exact identifiers, use more precise name-based search
        if (empty($conditions) && !empty($product['name'])) {
            // Only log in verbose debug mode
            if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                error_log('[AEBG] No exact identifiers found, using precise name-based search');
            }
            $conditions = $this->build_precise_name_search_conditions($product['name'], $product['brand'] ?? '');
        }

        // If still no conditions, try brand-based search with model
        if (empty($conditions) && !empty($product['brand'])) {
            $model = $this->extract_model_from_name($product['name'] ?? '');
            if (!empty($model)) {
                $conditions[] = 'brand = "' . $this->sanitize_condition_value($product['brand']) . '"';
                $conditions[] = 'name LIKE "%' . $this->sanitize_condition_value($model) . '%"';
                // Only log in verbose debug mode
                if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                    error_log('[AEBG] Added brand + model conditions: brand = "' . $product['brand'] . '", name LIKE "%' . $model . '%"');
                }
            } else {
                $conditions[] = 'brand = "' . $this->sanitize_condition_value($product['brand']) . '"';
                // Only log in verbose debug mode
                if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                    error_log('[AEBG] Added brand condition: brand = "' . $product['brand'] . '"');
                }
            }
        }

        // NOTE: Currency filter removed from query - Datafeedr API returns "Invalid currency" error (405)
        // Currency filtering will be done after getting results instead
        $settings = get_option('aebg_settings', []);
        $default_currency = $settings['default_currency'] ?? 'USD';
        if (!empty($default_currency) && $default_currency !== 'All Currencies') {
            // error_log('[AEBG] Currency filter will be applied after API response for merchant comparison: ' . $default_currency);
        }

        // If still no conditions, use a very specific search with exact product name
        if (empty($conditions) && !empty($product['name'])) {
            error_log('[AEBG] No conditions found, using exact product name search');
            $exact_name = $this->get_exact_product_name_for_search($product['name']);
            if (!empty($exact_name)) {
                $conditions[] = 'name = "' . $this->sanitize_condition_value($exact_name) . '"';
                error_log('[AEBG] Added exact name condition: name = "' . $exact_name . '"');
            }
        }

        // error_log('[AEBG] Final merchant search conditions: ' . json_encode($conditions));
        
        return $conditions;
    }

    /**
     * Build precise name search conditions based on product name and brand
     * 
     * @param string $product_name Product name
     * @param string $brand Product brand
     * @return array Search conditions
     */
    private function build_precise_name_search_conditions($product_name, $brand = '') {
        $conditions = [];
        $clean_name = $this->normalize_product_name($product_name);
        
        // Extract key components
        $components = $this->extract_product_components($clean_name);
        
        if (!empty($components['brand']) && !empty($components['model'])) {
            // Brand + Model search - use exact matching for better precision
            $conditions[] = 'brand = "' . $this->sanitize_condition_value($components['brand']) . '"';
            $conditions[] = 'name LIKE "%' . $this->sanitize_condition_value($components['model']) . '%"';
            // Only log in verbose debug mode
            if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                error_log('[AEBG] Added precise brand + model conditions: brand = "' . $components['brand'] . '", name LIKE "%' . $components['model'] . '%"');
            }
        } elseif (!empty($components['brand'])) {
            // Brand only search with exact brand matching
            $conditions[] = 'brand = "' . $this->sanitize_condition_value($components['brand']) . '"';
            // Only log in verbose debug mode
            if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                error_log('[AEBG] Added precise brand condition: brand = "' . $components['brand'] . '"');
            }
        } elseif (!empty($components['model'])) {
            // Model only search with exact model matching
            $conditions[] = 'name LIKE "%' . $this->sanitize_condition_value($components['model']) . '%"';
            // Only log in verbose debug mode
            if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                error_log('[AEBG] Added precise model condition: name LIKE "%' . $components['model'] . '%"');
            }
        } else {
            // Use exact product name if no components found
            $exact_name = $this->get_exact_product_name_for_search($clean_name);
            if (!empty($exact_name)) {
                $conditions[] = 'name = "' . $this->sanitize_condition_value($exact_name) . '"';
                // Only log in verbose debug mode
                if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
                    error_log('[AEBG] Added exact name condition: name = "' . $exact_name . '"');
                }
            }
        }

        return $conditions;
    }

    /**
     * Extract model from product name
     * 
     * @param string $product_name Product name
     * @return string Model or empty string
     */
    private function extract_model_from_name($product_name) {
        if (empty($product_name)) {
            return '';
        }
        
        // Common model patterns (e.g., G1, G2, G3, iPhone 14, etc.)
        $patterns = [
            '/\b([A-Z]\d+)\b/',           // G1, G2, G3, etc.
            '/\b([A-Z]+\s+\d+)\b/',       // iPhone 14, Samsung Galaxy S23, etc.
            '/\b(\d{4})\b/',              // 2023, 2024, etc.
            '/\b([A-Z]{2,}\d{2,})\b/',   // XPS13, ThinkPad T14, etc.
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $product_name, $matches)) {
                return trim($matches[1]);
            }
        }
        
        return '';
    }

    /**
     * Get exact product name for search (remove common variations)
     * 
     * @param string $product_name Product name
     * @return string Exact product name for search
     */
    private function get_exact_product_name_for_search($product_name) {
        if (empty($product_name)) {
            return '';
        }
        
        // Remove common variations that don't affect product identity
        $exact_name = $product_name;
        
        // Remove color variations
        $exact_name = preg_replace('/\s*-\s*(Hvid|Rosa|Sort|Blå|Grøn|Gul|Orange|Rød|Lilla|Grå|Hvid|Pink|Black|White|Blue|Green|Yellow|Orange|Red|Purple|Gray|Grey)\s*$/i', '', $exact_name);
        
        // Remove "Bedst i test" and similar marketing text
        $exact_name = preg_replace('/\s*-\s*Bedst\s+i\s+test\s+\d{4}\s*$/i', '', $exact_name);
        $exact_name = preg_replace('/\s*-\s*Best\s+in\s+test\s+\d{4}\s*$/i', '', $exact_name);
        
        // Remove trailing dashes and spaces
        $exact_name = trim($exact_name, ' -');
        
        return $exact_name;
    }

    /**
     * Sanitize condition value for Datafeedr API
     * 
     * @param string $value Value to sanitize
     * @return string Sanitized value
     */
    private function sanitize_condition_value($value) {
        // Remove only characters that could break the SQL query, but preserve international characters
        // Allow letters (including international), numbers, spaces, hyphens, dots, and common punctuation
        $value = preg_replace('/[^\p{L}\p{N}\s\-\.\,\!\?]/u', '', $value);
        return trim($value);
    }
    
    /**
     * Map frontend network codes to valid Datafeedr API source values
     * 
     * @param string $network_code Frontend network code (e.g., 'amazon_us', 'partner_ads')
     * @return string|null Valid Datafeedr source value or null if not mappable
     */
    public function mapNetworkToSource($network_code) {
        // Map frontend network codes to valid Datafeedr source values
        // These should match the actual source values used by Datafeedr API
        $network_mapping = [
            'amazon_us' => 'amazon',
            'amazon_uk' => 'amazon',
            'partner_ads' => 'partnerads', // Partner Ads Denmark - use 'partnerads' for API
            'partner_ads_dk' => 'partnerads', // Partner Ads Denmark (DK variant) - use 'partnerads' for API
            'api_15' => 'partnerads', // Partner Ads Denmark - use 'partnerads' for API
            'timeone' => 'timeone',
            'adrecord' => 'adrecord',
            'addrevenue' => 'addrevenue',
            'zanox' => 'zanox',
            'partnerize' => 'partnerize',
            'performance_horizon' => 'performance_horizon'
        ];
        
        // error_log('[AEBG] NETWORK MAPPING: Mapping "' . $network_code . '" to "' . ($network_mapping[$network_code] ?? 'null') . '"');
        return $network_mapping[$network_code] ?? null;
    }

    /**
     * Map internal source codes to proper display names for API queries
     * 
     * @param string $source_code Internal source code (e.g., 'partnerads', 'awin')
     * @return string Proper display name for API queries
     */
    public function mapSourceToDisplayName($source_code) {
        // Map internal source codes to proper display names used in API queries
        $display_mapping = [
            'partnerads' => 'Partner-Ads Denmark',
            'awin' => 'Awin Denmark',
            'timeone' => 'TimeOne',
            'adrecord' => 'Adrecord',
            'addrevenue' => 'AddRevenue',
            'zanox' => 'Zanox',
            'partnerize' => 'Partnerize',
            'performance_horizon' => 'Performance Horizon',
            'amazon' => 'Amazon'
        ];
        
        return $display_mapping[$source_code] ?? $source_code;
    }
    
    /**
     * Map network code to display name for product display
     * Handles various network formats from Datafeedr API
     * 
     * @param string $raw_network Raw network value from API (e.g., 'Partner-Ads Denmark', 'api_15', 'partnerads')
     * @return string Display name for the network
     */
    private function mapNetworkToDisplayName($raw_network) {
        if (empty($raw_network)) {
            return '';
        }
        
        $network_lower = strtolower(trim($raw_network));
        
        // Direct display name mappings
        $display_mapping = [
            // Partner Ads variations
            'partner-ads denmark' => 'Partner-Ads Denmark',
            'partner ads denmark' => 'Partner-Ads Denmark',
            'partner-ads' => 'Partner-Ads Denmark',
            'partnerads' => 'Partner-Ads Denmark',
            'partner_ads' => 'Partner-Ads Denmark',
            'partner_ads_dk' => 'Partner-Ads Denmark',
            'api_15' => 'Partner-Ads Denmark',
            
            // Awin variations
            'awin denmark' => 'Awin Denmark',
            'awin' => 'Awin Denmark',
            
            // Other networks
            'timeone' => 'TimeOne',
            'adrecord' => 'Adrecord',
            'addrevenue' => 'AddRevenue',
            'zanox' => 'Zanox',
            'partnerize' => 'Partnerize',
            'performance horizon' => 'Performance Horizon',
            'performance_horizon' => 'Performance Horizon',
            'amazon' => 'Amazon',
        ];
        
        // Check exact match first
        if (isset($display_mapping[$network_lower])) {
            return $display_mapping[$network_lower];
        }
        
        // Check partial matches (e.g., "Partner-Ads Denmark" contains "partner")
        foreach ($display_mapping as $key => $display_name) {
            if (strpos($network_lower, $key) !== false || strpos($key, $network_lower) !== false) {
                return $display_name;
            }
        }
        
        // If no mapping found, return original (capitalize first letter of each word)
        return ucwords(str_replace(['_', '-'], ' ', $raw_network));
    }

    /**
     * Format merchant comparison data from API response
     * 
     * @param array $products Products from API
     * @param array $original_product Original product data
     * @param int $limit Maximum merchants to return
     * @return array Formatted merchant comparison data
     */
    private function format_merchant_comparison_data($products, $original_product, $limit) {
        Logger::debug('format_merchant_comparison_data: Starting - Processing ' . count($products) . ' products, limit: ' . $limit);
        $merchants = [];
        $prices = [];
        $merchant_details = [];
        
        $method_start = microtime(true);
        
        // Always include the original product
        $original_merchant = $original_product['merchant'] ?? 'Unknown';
        $original_currency = $original_product['currency'] ?? CurrencyManager::getDefaultCurrency();
        $original_price = $this->convert_datafeedr_price($original_product['price'] ?? 0, $original_currency);
        $original_finalprice = $this->convert_datafeedr_price($original_product['finalprice'] ?? $original_product['price'] ?? 0, $original_currency);
        $original_salediscount = floatval($original_product['salediscount'] ?? 0);
        $original_network = $original_product['network'] ?? 'Unknown';
        $original_url = $original_product['url'] ?? '';
        
        // Use finalprice as the actual selling price
        $original_selling_price = $original_finalprice > 0 ? $original_finalprice : $original_price;
        
        if (!empty($original_merchant) && $original_selling_price > 0) {
            $merchant_key = sanitize_title($original_merchant);
            $merchant_details[$merchant_key] = [
                'name' => $original_merchant,
                'network' => $original_network,
                'currency' => $original_product['currency'] ?? 'USD',
                'prices' => [$original_selling_price],
                'products' => [[
                    'id' => $original_product['_id'] ?? $original_product['id'] ?? '',
                    'name' => $original_product['name'] ?? '',
                    'price' => $original_price, // Original/regular price
                    'finalprice' => $original_selling_price, // Sale/current price
                    'salediscount' => $original_salediscount, // Discount percentage
                    'url' => $original_url,
                    'image_url' => $original_product['image'] ?? $original_product['image_url'] ?? '',
                    'availability' => $original_product['availability'] ?? 'in_stock',
                    'rating' => $original_product['rating'] ?? 0,
                    'reviews_count' => $original_product['reviews_count'] ?? 0,
                    'network' => $original_network,
                    'is_original' => true
                ]],
                'lowest_price' => $original_selling_price,
                'highest_price' => $original_selling_price,
                'average_price' => $original_selling_price,
                'average_rating' => $original_product['rating'] ?? 0,
                'product_count' => 1,
                'is_original' => true,
                'url' => $original_url
            ];
            $prices[] = $original_selling_price;
            // error_log('[AEBG] Added original merchant: ' . $original_merchant . ' with network: ' . $original_network . ' and URL: ' . $original_url);
        }

        // Process API products
        // CRITICAL: Limit processing to prevent hangs on very large responses
        $max_products_to_process = min(count($products), 200); // Limit to 200 products max
        $products_to_process = array_slice($products, 0, $max_products_to_process);
        
        if (count($products) > $max_products_to_process) {
            Logger::info('format_merchant_comparison_data: Limiting processing to ' . $max_products_to_process . ' products (received ' . count($products) . ')');
        }
        
        $processed_merchants = [];
        $processing_start = microtime(true);
        $product_count = 0;
        
        foreach ($products_to_process as $product) {
            $product_count++;
            
            // CRITICAL: Add timeout check to prevent infinite loops or hangs
            // If processing takes more than 5 seconds, break and return what we have
            if (($product_count % 50) === 0) {
                $elapsed = microtime(true) - $processing_start;
                if ($elapsed > 5) {
                    Logger::warning('format_merchant_comparison_data: Processing timeout after ' . round($elapsed, 2) . 's and ' . $product_count . ' products - returning partial results');
                    break;
                }
            }
            // Try multiple merchant field names - Datafeedr API might use different field names
            $merchant = $product['merchant'] ?? $product['store'] ?? $product['merchant_name'] ?? $product['store_name'] ?? '';
            $product_currency = $product['currency'] ?? CurrencyManager::getDefaultCurrency();
            $price = $this->convert_datafeedr_price($product['price'] ?? 0, $product_currency);
            $network = $product['network'] ?? $product['network_name'] ?? 'Unknown';
            $url = $product['url'] ?? $product['direct_url'] ?? '';
            
            // error_log('[AEBG] Processing product: ' . ($product['name'] ?? 'Unknown') . ' - Merchant: ' . $merchant . ' - Price: ' . $price . ' - Network: ' . $network . ' - URL: ' . $url);
            
            if (!empty($merchant) && $price > 0) {
                $merchant_key = sanitize_title($merchant);
                
                // Get currency first for price normalization
                $product_currency = $product['currency'] ?? 'USD';
                
                // Get finalprice and salediscount for discount display FIRST
                // This ensures we use the sale price (finalprice) as the primary price, not the original price
                $finalprice = $this->convert_datafeedr_price($product['finalprice'] ?? $product['price'] ?? 0, $product_currency);
                $salediscount = floatval($product['salediscount'] ?? 0);
                $original_price = $price; // price is the original/regular price
                
                // Use finalprice as the actual selling price (lowest of sale or regular)
                // This is the price we should display and use for comparisons
                $selling_price = $finalprice > 0 ? $finalprice : $price;
                
                if (!isset($merchant_details[$merchant_key])) {
                    $merchant_details[$merchant_key] = [
                        'name' => $merchant,
                        'network' => $network,
                        'currency' => $product_currency,
                        'prices' => [],
                        'products' => [],
                        'lowest_price' => $selling_price, // Use selling price (finalprice), not original price
                        'highest_price' => $selling_price, // Use selling price (finalprice), not original price
                        'average_price' => 0,
                        'average_rating' => 0,
                        'product_count' => 0,
                        'is_original' => false,
                        'url' => $url
                    ];
                    $processed_merchants[] = $merchant;
                }
                
                $merchant_details[$merchant_key]['prices'][] = $selling_price;
                $merchant_details[$merchant_key]['products'][] = [
                    'id' => $product['_id'] ?? $product['id'] ?? '',
                    'name' => $product['name'] ?? '',
                    'price' => $original_price, // Original/regular price
                    'finalprice' => $selling_price, // Sale/current price
                    'salediscount' => $salediscount, // Discount percentage
                    'currency' => $product['currency'] ?? 'USD',
                    'url' => $url,
                    'image_url' => $product['image'] ?? $product['image_url'] ?? '',
                    'availability' => $product['availability'] ?? 'unknown',
                    'rating' => $product['rating'] ?? 0,
                    'reviews_count' => $product['reviews_count'] ?? 0,
                    'network' => $network,
                    'is_original' => false
                ];
                
                // Use selling price (finalprice) for lowest/highest calculations
                $merchant_details[$merchant_key]['lowest_price'] = min($merchant_details[$merchant_key]['lowest_price'], $selling_price);
                $merchant_details[$merchant_key]['highest_price'] = max($merchant_details[$merchant_key]['highest_price'], $selling_price);
                $merchant_details[$merchant_key]['product_count']++;
                
                // Update URL if we found a better one
                if (!empty($url) && empty($merchant_details[$merchant_key]['url'])) {
                    $merchant_details[$merchant_key]['url'] = $url;
                }
                
                $prices[] = $price;
            } else {
                Logger::debug('Skipping product - missing merchant or price: ' . ($product['name'] ?? 'Unknown'));
            }
        }

        Logger::debug('format_merchant_comparison_data: Completed product processing loop - Found ' . count($processed_merchants) . ' unique merchants');
        
        $loop_elapsed = microtime(true) - $method_start;
        if ($loop_elapsed > 2) {
            Logger::warning('format_merchant_comparison_data: Product processing loop took ' . round($loop_elapsed, 2) . ' seconds');
        }

        // Calculate averages and sort
        Logger::debug('format_merchant_comparison_data: Starting average calculation and sorting for ' . count($merchant_details) . ' merchants');
        $calc_start = microtime(true);
        
        foreach ($merchant_details as $key => &$merchant) {
            if (!empty($merchant['prices'])) {
                $merchant['average_price'] = array_sum($merchant['prices']) / count($merchant['prices']);
            }
            if (!empty($merchant['products'])) {
                $ratings = array_column($merchant['products'], 'rating');
                $ratings = array_filter($ratings, function($rating) { return $rating > 0; });
                $merchant['average_rating'] = !empty($ratings) ? array_sum($ratings) / count($ratings) : 0;
            }
            $merchant['prices'] = array_unique($merchant['prices']);
            sort($merchant['prices']);
        }

        $calc_elapsed = microtime(true) - $calc_start;
        Logger::debug('format_merchant_comparison_data: Completed average calculation in ' . round($calc_elapsed, 2) . ' seconds');
        
        // Sort merchants by lowest price (original product first if it exists)
        Logger::debug('format_merchant_comparison_data: Starting merchant sorting');
        $sort_start = microtime(true);
        
        uasort($merchant_details, function($a, $b) {
            $a_is_original = isset($a['is_original']) ? $a['is_original'] : false;
            $b_is_original = isset($b['is_original']) ? $b['is_original'] : false;
            
            if ($a_is_original && !$b_is_original) return -1;
            if (!$a_is_original && $b_is_original) return 1;
            return $a['lowest_price'] <=> $b['lowest_price'];
        });

        $sort_elapsed = microtime(true) - $sort_start;
        Logger::debug('format_merchant_comparison_data: Completed sorting in ' . round($sort_elapsed, 2) . ' seconds');
        
        // CRITICAL: Filter merchants by configured networks BEFORE limiting
        Logger::debug('format_merchant_comparison_data: Filtering merchants by configured networks');
        $merchant_details = $this->filter_merchants_by_configured_networks($merchant_details);
        Logger::debug('format_merchant_comparison_data: After network filtering: ' . count($merchant_details) . ' merchants');
        
        // Limit results
        Logger::debug('format_merchant_comparison_data: Limiting to ' . $limit . ' merchants');
        $merchant_details = array_slice($merchant_details, 0, $limit, true);

        Logger::debug('format_merchant_comparison_data: Final merchant count: ' . count($merchant_details));

        // Build the final merchants array with proper structure
        Logger::debug('format_merchant_comparison_data: Building final merchants array');
        $build_start = microtime(true);
        
        foreach ($merchant_details as $merchant_key => $merchant) {
            // Extract price information from products array for easier access
            $retail_price = null;
            $final_price = null;
            $sale_discount = 0;
            $currency = null;
            
            if (!empty($merchant['products']) && is_array($merchant['products'])) {
                // Get price info from first product (they should all be the same for same merchant)
                $first_product = $merchant['products'][0];
                $retail_price = $first_product['price'] ?? null;
                $final_price = $first_product['finalprice'] ?? null;
                $sale_discount = floatval($first_product['salediscount'] ?? 0);
                $currency = $first_product['currency'] ?? null;
            }
            
            // If currency not found, detect from merchant name/domain
            if (empty($currency)) {
                $merchant_name = $merchant['name'] ?? '';
                $currency = $this->detect_currency_from_merchant_name($merchant_name);
            }
            
            // Default to DKK for Danish shops (was USD)
            $currency = $currency ?: 'DKK';
            
            // Extract affiliate_url from first product if available (Datafeedr provides this with @@@ placeholder)
            $affiliate_url = null;
            if (!empty($merchant['products']) && is_array($merchant['products'])) {
                $first_product = $merchant['products'][0];
                $affiliate_url = $first_product['affiliate_url'] ?? null;
            }
            
            // Fallback to merchant-level affiliate_url if not in products
            if (empty($affiliate_url)) {
                $affiliate_url = $merchant['affiliate_url'] ?? null;
            }
            
            $merchants[$merchant['name']] = [
                'name' => $merchant['name'],
                'network' => $merchant['network'],
                'price' => $merchant['lowest_price'],
                'lowest_price' => $merchant['lowest_price'],
                'highest_price' => $merchant['highest_price'],
                'average_price' => $merchant['average_price'],
                'average_rating' => $merchant['average_rating'],
                'availability' => 'in_stock', // Default to in stock
                'url' => $merchant['url'],
                'affiliate_url' => $affiliate_url, // Include affiliate_url from Datafeedr (with @@@ placeholder)
                'rating' => $merchant['average_rating'],
                'merchant_count' => $merchant['product_count'],
                'is_original' => $merchant['is_original'] ?? false,
                // Include products array for detailed price information
                'products' => $merchant['products'] ?? [],
                // Include price fields at merchant level for easier access
                'finalprice' => $final_price,
                'salediscount' => $sale_discount,
                'currency' => $currency,
            ];
        }

        $build_elapsed = microtime(true) - $build_start;
        Logger::debug('format_merchant_comparison_data: Completed building final array in ' . round($build_elapsed, 2) . ' seconds');
        
        // Calculate overall price range
        Logger::debug('format_merchant_comparison_data: Calculating price range');
        $price_range = [
            'lowest' => !empty($prices) ? min($prices) : 0,
            'highest' => !empty($prices) ? max($prices) : 0
        ];

        Logger::debug('format_merchant_comparison_data: Final price range: ' . $price_range['lowest'] . ' - ' . $price_range['highest']);
        
        $total_elapsed = microtime(true) - $method_start;
        if ($total_elapsed > 1) {
            Logger::info('format_merchant_comparison_data: Method completed in ' . round($total_elapsed, 2) . ' seconds total');
        }
        Logger::debug('format_merchant_comparison_data: Returning ' . count($merchants) . ' merchants');

        return [
            'merchants' => $merchants,
            'merchant_count' => count($merchants),
            'price_range' => $price_range,
            'original_product' => $original_product,
            'total_products_found' => count($products),
            'timestamp' => current_time('mysql')
        ];
    }

    /**
     * Get fallback merchant data when API fails
     * 
     * @param array $product Product data
     * @return array Fallback merchant data
     */
    private function get_fallback_merchant_data($product) {
        $product_currency = $product['currency'] ?? CurrencyManager::getDefaultCurrency();
        $fallback_price = $this->convert_datafeedr_price($product['price'] ?? 0, $product_currency);
        $fallback_merchant = $product['merchant'] ?? 'Unknown';
        
        return [
            'merchant_count' => 1,
            'price_range' => [
                'lowest' => $fallback_price,
                'highest' => $fallback_price
            ],
            'merchants' => [
                sanitize_title($fallback_merchant) => [
                    'name' => $fallback_merchant,
                    'prices' => [$fallback_price],
                    'products' => [[
                        'id' => $product['id'] ?? '',
                        'name' => $product['name'] ?? '',
                        'price' => $fallback_price,
                        'url' => $product['url'] ?? '',
                        'image_url' => $product['image'] ?? $product['image_url'] ?? '',
                        'availability' => $product['availability'] ?? 'in_stock',
                        'rating' => $product['rating'] ?? 0,
                        'reviews_count' => $product['reviews_count'] ?? 0,
                        'is_original' => true
                    ]],
                    'lowest_price' => $fallback_price,
                    'highest_price' => $fallback_price,
                    'average_price' => $fallback_price,
                    'average_rating' => $product['rating'] ?? 0,
                    'product_count' => 1,
                    'is_original' => true
                ]
            ],
            'original_product' => $product,
            'total_products_found' => 1
        ];
    }

    /**
     * AJAX handler for merchant comparison modal
     */
    public function ajax_get_merchant_comparison() {
        if (!wp_verify_nonce($_POST['nonce'], 'aebg_ajax_nonce')) {
            wp_send_json_error('Invalid nonce.');
        }
        
        $product_id = sanitize_text_field($_POST['product_id'] ?? '');
        $product_data = $_POST['product_data'] ?? [];
        $limit = intval($_POST['limit'] ?? 20);
        
        if (empty($product_id)) {
            wp_send_json_error('Product ID is required.');
        }
        
        // Validate product data
        if (empty($product_data) || !is_array($product_data)) {
            wp_send_json_error('Invalid product data provided.');
        }
        
        // Ensure product_id is set in product_data
        $product_data['id'] = $product_id;
        
        // Get merchant comparison data (with caching)
        $merchant_data = $this->get_merchant_comparison($product_data, $limit);
        
        if (is_wp_error($merchant_data)) {
            wp_send_json_error($merchant_data->get_error_message());
        }
        
        wp_send_json_success($merchant_data);
    }

    /**
     * AJAX handler for updating product merchant
     */
    public function ajax_update_product_merchant() {
        if (!wp_verify_nonce($_POST['nonce'], 'aebg_ajax_nonce')) {
            wp_send_json_error('Invalid nonce.');
        }
        
        $product_id = sanitize_text_field($_POST['product_id'] ?? '');
        $merchant_name = sanitize_text_field($_POST['merchant_name'] ?? '');
        
        if (empty($product_id) || empty($merchant_name)) {
            wp_send_json_error('Product ID and merchant name are required.');
        }
        
        // Update product merchant in database
        $result = $this->update_product_merchant($product_id, $merchant_name);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success('Merchant updated successfully');
    }

    /**
     * Update product merchant in database
     */
    private function update_product_merchant($product_id, $merchant_name) {
        global $wpdb;
        
        $post_id = get_the_ID();
        if (!$post_id) {
            return new \WP_Error('no_post_id', 'No post ID found');
        }
        
        // Get current products
        $products = get_post_meta($post_id, '_aebg_products', true);
        if (!is_array($products)) {
            return new \WP_Error('no_products', 'No products found');
        }
        
        // Find and update the product
        $updated = false;
        foreach ($products as &$product) {
            if (($product['id'] ?? '') === $product_id) {
                $product['merchant'] = $merchant_name;
                $updated = true;
                break;
            }
        }
        
        if (!$updated) {
            return new \WP_Error('product_not_found', 'Product not found');
        }
        
        // Save updated products
        $result = update_post_meta($post_id, '_aebg_products', $products);
        
        if ($result === false) {
            return new \WP_Error('update_failed', 'Failed to update product');
        }
        
        // Clear cache for this product
        \AEBG\Core\MerchantCache::clear($product_id);
        
        return true;
    }

    /**
     * Clear merchant cache for a specific product
     * 
     * @param string $product_id Product ID
     * @return bool True on success, false on failure
     */
    public function clear_merchant_cache($product_id) {
        if (empty($product_id)) {
            return false;
        }
        
        // Clear transient cache
        $cache_cleared = \AEBG\Core\MerchantCache::clear($product_id);
        
        // Also clear old comparison data from database
        global $wpdb;
        $table_name = $wpdb->prefix . 'aebg_comparisons';
        $post_id = get_the_ID();
        $user_id = get_current_user_id();
        
        // Delete old comparison data for this product
        error_log('[AEBG] Attempting to delete old comparison data for product: ' . $product_id . ', user: ' . $user_id . ', post: ' . $post_id);
        
        $deleted = $wpdb->delete(
            $table_name,
            [
                'user_id' => $user_id,
                'post_id' => $post_id,
                'product_id' => $product_id
            ],
            ['%d', '%s', '%s']
        );
        
        if ($deleted !== false) {
            error_log('[AEBG] Cleared ' . $deleted . ' old comparison records from database for product: ' . $product_id);
        } else {
            error_log('[AEBG] Failed to delete comparison records from database for product: ' . $product_id);
            error_log('[AEBG] Database error: ' . $wpdb->last_error);
        }
        
        return $cache_cleared;
    }

    /**
     * Clear all merchant caches
     * 
     * @return int Number of caches cleared
     */
    public function clear_all_merchant_caches() {
        return \AEBG\Core\MerchantCache::clear_all();
    }

    /**
     * AJAX handler for clearing merchant cache
     */
    public function ajax_clear_merchant_cache() {
        if (!wp_verify_nonce($_POST['nonce'], 'aebg_ajax_nonce')) {
            wp_send_json_error('Invalid nonce.');
        }
        
        $product_id = sanitize_text_field($_POST['product_id'] ?? '');
        
        if (empty($product_id)) {
            // Clear all caches
            $cleared_count = $this->clear_all_merchant_caches();
            wp_send_json_success('Cleared ' . $cleared_count . ' merchant caches');
        } else {
            // Clear specific product cache
            $result = $this->clear_merchant_cache($product_id);
            if ($result) {
                wp_send_json_success('Cleared merchant cache for product: ' . $product_id);
            } else {
                wp_send_json_error('Failed to clear merchant cache for product: ' . $product_id);
            }
        }
    }

    /**
     * AJAX handler for updating product name
     */
    public function ajax_update_product_name() {
        error_log('[AEBG] ajax_update_product_name called');
        error_log('[AEBG] POST data: ' . print_r($_POST, true));
        
        if (!wp_verify_nonce($_POST['nonce'], 'aebg_ajax_nonce')) {
            error_log('[AEBG] Nonce verification failed');
            wp_send_json_error('Invalid nonce.');
        }
        
        $product_id = sanitize_text_field($_POST['product_id'] ?? '');
        $new_name = sanitize_text_field($_POST['new_name'] ?? '');
        $post_id = intval($_POST['post_id'] ?? 0);
        
        error_log('[AEBG] Processed data: product_id=' . $product_id . ', new_name=' . $new_name . ', post_id=' . $post_id);
        
        if (empty($product_id)) {
            wp_send_json_error('Product ID is required.');
        }
        
        if (empty($new_name)) {
            wp_send_json_error('Product name cannot be empty.');
        }
        
        // Update product name in database
        $result = $this->update_product_name($product_id, $new_name);
        
        if (is_wp_error($result)) {
            error_log('[AEBG] Update failed: ' . $result->get_error_message());
            wp_send_json_error($result->get_error_message());
        }
        
        error_log('[AEBG] Update successful');
        wp_send_json_success('Product name updated successfully');
    }
    
    /**
     * Update product name in database
     * 
     * @param string $product_id Product ID
     * @param string $new_name New product name
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    private function update_product_name($product_id, $new_name) {
        global $wpdb;
        
        error_log('[AEBG] update_product_name called: product_id=' . $product_id . ', new_name=' . $new_name);
        
        // Get the current post ID from the product ID
        $post_id = $this->get_post_id_from_product_id($product_id);
        
        error_log('[AEBG] Found post_id: ' . ($post_id ? $post_id : 'false'));
        
        if (!$post_id) {
            error_log('[AEBG] Product not found in any post: ' . $product_id);
            return new \WP_Error('product_not_found', 'Product not found in any post.');
        }
        
        // Get current products
        $products = get_post_meta($post_id, '_aebg_products', true);
        
        error_log('[AEBG] Current products: ' . (is_array($products) ? count($products) . ' products' : 'not an array'));
        
        if (!is_array($products)) {
            error_log('[AEBG] No products found for post: ' . $post_id);
            return new \WP_Error('no_products', 'No products found for this post.');
        }
        
        // Find and update the product
        $product_found = false;
        foreach ($products as $key => &$product) {
            if (!is_array($product)) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log('[AEBG] Product at key ' . $key . ' is not an array: ' . print_r($product, true));
                }
                continue;
            }
            
            $current_product_id = $product['id'] ?? '';
            error_log('[AEBG] Checking product: ' . $current_product_id . ' against: ' . $product_id);
            
            if ($current_product_id === $product_id) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log('[AEBG] Found product to update: ' . $product_id);
                }
                $product['name'] = $new_name;
                $product['display_name'] = $new_name; // Add a display name field
                $product_found = true;
                break;
            }
        }
        
        if (!$product_found) {
            error_log('[AEBG] Product not found in post products: ' . $product_id);
            error_log('[AEBG] Available product IDs: ' . implode(', ', array_map(function($p) { return $p['id'] ?? 'no-id'; }, $products)));
            return new \WP_Error('product_not_found', 'Product not found in post products.');
        }
        
        // Save updated products
        error_log('[AEBG] Saving updated products to post: ' . $post_id);
        
        // Ensure the data is properly formatted
        $products = array_values(array_filter($products, function($product) {
            return is_array($product) && !empty($product);
        }));
        
        // Try to update the post meta
        $result = update_post_meta($post_id, '_aebg_products', $products);
        
        if ($result === false) {
            // Try alternative approach - delete and re-add
            error_log('[AEBG] First attempt failed, trying delete and re-add approach');
            delete_post_meta($post_id, '_aebg_products');
            $result = add_post_meta($post_id, '_aebg_products', $products, true);
            
            if ($result === false) {
                error_log('[AEBG] Failed to save updated product data to post: ' . $post_id);
                error_log('[AEBG] Products data: ' . print_r($products, true));
                return new \WP_Error('save_failed', 'Failed to save updated product data.');
            }
        }
        
        // Clear merchant cache for this product
        $this->clear_merchant_cache($product_id);
        
        error_log('[AEBG] Successfully updated product name: ' . $product_id . ' to "' . $new_name . '"');
        
        return true;
    }
    
    /**
     * Get post ID from product ID
     * 
     * @param string $product_id Product ID
     * @return int|false Post ID or false if not found
     */
    private function get_post_id_from_product_id($product_id) {
        global $wpdb;
        
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log('[AEBG] get_post_id_from_product_id called: ' . $product_id);
        }
        
        // First, try to find the product in the current post context
        if (isset($_POST['post_id']) && !empty($_POST['post_id'])) {
            $current_post_id = intval($_POST['post_id']);
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log('[AEBG] Using current post_id from request: ' . $current_post_id);
            }
            
            // Verify the product exists in this post
            $products = get_post_meta($current_post_id, '_aebg_products', true);
            if (is_array($products)) {
                foreach ($products as $product) {
                    if (is_array($product) && ($product['id'] ?? '') === $product_id) {
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log('[AEBG] Found product in current post: ' . $current_post_id);
                        }
                        return $current_post_id;
                    }
                }
            }
        }
        
        // Try to get post ID from current screen
        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen && $screen->base === 'post' && isset($_GET['post'])) {
                $post_id = intval($_GET['post']);
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log('[AEBG] Using post_id from current screen: ' . $post_id);
                }
                
                // Verify the product exists in this post
                $products = get_post_meta($post_id, '_aebg_products', true);
                if (is_array($products)) {
                    foreach ($products as $product) {
                        if (is_array($product) && ($product['id'] ?? '') === $product_id) {
                            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                error_log('[AEBG] Found product in current screen post: ' . $post_id);
                            }
                            return $post_id;
                        }
                    }
                }
            }
        }
        
        // Search in post meta for the product
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_aebg_products' 
            AND meta_value LIKE %s",
            '%' . $wpdb->esc_like($product_id) . '%'
        ));
        
        error_log('[AEBG] Database search result: ' . ($post_id ? $post_id : 'false'));
        
        return $post_id ? (int) $post_id : false;
    }

    /**
     * Test Datafeedr API connection and search functionality
     */
    public function test_search_functionality() {
        // Test search functionality
        $test_query = 'test product';
        $results = $this->search_advanced($test_query, 5, 'relevance', 0, 0, 0, false, '', '', '', false, 0, [], '');
        
        if (is_wp_error($results)) {
            return $results;
        }
        
        return $results;
    }
    
    /**
     * AJAX handler for saving comparison data
     */
    public function ajax_save_comparison() {
        if (!wp_verify_nonce($_POST['nonce'], 'aebg_ajax_nonce')) {
            wp_send_json_error('Invalid nonce.');
        }
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('User not logged in.');
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : null;
        $product_id = sanitize_text_field($_POST['product_id'] ?? '');
        $comparison_name = sanitize_text_field($_POST['comparison_name'] ?? 'Product Comparison');
        $comparison_data = $_POST['comparison_data'] ?? [];
        
        if (empty($product_id)) {
            wp_send_json_error('Product ID is required.');
        }
        
        // Validate comparison data
        if (!is_array($comparison_data)) {
            wp_send_json_error('Invalid comparison data format.');
        }
        
        // CRITICAL: Filter merchants by configured networks before saving
        if (isset($comparison_data['merchants']) && is_array($comparison_data['merchants'])) {
            $filtered_merchants = $this->filter_merchants_array_by_configured_networks($comparison_data['merchants']);
            $original_count = count($comparison_data['merchants']);
            $filtered_count = count($filtered_merchants);
            $comparison_data['merchants'] = $filtered_merchants;
            $comparison_data['merchant_count'] = $filtered_count;
            
            error_log('[AEBG] 💾 SAVE: Filtered merchants before saving - ' . $original_count . ' before, ' . $filtered_count . ' after (only configured networks)');
            
            // Recalculate price range based on filtered merchants
            if (!empty($filtered_merchants)) {
                $prices = [];
                foreach ($filtered_merchants as $merchant) {
                    $price = $merchant['price'] ?? $merchant['lowest_price'] ?? 0;
                    if ($price > 0) {
                        $prices[] = $price;
                    }
                }
                if (!empty($prices)) {
                    $comparison_data['price_range'] = [
                        'lowest' => min($prices),
                        'highest' => max($prices)
                    ];
                } else {
                    // No valid prices found, set to empty range
                    $comparison_data['price_range'] = [
                        'lowest' => 0,
                        'highest' => 0
                    ];
                }
            } else {
                // No merchants after filtering, set empty range and count
                $comparison_data['price_range'] = [
                    'lowest' => 0,
                    'highest' => 0
                ];
                $comparison_data['merchant_count'] = 0;
            }
        }
        
        // Try to save comparison data, but handle database errors gracefully
        try {
            $result = \AEBG\Core\ComparisonManager::save_comparison(
                $user_id,
                $post_id,
                $product_id,
                $comparison_name,
                $comparison_data
            );
            
            if ($result) {
                wp_send_json_success([
                    'comparison_id' => $result,
                    'message' => 'Comparison saved successfully.'
                ]);
            } else {
                // Check if this is a database schema issue that was automatically fixed
                global $wpdb;
                $error = $wpdb->last_error;
                if (strpos($error, 'Duplicate entry') !== false && strpos($error, 'idx_user_product') !== false) {
                    // The ComparisonManager should have handled this automatically
                    // If we still get an error, it means the automatic fix failed
                    wp_send_json_error('Database schema issue detected. Please refresh the page and try again.');
                } else {
                    wp_send_json_error('Failed to save comparison: ' . $error);
                }
            }
        } catch (Exception $e) {
            error_log('[AEBG] Exception in ajax_save_comparison: ' . $e->getMessage());
            wp_send_json_error('Failed to save comparison: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for loading comparison data
     */
    public function ajax_load_comparison() {
        if (!wp_verify_nonce($_POST['nonce'], 'aebg_ajax_nonce')) {
            wp_send_json_error('Invalid nonce.');
        }
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('User not logged in.');
        }
        
        $product_id = sanitize_text_field($_POST['product_id'] ?? '');
        
        if (empty($product_id)) {
            wp_send_json_error('Product ID is required.');
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : null;
        
        error_log('[AEBG] 🔍 AJAX: Loading comparison for product ' . $product_id . ' with post_id: ' . ($post_id ?? 'NULL'));
        
        $comparison = \AEBG\Core\ComparisonManager::get_comparison($user_id, $product_id, $post_id);
        
        if ($comparison) {
            error_log('[AEBG] ✅ AJAX: Found comparison data for product ' . $product_id);
            wp_send_json_success($comparison);
        } else {
            error_log('[AEBG] 📭 AJAX: No comparison data found for product ' . $product_id . ' with post_id: ' . ($post_id ?? 'NULL'));
            wp_send_json_error('No comparison data found.');
        }
    }
    
    /**
     * AJAX handler for deleting comparison data
     */
    public function ajax_delete_comparison() {
        if (!wp_verify_nonce($_POST['nonce'], 'aebg_ajax_nonce')) {
            wp_send_json_error('Invalid nonce.');
        }
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('User not logged in.');
        }
        
        $product_id = sanitize_text_field($_POST['product_id'] ?? '');
        
        if (empty($product_id)) {
            wp_send_json_error('Product ID is required.');
        }
        
        $result = \AEBG\Core\ComparisonManager::delete_comparison($user_id, $product_id);
        
        if ($result) {
            wp_send_json_success('Comparison deleted successfully.');
        } else {
            wp_send_json_error('Failed to delete comparison.');
        }
    }
    
    /**
     * AJAX handler for getting all user comparisons
     */
    public function ajax_get_user_comparisons() {
        if (!wp_verify_nonce($_POST['nonce'], 'aebg_ajax_nonce')) {
            wp_send_json_error('Invalid nonce.');
        }
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('User not logged in.');
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : null;
        
        $comparisons = \AEBG\Core\ComparisonManager::get_user_comparisons($user_id, $post_id);
        
        wp_send_json_success($comparisons);
    }
    
    /**
     * AJAX handler for testing comparison database
     */
    public function ajax_test_comparison_db() {
        if (!wp_verify_nonce($_POST['nonce'], 'aebg_ajax_nonce')) {
            wp_send_json_error('Invalid nonce.');
        }
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('User not logged in.');
        }
        
        $test_results = \AEBG\Core\ComparisonManager::test_database_connection();
        
        if ($test_results['error']) {
            wp_send_json_error($test_results);
        } else {
            wp_send_json_success($test_results);
        }
    }

    /**
     * AJAX handler for clearing incorrect comparison data
     */
    public function ajax_clear_incorrect_comparisons() {
        if (!wp_verify_nonce($_POST['nonce'], 'aebg_ajax_nonce')) {
            wp_send_json_error('Invalid nonce.');
        }
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('User not logged in.');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'aebg_comparisons';
        
        // Delete records that contain strike-a-pose data (incorrect format)
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE user_id = %d AND comparison_data LIKE %s",
                $user_id,
                '%strike-a-pose%'
            )
        );
        
        if ($deleted !== false) {
            error_log('[AEBG] Cleared ' . $deleted . ' incorrect comparison records for user: ' . $user_id);
            wp_send_json_success([
                'message' => 'Cleared ' . $deleted . ' incorrect comparison records',
                'deleted_count' => $deleted
            ]);
        } else {
            error_log('[AEBG] Failed to clear incorrect comparison records for user: ' . $user_id);
            wp_send_json_error('Failed to clear incorrect comparison data');
        }
    }

    /**
     * Save found products to the database for future use
     * 
     * @param array $products Array of found products
     * @param string $search_query The original search query
     */
    private function save_found_products_to_database($products, $search_query) {
        if (empty($products) || !is_array($products)) {
            return;
        }
        
        // Only save if ComparisonManager is available
        if (!class_exists('\AEBG\Core\ComparisonManager')) {
            error_log('[AEBG] ComparisonManager not available, skipping database save');
            return;
        }
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            error_log('[AEBG] No user ID available, skipping database save');
            return;
        }
        
        error_log('[AEBG] Attempting to save ' . count($products) . ' found products to database');
        
        foreach ($products as $product) {
            if (empty($product['id']) || empty($product['name'])) {
                continue;
            }
            
            // Create comparison data structure
            $product_currency = $product['currency'] ?? CurrencyManager::getDefaultCurrency();
            $normalized_price = $this->convert_datafeedr_price($product['price'] ?? 0, $product_currency);
            $comparison_data = [
                'merchants' => [
                    [
                        'name' => $product['merchant'] ?? 'Unknown',
                        'network' => $product['source'] ?? 'Unknown',
                        'prices' => [$normalized_price],
                        'products' => [[
                            'id' => $product['id'],
                            'name' => $product['name'],
                            'price' => $normalized_price,
                            'url' => $product['url'] ?? '',
                            'image_url' => $product['image'] ?? '',
                            'availability' => $product['availability'] ?? 'unknown',
                            'rating' => floatval($product['rating'] ?? 0),
                            'reviews_count' => intval($product['reviews_count'] ?? 0),
                            'network' => $product['source'] ?? 'Unknown',
                            'is_original' => true
                        ]],
                        'lowest_price' => $normalized_price,
                        'highest_price' => $normalized_price,
                        'average_price' => $normalized_price,
                        'average_rating' => floatval($product['rating'] ?? 0),
                        'product_count' => 1,
                        'is_original' => true
                    ]
                ],
                'price_range' => [
                    'lowest' => $normalized_price,
                    'highest' => $normalized_price
                ],
                'search_query' => $search_query,
                'found_via' => 'datafeedr_search',
                'timestamp' => current_time('mysql')
            ];
            
            try {
                // Try to save to database (without post_id for now)
                $save_result = \AEBG\Core\ComparisonManager::save_comparison(
                    $user_id,
                    null, // No post_id for general searches
                    $product['id'],
                    'Datafeedr Search Result: ' . $product['name'],
                    $comparison_data
                );
                
                if ($save_result !== false) {
                    error_log('[AEBG] Successfully saved product ' . $product['id'] . ' to database');
                } else {
                    error_log('[AEBG] Failed to save product ' . $product['id'] . ' to database');
                }
            } catch (Exception $e) {
                error_log('[AEBG] Exception while saving product ' . $product['id'] . ' to database: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Filter merchants by configured networks (for merchant_details array format)
     * 
     * @param array $merchant_details Associative array with merchant_key => merchant_data
     * @return array Filtered merchant_details array
     */
    private function filter_merchants_by_configured_networks($merchant_details) {
        if (empty($merchant_details)) {
            return $merchant_details;
        }
        
        // Get all configured networks
        $networks_manager = new \AEBG\Admin\Networks_Manager();
        $all_affiliate_ids = $networks_manager->get_all_affiliate_ids();
        $configured_network_keys = array_keys(array_filter($all_affiliate_ids, function($id) {
            return !empty($id);
        }));
        
        if (empty($configured_network_keys)) {
            Logger::debug('filter_merchants_by_configured_networks: No configured networks found, returning all merchants');
            return $merchant_details;
        }
        
        // Map configured network keys to Datafeedr source names and display names
        $configured_sources = [];
        foreach ($configured_network_keys as $network_key) {
            $mapped_source = $this->mapNetworkToSource($network_key);
            if ($mapped_source) {
                $display_name = $this->mapSourceToDisplayName($mapped_source);
                if ($display_name) {
                    $configured_sources[] = strtolower($display_name);
                }
                $configured_sources[] = strtolower($mapped_source);
            }
            // Also add the network key itself for matching
            $configured_sources[] = strtolower($network_key);
        }
        
        if (empty($configured_sources)) {
            Logger::debug('filter_merchants_by_configured_networks: No configured sources found after mapping, returning all merchants');
            return $merchant_details;
        }
        
        Logger::debug('filter_merchants_by_configured_networks: Filtering ' . count($merchant_details) . ' merchants by configured sources: ' . implode(', ', $configured_sources));
        
        // Filter merchants by network
        $filtered_merchants = [];
        foreach ($merchant_details as $merchant_key => $merchant) {
            $merchant_network = strtolower(trim($merchant['network'] ?? ''));
            
            // Check if merchant's network matches any configured source
            // Use exact match or word-boundary matching to avoid false positives
            $is_configured = false;
            foreach ($configured_sources as $configured_source) {
                $configured_source = strtolower(trim($configured_source));
                if (empty($merchant_network) || empty($configured_source)) {
                    continue;
                }
                
                // Exact match
                if ($merchant_network === $configured_source) {
                    $is_configured = true;
                    break;
                }
                
                // Check if merchant network contains the configured source as a whole word
                // This prevents "partner" from matching "partnerize"
                if (preg_match('/\b' . preg_quote($configured_source, '/') . '\b/i', $merchant_network)) {
                    $is_configured = true;
                    break;
                }
                
                // Also check reverse: if configured source contains merchant network as whole word
                // This handles cases like "partner-ads denmark" matching "partner-ads"
                if (preg_match('/\b' . preg_quote($merchant_network, '/') . '\b/i', $configured_source)) {
                    $is_configured = true;
                    break;
                }
            }
            
            if ($is_configured) {
                $filtered_merchants[$merchant_key] = $merchant;
            } else {
                Logger::debug('filter_merchants_by_configured_networks: Filtered out merchant: ' . ($merchant['name'] ?? $merchant_key) . ' (network: ' . $merchant_network . ')');
            }
        }
        
        Logger::debug('filter_merchants_by_configured_networks: Filtering result - ' . count($merchant_details) . ' before, ' . count($filtered_merchants) . ' after');
        
        return $filtered_merchants;
    }
    
    /**
     * Filter merchants array by configured networks (for merchants array format)
     * 
     * @param array $merchants Associative array with merchant_name => merchant_data
     * @return array Filtered merchants array
     */
    private function filter_merchants_array_by_configured_networks($merchants) {
        if (empty($merchants)) {
            return $merchants;
        }
        
        // Get all configured networks
        $networks_manager = new \AEBG\Admin\Networks_Manager();
        $all_affiliate_ids = $networks_manager->get_all_affiliate_ids();
        $configured_network_keys = array_keys(array_filter($all_affiliate_ids, function($id) {
            return !empty($id);
        }));
        
        if (empty($configured_network_keys)) {
            error_log('[AEBG] filter_merchants_array_by_configured_networks: No configured networks found, returning all merchants');
            return $merchants;
        }
        
        // Map configured network keys to Datafeedr source names and display names
        $configured_sources = [];
        foreach ($configured_network_keys as $network_key) {
            $mapped_source = $this->mapNetworkToSource($network_key);
            if ($mapped_source) {
                $display_name = $this->mapSourceToDisplayName($mapped_source);
                if ($display_name) {
                    $configured_sources[] = strtolower($display_name);
                }
                $configured_sources[] = strtolower($mapped_source);
            }
            // Also add the network key itself for matching
            $configured_sources[] = strtolower($network_key);
        }
        
        if (empty($configured_sources)) {
            error_log('[AEBG] filter_merchants_array_by_configured_networks: No configured sources found after mapping, returning all merchants');
            return $merchants;
        }
        
        error_log('[AEBG] filter_merchants_array_by_configured_networks: Filtering ' . count($merchants) . ' merchants by configured sources: ' . implode(', ', $configured_sources));
        
        // Filter merchants by network
        $filtered_merchants = [];
        foreach ($merchants as $merchant_name => $merchant) {
            $merchant_network = strtolower(trim($merchant['network'] ?? $merchant['network_name'] ?? ''));
            $merchant_source = strtolower(trim($merchant['source'] ?? ''));
            
            // Check if merchant's network or source matches any configured source
            // Use exact match or word-boundary matching to avoid false positives
            $is_configured = false;
            foreach ($configured_sources as $configured_source) {
                $configured_source = strtolower(trim($configured_source));
                if (empty($configured_source)) {
                    continue;
                }
                
                // Check merchant network
                if (!empty($merchant_network)) {
                    // Exact match
                    if ($merchant_network === $configured_source) {
                        $is_configured = true;
                        break;
                    }
                    
                    // Word-boundary matching to prevent false positives
                    if (preg_match('/\b' . preg_quote($configured_source, '/') . '\b/i', $merchant_network)) {
                        $is_configured = true;
                        break;
                    }
                    
                    // Reverse: configured contains merchant network
                    if (preg_match('/\b' . preg_quote($merchant_network, '/') . '\b/i', $configured_source)) {
                        $is_configured = true;
                        break;
                    }
                }
                
                // Check merchant source
                if (!empty($merchant_source)) {
                    // Exact match
                    if ($merchant_source === $configured_source) {
                        $is_configured = true;
                        break;
                    }
                    
                    // Word-boundary matching
                    if (preg_match('/\b' . preg_quote($configured_source, '/') . '\b/i', $merchant_source)) {
                        $is_configured = true;
                        break;
                    }
                    
                    // Reverse: configured contains merchant source
                    if (preg_match('/\b' . preg_quote($merchant_source, '/') . '\b/i', $configured_source)) {
                        $is_configured = true;
                        break;
                    }
                }
            }
            
            if ($is_configured) {
                $filtered_merchants[$merchant_name] = $merchant;
            } else {
                error_log('[AEBG] filter_merchants_array_by_configured_networks: Filtered out merchant: ' . $merchant_name . ' (network: ' . $merchant_network . ', source: ' . $merchant_source . ')');
            }
        }
        
        error_log('[AEBG] filter_merchants_array_by_configured_networks: Filtering result - ' . count($merchants) . ' before, ' . count($filtered_merchants) . ' after');
        
        return $filtered_merchants;
    }
    
    /**
     * Detect currency from merchant name/domain
     * 
     * @param string $merchant_name Merchant name or domain
     * @return string|null Currency code or null if cannot detect
     */
    /**
     * Detect currency from merchant name/domain
     * 
     * @deprecated Use CurrencyManager::detectCurrency() instead
     * This method is kept for backward compatibility but now delegates to CurrencyManager
     * 
     * @param string $merchant_name Merchant name or domain
     * @return string|null Currency code or null if cannot detect
     */
    private function detect_currency_from_merchant_name($merchant_name) {
        return CurrencyManager::detectCurrency($merchant_name);
    }
    
    /**
     * Reset HTTP connections before making Datafeedr API request
     * This is critical for second+ jobs which may inherit stale connections
     * 
     * @return void
     */
    private function resetHttpConnectionsBeforeRequest() {
        error_log('[AEBG] resetHttpConnectionsBeforeRequest: Resetting HTTP connections before Datafeedr API call for job #' . ($GLOBALS['aebg_job_number'] ?? 'unknown'));
        
        // CRITICAL: Small delay to ensure any pending connections from previous job are fully closed
        // This gives the OS time to close TCP connections before we make a new request
        // This is the ONLY thing we do - we don't clear WordPress cache as it might affect other requests
        usleep(100000); // 0.1 second delay - ensures connections are fully closed
        
        error_log('[AEBG] resetHttpConnectionsBeforeRequest: HTTP connection reset completed (delay only)');
    }
}




