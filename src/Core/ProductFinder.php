<?php

namespace AEBG\Core;

use AEBG\Core\Datafeedr;
use AEBG\Core\DuplicateDetector;
use AEBG\Core\PriceComparisonManager;
use AEBG\Core\ProductManager;
use AEBG\Core\TitleAnalyzer;
use AEBG\Core\Variables;
use AEBG\Core\Logger;
use AEBG\Core\SettingsHelper;

/**
 * Product Finder Class
 * Handles product finding and selection with intelligent ranking.
 *
 * @package AEBG\Core
 */
class ProductFinder {
	/**
	 * Cache TTL for product searches (1 hour)
	 */
	const CACHE_TTL = 3600;

	/**
	 * Find products using Datafeedr with intelligent keyword extraction.
	 *
	 * @param array  $context The context data.
	 * @param string $title The post title.
	 * @param string $api_key OpenAI API key.
	 * @param string $ai_model AI model to use.
	 * @return array|\WP_Error
	 */
	public function findProducts($context, $title = '', $api_key = '', $ai_model = '') {
		// Check cache first
		$cache_key = $this->getCacheKey($context, $title);
		$cached = $this->getCachedResults($cache_key);
		if ($cached !== false) {
			Logger::debug('Product search cache hit', ['cache_key' => $cache_key]);
			return $cached;
		}

		$datafeedr = new Datafeedr();
		
		// Check if Datafeedr is properly configured
		$datafeedr_config = $datafeedr->is_configured();
		if (is_wp_error($datafeedr_config)) {
			return new \WP_Error('aebg_datafeedr_not_configured', $datafeedr_config->get_error_message());
		}

		$search_limit = max(1, min(100, $context['quantity'] * 3)); // Get more for selection
		
		// Use intelligent keyword extraction if available
		$search_keywords = [];
		if (!empty($title) && !empty($api_key) && !empty($ai_model)) {
			$variables = new Variables();
			$title_analyzer = new TitleAnalyzer($variables, []);
			$keyword_data = $title_analyzer->extractProductSearchKeywords($title, $api_key, $ai_model);
			if (!is_wp_error($keyword_data)) {
				$search_keywords = array_merge(
					[$keyword_data['primary_keyword']],
					$keyword_data['secondary_keywords']
				);
				Logger::debug('Using AI-extracted keywords', ['keywords' => $search_keywords]);
			} else {
				Logger::warning('AI keyword extraction failed, using fallback', ['error' => $keyword_data->get_error_message()]);
			}
		}
		
		// Fallback to context keywords if AI extraction failed
		if (empty($search_keywords)) {
			$search_keywords = $context['search_keywords'] ?? [$context['category']];
		}
		
		$primary_keyword = $search_keywords[0] ?? $context['category'];
		Logger::debug('Searching Datafeedr', ['limit' => $search_limit, 'keyword' => $primary_keyword]);
		
		// Simplify search term
		$simplified_keyword = $this->simplifySearchTerm($primary_keyword);
		if ($simplified_keyword !== $primary_keyword) {
			Logger::debug('Simplified search term', ['from' => $primary_keyword, 'to' => $simplified_keyword]);
		}
		
		// Get default currency and networks from settings
		$settings = get_option('aebg_settings', []);
		$default_currency = $settings['default_currency'] ?? 'USD';
		$default_networks = $settings['default_networks'] ?? [];
		$search_configured_only = isset($settings['search_configured_only']) ? (bool)$settings['search_configured_only'] : false;
		
		// Handle configured networks
		if ($search_configured_only) {
			$networks_manager = new \AEBG\Admin\Networks_Manager();
			$all_affiliate_ids = $networks_manager->get_all_affiliate_ids();
			$configured_network_keys = array_keys(array_filter($all_affiliate_ids, function($id) {
				return !empty($id);
			}));
			
			if (!empty($configured_network_keys)) {
				$default_networks = $configured_network_keys;
			}
		}
		
		// If default_networks is empty or contains 'all', use all networks
		if (empty($default_networks) || (is_array($default_networks) && in_array('all', $default_networks))) {
			if (!$search_configured_only) {
				$default_networks = ['all'];
			}
		}
		
		// Try advanced search first
		$products = $datafeedr->search_advanced(
			$simplified_keyword,
			$search_limit,
			'relevance',
			0, 0, 0, false, $default_currency, '', '', true, 0, $default_networks, ''
		);
		
		// Handle array response
		if (!is_wp_error($products) && is_array($products) && isset($products['products'])) {
			$products = $products['products'];
		}

		// If advanced search fails or returns few results, try with original keyword
		if (is_wp_error($products) || (is_array($products) && count($products) < $context['quantity'])) {
			Logger::debug('Trying with original keyword', ['count' => is_wp_error($products) ? 0 : count($products)]);
			$products = $datafeedr->search_advanced(
				$primary_keyword,
				$search_limit,
				'relevance',
				0, 0, 0, false, $default_currency, '', '', true, 0, $default_networks, ''
			);
			if (!is_wp_error($products) && is_array($products) && isset($products['products'])) {
				$products = $products['products'];
			}
		}
		
		// Try additional keywords if still not enough
		if (!is_wp_error($products) && count($products) < $context['quantity'] && count($search_keywords) > 1) {
			Logger::debug('Trying additional keywords');
			$additional_products = [];
			
			for ($i = 1; $i < min(3, count($search_keywords)); $i++) {
				$keyword = $search_keywords[$i];
				$simplified_keyword = $this->simplifySearchTerm($keyword);
				$keyword_products = $datafeedr->search_advanced(
					$simplified_keyword,
					$search_limit / 2,
					'relevance',
					0, 0, 0, false, $default_currency, '', '', true, 0, $default_networks, ''
				);
				if (!is_wp_error($keyword_products) && is_array($keyword_products) && isset($keyword_products['products'])) {
					$keyword_products = $keyword_products['products'];
				}
				
				if (!is_wp_error($keyword_products)) {
					$additional_products = array_merge($additional_products, $keyword_products);
				}
			}
			
			// Merge and remove duplicates
			if (!empty($additional_products)) {
				$all_products = array_merge($products, $additional_products);
				$valid_products = array_filter($all_products, function($p) {
					return is_array($p) && isset($p['id']);
				});
				$product_ids = array_values(array_unique(array_column($valid_products, 'id')));
				$products = array_map(function($id) use ($valid_products) {
					$filtered = array_filter($valid_products, function($p) use ($id) {
						return $p['id'] === $id;
					});
					return !empty($filtered) ? array_values($filtered)[0] : null;
				}, $product_ids);
				$products = array_filter($products);
			}
		}

		if (is_wp_error($products)) {
			return $products;
		}

		// Extract products array from response structure
		if (is_array($products) && isset($products['products'])) {
			$products = $products['products'];
		}

		// Filter out invalid products
		if (!is_array($products)) {
			return [];
		}

		$products = array_filter($products, function($product) {
			return is_array($product) && isset($product['id']);
		});

		Logger::debug('Found valid products', ['count' => count($products)]);
		
		// Apply duplicate detection
		$settings = get_option('aebg_settings', []);
		$enable_duplicate_detection = $settings['enable_duplicate_detection'] ?? true;
		
		if ($enable_duplicate_detection) {
			$duplicate_options = [
				'prevent_same_product_different_suppliers' => $settings['prevent_same_product_different_suppliers'] ?? true,
				'prevent_same_product_different_colors' => $settings['prevent_same_product_different_colors'] ?? true,
				'similarity_threshold' => $settings['duplicate_similarity_threshold'] ?? 0.85,
				'prefer_higher_rating' => $settings['prefer_higher_rating_for_duplicates'] ?? true,
				'prefer_more_reviews' => $settings['prefer_more_reviews_for_duplicates'] ?? true,
			];
			
			try {
				$filtered_products = DuplicateDetector::filterDuplicates($products, $duplicate_options);
				$stats = DuplicateDetector::getStatistics($products, $filtered_products);
				Logger::debug('Duplicate detection completed', ['removed' => $stats['removed_count'], 'percentage' => $stats['removal_percentage']]);
				$products = $filtered_products;
			} catch (\Throwable $e) {
				Logger::error('Duplicate detection failed', ['error' => $e->getMessage()]);
			}
		}

		// Cache results
		$this->cacheResults($cache_key, $products);
		
		return $products;
	}

