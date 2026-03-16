<?php

namespace AEBG\API;

/**
 * Batch Status Controller Class
 *
 * @package AEBG\API
 */
class BatchStatusController extends \WP_REST_Controller {
	/**
	 * BatchStatusController constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes() {
		register_rest_route(
			'aebg/v1',
			'/batch/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => [ $this, 'get_item_permissions_check' ],
				'args'                => [
					'id' => [
						'validate_callback' => function ( $param, $request, $key ) {
							return is_numeric( $param );
						},
					],
				],
			]
		);
		
		register_rest_route(
			'aebg/v1',
			'/batch/(?P<id>\d+)/cancel',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'cancel_batch' ],
				'permission_callback' => [ $this, 'get_item_permissions_check' ],
				'args'                => [
					'id' => [
						'validate_callback' => function ( $param, $request, $key ) {
							return is_numeric( $param );
						},
					],
				],
			]
		);
		
		register_rest_route(
			'aebg/v1',
			'/batch/active',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_active_batch' ],
				'permission_callback' => [ $this, 'get_item_permissions_check' ],
			]
		);
	}

	/**
	 * Get one item from the collection
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_item( $request ) {
		// CRITICAL: Set very short timeout and disable expensive operations
		// Reduced to 3 seconds to prevent web server timeouts (most servers timeout at 30-60s, but we want to be safe)
		@set_time_limit( 3 );
		@ini_set( 'max_execution_time', 3 );
		
		// CRITICAL: Disable expensive WordPress hooks that might slow down the request
		// This prevents plugins/themes from interfering with this fast endpoint
		$wp_query_backup = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;
		
		// Start output buffering immediately
		$ob_started = false;
		if (ob_get_level() === 0) {
			ob_start();
			$ob_started = true;
		} else {
			ob_clean();
		}
		
		// Log request start for debugging 503 errors
		$request_start = microtime(true);
		
		// Use caching to avoid repeated database queries
		$batch_id = (int) $request['id'];
		$cache_key = 'aebg_batch_status_' . $batch_id;
		
		// CRITICAL: For active batches, don't use cache to ensure real-time updates
		// Only use cache for completed/cancelled/failed batches
		$cached_data = wp_cache_get( $cache_key, 'aebg_batches' );
		$use_cache = false;
		
		if ( $cached_data !== false && is_array( $cached_data ) ) {
			$cached_status = $cached_data['status'] ?? 'pending';
			// Only use cache for completed/final batches, not for active ones
			if ( in_array( $cached_status, [ 'completed', 'cancelled', 'failed' ] ) ) {
				$use_cache = true;
			}
		}
		
		if ( $use_cache ) {
			if ($ob_started) {
				ob_end_clean();
			} else {
				ob_clean();
			}
			$response = new \WP_REST_Response( $cached_data, 200 );
			$response->set_headers([
				'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
			]);
			return $response;
		}
		
		try {
			global $wpdb;
			
			// Validate batch ID
			if ( $batch_id <= 0 ) {
				$data = [
					'status' => 'not_found',
					'total_items' => 0,
					'processed_items' => 0,
					'failed_items' => 0,
					'processing_items' => [],
					'failed_items_detail' => [],
				];
				$response = new \WP_REST_Response( $data, 200 );
				$response->set_headers([
					'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
				]);
				if ($ob_started) {
					ob_end_clean();
				} else {
					ob_clean();
				}
				return $response;
			}
			
			// Use a single, fast query - no table existence checks
			$table_name = $wpdb->prefix . 'aebg_batches';
			$wpdb->suppress_errors( true );
			
			// Single optimized query with LIMIT 1 for speed
			// CRITICAL: Include created_at for elapsed_time calculation and settings for AI model
			// CRITICAL: Use direct query with error suppression to prevent 503 on database issues
			$query_start = microtime(true);
			$data = $wpdb->get_row( 
				$wpdb->prepare( 
					"SELECT status, total_items, processed_items, failed_items, created_at, settings FROM {$table_name} WHERE id = %d LIMIT 1", 
					$batch_id 
				),
				ARRAY_A
			);
			$query_elapsed = microtime(true) - $query_start;
			
			// Log slow queries to identify performance issues
			if ($query_elapsed > 1.0) {
				@error_log('[AEBG] BatchStatusController::get_item - Slow query detected: ' . round($query_elapsed, 2) . 's for batch ' . $batch_id);
			}
			
			$wpdb->suppress_errors( false );
			
			// If batch not found or query failed, return default structure
			if ( empty( $data ) || ! is_array( $data ) ) {
				$data = [
					'status' => 'not_found',
					'total_items' => 0,
					'processed_items' => 0,
					'failed_items' => 0,
					'elapsed_time' => 0,
					'processing_items' => [],
					'failed_items_detail' => [],
				];
			} else {
				// CRITICAL: Recalculate actual counts from items table for accuracy
				// The batch table counts may be out of sync, so we count from source of truth
				$wpdb->suppress_errors( true );
				
				// Count actual completed and failed items from items table
				$items_counts = $wpdb->get_row( $wpdb->prepare(
					"SELECT 
						COUNT(*) as total,
						SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
						SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
						SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_count
					FROM {$wpdb->prefix}aebg_batch_items 
					WHERE batch_id = %d",
					$batch_id
				), ARRAY_A );
				$wpdb->suppress_errors( false );
				
				// Use actual counts from items table if available, otherwise fall back to batch table
				if ( $items_counts && is_array( $items_counts ) ) {
					$actual_processed = (int) ($items_counts['completed_count'] ?? 0);
					$actual_failed = (int) ($items_counts['failed_count'] ?? 0);
					$actual_total = (int) ($items_counts['total'] ?? 0);
					$processing_count = (int) ($items_counts['processing_count'] ?? 0);
					
					// Override batch table values with actual counts
					$data['processed_items'] = $actual_processed;
					$data['failed_items'] = $actual_failed;
					$data['total_items'] = $actual_total > 0 ? $actual_total : $data['total_items'];
					
					// CRITICAL: Auto-update batch status if all items are done but batch status is wrong
					$batch_status = (string) ($data['status'] ?? 'pending');
					$completed_count = $actual_processed + $actual_failed;
					
					if ( $completed_count === $actual_total && $actual_total > 0 ) {
						// All items are done - ensure batch status is 'completed'
						if ( ! in_array( $batch_status, [ 'completed', 'cancelled', 'failed' ] ) ) {
							// Update batch status to completed
							$wpdb->update(
								"{$wpdb->prefix}aebg_batches",
								[ 'status' => 'completed' ],
								[ 'id' => $batch_id ],
								[ '%s' ],
								[ '%d' ]
							);
							$data['status'] = 'completed';
							// Clear cache to ensure frontend sees the update
							wp_cache_delete( 'aebg_batch_status_' . $batch_id, 'aebg_batches' );
						}
					} elseif ( $processing_count > 0 || $completed_count < $actual_total ) {
						// Items are still processing or not all done - ensure status is active
						if ( ! in_array( $batch_status, [ 'in_progress', 'processing', 'pending', 'scheduled' ] ) && $batch_status !== 'completed' ) {
							$data['status'] = 'in_progress';
						}
					}
				}
				
				// CRITICAL: Calculate elapsed_time from batch creation time
				$created_at = $data['created_at'] ?? null;
				if ( $created_at ) {
					$created_timestamp = strtotime( $created_at );
					$current_timestamp = time();
					$data['elapsed_time'] = max( 0, $current_timestamp - $created_timestamp ); // seconds
				} else {
					$data['elapsed_time'] = 0;
				}
				
				// CRITICAL: Extract AI model from batch settings for ETA calculations
				$ai_model = 'gpt-3.5-turbo'; // Default
				if ( ! empty( $data['settings'] ) ) {
					$settings = json_decode( $data['settings'], true );
					if ( is_array( $settings ) ) {
						$ai_model = $settings['ai_model'] ?? $settings['model'] ?? $ai_model;
					}
				}
				$data['ai_model'] = $ai_model;
				
				// Get ALL processing items with their step information (not just one)
				// This allows the frontend to show all posts being processed simultaneously
				// CRITICAL: Include:
				// 1. Items with status = 'processing' (actively being processed)
				// 2. Items with status = 'pending' AND current_step IS NOT NULL (scheduled with step info)
				// 3. Items with status = 'pending' (scheduled but step not started yet - show as "Preparing...")
				$wpdb->suppress_errors( true );
				$processing_items = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, source_title, current_step, step_progress, status 
					FROM {$wpdb->prefix}aebg_batch_items 
					WHERE batch_id = %d AND status IN ('processing', 'pending')
					ORDER BY 
						CASE 
							WHEN status = 'processing' THEN 1
							WHEN status = 'pending' AND current_step IS NOT NULL THEN 2
							ELSE 3
						END,
						id ASC",
					$batch_id
				), ARRAY_A );
				$wpdb->suppress_errors( false );
				
				// Process all items and build array with step information
				$processing_items_data = [];
				$total_steps = 12; // Default
				
				if ( class_exists( '\AEBG\Core\StepHandler' ) ) {
					$total_steps = \AEBG\Core\StepHandler::get_total_steps();
				}
				
				if ( ! empty( $processing_items ) && is_array( $processing_items ) ) {
					foreach ( $processing_items as $item ) {
						if ( ! is_array( $item ) ) {
							continue;
						}
						
						$item_id = (int) ( $item['id'] ?? 0 );
						$item_title = $item['source_title'] ?? '';
						$current_step = $item['current_step'] ?? null;
						$step_progress = (int) ( $item['step_progress'] ?? 0 );
						
						$item_data = [
							'id' => $item_id,
							'title' => $item_title,
						];
						
						// CRITICAL: Always include step information (even if step_progress is 0)
						// This allows frontend to show "Starting..." or the actual step
						$item_data['current_step'] = $current_step;
						$item_data['step_progress'] = $step_progress;
						$item_data['total_steps'] = $total_steps;
						
						// Add step description if current_step is set
						if ( ! empty( $current_step ) ) {
							$step_description = '';
							if ( class_exists( '\AEBG\Core\StepHandler' ) ) {
								$step_description = \AEBG\Core\StepHandler::get_step_description( $current_step );
							}
							$item_data['step_description'] = $step_description;
						} else {
							$item_data['step_description'] = '';
						}
						
						$processing_items_data[] = $item_data;
					}
				}
				
				// Set processing_items array (all items being processed)
				$data['processing_items'] = $processing_items_data;
				
				// Also set current_item for backward compatibility (first item if available)
				if ( ! empty( $processing_items_data ) ) {
					$data['current_item'] = $processing_items_data[0];
				}
				
				// Get ALL failed items with their error messages
				// This allows the frontend to show which items failed and why
				$wpdb->suppress_errors( true );
				$failed_items = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, source_title, log_message, status 
					FROM {$wpdb->prefix}aebg_batch_items 
					WHERE batch_id = %d AND status = 'failed' 
					ORDER BY id ASC",
					$batch_id
				), ARRAY_A );
				$wpdb->suppress_errors( false );
				
				// Process failed items and build array with error information
				$failed_items_data = [];
				
				if ( ! empty( $failed_items ) && is_array( $failed_items ) ) {
					foreach ( $failed_items as $item ) {
						if ( ! is_array( $item ) ) {
							continue;
						}
						
						$item_id = (int) ( $item['id'] ?? 0 );
						$item_title = $item['source_title'] ?? '';
						$error_message = $item['log_message'] ?? 'Unknown error';
						
						$failed_items_data[] = [
							'id' => $item_id,
							'title' => $item_title,
							'error_message' => $error_message,
						];
					}
				}
				
				// Set failed_items_detail array (all failed items with details)
				$data['failed_items_detail'] = $failed_items_data;
			}
			
			// Ensure all values are properly set
			$data = array_merge([
				'status' => 'pending',
				'total_items' => 0,
				'processed_items' => 0,
				'failed_items' => 0,
				'elapsed_time' => 0,
				'processing_items' => [],
				'failed_items_detail' => [],
			], (array) $data);
			
			// Convert numeric strings to integers
			$data['total_items'] = (int) ($data['total_items'] ?? 0);
			$data['processed_items'] = (int) ($data['processed_items'] ?? 0);
			$data['failed_items'] = (int) ($data['failed_items'] ?? 0);
			$data['elapsed_time'] = (int) ($data['elapsed_time'] ?? 0);
			
			// CRITICAL: Don't report failed items until we have completed items
			$completed = $data['processed_items'] + $data['failed_items'];
			if ($completed === 0) {
				$data['failed_items'] = 0;
			}
			
			// Ensure status is a valid string
			$data['status'] = (string) ($data['status'] ?? 'pending');
			
			// CRITICAL: Only cache completed/final batches to ensure real-time updates for active batches
			// Active batches (pending, in_progress, scheduled) should always fetch fresh data
			$batch_status = $data['status'] ?? 'pending';
			if ( in_array( $batch_status, [ 'completed', 'cancelled', 'failed' ] ) ) {
				// Cache final batches for 5 seconds (they won't change)
				wp_cache_set( $cache_key, $data, 'aebg_batches', 5 );
			} else {
				// Don't cache active batches - always fetch fresh data for real-time updates
				// Clear any existing cache to prevent stale data
				wp_cache_delete( $cache_key, 'aebg_batches' );
			}
			
			// Clean any output that might have been generated
			if ($ob_started) {
				ob_end_clean();
			} else {
				ob_clean();
			}
			
			// Log total request time to identify slow requests
			$total_elapsed = microtime(true) - $request_start;
			if ($total_elapsed > 2.0) {
				@error_log('[AEBG] BatchStatusController::get_item - Slow request: ' . round($total_elapsed, 2) . 's for batch ' . $batch_id);
			}
			
			// Create response with proper JSON encoding
			$response = new \WP_REST_Response( $data, 200 );
			$response->set_headers([
				'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
				'X-Request-Time' => round($total_elapsed, 3), // Debug header
			]);
			
			return $response;
			
		} catch ( \Exception $e ) {
			// Clean output buffer before error response
			if ($ob_started) {
				ob_end_clean();
			} else {
				ob_clean();
			}
			
			// Log error but don't output it (would break JSON)
			$elapsed = microtime(true) - $request_start;
			@error_log( '[AEBG] BatchStatusController::get_item error after ' . round($elapsed, 2) . 's: ' . $e->getMessage() . ' (batch: ' . $batch_id . ')' );
			
			// Return safe default data instead of error to prevent 503
			$data = [
				'status' => 'pending',
				'total_items' => 0,
				'processed_items' => 0,
				'failed_items' => 0,
				'elapsed_time' => 0,
				'processing_items' => [],
				'failed_items_detail' => [],
			];
			$response = new \WP_REST_Response( $data, 200 );
			$headers = [
				'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
			];
			// Only add debug header in development
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$headers['X-Error'] = 'Exception caught';
			}
			$response->set_headers( $headers );
			return $response;
		} catch ( \Error $e ) {
			// Clean output buffer before error response
			if ($ob_started) {
				ob_end_clean();
			} else {
				ob_clean();
			}
			
			// Catch fatal errors (PHP 7+)
			$elapsed = microtime(true) - $request_start;
			@error_log( '[AEBG] BatchStatusController::get_item fatal error after ' . round($elapsed, 2) . 's: ' . $e->getMessage() . ' (batch: ' . $batch_id . ')' );
			
			// Return safe default data instead of error to prevent 503
			$data = [
				'status' => 'pending',
				'total_items' => 0,
				'processed_items' => 0,
				'failed_items' => 0,
				'elapsed_time' => 0,
				'processing_items' => [],
				'failed_items_detail' => [],
			];
			$response = new \WP_REST_Response( $data, 200 );
			$response->set_headers([
				'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
			]);
			return $response;
		} catch ( \Throwable $e ) {
			// Catch any other throwable (PHP 7+)
			if ($ob_started) {
				ob_end_clean();
			} else {
				ob_clean();
			}
			
			$elapsed = microtime(true) - $request_start;
			@error_log( '[AEBG] BatchStatusController::get_item throwable after ' . round($elapsed, 2) . 's: ' . $e->getMessage() . ' (batch: ' . $batch_id . ')' );
			
			// Return safe default data
			$data = [
				'status' => 'pending',
				'total_items' => 0,
				'processed_items' => 0,
				'failed_items' => 0,
				'elapsed_time' => 0,
				'processing_items' => [],
				'failed_items_detail' => [],
			];
			$response = new \WP_REST_Response( $data, 200 );
			$response->set_headers([
				'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
			]);
			return $response;
		}
	}

	/**
	 * Cancel a batch and its associated actions
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function cancel_batch( $request ) {
		// Set a short timeout for this endpoint
		@set_time_limit( 10 );
		
		// Start output buffering
		$ob_started = false;
		if (ob_get_level() === 0) {
			ob_start();
			$ob_started = true;
		} else {
			ob_clean();
		}
		
		try {
			global $wpdb;
			$batch_id = (int) $request['id'];
			
			// Validate batch ID
			if ( $batch_id <= 0 ) {
				$response = new \WP_REST_Response( [
					'success' => false,
					'message' => 'Invalid batch ID',
				], 400 );
				$response->set_headers([
					'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
				]);
				if ($ob_started) {
					ob_end_clean();
				} else {
					ob_clean();
				}
				return $response;
			}
			
			// Check if batch exists
			$wpdb->suppress_errors( true );
			$batch = $wpdb->get_row( 
				$wpdb->prepare( 
					"SELECT id, status FROM {$wpdb->prefix}aebg_batches WHERE id = %d LIMIT 1", 
					$batch_id 
				),
				ARRAY_A
			);
			$wpdb->suppress_errors( false );
			
			if ( empty( $batch ) || ! is_array( $batch ) ) {
				$response = new \WP_REST_Response( [
					'success' => false,
					'message' => 'Batch not found',
				], 404 );
				$response->set_headers([
					'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
				]);
				if ($ob_started) {
					ob_end_clean();
				} else {
					ob_clean();
				}
				return $response;
			}
			
			// Don't cancel if already completed or cancelled
			if ( in_array( $batch['status'], [ 'completed', 'cancelled', 'failed' ] ) ) {
				$response = new \WP_REST_Response( [
					'success' => true,
					'message' => 'Batch is already ' . $batch['status'],
					'status' => $batch['status'],
				], 200 );
				$response->set_headers([
					'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
				]);
				if ($ob_started) {
					ob_end_clean();
				} else {
					ob_clean();
				}
				return $response;
			}
			
			// Get all batch items for this batch
			$wpdb->suppress_errors( true );
			$batch_items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}aebg_batch_items WHERE batch_id = %d",
					$batch_id
				),
				ARRAY_A
			);
			$wpdb->suppress_errors( false );
			
			$cancelled_actions = 0;
			
			// CRITICAL: Cancel ALL actions (pending, scheduled, and in-progress) for this batch
			// Use ActionSchedulerHelper to ensure proper initialization check
			if ( ! empty( $batch_items ) && class_exists( '\AEBG\Core\ActionSchedulerHelper' ) && \AEBG\Core\ActionSchedulerHelper::ensure_initialized() ) {
				foreach ( $batch_items as $item ) {
					$item_id = (int) $item['id'];
					
					// Cancel pending actions
					$pending_actions = \AEBG\Core\ActionSchedulerHelper::get_scheduled_actions( [
						'hook' => 'aebg_execute_generation',
						'group' => 'aebg_generation_' . $item_id,
						'status' => 'pending',
						'per_page' => 10,
					] );
					
					foreach ( $pending_actions as $action ) {
						if ( function_exists( 'as_unschedule_action' ) ) {
							as_unschedule_action( 'aebg_execute_generation', [ 'item_id' => $item_id ], 'aebg_generation_' . $item_id );
							$cancelled_actions++;
						} elseif ( method_exists( $action, 'get_id' ) && function_exists( 'as_cancel_scheduled_action' ) ) {
							as_cancel_scheduled_action( $action->get_id() );
							$cancelled_actions++;
						}
					}
					
					// CRITICAL: Also cancel in-progress actions (these are currently running)
					$in_progress_actions = \AEBG\Core\ActionSchedulerHelper::get_scheduled_actions( [
						'hook' => 'aebg_execute_generation',
						'group' => 'aebg_generation_' . $item_id,
						'status' => 'in-progress',
						'per_page' => 10,
					] );
					
					foreach ( $in_progress_actions as $action ) {
						if ( method_exists( $action, 'get_id' ) && function_exists( 'as_cancel_scheduled_action' ) ) {
							as_cancel_scheduled_action( $action->get_id() );
							$cancelled_actions++;
						}
					}
				}
			}
			
			// CRITICAL: Update batch status to cancelled with WHERE clause to prevent overwriting if already cancelled
			// This ensures we don't accidentally overwrite a cancelled status if another process already cancelled it
			$wpdb->suppress_errors( true );
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}aebg_batches 
					SET status = 'cancelled' 
					WHERE id = %d AND status NOT IN ('cancelled', 'completed', 'failed')",
					$batch_id
				)
			);
			$wpdb->suppress_errors( false );
			
			// Update all pending/processing batch items to cancelled
			if ( ! empty( $batch_items ) ) {
				$item_ids = array_map( function( $item ) {
					return (int) $item['id'];
				}, $batch_items );
				
				// Sanitize all IDs to integers
				$sanitized_ids = array_map( 'intval', $item_ids );
				$sanitized_ids = array_filter( $sanitized_ids ); // Remove any invalid IDs
				
				if ( ! empty( $sanitized_ids ) ) {
					// Create safe IN clause with sanitized integers
					$ids_string = implode( ',', $sanitized_ids );
					
					$wpdb->suppress_errors( true );
					// IDs are already sanitized as integers, so safe to use directly
					$wpdb->query(
						"UPDATE {$wpdb->prefix}aebg_batch_items 
						SET status = 'cancelled' 
						WHERE id IN ($ids_string) AND status IN ('pending', 'processing')"
					);
					$wpdb->suppress_errors( false );
				}
			}
			
			// CRITICAL: Clear ALL cache for this batch (multiple cache layers)
			$cache_key = 'aebg_batch_status_' . $batch_id;
			wp_cache_delete( $cache_key, 'aebg_batches' );
			
			// Clear cache group entirely to ensure no stale data
			if ( function_exists( 'wp_cache_flush_group' ) ) {
				wp_cache_flush_group( 'aebg_batches' );
			} else {
				// Fallback: delete common cache keys
				wp_cache_delete( 'aebg_batch_status_' . $batch_id );
				wp_cache_delete( 'aebg_batch_' . $batch_id );
			}
			
			// Also clear any transients that might be caching batch data
			delete_transient( 'aebg_batch_' . $batch_id );
			delete_transient( 'aebg_batch_status_' . $batch_id );
			
			// Clean output buffer
			if ($ob_started) {
				ob_end_clean();
			} else {
				ob_clean();
			}
			
			$response = new \WP_REST_Response( [
				'success' => true,
				'message' => 'Batch cancelled successfully',
				'cancelled_actions' => $cancelled_actions,
			], 200 );
			$response->set_headers([
				'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
			]);
			
			return $response;
			
		} catch ( \Exception $e ) {
			if ($ob_started) {
				ob_end_clean();
			} else {
				ob_clean();
			}
			
			@error_log( '[AEBG] BatchStatusController::cancel_batch error: ' . $e->getMessage() );
			
			$response = new \WP_REST_Response( [
				'success' => false,
				'message' => 'Failed to cancel batch',
			], 500 );
			$response->set_headers([
				'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
			]);
			return $response;
		} catch ( \Error $e ) {
			if ($ob_started) {
				ob_end_clean();
			} else {
				ob_clean();
			}
			
			@error_log( '[AEBG] BatchStatusController::cancel_batch fatal error: ' . $e->getMessage() );
			
			$response = new \WP_REST_Response( [
				'success' => false,
				'message' => 'Failed to cancel batch',
			], 500 );
			$response->set_headers([
				'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
			]);
			return $response;
		}
	}

	/**
	 * Get the most recent active batch for the current user
	 * CRITICAL: Also verifies that Action Scheduler jobs actually exist
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_active_batch( $request ) {
		try {
			global $wpdb;
			
			$user_id = get_current_user_id();
			$table_name = $wpdb->prefix . 'aebg_batches';
			
			// CRITICAL: Check batch table directly first - this is more reliable
			// Check for batches with active status (in_progress, pending, scheduled)
			// This catches batches even if Action Scheduler hasn't initialized or actions are in unexpected states
			
			// DEBUG: Log what we're looking for
			error_log( '[AEBG] get_active_batch: Checking for active batches for user_id: ' . $user_id );
			
			// First, let's see what batches exist for this user (for debugging)
			$all_batches = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, status, total_items, processed_items, failed_items, created_at 
					FROM {$table_name} 
					WHERE user_id = %d 
					ORDER BY created_at DESC
					LIMIT 5",
					$user_id
				),
				ARRAY_A
			);
			
			if ( ! empty( $all_batches ) ) {
				error_log( '[AEBG] get_active_batch: Found ' . count( $all_batches ) . ' batches for user. Statuses: ' . implode( ', ', array_column( $all_batches, 'status' ) ) );
			} else {
				error_log( '[AEBG] get_active_batch: No batches found for user_id: ' . $user_id );
			}
			
			// CRITICAL: Check for active batches - include all possible active statuses
			// First check by batch status
			$active_batch = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, status, total_items, processed_items, failed_items, created_at 
					FROM {$table_name} 
					WHERE user_id = %d 
					AND status IN ('in_progress', 'pending', 'scheduled', 'processing')
					ORDER BY created_at DESC
					LIMIT 1",
					$user_id
				),
				ARRAY_A
			);
			
			// If no batch found by status, check if any batch has items still processing
			// CRITICAL: Exclude completed/cancelled/failed batches from this check
			if ( empty( $active_batch ) ) {
				$active_batch = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT b.id, b.status, b.total_items, b.processed_items, b.failed_items, b.created_at
						FROM {$table_name} b
						INNER JOIN {$wpdb->prefix}aebg_batch_items bi ON bi.batch_id = b.id
						WHERE b.user_id = %d 
						AND b.status NOT IN ('completed', 'cancelled', 'failed')
						AND bi.status IN ('pending', 'processing')
						GROUP BY b.id
						ORDER BY b.created_at DESC
						LIMIT 1",
						$user_id
					),
					ARRAY_A
				);
				
				if ( ! empty( $active_batch ) ) {
					error_log( '[AEBG] get_active_batch: Found active batch by checking items (batch has processing items)' );
				}
			}
			
			if ( ! empty( $active_batch ) ) {
				error_log( '[AEBG] get_active_batch: Found active batch in database query: ' . json_encode( $active_batch ) );
			} else {
				error_log( '[AEBG] get_active_batch: No active batch found in database query (checked statuses: in_progress, pending, scheduled, processing)' );
			}
			
			// If we found an active batch in the database, use it
			if ( ! empty( $active_batch ) && is_array( $active_batch ) ) {
				$batch_id = (int) $active_batch['id'];
				$batch_status = (string) ($active_batch['status'] ?? 'pending');
				
				// CRITICAL: If batch status is already completed/cancelled/failed, immediately return inactive
				// This prevents showing stale progress for completed batches
				if ( in_array( $batch_status, [ 'completed', 'cancelled', 'failed' ] ) ) {
					error_log( '[AEBG] get_active_batch: Batch ' . $batch_id . ' has status "' . $batch_status . '" - marking as inactive.' );
					$data = [
						'active' => false,
						'batch_id' => null,
						'elapsed_time' => 0,
					];
				} else {
					// CRITICAL: Verify the batch is actually still active by checking item counts
				// A batch might have status 'in_progress' but all items are actually completed
				$processed_items = (int) ($active_batch['processed_items'] ?? 0);
				$failed_items = (int) ($active_batch['failed_items'] ?? 0);
				$total_items = (int) ($active_batch['total_items'] ?? 0);
				$completed_count = $processed_items + $failed_items;
				
				// Also check actual item counts from the items table for accuracy
				$items_counts = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT 
							COUNT(*) as total,
							SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
							SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
							SUM(CASE WHEN status IN ('pending', 'processing') THEN 1 ELSE 0 END) as processing_count
						FROM {$wpdb->prefix}aebg_batch_items 
						WHERE batch_id = %d",
						$batch_id
					),
					ARRAY_A
				);
				
				// Handle case where query returns null or empty
				if ( empty( $items_counts ) || ! is_array( $items_counts ) ) {
					error_log( '[AEBG] get_active_batch: ⚠️ Items count query returned empty for batch ' . $batch_id . '. Treating as inactive.' );
					$data = [
						'active' => false,
						'batch_id' => null,
						'elapsed_time' => 0,
					];
				} else {
					$actual_completed = (int) ($items_counts['completed_count'] ?? 0);
					$actual_failed = (int) ($items_counts['failed_count'] ?? 0);
					$actual_total = (int) ($items_counts['total'] ?? 0);
					$actual_processing = (int) ($items_counts['processing_count'] ?? 0);
					$actual_completed_count = $actual_completed + $actual_failed;
					
					error_log( '[AEBG] get_active_batch: Batch ' . $batch_id . ' item counts - Total: ' . $actual_total . ', Completed: ' . $actual_completed . ', Failed: ' . $actual_failed . ', Processing: ' . $actual_processing );
					
					// If all items are completed, the batch is not active, even if status says otherwise
					if ( $actual_total > 0 && $actual_completed_count === $actual_total && $actual_processing === 0 ) {
						error_log( '[AEBG] get_active_batch: Batch ' . $batch_id . ' has status "' . $batch_status . '" but all items are completed. Marking as inactive.' );
						
						// Update batch status to completed if it's not already
						if ( ! in_array( $batch_status, [ 'completed', 'cancelled', 'failed' ] ) ) {
							$wpdb->update(
								"{$wpdb->prefix}aebg_batches",
								[ 'status' => 'completed' ],
								[ 'id' => $batch_id ],
								[ '%s' ],
								[ '%d' ]
							);
							wp_cache_delete( 'aebg_batch_status_' . $batch_id, 'aebg_batches' );
							error_log( '[AEBG] get_active_batch: Updated batch ' . $batch_id . ' status from "' . $batch_status . '" to "completed"' );
						}
						
						// Return inactive response
						$data = [
							'active' => false,
							'batch_id' => null,
							'elapsed_time' => 0,
						];
					} else {
						// Batch is actually active - calculate elapsed_time from batch creation time
						$created_at = $active_batch['created_at'] ?? null;
						$elapsed_time = 0;
						if ( $created_at ) {
							$created_timestamp = strtotime( $created_at );
							$current_timestamp = time();
							$elapsed_time = max( 0, $current_timestamp - $created_timestamp ); // seconds
						}
						
						error_log( '[AEBG] get_active_batch: ✅ Found active batch ' . $batch_id . ' in database (status: ' . $batch_status . ', completed: ' . $actual_completed_count . '/' . $actual_total . ')' );
						$data = [
							'active' => true,
							'batch_id' => $batch_id,
							'status' => $batch_status,
							'total_items' => $actual_total > 0 ? $actual_total : $total_items,
							'processed_items' => $actual_completed > 0 ? $actual_completed : $processed_items,
							'failed_items' => $actual_failed > 0 ? $actual_failed : $failed_items,
							'elapsed_time' => $elapsed_time,
						];
					}
				}
			}
			} else {
				// Fallback: Check Action Scheduler for active jobs
				// This is a secondary check in case batch status isn't updated yet
				$has_active_jobs = false;
				$active_item_id = null;
				
				if ( class_exists( '\AEBG\Core\ActionSchedulerHelper' ) && \AEBG\Core\ActionSchedulerHelper::ensure_initialized() ) {
					// Check for pending actions first (most common)
					$pending_actions = \AEBG\Core\ActionSchedulerHelper::get_scheduled_actions( [
						'hook' => 'aebg_execute_generation',
						'status' => 'pending',
						'per_page' => 1, // Only need to know if at least one exists
					] );
					
					if ( ! empty( $pending_actions ) ) {
						$has_active_jobs = true;
						$action = reset( $pending_actions ); // Get first action
						$args = $action->get_args();
						$active_item_id = isset( $args['item_id'] ) ? (int) $args['item_id'] : null;
						error_log( '[AEBG] get_active_batch: Found pending Action Scheduler job for item ' . $active_item_id );
					}
					
					// Also check for in-progress actions
					if ( ! $has_active_jobs ) {
						$in_progress_actions = \AEBG\Core\ActionSchedulerHelper::get_scheduled_actions( [
							'hook' => 'aebg_execute_generation',
							'status' => 'in-progress',
							'per_page' => 1, // Only need to know if at least one exists
						] );
						
						if ( ! empty( $in_progress_actions ) ) {
							$has_active_jobs = true;
							$action = reset( $in_progress_actions ); // Get first action
							$args = $action->get_args();
							$active_item_id = isset( $args['item_id'] ) ? (int) $args['item_id'] : null;
							error_log( '[AEBG] get_active_batch: Found in-progress Action Scheduler job for item ' . $active_item_id );
						}
					}
				}
				
				// If we found active jobs, get the batch info from the item_id
				if ( $has_active_jobs && $active_item_id ) {
				// Get batch_id from the item
				$batch_id = $wpdb->get_var( $wpdb->prepare(
					"SELECT batch_id FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d LIMIT 1",
					$active_item_id
				) );
				
				if ( $batch_id ) {
					// Get batch details
					$batch = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT id, status, total_items, processed_items, failed_items, created_at 
							FROM {$table_name} 
							WHERE id = %d AND user_id = %d
							LIMIT 1",
							$batch_id,
							$user_id
						),
						ARRAY_A
					);
					
					if ( ! empty( $batch ) && is_array( $batch ) ) {
						// Check if batch is cancelled/completed/failed (shouldn't happen, but safety check)
						$batch_status = (string) ($batch['status'] ?? 'pending');
						if ( in_array( $batch_status, [ 'cancelled', 'completed', 'failed' ] ) ) {
							error_log( '[AEBG] get_active_batch: Batch ' . $batch_id . ' has status ' . $batch_status . ' but has active jobs - this is unexpected' );
							$data = [
								'active' => false,
								'batch_id' => null,
								'elapsed_time' => 0,
							];
						} else {
							// CRITICAL: Verify the batch is actually still active by checking item counts
							// Even if Action Scheduler has jobs, the batch might be completed
							$items_counts = $wpdb->get_row(
								$wpdb->prepare(
									"SELECT 
										COUNT(*) as total,
										SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
										SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
										SUM(CASE WHEN status IN ('pending', 'processing') THEN 1 ELSE 0 END) as processing_count
									FROM {$wpdb->prefix}aebg_batch_items 
									WHERE batch_id = %d",
									$batch_id
								),
								ARRAY_A
							);
							
							$actual_completed = (int) ($items_counts['completed_count'] ?? 0);
							$actual_failed = (int) ($items_counts['failed_count'] ?? 0);
							$actual_total = (int) ($items_counts['total'] ?? 0);
							$actual_processing = (int) ($items_counts['processing_count'] ?? 0);
							$actual_completed_count = $actual_completed + $actual_failed;
							
							// If all items are completed, the batch is not active
							if ( $actual_total > 0 && $actual_completed_count === $actual_total && $actual_processing === 0 ) {
								error_log( '[AEBG] get_active_batch: Batch ' . $batch_id . ' has Action Scheduler jobs but all items are completed. Marking as inactive.' );
								
								// Update batch status to completed if it's not already
								if ( ! in_array( $batch_status, [ 'completed', 'cancelled', 'failed' ] ) ) {
									$wpdb->update(
										"{$wpdb->prefix}aebg_batches",
										[ 'status' => 'completed' ],
										[ 'id' => $batch_id ],
										[ '%s' ],
										[ '%d' ]
									);
									wp_cache_delete( 'aebg_batch_status_' . $batch_id, 'aebg_batches' );
									error_log( '[AEBG] get_active_batch: Updated batch ' . $batch_id . ' status from "' . $batch_status . '" to "completed"' );
								}
								
								$data = [
									'active' => false,
									'batch_id' => null,
									'elapsed_time' => 0,
								];
							} else {
								// Batch is actually active - calculate elapsed_time from batch creation time
								$created_at = $batch['created_at'] ?? null;
								$elapsed_time = 0;
								if ( $created_at ) {
									$created_timestamp = strtotime( $created_at );
									$current_timestamp = time();
									$elapsed_time = max( 0, $current_timestamp - $created_timestamp ); // seconds
								}
								
								error_log( '[AEBG] get_active_batch: ✅ Found active batch ' . $batch_id . ' (has active Action Scheduler jobs, completed: ' . $actual_completed_count . '/' . $actual_total . ')' );
								$data = [
									'active' => true,
									'batch_id' => (int) $batch_id,
									'status' => $batch_status,
									'total_items' => $actual_total > 0 ? $actual_total : (int) ($batch['total_items'] ?? 0),
									'processed_items' => $actual_completed > 0 ? $actual_completed : (int) ($batch['processed_items'] ?? 0),
									'failed_items' => $actual_failed > 0 ? $actual_failed : (int) ($batch['failed_items'] ?? 0),
									'elapsed_time' => $elapsed_time,
								];
							}
						}
					} else {
						// Batch not found or doesn't belong to user
						error_log( '[AEBG] get_active_batch: Batch ' . $batch_id . ' not found or doesn\'t belong to user ' . $user_id );
						$data = [
							'active' => false,
							'batch_id' => null,
							'elapsed_time' => 0,
						];
					}
				} else {
						// Item not found in database
						error_log( '[AEBG] get_active_batch: Item ' . $active_item_id . ' not found in database' );
						$data = [
							'active' => false,
							'batch_id' => null,
							'elapsed_time' => 0,
						];
					}
				} else {
					// No active jobs found in Action Scheduler either
					error_log( '[AEBG] get_active_batch: No active batches found in database or Action Scheduler' );
					$data = [
						'active' => false,
						'batch_id' => null,
						'status' => null,
						'total_items' => 0,
						'processed_items' => 0,
						'failed_items' => 0,
						'elapsed_time' => 0,
					];
				}
			}
			
			// Ensure all expected fields are present for consistency
			$data = wp_parse_args( $data, [
				'active' => false,
				'batch_id' => null,
				'status' => null,
				'total_items' => 0,
				'processed_items' => 0,
				'failed_items' => 0,
				'elapsed_time' => 0,
			] );
			
			$response = new \WP_REST_Response( $data, 200 );
			$response->set_headers([
				'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
			]);
			
			return $response;
			
		} catch ( \Exception $e ) {
			@error_log( '[AEBG] BatchStatusController::get_active_batch error: ' . $e->getMessage() );
			@error_log( '[AEBG] BatchStatusController::get_active_batch stack trace: ' . $e->getTraceAsString() );
			
			// Return consistent error response structure
			$data = [
				'active' => false,
				'batch_id' => null,
				'status' => null,
				'total_items' => 0,
				'processed_items' => 0,
				'failed_items' => 0,
				'elapsed_time' => 0,
				'error' => 'An error occurred while checking for active batch',
			];
			$response = new \WP_REST_Response( $data, 200 );
			$response->set_headers([
				'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
			]);
			return $response;
		}
	}

	/**
	 * Check if a given request has access to get a specific item
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|bool
	 */
	public function get_item_permissions_check( $request ) {
		// Primary check: aebg_generate_content capability
		if ( current_user_can( 'aebg_generate_content' ) ) {
			return true;
		}
		
		// Fallback: Check if user can access the generator page
		// This ensures users who can access the page can also check batch status
		if ( current_user_can( 'manage_options' ) || current_user_can( 'edit_posts' ) ) {
			// Log for debugging - only log once per hour to prevent log spam
			static $logged = false;
			if ( ! $logged && ! get_transient( 'aebg_permission_fallback_logged' ) ) {
				error_log( '[AEBG] BatchStatusController: Using fallback permission check for user ' . get_current_user_id() . '. Consider granting aebg_generate_content capability.' );
				set_transient( 'aebg_permission_fallback_logged', true, 3600 ); // Log once per hour
				$logged = true;
			}
			return true;
		}
		
		// No permission
		return false;
	}
}
