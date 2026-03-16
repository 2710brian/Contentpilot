<?php

namespace AEBG\Core;

use AEBG\Core\ActionSchedulerHelper;
use AEBG\Core\Logger;

/**
 * Testvinder Replacement Scheduler
 * 
 * Handles scheduling of testvinder-only regeneration workflow (3-step process).
 * Similar to ProductReplacementScheduler but for testvinder containers only.
 * 
 * @package AEBG\Core
 */
class TestvinderReplacementScheduler {
	
	/**
	 * Schedule testvinder-only regeneration (Step 1)
	 * 
	 * @param int $post_id Post ID
	 * @param int $product_number Product number
	 * @return int|false Action ID on success, false on failure
	 */
	public static function scheduleReplacement(int $post_id, int $product_number) {
		$group = "aebg_testvinder_{$post_id}_{$product_number}";
		
		Logger::info('Scheduling testvinder-only regeneration', [
			'post_id' => $post_id,
			'product_number' => $product_number,
			'group' => $group,
		]);
		
		$action_id = ActionSchedulerHelper::schedule_action(
			'aebg_regenerate_testvinder_only', // Step 1
			[$post_id, $product_number],
			$group,
			0, // Immediate
			true // Unique
		);
		
		if ($action_id > 0) {
			Logger::info('Testvinder regeneration Step 1 scheduled', [
				'action_id' => $action_id,
				'post_id' => $post_id,
				'product_number' => $product_number,
			]);
		}
		
		return $action_id;
	}
	
	/**
	 * Schedule next step in testvinder workflow
	 * 
	 * @param string $current_step Current step ('step_1', 'step_2', 'step_3')
	 * @param int $post_id Post ID
	 * @param int $product_number Product number
	 * @param int $delay Delay in seconds (default: 0 for immediate execution)
	 * @return int|false Action ID on success, false on failure
	 */
	public static function scheduleNextStep($current_step, $post_id, $product_number, $delay = 0) {
		$group = "aebg_testvinder_{$post_id}_{$product_number}";
		
		// Determine next step
		$next_step = null;
		$next_hook = null;
		
		switch ($current_step) {
			case 'step_1':
				$next_step = 'step_2';
				$next_hook = 'aebg_testvinder_step_2';
				break;
			case 'step_2':
				$next_step = 'step_3';
				$next_hook = 'aebg_testvinder_step_3';
				break;
			case 'step_3':
				// Last step - no next step
				return false;
			default:
				Logger::error('Unknown step in testvinder regeneration', [
					'current_step' => $current_step,
					'post_id' => $post_id,
					'product_number' => $product_number,
				]);
				return false;
		}
		
		// Use delay=1 when called from within an action
		$actual_delay = $delay;
		if ($delay === 0) {
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
			Logger::info('Next testvinder step scheduled', [
				'current_step' => $current_step,
				'next_step' => $next_step,
				'action_id' => $action_id,
				'post_id' => $post_id,
				'product_number' => $product_number,
			]);
			
			// Trigger Action Scheduler execution
			self::triggerActionSchedulerExecution();
		}
		
		return $action_id;
	}
	
	/**
	 * Trigger Action Scheduler execution
	 * 
	 * @return bool Success
	 */
	private static function triggerActionSchedulerExecution() {
		if (function_exists('as_run_queue_runner')) {
			// Run queue runner directly
			as_run_queue_runner();
			return true;
		}
		
		// Fallback: Trigger via WordPress cron
		if (function_exists('spawn_cron')) {
			spawn_cron();
			return true;
		}
		
		return false;
	}
	
	/**
	 * Check if testvinder regeneration is in progress
	 * 
	 * @param int $post_id Post ID
	 * @param int $product_number Product number
	 * @return bool True if in progress
	 */
	public static function isReplacementInProgress(int $post_id, int $product_number): bool {
		$group = "aebg_testvinder_{$post_id}_{$product_number}";
		
		$hooks = [
			'aebg_regenerate_testvinder_only',
			'aebg_testvinder_step_2',
			'aebg_testvinder_step_3',
		];
		
		foreach ($hooks as $hook) {
			if (ActionSchedulerHelper::action_exists($hook, [$post_id, $product_number], $group)) {
				return true;
			}
		}
		
		// Also check for checkpoint
		$checkpoint = CheckpointManager::loadReplacementCheckpoint($post_id, $product_number, 'testvinder');
		if (!empty($checkpoint)) {
			return true;
		}
		
		return false;
	}
	
	/**
	 * Cancel testvinder regeneration if in progress
	 * 
	 * @param int $post_id Post ID
	 * @param int $product_number Product number
	 * @return int Number of actions cancelled
	 */
	public static function cancelReplacement(int $post_id, int $product_number): int {
		$cancelled = 0;
		$group = "aebg_testvinder_{$post_id}_{$product_number}";
		
		$hooks = [
			'aebg_regenerate_testvinder_only',
			'aebg_testvinder_step_2',
			'aebg_testvinder_step_3',
		];
		
		foreach ($hooks as $hook) {
			if (function_exists('as_unschedule_action')) {
				$unscheduled = as_unschedule_action($hook, [$post_id, $product_number], $group);
				if ($unscheduled) {
					$cancelled++;
				}
			}
		}
		
		// Clear checkpoint
		CheckpointManager::clearReplacementCheckpoint($post_id, $product_number, 'testvinder');
		
		if ($cancelled > 0) {
			Logger::info('Cancelled testvinder regeneration', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'cancelled_count' => $cancelled,
			]);
		}
		
		return $cancelled;
	}
}

