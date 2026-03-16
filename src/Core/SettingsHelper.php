<?php

namespace AEBG\Core;

/**
 * Settings Helper Class
 * 
 * Centralized access to plugin settings with caching and convenience methods.
 * Reduces code duplication and provides type-safe access to settings.
 * 
 * @package AEBG\Core
 */
class SettingsHelper {
	
	/**
	 * Settings cache to avoid multiple get_option calls
	 * 
	 * @var array|null
	 */
	private static $settings_cache = null;
	
	/**
	 * Get a setting value
	 * 
	 * @param string $key Setting key
	 * @param mixed $default Default value if setting doesn't exist
	 * @return mixed Setting value or default
	 */
	public static function get( $key, $default = null ) {
		if ( self::$settings_cache === null ) {
			self::$settings_cache = get_option( 'aebg_settings', [] );
		}
		
		return self::$settings_cache[ $key ] ?? $default;
	}
	
	/**
	 * Clear the settings cache
	 * Call this after settings are updated
	 * 
	 * @return void
	 */
	public static function clearCache() {
		self::$settings_cache = null;
	}
	
	/**
	 * Get delay between requests in seconds
	 * 
	 * @return float Delay in seconds (0-10)
	 */
	public static function getDelayBetweenRequests() {
		$delay = self::get( 'delay_between_requests', 1 );
		return max( 0, min( 10, floatval( $delay ) ) );
	}
	
	/**
	 * Get delay between requests in microseconds
	 * 
	 * @return int Delay in microseconds
	 */
	public static function getDelayBetweenRequestsMicroseconds() {
		return intval( self::getDelayBetweenRequests() * 1000000 );
	}
	
	/**
	 * Get batch size for price comparison
	 * 
	 * @return int Batch size (1-20)
	 */
	public static function getBatchSize() {
		$batch_size = self::get( 'batch_size', 5 );
		return max( 1, min( 20, intval( $batch_size ) ) );
	}
	
	/**
	 * Check if duplicate detection is enabled
	 * 
	 * @return bool
	 */
	public static function isDuplicateDetectionEnabled() {
		return self::get( 'enable_duplicate_detection', true );
	}
	
	/**
	 * Check if merchant discovery is enabled
	 * 
	 * @return bool
	 */
	public static function isMerchantDiscoveryEnabled() {
		return self::get( 'enable_merchant_discovery', true );
	}
	
	/**
	 * Get maximum merchants per product
	 * 
	 * @return int Maximum merchants (1-10)
	 */
	public static function getMaxMerchantsPerProduct() {
		$max = self::get( 'max_merchants_per_product', 5 );
		return max( 1, min( 10, intval( $max ) ) );
	}
	
	/**
	 * Check if price comparison is enabled
	 * 
	 * @return bool
	 */
	public static function isPriceComparisonEnabled() {
		return self::get( 'enable_price_comparison', true );
	}
	
	/**
	 * Get minimum merchant count for price comparison
	 * 
	 * @return int Minimum merchant count (1-10)
	 */
	public static function getMinMerchantCount() {
		$min = self::get( 'min_merchant_count', 2 );
		return max( 1, min( 10, intval( $min ) ) );
	}
	
	/**
	 * Get maximum merchant count for price comparison
	 * 
	 * @return int Maximum merchant count (2-20)
	 */
	public static function getMaxMerchantCount() {
		$max = self::get( 'max_merchant_count', 10 );
		return max( 2, min( 20, intval( $max ) ) );
	}
	
	/**
	 * Check if filter by price comparison is enabled
	 * 
	 * @return bool
	 */
	public static function isFilterByPriceComparisonEnabled() {
		return self::get( 'filter_by_price_comparison', false );
	}
	
	/**
	 * Check if sort by price comparison is enabled
	 * 
	 * @return bool
	 */
	public static function isSortByPriceComparisonEnabled() {
		return self::get( 'sort_by_price_comparison', false );
	}
	
	/**
	 * Get all duplicate detection options
	 * 
	 * @return array Duplicate detection options
	 */
	public static function getDuplicateDetectionOptions() {
		return [
			'prevent_same_product_different_suppliers' => self::get( 'prevent_same_product_different_suppliers', true ),
			'prevent_same_product_different_colors' => self::get( 'prevent_same_product_different_colors', true ),
			'similarity_threshold' => self::get( 'duplicate_similarity_threshold', 0.85 ),
			'prefer_higher_rating' => self::get( 'prefer_higher_rating_for_duplicates', true ),
			'prefer_more_reviews' => self::get( 'prefer_more_reviews_for_duplicates', true ),
		];
	}
	
	/**
	 * Get all price comparison options
	 * 
	 * @return array Price comparison options
	 */
	public static function getPriceComparisonOptions() {
		return [
			'enable_price_comparison' => self::isPriceComparisonEnabled(),
			'min_merchant_count' => self::getMinMerchantCount(),
			'max_merchant_count' => self::getMaxMerchantCount(),
			'prefer_lower_prices' => self::get( 'prefer_lower_prices', true ),
			'prefer_higher_ratings' => self::get( 'prefer_higher_ratings', true ),
			'price_variance_threshold' => self::get( 'price_variance_threshold', 0.3 ),
			'batch_size' => self::getBatchSize(),
		];
	}
}

