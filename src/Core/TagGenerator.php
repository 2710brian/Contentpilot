<?php

namespace AEBG\Core;

use AEBG\Core\ContentGenerator;
use AEBG\Core\Logger;

/**
 * Tag Generator Class
 * Handles AI-powered tag generation and assignment for posts.
 *
 * @package AEBG\Core
 */
class TagGenerator {
	/**
	 * Generate and assign tags to a post.
	 *
	 * @param int $post_id Post ID.
	 * @param string $title Post title.
	 * @param string $content Post content.
	 * @param array $products Array of products.
	 * @param array $context Context data.
	 * @param array $settings Settings array.
	 * @param string $api_key OpenAI API key.
	 * @param string $ai_model AI model to use.
	 * @return array|false Array of assigned tag names on success, false on failure.
	 */
	public static function generateAndAssign(int $post_id, string $title, string $content, array $products, array $context, array $settings, string $api_key, string $ai_model): array|false {
		try {
			// Check setting
			if (empty($settings['auto_tags']) || !$settings['auto_tags']) {
				Logger::debug('Tag generation skipped - setting disabled', ['post_id' => $post_id]);
				return false;
			}
			
			// Validate API key
			if (empty($api_key)) {
				Logger::error('Tag generation failed - no API key', ['post_id' => $post_id]);
				return false;
			}
			
			// Build prompt
			$prompt = self::buildTagPrompt($title, $content, $products);
			
			// Generate tags using ContentGenerator
			$content_generator = new ContentGenerator();
			$generated_tags = $content_generator->generateContentWithPrompt(
				$title,
				$products,
				$context,
				$api_key,
				$ai_model,
				$prompt,
				'tags'
			);
			
			if (!$generated_tags || is_wp_error($generated_tags)) {
				Logger::warning('Tag generation failed', [
					'post_id' => $post_id,
					'error' => is_wp_error($generated_tags) ? $generated_tags->get_error_message() : 'Unknown error'
				]);
				return false;
			}
			
			// Parse tags from response
			$tags = self::parseTags($generated_tags);
			
			if (empty($tags)) {
				Logger::warning('No tags parsed from AI response', ['post_id' => $post_id, 'response' => $generated_tags]);
				return false;
			}
			
			// CRITICAL: Verify post exists before assigning tags
			$post = get_post($post_id);
			if (!$post) {
				Logger::error('Post does not exist, cannot assign tags', [
					'post_id' => $post_id
				]);
				return false;
			}
			
			// CRITICAL: Clear post cache to ensure fresh data
			clean_post_cache($post_id);
			wp_cache_delete($post_id, 'posts');
			
			// CRITICAL: Ensure post is published or at least saved (not auto-draft)
			// Tags can only be assigned to saved posts
			if ($post->post_status === 'auto-draft') {
				Logger::warning('Post is auto-draft, updating status to draft before assigning tags', [
					'post_id' => $post_id
				]);
				wp_update_post([
					'ID' => $post_id,
					'post_status' => 'draft'
				]);
				clean_post_cache($post_id);
			}
			
			// Assign tags to post
			// CRITICAL: Use wp_set_object_terms() directly for more reliable tag assignment
			// wp_set_post_tags() is a wrapper that may have issues in some WordPress configurations
			// CRITICAL: Pass tags as array of strings (tag names), WordPress will create them if they don't exist
			$result = wp_set_object_terms($post_id, $tags, 'post_tag', false); // false = append to existing tags
			
			if (is_wp_error($result)) {
				Logger::error('Failed to assign tags', [
					'post_id' => $post_id,
					'error' => $result->get_error_message(),
					'error_code' => $result->get_error_code(),
					'error_data' => $result->get_error_data(),
					'tags' => $tags
				]);
				return false;
			}
			
			// CRITICAL: Clear all caches after assigning tags
			clean_post_cache($post_id);
			wp_cache_delete($post_id, 'post_tag_relationships');
			wp_cache_delete('post_tag', 'taxonomy');
			
			// CRITICAL: Force WordPress to reload term relationships
			// Sometimes WordPress caches term relationships and doesn't refresh them immediately
			if (function_exists('delete_object_term_cache')) {
				\delete_object_term_cache($post_id, 'post_tag');
			}
			
			// Verify tags were actually assigned
			$assigned_tags = wp_get_post_tags($post_id, ['fields' => 'names']);
			if (empty($assigned_tags)) {
				Logger::warning('Tags were not assigned - wp_set_object_terms returned but no tags found on post', [
					'post_id' => $post_id,
					'result' => $result,
					'result_type' => gettype($result),
					'tags' => $tags
				]);
				
				// Try alternative method: wp_set_post_tags()
				$result2 = wp_set_post_tags($post_id, $tags, false);
				if (is_wp_error($result2)) {
					Logger::error('Alternative tag assignment method also failed', [
						'post_id' => $post_id,
						'error' => $result2->get_error_message(),
						'tags' => $tags
					]);
					return false;
				}
				
				// Clear caches again
				clean_post_cache($post_id);
				if (function_exists('delete_object_term_cache')) {
					\delete_object_term_cache($post_id, 'post_tag');
				}
				
				// Verify again
				$assigned_tags = wp_get_post_tags($post_id, ['fields' => 'names']);
				
				if (empty($assigned_tags)) {
					// Last resort: Manually create term relationships
					Logger::warning('Both methods failed to assign tags, trying manual term relationship creation', [
						'post_id' => $post_id,
						'tags' => $tags
					]);
					
					foreach ($tags as $tag_name) {
						$term = get_term_by('name', $tag_name, 'post_tag');
						if (!$term) {
							// Create term if it doesn't exist
							$term_result = wp_insert_term($tag_name, 'post_tag');
							if (!is_wp_error($term_result)) {
								$term_id = $term_result['term_id'];
							} else {
								Logger::warning('Failed to create tag term', [
									'tag_name' => $tag_name,
									'error' => $term_result->get_error_message()
								]);
								continue;
							}
						} else {
							$term_id = $term->term_id;
						}
						
						// Manually create term relationship
						$term_relationship_result = wp_set_object_terms($post_id, [$term_id], 'post_tag', true);
						if (is_wp_error($term_relationship_result)) {
							Logger::warning('Failed to create term relationship', [
								'post_id' => $post_id,
								'term_id' => $term_id,
								'tag_name' => $tag_name,
								'error' => $term_relationship_result->get_error_message()
							]);
						}
					}
					
					// Clear caches one more time
					clean_post_cache($post_id);
					if (function_exists('delete_object_term_cache')) {
						\delete_object_term_cache($post_id, 'post_tag');
					}
					
					// Final verification
					$assigned_tags = wp_get_post_tags($post_id, ['fields' => 'names']);
				}
			}
			
			Logger::info('Tags generated and assigned successfully', [
				'post_id' => $post_id,
				'requested_tag_count' => count($tags),
				'assigned_tag_count' => count($assigned_tags),
				'requested_tags' => $tags,
				'assigned_tags' => $assigned_tags
			]);
			
			return $assigned_tags ?: $tags; // Return assigned tags if available, otherwise requested tags
		} catch (\Exception $e) {
			Logger::error('Exception in tag generation', [
				'post_id' => $post_id,
				'error' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine()
			]);
			return false;
		}
	}
	
