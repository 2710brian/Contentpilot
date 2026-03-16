<?php

namespace AEBG\Core;

use AEBG\Core\APIClient;
use AEBG\Core\VariableReplacer;
use AEBG\Core\ContentFormatter;
use AEBG\Core\Logger;
use AEBG\Core\TimeoutManager;

/**
 * Content Generator Class
 * Handles AI content generation with optimized prompts.
 *
 * @package AEBG\Core
 */
class ContentGenerator {
	/**
	 * Cache TTL for generated content (1 hour)
	 */
	const CACHE_TTL = 3600;

	/**
	 * Variable replacer instance.
	 *
	 * @var VariableReplacer
	 */
	private $variable_replacer;

	/**
	 * Job start time for timeout checks.
	 *
	 * @var float|null
	 */
	private $job_start_time;

	/**
	 * Constructor.
	 *
	 * @param VariableReplacer $variable_replacer Variable replacer instance.
	 * @param float|null       $job_start_time Job start time.
	 */
	public function __construct($variable_replacer = null, $job_start_time = null) {
		$this->variable_replacer = $variable_replacer ?: new VariableReplacer();
		$this->job_start_time = $job_start_time;
	}

	/**
	 * Generate content with title and products.
	 *
	 * @param string $title The title.
	 * @param array  $products Array of products.
	 * @param array  $context Context data.
	 * @param string $api_key API key.
	 * @param string $ai_model AI model.
	 * @return string|\WP_Error Generated content or WP_Error.
	 */
	public function generateContent($title, $products, $context, $api_key, $ai_model) {
		// Check cache first
		$cache_key = $this->getCacheKey($title, $products, $context);
		$cached = $this->getCachedContent($cache_key);
		if ($cached !== false) {
			Logger::debug('Content generation cache hit', ['cache_key' => $cache_key]);
			return $cached;
		}

		// Build prompt
		$prompt = $this->buildContentPrompt($title, $products, $context);

		// Generate content
		$content = $this->generateContentWithPrompt($title, $products, $context, $api_key, $ai_model, $prompt);

		if (is_wp_error($content)) {
			return $content;
		}

		// Cache successful generation
		$this->cacheContent($cache_key, $content);

		return $content;
	}

