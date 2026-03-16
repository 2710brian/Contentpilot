<?php

namespace AEBG\Core;

use AEBG\Core\ContentGenerator;
use AEBG\Core\Logger;
use AEBG\Core\SchemaValidator;
use AEBG\Core\SchemaEnhancer;
use AEBG\Core\SchemaFormatter;

/**
 * Schema Generator Class
 * Handles AI-powered Schema.org JSON-LD structured data generation.
 *
 * @package AEBG\Core
 */
class SchemaGenerator {
	/**
	 * Generate and save structured data (Schema.org JSON-LD) for a post.
	 *
	 * @param int $post_id Post ID.
	 * @param string $title Post title.
	 * @param string $content Post content.
	 * @param array $products Array of products.
	 * @param array $settings Settings array.
	 * @param string $api_key OpenAI API key.
	 * @param string $ai_model AI model to use.
	 * @return array|false Schema JSON-LD array on success, false on failure.
	 */
	public static function generateAndSave(int $post_id, string $title, string $content, array $products, array $settings, string $api_key, string $ai_model): array|false {
		try {
			// Check setting
			if (empty($settings['include_schema']) || !$settings['include_schema']) {
				Logger::debug('Schema generation skipped - setting disabled', ['post_id' => $post_id]);
				return false;
			}
			
			// Validate API key
			if (empty($api_key)) {
				Logger::error('Schema generation failed - no API key', ['post_id' => $post_id]);
				return false;
			}
			
			// Build prompt
			$prompt = self::buildSchemaPrompt($title, $content, $products);
			
			// Generate schema using ContentGenerator
			// Use higher max_tokens (6000) for schema generation to handle large JSON with many products
			$content_generator = new ContentGenerator();
			$generated_schema = $content_generator->generateContentWithPrompt(
				$title,
				$products,
				[],
				$api_key,
				$ai_model,
				$prompt,
				'schema',
				6000 // Higher max_tokens for schema JSON (can be large with many products)
			);
			
			if (!$generated_schema || is_wp_error($generated_schema)) {
				Logger::warning('Schema generation failed', [
					'post_id' => $post_id,
					'error' => is_wp_error($generated_schema) ? $generated_schema->get_error_message() : 'Unknown error'
				]);
				return false;
			}
			
			// Parse schema
			$schema_data = self::parseSchema($generated_schema, $post_id, $title, $content, $products);
			
			if (empty($schema_data) || !is_array($schema_data)) {
				Logger::warning('Schema parsing failed or returned invalid data', ['post_id' => $post_id, 'raw' => $generated_schema]);
				// Try to generate fallback minimal schema
				$schema_data = self::generateFallbackSchema($post_id, $title, $content, $products);
				if (!$schema_data) {
					return false;
				}
			}
			
			// Enhance schema with required properties
			$schema_data = SchemaEnhancer::enhance($schema_data, $post_id, $title, $content, $products);
			
			// Validate schema
			$is_valid = SchemaValidator::validate($schema_data, $post_id);
			if (!$is_valid) {
				$errors = SchemaValidator::get_errors();
				Logger::warning('Schema validation failed', [
					'post_id' => $post_id,
					'errors' => $errors
				]);
				// Continue anyway - enhanced schema should be better than nothing
			}
			
			// Save schema
			$result = update_post_meta($post_id, '_aebg_schema_json', json_encode($schema_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			
			if (!$result) {
				Logger::warning('Failed to save schema to post meta', ['post_id' => $post_id]);
				return false;
			}
			
			Logger::info('Schema generated and saved successfully', [
				'post_id' => $post_id,
				'schema_type' => $schema_data['@type'] ?? 'unknown'
			]);
			
			return $schema_data;
		} catch (\Exception $e) {
			Logger::error('Exception in schema generation', [
				'post_id' => $post_id,
				'error' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine()
			]);
			return false;
		}
	}
	
	/**
	 * Build prompt for schema generation.
	 *
	 * @param string $title Post title.
	 * @param string $content Post content.
	 * @param array $products Array of products.
	 * @return string Prompt.
	 */
	private static function buildSchemaPrompt(string $title, string $content, array $products): string {
		// Use more content for better context (up to 500 words)
		$content_excerpt = wp_trim_words($content, 500, '...');
		
		$product_list = [];
		if (!empty($products) && is_array($products)) {
			foreach ($products as $index => $product) {
				$product_list[] = [
					'name' => $product['name'] ?? $product['title'] ?? 'Product ' . ($index + 1),
					'price' => $product['price'] ?? 0,
					'currency' => $product['currency'] ?? 'USD',
					'brand' => $product['brand'] ?? '',
					'image' => $product['image'] ?? $product['image_url'] ?? '',
					'description' => $product['description'] ?? '',
					'url' => $product['url'] ?? $product['affiliate_url'] ?? '',
				];
			}
		}
		$product_list_json = json_encode($product_list, JSON_PRETTY_PRINT);
		
		return "You are a Schema.org structured data expert. Generate valid Schema.org JSON-LD markup for this blog post following Google's structured data guidelines.

Post Title: {$title}
Content: {$content_excerpt}
Products: {$product_list_json}

REQUIREMENTS:
1. Follow Schema.org vocabulary (https://schema.org)
2. Use JSON-LD format (not Microdata or RDFa)
3. All dates must be in ISO 8601 format (YYYY-MM-DDTHH:MM:SS+00:00)
4. Prices must be strings (e.g., \"29.99\" not 29.99)
5. URLs must be absolute and valid
6. Images must be absolute URLs

Return a SINGLE JSON object with this structure:
{
  \"@context\": \"https://schema.org\",
  \"@type\": \"Article\",
  \"headline\": \"[Article headline matching the post title]\",
  \"description\": \"[SEO-friendly description, 150-160 characters]\",
  \"author\": {
    \"@type\": \"Person\",
    \"name\": \"[Author name]\"
  },
  \"datePublished\": \"[ISO 8601 date]\",
  \"dateModified\": \"[ISO 8601 date]\",
  \"publisher\": {
    \"@type\": \"Organization\",
    \"name\": \"[Site name]\",
    \"logo\": {
      \"@type\": \"ImageObject\",
      \"url\": \"[Site logo URL]\"
    }
  },
  \"image\": \"[Featured image URL]\",
  \"mainEntityOfPage\": {
    \"@type\": \"WebPage\",
    \"@id\": \"[Post URL]\"
  },
  \"mainEntity\": {
    \"@type\": \"ItemList\",
    \"itemListElement\": [
      {
        \"@type\": \"ListItem\",
        \"position\": 1,
        \"item\": {
          \"@type\": \"Product\",
          \"name\": \"[Product name]\",
          \"description\": \"[Product description]\",
          \"brand\": {
            \"@type\": \"Brand\",
            \"name\": \"[Brand name]\"
          },
          \"image\": \"[Product image URL]\",
          \"offers\": {
            \"@type\": \"Offer\",
            \"price\": \"[Price as string]\",
            \"priceCurrency\": \"[ISO 4217 currency code]\",
            \"availability\": \"https://schema.org/InStock\",
            \"url\": \"[Product URL if available]\"
          }
        }
      }
    ]
  }
}

