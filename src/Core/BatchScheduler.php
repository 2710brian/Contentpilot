<?php

namespace AEBG\Core;

use AEBG\Core\Generator;
use AEBG\Core\ActionSchedulerHelper;
use AEBG\Core\Logger;
use AEBG\Core\StepHandler;

/**
 * Batch Scheduler Class
 *
 * @package AEBG\Core
 */
class BatchScheduler {
	/**
	 * BatchScheduler constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_aebg_schedule_batch', [ $this, 'ajax_schedule_batch' ] );
		add_action( 'wp_ajax_aebg_validate_template', [ $this, 'ajax_validate_template' ] );
		add_action( 'wp_ajax_aebg_check_duplicate_titles', [ $this, 'ajax_check_duplicate_titles' ] );
	}

	/**
	 * AJAX schedule batch.
	 */
	public function ajax_schedule_batch() {
		// Only apply rate limiting for user-initiated requests, not background processes
		// Check if this is a background process by looking for specific headers or user agent
		$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
		$is_background = (
			empty($user_agent) || 
			strpos($user_agent, 'WordPress') !== false ||
			strpos($user_agent, 'wp-cron') !== false ||
			(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest' && empty($_SERVER['HTTP_REFERER']))
		);
		
		if ($is_background) {
			Logger::debug( 'Background process detected, skipping rate limiting' );
			// Continue with processing but don't set rate limiting
		} else {
			// Check for duplicate requests within 1 second (reduced from 2 seconds)
			$user_id = get_current_user_id();
			$request_key = 'aebg_batch_request_' . $user_id;
			$last_request_time = get_transient($request_key);
			
			if ($last_request_time && (time() - $last_request_time) < 1) {
				Logger::warning( 'Duplicate batch request detected', [
					'user_id' => $user_id,
				] );
				wp_send_json_error(['message' => 'Please wait a moment before trying again.'], 429);
			}
			
			// Set the request timestamp with shorter expiration (reduced from 10 to 3 seconds)
			set_transient($request_key, time(), 3);
		}
		
		$request_id = uniqid('aebg_', true);
		
		// Check capability
		if ( ! current_user_can( 'aebg_generate_content' ) ) {
			Logger::warning( 'Permission denied', [
				'user_id' => get_current_user_id(),
			] );
			wp_send_json_error( [ 'message' => __( 'Permission denied. You need the aebg_generate_content capability.', 'aebg' ) ], 403 );
		}
		
		// Verify nonce
		if ( ! check_ajax_referer( 'aebg_schedule_batch', '_ajax_nonce', false ) ) {
			Logger::warning( 'Nonce verification failed', [
				'request_id' => $request_id,
			] );
			wp_send_json_error( [ 'message' => __( 'Security check failed. Please refresh the page and try again.', 'aebg' ) ], 403 );
		}

		$settings                  = isset( $_POST['settings'] ) ? json_decode( stripslashes( $_POST['settings'] ), true ) : [];
		$settings['template_id'] = (int) $settings['template_id'];
		$titles                    = isset( $_POST['titles'] ) ? json_decode( stripslashes( $_POST['titles'] ) ) : [];
		$titles                    = array_map( 'sanitize_text_field', $titles );

		if ( empty( $titles ) ) {
			Logger::warning( 'No titles provided', [
				'request_id' => $request_id,
			] );
			wp_send_json_error( [ 'message' => __( 'No titles provided.', 'aebg' ) ] );
		}

		Logger::info( 'Scheduling batch', [
			'request_id' => $request_id,
			'title_count' => count($titles),
		] );

		$batch_id = $this->schedule_batch( $settings, $titles, $request_id );

		if ( is_wp_error( $batch_id ) ) {
			Logger::error( 'Batch scheduling failed', [
				'request_id' => $request_id,
				'error' => $batch_id->get_error_message(),
			] );
			wp_send_json_error( [ 'message' => $batch_id->get_error_message() ] );
		}

		Logger::info( 'Batch scheduled successfully', [
			'batch_id' => $batch_id,
			'request_id' => $request_id,
		] );
		wp_send_json_success( [ 'batch_id' => $batch_id ] );
	}

	/**
	 * AJAX validate template.
	 */
	public function ajax_validate_template() {
		// Check capability
		if ( ! current_user_can( 'aebg_generate_content' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'aebg' ) ], 403 );
		}
		
