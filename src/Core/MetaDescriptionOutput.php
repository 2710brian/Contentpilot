<?php

namespace AEBG\Core;

/**
 * Meta Description Output Class
 * Handles outputting meta descriptions to the frontend head.
 * Works as a fallback if no SEO plugin is active.
 *
 * @package AEBG\Core
 */
class MetaDescriptionOutput {
	
	/**
	 * Initialize meta description output hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Add output with low priority (99) so SEO plugins can override
		// If no SEO plugin is active, this will output the meta description
		// If SEO plugin is active, it will output first and this won't duplicate
		add_action('wp_head', [__CLASS__, 'output_meta_description'], 99);
	}
	
	/**
	 * Check if an SEO plugin is active and handling meta descriptions.
	 *
	 * @return bool True if SEO plugin is active.
	 */
	private static function hasSEOPlugin(): bool {
		// Check for common SEO plugins
		if (defined('WPSEO_VERSION')) {
			// Yoast SEO is active
			return true;
		}
		
		if (defined('RANK_MATH_VERSION')) {
			// Rank Math is active
			return true;
		}
		
		if (defined('AIOSEO_VERSION')) {
			// All in One SEO is active
			return true;
		}
		
		if (class_exists('The_SEO_Framework\Load')) {
			// The SEO Framework is active
			return true;
		}
		
		if (class_exists('SEOPress')) {
			// SEOPress is active
			return true;
		}
		
		return false;
	}
	
	/**
	 * Output meta description to the frontend head.
	 *
	 * @return void
	 */
	public static function output_meta_description(): void {
		// Only output on singular posts/pages
		if (!is_singular()) {
			return;
		}
		
		$post_id = get_the_ID();
		if (!$post_id) {
			return;
		}
		
		// Check if SEO plugin already output meta description
		// We check by looking at the output buffer (if available) or by checking if meta tag exists
		// Since we can't easily check what was already output, we'll check if SEO plugin has meta description
		// and if it does, we skip output to avoid duplicates
		if (self::hasSEOPlugin() && self::seoPluginHasMetaDescription($post_id)) {
			// SEO plugin is handling it, don't duplicate
			return;
		}
		
		// Try to get meta description from our saved meta field
		$meta_description = get_post_meta($post_id, '_wp_meta_description', true);
		
		// If not found, try to get from SEO plugins (in case they're installed but not outputting)
		if (empty($meta_description)) {
			// Try Yoast SEO
			if (defined('WPSEO_VERSION')) {
				$meta_description = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
			}
			
			// Try Rank Math
			if (empty($meta_description) && defined('RANK_MATH_VERSION')) {
				$meta_description = get_post_meta($post_id, 'rank_math_description', true);
			}
			
			// Try AIOSEO
			if (empty($meta_description) && defined('AIOSEO_VERSION')) {
				$meta_description = get_post_meta($post_id, '_aioseo_description', true);
			}
		}
		
		// If still empty, generate from excerpt or content
		if (empty($meta_description)) {
			$meta_description = self::generate_fallback_description($post_id);
		}
		
		if (empty($meta_description)) {
			return;
		}
		
		// Clean and validate
		$meta_description = self::clean_meta_description($meta_description);
		
		if (empty($meta_description)) {
			return;
		}
		
		// Output meta description
		echo "\n" . '<!-- AEBG Meta Description -->' . "\n";
		echo '<meta name="description" content="' . esc_attr($meta_description) . '">' . "\n";
		
		Logger::debug('Meta description output to frontend', [
			'post_id' => $post_id,
			'length' => strlen($meta_description)
		]);
	}
	
	/**
	 * Check if SEO plugin has a meta description for this post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if SEO plugin has meta description.
	 */
	private static function seoPluginHasMetaDescription(int $post_id): bool {
		// Check Yoast SEO
		if (defined('WPSEO_VERSION')) {
			$meta = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
			if (!empty($meta)) {
				return true;
			}
		}
		
		// Check Rank Math
		if (defined('RANK_MATH_VERSION')) {
			$meta = get_post_meta($post_id, 'rank_math_description', true);
			if (!empty($meta)) {
				return true;
			}
		}
		
		// Check AIOSEO
		if (defined('AIOSEO_VERSION')) {
			$meta = get_post_meta($post_id, '_aioseo_description', true);
			if (!empty($meta)) {
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * Generate fallback meta description from post content.
	 *
	 * @param int $post_id Post ID.
	 * @return string|false Meta description or false on failure.
	 */
	private static function generate_fallback_description(int $post_id): string|false {
		$post = get_post($post_id);
		if (!$post) {
			return false;
		}
		
		// Try excerpt first
		if (!empty($post->post_excerpt)) {
			$description = wp_trim_words($post->post_excerpt, 25, '...');
			if (strlen($description) >= 120) {
				return $description;
			}
		}
		
		// Fallback to content
		$content = strip_tags($post->post_content);
		$description = wp_trim_words($content, 25, '...');
		
		if (strlen($description) < 120) {
			return false;
		}
		
		return $description;
	}
	
	/**
	 * Clean and validate meta description.
	 *
	 * @param string $meta_description Raw meta description.
	 * @return string Cleaned meta description.
	 */
	private static function clean_meta_description(string $meta_description): string {
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
		
		return $meta_description;
	}
	
	/**
	 * Get meta description for a specific post (for testing/debugging).
	 *
	 * @param int $post_id Post ID.
	 * @return string|false Meta description or false on failure.
	 */
	public static function get_meta_description(int $post_id): string|false {
		$meta_description = get_post_meta($post_id, '_wp_meta_description', true);
		
		if (empty($meta_description)) {
			// Try SEO plugins
			if (defined('WPSEO_VERSION')) {
				$meta_description = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
			}
			if (empty($meta_description) && defined('RANK_MATH_VERSION')) {
				$meta_description = get_post_meta($post_id, 'rank_math_description', true);
			}
			if (empty($meta_description) && defined('AIOSEO_VERSION')) {
				$meta_description = get_post_meta($post_id, '_aioseo_description', true);
			}
		}
		
		return !empty($meta_description) ? $meta_description : false;
	}
}