	/**
	 * Generate content with a specific prompt.
	 *
	 * @param string $title The title.
	 * @param array  $products Array of products.
	 * @param array  $context Context data.
	 * @param string $api_key API key.
	 * @param string $ai_model AI model.
	 * @param string $prompt The prompt.
	 * @param string $format Format type (text-editor, heading, button, icon-list-item, etc.). Default: text-editor.
	 * @param int    $max_tokens Maximum tokens for the response. Default: 2000. Use higher values (4000-6000) for schema generation.
	 * @return string|false|\WP_Error Generated content, false on error, or WP_Error.
	 */
	public function generateContentWithPrompt($title, $products, $context, $api_key, $ai_model, $prompt, $format = 'text-editor', $max_tokens = 2000) {
		// Validate inputs
		if (empty($api_key) || !is_string($api_key)) {
			Logger::error('API key is empty or invalid');
			return false;
		}

		if (empty($ai_model) || !is_string($ai_model)) {
			Logger::error('AI model is empty or invalid');
			return false;
		}

		if (empty($prompt) || !is_string($prompt)) {
			Logger::error('Prompt is empty or invalid');
			return false;
		}

		if (empty($title) || !is_string($title)) {
			Logger::error('Title is empty or invalid');
			return false;
		}

		if (!is_array($products)) {
			Logger::error('Products is not an array');
			return false;
		}

		if (!is_array($context)) {
			Logger::error('Context is not an array');
			return false;
		}

		// Add product context to prevent AI from making up products
		if (!empty($products)) {
			$product_names = [];
			foreach ($products as $index => $product) {
				$product_number = $index + 1;
				$product_name = $product['name'] ?? $product['title'] ?? 'Product ' . $product_number;
				$product_names[] = $product_number . '. ' . $product_name;
			}

			$product_context = "\n\nCRITICAL: You MUST use ONLY these specific products that have been selected for this post:\n" . 
				implode("\n", $product_names) . 
				"\n\nDO NOT make up, invent, or reference any other products. DO NOT use placeholder names like 'XYZ', 'Product ABC', or generic product names. Use the EXACT product names listed above.";

			$prompt .= $product_context;
		}

		// Replace variables in prompt
		$processed_prompt = $this->variable_replacer->replaceVariablesInPrompt($prompt, $title, $products, $context);

		// Validate processed prompt
		if (empty($processed_prompt) || strlen(trim($processed_prompt)) < 10) {
			Logger::error('Processed prompt is invalid or too short', [
				'original_length' => strlen($prompt),
				'processed_length' => strlen($processed_prompt ?? '')
			]);
			return false;
		}

		// Optimize prompt
		$processed_prompt = $this->optimizePrompt($processed_prompt, $context);

		// Check timeout before making API call
		if ($this->job_start_time) {
			$elapsed = microtime(true) - $this->job_start_time;
			$max_time = TimeoutManager::DEFAULT_TIMEOUT - TimeoutManager::SAFETY_BUFFER; // Use centralized timeout (1750s = 30min - 50s buffer)
			if ($elapsed > $max_time) {
				Logger::warning('Skipping content generation - approaching timeout', [
					'elapsed' => round($elapsed, 2),
					'max' => $max_time
				]);
				return false;
			}
		}

		// Make API request
		$api_endpoint = APIClient::getApiEndpoint($ai_model);
		$request_body = APIClient::buildRequestBody($ai_model, $processed_prompt, $max_tokens, 0.7);

		// CRITICAL: Use longer timeout for API requests (OpenAI can take 30-60s for longer prompts)
		// Icon list items and other inline generation need sufficient time
		$timeout = 60; // Increased from 30 to 60 seconds for better reliability
		if ($this->job_start_time && !defined('WP_CLI')) {
			$elapsed = microtime(true) - $this->job_start_time;
			$remaining = (\AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT - \AEBG\Core\TimeoutManager::SAFETY_BUFFER) - $elapsed; // Use centralized timeout
			// Leave 20s buffer, but allow up to 60s for API requests (minimum 10s)
			$timeout = min(60, max(10, $remaining - 20));
		}

		$response = APIClient::makeRequest($api_endpoint, $api_key, $request_body, $timeout, 3);

		if (is_wp_error($response)) {
			Logger::error('Content generation API error', ['error' => $response->get_error_message()]);
			return false;
		}

		// Extract content
		$content = APIClient::extractContentFromResponse($response, $ai_model);

		// Validate generated content
		$validation = $this->validateGeneratedContent($content);
		if (is_wp_error($validation)) {
			Logger::error('Generated content validation failed', ['error' => $validation->get_error_message()]);
			return false;
		}

		// Format content (skip formatting for icon list items to preserve plain text)
		$content = $this->formatGeneratedContent($content, $format);

		return $content;
	}

