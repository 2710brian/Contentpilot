<?php

namespace AEBG\Core;

use AEBG\Core\ProductImageManager;

/**
 * Image Processor Class
 * Wraps ProductImageManager and handles all image operations.
 *
 * @package AEBG\Core
 */
class ImageProcessor {
	/**
	 * Process product images using ProductImageManager
	 *
	 * @param array $products Array of products
	 * @param int $post_id Post ID to associate images with
	 * @return array Processed products with image attachment IDs
	 */
	public static function processProductImages($products, $post_id = 0) {
		return ProductImageManager::processProductImages($products, $post_id);
	}

	/**
	 * Generate featured image for a post using AI (like old version).
	 *
	 * @param string $title The post title
	 * @param string $api_key OpenAI API key
	 * @param string $ai_model AI model to use
	 * @param string $style Image style
	 * @param array $image_settings Optional image generation settings (model, size, quality)
	 * @return int|false Attachment ID or false on failure
	 */
	public static function generateFeaturedImage($title, $api_key, $ai_model, $style = 'realistic photo', $image_settings = []) {
		// Create a descriptive prompt based on the title and style
		$prompt = self::createFeaturedImagePrompt($title, $style);
		
		// Generate the image using DALL-E API with settings
		$image_url = self::generateAIImage($prompt, $api_key, $ai_model, $image_settings);
		
		if (!$image_url) {
			error_log('[AEBG] Failed to generate featured image for title: ' . $title);
			return false;
		}
		
		// Download and save the image to the media library
		$attachment_id = ProductImageManager::downloadAndInsertImage($image_url, $prompt, 0);
		
		if (!$attachment_id) {
			error_log('[AEBG] Failed to save featured image to media library');
			return false;
		}
		
		error_log('[AEBG] Featured image generated and saved successfully with ID: ' . $attachment_id);
		return $attachment_id;
	}

	/**
	 * Create a descriptive prompt for featured image generation (like old version).
	 *
	 * @param string $title The post title
	 * @param string $style The visual style
	 * @return string The generated prompt
	 */
	public static function createFeaturedImagePrompt($title, $style) {
		// Clean the title for better prompt generation
		$clean_title = sanitize_text_field($title);
		
		// Create a general, descriptive prompt based on the title
		$base_prompt = "Create a professional featured image for a blog post about: " . $clean_title;
		
		// Add style-specific instructions
		$style_instructions = self::getStyleInstructions($style);
		
		// Combine base prompt with style instructions
		$full_prompt = $base_prompt . ". " . $style_instructions . " The image should be visually appealing and relevant to the topic.";
		
		return $full_prompt;
	}

	/**
	 * Get style-specific instructions for image generation (like old version).
	 *
	 * @param string $style The visual style
	 * @return string The style instructions
	 */
	public static function getStyleInstructions($style) {
		$style_instructions = [
			'realistic photo' => 'Style: High-quality realistic photograph with natural lighting and professional composition',
			'digital art' => 'Style: Modern digital artwork with vibrant colors and contemporary design elements',
			'illustration' => 'Style: Hand-drawn illustration with artistic flair and creative interpretation',
			'3D render' => 'Style: 3D rendered image with depth, shadows, and modern visual appeal',
			'minimalist' => 'Style: Clean minimalist design with simple shapes, limited colors, and elegant composition',
			'vintage' => 'Style: Vintage aesthetic with retro colors, textures, and classic design elements',
			'modern' => 'Style: Contemporary modern design with sleek lines, bold typography, and current trends',
			'professional' => 'Style: Professional business image with corporate aesthetics and polished appearance'
		];
		
		return $style_instructions[$style] ?? $style_instructions['realistic photo'];
	}