	/**
	 * Build prompt for tag generation.
	 *
	 * @param string $title Post title.
	 * @param string $content Post content.
	 * @param array $products Array of products.
	 * @return string Prompt.
	 */
	private static function buildTagPrompt(string $title, string $content, array $products): string {
		$content_excerpt = wp_trim_words($content, 50, '...');
		
		$product_names = [];
		if (!empty($products) && is_array($products)) {
			foreach ($products as $product) {
				$product_names[] = $product['name'] ?? $product['title'] ?? '';
			}
		}
		$product_names_text = !empty($product_names) ? implode(', ', array_filter($product_names)) : 'None';
		
		return "Based on the following blog post, generate 5-10 relevant WordPress tags.

Post Title: {$title}
Content Excerpt: {$content_excerpt}
Products Featured: {$product_names_text}

Requirements:
- Return only tag names, comma-separated
- Tags should be relevant for SEO
- Use lowercase, no special characters except hyphens
- Maximum 10 tags
- Each tag should be 1-3 words

Return format: tag1, tag2, tag3, ...";
	}
	
	/**
	 * Parse tags from AI response.
	 *
	 * @param string $response AI response.
	 * @return array Array of tag names.
	 */
	private static function parseTags(string $response): array {
		// Clean the response
		$response = trim($response);
		
		// Remove quotes if present
		$response = trim($response, '"\'');
		
		// Split by comma
		$tags = explode(',', $response);
		
		// Clean each tag
		$cleaned_tags = [];
		foreach ($tags as $tag) {
			$tag = trim($tag);
			$tag = strtolower($tag);
			// Remove special characters except hyphens and spaces
			$tag = preg_replace('/[^a-z0-9\s-]/', '', $tag);
			// Replace multiple spaces with single space
			$tag = preg_replace('/\s+/', ' ', $tag);
			$tag = trim($tag);
			
			// Skip empty tags and tags that are too long
			if (!empty($tag) && strlen($tag) <= 50) {
				$cleaned_tags[] = $tag;
			}
		}
		
		// Limit to 10 tags
		$cleaned_tags = array_slice($cleaned_tags, 0, 10);
		
		return array_unique($cleaned_tags);
	}
}

