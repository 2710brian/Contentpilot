<?php

namespace AEBG\Core;

/**
 * APIClient Class
 * 
 * Handles all OpenAI API requests with robust timeout handling, retry logic,
 * DNS pre-flight checks, PHP-level timeout watchdog, and rate limit management.
 *
 * @package AEBG\Core
 */
class APIClient {
	
	/**
	 * Make an API request with robust timeout handling and retry logic.
	 * 
	 * Features:
	 * 1. DNS pre-flight check
	 * 2. Direct cURL with ALL timeout options
	 * 3. PHP-level timeout watchdog
	 * 4. Request timing detection
	 * 5. Comprehensive error handling
	 *
	 * @param string $api_endpoint The API endpoint URL.
	 * @param string $api_key The API key.
	 * @param array $request_body The request body data.
	 * @param int $timeout Timeout in seconds.
	 * @param int $max_retries Maximum number of retries.
	 * @return array|\WP_Error The API response data or WP_Error on failure.
	 */
	public static function makeRequest($api_endpoint, $api_key, $request_body, $timeout = 60, $max_retries = 3) {
		$retry_count = 0;
		$last_error = null;
		
		while ($retry_count <= $max_retries) {
			$request_start = microtime(true);
			
			// Set flag to prevent shortcode execution during API request
			$GLOBALS['AEBG_API_REQUEST_IN_PROGRESS'] = true;
			
			// Suppress error output during API requests
			$old_error_reporting = error_reporting();
			$old_display_errors = ini_get('display_errors');
			error_reporting(E_ERROR | E_PARSE); // Only show critical errors, suppress warnings/deprecations
			@ini_set('display_errors', 0); // Prevent error output
			
			// Start output buffering to catch any accidental output
			ob_start();
			
			// Set up watchdog timeout to detect hung requests
			$watchdog_timeout = $timeout + 5;
			
			// PHP-level timeout protection to prevent infinite hangs
			// If cURL hangs, PHP will kill it after this timeout
			// We add a safety buffer (timeout + 15s) to ensure the request can complete normally
			$php_timeout_buffer = $timeout + 15;
			$current_php_timeout = ini_get('max_execution_time');
			$original_php_timeout = $current_php_timeout;
			$php_timeout_modified = false;
			
			// Only modify PHP timeout if absolutely necessary to prevent hangs
			// DO NOT reduce timeout if it's already at a reasonable value (like 1800s)
			// Reducing timeout unnecessarily can cause premature job termination
			$unreasonably_high_threshold = 3600; // 1 hour - anything above this is considered unreasonably high
			
			if ($current_php_timeout == 0) {
				// Unlimited timeout - set to buffer to prevent infinite hangs
				$safe_timeout = $php_timeout_buffer;
				if (@set_time_limit($safe_timeout)) {
					$php_timeout_modified = true;
					error_log('[AEBG] APIClient::makeRequest: Set PHP timeout to ' . $safe_timeout . 's (was: unlimited) for request timeout: ' . $timeout . 's');
				}
			} elseif ($current_php_timeout > $unreasonably_high_threshold) {
				// Unreasonably high timeout - reduce to buffer to prevent hangs
				$safe_timeout = $php_timeout_buffer;
				if (@set_time_limit($safe_timeout)) {
					$php_timeout_modified = true;
					error_log('[AEBG] APIClient::makeRequest: Reduced PHP timeout from ' . $current_php_timeout . 's to ' . $safe_timeout . 's (was unreasonably high) for request timeout: ' . $timeout . 's');
				}
			} elseif ($current_php_timeout < $php_timeout_buffer) {
				// Too low timeout - increase to buffer to prevent premature kills
				$safe_timeout = $php_timeout_buffer;
				if (@set_time_limit($safe_timeout)) {
					$php_timeout_modified = true;
					error_log('[AEBG] APIClient::makeRequest: Increased PHP timeout from ' . $current_php_timeout . 's to ' . $safe_timeout . 's (was too low) for request timeout: ' . $timeout . 's');
				}
			}
			
			try {
				// DNS pre-flight check: Verify we can resolve the hostname before making the request
				// This prevents cURL from hanging on DNS resolution issues
				$parsed_url = parse_url($api_endpoint);
				$hostname = $parsed_url['host'] ?? '';
				
				if (!empty($hostname)) {
					$dns_check_start = microtime(true);
					$ip = @gethostbyname($hostname);
					$dns_check_elapsed = microtime(true) - $dns_check_start;
					
					if ($ip === $hostname) {
						// DNS resolution failed (gethostbyname returns hostname if it can't resolve)
						$error_message = 'DNS resolution failed for ' . $hostname;
						error_log('[AEBG] APIClient::makeRequest DNS pre-flight check failed: ' . $error_message);
						
						// Retry on DNS failures
						if ($retry_count < $max_retries) {
							$retry_count++;
							$delay = min(2 * $retry_count, 10);
							error_log('[AEBG] DNS resolution failed, retrying after ' . $delay . ' seconds (attempt ' . $retry_count . '/' . $max_retries . ')');
							sleep($delay);
							continue;
						}
						
						$last_error = new \WP_Error('dns_resolution_failed', $error_message);
						continue;
					} else {
						error_log('[AEBG] APIClient::makeRequest DNS pre-flight check passed: ' . $hostname . ' -> ' . $ip . ' (took ' . round($dns_check_elapsed, 3) . 's)');
					}
				}
				
				// Use cURL for robust timeout handling
				$ch = curl_init($api_endpoint);
				
				$headers = [
					'Content-Type: application/json',
					'Authorization: Bearer ' . $api_key,
					'User-Agent: WordPress/AEBG-Plugin/1.0.1',
					'Connection: close', // Force connection close to prevent reuse
				];
				
				curl_setopt_array($ch, [
					CURLOPT_POST => true,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_HTTPHEADER => $headers,
					CURLOPT_POSTFIELDS => json_encode($request_body),
					CURLOPT_TIMEOUT => $timeout,
					CURLOPT_CONNECTTIMEOUT => 10,
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_FRESH_CONNECT => true,  // Force new connection
					CURLOPT_FORBID_REUSE => true,   // Prevent connection reuse
					CURLOPT_TCP_NODELAY => true,    // Disable Nagle algorithm for faster connections
					CURLOPT_IPRESOLVE => CURL_IPRESOLVE_WHATEVER, // Let cURL decide IP version
				]);
				
				$response = curl_exec($ch);
				$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				$curl_error = curl_error($ch);
				$curl_errno = curl_errno($ch);
				
				curl_close($ch);
				
				$request_elapsed = microtime(true) - $request_start;
				error_log('[AEBG] APIClient::makeRequest: Request completed in ' . round($request_elapsed, 2) . 's');
				
				// Check if request exceeded watchdog timeout (hung request)
				if ($request_elapsed > $watchdog_timeout) {
					error_log('[AEBG] ⚠️ WARNING: API request exceeded watchdog timeout (' . round($request_elapsed, 2) . 's > ' . $watchdog_timeout . 's) - treating as hung request');
					if ($curl_errno === 0) {
						// Request completed but took too long - might be a slow response
						error_log('[AEBG] Request completed but exceeded watchdog timeout - response may be valid but slow');
					} else {
						// Request likely hung
						$last_error = new \WP_Error('http_request_timeout', 'Request exceeded watchdog timeout (' . round($request_elapsed, 2) . 's > ' . $watchdog_timeout . 's) - hung request detected');
						if ($retry_count < $max_retries) {
							$retry_count++;
							$delay = min(2 * $retry_count, 10);
							error_log('[AEBG] Hung request detected, retrying after ' . $delay . ' seconds (attempt ' . $retry_count . '/' . $max_retries . ')');
							sleep($delay);
							continue;
						}
					}
				}
				
				// Handle cURL errors
				if ($curl_errno !== 0) {
					$error_message = 'cURL error: ' . $curl_error . ' (code: ' . $curl_errno . ')';
					error_log('[AEBG] APIClient::makeRequest cURL error: ' . $error_message);
					
					// Retry on connection/timeout errors
					if (in_array($curl_errno, [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST])) {
						$retry_count++;
						if ($retry_count <= $max_retries) {
							$delay = min(2 * $retry_count, 10); // Exponential backoff, max 10s
							error_log('[AEBG] Retrying after ' . $delay . ' seconds (attempt ' . $retry_count . '/' . $max_retries . ')');
							sleep($delay);
							continue;
						}
					}
					
					$last_error = new \WP_Error('http_request_failed', $error_message);
					continue;
				}
				
				// Handle HTTP errors
				if ($http_code >= 400) {
					$response_data = json_decode($response, true);
					$error_message = $response_data['error']['message'] ?? 'HTTP ' . $http_code . ' error';
					
					// Check for non-retryable errors (context length, invalid request, etc.)
					$is_context_error = stripos($error_message, 'context length') !== false || 
					                    stripos($error_message, 'maximum context length') !== false ||
					                    stripos($error_message, 'maximum tokens') !== false;
					$is_invalid_request = stripos($error_message, 'invalid') !== false && 
					                      stripos($error_message, 'request') !== false;
					
					// Don't retry on context length errors or invalid requests - they won't succeed on retry
					if ($is_context_error || $is_invalid_request) {
						error_log('[AEBG] APIClient::makeRequest Non-retryable error (context/invalid request): ' . $error_message);
						$last_error = new \WP_Error('http_request_failed', $error_message);
						break; // Exit retry loop immediately
					}
					
					// Handle rate limiting (429)
					if ($http_code === 429) {
						$retry_count++;
						if ($retry_count <= $max_retries) {
							// Extract retry-after header if available, otherwise use exponential backoff
							$retry_after = isset($response_data['error']['retry_after']) 
								? (int)$response_data['error']['retry_after'] 
								: min(2 * $retry_count, 60); // Max 60s wait
							
							// Add jitter to prevent thundering herd
							$jitter = rand(1, 3);
							$delay = $retry_after + $jitter;
							
							error_log('[AEBG] Rate limit detected (429), waiting ' . $delay . ' seconds before retry (attempt ' . $retry_count . '/' . $max_retries . ')');
							sleep($delay);
							continue;
						}
					}
					
					// Handle server errors (5xx) with retry
					if ($http_code >= 500 && $retry_count < $max_retries) {
						$retry_count++;
						$delay = min(2 * $retry_count, 10);
						error_log('[AEBG] Server error (' . $http_code . '), retrying after ' . $delay . ' seconds (attempt ' . $retry_count . '/' . $max_retries . ')');
						sleep($delay);
						continue;
					}
					
					error_log('[AEBG] APIClient::makeRequest HTTP error: ' . $error_message);
					$last_error = new \WP_Error('http_request_failed', $error_message);
					continue;
				}
				
				// Parse response
				$data = json_decode($response, true);
				
				// Log response structure for Responses API debugging
				if (isset($request_body['tools']) && !empty($request_body['tools'])) {
					error_log('[AEBG] APIClient::makeRequest - Responses API response keys: ' . implode(', ', array_keys($data ?? [])));
					error_log('[AEBG] APIClient::makeRequest - Responses API response preview: ' . substr(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 0, 2000));
				}
				
				if (json_last_error() !== JSON_ERROR_NONE) {
					$error_message = 'Invalid JSON response: ' . json_last_error_msg();
					error_log('[AEBG] APIClient::makeRequest JSON error: ' . $error_message);
					
					// Retry on invalid JSON (might be a transient server issue)
					if ($retry_count < $max_retries) {
						$retry_count++;
						$delay = min(2 * $retry_count, 10);
						error_log('[AEBG] Invalid JSON response, retrying after ' . $delay . ' seconds (attempt ' . $retry_count . '/' . $max_retries . ')');
						sleep($delay);
						continue;
					}
					
					$last_error = new \WP_Error('json_decode_error', $error_message);
					continue;
				}
				
				// Check for API-level errors in response
				if (isset($data['error'])) {
					$error_message = $data['error']['message'] ?? 'Unknown API error';
					$error_code = $data['error']['code'] ?? 'api_error';
					error_log('[AEBG] APIClient::makeRequest API error: ' . $error_message . ' (code: ' . $error_code . ')');
					
					// Don't retry on certain error types (invalid request, not found, etc.)
					$non_retryable_codes = ['invalid_request_error', 'not_found', 'invalid_api_key', 'permission_denied'];
					if (in_array($error_code, $non_retryable_codes)) {
						$last_error = new \WP_Error($error_code, $error_message);
						break; // Exit retry loop immediately
					}
					
					$last_error = new \WP_Error('api_error', $error_message);
					continue;
				}
				
				// Success - clean up and return
				$buffered_output = ob_get_clean();
				if (!empty($buffered_output)) {
					error_log('[AEBG] ⚠️ WARNING: Output was generated during API request (length: ' . strlen($buffered_output) . ' bytes)');
					error_log('[AEBG] ⚠️ Buffered output preview: ' . substr($buffered_output, 0, 500));
				} else {
					ob_end_clean();
				}
				
				// Restore error reporting
				error_reporting($old_error_reporting);
				if ($old_display_errors !== false) {
					@ini_set('display_errors', $old_display_errors);
				}
				
				// Clear the API request flag
				unset($GLOBALS['AEBG_API_REQUEST_IN_PROGRESS']);
				
				// Restore PHP timeout if we modified it
				if ($php_timeout_modified && isset($original_php_timeout)) {
					if ($original_php_timeout == 0) {
						@set_time_limit(0); // Restore unlimited if it was unlimited
					} else {
						@set_time_limit($original_php_timeout);
					}
					error_log('[AEBG] APIClient::makeRequest: Restored PHP timeout to: ' . ($original_php_timeout == 0 ? 'unlimited' : $original_php_timeout . 's'));
				}
				
				return $data;
				
			} catch (\Exception $e) {
				error_log('[AEBG] APIClient::makeRequest Exception: ' . $e->getMessage());
				$last_error = new \WP_Error('api_request_exception', $e->getMessage());
			} finally {
				// Always clean up
				$buffered_output = ob_get_clean();
				if (!empty($buffered_output)) {
					error_log('[AEBG] ⚠️ WARNING: Output was generated during API request (length: ' . strlen($buffered_output) . ' bytes)');
				}
				
				// Restore error reporting
				error_reporting($old_error_reporting);
				if ($old_display_errors !== false) {
					@ini_set('display_errors', $old_display_errors);
				}
				
				// Clear the API request flag
				unset($GLOBALS['AEBG_API_REQUEST_IN_PROGRESS']);
				
				// Always restore PHP timeout if we modified it
				if ($php_timeout_modified && isset($original_php_timeout)) {
					if ($original_php_timeout == 0) {
						@set_time_limit(0); // Restore unlimited if it was unlimited
					} else {
						@set_time_limit($original_php_timeout);
					}
					error_log('[AEBG] APIClient::makeRequest: Restored PHP timeout to: ' . ($original_php_timeout == 0 ? 'unlimited' : $original_php_timeout . 's'));
				}
			}
		}
		
		// All retries exhausted
		if ($last_error) {
			return $last_error;
		}
		
		return new \WP_Error('max_retries_exceeded', 'Maximum retry attempts exceeded');
	}
	