	/**
	 * Generate an AI image using OpenAI DALL·E API (like old version).
	 *
	 * @param string $prompt The image prompt.
	 * @param string $api_key OpenAI API key.
	 * @param string $ai_model AI model to use.
	 * @param array $image_settings Optional image generation settings (model, size, quality).
	 * @return string|false Image URL or false on failure.
	 */
	public static function generateAIImage($prompt, $api_key, $ai_model, $image_settings = []) {
		// Get image model from settings or fallback to text model
		$image_model = !empty($image_settings['image_model'])
			? sanitize_text_field($image_settings['image_model'])
			: self::getImageModelFromTextModel($ai_model);

		// Nano Banana / Gemini (Google) – use Google API and return URL
		$nano_models = [ 'nano-banana', 'nano-banana-2', 'nano-banana-pro' ];
		if ( in_array( $image_model, $nano_models, true ) ) {
			return self::generateAIImageGemini( $prompt, $image_settings, $image_model );
		}

		// Get image size from settings or fallback to default
		$image_size = !empty($image_settings['image_size'])
			? sanitize_text_field($image_settings['image_size'])
			: self::getImageSizeFromModel($image_model);

		// Validate image size based on model
		$image_size = self::validateImageSize($image_model, $image_size);

		$request_body = [
			'model' => $image_model,
			'prompt' => $prompt,
			'n' => 1,
			'size' => $image_size,
		];

		// Add quality parameter for DALL-E 3 only (DALL-E 2 doesn't support quality parameter)
		if (strpos($image_model, 'dall-e-3') === 0) {
			$quality = !empty($image_settings['image_quality'])
				? sanitize_text_field($image_settings['image_quality'])
				: 'standard';

			// Validate quality (only 'standard' or 'hd' for DALL-E 3)
			if (in_array($quality, ['standard', 'hd'], true)) {
				$request_body['quality'] = $quality;
			} else {
				$request_body['quality'] = 'standard';
			}
		}

		$api_endpoint = 'https://api.openai.com/v1/images/generations';

		// ULTRA-ROBUST: Use APIClient::makeRequest() for ultra-robust timeout handling
		$response_data = \AEBG\Core\APIClient::makeRequest($api_endpoint, $api_key, $request_body, 60, 3);

		if (is_wp_error($response_data)) {
			error_log('[AEBG] DALL-E API error: ' . $response_data->get_error_message());
			return false;
		}

		if (empty($response_data) || !is_array($response_data)) {
			error_log('[AEBG] DALL-E API returned empty or invalid response');
			return false;
		}

		if (!isset($response_data['data'][0]['url'])) {
			error_log('[AEBG] DALL-E API response missing image URL');
			return false;
		}

		self::recordImageGenerationUsage( $image_model, $image_size, isset( $request_body['quality'] ) ? $request_body['quality'] : 'standard' );
		return $response_data['data'][0]['url'];
	}

	/**
	 * Map our image model id to Gemini API model name (per official docs).
	 * https://ai.google.dev/gemini-api/docs/image-generation
	 *
	 * @param string $image_model One of nano-banana, nano-banana-2, nano-banana-pro.
	 * @return string Gemini API model name.
	 */
	private static function getGeminiImageModelName( $image_model ) {
		$map = [
			'nano-banana'     => 'gemini-2.5-flash-image',           // Nano Banana – speed, high-volume
			'nano-banana-2'   => 'gemini-3.1-flash-image-preview',   // Nano Banana 2 – recommended balance
			'nano-banana-pro' => 'gemini-3-pro-image-preview',       // Nano Banana Pro – professional
		];
		return $map[ $image_model ] ?? 'gemini-2.5-flash-image';
	}

	/**
	 * Map our size (e.g. 1024x1024) to Gemini aspect ratio (per docs).
	 *
	 * @param string $image_size 1024x1024, 1792x1024, or 1024x1792.
	 * @return string Aspect ratio e.g. 1:1, 16:9, 9:16.
	 */
	private static function getGeminiAspectRatio( $image_size ) {
		if ( $image_size === '1792x1024' ) {
			return '16:9';
		}
		if ( $image_size === '1024x1792' ) {
			return '9:16';
		}
		return '1:1';
	}

