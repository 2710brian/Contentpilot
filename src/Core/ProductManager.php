<?php

namespace AEBG\Core;

use AEBG\Core\CurrencyManager;


/**
 * Product Manager Class
 * 
 * Handles product validation, context management, and utility methods
 * 
 * @package AEBG\Core
 */
class ProductManager {
    
    /**
     * Validate and normalize product data
     * 
     * @param array $product Raw product data
     * @return array Validated product data
     */
    public static function validateProduct($product) {
        if (!is_array($product)) {
            error_log('[AEBG] ProductManager::validateProduct - Product is not an array, type: ' . gettype($product));
            return null;
        }

        // Helper function to safely convert values to strings
        $safeString = function($value) {
            if (is_array($value)) {
                return json_encode($value);
            }
            return (string) $value;
        };

        // Helper function to safely convert values to integers
        $safeInt = function($value) {
            if (is_array($value)) {
                return 0;
            }
            return (int) $value;
        };

        // Helper function to safely convert values to floats
        $safeFloat = function($value) {
            if (is_array($value)) {
                return 0.0;
            }
            return (float) $value;
        };

        // Fix malformed URLs before validation
        $product_url = $product['url'] ?? '';
        // URLs from Datafeedr are already correctly formatted
        
        // Also check other URL fields
        $affiliate_url = $product['affiliate_url'] ?? '';
        if (!empty($affiliate_url)) {
            // URLs from Datafeedr are already correctly formatted
        }
        $product_url_field = $product['product_url'] ?? '';
        if (!empty($product_url_field)) {
            // URLs from Datafeedr are already correctly formatted
        }
        
        // Ensure required fields exist with fallbacks
        $validated = [
            'id' => $safeString($product['id'] ?? ''),
            'name' => $safeString($product['name'] ?? 'Unknown Product'),
            'price' => $safeFloat($product['price'] ?? 0),
            'currency' => $safeString($product['currency'] ?? 'USD'),
            'brand' => $safeString($product['brand'] ?? ''),
            'rating' => $safeFloat($product['rating'] ?? 0),
            'reviews_count' => $safeInt($product['reviews_count'] ?? 0),
            'image_url' => $safeString($product['image_url'] ?? ''),
            'url' => $safeString($product_url),
            'product_url' => $safeString($product_url_field ?: $product_url),
            'affiliate_url' => $safeString($affiliate_url ?: $product_url),
            'merchant' => $safeString($product['merchant'] ?? ''),
            'category' => $safeString($product['category'] ?? ''),
            'availability' => $safeString($product['availability'] ?? ''),
            'description' => $safeString($product['description'] ?? ''),
            'sku' => $safeString($product['sku'] ?? ''),
            'condition' => $safeString($product['condition'] ?? ''),
            'shipping' => $safeString($product['shipping'] ?? ''),
            'network' => $safeString($product['network'] ?? ''),
            'program' => $safeString($product['program'] ?? ''),
            'commission' => $safeFloat($product['commission'] ?? 0),
            'commission_type' => $safeString($product['commission_type'] ?? ''),
            'last_updated' => $safeString($product['last_updated'] ?? ''),
        ];

        // Add optional fields if they exist
        if (isset($product['justification'])) {
            $validated['justification'] = $safeString($product['justification']);
        }
        if (isset($product['selection_rank'])) {
            $validated['selection_rank'] = $safeInt($product['selection_rank']);
        }

        // Test JSON encoding of the validated product
        $test_json = json_encode($validated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($test_json === false) {
            error_log('[AEBG] ERROR: Failed to JSON encode validated product: ' . json_last_error_msg());
            error_log('[AEBG] Product data that failed: ' . print_r($validated, true));
            return null;
        }

        // Always return the validated product (even if ID is empty)
        return $validated;
    }

    /**
     * Validate an array of products
     * 
     * @param array $products Array of product data
     * @return array Array of validated products
     */
    public static function validateProducts($products) {
        error_log('[AEBG] ProductManager::validateProducts called with ' . count($products) . ' products');
        
        if (!is_array($products)) {
            error_log('[AEBG] ProductManager::validateProducts - Products is not an array');
            return [];
        }

        $validated = [];
        $skipped = 0;
        
        // Preserve the original array structure and positions exactly
        foreach ($products as $index => $product) {
            $validated_product = self::validateProduct($product);
            if ($validated_product) {
                // Preserve the original position by using the exact same index
                $validated[$index] = $validated_product;
            } else {
                $skipped++;
                error_log('[AEBG] ProductManager::validateProducts - Skipped product at index ' . $index . ' (validation failed)');
            }
        }

        // DO NOT re-index the array - preserve the original structure exactly
        // This is crucial for replacements to work correctly
        error_log('[AEBG] ProductManager::validateProducts - Preserving original array structure with ' . count($validated) . ' products');
        error_log('[AEBG] ProductManager::validateProducts - Array keys: ' . json_encode(array_keys($validated)));
        
        error_log('[AEBG] ProductManager::validateProducts - Validated: ' . count($validated) . ', Skipped: ' . $skipped);
        return $validated;
    }

    /**
     * Get products for a specific post
     * 
     * @param int $post_id Post ID
     * @return array Array of products
     */
    public static function getPostProducts($post_id) {
        if (!$post_id) {
            return [];
        }

        // Try to get from post meta first
        $products = get_post_meta($post_id, '_aebg_products', true);
        if (!empty($products) && is_array($products)) {
            // Don't re-validate and re-index - just return the products as they are
            // This preserves the original positions for replacements
            error_log('[AEBG] ProductManager::getPostProducts - Returning ' . count($products) . ' products with preserved structure');
            return $products;
        }

        // Fallback to product IDs - use database lookup first, then API
        $product_ids = get_post_meta($post_id, '_aebg_product_ids', true);
        if (empty($product_ids) || !is_array($product_ids)) {
            return [];
        }

        $datafeedr = new Datafeedr();
        $products = [];
        $products_needing_api = [];
        
        // Request-level cache for API results
        static $request_cache = [];
        
        foreach ($product_ids as $index => $product_id) {
            if (empty($product_id)) {
                continue;
            }
            
            // Check request cache first
            $cache_key = 'product_' . $product_id;
            if (isset($request_cache[$cache_key])) {
                $cached_product = $request_cache[$cache_key];
                if ($cached_product !== false) {
                    $validated = self::validateProduct($cached_product);
                    if ($validated) {
                        $products[$index] = $validated; // Preserve original index
                    }
                }
                continue;
            }
            
            // Try database lookup first (no API call)
            $db_product = $datafeedr->get_product_data_from_database($product_id);
            
            if ($db_product && is_array($db_product)) {
                $validated = self::validateProduct($db_product);
                if ($validated) {
                    $products[$index] = $validated;
                    $request_cache[$cache_key] = $db_product; // Cache for this request
                    continue;
                }
            }
            
            // Mark for API lookup (only if database lookup failed)
            $products_needing_api[$index] = $product_id;
        }
        
        // Batch API lookups for products not found in database
        if (!empty($products_needing_api)) {
            error_log('[AEBG] ProductManager::getPostProducts - Need API lookup for ' . count($products_needing_api) . ' products');
            
            foreach ($products_needing_api as $index => $product_id) {
                // Check WordPress object cache before API call
                $wp_cache_key = 'aebg_product_' . md5($product_id);
                $cached_product = wp_cache_get($wp_cache_key, 'aebg_products');
                
                if ($cached_product !== false && is_array($cached_product)) {
                    $validated = self::validateProduct($cached_product);
                    if ($validated) {
                        $products[$index] = $validated;
                        $request_cache['product_' . $product_id] = $cached_product;
                        continue;
                    }
                }
                
                // API call (last resort)
                $product_result = $datafeedr->search('id:' . $product_id, 1);
                
                if (!is_wp_error($product_result) && !empty($product_result)) {
                    $product_data = $product_result[0];
                    $validated = self::validateProduct($product_data);
                    
                    if ($validated) {
                        $products[$index] = $validated;
                        
                        // Cache in all layers
                        $request_cache['product_' . $product_id] = $product_data;
                        wp_cache_set($wp_cache_key, $product_data, 'aebg_products', 15 * MINUTE_IN_SECONDS);
                    } else {
                        // Cache negative result to prevent repeated failed lookups
                        $request_cache['product_' . $product_id] = false;
                        wp_cache_set($wp_cache_key, false, 'aebg_products', 5 * MINUTE_IN_SECONDS);
                    }
                } else {
                    // Cache negative result
                    $request_cache['product_' . $product_id] = false;
                    wp_cache_set($wp_cache_key, false, 'aebg_products', 5 * MINUTE_IN_SECONDS);
                }
            }
        }
        
        // Remove empty slots but preserve order
        $products = array_filter($products, function($product) {
            return $product !== null;
        });
        
        // Re-index to maintain sequential order while preserving relative positions
        $products = array_values($products);
        
        error_log('[AEBG] ProductManager::getPostProducts - Returning ' . count($products) . ' validated products');
        return $products;
    }

    /**
     * Save products for a specific post
     *
     * @param int $post_id The post ID
     * @param array $products Array of products
     * @return bool Success status
     */
    public static function savePostProducts($post_id, $products) {
        error_log('[AEBG] ProductManager::savePostProducts called with post_id: ' . $post_id . ', products count: ' . count($products));
        
        // Enhanced parameter validation
        if (!$post_id || !is_numeric($post_id) || $post_id <= 0) {
            error_log('[AEBG] ProductManager::savePostProducts - Invalid post_id: ' . var_export($post_id, true));
            return false;
        }

        if (!is_array($products) || empty($products)) {
            error_log('[AEBG] ProductManager::savePostProducts - Invalid products parameter: ' . gettype($products));
            return false;
        }

        // Check if post exists
        $post = get_post($post_id);
        if (!$post) {
            error_log('[AEBG] ProductManager::savePostProducts - Post not found: ' . $post_id);
            return false;
        }

        $validated_products = self::validateProducts($products);
        error_log('[AEBG] ProductManager::savePostProducts - Validated products count: ' . count($validated_products));
        
        if (empty($validated_products)) {
            error_log('[AEBG] No valid products to save for post ' . $post_id);
            return false;
        }

        // Debug: Check if the validated products can be JSON encoded
        $test_json = json_encode($validated_products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($test_json === false) {
            error_log('[AEBG] ERROR: Failed to JSON encode validated products: ' . json_last_error_msg());
            return false;
        }
        error_log('[AEBG] JSON encoding test passed, size: ' . strlen($test_json) . ' bytes');

        // Ensure all products have required fields
        foreach ($validated_products as $index => $product) {
            if (!isset($product['id']) || empty($product['id'])) {
                $validated_products[$index]['id'] = 'product_' . time() . '_' . $index;
                error_log('[AEBG] Generated missing ID for product at index ' . $index . ': ' . $validated_products[$index]['id']);
            }
            if (!isset($product['name']) || empty($product['name'])) {
                $validated_products[$index]['name'] = 'Unknown Product ' . ($index + 1);
                error_log('[AEBG] Generated missing name for product at index ' . $index . ': ' . $validated_products[$index]['name']);
            }
        }

        // Save full product data with enhanced error handling
        // CRITICAL: update_post_meta returns false if value hasn't changed (WordPress behavior)
        // We need to check if meta exists and compare values, not just check return value
        error_log('[AEBG] Attempting to save _aebg_products meta...');
        $existing_products = get_post_meta($post_id, '_aebg_products', true);
        $products_changed = ($existing_products !== $validated_products);
        
        if ($products_changed) {
            $result1 = update_post_meta($post_id, '_aebg_products', $validated_products);
            // update_post_meta returns false if value is the same OR if it fails
            // So we need to verify the save actually worked
            if ($result1 === false) {
                // Check if the save actually worked by comparing stored value
                $verify_products = get_post_meta($post_id, '_aebg_products', true);
                if ($verify_products === $validated_products || (is_array($verify_products) && is_array($validated_products) && count($verify_products) === count($validated_products))) {
                    // Save actually worked, just value was same
                    $result1 = true;
                    error_log('[AEBG] update_post_meta returned false but value was saved correctly (likely same value)');
                } else {
                    // Save actually failed, try delete and re-add
                    error_log('[AEBG] update_post_meta failed, trying delete and re-add approach for _aebg_products');
                    delete_post_meta($post_id, '_aebg_products');
                    $result1 = add_post_meta($post_id, '_aebg_products', $validated_products, true);
                    if ($result1 === false) {
                        error_log('[AEBG] ERROR: Both update_post_meta and add_post_meta failed for _aebg_products');
                        return false;
                    }
                }
            }
        } else {
            // Value hasn't changed, no need to update
            $result1 = true;
            error_log('[AEBG] Products meta unchanged, skipping update');
        }
        error_log('[AEBG] update_post_meta result1: ' . ($result1 !== false ? 'success' : 'failed'));
        
        // Save product IDs for backward compatibility
        $product_ids = array_column($validated_products, 'id');
        $product_ids = array_filter($product_ids); // Remove empty IDs
        error_log('[AEBG] Extracted product IDs: ' . json_encode($product_ids));
        error_log('[AEBG] Attempting to save _aebg_product_ids meta...');
        
        $existing_ids = get_post_meta($post_id, '_aebg_product_ids', true);
        $ids_changed = ($existing_ids !== $product_ids);
        
        if ($ids_changed) {
            $result2 = update_post_meta($post_id, '_aebg_product_ids', $product_ids);
            if ($result2 === false) {
                // Verify if save actually worked
                $verify_ids = get_post_meta($post_id, '_aebg_product_ids', true);
                if ($verify_ids === $product_ids || (is_array($verify_ids) && is_array($product_ids) && count($verify_ids) === count($product_ids))) {
                    $result2 = true;
                    error_log('[AEBG] update_post_meta returned false but value was saved correctly (likely same value)');
                } else {
                    error_log('[AEBG] update_post_meta failed, trying delete and re-add approach for _aebg_product_ids');
                    delete_post_meta($post_id, '_aebg_product_ids');
                    $result2 = add_post_meta($post_id, '_aebg_product_ids', $product_ids, true);
                    if ($result2 === false) {
                        error_log('[AEBG] ERROR: Both update_post_meta and add_post_meta failed for _aebg_product_ids');
                        return false;
                    }
                }
            }
        } else {
            $result2 = true;
            error_log('[AEBG] Product IDs meta unchanged, skipping update');
        }
        error_log('[AEBG] update_post_meta result2: ' . ($result2 !== false ? 'success' : 'failed'));
        
        // Save product count
        $product_count = count($validated_products);
        error_log('[AEBG] Attempting to save _aebg_product_count meta with value: ' . $product_count);
        
        $existing_count = get_post_meta($post_id, '_aebg_product_count', true);
        $count_changed = ($existing_count != $product_count);
        
        if ($count_changed) {
            $result3 = update_post_meta($post_id, '_aebg_product_count', $product_count);
            if ($result3 === false) {
                // Verify if save actually worked
                $verify_count = get_post_meta($post_id, '_aebg_product_count', true);
                if ($verify_count == $product_count) {
                    $result3 = true;
                    error_log('[AEBG] update_post_meta returned false but value was saved correctly (likely same value)');
                } else {
                    error_log('[AEBG] update_post_meta failed, trying delete and re-add approach for _aebg_product_count');
                    delete_post_meta($post_id, '_aebg_product_count');
                    $result3 = add_post_meta($post_id, '_aebg_product_count', $product_count, true);
                    if ($result3 === false) {
                        error_log('[AEBG] ERROR: Both update_post_meta and add_post_meta failed for _aebg_product_count');
                        return false;
                    }
                }
            }
        } else {
            $result3 = true;
            error_log('[AEBG] Product count meta unchanged, skipping update');
        }
        error_log('[AEBG] update_post_meta result3: ' . ($result3 !== false ? 'success' : 'failed'));

        $success = $result1 !== false && $result2 !== false && $result3 !== false;
        
        if ($success) {
            error_log('[AEBG] Successfully saved ' . count($validated_products) . ' products to post ' . $post_id);
            
            // NOTE: Elementor template variables are now updated from Meta_Box.php with the specific product_number
            // We don't update here anymore to avoid double-processing and ensure we target the correct container
            error_log('[AEBG] Skipping Elementor update in savePostProducts - will be handled by Meta_Box.php with product_number');
        } else {
            error_log('[AEBG] Failed to save products to post ' . $post_id . ' - result1: ' . ($result1 !== false ? 'success' : 'failed') . ', result2: ' . ($result2 !== false ? 'success' : 'failed') . ', result3: ' . ($result3 !== false ? 'success' : 'failed'));
        }

        return $success;
    }
    
    /**
     * Update Elementor template variables for a specific product container
     * 
     * @param int $post_id Post ID
     * @param array $products Products array
     * @param int $product_number Product number (1-based) - only this container will be updated
     * @return bool Success status
     */
    public static function updateElementorTemplateVariablesForProduct($post_id, $products, $product_number) {
        error_log('[AEBG] updateElementorTemplateVariablesForProduct called with product_number: ' . ($product_number ?? 'null'));
        if ($product_number === null || $product_number === 0) {
            error_log('[AEBG] ERROR: updateElementorTemplateVariablesForProduct called without valid product_number!');
            return false;
        }
        return self::updateElementorTemplateVariables($post_id, $products, $product_number);
    }
    
    /**
     * Update Elementor template variables with current product data
     * 
     * @param int $post_id Post ID
     * @param array $products Products array
     * @param int|null $product_number Optional: Only update this specific product container (1-based)
     * @return bool Success status
     */
    private static function updateElementorTemplateVariables($post_id, $products, $product_number = null) {
        try {
            // Log call stack to see where this is being called from
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            error_log('[AEBG] ===== START: updateElementorTemplateVariables =====');
            error_log('[AEBG] Called from: ' . (isset($backtrace[1]['class']) ? $backtrace[1]['class'] : '') . '::' . (isset($backtrace[1]['function']) ? $backtrace[1]['function'] : '') . '() in ' . (isset($backtrace[1]['file']) ? basename($backtrace[1]['file']) : '') . ':' . (isset($backtrace[1]['line']) ? $backtrace[1]['line'] : ''));
            error_log('[AEBG] Post ID: ' . $post_id);
            error_log('[AEBG] Products count: ' . count($products));
            error_log('[AEBG] Product number parameter: ' . ($product_number !== null ? $product_number : 'NULL'));
            error_log('[AEBG] Products: ' . json_encode(array_map(function($p) {
                return $p ? ['name' => $p['name'] ?? 'unknown', 'id' => $p['id'] ?? 'unknown', 'price' => $p['price'] ?? 'N/A'] : null;
            }, $products)));
            
            // Check if post has Elementor data
            // Clear cache first to ensure we get fresh data
            wp_cache_delete($post_id, 'post_meta');
            $elementor_data = get_post_meta($post_id, '_elementor_data', true);
            if (empty($elementor_data)) {
                error_log('[AEBG] No Elementor data found for post ' . $post_id . ', skipping variable update');
                return true; // Not an error, just no Elementor data
            }
            
            // Decode if string
            if (is_string($elementor_data)) {
                $elementor_data = json_decode($elementor_data, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log('[AEBG] Failed to decode Elementor data: ' . json_last_error_msg());
                    return false;
                }
            }
            
            if (!is_array($elementor_data)) {
                error_log('[AEBG] Invalid Elementor data format for post ' . $post_id);
                return false;
            }
            
            // Get post title
            $post = get_post($post_id);
            $title = $post ? $post->post_title : '';
            
            // Update variables using Generator class
            $generator = new \AEBG\Core\Generator([]);
            
            // If product_number is specified, only update that specific container
            error_log('[AEBG] updateElementorTemplateVariables - product_number parameter: ' . ($product_number !== null ? $product_number : 'null'));
            if ($product_number !== null && $product_number > 0) {
                $target_css_id = 'product-' . $product_number;
                error_log('[AEBG] Updating variables only for product container: ' . $target_css_id);
                $updated_data = $generator->updateProductVariablesInContainer($elementor_data, $target_css_id, $products, $title);
            } else {
                // Update all products (fallback for when product number is not known)
                error_log('[AEBG] WARNING: Updating variables for all products (no specific product number provided)');
                $updated_data = $generator->updateProductVariablesInElementorData($elementor_data, $products, $title);
            }
            
            if (is_wp_error($updated_data)) {
                error_log('[AEBG] Failed to update Elementor variables: ' . $updated_data->get_error_message());
                return false;
            }
            
            // Save updated Elementor data
            error_log('[AEBG] Cleaning Elementor data for encoding...');
            $cleaned_data = \AEBG\Core\DataUtilities::cleanElementorDataForEncoding($updated_data);
            error_log('[AEBG] Encoding Elementor data to JSON...');
            $encoded_data = json_encode($cleaned_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            
            if ($encoded_data === false) {
                error_log('[AEBG] ERROR: Failed to encode updated Elementor data - ' . json_last_error_msg());
                return false;
            }
            
            $encoded_size = strlen($encoded_data);
            error_log('[AEBG] Encoded Elementor data size: ' . $encoded_size . ' bytes');
            
            // Get current data for comparison
            $current_data = get_post_meta($post_id, '_elementor_data', true);
            $current_size = is_string($current_data) ? strlen($current_data) : 0;
            error_log('[AEBG] Current Elementor data size: ' . $current_size . ' bytes');
            
            error_log('[AEBG] Saving updated Elementor data to post meta...');
            $update_result = update_post_meta($post_id, '_elementor_data', $encoded_data);
            error_log('[AEBG] update_post_meta result: ' . ($update_result !== false ? 'success' : 'failed'));
            if ($update_result === false) {
                // Verify if save actually worked
                error_log('[AEBG] update_post_meta returned false, verifying save...');
                $verify_data = get_post_meta($post_id, '_elementor_data', true);
                if (is_string($verify_data)) {
                    $verify_data = json_decode($verify_data, true);
                }
                $verify_size = is_string($verify_data) ? strlen($verify_data) : (is_array($verify_data) ? count($verify_data) : 0);
                error_log('[AEBG] Verified data size: ' . $verify_size);
                
                // Compare data structures
                if (is_array($verify_data) && is_array($updated_data)) {
                    // Check if key parts match (product variables should be updated)
                    $verify_json = json_encode($verify_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $updated_json = json_encode($updated_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($verify_json === $updated_json) {
                        error_log('[AEBG] ✅ Elementor variables updated successfully (value was same, save verified)');
                        return true;
                    } else {
                        error_log('[AEBG] ⚠️ WARNING: Data mismatch - verify_json length: ' . strlen($verify_json) . ', updated_json length: ' . strlen($updated_json));
                        // Try to find differences
                        $diff_start = 0;
                        $min_len = min(strlen($verify_json), strlen($updated_json));
                        for ($i = 0; $i < $min_len; $i++) {
                            if ($verify_json[$i] !== $updated_json[$i]) {
                                $diff_start = $i;
                                break;
                            }
                        }
                        error_log('[AEBG] First difference at position: ' . $diff_start);
                        error_log('[AEBG] Verify snippet: ' . substr($verify_json, $diff_start, 200));
                        error_log('[AEBG] Updated snippet: ' . substr($updated_json, $diff_start, 200));
                    }
                } else {
                    error_log('[AEBG] ⚠️ WARNING: Data type mismatch - verify_data type: ' . gettype($verify_data) . ', updated_data type: ' . gettype($updated_data));
                }
                error_log('[AEBG] ❌ Failed to save updated Elementor data');
                return false;
            } else {
                // Verify the save was successful
                error_log('[AEBG] Verifying saved data...');
                $verify_data = get_post_meta($post_id, '_elementor_data', true);
                $verify_size = is_string($verify_data) ? strlen($verify_data) : 0;
                error_log('[AEBG] ✅ Verified saved data size: ' . $verify_size . ' bytes');
            }
            
            // CRITICAL: Clear Elementor cache and regenerate CSS/JS after variable update
            // This ensures frontend and editor show the updated product data immediately
            self::clearElementorCacheAfterUpdate($post_id);
            
            error_log('[AEBG] Elementor template variables updated successfully for post ' . $post_id);
            error_log('[AEBG] ===== SUCCESS: updateElementorTemplateVariables completed =====');
            return true;
            
        } catch (\Exception $e) {
            error_log('[AEBG] Exception updating Elementor variables: ' . $e->getMessage());
            error_log('[AEBG] Stack trace: ' . $e->getTraceAsString());
            error_log('[AEBG] ===== ERROR: updateElementorTemplateVariables failed =====');
            return false;
        }
    }
    
    /**
     * Clear Elementor cache and regenerate CSS/JS after updating variables
     * 
     * @param int $post_id Post ID
     * @return void
     */
    private static function clearElementorCacheAfterUpdate($post_id) {
        error_log('[AEBG] Clearing Elementor cache and regenerating CSS/JS for post ' . $post_id);
        
        // Clear Elementor-specific cache meta
        delete_post_meta($post_id, '_elementor_data_cache');
        
        // Clear WordPress object cache for this post
        wp_cache_delete($post_id, 'post_meta');
        clean_post_cache($post_id);
        
        // Clear Elementor global cache if available
        if (class_exists('\Elementor\Plugin')) {
            try {
                $elementor = \Elementor\Plugin::instance();
                
                // Clear Elementor's internal cache
                if (method_exists($elementor, 'files_manager')) {
                    $elementor->files_manager->clear_cache();
                }
                
                // Clear Elementor's CSS cache
                if (method_exists($elementor, 'kits_manager')) {
                    $elementor->kits_manager->clear_cache();
                }
                
                // Regenerate CSS file
                if (class_exists('\Elementor\Core\Files\CSS\Post')) {
                    $css_file = \Elementor\Core\Files\CSS\Post::create($post_id);
                    if ($css_file) {
                        $css_file->delete();
                        $css_file->update(); // Generate and write the CSS file
                        error_log('[AEBG] Regenerated Elementor CSS file for post ' . $post_id);
                    }
                }
                
                // Regenerate JS file
                if (class_exists('\Elementor\Core\Files\JS\Post')) {
                    $js_file = \Elementor\Core\Files\JS\Post::create($post_id);
                    if ($js_file) {
                        $js_file->delete();
                        $js_file->update(); // Generate and write the JS file
                        error_log('[AEBG] Regenerated Elementor JS file for post ' . $post_id);
                    }
                }
                
                // Clear Elementor's documents cache and force document refresh
                // CRITICAL: This ensures Elementor editor recognizes the updated data
                if (isset($elementor->documents) && is_object($elementor->documents) && method_exists($elementor->documents, 'get')) {
                    $document = $elementor->documents->get($post_id);
                    if ($document) {
                        // Delete autosave to force fresh load
                        if (method_exists($document, 'delete_autosave')) {
                            $document->delete_autosave();
                        }
                        // Force document to recognize the updated data
                        // This ensures Elementor editor sees the changes immediately
                        if (method_exists($document, 'save')) {
                            // Get the updated elementor data to save with document
                            $updated_elementor_data = get_post_meta($post_id, '_elementor_data', true);
                            if (!empty($updated_elementor_data)) {
                                if (is_string($updated_elementor_data)) {
                                    $updated_elementor_data = json_decode($updated_elementor_data, true);
                                }
                                if (is_array($updated_elementor_data)) {
                                    $document->save(['elements' => $updated_elementor_data]);
                                    error_log('[AEBG] Saved Elementor document with updated elements for post ' . $post_id);
                                }
                            }
                        }
                    }
                }
                
                // Trigger Elementor hooks for complete refresh
                do_action('elementor/core/files/clear_cache');
                do_action('elementor/css-file/clear_cache');
                do_action('elementor/js-file/clear_cache');
                
                // Force update post modified time to invalidate all caches
                // This ensures WordPress and Elementor recognize the content has changed
                wp_update_post([
                    'ID' => $post_id,
                    'post_modified' => current_time('mysql'),
                    'post_modified_gmt' => current_time('mysql', 1)
                ], true);
                
                error_log('[AEBG] Elementor cache cleared and CSS/JS regenerated successfully');
            } catch (\Exception $e) {
                error_log('[AEBG] Error clearing Elementor cache: ' . $e->getMessage());
            }
        }
    }

    /**
     * Get product context for a post
     * 
     * @param int $post_id Post ID
     * @return array Context data
     */
    public static function getProductContext($post_id) {
        if (!$post_id) {
            return [];
        }

        $context = get_post_meta($post_id, '_aebg_product_context', true);
        return is_array($context) ? $context : [];
    }

    /**
     * Save product context for a post
     * 
     * @param int $post_id Post ID
     * @param array $context Context data
     * @return bool Success status
     */
    public static function saveProductContext($post_id, $context) {
        if (!$post_id || !is_array($context)) {
            return false;
        }

        $result = update_post_meta($post_id, '_aebg_product_context', $context);
        return $result !== false;
    }

    /**
     * Check if a post has products
     * 
     * @param int $post_id Post ID
     * @return bool True if post has products
     */
    public static function hasProducts($post_id) {
        if (!$post_id) {
            return false;
        }

        $count = get_post_meta($post_id, '_aebg_product_count', true);
        if ($count !== '') {
            return (int) $count > 0;
        }

        $products = self::getPostProducts($post_id);
        return !empty($products);
    }

    /**
     * Get product count for a post
     * 
     * @param int $post_id Post ID
     * @return int Number of products
     */
    public static function getProductCount($post_id) {
        if (!$post_id) {
            return 0;
        }

        $count = get_post_meta($post_id, '_aebg_product_count', true);
        if ($count !== '') {
            return (int) $count;
        }

        $products = self::getPostProducts($post_id);
        return count($products);
    }

    /**
     * Format product price for display
     * 
     * @deprecated Use CurrencyManager::formatPrice() directly
     * This method is kept for backward compatibility but now delegates to CurrencyManager
     * 
     * @param float $price Price value
     * @param string $currency Currency code
     * @return string Formatted price
     */
    public static function formatPrice($price, $currency = 'USD') {
        // Delegate to CurrencyManager for unified formatting
        return CurrencyManager::formatPrice($price, $currency);
    }


    /**
     * Get product rating display
     * 
     * @param float $rating Rating value
     * @param int $max_rating Maximum rating (default 5)
     * @return string HTML for rating display
     */
    public static function getRatingDisplay($rating, $max_rating = 5) {
        if (!is_numeric($rating) || $rating <= 0) {
            return '';
        }

        $rating = min($rating, $max_rating);
        $full_stars = floor($rating);
        $has_half_star = ($rating - $full_stars) >= 0.5;
        
        $html = '<div class="aebg-rating">';
        $html .= '<span class="aebg-rating-value">' . number_format($rating, 1) . '</span>';
        $html .= '<span class="aebg-rating-stars">';
        
        for ($i = 1; $i <= $max_rating; $i++) {
            if ($i <= $full_stars) {
                $html .= '<span class="aebg-star aebg-star-full">★</span>';
            } elseif ($i == $full_stars + 1 && $has_half_star) {
                $html .= '<span class="aebg-star aebg-star-half">☆</span>';
            } else {
                $html .= '<span class="aebg-star aebg-star-empty">☆</span>';
            }
        }
        
        $html .= '</span>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Fix malformed URLs (handles s// issue)
     * Static method for use in ProductManager
     * 
     * @param string $url The URL to fix
     * @return string The fixed URL
     */
    
    /**
     * Normalize product data before saving
     * Handles field mapping, currency detection, and network assignment
     * 
     * @param array $product_data Raw product data
     * @param int|null $post_id Post ID (optional, for context)
     * @param array $existing_products Existing products array (optional, for preservation)
     * @return array Normalized product data
     */
    public static function normalizeProductData($product_data, $post_id = null, $existing_products = []) {
        // 1. Normalize image field (map 'image' to 'image_url')
        if (empty($product_data['image_url']) && !empty($product_data['image'])) {
            $product_data['image_url'] = $product_data['image'];
        }
        
        // 2. Detect and assign currency using CurrencyManager
        if (empty($product_data['currency'])) {
            $product_data['currency'] = CurrencyManager::getProductCurrency(
                $product_data,
                $existing_products,
                $post_id
            );
        }
        
        // 3. Detect and assign network
        if (empty($product_data['network'])) {
            // Check multiple possible field names for network
            $network_fields = ['network', 'network_name', 'source_name', 'source', 'network_code'];
            foreach ($network_fields as $field) {
                if (!empty($product_data[$field])) {
                    $product_data['network'] = $product_data[$field];
                    break;
                }
            }
            
            // If still no network, preserve from existing products
            if (empty($product_data['network']) && !empty($existing_products)) {
                $first_product = reset($existing_products);
                if (!empty($first_product['network'])) {
                    $product_data['network'] = $first_product['network'];
                }
            }
            
            // If still no network and only one network is configured, use it
            if (empty($product_data['network'])) {
                $networks = \AEBG\Core\Network_API_Manager::get_active_networks();
                if (count($networks) === 1) {
                    $network = reset($networks);
                    $product_data['network'] = $network['network_name'] ?? $network['network_key'] ?? '';
                }
            }
        }
        
        return $product_data;
    }
    
    /**
     * Preserve important fields from old product when replacing
     * 
     * @param array $new_product_data New product data
     * @param array $old_product Old product data
     * @return array Product data with preserved fields
     */
    public static function preserveProductFieldsOnReplacement($new_product_data, $old_product) {
        $fields_to_preserve = ['currency', 'network', 'image_url'];
        
        foreach ($fields_to_preserve as $field) {
            if (empty($new_product_data[$field]) && !empty($old_product[$field])) {
                $new_product_data[$field] = $old_product[$field];
                // Only log in debug mode
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[AEBG] Preserved ' . $field . ' from old product' . ($field !== 'image_url' ? ': ' . $new_product_data[$field] : ''));
                }
            }
        }
        
        return $new_product_data;
    }
} 