<?php

namespace AEBG\Core;

use AEBG\Core\CheckpointManager;
use AEBG\Core\Logger;

/**
 * Step Handler Class
 * 
 * Centralized handler for step-by-step action execution.
 * Manages step routing, chaining, and state management.
 * 
 * @package AEBG\Core
 */
class StepHandler {
	
	/**
	 * Step execution map
	 * Defines all steps, their hooks, methods, and next steps
	 */
	private static $step_map = [
		'step_1' => [
			'hook' => 'aebg_execute_step_1',
			'method' => 'execute_step_1',
			'next_step' => 'step_2',
			'checkpoint_step' => CheckpointManager::STEP_1_ANALYZE_TITLE,
			'step_number' => 1,
			'description' => 'Analyze Title',
		],
		'step_2' => [
			'hook' => 'aebg_execute_step_2',
			'method' => 'execute_step_2',
			'next_step' => 'step_3',
			'checkpoint_step' => CheckpointManager::STEP_2_FIND_PRODUCTS,
			'step_number' => 2,
			'description' => 'Find Products',
		],
		'step_3' => [
			'hook' => 'aebg_execute_step_3',
			'method' => 'execute_step_3',
			'next_step' => 'step_3_5',
			'checkpoint_step' => CheckpointManager::STEP_3_SELECT_PRODUCTS,
			'step_number' => 3,
			'description' => 'Select Products',
		],
		'step_3_5' => [
			'hook' => 'aebg_execute_step_3_5',
			'method' => 'execute_step_3_5',
			'next_step' => 'step_3_6',
			'checkpoint_step' => CheckpointManager::STEP_3_5_DISCOVER_MERCHANTS,
			'step_number' => 4,
			'description' => 'Discover Merchants',
		],
		'step_3_6' => [
			'hook' => 'aebg_execute_step_3_6',
			'method' => 'execute_step_3_6',
			'next_step' => 'step_3_7',
			'checkpoint_step' => CheckpointManager::STEP_3_6_PRICE_COMPARISON,
			'step_number' => 5,
			'description' => 'Price Comparison',
		],
		'step_3_7' => [
			'hook' => 'aebg_execute_step_3_7',
			'method' => 'execute_step_3_7',
			'next_step' => 'step_4',
			'checkpoint_step' => CheckpointManager::STEP_3_7_PROCESS_IMAGES,
			'step_number' => 6,
			'description' => 'Process Images',
		],
		'step_4' => [
			'hook' => 'aebg_execute_step_4',
			'method' => 'execute_step_4_collect_prompts',
			'next_step' => 'step_4_1',
			'checkpoint_step' => CheckpointManager::STEP_4_COLLECT_PROMPTS,
			'step_number' => 7,
			'description' => 'Collect AI Prompts',
		],
		'step_4_1' => [
			'hook' => 'aebg_execute_step_4_1',
			'method' => 'execute_step_4_1_process_fields',
			'next_step' => 'step_4_2',
			'checkpoint_step' => CheckpointManager::STEP_4_1_PROCESS_FIELDS,
			'step_number' => 8,
			'description' => 'Process AI Fields',
		],
		'step_4_2' => [
			'hook' => 'aebg_execute_step_4_2',
			'method' => 'execute_step_4_2_apply_content',
			'next_step' => 'step_5',
			'checkpoint_step' => CheckpointManager::STEP_4_2_APPLY_CONTENT,
			'step_number' => 9,
			'description' => 'Apply Content to Widgets',
		],
		'step_5' => [
			'hook' => 'aebg_execute_step_5',
			'method' => 'execute_step_5',
			'next_step' => 'step_5_5',
			'checkpoint_step' => CheckpointManager::STEP_5_GENERATE_CONTENT,
			'step_number' => 10,
			'description' => 'Generate Content',
		],
		'step_5_5' => [
			'hook' => 'aebg_execute_step_5_5',
			'method' => 'execute_step_5_5',
			'next_step' => 'step_6',
			'checkpoint_step' => CheckpointManager::STEP_5_5_MERCHANT_COMPARISONS,
			'step_number' => 11,
			'description' => 'Merchant Comparisons',
		],
		'step_6' => [
			'hook' => 'aebg_execute_step_6',
			'method' => 'execute_step_6',
			'next_step' => 'step_7',
			'checkpoint_step' => CheckpointManager::STEP_6_CREATE_POST,
			'step_number' => 12,
			'description' => 'Create Post',
		],
		'step_7' => [
			'hook' => 'aebg_execute_step_7',
			'method' => 'execute_step_7',
			'next_step' => 'step_8',
			'checkpoint_step' => CheckpointManager::STEP_7_IMAGE_ENHANCEMENTS,
			'step_number' => 13,
			'description' => 'Image Enhancements',
		],
		'step_8' => [
			'hook' => 'aebg_execute_step_8',
			'method' => 'execute_step_8',
			'next_step' => null, // Last step
			'checkpoint_step' => CheckpointManager::STEP_8_SEO_ENHANCEMENTS,
			'step_number' => 14,
			'description' => 'SEO Enhancements',
		],
	];
	