	/**
	 * Use OpenAI to select the best products.
	 *
	 * @param array  $products Array of products.
	 * @param array  $context The context data.
	 * @param string $api_key OpenAI API key.
	 * @param string $ai_model AI model to use.
	 * @return array|\WP_Error
	 */
	public function selectProducts($products, $context, $api_key, $ai_model) {
		if (empty($products)) {
			return [];
		}

		// Prepare product data for AI analysis
		$product_data = [];
		foreach ($products as $product) {
			if (!is_array($product)) {
				continue;
			}
			
			$product_data[] = [
				'id' => $product['id'] ?? '',
				'name' => $this->truncateString($product['name'] ?? '', 100),
				'brand' => $this->truncateString($product['brand'] ?? '', 50),
				'price' => $product['price'] ?? 0,
				'currency' => $product['currency'] ?? 'USD',
				'rating' => $product['rating'] ?? 0,
				'reviews_count' => $product['reviews_count'] ?? 0,
				'description' => $this->truncateString($product['description'] ?? '', 200),
				'category' => $this->truncateString($product['category'] ?? '', 50),
				'merchant' => $this->truncateString($product['merchant'] ?? '', 50),
			];
		}

		$prompt = $this->buildSelectionPrompt($context, $product_data);
		
		$api_endpoint = APIClient::getApiEndpoint($ai_model);
		$request_body = APIClient::buildRequestBody($ai_model, $prompt . "\n\nProducts: " . json_encode($product_data, JSON_PRETTY_PRINT), 2048, 0.3);
		
		$json_body = json_encode($request_body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json_body === false) {
			return new \WP_Error('json_encode_failed', 'Failed to encode request body: ' . json_last_error_msg());
		}
		
		// ULTRA-ROBUST: Use APIClient::makeRequest() for ultra-robust timeout handling
		$data = \AEBG\Core\APIClient::makeRequest($api_endpoint, $api_key, $request_body, 60, 3);

		if (is_wp_error($data)) {
			Logger::error('Product selection API error', ['error' => $data->get_error_message()]);
			return $this->fallbackProductSelection($products, $context);
		}

		if (empty($data) || !is_array($data)) {
			Logger::error('Product selection API returned empty or invalid response');
			return $this->fallbackProductSelection($products, $context);
		}

		if (isset($data['error'])) {
			Logger::error('Product selection API error', ['error' => $data['error']['message'] ?? 'Unknown API error']);
			return $this->fallbackProductSelection($products, $context);
		}

		$content = APIClient::extractContentFromResponse($data, $ai_model);
		$json_content = APIClient::extractJsonFromMarkdown($content);
		$selection_data = json_decode($json_content, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			Logger::error('Failed to parse selection JSON', ['error' => json_last_error_msg()]);
			return $this->fallbackProductSelection($products, $context);
		}

		$selected_ids = $selection_data['selected_products'] ?? [];
		if (empty($selected_ids) || !is_array($selected_ids)) {
			Logger::warning('No products selected by AI', ['response' => $selection_data]);
			return $this->fallbackProductSelection($products, $context);
		}

		// Map selected IDs to products
		$selected_products = [];
		$product_map = [];
		foreach ($products as $product) {
			$product_map[$product['id']] = $product;
		}

		foreach ($selected_ids as $product_id) {
			if (isset($product_map[$product_id])) {
				$product = $product_map[$product_id];
				if (isset($selection_data['justifications'][$product_id])) {
					$product['justification'] = $selection_data['justifications'][$product_id];
				}
				$selected_products[] = $product;
			}
		}

		// Ensure we have the requested quantity
		$requested_quantity = $context['quantity'] ?? 5;
		if (count($selected_products) < $requested_quantity) {
			// Fill with fallback selection
			$remaining = $requested_quantity - count($selected_products);
			$fallback = $this->fallbackProductSelection($products, ['quantity' => $remaining]);
			$selected_products = array_merge($selected_products, $fallback);
		}

		$selected_products = array_slice($selected_products, 0, $requested_quantity);
		
		Logger::debug('Products selected', ['count' => count($selected_products), 'requested' => $requested_quantity]);
		
		return $selected_products;
	}

