<?php

namespace AEBG\Core;

use AEBG\Core\ImageProcessor;
use AEBG\Core\Logger;

/**
 * Featured Image Generator Class
 * Handles generation and assignment of featured images for posts.
 *
 * @package AEBG\Core
 */
class FeaturedImageGenerator {
	/**
	 * Generate and set featured image for a post.
	 *
	 * @param int $post_id Post ID.
	 * @param string $title Post title.
	 * @param array $settings Settings array.
	 * @param string $api_key OpenAI API key.
	 * @param string $ai_model AI model to use.
	 * @return int|false Attachment ID on success, false on failure.
	 */
	public static function generate(int $post_id, string $title, array $settings, string $api_key, string $ai_model): int|false {
		try {
			// Check setting
			if (empty($settings['generate_featured_images']) || !$settings['generate_featured_images']) {
				Logger::debug('Featured image generation skipped - setting disabled', ['post_id' => $post_id]);
				return false;
			}
			
			// Get image style from settings
			$image_style = $settings['featured_image_style'] ?? 'realistic photo';
			
			// Get image generation settings
			$image_settings = [
				'image_model' => $settings['image_model'] ?? 'dall-e-3',
				'image_size' => $settings['image_size'] ?? '1024x1024',
				'image_quality' => $settings['image_quality'] ?? 'standard',
			];
			
			// Validate API key
			if (empty($api_key)) {
				Logger::error('Featured image generation failed - no API key', ['post_id' => $post_id]);
				return false;
			}
			
			// Generate featured image using ImageProcessor
			$attachment_id = ImageProcessor::generateFeaturedImage($title, $api_key, $ai_model, $image_style, $image_settings);
			
			if (!$attachment_id || !is_numeric($attachment_id)) {
				Logger::warning('Featured image generation returned invalid attachment ID', [
					'post_id' => $post_id,
					'attachment_id' => $attachment_id
				]);
				return false;
			}
			
			// Set as featured image
			$result = set_post_thumbnail($post_id, $attachment_id);
			
			if ($result) {
				Logger::info('Featured image set successfully', [
					'post_id' => $post_id,
					'attachment_id' => $attachment_id,
					'style' => $image_style
				]);
				return (int) $attachment_id;
			} else {
				Logger::warning('Failed to set featured image', [
					'post_id' => $post_id,
					'attachment_id' => $attachment_id
				]);
				return false;
			}
		} catch (\Exception $e) {
			Logger::error('Exception in featured image generation', [
				'post_id' => $post_id,
				'error' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine()
			]);
			return false;
		}
	}
}

