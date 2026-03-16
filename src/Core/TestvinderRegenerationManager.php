<?php

namespace AEBG\Core;

use AEBG\Core\ProductReplacementScheduler;
use AEBG\Core\TestvinderReplacementScheduler;
use AEBG\Core\ActionSchedulerHelper;
use AEBG\Core\Logger;

/**
 * Testvinder Regeneration Manager
 * 
 * Manages scheduling of testvinder container regenerations.
 * Extends ProductReplacementScheduler pattern.
 * 
 * @package AEBG\Core
 */
class TestvinderRegenerationManager {
	
	/**
	 * Schedule testvinder-only regeneration
	 * 
	 * @param int $post_id Post ID
	 * @param int $product_number Product number (position)
	 * @return int|false Action ID on success, false on failure
	 */
	public static function scheduleTestvinderOnlyRegeneration(
		int $post_id,
		int $product_number
	) {
		Logger::info('Scheduling testvinder-only regeneration', [
			'post_id' => $post_id,
			'product_number' => $product_number,
		]);
		
		// Validate parameters
		if (empty($post_id) || !is_numeric($post_id) || $post_id <= 0) {
			Logger::error('Invalid post_id for testvinder regeneration', [
				'post_id' => $post_id,
				'product_number' => $product_number,
			]);
			return false;
		}
		
		if (empty($product_number) || !is_numeric($product_number) || $product_number < 1) {
			Logger::error('Invalid product_number for testvinder regeneration', [
				'post_id' => $post_id,
				'product_number' => $product_number,
			]);
			return false;
		}
		
		// Use TestvinderReplacementScheduler for consistent workflow
		return TestvinderReplacementScheduler::scheduleReplacement($post_id, $product_number);
		
		if ($action_id > 0) {
			Logger::info('Testvinder regeneration scheduled', [
				'action_id' => $action_id,
				'post_id' => $post_id,
				'product_number' => $product_number,
				'group' => $group,
			]);
		} else {
			Logger::error('Failed to schedule testvinder regeneration', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'action_id' => $action_id,
			]);
		}
		
		return $action_id;
	}
	
	/**
	 * Schedule full regeneration (product + testvinder)
	 * 
	 * @param int $post_id Post ID
	 * @param int $product_number Product number
	 * @return int|false Action ID on success, false on failure
	 */
	public static function scheduleProductAndTestvinderRegeneration(
		int $post_id,
		int $product_number
	) {
		Logger::info('Scheduling product and testvinder regeneration', [
			'post_id' => $post_id,
			'product_number' => $product_number,
		]);
		
		// Use existing ProductReplacementScheduler
		// This already handles both product and testvinder containers
		return ProductReplacementScheduler::scheduleReplacement(
			$post_id,
			$product_number
		);
	}
	
	/**
	 * Check if regeneration is in progress
	 * 
	 * @param int $post_id Post ID
	 * @param int $product_number Product number
	 * @return bool True if regeneration is in progress
	 */
	public static function isRegenerationInProgress(int $post_id, int $product_number): bool {
		$group = "aebg_testvinder_{$post_id}_{$product_number}";
		
		$hooks = [
			'aebg_regenerate_testvinder_only',
			'aebg_replace_product_step_1', // Full regeneration uses replacement hooks
			'aebg_replace_product_step_2',
			'aebg_replace_product_step_3',
		];
		
		foreach ($hooks as $hook) {
			if (ActionSchedulerHelper::action_exists($hook, [$post_id, $product_number], $group)) {
				return true;
			}
		}
		
		// Also check replacement group
		$replacement_group = "aebg_replace_{$post_id}_{$product_number}";
		foreach ($hooks as $hook) {
			if (ActionSchedulerHelper::action_exists($hook, [$post_id, $product_number], $replacement_group)) {
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * Cancel regeneration if in progress
	 * 
	 * @param int $post_id Post ID
	 * @param int $product_number Product number
	 * @return int Number of actions cancelled
	 */
	public static function cancelRegeneration(int $post_id, int $product_number): int {
		$cancelled = 0;
		$group = "aebg_testvinder_{$post_id}_{$product_number}";
		
		$hooks = [
			'aebg_regenerate_testvinder_only',
		];
		
		foreach ($hooks as $hook) {
			if (function_exists('as_unschedule_action')) {
				$unscheduled = as_unschedule_action($hook, [$post_id, $product_number], $group);
				if ($unscheduled) {
					$cancelled++;
				}
			}
		}
		
		// Also cancel replacement if in progress
		$replacement_cancelled = ProductReplacementScheduler::cancelReplacement($post_id, $product_number);
		$cancelled += $replacement_cancelled;
		
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

