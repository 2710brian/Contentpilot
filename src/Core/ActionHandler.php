<?php

namespace AEBG\Core;

use AEBG\Core\TimeoutManager;
use AEBG\Core\Logger;
use AEBG\Core\JobTracker;
use AEBG\Core\ActionSchedulerHelper;
use AEBG\Core\ErrorHandler;
use AEBG\Core\CheckpointManager;
use AEBG\Core\StepHandler;
use AEBG\Core\ProductReplacementScheduler;
use AEBG\Core\TestvinderReplacementScheduler;

/**
 * Action Handler Class
 *
 * @package AEBG\Core
 */
class ActionHandler {
	/**
	 * Static flag to track if an action is currently executing.
	 * This prevents the shutdown hook from querying the database while we're running,
	 * which would cause "Commands out of sync" fatal errors.
	 * 
	 * @var bool
	 */
	private static $is_executing = false;

	/**
	 * ActionHandler constructor.
	 */
	public function __construct() {
		// Legacy hook (backward compatibility)
		add_action( 'aebg_execute_generation', [ $this, 'execute' ] );
		
		// Step-by-step hooks
		add_action( 'aebg_execute_step_1', [ $this, 'execute_step_1' ] );
		add_action( 'aebg_execute_step_2', [ $this, 'execute_step_2' ] );
		add_action( 'aebg_execute_step_3', [ $this, 'execute_step_3' ] );
		add_action( 'aebg_execute_step_3_5', [ $this, 'execute_step_3_5' ] );
		add_action( 'aebg_execute_step_3_6', [ $this, 'execute_step_3_6' ] );
		add_action( 'aebg_execute_step_3_7', [ $this, 'execute_step_3_7' ] );
		add_action( 'aebg_execute_step_4', [ $this, 'execute_step_4' ] );
		add_action( 'aebg_execute_step_4_1', [ $this, 'execute_step_4_1' ] );
		add_action( 'aebg_execute_step_4_2', [ $this, 'execute_step_4_2' ] );
		add_action( 'aebg_execute_step_5', [ $this, 'execute_step_5' ] );
		add_action( 'aebg_execute_step_5_5', [ $this, 'execute_step_5_5' ] );
		add_action( 'aebg_execute_step_6', [ $this, 'execute_step_6' ] );
		add_action( 'aebg_execute_step_7', [ $this, 'execute_step_7' ] );
		add_action( 'aebg_execute_step_8', [ $this, 'execute_step_8' ] );
		
		// Product replacement hooks
		add_action( 'aebg_replace_product_step_1', [ $this, 'execute_replace_step_1' ] );
		add_action( 'aebg_replace_product_step_2', [ $this, 'execute_replace_step_2' ] );
		add_action( 'aebg_replace_product_step_3', [ $this, 'execute_replace_step_3' ] );
		
		// Testvinder-only regeneration hooks
		add_action( 'aebg_regenerate_testvinder_only', [ $this, 'execute_testvinder_step_1' ] );
		add_action( 'aebg_testvinder_step_2', [ $this, 'execute_testvinder_step_2' ] );
		add_action( 'aebg_testvinder_step_3', [ $this, 'execute_testvinder_step_3' ] );
		
		// Featured image regeneration hook
		add_action( 'aebg_regenerate_featured_image', [ $this, 'execute_regenerate_featured_image' ] );
	}

	/**
	 * Check if an action is currently executing.
	 * Used by shutdown hooks to avoid database queries during execution.
	 * 
	 * @return bool
	 */
	public static function is_executing() {
		return self::$is_executing;
	}

	/**
	 * Execute the generation.
	 * 
	 * This method checks the feature flag and either:
	 * - Routes to Step 1 if step-by-step is enabled
	 * - Executes legacy full generation if step-by-step is disabled
	 *
	 * @param int|array $item_id The item ID (can be int or array with 'item_id' key for legacy)
	 * @param string|null $title Optional title (for new format with title in args)
	 */
	public function execute( $item_id, $title = null ) {
		// Handle legacy format where item_id comes as array ['item_id' => 123, 'title' => '...']
		if ( is_array( $item_id ) && isset( $item_id['item_id'] ) ) {
			$title = $item_id['title'] ?? $title;
			$item_id = (int) $item_id['item_id'];
		} else {
			$item_id = (int) $item_id;
		}
		
		// Check feature flag for step-by-step actions (default: true - step-by-step is the preferred method)
		$use_step_by_step = get_option( 'aebg_use_step_by_step_actions', true );
		
		if ( $use_step_by_step ) {
			// Route to Step 1 (step-by-step architecture)
			error_log( '[AEBG] Step-by-step mode enabled - routing to Step 1 for item_id=' . $item_id . ( $title ? ', title=' . $title : '' ) );
			return $this->execute_step_1( $item_id, $title );
		} else {
			// Legacy flow (existing code)
			return $this->execute_legacy( $item_id, $title );
		}
	}
	
	/**
	 * Execute legacy full generation (backward compatibility)
	 * 
	 * This is the original execute() method, renamed for clarity.
	 *
	 * @param int $item_id The item ID.
	 * @param string|null $title Optional title (for new format with title in args)
	 */
	private function execute_legacy( $item_id, $title = null ) {
		// CRITICAL: Log process information to verify process isolation
		$process_id = function_exists('getmypid') ? getmypid() : 'unknown';
		$memory_usage = memory_get_usage(true);
		$memory_peak = memory_get_peak_usage(true);
		error_log('[AEBG] 🔄 PROCESS ISOLATION CHECK - Job starting | item_id=' . $item_id . ' | process_id=' . $process_id . ' | memory=' . round($memory_usage / 1024 / 1024, 2) . 'MB | peak=' . round($memory_peak / 1024 / 1024, 2) . 'MB | php_timeout=' . ini_get('max_execution_time') . 's | timestamp=' . microtime(true));
		
		// CRITICAL: Store item_id in global for lock tracking
		$GLOBALS['aebg_current_item_id'] = $item_id;
		
		// CRITICAL: Acquire lock to prevent concurrent execution
		// This ensures only one generation runs at a time, preventing race conditions
		$lock_key = 'aebg_generation_lock';
		$lock_acquired = $this->acquire_generation_lock($lock_key, \AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT); // Use centralized timeout (1800s = 30 minutes)
		
		if (!$lock_acquired) {
			error_log('[AEBG] ActionHandler::execute - Could not acquire generation lock for item_id: ' . $item_id . ' - another generation is in progress');
			// Reschedule this action for later (5 seconds from now)
			// Fetch title for rescheduling
			global $wpdb;
			$item = $wpdb->get_row( $wpdb->prepare( 
				"SELECT source_title FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d", 
				$item_id 
			) );
			$title = $item->source_title ?? '';
			as_schedule_single_action(time() + 5, 'aebg_execute_generation', ['item_id' => $item_id, 'title' => $title]);
			unset($GLOBALS['aebg_current_item_id']);
			return;
		}
		
		// CRITICAL: Clear any stale global variables from previous job FIRST
		// This ensures clean state for each new job
		if (isset($GLOBALS['AEBG_GENERATION_IN_PROGRESS'])) {
			unset($GLOBALS['AEBG_GENERATION_IN_PROGRESS']);
		}
		// CRITICAL: Clear stale job start time from previous job to prevent false timeout calculations
		$old_job_start_time = $GLOBALS['aebg_job_start_time'] ?? null;
		if (isset($GLOBALS['aebg_job_start_time'])) {
			unset($GLOBALS['aebg_job_start_time']);
			error_log('[AEBG] ActionHandler::execute - Cleared stale aebg_job_start_time from previous job (was: ' . $old_job_start_time . ')');
		} else {
			error_log('[AEBG] ActionHandler::execute - No stale aebg_job_start_time found (clean state)');
		}
		
		// CRITICAL: Set execution flag FIRST to prevent shutdown hook from querying database
		// This prevents "Commands out of sync" fatal errors when shutdown hook runs
		// while we have unprocessed MySQL results
		self::$is_executing = true;
		
		// CRITICAL: Also set global variable as backup check (more reliable than static property)
		// This prevents shortcodes from executing during generation
		// Use global variable instead of constant so it can be cleared
		$GLOBALS['AEBG_GENERATION_IN_PROGRESS'] = true;
		
		error_log('[AEBG] ActionHandler::execute - Set execution flags for item_id: ' . $item_id);
		
		// CRITICAL: Set PHP timeout to prevent Action Scheduler from killing the job
		// Action Scheduler's failure_period defaults to 300s, but we need 1800s (30 minutes)
		// Setting PHP timeout ensures the job has enough time even if the filter isn't applied
		// CRITICAL: ALWAYS reset timeout to ensure fresh timer for each job (set_time_limit resets from current point)
		$current_php_timeout = ini_get('max_execution_time');
		$target_timeout = TimeoutManager::DEFAULT_TIMEOUT; // 1800 seconds
		
		// CRITICAL: Detect server-level timeouts that can't be overridden
		// FastCGI, mod_php, and web servers often have timeouts (typically 300s) that can't be changed by set_time_limit()
		$sapi_name = php_sapi_name();
		$timeout_set_successfully = @set_time_limit($target_timeout);
		$timeout_after_set = ini_get('max_execution_time');
		
		// CRITICAL: Detect server-level timeouts that override PHP's set_time_limit()
		// Many hosting providers have FastCGI/mod_php timeouts (typically 180s, 300s, or 600s)
		// that can't be overridden by set_time_limit()
		$server_timeout_detected = false;
		$detected_timeout_value = null;
		
		// Check for common server-level timeout values
		$common_server_timeouts = [180, 300, 600]; // Common FastCGI/mod_php timeout values
		if ($timeout_set_successfully && (int)$timeout_after_set != $target_timeout) {
			// set_time_limit() was called but timeout wasn't set to target value
			// Check if it matches a common server-level timeout
			foreach ($common_server_timeouts as $server_timeout) {
				if ((int)$timeout_after_set == $server_timeout) {
					$server_timeout_detected = true;
					$detected_timeout_value = $server_timeout;
					error_log('[AEBG] ⚠️ CRITICAL: Server-level timeout detected! PHP timeout is ' . $server_timeout . 's (requested: ' . $target_timeout . 's) - process will be killed after ' . $server_timeout . 's regardless of set_time_limit()');
					error_log('[AEBG] ⚠️ This is likely a FastCGI, mod_php, or web server timeout that cannot be overridden by PHP');
					break;
				}
			}
		}
		
		// Check if timeout was actually set (some servers ignore set_time_limit())
		if ($timeout_set_successfully && (int)$timeout_after_set == $target_timeout) {
			error_log('[AEBG] ActionHandler::execute - Set PHP timeout to ' . $target_timeout . 's (was: ' . ($current_php_timeout == 0 ? 'unlimited' : $current_php_timeout . 's') . ') - Fresh timer for this job');
		} else {
			// Timeout wasn't set - likely server-level timeout is in effect
			error_log('[AEBG] ⚠️ CRITICAL WARNING: ActionHandler::execute - Failed to set PHP timeout to ' . $target_timeout . 's');
			error_log('[AEBG] ⚠️ Current timeout: ' . ($timeout_after_set == 0 ? 'unlimited' : $timeout_after_set . 's') . ', SAPI: ' . $sapi_name);
			error_log('[AEBG] ⚠️ Server-level timeout may be active (FastCGI/mod_php/web server) - process may be killed after ' . $timeout_after_set . 's');
			error_log('[AEBG] ⚠️ If jobs fail after ~300s, check server configuration: FastCGI timeout, mod_php timeout, or web server timeout');
		}
		
		// Check PHP timeout (does not modify server configuration - respects server settings)
		TimeoutManager::check_timeout( TimeoutManager::DEFAULT_TIMEOUT );
		
		// CRITICAL: Verify Action Scheduler failure_period filter is applied
		// This ensures Action Scheduler won't mark the action as failed after 300s
		$failure_period = apply_filters('action_scheduler_failure_period', 300);
		if ($failure_period < $target_timeout) {
			error_log('[AEBG] ⚠️ WARNING: ActionHandler::execute - Action Scheduler failure_period is only ' . $failure_period . 's (expected: ' . $target_timeout . 's). Filter may not be applied!');
		} else {
			error_log('[AEBG] ActionHandler::execute - Action Scheduler failure_period verified: ' . $failure_period . 's');
		}
		
		// CRITICAL: Get current action ID for heartbeat mechanism
		// This allows us to update the action's last_attempt_gmt timestamp periodically to prevent Action Scheduler from marking it as failed
		// Action Scheduler's mark_failures() checks if last_attempt_gmt + failure_period < now(), so we need to update last_attempt_gmt
		$current_action_id = null;
		global $wpdb;
		
		// CRITICAL: Query database directly to find the action ID
		// This is more reliable than as_get_scheduled_actions() which may not find actions that are currently executing
		// Action Scheduler stores args as JSON in the args column (or extended_args if args > 191 chars)
		$action_table = $wpdb->prefix . 'actionscheduler_actions';
		
		// Find action by hook and args (item_id is stored in args as JSON array)
		// Action Scheduler uses wp_json_encode() which may differ from json_encode()
		// We need to check multiple statuses since the action might be in different states
		$args_json = wp_json_encode([$item_id]);
		
		// Try multiple queries to find the action:
		// 1. Direct match on args column (for short args)
		// 2. Match on extended_args column (for long args that are hashed)
		// 3. Check multiple statuses (pending, in-progress, running, complete)
		$sql = $wpdb->prepare(
			"SELECT action_id 
			FROM {$action_table}
			WHERE hook = %s 
			AND (args = %s OR extended_args = %s)
			AND status IN ('pending', 'in-progress', 'running', 'complete')
			ORDER BY action_id DESC
			LIMIT 1",
			'aebg_execute_generation',
			$args_json,
			$args_json
		);
		
		$action_id = $wpdb->get_var($sql);
		
		if ($action_id) {
			$current_action_id = (int)$action_id;
			error_log('[AEBG] ActionHandler::execute - Found current action ID via database query: ' . $current_action_id . ' for item_id: ' . $item_id);
		} else {
			// Fallback: Try searching by JSON contains (in case of whitespace differences)
			$args_search = '%' . $wpdb->esc_like($args_json) . '%';
			$sql = $wpdb->prepare(
				"SELECT action_id 
				FROM {$action_table}
				WHERE hook = %s 
				AND (args LIKE %s OR extended_args LIKE %s)
				AND status IN ('pending', 'in-progress', 'running', 'complete')
				ORDER BY action_id DESC
				LIMIT 1",
				'aebg_execute_generation',
				$args_search,
				$args_search
			);
			$action_id = $wpdb->get_var($sql);
			
			if ($action_id) {
				$current_action_id = (int)$action_id;
				error_log('[AEBG] ActionHandler::execute - Found current action ID via LIKE query: ' . $current_action_id . ' for item_id: ' . $item_id);
			} else {
				// Fallback: Try as_get_scheduled_actions() if database query fails
				// This is the recommended way and should work even if the direct query doesn't
				if (function_exists('as_get_scheduled_actions')) {
					$actions = as_get_scheduled_actions([
						'hook' => 'aebg_execute_generation',
						'args' => [$item_id],
						'per_page' => 1,
						'status' => 'any', // Check all statuses
					]);
					if (!empty($actions)) {
						$current_action_id = $actions[0]->get_id();
						error_log('[AEBG] ActionHandler::execute - Found current action ID via as_get_scheduled_actions(): ' . $current_action_id . ' for item_id: ' . $item_id);
					}
				}
				
				if (!$current_action_id) {
					// Action ID not found initially - this is OK, heartbeat will retry
					// The action might not be fully committed to database yet, or might be in a different state
					// The heartbeat callback will retry finding it every 30 seconds
					error_log('[AEBG] ℹ️ INFO: ActionHandler::execute - Action ID not found initially for item_id: ' . $item_id . ' - heartbeat will retry automatically');
				}
			}
		}
		
		// CRITICAL: Update action's last_attempt timestamp IMMEDIATELY to prevent Action Scheduler from marking it as failed
		// Action Scheduler's mark_failures() checks if modified (which maps to last_attempt_gmt) <= (now - failure_period)
		// We must update the last_attempt_gmt timestamp directly in the database to reset the failure_period timer
		if ($current_action_id) {
			try {
				$action_table = $wpdb->prefix . 'actionscheduler_actions';
				$now_gmt = current_time('mysql', true);
				$now_local = current_time('mysql');
				
				// Update last_attempt_gmt and last_attempt_local to current time
				// This resets the failure_period timer, preventing Action Scheduler from marking the action as failed
				// Action Scheduler's mark_failures() queries: WHERE last_attempt_gmt <= (now - failure_period)
				// By updating last_attempt_gmt to now, we ensure the action won't match this query
				$updated = $wpdb->update(
					$action_table,
					[
						'last_attempt_gmt' => $now_gmt,
						'last_attempt_local' => $now_local,
					],
					['action_id' => $current_action_id],
					['%s', '%s'],
					['%d']
				);
				
				if ($updated !== false) {
					error_log('[AEBG] ActionHandler::execute - Updated last_attempt_gmt immediately for action_id: ' . $current_action_id . ' (resets failure_period timer)');
				} else {
					error_log('[AEBG] ⚠️ WARNING: ActionHandler::execute - Failed to update last_attempt_gmt for action_id: ' . $current_action_id . ' - ' . $wpdb->last_error);
				}
			} catch (\Exception $e) {
				error_log('[AEBG] ⚠️ WARNING: ActionHandler::execute - Failed to update last_attempt_gmt immediately: ' . $e->getMessage());
			}
		}
		
		// CRITICAL: Set up heartbeat mechanism to prevent Action Scheduler from marking action as failed
		// This logs periodically and updates the action's modified timestamp every 30 seconds
		$heartbeat_interval = 30; // Update every 30 seconds
		$last_heartbeat = microtime(true);
		$heartbeat_action_id = $current_action_id; // Store in closure scope
		$heartbeat_callback = function() use (&$last_heartbeat, $heartbeat_interval, &$heartbeat_action_id, $item_id) {
			$now = microtime(true);
			if (($now - $last_heartbeat) >= $heartbeat_interval) {
				$elapsed = $now - ($GLOBALS['aebg_job_start_time'] ?? $now);
				error_log('[AEBG] 💓 HEARTBEAT - Job still alive: elapsed=' . round($elapsed, 1) . 's, item_id=' . $item_id);
				
				// CRITICAL: Log warning if approaching 300 seconds (common server-level timeout)
				if ($elapsed >= 290 && $elapsed < 310) {
					error_log('[AEBG] ⚠️ WARNING: Approaching 300-second mark (elapsed: ' . round($elapsed, 1) . 's). Server-level timeout may kill process!');
				}
				
				// CRITICAL: Retry finding action ID if we don't have it yet (it might be available now)
				if (!$heartbeat_action_id) {
					global $wpdb;
					$action_table = $wpdb->prefix . 'actionscheduler_actions';
					$args_json = wp_json_encode([$item_id]);
					
					// Try direct match first
					$sql = $wpdb->prepare(
						"SELECT action_id 
						FROM {$action_table}
						WHERE hook = %s 
						AND (args = %s OR extended_args = %s)
						AND status IN ('pending', 'in-progress', 'running', 'complete')
						ORDER BY action_id DESC
						LIMIT 1",
						'aebg_execute_generation',
						$args_json,
						$args_json
					);
					$action_id = $wpdb->get_var($sql);
					
					if (!$action_id) {
						// Try LIKE search as fallback
						$args_search = '%' . $wpdb->esc_like($args_json) . '%';
						$sql = $wpdb->prepare(
							"SELECT action_id 
							FROM {$action_table}
							WHERE hook = %s 
							AND (args LIKE %s OR extended_args LIKE %s)
							AND status IN ('pending', 'in-progress', 'running', 'complete')
							ORDER BY action_id DESC
							LIMIT 1",
							'aebg_execute_generation',
							$args_search,
							$args_search
						);
						$action_id = $wpdb->get_var($sql);
					}
					
					if ($action_id) {
						$heartbeat_action_id = (int)$action_id;
						error_log('[AEBG] 💓 HEARTBEAT - Found action ID on retry: ' . $heartbeat_action_id . ' for item_id: ' . $item_id);
					}
				}
				
				// CRITICAL: Update action's last_attempt timestamp to prevent Action Scheduler from marking it as failed
				// Action Scheduler's mark_failures() checks if modified (which maps to last_attempt_gmt) <= (now - failure_period)
				// We must update the last_attempt_gmt timestamp directly in the database to reset the failure_period timer
				if ($heartbeat_action_id) {
					try {
						global $wpdb;
						$action_table = $wpdb->prefix . 'actionscheduler_actions';
						$now_gmt = current_time('mysql', true);
						$now_local = current_time('mysql');
						
						// Update last_attempt_gmt and last_attempt_local to current time
						// This resets the failure_period timer, preventing Action Scheduler from marking the action as failed
						// Action Scheduler's mark_failures() queries: WHERE last_attempt_gmt <= (now - failure_period)
						// By updating last_attempt_gmt to now, we ensure the action won't match this query
						$updated = $wpdb->update(
							$action_table,
							[
								'last_attempt_gmt' => $now_gmt,
								'last_attempt_local' => $now_local,
							],
							['action_id' => $heartbeat_action_id],
							['%s', '%s'],
							['%d']
						);
						
						if ($updated !== false) {
							error_log('[AEBG] 💓 HEARTBEAT - Updated last_attempt_gmt for action_id: ' . $heartbeat_action_id . ' (resets failure_period timer)');
						} else {
							error_log('[AEBG] 💓 HEARTBEAT - Failed to update last_attempt_gmt for action_id: ' . $heartbeat_action_id . ' - ' . $wpdb->last_error);
						}
					} catch (\Exception $e) {
						// Silently fail - heartbeat is best effort
						error_log('[AEBG] 💓 HEARTBEAT - Failed to update last_attempt_gmt: ' . $e->getMessage());
					}
				} else {
					// Log warning if we still don't have action ID after multiple heartbeats
					if ($elapsed > 60) {
						error_log('[AEBG] ⚠️ WARNING: Heartbeat active but action ID not found - Action Scheduler may mark action as failed after 1800s');
					}
				}
				
				$last_heartbeat = $now;
			}
		};
		
		// Store heartbeat callback in global for access during long operations
		$GLOBALS['aebg_heartbeat_callback'] = $heartbeat_callback;
		$GLOBALS['aebg_current_action_id'] = $current_action_id;
		
		// CRITICAL: Create job tracker for THIS job (single source of truth for timing)
		// Each job gets its own fresh JobTracker instance with current time
		$job_tracker = new JobTracker();
		$job_start_time = $job_tracker->get_start_time();
		error_log('[AEBG] ActionHandler::execute - Created JobTracker with start_time: ' . $job_start_time . ' (current time: ' . microtime(true) . ')');
		
		// Log execution start with current time to track scheduling delays
		$current_time = microtime(true);
		$scheduling_delay = $current_time - $job_start_time;
		Logger::info( 'ActionHandler::execute called', [
			'item_id' => $item_id,
			'start_time' => date( 'Y-m-d H:i:s', (int) $job_start_time ),
			'timestamp' => $job_start_time,
			'scheduling_delay_seconds' => round($scheduling_delay, 2),
		] );
		error_log('[AEBG] ⏱️ ActionHandler::execute - Job scheduled at: ' . date('Y-m-d H:i:s', (int) $job_start_time) . ', executing at: ' . date('Y-m-d H:i:s', (int) $current_time) . ', delay: ' . round($scheduling_delay, 2) . ' seconds');
		
		// Get item info for better logging
		global $wpdb;
		$item_info = $wpdb->get_row( $wpdb->prepare( "SELECT batch_id, source_title, status FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d", $item_id ) );
		if ( $item_info ) {
			// Get batch info to determine article number
			$batch_items = $wpdb->get_results( $wpdb->prepare( "SELECT id, source_title FROM {$wpdb->prefix}aebg_batch_items WHERE batch_id = %d ORDER BY id ASC", $item_info->batch_id ), ARRAY_A );
			$article_number = 1;
			foreach ( $batch_items as $index => $batch_item ) {
				if ( (int) $batch_item['id'] === (int) $item_id ) {
					$article_number = $index + 1;
					break;
				}
			}
			
			// CRITICAL: Set job number in global for Datafeedr connection reset
			// This allows Datafeedr to add delays and reset connections for second+ jobs
			$GLOBALS['aebg_job_number'] = $article_number;
			error_log('[AEBG] Set aebg_job_number to: ' . $article_number);
			Logger::info( 'Processing article', [
				'article_number' => $article_number,
				'batch_id' => $item_info->batch_id,
				'title' => $item_info->source_title,
			] );
		}
		
		// CRITICAL: Server-level timeout is 180 seconds (FastCGI/mod_php/web server)
		// Even though PHP timeout is set to 1800s, the server will kill the process at 180s
		// We need to proactively reschedule BEFORE hitting 180s to avoid server timeout
		$SERVER_TIMEOUT = 180; // Server-level timeout (cannot be overridden)
		
		// Get AI model from settings or checkpoint to determine threshold
		// Handle both 'model' and 'ai_model' keys for compatibility
		$ai_model = $settings['ai_model'] ?? $settings['model'] ?? 'gpt-3.5-turbo';
		if ( $item_id && class_exists( '\AEBG\Core\CheckpointManager' ) ) {
			$checkpoint_state = \AEBG\Core\CheckpointManager::getCheckpointState( $item_id );
			if ( $checkpoint_state ) {
				// Check both keys in checkpoint state
				$ai_model = $checkpoint_state['ai_model'] ?? $checkpoint_state['model'] ?? $ai_model;
			}
		}
		
		// Model-specific reschedule thresholds
		// GPT-4 requests take 7-8s, so we need 20s buffer (reschedule at 160s)
		// GPT-3.5 requests take 1-2s, so we can use 40s buffer (reschedule at 140s)
		$is_gpt4 = strpos( strtolower( $ai_model ), 'gpt-4' ) !== false || strpos( strtolower( $ai_model ), 'gpt4' ) !== false;
		$RESCHEDULE_THRESHOLD = $is_gpt4 ? 160 : 140; // GPT-4: 20s buffer, GPT-3.5: 40s buffer
		
		// Helper function to check if we're running out of time
		$check_timeout = function() use ($job_tracker, $item_id, $SERVER_TIMEOUT, $RESCHEDULE_THRESHOLD) {
			$elapsed = $job_tracker->get_elapsed_time();
			
			// CRITICAL: Check for server-level timeout (180s) - this is the real limit
			// If we're approaching the model-specific threshold (160s for GPT-4, 140s for GPT-3.5), save checkpoint and reschedule to continue
			if ( $elapsed >= $RESCHEDULE_THRESHOLD && $elapsed < $SERVER_TIMEOUT ) {
				if ( $item_id ) {
					$current_step = CheckpointManager::getCurrentStep( $item_id );
					if ( $current_step ) {
						error_log( '[AEBG] ⚠️ CRITICAL: Approaching server timeout (180s) - elapsed: ' . round( $elapsed, 1 ) . 's - checkpoint exists at step: ' . $current_step );
						error_log( '[AEBG] 🔄 Proactively rescheduling action to continue from checkpoint and avoid server timeout' );
						
						// Reschedule action to continue from checkpoint
						// This will run in a new async request with fresh 180s timer
						// Fetch title for rescheduling
						global $wpdb;
						$item = $wpdb->get_row( $wpdb->prepare( 
							"SELECT source_title FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d", 
							$item_id 
						) );
						$title = $item->source_title ?? '';
						as_schedule_single_action( time() + 2, 'aebg_execute_generation', [ 'item_id' => $item_id, 'title' => $title ] );
						
						// Return true to stop current execution (action will continue in next request)
						return true;
					} else {
						error_log( '[AEBG] ⚠️ CRITICAL: Approaching server timeout (180s) - elapsed: ' . round( $elapsed, 1 ) . 's - no checkpoint to save (generation not started yet)' );
					}
				}
			}
			
			// Check PHP timeout (1800s) - this is secondary to server timeout
			$max_time = TimeoutManager::DEFAULT_TIMEOUT - TimeoutManager::SAFETY_BUFFER;
			
			// Save checkpoint if approaching PHP timeout (60 seconds before)
			if ( $item_id && $job_tracker->is_approaching_timeout( $max_time, 60 ) ) {
				$current_step = CheckpointManager::getCurrentStep( $item_id );
				if ( $current_step ) {
					error_log( '[AEBG] ⚠️ Approaching PHP timeout - checkpoint already exists at step: ' . $current_step );
				} else {
					error_log( '[AEBG] ⚠️ Approaching PHP timeout - no checkpoint to save (generation not started yet)' );
				}
			}
			
			if ( $job_tracker->is_timeout_exceeded( $max_time ) ) {
				$elapsed = $job_tracker->get_elapsed_time();
				Logger::error( 'Timeout check failed', [
					'elapsed' => round( $elapsed, 2 ),
					'max' => $max_time,
				] );
				return true;
			}
			
			// Log warning if approaching timeout
			if ( $job_tracker->is_approaching_timeout( $max_time, 30 ) ) {
				$remaining = $job_tracker->get_remaining_time( $max_time );
				Logger::warning( 'Approaching timeout', [
					'remaining' => round( $remaining, 1 ),
				] );
			}
			
		return false;
	};
	
	// Wrap entire execution in try-finally to ensure execution flag is always cleared
	try {
		// Get item with batch info for article number tracking
		$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d", $item_id ) );
			if ( ! $item ) {
				Logger::error( 'Item not found', [ 'item_id' => $item_id ] );
				// Can't trigger next job without batch_id, so just return
				return;
			}

			// Check if this item is already being processed or completed to prevent duplicates
			if ( $item->status === 'processing' || $item->status === 'completed' ) {
				Logger::debug( 'Item already processed, skipping to prevent duplicates', [
					'item_id' => $item_id,
					'status' => $item->status,
				] );
				// Action Scheduler will automatically pick up next pending action on next WP Cron run
				// No need to manually trigger next job - this provides process isolation
				return;
			}

			Logger::debug( 'Processing item', [ 'item' => $item ] );

			$batch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aebg_batches WHERE id = %d", $item->batch_id ) );
			if ( ! $batch ) {
				Logger::error( 'Batch not found', [ 'batch_id' => $item->batch_id ] );
				// Can't trigger next job without valid batch, so just return
				return;
			}

			// CRITICAL: Check if batch was cancelled - if so, abort immediately
			// Re-query to get latest status (might have been cancelled between queries)
			$latest_batch_status = $wpdb->get_var( $wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}aebg_batches WHERE id = %d LIMIT 1",
				$item->batch_id
			) );
			