		// Verify nonce
		if ( ! check_ajax_referer( 'aebg_validate_template', '_ajax_nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed. Please refresh the page and try again.', 'aebg' ) ], 403 );
		}

		$template_id = isset( $_POST['template_id'] ) ? (int) $_POST['template_id'] : 0;
		$product_count = isset( $_POST['product_count'] ) ? (int) $_POST['product_count'] : 0;

		if ( empty( $template_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Template ID is required.', 'aebg' ) ] );
		}

		if ( $product_count < 1 ) {
			wp_send_json_error( [ 'message' => __( 'Product count must be at least 1.', 'aebg' ) ] );
		}

		// CRITICAL: Save and clear any existing job start time before validation
		// Validation should not interfere with actual generation jobs
		$saved_job_start_time = $GLOBALS['aebg_job_start_time'] ?? null;
		if (isset($GLOBALS['aebg_job_start_time'])) {
			unset($GLOBALS['aebg_job_start_time']);
		}
		
		// Create a temporary Generator instance for validation
		$generator = new Generator( [] );
		$validation_result = $generator->validateTemplateProductCount( $template_id, $product_count );
		
		// CRITICAL: Clear job start time set by validation Generator
		// Validation should not set this global - it's only for actual generation jobs
		if (isset($GLOBALS['aebg_job_start_time'])) {
			unset($GLOBALS['aebg_job_start_time']);
		}
		
		// Restore original job start time if it existed (shouldn't happen during validation, but be safe)
		if ($saved_job_start_time !== null) {
			$GLOBALS['aebg_job_start_time'] = $saved_job_start_time;
		}

		if ( is_wp_error( $validation_result ) ) {
			wp_send_json_error( [ 'message' => $validation_result->get_error_message() ] );
		}

		wp_send_json_success( $validation_result );
	}

	/**
	 * AJAX check duplicate titles.
	 */
	public function ajax_check_duplicate_titles() {
		// Check capability
		if ( ! current_user_can( 'aebg_generate_content' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'aebg' ) ], 403 );
		}
		
		// Verify nonce
		if ( ! check_ajax_referer( 'aebg_check_duplicates', '_ajax_nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed. Please refresh the page and try again.', 'aebg' ) ], 403 );
		}

		$titles = isset( $_POST['titles'] ) ? json_decode( stripslashes( $_POST['titles'] ), true ) : [];
		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( $_POST['post_type'] ) : 'post';

		if ( empty( $titles ) || ! is_array( $titles ) ) {
			wp_send_json_error( [ 'message' => __( 'No titles provided.', 'aebg' ) ] );
		}

		// Sanitize titles
		$titles = array_map( 'sanitize_text_field', $titles );
		$titles = array_filter( $titles, function( $title ) {
			return ! empty( trim( $title ) );
		} );

		if ( empty( $titles ) ) {
			wp_send_json_error( [ 'message' => __( 'No valid titles provided.', 'aebg' ) ] );
		}

		// Check for duplicates
		$duplicates = $this->check_titles_against_database( $titles, $post_type );

		Logger::info( 'Duplicate title check completed', [
			'title_count' => count( $titles ),
			'duplicate_count' => count( $duplicates ),
			'post_type' => $post_type,
		] );

		wp_send_json_success( [
			'duplicates' => $duplicates,
			'total_checked' => count( $titles ),
			'duplicate_count' => count( $duplicates ),
		] );
	}

	/**
	 * Check titles against database for duplicates.
	 *
	 * @param array  $titles Array of titles to check.
	 * @param string $post_type Post type to check against.
	 * @return array Array of duplicate information.
	 */
	private function check_titles_against_database( $titles, $post_type ) {
		global $wpdb;

		if ( empty( $titles ) ) {
			return [];
		}

		// Build prepared statement for IN clause
		$placeholders = implode( ',', array_fill( 0, count( $titles ), '%s' ) );
		
		// Query for exact matches
		$query = $wpdb->prepare(
			"SELECT ID, post_title, post_type, post_status, post_date
			FROM {$wpdb->posts} 
			WHERE post_title IN ($placeholders) 
			AND post_type = %s
			AND post_status != 'trash'
			ORDER BY post_date DESC",
			...array_merge( $titles, [ $post_type ] )
		);

		$results = $wpdb->get_results( $query, ARRAY_A );

		// Organize results by title
		$duplicates = [];
		foreach ( $results as $post ) {
			$title = $post['post_title'];
			if ( ! isset( $duplicates[ $title ] ) ) {
				$duplicates[ $title ] = [];
			}
			
			// Get edit link
			$edit_link = get_edit_post_link( $post['ID'], 'raw' );
			
			$duplicates[ $title ][] = [
				'post_id' => (int) $post['ID'],
				'post_title' => $post['post_title'],
				'post_type' => $post['post_type'],
				'post_status' => $post['post_status'],
				'post_date' => $post['post_date'],
				'edit_link' => $edit_link ? $edit_link : '',
				'view_link' => get_permalink( $post['ID'] ),
			];
		}

		return $duplicates;
	}

	/**
	 * Schedule batch.
	 *
	 * @param array $settings The settings.
	 * @param array $titles The titles.
	 * @param string $request_id The request ID for logging.
	 * @return int|\WP_Error
	 */
	public function schedule_batch( $settings, $titles, $request_id = '' ) {
		global $wpdb;

		// CRITICAL: Use MySQL lock to prevent race condition between check and insert
		// This ensures atomicity - no other process can schedule a batch while we're checking/inserting
		$lock_name = 'aebg_batch_schedule_' . get_current_user_id();
		$lock_acquired = $wpdb->get_var($wpdb->prepare(
			"SELECT GET_LOCK(%s, 5) AS lock_acquired",
			$lock_name
		));
		
		if ($lock_acquired != 1) {
			Logger::warning( 'Could not acquire batch scheduling lock', [
				'request_id' => $request_id,
				'user_id' => get_current_user_id(),
			] );
			return new \WP_Error( 'lock_failed', 'Could not acquire scheduling lock. Please try again in a moment.' );
		}
		
		try {
			// CRITICAL: Check if any batch is currently in progress for this user
			// This prevents multiple batches from running concurrently
			// Now protected by MySQL lock to prevent race conditions
			// CRITICAL: Explicitly exclude cancelled, completed, and failed batches
			// Also clear cache to ensure we get fresh data (handles manually deleted batches)
			wp_cache_flush_group('aebg_batches');
			
			$active_batch = $wpdb->get_var($wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}aebg_batches 
				WHERE user_id = %d 
				AND status IN ('scheduled', 'in_progress')
				AND status NOT IN ('cancelled', 'completed', 'failed')
				LIMIT 1",
				get_current_user_id()
			));

			if ($active_batch) {
				// CRITICAL: Double-check the batch still exists and is actually active
				// This handles cases where batch was manually deleted or cancelled between queries
				$batch_verification = $wpdb->get_var($wpdb->prepare(
					"SELECT status FROM {$wpdb->prefix}aebg_batches 
					WHERE id = %d 
					AND status NOT IN ('cancelled', 'completed', 'failed')
					LIMIT 1",
					$active_batch
				));
				
				if ($batch_verification && in_array($batch_verification, ['scheduled', 'in_progress'])) {
					// CRITICAL: Check if batch is actually stuck (in_progress but no active jobs)
					// Get all batch items for this batch
					$batch_items = $wpdb->get_results($wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}aebg_batch_items WHERE batch_id = %d",
						$active_batch
					), ARRAY_A);
					
					$has_active_jobs = false;
					// Use ActionSchedulerHelper to ensure proper initialization check
					if (!empty($batch_items) && class_exists('\AEBG\Core\ActionSchedulerHelper') && \AEBG\Core\ActionSchedulerHelper::ensure_initialized()) {
						foreach ($batch_items as $item) {
							$item_id = (int) $item['id'];
							
							// Check for in-progress actions
							$in_progress_actions = \AEBG\Core\ActionSchedulerHelper::get_scheduled_actions([
								'hook' => 'aebg_execute_generation',
								'group' => 'aebg_generation_' . $item_id,
								'status' => 'in-progress',
								'per_page' => 1,
							]);
							
							if (!empty($in_progress_actions)) {
								$has_active_jobs = true;
								break;
							}
							
							// Also check for pending actions
							$pending_actions = \AEBG\Core\ActionSchedulerHelper::get_scheduled_actions([
								'hook' => 'aebg_execute_generation',
								'group' => 'aebg_generation_' . $item_id,
								'status' => 'pending',
								'per_page' => 1,
							]);
							
							if (!empty($pending_actions)) {
								$has_active_jobs = true;
								break;
							}
						}
					}
					
					if ($has_active_jobs) {
						// Batch is actually running - block new batch
						Logger::warning( 'Active batch already in progress', [
							'request_id' => $request_id,
							'active_batch_id' => $active_batch,
							'status' => $batch_verification,
						] );
						return new \WP_Error( 'concurrent_batch', 'Another batch is already in progress. Please wait for it to complete before starting a new one.' );
					} else {
						// Batch is stuck (in_progress but no active jobs) - mark as completed and allow new batch
						Logger::warning( 'Detected stuck batch (in_progress but no active jobs) - marking as completed', [
							'request_id' => $request_id,
							'batch_id' => $active_batch,
							'status' => $batch_verification,
						] );
						
						// Mark batch as completed (it's stuck, not actually running)
						$wpdb->update(
							"{$wpdb->prefix}aebg_batches",
							['status' => 'completed'],
							['id' => $active_batch],
							['%s'],
							['%d']
						);
						
						// Clear cache
						wp_cache_flush_group('aebg_batches');
						
						Logger::info( 'Stuck batch marked as completed, allowing new batch', [
							'request_id' => $request_id,
							'batch_id' => $active_batch,
						] );
						// Continue to allow new batch
					}
				} else {
					// Batch was cancelled/deleted between queries - allow new batch
					Logger::debug( 'Previously detected active batch is no longer active', [
						'request_id' => $request_id,
						'batch_id' => $active_batch,
						'status' => $batch_verification ?? 'not_found',
					] );
				}
			}

		// Check if we already have a batch with the same titles and settings (within last 2 minutes, reduced from 5)
		$existing_batch = $wpdb->get_row($wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}aebg_batches 
			WHERE user_id = %d 
			AND template_id = %d 
			AND total_items = %d 
			AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
			ORDER BY created_at DESC 
			LIMIT 1",
			get_current_user_id(),
			$settings['template_id'],
			count($titles)
		));

			if ($existing_batch) {
				Logger::warning( 'Duplicate batch detected', [
					'request_id' => $request_id,
					'existing_batch_id' => $existing_batch->id,
				] );
				return new \WP_Error( 'duplicate_batch', 'A similar batch was already scheduled recently. Please wait a moment before trying again.' );
			}

			$wpdb->insert(
			$wpdb->prefix . 'aebg_batches',
			[
				'user_id'     => get_current_user_id(),
				'template_id' => $settings['template_id'],
				'settings'    => json_encode( $settings ),
				'status'      => 'scheduled',
				'total_items' => count( $titles ),
				'created_at'  => current_time( 'mysql' ),
			]
		);
		$batch_id = $wpdb->insert_id;

		if (!$batch_id) {
			Logger::error( 'Failed to insert batch', [
				'request_id' => $request_id,
				'db_error' => $wpdb->last_error,
			] );
			return new \WP_Error( 'database_error', 'Failed to create batch in database.' );
		}

			Logger::info( 'Created batch', [
				'batch_id' => $batch_id,
				'request_id' => $request_id,
			] );

			$scheduled_count = 0;
			foreach ( $titles as $index => $title ) {
			// Check if we already have a batch item with this title in this batch
			$existing_item = $wpdb->get_row($wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}aebg_batch_items 
				WHERE batch_id = %d AND source_title = %s",
				$batch_id,
				$title
			));

			if ($existing_item) {
				Logger::debug( 'Duplicate title detected, skipping', [
					'title' => $title,
					'batch_id' => $batch_id,
				] );
				continue;
			}

			$wpdb->insert(
				$wpdb->prefix . 'aebg_batch_items',
				[
					'batch_id'     => $batch_id,
					'source_title' => $title,
					'status'       => 'pending',
					'created_at'   => current_time( 'mysql' ),
				]
			);
			$item_id = $wpdb->insert_id;

			if (!$item_id) {
				Logger::error( 'Failed to insert batch item', [
					'title' => $title,
					'batch_id' => $batch_id,
					'db_error' => $wpdb->last_error,
				] );
				continue;
			}

			// CRITICAL: Schedule all actions at once with minimal delay
			// Each job will trigger the next one via async HTTP request when it completes
			// This ensures each job runs in a separate PHP process (separate HTTP request = separate process)
			// This is more reliable than delays because:
			// 1. No timing issues - next job starts immediately after previous completes
			// 2. Guaranteed process isolation - each HTTP request = separate PHP process
			// 3. No shared state - fresh connections, memory, globals for each job
			// 4. More efficient - no waiting between jobs
			$delay = $index === 0 ? 1 : 1; // All jobs scheduled with 1-second delay
			
			// Check feature flag for step-by-step actions
			$use_step_by_step = get_option( 'aebg_use_step_by_step_actions', true );
			
			if ( $use_step_by_step ) {
				// Step-by-step mode: Schedule Step 1 instead of full generation
				$hook = StepHandler::get_step_hook( StepHandler::get_first_step() );
				if ( ! $hook ) {
					Logger::error( 'Step 1 hook not found', [ 'item_id' => $item_id ] );
					$hook = 'aebg_execute_generation'; // Fallback to legacy
					$args = [ 'item_id' => $item_id, 'title' => $title ]; // Legacy format with title
				} else {
					Logger::info( 'Scheduling Step 1 (step-by-step mode)', [ 'item_id' => $item_id, 'title' => $title ] );
					$args = [ $item_id, $title ]; // Step hooks use item_id as first argument, title as second
				}
			} else {
				// Legacy mode: Schedule full generation
				$hook = 'aebg_execute_generation';
				$args = [ 'item_id' => $item_id, 'title' => $title ]; // Legacy format with title
			}
			$group = 'aebg_generation_' . $item_id;
			
			$action_id = ActionSchedulerHelper::schedule_action( 
				$hook,
				$args,
				$group,
				$delay, // Increasing delays to force separate processes
				true // Make action unique
			);
			
			if ( $action_id > 0 ) {
				$scheduled_count++;
				Logger::debug( 'Action scheduled successfully', [
					'action_id' => $action_id,
					'item_id' => $item_id,
					'title' => $title,
					'request_id' => $request_id,
					'delay_seconds' => $delay,
					'article_number' => $index + 1,
					'note' => $index === 0 ? 'First job (immediate)' : 'Will be triggered via async HTTP request when previous job completes',
				] );
			} elseif ( $action_id === 0 ) {
				// Action already exists (duplicate prevented) - this is success
				$scheduled_count++;
				Logger::debug( 'Action already exists (duplicate prevented)', [
					'item_id' => $item_id,
					'title' => $title,
				] );
			} else {
				Logger::error( 'Failed to schedule action', [
					'item_id' => $item_id,
					'title' => $title,
					'request_id' => $request_id,
				] );
			}
		}

			Logger::info( 'Batch items scheduled', [
				'scheduled_count' => $scheduled_count,
				'total_titles' => count($titles),
				'batch_id' => $batch_id,
				'request_id' => $request_id,
			] );

			// Note: We do NOT immediately trigger QueueRunner here
			// Action Scheduler will process actions via shutdown hook or cron naturally
			// This prevents cumulative timeout issues

			return $batch_id;
		} finally {
			// CRITICAL: Always release MySQL lock, even if an error occurred
			$release_result = $wpdb->get_var($wpdb->prepare(
				"SELECT RELEASE_LOCK(%s) AS lock_released",
				$lock_name
			));
			
			if ($release_result == 1) {
				Logger::debug( 'Batch scheduling lock released' );
			}
		}
	}
}
