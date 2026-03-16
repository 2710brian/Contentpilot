<?php

namespace AEBG\Core;


/**
 * Variables Class
 *
 * @package AEBG\Core
 */
class Variables {
	/**
	 * Context data for variable replacement.
	 *
	 * @var array
	 */
	private $context = [];

	/**
	 * Variables constructor.
	 */
	public function __construct() {
		// Initialize with default context
		$this->setContext('year', date('Y'));
		$this->setContext('date', date('Y-m-d'));
		$this->setContext('time', date('H:i:s'));
	}

	/**
	 * Set context data for variable replacement.
	 *
	 * @param string $key The context key.
	 * @param mixed  $value The context value.
	 */
	public function setContext($key, $value) {
		$this->context[$key] = $value;
	}

	/**
	 * Get context data.
	 *
	 * @param string $key The context key.
	 * @return mixed|null
	 */
	public function getContext($key) {
		return $this->context[$key] ?? null;
	}

	/**
	 * Replace variables in content.
	 *
	 * @param string $content The content.
	 * @param string $title The title.
	 * @param array  $products Array of products.
	 * @param array  $additional_context Additional context data.
	 * @return string
	 */
	public function replace($content, $title, $products = [], $additional_context = []) {
		// Merge all context
		$context = array_merge($this->context, $additional_context);
		
		// Replace basic variables
		$content = str_replace('{title}', $title, $content);
		$content = str_replace('{year}', date('Y'), $content);
		$content = str_replace('{date}', date('Y-m-d'), $content);
		$content = str_replace('{time}', date('H:i:s'), $content);
		
		// Replace product variables
		if (!empty($products) && is_array($products)) {
			// Only log once per replacement session (not for every recursive call)
			static $replacement_session_id = null;
			if ($replacement_session_id === null) {
				$replacement_session_id = uniqid('vars_', true);
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('[AEBG] Variables::replace - Processing ' . count($products) . ' products (session: ' . substr($replacement_session_id, -8) . ')');
				}
			}
			foreach ($products as $index => $product) {
				$product_num = $index + 1;
				
				// Use optimized short_name if available, otherwise fallback to name
				$product_name = !empty($product['short_name']) ? $product['short_name'] : ($product['name'] ?? '');
				
				// Basic product variables - ensure all values are strings
				$content = str_replace("{product-{$product_num}}", (string)$product_name, $content);
				$content = str_replace("{product-{$product_num}-name}", (string)$product_name, $content);
				$content = str_replace("{product-{$product_num}-price}", (string)($product['price'] ?? ''), $content);
				$content = str_replace("{product-{$product_num}-brand}", (string)($product['brand'] ?? ''), $content);
				$content = str_replace("{product-{$product_num}-rating}", (string)($product['rating'] ?? ''), $content);
				$content = str_replace("{product-{$product_num}-reviews}", (string)($product['reviews_count'] ?? ''), $content);
				$content = str_replace("{product-{$product_num}-description}", (string)($product['description'] ?? ''), $content);
				// Enhanced URL extraction - try multiple possible field names
				$product_url = '';
				$url_fields = ['url', 'product_url', 'affiliate_url', 'direct_url', 'link', 'product_link'];
				foreach ($url_fields as $field) {
					if (!empty($product[$field])) {
						$product_url = $product[$field];
						break;
					}
				}
				// Fix malformed URLs before inserting into content
				// URLs from Datafeedr are already correctly formatted
				$content = str_replace("{product-{$product_num}-url}", (string)$product_url, $content);
				$content = str_replace("{product-{$product_num}-merchant}", (string)($product['merchant'] ?? ''), $content);
				$content = str_replace("{product-{$product_num}-category}", (string)($product['category'] ?? ''), $content);
				$content = str_replace("{product-{$product_num}-availability}", (string)($product['availability'] ?? ''), $content);
				
				// Product image variables
				if (!empty($product['featured_image_id'])) {
					$featured_image_url = wp_get_attachment_url($product['featured_image_id']);
					$featured_image_html = wp_get_attachment_image($product['featured_image_id'], 'full');
					
					$content = str_replace("{product-{$product_num}-featured-image}", (string)($featured_image_url ?: ''), $content);
					$content = str_replace("{product-{$product_num}-featured-image-id}", (string)($product['featured_image_id']), $content);
					$content = str_replace("{product-{$product_num}-featured-image-html}", (string)($featured_image_html ?: ''), $content);
				}
				
				// Simple product image variable (most commonly used)
				if (!empty($product['image']) || !empty($product['image_url'])) {
					$product_image = $product['image'] ?? $product['image_url'];
					$content = str_replace("{product-{$product_num}-image}", (string)$product_image, $content);
				} elseif (!empty($product['featured_image_id'])) {
					$featured_image_url = wp_get_attachment_url($product['featured_image_id']);
					$content = str_replace("{product-{$product_num}-image}", (string)($featured_image_url ?: ''), $content);
				}
				
				if (!empty($product['gallery_image_ids']) && is_array($product['gallery_image_ids'])) {
					$gallery_urls = [];
					$gallery_html = '';
					
					foreach ($product['gallery_image_ids'] as $gallery_id) {
						$gallery_url = wp_get_attachment_url($gallery_id);
						$gallery_image_html = wp_get_attachment_image($gallery_id, 'medium');
						
						if ($gallery_url) {
							$gallery_urls[] = $gallery_url;
						}
						if ($gallery_image_html) {
							$gallery_html .= $gallery_image_html . "\n";
						}
					}
					
					$content = str_replace("{product-{$product_num}-gallery-images}", (string)implode(',', $gallery_urls), $content);
					$content = str_replace("{product-{$product_num}-gallery-images-ids}", (string)implode(',', $product['gallery_image_ids']), $content);
					$content = str_replace("{product-{$product_num}-gallery-images-html}", (string)$gallery_html, $content);
				}
				
				// Product features and specs (if available)
				if (!empty($product['features']) && is_array($product['features'])) {
					$content = str_replace("{product-{$product_num}-features}", (string)implode(', ', $product['features']), $content);
				}
				
				if (!empty($product['specifications']) && is_array($product['specifications'])) {
					$content = str_replace("{product-{$product_num}-specs}", (string)implode(', ', $product['specifications']), $content);
				}
				
				// Product justification (if available)
				if (!empty($product['justification'])) {
					$justification = $product['justification'];
					if (is_array($justification)) {
						$justification = implode(', ', $justification);
					}
					$content = str_replace("{product-{$product_num}-justification}", (string)$justification, $content);
				}
				
				// NEW: Product link variable (uses selected merchant)
				$product_link = $this->get_product_link($product);
				$content = str_replace("{product-{$product_num}-link}", $product_link, $content);
				
				// NEW: Product affiliate URL variable (with affiliate tracking)
				$product_affiliate_url = $this->get_product_affiliate_url($product);
				$content = str_replace("{product-{$product_num}-affiliate-url}", $product_affiliate_url, $content);
				
				// NEW: Product comparison variable
				$product_comparison = $this->get_product_comparison($product, $additional_context);
				$content = str_replace("{product-{$product_num}-comparison}", $product_comparison, $content);
				
				// Product processing is routine - only log in verbose debug mode
				if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
					error_log('[AEBG] Variables::replace - Processed product ' . $product_num . ': ' . ($product['name'] ?? 'unnamed'));
				}
			}
		} else {
			error_log('[AEBG] Variables::replace - No products provided for variable replacement');
		}
		
		// Replace context variables
		foreach ($context as $key => $value) {
			if (is_string($value) || is_numeric($value)) {
				$content = str_replace("{{$key}}", $value, $content);
			} elseif (is_array($value)) {
				$content = str_replace("{{$key}}", implode(', ', $value), $content);
			}
		}
		
		return $content;
	}

	/**
	 * Replace variables (legacy method for backward compatibility).
	 *
	 * @param string $content The content.
	 * @param string $title The title.
	 * @return string
	 */
	public function replace_legacy($content, $title) {
		return $this->replace($content, $title);
	}

	/**
	 * Get all available variables for help/display purposes.
	 *
	 * @return array
	 */
	public function getAvailableVariables() {
		return [
			'basic' => [
				'{title}' => 'Post title',
				'{year}' => 'Current year',
				'{date}' => 'Current date (YYYY-MM-DD)',
				'{time}' => 'Current time (HH:MM:SS)',
			],
			'products' => [
				'{product-1}' => 'First product name',
				'{product-1-name}' => 'First product name (explicit)',
				'{product-1-price}' => 'First product price',
				'{product-1-brand}' => 'First product brand',
				'{product-1-rating}' => 'First product rating',
				'{product-1-reviews}' => 'First product review count',
				'{product-1-description}' => 'First product description',
				'{product-1-url}' => 'First product URL',
				'{product-1-affiliate-url}' => 'First product affiliate URL (with tracking)',
				'{product-1-merchant}' => 'First product merchant',
				'{product-1-category}' => 'First product category',
				'{product-1-availability}' => 'First product availability',
				'{product-1-features}' => 'First product features (comma-separated)',
				'{product-1-specs}' => 'First product specifications (comma-separated)',
				'{product-1-justification}' => 'First product selection justification',
				'{product-2}' => 'Second product name',
				'{product-3}' => 'Third product name',
				// ... and so on for more products
			],
			'product_images' => [
				'{product-1-image}' => 'First product image URL (simplified)',
				'{product-1-featured-image}' => 'First product featured image URL',
				'{product-1-featured-image-id}' => 'First product featured image attachment ID',
				'{product-1-featured-image-html}' => 'First product featured image HTML tag',
				'{product-1-gallery-images}' => 'First product gallery image URLs (comma-separated)',
				'{product-1-gallery-images-ids}' => 'First product gallery image attachment IDs (comma-separated)',
				'{product-1-gallery-images-html}' => 'First product gallery images HTML tags',
				'{product-2-image}' => 'Second product image URL (simplified)',
				'{product-2-featured-image}' => 'Second product featured image URL',
				'{product-2-gallery-images}' => 'Second product gallery image URLs',
				// ... and so on for more products
			],
			'product_links' => [
				'{product-1-url}' => 'First product URL',
				'{product-1-affiliate-url}' => 'First product affiliate URL (with tracking)',
				'{product-2-url}' => 'Second product URL',
				'{product-2-affiliate-url}' => 'Second product affiliate URL (with tracking)',
				// ... and so on for more products
			],
			'context' => [
				'{category}' => 'Extracted category from title',
				'{attributes}' => 'Extracted attributes from title',
				'{target_audience}' => 'Target audience',
				'{content_type}' => 'Content type',
				'{key_topics}' => 'Key topics',
				'{search_keywords}' => 'Search keywords used',
			]
		];
	}

	/**
	 * Get product link with selected merchant
	 * 
	 * @param array $product Product data
	 * @return string Product link
	 */
	private function get_product_link($product) {
		$merchant_name = $product['merchant'] ?? '';
		$product_url = $product['url'] ?? '';
		
		if (empty($product_url)) {
			return '#';
		}
		
		// URLs from Datafeedr are already correctly formatted
		
		// If we have a specific merchant selected, try to get their URL
		if (!empty($merchant_name)) {
			// Check if we have merchant-specific URL in the product data
			$merchant_url = $product['merchant_urls'][$merchant_name] ?? $product_url;
			// Fix malformed URLs
			// URLs from Datafeedr are already correctly formatted
			return esc_url($merchant_url);
		}
		
		return esc_url($product_url);
	}

	/**
	 * Get product affiliate URL with tracking
	 * 
	 * @param array $product Product data
	 * @return string Affiliate URL
	 */
	private function get_product_affiliate_url($product) {
		$merchant_name = $product['merchant'] ?? '';
		$product_url = $product['url'] ?? '';
		
		if (empty($product_url)) {
			return '#';
		}
		
		// URLs from Datafeedr are already correctly formatted
		
		// Get the base URL (either merchant-specific or default)
		$base_url = $product_url;
		if (!empty($merchant_name)) {
			$base_url = $product['merchant_urls'][$merchant_name] ?? $product_url;
			// Fix malformed URLs
			// URLs from Datafeedr are already correctly formatted
		}
		
		// Use the existing affiliate link system from Shortcodes class
		$shortcodes = new \AEBG\Core\Shortcodes();
		$affiliate_url = $shortcodes->get_affiliate_link_for_url($base_url, $merchant_name);
		
		return esc_url($affiliate_url);
	}

	/**
	 * Get product comparison table HTML
	 * 
	 * @param array $product Product data
	 * @param array $context Additional context
	 * @return string Product comparison HTML
	 */
	private function get_product_comparison($product, $context = []) {
		$merchant_limit = $context['merchant_limit'] ?? 10;
		$product_id = $product['id'] ?? '';
		
		if (empty($product_id)) {
			return '<p>No product comparison available</p>';
		}
		
		// DEFERRED: Skip merchant comparison during batch generation to prevent timeouts
		// Merchant comparison is now processed asynchronously after post creation
		// Return a placeholder that will be replaced when data is available
		if (defined('AEBG_ACTION_START_TIME') || did_action('aebg_execute_generation')) {
			// We're in batch generation context - skip synchronous API call
			return '<p>Pris sammenligning behandles og vil være tilgængelig snart.</p>';
		}
		
		// Get merchant data (with caching) - only for non-batch contexts
		$datafeedr = new \AEBG\Core\Datafeedr();
		$merchant_data = $datafeedr->get_merchant_comparison($product, $merchant_limit);
		
		if (is_wp_error($merchant_data) || empty($merchant_data['merchants'])) {
			return '<p>No merchant comparison available</p>';
		}
		
		// Build comparison table HTML
		$html = '<div class="aebg-product-comparison">';
		$html .= '<h4>Price Comparison</h4>';
		$html .= '<table class="aebg-comparison-table">';
		$html .= '<thead><tr>';
		$html .= '<th>Merchant</th>';
		$html .= '<th>Price</th>';
		$html .= '<th>Rating</th>';
		$html .= '<th>Availability</th>';
		$html .= '</tr></thead>';
		$html .= '<tbody>';
		
		$merchants = array_values($merchant_data['merchants']);
		$count = 0;
		
		// Get currency from product (primary source)
		$currency = $product['currency'] ?? 'DKK';
		
		foreach ($merchants as $merchant) {
			if ($merchant_limit !== 'all' && $count >= $merchant_limit) {
				break;
			}
			
			// Get currency for this merchant (prefer merchant-specific currency)
			$merchant_currency = $merchant['currency'] ?? $currency;
			if (empty($merchant_currency) && isset($merchant['products']) && is_array($merchant['products']) && !empty($merchant['products'])) {
				$merchant_currency = $merchant['products'][0]['currency'] ?? $currency;
			}
			
			$is_selected = $merchant['name'] === ($product['merchant'] ?? '');
			$row_class = $is_selected ? 'selected-merchant' : '';
			
			$html .= '<tr class="' . $row_class . '">';
			$html .= '<td>' . esc_html($merchant['name']) . '</td>';
			$html .= '<td>' . esc_html($this->format_price($merchant['lowest_price'], $merchant_currency)) . '</td>';
			$html .= '<td>' . esc_html(number_format($merchant['average_rating'] ?? 0, 1)) . '/5</td>';
			$html .= '<td>' . esc_html($merchant['availability'] ?? 'Unknown') . '</td>';
			$html .= '</tr>';
			
			$count++;
		}
		
		$html .= '</tbody></table>';
		$html .= '</div>';
		
		return $html;
	}

	/**
	 * Format price for display
	 * 
	 * @param float $price Price to format
	 * @param string $currency Currency code (default: DKK)
	 * @return string Formatted price
	 */
	private function format_price($price, $currency = 'DKK') {
		if (empty($price) && $price !== 0 && $price !== '0') {
			return 'N/A';
		}
		
		// Use ProductManager::formatPrice which handles all currencies properly
		// API returns proper decimal values, so no conversion needed
		return \AEBG\Core\ProductManager::formatPrice($price, $currency);
	}
	
}
