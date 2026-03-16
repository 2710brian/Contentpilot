<?php

namespace AEBG\Core;

use AEBG\Core\Logger;

/**
 * Checkpoint Manager Class
 * Handles saving, loading, and managing checkpoint state for generation jobs.
 *
 * @package AEBG\Core
 */
class CheckpointManager {
	/**
	 * Step identifiers
	 */
	const STEP_INITIAL = 'initial';
	const STEP_1_ANALYZE_TITLE = 'step_1_analyze_title';
	const STEP_2_FIND_PRODUCTS = 'step_2_find_products';
	const STEP_3_SELECT_PRODUCTS = 'step_3_select_products';
	const STEP_3_5_DISCOVER_MERCHANTS = 'step_3_5_discover_merchants';
	const STEP_3_6_PRICE_COMPARISON = 'step_3_6_price_comparison';
	const STEP_3_7_PROCESS_IMAGES = 'step_3_7_process_images';
	const STEP_4_COLLECT_PROMPTS = 'step_4_collect_prompts';
	const STEP_4_1_PROCESS_FIELDS = 'step_4_1_process_fields';
	const STEP_4_2_APPLY_CONTENT = 'step_4_2_apply_content';
	const STEP_4_PROCESS_TEMPLATE = 'step_4_process_template'; // Legacy - for backward compatibility
	const STEP_5_GENERATE_CONTENT = 'step_5_generate_content';
	const STEP_5_5_MERCHANT_COMPARISONS = 'step_5_5_merchant_comparisons';
	const STEP_6_CREATE_POST = 'step_6_create_post';
	const STEP_7_IMAGE_ENHANCEMENTS = 'step_7_image_enhancements';
	const STEP_8_SEO_ENHANCEMENTS = 'step_8_seo_enhancements';
	
	// Product replacement steps
	const STEP_REPLACE_COLLECT_PROMPTS = 'replace_collect_prompts';
	const STEP_REPLACE_PROCESS_FIELDS = 'replace_process_fields';
	const STEP_REPLACE_APPLY_CONTENT = 'replace_apply_content';
	
	// Testvinder-only regeneration steps
	const STEP_TESTVINDER_COLLECT_PROMPTS = 'testvinder_collect_prompts';
	const STEP_TESTVINDER_PROCESS_FIELDS = 'testvinder_process_fields';
	const STEP_TESTVINDER_APPLY_CONTENT = 'testvinder_apply_content';

	/**
	 * Maximum resume attempts to prevent infinite loops
	 */
	const MAX_RESUME_ATTEMPTS = 3;

	/**
	 * Maximum checkpoint state size (10MB)
	 */
	const MAX_STATE_SIZE = 10485760;

	/**
	 * Current state version for migration support
	 */
	const STATE_VERSION = '1.0';

	/**
	 * Product replacement state version
	 */
	const REPLACEMENT_STATE_VERSION = '1.0';

	/**
	 * Maximum retry attempts for product replacement
	 */
	const MAX_RETRY_ATTEMPTS = 3;

	/**
	 * Save checkpoint state
	 *
	 * @param int    $item_id Item ID.
	 * @param string $step Step identifier.
	 * @param array  $state State data to save.
	 * @return bool True on success, false on failure.
	 */
	public static function saveCheckpoint( int $item_id, string $step, array $state ): bool {
		global $wpdb;

		try {
			// Check if columns exist
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}aebg_batch_items" );
			$missing_columns = [];
			if ( ! in_array( 'checkpoint_state', $columns, true ) ) {
				$missing_columns[] = 'checkpoint_state';
			}
			if ( ! in_array( 'checkpoint_step', $columns, true ) ) {
				$missing_columns[] = 'checkpoint_step';
			}
			if ( ! in_array( 'last_checkpoint_at', $columns, true ) ) {
				$missing_columns[] = 'last_checkpoint_at';
			}
			
			// Ensure columns exist
			if ( ! empty( $missing_columns ) ) {
				if ( class_exists( 'AEBG\\Installer' ) ) {
					\AEBG\Installer::ensureCheckpointColumns();
					// Re-check columns
					$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}aebg_batch_items" );
					// If still missing, log error and return false
					if ( ! in_array( 'checkpoint_state', $columns, true ) ) {
						Logger::error( 'Checkpoint columns not available', [
							'item_id' => $item_id,
							'missing' => $missing_columns,
						] );
						return false;
					}
				} else {
					Logger::error( 'Installer class not available to create checkpoint columns', [
						'item_id' => $item_id,
					] );
					return false;
				}
			}
			// Sanitize state before saving
			$sanitized_state = self::sanitizeState( $state );

			// Build checkpoint structure
			$checkpoint = [
				'version' => self::STATE_VERSION,
				'step' => $step,
				'timestamp' => microtime( true ),
				'data' => $sanitized_state,
				'metadata' => [
					'last_checkpoint_at' => current_time( 'mysql' ),
				],
			];

			// Encode to JSON
			$checkpoint_json = json_encode( $checkpoint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			// Check size limit
			if ( strlen( $checkpoint_json ) > self::MAX_STATE_SIZE ) {
				Logger::error( 'Checkpoint state too large', [
					'item_id' => $item_id,
					'size' => strlen( $checkpoint_json ),
					'max_size' => self::MAX_STATE_SIZE,
				] );
				return false;
			}

			// Validate JSON encoding
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				Logger::error( 'Failed to encode checkpoint state', [
					'item_id' => $item_id,
					'error' => json_last_error_msg(),
				] );
				return false;
			}