	/**
	 * Get the appropriate API endpoint based on the model and whether web search is enabled.
	 *
	 * @param string $model The AI model name.
	 * @param bool $use_web_search Whether to use web search (uses Responses API).
	 * @return string The API endpoint.
	 */
	public static function getApiEndpoint($model, $use_web_search = false) {
		// If web search is enabled, use Responses API
		if ($use_web_search) {
			return 'https://api.openai.com/v1/responses';
		}
		
		// GPT models use the chat completions endpoint
		if (strpos($model, 'gpt-') === 0) {
			return 'https://api.openai.com/v1/chat/completions';
		}
		// Legacy models use the completions endpoint
		return 'https://api.openai.com/v1/completions';
	}
	
	/**
	 * Whether the model requires max_completion_tokens instead of max_tokens.
	 * GPT-5.x models (e.g. gpt-5.2, gpt-5-nano) use max_completion_tokens per OpenAI API.
	 *
	 * @param string $model The AI model name.
	 * @return bool True if model uses max_completion_tokens.
	 */
	public static function usesMaxCompletionTokens( $model ) {
		return ( strpos( $model, 'gpt-5' ) === 0 );
	}
	
	/**
	 * Return the completion limit parameter for the given model.
	 * GPT-5.x: max_completion_tokens; older models: max_tokens.
	 *
	 * @param string $model The AI model name.
	 * @param int    $max_tokens Maximum completion tokens.
	 * @return array One key: either 'max_completion_tokens' or 'max_tokens'.
	 */
	public static function getCompletionLimitParam( $model, $max_tokens ) {
		if ( self::usesMaxCompletionTokens( $model ) ) {
			return [ 'max_completion_tokens' => $max_tokens ];
		}
		return [ 'max_tokens' => $max_tokens ];
	}
	
