<?php

namespace AEBG\Core;


/**
 * AI Prompt Processor Class
 * Handles AI Content Prompt processing, placeholder detection, and variable replacement.
 *
 * @package AEBG\Core
 */
class AIPromptProcessor {
	/**
	 * Variables instance.
	 *
	 * @var Variables
	 */
	private $variables;

	/**
	 * Context registry instance.
	 *
	 * @var ContextRegistry
	 */
	private $context_registry;

	/**
	 * OpenAI API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * AI model to use.
	 *
	 * @var string
	 */
	private $ai_model;

	/**
	 * System message for AI knowledge base.
	 *
	 * @var string
	 */
	private $system_message;

	/**
	 * Whether system message is enabled.
	 *
	 * @var bool
	 */
	private $system_message_enabled;

	/**
	 * AIPromptProcessor constructor.
	 *
	 * @param Variables        $variables Variables instance.
	 * @param ContextRegistry  $context_registry Context registry instance.
	 * @param string           $api_key OpenAI API key.
	 * @param string           $ai_model AI model to use.
	 */
	public function __construct($variables, $context_registry, $api_key, $ai_model = 'gpt-3.5-turbo') {
		$this->variables = $variables;
		$this->context_registry = $context_registry;
		$this->api_key = $api_key;
		$this->ai_model = $ai_model;
		$this->loadSystemMessage();
	}

	/**
	 * Load system message from settings.
	 */
	private function loadSystemMessage() {
		$settings = \AEBG\Admin\Settings::get_settings();
		$this->system_message = $settings['system_message'] ?? '';
		$this->system_message_enabled = $settings['system_message_enabled'] ?? true;
	}

	/**
	 * Get negative phrases from settings.
	 *
	 * @return array
	 */
	private function getNegativePhrases() {
		$settings = \AEBG\Admin\Settings::get_settings();
		$phrases = $settings['negative_phrases'] ?? [];
		return is_array($phrases) ? $phrases : [];
	}

	/**
	 * Process an AI prompt with full context.
	 *
	 * @param string $prompt The AI prompt.
	 * @param string $title The post title.
	 * @param array  $products Array of products.
	 * @param array  $context Additional context.
	 * @param string $field_id The field ID for context sharing.
	 * @return string
	 */
	public function processPrompt($prompt, $title, $products = [], $context = [], $field_id = null) {
		// error_log('[AEBG] AIPromptProcessor::processPrompt called with prompt: ' . $prompt);
		// error_log('[AEBG] Field ID: ' . ($field_id ?? 'null'));
		
		$trimmed_prompt = trim($prompt);
		
		// CRITICAL: Skip AI processing for image/URL/name-only variables
		// These should never be sent to OpenAI - they should be handled directly
		if (preg_match('/^\{product-\d+-image\}$/', $trimmed_prompt)) {
			error_log('[AEBG] ⚠️ SKIPPING AI processing for image variable: ' . $trimmed_prompt . ' - should be handled directly');
			return ''; // Return empty - this will be handled in applyProcessedContent
		}
		
		if (preg_match('/^\{product-\d+-(url|affiliate-url)\}$/', $trimmed_prompt)) {
			error_log('[AEBG] ⚠️ SKIPPING AI processing for URL variable: ' . $trimmed_prompt . ' - should be handled directly');
			return ''; // Return empty - this will be handled in applyProcessedContent
		}
		
		// CRITICAL: Skip AI processing for product name variable - replace directly with optimized name
		if (preg_match('/^\{product-(\d+)-name\}$/', $trimmed_prompt, $matches)) {
			$product_number = (int)$matches[1];
			$product_index = $product_number - 1;
			
			if (!empty($products[$product_index]) && is_array($products[$product_index])) {
				$product = $products[$product_index];
				// Use optimized short_name if available, otherwise fallback to name
				$product_name = !empty($product['short_name']) ? $product['short_name'] : ($product['name'] ?? '');
				
				error_log('[AEBG] ⚠️ SKIPPING AI processing for product name variable: ' . $trimmed_prompt . ' - returning optimized name directly: ' . $product_name);
				return $product_name; // Return the product name directly, no OpenAI call
			}
			
			error_log('[AEBG] ⚠️ SKIPPING AI processing for product name variable: ' . $trimmed_prompt . ' - product not found, returning empty');
			return ''; // Product not found, return empty
		}
		
		// IMPORTANT: Replace URL variables FIRST before any AI processing
		// This prevents URL variables from being sent to OpenAI
		$prompt_with_urls = $this->replaceUrlVariables($prompt, $title, $products, $context);
		// error_log('[AEBG] Prompt after URL variable replacement: ' . $prompt_with_urls);
		
		// CRITICAL: If after URL replacement, the prompt is just a URL, don't send to AI
		$trimmed_with_urls = trim($prompt_with_urls);
		if (preg_match('/^https?:\/\/.+/', $trimmed_with_urls) && strlen($trimmed_with_urls) < 200) {
			error_log('[AEBG] ⚠️ SKIPPING AI processing - prompt is just a URL: ' . substr($trimmed_with_urls, 0, 100));
			return $trimmed_with_urls; // Return the URL directly
		}
		
		// Detect placeholder text patterns (excluding URL variables)
		$placeholders = $this->detectPlaceholders($prompt_with_urls);
		
		// Replace remaining variables in the prompt (excluding URLs which are already handled)
		$processed_prompt = $this->replaceNonUrlVariables($prompt_with_urls, $title, $products, $context);
		// error_log('[AEBG] Processed prompt: ' . $processed_prompt);
		
		// CRITICAL: Double-check - if processed prompt is just a URL, don't send to AI
		$trimmed_processed = trim($processed_prompt);
		if (preg_match('/^https?:\/\/.+/', $trimmed_processed) && strlen($trimmed_processed) < 200) {
			error_log('[AEBG] ⚠️ SKIPPING AI processing - processed prompt is just a URL: ' . substr($trimmed_processed, 0, 100));
			return $trimmed_processed; // Return the URL directly
		}
		
		// Add context from other fields if field_id is provided
		if ($field_id && $this->context_registry) {
			$context_string = $this->context_registry->getContextString($field_id);
			if (!empty($context_string)) {
				$processed_prompt .= "\n\nAdditional Context:\n" . $context_string;
			}
		}
		
		// Generate content using OpenAI
		$content = $this->generateContent($processed_prompt);
		// error_log('[AEBG] Generated content: ' . $content);
		
		// Replace placeholders if any were detected
		if (!empty($placeholders)) {
			$content = $this->replacePlaceholders($content, $placeholders);
		}
		
		return $content;
	}