	/**
	 * Get step configuration
	 * 
	 * @param string $step_key Step key (e.g., 'step_1')
	 * @return array|null Step configuration or null if not found
	 */
	public static function get_step_config( string $step_key ): ?array {
		return self::$step_map[ $step_key ] ?? null;
	}
	
	/**
	 * Get step hook name
	 * 
	 * @param string $step_key Step key
	 * @return string|null Hook name or null if not found
	 */
	public static function get_step_hook( string $step_key ): ?string {
		$config = self::get_step_config( $step_key );
		return $config['hook'] ?? null;
	}
	
	/**
	 * Get next step key
	 * 
	 * @param string $current_step_key Current step key
	 * @return string|null Next step key or null if last step
	 */
	public static function get_next_step( string $current_step_key ): ?string {
		$config = self::get_step_config( $current_step_key );
		return $config['next_step'] ?? null;
	}
	
	/**
	 * Get checkpoint step constant
	 * 
	 * @param string $step_key Step key
	 * @return string|null Checkpoint step constant or null
	 */
	public static function get_checkpoint_step( string $step_key ): ?string {
		$config = self::get_step_config( $step_key );
		return $config['checkpoint_step'] ?? null;
	}
	
	/**
	 * Get step number
	 * 
	 * @param string $step_key Step key
	 * @return int Step number (1-12) or 0 if not found
	 */
	public static function get_step_number( string $step_key ): int {
		$config = self::get_step_config( $step_key );
		return $config['step_number'] ?? 0;
	}
	
	/**
	 * Get total number of steps
	 * 
	 * @return int Total number of steps (14)
	 */
	public static function get_total_steps(): int {
		return count( self::$step_map );
	}
	
	/**
	 * Get step description
	 * 
	 * @param string $step_key Step key
	 * @return string Step description or empty string
	 */
	public static function get_step_description( string $step_key ): string {
		$config = self::get_step_config( $step_key );
		return $config['description'] ?? '';
	}
	
	/**
	 * Get all step keys in order
	 * 
	 * @return array Array of step keys in execution order
	 */
	public static function get_all_step_keys(): array {
		return array_keys( self::$step_map );
	}
	
	/**
	 * Get first step key
	 * 
	 * @return string First step key ('step_1')
	 */
	public static function get_first_step(): string {
		return 'step_1';
	}
	
	/**
	 * Get last step key
	 * 
	 * @return string Last step key ('step_8')
	 */
	public static function get_last_step(): string {
		return 'step_8';
	}
	
	/**
	 * Check if step is last step
	 * 
	 * @param string $step_key Step key
	 * @return bool True if last step, false otherwise
	 */
	public static function is_last_step( string $step_key ): bool {
		return self::get_next_step( $step_key ) === null;
	}
	
	/**
	 * Schedule next step
	 * 
	 * @param string $current_step_key Current step key
	 * @param int $item_id Item ID
	 * @param int $delay Delay in seconds (default: 2)
	 * @param string|null $title Optional title (for new format with title in args)
	 * @return int|false Action ID on success, false on failure
	 */
	public static function schedule_next_step( string $current_step_key, int $item_id, int $delay = 2, $title = null ): int|false {
		$next_step = self::get_next_step( $current_step_key );
		
		if ( ! $next_step ) {
			// Last step - no next step to schedule
			return false;
		}
		
		$next_hook = self::get_step_hook( $next_step );
		if ( ! $next_hook ) {
			Logger::error( 'Next step hook not found', [
				'current_step' => $current_step_key,
				'next_step' => $next_step,
				'item_id' => $item_id,
			] );
			return false;
		}
		
		// Schedule next step
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			Logger::error( 'as_schedule_single_action() not available', [
				'item_id' => $item_id,
				'next_step' => $next_step,
			] );
			return false;
		}
		