	/**
	 * Fallback product selection when AI selection fails.
	 *
	 * @param array $products Array of products.
	 * @param array $context The context data.
	 * @return array Selected products.
	 */
	public function fallbackProductSelection($products, $context) {
		if (empty($products)) {
			return [];
		}

		$requested_quantity = $context['quantity'] ?? 5;
		
		// Rank products by quality
		$ranked = $this->rankProducts($products, $context);
		$selected_products = array_slice($ranked, 0, $requested_quantity);
		
		Logger::debug('Fallback selection completed', ['count' => count($selected_products)]);
		
		return $selected_products;
	}

	/**
	 * Rank products by quality and relevance.
	 *
	 * @param array $products Array of products.
	 * @param array $context The context data.
	 * @return array Ranked products.
	 */
	public function rankProducts($products, $context) {
		$ranked = [];
		
		foreach ($products as $product) {
			$score = 0;
			
			// Rating score (0-50 points)
			$rating = floatval($product['rating'] ?? 0);
			$score += $rating * 10;
			
			// Reviews count score (0-30 points)
			$reviews = intval($product['reviews_count'] ?? 0);
			$score += min(30, $reviews / 10);
			
			// Price relevance (0-20 points)
			$price = floatval($product['price'] ?? 0);
			if (isset($context['price_range'])) {
				$min_price = floatval($context['price_range']['min'] ?? 0);
				$max_price = floatval($context['price_range']['max'] ?? 999999);
				if ($price >= $min_price && $price <= $max_price) {
					$score += 20;
				}
			}
			
			$product['_selection_score'] = $score;
			$ranked[] = $product;
		}
		
		// Sort by score descending
		usort($ranked, function($a, $b) {
			return ($b['_selection_score'] ?? 0) <=> ($a['_selection_score'] ?? 0);
		});
		
		return $ranked;
	}