	/**
	 * Replace URL variables in content before AI processing.
	 * This ensures URL variables are converted to actual URLs and not sent to OpenAI.
	 *
	 * @param string $content The content to process.
	 * @param string $title The post title.
	 * @param array  $products Array of products.
	 * @param array  $context Additional context.
	 * @return string Content with URL variables replaced.
	 */
	private function replaceUrlVariables($content, $title, $products = [], $context = []) {
		// error_log('[AEBG] AIPromptProcessor::replaceUrlVariables - Processing URL variables');
		
		// Replace product URL variables with actual URLs
		if (!empty($products) && is_array($products)) {
			foreach ($products as $index => $product) {
				$product_num = $index + 1;
				
				// Extract URL from product data
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
				
				// Replace URL variable with actual URL
				$url_variable = "{product-{$product_num}-url}";
				if (strpos($content, $url_variable) !== false) {
					$content = str_replace($url_variable, $product_url, $content);
					error_log('[AEBG] Replaced ' . $url_variable . ' with: ' . $product_url);
				}
			}
		}
		
		// Replace other URL-related variables
		$url_variables = [
			'{product-url}' => $products[0]['url'] ?? '',
			'{product-link}' => $products[0]['url'] ?? '',
			'{affiliate-link}' => $products[0]['affiliate_url'] ?? $products[0]['url'] ?? '',
		];
		
		foreach ($url_variables as $variable => $replacement) {
			if (!empty($replacement) && strpos($content, $variable) !== false) {
				$content = str_replace($variable, $replacement, $content);
				error_log('[AEBG] Replaced ' . $variable . ' with: ' . $replacement);
			}
		}
		
		return $content;
	}

