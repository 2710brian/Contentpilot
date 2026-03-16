<?php

namespace AEBG\Core;

use AEBG\Core\ContentGenerator;
use AEBG\Core\Logger;

/**
 * Meta Description Generator Class
 * Handles AI-powered meta description generation and saving for SEO plugins.
 *
 * @package AEBG\Core
 */
class MetaDescriptionGenerator {
	/**
	 * Generate and save meta description for a post.
	 *
	 * @param int $post_id Post ID.
	 * @param string $title Post title.
	 * @param string $content Post content.
	 * @param array $products Array of products.
	 * @param array $settings Settings array.
	 * @param string $api_key OpenAI API key.
	 * @param string $ai_model AI model to use.
	 * @return string|false Meta description on success, false on failure.
	 */
	public static function generateAndSave(int $post_id, string $title, string $content, array $products, array $settings, string $api_key, string $ai_model): string|false {
		try {
			// Check setting
			if (empty($settings['include_meta']) || !$settings['include_meta']) {
				Logger::debug('Meta description generation skipped - setting disabled', ['post_id' => $post_id]);
				return false;
			}
			
			// Validate API key
			if (empty($api_key)) {
				Logger::error('Meta description generation failed - no API key', ['post_id' => $post_id]);
				return false;
			}
			
			// Build prompt
			$prompt = self::buildMetaDescriptionPrompt($title, $content, $products);
			
			// Generate meta description using ContentGenerator
			$content_generator = new ContentGenerator();
			$generated_meta = $content_generator->generateContentWithPrompt(
				$title,
				$products,
				[],
				$api_key,
				$ai_model,
				$prompt,
				'meta-description'
			);
			
			if (!$generated_meta || is_wp_error($generated_meta)) {
				Logger::warning('Meta description generation failed', [
					'post_id' => $post_id,
					'error' => is_wp_error($generated_meta) ? $generated_meta->get_error_message() : 'Unknown error'
				]);
				return false;
			}
			
			// Clean and validate meta description
			$meta_description = self::cleanMetaDescription($generated_meta);
			
			if (empty($meta_description)) {
				Logger::warning('Meta description is empty after cleaning', ['post_id' => $post_id, 'raw' => $generated_meta]);
				return false;
			}
			
			// Save to multiple SEO plugins
			$saved = self::saveToSEOPlugins($post_id, $meta_description);
			
			if ($saved) {
				Logger::info('Meta description generated and saved successfully', [
					'post_id' => $post_id,
					'length' => strlen($meta_description),
					'meta' => $meta_description
				]);
				return $meta_description;
			} else {
				Logger::warning('Failed to save meta description to any SEO plugin', ['post_id' => $post_id]);
				return false;
			}
		} catch (\Exception $e) {
			Logger::error('Exception in meta description generation', [
				'post_id' => $post_id,
				'error' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine()
			]);
			return false;
		}
	}
	
	/**
	 * Build prompt for meta description generation.
	 *
	 * @param string $title Post title.
	 * @param string $content Post content.
	 * @param array $products Array of products.
	 * @return string Prompt.
	 */
	private static function buildMetaDescriptionPrompt(string $title, string $content, array $products): string {
		$content_excerpt = wp_trim_words($content, 100, '...');
		
		$product_names = [];
		if (!empty($products) && is_array($products)) {
			foreach ($products as $product) {
				$product_names[] = $product['name'] ?? $product['title'] ?? '';
			}
		}
		$product_names_text = !empty($product_names) ? implode(', ', array_filter($product_names)) : 'None';
		
		return "Write a compelling SEO meta description for this blog post.

Post Title: {$title}
Content Excerpt: {$content_excerpt}
Products Featured: {$product_names_text}

Requirements:
- Exactly 150-160 characters
- Include primary keyword naturally
- Compelling and click-worthy
- No quotes or special formatting
- Clear value proposition
- Call to action if appropriate

Return only the meta description text, nothing else.";
	}
	
	/**
	 * Clean and validate meta description.
	 *
	 * @param string $meta_description Raw meta description.
	 * @return string Cleaned meta description.
	 */
	private static function cleanMetaDescription(string $meta_description): string {
		// Remove quotes
		$meta_description = trim($meta_description, '"\'');
		
		// Remove HTML tags
		$meta_description = strip_tags($meta_description);
		
		// Trim whitespace
		$meta_description = trim($meta_description);
		
		// Limit to 160 characters (SEO best practice)
		if (strlen($meta_description) > 160) {
			$meta_description = substr($meta_description, 0, 157) . '...';
		}
		
		// Ensure minimum length
		if (strlen($meta_description) < 120) {
			// Too short, but return it anyway
			Logger::warning('Meta description is shorter than recommended', ['length' => strlen($meta_description)]);
		}
		
		return $meta_description;
	}
	
	/**
	 * Save meta description to multiple SEO plugins.
	 *
	 * @param int $post_id Post ID.
	 * @param string $meta_description Meta description.
	 * @return bool True if saved to at least one plugin, false otherwise.
	 */
	private static function saveToSEOPlugins(int $post_id, string $meta_description): bool {
		$saved = false;
		$plugins = self::detectSEOPlugins();
		
		// Yoast SEO
		if (in_array('yoast', $plugins)) {
			$result = update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_description);
			if ($result) {
				$saved = true;
				Logger::debug('Saved meta description to Yoast SEO', ['post_id' => $post_id]);
			}
		}
		
		// Rank Math
		if (in_array('rankmath', $plugins)) {
			$result = update_post_meta($post_id, 'rank_math_description', $meta_description);
			if ($result) {
				$saved = true;
				Logger::debug('Saved meta description to Rank Math', ['post_id' => $post_id]);
			}
		}
		
		// All in One SEO
		if (in_array('aioseo', $plugins)) {
			$result = update_post_meta($post_id, '_aioseo_description', $meta_description);
			if ($result) {
				$saved = true;
				Logger::debug('Saved meta description to AIOSEO', ['post_id' => $post_id]);
			}
		}
		
		// WordPress Core (fallback)
		$result = update_post_meta($post_id, '_wp_meta_description', $meta_description);
		if ($result) {
			$saved = true;
			Logger::debug('Saved meta description to WordPress core meta', ['post_id' => $post_id]);
		}
		
		return $saved;
	}
	
	/**
	 * Detect installed SEO plugins.
	 *
	 * @return array Array of detected plugin names.
	 */
	private static function detectSEOPlugins(): array {
		$plugins = [];
		
		if (defined('WPSEO_VERSION')) {
			$plugins[] = 'yoast';
		}
		if (defined('RANK_MATH_VERSION')) {
			$plugins[] = 'rankmath';
		}
		if (defined('AIOSEO_VERSION')) {
			$plugins[] = 'aioseo';
		}
		
		return $plugins;
	}
}