	/**
	 * Process price comparison for products.
	 *
	 * @param array $products Array of products.
	 * @param array $context The context data.
	 * @return array Products with price comparison data.
	 */
	public function processPriceComparisonForProducts($products, $context) {
		if (empty($products)) {
			return $products;
		}

		$price_manager = new PriceComparisonManager();
		
		// Get price comparison options from settings
		$options = SettingsHelper::getPriceComparisonOptions();
		
		// Override with context values if provided
		if (is_array($context)) {
			if (isset($context['min_merchant_count'])) {
				$options['min_merchant_count'] = $context['min_merchant_count'];
			}
			if (isset($context['max_merchant_count'])) {
				$options['max_merchant_count'] = $context['max_merchant_count'];
			}
			// CRITICAL: Pass user_id (author_id) from context so comparison data can be saved to database
			if (isset($context['author_id'])) {
				$options['user_id'] = $context['author_id'];
			} elseif (isset($context['user_id'])) {
				$options['user_id'] = $context['user_id'];
			}
			// post_id may not be available yet during bulk generation, but pass it if available
			if (isset($context['post_id'])) {
				$options['post_id'] = $context['post_id'];
			}
		}
		
		// Process price comparison
		$processed_products = $price_manager->processPriceComparison($products, $options);
		
		// Apply filtering if enabled
		if (SettingsHelper::isFilterByPriceComparisonEnabled()) {
			$filter_criteria = [
				'min_merchant_count' => $options['min_merchant_count'],
				'require_price_competition' => true,
				'max_price_variance' => $options['price_variance_threshold'],
				'prefer_lower_prices' => $options['prefer_lower_prices']
			];
			
			$filtered_products = $price_manager->filterByPriceComparison($processed_products, $filter_criteria);
			
			// Safety check: Don't filter if it removes too many products
			$min_required = $context['quantity'] ?? 3;
			if (count($filtered_products) >= $min_required) {
				$processed_products = $filtered_products;
				Logger::info('Price comparison filtering applied', [
					'original_count' => count($products),
					'filtered_count' => count($filtered_products)
				]);
			} else {
				Logger::warning('Price comparison filtering removed too many products, skipping filter', [
					'original_count' => count($products),
					'filtered_count' => count($filtered_products),
					'min_required' => $min_required
				]);
			}
		}
		
		// Apply sorting if enabled
		if (SettingsHelper::isSortByPriceComparisonEnabled()) {
			$sort_criteria = [
				'primary' => 'merchant_count', // Default: sort by merchant count
				'secondary' => 'price_variance',
				'direction' => 'desc'
			];
			
			$processed_products = $price_manager->sortByPriceComparison($processed_products, $sort_criteria);
			Logger::info('Products sorted by price comparison criteria');
		}
		
		return $processed_products;
	}

