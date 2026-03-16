<?php

namespace AEBG\Core;

use AEBG\Core\ActionSchedulerHelper;
use AEBG\Core\Logger;

/**
 * Product Replacement Scheduler
 * 
 * Manages the 3-step Action Scheduler workflow for product replacement:
 * Step 1: Collect AI Prompts
 * Step 2: Process AI Fields
 * Step 3: Apply Content and Save
 * 
 * @package AEBG\Core
 */
class ProductReplacementScheduler {
	
	/**
	 * Schedule product replacement workflow
	 * 
	 * @param int $post_id Post ID
	 * @param int $product_number Product number (1-based)
	 * @param array $product_data Product data
	 * @return int|false Action ID on success, false on failure
	 */
	public static function scheduleReplacement($post_id, $product_number, $product_data = []) {
		Logger::info('===== SCHEDULING PRODUCT REPLACEMENT =====', [
			'post_id' => $post_id,
			'product_number' => $product_number,
		]);
		
		// Validate parameters
		if (empty($post_id) || !is_numeric($post_id)) {
			Logger::error('Invalid post_id for product replacement', [
				'post_id' => $post_id,
				'product_number' => $product_number,
			]);
			return false;
		}
		
		if (empty($product_number) || !is_numeric($product_number) || $product_number < 1) {
			Logger::error('Invalid product_number for product replacement', [
				'post_id' => $post_id,
				'product_number' => $product_number,
			]);
			return false;
		}
		
		// Store job start time in checkpoint for timeout tracking
		$start_time = microtime(true);
		$checkpoint_state = [
			'post_id' => $post_id,
			'product_number' => $product_number,
			'job_start_time' => $start_time,
			'settings' => [], // Will be populated in Step 1
		];
		
		// Save initial checkpoint with start time
		\AEBG\Core\CheckpointManager::saveReplacementCheckpoint(
			$post_id,
			$product_number,
			\AEBG\Core\CheckpointManager::STEP_REPLACE_COLLECT_PROMPTS,
			$checkpoint_state
		);
		
		// Store in global for immediate access
		if (!isset($GLOBALS['aebg_replacement_start_time'])) {
			$GLOBALS['aebg_replacement_start_time'] = [];
		}
		$GLOBALS['aebg_replacement_start_time'][$post_id][$product_number] = $start_time;
		
		// Create unique group for this replacement
		$group = "aebg_replace_{$post_id}_{$product_number}";
		
		// Schedule Step 1: Collect Prompts
		// CRITICAL: Use delay=0 to trigger immediate execution via as_enqueue_async_action()
		// This ensures the action runs immediately instead of waiting for WP-Cron
		$action_id = ActionSchedulerHelper::schedule_action(
			'aebg_replace_product_step_1',
			[$post_id, $product_number],
			$group,
			0, // 0 = immediate execution via as_enqueue_async_action()
			true // unique
		);
		
		if ($action_id > 0) {
			Logger::info('Product replacement scheduled', [
				'action_id' => $action_id,
				'post_id' => $post_id,
				'product_number' => $product_number,
				'group' => $group,
				'start_time' => $start_time,
			]);
			
			// CRITICAL: Trigger Action Scheduler to run immediately after scheduling
			// This ensures the action executes right away instead of waiting for WP-Cron
			self::triggerActionSchedulerExecution();
		} else {
			Logger::error('Failed to schedule product replacement', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'action_id' => $action_id,
			]);
		}
		
