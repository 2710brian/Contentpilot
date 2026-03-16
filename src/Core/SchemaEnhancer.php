<?php

namespace AEBG\Core;

/**
 * Schema Enhancer Class
 * Enhances schema data by adding required properties and fixing structure.
 *
 * @package AEBG\Core
 */
class SchemaEnhancer {
	
	/**
	 * Enhance schema data with required properties and fixes.
	 *
	 * @param array $schema_data Schema data to enhance.
	 * @param int $post_id Post ID.
	 * @param string $title Post title.
	 * @param string $content Post content.
	 * @param array $products Array of products.
	 * @return array Enhanced schema data.
	 */
	public static function enhance(array $schema_data, int $post_id, string $title, string $content, array $products): array {
		// Ensure required top-level properties
		$schema_data['@context'] = 'https://schema.org';
		
		if (!isset($schema_data['@type'])) {
			$schema_data['@type'] = 'Article';
		}
		
		$schema_type = $schema_data['@type'];
		
		// Enhance based on schema type
		if ($schema_type === 'Article') {
			$schema_data = self::enhance_article($schema_data, $post_id, $title, $content);
		}
		
		// Enhance products in schema
		if (isset($schema_data['mainEntity']['itemListElement']) || isset($schema_data['mainEntityOfPage'])) {
			$schema_data = self::enhance_products($schema_data, $products);
		}
		
		return $schema_data;
	}
	
	/**
	 * Enhance Article schema.
	 *
	 * @param array $schema_data Schema data.
	 * @param int $post_id Post ID.
	 * @param string $title Post title.
	 * @param string $content Post content.
	 * @return array Enhanced schema data.
	 */
	private static function enhance_article(array $schema_data, int $post_id, string $title, string $content): array {
		// Add headline if missing
		if (!isset($schema_data['headline']) || empty($schema_data['headline'])) {
			$schema_data['headline'] = SchemaFormatter::format_text($title, 110);
		}
		
		// Add description if missing
		if (!isset($schema_data['description']) || empty($schema_data['description'])) {
			$schema_data['description'] = SchemaFormatter::format_text($content, 160);
		}
		
		// Add dates
		$post = get_post($post_id);
		if ($post) {
			if (!isset($schema_data['datePublished'])) {
				$schema_data['datePublished'] = SchemaFormatter::format_date($post->post_date);
			}
			
			if (!isset($schema_data['dateModified'])) {
				$schema_data['dateModified'] = SchemaFormatter::format_date($post->post_modified);
			}
		}
		
		// Add author if missing
		if (!isset($schema_data['author']) || empty($schema_data['author'])) {
			$author_id = $post->post_author ?? 0;
			$author_name = get_the_author_meta('display_name', $author_id);
			if ($author_name) {
				$schema_data['author'] = [
					'@type' => 'Person',
					'name' => SchemaFormatter::format_text($author_name, 100)
				];
			}
		}
		
		// Add publisher (required for rich results)
		if (!isset($schema_data['publisher'])) {
			$schema_data['publisher'] = self::get_publisher();
		}
		
		// Add featured image if available
		if (!isset($schema_data['image']) || empty($schema_data['image'])) {
			$featured_image_id = get_post_thumbnail_id($post_id);
			if ($featured_image_id) {
				$image_url = SchemaFormatter::format_image($featured_image_id);
				if ($image_url) {
					$schema_data['image'] = $image_url;
				}
			}
		} else {
			// Ensure image is properly formatted
			$image_url = SchemaFormatter::format_image($schema_data['image']);
			if ($image_url) {
				$schema_data['image'] = $image_url;
			} else {
				unset($schema_data['image']);
			}
		}
		
		// Add mainEntityOfPage if missing
		if (!isset($schema_data['mainEntityOfPage'])) {
			$permalink = get_permalink($post_id);
			if ($permalink) {
				$schema_data['mainEntityOfPage'] = [
					'@type' => 'WebPage',
					'@id' => $permalink
				];
			}
		}
		
		return $schema_data;
	}
	
	/**
	 * Enhance products in schema.
	 *
	 * @param array $schema_data Schema data.
	 * @param array $products Array of products.
	 * @return array Enhanced schema data.
	 */
	private static function enhance_products(array $schema_data, array $products): array {
		// Handle ItemList structure
		if (isset($schema_data['mainEntity']['@type']) && $schema_data['mainEntity']['@type'] === 'ItemList') {
			if (isset($schema_data['mainEntity']['itemListElement']) && is_array($schema_data['mainEntity']['itemListElement'])) {
				foreach ($schema_data['mainEntity']['itemListElement'] as $index => &$item) {
					// Find matching product
					$product = $products[$index] ?? null;
					
					if ($product && isset($item['@type']) && $item['@type'] === 'Product') {
						$item = self::enhance_product($item, $product);
					}
				}
				unset($item);
			}
		}
		
		return $schema_data;
	}
	