	/**
	 * Replace AI content in template.
	 *
	 * @param array  &$data Template data.
	 * @param string $title The title.
	 * @param array  $products Array of products.
	 * @param array  $context Context data.
	 * @param string $api_key API key.
	 * @param string $ai_model AI model.
	 * @return void
	 */
	public function replaceAIContentInTemplate(&$data, $title, $products, $context, $api_key, $ai_model) {
		if (!is_array($data)) {
			return;
		}

		// Process child elements
		if (isset($data['elements']) && is_array($data['elements'])) {
			foreach ($data['elements'] as &$element) {
				$this->replaceAIContentInTemplate($element, $title, $products, $context, $api_key, $ai_model);
			}
		}

		// Process widgets with AI settings
		if (isset($data['elType']) && $data['elType'] === 'widget') {
			$widget_type = $data['widgetType'] ?? 'unknown';
			$ai_enabled = isset($data['settings']['aebg_ai_enable']) && $data['settings']['aebg_ai_enable'] === 'yes';
			$prompt = $data['settings']['aebg_ai_prompt'] ?? '';

			if ($ai_enabled && !empty($prompt)) {
				// Check if prompt is just a variable pattern
				if (preg_match('/^\{[^}]+\}$/', trim($prompt))) {
					$processed_prompt = $this->variable_replacer->replaceVariablesInPrompt($prompt, $title, $products, $context);
					if (!empty($processed_prompt) && $processed_prompt !== $prompt) {
						$data = $this->applyContentToWidget($data, $processed_prompt, $widget_type);
					}
				} else {
					// Generate content using AI
					$generated_content = $this->generateContentWithPrompt($title, $products, $context, $api_key, $ai_model, $prompt);
					if ($generated_content && !is_wp_error($generated_content)) {
						$data = $this->applyContentToWidget($data, $generated_content, $widget_type);
					}
				}
			}
		}
	}

	/**
	 * Optimize prompt for better AI performance.
	 *
	 * @param string $prompt The prompt.
	 * @param array  $context Context data.
	 * @return string Optimized prompt.
	 */
	public function optimizePrompt($prompt, $context) {
		// Truncate if too long (rough estimate: 1 token ≈ 4 characters)
		$estimated_tokens = strlen($prompt) / 4;
		$max_prompt_tokens = 3500; // Leave room for response

		if ($estimated_tokens > $max_prompt_tokens) {
			Logger::warning('Prompt too large, truncating', [
				'estimated_tokens' => round($estimated_tokens),
				'max_tokens' => $max_prompt_tokens
			]);
			$max_chars = $max_prompt_tokens * 4;
			$prompt = substr($prompt, 0, $max_chars);
		}

		return $prompt;
	}

	/**
	 * Validate generated content.
	 *
	 * @param string $content Generated content.
	 * @return bool|\WP_Error True if valid, WP_Error if invalid.
	 */
	public function validateGeneratedContent($content) {
		if (empty($content)) {
			return new \WP_Error('empty_content', 'Generated content is empty');
		}

		if (strlen($content) < 10) {
			return new \WP_Error('content_too_short', 'Generated content is too short');
		}

		// Check for common error patterns
		if (preg_match('/^(error|failed|unable|cannot)/i', trim($content))) {
			return new \WP_Error('content_contains_errors', 'Generated content appears to contain error messages');
		}

		return true;
	}

	/**
	 * Format generated content.
	 *
	 * @param string $content Generated content.
	 * @param string $format Format type.
	 * @return string Formatted content.
	 */
	public function formatGeneratedContent($content, $format = 'text-editor') {
		switch ($format) {
			case 'text-editor':
				return ContentFormatter::formatTextEditorContent($content);
			case 'heading':
				return ContentFormatter::formatHeadingContent($content);
			case 'button':
				return ContentFormatter::formatButtonContent($content);
			case 'icon-list-item':
			case 'icon-list':
			case 'aebg-icon-list':
				// Icon list items: strip only <p> tags (allow other HTML like <b>, <i>, <strong>, <span>, etc.)
				// Remove opening and closing <p> tags
				$content = preg_replace('/<\/?p[^>]*>/i', '', $content);
				// Clean up whitespace
				$content = trim($content);
				return $content;
			default:
				return $content;
		}
	}

