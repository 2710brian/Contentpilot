<?php

namespace AEBG\Core;

use AEBG\Core\APIClient;
use AEBG\Core\DataUtilities;
use AEBG\Core\Variables;

/**
 * Title Analyzer Class
 * Handles title analysis and context extraction.
 *
 * @package AEBG\Core
 */
class TitleAnalyzer {
	/**
	 * Variables instance.
	 *
	 * @var Variables
	 */
	private $variables;

	/**
	 * Settings array.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * TitleAnalyzer constructor.
	 *
	 * @param Variables $variables Variables instance.
	 * @param array $settings Settings array.
	 */
	public function __construct($variables, $settings = []) {
		$this->variables = $variables;
		$this->settings = $settings;
	}

	/**
	 * Analyze title and extract comprehensive context.
	 *
	 * @param string $title The post title.
	 * @param string $api_key OpenAI API key.
	 * @param string $ai_model AI model to use.
	 * @return array|\WP_Error
	 */
	public function analyzeTitle($title, $api_key, $ai_model) {
		$prompt = "Analyze the following blog post title and extract comprehensive context for product selection. Return a JSON object with: category, quantity, attributes, target_audience, content_type, key_topics, preferred_brands, product_features, and search_keywords. For example, for 'Best 7 Gaming Headsets for Streaming in 2025', return: {\"category\": \"gaming headsets\", \"quantity\": 7, \"attributes\": [\"gaming\", \"streaming\", \"wireless\"], \"target_audience\": \"gamers and streamers\", \"content_type\": \"product review\", \"key_topics\": [\"audio quality\", \"comfort\", \"microphone\", \"latency\"], \"preferred_brands\": [\"Logitech\", \"Razer\", \"SteelSeries\"], \"product_features\": [\"noise cancellation\", \"surround sound\", \"detachable mic\"], \"search_keywords\": [\"gaming headset\", \"streaming headset\", \"wireless gaming\"]}";
		
		$api_endpoint = APIClient::getApiEndpoint($ai_model);
		$request_body = APIClient::buildRequestBody($ai_model, $prompt . "\n\nTitle: " . $title, 1024, 0.2);
		
		// Clean the request body before JSON encoding
		$cleaned_request_body = DataUtilities::cleanElementorDataForEncoding($request_body);
		
		// ULTRA-ROBUST: Use APIClient::makeRequest() instead of wp_remote_post()
		// This provides cURL-based timeout handling, retry logic, and hang prevention
		error_log('[AEBG] analyzeTitle: Making API request using APIClient::makeRequest() for robust timeout handling');
		$data = APIClient::makeRequest(
			$api_endpoint,
			$api_key,
			$cleaned_request_body,
			60, // 60 second timeout
			3   // 3 retries
		);

		if (is_wp_error($data)) {
			error_log('[AEBG] analyzeTitle: API request failed: ' . $data->get_error_message());
			return new \WP_Error('aebg_openai_api_error', $data->get_error_message());
		}

		if (empty($data) || !is_array($data)) {
			error_log('[AEBG] analyzeTitle: OpenAI API returned empty or invalid response');
			return new \WP_Error('aebg_openai_api_error', 'Empty or invalid response from OpenAI API');
		}

		if (isset($data['error'])) {
			$error_message = $data['error']['message'] ?? 'Unknown API error';
			error_log('[AEBG] analyzeTitle: OpenAI API error: ' . $error_message);
			return new \WP_Error('aebg_openai_api_error', $error_message);
		}

		$content = APIClient::extractContentFromResponse($data, $ai_model);
		
		// Extract JSON from markdown code blocks if present
		$json_content = APIClient::extractJsonFromMarkdown($content);
		
		$context = json_decode($json_content, true);
		
		if (json_last_error() !== JSON_ERROR_NONE) {
			error_log('[AEBG] Failed to parse context analysis JSON: ' . json_last_error_msg());
			error_log('[AEBG] Raw content: ' . $content);
			error_log('[AEBG] Extracted JSON content: ' . $json_content);
			return new \WP_Error('aebg_json_parse_error', 'Failed to parse context analysis: ' . json_last_error_msg());
		}

		// Set default values if not provided
		$context['category'] = $context['category'] ?? $title;
		// Prioritize user's selection over AI analysis
		$context['quantity'] = (int) ($this->settings['num_products'] ?? ($context['quantity'] ?? 7));
		$context['attributes'] = $context['attributes'] ?? [];
		$context['target_audience'] = $context['target_audience'] ?? 'general audience';
		$context['content_type'] = $context['content_type'] ?? 'informational';
		$context['key_topics'] = $context['key_topics'] ?? [];
		$context['price_range'] = $context['price_range'] ?? ['min' => 0, 'max' => 10000];
		$context['preferred_brands'] = $context['preferred_brands'] ?? [];
		$context['product_features'] = $context['product_features'] ?? [];
		$context['search_keywords'] = $context['search_keywords'] ?? [$context['category']];

		// Set context in variables for later use
		$this->variables->setContext('category', $context['category']);
		$this->variables->setContext('attributes', implode(', ', $context['attributes']));
		$this->variables->setContext('target_audience', $context['target_audience']);
		$this->variables->setContext('content_type', $context['content_type']);
		$this->variables->setContext('key_topics', implode(', ', $context['key_topics']));
		$this->variables->setContext('search_keywords', implode(', ', $context['search_keywords']));

		error_log('[AEBG] Enhanced context analysis completed: ' . json_encode($context));
		return $context;
	}

