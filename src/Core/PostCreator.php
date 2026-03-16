<?php

namespace AEBG\Core;

use AEBG\Core\ProductManager;
use AEBG\Core\ProductImageManager;
use AEBG\Core\DataUtilities;
use AEBG\Core\Logger;
use AEBG\Core\ContentFormatter;

/**
 * Post Creator Class
 * Handles WordPress post creation with transaction support.
 *
 * @package AEBG\Core
 */
class PostCreator {
	/**
	 * Author ID.
	 *
	 * @var int
	 */
	private $author_id;

	/**
	 * Job start time for timeout checks.
	 *
	 * @var float|null
	 */
	private $job_start_time;

	/**
	 * Constructor.
	 *
	 * @param int        $author_id Author ID.
	 * @param float|null $job_start_time Job start time.
	 */
	public function __construct($author_id = null, $job_start_time = null) {
		$this->author_id = $author_id ?: get_current_user_id();
		$this->job_start_time = $job_start_time;
	}

	/**
	 * Create WordPress post.
	 *
	 * @param string $title Post title.
	 * @param string $content Post content.
	 * @param array  $processed_template Processed Elementor template.
	 * @param array  $settings Settings.
	 * @param array  $products Array of products.
	 * @return int|\WP_Error Post ID or WP_Error.
	 */
	public function createPost($title, $content, $processed_template, $settings, $products) {
		Logger::debug('Creating post', ['title' => $title]);

		// Check timeout
		if ($this->job_start_time) {
			$elapsed = microtime(true) - $this->job_start_time;
			$max_time = \AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT - \AEBG\Core\TimeoutManager::SAFETY_BUFFER; // Use centralized timeout (1750s = 30min - 50s buffer)
			if ($elapsed > $max_time) {
				Logger::warning('Post creation timeout', ['elapsed' => round($elapsed, 2)]);
				return new \WP_Error('aebg_timeout', 'Post creation timeout: elapsed ' . round($elapsed, 2) . ' seconds');
			}
		}

		global $wpdb;

		// Consume MySQL results
		$this->consumeAllMySQLResults();

		// Ensure unique title
		$post_type = $settings['post_type'] ?? 'post';
		$unique_title = $this->ensureUniqueTitle($title, $post_type);

		// Prepare post content
		// Format content with markdown conversion if we have content (not using Elementor template)
		if ($processed_template && !is_wp_error($processed_template)) {
			$post_content = ''; // Elementor template handles content
		} else {
			// Format content with markdown-to-HTML conversion
			$post_content = ContentFormatter::formatTextEditorContent($content);
		}
		$post_status = $settings['post_status'] ?? 'draft';

		$post_data = [
			'post_title'   => $unique_title,
			'post_content' => $post_content,
			'post_status'  => $post_status,
			'post_type'    => $post_type,
			'post_author'  => $this->author_id,
		];

		// Create post
		$this->consumeAllMySQLResults();
		$new_post_id = wp_insert_post($post_data, true);
		$this->consumeAllMySQLResults();

		if (is_wp_error($new_post_id)) {
			Logger::error('Post creation failed', ['error' => $new_post_id->get_error_message()]);
			return $new_post_id;
		}

		if (empty($new_post_id) || !is_numeric($new_post_id) || $new_post_id <= 0) {
			Logger::error('Post creation returned invalid ID', ['id' => $new_post_id]);
			return new \WP_Error('aebg_post_creation_error', 'Post creation failed - invalid post ID returned');
		}

		// Verify post exists
		$verified = $this->verifyPostExists($new_post_id);
		if (!$verified) {
			Logger::error('Post not found after creation', ['post_id' => $new_post_id]);
			return new \WP_Error('aebg_post_not_saved', 'Post was not saved to database');
		}

		// Save metadata
		$this->savePostMetadata($new_post_id, $title, $settings);

		// Save products
		if (!empty($products) && is_array($products)) {
			$this->savePostProducts($new_post_id, $products);
		}

		// Save Elementor template
		if ($processed_template && !is_wp_error($processed_template)) {
			$this->saveElementorTemplate($new_post_id, $processed_template, $settings);
		}

		// Force Elementor CSS generation
		$this->forceElementorCSSGeneration($new_post_id);

		Logger::info('Post created successfully', ['post_id' => $new_post_id, 'title' => $unique_title]);

		return $new_post_id;
	}

	/**
	 * Ensure unique title.
	 *
	 * @param string $title Original title.
	 * @param string $post_type Post type.
	 * @return string Unique title.
	 */
	private function ensureUniqueTitle($title, $post_type) {
		global $wpdb;

		$unique_title = $title;
		$counter = 1;

		$this->consumeAllMySQLResults();
		$existing_post = $wpdb->get_var($wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = %s LIMIT 1",
			$unique_title,
			$post_type
		));
		$this->consumeAllMySQLResults();

