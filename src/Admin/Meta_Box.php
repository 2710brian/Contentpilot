<?php

namespace AEBG\Admin;

use AEBG\Core\TemplateManager;

class Meta_Box {
    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'save_post', [ $this, 'save_meta_box' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'wp_ajax_aebg_update_product_rating', [ $this, 'ajax_update_product_rating' ] );
        add_action( 'wp_ajax_aebg_save_product_data', [ $this, 'ajax_save_product_data' ] );
        add_action( 'wp_ajax_aebg_regenerate_content', [ $this, 'ajax_regenerate_content' ] );
        add_action( 'wp_ajax_aebg_update_template_for_new_product', [ $this, 'ajax_update_template_for_new_product' ] );
        add_action( 'wp_ajax_aebg_update_template_after_removal', [ $this, 'ajax_update_template_after_removal' ] );
        add_action( 'wp_ajax_aebg_remove_product', [ $this, 'ajax_remove_product' ] );
        add_action( 'wp_ajax_aebg_get_networks_for_modal', [ $this, 'ajax_get_networks_for_modal' ] );
    }

    public function add_meta_box() {
        add_meta_box(
            'aebg_products',
            __( 'Associated Products', 'aebg' ),
            [ $this, 'render_meta_box' ],
            'post',
            'normal',
            'high'
        );
    }

    public function enqueue_scripts( $hook ) {
        global $post;
        
        // Only enqueue on post edit pages
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ] ) ) {
            return;
        }
        
        // Only enqueue for post type
        if ( $post && $post->post_type !== 'post' ) {
            return;
        }

        // Enqueue jQuery UI for sortable functionality
        wp_enqueue_script( 'jquery-ui-sortable' );
        
        // Enqueue our custom CSS and JS
        // Add cache-busting timestamp with microtime for more aggressive cache busting
        $cache_buster = microtime(true);
        
        wp_enqueue_style(
            'aebg-edit-posts',
            plugin_dir_url( dirname( __DIR__ ) ) . 'assets/css/edit-posts.css',
            [],
            '1.0.1.' . $cache_buster
        );

        // Enqueue modern network selector styles since it's included in the view
        // Only enqueue if not already enqueued to prevent duplicates
        if (!wp_style_is('aebg-modern-networks-selector-css', 'enqueued')) {
            wp_enqueue_style(
                'aebg-modern-networks-selector-css',
                plugin_dir_url( dirname( __DIR__ ) ) . 'assets/css/modern-networks-selector.css',
                [],
                '1.0.1.' . $cache_buster
            );
        }
        
        wp_enqueue_script(
            'aebg-edit-posts',
            plugin_dir_url( dirname( __DIR__ ) ) . 'assets/js/edit-posts.js',
            [ 'jquery', 'jquery-ui-sortable' ],
            '1.0.1.' . $cache_buster,
            true
        );
        
        // Enqueue reorder conflict handler
        wp_enqueue_script(
            'aebg-reorder-conflict-handler',
            plugin_dir_url( dirname( __DIR__ ) ) . 'assets/js/reorder-conflict-handler.js',
            [ 'jquery', 'aebg-edit-posts' ],
            '1.0.0.' . $cache_buster,
            true
        );
        
        // Enqueue reorder progress UI
        wp_enqueue_script(
            'aebg-reorder-progress-ui',
            plugin_dir_url( dirname( __DIR__ ) ) . 'assets/js/reorder-progress-ui.js',
            [ 'jquery', 'aebg-reorder-conflict-handler' ],
            '1.0.0.' . $cache_buster,
            true
        );

        // Enqueue modern network selector script since it's included in the view
        // Only enqueue if not already enqueued to prevent duplicates
        if (!wp_script_is('aebg-modern-network-selector-js', 'enqueued')) {
            wp_enqueue_script(
                'aebg-modern-network-selector-js',
                plugin_dir_url( dirname( __DIR__ ) ) . 'assets/js/modern-network-selector.js',
                [ 'jquery' ],
                '1.0.1.' . $cache_buster,
                true
            );
        }
        
        // Add inline script to prevent duplicate class declarations
        wp_add_inline_script('aebg-modern-network-selector-js', '
            // Prevent duplicate class declarations
            if (typeof window.aebgNetworksSelectorLoaded === "undefined") {
                window.aebgNetworksSelectorLoaded = true;
            } else {
                console.warn("Modern Network Selector script already loaded, preventing duplicate initialization");
            }
        ');

        // Get networks data for the Modern Networks Selector
        $networks_manager = new \AEBG\Admin\Networks_Manager();
        $networks_data = $networks_manager->get_all_networks_with_status();
        
        if ( is_wp_error( $networks_data ) ) {
            $networks_data = [];
        }
        
        // Localize script with AJAX data and networks data
        // Localize reorder conflict handler
        wp_localize_script( 'aebg-reorder-conflict-handler', 'aebg_reorder', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'aebg_reorder_conflict' ),
            'nonce_detect' => wp_create_nonce( 'aebg_detect_reorder_conflicts' ),
        ] );
        
        wp_localize_script( 'aebg-edit-posts', 'aebg_ajax', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'aebg_ajax_nonce' ),
            'update_post_nonce' => wp_create_nonce( 'aebg_update_post_products' ),
            'search_products_nonce' => wp_create_nonce( 'aebg_search_products' ),
            'plugin_url' => plugin_dir_url( dirname( __DIR__ ) )
        ] );
        
        // Localize networks data for the Modern Networks Selector
        wp_localize_script( 'aebg-edit-posts', 'aebgNetworksData', $networks_data );
    }

    /**
     * Get merchant counts from database for all products on a post
     * 
     * @param int $post_id Post ID
     * @param array $products Array of products
     * @return array Array of merchant counts indexed by product ID
     */
    /**
     * Normalize product data before saving
     * Handles field mapping, currency detection, and network assignment
     * 
     * @param array $product_data Raw product data
     * @param int $post_id Post ID
     * @param array $existing_products Existing products array
     * @return array Normalized product data
     * @deprecated Use ProductManager::normalizeProductData() instead
     */
    private function normalize_product_data_for_save( $product_data, $post_id, $existing_products = [] ) {
        // Delegate to ProductManager for consistency
        return \AEBG\Core\ProductManager::normalizeProductData($product_data, $post_id, $existing_products);
    }

    /**
     * Preserve important fields from old product when replacing
     * 
     * @param array $new_product_data New product data
     * @param array $old_product Old product data
     * @return array Product data with preserved fields
     * @deprecated Use ProductManager::preserveProductFieldsOnReplacement() instead
     */
    private function preserve_product_fields_on_replacement( $new_product_data, $old_product ) {
        // Delegate to ProductManager for consistency
        return \AEBG\Core\ProductManager::preserveProductFieldsOnReplacement($new_product_data, $old_product);
    }

    private function get_merchant_counts_from_database($post_id, $products) {
        global $wpdb;
        
        $merchant_counts = [];
        $table_name = $wpdb->prefix . 'aebg_comparisons';
        
        // Only log in verbose debug mode to reduce log noise
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'AEBG_VERBOSE_DEBUG' ) && AEBG_VERBOSE_DEBUG ) {
            error_log('[AEBG] Getting merchant counts from database for post ' . $post_id . ' with ' . count($products) . ' products');
        }
        
        foreach ($products as $product) {
            $product_id = $product['id'] ?? '';
            if (empty($product_id)) {
                continue;
            }
            
            // Debug: Check if this product exists in database (only in debug mode)
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $check_query = $wpdb->prepare(
                "SELECT COUNT(*) as count FROM $table_name WHERE product_id = %s AND status = 'active'",
                $product_id
            );
            $product_count = $wpdb->get_var($check_query);
            }
            
            // Simple query: find ANY comparison data for this product
            $query = $wpdb->prepare(
                "SELECT comparison_data FROM $table_name WHERE product_id = %s AND status = 'active' ORDER BY updated_at DESC LIMIT 1",
                $product_id
            );
            
            $result = $wpdb->get_row($query, ARRAY_A);
            
                                if ($result && !empty($result['comparison_data'])) {
            $data = json_decode($result['comparison_data'], true);
            // error_log('[AEBG] 📊 PAGE LOAD: Raw comparison data for product ' . $product_id . ': ' . print_r($data, true));
            
            // Normalize data structure for consistent processing
            $data = $this->normalize_merchant_data_structure($data);
                
                if ($data && isset($data['merchants']) && is_array($data['merchants']) && !empty($data['merchants'])) {
                    // Calculate merchant count and price range from stored comparison data
                    $merchants = [];
                    $prices = [];
                
                foreach ($data['merchants'] as $merchant_name => $merchant_data) {
                    if (is_array($merchant_data)) {
                        // Extract price from various possible fields
                        $price = 0;
                        if (isset($merchant_data['price'])) {
                            $price = floatval($merchant_data['price']);
                        } elseif (isset($merchant_data['lowest_price'])) {
                            $price = floatval($merchant_data['lowest_price']);
                        }
                        
                        if ($price > 0) {
                            $merchants[$merchant_name] = $merchant_data;
                            $prices[] = $price;
                        }
                    }
                }
                    
                    $merchant_count = count($merchants);
                    $lowest_price = !empty($prices) ? min($prices) : 0;
                    $highest_price = !empty($prices) ? max($prices) : 0;
                    
                    // Find merchants with lowest and highest prices
                    $lowest_merchant_name = 'Unknown';
                    $highest_merchant_name = 'Unknown';
                    
                    // Process normalized merchant data structure
                    foreach ($data['merchants'] as $merchant_name => $merchant_data) {
                        if (is_array($merchant_data)) {
                            $price = 0;
                            if (isset($merchant_data['price'])) {
                                $price = floatval($merchant_data['price']);
                            } elseif (isset($merchant_data['lowest_price'])) {
                                $price = floatval($merchant_data['lowest_price']);
                            }
                            
                            if ($price == $lowest_price) {
                                $lowest_merchant_name = $merchant_name;
                            }
                            if ($price == $highest_price) {
                                $highest_merchant_name = $merchant_name;
                            }
                        }
                    }
                    
                    $merchant_counts[$product_id] = [
                        'merchant_count' => $merchant_count,
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
                } else {
                    $merchant_counts[$product_id] = [
                        'merchant_count' => 1,
                        'price_range' => [
                            'lowest' => floatval($product['price'] ?? 0),
                            'highest' => floatval($product['price'] ?? 0)
                        ],
                        'merchants' => [
                            'lowest_price' => [
                                'name' => $product['merchant'] ?? 'Unknown',
                                'price' => floatval($product['price'] ?? 0)
                            ],
                            'highest_price' => [
                                'name' => $product['merchant'] ?? 'Unknown',
                                'price' => floatval($product['price'] ?? 0)
                            ]
                        ]
                    ];
                }
            } else {
                $merchant_counts[$product_id] = [
                    'merchant_count' => 1,
                    'price_range' => [
                        'lowest' => floatval($product['price'] ?? 0),
                        'highest' => floatval($product['price'] ?? 0)
                    ],
                    'merchants' => [
                        'lowest_price' => [
                            'name' => $product['merchant'] ?? 'Unknown',
                            'price' => floatval($product['price'] ?? 0)
                        ],
                        'highest_price' => [
                            'name' => $product['merchant'] ?? 'Unknown',
                            'price' => floatval($product['price'] ?? 0)
                        ]
                    ]
                ];
            }
        }
        
        // Only log summary in verbose debug mode to reduce log noise
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'AEBG_VERBOSE_DEBUG' ) && AEBG_VERBOSE_DEBUG ) {
            error_log('[AEBG] Completed loading merchant counts for ' . count($merchant_counts) . ' products from database');
        }
        
        return $merchant_counts;
    }

    /**
     * Get comparison data for a specific product from database
     * 
     * @param string $product_id Product ID
     * @param int $post_id Post ID
     * @return array|null Comparison data or null if not found
     */
    private function get_comparison_data_for_product($product_id, $post_id) {
        global $wpdb;
        
        $user_id = get_current_user_id();
        $table_name = $wpdb->prefix . 'aebg_comparisons';
        
        error_log('[AEBG] 🔍 MODAL: Getting comparison data for product ' . $product_id . ' from database');
        
        $query = $wpdb->prepare(
            "SELECT comparison_data FROM $table_name WHERE user_id = %d AND post_id = %d AND product_id = %s AND status = 'active' ORDER BY updated_at DESC LIMIT 1",
            $user_id,
            $post_id,
            $product_id
        );
        
        $result = $wpdb->get_var($query);
        
        if ($result) {
            $comparison_data = json_decode($result, true);
            if ($comparison_data && isset($comparison_data['merchants'])) {
                // Normalize data structure for consistent processing
                $comparison_data = $this->normalize_merchant_data_structure($comparison_data);
                error_log('[AEBG] ✅ MODAL: Found comparison data for product ' . $product_id . ' with ' . count($comparison_data['merchants']) . ' merchants');
                return $comparison_data;
            }
        }
        
        error_log('[AEBG] 📭 MODAL: No comparison data found for product ' . $product_id);
        return null;
    }

    /**
     * Render comparison table HTML for a product
     * 
     * @param array $comparison_data Comparison data from database
     * @param array $product Product data
     * @return string HTML for the comparison table
     */
    private function render_comparison_table_html($comparison_data, $product) {
        if (!$comparison_data || !isset($comparison_data['merchants']) || empty($comparison_data['merchants'])) {
            return '<tr><td colspan="8" class="text-center">No merchant data available</td></tr>';
        }
        
        $html = '';
        $merchants = $comparison_data['merchants'];
        
        foreach ($merchants as $merchant_name => $merchant) {
            $is_selected = ($merchant_name === ($product['merchant'] ?? ''));
            $selected_class = $is_selected ? 'selected' : '';
            $rating = $merchant['rating'] ?? $merchant['average_rating'] ?? 0;
            $stars = $this->generate_stars($rating);
            $availability = $merchant['availability'] ?? 'in_stock';
            $network = $merchant['network'] ?? $merchant['network_name'] ?? $merchant['network_info'] ?? 'Unknown';
            $price = $merchant['price'] ?? $merchant['lowest_price'] ?? 0;
            $url = $merchant['url'] ?? '';
            
            // Make product name clickable if URL is available
            $product_name_cell = '';
            
            // CRITICAL FIX: Use merchant-specific product name if available, otherwise fall back to main product name
            $display_product_name = '';
            if (isset($merchant['product_name']) && !empty($merchant['product_name'])) {
                // Use merchant's specific product name
                $display_product_name = $merchant['product_name'];
            } elseif (isset($merchant['name']) && !empty($merchant['name']) && $merchant['name'] !== $merchant_name) {
                // Use merchant's name if it's different from merchant name (likely a product name)
                $display_product_name = $merchant['name'];
            } elseif (isset($merchant['title']) && !empty($merchant['title'])) {
                // Use merchant's title if available
                $display_product_name = $merchant['title'];
            } else {
                // Fall back to main product name
                $display_product_name = $product['name'] ?? 'Unknown Product';
            }
            
            if ($url) {
                $product_name_cell = '<a href="' . esc_url($url) . '" target="_blank" class="aebg-product-link" title="View product on ' . esc_attr($merchant_name) . '">' . esc_html($display_product_name) . ' <span class="dashicons dashicons-external"></span></a>';
            } else {
                $product_name_cell = esc_html($display_product_name);
            }
            
            // Get currency from merchant or product data
            $currency = $merchant['currency'] ?? $product['currency'] ?? 'DKK';
            
            $html .= '
                <tr class="' . $selected_class . ' aebg-comparison-row" data-product-id="' . esc_attr($merchant_name) . '" data-merchant="' . esc_attr($merchant_name) . '">
                    <td class="aebg-td-drag">
                        <span class="aebg-drag-handle" title="Drag to reorder">⋮⋮</span>
                    </td>
                    <td>' . esc_html($merchant_name) . '</td>
                    <td class="aebg-product-name-cell">' . $product_name_cell . '</td>
                    <td>' . $this->format_price($price, $currency) . '</td>
                    <td>' . esc_html($network) . '</td>
                    <td>' . $stars . ' ' . number_format($rating, 1) . '/5</td>
                    <td>' . ($availability === 'in_stock' ? 'In Stock' : 'Out of Stock') . '</td>
                    <td>';
            
            if ($url) {
                $html .= '
                        <button type="button" class="aebg-btn-view-product" data-product-url="' . esc_attr($url) . '" title="View product on merchant website">
                            <span class="dashicons dashicons-external"></span>
                            View
                        </button>';
            }
            
            $html .= '
                        <button type="button" class="aebg-btn-remove-from-comparison" data-product-id="' . esc_attr($merchant_name) . '">
                            <span class="dashicons dashicons-trash"></span>
                            Remove
                        </button>
                    </td>
                </tr>';
        }
        
        return $html;
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'aebg_products_meta_box', 'aebg_products_meta_box_nonce' );
        $products = get_post_meta( $post->ID, '_aebg_products', true );
        $product_ids = get_post_meta( $post->ID, '_aebg_product_ids', true );
        
        // Ensure product_ids is an array
        if ( ! is_array( $product_ids ) ) {
            $product_ids = [];
        }
        
        // Get merchant counts from database for all products
        $merchant_counts = [];
        
        if ( ! empty( $products ) && is_array( $products ) ) {
            $merchant_counts = $this->get_merchant_counts_from_database( $post->ID, $products );
        }
        
        ?>
        <div id="aebg-products-container">
            <!-- Associated Products Table -->
            <div class="aebg-associated-products-container">
                <div class="aebg-associated-products-header">
                    <h3>
                        <span class="aebg-icon">📦</span>
                        Associated Products
                    </h3>
                    <div class="aebg-products-count"><?php echo count( $product_ids ); ?> product<?php echo count( $product_ids ) !== 1 ? 's' : ''; ?></div>
                </div>
                
                <table class="aebg-associated-products-table">
                    <thead>
                        <tr>
                            <th class="aebg-th-drag"></th>
                            <th class="aebg-th-image">Image</th>
                            <th class="aebg-th-name">Product Name</th>
                            <th class="aebg-th-price">Price</th>
                            <th class="aebg-th-brand">Brand</th>
                            <th class="aebg-th-network">Network</th>
                            <th class="aebg-th-merchant">Merchant</th>
                            <th class="aebg-th-rating">Rating</th>
                            <th class="aebg-th-merchants">
                                Merchants
                                <button type="button" id="aebg-refresh-merchants" class="aebg-refresh-btn" title="Refresh merchant information">
                                    🔄
                                </button>
                            </th>
                            <th class="aebg-th-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( ! empty( $products ) && is_array( $products ) ) : ?>
                            <?php foreach ( $products as $index => $product ) : ?>
                                <tr class="aebg-product-row" data-product-id="<?php echo esc_attr( $product['id'] ?? $index ); ?>" data-product-number="<?php echo esc_attr( $index + 1 ); ?>">
                                    <td class="aebg-td-drag">
                                        <span class="aebg-drag-handle">⋮⋮</span>
                                    </td>
                                    <td class="aebg-td-image">
                                        <?php 
                                        $image_url = $this->get_product_image_url( $product );
                                        
                                        if ( ! empty( $image_url ) ) : ?>
                                            <img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $product['name'] ?? 'Product' ); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                            <div class="aebg-no-image" style="display: none;">No Image</div>
                                        <?php else : ?>
                                            <div class="aebg-no-image">No Image</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="aebg-td-name">
                                        <div class="aebg-product-name">
                                            <strong class="aebg-product-name-text" data-product-id="<?php echo esc_attr($product['id']); ?>" contenteditable="true" title="<?php echo esc_attr($product['display_name'] ?? $product['name']) !== $product['name'] ? 'Original name: ' . esc_attr($product['name']) . ' - Click to edit' : 'Click to edit product name'; ?>"><?php echo esc_html($product['display_name'] ?? $product['name']); ?></strong>
                                            <?php if (($product['display_name'] ?? $product['name']) !== $product['name']) : ?>
                                                <span class="aebg-original-name-indicator" title="Renamed from: <?php echo esc_attr($product['name']); ?>">✏️</span>
                                            <?php endif; ?>
                                            <button type="button" class="aebg-edit-name-btn" title="Edit product name" style="display: none;">
                                                <span class="dashicons dashicons-edit"></span>
                                            </button>
                                            <button type="button" class="aebg-save-name-btn" title="Save changes" style="display: none;">
                                                <span class="dashicons dashicons-yes"></span>
                                            </button>
                                            <button type="button" class="aebg-cancel-name-btn" title="Cancel editing" style="display: none;">
                                                <span class="dashicons dashicons-no"></span>
                                            </button>
                                        </div>
                                        <?php if (!empty($product['description'])) : ?>
                                            <div class="aebg-product-description"><?php echo esc_html(substr($product['description'], 0, 100)); ?><?php echo strlen($product['description']) > 100 ? '...' : ''; ?></div>
                                        <?php endif; ?>
                                        <div class="aebg-product-meta">
                                            <span>💰 <?php 
                                                $product_currency = $product['currency'] ?? 'DKK';
                                                echo $this->format_price($product['price'], $product_currency); 
                                            ?></span>
                                            <span>🏷️ <?php echo esc_html($product['brand'] ?? 'Unknown'); ?></span>
                                            <span>⭐ <?php echo esc_html($product['rating'] ?? '0'); ?>/5</span>
                                        </div>
                                    </td>
                                    <td class="aebg-td-price">
                                        <div class="aebg-price-range-display" data-product-id="<?php echo esc_attr( $product['id'] ?? $index ); ?>">
                                            <div class="aebg-price-lowest">
                                                <span class="aebg-price-label">Lowest:</span>
                                                <span class="aebg-price-value"><?php 
                                                    $product_id = $product['id'] ?? $index;
                                                    // error_log('[AEBG] 🎨 TEMPLATE: Rendering lowest price for product_id: ' . $product_id);
                                                    $lowest_price = $merchant_counts[$product_id]['price_range']['lowest'] ?? $product['price'] ?? 0;
                                                    $lowest_merchant = $merchant_counts[$product_id]['merchants']['lowest_price']['name'] ?? 'Unknown';
                                                    $product_currency = $product['currency'] ?? 'DKK';
                                                    echo esc_html( $this->format_price( $lowest_price, $product_currency ) );
                                                ?></span>
                                                <div class="aebg-merchant-name"><?php echo esc_html( $lowest_merchant ); ?></div>
                                            </div>
                                            <div class="aebg-price-highest">
                                                <span class="aebg-price-label">Highest:</span>
                                                <span class="aebg-price-value"><?php 
                                                    $product_id = $product['id'] ?? $index;
                                                    $highest_price = $merchant_counts[$product_id]['price_range']['highest'] ?? $product['price'] ?? 0;
                                                    $highest_merchant = $merchant_counts[$product_id]['merchants']['highest_price']['name'] ?? 'Unknown';
                                                    $product_currency = $product['currency'] ?? 'DKK';
                                                    echo esc_html( $this->format_price( $highest_price, $product_currency ) );
                                                ?></span>
                                                <div class="aebg-merchant-name"><?php echo esc_html( $highest_merchant ); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="aebg-td-brand">
                                        <span class="aebg-brand"><?php echo esc_html( $product['brand'] ?? 'N/A' ); ?></span>
                                    </td>
                                    <td class="aebg-td-network">
                                        <span class="aebg-network"><?php echo esc_html( $product['network'] ?? $product['network_name'] ?? 'N/A' ); ?></span>
                                    </td>
                                    <td class="aebg-td-merchant">
                                        <span class="aebg-merchant"><?php echo esc_html( $product['merchant'] ?? 'N/A' ); ?></span>
                                    </td>
                                    <td class="aebg-td-rating">
                                        <div class="aebg-rating" data-product-id="<?php echo esc_attr( $product['id'] ?? $index ); ?>">
                                            <div class="aebg-rating-editor">
                                                <div class="aebg-stars-container">
                                                    <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                                                        <span class="aebg-star" data-rating="<?php echo $i; ?>">☆</span>
                                                    <?php endfor; ?>
                                                </div>
                                                <input type="number" class="aebg-rating-input" value="<?php echo esc_attr( $product['rating'] ?? 0 ); ?>" min="0" max="5" step="0.1" />
                                                <span class="aebg-rating-slash">/5</span>
                                            </div>
                                            <div class="aebg-rating-display">
                                                <div class="aebg-stars"><?php echo $this->generate_stars( $product['rating'] ?? 0 ); ?></div>
                                                <div class="aebg-rating-text">
                                                    <?php echo esc_html( $product['rating'] ?? '0' ); ?>/5
                                                    <span class="aebg-edit-icon" title="Click to edit rating">✏️</span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="aebg-td-merchants">
                                        <div class="aebg-merchants-info" data-product-id="<?php echo esc_attr( $product['id'] ?? $index ); ?>">
                                            <div class="aebg-merchants-count">
                                                <span class="aebg-merchants-icon">🏪</span>
                                                <span class="aebg-merchants-number"><?php 
                                                    // Use the actual product ID if available, otherwise fall back to index
                                                    $product_id = $product['id'] ?? $index;
                                                    
                                                    // Try to find merchant count using the actual product ID first
                                                    $merchant_count = 1; // Default fallback
                                                    
                                                    if (!empty($product['id']) && isset($merchant_counts[$product['id']])) {
                                                        // Use the actual product ID
                                                        $merchant_count = $merchant_counts[$product['id']]['merchant_count'] ?? 1;
                                                        // error_log('[AEBG] 🎨 TEMPLATE: Found merchant count using product[id]: ' . $product['id'] . ' = ' . $merchant_count);
                                                    } elseif (isset($merchant_counts[$index])) {
                                                        // Fall back to index if product ID not found
                                                        $merchant_count = $merchant_counts[$index]['merchant_count'] ?? 1;
                                                        // error_log('[AEBG] 🎨 TEMPLATE: Found merchant count using index: ' . $index . ' = ' . $merchant_count);
                                                    } else {
                                                        // No merchant count found, check if we have comparison data
                                                        // error_log('[AEBG] 🎨 TEMPLATE: No merchant count found for product_id: ' . $product_id . ' (product[id]: ' . ($product['id'] ?? 'NULL') . ', index: ' . $index . ')');
                                                        // error_log('[AEBG] 🎨 TEMPLATE: Available merchant_counts keys: ' . implode(', ', array_keys($merchant_counts)));
                                                        
                                                        // Try to get merchant count from database directly
                                                        $direct_merchant_count = $this->get_direct_merchant_count($product['id'] ?? $index);
                                                        if ($direct_merchant_count > 0) {
                                                            $merchant_count = $direct_merchant_count;
                                                            // error_log('[AEBG] 🎨 TEMPLATE: Got direct merchant count: ' . $merchant_count);
                                                        }
                                                    }
                                                    
                                                    // error_log('[AEBG] 🎨 TEMPLATE: Final merchant count for product ' . $product_id . ': ' . $merchant_count);
                                                    echo esc_html( $merchant_count );
                                                ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="aebg-td-actions">
                                        <div class="aebg-actions">
                                            <button type="button" class="aebg-btn-remove" data-product-id="<?php echo esc_attr( $product['id'] ?? $index ); ?>" title="Remove product">
                                                Remove
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Add Products Section -->
            <div class="aebg-add-products-section">
                <div class="aebg-add-products-header">
                    <h4>
                        <span class="aebg-icon">➕</span>
                        Add More Products
                    </h4>
                    <p>Search for products to add to this post</p>
                    <button type="button" class="aebg-toggle-search" id="main-toggle-search">
                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                        Show Search Filters
                    </button>
                </div>
                
                <div class="aebg-search-filters" id="main-search-filters" style="display: none;">
                    <div class="aebg-search-row">
                        <div class="aebg-search-field">
                            <label for="main-search-name">Product Name</label>
                            <input type="text" id="main-search-name" placeholder="Enter product name..." />
                        </div>
                        <div class="aebg-search-field">
                            <label for="main-search-brand">Brand</label>
                            <input type="text" id="main-search-brand" placeholder="Enter brand name..." />
                        </div>
                        <div class="aebg-search-field">
                            <label for="main-search-currency">Currency</label>
                            <select id="main-search-currency">
                                <option value="">All Currencies</option>
                                <option value="DKK">DKK (Danish Krone)</option>
                                <option value="USD">USD (US Dollar)</option>
                                <option value="EUR">EUR (Euro)</option>
                                <option value="GBP">GBP (British Pound)</option>
                                <option value="SEK">SEK (Swedish Krona)</option>
                                <option value="NOK">NOK (Norwegian Krone)</option>
                                <option value="CHF">CHF (Swiss Franc)</option>
                                <option value="PLN">PLN (Polish Złoty)</option>
                                <option value="CZK">CZK (Czech Koruna)</option>
                                <option value="HUF">HUF (Hungarian Forint)</option>
                                <option value="RON">RON (Romanian Leu)</option>
                                <option value="BGN">BGN (Bulgarian Lev)</option>
                                <option value="HRK">HRK (Croatian Kuna)</option>
                                <option value="RSD">RSD (Serbian Dinar)</option>
                                <option value="UAH">UAH (Ukrainian Hryvnia)</option>
                                <option value="RUB">RUB (Russian Ruble)</option>
                                <option value="TRY">TRY (Turkish Lira)</option>
                                <option value="ILS">ILS (Israeli Shekel)</option>
                                <option value="ZAR">ZAR (South African Rand)</option>
                                <option value="BRL">BRL (Brazilian Real)</option>
                                <option value="MXN">MXN (Mexican Peso)</option>
                                <option value="CAD">CAD (Canadian Dollar)</option>
                                <option value="AUD">AUD (Australian Dollar)</option>
                                <option value="NZD">NZD (New Zealand Dollar)</option>
                                <option value="JPY">JPY (Japanese Yen)</option>
                                <option value="CNY">CNY (Chinese Yuan)</option>
                                <option value="INR">INR (Indian Rupee)</option>
                                <option value="KRW">KRW (South Korean Won)</option>
                                <option value="SGD">SGD (Singapore Dollar)</option>
                                <option value="HKD">HKD (Hong Kong Dollar)</option>
                                <option value="THB">THB (Thai Baht)</option>
                                <option value="MYR">MYR (Malaysian Ringgit)</option>
                                <option value="IDR">IDR (Indonesian Rupiah)</option>
                                <option value="PHP">PHP (Philippine Peso)</option>
                                <option value="VND">VND (Vietnamese Dong)</option>
                            </select>
                        </div>
                    </div>
                    <div class="aebg-search-row">
                        <div class="aebg-search-field">
                            <label for="main-search-networks">Networks</label>
                            <?php include_once(plugin_dir_path(__FILE__) . 'views/modern-network-selector.php'); ?>
                        </div>
                        <div class="aebg-search-field">
                            <label for="main-search-category">Category</label>
                            <input type="text" id="main-search-category" placeholder="Enter category..." />
                        </div>
                        <div class="aebg-search-field">
                            <label for="main-search-rating">Min Rating</label>
                            <select id="main-search-rating">
                                <option value="">Any Rating</option>
                                <option value="1">1+ Stars</option>
                                <option value="2">2+ Stars</option>
                                <option value="3">3+ Stars</option>
                                <option value="4">4+ Stars</option>
                                <option value="5">5 Stars Only</option>
                            </select>
                        </div>
                    </div>
                    <div class="aebg-search-row">
                        <div class="aebg-search-field">
                            <label for="main-search-min-price">Min Price</label>
                            <input type="number" id="main-search-min-price" placeholder="0" step="0.01" />
                        </div>
                        <div class="aebg-search-field">
                            <label for="main-search-max-price">Max Price</label>
                            <input type="number" id="main-search-max-price" placeholder="999999" step="0.01" />
                        </div>
                        <div class="aebg-search-field">
                            <label for="main-search-limit">Results per page</label>
                            <select id="main-search-limit">
                                <option value="20">20</option>
                                <option value="50" selected>50</option>
                                <option value="100">100</option>
                            </select>
                        </div>
                        <div class="aebg-search-field checkbox-field">
                            <input type="checkbox" id="main-search-has-image" />
                            <label for="main-search-has-image">Has Image Only</label>
                        </div>
                        <div class="aebg-search-field">
                            <label for="main-search-sort">Sort By</label>
                            <select id="main-search-sort">
                                <option value="relevance">Relevance</option>
                                <option value="price_asc">Price: Low to High</option>
                                <option value="price_desc">Price: High to Low</option>
                                <option value="rating_desc">Rating: High to Low</option>
                                <option value="name_asc">Name: A to Z</option>
                            </select>
                        </div>
                        <div class="aebg-search-field checkbox-field">
                            <input type="checkbox" id="main-search-in-stock" />
                            <label for="main-search-in-stock">In Stock Only</label>
                        </div>
                    </div>
                    <div class="aebg-search-actions">
                        <button type="button" class="aebg-btn-search" id="main-search-products">
                            <span class="dashicons dashicons-search"></span>
                            Search Products
                        </button>
                        <button type="button" class="aebg-btn-clear" id="main-clear-search">
                            <span class="dashicons dashicons-dismiss"></span>
                            Clear
                        </button>
                    </div>
                </div>
                
                <div class="aebg-search-container">
                    <input type="text" class="aebg-search-input" placeholder="Search for products..." />
                    <div class="aebg-search-results">
                        <!-- Search results will be populated here -->
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Include Merchant Comparison Modal -->
        <?php 
        // Pass products data to the modal template
        $modal_products = $products;
        include plugin_dir_path(__FILE__) . 'views/merchant-comparison-modal.php';
        include plugin_dir_path(__FILE__) . 'views/reorder-conflict-modal.php'; 
        ?>
        
        <!-- Pass products data to JavaScript -->
        <script>
            window.aebgProducts = <?php echo json_encode($products); ?>;
            console.log('Products data passed to JavaScript:', window.aebgProducts);
        </script>
        
        <?php
    }

    public function save_meta_box( $post_id ) {
        if ( ! isset( $_POST['aebg_products_meta_box_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( $_POST['aebg_products_meta_box_nonce'], 'aebg_products_meta_box' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        if ( ! isset( $_POST['aebg_product_ids'] ) ) {
            return;
        }
        
        $new_product_ids = explode( ',', sanitize_text_field( $_POST['aebg_product_ids'] ) );
        $new_product_ids = array_map( 'intval', $new_product_ids );
        $new_product_ids = array_filter( $new_product_ids ); // Remove empty values
        
        // Get the current product IDs to compare
        $current_product_ids = get_post_meta( $post_id, '_aebg_product_ids', true );
        if ( ! is_array( $current_product_ids ) ) {
            $current_product_ids = [];
        }
        
        // Check if the order has changed
        $order_changed = false;
        if ( count( $new_product_ids ) !== count( $current_product_ids ) ) {
            $order_changed = true;
        } else {
            foreach ( $new_product_ids as $index => $product_id ) {
                if ( ! isset( $current_product_ids[ $index ] ) || $current_product_ids[ $index ] != $product_id ) {
                    $order_changed = true;
                    break;
                }
            }
        }
        
        // Update the product IDs
        update_post_meta( $post_id, '_aebg_product_ids', $new_product_ids );
        
        // If the order has changed, update the Elementor data
        if ( $order_changed ) {
            error_log( '[AEBG] Product order changed for post ' . $post_id . ', updating Elementor data' );
            
            try {
                // Get the current products data
                $current_products = get_post_meta( $post_id, '_aebg_products', true );
                if ( ! is_array( $current_products ) ) {
                    $current_products = [];
                }
                
                // Create a mapping of product IDs to product data
                $products_by_id = [];
                foreach ( $current_products as $product ) {
                    $product_id = is_array( $product ) ? ( $product['id'] ?? '' ) : $product;
                    if ( $product_id ) {
                        $products_by_id[ $product_id ] = $product;
                    }
                }
                
                // Create the new products array in the correct order
                $reordered_products = [];
                foreach ( $new_product_ids as $product_id ) {
                    if ( isset( $products_by_id[ $product_id ] ) ) {
                        $reordered_products[] = $products_by_id[ $product_id ];
                    }
                }
                
                // Update the products meta
                update_post_meta( $post_id, '_aebg_products', $reordered_products );
                
                        // Update the Elementor data using TemplateManager
        $template_manager = new TemplateManager();
        $result = $template_manager->updatePostProductsWithOrder( $post_id, $new_product_ids );
                
                if ( is_wp_error( $result ) ) {
                    error_log( '[AEBG] Failed to update Elementor data: ' . $result->get_error_message() );
                } else {
                    error_log( '[AEBG] Successfully updated Elementor data for post ' . $post_id );
                }
                
            } catch ( Exception $e ) {
                error_log( '[AEBG] Exception while updating Elementor data: ' . $e->getMessage() );
            }
        }
    }

    /**
     * Get the best available image URL for a product
     * 
     * @param array $product Product data
     * @return string Image URL or empty string
     */
    private function get_product_image_url( $product ) {
        // Check for various image field names that might be available
        $image_url = $product['image_url'] ?? $product['image'] ?? $product['featured_image_url'] ?? $product['featured_image'] ?? '';
        
        // If we have an attachment ID, get the URL
        if ( empty( $image_url ) && ! empty( $product['featured_image_id'] ) ) {
            $image_url = wp_get_attachment_url( $product['featured_image_id'] );
        }
        
        return $image_url;
    }

    /**
     * Format price for display in Danish currency
     * 
     * @param mixed $price Price value
     * @return string Formatted price
     */
    private function format_price( $price, $currency = 'DKK' ) {
        if ( empty( $price ) || $price === 'N/A' ) {
            return 'N/A';
        }
        
        $price_value = floatval( $price );
        if ( $price_value <= 0 ) {
            return 'N/A';
        }
        
        // Use ProductManager::formatPrice which handles all currencies properly
        // API returns proper decimal values, so no conversion needed
        return \AEBG\Core\ProductManager::formatPrice( $price_value, $currency );
    }

    /**
     * Generate star rating display
     */
    private function generate_stars( $rating ) {
        $rating = floatval( $rating );
        $full_stars = floor( $rating );
        $has_half_star = ( $rating % 1 ) >= 0.5;
        $empty_stars = 5 - $full_stars - ( $has_half_star ? 1 : 0 );
        
        $stars = '';
        for ( $i = 0; $i < $full_stars; $i++ ) {
            $stars .= '★';
        }
        if ( $has_half_star ) {
            $stars .= '☆';
        }
        for ( $i = 0; $i < $empty_stars; $i++ ) {
            $stars .= '☆';
        }
        
        return $stars;
    }

    /**
     * AJAX handler to save product data
     */
    public function ajax_save_product_data() {
        error_log('[AEBG] ===== ajax_save_product_data CALLED =====');
        // Verify nonce
        if ( ! check_ajax_referer( 'aebg_ajax_nonce', 'nonce', false ) ) {
            error_log('[AEBG] Security check failed in ajax_save_product_data');
            wp_send_json_error( 'Security check failed' );
        }

        // Check permissions
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        // Validate and sanitize required parameters
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        $product_data = isset( $_POST['product_data'] ) ? $_POST['product_data'] : null;
        $product_number = isset( $_POST['product_number'] ) ? intval( $_POST['product_number'] ) : null;

        // Enhanced parameter validation
        if ( ! $post_id || $post_id <= 0 ) {
            wp_send_json_error( 'Invalid post ID provided.' );
        }

        // Handle product_data - it might come as a JSON string or as an array
        if ( $product_data === null ) {
            wp_send_json_error( 'Product data is required.' );
        }
        
        // If product_data is a string, try to decode it as JSON
        if ( is_string( $product_data ) ) {
            $decoded = json_decode( $product_data, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                $product_data = $decoded;
            } else {
                error_log( '[AEBG] Failed to decode product_data JSON: ' . json_last_error_msg() );
                wp_send_json_error( 'Invalid product data format (JSON decode failed).' );
            }
        }
        
        if ( ! is_array( $product_data ) ) {
            error_log( '[AEBG] Product data is not an array. Type: ' . gettype( $product_data ) );
            wp_send_json_error( 'Invalid product data provided (expected array).' );
        }

        // Validate post exists
        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( 'Post not found.' );
        }

        // Only log in debug mode
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log('[AEBG] Post ID: ' . $post_id);
        error_log('[AEBG] Product number: ' . ($product_number !== null ? $product_number : 'null'));
        error_log('[AEBG] Product data keys: ' . (is_array($product_data) ? json_encode(array_keys($product_data)) : 'not array'));
        }

        // Ensure product has required fields
        if ( empty( $product_data['name'] ) ) {
            error_log('[AEBG] ERROR: Product data missing name field');
            wp_send_json_error( 'Product name is required.' );
        }
        
        // Ensure product has an ID field (create one if missing)
        if ( empty( $product_data['id'] ) ) {
            $product_data['id'] = 'product_' . time() . '_' . rand(1000, 9999);
            error_log('[AEBG] Generated product ID: ' . $product_data['id']);
        }

        // Get existing products once (cached for performance)
        $existing_products = get_post_meta( $post_id, '_aebg_products', true );
        if ( ! is_array( $existing_products ) ) {
            $existing_products = [];
        }

        // Normalize product data before validation (using ProductManager for consistency)
        $product_data = \AEBG\Core\ProductManager::normalizeProductData( $product_data, $post_id, $existing_products );

        // Only log in debug mode
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log('[AEBG] Existing products count: ' . count($existing_products));
            error_log('[AEBG] Normalized product data - currency: ' . ( $product_data['currency'] ?? 'missing' ) . ', network: ' . ( $product_data['network'] ?? 'missing' ) . ', image_url: ' . ( ! empty( $product_data['image_url'] ) ? 'present' : 'missing' ));
        }

        $is_new_product = false;
        $is_replacement = false;

        if ( $product_number !== null ) {
            // Insert at specific position (product_number is 1-based, array is 0-based)
            $product_index = $product_number - 1;
            
            // Check if this is a replacement (product already exists at this position)
            if ( isset( $existing_products[ $product_index ] ) ) {
                $is_replacement = true;
                $old_product = $existing_products[ $product_index ];
                
                // Only log in debug mode
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[AEBG] Replacing product at position ' . $product_number . ' (index ' . $product_index . ')' );
                    error_log( '[AEBG] Old product ID: ' . ( $old_product['id'] ?? 'missing' ) );
                    error_log( '[AEBG] New product ID: ' . ( $product_data['id'] ?? 'missing' ) );
                }
                
                // For replacements, preserve important fields from old product if missing in new product
                // This ensures currency, network, and other metadata are preserved
                $product_data = \AEBG\Core\ProductManager::preserveProductFieldsOnReplacement( $product_data, $old_product );
                
                // Track product replacement
                \AEBG\Core\UsageTracker::record_product_replacement([
                    'post_id' => $post_id,
                    'user_id' => get_current_user_id(),
                    'old_product_id' => $old_product['id'] ?? null,
                    'old_product_name' => $old_product['name'] ?? null,
                    'new_product_id' => $product_data['id'] ?? null,
                    'new_product_name' => $product_data['name'] ?? null,
                    'product_number' => $product_number,
                    'replacement_type' => 'manual',
                ]);
                
                // For replacements, we need to ensure the array is properly indexed
                // and the old product is replaced at the exact same position
                $existing_products[ $product_index ] = $product_data;
                
                // DO NOT re-index the array - preserve the original structure exactly
                // This is crucial for replacements to work correctly
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[AEBG] Replaced product at index ' . $product_index . ' - preserving array structure' );
                }
                
            } else {
                $is_new_product = true;
                
                // Ensure array is large enough
                while ( count( $existing_products ) <= $product_index ) {
                    $existing_products[] = null;
                }
                
                // Insert the product at the specified position
                $existing_products[ $product_index ] = $product_data;
                
                // Filter out null values only (not all falsy values)
                $existing_products = array_filter( $existing_products, function($product) {
                    return $product !== null;
                });
                
                // Re-index the array to ensure proper sequential indexing
                $existing_products = array_values($existing_products);
            }
        } else {
            // Add to the end (for new additions)
            $existing_products[] = $product_data;
            $is_new_product = true;
            $product_number = count( $existing_products );
        }

        // Only log in debug mode
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log('[AEBG] Final products count: ' . count($existing_products));
        error_log('[AEBG] Is new product: ' . ($is_new_product ? 'yes' : 'no'));
        error_log('[AEBG] Is replacement: ' . ($is_replacement ? 'yes' : 'no'));
        }

        // Save using ProductManager with transaction-like behavior
        $transaction_result = \AEBG\Core\ProductTransactionManager::executeWithRollback(
            $post_id,
            $existing_products,
            function($post_id, $products) {
                return \AEBG\Core\ProductManager::savePostProducts($post_id, $products);
            }
        );

        if ( ! $transaction_result['success'] ) {
            error_log('[AEBG] ERROR: Failed to save product data via ProductManager: ' . ($transaction_result['error'] ?? 'Unknown error'));
            error_log('[AEBG] Post ID: ' . $post_id);
            wp_send_json_error( 'Failed to save product data: ' . ($transaction_result['error'] ?? 'Unknown error') );
            return; // Exit early on error
        }

        // CRITICAL: Regenerate AI content and update Elementor template for the specific product container
        // Do this BEFORE sending response to ensure product_number is passed correctly
        // Only update the container for the replaced/added product, not the entire template
        if ($is_replacement || $is_new_product) {
            error_log('[AEBG] ===== REGENERATING CONTENT FOR PRODUCT =====');
            error_log('[AEBG] Post ID: ' . $post_id);
            error_log('[AEBG] Product number: ' . $product_number);
            error_log('[AEBG] Products count: ' . count($existing_products));
            error_log('[AEBG] Is replacement: ' . ($is_replacement ? 'yes' : 'no'));
            error_log('[AEBG] Is new product: ' . ($is_new_product ? 'yes' : 'no'));
            
            // Verify product_number is valid
            if ($product_number === null || $product_number === 0) {
                error_log('[AEBG] ❌ ERROR: product_number is null or 0! Cannot regenerate content.');
            } else {
                // Get the product data for the replaced/added product
                $product_index = $product_number - 1;
                $product_data = isset($existing_products[$product_index]) ? $existing_products[$product_index] : null;
                
                if (!$product_data) {
                    error_log('[AEBG] ❌ ERROR: Product data not found at index ' . $product_index);
                } else {
                    // Get AI settings
                    $aebg_settings = get_option('aebg_settings', []);
                    $settings = [
                        'openai_api_key' => $aebg_settings['api_key'] ?? '',
                        'ai_model' => $aebg_settings['model'] ?? 'gpt-3.5-turbo'
                    ];
                    
                    // CRITICAL: Enrich the NEW product data BEFORE scheduling Action Scheduler
                    // This ensures the enriched product is saved to the database and available for the workflow
                    if (!empty($settings['openai_api_key'])) {
                        error_log('[AEBG] Enriching NEW product data before scheduling Action Scheduler...');
                        $generator = new \AEBG\Core\Generator($settings);
                        $enriched_product = $generator->enrichSingleProduct($product_data, $settings['openai_api_key'], $settings['ai_model']);
                        
                        // Update the product in the array and save to database
                        $existing_products[$product_index] = $enriched_product;
                        update_post_meta($post_id, '_aebg_products', $existing_products);
                        $product_data = $enriched_product; // Use enriched product for scheduling
                        error_log('[AEBG] ✅ NEW product enriched and saved to database before scheduling');
                    }
                    
                    // Feature flag: Use Action Scheduler for product replacement (default: true)
                    $use_action_scheduler = get_option('aebg_use_action_scheduler_for_replacements', true);
                    
                    // Track if Action Scheduler was successfully used
                    $action_scheduler_used = false;
                    $action_id = 0;
                    
                    if (empty($settings['openai_api_key'])) {
                        error_log('[AEBG] ⚠️ WARNING: OpenAI API key not configured. Skipping AI content regeneration, only updating variables.');
                        // Fallback: Only update variables if no API key
                        $elementor_update_result = \AEBG\Core\ProductManager::updateElementorTemplateVariablesForProduct($post_id, $existing_products, $product_number);
                        if (!$elementor_update_result) {
                            error_log('[AEBG] WARNING: Failed to update Elementor template variables');
                        } else {
                            error_log('[AEBG] ✅ Variables updated successfully (no AI regeneration)');
                        }
                    } elseif ($use_action_scheduler && class_exists('\AEBG\Core\ProductReplacementScheduler')) {
                        // NEW: Use Action Scheduler for optimized 3-step workflow
                        error_log('[AEBG] Using Action Scheduler for optimized product replacement workflow...');
                        
                        $action_id = \AEBG\Core\ProductReplacementScheduler::scheduleReplacement($post_id, $product_number, $product_data);
                        
                        if ($action_id > 0) {
                            $action_scheduler_used = true;
                            error_log('[AEBG] ✅ Product replacement scheduled via Action Scheduler (action_id=' . $action_id . ')');
                            error_log('[AEBG] ✅ AJAX response will be sent immediately. Regeneration will happen in background.');
                        } else {
                            error_log('[AEBG] ⚠️ WARNING: Failed to schedule Action Scheduler replacement, falling back to old method');
                        }
                    }
                    
                    // Fallback: Use old synchronous method ONLY if Action Scheduler is disabled, failed, or not available
                    // CRITICAL FIX: Skip old method if Action Scheduler was successfully used
                    if (!$action_scheduler_used && (empty($settings['openai_api_key']) || !$use_action_scheduler || !class_exists('\AEBG\Core\ProductReplacementScheduler'))) {
                        // CRITICAL: Regeneration can take 2-3 minutes. We need to:
                        // 1. Send AJAX response immediately (so frontend doesn't timeout)
                        // 2. Process regeneration in background using WordPress shutdown hook
                        // 3. This prevents AJAX timeout and database connection issues
                        
                        if (!empty($settings['openai_api_key'])) {
                            error_log('[AEBG] Using legacy shutdown hook method for product replacement...');
                            
                            // Schedule regeneration to run after AJAX response is sent
                            add_action('shutdown', function() use ($post_id, $product_number, $product_data, $settings, $existing_products) {
                                error_log('[AEBG] ===== BACKGROUND: Starting AI content regeneration (legacy method) =====');
                                error_log('[AEBG] Post ID: ' . $post_id . ', Product: ' . $product_number);
                                
                                // Set longer time limit for background processing
                                set_time_limit(300); // 5 minutes
                                
                                try {
                                    // Initialize Generator and regenerate AI content
                                    $generator = new \AEBG\Core\Generator($settings);
                                    $regeneration_result = $generator->regenerateProductContent($post_id, $product_number, $product_data);
                                    
                                    if (is_wp_error($regeneration_result)) {
                                        error_log('[AEBG] ❌ ERROR: AI content regeneration failed: ' . $regeneration_result->get_error_message());
                                        // Fallback: Try to update variables at least
                                        error_log('[AEBG] Falling back to variable update only...');
                                        $elementor_update_result = \AEBG\Core\ProductManager::updateElementorTemplateVariablesForProduct($post_id, $existing_products, $product_number);
                                        if (!$elementor_update_result) {
                                            error_log('[AEBG] WARNING: Variable update also failed');
                                        } else {
                                            error_log('[AEBG] ✅ Variables updated successfully (AI regeneration failed)');
                                        }
                                    } else {
                                        error_log('[AEBG] ✅ AI content regeneration completed successfully in background');
                                    }
                                } catch (\Exception $e) {
                                    error_log('[AEBG] ❌ EXCEPTION during background regeneration: ' . $e->getMessage());
                                }
                            }, 999); // High priority to run after other shutdown hooks
                            
                            error_log('[AEBG] ✅ Regeneration scheduled in background (legacy method). AJAX response will be sent immediately.');
                        }
                    }
                }
            }
        }

        // Return success response
        // CRITICAL: If Action Scheduler was used, indicate that replacement is scheduled and will happen in background
        $response_data = [
            'message' => $is_replacement ? 'Product updated successfully' : 'Product added successfully',
            'product_number' => $product_number,
            'is_new_product' => $is_new_product,
            'is_replacement' => $is_replacement,
            'total_products' => count($existing_products)
        ];
        
        // Add Action Scheduler status if replacement was scheduled
        if (isset($action_scheduler_used) && $action_scheduler_used && $action_id > 0) {
            $response_data['action_scheduler_scheduled'] = true;
            $response_data['action_id'] = $action_id;
            $response_data['message'] = $is_replacement 
                ? 'Product updated. Content regeneration is running in the background...' 
                : 'Product added. Content generation is running in the background...';
            $response_data['status'] = 'scheduled'; // Indicates background processing
        } else {
            $response_data['action_scheduler_scheduled'] = false;
            $response_data['status'] = 'completed'; // Immediate completion (no AI or variables only)
        }
        
        wp_send_json_success( $response_data );
    }

    /**
     * AJAX handler to regenerate content for a specific product
     */
    public function ajax_regenerate_content() {
        // Verify nonce
        if ( ! check_ajax_referer( 'aebg_ajax_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Security check failed' );
        }

        // Check permissions
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        // Validate and sanitize required parameters
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        $product_number = isset( $_POST['product_number'] ) ? intval( $_POST['product_number'] ) : 0;

        // Enhanced parameter validation
        if ( ! $post_id || $post_id <= 0 ) {
            wp_send_json_error( 'Invalid post ID provided.' );
        }

        if ( ! $product_number || $product_number <= 0 ) {
            wp_send_json_error( 'Invalid product number provided.' );
        }

        try {
            // Validate post exists
            $post = get_post( $post_id );
            if ( ! $post ) {
                wp_send_json_error( 'Post not found.' );
            }

            // Get current products
            $products = get_post_meta( $post_id, '_aebg_products', true );
            if ( ! is_array( $products ) || empty( $products ) ) {
                wp_send_json_error( 'No products found for this post.' );
            }

            // Get the specific product (product_number is 1-based, array is 0-based)
            $product_index = $product_number - 1;
            if ( ! isset( $products[ $product_index ] ) ) {
                wp_send_json_error( 'Product not found at specified position.' );
            }

            $product = $products[ $product_index ];

            // Get the Elementor template (optional)
            $template_id = get_post_meta( $post_id, '_elementor_template_id', true );
            
            // Get AI settings from the correct option
            $aebg_settings = get_option( 'aebg_settings', [] );
            $settings = [
                'openai_api_key' => $aebg_settings['api_key'] ?? '',
                'ai_model' => $aebg_settings['model'] ?? 'gpt-3.5-turbo'
            ];

            // Add template_id if available
            if ( $template_id ) {
                $settings['template_id'] = $template_id;
            }

            if ( empty( $settings['openai_api_key'] ) ) {
                wp_send_json_error( 'OpenAI API key not configured.' );
            }

            // Initialize Generator
            $generator = new \AEBG\Core\Generator( $settings );

            // Regenerate content for the specific product container
            $result = $generator->regenerateProductContent( $post_id, $product_number, $product );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( 'Content regeneration failed: ' . $result->get_error_message() );
            }

            wp_send_json_success( [
                'message' => 'Content regenerated successfully',
                'product_number' => $product_number
            ] );

        } catch ( Exception $e ) {
            wp_send_json_error( 'Content regeneration failed: ' . $e->getMessage() );
        }
    }

    /**
     * AJAX handler for updating product ratings
     */
    public function ajax_update_product_rating() {
        // Verify nonce
        if ( ! wp_verify_nonce( $_POST['nonce'], 'aebg_ajax_nonce' ) ) {
            wp_send_json_error( 'Security check failed.' );
        }

        // Check permissions
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        // Validate and sanitize required parameters
        $post_id = intval( $_POST['post_id'] ?? 0 );
        $product_id = sanitize_text_field( $_POST['product_id'] ?? '' );
        $rating = floatval( $_POST['rating'] ?? 0 );

        // Enhanced parameter validation
        if ( ! $post_id || $post_id <= 0 ) {
            wp_send_json_error( 'Invalid post ID provided.' );
        }

        if ( empty( $product_id ) ) {
            wp_send_json_error( 'Product ID is required.' );
        }

        // Validate rating range
        if ( $rating < 0 || $rating > 5 ) {
            wp_send_json_error( 'Rating must be between 0 and 5.' );
        }

        // Validate post exists
        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( 'Post not found.' );
        }

        // Get current products data
        $products = get_post_meta( $post_id, '_aebg_products', true );
        
        if ( ! is_array( $products ) ) {
            wp_send_json_error( 'No products data found.' );
        }

        // Find and update the product rating
        $product_updated = false;
        foreach ( $products as $index => $product ) {
            if ( ( $product['id'] ?? $index ) == $product_id ) {
                $products[$index]['rating'] = $rating;
                $product_updated = true;
                break;
            }
        }

        if ( ! $product_updated ) {
            wp_send_json_error( 'Product not found.' );
        }

        // Save updated products data
        $update_result = update_post_meta( $post_id, '_aebg_products', $products );

        if ( $update_result ) {
            wp_send_json_success( [
                'message' => 'Rating updated successfully',
                'rating' => $rating,
                'stars' => $this->generate_stars( $rating )
            ] );
        } else {
            wp_send_json_error( 'Failed to update rating.' );
        }
    }

    /**
     * AJAX handler to update template structure for new products
     */
    public function ajax_update_template_for_new_product() {
        // Verify nonce
        if ( ! check_ajax_referer( 'aebg_ajax_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Security check failed' );
        }

        // Check permissions
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        // Validate and sanitize required parameters
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        $product_number = isset( $_POST['product_number'] ) ? intval( $_POST['product_number'] ) : 0;

        // Enhanced parameter validation
        if ( ! $post_id || $post_id <= 0 ) {
            wp_send_json_error( 'Invalid post ID provided.' );
        }

        if ( ! $product_number || $product_number <= 0 ) {
            wp_send_json_error( 'Invalid product number provided.' );
        }

        try {
            // Validate post exists
            $post = get_post( $post_id );
            if ( ! $post ) {
                wp_send_json_error( 'Post not found.' );
            }

            // Get AI settings from the correct option
            $aebg_settings = get_option( 'aebg_settings', [] );
            $settings = [
                'openai_api_key' => $aebg_settings['api_key'] ?? '',
                'ai_model' => $aebg_settings['model'] ?? 'gpt-3.5-turbo'
            ];

            // Initialize Generator (even without API key for template structure updates)
            $generator = new \AEBG\Core\Generator( $settings );

            // Update template for the new product
            $result = $generator->updateTemplateForNewProduct( $post_id, $product_number );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( 'Template update failed: ' . $result->get_error_message() );
            }

            wp_send_json_success( [
                'message' => 'Template updated successfully for new product ' . $product_number,
                'product_number' => $product_number,
                'ai_configured' => !empty($settings['openai_api_key'])
            ] );

        } catch ( Exception $e ) {
            wp_send_json_error( 'Template update failed: ' . $e->getMessage() );
        }
    }

    /**
     * AJAX handler to remove a product from the database
     */
    public function ajax_remove_product() {
        // Verify nonce
        if ( ! check_ajax_referer( 'aebg_ajax_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Security check failed' );
        }

        // Check permissions
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        // Validate and sanitize required parameters
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        $product_id = isset( $_POST['product_id'] ) ? sanitize_text_field( $_POST['product_id'] ) : '';
        $removed_product_number = isset( $_POST['removed_product_number'] ) ? intval( $_POST['removed_product_number'] ) : 0;

        // Enhanced parameter validation
        if ( ! $post_id || $post_id <= 0 ) {
            wp_send_json_error( 'Invalid post ID provided.' );
        }

        if ( empty( $product_id ) ) {
            wp_send_json_error( 'Product ID is required.' );
        }

        if ( ! $removed_product_number || $removed_product_number <= 0 ) {
            wp_send_json_error( 'Invalid product number provided.' );
        }

        try {
            // Validate post exists
            $post = get_post( $post_id );
            if ( ! $post ) {
                wp_send_json_error( 'Post not found.' );
            }

            // Get current products data
            $products = get_post_meta( $post_id, '_aebg_products', true );
            $product_ids = get_post_meta( $post_id, '_aebg_product_ids', true );

            if ( ! is_array( $products ) ) {
                $products = [];
            }
            if ( ! is_array( $product_ids ) ) {
                $product_ids = [];
            }

            // Remove the product from both arrays
            $updated_products = [];
            $updated_product_ids = [];
            $product_found = false;

            foreach ( $products as $index => $product ) {
                $current_product_id = $product['id'] ?? $product['product_id'] ?? '';
                if ( $current_product_id !== $product_id ) {
                    $updated_products[] = $product;
                    $updated_product_ids[] = $current_product_id;
                } else {
                    $product_found = true;
                }
            }

            if ( ! $product_found ) {
                wp_send_json_error( 'Product not found in database.' );
            }

            // Update the post meta
            $products_update_result = update_post_meta( $post_id, '_aebg_products', $updated_products );
            $product_ids_update_result = update_post_meta( $post_id, '_aebg_product_ids', $updated_product_ids );

            if ( ! $products_update_result || ! $product_ids_update_result ) {
                wp_send_json_error( 'Failed to update product data.' );
            }

            // Update product count
            $product_count = count( $updated_products );
            update_post_meta( $post_id, '_aebg_product_count', $product_count );

            wp_send_json_success( [
                'message' => 'Product removed successfully',
                'removed_product_id' => $product_id,
                'removed_product_number' => $removed_product_number,
                'remaining_products' => $product_count
            ] );

        } catch ( Exception $e ) {
            wp_send_json_error( 'Product removal failed: ' . $e->getMessage() );
        }
    }

    /**
     * Simple template update after removal (without AI)
     */
    private function update_template_after_removal_simple( $post_id, $removed_product_number ) {
        try {
            // Get current Elementor data
            $elementor_data = get_post_meta( $post_id, '_elementor_data', true );
            
            if ( empty( $elementor_data ) ) {
                error_log( '[AEBG] No Elementor data found for post ' . $post_id );
                return true; // No Elementor data to update
            }

            // Decode the JSON data
            $data = $this->decodeJsonWithUnicode( $elementor_data );
            if ( $data === false ) {
                error_log( '[AEBG] Failed to decode Elementor data with Unicode support for post ' . $post_id );
                return true; // Invalid data
            }
            
            if ( ! is_array( $data ) ) {
                error_log( '[AEBG] Invalid Elementor data structure for post ' . $post_id );
                return true; // Invalid data
            }

            // Process the data to adjust product numbers
            $this->adjust_template_after_removal( $data, $removed_product_number );

            // Clean the data before encoding to prevent JSON issues
            $cleaned_data = $this->cleanElementorDataForEncoding($data);
            
            // Save the updated data with proper JSON encoding flags
            $encoded_data = json_encode( $cleaned_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            if ( $encoded_data === false ) {
                error_log( '[AEBG] Failed to encode updated Elementor data for post ' . $post_id );
                error_log( '[AEBG] JSON error: ' . json_last_error_msg() );
                return false;
            }

            $update_result = update_post_meta( $post_id, '_elementor_data', $encoded_data );
            if ( $update_result === false ) {
                error_log( '[AEBG] Failed to update Elementor data for post ' . $post_id );
                return false;
            }

            // Clear Elementor cache to ensure frontend updates immediately
            $this->clearElementorCache( $post_id );

            error_log( '[AEBG] Successfully updated template after removing product ' . $removed_product_number . ' from post ' . $post_id );
            return true;

        } catch ( Exception $e ) {
            error_log( '[AEBG] Template update error: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Recursively adjust template data after product removal
     */
    private function adjust_template_after_removal( &$data, $removed_product_number ) {
        if ( ! is_array( $data ) ) {
            return;
        }

        error_log('[AEBG] adjust_template_after_removal: Processing data for removed product ' . $removed_product_number);

        // Handle numeric array structure (top-level containers)
        if (array_keys($data) === range(0, count($data) - 1)) {
            error_log('[AEBG] Processing numeric array structure with ' . count($data) . ' elements');
            
            $filtered_data = [];
            
            foreach ($data as $index => $item) {
                // Recursively process each item to find and remove product containers
                $processed_item = $this->adjust_template_after_removal_recursive($item, $removed_product_number);
                if ($processed_item !== null) {
                    $filtered_data[] = $processed_item;
                } else {
                    error_log('[AEBG] Removed item at index ' . $index . ' from numeric array');
                }
            }
            
            // Replace the data array with filtered data
            $data = $filtered_data;
            return;
        }

        // For associative arrays, we need to process them recursively
        $processed_data = $this->adjust_template_after_removal_recursive($data, $removed_product_number);
        if ($processed_data !== null) {
            // Update the original data with the processed data
            foreach ($processed_data as $key => $value) {
                $data[$key] = $value;
            }
        }
    }

    /**
     * Recursive helper method for adjust_template_after_removal
     */
    private function adjust_template_after_removal_recursive($data, $removed_product_number) {
        if (!is_array($data)) {
            return $data;
        }

        // Handle numeric array structure (lists of elements)
        if (array_keys($data) === range(0, count($data) - 1)) {
            $filtered_data = [];
            
            foreach ($data as $index => $item) {
                $processed_item = $this->adjust_template_after_removal_recursive($item, $removed_product_number);
                if ($processed_item !== null) {
                    $filtered_data[] = $processed_item;
                }
            }
            
            return $filtered_data;
        }

        // Handle associative array structure (nested elements)
        $updated_data = $data;
        
        // Check if this element has a CSS ID that needs updating
        if (isset($data['settings']) && is_array($data['settings'])) {
            $settings = &$updated_data['settings'];
            $css_id = $settings['_element_id'] ?? $settings['_css_id'] ?? $settings['css_id'] ?? '';
            
            // Check for product-X CSS ID pattern with exact matching
            if (preg_match('/^product-(\d+)$/', $css_id, $matches)) {
                $current_product_number = (int) $matches[1];
                
                // If this is the container for the removed product, return null to remove it
                if ($current_product_number === $removed_product_number) {
                    error_log('[AEBG] Found and removing container for product ' . $removed_product_number . ' with CSS ID: ' . $css_id);
                    return null; // This will remove the container
                }
                
                // If this product number is greater than the removed product number,
                // we need to decrement it to fill the gap
                if ($current_product_number > $removed_product_number) {
                    $new_css_id = 'product-' . ($current_product_number - 1);
                    
                    // Update CSS ID - prioritize _element_id since that's what your templates use
                    if (isset($settings['_element_id'])) {
                        $settings['_element_id'] = $new_css_id;
                    } elseif (isset($settings['_css_id'])) {
                        $settings['_css_id'] = $new_css_id;
                    } elseif (isset($settings['css_id'])) {
                        $settings['css_id'] = $new_css_id;
                    }
                    
                    error_log('[AEBG] Updated CSS ID from ' . $css_id . ' to ' . $new_css_id . ' after removing product ' . $removed_product_number);
                }
            }

            // Update variables in text content to match the new product numbers
            // EXCLUDE aebg_ai_prompt from variable replacement - it should remain as a template
            $text_fields = ['text', 'title', 'description', 'content', 'html', 'shortcode'];
            foreach ($text_fields as $field) {
                if (isset($settings[$field]) && is_string($settings[$field])) {
                    $settings[$field] = $this->updateVariablesAfterRemoval($settings[$field], $removed_product_number);
                }
            }
        }

        // Recursively process children
        if (isset($data['elements']) && is_array($data['elements'])) {
            $updated_elements = [];
            foreach ($data['elements'] as $index => $element) {
                $processed_element = $this->adjust_template_after_removal_recursive($element, $removed_product_number);
                if ($processed_element !== null) {
                    $updated_elements[] = $processed_element;
                }
            }
            $updated_data['elements'] = $updated_elements;
        }

        if (isset($data['content']) && is_array($data['content'])) {
            $updated_content = [];
            foreach ($data['content'] as $index => $content_item) {
                $processed_content = $this->adjust_template_after_removal_recursive($content_item, $removed_product_number);
                if ($processed_content !== null) {
                    $updated_content[] = $processed_content;
                }
            }
            $updated_data['content'] = $updated_content;
        }
        
        // Process any other array structures that might contain nested elements
        foreach ($data as $key => $value) {
            if (is_array($value) && $key !== 'settings' && $key !== 'elements' && $key !== 'content') {
                // Check if this is a numeric array (list of elements)
                if (array_keys($value) === range(0, count($value) - 1)) {
                    $updated_array = [];
                    foreach ($value as $index => $item) {
                        $processed_item = $this->adjust_template_after_removal_recursive($item, $removed_product_number);
                        if ($processed_item !== null) {
                            $updated_array[] = $processed_item;
                        }
                    }
                    $updated_data[$key] = $updated_array;
                }
            }
        }

        return $updated_data;
    }

    /**
     * Adjust CSS ID after product removal
     */
    private function adjust_css_id_after_removal( $css_id, $removed_product_number ) {
        // Pattern to match product-X in CSS IDs (only product-X, not bpX or other patterns)
        $pattern = '/^product-(\d+)$/';
        
        if (preg_match($pattern, $css_id, $matches)) {
            $product_number = intval( $matches[1] );
            if ( $product_number > $removed_product_number ) {
                $new_css_id = 'product-' . ( $product_number - 1 );
                error_log('[AEBG] Updated CSS ID from ' . $css_id . ' to ' . $new_css_id . ' after removing product ' . $removed_product_number);
                return $new_css_id;
            }
        }
        
        return $css_id; // Keep unchanged if not a product-X pattern or less than or equal to removed number
    }

    /**
     * Adjust variables after product removal
     */
    private function adjust_variables_after_removal( &$settings, $removed_product_number ) {
        foreach ( $settings as $key => &$value ) {
            if ( is_string( $value ) ) {
                // Pattern to match {product-X-*} variables (only product-X, not bpX or other patterns)
                $pattern = '/\{product-(\d+)-([^}]+)\}/';
                
                $value = preg_replace_callback( $pattern, function( $matches ) use ( $removed_product_number ) {
                    $product_number = intval( $matches[1] );
                    $variable_name = $matches[2];
                    
                    if ( $product_number > $removed_product_number ) {
                        $new_variable = '{product-' . ( $product_number - 1 ) . '-' . $variable_name . '}';
                        error_log('[AEBG] Updated variable from ' . $matches[0] . ' to ' . $new_variable . ' after removing product ' . $removed_product_number);
                        return $new_variable;
                    }
                    return $matches[0]; // Keep unchanged if less than or equal to removed number
                }, $value );
                
                // Also handle simple {product-X} variables
                $simple_pattern = '/\{product-(\d+)\}/';
                $value = preg_replace_callback( $simple_pattern, function( $matches ) use ( $removed_product_number ) {
                    $product_number = intval( $matches[1] );
                    
                    if ( $product_number > $removed_product_number ) {
                        $new_variable = '{product-' . ( $product_number - 1 ) . '}';
                        error_log('[AEBG] Updated simple variable from ' . $matches[0] . ' to ' . $new_variable . ' after removing product ' . $removed_product_number);
                        return $new_variable;
                    }
                    return $matches[0]; // Keep unchanged if less than or equal to removed number
                }, $value );
            } elseif ( is_array( $value ) ) {
                $this->adjust_variables_after_removal( $value, $removed_product_number );
            }
        }
    }

    /**
     * Update product variables in text content after product removal
     *
     * @param string $text The text content
     * @param int $removed_product_number The product number that was removed
     * @return string Updated text content
     */
    private function updateVariablesAfterRemoval($text, $removed_product_number) {
        // Find all product variables in the text
        $pattern = '/\{product-(\d+)(-[^}]+)?\}/';
        
        return preg_replace_callback($pattern, function($matches) use ($removed_product_number) {
            $product_num = (int) $matches[1];
            $suffix = $matches[2] ?? '';
            
            // If this product number is greater than the removed product number,
            // decrement it to fill the gap
            if ($product_num > $removed_product_number) {
                $new_product_num = $product_num - 1;
                return '{product-' . $new_product_num . $suffix . '}';
            }
            
            // Otherwise, keep the same
            return $matches[0];
        }, $text);
    }

    /**
     * AJAX handler to update template after product removal (legacy - kept for compatibility)
     */
    public function ajax_update_template_after_removal() {
        // Verify nonce
        if ( ! check_ajax_referer( 'aebg_ajax_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Security check failed' );
        }

        // Check permissions
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        // Validate and sanitize required parameters
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        $removed_product_number = isset( $_POST['removed_product_number'] ) ? intval( $_POST['removed_product_number'] ) : 0;

        // Enhanced parameter validation
        if ( ! $post_id || $post_id <= 0 ) {
            wp_send_json_error( 'Invalid post ID provided.' );
        }

        if ( ! $removed_product_number || $removed_product_number <= 0 ) {
            wp_send_json_error( 'Invalid product number provided.' );
        }

        try {
            // Validate post exists
            $post = get_post( $post_id );
            if ( ! $post ) {
                wp_send_json_error( 'Post not found.' );
            }

            // Use the simple template update method instead of requiring OpenAI API
            $result = $this->update_template_after_removal_simple( $post_id, $removed_product_number );

            if ( $result ) {
                wp_send_json_success( [
                    'message' => 'Template updated successfully after product removal',
                    'removed_product_number' => $removed_product_number
                ] );
            } else {
                wp_send_json_error( 'Template update failed.' );
            }

        } catch ( Exception $e ) {
            wp_send_json_error( 'Template update failed: ' . $e->getMessage() );
        }
    }

    /**
     * Helper method to decode JSON with Unicode support
     *
     * @param string $json_string The JSON string to decode
     * @return array|false The decoded data or false on failure
     */
    private function decodeJsonWithUnicode($json_string) {
        if (!is_string($json_string)) {
            return false;
        }
        
        // Simply use standard json_decode - it handles Unicode escape sequences automatically
        $decoded = json_decode($json_string, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        
        error_log('[AEBG] JSON decode failed: ' . json_last_error_msg());
        return false;
    }
    
    /**
     * Helper method to decode all Unicode escape sequences in a string
     *
     * @param string $string The string containing Unicode escape sequences
     * @return string The string with decoded Unicode sequences
     */
    private function decodeUnicodeSequences($string) {
        // Use a comprehensive approach to decode all Unicode escape sequences
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($matches) {
            $hex = $matches[1];
            $dec = hexdec($hex);
            
            // Handle UTF-8 encoding properly
            if ($dec < 128) {
                // ASCII character
                return chr($dec);
            } elseif ($dec < 2048) {
                // 2-byte UTF-8
                return chr(192 | ($dec >> 6)) . chr(128 | ($dec & 63));
            } elseif ($dec < 65536) {
                // 3-byte UTF-8
                return chr(224 | ($dec >> 12)) . chr(128 | (($dec >> 6) & 63)) . chr(128 | ($dec & 63));
            } else {
                // 4-byte UTF-8 (though this shouldn't happen with 4-digit hex)
                return chr(240 | ($dec >> 18)) . chr(128 | (($dec >> 12) & 63)) . chr(128 | (($dec >> 6) & 63)) . chr(128 | ($dec & 63));
            }
        }, $string);
    }

    /**
     * Clean Elementor data before JSON encoding to prevent unescaped quotes issues
     *
     * @param mixed $elementor_data The Elementor data to clean
     * @return mixed The cleaned Elementor data
     */
    private function cleanElementorDataForEncoding($elementor_data) {
        if (!is_array($elementor_data)) {
            return $elementor_data;
        }
        
        $cleaned = [];
        foreach ($elementor_data as $key => $value) {
            if (is_array($value)) {
                $cleaned[$key] = $this->cleanElementorDataForEncoding($value);
            } else if (is_string($value)) {
                // Clean string values that might contain unescaped quotes
                $cleaned[$key] = $this->cleanStringForJson($value);
            } else {
                $cleaned[$key] = $value;
            }
        }
        
        return $cleaned;
    }

    /**
     * Clean a string value for JSON encoding
     *
     * @param string $string The string to clean
     * @return string The cleaned string
     */
    private function cleanStringForJson($string) {
        if (!is_string($string)) {
            return $string;
        }
        
        // First, handle any control characters that could break JSON
        $cleaned = preg_replace('/[\x00-\x1F\x7F]/', '', $string);
        
        // Use json_encode to properly escape the string, then remove the surrounding quotes
        $json_encoded = json_encode($cleaned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json_encoded === false) {
            // If json_encode fails, fall back to manual escaping
            $cleaned = str_replace('"', '\\"', $cleaned);
            $cleaned = str_replace(["\r", "\n", "\t"], ['\\r', '\\n', '\\t'], $cleaned);
            return $cleaned;
        }
        
        // Remove the surrounding quotes that json_encode adds
        $cleaned = substr($json_encoded, 1, -1);
        
        return $cleaned;
    }

    /**
     * Clear Elementor cache for a specific post
     *
     * @param int $post_id The post ID
     */
    private function clearElementorCache( $post_id ) {
        error_log( '[AEBG] Clearing Elementor cache for post ' . $post_id );
        
        // Clear Elementor-specific cache meta (but keep _elementor_edit_mode)
        delete_post_meta( $post_id, '_elementor_data_cache' );
        // Don't delete _elementor_edit_mode - we need this for Elementor to recognize the post
        delete_post_meta( $post_id, '_elementor_css' );
        delete_post_meta( $post_id, '_elementor_js' );
        delete_post_meta( $post_id, '_elementor_custom_css' );
        delete_post_meta( $post_id, '_elementor_custom_js' );
        
        // Clear Elementor page cache
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( 'elementor_css_' . $post_id, 'elementor' );
            wp_cache_delete( 'elementor_js_' . $post_id, 'elementor' );
            wp_cache_delete( 'elementor_data_' . $post_id, 'elementor' );
        }
        
        // Clear Elementor global cache if available
        if ( class_exists( '\Elementor\Plugin' ) ) {
            try {
                // Clear Elementor's internal cache
                $elementor = \Elementor\Plugin::instance();
                if ( method_exists( $elementor, 'files_manager' ) ) {
                    $elementor->files_manager->clear_cache();
                }
                
                // Clear Elementor's CSS cache
                if ( method_exists( $elementor, 'kits_manager' ) ) {
                    $elementor->kits_manager->clear_cache();
                }
                
                // Clear Elementor's documents cache
                if ( method_exists( $elementor, 'documents' ) ) {
                    $document = $elementor->documents->get( $post_id );
                    if ( $document && method_exists( $document, 'delete_autosave' ) ) {
                        $document->delete_autosave();
                    }
                }
            } catch ( \Exception $e ) {
                error_log( '[AEBG] Error clearing Elementor cache: ' . $e->getMessage() );
            }
        }
        
        // Clear WordPress object cache for this post
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( $post_id, 'posts' );
            wp_cache_delete( $post_id, 'post_meta' );
        }
        
        // Clear any other Elementor-related caches
        do_action( 'elementor/core/files/clear_cache' );
        do_action( 'elementor/css-file/clear_cache' );
        do_action( 'elementor/js-file/clear_cache' );
        
        // Clear Elementor's internal caches
        if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
            try {
                $css_file = new \Elementor\Core\Files\CSS\Post( $post_id );
                $css_file->delete();
            } catch ( \Exception $e ) {
                error_log( '[AEBG] Error clearing Elementor CSS file: ' . $e->getMessage() );
            }
        }
        
        // Clear Elementor's JS cache
        if ( class_exists( '\Elementor\Core\Files\JS\Post' ) ) {
            try {
                $js_file = new \Elementor\Core\Files\JS\Post( $post_id );
                $js_file->delete();
            } catch ( \Exception $e ) {
                error_log( '[AEBG] Error clearing Elementor JS file: ' . $e->getMessage() );
            }
        }
        
        error_log( '[AEBG] Elementor cache cleared for post ' . $post_id );
    }

    /**
     * Normalize merchant data structure for consistent processing
     * 
     * @param array $data Comparison data
     * @return array Normalized comparison data
     */
    private function normalize_merchant_data_structure($data) {
        if (!isset($data['merchants'])) {
            return $data;
        }
        
        $merchants = $data['merchants'];
        
        // If merchants is already an associative array, return as is
        if (!isset($merchants[0])) {
            return $data;
        }
        
        // Convert indexed array to associative array for consistency
        $normalized_merchants = [];
        foreach ($merchants as $merchant_data) {
            if (is_array($merchant_data) && isset($merchant_data['name'])) {
                $merchant_name = $merchant_data['name'];
                $normalized_merchants[$merchant_name] = $merchant_data;
            }
        }
        
        $data['merchants'] = $normalized_merchants;
        return $data;
    }

    /**
     * Get merchant count directly from database for a specific product
     * 
     * @param string $product_id Product ID
     * @return int Merchant count
     */
    private function get_direct_merchant_count($product_id) {
        global $wpdb;
        
        if (empty($product_id)) {
            return 1;
        }
        
        $table_name = $wpdb->prefix . 'aebg_comparisons';
        
        // Try to find comparison data for this product
        $query = $wpdb->prepare(
            "SELECT comparison_data FROM $table_name WHERE product_id = %s AND status = 'active' ORDER BY updated_at DESC LIMIT 1",
            $product_id
        );
        
        $result = $wpdb->get_row($query, ARRAY_A);
        
        if ($result && !empty($result['comparison_data'])) {
            $data = json_decode($result['comparison_data'], true);
            
            if ($data && isset($data['merchants']) && !empty($data['merchants'])) {
                // Normalize data structure
                $data = $this->normalize_merchant_data_structure($data);
                
                // Count unique merchants
                $merchant_count = count($data['merchants']);
                error_log('[AEBG] 🔍 DIRECT COUNT: Found ' . $merchant_count . ' merchants for product ' . $product_id . ' directly from database');
                return $merchant_count;
            }
        }
        
        error_log('[AEBG] 🔍 DIRECT COUNT: No comparison data found for product ' . $product_id . ', returning default count 1');
        return 1;
    }

    /**
     * AJAX handler for getting networks for the modal
     */
    public function ajax_get_networks_for_modal() {
        // Verify nonce
        if ( ! wp_verify_nonce( $_POST['nonce'], 'aebg_ajax_nonce' ) ) {
            wp_send_json_error( 'Security check failed' );
        }

        // Check permissions
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }

        try {
            // Initialize Networks Manager
            $networks_manager = new \AEBG\Admin\Networks_Manager();
            
            // Get all networks with their configuration status
            $networks = $networks_manager->get_all_networks_with_status();
            
            if ( is_wp_error( $networks ) ) {
                wp_send_json_error( 'Failed to get networks: ' . $networks->get_error_message() );
            }

            // Format networks for the modal
            $formatted_networks = [];
            foreach ($networks as $network) {
                $formatted_networks[] = [
                    'code' => $network['code'],
                    'name' => $network['name'],
                    'country' => $network['country'],
                    'configured' => $network['configured'],
                    'affiliate_id' => $network['affiliate_id']
                ];
            }

            wp_send_json_success( $formatted_networks );
            
        } catch ( Exception $e ) {
            wp_send_json_error( 'Error getting networks: ' . $e->getMessage() );
        }
    }
}