	/**
	 * Intelligently extract product search keywords from title using AI.
	 *
	 * @param string $title The post title.
	 * @param string $api_key OpenAI API key.
	 * @param string $ai_model AI model to use.
	 * @return array|\WP_Error
	 */
	public function extractProductSearchKeywords($title, $api_key, $ai_model) {
		// Detect language to provide better context to AI
		$language = $this->detectLanguage($title);
		$language_context = '';
		switch($language) {
			case 'da':
				$language_context = 'Danish';
				break;
			case 'sv':
				$language_context = 'Swedish';
				break;
			case 'no':
				$language_context = 'Norwegian';
				break;
			case 'en':
				$language_context = 'English';
				break;
			default:
				$language_context = 'Unknown';
				break;
		}
		error_log('[AEBG] Detected language for title "' . $title . '": ' . $language_context . ' (' . $language . ')');
		$prompt = "Analyze the following blog post title and extract the most relevant product search keywords for finding products on an e-commerce platform. 

Title: \"{$title}\"
Language: {$language_context}

Instructions:
1. Extract the core product type/category in the ORIGINAL LANGUAGE (do not translate)
2. Identify any specific brands mentioned
3. Extract key features/attributes that would help find relevant products
4. Consider color, size, or other specific characteristics
5. Remove generic words like 'best', 'top', 'review', '2025', 'test', etc.
6. Focus on terms that would actually appear in product listings
7. IMPORTANT: Keep the original language - if the title is in Danish, extract Danish keywords; if in Swedish, extract Swedish keywords; if in Norwegian, extract Norwegian keywords; if in English, extract English keywords

Return a JSON object with:
- primary_keyword: The main product search term in original language (most important)
- secondary_keywords: Array of additional search terms in original language (2-3 terms)
- brand_keywords: Array of brand names if mentioned
- feature_keywords: Array of specific features/attributes in original language
- search_strategy: Brief explanation of the search approach

Example for 'Bedste gamer mus 2025':
{
  \"primary_keyword\": \"gamer mus\",
  \"secondary_keywords\": [\"gaming mus\", \"trådløs mus\", \"gaming peripherals\"],
  \"brand_keywords\": [],
  \"feature_keywords\": [\"gaming\", \"trådløs\", \"ergonomisk\"],
  \"search_strategy\": \"Focus on gamer mus category with trådløs and ergonomisk features\"
}";

		// ULTRA-ROBUST: Use APIClient::makeRequest() for ultra-robust timeout handling
		$request_body = array_merge(
			[
				'model' => $ai_model,
				'messages' => [
					[
						'role' => 'system',
						'content' => 'You are an expert e-commerce product search specialist. Extract the most relevant product search keywords from blog post titles.'
					],
					[
						'role' => 'user',
						'content' => $prompt
					]
				],
				'temperature' => 0.3,
			],
			APIClient::getCompletionLimitParam( $ai_model, 1000 )
		);
		
		$data = \AEBG\Core\APIClient::makeRequest(
			'https://api.openai.com/v1/chat/completions',
			$api_key,
			$request_body,
			60,
			3
		);

		if (is_wp_error($data)) {
			error_log('[AEBG] Error extracting product keywords: ' . $data->get_error_message());
			return new \WP_Error('aebg_keyword_extraction_error', 'Failed to extract product keywords: ' . $data->get_error_message());
		}

		if (empty($data) || !is_array($data)) {
			error_log('[AEBG] OpenAI API returned empty or invalid response during keyword extraction');
			return new \WP_Error('aebg_openai_api_error', 'Empty or invalid response from OpenAI API');
		}

		if (isset($data['error'])) {
			$msg = '[AEBG] OpenAI API error during keyword extraction: ' . ($data['error']['message'] ?? 'Unknown API error');
			error_log($msg);
			return new \WP_Error('aebg_openai_api_error', $msg);
		}

		$content = $data['choices'][0]['message']['content'] ?? '';
		
		// Extract JSON from markdown code blocks if present
		$json_content = APIClient::extractJsonFromMarkdown($content);
		
		$keywords = json_decode($json_content, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			error_log('[AEBG] Failed to parse keyword extraction response: ' . $content);
			error_log('[AEBG] Extracted JSON content: ' . $json_content);
			// Fallback to basic extraction
			return $this->fallbackKeywordExtraction($title);
		}

		error_log('[AEBG] Extracted product keywords: ' . json_encode($keywords));
		return $keywords;
	}