		while ($existing_post && $counter <= 100) {
			$counter++;
			$unique_title = $title . ' (' . $counter . ')';

			$this->consumeAllMySQLResults();
			$existing_post = $wpdb->get_var($wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = %s LIMIT 1",
				$unique_title,
				$post_type
			));
			$this->consumeAllMySQLResults();
		}

		if ($counter > 100) {
			Logger::error('Unable to generate unique title', ['title' => $title]);
			return $title; // Return original if we can't make it unique
		}

		return $unique_title;
	}

	/**
	 * Verify post exists in database.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if exists.
	 */
	private function verifyPostExists($post_id) {
		global $wpdb;

		$this->consumeAllMySQLResults();
		$verify_post = $wpdb->get_var($wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
			$post_id
		));
		$this->consumeAllMySQLResults();

		return !empty($verify_post) && (int)$verify_post === (int)$post_id;
	}

	/**
	 * Save post metadata.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $title Original title.
	 * @param array  $settings Settings.
	 * @return void
	 */
	private function savePostMetadata($post_id, $title, $settings) {
		$this->consumeAllMySQLResults();
		update_post_meta($post_id, '_aebg_generated', true);
		$this->consumeAllMySQLResults();
		update_post_meta($post_id, '_aebg_generated_at', current_time('mysql'));
		$this->consumeAllMySQLResults();
		update_post_meta($post_id, '_aebg_source_title', $title);
		$this->consumeAllMySQLResults();
	}

	/**
	 * Save post products.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $products Array of products.
	 * @return void
	 */
	private function savePostProducts($post_id, $products) {
		Logger::debug('Saving products for post', ['post_id' => $post_id, 'count' => count($products)]);

		// Save products
		$save_success = ProductManager::savePostProducts($post_id, $products);

		if ($save_success) {
			// Process product images
			$processed_products = ProductImageManager::processProductImages($products, $post_id);

			// Update products with image data
			$updated_products = [];
			foreach ($processed_products as $index => $processed_product) {
				$updated_products[] = array_merge($products[$index], $processed_product);
			}

			// Save updated products
			ProductManager::savePostProducts($post_id, $updated_products);

			// Save context
			$context_data = [
				'category' => '',
				'target_audience' => '',
				'content_type' => '',
				'search_keywords' => [],
				'attributes' => [],
				'key_topics' => [],
			];

			ProductManager::saveProductContext($post_id, $context_data);
		}
	}

	/**
	 * Save Elementor template.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $processed_template Processed template.
	 * @param array $settings Settings.
	 * @return void
	 */
	private function saveElementorTemplate($post_id, $processed_template, $settings) {
		Logger::debug('Saving Elementor template', ['post_id' => $post_id]);

		// Set Elementor meta
		$this->consumeAllMySQLResults();
		update_post_meta($post_id, '_elementor_edit_mode', 'builder');
		$this->consumeAllMySQLResults();
		update_post_meta($post_id, '_elementor_template_id', $settings['template_id'] ?? 0);
		$this->consumeAllMySQLResults();
		update_post_meta($post_id, '_elementor_template_type', 'wp-post');
		$this->consumeAllMySQLResults();
		update_post_meta($post_id, '_elementor_version', '3.31.0');
		$this->consumeAllMySQLResults();
		update_post_meta($post_id, '_elementor_page_settings', []);
		$this->consumeAllMySQLResults();
		update_post_meta($post_id, '_elementor_page_assets', []);
		$this->consumeAllMySQLResults();
		update_post_meta($post_id, '_elementor_controls_usage', []);
		$this->consumeAllMySQLResults();
		update_post_meta($post_id, '_elementor_elements_usage', []);
		$this->consumeAllMySQLResults();

		// Clean and save Elementor data
		$cleaned_data = DataUtilities::cleanElementorDataForEncoding($processed_template, true);
		$json_data = DataUtilities::safeJsonEncode($cleaned_data);

		if (!is_wp_error($json_data)) {
			$this->consumeAllMySQLResults();
			update_post_meta($post_id, '_elementor_data', $json_data);
			$this->consumeAllMySQLResults();
		} else {
			Logger::error('Failed to encode Elementor data', ['error' => $json_data->get_error_message()]);
		}
	}

	/**
	 * Force Elementor CSS generation.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function forceElementorCSSGeneration($post_id) {
		// Trigger Elementor CSS regeneration
		if (class_exists('\Elementor\Plugin')) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	/**
	 * Consume all MySQL results.
	 *
	 * @return void
	 */
	private function consumeAllMySQLResults() {
		global $wpdb;

		// Consume any pending results
		if ($wpdb->last_result) {
			while ($wpdb->get_row(null, ARRAY_A)) {
				// Consume all rows
			}
		}
	}

	/**
	 * Reset database connection.
	 *
	 * @return void
	 */
	private function resetDatabaseConnection() {
		global $wpdb;
		$wpdb->db_connect();
	}
}