	/**
	 * Build the request body based on the model type.
	 *
	 * @param string $model The AI model name.
	 * @param string $prompt The prompt.
	 * @param int $max_tokens Maximum tokens.
	 * @param float $temperature Temperature.
	 * @param bool $use_web_search Whether to use web search tool (for Responses API).
	 * @return array The request body.
	 */
	public static function buildRequestBody($model, $prompt, $max_tokens, $temperature, $use_web_search = false) {
		// If using web search, use Responses API format
		if ($use_web_search) {
			$body = [
				'model' => $model,
				'input' => $prompt,
				'tools' => [
					['type' => 'web_search']
				],
				'temperature' => $temperature,
			];
			// GPT-5.x use max_completion_tokens; Responses API may use max_output_tokens for older models
			$body = array_merge( $body, self::usesMaxCompletionTokens( $model ) ? [ 'max_completion_tokens' => $max_tokens ] : [ 'max_output_tokens' => $max_tokens ] );
			return $body;
		}
		// GPT models use the chat completions format
		if (strpos($model, 'gpt-') === 0) {
			$messages = [];
			
			// Add system message if enabled and not empty
			$settings = \AEBG\Admin\Settings::get_settings();
			$system_message = $settings['system_message'] ?? '';
			$system_message_enabled = $settings['system_message_enabled'] ?? true;
			
			if ($system_message_enabled && !empty($system_message)) {
				$system_content = $system_message;
				
				// Append negative phrases instruction
				$negative_phrases = $settings['negative_phrases'] ?? [];
				if (!empty($negative_phrases) && is_array($negative_phrases)) {
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
				self::getCompletionLimitParam( $model, $max_tokens )
			);
		}
		
		// Legacy models use the completions format
		return array_merge(
			[
				'model' => $model,
				'prompt' => $prompt,
				'temperature' => $temperature,
			],
			self::getCompletionLimitParam( $model, $max_tokens )
		);
	}
	
	/**
	 * Extract content from the API response based on model type and API type.
	 *
	 * @param array $data The API response data.
	 * @param string $model The AI model name.
	 * @param bool $use_web_search Whether web search was used (Responses API).
	 * @return string The extracted content.
	 */
	public static function extractContentFromResponse($data, $model, $use_web_search = false) {
		// Responses API returns content in nested structure
		if ($use_web_search) {
			// Actual Responses API structure (from logs):
			// $data['output'] = array of output items
			// $data['output'][0] = first output item (type: "message")
			// $data['output'][0]['content'] = array of content items
			// $data['output'][0]['content'][0] = first content item (type: "output_text")
			// $data['output'][0]['content'][0]['text'] = actual text content
			
			// Primary location: nested in output array
			if (isset($data['output']) && is_array($data['output']) && !empty($data['output'])) {
				// Find the message type output
				foreach ($data['output'] as $output_item) {
					if (isset($output_item['type']) && $output_item['type'] === 'message') {
						if (isset($output_item['content']) && is_array($output_item['content'])) {
							// Find the output_text type content
							foreach ($output_item['content'] as $content_item) {
								if (isset($content_item['type']) && $content_item['type'] === 'output_text') {
									if (isset($content_item['text']) && !empty($content_item['text'])) {
										return trim($content_item['text']);
									}
								}
							}
						}
					}
				}
			}
			
			// Fallback: Check for direct output_text field (if API format changes)
			if (isset($data['output_text']) && !empty($data['output_text'])) {
				return trim($data['output_text']);
			}
			
			// Fallback: Check for choices array (if API returns chat completions format)
			if (isset($data['choices']) && is_array($data['choices']) && !empty($data['choices'])) {
				$first_choice = $data['choices'][0];
				if (isset($first_choice['message']['content'])) {
					return trim($first_choice['message']['content']);
				}
				if (isset($first_choice['text'])) {
					return trim($first_choice['text']);
				}
			}
			
			// Log the structure for debugging if we can't find content
			error_log('[AEBG] APIClient::extractContentFromResponse - Could not find content in Responses API response');
			return ''; // Return empty if we can't find the content
		}
		
		// GPT models return content in choices[0].message.content
		if (strpos($model, 'gpt-') === 0) {
			return trim($data['choices'][0]['message']['content'] ?? '');
		}
		// Legacy models return content in choices[0].text
		return trim($data['choices'][0]['text'] ?? '');
	}
	
	/**
	 * Extract JSON content from markdown code blocks.
	 *
	 * @param string $content The content that may contain markdown code blocks.
	 * @return string The extracted JSON content.
	 */
	public static function extractJsonFromMarkdown($content) {
		// Remove markdown code block markers
		$content = preg_replace('/```json\s*/', '', $content);
		$content = preg_replace('/```\s*$/', '', $content);
		
		// Also handle cases where the language identifier might be different or missing
		$content = preg_replace('/```\s*(\w+)?\s*/', '', $content);
		$content = preg_replace('/```\s*$/', '', $content);
		
		// Trim whitespace
		$content = trim($content);
		
		return $content;
	}
	
	/**
	 * Check rate limits (placeholder for future implementation).
	 * Currently, rate limiting is handled automatically in makeRequest().
	 *
	 * @return void
	 */
	public static function rateLimitCheck() {
		// Rate limiting is handled automatically in makeRequest() via 429 response handling
		// This method exists for compatibility with code that references it
	}
}
