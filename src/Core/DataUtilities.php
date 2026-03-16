<?php

namespace AEBG\Core;

use AEBG\Core\Shortcodes;

/**
 * Data Utilities Class
 * Centralized data manipulation utilities.
 *
 * @package AEBG\Core
 */
class DataUtilities {
	/**
	 * Clean Elementor data before JSON encoding to prevent unescaped quotes issues
	 * OPTIMIZED: Added size limits, caching, and progressive processing to prevent delays.
	 *
	 * @param mixed $elementor_data The Elementor data to clean
	 * @param bool $fix_urls Whether to fix URLs in the data (default: true)
	 * @param int $depth Current recursion depth (internal use)
	 * @param float $start_time Processing start time (internal use)
	 * @return mixed The cleaned Elementor data
	 */
	public static function cleanElementorDataForEncoding($elementor_data, $fix_urls = true, $depth = 0, $start_time = null) {
		// Initialize start time on first call
		if ($start_time === null) {
			$start_time = microtime(true);
		}
		
		// Safety limits to prevent infinite loops and excessive processing
		$max_depth = 50; // Maximum recursion depth
		$max_processing_time = 10.0; // Maximum processing time in seconds (10 seconds)
		$max_array_size = 10000; // Maximum array size to process
		
		// Check processing time limit
		$elapsed = microtime(true) - $start_time;
		if ($elapsed > $max_processing_time) {
			error_log('[AEBG] WARNING: cleanElementorDataForEncoding exceeded time limit (' . round($elapsed, 2) . 's), returning data as-is to prevent timeout');
			return $elementor_data; // Return original data to prevent timeout
		}
		
		// Check depth limit
		if ($depth > $max_depth) {
			error_log('[AEBG] WARNING: cleanElementorDataForEncoding exceeded depth limit (' . $depth . '), returning data as-is');
			return $elementor_data; // Return original data to prevent infinite recursion
		}
		
		if (!is_array($elementor_data)) {
			return $elementor_data;
		}
		
		// Check array size limit
		if (count($elementor_data) > $max_array_size) {
			error_log('[AEBG] WARNING: cleanElementorDataForEncoding array too large (' . count($elementor_data) . ' items), processing first ' . $max_array_size . ' items only');
			$elementor_data = array_slice($elementor_data, 0, $max_array_size, true);
		}
		
		// First, fix any URLs in the data structure (only on first call, not recursively)
		if ($fix_urls) {
			$elementor_data = self::fixUrlsInElementorData($elementor_data, $start_time);
		}
		
		$cleaned = [];
		$processed_count = 0;
		foreach ($elementor_data as $key => $value) {
			// Check processing time periodically (every 100 items)
			if ($processed_count % 100 === 0 && $processed_count > 0) {
				$elapsed = microtime(true) - $start_time;
				if ($elapsed > $max_processing_time) {
					error_log('[AEBG] WARNING: cleanElementorDataForEncoding exceeded time limit during processing, stopping at item ' . $processed_count);
					// Add remaining items without processing
					foreach (array_slice($elementor_data, $processed_count, null, true) as $remaining_key => $remaining_value) {
						$cleaned[$remaining_key] = $remaining_value;
					}
					break;
				}
			}
			
			if (is_array($value)) {
				// Don't fix URLs recursively - they're already fixed
				$cleaned[$key] = self::cleanElementorDataForEncoding($value, false, $depth + 1, $start_time);
			} else if (is_string($value)) {
				// Clean string values that might contain unescaped quotes
				$cleaned[$key] = self::cleanStringForJson($value);
			} else {
				$cleaned[$key] = $value;
			}
			
			$processed_count++;
		}
		
		return $cleaned;
	}