CRITICAL RULES:
- Return ONLY valid JSON, no markdown code blocks, no explanations
- All Product schemas must be inside mainEntity.itemListElement array as ListItem objects
- Each ListItem must have a position number (1, 2, 3, etc.)
- Product must be nested inside ListItem.item property
- Include ALL required properties for Article and Product schemas
- Use exact property names from Schema.org (case-sensitive)
- Ensure all values match the content provided

Return only the JSON object, nothing else.";
	}
	
	/**
	 * Parse and validate schema from AI response.
	 *
	 * @param string $response AI response.
	 * @param int $post_id Post ID.
	 * @param string $title Post title.
	 * @param string $content Post content.
	 * @param array $products Array of products.
	 * @return array|false Schema array on success, false on failure.
	 */
	private static function parseSchema(string $response, int $post_id, string $title, string $content, array $products): array|false {
		// Try to extract JSON from response
		$json_start = strpos($response, '{');
		$json_end = strrpos($response, '}');
		
		if ($json_start === false || $json_end === false) {
			Logger::warning('No JSON found in schema response', ['post_id' => $post_id, 'response' => substr($response, 0, 500)]);
			return false;
		}
		
		$json_string = substr($response, $json_start, $json_end - $json_start + 1);
		
		// CRITICAL: Fix common JSON issues - remove code block markers if present
		$json_string = preg_replace('/^```json\s*/i', '', $json_string);
		$json_string = preg_replace('/\s*```\s*$/', '', $json_string);
		$json_string = trim($json_string);
		
		// Check if JSON appears truncated (common signs: incomplete strings, missing closing braces)
		$is_truncated = self::isJsonTruncated($json_string);
		if ($is_truncated) {
			Logger::warning('Schema JSON appears truncated - response may have exceeded max_tokens', [
				'post_id' => $post_id,
				'json_length' => strlen($json_string),
				'json_preview' => substr($json_string, max(0, strlen($json_string) - 200))
			]);
		}
		
		// Try to decode JSON
		$schema_data = json_decode($json_string, true);
		
		if (json_last_error() !== JSON_ERROR_NONE) {
			// If JSON is truncated, try to fix it by closing incomplete structures
			if ($is_truncated) {
				$fixed_json = self::fixTruncatedJson($json_string);
				if ($fixed_json) {
					$schema_data = json_decode($fixed_json, true);
					if (json_last_error() === JSON_ERROR_NONE) {
						Logger::info('Fixed truncated JSON in schema', ['post_id' => $post_id]);
					}
				}
			}
			
			// If still failing, try to fix invalid JSON structure where products are top-level objects
			if (json_last_error() !== JSON_ERROR_NONE) {
				$fixed_json = self::fixInvalidJsonStructure($json_string);
				if ($fixed_json) {
					$schema_data = json_decode($fixed_json, true);
					if (json_last_error() !== JSON_ERROR_NONE) {
						Logger::warning('JSON decode error in schema (after fix attempt)', [
							'post_id' => $post_id,
							'error' => json_last_error_msg(),
							'json' => substr($fixed_json, 0, 500)
						]);
						return false;
					}
					Logger::info('Fixed invalid JSON structure in schema', ['post_id' => $post_id]);
				} else {
					Logger::warning('JSON decode error in schema', [
						'post_id' => $post_id,
						'error' => json_last_error_msg(),
						'json' => substr($json_string, 0, 500),
						'is_truncated' => $is_truncated
					]);
					return false;
				}
			}
		}
		
		// Ensure required fields
		if (!isset($schema_data['@context'])) {
			$schema_data['@context'] = 'https://schema.org';
		}
		
		if (!isset($schema_data['@type'])) {
			$schema_data['@type'] = 'Article';
		}
		
		// Format existing fields using SchemaFormatter
		if (isset($schema_data['headline'])) {
			$schema_data['headline'] = SchemaFormatter::format_text($schema_data['headline'], 110);
		}
		
		if (isset($schema_data['description'])) {
			$schema_data['description'] = SchemaFormatter::format_text($schema_data['description'], 160);
		}
		
		// Format dates
		if (isset($schema_data['datePublished'])) {
			$schema_data['datePublished'] = SchemaFormatter::format_date($schema_data['datePublished']);
		}
		
		if (isset($schema_data['dateModified'])) {
			$schema_data['dateModified'] = SchemaFormatter::format_date($schema_data['dateModified']);
		}
		
		// Format image URL if present
		if (isset($schema_data['image'])) {
			$formatted_image = SchemaFormatter::format_image($schema_data['image']);
			if ($formatted_image) {
				$schema_data['image'] = $formatted_image;
			} else {
				unset($schema_data['image']);
			}
		}
		
		// Format product offers prices
		if (isset($schema_data['mainEntity']['itemListElement']) && is_array($schema_data['mainEntity']['itemListElement'])) {
			foreach ($schema_data['mainEntity']['itemListElement'] as &$item) {
				if (isset($item['offers']['price'])) {
					$item['offers']['price'] = SchemaFormatter::format_price($item['offers']['price']);
				}
				if (isset($item['offers']['priceCurrency'])) {
					$item['offers']['priceCurrency'] = SchemaFormatter::format_currency($item['offers']['priceCurrency']);
				}
			}
			unset($item);
		}
		
		return $schema_data;
	}
	
	/**
	 * Check if JSON string appears to be truncated.
	 *
	 * @param string $json_string JSON string to check.
	 * @return bool True if JSON appears truncated.
	 */
	private static function isJsonTruncated(string $json_string): bool {
		// Check for common signs of truncation:
		// 1. Incomplete string (unclosed quotes)
		// 2. Incomplete property name (ends with "@typ" instead of "@type")
		// 3. Missing closing braces/brackets
		// 4. Ends with incomplete value
		
		$trimmed = trim($json_string);
		
		// Check if ends with incomplete property name (like "@typ" instead of "@type")
		if (preg_match('/"@typ"?\s*$/', $trimmed)) {
			return true;
		}
		
		// Check for unclosed strings (odd number of unescaped quotes at the end)
		$last_quote_pos = strrpos($trimmed, '"');
		if ($last_quote_pos !== false) {
			$after_last_quote = substr($trimmed, $last_quote_pos + 1);
			// If there's content after the last quote that's not whitespace or closing chars, it might be truncated
			if (!empty($after_last_quote) && !preg_match('/^\s*[}\]]/', $after_last_quote)) {
				// Count unescaped quotes before the last one
				$before_last_quote = substr($trimmed, 0, $last_quote_pos);
				$unescaped_quotes = preg_match_all('/(?<!\\\\)"/', $before_last_quote);
				if ($unescaped_quotes % 2 === 0) {
					// Even number of quotes before last quote means last quote opens a string that's not closed
					return true;
				}
			}
		}
		
		// Check brace/bracket balance
		$open_braces = substr_count($trimmed, '{');
		$close_braces = substr_count($trimmed, '}');
		$open_brackets = substr_count($trimmed, '[');
		$close_brackets = substr_count($trimmed, ']');
		
		if ($open_braces > $close_braces || $open_brackets > $close_brackets) {
			return true;
		}
		
		return false;
	}
	
	/**
	 * Attempt to fix truncated JSON by closing incomplete structures.
	 *
	 * @param string $json_string Truncated JSON string.
	 * @return string|false Fixed JSON string on success, false on failure.
	 */
	private static function fixTruncatedJson(string $json_string): string|false {
		$trimmed = trim($json_string);
		
		// If it ends with an incomplete property name like "@typ", remove it
		$trimmed = preg_replace('/"@typ"?\s*$/', '', $trimmed);
		
		// If it ends with an incomplete string, try to close it
		// Find the last unclosed string and close it
		$last_quote_pos = strrpos($trimmed, '"');
		if ($last_quote_pos !== false) {
			$after_last_quote = substr($trimmed, $last_quote_pos + 1);
			$before_last_quote = substr($trimmed, 0, $last_quote_pos);
			
			// Count unescaped quotes before the last one
			$unescaped_quotes = preg_match_all('/(?<!\\\\)"/', $before_last_quote);
			
			if ($unescaped_quotes % 2 === 0 && !empty($after_last_quote) && !preg_match('/^\s*[}\]:,]/', $after_last_quote)) {
				// String is not closed, close it
				$trimmed = $before_last_quote . '"' . $after_last_quote;
			}
		}
		
		// Close any unclosed braces/brackets
		$open_braces = substr_count($trimmed, '{');
		$close_braces = substr_count($trimmed, '}');
		$open_brackets = substr_count($trimmed, '[');
		$close_brackets = substr_count($trimmed, ']');
		
		// Add missing closing brackets first (they're nested inside braces)
		for ($i = 0; $i < ($open_brackets - $close_brackets); $i++) {
			$trimmed .= ']';
		}
		
		// Add missing closing braces
		for ($i = 0; $i < ($open_braces - $close_braces); $i++) {
			$trimmed .= '}';
		}
		
		// Try to decode the fixed JSON
		$decoded = json_decode($trimmed, true);
		if (json_last_error() === JSON_ERROR_NONE) {
			return $trimmed;
		}
		
		return false;
	}
	
	/**
	 * Fix invalid JSON structure where products are incorrectly placed as top-level objects.
	 *
	 * @param string $json_string Invalid JSON string.
	 * @return string|false Fixed JSON string on success, false on failure.
	 */
	private static function fixInvalidJsonStructure(string $json_string): string|false {
		// Try to extract the first valid JSON object (the Article)
		$depth = 0;
		$start = -1;
		$end = -1;
		
		for ($i = 0; $i < strlen($json_string); $i++) {
			$char = $json_string[$i];
			
			if ($char === '{') {
				if ($depth === 0) {
					$start = $i;
				}
				$depth++;
			} elseif ($char === '}') {
				$depth--;
				if ($depth === 0 && $start !== -1) {
					$end = $i;
					break;
				}
			}
		}
		
		if ($start !== -1 && $end !== -1) {
			$first_object = substr($json_string, $start, $end - $start + 1);
			$decoded = json_decode($first_object, true);
			
			if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
				// Found valid first object - return it
				return $first_object;
			}
		}
		
		return false;
	}
	
	/**
	 * Generate fallback minimal schema if AI generation fails.
	 *
	 * @param int $post_id Post ID.
	 * @param string $title Post title.
	 * @param string $content Post content.
	 * @param array $products Array of products.
	 * @return array|false Fallback schema array on success, false on failure.
	 */
	private static function generateFallbackSchema(int $post_id, string $title, string $content, array $products): array|false {
		try {
			$post = get_post($post_id);
			if (!$post) {
				return false;
			}
			
			// Create minimal valid Article schema
			$schema_data = [
				'@context' => 'https://schema.org',
				'@type' => 'Article',
				'headline' => SchemaFormatter::format_text($title, 110),
				'description' => SchemaFormatter::format_text($content, 160),
				'datePublished' => SchemaFormatter::format_date($post->post_date),
				'dateModified' => SchemaFormatter::format_date($post->post_modified),
			];
			
			// Add author
			$author_id = $post->post_author ?? 0;
			$author_name = get_the_author_meta('display_name', $author_id);
			if ($author_name) {
				$schema_data['author'] = [
					'@type' => 'Person',
					'name' => SchemaFormatter::format_text($author_name, 100)
				];
			}
			
			// Add featured image
			$featured_image_id = get_post_thumbnail_id($post_id);
			if ($featured_image_id) {
				$image_url = SchemaFormatter::format_image($featured_image_id);
				if ($image_url) {
					$schema_data['image'] = $image_url;
				}
			}
			
			Logger::info('Generated fallback schema', ['post_id' => $post_id]);
			
			return $schema_data;
		} catch (\Exception $e) {
			Logger::error('Failed to generate fallback schema', [
				'post_id' => $post_id,
				'error' => $e->getMessage()
			]);
			return false;
		}
	}
}