	/**
	 * Build content prompt.
	 *
	 * @param string $title The title.
	 * @param array  $products Array of products.
	 * @param array  $context Context data.
	 * @return string Prompt.
	 */
	private function buildContentPrompt($title, $products, $context) {
		if (!empty($products)) {
			$product_names = [];
			$product_details_list = [];
			
			foreach ($products as $index => $product) {
				$product_number = $index + 1;
				$product_name = $product['name'] ?? $product['title'] ?? 'Product ' . $product_number;
				$product_names[] = $product_number . '. ' . $product_name;
				
				$product_details_list[] = [
					'name' => $product_name,
					'brand' => $product['brand'] ?? 'Unknown',
					'price' => isset($product['price']) ? number_format($product['price'], 2) . ' ' . ($product['currency'] ?? 'USD') : 'Price not available',
					'description' => $this->truncateString($product['description'] ?? '', 200),
					'rating' => $product['rating'] ?? 0,
					'merchant' => $product['merchant'] ?? 'Unknown',
				];
			}

			$product_names_text = implode("\n", $product_names);
			$product_details_json = json_encode($product_details_list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

			return "CRITICAL INSTRUCTIONS: You MUST write about ONLY the following specific products that have been selected for this post. DO NOT make up, invent, or reference any other products. DO NOT use placeholder names like 'XYZ', 'Product ABC', or generic product names.

POST TITLE: {$title}

REQUIRED PRODUCTS (you MUST use these exact products in this exact order):
{$product_names_text}

DETAILED PRODUCT INFORMATION:
{$product_details_json}

INSTRUCTIONS:
1. Write a detailed blog post with the title '{$title}'
2. You MUST feature ONLY the products listed above, in the exact order shown
3. For each product, write a detailed review using the product's EXACT name as shown above
4. Include the product's features, specifications, and your justification for why it's on the list
5. Use the shortcode `[bit_products order=\"X\"]` to refer to the products in the content, where X is the 1-based order (1, 2, 3, etc.)
6. DO NOT invent or make up product names - use ONLY the names provided above
7. DO NOT use generic placeholders like 'XYZ Product' or 'Product ABC' - use the actual product names from the list

Remember: You are writing about REAL products that have been specifically selected. Use their exact names and information as provided above.";
		} else {
			return "Write a comprehensive blog post with the title '{$title}'. This should be an informative article about {$context['category']} that provides valuable insights, tips, and information for readers. The content should be engaging, well-researched, and SEO-optimized. Include relevant information about the topic, best practices, and helpful advice.";
		}
	}

	/**
	 * Apply content to widget.
	 *
	 * @param array  $data Widget data.
	 * @param string $content Generated content.
	 * @param string $widget_type Widget type.
	 * @return array Updated widget data.
	 */
	private function applyContentToWidget($data, $content, $widget_type) {
		switch ($widget_type) {
			case 'text-editor':
				$data['settings']['editor'] = $content;
				break;
			case 'heading':
				$data['settings']['title'] = $content;
				break;
			case 'button':
				$data['settings']['text'] = $content;
				break;
			case 'image':
				$data['settings']['caption'] = $content;
				break;
			case 'icon':
				$data['settings']['text'] = $content;
				break;
			case 'icon-box':
				$data['settings']['title_text'] = $content;
				break;
			default:
				if (isset($data['settings']['content'])) {
					$data['settings']['content'] = $content;
				} elseif (isset($data['settings']['text'])) {
					$data['settings']['text'] = $content;
				} elseif (isset($data['settings']['title'])) {
					$data['settings']['title'] = $content;
				}
				break;
		}

		return $data;
	}

	/**
	 * Get cache key for content generation.
	 *
	 * @param string $title Title.
	 * @param array  $products Products.
	 * @param array  $context Context.
	 * @return string Cache key.
	 */
	private function getCacheKey($title, $products, $context) {
		return 'aebg_generated_content_' . md5($title . json_encode($products) . json_encode($context));
	}

	/**
	 * Get cached content.
	 *
	 * @param string $cache_key Cache key.
	 * @return string|false Cached content or false.
	 */
	private function getCachedContent($cache_key) {
		$cached = get_transient($cache_key);
		return $cached !== false ? $cached : false;
	}

	/**
	 * Cache generated content.
	 *
	 * @param string $cache_key Cache key.
	 * @param string $content Content.
	 * @return void
	 */
	private function cacheContent($cache_key, $content) {
		set_transient($cache_key, $content, self::CACHE_TTL);
	}

	/**
	 * Truncate string.
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