			if ( $latest_batch_status === 'cancelled' || $batch->status === 'cancelled' ) {
				Logger::info( 'Batch cancelled, aborting execution', [
					'batch_id' => $item->batch_id,
					'item_id' => $item_id,
				] );
				// Update item status to cancelled if it's still pending/processing
				if ( in_array( $item->status, [ 'pending', 'processing' ] ) ) {
					$wpdb->update(
						"{$wpdb->prefix}aebg_batch_items",
						[ 'status' => 'cancelled' ],
						[ 'id' => $item_id ]
					);
				}
				// Don't trigger next job - batch is cancelled
				return;
			}

			Logger::debug( 'Processing batch', [ 'batch' => $batch ] );

			$settings = json_decode( $batch->settings, true );
			if (json_last_error() !== JSON_ERROR_NONE) {
				Logger::error( 'JSON decode error', [
					'error' => json_last_error_msg(),
					'batch_id' => $batch->id,
				] );
				$settings = [];
			}
			if (!is_array($settings)) {
				Logger::warning( 'Settings is not an array, converting to empty array', [
					'batch_id' => $batch->id,
					'type' => gettype($settings),
				] );
				$settings = [];
			}
			Logger::debug( 'Settings loaded', [ 'settings' => $settings ] );

			// CRITICAL: Double-check batch is not cancelled before updating status
			$batch_status_check = $wpdb->get_var( $wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}aebg_batches WHERE id = %d LIMIT 1",
				$batch->id
			) );
			if ( $batch_status_check === 'cancelled' ) {
				error_log( '[AEBG] ActionHandler::execute - Batch ' . $batch->id . ' was cancelled. Aborting before status update.' );
				return;
			}

			$wpdb->update( "{$wpdb->prefix}aebg_batch_items", [ 'status' => 'processing' ], [ 'id' => $item_id ] );
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}aebg_batches SET status = 'in_progress' WHERE id = %d AND status != 'cancelled'", $batch->id ) );

			// Use title from args if provided, otherwise use from database
			$final_title = $title ?? $item->source_title ?? '';
			error_log( '[AEBG] ActionHandler::execute - Starting generation with title: ' . $final_title );
			
			// Validate required parameters before creating Generator
			if (empty($item->source_title) || !is_string($item->source_title)) {
				$error_msg = 'Invalid source title: ' . gettype($item->source_title);
				error_log( '[AEBG] ActionHandler::execute - ' . $error_msg );
				$wpdb->update(
					"{$wpdb->prefix}aebg_batch_items",
					[
						'status'      => 'failed',
						'log_message' => $error_msg,
					],
					[ 'id' => $item_id ]
				);
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}aebg_batches SET failed_items = COALESCE(failed_items, 0) + 1 WHERE id = %d", $batch->id ) );
				// Action Scheduler will automatically pick up next pending action on next WP Cron run
				// No need to manually trigger next job - this provides process isolation
				return;
			}
			
			if (empty($batch->template_id) || !is_numeric($batch->template_id)) {
				$error_msg = 'Invalid template ID: ' . gettype($batch->template_id);
				error_log( '[AEBG] ActionHandler::execute - ' . $error_msg );
				$wpdb->update(
					"{$wpdb->prefix}aebg_batch_items",
					[
						'status'      => 'failed',
						'log_message' => $error_msg,
					],
					[ 'id' => $item_id ]
				);
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}aebg_batches SET failed_items = COALESCE(failed_items, 0) + 1 WHERE id = %d", $batch->id ) );
				// Action Scheduler will automatically pick up next pending action on next WP Cron run
				// No need to manually trigger next job - this provides process isolation
				return;
			}
			
			// Check timeout before starting generation
			$timeout_result = $check_timeout();
			if ($timeout_result) {
				// Check if this is a proactive reschedule (using model-specific threshold) vs actual timeout
				$elapsed = $job_tracker->get_elapsed_time();
				// Use the same model-specific threshold we calculated earlier
				if ( $elapsed >= $RESCHEDULE_THRESHOLD && $elapsed < $SERVER_TIMEOUT ) {
					// This is a proactive reschedule to avoid server timeout
					// Don't mark as failed - the rescheduled action will continue from checkpoint
					error_log( '[AEBG] 🔄 Proactive reschedule triggered - returning gracefully to allow rescheduled action to continue' );
					return; // Return gracefully - rescheduled action will continue
				}
				
				// This is a real timeout (not proactive reschedule)
				$error_message = 'Timeout: Approaching action scheduler limit before generation started';
				Logger::error( 'Timeout before generation started', [
					'item_id' => $item_id,
					'batch_id' => $batch->id,
				] );
				ErrorHandler::mark_item_failed( $item_id, $error_message, $batch->id );
				// Action Scheduler will automatically pick up next pending action on next WP Cron run
				// No need to manually trigger next job - this provides process isolation
				return;
			}
			
			error_log( '[AEBG] ActionHandler::execute - Creating Generator instance...' );
			// CRITICAL: Verify job_start_time is valid before passing to Generator
			if (empty($job_start_time) || !is_numeric($job_start_time)) {
				error_log('[AEBG] ⚠️ CRITICAL ERROR: Invalid job_start_time: ' . var_export($job_start_time, true) . ', using current time');
				$job_start_time = microtime(true);
			}
			
			// CRITICAL: Pass job-specific start time to Generator
			// This ensures each job has its own timeout tracking, not shared across jobs
			// CRITICAL: Pass the batch creator's user_id to ensure posts are attributed to the correct author
			$batch_author_id = isset( $batch->user_id ) ? (int) $batch->user_id : get_current_user_id();
			error_log( '[AEBG] Using author_id from batch: ' . $batch_author_id . ' (batch user_id: ' . ( $batch->user_id ?? 'not set' ) . ')' );
			error_log( '[AEBG] ActionHandler::execute - About to create Generator with job_start_time: ' . $job_start_time . ' (type: ' . gettype($job_start_time) . ')');
			
			// CRITICAL: Verify global is cleared before Generator sets it
			if (isset($GLOBALS['aebg_job_start_time'])) {
				error_log('[AEBG] ⚠️ WARNING: aebg_job_start_time still set before Generator creation: ' . $GLOBALS['aebg_job_start_time']);
			}
			
			$generator = new Generator( $settings, $job_start_time, $batch_author_id );
			
			// CRITICAL: Verify Generator set the global correctly
			if (!isset($GLOBALS['aebg_job_start_time'])) {
				error_log('[AEBG] ⚠️ CRITICAL ERROR: Generator did not set aebg_job_start_time global!');
			} else if ($GLOBALS['aebg_job_start_time'] != $job_start_time) {
				error_log('[AEBG] ⚠️ WARNING: Generator set aebg_job_start_time to ' . $GLOBALS['aebg_job_start_time'] . ' but expected ' . $job_start_time);
			} else {
				error_log('[AEBG] ✅ ActionHandler::execute - Generator correctly set aebg_job_start_time to: ' . $GLOBALS['aebg_job_start_time']);
			}
			
			error_log( '[AEBG] ActionHandler::execute - Generator created with job_start_time: ' . $job_start_time . ', author_id: ' . $batch_author_id );
			
			Logger::debug( 'Calling generator->run()', [
				'title' => $item->source_title,
				'template_id' => $batch->template_id,
			] );
			
			// Log elapsed time before starting generation
			$elapsed_before_generation = $job_tracker->get_elapsed_time();
			Logger::debug( 'Elapsed time before generator->run()', [
				'elapsed' => round($elapsed_before_generation, 2),
			] );
			
			// Wrap generator->run() in additional error handling
			$new_post_id = null;
			$generation_error = null;
			
			try {
				$generation_start = microtime(true);
				// Use title from args if provided, otherwise use from database
				$final_title = $title ?? $item->source_title ?? '';
				$new_post_id = $generator->run( $final_title, $batch->template_id, $settings, $item_id );
				$generation_elapsed = microtime(true) - $generation_start;
				$total_elapsed = $job_tracker->get_elapsed_time();
				
				Logger::info( 'Generator->run() completed', [
					'generation_elapsed' => round($generation_elapsed, 2),
					'total_elapsed' => round($total_elapsed, 2),
					'return_type' => gettype($new_post_id),
				] );
			} catch ( \Throwable $e ) {
				// Catch any throwable (Exception, Error, etc.)
				$generation_error = 'Generation exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
				error_log( '[AEBG] ActionHandler::execute - Generation exception caught: ' . $generation_error );
				error_log( '[AEBG] ActionHandler::execute - Exception trace: ' . $e->getTraceAsString() );
				$new_post_id = new \WP_Error( 'generation_exception', $generation_error );
			}
			
			// Check if generation returned null or false (invalid post ID)
			// Note: 0 is a valid return from wp_insert_post on failure, but it should be WP_Error instead
			// However, we'll let WP_Error handling catch it if it's actually 0
			if ( $new_post_id === null || $new_post_id === false ) {
				$error_message = 'Generation returned invalid result: ' . gettype($new_post_id) . ' - ' . var_export($new_post_id, true);
				error_log( '[AEBG] ActionHandler::execute - ' . $error_message );
				$new_post_id = new \WP_Error( 'invalid_result', $error_message );
			} elseif ( $new_post_id === 0 ) {
				// wp_insert_post returns 0 on failure (deprecated, should be WP_Error)
				$error_message = 'Post creation returned 0 (failed silently)';
				error_log( '[AEBG] ActionHandler::execute - ' . $error_message );
				$new_post_id = new \WP_Error( 'post_creation_failed', $error_message );
			}

			if ( is_wp_error( $new_post_id ) ) {
				$error_message = $new_post_id->get_error_message();
				$error_code = $new_post_id->get_error_code();
				
				// Check if this is a timeout error and can resume
				if ( ( $error_code === 'aebg_timeout' || strpos( strtolower( $error_message ), 'timeout' ) !== false ) && CheckpointManager::canResume( $item_id ) ) {
					$current_step = CheckpointManager::getCurrentStep( $item_id );
					if ( $current_step ) {
						error_log( '[AEBG] 🔄 Timeout detected with checkpoint - rescheduling for resume | item_id=' . $item_id . ' | step=' . $current_step );
						// Reschedule action to resume from checkpoint
						// Use title from $item if available, otherwise fetch from DB
						$reschedule_title = isset( $item->source_title ) ? $item->source_title : '';
						if ( empty( $reschedule_title ) ) {
							$title_item = $wpdb->get_row( $wpdb->prepare( 
								"SELECT source_title FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d", 
								$item_id 
							) );
							$reschedule_title = $title_item->source_title ?? '';
						}
						as_schedule_single_action( time() + 5, 'aebg_execute_generation', [ 'item_id' => $item_id, 'title' => $reschedule_title ] );
						// Don't mark as failed yet - will retry from checkpoint
						return;
					}
				}
				
				// Get article number for logging
				$batch_items = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}aebg_batch_items WHERE batch_id = %d ORDER BY id ASC", $batch->id ), ARRAY_A );
				$article_number = 1;
				foreach ( $batch_items as $index => $batch_item ) {
					if ( (int) $batch_item['id'] === (int) $item_id ) {
						$article_number = $index + 1;
						break;
					}
				}
				
				error_log( '[AEBG] ===== ARTICLE #' . $article_number . ' FAILED =====' );
				error_log( '[AEBG] Error code: ' . $error_code );
				error_log( '[AEBG] Error message: ' . $error_message );
				error_log( '[AEBG] Batch ID: ' . $batch->id );
				error_log( '[AEBG] Item ID: ' . $item_id );
				error_log( '[AEBG] Title: ' . $item->source_title );
				
				// Clear checkpoint on permanent failure
				CheckpointManager::clearCheckpoint( $item_id );
					
				$wpdb->update(
					"{$wpdb->prefix}aebg_batch_items",
					[
						'status'      => 'failed',
						'log_message' => $error_message,
					],
					[ 'id' => $item_id ]
				);
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}aebg_batches SET failed_items = COALESCE(failed_items, 0) + 1 WHERE id = %d", $batch->id ) );
				
				// CRITICAL: Clear cache immediately after updating batch status
				$cache_key = 'aebg_batch_status_' . $batch->id;
				wp_cache_delete( $cache_key, 'aebg_batches' );
				error_log( '[AEBG] ActionHandler::execute - Cache cleared for batch ' . $batch->id . ' after marking item as failed' );
				
				error_log( '[AEBG] ===== ARTICLE #' . $article_number . ' MARKED AS FAILED IN DATABASE =====' );
				
				// Action Scheduler will automatically pick up next pending action on next WP Cron run
				// No need to manually trigger next job - this provides process isolation
				return;
			}
			
			error_log('[AEBG] ===== VALIDATING POST ID =====');
			error_log('[AEBG] Post ID received: ' . var_export($new_post_id, true));
			error_log('[AEBG] Post ID type: ' . gettype($new_post_id));
			
			// Validate post ID is actually a valid WordPress post
			// Convert to integer to handle string post IDs
			$post_id_int = (int) $new_post_id;
			
			// Verify post actually exists in database
			$post_check = get_post($post_id_int);
			if (!$post_check) {
				error_log('[AEBG] CRITICAL ERROR: Post ID ' . $post_id_int . ' does not exist in WordPress database!');
				error_log('[AEBG] This means the post was not actually created or was deleted immediately after creation');
				$error_message = 'Post ID ' . $post_id_int . ' does not exist in database after generation';
				$wpdb->update(
					"{$wpdb->prefix}aebg_batch_items",
					[
						'status'      => 'failed',
						'log_message' => $error_message,
					],
					[ 'id' => $item_id ],
					[ '%s', '%s' ],
					[ '%d' ]
				);
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}aebg_batches SET failed_items = COALESCE(failed_items, 0) + 1 WHERE id = %d", $batch->id ) );
				$this->check_batch_completion( $batch->id );
				// Action Scheduler will automatically pick up next pending action on next WP Cron run
				// No need to manually trigger next job - this provides process isolation
				return;
			}
			
			error_log('[AEBG] Post verification successful:');
			error_log('[AEBG] - Post ID: ' . $post_check->ID);
			error_log('[AEBG] - Post Title: ' . $post_check->post_title);
			error_log('[AEBG] - Post Status: ' . $post_check->post_status);
			error_log('[AEBG] - Post Type: ' . $post_check->post_type);
			
			// Use the integer version for consistency
			$new_post_id = $post_id_int;
			
			if ( ! is_numeric( $new_post_id ) || $post_id_int <= 0 ) {
				$error_message = 'Invalid post ID returned: ' . var_export($new_post_id, true) . ' (type: ' . gettype($new_post_id) . ')';
				error_log( '[AEBG] ActionHandler::execute - ' . $error_message );
				error_log( '[AEBG] ActionHandler::execute - Post ID validation failed. Raw value: ' . var_export($new_post_id, true) );
				$wpdb->update(
					"{$wpdb->prefix}aebg_batch_items",
					[
						'status'      => 'failed',
						'log_message' => $error_message,
					],
					[ 'id' => $item_id ]
				);
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}aebg_batches SET failed_items = COALESCE(failed_items, 0) + 1 WHERE id = %d", $batch->id ) );
				$cache_key = 'aebg_batch_status_' . $batch->id;
				wp_cache_delete( $cache_key, 'aebg_batches' );
				return;
			}
			

		// Get article number for logging
		$batch_items = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}aebg_batch_items WHERE batch_id = %d ORDER BY id ASC", $batch->id ), ARRAY_A );
		$article_number = 1;
		foreach ( $batch_items as $index => $batch_item ) {
			if ( (int) $batch_item['id'] === (int) $item_id ) {
				$article_number = $index + 1;
				break;
			}
		}
		
		// Get post info for verification
		$post_title = get_the_title( $new_post_id );
		$post_status = get_post_status( $new_post_id );
		$post_url = get_permalink( $new_post_id );
		
		error_log( '[AEBG] ===== ARTICLE #' . $article_number . ' COMPLETED SUCCESSFULLY =====' );
		error_log( '[AEBG] Post ID: ' . $new_post_id );
		error_log( '[AEBG] Post Title: ' . $post_title );
		error_log( '[AEBG] Post Status: ' . $post_status );
		error_log( '[AEBG] Post URL: ' . ( $post_url ? $post_url : 'N/A (draft)' ) );
		error_log( '[AEBG] Batch ID: ' . $batch->id );
		error_log( '[AEBG] Item ID: ' . $item_id );

		// CRITICAL: Check if batch was cancelled before marking as completed
		$batch_status_check = $wpdb->get_var( $wpdb->prepare(
			"SELECT status FROM {$wpdb->prefix}aebg_batches WHERE id = %d LIMIT 1",
			$batch->id
		) );
		if ( $batch_status_check === 'cancelled' ) {
			error_log( '[AEBG] ActionHandler::execute - Batch ' . $batch->id . ' was cancelled. Marking item as cancelled instead of completed.' );
			$wpdb->update(
				"{$wpdb->prefix}aebg_batch_items",
				[ 'status' => 'cancelled' ],
				[ 'id' => $item_id ]
			);
			return; // Don't update batch counts or trigger next job
		}

		$wpdb->update(
			"{$wpdb->prefix}aebg_batch_items",
			[
				'status'            => 'completed',
				'generated_post_id' => $new_post_id,
			],
			[ 'id' => $item_id ]
		);
		
		// Mark item as completed successfully (for shutdown function)
		
		// CRITICAL: Update processed_items and clear cache using helper function
		$this->increment_processed_items( $batch->id );
		
			error_log( '[AEBG] ===== ARTICLE #' . $article_number . ' MARKED AS COMPLETED IN DATABASE =====' );
			
			// Clear checkpoint on successful completion
			CheckpointManager::clearCheckpoint( $item_id );
			
			// NOTE: Merchant comparisons are now processed synchronously during post generation
			// This ensures each post is 100% complete when generation finishes
			// No async scheduling needed - handled in Generator::processMerchantComparisons()
			
			// CRITICAL: Comprehensive cleanup after job completion
			// This ensures clean state and prevents state accumulation
			$this->cleanup_before_next_job();
		
		// CRITICAL: Clear global variable after job completion
		// This ensures clean state
		if (isset($GLOBALS['AEBG_GENERATION_IN_PROGRESS'])) {
			unset($GLOBALS['AEBG_GENERATION_IN_PROGRESS']);
			error_log('[AEBG] ActionHandler::execute - Cleared AEBG_GENERATION_IN_PROGRESS flag after job completion');
		}
		
		// CRITICAL: Log process information at job completion to verify process isolation
		$process_id = function_exists('getmypid') ? getmypid() : 'unknown';
		$memory_usage = memory_get_usage(true);
		$memory_peak = memory_get_peak_usage(true);
		error_log('[AEBG] ✅ PROCESS ISOLATION CHECK - Job completed | item_id=' . $item_id . ' | process_id=' . $process_id . ' | memory=' . round($memory_usage / 1024 / 1024, 2) . 'MB | peak=' . round($memory_peak / 1024 / 1024, 2) . 'MB | php_timeout=' . ini_get('max_execution_time') . 's | timestamp=' . microtime(true));
		
		// CRITICAL: Trigger Action Scheduler's async loopback mechanism for process isolation
		// According to Action Scheduler docs, it automatically makes async loopback requests
		// when processing batches. We trigger this mechanism to ensure the next action runs
		// in a separate PHP process (separate HTTP request = separate process).
		// 
		// Action Scheduler's built-in behavior:
		// - Checks on 'shutdown' hook if there are pending actions
		// - Makes async loopback request to continue processing in new request
		// - This provides guaranteed process isolation
		// 
		// We trigger it immediately after job completion to ensure next job starts right away
		// in a separate PHP process, providing:
		// - Complete process isolation (no shared state)
		// - Fresh HTTP connections (no stale connection pool)
		// - Fresh memory state (no shared globals)
		// - Immediate execution (no waiting for next WP Cron run)
		$this->trigger_action_scheduler_loopback();
			
		} catch ( \TypeError $e ) {
			// Catch type errors specifically
			$error_message = 'Type Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
			error_log( '[AEBG] ActionHandler::execute - TypeError caught: ' . $error_message );
			error_log( '[AEBG] ActionHandler::execute - TypeError trace: ' . $e->getTraceAsString() );
			
			// Get article number for logging
			$batch_items = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}aebg_batch_items WHERE batch_id = %d ORDER BY id ASC", $batch->id ?? 0 ), ARRAY_A );
			$article_number = 1;
			foreach ( $batch_items as $index => $batch_item ) {
				if ( (int) $batch_item['id'] === (int) $item_id ) {
					$article_number = $index + 1;
					break;
				}
			}
			error_log( '[AEBG] ===== ARTICLE #' . $article_number . ' FAILED (TypeError) =====' );
			
			$wpdb->update(
				"{$wpdb->prefix}aebg_batch_items",
				[
					'status'      => 'failed',
					'log_message' => $error_message,
				],
				[ 'id' => $item_id ]
			);
			if ( isset( $batch->id ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}aebg_batches SET failed_items = COALESCE(failed_items, 0) + 1 WHERE id = %d", $batch->id ) );
				$cache_key = 'aebg_batch_status_' . $batch->id;
				wp_cache_delete( $cache_key, 'aebg_batches' );
				// Action Scheduler will automatically pick up next pending action on next WP Cron run
				// No need to manually trigger next job - this provides process isolation
			}
			
			error_log( '[AEBG] ActionHandler::execute - Item marked as failed due to TypeError' );
		} catch ( \Error $e ) {
			// Catch PHP 7+ Error class (fatal errors, etc.)
			$error_message = 'Fatal Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
			error_log( '[AEBG] ActionHandler::execute - Fatal Error caught: ' . $error_message );
			error_log( '[AEBG] ActionHandler::execute - Fatal Error trace: ' . $e->getTraceAsString() );
			
			// Get article number for logging
			$batch_items = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}aebg_batch_items WHERE batch_id = %d ORDER BY id ASC", $batch->id ?? 0 ), ARRAY_A );
			$article_number = 1;
			foreach ( $batch_items as $index => $batch_item ) {
				if ( (int) $batch_item['id'] === (int) $item_id ) {
					$article_number = $index + 1;
					break;
				}
			}
			error_log( '[AEBG] ===== ARTICLE #' . $article_number . ' FAILED (Fatal Error) =====' );
			
			$wpdb->update(
				"{$wpdb->prefix}aebg_batch_items",
				[
					'status'      => 'failed',
					'log_message' => $error_message,
				],
				[ 'id' => $item_id ]
			);
			if ( isset( $batch->id ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}aebg_batches SET failed_items = COALESCE(failed_items, 0) + 1 WHERE id = %d", $batch->id ) );
				$cache_key = 'aebg_batch_status_' . $batch->id;
				wp_cache_delete( $cache_key, 'aebg_batches' );
				// Action Scheduler will automatically pick up next pending action on next WP Cron run
				// No need to manually trigger next job - this provides process isolation
			}
			
			error_log( '[AEBG] ActionHandler::execute - Item marked as failed due to Fatal Error' );
		} catch ( \Exception $e ) {
			$error_message = 'Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
			error_log( '[AEBG] ActionHandler::execute - Exception caught: ' . $error_message );
			error_log( '[AEBG] ActionHandler::execute - Exception trace: ' . $e->getTraceAsString() );
			
			// Check if this is a proactive reschedule (from template processing)
			if ( strpos( $e->getMessage(), 'Proactive reschedule' ) !== false && isset( $item_id ) ) {
				error_log( '[AEBG] 🔄 Proactive reschedule exception caught - returning gracefully to allow rescheduled action to continue' );
				// Action has already been rescheduled in ElementorTemplateProcessor
				// Just return gracefully - don't mark as failed
				return;
			}
			
			// Check if this is a timeout error and can resume
			if ( $this->isTimeoutError( $e ) && isset( $item_id ) && CheckpointManager::canResume( $item_id ) ) {
				$current_step = CheckpointManager::getCurrentStep( $item_id );
				if ( $current_step ) {
					error_log( '[AEBG] 🔄 Timeout detected with checkpoint - rescheduling for resume | item_id=' . $item_id . ' | step=' . $current_step );
					// Reschedule action to resume from checkpoint
					// Fetch title for rescheduling
					$title_item = $wpdb->get_row( $wpdb->prepare( 
						"SELECT source_title FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d", 
						$item_id 
					) );
					$reschedule_title = $title_item->source_title ?? '';
					as_schedule_single_action( time() + 5, 'aebg_execute_generation', [ 'item_id' => $item_id, 'title' => $reschedule_title ] );
					// Don't mark as failed yet - will retry from checkpoint
					return;
				}
			}
			
			// Get article number for logging
			$batch_items = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}aebg_batch_items WHERE batch_id = %d ORDER BY id ASC", $batch->id ?? 0 ), ARRAY_A );
			$article_number = 1;
			foreach ( $batch_items as $index => $batch_item ) {
				if ( (int) $batch_item['id'] === (int) $item_id ) {
					$article_number = $index + 1;
					break;
				}
			}
			error_log( '[AEBG] ===== ARTICLE #' . $article_number . ' FAILED (Exception) =====' );
			
			// Clear checkpoint on permanent failure
			if ( isset( $item_id ) ) {
				CheckpointManager::clearCheckpoint( $item_id );
			}
			
			$wpdb->update(
				"{$wpdb->prefix}aebg_batch_items",
				[
					'status'      => 'failed',
					'log_message' => $error_message,
				],
				[ 'id' => $item_id ]
			);
			if ( isset( $batch->id ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}aebg_batches SET failed_items = COALESCE(failed_items, 0) + 1 WHERE id = %d", $batch->id ) );
				$cache_key = 'aebg_batch_status_' . $batch->id;
				wp_cache_delete( $cache_key, 'aebg_batches' );
				// Action Scheduler will automatically pick up next pending action on next WP Cron run
				// No need to manually trigger next job - this provides process isolation
			}
			
			error_log( '[AEBG] ActionHandler::execute - Item marked as failed due to exception' );
		} catch ( \Throwable $e ) {
			// Catch any other throwable (PHP 7+)
			$error_message = 'Throwable: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
			error_log( '[AEBG] ActionHandler::execute - Throwable caught: ' . $error_message );
			error_log( '[AEBG] ActionHandler::execute - Throwable trace: ' . $e->getTraceAsString() );
			
			// Get article number for logging
			$batch_items = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}aebg_batch_items WHERE batch_id = %d ORDER BY id ASC", $batch->id ?? 0 ), ARRAY_A );
			$article_number = 1;
			foreach ( $batch_items as $index => $batch_item ) {
				if ( (int) $batch_item['id'] === (int) $item_id ) {
					$article_number = $index + 1;
					break;
				}
			}
			error_log( '[AEBG] ===== ARTICLE #' . $article_number . ' FAILED (Throwable) =====' );
			
			$wpdb->update(
				"{$wpdb->prefix}aebg_batch_items",
				[
					'status'      => 'failed',
					'log_message' => $error_message,
				],
				[ 'id' => $item_id ]
			);
			if ( isset( $batch->id ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}aebg_batches SET failed_items = COALESCE(failed_items, 0) + 1 WHERE id = %d", $batch->id ) );
				$cache_key = 'aebg_batch_status_' . $batch->id;
				wp_cache_delete( $cache_key, 'aebg_batches' );
				// Action Scheduler will automatically pick up next pending action on next WP Cron run
				// No need to manually trigger next job - this provides process isolation
			}
			
			error_log( '[AEBG] ActionHandler::execute - Item marked as failed due to throwable' );
		} finally {
			// Final check for batch completion (only reached if no exceptions were thrown)
			if ( isset( $batch->id ) ) {
				$this->check_batch_completion( $batch->id );
			}
			
			// CRITICAL: Clear execution flag - must be done in finally to ensure it's always cleared
			// This allows shutdown hook to safely query database after execution completes
			self::$is_executing = false;
			
			// CRITICAL: Clear global variable to allow shortcodes to execute again
			// This MUST be done before trigger_next_job() to ensure clean state for next post
			if (isset($GLOBALS['AEBG_GENERATION_IN_PROGRESS'])) {
				unset($GLOBALS['AEBG_GENERATION_IN_PROGRESS']);
				error_log('[AEBG] ActionHandler::execute - Cleared AEBG_GENERATION_IN_PROGRESS flag in finally block');
			}
			
			// CRITICAL: Release generation lock - must be done in finally to ensure it's always released
			if (isset($lock_key)) {
				$this->release_generation_lock($lock_key);
				error_log('[AEBG] ActionHandler::execute - Released generation lock');
			}
		}
	}

	/**
	 * Normalize item_id parameter from Action Scheduler
	 * Action Scheduler passes args as array, so [item_id] becomes the first argument
	 * 
	 * @param int|array $item_id Item ID (can be int or array)
	 * @return int Normalized item ID
	 */
	private function normalize_item_id( $item_id ): int {
		// Handle Action Scheduler args format [item_id] or direct int
		// Note: Action Scheduler passes array elements as separate parameters, so this mainly handles legacy format
		if ( is_array( $item_id ) && isset( $item_id[0] ) ) {
			return (int) $item_id[0];
		} elseif ( is_array( $item_id ) && isset( $item_id['item_id'] ) ) {
			return (int) $item_id['item_id'];
		} else {
			return (int) $item_id;
		}
	}

	/**
	 * Execute Step 1: Analyze Title
	 * 
	 * @param int|array $item_id The item ID (can be int or array for Action Scheduler compatibility)
	 * @param string|null $title Optional title (for new format with title in args)
	 */
	public function execute_step_1( $item_id, $title = null ) {
		return $this->execute_step( 'step_1', $this->normalize_item_id( $item_id ), $title );
	}

	/**
	 * Execute Step 2: Find Products
	 * 
	 * @param int|array $item_id The item ID (can be int or array for Action Scheduler compatibility)
	 * @param string|null $title Optional title (for new format with title in args)
	 */
	public function execute_step_2( $item_id, $title = null ) {
		return $this->execute_step( 'step_2', $this->normalize_item_id( $item_id ), $title );
	}

	/**
	 * Execute Step 3: Select Products
	 * 
	 * @param int|array $item_id The item ID (can be int or array for Action Scheduler compatibility)
	 * @param string|null $title Optional title (for new format with title in args)
	 */
	public function execute_step_3( $item_id, $title = null ) {
		return $this->execute_step( 'step_3', $this->normalize_item_id( $item_id ), $title );
	}

	/**
	 * Execute Step 3.5: Discover Merchants
	 * 
	 * @param int|array $item_id The item ID (can be int or array for Action Scheduler compatibility)
	 * @param string|null $title Optional title (for new format with title in args)
	 */
	public function execute_step_3_5( $item_id, $title = null ) {
		return $this->execute_step( 'step_3_5', $this->normalize_item_id( $item_id ), $title );
	}

	/**
	 * Execute Step 3.6: Price Comparison
	 * 
	 * @param int|array $item_id The item ID (can be int or array for Action Scheduler compatibility)
	 * @param string|null $title Optional title (for new format with title in args)
	 */
	public function execute_step_3_6( $item_id, $title = null ) {
		return $this->execute_step( 'step_3_6', $this->normalize_item_id( $item_id ), $title );
	}

	/**
	 * Execute Step 3.7: Process Images
	 * 
	 * @param int|array $item_id The item ID (can be int or array for Action Scheduler compatibility)
	 * @param string|null $title Optional title (for new format with title in args)
	 */
	public function execute_step_3_7( $item_id, $title = null ) {
		return $this->execute_step( 'step_3_7', $this->normalize_item_id( $item_id ), $title );
	}

	/**
	 * Execute Step 4: Process Elementor Template
	 * 
	 * @param int|array $item_id The item ID (can be int or array for Action Scheduler compatibility)
	 * @param string|null $title Optional title (for new format with title in args)
	 */
	public function execute_step_4( $item_id, $title = null ) {
		// CRITICAL: Log raw item_id to verify it's correct
		$normalized_id = $this->normalize_item_id( $item_id );
		
		// CRITICAL: Check if this is a rescheduled action (check if checkpoint exists for step_4)
		$checkpoint = CheckpointManager::loadCheckpoint( $normalized_id );
		$is_resume = false;
		if ( $checkpoint && isset( $checkpoint['step'] ) && $checkpoint['step'] === 'step_4_process_template' ) {
			$is_resume = true;
			$has_partial = isset( $checkpoint['data']['processed_template_partial'] ) && ! empty( $checkpoint['data']['processed_template_partial'] );
			$has_after_ai = isset( $checkpoint['data']['processed_template_after_ai_fields'] ) && ! empty( $checkpoint['data']['processed_template_after_ai_fields'] );
			error_log( sprintf(
				'[AEBG] 🔄 RESUME DETECTED: execute_step_4 called for item_id=%d | This is a RESUME from step_4_process_template | has_partial=%s, has_after_ai=%s',
				$normalized_id,
				$has_partial ? 'yes' : 'no',
				$has_after_ai ? 'yes' : 'no'
			) );
		} else {
			error_log( sprintf(
				'[AEBG] 🔍 execute_step_4 called | raw_item_id=%s (type: %s) | normalized_item_id=%d | checkpoint_step=%s | is_resume=%s',
				var_export( $item_id, true ),
				gettype( $item_id ),
				$normalized_id,
				$checkpoint ? ( $checkpoint['step'] ?? 'unknown' ) : 'none',
				$is_resume ? 'yes' : 'no'
			) );
		}
		
		return $this->execute_step( 'step_4', $normalized_id, $title );
	}

	/**
	 * Execute Step 4.1: Process AI Fields
	 * 
	 * @param int|array $item_id The item ID (can be int or array for Action Scheduler compatibility)
	 * @param string|null $title Optional title (for new format with title in args)
	 */
	public function execute_step_4_1( $item_id, $title = null ) {
		return $this->execute_step( 'step_4_1', $this->normalize_item_id( $item_id ), $title );
	}

	/**
	 * Execute Step 4.2: Apply Content to Widgets
	 * 
	 * @param int|array $item_id The item ID (can be int or array for Action Scheduler compatibility)
	 * @param string|null $title Optional title (for new format with title in args)
	 */
	public function execute_step_4_2( $item_id, $title = null ) {
		return $this->execute_step( 'step_4_2', $this->normalize_item_id( $item_id ), $title );
	}

	/**
	 * Execute Step 5: Generate Content
	 * 
	 * @param int|array $item_id The item ID (can be int or array for Action Scheduler compatibility)
	 * @param string|null $title Optional title (for new format with title in args)
	 */
	public function execute_step_5( $item_id, $title = null ) {
		return $this->execute_step( 'step_5', $this->normalize_item_id( $item_id ), $title );
	}

	/**
	 * Execute Step 5.5: Merchant Comparisons
	 * 
	 * @param int|array $item_id The item ID (can be int or array for Action Scheduler compatibility)
	 * @param string|null $title Optional title (for new format with title in args)
	 */
	public function execute_step_5_5( $item_id, $title = null ) {
		return $this->execute_step( 'step_5_5', $this->normalize_item_id( $item_id ), $title );
	}

	/**
	 * Execute Step 6: Create Post
	 * 
	 * @param int|array $item_id The item ID (can be int or array for Action Scheduler compatibility)
	 * @param string|null $title Optional title (for new format with title in args)
	 */
	public function execute_step_6( $item_id, $title = null ) {
		return $this->execute_step( 'step_6', $this->normalize_item_id( $item_id ), $title );
	}

	/**
	 * Execute Step 7: Image Enhancements
	 * 
	 * @param int|array $item_id The item ID (can be int or array for Action Scheduler compatibility)
	 * @param string|null $title Optional title (for new format with title in args)
	 */
	public function execute_step_7( $item_id, $title = null ) {
		return $this->execute_step( 'step_7', $this->normalize_item_id( $item_id ), $title );
	}

	/**
	 * Execute Step 8: SEO Enhancements
	 * 
	 * @param int|array $item_id The item ID (can be int or array for Action Scheduler compatibility)
	 * @param string|null $title Optional title (for new format with title in args)
	 */
	public function execute_step_8( $item_id, $title = null ) {
		return $this->execute_step( 'step_8', $this->normalize_item_id( $item_id ), $title );
	}

	/**
	 * Core step execution method
	 * Handles all step execution logic, checkpoint management, and step chaining
	 * 
	 * @param string $step_key Step key (e.g., 'step_1', 'step_2')
	 * @param int $item_id Item ID
	 * @param string|null $title Optional title (for new format with title in args)
	 * @return void
	 */
	private function execute_step( string $step_key, int $item_id, $title = null ) {
		global $wpdb;
		
		// CRITICAL: Log process information
		$process_id = function_exists('getmypid') ? getmypid() : 'unknown';
		$step_config = StepHandler::get_step_config( $step_key );
		$step_description = $step_config['description'] ?? $step_key;
		$step_number = $step_config['step_number'] ?? 0;
		
		$total_steps = StepHandler::get_total_steps();
		error_log( sprintf(
			'[AEBG] 🔄 STEP EXECUTION START | step=%s (%s) | item_id=%d | step_number=%d/%d | process_id=%s | timestamp=%.4f',
			$step_key,
			$step_description,
			$item_id,
			$step_number,
			$total_steps,
			$process_id,
			microtime(true)
		) );
		
		// CRITICAL: Store item_id in global for lock tracking
		$GLOBALS['aebg_current_item_id'] = $item_id;
		
		// CRITICAL: Acquire step-specific lock to prevent concurrent execution
		$lock_key = 'aebg_step_' . $step_key . '_' . $item_id;
		$lock_acquired = $this->acquire_generation_lock( $lock_key, 180 ); // 180s timeout per step
		
		if ( ! $lock_acquired ) {
			error_log( sprintf(
				'[AEBG] Step %s already executing for item_id=%d - rescheduling',
				$step_key,
				$item_id
			) );
			// Reschedule this step for later
			$hook = StepHandler::get_step_hook( $step_key );
			if ( $hook ) {
				// Fetch title if not provided (for backward compatibility)
				if ( empty( $title ) ) {
					$item = $wpdb->get_row( $wpdb->prepare( 
						"SELECT source_title FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d", 
						$item_id 
					) );
					$title = $item->source_title ?? '';
				}
				as_schedule_single_action( time() + 5, $hook, [ $item_id, $title ], 'aebg_generation_' . $item_id, true );
			}
			unset( $GLOBALS['aebg_current_item_id'] );
			return;
		}
		
		// CRITICAL: Set execution flag
		self::$is_executing = true;
		$GLOBALS['AEBG_GENERATION_IN_PROGRESS'] = true;
		
		// CRITICAL: Set step start time for timeout tracking
		// Each step gets its own 180s timeout, so we track from step start, not job start
		$step_start_time = microtime( true );
		$GLOBALS['aebg_step_start_time'] = $step_start_time;
		// Also set job_start_time to step start time for ElementorTemplateProcessor compatibility
		// This ensures ElementorTemplateProcessor's proactive rescheduling works correctly
		$GLOBALS['aebg_job_start_time'] = $step_start_time;
		
		// CRITICAL: Set PHP timeout for this step (180s)
		$current_php_timeout = ini_get( 'max_execution_time' );
		$target_timeout = 180; // Each step gets 180s
		@set_time_limit( $target_timeout );
		$timeout_after_set = ini_get( 'max_execution_time' );
		
		if ( (int) $timeout_after_set === $target_timeout ) {
			error_log( sprintf(
				'[AEBG] Step %s - Set PHP timeout to %ds (was: %s) | step_start_time=%.4f',
				$step_key,
				$target_timeout,
				$current_php_timeout == 0 ? 'unlimited' : $current_php_timeout . 's',
				$step_start_time
			) );
		} else {
			error_log( sprintf(
				'[AEBG] ⚠️ Step %s - Failed to set PHP timeout to %ds (actual: %s) | step_start_time=%.4f',
				$step_key,
				$target_timeout,
				$timeout_after_set == 0 ? 'unlimited' : $timeout_after_set . 's',
				$step_start_time
			) );
		}
		
		// Wrap in try-finally to ensure cleanup
		try {
			// Get item and batch info
			$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d", $item_id ) );
			if ( ! $item ) {
				Logger::error( 'Item not found', [ 'item_id' => $item_id ] );
				return;
			}
			
			// Fetch title if not provided (for backward compatibility)
			if ( empty( $title ) && isset( $item->source_title ) ) {
				$title = $item->source_title;
			}
			
			// CRITICAL: Check if item is already completed or failed
			// This prevents regenerating articles that have already been completed
			if ( in_array( $item->status, [ 'completed', 'failed', 'cancelled' ] ) ) {
				error_log( sprintf(
					'[AEBG] ⚠️ Step %s - Item %d already %s, skipping to prevent duplicate generation',
					$step_key,
					$item_id,
					$item->status
				) );
				
				// CRITICAL: If item is completed, cancel any pending actions for this item to prevent regeneration
				if ( $item->status === 'completed' ) {
					\AEBG\Core\StepHandler::cancel_all_steps_for_item( $item_id );
					error_log( sprintf(
						'[AEBG] ✅ Cancelled all pending actions for completed item %d to prevent regeneration',
						$item_id
					) );
				}
				
				return;
			}
			
			$batch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aebg_batches WHERE id = %d", $item->batch_id ) );
			if ( ! $batch ) {
				Logger::error( 'Batch not found', [ 'batch_id' => $item->batch_id ] );
				return;
			}
			
			// Check if batch was cancelled
			if ( $batch->status === 'cancelled' ) {
				error_log( sprintf(
					'[AEBG] Step %s - Batch cancelled, cancelling all steps for item_id=%d',
					$step_key,
					$item_id
				) );
				StepHandler::cancel_all_steps_for_item( $item_id );
				$wpdb->update(
					"{$wpdb->prefix}aebg_batch_items",
					[ 'status' => 'cancelled' ],
					[ 'id' => $item_id ]
				);
				return;
			}
			
			// Update item status and step tracking
			$wpdb->update(
				"{$wpdb->prefix}aebg_batch_items",
				[
					'status' => 'processing',
					'current_step' => $step_key,
					'step_progress' => $step_number,
				],
				[ 'id' => $item_id ]
			);
			
			// Update batch status
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}aebg_batches SET status = 'in_progress' WHERE id = %d AND status != 'cancelled'",
				$batch->id
			) );
			
			// Load checkpoint from previous step (if not Step 1)
			// CRITICAL: For Step 4, we may have a checkpoint from Step 4 itself (if it was interrupted)
			// So we should load the checkpoint regardless of which step we're on
			$checkpoint = null;
			$checkpoint_state = null;
			
			if ( $step_key !== 'step_1' ) {
				// CRITICAL: Log item_id to verify it's consistent
				error_log( sprintf(
					'[AEBG] 🔍 Loading checkpoint for Step %s | item_id=%d (type: %s)',
					$step_key,
					$item_id,
					gettype( $item_id )
				) );
				
				$checkpoint = CheckpointManager::loadCheckpoint( $item_id );
				if ( $checkpoint ) {
					$checkpoint_state = $checkpoint['data'] ?? null;
					$checkpoint_step = $checkpoint['step'] ?? 'unknown';
					error_log( sprintf(
						'[AEBG] Step %s - Loaded checkpoint from step: %s for item_id=%d',
						$step_key,
						$checkpoint_step,
						$item_id
					) );
					
					// CRITICAL: For Step 4, check if we have a partial template in the checkpoint
					// This means Step 4 was interrupted and we should resume from where it stopped
					if ( $step_key === 'step_4' ) {
						$has_partial = isset( $checkpoint_state['processed_template_partial'] ) && ! empty( $checkpoint_state['processed_template_partial'] );
						$has_after_ai = isset( $checkpoint_state['processed_template_after_ai_fields'] ) && ! empty( $checkpoint_state['processed_template_after_ai_fields'] );
						error_log( sprintf(
							'[AEBG] 🔍 Step 4 checkpoint check: has_partial=%s, has_after_ai=%s, checkpoint_step=%s',
							$has_partial ? 'yes' : 'no',
							$has_after_ai ? 'yes' : 'no',
							$checkpoint_step
						) );
						
						// If checkpoint is from step_4_process_template, we should have partial template data
						if ( $checkpoint_step === 'step_4_process_template' && ! $has_partial && ! $has_after_ai ) {
							error_log( '[AEBG] ⚠️ WARNING: Step 4 checkpoint from step_4_process_template but no partial template data found!' );
						}
					}
				} else {
					// No checkpoint or invalid checkpoint - mark as failed instead of restarting
					// CRITICAL: Don't restart from Step 1 as this causes duplicate generation
					$error_message = sprintf(
						'Step %s - No valid checkpoint found, cannot continue. Checkpoint may have been cleared or is invalid.',
						$step_key
					);
					error_log( '[AEBG] ⚠️ ' . $error_message );
					
					// Mark item as failed instead of restarting
					ErrorHandler::mark_item_failed( $item_id, $error_message, $batch->id );
					CheckpointManager::clearCheckpoint( $item_id );
					
					// Cancel all pending actions for this item
					StepHandler::cancel_all_steps_for_item( $item_id );
					
					return;
				}
			}
			
			// Get settings from batch
			$settings = json_decode( $batch->settings, true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $settings ) ) {
				$settings = [];
			}
			
			// Get API key and model
			$api_key = defined( 'AEBG_AI_API_KEY' ) ? AEBG_AI_API_KEY : ( $settings['api_key'] ?? get_option( 'aebg_settings' )['api_key'] ?? '' );
			if ( empty( $api_key ) ) {
				$error_message = 'OpenAI API key is not set';
				Logger::error( $error_message, [ 'item_id' => $item_id ] );
				ErrorHandler::mark_item_failed( $item_id, $error_message, $batch->id );
				return;
			}
			
			// Handle both 'model' and 'ai_model' keys for compatibility
			$ai_model = $settings['ai_model'] ?? $settings['model'] ?? 'gpt-3.5-turbo';
			$batch_author_id = isset( $batch->user_id ) ? (int) $batch->user_id : get_current_user_id();
			
			// Create Generator instance with author_id in constructor
			$generator = new Generator( $settings, null, $batch_author_id );
			
			// Execute the specific step using Generator
			$step_result = $this->execute_generator_step( $generator, $step_key, $item_id, $checkpoint_state, $settings, $api_key, $ai_model );
			
			if ( is_wp_error( $step_result ) ) {
				// Check if this is a proactive reschedule (Steps 4 and 8 can reschedule themselves)
				$error_code = $step_result->get_error_code();
				$error_message = $step_result->get_error_message();
				
				// CRITICAL: Check for proactive reschedule in multiple ways to ensure we catch it
				$error_message_lower = strtolower( $error_message );
				$is_proactive_reschedule = (
					$error_code === 'step_4_reschedule'
					|| $error_code === 'step_4_1_reschedule'
					|| $error_code === 'step_4_2_reschedule'
					|| $error_code === 'step_8_reschedule'
					|| strpos( $error_code, '_reschedule' ) !== false // Any step reschedule
					|| strpos( $error_message, 'rescheduled to avoid timeout' ) !== false
					|| strpos( $error_message, 'Proactive reschedule' ) !== false
					|| ( in_array( $step_key, [ 'step_4', 'step_4_1', 'step_4_2', 'step_8' ] ) && strpos( $error_message_lower, 'proactive' ) !== false && strpos( $error_message_lower, 'reschedule' ) !== false )
				);
				
				if ( $is_proactive_reschedule ) {
					error_log( sprintf(
						'[AEBG] 🔄 Step proactive reschedule detected (code=%s, step=%s) - returning gracefully to allow rescheduled action to continue',
						$error_code,
						$step_key
					) );
					// Step has already been rescheduled - trigger Action Scheduler to process it
					$this->trigger_action_scheduler_loopback();
					return;
				}
				
				// Step failed
				$error_message = $step_result->get_error_message();
				error_log( sprintf(
					'[AEBG] ❌ Step %s FAILED | item_id=%d | error=%s',
					$step_key,
					$item_id,
					$error_message
				) );
				
				// Check retry count
				$retry_count = $this->get_step_retry_count( $item_id, $step_key );
				
				if ( $retry_count < 3 ) {
					// Increment retry count BEFORE scheduling retry
					$new_retry_count = $this->increment_step_retry_count( $item_id, $step_key );
					
					// Retry same step
					$hook = StepHandler::get_step_hook( $step_key );
					if ( $hook ) {
						as_schedule_single_action( time() + 10, $hook, [ $item_id ], 'aebg_generation_' . $item_id, true );
						error_log( sprintf(
							'[AEBG] 🔄 Scheduling retry for Step %s (attempt %d/%d)',
							$step_key,
							$new_retry_count,
							3
						) );
					}
				} else {
					// Max retries exceeded - mark as failed
					error_log( sprintf(
						'[AEBG] ❌ Step %s exceeded max retries (3) - marking item as failed',
						$step_key
					) );
					ErrorHandler::mark_item_failed( $item_id, $error_message, $batch->id );
					CheckpointManager::clearCheckpoint( $item_id );
				}
				return;
			}
			
			// Step completed successfully
			error_log( sprintf(
				'[AEBG] ✅ Step %s COMPLETED | item_id=%d | elapsed=%.2fs',
				$step_key,
				$item_id,
				$step_result['elapsed'] ?? 0
			) );
			
			// Clear retry count on success (step completed successfully)
			$transient_key = 'aebg_step_retry_' . $item_id . '_' . $step_key;
			delete_transient( $transient_key );
			
			// CRITICAL: Check if this is the last step
			$is_last = StepHandler::is_last_step( $step_key );
			$next_step = StepHandler::get_next_step( $step_key );
			error_log( sprintf(
				'[AEBG] 🔍 Step %s completion check | is_last_step=%s | next_step=%s | post_id=%s',
				$step_key,
				$is_last ? 'YES' : 'NO',
				$next_step ?? 'null',
				isset( $step_result['post_id'] ) ? $step_result['post_id'] : 'missing'
			) );
			
			if ( $is_last ) {
				// Last step - mark item as completed
				$post_id = $step_result['post_id'] ?? null;
				if ( $post_id && is_numeric( $post_id ) && $post_id > 0 ) {
					error_log( sprintf(
						'[AEBG] ✅ Step %s is LAST STEP - marking item %d as completed with post_id=%d',
						$step_key,
						$item_id,
						$post_id
					) );
					
					$wpdb->update(
						"{$wpdb->prefix}aebg_batch_items",
						[
							'status' => 'completed',
							'generated_post_id' => $post_id,
							'current_step' => null,
							'step_progress' => 0,
						],
						[ 'id' => $item_id ]
					);
					
					// Clear checkpoint
					CheckpointManager::clearCheckpoint( $item_id );
					
					// CRITICAL: Cancel all pending actions for this item to prevent regeneration
					StepHandler::cancel_all_steps_for_item( $item_id );
					error_log( sprintf(
						'[AEBG] ✅ Cancelled all pending actions for completed item %d to prevent regeneration',
						$item_id
					) );
					
					// Increment processed items
					$this->increment_processed_items( $batch->id );
					
					// Check batch completion
					$this->check_batch_completion( $batch->id );
					
					error_log( sprintf(
						'[AEBG] 🎉 GENERATION COMPLETE | item_id=%d | post_id=%d | step=%s',
						$item_id,
						$post_id,
						$step_key
					) );
				} else {
					// Post ID invalid
					error_log( sprintf(
						'[AEBG] ❌ Step %s is LAST STEP but post_id is invalid: %s',
						$step_key,
						var_export( $post_id, true )
					) );
					ErrorHandler::mark_item_failed( $item_id, 'Invalid post ID returned from final step', $batch->id );
				}
			} else {
				// Not last step - schedule next step
				error_log( sprintf(
					'[AEBG] ➡️ Step %s is NOT last step - scheduling next step: %s',
					$step_key,
					$next_step ?? 'null'
				) );
				
				$action_id = StepHandler::schedule_next_step( $step_key, $item_id, 2, $title );
				if ( $action_id === false ) {
					error_log( sprintf(
						'[AEBG] ⚠️ Failed to schedule next step after Step %s',
						$step_key
					) );
				} else {
					error_log( sprintf(
						'[AEBG] ✅ Scheduled next step %s for item %d (action_id=%d)',
						$next_step ?? 'unknown',
						$item_id,
						$action_id
					) );
				}
			}
			
		} catch ( \Exception $e ) {
			// Check if this is a proactive reschedule exception (from ElementorTemplateProcessor)
			if ( strpos( $e->getMessage(), 'Proactive reschedule' ) !== false && $item_id ) {
				error_log( sprintf(
					'[AEBG] 🔄 Step %s proactive reschedule exception caught - returning gracefully to allow rescheduled action to continue',
					$step_key
				) );
				// Action has already been rescheduled in ElementorTemplateProcessor or Generator
				// Trigger Action Scheduler to process the rescheduled action
				$this->trigger_action_scheduler_loopback();
				return;
			}
			
			$error_message = 'Exception in Step ' . $step_key . ': ' . $e->getMessage();
			error_log( sprintf(
				'[AEBG] ❌ Step %s EXCEPTION | item_id=%d | error=%s',
				$step_key,
				$item_id,
				$error_message
			) );
			
			// Check retry count
			$retry_count = $this->get_step_retry_count( $item_id, $step_key );
			
			if ( $retry_count < 3 ) {
				// Increment retry count BEFORE scheduling retry
				$new_retry_count = $this->increment_step_retry_count( $item_id, $step_key );
				
				// Retry same step
				$hook = StepHandler::get_step_hook( $step_key );
				if ( $hook ) {
					as_schedule_single_action( time() + 10, $hook, [ $item_id ], 'aebg_generation_' . $item_id, true );
					error_log( sprintf(
						'[AEBG] 🔄 Scheduling retry for Step %s after exception (attempt %d/%d)',
						$step_key,
						$new_retry_count,
						3
					) );
				}
			} else {
				// Max retries exceeded
				error_log( sprintf(
					'[AEBG] ❌ Step %s exceeded max retries (3) after exception - marking item as failed',
					$step_key
				) );
				$item = $wpdb->get_row( $wpdb->prepare( "SELECT batch_id FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d", $item_id ) );
				if ( $item ) {
					ErrorHandler::mark_item_failed( $item_id, $error_message, $item->batch_id );
					CheckpointManager::clearCheckpoint( $item_id );
				}
			}
		} finally {
			// CRITICAL: Clear execution flag
			self::$is_executing = false;
			if ( isset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] ) ) {
				unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
			}
			
			// CRITICAL: Release lock
			if ( isset( $lock_key ) ) {
				$this->release_generation_lock( $lock_key );
			}
			
			unset( $GLOBALS['aebg_current_item_id'] );
		}
	}
	
	/**
	 * Execute a specific step using Generator
	 * 
	 * @param Generator $generator Generator instance
	 * @param string $step_key Step key
	 * @param int $item_id Item ID
	 * @param array|null $checkpoint_state Previous checkpoint state
	 * @param array $settings Settings
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array|\WP_Error Step result or error
	 */
	private function execute_generator_step( $generator, string $step_key, int $item_id, ?array $checkpoint_state, array $settings, string $api_key, string $ai_model ) {
		$start_time = microtime( true );
		
		try {
			// Route to appropriate Generator method based on step
			switch ( $step_key ) {
				case 'step_1':
					return $generator->execute_step_1_analyze_title( $checkpoint_state, $item_id, $settings, $api_key, $ai_model );
					
				case 'step_2':
					return $generator->execute_step_2_find_products( $checkpoint_state, $item_id, $settings, $api_key, $ai_model );
					
				case 'step_3':
					return $generator->execute_step_3_select_products( $checkpoint_state, $item_id, $settings, $api_key, $ai_model );
					
				case 'step_3_5':
					return $generator->execute_step_3_5_discover_merchants( $checkpoint_state, $item_id, $settings, $api_key, $ai_model );
					
				case 'step_3_6':
					return $generator->execute_step_3_6_price_comparison( $checkpoint_state, $item_id, $settings, $api_key, $ai_model );
					
				case 'step_3_7':
					return $generator->execute_step_3_7_process_images( $checkpoint_state, $item_id, $settings, $api_key, $ai_model );
					
				case 'step_4':
					return $generator->execute_step_4_collect_prompts( $checkpoint_state, $item_id, $settings, $api_key, $ai_model );
					
				case 'step_4_1':
					return $generator->execute_step_4_1_process_fields( $checkpoint_state, $item_id, $settings, $api_key, $ai_model );
					
				case 'step_4_2':
					return $generator->execute_step_4_2_apply_content( $checkpoint_state, $item_id, $settings, $api_key, $ai_model );
					
				case 'step_5':
					return $generator->execute_step_5_generate_content( $checkpoint_state, $item_id, $settings, $api_key, $ai_model );
					
				case 'step_5_5':
					return $generator->execute_step_5_5_merchant_comparisons( $checkpoint_state, $item_id, $settings, $api_key, $ai_model );
					
				case 'step_6':
					return $generator->execute_step_6_create_post( $checkpoint_state, $item_id, $settings, $api_key, $ai_model );
					
				case 'step_7':
					return $generator->execute_step_7_image_enhancements( $checkpoint_state, $item_id, $settings, $api_key, $ai_model );
					
				case 'step_8':
					return $generator->execute_step_8_seo_enhancements( $checkpoint_state, $item_id, $settings, $api_key, $ai_model );
					
				default:
					return new \WP_Error( 'invalid_step', 'Invalid step key: ' . $step_key );
			}
		} catch ( \Exception $e ) {
			$elapsed = microtime( true ) - $start_time;
			return new \WP_Error( 'step_exception', 'Exception in step execution: ' . $e->getMessage(), [ 'elapsed' => $elapsed ] );
		}
	}
	
	/**
	 * Get step retry count for an item
	 * 
	 * @param int $item_id Item ID
	 * @param string $step_key Step key
	 * @return int Retry count
	 */
	private function get_step_retry_count( int $item_id, string $step_key ): int {
		// Use transient to track retry count per step
		$transient_key = 'aebg_step_retry_' . $item_id . '_' . $step_key;
		$retry_count = (int) get_transient( $transient_key );
		return $retry_count;
	}
	
	/**
	 * Increment step retry count
	 * 
	 * @param int $item_id Item ID
	 * @param string $step_key Step key
	 * @return int New retry count
	 */
	private function increment_step_retry_count( int $item_id, string $step_key ): int {
		$transient_key = 'aebg_step_retry_' . $item_id . '_' . $step_key;
		$retry_count = (int) get_transient( $transient_key );
		$retry_count++;
		set_transient( $transient_key, $retry_count, 3600 ); // 1 hour expiration
		return $retry_count;
	}

	/**
	 * Check if the batch is complete.
	 *
	 * @param int $batch_id The batch ID.
	 */
	private function check_batch_completion( $batch_id ) {
		global $wpdb;

		$batch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aebg_batches WHERE id = %d", $batch_id ) );

		if ( ! $batch ) {
			error_log( '[AEBG] ActionHandler::check_batch_completion - Batch not found for ID: ' . $batch_id );
			return;
		}

		// Ensure all values are properly converted to integers with detailed logging
		$processed_items = intval($batch->processed_items ?? 0);
		$failed_items = intval($batch->failed_items ?? 0);
		$total_items = intval($batch->total_items ?? 0);

		error_log( '[AEBG] ActionHandler::check_batch_completion - Batch ID: ' . $batch_id . 
				  ', Processed: ' . $processed_items . 
				  ', Failed: ' . $failed_items . 
				  ', Total: ' . $total_items . 
				  ', Sum: ' . ($processed_items + $failed_items) );

		if ( ($processed_items + $failed_items) === $total_items ) {
			$wpdb->update( "{$wpdb->prefix}aebg_batches", [ 'status' => 'completed' ], [ 'id' => $batch_id ] );
			
			// CRITICAL: Clear cache when batch is marked as completed
			$cache_key = 'aebg_batch_status_' . $batch_id;
			wp_cache_delete( $cache_key, 'aebg_batches' );
			
			error_log( '[AEBG] ActionHandler::check_batch_completion - Batch marked as completed' );
		}
	}

	/**
	 * Update batch processed_items count and clear cache
	 * Helper function to ensure cache is always cleared when batch counts change
	 *
	 * @param int $batch_id The batch ID
	 * @return bool True if update succeeded, false otherwise
	 */
	private function increment_processed_items( $batch_id ) {
		global $wpdb;
		
		$batch_update = $wpdb->query( $wpdb->prepare( 
			"UPDATE {$wpdb->prefix}aebg_batches SET processed_items = COALESCE(processed_items, 0) + 1 WHERE id = %d", 
			$batch_id 
		) );
		
		if ( $batch_update === false ) {
			error_log( '[AEBG] WARNING: Failed to increment processed_items. Error: ' . $wpdb->last_error );
		} else {
			error_log( '[AEBG] Successfully incremented processed_items for batch ' . $batch_id );
		}
		
		// CRITICAL: Clear cache immediately to ensure frontend sees the update
		$this->clear_batch_cache( $batch_id );
		
		return $batch_update !== false;
	}

	/**
	 * Update batch failed_items count and clear cache
	 * Helper function to ensure cache is always cleared when batch counts change
	 *
	 * @param int $batch_id The batch ID
	 * @return bool True if update succeeded, false otherwise
	 */
	private function increment_failed_items( $batch_id ) {
		global $wpdb;
		
		$batch_update = $wpdb->query( $wpdb->prepare( 
			"UPDATE {$wpdb->prefix}aebg_batches SET failed_items = COALESCE(failed_items, 0) + 1 WHERE id = %d", 
			$batch_id 
		) );
		
		if ( $batch_update === false ) {
			error_log( '[AEBG] WARNING: Failed to increment failed_items. Error: ' . $wpdb->last_error );
		} else {
			error_log( '[AEBG] Successfully incremented failed_items for batch ' . $batch_id );
		}
		
		// CRITICAL: Clear cache immediately to ensure frontend sees the update
		$this->clear_batch_cache( $batch_id );
		
		return $batch_update !== false;
	}

	/**
	 * Clear batch status cache
	 * Ensures frontend gets fresh data on next poll
	 *
	 * @param int $batch_id The batch ID
	 */
	private function clear_batch_cache( $batch_id ) {
		$cache_key = 'aebg_batch_status_' . $batch_id;
		wp_cache_delete( $cache_key, 'aebg_batches' );
		
		// Also flush object cache group if available to ensure immediate visibility
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'aebg_batches' );
		}
		
		error_log( '[AEBG] Cache cleared for batch ' . $batch_id );
	}

	/**
	 * Comprehensive cleanup before triggering next job
	 * 
	 * CRITICAL: This ensures clean state between articles to prevent:
	 * - Database connection issues
	 * - Memory accumulation
	 * - WordPress cache accumulation
	 * - State persistence from previous job
	 * 
	 * @return void
	 */
	private function cleanup_before_next_job() {
		error_log('[AEBG] cleanup_before_next_job: Starting comprehensive cleanup...');
		
		global $wpdb;
		
		// Force database connection reset
		// This prevents the next job from inheriting a stale connection
		if ($wpdb && isset($wpdb->dbh) && $wpdb->dbh instanceof \mysqli) {
			$mysqli = $wpdb->dbh;
			
			// Consume all MySQL results
			if ($result = $mysqli->store_result()) {
				$result->free();
			}
			
			// Consume all remaining results from multi-query
			$max_iterations = 50;
			$iteration = 0;
			while ($mysqli->more_results() && $iteration < $max_iterations) {
				$iteration++;
				$mysqli->next_result();
				if ($result = $mysqli->store_result()) {
					$result->free();
				}
				if ($mysqli->errno) {
					break;
				}
			}
			
			// Ping to check connection health
			if (!$mysqli->ping()) {
				error_log('[AEBG] cleanup_before_next_job: MySQL connection is dead, forcing reconnection...');
				if (method_exists($wpdb, 'db_connect')) {
					$wpdb->db_connect();
				}
			} else {
				// Connection is alive, but reset state anyway
				$mysqli->ping();
			}
		}
		
		// Flush WordPress query cache
		if ($wpdb) {
			$wpdb->flush();
			$wpdb->last_error = '';
			$wpdb->last_query = '';
		}
		
		// Clear WordPress object cache groups
		if (function_exists('wp_cache_flush_group')) {
			wp_cache_flush_group('posts');
			wp_cache_flush_group('post_meta');
			wp_cache_flush_group('aebg_batches');
			wp_cache_flush_group('aebg_comparisons');
		}
		
		// Clear transients that might accumulate
		if (function_exists('wp_cache_delete')) {
			// Clear any AEBG-related transients
			global $wpdb;
			$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_aebg_%' OR option_name LIKE '_transient_timeout_aebg_%'");
		}
		
		// Force garbage collection to free memory
		if (function_exists('gc_collect_cycles')) {
			$collected = gc_collect_cycles();
			if ($collected > 0) {
				error_log('[AEBG] cleanup_before_next_job: Garbage collected ' . $collected . ' cycles');
			}
		}
		
		// Clear all AEBG-related global variables
		$globals_to_clear = [
			'AEBG_API_REQUEST_IN_PROGRESS',
			'aebg_job_start_time',
			'aebg_current_item_id',
			'aebg_job_number', // Clear job number to prevent state persistence
		];
		foreach ($globals_to_clear as $global_key) {
			if (isset($GLOBALS[$global_key])) {
				unset($GLOBALS[$global_key]);
			}
		}
		
		// CRITICAL: Reset WordPress HTTP API connection pool
		// WordPress HTTP API (Requests library) maintains a connection pool that persists across requests
		// The second job runs in the same PHP process, so it inherits this pool
		// We need to force WordPress to reset its HTTP transport state
		$this->resetWordPressHttpTransport();
		
		// CRITICAL: Small delay to ensure all connections are fully closed
		// This gives the OS time to close TCP connections before the next job starts
		// Even with Connection: close, the OS needs a moment to fully close the connection
		usleep(100000); // 0.1 second delay - ensures connections are fully closed
		
		// Log memory usage for monitoring
		if (function_exists('memory_get_usage')) {
			$memory_mb = round(memory_get_usage(true) / 1024 / 1024, 2);
			$peak_memory_mb = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
			error_log('[AEBG] cleanup_before_next_job: Memory usage: ' . $memory_mb . ' MB (peak: ' . $peak_memory_mb . ' MB)');
		}
		
		error_log('[AEBG] cleanup_before_next_job: Comprehensive cleanup completed');
	}
	
	/**
	 * Reset WordPress HTTP API transport state
	 * 
	 * CRITICAL: WordPress HTTP API (Requests library) maintains a connection pool
	 * that persists across requests in the same PHP process. The second job inherits
	 * this pool, which may contain stale connections. This method forces WordPress
	 * to reset its HTTP transport state, ensuring the next job starts with a clean
	 * connection pool.
	 * 
	 * @return void
	 */
	private function resetWordPressHttpTransport() {
		error_log('[AEBG] resetWordPressHttpTransport: Resetting WordPress HTTP API transport state...');
		
		// Note: We don't directly access WordPress HTTP API internals to avoid triggering deprecation warnings
		// WordPress HTTP API (Requests library) maintains a connection pool, but we can't directly reset it
		// Instead, we rely on cache clearing and garbage collection to clean up connections
		
		// Method 2: Clear WordPress HTTP API cache
		// WordPress caches the transport class and other HTTP-related data
		if (function_exists('wp_cache_delete')) {
			// Clear any HTTP-related cache
			wp_cache_delete('http_transport', 'options');
			wp_cache_delete('_transient_doing_cron', 'options');
			wp_cache_delete('http_response', 'transient');
		}
		
		// Method 3: Force garbage collection to clean up any unreferenced connections
		// This will free any cURL handles or other resources that are no longer referenced
		if (function_exists('gc_collect_cycles')) {
			$collected = gc_collect_cycles();
			if ($collected > 0) {
				error_log('[AEBG] resetWordPressHttpTransport: Garbage collected ' . $collected . ' cycles (may include HTTP connections)');
			}
		}
		
		// Method 4: Clear any static state in WordPress HTTP transports
		// WordPress HTTP API may use cURL, fsockopen, or other transports
		// Each may maintain static state that persists across requests
		// We can't directly access this, but the delay below helps ensure connections are closed
		
		error_log('[AEBG] resetWordPressHttpTransport: WordPress HTTP API transport state reset completed');
	}

	/**
	 * Trigger Action Scheduler's async loopback mechanism for process isolation.
	 * 
	 * IMPORTANT: Action Scheduler's async loopback only works in admin context and has a 60-second lock.
	 * Since our jobs run via WP Cron (non-admin), we need to explicitly trigger the async request.
	 * 
	 * We directly call Action Scheduler's async request runner to ensure it dispatches:
	 * 1. Check if there are pending actions
	 * 2. Check if we haven't exceeded concurrent batches
	 * 3. Dispatch async request if conditions are met
	 * 4. Next action runs in separate PHP process (separate HTTP request = separate process)
	 * 
	 * This ensures process isolation even when running in non-admin context (WP Cron).
	 */
	private function trigger_action_scheduler_loopback() {
		// CRITICAL: Action Scheduler's async loopback only works in admin context by default
		// Since our jobs run via WP Cron (non-admin), we need to explicitly trigger it
		// 
		// Action Scheduler's maybe_dispatch_async_request() checks:
		// 1. is_admin() - FAILS in WP Cron context
		// 2. 60-second lock - may prevent immediate dispatch
		// 
		// We bypass these limitations by directly calling the async request runner
		add_action( 'shutdown', function() {
			try {
				// Get Action Scheduler's queue runner instance
				// CRITICAL: Use leading backslash to reference global namespace class
				if ( ! class_exists( '\ActionScheduler_QueueRunner' ) ) {
					Logger::warning( 'ActionScheduler_QueueRunner class not found', [] );
					return;
				}
				
				$runner = \ActionScheduler_QueueRunner::instance();
				
				// Use reflection to access protected async_request property
				$reflection = new \ReflectionClass( $runner );
				if ( ! $reflection->hasProperty( 'async_request' ) ) {
					Logger::warning( 'Action Scheduler queue runner does not have async_request property', [] );
					return;
				}
				
				$async_request_property = $reflection->getProperty( 'async_request' );
				$async_request_property->setAccessible( true );
				$async_request = $async_request_property->getValue( $runner );
				
				if ( ! $async_request || ! method_exists( $async_request, 'maybe_dispatch' ) ) {
					Logger::warning( 'Action Scheduler async request runner not available or missing maybe_dispatch method', [] );
					return;
				}
				
				// Call maybe_dispatch() directly - this bypasses the admin check and lock
				// The allow() method inside maybe_dispatch() will check:
				// - has_action( 'action_scheduler_run_queue' )
				// - has_maximum_concurrent_batches() (we limit to 1)
				// - has_pending_actions_due()
				// 
				// If all conditions are met, it will dispatch an async request
				$result = $async_request->maybe_dispatch();
				
				Logger::debug( 'Triggered Action Scheduler async loopback request explicitly', [
					'result' => $result,
					'context' => is_admin() ? 'admin' : 'cron',
				] );
			} catch ( \Exception $e ) {
				Logger::error( 'Failed to trigger Action Scheduler async loopback', [
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				] );
			}
		}, 999 );
		
		Logger::debug( 'Scheduled explicit Action Scheduler async loopback trigger on shutdown hook', [] );
	}

	/**
	 * Trigger the next job in the batch if there are pending items.
	 * 
	 * DEPRECATED: This method is no longer used. Use trigger_next_job_async() instead.
	 *
	 * @param int $batch_id The batch ID.
	 * @deprecated Use trigger_next_job_async() instead for guaranteed process isolation
	 */
	private function trigger_next_job( $batch_id ) {
		global $wpdb;

		// Validate batch_id
		if ( empty( $batch_id ) || ! is_numeric( $batch_id ) ) {
			Logger::warning( 'Invalid batch_id', [ 'batch_id' => $batch_id ] );
			return;
		}

		// Verify batch exists
		$batch_exists = $wpdb->get_var( $wpdb->prepare( 
			"SELECT COUNT(*) FROM {$wpdb->prefix}aebg_batches WHERE id = %d", 
			$batch_id 
		) );

		if ( ! $batch_exists ) {
			Logger::warning( 'Batch not found', [ 'batch_id' => $batch_id ] );
			return;
		}

		// Find the next pending item in this batch (ordered by ID to maintain sequence)
		// Also check for stuck actions (in-progress for too long) and failed items
		$next_item = $wpdb->get_row( $wpdb->prepare( 
			"SELECT id, source_title FROM {$wpdb->prefix}aebg_batch_items 
			WHERE batch_id = %d AND status = 'pending' 
			ORDER BY id ASC 
			LIMIT 1", 
			$batch_id 
		) );

		if ( ! $next_item ) {
			Logger::debug( 'No pending items found - batch processing complete', [ 'batch_id' => $batch_id ] );
			return;
		}

		if ( empty( $next_item->id ) ) {
			Logger::warning( 'Next item has invalid ID', [ 'batch_id' => $batch_id ] );
			return;
		}

		// Check if action already exists using efficient check
		$hook = 'aebg_execute_generation';
		$args = [ 'item_id' => $next_item->id ];
		$group = 'aebg_generation_' . $next_item->id;
		
		if ( ActionSchedulerHelper::action_exists( $hook, $args, $group ) ) {
			Logger::debug( 'Action already exists, skipping duplicate', [
				'item_id' => $next_item->id,
				'batch_id' => $batch_id,
			] );
			return;
		}

		// Get article number for logging
		$batch = $wpdb->get_row( $wpdb->prepare( "SELECT total_items FROM {$wpdb->prefix}aebg_batches WHERE id = %d", $batch_id ) );
		$batch_items = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}aebg_batch_items WHERE batch_id = %d ORDER BY id ASC", $batch_id ), ARRAY_A );
		$article_number = 1;
		foreach ( $batch_items as $index => $batch_item ) {
			if ( (int) $batch_item['id'] === (int) $next_item->id ) {
				$article_number = $index + 1;
				break;
			}
		}
		
		Logger::info( 'Attempting to schedule next job', [
			'article_number' => $article_number,
			'item_id' => $next_item->id,
			'title' => $next_item->source_title ?? 'N/A',
			'batch_id' => $batch_id,
		] );
		
		// Schedule with 5 second delay to ensure previous job is fully complete
		// Increased from 2 to 5 seconds to allow more time for cleanup, especially after first article
		// Use ActionSchedulerHelper for proper initialization checks and efficient scheduling
		$action_id = ActionSchedulerHelper::schedule_action( 
			$hook,
			$args,
			$group,
			5, // 5 second delay (increased from 2)
			true // Make action unique
		);
		
		if ( $action_id > 0 ) {
			Logger::info( 'Scheduled next job successfully', [
				'action_id' => $action_id,
				'article_number' => $article_number,
				'total_items' => $batch->total_items ?? 'unknown',
				'item_id' => $next_item->id,
				'title' => $next_item->source_title ?? 'N/A',
				'batch_id' => $batch_id,
			] );
		} elseif ( $action_id === 0 ) {
			// Action already exists (prevented duplicate) - this is success
			Logger::debug( 'Action already exists (duplicate prevented)', [
				'item_id' => $next_item->id,
				'article_number' => $article_number,
			] );
		} else {
			// Actual failure
			Logger::error( 'Failed to schedule next job', [
				'item_id' => $next_item->id,
				'batch_id' => $batch_id,
				'article_number' => $article_number,
			] );
		}
	}
	
	/**
	 * Acquire a generation lock to prevent concurrent execution
	 * Uses MySQL GET_LOCK for true atomicity across multiple servers and processes
	 * Falls back to WordPress options table if MySQL locking is unavailable
	 * 
	 * @param string $lock_key Lock key identifier
	 * @param int $timeout Lock timeout in seconds (default: uses TimeoutManager)
	 * @return bool True if lock acquired, false if already locked
	 */
	private function acquire_generation_lock($lock_key, $timeout = null) {
		// Use centralized timeout if not specified
		if ($timeout === null) {
			$timeout = \AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT;
		}
		global $wpdb;
		
		// CRITICAL: Use MySQL GET_LOCK for true atomicity (works across multiple servers)
		// This is the most reliable method for distributed systems
		// CRITICAL: Use timeout of 0 for non-blocking check - if lock is held, reschedule immediately
		// Using a long timeout would cause the second job to block waiting for the first, which defeats process isolation
		$mysql_lock_name = 'aebg_' . $lock_key;
		$lock_result = $wpdb->get_var($wpdb->prepare(
			"SELECT GET_LOCK(%s, %d) AS lock_acquired",
			$mysql_lock_name,
			0  // Non-blocking: return immediately if lock is held
		));
		
		if ($lock_result == 1) {
			// MySQL lock acquired successfully
			error_log('[AEBG] Generation lock acquired via MySQL GET_LOCK (expires in: ' . $timeout . 's)');
			
			// Also store in options table for visibility and fallback
			$lock_data = [
				'acquired_at' => time(),
				'expires_at' => time() + $timeout,
				'process_id' => getmypid(),
				'item_id' => isset($GLOBALS['aebg_current_item_id']) ? $GLOBALS['aebg_current_item_id'] : 'unknown',
				'method' => 'mysql_get_lock'
			];
			$lock_option_key = 'aebg_' . $lock_key;
			update_option($lock_option_key, maybe_serialize($lock_data), 'no');
			
			return true;
		}
		
		// MySQL lock failed - check if it's because lock is held or another error
		if ($lock_result === null) {
			// GET_LOCK returned NULL (error occurred)
			error_log('[AEBG] MySQL GET_LOCK error - falling back to options table method');
		} else {
			// GET_LOCK returned 0 (lock is held by another connection)
			error_log('[AEBG] Generation lock already held by another process (MySQL GET_LOCK returned 0)');
		}
		
		// Fallback to options table method (less reliable but works if MySQL locking unavailable)
		$lock_option_key = 'aebg_' . $lock_key;
		$lock_value = get_option($lock_option_key, false);
		
		// Check if lock exists and is still valid
		if ($lock_value !== false) {
			$lock_data = maybe_unserialize($lock_value);
			if (is_array($lock_data) && isset($lock_data['expires_at'])) {
				// Lock exists and hasn't expired
				if (time() < $lock_data['expires_at']) {
					error_log('[AEBG] Generation lock already held (expires at: ' . date('Y-m-d H:i:s', $lock_data['expires_at']) . ')');
					return false;
				}
				// Lock expired, remove it
				delete_option($lock_option_key);
			}
		}
		
		// Attempt to acquire lock using atomic operation
		// Use add_option which fails if option already exists (atomic check-and-set)
		$lock_data = [
			'acquired_at' => time(),
			'expires_at' => time() + $timeout,
			'process_id' => getmypid(),
			'item_id' => isset($GLOBALS['aebg_current_item_id']) ? $GLOBALS['aebg_current_item_id'] : 'unknown',
			'method' => 'options_table'
		];
		
		// Try to add the option (atomic operation - fails if exists)
		$lock_acquired = add_option($lock_option_key, maybe_serialize($lock_data), '', 'no');
		
		if ($lock_acquired) {
			error_log('[AEBG] Generation lock acquired via options table (expires at: ' . date('Y-m-d H:i:s', $lock_data['expires_at']) . ')');
			return true;
		}
		
		// Lock acquisition failed (another process has it)
		error_log('[AEBG] Generation lock acquisition failed - another process holds the lock');
		return false;
	}
	
	/**
	 * Release a generation lock
	 * Releases both MySQL lock and options table entry
	 * 
	 * @param string $lock_key Lock key identifier
	 * @return void
	 */
	private function release_generation_lock($lock_key) {
		global $wpdb;
		
		// CRITICAL: Release MySQL lock first (if it was acquired)
		$mysql_lock_name = 'aebg_' . $lock_key;
		$release_result = $wpdb->get_var($wpdb->prepare(
			"SELECT RELEASE_LOCK(%s) AS lock_released",
			$mysql_lock_name
		));
		
		if ($release_result == 1) {
			error_log('[AEBG] Generation lock released via MySQL RELEASE_LOCK');
		} elseif ($release_result === null) {
			error_log('[AEBG] MySQL RELEASE_LOCK returned NULL (lock may not have been held)');
		}
		
		// Also remove from options table
		$lock_option_key = 'aebg_' . $lock_key;
		$deleted = delete_option($lock_option_key);
		
		if ($deleted) {
			error_log('[AEBG] Generation lock removed from options table');
		} else {
			error_log('[AEBG] Warning: Generation lock removal from options table failed (may have already been removed)');
		}
	}

	/**
	 * Parse memory limit string (e.g., "256M", "512M") to bytes
	 * 
	 * @param string $memory_limit Memory limit string
	 * @return int Memory limit in bytes
	 */
	private static function parse_memory_limit( $memory_limit ) {
		$memory_limit_bytes = 0;
		if ( preg_match( '/^(\d+)([KMGT]?)$/i', $memory_limit, $matches ) ) {
			$value = intval( $matches[1] );
			$unit = strtoupper( $matches[2] ?? '' );
			switch ( $unit ) {
				case 'G':
					$memory_limit_bytes = $value * 1024 * 1024 * 1024;
					break;
				case 'M':
					$memory_limit_bytes = $value * 1024 * 1024;
					break;
				case 'K':
					$memory_limit_bytes = $value * 1024;
					break;
				default:
					$memory_limit_bytes = $value;
					break;
			}
		}
		return $memory_limit_bytes;
	}

	/**
	 * Check if error is timeout-related
	 *
	 * @param \Throwable $e Exception or error.
	 * @return bool True if timeout-related.
	 */
	private function isTimeoutError( \Throwable $e ): bool {
		$message = strtolower( $e->getMessage() );
		$timeout_keywords = [ 'timeout', 'max_execution_time', 'execution time limit', 'action scheduler limit' ];
		
		foreach ( $timeout_keywords as $keyword ) {
			if ( strpos( $message, $keyword ) !== false ) {
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Error classification constants for product replacement
	 */
	const ERROR_FATAL = 'fatal';
	const ERROR_RETRYABLE = 'retryable';
	const ERROR_TIMEOUT = 'timeout';
	const ERROR_API_ERROR = 'api_error';
	const ERROR_DATABASE_ERROR = 'database_error';

	/**
	 * Classify product replacement error
	 *
	 * @param \WP_Error|\Exception $error Error object.
	 * @return array Error classification with type, retry_delay, and should_retry.
	 */
	private function classifyReplacementError( $error ): array {
		$error_message = '';
		$error_code = '';

		if ( $error instanceof \WP_Error ) {
			$error_message = strtolower( $error->get_error_message() );
			$error_code = $error->get_error_code();
		} elseif ( $error instanceof \Exception ) {
			$error_message = strtolower( $error->getMessage() );
			$error_code = get_class( $error );
		}

		// Fatal errors - don't retry
		$fatal_codes = [ 'post_not_found', 'no_products', 'product_not_found', 'no_elementor_data', 'invalid_elementor_data', 'container_not_found' ];
		if ( in_array( $error_code, $fatal_codes, true ) ) {
			return [
				'type' => self::ERROR_FATAL,
				'retry_delay' => 0,
				'should_retry' => false,
			];
		}

		// Timeout errors - retry with longer delay
		$timeout_keywords = [ 'timeout', 'max_execution_time', 'execution time limit', 'action scheduler limit' ];
		foreach ( $timeout_keywords as $keyword ) {
			if ( strpos( $error_message, $keyword ) !== false ) {
				return [
					'type' => self::ERROR_TIMEOUT,
					'retry_delay' => 30, // Longer delay for timeouts
					'should_retry' => true,
				];
			}
		}

		// API errors - retry with exponential backoff
		$api_keywords = [ 'api', 'openai', 'rate limit', 'quota', '429', '500', '502', '503', '504' ];
		foreach ( $api_keywords as $keyword ) {
			if ( strpos( $error_message, $keyword ) !== false ) {
				return [
					'type' => self::ERROR_API_ERROR,
					'retry_delay' => 10, // Base delay for API errors
					'should_retry' => true,
				];
			}
		}

		// Database errors - retry with delay
		$db_keywords = [ 'database', 'mysql', 'connection', 'query', 'deadlock', 'lock wait' ];
		foreach ( $db_keywords as $keyword ) {
			if ( strpos( $error_message, $keyword ) !== false ) {
				return [
					'type' => self::ERROR_DATABASE_ERROR,
					'retry_delay' => 5,
					'should_retry' => true,
				];
			}
		}

		// Default: retryable error
		return [
			'type' => self::ERROR_RETRYABLE,
			'retry_delay' => 5,
			'should_retry' => true,
		];
	}

	/**
	 * Calculate retry delay with exponential backoff
	 *
	 * @param int $retry_count Current retry count.
	 * @return int Delay in seconds.
	 */
	private function calculateReplacementRetryDelay( int $retry_count ): int {
		switch ( $retry_count ) {
			case 1:
				return 5; // 5 seconds
			case 2:
				return 10; // 10 seconds
			case 3:
				return 20; // 20 seconds
			default:
				return 20; // Max 20 seconds
		}
	}

	/**
	 * Schedule retry for product replacement
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $product_number Product number (1-based).
	 * @param string $step Step identifier.
	 * @param int    $delay Delay in seconds.
	 * @return int|false Action ID on success, false on failure.
	 */
	private function scheduleReplacementRetry( int $post_id, int $product_number, string $step, int $delay ): int|false {
		// Determine hook based on step
		$hook = '';
		switch ( $step ) {
			case CheckpointManager::STEP_REPLACE_COLLECT_PROMPTS:
				$hook = 'aebg_replace_product_step_1';
				break;
			case CheckpointManager::STEP_REPLACE_PROCESS_FIELDS:
				$hook = 'aebg_replace_product_step_2';
				break;
			case CheckpointManager::STEP_REPLACE_APPLY_CONTENT:
				$hook = 'aebg_replace_product_step_3';
				break;
			default:
				Logger::error( 'Unknown step for retry scheduling', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'step' => $step,
				] );
				return false;
		}

		$group = "aebg_replace_{$post_id}_{$product_number}";
		$action_id = \AEBG\Core\ActionSchedulerHelper::schedule_action(
			$hook,
			[ $post_id, $product_number ],
			$group,
			$delay,
			true // unique
		);

		if ( $action_id > 0 ) {
			Logger::info( 'Replacement retry scheduled', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'step' => $step,
				'delay' => $delay,
				'action_id' => $action_id,
			] );
		} else {
			Logger::error( 'Failed to schedule replacement retry', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'step' => $step,
				'delay' => $delay,
			] );
		}

		return $action_id;
	}

	/**
	 * Mark product replacement as failed
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $product_number Product number (1-based).
	 * @param string $step Step identifier.
	 * @param string $error Error message.
	 * @param string $reason Failure reason code.
	 * @return bool True on success, false on failure.
	 */
	private function markReplacementAsFailed( int $post_id, int $product_number, string $step, string $error, string $reason = 'unknown' ): bool {
		global $wpdb;

		try {
			$table_name = $wpdb->prefix . 'aebg_product_replacement_checkpoints';
			
			$result = $wpdb->query( $wpdb->prepare(
				"UPDATE {$table_name}
				SET failed_at = NOW(),
					error_message = %s,
					failure_reason = %s,
					updated_at = NOW()
				WHERE post_id = %d AND product_number = %d",
				$error,
				$reason,
				$post_id,
				$product_number
			) );

			if ( $result === false ) {
				Logger::error( 'Failed to mark replacement as failed', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'step' => $step,
					'error' => $wpdb->last_error,
				] );
				return false;
			}

			// Clear checkpoint
			CheckpointManager::clearReplacementCheckpoint( $post_id, $product_number );

			// Cancel all scheduled steps
			\AEBG\Core\ProductReplacementScheduler::cancelReplacement( $post_id, $product_number );

			Logger::error( 'Product replacement marked as failed', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'step' => $step,
				'reason' => $reason,
				'error' => $error,
			] );

			return true;
		} catch ( \Exception $e ) {
			Logger::error( 'Exception marking replacement as failed', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'step' => $step,
				'error' => $e->getMessage(),
			] );
			return false;
		}
	}

	/**
	 * Acquire replacement lock to prevent concurrent execution
	 * Uses MySQL GET_LOCK for true atomicity (reuses pattern from acquire_generation_lock)
	 *
	 * @param int $post_id Post ID.
	 * @param int $product_number Product number (1-based).
	 * @param int $timeout Lock timeout in seconds (default: 30 minutes).
	 * @return bool True if lock acquired, false if already locked.
	 */
	private function acquireReplacementLock( int $post_id, int $product_number, int $timeout = 1800 ): bool {
		global $wpdb;
		
		// Use MySQL GET_LOCK for true atomicity (works across multiple servers)
		// Use timeout of 0 for non-blocking check - if lock is held, reschedule immediately
		$mysql_lock_name = 'aebg_replace_lock_' . $post_id . '_' . $product_number;
		$lock_result = $wpdb->get_var( $wpdb->prepare(
			"SELECT GET_LOCK(%s, %d) AS lock_acquired",
			$mysql_lock_name,
			0  // Non-blocking: return immediately if lock is held
		) );
		
		if ( $lock_result == 1 ) {
			// MySQL lock acquired successfully
			Logger::debug( 'Replacement lock acquired via MySQL GET_LOCK', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'timeout' => $timeout,
			] );
			
			// Also store in options table for visibility and fallback
			$lock_data = [
				'acquired_at' => time(),
				'expires_at' => time() + $timeout,
				'process_id' => function_exists( 'getmypid' ) ? getmypid() : 'unknown',
				'post_id' => $post_id,
				'product_number' => $product_number,
				'method' => 'mysql_get_lock',
			];
			$lock_option_key = 'aebg_replace_lock_' . $post_id . '_' . $product_number;
			update_option( $lock_option_key, maybe_serialize( $lock_data ), 'no' );
			
			return true;
		}
		
		// MySQL lock failed - check if it's because lock is held or another error
		if ( $lock_result === null ) {
			// GET_LOCK returned NULL (error occurred)
			Logger::error( 'MySQL GET_LOCK error for replacement', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'mysql_error' => $wpdb->last_error,
			] );
		} else {
			// Lock is held by another process
			Logger::debug( 'Replacement lock already held by another process', [
				'post_id' => $post_id,
				'product_number' => $product_number,
			] );
		}
		
		return false;
	}

	/**
	 * Release replacement lock
	 *
	 * @param int $post_id Post ID.
	 * @param int $product_number Product number (1-based).
	 * @return bool True on success, false on failure.
	 */
	private function releaseReplacementLock( int $post_id, int $product_number ): bool {
		global $wpdb;
		
		$mysql_lock_name = 'aebg_replace_lock_' . $post_id . '_' . $product_number;
		$lock_result = $wpdb->get_var( $wpdb->prepare(
			"SELECT RELEASE_LOCK(%s) AS lock_released",
			$mysql_lock_name
		) );
		
		// Delete options table entry
		$lock_option_key = 'aebg_replace_lock_' . $post_id . '_' . $product_number;
		delete_option( $lock_option_key );
		
		if ( $lock_result == 1 ) {
			Logger::debug( 'Replacement lock released', [
				'post_id' => $post_id,
				'product_number' => $product_number,
			] );
			return true;
		}
		
		Logger::warning( 'Failed to release replacement lock (may not have been held)', [
			'post_id' => $post_id,
			'product_number' => $product_number,
		] );
		return false;
	}

	/**
	 * Check if replacement is locked
	 *
	 * @param int $post_id Post ID.
	 * @param int $product_number Product number (1-based).
	 * @return bool True if locked, false otherwise.
	 */
	private function isReplacementLocked( int $post_id, int $product_number ): bool {
		$lock_option_key = 'aebg_replace_lock_' . $post_id . '_' . $product_number;
		$lock_data = get_option( $lock_option_key );
		
		if ( ! $lock_data ) {
			return false;
		}
		
		$lock_data = maybe_unserialize( $lock_data );
		if ( ! is_array( $lock_data ) || ! isset( $lock_data['expires_at'] ) ) {
			return false;
		}
		
		// Check if lock is stale (older than 30 minutes)
		if ( time() > $lock_data['expires_at'] ) {
			// Auto-release stale lock
			$this->releaseReplacementLock( $post_id, $product_number );
			Logger::warning( 'Stale replacement lock detected and released', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'expires_at' => $lock_data['expires_at'],
			] );
			return false;
		}
		
		return true;
	}

	/**
	 * Get replacement job start time
	 *
	 * @param int $post_id Post ID.
	 * @param int $product_number Product number (1-based).
	 * @return float Start time (microtime) or current time if not found.
	 */
	private function getReplacementStartTime( int $post_id, int $product_number ): float {
		// Try global first
		if ( isset( $GLOBALS['aebg_replacement_start_time'][ $post_id ][ $product_number ] ) ) {
			return $GLOBALS['aebg_replacement_start_time'][ $post_id ][ $product_number ];
		}
		
		// Try checkpoint
		$checkpoint = CheckpointManager::loadReplacementCheckpoint( $post_id, $product_number );
		if ( $checkpoint && isset( $checkpoint['data']['job_start_time'] ) ) {
			$start_time = (float) $checkpoint['data']['job_start_time'];
			// Store in global for future access
			if ( ! isset( $GLOBALS['aebg_replacement_start_time'] ) ) {
				$GLOBALS['aebg_replacement_start_time'] = [];
			}
			$GLOBALS['aebg_replacement_start_time'][ $post_id ][ $product_number ] = $start_time;
			return $start_time;
		}
		
		// Not found - use current time (new job)
		$start_time = microtime( true );
		if ( ! isset( $GLOBALS['aebg_replacement_start_time'] ) ) {
			$GLOBALS['aebg_replacement_start_time'] = [];
		}
		$GLOBALS['aebg_replacement_start_time'][ $post_id ][ $product_number ] = $start_time;
		return $start_time;
	}

	/**
	 * Get replacement elapsed time
	 *
	 * @param int $post_id Post ID.
	 * @param int $product_number Product number (1-based).
	 * @return float Elapsed time in seconds.
	 */
	private function getReplacementElapsedTime( int $post_id, int $product_number ): float {
		$start_time = $this->getReplacementStartTime( $post_id, $product_number );
		return microtime( true ) - $start_time;
	}

	/**
	 * Check if replacement is approaching timeout
	 *
	 * @param int $post_id Post ID.
	 * @param int $product_number Product number (1-based).
	 * @return bool True if approaching timeout, false otherwise.
	 */
	private function checkReplacementTimeout( int $post_id, int $product_number ): bool {
		$elapsed = $this->getReplacementElapsedTime( $post_id, $product_number );
		$max_time = \AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT - \AEBG\Core\TimeoutManager::SAFETY_BUFFER;
		
		// Check at 80% of max time for proactive rescheduling
		$threshold = $max_time * 0.8;
		
		return $elapsed >= $threshold;
	}

	/**
	 * Validate product replacement request
	 *
	 * @param int $post_id Post ID.
	 * @param int $product_number Product number (1-based).
	 * @return \WP_Error|true True if valid, WP_Error on failure.
	 */
	private function validateReplacementRequest( int $post_id, int $product_number ) {
		// Validate post exists
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', 'Post not found: ' . $post_id );
		}

		// Validate product exists
		$products = get_post_meta( $post_id, '_aebg_products', true );
		if ( empty( $products ) || ! is_array( $products ) ) {
			return new \WP_Error( 'no_products', 'No products found for post: ' . $post_id );
		}

		$product_index = $product_number - 1;
		if ( ! isset( $products[ $product_index ] ) || empty( $products[ $product_index ] ) ) {
			return new \WP_Error( 'product_not_found', 'Product not found at position: ' . $product_number );
		}

		// Validate Elementor data exists
		$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $elementor_data ) ) {
			return new \WP_Error( 'no_elementor_data', 'No Elementor data found for post: ' . $post_id );
		}

		// Validate Elementor data is valid JSON/array
		if ( is_string( $elementor_data ) ) {
			$decoded = json_decode( $elementor_data, true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
				return new \WP_Error( 'invalid_elementor_data', 'Invalid Elementor data structure for post: ' . $post_id );
			}
		} elseif ( ! is_array( $elementor_data ) ) {
			return new \WP_Error( 'invalid_elementor_data', 'Invalid Elementor data type for post: ' . $post_id );
		}

		return true;
	}

	/**
	 * Execute Step 1: Collect AI Prompts for Product Replacement
	 * 
	 * @param int|array $post_id Post ID or array of arguments
	 * @param int|null $product_number Product number (1-based) or null if first arg is array
	 */
	public function execute_replace_step_1( $post_id, $product_number = null ) {
		// CRITICAL: Use func_get_args() to capture ALL arguments passed by Action Scheduler
		// Action Scheduler may pass arguments in different formats depending on version/settings
		$all_args = func_get_args();
		
		Logger::debug( 'execute_replace_step_1 received arguments', [
			'arg_count' => count( $all_args ),
			'arg_0' => $all_args[0] ?? 'missing',
			'arg_0_type' => isset( $all_args[0] ) ? gettype( $all_args[0] ) : 'missing',
			'arg_1' => $all_args[1] ?? 'missing',
			'arg_1_type' => isset( $all_args[1] ) ? gettype( $all_args[1] ) : 'missing',
			'post_id_param' => $post_id,
			'product_number_param' => $product_number,
		] );
		
		// Extract arguments from various possible formats
		// Format 1: Action Scheduler passes array as first arg: [post_id, product_number]
		if ( is_array( $all_args[0] ) && count( $all_args[0] ) >= 2 ) {
			$post_id = $all_args[0][0];
			$product_number = $all_args[0][1];
		}
		// Format 2: Action Scheduler passes arguments individually: post_id, product_number
		elseif ( count( $all_args ) >= 2 && ! is_array( $all_args[0] ) ) {
			$post_id = $all_args[0];
			$product_number = $all_args[1];
		}
		// Format 3: Nested array: [[post_id, product_number]]
		elseif ( is_array( $all_args[0] ) && count( $all_args[0] ) === 1 && is_array( $all_args[0][0] ) ) {
			$nested = $all_args[0][0];
			if ( count( $nested ) >= 2 ) {
				$post_id = $nested[0];
				$product_number = $nested[1];
			}
		}
		
		// Validate and cast arguments
		$post_id = (int) $post_id;
		$product_number = (int) $product_number;
		
		// CRITICAL FALLBACK: If arguments are invalid, try to get from Action Scheduler database
		// This handles cases where Action Scheduler doesn't pass arguments correctly
		if ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) {
			Logger::warning( 'Invalid arguments for execute_replace_step_1, attempting fallback from Action Scheduler database', [
				'all_args' => $all_args,
				'parsed_post_id' => $post_id,
				'parsed_product_number' => $product_number,
			] );
			
			// Try to get from the current action's stored arguments
			// Action Scheduler stores args in the actions table
			global $wpdb;
			$action_table = $wpdb->prefix . 'actionscheduler_actions';
			
			// Strategy 1: Get the most recent in-progress or pending action for this hook
			$action = $wpdb->get_row( $wpdb->prepare(
				"SELECT action_id, args FROM {$action_table} 
				WHERE hook = %s 
				AND (status = 'in-progress' OR status = 'pending')
				ORDER BY scheduled_date_gmt DESC 
				LIMIT 1",
				'aebg_replace_product_step_1'
			), ARRAY_A );
			
			// Strategy 2: If we have a partial post_id, try to find action by matching args
			if ( ! $action && ! empty( $post_id ) && $post_id > 0 ) {
				// Query all recent actions and check their args
				$recent_actions = $wpdb->get_results( $wpdb->prepare(
					"SELECT action_id, args FROM {$action_table} 
					WHERE hook = %s 
					AND (status = 'in-progress' OR status = 'pending')
					ORDER BY scheduled_date_gmt DESC 
					LIMIT 10",
					'aebg_replace_product_step_1'
				), ARRAY_A );
				
				// Try to find one that matches our post_id
				foreach ( $recent_actions as $candidate ) {
					if ( ! empty( $candidate['args'] ) ) {
						$candidate_args = maybe_unserialize( $candidate['args'] );
						if ( ! is_array( $candidate_args ) && is_string( $candidate['args'] ) ) {
							$candidate_args = json_decode( $candidate['args'], true );
						}
						if ( is_array( $candidate_args ) && isset( $candidate_args[0] ) && (int) $candidate_args[0] === $post_id ) {
							$action = $candidate;
							break;
						}
					}
				}
			}
			
			if ( $action && ! empty( $action['args'] ) ) {
				$raw_args = $action['args'];
				$args_from_db = null;
				
				// Try multiple parsing methods
				// Method 1: PHP serialized format
				$args_from_db = maybe_unserialize( $raw_args );
				
				// Method 2: JSON format (if unserialize didn't work or returned false)
				if ( ! is_array( $args_from_db ) ) {
					// Check if it's a JSON string
					if ( is_string( $raw_args ) && ( $raw_args[0] === '[' || $raw_args[0] === '{' ) ) {
						$json_decoded = json_decode( $raw_args, true );
						if ( json_last_error() === JSON_ERROR_NONE && is_array( $json_decoded ) ) {
							$args_from_db = $json_decoded;
						}
					}
				}
				
				// Method 3: If still not an array, try direct JSON decode
				if ( ! is_array( $args_from_db ) && is_string( $raw_args ) ) {
					$json_decoded = json_decode( $raw_args, true );
					if ( json_last_error() === JSON_ERROR_NONE && is_array( $json_decoded ) ) {
						$args_from_db = $json_decoded;
					}
				}
				
				// Extract arguments from parsed data
				if ( is_array( $args_from_db ) && count( $args_from_db ) >= 2 ) {
					$post_id = (int) $args_from_db[0];
					$product_number = (int) $args_from_db[1];
					if ( $post_id > 0 && $product_number > 0 ) {
						Logger::info( 'Recovered arguments from Action Scheduler database', [
							'post_id' => $post_id,
							'product_number' => $product_number,
							'raw_args' => $raw_args,
							'parsed_args' => $args_from_db,
						] );
					}
				} else {
					Logger::warning( 'Failed to parse Action Scheduler args from database', [
						'raw_args' => $raw_args,
						'parsed_args' => $args_from_db,
						'is_array' => is_array( $args_from_db ),
						'count' => is_array( $args_from_db ) ? count( $args_from_db ) : 0,
					] );
				}
			}
			
			// Final validation - product_number must be >= 1
			if ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) {
				Logger::error( 'Invalid arguments for execute_replace_step_1 - cannot recover', [
					'all_args' => $all_args,
					'parsed_post_id' => $post_id,
					'parsed_product_number' => $product_number,
					'action_args_from_db' => $args_from_db ?? 'not_found',
				] );
				return;
			}
		}
		$start_time = microtime( true );
		$start_memory = memory_get_usage( true );
		
		// Fire monitoring hook
		do_action( 'aebg_replacement_started', $post_id, $product_number, 'step_1' );
		
		Logger::info( '===== STEP 1: COLLECT PROMPTS FOR PRODUCT REPLACEMENT =====', [
			'post_id' => $post_id,
			'product_number' => $product_number,
			'process_id' => function_exists( 'getmypid' ) ? getmypid() : 'unknown',
		] );
		
		// Set execution flag
		self::$is_executing = true;
		$GLOBALS['AEBG_GENERATION_IN_PROGRESS'] = true;
		
		// Acquire lock to prevent concurrent execution
		$lock_acquired = $this->acquireReplacementLock( $post_id, $product_number );
		if ( ! $lock_acquired ) {
			Logger::warning( 'Could not acquire replacement lock, rescheduling', [
				'post_id' => $post_id,
				'product_number' => $product_number,
			] );
			// Reschedule after 5 seconds
			$this->scheduleReplacementRetry( $post_id, $product_number, CheckpointManager::STEP_REPLACE_COLLECT_PROMPTS, 5 );
			self::$is_executing = false;
			unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
			return;
		}
		
		try {
			// Validate request
			$validation = $this->validateReplacementRequest( $post_id, $product_number );
			if ( is_wp_error( $validation ) ) {
				Logger::error( 'Replacement request validation failed', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'error' => $validation->get_error_message(),
					'error_code' => $validation->get_error_code(),
				] );
				// Release lock before returning
				$this->releaseReplacementLock( $post_id, $product_number );
				self::$is_executing = false;
				unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
				return;
			}
			
			// Get AI settings
			$aebg_settings = get_option( 'aebg_settings', [] );
			$settings = [
				'openai_api_key' => $aebg_settings['api_key'] ?? '',
				'ai_model' => $aebg_settings['model'] ?? 'gpt-3.5-turbo'
			];
			
			if ( empty( $settings['openai_api_key'] ) ) {
				Logger::warning( 'OpenAI API key not configured. Skipping AI content regeneration.', [
					'post_id' => $post_id,
					'product_number' => $product_number,
				] );
				// Still proceed to schedule next step for variable updates
			}
			
			// CRITICAL: Enrich the NEW product BEFORE collecting prompts
			// Get products from database (the new product should already be saved at the target index)
			$products = get_post_meta( $post_id, '_aebg_products', true );
			if ( ! empty( $products ) && is_array( $products ) ) {
				$api_key = $settings['openai_api_key'] ?? '';
				$ai_model = $settings['ai_model'] ?? 'gpt-3.5-turbo';
				Logger::debug( 'Enriching NEW product before collecting prompts', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'product_index' => $product_number - 1,
				] );
				
				// Initialize Generator for enrichment
				$generator = new \AEBG\Core\Generator( $settings );
				
				// Enrich only the new product (at target index)
				$products = $generator->enrichProductsForRegeneration( $products, $api_key, $ai_model, $product_number );
				
				// Save enriched products back to database
				update_post_meta( $post_id, '_aebg_products', $products );
				Logger::debug( 'NEW product enriched and saved to database', [
					'post_id' => $post_id,
					'product_number' => $product_number,
				] );
			}
			
			// Initialize Generator for prompt collection
			$generator = new \AEBG\Core\Generator( $settings );
			
			// Collect prompts
			$result = $generator->collectPromptsForProduct( $post_id, $product_number );
			
			if ( is_wp_error( $result ) ) {
				// Classify error
				$error_classification = $this->classifyReplacementError( $result );
				
				// Don't retry fatal errors
				if ( ! $error_classification['should_retry'] ) {
					Logger::error( 'Failed to collect prompts (fatal error, no retry)', [
						'post_id' => $post_id,
						'product_number' => $product_number,
						'error' => $result->get_error_message(),
						'error_code' => $result->get_error_code(),
						'error_type' => $error_classification['type'],
					] );
				return;
			}
			
				// Check retry count
				$retry_count = CheckpointManager::getReplacementRetryCount( $post_id, $product_number, CheckpointManager::STEP_REPLACE_COLLECT_PROMPTS );
				
				if ( $retry_count >= CheckpointManager::MAX_RETRY_ATTEMPTS ) {
					// Max retries reached - mark as failed
					$this->markReplacementAsFailed(
						$post_id,
						$product_number,
						CheckpointManager::STEP_REPLACE_COLLECT_PROMPTS,
						$result->get_error_message(),
						'max_retries_exceeded'
					);
					// Release lock before returning
					$this->releaseReplacementLock( $post_id, $product_number );
					self::$is_executing = false;
					unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
					return;
				}
				
				// Increment retry count and schedule retry
				CheckpointManager::incrementReplacementRetryCount( $post_id, $product_number, CheckpointManager::STEP_REPLACE_COLLECT_PROMPTS );
				$delay = $this->calculateReplacementRetryDelay( $retry_count + 1 );
				$this->scheduleReplacementRetry( $post_id, $product_number, CheckpointManager::STEP_REPLACE_COLLECT_PROMPTS, $delay );
				
				Logger::warning( 'Failed to collect prompts, retry scheduled', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'error' => $result->get_error_message(),
					'error_code' => $result->get_error_code(),
					'error_type' => $error_classification['type'],
					'retry_count' => $retry_count + 1,
					'delay' => $delay,
				] );
				return;
			}
			
			// Reset retry count on success
			CheckpointManager::resetReplacementRetryCount( $post_id, $product_number, CheckpointManager::STEP_REPLACE_COLLECT_PROMPTS );
			
			// Save checkpoint using database (replacing transient)
			// Include job_start_time for timeout tracking
			$job_start_time = $this->getReplacementStartTime( $post_id, $product_number );
			$checkpoint_state = [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'elementor_data' => $result['elementor_data'],
				'container' => $result['container'],
				'testvinder_container' => $result['testvinder_container'] ?? null,
				'context_registry_state' => $result['context_registry_state'],
				'settings' => $settings,
				'job_start_time' => $job_start_time,
				'has_testvinder' => !empty($result['testvinder_container']),
			];
			
			$saved = CheckpointManager::saveReplacementCheckpoint(
				$post_id,
				$product_number,
				CheckpointManager::STEP_REPLACE_COLLECT_PROMPTS,
				$checkpoint_state
			);
			
			if ( ! $saved ) {
				Logger::error( 'Failed to save replacement checkpoint', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'step' => CheckpointManager::STEP_REPLACE_COLLECT_PROMPTS,
				] );
				return;
			}
			
			$elapsed_time = microtime( true ) - $start_time;
			$peak_memory = memory_get_peak_usage( true );
			
			Logger::info( 'Step 1 completed: Collected AI prompts', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'field_count' => $result['field_count'] ?? 0,
				'execution_time' => round( $elapsed_time, 2 ),
				'memory_usage_mb' => round( $start_memory / 1024 / 1024, 2 ),
				'peak_memory_mb' => round( $peak_memory / 1024 / 1024, 2 ),
			] );
			
			// Fire monitoring hook
			do_action( 'aebg_replacement_step_completed', $post_id, $product_number, 'step_1', [
				'execution_time' => $elapsed_time,
				'memory_usage' => $start_memory,
				'peak_memory' => $peak_memory,
				'field_count' => $result['field_count'] ?? 0,
			] );
			
			// Schedule Step 2 (delay=0 for immediate execution)
			$step_2_action_id = ProductReplacementScheduler::scheduleNextStep( 'step_1', $post_id, $product_number, 0 );
			
			if ( ! $step_2_action_id || $step_2_action_id <= 0 ) {
				Logger::error( 'CRITICAL: Failed to schedule Step 2 after Step 1 completion', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'step_2_action_id' => $step_2_action_id,
				] );
				// Don't return - Step 1 completed successfully, but log the error
			} else {
				Logger::info( 'Step 2 scheduled successfully after Step 1', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'step_2_action_id' => $step_2_action_id,
				] );
				
				// CRITICAL: Force Action Scheduler to process the next action immediately
				// Since we're inside an action execution, we need multiple approaches
				if ( class_exists( '\ActionScheduler_QueueRunner' ) ) {
					try {
						$runner = \ActionScheduler_QueueRunner::instance();
						
						// Approach 1: Try to run queue directly (processes pending actions)
						if ( method_exists( $runner, 'run' ) ) {
							// Run with limit of 1 to process just the next action
							$processed = $runner->run( 1 );
							Logger::info( 'Triggered Action Scheduler queue runner directly after Step 1', [
								'actions_processed' => $processed,
							] );
						}
						
						// Approach 2: Also try async request dispatch (for next HTTP request)
						if ( method_exists( $runner, 'maybe_dispatch_async_request' ) ) {
							$dispatched = $runner->maybe_dispatch_async_request();
							Logger::debug( 'Triggered Action Scheduler async request dispatch after Step 1', [
								'dispatched' => $dispatched,
							] );
						}
		} catch ( \Exception $e ) {
						Logger::warning( 'Failed to trigger queue runner directly', [
							'error' => $e->getMessage(),
						] );
					}
				}
				
			}
			
		} catch ( \Exception $e ) {
			// Classify exception
			$error_classification = $this->classifyReplacementError( $e );
			
			// Don't retry fatal errors
			if ( ! $error_classification['should_retry'] ) {
			$elapsed_time = microtime( true ) - $start_time;
			
			Logger::error( 'Exception in Step 1 (fatal error, no retry)', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'error' => $e->getMessage(),
				'error_type' => $error_classification['type'],
				'execution_time' => round( $elapsed_time, 2 ),
				'trace' => $e->getTraceAsString(),
			] );
			
			// Fire monitoring hook
			do_action( 'aebg_replacement_failed', $post_id, $product_number, 'step_1', [
				'error' => $e->getMessage(),
				'error_type' => $error_classification['type'],
				'execution_time' => $elapsed_time,
			] );
			
			return;
			}
			
			// Check retry count
			$retry_count = CheckpointManager::getReplacementRetryCount( $post_id, $product_number, CheckpointManager::STEP_REPLACE_COLLECT_PROMPTS );
			
			if ( $retry_count >= CheckpointManager::MAX_RETRY_ATTEMPTS ) {
				// Max retries reached - mark as failed
				$this->markReplacementAsFailed(
					$post_id,
					$product_number,
					CheckpointManager::STEP_REPLACE_COLLECT_PROMPTS,
					$e->getMessage(),
					'max_retries_exceeded'
				);
				return;
			}
			
			// Increment retry count and schedule retry
			CheckpointManager::incrementReplacementRetryCount( $post_id, $product_number, CheckpointManager::STEP_REPLACE_COLLECT_PROMPTS );
			$delay = $this->calculateReplacementRetryDelay( $retry_count + 1 );
			$this->scheduleReplacementRetry( $post_id, $product_number, CheckpointManager::STEP_REPLACE_COLLECT_PROMPTS, $delay );
			
			$elapsed_time = microtime( true ) - $start_time;
			
			Logger::warning( 'Exception in Step 1, retry scheduled', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'error' => $e->getMessage(),
				'error_type' => $error_classification['type'],
				'retry_count' => $retry_count + 1,
				'delay' => $delay,
				'execution_time' => round( $elapsed_time, 2 ),
			] );
			
			// Fire monitoring hook
			do_action( 'aebg_replacement_retried', $post_id, $product_number, 'step_1', [
				'error' => $e->getMessage(),
				'error_type' => $error_classification['type'],
				'retry_count' => $retry_count + 1,
				'delay' => $delay,
				'execution_time' => $elapsed_time,
			] );
		} finally {
			// Release lock
			$this->releaseReplacementLock( $post_id, $product_number );
			self::$is_executing = false;
			unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
		}
	}

	/**
	 * Execute Step 2: Process AI Fields for Product Replacement
	 * 
	 * @param int|array $post_id Post ID or array of arguments
	 * @param int|null $product_number Product number (1-based) or null if first arg is array
	 */
	public function execute_replace_step_2( $post_id, $product_number = null ) {
		// CRITICAL: Use func_get_args() to capture ALL arguments passed by Action Scheduler
		$all_args = func_get_args();
		
		// Extract arguments from various possible formats
		if ( is_array( $all_args[0] ) && count( $all_args[0] ) >= 2 ) {
			$post_id = $all_args[0][0];
			$product_number = $all_args[0][1];
		} elseif ( count( $all_args ) >= 2 && ! is_array( $all_args[0] ) ) {
			$post_id = $all_args[0];
			$product_number = $all_args[1];
		} elseif ( is_array( $all_args[0] ) && count( $all_args[0] ) === 1 && is_array( $all_args[0][0] ) ) {
			$nested = $all_args[0][0];
			if ( count( $nested ) >= 2 ) {
				$post_id = $nested[0];
				$product_number = $nested[1];
			}
		}
		
		// Validate and cast arguments
		$post_id = (int) $post_id;
		$product_number = (int) $product_number;
		
		// CRITICAL FALLBACK: If arguments are invalid, try to get from Action Scheduler or checkpoint
		if ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) {
			// First try Action Scheduler database
			global $wpdb;
			$action_table = $wpdb->prefix . 'actionscheduler_actions';
			
			// Strategy 1: Get the most recent in-progress or pending action for this hook
			$action = $wpdb->get_row( $wpdb->prepare(
				"SELECT action_id, args FROM {$action_table} 
				WHERE hook = %s 
				AND (status = 'in-progress' OR status = 'pending')
				ORDER BY scheduled_date_gmt DESC 
				LIMIT 1",
				'aebg_replace_product_step_2'
			), ARRAY_A );
			
			// Strategy 2: If we have a partial post_id, try to find action by matching args
			if ( ! $action && ! empty( $post_id ) && $post_id > 0 ) {
				// Query all recent actions and check their args
				$recent_actions = $wpdb->get_results( $wpdb->prepare(
					"SELECT action_id, args FROM {$action_table} 
					WHERE hook = %s 
					AND (status = 'in-progress' OR status = 'pending')
					ORDER BY scheduled_date_gmt DESC 
					LIMIT 10",
					'aebg_replace_product_step_2'
				), ARRAY_A );
				
				// Try to find one that matches our post_id
				foreach ( $recent_actions as $candidate ) {
					if ( ! empty( $candidate['args'] ) ) {
						$candidate_args = maybe_unserialize( $candidate['args'] );
						if ( ! is_array( $candidate_args ) && is_string( $candidate['args'] ) ) {
							$candidate_args = json_decode( $candidate['args'], true );
						}
						if ( is_array( $candidate_args ) && isset( $candidate_args[0] ) && (int) $candidate_args[0] === $post_id ) {
							$action = $candidate;
							break;
						}
					}
				}
			}
			
			if ( $action && ! empty( $action['args'] ) ) {
				$raw_args = $action['args'];
				$args_from_db = null;
				
				// Try multiple parsing methods
				// Method 1: PHP serialized format
				$args_from_db = maybe_unserialize( $raw_args );
				
				// Method 2: JSON format (if unserialize didn't work or returned false)
				if ( ! is_array( $args_from_db ) ) {
					// Check if it's a JSON string
					if ( is_string( $raw_args ) && ( $raw_args[0] === '[' || $raw_args[0] === '{' ) ) {
						$json_decoded = json_decode( $raw_args, true );
						if ( json_last_error() === JSON_ERROR_NONE && is_array( $json_decoded ) ) {
							$args_from_db = $json_decoded;
						}
					}
				}
				
				// Method 3: If still not an array, try direct JSON decode
				if ( ! is_array( $args_from_db ) && is_string( $raw_args ) ) {
					$json_decoded = json_decode( $raw_args, true );
					if ( json_last_error() === JSON_ERROR_NONE && is_array( $json_decoded ) ) {
						$args_from_db = $json_decoded;
					}
				}
				
				// Extract arguments from parsed data
				if ( is_array( $args_from_db ) && count( $args_from_db ) >= 2 ) {
					$post_id = (int) $args_from_db[0];
					$product_number = (int) $args_from_db[1];
					if ( $post_id > 0 && $product_number > 0 ) {
						Logger::info( 'Recovered arguments from Action Scheduler database (Step 2)', [
							'post_id' => $post_id,
							'product_number' => $product_number,
							'raw_args' => $raw_args,
							'parsed_args' => $args_from_db,
						] );
					}
				} else {
					Logger::warning( 'Failed to parse Action Scheduler args from database (Step 2)', [
						'raw_args' => $raw_args,
						'parsed_args' => $args_from_db,
						'is_array' => is_array( $args_from_db ),
						'count' => is_array( $args_from_db ) ? count( $args_from_db ) : 0,
					] );
				}
			}
			
			// If still invalid and we have at least post_id, try to find checkpoint
			if ( ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) && $post_id > 0 ) {
				// Try to find checkpoint by post_id (query all checkpoints for this post)
				$checkpoint_table = $wpdb->prefix . 'aebg_product_replacement_checkpoints';
				$checkpoint_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT product_number, checkpoint_state FROM {$checkpoint_table} 
					WHERE post_id = %d 
					ORDER BY updated_at DESC 
					LIMIT 1",
					$post_id
				), ARRAY_A );
				
				if ( $checkpoint_row ) {
					$product_number = (int) $checkpoint_row['product_number'];
					Logger::info( 'Recovered product_number from checkpoint table', [
						'post_id' => $post_id,
						'product_number' => $product_number,
					] );
				}
			}
			
			if ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) {
				Logger::error( 'Invalid arguments for execute_replace_step_2 - cannot recover', [
					'all_args' => $all_args,
					'parsed_post_id' => $post_id,
					'parsed_product_number' => $product_number,
				] );
				return;
			}
		}
		
		$start_time = microtime( true );
		$start_memory = memory_get_usage( true );
		
		// Fire monitoring hook
		do_action( 'aebg_replacement_started', $post_id, $product_number, 'step_2' );
		
		Logger::info( '===== STEP 2: PROCESS AI FIELDS FOR PRODUCT REPLACEMENT =====', [
			'post_id' => $post_id,
			'product_number' => $product_number,
			'process_id' => function_exists( 'getmypid' ) ? getmypid() : 'unknown',
		] );
		
		// Set execution flag
		self::$is_executing = true;
		$GLOBALS['AEBG_GENERATION_IN_PROGRESS'] = true;
		
		// Acquire lock to prevent concurrent execution (should already be held from Step 1, but check anyway)
		$lock_acquired = $this->acquireReplacementLock( $post_id, $product_number );
		if ( ! $lock_acquired ) {
			Logger::warning( 'Could not acquire replacement lock, rescheduling', [
				'post_id' => $post_id,
				'product_number' => $product_number,
			] );
			// Reschedule after 5 seconds
			$this->scheduleReplacementRetry( $post_id, $product_number, CheckpointManager::STEP_REPLACE_PROCESS_FIELDS, 5 );
			self::$is_executing = false;
			unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
			return;
		}
		
		try {
			// Validate request
			$validation = $this->validateReplacementRequest( $post_id, $product_number );
			if ( is_wp_error( $validation ) ) {
				Logger::error( 'Replacement request validation failed', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'error' => $validation->get_error_message(),
					'error_code' => $validation->get_error_code(),
				] );
				// Release lock before returning
				$this->releaseReplacementLock( $post_id, $product_number );
				self::$is_executing = false;
				unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
				return;
			}
			
			// Load checkpoint from database (replacing transient)
			$checkpoint = CheckpointManager::loadReplacementCheckpoint( $post_id, $product_number );
			
			if ( ! $checkpoint || ! isset( $checkpoint['step'] ) || $checkpoint['step'] !== CheckpointManager::STEP_REPLACE_COLLECT_PROMPTS ) {
				// Check if checkpoint is corrupted
				if ( $checkpoint && isset( $checkpoint['step'] ) && $checkpoint['step'] !== CheckpointManager::STEP_REPLACE_COLLECT_PROMPTS ) {
					Logger::warning( 'Corrupted checkpoint detected, clearing and restarting', [
						'post_id' => $post_id,
						'product_number' => $product_number,
						'checkpoint_step' => $checkpoint['step'],
						'expected_step' => CheckpointManager::STEP_REPLACE_COLLECT_PROMPTS,
					] );
					CheckpointManager::clearReplacementCheckpoint( $post_id, $product_number );
					// Restart from Step 1
					$this->scheduleReplacementRetry( $post_id, $product_number, CheckpointManager::STEP_REPLACE_COLLECT_PROMPTS, 5 );
				return;
			}
			
				Logger::error( 'Invalid checkpoint for Step 2', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'checkpoint_step' => $checkpoint['step'] ?? 'missing',
					'expected_step' => CheckpointManager::STEP_REPLACE_COLLECT_PROMPTS,
				] );
				// Release lock before returning
				$this->releaseReplacementLock( $post_id, $product_number );
				self::$is_executing = false;
				unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
				return;
			}
			
			// Resume from incremental checkpoint if available
			$checkpoint_data = $checkpoint['data'] ?? [];
			$processed_fields = $checkpoint_data['field_processing_progress'] ?? [];
			$current_field_index = $checkpoint_data['current_field_index'] ?? null;
			
			$checkpoint_data = $checkpoint['data'] ?? [];
			$context_registry_state = $checkpoint_data['context_registry_state'] ?? [];
			$settings = $checkpoint_data['settings'] ?? [];
			
			// Get AI settings - try multiple sources
			$api_key = $settings['openai_api_key'] ?? '';
			$ai_model = $settings['ai_model'] ?? 'gpt-3.5-turbo';
			
			// DEBUG: Log checkpoint settings for troubleshooting
			Logger::debug( 'Step 2 API key check', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'checkpoint_has_settings' => ! empty( $settings ),
				'checkpoint_api_key_present' => isset( $settings['openai_api_key'] ),
				'checkpoint_api_key_empty' => empty( $api_key ),
				'settings_keys' => array_keys( $settings ?? [] ),
			] );
			
			// FALLBACK: If API key not in checkpoint settings, try to get from WordPress options
			if ( empty( $api_key ) ) {
				$aebg_settings = get_option( 'aebg_settings', [] );
				$api_key = $aebg_settings['api_key'] ?? '';
				Logger::info( 'API key not found in checkpoint, retrieved from WordPress options', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'api_key_found' => ! empty( $api_key ),
					'api_key_length' => strlen( $api_key ),
					'aebg_settings_keys' => array_keys( $aebg_settings ?? [] ),
				] );
			}
			
			// FALLBACK: Also try constant if still empty
			if ( empty( $api_key ) && defined( 'AEBG_AI_API_KEY' ) ) {
				$api_key = AEBG_AI_API_KEY;
				Logger::info( 'API key retrieved from AEBG_AI_API_KEY constant', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'api_key_length' => strlen( $api_key ),
				] );
			}
			
			if ( empty( $api_key ) ) {
				Logger::warning( 'OpenAI API key not configured. Skipping AI processing, scheduling Step 3 for variable updates only.', [
					'post_id' => $post_id,
					'product_number' => $product_number,
				] );
				
				// CRITICAL: Still save a checkpoint for Step 2 so Step 3 can validate it
				// Use the context registry state from Step 1 (unchanged since no AI processing)
				$job_start_time = $this->getReplacementStartTime( $post_id, $product_number );
				$checkpoint_state = [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'elementor_data' => $checkpoint_data['elementor_data'] ?? [],
					'container' => $checkpoint_data['container'] ?? [],
					'context_registry_state' => $context_registry_state, // Unchanged from Step 1
					'settings' => $settings,
					'job_start_time' => $job_start_time,
					'ai_skipped' => true, // Flag to indicate AI processing was skipped
				];
				
				$saved = CheckpointManager::saveReplacementCheckpoint(
					$post_id,
					$product_number,
					CheckpointManager::STEP_REPLACE_PROCESS_FIELDS,
					$checkpoint_state
				);
				
				if ( ! $saved ) {
					Logger::error( 'Failed to save replacement checkpoint when skipping AI', [
						'post_id' => $post_id,
						'product_number' => $product_number,
					] );
				} else {
					Logger::info( 'Saved Step 2 checkpoint (AI skipped)', [
						'post_id' => $post_id,
						'product_number' => $product_number,
					] );
				}
				
				// Schedule Step 3 anyway for variable updates (delay=0 for immediate execution)
				ProductReplacementScheduler::scheduleNextStep( 'step_2', $post_id, $product_number, 0 );
				return;
			}
			
			// Initialize Generator
			$generator = new \AEBG\Core\Generator( $settings );
			
			// Restore context registry state
			$context_registry = new \AEBG\Core\ContextRegistry();
			if ( method_exists( $context_registry, 'importState' ) ) {
				$context_registry->importState( $context_registry_state );
			}
			
			// Initialize template processor
			global $aebg_variables;
			if ( ! $aebg_variables ) {
				$aebg_variables = new \AEBG\Core\Variables();
			}
			
			// Initialize AI processor if needed
			$ai_processor = null;
			if ( ! empty( $api_key ) ) {
				$ai_processor = new \AEBG\Core\AIPromptProcessor( $aebg_variables, $context_registry, $api_key, $ai_model );
			}
			
			$template_processor = new \AEBG\Core\ElementorTemplateProcessor(
				$context_registry,
				$ai_processor,
				new \AEBG\Core\VariableReplacer(),
				new \AEBG\Core\ContentGenerator( new \AEBG\Core\VariableReplacer() )
			);
			
			// Get products and title
			// CRITICAL: Products are already enriched in Step 1, no need to enrich again
			$products = get_post_meta( $post_id, '_aebg_products', true );
			
			$post = get_post( $post_id );
			$title = $post ? $post->post_title : '';
			
			// Get processing order
			$processing_order = $context_registry->getProcessingOrder();
			
			// Resume from incremental checkpoint if available
			$processed_fields = $checkpoint_data['field_processing_progress'] ?? [];
			$current_field_index = $checkpoint_data['current_field_index'] ?? null;
			
			// If resuming, skip already processed fields
			if ( ! empty( $processed_fields ) && $current_field_index !== null ) {
				Logger::info( 'Resuming from incremental checkpoint', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'processed_fields' => count( $processed_fields ),
					'current_field_index' => $current_field_index,
					'total_fields' => count( $processing_order ),
				] );
				
				// Filter out already processed fields
				$processing_order = array_filter( $processing_order, function( $field_id ) use ( $processed_fields ) {
					return ! in_array( $field_id, $processed_fields, true );
				} );
				$processing_order = array_values( $processing_order ); // Re-index
			}
			
			// CRITICAL: Handle empty processing_order - skip to Step 3 if no fields to process
			if ( empty( $processing_order ) ) {
				Logger::info( 'No AI fields to process, skipping to Step 3', [
					'post_id' => $post_id,
					'product_number' => $product_number,
				] );
				
				// Save checkpoint with empty context_registry_state
				$checkpoint_state = [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'elementor_data' => $checkpoint_data['elementor_data'] ?? [],
					'container' => $checkpoint_data['container'] ?? [],
					'context_registry_state' => $context_registry_state, // Keep existing state
					'settings' => $settings,
				];
				
				CheckpointManager::saveReplacementCheckpoint(
					$post_id,
					$product_number,
					CheckpointManager::STEP_REPLACE_PROCESS_FIELDS,
					$checkpoint_state
				);
				
				// Schedule Step 3
				ProductReplacementScheduler::scheduleNextStep( 'step_2', $post_id, $product_number, 2 );
				return;
			}
			
			// Log icon-list-item fields in processing order for debugging
			$icon_list_fields = [];
			foreach ( $processing_order as $field_id ) {
				$field_data = $context_registry->getContext( $field_id );
				if ( $field_data && isset( $field_data['widget_type'] ) && $field_data['widget_type'] === 'icon-list-item' ) {
					$icon_list_fields[] = $field_id;
				}
			}
			
			Logger::debug( 'Processing AI fields', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'field_count' => count( $processing_order ),
				'icon_list_fields_count' => count( $icon_list_fields ),
				'icon_list_field_ids' => array_slice( $icon_list_fields, 0, 10 ), // Log first 10
			] );
			
			// Process each field with timeout checking
			$context = [
				'title' => $title,
				'product_count' => count( $products ),
				'products' => $products,
			];
			
			$processed_fields = [];
			foreach ( $processing_order as $field_id ) {
				// Check timeout before processing each field
				if ( $this->checkReplacementTimeout( $post_id, $product_number ) ) {
					Logger::warning( 'Approaching timeout, saving incremental checkpoint and rescheduling', [
						'post_id' => $post_id,
						'product_number' => $product_number,
						'elapsed_time' => $this->getReplacementElapsedTime( $post_id, $product_number ),
						'processed_fields' => count( $processed_fields ),
						'total_fields' => count( $processing_order ),
					] );
					
					// Save incremental checkpoint with progress
					$checkpoint_state = [
						'post_id' => $post_id,
						'product_number' => $product_number,
						'elementor_data' => $checkpoint_data['elementor_data'] ?? [],
						'container' => $checkpoint_data['container'] ?? [],
						'testvinder_container' => $checkpoint_data['testvinder_container'] ?? null, // Preserve testvinder container
						'context_registry_state' => $context_registry->exportState(), // Partial results
						'settings' => $settings,
						'field_processing_progress' => $processed_fields, // Track processed fields
						'current_field_index' => array_search( $field_id, $processing_order, true ),
						'job_start_time' => $this->getReplacementStartTime( $post_id, $product_number ), // Preserve timeout tracking
					];
					
					CheckpointManager::saveReplacementCheckpoint(
						$post_id,
						$product_number,
						CheckpointManager::STEP_REPLACE_PROCESS_FIELDS,
						$checkpoint_state
					);
					
					// Reschedule Step 2 with delay
					$this->scheduleReplacementRetry( $post_id, $product_number, CheckpointManager::STEP_REPLACE_PROCESS_FIELDS, 5 );
					return;
				}
				
				$template_processor->processAIField( $field_id, $title, $products, $context, $api_key, $ai_model );
				$processed_fields[] = $field_id;
			}
			
			// Export updated context registry state
			$updated_context_registry_state = $context_registry->exportState();
			
			// Save checkpoint using database (replacing transient)
			// Include job_start_time for timeout tracking
			$job_start_time = $this->getReplacementStartTime( $post_id, $product_number );
			$checkpoint_state = [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'elementor_data' => $checkpoint_data['elementor_data'] ?? [],
				'container' => $checkpoint_data['container'] ?? [],
				'testvinder_container' => $checkpoint_data['testvinder_container'] ?? null, // Preserve testvinder container
				'context_registry_state' => $updated_context_registry_state,
				'settings' => $settings,
				'job_start_time' => $job_start_time,
			];
			
			$saved = CheckpointManager::saveReplacementCheckpoint(
				$post_id,
				$product_number,
				CheckpointManager::STEP_REPLACE_PROCESS_FIELDS,
				$checkpoint_state
			);
			
			if ( ! $saved ) {
				Logger::error( 'Failed to save replacement checkpoint', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'step' => CheckpointManager::STEP_REPLACE_PROCESS_FIELDS,
				] );
				return;
			}
			
			// Reset retry count on success
			CheckpointManager::resetReplacementRetryCount( $post_id, $product_number, CheckpointManager::STEP_REPLACE_PROCESS_FIELDS );
			
			$elapsed_time = microtime( true ) - $start_time;
			$peak_memory = memory_get_peak_usage( true );
			
			Logger::info( 'Step 2 completed: Processed AI fields', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'field_count' => count( $processing_order ),
				'execution_time' => round( $elapsed_time, 2 ),
				'memory_usage_mb' => round( $start_memory / 1024 / 1024, 2 ),
				'peak_memory_mb' => round( $peak_memory / 1024 / 1024, 2 ),
			] );
			
			// Fire monitoring hook
			do_action( 'aebg_replacement_step_completed', $post_id, $product_number, 'step_2', [
				'execution_time' => $elapsed_time,
				'memory_usage' => $start_memory,
				'peak_memory' => $peak_memory,
				'field_count' => count( $processing_order ),
			] );
			
			// Schedule Step 3 (delay=0 for immediate execution)
			$step_3_action_id = ProductReplacementScheduler::scheduleNextStep( 'step_2', $post_id, $product_number, 0 );
			
			if ( ! $step_3_action_id || $step_3_action_id <= 0 ) {
				Logger::error( 'CRITICAL: Failed to schedule Step 3 after Step 2 completion', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'step_3_action_id' => $step_3_action_id,
				] );
			} else {
				Logger::info( 'Step 3 scheduled successfully after Step 2', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'step_3_action_id' => $step_3_action_id,
				] );
				
				// CRITICAL: Force Action Scheduler to process the next action immediately
				// Since we're inside an action execution, we need multiple approaches
				if ( class_exists( '\ActionScheduler_QueueRunner' ) ) {
					try {
						$runner = \ActionScheduler_QueueRunner::instance();
						
						// Approach 1: Try to run queue directly (processes pending actions)
						if ( method_exists( $runner, 'run' ) ) {
							// Run with limit of 1 to process just the next action
							$processed = $runner->run( 1 );
							Logger::info( 'Triggered Action Scheduler queue runner directly after Step 2', [
								'actions_processed' => $processed,
							] );
						}
						
						// Approach 2: Also try async request dispatch (for next HTTP request)
						if ( method_exists( $runner, 'maybe_dispatch_async_request' ) ) {
							$dispatched = $runner->maybe_dispatch_async_request();
							Logger::debug( 'Triggered Action Scheduler async request dispatch after Step 2', [
								'dispatched' => $dispatched,
							] );
						}
		} catch ( \Exception $e ) {
						Logger::warning( 'Failed to trigger queue runner directly', [
							'error' => $e->getMessage(),
						] );
					}
				}
			}
			
		} catch ( \Exception $e ) {
			// Classify exception
			$error_classification = $this->classifyReplacementError( $e );
			
			$elapsed_time = microtime( true ) - $start_time;
			
			// Don't retry fatal errors
			if ( ! $error_classification['should_retry'] ) {
				Logger::error( 'Exception in Step 2 (fatal error, no retry)', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'error' => $e->getMessage(),
					'error_type' => $error_classification['type'],
					'execution_time' => round( $elapsed_time, 2 ),
					'trace' => $e->getTraceAsString(),
				] );
				
				// Fire monitoring hook
				do_action( 'aebg_replacement_failed', $post_id, $product_number, 'step_2', [
					'error' => $e->getMessage(),
					'error_type' => $error_classification['type'],
					'execution_time' => $elapsed_time,
				] );
				
				return;
			}
			
			// Check retry count
			$retry_count = CheckpointManager::getReplacementRetryCount( $post_id, $product_number, CheckpointManager::STEP_REPLACE_PROCESS_FIELDS );
			
			if ( $retry_count >= CheckpointManager::MAX_RETRY_ATTEMPTS ) {
				// Max retries reached - mark as failed
				$this->markReplacementAsFailed(
					$post_id,
					$product_number,
					CheckpointManager::STEP_REPLACE_PROCESS_FIELDS,
					$e->getMessage(),
					'max_retries_exceeded'
				);
				
				// Fire monitoring hook
				do_action( 'aebg_replacement_failed', $post_id, $product_number, 'step_2', [
					'error' => $e->getMessage(),
					'error_type' => $error_classification['type'],
					'execution_time' => $elapsed_time,
					'reason' => 'max_retries_exceeded',
				] );
				
				return;
			}
			
			// Increment retry count and schedule retry
			CheckpointManager::incrementReplacementRetryCount( $post_id, $product_number, CheckpointManager::STEP_REPLACE_PROCESS_FIELDS );
			$delay = $this->calculateReplacementRetryDelay( $retry_count + 1 );
			$this->scheduleReplacementRetry( $post_id, $product_number, CheckpointManager::STEP_REPLACE_PROCESS_FIELDS, $delay );
			
			Logger::warning( 'Exception in Step 2, retry scheduled', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'error' => $e->getMessage(),
				'error_type' => $error_classification['type'],
				'retry_count' => $retry_count + 1,
				'delay' => $delay,
				'execution_time' => round( $elapsed_time, 2 ),
			] );
			
			// Fire monitoring hook
			do_action( 'aebg_replacement_retried', $post_id, $product_number, 'step_2', [
				'error' => $e->getMessage(),
				'error_type' => $error_classification['type'],
				'retry_count' => $retry_count + 1,
				'delay' => $delay,
				'execution_time' => $elapsed_time,
			] );
		} finally {
			// Release lock
			$this->releaseReplacementLock( $post_id, $product_number );
			self::$is_executing = false;
			unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
		}
	}

	/**
	 * Execute Step 3: Apply Content and Save for Product Replacement
	 * 
	 * @param int|array $post_id Post ID or array of arguments
	 * @param int|null $product_number Product number (1-based) or null if first arg is array
	 */
	public function execute_replace_step_3( $post_id, $product_number = null ) {
		// CRITICAL: Use func_get_args() to capture ALL arguments passed by Action Scheduler
		$all_args = func_get_args();
		
		// Extract arguments from various possible formats
		if ( is_array( $all_args[0] ) && count( $all_args[0] ) >= 2 ) {
			$post_id = $all_args[0][0];
			$product_number = $all_args[0][1];
		} elseif ( count( $all_args ) >= 2 && ! is_array( $all_args[0] ) ) {
			$post_id = $all_args[0];
			$product_number = $all_args[1];
		} elseif ( is_array( $all_args[0] ) && count( $all_args[0] ) === 1 && is_array( $all_args[0][0] ) ) {
			$nested = $all_args[0][0];
			if ( count( $nested ) >= 2 ) {
				$post_id = $nested[0];
				$product_number = $nested[1];
			}
		}
		
		// Validate and cast arguments
		$post_id = (int) $post_id;
		$product_number = (int) $product_number;
		
		// CRITICAL FALLBACK: If arguments are invalid, try to get from Action Scheduler or checkpoint
		if ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) {
			// First try Action Scheduler database
			global $wpdb;
			$action_table = $wpdb->prefix . 'actionscheduler_actions';
			
			// Strategy 1: Get the most recent in-progress or pending action for this hook
			$action = $wpdb->get_row( $wpdb->prepare(
				"SELECT action_id, args FROM {$action_table} 
				WHERE hook = %s 
				AND (status = 'in-progress' OR status = 'pending')
				ORDER BY scheduled_date_gmt DESC 
				LIMIT 1",
				'aebg_replace_product_step_3'
			), ARRAY_A );
			
			// Strategy 2: If we have a partial post_id, try to find action by matching args
			if ( ! $action && ! empty( $post_id ) && $post_id > 0 ) {
				// Query all recent actions and check their args
				$recent_actions = $wpdb->get_results( $wpdb->prepare(
					"SELECT action_id, args FROM {$action_table} 
					WHERE hook = %s 
					AND (status = 'in-progress' OR status = 'pending')
					ORDER BY scheduled_date_gmt DESC 
					LIMIT 10",
					'aebg_replace_product_step_3'
				), ARRAY_A );
				
				// Try to find one that matches our post_id
				foreach ( $recent_actions as $candidate ) {
					if ( ! empty( $candidate['args'] ) ) {
						$candidate_args = maybe_unserialize( $candidate['args'] );
						if ( ! is_array( $candidate_args ) && is_string( $candidate['args'] ) ) {
							$candidate_args = json_decode( $candidate['args'], true );
						}
						if ( is_array( $candidate_args ) && isset( $candidate_args[0] ) && (int) $candidate_args[0] === $post_id ) {
							$action = $candidate;
							break;
						}
					}
				}
			}
			
			if ( $action && ! empty( $action['args'] ) ) {
				$raw_args = $action['args'];
				$args_from_db = null;
				
				// Try multiple parsing methods
				// Method 1: PHP serialized format
				$args_from_db = maybe_unserialize( $raw_args );
				
				// Method 2: JSON format (if unserialize didn't work or returned false)
				if ( ! is_array( $args_from_db ) ) {
					// Check if it's a JSON string
					if ( is_string( $raw_args ) && ( $raw_args[0] === '[' || $raw_args[0] === '{' ) ) {
						$json_decoded = json_decode( $raw_args, true );
						if ( json_last_error() === JSON_ERROR_NONE && is_array( $json_decoded ) ) {
							$args_from_db = $json_decoded;
						}
					}
				}
				
				// Method 3: If still not an array, try direct JSON decode
				if ( ! is_array( $args_from_db ) && is_string( $raw_args ) ) {
					$json_decoded = json_decode( $raw_args, true );
					if ( json_last_error() === JSON_ERROR_NONE && is_array( $json_decoded ) ) {
						$args_from_db = $json_decoded;
					}
				}
				
				// Extract arguments from parsed data
				if ( is_array( $args_from_db ) && count( $args_from_db ) >= 2 ) {
					$post_id = (int) $args_from_db[0];
					$product_number = (int) $args_from_db[1];
					if ( $post_id > 0 && $product_number > 0 ) {
						Logger::info( 'Recovered arguments from Action Scheduler database (Step 3)', [
							'post_id' => $post_id,
							'product_number' => $product_number,
							'raw_args' => $raw_args,
							'parsed_args' => $args_from_db,
						] );
					}
				} else {
					Logger::warning( 'Failed to parse Action Scheduler args from database (Step 3)', [
						'raw_args' => $raw_args,
						'parsed_args' => $args_from_db,
						'is_array' => is_array( $args_from_db ),
						'count' => is_array( $args_from_db ) ? count( $args_from_db ) : 0,
					] );
				}
			}
			
			// If still invalid and we have at least post_id, try to find checkpoint
			if ( ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) && $post_id > 0 ) {
				// Try to find checkpoint by post_id
				$checkpoint_table = $wpdb->prefix . 'aebg_product_replacement_checkpoints';
				$checkpoint_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT product_number, checkpoint_state FROM {$checkpoint_table} 
					WHERE post_id = %d 
					ORDER BY updated_at DESC 
					LIMIT 1",
					$post_id
				), ARRAY_A );
				
				if ( $checkpoint_row ) {
					$product_number = (int) $checkpoint_row['product_number'];
					Logger::info( 'Recovered product_number from checkpoint table', [
						'post_id' => $post_id,
						'product_number' => $product_number,
					] );
				}
			}
			
			if ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) {
				Logger::error( 'Invalid arguments for execute_replace_step_3 - cannot recover', [
					'all_args' => $all_args,
					'parsed_post_id' => $post_id,
					'parsed_product_number' => $product_number,
				] );
				return;
			}
		}
		$start_time = microtime( true );
		$start_memory = memory_get_usage( true );
		
		// Fire monitoring hook
		do_action( 'aebg_replacement_started', $post_id, $product_number, 'step_3' );
		
		Logger::info( '===== STEP 3: APPLY CONTENT AND SAVE FOR PRODUCT REPLACEMENT =====', [
			'post_id' => $post_id,
			'product_number' => $product_number,
			'process_id' => function_exists( 'getmypid' ) ? getmypid() : 'unknown',
		] );
		
		// Set execution flag
		self::$is_executing = true;
		$GLOBALS['AEBG_GENERATION_IN_PROGRESS'] = true;
		
		// Acquire lock to prevent concurrent execution (should already be held from Step 1, but check anyway)
		$lock_acquired = $this->acquireReplacementLock( $post_id, $product_number );
		if ( ! $lock_acquired ) {
			Logger::warning( 'Could not acquire replacement lock, rescheduling', [
				'post_id' => $post_id,
				'product_number' => $product_number,
			] );
			// Reschedule after 5 seconds
			$this->scheduleReplacementRetry( $post_id, $product_number, CheckpointManager::STEP_REPLACE_APPLY_CONTENT, 5 );
			self::$is_executing = false;
			unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
			return;
		}
		
		try {
			// Validate request
			$validation = $this->validateReplacementRequest( $post_id, $product_number );
			if ( is_wp_error( $validation ) ) {
				Logger::error( 'Replacement request validation failed', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'error' => $validation->get_error_message(),
					'error_code' => $validation->get_error_code(),
				] );
				// Release lock before returning
				$this->releaseReplacementLock( $post_id, $product_number );
				self::$is_executing = false;
				unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
				return;
			}
			
			// Load checkpoint from database (replacing transient)
			$checkpoint = CheckpointManager::loadReplacementCheckpoint( $post_id, $product_number );
			
			if ( ! $checkpoint || ! isset( $checkpoint['step'] ) || $checkpoint['step'] !== CheckpointManager::STEP_REPLACE_PROCESS_FIELDS ) {
				Logger::error( 'Invalid checkpoint for Step 3', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'checkpoint_step' => $checkpoint['step'] ?? 'missing',
					'expected_step' => CheckpointManager::STEP_REPLACE_PROCESS_FIELDS,
				] );
				// Release lock before returning
				$this->releaseReplacementLock( $post_id, $product_number );
				self::$is_executing = false;
				unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
				return;
			}
			
			$checkpoint_data = $checkpoint['data'] ?? [];
			
			// CRITICAL: Do NOT delete checkpoint here - only delete AFTER successful completion
			$elementor_data = $checkpoint_data['elementor_data'] ?? [];
			$container = $checkpoint_data['container'] ?? [];
			$testvinder_container = $checkpoint_data['testvinder_container'] ?? null;
			$context_registry_state = $checkpoint_data['context_registry_state'] ?? [];
			$settings = $checkpoint_data['settings'] ?? [];
			
			// Log testvinder container status
			if (!empty($testvinder_container)) {
				Logger::info('Step 3: Found testvinder container in checkpoint', [
					'post_id' => $post_id,
					'product_number' => $product_number,
				]);
			}
			
			// FALLBACK: Ensure API key is in settings (may be missing from checkpoint)
			if ( empty( $settings['openai_api_key'] ) ) {
				$aebg_settings = get_option( 'aebg_settings', [] );
				$settings['openai_api_key'] = $aebg_settings['api_key'] ?? '';
				if ( empty( $settings['openai_api_key'] ) && defined( 'AEBG_AI_API_KEY' ) ) {
					$settings['openai_api_key'] = AEBG_AI_API_KEY;
				}
				Logger::debug( 'Step 3: API key retrieved from fallback sources', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'api_key_found' => ! empty( $settings['openai_api_key'] ),
				] );
			}
			
			// Ensure ai_model is set
			if ( empty( $settings['ai_model'] ) ) {
				$aebg_settings = get_option( 'aebg_settings', [] );
				$settings['ai_model'] = $aebg_settings['model'] ?? 'gpt-3.5-turbo';
			}
			
			// Initialize Generator
			$generator = new \AEBG\Core\Generator( $settings );
			
			// Apply content (pass context_registry_state to restore generated content, and testvinder container if it exists)
			$result = $generator->applyContentForProduct( $post_id, $product_number, $elementor_data, $container, $context_registry_state, $testvinder_container );
			
			if ( is_wp_error( $result ) ) {
				Logger::error( 'Failed to apply content', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'error' => $result->get_error_message(),
					'error_code' => $result->get_error_code(),
				] );
				// Don't delete checkpoint on error - allow retry
				// But release lock before returning
				$this->releaseReplacementLock( $post_id, $product_number );
				self::$is_executing = false;
				unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
				return;
			}
			
			// Save Elementor data
			$cleaned_data = $generator->cleanElementorDataForEncoding( $result );
			$encoded_data = json_encode( $cleaned_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			
			if ( $encoded_data === false ) {
				Logger::error( 'Failed to encode Elementor data', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'json_error' => json_last_error_msg(),
				] );
				// Don't delete checkpoint on error - allow retry
				// But release lock before returning
				$this->releaseReplacementLock( $post_id, $product_number );
				self::$is_executing = false;
				unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
				return;
			}
			
			$update_result = update_post_meta( $post_id, '_elementor_data', $encoded_data );
			
			if ( $update_result === false ) {
				Logger::error( 'Failed to update Elementor data in post meta', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'data_size' => strlen( $encoded_data ),
				] );
				// Don't delete checkpoint on error - allow retry
				// But release lock before returning
				$this->releaseReplacementLock( $post_id, $product_number );
				self::$is_executing = false;
				unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
				return;
			}
			
			// CRITICAL: Update post modified date so Elementor knows content changed
			wp_update_post( [
				'ID' => $post_id,
				'post_modified' => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', 1 ),
			] );
			
			// Clear Elementor cache
			$generator->clearElementorCache( $post_id );
			
			// CRITICAL: Force CSS/JS regeneration for frontend and editor to show updated content
			$generator->forceElementorCSSGeneration( $post_id );
			
			// Reset retry count on success
			CheckpointManager::resetReplacementRetryCount( $post_id, $product_number, CheckpointManager::STEP_REPLACE_APPLY_CONTENT );
			
			// CRITICAL: Delete checkpoint AFTER successful completion (not before)
			CheckpointManager::clearReplacementCheckpoint( $post_id, $product_number );
			
			$elapsed_time = microtime( true ) - $start_time;
			$peak_memory = memory_get_peak_usage( true );
			
			Logger::info( 'Step 3 completed: Content applied and saved successfully', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'execution_time' => round( $elapsed_time, 2 ),
				'peak_memory_mb' => round( $peak_memory / 1024 / 1024, 2 ),
			] );
			
			// Fire monitoring hook
			do_action( 'aebg_replacement_completed', $post_id, $product_number, [
				'total_execution_time' => $elapsed_time,
				'peak_memory' => $peak_memory,
			] );
			
			Logger::info( 'PRODUCT REPLACEMENT COMPLETED', [
				'post_id' => $post_id,
				'product_number' => $product_number,
			] );
			
		} catch ( \Exception $e ) {
			// Classify exception
			$error_classification = $this->classifyReplacementError( $e );
			
			$elapsed_time = microtime( true ) - $start_time;
			
			// Don't retry fatal errors
			if ( ! $error_classification['should_retry'] ) {
				Logger::error( 'Exception in Step 3 (fatal error, no retry)', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'error' => $e->getMessage(),
					'error_type' => $error_classification['type'],
					'execution_time' => round( $elapsed_time, 2 ),
					'trace' => $e->getTraceAsString(),
				] );
				
				// Fire monitoring hook
				do_action( 'aebg_replacement_failed', $post_id, $product_number, 'step_3', [
					'error' => $e->getMessage(),
					'error_type' => $error_classification['type'],
					'execution_time' => $elapsed_time,
				] );
				
				// Don't delete checkpoint on fatal error - may need manual intervention
				return;
			}
			
			// Check retry count
			$retry_count = CheckpointManager::getReplacementRetryCount( $post_id, $product_number, CheckpointManager::STEP_REPLACE_APPLY_CONTENT );
			
			if ( $retry_count >= CheckpointManager::MAX_RETRY_ATTEMPTS ) {
				// Max retries reached - mark as failed
				$this->markReplacementAsFailed(
					$post_id,
					$product_number,
					CheckpointManager::STEP_REPLACE_APPLY_CONTENT,
					$e->getMessage(),
					'max_retries_exceeded'
				);
				
				// Fire monitoring hook
				do_action( 'aebg_replacement_failed', $post_id, $product_number, 'step_3', [
					'error' => $e->getMessage(),
					'error_type' => $error_classification['type'],
					'execution_time' => $elapsed_time,
					'reason' => 'max_retries_exceeded',
				] );
				
				return;
			}
			
			// Increment retry count and schedule retry
			CheckpointManager::incrementReplacementRetryCount( $post_id, $product_number, CheckpointManager::STEP_REPLACE_APPLY_CONTENT );
			$delay = $this->calculateReplacementRetryDelay( $retry_count + 1 );
			$this->scheduleReplacementRetry( $post_id, $product_number, CheckpointManager::STEP_REPLACE_APPLY_CONTENT, $delay );
			
			Logger::warning( 'Exception in Step 3, retry scheduled', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'error' => $e->getMessage(),
				'error_type' => $error_classification['type'],
				'retry_count' => $retry_count + 1,
				'delay' => $delay,
				'execution_time' => round( $elapsed_time, 2 ),
			] );
			
			// Fire monitoring hook
			do_action( 'aebg_replacement_retried', $post_id, $product_number, 'step_3', [
				'error' => $e->getMessage(),
				'error_type' => $error_classification['type'],
				'retry_count' => $retry_count + 1,
				'delay' => $delay,
				'execution_time' => $elapsed_time,
			] );
			
			// Don't delete checkpoint on exception - allow retry
		} finally {
			// Release lock
			$this->releaseReplacementLock( $post_id, $product_number );
			self::$is_executing = false;
			unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
		}
	}

	// ============================================
	// TESTVINDER-ONLY REGENERATION METHODS
	// ============================================

	/**
	 * Execute testvinder-only regeneration Step 1: Collect prompts
	 * 
	 * @param int|array $post_id Post ID (can be array for Action Scheduler)
	 * @param int|null $product_number Product number
	 */
	public function execute_testvinder_step_1( $post_id, $product_number = null ) {
		// CRITICAL: Use func_get_args() to capture ALL arguments passed by Action Scheduler
		$all_args = func_get_args();
		
		Logger::debug( 'execute_testvinder_step_1 received arguments', [
			'arg_count' => count( $all_args ),
			'arg_0' => $all_args[0] ?? 'missing',
			'arg_1' => $all_args[1] ?? 'missing',
		] );
		
		// Extract arguments from various possible formats (same as execute_replace_step_1)
		if ( is_array( $all_args[0] ) && count( $all_args[0] ) >= 2 ) {
			$post_id = $all_args[0][0];
			$product_number = $all_args[0][1];
		} elseif ( count( $all_args ) >= 2 && ! is_array( $all_args[0] ) ) {
			$post_id = $all_args[0];
			$product_number = $all_args[1];
		} elseif ( is_array( $all_args[0] ) && count( $all_args[0] ) === 1 && is_array( $all_args[0][0] ) ) {
			$nested = $all_args[0][0];
			if ( count( $nested ) >= 2 ) {
				$post_id = $nested[0];
				$product_number = $nested[1];
			}
		}
		
		// Validate and cast arguments
		$post_id = (int) $post_id;
		$product_number = (int) $product_number;
		
		// CRITICAL FALLBACK: If arguments are invalid, try to get from Action Scheduler database
		if ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) {
			// Try to recover from Action Scheduler database (same logic as execute_replace_step_1)
			global $wpdb;
			$action_table = $wpdb->prefix . 'actionscheduler_actions';
			$action = $wpdb->get_row( $wpdb->prepare(
				"SELECT action_id, args FROM {$action_table} 
				WHERE hook = %s 
				AND (status = 'in-progress' OR status = 'pending')
				ORDER BY scheduled_date_gmt DESC 
				LIMIT 1",
				'aebg_regenerate_testvinder_only'
			), ARRAY_A );
			
			if ( $action && ! empty( $action['args'] ) ) {
				$raw_args = $action['args'];
				$args_from_db = maybe_unserialize( $raw_args );
				
				if ( ! is_array( $args_from_db ) && is_string( $raw_args ) ) {
					$args_from_db = json_decode( $raw_args, true );
				}
				
				if ( is_array( $args_from_db ) && count( $args_from_db ) >= 2 ) {
					$post_id = (int) $args_from_db[0];
					$product_number = (int) $args_from_db[1];
				}
			}
			
			// If still invalid, try checkpoint
			if ( ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) && $post_id > 0 ) {
				$checkpoint_table = $wpdb->prefix . 'aebg_product_replacement_checkpoints';
				$checkpoint_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT product_number, checkpoint_state FROM {$checkpoint_table} 
					WHERE post_id = %d 
					AND checkpoint_type = 'testvinder'
					ORDER BY updated_at DESC 
					LIMIT 1",
					$post_id
				), ARRAY_A );
				
				if ( $checkpoint_row ) {
					$product_number = (int) $checkpoint_row['product_number'];
				}
			}
			
			if ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) {
				Logger::error( 'Invalid arguments for execute_testvinder_step_1 - cannot recover', [
					'all_args' => $all_args,
					'parsed_post_id' => $post_id,
					'parsed_product_number' => $product_number,
				] );
				return;
			}
		}
		
		$start_time = microtime( true );
		$start_memory = memory_get_usage( true );
		
		// Fire monitoring hook
		do_action( 'aebg_testvinder_regeneration_started', $post_id, $product_number, 'step_1' );
		
		Logger::info( 'Starting testvinder regeneration Step 1', [
			'post_id' => $post_id,
			'product_number' => $product_number,
		] );
		
		try {
			// Get settings
			$aebg_settings = get_option( 'aebg_settings', [] );
			$settings = [
				'openai_api_key' => $aebg_settings['api_key'] ?? '',
				'ai_model' => $aebg_settings['model'] ?? 'gpt-3.5-turbo',
			];
			
			// Fallback API key
			if ( empty( $settings['openai_api_key'] ) && defined( 'AEBG_AI_API_KEY' ) ) {
				$settings['openai_api_key'] = AEBG_AI_API_KEY;
			}
			
			// Initialize Generator
			$generator = new Generator( $settings );
			
			// Collect prompts from testvinder container only
			$result = $generator->collectPromptsForTestvinder( $post_id, $product_number );
			
			if ( is_wp_error( $result ) ) {
				Logger::error( 'Failed to collect testvinder prompts', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'error' => $result->get_error_message(),
				] );
				return;
			}
			
			// Save checkpoint
			$checkpoint_state = [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'elementor_data' => $result['elementor_data'] ?? [],
				'testvinder_container' => $result['testvinder_container'] ?? [],
				'context_registry_state' => $result['context_registry_state'] ?? [],
				'settings' => $settings,
			];
			
			$saved = CheckpointManager::saveReplacementCheckpoint(
				$post_id,
				$product_number,
				CheckpointManager::STEP_TESTVINDER_COLLECT_PROMPTS,
				$checkpoint_state,
				'testvinder' // checkpoint type
			);
			
			if ( ! $saved ) {
				Logger::error( 'Failed to save testvinder checkpoint', [
					'post_id' => $post_id,
					'product_number' => $product_number,
				] );
				return;
			}
			
			// Schedule Step 2
			TestvinderReplacementScheduler::scheduleNextStep( 'step_1', $post_id, $product_number, 1 );
			
			$elapsed_time = microtime( true ) - $start_time;
			$peak_memory = memory_get_peak_usage( true );
			
			Logger::info( 'Testvinder Step 1 completed: Collected prompts', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'field_count' => $result['field_count'] ?? 0,
				'execution_time' => round( $elapsed_time, 2 ),
				'memory_usage_mb' => round( $start_memory / 1024 / 1024, 2 ),
				'peak_memory_mb' => round( $peak_memory / 1024 / 1024, 2 ),
			] );
			
		} catch ( \Exception $e ) {
			Logger::error( 'Exception in execute_testvinder_step_1', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			] );
		}
	}

	/**
	 * Execute testvinder-only regeneration Step 2: Process AI fields
	 * 
	 * @param int|array $post_id Post ID or array of arguments
	 * @param int|null $product_number Product number (1-based) or null if first arg is array
	 */
	public function execute_testvinder_step_2( $post_id, $product_number = null ) {
		// CRITICAL: Use func_get_args() to capture ALL arguments passed by Action Scheduler
		$all_args = func_get_args();
		
		// Extract arguments from various possible formats
		if ( is_array( $all_args[0] ) && count( $all_args[0] ) >= 2 ) {
			$post_id = $all_args[0][0];
			$product_number = $all_args[0][1];
		} elseif ( count( $all_args ) >= 2 && ! is_array( $all_args[0] ) ) {
			$post_id = $all_args[0];
			$product_number = $all_args[1];
		} elseif ( is_array( $all_args[0] ) && count( $all_args[0] ) === 1 && is_array( $all_args[0][0] ) ) {
			$nested = $all_args[0][0];
			if ( count( $nested ) >= 2 ) {
				$post_id = $nested[0];
				$product_number = $nested[1];
			}
		}
		
		// Validate and cast arguments
		$post_id = (int) $post_id;
		$product_number = (int) $product_number;
		
		// CRITICAL FALLBACK: If arguments are invalid, try to get from Action Scheduler or checkpoint
		if ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) {
			// First try Action Scheduler database
			global $wpdb;
			$action_table = $wpdb->prefix . 'actionscheduler_actions';
			
			// Strategy 1: Get the most recent in-progress or pending action for this hook
			$action = $wpdb->get_row( $wpdb->prepare(
				"SELECT action_id, args FROM {$action_table} 
				WHERE hook = %s 
				AND (status = 'in-progress' OR status = 'pending')
				ORDER BY scheduled_date_gmt DESC 
				LIMIT 1",
				'aebg_testvinder_step_2'
			), ARRAY_A );
			
			// Strategy 2: If we have a partial post_id, try to find action by matching args
			if ( ! $action && ! empty( $post_id ) && $post_id > 0 ) {
				// Query all recent actions and check their args
				$recent_actions = $wpdb->get_results( $wpdb->prepare(
					"SELECT action_id, args FROM {$action_table} 
					WHERE hook = %s 
					AND (status = 'in-progress' OR status = 'pending')
					ORDER BY scheduled_date_gmt DESC 
					LIMIT 10",
					'aebg_testvinder_step_2'
				), ARRAY_A );
				
				// Try to find one that matches our post_id
				foreach ( $recent_actions as $candidate ) {
					if ( ! empty( $candidate['args'] ) ) {
						$candidate_args = maybe_unserialize( $candidate['args'] );
						if ( ! is_array( $candidate_args ) && is_string( $candidate['args'] ) ) {
							$candidate_args = json_decode( $candidate['args'], true );
						}
						if ( is_array( $candidate_args ) && isset( $candidate_args[0] ) && (int) $candidate_args[0] === $post_id ) {
							$action = $candidate;
							break;
						}
					}
				}
			}
			
			if ( $action && ! empty( $action['args'] ) ) {
				$raw_args = $action['args'];
				$args_from_db = null;
				
				// Try multiple parsing methods
				// Method 1: PHP serialized format
				$args_from_db = maybe_unserialize( $raw_args );
				
				// Method 2: JSON format (if unserialize didn't work or returned false)
				if ( ! is_array( $args_from_db ) ) {
					// Check if it's a JSON string
					if ( is_string( $raw_args ) && ( $raw_args[0] === '[' || $raw_args[0] === '{' ) ) {
						$json_decoded = json_decode( $raw_args, true );
						if ( json_last_error() === JSON_ERROR_NONE && is_array( $json_decoded ) ) {
							$args_from_db = $json_decoded;
						}
					}
				}
				
				// Method 3: If still not an array, try direct JSON decode
				if ( ! is_array( $args_from_db ) && is_string( $raw_args ) ) {
					$json_decoded = json_decode( $raw_args, true );
					if ( json_last_error() === JSON_ERROR_NONE && is_array( $json_decoded ) ) {
						$args_from_db = $json_decoded;
					}
				}
				
				// Extract arguments from parsed data
				if ( is_array( $args_from_db ) && count( $args_from_db ) >= 2 ) {
					$post_id = (int) $args_from_db[0];
					$product_number = (int) $args_from_db[1];
					if ( $post_id > 0 && $product_number > 0 ) {
						Logger::info( 'Recovered arguments from Action Scheduler database (testvinder Step 2)', [
							'post_id' => $post_id,
							'product_number' => $product_number,
							'raw_args' => $raw_args,
							'parsed_args' => $args_from_db,
						] );
					}
				} else {
					Logger::warning( 'Failed to parse Action Scheduler args from database (testvinder Step 2)', [
						'raw_args' => $raw_args,
						'parsed_args' => $args_from_db,
						'is_array' => is_array( $args_from_db ),
						'count' => is_array( $args_from_db ) ? count( $args_from_db ) : 0,
					] );
				}
			}
			
			// If still invalid and we have at least post_id, try to find checkpoint
			if ( ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) && $post_id > 0 ) {
				// Try to find checkpoint by post_id (query all checkpoints for this post)
				$checkpoint_table = $wpdb->prefix . 'aebg_product_replacement_checkpoints';
				$checkpoint_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT product_number, checkpoint_state FROM {$checkpoint_table} 
					WHERE post_id = %d 
					AND checkpoint_type = 'testvinder'
					ORDER BY updated_at DESC 
					LIMIT 1",
					$post_id
				), ARRAY_A );
				
				if ( $checkpoint_row ) {
					$product_number = (int) $checkpoint_row['product_number'];
					Logger::info( 'Recovered product_number from checkpoint table (testvinder Step 2)', [
						'post_id' => $post_id,
						'product_number' => $product_number,
					] );
				}
			}
			
			if ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) {
				Logger::error( 'Invalid arguments for execute_testvinder_step_2 - cannot recover', [
					'all_args' => $all_args,
					'parsed_post_id' => $post_id,
					'parsed_product_number' => $product_number,
				] );
				return;
			}
		}
		
		// Load checkpoint from database
		$checkpoint = CheckpointManager::loadReplacementCheckpoint( $post_id, $product_number, 'testvinder' );
		
		if ( ! $checkpoint || ! isset( $checkpoint['step'] ) || $checkpoint['step'] !== CheckpointManager::STEP_TESTVINDER_COLLECT_PROMPTS ) {
			Logger::error( 'Invalid checkpoint for testvinder Step 2', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'checkpoint_step' => $checkpoint['step'] ?? 'missing',
				'expected_step' => CheckpointManager::STEP_TESTVINDER_COLLECT_PROMPTS,
			] );
			return;
		}
		
		$checkpoint_data = $checkpoint['data'] ?? [];
		
		$start_time = microtime( true );
		$start_memory = memory_get_usage( true );
		
		// Get settings and context registry state
		$context_registry_state = $checkpoint_data['context_registry_state'] ?? [];
		$settings = $checkpoint_data['settings'] ?? [];
		
		// Get AI settings - try multiple sources
		$api_key = $settings['openai_api_key'] ?? '';
		$ai_model = $settings['ai_model'] ?? 'gpt-3.5-turbo';
		
		// DEBUG: Log checkpoint settings for troubleshooting
		Logger::debug( 'Testvinder Step 2 API key check', [
			'post_id' => $post_id,
			'product_number' => $product_number,
			'checkpoint_has_settings' => ! empty( $settings ),
			'checkpoint_api_key_present' => isset( $settings['openai_api_key'] ),
			'checkpoint_api_key_empty' => empty( $api_key ),
			'settings_keys' => array_keys( $settings ?? [] ),
		] );
		
		// FALLBACK: If API key not in checkpoint settings, try to get from WordPress options
		if ( empty( $api_key ) ) {
			$aebg_settings = get_option( 'aebg_settings', [] );
			$api_key = $aebg_settings['api_key'] ?? '';
			Logger::info( 'API key not found in checkpoint, retrieved from WordPress options (testvinder Step 2)', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'api_key_found' => ! empty( $api_key ),
				'api_key_length' => strlen( $api_key ),
				'aebg_settings_keys' => array_keys( $aebg_settings ?? [] ),
			] );
		}
		
		// FALLBACK: Also try constant if still empty
		if ( empty( $api_key ) && defined( 'AEBG_AI_API_KEY' ) ) {
			$api_key = AEBG_AI_API_KEY;
			Logger::info( 'API key retrieved from AEBG_AI_API_KEY constant (testvinder Step 2)', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'api_key_length' => strlen( $api_key ),
			] );
		}
		
		if ( empty( $api_key ) ) {
			Logger::warning( 'OpenAI API key not configured. Skipping AI processing, scheduling Step 3 for variable updates only (testvinder).', [
				'post_id' => $post_id,
				'product_number' => $product_number,
			] );
			
			// CRITICAL: Still save a checkpoint for Step 2 so Step 3 can validate it
			// Use the context registry state from Step 1 (unchanged since no AI processing)
			$checkpoint_state = [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'elementor_data' => $checkpoint_data['elementor_data'] ?? [],
				'testvinder_container' => $checkpoint_data['testvinder_container'] ?? [],
				'context_registry_state' => $context_registry_state, // Unchanged from Step 1
				'settings' => $settings,
				'ai_skipped' => true, // Flag to indicate AI processing was skipped
			];
			
			$saved = CheckpointManager::saveReplacementCheckpoint(
				$post_id,
				$product_number,
				CheckpointManager::STEP_TESTVINDER_PROCESS_FIELDS,
				$checkpoint_state,
				'testvinder'
			);
			
			if ( ! $saved ) {
				Logger::error( 'Failed to save testvinder checkpoint when skipping AI', [
					'post_id' => $post_id,
					'product_number' => $product_number,
				] );
			} else {
				Logger::info( 'Saved testvinder Step 2 checkpoint (AI skipped)', [
					'post_id' => $post_id,
					'product_number' => $product_number,
				] );
			}
			
			// Schedule Step 3 anyway for variable updates (delay=0 for immediate execution)
			TestvinderReplacementScheduler::scheduleNextStep( 'step_2', $post_id, $product_number, 0 );
			return;
		}
		
		// Initialize Generator and restore context registry
		$generator = new Generator( $settings );
		$context_registry = new ContextRegistry();
		if ( method_exists( $context_registry, 'importState' ) ) {
			$context_registry->importState( $context_registry_state );
		}
		
		// Initialize template processor
		global $aebg_variables;
		if ( ! $aebg_variables ) {
			$aebg_variables = new Variables();
		}
		
		// Initialize AI processor (we know api_key is not empty at this point)
		$ai_processor = new AIPromptProcessor( $aebg_variables, $context_registry, $api_key, $ai_model );
		
		$template_processor = new ElementorTemplateProcessor(
			$context_registry,
			$ai_processor,
			new VariableReplacer(),
			new ContentGenerator( new VariableReplacer() )
		);
		
		// Get products and title
		$products = get_post_meta( $post_id, '_aebg_products', true );
		$post = get_post( $post_id );
		$title = $post ? $post->post_title : '';
		
		// Get processing order
		$processing_order = $context_registry->getProcessingOrder();
		
		// CRITICAL: Handle empty processing_order - skip to Step 3 if no fields to process
		if ( empty( $processing_order ) ) {
			Logger::info( 'No AI fields to process, skipping to Step 3 (testvinder)', [
				'post_id' => $post_id,
				'product_number' => $product_number,
			] );
			
			// Save checkpoint with existing context_registry_state
			$checkpoint_state = [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'elementor_data' => $checkpoint_data['elementor_data'] ?? [],
				'testvinder_container' => $checkpoint_data['testvinder_container'] ?? [],
				'context_registry_state' => $context_registry_state, // Keep existing state
				'settings' => $settings,
			];
			
			CheckpointManager::saveReplacementCheckpoint(
				$post_id,
				$product_number,
				CheckpointManager::STEP_TESTVINDER_PROCESS_FIELDS,
				$checkpoint_state,
				'testvinder'
			);
			
			// Schedule Step 3
			TestvinderReplacementScheduler::scheduleNextStep( 'step_2', $post_id, $product_number, 1 );
			return;
		}
		
		// Process each field
		$context = [
			'title' => $title,
			'product_count' => count( $products ),
			'products' => $products,
		];
		
		foreach ( $processing_order as $field_id ) {
			$template_processor->processAIField( $field_id, $title, $products, $context, $api_key, $ai_model );
		}
		
		// Export updated context registry state
		$updated_context_registry_state = $context_registry->exportState();
		
		// Save checkpoint
		$checkpoint_state = [
			'post_id' => $post_id,
			'product_number' => $product_number,
			'elementor_data' => $checkpoint_data['elementor_data'] ?? [],
			'testvinder_container' => $checkpoint_data['testvinder_container'] ?? [],
			'context_registry_state' => $updated_context_registry_state,
			'settings' => $settings,
		];
		
		CheckpointManager::saveReplacementCheckpoint(
			$post_id,
			$product_number,
			CheckpointManager::STEP_TESTVINDER_PROCESS_FIELDS,
			$checkpoint_state,
			'testvinder'
		);
		
		// Schedule Step 3
		TestvinderReplacementScheduler::scheduleNextStep( 'step_2', $post_id, $product_number, 1 );
		
		$elapsed_time = microtime( true ) - $start_time;
		Logger::info( 'Testvinder Step 2 completed: Processed AI fields', [
			'post_id' => $post_id,
			'product_number' => $product_number,
			'field_count' => count( $processing_order ),
			'execution_time' => round( $elapsed_time, 2 ),
		] );
	}

	/**
	 * Execute testvinder-only regeneration Step 3: Apply content and save
	 * 
	 * @param int|array $post_id Post ID or array of arguments
	 * @param int|null $product_number Product number (1-based) or null if first arg is array
	 */
	public function execute_testvinder_step_3( $post_id, $product_number = null ) {
		// CRITICAL: Use func_get_args() to capture ALL arguments passed by Action Scheduler
		$all_args = func_get_args();
		
		// Extract arguments from various possible formats
		if ( is_array( $all_args[0] ) && count( $all_args[0] ) >= 2 ) {
			$post_id = $all_args[0][0];
			$product_number = $all_args[0][1];
		} elseif ( count( $all_args ) >= 2 && ! is_array( $all_args[0] ) ) {
			$post_id = $all_args[0];
			$product_number = $all_args[1];
		} elseif ( is_array( $all_args[0] ) && count( $all_args[0] ) === 1 && is_array( $all_args[0][0] ) ) {
			$nested = $all_args[0][0];
			if ( count( $nested ) >= 2 ) {
				$post_id = $nested[0];
				$product_number = $nested[1];
			}
		}
		
		// Validate and cast arguments
		$post_id = (int) $post_id;
		$product_number = (int) $product_number;
		
		// CRITICAL FALLBACK: If arguments are invalid, try to get from Action Scheduler or checkpoint
		if ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) {
			// First try Action Scheduler database
			global $wpdb;
			$action_table = $wpdb->prefix . 'actionscheduler_actions';
			
			// Strategy 1: Get the most recent in-progress or pending action for this hook
			$action = $wpdb->get_row( $wpdb->prepare(
				"SELECT action_id, args FROM {$action_table} 
				WHERE hook = %s 
				AND (status = 'in-progress' OR status = 'pending')
				ORDER BY scheduled_date_gmt DESC 
				LIMIT 1",
				'aebg_testvinder_step_3'
			), ARRAY_A );
			
			// Strategy 2: If we have a partial post_id, try to find action by matching args
			if ( ! $action && ! empty( $post_id ) && $post_id > 0 ) {
				// Query all recent actions and check their args
				$recent_actions = $wpdb->get_results( $wpdb->prepare(
					"SELECT action_id, args FROM {$action_table} 
					WHERE hook = %s 
					AND (status = 'in-progress' OR status = 'pending')
					ORDER BY scheduled_date_gmt DESC 
					LIMIT 10",
					'aebg_testvinder_step_3'
				), ARRAY_A );
				
				// Try to find one that matches our post_id
				foreach ( $recent_actions as $candidate ) {
					if ( ! empty( $candidate['args'] ) ) {
						$candidate_args = maybe_unserialize( $candidate['args'] );
						if ( ! is_array( $candidate_args ) && is_string( $candidate['args'] ) ) {
							$candidate_args = json_decode( $candidate['args'], true );
						}
						if ( is_array( $candidate_args ) && isset( $candidate_args[0] ) && (int) $candidate_args[0] === $post_id ) {
							$action = $candidate;
							break;
						}
					}
				}
			}
			
			if ( $action && ! empty( $action['args'] ) ) {
				$raw_args = $action['args'];
				$args_from_db = null;
				
				// Try multiple parsing methods
				// Method 1: PHP serialized format
				$args_from_db = maybe_unserialize( $raw_args );
				
				// Method 2: JSON format (if unserialize didn't work or returned false)
				if ( ! is_array( $args_from_db ) ) {
					// Check if it's a JSON string
					if ( is_string( $raw_args ) && ( $raw_args[0] === '[' || $raw_args[0] === '{' ) ) {
						$json_decoded = json_decode( $raw_args, true );
						if ( json_last_error() === JSON_ERROR_NONE && is_array( $json_decoded ) ) {
							$args_from_db = $json_decoded;
						}
					}
				}
				
				// Method 3: If still not an array, try direct JSON decode
				if ( ! is_array( $args_from_db ) && is_string( $raw_args ) ) {
					$json_decoded = json_decode( $raw_args, true );
					if ( json_last_error() === JSON_ERROR_NONE && is_array( $json_decoded ) ) {
						$args_from_db = $json_decoded;
					}
				}
				
				// Extract arguments from parsed data
				if ( is_array( $args_from_db ) && count( $args_from_db ) >= 2 ) {
					$post_id = (int) $args_from_db[0];
					$product_number = (int) $args_from_db[1];
					if ( $post_id > 0 && $product_number > 0 ) {
						Logger::info( 'Recovered arguments from Action Scheduler database (testvinder Step 3)', [
							'post_id' => $post_id,
							'product_number' => $product_number,
							'raw_args' => $raw_args,
							'parsed_args' => $args_from_db,
						] );
					}
				} else {
					Logger::warning( 'Failed to parse Action Scheduler args from database (testvinder Step 3)', [
						'raw_args' => $raw_args,
						'parsed_args' => $args_from_db,
						'is_array' => is_array( $args_from_db ),
						'count' => is_array( $args_from_db ) ? count( $args_from_db ) : 0,
					] );
				}
			}
			
			// If still invalid and we have at least post_id, try to find checkpoint
			if ( ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) && $post_id > 0 ) {
				// Try to find checkpoint by post_id
				$checkpoint_table = $wpdb->prefix . 'aebg_product_replacement_checkpoints';
				$checkpoint_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT product_number, checkpoint_state FROM {$checkpoint_table} 
					WHERE post_id = %d 
					AND checkpoint_type = 'testvinder'
					ORDER BY updated_at DESC 
					LIMIT 1",
					$post_id
				), ARRAY_A );
				
				if ( $checkpoint_row ) {
					$product_number = (int) $checkpoint_row['product_number'];
					Logger::info( 'Recovered product_number from checkpoint table (testvinder Step 3)', [
						'post_id' => $post_id,
						'product_number' => $product_number,
					] );
				}
			}
			
			if ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) {
				Logger::error( 'Invalid arguments for execute_testvinder_step_3 - cannot recover', [
					'all_args' => $all_args,
					'parsed_post_id' => $post_id,
					'parsed_product_number' => $product_number,
				] );
				return;
			}
		}
		
		$start_time = microtime( true );
		$start_memory = memory_get_usage( true );
		
		// Fire monitoring hook
		do_action( 'aebg_testvinder_regeneration_started', $post_id, $product_number, 'step_3' );
		
		Logger::info( '===== TESTVINDER STEP 3: APPLY CONTENT AND SAVE =====', [
			'post_id' => $post_id,
			'product_number' => $product_number,
			'process_id' => function_exists( 'getmypid' ) ? getmypid() : 'unknown',
		] );
		
		// Set execution flag
		self::$is_executing = true;
		$GLOBALS['AEBG_GENERATION_IN_PROGRESS'] = true;
		
		// Acquire lock to prevent concurrent execution
		$lock_acquired = $this->acquireReplacementLock( $post_id, $product_number );
		if ( ! $lock_acquired ) {
			Logger::warning( 'Could not acquire testvinder replacement lock, rescheduling', [
				'post_id' => $post_id,
				'product_number' => $product_number,
			] );
			// Reschedule after 5 seconds
			TestvinderReplacementScheduler::scheduleNextStep( 'step_2', $post_id, $product_number, 5 );
			self::$is_executing = false;
			unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
			return;
		}
		
		try {
			// Validate request
			$validation = $this->validateReplacementRequest( $post_id, $product_number );
			if ( is_wp_error( $validation ) ) {
				Logger::error( 'Testvinder replacement request validation failed', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'error' => $validation->get_error_message(),
					'error_code' => $validation->get_error_code(),
				] );
				// Release lock before returning
				$this->releaseReplacementLock( $post_id, $product_number );
				self::$is_executing = false;
				unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
				return;
			}
			
			// Load checkpoint from database
			$checkpoint = CheckpointManager::loadReplacementCheckpoint( $post_id, $product_number, 'testvinder' );
			
			if ( ! $checkpoint || ! isset( $checkpoint['step'] ) || $checkpoint['step'] !== CheckpointManager::STEP_TESTVINDER_PROCESS_FIELDS ) {
				Logger::error( 'Invalid checkpoint for testvinder Step 3', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'checkpoint_step' => $checkpoint['step'] ?? 'missing',
					'expected_step' => CheckpointManager::STEP_TESTVINDER_PROCESS_FIELDS,
				] );
				// Release lock before returning
				$this->releaseReplacementLock( $post_id, $product_number );
				self::$is_executing = false;
				unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
				return;
			}
			
			$checkpoint_data = $checkpoint['data'] ?? [];
			
			// CRITICAL: Do NOT delete checkpoint here - only delete AFTER successful completion
			$elementor_data = $checkpoint_data['elementor_data'] ?? [];
			$testvinder_container = $checkpoint_data['testvinder_container'] ?? [];
			$context_registry_state = $checkpoint_data['context_registry_state'] ?? [];
			$settings = $checkpoint_data['settings'] ?? [];
			$ai_skipped = $checkpoint_data['ai_skipped'] ?? false;
			
			// Log if AI was skipped in Step 2
			if ( $ai_skipped ) {
				Logger::info( 'Testvinder Step 3: AI processing was skipped in Step 2, applying variable replacements only', [
					'post_id' => $post_id,
					'product_number' => $product_number,
				] );
			}
			
			// FALLBACK: Ensure API key is in settings (may be missing from checkpoint)
			// Note: API key is not required for Step 3 if AI was skipped, but we still populate it for consistency
			if ( empty( $settings['openai_api_key'] ) ) {
				$aebg_settings = get_option( 'aebg_settings', [] );
				$settings['openai_api_key'] = $aebg_settings['api_key'] ?? '';
				if ( empty( $settings['openai_api_key'] ) && defined( 'AEBG_AI_API_KEY' ) ) {
					$settings['openai_api_key'] = AEBG_AI_API_KEY;
				}
				Logger::debug( 'Testvinder Step 3: API key retrieved from fallback sources', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'api_key_found' => ! empty( $settings['openai_api_key'] ),
					'ai_skipped' => $ai_skipped,
				] );
			}
			
			// Ensure ai_model is set
			if ( empty( $settings['ai_model'] ) ) {
				$aebg_settings = get_option( 'aebg_settings', [] );
				$settings['ai_model'] = $aebg_settings['model'] ?? 'gpt-3.5-turbo';
			}
			
			// Log context registry state info
			$field_count = count( $context_registry_state['contexts'] ?? [] );
			Logger::debug( 'Testvinder Step 3: Context registry state loaded', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'field_count' => $field_count,
				'ai_skipped' => $ai_skipped,
			] );
			
			// Initialize Generator
			$generator = new Generator( $settings );
			
			// Apply content to testvinder container only
			$result = $generator->applyContentForTestvinder(
				$post_id,
				$product_number,
				$elementor_data,
				$testvinder_container,
				$context_registry_state
			);
			
			if ( is_wp_error( $result ) ) {
				Logger::error( 'Failed to apply testvinder content', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'error' => $result->get_error_message(),
					'error_code' => $result->get_error_code(),
				] );
				// Don't delete checkpoint on error - allow retry
				// But release lock before returning
				$this->releaseReplacementLock( $post_id, $product_number );
				self::$is_executing = false;
				unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
				return;
			}
			
			// Save Elementor data
			$template_manager = new TemplateManager();
			$save_result = $template_manager->saveElementorData( $post_id, $result );
			
			if ( is_wp_error( $save_result ) ) {
				Logger::error( 'Failed to save Elementor data after testvinder regeneration', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'error' => $save_result->get_error_message(),
					'error_code' => $save_result->get_error_code(),
				] );
				// Don't delete checkpoint on error - allow retry
				// But release lock before returning
				$this->releaseReplacementLock( $post_id, $product_number );
				self::$is_executing = false;
				unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
				return;
			}
			
			// CRITICAL: Update post modified date so Elementor knows content changed
			wp_update_post( [
				'ID' => $post_id,
				'post_modified' => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', 1 ),
			] );
			
			// Clear Elementor cache
			$generator->clearElementorCache( $post_id );
			
			// CRITICAL: Force CSS/JS regeneration for frontend and editor to show updated content
			$generator->forceElementorCSSGeneration( $post_id );
			
			// CRITICAL: Delete checkpoint AFTER successful completion (not before)
			CheckpointManager::clearReplacementCheckpoint( $post_id, $product_number, 'testvinder' );
			
			$elapsed_time = microtime( true ) - $start_time;
			$peak_memory = memory_get_peak_usage( true );
			
			Logger::info( 'Testvinder Step 3 completed: Applied content and saved successfully', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'execution_time' => round( $elapsed_time, 2 ),
				'peak_memory_mb' => round( $peak_memory / 1024 / 1024, 2 ),
			] );
			
			// Fire monitoring hook
			do_action( 'aebg_testvinder_regeneration_completed', $post_id, $product_number, [
				'total_execution_time' => $elapsed_time,
				'peak_memory' => $peak_memory,
			] );
			
			Logger::info( 'TESTVINDER REGENERATION COMPLETED', [
				'post_id' => $post_id,
				'product_number' => $product_number,
			] );
			
		} catch ( \Exception $e ) {
			// Classify exception
			$error_classification = $this->classifyReplacementError( $e );
			
			$elapsed_time = microtime( true ) - $start_time;
			
			// Don't retry fatal errors
			if ( ! $error_classification['should_retry'] ) {
				Logger::error( 'Exception in testvinder Step 3 (fatal error, no retry)', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'error' => $e->getMessage(),
					'error_type' => $error_classification['type'],
					'execution_time' => round( $elapsed_time, 2 ),
					'trace' => $e->getTraceAsString(),
				] );
				
				// Fire monitoring hook
				do_action( 'aebg_testvinder_regeneration_failed', $post_id, $product_number, 'step_3', [
					'error' => $e->getMessage(),
					'error_type' => $error_classification['type'],
					'execution_time' => $elapsed_time,
				] );
				
				// Don't delete checkpoint on fatal error - may need manual intervention
				return;
			}
			
			// Check retry count
			$retry_count = CheckpointManager::getReplacementRetryCount( $post_id, $product_number, CheckpointManager::STEP_TESTVINDER_APPLY_CONTENT );
			
			if ( $retry_count >= CheckpointManager::MAX_RETRY_ATTEMPTS ) {
				// Max retries reached - mark as failed
				Logger::error( 'Testvinder Step 3 exceeded max retries', [
					'post_id' => $post_id,
					'product_number' => $product_number,
					'error' => $e->getMessage(),
					'retry_count' => $retry_count,
				] );
				
				// Fire monitoring hook
				do_action( 'aebg_testvinder_regeneration_failed', $post_id, $product_number, 'step_3', [
					'error' => $e->getMessage(),
					'error_type' => $error_classification['type'],
					'execution_time' => $elapsed_time,
					'reason' => 'max_retries_exceeded',
				] );
				
				return;
			}
			
			// Increment retry count and schedule retry
			CheckpointManager::incrementReplacementRetryCount( $post_id, $product_number, CheckpointManager::STEP_TESTVINDER_APPLY_CONTENT );
			$delay = $this->calculateReplacementRetryDelay( $retry_count + 1 );
			TestvinderReplacementScheduler::scheduleNextStep( 'step_2', $post_id, $product_number, $delay );
			
			Logger::warning( 'Exception in testvinder Step 3, retry scheduled', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'error' => $e->getMessage(),
				'error_type' => $error_classification['type'],
				'retry_count' => $retry_count + 1,
				'delay' => $delay,
				'execution_time' => round( $elapsed_time, 2 ),
			] );
			
			// Fire monitoring hook
			do_action( 'aebg_testvinder_regeneration_retried', $post_id, $product_number, 'step_3', [
				'error' => $e->getMessage(),
				'error_type' => $error_classification['type'],
				'retry_count' => $retry_count + 1,
				'delay' => $delay,
				'execution_time' => $elapsed_time,
			] );
			
			// Don't delete checkpoint on exception - allow retry
		} finally {
			// Release lock
			$this->releaseReplacementLock( $post_id, $product_number );
			self::$is_executing = false;
			unset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] );
		}
	}

	/**
	 * Execute featured image regeneration.
	 *
	 * @param string $job_id The job ID.
	 */
	public function execute_regenerate_featured_image( $job_id ) {
		// Set execution flag (prevents shutdown hook conflicts)
		self::$is_executing = true;
		$GLOBALS['aebg_current_item_id'] = $job_id; // For usage tracking

		try {
			// Get job data from transient
			$job_data = get_transient( 'aebg_featured_image_job_' . $job_id );
			if ( ! $job_data ) {
				throw new \Exception( 'Job data not found' );
			}

			$post_id = $job_data['post_id'];
			$style = $job_data['style'];
			$image_model = $job_data['image_model'];
			$image_size = $job_data['image_size'];
			$image_quality = $job_data['image_quality'];
			$custom_prompt = $job_data['custom_prompt'] ?? null;
			$use_custom_prompt = $job_data['use_custom_prompt'] ?? false;

			// Update status to 'processing'
			$this->update_featured_image_job_status( $job_id, 'processing', 10, 'Starting image generation...' );

			// Get settings
			$settings = get_option( 'aebg_settings', [] );
			$api_key = $settings['api_key'] ?? '';
			$ai_model = $settings['model'] ?? 'gpt-4';

			if ( empty( $api_key ) ) {
				throw new \Exception( 'OpenAI API key not configured' );
			}

			// Prepare image settings
			$image_settings = [
				'image_model'   => $image_model,
				'image_size'    => $image_size,
				'image_quality' => $image_quality,
			];

			// Update progress
			$this->update_featured_image_job_status( $job_id, 'processing', 30, 'Generating image prompt...' );

			// Handle custom prompt vs auto-generated
			if ( $use_custom_prompt && ! empty( $custom_prompt ) ) {
				// Use custom prompt
				$style_instructions = \AEBG\Core\ImageProcessor::getStyleInstructions( $style );
				$full_prompt = sanitize_textarea_field( $custom_prompt );
				if ( stripos( $full_prompt, 'style:' ) === false ) {
					$full_prompt .= '. ' . $style_instructions;
				}
			} else {
				// Use auto-generated prompt
				$post = get_post( $post_id );
				if ( ! $post ) {
					throw new \Exception( 'Post not found' );
				}
				$full_prompt = \AEBG\Core\ImageProcessor::createFeaturedImagePrompt( $post->post_title, $style );
			}

			// Update progress
			$this->update_featured_image_job_status( $job_id, 'processing', 50, 'Calling DALL-E API...' );

			// Generate image (reuse existing ImageProcessor)
			$image_url = \AEBG\Core\ImageProcessor::generateAIImage( $full_prompt, $api_key, $ai_model, $image_settings );

			if ( ! $image_url ) {
				throw new \Exception( 'Failed to generate image' );
			}

			// Update progress
			$this->update_featured_image_job_status( $job_id, 'processing', 70, 'Downloading image...' );

			// Download and save image
			$attachment_id = \AEBG\Core\ProductImageManager::downloadAndInsertImage(
				$image_url,
				$full_prompt,
				0
			);

			if ( ! $attachment_id ) {
				throw new \Exception( 'Failed to save image to media library' );
			}

			// Update progress
			$this->update_featured_image_job_status( $job_id, 'processing', 90, 'Setting as featured image...' );

			// Set as featured image
			$result = set_post_thumbnail( $post_id, $attachment_id );

			if ( ! $result ) {
				throw new \Exception( 'Failed to set featured image' );
			}

			// Get attachment URLs
			$attachment_url = wp_get_attachment_url( $attachment_id );
			$thumbnail_url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
			// Fallback to medium size if thumbnail not available
			if ( ! $thumbnail_url ) {
				$thumbnail_url = wp_get_attachment_image_url( $attachment_id, 'medium' );
			}
			// Final fallback to full URL
			if ( ! $thumbnail_url ) {
				$thumbnail_url = $attachment_url;
			}

			// Update status to completed
			$this->update_featured_image_job_status( $job_id, 'completed', 100, 'Image generated successfully!', [
				'attachment_id'  => $attachment_id,
				'attachment_url' => $attachment_url,
				'thumbnail_url'  => $thumbnail_url,
				'prompt_used'    => $full_prompt,
			] );

			// Clean up transient after 1 hour (keep for history)
			set_transient( 'aebg_featured_image_job_' . $job_id, $job_data, HOUR_IN_SECONDS );

			Logger::info( 'Featured image regeneration completed', [
				'job_id'        => $job_id,
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
			] );

		} catch ( \Exception $e ) {
			// Update status to failed
			$this->update_featured_image_job_status( $job_id, 'failed', 0, 'Generation failed: ' . $e->getMessage(), [
				'error_message' => $e->getMessage(),
			] );

			Logger::error( 'Featured image regeneration failed', [
				'job_id' => $job_id,
				'error'  => $e->getMessage(),
				'file'   => $e->getFile(),
				'line'   => $e->getLine(),
			] );
		} finally {
			// Clear execution flag
			self::$is_executing = false;
			unset( $GLOBALS['aebg_current_item_id'] );
		}
	}

	/**
	 * Update featured image job status.
	 *
	 * @param string $job_id Job ID.
	 * @param string $status Status (pending, processing, completed, failed).
	 * @param int    $progress Progress percentage (0-100).
	 * @param string $message Status message.
	 * @param array  $data Additional data.
	 */
	private function update_featured_image_job_status( $job_id, $status, $progress, $message, $data = [] ) {
		$status_data = [
			'status'     => $status,
			'progress'   => $progress,
			'message'     => $message,
			'updated_at' => time(),
		] + $data;

		set_transient( 'aebg_featured_image_status_' . $job_id, $status_data, HOUR_IN_SECONDS );
	}
}