	/**
	 * Enhance individual Product schema.
	 *
	 * @param array $product_schema Product schema data.
	 * @param array $product Product data.
	 * @return array Enhanced product schema.
	 */
	private static function enhance_product(array $product_schema, array $product): array {
		// Add brand if missing
		if (!isset($product_schema['brand']) || empty($product_schema['brand'])) {
			$brand = $product['brand'] ?? '';
			if (!empty($brand)) {
				$formatted_brand = SchemaFormatter::format_brand($brand);
				if ($formatted_brand) {
					$product_schema['brand'] = [
						'@type' => 'Brand',
						'name' => $formatted_brand
					];
				}
			}
		}
		
		// Add image if missing
		if (!isset($product_schema['image']) || empty($product_schema['image'])) {
			$image_url = $product['image'] ?? $product['image_url'] ?? '';
			if (!empty($image_url)) {
				$formatted_image = SchemaFormatter::format_image($image_url);
				if ($formatted_image) {
					$product_schema['image'] = $formatted_image;
				}
			}
		} else {
			// Ensure image is properly formatted
			$formatted_image = SchemaFormatter::format_image($product_schema['image']);
			if ($formatted_image) {
				$product_schema['image'] = $formatted_image;
			} else {
				unset($product_schema['image']);
			}
		}
		
		// Enhance offers
		if (isset($product_schema['offers'])) {
			$offers = is_array($product_schema['offers']) && isset($product_schema['offers'][0])
				? $product_schema['offers']
				: [$product_schema['offers']];
			
			foreach ($offers as &$offer) {
				if (!isset($offer['@type'])) {
					$offer['@type'] = 'Offer';
				}
				
				// Format price
				if (isset($offer['price'])) {
					$offer['price'] = SchemaFormatter::format_price($offer['price']);
				} else {
					$price = $product['price'] ?? 0;
					$offer['price'] = SchemaFormatter::format_price($price);
				}
				
				// Format currency
				if (isset($offer['priceCurrency'])) {
					$offer['priceCurrency'] = SchemaFormatter::format_currency($offer['priceCurrency']);
				} else {
					$currency = $product['currency'] ?? 'USD';
					$offer['priceCurrency'] = SchemaFormatter::format_currency($currency);
				}
				
				// Add availability if missing
				if (!isset($offer['availability'])) {
					$offer['availability'] = 'https://schema.org/InStock';
				}
				
				// Add url if available
				if (!isset($offer['url']) && isset($product['url'])) {
					$url = SchemaFormatter::format_url($product['url']);
					if ($url) {
						$offer['url'] = $url;
					}
				}
			}
			unset($offer);
			
			// If single offer, don't wrap in array
			if (count($offers) === 1) {
				$product_schema['offers'] = $offers[0];
			} else {
				$product_schema['offers'] = $offers;
			}
		}
		
		return $product_schema;
	}
	
	/**
	 * Get publisher Organization schema.
	 *
	 * @return array Publisher schema.
	 */
	private static function get_publisher(): array {
		$site_name = get_bloginfo('name');
		$site_url = home_url();
		
		$publisher = [
			'@type' => 'Organization',
			'name' => !empty($site_name) ? $site_name : 'Website',
		];
		
		// Add URL
		if ($site_url) {
			$formatted_url = SchemaFormatter::format_url($site_url);
			if ($formatted_url) {
				$publisher['url'] = $formatted_url;
			}
		}
		
		// Try to get logo from theme or site icon
		$logo_url = self::get_site_logo();
		if ($logo_url) {
			$publisher['logo'] = [
				'@type' => 'ImageObject',
				'url' => $logo_url
			];
		}
		
		return $publisher;
	}
	
	/**
	 * Get site logo URL.
	 *
	 * @return string|false Logo URL or false if not found.
	 */
	private static function get_site_logo(): string|false {
		// Try custom logo (WordPress 4.5+)
		$custom_logo_id = get_theme_mod('custom_logo');
		if ($custom_logo_id) {
			$logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
			if ($logo_url) {
				return SchemaFormatter::format_url($logo_url);
			}
		}
		
		// Try site icon
		$site_icon_id = get_option('site_icon');
		if ($site_icon_id) {
			$icon_url = wp_get_attachment_image_url($site_icon_id, 'full');
			if ($icon_url) {
				return SchemaFormatter::format_url($icon_url);
			}
		}
		
		return false;
	}
}