	/**
	 * Discover merchants for products.
	 *
	 * @param array $products Array of products.
	 * @param array $context The context data.
	 * @return array Products with merchant data.
	 */
	public function discoverMerchantsForProducts($products, $context) {
		// Check if merchant discovery is enabled
		if (!SettingsHelper::isMerchantDiscoveryEnabled()) {
			Logger::info('Merchant discovery disabled, skipping');
			return $products;
		}
		
		if (empty($products)) {
			return $products;
		}
		
		$max_merchants = SettingsHelper::getMaxMerchantsPerProduct();
		$datafeedr = new Datafeedr();
		
		if (!$datafeedr->is_configured()) {
			Logger::warning('Datafeedr not configured, skipping merchant discovery');
			return $products;
		}
		
		Logger::info('Starting merchant discovery for ' . count($products) . ' products', [
			'max_merchants_per_product' => $max_merchants
		]);
		
		$discovered_products = [];
		
		foreach ($products as $product) {
			$product_id = $product['id'] ?? '';
			$product_name = $product['name'] ?? '';
			$current_merchant = $product['merchant'] ?? '';
			
			if (empty($product_id) || empty($product_name)) {
				$discovered_products[] = $product;
				continue;
			}
			
			Logger::debug('Discovering merchants for product: ' . $product_name . ' (ID: ' . $product_id . ')');
			
			// Prepare search parameters for merchant discovery
			$discovery_params = [
				'product_id' => $product_id,
				'product_name' => $product_name,
				'brand' => $product['brand'] ?? '',
				'category' => $product['category'] ?? '',
				'price_min' => max(0, ($product['price'] ?? 0) * 0.5), // 50% below current price
				'price_max' => ($product['price'] ?? 0) * 2, // 200% above current price
				'limit' => $max_merchants,
				'disable_duplicate_detection' => true, // Crucial: disable duplicate detection
				'disable_color_filtering' => true, // Crucial: disable color filtering
				'include_existing_merchant' => true, // Include the current merchant
				'prefer_different_merchants' => true, // Try to find different merchants
				'search_strategy' => 'exact_match', // Use exact product matching
			];
			
			// Search for additional merchants
			$merchant_results = $datafeedr->search_products_for_merchant_discovery($discovery_params);
			
			if (is_wp_error($merchant_results)) {
				Logger::warning('Error discovering merchants for product ' . $product_id . ': ' . $merchant_results->get_error_message());
				$discovered_products[] = $product;
				continue;
			}
			
			// Process discovered merchants
			$discovered_merchants = [];
			$merchant_count = 0;
			
			foreach ($merchant_results as $merchant_product) {
				if ($merchant_count >= $max_merchants) {
					break;
				}
				
				// Skip if it's the same merchant as the original product
				if (!empty($current_merchant) && ($merchant_product['merchant'] ?? '') === $current_merchant) {
					continue;
				}
				
				$discovered_merchants[] = $merchant_product;
				$merchant_count++;
			}
			
			// Add discovered merchants to product
			if (!empty($discovered_merchants)) {
				$product['discovered_merchants'] = $discovered_merchants;
				Logger::debug('Discovered ' . count($discovered_merchants) . ' merchants for product ' . $product_id);
			}
			
			$discovered_products[] = $product;
		}
		
		Logger::info('Merchant discovery completed for ' . count($discovered_products) . ' products');
		
		return $discovered_products;
	}