		// Fetch title if not provided (for backward compatibility)
		if ( empty( $title ) ) {
			global $wpdb;
			$item = $wpdb->get_row( $wpdb->prepare( 
				"SELECT source_title FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d", 
				$item_id 
			) );
			$title = $item->source_title ?? '';
		}
		
		$group = 'aebg_generation_' . $item_id;
		$action_id = as_schedule_single_action(
			time() + $delay,
			$next_hook,
			[ $item_id, $title ],
			$group,
			true // unique
		);
		
		if ( $action_id > 0 ) {
			Logger::debug( 'Next step scheduled', [
				'current_step' => $current_step_key,
				'next_step' => $next_step,
				'next_hook' => $next_hook,
				'item_id' => $item_id,
				'action_id' => $action_id,
				'delay' => $delay,
			] );
			
			error_log( sprintf(
				'[AEBG] ✅ Step %s completed, scheduled Step %s for item_id=%d (action_id=%d)',
				$current_step_key,
				$next_step,
				$item_id,
				$action_id
			) );
		} else {
			Logger::error( 'Failed to schedule next step', [
				'current_step' => $current_step_key,
				'next_step' => $next_step,
				'item_id' => $item_id,
			] );
		}
		
		return $action_id;
	}
	
	/**
	 * Cancel all remaining steps for an item
	 * 
	 * @param int $item_id Item ID
	 * @return int Number of actions cancelled
	 */
	public static function cancel_all_steps_for_item( int $item_id ): int {
		$cancelled = 0;
		$group = 'aebg_generation_' . $item_id;
		
		// CRITICAL: Action Scheduler requires args to match exactly when unscheduling.
		// We schedule with [ $item_id, $title ], so we must unschedule with the same.
		$title = '';
		if ( function_exists( 'as_unschedule_action' ) ) {
			global $wpdb;
			$item = $wpdb->get_row( $wpdb->prepare(
				"SELECT source_title FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d",
				$item_id
			) );
			if ( $item && isset( $item->source_title ) ) {
				$title = $item->source_title;
			}
		}
		$args = [ $item_id, $title ];
		
		// Get all step hooks
		$all_steps = self::get_all_step_keys();
		
		foreach ( $all_steps as $step_key ) {
			$hook = self::get_step_hook( $step_key );
			if ( ! $hook ) {
				continue;
			}
			
			// Unschedule all pending actions for this step (args must match schedule: [ item_id, title ])
			if ( function_exists( 'as_unschedule_action' ) ) {
				$unscheduled = as_unschedule_action( $hook, $args, $group );
				if ( $unscheduled ) {
					$cancelled++;
					Logger::debug( 'Cancelled step action', [
						'step' => $step_key,
						'hook' => $hook,
						'item_id' => $item_id,
					] );
				}
			}
		}
		
		if ( $cancelled > 0 ) {
			Logger::info( 'Cancelled all steps for item', [
				'item_id' => $item_id,
				'cancelled_count' => $cancelled,
			] );
		}
		
		return $cancelled;
	}
	
	/**
	 * Get step key from hook name
	 * 
	 * @param string $hook Hook name (e.g., 'aebg_execute_step_1')
	 * @return string|null Step key or null if not found
	 */
	public static function get_step_key_from_hook( string $hook ): ?string {
		foreach ( self::$step_map as $step_key => $config ) {
			if ( $config['hook'] === $hook ) {
				return $step_key;
			}
		}
		return null;
	}
	
	/**
	 * Validate step key
	 * 
	 * @param string $step_key Step key to validate
	 * @return bool True if valid, false otherwise
	 */
	public static function is_valid_step_key( string $step_key ): bool {
		return isset( self::$step_map[ $step_key ] );
	}
}

