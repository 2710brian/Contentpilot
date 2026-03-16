<?php

namespace AEBG\Core;

/**
 * Merchant Cache Class
 * 
 * Handles 30-day caching for merchant comparison data to reduce API calls
 * and improve performance according to Datafeedr API best practices.
 * 
 * @package AEBG\Core
 */
class MerchantCache {
    
    /**
     * Cache duration in seconds (30 days)
     */
    private const CACHE_DURATION = 30 * DAY_IN_SECONDS;
    
    /**
     * Cache prefix for merchant data
     */
    private const CACHE_PREFIX = 'aebg_merchant_cache_';
    
    /**
     * Get cached merchant data for a product
     * 
     * @param string $product_id Product ID
     * @param int|null $post_id Optional post ID for post-specific caching
     * @return array|null Cached merchant data or null if not found/expired
     */
    public static function get($product_id, $post_id = null) {
        if (empty($product_id)) {
            return null;
        }
        
        $cache_key = self::get_cache_key($product_id, $post_id);
        $cached_data = get_transient($cache_key);
        
        if ($cached_data !== false) {
            error_log('[AEBG] MerchantCache: Using cached data for product ' . $product_id . ($post_id ? ' (post ' . $post_id . ')' : ''));
            return $cached_data;
        }
        
        error_log('[AEBG] MerchantCache: No cached data found for product ' . $product_id . ($post_id ? ' (post ' . $post_id . ')' : ''));
        return null;
    }
    
    /**
     * Cache merchant data for a product
     * 
     * @param string $product_id Product ID
     * @param array $merchant_data Merchant data to cache
     * @return bool True on success, false on failure
     */
    public static function set($product_id, $merchant_data) {
        if (empty($product_id) || empty($merchant_data)) {
            error_log('[AEBG] MerchantCache: Invalid data provided for caching');
            return false;
        }
        
        $cache_key = self::get_cache_key($product_id);
        $result = set_transient($cache_key, $merchant_data, self::CACHE_DURATION);
        
        if ($result) {
            error_log('[AEBG] MerchantCache: Successfully cached merchant data for product ' . $product_id);
        } else {
            error_log('[AEBG] MerchantCache: Failed to cache merchant data for product ' . $product_id);
        }
        
        return $result;
    }
    
    /**
     * Clear cache for a specific product
     * 
     * @param string $product_id Product ID
     * @return bool True on success, false on failure
     */
    public static function clear($product_id) {
        if (empty($product_id)) {
            return false;
        }
        
        $cache_key = self::get_cache_key($product_id);
        $result = delete_transient($cache_key);
        
        if ($result) {
            error_log('[AEBG] MerchantCache: Successfully cleared cache for product ' . $product_id);
        } else {
            error_log('[AEBG] MerchantCache: Failed to clear cache for product ' . $product_id);
        }
        
        return $result;
    }
    
    /**
     * Clear all merchant caches
     * 
     * @return int Number of caches cleared
     */
    public static function clear_all() {
        global $wpdb;
        
        $prefix = self::CACHE_PREFIX;
        $transient_prefix = '_transient_';
        $timeout_prefix = '_transient_timeout_';
        
        // Delete transient data
        $transient_deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $transient_prefix . $prefix . '%'
            )
        );
        
        // Delete timeout data
        $timeout_deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $timeout_prefix . $prefix . '%'
            )
        );
        
        $total_deleted = $transient_deleted + $timeout_deleted;
        error_log('[AEBG] MerchantCache: Cleared ' . $total_deleted . ' cached items');
        
        return $total_deleted;
    }
    
    /**
     * Get cache statistics
     * 
     * @return array Cache statistics
     */
    public static function get_stats() {
        global $wpdb;
        
        $prefix = self::CACHE_PREFIX;
        $transient_prefix = '_transient_';
        
        // Count cached items
        $cached_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                $transient_prefix . $prefix . '%'
            )
        );
        
        // Get cache size
        $cache_size = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE option_name LIKE %s",
                $transient_prefix . $prefix . '%'
            )
        );
        
        return [
            'cached_items' => (int) $cached_count,
            'cache_size_bytes' => (int) $cache_size,
            'cache_size_mb' => round(($cache_size / 1024 / 1024), 2),
            'cache_duration_days' => self::CACHE_DURATION / DAY_IN_SECONDS
        ];
    }
    
    /**
     * Check if cache is enabled and working
     * 
     * @return bool True if cache is working, false otherwise
     */
    public static function is_working() {
        $test_key = self::get_cache_key('test');
        $test_data = ['test' => 'data'];
        
        // Try to set and get test data
        $set_result = set_transient($test_key, $test_data, 60);
        $get_result = get_transient($test_key);
        $delete_result = delete_transient($test_key);
        
        return $set_result && $get_result === $test_data && $delete_result;
    }
    
    /**
     * Get cache key for a product
     * 
     * @param string $product_id Product ID
     * @return string Cache key
     */
    private static function get_cache_key($product_id) {
        return self::CACHE_PREFIX . md5($product_id);
    }
    
    /**
     * Get cache expiration time for a product
     * 
     * @param string $product_id Product ID
     * @return int|false Expiration timestamp or false if not found
     */
    public static function get_expiration($product_id) {
        if (empty($product_id)) {
            return false;
        }
        
        $cache_key = self::get_cache_key($product_id);
        $timeout_key = '_transient_timeout_' . $cache_key;
        
        return get_option($timeout_key, false);
    }
    
    /**
     * Check if cache is expired for a product
     * 
     * @param string $product_id Product ID
     * @return bool True if expired or not found, false if still valid
     */
    public static function is_expired($product_id) {
        $expiration = self::get_expiration($product_id);
        
        if ($expiration === false) {
            return true; // Not found, consider expired
        }
        
        return $expiration < time();
    }
    
    /**
     * Get cache age for a product
     * 
     * @param string $product_id Product ID
     * @return int|false Age in seconds or false if not found
     */
    public static function get_age($product_id) {
        $expiration = self::get_expiration($product_id);
        
        if ($expiration === false) {
            return false;
        }
        
        $age = time() - ($expiration - self::CACHE_DURATION);
        return max(0, $age);
    }
} 