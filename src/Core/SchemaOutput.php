<?php

namespace AEBG\Core;

/**
 * Schema Output Class
 * Handles outputting Schema.org JSON-LD structured data to the frontend.
 *
 * @package AEBG\Core
 */
class SchemaOutput {
	
	/**
	 * Initialize schema output hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action('wp_head', [__CLASS__, 'output_schema'], 1);
	}
	
	/**
	 * Output schema JSON-LD to the frontend head.
	 *
	 * @return void
	 */
	public static function output_schema(): void {
		// Only output on singular posts/pages
		if (!is_singular()) {
			return;
		}
		
		$post_id = get_the_ID();
		if (!$post_id) {
			return;
		}
		
		// Get schema from post meta
		$schema_json = get_post_meta($post_id, '_aebg_schema_json', true);
		if (empty($schema_json)) {
			return;
		}
		
		// Decode and validate schema
		$schema_data = json_decode($schema_json, true);
		if (!is_array($schema_data) || empty($schema_data)) {
			Logger::warning('Invalid schema data found in post meta', [
				'post_id' => $post_id,
				'schema_json' => substr($schema_json, 0, 200)
			]);
			return;
		}
		
		// Validate schema has required fields
		if (!isset($schema_data['@context']) || !isset($schema_data['@type'])) {
			Logger::warning('Schema missing required @context or @type', [
				'post_id' => $post_id
			]);
			return;
		}
		
		// Output schema as JSON-LD script tag
		echo "\n" . '<!-- AEBG Schema.org JSON-LD -->' . "\n";
		echo '<script type="application/ld+json">' . "\n";
		echo wp_json_encode($schema_data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		echo "\n" . '</script>' . "\n";
		
		Logger::debug('Schema output to frontend', [
			'post_id' => $post_id,
			'schema_type' => $schema_data['@type'] ?? 'unknown'
		]);
	}
	
	/**
	 * Get schema for a specific post (for testing/debugging).
	 *
	 * @param int $post_id Post ID.
	 * @return array|false Schema array on success, false on failure.
	 */
	public static function get_schema(int $post_id): array|false {
		$schema_json = get_post_meta($post_id, '_aebg_schema_json', true);
		if (empty($schema_json)) {
			return false;
		}
		
		$schema_data = json_decode($schema_json, true);
		if (!is_array($schema_data)) {
			return false;
		}
		
		return $schema_data;
	}
}