	/**
	 * Calculate price variance.
	 *
	 * @param array $prices Array of prices.
	 * @return float Price variance.
	 */
	public function calculatePriceVariance($prices) {
		if (empty($prices) || count($prices) < 2) {
			return 0;
		}

		$mean = array_sum($prices) / count($prices);
		$variance = 0;
		
		foreach ($prices as $price) {
			$variance += pow($price - $mean, 2);
		}
		
		return $variance / count($prices);
	}

	/**
	 * Filter duplicate products.
	 *
	 * @param array $products Array of products.
	 * @return array Filtered products.
	 */
	public function filterDuplicateProducts($products) {
		$settings = get_option('aebg_settings', []);
		$duplicate_options = [
			'prevent_same_product_different_suppliers' => $settings['prevent_same_product_different_suppliers'] ?? true,
			'prevent_same_product_different_colors' => $settings['prevent_same_product_different_colors'] ?? true,
			'similarity_threshold' => $settings['duplicate_similarity_threshold'] ?? 0.85,
		];
		
		return DuplicateDetector::filterDuplicates($products, $duplicate_options);
	}

	/**
	 * Validate product data.
	 *
	 * @param array $product Product data.
	 * @return bool|\WP_Error True if valid, WP_Error if invalid.
	 */
	public function validateProductData($product) {
		return ProductManager::validateProduct($product);
	}

	/**
	 * Simplify search term by removing common words.
	 *
	 * @param string $term Search term.
	 * @return string Simplified term.
	 */
	private function simplifySearchTerm($term) {
		// Common words to remove (in multiple languages)
		$common_words = [
			'best', 'bedste', 'top', 'topliste', 'review', 'anmeldelse',
			'for', 'til', 'i', 'in', 'the', 'den', 'det', 'et', 'en',
			'and', 'og', 'or', 'eller', 'of', 'af', 'a', 'an', 'et'
		];
		
		$words = explode(' ', strtolower($term));
		$filtered = array_filter($words, function($word) use ($common_words) {
			return !in_array($word, $common_words) && strlen($word) > 2;
		});
		
		return implode(' ', $filtered);
	}

	/**
	 * Build selection prompt for AI.
	 *
	 * @param array $context Context data.
	 * @param array $product_data Product data.
	 * @return string Prompt.
	 */
	private function buildSelectionPrompt($context, $product_data) {
		$quantity = $context['quantity'] ?? 5;
		
		return "You are an expert product reviewer. Based on the context and post title, analyze the following list of products and select EXACTLY {$quantity} most relevant products. You MUST return exactly {$quantity} products, no more and no less. 

Context:
- Category: {$context['category']}
- Target Audience: {$context['target_audience']}
- Content Type: {$context['content_type']}
- Key Topics: " . implode(', ', array_slice($context['key_topics'] ?? [], 0, 5)) . "
- Price Range: \${$context['price_range']['min']} - \${$context['price_range']['max']}
- Preferred Brands: " . implode(', ', array_slice($context['preferred_brands'] ?? [], 0, 3)) . "
- Product Features: " . implode(', ', array_slice($context['product_features'] ?? [], 0, 5)) . "

Selection criteria:
1. Relevance to target audience and content type
2. Quality (rating and reviews)
3. Price within specified range
4. Brand preference (if specified)
5. Feature alignment with requirements
6. Overall value for money

Return a JSON object with 'selected_products' array containing the selected product IDs in order of preference, and 'justifications' array with brief explanations for each selection.";
	}