	/**
	 * Generate an AI image using Google Gemini (Nano Banana) API.
	 * Supports: Nano Banana (2.5 Flash), Nano Banana 2 (3.1 Flash Image), Nano Banana Pro (3 Pro Image).
	 * Requires Google API key in Settings. See https://ai.google.dev/gemini-api/docs/image-generation
	 *
	 * @param string $prompt         The image prompt.
	 * @param array  $image_settings Optional image generation settings (image_size, image_model).
	 * @param string $image_model    One of nano-banana, nano-banana-2, nano-banana-pro.
	 * @return string|false Image URL or false on failure.
	 */
	private static function generateAIImageGemini( $prompt, $image_settings, $image_model = 'nano-banana' ) {
		$settings = \AEBG\Admin\Settings::get_settings();
		$google_key = defined( 'AEBG_GOOGLE_API_KEY' ) ? AEBG_GOOGLE_API_KEY : ( $settings['google_api_key'] ?? '' );
		if ( empty( $google_key ) ) {
			error_log( '[AEBG] Nano Banana selected but Google API key is missing. Add it in Settings or define AEBG_GOOGLE_API_KEY.' );
			return false;
		}

		$image_size = ! empty( $image_settings['image_size'] ) ? sanitize_text_field( $image_settings['image_size'] ) : '1024x1024';
		$image_size = self::validateImageSize( $image_model, $image_size );

		$api_model = self::getGeminiImageModelName( $image_model );
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $api_model . ':generateContent';

		$aspect_ratio = self::getGeminiAspectRatio( $image_size );

		// generationConfig per official REST docs: responseModalities, imageConfig (aspectRatio; imageSize for 3.1/3 Pro)
		$generation_config = [
			'responseModalities' => [ 'IMAGE', 'TEXT' ],
			'imageConfig'        => [
				'aspectRatio' => $aspect_ratio,
			],
		];
		// Gemini 3.1 Flash Image and 3 Pro Image support imageSize (1K, 2K, 4K). Use 1K for standard output.
		if ( in_array( $image_model, [ 'nano-banana-2', 'nano-banana-pro' ], true ) ) {
			$generation_config['imageConfig']['imageSize'] = '1K';
		}

		$body = [
			'contents' => [
				[ 'parts' => [ [ 'text' => $prompt ] ] ],
			],
			'generationConfig' => $generation_config,
		];

		$response = wp_remote_post( $url, [
			'timeout' => 120,
			'headers' => [
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $google_key,
			],
			'body' => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $response ) ) {
			error_log( '[AEBG] Gemini image API error: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body_raw = wp_remote_retrieve_body( $response );
		$data = json_decode( $body_raw, true );

		if ( $code !== 200 || empty( $data ) ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : substr( $body_raw, 0, 500 );
			error_log( '[AEBG] Gemini image API failed (HTTP ' . $code . '): ' . $msg );
			return false;
		}

		if ( empty( $data['candidates'][0] ) || empty( $data['candidates'][0]['content']['parts'] ) ) {
			$reason = isset( $data['candidates'][0]['finishReason'] ) ? $data['candidates'][0]['finishReason'] : ( isset( $data['promptFeedback']['blockReason'] ) ? $data['promptFeedback']['blockReason'] : 'no image in response' );
			error_log( '[AEBG] Gemini image API: ' . $reason );
			return false;
		}

		$parts = $data['candidates'][0]['content']['parts'];
		$image_base64 = null;
		$mime_type = 'image/png';
		// Use the last image part that is not a "thought" (Gemini 3 returns interim thought images).
		foreach ( array_reverse( $parts ) as $part ) {
			if ( ! empty( $part['thought'] ) ) {
				continue;
			}
			$inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
			if ( $inline && ! empty( $inline['data'] ) ) {
				$image_base64 = $inline['data'];
				$mime_type = $inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png';
				break;
			}
		}

		if ( empty( $image_base64 ) ) {
			error_log( '[AEBG] Gemini image API response contained no image data.' );
			return false;
		}

		$decoded = base64_decode( $image_base64, true );
		if ( $decoded === false ) {
			error_log( '[AEBG] Gemini image API: failed to decode base64 image.' );
			return false;
		}

		$ext = ( strpos( $mime_type, 'jpeg' ) !== false || strpos( $mime_type, 'jpg' ) !== false ) ? 'jpg' : 'png';
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			error_log( '[AEBG] Gemini image: upload dir error: ' . $upload_dir['error'] );
			return false;
		}
		$filename = 'aebg-gemini-' . uniqid() . '.' . $ext;
		$filepath = $upload_dir['path'] . '/' . $filename;
		$fileurl = $upload_dir['url'] . '/' . $filename;

		if ( file_put_contents( $filepath, $decoded ) === false ) {
			error_log( '[AEBG] Gemini image: failed to write file: ' . $filepath );
			return false;
		}

		self::recordImageGenerationUsage( $image_model, $image_size, 'standard' );
		return $fileurl;
	}

	/**
	 * Record image generation usage for tracking.
	 *
	 * @param string $image_model Model name.
	 * @param string $image_size  Size.
	 * @param string $quality     Quality (e.g. standard, hd).
	 */
	private static function recordImageGenerationUsage( $image_model, $image_size, $quality = 'standard' ) {
		$item_id = $GLOBALS['aebg_current_item_id'] ?? null;
		$batch_id = null;
		$batch_item_id = null;
		if ( $item_id ) {
			global $wpdb;
			$item = $wpdb->get_row( $wpdb->prepare(
				"SELECT batch_id FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d",
				$item_id
			) );
			if ( $item ) {
				$batch_id = $item->batch_id;
				$batch_item_id = $item_id;
			}
		}
		\AEBG\Core\UsageTracker::record_image_generation( [
			'batch_id'      => $batch_id,
			'batch_item_id' => $batch_item_id,
			'post_id'       => $GLOBALS['aebg_current_post_id'] ?? null,
			'user_id'       => get_current_user_id(),
			'model'         => $image_model,
			'size'          => $image_size,
			'quality'       => $quality,
			'request_type'  => 'image_generation',
			'step_name'     => $GLOBALS['aebg_current_step'] ?? 'image_generation',
		] );
	}
	
	/**
	 * Validate image size based on model capabilities.
	 *
	 * @param string $image_model The image model name.
	 * @param string $image_size The requested image size.
	 * @return string Validated image size.
	 */
	private static function validateImageSize($image_model, $image_size) {
		// Nano Banana family – same aspect ratios as DALL-E 3 (1:1, 16:9, 9:16)
		$nano_models = [ 'nano-banana', 'nano-banana-2', 'nano-banana-pro' ];
		if ( in_array( $image_model, $nano_models, true ) ) {
			$valid_sizes = [ '1024x1024', '1792x1024', '1024x1792' ];
			return in_array( $image_size, $valid_sizes, true ) ? $image_size : '1024x1024';
		}

		// DALL-E 3 supported sizes
		if (strpos($image_model, 'dall-e-3') === 0) {
			$valid_sizes = ['1024x1024', '1792x1024', '1024x1792'];
			if (in_array($image_size, $valid_sizes)) {
				return $image_size;
			}
			return '1024x1024'; // Default for DALL-E 3
		}

		// DALL-E 2 supported sizes
		if (strpos($image_model, 'dall-e-2') === 0) {
			$valid_sizes = ['256x256', '512x512', '1024x1024'];
			// Map DALL-E 3 sizes to closest DALL-E 2 sizes
			if ($image_size === '1792x1024' || $image_size === '1024x1792') {
				return '1024x1024';
			}
			if (in_array($image_size, $valid_sizes)) {
				return $image_size;
			}
			return '1024x1024'; // Default for DALL-E 2
		}

		// Fallback
		return '1024x1024';
	}

	/**
	 * Get image model from text model (like old version).
	 *
	 * @param string $text_model The text model name.
	 * @return string The image model name.
	 */
	private static function getImageModelFromTextModel($text_model) {
		// Default to DALL-E 3 for most models
		if (strpos($text_model, 'gpt-4') === 0 || strpos($text_model, 'gpt-3.5') === 0) {
			return 'dall-e-3';
		}
		
		// Default to DALL-E 3
		return 'dall-e-3';
	}

	/**
	 * Get image size from model (like old version).
	 *
	 * @param string $image_model The image model name.
	 * @return string The image size.
	 */
	private static function getImageSizeFromModel($image_model) {
		$nano_models = [ 'nano-banana', 'nano-banana-2', 'nano-banana-pro' ];
		if ( in_array( $image_model, $nano_models, true ) || strpos( $image_model, 'dall-e-3' ) === 0 ) {
			return '1024x1024';
		}
		return '1024x1024'; // DALL-E 2 default
	}
}