		return $action_id;
	}
	
	/**
	 * Trigger Action Scheduler to execute immediately after scheduling
	 * This ensures actions run right away instead of waiting for WP-Cron
	 * 
	 * CRITICAL: This method tries multiple approaches to ensure actions execute:
	 * 1. Direct queue runner execution (works when called from within an action)
	 * 2. Async request dispatch (via shutdown hook)
	 * 
	 * @return bool True if triggered successfully, false otherwise
	 */
	private static function triggerActionSchedulerExecution() {
		$triggered = false;
		
		// Approach 1: Try to run directly if we're in a context that allows it
		// This works when called from within an Action Scheduler action execution
		try {
			if (class_exists('\ActionScheduler_QueueRunner')) {
				$runner = \ActionScheduler_QueueRunner::instance();
				
				// Try to run queue directly - this works even inside action execution
				if (method_exists($runner, 'run')) {
					// Run with limit of 1 to process just the next pending action
					$runner->run(1);
					Logger::debug('Triggered Action Scheduler queue runner directly', []);
					$triggered = true;
				}
			}
		} catch (\Exception $e) {
			Logger::debug('Direct queue runner execution failed, using shutdown hook', [
				'error' => $e->getMessage(),
			]);
		}
		
		// Approach 2: Use shutdown hook for async request dispatch
		// This works when called from AJAX or other non-action contexts
		// Only add if direct execution didn't work
		if (!$triggered) {
			add_action('shutdown', function() {
				try {
					if (!class_exists('\ActionScheduler_QueueRunner')) {
						return;
					}
					
					$runner = \ActionScheduler_QueueRunner::instance();
					
					// Try to trigger async request dispatch
					if (method_exists($runner, 'maybe_dispatch_async_request')) {
						$result = $runner->maybe_dispatch_async_request();
						Logger::debug('Triggered Action Scheduler async request dispatch via shutdown hook', [
							'result' => $result,
						]);
					}
					
					// Also try to run queue directly if in admin context
					if (is_admin() && method_exists($runner, 'run')) {
						$runner->run(1);
						Logger::debug('Ran Action Scheduler queue directly via shutdown hook', []);
					}
				} catch (\Exception $e) {
					Logger::warning('Failed to trigger Action Scheduler execution via shutdown hook', [
						'error' => $e->getMessage(),
					]);
				}
			}, 999);
		}
		
		return $triggered;
	}
	
	/**
	 * Schedule next step in replacement workflow
	 * 
	 * @param string $current_step Current step ('step_1', 'step_2', 'step_3')
	 * @param int $post_id Post ID
	 * @param int $product_number Product number
	 * @param int $delay Delay in seconds (default: 0 for immediate execution)
	 * @return int|false Action ID on success, false on failure
	 */
	public static function scheduleNextStep($current_step, $post_id, $product_number, $delay = 0) {
		$group = "aebg_replace_{$post_id}_{$product_number}";
		
		// Determine next step
		$next_step = null;
		$next_hook = null;
		
		switch ($current_step) {
			case 'step_1':
				$next_step = 'step_2';
				$next_hook = 'aebg_replace_product_step_2';
				break;
			case 'step_2':
				$next_step = 'step_3';
				$next_hook = 'aebg_replace_product_step_3';
				break;
			case 'step_3':
				// Last step - no next step
				return false;
			default:
				Logger::error('Unknown step in product replacement', [
					'current_step' => $current_step,
					'post_id' => $post_id,
					'product_number' => $product_number,
				]);
				return false;
		}
		
		// CRITICAL: When scheduling from within an action execution, use delay=1 instead of 0
		// as_enqueue_async_action with delay=0 might not execute immediately when called from within an action
		// The direct queue runner call in ActionHandler should handle immediate execution
		// Using delay=1 ensures it will be picked up reliably on the next queue run
		$actual_delay = $delay;
		if ( $delay === 0 ) {
			// When called from within an action, use 1 second delay to ensure proper processing
			$actual_delay = 1;
		}
		
		$action_id = ActionSchedulerHelper::schedule_action(
			$next_hook,
			[$post_id, $product_number],
			$group,
			$actual_delay,
			true // unique
		);
		
		if ($action_id > 0) {
			Logger::info('Next step scheduled for product replacement', [
				'current_step' => $current_step,
				'next_step' => $next_step,
				'action_id' => $action_id,
				'post_id' => $post_id,
				'product_number' => $product_number,
				'requested_delay' => $delay,
				'actual_delay' => $actual_delay,
				'hook' => $next_hook,
			]);
			
			error_log(sprintf(
				'[AEBG] ✅ Step %s completed, scheduled Step %s for post_id=%d, product=%d (action_id=%d, delay=%ds, hook=%s)',
				$current_step,
				$next_step,
				$post_id,
				$product_number,
				$action_id,
				$actual_delay,
				$next_hook
			));
			
			// CRITICAL: Always trigger Action Scheduler execution after scheduling next step
			// This ensures the next step executes as soon as possible
			// The direct queue runner call in ActionHandler will handle immediate execution
			$triggered = self::triggerActionSchedulerExecution();
			
			// Verify the action was actually created in the database
			global $wpdb;
			$action_table = $wpdb->prefix . 'actionscheduler_actions';
			$verify_action = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM {$action_table} 
				WHERE action_id = %d 
				AND hook = %s 
				AND status IN ('pending', 'in-progress')",
				$action_id,
				$next_hook
			));
			
			if ($verify_action > 0) {
				Logger::debug('Verified next step action exists in database', [
					'action_id' => $action_id,
					'hook' => $next_hook,
					'next_step' => $next_step,
					'status' => 'pending or in-progress',
				]);
			} else {
				Logger::warning('Next step action not found in database after scheduling', [
					'action_id' => $action_id,
					'hook' => $next_hook,
					'next_step' => $next_step,
				]);
			}
		} else {
			Logger::error('CRITICAL: Failed to schedule next step for product replacement', [
				'current_step' => $current_step,
				'next_step' => $next_step,
				'post_id' => $post_id,
				'product_number' => $product_number,
				'delay' => $delay,
				'hook' => $next_hook,
				'action_id' => $action_id,
			]);
		}
		
		return $action_id;
	}
	
	/**
	 * Cancel all steps for a product replacement
	 * 
	 * @param int $post_id Post ID
	 * @param int $product_number Product number
	 * @return int Number of actions cancelled
	 */
	public static function cancelReplacement($post_id, $product_number) {
		$group = "aebg_replace_{$post_id}_{$product_number}";
		$cancelled = 0;
		
		$hooks = [
			'aebg_replace_product_step_1',
			'aebg_replace_product_step_2',
			'aebg_replace_product_step_3',
		];
		
		foreach ($hooks as $hook) {
			if (function_exists('as_unschedule_action')) {
				$unscheduled = as_unschedule_action($hook, [$post_id, $product_number], $group);
				if ($unscheduled) {
					$cancelled++;
				}
			}
		}
		
		if ($cancelled > 0) {
			Logger::info('Cancelled product replacement steps', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'cancelled_count' => $cancelled,
			]);
		}
		
		return $cancelled;
	}
	
	/**
	 * Check if replacement is in progress
	 * 
	 * @param int $post_id Post ID
	 * @param int $product_number Product number
	 * @return bool True if replacement is in progress
	 */
	public static function isReplacementInProgress($post_id, $product_number) {
		$group = "aebg_replace_{$post_id}_{$product_number}";
		
		$hooks = [
			'aebg_replace_product_step_1',
			'aebg_replace_product_step_2',
			'aebg_replace_product_step_3',
		];
		
		foreach ($hooks as $hook) {
			if (ActionSchedulerHelper::action_exists($hook, [$post_id, $product_number], $group)) {
				return true;
			}
		}
		
		return false;
	}
}

