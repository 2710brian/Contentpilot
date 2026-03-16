<?php

namespace AEBG\Core;

use AEBG\Core\ContentGenerator;
use AEBG\Core\Logger;

/**
 * Category Generator Class
 * Handles AI-powered category generation and assignment for posts.
 *
 * @package AEBG\Core
 */
class CategoryGenerator {
	/**
	 * Generate and assign categories to a post.
	 *
	 * @param int $post_id Post ID.
	 * @param string $title Post title.
	 * @param string $content Post content.
	 * @param array $products Array of products.
	 * @param array $context Context data.
	 * @param array $settings Settings array.
	 * @param string $api_key OpenAI API key.
	 * @param string $ai_model AI model to use.
	 * @return array|false Array of assigned category IDs on success, false on failure.
	 */
	public static function generateAndAssign(int $post_id, string $title, string $content, array $products, array $context, array $settings, string $api_key, string $ai_model): array|false {
		try {
			// Check setting
			if (empty($settings['auto_categories']) || !$settings['auto_categories']) {
				Logger::debug('Category generation skipped - setting disabled', ['post_id' => $post_id]);
				return false;
			}
			
			// Validate API key
			if (empty($api_key)) {
				Logger::error('Category generation failed - no API key', ['post_id' => $post_id]);
				return false;
			}
			
			// Get existing categories
			$existing_categories = self::getExistingCategories();
			
			// CRITICAL: If no existing categories, skip category assignment
			if (empty($existing_categories)) {
				Logger::warning('No existing categories found in WordPress - cannot assign categories', ['post_id' => $post_id]);
				return false;
			}
			
			// Build prompt
			$prompt = self::buildCategoryPrompt($title, $content, $products, $existing_categories);
			
			// Generate categories using ContentGenerator
			$content_generator = new ContentGenerator();
			$generated_categories = $content_generator->generateContentWithPrompt(
				$title,
				$products,
				$context,
				$api_key,
				$ai_model,
				$prompt,
				'categories'
			);
			
			if (!$generated_categories || is_wp_error($generated_categories)) {
				Logger::warning('Category generation failed', [
					'post_id' => $post_id,
					'error' => is_wp_error($generated_categories) ? $generated_categories->get_error_message() : 'Unknown error'
				]);
				return false;
			}
			
			// Parse categories from response
			$category_names = self::parseCategories($generated_categories);
			
			if (empty($category_names)) {
				Logger::warning('No categories parsed from AI response', ['post_id' => $post_id, 'response' => $generated_categories]);
				return false;
			}
			
			// Match categories against existing ones (no creation)
			$category_ids = self::matchOrCreateCategories($category_names, $existing_categories);
			
			if (empty($category_ids)) {
				Logger::warning('No matching categories found', [
					'post_id' => $post_id,
					'category_names' => $category_names,
					'existing_categories_count' => count($existing_categories)
				]);
				// Return false to indicate no categories were assigned (but don't treat as error)
				return false;
			}
			
			// Assign categories to post
			$result = wp_set_object_terms($post_id, $category_ids, 'category', false); // false = append to existing
			
			if (is_wp_error($result)) {
				Logger::error('Failed to assign categories', [
					'post_id' => $post_id,
					'error' => $result->get_error_message(),
					'category_ids' => $category_ids
				]);
				return false;
			}
			
			// CRITICAL: Invalidate caches after category assignment
			clean_post_cache($post_id);
			wp_cache_delete($post_id, 'post_meta');
			if (function_exists('delete_object_term_cache')) {
				\delete_object_term_cache($post_id, 'category');
			}
			
			Logger::info('Categories generated and assigned successfully', [
				'post_id' => $post_id,
				'category_count' => count($category_ids),
				'category_ids' => $category_ids
			]);
			
			return $category_ids;
		} catch (\Exception $e) {
			Logger::error('Exception in category generation', [
				'post_id' => $post_id,
				'error' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine()
			]);
			return false;
		}
	}
	
	/**
	 * Get existing WordPress categories.
	 *
	 * @return array Array of category names.
	 */
	private static function getExistingCategories(): array {
		$categories = get_categories(['hide_empty' => false]);
		$category_names = [];
		foreach ($categories as $category) {
			$category_names[] = $category->name;
		}
		return $category_names;
	}
	
	/**
	 * Build prompt for category generation.
	 *
	 * @param string $title Post title.
	 * @param string $content Post content.
	 * @param array $products Array of products.
	 * @param array $existing_categories Array of existing category names.
	 * @return string Prompt.
	 */
	private static function buildCategoryPrompt(string $title, string $content, array $products, array $existing_categories): string {
		$content_excerpt = wp_trim_words($content, 50, '...');
		
		$product_names = [];
		if (!empty($products) && is_array($products)) {
			foreach ($products as $product) {
				$product_names[] = $product['name'] ?? $product['title'] ?? '';
			}
		}
		$product_names_text = !empty($product_names) ? implode(', ', array_filter($product_names)) : 'None';
		
		// Include all existing categories (or up to 50 if there are many)
		$existing_categories_text = !empty($existing_categories) ? implode(', ', array_slice($existing_categories, 0, 50)) : 'None';
		if (count($existing_categories) > 50) {
			$existing_categories_text .= ' (and ' . (count($existing_categories) - 50) . ' more)';
		}
		
		return "Based on the following blog post, select 1-3 relevant WordPress categories from the existing categories list.

Post Title: {$title}
Content Excerpt: {$content_excerpt}
Products Featured: {$product_names_text}

Existing Categories: {$existing_categories_text}

CRITICAL REQUIREMENTS:
- You MUST ONLY select from the existing categories list above
- Do NOT suggest new category names
- Return only existing category names that best match the post content
- Return category names exactly as they appear in the existing categories list
- Maximum 3 categories
- If no existing category is a good match, return fewer categories or return 'None'

Return format: category1, category2, category3 (or 'None' if no good match)";
	}
	