	/**
	 * Recursively fix malformed URLs in Elementor data structure
	 * This ensures URLs are fixed before being saved to Elementor post meta
	 * OPTIMIZED: Added URL processing cache and time limits to prevent delays
	 *
	 * @param mixed $data The Elementor data to process
	 * @param float $start_time Processing start time (internal use)
	 * @param int $depth Current recursion depth (internal use)
	 * @return mixed The data with fixed URLs
	 */
	public static function fixUrlsInElementorData($data, $start_time = null, $depth = 0) {
		// Initialize start time on first call
		if ($start_time === null) {
			$start_time = microtime(true);
		}
		
		// Safety limits
		$max_depth = 50;
		$max_processing_time = 8.0; // 8 seconds max for URL processing (leaves 2s for other processing)
		$max_array_size = 5000;
		
		// Check time limit
		$elapsed = microtime(true) - $start_time;
		if ($elapsed > $max_processing_time) {
			error_log('[AEBG] WARNING: fixUrlsInElementorData exceeded time limit (' . round($elapsed, 2) . 's), skipping URL processing');
			return $data; // Return original data
		}
		
		// Check depth limit
		if ($depth > $max_depth) {
			return $data; // Return original data
		}
		
		// Initialize URL cache (static to persist across recursive calls)
		static $url_cache = [];
		
		// Initialize Shortcodes instance once (reuse across calls)
		static $shortcodes_instance = null;
		if ($shortcodes_instance === null) {
			$shortcodes_instance = new Shortcodes();
		}
		
		if (!is_array($data)) {
			// If it's a string and looks like a URL, process affiliate link
			// CRITICAL: Process ALL URLs that contain @@@ placeholder, regardless of domain
			if (is_string($data)) {
				// Check cache first
				if (isset($url_cache[$data])) {
					return $url_cache[$data];
				}
				
				$needs_processing = false;
				if (strpos($data, '@@@') !== false) {
					$needs_processing = true;
				} elseif (
					preg_match('/^https?:\/\//', $data) || 
					preg_match('/^(s:\/\/|s\/\/|http:\/\/s:\/\/|https:\/\/s:\/\/|http:\/\/s\/\/|https:\/\/s\/\/)/', $data) ||
					filter_var($data, FILTER_VALIDATE_URL) !== false ||
					strpos($data, 'partner-ads.com') !== false ||
					strpos($data, 'partnerid=') !== false
				) {
					$needs_processing = true;
				}
				
				if ($needs_processing) {
					$processed = $shortcodes_instance->process_affiliate_link($data);
					// Cache the result (limit cache size to prevent memory issues)
					if (count($url_cache) < 1000) {
						$url_cache[$data] = $processed;
					}
					return $processed;
				}
			}
			return $data;
		}
		
		// Check array size limit
		if (count($data) > $max_array_size) {
			error_log('[AEBG] WARNING: fixUrlsInElementorData array too large (' . count($data) . ' items), processing first ' . $max_array_size . ' items only');
			$data = array_slice($data, 0, $max_array_size, true);
		}
		
		$fixed = [];
		$processed_count = 0;
		foreach ($data as $key => $value) {
			// Check processing time periodically
			if ($processed_count % 50 === 0 && $processed_count > 0) {
				$elapsed = microtime(true) - $start_time;
				if ($elapsed > $max_processing_time) {
					error_log('[AEBG] WARNING: fixUrlsInElementorData exceeded time limit during processing, stopping at item ' . $processed_count);
					// Add remaining items without processing
					foreach (array_slice($data, $processed_count, null, true) as $remaining_key => $remaining_value) {
						$fixed[$remaining_key] = $remaining_value;
					}
					break;
				}
			}
			
			// Check for common URL field names in Elementor
			if (($key === 'url' || $key === 'href') && is_string($value)) {
				// Check cache first
				if (isset($url_cache[$value])) {
					$fixed[$key] = $url_cache[$value];
				} else {
					// CRITICAL: Process ALL URLs, especially those with @@@ placeholder
					$processed = $shortcodes_instance->process_affiliate_link($value);
					$fixed[$key] = $processed;
					// Cache the result
					if (count($url_cache) < 1000) {
						$url_cache[$value] = $processed;
					}
				}
			} elseif (is_array($value) && isset($value['url']) && is_string($value['url'])) {
				// This is a nested URL structure (like link.url or image.url)
				// CRITICAL: Always process nested URLs, especially those with @@@ placeholder
				$fixed_value = $value;
				// Check cache first
				if (isset($url_cache[$value['url']])) {
					$fixed_value['url'] = $url_cache[$value['url']];
				} else {
					$processed = $shortcodes_instance->process_affiliate_link($value['url']);
					$fixed_value['url'] = $processed;
					// Cache the result
					if (count($url_cache) < 1000) {
						$url_cache[$value['url']] = $processed;
					}
				}
				$fixed[$key] = self::fixUrlsInElementorData($fixed_value, $start_time, $depth + 1);
			} else {
				// Recursively process nested arrays
				$fixed[$key] = self::fixUrlsInElementorData($value, $start_time, $depth + 1);
			}
			
			$processed_count++;
		}
		
		return $fixed;
	}