	/**
	 * Replace non-URL variables in content.
	 * This handles all variables except URLs which are already processed.
	 *
	 * @param string $content The content to process.
	 * @param string $title The post title.
	 * @param array  $products Array of products.
	 * @param array  $context Additional context.
	 * @return string Content with non-URL variables replaced.
	 */
	private function replaceNonUrlVariables($content, $title, $products = [], $context = []) {
		// error_log('[AEBG] AIPromptProcessor::replaceNonUrlVariables - Processing non-URL variables');
		
		// Replace basic variables
		$content = str_replace('{title}', $title, $content);
		$content = str_replace('{year}', date('Y'), $content);
		$content = str_replace('{date}', date('Y-m-d'), $content);
		$content = str_replace('{time}', date('H:i:s'), $content);
		
		// Replace product variables (excluding URLs)
		if (!empty($products) && is_array($products)) {
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
				$content = str_replace("{product-{$product_num}-merchant}", (string)($product['merchant'] ?? ''), $content);
				
				// Replace URL variables
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
				$content = str_replace("{product-{$product_num}-category}", (string)($product['category'] ?? ''), $content);
				$content = str_replace("{product-{$product_num}-availability}", (string)($product['availability'] ?? ''), $content);
				
				// Product image variables
				if (!empty($product['featured_image_id'])) {
					$featured_image_url = wp_get_attachment_url($product['featured_image_id']);
					$featured_image_html = wp_get_attachment_image($product['featured_image_id'], 'full');
					
					$content = str_replace("{product-{$product_num}-featured-image}", (string)($featured_image_url ?: ''), $content);
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
			}
		}
		
		// Replace context variables
		foreach ($context as $key => $value) {
			$variable = '{' . $key . '}';
			if (strpos($content, $variable) !== false) {
				$content = str_replace($variable, (string)$value, $content);
			}
		}
		
		return $content;
	}

	/**
	 * Detect placeholder text patterns in content.
	 * Excludes URL variables which should not be processed by AI.
	 *
	 * @param string $text The text to analyze.
	 * @return array
	 */
	private function detectPlaceholders($text) {
		// First, remove any URL variables from the text to prevent them from being detected as placeholders
		$text_without_urls = $this->removeUrlVariablesFromText($text);
		
		$patterns = [
			'/Add Your (.*?) Here/i',
			'/Enter Your (.*?) Here/i',
			'/Click to edit (.*?)/i',
			'/Type your (.*?) here/i',
			'/Insert your (.*?) here/i',
			'/Place your (.*?) here/i',
			'/Add (.*?) here/i',
			'/Enter (.*?) here/i',
			'/Click to add (.*?)/i',
			'/Click to enter (.*?)/i',
			'/Click to type (.*?)/i',
			'/Click to insert (.*?)/i',
			'/Click to place (.*?)/i',
			'/Double click to edit/i',
			'/Double click to add/i',
			'/Double click to enter/i',
			'/Double click to type/i',
			'/Double click to insert/i',
			'/Double click to place/i',
		];
		
		$placeholders = [];
		foreach ($patterns as $pattern) {
			if (preg_match_all($pattern, $text_without_urls, $matches)) {
				$placeholders = array_merge($placeholders, $matches[0]);
			}
		}
		
		// Remove duplicates and trim
		$placeholders = array_unique(array_map('trim', $placeholders));
		
		// error_log('[AEBG] AIPromptProcessor::detectPlaceholders - Found placeholders: ' . json_encode($placeholders));
		
		return $placeholders;
	}

	/**
	 * Remove URL variables from text to prevent them from being detected as placeholders.
	 * This ensures URL variables are not sent to OpenAI for processing.
	 *
	 * @param string $text The text to process.
	 * @return string Text with URL variables removed.
	 */
	private function removeUrlVariablesFromText($text) {
		// Remove product URL variables
		$text = preg_replace('/\{product-\d+-url\}/', '', $text);
		
		// Remove other URL variables
		$text = preg_replace('/\{product-url\}/', '', $text);
		$text = preg_replace('/\{product-link\}/', '', $text);
		$text = preg_replace('/\{affiliate-link\}/', '', $text);
		
		// Remove any remaining URL-like patterns that might be variables
		$text = preg_replace('/\{[^}]*-url\}/', '', $text);
		$text = preg_replace('/\{[^}]*-link\}/', '', $text);
		
		return $text;
	}

