<?php

namespace AEBG\Core;

/**
 * Product Transaction Manager
 * 
 * Provides transaction-like behavior for product save operations
 * with automatic rollback on failure
 * 
 * @package AEBG\Core
 */
class ProductTransactionManager {
    
    /**
     * Execute product save with transaction-like behavior
     * 
     * @param int $post_id Post ID
     * @param array $new_products New products array
     * @param callable $operation Operation to execute (save, regenerate, etc.)
     * @return array ['success' => bool, 'data' => mixed, 'error' => string|null]
     */
    public static function executeWithRollback($post_id, $new_products, $operation) {
        // 1. Backup current state
        $backup = self::backupProductState($post_id);
        
        // 2. Execute operation
        try {
            $result = call_user_func($operation, $post_id, $new_products);
            
            // 3. Validate result
            if (self::validateOperation($post_id, $new_products)) {
                return [
                    'success' => true,
                    'data' => $result,
                    'error' => null
                ];
            } else {
                // Validation failed - rollback
                self::rollback($post_id, $backup);
                return [
                    'success' => false,
                    'data' => null,
                    'error' => 'Operation validation failed'
                ];
            }
        } catch (\Exception $e) {
            // Exception occurred - rollback
            self::rollback($post_id, $backup);
            return [
                'success' => false,
                'data' => null,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Backup current product state
     * 
     * @param int $post_id Post ID
     * @return array Backup data
     */
    private static function backupProductState($post_id) {
        return [
            'products' => get_post_meta($post_id, '_aebg_products', true),
            'elementor_data' => get_post_meta($post_id, '_elementor_data', true),
            'timestamp' => time()
        ];
    }
    
    /**
     * Rollback to previous state
     * 
     * @param int $post_id Post ID
     * @param array $backup Backup data
     * @return void
     */
    private static function rollback($post_id, $backup) {
        if (isset($backup['products'])) {
            update_post_meta($post_id, '_aebg_products', $backup['products']);
        }
        if (isset($backup['elementor_data'])) {
            update_post_meta($post_id, '_elementor_data', $backup['elementor_data']);
        }
        
        // Clear caches
        wp_cache_delete($post_id, 'post_meta');
        clean_post_cache($post_id);
        
        // Log rollback
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[AEBG] ProductTransactionManager: Rolled back product state for post ' . $post_id);
        }
    }
    
    /**
     * Validate operation succeeded
     * 
     * @param int $post_id Post ID
     * @param array $expected_products Expected products array
     * @return bool True if validation passed
     */
    private static function validateOperation($post_id, $expected_products) {
        $saved_products = get_post_meta($post_id, '_aebg_products', true);
        
        if (!is_array($saved_products)) {
            return false;
        }
        
        // Basic validation: count should match
        if (count($saved_products) !== count($expected_products)) {
            return false;
        }
        
        // Validate each product has required fields
        foreach ($saved_products as $product) {
            if (empty($product['id']) || empty($product['name'])) {
                return false;
            }
        }
        
        return true;
    }
}