	/**
	 * Clean a string value for JSON encoding
	 *
	 * @param string $string The string to clean
	 * @return string The cleaned string
	 */
	public static function cleanStringForJson($string) {
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

	/**
	 * Create a deep copy of an array
	 *
	 * @param array $array The array to copy.
	 * @return array The deep copy of the array.
	 */
	public static function deepCopyArray($array) {
		if (!is_array($array)) {
			return $array;
		}
		
		$copy = [];
		foreach ($array as $key => $value) {
			if (is_array($value)) {
				$copy[$key] = self::deepCopyArray($value);
			} else {
				$copy[$key] = $value;
			}
		}
		
		return $copy;
	}

	/**
	 * Safe JSON encode with error handling
	 *
	 * @param mixed $data The data to encode
	 * @return string|\WP_Error JSON string or WP_Error on failure
	 */
	public static function safeJsonEncode($data) {
		// Validate input type
		if (!is_array($data) && !is_string($data) && !is_object($data)) {
			error_log('[AEBG] safe_json_encode: Invalid data type: ' . gettype($data));
			return new \WP_Error('invalid_data_type', 'Data must be array, string, or object, got: ' . gettype($data));
		}
		
		// Encode to JSON
		$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		
		// Check if encoding failed
		if ($json === false) {
			$json_error = json_last_error_msg();
			error_log('[AEBG] safe_json_encode: json_encode failed: ' . $json_error);
			return new \WP_Error('json_encode_failed', 'Failed to encode data to JSON: ' . $json_error);
		}
		
		return $json;
	}

	/**
	 * Decode JSON with Unicode support
	 *
	 * @param string $json_string The JSON string to decode
	 * @return mixed|false Decoded data or false on failure
	 */
	public static function decodeJsonWithUnicode($json_string) {
		if (!is_string($json_string)) {
			return false;
		}
		
		// Simply use standard json_decode - it handles Unicode escape sequences automatically
		$decoded = json_decode($json_string, true);
		if (json_last_error() === JSON_ERROR_NONE) {
			return $decoded;
		}
		
		error_log('[AEBG] JSON decode failed: ' . json_last_error_msg());
		return false;
	}

	/**
	 * Helper method to decode all Unicode escape sequences in a string
	 *
	 * @param string $string The string containing Unicode escape sequences
	 * @return string The string with decoded Unicode sequences
	 */
	public static function decodeUnicodeSequences($string) {
		// Use a comprehensive approach to decode all Unicode escape sequences
		return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($matches) {
			$hex = $matches[1];
			$dec = hexdec($hex);
			
			// Handle UTF-8 encoding properly
			if ($dec < 128) {
				// ASCII character
				return chr($dec);
			} elseif ($dec < 2048) {
				// 2-byte UTF-8
				return chr(192 | ($dec >> 6)) . chr(128 | ($dec & 63));
			} elseif ($dec < 65536) {
				// 3-byte UTF-8
				return chr(224 | ($dec >> 12)) . chr(128 | (($dec >> 6) & 63)) . chr(128 | ($dec & 63));
			} else {
				// 4-byte UTF-8 (though this shouldn't happen with 4-digit hex)
				return chr(240 | ($dec >> 18)) . chr(128 | (($dec >> 12) & 63)) . chr(128 | (($dec >> 6) & 63)) . chr(128 | ($dec & 63));
			}
		}, $string);
	}

	/**
	 * Create a deep copy of Elementor data using JSON encode/decode
	 * More efficient than recursive copying for large structures
	 *
	 * @param mixed $data The Elementor data to copy
	 * @return mixed The deep copy of the data
	 */
	public static function deepCopyElementorData($data) {
		if (!is_array($data)) {
			return $data;
		}
		
		// Use JSON encode/decode for a true deep copy
		$json_encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json_encoded === false) {
			error_log('[AEBG] Failed to encode data for deep copy: ' . json_last_error_msg());
			return $data;
		}
		
		$deep_copy = json_decode($json_encoded, true);
		if ($deep_copy === null) {
			error_log('[AEBG] Failed to decode data for deep copy: ' . json_last_error_msg());
			return $data;
		}
		
		return $deep_copy;
	}

	/**
	 * Validate data structure against a schema
	 *
	 * @param mixed $data The data to validate
	 * @param array $schema The schema to validate against
	 * @return bool|\WP_Error True if valid, WP_Error if invalid
	 */
	public static function validateDataStructure($data, $schema) {
		if (!is_array($schema)) {
			return new \WP_Error('invalid_schema', 'Schema must be an array');
		}

		// Basic validation - can be extended
		if (isset($schema['required']) && is_array($schema['required'])) {
			foreach ($schema['required'] as $field) {
				if (!isset($data[$field])) {
					return new \WP_Error('missing_field', 'Required field missing: ' . $field);
				}
			}
		}

		if (isset($schema['type']) && gettype($data) !== $schema['type']) {
			return new \WP_Error('type_mismatch', 'Data type mismatch. Expected: ' . $schema['type'] . ', Got: ' . gettype($data));
		}

		return true;
	}

	/**
	 * Optimize data structure by removing unnecessary data
	 *
	 * @param array $data The data to optimize
	 * @return array The optimized data
	 */
	public static function optimizeDataStructure($data) {
		if (!is_array($data)) {
			return $data;
		}

		$optimized = [];
		foreach ($data as $key => $value) {
			// Skip empty values
			if ($value === null || $value === '' || (is_array($value) && empty($value))) {
				continue;
			}

			// Recursively optimize arrays
			if (is_array($value)) {
				$value = self::optimizeDataStructure($value);
				if (!empty($value)) {
					$optimized[$key] = $value;
				}
			} else {
				$optimized[$key] = $value;
			}
		}

		return $optimized;
	}
}