	/**
	 * Generate content using OpenAI API with retry logic for rate limiting.
	 *
	 * @param string $prompt The prompt to send to the API.
	 * @return string The generated content.
	 */
	private function generateContent($prompt) {
		// CRITICAL: Register shutdown function to catch fatal errors
		register_shutdown_function(function() {
			$error = error_get_last();
			if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
				error_log('[AEBG] AIPromptProcessor::generateContent FATAL ERROR: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']);
			}
		});
		
		try {
			// error_log('[AEBG] AIPromptProcessor::generateContent called with prompt: ' . substr($prompt, 0, 100) . '...');
			
			// CRITICAL FIX #5: Validate prompt size before processing (OpenAI best practice)
			// Rough estimate: 1 token ≈ 4 characters for English, but can vary
			// gpt-3.5-turbo has 4096 token context window, but we use max_tokens=512 for completion
			// So prompt should be max ~3500 tokens (14,000 chars) to leave room for response
			$estimated_tokens = strlen($prompt) / 4;
			$max_prompt_tokens = 3500; // Leave room for 512 token response
			if ($estimated_tokens > $max_prompt_tokens) {
				error_log('[AEBG] ⚠️ Prompt too large: ~' . round($estimated_tokens) . ' tokens (max: ' . $max_prompt_tokens . '), truncating...');
				$max_chars = $max_prompt_tokens * 4;
				$prompt = substr($prompt, 0, $max_chars);
				error_log('[AEBG] Prompt truncated to ' . strlen($prompt) . ' characters (~' . round(strlen($prompt) / 4) . ' tokens)');
			}
			
			$api_endpoint = $this->getApiEndpoint($this->ai_model);
			$request_body = $this->buildRequestBody($this->ai_model, $prompt, 512, 0.7);
			
			// Retry configuration
			$max_retries = 3;
			$base_delay = 2; // seconds
			$attempt = 0;
			
			while ($attempt <= $max_retries) {
				$attempt++;
				// OpenAI best practice: Chat completions can take 30-60 seconds for longer prompts
				// Use 60 seconds to ensure we don't timeout prematurely
				$api_timeout = 60; // Increased timeout for chat completions (OpenAI recommendation)
				
				// CRITICAL: Log BEFORE the attempt log to ensure we're in the loop
				// error_log('[AEBG] ===== INSIDE RETRY LOOP - ATTEMPT ' . $attempt . ' =====');
				// error_log('[AEBG] AIPromptProcessor::generateContent - About to log attempt number...');
				
				// CRITICAL: Use try-catch around EVERY operation to catch fatal errors
				try {
					// error_log('[AEBG] AIPromptProcessor::generateContent API request attempt ' . $attempt . ' of ' . ($max_retries + 1) . ' (timeout: ' . $api_timeout . 's)');
					// error_log('[AEBG] AIPromptProcessor::generateContent - Attempt log completed successfully');
				} catch (\Throwable $e) {
					error_log('[AEBG] AIPromptProcessor::generateContent ERROR logging attempt: ' . $e->getMessage());
					return '';
				}
				
				// CRITICAL FIX: OpenAI API request body is a simple array - NO NEED to clean Elementor data!
				// cleanElementorDataForEncoding is for Elementor data structures, not simple API request bodies
				// Calling it causes fixUrlsInElementorData which creates Shortcodes instance and can hang
				// Just encode the request body directly - it's already clean
				
				// CRITICAL FIX #4: Validate request body size before JSON encoding (OpenAI best practice)
				// Estimate size to prevent API rejections due to oversized requests
				$estimated_size = strlen(json_encode($request_body, JSON_UNESCAPED_UNICODE));
				$max_request_size = 1000000; // 1MB limit (OpenAI API has limits)
				if ($estimated_size > $max_request_size) {
					error_log('[AEBG] ⚠️ Request body too large: ' . $estimated_size . ' bytes (max: ' . $max_request_size . ' bytes)');
					if ($attempt > $max_retries) {
						return '';
					}
					// Try to reduce prompt size and retry
					$prompt = substr($prompt, 0, strlen($prompt) * 0.8); // Reduce by 20%
					$request_body = $this->buildRequestBody($this->ai_model, $prompt, 512, 0.7);
					error_log('[AEBG] Reduced prompt size and retrying...');
					continue;
				}
				
				// JSON encode with error handling
				// error_log('[AEBG] AIPromptProcessor::generateContent Starting JSON encoding...');
				$json_start = microtime(true);
				$json_body = json_encode($request_body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				$json_elapsed = microtime(true) - $json_start;
				// error_log('[AEBG] AIPromptProcessor::generateContent JSON encoding completed in ' . round($json_elapsed, 2) . ' seconds');
				
				if ($json_body === false) {
					error_log('[AEBG] AIPromptProcessor::generateContent JSON encoding failed: ' . json_last_error_msg());
					if ($attempt > $max_retries) {
						return '';
					}
					continue;
				}
			
			// ULTRA-ROBUST: Use APIClient::makeRequest() which has:
			// 1. DNS pre-flight check
			// 2. Direct cURL with ALL timeout options
			// 3. PHP-level timeout watchdog
			// 4. Request timing detection
			// 5. Comprehensive error handling
			// This is the most reliable way to prevent hangs once and for all
			// error_log('[AEBG] AIPromptProcessor::generateContent Sending API request to: ' . $api_endpoint);
			// error_log('[AEBG] AIPromptProcessor::generateContent - Global flag check: ' . (isset($GLOBALS['AEBG_GENERATION_IN_PROGRESS']) ? 'SET' : 'NOT SET'));
			// error_log('[AEBG] AIPromptProcessor::generateContent - ActionHandler::is_executing(): ' . (class_exists('\AEBG\Core\ActionHandler') && \AEBG\Core\ActionHandler::is_executing() ? 'TRUE' : 'FALSE'));
			
			// CRITICAL: Update Action Scheduler action timestamp BEFORE long API call
			// This prevents Action Scheduler from marking the action as failed if the API call takes a while
			if (isset($GLOBALS['aebg_current_action_id']) && $GLOBALS['aebg_current_action_id'] && class_exists('\ActionScheduler_Store')) {
				try {
					$store = \ActionScheduler_Store::instance();
					$action = $store->fetch_action($GLOBALS['aebg_current_action_id']);
					if ($action) {
						$store->save_action($action);
					}
				} catch (\Exception $e) {
					// Silently fail - best effort
				}
			}
			
			// Use APIClient::makeRequest() for ultra-robust timeout handling
			$data = \AEBG\Core\APIClient::makeRequest($api_endpoint, $this->api_key, $request_body, $api_timeout, $max_retries);
			
			// Handle errors from APIClient
			if (is_wp_error($data)) {
				$error_code = $data->get_error_code();
				$error_message = $data->get_error_message();
				error_log('[AEBG] AIPromptProcessor::generateContent OpenAI API request failed: [' . $error_code . '] ' . $error_message);
				
				// Check if it's a timeout/hang error - these should be retried
				$is_timeout = (
					strpos($error_message, 'timeout') !== false ||
					strpos($error_message, 'timed out') !== false ||
					strpos($error_message, 'exceeded timeout') !== false ||
					$error_code === 'http_request_failed' ||
					$error_code === 'http_request_timeout' ||
					$error_code === 'dns_resolution_failed' ||
					$error_code === 'empty_response'
				);
				
				if ($attempt > $max_retries) {
					error_log('[AEBG] Max retries exceeded for API request failure');
					return '';
				}
				
				// For timeout/hang errors, use shorter delays (network might recover quickly)
				// For other errors, use standard exponential backoff
				if ($is_timeout) {
					error_log('[AEBG] Timeout/hang detected - using shorter retry delay');
					$delay = min($base_delay * $attempt, 5); // Max 5 seconds for timeouts
				} else {
					$delay = $base_delay * pow(2, $attempt - 1);
				}
				
				// Add jitter to prevent thundering herd
				$jitter = rand(0, 2);
				$delay += $jitter;
				
				error_log('[AEBG] Waiting ' . $delay . ' seconds before retry (attempt ' . $attempt . ' of ' . ($max_retries + 1) . ', with ' . $jitter . 's jitter)...');
				sleep($delay);
				continue;
			}
			
			// Validate data is not empty
			if (empty($data) || !is_array($data)) {
				error_log('[AEBG] ⚠️ Empty or invalid response data from APIClient');
				if ($attempt > $max_retries) {
					error_log('[AEBG] Max retries exceeded after empty response');
					return '';
				}
				// Treat as timeout and retry with shorter delay
				$delay = min($base_delay * $attempt, 5);
				$jitter = rand(0, 2);
				$delay += $jitter;
				error_log('[AEBG] Waiting ' . $delay . ' seconds before retry (empty response, with ' . $jitter . 's jitter)...');
				sleep($delay);
				continue;
			}
			
			// CRITICAL FIX #6: Validate response structure (OpenAI best practice)
			// Note: APIClient::makeRequest() already handles HTTP status codes, rate limiting, and server errors
			// We only need to validate the data structure here
			if (!isset($data['choices']) || !is_array($data['choices']) || empty($data['choices'])) {
				error_log('[AEBG] ⚠️ Invalid API response structure: missing or empty choices array');
				if ($attempt > $max_retries) {
					return '';
				}
				// Retry on invalid response structure (might be transient)
				$delay = $base_delay * pow(2, $attempt - 1);
				$jitter = rand(0, 2);
				$delay += $jitter;
				error_log('[AEBG] Waiting ' . $delay . ' seconds before retry (invalid response structure)...');
				sleep($delay);
				continue;
			}
			
			if (!isset($data['choices'][0])) {
				error_log('[AEBG] ⚠️ Invalid API response: empty choices array');
				if ($attempt > $max_retries) {
					return '';
				}
				$delay = $base_delay * pow(2, $attempt - 1);
				$jitter = rand(0, 2);
				$delay += $jitter;
				error_log('[AEBG] Waiting ' . $delay . ' seconds before retry (empty choices)...');
				sleep($delay);
				continue;
			}

			// Handle API errors in response body (APIClient handles HTTP errors, but API-level errors come in response)
			if (isset($data['error'])) {
				$error_message = $data['error']['message'] ?? 'Unknown API error';
				error_log('[AEBG] OpenAI API error in response: ' . $error_message);
				
				// Check if it's a rate limit error in the response body
				if (strpos($error_message, 'rate limit') !== false || strpos($error_message, 'quota') !== false) {
					if ($attempt <= $max_retries) {
						// CRITICAL FIX #1: Add jitter to rate limit retries (OpenAI best practice)
						$delay = $base_delay * pow(2, $attempt - 1);
						$jitter = rand(0, 2); // Add 0-2 seconds of jitter
						$delay += $jitter;
						error_log('[AEBG] Rate limit error detected, waiting ' . $delay . ' seconds before retry (with ' . $jitter . 's jitter)...');
						sleep($delay);
						continue;
					}
				}
				
				if ($attempt > $max_retries) {
					return '';
				}
				// Wait before retry for other errors
				// CRITICAL FIX #1: Add jitter to all retries (OpenAI best practice)
				$delay = $base_delay * pow(2, $attempt - 1);
				$jitter = rand(0, 2); // Add 0-2 seconds of jitter
				$delay += $jitter;
				error_log('[AEBG] Waiting ' . $delay . ' seconds before retry (with ' . $jitter . 's jitter)...');
				sleep($delay);
				continue;
			}

			// CRITICAL FIX #7: Extract and log token usage (OpenAI best practice - cost optimization)
			if (isset($data['usage'])) {
				$tokens_used = $data['usage']['total_tokens'] ?? 0;
				$prompt_tokens = $data['usage']['prompt_tokens'] ?? 0;
				$completion_tokens = $data['usage']['completion_tokens'] ?? 0;
				
				// Store token usage in database
				// Get batch info from global context if available
				$item_id = $GLOBALS['aebg_current_item_id'] ?? null;
				$batch_id = null;
				$batch_item_id = null;
				
				if ($item_id) {
					global $wpdb;
					$item = $wpdb->get_row($wpdb->prepare(
						"SELECT batch_id FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d",
						$item_id
					));
					if ($item) {
						$batch_id = $item->batch_id;
						$batch_item_id = $item_id;
					}
				}
				
				\AEBG\Core\UsageTracker::record_api_usage([
					'batch_id' => $batch_id,
					'batch_item_id' => $batch_item_id,
					'post_id' => $GLOBALS['aebg_current_post_id'] ?? null,
					'user_id' => get_current_user_id(),
					'model' => $this->ai_model,
					'prompt_tokens' => $prompt_tokens,
					'completion_tokens' => $completion_tokens,
					'total_tokens' => $tokens_used,
					'request_type' => 'generation',
					'field_id' => null, // Field ID not available in this context
					'step_name' => $GLOBALS['aebg_current_step'] ?? null,
				]);
			}
			
		// Success - extract and return content
		$content = $this->extractContentFromResponse($data, $this->ai_model);
		// error_log('[AEBG] Successfully extracted content from API response: ' . substr($content, 0, 100) . '...');
		return $content;
		}

		} catch (\Throwable $e) {
			error_log('[AEBG] AIPromptProcessor::generateContent FATAL ERROR in main try block: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
			return '';
		}
		
		error_log('[AEBG] All retry attempts failed');
		return '';
	}

	/**
	 * Replace placeholders in generated content.
	 *
	 * @param string $content The generated content.
	 * @param array  $placeholders Array of placeholders to replace.
	 * @return string
	 */
	private function replacePlaceholders($content, $placeholders) {
		foreach ($placeholders as $placeholder) {
			// Extract the meaningful part from the placeholder
			$meaningful_part = $this->extractMeaningfulPart($placeholder);
			
			// Replace the placeholder with the meaningful content
			$content = str_replace($placeholder, $meaningful_part, $content);
		}
		
		return $content;
	}

	/**
	 * Extract meaningful part from placeholder text.
	 *
	 * @param string $placeholder The placeholder text.
	 * @return string
	 */
	private function extractMeaningfulPart($placeholder) {
		// Remove common placeholder patterns to get the meaningful part
		$patterns = [
			'/^Add Your (.*?) Here$/i',
			'/^Enter Your (.*?) Here$/i',
			'/^Click to edit (.*?)$/i',
			'/^Type your (.*?) here$/i',
			'/^Insert your (.*?) here$/i',
			'/^Place your (.*?) here$/i',
			'/^Add (.*?) here$/i',
			'/^Enter (.*?) here$/i',
			'/^Click to add (.*?)$/i',
			'/^Click to enter (.*?)$/i',
			'/^Click to type (.*?)$/i',
			'/^Click to insert (.*?)$/i',
			'/^Click to place (.*?)$/i',
			'/^Double click to edit$/i',
			'/^Double click to add$/i',
			'/^Double click to enter$/i',
			'/^Double click to type$/i',
			'/^Double click to insert$/i',
			'/^Double click to place$/i',
		];
		
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $placeholder, $matches)) {
				return $matches[1] ?? $placeholder;
			}
		}
		
		return $placeholder;
	}

	/**
	 * Get the appropriate API endpoint based on the model.
	 *
	 * @param string $model The AI model name.
	 * @return string The API endpoint.
	 */
	private function getApiEndpoint($model) {
		// GPT models use the chat completions endpoint
		if (strpos($model, 'gpt-') === 0) {
			return 'https://api.openai.com/v1/chat/completions';
		}
		// Legacy models use the completions endpoint
		return 'https://api.openai.com/v1/completions';
	}

	/**
	 * Build the request body based on the model type.
	 *
	 * @param string $model The AI model name.
	 * @param string $prompt The prompt.
	 * @param int    $max_tokens Maximum tokens.
	 * @param float  $temperature Temperature.
	 * @return array The request body.
	 */
	private function buildRequestBody($model, $prompt, $max_tokens, $temperature) {
		// GPT models use the chat completions format
		if (strpos($model, 'gpt-') === 0) {
			$messages = [];
			
			// Add system message if enabled and not empty
			if ($this->system_message_enabled && !empty($this->system_message)) {
				$system_content = $this->system_message;
				
				// Append negative phrases instruction
				$negative_phrases = $this->getNegativePhrases();
				if (!empty($negative_phrases)) {
					$phrases_list = implode(', ', array_map(function($phrase) {
						return '"' . $phrase . '"';
					}, $negative_phrases));
					$system_content .= "\n\nIMPORTANT RESTRICTION: You must NEVER use the following phrases in your responses: " . $phrases_list . ". Always avoid these exact phrases and any variations or similar expressions of them. If you find yourself about to use any of these phrases, rephrase your response using different wording.";
				}
				
				$messages[] = [
					'role' => 'system',
					'content' => $system_content
				];
			}
			
			// Add user message
			$messages[] = [
				'role' => 'user',
				'content' => $prompt
			];
			
			return array_merge(
				[
					'model' => $model,
					'messages' => $messages,
					'temperature' => $temperature,
				],
				APIClient::getCompletionLimitParam( $model, $max_tokens )
			);
		}
		// Legacy models use the completions format
		return array_merge(
			[
				'model' => $model,
				'prompt' => $prompt,
				'temperature' => $temperature,
			],
			APIClient::getCompletionLimitParam( $model, $max_tokens )
		);
	}

	/**
	 * Extract content from the API response based on model type.
	 *
	 * @param array  $data The API response data.
	 * @param string $model The AI model name.
	 * @return string The extracted content.
	 */
	private function extractContentFromResponse($data, $model) {
		// GPT models return content in choices[0].message.content
		if (strpos($model, 'gpt-') === 0) {
			return trim($data['choices'][0]['message']['content'] ?? '');
		}
		// Legacy models return content in choices[0].text
		return trim($data['choices'][0]['text'] ?? '');
	}

	/**
	 * Process Elementor widget with AI prompt.
	 *
	 * @param array  $widget_data The widget data.
	 * @param string $title The post title.
	 * @param array  $products Array of products.
	 * @param array  $context Additional context.
	 * @param string $field_id The field ID.
	 * @return array
	 */
	public function processElementorWidget($widget_data, $title, $products = [], $context = [], $field_id = null) {
		if (!isset($widget_data['settings']['aebg_ai_enable']) || 
			$widget_data['settings']['aebg_ai_enable'] !== 'yes' || 
			empty($widget_data['settings']['aebg_ai_prompt'])) {
			return $widget_data;
		}

		$prompt = $widget_data['settings']['aebg_ai_prompt'];
		$generated_content = $this->processPrompt($prompt, $title, $products, $context, $field_id);

		if (!empty($generated_content)) {
			$widget_data = $this->applyContentToWidget($widget_data, $generated_content);
		}

		return $widget_data;
	}

	/**
	 * Apply generated content to widget based on widget type.
	 *
	 * @param array  $widget_data The widget data.
	 * @param string $content The generated content.
	 * @return array
	 */
	private function applyContentToWidget($widget_data, $content) {
		$widget_type = $widget_data['widgetType'] ?? '';

		switch ($widget_type) {
			case 'text-editor':
				$widget_data['settings']['editor'] = $content;
				break;
			case 'heading':
				$widget_data['settings']['title'] = $content;
				break;
			case 'button':
				$widget_data['settings']['text'] = $content;
				break;
			case 'image':
				$widget_data['settings']['caption'] = $content;
				break;
			case 'icon':
				$widget_data['settings']['text'] = $content;
				break;
			case 'icon-box':
				$widget_data['settings']['title_text'] = $content;
				break;
			case 'flip-box':
				$widget_data['settings']['title_text_a'] = $content;
				break;
			case 'call-to-action':
				$widget_data['settings']['title'] = $content;
				break;
			default:
				// For unknown widget types, try common content fields
				if (isset($widget_data['settings']['content'])) {
					$widget_data['settings']['content'] = $content;
				} elseif (isset($widget_data['settings']['text'])) {
					$widget_data['settings']['text'] = $content;
				} elseif (isset($widget_data['settings']['title'])) {
					$widget_data['settings']['title'] = $content;
				}
				break;
		}

		return $widget_data;
	}

	/**
	 * Get available variables for help/display purposes.
	 *
	 * @return array
	 */
	public function getAvailableVariables() {
		return $this->variables->getAvailableVariables();
	}

	/**
	 * Clean Elementor data for JSON encoding
	 *
	 * @param mixed $elementor_data The data to clean
	 * @return mixed The cleaned data
	 */
	private function cleanElementorDataForEncoding($elementor_data) {
		if (is_array($elementor_data)) {
			$cleaned = [];
			foreach ($elementor_data as $key => $value) {
				$cleaned[$key] = $this->cleanElementorDataForEncoding($value);
			}
			return $cleaned;
		} elseif (is_string($elementor_data)) {
			return $this->cleanStringForJson($elementor_data);
		}
		return $elementor_data;
	}

	/**
	 * Clean a string value for JSON encoding
	 *
	 * @param string $string The string to clean
	 * @return string The cleaned string
	 */
	private function cleanStringForJson($string) {
		if (!is_string($string)) {
			return $string;
		}
		
		// First, handle any control characters that could break JSON
		$cleaned = preg_replace('/[\x00-\x1F\x7F]/', '', $string);
		
		// Use json_encode to properly escape the string, then remove the surrounding quotes
		$json_encoded = json_encode($cleaned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json_encoded === false) {
			// If json_encode fails, fall back to manual escaping
			$cleaned = str_replace('"', '\\"', $cleaned);
			$cleaned = str_replace(["\r", "\n", "\t"], ['\\r', '\\n', '\\t'], $cleaned);
			return $cleaned;
		}
		
		// Remove the surrounding quotes that json_encode adds
		$cleaned = substr($json_encoded, 1, -1);
		
		return $cleaned;
	}
	
} 