	/**
	 * Simple language detection based on common words.
	 *
	 * @param string $text The text to analyze.
	 * @return string Language code ('da' for Danish, 'sv' for Swedish, 'no' for Norwegian, 'en' for English, 'unknown' for unknown).
	 */
	public function detectLanguage($text) {
		$text = strtolower($text);
		
		// Danish indicators
		$danish_words = ['bedste', 'bedst', 'gratis', 'fragt', 'køb', 'anmeldelse', 'test', 'letvægts', 'bærbar', 'trådløs', 'støjreduktion', 'pulsmåler', 'bomulds', 'organisk', 'lilla', 'fra', 'kaffemaskine', 'robot', 'maskine', 'enhed', 'teknologi', 'automatisk', 'intelligent', 'smart', 'hjem', 'hus', 'køkken', 'værelse', 'stue', 'have', 'terrasse', 'balkon'];
		
		// Swedish indicators
		$swedish_words = ['bästa', 'bäst', 'gratis', 'frakt', 'köp', 'recension', 'test', 'lättvikt', 'bärbar', 'trådlös', 'brusreducering', 'pulsmätare', 'bomull', 'organisk', 'lila', 'från'];
		
		// Norwegian indicators
		$norwegian_words = ['beste', 'best', 'gratis', 'frakt', 'kjøp', 'anmeldelse', 'test', 'lettvekt', 'bærbar', 'trådløs', 'støyreduksjon', 'pulsmåler', 'bomull', 'organisk', 'lilla', 'fra'];
		
		// English indicators
		$english_words = ['best', 'top', 'review', 'test', 'buy', 'free', 'shipping', 'delivery', 'wireless', 'noise', 'cancellation', 'heart', 'rate', 'monitor', 'cotton', 'organic', 'purple', 'from'];
		
		$danish_count = 0;
		$swedish_count = 0;
		$norwegian_count = 0;
		$english_count = 0;
		
		foreach ($danish_words as $word) {
			if (strpos($text, $word) !== false) {
				$danish_count++;
			}
		}
		
		foreach ($swedish_words as $word) {
			if (strpos($text, $word) !== false) {
				$swedish_count++;
			}
		}
		
		foreach ($norwegian_words as $word) {
			if (strpos($text, $word) !== false) {
				$norwegian_count++;
			}
		}
		
		foreach ($english_words as $word) {
			if (strpos($text, $word) !== false) {
				$english_count++;
			}
		}
		
		// Return the language with the highest count
		$counts = [
			'da' => $danish_count,
			'sv' => $swedish_count,
			'no' => $norwegian_count,
			'en' => $english_count
		];
		
		$max_count = max($counts);
		if ($max_count > 0) {
			return array_search($max_count, $counts);
		} else {
			return 'unknown';
		}
	}

