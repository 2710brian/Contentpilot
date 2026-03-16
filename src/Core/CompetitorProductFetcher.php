<?php

namespace AEBG\Core;

use AEBG\Core\Logger;
use AEBG\Core\CompetitorScraper;
use AEBG\Core\CompetitorAnalyzer;
use AEBG\Core\CompetitorProductConverter;
use AEBG\Core\CompetitorTrackingManager;

/**
 * Competitor Product Fetcher
 * 
 * Fetches and extracts products from competitor URLs on-demand
 * 
 * @package AEBG\Core
 */
class CompetitorProductFetcher {
	
	/**
	 * Fetch products from competitor URL
	 * 
	 * @param string $competitor_url URL to scrape
	 * @param int    $count          Number of products needed (optional, returns all if not specified)
	 * @param array  $config         Optional configuration (can include 'ai_prompt_template')
	 * @return array|\WP_Error Products array or error
	 */
	public function fetchProducts( string $competitor_url, int $count = 0, array $config = [] ): array|\WP_Error {
		Logger::info( 'Fetching products from competitor URL', [
			'url' => $competitor_url,
			'count' => $count,
		] );
		
		// Validate URL
		$competitor_url = esc_url_raw( $competitor_url );
		if ( ! filter_var( $competitor_url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'invalid_url', __( 'Invalid competitor URL provided.', 'aebg' ) );
		}
		
		// Step 1: Scrape the URL
		$scraper = new CompetitorScraper();
		$scraped_data = $scraper->scrape( $competitor_url );
		
		if ( is_wp_error( $scraped_data ) ) {
			Logger::error( 'Failed to scrape competitor URL', [
				'url' => $competitor_url,
				'error' => $scraped_data->get_error_message(),
			] );
			return $scraped_data;
		}
		
		// Step 2: Analyze with AI to extract products
		$analyzer = new CompetitorAnalyzer();
		$products = $analyzer->analyze( $scraped_data['content'], $config );
		
		if ( is_wp_error( $products ) ) {
			Logger::error( 'Failed to analyze competitor content', [
				'url' => $competitor_url,
				'error' => $products->get_error_message(),
			] );
			return $products;
		}
		
		if ( empty( $products ) || ! is_array( $products ) ) {
			return new \WP_Error( 'no_products', __( 'No products found on competitor page.', 'aebg' ) );
		}
		
		// Step 3: Convert to generator format
		$converter = new CompetitorProductConverter();
		$converted_products = $converter->convertProducts( $products, $count );
		
		if ( empty( $converted_products ) ) {
			return new \WP_Error( 'conversion_failed', __( 'Failed to convert products to generator format.', 'aebg' ) );
		}
		
		Logger::info( 'Successfully fetched products from competitor', [
			'url' => $competitor_url,
			'found_count' => count( $products ),
			'converted_count' => count( $converted_products ),
		] );
		
		return $converted_products;
	}
	
	/**
	 * Get latest products from existing competitor
	 * 
	 * @param int $competitor_id Competitor ID
	 * @param int $count         Number of products needed (optional, returns all if not specified)
	 * @return array|\WP_Error Products array or error
	 */
	public function getProductsFromCompetitor( int $competitor_id, int $count = 0 ): array|\WP_Error {
		global $wpdb;
		
		Logger::info( 'Getting products from existing competitor', [
			'competitor_id' => $competitor_id,
			'count' => $count,
		] );
		
		// Get competitor
		$competitor = CompetitorTrackingManager::get_competitor( $competitor_id );
		if ( ! $competitor ) {
			return new \WP_Error( 'competitor_not_found', __( 'Competitor not found.', 'aebg' ) );
		}
		
		// Get latest scrape ID
		$latest_scrape_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}aebg_competitor_scrapes 
			WHERE competitor_id = %d AND status = 'completed' 
			ORDER BY id DESC LIMIT 1",
			$competitor_id
		) );
		
		if ( ! $latest_scrape_id ) {
			// No completed scrape found, perform on-demand scrape
			Logger::info( 'No completed scrape found, performing on-demand scrape', [
				'competitor_id' => $competitor_id,
				'url' => $competitor['url'],
			] );
			return $this->fetchProducts( $competitor['url'], $count );
		}
		
		// Get products from latest scrape
		$products = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}aebg_competitor_products 
			WHERE competitor_id = %d AND scrape_id = %d 
			ORDER BY position ASC",
			$competitor_id,
			$latest_scrape_id
		), ARRAY_A );
		
		if ( empty( $products ) ) {
			return new \WP_Error( 'no_products', __( 'No products found for this competitor.', 'aebg' ) );
		}
		
		// Limit to requested count if specified
		if ( $count > 0 && $count < count( $products ) ) {
			$products = array_slice( $products, 0, $count );
		}
		
		// Convert to generator format
		$converter = new CompetitorProductConverter();
		$converted_products = $converter->convertProducts( $products, 0 ); // 0 = convert all provided
		
		if ( empty( $converted_products ) ) {
			return new \WP_Error( 'conversion_failed', __( 'Failed to convert products to generator format.', 'aebg' ) );
		}
		
		Logger::info( 'Successfully retrieved products from competitor', [
			'competitor_id' => $competitor_id,
			'found_count' => count( $products ),
			'converted_count' => count( $converted_products ),
		] );
		
		return $converted_products;
	}
}

