<?php

namespace AEBG\Core;

use AEBG\Core\Logger;

/**
 * Competitor Product Converter
 * 
 * Converts competitor product format to generator-compatible format
 * 
 * @package AEBG\Core
 */
class CompetitorProductConverter {
	
	/**
	 * Convert competitor products to generator format
	 * 
	 * @param array $competitor_products Products from competitor tracking
	 * @param int   $count               Number of products to convert (optional, converts all if not specified)
	 * @return array Generator-compatible products
	 */
	public function convertProducts( array $competitor_products, int $count = 0 ): array {
		Logger::info( 'Converting competitor products to generator format', [
			'total_products' => count( $competitor_products ),
			'requested_count' => $count,
		] );
		
		// Limit to requested count if specified
		if ( $count > 0 && $count < count( $competitor_products ) ) {
			$competitor_products = array_slice( $competitor_products, 0, $count );
		}
		
		$converted_products = [];
		
		foreach ( $competitor_products as $index => $competitor_product ) {
			$converted = $this->convertSingleProduct( $competitor_product, $index );
			if ( $converted ) {
				$converted_products[] = $converted;
			}
		}
		
		Logger::info( 'Product conversion completed', [
			'converted_count' => count( $converted_products ),
		] );
		
		return $converted_products;
	}
	
	/**
	 * Convert single competitor product
	 * 
	 * @param array $competitor_product Single competitor product
	 * @param int   $index              Product index (0-based)
	 * @return array|null Generator-compatible product or null if invalid
	 */
	private function convertSingleProduct( array $competitor_product, int $index ): ?array {
		// Extract product name
		$name = $competitor_product['name'] ?? $competitor_product['product_name'] ?? '';
		if ( empty( $name ) ) {
			Logger::warning( 'Skipping product with no name', [ 'index' => $index ] );
			return null;
		}
		
		// Parse product_data if it's a string
		$product_data = $competitor_product['product_data'] ?? [];
		if ( is_string( $product_data ) ) {
			$product_data = json_decode( $product_data, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$product_data = [];
			}
		}
		if ( ! is_array( $product_data ) ) {
			$product_data = [];
		}
		
		// Extract fields from product_data (handle various field name variations)
		$price = $product_data['price'] ?? $product_data['Price'] ?? $product_data['actual_price'] ?? $product_data['current_price'] ?? '';
		$affiliate_link = $product_data['affiliate_link'] ?? $product_data['affiliateLink'] ?? $product_data['affiliate_url'] ?? '';
		$merchant = $product_data['merchant'] ?? $product_data['Merchant'] ?? $product_data['merchant_name'] ?? $product_data['store'] ?? $product_data['shop'] ?? '';
		$network = $product_data['network'] ?? $product_data['Network'] ?? $product_data['network_name'] ?? $product_data['affiliate_network'] ?? '';
		$rating = $product_data['rating'] ?? $product_data['Rating'] ?? '';
		$reviews_count = $product_data['reviews_count'] ?? $product_data['reviews'] ?? $product_data['review_count'] ?? '';
		$description = $product_data['description'] ?? $product_data['Description'] ?? '';
		$brand = $product_data['brand'] ?? $product_data['Brand'] ?? '';
		$category = $product_data['category'] ?? $product_data['Category'] ?? '';
		$availability = $product_data['availability'] ?? $product_data['Availability'] ?? '';
		
		// Get product URL (prefer product_url, fallback to url, then affiliate_link)
		$product_url = $competitor_product['product_url'] ?? $competitor_product['url'] ?? $affiliate_link ?? '';
		
		// Try to extract image from product URL or metadata
		$image = $product_data['image'] ?? $product_data['image_url'] ?? $product_data['Image'] ?? '';
		
		// Build generator-compatible product array
		$generator_product = [
			// Core fields
			'name'         => sanitize_text_field( $name ),
			'short_name'   => sanitize_text_field( $name ), // Use same as name if no short_name available
			'price'        => sanitize_text_field( $price ),
			'url'          => esc_url_raw( $product_url ),
			'affiliate_url' => esc_url_raw( $affiliate_link ),
			
			// Merchant and network
			'merchant'     => sanitize_text_field( $merchant ),
			'network'      => sanitize_text_field( $network ),
			
			// Product details
			'brand'        => sanitize_text_field( $brand ),
			'rating'       => sanitize_text_field( $rating ),
			'reviews_count' => sanitize_text_field( $reviews_count ),
			'description'  => sanitize_textarea_field( $description ),
			'category'    => sanitize_text_field( $category ),
			'availability' => sanitize_text_field( $availability ),
			
			// Image
			'image'        => esc_url_raw( $image ),
			'image_url'    => esc_url_raw( $image ),
			
			// Additional metadata (store original competitor data for reference)
			'competitor_metadata' => [
				'position' => $competitor_product['position'] ?? ( $index + 1 ),
				'original_data' => $product_data,
			],
		];
		
		// Ensure all string fields are strings (not null)
		foreach ( $generator_product as $key => $value ) {
			if ( $value === null ) {
				$generator_product[ $key ] = '';
			}
		}
		
		return $generator_product;
	}
}