	/**
	 * Parse categories from AI response.
	 *
	 * @param string $response AI response.
	 * @return array Array of category names.
	 */
	private static function parseCategories(string $response): array {
		// Clean the response
		$response = trim($response);
		
		// Remove quotes if present
		$response = trim($response, '"\'');
		
		// Split by comma
		$categories = explode(',', $response);
		
		// Clean each category
		$cleaned_categories = [];
		foreach ($categories as $category) {
			$category = trim($category);
			// Keep original case for category names
			$category = trim($category);
			
			// Skip empty categories
			if (!empty($category) && strlen($category) <= 100) {
				$cleaned_categories[] = $category;
			}
		}
		
		// Limit to 3 categories
		$cleaned_categories = array_slice($cleaned_categories, 0, 3);
		
		return array_unique($cleaned_categories);
	}
	
	/**
	 * Match categories against existing categories only (no creation).
	 * Uses fuzzy matching to find best matches.
	 *
	 * @param array $category_names Array of category names from AI.
	 * @param array $existing_categories Array of existing category names.
	 * @return array Array of category IDs (only matched categories).
	 */
	private static function matchOrCreateCategories(array $category_names, array $existing_categories): array {
		$category_ids = [];
		
		// Build a map of existing categories for faster lookup
		$existing_map = [];
		foreach ($existing_categories as $existing_name) {
			$term = get_term_by('name', $existing_name, 'category');
			if ($term && !is_wp_error($term)) {
				$existing_map[strtolower($existing_name)] = [
					'name' => $existing_name,
					'term_id' => $term->term_id
				];
			}
		}
		
		foreach ($category_names as $category_name) {
			// Skip if AI returned 'None' or empty
			if (empty($category_name) || strtolower(trim($category_name)) === 'none') {
				continue;
			}
			
			$category_name_clean = trim($category_name);
			$category_name_lower = strtolower($category_name_clean);
			
			$matched_term_id = null;
			$best_match_score = 0;
			
			// 1. Try exact match (case-insensitive)
			if (isset($existing_map[$category_name_lower])) {
				$matched_term_id = $existing_map[$category_name_lower]['term_id'];
				Logger::debug('Exact category match found', [
					'requested' => $category_name_clean,
					'matched' => $existing_map[$category_name_lower]['name'],
					'term_id' => $matched_term_id
				]);
			} else {
				// 2. Try fuzzy matching - find best similarity match
				$best_match_name = null;
				foreach ($existing_map as $existing_lower => $existing_data) {
					$similarity = self::calculateSimilarity($category_name_lower, $existing_lower);
					
					// Use a threshold of 0.6 (60% similarity) for matching
					if ($similarity > $best_match_score && $similarity >= 0.6) {
						$best_match_score = $similarity;
						$matched_term_id = $existing_data['term_id'];
						$best_match_name = $existing_data['name'];
					}
				}
				
				if ($matched_term_id) {
					Logger::debug('Fuzzy category match found', [
						'requested' => $category_name_clean,
						'matched' => $best_match_name,
						'similarity' => round($best_match_score * 100, 1) . '%',
						'term_id' => $matched_term_id
					]);
				} else {
					Logger::warning('No matching category found', [
						'requested' => $category_name_clean,
						'best_match_score' => round($best_match_score * 100, 1) . '%'
					]);
				}
			}
			
			if ($matched_term_id) {
				$category_ids[] = $matched_term_id;
			}
		}
		
		return array_unique($category_ids);
	}
	
	/**
	 * Calculate similarity between two strings using multiple methods.
	 *
	 * @param string $str1 First string.
	 * @param string $str2 Second string.
	 * @return float Similarity score between 0 and 1.
	 */
	private static function calculateSimilarity(string $str1, string $str2): float {
		// Handle empty strings
		if (empty($str1) && empty($str2)) {
			return 1.0;
		}
		if (empty($str1) || empty($str2)) {
			return 0.0;
		}
		
		// Method 1: Check if one string contains the other (partial match)
		if (stripos($str1, $str2) !== false || stripos($str2, $str1) !== false) {
			$min_len = min(strlen($str1), strlen($str2));
			$max_len = max(strlen($str1), strlen($str2));
			// Higher score if lengths are closer
			$ratio = $max_len > 0 ? ($min_len / $max_len) : 0;
			return max(0.0, min(1.0, 0.7 + (0.3 * $ratio))); // Clamp between 0 and 1
		}
		
		// Method 2: Levenshtein distance (edit distance)
		$max_len = max(strlen($str1), strlen($str2));
		if ($max_len === 0) {
			return 1.0;
		}
		
		$distance = levenshtein($str1, $str2);
		$similarity = 1 - ($distance / $max_len);
		$similarity = max(0.0, min(1.0, $similarity)); // Clamp between 0 and 1
		
		// Method 3: Word-based similarity (if strings contain multiple words)
		$words1 = array_filter(explode(' ', $str1)); // Remove empty words
		$words2 = array_filter(explode(' ', $str2)); // Remove empty words
		
		if (count($words1) > 1 || count($words2) > 1) {
			$common_words = array_intersect($words1, $words2);
			$total_words = count(array_unique(array_merge($words1, $words2)));
			$word_similarity = $total_words > 0 ? count($common_words) / $total_words : 0;
			
			// Combine both methods (weighted average)
			$combined = ($similarity * 0.6) + ($word_similarity * 0.4);
			return max(0.0, min(1.0, $combined)); // Clamp between 0 and 1
		}
		
		return $similarity;
	}
}