	/**
	 * Get cache key for product search.
	 *
	 * @param array  $context Context data.
	 * @param string $title Title.
	 * @return string Cache key.
	 */
	private function getCacheKey($context, $title) {
		return 'aebg_product_search_' . md5(json_encode($context) . $title);
	}

	/**
	 * Get cached results.
	 *
	 * @param string $cache_key Cache key.
	 * @return array|false Cached results or false.
	 */
	private function getCachedResults($cache_key) {
		$cached = get_transient($cache_key);
		return $cached !== false ? $cached : false;
	}

	/**
	 * Cache search results.
	 *
	 * @param string $cache_key Cache key.
	 * @param array  $products Products.
	 * @return void
	 */
	private function cacheResults($cache_key, $products) {
		set_transient($cache_key, $products, self::CACHE_TTL);
	}

	/**
	 * Optimize product names by generating short, concise versions.
	 * This runs once after product selection to save tokens during content generation.
	 *
	 * @param array  $products Array of selected products.
	 * @param string $api_key OpenAI API key.
	 * @param string $ai_model AI model to use.
	 * @return array Products with optimized names stored in 'short_name' field.
	 */
	public function optimizeProductNames($products, $api_key, $ai_model) {
		if (empty($products) || !is_array($products)) {
			return $products;
		}

		if (empty($api_key) || empty($ai_model)) {
			Logger::warning('Cannot optimize product names - missing API key or model');
			return $products;
		}

		Logger::debug('Optimizing product names', ['count' => count($products)]);

		$optimized_products = [];
		$api_endpoint = \AEBG\Core\APIClient::getApiEndpoint($ai_model);

		foreach ($products as $index => $product) {
			$original_name = $product['name'] ?? '';
			
			// Skip if no name or already optimized
			if (empty($original_name)) {
				$optimized_products[] = $product;
				continue;
			}

			// Check if already has short_name (from previous optimization)
			if (!empty($product['short_name'])) {
				$optimized_products[] = $product;
				continue;
			}

			// Generate optimized short name
			$prompt = "write \"# {$original_name}\" actual model name as short as possible and nothing else in native fluent danish.";
			
			$request_body = \AEBG\Core\APIClient::buildRequestBody(
				$ai_model,
				$prompt,
				200, // Short response expected
				0.7
			);

			$data = \AEBG\Core\APIClient::makeRequest($api_endpoint, $api_key, $request_body, 30, 2);

			if (is_wp_error($data)) {
				Logger::warning('Failed to optimize product name', [
					'product_index' => $index,
					'error' => $data->get_error_message()
				]);
				// Keep original name if optimization fails
				$product['short_name'] = $original_name;
			} else {
				$content = \AEBG\Core\APIClient::extractContentFromResponse($data, $ai_model);
				$short_name = trim($content);
				
				// Validate the result
				if (!empty($short_name) && strlen($short_name) < strlen($original_name)) {
					$product['short_name'] = $short_name;
					Logger::debug('Optimized product name', [
						'original' => substr($original_name, 0, 50),
						'optimized' => $short_name
					]);
				} else {
					// Fallback to original if optimization didn't improve it
					$product['short_name'] = $original_name;
					Logger::debug('Product name optimization did not improve length, using original');
				}
			}

			$optimized_products[] = $product;
		}

		Logger::debug('Product name optimization completed', ['count' => count($optimized_products)]);
		return $optimized_products;
	}

	/**
	 * Truncate string to specified length.
	 *
	 * @param string $string String to truncate.
	 * @param int    $length Maximum length.
	 * @return string Truncated string.
	 */
	private function truncateString($string, $length) {
		if (strlen($string) <= $length) {
			return $string;
		}
		return substr($string, 0, $length - 3) . '...';
	}
}