	/**
	 * Fallback keyword extraction when AI extraction fails.
	 *
	 * @param string $title The post title.
	 * @return array
	 */
	public function fallbackKeywordExtraction($title) {
		// Remove common words that don't help with product search (multi-language)
		$remove_words = [
			// Danish
			'bedste', 'top', 'bedst', 'anmeldelse', 'test', '2025', '2024', '2023', 'gratis', 'fragt', 'køb', 'køb nu', 'og', 'med', 'til', 'for', 'i', 'på', 'af', 'fra', 'den', 'det', 'en', 'et',
			// English
			'best', 'top', 'review', 'test', 'buy', 'buy now', 'free', 'shipping', 'delivery', 'and', 'with', 'to', 'for', 'in', 'on', 'of', 'from', 'the', 'a', 'an',
			// Generic
			'2025', '2024', '2023', '2022', '2021'
		];
		
		$clean_title = strtolower($title);
		
		foreach ($remove_words as $word) {
			$clean_title = str_replace($word, '', $clean_title);
		}
		
		// Extract potential product terms
		$words = array_filter(array_map('trim', explode(' ', $clean_title)));
		
		// Take first 2-3 meaningful words for primary keyword
		$primary_words = array_slice($words, 0, 2);
		$primary_keyword = implode(' ', $primary_words);
		
		// Create secondary keywords with variations
		$secondary_keywords = [];
		if (count($words) >= 2) {
			$secondary_keywords[] = implode(' ', array_slice($words, 0, 2)); // Same as primary
		}
		if (count($words) >= 3) {
			$secondary_keywords[] = implode(' ', array_slice($words, 0, 3)); // First 3 words
		}
		if (count($words) >= 2) {
			$secondary_keywords[] = implode(' ', array_slice($words, 1, 2)); // Skip first word
		}
		
		// Remove duplicates and empty values
		$secondary_keywords = array_filter(array_unique($secondary_keywords));
		
		return [
			'primary_keyword' => $primary_keyword,
			'secondary_keywords' => array_values($secondary_keywords),
			'brand_keywords' => [],
			'feature_keywords' => [],
			'search_strategy' => 'Fallback extraction preserving original language'
		];
	}

	/**
	 * Update context with actual selected product information
	 *
	 * @param array $context The context array.
	 * @param array $products The products array.
	 * @return array Updated context.
	 */
	public function updateContextWithProducts($context, $products) {
		if (empty($products) || !is_array($products)) {
			return $context;
		}
		
		// Extract actual product information to update context
		$actual_categories = [];
		$actual_brands = [];
		$actual_features = [];
		$actual_price_range = ['min' => PHP_INT_MAX, 'max' => 0];
		$actual_merchants = [];
		$actual_ratings = [];
		$actual_reviews = [];
		
		foreach ($products as $product) {
			// Extract category
			if (!empty($product['category'])) {
				$actual_categories[] = $product['category'];
			}
			
			// Extract brand
			if (!empty($product['brand'])) {
				$actual_brands[] = $product['brand'];
			}
			
			// Extract merchant
			if (!empty($product['merchant'])) {
				$actual_merchants[] = $product['merchant'];
			}
			
			// Extract rating
			if (!empty($product['rating']) && is_numeric($product['rating'])) {
				$actual_ratings[] = (float)$product['rating'];
			}
			
			// Extract reviews count
			if (!empty($product['reviews_count']) && is_numeric($product['reviews_count'])) {
				$actual_reviews[] = (int)$product['reviews_count'];
			}
			
			// Extract features from description or other fields
			if (!empty($product['description'])) {
				// Simple feature extraction - can be enhanced
				$description = strtolower($product['description']);
				$common_features = ['wireless', 'bluetooth', 'waterproof', 'rechargeable', 'portable', 'compact', 'lightweight', 'durable', 'ergonomic'];
				foreach ($common_features as $feature) {
					if (strpos($description, $feature) !== false) {
						$actual_features[] = $feature;
					}
				}
			}
			
			// Extract price
			if (!empty($product['price']) && is_numeric($product['price'])) {
				$price = (float)$product['price'];
				if ($price < $actual_price_range['min']) {
					$actual_price_range['min'] = $price;
				}
				if ($price > $actual_price_range['max']) {
					$actual_price_range['max'] = $price;
				}
			}
		}
		
		// Update context with actual product data
		if (!empty($actual_categories)) {
			$context['actual_category'] = implode(', ', array_unique($actual_categories));
		}
		if (!empty($actual_brands)) {
			$context['actual_brands'] = implode(', ', array_unique($actual_brands));
		}
		if (!empty($actual_features)) {
			$context['actual_features'] = array_unique($actual_features);
		}
		if ($actual_price_range['min'] !== PHP_INT_MAX) {
			$context['actual_price_range'] = $actual_price_range;
		}
		if (!empty($actual_merchants)) {
			$context['actual_merchants'] = implode(', ', array_unique($actual_merchants));
		}
		if (!empty($actual_ratings)) {
			$context['actual_ratings'] = $actual_ratings;
			$context['average_rating'] = array_sum($actual_ratings) / count($actual_ratings);
		}
		if (!empty($actual_reviews)) {
			$context['actual_reviews'] = $actual_reviews;
			$context['total_reviews'] = array_sum($actual_reviews);
		}
		
		return $context;
	}
}

