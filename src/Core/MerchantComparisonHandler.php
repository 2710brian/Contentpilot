<?php

namespace AEBG\Core;

/**
 * Merchant Comparison Handler Class
 * 
 * Handles asynchronous merchant comparison processing after post creation
 * 
 * @package AEBG\Core
 */
class MerchantComparisonHandler {
	
	/**
	 * MerchantComparisonHandler constructor.
	 */
	public function __construct() {
		add_action( 'aebg_process_merchant_comparison', [ $this, 'execute' ] );
	}

	/**
	 * Execute merchant comparison processing for a post
	 *
	 * @param array $args Action arguments containing 'post_id'.
	 */
	public function execute( $args ) {
		global $wpdb;
		
		// Extract post_id from args (Action Scheduler passes args as array)
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : ( is_numeric( $args ) ? (int) $args : 0 );
		
		if ( ! $post_id ) {
			error_log( '[AEBG] MerchantComparisonHandler::execute - Invalid post_id: ' . var_export( $args, true ) );
			return;
		}
		
		error_log( '[AEBG] MerchantComparisonHandler::execute - Processing merchant comparison for post ID: ' . $post_id );
		
		// Verify post exists
		$post = get_post( $post_id );
		if ( ! $post ) {
			error_log( '[AEBG] MerchantComparisonHandler::execute - Post not found: ' . $post_id );
			return;
		}
		
		// Get products from post meta
		$products = get_post_meta( $post_id, '_aebg_products', true );
		if ( ! is_array( $products ) || empty( $products ) ) {
			error_log( '[AEBG] MerchantComparisonHandler::execute - No products found for post: ' . $post_id );
			return;
		}
		
		error_log( '[AEBG] MerchantComparisonHandler::execute - Found ' . count( $products ) . ' products for post: ' . $post_id );
		
		// Get user ID from post author
		$user_id = (int) $post->post_author;
		if ( ! $user_id ) {
			error_log( '[AEBG] MerchantComparisonHandler::execute - No author found for post: ' . $post_id );
			return;
		}
		
		// Initialize Datafeedr
		$datafeedr = new Datafeedr();
		
		// Process each product
		$processed_count = 0;
		$product_index = 0;
		foreach ( $products as $product ) {
			$product_index++;
			if ( empty( $product['id'] ) ) {
				continue;
			}
			
			// CRITICAL: Add delay before each API call (except first) to prevent connection pool exhaustion
			// When processing multiple products, rapid API calls can exhaust the connection pool
			// This delay allows the connection pool to recover between requests
			if ($product_index > 1) {
				$delay_microseconds = \AEBG\Core\SettingsHelper::getDelayBetweenRequestsMicroseconds();
				$delay_seconds = \AEBG\Core\SettingsHelper::getDelayBetweenRequests();
				error_log('[AEBG] MerchantComparisonHandler: Adding ' . $delay_seconds . 's delay before API call for product ' . $product_index . ' to prevent connection pool exhaustion');
				usleep($delay_microseconds);
				// Force garbage collection every 5 products to help free up connections
				if ($product_index % 5 === 0) {
					gc_collect_cycles();
					error_log('[AEBG] MerchantComparisonHandler: Garbage collection performed after product ' . $product_index);
				}
				error_log('[AEBG] MerchantComparisonHandler: Delay completed, proceeding with API call');
			}
			
			try {
				error_log( '[AEBG] MerchantComparisonHandler::execute - Processing product: ' . ( $product['name'] ?? $product['id'] ) );
				
				// Get merchant comparison data
				$merchant_data = $datafeedr->get_merchant_comparison( $product, 10 );
				
				if ( is_wp_error( $merchant_data ) ) {
					error_log( '[AEBG] MerchantComparisonHandler::execute - Error fetching merchant data: ' . $merchant_data->get_error_message() );
					continue;
				}
				
				if ( empty( $merchant_data['merchants'] ) || ! is_array( $merchant_data['merchants'] ) ) {
					error_log( '[AEBG] MerchantComparisonHandler::execute - No merchants found for product: ' . $product['id'] );
					continue;
				}
				
				// Save comparison data to database
				$save_result = \AEBG\Core\ComparisonManager::save_comparison(
					$user_id,
					$post_id,
					$product['id'],
					'Async Merchant Comparison',
					$merchant_data
				);
				
				if ( $save_result !== false ) {
					$processed_count++;
					error_log( '[AEBG] MerchantComparisonHandler::execute - Successfully saved comparison data for product: ' . $product['id'] );
				} else {
					error_log( '[AEBG] MerchantComparisonHandler::execute - Failed to save comparison data for product: ' . $product['id'] );
				}
				
			} catch ( \Exception $e ) {
				error_log( '[AEBG] MerchantComparisonHandler::execute - Exception processing product ' . $product['id'] . ': ' . $e->getMessage() );
				continue;
			}
		}
		
		error_log( '[AEBG] MerchantComparisonHandler::execute - Completed processing ' . $processed_count . ' products for post: ' . $post_id );
		
		// CRITICAL: Clean up database connections to prevent "too many connections" errors
		// This is especially important when multiple handlers run concurrently
		$this->cleanup_database_connections();
	}
	
	/**
	 * Clean up database connections to prevent connection leaks
	 * 
	 * @return void
	 */
	private function cleanup_database_connections() {
		global $wpdb;
		
		if ( ! $wpdb ) {
			return;
		}
		
		// Consume all unprocessed MySQL results
		if ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof \mysqli ) {
			$mysqli = $wpdb->dbh;
			
			// Consume current result if any
			if ( $result = $mysqli->store_result() ) {
				$result->free();
			}
			
			// Consume all remaining results from multi-query
			$max_iterations = 50; // Safety limit
			$iteration = 0;
			while ( $mysqli->more_results() && $iteration < $max_iterations ) {
				$iteration++;
				$mysqli->next_result();
				if ( $result = $mysqli->store_result() ) {
					$result->free();
				}
				if ( $mysqli->errno ) {
					break; // Stop on error
				}
			}
		}
		
		// Flush WordPress query cache
		$wpdb->flush();
		$wpdb->last_error = '';
		$wpdb->last_query = '';
		
		// Clear object cache to free memory
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'posts' );
			wp_cache_flush_group( 'post_meta' );
		}
	}
}