			// Get current resume count
			$resume_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT resume_count FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d",
				$item_id
			) );

			// Update database
			$updated = $wpdb->update(
				"{$wpdb->prefix}aebg_batch_items",
				[
					'checkpoint_state' => $checkpoint_json,
					'checkpoint_step' => $step,
					'last_checkpoint_at' => current_time( 'mysql' ),
				],
				[ 'id' => $item_id ],
				[ '%s', '%s', '%s' ],
				[ '%d' ]
			);

			if ( $updated === false ) {
				Logger::error( 'Failed to save checkpoint', [
					'item_id' => $item_id,
					'db_error' => $wpdb->last_error,
				] );
				return false;
			}

			$state_size = strlen( $checkpoint_json );
			error_log( '[AEBG] 💾 CHECKPOINT SAVED | item_id=' . $item_id . ' | step=' . $step . ' | size=' . round( $state_size / 1024, 2 ) . ' KB' );
			Logger::debug( 'Checkpoint saved', [
				'item_id' => $item_id,
				'step' => $step,
				'size_bytes' => $state_size,
			] );

			return true;
		} catch ( \Exception $e ) {
			Logger::error( 'Exception saving checkpoint', [
				'item_id' => $item_id,
				'error' => $e->getMessage(),
			] );
			return false;
		}
	}

	/**
	 * Load checkpoint state
	 *
	 * @param int $item_id Item ID.
	 * @return array|null Checkpoint state or null if not found/invalid.
	 */
	public static function loadCheckpoint( int $item_id ): ?array {
		global $wpdb;

		try {
			// Check if column exists
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}aebg_batch_items" );
			if ( ! in_array( 'checkpoint_state', $columns, true ) ) {
				// Column doesn't exist - ensure it's created
				if ( class_exists( 'AEBG\\Installer' ) ) {
					\AEBG\Installer::ensureCheckpointColumns();
					// Re-check columns
					$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}aebg_batch_items" );
				}
				// If still doesn't exist, return null (no checkpoint available)
				if ( ! in_array( 'checkpoint_state', $columns, true ) ) {
					return null;
				}
			}

			$checkpoint_json = $wpdb->get_var( $wpdb->prepare(
				"SELECT checkpoint_state FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d",
				$item_id
			) );

			if ( empty( $checkpoint_json ) ) {
				return null;
			}

			$checkpoint = json_decode( $checkpoint_json, true );

			// Check for JSON decode errors
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				error_log( '[AEBG] ⚠️ Corrupted checkpoint detected - clearing | item_id=' . $item_id . ' | error=' . json_last_error_msg() );
				self::clearCheckpoint( $item_id );
				return null;
			}

			// Validate checkpoint structure
			if ( ! self::validateState( $checkpoint, $checkpoint['step'] ?? '' ) ) {
				error_log( '[AEBG] ⚠️ Invalid checkpoint state - clearing | item_id=' . $item_id );
				self::clearCheckpoint( $item_id );
				return null;
			}

			// Get resume count
			$resume_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT resume_count FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d",
				$item_id
			) );

			error_log( '[AEBG] 🔄 CHECKPOINT LOADED | item_id=' . $item_id . ' | step=' . ( $checkpoint['step'] ?? 'unknown' ) . ' | resume_count=' . $resume_count );

			return $checkpoint;
		} catch ( \Exception $e ) {
			Logger::error( 'Exception loading checkpoint', [
				'item_id' => $item_id,
				'error' => $e->getMessage(),
			] );
			return null;
		}
	}

	/**
	 * Get checkpoint state data (returns the data portion of the checkpoint)
	 *
	 * @param int $item_id Item ID.
	 * @return array|null Checkpoint state data or null if not found/invalid.
	 */
	public static function getCheckpointState( int $item_id ): ?array {
		$checkpoint = self::loadCheckpoint( $item_id );
		
		if ( $checkpoint === null ) {
			return null;
		}
		
		// Return the data portion of the checkpoint
		return $checkpoint['data'] ?? null;
	}

	/**
	 * Clear checkpoint (after successful completion or permanent failure)
	 *
	 * @param int $item_id Item ID.
	 * @return bool True on success, false on failure.
	 */
	public static function clearCheckpoint( int $item_id ): bool {
		global $wpdb;

		try {
			$updated = $wpdb->update(
				"{$wpdb->prefix}aebg_batch_items",
				[
					'checkpoint_state' => null,
					'checkpoint_step' => null,
					'last_checkpoint_at' => null,
				],
				[ 'id' => $item_id ],
				[ null, null, null ],
				[ '%d' ]
			);

			if ( $updated !== false ) {
				error_log( '[AEBG] 🗑️ CHECKPOINT CLEARED | item_id=' . $item_id );
				Logger::debug( 'Checkpoint cleared', [ 'item_id' => $item_id ] );
			}

			return $updated !== false;
		} catch ( \Exception $e ) {
			Logger::error( 'Exception clearing checkpoint', [
				'item_id' => $item_id,
				'error' => $e->getMessage(),
			] );
			return false;
		}
	}

	/**
	 * Check if resume is allowed (not exceeded max attempts)
	 *
	 * @param int $item_id Item ID.
	 * @return bool True if resume is allowed, false otherwise.
	 */
	public static function canResume( int $item_id ): bool {
		global $wpdb;

		// Check if column exists
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}aebg_batch_items" );
		if ( ! in_array( 'resume_count', $columns, true ) ) {
			// Column doesn't exist - ensure it's created
			if ( class_exists( 'AEBG\\Installer' ) ) {
				\AEBG\Installer::ensureCheckpointColumns();
				// Re-check columns
				$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}aebg_batch_items" );
			}
			// If still doesn't exist, return false (can't resume without column)
			if ( ! in_array( 'resume_count', $columns, true ) ) {
				return false;
			}
		}

		$resume_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT resume_count FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d",
			$item_id
		) );

		return $resume_count < self::MAX_RESUME_ATTEMPTS;
	}

	/**
	 * Increment resume count
	 *
	 * @param int $item_id Item ID.
	 * @return bool True on success, false on failure.
	 */
	public static function incrementResumeCount( int $item_id ): bool {
		global $wpdb;

		try {
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}aebg_batch_items SET resume_count = resume_count + 1 WHERE id = %d",
				$item_id
			) );

			$resume_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT resume_count FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d",
				$item_id
			) );

			error_log( '[AEBG] 📈 RESUME COUNT INCREMENTED | item_id=' . $item_id . ' | count=' . $resume_count );

			return $updated !== false;
		} catch ( \Exception $e ) {
			Logger::error( 'Exception incrementing resume count', [
				'item_id' => $item_id,
				'error' => $e->getMessage(),
			] );
			return false;
		}
	}

	/**
	 * Get current step for an item
	 *
	 * @param int $item_id Item ID.
	 * @return string|null Step identifier or null if not found.
	 */
	public static function getCurrentStep( int $item_id ): ?string {
		global $wpdb;

		// Check if column exists
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}aebg_batch_items" );
		if ( ! in_array( 'checkpoint_step', $columns, true ) ) {
			// Column doesn't exist - ensure it's created
			if ( class_exists( 'AEBG\\Installer' ) ) {
				\AEBG\Installer::ensureCheckpointColumns();
			}
			return null;
		}

		$step = $wpdb->get_var( $wpdb->prepare(
			"SELECT checkpoint_step FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d",
			$item_id
		) );

		return $step ?: null;
	}

	/**
	 * Validate checkpoint state structure
	 *
	 * @param array  $state Checkpoint state.
	 * @param string $step Step identifier.
	 * @return bool True if valid, false otherwise.
	 */
	private static function validateState( array $state, string $step ): bool {
		// Check required top-level fields
		if ( ! isset( $state['version'] ) || ! isset( $state['step'] ) || ! isset( $state['data'] ) ) {
			return false;
		}

		// Validate version
		if ( version_compare( $state['version'], self::STATE_VERSION, '<' ) ) {
			return false;
		}

		// Validate step matches
		if ( $state['step'] !== $step ) {
			return false;
		}

		// Validate data is array
		if ( ! is_array( $state['data'] ) ) {
			return false;
		}

		// Check required fields based on step
		$required_fields = self::getRequiredFieldsForStep( $step );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $state['data'][ $field ] ) ) {
				Logger::warning( 'Checkpoint missing required field', [
					'step' => $step,
					'field' => $field,
				] );
				return false;
			}
		}

		return true;
	}

	/**
	 * Get required fields for a step
	 *
	 * @param string $step Step identifier.
	 * @return array Required field names.
	 */
	private static function getRequiredFieldsForStep( string $step ): array {
		$required_fields = [
			self::STEP_1_ANALYZE_TITLE => [ 'title', 'template_id', 'context', 'settings' ],
			self::STEP_2_FIND_PRODUCTS => [ 'title', 'template_id', 'context', 'products', 'settings' ],
			self::STEP_3_SELECT_PRODUCTS => [ 'title', 'template_id', 'context', 'products', 'selected_products', 'settings' ],
			self::STEP_3_5_DISCOVER_MERCHANTS => [ 'title', 'template_id', 'context', 'selected_products', 'settings' ],
			self::STEP_3_6_PRICE_COMPARISON => [ 'title', 'template_id', 'context', 'selected_products', 'settings' ],
			self::STEP_3_7_PROCESS_IMAGES => [ 'title', 'template_id', 'context', 'selected_products', 'settings' ],
			self::STEP_4_COLLECT_PROMPTS => [ 'title', 'template_id', 'context', 'selected_products', 'settings', 'elementor_data' ],
			self::STEP_4_1_PROCESS_FIELDS => [ 'title', 'template_id', 'context', 'selected_products', 'settings', 'elementor_data', 'context_registry_state' ],
			self::STEP_4_2_APPLY_CONTENT => [ 'title', 'template_id', 'context', 'selected_products', 'settings', 'elementor_data', 'context_registry_state' ],
			self::STEP_4_PROCESS_TEMPLATE => [ 'title', 'template_id', 'context', 'selected_products', 'settings' ], // Legacy
			self::STEP_5_GENERATE_CONTENT => [ 'title', 'template_id', 'context', 'selected_products', 'settings' ],
			self::STEP_5_5_MERCHANT_COMPARISONS => [ 'title', 'template_id', 'context', 'selected_products', 'settings' ],
			self::STEP_6_CREATE_POST => [ 'title', 'template_id', 'context', 'selected_products', 'settings', 'post_id' ],
			self::STEP_7_IMAGE_ENHANCEMENTS => [ 'title', 'template_id', 'post_id', 'settings' ],
			self::STEP_8_SEO_ENHANCEMENTS => [ 'title', 'template_id', 'post_id', 'settings' ],
		];

		return $required_fields[ $step ] ?? [ 'title', 'template_id', 'settings' ];
	}

	/**
	 * Sanitize state data before saving
	 *
	 * @param array $state State data.
	 * @return array Sanitized state data.
	 */
	private static function sanitizeState( array $state ): array {
		$sanitized = [];

		// Sanitize title
		if ( isset( $state['title'] ) ) {
			$sanitized['title'] = sanitize_text_field( $state['title'] );
		}

		// Sanitize template_id
		if ( isset( $state['template_id'] ) ) {
			$sanitized['template_id'] = absint( $state['template_id'] );
		}

		// Copy context (already sanitized by TitleAnalyzer)
		if ( isset( $state['context'] ) && is_array( $state['context'] ) ) {
			$sanitized['context'] = $state['context'];
		}

		// Sanitize products array
		if ( isset( $state['products'] ) && is_array( $state['products'] ) ) {
			$sanitized['products'] = array_map( [ self::class, 'sanitizeProduct' ], $state['products'] );
		}

		// Sanitize selected_products array
		if ( isset( $state['selected_products'] ) && is_array( $state['selected_products'] ) ) {
			$sanitized['selected_products'] = array_map( [ self::class, 'sanitizeProduct' ], $state['selected_products'] );
		}

		// Copy processed_template (Elementor data - already validated)
		if ( isset( $state['processed_template'] ) ) {
			$sanitized['processed_template'] = $state['processed_template'];
		}
		
		// Copy processed_template_after_ai_fields (for Step 4 resume)
		if ( isset( $state['processed_template_after_ai_fields'] ) ) {
			$sanitized['processed_template_after_ai_fields'] = $state['processed_template_after_ai_fields'];
		}
		
		// Copy processed_template_partial (for Step 4 resume during apply phase)
		if ( isset( $state['processed_template_partial'] ) ) {
			$sanitized['processed_template_partial'] = $state['processed_template_partial'];
		}
		
		// Copy apply_phase_started flag (for Step 4 resume)
		if ( isset( $state['apply_phase_started'] ) ) {
			$sanitized['apply_phase_started'] = (bool) $state['apply_phase_started'];
		}

		// Sanitize content
		if ( isset( $state['content'] ) ) {
			$sanitized['content'] = wp_kses_post( $state['content'] );
		}

		// Copy settings (already validated)
		if ( isset( $state['settings'] ) && is_array( $state['settings'] ) ) {
			$sanitized['settings'] = $state['settings'];
			// Never store API keys in checkpoint
			unset( $sanitized['settings']['api_key'] );
			unset( $sanitized['settings']['openai_api_key'] );
		}

		// Copy ai_model
		if ( isset( $state['ai_model'] ) ) {
			$sanitized['ai_model'] = sanitize_text_field( $state['ai_model'] );
		}

		// Copy author_id
		if ( isset( $state['author_id'] ) ) {
			$sanitized['author_id'] = absint( $state['author_id'] );
		}

		// CRITICAL: Copy post_id (required for Steps 7 & 8)
		if ( isset( $state['post_id'] ) ) {
			$sanitized['post_id'] = absint( $state['post_id'] );
		}

		// Copy image_enhancement_results (for Step 7)
		if ( isset( $state['image_enhancement_results'] ) && is_array( $state['image_enhancement_results'] ) ) {
			$sanitized['image_enhancement_results'] = $state['image_enhancement_results'];
		}
		
		// CRITICAL: Copy seo_progress (for Step 8 incremental checkpointing)
		if ( isset( $state['seo_progress'] ) && is_array( $state['seo_progress'] ) ) {
			$sanitized['seo_progress'] = $state['seo_progress'];
		}
		
		// CRITICAL: Copy results (for Step 8 to preserve completed API call results)
		if ( isset( $state['results'] ) && is_array( $state['results'] ) ) {
			$sanitized['results'] = $state['results'];
		}
		
		// CRITICAL: Copy image_progress (for Step 7 incremental checkpointing)
		if ( isset( $state['image_progress'] ) && is_array( $state['image_progress'] ) ) {
			$sanitized['image_progress'] = $state['image_progress'];
		}
		
		// CRITICAL: Copy elementor_data (for Step 4 split phases)
		if ( isset( $state['elementor_data'] ) ) {
			$sanitized['elementor_data'] = $state['elementor_data'];
		}
		
		// CRITICAL: Copy processed_template (for Step 5 - final processed Elementor template)
		if ( isset( $state['processed_template'] ) ) {
			$sanitized['processed_template'] = $state['processed_template'];
		}
		
		// CRITICAL: Copy context_registry_state (for Step 4.1 to preserve collected prompts and processed results)
		if ( isset( $state['context_registry_state'] ) && is_array( $state['context_registry_state'] ) ) {
			$sanitized['context_registry_state'] = $state['context_registry_state'];
		}
		
		// CRITICAL: Copy field_processing_progress (for Step 4.1 incremental checkpointing)
		if ( isset( $state['field_processing_progress'] ) && is_array( $state['field_processing_progress'] ) ) {
			$sanitized['field_processing_progress'] = $state['field_processing_progress'];
		}

		return $sanitized;
	}

	/**
	 * Sanitize product data
	 *
	 * @param array|mixed $product Product data.
	 * @return array|mixed Sanitized product data.
	 */
	private static function sanitizeProduct( $product ) {
		if ( ! is_array( $product ) ) {
			return $product;
		}

		$sanitized = [];

		// Sanitize string fields
		$string_fields = [ 'name', 'title', 'description', 'url', 'image_url', 'merchant_name', 'merchant_url' ];
		foreach ( $string_fields as $field ) {
			if ( isset( $product[ $field ] ) ) {
				$sanitized[ $field ] = sanitize_text_field( $product[ $field ] );
			}
		}

		// Sanitize numeric fields
		$numeric_fields = [ 'price', 'rating', 'review_count', 'product_id' ];
		foreach ( $numeric_fields as $field ) {
			if ( isset( $product[ $field ] ) ) {
				$sanitized[ $field ] = is_numeric( $product[ $field ] ) ? floatval( $product[ $field ] ) : $product[ $field ];
			}
		}

		// Copy arrays (merchants, comparisons, etc.)
		$array_fields = [ 'merchants', 'comparisons', 'images', 'variants' ];
		foreach ( $array_fields as $field ) {
			if ( isset( $product[ $field ] ) && is_array( $product[ $field ] ) ) {
				$sanitized[ $field ] = $product[ $field ];
			}
		}

		// Copy all other fields as-is (may contain complex data structures)
		foreach ( $product as $key => $value ) {
			if ( ! isset( $sanitized[ $key ] ) ) {
				$sanitized[ $key ] = $value;
			}
		}

		return $sanitized;
	}

	/**
	 * Get checkpoint count for an item.
	 *
	 * @param int $item_id Item ID.
	 * @return int Checkpoint count.
	 */
	public static function getCheckpointCount( int $item_id ): int {
		global $wpdb;
		
		$item = $wpdb->get_row( $wpdb->prepare(
			"SELECT checkpoint_state, last_checkpoint_at FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d",
			$item_id
		), ARRAY_A );
		
		if ( ! $item || empty( $item['checkpoint_state'] ) ) {
			return 0;
		}
		
		// Count how many times checkpoint was saved (rough estimate based on state size)
		// For more accurate count, we'd need to track this separately
		return $item['last_checkpoint_at'] ? 1 : 0;
	}

	/**
	 * Get resume count for an item.
	 *
	 * @param int $item_id Item ID.
	 * @return int Resume count.
	 */
	public static function getResumeCount( int $item_id ): int {
		global $wpdb;
		
		$resume_count = $wpdb->get_var( $wpdb->prepare(
			"SELECT resume_count FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d",
			$item_id
		) );
		
		return (int) ( $resume_count ?? 0 );
	}

	/**
	 * Cleanup old checkpoints (older than specified days)
	 *
	 * @param int $days Number of days to keep checkpoints.
	 * @return int Number of checkpoints cleaned up.
	 */
	public static function cleanupOldCheckpoints( int $days = 7 ): int {
		global $wpdb;

		try {
			$deleted = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}aebg_batch_items 
				SET checkpoint_state = NULL, checkpoint_step = NULL, last_checkpoint_at = NULL 
				WHERE last_checkpoint_at < DATE_SUB(NOW(), INTERVAL %d DAY)
				AND checkpoint_state IS NOT NULL",
				$days
			) );

			if ( $deleted !== false ) {
				error_log( '[AEBG] 🧹 CLEANUP: Removed ' . $deleted . ' old checkpoint(s) older than ' . $days . ' days' );
			}

			return $deleted !== false ? $deleted : 0;
		} catch ( \Exception $e ) {
			Logger::error( 'Exception cleaning up old checkpoints', [
				'error' => $e->getMessage(),
			] );
			return 0;
		}
	}

	/**
	 * Save product replacement checkpoint state
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $product_number Product number (1-based).
	 * @param string $step Step identifier.
	 * @param array  $state State data to save.
	 * @param string $checkpoint_type Checkpoint type ('product' or 'testvinder', default: 'product').
	 * @return bool True on success, false on failure.
	 */
	public static function saveReplacementCheckpoint( int $post_id, int $product_number, string $step, array $state, string $checkpoint_type = 'product' ): bool {
		global $wpdb;

		try {
			// Validate post_id and product_number
			if ( $post_id <= 0 || $product_number <= 0 ) {
				Logger::error( 'Invalid post_id or product_number for replacement checkpoint', [
					'post_id' => $post_id,
					'product_number' => $product_number,
				] );
				return false;
			}

			// Sanitize state before saving
			$sanitized_state = self::sanitizeReplacementState( $state );

			// Check state size
			$json_state = wp_json_encode( $sanitized_state );
			if ( strlen( $json_state ) > self::MAX_STATE_SIZE ) {
				Logger::error( 'Replacement checkpoint state too large', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'size' => strlen( $json_state ),
					'max_size' => self::MAX_STATE_SIZE,
				] );
				return false;
			}

			// Ensure table exists
			if ( class_exists( 'AEBG\\Installer' ) ) {
				\AEBG\Installer::ensureProductReplacementCheckpointsTable();
			}

			// Prepare checkpoint data with version
			$checkpoint_data = [
				'version' => self::REPLACEMENT_STATE_VERSION,
				'step' => $step,
				'data' => $sanitized_state,
			];

			$table_name = $wpdb->prefix . 'aebg_product_replacement_checkpoints';
			$json_data = wp_json_encode( $checkpoint_data );

			// Use INSERT ... ON DUPLICATE KEY UPDATE for atomic upsert
			$result = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$table_name} 
				(post_id, product_number, checkpoint_step, checkpoint_state, last_checkpoint_at, updated_at)
				VALUES (%d, %d, %s, %s, NOW(), NOW())
				ON DUPLICATE KEY UPDATE
				checkpoint_step = VALUES(checkpoint_step),
				checkpoint_state = VALUES(checkpoint_state),
				last_checkpoint_at = VALUES(last_checkpoint_at),
				updated_at = VALUES(updated_at)",
				$post_id,
				$product_number,
				$step,
				$json_data
			) );

			if ( $result === false ) {
				Logger::error( 'Failed to save replacement checkpoint', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'step' => $step,
					'error' => $wpdb->last_error,
				] );
				return false;
			}

			Logger::debug( 'Replacement checkpoint saved', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'step' => $step,
			] );

			return true;
		} catch ( \Exception $e ) {
			Logger::error( 'Exception saving replacement checkpoint', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'step' => $step,
				'error' => $e->getMessage(),
			] );
			return false;
		}
	}

	/**
	 * Load product replacement checkpoint state
	 *
	 * @param int $post_id Post ID.
	 * @param int $product_number Product number (1-based).
	 * @return array|null Checkpoint state or null if not found/invalid.
	 */
	public static function loadReplacementCheckpoint( int $post_id, int $product_number ): ?array {
		global $wpdb;

		try {
			$table_name = $wpdb->prefix . 'aebg_product_replacement_checkpoints';
			
			$checkpoint = $wpdb->get_row( $wpdb->prepare(
				"SELECT checkpoint_step, checkpoint_state, resume_count, retry_count, last_checkpoint_at
				FROM {$table_name}
				WHERE post_id = %d AND product_number = %d
				LIMIT 1",
				$post_id,
				$product_number
			), ARRAY_A );

			if ( ! $checkpoint || empty( $checkpoint['checkpoint_state'] ) ) {
				return null;
			}

			// Decode JSON
			$state = json_decode( $checkpoint['checkpoint_state'], true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				Logger::error( 'Failed to decode replacement checkpoint JSON', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'json_error' => json_last_error_msg(),
				] );
				return null;
			}

			// Validate checkpoint structure
			if ( ! self::validateReplacementCheckpoint( $state, $checkpoint['checkpoint_step'] ) ) {
				Logger::warning( 'Invalid replacement checkpoint structure', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'step' => $checkpoint['checkpoint_step'],
				] );
				return null;
			}

			// Add metadata
			$state['_metadata'] = [
				'resume_count' => (int) $checkpoint['resume_count'],
				'retry_count' => (int) $checkpoint['retry_count'],
				'last_checkpoint_at' => $checkpoint['last_checkpoint_at'],
			];

			return $state;
		} catch ( \Exception $e ) {
			Logger::error( 'Exception loading replacement checkpoint', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'error' => $e->getMessage(),
			] );
			return null;
		}
	}

	/**
	 * Clear product replacement checkpoint
	 *
	 * @param int $post_id Post ID.
	 * @param int $product_number Product number (1-based).
	 * @return bool True on success, false on failure.
	 */
	public static function clearReplacementCheckpoint( int $post_id, int $product_number ): bool {
		global $wpdb;

		try {
			$table_name = $wpdb->prefix . 'aebg_product_replacement_checkpoints';
			
			$result = $wpdb->delete(
				$table_name,
				[
					'post_id' => $post_id,
					'product_number' => $product_number,
				],
				[ '%d', '%d' ]
			);

			if ( $result === false ) {
				Logger::error( 'Failed to clear replacement checkpoint', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'error' => $wpdb->last_error,
				] );
				return false;
			}

			Logger::debug( 'Replacement checkpoint cleared', [
				'post_id' => $post_id,
				'product_number' => $product_number,
			] );

			return true;
		} catch ( \Exception $e ) {
			Logger::error( 'Exception clearing replacement checkpoint', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'error' => $e->getMessage(),
			] );
			return false;
		}
	}

	/**
	 * Get resume count for product replacement
	 *
	 * @param int $post_id Post ID.
	 * @param int $product_number Product number (1-based).
	 * @return int Resume count.
	 */
	public static function getReplacementResumeCount( int $post_id, int $product_number ): int {
		global $wpdb;

		try {
			$table_name = $wpdb->prefix . 'aebg_product_replacement_checkpoints';
			
			$resume_count = $wpdb->get_var( $wpdb->prepare(
				"SELECT resume_count FROM {$table_name}
				WHERE post_id = %d AND product_number = %d
				LIMIT 1",
				$post_id,
				$product_number
			) );

			return (int) ( $resume_count ?? 0 );
		} catch ( \Exception $e ) {
			Logger::error( 'Exception getting replacement resume count', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'error' => $e->getMessage(),
			] );
			return 0;
		}
	}

	/**
	 * Increment resume count for product replacement
	 *
	 * @param int $post_id Post ID.
	 * @param int $product_number Product number (1-based).
	 * @return bool True on success, false on failure.
	 */
	public static function incrementReplacementResumeCount( int $post_id, int $product_number ): bool {
		global $wpdb;

		try {
			$table_name = $wpdb->prefix . 'aebg_product_replacement_checkpoints';
			
			$result = $wpdb->query( $wpdb->prepare(
				"UPDATE {$table_name}
				SET resume_count = resume_count + 1, updated_at = NOW()
				WHERE post_id = %d AND product_number = %d",
				$post_id,
				$product_number
			) );

			if ( $result === false ) {
				Logger::error( 'Failed to increment replacement resume count', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'error' => $wpdb->last_error,
				] );
				return false;
			}

			return true;
		} catch ( \Exception $e ) {
			Logger::error( 'Exception incrementing replacement resume count', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'error' => $e->getMessage(),
			] );
			return false;
		}
	}

	/**
	 * Check if product replacement can resume
	 *
	 * @param int $post_id Post ID.
	 * @param int $product_number Product number (1-based).
	 * @return bool True if can resume, false otherwise.
	 */
	public static function canResumeReplacement( int $post_id, int $product_number ): bool {
		$resume_count = self::getReplacementResumeCount( $post_id, $product_number );
		return $resume_count < self::MAX_RESUME_ATTEMPTS;
	}

	/**
	 * Get retry count for product replacement
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $product_number Product number (1-based).
	 * @param string $step Step identifier.
	 * @return int Retry count.
	 */
	public static function getReplacementRetryCount( int $post_id, int $product_number, string $step ): int {
		global $wpdb;

		try {
			$table_name = $wpdb->prefix . 'aebg_product_replacement_checkpoints';
			
			$retry_count = $wpdb->get_var( $wpdb->prepare(
				"SELECT retry_count FROM {$table_name}
				WHERE post_id = %d AND product_number = %d
				LIMIT 1",
				$post_id,
				$product_number
			) );

			return (int) ( $retry_count ?? 0 );
		} catch ( \Exception $e ) {
			Logger::error( 'Exception getting replacement retry count', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'step' => $step,
				'error' => $e->getMessage(),
			] );
			return 0;
		}
	}

	/**
	 * Increment retry count for product replacement
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $product_number Product number (1-based).
	 * @param string $step Step identifier.
	 * @return bool True on success, false on failure.
	 */
	public static function incrementReplacementRetryCount( int $post_id, int $product_number, string $step ): bool {
		global $wpdb;

		try {
			$table_name = $wpdb->prefix . 'aebg_product_replacement_checkpoints';
			
			$result = $wpdb->query( $wpdb->prepare(
				"UPDATE {$table_name}
				SET retry_count = retry_count + 1, updated_at = NOW()
				WHERE post_id = %d AND product_number = %d",
				$post_id,
				$product_number
			) );

			if ( $result === false ) {
				Logger::error( 'Failed to increment replacement retry count', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'step' => $step,
					'error' => $wpdb->last_error,
				] );
				return false;
			}

			Logger::info( 'Replacement retry count incremented', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'step' => $step,
				'retry_count' => self::getReplacementRetryCount( $post_id, $product_number, $step ),
			] );

			return true;
		} catch ( \Exception $e ) {
			Logger::error( 'Exception incrementing replacement retry count', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'step' => $step,
				'error' => $e->getMessage(),
			] );
			return false;
		}
	}

	/**
	 * Reset retry count for product replacement
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $product_number Product number (1-based).
	 * @param string $step Step identifier.
	 * @return bool True on success, false on failure.
	 */
	public static function resetReplacementRetryCount( int $post_id, int $product_number, string $step ): bool {
		global $wpdb;

		try {
			$table_name = $wpdb->prefix . 'aebg_product_replacement_checkpoints';
			
			$result = $wpdb->query( $wpdb->prepare(
				"UPDATE {$table_name}
				SET retry_count = 0, updated_at = NOW()
				WHERE post_id = %d AND product_number = %d",
				$post_id,
				$product_number
			) );

			return $result !== false;
		} catch ( \Exception $e ) {
			Logger::error( 'Exception resetting replacement retry count', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'step' => $step,
				'error' => $e->getMessage(),
			] );
			return false;
		}
	}

	/**
	 * Validate product replacement checkpoint structure
	 *
	 * @param array  $checkpoint Checkpoint data.
	 * @param string $step Expected step identifier.
	 * @return bool True if valid, false otherwise.
	 */
	private static function validateReplacementCheckpoint( array $checkpoint, string $step ): bool {
		// Check required top-level fields
		if ( ! isset( $checkpoint['version'] ) || ! isset( $checkpoint['step'] ) || ! isset( $checkpoint['data'] ) ) {
			return false;
		}

		// Validate version
		if ( version_compare( $checkpoint['version'], self::REPLACEMENT_STATE_VERSION, '<' ) ) {
			return false;
		}

		// Validate step matches
		if ( $checkpoint['step'] !== $step ) {
			return false;
		}

		// Validate data is array
		if ( ! is_array( $checkpoint['data'] ) ) {
			return false;
		}

		// Check required fields based on step
		$required_fields = self::getRequiredFieldsForReplacementStep( $step );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $checkpoint['data'][ $field ] ) ) {
				Logger::warning( 'Replacement checkpoint missing required field', [
					'step' => $step,
					'field' => $field,
				] );
				return false;
			}
		}

		return true;
	}

	/**
	 * Get required fields for a product replacement step
	 *
	 * @param string $step Step identifier.
	 * @return array Required field names.
	 */
	private static function getRequiredFieldsForReplacementStep( string $step ): array {
		$required_fields = [
			self::STEP_REPLACE_COLLECT_PROMPTS => [ 'post_id', 'product_number', 'elementor_data', 'container', 'context_registry_state', 'settings' ],
			self::STEP_REPLACE_PROCESS_FIELDS => [ 'post_id', 'product_number', 'elementor_data', 'container', 'context_registry_state', 'settings' ],
			self::STEP_REPLACE_APPLY_CONTENT => [ 'post_id', 'product_number', 'elementor_data', 'container', 'context_registry_state', 'settings' ],
		];

		return $required_fields[ $step ] ?? [ 'post_id', 'product_number', 'settings' ];
	}

	/**
	 * Sanitize product replacement state data before saving
	 *
	 * @param array $state State data.
	 * @return array Sanitized state data.
	 */
	private static function sanitizeReplacementState( array $state ): array {
		$sanitized = [];

		// Sanitize post_id
		if ( isset( $state['post_id'] ) ) {
			$sanitized['post_id'] = absint( $state['post_id'] );
		}

		// Sanitize product_number
		if ( isset( $state['product_number'] ) ) {
			$sanitized['product_number'] = absint( $state['product_number'] );
		}

		// Validate elementor_data structure (is_array)
		if ( isset( $state['elementor_data'] ) ) {
			if ( is_array( $state['elementor_data'] ) ) {
				$sanitized['elementor_data'] = $state['elementor_data'];
			} else {
				Logger::warning( 'Invalid elementor_data structure in replacement checkpoint', [
					'type' => gettype( $state['elementor_data'] ),
				] );
			}
		}

		// Validate context_registry_state (is_array)
		if ( isset( $state['context_registry_state'] ) ) {
			if ( is_array( $state['context_registry_state'] ) ) {
				$sanitized['context_registry_state'] = $state['context_registry_state'];
			} else {
				Logger::warning( 'Invalid context_registry_state structure in replacement checkpoint', [
					'type' => gettype( $state['context_registry_state'] ),
				] );
			}
		}

		// Validate container structure (is_array)
		if ( isset( $state['container'] ) ) {
			if ( is_array( $state['container'] ) ) {
				$sanitized['container'] = $state['container'];
			} else {
				Logger::warning( 'Invalid container structure in replacement checkpoint', [
					'type' => gettype( $state['container'] ),
				] );
			}
		}

		// Validate testvinder_container structure (is_array or null)
		if ( isset( $state['testvinder_container'] ) ) {
			if ( is_array( $state['testvinder_container'] ) || $state['testvinder_container'] === null ) {
				$sanitized['testvinder_container'] = $state['testvinder_container'];
			} else {
				Logger::warning( 'Invalid testvinder_container structure in replacement checkpoint', [
					'type' => gettype( $state['testvinder_container'] ),
				] );
			}
		}

		// Copy has_testvinder flag (boolean)
		if ( isset( $state['has_testvinder'] ) ) {
			$sanitized['has_testvinder'] = (bool) $state['has_testvinder'];
		}

		// Copy job_start_time (for timeout tracking)
		if ( isset( $state['job_start_time'] ) ) {
			$sanitized['job_start_time'] = is_numeric( $state['job_start_time'] ) ? (float) $state['job_start_time'] : $state['job_start_time'];
		}

		// Copy field_processing_progress (for Step 2 incremental checkpointing)
		if ( isset( $state['field_processing_progress'] ) && is_array( $state['field_processing_progress'] ) ) {
			$sanitized['field_processing_progress'] = $state['field_processing_progress'];
		}

		// Copy current_field_index (for Step 2 incremental checkpointing)
		if ( isset( $state['current_field_index'] ) ) {
			$sanitized['current_field_index'] = is_numeric( $state['current_field_index'] ) ? (int) $state['current_field_index'] : $state['current_field_index'];
		}

		// Copy settings (already validated) - remove API keys
		if ( isset( $state['settings'] ) && is_array( $state['settings'] ) ) {
			$sanitized['settings'] = $state['settings'];
			// Never store API keys in checkpoint
			unset( $sanitized['settings']['api_key'] );
			unset( $sanitized['settings']['openai_api_key'] );
		}

		return $sanitized;
	}

	/**
	 * Detect corrupted checkpoint
	 *
	 * @param array  $checkpoint Checkpoint data.
	 * @param string $expected_step Expected step identifier.
	 * @return bool True if corrupted, false otherwise.
	 */
	public static function isReplacementCheckpointCorrupted( array $checkpoint, string $expected_step ): bool {
		// Check if checkpoint structure is valid
		if ( ! isset( $checkpoint['version'] ) || ! isset( $checkpoint['step'] ) || ! isset( $checkpoint['data'] ) ) {
			return true;
		}

		// Check if step matches expected step
		if ( $checkpoint['step'] !== $expected_step ) {
			return true;
		}

		// Check if data is array
		if ( ! is_array( $checkpoint['data'] ) ) {
			return true;
		}

		// Check if version is compatible
		if ( version_compare( $checkpoint['version'], self::REPLACEMENT_STATE_VERSION, '<' ) ) {
			return true;
		}

		return false;
	}
}

